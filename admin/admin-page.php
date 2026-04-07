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
    update_option('mfsd_ss_mode',              sanitize_text_field($_POST['game_mode'] ?? 'full'));
    update_option('mfsd_ss_round_limit',       max(1, (int)($_POST['round_limit'] ?? 3)));
    update_option('mfsd_ss_turn_timeout',      max(1, (int)($_POST['turn_timeout'] ?? 24)));
    update_option('mfsd_ss_vote_timeout',      max(1, (int)($_POST['vote_timeout'] ?? 24)));
    update_option('mfsd_ss_free_text_11_12',   isset($_POST['ft_11_12']) ? '1' : '0');
    update_option('mfsd_ss_free_text_13_14',   isset($_POST['ft_13_14']) ? '1' : '0');
    update_option('mfsd_ss_free_text_max',     max(1, min(5, (int)($_POST['ft_max'] ?? 2))));
    update_option('mfsd_ss_free_text_min_len', max(2, (int)($_POST['ft_min_len'] ?? 3)));
    update_option('mfsd_ss_free_text_max_len', min(80, (int)($_POST['ft_max_len'] ?? 40)));
    update_option('mfsd_ss_snap_mode',         sanitize_text_field($_POST['snap_mode'] ?? 'quick_draw'));
    update_option('mfsd_ss_snap_quick_draw_target', max(1, (int)($_POST['snap_quick_draw_target'] ?? 5)));
    update_option('mfsd_ss_snap_timer',        max(1, min(10, (int)($_POST['snap_timer'] ?? 3))));
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
        $wpdb->query($wpdb->prepare(
            "UPDATE $pp SET score_total = 0, confidence_tokens = 2,
             submission_status = 'pending', submitted_at = NULL, turn_order = NULL
             WHERE game_id = %d",
            $gid
        ));
        $wpdb->update($gp, ['status' => 'submission', 'current_turn_id' => null], ['id' => $gid]);
        $notice = ['success', 'Game #' . $gid . ' has been reset to submission phase.'];
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

$categories = [
    'Character','Social & Caring','Creative & Expressive','Mind & Learning',
    'Leadership & Drive','Practical & Dependable','Growth & Mindset','Family',
];

$pending_flags = count($flags);

