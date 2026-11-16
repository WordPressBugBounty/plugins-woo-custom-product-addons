<?php
/**
 * Acowebs feedback platform: the deactivation popup and the newsletter notice (FEAT-021).
 *
 * Two admin pieces from the shared Acowebs UI kit (assets/acowebs/), both posting through admin-ajax.php to
 * the Acowebs feedback API (v1, https://audience.acowebs.com):
 *
 * | Piece               | Where and for whom                                        | Sends                         |
 * | ------------------- | --------------------------------------------------------- | ----------------------------- |
 * | Deactivation popup  | plugins.php (site admin), users with activate_plugins     | POST /v1/deactivation on Send |
 * | Newsletter notice   | Free's own screen, users with manage_woocommerce, from 14 | POST /v1/subscribe on         |
 * |                     | days after this code first ran there, until dismissed     | Subscribe                     |
 *
 * Rules (owner, FEAT-021, 2026-11-11, "Approve as recommended"):
 * - Nothing leaves the site without a click on Send or Subscribe. Skip, Cancel, Esc, bulk deactivation,
 *   WP-CLI and network deactivation send nothing.
 * - Feedback never blocks deactivation: the browser follows the deactivate link when admin-ajax answers or
 *   after 3 seconds, and this class never lets a failed request reach the user (no error, no PHP notice).
 * - "Acowebs can contact me about this feedback" starts unticked (WordPress.org guideline 7, GDPR recital
 *   32: a pre-ticked box is not consent). Name and email are sent only while it is ticked; the server
 *   drops them otherwise.
 * - The reason is sent as its English label whatever the admin language: the platform groups by that
 *   text. The admin's locale goes separately in site_language.
 * - The notice appears only 14 days after this code first ran on Free's screen, so an update never greets
 *   an existing merchant with a new card (DEC-039), and never again for a user once dismissed or subscribed.
 *
 * Shared with every Acowebs plugin (on purpose, so they cooperate): the API URL filter
 * `acowebs_feedback_api_url`, the user meta `acowebs_optin_done` (set when a user dismissed or subscribed
 * in any Acowebs plugin) and the stylesheet handle `acowebs-feedback-ui` (WordPress loads one copy).
 * Everything else is Free's own (namespaced class, `wcpa_*` AJAX actions and nonce), so Free does not depend
 * on which plugin's copy of the platform's sample classes would load first.
 *
 * Guide: hub docs/code/feedback-integration.md.
 */

namespace Acowebs\WCPA\Free;

if (!defined('ABSPATH')) {
    exit;
}

class Feedback
{
    /** Canonical plugin name on the platform; Free and Pro send exactly the same string (guide section 8). */
    const PLUGIN_NAME = 'Product Addons for WooCommerce';
    const EDITION = 'free';
    /**
     * API base URL. It must keep working for as long as this version is installed anywhere: the platform's
     * rule is never to change or retire it (guide section 3). A site can redirect it with the filter.
     */
    const API_URL = 'https://audience.acowebs.com';
    /** Version of the UI kit stylesheet in assets/acowebs/ (cache key of the shared handle). */
    const KIT_VERSION = '1.0.0';
    const STYLE_HANDLE = 'acowebs-feedback-ui';
    /** Free's own look on top of the kit (assets/acowebs/wcpa-feedback.css). */
    const THEME_HANDLE = 'wcpa-feedback';
    const SCRIPT_HANDLE = 'wcpa-feedback';
    const NONCE = 'wcpa_feedback';
    /** User meta shared by every Acowebs plugin: the user dismissed the newsletter notice or subscribed. */
    const OPTIN_META = 'acowebs_optin_done';
    /** Option: when this code first evaluated the notice on Free's screen (Unix time). */
    const SINCE_OPTION = 'wcpa_feedback_since';
    const NOTICE_DELAY = 14 * DAY_IN_SECONDS;
    /** Free's admin page (menu slug wcpa-admin-ui); Forms, Designs and Settings are hash routes of it. */
    const SCREEN = 'toplevel_page_wcpa-admin-ui';
    const FORUM_URL = 'https://wordpress.org/support/plugin/woo-custom-product-addons/';
    const PRIVACY_URL = 'https://acowebs.com/privacy-policy/';
    /** Browser cap: deactivate this long after Send even if admin-ajax has not answered (feedback.js). */
    const BROWSER_WAIT_MS = 3000;
    /** Server timeout of the deactivation request; below BROWSER_WAIT_MS so admin-ajax answers in time. */
    const DEACTIVATION_TIMEOUT = 2;
    const SUBSCRIBE_TIMEOUT = 5;

