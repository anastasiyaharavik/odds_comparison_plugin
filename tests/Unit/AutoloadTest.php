<?php

/**
 * Smoke test for the PSR-4 autoload mapping.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Lifecycle\Installer;
use OddsComparison\Lifecycle\Uninstaller;
use OddsComparison\Plugin;
use OddsComparison\Scheduler\CronSchedule;
use OddsComparison\Settings\Settings;
use OddsComparison\Support\OptionKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every class in src/ must be resolvable via the "OddsComparison\" => "src/" mapping.
 */
final class AutoloadTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function symbols(): array
    {
        return [
            'Plugin'            => [Plugin::class],
            'HookableInterface' => [HookableInterface::class],
            'Installer'         => [Installer::class],
            'Uninstaller'       => [Uninstaller::class],
            'CronSchedule'      => [CronSchedule::class],
            'Settings'          => [Settings::class],
            'OptionKeys'        => [OptionKeys::class],
        ];
    }

    #[DataProvider('symbols')]
    public function testSymbolIsAutoloadable(string $symbol): void
    {
        self::assertTrue(class_exists($symbol) || interface_exists($symbol), $symbol . ' not autoloadable');
    }

    public function testOptionKeysShareThePluginPrefix(): void
    {
        foreach (OptionKeys::all() as $option) {
            self::assertStringStartsWith('odds_comparison_', $option);
        }

        self::assertSame(OptionKeys::all(), array_values(array_unique(OptionKeys::all())));
    }

    public function testSettingsDefaultsAreConsistentWithCronIntervals(): void
    {
        self::assertContains(Settings::DEFAULTS['refresh_interval'], CronSchedule::INTERVALS);
        self::assertGreaterThanOrEqual(2 * Settings::DEFAULTS['refresh_interval'], Settings::DEFAULTS['stale_after']);
    }
}
