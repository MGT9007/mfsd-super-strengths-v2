<?php
/**
 * MFSD Super Strengths — REST API Layer (FIXED for completed game detection)
 *
 * FIX: The state endpoint now checks for completed games FIRST and returns them
 * so the JS can display the summary screen instead of showing "Start Game" again.
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_API {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        $ns = 'mfsd-super-strengths/v1';
        $me = $this;

        register_rest_route($ns, '/state',              [['methods'=>'GET',  'callback'=>[$me,'state'],             'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/strengths',          [['methods'=>'GET',  'callback'=>[$me,'strengths'],         'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/validate-text',      [['methods'=>'POST', 'callback'=>[$me,'validate_text'],    'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/submission/save',    [['methods'=>'POST', 'callback'=>[$me,'submission_save'],  'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/submission/submit',  [['methods'=>'POST', 'callback'=>[$me,'submission_submit'],'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/hand',          [['methods'=>'GET',  'callback'=>[$me,'hand'],             'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/play',          [['methods'=>'POST', 'callback'=>[$me,'play_card'],        'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/vote',          [['methods'=>'POST', 'callback'=>[$me,'vote'],             'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/summary',       [['methods'=>'GET',  'callback'=>[$me,'summary'],           'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/game/start',         [['methods'=>'POST', 'callback'=>[$me,'start_game'],       'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/snap/claim',         [['methods'=>'POST', 'callback'=>[$me,'snap_claim'],       'permission_callback'=>[$me,'auth']]]);
        register_rest_route($ns, '/snap/deal',          [['methods'=>'POST', 'callback'=>[$me,'snap_deal'],        'permission_callback'=>[$me,'auth']]]);
    }

    public function auth() {
        return is_user_logged_in();
    }

    private function get_player($game_id) {
        global $wpdb;
        $uid = get_current_user_id();
        $pp  = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $pp WHERE user_id = %d AND game_id = %d",
            $uid, $game_id
        ), ARRAY_A);
    }

    // =========================================================================
    // GET /state  — FIXED to detect completed games first
    // =========================================================================
    public function state() {
        global $wpdb;
        $uid = get_current_user_id();
        $pp  = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $gp  = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
        $cp  = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        // FIX: Check for COMPLETED games first (most recent)
        $completed_player = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, g.status AS game_status, g.mode AS game_mode,
                    g.id AS game_id
             FROM {$pp} p
             JOIN {$gp} g ON g.id = p.game_id
             WHERE p.user_id = %d AND g.status = 'complete'
             ORDER BY g.created_at DESC LIMIT 1",
            $uid
        ), ARRAY_A);

        // If completed game found, return minimal response for summary display
        if ($completed_player) {
            return rest_ensure_response([
                'ok'         => true,
                'status'     => 'completed',
                'gameId'     => (int) $completed_player['game_id'],
                'gameMode'   => $completed_player['game_mode'],
                'player'     => [
                    'id'           => (int) $completed_player['id'],
                    'display_name' => $completed_player['display_name'],
                    'role'         => $completed_player['role'],
                ],
            ]);
        }

        // Now check for active games
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, g.status AS game_status, g.mode AS game_mode,
                    g.id AS game_id, g.current_turn_id, g.round_limit
             FROM {$pp} p
             JOIN {$gp} g ON g.id = p.game_id
             WHERE p.user_id = %d AND g.status != 'complete'
             ORDER BY g.created_at DESC LIMIT 1",
            $uid
        ), ARRAY_A);

        // No active game - check if student with linked parents (can start game)
        if (!$player) {
            return $this->no_game_response($uid);
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
            'gameId'          => $game_id,
            'gameMode'        => $player['game_mode'],
            'roundLimit'      => (int) $player['round_limit'],
            'currentTurnId'   => $player['current_turn_id'] ? (int) $player['current_turn_id'] : null,
            'player'          => [
                'id'               => $player_id,
                'display_name'     => $player['display_name'],
                'role'             => $player['role'],
                'score_total'      => (int) $player['score_total'],
                'confidence_tokens'=> (int) $player['confidence_tokens'],
                'submission_status'=> $player['submission_status'],
                'saved_counts'     => $saved_counts,
            ],
            'allPlayers'      => array_map(function($p) use ($uid) {
                return [
                    'id'               => (int) $p['id'],
                    'user_id'          => (int) $p['user_id'],
                    'display_name'     => $p['display_name'],
                    'role'             => $p['role'],
                    'score_total'      => (int) $p['score_total'],
                    'confidence_tokens'=> (int) $p['confidence_tokens'],
                    'submission_status'=> $p['submission_status'],
                    'turn_order'       => $p['turn_order'] ? (int) $p['turn_order'] : null,
                    'is_me'            => (int) $p['user_id'] === $uid,
                ];
            }, $all_players),
        ];

        // Snap-specific state
        if ($player['game_mode'] === 'snap') {
            $sp = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$sp}
                 WHERE game_id = %d
                 ORDER BY created_at DESC LIMIT 1",
                $game_id
            ), ARRAY_A);

            if ($session) {
                $pile = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}" . MFSD_SS_DB::TBL_SNAP_HANDS . "
                     WHERE snap_session_id = %d
                     ORDER BY dealt_at ASC",
                    $session['id']
                ), ARRAY_A);

                $response['snap'] = [
                    'session_id'    => (int) $session['id'],
                    'status'        => $session['status'],
                    'started_at'    => $session['started_at'],
                    'pile'          => array_map(function($c) {
                        return [
                            'id'            => (int) $c['id'],
                            'card_id'       => (int) $c['card_id'],
                            'dealt_at'      => $c['dealt_at'],
                            'author_name'   => $c['author_name'],
                            'target_name'   => $c['target_name'],
                            'strength_text' => $c['strength_text'],
                        ];
                    }, $pile),
                ];

                // Player hands
                $hands_raw = $wpdb->get_results($wpdb->prepare(
                    "SELECT player_id, COUNT(*) AS cnt
                     FROM {$wpdb->prefix}" . MFSD_SS_DB::TBL_SNAP_HANDS . "
                     WHERE snap_session_id = %d AND in_pile = 0
                     GROUP BY player_id",
                    $session['id']
                ), ARRAY_A);
                $hand_counts = [];
                foreach ($hands_raw as $h) {
                    $hand_counts[(int) $h['player_id']] = (int) $h['cnt'];
                }
                $response['snap']['handCounts'] = $hand_counts;

                // Snap scores
                $claims_raw = $wpdb->get_results($wpdb->prepare(
                    "SELECT player_id, COUNT(*) AS cnt
                     FROM {$wpdb->prefix}" . MFSD_SS_DB::TBL_SNAP_CLAIMS . "
                     WHERE snap_session_id = %d AND won = 1
                     GROUP BY player_id",
                    $session['id']
                ), ARRAY_A);
                $snap_scores = [];
                foreach ($claims_raw as $sc) {
                    $snap_scores[(int) $sc['player_id']] = (int) $sc['cnt'];
                }
                $response['snap']['snapScores'] = $snap_scores;
            }
        }

        return rest_ensure_response($response);
    }

    // =========================================================================
    // NO GAME RESPONSE — student can start, parent waits
    // =========================================================================
    private static function no_game_response($uid) {
        global $wpdb;
        $lp = $wpdb->prefix . 'mfsd_parent_student_links';

        // Check if this user is a student with linked parents
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT parent_user_id FROM {$lp} WHERE student_user_id = %d",
            $uid
        ), ARRAY_A);

        if (!empty($links)) {
            $parent_ids = array_column($links, 'parent_user_id');
            $placeholders = implode(',', array_fill(0, count($parent_ids), '%d'));
            $parent_names = $wpdb->get_col($wpdb->prepare(
                "SELECT display_name FROM {$wpdb->users} WHERE ID IN ($placeholders)",
                ...$parent_ids
            ));

            return rest_ensure_response([
                'ok'           => true,
                'status'       => 'no_game',
                'viewer_role'  => 'student',
                'can_start'    => true,
                'family_members' => $parent_names,
            ]);
        }

        // Check if this user is a parent waiting for student to start
        $student_link = $wpdb->get_row($wpdb->prepare(
            "SELECT student_user_id FROM {$lp} WHERE parent_user_id = %d LIMIT 1",
            $uid
        ), ARRAY_A);

        if ($student_link) {
            $student_name = $wpdb->get_var($wpdb->prepare(
                "SELECT display_name FROM {$wpdb->users} WHERE ID = %d",
                $student_link['student_user_id']
            ));

            return rest_ensure_response([
                'ok'           => true,
                'status'       => 'no_game',
                'viewer_role'  => 'parent',
                'student_name' => $student_name,
            ]);
        }

        // Default: no game and no way to start
        return rest_ensure_response([
            'ok'      => true,
            'status'  => 'no_game',
            'message' => 'No active game found. Ask your admin to set up a game.',
        ]);
    }

    // =========================================================================
    // POST /game/start — student initiates game with linked parents
    // =========================================================================
    public function start_game() {
        global $wpdb;
        $uid = get_current_user_id();
        $lp  = $wpdb->prefix . 'mfsd_parent_student_links';

        // Only students can start games
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT parent_user_id FROM {$lp} WHERE student_user_id = %d",
            $uid
        ), ARRAY_A);

        if (empty($links)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'No linked parents found'], 400);
        }

        // Get default config
        $mode = get_option('mfsd_ss_default_mode', 'full');
        $round_limit = (int) get_option('mfsd_ss_round_limit', 3);

        // Create game
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
        $wpdb->insert($gp, [
            'status'      => 'active',
            'mode'        => $mode,
            'round_limit' => $round_limit,
            'created_at'  => current_time('mysql'),
        ]);
        $game_id = $wpdb->insert_id;

        // Add student as player
        $student = wp_get_current_user();
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $wpdb->insert($pp, [
            'game_id'      => $game_id,
            'user_id'      => $uid,
            'display_name' => $student->display_name,
            'role'         => 'Student',
            'turn_order'   => 1,
        ]);

        // Add linked parents
        $turn = 2;
        foreach ($links as $link) {
            $parent = get_userdata($link['parent_user_id']);
            if ($parent) {
                $wpdb->insert($pp, [
                    'game_id'      => $game_id,
                    'user_id'      => $parent->ID,
                    'display_name' => $parent->display_name,
                    'role'         => 'Parent',
                    'turn_order'   => $turn++,
                ]);
            }
        }

        // Deal cards and create first turn
        MFSD_SS_Game::deal_cards($game_id);
        if ($mode !== 'snap') {
            MFSD_SS_Game::create_first_turn($game_id);
        }

        return rest_ensure_response(['ok' => true, 'game_id' => $game_id]);
    }

    // =========================================================================
    // GET /strengths — list of all strength phrases
    // =========================================================================
    public function strengths() {
        $list = MFSD_SS_DB::get_strength_list();
        return rest_ensure_response(['ok' => true, 'strengths' => $list]);
    }

    // =========================================================================
    // POST /validate-text
    // =========================================================================
    public function validate_text($request) {
        $params = $request->get_json_params();
        $text   = isset($params['text']) ? trim($params['text']) : '';

        if (empty($text)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Text required'], 400);
        }

        $result = MFSD_SS_Validator::validate($text);
        return rest_ensure_response(['ok' => true, 'result' => $result]);
    }

    // =========================================================================
    // POST /submission/save
    // =========================================================================
    public function submission_save($request) {
        global $wpdb;
        $params = $request->get_json_params();
        $game_id         = isset($params['game_id']) ? (int) $params['game_id'] : 0;
        $target_player_id= isset($params['target_player_id']) ? (int) $params['target_player_id'] : 0;
        $strength_text   = isset($params['strength_text']) ? trim($params['strength_text']) : '';

        if (!$game_id || !$target_player_id || empty($strength_text)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid input'], 400);
        }

        $player = $this->get_player($game_id);
        if (!$player) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Not in game'], 403);
        }

        $validation = MFSD_SS_Validator::validate($strength_text);
        if (!$validation['valid']) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Validation failed', 'validation' => $validation], 400);
        }

        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $wpdb->insert($cp, [
            'game_id'          => $game_id,
            'author_player_id' => $player['id'],
            'target_player_id' => $target_player_id,
            'strength_text'    => $strength_text,
            'flagged'          => 0,
        ]);

        return rest_ensure_response(['ok' => true, 'card_id' => $wpdb->insert_id]);
    }

    // =========================================================================
    // POST /submission/submit
    // =========================================================================
    public function submission_submit($request) {
        global $wpdb;
        $params  = $request->get_json_params();
        $game_id = isset($params['game_id']) ? (int) $params['game_id'] : 0;

        if (!$game_id) {
            return new WP_REST_Response(['ok' => false, 'error' => 'game_id required'], 400);
        }

        $player = $this->get_player($game_id);
        if (!$player) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Not in game'], 403);
        }

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $wpdb->update($pp, ['submission_status' => 'submitted'], ['id' => $player['id']]);

        return rest_ensure_response(['ok' => true]);
    }

    // =========================================================================
    // GET /game/hand
    // =========================================================================
    public function hand($request) {
        $game_id = $request->get_param('game_id');
        if (!$game_id) {
            return new WP_REST_Response(['ok' => false, 'error' => 'game_id required'], 400);
        }

        $player = $this->get_player($game_id);
        if (!$player) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Not in game'], 403);
        }

        $hand = MFSD_SS_Game::get_hand($game_id, $player['id']);
        return rest_ensure_response(['ok' => true, 'hand' => $hand]);
    }

    // =========================================================================
    // POST /game/play
    // =========================================================================
    public function play_card($request) {
        $params  = $request->get_json_params();
        $game_id = isset($params['game_id']) ? (int) $params['game_id'] : 0;
        $card_id = isset($params['card_id']) ? (int) $params['card_id'] : 0;
        $tokens  = isset($params['confidence_tokens']) ? (int) $params['confidence_tokens'] : 0;

        if (!$game_id || !$card_id) {
            return new WP_REST_Response(['ok' => false, 'error' => 'game_id and card_id required'], 400);
        }

        $player = $this->get_player($game_id);
        if (!$player) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Not in game'], 403);
        }

        $result = MFSD_SS_Game::play_card($game_id, $player['id'], $card_id, $tokens);
        if (!$result['ok']) {
            return new WP_REST_Response($result, 400);
        }

        return rest_ensure_response($result);
    }

    // =========================================================================
    // POST /game/vote
    // =========================================================================
    public function vote($request) {
        $params     = $request->get_json_params();
        $game_id    = isset($params['game_id']) ? (int) $params['game_id'] : 0;
        $turn_id    = isset($params['turn_id']) ? (int) $params['turn_id'] : 0;
        $chosen_id  = isset($params['chosen_player_id']) ? (int) $params['chosen_player_id'] : 0;

        if (!$game_id || !$turn_id || !$chosen_id) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Missing params'], 400);
        }

        $player = $this->get_player($game_id);
        if (!$player) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Not in game'], 403);
        }

        $result = MFSD_SS_Game::record_vote($turn_id, $player['id'], $chosen_id);
        return rest_ensure_response($result);
    }

    // =========================================================================
    // GET /game/summary
    // =========================================================================
    public function summary($request) {
        global $wpdb;
        $game_id = $request->get_param('game_id');
        if (!$game_id) {
            return new WP_REST_Response(['ok' => false, 'error' => 'game_id required'], 400);
        }

        $player = $this->get_player($game_id);
        if (!$player) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Not in game'], 403);
        }

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $all_players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name, user_id, role FROM {$pp} WHERE game_id = %d",
            $game_id
        ), ARRAY_A);

        $cards_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT c.target_player_id, c.strength_text,
                    a.display_name AS author_name, t.display_name AS target_name
             FROM {$cp} c
             JOIN {$pp} a ON a.id = c.author_player_id
             JOIN {$pp} t ON t.id = c.target_player_id
             WHERE c.game_id = %d AND c.dealt_to_player_id IS NOT NULL
             ORDER BY c.target_player_id, c.id",
            $game_id
        ), ARRAY_A);

        $by_target = [];
        foreach ($cards_raw as $c) {
            $tid = (int) $c['target_player_id'];
            if (!isset($by_target[$tid])) {
                $by_target[$tid] = [
                    'target_player_id' => $tid,
                    'target_name'      => $c['target_name'],
                    'is_me'            => false,
                    'strengths'        => [],
                ];
            }
            $by_target[$tid]['strengths'][] = [
                'text'   => $c['strength_text'],
                'author' => $c['author_name'],
            ];
        }

        $uid = get_current_user_id();
        foreach ($by_target as $tid => &$group) {
            foreach ($all_players as $p) {
                if ((int) $p['id'] === $tid && (int) $p['user_id'] === $uid) {
                    $group['is_me'] = true;
                    break;
                }
            }
        }

        $cards_for_summary = array_values($by_target);

        // Generate AI summary only for students
        $ai_summary = null;
        $is_student = false;
        foreach ($all_players as $p) {
            if ((int) $p['user_id'] === $uid && strtolower($p['role']) === 'student') {
                $is_student = true;
                break;
            }
        }

        if ($is_student) {
            foreach ($cards_for_summary as $group) {
                if ($group['is_me']) {
                    $student_name = $group['target_name'];
                    $ai_summary = MFSD_SS_Game::generate_strengths_summary($group['strengths'], $student_name);
                    break;
                }
            }
        }

        return rest_ensure_response([
            'ok'         => true,
            'cards'      => $cards_for_summary,
            'ai_summary' => $ai_summary,
        ]);
    }

    // =========================================================================
    // POST /snap/claim
    // =========================================================================
    public function snap_claim($request) {
        global $wpdb;
        $params     = $request->get_json_params();
        $game_id    = isset($params['game_id']) ? (int) $params['game_id'] : 0;
        $session_id = isset($params['session_id']) ? (int) $params['session_id'] : 0;

        if (!$game_id || !$session_id) {
            return new WP_REST_Response(['ok' => false, 'error' => 'game_id and session_id required'], 400);
        }

        $player = $this->get_player($game_id);
        if (!$player) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Not in game'], 403);
        }

        $sp = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sp} WHERE id = %d AND game_id = %d",
            $session_id, $game_id
        ), ARRAY_A);

        if (!$session || $session['status'] !== 'active') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Session not active'], 400);
        }

        // Check if pile is valid for snapping
        $pile = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . MFSD_SS_DB::TBL_SNAP_HANDS . "
             WHERE snap_session_id = %d
             ORDER BY dealt_at DESC LIMIT 2",
            $session_id
        ), ARRAY_A);

        if (count($pile) < 2) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Not enough cards to snap'], 400);
        }

        $card1 = $pile[0];
        $card2 = $pile[1];

        $match = ($card1['author_name'] === $card2['author_name']) ||
                 ($card1['target_name'] === $card2['target_name']) ||
                 ($card1['strength_text'] === $card2['strength_text']);

        if (!$match) {
            return new WP_REST_Response(['ok' => false, 'error' => 'No match'], 400);
        }

        // Record claim atomically
        $wpdb->query('START TRANSACTION');

        $claims_table = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_CLAIMS;
        $wpdb->insert($claims_table, [
            'snap_session_id' => $session_id,
            'player_id'       => $player['id'],
            'won'             => 1,
            'claimed_at'      => gmdate('Y-m-d H:i:s'),
        ]);

        $wpdb->update($sp, [
            'status' => 'snap_claimed',
        ], ['id' => $session_id]);

        $wpdb->query('COMMIT');

        return rest_ensure_response(['ok' => true, 'won' => true]);
    }

    // =========================================================================
    // POST /snap/deal
    // =========================================================================
    public function snap_deal($request) {
        global $wpdb;
        $params  = $request->get_json_params();
        $game_id = isset($params['game_id']) ? (int) $params['game_id'] : 0;

        if (!$game_id) {
            return new WP_REST_Response(['ok' => false, 'error' => 'game_id required'], 400);
        }

        $player = $this->get_player($game_id);
        if (!$player) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Not in game'], 403);
        }

        $result = MFSD_SS_Game::snap_deal_next($game_id, $player['id']);
        return rest_ensure_response($result);
    }
}

new MFSD_SS_API();