    /** @var bool|null Cached answer of notice_due() for this request. */
    private static $noticeDue = null;

    public static function register()
    {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('admin_footer', [__CLASS__, 'render_popup']);
        add_action('admin_notices', [__CLASS__, 'render_notice']);
        add_action('wp_ajax_wcpa_deactivation_feedback', [__CLASS__, 'ajax_deactivation']);
        add_action('wp_ajax_wcpa_optin_subscribe', [__CLASS__, 'ajax_subscribe']);
        add_action('wp_ajax_wcpa_optin_dismiss', [__CLASS__, 'ajax_dismiss']);
    }

    /**
     * The deactivation reasons: key => [English label sent to the API, translated label, placeholder of
     * the details box (null: no box), whether the box offers the support forum].
     * The first six are the kit's shared reasons; "pro" is Free's own (owner Q7).
     */
    public static function reasons()
    {
        return [
            'not_working' => ["I couldn't get it to work", __("I couldn't get it to work", 'woo-custom-product-addons'),
                __('What went wrong? Any error message helps.', 'woo-custom-product-addons'), true],
            'better' => ['I found a better plugin', __('I found a better plugin', 'woo-custom-product-addons'),
                __('Which plugin did you switch to? What does it do better?', 'woo-custom-product-addons'), false],
            'pro' => ["I'm switching to the Pro version", __("I'm switching to the Pro version", 'woo-custom-product-addons'), null, false],
            'feature' => ["It's missing a feature I need", __("It's missing a feature I need", 'woo-custom-product-addons'),
                __('Which feature were you looking for?', 'woo-custom-product-addons'), false],
            'broke' => ['It broke my site or conflicts with another plugin', __('It broke my site or conflicts with another plugin', 'woo-custom-product-addons'),
                __('What broke, or which plugin does it conflict with?', 'woo-custom-product-addons'), true],
            'short' => ['I only needed it for a short time', __('I only needed it for a short time', 'woo-custom-product-addons'),
                __('Thanks! Anything we could improve?', 'woo-custom-product-addons'), false],
            'other' => ['Other', __('Other', 'woo-custom-product-addons'),
                __('Please tell us a little more.', 'woo-custom-product-addons'), false],
        ];
    }

    /** API base URL without a trailing slash; `acowebs_feedback_api_url` is shared with the other Acowebs plugins. */
    public static function api_url()
    {
        return untrailingslashit((string)apply_filters('acowebs_feedback_api_url', self::API_URL));
    }

