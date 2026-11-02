<?php

/**
 * Guards the recorded fixtures: real, unmodified captures that match the registry config.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit;

use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixtureManifestTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /**
     * Every source directory that has a manifest.
     *
     * @return array<string, array{string}>
     */
    public static function manifests(): array
    {
        $manifests = [];

        foreach (glob(self::ROOT . 'fixtures/*/manifest.json') ?: [] as $file) {
            $manifests[basename(dirname($file))] = [$file];
        }

        return $manifests;
    }

    #[DataProvider('manifests')]
    public function testFilesMatchTheirChecksums(string $manifestFile): void
    {
        $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        $dir      = dirname($manifestFile);

        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $manifest['capturedAt']);
        self::assertNotEmpty($manifest['files']);

        foreach ($manifest['files'] as $key => $entry) {
            $path = $dir . '/' . $entry['file'];

            self::assertFileExists($path, $key);
            self::assertSame(200, $entry['status'], $key);
            self::assertStringStartsWith($manifest['baseUrl'] . '/', $entry['url'], $key);
            self::assertStringNotContainsString('apiKey=', str_replace('apiKey=REDACTED', '', $entry['url']), $key);
            self::assertSame($entry['sha256'], hash_file('sha256', $path), $key . ' was modified after capture');
        }
    }

    public function testBettingOddsFixturesContainTheConfiguredCodes(): void
    {
        $dir = self::ROOT . 'fixtures/bettingodds/';

        if (!is_file($dir . 'manifest.json')) {
            self::markTestSkipped('No recorded BettingOdds pages in fixtures/.');
        }

        $match    = (string) file_get_contents($dir . 'match.txt');
        $config   = self::ROOT . 'config/';
        $missing  = [];

        foreach ((new BookmakerRegistry($config . 'bookmakers.php'))->all() as $bookmaker) {
            if (!str_contains($match, 'data-bookmaker="' . $bookmaker->sourceCode('bettingodds') . '"')) {
                $missing[] = $bookmaker->slug;
            }
        }

        self::assertSame([], $missing, 'default bookmakers absent from the recorded match page');

        $markets = new MarketRegistry($config . 'markets.php');
        $league  = (new CompetitionRegistry($config . 'competitions.php'))->get('premier-league');

        self::assertNotNull($league);
        self::assertStringContainsString('data-marketname="Match Result"', $match);

        foreach ($league->outrightMarketKeys('bettingodds') as $marketKey) {
            $page = (string) file_get_contents($dir . 'premier-league-' . $marketKey . '.txt');
            $name = (string) $markets->get($marketKey)?->sourceName('bettingodds');

            self::assertStringContainsString('data-marketname="' . $name . '"', $page, $marketKey);
        }
    }
}
