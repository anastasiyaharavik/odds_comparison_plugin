<?php

/**
 * Index of (event, market) pairs used by blocks.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Storage;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Support\OptionKeys;
use WP_Post;

defined('ABSPATH') || exit;

/**
 * Which (eventId, marketKey) pairs blocks actually show, and in which posts.
 *
 * Only tracked pairs are fetched, so request volume follows real usage. Pairs come from:
 * - published posts: blocks are collected on `save_post`, recursing into inner blocks and synced
 *   patterns (`core/block`). Unpublishing, trashing or deleting a post removes its references;
 * - the editor preview (`POST /track`): a pair is kept for {@see self::PREVIEW_SECONDS} so the
 *   preview fills in after the next refresh.
 *
 * Stored in the non-autoloaded option `odds_comparison_tracked`, per site.
 *
 * @phpstan-type Pair array{eventId: string, marketKey: string, postIds: list<int>, previewUntil: int}
 */
final class TrackedEventsIndex implements HookableInterface
{
    /** Block that references odds. */
    public const BLOCK_NAME = 'odds-comparison/odds-table';

    /** Default market of the block (block.json). */
    private const DEFAULT_MARKET = 'match-result';

    /** How long an editor preview keeps an otherwise unused pair tracked. */
    public const PREVIEW_SECONDS = 86400;

