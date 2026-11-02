<?php

/**
 * Price value object.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Domain;

use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * One bookmaker's price for one selection.
 *
 * The decimal price is the only stored value: it is what the bookmaker pays. Fractional and
 * American odds are derived from it at render time by OddsConverter, so
 * every source shows the same fraction for the same price.
 *
 * Range/plausibility rules (1.01–1001) belong to OddsValidator. This class only rejects values
 * that cannot be odds at all.
 */
final class Price
{
    /**
     * @param float $decimal Decimal odds, must be finite and > 1.
     * @throws InvalidArgumentException When the decimal value is malformed.
     */
    public function __construct(public readonly float $decimal)
    {
        if (!is_finite($decimal) || $decimal <= 1.0) {
            throw new InvalidArgumentException(
                sprintf('Decimal odds must be a finite number above 1, got %F.', (float) $decimal)
            );
        }
    }

    /**
     * Rebuild from {@see self::toArray()} output. A `fractional` key stored by earlier versions
     * is ignored.
     *
     * @param array<string, mixed> $data Stored data.
     * @return self
     * @throws InvalidArgumentException When the data is malformed.
     */
    public static function fromArray(array $data): self
    {
        return new self(Field::float($data, 'decimal'));
    }

    /**
     * Plain-array form for storage.
     *
     * @return array{decimal: float}
     */
    public function toArray(): array
    {
        return ['decimal' => $this->decimal];
    }
}
