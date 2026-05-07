# MFSD Super Strengths Cards — Technical Specification v1.0

**Plugin directory:** `mfsd-super-strengths-v2/`
**Shortcode(s):** `[mfsd_super_strengths]`
**Version:** 4.6.0
**Author:** MisterT9007
**Purpose:** A family card game played between a student and their linked parents/carers. Each player writes five "Super Strength" cards about every other player, then the group plays a guessing game (Full or Short mode) or a real-time Snap game. The guessing game reveals strengths progressively across two phases with a scoring system including Confidence Tokens; Snap is a live reaction game requiring all players to be online simultaneously. On completion the student's course task status is marked complete via the `mfsd-ordering` integration.

---

## File Structure

```
mfsd-super-strengths-v2/
├── mfsd-super-strengths.php          Bootstrap, singleton, shortcode, cron
├── admin/
│   └── admin-page.php                Tabbed WP admin UI
├── includes/
│   ├── class-ss-db.php               Table definitions, install, seed data
│   ├── class-ss-validator.php        Free-text content moderation pipeline
│   ├── class-ss-game.php             Game engine (dealing, turns, scoring, snap)
│   └── class-ss-api.php              REST API — 17 routes
├── assets/
│   ├── mfsd-super-strengths.css      Theme-aware styles (Gamer + Corporate)
│   └── mfsd-super-strengths.js       Vanilla-JS frontend state machine
└── techspecs/
    └── SuperStrengths_TechSpec_v1.0.md
```

---

## Database Schema

All tables are created via `dbDelta()` in `MFSD_SS_DB::install()`. The constant `CARDS_PER_TARGET = 5` is used throughout.

### `wp_mfsd_ss_games`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| status | ENUM('submission','dealing','playing','complete') | Default 'submission' |
| mode | ENUM('short','full','snap') | Default 'full' |
| round_limit | INT | Used for ≥5 player games |
| turn_timeout_hours | INT | Default 24 |
| vote_timeout_hours | INT | Default 24 |
| current_turn_id | BIGINT UNSIGNED NULL | FK to turns |
| created_at / updated_at | DATETIME | |

### `wp_mfsd_ss_players`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| game_id | BIGINT UNSIGNED | |
| user_id | BIGINT UNSIGNED | WP user ID |
| display_name | VARCHAR(100) | |
| role | ENUM('student','parent','carer','sibling','other') | |
| score_total | INT | Default 0 |
| confidence_tokens | INT | Default 2 |
| submission_status | ENUM('pending','submitted') | |
| submitted_at | DATETIME NULL | |
| turn_order | INT NULL | Set during deal_cards() |

### `wp_mfsd_ss_cards`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| game_id | BIGINT UNSIGNED | |
| author_player_id | BIGINT UNSIGNED | Who wrote it |
| target_player_id | BIGINT UNSIGNED | Who it describes |
| strength_id | BIGINT UNSIGNED NULL | NULL for free-text |
| strength_text | VARCHAR(100) | |
| is_free_text | TINYINT(1) | 0 = list, 1 = free |
| flagged | TINYINT(1) | 0 = clean, 1 = pending moderation |
| dealt_to_player_id | BIGINT UNSIGNED NULL | Set during deal |
| played | TINYINT(1) | Default 0 |
| created_at | DATETIME | |

### `wp_mfsd_ss_turns`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| game_id | BIGINT UNSIGNED | |
| turn_number | INT | |
| card_id | BIGINT UNSIGNED | 0 = not yet played |
| played_by_player_id | BIGINT UNSIGNED | |
| phase | ENUM('A','B','complete') | |
| phase_a_reveal_at | DATETIME NULL | Timeout for Phase A |
| phase_b_reveal_at | DATETIME NULL | Timeout for Phase B |
| started_at | DATETIME | |
| ended_at | DATETIME NULL | |
| skipped_once | TINYINT(1) | Timeout skip guard |
| round_winner_player_id | BIGINT UNSIGNED NULL | |

### `wp_mfsd_ss_votes`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| turn_id / game_id | BIGINT UNSIGNED | |
| phase | ENUM('A','B') | |
| voter_player_id | BIGINT UNSIGNED | |
| selected_player_id | BIGINT UNSIGNED | Guessed player |
| is_confident | TINYINT(1) | Confidence Token used |
| is_correct | TINYINT(1) NULL | Set on reveal |
| points_earned | INT | |
| submitted_at | DATETIME | |
| UNIQUE | (turn_id, voter_player_id, phase) | One vote per phase |

