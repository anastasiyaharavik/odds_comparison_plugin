<?php

/**
 * DemoSource: recorded odds with kick-offs moved into the coming days.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Source;

use DateTimeImmutable;
use DateTimeZone;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Http\HttpException;
use OddsComparison\Http\RecordedHttpClient;
use OddsComparison\Odds\OddsValidator;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Source\BettingOdds\BettingOddsSource;
use OddsComparison\Source\DemoSource;
use OddsComparison\Source\TheOddsApi\TheOddsApiSource;
use PHPUnit\Framework\TestCase;

final class DemoSourceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    /** Chelsea v Man Utd, as identified by BettingOdds (data-id). */
    private const CHELSEA = 'bettingodds:premier-league/4othg6zhiyox0rivijbld3swk';

    private BookmakerRegistry $bookmakers;

    private CompetitionRegistry $competitions;

    private MarketRegistry $markets;

    private RecordedHttpClient $http;

    protected function setUp(): void
    {
        if (!is_file(self::ROOT . 'fixtures/bettingodds/manifest.json')) {
            self::markTestSkipped('No recorded BettingOdds responses.');
        }

        $GLOBALS['odds_comparison_test_transients'] = [];
        $this->bookmakers   = new BookmakerRegistry(self::ROOT . 'config/bookmakers.php');
        $this->competitions = new CompetitionRegistry(self::ROOT . 'config/competitions.php');
        $this->markets      = new MarketRegistry(self::ROOT . 'config/markets.php');
        $this->http         = new RecordedHttpClient(self::ROOT . 'fixtures');
    }

    private function capturedAt(): DateTimeImmutable
    {
        $capturedAt = $this->http->firstCapturedAt();
        self::assertNotNull($capturedAt);

        return $capturedAt;
    }

    private function demo(string $now): DemoSource
    {
        $capturedAt = $this->capturedAt();
        $inner      = new BettingOddsSource(
            $this->http,
            $this->bookmakers,
            $this->competitions,
            new OddsValidator(),
            static fn (): DateTimeImmutable => $capturedAt
        );

        return new DemoSource($inner, $capturedAt, static fn (): DateTimeImmutable => new DateTimeImmutable($now));
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

    public function testDayShiftPutsTheCaptureTimeAtOrAfterNow(): void
    {
        self::assertSame(0, $this->demo('2026-10-30T10:00:00Z')->dayShift(), 'before the capture: no shift');
        self::assertSame(1, $this->demo('2026-10-30T13:00:00Z')->dayShift());
        self::assertSame(3, $this->demo('2026-11-02T09:00:00Z')->dayShift());
        self::assertSame(4, $this->demo('2026-11-02T13:00:00Z')->dayShift());
        self::assertSame(366, $this->demo('2027-10-31T00:00:00Z')->dayShift());
    }

    public function testKickOffsMoveIntoTheComingDaysKeepingUkTime(): void
    {
        $now    = '2026-12-15T18:00:00Z';
        $demo   = $this->demo($now);
        $events = $demo->discoverEvents($this->competitions->get('premier-league'));
        $match  = self::find($events, self::CHELSEA);

        self::assertSame('Chelsea vs Manchester United', $match->name);
        // Recorded for Saturday 31 October 12:30 UK time; 47 days later it is 17 December, 12:30 UK.
        self::assertSame(47, $demo->dayShift());
        self::assertEquals(new DateTimeImmutable('2026-12-17T12:30:00Z'), $match->startsAt);

        foreach ($events as $event) {
            if ($event->isOutright()) {
                self::assertNull($event->startsAt);
                continue;
            }

            self::assertFalse($event->hasStarted(new DateTimeImmutable($now)), $event->name . ' is upcoming');
            self::assertLessThan(new DateTimeImmutable('2027-01-31'), $event->startsAt, $event->name);
        }

        self::find($events, 'bettingodds:premier-league/outrights');
    }

    public function testWallClockTimeSurvivesSummerTime(): void
    {
        // Recorded in GMT (12:30 UTC = 12:30 UK); in summer 12:30 UK is 11:30 UTC.
        $match = self::find(
            $this->demo('2027-06-01T08:00:00Z')->discoverEvents($this->competitions->get('premier-league')),
            self::CHELSEA
        );

        self::assertNotNull($match->startsAt);
        self::assertSame(
            '12:30',
            $match->startsAt->setTimezone(new DateTimeZone('Europe/London'))->format('H:i')
        );
    }

    public function testPricesAreTheRecordedOnesStampedWithTheCurrentTime(): void
    {
        $now    = '2026-11-20T09:00:00Z';
        $demo   = $this->demo($now);
        $events = $demo->discoverEvents($this->competitions->get('premier-league'));
        $market = $this->markets->get('match-result');
        self::assertNotNull($market);

        $odds = $demo->fetchMarket(self::find($events, self::CHELSEA), $market);

        self::assertEquals(new DateTimeImmutable($now), $odds->fetchedAt);
        self::assertSame(self::CHELSEA, $odds->eventId);
        self::assertSame('bettingodds', $odds->sourceId);

        // Same prices as the plain recorded source.
        $capturedAt = $this->capturedAt();
        $plain      = (new BettingOddsSource(
            $this->http,
            $this->bookmakers,
            $this->competitions,
            new OddsValidator(),
            static fn (): DateTimeImmutable => $capturedAt
        ))->fetchMarket(self::find($events, self::CHELSEA), $market);

        self::assertEquals($plain->selections, $odds->selections);
    }

    public function testCompetitionWithoutRecordedDataHasNoEventsAndAWarning(): void
    {
        $demo   = $this->demo('2026-11-20T09:00:00Z');
        $events = $demo->discoverEvents($this->competitions->get('la-liga'));

        self::assertSame([], $events);
        self::assertSame(['Demo data has no recorded odds for La Liga.'], $demo->warnings());

        $demo->clearDiagnostics();
        self::assertSame([], $demo->warnings());
    }

    public function testTheOddsApiDemoNeedsNoRealKey(): void
    {
        if (!is_file(self::ROOT . 'fixtures/theoddsapi/manifest.json')) {
            self::markTestSkipped('No recorded The Odds API responses.');
        }

        $capturedAt = $this->capturedAt();
        $demo       = new DemoSource(
            new TheOddsApiSource(
                $this->http,
                $this->bookmakers,
                $this->competitions,
                new OddsValidator(),
                'demo',
                static fn (): DateTimeImmutable => $capturedAt
            ),
            $capturedAt,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-20T09:00:00Z')
        );

        $events = $demo->discoverEvents($this->competitions->get('premier-league'));

        self::assertNotSame([], $events);
        self::assertSame('theoddsapi', $demo->id());

        foreach ($events as $event) {
            self::assertGreaterThan(new DateTimeImmutable('2026-11-20T09:00:00Z'), $event->startsAt);
        }
    }

    public function testOtherFailuresAreNotHidden(): void
    {
        $failing = new class implements SourceInterface {
            public function id(): string
            {
                return 'failing';
            }

            public function baseUrl(): string
            {
                return 'https://failing.example';
            }

            public function discoverEvents(Competition $competition): array
            {
                throw new HttpException('Forbidden', 403);
            }

            public function fetchMarket(Event $event, Market $market): MarketOdds
            {
                throw new HttpException('Forbidden', 403);
            }
        };

        $demo = new DemoSource(
            $failing,
            new DateTimeImmutable('2026-10-30T12:00:00Z'),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-20T09:00:00Z')
        );

        $this->expectException(HttpException::class);
        $demo->discoverEvents($this->competitions->get('premier-league'));
    }
}
