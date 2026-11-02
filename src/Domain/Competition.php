<?php

/**
 * Competition value object.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Domain;

use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * A competition whose events are discovered from a source (e.g. Premier League).
 *
 * `sourcePaths` holds the competition's listing page per source. `outrightPaths` holds, per
 * source, the page of each outright market the source offers for this competition, e.g.
 * `['bettingodds' => ['outright-winner' => '/football/premier-league/winner']]`. Outright
 * availability differs between competitions, so it is registry data rather than a rule.
 */
final class Competition
{
    /**
     * @param string                                $slug          Kebab-case identifier (e.g. `premier-league`).
     * @param string                                $name          Display name.
     * @param string                                $sport         Kebab-case sport (e.g. `football`).
     * @param array<string, string>                 $sourcePaths   Source id => host-relative listing path.
     * @param array<string, array<string, string>>  $outrightPaths Source id => (market key => host-relative path).
     * @throws InvalidArgumentException When any field is invalid.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $sport,
        public readonly array $sourcePaths,
        public readonly array $outrightPaths = []
    ) {
        Assert::slug($slug, 'Competition slug');
        Assert::notBlank($name, 'Competition name');
        Assert::slug($sport, 'Competition sport');
        Assert::sourceMap($sourcePaths, 'Competition sourcePaths', self::hostRelativePath(...));

        foreach ($outrightPaths as $sourceId => $paths) {
            if (!is_string($sourceId) || !is_array($paths)) {
                throw new InvalidArgumentException('Competition outrightPaths must map source ids to market paths.');
            }

            Assert::slug($sourceId, 'Competition outrightPaths source id');

            foreach ($paths as $marketKey => $path) {
                if (!is_string($marketKey) || !is_string($path)) {
                    throw new InvalidArgumentException('Competition outrightPaths must map market keys to paths.');
                }

                Assert::slug($marketKey, 'Competition outrightPaths market key');
                self::hostRelativePath($path, 'Competition outrightPaths[' . $sourceId . '][' . $marketKey . ']');
            }
        }
    }

    /**
     * Build from a registry definition (`config/competitions.php` or the `odds_comparison/competitions` filter).
     *
     * @param array<string, mixed> $data Keys: slug, name, sport, sourcePaths, outrightPaths?.
     * @return self
     * @throws InvalidArgumentException When required keys are missing or invalid.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'slug'),
            Field::string($data, 'name'),
            Field::string($data, 'sport'),
            Field::array($data, 'sourcePaths', []),
            Field::array($data, 'outrightPaths', [])
        );
    }

    /**
     * Listing path for this competition on a source, or null when the source does not cover it.
     *
     * @param string $sourceId Source id.
     * @return string|null
     */
    public function sourcePath(string $sourceId): ?string
    {
        return $this->sourcePaths[$sourceId] ?? null;
    }

    /**
     * Page path of one outright market on a source, or null when not offered.
     *
     * @param string $sourceId  Source id.
     * @param string $marketKey Market key.
     * @return string|null
     */
    public function outrightPath(string $sourceId, string $marketKey): ?string
    {
        return $this->outrightPaths[$sourceId][$marketKey] ?? null;
    }

    /**
     * Market keys of the outright markets a source offers for this competition.
     *
     * @param string $sourceId Source id.
     * @return list<string>
     */
    public function outrightMarketKeys(string $sourceId): array
    {
        return array_keys($this->outrightPaths[$sourceId] ?? []);
    }

    /**
     * Assert a host-relative path (leading slash).
     *
     * @param string $path  Path.
     * @param string $field Field name for the error message.
     * @return void
     * @throws InvalidArgumentException When the path is unsafe.
     */
    private static function hostRelativePath(string $path, string $field): void
    {
        Assert::relativePath($path, $field, true);
    }
}