// =============================================================================
// VALIDATION: round limit warning
// =============================================================================
$round_limit   = (int) get_option('mfsd_ss_round_limit', 3);
$max_safe_r_5  = 4 * MFSD_SS_DB::CARDS_PER_TARGET; // (5-1) × 5 = 20
$max_safe_r_6  = 5 * MFSD_SS_DB::CARDS_PER_TARGET; // (6-1) × 5 = 25
$rl_warning    = ($round_limit > $max_safe_r_5);

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
        .ss-dealing-preview {
            background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 6px;
            padding: 12px; margin: 12px 0; font-size: 13px;
        }
        .ss-dealing-preview table { border-collapse: collapse; width: 100%; }
        .ss-dealing-preview td, .ss-dealing-preview th { padding: 4px 8px; }
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

        <?php if ($rl_warning): ?>
        <div class="ss-warn-box">
            ⚠️ <strong>Round limit warning:</strong> Round limit is set to <?php echo $round_limit; ?>.
            With 5 players, hand size is <?php echo 4 * MFSD_SS_DB::CARDS_PER_TARGET; ?> cards — any R above <?php echo 4 * MFSD_SS_DB::CARDS_PER_TARGET; ?> means players run out of cards before finishing their turns.
        </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('mfsd_ss_config'); ?>
            <input type="hidden" name="ss_save_config" value="1">

            <table class="form-table"><tbody>
                <tr>
                    <th scope="row">Game Mode</th>
                    <td>
                        <label><input type="radio" name="game_mode" value="full" <?php checked(get_option('mfsd_ss_mode','full'),'full'); ?>>
                            <strong>Extended</strong> — Phase A (guess target) + Phase B (guess author)</label><br><br>
                        <label><input type="radio" name="game_mode" value="short" <?php checked(get_option('mfsd_ss_mode','full'),'short'); ?>>
                            <strong>Family Short</strong> — Phase A only (guess target)</label><br><br>
                        <label><input type="radio" name="game_mode" value="snap" <?php checked(get_option('mfsd_ss_mode','full'),'snap'); ?>>
                            <strong>Snap</strong> — Real-time Super Strengths Snap card game (2–6 players, all must be online)</label>
                        <p class="description">Extended is recommended for families. Snap requires all players to be online simultaneously.</p>
                    </td>
                </tr>

                <tr id="ss-snap-settings-row" style="<?php echo get_option('mfsd_ss_mode') === 'snap' ? '' : 'display:none'; ?>">
                    <th scope="row" style="padding-left:30px;">↳ Snap sub-mode</th>
                    <td>
                        <label><input type="radio" name="snap_mode" value="quick_draw" <?php checked(get_option('mfsd_ss_snap_mode','quick_draw'),'quick_draw'); ?>>
                            <strong>Quick Draw</strong> — Fixed number of snaps; player with most wins at the end wins</label><br><br>
                        <label><input type="radio" name="snap_mode" value="until_death" <?php checked(get_option('mfsd_ss_snap_mode','quick_draw'),'until_death'); ?>>
                            <strong>Until the Death</strong> — Play until all cards have been snapped; player with most snaps wins</label>
                    </td>
                </tr>

                <tr id="ss-snap-qd-row" style="<?php echo (get_option('mfsd_ss_mode') === 'snap' && get_option('mfsd_ss_snap_mode') === 'quick_draw') ? '' : 'display:none'; ?>">
                    <th scope="row" style="padding-left:30px;">↳ Quick Draw target snaps</th>
                    <td>
                        <input type="number" name="snap_quick_draw_target" value="<?php echo (int) get_option('mfsd_ss_snap_quick_draw_target', 5); ?>" min="1" max="30" class="small-text">
                        <p class="description">Number of snaps before the game ends. Default: 5. Max possible snaps = (n−1)×5 pairs.</p>
                    </td>
                </tr>

                <tr id="ss-snap-timer-row" style="<?php echo get_option('mfsd_ss_mode') === 'snap' ? '' : 'display:none'; ?>">
                    <th scope="row" style="padding-left:30px;">↳ Snap timer (seconds)</th>
                    <td>
                        <input type="number" name="snap_timer" value="<?php echo (int) get_option('mfsd_ss_snap_timer', 3); ?>" min="1" max="10" class="small-text">
                        <p class="description">How long the snap bullseye is visible after a match. Default: 3 seconds. Tiebreaker always uses 5 seconds.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Round limit <br><small style="font-weight:400">(5–6 player games)</small></th>
                    <td>
                        <input type="number" name="round_limit" id="ss-round-limit"
                               value="<?php echo (int) get_option('mfsd_ss_round_limit',3); ?>"
                               min="1" max="25" class="small-text">
                        <p class="description">
                            Max turns each player takes. Hand size = (n−1)×5.
                            3 or 4 players play all cards (limit ignored).<br>
                            <strong id="ss-rl-hint"></strong>
                        </p>
                        <!-- Dealing preview table -->
                        <div class="ss-dealing-preview" id="ss-deal-preview">
                            <table>
                                <tr><th>Players</th><th>Hand size</th><th>R (limit)</th><th>Cards played</th><th>Cards in summary</th><th>Status</th></tr>
                                <?php foreach ([3,4,5,6] as $n): ?>
                                <?php $hand = ($n-1)*5; $played = min($hand, $round_limit * $n); $unplayed = ($n*$hand) - ($played * $n); ?>
                                <tr>
                                    <td><?php echo $n; ?></td>
                                    <td><?php echo $hand; ?></td>
                                    <td><?php echo ($n <= 4) ? 'All' : $round_limit; ?></td>
                                    <td><?php echo ($n <= 4) ? $n*$hand : min($round_limit,$hand)*$n; ?></td>
                                    <td><?php echo ($n <= 4) ? 0 : max(0, ($n*$hand) - (min($round_limit,$hand)*$n)); ?></td>
                                    <td><?php echo ($n <= 4 || $round_limit <= $hand) ? '✅' : '⚠️ R exceeds hand'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Turn timeout (hours)</th>
                    <td>
                        <input type="number" name="turn_timeout" value="<?php echo (int) get_option('mfsd_ss_turn_timeout',24); ?>" min="1" class="small-text">
                        <p class="description">How long a player has to choose and play a card. Default: 24 hours.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Vote timeout (hours)</th>
                    <td>
                        <input type="number" name="vote_timeout" value="<?php echo (int) get_option('mfsd_ss_vote_timeout',24); ?>" min="1" class="small-text">
                        <p class="description">How long voting stays open before auto-reveal. Default: 24 hours.</p>
                    </td>
                </tr>

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

        <div class="ss-info-box">
            <strong>How to set up a game:</strong>
            1. Create a game below → 2. Add each family member by their WordPress user ID → 3. Share the page with the
            <code>[mfsd_super_strengths]</code> shortcode so they can log in and submit cards.
        </div>

        <!-- Create game -->
        <h3>Create New Game</h3>
        <form method="post" style="background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:16px;margin-bottom:24px;">
            <?php wp_nonce_field('mfsd_ss_create_game'); ?>
            <input type="hidden" name="ss_create_game" value="1">
            <table class="form-table"><tbody>
                <tr>
                    <th>Mode</th>
                    <td>
                        <select name="new_game_mode">
                            <option value="full">Full (Phase A + B)</option>
                            <option value="short">Short (Phase A only)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Round limit (5–6 players)</th>
                    <td><input type="number" name="new_round_limit" value="<?php echo (int) get_option('mfsd_ss_round_limit',3); ?>" min="1" max="25" class="small-text"></td>
                </tr>
            </tbody></table>
            <p><input type="submit" class="button-primary" value="+ Create Game"></p>
        </form>

        <!-- Add player -->
        <h3>Add Player to Game</h3>
        <form method="post" style="background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:16px;margin-bottom:24px;">
            <?php wp_nonce_field('mfsd_ss_add_player'); ?>
            <input type="hidden" name="ss_add_player" value="1">
            <table class="form-table"><tbody>
                <tr>
                    <th>Game ID</th>
                    <td><input type="number" name="player_game_id" class="small-text" placeholder="e.g. 1"></td>
                </tr>
                <tr>
                    <th>WordPress User ID</th>
                    <td><input type="number" name="player_user_id" class="small-text" placeholder="e.g. 42"></td>
                </tr>
                <tr>
                    <th>Role</th>
                    <td>
                        <select name="player_role">
                            <option value="student">Student</option>
                            <option value="parent">Parent</option>
                            <option value="carer">Carer</option>
                            <option value="sibling">Sibling</option>
                            <option value="other">Other</option>
                        </select>
                    </td>
                </tr>
            </tbody></table>
            <p><input type="submit" class="button-primary" value="Add Player"></p>
        </form>

        <!-- Game list -->
        <h3>Recent Games</h3>
        <?php if (empty($games)): ?>
            <p>No games yet.</p>
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
                    <span style="font-weight:400;font-size:13px;margin-left:8px;"><?php echo esc_html($g['mode']); ?> mode · R=<?php echo (int)$g['round_limit']; ?></span>
                </h4>
                <div class="meta">
                    Created <?php echo esc_html($g['created_at']); ?> ·
                    <?php echo (int)$g['player_count']; ?> players ·
                    <?php echo (int)$g['submitted_count']; ?>/<?php echo (int)$g['player_count']; ?> submitted
                </div>

                <?php if (!empty($players_in_game)): ?>
                <table class="ss-table" style="margin-top:10px;max-width:600px;">
                    <tr><th>Player</th><th>Role</th><th>Submitted</th><th>Score</th></tr>
                    <?php foreach ($players_in_game as $pl): ?>
                    <tr>
                        <td><?php echo esc_html($pl['display_name']); ?></td>
                        <td><?php echo esc_html($pl['role']); ?></td>
                        <td><?php echo $pl['submission_status'] === 'submitted' ? '✅' : '⏳'; ?></td>
                        <td><?php echo (int)$pl['score_total']; ?> pts</td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>

                <?php if ($g['status'] !== 'complete'): ?>
                <form method="post" style="margin-top:10px;">
                    <?php wp_nonce_field('mfsd_ss_reset'); ?>
                    <input type="hidden" name="ss_reset_game" value="1">
                    <input type="hidden" name="reset_game_id" value="<?php echo $g['id']; ?>">
                    <input type="submit" class="button button-small" value="↺ Reset game"
                           onclick="return confirm('Reset game #<?php echo $g['id']; ?>? This deletes all cards, turns and votes.')">
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
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

