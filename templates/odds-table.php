<?php

/**
 * Odds table markup.
 *
 * Themes can override this file by copying it to `{theme}/odds-comparison/odds-table.php`.
 * Everything is escaped here, at output. Scraped strings (team and player names) are untrusted.
 *
 * Available: `$args`, the view model from OddsComparison\Block\TableRenderer::viewModel() after
 * the `odds_comparison/render_args` filter, plus `uid` and `wrapperAttributes`.
 *
 * @package OddsComparison
 *
 * @var array<string, mixed> $args
 */

defined('ABSPATH') || exit;

$oc_wrapper = (string) ($args['wrapperAttributes'] ?? '');

if (($args['state'] ?? '') !== 'table') {
    if (($args['message'] ?? '') !== '') {
        printf(
            '<div %1$s><p class="oc-odds__message oc-odds-%2$s">%3$s</p></div>',
            $oc_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_block_wrapper_attributes().
            esc_attr((string) $args['state']),
            esc_html((string) $args['message'])
        );
    }

    return;
}

$oc_uid      = (string) $args['uid'];
$oc_limit    = max(1, (int) $args['maxRows']);
$oc_rows     = array_slice((array) $args['rows'], 0, $oc_limit);
$oc_offers   = (array) ($args['bestOffers'] ?? []);
$oc_formats  = [
    'fractional' => __('Fractional', 'odds-comparison'),
    'decimal'    => __('Decimal', 'odds-comparison'),
    'american'   => __('American', 'odds-comparison'),
];
$oc_new_tab  = __('(opens in a new tab)', 'odds-comparison');
$oc_fetched  = $args['fetchedAt'] instanceof DateTimeInterface ? $args['fetchedAt'] : null;
$oc_kickoff  = is_array($args['kickoff'] ?? null) ? $args['kickoff'] : null;
$oc_is_match = ($args['layout'] ?? '') === 'bookmaker-rows' && ($args['home'] ?? '') !== '' && ($args['away'] ?? '') !== '';

/**
 * Price value with all three formats for the visitor switcher, plus the best-price marker.
 *
 * @param array<string, mixed> $cell Cell from the view model.
 * @return string Escaped HTML.
 */
$oc_price_html = static function (array $cell): string {
    return ($cell['best'] ? '<span class="oc-odds__star" aria-hidden="true">&#9733;</span>' : '')
        . sprintf(
            '<span class="oc-odds__value" data-dec="%1$s" data-frac="%2$s" data-us="%3$s">%4$s</span>',
            esc_attr((string) $cell['dec']),
            esc_attr((string) $cell['frac']),
            esc_attr((string) $cell['us']),
            esc_html((string) $cell['text'])
        )
        . ($cell['best'] ? '<span class="oc-sr-only"> ' . esc_html__('best odds', 'odds-comparison') . '</span>' : '');
};

/**
 * A price chip (plain text). The bookmaker's name carries the link, see $oc_bookmaker_html.
 *
 * @param array<string, mixed> $cell Cell from the view model.
 * @return string Escaped HTML.
 */
$oc_chip_html = static function (array $cell) use ($oc_price_html): string {
    return sprintf(
        '<span class="%1$s">%2$s</span>',
        esc_attr('oc-odds__price' . ($cell['best'] ? ' is-best' : '')),
        $oc_price_html($cell)
    );
};

/**
 * A bookmaker's name: a link to the bookmaker (affiliate link or website, new tab), or plain
 * text when there is no link.
 *
 * @param string $name Bookmaker name.
 * @param string $link Outbound link from the view model ("" for none).
 * @return string Escaped HTML.
 */
