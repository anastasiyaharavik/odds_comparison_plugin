<?php

/**
 * Publishes settings changes as a plugin action.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Settings;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * Fires `odds_comparison/settings_saved` (new, old) whenever the settings option changes,
 * from the settings page or from code. Listeners such as the cron schedule
 * react to the change (Observer pattern).
 */
final class SettingsEvents implements HookableInterface
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('update_option_' . OptionKeys::SETTINGS, [$this, 'onUpdate'], 10, 2);
    }

    /**
     * Relay an option update.
     *
     * @param mixed $old Previous stored value.
     * @param mixed $new New stored value.
     * @return void
     */
    public function onUpdate(mixed $old, mixed $new): void
    {
        $old = array_replace(Settings::DEFAULTS, is_array($old) ? $old : []);
        $new = array_replace(Settings::DEFAULTS, is_array($new) ? $new : []);

        if ($old === $new) {
            return;
        }

        /**
         * Fires after the plugin settings changed.
         *
         * @param array<string, mixed> $new New settings (defaults merged).
         * @param array<string, mixed> $old Previous settings (defaults merged).
         */
        do_action('odds_comparison/settings_saved', $new, $old);
    }
}
