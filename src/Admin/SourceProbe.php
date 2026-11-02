<?php

/**
 * Checks that a data source can deliver odds before it is activated.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Admin;

use InvalidArgumentException;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Settings\Settings;
use OddsComparison\Source\SourceFactory;
use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Makes one real discovery request through a source (the `active_source` setting).
 *
 * Runs only when an administrator switches the source (one request; one credit on The Odds
 * API). It uses the first enabled competition the source covers, else any competition it
 * covers.
 */
final class SourceProbe
{
    /**
     * @param SourceFactory       $sources      Source factory.
     * @param CompetitionRegistry $competitions Competition registry.
     * @param Settings            $settings     Settings (enabled competitions).
     */
    public function __construct(
        private readonly SourceFactory $sources,
        private readonly CompetitionRegistry $competitions,
        private readonly Settings $settings
    ) {
    }

    /**
     * Why a source cannot be used right now, or null when it returned events.
     *
     * @param string $sourceId Source id.
     * @return string|null Plain-text reason.
     */
    public function problem(string $sourceId): ?string
    {
        $candidates = [
            ...array_values($this->competitions->only($this->settings->enabledCompetitions())),
            ...array_values($this->competitions->all()),
        ];

        foreach ($candidates as $competition) {
            if ($competition->sourcePath($sourceId) === null) {
                continue;
            }

            try {
                $events = $this->sources->make($sourceId)->discoverEvents($competition);
            } catch (RuntimeException | InvalidArgumentException $exception) {
                return self::reason($exception->getMessage());
            }

            return $events === []
                /* translators: %s: competition name. */
                ? sprintf(__('no events were found for %s', 'odds-comparison'), $competition->name)
                : null;
        }

        return __('it covers none of the registered competitions', 'odds-comparison');
    }

    /**
     * Short reason from an exception message (drops the URL prefix, which may be long).
     *
     * @param string $message Exception message (already redacted).
     * @return string
     */
    private static function reason(string $message): string
    {
        $reason = (string) preg_replace('#^https?://\S+?:\s*#', '', $message);

        return rtrim($reason !== '' ? $reason : $message, '.');
    }
}
