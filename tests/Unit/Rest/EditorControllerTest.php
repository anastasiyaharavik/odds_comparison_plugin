<?php

/**
 * Editor REST endpoints: event list and preview tracking.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Rest;

use DateTimeImmutable;
use OddsComparison\Block\PreviewContext;
use OddsComparison\Domain\Event;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Rest\EditorController;
use OddsComparison\Settings\Settings;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\OddsRepository;
use OddsComparison\Storage\TrackedEventsIndex;
use OddsComparison\Support\OptionKeys;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class EditorControllerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    private const NOW = '2026-11-02T12:00:00Z';

    private const MATCH = 'bettingodds:premier-league/m1';

    private const OUTRIGHTS = 'bettingodds:premier-league/outrights';

    private TrackedEventsIndex $tracked;

    protected function setUp(): void
    {
        $GLOBALS['odds_comparison_test_options']    = [];
        $GLOBALS['odds_comparison_test_transients'] = [];
        $GLOBALS['odds_comparison_test_cron']       = [];
        $GLOBALS['odds_comparison_test_can']        = true;

        update_option(OptionKeys::SETTINGS, [
            'active_source'   => 'bettingodds',
            'enabled_markets' => ['match-result', 'outright-winner'],
        ]);

        $now = new DateTimeImmutable(self::NOW);
        (new EventIndex())->replace('bettingodds', 'premier-league', [
            Event::match(
                'bettingodds',
                'premier-league/m1',
                'premier-league',
                'Chelsea vs Arsenal',
                'Chelsea',
                'Arsenal',
                $now->modify('+1 day'),
                '/football/premier-league'
            ),
            Event::match(
                'bettingodds',
                'premier-league/m0',
                'premier-league',
                'Leeds vs Spurs',
                'Leeds',
                'Spurs',
                $now->modify('-1 hour'),
                '/football/premier-league'
            ),
            Event::outrights('bettingodds', 'premier-league', 'Premier League Outrights', '/football/premier-league'),
        ], $now);

        $this->tracked = new TrackedEventsIndex();
    }

    private function controller(): EditorController
    {
        return new EditorController(
            new Settings(),
            new EventIndex(),
            new MarketRegistry(self::ROOT . 'config/markets.php'),
            new BookmakerRegistry(self::ROOT . 'config/bookmakers.php'),
            new CompetitionRegistry(self::ROOT . 'config/competitions.php'),
            new OddsRepository(static fn (): int => 60),
            $this->tracked,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW)
        );
    }

    /**
     * @param array<string, string> $params Parameters.
     */
    private static function request(string $route, array $params): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', $route);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    public function testTrackRejectsAMarketTheEventCannotHave(): void
    {
        $controller = $this->controller();

        $result = $controller->track(self::request('/odds-comparison/v1/track', [
            'eventId'   => self::OUTRIGHTS,
            'marketKey' => 'match-result',
        ]));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('odds_comparison_incompatible_pair', $result->get_error_code());
        self::assertSame(['status' => 400], $result->get_error_data());
        self::assertSame([], $this->tracked->pairs(), 'nothing tracked');

        $result = $controller->track(self::request('/odds-comparison/v1/track', [
            'eventId'   => self::MATCH,
            'marketKey' => 'outright-winner',
        ]));
        self::assertInstanceOf(WP_Error::class, $result);
    }

    public function testTrackAcceptsCompatiblePairs(): void
    {
        $controller = $this->controller();

        foreach ([[self::MATCH, 'match-result'], [self::OUTRIGHTS, 'outright-winner']] as [$eventId, $market]) {
            $result = $controller->track(self::request('/odds-comparison/v1/track', [
                'eventId'   => $eventId,
                'marketKey' => $market,
            ]));
            self::assertInstanceOf(WP_REST_Response::class, $result);
        }

        self::assertCount(2, $this->tracked->pairs());
    }

    public function testEventListLeavesOutStartedMatchesExceptTheBlocksOwnMarked(): void
    {
        $controller = $this->controller();

        $list = $controller->getEvents(self::request('/odds-comparison/v1/events', ['search' => '', 'per_page' => 50]));
        self::assertInstanceOf(WP_REST_Response::class, $list);
        self::assertSame([self::MATCH, self::OUTRIGHTS], array_column((array) $list->get_data(), 'id'));

        $withOwn = $controller->getEvents(self::request('/odds-comparison/v1/events', [
            'search'   => '',
            'per_page' => 50,
            'include'  => 'bettingodds:premier-league/m0',
        ]));
        $first   = ((array) $withOwn->get_data())[0];
        self::assertSame('bettingodds:premier-league/m0', $first['id']);
        self::assertTrue($first['started']);
        self::assertStringEndsWith('Leeds vs Spurs (started)', $first['label']);
    }

    public function testPreviewOnlyForTheBlockRendererRoute(): void
    {
        $context  = new PreviewContext();
        $renderer = new WP_REST_Request('GET', PreviewContext::ROUTE);
        $posts    = new WP_REST_Request('GET', '/wp/v2/posts');

        self::assertFalse($context->isPreview(), 'page request');

        $context->enter(null, null, $posts);
        self::assertFalse($context->isPreview(), 'content.rendered of another REST route');
        $context->leave(null, null, $posts);

        $context->enter(null, null, $renderer);
        self::assertTrue($context->isPreview());
        $GLOBALS['odds_comparison_test_can'] = false;
        self::assertFalse($context->isPreview(), 'never for users who cannot edit');
        $GLOBALS['odds_comparison_test_can'] = true;
        $context->leave(null, null, $renderer);

        self::assertFalse($context->isPreview(), 'ended with the request');
    }
}
