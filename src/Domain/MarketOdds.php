<?php

/**
 * Odds snapshot for one event market.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * All bookmakers' prices for every selection of one event market, as fetched at one moment.
 *
 * Freshness is decided from `fetchedAt`, never from cache expiry.
 *
 * Shape of `selections` (insertion order = display order):
 *
 *     [
 *         'home' => ['label' => 'Arsenal', 'prices' => ['bet365' => Price, 'william-hill' => Price]],
 *         'draw' => ['label' => 'Draw',    'prices' => [...]],
 *     ]
 *
 * @phpstan-type Selection array{label: string, prices: array<string, Price>}
 */
final class MarketOdds
{
    /** Fetch time, always UTC. */
    public readonly DateTimeImmutable $fetchedAt;

    /**
     * @param string                    $eventId    Source-scoped event id.
     * @param string                    $marketKey  Market key.
     * @param string                    $sourceId   Source the odds came from.
     * @param DateTimeImmutable         $fetchedAt  Fetch time (converted to UTC).
     * @param array<string, Selection>  $selections Selection key => label and per-bookmaker prices.
     * @throws InvalidArgumentException When any field is invalid.
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $marketKey,
        public readonly string $sourceId,
        DateTimeImmutable $fetchedAt,
        public readonly array $selections
    ) {
        Assert::notBlank($eventId, 'MarketOdds eventId');
        Assert::slug($marketKey, 'MarketOdds marketKey');
        Assert::slug($sourceId, 'MarketOdds sourceId');
        self::assertSelections($selections);

        $this->fetchedAt = $fetchedAt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Rebuild from {@see self::toArray()} output (repository storage).
     *
     * @param array<string, mixed> $data Stored data.
     * @return self
     * @throws InvalidArgumentException When the data is malformed.
     */
    public static function fromArray(array $data): self
    {
        $selections = [];

        foreach (Field::array($data, 'selections') as $key => $selection) {
            if (!is_array($selection)) {
                throw new InvalidArgumentException('Each selection must be an array.');
            }

            $prices = [];

            foreach (Field::array($selection, 'prices') as $slug => $price) {
                if (!is_array($price)) {
                    throw new InvalidArgumentException('Each price must be an array.');
                }

                $prices[$slug] = Price::fromArray($price);
            }

            $selections[$key] = ['label' => Field::string($selection, 'label'), 'prices' => $prices];
        }

        return new self(
            Field::string($data, 'eventId'),
            Field::string($data, 'marketKey'),
            Field::string($data, 'sourceId'),
            new DateTimeImmutable('@' . Field::int($data, 'fetchedAt')),
            $selections
        );
    }

    /**
     * Plain-array form for storage (`fetchedAt` as a Unix timestamp).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $selections = [];

        foreach ($this->selections as $key => $selection) {
            $selections[$key] = [
                'label'  => $selection['label'],
                'prices' => array_map(static fn (Price $price): array => $price->toArray(), $selection['prices']),
            ];
        }

        return [
            'eventId'    => $this->eventId,
            'marketKey'  => $this->marketKey,
            'sourceId'   => $this->sourceId,
            'fetchedAt'  => $this->fetchedAt->getTimestamp(),
            'selections' => $selections,
        ];
    }

    /**
     * Copy with different selections (used by the validator to drop prices immutably).
     *
     * @param array<string, Selection> $selections New selections.
     * @return self
     * @throws InvalidArgumentException When the selections are invalid.
     */
    public function withSelections(array $selections): self
    {
        return new self($this->eventId, $this->marketKey, $this->sourceId, $this->fetchedAt, $selections);
    }

    /**
     * Selection keys in display order.
     *
     * @return list<string>
     */
    public function selectionKeys(): array
    {
        return array_keys($this->selections);
    }

    /**
     * Every bookmaker slug that has at least one price, in first-seen order.
     *
     * @return list<string>
     */
    public function bookmakerSlugs(): array
    {
        $slugs = [];

        foreach ($this->selections as $selection) {
            foreach (array_keys($selection['prices']) as $slug) {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * One bookmaker's price for one selection, or null when not offered.
     *
     * @param string $selectionKey  Selection key.
     * @param string $bookmakerSlug Bookmaker slug.
     * @return Price|null
     */
    public function price(string $selectionKey, string $bookmakerSlug): ?Price
    {
        return $this->selections[$selectionKey]['prices'][$bookmakerSlug] ?? null;
    }

    /**
     * Validate the selections structure.
     *
     * @param array<mixed, mixed> $selections Selections to check.
     * @return void
     * @throws InvalidArgumentException When the structure is invalid.
     */
    private static function assertSelections(array $selections): void
    {
        foreach ($selections as $key => $selection) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Selection keys must be strings.');
            }

            Assert::slug($key, 'Selection key');

            if (!is_array($selection) || !isset($selection['label'], $selection['prices'])) {
                throw new InvalidArgumentException(
                    sprintf('Selection %s needs a label and prices.', json_encode($key, Assert::JSON))
                );
            }

            if (!is_string($selection['label']) || !is_array($selection['prices'])) {
                throw new InvalidArgumentException(
                    sprintf('Selection %s has an invalid label or prices.', json_encode($key, Assert::JSON))
                );
            }

            Assert::notBlank($selection['label'], 'Selection label');

            foreach ($selection['prices'] as $slug => $price) {
                if (!is_string($slug) || !$price instanceof Price) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Selection %s prices must map bookmaker slugs to Price.',
                            json_encode($key, Assert::JSON)
                        )
                    );
                }

                Assert::slug($slug, 'Bookmaker slug');
            }
        }
    }
}