### `wp_mfsd_ss_strengths`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| strength_text | VARCHAR(100) | |
| category | VARCHAR(60) | 8 categories (see Seed Data) |
| active | TINYINT(1) | Default 1 |
| times_used | INT | Incremented on submission/save |

### `wp_mfsd_ss_banned_terms`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| term | VARCHAR(100) | |
| category | ENUM('profanity','violence','sexual','self_harm','custom') | |
| action | ENUM('block','flag') | |
| match_count | INT | Analytics counter |
| active | TINYINT(1) | |

### `wp_mfsd_ss_flagged`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| game_id / player_id / target_player_id | BIGINT UNSIGNED | |
| submitted_text | VARCHAR(200) | |
| matched_rule | VARCHAR(100) | |
| status | ENUM('pending','allowed','rejected') | |
| reviewed_by | BIGINT UNSIGNED NULL | Admin WP user ID |
| reviewed_at | DATETIME NULL | |

### Snap Tables

**`wp_mfsd_ss_snap_sessions`** — One session per snap game.
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| game_id | BIGINT UNSIGNED | |
| status | ENUM('waiting','countdown','playing','snap_active','tiebreaker','complete') | |
| snap_mode | ENUM('quick_draw','until_death') | |
| quick_draw_target | INT | Default 5 |
| snap_timer_seconds | INT | Default 3 |
| current_turn_player_id | BIGINT UNSIGNED NULL | |
| pile | LONGTEXT NULL | JSON array, index 0 = bottom |
| snap_x / snap_y | DECIMAL(5,2) NULL | Bullseye position as % |
| snap_expires_at | DATETIME NULL | UTC |
| countdown_ends_at | DATETIME NULL | UTC |
| total_snaps_won | INT | |
| last_snap_winner_id / winner_player_id | BIGINT UNSIGNED NULL | |

**`wp_mfsd_ss_snap_hands`** — One row per player per session.
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| session_id | BIGINT UNSIGNED | |
| player_id | BIGINT UNSIGNED | |
| cards | LONGTEXT | JSON ordered hand; index 0 = next to play |
| snap_score | INT | Default 0 |
| is_present | TINYINT(1) | Set on /snap/join |
| joined_at | DATETIME NULL | |

**`wp_mfsd_ss_snap_claims`** — Millisecond-precision audit log for snap claims.
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| session_id | BIGINT UNSIGNED | |
| player_id | BIGINT UNSIGNED | |
| claimed_at | DATETIME | |
| won | TINYINT(1) | |

### Seed Data

**100 Strengths** across 8 categories:
- Character (15): Resilient, Determined, Honest, Brave, Patient, Calm, Humble, Consistent, Reliable, Fearless, Authentic, Principled, Grounded, Self-aware, Courageous
- Social & Caring (15): Kind, Generous, Loyal, Supportive, Encouraging, Warm, Forgiving, Compassionate, Empathetic, Inclusive, Good listener, Makes people feel welcome, Stands up for others, Sees the best in people, Always there for you
- Creative & Expressive (12): Creative, Imaginative, Artistic, Inventive, Original, Curious, Playful, Witty, Storyteller, Thinks outside the box, Sees things differently, Brings ideas to life
- Mind & Learning (13): Focused, Organised, Hardworking, Thorough, Analytical, Wise, Quick learner, Open-minded, Perceptive, Problem solver, Asks great questions, Never gives up on a challenge, Pays attention to detail
- Leadership & Drive (12): Confident, Ambitious, Motivated, Inspiring, Decisive, Responsible, Adaptable, Proactive, Natural leader, Leads by example, Makes things happen, Brings out the best in others
- Practical & Dependable (12): Practical, Resourceful, Punctual, Careful, Observant, Prepared, Dedicated, Skilled, Talented, Gets things done, You can count on them, Always follows through
- Growth & Mindset (9): Reflective, Improving every day, Embraces a challenge, Learns from mistakes, Keeps going, Positive mindset, Solution focused, Sees the bigger picture, Turns setbacks into comebacks
- Family (12): Loving, Funny, Makes you laugh, Protective, Present, Strong, Home is better with them in it, Makes you feel safe, Gives great advice, Always in your corner, Never judges you, Shows up when it matters

