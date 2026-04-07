<?php
/**
 * MFSD Super Strengths — Game Engine
 * Guessing game: deal, turns, scoring, AI summary, timeouts.
 * Snap game: session init, play card, snap win, expire, reshuffle, complete.
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_Game {

    // =========================================================================
    // GUESSING GAME — DEALING
    // =========================================================================
    public static function deal_cards($game_id) {
        global $wpdb;

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, role FROM $pp WHERE game_id = %d", $game_id
        ), ARRAY_A);

        $n         = count($players);
        $hand_size = ($n - 1) * MFSD_SS_DB::CARDS_PER_TARGET;

        $all_card_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $cp WHERE game_id = %d AND flagged = 0", $game_id
        ));
        shuffle($all_card_ids);

        // Student always plays first — shuffle the rest randomly
        $student_id = null;
        $other_ids  = [];
        foreach ($players as $pl) {
            if ($pl['role'] === 'student') $student_id = (int)$pl['id'];
            else $other_ids[] = (int)$pl['id'];
        }
        shuffle($other_ids);
        $player_ids = $student_id ? array_merge([$student_id], $other_ids) : $other_ids;

        foreach ($player_ids as $order => $pid) {
            $wpdb->update($pp, ['turn_order' => $order + 1], ['id' => $pid]);
        }

        $chunks = array_chunk($all_card_ids, $hand_size);
        foreach ($player_ids as $i => $pid) {
            if (!isset($chunks[$i])) continue;
            foreach ($chunks[$i] as $cid) {
                $wpdb->update($cp, ['dealt_to_player_id' => $pid], ['id' => $cid]);
            }
        }

        $wpdb->update($gp, ['status' => 'playing'], ['id' => $game_id]);
        self::create_next_turn($game_id);
    }

    // =========================================================================
    // GUESSING GAME — TURNS
    // =========================================================================
    public static function create_next_turn($game_id) {
        global $wpdb;

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
        $cp = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;

        $last_turn_num = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(turn_number) FROM $tp WHERE game_id = %d", $game_id
        ));
        $next_num = $last_turn_num + 1;

        $n = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $pp WHERE game_id = %d", $game_id
        ));

        $player_order    = ($last_turn_num % $n) + 1;
        $current_player  = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $pp WHERE game_id = %d AND turn_order = %d",
            $game_id, $player_order
        ), ARRAY_A);

        if (!$current_player) { self::check_game_complete($game_id); return; }

        $player_id = (int) $current_player['id'];
        $game      = $wpdb->get_row($wpdb->prepare(
            "SELECT round_limit FROM $gp WHERE id = %d", $game_id
        ), ARRAY_A);

        if ($n >= 5) {
            $turns_played = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $tp
                 WHERE game_id = %d AND played_by_player_id = %d AND ended_at IS NOT NULL",
                $game_id, $player_id
            ));
            if ($turns_played >= (int) $game['round_limit']) {
                self::check_game_complete($game_id); return;
            }
        }

        $card = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $cp
             WHERE game_id = %d AND dealt_to_player_id = %d AND played = 0 LIMIT 1",
            $game_id, $player_id
        ));
        if (!$card) { self::check_game_complete($game_id); return; }

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
    // GUESSING GAME — SCORING / REVEAL
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

        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT target_player_id, author_player_id FROM $cp WHERE id = %d", $card_id
        ), ARRAY_A);

        $correct_id = ($phase === 'A')
            ? (int) $card['target_player_id']
            : (int) $card['author_player_id'];

        $votes        = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $vp WHERE turn_id = %d AND phase = %s", $turn_id, $phase
        ), ARRAY_A);
        $round_points = [];

        foreach ($votes as $vote) {
            $voter_id   = (int) $vote['voter_player_id'];
            $is_correct = ((int) $vote['selected_player_id'] === $correct_id) ? 1 : 0;
            $is_conf    = (int) $vote['is_confident'];
            $points     = 0;

            if ($is_correct) {
                $points += 2;
                if ($is_conf) $points += 3;
            } else {
                if ($is_conf) {
                    $points -= 3;
                    $round_points[$player_id] = ($round_points[$player_id] ?? 0) + 3;
                }
            }

            $wpdb->update($vp, ['is_correct' => $is_correct, 'points_earned' => $points], ['id' => (int) $vote['id']]);
            $wpdb->query($wpdb->prepare(
                "UPDATE $pp SET score_total = score_total + %d WHERE id = %d", $points, $voter_id
            ));
            $round_points[$voter_id] = ($round_points[$voter_id] ?? 0) + $points;
        }

        if (!empty($round_points[$player_id]) && $round_points[$player_id] > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $pp SET score_total = score_total + %d WHERE id = %d",
                $round_points[$player_id], $player_id
            ));
        }

        if (!empty($round_points)) {
            $max_pts = max($round_points);
            if ($max_pts > 0) {
                foreach ($round_points as $pid => $pts) {
                    if ($pts === $max_pts) {
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $pp SET score_total = score_total + 1 WHERE id = %d", $pid
                        ));
                    }
                }
                $winner_pid = array_key_first(array_filter($round_points, fn($p) => $p === $max_pts));
                $wpdb->update($tp, ['round_winner_player_id' => $winner_pid], ['id' => $turn_id]);
            }
        }

        if ($phase === 'A') {
            if ($game_mode === 'full') {
                $timeout_h = (int) get_option('mfsd_ss_vote_timeout', 24);
                $reveal_b  = date('Y-m-d H:i:s', strtotime("+{$timeout_h} hours"));
                $wpdb->update($tp, ['phase' => 'B', 'phase_b_reveal_at' => $reveal_b], ['id' => $turn_id]);
            } else {
                self::_end_turn($turn_id, $game_id);
            }
        } else {
            self::_end_turn($turn_id, $game_id);
        }
    }

    private static function _end_turn($turn_id, $game_id) {
        global $wpdb;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;
        $wpdb->update($tp, ['phase' => 'complete', 'ended_at' => current_time('mysql')], ['id' => $turn_id]);
        self::create_next_turn($game_id);
    }

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
            $wpdb->update($gp, ['status' => 'complete', 'current_turn_id' => null], ['id' => $game_id]);
            self::notify_task_complete($game_id);
        }
    }

    // =========================================================================
    // GUESSING GAME — AI SUMMARY
    // =========================================================================
    public static function generate_strengths_summary(array $cards, string $name): ?string {
        if (empty($cards) || !isset($GLOBALS['mwai'])) return null;
        $list = implode(', ', array_column($cards, 'strength_text'));
        try {
            $prompt  = "You are a warm, encouraging coach speaking directly to {$name}, a student aged 11–14.\n\n";
            $prompt .= "Here are all the Super Strength cards that {$name}'s family wrote for them:\n{$list}\n\n";
            $prompt .= "Write 3–4 sentences that:\n";
            $prompt .= "1. Celebrate the themes you notice.\n";
            $prompt .= "2. Name one or two standout patterns.\n";
            $prompt .= "3. Suggest one specific way {$name} could lean into a strength this week.\n\n";
            $prompt .= "Rules: Address {$name} as 'you'/'your'. UK English. Warm. Age-appropriate. Start with: **Steve says:**";
            return $GLOBALS['mwai']->simpleTextQuery($prompt);
        } catch (Exception $e) {
            error_log('MFSD SS: AI summary error: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // GUESSING GAME — TIMEOUT CRON
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
            if ($game['mode'] === 'snap' || !$game['current_turn_id']) continue;

            $turn = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tp WHERE id = %d", (int) $game['current_turn_id']
            ), ARRAY_A);
            if (!$turn) continue;

            $now = current_time('mysql');
            if ((int) $turn['card_id'] === 0) {
                $timeout_h = (int) get_option('mfsd_ss_turn_timeout', 24);
                $deadline  = date('Y-m-d H:i:s', strtotime($turn['started_at'] . " +{$timeout_h} hours"));
                if ($now > $deadline) self::_handle_play_timeout($turn, $game['id']);
            } elseif ($turn['phase'] === 'A' && $turn['phase_a_reveal_at'] && $now > $turn['phase_a_reveal_at']) {
                self::process_reveal($turn, $game['id'], $game['mode']);
            } elseif ($turn['phase'] === 'B' && $turn['phase_b_reveal_at'] && $now > $turn['phase_b_reveal_at']) {
                self::process_reveal($turn, $game['id'], $game['mode']);
            }
        }
    }

    private static function _handle_play_timeout($turn, $game_id) {
        global $wpdb;
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $tp = $wpdb->prefix . MFSD_SS_DB::TBL_TURNS;

        $player_id = (int) $turn['played_by_player_id'];

        if (!(int) $turn['skipped_once']) {
            $wpdb->update($tp, ['skipped_once' => 1, 'ended_at' => current_time('mysql')], ['id' => (int) $turn['id']]);
            self::create_next_turn($game_id);
        } else {
            $n = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $pp WHERE game_id = %d", $game_id
            ));
            $wpdb->query($wpdb->prepare(
                "UPDATE $pp SET score_total = score_total - %d WHERE id = %d", 3 * ($n - 1), $player_id
            ));
            $wpdb->query($wpdb->prepare(
                "UPDATE $pp SET score_total = score_total + 3 WHERE game_id = %d AND id != %d",
                $game_id, $player_id
            ));
            $wpdb->update($tp, ['phase' => 'complete', 'ended_at' => current_time('mysql')], ['id' => (int) $turn['id']]);
            self::create_next_turn($game_id);
        }
    }

    // =========================================================================
    // SNAP — SESSION INITIALISATION
    // Called after all players submit in snap mode.
    // Duplicates the card pool, shuffles, deals (n-1)*10 cards per player.
    // =========================================================================
    public static function init_snap_session($game_id) {
        global $wpdb;

        $pp  = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $cp  = $wpdb->prefix . MFSD_SS_DB::TBL_CARDS;
        $gp  = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;
        $ss  = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $sh  = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;

        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, role FROM $pp WHERE game_id = %d ORDER BY id ASC", $game_id
        ), ARRAY_A);
        $n = count($players);

        // All submitted, unflagged cards
        $cards = $wpdb->get_results($wpdb->prepare(
            "SELECT id, strength_text FROM $cp WHERE game_id = %d AND flagged = 0", $game_id
        ), ARRAY_A);

        // Duplicate the entire pool — each card appears exactly twice (guaranteed pair)
        $pool = [];
        foreach ($cards as $card) {
            $entry = ['card_id' => (int)$card['id'], 'strength_text' => $card['strength_text']];
            $pool[] = $entry;
            $pool[] = $entry; // twin
        }
        shuffle($pool);

        // hand_size = (n-1) * 5 cards submitted * 2 duplicates / n players = (n-1) * 10
        $hand_size = ($n - 1) * 10;

        // Find student — child always plays first
        $student = null;
        foreach ($players as $player) {
            if ($player['role'] === 'student') { $student = $player; break; }
        }
        $first_player_id = $student ? (int)$student['id'] : (int)$players[0]['id'];

        // Create session
        $wpdb->insert($ss, [
            'game_id'               => $game_id,
            'status'                => 'waiting',
            'snap_mode'             => get_option('mfsd_ss_snap_mode', 'quick_draw'),
            'quick_draw_target'     => (int) get_option('mfsd_ss_snap_quick_draw_target', 5),
            'snap_timer_seconds'    => (int) get_option('mfsd_ss_snap_timer', 3),
            'current_turn_player_id'=> $first_player_id,
            'pile'                  => json_encode([]),
            'total_snaps_won'       => 0,
        ]);
        $session_id = $wpdb->insert_id;

        // Deal hands from shuffled pool
        $chunks = array_chunk($pool, $hand_size);
        foreach ($players as $i => $player) {
            $hand = $chunks[$i] ?? [];
            $wpdb->insert($sh, [
                'session_id' => $session_id,
                'player_id'  => (int)$player['id'],
                'cards'      => json_encode($hand),
                'snap_score' => 0,
                'is_present' => 0,
            ]);
        }

        // Game status stays at 'playing' — JS detects snap mode and routes to snap UI
        $wpdb->update($gp, ['status' => 'playing'], ['id' => $game_id]);

        return $session_id;
    }

    // =========================================================================
    // SNAP — NEXT PLAYER (skips players with no cards if others still have some)
    // =========================================================================
    public static function get_next_snap_player($session_id, $game_id, $current_player_id) {
        global $wpdb;

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;
        $sh = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;

        // Get all players in turn order: student first, then others by player id
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.role FROM $pp p
             JOIN $sh h ON h.player_id = p.id AND h.session_id = %d
             WHERE p.game_id = %d
             ORDER BY (p.role = 'student') DESC, p.id ASC",
            $session_id, $game_id
        ), ARRAY_A);

        $ids = array_column($players, 'id');
        $cur_idx = array_search($current_player_id, $ids);

        // Try each subsequent player in rotation; skip if empty hand
        for ($i = 1; $i <= count($ids); $i++) {
            $next_idx = ($cur_idx + $i) % count($ids);
            $next_id  = (int)$ids[$next_idx];

            $hand_json = $wpdb->get_var($wpdb->prepare(
                "SELECT cards FROM $sh WHERE session_id = %d AND player_id = %d",
                $session_id, $next_id
            ));
            $hand = json_decode($hand_json, true) ?: [];
            if (!empty($hand)) return $next_id;
        }

        // All hands empty — return current player (reshuffle will fix this)
        return $current_player_id;
    }

    // =========================================================================
    // SNAP — PROCESS WIN (called when a player successfully claims snap)
    // =========================================================================
    public static function process_snap_win($session, $winner_player_id, $game_id) {
        global $wpdb;

        $ss = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $sh = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $session_id  = (int)$session['id'];
        $pile        = json_decode($session['pile'], true) ?: [];
        $snaps_won   = (int)$session['total_snaps_won'] + 1;

        // Top 2 cards are the matched pair — permanently removed from the game
        $top    = array_pop($pile); // matched card 1
        $second = array_pop($pile); // matched card 2 — both removed

        // Remaining pile cards go to the winner's hand (appended to back)
        $remaining_pile = $pile; // everything below the matched pair
        $pile_for_winner = $remaining_pile;

        // Load winner's current hand
        $hand_json    = $wpdb->get_var($wpdb->prepare(
            "SELECT cards FROM $sh WHERE session_id = %d AND player_id = %d",
            $session_id, $winner_player_id
        ));
        $winner_hand  = json_decode($hand_json, true) ?: [];

        // Pile cards go to winner (shuffled before adding so order isn't predictable)
        shuffle($pile_for_winner);
        $new_winner_hand = array_merge($winner_hand, $pile_for_winner);

        // Persist winner's updated hand
        $wpdb->update($sh, ['cards' => json_encode($new_winner_hand)], [
            'session_id' => $session_id, 'player_id' => $winner_player_id,
        ]);

        // Increment winner score
        $wpdb->query($wpdb->prepare(
            "UPDATE $sh SET snap_score = snap_score + 1
             WHERE session_id = %d AND player_id = %d",
            $session_id, $winner_player_id
        ));

        // Clear claims for this session so the NEXT snap opportunity can be claimed.
        // Without this, the won=1 record blocks all subsequent snaps in the same session.
        $sc_t = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_CLAIMS;
        $wpdb->delete($sc_t, ['session_id' => $session_id]);

        // Clear pile, winner plays next
        $next_player_id = (int)$winner_player_id;

        $wpdb->update($ss, [
            'status'                 => 'playing',
            'pile'                   => json_encode([]),
            'snap_x'                 => null,
            'snap_y'                 => null,
            'snap_expires_at'        => null,
            'total_snaps_won'        => $snaps_won,
            'last_snap_winner_id'    => $winner_player_id,
            'current_turn_player_id' => $next_player_id,
        ], ['id' => $session_id]);

        // Check win conditions
        self::check_snap_complete($session_id, $game_id, $snaps_won, $session['snap_mode'], (int)$session['quick_draw_target']);
    }

    // =========================================================================
    // SNAP — EXPIRE (no one claimed snap in time)
    // =========================================================================
    public static function expire_snap($session, $game_id) {
        global $wpdb;

        $ss = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $sh = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;

        $session_id = (int)$session['id'];
        $pile       = json_decode($session['pile'], true) ?: [];

        // Cards stay in pile — next player in turn plays onto them
        $next_player_id = self::get_next_snap_player(
            $session_id, $game_id, (int)$session['current_turn_player_id']
        );

        $wpdb->update($ss, [
            'status'                 => 'playing',
            'snap_x'                 => null,
            'snap_y'                 => null,
            'snap_expires_at'        => null,
            'current_turn_player_id' => $next_player_id,
        ], ['id' => $session_id]);

        // If all hands are empty after expiry, reshuffle pile back into hands
        self::check_snap_reshuffle($session_id, $game_id);
    }

    // =========================================================================
    // SNAP — CHECK WIN CONDITIONS
    // =========================================================================
    public static function check_snap_complete($session_id, $game_id, $snaps_won, $snap_mode, $quick_draw_target) {
        global $wpdb;

        $ss = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $sh = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;
        $gp = $wpdb->prefix . MFSD_SS_DB::TBL_GAMES;

        $complete = false;

        if ($snap_mode === 'quick_draw' && $snaps_won >= $quick_draw_target) {
            $complete = true;
        } elseif ($snap_mode === 'until_death') {
            // All cards snapped and removed — no cards in any hand and pile is empty
            $total_in_hands = 0;
            $hands = $wpdb->get_results($wpdb->prepare(
                "SELECT cards FROM $sh WHERE session_id = %d", $session_id
            ), ARRAY_A);
            foreach ($hands as $h) {
                $total_in_hands += count(json_decode($h['cards'], true) ?: []);
            }
            $pile = json_decode($wpdb->get_var($wpdb->prepare(
                "SELECT pile FROM $ss WHERE id = %d", $session_id
            )), true) ?: [];
            if ($total_in_hands === 0 && empty($pile)) $complete = true;
        }

        if (!$complete) return;

        // Find winner or trigger tiebreaker
        $hands = $wpdb->get_results($wpdb->prepare(
            "SELECT player_id, snap_score FROM $sh WHERE session_id = %d ORDER BY snap_score DESC",
            $session_id
        ), ARRAY_A);

        $top_score  = (int)$hands[0]['snap_score'];
        $tied       = array_filter($hands, fn($h) => (int)$h['snap_score'] === $top_score);

        if (count($tied) === 1) {
            // Clear winner
            $winner_id = (int)$hands[0]['player_id'];
            $wpdb->update($ss, [
                'status'           => 'complete',
                'winner_player_id' => $winner_id,
            ], ['id' => $session_id]);
            $wpdb->update($gp, ['status' => 'complete'], ['id' => $game_id]);
            self::notify_task_complete($game_id);
        } else {
            // Tiebreaker — 5-second snap; first to click wins
            $timer     = 5;
            $expires   = gmdate('Y-m-d H:i:s', time() + $timer);
            $snap_x    = rand(10, 85);
            $snap_y    = rand(20, 75);
            $wpdb->update($ss, [
                'status'          => 'tiebreaker',
                'snap_x'          => $snap_x,
                'snap_y'          => $snap_y,
                'snap_expires_at' => $expires,
            ], ['id' => $session_id]);
        }
    }

    // =========================================================================
    // SNAP — RESHUFFLE
    // Called when all player hands are empty (no snap has occurred yet with remaining cards).
    // Remaining pile cards are reshuffled and redealt. Child plays first.
    // =========================================================================
    public static function check_snap_reshuffle($session_id, $game_id) {
        global $wpdb;

        $ss = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_SESSIONS;
        $sh = $wpdb->prefix . MFSD_SS_DB::TBL_SNAP_HANDS;
        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;

        $hands = $wpdb->get_results($wpdb->prepare(
            "SELECT player_id, cards FROM $sh WHERE session_id = %d", $session_id
        ), ARRAY_A);

        $all_empty = true;
        foreach ($hands as $h) {
            if (!empty(json_decode($h['cards'], true))) { $all_empty = false; break; }
        }
        if (!$all_empty) return;

        // All hands empty — pull pile back in
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $ss WHERE id = %d", $session_id
        ), ARRAY_A);
        $pile = json_decode($session['pile'], true) ?: [];

        if (empty($pile)) {
            // Nothing left — shouldn't happen in snap mode but handle gracefully
            self::check_snap_complete($session_id, $game_id, (int)$session['total_snaps_won'],
                $session['snap_mode'], (int)$session['quick_draw_target']);
            return;
        }

        shuffle($pile);
        $n         = count($hands);
        $hand_size = (int) floor(count($pile) / $n);
        $chunks    = array_chunk($pile, max(1, $hand_size));

        // Find student player ID
        $student_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $pp WHERE game_id = %d AND role = 'student' LIMIT 1", $game_id
        ));

        foreach ($hands as $i => $h) {
            $new_hand = $chunks[$i] ?? [];
            $wpdb->update($sh, ['cards' => json_encode($new_hand)], [
                'session_id' => $session_id, 'player_id' => (int)$h['player_id'],
            ]);
        }

        // Clear pile, child plays first after reshuffle
        $wpdb->update($ss, [
            'pile'                   => json_encode([]),
            'current_turn_player_id' => $student_id ?: (int)$hands[0]['player_id'],
        ], ['id' => $session_id]);
    }

    // =========================================================================
    // MFSD COURSES — notify ordering system that Super Strengths is complete
    // Finds the student player for this game and calls mfsd_set_task_status.
    // =========================================================================
    public static function notify_task_complete(int $game_id): void {
        if (!function_exists('mfsd_set_task_status')) return;
        global $wpdb;

        $pp = $wpdb->prefix . MFSD_SS_DB::TBL_PLAYERS;

        // Find the student player's WP user_id
        $student_user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM $pp WHERE game_id = %d AND role = 'student' LIMIT 1",
            $game_id
        ));

        if ($student_user_id) {
            mfsd_set_task_status($student_user_id, 'super_strengths', 'completed');
        }
    }
}