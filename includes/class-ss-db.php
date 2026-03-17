<?php
/**
 * MFSD Super Strengths — Database layer
 * Table definitions, install/upgrade, seed data
 */

if (!defined('ABSPATH')) exit;

class MFSD_SS_DB {

    // Fixed per spec: each player writes exactly 5 cards per target
    const CARDS_PER_TARGET = 5;

    // Table name slugs (without wpdb prefix)
    const TBL_GAMES     = 'mfsd_ss_games';
    const TBL_PLAYERS   = 'mfsd_ss_players';
    const TBL_CARDS     = 'mfsd_ss_cards';
    const TBL_TURNS     = 'mfsd_ss_turns';
    const TBL_VOTES     = 'mfsd_ss_votes';
    const TBL_STRENGTHS = 'mfsd_ss_strengths';
    const TBL_BANNED    = 'mfsd_ss_banned_terms';
    const TBL_FLAGS     = 'mfsd_ss_flagged';

    /**
     * Called on plugin activation.
     */
    public static function install() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $g  = $wpdb->prefix . self::TBL_GAMES;
        $p  = $wpdb->prefix . self::TBL_PLAYERS;
        $ca = $wpdb->prefix . self::TBL_CARDS;
        $t  = $wpdb->prefix . self::TBL_TURNS;
        $v  = $wpdb->prefix . self::TBL_VOTES;
        $s  = $wpdb->prefix . self::TBL_STRENGTHS;
        $b  = $wpdb->prefix . self::TBL_BANNED;
        $fl = $wpdb->prefix . self::TBL_FLAGS;

