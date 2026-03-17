<?php
/**
 * MFSD Super Strengths — REST API
 * Registers all routes and handles HTTP request/response.
 * Pure I/O layer — delegates all logic to MFSD_SS_Game and MFSD_SS_Validator.
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_API {

    const NS = 'mfsd-ss/v1';

    // =========================================================================
    // ROUTE REGISTRATION
    // =========================================================================
    public static function register_routes() {
        $ns = self::NS;
        $me = __CLASS__;

        register_rest_route($ns, '/state',              [['methods'=>'GET',  'callback'=>[$me,'state'],             'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/strengths',          [['methods'=>'GET',  'callback'=>[$me,'strengths'],         'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/validate-text',      [['methods'=>'POST', 'callback'=>[$me,'validate_text'],    'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/submission/save',    [['methods'=>'POST', 'callback'=>[$me,'submission_save'],  'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/submission/submit',  [['methods'=>'POST', 'callback'=>[$me,'submission_submit'],'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/hand',          [['methods'=>'GET',  'callback'=>[$me,'hand'],             'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/play',          [['methods'=>'POST', 'callback'=>[$me,'play_card'],        'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/vote',          [['methods'=>'POST', 'callback'=>[$me,'vote'],             'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/turn',          [['methods'=>'GET',  'callback'=>[$me,'turn_state'],       'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/results',       [['methods'=>'GET',  'callback'=>[$me,'results'],          'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/admin/flag-review',  [['methods'=>'POST', 'callback'=>[$me,'flag_review'],      'permission_callback'=>[$me,'is_admin']]]);
    }

    public static function auth()     { return is_user_logged_in(); }
    public static function is_admin() { return current_user_can('manage_options'); }

    private static function err($code, $msg, $status = 400) {
        return new WP_Error($code, $msg, ['status' => $status]);
    }

    // ── Convenience: get current player row for a game ────────────────────────
    private static function get_player($game_id, $user_id = null) {
        global $wpdb;
        $uid = $user_id ?: get_current_user_id();
        $pp  = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $pp WHERE user_id = %d AND game_id = %d",
            $uid, $game_id
        ), ARRAY_A);
    }

    // =========================================================================
    // GET /state  — central entry point; JS calls this on load
    // =========================================================================
    public static function state() {
        global $wpdb;
        $uid = get_current_user_id();
        $pp  = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $gp  = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
        $cp  = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        // Find the most recent active game this user belongs to
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, g.status AS game_status, g.mode AS game_mode,
                    g.id AS game_id, g.current_turn_id, g.round_limit
             FROM {$pp} p
             JOIN {$gp} g ON g.id = p.game_id
             WHERE p.user_id = %d AND g.status != 'complete'
             ORDER BY g.created_at DESC LIMIT 1",
            $uid
        ), ARRAY_A);

        if (!$player) {
            return rest_ensure_response([
                'ok'      => true,
                'status'  => 'no_game',
                'message' => 'No active game found. Ask your admin to set up a game.',
            ]);
        }

        $game_id   = (int) $player['game_id'];
        $player_id = (int) $player['id'];

        $all_players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, display_name, role, score_total,
                    confidence_tokens, submission_status, turn_order
             FROM {$pp} WHERE game_id = %d ORDER BY COALESCE(turn_order, id) ASC",
            $game_id
        ), ARRAY_A);

        // Saved card counts per target (for submission progress bars)
        $saved_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT target_player_id, COUNT(*) AS cnt
             FROM {$cp}
             WHERE game_id = %d AND author_player_id = %d AND flagged = 0
             GROUP BY target_player_id",
            $game_id, $player_id
        ), ARRAY_A);
        $saved_counts = [];
        foreach ($saved_raw as $r) {
            $saved_counts[(int) $r['target_player_id']] = (int) $r['cnt'];
        }

        $response = [
            'ok'              => true,
            'status'          => $player['game_status'],
            'game_id'         => $game_id,
            'game_mode'       => $player['game_mode'],
            'round_limit'     => (int) $player['round_limit'],
            'current_turn_id' => $player['current_turn_id'] ? (int) $player['current_turn_id'] : null,
            'player'          => [
                'id'                => $player_id,
                'display_name'      => $player['display_name'],
                'role'              => $player['role'],
                'submission_status' => $player['submission_status'],
                'score_total'       => (int) $player['score_total'],
                'confidence_tokens' => (int) $player['confidence_tokens'],
                'turn_order'        => (int) $player['turn_order'],
                'saved_counts'      => $saved_counts,
            ],
            'all_players'     => $all_players,
        ];

        // Include hand if game is in play
        if ($player['game_status'] === 'playing') {
            $cp2 = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
            $response['hand'] = $wpdb->get_results($wpdb->prepare(
                "SELECT c.id, c.strength_text, c.played,
                        p.display_name AS target_name
                 FROM {$cp2} c
                 JOIN {$pp} p ON p.id = c.target_player_id
                 WHERE c.game_id = %d AND c.dealt_to_player_id = %d
                 ORDER BY c.played ASC, c.id ASC",
                $game_id, $player_id
            ), ARRAY_A);
        }

        return rest_ensure_response($response);
    }

    // =========================================================================
    // GET /strengths  — grouped by category
    // =========================================================================
    public static function strengths() {
        global $wpdb;
        $sp   = $wpdb->prefix . MFSD_SS_DB::TBL_STRENGTHS;
        $rows = $wpdb->get_results(
            "SELECT id, strength_text, category FROM $sp WHERE active = 1 ORDER BY category, strength_text",
            ARRAY_A
        );

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['category']][] = ['id' => (int) $r['id'], 'text' => $r['strength_text']];
        }

        return rest_ensure_response(['ok' => true, 'strengths' => $grouped]);
    }

    // =========================================================================
    // POST /validate-text  — free-text content check
    // =========================================================================
    public static function validate_text(WP_REST_Request $req) {
        $text   = sanitize_text_field($req->get_param('text') ?? '');
        $result = MFSD_SS_Validator::validate($text);
        return rest_ensure_response(['ok' => true, 'result' => $result]);
    }

    // =========================================================================
    // POST /submission/save  — save draft cards for one target
    // =========================================================================
    public static function submission_save(WP_REST_Request $req) {
        global $wpdb;
        $uid       = get_current_user_id();
        $game_id   = (int) $req->get_param('game_id');
        $target_id = (int) $req->get_param('target_player_id');
        $strengths = $req->get_param('strengths') ?? [];

        $player = self::get_player($game_id, $uid);
        if (!$player)                               return self::err('not_in_game', 'Player not in game', 403);
        if ($player['submission_status'] === 'submitted') return self::err('already_submitted', 'Already submitted', 409);

        $player_id = (int) $player['id'];
        $cp  = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $fl  = $wpdb->prefix . MFSD_SS_DB::TBL_FLAGS;
        $sp  = $wpdb->prefix . MFSD_SS_DB::TBL_STRENGTHS;
        $max = MFSD_SS_DB::CARDS_PER_TARGET;

        // Clear existing drafts for this target so re-saves are idempotent
        $wpdb->delete($cp, [
            'game_id'          => $game_id,
            'author_player_id' => $player_id,
            'target_player_id' => $target_id,
        ]);

        $saved = $flagged = 0;

        foreach (array_slice($strengths, 0, $max) as $s) {
            $type   = sanitize_text_field($s['type'] ?? 'list');
            $text   = sanitize_text_field($s['text'] ?? '');
            $str_id = !empty($s['strength_id']) ? (int) $s['strength_id'] : null;
            $is_ft  = ($type === 'free') ? 1 : 0;
            $flag   = 0;

            if (empty($text)) continue;

            if ($is_ft) {
                $vr = MFSD_SS_Validator::validate($text);
                if ($vr['action'] === 'block') continue; // silently skip blocked
                if ($vr['action'] === 'flag') {
                    $flag = 1;
                    $flagged++;
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

            // No same-author duplicate to same target
            $dup = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $cp
                 WHERE game_id = %d AND author_player_id = %d
                   AND target_player_id = %d AND strength_text = %s",
                $game_id, $player_id, $target_id, $text
            ));
            if ($dup) continue;

            $wpdb->insert($cp, [
                'game_id'          => $game_id,
                'author_player_id' => $player_id,
                'target_player_id' => $target_id,
                'strength_id'      => $str_id,
                'strength_text'    => $text,
                'is_free_text'     => $is_ft,
                'flagged'          => $flag,
            ]);

            // Increment strength usage counter
            if ($str_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $sp SET times_used = times_used + 1 WHERE id = %d",
                    $str_id
                ));
            }

            $saved++;
        }

        return rest_ensure_response([
            'ok'        => true,
            'saved'     => $saved,
            'flagged'   => $flagged,
            'target_id' => $target_id,
        ]);
    }

    // =========================================================================
    // POST /submission/submit  — lock all cards; trigger deal if all done
    // =========================================================================
    public static function submission_submit(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int) $player['id'];
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        // Verify 5 cards saved per every other player
        $others = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name FROM $pp WHERE game_id = %d AND id != %d",
            $game_id, $player_id
        ), ARRAY_A);

        foreach ($others as $other) {
            $cnt = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $cp
                 WHERE game_id = %d AND author_player_id = %d AND target_player_id = %d AND flagged = 0",
                $game_id, $player_id, (int) $other['id']
            ));
            if ($cnt < MFSD_SS_DB::CARDS_PER_TARGET) {
                return self::err('incomplete',
                    "You need exactly " . MFSD_SS_DB::CARDS_PER_TARGET .
                    " cards for {$other['display_name']} — you have {$cnt}.",
                    400
                );
            }
        }

        // Mark submitted
        $wpdb->update($pp, [
            'submission_status' => 'submitted',
            'submitted_at'      => current_time('mysql'),
        ], ['id' => $player_id]);

        // ── Ordering: mark in_progress when student submits their cards ────
        if ( function_exists( 'mfsd_set_task_status' ) && get_option( 'mfsd_ss_course_management', '1' ) === '1' ) {
            mfsd_set_task_status( $uid, 'super_strengths', 'in_progress' );
        }
        // ──────────────────────────────────────────────────────────────────

        // Deal if everyone is done
        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $pp WHERE game_id = %d AND submission_status = 'pending'",
            $game_id
        ));

        $all_submitted = ($pending === 0);
        if ($all_submitted) {
            MFSD_SS_Game::deal_cards($game_id);
        }

        $all = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name, submission_status FROM $pp WHERE game_id = %d",
            $game_id
        ), ARRAY_A);

        return rest_ensure_response([
            'ok'           => true,
            'all_submitted' => $all_submitted,
            'players'      => $all,
        ]);
    }

    // =========================================================================
    // GET /game/hand
    // =========================================================================
    public static function hand(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $hand = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.strength_text, c.played,
                    p.display_name AS target_name
             FROM {$cp} c
             JOIN {$pp} p ON p.id = c.target_player_id
             WHERE c.game_id = %d AND c.dealt_to_player_id = %d
             ORDER BY c.played ASC, c.id ASC",
            $game_id, (int) $player['id']
        ), ARRAY_A);

        return rest_ensure_response(['ok' => true, 'hand' => $hand]);
    }

    // =========================================================================
    // POST /game/play  — player chooses a card to play into centre
    // =========================================================================
    public static function play_card(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');
        $card_id = (int) $req->get_param('card_id');

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int) $player['id'];
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        // Confirm it's this player's turn and they haven't played yet
        $turn = $wpdb->get_row($wpdb->prepare(
            "SELECT t.* FROM {$tp} t
             JOIN {$gp} g ON g.current_turn_id = t.id
             WHERE g.id = %d AND t.played_by_player_id = %d AND t.card_id = 0",
            $game_id, $player_id
        ), ARRAY_A);

        if (!$turn) return self::err('not_your_turn', "It's not your turn to play", 409);

        // Card must be in this player's hand, unplayed
        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, p.display_name AS target_name
             FROM {$cp} c
             JOIN {$pp} p ON p.id = c.target_player_id
             WHERE c.id = %d AND c.game_id = %d AND c.dealt_to_player_id = %d AND c.played = 0",
            $card_id, $game_id, $player_id
        ), ARRAY_A);

        if (!$card) return self::err('invalid_card', 'Invalid card selection', 400);

        $timeout_h = (int) get_option('mfsd_ss_vote_timeout', 24);
        $reveal_at = date('Y-m-d H:i:s', strtotime("+{$timeout_h} hours"));

        $wpdb->update($cp, ['played' => 1], ['id' => $card_id]);
        $wpdb->update($tp, [
            'card_id'           => $card_id,
            'phase'             => 'A',
            'phase_a_reveal_at' => $reveal_at,
        ], ['id' => (int) $turn['id']]);

        return rest_ensure_response([
            'ok'      => true,
            'turn_id' => (int) $turn['id'],
            'card'    => [
                'id'            => $card_id,
                'strength_text' => $card['strength_text'],
                'target_name'   => $card['target_name'],
            ],
            'reveal_at' => $reveal_at,
        ]);
    }

    // =========================================================================
    // GET /game/turn  — full turn state; JS polls this
    // =========================================================================
    public static function turn_state(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int) $player['id'];

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $vp = $wpdb->prefix . MFSD_SS_DB::TBL_VOTES;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $game = $wpdb->get_row($wpdb->prepare(
            "SELECT status, current_turn_id, mode FROM $gp WHERE id = %d",
            $game_id
        ), ARRAY_A);

        if ($game['status'] === 'complete') {
            return rest_ensure_response(['ok' => true, 'game_status' => 'complete']);
        }

        if (!$game['current_turn_id']) {
            return rest_ensure_response(['ok' => true, 'game_status' => 'waiting']);
        }

        $turn = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, c.strength_text, c.target_player_id, c.author_player_id,
                    pt.display_name AS target_name,
                    pa.display_name AS author_name,
                    pp2.display_name AS played_by_name
             FROM {$tp} t
             LEFT JOIN {$cp} c    ON c.id   = t.card_id
             LEFT JOIN {$pp} pt   ON pt.id  = c.target_player_id
             LEFT JOIN {$pp} pa   ON pa.id  = c.author_player_id
             LEFT JOIN {$pp} pp2  ON pp2.id = t.played_by_player_id
             WHERE t.id = %d",
            (int) $game['current_turn_id']
        ), ARRAY_A);

        if (!$turn) {
            return rest_ensure_response(['ok' => true, 'game_status' => 'waiting']);
        }

        $n = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $pp WHERE game_id = %d", $game_id
        ));

        // Count votes in for current phase
        $votes_in = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $vp WHERE turn_id = %d AND phase = %s",
            (int) $turn['id'], $turn['phase']
        ));
        $expected = ($turn['phase'] === 'A') ? $n - 1 : $n;
        $all_voted = ($votes_in >= $expected) && $turn['phase'] !== 'complete';

        // Auto-reveal if all votes in
        if ($all_voted) {
            MFSD_SS_Game::process_reveal($turn, $game_id, $game['mode']);
            // Reload fresh turn data
            $turn = $wpdb->get_row($wpdb->prepare(
                "SELECT t.*, c.strength_text, c.target_player_id, c.author_player_id,
                        pt.display_name AS target_name,
                        pa.display_name AS author_name,
                        pp2.display_name AS played_by_name
                 FROM {$tp} t
                 LEFT JOIN {$cp} c    ON c.id   = t.card_id
                 LEFT JOIN {$pp} pt   ON pt.id  = c.target_player_id
                 LEFT JOIN {$pp} pa   ON pa.id  = c.author_player_id
                 LEFT JOIN {$pp} pp2  ON pp2.id = t.played_by_player_id
                 WHERE t.id = %d",
                (int) $game['current_turn_id']
            ), ARRAY_A);
        }

        // My vote for the current phase
        $my_vote = $wpdb->get_row($wpdb->prepare(
            "SELECT selected_player_id, is_confident, is_correct, points_earned
             FROM $vp WHERE turn_id = %d AND voter_player_id = %d AND phase = %s",
            (int) $turn['id'], $player_id, $turn['phase']
        ), ARRAY_A);

        // Phase A vote results (shown once Phase B begins or turn is complete)
        $reveal_votes = [];
        if (in_array($turn['phase'], ['B','complete'])) {
            $reveal_votes = $wpdb->get_results($wpdb->prepare(
                "SELECT v.voter_player_id, v.selected_player_id, v.is_confident,
                        v.is_correct, v.points_earned,
                        pv.display_name AS voter_name,
                        ps.display_name AS selected_name
                 FROM {$vp} v
                 JOIN {$pp} pv ON pv.id = v.voter_player_id
                 JOIN {$pp} ps ON ps.id = v.selected_player_id
                 WHERE v.turn_id = %d AND v.phase = 'A'",
                (int) $turn['id']
            ), ARRAY_A);
        }

        $all_players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name, score_total, confidence_tokens FROM $pp
             WHERE game_id = %d ORDER BY COALESCE(turn_order, id)",
            $game_id
        ), ARRAY_A);

        $is_card_player = ((int) $turn['played_by_player_id'] === $player_id);

        return rest_ensure_response([
            'ok'             => true,
            'game_status'    => $game['status'],
            'player_id'      => $player_id,
            'is_card_player' => $is_card_player,
            'votes_in'       => $votes_in,
            'expected_voters'=> $expected,
            'all_voted'      => $all_voted,
            'all_players'    => $all_players,
            'my_vote'        => $my_vote,
            'reveal_votes'   => $reveal_votes,
            'turn'           => [
                'id'             => (int) $turn['id'],
                'turn_number'    => (int) $turn['turn_number'],
                'phase'          => $turn['phase'],
                'played_by_id'   => (int) $turn['played_by_player_id'],
                'played_by_name' => $turn['played_by_name'],
                'card_id'        => $turn['card_id'] ? (int) $turn['card_id'] : null,
                'strength_text'  => $turn['card_id'] ? $turn['strength_text'] : null,
                // Revealed after Phase A
                'target_name'    => in_array($turn['phase'], ['B','complete']) ? $turn['target_name'] : null,
                'target_id'      => in_array($turn['phase'], ['B','complete']) ? (int) $turn['target_player_id'] : null,
                // Revealed after Phase B (or short mode after A)
                'author_name'    => ($turn['phase'] === 'complete') ? $turn['author_name'] : null,
                'author_id'      => ($turn['phase'] === 'complete') ? (int) $turn['author_player_id'] : null,
                'round_winner_id'=> $turn['round_winner_player_id'] ? (int) $turn['round_winner_player_id'] : null,
            ],
        ]);
    }

    // =========================================================================
    // POST /game/vote
    // =========================================================================
    public static function vote(WP_REST_Request $req) {
        global $wpdb;
        $uid        = get_current_user_id();
        $game_id    = (int) $req->get_param('game_id');
        $turn_id    = (int) $req->get_param('turn_id');
        $phase      = sanitize_text_field($req->get_param('phase') ?? 'A');
        $selected   = (int) $req->get_param('selected_player_id');
        $confident  = !empty($req->get_param('is_confident'));

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int) $player['id'];
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $vp = $wpdb->prefix . MFSD_SS_DB::TBL_VOTES;

        // Validate turn is in the correct phase
        $turn = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tp WHERE id = %d AND game_id = %d AND phase = %s",
            $turn_id, $game_id, $phase
        ), ARRAY_A);
        if (!$turn) return self::err('invalid_turn', 'Turn not in that phase', 400);

        // Phase A: card player sits out
        if ($phase === 'A' && $player_id === (int) $turn['played_by_player_id']) {
            return self::err('cannot_vote', 'Card player cannot vote in Phase A', 403);
        }

        // Can't use confidence token if none left
        if ($confident && (int) $player['confidence_tokens'] <= 0) {
            $confident = false;
        }

        // Upsert vote
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $vp WHERE turn_id = %d AND voter_player_id = %d AND phase = %s",
            $turn_id, $player_id, $phase
        ));

        $data = [
            'turn_id'            => $turn_id,
            'game_id'            => $game_id,
            'phase'              => $phase,
            'voter_player_id'    => $player_id,
            'selected_player_id' => $selected,
            'is_confident'       => $confident ? 1 : 0,
            'submitted_at'       => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($vp, $data, ['id' => $existing]);
        } else {
            $wpdb->insert($vp, $data);
        }

        // Deduct confidence token if used
        if ($confident) {
            $wpdb->update($pp,
                ['confidence_tokens' => max(0, (int) $player['confidence_tokens'] - 1)],
                ['id' => $player_id]
            );
        }

        return rest_ensure_response(['ok' => true, 'phase' => $phase, 'is_confident' => $confident]);
    }

    // =========================================================================
    // GET /game/results  — final scores + all cards received + AI summary
    // =========================================================================
    public static function results(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int) $player['id'];
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $scores = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name, score_total FROM $pp
             WHERE game_id = %d ORDER BY score_total DESC",
            $game_id
        ), ARRAY_A);

        $received = $wpdb->get_results($wpdb->prepare(
            "SELECT c.strength_text, c.is_free_text, c.played,
                    p.display_name AS author_name
             FROM {$cp} c
             JOIN {$pp} p ON p.id = c.author_player_id
             WHERE c.game_id = %d AND c.target_player_id = %d AND c.flagged = 0
             ORDER BY c.played DESC, c.strength_text ASC",
            $game_id, $player_id
        ), ARRAY_A);

        $ai_summary = MFSD_SS_Game::generate_strengths_summary(
            $received,
            $player['display_name']
        );

        // ── Ordering: mark completed when student reaches their results ────
        // Only fires when the game is fully complete — the student has played
        // through the whole activity including the guessing game.
        if ( function_exists( 'mfsd_set_task_status' ) && get_option( 'mfsd_ss_course_management', '1' ) === '1' ) {
            global $wpdb;
            $gp_check = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
            $game_status = $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM $gp_check WHERE id = %d", $game_id
            ) );
            if ( $game_status === 'complete' ) {
                mfsd_set_task_status( $uid, 'super_strengths', 'completed' );
            }
        }
        // ──────────────────────────────────────────────────────────────────

        return rest_ensure_response([
            'ok'         => true,
            'scores'     => $scores,
            'my_cards'   => $received,
            'ai_summary' => $ai_summary,
            'player_id'  => $player_id,
        ]);
    }

    // =========================================================================
    // POST /admin/flag-review  — allow or reject a flagged free-text card
    // =========================================================================
    public static function flag_review(WP_REST_Request $req) {
        global $wpdb;
        $flag_id    = (int) $req->get_param('flag_id');
        $action     = sanitize_text_field($req->get_param('action_type') ?? 'reject');
        $admin_uid  = get_current_user_id();

        $fl = $wpdb->prefix . MFSD_SS_DB::TBL_FLAGS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $flag = $wpdb->get_row($wpdb->prepare("SELECT * FROM $fl WHERE id = %d", $flag_id), ARRAY_A);
        if (!$flag) return self::err('not_found', 'Flag not found', 404);

        $status = ($action === 'allow') ? 'allowed' : 'rejected';
        $wpdb->update($fl, [
            'status'      => $status,
            'reviewed_by' => $admin_uid,
            'reviewed_at' => current_time('mysql'),
        ], ['id' => $flag_id]);

        if ($action === 'allow') {
            // Unflag the associated card so it enters the game pool
            $wpdb->update($cp, ['flagged' => 0], [
                'game_id'          => (int) $flag['game_id'],
                'author_player_id' => (int) $flag['player_id'],
                'target_player_id' => (int) $flag['target_player_id'],
                'strength_text'    => $flag['submitted_text'],
            ]);
        } else {
            // Hard reject — remove from pool
            $wpdb->delete($cp, [
                'game_id'          => (int) $flag['game_id'],
                'author_player_id' => (int) $flag['player_id'],
                'target_player_id' => (int) $flag['target_player_id'],
                'strength_text'    => $flag['submitted_text'],
            ]);
        }

        return rest_ensure_response(['ok' => true, 'action' => $action]);
    }
}