$oc_bookmaker_html = static function (string $name, string $link) use ($oc_new_tab): string {
    if ($link === '') {
        return esc_html($name);
    }

    return sprintf(
        '<a class="oc-odds__bookmaker" href="%1$s" rel="nofollow sponsored noopener" target="_blank">%2$s<span class="oc-sr-only"> %3$s</span></a>',
        esc_url($link),
        esc_html($name),
        esc_html($oc_new_tab)
    );
};
?>
<div <?php echo $oc_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_block_wrapper_attributes(). ?>
    data-oc-table data-format="<?php echo esc_attr((string) $args['format']); ?>"
    data-stale="<?php echo $args['stale'] ? 'true' : 'false'; ?>">
    <div class="oc-odds__card">
        <header class="oc-odds__head">
            <?php if ($oc_is_match) : ?>
                <div class="oc-odds__match">
                    <span class="oc-odds__team"><?php echo esc_html((string) $args['home']); ?><small><?php esc_html_e('Home', 'odds-comparison'); ?></small></span>
                    <span class="oc-odds__kickoff">
                        <span class="oc-odds__vs" aria-hidden="true"><?php esc_html_e('vs', 'odds-comparison'); ?></span>
                        <span class="oc-sr-only"><?php esc_html_e('versus', 'odds-comparison'); ?></span>
                        <?php if ($oc_kickoff !== null) : ?>
                            <time class="oc-odds__time" datetime="<?php echo esc_attr((string) $oc_kickoff['iso']); ?>"><?php echo esc_html((string) $oc_kickoff['text']); ?></time>
                        <?php endif; ?>
                    </span>
                    <span class="oc-odds__team"><?php echo esc_html((string) $args['away']); ?><small><?php esc_html_e('Away', 'odds-comparison'); ?></small></span>
                </div>
            <?php else : ?>
                <p class="oc-odds__title"><?php echo esc_html((string) $args['eventName']); ?></p>
            <?php endif; ?>
        </header>
        <div class="oc-odds__bar">
            <span class="oc-odds__subtitle"><?php echo esc_html((string) $args['subtitle']); ?></span>
            <?php if ($args['showSwitcher']) : ?>
                <div class="oc-odds__switcher" hidden>
                    <label for="<?php echo esc_attr($oc_uid . '-format'); ?>"><?php esc_html_e('Odds format', 'odds-comparison'); ?></label>
                    <select id="<?php echo esc_attr($oc_uid . '-format'); ?>" data-oc-format-switcher>
                        <?php foreach ($oc_formats as $oc_value => $oc_label) : ?>
                            <option value="<?php echo esc_attr($oc_value); ?>"<?php selected($oc_value, (string) $args['format']); ?>><?php echo esc_html($oc_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($oc_offers !== []) : ?>
            <ul class="oc-odds__offers oc-odds__offers--<?php echo esc_attr((string) $args['layout']); ?>" aria-label="<?php esc_attr_e('Best odds', 'odds-comparison'); ?>">
                <?php foreach ($oc_offers as $oc_offer) : ?>
                    <li class="oc-odds__offer">
                        <span class="oc-odds__offer-label"><?php echo esc_html((string) $oc_offer['selection']); ?></span>
                        <?php echo $oc_chip_html((array) $oc_offer['cell']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
                        <span class="oc-odds__offer-at">
                            <?php
                            $oc_at = sprintf(
                                /* translators: %s: bookmaker name. */
                                esc_html__('at %s', 'odds-comparison'),
                                $oc_bookmaker_html((string) $oc_offer['bookmaker'], (string) (((array) $oc_offer['cell'])['link'] ?? ''))
                            );
                            echo $oc_at; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped format and escaped link.
                            ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="oc-odds__more" aria-expanded="true" aria-controls="<?php echo esc_attr($oc_uid . '-all'); ?>" hidden
                data-label-show="<?php echo esc_attr(sprintf(/* translators: %d: number of bookmakers. */ _n('Compare %d bookmaker', 'Compare all %d bookmakers', (int) $args['bookmakerCount'], 'odds-comparison'), (int) $args['bookmakerCount'])); ?>"
                data-label-hide="<?php esc_attr_e('Hide bookmakers', 'odds-comparison'); ?>"><?php esc_html_e('Hide bookmakers', 'odds-comparison'); ?></button>
        <?php endif; ?>
        <div class="oc-odds__all" id="<?php echo esc_attr($oc_uid . '-all'); ?>">
            <div class="oc-odds__scroll" tabindex="0" role="region" aria-labelledby="<?php echo esc_attr($oc_uid . '-caption'); ?>">
                <table class="oc-odds__table oc-odds--<?php echo esc_attr((string) $args['layout']); ?>">
                    <caption id="<?php echo esc_attr($oc_uid . '-caption'); ?>"><?php echo esc_html((string) $args['caption']); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html((string) $args['rowHeader']); ?></th>
                            <?php foreach ((array) $args['columns'] as $oc_column) : ?>
                                <th scope="col"><?php echo $oc_bookmaker_html((string) $oc_column['label'], (string) ($oc_column['link'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($oc_rows as $oc_row) : ?>
                            <tr>
                                <th scope="row">
                                    <?php if (!empty($oc_row['logo'])) : ?>
                                        <img class="oc-odds__logo" src="<?php echo esc_url((string) $oc_row['logo']); ?>" alt="" width="24" height="24" loading="lazy" decoding="async" />
                                    <?php endif; ?>
                                    <?php echo $oc_bookmaker_html((string) $oc_row['label'], (string) ($oc_row['link'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?>
                                </th>
                                <?php foreach ((array) $oc_row['cells'] as $oc_cell) : ?>
                                    <?php if (empty($oc_cell['price'])) : ?>
                                        <td class="oc-odds__cell is-na" data-label="<?php echo esc_attr((string) $oc_cell['column']); ?>">
                                            <span aria-hidden="true">&ndash;</span><span class="oc-sr-only"><?php esc_html_e('Not available', 'odds-comparison'); ?></span>
                                        </td>
                                    <?php else : ?>
                                        <td class="oc-odds__cell<?php echo $oc_cell['best'] ? ' is-best' : ''; ?>" data-label="<?php echo esc_attr((string) $oc_cell['column']); ?>">
                                            <?php echo $oc_chip_html($oc_cell); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <footer class="oc-odds__footer">
            <?php if ($oc_offers !== []) : ?>
                <span class="oc-odds__legend"><span aria-hidden="true">&#9733;</span> <?php esc_html_e('Best price', 'odds-comparison'); ?></span>
            <?php endif; ?>
            <span class="oc-odds__meta">
                <?php if ($oc_fetched !== null) : ?>
                    <time datetime="<?php echo esc_attr($oc_fetched->format(DATE_ATOM)); ?>"><?php echo esc_html((string) $args['updated']); ?></time>
                <?php endif; ?>
                <?php if ($args['stale']) : ?>
                    <span class="oc-odds__stale"><?php esc_html_e('Odds may be out of date', 'odds-comparison'); ?></span>
                <?php endif; ?>
                <?php if (!empty($args['demo'])) : ?>
                    <span class="oc-odds__badge"><?php esc_html_e('Demo data', 'odds-comparison'); ?></span>
                <?php endif; ?>
                <span class="oc-odds__disclaimer"><?php echo esc_html((string) $args['disclaimer']); ?></span>
            </span>
        </footer>
    </div>
</div>
