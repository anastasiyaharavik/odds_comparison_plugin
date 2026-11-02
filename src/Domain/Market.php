<?php

/**
 * Betting market value object.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Domain;

use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * A betting market (e.g. match result, outright winner).
 *
 * `selectionType` tells the validator how to judge completeness. Match markets
 * (three-way, two-way) have a fixed set of selections and get the overround check. Outright
 * markets (competition winner, top goalscorer, ...) have a variable number of selections
 * and are offered by outrights events only.
 *
 * `sourceNames` maps a source id to the market's name on that source, e.g.
 * `['bettingodds' => 'Match Result']`. The source's parser uses it to pick the right prices.
 */
final class Market
{
    /** Three outcomes, e.g. 1X2 (home / draw / away). */
    public const SELECTION_THREE_WAY = 'three-way';

    /** Two outcomes, e.g. yes/no, over/under, draw-no-bet home/away. */
    public const SELECTION_TWO_WAY = 'two-way';

    /** Variable number of selections (teams or players) in a competition-level market. */
    public const SELECTION_OUTRIGHT = 'outright';

    /** Number of selections in a complete market, by selection type (null = variable). */
    private const SELECTION_COUNTS = [
        self::SELECTION_THREE_WAY => 3,
        self::SELECTION_TWO_WAY   => 2,
        self::SELECTION_OUTRIGHT  => null,
    ];

    /**
     * @param string                $key           Kebab-case market key (e.g. `match-result`).
     * @param string                $label         Display label.
     * @param array<string, string> $sourceNames   Source id => the market's name on that source.
     * @param string                $selectionType One of the SELECTION_* constants.
     * @throws InvalidArgumentException When any field is invalid.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $sourceNames,
        public readonly string $selectionType
    ) {
        Assert::slug($key, 'Market key');
        Assert::notBlank($label, 'Market label');
        Assert::sourceMap($sourceNames, 'Market sourceNames');

        if (!array_key_exists($selectionType, self::SELECTION_COUNTS)) {
            throw new InvalidArgumentException(
                sprintf('Unknown market selectionType %s.', json_encode($selectionType, Assert::JSON))
            );
        }
    }

    /**
     * Build from a registry definition (`config/markets.php` or the `odds_comparison/markets` filter).
     *
     * @param array<string, mixed> $data Keys: key, label, sourceNames, selectionType.
     * @return self
     * @throws InvalidArgumentException When required keys are missing or invalid.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'key'),
            Field::string($data, 'label'),
            Field::array($data, 'sourceNames', []),
            Field::string($data, 'selectionType')
        );
    }

    /**
     * The market's name on a source, or null when the source does not offer it.
     *
     * @param string $sourceId Source id.
     * @return string|null
     */
    public function sourceName(string $sourceId): ?string
    {
        return $this->sourceNames[$sourceId] ?? null;
    }

    /**
     * Whether this is a competition-level (outright) market rather than a match market.
     *
     * @return bool
     */
    public function isOutright(): bool
    {
        return $this->selectionType === self::SELECTION_OUTRIGHT;
    }

    /**
     * Number of selections a complete market has, or null for outright markets (variable).
     *
     * @return int|null
     */
    public function expectedSelectionCount(): ?int
    {
        return self::SELECTION_COUNTS[$this->selectionType];
    }
}
