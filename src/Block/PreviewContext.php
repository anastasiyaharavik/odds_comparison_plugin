<?php

/**
 * Knows whether the current request renders the block for the editor preview.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Block;

use OddsComparison\Contracts\HookableInterface;
use WP_REST_Request;

defined('ABSPATH') || exit;

/**
 * The editor preview (ServerSideRender) is a REST request to the block-renderer route for this
 * block. Only that request gets the editor hints ("Choose an event…", "Odds are being fetched…");
 * any other REST request that renders the block, such as `content.rendered` of
 * `/wp/v2/posts` for an anonymous client, gets the same output as the page.
 */
final class PreviewContext implements HookableInterface
{
    /** REST route of this block's editor preview. */
    public const ROUTE = '/wp/v2/block-renderer/odds-comparison/odds-table';

    /** Nesting depth of block-renderer requests being dispatched now. */
    private int $depth = 0;

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_filter('rest_request_before_callbacks', [$this, 'enter'], 10, 3);
        add_filter('rest_request_after_callbacks', [$this, 'leave'], 10, 3);
    }

    /**
     * Note the start of a block-renderer request for this block.
     *
     * @param mixed           $response Response so far (passed through).
     * @param mixed           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public function enter(mixed $response, mixed $handler, WP_REST_Request $request): mixed
    {
        if ($request->get_route() === self::ROUTE) {
            ++$this->depth;
        }

        return $response;
    }

    /**
     * Note the end of a block-renderer request for this block.
     *
     * @param mixed           $response Response (passed through).
     * @param mixed           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public function leave(mixed $response, mixed $handler, WP_REST_Request $request): mixed
    {
        if ($request->get_route() === self::ROUTE && $this->depth > 0) {
            --$this->depth;
        }

        return $response;
    }

    /**
     * Whether the block is being rendered for the editor preview of a user who can edit posts.
     *
     * @return bool
     */
    public function isPreview(): bool
    {
        return $this->depth > 0 && current_user_can('edit_posts');
    }
}
