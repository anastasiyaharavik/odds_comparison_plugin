<?php

/**
 * Tests for the registry value objects (Bookmaker, Market, Competition) and OddsFormat.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Domain;

use InvalidArgumentException;
use OddsComparison\Domain\Bookmaker;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\OddsFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function testBookmakerFromArray(): void
    {
        $bookmaker = Bookmaker::fromArray([
            'slug'        => '888sport',
            'name'        => '888sport',
            'sourceCodes' => ['bettingodds' => '888sport'],
            'defaultUrl'  => 'https://www.888sport.com/',
        ]);

        self::assertSame('888sport', $bookmaker->slug);
        self::assertSame('', $bookmaker->logoUrl);
        self::assertSame('888sport', $bookmaker->sourceCode('bettingodds'));
        self::assertNull($bookmaker->sourceCode('other'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidBookmakers(): array
    {
        $valid = ['slug' => 'bet365', 'name' => 'Bet365'];

        return [
            'missing slug'         => [['name' => 'Bet365']],
            'upper-case slug'      => [['slug' => 'Bet365'] + $valid],
            'slug with space'      => [['slug' => 'bet 365'] + $valid],
            'blank name'           => [['name' => '  '] + $valid],
            'javascript url'       => [['defaultUrl' => 'javascript:alert(1)'] + $valid],
            'relative logo'        => [['logoUrl' => '/logo.png'] + $valid],
            'non-string code'      => [['sourceCodes' => ['bettingodds' => 5]] + $valid],
            'empty code'           => [['sourceCodes' => ['bettingodds' => '']] + $valid],
            'list of codes'        => [['sourceCodes' => ['bet365']] + $valid],
            'sourceCodes not list' => [['sourceCodes' => 'bet365'] + $valid],
        ];
    }

    /**
     * @param array<string, mixed> $data Definition.
     */
    #[DataProvider('invalidBookmakers')]
    public function testBookmakerRejectsInvalidState(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        Bookmaker::fromArray($data);
    }

    public function testMarketSelectionTypes(): void
    {
        $match    = new Market(
            'match-result',
            'Match Result',
            ['bettingodds' => 'Match Result'],
            Market::SELECTION_THREE_WAY
        );
        $twoWay   = new Market('draw-no-bet', 'Draw No Bet', [], Market::SELECTION_TWO_WAY);
        $outright = Market::fromArray([
            'key'           => 'outright-winner',
            'label'         => 'Outright Winner',
            'sourceNames'   => ['bettingodds' => 'Outright Winner'],
            'selectionType' => Market::SELECTION_OUTRIGHT,
        ]);

        self::assertSame(3, $match->expectedSelectionCount());
        self::assertSame(2, $twoWay->expectedSelectionCount());
        self::assertNull($outright->expectedSelectionCount());
        self::assertFalse($match->isOutright());
        self::assertTrue($outright->isOutright());
        self::assertSame('Match Result', $match->sourceName('bettingodds'));
        self::assertNull($twoWay->sourceName('bettingodds'));
    }

    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function invalidMarkets(): array
    {
        return [
            'unknown selection type' => [['bettingodds' => 'Match Result'], 'four-way'],
            'blank source name'      => [['bettingodds' => ' '], Market::SELECTION_TWO_WAY],
            'bad source id'          => [['Betting Odds' => 'Match Result'], Market::SELECTION_TWO_WAY],
        ];
    }

    /**
     * @param array<mixed> $names Source names.
     * @param string       $type  Selection type.
     */
    #[DataProvider('invalidMarkets')]
    public function testMarketRejectsInvalidState(array $names, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Market('match-result', 'Match Result', $names, $type);
    }

    public function testCompetitionPaths(): void
    {
        $competition = Competition::fromArray([
            'slug'          => 'premier-league',
            'name'          => 'Premier League',
            'sport'         => 'football',
            'sourcePaths'   => ['bettingodds' => '/football/premier-league'],
            'outrightPaths' => ['bettingodds' => ['outright-winner' => '/football/premier-league/winner']],
        ]);

        self::assertSame('/football/premier-league', $competition->sourcePath('bettingodds'));
        self::assertSame(
            '/football/premier-league/winner',
            $competition->outrightPath('bettingodds', 'outright-winner')
        );
        self::assertNull($competition->outrightPath('bettingodds', 'relegation'));
        self::assertSame(['outright-winner'], $competition->outrightMarketKeys('bettingodds'));
        self::assertSame([], $competition->outrightMarketKeys('other'));
    }

    /**
     * @return array<string, array{array<mixed>, array<mixed>}>
     */
    public static function invalidCompetitionPaths(): array
    {
        $ok = ['bettingodds' => '/football/serie-a'];

        return [
            'protocol-relative listing' => [['bettingodds' => '//evil.test/football'], []],
            'absolute listing'          => [['bettingodds' => 'https://evil.test/'], []],
            'outright full url'         => [$ok, ['bettingodds' => ['outright-winner' => 'https://evil.test/x']]],
            'outright traversal'        => [$ok, ['bettingodds' => ['outright-winner' => '/football/../admin']]],
            'outright bad market key'   => [$ok, ['bettingodds' => ['Outright Winner' => '/football/x']]],
            'outright not a map'        => [$ok, ['bettingodds' => '/football/x']],
        ];
    }

    /**
     * @param array<mixed> $sourcePaths   Listing paths.
     * @param array<mixed> $outrightPaths Outright paths.
     */
    #[DataProvider('invalidCompetitionPaths')]
    public function testCompetitionRejectsUnsafePaths(array $sourcePaths, array $outrightPaths): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Competition('serie-a', 'Serie A', 'football', $sourcePaths, $outrightPaths);
    }

    public function testOddsFormatResolve(): void
    {
        self::assertSame(OddsFormat::Decimal, OddsFormat::resolve('decimal', OddsFormat::Fractional));
        self::assertSame(OddsFormat::Fractional, OddsFormat::resolve(OddsFormat::INHERIT, OddsFormat::Fractional));
        self::assertSame(OddsFormat::American, OddsFormat::resolve('<script>', OddsFormat::American));
        self::assertSame(['fractional', 'decimal', 'american'], OddsFormat::values());
    }
}