    /** Synced-pattern nesting limit (guards against reference loops). */
    private const MAX_DEPTH = 5;

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('save_post', [$this, 'onSavePost'], 10, 2);
        add_action('wp_trash_post', [$this, 'forgetPost']);
        add_action('before_delete_post', [$this, 'forgetPost']);
    }

    /**
     * Re-index a saved post: published posts contribute their pairs, others none.
     *
     * @param int     $postId Post id.
     * @param WP_Post $post   Post.
     * @return void
     */
    public function onSavePost(int $postId, WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $pairs = $post->post_status === 'publish' ? $this->pairsInContent($post->post_content) : [];

        $this->setPostPairs($postId, $pairs);
    }

    /**
     * Remove every reference of a post (trash, delete).
     *
     * @param int $postId Post id.
     * @return void
     */
    public function forgetPost(int $postId): void
    {
        $this->setPostPairs((int) $postId, []);
    }

    /**
     * Track a pair for an editor preview.
     *
     * @param string $eventId   Event id.
     * @param string $marketKey Market key.
     * @return void
     */
    public function trackPreview(string $eventId, string $marketKey): void
    {
        $all             = $this->all();
        $key             = $eventId . '|' . $marketKey;
        $all[$key]     ??= self::emptyPair($eventId, $marketKey);
        $all[$key]['previewUntil'] = time() + self::PREVIEW_SECONDS;

        $this->store($all);
    }

    /**
     * Pairs that should be refreshed: referenced by a post or by a live preview.
     *
     * @return list<Pair>
     */
    public function pairs(): array
    {
        $now = time();

        return array_values(array_filter(
            $this->all(),
            static fn (array $pair): bool => $pair['postIds'] !== [] || $pair['previewUntil'] > $now
        ));
    }

    /**
     * Post ids that show a pair (for cache purging on `odds_comparison/odds_updated`).
     *
     * @param string $eventId   Event id.
     * @param string $marketKey Market key.
     * @return list<int>
     */
    public function postIdsFor(string $eventId, string $marketKey): array
    {
        return $this->all()[$eventId . '|' . $marketKey]['postIds'] ?? [];
    }

    /**
     * (eventId, marketKey) pairs referenced by blocks in post content, including inner blocks
     * and synced patterns.
     *
     * @param string $content Post content.
     * @param int    $depth   Synced-pattern nesting depth.
     * @return list<array{string, string}>
     */
    public function pairsInContent(string $content, int $depth = 0): array
    {
        if (!has_blocks($content) || $depth > self::MAX_DEPTH) {
            return [];
        }

        $pairs = [];
        $this->collect(parse_blocks($content), $pairs, $depth);

        return array_values($pairs);
    }

    /**
     * Walk parsed blocks.
     *
     * @param array<int, array<string, mixed>>     $blocks Parsed blocks.
     * @param array<string, array{string, string}> $pairs  Collected pairs by key (by reference).
     * @param int                                  $depth  Synced-pattern depth.
     * @return void
     */
    private function collect(array $blocks, array &$pairs, int $depth): void
    {
        foreach ($blocks as $block) {
            $name  = $block['blockName'] ?? null;
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            if ($name === self::BLOCK_NAME) {
                $eventId   = is_string($attrs['eventId'] ?? null) ? $attrs['eventId'] : '';
                $marketKey = is_string($attrs['marketKey'] ?? null) ? $attrs['marketKey'] : self::DEFAULT_MARKET;

                if ($eventId !== '') {
                    $pairs[$eventId . '|' . $marketKey] = [$eventId, $marketKey];
                }
            } elseif ($name === 'core/block' && isset($attrs['ref'])) {
                $pattern = get_post((int) $attrs['ref']);

                if ($pattern instanceof WP_Post && $pattern->post_type === 'wp_block') {
                    foreach ($this->pairsInContent($pattern->post_content, $depth + 1) as [$eventId, $marketKey]) {
                        $pairs[$eventId . '|' . $marketKey] = [$eventId, $marketKey];
                    }
                }
            }

            if (is_array($block['innerBlocks'] ?? null)) {
                $this->collect($block['innerBlocks'], $pairs, $depth);
            }
        }
    }

    /**
     * Make a post reference exactly the given pairs.
     *
     * @param int                          $postId Post id.
     * @param list<array{string, string}>  $pairs  Pairs.
     * @return void
     */
    private function setPostPairs(int $postId, array $pairs): void
    {
        $all     = $this->all();
        $before  = $all;
        $wanted  = [];

        foreach ($pairs as [$eventId, $marketKey]) {
            $wanted[$eventId . '|' . $marketKey] = [$eventId, $marketKey];
        }

        foreach ($all as $key => $pair) {
            $all[$key]['postIds'] = array_values(array_diff($pair['postIds'], [$postId]));
        }

        foreach ($wanted as $key => [$eventId, $marketKey]) {
            $all[$key]            ??= self::emptyPair($eventId, $marketKey);
            $all[$key]['postIds'][] = $postId;
        }

        $now = time();
        $all = array_filter(
            $all,
            static fn (array $pair): bool => $pair['postIds'] !== [] || $pair['previewUntil'] > $now
        );

        if ($all !== $before) {
            $this->store($all);
        }
    }

    /**
     * All stored pairs by key.
     *
     * @return array<string, Pair>
     */
    private function all(): array
    {
        $stored = get_option(OptionKeys::TRACKED, []);
        $pairs  = [];

        foreach (is_array($stored) ? $stored : [] as $key => $pair) {
            $valid = is_string($key) && is_array($pair)
                && is_string($pair['eventId'] ?? null) && is_string($pair['marketKey'] ?? null);

            if ($valid) {
                $pairs[$key] = [
                    'eventId'      => $pair['eventId'],
                    'marketKey'    => $pair['marketKey'],
                    'postIds'      => array_values(array_map('intval', (array) ($pair['postIds'] ?? []))),
                    'previewUntil' => (int) ($pair['previewUntil'] ?? 0),
                ];
            }
        }

        return $pairs;
    }

    /**
     * A pair with no references yet.
     *
     * @param string $eventId   Event id.
     * @param string $marketKey Market key.
     * @return Pair
     */
    private static function emptyPair(string $eventId, string $marketKey): array
    {
        return ['eventId' => $eventId, 'marketKey' => $marketKey, 'postIds' => [], 'previewUntil' => 0];
    }

    /**
     * Persist pairs. Pairs no post uses and whose preview has expired are dropped, so the option
     * holds only live references and does not grow with every event ever previewed.
     *
     * @param array<string, Pair> $pairs Pairs by key.
     * @return void
     */
    private function store(array $pairs): void
    {
        $now  = time();
        $live = static fn (array $pair): bool => $pair['postIds'] !== [] || $pair['previewUntil'] > $now;

        update_option(OptionKeys::TRACKED, array_filter($pairs, $live), false);
    }
}