**~50 Banned Terms** across categories: profanity (block/flag), violence (block/flag), sexual (block/flag), self-harm (flag).

### Default `wp_options` Values
| Option | Default |
|---|---|
| mfsd_ss_mode | 'full' |
| mfsd_ss_round_limit | 3 |
| mfsd_ss_turn_timeout | 24 (hours) |
| mfsd_ss_vote_timeout | 24 (hours) |
| mfsd_ss_free_text_11_12 | '0' |
| mfsd_ss_free_text_13_14 | '1' |
| mfsd_ss_free_text_max | 2 |
| mfsd_ss_free_text_min_len | 3 |
| mfsd_ss_free_text_max_len | 40 |
| mfsd_ss_snap_mode | 'quick_draw' |
| mfsd_ss_snap_quick_draw_target | 5 |
| mfsd_ss_snap_timer | 3 |

---

## Game / Assessment Flow

### Guessing Game (Full / Short modes)

```
[Student] Game Not Started
    → Student clicks "Start Game"
    → POST /game/start  (parents auto-added from wp_mfsd_parent_student_links)
    → game.status = 'submission'

[All players] Submission Phase
    → Each player writes 5 cards for every other player (CARDS_PER_TARGET = 5)
    → Cards auto-saved via POST /submission/save (can be re-saved per target)
    → Free-text validated client-side (length) and server-side (Validator pipeline)
    → POST /submission/submit  marks player 'submitted'
    → When ALL submitted → MFSD_SS_Game::deal_cards() is called

[Server] Dealing Phase
    → deal_cards():
        - Fetches all unflagged cards, shuffles
        - Student plays first; other players shuffled randomly
        - Sets turn_order on each player row
        - hand_size = (n-1) × 5 cards per player
        - Assigns dealt_to_player_id on each card
        - Sets game.status = 'playing'
        - Calls create_next_turn()
    → game.status = 'playing'

[All players] Playing Phase — repeating turns
    → create_next_turn(): Round-robin by turn_order
    → Card player picks a card from hand → POST /game/play → sets card_id, phase='A', phase_a_reveal_at

    Phase A — "Who is this card about?"
        → All players except card player vote → POST /game/vote
        → When votes_in >= n-1  OR  phase_a_reveal_at reached (cron):
            → process_reveal(phase='A'):
                - Correct guess: +2 pts; confident+correct: +3 more; confident+wrong: -3 (card player gets +3)
                - Round winner (most points this round): +1 bonus
        → Full mode: phase transitions to 'B'; Short mode: turn ends

    Phase B — "Who WROTE this card?" (Full mode only)
        → All players vote → POST /game/vote
        → When votes_in >= n  OR  phase_b_reveal_at reached (cron):
            → process_reveal(phase='B') with same scoring
            → Turn ends: create_next_turn()

[Server] Game Complete
    → check_game_complete(): All dealt cards played → game.status = 'complete'
    → notify_task_complete() → mfsd_set_task_status(student_user_id, 'super_strengths', 'completed')

[Student] Final Results
    → GET /game/results → scores, received cards, Steve AI summary
```

### Snap Game

