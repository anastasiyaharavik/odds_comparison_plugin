<?php

/**
 * Base class for config + filter backed registries.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Registry;

use InvalidArgumentException;
use OddsComparison\Domain\Assert;
use OddsComparison\Plugin;

defined('ABSPATH') || exit;

/**
 * Registry pattern: default definitions from a `config/*.php` file, passed through a filter,
 * turned into typed value objects and looked up by key.
 *
 * Loading is lazy (first lookup), so it happens after other plugins have added their filters.
 * The result is memoized for the rest of the request. A filter may return arrays or ready-made
 * value objects. Invalid entries are skipped with a `_doing_it_wrong()` notice instead of
 * breaking the site. A later entry with the same key replaces an earlier one, so a filter
 * can override defaults.
 *
 * @template T of object
 */
abstract class AbstractRegistry
{
    /**
     * Loaded items keyed by their registry key, or null before the first lookup.
     *
     * @var array<string, T>|null
     */
    private ?array $items = null;

    /**
     * @param string $configFile Absolute path of the PHP file that returns the default definitions.
     */
    public function __construct(private readonly string $configFile)
    {
    }

    /**
     * Filter hook that receives the definitions list.
     *
     * @return string
     */
    abstract protected function filterName(): string;

    /**
     * Value-object class this registry holds.
     *
     * @return class-string<T>
     */
    abstract protected function itemClass(): string;

    /**
     * Build a value object from an array definition.
     *
     * @param array<string, mixed> $definition Definition array.
     * @return T
     * @throws InvalidArgumentException When the definition is invalid.
     */
    abstract protected function fromDefinition(array $definition): object;

    /**
     * Registry key of an item.
     *
     * @param T $item Item.
     * @return string
     */
    abstract protected function keyOf(object $item): string;

    /**
     * All items keyed by registry key, in definition order.
     *
     * @return array<string, T>
     */
    public function all(): array
    {
        return $this->items ??= $this->load();
    }

    /**
     * One item by key, or null when unknown.
     *
     * @param string $key Registry key.
     * @return T|null
     */
    public function get(string $key): ?object
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Whether a key is registered.
     *
     * @param string $key Registry key.
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * All registered keys, in definition order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Items for the given keys in the given order; unknown keys are skipped.
     *
     * Used to intersect admin/block selections with what is actually registered.
     *
     * @param array<mixed> $keys Requested keys.
     * @return array<string, T>
     */
    public function only(array $keys): array
    {
        $all   = $this->all();
        $items = [];

        foreach ($keys as $key) {
            if (is_string($key) && isset($all[$key])) {
                $items[$key] = $all[$key];
            }
        }

        return $items;
    }

    /**
     * Load defaults, apply the filter and build the items.
     *
     * @return array<string, T>
     */
    private function load(): array
    {
        $defaults = $this->defaults();

        /**
         * Filters the registry definitions (bookmakers, markets or competitions).
         *
         * @param array<int|string, array<string, mixed>|object> $definitions Definition arrays or value objects.
         */
        $definitions = apply_filters($this->filterName(), $defaults);

        if (!is_array($definitions)) {
            $this->reportInvalid(sprintf('The "%s" filter must return an array.', $this->filterName()));
            $definitions = $defaults;
        }

        $items = [];

        foreach ($definitions as $definition) {
            try {
                $item = $this->toItem($definition);
            } catch (InvalidArgumentException $exception) {
                $this->reportInvalid($exception->getMessage());
                continue;
            }

            $items[$this->keyOf($item)] = $item;
        }

        return $items;
    }

    /**
     * Default definitions from the config file.
     *
     * @return array<int|string, mixed>
     */
    private function defaults(): array
    {
        if (!is_readable($this->configFile)) {
            return [];
        }

        $definitions = require $this->configFile;

        return is_array($definitions) ? $definitions : [];
    }

    /**
     * Turn one definition into a value object.
     *
     * @param mixed $definition Array definition or value object.
     * @return T
     * @throws InvalidArgumentException When the definition is invalid.
     */
    private function toItem(mixed $definition): object
    {
        $class = $this->itemClass();

        if ($definition instanceof $class) {
            return $definition;
        }

        if (!is_array($definition)) {
            throw new InvalidArgumentException(sprintf(
                'Entries of %s must be arrays or %s objects.',
                wp_json_encode($this->filterName(), Assert::JSON),
                wp_json_encode($class, Assert::JSON)
            ));
        }

        return $this->fromDefinition($definition);
    }

    /**
     * Tell developers about a bad registry entry (shown only with WP_DEBUG).
     *
     * @param string $message Problem description.
     * @return void
     */
    private function reportInvalid(string $message): void
    {
        _doing_it_wrong(esc_html(static::class . '::all'), esc_html($message), esc_html(Plugin::VERSION));
    }
}