        // Games —  one row per game session
        dbDelta("CREATE TABLE $g (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            status              ENUM('submission','dealing','playing','complete') NOT NULL DEFAULT 'submission',
            mode                ENUM('short','full') NOT NULL DEFAULT 'full',
            round_limit         INT NOT NULL DEFAULT 3,
            turn_timeout_hours  INT NOT NULL DEFAULT 24,
            vote_timeout_hours  INT NOT NULL DEFAULT 24,
            current_turn_id     BIGINT UNSIGNED NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $c;");

        // Players — one row per user per game
        dbDelta("CREATE TABLE $p (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            game_id           BIGINT UNSIGNED NOT NULL,
            user_id           BIGINT UNSIGNED NOT NULL,
            display_name      VARCHAR(100) NOT NULL,
            role              ENUM('student','parent','carer','sibling','other') NOT NULL DEFAULT 'parent',
            score_total       INT NOT NULL DEFAULT 0,
            confidence_tokens INT NOT NULL DEFAULT 2,
            submission_status ENUM('pending','submitted') NOT NULL DEFAULT 'pending',
            submitted_at      DATETIME NULL,
            turn_order        INT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_game_user (game_id, user_id),
            KEY idx_game (game_id),
            KEY idx_user (user_id)
        ) $c;");

        // Cards — one row per submitted Super Strength card
        dbDelta("CREATE TABLE $ca (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            game_id             BIGINT UNSIGNED NOT NULL,
            author_player_id    BIGINT UNSIGNED NOT NULL,
            target_player_id    BIGINT UNSIGNED NOT NULL,
            strength_id         BIGINT UNSIGNED NULL COMMENT 'NULL if free-text',
            strength_text       VARCHAR(100) NOT NULL,
            is_free_text        TINYINT(1) NOT NULL DEFAULT 0,
            flagged             TINYINT(1) NOT NULL DEFAULT 0,
            dealt_to_player_id  BIGINT UNSIGNED NULL,
            played              TINYINT(1) NOT NULL DEFAULT 0,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_game   (game_id),
            KEY idx_author (author_player_id),
            KEY idx_target (target_player_id),
            KEY idx_dealt  (dealt_to_player_id)
        ) $c;");

        // Turns — one row per card play
        dbDelta("CREATE TABLE $t (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            game_id                BIGINT UNSIGNED NOT NULL,
            turn_number            INT NOT NULL,
            card_id                BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 until player chooses',
            played_by_player_id    BIGINT UNSIGNED NOT NULL,
            phase                  ENUM('A','B','complete') NOT NULL DEFAULT 'A',
            phase_a_reveal_at      DATETIME NULL,
            phase_b_reveal_at      DATETIME NULL,
            started_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at               DATETIME NULL,
            skipped_once           TINYINT(1) NOT NULL DEFAULT 0,
            round_winner_player_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY idx_game (game_id),
            KEY idx_card (card_id)
        ) $c;");

        // Votes — one row per player per phase per turn
        dbDelta("CREATE TABLE $v (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            turn_id             BIGINT UNSIGNED NOT NULL,
            game_id             BIGINT UNSIGNED NOT NULL,
            phase               ENUM('A','B') NOT NULL,
            voter_player_id     BIGINT UNSIGNED NOT NULL,
            selected_player_id  BIGINT UNSIGNED NOT NULL,
            is_confident        TINYINT(1) NOT NULL DEFAULT 0,
            is_correct          TINYINT(1) NULL,
            points_earned       INT NOT NULL DEFAULT 0,
            submitted_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_turn_voter_phase (turn_id, voter_player_id, phase),
            KEY idx_turn (turn_id),
            KEY idx_game (game_id)
        ) $c;");

        // Strengths — admin-managed word list
        dbDelta("CREATE TABLE $s (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            strength_text VARCHAR(100) NOT NULL,
            category      VARCHAR(60) NOT NULL DEFAULT 'Character',
            active        TINYINT(1) NOT NULL DEFAULT 1,
            times_used    INT NOT NULL DEFAULT 0,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_category (category),
            KEY idx_active   (active)
        ) $c;");

        // Banned terms — profanity, violence, sexual, self-harm, custom
        dbDelta("CREATE TABLE $b (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            term        VARCHAR(100) NOT NULL,
            category    ENUM('profanity','violence','sexual','self_harm','custom') NOT NULL DEFAULT 'profanity',
            action      ENUM('block','flag') NOT NULL DEFAULT 'block',
            match_count INT NOT NULL DEFAULT 0,
            active      TINYINT(1) NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_category (category),
            KEY idx_active   (active)
        ) $c;");

        // Flagged free-text submissions awaiting admin review
        dbDelta("CREATE TABLE $fl (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            game_id           BIGINT UNSIGNED NOT NULL,
            player_id         BIGINT UNSIGNED NOT NULL,
            target_player_id  BIGINT UNSIGNED NOT NULL,
            submitted_text    VARCHAR(200) NOT NULL,
            matched_rule      VARCHAR(100) NOT NULL,
            status            ENUM('pending','allowed','rejected') NOT NULL DEFAULT 'pending',
            reviewed_by       BIGINT UNSIGNED NULL,
            reviewed_at       DATETIME NULL,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_game   (game_id)
        ) $c;");

        // Seed tables if empty
        $count_s = (int) $wpdb->get_var("SELECT COUNT(*) FROM $s");
        if ($count_s === 0) self::seed_strengths();

        $count_b = (int) $wpdb->get_var("SELECT COUNT(*) FROM $b");
        if ($count_b === 0) self::seed_banned_terms();

        // Default plugin options
        add_option('mfsd_ss_mode',              'full');
        add_option('mfsd_ss_round_limit',        3);
        add_option('mfsd_ss_turn_timeout',       24);
        add_option('mfsd_ss_vote_timeout',       24);
        add_option('mfsd_ss_free_text_11_12',   '0');
        add_option('mfsd_ss_free_text_13_14',   '1');
        add_option('mfsd_ss_free_text_max',      2);
        add_option('mfsd_ss_free_text_min_len',  3);
        add_option('mfsd_ss_free_text_max_len',  40);
        add_option('mfsd_ss_course_management', '1');
    }

