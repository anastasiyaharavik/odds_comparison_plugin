<?php

/**
 * REST routes used by the block editor.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Rest;

use Closure;
use DateTimeImmutable;
use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Contracts\OddsRepositoryInterface;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Scheduler\RefreshScheduler;
use OddsComparison\Settings\Settings;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\TrackedEventsIndex;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;

/**
 * `odds-comparison/v1` routes for the block editor:
 *
 * - `GET  /events?search=&include=` upcoming events of the active source
 * - `GET  /markets?eventId=`        enabled markets the event offers
 * - `GET  /bookmakers`              enabled bookmakers in admin order
 * - `GET  /selections?eventId=&marketKey=` selections of stored odds (empty until fetched)
 * - `POST /track`                   track a pair for the preview and queue a background fetch
 *
 * Everything is read from the indexes and the repository; nothing scrapes.
 * Permission: `edit_posts`; no public routes.
 */
final class EditorController implements HookableInterface
{
    /** Event id format (source-scoped). */
    private const EVENT_ID_PATTERN = '^[a-z0-9-]+:[A-Za-z0-9._~/-]+$';

    /** Market key format. */
    private const MARKET_KEY_PATTERN = '^[a-z0-9-]+$';

    private readonly Closure $clock;

    /**
     * @param Settings                $settings     Settings.
     * @param EventIndex              $events       Event index.
     * @param MarketRegistry          $markets      Market registry.
     * @param BookmakerRegistry       $bookmakers   Bookmaker registry.
     * @param CompetitionRegistry     $competitions Competition registry.
     * @param OddsRepositoryInterface $repository   Odds storage.
     * @param TrackedEventsIndex      $tracked      Tracked pairs.
     * @param Closure|null            $clock        fn(): DateTimeImmutable; "now".
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly EventIndex $events,
        private readonly MarketRegistry $markets,
        private readonly BookmakerRegistry $bookmakers,
        private readonly CompetitionRegistry $competitions,
        private readonly OddsRepositoryInterface $repository,
        private readonly TrackedEventsIndex $tracked,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register the routes.
     *
     * @return void
     */
    public function registerRoutes(): void
    {
        $eventId   = ['type' => 'string', 'pattern' => self::EVENT_ID_PATTERN, 'maxLength' => 200];
        $marketKey = ['type' => 'string', 'pattern' => self::MARKET_KEY_PATTERN, 'maxLength' => 64];
        $readable  = fn (string $route, string $method, array $args): bool => register_rest_route(
            StatusController::NAMESPACE,
            $route,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, $method],
                'permission_callback' => [$this, 'canEdit'],
                'args'                => $args,
            ]
        );

        $readable('/events', 'getEvents', [
            'search'   => [
                'type'              => 'string',
                'maxLength'         => 100,
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'include'  => $eventId + ['required' => false],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
        ]);
        $readable('/markets', 'getMarkets', ['eventId' => $eventId + ['required' => false]]);
        $readable('/bookmakers', 'getBookmakers', []);
        $readable('/selections', 'getSelections', [
            'eventId'   => $eventId + ['required' => true],
            'marketKey' => $marketKey + ['required' => true],
        ]);

        register_rest_route(StatusController::NAMESPACE, '/track', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'track'],
            'permission_callback' => [$this, 'canEdit'],
            'args'                => [
                'eventId'   => $eventId + ['required' => true],
                'marketKey' => $marketKey + ['required' => true],
            ],
        ]);
    }

    /**
     * Permission: users who can edit posts.
     *
     * @return bool
     */
    public function canEdit(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Upcoming events of the active source (plus an explicitly included one, e.g. the block's
     * current event, even if it belongs to another source).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function getEvents(WP_REST_Request $request): WP_REST_Response
    {
        $now     = ($this->clock)();
        $events  = $this->events->search(
            $this->settings->activeSource(),
            (string) $request['search'],
            (int) $request['per_page'],
            $now
        );
        $include = is_string($request['include']) ? $this->events->get($request['include']) : null;

        // The block's own event is listed so the picker can show it, marked when it has started.
        if ($include !== null && !in_array($include, $events, true)) {
            array_unshift($events, $include);
        }

        return new WP_REST_Response(array_values(array_map(
            fn (Event $event): array => $this->eventData($event, $now),
            $events
        )));
    }

    /**
     * Enabled markets, limited to those the event offers when an event is given.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function getMarkets(WP_REST_Request $request): WP_REST_Response
    {
        $event   = is_string($request['eventId']) ? $this->events->get($request['eventId']) : null;
        $markets = [];

        foreach ($this->markets->only($this->settings->enabledMarkets()) as $market) {
            if ($event !== null && !$this->offers($event, $market)) {
                continue;
            }

            $markets[] = ['key' => $market->key, 'label' => $market->label, 'outright' => $market->isOutright()];
        }

        return new WP_REST_Response($markets);
    }

    /**
     * Enabled bookmakers in admin order.
     *
     * @return WP_REST_Response
     */
    public function getBookmakers(): WP_REST_Response
    {
        $data = [];

        foreach ($this->bookmakers->only($this->settings->enabledBookmakers($this->bookmakers->keys())) as $bookmaker) {
            $data[] = ['slug' => $bookmaker->slug, 'label' => $this->settings->bookmakerLabel($bookmaker)];
        }

        return new WP_REST_Response($data);
    }

    /**
     * Selections of the stored odds (empty until the pair has been fetched).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function getSelections(WP_REST_Request $request): WP_REST_Response
    {
        $odds = $this->repository->get((string) $request['eventId'], (string) $request['marketKey']);
        $data = [];

        foreach ($odds?->selections ?? [] as $key => $selection) {
            $data[] = ['key' => $key, 'label' => $selection['label']];
        }

        return new WP_REST_Response($data);
    }

    /**
     * Track a pair for the editor preview and queue a background fetch when it has no odds yet.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function track(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $eventId   = (string) $request['eventId'];
        $marketKey = (string) $request['marketKey'];

        if ($this->events->get($eventId) === null || !in_array($marketKey, $this->settings->enabledMarkets(), true)) {
            return new WP_Error(
                'odds_comparison_unknown_pair',
                __('Unknown event or disabled market.', 'odds-comparison'),
                ['status' => 404]
            );
        }

        $event  = $this->events->get($eventId);
        $market = $this->markets->get($marketKey);

        // E.g. an outrights event with Match Result: it could never be priced, and tracking it
        // would make every refresh request it for a day.
        if ($event === null || $market === null || !$this->offers($event, $market)) {
            return new WP_Error(
                'odds_comparison_incompatible_pair',
                __('This market is not available for this event.', 'odds-comparison'),
                ['status' => 400]
            );
        }

        $this->tracked->trackPreview($eventId, $marketKey);
        $cached = $this->repository->get($eventId, $marketKey) !== null;

        if (!$cached) {
            RefreshScheduler::queueManualRun();
        }

        return new WP_REST_Response(['tracked' => true, 'cached' => $cached]);
    }

    /**
     * Whether a market can be priced for an event: same kind (match or outrights), named on the
     * event's source and, for outright markets, listed for the event's competition.
     *
     * @param Event  $event  Event.
     * @param Market $market Market.
     * @return bool
     */
    private function offers(Event $event, Market $market): bool
    {
        if ($market->isOutright() !== $event->isOutright() || $market->sourceName($event->sourceId) === null) {
            return false;
        }

        return !$market->isOutright()
            || $this->competitions->get($event->competition)?->outrightPath($event->sourceId, $market->key) !== null;
    }

    /**
     * Event as editor data.
     *
     * @param Event             $event Event.
     * @param DateTimeImmutable $now   Current time.
     * @return array<string, mixed>
     */
    private function eventData(Event $event, DateTimeImmutable $now): array
    {
        $competition = $this->competitions->get($event->competition)?->name ?? $event->competition;
        $started     = $event->hasStarted($now);
        $when        = $event->startsAt === null
            ? __('season', 'odds-comparison')
            : wp_date(__('D j M, H:i', 'odds-comparison'), $event->startsAt->getTimestamp());
        $label       = $competition . ' · ' . $when . ' — ' . $event->name;

        return [
            'id'          => $event->id,
            'name'        => $event->name,
            'kind'        => $event->kind,
            'competition' => $competition,
            'startsAt'    => $event->startsAt?->format(DATE_ATOM),
            'started'     => $started,
            /* translators: %s: event label (competition, date and name). */
            'label'       => $started ? sprintf(__('%s (started)', 'odds-comparison'), $label) : $label,
        ];
    }
}
