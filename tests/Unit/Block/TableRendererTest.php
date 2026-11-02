<?php

/**
 * TableRenderer view model: validation rules, fallback states and table data.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Block;

use DateTimeImmutable;
use OddsComparison\Block\TableRenderer;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Domain\Price;
use OddsComparison\Odds\OddsConverter;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Settings\Settings;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\OddsRepository;
use OddsComparison\Support\OptionKeys;
use PHPUnit\Framework\TestCase;

final class TableRendererTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    private const NOW = '2026-10-30T12:00:00Z';

    private const MATCH = 'bettingodds:premier-league/m1';

    private const OUTRIGHTS = 'bettingodds:premier-league/outrights';

    private OddsRepository $repository;

    protected function setUp(): void
    {
        $GLOBALS['odds_comparison_test_transients'] = [];
        $GLOBALS['odds_comparison_test_options']    = [];
        $GLOBALS['odds_comparison_test_cache']      = [];
        remove_all_filters('odds_comparison/bookmaker_link');
        remove_all_filters('odds_comparison/disclaimer');

        update_option(OptionKeys::SETTINGS, [
            'enabled_markets'     => ['match-result', 'outright-winner'],
            'default_odds_format' => 'decimal',
            'bookmakers'          => [
                'betfred'      => ['enabled' => false],
                'william-hill' => ['enabled' => true, 'link' => 'https://aff.example/wh', 'label' => 'WH'],
            ],
        ]);

        $now = new DateTimeImmutable(self::NOW);
        (new EventIndex())->replace('bettingodds', 'premier-league', [
            Event::match(
                'bettingodds',
                'premier-league/m1',
                'premier-league',
                'Chelsea vs Manchester United',
                'Chelsea',
                'Manchester United',
                $now->modify('+1 day'),
                '/football/chelsea-man-utd'
            ),
            Event::outrights('bettingodds', 'premier-league', 'Premier League Outrights', '/football/premier-league'),
        ], $now);

        $this->repository = new OddsRepository(
            static fn (): int => 60,
            static fn (): int => (new DateTimeImmutable(self::NOW))->getTimestamp()
        );
    }

    private function renderer(): TableRenderer
    {
        return new TableRenderer(
            new Settings(),
            new BookmakerRegistry(self::ROOT . 'config/bookmakers.php'),
            new MarketRegistry(self::ROOT . 'config/markets.php'),
            new CompetitionRegistry(self::ROOT . 'config/competitions.php'),
            new EventIndex(),
            $this->repository,
            new OddsConverter(),
            self::ROOT . 'templates',
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW)
        );
    }

    private function storeMatchOdds(int $ageMinutes = 5): void
    {
        $price = static fn (float $decimal): Price => new Price($decimal);

        $this->repository->save(new MarketOdds(
            self::MATCH,
            'match-result',
            'bettingodds',
            (new DateTimeImmutable(self::NOW))->modify("-{$ageMinutes} minutes"),
            [
                'home' => ['label' => 'Chelsea', 'prices' => [
                    'william-hill' => $price(1.83),
                    'paddy-power'  => $price(1.8),
                    'betfred'      => $price(2.5), // Disabled in settings: must not win "best".
                ]],
                'draw' => ['label' => 'Draw', 'prices' => [
                    'william-hill' => $price(3.75),
                    'paddy-power'  => $price(3.75),
                ]],
                'away' => ['label' => 'Manchester United', 'prices' => [
                    'paddy-power' => $price(4.0),
                ]],
            ]
        ));
    }

    /**
     * @param array<string, mixed> $overrides Attribute overrides.
     * @return array<string, mixed>
     */
    private static function attributes(array $overrides = []): array
    {
        return $overrides + [
            'eventId'    => self::MATCH,
            'marketKey'  => 'match-result',
            'bookmakers' => ['paddy-power', 'william-hill', 'betfred', 'unknown'],
        ];
    }

    public function testFallbackStates(): void
    {
        $renderer = $this->renderer();

        self::assertSame(['state' => 'empty', 'message' => ''], $renderer->viewModel(['eventId' => '']));
        self::assertNotSame('', $renderer->viewModel(['eventId' => ''], true)['message'], 'preview explains');
        self::assertSame('empty', $renderer->viewModel(self::attributes(['eventId' => 'bettingodds:gone/1']))['state']);
        self::assertSame(
            'empty',
            $renderer->viewModel(self::attributes(['bookmakers' => ['betfred']]))['state'],
            'only disabled bookmakers = none'
        );
        self::assertSame(
            'unavailable',
            $renderer->viewModel(self::attributes(['marketKey' => 'relegation']))['state'],
            'market not enabled'
        );
        self::assertSame(
            'unavailable',
            $renderer->viewModel(self::attributes(['marketKey' => 'outright-winner']))['state'],
            'outright market on a match'
        );
        self::assertSame('unavailable', $renderer->viewModel(self::attributes())['state'], 'no stored odds');
        self::assertSame('fetching', $renderer->viewModel(self::attributes(), true)['state']);
    }

    public function testStartedMatchShowsNoPrices(): void
    {
        $this->storeMatchOdds();
        (new EventIndex())->replace('bettingodds', 'premier-league', [
            Event::match(
                'bettingodds',
                'premier-league/m1',
                'premier-league',
                'Chelsea vs Manchester United',
                'Chelsea',
                'Manchester United',
                new DateTimeImmutable('2026-10-30T11:00:00Z'),
                '/football/chelsea-man-utd'
            ),
        ], new DateTimeImmutable('2026-10-30T10:00:00Z'));

        $model = $this->renderer()->viewModel(self::attributes());

        self::assertSame('started', $model['state']);
        self::assertArrayNotHasKey('rows', $model);
    }

    public function testMatchTable(): void
    {
        $this->storeMatchOdds();
        add_filter(
            'odds_comparison/bookmaker_link',
            static fn (string $link, $bookmaker): string => add_utm($link, $bookmaker->slug),
            10,
            2
        );

        $model = $this->renderer()->viewModel(self::attributes());

        self::assertSame('table', $model['state']);
        self::assertSame('bookmaker-rows', $model['layout']);
        self::assertSame('Chelsea vs Manchester United — Match Result', $model['caption']);
        self::assertSame('decimal', $model['format'], 'inherit resolves to the site default');
        self::assertSame(['home', 'draw', 'away'], array_column($model['columns'], 'key'));
        self::assertSame(
            ['paddy-power', 'william-hill'],
            array_column($model['rows'], 'key'),
            'block order, disabled and unknown removed'
        );
        self::assertSame('WH', $model['rows'][1]['label'], 'admin label override');

        [$pp, $wh] = $model['rows'];
        self::assertTrue(
            $wh['cells'][0]['best'],
            'best home price among displayed bookmakers (1.83, not betfred 2.50)'
        );
        self::assertFalse($pp['cells'][0]['best']);
        self::assertTrue($pp['cells'][1]['best'] && $wh['cells'][1]['best'], 'ties are all best');
        self::assertSame(['price' => false, 'column' => 'Manchester United'], $wh['cells'][2], 'missing price');
        self::assertSame(
            ['1.83', '5/6', '-120', '1.83'],
            [$wh['cells'][0]['dec'], $wh['cells'][0]['frac'], $wh['cells'][0]['us'], $wh['cells'][0]['text']]
        );
        self::assertSame(
            'https://aff.example/wh?utm=william-hill',
            $wh['cells'][0]['link'],
            'saved affiliate link through the filter'
        );
        self::assertSame(
            'https://www.paddypower.com/?utm=paddy-power',
            $pp['link'],
            'default URL when no affiliate link'
        );
        self::assertSame('Updated 11:55', $model['updated'], 'fetch time, not "5 minutes ago"');
        self::assertFalse($model['stale']);
        self::assertSame('18+ | Odds subject to change. Gamble responsibly.', $model['disclaimer']);

        self::assertSame(['Chelsea', 'Manchester United'], [$model['home'], $model['away']]);
        self::assertSame('Premier League · Match Result', $model['subtitle']);
        self::assertSame(2, $model['bookmakerCount']);
        self::assertSame(
            [['Chelsea', 'WH', '1.83'], ['Draw', 'Paddy Power', '3.75'], ['Manchester United', 'Paddy Power', '4.00']],
            array_map(
                static fn (array $offer): array => [$offer['selection'], $offer['bookmaker'], $offer['cell']['dec']],
                $model['bestOffers']
            ),
            'best price per selection; the first displayed bookmaker wins a tie'
        );
    }

    public function testFilteredLinksMustBeHttp(): void
    {
        $this->storeMatchOdds();
        add_filter('odds_comparison/bookmaker_link', static fn (): string => 'javascript:alert(1)');

        $model = $this->renderer()->viewModel(self::attributes());

        self::assertSame([''], array_values(array_unique(array_column($model['rows'], 'link'))));
    }

    public function testOptionsSelectionsFormatAndStaleness(): void
    {
        $this->storeMatchOdds(90);

        $model = $this->renderer()->viewModel(self::attributes([
            'selections'    => ['away', 'home'],
            'oddsFormat'    => 'fractional',
            'highlightBest' => false,
            'caption'       => 'Chelsea to win?',
        ]));

        self::assertSame(['home', 'away'], array_column($model['columns'], 'key'), 'filter keeps stored order');
        self::assertSame('5/6', $model['rows'][1]['cells'][0]['text']);
        self::assertFalse($model['rows'][1]['cells'][0]['best'], 'highlighting off');
        self::assertSame('Chelsea to win?', $model['caption']);
        self::assertSame('Chelsea to win?', $model['subtitle'], 'custom caption replaces competition · market');
        self::assertSame([], $model['bestOffers'], 'no best-price summary when highlighting is off');
        self::assertTrue($model['stale'], '90 minutes > stale_after 60');
        self::assertSame('Updated 10:30', $model['updated']);
    }

    public function testCacheKeyIgnoresTheClockButFollowsLinkFilters(): void
    {
        $this->storeMatchOdds();
        $now      = new DateTimeImmutable(self::NOW);
        $renderer = fn (DateTimeImmutable $at): TableRenderer => new TableRenderer(
            new Settings(),
            new BookmakerRegistry(self::ROOT . 'config/bookmakers.php'),
            new MarketRegistry(self::ROOT . 'config/markets.php'),
            new CompetitionRegistry(self::ROOT . 'config/competitions.php'),
            new EventIndex(),
            $this->repository,
            new OddsConverter(),
            self::ROOT . 'templates',
            static fn (): DateTimeImmutable => $at
        );

        $first = $renderer($now)->render(self::attributes());
        $GLOBALS['odds_comparison_test_cache']['odds_comparison'] = array_map(
            static fn (string $html): string => $html . '<!-- cached -->',
            $GLOBALS['odds_comparison_test_cache']['odds_comparison'] ?? []
        );

        // Three minutes later nothing visible changed: the cached HTML is served.
        self::assertSame($first . '<!-- cached -->', $renderer($now->modify('+3 minutes'))->render(self::attributes()));

        // A link filter added later changes the output, so the cache is bypassed.
        add_filter(
            'odds_comparison/bookmaker_link',
            static fn (string $link): string => $link . '?ref=new',
            10,
            1
        );
        $filtered = $renderer($now->modify('+3 minutes'))->render(self::attributes());
        self::assertStringNotContainsString('<!-- cached -->', $filtered);
        self::assertStringContainsString('?ref=new', $filtered);
    }

    public function testOutrightTableIsTransposedWithFavouritesFirst(): void
    {
        $teams = [];

        foreach (range(1, 14) as $rank) {
            $teams['team-' . $rank] = ['label' => 'Team ' . $rank, 'prices' => [
                'william-hill' => new Price(1.5 + $rank),
                'paddy-power'  => new Price(1.4 + $rank),
            ]];
        }

        $teams = array_reverse($teams, true); // Stored order differs from favourite order.
        $now = new DateTimeImmutable(self::NOW);
        $this->repository->save(new MarketOdds(self::OUTRIGHTS, 'outright-winner', 'bettingodds', $now, $teams));

        $outright = ['eventId' => self::OUTRIGHTS, 'marketKey' => 'outright-winner'];
        $model    = $this->renderer()->viewModel(self::attributes($outright));

        self::assertSame('selection-rows', $model['layout']);
        self::assertSame(['paddy-power', 'william-hill'], array_column($model['columns'], 'key'));
        self::assertSame('team-1', $model['rows'][0]['key'], 'shortest price first');
        self::assertSame(TableRenderer::DEFAULT_OUTRIGHT_ROWS, $model['maxRows']);
        self::assertCount(TableRenderer::DEFAULT_OUTRIGHT_ROWS, $model['rows'], 'rows past the limit are not built');
        self::assertSame('team-10', $model['rows'][9]['key']);
        self::assertTrue($model['rows'][0]['cells'][1]['best'], 'William Hill pays more for Team 1');
        self::assertCount(TableRenderer::DEFAULT_OUTRIGHT_ROWS, $model['bestOffers'], 'summary of the shown rows');
        self::assertSame(['Team 1', 'WH'], [
            $model['bestOffers'][0]['selection'],
            $model['bestOffers'][0]['bookmaker'],
        ]);

        $picked = $this->renderer()->viewModel(self::attributes([
            'eventId'    => self::OUTRIGHTS,
            'marketKey'  => 'outright-winner',
            'selections' => ['team-14'],
        ]));
        self::assertSame(['team-14'], array_column($picked['rows'], 'key'));
        self::assertSame(1, $picked['maxRows']);
    }

    public function testBookmakerNamesLinkAndPricesDoNot(): void
    {
        $this->storeMatchOdds();
        $html = $this->renderer()->render(self::attributes());

        // Row headers: the saved affiliate link for William Hill (label "WH"), the website otherwise.
        self::assertMatchesRegularExpression(
            '#<a class="oc-odds__bookmaker" href="https://aff\.example/wh" rel="nofollow sponsored noopener"'
            . ' target="_blank">WH#',
            $html
        );
        self::assertStringContainsString(
            '<a class="oc-odds__bookmaker" href="https://www.paddypower.com/" rel="nofollow sponsored noopener"',
            $html
        );
        self::assertSame(0, preg_match('#<a [^>]*class="oc-odds__price#', $html), 'price chips are not links');
        self::assertGreaterThan(0, preg_match_all('#<span class="oc-odds__price#', $html));

        // Best-offer summary: "at <bookmaker link>".
        self::assertMatchesRegularExpression('#oc-odds__offer-at">\s*at <a class="oc-odds__bookmaker"#', $html);
        self::assertStringContainsString('(opens in a new tab)', $html);
    }

    public function testDemoDataIsMarkedAndLiveDataIsNot(): void
    {
        $this->storeMatchOdds();
        $demo = $this->renderer()->render(self::attributes());

        self::assertTrue($this->renderer()->viewModel(self::attributes())['demo'], 'new installs show demo data');
        self::assertStringContainsString('<span class="oc-odds__badge">Demo data</span>', $demo);

        update_option(
            OptionKeys::SETTINGS,
            ['data_mode' => 'live'] + (array) get_option(OptionKeys::SETTINGS, [])
        );
        $GLOBALS['odds_comparison_test_cache'] = [];
        $live = $this->renderer()->render(self::attributes());

        self::assertStringNotContainsString('oc-odds__badge', $live);
    }

    public function testOutrightBookmakerColumnHeadersLink(): void
    {
        $teams = [];

        foreach (range(1, 3) as $rank) {
            $teams['team-' . $rank] = ['label' => 'Team ' . $rank, 'prices' => [
                'william-hill' => new Price(1.5 + $rank),
                'paddy-power'  => new Price(1.4 + $rank),
            ]];
        }

        $now = new DateTimeImmutable(self::NOW);
        $this->repository->save(new MarketOdds(self::OUTRIGHTS, 'outright-winner', 'bettingodds', $now, $teams));

        $html = $this->renderer()->render(
            self::attributes(['eventId' => self::OUTRIGHTS, 'marketKey' => 'outright-winner'])
        );

        self::assertMatchesRegularExpression(
            '#<th scope="col"><a class="oc-odds__bookmaker" href="https://aff.example/wh"#',
            $html
        );
        self::assertMatchesRegularExpression(
            '#<th scope="row">\s*Team 1\s*</th>#',
            $html,
            'selection names are not links'
        );
    }

    public function testLinkFilterReturningNothingLeavesThePlainName(): void
    {
        add_filter('odds_comparison/bookmaker_link', static fn (): string => '');
        $this->storeMatchOdds();

        $html = $this->renderer()->render(self::attributes());

        self::assertStringNotContainsString('oc-odds__bookmaker', $html);
        self::assertMatchesRegularExpression('#<th scope="row">\s*Paddy Power\s*</th>#', $html);
    }
}

/**
 * Append a utm query parameter (test helper for the bookmaker_link filter).
 *
 * @param string $link URL.
 * @param string $slug Bookmaker slug.
 * @return string
 */
function add_utm(string $link, string $slug): string
{
    return $link . (str_contains($link, '?') ? '&' : '?') . 'utm=' . $slug;
}
