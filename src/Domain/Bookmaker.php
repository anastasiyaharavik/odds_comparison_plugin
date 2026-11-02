<?php

/**
 * Bookmaker value object.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Domain;

use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * A bookmaker known to the plugin (registry data, not a scraper).
 *
 * `sourceCodes` maps a source id to the bookmaker's identifier on that source, e.g.
 * `['bettingodds' => 'williamhill']`. Adding a bookmaker is therefore a registry entry, never a class.
 */
final class Bookmaker
{
    /**
     * @param string                $slug        Kebab-case identifier (e.g. `bet365`, `william-hill`).
     * @param string                $name        Display name.
     * @param string                $logoUrl     Absolute http(s) logo URL, or "" for none.
     * @param array<string, string> $sourceCodes Source id => bookmaker code on that source.
     * @param string                $defaultUrl  Absolute http(s) landing URL, or "" for none.
     * @throws InvalidArgumentException When any field is invalid.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $logoUrl = '',
        public readonly array $sourceCodes = [],
        public readonly string $defaultUrl = ''
    ) {
        Assert::slug($slug, 'Bookmaker slug');
        Assert::notBlank($name, 'Bookmaker name');
        Assert::httpUrl($logoUrl, 'Bookmaker logoUrl');
        Assert::sourceMap($sourceCodes, 'Bookmaker sourceCodes');
        Assert::httpUrl($defaultUrl, 'Bookmaker defaultUrl');
    }

    /**
     * Build from a registry definition (`config/bookmakers.php` or the `odds_comparison/bookmakers` filter).
     *
     * @param array<string, mixed> $data Keys: slug, name, logoUrl?, sourceCodes?, defaultUrl?.
     * @return self
     * @throws InvalidArgumentException When required keys are missing or invalid.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'slug'),
            Field::string($data, 'name'),
            Field::string($data, 'logoUrl', ''),
            Field::array($data, 'sourceCodes', []),
            Field::string($data, 'defaultUrl', '')
        );
    }

    /**
     * The bookmaker's code on a given source, or null when it is not listed there.
     *
     * @param string $sourceId Source id (e.g. `bettingodds`).
     * @return string|null
     */
    public function sourceCode(string $sourceId): ?string
    {
        return $this->sourceCodes[$sourceId] ?? null;
    }
}
