<?php

/**
 * Bookmaker registry.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Registry;

use OddsComparison\Domain\Bookmaker;

defined('ABSPATH') || exit;

/**
 * Bookmakers from `config/bookmakers.php` plus the `odds_comparison/bookmakers` filter, keyed by slug.
 *
 * @extends AbstractRegistry<Bookmaker>
 */
final class BookmakerRegistry extends AbstractRegistry
{
    /** Filter that registers or modifies bookmakers. */
    public const FILTER = 'odds_comparison/bookmakers';

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
        return Bookmaker::class;
    }

    /**
     * {@inheritDoc}
     */
    protected function fromDefinition(array $definition): object
    {
        return Bookmaker::fromArray($definition);
    }

    /**
     * {@inheritDoc}
     *
     * @param Bookmaker $item Item.
     */
    protected function keyOf(object $item): string
    {
        return $item->slug;
    }
}
