<?php
/**
 * MFSD Super Strengths Cards — Admin Interface
 * Included by MFSD_Super_Strengths::admin_page()
 * Handles all POST/GET actions itself before rendering the tabbed UI.
 */

if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) return;

global $wpdb;
$sp = $wpdb->prefix . MFSD_SS_DB::TBL_STRENGTHS;
$bp = $wpdb->prefix . MFSD_SS_DB::TBL_BANNED;
$fl = $wpdb->prefix . MFSD_SS_DB::TBL_FLAGS;
$gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
$pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
$cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
$tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
$vp = $wpdb->prefix . MFSD_SS_DB::TBL_VOTES;

$notice = '';

// =============================================================================
// HANDLE POST ACTIONS
// =============================================================================

// ── Save configuration ────────────────────────────────────────────────────────
if (isset($_POST['ss_save_config']) && check_admin_referer('mfsd_ss_config')) {
    update_option('mfsd_ss_free_text_11_12',   isset($_POST['ft_11_12']) ? '1' : '0');
    update_option('mfsd_ss_free_text_13_14',   isset($_POST['ft_13_14']) ? '1' : '0');
    update_option('mfsd_ss_free_text_max',     max(1, min(5, (int)($_POST['ft_max'] ?? 2))));
    update_option('mfsd_ss_free_text_min_len', max(2, (int)($_POST['ft_min_len'] ?? 3)));
    update_option('mfsd_ss_free_text_max_len', min(80, (int)($_POST['ft_max_len'] ?? 40)));
    // Memory game settings
    update_option('mfsd_ss_card_pool',             sanitize_text_field($_POST['card_pool'] ?? 'family_cards'));
    update_option('mfsd_ss_memory_mode',           sanitize_text_field($_POST['memory_mode'] ?? 'first_to_x'));
    update_option('mfsd_ss_memory_target_matches', max(1, (int)($_POST['memory_target_matches'] ?? 5)));
    update_option('mfsd_ss_memory_time_limit',     max(1, (int)($_POST['memory_time_limit'] ?? 5)));
    update_option('mfsd_ss_turn_timeout_mins',     max(1, (int)($_POST['turn_timeout_mins'] ?? 5)));
    update_option('mfsd_ss_turn_warning_secs',     max(0, (int)($_POST['turn_warning_secs'] ?? 60)));
    update_option('mfsd_ss_demo_mode_enabled',     isset($_POST['demo_mode_enabled']) ? '1' : '0');
    update_option('mfsd_ss_demo_time_limit_mins',  max(1, (int)($_POST['demo_time_limit_mins'] ?? 3)));
    // Memory game SteveGPT slots
    $sg_keys = ['ss_welcome_intro','ss_welcome_chat','ss_student_summary','ss_parent_summary','ss_student_summary_chat','ss_parent_summary_chat','ss_demo_picker','ss_demo_summary','ss_demo_chat'];
    foreach ($sg_keys as $k) {
        update_option('mfsd_stevegpt_map_' . $k, sanitize_text_field($_POST['sg_' . $k] ?? ''));
    }
    $notice = ['success', 'Configuration saved.'];
}

// ── Create new game ───────────────────────────────────────────────────────────
if (isset($_POST['ss_create_game']) && check_admin_referer('mfsd_ss_create_game')) {
    $new_mode = sanitize_text_field($_POST['new_game_mode'] ?? 'full');
    $new_rl   = max(1, (int)($_POST['new_round_limit'] ?? 3));
    $wpdb->insert($gp, [
        'status'             => 'submission',
        'mode'               => $new_mode,
        'round_limit'        => $new_rl,
        'turn_timeout_hours' => (int) get_option('mfsd_ss_turn_timeout', 24),
        'vote_timeout_hours' => (int) get_option('mfsd_ss_vote_timeout', 24),
    ]);
    $notice = ['success', 'Game #' . $wpdb->insert_id . ' created. Add players below.'];
}

// ── Add player to game ────────────────────────────────────────────────────────
if (isset($_POST['ss_add_player']) && check_admin_referer('mfsd_ss_add_player')) {
    $gid  = (int)($_POST['player_game_id'] ?? 0);
    $uid  = (int)($_POST['player_user_id'] ?? 0);
    $role = sanitize_text_field($_POST['player_role'] ?? 'parent');
    $user = $uid ? get_userdata($uid) : null;

    if ($user && $gid) {
        // Check game exists and is in submission phase
        $game_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $gp WHERE id = %d", $gid));
        if ($game_status !== 'submission') {
            $notice = ['error', 'Players can only be added during the submission phase.'];
        } elseif ($wpdb->get_var($wpdb->prepare("SELECT id FROM $pp WHERE game_id = %d AND user_id = %d", $gid, $uid))) {
            $notice = ['error', 'That user is already in game #' . $gid . '.'];
        } else {
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pp WHERE game_id = %d", $gid));
            if ($count >= 6) {
                $notice = ['error', 'Maximum 6 players per game.'];
            } else {
                $wpdb->insert($pp, [
                    'game_id'      => $gid,
                    'user_id'      => $uid,
                    'display_name' => $user->display_name,
                    'role'         => $role,
                ]);
                $notice = ['success', esc_html($user->display_name) . ' added to game #' . $gid . '.'];
            }
        }
    } else {
        $notice = ['error', 'Invalid user or game ID.'];
    }
}

