<?php

/**
 * Per-host request throttle.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

use Closure;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * Keeps at least `minInterval` seconds (plus random jitter) between requests to the same host.
 *
 * Within a run the wait is enforced in-process. The last request time per host is also kept in
 * a transient, so consecutive cron runs respect the interval too. A transient is used rather
 * than wp_cache, which does not persist without an object cache.
 */
final class RateLimiter
{
    /** Seconds the last-request transient lives; longer than any sensible interval. */
    private const MEMORY_SECONDS = 3600;

    /**
     * Last request time per host within this process.
     *
     * @var array<string, float>
     */
    private array $lastRequest = [];

    private readonly Closure $clock;

    private readonly Closure $sleep;

    private readonly Closure $jitter;

    /**
     * @param float        $minInterval Minimum seconds between requests to one host.
     * @param float        $maxJitter   Maximum random extra seconds per wait.
     * @param Closure|null $clock       fn(): float, current time in seconds (tests).
     * @param Closure|null $sleep       fn(float $seconds): void (tests).
     * @param Closure|null $jitter      fn(float $max): float, random extra delay (tests).
     */
    public function __construct(
        private readonly float $minInterval,
        private readonly float $maxJitter = 1.0,
        ?Closure $clock = null,
        ?Closure $sleep = null,
        ?Closure $jitter = null
    ) {
        $this->clock  = $clock ?? static fn (): float => microtime(true);
        $this->sleep  = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1000000));
        };
        $this->jitter = $jitter ?? static fn (float $max): float => $max * (random_int(0, 1000) / 1000);
    }

    /**
     * Block until a request to the host is allowed, then record it as made.
     *
     * @param string $host Host name.
     * @return void
     */
    public function wait(string $host): void
    {
        $last = $this->lastRequestAt($host);

        if ($last > 0.0) {
            $remaining = $last + $this->minInterval + ($this->jitter)($this->maxJitter) - ($this->clock)();

            if ($remaining > 0) {
                ($this->sleep)($remaining);
            }
        }

        $now                      = ($this->clock)();
        $this->lastRequest[$host] = $now;
        set_transient(self::transientName($host), (string) $now, self::MEMORY_SECONDS);
    }

    /**
     * Longest time {@see self::wait()} may block for a host right now (interval plus full jitter).
     *
     * @param string $host Host name.
     * @return float Seconds, 0 when a request is allowed immediately.
     */
    public function longestWait(string $host): float
    {
        $last = $this->lastRequestAt($host);

        if ($last <= 0.0) {
            return 0.0;
        }

        return max(0.0, $last + $this->minInterval + $this->maxJitter - ($this->clock)());
    }

    /**
     * Time of the last request to a host, in this process or an earlier run.
     *
     * @param string $host Host name.
     * @return float Seconds, 0 when unknown.
     */
    private function lastRequestAt(string $host): float
    {
        return max($this->lastRequest[$host] ?? 0.0, (float) get_transient(self::transientName($host)));
    }

    /**
     * Transient name for a host.
     *
     * @param string $host Host name.
     * @return string
     */
    public static function transientName(string $host): string
    {
        return OptionKeys::TRANSIENT_PREFIX . 'rl_' . md5($host);
    }
}
