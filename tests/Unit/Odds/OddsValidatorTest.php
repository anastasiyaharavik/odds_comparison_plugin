<?php

/**
 * OddsValidator rules.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Odds;

use DateTimeImmutable;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Domain\Price;
use OddsComparison\Odds\OddsConverter;
use OddsComparison\Odds\OddsValidator;
use PHPUnit\Framework\TestCase;

final class OddsValidatorTest extends TestCase
{
    private OddsValidator $validator;

    private Market $match;

    private Market $outright;

    protected function setUp(): void
    {
        $this->validator = new OddsValidator();
        $this->match     = new Market('match-result', 'Match Result', [], Market::SELECTION_THREE_WAY);
        $this->outright  = new Market('outright-winner', 'Outright Winner', [], Market::SELECTION_OUTRIGHT);
    }

    /**
     * Match result with a realistic book (overround ≈ 1.06) per bookmaker, plus overrides.
     *
     * @param array<string, array<string, Price|null>> $overrides selection => [slug => Price|null (remove)].
     */
    private function matchOdds(array $overrides = []): MarketOdds
    {
        $book = static fn (): array => [
            'home' => new Price(1.83),
            'draw' => new Price(3.75),
            'away' => new Price(4.0),
        ];
        $selections = [
            'home' => ['label' => 'Chelsea', 'prices' => []],
            'draw' => ['label' => 'Draw', 'prices' => []],
            'away' => ['label' => 'Manchester United', 'prices' => []],
        ];

        foreach (['william-hill', 'betfred', 'sky-bet'] as $slug) {
            foreach ($book() as $key => $price) {
                $selections[$key]['prices'][$slug] = $price;
            }
        }

        foreach ($overrides as $key => $prices) {
            foreach ($prices as $slug => $price) {
                if ($price === null) {
                    unset($selections[$key]['prices'][$slug]);
                } else {
                    $selections[$key]['prices'][$slug] = $price;
                }
            }
        }

        return new MarketOdds(
            'bettingodds:premier-league/x',
            'match-result',
            'bettingodds',
            new DateTimeImmutable(),
            $selections
        );
    }

    public function testRealisticOddsPassUnchanged(): void
    {
        $odds   = $this->matchOdds();
        $result = $this->validator->validate($odds, $this->match);

        self::assertTrue($result->isAccepted());
        self::assertSame([], $result->notes);
        self::assertEquals($odds->selections, $result->odds?->selections);
    }

    public function testOutOfRangePricesAreDropped(): void
    {
        $result = $this->validator->validate(
            $this->matchOdds(['home' => ['betfred' => new Price(1.005)], 'away' => ['sky-bet' => new Price(1500.0)]]),
            $this->match
        );

        self::assertTrue($result->isAccepted());
        self::assertNull($result->odds?->price('home', 'betfred'));
        self::assertNull($result->odds?->price('away', 'sky-bet'));
        self::assertCount(2, array_filter($result->notes, static fn (string $n): bool => str_contains($n, 'outside')));
    }

    public function testOffMarketBookIsDroppedWhole(): void
    {
        // Overround 1/1.2 + 1/1.5 + 1/1.6 ≈ 2.12: a mis-parse, so all betfred prices go.
        $result = $this->validator->validate(
            $this->matchOdds([
                'home' => ['betfred' => new Price(1.2)],
                'draw' => ['betfred' => new Price(1.5)],
                'away' => ['betfred' => new Price(1.6)],
            ]),
            $this->match
        );

        self::assertTrue($result->isAccepted());
        self::assertNotContains('betfred', $result->odds?->bookmakerSlugs() ?? []);
        self::assertStringContainsString('overround', implode(' ', $result->notes));
    }

    public function testArbitrageInsideOneBookIsDroppedToo(): void
    {
        // Σ 1/d = 1/3 + 1/4 + 1/5 ≈ 0.78 < 0.98.
        $result = $this->validator->validate(
            $this->matchOdds([
                'home' => ['sky-bet' => new Price(3.0)],
                'draw' => ['sky-bet' => new Price(4.0)],
                'away' => ['sky-bet' => new Price(5.0)],
            ]),
            $this->match
        );

        self::assertNotContains('sky-bet', $result->odds?->bookmakerSlugs() ?? []);
    }

    public function testIncompleteBookIsKeptWithoutOverroundCheck(): void
    {
        $result = $this->validator->validate($this->matchOdds(['away' => ['betfred' => null]]), $this->match);

        self::assertTrue($result->isAccepted());
        self::assertNotNull($result->odds?->price('home', 'betfred'));
    }

    public function testFewerThanTwoBookmakersIsRejected(): void
    {
        $result = $this->validator->validate(
            $this->matchOdds([
                'home' => ['betfred' => null, 'sky-bet' => null],
                'draw' => ['betfred' => null, 'sky-bet' => null],
                'away' => ['betfred' => null, 'sky-bet' => null],
            ]),
            $this->match
        );

        self::assertFalse($result->isAccepted());
        self::assertStringContainsString('1 bookmaker', (string) $result->rejection);
    }

    public function testMissingMatchSelectionIsRejected(): void
    {
        $result = $this->validator->validate(
            $this->matchOdds(['draw' => ['william-hill' => null, 'betfred' => null, 'sky-bet' => null]]),
            $this->match
        );

        self::assertFalse($result->isAccepted());
        self::assertStringContainsString('2 of 3 selections', (string) $result->rejection);
    }

    public function testOutrightSkipsOverroundButNeedsTwoSelections(): void
    {
        // A relegation-style multi-place book: Σ 1/d far above 1.40 is normal here.
        $teams = [];
        foreach (['a' => 1.2, 'b' => 1.3, 'c' => 1.5, 'd' => 2.0] as $key => $decimal) {
            $teams[$key] = [
                'label'  => strtoupper($key),
                'prices' => ['betfred' => new Price($decimal), 'sky-bet' => new Price($decimal)],
            ];
        }

        $odds = new MarketOdds(
            'bettingodds:premier-league/outrights',
            'outright-winner',
            'bettingodds',
            new DateTimeImmutable(),
            $teams
        );
        self::assertTrue($this->validator->validate($odds, $this->outright)->isAccepted());

        $single = $odds->withSelections(['a' => $teams['a']]);
        self::assertFalse($this->validator->validate($single, $this->outright)->isAccepted());
    }
}
