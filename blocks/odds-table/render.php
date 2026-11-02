<?php

/**
 * Server-side render template for odds-comparison/odds-table.
 *
 * Available variables (provided by WordPress): $attributes, $content, $block.
 * Thin wrapper: all logic lives in OddsComparison\Block\TableRenderer.
 * The editor preview (ServerSideRender) arrives as a REST request.
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;

$oc_html = OddsComparison\Plugin::instance()->tableRenderer()->render(
    (array) $attributes,
    wp_is_serving_rest_request(),
    get_block_wrapper_attributes(['class' => 'oc-odds'])
);

echo $oc_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
