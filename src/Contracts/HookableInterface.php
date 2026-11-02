<?php

/**
 * Contract for services that attach themselves to WordPress hooks.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

defined('ABSPATH') || exit;

/**
 * A service that wires its own actions and filters.
 *
 * Hook registration is kept out of constructors so services can be instantiated
 * (e.g. in unit tests) without side effects. Plugin::boot() calls register() exactly once.
 */
interface HookableInterface
{
    /**
     * Attach the service's WordPress actions and filters.
     *
     * @return void
     */
    public function register(): void;
}
