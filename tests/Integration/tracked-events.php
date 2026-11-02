<?php

/**
 * WordPress integration check for TrackedEventsIndex and the plugin hooks.
 *
 *     wp eval-file wp-content/plugins/odds-comparison/tests/Integration/tracked-events.php
 *
 * Creates temporary posts (removed at the end), saves the tracked index and restores it, and
 * prints PASS/FAIL per check. Exit status 1 on any failure. No network requests.
 *
 * @package OddsComparison
 */

use OddsComparison\Plugin;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

$plugin  = Plugin::instance();
$tracked = $plugin->tracked();
$saved   = get_option(OptionKeys::TRACKED, null);
$created = [];
$failed  = 0;

$check = static function (string $label, bool $ok) use (&$failed): void {
    WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $label);
    $failed += $ok ? 0 : 1;
};

$block = static fn (string $eventId, string $market = ''): string => sprintf(
    '<!-- wp:odds-comparison/odds-table %s /-->',
    wp_json_encode(array_filter(['eventId' => $eventId, 'marketKey' => $market]))
);

try {
    delete_option(OptionKeys::TRACKED);

    $check('save_post hook registered', has_action('save_post', [$tracked, 'onSavePost']) !== false);
    $check('wp_trash_post hook registered', has_action('wp_trash_post', [$tracked, 'forgetPost']) !== false);

    // Synced pattern holding one table.
    $pattern   = wp_insert_post([
        'post_type'    => 'wp_block',
        'post_status'  => 'publish',
        'post_title'   => 'OC test pattern',
        'post_content' => $block('test:pattern/1', 'outright-winner'),
    ]);
    $created[] = $pattern;

    // Post: top-level table (default market), nested table in a group, and the synced pattern.
    $content = $block('test:pl/1')
        . '<!-- wp:group --><div class="wp-block-group">'
        . $block('test:pl/2', 'match-result')
        . '</div><!-- /wp:group -->'
        . sprintf('<!-- wp:block {"ref":%d} /-->', $pattern);

    $draft     = wp_insert_post(['post_status' => 'draft', 'post_title' => 'OC draft', 'post_content' => $content]);
    $created[] = $draft;
    $check('draft posts are not tracked', $tracked->postIdsFor('test:pl/1', 'match-result') === []);

    wp_update_post(['ID' => $draft, 'post_status' => 'publish']);
    $check(
        'published post: top-level block tracked (default market)',
        $tracked->postIdsFor('test:pl/1', 'match-result') === [$draft]
    );
    $check('published post: nested block tracked', $tracked->postIdsFor('test:pl/2', 'match-result') === [$draft]);
    $check(
        'published post: synced pattern block tracked',
        in_array($draft, $tracked->postIdsFor('test:pattern/1', 'outright-winner'), true)
    );

    wp_update_post(['ID' => $draft, 'post_content' => $block('test:pl/2', 'match-result')]);
    $check('removed block is untracked', $tracked->postIdsFor('test:pl/1', 'match-result') === []);
    $check('kept block stays tracked', $tracked->postIdsFor('test:pl/2', 'match-result') === [$draft]);

    wp_trash_post($draft);
    $check('trashing removes references', $tracked->postIdsFor('test:pl/2', 'match-result') === []);

    $tracked->trackPreview('test:preview/1', 'match-result');
    $pairs = array_map(static fn (array $p): string => $p['eventId'] . '|' . $p['marketKey'], $tracked->pairs());
    $check('editor preview pair is due for refresh', in_array('test:preview/1|match-result', $pairs, true));

    $stored = (array) get_option(OptionKeys::TRACKED, []);
    $check('pairs no post uses are not stored', !isset($stored['test:pl/2|match-result']));
    $stored['test:expired/1|match-result'] = [
        'eventId'      => 'test:expired/1',
        'marketKey'    => 'match-result',
        'postIds'      => [],
        'previewUntil' => time() - 1,
    ];
    update_option(OptionKeys::TRACKED, $stored, false);
    $tracked->trackPreview('test:preview/2', 'match-result');
    $stored = (array) get_option(OptionKeys::TRACKED, []);
    $check(
        'expired previews are dropped on the next save',
        !isset($stored['test:expired/1|match-result']) && isset($stored['test:preview/2|match-result'])
    );

    // TECHNICAL_DOCUMENTATION.md snippet shapes.
    add_filter('odds_comparison/cache_ttl', static fn (array $ttl): array => ['stale_after' => 5] + $ttl);
    $ttl = (new OddsComparison\Settings\Settings())->cacheTtl();
    $check('cache_ttl filter overrides stale_after', $ttl['stale_after'] === 5);
    $check('odds_comparison/loaded fired', did_action('odds_comparison/loaded') > 0);
} finally {
    foreach ($created as $id) {
        wp_delete_post($id, true);
    }

    $saved === null ? delete_option(OptionKeys::TRACKED) : update_option(OptionKeys::TRACKED, $saved, false);
}

$failed === 0 ? WP_CLI::success('All checks passed.') : WP_CLI::error(sprintf('%d check(s) failed.', $failed));