    // ── Seed: all 100 Super Strengths from Appendix D ────────────────────────
    public static function seed_strengths() {
        global $wpdb;
        $t = $wpdb->prefix . self::TBL_STRENGTHS;

        $strengths = [
            // Character (15)
            ['Resilient','Character'],         ['Determined','Character'],
            ['Honest','Character'],            ['Brave','Character'],
            ['Patient','Character'],           ['Calm','Character'],
            ['Humble','Character'],            ['Consistent','Character'],
            ['Reliable','Character'],          ['Fearless','Character'],
            ['Authentic','Character'],         ['Principled','Character'],
            ['Grounded','Character'],          ['Self-aware','Character'],
            ['Courageous','Character'],

            // Social & Caring (15)
            ['Kind','Social & Caring'],            ['Generous','Social & Caring'],
            ['Loyal','Social & Caring'],           ['Supportive','Social & Caring'],
            ['Encouraging','Social & Caring'],     ['Warm','Social & Caring'],
            ['Forgiving','Social & Caring'],       ['Compassionate','Social & Caring'],
            ['Empathetic','Social & Caring'],      ['Inclusive','Social & Caring'],
            ['Good listener','Social & Caring'],
            ['Makes people feel welcome','Social & Caring'],
            ['Stands up for others','Social & Caring'],
            ['Sees the best in people','Social & Caring'],
            ['Always there for you','Social & Caring'],

            // Creative & Expressive (12)
            ['Creative','Creative & Expressive'],
            ['Imaginative','Creative & Expressive'],
            ['Artistic','Creative & Expressive'],
            ['Inventive','Creative & Expressive'],
            ['Original','Creative & Expressive'],
            ['Curious','Creative & Expressive'],
            ['Playful','Creative & Expressive'],
            ['Witty','Creative & Expressive'],
            ['Storyteller','Creative & Expressive'],
            ['Thinks outside the box','Creative & Expressive'],
            ['Sees things differently','Creative & Expressive'],
            ['Brings ideas to life','Creative & Expressive'],

            // Mind & Learning (13)
            ['Focused','Mind & Learning'],         ['Organised','Mind & Learning'],
            ['Hardworking','Mind & Learning'],      ['Thorough','Mind & Learning'],
            ['Analytical','Mind & Learning'],       ['Wise','Mind & Learning'],
            ['Quick learner','Mind & Learning'],    ['Open-minded','Mind & Learning'],
            ['Perceptive','Mind & Learning'],       ['Problem solver','Mind & Learning'],
            ['Asks great questions','Mind & Learning'],
            ['Never gives up on a challenge','Mind & Learning'],
            ['Pays attention to detail','Mind & Learning'],

            // Leadership & Drive (12)
            ['Confident','Leadership & Drive'],    ['Ambitious','Leadership & Drive'],
            ['Motivated','Leadership & Drive'],    ['Inspiring','Leadership & Drive'],
            ['Decisive','Leadership & Drive'],     ['Responsible','Leadership & Drive'],
            ['Adaptable','Leadership & Drive'],    ['Proactive','Leadership & Drive'],
            ['Natural leader','Leadership & Drive'],
            ['Leads by example','Leadership & Drive'],
            ['Makes things happen','Leadership & Drive'],
            ['Brings out the best in others','Leadership & Drive'],

            // Practical & Dependable (12)
            ['Practical','Practical & Dependable'],
            ['Resourceful','Practical & Dependable'],
            ['Punctual','Practical & Dependable'],
            ['Careful','Practical & Dependable'],
            ['Observant','Practical & Dependable'],
            ['Prepared','Practical & Dependable'],
            ['Dedicated','Practical & Dependable'],
            ['Skilled','Practical & Dependable'],
            ['Talented','Practical & Dependable'],
            ['Gets things done','Practical & Dependable'],
            ['You can count on them','Practical & Dependable'],
            ['Always follows through','Practical & Dependable'],

            // Growth & Mindset (9)
            ['Reflective','Growth & Mindset'],
            ['Improving every day','Growth & Mindset'],
            ['Embraces a challenge','Growth & Mindset'],
            ['Learns from mistakes','Growth & Mindset'],
            ['Keeps going','Growth & Mindset'],
            ['Positive mindset','Growth & Mindset'],
            ['Solution focused','Growth & Mindset'],
            ['Sees the bigger picture','Growth & Mindset'],
            ['Turns setbacks into comebacks','Growth & Mindset'],

            // Family (12)
            ['Loving','Family'],
            ['Funny','Family'],
            ['Makes you laugh','Family'],
            ['Protective','Family'],
            ['Present','Family'],
            ['Strong','Family'],
            ['Home is better with them in it','Family'],
            ['Makes you feel safe','Family'],
            ['Gives great advice','Family'],
            ['Always in your corner','Family'],
            ['Never judges you','Family'],
            ['Shows up when it matters','Family'],
        ];

        foreach ($strengths as [$text, $cat]) {
            $wpdb->insert($t, [
                'strength_text' => $text,
                'category'      => $cat,
                'active'        => 1,
            ]);
        }
    }

