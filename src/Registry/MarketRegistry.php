<?php

/**
 * Market registry.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Registry;

use OddsComparison\Domain\Market;

defined('ABSPATH') || exit;

/**
 * Markets from `config/markets.php` plus the `odds_comparison/markets` filter, keyed by market key.
 *
 * @extends AbstractRegistry<Market>
 */
final class MarketRegistry extends AbstractRegistry
{
    /** Filter that registers or modifies markets. */
    public const FILTER = 'odds_comparison/markets';

    /**
     * {@inheritDoc}
     */
    protected function filterName(): string
    {
        return self::FILTER;
    }

    /**
     * {@inheritDoc}
     */
    protected function itemClass(): string
    {
        return Market::class;
    }

    /**
     * {@inheritDoc}
     */
    protected function fromDefinition(array $definition): object
    {
        return Market::fromArray($definition);
    }

    /**
     * {@inheritDoc}
     *
     * @param Market $item Item.
     */
    protected function keyOf(object $item): string
    {
        return $item->key;
    }
}
