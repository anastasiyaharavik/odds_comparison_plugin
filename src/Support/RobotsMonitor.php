<?php

/**
 * Daily robots.txt check per source.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Support;

use InvalidArgumentException;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Domain\Competition;
use OddsComparison\Http\HttpException;
use OddsComparison\Http\TimeBudgetExceeded;
use OddsComparison\Http\RobotsTxt;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Source\SourceFactory;
use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Checks once a day whether the source's robots.txt allows the paths the plugin uses
 * (competition listings and outright market pages), and enforces the result: refresh runs ask
 * {@see self::allows()} before each discovery and pair, and never request a disallowed path.
 * The result is shown on the Status tab and raises an admin warning. A missing robots.txt (404)
 * allows everything by convention; a check that fails (network error) keeps the previous
 * result.
 */
final class RobotsMonitor
{
    /** Product token matched against User-agent groups. */
    private const PRODUCT_TOKEN = 'OddsComparisonPlugin';

    /** Seconds between checks. */
    private const INTERVAL = 86400;

    /**
     * @param HttpClientInterface $http         Transport.
     * @param SourceFactory       $sources      Source factory (base URLs).
     * @param CompetitionRegistry $competitions Competition registry (paths).
     * @param StatusRecorder      $status       Status storage.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SourceFactory $sources,
        private readonly CompetitionRegistry $competitions,
        private readonly StatusRecorder $status
    ) {
    }

    /**
     * Check the source's robots.txt when the last check is older than a day.
     *
     * @param string       $sourceId     Source id.
     * @param list<string> $competitions Enabled competition slugs (their paths are checked).
     * @return bool Whether a request was made.
     */
    public function checkIfDue(string $sourceId, array $competitions): bool
    {
        if (time() - $this->status->source($sourceId)['robots']['checkedAt'] < self::INTERVAL) {
            return false;
        }

        $paths = [];

        foreach ($this->competitions->only($competitions) as $competition) {
            $paths[] = (string) $competition->sourcePath($sourceId);

            foreach ($competition->outrightMarketKeys($sourceId) as $marketKey) {
                $paths[] = (string) $competition->outrightPath($sourceId, $marketKey);
            }
        }

        $paths = array_values(array_filter($paths));

        try {
            $robotsUrl = rtrim($this->sources->make($sourceId)->baseUrl(), '/') . '/robots.txt';
        } catch (InvalidArgumentException) {
            return false; // Unknown source: nothing to check; the run reports the source itself.
        }

        try {
            $body = $this->http->get($robotsUrl)->body;
        } catch (TimeBudgetExceeded) {
            return false; // Nothing was sent; the check runs again next time.
        } catch (HttpException $exception) {
            if ($exception->statusCode === 404) {
                $this->status->recordRobots(
                    $sourceId,
                    true,
                    __('No robots.txt; all paths allowed.', 'odds-comparison')
                );
            } else {
                // Keep enforcing the last known disallowed paths until a check succeeds.
                $this->status->recordRobots(
                    $sourceId,
                    null,
                    $exception->getMessage(),
                    $this->status->source($sourceId)['robots']['disallowed']
                );
            }

            return true;
        } catch (RuntimeException $exception) {
            // Any other transport failure: keep enforcing the last known result, never abort the run.
            $this->status->recordRobots(
                $sourceId,
                null,
                $exception->getMessage(),
                $this->status->source($sourceId)['robots']['disallowed']
            );

            return true;
        }

        $robots     = new RobotsTxt($body, self::PRODUCT_TOKEN);
        $disallowed = array_values(array_filter($paths, static fn (string $path): bool => !$robots->isAllowed($path)));

        $this->status->recordRobots(
            $sourceId,
            $disallowed === [],
            $disallowed === []
                ? sprintf(
                    /* translators: %d: number of paths. */
                    _n('%d path checked, allowed.', '%d paths checked, all allowed.', count($paths), 'odds-comparison'),
                    count($paths)
                )
                /* translators: %s: comma-separated paths. */
                : sprintf(__('Disallowed: %s', 'odds-comparison'), implode(', ', $disallowed)),
            $disallowed
        );

        return true;
    }

    /**
     * Whether the last robots.txt check allows the path a refresh step would request.
     *
     * @param string      $sourceId    Source id.
     * @param Competition $competition Competition.
     * @param string|null $marketKey   Market being priced, or null for event discovery (the listing).
     *                                 Outright markets use their own page; others the listing.
     * @return bool
     */
    public function allows(string $sourceId, Competition $competition, ?string $marketKey = null): bool
    {
        $path = ($marketKey === null ? null : $competition->outrightPath($sourceId, $marketKey))
            ?? $competition->sourcePath($sourceId);

        return $path === null || !in_array($path, $this->status->source($sourceId)['robots']['disallowed'], true);
    }
}
