<?php

/**
 * Capped error log.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Support;

use OddsComparison\Http\WpHttpClient;

defined('ABSPATH') || exit;

/**
 * Keeps the newest {@see self::MAX_ENTRIES} problems in a non-autoloaded option for the Status
 * tab. With `WP_DEBUG_LOG` on, entries also go to the PHP error log.
 *
 * Messages are plain text and are escaped where they are displayed. No personal data is logged,
 * and API keys are redacted before storage.
 *
 * An entry that repeats the newest one (same level, source and message) is not added again: the
 * newest entry's `count` goes up and its `time` moves to the latest occurrence.
 *
 * @phpstan-type Entry array{time: int, level: string, source: string, message: string, count?: int}
 */
final class Logger
{
    public const MAX_ENTRIES = 50;

    public const ERROR = 'error';

    public const WARNING = 'warning';

    /** Longest stored message, so one bad page cannot bloat the option. */
    private const MAX_MESSAGE_LENGTH = 500;

    /**
     * Record an error.
     *
     * @param string $source  Source id or component (e.g. `bettingodds`, `scheduler`).
     * @param string $message Plain-text message.
     * @return void
     */
    public function error(string $source, string $message): void
    {
        $this->log(self::ERROR, $source, $message);
    }

    /**
     * Record a warning.
     *
     * @param string $source  Source id or component.
     * @param string $message Plain-text message.
     * @return void
     */
    public function warning(string $source, string $message): void
    {
        $this->log(self::WARNING, $source, $message);
    }

    /**
     * Newest entries first.
     *
     * @return list<Entry>
     */
    public function entries(): array
    {
        $entries = get_option(OptionKeys::ERRORS, []);

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * Remove all entries.
     *
     * @return void
     */
    public function clear(): void
    {
        delete_option(OptionKeys::ERRORS);
    }

    /**
     * Store one entry.
     *
     * @param string $level   Level.
     * @param string $source  Source.
     * @param string $message Message.
     * @return void
     */
    private function log(string $level, string $source, string $message): void
    {
        $message = mb_substr(WpHttpClient::redact(trim($message)), 0, self::MAX_MESSAGE_LENGTH);
        $now     = time();

        $add = static function (mixed $stored) use ($level, $source, $message, $now): array {
            $entries = is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
            $newest  = $entries[0] ?? null;

            // The same problem again (e.g. every pair of a run hitting an open circuit): one entry
            // with a count and the latest time, so repeats cannot push real errors out of the log.
            if (
                $newest !== null
                && ($newest['level'] ?? '') === $level
                && ($newest['source'] ?? '') === $source
                && ($newest['message'] ?? '') === $message
            ) {
                $entries[0]['count'] = max(1, (int) ($newest['count'] ?? 1)) + 1;
                $entries[0]['time']  = $now;

                return $entries;
            }

            $entry = ['time' => $now, 'level' => $level, 'source' => $source, 'message' => $message, 'count' => 1];

            return array_slice([$entry, ...$entries], 0, self::MAX_ENTRIES);
        };

        OptionMutex::update(OptionKeys::ERRORS, $add);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in via WP_DEBUG_LOG.
            error_log(sprintf('[odds-comparison] %s %s: %s', $level, $source, $message));
        }
    }
}
