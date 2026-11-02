<?php

/**
 * Server-side render template for odds-comparison/odds-table.
 *
 * Available variables (provided by WordPress): $attributes, $content, $block.
 * Thin wrapper: all logic lives in OddsComparison\Block\TableRenderer.
 * Editor hints only for the editor preview (the block-renderer REST route, see PreviewContext);
 * every other request, REST included, gets the visitor output.
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;

$oc_plugin = OddsComparison\Plugin::instance();
$oc_html   = $oc_plugin->tableRenderer()->render(
    (array) $attributes,
    $oc_plugin->previewContext()->isPreview(),
    get_block_wrapper_attributes(['class' => 'oc-odds'])
);

echo $oc_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