```
[All players submit] → MFSD_SS_Game::init_snap_session()
    → Duplicates entire card pool (each card appears exactly twice)
    → Shuffles pool; deals (n-1)×10 cards per player
    → Creates snap_session with status='waiting', student plays first
    → game.status = 'playing'

[All players] Waiting Room
    → Each player lands on page → POST /snap/join marks is_present=1
    → When all present → status='countdown', countdown_ends_at = now+3s

[All players] 3-2-1 Countdown
    → Client counts down from countdown_ends_at (UTC)
    → When expired → lazy transition: status='countdown' → 'playing' on next GET /snap/session

[All players] Playing Phase (polls at 500ms)
    → Current player's turn → POST /snap/play-card
        - Removes top card from hand
        - Appends to pile
        - Checks: if pile[-1].strength_text === pile[-2].strength_text → SNAP triggered
            → Sets status='snap_active', random snap_x/snap_y (min 20% distance from last), snap_expires_at=now+timer
        - Else: advances current_turn_player_id (skips players with empty hands)
        - check_snap_reshuffle(): if all hands empty, reshuffles pile back to hands

[Any player] Snap Claim
    → Bullseye appears at random server-specified position
    → POST /snap/claim — DB transaction with FOR UPDATE
        - SELECT session FOR UPDATE
        - Checks expiry (1-second grace)
        - Inserts claim record; sets status='snap_claimed' (blocks concurrent claims)
        - COMMIT
        - process_snap_win():
            - Removes matched pair (top 2) from pile permanently
            - Remaining pile cards → shuffled onto winner's hand
            - Increments snap_score
            - Clears claims table for next snap
            - Sets pile=[], winner plays next
            - check_snap_complete(): quick_draw ≥ target OR until_death all cards gone

[Snap Expiry — no claim]
    → Lazy: GET /snap/session fires expire_snap()
    → Pile stays; next player in rotation continues
    → 1-second grace prevents race with valid in-flight claim

[Game Complete — tie]
    → Tiebreaker: 5s bullseye, first to claim wins
    → If tiebreaker expires: winner by snap_score then player_id ASC

[Notification]
    → notify_task_complete() → mfsd_set_task_status(student_user_id, 'super_strengths', 'completed')

[Results Screen]
    → renderSnapComplete() fetches GET /game/summary
    → Groups all cards by target; shows Steve AI analysis (students only)
```

---

## Key Flows

### Free-Text Validation Pipeline (`MFSD_SS_Validator::validate`)

1. **Normalise leetspeak** — maps `4→a`, `@→a`, `3→e`, `1→i`, `!→i`, `|→i`, `0→o`, `$→s`, `5→s`, `7→t`, `ph→f`, `ck→k`, strips non-alpha/space characters
2. **Check banned terms** — queries `wp_mfsd_ss_banned_terms`; checks both original and normalised text; increments `match_count`; returns `block` or `flag` result
3. **PII regex patterns** — checks for UK phone number, email address, URLs / social handles (instagram/snapchat/tiktok/discord), UK postcode — always returns `block`
4. Returns `{'valid': true, 'action': 'allow'}` if all steps pass

Return shape: `{ valid, action (allow|block|flag), reason, message, [matched], [pii_type] }`

### Confidence Token Mechanic

- Each player starts with 2 tokens per game
- On any vote, player may toggle "Use Confidence Token"
- If confident + correct: voter gains +3 extra (total +5)
- If confident + wrong: voter loses 3 pts; card player gains 3 pts
- `is_confident` flag deducted from player.confidence_tokens at time of vote submission

### Turn Timeout (Hourly Cron: `mfsd_ss_timeout_check`)

For each active non-snap game:
- If `card_id = 0` and `started_at + turn_timeout_hours` has passed:
  - First offence: skip turn (`skipped_once=1`), advance to next player
  - Second offence: penalty `-3 × (n-1)` to player, `+3` to all others
- If Phase A reveal timeout (`phase_a_reveal_at`): force `process_reveal(phase='A')`
- If Phase B reveal timeout (`phase_b_reveal_at`): force `process_reveal(phase='B')`

### Parent Polling

- Parents with no active game see a waiting screen and poll `GET /state` every 15 seconds
- When game appears, they are routed directly to the submission screen

### Snap Bullseye Anti-Cheat

The random position is generated server-side by `random_position()` with a minimum distance of 20% from the last position. The moving target replaces any button-type restriction — both left-click and right-click (and `contextmenu`) trigger `claimSnap()`. The `snapClaiming` guard flag prevents double-fire. The server-side DB transaction (`FOR UPDATE`) ensures exactly one winner regardless of network timing.

---

## AJAX / REST Endpoints

**Namespace:** `mfsd-ss/v1`
**Authentication:** `is_user_logged_in()` (all routes); `current_user_can('manage_options')` (admin route only)
**Nonce:** `X-WP-Nonce` header, created via `wp_create_nonce('wp_rest')`, localized as `cfg.nonce`

