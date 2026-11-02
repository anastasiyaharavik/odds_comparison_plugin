<?php

/**
 * Demo/Live data mode: setting values and the reset on a switch.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Scheduler;

use DateTimeImmutable;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Domain\Price;
use OddsComparison\Scheduler\CronSchedule;
use OddsComparison\Scheduler\DataModeSwitch;
use OddsComparison\Settings\Settings;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\OddsRepository;
use OddsComparison\Support\OptionKeys;
use OddsComparison\Support\StatusRecorder;
use PHPUnit\Framework\TestCase;

final class DataModeSwitchTest extends TestCase
{
    private OddsRepository $repository;

    private EventIndex $events;

    private StatusRecorder $status;

    protected function setUp(): void
    {
        $GLOBALS['odds_comparison_test_options']    = [];
        $GLOBALS['odds_comparison_test_transients'] = [];
        $GLOBALS['odds_comparison_test_cron']       = [];
        $GLOBALS['odds_comparison_test_cache']      = [];

        $this->repository = new OddsRepository(static fn (): int => 60);
        $this->events     = new EventIndex();
        $this->status     = new StatusRecorder();
    }

    public function testNewInstallsStartInDemoMode(): void
    {
        $settings = new Settings();

        self::assertSame('demo', $settings->dataMode());
        self::assertTrue($settings->isDemo());
    }

    /**
     * @dataProvider modes
     */
    public function testStoredModeValues(mixed $stored, string $expected): void
    {
        update_option(OptionKeys::SETTINGS, ['data_mode' => $stored]);

        self::assertSame($expected, (new Settings())->dataMode());
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function modes(): array
    {
        return [
            'demo'                 => ['demo', 'demo'],
            'live'                 => ['live', 'live'],
            'recorded (earlier)'   => ['recorded', 'demo'],
            'unknown falls to live' => ['other', 'live'],
            'not a string'         => [1, 'live'],
        ];
    }

    private function seed(): void
    {
        $event = Event::match(
            'bettingodds',
            'premier-league/x',
            'premier-league',
            'Chelsea vs Arsenal',
            'Chelsea',
            'Arsenal',
            new DateTimeImmutable('+2 days'),
            '/football/premier-league'
        );
        $this->events->replace('bettingodds', 'premier-league', [$event], new DateTimeImmutable());
        $this->repository->save(new MarketOdds(
            $event->id,
            'match-result',
            'bettingodds',
            new DateTimeImmutable(),
            ['home' => ['label' => 'Chelsea', 'prices' => ['betfred' => new Price(2.5)]]]
        ));
        $this->status->recordCompetitionFetch('theoddsapi', 'premier-league');
        $this->status->onAfterRefresh(['source' => 'bettingodds', 'fetched' => 1]);
    }

    public function testSwitchingModesClearsDataAndQueuesARefresh(): void
    {
        $this->seed();

        $switch = new DataModeSwitch($this->repository, $this->events, $this->status);
        $switch->onSettingsSaved(['data_mode' => 'live'] + Settings::DEFAULTS, Settings::DEFAULTS);

        self::assertNull($this->repository->get('bettingodds:premier-league/x', 'match-result'));
        self::assertSame([], (new EventIndex())->all());
        self::assertSame([], $this->status->source('theoddsapi')['fetchedAt'], 'per-source status reset');
        self::assertNotNull($this->status->lastRun(), 'run history kept');
        self::assertNotFalse(wp_next_scheduled(CronSchedule::HOOK, ['manual']), 'refresh queued');
    }

    public function testOtherSettingChangesKeepTheData(): void
    {
        $this->seed();

        $switch = new DataModeSwitch($this->repository, $this->events, $this->status);
        $switch->onSettingsSaved(['refresh_interval' => 30] + Settings::DEFAULTS, Settings::DEFAULTS);
        // "recorded" from earlier builds already meant demo data.
        $switch->onSettingsSaved(['data_mode' => 'recorded'] + Settings::DEFAULTS, Settings::DEFAULTS);

        self::assertNotNull($this->repository->get('bettingodds:premier-league/x', 'match-result'));
        self::assertCount(1, (new EventIndex())->all());
        self::assertSame([], $GLOBALS['odds_comparison_test_cron']);
    }

    public function testRegistersOnSettingsSaved(): void
    {
        $GLOBALS['odds_comparison_test_filters'] = [];
        (new DataModeSwitch($this->repository, $this->events, $this->status))->register();

        self::assertArrayHasKey('odds_comparison/settings_saved', $GLOBALS['odds_comparison_test_filters']);
    }

    public function testStaleThresholdAndRefreshAgeArePerSource(): void
    {
        update_option(OptionKeys::SETTINGS, [
            'refresh_interval'     => 15,
            'stale_after'          => 60,
            'api_refresh_interval' => 180,
        ]);
        $settings = new Settings();

        self::assertSame([15, 60], [$settings->refreshAgeFor('bettingodds'), $settings->staleAfterFor('bettingodds')]);
        // The Odds API is fetched every 3 hours, so its odds are on schedule up to 3 h 15 min old.
        self::assertSame([180, 195], [$settings->refreshAgeFor('theoddsapi'), $settings->staleAfterFor('theoddsapi')]);

        $repository = new OddsRepository(
            static fn (string $sourceId): int => $settings->staleAfterFor($sourceId),
            static fn (): int => 10_000_000
        );
        $odds       = static fn (string $source): MarketOdds => new MarketOdds(
            $source . ':premier-league/x',
            'match-result',
            $source,
            new DateTimeImmutable('@' . (10_000_000 - 120 * 60)),
            ['home' => ['label' => 'A', 'prices' => ['betfred' => new Price(2.0)]]]
        );

        self::assertTrue($repository->isStale($odds('bettingodds')), '2 h old scraped odds are stale');
        self::assertFalse($repository->isStale($odds('theoddsapi')), '2 h old API odds are on schedule');
    }

    public function testRequestIntervalIsCappedSoARunCanMakeSeveralRequests(): void
    {
        update_option(OptionKeys::SETTINGS, ['min_request_interval' => 30]);

        self::assertSame(Settings::MAX_REQUEST_INTERVAL, (new Settings())->minRequestInterval());
    }
}
