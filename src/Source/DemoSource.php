<?php

/**
 * Demo-data decorator for odds sources.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Source;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OddsComparison\Contracts\DiagnosticsInterface;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Http\HttpException;

defined('ABSPATH') || exit;

/**
 * Demo mode (Decorator): wraps a source that reads recorded responses and moves every kick-off
 * into the coming days, so the demo never goes out of date.
 *
 * - Prices, bookmakers, teams and markets are the real recorded ones and are never changed.
 * - Kick-offs move forward by whole days: the smallest number of days that puts the capture
 *   time at or after "now". Every match listed after the capture is therefore still upcoming,
 *   and the local kick-off time (e.g. 15:00 UK time) stays the same.
 * - Odds are stamped with the real current time, so freshness and caching work as in Live mode.
 * - A competition without recorded data has no demo events (a warning, not an error).
 *
 * The id, base URL and event ids are the wrapped source's, so registry paths, markets and
 * bookmaker codes apply unchanged.
 */
final class DemoSource implements SourceInterface, DiagnosticsInterface
{
    /** Time zone whose wall-clock kick-off times are kept when dates move. */
    private const TIMEZONE = 'Europe/London';

    /**
     * Demo-only warnings (competitions without recorded data).
     *
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * @param SourceInterface   $inner      Source reading recorded responses.
     * @param DateTimeImmutable $capturedAt When the wrapped source's responses were recorded.
     * @param Closure           $clock      fn(): DateTimeImmutable; the real current time.
     */
    public function __construct(
        private readonly SourceInterface $inner,
        private readonly DateTimeImmutable $capturedAt,
        private readonly Closure $clock
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return $this->inner->id();
    }

    /**
     * {@inheritDoc}
     */
    public function baseUrl(): string
    {
        return $this->inner->baseUrl();
    }

    /**
     * Whole days the recorded dates move forward right now.
     *
     * @return int
     */
    public function dayShift(): int
    {
        $behind = $this->now()->getTimestamp() - $this->capturedAt->getTimestamp();

        return $behind <= 0 ? 0 : (int) ceil($behind / 86400);
    }

    /**
     * {@inheritDoc}
     */
    public function discoverEvents(Competition $competition): array
    {
        try {
            $events = $this->inner->discoverEvents($competition);
        } catch (HttpException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }

            $this->warnings[] = sprintf('Demo data has no recorded odds for %s.', $competition->name);

            return [];
        }

        $days = $this->dayShift();

        return array_map(static fn (Event $event): Event => self::shift($event, $days), $events);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchMarket(Event $event, Market $market): MarketOdds
    {
        $odds = $this->inner->fetchMarket($event, $market);

        return new MarketOdds($odds->eventId, $odds->marketKey, $odds->sourceId, $this->now(), $odds->selections);
    }

    /**
     * {@inheritDoc}
     */
    public function warnings(): array
    {
        $inner = $this->inner instanceof DiagnosticsInterface ? $this->inner->warnings() : [];

        return [...$this->warnings, ...$inner];
    }

    /**
     * {@inheritDoc}
     */
    public function unmappedCodes(): array
    {
        return $this->inner instanceof DiagnosticsInterface ? $this->inner->unmappedCodes() : [];
    }

    /**
     * {@inheritDoc}
     */
    public function clearDiagnostics(): void
    {
        $this->warnings = [];

        if ($this->inner instanceof DiagnosticsInterface) {
            $this->inner->clearDiagnostics();
        }
    }

    /**
     * Move a match's kick-off forward by whole days, keeping the UK wall-clock time.
     *
     * @param Event $event Event as recorded.
     * @param int   $days  Days to add.
     * @return Event
     */
    private static function shift(Event $event, int $days): Event
    {
        if ($event->startsAt === null || $days === 0) {
            return $event;
        }

        $startsAt = $event->startsAt
            ->setTimezone(new DateTimeZone(self::TIMEZONE))
            ->modify(sprintf('+%d days', $days));

        return new Event(
            $event->id,
            $event->sourceId,
            $event->competition,
            $event->kind,
            $event->name,
            $event->home,
            $event->away,
            $startsAt,
            $event->path
        );
    }

    /**
     * Real current time.
     *
     * @return DateTimeImmutable
     */
    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
