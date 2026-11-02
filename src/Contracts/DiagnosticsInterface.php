<?php

/**
 * Optional contract for sources that collect non-fatal problems.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

defined('ABSPATH') || exit;

/**
 * A source that collects non-fatal problems while it fetches (skipped events, dropped prices,
 * "markup changed" hints, bookmaker codes missing from the registry).
 *
 * Optional: the refresh reports these to the error log when a source implements it.
 * {@see \OddsComparison\Source\AbstractSource} implements it for every built-in source.
 */
interface DiagnosticsInterface
{
    /**
     * Non-fatal problems collected since construction or the last {@see self::clearDiagnostics()}.
     *
     * @return list<string>
     */
    public function warnings(): array;

    /**
     * Bookmaker codes seen on the source but not in the registry, with counts.
     *
     * @return array<string, int>
     */
    public function unmappedCodes(): array;

    /**
     * Forget the collected warnings and codes (after they were reported).
     *
     * @return void
     */
    public function clearDiagnostics(): void;
}
