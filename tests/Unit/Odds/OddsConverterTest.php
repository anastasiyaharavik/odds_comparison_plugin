<?php

/**
 * OddsConverter tests driven by tests/fixtures/odds.php.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Odds;

use InvalidArgumentException;
use OddsComparison\Domain\OddsFormat;
use OddsComparison\Domain\Price;
use OddsComparison\Odds\OddsConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OddsConverterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function cases(): array
    {
        return require __DIR__ . '/../../fixtures/odds.php';
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    private static function direction(string $name): iterable
    {
        foreach (self::cases()[$name] as [$input, $expected]) {
            yield var_export($input, true) => [$input, $expected];
        }
    }

    public static function fractionalToDecimal(): iterable
    {
        return self::direction('fractional_to_decimal');
    }

    public static function americanToDecimal(): iterable
    {
        return self::direction('american_to_decimal');
    }

    public static function decimalToFractional(): iterable
    {
        return self::direction('decimal_to_fractional');
    }

    public static function decimalToAmerican(): iterable
    {
        return self::direction('decimal_to_american');
    }

    /**
     * @return iterable<string, array{OddsFormat, mixed}>
     */
    public static function invalidInputs(): iterable
    {
        foreach (self::cases()['invalid'] as $format => $values) {
            foreach ($values as $value) {
                yield $format . ' ' . var_export($value, true) => [OddsFormat::from($format), $value];
            }
        }
    }

    public function testEveryDirectionHasAtLeastTwentyCases(): void
    {
        $directions = ['fractional_to_decimal', 'american_to_decimal', 'decimal_to_fractional', 'decimal_to_american'];

        foreach ($directions as $name) {
            self::assertGreaterThanOrEqual(20, count(self::cases()[$name]), $name);
        }
    }

    #[DataProvider('fractionalToDecimal')]
    public function testFractionalToDecimal(string $input, float $expected): void
    {
        $actual = (new OddsConverter())->toDecimal($input, OddsFormat::Fractional);

        self::assertEqualsWithDelta($expected, $actual, 0.000001);
    }

    #[DataProvider('americanToDecimal')]
    public function testAmericanToDecimal(string|int|float $input, float $expected): void
    {
        $actual = (new OddsConverter())->toDecimal($input, OddsFormat::American);

        self::assertEqualsWithDelta($expected, $actual, 0.000001);
    }

    #[DataProvider('decimalToFractional')]
    public function testDecimalToFractional(float $input, string $expected): void
    {
        self::assertSame($expected, (new OddsConverter())->toFractional($input));
    }

    #[DataProvider('decimalToAmerican')]
    public function testDecimalToAmerican(float $input, string $expected): void
    {
        self::assertSame($expected, (new OddsConverter())->toAmerican($input));
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputThrows(OddsFormat $format, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OddsConverter())->toDecimal($value, $format);
    }

    public function testInvalidDecimalForOutputConversionsThrows(): void
    {
        $converter = new OddsConverter();

        foreach ([1.0, 0.5, NAN] as $value) {
            try {
                $converter->toFractional($value);
                self::fail('toFractional accepted ' . var_export($value, true));
            } catch (InvalidArgumentException) {
            }

            try {
                $converter->toAmerican($value);
                self::fail('toAmerican accepted ' . var_export($value, true));
            } catch (InvalidArgumentException) {
            }
        }

        self::addToAssertionCount(1);
    }

    public function testFractionsAreExactForTheDecimalPrice(): void
    {
        $converter = new OddsConverter();
        $fraction  = static fn (float $decimal): string
            => $converter->format(new Price($decimal), OddsFormat::Fractional);

        // Recorded BettingOdds.com prices: the page showed 14/5, 3/1 and 4/1 next to these decimals.
        self::assertSame('29/10', $fraction(3.9));
        self::assertSame('31/10', $fraction(4.1));
        self::assertSame('22/5', $fraction(5.4));
        self::assertNotSame($fraction(4.0), $fraction(4.03), 'different prices never look the same');
        // A decimal that is a standard fraction rounded to two places shows that fraction.
        self::assertSame('10/11', $fraction(1.91));
        self::assertSame('4/6', $fraction(1.67));
        self::assertSame('13/8', $fraction(2.62));
        // One instance remembers fractions per decimal; close decimals keep their own result.
        self::assertSame(['10/11', '9/10', '10/11'], [$fraction(1.91), $fraction(1.9), $fraction(1.91)]);
    }

    public function testFormatRendersEveryFormatFromTheDecimal(): void
    {
        $converter = new OddsConverter();

        self::assertSame('5/8', $converter->format(new Price(1.625), OddsFormat::Fractional));
        self::assertSame('5/2', $converter->format(new Price(3.5), OddsFormat::Fractional));
        self::assertSame('3.50', $converter->format(new Price(3.5), OddsFormat::Decimal));
        self::assertSame('1.91', $converter->format(new Price(1.909091), OddsFormat::Decimal));
        self::assertSame('+250', $converter->format(new Price(3.5), OddsFormat::American));
    }

    public function testRoundTripsOverTheWholeLadder(): void
    {
        $converter = new OddsConverter();

        foreach (['1/10', '4/6', '10/11', '1/1', '6/4', '85/40', '11/4', '100/1'] as $fraction) {
            $decimal = $converter->toDecimal($fraction, OddsFormat::Fractional);

            self::assertSame($fraction, $converter->toFractional($decimal), $fraction);
            self::assertEqualsWithDelta(
                $decimal,
                $converter->toDecimal($converter->toAmerican($decimal), OddsFormat::American),
                0.01,
                $fraction
            );
        }
    }

    public function testNoTwoTwoPlacePricesShareAFraction(): void
    {
        $converter = new OddsConverter();

        self::assertSame('85/40', $converter->toFractional(3.12));
        self::assertSame('213/100', $converter->toFractional(3.13), 'the boundary belongs to one side only');
        self::assertSame('13/8', $converter->toFractional(2.62));
        self::assertNotSame($converter->toFractional(2.62), $converter->toFractional(2.63));

        $seen   = [];
        $shared = [];

        for ($cents = 101; $cents <= 10000; $cents++) {
            $fraction = $converter->toFractional($cents / 100);

            if (isset($seen[$fraction])) {
                $shared[] = sprintf('%.2f and %.2f are both %s', $seen[$fraction], $cents / 100, $fraction);
            }

            $seen[$fraction] = $cents / 100;
        }

        self::assertSame([], $shared);
    }

    public function testFractionalAndAmericanAgree(): void
    {
        $converter = new OddsConverter();

        foreach ([[1.995, '1/1', '+100'], [1.17, '1/6', '-600'], [3.12, '85/40', '+213']] as [$decimal, $frac, $us]) {
            self::assertSame(
                [$frac, $us],
                [$converter->toFractional($decimal), $converter->toAmerican($decimal)],
                (string) $decimal
            );
        }
    }
}
