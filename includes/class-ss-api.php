<?php
/**
 * MFSD Super Strengths — REST API
 * Guessing game + Snap game routes.
 * Pure I/O — delegates logic to MFSD_SS_Game and MFSD_SS_Validator.
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_API {

    const NS = 'mfsd-ss/v1';

    public static function register_routes() {
        $ns = self::NS;
        $me = __CLASS__;

        // ── Core ──────────────────────────────────────────────────────────────
        register_rest_route($ns, '/state',             [['methods'=>'GET',  'callback'=>[$me,'state'],            'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/start',        [['methods'=>'POST', 'callback'=>[$me,'start_game'],       'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/strengths',         [['methods'=>'GET',  'callback'=>[$me,'strengths'],        'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/validate-text',     [['methods'=>'POST', 'callback'=>[$me,'validate_text'],   'permission_callback'=>[$me,'auth']]]);

        // ── Submission (shared by all modes) ──────────────────────────────────
        register_rest_route($ns, '/submission/save',   [['methods'=>'POST', 'callback'=>[$me,'submission_save'],  'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/submission/submit', [['methods'=>'POST', 'callback'=>[$me,'submission_submit'],'permission_callback'=>[$me,'auth']]]);

        // ── Guessing game ────────────────────────────────────────────────────
        register_rest_route($ns, '/game/hand',         [['methods'=>'GET',  'callback'=>[$me,'hand'],             'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/play',         [['methods'=>'POST', 'callback'=>[$me,'play_card'],        'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/vote',         [['methods'=>'POST', 'callback'=>[$me,'vote'],             'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/turn',         [['methods'=>'GET',  'callback'=>[$me,'turn_state'],       'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/results',      [['methods'=>'GET',  'callback'=>[$me,'results'],          'permission_callback'=>[$me,'auth']]]);

        // ── Snap game ────────────────────────────────────────────────────────
        register_rest_route($ns, '/snap/join',         [['methods'=>'POST', 'callback'=>[$me,'snap_join'],        'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/snap/session',      [['methods'=>'GET',  'callback'=>[$me,'snap_session'],     'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/snap/play-card',    [['methods'=>'POST', 'callback'=>[$me,'snap_play_card'],   'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/snap/claim',        [['methods'=>'POST', 'callback'=>[$me,'snap_claim'],       'permission_callback'=>[$me,'auth']]]);

        // ── Admin ────────────────────────────────────────────────────────────
        register_rest_route($ns, '/admin/flag-review', [['methods'=>'POST', 'callback'=>[$me,'flag_review'],      'permission_callback'=>[$me,'is_admin']]]);
    }

    public static function auth()     { return is_user_logged_in(); }
    public static function is_admin() { return current_user_can('manage_options'); }

    private static function err($code, $msg, $status = 400) {
        return new WP_Error($code, $msg, ['status' => $status]);
    }

    private static function get_player($game_id, $user_id = null) {
        global $wpdb;
        $uid = $user_id ?: get_current_user_id();
        $pp  = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $pp WHERE user_id = %d AND game_id = %d", $uid, $game_id
        ), ARRAY_A);
    }

    // =========================================================================
    // GET /state
    // =========================================================================
    public static function state() {
        global $wpdb;
        $uid = get_current_user_id();
        $pp  = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $gp  = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
        $cp  = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, g.status AS game_status, g.mode AS game_mode,
                    g.id AS game_id, g.current_turn_id, g.round_limit
             FROM {$pp} p
             JOIN {$gp} g ON g.id = p.game_id
             WHERE p.user_id = %d AND g.status != 'complete'
             ORDER BY g.created_at DESC LIMIT 1",
            $uid
        ), ARRAY_A);

        if (!$player) return rest_ensure_response(self::no_game_response($uid));

        $game_id   = (int) $player['game_id'];
        $player_id = (int) $player['id'];

        $all_players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, display_name, role, score_total,
                    confidence_tokens, submission_status, turn_order
             FROM {$pp} WHERE game_id = %d ORDER BY COALESCE(turn_order, id) ASC",
            $game_id
        ), ARRAY_A);

        $saved_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT target_player_id, COUNT(*) AS cnt
             FROM {$cp}
             WHERE game_id = %d AND author_player_id = %d AND flagged = 0
             GROUP BY target_player_id",
            $game_id, $player_id
        ), ARRAY_A);
        $saved_counts = [];
        foreach ($saved_raw as $r) $saved_counts[(int)$r['target_player_id']] = (int)$r['cnt'];

        $response = [
            'ok'              => true,
            'status'          => $player['game_status'],
            'game_id'         => $game_id,
            'game_mode'       => $player['game_mode'],
            'round_limit'     => (int) $player['round_limit'],
            'current_turn_id' => $player['current_turn_id'] ? (int)$player['current_turn_id'] : null,
            'player'          => [
                'id'                => $player_id,
                'display_name'      => $player['display_name'],
                'role'              => $player['role'],
                'submission_status' => $player['submission_status'],
                'score_total'       => (int)$player['score_total'],
                'confidence_tokens' => (int)$player['confidence_tokens'],
                'turn_order'        => (int)$player['turn_order'],
                'saved_counts'      => $saved_counts,
            ],
            'all_players' => $all_players,
        ];

        if ($player['game_status'] === 'playing' && $player['game_mode'] !== 'snap') {
            $response['hand'] = $wpdb->get_results($wpdb->prepare(
                "SELECT c.id, c.strength_text, c.played, p.display_name AS target_name
                 FROM {$cp} c
                 JOIN {$pp} p ON p.id = c.target_player_id
                 WHERE c.game_id = %d AND c.dealt_to_player_id = %d
                 ORDER BY c.played ASC, c.id ASC",
                $game_id, $player_id
            ), ARRAY_A);
        }

        return rest_ensure_response($response);
    }

    // =========================================================================
    // NO GAME — student vs parent differentiation
    // =========================================================================
    private static function no_game_response($uid) {
        global $wpdb;
        $lt = $wpdb->prefix . 'mfsd_parent_student_links';

        $linked_parents = $wpdb->get_results($wpdb->prepare(
            "SELECT l.parent_user_id, l.relationship_type, u.display_name
             FROM {$lt} l JOIN {$wpdb->users} u ON u.ID = l.parent_user_id
             WHERE l.student_user_id = %d AND l.link_status = 'active'",
            $uid
        ), ARRAY_A);

        if (!empty($linked_parents)) {
            return [
                'ok'             => true,
                'status'         => 'no_game',
                'viewer_role'    => 'student',
                'can_start'      => true,
                'linked_parents' => array_map(fn($p) => [
                    'user_id'      => (int)$p['parent_user_id'],
                    'display_name' => $p['display_name'],
                    'role'         => $p['relationship_type'],
                ], $linked_parents),
            ];
        }

        $linked_student = $wpdb->get_row($wpdb->prepare(
            "SELECT l.student_user_id, u.display_name AS student_name
             FROM {$lt} l JOIN {$wpdb->users} u ON u.ID = l.student_user_id
             WHERE l.parent_user_id = %d AND l.link_status = 'active'
             ORDER BY l.link_id ASC LIMIT 1",
            $uid
        ), ARRAY_A);

        if ($linked_student) {
            return [
                'ok'           => true,
                'status'       => 'no_game',
                'viewer_role'  => 'parent',
                'can_start'    => false,
                'student_name' => $linked_student['student_name'],
                'message'      => $linked_student['student_name'] . " hasn't started a Super Strengths game yet. You'll be notified here as soon as they do.",
            ];
        }

        return [
            'ok'          => true,
            'status'      => 'no_game',
            'viewer_role' => 'unknown',
            'can_start'   => false,
            'message'     => 'No active game found. Please ask your admin for help.',
        ];
    }

    // =========================================================================
    // POST /game/start — student creates game; parents auto-added from links
    // =========================================================================
    public static function start_game(WP_REST_Request $req) {
        global $wpdb;
        $uid  = get_current_user_id();
        $user = get_userdata($uid);
        $lt   = $wpdb->prefix . 'mfsd_parent_student_links';
        $pp   = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $gp   = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $linked_parents = $wpdb->get_results($wpdb->prepare(
            "SELECT l.parent_user_id, l.relationship_type, u.display_name
             FROM {$lt} l JOIN {$wpdb->users} u ON u.ID = l.parent_user_id
             WHERE l.student_user_id = %d AND l.link_status = 'active'",
            $uid
        ), ARRAY_A);

        if (empty($linked_parents)) {
            return self::err('no_links', 'No linked family members found.', 400);
        }

        $n = count($linked_parents) + 1;
        if ($n < 2) return self::err('too_few', 'Need at least 2 players.', 400);
        if ($n > 6) $linked_parents = array_slice($linked_parents, 0, 5);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT g.id FROM {$gp} g JOIN {$pp} p ON p.game_id = g.id
             WHERE p.user_id = %d AND g.status != 'complete' LIMIT 1",
            $uid
        ));
        if ($existing) return self::err('game_exists', 'You already have an active game.', 409);

        $mode = get_option('mfsd_ss_mode', 'full');
        $wpdb->insert($gp, [
            'status'             => 'submission',
            'mode'               => $mode,
            'round_limit'        => (int) get_option('mfsd_ss_round_limit', 3),
            'turn_timeout_hours' => (int) get_option('mfsd_ss_turn_timeout', 24),
            'vote_timeout_hours' => (int) get_option('mfsd_ss_vote_timeout', 24),
        ]);
        $game_id = $wpdb->insert_id;

        $wpdb->insert($pp, ['game_id' => $game_id, 'user_id' => $uid, 'display_name' => $user->display_name, 'role' => 'student']);

        foreach ($linked_parents as $parent) {
            $valid_roles = ['parent','carer','sibling','other'];
            $role = in_array($parent['relationship_type'], $valid_roles) ? $parent['relationship_type'] : 'parent';
            $wpdb->insert($pp, [
                'game_id'      => $game_id,
                'user_id'      => (int)$parent['parent_user_id'],
                'display_name' => $parent['display_name'],
                'role'         => $role,
            ]);
        }

        return self::state();
    }

    // =========================================================================
    // GET /strengths
    // =========================================================================
    public static function strengths() {
        global $wpdb;
        $sp   = $wpdb->prefix . MFSD_SS_DB::TBL_STRENGTHS;
        $rows = $wpdb->get_results(
            "SELECT id, strength_text, category FROM $sp WHERE active = 1 ORDER BY category, strength_text",
            ARRAY_A
        );
        $grouped = [];
        foreach ($rows as $r) $grouped[$r['category']][] = ['id' => (int)$r['id'], 'text' => $r['strength_text']];
        return rest_ensure_response(['ok' => true, 'strengths' => $grouped]);
    }

    // =========================================================================
    // POST /validate-text
    // =========================================================================
    public static function validate_text(WP_REST_Request $req) {
        $text   = sanitize_text_field($req->get_param('text') ?? '');
        $result = MFSD_SS_Validator::validate($text);
        return rest_ensure_response(['ok' => true, 'result' => $result]);
    }

    // =========================================================================
    // POST /submission/save
    // =========================================================================
    public static function submission_save(WP_REST_Request $req) {
        global $wpdb;
        $uid       = get_current_user_id();
        $game_id   = (int) $req->get_param('game_id');
        $target_id = (int) $req->get_param('target_player_id');
        $strengths = $req->get_param('strengths') ?? [];

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);
        if ($player['submission_status'] === 'submitted') return self::err('already_submitted', 'Already submitted', 409);

        $player_id = (int)$player['id'];
        $cp  = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $fl  = $wpdb->prefix . MFSD_SS_DB::TBL_FLAGS;
        $sp  = $wpdb->prefix . MFSD_SS_DB::TBL_STRENGTHS;
        $max = MFSD_SS_DB::CARDS_PER_TARGET;

        $wpdb->delete($cp, ['game_id' => $game_id, 'author_player_id' => $player_id, 'target_player_id' => $target_id]);

        $saved = $flagged = 0;
        foreach (array_slice($strengths, 0, $max) as $s) {
            $type   = sanitize_text_field($s['type'] ?? 'list');
            $text   = sanitize_text_field($s['text'] ?? '');
            $str_id = !empty($s['strength_id']) ? (int)$s['strength_id'] : null;
            $is_ft  = ($type === 'free') ? 1 : 0;
            $flag   = 0;

            if (empty($text)) continue;

            if ($is_ft) {
                $vr = MFSD_SS_Validator::validate($text);
                if ($vr['action'] === 'block') continue;
                if ($vr['action'] === 'flag') {
                    $flag = 1; $flagged++;
                    $wpdb->insert($fl, [
                        'game_id' => $game_id, 'player_id' => $player_id,
                        'target_player_id' => $target_id, 'submitted_text' => $text,
                        'matched_rule' => $vr['matched'] ?? 'pattern', 'status' => 'pending',
                    ]);
                }
            }

            $dup = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $cp WHERE game_id = %d AND author_player_id = %d
                  AND target_player_id = %d AND strength_text = %s",
                $game_id, $player_id, $target_id, $text
            ));
            if ($dup) continue;

            $wpdb->insert($cp, [
                'game_id' => $game_id, 'author_player_id' => $player_id,
                'target_player_id' => $target_id, 'strength_id' => $str_id,
                'strength_text' => $text, 'is_free_text' => $is_ft, 'flagged' => $flag,
            ]);
            if ($str_id) $wpdb->query($wpdb->prepare(
                "UPDATE $sp SET times_used = times_used + 1 WHERE id = %d", $str_id
            ));
            $saved++;
        }

        return rest_ensure_response(['ok' => true, 'saved' => $saved, 'flagged' => $flagged, 'target_id' => $target_id]);
    }

    // =========================================================================
    // POST /submission/submit — branches on game mode for dealing
    // =========================================================================
    public static function submission_submit(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int)$player['id'];
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $others = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name FROM $pp WHERE game_id = %d AND id != %d", $game_id, $player_id
        ), ARRAY_A);

        foreach ($others as $other) {
            $cnt = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $cp WHERE game_id = %d AND author_player_id = %d
                  AND target_player_id = %d AND flagged = 0",
                $game_id, $player_id, (int)$other['id']
            ));
            if ($cnt < MFSD_SS_DB::CARDS_PER_TARGET) {
                return self::err('incomplete',
                    "You need exactly " . MFSD_SS_DB::CARDS_PER_TARGET .
                    " cards for {$other['display_name']} — you have {$cnt}.", 400);
            }
        }

        $wpdb->update($pp, ['submission_status' => 'submitted', 'submitted_at' => current_time('mysql')], ['id' => $player_id]);

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $pp WHERE game_id = %d AND submission_status = 'pending'", $game_id
        ));

        $all_submitted = ($pending === 0);
        if ($all_submitted) {
            $mode = $wpdb->get_var($wpdb->prepare("SELECT mode FROM $gp WHERE id = %d", $game_id));
            if ($mode === 'snap') {
                MFSD_SS_Game::init_snap_session($game_id);
            } else {
                MFSD_SS_Game::deal_cards($game_id);
            }
        }

        $all = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name, submission_status FROM $pp WHERE game_id = %d", $game_id
        ), ARRAY_A);

        return rest_ensure_response(['ok' => true, 'all_submitted' => $all_submitted, 'players' => $all]);
    }

    // =========================================================================
    // GET /game/hand
    // =========================================================================
    public static function hand(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');
        $player  = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $hand = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.strength_text, c.played, p.display_name AS target_name
             FROM {$cp} c JOIN {$pp} p ON p.id = c.target_player_id
             WHERE c.game_id = %d AND c.dealt_to_player_id = %d
             ORDER BY c.played ASC, c.id ASC",
            $game_id, (int)$player['id']
        ), ARRAY_A);

        return rest_ensure_response(['ok' => true, 'hand' => $hand]);
    }

    // =========================================================================
    // POST /game/play (guessing game)
    // =========================================================================
    public static function play_card(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');
        $card_id = (int) $req->get_param('card_id');

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int)$player['id'];
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $turn = $wpdb->get_row($wpdb->prepare(
            "SELECT t.* FROM {$tp} t JOIN {$gp} g ON g.current_turn_id = t.id
             WHERE g.id = %d AND t.played_by_player_id = %d AND t.card_id = 0",
            $game_id, $player_id
        ), ARRAY_A);
        if (!$turn) return self::err('not_your_turn', "It's not your turn", 409);

        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, p.display_name AS target_name
             FROM {$cp} c JOIN {$pp} p ON p.id = c.target_player_id
             WHERE c.id = %d AND c.game_id = %d AND c.dealt_to_player_id = %d AND c.played = 0",
            $card_id, $game_id, $player_id
        ), ARRAY_A);
        if (!$card) return self::err('invalid_card', 'Invalid card', 400);

        $timeout_h = (int) get_option('mfsd_ss_vote_timeout', 24);
        $reveal_at = date('Y-m-d H:i:s', strtotime("+{$timeout_h} hours"));

        $wpdb->update($cp, ['played' => 1], ['id' => $card_id]);
        $wpdb->update($tp, ['card_id' => $card_id, 'phase' => 'A', 'phase_a_reveal_at' => $reveal_at], ['id' => (int)$turn['id']]);

        return rest_ensure_response(['ok' => true, 'turn_id' => (int)$turn['id'],
            'card' => ['id' => $card_id, 'strength_text' => $card['strength_text'], 'target_name' => $card['target_name']],
            'reveal_at' => $reveal_at]);
    }

    // =========================================================================
    // GET /game/turn (guessing game)
    // =========================================================================
    public static function turn_state(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');
        $player  = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int)$player['id'];
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $vp = $wpdb->prefix . MFSD_SS_DB::TBL_VOTES;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $game = $wpdb->get_row($wpdb->prepare(
            "SELECT status, current_turn_id, mode FROM $gp WHERE id = %d", $game_id
        ), ARRAY_A);

        if ($game['status'] === 'complete') return rest_ensure_response(['ok' => true, 'game_status' => 'complete']);
        if (!$game['current_turn_id'])     return rest_ensure_response(['ok' => true, 'game_status' => 'waiting']);

        $turn = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, c.strength_text, c.target_player_id, c.author_player_id,
                    pt.display_name AS target_name, pa.display_name AS author_name,
                    pp2.display_name AS played_by_name
             FROM {$tp} t
             LEFT JOIN {$cp} c   ON c.id   = t.card_id
             LEFT JOIN {$pp} pt  ON pt.id  = c.target_player_id
             LEFT JOIN {$pp} pa  ON pa.id  = c.author_player_id
             LEFT JOIN {$pp} pp2 ON pp2.id = t.played_by_player_id
             WHERE t.id = %d",
            (int)$game['current_turn_id']
        ), ARRAY_A);

        if (!$turn) return rest_ensure_response(['ok' => true, 'game_status' => 'waiting']);

        $n        = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pp WHERE game_id = %d", $game_id));
        $votes_in = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $vp WHERE turn_id = %d AND phase = %s", (int)$turn['id'], $turn['phase']
        ));
        $expected  = ($turn['phase'] === 'A') ? $n - 1 : $n;
        $all_voted = ($votes_in >= $expected) && $turn['phase'] !== 'complete';

        if ($all_voted) {
            MFSD_SS_Game::process_reveal($turn, $game_id, $game['mode']);
            $turn = $wpdb->get_row($wpdb->prepare(
                "SELECT t.*, c.strength_text, c.target_player_id, c.author_player_id,
                        pt.display_name AS target_name, pa.display_name AS author_name,
                        pp2.display_name AS played_by_name
                 FROM {$tp} t
                 LEFT JOIN {$cp} c   ON c.id   = t.card_id
                 LEFT JOIN {$pp} pt  ON pt.id  = c.target_player_id
                 LEFT JOIN {$pp} pa  ON pa.id  = c.author_player_id
                 LEFT JOIN {$pp} pp2 ON pp2.id = t.played_by_player_id
                 WHERE t.id = %d",
                (int)$game['current_turn_id']
            ), ARRAY_A);
        }

        $my_vote = $wpdb->get_row($wpdb->prepare(
            "SELECT selected_player_id, is_confident, is_correct, points_earned
             FROM $vp WHERE turn_id = %d AND voter_player_id = %d AND phase = %s",
            (int)$turn['id'], $player_id, $turn['phase']
        ), ARRAY_A);

        $reveal_votes = [];
        if (in_array($turn['phase'], ['B','complete'])) {
            $reveal_votes = $wpdb->get_results($wpdb->prepare(
                "SELECT v.voter_player_id, v.selected_player_id, v.is_confident,
                        v.is_correct, v.points_earned,
                        pv.display_name AS voter_name, ps.display_name AS selected_name
                 FROM {$vp} v
                 JOIN {$pp} pv ON pv.id = v.voter_player_id
                 JOIN {$pp} ps ON ps.id = v.selected_player_id
                 WHERE v.turn_id = %d AND v.phase = 'A'",
                (int)$turn['id']
            ), ARRAY_A);
        }

        $all_players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name, score_total, confidence_tokens FROM $pp
             WHERE game_id = %d ORDER BY COALESCE(turn_order, id)",
            $game_id
        ), ARRAY_A);

        return rest_ensure_response([
            'ok'             => true,
            'game_status'    => $game['status'],
            'player_id'      => $player_id,
            'is_card_player' => ((int)$turn['played_by_player_id'] === $player_id),
            'votes_in'       => $votes_in,
            'expected_voters'=> $expected,
            'all_voted'      => $all_voted,
            'all_players'    => $all_players,
            'my_vote'        => $my_vote,
            'reveal_votes'   => $reveal_votes,
            'turn'           => [
                'id'              => (int)$turn['id'],
                'turn_number'     => (int)$turn['turn_number'],
                'phase'           => $turn['phase'],
                'played_by_id'    => (int)$turn['played_by_player_id'],
                'played_by_name'  => $turn['played_by_name'],
                'card_id'         => $turn['card_id'] ? (int)$turn['card_id'] : null,
                'strength_text'   => $turn['card_id'] ? $turn['strength_text'] : null,
                'target_name'     => in_array($turn['phase'], ['B','complete']) ? $turn['target_name'] : null,
                'target_id'       => in_array($turn['phase'], ['B','complete']) ? (int)$turn['target_player_id'] : null,
                'author_name'     => ($turn['phase'] === 'complete') ? $turn['author_name'] : null,
                'author_id'       => ($turn['phase'] === 'complete') ? (int)$turn['author_player_id'] : null,
                'round_winner_id' => $turn['round_winner_player_id'] ? (int)$turn['round_winner_player_id'] : null,
            ],
        ]);
    }

    // =========================================================================
    // POST /game/vote (guessing game)
    // =========================================================================
    public static function vote(WP_REST_Request $req) {
        global $wpdb;
        $uid       = get_current_user_id();
        $game_id   = (int) $req->get_param('game_id');
        $turn_id   = (int) $req->get_param('turn_id');
        $phase     = sanitize_text_field($req->get_param('phase') ?? 'A');
        $selected  = (int) $req->get_param('selected_player_id');
        $confident = !empty($req->get_param('is_confident'));

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int)$player['id'];
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $vp = $wpdb->prefix . MFSD_SS_DB::TBL_VOTES;

        $turn = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tp WHERE id = %d AND game_id = %d AND phase = %s", $turn_id, $game_id, $phase
        ), ARRAY_A);
        if (!$turn) return self::err('invalid_turn', 'Turn not in that phase', 400);
        if ($phase === 'A' && $player_id === (int)$turn['played_by_player_id']) {
            return self::err('cannot_vote', 'Card player cannot vote in Phase A', 403);
        }
        if ($confident && (int)$player['confidence_tokens'] <= 0) $confident = false;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $vp WHERE turn_id = %d AND voter_player_id = %d AND phase = %s",
            $turn_id, $player_id, $phase
        ));
        $data = [
            'turn_id' => $turn_id, 'game_id' => $game_id, 'phase' => $phase,
            'voter_player_id' => $player_id, 'selected_player_id' => $selected,
            'is_confident' => $confident ? 1 : 0, 'submitted_at' => current_time('mysql'),
        ];
        if ($existing) $wpdb->update($vp, $data, ['id' => $existing]);
        else $wpdb->insert($vp, $data);

        if ($confident) $wpdb->update($pp,
            ['confidence_tokens' => max(0, (int)$player['confidence_tokens'] - 1)],
            ['id' => $player_id]
        );

        return rest_ensure_response(['ok' => true, 'phase' => $phase, 'is_confident' => $confident]);
    }

    // =========================================================================
    // GET /game/results (guessing game — snap results handled by /snap/session)
    // =========================================================================
    public static function results(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');
        $player  = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int)$player['id'];
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $scores = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name, score_total FROM $pp WHERE game_id = %d ORDER BY score_total DESC", $game_id
        ), ARRAY_A);

        $received = $wpdb->get_results($wpdb->prepare(
            "SELECT c.strength_text, c.is_free_text, c.played, p.display_name AS author_name
             FROM {$cp} c JOIN {$pp} p ON p.id = c.author_player_id
             WHERE c.game_id = %d AND c.target_player_id = %d AND c.flagged = 0
             ORDER BY c.played DESC, c.strength_text ASC",
            $game_id, $player_id
        ), ARRAY_A);

        $ai_summary = MFSD_SS_Game::generate_strengths_summary($received, $player['display_name']);

        return rest_ensure_response(['ok' => true, 'scores' => $scores,
            'my_cards' => $received, 'ai_summary' => $ai_summary, 'player_id' => $player_id]);
    }

    // =========================================================================
    // SNAP — POST /snap/join
    // Player signals they are on the page and ready to play.
    // When all players have joined, server starts the 3-2-1 countdown.
    // =========================================================================
    public static function snap_join(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');
        $player  = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id  = (int)$player['id'];
        $ss = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $sh = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $ss WHERE game_id = %d ORDER BY id DESC LIMIT 1", $game_id
        ), ARRAY_A);
        if (!$session) return self::err('no_session', 'No snap session found', 404);

        $session_id = (int)$session['id'];

        // Mark this player as present
        $wpdb->update($sh, ['is_present' => 1, 'joined_at' => current_time('mysql')], [
            'session_id' => $session_id, 'player_id' => $player_id,
        ]);

        // Check if all players are now present
        if ($session['status'] === 'waiting') {
            $total  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pp WHERE game_id = %d", $game_id));
            $joined = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $sh WHERE session_id = %d AND is_present = 1", $session_id
            ));
            if ($joined >= $total) {
                // Everyone here — start 3-second countdown
                $countdown_ends = gmdate('Y-m-d H:i:s', time() + 3);
                $wpdb->update($ss, ['status' => 'countdown', 'countdown_ends_at' => $countdown_ends], ['id' => $session_id]);
            }
        }

        return self::snap_session_state($game_id, $uid);
    }

    // =========================================================================
    // SNAP — GET /snap/session  (polled at 500ms during gameplay)
    // =========================================================================
    public static function snap_session(WP_REST_Request $req) {
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');
        return self::snap_session_state($game_id, $uid);
    }

    // =========================================================================
    // SNAP — POST /snap/play-card
    // Plays the top card from the player's hand onto the pile.
    // Triggers snap opportunity if the new card matches the previous top card.
    // =========================================================================
    public static function snap_play_card(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');
        $player  = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int)$player['id'];
        $ss = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $sh = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $ss WHERE game_id = %d AND status = 'playing'", $game_id
        ), ARRAY_A);
        if (!$session) return self::err('not_playing', 'Game not in play', 409);
        if ((int)$session['current_turn_player_id'] !== $player_id) {
            return self::err('not_your_turn', 'Not your turn', 409);
        }

        $session_id = (int)$session['id'];
        $hand_row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $sh WHERE session_id = %d AND player_id = %d", $session_id, $player_id
        ), ARRAY_A);

        $hand = json_decode($hand_row['cards'], true) ?: [];
        if (empty($hand)) return self::err('no_cards', 'No cards in hand', 400);

        // Take top card (index 0 = next to play)
        $played = array_shift($hand);
        $played['played_by_player_id'] = $player_id;

        $pile = json_decode($session['pile'], true) ?: [];
        $pile[] = $played; // append to top

        // Check for snap: top 2 cards match by strength_text
        $snap_triggered = false;
        if (count($pile) >= 2) {
            $top    = $pile[count($pile) - 1];
            $second = $pile[count($pile) - 2];
            if ($top['strength_text'] === $second['strength_text']) {
                $snap_triggered = true;
            }
        }

        // Persist hand
        $wpdb->update($sh, ['cards' => json_encode($hand)], ['session_id' => $session_id, 'player_id' => $player_id]);

        if ($snap_triggered) {
            $timer     = (int)$session['snap_timer_seconds'];
            $expires   = gmdate('Y-m-d H:i:s', time() + $timer);
            // Random bullseye position — never the same area twice (offset from last position)
            $last_x    = $session['snap_x'] ? (float)$session['snap_x'] : 50;
            $last_y    = $session['snap_y'] ? (float)$session['snap_y'] : 50;
            $snap_x    = self::random_position($last_x, 10, 85, 20);
            $snap_y    = self::random_position($last_y, 15, 80, 20);

            $wpdb->update($ss, [
                'pile'            => json_encode($pile),
                'status'          => 'snap_active',
                'snap_x'          => $snap_x,
                'snap_y'          => $snap_y,
                'snap_expires_at' => $expires,
            ], ['id' => $session_id]);
        } else {
            $next_player_id = MFSD_SS_Game::get_next_snap_player($session_id, $game_id, $player_id);
            $wpdb->update($ss, [
                'pile'                   => json_encode($pile),
                'current_turn_player_id' => $next_player_id,
            ], ['id' => $session_id]);

            // Check if all hands now empty — reshuffle needed
            MFSD_SS_Game::check_snap_reshuffle($session_id, $game_id);
        }

        return self::snap_session_state($game_id, $uid);
    }

    // =========================================================================
    // SNAP — POST /snap/claim
    // Atomic: first valid claim wins. Uses DB transaction + FOR UPDATE.
    // Status is set to 'playing' INSIDE the transaction so any concurrent claim
    // finds status changed and returns no_snap — no separate already_won check needed.
    // =========================================================================
    public static function snap_claim(WP_REST_Request $req) {
        global $wpdb;
        $uid     = get_current_user_id();
        $game_id = (int) $req->get_param('game_id');
        $player  = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);

        $player_id = (int)$player['id'];
        $ss = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $sc = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_CLAIMS;

        $wpdb->query('START TRANSACTION');

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $ss WHERE game_id = %d AND status IN ('snap_active','tiebreaker') ORDER BY id DESC LIMIT 1 FOR UPDATE",
            $game_id
        ), ARRAY_A);

        if (!$session) {
            $wpdb->query('ROLLBACK');
            return self::err('no_snap', 'No active snap to claim', 409);
        }

        // Check expiry — 1-second grace period so late-arriving claims still count
        $claim_grace = gmdate('Y-m-d H:i:s', strtotime($session['snap_expires_at']) + 1);
        if (gmdate('Y-m-d H:i:s') > $claim_grace) {
            $wpdb->query('ROLLBACK');
            MFSD_SS_Game::expire_snap($session, $game_id);
            return self::snap_session_state($game_id, $uid);
        }

        $session_id = (int)$session['id'];

        // Record claim and immediately set status to 'snap_claimed' within the
        // transaction — concurrent claims find status changed and return no_snap.
        $wpdb->insert($sc, ['session_id' => $session_id, 'player_id' => $player_id, 'won' => 1]);
        $wpdb->query($wpdb->prepare(
            "UPDATE $ss SET status = 'snap_claimed' WHERE id = %d", $session_id
        ));
        $wpdb->query('COMMIT');

        if ($session['status'] === 'tiebreaker') {
            $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
            $wpdb->update($ss, ['status' => 'complete', 'winner_player_id' => $player_id], ['id' => $session_id]);
            $wpdb->update($gp, ['status' => 'complete'], ['id' => $game_id]);
        } else {
            MFSD_SS_Game::process_snap_win($session, $player_id, $game_id);
        }

        return self::snap_session_state($game_id, $uid);
    }

    // =========================================================================
    // SNAP — SHARED SESSION STATE HELPER
    // Handles lazy countdown → playing and snap expiry transitions.
    // =========================================================================
    private static function snap_session_state($game_id, $uid) {
        global $wpdb;

        $player = self::get_player($game_id, $uid);
        if (!$player) return self::err('not_in_game', 'Not in game', 403);
        $player_id = (int)$player['id'];

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $ss = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $sh = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $ss WHERE game_id = %d ORDER BY id DESC LIMIT 1", $game_id
        ), ARRAY_A);
        if (!$session) return self::err('no_session', 'No snap session', 404);

        $session_id = (int)$session['id'];
        $now        = gmdate('Y-m-d H:i:s'); // UTC — matches gmdate() expiry times

        // Lazy: countdown → playing
        if ($session['status'] === 'countdown' && $now >= $session['countdown_ends_at']) {
            $wpdb->update($ss, ['status' => 'playing'], ['id' => $session_id]);
            $session['status'] = 'playing';
        }

        // Lazy: snap expired — no winner.
        // Grace period: expire 1 second AFTER snap_expires_at to avoid race where
        // a valid claim arrives at the same moment the poll fires the lazy expiry.
        $grace_expiry = gmdate('Y-m-d H:i:s', strtotime($session['snap_expires_at']) + 1);
        if (in_array($session['status'], ['snap_active','tiebreaker','snap_claimed']) && $now >= $grace_expiry) {
            if ($session['status'] === 'snap_active') {
                MFSD_SS_Game::expire_snap($session, $game_id);
            } else {
                // Tiebreaker expired — no winner. Give it to highest score (already equal, pick first by id)
                $sh2 = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;
                $win = $wpdb->get_var($wpdb->prepare(
                    "SELECT player_id FROM $sh2 WHERE session_id = %d ORDER BY snap_score DESC, player_id ASC LIMIT 1",
                    $session_id
                ));
                $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
                $wpdb->update($ss, ['status' => 'complete', 'winner_player_id' => (int)$win], ['id' => $session_id]);
                $wpdb->update($gp, ['status' => 'complete'], ['id' => $game_id]);
            }
            $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ss WHERE id = %d", $session_id), ARRAY_A);
        }

        // Build per-player state
        $all_hands = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, p.display_name, p.role
             FROM $sh h JOIN $pp p ON p.id = h.player_id
             WHERE h.session_id = %d",
            $session_id
        ), ARRAY_A);

        $players_state = [];
        $my_hand_count = 0;

        foreach ($all_hands as $h) {
            $cards = json_decode($h['cards'], true) ?: [];
            $count = count($cards);
            $is_me = ((int)$h['player_id'] === $player_id);
            if ($is_me) $my_hand_count = $count;
            $players_state[] = [
                'player_id'    => (int)$h['player_id'],
                'display_name' => $h['display_name'],
                'role'         => $h['role'],
                'hand_count'   => $count,
                'snap_score'   => (int)$h['snap_score'],
                'is_present'   => (bool)$h['is_present'],
                'is_me'        => $is_me,
            ];
        }

        $pile     = json_decode($session['pile'], true) ?: [];
        $pile_top = !empty($pile) ? $pile[count($pile) - 1] : null;

        // Enrich pile_top with author and target names from the cards table
        $pile_top_data = null;
        if ($pile_top) {
            $cp_t = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
            $card_detail = !empty($pile_top['card_id']) ? $wpdb->get_row($wpdb->prepare(
                "SELECT c.author_player_id, c.target_player_id,
                        pa.display_name AS author_name,
                        pt.display_name AS target_name
                 FROM {$cp_t} c
                 JOIN {$pp} pa ON pa.id = c.author_player_id
                 JOIN {$pp} pt ON pt.id = c.target_player_id
                 WHERE c.id = %d",
                (int)$pile_top['card_id']
            ), ARRAY_A) : null;

            $pile_top_data = [
                'strength_text'       => $pile_top['strength_text'],
                'played_by_player_id' => (int)$pile_top['played_by_player_id'],
                'author_name'         => $card_detail['author_name'] ?? null,
                'target_name'         => $card_detail['target_name'] ?? null,
            ];
        }

        // Also include the second card for the straddle effect — with full author/target
        $pile_second = null;
        if (count($pile) >= 2) {
            $second_raw = $pile[count($pile) - 2];
            $second_detail = !empty($second_raw['card_id']) ? $wpdb->get_row($wpdb->prepare(
                "SELECT pa.display_name AS author_name, pt.display_name AS target_name
                 FROM {$cp_t} c
                 JOIN {$pp} pa ON pa.id = c.author_player_id
                 JOIN {$pp} pt ON pt.id = c.target_player_id
                 WHERE c.id = %d",
                (int)$second_raw['card_id']
            ), ARRAY_A) : null;

            $pile_second = [
                'strength_text' => $second_raw['strength_text'],
                'author_name'   => $second_detail['author_name'] ?? null,
                'target_name'   => $second_detail['target_name'] ?? null,
            ];
        }

        return rest_ensure_response([
            'ok'                     => true,
            'session_id'             => $session_id,
            'status'                 => $session['status'],
            'snap_mode'              => $session['snap_mode'],
            'quick_draw_target'      => (int)$session['quick_draw_target'],
            'snap_timer_seconds'     => (int)$session['snap_timer_seconds'],
            'current_turn_player_id' => (int)$session['current_turn_player_id'],
            'pile_count'             => count($pile),
            'pile_top'               => $pile_top_data,
            'pile_second'            => $pile_second,
            'snap_active'            => in_array($session['status'], ['snap_active','tiebreaker']),
            'is_tiebreaker'          => $session['status'] === 'tiebreaker',
            'snap_x'                 => $session['snap_x'] ? (float)$session['snap_x'] : null,
            'snap_y'                 => $session['snap_y'] ? (float)$session['snap_y'] : null,
            'snap_expires_at'        => $session['snap_expires_at'],
            'countdown_ends_at'      => $session['countdown_ends_at'],
            'total_snaps_won'        => (int)$session['total_snaps_won'],
            'last_snap_winner_id'    => $session['last_snap_winner_id'] ? (int)$session['last_snap_winner_id'] : null,
            'winner_player_id'       => $session['winner_player_id'] ? (int)$session['winner_player_id'] : null,
            'players'                => $players_state,
            'player_id'              => $player_id,
            'my_hand_count'          => $my_hand_count,
        ]);
    }

    // =========================================================================
    // ADMIN — POST /admin/flag-review
    // =========================================================================
    public static function flag_review(WP_REST_Request $req) {
        global $wpdb;
        $flag_id   = (int) $req->get_param('flag_id');
        $action    = sanitize_text_field($req->get_param('action_type') ?? 'reject');
        $admin_uid = get_current_user_id();

        $fl = $wpdb->prefix . MFSD_SS_DB::TBL_FLAGS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $flag = $wpdb->get_row($wpdb->prepare("SELECT * FROM $fl WHERE id = %d", $flag_id), ARRAY_A);
        if (!$flag) return self::err('not_found', 'Flag not found', 404);

        $wpdb->update($fl, [
            'status'      => ($action === 'allow') ? 'allowed' : 'rejected',
            'reviewed_by' => $admin_uid,
            'reviewed_at' => current_time('mysql'),
        ], ['id' => $flag_id]);

        if ($action === 'allow') {
            $wpdb->update($cp, ['flagged' => 0], [
                'game_id' => (int)$flag['game_id'], 'author_player_id' => (int)$flag['player_id'],
                'target_player_id' => (int)$flag['target_player_id'], 'strength_text' => $flag['submitted_text'],
            ]);
        } else {
            $wpdb->delete($cp, [
                'game_id' => (int)$flag['game_id'], 'author_player_id' => (int)$flag['player_id'],
                'target_player_id' => (int)$flag['target_player_id'], 'strength_text' => $flag['submitted_text'],
            ]);
        }

        return rest_ensure_response(['ok' => true, 'action' => $action]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Generate a random position that is at least $min_distance away from $last.
     */
    private static function random_position($last, $min, $max, $min_distance) {
        $attempts = 0;
        do {
            $pos = rand($min * 10, $max * 10) / 10;
            $attempts++;
        } while (abs($pos - $last) < $min_distance && $attempts < 20);
        return $pos;
    }
}