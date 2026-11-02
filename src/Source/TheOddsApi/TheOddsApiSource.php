<?php

/**
 * The Odds API source (licensed feed).
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Source\TheOddsApi;

use Closure;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Http\HttpResponse;
use OddsComparison\Odds\OddsValidator;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Source\AbstractSource;
use OddsComparison\Support\OptionKeys;
use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Reads The Odds API v4: live data where the scraper is blocked.
 *
 * - One `/odds` request per competition returns every event with every `uk` bookmaker's
 *   match-result (`h2h`) prices. Discovery and pricing share it (memoized per run), so one
 *   competition costs 1 credit per fetch.
 * - Outright markets are not offered (the API has none for football).
 * - The key comes from the composition root (wp-config constant or environment), is only
 *   placed in request URLs, and is redacted from every error by the HTTP client.
 * - `x-requests-remaining` is stored for the Status tab. Requests stop when fewer than
 *   {@see self::MIN_CREDITS} credits remain.
 */
final class TheOddsApiSource extends AbstractSource
{
    public const ID = 'theoddsapi';

    /** Hardcoded base URL (SSRF guard). */
    private const BASE_URL = 'https://api.the-odds-api.com';

    /** Bookmaker region. */
    private const REGION = 'uk';

    /** API market key for match result. */
    private const H2H = 'h2h';

    /** Stop below this many remaining credits. */
    public const MIN_CREDITS = 10;

    /** Transient holding the last known quota. */
    public const QUOTA_TRANSIENT = OptionKeys::TRANSIENT_PREFIX . 'theoddsapi_quota';

    /**
     * @param HttpClientInterface   $http         Transport.
     * @param BookmakerRegistry     $bookmakers   Bookmaker registry.
     * @param CompetitionRegistry   $competitions Competition registry.
     * @param OddsValidator         $validator    Validator.
     * @param string                $apiKey       API key ("" when not configured).
     * @param Closure|null          $clock        fn(): DateTimeImmutable.
     * @param TheOddsApiMapper|null $mapper       JSON mapper.
     */
    public function __construct(
        HttpClientInterface $http,
        BookmakerRegistry $bookmakers,
        CompetitionRegistry $competitions,
        OddsValidator $validator,
        private readonly string $apiKey,
        ?Closure $clock = null,
        private readonly TheOddsApiMapper $mapper = new TheOddsApiMapper()
    ) {
        parent::__construct($http, $bookmakers, $competitions, $validator, $clock);
    }

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * {@inheritDoc}
     */
    public function baseUrl(): string
    {
        return self::BASE_URL;
    }

    /**
     * Whether an API key is configured.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Last known quota, or null before the first metered request.
     *
     * @return array{remaining: int, used: int, checkedAt: int}|null
     */
    public static function quota(): ?array
    {
        $quota = get_transient(self::QUOTA_TRANSIENT);

        return is_array($quota) && isset($quota['remaining'], $quota['used'], $quota['checkedAt'])
            ? [
                'remaining' => (int) $quota['remaining'],
                'used'      => (int) $quota['used'],
                'checkedAt' => (int) $quota['checkedAt'],
            ]
            : null;
    }

    /**
     * {@inheritDoc}
     */
    protected function competitionUrl(Competition $competition): ?string
    {
        $path = $competition->sourcePath(self::ID);

        if ($path === null) {
            return null;
        }

        // Query order must match the recorded fixtures (regions, markets, oddsFormat, apiKey).
        return self::BASE_URL . $path . '?' . http_build_query([
            'regions'    => self::REGION,
            'markets'    => self::H2H,
            'oddsFormat' => 'decimal',
            'apiKey'     => $this->apiKey,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    protected function marketUrl(Event $event, Market $market, Competition $competition): ?string
    {
        if ($event->isOutright() || $market->sourceName(self::ID) !== self::H2H) {
            return null;
        }

        return $this->competitionUrl($competition);
    }

    /**
     * {@inheritDoc}
     */
    protected function parseEvents(string $body, Competition $competition): array
    {
        $events = [];

        foreach ($this->mapper->parseEvents($body) as $raw) {
            try {
                $events[] = Event::match(
                    self::ID,
                    $competition->slug . '/' . $raw['id'],
                    $competition->slug,
                    $raw['home'] . ' vs ' . $raw['away'],
                    $raw['home'],
                    $raw['away'],
                    new DateTimeImmutable($raw['commenceTime']),
                    (string) $competition->sourcePath(self::ID)
                );
            } catch (InvalidArgumentException | Exception $exception) {
                $this->warn(sprintf('Skipped API event %s: %s', $raw['id'], $exception->getMessage()));
            }
        }

        $this->warn(...$this->mapper->warnings());

        return $events;
    }

    /**
     * {@inheritDoc}
     */
    protected function parseMarket(string $body, Event $event, Market $market): array
    {
        $apiEventId = substr($event->id, strrpos($event->id, '/') + 1);
        $rows       = [];

        foreach ($this->mapper->parsePrices($body, $apiEventId, self::H2H) as $price) {
            $selection = self::matchSelection($event, $price['participant']);

            if ($selection !== null) {
                $rows[] = [
                    'selection' => $selection[0],
                    'label'     => $selection[1],
                    'bookmaker' => $price['bookmaker'],
                    'decimal'   => $price['decimal'],
                ];
            }
        }

        $this->warn(...$this->mapper->warnings());

        return $rows;
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException When the key is missing or the quota is nearly exhausted.
     */
    protected function beforeRequest(string $url): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('The Odds API key is not configured (ODDS_COMPARISON_THEODDSAPI_KEY).');
        }

        $quota = self::quota();

        if ($quota !== null && $quota['remaining'] < self::MIN_CREDITS) {
            throw new RuntimeException(
                sprintf(
                    'The Odds API quota is nearly exhausted (%d credits left); requests paused.',
                    (int) $quota['remaining']
                )
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function afterResponse(HttpResponse $response): void
    {
        $remaining = $response->header('x-requests-remaining');

        if ($remaining !== null && is_numeric($remaining)) {
            set_transient(
                self::QUOTA_TRANSIENT,
                [
                    'remaining' => (int) $remaining,
                    'used'      => (int) ($response->header('x-requests-used') ?? 0),
                    'checkedAt' => $this->now()->getTimestamp(),
                ],
                31 * 86400
            );
        }
    }
}
