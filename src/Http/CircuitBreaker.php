<?php

/**
 * Circuit breaker per source host.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

use Closure;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * Stops requests to a host that keeps failing.
 *
 *     closed --(N consecutive failures)--> open --(openSeconds elapsed)--> half-open
 *     half-open --(probe succeeds)--> closed
 *     half-open --(probe fails)-----> open again
 *
 * `pause()` opens the circuit for a server-requested time (Retry-After), whatever the failure
 * count. State is kept in a transient so it survives between cron runs and can be shown on the
 * Status tab.
 *
 * @phpstan-type State array{state: string, failures: int, openUntil: int, lastError: string}
 */
final class CircuitBreaker
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half-open';

    /** State is forgotten after a week of no activity. */
    private const MEMORY_SECONDS = 604800;

    private readonly Closure $clock;

    /**
     * @param int          $threshold   Consecutive failures that open the circuit.
     * @param int          $openSeconds How long the circuit stays open.
     * @param Closure|null $clock       fn(): int, current Unix time (tests).
     */
    public function __construct(
        private readonly int $threshold = 3,
        private readonly int $openSeconds = 3600,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Whether a request may be made now. An expired open circuit moves to half-open and allows
     * exactly this one probe.
     *
     * @param string $key Host name.
     * @return bool
     */
    public function allowRequest(string $key): bool
    {
        $state = $this->state($key);

        if ($state['state'] === self::OPEN) {
            if (($this->clock)() < $state['openUntil']) {
                return false;
            }

            $state['state'] = self::HALF_OPEN;
            $this->save($key, $state);
        }

        return true;
    }

    /**
     * Record a successful request: the circuit closes.
     *
     * @param string $key Host name.
     * @return void
     */
    public function recordSuccess(string $key): void
    {
        $state = $this->state($key);

        if ($state['state'] !== self::CLOSED || $state['failures'] > 0) {
            $this->save($key, ['state' => self::CLOSED, 'failures' => 0, 'openUntil' => 0] + $state);
        }
    }

    /**
     * Record a failed request. A failed half-open probe, or reaching the threshold, opens it.
     *
     * @param string $key    Host name.
     * @param string $reason Short failure reason (no secrets).
     * @return void
     */
    public function recordFailure(string $key, string $reason): void
    {
        $state              = $this->state($key);
        $state['failures'] += 1;
        $state['lastError'] = $reason;

        if ($state['state'] === self::HALF_OPEN || $state['failures'] >= $this->threshold) {
            $state['state']     = self::OPEN;
            $state['openUntil'] = ($this->clock)() + $this->openSeconds;
        }

        $this->save($key, $state);
    }

    /**
     * Open the circuit for a server-requested time (e.g. Retry-After).
     *
     * @param string $key     Host name.
     * @param int    $seconds Pause length.
     * @param string $reason  Short reason.
     * @return void
     */
    public function pause(string $key, int $seconds, string $reason): void
    {
        $state = $this->state($key);

        $this->save($key, [
            'state'     => self::OPEN,
            'failures'  => $state['failures'],
            'openUntil' => ($this->clock)() + max(1, $seconds),
            'lastError' => $reason,
        ]);
    }

    /**
     * Current state for diagnostics (Status tab).
     *
     * @param string $key Host name.
     * @return State
     */
    public function state(string $key): array
    {
        $stored = get_transient(self::transientName($key));
        $stored = is_array($stored) ? $stored : [];

        return [
            'state'     => in_array($stored['state'] ?? null, [self::OPEN, self::HALF_OPEN], true)
                ? (string) $stored['state'] : self::CLOSED,
            'failures'  => (int) ($stored['failures'] ?? 0),
            'openUntil' => (int) ($stored['openUntil'] ?? 0),
            'lastError' => (string) ($stored['lastError'] ?? ''),
        ];
    }

    /**
     * Forget all state for a host (admin "Reset circuit breaker").
     *
     * @param string $key Host name.
     * @return void
     */
    public function reset(string $key): void
    {
        delete_transient(self::transientName($key));
    }

    /**
     * Persist state.
     *
     * @param string $key   Host name.
     * @param State  $state State.
     * @return void
     */
    private function save(string $key, array $state): void
    {
        set_transient(self::transientName($key), $state, self::MEMORY_SECONDS);
    }

    /**
     * Transient name for a host.
     *
     * @param string $key Host name.
     * @return string
     */
    public static function transientName(string $key): string
    {
        return OptionKeys::TRANSIENT_PREFIX . 'cb_' . md5($key);
    }
}
