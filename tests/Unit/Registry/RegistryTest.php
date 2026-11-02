<?php

/**
 * Tests for the config + filter registries.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Registry;

use OddsComparison\Domain\Bookmaker;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../config/';

    protected function setUp(): void
    {
        remove_all_filters(BookmakerRegistry::FILTER);
        $GLOBALS['odds_comparison_test_doing_it_wrong'] = [];
    }

    protected function tearDown(): void
    {
        remove_all_filters(BookmakerRegistry::FILTER);
    }

    public function testDefaultConfigsLoadCompletely(): void
    {
        $bookmakers   = new BookmakerRegistry(self::CONFIG . 'bookmakers.php');
        $markets      = new MarketRegistry(self::CONFIG . 'markets.php');
        $competitions = new CompetitionRegistry(self::CONFIG . 'competitions.php');

        self::assertCount(10, $bookmakers->all());
        self::assertSame(
            ['match-result', 'outright-winner', 'top-goalscorer', 'relegation', 'top-four-finish'],
            $markets->keys()
        );
        self::assertCount(7, $competitions->all());
        self::assertSame([], $GLOBALS['odds_comparison_test_doing_it_wrong'], 'defaults are all valid');

        foreach ($bookmakers->all() as $bookmaker) {
            self::assertNotNull($bookmaker->sourceCode('bettingodds'), $bookmaker->slug . ' lacks a source code');
        }

        foreach ($markets->all() as $market) {
            self::assertNotNull($market->sourceName('bettingodds'), $market->key . ' lacks a source name');
        }

        // Every outright path must point at a registered outright market.
        foreach ($competitions->all() as $competition) {
            foreach ($competition->outrightMarketKeys('bettingodds') as $marketKey) {
                self::assertTrue($markets->get($marketKey)?->isOutright(), $competition->slug . ': ' . $marketKey);
            }
        }
    }

    public function testBookmakerAddedViaFilterIsResolved(): void
    {
        add_filter(BookmakerRegistry::FILTER, static function (array $bookmakers): array {
            $bookmakers[] = [
                'slug'        => '888sport',
                'name'        => '888sport',
                'sourceCodes' => ['bettingodds' => 'talksportbet'],
            ];
            $bookmakers[] = new Bookmaker('spreadex', 'Spreadex');

            return $bookmakers;
        });

        $registry = new BookmakerRegistry(self::CONFIG . 'bookmakers.php');

        self::assertCount(12, $registry->all());
        self::assertSame('talksportbet', $registry->get('888sport')?->sourceCode('bettingodds'));
        self::assertTrue($registry->has('spreadex'));
        self::assertSame(
            ['888sport', 'william-hill'],
            array_keys($registry->only(['888sport', 'unknown', 'william-hill']))
        );
    }

    public function testFilterCanOverrideAndInvalidEntriesAreSkipped(): void
    {
        add_filter(BookmakerRegistry::FILTER, static function (array $bookmakers): array {
            $bookmakers[] = ['slug' => 'william-hill', 'name' => 'William Hill (override)'];
            $bookmakers[] = ['slug' => 'Not A Slug', 'name' => 'Broken'];
            $bookmakers[] = 'garbage';

            return $bookmakers;
        });

        $registry = new BookmakerRegistry(self::CONFIG . 'bookmakers.php');

        self::assertCount(10, $registry->all());
        self::assertSame('William Hill (override)', $registry->get('william-hill')?->name);
        self::assertSame('william-hill', $registry->keys()[0], 'override keeps the original position');
        self::assertCount(2, $GLOBALS['odds_comparison_test_doing_it_wrong']);
    }

    public function testNonArrayFilterResultFallsBackToDefaults(): void
    {
        add_filter(BookmakerRegistry::FILTER, static fn (): string => 'oops');

        $registry = new BookmakerRegistry(self::CONFIG . 'bookmakers.php');

        self::assertCount(10, $registry->all());
        self::assertCount(1, $GLOBALS['odds_comparison_test_doing_it_wrong']);
    }

    public function testMissingConfigFileYieldsEmptyRegistry(): void
    {
        $registry = new MarketRegistry(self::CONFIG . 'does-not-exist.php');

        self::assertSame([], $registry->all());
        self::assertNull($registry->get('match-result'));
    }
}
