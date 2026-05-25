<?php
/**
 * MFSD Super Strengths — Summary & AI engine (Phase D)
 * Builds data, resolves prompts, calls SteveGPT, parses sections.
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_Summary {

    // =========================================================================
    // MBTI nickname lookup (local copy — avoids cross-plugin dependency)
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

    // =========================================================================
    // DATA GATHERING
    // =========================================================================

    /**
     * Gathers all data required to build student and parent prompts.
     *
     * @param int $game_id
     * @param int $player_id  The player row ID (not user_id) of the requesting player.
     */
    public static function get_summary_data(int $game_id, int $player_id): array {
        global $wpdb;
        $smg  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;
        $smp  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;
        $smss = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SELF_STRENGTHS;
        $smc  = $wpdb->prefix . MFSD_SS_DB::TBL_SM_CARDS;

        $game = $wpdb->get_row($wpdb->prepare("SELECT * FROM $smg WHERE id = %d", $game_id), ARRAY_A);
        if (!$game) return [];

        $all_players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $smp WHERE game_id = %d ORDER BY turn_order ASC", $game_id
        ), ARRAY_A);

        $viewer         = null;
        $student_player = null;
        foreach ($all_players as $p) {
            if ((int) $p['id'] === $player_id)  $viewer         = $p;
            if ($p['role'] === 'student')        $student_player = $p;
        }
        if (!$viewer || !$student_player) return [];

        $student_uid = (int) $student_player['user_id'];
        $student_pid = (int) $student_player['id'];
        $student_age = (int) get_user_meta($student_uid, 'mfsd_age', true);

        // Self-strengths indexed by player_id
        $ss_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT player_id, strength_text FROM $smss WHERE game_id = %d ORDER BY id ASC", $game_id
        ), ARRAY_A);
        $self_by_player = [];
        foreach ($ss_rows as $row) {
            $self_by_player[(int) $row['player_id']][] = $row['strength_text'];
        }

        // Family cards indexed by author → target
        $card_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT author_player_id, target_player_id, strength_text
             FROM $smc WHERE game_id = %d AND flagged = 0 ORDER BY id ASC",
            $game_id
        ), ARRAY_A);
        $cards_map = [];
        foreach ($card_rows as $c) {
            $aid = (int) $c['author_player_id'];
            $tid = (int) $c['target_player_id'];
            $cards_map[$aid][$tid][] = $c['strength_text'];
        }

        // Build parent records
        $parents = [];
        foreach ($all_players as $p) {
            if ($p['role'] === 'student') continue;
            $pid      = (int) $p['id'];
            $parents[] = [
                'id'                              => $pid,
                'user_id'                         => (int) $p['user_id'],
                'display_name'                    => $p['display_name'],
                'role'                            => $p['role'],
                'self_strengths'                  => $self_by_player[$pid] ?? [],
                'cards_about_student'             => $cards_map[$pid][$student_pid] ?? [],
                'student_cards_about_this_parent' => $cards_map[$student_pid][$pid] ?? [],
            ];
        }

        return [
            'game_id'     => $game_id,
            'game'        => $game,
            'viewer'      => $viewer,
            'viewer_role' => $viewer['role'],
            'student'     => [
                'id'             => $student_pid,
                'user_id'        => $student_uid,
                'display_name'   => $student_player['display_name'],
                'age'            => $student_age,
                'self_strengths' => $self_by_player[$student_pid] ?? [],
            ],
            'parents'     => $parents,
            'lens'        => self::get_lens_context($student_uid),
            'personality' => self::get_personality_context($student_uid),
            'word_assoc'  => self::get_word_assoc_context($student_uid),
        ];
    }

    // =========================================================================
    // CONTEXT FETCHERS — all degrade gracefully if data / table absent
    // =========================================================================

    public static function get_lens_context(int $student_user_id): array {
        global $wpdb;
        $tbl_s = $wpdb->prefix . 'mfsd_lens_sessions';
        $tbl_r = $wpdb->prefix . 'mfsd_lens_responses';
        $tbl_i = $wpdb->prefix . 'mfsd_lens_images';

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$tbl_s}'")) {
            return ['available' => false];
        }

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tbl_s} WHERE student_id = %d AND status = 'complete'
             ORDER BY completed_at DESC LIMIT 1",
            $student_user_id
        ), ARRAY_A);

        if (!$session) return ['available' => false];

        // Calculate agreements/differences from response pairs
        $responses = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tbl_r}'")) {
            $responses = $wpdb->get_results($wpdb->prepare(
                "SELECT r.role, r.card_position, r.choice, r.other_text,
                        i.name AS image_name, i.choice1_label, i.choice2_label
                 FROM {$tbl_r} r
                 LEFT JOIN {$tbl_i} i ON i.id = r.image_id
                 WHERE r.session_id = %s ORDER BY r.card_position ASC",
                $session['session_id']
            ), ARRAY_A);
        }

        $by_card = [];
        foreach ($responses as $r) {
            $by_card[(int) $r['card_position']][$r['role']] = $r;
        }

        $agreements = 0;
        $diff_parts = [];
        foreach ($by_card as $pos => $row) {
            $s = $row['student'] ?? null;
            $pa = $row['parent']  ?? null;
            if (!$s || !$pa) continue;
            $s_lbl  = ($s['choice']  === 'other') ? ($s['other_text']  ?? '') : ($s['choice']  === 'choice1' ? $s['choice1_label']  : $s['choice2_label']);
            $p_lbl  = ($pa['choice'] === 'other') ? ($pa['other_text'] ?? '') : ($pa['choice'] === 'choice1' ? $pa['choice1_label'] : $pa['choice2_label']);
            if ($s_lbl === $p_lbl) {
                $agreements++;
            } else {
                $img          = $s['image_name'] ?? ('Card ' . ($pos + 1));
                $diff_parts[] = "{$img}: student saw {$s_lbl}, parent saw {$p_lbl}";
            }
        }

        $lens_summary = '';
        if (!empty($session['ai_summary'])) {
            $lens_summary = strip_tags($session['ai_summary']);
            if (mb_strlen($lens_summary) > 300) {
                $lens_summary = mb_substr($lens_summary, 0, 297) . '…';
            }
        }

        return [
            'available'   => true,
            'agreements'  => $agreements,
            'differences' => empty($diff_parts) ? 'none' : implode('; ', $diff_parts),
            'summary'     => $lens_summary,
        ];
    }

    private static function get_personality_context(int $student_user_id): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mfsd_ptest_results';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$tbl}'")) return ['available' => false];

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT mbti_type FROM {$tbl}
             WHERE user_id = %d AND test_type = 'COMBINED' AND mbti_type IS NOT NULL
             ORDER BY id DESC LIMIT 1",
            $student_user_id
        ), ARRAY_A);

        if (empty($result['mbti_type'])) return ['available' => false];

        return [
            'available' => true,
            'mbti_type' => $result['mbti_type'],
            'label'     => self::mbti_label($result['mbti_type']),
        ];
    }

    private static function get_word_assoc_context(int $student_user_id): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mfsd_word_associations';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$tbl}'")) return ['available' => false];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT word, association_1, association_2, association_3, time_taken
             FROM {$tbl} WHERE user_id = %d ORDER BY time_taken ASC LIMIT 5",
            $student_user_id
        ), ARRAY_A);

        if (empty($rows)) return ['available' => false];

        $lines = [];
        foreach ($rows as $r) {
            $secs  = ($r['time_taken'] > 0) ? round($r['time_taken'] / 1000, 1) . 's' : '';
            $assoc = implode(', ', array_filter([$r['association_1'], $r['association_2'], $r['association_3']]));
            $lines[] = "{$r['word']}: {$assoc}" . ($secs ? " ({$secs})" : '');
        }

        return [
            'available' => true,
            'top'       => implode("\n", $lines),
        ];
    }

    // =========================================================================
    // PROMPT BUILDERS — all conditionals resolved in PHP
    // =========================================================================

    public static function build_student_prompt(array $data): string {
        $student = $data['student'];
        $parents = $data['parents'];
        $lens    = $data['lens'];
        $pers    = $data['personality'];
        $wa      = $data['word_assoc'];
        $name    = $student['display_name'];
        $age     = $student['age'];

        $p  = "STUDENT CONTEXT:\n";
        $p .= "Name: {$name}\n";
        $p .= "Age: {$age}\n\n";

        $p .= "SELF-CHOSEN STRENGTHS:\n";
        $p .= "{$name} chose these strengths about themselves:\n";
        foreach ($student['self_strengths'] as $s) { $p .= "• {$s}\n"; }
        $p .= "\n";

        $p .= "FAMILY STRENGTHS ABOUT {$name}:\n";
        foreach ($parents as $parent) {
            $p .= "{$parent['display_name']} chose these strengths about {$name}:\n";
            foreach ($parent['cards_about_student'] as $s) { $p .= "• {$s}\n"; }
            $p .= "\n";
        }

        $p .= "WHAT {$name} WROTE ABOUT THEIR FAMILY:\n";
        foreach ($parents as $parent) {
            $p .= "{$name} chose these strengths about {$parent['display_name']}:\n";
            foreach ($parent['student_cards_about_this_parent'] as $s) { $p .= "• {$s}\n"; }
            $p .= "\n";
        }

        if ($pers['available']) {
            $p .= "PERSONALITY TYPE (Who Am I):\n";
            $p .= "Type: {$pers['mbti_type']} — {$pers['label']}\n\n";
        }

        if ($wa['available']) {
            $p .= "WORD ASSOCIATION (fastest responses):\n";
            $p .= $wa['top'] . "\n\n";
        }

        if ($lens['available']) {
            $p .= "SOLUTION LENS:\n";
            $p .= "{$name} and their parent/carer saw {$lens['agreements']} images the same way.\n";
            $p .= "Differences: {$lens['differences']}\n";
            if ($lens['summary']) {
                $p .= "Steve's prior reflection: {$lens['summary']}\n";
            }
            $p .= "\n";
        }

        $p .= "Using the OUTPUT FORMAT in your instructions, generate {$name}'s Super Strengths summary across all four sections.";
        return $p;
    }

    public static function build_parent_prompt(array $data): string {
        $student = $data['student'];
        $viewer  = $data['viewer'];
        $parents = $data['parents'];
        $lens    = $data['lens'];
        $pers    = $data['personality'];
        $wa      = $data['word_assoc'];
        $sname   = $student['display_name'];
        $sage    = $student['age'];

        // Locate this parent's entry and any other participants
        $viewer_pid   = (int) $viewer['id'];
        $this_parent  = null;
        $other_parents = [];
        foreach ($parents as $par) {
            if ($par['id'] === $viewer_pid) $this_parent = $par;
            else $other_parents[] = $par;
        }
        if (!$this_parent) return '';

        $pname = $this_parent['display_name'];
        $prole = $this_parent['role'];

        $p  = "PARENT CONTEXT:\n";
        $p .= "Name: {$pname}\n";
        $p .= "Relationship to student: {$prole}\n\n";

        $p .= "STUDENT CONTEXT:\n";
        $p .= "Name: {$sname}\n";
        $p .= "Age: {$sage}\n\n";

        $p .= "STUDENT'S SELF-CHOSEN STRENGTHS:\n";
        $p .= "{$sname} chose these strengths about themselves:\n";
        foreach ($student['self_strengths'] as $s) { $p .= "• {$s}\n"; }
        $p .= "\n";

        $p .= "WHAT {$pname} WROTE ABOUT {$sname}:\n";
        $p .= "{$pname} chose these strengths about {$sname}:\n";
        foreach ($this_parent['cards_about_student'] as $s) { $p .= "• {$s}\n"; }
        $p .= "\n";

        $p .= "WHAT {$sname} WROTE ABOUT {$pname}:\n";
        $p .= "{$sname} chose these strengths about {$pname}:\n";
        foreach ($this_parent['student_cards_about_this_parent'] as $s) { $p .= "• {$s}\n"; }
        $p .= "\n";

        $p .= "{$pname}'s SELF-CHOSEN STRENGTHS:\n";
        $p .= "{$pname} chose these strengths about themselves:\n";
        foreach ($this_parent['self_strengths'] as $s) { $p .= "• {$s}\n"; }
        $p .= "\n";

        foreach ($other_parents as $op) {
            $p .= "OTHER FAMILY MEMBER CONTEXT:\n";
            $p .= "{$op['display_name']} also chose these strengths about {$sname}:\n";
            foreach ($op['cards_about_student'] as $s) { $p .= "• {$s}\n"; }
            $p .= "\n";
            $p .= "{$sname} chose these strengths about {$op['display_name']}:\n";
            foreach ($op['student_cards_about_this_parent'] as $s) { $p .= "• {$s}\n"; }
            $p .= "\n";
        }

        if ($pers['available']) {
            $p .= "PERSONALITY TYPE (Who Am I):\n";
            $p .= "Type: {$pers['mbti_type']} — {$pers['label']}\n\n";
        }

        if ($wa['available']) {
            $p .= "WORD ASSOCIATION ({$sname}'s fastest responses):\n";
            $p .= $wa['top'] . "\n\n";
        }

        if ($lens['available']) {
            $p .= "SOLUTION LENS:\n";
            $p .= "{$sname} and {$pname} saw {$lens['agreements']} images the same way.\n";
            $p .= "Differences: {$lens['differences']}\n";
            if ($lens['summary']) {
                $p .= "Steve's prior reflection: {$lens['summary']}\n";
            }
            $p .= "\n";
        }

        $p .= "Using the OUTPUT FORMAT in your instructions, generate {$pname}'s Super Strengths parent insight summary across all four sections.";
        return $p;
    }

    // =========================================================================
    // AI SUMMARY GENERATION
    // =========================================================================

    /**
     * Calls SteveGPT, parses ###SECTION### markers, stores result.
     * Returns ['ok' => true, 'sections' => [...], 'raw' => string]
     *      or ['error' => 'reason'].
     */
    public static function generate_ai_summary(int $game_id, int $player_id): array {
        $data = self::get_summary_data($game_id, $player_id);
        if (empty($data)) return ['error' => 'no_data'];

        $is_student = ($data['viewer_role'] === 'student');
        $opt_key    = $is_student
            ? 'mfsd_stevegpt_map_ss_student_summary'
            : 'mfsd_stevegpt_map_ss_parent_summary';
        $chatbot_id = get_option($opt_key, '');

        if (!$chatbot_id)                       return ['error' => 'chatbot_not_configured'];
        if (!class_exists('SteveGPT_Chatbot'))  return ['error' => 'stevegpt_unavailable'];

        $prompt = $is_student
            ? self::build_student_prompt($data)
            : self::build_parent_prompt($data);

        if (empty($prompt)) return ['error' => 'prompt_build_failed'];

        try {
            $ai      = SteveGPT_Chatbot::get($chatbot_id);
            $user_id = $is_student
                ? (int) $data['student']['user_id']
                : (int) $data['viewer']['user_id'];

            $raw      = $ai->query($prompt, $user_id);
            $sections = self::parse_sections($raw, $is_student);

            // Persist to mfsd_sm_summaries
            global $wpdb;
            $smsu = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SUMMARIES;
            $payload = [
                'game_id'      => $game_id,
                'player_id'    => $player_id,
                'summary_type' => $data['viewer_role'],
                'ai_summary'   => $raw,
                'generated_at' => current_time('mysql'),
            ];
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $smsu WHERE game_id = %d AND player_id = %d",
                $game_id, $player_id
            ));
            if ($existing) {
                $wpdb->update($smsu, $payload, ['game_id' => $game_id, 'player_id' => $player_id]);
            } else {
                $wpdb->insert($smsu, $payload);
            }

            return ['ok' => true, 'sections' => $sections, 'raw' => $raw];
        } catch (\Exception $e) {
            return ['error' => 'ai_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * Return already-stored summary sections, or generate fresh if not yet stored.
     */
    public static function get_or_generate_summary(int $game_id, int $player_id): array {
        global $wpdb;
        $smsu = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SUMMARIES;

        $stored = $wpdb->get_row($wpdb->prepare(
            "SELECT ai_summary, summary_type FROM $smsu WHERE game_id = %d AND player_id = %d",
            $game_id, $player_id
        ), ARRAY_A);

        if ($stored && !empty($stored['ai_summary'])) {
            $is_student = ($stored['summary_type'] === 'student');
            return [
                'ok'       => true,
                'sections' => self::parse_sections($stored['ai_summary'], $is_student),
                'raw'      => $stored['ai_summary'],
                'cached'   => true,
            ];
        }

        return self::generate_ai_summary($game_id, $player_id);
    }

    // =========================================================================
    // SECTION PARSER
    // =========================================================================

    public static function parse_sections(string $raw, bool $is_student = true): array {
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

        // Fallback: API returned no markers
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
    // CHAT CONTEXT BUILDER
    // =========================================================================

    /**
     * Builds the session context string to inject into the summary chat widget.
     * Includes all strength data + the stored AI summary for this player.
     */
    public static function build_chat_context(int $game_id, int $player_id): string {
        global $wpdb;
        $smsu = $wpdb->prefix . MFSD_SS_DB::TBL_SM_SUMMARIES;

        $data = self::get_summary_data($game_id, $player_id);
        if (empty($data)) return '';

        $is_student = ($data['viewer_role'] === 'student');
        $student    = $data['student'];
        $parents    = $data['parents'];

        $stored = $wpdb->get_row($wpdb->prepare(
            "SELECT ai_summary FROM $smsu WHERE game_id = %d AND player_id = %d",
            $game_id, $player_id
        ), ARRAY_A);
        $ai_summary = $stored['ai_summary'] ?? '';

        $ctx  = "=== SUPER STRENGTHS MEMORY — SESSION CONTEXT ===\n\n";
        $ctx .= "Student: {$student['display_name']} (age {$student['age']})\n";
        $ctx .= "Student's self-chosen strengths: " . implode(', ', $student['self_strengths']) . "\n\n";

        foreach ($parents as $par) {
            $ctx .= "{$par['display_name']} wrote about {$student['display_name']}: "
                  . implode(', ', $par['cards_about_student']) . "\n";
            $ctx .= "{$student['display_name']} wrote about {$par['display_name']}: "
                  . implode(', ', $par['student_cards_about_this_parent']) . "\n\n";
        }

        if (!$is_student) {
            $viewer_pid = (int) $data['viewer']['id'];
            foreach ($parents as $par) {
                if ($par['id'] === $viewer_pid) {
                    $ctx .= "{$par['display_name']}'s self-chosen strengths: "
                          . implode(', ', $par['self_strengths']) . "\n\n";
                    break;
                }
            }
        }

        $lc = $data['lens'];
        if ($lc['available']) {
            $ctx .= "Solution Lens: {$student['display_name']} and family agreed on {$lc['agreements']} cards.\n\n";
        }

        $pc = $data['personality'];
        if ($pc['available']) {
            $ctx .= "Personality type: {$pc['mbti_type']} — {$pc['label']}\n\n";
        }

        $wc = $data['word_assoc'];
        if ($wc['available']) {
            $ctx .= "Word Association (fastest responses):\n{$wc['top']}\n\n";
        }

        if ($ai_summary) {
            $key = $is_student ? '{{student_ai_summary}}' : '{{parent_ai_summary}}';
            $ctx .= "Steve's summary ({$key}):\n{$ai_summary}\n";
        }

        return $ctx;
    }
}
