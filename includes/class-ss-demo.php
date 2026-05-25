<?php
/**
 * MFSD Super Strengths — Demo mode engine (Phase E, MYF-172 + MYF-173)
 * Prerequisite checks, data fetchers, Steve pick generation, board dealing,
 * flip engine, summary generation. REST endpoints live in class-ss-api.php.
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_Demo {

    const PICKS_COUNT     = 5;
    const PICKS_MIN_VALID = 3;

    // =========================================================================
    // PREREQUISITE CHECK — Lens + Word Assoc + Personality all must have data
    // =========================================================================

    public static function check_prerequisites(int $student_user_id): bool {
        $lens = self::fetch_lens_data($student_user_id);
        $wa   = self::fetch_word_assoc_data($student_user_id);
        $pers = self::fetch_personality_data($student_user_id);
        return $lens['available'] && $wa['available'] && $pers['available'];
    }

    // =========================================================================
    // STATUS — most recent demo game for this student
    // =========================================================================

    public static function get_status(int $student_user_id): array {
        global $wpdb;
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $game = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$smg}
             WHERE student_user_id = %d AND game_type = 'demo'
             ORDER BY id DESC LIMIT 1",
            $student_user_id
        ), ARRAY_A);

        if (!$game) return ['found' => false];

        return [
            'found'   => true,
            'game_id' => (int) $game['id'],
            'status'  => $game['status'],
        ];
    }

    // =========================================================================
    // DATA FETCHERS — graceful fallback when table / data absent
    // =========================================================================

    public static function fetch_lens_data(int $student_user_id): array {
        return MFSD_SS_Summary::get_lens_context($student_user_id);
    }

    public static function fetch_word_assoc_data(int $student_user_id): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mfsd_word_associations';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$tbl}'")) return ['available' => false];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT word, association_1, association_2, association_3, time_taken
             FROM {$tbl} WHERE user_id = %d ORDER BY time_taken ASC LIMIT 5",
            $student_user_id
        ), ARRAY_A);

        if (count($rows) < 3) return ['available' => false];

        $lines = [];
        foreach ($rows as $r) {
            $assoc   = implode(', ', array_filter([$r['association_1'], $r['association_2'], $r['association_3']]));
            $lines[] = "{$r['word']}: {$assoc}";
        }

        return ['available' => true, 'top' => implode("\n", $lines)];
    }

    public static function fetch_personality_data(int $student_user_id): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mfsd_ptest_results';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$tbl}'")) return ['available' => false];

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT mbti_type, disc_primary FROM {$tbl}
             WHERE user_id = %d AND test_type = 'COMBINED' AND mbti_type IS NOT NULL
             ORDER BY id DESC LIMIT 1",
            $student_user_id
        ), ARRAY_A);

        if (empty($result['mbti_type'])) return ['available' => false];

        return [
            'available'    => true,
            'mbti_type'    => $result['mbti_type'],
            'disc_primary' => $result['disc_primary'] ?? '',
            'label'        => self::mbti_label($result['mbti_type']),
        ];
    }

    // =========================================================================
    // STRENGTHS LIBRARY HELPERS
    // =========================================================================

    private static function get_strengths_library(): array {
        global $wpdb;
        $tbl = $wpdb->prefix . MFSD_SS_DB::TBL_STRENGTHS;
        return $wpdb->get_results(
            "SELECT id, strength_text FROM {$tbl} WHERE active = 1 ORDER BY strength_text ASC",
            ARRAY_A
        ) ?: [];
    }

    private static function get_strengths_index(): array {
        $idx = [];
        foreach (self::get_strengths_library() as $s) {
            $idx[$s['strength_text']] = (int) $s['id'];
        }
        return $idx;
    }

    // =========================================================================
    // PROMPT BUILDER — user message for the demo picker chatbot
    // =========================================================================

    public static function build_steve_prompt(
        int    $student_user_id,
        string $student_name,
        int    $student_age,
        array  $self_strengths
    ): string {
        $lens = self::fetch_lens_data($student_user_id);
        $wa   = self::fetch_word_assoc_data($student_user_id);
        $pers = self::fetch_personality_data($student_user_id);

        $library     = self::get_strengths_library();
        $library_csv = implode(', ', array_column($library, 'strength_text'));
        $self_str    = implode(', ', $self_strengths);

        $p  = "Student name: {$student_name}\n";
        $p .= "Student age: {$student_age}\n\n";
        $p .= "STUDENT'S SELF-CHOSEN STRENGTHS:\n{$self_str}\n\n";
        $p .= "STRENGTHS LIBRARY (you must pick only from this exact list):\n{$library_csv}\n\n";

        if ($lens['available']) {
            $diff_str   = $lens['differences'];
            $diff_count = ($diff_str !== 'none') ? count(explode(';', $diff_str)) : 0;
            $p .= "SOLUTION LENS:\n";
            $p .= "Agreements: {$lens['agreements']}\n";
            $p .= "Differences count: {$diff_count}\n";
            $p .= "Differences: {$diff_str}\n";
            if (!empty($lens['summary'])) $p .= "Prior summary: {$lens['summary']}\n";
            $p .= "\n";
        }

        if ($wa['available']) {
            $p .= "WORD ASSOCIATION (student's fastest responses):\n{$wa['top']}\n\n";
        }

        if ($pers['available']) {
            $p .= "PERSONALITY TYPE:\nType: {$pers['mbti_type']} — {$pers['label']}\n\n";
        }

        $p .= "Return a JSON array of exactly " . self::PICKS_COUNT . " picks using only strengths from the library above.\n";
        $p .= "You may overlap with up to 3 of the student's self-chosen strengths but must include at least 2 unique picks not already in their list.\n";
        $p .= "Each pick must be a JSON object with exactly these keys:\n";
        $p .= "  - strength_text: exact string from the library\n";
        $p .= "  - source_activity: one of lens, word_association, personality, self_strengths\n";
        $p .= "  - rationale: 1-2 sentences explaining why you chose this strength for this student\n";
        $p .= "Return a JSON array ONLY — no preamble, no explanation outside the array.";

        return $p;
    }

    // =========================================================================
    // RESPONSE PARSER — validates JSON, resolves IDs, falls back if needed
    // =========================================================================

    public static function parse_steve_response(string $raw): array {
        $strength_idx  = self::get_strengths_index();
        $all_strengths = array_keys($strength_idx);

        $json_str = trim(preg_replace(['/^```(?:json)?\s*/i', '/\s*```$/s'], '', trim($raw)));
        $decoded  = json_decode($json_str, true);

        $valid_picks = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) continue;
                $text   = trim($item['strength_text']   ?? '');
                $source = trim($item['source_activity'] ?? '');
                $rat    = trim($item['rationale']       ?? '');
                if (!$text || !$source || !$rat) continue;
                if (!isset($strength_idx[$text]))  continue;

                $valid_picks[] = [
                    'strength_id'     => $strength_idx[$text],
                    'strength_text'   => $text,
                    'source_activity' => $source,
                    'rationale'       => $rat,
                ];
                if (count($valid_picks) === self::PICKS_COUNT) break;
            }
        }

        if (count($valid_picks) < self::PICKS_MIN_VALID) {
            error_log(sprintf(
                'MFSD_SS_Demo: Steve picker returned %d valid picks (min %d). Using random fallback. Raw: %s',
                count($valid_picks), self::PICKS_MIN_VALID, substr($raw, 0, 300)
            ));
            return self::random_fallback_picks($all_strengths, $strength_idx);
        }

        if (count($valid_picks) < self::PICKS_COUNT) {
            $used      = array_column($valid_picks, 'strength_text');
            $remaining = array_values(array_diff($all_strengths, $used));
            shuffle($remaining);
            foreach (array_slice($remaining, 0, self::PICKS_COUNT - count($valid_picks)) as $text) {
                $valid_picks[] = [
                    'strength_id'     => $strength_idx[$text],
                    'strength_text'   => $text,
                    'source_activity' => 'library',
                    'rationale'       => 'Steve sees great potential in this strength for you.',
                ];
            }
        }

        return $valid_picks;
    }

    private static function random_fallback_picks(array $all_strengths, array $strength_idx): array {
        shuffle($all_strengths);
        $picks = [];
        foreach (array_slice($all_strengths, 0, self::PICKS_COUNT) as $text) {
            $picks[] = [
                'strength_id'     => $strength_idx[$text],
                'strength_text'   => $text,
                'source_activity' => 'library',
                'rationale'       => 'Steve sees great potential in this strength for you.',
            ];
        }
        return $picks;
    }

    // =========================================================================
    // GENERATE STEVE PICKS — full flow: fetch → prompt → AI → parse
    // Caller (deal_demo_board) handles DB persistence of rationale.
    // Always returns 5 picks — falls back to random rather than erroring.
    // =========================================================================

    public static function generate_steve_picks(
        int    $game_id,
        int    $student_user_id,
        string $student_name,
        int    $student_age,
        array  $self_strengths
    ): array {
        $chatbot_id = get_option('mfsd_stevegpt_map_ss_demo_picker', '');

        if (!$chatbot_id || !class_exists('SteveGPT_Chatbot')) {
            if (!$chatbot_id) {
                error_log("MFSD_SS_Demo: demo_picker chatbot not configured (game #{$game_id}), using random fallback");
            } else {
                error_log("MFSD_SS_Demo: SteveGPT_Chatbot class unavailable (game #{$game_id}), using random fallback");
            }
            $idx = self::get_strengths_index();
            return self::random_fallback_picks(array_keys($idx), $idx);
        }

        $prompt = self::build_steve_prompt($student_user_id, $student_name, $student_age, $self_strengths);

        try {
            $ai  = SteveGPT_Chatbot::get($chatbot_id);
            $raw = $ai->query($prompt, $student_user_id);
        } catch (\Exception $e) {
            error_log("MFSD_SS_Demo: SteveGPT query failed (game #{$game_id}): " . $e->getMessage());
            $idx = self::get_strengths_index();
            return self::random_fallback_picks(array_keys($idx), $idx);
        }

        return self::parse_steve_response($raw);
    }

    // =========================================================================
    // BOARD DEALING — creates 10 pairs (5 self + 5 Steve picks), shuffled
    // Stores rationale in mfsd_sm_demo_rationale keyed to board_card_id.
    // Updates game status to 'playing' with time limit if configured.
    // =========================================================================

    public static function deal_demo_board(int $game_id, int $player_id, array $picks, array $self_strengths): void {
        global $wpdb;
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $smp  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        $smb  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_BOARD;
        $smdr = $wpdb->prefix . MFSD_SS_DB::TBL_SM_DEMO_RATIONALE;

        $tiles      = [];
        $pair_index = 0;

        foreach ($self_strengths as $text) {
            $pair_key = 'dp_' . $game_id . '_' . $pair_index++;
            for ($i = 0; $i < 2; $i++) {
                $tiles[] = [
                    'pair_key'         => $pair_key,
                    'card_type'        => 'self_strength',
                    'card_id'          => null,
                    'self_strength_id' => null,
                    'author_player_id' => $player_id,
                    'target_player_id' => $player_id,
                    'strength_text'    => $text,
                    'author_display'   => 'You',
                    'target_display'   => 'You',
                    '_pick_index'      => null,
                ];
            }
        }

        foreach ($picks as $pi => $pick) {
            $pair_key = 'dp_' . $game_id . '_' . $pair_index++;
            for ($i = 0; $i < 2; $i++) {
                $tiles[] = [
                    'pair_key'         => $pair_key,
                    'card_type'        => 'steve_pick',
                    'card_id'          => null,
                    'self_strength_id' => null,
                    'author_player_id' => null,
                    'target_player_id' => $player_id,
                    'strength_text'    => $pick['strength_text'],
                    'author_display'   => 'Steve',
                    'target_display'   => 'You',
                    '_pick_index'      => $pi,
                ];
            }
        }

        shuffle($tiles);

        $pair_first_board_id = [];

        foreach ($tiles as $pos => $tile) {
            $pi       = $tile['_pick_index'];
            $pair_key = $tile['pair_key'];
            unset($tile['_pick_index']);

            $wpdb->insert($smb, [
                'game_id'          => $game_id,
                'position'         => $pos,
                'pair_key'         => $pair_key,
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
            $board_id = $wpdb->insert_id;

            if ($tile['card_type'] === 'steve_pick' && $pi !== null && !isset($pair_first_board_id[$pair_key])) {
                $pair_first_board_id[$pair_key] = ['board_card_id' => $board_id, 'pick_index' => $pi];
            }
        }

        foreach ($pair_first_board_id as $info) {
            $pick = $picks[$info['pick_index']];
            $wpdb->insert($smdr, [
                'game_id'         => $game_id,
                'board_card_id'   => $info['board_card_id'],
                'strength_text'   => $pick['strength_text'],
                'source_activity' => $pick['source_activity'],
                'rationale'       => $pick['rationale'],
                'created_at'      => current_time('mysql'),
            ]);
        }

        $wpdb->update($smp, ['current_turn_started_at' => current_time('mysql')], ['id' => $player_id]);

        $now         = current_time('mysql');
        $update_data = ['status' => 'playing', 'game_started_at' => $now];

        $demo_mins = (int) get_option('mfsd_ss_demo_time_limit_mins', 3);
        if ($demo_mins > 0) {
            $update_data['game_ends_at'] = gmdate('Y-m-d H:i:s', time() + ($demo_mins * 60));
        }

        $wpdb->update($smg, $update_data, ['id' => $game_id]);
    }

    // =========================================================================
    // FLIP ENGINE — solo play, no turn rotation on miss
    // =========================================================================

    public static function flip_card(int $game_id, int $player_id, int $position): array {
        global $wpdb;
        $smb  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_BOARD;
        $smt  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_TURNS;
        $smp  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $smdr = $wpdb->prefix . MFSD_SS_DB::TBL_SM_DEMO_RATIONALE;

        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $smb WHERE game_id = %d AND position = %d",
            $game_id, $position
        ), ARRAY_A);
        if (!$card)              return ['error' => 'invalid_position', 'message' => 'Invalid position'];
        if ($card['is_matched']) return ['error' => 'already_matched',  'message' => 'Card already matched'];
        if ($card['is_face_up']) return ['error' => 'already_face_up',  'message' => 'Card already face up'];

        $game = $wpdb->get_row($wpdb->prepare("SELECT * FROM $smg WHERE id = %d", $game_id), ARRAY_A);

        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $smt WHERE game_id = %d AND player_id = %d AND flip2_position IS NULL AND timed_out = 0",
            $game_id, $player_id
        ), ARRAY_A);

        if (!$pending) {
            // FLIP 1
            $turn_num = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $smt WHERE game_id = %d", $game_id
            )) + 1;

            $wpdb->insert($smt, [
                'game_id'        => $game_id,
                'player_id'      => $player_id,
                'turn_number'    => $turn_num,
                'flip1_position' => $position,
                'started_at'     => current_time('mysql'),
            ]);
            $wpdb->update($smb, ['is_face_up' => 1], ['game_id' => $game_id, 'position' => $position]);

            return [
                'ok'          => true,
                'flip_number' => 1,
                'position'    => $position,
                'content'     => self::card_content($card),
            ];
        }

        // FLIP 2
        $flip1_card = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $smb WHERE game_id = %d AND position = %d",
            $game_id, (int) $pending['flip1_position']
        ), ARRAY_A);

        $wpdb->update($smb, ['is_face_up' => 1], ['game_id' => $game_id, 'position' => $position]);

        $is_match = ($flip1_card && $flip1_card['pair_key'] === $card['pair_key']);

        $wpdb->update($smt, [
            'flip2_position' => $position,
            'is_match'       => $is_match ? 1 : 0,
            'completed_at'   => current_time('mysql'),
        ], ['id' => (int) $pending['id']]);

        if ($is_match) {
            $wpdb->update($smb, [
                'is_matched'           => 1,
                'matched_by_player_id' => $player_id,
                'matched_at'           => current_time('mysql'),
            ], ['game_id' => $game_id, 'pair_key' => $card['pair_key']]);

            $new_score = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT score FROM $smp WHERE id = %d", $player_id
            )) + 1;
            $wpdb->update($smp, ['score' => $new_score], ['id' => $player_id]);

            $rationale = null;
            if ($card['card_type'] === 'steve_pick') {
                $dr = $wpdb->get_row($wpdb->prepare(
                    "SELECT rationale, source_activity FROM $smdr WHERE game_id = %d AND strength_text = %s LIMIT 1",
                    $game_id, $card['strength_text']
                ), ARRAY_A);
                if ($dr) {
                    $rationale = ['text' => $dr['rationale'], 'source_activity' => $dr['source_activity']];
                }
            }

            $unmatched     = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $smb WHERE game_id = %d AND is_matched = 0", $game_id
            ));
            $time_expired  = ($game['game_ends_at'] && current_time('mysql') >= $game['game_ends_at']);
            $game_complete = ($unmatched === 0 || $time_expired);

            if ($game_complete) {
                $wpdb->update($smg, [
                    'status'           => 'complete',
                    'winner_player_id' => $player_id,
                    'completed_at'     => current_time('mysql'),
                ], ['id' => $game_id]);
                $wpdb->update($smp, ['current_turn_started_at' => null], ['id' => $player_id]);
            } else {
                $wpdb->update($smp, ['current_turn_started_at' => current_time('mysql')], ['id' => $player_id]);
            }

            return [
                'ok'             => true,
                'flip_number'    => 2,
                'position'       => $position,
                'is_match'       => true,
                'matched_pair'   => [self::card_content($flip1_card), self::card_content($card)],
                'new_score'      => $new_score,
                'rationale'      => $rationale ? $rationale['text']            : null,
                'source_activity'=> $rationale ? $rationale['source_activity'] : null,
                'game_complete'  => $game_complete,
            ];
        }

        // No match — flip both back; player continues immediately (no turn rotation)
        $wpdb->update($smb, ['is_face_up' => 0], ['game_id' => $game_id, 'position' => $position]);
        $wpdb->update($smb, ['is_face_up' => 0], ['game_id' => $game_id, 'position' => (int) $pending['flip1_position']]);
        $wpdb->update($smp, ['current_turn_started_at' => current_time('mysql')], ['id' => $player_id]);

        return [
            'ok'             => true,
            'flip_number'    => 2,
            'position'       => $position,
            'is_match'       => false,
            'flip1_position' => (int) $pending['flip1_position'],
            'flip1_content'  => self::card_content($flip1_card),
            'flip2_content'  => self::card_content($card),
        ];
    }

    private static function card_content(array $card): array {
        $label = ($card['card_type'] === 'steve_pick')
            ? 'Steve thinks you are… ' . $card['strength_text']
            : 'You believe you are… ' . $card['strength_text'];

        return [
            'card_type'      => $card['card_type'],
            'strength_text'  => $card['strength_text'],
            'author_display' => $card['author_display'],
            'target_display' => $card['target_display'],
            'label'          => $label,
        ];
    }

    // =========================================================================
    // GET PLAYER — lookup player row for a demo game
    // =========================================================================

    public static function get_player(int $game_id, int $user_id): ?array {
        global $wpdb;
        $smp = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $smp WHERE game_id = %d AND user_id = %d LIMIT 1",
            $game_id, $user_id
        ), ARRAY_A) ?: null;
    }

    // =========================================================================
    // SUMMARY DATA — aggregates everything needed for the demo summary screen
    // =========================================================================

    public static function get_summary_data(int $game_id): array {
        global $wpdb;
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $smp  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        $smss = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SELF_STRENGTHS;
        $smb  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_BOARD;
        $smdr = $wpdb->prefix . MFSD_SS_DB::TBL_SM_DEMO_RATIONALE;

        $game = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$smg} WHERE id = %d AND game_type = 'demo'", $game_id
        ), ARRAY_A);
        if (!$game) return [];

        $student_uid = (int) $game['student_user_id'];
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$smp} WHERE game_id = %d AND user_id = %d LIMIT 1",
            $game_id, $student_uid
        ), ARRAY_A);
        if (!$player) return [];

        $player_id   = (int) $player['id'];
        $student_age = (int) get_user_meta($student_uid, 'mfsd_age', true);

        $self_strengths = $wpdb->get_col($wpdb->prepare(
            "SELECT strength_text FROM {$smss} WHERE game_id = %d AND player_id = %d ORDER BY id ASC",
            $game_id, $player_id
        ));

        $picks = $wpdb->get_results($wpdb->prepare(
            "SELECT strength_text, source_activity, rationale FROM {$smdr} WHERE game_id = %d ORDER BY id ASC",
            $game_id
        ), ARRAY_A);

        $total_pairs = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT pair_key) FROM {$smb} WHERE game_id = %d", $game_id
        ));
        $matched_pairs = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT pair_key) FROM {$smb} WHERE game_id = %d AND is_matched = 1", $game_id
        ));

        $pick_texts      = array_column($picks, 'strength_text');
        $shared_strengths = array_values(array_intersect($self_strengths, $pick_texts));
        $hidden_strengths = array_values(array_diff($pick_texts, $self_strengths));

        return [
            'game_id'          => $game_id,
            'game'             => $game,
            'student_uid'      => $student_uid,
            'player_id'        => $player_id,
            'student_name'     => $player['display_name'],
            'student_age'      => $student_age,
            'self_strengths'   => $self_strengths,
            'picks'            => $picks,
            'total_pairs'      => $total_pairs,
            'matched_pairs'    => $matched_pairs,
            'shared_strengths' => $shared_strengths,
            'hidden_strengths' => $hidden_strengths,
        ];
    }

    // =========================================================================
    // DEMO AI SUMMARY — calls demo_summary chatbot, parses 3 sections, caches
    // =========================================================================

    public static function generate_demo_ai_summary(int $game_id, int $player_id): array {
        $data = self::get_summary_data($game_id);
        if (empty($data)) return ['error' => 'no_data'];

        $chatbot_id = get_option('mfsd_stevegpt_map_ss_demo_summary', '');
        if (!$chatbot_id)                      return ['error' => 'chatbot_not_configured'];
        if (!class_exists('SteveGPT_Chatbot')) return ['error' => 'stevegpt_unavailable'];

        $p  = "Student: {$data['student_name']}, age {$data['student_age']}\n\n";
        $p .= "Self-chosen strengths: " . implode(', ', $data['self_strengths']) . "\n\n";

        foreach ($data['picks'] as $i => $pick) {
            $n  = $i + 1;
            $p .= "Steve's Pick {$n}: {$pick['strength_text']} (source: {$pick['source_activity']})\n";
            $p .= "Rationale: {$pick['rationale']}\n\n";
        }

        $p .= "Pairs matched: {$data['matched_pairs']} of {$data['total_pairs']}\n";
        $p .= "Shared strengths (student also chose these): " . (empty($data['shared_strengths']) ? 'none' : implode(', ', $data['shared_strengths'])) . "\n";
        $p .= "Steve's unique picks: " . (empty($data['hidden_strengths']) ? 'none' : implode(', ', $data['hidden_strengths'])) . "\n\n";
        $p .= "Generate the demo summary using the OUTPUT FORMAT in your instructions.";

        try {
            $ai  = SteveGPT_Chatbot::get($chatbot_id);
            $raw = $ai->query($p, $data['student_uid']);
        } catch (\Exception $e) {
            return ['error' => 'ai_failed', 'message' => $e->getMessage()];
        }

        $sections = self::parse_demo_summary_sections($raw);

        global $wpdb;
        $smsu    = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SUMMARIES;
        $payload = [
            'game_id'      => $game_id,
            'player_id'    => $player_id,
            'summary_type' => 'demo',
            'ai_summary'   => $raw,
            'generated_at' => current_time('mysql'),
        ];
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $smsu WHERE game_id = %d AND player_id = %d", $game_id, $player_id
        ));
        if ($existing) {
            $wpdb->update($smsu, $payload, ['game_id' => $game_id, 'player_id' => $player_id]);
        } else {
            $wpdb->insert($smsu, $payload);
        }

        return ['ok' => true, 'sections' => $sections, 'raw' => $raw];
    }

    public static function get_or_generate_summary(int $game_id, int $player_id): array {
        global $wpdb;
        $smsu   = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SUMMARIES;
        $stored = $wpdb->get_row($wpdb->prepare(
            "SELECT ai_summary FROM $smsu WHERE game_id = %d AND player_id = %d AND summary_type = 'demo'",
            $game_id, $player_id
        ), ARRAY_A);

        if ($stored && !empty($stored['ai_summary'])) {
            return [
                'ok'       => true,
                'sections' => self::parse_demo_summary_sections($stored['ai_summary']),
                'raw'      => $stored['ai_summary'],
                'cached'   => true,
            ];
        }

        return self::generate_demo_ai_summary($game_id, $player_id);
    }

    // =========================================================================
    // SECTION PARSER — 3-tab demo summary ###SECTION### format
    // =========================================================================

    public static function parse_demo_summary_sections(string $raw): array {
        $sections = [];
        $parts    = preg_split('/###SECTION:([A-Z_]+)###/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);

        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $key     = $parts[$i];
            $content = trim($parts[$i + 1] ?? '');
            if ($key && $content !== '') {
                $sections[$key] = [
                    'label'   => ucwords(strtolower(str_replace('_', ' ', $key))),
                    'content' => $content,
                ];
            }
        }

        if (empty($sections)) {
            $sections['SUMMARY'] = [
                'label'    => 'Summary',
                'content'  => trim($raw),
                'fallback' => true,
            ];
        }

        return $sections;
    }

    // =========================================================================
    // CHAT CONTEXT — injected into demo chat widget
    // =========================================================================

    public static function build_chat_context(int $game_id): string {
        global $wpdb;
        $smsu = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SUMMARIES;
        $smss = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SELF_STRENGTHS;
        $smp  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        $smdr = $wpdb->prefix . MFSD_SS_DB::TBL_SM_DEMO_RATIONALE;
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;

        $game = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$smg} WHERE id = %d AND game_type = 'demo'", $game_id
        ), ARRAY_A);
        if (!$game) return '';

        $student_uid = (int) $game['student_user_id'];
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name FROM {$smp} WHERE game_id = %d AND user_id = %d LIMIT 1",
            $game_id, $student_uid
        ), ARRAY_A);
        if (!$player) return '';

        $player_id   = (int) $player['id'];
        $student_name = $player['display_name'];
        $student_age  = (int) get_user_meta($student_uid, 'mfsd_age', true);

        $self_strengths = $wpdb->get_col($wpdb->prepare(
            "SELECT strength_text FROM {$smss} WHERE game_id = %d AND player_id = %d ORDER BY id ASC",
            $game_id, $player_id
        ));

        $picks = $wpdb->get_results($wpdb->prepare(
            "SELECT strength_text, source_activity, rationale FROM {$smdr} WHERE game_id = %d ORDER BY id ASC",
            $game_id
        ), ARRAY_A);

        $stored_summary = $wpdb->get_var($wpdb->prepare(
            "SELECT ai_summary FROM {$smsu} WHERE game_id = %d AND player_id = %d LIMIT 1",
            $game_id, $player_id
        ));

        $ctx  = "=== SUPER STRENGTHS DEMO — SESSION CONTEXT ===\n\n";
        $ctx .= "Student: {$student_name} (age {$student_age})\n";
        $ctx .= "Student's self-chosen strengths: " . implode(', ', $self_strengths) . "\n\n";

        if (!empty($picks)) {
            $ctx .= "Steve's 5 picks:\n";
            foreach ($picks as $i => $p) {
                $n = $i + 1;
                $ctx .= "{$n}. {$p['strength_text']} (from {$p['source_activity']}): {$p['rationale']}\n";
            }
            $ctx .= "\n";
        }

        $pers = self::fetch_personality_data($student_uid);
        if ($pers['available']) {
            $ctx .= "Personality type: {$pers['mbti_type']} — {$pers['label']}\n\n";
        }

        $wa = self::fetch_word_assoc_data($student_uid);
        if ($wa['available']) {
            $ctx .= "Word Association (fastest responses):\n{$wa['top']}\n\n";
        }

        if ($stored_summary) {
            $ctx .= "Steve's summary:\n{$stored_summary}\n";
        }

        return $ctx;
    }

    // =========================================================================
    // MBTI label lookup (local copy — avoids cross-plugin dependency)
    // =========================================================================

    private static function mbti_label(string $type): string {
        static $map = [
            'ISTJ' => 'The Logistician', 'ISFJ' => 'The Defender',
            'INFJ' => 'The Advocate',    'INTJ' => 'The Architect',
            'ISTP' => 'The Virtuoso',    'ISFP' => 'The Adventurer',
            'INFP' => 'The Mediator',    'INTP' => 'The Logician',
            'ESTP' => 'The Entrepreneur','ESFP' => 'The Entertainer',
            'ENFP' => 'The Campaigner',  'ENTP' => 'The Debater',
            'ESTJ' => 'The Executive',   'ESFJ' => 'The Consul',
            'ENFJ' => 'The Protagonist', 'ENTJ' => 'The Commander',
        ];
        return $map[$type] ?? $type;
    }
}
