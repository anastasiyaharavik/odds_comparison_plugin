<?php

/**
 * BettingOddsParser against real recorded pages (fixtures/bettingodds, captured 2026-10-30).
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Source;

use OddsComparison\Source\BettingOdds\BettingOddsParser;
use PHPUnit\Framework\TestCase;

final class BettingOddsParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../fixtures/bettingodds/';

    private const MATCH = 'Chelsea vs Manchester United';

    private BettingOddsParser $parser;

    protected function setUp(): void
    {
        if (!is_file(self::FIXTURES . 'manifest.json')) {
            self::markTestSkipped('No recorded BettingOdds pages in fixtures/.');
        }

        $this->parser = new BettingOddsParser();
    }

    private static function page(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    public function testListingYieldsPremierLeagueMatchesOnlyWithFullNames(): void
    {
        $events = $this->parser->parseEvents(self::page('premier-league-listing.html'));
        $byName = array_column($events, null, 'name');

        self::assertGreaterThanOrEqual(10, count($events));
        self::assertSame([], $this->parser->warnings());
        self::assertSame(
            [
                'id'   => '4othg6zhiyox0rivijbld3swk',
                'path' => '/football/chelsea-man-utd',
                'name' => self::MATCH,
                'home' => 'Chelsea',
                'away' => 'Manchester United',
                'day'  => 'Saturday 31 October',
                'time' => '12:30',
            ],
            $byName[self::MATCH] ?? null
        );

        // Fixtures further out are listed (with prices) before the source gives them a page.
        self::assertSame('', $byName['Leeds United vs Tottenham Hotspur']['path'] ?? null);

        foreach ($events as $event) {
            // Sidebar widget items (Championship fixtures with dated paths) must not leak in.
            self::assertStringNotContainsString('/championship/', $event['path']);
            self::assertMatchesRegularExpression('#^(/football/[a-z0-9-]+)?$#', $event['path']);
            self::assertMatchesRegularExpression('/^[a-z0-9]+$/', $event['id']);
            self::assertMatchesRegularExpression('/^\d{1,2}:\d{2}$/', $event['time']);
        }

        self::assertSame(count($events), count(array_unique(array_column($events, 'id'))));
    }

    public function testMatchPagePricesAreScopedToTheEventAndMarket(): void
    {
        $prices = $this->parser->parsePrices(self::page('match.html'), BettingOddsParser::MATCH_RESULT, self::MATCH);

        self::assertSame([], $this->parser->warnings());
        self::assertSame(
            ['Chelsea', 'Draw', 'Manchester United'],
            array_values(array_unique(array_column($prices, 'participant')))
        );
        self::assertGreaterThanOrEqual(10, count(array_unique(array_column($prices, 'bookmaker'))));

        $pairs = array_map(static fn (array $p): string => $p['bookmaker'] . '|' . $p['participant'], $prices);
        self::assertSame($pairs, array_values(array_unique($pairs)), 'no duplicate bookmaker/participant pairs');

        $williamHillHome = array_values(array_filter(
            $prices,
            static fn (array $p): bool => $p['bookmaker'] === 'williamhill' && $p['participant'] === 'Chelsea'
        ));
        self::assertSame(
            [['bookmaker' => 'williamhill', 'participant' => 'Chelsea', 'decimal' => '1.83']],
            $williamHillHome
        );
    }

    public function testListingCarriesTheSameMatchResultGrid(): void
    {
        $fromListing = $this->parser->parsePrices(
            self::page('premier-league-listing.html'),
            BettingOddsParser::MATCH_RESULT,
            self::MATCH
        );

        self::assertGreaterThanOrEqual(30, count($fromListing), 'about 20 bookmakers x 3 selections');
        self::assertContains('betfair', array_column($fromListing, 'bookmaker'));
    }

    public function testUnavailablePricesArePassedThroughRaw(): void
    {
        $prices = $this->parser->parsePrices(
            self::page('premier-league-listing.html'),
            BettingOddsParser::MATCH_RESULT,
            self::MATCH
        );
        $dashes = array_filter($prices, static fn (array $p): bool => $p['decimal'] === '-');

        self::assertNotEmpty($dashes, 'the listing has "-" (unavailable) prices for the validator to drop');
    }

    public function testOutrightPage(): void
    {
        $prices = $this->parser->parsePrices(self::page('premier-league-outright-winner.html'), 'Outright Winner');

        self::assertSame([], $this->parser->warnings());
        self::assertGreaterThanOrEqual(20, count(array_unique(array_column($prices, 'participant'))));
        self::assertGreaterThanOrEqual(5, count(array_unique(array_column($prices, 'bookmaker'))));
        self::assertNotContains('Draw', array_column($prices, 'participant'), 'widget match prices excluded');
    }

    public function testRelegationPageKeepsOnlyTheRequestedMarket(): void
    {
        $page      = self::page('premier-league-relegation.html');
        $relegated = $this->parser->parsePrices($page, 'To be Relegated');
        $stayUp    = $this->parser->parsePrices($page, 'To Stay Up');

        self::assertNotEmpty($relegated);
        self::assertNotEmpty($stayUp);
        self::assertNotSame($relegated, $stayUp);
    }

    public function testUtf8NamesSurvive(): void
    {
        $page  = self::page('premier-league-top-goalscorer.html');
        $names = array_column($this->parser->parsePrices($page, 'Top Goalscorer'), 'participant');

        self::assertContains('Erling Braut Haaland', $names);
        foreach ($names as $name) {
            self::assertTrue(mb_check_encoding($name, 'UTF-8'), $name);
        }
    }

    public function testMarkupChangedPageReturnsEmptyWithWarning(): void
    {
        // A real page of the source without the main match list or the requested market.
        $page = self::page('premier-league-stats-no-odds.html');

        self::assertSame([], $this->parser->parseEvents($page));
        self::assertStringContainsString('markup changed', $this->parser->warnings()[0] ?? '');

        self::assertSame([], $this->parser->parsePrices($page, BettingOddsParser::MATCH_RESULT, self::MATCH));
        self::assertNotEmpty($this->parser->warnings());
    }

    public function testNonHtmlInputDoesNotFail(): void
    {
        self::assertSame([], $this->parser->parseEvents(''));
        self::assertSame([], $this->parser->parseEvents(self::page('robots.txt')));
        self::assertSame([], $this->parser->parsePrices('<<<not html', 'Outright Winner'));
    }
}