| Method | Route | Description |
|---|---|---|
| GET | `/state` | Full game state for current user. Returns `no_game` (with viewer_role and linked players) or active game state including hand if playing. Checks completed games first. |
| POST | `/game/start` | Student creates a new game. Auto-adds all linked active parents from `wp_mfsd_parent_student_links`. Validates no existing active game. Returns state response. |
| GET | `/strengths` | Returns all active strengths grouped by category. |
| POST | `/validate-text` | Runs `MFSD_SS_Validator::validate()` on submitted `text`. Returns `{ok, result}`. |
| POST | `/submission/save` | Saves up to 5 cards for one target player. Deletes existing cards for that target first. Validates free-text. Increments `times_used` on strength record. |
| POST | `/submission/submit` | Marks player submitted. Validates 5 cards per target exist. When all players submitted: calls `deal_cards()` or `init_snap_session()` based on game mode. |
| GET | `/game/hand` | Returns player's dealt hand with target names. |
| POST | `/game/play` | Plays a selected card. Sets `card_id`, `phase='A'`, and `phase_a_reveal_at` on the turn. |
| GET | `/game/turn` | Full turn state. Triggers `process_reveal()` lazily if all votes are in. Returns card, votes, reveal data, scores. Phase B reveal omits target/author until respective phase. |
| POST | `/game/vote` | Records phase A or B vote. Updates confidence token count. Allows vote revision (upsert). Card player cannot vote in Phase A. |
| GET | `/game/results` | Final scores + received cards + Steve AI summary via `generate_strengths_summary()`. |
| POST | `/snap/join` | Marks player as present. When all present, sets `status='countdown'` with 3s timer. |
| GET | `/snap/session` | Polled at 500ms. Lazy transitions: countdown→playing, snap_expired→resume. Returns full session state including pile top/second, bullseye position, all player hands. |
| POST | `/snap/play-card` | Takes top card from hand, adds to pile. Triggers `snap_active` with random bullseye position if match. Advances turn order otherwise. |
| POST | `/snap/claim` | DB transaction (`FOR UPDATE`). Atomic first-claim wins. Handles tiebreaker completion. Calls `process_snap_win()`. |
| GET | `/game/summary` | Post-game all cards grouped by target. Students sorted first (their own), parents get student first. Steve AI analysis for students only. |
| POST | `/admin/flag-review` | Admin only. `action_type=allow` unflag card; `action_type=reject` delete card. Updates flag record with reviewer and timestamp. |

---

## Admin Panel

Located at **WordPress Admin → Super Strengths** (dashicons-awards, position 28).

**Tab 1: Configuration**
- Game mode: full / short / snap
- Round limit (for ≥5 player games)
- Turn timeout hours / vote timeout hours
- Free-text settings: enable per age group (11-12, 13-14), max free-text cards, min/max character length
- Snap settings: snap mode (quick_draw/until_death), quick draw target, snap timer (1-10s)
- All saved via `update_option()`, validated with `check_admin_referer('mfsd_ss_config')`

**Tab 2: Games**
- List of all games with status, mode, player count, created date
- Create new game form (mode, round limit) → inserts game in submission phase
- Add player to existing game (WP user lookup, role selector) — blocked if game is not in submission phase, max 6 players
- Reset game (moves to submission, clears turns/votes/dealt cards)
- Delete game (removes all related records)

**Tab 3: Strength List**
- Lists all 100+ strengths with category, active status, usage count
- Toggle active/inactive
- Add new strength (text + category)
- Delete strength

**Tab 4: Banned Terms**
- Lists all terms with category, action, match count
- Add new term (text, category, action)
- Toggle active/inactive
- Delete term

**Tab 5: Flagged Submissions**
- Lists pending flagged free-text submissions with game ID, player, target, text, matched rule
- Allow: removes `flagged=1` from card, sets flag record to 'allowed'
- Reject: deletes card entirely, sets flag record to 'rejected'

---

## SteveGPT Integration

**Method used:** Legacy `$GLOBALS['mwai']->simpleTextQuery($prompt)` with `try/catch`.

**Called from two places:**

### `MFSD_SS_Game::generate_strengths_summary()` — Guessing game results
Called from `GET /game/results`. Prompt instructs a "warm, encouraging coach" voice speaking directly to the student (aged 11-14). Lists all received strength cards, asks for 3-4 sentences celebrating themes and patterns, and suggesting one specific way to lean into a strength. Rules: address student as "you/your", UK English, warm, age-appropriate. Output must start with `**Steve says:**`.

