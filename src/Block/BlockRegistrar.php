<?php

/**
 * Registers the odds table block.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Block;

use OddsComparison\Contracts\HookableInterface;

defined('ABSPATH') || exit;

/**
 * Registers `odds-comparison/odds-table` from its compiled `block.json`.
 *
 * block.json declares the editor script, view script and styles, so WordPress loads the
 * frontend assets only on pages that contain the block. `render.php` delegates to
 * {@see TableRenderer}.
 */
final class BlockRegistrar implements HookableInterface
{
    /**
     * @param string $blockDir Absolute path of `build/odds-table`.
     */
    public function __construct(private readonly string $blockDir)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
    }

    /**
     * Register the block type and its script translations.
     *
     * @return void
     */
    public function registerBlock(): void
    {
        $type = register_block_type($this->blockDir);

        if ($type === false) {
            return;
        }

        foreach ([...$type->editor_script_handles, ...$type->view_script_handles] as $handle) {
            wp_set_script_translations($handle, 'odds-comparison');
        }
    }
}
