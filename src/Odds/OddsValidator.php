<?php

/**
 * Data-accuracy checks for fetched odds.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Odds;

use OddsComparison\Domain\Market;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Domain\Price;

defined('ABSPATH') || exit;

/**
 * Cleans fetched odds and rejects results that look like a mis-parse. Pure PHP.
 *
 * Applied in order:
 * 1. Range: 1.01 ≤ decimal ≤ 1001, otherwise the price is dropped. Only the decimal price is
 *    used; fractions shown by a source are ignored (fractions are derived from the decimal).
 * 2. Overround (match markets only): for each bookmaker that prices every selection,
 *    Σ 1/decimal must lie in [0.98, 1.40]. Otherwise all of that bookmaker's prices for the
 *    market are dropped. A book below 0.98 would be a guaranteed profit (arbitrage) inside one
 *    bookmaker, and one above 1.40 is far beyond any real margin, so both point to a mis-parse.
 *    Outright books are partial or multi-place (e.g. relegation pays 3), so they are skipped.
 * 3. Minimum quality: at least 2 bookmakers; a match market needs every selection priced; an
 *    outright market needs at least 2 priced selections. Otherwise the whole result is rejected
 *    and the caller keeps the previous good data.
 */
final class OddsValidator
{
    public const MIN_DECIMAL = 1.01;

    public const MAX_DECIMAL = 1001.0;

    public const MIN_OVERROUND = 0.98;

    public const MAX_OVERROUND = 1.40;

    public const MIN_BOOKMAKERS = 2;

    public const MIN_OUTRIGHT_SELECTIONS = 2;

    /**
     * Validate and clean odds for a market.
     *
     * @param MarketOdds $odds   Fetched odds.
     * @param Market     $market Market definition (selection type).
     * @return ValidationResult
     */
    public function validate(MarketOdds $odds, Market $market): ValidationResult
    {
        $notes      = [];
        $selections = $this->dropImplausiblePrices($odds->selections, $notes);

        if (!$market->isOutright()) {
            $selections = $this->dropOffMarketBooks($selections, $notes);
        }

        $selections = array_filter($selections, static fn (array $selection): bool => $selection['prices'] !== []);
        $rejection  = $this->qualityProblem($selections, $market);

        if ($rejection !== null) {
            return ValidationResult::rejected($rejection, $notes);
        }

        return ValidationResult::accepted($odds->withSelections($selections), $notes);
    }

    /**
     * Range check per price.
     *
     * @param array<string, array{label: string, prices: array<string, Price>}> $selections Selections.
     * @param list<string>                                                      $notes      Drop notes (appended).
     * @return array<string, array{label: string, prices: array<string, Price>}>
     */
    private function dropImplausiblePrices(array $selections, array &$notes): array
    {
        foreach ($selections as $key => $selection) {
            foreach ($selection['prices'] as $slug => $price) {
                $problem = $this->priceProblem($price);

                if ($problem !== null) {
                    unset($selections[$key]['prices'][$slug]);
                    $notes[] = sprintf('Dropped %s / %s: %s.', $slug, $selection['label'], $problem);
                }
            }
        }

        return $selections;
    }

    /**
     * Why a single price is implausible, or null when it is fine.
     *
     * @param Price $price Price.
     * @return string|null
     */
    private function priceProblem(Price $price): ?string
    {
        if ($price->decimal < self::MIN_DECIMAL || $price->decimal > self::MAX_DECIMAL) {
            return sprintf('decimal %.4F outside %.2F–%.0F', $price->decimal, self::MIN_DECIMAL, self::MAX_DECIMAL);
        }

        return null;
    }

    /**
     * Overround check per bookmaker with a complete book.
     *
     * @param array<string, array{label: string, prices: array<string, Price>}> $selections Selections.
     * @param list<string>                                                      $notes      Drop notes (appended).
     * @return array<string, array{label: string, prices: array<string, Price>}>
     */
    private function dropOffMarketBooks(array $selections, array &$notes): array
    {
        $books = [];

        foreach ($selections as $selection) {
            foreach ($selection['prices'] as $slug => $price) {
                $books[$slug][] = 1 / $price->decimal;
            }
        }

        foreach ($books as $slug => $inverses) {
            if (count($inverses) !== count($selections)) {
                continue;
            }

            $overround = array_sum($inverses);

            if ($overround < self::MIN_OVERROUND || $overround > self::MAX_OVERROUND) {
                foreach (array_keys($selections) as $key) {
                    unset($selections[$key]['prices'][$slug]);
                }

                $notes[] = sprintf(
                    'Dropped all %s prices: overround %.3F outside %.2F–%.2F.',
                    $slug,
                    $overround,
                    self::MIN_OVERROUND,
                    self::MAX_OVERROUND
                );
            }
        }

        return $selections;
    }

    /**
     * Minimum-quality rule, or null when satisfied.
     *
     * @param array<string, array{label: string, prices: array<string, Price>}> $selections Priced selections.
     * @param Market                                                            $market     Market.
     * @return string|null
     */
    private function qualityProblem(array $selections, Market $market): ?string
    {
        $bookmakers = [];

        foreach ($selections as $selection) {
            $bookmakers += array_flip(array_keys($selection['prices']));
        }

        if (count($bookmakers) < self::MIN_BOOKMAKERS) {
            return sprintf('only %d bookmaker(s) with valid prices', count($bookmakers));
        }

        $expected = $market->expectedSelectionCount();

        if ($expected !== null && count($selections) < $expected) {
            return sprintf('%d of %d selections priced', count($selections), $expected);
        }

        if ($expected === null && count($selections) < self::MIN_OUTRIGHT_SELECTIONS) {
            return sprintf('only %d outright selection(s) priced', count($selections));
        }

        return null;
    }
}