// ── Reset game ────────────────────────────────────────────────────────────────
if (isset($_POST['ss_reset_game']) && check_admin_referer('mfsd_ss_reset')) {
    $gid = (int)($_POST['reset_game_id'] ?? 0);
    if ($gid) {
        $wpdb->query($wpdb->prepare("DELETE FROM $vp WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $tp WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $cp WHERE game_id = %d", $gid));

        // Clean up snap sessions, hands and claims for this game
        $ssp = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $shp = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;
        $scp = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_CLAIMS;
        $old_session_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $ssp WHERE game_id = %d", $gid));
        foreach ($old_session_ids as $sid) {
            $wpdb->delete($shp, ['session_id' => (int)$sid]);
            $wpdb->delete($scp, ['session_id' => (int)$sid]);
        }
        $wpdb->delete($ssp, ['game_id' => $gid]);
        $wpdb->query($wpdb->prepare(
            "UPDATE $pp SET score_total = 0, confidence_tokens = 2,
             submission_status = 'pending', submitted_at = NULL, turn_order = NULL
             WHERE game_id = %d",
            $gid
        ));
        $wpdb->update($gp, [
            'status'             => 'submission',
            'current_turn_id'    => null,
            'mode'               => get_option('mfsd_ss_mode', 'full'),
            'round_limit'        => (int) get_option('mfsd_ss_round_limit', 3),
            'turn_timeout_hours' => (int) get_option('mfsd_ss_turn_timeout', 24),
            'vote_timeout_hours' => (int) get_option('mfsd_ss_vote_timeout', 24),
        ], ['id' => $gid]);
        $notice = ['success', 'Game #' . $gid . ' has been reset to submission phase.'];
    }
}

// ── Reset v5 memory / demo game ───────────────────────────────────────────────
if (isset($_POST['ss_reset_sm_game']) && check_admin_referer('mfsd_ss_reset_sm')) {
    $gid = (int)($_POST['reset_sm_game_id'] ?? 0);
    if ($gid) {
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $smp  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        $smss = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SELF_STRENGTHS;
        $smc  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_CARDS;
        $smb  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_BOARD;
        $smt  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_TURNS;
        $smsu = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SUMMARIES;
        $smdr = $wpdb->prefix . MFSD_SS_DB::TBL_SM_DEMO_RATIONALE;

        $game_row   = $wpdb->get_row($wpdb->prepare("SELECT student_user_id, game_type FROM $smg WHERE id = %d", $gid), ARRAY_A);
        $student_id = $game_row ? (int)$game_row['student_user_id'] : 0;

        $wpdb->query($wpdb->prepare("DELETE FROM $smdr WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $smsu WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $smt  WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $smb  WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $smc  WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $smss WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $smp  WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $smg  WHERE id = %d", $gid));

        // Reset task progress — mfsd_set_task_status() only accepts 'in_progress'/'completed'
        // and refuses to downgrade from 'completed', so we delete the row directly.
        if ($student_id) {
            $wpdb->delete(
                $wpdb->prefix . 'mfsd_task_progress',
                ['student_id' => $student_id, 'task_slug' => 'super_strengths'],
                ['%d', '%s']
            );
        }

        // Remove all SS badges and wallet entries for this student (testing/support reset)
        if ($student_id) {
            $badge_table = $wpdb->prefix . 'mfsd_badges';
            if ($wpdb->get_var("SHOW TABLES LIKE '$badge_table'") === $badge_table) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $badge_table WHERE student_id = %d AND (badge_slug LIKE %s OR badge_slug = %s)",
                    $student_id,
                    $wpdb->esc_like('badge_ss_') . '%',
                    'badge_super_strengths'
                ));
            }
            $wallet_table = $wpdb->prefix . 'mfsd_wallet';
            if ($wpdb->get_var("SHOW TABLES LIKE '$wallet_table'") === $wallet_table) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $wallet_table WHERE student_id = %d AND source LIKE %s",
                    $student_id,
                    $wpdb->esc_like('badge_ss_') . '%'
                ));
            }
        }

        $notice = ['success', "Game #{$gid} fully deleted. Task status and SS badges reset" . ($student_id ? " for user #{$student_id}" : '') . '.'];
    }
}

// ── Delete game (complete games only) ─────────────────────────────────────────
if (isset($_POST['ss_delete_game']) && check_admin_referer('mfsd_ss_delete_game')) {
    $gid = (int)($_POST['delete_game_id'] ?? 0);
    if ($gid) {
        $ssp = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $shp = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;
        $scp = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_CLAIMS;
        $old_session_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $ssp WHERE game_id = %d", $gid));
        foreach ($old_session_ids as $sid) {
            $wpdb->delete($shp, ['session_id' => (int)$sid]);
            $wpdb->delete($scp, ['session_id' => (int)$sid]);
        }
        $wpdb->delete($ssp, ['game_id' => $gid]);
        $wpdb->query($wpdb->prepare("DELETE FROM $vp WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $tp WHERE game_id = %d", $gid));
        $wpdb->query($wpdb->prepare("DELETE FROM $cp WHERE game_id = %d", $gid));
        $wpdb->delete($pp, ['game_id' => $gid]);
        $wpdb->delete($gp, ['id' => $gid]);
        $notice = ['success', 'Game #' . $gid . ' has been permanently deleted.'];
    }
}

// ── Add strength ──────────────────────────────────────────────────────────────
if (isset($_POST['ss_add_strength']) && check_admin_referer('mfsd_ss_strength')) {
    $text = sanitize_text_field($_POST['strength_text'] ?? '');
    $cat  = sanitize_text_field($_POST['strength_cat']  ?? 'Character');
    if (strlen($text) >= 2 && strlen($text) <= 100) {
        // Prevent duplicates
        $dup = $wpdb->get_var($wpdb->prepare("SELECT id FROM $sp WHERE strength_text = %s", $text));
        if ($dup) {
            $notice = ['error', '"' . esc_html($text) . '" already exists in the list.'];
        } else {
            $wpdb->insert($sp, ['strength_text' => $text, 'category' => $cat, 'active' => 1]);
            $notice = ['success', '"' . esc_html($text) . '" added to ' . esc_html($cat) . '.'];
        }
    } else {
        $notice = ['error', 'Strength text must be between 2 and 100 characters.'];
    }
}

