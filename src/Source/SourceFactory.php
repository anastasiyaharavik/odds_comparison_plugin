<?php

/**
 * Factory for odds source adapters.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Source;

use InvalidArgumentException;
use OddsComparison\Contracts\SourceInterface;

defined('ABSPATH') || exit;

/**
 * Factory pattern: resolves a source id to a SourceInterface instance.
 *
 * The id => definition map comes from the built-in defaults passed through the
 * `odds_comparison/sources` filter. A definition is either:
 *
 * - a class-string implementing SourceInterface, constructed with the shared
 *   `$constructorArgs` (the AbstractSource constructor contract), or
 * - a callable that takes no arguments and returns a SourceInterface, for sources with
 *   their own dependencies.
 *
 * Instances are created once per request.
 */
final class SourceFactory
{
    /** Filter that registers source adapters (id => class-string|callable). */
    public const FILTER = 'odds_comparison/sources';

    /**
     * Filtered definitions, or null before the first lookup.
     *
     * @var array<mixed, mixed>|null
     */
    private ?array $definitions = null;

    /**
     * Built instances by id.
     *
     * @var array<string, SourceInterface>
     */
    private array $instances = [];

    /**
     * @param array<string, class-string<SourceInterface>|callable(): SourceInterface> $defaults
     *        Built-in sources.
     * @param list<mixed> $constructorArgs Arguments for class-string sources.
     */
    public function __construct(
        private readonly array $defaults = [],
        private readonly array $constructorArgs = []
    ) {
    }

    /**
     * Get the source for an id.
     *
     * @param string $id Source id.
     * @return SourceInterface
     * @throws InvalidArgumentException When the id is unknown or its definition does not produce a matching source.
     */
    public function make(string $id): SourceInterface
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $definitions = $this->definitions();

        if (!array_key_exists($id, $definitions)) {
            throw new InvalidArgumentException(sprintf('Unknown odds source "%s".', $id));
        }

        $source = $this->build($definitions[$id]);

        if ($source->id() !== $id) {
            throw new InvalidArgumentException(
                sprintf('Source registered as "%s" reports id "%s".', $id, $source->id())
            );
        }

        return $this->instances[$id] = $source;
    }

    /**
     * Whether a source id is registered.
     *
     * @param string $id Source id.
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->definitions());
    }

    /**
     * Registered source ids.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_filter(array_keys($this->definitions()), 'is_string'));
    }

    /**
     * Filtered definitions (memoized).
     *
     * @return array<mixed, mixed>
     */
    private function definitions(): array
    {
        if ($this->definitions === null) {
            /**
             * Filters the registered odds sources.
             *
             * @param array<string, class-string|callable> $sources Source id => class-string or factory callable.
             */
            $definitions       = apply_filters(self::FILTER, $this->defaults);
            $this->definitions = is_array($definitions) ? $definitions : $this->defaults;
        }

        return $this->definitions;
    }

    /**
     * Instantiate one definition.
     *
     * @param mixed $definition Class-string or callable.
     * @return SourceInterface
     * @throws InvalidArgumentException When the definition is invalid.
     */
    private function build(mixed $definition): SourceInterface
    {
        if (is_string($definition) && is_subclass_of($definition, SourceInterface::class)) {
            return new $definition(...$this->constructorArgs);
        }

        if (is_callable($definition)) {
            $source = $definition();

            if ($source instanceof SourceInterface) {
                return $source;
            }
        }

        throw new InvalidArgumentException(
            'Source definitions must be a SourceInterface class name or a callable returning a SourceInterface.'
        );
    }
}
