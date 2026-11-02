<?php

/**
 * Wall-clock deadline of a refresh run.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Support;

use Closure;

defined('ABSPATH') || exit;

/**
 * Shared between the refresh scheduler, which starts and clears it, and the HTTP client, which
 * shortens timeouts and skips retries so a run never outlasts its time budget.
 * Outside a run there is no deadline and requests use their normal timeouts.
 */
final class Deadline
{
    private ?float $startedAt = null;

    private ?float $endsAt = null;

    private readonly Closure $clock;

    /**
     * @param Closure|null $clock fn(): float, current time in seconds (tests).
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Start a deadline the given number of seconds from now.
     *
     * @param float $seconds Budget in seconds.
     * @return void
     */
    public function start(float $seconds): void
    {
        $this->startedAt = ($this->clock)();
        $this->endsAt    = $this->startedAt + $seconds;
    }

    /**
     * Remove the deadline.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->startedAt = null;
        $this->endsAt    = null;
    }

    /**
     * Seconds since the deadline was started, or 0 when none is set.
     *
     * @return float
     */
    public function elapsed(): float
    {
        return $this->startedAt === null ? 0.0 : ($this->clock)() - $this->startedAt;
    }

    /**
     * Seconds left, or null when no deadline is set.
     *
     * @return float|null
     */
    public function remaining(): ?float
    {
        return $this->endsAt === null ? null : $this->endsAt - ($this->clock)();
    }

    /**
     * Whether work of the given length still fits (always true without a deadline).
     *
     * @param float $seconds Expected duration.
     * @return bool
     */
    public function allows(float $seconds): bool
    {
        $remaining = $this->remaining();

        return $remaining === null || $remaining >= $seconds;
    }
}
