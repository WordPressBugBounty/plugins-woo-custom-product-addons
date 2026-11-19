<?php
/**
 * Advance notice for sites below the next platform floor (DEV-008, DEC-129).
 *
 * Why (confirmed, hub task DEV-008): version 6 (Free 3.2.0, with Pro 6.0) declares PHP 7.4, WordPress 5.9 and
 * WooCommerce 7.0 as its minimums. WordPress.org then serves them with the update, WordPress stops offering
 * 3.2.0 as a one-click update to a site below them, and such a site stays on 3.1.x, which keeps working. This notice tells the merchant before that happens, and exactly what is too old, so they can ask
 * their host in time. Every site at or above the floor sees nothing and nothing is stored for it.
 *
 * | Site                                            | Plugins / Updates screens (user can update plugins) | Other screens |
 * | ----------------------------------------------- | --------------------------------------------------- | ------------- |
 * | PHP 8.2, WordPress 7.1, WooCommerce 11.2        | nothing                                             | nothing       |
 * | PHP 7.3, WordPress 7.1, WooCommerce 11.2        | "… 3.2 will need PHP 7.4 or later. This site runs PHP 7.3. …" | nothing |
 * | PHP 7.3, WordPress 5.8, WooCommerce 6.9         | one notice, one sentence per item (PHP, WordPress, WooCommerce) | nothing |
 * | below, the user dismissed it for this floor     | nothing (user meta holds the floor's key)           | nothing       |
 * | below, a later floor (new FLOOR values)         | shown again                                         | nothing       |
 * | WooCommerce not active                          | WooCommerce is not judged                           | nothing       |
 *
 * The floor lives only in FLOOR, so a later raise (PHP 8.1 is planned for a 6.x, DEC-129) reuses this notice by
 * changing those values. The screens and the dismiss pattern are those of the update-failure notice
 * (Pro's Update_Notices::admin_notice, DEC-114), so both editions show it the same way.
 *
 * @since 3.1.11
 */

namespace Acowebs\WCPA\Free;

if ( ! defined('ABSPATH')) {
    exit;
}

class Requirements
{
    /**
     * The next version and its declared minimums (DEC-129, owner 2026-11-14: WooCommerce 7.0).
     * Must equal what Free 3.2.0's start.php and readme.txt declare.
     */
    const FLOOR = ['version' => '3.2', 'php' => '7.4', 'wp' => '5.9', 'wc' => '7.0'];

    /** User meta: the key of the floor whose notice the user dismissed (see floor_key()). */
    const DISMISSED_META = 'wcpa_requirements_notice_dismissed';

    /** admin-ajax action (and nonce action) of the notice's dismiss button. */
    const AJAX_DISMISS = 'wcpa_dismiss_requirements_notice';

    /** The screens that show it: where merchants update plugins, as the update-failure notice. */
    const SCREENS = ['plugins', 'update-core', 'plugins-network', 'update-core-network'];

    /** Hooks for admin requests only (called from Main inside is_admin()). */
    public static function register()
    {
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
        add_action('network_admin_notices', [__CLASS__, 'admin_notice']);
        add_action('wp_ajax_' . self::AJAX_DISMISS, [__CLASS__, 'ajax_dismiss']);
    }

    /**
     * This site's versions. WooCommerce is null when it is not loaded, so it is not judged.
     *
     * @return array ['php' => string, 'wp' => string, 'wc' => string|null]
     */
    public static function site_versions()
    {
        return [
            'php' => PHP_VERSION,
            'wp'  => get_bloginfo('version'),
            'wc'  => defined('WC_VERSION') ? WC_VERSION : null,
        ];
    }

    /**
     * The items of $floor that $site does not meet, in the order PHP, WordPress, WooCommerce.
     * The comparison is WordPress's own (is_php_version_compatible: version_compare(…, '>=')).
     *
     * @param array $floor FLOOR, or a test copy.
     * @param array $site  site_versions(), or a test copy.
     *
     * @return array[] each ['item' => 'php'|'wp'|'wc', 'site' => string, 'needed' => string]
     */
    public static function unmet(array $floor, array $site)
    {
        $out = [];
        foreach (['php', 'wp', 'wc'] as $item) {
            if (empty($floor[$item]) || empty($site[$item])) {
                continue;
            }
            if (version_compare((string) $site[$item], (string) $floor[$item], '<')) {
                $out[] = ['item' => $item, 'site' => (string) $site[$item], 'needed' => (string) $floor[$item]];
            }
        }

        return $out;
    }