// Show/hide snap settings based on game mode selection
function updateSnapRows() {
    const mode = document.querySelector('input[name="game_mode"]:checked')?.value;
    const snapRows = ['ss-snap-settings-row','ss-snap-timer-row'];
    snapRows.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = (mode === 'snap') ? '' : 'none';
    });
    const qdRow = document.getElementById('ss-snap-qd-row');
    if (qdRow) {
        const subMode = document.querySelector('input[name="snap_mode"]:checked')?.value;
        qdRow.style.display = (mode === 'snap' && subMode === 'quick_draw') ? '' : 'none';
    }
}
document.querySelectorAll('input[name="game_mode"]').forEach(r => r.addEventListener('change', updateSnapRows));
document.querySelectorAll('input[name="snap_mode"]').forEach(r => r.addEventListener('change', updateSnapRows));

// Round limit hint updater
const rlInput = document.getElementById('ss-round-limit');
const rlHint  = document.getElementById('ss-rl-hint');
if (rlInput && rlHint) {
    function updateRLHint() {
        const r = parseInt(rlInput.value) || 3;
        const maxFor5 = 4 * <?php echo MFSD_SS_DB::CARDS_PER_TARGET; ?>;
        const maxFor6 = 5 * <?php echo MFSD_SS_DB::CARDS_PER_TARGET; ?>;
        if (r > maxFor5) {
            rlHint.style.color = '#b91c1c';
            rlHint.textContent = '⚠️ R=' + r + ' exceeds hand size for 5-player games (max ' + maxFor5 + ')';
        } else if (r > maxFor6) {
            rlHint.style.color = '#b91c1c';
            rlHint.textContent = '⚠️ R=' + r + ' exceeds hand size for 6-player games (max ' + maxFor6 + ')';
        } else {
            rlHint.style.color = '#065f46';
            rlHint.textContent = '✓ Valid for all player counts up to 6';
        }
    }
    rlInput.addEventListener('input', updateRLHint);
    updateRLHint();
}
</script>