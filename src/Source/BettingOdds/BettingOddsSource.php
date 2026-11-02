<?php

/**
 * BettingOdds.com scraper source.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Source\BettingOdds;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Odds\OddsValidator;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Source\AbstractSource;

defined('ABSPATH') || exit;

/**
 * Scrapes BettingOdds.com, an existing odds comparison site.
 *
 * - Events: one request per competition listing. The source's stable match id (`data-id`)
 *   becomes the local event id `{competition}/{data-id}`, so ids survive the match getting its
 *   own page. Each competition with outright pages also gets one outrights event.
 * - Match result: priced from the competition listing, which carries every bookmaker's grid.
 *   Memoized per run, so every match of a competition costs one request.
 * - Outright markets: one request per market page.
 * - Listing times are UK local time without a year; they resolve to the occurrence nearest to
 *   "now" whose weekday matches the listing ("Saturday 31 October").
 */
final class BettingOddsSource extends AbstractSource
{
    public const ID = 'bettingodds';

    /** Hardcoded base URL; only registry paths and validated scraped paths are appended (SSRF guard). */
    private const BASE_URL = 'https://www.bettingodds.com';

    /** Time zone of listing times. */
    private const TIMEZONE = 'Europe/London';

    /**
     * @param HttpClientInterface    $http         Transport.
     * @param BookmakerRegistry      $bookmakers   Bookmaker registry.
     * @param CompetitionRegistry    $competitions Competition registry.
     * @param OddsValidator          $validator    Validator.
     * @param Closure|null           $clock        fn(): DateTimeImmutable.
     * @param BettingOddsParser|null $parser       Page parser.
     */
    public function __construct(
        HttpClientInterface $http,
        BookmakerRegistry $bookmakers,
        CompetitionRegistry $competitions,
        OddsValidator $validator,
        ?Closure $clock = null,
        private readonly BettingOddsParser $parser = new BettingOddsParser()
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
     * {@inheritDoc}
     */
    protected function competitionUrl(Competition $competition): ?string
    {
        $path = $competition->sourcePath(self::ID);

        return $path === null ? null : self::BASE_URL . $path;
    }

    /**
     * {@inheritDoc}
     */
    protected function marketUrl(Event $event, Market $market, Competition $competition): ?string
    {
        if ($market->sourceName(self::ID) === null || $market->isOutright() !== $event->isOutright()) {
            return null;
        }

        $path = $market->isOutright()
            ? $competition->outrightPath(self::ID, $market->key)
            : $competition->sourcePath(self::ID);

        return $path === null ? null : self::BASE_URL . $path;
    }

    /**
     * {@inheritDoc}
     */
    protected function parseEvents(string $body, Competition $competition): array
    {
        $events      = [];
        $listingPath = (string) $competition->sourcePath(self::ID);

        foreach ($this->parser->parseEvents($body) as $raw) {
            $startsAt = $this->kickoff($raw['day'], $raw['time']);

            if ($startsAt === null) {
                $this->warn(sprintf('Unreadable kick-off "%s %s" for %s.', $raw['day'], $raw['time'], $raw['name']));
                continue;
            }

            try {
                $events[] = Event::match(
                    self::ID,
                    $competition->slug . '/' . $raw['id'],
                    $competition->slug,
                    $raw['name'],
                    $raw['home'],
                    $raw['away'],
                    $startsAt,
                    $raw['path'] !== '' ? $raw['path'] : $listingPath
                );
            } catch (InvalidArgumentException $exception) {
                $this->warn(sprintf('Skipped event %s: %s', $raw['name'], $exception->getMessage()));
            }
        }

        $this->warn(...$this->parser->warnings());

        if ($competition->outrightMarketKeys(self::ID) !== []) {
            $events[] = Event::outrights(self::ID, $competition->slug, $competition->name . ' Outrights', $listingPath);
        }

        return $events;
    }

    /**
     * {@inheritDoc}
     */
    protected function parseMarket(string $body, Event $event, Market $market): array
    {
        $name   = (string) $market->sourceName(self::ID);
        $prices = $market->isOutright()
            ? $this->parser->parsePrices($body, $name)
            : $this->parser->parsePrices($body, $name, $event->name);
        $rows   = [];

        $this->warn(...$this->parser->warnings());

        foreach ($prices as $price) {
            $selection = $market->isOutright()
                ? [self::selectionKey($price['participant']), $price['participant']]
                : self::matchSelection($event, $price['participant']);

            if ($selection === null) {
                continue;
            }

            $rows[] = [
                'selection' => $selection[0],
                'label'     => $selection[1],
                'bookmaker' => $price['bookmaker'],
                'decimal'   => $price['decimal'],
            ];
        }

        return $rows;
    }

    /**
     * Resolve "Saturday 31 October" + "12:30" (UK time, no year) to a UTC instant.
     *
     * @param string $day  Day heading.
     * @param string $time "HH:MM".
     * @return DateTimeImmutable|null
     */
    private function kickoff(string $day, string $time): ?DateTimeImmutable
    {
        if (preg_match('/^([A-Za-z]+)\s+(\d{1,2})\s+([A-Za-z]+)$/', $day, $parts) !== 1) {
            return null;
        }

        $zone = new DateTimeZone(self::TIMEZONE);
        $now  = $this->now();
        $best = null;

        foreach ([-1, 0, 1] as $offset) {
            $year      = (int) $now->setTimezone($zone)->format('Y') + $offset;
            $text      = "{$parts[2]} {$parts[3]} {$year} {$time}";
            $candidate = DateTimeImmutable::createFromFormat('!j F Y H:i', $text, $zone);

            if ($candidate === false || strcasecmp($candidate->format('l'), $parts[1]) !== 0) {
                continue;
            }

            $distance = abs($candidate->getTimestamp() - $now->getTimestamp());

            if ($best === null || $distance < $best[0]) {
                $best = [$distance, $candidate];
            }
        }

        return $best === null ? null : $best[1]->setTimezone(new DateTimeZone('UTC'));
    }
}