    /** One key per floor, so a dismissal holds for this floor only. */
    public static function floor_key(array $floor)
    {
        return implode(':', [$floor['version'], $floor['php'], $floor['wp'], $floor['wc']]);
    }

    /**
     * The notice's sentences, plain text: one per unmet item, then what keeps working.
     *
     * @param array  $unmet     unmet()
     * @param string $installed the installed version (WCPA_VERSION)
     * @param array  $floor     FLOOR, or a test copy
     *
     * @return string[]
     */
    public static function sentences(array $unmet, $installed, array $floor = self::FLOOR)
    {
        $name = 'WooCommerce Custom Product Addons';
        $out  = [];
        foreach ($unmet as $u) {
            switch ($u['item']) {
                case 'php':
                    /* translators: 1: plugin name, 2: next plugin version, 3: PHP version needed, 4: this site's PHP version */
                    $text = __('%1$s %2$s will need PHP %3$s or later. This site runs PHP %4$s.', 'woo-custom-product-addons');
                    break;
                case 'wp':
                    /* translators: 1: plugin name, 2: next plugin version, 3: WordPress version needed, 4: this site's WordPress version */
                    $text = __('%1$s %2$s will need WordPress %3$s or later. This site runs WordPress %4$s.', 'woo-custom-product-addons');
                    break;
                default:
                    /* translators: 1: plugin name, 2: next plugin version, 3: WooCommerce version needed, 4: this site's WooCommerce version */
                    $text = __('%1$s %2$s will need WooCommerce %3$s or later. This site runs WooCommerce %4$s.', 'woo-custom-product-addons');
            }
            $out[] = sprintf($text, $name, $floor['version'], $u['needed'], $u['site']);
        }
        if ($out) {
            // UI review 2026-11-14: "Update these" read wrong with one item, so the closing sentence has a singular form.
            /* translators: 1: installed plugin version (major.minor), 2: next plugin version */
            $out[] = sprintf(_n('Version %1$s keeps working; %2$s will be offered once this is updated.',
                'Version %1$s keeps working; %2$s will be offered once these are updated.', count($unmet), 'woo-custom-product-addons'),
                implode('.', array_slice(explode('.', (string) $installed), 0, 2)), $floor['version']);
        }

        return $out;
    }

    /**
     * admin_notices / network_admin_notices: the notice on the Plugins and Updates screens, for users who can
     * update plugins, while the site is below FLOOR and the user has not dismissed this floor.
     *
     * @param array|string $site Test seam: a site_versions()-shaped array. WordPress passes '' here.
     */
    public static function admin_notice($site = '')
    {
        if ( ! current_user_can('update_plugins') || ! function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array($screen->id, self::SCREENS, true)) {
            return;
        }
        $unmet = self::unmet(self::FLOOR, is_array($site) ? $site : self::site_versions());
        if ( ! $unmet || get_user_meta(get_current_user_id(), self::DISMISSED_META, true) === self::floor_key(self::FLOOR)) {
            return;
        }
        printf('<div class="notice notice-warning is-dismissible wcpa-requirements-notice" data-nonce="%1$s"><p>%2$s</p></div>',
            esc_attr(wp_create_nonce(self::AJAX_DISMISS)),
            esc_html(implode(' ', self::sentences($unmet, WCPA_VERSION))));
        add_action('admin_footer', [__CLASS__, 'dismiss_script']);
    }

    /** The notice's close button (added by WordPress's common.js) also stores the dismissal. */
    public static function dismiss_script()
    {
        ?>
        <script>
            jQuery(document).on('click', '.wcpa-requirements-notice .notice-dismiss', function () {
                jQuery.post(window.ajaxurl, {
                    action: '<?php echo esc_js(self::AJAX_DISMISS); ?>',
                    _ajax_nonce: jQuery(this).closest('.wcpa-requirements-notice').data('nonce')
                });
            });
        </script>
        <?php
    }

    /** Stores the dismissal of the current floor for the current user. */
    public static function ajax_dismiss()
    {
        check_ajax_referer(self::AJAX_DISMISS);
        if ( ! current_user_can('update_plugins')) {
            wp_send_json_error(null, 403);
        }
        update_user_meta(get_current_user_id(), self::DISMISSED_META, self::floor_key(self::FLOOR));
        wp_send_json_success();
    }
}
