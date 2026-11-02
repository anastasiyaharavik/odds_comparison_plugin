<?php

/**
 * Deactivation and uninstall leave no cron events and no transients behind.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Lifecycle;

use DateTimeImmutable;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Domain\Price;
use OddsComparison\Http\CircuitBreaker;
use OddsComparison\Http\RateLimiter;
use OddsComparison\Lifecycle\Uninstaller;
use OddsComparison\Scheduler\CronSchedule;
use OddsComparison\Scheduler\OddsRefresher;
use OddsComparison\Source\TheOddsApi\TheOddsApiSource;
use OddsComparison\Storage\OddsRepository;
use OddsComparison\Support\OptionKeys;
use PHPUnit\Framework\TestCase;

final class LifecycleCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['odds_comparison_test_options']    = [];
        $GLOBALS['odds_comparison_test_transients'] = [];
        $GLOBALS['odds_comparison_test_cron']       = [];
    }

    public function testUnscheduleAllRemovesQueuedManualRunsToo(): void
    {
        wp_schedule_single_event(time() + 60, CronSchedule::HOOK);
        wp_schedule_single_event(time(), CronSchedule::HOOK, ['manual']);
        wp_schedule_single_event(time(), 'another_plugin_hook');

        (new CronSchedule())->unscheduleAll();

        self::assertSame(['another_plugin_hook'], array_column($GLOBALS['odds_comparison_test_cron'], 'hook'));
    }

    public function testUninstallDeletesTransientsThroughTheApi(): void
    {
        // Transients as a persistent object cache holds them: never in the options table.
        (new OddsRepository(static fn (): int => 60))->save(new MarketOdds(
            'bettingodds:premier-league/x',
            'match-result',
            'bettingodds',
            new DateTimeImmutable(),
            ['home' => ['label' => 'A', 'prices' => ['betfred' => new Price(2.0)]]]
        ));
        set_transient(TheOddsApiSource::QUOTA_TRANSIENT, ['remaining' => 400], 31 * 86400);
        set_transient(CircuitBreaker::transientName('www.bettingodds.com'), ['state' => 'open'], 3600);
        set_transient(RateLimiter::transientName('api.the-odds-api.com'), '1', 3600);
        set_transient(OddsRefresher::diagnosticsTransient('bettingodds', 'warnings'), 'x', 86400);
        update_option(OptionKeys::SETTINGS, ['data_mode' => 'demo']);
        update_option('_transient_odds_comparison_custom', 'stored in the table');
        update_option('unrelated_option', 'kept');
        wp_schedule_single_event(time(), CronSchedule::HOOK, ['manual']);

        (new Uninstaller())->uninstall();

        self::assertSame([], $GLOBALS['odds_comparison_test_transients']);
        self::assertSame(['unrelated_option' => 'kept'], $GLOBALS['odds_comparison_test_options']);
        self::assertSame([], $GLOBALS['odds_comparison_test_cron']);
    }
}
