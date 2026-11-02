<?php

/**
 * Odds format conversion.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Odds;

use InvalidArgumentException;
use OddsComparison\Domain\OddsFormat;
use OddsComparison\Domain\Price;

defined('ABSPATH') || exit;

/**
 * Converts between fractional, decimal and American odds. Pure PHP, no WordPress.
 *
 * This is the single source of truth for conversions. The frontend format switcher only
 * swaps strings this class rendered into `data-*` attributes.
 *
 * Rules:
 * - Fractional → decimal: n/d + 1. "evens", "evs" (any case) mean 1/1.
 * - American → decimal: +150 → 1 + 150/100; -200 → 1 + 100/200; ±100 → 2.00. Values strictly
 *   between -100 and +100 are invalid.
 * - Decimal → American: from the exact fraction n/d below, not from the rounded decimal:
 *   n ≥ d → +round(100·n/d); n < d → -round(100·d/n). 1.17 (1/6) is -600, not -588.
 * - Decimal → fractional, in two steps:
 *   1. When the decimal is a standard UK bookmaker fraction rounded to two places (1.91 is
 *      10/11 = 1.909…, 1.67 is 4/6), that fraction is shown as bookmakers quote it, not reduced.
 *      This deliberately differs from the decimal by up to 0.005 (the rounding the decimal
 *      already carries). The window is half-open, [fraction − 0.005, fraction + 0.005), so it
 *      holds exactly one two-place decimal: 3.12 is 85/40 (3.125), 3.13 is not.
 *   2. Otherwise the best rational approximation with a denominator ≤ 100, reduced. This is
 *      exact for two-decimal prices (3.90 → 29/10).
 *   The same decimal always renders as the same fraction, so equal prices look equal, and
 *   different two-place decimals never render as the same fraction. Fractional and American
 *   always come from the same fraction, so they agree (1.995 is 1/1 and +100).
 * - Invalid input throws InvalidArgumentException; callers drop the price or fall back.
 */
final class OddsConverter
{
    /**
     * Fractions already rendered in this request, by decimal.
     *
     * @var array<string, string>
     */
    private array $fractions = [];

    /**
     * American odds already rendered in this request, by decimal.
     *
     * @var array<string, string>
     */
    private array $americans = [];

    /** Largest denominator for fractions outside the standard ladder. */
    private const MAX_DENOMINATOR = 100;

    /** A ladder fraction is used when the decimal is within this distance below it, or less above. */
    private const SNAP_TOLERANCE = 0.005;

    /**
     * Standard UK bookmaker fractions, as quoted (not reduced: 4/6, 6/4, 85/40).
     *
     * @var list<array{int, int}>
     */
    private const LADDER = [
        [1, 10], [1, 9], [1, 8], [2, 15], [1, 7], [2, 13], [1, 6], [2, 11], [1, 5], [2, 9],
        [1, 4], [2, 7], [3, 10], [1, 3], [4, 11], [2, 5], [4, 9], [1, 2], [8, 15], [4, 7],
        [8, 13], [4, 6], [8, 11], [4, 5], [5, 6], [10, 11], [1, 1], [21, 20], [11, 10], [6, 5],
        [5, 4], [11, 8], [7, 5], [6, 4], [13, 8], [8, 5], [17, 10], [7, 4], [9, 5], [15, 8],
        [2, 1], [85, 40], [11, 5], [9, 4], [12, 5], [5, 2], [13, 5], [11, 4], [14, 5], [3, 1],
        [16, 5], [10, 3], [7, 2], [18, 5], [4, 1], [9, 2], [5, 1], [11, 2], [6, 1], [13, 2],
        [7, 1], [15, 2], [8, 1], [17, 2], [9, 1], [10, 1], [11, 1], [12, 1], [14, 1], [16, 1],
        [18, 1], [20, 1], [22, 1], [25, 1], [28, 1], [33, 1], [40, 1], [50, 1], [66, 1], [80, 1],
        [100, 1],
    ];

    /**
     * Convert any format to decimal odds.
     *
     * @param string|float|int $value Value in the given format (e.g. "5/2", "evens", "+150", 3.5).
     * @param OddsFormat       $from  Format of $value.
     * @return float Decimal odds (> 1).
     * @throws InvalidArgumentException When the value is not valid odds in that format.
     */
    public function toDecimal(string|float|int $value, OddsFormat $from): float
    {
        $decimal = match ($from) {
            OddsFormat::Fractional => $this->fractionalToDecimal((string) $value),
            OddsFormat::American   => $this->americanToDecimal($value),
            OddsFormat::Decimal    => $this->number($value),
        };

        return $this->assertDecimal($decimal);
    }

    /**
     * Decimal odds as a fraction "n/d".
     *
     * @param float $decimal Decimal odds (> 1).
     * @return string
     * @throws InvalidArgumentException When the decimal odds are invalid.
     */
    public function toFractional(float $decimal): string
    {
        [$numerator, $denominator] = $this->fraction($decimal);

        return $numerator . '/' . $denominator;
    }

    /**
     * Decimal odds as American odds ("+150", "-200").
     *
     * Computed from the exact fraction behind the price ({@see self::toFractional()}), not from
     * the two-decimal rounding: 1.17 is 1/6, so -600 (100 × 6/1), not -588 (100 / 0.17).
     * Rounding happens once, at the end.
     *
     * @param float $decimal Decimal odds (> 1).
     * @return string
     * @throws InvalidArgumentException When the decimal odds are invalid.
     */
    public function toAmerican(float $decimal): string
    {
        [$numerator, $denominator] = $this->fraction($decimal);

        return $numerator >= $denominator
            ? '+' . (int) round(100 * $numerator / $denominator)
            : '-' . (int) round(100 * $denominator / $numerator);
    }

