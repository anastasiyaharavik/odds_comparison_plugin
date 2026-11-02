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
 * @phpstan-type Entry array{time: int, level: string, source: string, message: string}
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
        $entry   = ['time' => time(), 'level' => $level, 'source' => $source, 'message' => $message];
        $entries = array_slice([$entry, ...$this->entries()], 0, self::MAX_ENTRIES);

        update_option(OptionKeys::ERRORS, $entries, false);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in via WP_DEBUG_LOG.
            error_log(sprintf('[odds-comparison] %s %s: %s', $level, $source, $message));
        }
    }
}
