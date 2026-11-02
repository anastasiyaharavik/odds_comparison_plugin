<?php

/**
 * Admin REST routes for refresh status.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Rest;

use OddsComparison\Admin\SettingsPage;
use OddsComparison\Admin\StatusPage;
use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Scheduler\RefreshScheduler;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;

/**
 * `GET /odds-comparison/v1/status` and `POST /odds-comparison/v1/refresh`, used by the Status
 * tab's asynchronous "Refresh now". Administrators only (`manage_options`).
 * Authentication is cookie + `X-WP-Nonce`, handled by core and sent by wp.apiFetch. No public
 * routes.
 */
final class StatusController implements HookableInterface
{
    public const NAMESPACE = 'odds-comparison/v1';

    /**
     * @param StatusPage $status Status data.
     */
    public function __construct(private readonly StatusPage $status)
    {
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
        register_rest_route(self::NAMESPACE, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getStatus'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [],
        ]);

        register_rest_route(self::NAMESPACE, '/refresh', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'queueRefresh'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [],
        ]);
    }

    /**
     * Permission: administrators only.
     *
     * @return bool
     */
    public function canManage(): bool
    {
        return current_user_can(SettingsPage::CAPABILITY);
    }

    /**
     * Current run state and last summary.
     *
     * @return WP_REST_Response
     */
    public function getStatus(): WP_REST_Response
    {
        return new WP_REST_Response($this->status->snapshot());
    }

    /**
     * Queue a manual run.
     *
     * @return WP_REST_Response
     */
    public function queueRefresh(): WP_REST_Response
    {
        $queued = RefreshScheduler::queueManualRun();

        return new WP_REST_Response(['queued' => $queued] + $this->status->snapshot(), $queued ? 202 : 200);
    }
}
