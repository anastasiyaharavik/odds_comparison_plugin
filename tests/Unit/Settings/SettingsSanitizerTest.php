<?php

/**
 * SettingsSanitizer: merge-on-save and validation.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Settings;

use OddsComparison\Settings\Settings;
use OddsComparison\Settings\SettingsSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsSanitizerTest extends TestCase
{
    private const COMPETITIONS = ['premier-league', 'championship', 'la-liga'];

    private const MARKETS = ['match-result', 'outright-winner'];

    private const BOOKMAKERS = ['william-hill', 'paddy-power', 'betfred'];

    /** @var list<string> */
    private array $probed = [];

    private function sanitizer(?string $probeError = null, bool $demo = false): SettingsSanitizer
    {
        return new SettingsSanitizer(
            self::COMPETITIONS,
            self::MARKETS,
            self::BOOKMAKERS,
            ['bettingodds', 'theoddsapi'],
            $demo,
            function (string $source) use ($probeError): ?string {
                $this->probed[] = $source;

                return $probeError;
            },
            ['bettingodds' => 'BettingOdds.com', 'theoddsapi' => 'The Odds API']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function current(): array
    {
        return array_replace(Settings::DEFAULTS, [
            'data_mode'            => 'live',
            'enabled_competitions' => ['premier-league'],
            'bookmakers'           => [
                'william-hill' => ['enabled' => true, 'label' => '', 'link' => 'https://aff.example/wh'],
            ],
            'enabled_markets'      => ['match-result', 'outright-winner'],
            'refresh_interval'     => 30,
            'stale_after'          => 90,
        ]);
    }

    /**
     * General tab submission without competitions, with overrides.
     *
     * @param array<string, mixed> $overrides Field overrides.
     * @return array<string, mixed>
     */
    private static function general(array $overrides = []): array
    {
        return $overrides + [
            '_tab'                => 'general',
            'active_source'       => 'bettingodds',
            'data_mode'           => 'live',
            'default_odds_format' => 'fractional',
        ];
    }

    /**
     * Bookmakers tab submission with one enabled William Hill row.
     *
     * @param array<string, string> $row Row fields.
     * @return array<string, mixed>
     */
    private static function williamHill(array $row): array
    {
        return ['_tab' => 'bookmakers', 'bookmakers' => ['william-hill' => $row + ['enabled' => '1']]];
    }

    /**
     * @return array{settings: array<string, mixed>, errors: list<array{code: string, message: string}>}
     */
    private function save(array $input, ?SettingsSanitizer $sanitizer = null): array
    {
        return ($sanitizer ?? $this->sanitizer())->sanitize($input, self::current());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function validTabSubmissions(): array
    {
        return [
            'general'    => [[
                '_tab' => 'general', 'active_source' => 'bettingodds', 'data_mode' => 'live',
                'default_odds_format' => 'decimal', 'enabled_competitions' => ['la-liga', 'premier-league'],
            ]],
            'bookmakers' => [[
                '_tab' => 'bookmakers',
                'bookmakers' => [
                    'william-hill' => ['enabled' => '1', 'link' => 'https://aff.example/wh2', 'label' => 'WH'],
                ],
                'bookmaker_order' => ['betfred', 'william-hill', 'paddy-power'],
            ]],
            'markets'    => [['_tab' => 'markets', 'enabled_markets' => ['match-result']]],
            'cache'      => [[
                '_tab' => 'cache', 'refresh_interval' => '15', 'stale_after' => '60',
                'requests_per_run' => '10', 'min_request_interval' => '5', 'api_refresh_interval' => '360',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $input Submitted tab.
     */
    #[DataProvider('validTabSubmissions')]
    public function testSavingOneTabNeverChangesTheOthers(array $input): void
    {
        $result  = $this->save($input);
        $current = self::current();
        $owned   = SettingsSanitizer::TABS[$input['_tab']];

        self::assertSame([], $result['errors']);

        foreach ($current as $key => $value) {
            if (!in_array($key, $owned, true)) {
                self::assertSame(
                    $value,
                    $result['settings'][$key],
                    "{$key} changed while saving the {$input['_tab']} tab"
                );
            }
        }
    }

    public function testGeneralTabValues(): void
    {
        $settings = $this->save(self::validTabSubmissions()['general'][0])['settings'];

        self::assertSame('decimal', $settings['default_odds_format']);
        self::assertSame(['premier-league', 'la-liga'], $settings['enabled_competitions'], 'registry order');
    }

    public function testUnknownCompetitionsAreDroppedAndEmptyListAllowed(): void
    {
        $settings = $this->save(self::general(['enabled_competitions' => ['mls', '<script>']]))['settings'];
        self::assertSame([], $settings['enabled_competitions']);

        $none = $this->save(self::general())['settings'];
        self::assertSame([], $none['enabled_competitions'], 'no checkbox ticked = none enabled');
    }

    public function testSourceSwitchRunsTheProbe(): void
    {
        $input = self::general(['active_source' => 'theoddsapi']);

        self::assertSame('theoddsapi', $this->save($input)['settings']['active_source']);
        self::assertSame(['theoddsapi'], $this->probed);

        $result = $this->save($input, $this->sanitizer('blocked by bot protection'));
        self::assertSame('bettingodds', $result['settings']['active_source'], 'failed probe keeps the current source');
        self::assertStringContainsString(
            'Could not switch to The Odds API: blocked by bot protection',
            $result['errors'][0]['message']
        );
    }

    public function testUnchangedSourceIsNotProbed(): void
    {
        $this->save(self::validTabSubmissions()['general'][0]);

        self::assertSame([], $this->probed, 'no network request when the source does not change');
    }

    public function testDemoModeNeedsRecordedData(): void
    {
        $input = self::general(['data_mode' => 'demo']);

        $result = $this->save($input);
        self::assertSame('live', $result['settings']['data_mode']);
        self::assertSame('data_mode', $result['errors'][0]['code']);

        self::assertSame('demo', $this->save($input, $this->sanitizer(demo: true))['settings']['data_mode']);
    }

    public function testUnknownDataModeIsRejected(): void
    {
        $result = $this->save(self::general(['data_mode' => 'recorded']), $this->sanitizer(demo: true));

        self::assertSame('live', $result['settings']['data_mode']);
        self::assertSame('data_mode', $result['errors'][0]['code']);
    }

    public function testBookmakerRowsLinksLabelsAndOrder(): void
    {
        $settings = $this->save(self::validTabSubmissions()['bookmakers'][0])['settings'];

        self::assertSame(
            ['enabled' => true, 'label' => 'WH', 'link' => 'https://aff.example/wh2'],
            $settings['bookmakers']['william-hill']
        );
        self::assertFalse($settings['bookmakers']['paddy-power']['enabled'], 'unchecked = disabled');
        self::assertSame(['betfred', 'william-hill', 'paddy-power'], $settings['bookmaker_order']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeLinks(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data uri'   => ['data:text/html,<script>alert(1)</script>'],
            'relative'   => ['/go/bet365'],
            'no host'    => ['https://'],
            'ftp'        => ['ftp://example.com/x'],
        ];
    }

    #[DataProvider('unsafeLinks')]
    public function testUnsafeAffiliateLinksAreRejectedAndThePreviousKept(string $link): void
    {
        $result = $this->save(self::williamHill(['link' => $link]));

        self::assertSame('https://aff.example/wh', $result['settings']['bookmakers']['william-hill']['link']);
        self::assertSame('link_william-hill', $result['errors'][0]['code']);
    }

    public function testEmptyLinkAndLabelMarkupAreCleaned(): void
    {
        $settings = $this->save(self::williamHill(['link' => '', 'label' => '<b>Hill</b>']))['settings'];

        self::assertSame('', $settings['bookmakers']['william-hill']['link']);
        self::assertSame('Hill', $settings['bookmakers']['william-hill']['label']);
    }

    public function testOrderIgnoresUnknownSlugsAndAppendsMissing(): void
    {
        $settings = $this->save(['_tab' => 'bookmakers', 'bookmaker_order' => ['evil', 'betfred']])['settings'];

        self::assertSame(['betfred', 'william-hill', 'paddy-power'], $settings['bookmaker_order']);
    }

    public function testNoJsMoveButtons(): void
    {
        $order = static fn (array $settings): array => $settings['bookmaker_order'];
        $base  = ['_tab' => 'bookmakers', 'bookmaker_order' => self::BOOKMAKERS];

        self::assertSame(
            ['william-hill', 'betfred', 'paddy-power'],
            $order($this->save($base + ['_move' => 'betfred:up'])['settings'])
        );
        self::assertSame(
            ['paddy-power', 'william-hill', 'betfred'],
            $order($this->save($base + ['_move' => 'william-hill:down'])['settings'])
        );
        self::assertSame(
            self::BOOKMAKERS,
            $order($this->save($base + ['_move' => 'william-hill:up'])['settings']),
            'first stays first'
        );
        self::assertSame(self::BOOKMAKERS, $order($this->save($base + ['_move' => 'x;drop:up'])['settings']));
    }

    public function testBookmakersAndMarketsFormKeepsStoredLabelsAndOrder(): void
    {
        $current                                        = self::current();
        $current['bookmakers']['william-hill']['label'] = 'Hill';
        $current['bookmaker_order']                     = ['betfred', 'william-hill', 'paddy-power'];

        $result = $this->sanitizer()->sanitize([
            '_tab'            => 'bookmakers',
            '_markets'        => '1',
            'bookmakers'      => ['william-hill' => ['enabled' => '1', 'link' => 'https://aff.example/new']],
            'enabled_markets' => ['outright-winner'],
        ], $current);

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['enabled' => true, 'label' => 'Hill', 'link' => 'https://aff.example/new'],
            $result['settings']['bookmakers']['william-hill'],
            'label not on the form: stored label kept'
        );
        self::assertSame(['betfred', 'william-hill', 'paddy-power'], $result['settings']['bookmaker_order']);
        self::assertSame(['outright-winner'], $result['settings']['enabled_markets']);
    }

    public function testBookmakersAndMarketsFormNeedsOneMarket(): void
    {
        $result = $this->save(['_tab' => 'bookmakers', '_markets' => '1', 'bookmakers' => []]);

        self::assertSame(['match-result', 'outright-winner'], $result['settings']['enabled_markets']);
        self::assertSame('enabled_markets', $result['errors'][0]['code']);
    }

    public function testAtLeastOneMarket(): void
    {
        $result = $this->save(['_tab' => 'markets']);

        self::assertSame(['match-result', 'outright-winner'], $result['settings']['enabled_markets']);
        self::assertSame('enabled_markets', $result['errors'][0]['code']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidCacheValues(): array
    {
        return [
            'refresh not in list'  => ['refresh_interval', '7'],
            'stale below range'    => ['stale_after', '5'],
            'stale not a number'   => ['stale_after', '1e3'],
            'requests too high'    => ['requests_per_run', '51'],
            'requests negative'    => ['requests_per_run', '-5'],
            'interval too low'     => ['min_request_interval', '1'],
            'interval above 10 s'  => ['min_request_interval', '20'],
            'api interval invalid' => ['api_refresh_interval', '30'],
        ];
    }

    #[DataProvider('invalidCacheValues')]
    public function testInvalidCacheValuesKeepThePreviousValue(string $key, string $value): void
    {
        $input  = array_replace(self::validTabSubmissions()['cache'][0], [$key => $value]);
        $result = $this->save($input);

        self::assertSame(self::current()[$key], $result['settings'][$key]);
        self::assertContains($key, array_column($result['errors'], 'code'));
    }

    public function testStaleAfterMustBeTwiceTheRefreshInterval(): void
    {
        $input  = array_replace(
            self::validTabSubmissions()['cache'][0],
            ['refresh_interval' => '60', 'stale_after' => '90']
        );
        $result = $this->save($input);

        self::assertSame(30, $result['settings']['refresh_interval'], 'both kept');
        self::assertSame(90, $result['settings']['stale_after']);
        self::assertStringContainsString('120 minutes', $result['errors'][0]['message']);
    }

    public function testTamperedFormChangesNothing(): void
    {
        foreach ([null, 'string', ['_tab' => 'status'], ['no' => 'tab']] as $input) {
            $result = $this->sanitizer()->sanitize($input, self::current());

            self::assertSame(self::current(), $result['settings']);
            self::assertSame('invalid_form', $result['errors'][0]['code']);
        }
    }
}