### `MFSD_SS_API::game_summary()` — Snap/post-game summary
Called from `GET /game/summary`. Only invoked when `viewer_role === 'student'` and `$GLOBALS['mwai']` is set. Prompt uses Steve Sallis voice (Solutions Mindset author), lists strengths with attribution, asks for 3-4 warm sentences about what the strengths reveal and the student's potential. Output starts with `Steve says:`.

**Fallback:** If `$GLOBALS['mwai']` is not set or the call throws, returns `null` — the UI simply omits the Steve Says block.

---

## Assets

### `mfsd-super-strengths.css`
Registered with version `4.6.0`. Enqueued only when shortcode is present.

**Theme Architecture:** CSS custom properties scoped to `.ss-wrap`. Two themes:

**Gamer / Student (default):**
- Background: `#0A0E1A` (dark navy)
- Accent: `#00D4FF` (cyan) — borders, glows
- Accent2: `#9333EA` (purple) — CTA buttons
- Gold: `#F59E0B` / `#FFD000` — coins, headings
- Fonts: Exo 2 (headings), Nunito (body)

**Corporate (parent/teacher/admin):**
- Applied via `body.mfsd-role-parent .ss-wrap:not(.ss-snap-mode)` selector
- Background: `#111111`
- Single accent: `#C9A84C` (gold) — replaces cyan/purple
- Fonts: Playfair Display (headings), Lato (body), Montserrat (nav)
- Snap always stays Gamer (`:not(.ss-snap-mode)` guard) since all players are online together

**Key Components:**
- `.ss-playing-card` — centrepiece card with gold border, diamond suit icons via `::before`/`::after`
- `.ss-hand` — 5-column grid of face-down/face-up card slots
- `.ss-strength-chip` — rounded pill for strength selection; `.family` variant in gold
- `.ss-cat-tabs` — horizontal scroll tab bar for 8 categories
- `.ss-vote-grid` — auto-fill grid of player name vote options
- `.ss-confidence` — gold-bordered confidence token toggle
- `.ss-reveal-banner` — colour-coded notification banners (correct/wrong/info/gold)
- `.ss-snap-bullseye` — fixed-position container with outer/middle rings (pulsing) and inner circle (SNAP text + timer)
- `.ss-snap-card` — 110×150px snap pile cards; `.ss-snap-card-face-up` rotated +3deg right; `.ss-snap-card-behind` rotated -3deg left
- `.ss-snap-countdown-num` — 120px (90px mobile) pulsing countdown digit
- `.ss-snap-result-flash` — absolute-positioned win/lose flash animation (2s)
- `.ss-loading-overlay` — fixed full-screen spinner overlay

### `mfsd-super-strengths.js`
IIFE, vanilla JS, no framework. Registered with version `4.6.0`. Enqueued only when shortcode is present.

**Config object (`MFSD_SS_CFG`)** localized from PHP: `restUrl`, `nonce`, `userId`, `displayName`, `ftEnabled`, `ftMax`, `ftMinLen`, `ftMaxLen`, `cardsPerTarget`, `gameMode`, `snapMode`, `snapTimer`, `snapQuickDrawTarget`, `isMobileHint`, `badgesUrl`, `portalUrl`, `courseId`.

**State object:** `gameId`, `gameStatus`, `gameMode`, `player`, `allPlayers`, `strengths`, `draftCards` (keyed by target_player_id), `currentTarget`, `hand`, `currentTurn`, `selectedCard`, `myVote`, `isConfident`, `pollTimer`.

**Screen flow:**
1. `init()` → `GET /state` → `routeToScreen()`
2. `renderNoGame()` — student (can start) / parent (poll 15s) / fallback
3. `renderSubmissionIntro()` — rules, player list
4. `renderSubmissionOverview()` — target list with progress bars
5. `renderPickStrengths(target)` — 100 chips, category tabs, search, optional free-text
6. `renderReview()` — all cards, edit buttons, final submit
7. `renderSubmissionWaiting()` — player status, polls 6s
8. `renderDealing()` — polls 4s
9. `renderGameTable()` → `renderTurnView()` → branches to:
   - `renderPlayCardUI()` — hand display, card selection, play button
   - `renderPhaseAUI()` — card display, voting UI, vote lock
   - `renderPhaseBUI()` — Phase A reveal banner, Phase B voting
   - `renderTurnComplete()` — reveal summary, next turn button
