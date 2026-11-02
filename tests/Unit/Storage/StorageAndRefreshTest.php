<?php

/**
 * OddsRepository, EventIndex, Logger and OddsRefresher.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Storage;

use DateTimeImmutable;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Domain\Price;
use OddsComparison\Http\RecordedHttpClient;
use OddsComparison\Odds\OddsConverter;
use OddsComparison\Odds\OddsValidator;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Scheduler\OddsRefresher;
use OddsComparison\Source\BettingOdds\BettingOddsSource;
use OddsComparison\Source\SourceFactory;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\OddsRepository;
use OddsComparison\Storage\TrackedEventsIndex;
use OddsComparison\Support\Logger;
use OddsComparison\Support\OptionKeys;
use PHPUnit\Framework\TestCase;

final class StorageAndRefreshTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    private const CAPTURED = '2026-10-30T12:28:06Z';

    private int $now;

    protected function setUp(): void
    {
        $GLOBALS['odds_comparison_test_transients'] = [];
        $GLOBALS['odds_comparison_test_options']    = [];
        $GLOBALS['odds_comparison_test_actions']    = [];

        $hooks = ['odds_comparison/market_odds', 'odds_comparison/odds_updated', 'odds_comparison/source_failed'];

        foreach ($hooks as $hook) {
            remove_all_filters($hook);
        }

        $this->now = (new DateTimeImmutable(self::CAPTURED))->getTimestamp();
    }

    private function odds(string $eventId = 'bettingodds:premier-league/x', int $fetchedAt = 0): MarketOdds
    {
        return new MarketOdds(
            $eventId,
            'match-result',
            'bettingodds',
            new DateTimeImmutable('@' . ($fetchedAt ?: $this->now)),
            [
                'home' => ['label' => 'A', 'prices' => ['betfred' => new Price(2.0)]],
                'away' => ['label' => 'B', 'prices' => ['betfred' => new Price(2.0)]],
            ]
        );
    }

    private static function match(
        string $localId,
        string $home,
        string $away,
        DateTimeImmutable $startsAt,
        string $sourceId = 'bettingodds'
    ): Event {
        return Event::match(
            $sourceId,
            'premier-league/' . $localId,
            'premier-league',
            $home . ' vs ' . $away,
            $home,
            $away,
            $startsAt,
            $sourceId === 'bettingodds' ? '/football/premier-league' : '/v4/sports/soccer_epl/odds'
        );
    }

    private function repository(int $staleAfter = 60): OddsRepository
    {
        return new OddsRepository(static fn (): int => $staleAfter, fn (): int => $this->now);
    }

    public function testRepositoryRoundTripAndMemo(): void
    {
        $repository = $this->repository();
        $odds       = $this->odds();

        self::assertNull($repository->get($odds->eventId, 'match-result'));
        self::assertTrue($repository->save($odds));
        self::assertEquals($odds, $repository->get($odds->eventId, 'match-result'));

        // A fresh instance reads the transient (another request).
        self::assertEquals($odds, $this->repository()->get($odds->eventId, 'match-result'));

        foreach (array_keys($GLOBALS['odds_comparison_test_transients']) as $name) {
            self::assertStringStartsWith(OptionKeys::TRANSIENT_PREFIX, $name);
            self::assertLessThanOrEqual(172, strlen($name));
        }
    }

    public function testStalenessComesFromFetchedAt(): void
    {
        $repository = $this->repository(60);

        self::assertFalse($repository->isStale($this->odds(fetchedAt: $this->now - 59 * 60)));
        self::assertTrue($repository->isStale($this->odds(fetchedAt: $this->now - 61 * 60)));
    }

    public function testCorruptedTransientIsTreatedAsMissing(): void
    {
        $repository = $this->repository();
        $repository->save($this->odds());

        foreach (array_keys($GLOBALS['odds_comparison_test_transients']) as $name) {
            $GLOBALS['odds_comparison_test_transients'][$name] = ['eventId' => 'broken'];
        }

        self::assertNull($this->repository()->get('bettingodds:premier-league/x', 'match-result'));
    }

    public function testFlushAndDelete(): void
    {
        $repository = $this->repository();
        $repository->save($this->odds('bettingodds:premier-league/a'));
        $repository->save($this->odds('bettingodds:premier-league/b'));

        self::assertTrue($repository->delete('bettingodds:premier-league/a', 'match-result'));
        self::assertNull($repository->get('bettingodds:premier-league/a', 'match-result'));

        $repository->flush();
        self::assertSame([], $GLOBALS['odds_comparison_test_transients']);
        self::assertNull($this->repository()->get('bettingodds:premier-league/b', 'match-result'));
    }

    public function testStoredKeyListDropsDeletedAndExpiredEntries(): void
    {
        $repository = $this->repository();
        $repository->save($this->odds('bettingodds:premier-league/a'));
        $repository->save($this->odds('bettingodds:premier-league/b'));
        self::assertCount(2, get_option(OptionKeys::ODDS_KEYS));

        $repository->delete('bettingodds:premier-league/a', 'match-result');
        self::assertCount(1, get_option(OptionKeys::ODDS_KEYS), 'delete() removes its entry');

        // Eight days later the first transient has expired; saving another pair drops its entry.
        $this->now += 8 * 86400;
        $repository->save($this->odds('bettingodds:premier-league/c'));
        $keys = get_option(OptionKeys::ODDS_KEYS);
        self::assertCount(1, $keys);
        self::assertSame([$this->now + 7 * 86400], array_values($keys));
    }

    public function testStoredKeyListFromEarlierVersionsIsStillFlushed(): void
    {
        $GLOBALS['odds_comparison_test_transients']['odds_comparison_odds_legacy'] = ['x'];
        update_option(OptionKeys::ODDS_KEYS, ['odds_comparison_odds_legacy']);

        $this->repository()->flush();

        self::assertSame([], $GLOBALS['odds_comparison_test_transients']);
        self::assertFalse(get_option(OptionKeys::ODDS_KEYS));
    }

    public function testEventIndexReplacePruneAndSearch(): void
    {
        $now     = new DateTimeImmutable(self::CAPTURED);
        $index   = new EventIndex();
        $soon    = self::match('1', 'Chelsea', 'Manchester United', $now->modify('+1 day'));
        $later   = self::match('2', 'Arsenal', 'Hull City', $now->modify('+8 days'));
        $started = self::match('3', 'Leeds', 'Spurs', $now->modify('-1 hour'));
        $outs    = Event::outrights('bettingodds', 'premier-league', 'PL Outrights', '/football/premier-league');
        $other   = self::match('abc', 'Chelsea', 'Manchester United', $now->modify('+1 day'), 'theoddsapi');

        $index->replace('bettingodds', 'premier-league', [$later, $outs, $soon, $started], $now);
        $index->replace('theoddsapi', 'premier-league', [$other], $now);

        self::assertNotNull($index->get($started->id), 'a started match is kept, so blocks can say it started');
        self::assertSame(
            [$soon->id, $later->id, $outs->id],
            array_map(static fn (Event $e): string => $e->id, $index->search('bettingodds', '', 50, $now)),
            'soonest first, outrights last, started and other sources excluded'
        );
        $found = $index->search('bettingodds', 'chel', 50, $now);
        self::assertSame([$soon->id], array_map(static fn (Event $e): string => $e->id, $found));
        self::assertSame(
            [$soon->id],
            array_map(static fn (Event $e): string => $e->id, $index->search('bettingodds', '', 1, $now)),
            'started matches never take a place within the limit'
        );
        self::assertSame(2, $index->upcomingMatchCount('bettingodds', 'premier-league', $now));

        // Rediscovery replaces only that slice; the started match stays even when no longer listed.
        $index->replace('bettingodds', 'premier-league', [$soon, $outs], $now);
        self::assertNull((new EventIndex())->get($later->id));
        self::assertNotNull((new EventIndex())->get($started->id));
        self::assertNotNull((new EventIndex())->get($other->id));

        // Two days on, the next-day matches have started too: all kept, none upcoming.
        self::assertSame(0, $index->prune($now->modify('+2 days')));
        self::assertSame([$outs->id], array_map(
            static fn (Event $e): string => $e->id,
            $index->search('bettingodds', '', 50, $now->modify('+2 days'))
        ));

        // A week after kick-off, started matches are dropped; outrights stay.
        $weekLater = $now->modify('+1 day')
            ->modify('+' . EventIndex::STARTED_RETENTION . ' seconds')
            ->modify('+1 minute');
        self::assertSame(3, $index->prune($weekLater));
        self::assertSame([$outs->id], array_keys((new EventIndex())->all()));
    }

    public function testLoggerCapsAndRedacts(): void
    {
        $logger = new Logger();

        for ($i = 1; $i <= Logger::MAX_ENTRIES + 5; $i++) {
            $logger->error('bettingodds', "failure {$i}");
        }

        $logger->warning('theoddsapi', 'https://api.the-odds-api.com/v4/sports?apiKey=SECRET');
        $entries = $logger->entries();

        self::assertCount(Logger::MAX_ENTRIES, $entries);
        self::assertSame('warning', $entries[0]['level'], 'newest first');
        self::assertStringNotContainsString('SECRET', $entries[0]['message']);
        self::assertSame('failure 55', $entries[1]['message']);
    }

    private function refresher(OddsRepository $repository, EventIndex $events): OddsRefresher
    {
        if (!is_file(self::ROOT . 'fixtures/bettingodds/manifest.json')) {
            self::markTestSkipped('No recorded BettingOdds responses.');
        }

        $bookmakers   = new BookmakerRegistry(self::ROOT . 'config/bookmakers.php');
        $competitions = new CompetitionRegistry(self::ROOT . 'config/competitions.php');
        $clock        = static fn (): DateTimeImmutable => new DateTimeImmutable(self::CAPTURED);
        $sources      = new SourceFactory(
            [BettingOddsSource::ID => BettingOddsSource::class],
            [
                new RecordedHttpClient(self::ROOT . 'fixtures'),
                $bookmakers,
                $competitions,
                new OddsValidator(),
                $clock,
            ]
        );

        return new OddsRefresher(
            $sources,
            $events,
            new MarketRegistry(self::ROOT . 'config/markets.php'),
            $repository,
            new TrackedEventsIndex(),
            new Logger(),
            $clock
        );
    }

    public function testRefreshPipelineFiresHooksAndStores(): void
    {
        $repository   = $this->repository();
        $events       = new EventIndex();
        $refresher    = $this->refresher($repository, $events);
        $competitions = new CompetitionRegistry(self::ROOT . 'config/competitions.php');
        $chelsea      = 'bettingodds:premier-league/4othg6zhiyox0rivijbld3swk';

        self::assertGreaterThan(10, $refresher->discover('bettingodds', $competitions->get('premier-league')));
        self::assertNotNull($events->get($chelsea));

        // DOCUMENTATION.md snippet shapes: mutate before save, purge after save.
        add_filter('odds_comparison/market_odds', static fn (MarketOdds $odds): MarketOdds => $odds->withSelections(
            array_intersect_key($odds->selections, ['home' => true, 'draw' => true, 'away' => true])
        ));
        $purged = [];
        $listener = static function (string $eventId, string $marketKey, array $postIds) use (&$purged): void {
            $purged[] = [$eventId, $marketKey, $postIds];
        };
        add_action('odds_comparison/odds_updated', $listener, 10, 3);

        self::assertSame(OddsRefresher::FETCHED, $refresher->refreshPair($chelsea, 'match-result'));
        self::assertSame([[$chelsea, 'match-result', []]], $purged);
        self::assertCount(10, $repository->get($chelsea, 'match-result')?->bookmakerSlugs() ?? []);
    }

    public function testDiagnosticsAreLoggedOnceUntilTheyChange(): void
    {
        $repository   = $this->repository();
        $events       = new EventIndex();
        $refresher    = $this->refresher($repository, $events);
        $competitions = new CompetitionRegistry(self::ROOT . 'config/competitions.php');
        $chelsea      = 'bettingodds:premier-league/4othg6zhiyox0rivijbld3swk';

        $refresher->discover('bettingodds', $competitions->get('premier-league'));
        $refresher->refreshPair($chelsea, 'match-result');
        $refresher->reportDiagnostics('bettingodds');

        $entries = (new Logger())->entries();
        $unknown = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => str_contains($entry['message'], 'not configured')
        ));

        self::assertCount(1, $unknown, 'one entry for unknown bookmaker codes');
        self::assertSame(Logger::WARNING, $unknown[0]['level']);
        self::assertSame('bettingodds', $unknown[0]['source']);
        self::assertStringContainsString('bet365', $unknown[0]['message'], 'a real code missing from the registry');

        // The same problems in the next run are not logged again.
        $refresher->refreshPair($chelsea, 'match-result');
        $refresher->reportDiagnostics('bettingodds');
        self::assertCount(count($entries), (new Logger())->entries());

        // Reported diagnostics were cleared from the source.
        $refresher->reportDiagnostics('bettingodds');
        self::assertCount(count($entries), (new Logger())->entries());

        // A day later the reminder is due: the fingerprints expired.
        foreach (array_keys($GLOBALS['odds_comparison_test_transients']) as $name) {
            if (str_starts_with($name, 'odds_comparison_diag_')) {
                unset($GLOBALS['odds_comparison_test_transients'][$name]);
            }
        }

        $refresher->refreshPair($chelsea, 'match-result');
        $refresher->reportDiagnostics('bettingodds');
        $after = (new Logger())->entries();
        // The reminder repeats the newest entry, so it is counted instead of added.
        self::assertCount(count($entries), $after);
        self::assertSame(2, $after[0]['count']);
    }

    public function testRepeatedLogEntriesAreCollapsed(): void
    {
        $logger = new Logger();
        $logger->clear();

        foreach (range(1, 9) as $attempt) {
            $logger->error('bettingodds', 'Requests to www.bettingodds.com are paused (circuit open).');
        }

        $logger->error('bettingodds', 'Another problem.');
        $logger->error('bettingodds', 'Requests to www.bettingodds.com are paused (circuit open).');

        $entries = $logger->entries();
        self::assertSame(
            [['Requests to www.bettingodds.com are paused (circuit open).', 1], ['Another problem.', 1], [
                'Requests to www.bettingodds.com are paused (circuit open).',
                9,
            ]],
            array_map(static fn (array $entry): array => [$entry['message'], $entry['count']], $entries),
            'nine repeats are one entry; a repeat after another message starts a new one'
        );
        self::assertGreaterThan(0, $GLOBALS['wpdb']->lockCalls, 'written under the option lock');
        self::assertSame([], $GLOBALS['wpdb']->namedLocks, 'lock released');
    }

    public function testDiagnosticsOfUnknownSourceAreIgnored(): void
    {
        $refresher = $this->refresher($this->repository(), new EventIndex());
        $refresher->reportDiagnostics('no-such-source');

        self::assertSame([], (new Logger())->entries());
    }

    public function testFailedFetchKeepsPreviousDataAndFiresSourceFailed(): void
    {
        $repository = $this->repository();
        $events     = new EventIndex();
        $refresher  = $this->refresher($repository, $events);

        // A real event whose market page holds no usable prices: the competition's stats page.
        $stale = Event::match(
            'bettingodds',
            'serie-a/zz',
            'serie-a',
            'Nowhere vs Nobody',
            'Nowhere',
            'Nobody',
            new DateTimeImmutable('2026-11-01'),
            '/football/serie-a'
        );
        $events->replace('bettingodds', 'serie-a', [$stale], new DateTimeImmutable(self::CAPTURED));
        $previous = $this->odds($stale->id);
        $repository->save($previous);

        $failures = [];
        add_action('odds_comparison/source_failed', static function (string $sourceId, $error) use (&$failures): void {
            $failures[] = [$sourceId, $error->get_error_message()];
        }, 10, 2);

        self::assertSame(OddsRefresher::FAILED, $refresher->refreshPair($stale->id, 'match-result'));
        self::assertSame('bettingodds', $failures[0][0] ?? null);
        self::assertStringContainsString('No recorded response', $failures[0][1]);
        self::assertEquals($previous, $this->repository()->get($stale->id, 'match-result'), 'good data untouched');
        self::assertSame(OddsRefresher::SKIPPED, $refresher->refreshPair('bettingodds:unknown/1', 'match-result'));
    }
}
