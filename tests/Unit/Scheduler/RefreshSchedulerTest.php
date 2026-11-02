<?php

/**
 * Lock, StatusRecorder and RefreshScheduler on recorded BettingOdds responses.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Scheduler;

use DateTimeImmutable;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Http\RecordedHttpClient;
use OddsComparison\Http\TimeBudgetExceeded;
use OddsComparison\Odds\OddsConverter;
use OddsComparison\Odds\OddsValidator;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Scheduler\Lock;
use OddsComparison\Scheduler\OddsRefresher;
use OddsComparison\Scheduler\RefreshScheduler;
use OddsComparison\Settings\Settings;
use OddsComparison\Source\BettingOdds\BettingOddsSource;
use OddsComparison\Source\SourceFactory;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\OddsRepository;
use OddsComparison\Storage\TrackedEventsIndex;
use OddsComparison\Support\Deadline;
use OddsComparison\Support\Logger;
use OddsComparison\Support\OptionKeys;
use OddsComparison\Support\RobotsMonitor;
use OddsComparison\Support\StatusRecorder;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class RefreshSchedulerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    private const CAPTURED = '2026-10-30T12:28:06Z';

    private const CHELSEA = 'bettingodds:premier-league/4othg6zhiyox0rivijbld3swk';

    protected function setUp(): void
    {
        $GLOBALS['odds_comparison_test_transients'] = [];
        $GLOBALS['odds_comparison_test_options']    = [];
        $GLOBALS['odds_comparison_test_actions']    = [];

        $hooks = [
            'odds_comparison/odds_updated',
            'odds_comparison/source_failed',
            'odds_comparison/before_refresh',
            'odds_comparison/after_refresh',
        ];

        foreach ($hooks as $hook) {
            remove_all_filters($hook);
        }
    }

    public function testLockIsExclusiveAndStaleLocksAreTakenOver(): void
    {
        $lock = new Lock();

        self::assertTrue($lock->acquire());
        self::assertFalse((new Lock())->acquire(), 'second run is blocked');
        self::assertTrue($lock->isLocked());

        $lock->release();
        self::assertFalse($lock->isLocked());

        update_option(OptionKeys::LOCK, (string) (time() - Lock::STALE_SECONDS - 1));
        self::assertFalse($lock->isLocked());
        self::assertTrue((new Lock())->acquire(), 'a crashed run does not block forever');
    }

    public function testOnlyOneProcessTakesOverAStaleLockAndOldHoldersCannotReleaseIt(): void
    {
        $crashed = new Lock();
        self::assertTrue($crashed->acquire());

        // The crashed run's lock goes stale; two runs find it at the same time.
        update_option(OptionKeys::LOCK, (time() - Lock::STALE_SECONDS - 1) . ':old');
        $first  = new Lock();
        $second = new Lock();

        self::assertTrue($first->acquire(), 'the first takeover wins');
        self::assertFalse($second->acquire(), 'the second sees a fresh lock');

        $crashed->release();
        self::assertTrue($first->isLocked(), 'a taken-over run cannot release its successor\'s lock');

        $first->release();
        self::assertFalse($first->isLocked());
    }

    public function testLockValueWithoutTimeDoesNotBlockForever(): void
    {
        update_option(OptionKeys::LOCK, 'garbage');

        self::assertFalse((new Lock())->isLocked());
        self::assertTrue((new Lock())->acquire());
    }

    public function testStatusRecorderObservesThePipeline(): void
    {
        $recorder = new StatusRecorder();
        $recorder->register();

        do_action('odds_comparison/odds_updated', self::CHELSEA, 'match-result', []);
        do_action('odds_comparison/source_failed', 'theoddsapi', new WP_Error('x', 'HTTP 401'));
        do_action('odds_comparison/after_refresh', ['fetched' => 3]);

        self::assertGreaterThan(0, $recorder->source('bettingodds')['lastSuccess']);
        self::assertSame('HTTP 401', $recorder->source('theoddsapi')['lastError']);
        self::assertSame(['fetched' => 3], $recorder->lastRun());

        foreach (range(4, 4 + StatusRecorder::MAX_RUNS) as $fetched) {
            do_action('odds_comparison/after_refresh', ['fetched' => $fetched]);
        }

        $runs = $recorder->recentRuns();
        self::assertCount(StatusRecorder::MAX_RUNS, $runs, 'history is bounded');
        self::assertSame(['fetched' => 4 + StatusRecorder::MAX_RUNS], $runs[0], 'newest first');
        self::assertArrayNotHasKey(OptionKeys::STATUS, array_filter(
            $GLOBALS['odds_comparison_test_options'],
            static fn ($value): bool => !is_array($value)
        ));
    }

    private function scheduler(int $requestsPerRun, ?Deadline $deadline = null): array
    {
        if (!is_file(self::ROOT . 'fixtures/bettingodds/manifest.json')) {
            self::markTestSkipped('No recorded BettingOdds responses.');
        }

        update_option(OptionKeys::SETTINGS, [
            'active_source'        => 'bettingodds',
            'enabled_competitions' => ['premier-league'],
            'enabled_markets'      => ['match-result', 'outright-winner'],
            'requests_per_run'     => $requestsPerRun,
        ]);

        $clock        = static fn (): DateTimeImmutable => new DateTimeImmutable(self::CAPTURED);
        $http         = new RecordedHttpClient(self::ROOT . 'fixtures');
        $bookmakers   = new BookmakerRegistry(self::ROOT . 'config/bookmakers.php');
        $competitions = new CompetitionRegistry(self::ROOT . 'config/competitions.php');
        $sources      = new SourceFactory(
            [BettingOddsSource::ID => BettingOddsSource::class],
            [$http, $bookmakers, $competitions, new OddsValidator(), $clock]
        );
        $settings     = new Settings();
        $events       = new EventIndex();
        $tracked      = new TrackedEventsIndex();
        $repository   = new OddsRepository(
            static fn (): int => 60,
            static fn (): int => (new DateTimeImmutable(self::CAPTURED))->getTimestamp()
        );
        $status       = new StatusRecorder();
        $status->register(); // Observer of after_refresh, as wired by Plugin.
        $refresher    = new OddsRefresher(
            $sources,
            $events,
            new MarketRegistry(self::ROOT . 'config/markets.php'),
            $repository,
            $tracked,
            new Logger(),
            $clock
        );
        $scheduler    = new RefreshScheduler(
            $settings,
            $competitions,
            $events,
            $tracked,
            $repository,
            $refresher,
            new RobotsMonitor($http, $sources, $competitions, $status),
            $status,
            new Lock(),
            $deadline ?? new Deadline(),
            $clock
        );

        return [$scheduler, $tracked, $events, $repository, $status];
    }

    public function testRunRespectsTheRequestBudgetAndKickOffOrder(): void
    {
        [$scheduler, $tracked, $events, $repository, $status] = $this->scheduler(3);

        // First run without tracked pairs: robots.txt + discovery.
        $first = $scheduler->run();
        self::assertGreaterThan(10, $first['discovered']);
        self::assertSame(0, $first['fetched']);
        self::assertTrue($status->source('bettingodds')['robots']['allowed']);

        $later = null;
        foreach ($events->search('bettingodds') as $event) {
            $afterChelsea = $event->startsAt > $events->get(self::CHELSEA)?->startsAt;

            if (!$event->isOutright() && $event->id !== self::CHELSEA && $afterChelsea) {
                $later = $event;
                break;
            }
        }
        self::assertNotNull($later);

        $tracked->trackPreview('bettingodds:premier-league/outrights', 'outright-winner');
        $tracked->trackPreview($later->id, 'match-result');
        $tracked->trackPreview(self::CHELSEA, 'match-result');

        self::assertSame(
            [self::CHELSEA, $later->id, 'bettingodds:premier-league/outrights'],
            array_column($scheduler->duePairs('bettingodds'), 'eventId'),
            'soonest kick-off first, outrights last'
        );

        // Budget 3 = discovery (robots already checked today) + 2 pairs; the outrights wait.
        $second = $scheduler->run('manual');
        self::assertSame(
            [2, 0, 1, 'manual'],
            [$second['fetched'], $second['failed'], $second['skipped'], $second['trigger']]
        );
        self::assertNotNull($repository->get(self::CHELSEA, 'match-result'));
        $stillDue = array_column($scheduler->duePairs('bettingodds'), 'eventId');
        self::assertSame(['bettingodds:premier-league/outrights'], $stillDue);

        $third = $scheduler->run();
        self::assertSame(1, $third['fetched'], 'the deferred pair is fetched next run');
        self::assertSame([], $scheduler->duePairs('bettingodds'));
        self::assertSame($third, $status->lastRun());
    }

    public function testOverlappingRunIsSkipped(): void
    {
        [$scheduler] = $this->scheduler(3);

        (new Lock())->acquire();
        $summary = $scheduler->run();

        self::assertTrue($summary['locked'] ?? false);
        self::assertSame(0, $summary['discovered']);
    }

    public function testPairsOfOtherSourcesOrDisabledMarketsAreNotDue(): void
    {
        [$scheduler, $tracked, $events] = $this->scheduler(3);
        $scheduler->run();

        $tracked->trackPreview(self::CHELSEA, 'top-goalscorer');
        $tracked->trackPreview('theoddsapi:premier-league/abc', 'match-result');

        self::assertSame([], $scheduler->duePairs('bettingodds'));
        self::assertNotNull($events->get(self::CHELSEA));
    }

    public function testRunStopsBeforeAStepThatWouldOutlastTheTimeBudget(): void
    {
        $now      = 0.0;
        $deadline = new Deadline(static function () use (&$now): float {
            return $now;
        });

        [$scheduler, $tracked, $events] = $this->scheduler(20, $deadline);
        $scheduler->run();

        $pairs = [];
        foreach ($events->search('bettingodds') as $event) {
            if (!$event->isOutright() && count($pairs) < 3) {
                $pairs[] = $event->id;
                $tracked->trackPreview($event->id, 'match-result');
            }
        }

        // Every fetch takes 9 s: after two, 7 s are left, less than the slowest step.
        add_action('odds_comparison/odds_updated', static function () use (&$now): void {
            $now += 9.0;
        });
        $started = [];
        add_action('odds_comparison/before_refresh', static function (string $source) use (&$started): void {
            $started[] = $source;
        });

        $summary = $scheduler->run();

        self::assertSame(['bettingodds'], $started);
        self::assertSame([2, 1, 18.0], [$summary['fetched'], $summary['skipped'], $summary['seconds']]);
        self::assertNull($deadline->remaining(), 'the deadline ends with the run');
        self::assertCount(1, $scheduler->duePairs('bettingodds'), 'the skipped pair waits for the next run');
    }

    public function testAPairCutByTheDeadlineIsSkippedNotFailed(): void
    {
        $event   = Event::match(
            'slow',
            'premier-league/x',
            'premier-league',
            'A vs B',
            'A',
            'B',
            new DateTimeImmutable('2026-11-01T15:00:00Z'),
            '/a-b'
        );
        $source  = new class implements SourceInterface {
            public function id(): string
            {
                return 'slow';
            }

            public function baseUrl(): string
            {
                return 'https://slow.example';
            }

            public function discoverEvents(Competition $competition): array
            {
                throw new TimeBudgetExceeded('out of time');
            }

            public function fetchMarket(Event $event, Market $market): MarketOdds
            {
                throw new TimeBudgetExceeded('out of time');
            }
        };
        $events  = new EventIndex();
        $events->replace('slow', 'premier-league', [$event], new DateTimeImmutable(self::CAPTURED));
        $logger    = new Logger();
        $refresher = new OddsRefresher(
            new SourceFactory(['slow' => static fn (): SourceInterface => $source]),
            $events,
            new MarketRegistry(self::ROOT . 'config/markets.php'),
            new OddsRepository(static fn (): int => 60),
            new TrackedEventsIndex(),
            $logger
        );
        $failures  = 0;
        add_action('odds_comparison/source_failed', static function () use (&$failures): void {
            ++$failures;
        });

        $competitions = new CompetitionRegistry(self::ROOT . 'config/competitions.php');
        self::assertSame(OddsRefresher::SKIPPED, $refresher->refreshPair($event->id, 'match-result'));
        self::assertNull($refresher->discover('slow', $competitions->get('premier-league')));
        self::assertNotNull($events->get($event->id), 'the event index is unchanged');
        self::assertSame(0, $failures);
        self::assertSame([], $logger->entries());
    }
}
