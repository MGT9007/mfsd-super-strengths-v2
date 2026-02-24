<?php
/**
 * MFSD Super Strengths — Free-text validator
 * Implements the 4-step pipeline from Appendix E:
 *   1. Normalise leetspeak
 *   2. Check profanity / banned term list
 *   3. Run PII regex patterns
 *   4. Block or flag for review
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_Validator {

    /**
     * Main entry point.
     * Returns an array: [ 'valid', 'action' (allow|block|flag), 'reason', 'message', ... ]
     */
    public static function validate($text) {
        if (empty(trim($text))) {
            return [
                'valid'   => false,
                'action'  => 'block',
                'reason'  => 'empty',
                'message' => 'Please write a Super Strength before adding.',
            ];
        }

        // Step 1 → normalise leetspeak then run banned terms
        $normalised = self::normalise_leet($text);
        $banned_result = self::check_banned_terms($text, $normalised);
        if ($banned_result) return $banned_result;

        // Step 2 → PII regex patterns
        $pii_result = self::check_pii($text);
        if ($pii_result) return $pii_result;

        return ['valid' => true, 'action' => 'allow'];
    }

    // ── Step 1a: Leetspeak normalisation ─────────────────────────────────────
    // Converts common character substitutions before checking the word list.
    // This means we don't need to maintain hundreds of variants manually.
    private static function normalise_leet($text) {
        $map = [
            '4'  => 'a',
            '@'  => 'a',
            '3'  => 'e',
            '1'  => 'i',
            '!'  => 'i',
            '|'  => 'i',
            '0'  => 'o',
            '$'  => 's',
            '5'  => 's',
            '7'  => 't',
            'ph' => 'f',
            'ck' => 'k',
        ];

        $out = strtolower($text);
        foreach ($map as $leet => $plain) {
            $out = str_replace($leet, $plain, $out);
        }
        // Strip non-alpha so f**k → fk which may still match a normalised form
        return preg_replace('/[^a-z\s]/u', '', $out);
    }

    // ── Step 1b: Banned term list check ──────────────────────────────────────
    private static function check_banned_terms($original, $normalised) {
        global $wpdb;
        $table = $wpdb->prefix . MFSD_SS_DB::TBL_BANNED;

        $terms = $wpdb->get_results(
            "SELECT term, action FROM $table WHERE active = 1",
            ARRAY_A
        );

        $orig_lower = strtolower($original);
        $norm_lower  = strtolower($normalised);

        foreach ($terms as $row) {
            $term = strtolower($row['term']);
            $hit  = strpos($orig_lower, $term) !== false
                 || strpos($norm_lower,  $term) !== false;

            if ($hit) {
                // Increment match counter for admin analytics
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET match_count = match_count + 1 WHERE term = %s",
                    $row['term']
                ));

                if ($row['action'] === 'block') {
                    return [
                        'valid'   => false,
                        'action'  => 'block',
                        'reason'  => 'profanity',
                        'message' => "That word isn't allowed in Super Strength cards. Please rephrase — think about what makes this person great!",
                    ];
                }

                // Flag for admin review
                return [
                    'valid'   => false,
                    'action'  => 'flag',
                    'reason'  => 'flag',
                    'matched' => $row['term'],
                    'message' => "This card is waiting for a quick check from your teacher or admin. Your other cards are saved — this one will appear once it's approved.",
                ];
            }
        }

        return null; // no match
    }

    // ── Step 2: PII regex patterns ────────────────────────────────────────────
    private static function check_pii($text) {
        $patterns = [
            // UK phone number
            ['/(\+?44|0)[\s\-]?[0-9]{4}[\s\-]?[0-9]{6}/', 'phone',
             "Phone numbers can't be shared in Super Strength cards."],
            // Email address
            ['/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', 'email',
             "Email addresses can't be shared here."],
            // URLs and social handles
            ['/(https?:\/\/|www\.|instagram|snapchat|tiktok|discord)/i', 'url',
             "Links and social handles can't be shared here."],
            // UK postcode
            ['/[A-Z]{1,2}[0-9][0-9A-Z]?\s?[0-9][A-Z]{2}/i', 'postcode',
             "Personal information like postcodes can't be shared here."],
        ];

        foreach ($patterns as [$pattern, $type, $specific]) {
            if (preg_match($pattern, $text)) {
                return [
                    'valid'    => false,
                    'action'   => 'block',
                    'reason'   => 'pii',
                    'pii_type' => $type,
                    'message'  => "Personal information can't be shared in Super Strength cards. " . $specific . " Please write about a strength instead.",
                ];
            }
        }

        return null;
    }
}
