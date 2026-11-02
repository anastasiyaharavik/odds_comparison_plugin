<?php

/**
 * Competition registry.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Registry;

use OddsComparison\Domain\Competition;

defined('ABSPATH') || exit;

/**
 * Competitions from `config/competitions.php` plus the `odds_comparison/competitions` filter, keyed by slug.
 *
 * @extends AbstractRegistry<Competition>
 */
final class CompetitionRegistry extends AbstractRegistry
{
    /** Filter that registers or modifies competitions. */
    public const FILTER = 'odds_comparison/competitions';

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
        return Competition::class;
    }

    /**
     * {@inheritDoc}
     */
    protected function fromDefinition(array $definition): object
    {
        return Competition::fromArray($definition);
    }

    /**
     * {@inheritDoc}
     *
     * @param Competition $item Item.
     */
    protected function keyOf(object $item): string
    {
        return $item->slug;
    }
}