    /**
     * Exact fraction (profit per unit stake) behind decimal odds: the standard fraction the
     * decimal is a two-place rounding of (within {@see self::SNAP_TOLERANCE}), else the closest
     * fraction with a denominator up to {@see self::MAX_DENOMINATOR}.
     *
     * @param float $decimal Decimal odds (> 1).
     * @return array{int, int} [numerator, denominator]
     * @throws InvalidArgumentException When the decimal odds are invalid.
     */
    private function fraction(float $decimal): array
    {
        $profit = $this->assertDecimal($decimal) - 1.0;
        $best   = null;
        $error  = INF;

        $offset = 0.0;

        foreach (self::LADDER as [$numerator, $denominator]) {
            $candidate = abs($numerator / $denominator - $profit);

            if ($candidate < $error) {
                $best   = [$numerator, $denominator];
                $error  = $candidate;
                $offset = $profit - $numerator / $denominator;
            }
        }

        // Half-open window [exact − 0.005, exact + 0.005): it holds exactly one two-place decimal,
        // so two different prices never share a fraction (3.12 is 85/40; 3.13 is 213/100). The
        // 1e-9 absorbs floating-point noise at the edges.
        if ($best !== null && $offset >= -self::SNAP_TOLERANCE - 1e-9 && $offset < self::SNAP_TOLERANCE - 1e-9) {
            return $best;
        }

        return $this->approximate($profit);
    }

    /**
     * Render a price in a format, always from the decimal price.
     *
     * Decimal is shown with 2 decimal places. Fractions are memoized per decimal for the request,
     * because one page repeats the same few prices many times.
     *
     * @param Price      $price  Price.
     * @param OddsFormat $target Target format.
     * @return string
     * @throws InvalidArgumentException Never for a valid Price (kept for interface honesty).
     */
    public function format(Price $price, OddsFormat $target): string
    {
        return match ($target) {
            OddsFormat::Decimal    => number_format($price->decimal, 2, '.', ''),
            OddsFormat::Fractional => $this->fractions[(string) $price->decimal]
                ??= $this->toFractional($price->decimal),
            OddsFormat::American   => $this->americans[(string) $price->decimal]
                ??= $this->toAmerican($price->decimal),
        };
    }

    /**
     * Parse "n/d" or evens.
     *
     * @param string $value Fraction.
     * @return float
     * @throws InvalidArgumentException When malformed.
     */
    private function fractionalToDecimal(string $value): float
    {
        $value = strtolower(trim($value));

        if ($value === 'evens' || $value === 'evs') {
            return 2.0;
        }

        if (preg_match('#^(\d+)\s*/\s*(\d+)$#', $value, $parts) !== 1 || (int) $parts[2] === 0) {
            throw new InvalidArgumentException(sprintf('Invalid fractional odds "%s".', $value));
        }

        return (int) $parts[1] / (int) $parts[2] + 1.0;
    }

    /**
     * Parse American odds.
     *
     * @param string|float|int $value American odds.
     * @return float
     * @throws InvalidArgumentException When malformed or strictly between -100 and +100.
     */
    private function americanToDecimal(string|float|int $value): float
    {
        $american = $this->number(is_string($value) ? ltrim(trim($value), '+') : $value);

        if ($american > -100.0 && $american < 100.0) {
            throw new InvalidArgumentException(sprintf('American odds must be ≤ -100 or ≥ +100, got %s.', $american));
        }

        return $american > 0 ? 1.0 + $american / 100 : 1.0 + 100 / -$american;
    }

    /**
     * Parse a finite number.
     *
     * @param string|float|int $value Value.
     * @return float
     * @throws InvalidArgumentException When not numeric or not finite.
     */
    private function number(string|float|int $value): float
    {
        if (is_string($value)) {
            $value = trim($value);

            if (!is_numeric($value)) {
                throw new InvalidArgumentException(sprintf('Not a number: "%s".', $value));
            }
        }

        $number = (float) $value;

        if (!is_finite($number)) {
            throw new InvalidArgumentException('Odds must be finite.');
        }

        return $number;
    }

    /**
     * Assert valid decimal odds (finite and > 1).
     *
     * @param float $decimal Decimal odds.
     * @return float
     * @throws InvalidArgumentException When invalid.
     */
    private function assertDecimal(float $decimal): float
    {
        if (!is_finite($decimal)) {
            throw new InvalidArgumentException('Decimal odds must be finite.');
        }

        if ($decimal <= 1.0) {
            throw new InvalidArgumentException(sprintf('Decimal odds must be above 1, got %s.', $decimal));
        }

        return $decimal;
    }

    /**
     * Best rational approximation n/d of a positive number with d ≤ MAX_DENOMINATOR, reduced.
     *
     * Trying every denominator is exact and cheap for d ≤ 100 (the same result as walking the
     * continued-fraction convergents and semiconvergents). Ties go to the smaller denominator.
     *
     * @param float $value Positive number.
     * @return array{int, int}
     */
    private function approximate(float $value): array
    {
        $best  = [max(1, (int) round($value)), 1];
        $error = abs($best[0] - $value);

        for ($denominator = 2; $denominator <= self::MAX_DENOMINATOR; $denominator++) {
            $numerator = max(1, (int) round($value * $denominator));
            $candidate = abs($numerator / $denominator - $value);

            if ($candidate < $error - 1e-12) {
                $best  = [$numerator, $denominator];
                $error = $candidate;
            }
        }

        $divisor = $this->gcd($best[0], $best[1]);

        return [intdiv($best[0], $divisor), intdiv($best[1], $divisor)];
    }

    /**
     * Greatest common divisor.
     *
     * @param int $a First.
     * @param int $b Second.
     * @return int
     */
    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, $a);
    }
}