// ── Toggle strength active/inactive ──────────────────────────────────────────
if (isset($_GET['ss_toggle_str']) && check_admin_referer('ss_toggle_' . $_GET['ss_toggle_str'])) {
    $id  = (int) $_GET['ss_toggle_str'];
    $cur = (int) $wpdb->get_var($wpdb->prepare("SELECT active FROM $sp WHERE id = %d", $id));
    $new = $cur ? 0 : 1;
    $wpdb->update($sp, ['active' => $new], ['id' => $id]);
    $notice = ['success', 'Strength ' . ($new ? 'activated' : 'deactivated') . '.'];
}

// ── Delete strength ───────────────────────────────────────────────────────────
if (isset($_GET['ss_del_str']) && check_admin_referer('ss_del_' . $_GET['ss_del_str'])) {
    $wpdb->delete($sp, ['id' => (int) $_GET['ss_del_str']]);
    $notice = ['success', 'Strength deleted.'];
}

// ── Add banned term ───────────────────────────────────────────────────────────
if (isset($_POST['ss_add_banned']) && check_admin_referer('mfsd_ss_banned')) {
    $term = strtolower(sanitize_text_field($_POST['banned_term'] ?? ''));
    $cat  = sanitize_text_field($_POST['banned_cat']    ?? 'profanity');
    $act  = sanitize_text_field($_POST['banned_action'] ?? 'block');
    if (strlen($term) >= 1) {
        $dup = $wpdb->get_var($wpdb->prepare("SELECT id FROM $bp WHERE term = %s", $term));
        if ($dup) {
            $notice = ['error', '"' . esc_html($term) . '" is already in the banned list.'];
        } else {
            $wpdb->insert($bp, ['term' => $term, 'category' => $cat, 'action' => $act, 'active' => 1]);
            $notice = ['success', '"' . esc_html($term) . '" added as ' . esc_html($act) . '.'];
        }
    }
}

// ── Delete banned term ────────────────────────────────────────────────────────
if (isset($_GET['ss_del_ban']) && check_admin_referer('ss_del_ban_' . $_GET['ss_del_ban'])) {
    $wpdb->delete($bp, ['id' => (int) $_GET['ss_del_ban']]);
    $notice = ['success', 'Banned term removed.'];
}

// ── Review flagged submission ─────────────────────────────────────────────────
if (isset($_POST['ss_flag_action']) && isset($_POST['flag_id']) && check_admin_referer('mfsd_ss_flag_' . $_POST['flag_id'])) {
    $fid    = (int) $_POST['flag_id'];
    $action = sanitize_text_field($_POST['ss_flag_action']);
    $flag   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $fl WHERE id = %d", $fid), ARRAY_A);
    if ($flag) {
        $status = ($action === 'allow') ? 'allowed' : 'rejected';
        $wpdb->update($fl, [
            'status'      => $status,
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
        ], ['id' => $fid]);

        if ($action === 'allow') {
            $wpdb->update($cp, ['flagged' => 0], [
                'game_id'          => (int)$flag['game_id'],
                'author_player_id' => (int)$flag['player_id'],
                'target_player_id' => (int)$flag['target_player_id'],
                'strength_text'    => $flag['submitted_text'],
            ]);
            $notice = ['success', 'Card approved and added to the game pool.'];
        } else {
            $wpdb->delete($cp, [
                'game_id'          => (int)$flag['game_id'],
                'author_player_id' => (int)$flag['player_id'],
                'target_player_id' => (int)$flag['target_player_id'],
                'strength_text'    => $flag['submitted_text'],
            ]);
            $notice = ['success', 'Card rejected and removed.'];
        }
    }
}

// =============================================================================
// LOAD DATA FOR DISPLAY
// =============================================================================
$strengths   = $wpdb->get_results("SELECT * FROM $sp ORDER BY category, strength_text", ARRAY_A);
$banned      = $wpdb->get_results("SELECT * FROM $bp ORDER BY category, term", ARRAY_A);
$flags       = $wpdb->get_results(
    "SELECT f.*, p.display_name AS player_name, p2.display_name AS target_name
     FROM $fl f
     LEFT JOIN $pp p  ON p.id  = f.player_id
     LEFT JOIN $pp p2 ON p2.id = f.target_player_id
     WHERE f.status = 'pending'
     ORDER BY f.created_at DESC",
    ARRAY_A
);
$games = $wpdb->get_results(
    "SELECT g.*, COUNT(DISTINCT p.id) AS player_count,
            SUM(CASE WHEN p.submission_status='submitted' THEN 1 ELSE 0 END) AS submitted_count
     FROM $gp g
     LEFT JOIN $pp p ON p.game_id = g.id
     GROUP BY g.id
     ORDER BY g.created_at DESC
     LIMIT 20",
    ARRAY_A
);

$smg_tbl  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
$sm_games = $wpdb->get_results(
    "SELECT g.*, u.display_name
     FROM {$smg_tbl} g
     LEFT JOIN {$wpdb->users} u ON u.ID = g.student_user_id
     ORDER BY g.created_at DESC
     LIMIT 50",
    ARRAY_A
) ?: [];

$categories = [
    'Character','Social & Caring','Creative & Expressive','Mind & Learning',
    'Leadership & Drive','Practical & Dependable','Growth & Mindset','Family',
];

$pending_flags = count($flags);

