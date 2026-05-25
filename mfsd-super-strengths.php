<?php
/**
 * Plugin Name: MFSD Super Strengths Cards
 * Description: Family card game — Extended (Phase A+B), Family Short (Phase A), or Memory mode.
 * Version: 5.5.2
 * Author: MisterT9007
 */

if (!defined('ABSPATH')) exit;

define('MFSD_SS_VERSION', '5.5.2');
define('MFSD_SS_PATH',    plugin_dir_path(__FILE__));
define('MFSD_SS_URL',     plugin_dir_url(__FILE__));

require_once MFSD_SS_PATH . 'includes/class-ss-db.php';
require_once MFSD_SS_PATH . 'includes/class-ss-validator.php';
require_once MFSD_SS_PATH . 'includes/class-ss-game.php';
require_once MFSD_SS_PATH . 'includes/class-ss-memory.php';
require_once MFSD_SS_PATH . 'includes/class-ss-api.php';
require_once MFSD_SS_PATH . 'includes/class-ss-summary.php';
require_once MFSD_SS_PATH . 'includes/class-ss-badges.php';
require_once MFSD_SS_PATH . 'includes/class-ss-demo.php';

final class MFSD_Super_Strengths {

    public static function instance() {
        static $i = null;
        return $i ?: $i = new self();
    }

