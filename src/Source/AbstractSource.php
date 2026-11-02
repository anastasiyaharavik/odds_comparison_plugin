<?php

/**
 * Template Method base for odds sources.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Source;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OddsComparison\Contracts\DiagnosticsInterface;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Domain\Price;
use OddsComparison\Http\HttpResponse;
use OddsComparison\Odds\OddsValidator;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Fixed fetch algorithm (Template Method):
 *
 *     build URL → request → parse → map bookmaker codes → build MarketOdds → validate
 *
 * Subclasses only describe their site: URLs ({@see self::competitionUrl()},
 * {@see self::marketUrl()}) and parsing ({@see self::parseEvents()}, {@see self::parseMarket()}).
 *
 * Responses are memoized per URL for the lifetime of the instance (one refresh run), so many
 * events priced from one listing page cost one request. Bookmaker codes the registry does not
 * know are ignored and counted (mapping check). Non-fatal problems are collected in
 * {@see self::warnings()}; the refresh run reports both to the error log and then clears them
 * ({@see DiagnosticsInterface}).
 *
 * @phpstan-type RawSelectionPrice array{
 *     selection: string, label: string, bookmaker: string, decimal: string
 * }
 */
abstract class AbstractSource implements SourceInterface, DiagnosticsInterface
{
    /**
     * Memoized responses by URL.
     *
     * @var array<string, HttpResponse>
     */
    private array $responses = [];

    /**
     * Non-fatal problems since construction.
     *
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * Unknown bookmaker codes seen, with counts.
     *
     * @var array<string, int>
     */
    private array $unmappedCodes = [];

    /**
     * Prices quoted as unavailable ("-", suspended) and the markets they were in. Routine on any
     * comparison site, so they are summed into one warning instead of one per market.
     *
     * @var array{prices: int, markets: int}
     */
    private array $unavailable = ['prices' => 0, 'markets' => 0];

    /**
     * Prices the validator dropped as implausible. Summed into one warning with one example.
     *
     * @var list<string>
     */
    private array $dropped = [];

    /**
     * Bookmaker slug by this source's code, built on first use.
     *
     * @var array<string, string>|null
     */
    private ?array $slugsByCode = null;

    private readonly Closure $clock;