// =============================================================================
// RENDER
// =============================================================================
?>
<div class="wrap">
    <h1>🃏 Super Strengths Cards</h1>

    <?php if ($notice): ?>
        <div class="notice notice-<?php echo $notice[0]; ?> is-dismissible">
            <p><?php echo $notice[1]; ?></p>
        </div>
    <?php endif; ?>

    <style>
        .ss-admin-tabs { margin: 20px 0; border-bottom: 2px solid #ddd; }
        .ss-tab-btn {
            padding: 10px 20px; border: 1px solid transparent; background: #f0f0f0;
            cursor: pointer; border-radius: 4px 4px 0 0; margin-right: 4px;
            font-size: 13px; font-weight: 600; position: relative; bottom: -2px;
        }
        .ss-tab-btn.active {
            background: #fff; border-color: #ddd; border-bottom-color: #fff;
        }
        .ss-tab-content { display: none; padding: 20px 0; }
        .ss-tab-content.active { display: block; }
        .ss-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        .ss-table th, .ss-table td {
            padding: 9px 12px; border: 1px solid #ddd; text-align: left; font-size: 13px;
        }
        .ss-table th { background: #f5f5f5; font-weight: 600; }
        .ss-table tr:hover td { background: #fafafa; }
        .ss-badge {
            display: inline-block; padding: 2px 9px; border-radius: 10px;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
        }
        .ss-badge-block  { background: #fce8e8; color: #b91c1c; }
        .ss-badge-flag   { background: #fff3cd; color: #92400e; }
        .ss-badge-active { background: #d1fae5; color: #065f46; }
        .ss-badge-inactive { background: #f3f4f6; color: #9ca3af; }
        .ss-badge-submission { background: #dbeafe; color: #1e40af; }
        .ss-badge-playing  { background: #d1fae5; color: #065f46; }
        .ss-badge-complete { background: #f3f4f6; color: #374151; }
        .ss-pipeline {
            display: flex; gap: 0; margin-bottom: 16px; border: 1px solid #ddd; border-radius: 6px; overflow: hidden;
        }
        .ss-pipeline-step {
            flex: 1; padding: 10px 12px; text-align: center; font-size: 12px;
            background: #f9f9f9; border-right: 1px solid #ddd; font-weight: 600;
        }
        .ss-pipeline-step:last-child { border-right: none; }
        .ss-pipeline-step span { display: block; font-weight: 400; color: #666; font-size: 11px; margin-top: 2px; }
        .ss-info-box {
            background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1;
            padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; font-size: 13px;
        }
        .ss-warn-box {
            background: #fff8e5; border: 1px solid #f0c036; border-left: 4px solid #f0c036;
            padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; font-size: 13px;
        }
        .ss-game-card {
            background: #fff; border: 1px solid #ddd; border-radius: 6px;
            padding: 16px; margin-bottom: 12px;
        }
        .ss-game-card h4 { margin: 0 0 8px; font-size: 15px; }
        .ss-game-card .meta { font-size: 12px; color: #666; }
        .delete-link { color: #a00; }
        .delete-link:hover { color: #dc3232; }

    </style>

    <!-- TABS -->
    <div class="ss-admin-tabs">
        <button class="ss-tab-btn active" data-tab="config">⚙️ Configuration</button>
        <button class="ss-tab-btn" data-tab="games">🎮 Games</button>
        <button class="ss-tab-btn" data-tab="strengths">💪 Strength List (<?php echo count($strengths); ?>)</button>
        <button class="ss-tab-btn" data-tab="banned">🚫 Banned Terms (<?php echo count($banned); ?>)</button>
        <button class="ss-tab-btn" data-tab="flags">
            ⚑ Flagged
            <?php if ($pending_flags > 0): ?>
                <span class="awaiting-mod" style="margin-left:4px;"><?php echo $pending_flags; ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- ===================================================================
         TAB: CONFIGURATION
         =================================================================== -->
    <div id="ss-tab-config" class="ss-tab-content active">
        <h2>Game Configuration</h2>

        <form method="post">
            <?php wp_nonce_field('mfsd_ss_config'); ?>
            <input type="hidden" name="ss_save_config" value="1">

            <table class="form-table"><tbody>
                <tr>
                    <th scope="row">Free text (age 11–12)</th>
                    <td><label><input type="checkbox" name="ft_11_12" <?php checked(get_option('mfsd_ss_free_text_11_12'),'1'); ?>> Allow students aged 11–12 to write their own strength phrases</label></td>
                </tr>
                <tr>
                    <th scope="row">Free text (age 13–14)</th>
                    <td><label><input type="checkbox" name="ft_13_14" <?php checked(get_option('mfsd_ss_free_text_13_14'),'1'); ?>> Allow students aged 13–14 to write their own strength phrases</label></td>
                </tr>
                <tr>
                    <th scope="row">Max free-text entries</th>
                    <td>
                        <input type="number" name="ft_max" value="<?php echo (int) get_option('mfsd_ss_free_text_max',2); ?>" min="1" max="5" class="small-text">
                        <p class="description">Per submission set (e.g. 2 means up to 2 of their 5 cards can be free-text).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Free-text length</th>
                    <td>
                        <input type="number" name="ft_min_len" value="<?php echo (int) get_option('mfsd_ss_free_text_min_len',3); ?>" min="1" class="small-text"> –
                        <input type="number" name="ft_max_len" value="<?php echo (int) get_option('mfsd_ss_free_text_max_len',40); ?>" max="100" class="small-text"> characters
                    </td>
                </tr>

                <tr><td colspan="2"><hr><h3 style="margin:0">Memory Game — Card Pool</h3>
                    <p class="description">Controls which cards enter the matching board. §4.2</p></td></tr>

                <tr>
                    <th scope="row">Card pool</th>
                    <td>
                        <label><input type="radio" name="card_pool" value="family_cards" <?php checked(get_option('mfsd_ss_card_pool','family_cards'),'family_cards'); ?>>
                            <strong>Family cards only</strong> — Cards written about other players (Phase 2) only</label><br><br>
                        <label><input type="radio" name="card_pool" value="all_cards" <?php checked(get_option('mfsd_ss_card_pool','family_cards'),'all_cards'); ?>>
                            <strong>All cards</strong> — Include each player's own self-strength cards (Phase 1) in the pool</label>
                        <p class="description">Default: Family cards only. Including self-strengths increases board size significantly (see spec §9 for tile counts).</p>
                    </td>
                </tr>

                <tr><td colspan="2"><hr><h3 style="margin:0">Memory Game — Game Mode</h3>
                    <p class="description">Controls when the game ends and how the winner is determined. §4.3</p></td></tr>

                <tr>
                    <th scope="row">Game end condition</th>
                    <td>
                        <label><input type="radio" name="memory_mode" value="first_to_x" <?php checked(get_option('mfsd_ss_memory_mode','first_to_x'),'first_to_x'); ?>>
                            <strong>First to X</strong> — First player to reach the target number of matched pairs wins</label><br><br>
                        <label><input type="radio" name="memory_mode" value="all_match" <?php checked(get_option('mfsd_ss_memory_mode','first_to_x'),'all_match'); ?>>
                            <strong>All match</strong> — Play until every card is matched; player with most matches wins (ties allowed)</label><br><br>
                        <label><input type="radio" name="memory_mode" value="timed" <?php checked(get_option('mfsd_ss_memory_mode','first_to_x'),'timed'); ?>>
                            <strong>Timed</strong> — Game ends after a set time; player with most matches at the buzzer wins</label>
                        <p class="description">Recommended: First to X for families with 3+ players — keeps game length predictable regardless of board size.</p>
                    </td>
                </tr>

                <tr id="ss-memory-target-row" style="<?php echo get_option('mfsd_ss_memory_mode','first_to_x') === 'first_to_x' ? '' : 'display:none'; ?>">
                    <th scope="row" style="padding-left:30px;">↳ Matches to win</th>
                    <td>
                        <input type="number" name="memory_target_matches" value="<?php echo (int) get_option('mfsd_ss_memory_target_matches', 5); ?>" min="1" max="50" class="small-text">
                        <p class="description">Number of matched pairs required to win. Default: 5. Max possible pairs = (n−1)×5 per player (family mode) or 10 (demo).</p>
                    </td>
                </tr>

                <tr id="ss-memory-timelimit-row" style="<?php echo get_option('mfsd_ss_memory_mode','first_to_x') === 'timed' ? '' : 'display:none'; ?>">
                    <th scope="row" style="padding-left:30px;">↳ Game time limit (minutes)</th>
                    <td>
                        <input type="number" name="memory_time_limit" value="<?php echo (int) get_option('mfsd_ss_memory_time_limit', 5); ?>" min="1" max="60" class="small-text">
                        <p class="description">Duration of timed games in minutes. Default: 5.</p>
                    </td>
                </tr>

                <tr><td colspan="2"><hr><h3 style="margin:0">Memory Game — Turn Settings</h3>
                    <p class="description">Controls the timeout behaviour when a player goes away. §4.4</p></td></tr>

                <tr>
                    <th scope="row">Turn timeout (minutes)</th>
                    <td>
                        <input type="number" name="turn_timeout_mins" value="<?php echo (int) get_option('mfsd_ss_turn_timeout_mins', 5); ?>" min="1" max="60" class="small-text">
                        <p class="description">If the active player doesn't flip their first card within this window, the turn auto-advances. Default: 5 minutes.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Turn warning (seconds before timeout)</th>
                    <td>
                        <input type="number" name="turn_warning_secs" value="<?php echo (int) get_option('mfsd_ss_turn_warning_secs', 60); ?>" min="0" max="300" class="small-text">
                        <p class="description">A countdown is shown on-screen this many seconds before the turn times out. Default: 60. Set to 0 to disable the warning.</p>
                    </td>
                </tr>

                <tr><td colspan="2"><hr><h3 style="margin:0">Memory Game — Demo Mode</h3>
                    <p class="description">Single-player demo where Steve AI selects cards based on prior course activities. §16.9</p></td></tr>

                <tr>
                    <th scope="row">Enable demo mode</th>
                    <td>
                        <label><input type="checkbox" name="demo_mode_enabled" id="ss-demo-enabled" <?php checked(get_option('mfsd_ss_demo_mode_enabled','0'),'1'); ?>>
                            Offer single-player demo to students who have completed all prerequisites</label>
                        <p class="description">Prerequisites: Solution Lens, Word Association, and Who Am I must all be marked complete in the ordering system. Demo is a one-time activity.</p>
                    </td>
                </tr>

                <tr id="ss-demo-timelimit-row" style="<?php echo get_option('mfsd_ss_demo_mode_enabled') === '1' ? '' : 'display:none'; ?>">
                    <th scope="row" style="padding-left:30px;">↳ Demo time limit (minutes)</th>
                    <td>
                        <input type="number" name="demo_time_limit_mins" value="<?php echo (int) get_option('mfsd_ss_demo_time_limit_mins', 3); ?>" min="1" max="10" class="small-text">
                        <p class="description">Duration of the demo game timer. Default: 3 minutes. Steve's picks are generated before the timer starts.</p>
                    </td>
                </tr>

                <tr><td colspan="2"><hr><h3 style="margin:0">Memory Game — SteveGPT Slots</h3>
                    <p class="description">Paste SteveGPT prompt option IDs (from the SteveGPT plugin) for each Memory game role. Leave blank to disable AI for that role.</p></td></tr>
                <?php
                $sg_labels = [
                    'ss_welcome_intro'   => ['Welcome intro',      'AI narration on the welcome/lobby screen.'],
                    'ss_welcome_chat'    => ['Welcome chatbot',     'Chatbot available on the welcome screen.'],
                    'ss_student_summary' => ['Student summary',     'Post-game AI summary for the student.'],
                    'ss_parent_summary'  => ['Parent summary',      'Post-game AI summary for the parent.'],
                    'ss_student_summary_chat' => ['Student summary chatbot', 'Chatbot on the student results/summary screen.'],
                    'ss_parent_summary_chat'  => ['Parent summary chatbot',  'Chatbot on the parent results/summary screen.'],
                    'ss_demo_picker'          => ['Demo card picker',         'Steve AI picks cards for demo mode.'],
                    'ss_demo_summary'         => ['Demo summary',             'AI summary generated after a demo game.'],
                    'ss_demo_chat'            => ['Demo chatbot',             'Chatbot on the demo results screen.'],
                ];
                foreach ($sg_labels as $k => [$label, $desc]):
                    $opt = 'mfsd_stevegpt_map_' . $k;
                ?>
                <tr>
                    <th scope="row"><?php echo esc_html($label); ?></th>
                    <td>
                        <input type="text" name="sg_<?php echo esc_attr($k); ?>"
                               value="<?php echo esc_attr(get_option($opt, '')); ?>"
                               class="regular-text" placeholder="e.g. stevegpt_prompt_123">
                        <p class="description"><?php echo esc_html($desc); ?></p>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody></table>

            <p class="submit">
                <input type="submit" class="button-primary" value="Save Configuration">
            </p>
        </form>
    </div>

    <!-- ===================================================================
         TAB: GAMES
         =================================================================== -->
    <div id="ss-tab-games" class="ss-tab-content">
        <h2>Games</h2>

        <!-- ── v5 Memory & Demo Games ── -->
        <h3>Memory &amp; Demo Games (v5)</h3>
        <?php
        $sm_status_colors = [
            'submission_self'   => 'submission',
            'submission_others' => 'submission',
            'dealing'           => 'flag',
            'playing'           => 'playing',
            'complete'          => 'complete',
        ];
        ?>
        <?php if (empty($sm_games)): ?>
            <p>No v5 games yet.</p>
        <?php else: ?>
        <table class="ss-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Mode</th>
                    <th>Started</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sm_games as $g): ?>
                <?php
                $status_key = $sm_status_colors[$g['status']] ?? 'inactive';
                $type_label = $g['game_type'] === 'demo' ? '🤖 Demo' : '🃏 Family';
                $started    = $g['game_started_at'] ?? $g['created_at'] ?? '—';
                ?>
                <tr>
                    <td><strong>#<?php echo (int)$g['id']; ?></strong><br><span style="font-size:11px;color:#666;"><?php echo esc_html(substr($g['game_key'] ?? '', 0, 20)); ?></span></td>
                    <td><?php echo esc_html($g['display_name'] ?? 'User #' . $g['student_user_id']); ?><br><span style="font-size:11px;color:#666;">uid:<?php echo (int)$g['student_user_id']; ?></span></td>
                    <td><?php echo $type_label; ?></td>
                    <td><span class="ss-badge ss-badge-<?php echo esc_attr($status_key); ?>"><?php echo esc_html($g['status']); ?></span></td>
                    <td><?php echo esc_html($g['memory_mode'] ?? '—'); ?></td>
                    <td style="font-size:12px;"><?php echo esc_html(substr($started, 0, 16)); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('mfsd_ss_reset_sm'); ?>
                            <input type="hidden" name="ss_reset_sm_game" value="1">
                            <input type="hidden" name="reset_sm_game_id" value="<?php echo (int)$g['id']; ?>">
                            <input type="submit" class="button button-small"
                                   style="color:#b91c1c;border-color:#b91c1c;"
                                   value="↺ Reset"
                                   onclick="return confirm('Delete ALL data for game #<?php echo (int)$g['id']; ?>?\n\nThis will:\n• Delete the game, board, turns, summaries\n• Reset the student\'s Super Strengths task to available\n• Remove all Super Strengths badges\n\nThis cannot be undone.')">
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ── Legacy v4 Games (collapsed) ── -->
        <details style="margin-top:32px;border:1px solid #ddd;border-radius:6px;padding:0;">
            <summary style="padding:12px 16px;cursor:pointer;font-weight:600;font-size:14px;background:#f9f9f9;border-radius:6px;">
                📦 Legacy v4 Games (<?php echo count($games); ?> records)
            </summary>
            <div style="padding:16px;">
                <p style="color:#92400e;background:#fff8e5;border:1px solid #f0c036;border-left:4px solid #f0c036;padding:10px 14px;border-radius:4px;font-size:13px;">
                    These are v4 guessing game records. v5 memory games are shown above.
                </p>
                <?php if (empty($games)): ?>
                    <p>No legacy games.</p>
                <?php else: ?>
                    <?php foreach ($games as $g):
                        $players_in_game = $wpdb->get_results($wpdb->prepare(
                            "SELECT display_name, role, submission_status, score_total FROM $pp WHERE game_id = %d ORDER BY turn_order ASC, id ASC",
                            $g['id']
                        ), ARRAY_A);
                    ?>
                    <div class="ss-game-card">
                        <h4>
                            Game #<?php echo $g['id']; ?>
                            <span class="ss-badge ss-badge-<?php echo esc_attr($g['status']); ?>"><?php echo esc_html($g['status']); ?></span>
                            <span style="font-weight:400;font-size:13px;margin-left:8px;"><?php echo esc_html($g['mode']); ?> mode</span>
                        </h4>
                        <div class="meta">Created <?php echo esc_html($g['created_at']); ?> · <?php echo (int)$g['player_count']; ?> players</div>

                        <?php if (!empty($players_in_game)): ?>
                        <table class="ss-table" style="margin-top:10px;max-width:560px;">
                            <tr><th>Player</th><th>Role</th><th>Submitted</th><th>Score</th></tr>
                            <?php foreach ($players_in_game as $pl): ?>
                            <tr>
                                <td><?php echo esc_html($pl['display_name']); ?></td>
                                <td><?php echo esc_html($pl['role']); ?></td>
                                <td><?php echo $pl['submission_status'] === 'submitted' ? '✅' : '⏳'; ?></td>
                                <td><?php echo (int)$pl['score_total']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <?php endif; ?>

                        <form method="post" style="margin-top:8px;display:inline-block;">
                            <?php wp_nonce_field('mfsd_ss_delete_game'); ?>
                            <input type="hidden" name="ss_delete_game" value="1">
                            <input type="hidden" name="delete_game_id" value="<?php echo $g['id']; ?>">
                            <input type="submit" class="button button-small" value="🗑 Delete"
                                   onclick="return confirm('Permanently delete legacy game #<?php echo $g['id']; ?>?')">
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </details>
    </div>

    <!-- ===================================================================
         TAB: STRENGTH LIST
         =================================================================== -->
    <div id="ss-tab-strengths" class="ss-tab-content">
        <h2>Strength List</h2>

        <h3>Add New Strength</h3>
        <form method="post" style="background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:16px;margin-bottom:24px;">
            <?php wp_nonce_field('mfsd_ss_strength'); ?>
            <input type="hidden" name="ss_add_strength" value="1">
            <table class="form-table"><tbody>
                <tr>
                    <th>Phrase</th>
                    <td><input type="text" name="strength_text" class="regular-text"
                               placeholder="e.g. Home is better with them in it" maxlength="100"></td>
                </tr>
                <tr>
                    <th>Category</th>
                    <td>
                        <select name="strength_cat">
                            <?php foreach ($categories as $cat): ?>
                                <option><?php echo esc_html($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </tbody></table>
            <p><input type="submit" class="button-primary" value="+ Add Strength"></p>
        </form>

        <!-- Strength table grouped by category -->
        <?php
        $by_cat = [];
        foreach ($strengths as $s) {
            $by_cat[$s['category']][] = $s;
        }
        foreach ($by_cat as $cat => $items):
            $active_count   = count(array_filter($items, fn($s) => $s['active']));
            $inactive_count = count($items) - $active_count;
        ?>
        <h3><?php echo esc_html($cat); ?>
            <span style="font-size:13px;font-weight:400;color:#666;"><?php echo count($items); ?> phrases
            <?php if ($inactive_count): ?>(<?php echo $inactive_count; ?> inactive)<?php endif; ?></span>
        </h3>
        <table class="ss-table">
            <thead><tr><th>Phrase</th><th>Status</th><th>Times used</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($items as $s): ?>
            <tr <?php echo $s['active'] ? '' : 'style="opacity:0.55"'; ?>>
                <td><strong><?php echo esc_html($s['strength_text']); ?></strong></td>
                <td>
                    <span class="ss-badge <?php echo $s['active'] ? 'ss-badge-active' : 'ss-badge-inactive'; ?>">
                        <?php echo $s['active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </td>
                <td><?php echo (int)$s['times_used']; ?></td>
                <td>
                    <a href="<?php echo esc_url(add_query_arg(['ss_toggle_str' => $s['id'], '_wpnonce' => wp_create_nonce('ss_toggle_'.$s['id'])])); ?>">
                        <?php echo $s['active'] ? 'Deactivate' : 'Activate'; ?>
                    </a>
                    &nbsp;|&nbsp;
                    <a class="delete-link"
                       href="<?php echo esc_url(add_query_arg(['ss_del_str' => $s['id'], '_wpnonce' => wp_create_nonce('ss_del_'.$s['id'])])); ?>"
                       onclick="return confirm('Delete \'<?php echo esc_js($s['strength_text']); ?>\'?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endforeach; ?>
    </div>

    <!-- ===================================================================
         TAB: BANNED TERMS
         =================================================================== -->
    <div id="ss-tab-banned" class="ss-tab-content">
        <h2>Banned Terms & Content Filters</h2>

        <!-- Validation pipeline diagram -->
        <div class="ss-pipeline">
            <div class="ss-pipeline-step">1. Leetspeak normalise<span>4→a 3→e 1→i 0→o $→s @→a</span></div>
            <div class="ss-pipeline-step">2. Profanity list<span>Against normalised + original</span></div>
            <div class="ss-pipeline-step">3. PII regex<span>Phone · email · URL · postcode</span></div>
            <div class="ss-pipeline-step">4. Block or flag<span>Block = hard stop · Flag = admin queue</span></div>
        </div>

        <div class="ss-info-box">
            <strong>PII patterns</strong> (managed in code, not editable here):
            UK phone · Email address · URLs &amp; social handles (instagram, tiktok, discord, snapchat) · UK postcodes.
            These always result in a hard block regardless of the term list.
        </div>

        <h3>Add New Term</h3>
        <form method="post" style="background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:16px;margin-bottom:24px;">
            <?php wp_nonce_field('mfsd_ss_banned'); ?>
            <input type="hidden" name="ss_add_banned" value="1">
            <table class="form-table"><tbody>
                <tr>
                    <th>Term / phrase</th>
                    <td><input type="text" name="banned_term" class="regular-text" placeholder="lowercase, e.g. hate you">
                        <p class="description">Will be checked against both original and leetspeak-normalised input.</p>
                    </td>
                </tr>
                <tr>
                    <th>Category</th>
                    <td>
                        <select name="banned_cat">
                            <option value="profanity">Profanity</option>
                            <option value="violence">Violence & Threat</option>
                            <option value="sexual">Sexual Content</option>
                            <option value="self_harm">Self-Harm</option>
                            <option value="custom">Custom</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Action on match</th>
                    <td>
                        <select name="banned_action">
                            <option value="block">Block + show error message</option>
                            <option value="flag">Flag for admin review</option>
                        </select>
                        <p class="description">
                            <strong>Block</strong> — rejects the submission instantly with a child-friendly message.<br>
                            <strong>Flag</strong> — allows the card but holds it in the review queue for admin decision.
                        </p>
                    </td>
                </tr>
            </tbody></table>
            <p><input type="submit" class="button-primary" value="+ Add Term"></p>
        </form>

        <!-- Term table by category -->
        <?php
        $by_bcat = [];
        foreach ($banned as $b) {
            $by_bcat[$b['category']][] = $b;
        }
        $cat_labels = [
            'profanity' => 'Profanity', 'violence' => 'Violence & Threat',
            'sexual' => 'Sexual Content', 'self_harm' => 'Self-Harm', 'custom' => 'Custom',
        ];
        foreach ($by_bcat as $bcat => $terms):
        ?>
        <h3><?php echo esc_html($cat_labels[$bcat] ?? $bcat); ?> (<?php echo count($terms); ?>)</h3>
        <table class="ss-table">
            <thead><tr><th>Term</th><th>Action</th><th>Matches</th><th>Remove</th></tr></thead>
            <tbody>
            <?php foreach ($terms as $b): ?>
            <tr>
                <td><code><?php echo esc_html($b['term']); ?></code></td>
                <td><span class="ss-badge <?php echo $b['action'] === 'block' ? 'ss-badge-block' : 'ss-badge-flag'; ?>">
                    <?php echo $b['action'] === 'block' ? 'Block' : 'Flag'; ?>
                </span></td>
                <td><?php echo (int)$b['match_count']; ?></td>
                <td>
                    <a class="delete-link"
                       href="<?php echo esc_url(add_query_arg(['ss_del_ban' => $b['id'], '_wpnonce' => wp_create_nonce('ss_del_ban_'.$b['id'])])); ?>"
                       onclick="return confirm('Remove \'<?php echo esc_js($b['term']); ?>\' from banned list?')">Remove</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endforeach; ?>
    </div>

    <!-- ===================================================================
         TAB: FLAGGED SUBMISSIONS
         =================================================================== -->
    <div id="ss-tab-flags" class="ss-tab-content">
        <h2>Flagged Submissions</h2>

        <?php if (empty($flags)): ?>
            <div class="ss-info-box">✅ No pending flags — all submissions are clear.</div>
        <?php else: ?>
            <div class="ss-warn-box">
                <strong><?php echo $pending_flags; ?> submission<?php echo $pending_flags > 1 ? 's' : ''; ?> awaiting review.</strong>
                These cards matched an ambiguous rule and need your decision before they enter the game.
            </div>

            <table class="ss-table">
                <thead>
                    <tr>
                        <th>Player</th>
                        <th>For</th>
                        <th>Game</th>
                        <th>Submitted text</th>
                        <th>Matched rule</th>
                        <th>Submitted</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($flags as $f): ?>
                <tr>
                    <td><?php echo esc_html($f['player_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo esc_html($f['target_name'] ?? '—'); ?></td>
                    <td>#<?php echo (int)$f['game_id']; ?></td>
                    <td style="font-family:monospace;">"<?php echo esc_html($f['submitted_text']); ?>"</td>
                    <td><code><?php echo esc_html($f['matched_rule']); ?></code></td>
                    <td style="font-size:12px;color:#666;"><?php echo esc_html($f['created_at']); ?></td>
                    <td>
                        <form method="post" style="display:flex;gap:6px;">
                            <?php wp_nonce_field('mfsd_ss_flag_' . $f['id']); ?>
                            <input type="hidden" name="flag_id" value="<?php echo $f['id']; ?>">
                            <input type="submit" name="ss_flag_action" value="allow"
                                   class="button button-primary button-small"
                                   title="Allow — add this card to the game pool">
                            <input type="submit" name="ss_flag_action" value="reject"
                                   class="button button-small"
                                   title="Reject — remove this card"
                                   onclick="return confirm('Reject and delete this card?')">
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div><!-- .wrap -->

<script>
// Tab switcher
document.querySelectorAll('.ss-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ss-tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.ss-tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('ss-tab-' + btn.dataset.tab).classList.add('active');
        btn.classList.add('active');
    });
});

// Show/hide memory mode dependent rows
function updateMemoryModeRows() {
    const mode = document.querySelector('input[name="memory_mode"]:checked')?.value;
    const targetRow = document.getElementById('ss-memory-target-row');
    const timeLimitRow = document.getElementById('ss-memory-timelimit-row');
    if (targetRow) targetRow.style.display = (mode === 'first_to_x') ? '' : 'none';
    if (timeLimitRow) timeLimitRow.style.display = (mode === 'timed') ? '' : 'none';
}
document.querySelectorAll('input[name="memory_mode"]').forEach(r => r.addEventListener('change', updateMemoryModeRows));

// Show/hide demo time limit row
const demoEnabledCb = document.getElementById('ss-demo-enabled');
const demoTimeLimitRow = document.getElementById('ss-demo-timelimit-row');
if (demoEnabledCb && demoTimeLimitRow) {
    demoEnabledCb.addEventListener('change', function() {
        demoTimeLimitRow.style.display = this.checked ? '' : 'none';
    });
}

</script>