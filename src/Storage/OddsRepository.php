<?php

/**
 * Transient-backed odds storage.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Storage;

use Closure;
use InvalidArgumentException;
use OddsComparison\Contracts\OddsRepositoryInterface;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * Stores the latest good odds per (event, market) in transients.
 *
 * - Key: `odds_comparison_odds_` + md5(eventId|marketKey), which stays under the transient name
 *   limit. With a persistent object cache, WordPress keeps transients there automatically.
 * - Transient expiry is 7 days and only serves as garbage collection. Freshness comes from
 *   `fetchedAt`, so stale data is still served and can be marked.
 * - Reads are memoized per request, so several blocks for the same pair read once.
 * - Stored transient names are listed in a small non-autoloaded option (name => expiry time), so
 *   {@see self::flush()} works without SQL and with object caches. Entries whose transient has
 *   expired are dropped whenever the list is written, and {@see self::delete()} removes its own
 *   entry, so the list only holds pairs saved within the last 7 days.
 * - Per site: on multisite each site has its own transients and list.
 */
final class OddsRepository implements OddsRepositoryInterface
{
    /** Transient lifetime: garbage collection only. */
    private const EXPIRY = 7 * 86400;

    /**
     * Per-request read memo (null = known missing).
     *
     * @var array<string, MarketOdds|null>
     */
    private array $memo = [];

    private readonly Closure $staleAfterMinutes;

    private readonly Closure $clock;

    /**
     * @param Closure      $staleAfterMinutes fn(): int; minutes before odds count as stale.
     * @param Closure|null $clock             fn(): int; current Unix time (Recorded mode, tests).
     */
    public function __construct(Closure $staleAfterMinutes, ?Closure $clock = null)
    {
        $this->staleAfterMinutes = $staleAfterMinutes;
        $this->clock             = $clock ?? static fn (): int => time();
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $eventId, string $marketKey): ?MarketOdds
    {
        $key = $this->key($eventId, $marketKey);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $stored = get_transient($key);
        $odds   = null;

        if (is_array($stored)) {
            try {
                $odds = MarketOdds::fromArray($stored);
            } catch (InvalidArgumentException) {
                // Corrupted or from an incompatible version: treat as missing.
                delete_transient($key);
            }
        }

        return $this->memo[$key] = $odds;
    }

    /**
     * {@inheritDoc}
     */
    public function save(MarketOdds $odds): bool
    {
        $key   = $this->key($odds->eventId, $odds->marketKey);
        $saved = set_transient($key, $odds->toArray(), self::EXPIRY);

        if ($saved) {
            $this->memo[$key] = $odds;
            $this->remember($key);
        }

        return $saved;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $eventId, string $marketKey): bool
    {
        $key = $this->key($eventId, $marketKey);
        unset($this->memo[$key]);
        $this->forget($key);

        return delete_transient($key);
    }

    /**
     * {@inheritDoc}
     */
    public function isStale(MarketOdds $odds): bool
    {
        $age = ($this->clock)() - $odds->fetchedAt->getTimestamp();

        return $age > max(1, ($this->staleAfterMinutes)()) * 60;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): void
    {
        foreach (array_keys($this->storedKeys()) as $key) {
            delete_transient($key);
        }

        delete_option(OptionKeys::ODDS_KEYS);
        $this->memo = [];
    }

    /**
     * Transient name for a pair.
     *
     * @param string $eventId   Event id.
     * @param string $marketKey Market key.
     * @return string
     */
    private function key(string $eventId, string $marketKey): string
    {
        return OptionKeys::TRANSIENT_PREFIX . 'odds_' . md5($eventId . '|' . $marketKey);
    }

    /**
     * Add or renew a transient name in the stored-keys list, dropping expired entries.
     *
     * @param string $key Transient name.
     * @return void
     */
    private function remember(string $key): void
    {
        $keys       = $this->liveKeys();
        $keys[$key] = ($this->clock)() + self::EXPIRY;

        update_option(OptionKeys::ODDS_KEYS, $keys, false);
    }

    /**
     * Remove a transient name from the stored-keys list.
     *
     * @param string $key Transient name.
     * @return void
     */
    private function forget(string $key): void
    {
        $stored = $this->storedKeys();
        $keys   = $this->liveKeys();
        unset($keys[$key]);

        if ($keys !== $stored) {
            update_option(OptionKeys::ODDS_KEYS, $keys, false);
        }
    }

    /**
     * Stored entries whose transient has not expired yet.
     *
     * @return array<string, int>
     */
    private function liveKeys(): array
    {
        $now = ($this->clock)();

        return array_filter($this->storedKeys(), static fn (int $expires): bool => $expires > $now);
    }

    /**
     * Stored transient names with their expiry time.
     *
     * Lists written by earlier versions hold names only; those entries get a full lifetime from
     * now, which keeps them until their transient has certainly expired.
     *
     * @return array<string, int>
     */
    private function storedKeys(): array
    {
        $stored = get_option(OptionKeys::ODDS_KEYS, []);
        $keys   = [];

        if (!is_array($stored)) {
            return $keys;
        }

        foreach ($stored as $name => $expires) {
            if (is_string($name) && is_int($expires)) {
                $keys[$name] = $expires;
            } elseif (is_int($name) && is_string($expires)) {
                $keys[$expires] = ($this->clock)() + self::EXPIRY;
            }
        }

        return $keys;
    }
}
