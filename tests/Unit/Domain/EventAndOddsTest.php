<?php

/**
 * Tests for Event, Price and MarketOdds.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Domain\Price;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventAndOddsTest extends TestCase
{
    private const MATCH_ID = 'bettingodds:premier-league/chelsea-man-utd';

    private function match(string $id = self::MATCH_ID, string $path = '/football/chelsea-man-utd'): Event
    {
        return new Event(
            $id,
            'bettingodds',
            'premier-league',
            Event::KIND_MATCH,
            'Chelsea vs Manchester United',
            'Chelsea',
            'Manchester United',
            new DateTimeImmutable('2026-10-31 12:30:00', new DateTimeZone('Europe/London')),
            $path
        );
    }

    public function testMatchNormalisesToUtcAndRoundTrips(): void
    {
        $event = $this->match();

        self::assertSame('UTC', $event->startsAt?->getTimezone()->getName());
        self::assertSame('2026-10-31 12:30', $event->startsAt?->format('Y-m-d H:i'), 'GMT applies after 25 Oct');
        self::assertFalse($event->isOutright());
        self::assertEquals($event, Event::fromArray($event->toArray()));
        self::assertEquals(
            $event,
            Event::match(
                'bettingodds',
                'premier-league/chelsea-man-utd',
                'premier-league',
                'Chelsea vs Manchester United',
                'Chelsea',
                'Manchester United',
                new DateTimeImmutable('2026-10-31 12:30:00', new DateTimeZone('UTC')),
                '/football/chelsea-man-utd'
            )
        );
    }

    public function testOutrightsEvent(): void
    {
        $event = Event::outrights(
            'bettingodds',
            'premier-league',
            'Premier League — Outrights',
            '/football/premier-league'
        );

        self::assertSame('bettingodds:premier-league/outrights', $event->id);
        self::assertTrue($event->isOutright());
        self::assertNull($event->startsAt);
        self::assertFalse($event->hasStarted(new DateTimeImmutable('2099-01-01')), 'outrights never start');
        self::assertEquals($event, Event::fromArray($event->toArray()));
    }

    public function testMatchHasStarted(): void
    {
        $event = $this->match();

        self::assertFalse($event->hasStarted(new DateTimeImmutable('2026-10-31 12:29:59', new DateTimeZone('UTC'))));
        self::assertTrue($event->hasStarted(new DateTimeImmutable('2026-10-31 12:30:00', new DateTimeZone('UTC'))));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidEvents(): array
    {
        $path = '/football/chelsea-man-utd';

        return [
            'id from another source' => ['oddschecker:premier-league/x', $path],
            'id without local part'  => ['bettingodds:', $path],
            'absolute url path'      => [self::MATCH_ID, 'https://evil.test/football'],
            'protocol-relative path' => [self::MATCH_ID, '//evil.test/football'],
            'no leading slash'       => [self::MATCH_ID, 'football/chelsea'],
            'traversal'              => [self::MATCH_ID, '/football/../../wp-admin'],
            'backslash'              => [self::MATCH_ID, '/football\\evil'],
            'whitespace'             => [self::MATCH_ID, '/football/chelsea man-utd'],
            'redirector with colon'  => [self::MATCH_ID, '/out.php?url=https://x'],
        ];
    }

    #[DataProvider('invalidEvents')]
    public function testEventRejectsInvalidState(string $id, string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->match($id, $path);
    }

    /**
     * @return array<string, array{string, string|null, string|null, DateTimeImmutable|null}>
     */
    public static function inconsistentKinds(): array
    {
        $now = new DateTimeImmutable();

        return [
            'match without teams'      => [Event::KIND_MATCH, null, null, $now],
            'match without start'      => [Event::KIND_MATCH, 'A', 'B', null],
            'match with blank team'    => [Event::KIND_MATCH, ' ', 'B', $now],
            'outright with teams'      => [Event::KIND_OUTRIGHT, 'A', 'B', null],
            'outright with start time' => [Event::KIND_OUTRIGHT, null, null, $now],
            'unknown kind'             => ['league', null, null, null],
        ];
    }

    #[DataProvider('inconsistentKinds')]
    public function testEventKindConsistency(
        string $kind,
        ?string $home,
        ?string $away,
        ?DateTimeImmutable $startsAt
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new Event(self::MATCH_ID, 'bettingodds', 'premier-league', $kind, 'X', $home, $away, $startsAt, '/football/x');
    }

    public function testPriceRoundTrips(): void
    {
        $price = new Price(3.5);

        self::assertEquals($price, Price::fromArray($price->toArray()));
        self::assertSame(['decimal' => 3.5], $price->toArray());
        self::assertEquals($price, Price::fromArray(['decimal' => 3.5, 'fractional' => '5/2']), 'old cache entries');
    }

    /**
     * @return array<string, array{float}>
     */
    public static function invalidPrices(): array
    {
        return [
            'one'       => [1.0],
            'below one' => [0.5],
            'infinite'  => [INF],
            'nan'       => [NAN],
        ];
    }

    #[DataProvider('invalidPrices')]
    public function testPriceRejectsInvalidState(float $decimal): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Price($decimal);
    }

    public function testMarketOddsAccessorsAndRoundTrip(): void
    {
        $odds = new MarketOdds(
            self::MATCH_ID,
            'match-result',
            'bettingodds',
            new DateTimeImmutable('@1793550600'),
            [
                'home' => [
                    'label'  => 'Chelsea',
                    'prices' => ['bet365' => new Price(1.8), 'betfred' => new Price(1.83)],
                ],
                'draw' => ['label' => 'Draw', 'prices' => ['william-hill' => new Price(3.75)]],
            ]
        );

        self::assertSame(['home', 'draw'], $odds->selectionKeys());
        self::assertSame(['bet365', 'betfred', 'william-hill'], $odds->bookmakerSlugs());
        self::assertSame(1.8, $odds->price('home', 'bet365')?->decimal);
        self::assertNull($odds->price('away', 'bet365'));
        self::assertEquals($odds, MarketOdds::fromArray($odds->toArray()));

        $trimmed = $odds->withSelections(['draw' => $odds->selections['draw']]);
        self::assertSame(['draw'], $trimmed->selectionKeys());
        self::assertSame(['home', 'draw'], $odds->selectionKeys(), 'original stays unchanged');
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidSelections(): array
    {
        return [
            'list instead of map' => [[['label' => 'Home', 'prices' => []]]],
            'missing label'       => [['home' => ['prices' => []]]],
            'blank label'         => [['home' => ['label' => ' ', 'prices' => []]]],
            'raw float price'     => [['home' => ['label' => 'Home', 'prices' => ['bet365' => 2.0]]]],
            'bad bookmaker slug'  => [['home' => ['label' => 'Home', 'prices' => ['Bet 365' => new Price(2.0)]]]],
        ];
    }

    /**
     * @param array<mixed> $selections Selections.
     */
    #[DataProvider('invalidSelections')]
    public function testMarketOddsRejectsInvalidSelections(array $selections): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MarketOdds(self::MATCH_ID, 'match-result', 'bettingodds', new DateTimeImmutable(), $selections);
    }

    public function testMarketOddsFromCorruptedStorageThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MarketOdds::fromArray([
            'eventId'   => self::MATCH_ID,
            'marketKey' => 'match-result',
            'sourceId'  => 'bettingodds',
        ]);
    }
}
