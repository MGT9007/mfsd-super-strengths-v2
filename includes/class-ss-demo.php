<?php
/**
 * MFSD Super Strengths — Demo mode engine (Phase E, MYF-172)
 * Prerequisite checks, data fetchers, Steve pick generation.
 * Board dealing and REST endpoints are in MYF-173 (class-ss-api.php additions).
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_Demo {

    const PICKS_COUNT     = 5;
    const PICKS_MIN_VALID = 3;  // Fall back to random if fewer than this many valid picks returned

    // =========================================================================
    // PREREQUISITE CHECK — all 3 prior activities must have data for Steve
    // =========================================================================

    public static function check_prerequisites(int $student_user_id): bool {
        $lens = self::fetch_lens_data($student_user_id);
        $wa   = self::fetch_word_assoc_data($student_user_id);
        $pers = self::fetch_personality_data($student_user_id);
        return $lens['available'] && $wa['available'] && $pers['available'];
    }

    // =========================================================================
    // STATUS — find the most recent demo game for this student
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
    // DATA FETCHERS — all return ['available' => false] when data / table absent
    // =========================================================================

    /**
     * Delegates to the Summary class lens fetcher (avoids duplication).
     */
    public static function fetch_lens_data(int $student_user_id): array {
        return MFSD_SS_Summary::get_lens_context($student_user_id);
    }

    public static function fetch_word_assoc_data(int $student_user_id): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mfsd_word_associations';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$tbl}'")) {
            return ['available' => false];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT word, association_1, association_2, association_3, time_taken
             FROM {$tbl} WHERE user_id = %d ORDER BY time_taken ASC LIMIT 5",
            $student_user_id
        ), ARRAY_A);

        if (empty($rows)) return ['available' => false];

        $lines = [];
        foreach ($rows as $r) {
            $assoc   = implode(', ', array_filter([$r['association_1'], $r['association_2'], $r['association_3']]));
            $lines[] = "{$r['word']}: {$assoc}";
        }

        return [
            'available' => true,
            'top'       => implode("\n", $lines),
        ];
    }

    public static function fetch_personality_data(int $student_user_id): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mfsd_ptest_results';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$tbl}'")) {
            return ['available' => false];
        }

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

    /** @return array<array{id: int, strength_text: string}> */
    private static function get_strengths_library(): array {
        global $wpdb;
        $tbl = $wpdb->prefix . MFSD_SS_DB::TBL_STRENGTHS;
        return $wpdb->get_results(
            "SELECT id, strength_text FROM {$tbl} WHERE active = 1 ORDER BY strength_text ASC",
            ARRAY_A
        ) ?: [];
    }

    /** @return array<string, int>  strength_text → strength_id */
    private static function get_strengths_index(): array {
        $idx = [];
        foreach (self::get_strengths_library() as $s) {
            $idx[$s['strength_text']] = (int) $s['id'];
        }
        return $idx;
    }

    // =========================================================================
    // PROMPT BUILDER — assembles the user message for the demo picker chatbot
    // =========================================================================

    /**
     * @param array<string> $self_strengths  Strength texts chosen by the student
     */
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
            if (!empty($lens['summary'])) {
                $p .= "Prior summary: {$lens['summary']}\n";
            }
            $p .= "\n";
        }

        if ($wa['available']) {
            $p .= "WORD ASSOCIATION (student's fastest responses):\n{$wa['top']}\n\n";
        }

        if ($pers['available']) {
            $p .= "PERSONALITY TYPE:\n";
            $p .= "Type: {$pers['mbti_type']} — {$pers['label']}\n\n";
        }

        $p .= "Return a JSON array of exactly " . self::PICKS_COUNT . " picks using only strengths from the library above.\n";
        $p .= "You may overlap with up to 3 of the student's self-chosen strengths but must include at least 2 unique picks not already in their list.\n";
        $p .= "Each pick must be a JSON object with exactly these keys:\n";
        $p .= "  - strength_text: exact string from the library\n";
        $p .= "  - source_activity: one of lens, word_association, personality, self_strengths\n";
        $p .= "  - rationale: 1–2 sentences explaining why you chose this strength for this student\n";
        $p .= "Return a JSON array ONLY — no preamble, no explanation outside the array.";

        return $p;
    }

    // =========================================================================
    // RESPONSE PARSER — validates JSON, resolves strength_ids, falls back if needed
    // Returns array of picks: [{strength_id, strength_text, source_activity, rationale}]
    // =========================================================================

    public static function parse_steve_response(string $raw): array {
        $strength_idx  = self::get_strengths_index();
        $all_strengths = array_keys($strength_idx);

        // Strip markdown code fences if present
        $json_str = trim(preg_replace(['/^```(?:json)?\s*/i', '/\s*```$/s'], '', trim($raw)));

        $decoded = json_decode($json_str, true);

        $valid_picks = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) continue;

                $text   = trim($item['strength_text']   ?? '');
                $source = trim($item['source_activity'] ?? '');
                $rat    = trim($item['rationale']       ?? '');

                if (!$text || !$source || !$rat) continue;
                if (!isset($strength_idx[$text]))  continue;  // Must match library exactly

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
                count($valid_picks),
                self::PICKS_MIN_VALID,
                substr($raw, 0, 300)
            ));
            return self::random_fallback_picks($all_strengths, $strength_idx);
        }

        // If 3 or 4 valid picks, pad to 5 with random library entries
        if (count($valid_picks) < self::PICKS_COUNT) {
            $used_texts = array_column($valid_picks, 'strength_text');
            $remaining  = array_values(array_diff($all_strengths, $used_texts));
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
    // GENERATE STEVE PICKS — full flow: data → prompt → AI → parse
    // Returns array of picks on success (caller handles DB persistence).
    // Falls back to random picks rather than returning WP_Error so the game
    // can always proceed.
    // =========================================================================

    /**
     * @param array<string> $self_strengths
     * @return array<array{strength_id: int, strength_text: string, source_activity: string, rationale: string}>
     */
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
    // CHAT CONTEXT — injects session data into the demo chat widget
    // Called by the GET /demo/chat-widget REST endpoint (MYF-173).
    // =========================================================================

    public static function build_chat_context(int $game_id): string {
        global $wpdb;
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $smss = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SELF_STRENGTHS;
        $smp  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        $smdr = $wpdb->prefix . MFSD_SS_DB::TBL_SM_DEMO_RATIONALE;
        $smsu = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SUMMARIES;

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
            $ctx .= "Steve's 5 picks for {$student_name}:\n";
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
    // SECTION PARSER — demo summary taskbot returns 3 ###SECTION### blocks
    // =========================================================================

    public static function parse_demo_summary_sections(string $raw): array {
        $markers = [
            'YOUR_PICKS'        => 'What I Saw In You',
            'SHARED_AND_HIDDEN' => 'Where We Agree',
            'WHATS_NEXT'        => "What's Next",
        ];

        $sections = [];
        $parts    = preg_split('/###SECTION:([A-Z_]+)###/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);

        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $key     = $parts[$i];
            $content = trim($parts[$i + 1] ?? '');
            if (isset($markers[$key])) {
                $sections[$key] = [
                    'label'   => $markers[$key],
                    'content' => $content,
                ];
            }
        }

        if (empty($sections)) {
            $sections['YOUR_PICKS'] = [
                'label'    => 'What I Saw In You',
                'content'  => trim($raw),
                'fallback' => true,
            ];
        }

        return $sections;
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