    /**
     * @param HttpClientInterface $http         Transport (live or recorded).
     * @param BookmakerRegistry   $bookmakers   Bookmaker registry (code mapping).
     * @param CompetitionRegistry $competitions Competition registry (paths).
     * @param OddsValidator       $validator    Data-accuracy checks.
     * @param Closure|null        $clock        fn(): DateTimeImmutable; "now" (in Demo mode: the capture time).
     */
    public function __construct(
        protected readonly HttpClientInterface $http,
        protected readonly BookmakerRegistry $bookmakers,
        protected readonly CompetitionRegistry $competitions,
        protected readonly OddsValidator $validator,
        ?Closure $clock = null
    ) {
        $this->clock = $clock
            ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * {@inheritDoc}
     */
    final public function discoverEvents(Competition $competition): array
    {
        $url = $this->competitionUrl($competition);

        if ($url === null) {
            return [];
        }

        return $this->parseEvents($this->request($url)->body, $competition);
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException When the event belongs to another source.
     */
    final public function fetchMarket(Event $event, Market $market): MarketOdds
    {
        if ($event->sourceId !== $this->id()) {
            throw new InvalidArgumentException(
                sprintf('Event %s does not belong to source %s.', $event->id, $this->id())
            );
        }

        $competition = $this->competitions->get($event->competition);
        $url         = $competition === null ? null : $this->marketUrl($event, $market, $competition);

        if ($url === null) {
            throw new RuntimeException(
                sprintf(
                    '%s does not offer market %s for %s.',
                    $this->id(),
                    $market->key,
                    $event->id
                )
            );
        }

        $raw    = $this->parseMarket($this->request($url)->body, $event, $market);
        $result = $this->validator->validate($this->toMarketOdds($event, $market, $raw), $market);

        array_push($this->dropped, ...$result->notes);

        if ($result->odds === null) {
            throw new RuntimeException(
                sprintf(
                    'Odds for %s / %s rejected: %s.',
                    $event->id,
                    $market->key,
                    (string) $result->rejection
                )
            );
        }

        return $result->odds;
    }

    /**
     * Non-fatal problems collected so far (parse warnings, skipped events), followed by one summary
     * line each for implausible and unavailable prices.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = $this->warnings;

        if ($this->dropped !== []) {
            $warnings[] = sprintf(
                '%d implausible price(s) dropped by the validator, e.g. %s',
                count($this->dropped),
                $this->dropped[0]
            );
        }

        if ($this->unavailable['prices'] > 0) {
            $warnings[] = sprintf(
                '%d unavailable price(s) skipped in %d market(s) (not priced or suspended by the bookmaker).',
                $this->unavailable['prices'],
                $this->unavailable['markets']
            );
        }

        return $warnings;
    }

    /**
     * Bookmaker codes seen on the source but not in the registry, with counts.
     *
     * @return array<string, int>
     */
    public function unmappedCodes(): array
    {
        return $this->unmappedCodes;
    }

    /**
     * {@inheritDoc}
     */
    public function clearDiagnostics(): void
    {
        $this->warnings      = [];
        $this->unmappedCodes = [];
        $this->unavailable   = ['prices' => 0, 'markets' => 0];
        $this->dropped       = [];
    }

    /**
     * Listing URL of a competition, or null when this source does not cover it.
     *
     * @param Competition $competition Competition.
     * @return string|null
     */
    abstract protected function competitionUrl(Competition $competition): ?string;

    /**
     * URL holding a market's prices for an event, or null when not offered.
     *
     * @param Event       $event       Event.
     * @param Market      $market      Market.
     * @param Competition $competition The event's competition.
     * @return string|null
     */
    abstract protected function marketUrl(Event $event, Market $market, Competition $competition): ?string;

    /**
     * Events from a listing response.
     *
     * @param string      $body        Response body.
     * @param Competition $competition Competition listed.
     * @return list<Event>
     */
    abstract protected function parseEvents(string $body, Competition $competition): array;

    /**
     * Raw prices of one market of one event, keyed to selections.
     *
     * @param string $body   Response body.
     * @param Event  $event  Event.
     * @param Market $market Market.
     * @return list<RawSelectionPrice>
     */
    abstract protected function parseMarket(string $body, Event $event, Market $market): array;

    /**
     * Called before a network request; may throw to prevent it (e.g. quota guard).
     *
     * @param string $url URL about to be requested.
     * @return void
     * @throws RuntimeException To skip the request.
     */
    protected function beforeRequest(string $url): void
    {
    }

    /**
     * Called after a successful request (e.g. to record quota headers).
     *
     * @param HttpResponse $response Response.
     * @return void
     */
    protected function afterResponse(HttpResponse $response): void
    {
    }

    /**
     * Current time (UTC); the capture time for recorded responses (Demo mode) and in tests.
     *
     * @return DateTimeImmutable
     */
    protected function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    /**
     * Record a non-fatal problem.
     *
     * @param string ...$messages Messages.
     * @return void
     */
    protected function warn(string ...$messages): void
    {
        array_push($this->warnings, ...$messages);
    }

    /**
     * Selection key and label for a match participant: home / draw / away, or null.
     *
     * @param Event  $event       Match.
     * @param string $participant Participant name on the source.
     * @return array{string, string}|null [key, label]
     */
    protected static function matchSelection(Event $event, string $participant): ?array
    {
        return match (true) {
            $participant === $event->home              => ['home', $participant],
            $participant === $event->away              => ['away', $participant],
            strcasecmp($participant, 'Draw') === 0     => ['draw', 'Draw'],
            default                                     => null,
        };
    }

    /**
     * Kebab-case selection key for an outright participant (e.g. "Erling Braut Haaland").
     *
     * @param string $name Participant name.
     * @return string
     */
    protected static function selectionKey(string $name): string
    {
        // iconv warns on unmappable input; the result is checked below, so the warning is noise.
        // phpcs:ignore Generic.PHP.NoSilencedErrors
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
        $key   = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        return $key !== '' ? $key : 's-' . substr(md5($name), 0, 12);
    }

    /**
     * GET a URL once per instance.
     *
     * @param string $url URL.
     * @return HttpResponse
     */
    private function request(string $url): HttpResponse
    {
        if (!isset($this->responses[$url])) {
            $this->beforeRequest($url);
            $this->responses[$url] = $this->http->get($url);
            $this->afterResponse($this->responses[$url]);
        }

        return $this->responses[$url];
    }

    /**
     * Map raw prices to a MarketOdds: codes → slugs, strings → Price. Unusable prices are skipped.
     *
     * @param Event                   $event  Event.
     * @param Market                  $market Market.
     * @param list<RawSelectionPrice> $raw    Raw prices.
     * @return MarketOdds
     */
    private function toMarketOdds(Event $event, Market $market, array $raw): MarketOdds
    {
        $selections   = [];
        $unavailable  = 0;

        foreach ($raw as $row) {
            $slug = $this->slugFor($row['bookmaker']);

            if ($slug === null) {
                $this->unmappedCodes[$row['bookmaker']] = ($this->unmappedCodes[$row['bookmaker']] ?? 0) + 1;
                continue;
            }

            $selections[$row['selection']] ??= ['label' => $row['label'], 'prices' => []];

            if (!is_numeric($row['decimal'])) {
                ++$unavailable;
                continue;
            }

            try {
                $selections[$row['selection']]['prices'][$slug] = new Price((float) $row['decimal']);
            } catch (InvalidArgumentException $exception) {
                $this->warn(sprintf('Unusable %s price for %s: %s', $slug, $row['label'], $exception->getMessage()));
            }
        }

        if ($unavailable > 0) {
            $this->unavailable['prices']  += $unavailable;
            $this->unavailable['markets'] += 1;
        }

        return new MarketOdds($event->id, $market->key, $this->id(), $this->now(), $selections);
    }

    /**
     * Registry slug for one of this source's bookmaker codes.
     *
     * @param string $code Source code.
     * @return string|null
     */
    private function slugFor(string $code): ?string
    {
        if ($this->slugsByCode === null) {
            $this->slugsByCode = [];

            foreach ($this->bookmakers->all() as $bookmaker) {
                $sourceCode = $bookmaker->sourceCode($this->id());

                if ($sourceCode !== null) {
                    $this->slugsByCode[$sourceCode] = $bookmaker->slug;
                }
            }
        }

        return $this->slugsByCode[$code] ?? null;
    }
}
