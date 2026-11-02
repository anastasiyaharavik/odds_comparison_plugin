<?php

/**
 * BettingOddsSource and TheOddsApiSource end to end on real recorded responses.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Source;

use DateTimeImmutable;
use InvalidArgumentException;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Http\RecordedHttpClient;
use OddsComparison\Odds\OddsConverter;
use OddsComparison\Odds\OddsValidator;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Source\BettingOdds\BettingOddsSource;
use OddsComparison\Source\TheOddsApi\TheOddsApiSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SourcesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    /** Chelsea v Man Utd, as identified by BettingOdds (data-id). */
    private const CHELSEA = 'bettingodds:premier-league/4othg6zhiyox0rivijbld3swk';

    private BookmakerRegistry $bookmakers;

    private CompetitionRegistry $competitions;

    private MarketRegistry $markets;

    protected function setUp(): void
    {
        $GLOBALS['odds_comparison_test_transients'] = [];
        $this->bookmakers   = new BookmakerRegistry(self::ROOT . 'config/bookmakers.php');
        $this->competitions = new CompetitionRegistry(self::ROOT . 'config/competitions.php');
        $this->markets      = new MarketRegistry(self::ROOT . 'config/markets.php');
    }

    private function requireFixtures(string $source): void
    {
        if (!is_file(self::ROOT . 'fixtures/' . $source . '/manifest.json')) {
            self::markTestSkipped("No recorded {$source} responses.");
        }
    }

    private function bettingOdds(): BettingOddsSource
    {
        $this->requireFixtures('bettingodds');

        return new BettingOddsSource(
            new RecordedHttpClient(self::ROOT . 'fixtures'),
            $this->bookmakers,
            $this->competitions,
            new OddsValidator(),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-30T12:28:06Z')
        );
    }

    private function theOddsApi(string $key = 'recorded'): TheOddsApiSource
    {
        $this->requireFixtures('theoddsapi');

        return new TheOddsApiSource(
            new RecordedHttpClient(self::ROOT . 'fixtures'),
            $this->bookmakers,
            $this->competitions,
            new OddsValidator(),
            $key,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-30T12:35:53Z')
        );
    }

    /**
     * @param list<Event> $events Events.
     */
    private static function find(array $events, string $id): Event
    {
        foreach ($events as $event) {
            if ($event->id === $id) {
                return $event;
            }
        }

        self::fail("Event {$id} not discovered.");
    }

    public function testBettingOddsDiscoversMatchesAndOutrights(): void
    {
        $source = $this->bettingOdds();
        $events = $source->discoverEvents($this->competitions->get('premier-league'));
        $match  = self::find($events, self::CHELSEA);

        self::assertSame('Chelsea vs Manchester United', $match->name);
        self::assertSame('/football/chelsea-man-utd', $match->path);
        // 12:30 UK time on 31 Oct 2026 is GMT (clocks went back on 25 Oct), so 12:30 UTC.
        self::assertEquals(new DateTimeImmutable('2026-10-31T12:30:00Z'), $match->startsAt);

        $outrights = self::find($events, 'bettingodds:premier-league/outrights');
        self::assertTrue($outrights->isOutright());

        foreach ($events as $event) {
            if (!$event->isOutright()) {
                self::assertGreaterThan(new DateTimeImmutable('2026-10-29'), $event->startsAt, $event->name);
                self::assertLessThan(new DateTimeImmutable('2026-11-30'), $event->startsAt, $event->name);
            }
        }
    }

    public function testBettingOddsMatchResultFromTheListing(): void
    {
        $source = $this->bettingOdds();
        $event  = self::find($source->discoverEvents($this->competitions->get('premier-league')), self::CHELSEA);
        $odds   = $source->fetchMarket($event, $this->markets->get('match-result'));

        self::assertSame(['home', 'draw', 'away'], $odds->selectionKeys());
        self::assertSame('Chelsea', $odds->selections['home']['label']);
        self::assertCount(10, $odds->bookmakerSlugs(), 'all 10 default bookmakers priced');
        self::assertEqualsWithDelta(1.83, $odds->price('home', 'william-hill')?->decimal, 0.001);
        self::assertSame('bettingodds', $odds->sourceId);
        self::assertArrayHasKey('bet365', $source->unmappedCodes(), 'bookmakers outside the registry are counted');
    }

    public function testBettingOddsOutrightMarket(): void
    {
        $source    = $this->bettingOdds();
        $events    = $source->discoverEvents($this->competitions->get('premier-league'));
        $outrights = self::find($events, 'bettingodds:premier-league/outrights');
        $odds      = $source->fetchMarket($outrights, $this->markets->get('outright-winner'));

        self::assertContains('arsenal', $odds->selectionKeys());
        self::assertGreaterThanOrEqual(10, count($odds->selectionKeys()));
        self::assertGreaterThanOrEqual(2, count($odds->bookmakerSlugs()));

        $scorers = $source->fetchMarket($outrights, $this->markets->get('top-goalscorer'));
        self::assertContains('erling-braut-haaland', $scorers->selectionKeys());
    }

    public function testBettingOddsRefusesMismatchedMarkets(): void
    {
        $source    = $this->bettingOdds();
        $events    = $source->discoverEvents($this->competitions->get('premier-league'));
        $outrights = self::find($events, 'bettingodds:premier-league/outrights');

        $this->expectException(RuntimeException::class);
        $source->fetchMarket($outrights, $this->markets->get('match-result'));
    }

    public function testEventsOfAnotherSourceAreRejected(): void
    {
        $event = Event::outrights('theoddsapi', 'premier-league', 'X', '/v4/sports/soccer_epl/odds');

        $this->expectException(InvalidArgumentException::class);
        $this->bettingOdds()->fetchMarket($event, $this->markets->get('outright-winner'));
    }

    public function testTheOddsApiDiscoversAndPricesMatches(): void
    {
        $source = $this->theOddsApi();
        $events = $source->discoverEvents($this->competitions->get('premier-league'));

        self::assertCount(21, $events);

        $chelsea = array_values(array_filter($events, static fn (Event $e): bool => $e->home === 'Chelsea'))[0];
        self::assertSame('Manchester United', $chelsea->away);
        self::assertEquals(new DateTimeImmutable('2026-10-31T12:30:00Z'), $chelsea->startsAt);
        self::assertSame('UTC', $chelsea->startsAt?->getTimezone()->getName());

        $odds = $source->fetchMarket($chelsea, $this->markets->get('match-result'));

        self::assertSame(['home', 'away', 'draw'], $odds->selectionKeys(), 'API outcome order');
        self::assertCount(10, $odds->bookmakerSlugs(), 'all 10 default bookmakers priced');
        self::assertSame(['decimal'], array_keys($odds->price('home', 'william-hill')?->toArray() ?? []));
        self::assertArrayHasKey('ladbrokes_uk', $source->unmappedCodes());
    }

    public function testTheOddsApiOffersNoOutrights(): void
    {
        $this->expectException(RuntimeException::class);
        $this->theOddsApi()->fetchMarket(
            Event::outrights('theoddsapi', 'premier-league', 'X', '/v4/sports/soccer_epl/odds'),
            $this->markets->get('outright-winner')
        );
    }

    public function testTheOddsApiWithoutKeyFailsBeforeAnyRequest(): void
    {
        $this->expectExceptionMessage('not configured');
        $this->theOddsApi('')->discoverEvents($this->competitions->get('premier-league'));
    }

    public function testTheOddsApiQuotaGuard(): void
    {
        set_transient(TheOddsApiSource::QUOTA_TRANSIENT, ['remaining' => 3, 'used' => 497, 'checkedAt' => 1], 60);

        $this->expectExceptionMessage('nearly exhausted');
        $this->theOddsApi()->discoverEvents($this->competitions->get('premier-league'));
    }

    public function testCompetitionsWithoutSourcePathYieldNothing(): void
    {
        $apiOnly = new Competition('mls', 'MLS', 'football', ['theoddsapi' => '/v4/sports/soccer_usa_mls/odds']);

        self::assertSame([], $this->bettingOdds()->discoverEvents($apiOnly));
    }
}
