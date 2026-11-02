<?php

/**
 * Minimal SourceInterface implementation for factory tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Source;

use DateTimeImmutable;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\MarketOdds;

/**
 * Minimal source used to exercise the factory.
 */
final class DummySource implements SourceInterface
{
    public function __construct(private readonly string $id = 'dummy')
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function baseUrl(): string
    {
        return 'https://dummy.example';
    }

    public function discoverEvents(Competition $competition): array
    {
        return [];
    }

    public function fetchMarket(Event $event, Market $market): MarketOdds
    {
        return new MarketOdds($event->id, $market->key, $this->id, new DateTimeImmutable(), []);
    }
}