    // ── Seed: Appendix E banned terms ────────────────────────────────────────
    public static function seed_banned_terms() {
        global $wpdb;
        $t = $wpdb->prefix . self::TBL_BANNED;

        $terms = [
            // Profanity — block
            ['fuck','profanity','block'],      ['shit','profanity','block'],
            ['cunt','profanity','block'],      ['bitch','profanity','block'],
            ['dick','profanity','block'],      ['cock','profanity','block'],
            ['pussy','profanity','block'],     ['arse','profanity','block'],
            ['ass','profanity','block'],       ['bollocks','profanity','block'],
            ['wanker','profanity','block'],    ['twat','profanity','block'],
            ['prick','profanity','block'],     ['whore','profanity','block'],
            ['slut','profanity','block'],      ['slag','profanity','block'],
            ['tosser','profanity','block'],    ['bellend','profanity','block'],
            ['knobhead','profanity','block'],  ['arsehole','profanity','block'],
            ['asshole','profanity','block'],   ['motherfucker','profanity','block'],
            ['dickhead','profanity','block'],  ['fuckwit','profanity','block'],
            ['fuk','profanity','block'],       ['phuck','profanity','block'],
            ['fcuk','profanity','block'],      ['biatch','profanity','block'],
            ['kunt','profanity','block'],      ['feck','profanity','block'],
            // Context-dependent — flag for review
            ['bastard','profanity','flag'],    ['tosspot','profanity','flag'],
            // Violence — block
            ['kill','violence','block'],       ['murder','violence','block'],
            ['stab','violence','block'],       ['shoot','violence','block'],
            ['want you dead','violence','block'],
            ['hate you','violence','flag'],
            // Sexual — block / flag
            ['naked','sexual','block'],        ['nude','sexual','block'],
            ['penis','sexual','block'],        ['vagina','sexual','block'],
            ['porn','sexual','block'],         ['rape','sexual','block'],
            ['molest','sexual','block'],
            ['sex','sexual','flag'],
            // Self-harm — flag (not hard block so sensitive support can reach admin)
            ['self-harm','self_harm','flag'],
            ['suicidal','self_harm','flag'],
            ['want to die','self_harm','flag'],
            ['kill myself','self_harm','flag'],
        ];

        foreach ($terms as [$term, $cat, $action]) {
            $wpdb->insert($t, [
                'term'     => $term,
                'category' => $cat,
                'action'   => $action,
                'active'   => 1,
            ]);
        }
    }

    // ── Helper: full table name with prefix ──────────────────────────────────
    public static function table($slug) {
        global $wpdb;
        return $wpdb->prefix . constant('self::TBL_' . strtoupper($slug));
    }
}