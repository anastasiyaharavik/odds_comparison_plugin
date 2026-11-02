<?php

/**
 * Daily robots.txt check per source.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Support;

use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Http\HttpException;
use OddsComparison\Http\RobotsTxt;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Source\SourceFactory;

defined('ABSPATH') || exit;

/**
 * Checks once a day whether the source's robots.txt allows the paths the plugin uses.
 * The result is shown on the Status tab and raises an admin warning when paths are
 * disallowed; the decision stays with the site owner. A missing robots.txt
 * (404) allows everything by convention.
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
            $body = $this->http->get(rtrim($this->sources->make($sourceId)->baseUrl(), '/') . '/robots.txt')->body;
        } catch (HttpException $exception) {
            if ($exception->statusCode === 404) {
                $this->status->recordRobots(
                    $sourceId,
                    true,
                    __('No robots.txt; all paths allowed.', 'odds-comparison')
                );
            } else {
                $this->status->recordRobots($sourceId, null, $exception->getMessage());
            }

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
                : sprintf(__('Disallowed: %s', 'odds-comparison'), implode(', ', $disallowed))
        );

        return true;
    }
}
