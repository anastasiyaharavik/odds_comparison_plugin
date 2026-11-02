<?php

/**
 * Tests for SourceFactory.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Source;

use InvalidArgumentException;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Source\SourceFactory;
use PHPUnit\Framework\TestCase;

final class SourceFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        remove_all_filters(SourceFactory::FILTER);
    }

    protected function tearDown(): void
    {
        remove_all_filters(SourceFactory::FILTER);
    }

    public function testDummySourceRegisteredViaFilterIsResolved(): void
    {
        add_filter(SourceFactory::FILTER, static function (array $sources): array {
            $sources['dummy'] = DummySource::class;

            return $sources;
        });

        $factory = new SourceFactory([], ['dummy']);
        $source  = $factory->make('dummy');

        self::assertInstanceOf(DummySource::class, $source);
        self::assertSame($source, $factory->make('dummy'), 'instances are memoized');
        self::assertTrue($factory->has('dummy'));
        self::assertSame(['dummy'], $factory->ids());
    }

    public function testCallableDefinition(): void
    {
        $factory = new SourceFactory(['custom' => static fn (): SourceInterface => new DummySource('custom')]);

        self::assertSame('custom', $factory->make('custom')->id());
    }

    public function testUnknownIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SourceFactory())->make('nope');
    }

    public function testExceptionMessagesArePlainTextNotEscaped(): void
    {
        // Messages are escaped where they are shown (Status tab); escaping them here too turned
        // "Brighton & Hove" into "Brighton &amp;amp; Hove" on screen.
        try {
            (new SourceFactory())->make('brighton&hove');
            self::fail('Expected an exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unknown odds source "brighton&hove".', $exception->getMessage());
        }
    }

    public function testInvalidDefinitionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SourceFactory(['bad' => \stdClass::class]))->make('bad');
    }

    public function testIdMismatchThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SourceFactory(['alias' => static fn (): SourceInterface => new DummySource('real')]))->make('alias');
    }
}