    /** True on the site plugins screen when the current user can deactivate Free there. */
    private static function popup_due()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'plugins' || !current_user_can('activate_plugins')) {
            return false;
        }
        // Network-activated: a site's plugins screen has no Deactivate link for Free; nothing to attach to.
        if (is_multisite()) {
            if (!function_exists('is_plugin_active_for_network')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (is_plugin_active_for_network(plugin_basename(WCPA_FILE))) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when the newsletter notice shows on this request. The first evaluation on Free's screen stores
     * the start of the 14-day wait; dismissing or subscribing (in any Acowebs plugin) ends it for that user.
     *
     * | Situation                                              | Shows |
     * | ------------------------------------------------------ | ----- |
     * | Another screen, or user without manage_woocommerce     | no    |
     * | First visit after the update or install                | no (wait starts) |
     * | Less than 14 days after that first visit               | no    |
     * | 14 days or more, user never dismissed or subscribed    | yes   |
     * | User meta acowebs_optin_done set (any Acowebs plugin)  | no    |
     */
    public static function notice_due()
    {
        if (self::$noticeDue !== null) {
            return self::$noticeDue;
        }
        self::$noticeDue = false;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== self::SCREEN || !current_user_can('manage_woocommerce')) {
            return false;
        }
        if (get_user_meta(get_current_user_id(), self::OPTIN_META, true)) {
            return false;
        }
        $since = (int)get_option(self::SINCE_OPTION, 0);
        if ($since <= 0) {
            add_option(self::SINCE_OPTION, time(), '', false);
            return false;
        }
        self::$noticeDue = (time() - $since) >= self::NOTICE_DELAY;
        return self::$noticeDue;
    }

    /** Stylesheet and script, only where the popup or the notice shows. */
    public static function enqueue()
    {
        if (!self::popup_due() && !self::notice_due()) {
            return;
        }
        wp_enqueue_style(self::STYLE_HANDLE, WCPA_ASSETS_URL . 'acowebs/acowebs-feedback-ui.css', [], self::KIT_VERSION);
        // Free's own look (owner 2026-11-11: "match the ui styles that of our plugin") and its support line,
        // scoped to Free's popup and notice ids and loaded after the shared sheet, whichever plugin's copy
        // of it WordPress loaded: other Acowebs plugins' popups keep the kit's look.
        wp_enqueue_style(self::THEME_HANDLE, WCPA_ASSETS_URL . 'acowebs/wcpa-feedback.css', [self::STYLE_HANDLE], WCPA_VERSION);
        wp_enqueue_script(self::SCRIPT_HANDLE, WCPA_ASSETS_URL . 'acowebs/feedback.js', [], WCPA_VERSION, true);
        wp_localize_script(self::SCRIPT_HANDLE, 'wcpaFeedback', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'plugin' => plugin_basename(WCPA_FILE),
            'wait' => self::BROWSER_WAIT_MS,
            'i18n' => [
                'subscribing' => __('Subscribing…', 'woo-custom-product-addons'),
                'error' => __('Sorry, we could not subscribe you right now. Please try again later.', 'woo-custom-product-addons'),
            ],
        ]);
    }

    /** The plugin's name as the plugins list shows it, for the popup title and the notice text. */
    private static function display_name()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $base = plugin_basename(WCPA_FILE);
        return isset($plugins[$base]['Name']) && $plugins[$base]['Name'] !== '' ? $plugins[$base]['Name'] : 'WooCommerce Custom Product Addons Free';
    }

    /** Prints the Acowebs mark (kit markup), decorative. Static markup, so nothing to escape but the class. */
    private static function print_badge($class = 'acofb-badge')
    {
        ?><span class="<?php echo esc_attr($class); ?>" aria-hidden="true"><svg viewBox="-8 -1.5 86 86" focusable="false"><path fill="#00DFB1" fill-rule="evenodd" d="M2.85642 22.0022C6.72119 18.0569 13.0525 17.9916 16.9978 21.8564L41.4978 45.8564C43.4052 47.7249 44.4861 50.2785 44.4999 52.9485C44.5136 55.6185 43.4591 58.1831 41.5711 60.0711L21.5711 80.0711C17.6658 83.9763 11.3342 83.9763 7.42894 80.0711C3.52369 76.1658 3.52369 69.8342 7.42894 65.9289L20.2846 53.0733L3.0022 36.1436C-0.943088 32.2788 -1.00836 25.9475 2.85642 22.0022Z"/><path fill="#00B2FF" fill-rule="evenodd" d="M60.1791 3.124C64.5289 7.36505 64.6171 14.3293 60.376 18.6791L48.4585 30.9022L65.7782 48.2218C70.0739 52.5176 70.0739 59.4824 65.7782 63.7782C61.4824 68.0739 54.5176 68.0739 50.2218 63.7782L25.2218 38.7782C20.9645 34.5208 20.9209 27.6318 25.124 23.3209L44.624 3.3209C48.8651 -1.02889 55.8293 -1.11705 60.1791 3.124Z"/><path fill="#0A84FF" fill-rule="evenodd" d="M43.5968 57.1543L25.2218 38.7786C22.1371 35.6937 21.2645 31.2271 22.6168 27.3607L41.4978 45.8572C43.4052 47.7257 44.4861 50.2794 44.4999 52.9495C44.5074 54.4143 44.1935 55.8473 43.5968 57.1543Z"/></svg></span><?php
    }

    /** Prints the lock icon of the kit's privacy lines, decorative (static markup). */
    private static function print_lock()
    {
        ?><svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 1a3.5 3.5 0 0 0-3.5 3.5V6H4a1.5 1.5 0 0 0-1.5 1.5v6A1.5 1.5 0 0 0 4 15h8a1.5 1.5 0 0 0 1.5-1.5v-6A1.5 1.5 0 0 0 12 6h-.5V4.5A3.5 3.5 0 0 0 8 1Zm2 5V4.5a2 2 0 1 0-4 0V6h4Z"/></svg><?php
    }

    /** Prints a link that opens in a new tab, with the kit's screen-reader note. */
    private static function print_new_tab_link($url, $text)
    {
        printf('<a class="acofb-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="acofb-sr"> %3$s</span></a>',
            esc_url($url), esc_html($text), esc_html__('(opens in a new tab)', 'woo-custom-product-addons'));
    }

    /** Prints the popup (kit deactivation-modal.html), hidden, on the plugins screen. */
    public static function render_popup()
    {
        if (!self::popup_due()) {
            return;
        }
        $user = wp_get_current_user();
        $name = self::display_name();
        ?>
        <div class="acofb acofb-overlay" id="acofb-wcpa-deactivation" data-acofb="deactivation" hidden>
            <div class="acofb-modal" role="dialog" aria-modal="true" aria-labelledby="acofb-wcpa-dx-title" aria-describedby="acofb-wcpa-dx-desc">
                <form class="acofb-modal-form" novalidate>
                    <div class="acofb-modal-head">
                        <?php self::print_badge(); ?>
                        <div>
                            <h2 class="acofb-title" id="acofb-wcpa-dx-title"><?php
                                /* translators: %s: the plugin's name as shown in the plugins list */
                                echo esc_html(sprintf(__('Quick question before you deactivate %s', 'woo-custom-product-addons'), $name)); ?></h2>
                            <p class="acofb-sub" id="acofb-wcpa-dx-desc"><?php esc_html_e('If you have a moment, tell us why. The Acowebs team reads every answer and uses it to improve the plugin.', 'woo-custom-product-addons'); ?></p>
                        </div>
                    </div>
                    <div class="acofb-modal-body">
                        <fieldset class="acofb-reasons">
                            <legend class="acofb-sr"><?php
                                /* translators: %s: the plugin's name as shown in the plugins list */
                                echo esc_html(sprintf(__('Why are you deactivating %s?', 'woo-custom-product-addons'), $name)); ?></legend>
                            <?php $i = 0;
                            foreach (self::reasons() as $key => list($english, $label, $placeholder, $help)) {
                                $i++; ?>
                                <div class="acofb-reason">
                                    <input class="acofb-radio" type="radio" name="reason" id="acofb-wcpa-r<?php echo (int)$i; ?>" value="<?php echo esc_attr($key); ?>" required>
                                    <label class="acofb-reason-card" for="acofb-wcpa-r<?php echo (int)$i; ?>"><?php echo esc_html($label); ?></label>
                                    <?php if ($placeholder !== null) { ?>
                                        <div class="acofb-details<?php echo $help ? ' wcpa-acofb-has-help' : ''; ?>">
                                            <label class="acofb-sr" for="acofb-wcpa-d<?php echo (int)$i; ?>"><?php esc_html_e('Tell us more (optional)', 'woo-custom-product-addons'); ?></label>
                                            <textarea class="acofb-textarea" id="acofb-wcpa-d<?php echo (int)$i; ?>" name="reason_details" rows="3" maxlength="5000" placeholder="<?php echo esc_attr($placeholder); ?>"></textarea>
                                            <?php if ($help) { ?>
                                                <p class="acofb-msg wcpa-acofb-help"><?php esc_html_e('Need help?', 'woo-custom-product-addons'); ?>
                                                    <?php self::print_new_tab_link(self::FORUM_URL, __('Ask in the support forum', 'woo-custom-product-addons')); ?></p>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </fieldset>
                        <div class="acofb-contact">
                            <input class="acofb-check" type="checkbox" name="consent" id="acofb-wcpa-contact" value="1">
                            <label class="acofb-check-label" for="acofb-wcpa-contact"><?php esc_html_e('Acowebs can contact me about this feedback', 'woo-custom-product-addons'); ?></label>
                            <div class="acofb-contact-fields">
                                <div class="acofb-field">
                                    <label class="acofb-label" for="acofb-wcpa-name"><?php esc_html_e('Name', 'woo-custom-product-addons'); ?></label>
                                    <input class="acofb-input" type="text" id="acofb-wcpa-name" name="name" value="<?php echo esc_attr($user->display_name); ?>" maxlength="255" autocomplete="name">
                                </div>
                                <div class="acofb-field">
                                    <label class="acofb-label" for="acofb-wcpa-email"><?php esc_html_e('Email', 'woo-custom-product-addons'); ?></label>
                                    <input class="acofb-input" type="email" id="acofb-wcpa-email" name="email" value="<?php echo esc_attr($user->user_email); ?>" maxlength="320" autocomplete="email">
                                </div>
                            </div>
                        </div>
                        <p class="acofb-privacy">
                            <?php self::print_lock(); ?>
                            <span><?php esc_html_e('We only send this if you click Send: your answer, your site address, name and language, the plugin version and your WordPress, WooCommerce and PHP versions. Your name and email are included only while the box above is ticked.', 'woo-custom-product-addons'); ?>
                                <?php self::print_new_tab_link(self::PRIVACY_URL, __('Privacy policy', 'woo-custom-product-addons')); ?></span>
                        </p>
                    </div>
                    <div class="acofb-modal-foot">
                        <button type="submit" class="acofb-btn acofb-btn--primary acofb-btn--send" data-acofb-action="send"><?php esc_html_e('Send & deactivate', 'woo-custom-product-addons'); ?></button>
                        <button type="button" class="acofb-btn acofb-btn--text" data-acofb-action="skip"><?php esc_html_e('Skip & deactivate', 'woo-custom-product-addons'); ?></button>
                        <button type="button" class="acofb-btn acofb-btn--text acofb-btn--cancel" data-acofb-action="cancel"><?php esc_html_e('Cancel', 'woo-custom-product-addons'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Prints the newsletter notice (kit newsletter-notice.html) with the other admin notices on Free's screen.
     * The wrapper is deliberately not a `.notice`: WordPress's common.js moves `.notice` elements next to the
     * first heading of the page, which on Free's screen is inside the React app that re-renders it.
     */
    public static function render_notice()
    {
        if (!self::notice_due()) {
            return;
        }
        $user = wp_get_current_user();
        $chips = [
            'blue' => __('New plugin launches', 'woo-custom-product-addons'),
            'mint' => __('Feature updates', 'woo-custom-product-addons'),
            'cyan' => __('WooCommerce tips', 'woo-custom-product-addons'),
            'gold' => __('Subscriber-only offers', 'woo-custom-product-addons'),
        ];
        ?>
        <div class="wcpa-acofb-notice-wrap">
            <div class="acofb acofb-notice" id="acofb-wcpa-newsletter" data-acofb="newsletter">
                <button type="button" class="acofb-close" data-acofb-action="dismiss" aria-label="<?php esc_attr_e('Dismiss', 'woo-custom-product-addons'); ?>">
                    <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
                </button>
                <div class="acofb-notice-body">
                    <div class="acofb-intro">
                        <?php self::print_badge('acofb-badge acofb-badge--lg'); ?>
                        <div class="acofb-intro-copy">
                            <p class="acofb-eyebrow"><?php esc_html_e('Acowebs · WooCommerce plugins', 'woo-custom-product-addons'); ?></p>
                            <h2 class="acofb-headline" id="acofb-wcpa-nl-title"><?php esc_html_e('Get new Acowebs plugins and features first', 'woo-custom-product-addons'); ?></h2>
                            <p class="acofb-text"><?php
                                /* translators: %s: the plugin's name as shown in the plugins list */
                                echo esc_html(sprintf(__('Thanks for using %s! Join our newsletter to hear about new plugins, feature updates and subscriber-only offers.', 'woo-custom-product-addons'), self::display_name())); ?></p>
                            <ul class="acofb-chips" aria-label="<?php esc_attr_e('What you get', 'woo-custom-product-addons'); ?>">
                                <?php foreach ($chips as $colour => $chip) { ?>
                                    <li class="acofb-chip acofb-chip--<?php echo esc_attr($colour); ?>"><?php echo esc_html($chip); ?></li>
                                <?php } ?>
                            </ul>
                        </div>
                    </div>
                    <form class="acofb-panel" aria-labelledby="acofb-wcpa-nl-title" novalidate>
                        <div class="acofb-field-row">
                            <div class="acofb-field">
                                <label class="acofb-label" for="acofb-wcpa-nl-name"><?php esc_html_e('First name', 'woo-custom-product-addons'); ?> <span class="acofb-optional"><?php esc_html_e('(optional)', 'woo-custom-product-addons'); ?></span></label>
                                <input class="acofb-input" type="text" id="acofb-wcpa-nl-name" name="name" maxlength="255" autocomplete="given-name">
                            </div>
                            <div class="acofb-field">
                                <label class="acofb-label" for="acofb-wcpa-nl-phone"><?php esc_html_e('Phone', 'woo-custom-product-addons'); ?> <span class="acofb-optional"><?php esc_html_e('(optional)', 'woo-custom-product-addons'); ?></span></label>
                                <input class="acofb-input" type="tel" id="acofb-wcpa-nl-phone" name="phone" maxlength="40" autocomplete="tel">
                            </div>
                        </div>
                        <div class="acofb-field">
                            <label class="acofb-label" for="acofb-wcpa-nl-email"><?php esc_html_e('Email', 'woo-custom-product-addons'); ?></label>
                            <input class="acofb-input" type="email" id="acofb-wcpa-nl-email" name="email" value="<?php echo esc_attr($user->user_email); ?>" maxlength="320" autocomplete="email" required>
                        </div>
                        <div class="acofb-actions-row">
                            <button type="submit" class="acofb-btn acofb-btn--primary"><?php esc_html_e('Subscribe', 'woo-custom-product-addons'); ?> <span class="acofb-btn-arrow" aria-hidden="true">→</span></button>
                            <button type="button" class="acofb-btn acofb-btn--text" data-acofb-action="dismiss"><?php esc_html_e('Not now', 'woo-custom-product-addons'); ?></button>
                        </div>
                        <p class="acofb-msg" role="status" aria-live="polite"></p>
                        <p class="acofb-privacy">
                            <?php self::print_lock(); ?>
                            <span><?php esc_html_e('About two emails a month from Acowebs: new plugins, updates and offers. We never sell or share your email, and you can unsubscribe anytime.', 'woo-custom-product-addons'); ?>
                                <?php self::print_new_tab_link(self::PRIVACY_URL, __('Privacy policy', 'woo-custom-product-addons')); ?></span>
                        </p>
                    </form>
                </div>
                <div class="acofb-thanks" role="status" tabindex="-1" hidden>
                    <span class="acofb-thanks-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <div>
                        <p class="acofb-title"><?php esc_html_e("You're in!", 'woo-custom-product-addons'); ?></p>
                        <p class="acofb-sub"><?php esc_html_e('Watch your inbox for new plugins, updates and offers from Acowebs.', 'woo-custom-product-addons'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /** Site details sent with every deactivation (guide: Acowebs_Feedback_Client::site_info()). */
    public static function site_info()
    {
        return [
            'site_url' => home_url(),
            'site_name' => get_bloginfo('name'),
            'wp_version' => get_bloginfo('version'),
            // null is refused by the API for text fields: left out below when WooCommerce is inactive.
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : null,
            'php_version' => PHP_VERSION,
            'site_language' => get_locale(),
        ];
    }

    /** Cuts an already sanitized text value to $max characters (the API's field limits). */
    private static function cut($value, $max)
    {
        $value = is_string($value) ? $value : '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    /**
     * Builds the deactivation record from the popup's post. Returns null when there is nothing to send
     * (no known reason). Only a known reason key is accepted and sent as its English label.
     *
     * @param array $post The unslashed request fields (reason key, reason_details, consent, name, email).
     */
    public static function deactivation_payload(array $post)
    {
        $reasons = self::reasons();
        $key = isset($post['reason']) && is_string($post['reason']) ? $post['reason'] : '';
        if (!isset($reasons[$key])) {
            return null;
        }
        $consent = isset($post['consent']) && rest_sanitize_boolean($post['consent']);
        $payload = array_merge(self::site_info(), [
            'plugin' => self::PLUGIN_NAME,
            'edition' => self::EDITION,
            'plugin_slug' => dirname(plugin_basename(WCPA_FILE)),
            'plugin_version' => WCPA_VERSION,
            'reason' => $reasons[$key][0],
            'consent' => $consent,
        ]);
        $details = $reasons[$key][2] !== null && isset($post['reason_details']) ? $post['reason_details'] : '';
        if ($details !== '') {
            $payload['reason_details'] = $details;
        }
        // Second check behind the browser: name and email only with the contact box ticked.
        if ($consent) {
            if (isset($post['name']) && $post['name'] !== '') {
                $payload['name'] = $post['name'];
            }
            if (isset($post['email']) && is_email($post['email'])) {
                $payload['email'] = $post['email'];
            }
        }
        return array_filter($payload, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /** admin-ajax: the popup's Send. Always answers success; the API's answer is never shown. */
    public static function ajax_deactivation()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error([], 403);
        }
        $payload = self::deactivation_payload([
            // Read right after check_ajax_referer() above: each field unslashed, sanitized and cut to the API's limit.
            'reason' => isset($_POST['reason']) && is_string($_POST['reason']) ? sanitize_key(wp_unslash($_POST['reason'])) : '',
            'reason_details' => isset($_POST['reason_details']) ? self::cut(sanitize_textarea_field(wp_unslash($_POST['reason_details'])), 5000) : '',
            'consent' => isset($_POST['consent']) ? sanitize_text_field(wp_unslash($_POST['consent'])) : '',
            'name' => isset($_POST['name']) ? self::cut(sanitize_text_field(wp_unslash($_POST['name'])), 255) : '',
            'email' => isset($_POST['email']) && is_string($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
        ]);
        // Skip silently when the record would be refused (no reason, or no site address).
        if ($payload !== null && !empty($payload['site_url'])) {
            self::request('/v1/deactivation', $payload, self::DEACTIVATION_TIMEOUT);
        }
        wp_send_json_success();
    }

    /**
     * One POST to the API. Never throws and never prints: a WP_Error, an error status or an exception
     * is returned to the caller (or null), which decides whether the user hears about it.
     *
     * @return array|\WP_Error|null wp_remote_post()'s result, or null after an exception.
     */
    public static function request($path, array $payload, $timeout)
    {
        try {
            return wp_remote_post(self::api_url() . $path, [
                'timeout' => $timeout,
                'redirection' => 0,
                'headers' => ['Content-Type' => 'application/json'],
                // A non-empty meta would go here as an object; Free sends none.
                'body' => wp_json_encode($payload),
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * admin-ajax: the notice's Subscribe. Waits for the API (up to 5 s) so the user learns whether it worked;
     * on success the shared flag hides the notice for good, on failure the form stays for a retry.
     */
    public static function ajax_subscribe()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'woo-custom-product-addons')], 403);
        }
        $email = isset($_POST['email']) && is_string($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'woo-custom-product-addons')], 422);
        }
        $payload = [
            'email' => $email,
            'list' => 'general',
            'source' => 'plugin-admin-notice',
            'plugin' => self::PLUGIN_NAME,
            'edition' => self::EDITION,
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
            // The click on Subscribe, with the privacy line visible, is the consent (guide section 7).
            'consent' => true,
        ];
        $optional = [
            'name' => isset($_POST['name']) ? self::cut(sanitize_text_field(wp_unslash($_POST['name'])), 255) : '',
            'phone' => isset($_POST['phone']) ? self::cut(sanitize_text_field(wp_unslash($_POST['phone'])), 40) : '',
        ];
        foreach ($optional as $key => $value) {
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }
        $response = self::request('/v1/subscribe', $payload, self::SUBSCRIBE_TIMEOUT);
        $code = is_array($response) ? (int)wp_remote_retrieve_response_code($response) : 0;
        if ($code === 422) {
            $body = json_decode((string)wp_remote_retrieve_body($response), true);
            foreach ((is_array($body) && isset($body['issues']) && is_array($body['issues'])) ? $body['issues'] : [] as $issue) {
                if (is_array($issue) && ($issue['path'] ?? '') === 'phone') {
                    wp_send_json_error(['message' => __('Please enter a valid phone number, or leave it empty.', 'woo-custom-product-addons')], 422);
                }
            }
        }
        if ($code !== 200) {
            wp_send_json_error(['message' => __('Sorry, we could not subscribe you right now. Please try again later.', 'woo-custom-product-addons')], 502);
        }
        update_user_meta(get_current_user_id(), self::OPTIN_META, time());
        wp_send_json_success();
    }

    /** admin-ajax: X or Not now. The notice never shows again for this user, in any Acowebs plugin. */
    public static function ajax_dismiss()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([], 403);
        }
        update_user_meta(get_current_user_id(), self::OPTIN_META, time());
        wp_send_json_success();
    }
}
