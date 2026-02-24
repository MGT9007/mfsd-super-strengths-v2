<?php
/**
 * MFSD Super Strengths — Game Engine
 * Handles: dealing, turn creation, phase reveals, scoring, timeouts, AI summary.
 * Pure logic — no HTTP, no output. Called by API and cron.
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_Game {

    // =========================================================================
    // DEALING
    // Called the moment all players have submitted their cards.
    // Hand size = (n-1) × 5. Always divides exactly — no remainder possible.
    // =========================================================================
    public static function deal_cards($game_id) {
        global $wpdb;

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $pp WHERE game_id = %d",
            $game_id
        ), ARRAY_A);

        $n         = count($players);
        $hand_size = ($n - 1) * MFSD_SS_DB::CARDS_PER_TARGET;

        // All unflagged cards in the game
        $all_card_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $cp WHERE game_id = %d AND flagged = 0",
            $game_id
        ));

        shuffle($all_card_ids);

        // Assign randomised turn order
        $player_ids = array_column($players, 'id');
        shuffle($player_ids);

        foreach ($player_ids as $order => $pid) {
            $wpdb->update($pp, ['turn_order' => $order + 1], ['id' => $pid]);
        }

        // Deal hand_size cards to each player in turn order
        // Total pool = n × (n-1) × 5 = n × hand_size → always exact split
        $chunks = array_chunk($all_card_ids, $hand_size);
        foreach ($player_ids as $i => $pid) {
            if (!isset($chunks[$i])) continue;
            foreach ($chunks[$i] as $cid) {
                $wpdb->update($cp, ['dealt_to_player_id' => $pid], ['id' => $cid]);
            }
        }

        // Advance game to playing and create the first turn
        $wpdb->update($gp, ['status' => 'playing'], ['id' => $game_id]);
        self::create_next_turn($game_id);
    }

    // =========================================================================
    // TURN CREATION
    // Finds whose turn it is next and creates a turn row.
    // For 5-6 players, respects round_limit.
    // =========================================================================
    public static function create_next_turn($game_id) {
        global $wpdb;

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $last_turn_num = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(turn_number) FROM $tp WHERE game_id = %d",
            $game_id
        ));
        $next_num = $last_turn_num + 1;

        $n = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $pp WHERE game_id = %d",
            $game_id
        ));

        // Rotating turn order: 1-indexed
        $player_order = ($last_turn_num % $n) + 1;

        $current_player = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $pp WHERE game_id = %d AND turn_order = %d",
            $game_id, $player_order
        ), ARRAY_A);

        if (!$current_player) {
            self::check_game_complete($game_id);
            return;
        }

        $player_id = (int) $current_player['id'];
        $game      = $wpdb->get_row($wpdb->prepare(
            "SELECT round_limit FROM $gp WHERE id = %d",
            $game_id
        ), ARRAY_A);

        // For 5-6 players: enforce round limit per player
        if ($n >= 5) {
            $turns_played = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $tp
                 WHERE game_id = %d AND played_by_player_id = %d AND ended_at IS NOT NULL",
                $game_id, $player_id
            ));
            if ($turns_played >= (int) $game['round_limit']) {
                self::check_game_complete($game_id);
                return;
            }
        }

        // Confirm this player still has unplayed cards
        $card = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $cp
             WHERE game_id = %d AND dealt_to_player_id = %d AND played = 0
             LIMIT 1",
            $game_id, $player_id
        ));

        if (!$card) {
            self::check_game_complete($game_id);
            return;
        }

        // Insert turn row — card_id set to 0 until the player actively plays
        $wpdb->insert($tp, [
            'game_id'             => $game_id,
            'turn_number'         => $next_num,
            'card_id'             => 0,
            'played_by_player_id' => $player_id,
            'phase'               => 'A',
        ]);
        $turn_id = $wpdb->insert_id;

        $wpdb->update($gp, ['current_turn_id' => $turn_id], ['id' => $game_id]);
    }

    // =========================================================================
    // REVEAL + SCORING
    // Processes all votes for the current phase, applies scoring, advances phase.
    // =========================================================================
    public static function process_reveal($turn, $game_id, $game_mode) {
        global $wpdb;

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $vp = $wpdb->prefix . MFSD_SS_DB::TBL_VOTES;

        $turn_id   = (int) $turn['id'];
        $card_id   = (int) $turn['card_id'];
        $player_id = (int) $turn['played_by_player_id'];
        $phase     = $turn['phase'];

        // Correct answer: Phase A = target player; Phase B = author player
        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT target_player_id, author_player_id FROM $cp WHERE id = %d",
            $card_id
        ), ARRAY_A);

        $correct_id = ($phase === 'A')
            ? (int) $card['target_player_id']
            : (int) $card['author_player_id'];

        // Load all votes for this phase
        $votes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $vp WHERE turn_id = %d AND phase = %s",
            $turn_id, $phase
        ), ARRAY_A);

        $round_points = []; // player_id => net points gained this phase

        foreach ($votes as $vote) {
            $voter_id   = (int) $vote['voter_player_id'];
            $is_correct = ((int) $vote['selected_player_id'] === $correct_id) ? 1 : 0;
            $is_conf    = (int) $vote['is_confident'];
            $points     = 0;

            if ($is_correct) {
                $points += 2; // base: correct vote
                if ($is_conf) $points += 3; // Confidence Token bonus
            } else {
                if ($is_conf) {
                    $points -= 3; // Confidence Token penalty for voter
                    // Card player gains +3 per wrong confident vote (Section 10)
                    $round_points[$player_id] = ($round_points[$player_id] ?? 0) + 3;
                }
            }

            // Persist verdict and points for this vote
            $wpdb->update($vp, [
                'is_correct'    => $is_correct,
                'points_earned' => $points,
            ], ['id' => (int) $vote['id']]);

            // Apply to player score
            $wpdb->query($wpdb->prepare(
                "UPDATE $pp SET score_total = score_total + %d WHERE id = %d",
                $points, $voter_id
            ));

            $round_points[$voter_id] = ($round_points[$voter_id] ?? 0) + $points;
        }

        // Apply the card player's accumulated +3 bonuses from wrong confident votes
        if (!empty($round_points[$player_id]) && $round_points[$player_id] > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $pp SET score_total = score_total + %d WHERE id = %d",
                $round_points[$player_id], $player_id
            ));
        }

        // Round winner bonus (+1 to highest scorer this turn — Section 11)
        if (!empty($round_points)) {
            $max_pts = max($round_points);
            if ($max_pts > 0) {
                foreach ($round_points as $pid => $pts) {
                    if ($pts === $max_pts) {
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $pp SET score_total = score_total + 1 WHERE id = %d",
                            $pid
                        ));
                    }
                }
                // Record first round winner in turn row
                $winner_pid = array_key_first(array_filter(
                    $round_points,
                    fn($p) => $p === $max_pts
                ));
                $wpdb->update($tp, ['round_winner_player_id' => $winner_pid], ['id' => $turn_id]);
            }
        }

        // ── Advance phase ────────────────────────────────────────────────────
        if ($phase === 'A') {
            if ($game_mode === 'full') {
                $timeout_h  = (int) get_option('mfsd_ss_vote_timeout', 24);
                $reveal_b   = date('Y-m-d H:i:s', strtotime("+{$timeout_h} hours"));
                $wpdb->update($tp, [
                    'phase'              => 'B',
                    'phase_b_reveal_at'  => $reveal_b,
                ], ['id' => $turn_id]);
            } else {
                // Short mode — no Phase B
                self::end_turn($turn_id, $game_id);
            }
        } else {
            // Phase B complete
            self::end_turn($turn_id, $game_id);
        }
    }

    private static function end_turn($turn_id, $game_id) {
        global $wpdb;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $wpdb->update($tp, [
            'phase'    => 'complete',
            'ended_at' => current_time('mysql'),
        ], ['id' => $turn_id]);
        self::create_next_turn($game_id);
    }

    // =========================================================================
    // CHECK GAME COMPLETE
    // =========================================================================
    public static function check_game_complete($game_id) {
        global $wpdb;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $unplayed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $cp
             WHERE game_id = %d AND dealt_to_player_id IS NOT NULL AND played = 0",
            $game_id
        ));

        if ($unplayed === 0) {
            $wpdb->update($gp, [
                'status'          => 'complete',
                'current_turn_id' => null,
            ], ['id' => $game_id]);
        }
    }

    // =========================================================================
    // AI SUMMARY — called on final results screen
    // =========================================================================
    public static function generate_strengths_summary(array $cards, string $name): ?string {
        if (empty($cards) || !isset($GLOBALS['mwai'])) return null;

        $list = implode(', ', array_column($cards, 'strength_text'));

        try {
            $prompt  = "You are a warm, encouraging coach speaking directly to {$name}, a student aged 11–14.\n\n";
            $prompt .= "Here are all the Super Strength cards that {$name}'s family and friends wrote for them:\n{$list}\n\n";
            $prompt .= "Write 3–4 sentences that:\n";
            $prompt .= "1. Celebrate the themes you notice across these strengths.\n";
            $prompt .= "2. Name one or two standout patterns — e.g. 'people really see your kindness'.\n";
            $prompt .= "3. Suggest one specific way {$name} could lean into one of these strengths this week.\n\n";
            $prompt .= "Rules: Address {$name} as 'you'/'your'. UK English. ";
            $prompt .= "Warm and growth-focused. Age-appropriate for 11–14. Start with: **Steve says:**";

            return $GLOBALS['mwai']->simpleTextQuery($prompt);
        } catch (Exception $e) {
            error_log('MFSD SS: AI summary error: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // TIMEOUT CRON (runs hourly)
    // =========================================================================
    public static function run_timeout_check() {
        global $wpdb;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;

        $games = $wpdb->get_results(
            "SELECT id, mode, current_turn_id FROM $gp WHERE status = 'playing'",
            ARRAY_A
        );

        foreach ($games as $game) {
            if (!$game['current_turn_id']) continue;

            $turn = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tp WHERE id = %d",
                (int) $game['current_turn_id']
            ), ARRAY_A);

            if (!$turn) continue;

            $now = current_time('mysql');

            // No card played yet — check play timeout
            if ((int) $turn['card_id'] === 0) {
                $timeout_h = (int) get_option('mfsd_ss_turn_timeout', 24);
                $deadline  = date('Y-m-d H:i:s', strtotime($turn['started_at'] . " +{$timeout_h} hours"));
                if ($now > $deadline) {
                    self::handle_play_timeout($turn, $game['id']);
                }
            } elseif ($turn['phase'] === 'A' && $turn['phase_a_reveal_at'] && $now > $turn['phase_a_reveal_at']) {
                self::process_reveal($turn, $game['id'], $game['mode']);
            } elseif ($turn['phase'] === 'B' && $turn['phase_b_reveal_at'] && $now > $turn['phase_b_reveal_at']) {
                self::process_reveal($turn, $game['id'], $game['mode']);
            }
        }
    }

    /**
     * Two-strike timeout rule (Section 12.1):
     * First skip → skipped, appended to end of turn order.
     * Second skip → forfeit: −3×(n−1) for the player, +3 per player for everyone else.
     */
    private static function handle_play_timeout($turn, $game_id) {
        global $wpdb;
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;

        $player_id = (int) $turn['played_by_player_id'];

        if (!(int) $turn['skipped_once']) {
            // First skip — move on, mark skipped
            $wpdb->update($tp, [
                'skipped_once' => 1,
                'ended_at'     => current_time('mysql'),
            ], ['id' => (int) $turn['id']]);
            self::create_next_turn($game_id);
        } else {
            // Second skip — forfeit penalties
            $n = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $pp WHERE game_id = %d",
                $game_id
            ));

            // Forfeit player loses 3 × (n-1)
            $wpdb->query($wpdb->prepare(
                "UPDATE $pp SET score_total = score_total - %d WHERE id = %d",
                3 * ($n - 1), $player_id
            ));

            // Every other player gains +3
            $wpdb->query($wpdb->prepare(
                "UPDATE $pp SET score_total = score_total + 3
                 WHERE game_id = %d AND id != %d",
                $game_id, $player_id
            ));

            $wpdb->update($tp, [
                'phase'    => 'complete',
                'ended_at' => current_time('mysql'),
            ], ['id' => (int) $turn['id']]);

            self::create_next_turn($game_id);
        }
    }
}