    private function __construct() {
        register_activation_hook(__FILE__, ['MFSD_SS_DB', 'install']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade_db']);
        add_action('init',          [$this, 'register_assets']);
        add_shortcode('mfsd_super_strengths', [$this, 'shortcode']);
        add_action('rest_api_init', ['MFSD_SS_API', 'register_routes']);
        add_action('admin_menu',    [$this, 'admin_menu']);

        add_filter('stevegpt_plugin_integration_slots', [$this, 'register_stevegpt_slots']);
        add_action('mfsd_ss_turn_timeout_check', ['MFSD_SS_Memory', 'run_turn_timeout_check']);
        if (!wp_next_scheduled('mfsd_ss_turn_timeout_check')) {
            wp_schedule_event(time(), 'hourly', 'mfsd_ss_turn_timeout_check');
        }
    }

    public function activate() {
        // Deregister snap cron replaced by memory-game cron
        wp_clear_scheduled_hook('mfsd_ss_timeout_check');
    }

    public static function maybe_upgrade_db() {
        $installed = get_option('mfsd_ss_db_version', '0');
        if (version_compare($installed, MFSD_SS_VERSION, '<')) {
            MFSD_SS_DB::install();
            update_option('mfsd_ss_db_version', MFSD_SS_VERSION);
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

        // ── Ordering gate — students only ──────────────────────────────────
        if (function_exists('mfsd_get_task_status') && get_option('mfsd_ss_course_management', 1)) {
            $student_id  = get_current_user_id();
            $viewer_role = get_user_meta($student_id, 'mfsd_role', true) ?: '';

            // Only gate students — parents/teachers/admins always pass through
            if ($viewer_role === 'student') {
                $status = mfsd_get_task_status($student_id, 'super_strengths');

                if ($status === 'locked') {
                    if (function_exists('mfsd_ordering_locked_message')) {
                        return mfsd_ordering_locked_message('super_strengths');
                    }
                    return '<p style="text-align:center;padding:40px;color:#555;">This activity is not available yet. Please complete the previous activity first.</p>';
                }

                if ($status === 'available') {
                    mfsd_set_task_status($student_id, 'super_strengths', 'in_progress');
                }
            }
        }
        // ── End ordering gate ──────────────────────────────────────────────

        wp_enqueue_style('mfsd-ss-css');
        wp_enqueue_script('mfsd-ss-js');

        $user_id   = get_current_user_id();
        $user      = get_userdata($user_id);
        $age       = (int) get_user_meta($user_id, 'mfsd_age', true);

        $ft_enabled = false;
        if ($age >= 13 && $age <= 14 && get_option('mfsd_ss_free_text_13_14') === '1') $ft_enabled = true;
        elseif ($age >= 11 && $age <= 12 && get_option('mfsd_ss_free_text_11_12') === '1') $ft_enabled = true;

        $steve_avatar_url = '';
        if (class_exists('SteveGPT_Chatbot')) {
            $avatar_sources = array_filter([
                get_option('mfsd_stevegpt_map_ss_welcome_chat', ''),
                get_option('mfsd_stevegpt_map_ss_demo_chat', ''),
                get_option('mfsd_stevegpt_map_ss_welcome_intro', ''),
            ]);
            foreach ($avatar_sources as $chatbot_id) {
                try {
                    $chatbot_app = SteveGPT_Chatbot::get($chatbot_id)->get_config()['appearance'] ?? [];
                    $url = $chatbot_app['avatar_image'] ?? '';
                    if ($url) { $steve_avatar_url = $url; break; }
                } catch (\Exception $e) {}
            }
        }

        // Build welcome chat context — game-specific info only.
        // Name and age come from SteveGPT's own student context (content_aware), sourced
        // from DOB meta. We must NOT duplicate those fields here or we risk sending a
        // stale/zero mfsd_age value that conflicts with the student context age.
        $demo_enabled  = get_option('mfsd_ss_demo_mode_enabled', '0') === '1';
        $game_mode_str = $demo_enabled ? 'demo' : get_option('mfsd_ss_mode', 'full');
        $ctx_parts     = [
            "The student is currently playing the Super Strengths card game.",
            "Game mode: {$game_mode_str}.",
        ];
        if ($demo_enabled) {
            $demo_secs   = (int) get_option('mfsd_ss_demo_time_limit_mins', 3) * 60;
            $ctx_parts[] = "Demo time limit: {$demo_secs} seconds. Board: 20 tiles (5 student picks + 5 Steve picks, each duplicated into pairs).";
        }
        $welcome_chat_context = implode(' ', $ctx_parts);

        wp_localize_script('mfsd-ss-js', 'MFSD_SS_CFG', [
            'restUrl'              => rest_url('mfsd-ss/v1/'),
            'nonce'                => wp_create_nonce('wp_rest'),
            'userId'               => $user_id,
            'displayName'          => $user->display_name,
            'playerAge'            => $age,
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
            'badgesUrl'            => home_url('/badges/'),
            'portalUrl'            => home_url('/about/parent-portal-home/'),
            'courseId'             => 1,
            // Memory game (v5) config
            'memoryMode'           => get_option('mfsd_ss_memory_mode', 'first_to_x'),
            'targetMatches'        => (int) get_option('mfsd_ss_memory_target_matches', 5),
            'timeLimitMins'        => (int) get_option('mfsd_ss_memory_time_limit', 5),
            'turnTimeoutMins'      => (int) get_option('mfsd_ss_turn_timeout_mins', 5),
            'demoModeEnabled'      => get_option('mfsd_ss_demo_mode_enabled', '0') === '1',
            'demoTimeLimitMins'    => (int) get_option('mfsd_ss_demo_time_limit_mins', 3),
            'welcomeIntroChatbotId'       => get_option('mfsd_stevegpt_map_ss_welcome_intro', ''),
            'welcomeChatChatbotId'        => get_option('mfsd_stevegpt_map_ss_welcome_chat', ''),
            'studentSummaryChatbotId'     => get_option('mfsd_stevegpt_map_ss_student_summary_chat', ''),
            'parentSummaryChatbotId'      => get_option('mfsd_stevegpt_map_ss_parent_summary_chat', ''),
            'demoChatbotId'               => get_option('mfsd_stevegpt_map_ss_demo_chat', ''),
            'steveAvatarUrl'              => $steve_avatar_url,
            'studentAvatarUrl'            => function_exists('mfsd_get_user_avatar_url') ? mfsd_get_user_avatar_url($user_id) : (get_avatar_url($user_id, ['size' => 80]) ?: ''),
            'welcomeChatContext'          => $welcome_chat_context,
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

    public function register_stevegpt_slots(array $slots): array {
        $slots[] = [
            'plugin' => 'Super Strengths',
            'role'   => 'Student summary',
            'option' => 'mfsd_stevegpt_map_ss_student_summary',
            'tokens' => ['student_name', 'student_age', 'student_self_strengths', 'family_voted_strengths', 'student_sees_parent_strengths', 'lens_context', 'personality_context', 'word_assoc_context'],
        ];
        $slots[] = [
            'plugin' => 'Super Strengths',
            'role'   => 'Parent summary',
            'option' => 'mfsd_stevegpt_map_ss_parent_summary',
            'tokens' => ['parent_name', 'student_name', 'student_age', 'student_self_strengths', 'family_voted_strengths', 'student_sees_parent_strengths', 'personality_context', 'word_assoc_context'],
        ];
        $slots[] = [
            'plugin' => 'Super Strengths',
            'role'   => 'Student summary chat',
            'option' => 'mfsd_stevegpt_map_ss_student_summary_chat',
            'tokens' => [],
        ];
        $slots[] = [
            'plugin' => 'Super Strengths',
            'role'   => 'Parent summary chat',
            'option' => 'mfsd_stevegpt_map_ss_parent_summary_chat',
            'tokens' => [],
        ];
        $slots[] = [
            'plugin' => 'Super Strengths',
            'role'   => 'Demo picker',
            'option' => 'mfsd_stevegpt_map_ss_demo_picker',
            'tokens' => ['student_name', 'student_age', 'student_self_strengths', 'strengths_library', 'lens_agreements', 'lens_differences_count', 'lens_differences', 'lens_summary', 'word_assoc_top', 'personality_type', 'personality_label'],
        ];
        $slots[] = [
            'plugin' => 'Super Strengths',
            'role'   => 'Demo summary',
            'option' => 'mfsd_stevegpt_map_ss_demo_summary',
            'tokens' => ['student_name', 'student_age', 'student_self_strengths', 'pick_1_text', 'pick_1_source', 'pick_1_rationale', 'pick_2_text', 'pick_2_source', 'pick_2_rationale', 'pick_3_text', 'pick_3_source', 'pick_3_rationale', 'pick_4_text', 'pick_4_source', 'pick_4_rationale', 'pick_5_text', 'pick_5_source', 'pick_5_rationale', 'shared_strengths', 'hidden_strengths', 'pairs_matched', 'total_pairs'],
        ];
        $slots[] = [
            'plugin' => 'Super Strengths',
            'role'   => 'Demo chat',
            'option' => 'mfsd_stevegpt_map_ss_demo_chat',
            'tokens' => [],
        ];
        return $slots;
    }
}

MFSD_Super_Strengths::instance();