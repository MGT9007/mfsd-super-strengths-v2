<?php
/**
 * Plugin Name: MFSD Super Strengths Cards
 * Description: Family card game — Extended (Phase A+B), Family Short (Phase A), or Snap mode.
 * Version: 3.11.0
 * Author: MisterT9007
 */

if (!defined('ABSPATH')) exit;

define('MFSD_SS_VERSION', '3.11.0');
define('MFSD_SS_PATH',    plugin_dir_path(__FILE__));
define('MFSD_SS_URL',     plugin_dir_url(__FILE__));

require_once MFSD_SS_PATH . 'includes/class-ss-db.php';
require_once MFSD_SS_PATH . 'includes/class-ss-validator.php';
require_once MFSD_SS_PATH . 'includes/class-ss-game.php';
require_once MFSD_SS_PATH . 'includes/class-ss-api.php';

final class MFSD_Super_Strengths {

    public static function instance() {
        static $i = null;
        return $i ?: $i = new self();
    }

    private function __construct() {
        register_activation_hook(__FILE__, ['MFSD_SS_DB', 'install']);
        add_action('init',          [$this, 'register_assets']);
        add_shortcode('mfsd_super_strengths', [$this, 'shortcode']);
        add_action('rest_api_init', ['MFSD_SS_API', 'register_routes']);
        add_action('admin_menu',    [$this, 'admin_menu']);

        add_action('mfsd_ss_timeout_check', ['MFSD_SS_Game', 'run_timeout_check']);
        if (!wp_next_scheduled('mfsd_ss_timeout_check')) {
            wp_schedule_event(time(), 'hourly', 'mfsd_ss_timeout_check');
        }
    }

    public function register_assets() {
        wp_register_style('mfsd-ss-css', MFSD_SS_URL . 'assets/mfsd-super-strengths.css', [], MFSD_SS_VERSION);
        wp_register_script('mfsd-ss-js',  MFSD_SS_URL . 'assets/mfsd-super-strengths.js',  [], MFSD_SS_VERSION, true);
    }

    public function shortcode() {
        if (!is_user_logged_in()) {
            return '<p class="ss-error">Please log in to play Super Strengths Cards.</p>';
        }

        wp_enqueue_style('mfsd-ss-css');
        wp_enqueue_script('mfsd-ss-js');

        $user_id   = get_current_user_id();
        $user      = get_userdata($user_id);
        $age       = (int) get_user_meta($user_id, 'mfsd_age', true);

        $ft_enabled = false;
        if ($age >= 13 && $age <= 14 && get_option('mfsd_ss_free_text_13_14') === '1') $ft_enabled = true;
        elseif ($age >= 11 && $age <= 12 && get_option('mfsd_ss_free_text_11_12') === '1') $ft_enabled = true;

        wp_localize_script('mfsd-ss-js', 'MFSD_SS_CFG', [
            'restUrl'              => rest_url('mfsd-ss/v1/'),
            'nonce'                => wp_create_nonce('wp_rest'),
            'userId'               => $user_id,
            'displayName'          => $user->display_name,
            'ftEnabled'            => $ft_enabled,
            'ftMax'                => (int) get_option('mfsd_ss_free_text_max', 2),
            'ftMinLen'             => (int) get_option('mfsd_ss_free_text_min_len', 3),
            'ftMaxLen'             => (int) get_option('mfsd_ss_free_text_max_len', 40),
            'cardsPerTarget'       => MFSD_SS_DB::CARDS_PER_TARGET,
            'gameMode'             => get_option('mfsd_ss_mode', 'full'),
            // Snap config — passed to JS for display (timer used for client-side countdown)
            'snapMode'             => get_option('mfsd_ss_snap_mode', 'quick_draw'),
            'snapTimer'            => (int) get_option('mfsd_ss_snap_timer', 3),
            'snapQuickDrawTarget'  => (int) get_option('mfsd_ss_snap_quick_draw_target', 5),
            'isMobileHint'         => wp_is_mobile(),
        ]);

        return '<div id="mfsd-ss-root"></div>';
    }

    public function admin_menu() {
        add_menu_page(
            'Super Strengths Cards', 'Super Strengths', 'manage_options',
            'mfsd-super-strengths', [$this, 'admin_page'],
            'dashicons-awards', 28
        );
    }

    public function admin_page() {
        require_once MFSD_SS_PATH . 'admin/admin-page.php';
    }
}

MFSD_Super_Strengths::instance();