10. `renderFinalResults()` — leaderboard, received cards, Steve Says block

**Snap screens:**
1. `renderSnapWaiting()` → join + poll 1.5s
2. `renderSnapCountdown(data)` → client countdown from `countdown_ends_at` UTC
3. `renderSnapGame()` → polls `refreshSnapGame` every 500ms; `updateSnapGame(data)` updates scoreboard, hands, pile, action area, bullseye
4. `renderSnapComplete(data)` → final scores + `GET /game/summary` for strengths reveal + Steve

**Polling strategy:**
- Guessing game waiting: `GET /game/turn` every 5s (or 4s during dealing)
- Snap: `GET /snap/session` every 500ms
- Parent no-game: `GET /state` every 15s
- Submission waiting: `GET /state` every 6s

**Utility functions:**
- `api(endpoint, method, body)` — fetch with nonce header, throws on non-OK
- `formatMarkdown(text)` — converts `**bold**`, `*italic*`, `\n` to HTML
- `escHtml(s)` — HTML entity escaping
- `roleEmoji(role)` — maps role string to emoji

---

## Security

- **Nonce authentication:** All REST routes check `is_user_logged_in()`. The `X-WP-Nonce` header (`wp_rest`) is set on every fetch call.
- **Admin routes:** `POST /admin/flag-review` requires `current_user_can('manage_options')`.
- **Admin UI:** All forms use `wp_nonce_field()` / `check_admin_referer()` with action-specific nonces.
- **Input sanitization:** All REST params use `(int)` casting for IDs, `sanitize_text_field()` for strings. All DB queries use `$wpdb->prepare()`.
- **Game ownership:** Every REST endpoint calls `self::get_player($game_id, $uid)` to verify the requesting user is in the game before any operation.
- **Free-text moderation:** `MFSD_SS_Validator::validate()` runs on every free-text save. Blocked text is silently dropped; flagged text is stored with `flagged=1` and requires admin review before appearing in the game.
- **Snap atomicity:** `POST /snap/claim` uses `START TRANSACTION` / `SELECT ... FOR UPDATE` / `COMMIT` to prevent race conditions — only one winner per snap event regardless of simultaneous claims.
- **Output escaping:** All dynamic content in JS uses `escHtml()` before rendering. PHP admin output uses `esc_html()`.
- **Submission validation:** `POST /submission/submit` verifies exactly `CARDS_PER_TARGET` non-flagged cards exist per target before accepting submission.

---

## Inter-Plugin Dependencies

| Plugin | Integration | Details |
|---|---|---|
| `mfsd-ordering` | Course gating + completion | Shortcode calls `mfsd_get_task_status($student_id, 'super_strengths')`. If `locked` → returns locked message. If `available` → sets `in_progress`. `notify_task_complete()` calls `mfsd_set_task_status($student_user_id, 'super_strengths', 'completed')`. |
| `mfsd-parent-portal` | Player linking | `GET /state`, `GET /state` (no_game), and `POST /game/start` all query `wp_mfsd_parent_student_links` (`parent_user_id`, `student_user_id`, `relationship_type`, `link_status='active'`). |
| `stevegtp` / `mwai` | AI summaries | Calls `$GLOBALS['mwai']->simpleTextQuery($prompt)`. SteveGPT is the expected provider. Used in `generate_strengths_summary()` and `game_summary()`. |
| `myfutureself-theme` | CSS variables | CSS properties fall back to theme tokens: `--color-bg-page`, `--color-accent`, `--font-heading`, `--font-body`, `--radius-lg`, etc. Body classes `mfsd-role-student/parent/teacher/admin` set by theme. |

**Upgrade note (from v1):** If upgrading from the original version, run: `ALTER TABLE wp_mfsd_ss_games MODIFY mode ENUM('short','full','snap') NOT NULL DEFAULT 'full';`

---

## Version History

| Version | Changes |
|---|---|
| 4.6.0 | Current version. Snap mode added (snap_sessions, snap_hands, snap_claims tables). Real-time bullseye claim system. Tiebreaker mechanic. `game/summary` endpoint. Post-game strength reveal for snap. |
| < 4.6.0 | Guessing game only (full and short modes). Free-text validator. Admin flag review. Hourly cron timeout check. |
