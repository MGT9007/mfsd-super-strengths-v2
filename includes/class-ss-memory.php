<?php
/**
 * MFSD Super Strengths — Memory game engine
 * Phase 1 (self-strengths save/submit), Phase 2 (cards for others save/submit), board dealing
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_Memory {

    const REQUIRED_SELF  = 5;
    const REQUIRED_CARDS = 5; // mirrors CARDS_PER_TARGET

    // =========================================================================
    // GAME START (student only)
    // Creates mfsd_sm_games + mfsd_sm_players rows for student + linked parents
    // =========================================================================
    public static function start_game(int $student_id): array {
        global $wpdb;

        $lt  = $wpdb->prefix . 'mfsd_parent_student_links';
        $smg = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $smp = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;

        // Reject if already in an active game
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT g.id FROM $smg g JOIN $smp p ON p.game_id = g.id
             WHERE p.user_id = %d AND g.status != 'complete' LIMIT 1",
            $student_id
        ));
        if ($existing) {
            return ['error' => 'game_exists', 'message' => 'You already have an active game.'];
        }

        $linked_parents = $wpdb->get_results($wpdb->prepare(
            "SELECT l.parent_user_id, l.relationship_type, u.display_name
             FROM {$lt} l JOIN {$wpdb->users} u ON u.ID = l.parent_user_id
             WHERE l.student_user_id = %d AND l.link_status = 'active'",
            $student_id
        ), ARRAY_A);

        if (empty($linked_parents)) {
            return ['error' => 'no_links', 'message' => 'No linked family members found.'];
        }

        $student  = get_userdata($student_id);
        $game_key = 'sm_' . $student_id . '_' . time();

        $wpdb->insert($smg, [
            'game_key'          => $game_key,
            'game_type'         => 'family',
            'student_user_id'   => $student_id,
            'status'            => 'submission_self',
            'memory_mode'       => get_option('mfsd_ss_memory_mode', 'first_to_x'),
            'card_pool'         => get_option('mfsd_ss_card_pool', 'family_cards'),
            'target_matches'    => (int) get_option('mfsd_ss_memory_target_matches', 5),
            'time_limit_mins'   => (int) get_option('mfsd_ss_memory_time_limit', 5),
            'turn_timeout_mins' => (int) get_option('mfsd_ss_turn_timeout_mins', 5),
            'created_at'        => current_time('mysql'),
        ]);
        $game_id = $wpdb->insert_id;

        // Student first (turn_order = 1)
        $wpdb->insert($smp, [
            'game_id'      => $game_id,
            'user_id'      => $student_id,
            'display_name' => $student->display_name,
            'role'         => 'student',
            'turn_order'   => 1,
            'joined_at'    => current_time('mysql'),
        ]);

        $valid_roles = ['parent', 'carer', 'sibling', 'other'];
        $turn_order  = 2;
        foreach ($linked_parents as $p) {
            $role = in_array($p['relationship_type'], $valid_roles) ? $p['relationship_type'] : 'parent';
            $wpdb->insert($smp, [
                'game_id'      => $game_id,
                'user_id'      => (int) $p['parent_user_id'],
                'display_name' => $p['display_name'],
                'role'         => $role,
                'turn_order'   => $turn_order++,
                'joined_at'    => current_time('mysql'),
            ]);
        }

        return ['game_id' => $game_id, 'game_key' => $game_key];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================
    public static function get_player(int $game_id, int $user_id): ?array {
        global $wpdb;
        $smp = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $smp WHERE game_id = %d AND user_id = %d",
            $game_id, $user_id
        ), ARRAY_A) ?: null;
    }

    public static function get_active_game(int $user_id): ?array {
        global $wpdb;
        $smg = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $smp = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT g.* FROM $smg g JOIN $smp p ON p.game_id = g.id
             WHERE p.user_id = %d AND g.status != 'complete'
             ORDER BY g.created_at DESC LIMIT 1",
            $user_id
        ), ARRAY_A) ?: null;
    }

    // =========================================================================
    // PHASE 1 — SELF-STRENGTHS
    // =========================================================================

    /**
     * Save (replace) self-strength picks for a player.
     * Idempotent — called on every card selection change before submit.
     */
    public static function save_self_strengths(int $game_id, int $player_id, array $strengths): array {
        global $wpdb;
        $smss = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SELF_STRENGTHS;
        $sp   = $wpdb->prefix . MFSD_SS_DB::TBL_STRENGTHS;

        $wpdb->delete($smss, ['game_id' => $game_id, 'player_id' => $player_id]);

        $saved = 0;
        foreach (array_slice($strengths, 0, self::REQUIRED_SELF) as $s) {
            $text   = sanitize_text_field($s['strength_text'] ?? '');
            $str_id = !empty($s['strength_id']) ? (int) $s['strength_id'] : null;

            if (empty($text)) continue;

            // Free-text: run through validator; block if invalid, skip flagged (keep the pick)
            if (empty($str_id)) {
                $vr = MFSD_SS_Validator::validate($text);
                if ($vr['action'] === 'block') continue;
            }

            $wpdb->insert($smss, [
                'game_id'       => $game_id,
                'player_id'     => $player_id,
                'strength_id'   => $str_id,
                'strength_text' => $text,
                'created_at'    => current_time('mysql'),
            ]);

            if ($str_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $sp SET times_used = times_used + 1 WHERE id = %d", $str_id
                ));
            }

            $saved++;
        }

        return ['saved' => $saved, 'complete' => ($saved >= self::REQUIRED_SELF)];
    }

    /**
     * Lock Phase 1 for a player.
     * If all players have submitted, advances game status to submission_others.
     */
    public static function submit_self(int $game_id, int $player_id): array {
        global $wpdb;
        $smss = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SELF_STRENGTHS;
        $smp  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $smss WHERE game_id = %d AND player_id = %d",
            $game_id, $player_id
        ));
        if ($count < self::REQUIRED_SELF) {
            return ['error' => 'incomplete', 'message' => "You need to pick " . self::REQUIRED_SELF . " strengths — you have {$count}."];
        }

        $wpdb->update($smp, ['self_submitted' => 1], ['id' => $player_id]);

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $smp WHERE game_id = %d AND self_submitted = 0",
            $game_id
        ));

        if ($pending === 0) {
            $wpdb->update($smg, ['status' => 'submission_others'], ['id' => $game_id]);
        }

        return ['ok' => true, 'all_submitted' => ($pending === 0), 'next_phase' => ($pending === 0) ? 'submission_others' : 'waiting'];
    }

    // =========================================================================
    // PHASE 2 — WRITING CARDS FOR OTHERS
    // =========================================================================

    /**
     * Save (replace) cards written by player for a specific target.
     * Idempotent — called on every change before submit.
     */
    public static function save_card_for_target(int $game_id, int $player_id, int $target_id, array $strengths): array {
        global $wpdb;
        $smc = $wpdb->prefix . MFSD_SS_DB::TBL_SM_CARDS;
        $sp  = $wpdb->prefix . MFSD_SS_DB::TBL_STRENGTHS;
        $fl  = $wpdb->prefix . MFSD_SS_DB::TBL_FLAGS;

        $wpdb->delete($smc, [
            'game_id'          => $game_id,
            'author_player_id' => $player_id,
            'target_player_id' => $target_id,
        ]);

        $saved = $flagged = 0;
        foreach (array_slice($strengths, 0, self::REQUIRED_CARDS) as $s) {
            $text   = sanitize_text_field($s['strength_text'] ?? '');
            $str_id = !empty($s['strength_id']) ? (int) $s['strength_id'] : null;
            $is_ft  = (($s['type'] ?? 'list') === 'free') ? 1 : 0;
            $flag   = 0;

            if (empty($text)) continue;

            if ($is_ft) {
                $vr = MFSD_SS_Validator::validate($text);
                if ($vr['action'] === 'block') continue;
                if ($vr['action'] === 'flag') {
                    $flag = 1; $flagged++;
                    $wpdb->insert($fl, [
                        'game_id'          => $game_id,
                        'player_id'        => $player_id,
                        'target_player_id' => $target_id,
                        'submitted_text'   => $text,
                        'matched_rule'     => $vr['matched'] ?? 'pattern',
                        'status'           => 'pending',
                    ]);
                }
            }

            $wpdb->insert($smc, [
                'game_id'          => $game_id,
                'author_player_id' => $player_id,
                'target_player_id' => $target_id,
                'strength_id'      => $str_id,
                'strength_text'    => $text,
                'is_free_text'     => $is_ft,
                'flagged'          => $flag,
                'approved'         => 1,
                'created_at'       => current_time('mysql'),
            ]);

            if ($str_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $sp SET times_used = times_used + 1 WHERE id = %d", $str_id
                ));
            }
            $saved++;
        }

        return ['saved' => $saved, 'flagged' => $flagged, 'target_id' => $target_id];
    }

    /**
     * Lock Phase 2 for a player.
     * If all players have submitted, triggers deal_board().
     */
    public static function submit_others(int $game_id, int $player_id): array {
        global $wpdb;
        $smc = $wpdb->prefix . MFSD_SS_DB::TBL_SM_CARDS;
        $smp = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;

        $others = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name FROM $smp WHERE game_id = %d AND id != %d",
            $game_id, $player_id
        ), ARRAY_A);

        foreach ($others as $other) {
            $cnt = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $smc
                 WHERE game_id = %d AND author_player_id = %d AND target_player_id = %d AND flagged = 0",
                $game_id, $player_id, (int) $other['id']
            ));
            if ($cnt < self::REQUIRED_CARDS) {
                return ['error' => 'incomplete', 'message' => "You need " . self::REQUIRED_CARDS . " cards for {$other['display_name']} — you have {$cnt}."];
            }
        }

        $wpdb->update($smp, ['others_submitted' => 1], ['id' => $player_id]);

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $smp WHERE game_id = %d AND others_submitted = 0",
            $game_id
        ));

        $all_submitted = ($pending === 0);
        if ($all_submitted) {
            self::deal_board($game_id);
        }

        $all = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name, others_submitted FROM $smp WHERE game_id = %d",
            $game_id
        ), ARRAY_A);

        return ['ok' => true, 'all_submitted' => $all_submitted, 'players' => $all];
    }

    // =========================================================================
    // BOARD DEALING (Phase 3 — server-side, instant)
    // Duplicates each card into a matched pair, shuffles, writes to mfsd_sm_board.
    // Advances status: dealing → playing (synchronous — frontend sees 'playing' after poll).
    // =========================================================================
    public static function deal_board(int $game_id): void {
        global $wpdb;
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $smp  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        $smc  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_CARDS;
        $smss = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SELF_STRENGTHS;
        $smb  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_BOARD;

        $wpdb->update($smg, ['status' => 'dealing'], ['id' => $game_id]);

        $game      = $wpdb->get_row($wpdb->prepare("SELECT * FROM $smg WHERE id = %d", $game_id), ARRAY_A);
        $card_pool = $game['card_pool'] ?? 'family_cards';

        // Collect source cards
        $tiles = [];

        $family_cards = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.strength_text, c.author_player_id, c.target_player_id,
                    pa.display_name AS author_display, pt.display_name AS target_display
             FROM {$smc} c
             JOIN {$smp} pa ON pa.id = c.author_player_id
             JOIN {$smp} pt ON pt.id = c.target_player_id
             WHERE c.game_id = %d AND c.flagged = 0 AND c.approved = 1",
            $game_id
        ), ARRAY_A);

        foreach ($family_cards as $card) {
            $tiles[] = [
                'card_type'        => 'family_card',
                'card_id'          => (int) $card['id'],
                'self_strength_id' => null,
                'author_player_id' => (int) $card['author_player_id'],
                'target_player_id' => (int) $card['target_player_id'],
                'strength_text'    => $card['strength_text'],
                'author_display'   => $card['author_display'],
                'target_display'   => $card['target_display'],
            ];
        }

        if ($card_pool === 'all_cards') {
            $self_cards = $wpdb->get_results($wpdb->prepare(
                "SELECT ss.id, ss.strength_text, ss.player_id, p.display_name AS player_display
                 FROM {$smss} ss JOIN {$smp} p ON p.id = ss.player_id
                 WHERE ss.game_id = %d",
                $game_id
            ), ARRAY_A);

            foreach ($self_cards as $ss) {
                $tiles[] = [
                    'card_type'        => 'self_strength',
                    'card_id'          => null,
                    'self_strength_id' => (int) $ss['id'],
                    'author_player_id' => (int) $ss['player_id'],
                    'target_player_id' => (int) $ss['player_id'],
                    'strength_text'    => $ss['strength_text'],
                    'author_display'   => $ss['player_display'],
                    'target_display'   => $ss['player_display'],
                ];
            }
        }

        // Duplicate each tile into a matched pair
        $pairs       = [];
        $pair_index  = 0;
        foreach ($tiles as $tile) {
            $pair_key = 'p_' . $game_id . '_' . $pair_index;
            $pairs[]  = array_merge($tile, ['pair_key' => $pair_key]);
            $pairs[]  = array_merge($tile, ['pair_key' => $pair_key]);
            $pair_index++;
        }

        shuffle($pairs);

        foreach ($pairs as $pos => $tile) {
            $wpdb->insert($smb, [
                'game_id'          => $game_id,
                'position'         => $pos,
                'pair_key'         => $tile['pair_key'],
                'card_type'        => $tile['card_type'],
                'card_id'          => $tile['card_id'],
                'self_strength_id' => $tile['self_strength_id'],
                'author_player_id' => $tile['author_player_id'],
                'target_player_id' => $tile['target_player_id'],
                'strength_text'    => $tile['strength_text'],
                'author_display'   => $tile['author_display'],
                'target_display'   => $tile['target_display'],
                'is_face_up'       => 0,
                'is_matched'       => 0,
            ]);
        }

        // Set current_turn_started_at for the first player (student, turn_order=1)
        $first = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $smp WHERE game_id = %d ORDER BY turn_order ASC LIMIT 1",
            $game_id
        ), ARRAY_A);

        if ($first) {
            $wpdb->update($smp,
                ['current_turn_started_at' => current_time('mysql')],
                ['id' => (int) $first['id']]
            );
        }

        $now         = current_time('mysql');
        $update_data = ['status' => 'playing', 'game_started_at' => $now];

        if ($game['memory_mode'] === 'timed') {
            $mins = (int) $game['time_limit_mins'];
            $update_data['game_ends_at'] = gmdate('Y-m-d H:i:s', strtotime("+{$mins} minutes", strtotime($now)));
        }

        $wpdb->update($smg, $update_data, ['id' => $game_id]);
    }
}
