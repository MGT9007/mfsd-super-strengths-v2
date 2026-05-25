<?php
/**
 * MFSD Super Strengths — Badge award engine (Phase D)
 * Mirrors class-lens-badges.php pattern. Student-only. Two badge types:
 * completion (10 coins) and winner (15 coins), each with 4 enamel pin designs.
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_Badges {

    const COINS_COMPLETE = 10;
    const COINS_WINNER   = 15;
    const DESIGNS        = ['steverman', 'supersteve', 'wondersteve', 'harley_steve'];

    // =========================================================================
    // PREFIX MATCH — Quest Log has_badge_type() not yet available, so we query
    // mfsd_badges directly with LIKE to detect any existing design variant.
    // =========================================================================

    private static function has_badge_type(int $student_id, string $prefix): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'mfsd_badges';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return false;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE student_id = %d AND badge_slug LIKE %s LIMIT 1",
            $student_id,
            $wpdb->esc_like($prefix) . '%'
        ));
    }

    // =========================================================================
    // CORE AWARD — randomly picks a design, prefix-checks, writes Quest Log row
    // Returns the awarded slug on success, false if Quest Log is unavailable or
    // the student already has this badge type.
    // =========================================================================

    public static function do_award(int $student_id, string $type): string|false {
        if (!class_exists('MFSD_Quest_Log_DB') || !class_exists('MFSD_Quest_Log_Wallet')) {
            return false;
        }

        $prefix = 'badge_ss_' . $type . '_';
        if (self::has_badge_type($student_id, $prefix)) return false;

        $designs = self::DESIGNS;

        // Winner badge must use a different design to the completion badge already awarded
        if ($type === 'winner') {
            $completion_slug = self::get_awarded_badge($student_id, 'complete');
            if ($completion_slug) {
                $completion_design = substr($completion_slug, strlen('badge_ss_complete_'));
                $designs = array_values(array_filter($designs, fn($d) => $d !== $completion_design));
            }
        }

        $design = $designs[array_rand($designs)];
        $slug   = $prefix . $design;
        $coins  = ($type === 'winner') ? self::COINS_WINNER : self::COINS_COMPLETE;

        $db      = new MFSD_Quest_Log_DB();
        $awarded = $db->award_badge($student_id, $slug, $coins);

        if ($awarded) {
            $wallet = new MFSD_Quest_Log_Wallet();
            $wallet->earn($student_id, $slug, $coins, 'Super Strengths ' . $type . ' badge earned');
        }

        return $awarded ? $slug : false;
    }

    // =========================================================================
    // COMPLETION BADGE — called from POST /memory/award-badge REST endpoint
    // after the student views the summary screen.
    // =========================================================================

    public static function award_completion(int $student_id, int $game_id): string|false {
        global $wpdb;
        $smg = $wpdb->prefix . MFSD_SS_DB::TBL_SM_GAMES;

        $game = $wpdb->get_row($wpdb->prepare(
            "SELECT status, student_user_id FROM {$smg} WHERE id = %d", $game_id
        ), ARRAY_A);

        if (!$game || $game['status'] !== 'complete') return false;
        if ((int) $game['student_user_id'] !== $student_id) return false;

        return self::do_award($student_id, 'complete');
    }

    // =========================================================================
    // WINNER BADGE — called for each winning student player at game end.
    // =========================================================================

    public static function award_winner(int $student_id, int $game_id): string|false {
        return self::do_award($student_id, 'winner');
    }

    // =========================================================================
    // LOOKUP — returns the badge slug if student already holds this badge type
    // =========================================================================

    public static function get_awarded_badge(int $student_id, string $type): string|false {
        global $wpdb;
        $table  = $wpdb->prefix . 'mfsd_badges';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return false;
        $prefix = 'badge_ss_' . $type . '_';
        return $wpdb->get_var($wpdb->prepare(
            "SELECT badge_slug FROM {$table} WHERE student_id = %d AND badge_slug LIKE %s ORDER BY earned_at DESC LIMIT 1",
            $student_id,
            $wpdb->esc_like($prefix) . '%'
        )) ?: false;
    }

    // =========================================================================
    // GAME-END DISPATCH — called from flip_card() once the game is marked
    // complete. Handles all_match ties: all students with the max score win.
    // =========================================================================

    public static function award_winners_on_game_end(int $game_id, string $memory_mode, int $winner_player_id): void {
        global $wpdb;
        $smp = $wpdb->prefix . MFSD_SS_DB::TBL_SM_PLAYERS;

        if ($memory_mode === 'all_match') {
            $max_score = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(score) FROM {$smp} WHERE game_id = %d", $game_id
            ));
            $winners = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, role FROM {$smp} WHERE game_id = %d AND score = %d",
                $game_id, $max_score
            ), ARRAY_A);
        } else {
            $winners = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, role FROM {$smp} WHERE id = %d", $winner_player_id
            ), ARRAY_A);
        }

        foreach ($winners as $w) {
            if ($w['role'] === 'student') {
                self::award_winner((int) $w['user_id'], $game_id);
            }
        }
    }
}
