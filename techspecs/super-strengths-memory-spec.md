# MFSD Super Strengths Cards — v5.0 Design Document & Technical Specification

**Plugin:** `mfsd-super-strengths`  
**Version:** 5.5.10  
**Prepared for:** My Future Self Digital  
**Status:** Live — updated to reflect production state  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [What Changes from v4](#2-what-changes-from-v4)
3. [Game Flow — Full Phase Walkthrough](#3-game-flow--full-phase-walkthrough)
4. [Admin Settings](#4-admin-settings)
5. [Database Schema — New Tables](#5-database-schema--new-tables)
6. [REST API Endpoints](#6-rest-api-endpoints)
7. [File Structure](#7-file-structure)
8. [Frontend — Screen Map & State Machine](#8-frontend--screen-map--state-machine)
9. [Card Pool Logic](#9-card-pool-logic)
10. [Turn & Presence System](#10-turn--presence-system)
11. [Game End Conditions](#11-game-end-conditions)
12. [Summary Screen Design](#12-summary-screen-design)
13. [AI / SteveGPT Integration](#13-ai--stevegpt-integration)
14. [Solution Lens Cross-Reference](#14-solution-lens-cross-reference)
15. [Validator — Self-Strengths](#15-validator--self-strengths)
16. [Demo Mode — Single Player](#16-demo-mode--single-player)
17. [Responsive Screen Sizes & Layout Parameters](#17-responsive-screen-sizes--layout-parameters)
18. [Theme Integration — Student & Parent](#18-theme-integration--student--parent)
19. [Badges & Quest Log Integration](#19-badges--quest-log-integration)
20. [Migration Notes — Snap Mode](#20-migration-notes--snap-mode)
21. [Implementation Phases](#21-implementation-phases)

---

## 1. Executive Summary

Super Strengths Cards v5.0 replaces the real-time Snap game mode with a turn-based **Strength Memory** game. The core purpose of the activity — helping a student and their family recognise and celebrate each other's strengths — is unchanged. The delivery mechanism becomes calmer, more inclusive, and works across mixed age groups and schedules.

### Key changes at a glance

| Area | v4 (Snap) | v5 (Memory) |
|---|---|---|
| Game mechanic | Real-time bullseye click | Turn-based memory card matching |
| Self-reflection step | ✗ None | ✓ Each player writes 5 self-strengths first |
| Simultaneous play | Required | Optional (sync or async turns) |
| Turn pressure | 3-second snap window | Admin-set turn timeout (default 5 mins) |
| Summary — student | Basic tag cloud | Self vs family comparison + Steve AI |
| Summary — parent | Same as student | Additional tab: self vs what student wrote about them |
| SteveGPT chatbot | ✗ None | ✓ Embedded on summary screen |
| Solution Lens link | ✗ None | ✓ AI summary references prior Lens results |
| Snap tables | Active | Left dormant, not deleted |
| New tables | — | 7 clean new tables (`mfsd_sm_*`) |

---

## 2. What Changes from v4

### Files modified

| File | Change |
|---|---|
| `mfsd-super-strengths.php` | Version bump, new admin option registrations, new REST routes, remove snap cron |
| `includes/class-ss-db.php` | Add `install()` logic for 7 new tables; keep snap tables dormant |
| `includes/class-ss-api.php` | Full rewrite — all snap endpoints removed, new memory game endpoints added |
| `assets/mfsd-super-strengths.js` | Full rewrite — new screen state machine |
| `assets/mfsd-super-strengths.css` | Major additions — memory board, card flip, presence, summary tabs |
| `admin/admin-page.php` | New settings sections; snap settings section marked deprecated/hidden |

### Files added (new)

| File | Purpose |
|---|---|
| `includes/class-ss-memory.php` | Memory game engine: board init, flip logic, match detection, scoring, turn rotation |
| `includes/class-ss-summary.php` | Summary data builder, SteveGPT prompt construction, Solution Lens data fetcher |

### Files unchanged

| File | Notes |
|---|---|
| `includes/class-ss-validator.php` | Reused unchanged for self-strength free-text validation |
| `includes/class-ss-game.php` | Guessing game engine — untouched. Snap methods left dormant. |

---

## 3. Game Flow — Full Phase Walkthrough

### Phase 0 — Game Start (student only)
The student visits the Super Strengths page. If demo mode is enabled in admin and no family game is active, the demo option is offered first. The intro screen shows two buttons: **Play 1 player with Steve** (demo) and **Start Game** (family). If they have linked family members and click Start Game, the game record is created and parents are notified (presence-based — no emails at this stage).

### Phase 1 — Self-Strengths (all players, async)

**Each player independently:**
1. Sees a prompt: *"Before writing about others, tell us about yourself."*
2. Picks **5 strengths** from the existing card library that they feel describe themselves.
3. These are saved as `mfsd_sm_self_strengths` records tagged with their player ID.
4. Once submitted, they move automatically to Phase 2.

**Gate:** A player cannot enter Phase 2 without completing Phase 1.  
**Async:** Players complete Phase 1 in their own time. A player returning to the site re-enters wherever they left off.

### Phase 2 — Writing Cards for Others (all players, async)

**Each player independently:**
- Writes **5 strength cards for each other player** (e.g. Mum writes 5 for the student and 5 for Dad).
- Cards are picked from the card library, exactly as in v4.
- Free-text is available based on admin settings and age, validated by `MFSD_SS_Validator`.
- Cards are saved as `mfsd_sm_cards` records.

**Waiting:** Once a player submits all their Phase 2 cards, they see a waiting screen showing submission progress for all players. When everyone has submitted, the game automatically advances.

**Admin option** controls whether self-strength cards written in Phase 1 enter the matching pool (see §9).

### Phase 3 — Dealing (server-side, instant)

Once all players have submitted Phase 2:
- `MFSD_SS_Memory::deal_board()` is called server-side.
- Cards are duplicated to create matched pairs, shuffled, and written to `mfsd_sm_board` as numbered positions (0-indexed).
- Game status advances to `playing`.
- Turn order is set: student plays first, then others in the order they joined.

### Phase 4 — Playing (turn-based, sync or async)

**On each player's turn:**
1. All players see the board. Face-down cards are shown as plain backs. Previously matched cards stay face-up (greyed and non-clickable).
2. The active player flips **Card 1** (click/tap). It reveals face-up with the full card text: *"Mum thinks you are… resilient"*.
3. The active player flips **Card 2**. It reveals face-up.
4. **Match:** Both cards freeze face-up. A match celebration moment fires (animation + text reveal). The active player's score increments. **They get another turn immediately.**
5. **No match:** Both cards flip back face-down after a 1.5-second pause. Turn passes to the next player.
6. If a player's browser is not present when their turn begins, all players see a **"[Name] has gone away"** banner. The game waits for the admin-configured timeout before auto-advancing to the next player.

### Phase 5 — Game Complete

Triggered by the active game end condition (see §11). Game status set to `complete`.

### Phase 6 — Summary Screen (see §12)

All players are routed to the summary screen. Student and parent views differ (see §12).

---

## 4. Admin Settings

All stored as WordPress options. Section added to the existing Super Strengths admin page.

### 4.1 Submission Settings

| Option key | Label | Type | Default |
|---|---|---|---|
| `mfsd_ss_cards_per_target` | Cards per player | Number | 5 |
| `mfsd_ss_free_text_11_12` | Allow free-text (11–12) | Toggle | Off |
| `mfsd_ss_free_text_13_14` | Allow free-text (13–14) | Toggle | On |
| `mfsd_ss_free_text_max` | Max free-text cards | Number | 2 |
| `mfsd_ss_free_text_min_len` | Min free-text length | Number | 3 |
| `mfsd_ss_free_text_max_len` | Max free-text length | Number | 40 |

*(These already exist in v4 — no change.)*

### 4.2 Card Pool Settings *(new)*

| Option key | Label | Type | Default | Notes |
|---|---|---|---|---|
| `mfsd_ss_card_pool` | Card pool for matching game | Radio | `family_cards` | `all_cards` includes self-strengths; `family_cards` uses only cards written about others |

### 4.3 Game Mode Settings *(new)*

| Option key | Label | Type | Default | Notes |
|---|---|---|---|---|
| `mfsd_ss_memory_mode` | Game end condition | Radio | `first_to_x` | Options: `all_match`, `first_to_x`, `timed` |
| `mfsd_ss_memory_target_matches` | Matches to win (first_to_x) | Number | 5 | Active only when mode is `first_to_x` |
| `mfsd_ss_memory_time_limit` | Game time limit (minutes) | Number | 5 | Active only when mode is `timed` |

### 4.4 Turn Settings *(new)*

| Option key | Label | Type | Default | Notes |
|---|---|---|---|---|
| `mfsd_ss_turn_timeout_mins` | Turn timeout (minutes) | Number | 5 | If a player doesn't flip their first card within this window, turn auto-advances |
| `mfsd_ss_turn_warning_secs` | Warning shown before timeout (seconds) | Number | 60 | Countdown shown on screen when turn is about to expire |

### 4.5 SteveGPT Integration

Seven chatbot IDs stored as WP options — one per distinct AI job. All follow the `mfsd_stevegpt_map_ss_*` naming convention matching the rest of the platform.

| Option key | Job | Screen | Notes |
|---|---|---|---|
| `mfsd_stevegpt_map_ss_welcome_intro` | Welcome intro message | Intro screen | Mode-aware copy — family vs demo, end condition type |
| `mfsd_stevegpt_map_ss_welcome_chat` | Pre-game Q&A chatbot | Intro screen | Answers student questions before cards are written |
| `mfsd_stevegpt_map_ss_student_summary` | Family game — student summary | Summary screen | Self vs family comparison; Solution Lens cross-reference |
| `mfsd_stevegpt_map_ss_parent_summary` | Family game — parent summary | Summary screen | Adult tone; self vs student-wrote-about-parent comparison |
| `mfsd_stevegpt_map_ss_family_chat` | Family game — chat widget | Summary screen | Full session context injected dynamically |
| `mfsd_stevegpt_map_ss_demo_picker` | Demo mode — Steve's card picks | Demo flow | Structured JSON output; must return 5 picks + rationale |
| `mfsd_stevegpt_map_ss_demo_summary` | Demo mode — summary + chat | Demo summary screen | Summary paragraph + post-game Q&A |

---

## 5. Database Schema — New Tables

All tables use WordPress `$wpdb->prefix` (typically `wp_`). All new tables prefixed `mfsd_sm_` (Super Strengths Memory).

**Snap tables (`mfsd_ss_snap_*`) are left untouched — dormant but not dropped.**

---

### 5.1 `mfsd_sm_games`

The master game record. One row per game instance.

```sql
CREATE TABLE mfsd_sm_games (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_key           VARCHAR(64)     NOT NULL UNIQUE,   -- e.g. 'sm_42_1718000000'
  student_user_id    BIGINT UNSIGNED NOT NULL,
  status             VARCHAR(30)     NOT NULL DEFAULT 'submission_self',
                                     -- submission_self | submission_others
                                     -- dealing | playing | complete
  memory_mode        VARCHAR(20)     NOT NULL DEFAULT 'first_to_x',
                                     -- all_match | first_to_x | timed
  card_pool          VARCHAR(20)     NOT NULL DEFAULT 'family_cards',
                                     -- all_cards | family_cards
  target_matches     TINYINT         NOT NULL DEFAULT 5,
  time_limit_mins    TINYINT         NOT NULL DEFAULT 5,
  turn_timeout_mins  TINYINT         NOT NULL DEFAULT 5,
  game_started_at    DATETIME        NULL,
  game_ends_at       DATETIME        NULL,    -- set when timed mode starts
  completed_at       DATETIME        NULL,
  winner_player_id   BIGINT UNSIGNED NULL,    -- FK to mfsd_sm_players
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_student (student_user_id),
  KEY idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 5.2 `mfsd_sm_players`

One row per player per game.

```sql
CREATE TABLE mfsd_sm_players (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id                   BIGINT UNSIGNED NOT NULL,   -- FK mfsd_sm_games.id
  user_id                   BIGINT UNSIGNED NOT NULL,
  display_name              VARCHAR(100)    NOT NULL,
  role                      VARCHAR(20)     NOT NULL,   -- student | parent | carer | sibling | other
  turn_order                TINYINT         NOT NULL DEFAULT 0,
  self_submitted            TINYINT(1)      NOT NULL DEFAULT 0,
  others_submitted          TINYINT(1)      NOT NULL DEFAULT 0,
  score                     SMALLINT        NOT NULL DEFAULT 0,  -- matched pairs claimed
  last_seen_at              DATETIME        NULL,        -- heartbeat for presence
  current_turn_started_at   DATETIME        NULL,        -- set when turn begins
  joined_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY idx_game_user (game_id, user_id),
  KEY idx_game    (game_id),
  KEY idx_turn    (game_id, turn_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 5.3 `mfsd_sm_self_strengths`

Strengths each player writes about themselves in Phase 1.

```sql
CREATE TABLE mfsd_sm_self_strengths (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id        BIGINT UNSIGNED NOT NULL,
  player_id      BIGINT UNSIGNED NOT NULL,  -- FK mfsd_sm_players.id
  strength_id    BIGINT UNSIGNED NULL,       -- FK to existing strengths library (if predefined)
  strength_text  VARCHAR(200)    NOT NULL,   -- stored text at time of submission
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_game_player (game_id, player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 5.4 `mfsd_sm_cards`

Cards written about other players in Phase 2. Mirrors v4 `mfsd_ss_cards` pattern.

```sql
CREATE TABLE mfsd_sm_cards (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id           BIGINT UNSIGNED NOT NULL,
  author_player_id  BIGINT UNSIGNED NOT NULL,  -- who wrote it
  target_player_id  BIGINT UNSIGNED NOT NULL,  -- who it's about
  strength_id       BIGINT UNSIGNED NULL,
  strength_text     VARCHAR(200)    NOT NULL,
  is_free_text      TINYINT(1)      NOT NULL DEFAULT 0,
  flagged           TINYINT(1)      NOT NULL DEFAULT 0,  -- validator flag
  approved          TINYINT(1)      NOT NULL DEFAULT 1,  -- admin override
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_game        (game_id),
  KEY idx_author      (game_id, author_player_id),
  KEY idx_target      (game_id, target_player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 5.5 `mfsd_sm_board`

The memory game board. One row per face-down card position. Cards are duplicated into pairs before insertion.

```sql
CREATE TABLE mfsd_sm_board (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id              BIGINT UNSIGNED NOT NULL,
  position             SMALLINT        NOT NULL,  -- 0-indexed board slot
  pair_key             VARCHAR(40)     NOT NULL,  -- both cards in a pair share this value
  card_type            VARCHAR(20)     NOT NULL,  -- 'family_card' | 'self_strength'
  card_id              BIGINT UNSIGNED NULL,       -- FK mfsd_sm_cards.id (if family_card)
  self_strength_id     BIGINT UNSIGNED NULL,       -- FK mfsd_sm_self_strengths.id (if self_strength)
  -- Display data cached at deal time (avoids joins on every flip)
  author_player_id     BIGINT UNSIGNED NULL,
  target_player_id     BIGINT UNSIGNED NULL,
  strength_text        VARCHAR(200)    NOT NULL,
  author_display       VARCHAR(100)    NULL,       -- "Mum" / "Dad" / player display name
  target_display       VARCHAR(100)    NULL,
  -- State
  is_face_up           TINYINT(1)      NOT NULL DEFAULT 0,
  is_matched           TINYINT(1)      NOT NULL DEFAULT 0,
  matched_by_player_id BIGINT UNSIGNED NULL,       -- FK mfsd_sm_players.id
  matched_at           DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY idx_game_pos (game_id, position),
  KEY idx_game      (game_id),
  KEY idx_pair      (game_id, pair_key),
  KEY idx_matched   (game_id, is_matched)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 5.6 `mfsd_sm_turns`

Audit log of every turn action. Used for turn timeout logic and summary analytics.

```sql
CREATE TABLE mfsd_sm_turns (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id           BIGINT UNSIGNED NOT NULL,
  player_id         BIGINT UNSIGNED NOT NULL,
  turn_number       SMALLINT        NOT NULL,
  flip1_position    SMALLINT        NULL,
  flip2_position    SMALLINT        NULL,
  is_match          TINYINT(1)      NULL,
  timed_out         TINYINT(1)      NOT NULL DEFAULT 0,
  started_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at      DATETIME        NULL,
  PRIMARY KEY (id),
  KEY idx_game   (game_id),
  KEY idx_player (game_id, player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 5.7 `mfsd_sm_summaries`

Stores the generated AI summary per player (student and each parent get their own record).

```sql
CREATE TABLE mfsd_sm_summaries (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id        BIGINT UNSIGNED NOT NULL,
  player_id      BIGINT UNSIGNED NOT NULL,
  summary_type   VARCHAR(20)     NOT NULL,  -- 'student' | 'parent'
  ai_summary     LONGTEXT        NULL,
  generated_at   DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY idx_game_player (game_id, player_id),
  KEY idx_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 6. REST API Endpoints

Namespace: `mfsd-ss/v1/`  
Authentication: `wp_rest` nonce, `is_user_logged_in()` on all routes.

All existing v4 routes for the guessing game (`/state`, `/strengths`, `/game/start`, `/submission/*`, `/turn/*`, `/game/results`, `/game/summary`) are preserved unchanged for the guessing game mode.

The following routes are **new** for the Memory game.

---

### 6.1 `GET /memory/state`

Returns the current game state for the authenticated user.

**Response:**
```json
{
  "status": "playing",
  "game_id": 12,
  "game_key": "sm_42_1718000000",
  "memory_mode": "first_to_x",
  "target_matches": 5,
  "time_limit_mins": 5,
  "game_ends_at": null,
  "player": { "id": 3, "role": "student", "score": 2, "turn_order": 1 },
  "all_players": [
    { "id": 3, "display_name": "Jamie", "role": "student", "score": 2, "is_present": true, "is_me": true },
    { "id": 4, "display_name": "Mum", "role": "parent", "score": 1, "is_present": false, "is_me": false }
  ],
  "current_turn_player_id": 3,
  "current_turn_started_at": "2024-01-01T10:05:00Z",
  "turn_timeout_mins": 5,
  "total_positions": 20,
  "matched_count": 6
}
```

---

### 6.2 `POST /memory/self-save`

Save self-strength card selection for Phase 1.

**Body:**
```json
{
  "game_id": 12,
  "strengths": [
    { "strength_id": 14, "strength_text": "Creative" },
    { "strength_id": 27, "strength_text": "Kind" }
  ]
}
```

**Response:**
```json
{ "ok": true, "saved": 5, "complete": true }
```

---

### 6.3 `POST /memory/self-submit`

Lock Phase 1. Player cannot change self-strengths after this.

**Body:** `{ "game_id": 12 }`

**Response:**
```json
{ "ok": true, "next_phase": "submission_others" }
```

---

### 6.4 `GET /memory/board`

Returns the full board state. Face-down card content is **withheld** — clients only receive position, `is_face_up`, `is_matched`, and limited metadata. Face-up content is included only for cards that are currently face-up or matched.

**Response:**
```json
{
  "game_id": 12,
  "positions": [
    {
      "position": 0,
      "is_face_up": false,
      "is_matched": false,
      "matched_by": null,
      "content": null
    },
    {
      "position": 1,
      "is_face_up": true,
      "is_matched": true,
      "matched_by": { "player_id": 3, "display_name": "Jamie" },
      "content": {
        "author_display": "Mum",
        "target_display": "Jamie",
        "strength_text": "Resilient",
        "label": "Mum thinks Jamie is… Resilient",
        "card_type": "family_card"
      }
    }
  ]
}
```

---

### 6.5 `POST /memory/flip`

A player flips a card. Server validates it is the player's turn and the card is face-down and unmatched.

**Body:**
```json
{ "game_id": 12, "position": 7 }
```

**Response (single flip — waiting for second card):**
```json
{
  "ok": true,
  "flip_number": 1,
  "content": {
    "author_display": "Dad",
    "target_display": "Jamie",
    "strength_text": "Brave",
    "label": "Dad thinks Jamie is… Brave",
    "card_type": "family_card"
  },
  "is_match": null,
  "turn_complete": false
}
```

**Response (second flip — match):**
```json
{
  "ok": true,
  "flip_number": 2,
  "content": { ... },
  "is_match": true,
  "matched_pair": {
    "position1": 3,
    "position2": 7,
    "strength_text": "Brave",
    "author_display": "Dad",
    "target_display": "Jamie",
    "label": "Dad thinks Jamie is… Brave"
  },
  "turn_complete": false,
  "new_score": 3,
  "game_complete": false
}
```

**Response (second flip — no match):**
```json
{
  "ok": true,
  "flip_number": 2,
  "content": { ... },
  "is_match": false,
  "turn_complete": true,
  "next_player_id": 4,
  "game_complete": false
}
```

---

### 6.6 `POST /memory/heartbeat`

Called every 30 seconds by any connected client to keep presence alive.

**Body:** `{ "game_id": 12 }`

**Response:** `{ "ok": true }`

---

### 6.7 `GET /memory/summary`

Returns the summary data for the logged-in player. Different data returned depending on role.

**Response (student):**
```json
{
  "player_role": "student",
  "student_name": "Jamie",
  "self_strengths": ["Creative", "Kind", "Brave", "Funny", "Caring"],
  "family_wrote_about_me": [
    { "strength_text": "Resilient", "author_display": "Mum" },
    { "strength_text": "Brave", "author_display": "Dad" }
  ],
  "ai_summary": "...",
  "lens_data_available": true
}
```

**Response (parent):**
```json
{
  "player_role": "parent",
  "player_name": "Mum",
  "student_name": "Jamie",
  "student_self_strengths": ["Creative", "Kind", "Brave", "Funny", "Caring"],
  "family_wrote_about_student": [ ... ],
  "student_ai_summary": "...",
  "parent_self_strengths": ["Organised", "Empathetic", ...],
  "student_wrote_about_parent": [
    { "strength_text": "Supportive", "author_display": "Jamie" }
  ],
  "parent_ai_summary": "...",
  "lens_data_available": false
}
```

---

### 6.8 `POST /memory/award-badge`

Called by frontend once the student has viewed the summary. Mirrors the Solution Lens badge pattern exactly.

**Body:** `{ "game_id": 12 }`

**Response:** `{ "badge_earned": true, "badge_image_url": "..." }`

---

## 7. File Structure

```
mfsd-super-strengths/
│
├── mfsd-super-strengths.php              Bootstrap, singleton, shortcode, cron
│
├── assets/
│   ├── mfsd-super-strengths.css          Theme-aware styles (Gamer + Corporate)
│   └── mfsd-super-strengths.js           Vanilla-JS frontend state machine
│
├── includes/
│   ├── class-ss-db.php                   Table definitions, install, seed data
│   ├── class-ss-validator.php            Free-text content moderation pipeline
│   ├── class-ss-game.php                 Guessing game engine (dormant snap methods)
│   ├── class-ss-api.php                  REST API — memory + demo routes
│   ├── class-ss-memory.php               Memory game engine (board, flip, scoring, turns)
│   ├── class-ss-summary.php              Summary data builder + SteveGPT prompt construction
│   ├── class-ss-badges.php               Badge award logic — MFSD_SS_Badges class
│   └── class-ss-demo.php                 Demo mode engine (Steve picks, board, summary)
│
└── admin/
    └── admin-page.php                    Tabbed WP admin UI
```

---

## 8. Frontend — Screen Map & State Machine

The JS state machine follows the same boot/render pattern as v4. All screen functions are named consistently.

### Screen IDs and trigger conditions

| Screen ID | Function | Triggered when |
|---|---|---|
| `NO_GAME` | `renderNoGame()` | API returns `no_game` |
| `SELF_INTRO` | `renderSelfIntro()` | `status === 'submission_self'`, not yet started |
| `SELF_WRITE` | `renderSelfWrite()` | `status === 'submission_self'`, in progress |
| `OTHERS_OVERVIEW` | `renderSubmissionOverview()` | `status === 'submission_others'` |
| `OTHERS_WRITE` | `renderPickStrengths(target)` | User selects a target from overview |
| `OTHERS_REVIEW` | `renderReview()` | All targets complete |
| `WAITING` | `renderSubmissionWaiting()` | Player submitted, others pending |
| `DEALING` | `renderDealing()` | `status === 'dealing'` |
| `BOARD` | `renderBoard()` | `status === 'playing'`, is my turn |
| `BOARD_WATCH` | `renderBoard()` | `status === 'playing'`, not my turn (passive view) |
| `AWAY_NOTICE` | `renderAwayNotice(player)` | Active player not present, within timeout window |
| `TIMEOUT_SKIP` | Auto — no screen | Server auto-advances turn after timeout |
| `GAME_OVER` | `renderGameOver()` | `status === 'complete'`, brief interstitial |
| `SUMMARY` | `renderSummary()` | `status === 'complete'`, after brief pause |

### State object

```javascript
let state = {
  gameId:          null,
  gameKey:         null,
  gameStatus:      null,   // submission_self | submission_others | dealing | playing | complete
  memoryMode:      null,   // all_match | first_to_x | timed
  player:          null,
  allPlayers:      [],
  strengths:       {},     // card library
  draftSelf:       [],     // self-strength draft (Phase 1)
  draftCards:      {},     // other-strength drafts (Phase 2)
  board:           [],     // array of position objects
  currentTurnPlayerId: null,
  gameEndsAt:      null,   // for timed mode
  pollTimer:       null,
  heartbeatTimer:  null,
};
```

### Polling strategy

| Situation | Poll interval |
|---|---|
| Submission waiting screen | 8 seconds |
| Active turn (my turn) | None — optimistic UI after flip API response |
| Watching (not my turn) | 4 seconds |
| Awaiting away player | 10 seconds |
| Heartbeat (all playing states) | 30 seconds (separate timer) |

---

## 9. Card Pool Logic

Controlled by `mfsd_ss_card_pool` admin setting.

### `family_cards` (default)

Only cards written by family members **about other players** (Phase 2 cards) enter the matching pool.

- Source table: `mfsd_sm_cards`
- Filter: `flagged = 0 AND approved = 1`
- Each card duplicated once → matched pair

### `all_cards`

Self-strength cards (Phase 1) are also included in the pool alongside family cards.

- Source tables: `mfsd_sm_cards` + `mfsd_sm_self_strengths`
- Self-strength cards use `card_type = 'self_strength'` in `mfsd_sm_board`
- Reveal text for self-strength cards: **"[Name] believes they are… [Strength]"**
- Reveal text for family cards: **"[Author] thinks [Target] is… [Strength]"**

### Board size considerations

With 3 players (student + 2 parents) and 5 cards per target:

| Pool type | Cards written | Pairs on board | Total tiles |
|---|---|---|---|
| `family_cards` | 3 players × 2 targets × 5 = 30 | 30 pairs | 60 tiles |
| `all_cards` | 30 + 3 self × 5 = 45 | 45 pairs | 90 tiles |

For large families, the admin may want `first_to_x` mode rather than `all_match` to keep game length reasonable.

---

## 10. Turn & Presence System

### Heartbeat

Every connected client calls `POST /memory/heartbeat` every 30 seconds. This writes `NOW()` to `mfsd_sm_players.last_seen_at`.

### Presence detection

A player is considered **present** if their `last_seen_at` is within the last 90 seconds (3 × heartbeat interval, allowing for network delay).

The board poll response includes `is_present` for each player. The frontend shows a small indicator next to each player's name.

### Away banner

When it is Player X's turn and `is_present = false` for Player X, all other players see:

> ⏳ **[Name] has gone away** — the game will wait up to [N] minutes before moving on.

The banner includes a countdown timer based on `current_turn_started_at + turn_timeout_mins`.

### Auto-advance

A WordPress cron event (`mfsd_ss_turn_timeout_check`) runs every minute. It checks:

```
SELECT * FROM mfsd_sm_games WHERE status = 'playing'
```

For each playing game, it checks whether the current player's turn started more than `turn_timeout_mins` minutes ago. If so:

1. The current turn record is marked `timed_out = 1` and `completed_at = NOW()`.
2. Turn passes to the next player (skipping players with zero tiles remaining if in `all_match` mode — not applicable to other modes).
3. `mfsd_sm_players.current_turn_started_at` is updated for the new player.

### Return to game

If a player returns after being away, they see the current board state and their turn (if it's theirs) or the watch state (if it's not).

---

## 11. Game End Conditions

### `all_match`
Game ends when all positions on the board have `is_matched = 1`. Winner is the player with the highest `score`. Ties are displayed as joint winners (no sudden-death tiebreaker for this mode — keeps it family-friendly).

### `first_to_x`
Game ends immediately when any player's `score` reaches `target_matches`. That player is the winner. Checked at the end of every successful match.

### `timed`
Game ends when `NOW() > game_ends_at`. At game start, `game_ends_at = game_started_at + time_limit_mins minutes`. The cron checks this every minute. The frontend countdown timer is driven client-side from `game_ends_at`.

---

## 12. Summary Screen Design

### Student view

**Tab 1 — Your Strengths (default)**

| Section | Content |
|---|---|
| Self-perception | "What you said about yourself" — 5 cards the student wrote in Phase 1 |
| How others see you | Cards written about the student by family members, grouped by author |
| Matches | Strengths that appeared in both self and family cards, highlighted |
| Steve Says | AI-generated paragraph (see §13) |
| Chat with Steve | SteveGPT chatbot widget |
| Badge | Awarded once student views summary. Badge image is 150×150px with click-to-zoom modal (280×280px full-screen overlay, dismissible by click or Esc). |

**Navigation buttons** (`.ss-summary-nav`): Displayed as a row (side by side) with `max-width: 240px` each and `flex: 1`. Two buttons: 🏅 See My Badges (gold) and 📚 Course Details (ghost). On the demo summary screen the labels are 🏅 View My Badges and 📚 Back to My Course.

### Parent view

**Tab 1 — [Student name]'s Strengths** (same as student Tab 1 — parents see the same summary)

**Tab 2 — Your Strengths (parent-specific)**

| Section | Content |
|---|---|
| What you said about yourself | Parent's own Phase 1 self-strengths |
| What [student name] wrote about you | Cards the student wrote about the parent in Phase 2 |
| Matches | Overlap between parent's self-perception and how the student sees them |
| Steve Says (parent) | Separate AI paragraph generated for the parent (see §13) |
| Chat with Steve | SteveGPT chatbot widget (same widget as student, parent-appropriate context) |

**Note:** Parents do not receive a badge. The badge is a student-only reward tied to the course.

---

## 13. AI / SteveGPT Integration

Mirrors the Solution Lens pattern exactly. Two chatbot IDs stored as WP options.

### 13.1 Summary generation

Called in `MFSD_SS_Summary::generate_ai_summary()` once the game reaches `complete` status. One summary generated per player who needs one (student + each parent).

#### Student prompt structure

```
You are Steve, a warm and encouraging coach speaking to {student_name}, aged {age}.

=== WHAT {student_name} WROTE ABOUT THEMSELVES ===
{self_strength_1}, {self_strength_2}, {self_strength_3}, {self_strength_4}, {self_strength_5}

=== WHAT THEIR FAMILY WROTE ABOUT {student_name} ===
{author} wrote: {strength_text}
[... one line per card ...]

=== SOLUTION LENS CONTEXT ===
{student_name} completed the Solution Lens activity in Week 1.
They saw {x} images the same way as their parent/carer and {y} differently.
The activity theme: noticing that two people can look at the same situation and see it differently.
[Included only if lens_data_available === true]

Your task:
1. Notice where {student_name}'s self-view matches how their family sees them — celebrate this.
2. Notice where there are differences — frame these positively as new perspectives to explore.
3. If Solution Lens data is available, gently connect the idea of different perspectives to how family members may notice different strengths.
4. Suggest one specific way {student_name} could lean into a strength this week.
5. UK English. Warm. Age-appropriate. Address {student_name} as "you". 3–5 sentences.
```

#### Parent prompt structure

```
You are Steve, a warm coach speaking to {parent_name}, a parent of {student_name}.

=== WHAT {parent_name} WROTE ABOUT THEMSELVES ===
{self_strength_1} ... {self_strength_5}

=== WHAT {student_name} WROTE ABOUT {parent_name} ===
{strength_text} [... one per card ...]

Your task:
1. Notice where {parent_name}'s self-view matches how {student_name} sees them — celebrate shared recognition.
2. Where there are differences, frame these as an opportunity — {student_name} may see a strength {parent_name} doesn't always recognise in themselves.
3. Keep tone warm, adult-appropriate, and encouraging. 2–4 sentences.
UK English.
```

### 13.2 Chatbot context

Built in `MFSD_SS_Summary::build_chat_context()`. Passed to the SteveGPT shortcode as the `context` attribute.

Includes:
- Game summary type (family size)
- All strength cards (author / target / text)
- AI summary already generated
- Solution Lens data if available

Follows the same sanitisation as Solution Lens (`build_chat_context` strips quotes, newlines, brackets).

---

## 14. Solution Lens Cross-Reference

The AI summary queries for completed Solution Lens sessions belonging to the student.

```php
// In MFSD_SS_Summary::get_lens_context($student_user_id)
$session = $wpdb->get_row($wpdb->prepare(
    "SELECT session_id, summary_type, ai_summary
     FROM {$wpdb->prefix}mfsd_lens_sessions
     WHERE student_id = %d AND status = 'complete'
     ORDER BY completed_at DESC LIMIT 1",
    $student_user_id
));
```

If a completed session exists:
- `lens_data_available = true`
- `agreements` and `differences` are pulled via `MFSD_Lens_Context::calculate_agreements()` and `calculate_differences()`
- These values are passed into the Steve prompt (see §13.1)

If no completed session exists:
- `lens_data_available = false`
- The Solution Lens paragraph is omitted from the Steve prompt
- No error is thrown

This means the Super Strengths summary is enriched if the student has done the Solution Lens, but degrades gracefully if they haven't.

---

## 15. Validator — Self-Strengths

Phase 1 self-strengths are picked from the card library (predefined options), so free-text validation is only needed if the student selects the free-text option.

The same `MFSD_SS_Validator::validate($text)` pipeline is used:
1. Normalise leetspeak
2. Check banned terms table
3. Check PII regex patterns

Free-text in Phase 1 is subject to the same admin toggles (`mfsd_ss_free_text_11_12`, `mfsd_ss_free_text_13_14`) and length limits as Phase 2 free-text.

Self-strengths written as free-text are stored with `strength_id = NULL` and the raw text in `strength_text`.

---

## 16. Demo Mode — Single Player

Demo mode is a standalone, single-player variant of the Memory game. It is enabled via an admin toggle and serves as both an introduction to the game mechanic and a meaningful self-reflection activity in its own right. It can be played before any family members have been invited, but only after the three prerequisite course tasks are complete.

---

### 16.1 Purpose

Demo mode answers the question: *"What if no family is available yet?"* Rather than blocking the student, it gives them a fully formed experience using their own course history as the second player. Steve selects five strength cards on the student's behalf, drawing on evidence from three prior tasks. The reveal of Steve's picks — and the explanation of *why* he chose them — is the emotional and educational payoff.

---

### 16.2 Ordering Gate

Demo mode is gated. The student **cannot** access Super Strengths (in any mode) until all three of the following tasks are marked `completed` in the task ordering system:

| Task | Plugin | Data used by Steve |
|---|---|---|
| Solution Lens | `mfsd-solution-lens` | Perception style — where student and parent saw same vs different things; the Gestalt principles that emerged |
| Word Association | `mfsd-word-association` *(assumed slug)* | The words the student associated most quickly / strongly |
| Who Am I | `mfsd-who-am-i` *(assumed slug)* | MBTI-style personality type result |

The existing ordering gate in `mfsd-super-strengths.php` already checks `mfsd_get_task_status()`. This gate is extended to require all three tasks, not just a single predecessor:

```php
$prerequisites = ['solution_lens', 'word_association', 'who_am_i'];
foreach ($prerequisites as $task) {
    if (mfsd_get_task_status($student_id, $task) !== 'completed') {
        // Return locked message with list of what still needs completing
    }
}
```

If any prerequisite is incomplete, the student sees a specific message listing which tasks remain, not a generic lock screen. This is consistent with the course's philosophy of transparent progress.

---

### 16.3 When Demo Mode Is Available vs Family Mode

| Condition | Mode shown |
|---|---|
| Prerequisites incomplete | Locked — neither mode available |
| Prerequisites complete, demo mode ON in admin, no active family game | Demo mode offered as primary entry point |
| Prerequisites complete, demo mode ON, family game already exists | Family game resumes; demo mode not re-offered |
| Prerequisites complete, demo mode OFF in admin | Standard family game entry only |
| Demo game already completed (one attempt) | Summary screen only — no replay |

Demo mode is a **one-time activity**. Once the student has completed it and viewed the summary, they cannot replay. A completed demo game is stored with `game_type = 'demo'` in `mfsd_sm_games`.

The intro screen button for demo entry is labelled **"Play 1 player with Steve"** (with Steve's avatar if available). A spacer of 28px separates it from the chat widget below.

---

### 16.4 Demo Mode Game Flow

#### Step 1 — Self-strengths (same as family game Phase 1)
The student picks 5 strengths from the card library that they feel describe themselves. The same free-text rules and validator apply. These are stored in `mfsd_sm_self_strengths` as normal.

#### Step 2 — Steve picks his 5 (server-side, instant)
Once the student submits their 5, the server calls `MFSD_SS_Demo::generate_steve_picks($student_id, $game_id)`. This function:

1. Fetches the three prerequisite data sources (see §16.6)
2. Builds a structured prompt for SteveGPT
3. Receives back 5 strength IDs (or free-text labels if no library match exists) plus a short rationale for each pick
4. Stores Steve's picks in `mfsd_sm_cards` with `author_player_id` set to a reserved system player row (`display_name = 'Steve'`, `role = 'steve'`)
5. Stores the rationale for each pick in `mfsd_sm_demo_rationale` (new table — see §16.7)

The student sees a brief animated interstitial — *"Steve is thinking about you…"* — while the API call completes. This takes 3–8 seconds in practice.

#### Step 3 — Board is dealt
`MFSD_SS_Memory::deal_board()` is called. The 5 student cards and 5 Steve cards are duplicated into 10 matched pairs — 20 tiles total. Board layout: 4×5 grid on desktop, 4×5 on mobile.

Steve's cards are face-down on the board. The student does not know which positions hold Steve's picks versus their own — that is the discovery.

#### Step 4 — Timed game
- Timer starts immediately when the board is shown. Duration set by admin (`mfsd_ss_demo_time_limit_mins`, default 3 minutes).
- Countdown displayed prominently — creates urgency.
- Student flips two cards per turn. Match: pair freezes face-up, score increments, student goes again. No match: cards flip back after 1.5s pause.
- **No turn rotation** — student plays continuously until time runs out or all 20 tiles are matched.
- **Match moment reveal:** When a matched pair is claimed, the banner shows:
  - If both cards are student picks: *"✨ You believe you are… [Strength]"*
  - If both cards are Steve picks: *"🤖 Steve thinks you are… [Strength]"*
  - If one of each — i.e. the student and Steve both picked the same strength — this is a **special match**: *"⭐ You and Steve both see this in you… [Strength]"* — distinct animation, held on screen for 3 seconds instead of 2.5.

#### Step 5 — Game ends
Either all 20 tiles matched, or timer reaches zero. Brief game-over interstitial showing score (pairs matched / 10).

#### Step 6 — Summary screen
See §16.5 below.

---

### 16.5 Demo Mode Summary Screen

The summary screen has two panels, revealed in sequence.

**Panel 1 — Your Picks vs Steve's Picks**

Shown immediately on load. Two side-by-side columns:

| Your 5 Strengths | Steve's 5 Picks |
|---|---|
| Creative | Resilient |
| Kind | Creative ← *(shared — highlighted)* |
| Brave | Empathetic |
| Funny | Curious |
| Caring | Persistent |

Shared picks (student and Steve chose the same strength) are highlighted in gold/amber on both sides with a connecting line or icon between them. This is the key visual moment — seeing where your self-view and Steve's evidence-based view align.

**Panel 2 — Why Steve Picked These**

Revealed after a brief 1.5s delay (creates a sense of Steve "explaining himself"). For each of Steve's 5 picks, a card is shown containing:

- The strength name
- Steve's rationale — 1–2 sentences referencing the specific prior activity that informed the pick
- The prior activity it came from (shown as a small badge: "Solution Lens", "Word Association", "Who Am I")

Example rationale cards:

> **Resilient** *(Solution Lens)*
> "In your Solution Lens activity, when you and your parent saw things differently, you stayed curious rather than frustrated. That kind of resilience — sitting with uncertainty — is something not everyone finds easy."

> **Creative** *(Word Association)*
> "Several of your fastest word associations pointed toward imagination and originality. Your mind reaches for the new and unexpected — that's a creative strength showing up."

> **Curious** *(Who Am I)*
> "Your personality type — [type] — is strongly associated with a love of learning and asking 'why'. Curiosity isn't just something you do; it looks like it's part of how you're wired."

**Panel 3 — Steve's Full AI Summary**

Below the rationale cards, Steve's full generated summary (see §16.6 for prompt structure). This is the reflective paragraph that connects everything — what the student sees in themselves, what the prior course activities suggest, and an encouraging framing for the family game ahead.

**No badge is awarded for demo mode.** The badge for Super Strengths is tied to the full family game completion.

**SteveGPT chat widget** is present on the summary screen, with the demo session context loaded. The student can ask Steve questions about any of his picks.

---

### 16.6 Steve's Card Selection — Data Sources & API Call

`MFSD_SS_Demo::generate_steve_picks()` fetches from three sources before calling SteveGPT.

#### Source 1 — Solution Lens
```php
// Fetch completed session
$lens = $wpdb->get_row($wpdb->prepare(
    "SELECT session_id, ai_summary FROM {$wpdb->prefix}mfsd_lens_sessions
     WHERE student_id = %d AND status = 'complete'
     ORDER BY completed_at DESC LIMIT 1",
    $student_id
));
// Fetch agreements/differences via MFSD_Lens_Context
$agreements  = MFSD_Lens_Context::calculate_agreements($responses);
$differences = MFSD_Lens_Context::calculate_differences($responses);
```

Data passed to prompt: number of agreements, nature of differences, and the existing AI summary text (already written in Steve's voice — highly relevant).

#### Source 2 — Word Association
```php
// Assumed pattern — to be confirmed against actual plugin schema
$word_assoc = $wpdb->get_results($wpdb->prepare(
    "SELECT word, response_time_ms FROM {$wpdb->prefix}mfsd_wa_responses
     WHERE student_id = %d
     ORDER BY response_time_ms ASC LIMIT 10",
    $student_id
));
```

Data passed to prompt: the student's fastest word associations (quickest response time = strongest instinctive link). **Note:** The exact table and column names must be confirmed against the Word Association plugin schema before this function is implemented. A safe fallback (`word_assoc_available = false`) must be in place if the table doesn't exist.

#### Source 3 — Who Am I
```php
// Assumed pattern — to be confirmed against actual plugin schema
$personality = get_user_meta($student_id, 'mfsd_personality_type', true);
$personality_label = get_user_meta($student_id, 'mfsd_personality_label', true);
```

Data passed to prompt: the student's MBTI-style type code and label. **Note:** Meta key names must be confirmed against the Who Am I plugin before implementation. Safe fallback if not set.

---

### 16.7 Steve's Card Selection — Prompt Structure

```
You are Steve, a warm and insightful coach.

You are selecting 5 strengths for {student_name}, aged {age}, based on evidence
from three activities they completed on the My Future Self platform.

=== AVAILABLE STRENGTHS LIBRARY ===
{comma-separated list of all active strength labels from the card library}

=== SOLUTION LENS DATA ===
{student_name} completed the Solution Lens activity.
They saw {agreements} images the same way as their parent and {differences_count} differently.
Specific differences: {differences_text}
Steve's prior summary of this activity: "{lens_ai_summary}"

=== WORD ASSOCIATION DATA ===
{student_name}'s fastest word associations (most instinctive responses):
{word_1}, {word_2}, {word_3}, {word_4}, {word_5}, {word_6}, {word_7}, {word_8}, {word_9}, {word_10}
[OMIT THIS SECTION if word_assoc_available = false]

=== WHO AM I DATA ===
{student_name}'s personality type: {type_code} — {type_label}
[OMIT THIS SECTION if personality_available = false]

=== {student_name}'s OWN PICKS ===
The student selected these 5 strengths to describe themselves:
{self_strength_1}, {self_strength_2}, {self_strength_3}, {self_strength_4}, {self_strength_5}

=== YOUR TASK ===
1. Select exactly 5 strengths from the available library that the evidence above suggests
   {student_name} possesses. You MAY include up to 2 strengths that also appear in their
   own picks — where the evidence strongly confirms their self-view. You MUST include at
   least 1 strength that does NOT appear in their own picks — this is the "surprising"
   strength that makes the activity interesting.
2. For each pick, write a rationale of 1–2 sentences in warm, UK English addressed to
   {student_name} as "you", referencing the specific activity that informed the pick.
3. Return ONLY a JSON array. No preamble, no markdown, no explanation outside the JSON.

Return format:
[
  {
    "strength_text": "Resilient",
    "source_activity": "Solution Lens",
    "rationale": "..."
  },
  ...
]
```

The response is parsed as JSON. If parsing fails, a safe fallback of 5 random strengths from the library is used, with rationale set to a generic Steve phrase. The fallback is logged for admin review.

---

### 16.8 New Table — `mfsd_sm_demo_rationale`

Stores Steve's per-card rationale so it can be displayed on the summary screen without re-calling the API.

```sql
CREATE TABLE mfsd_sm_demo_rationale (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id          BIGINT UNSIGNED NOT NULL,
  board_card_id    BIGINT UNSIGNED NOT NULL,  -- FK mfsd_sm_board.id (Steve's card)
  strength_text    VARCHAR(200)    NOT NULL,
  source_activity  VARCHAR(60)     NOT NULL,  -- 'Solution Lens' | 'Word Association' | 'Who Am I'
  rationale        TEXT            NOT NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 16.9 New Admin Settings for Demo Mode

Added to the admin settings page under a "Demo Mode" sub-section:

| Option key | Label | Type | Default | Notes |
|---|---|---|---|---|
| `mfsd_ss_demo_mode_enabled` | Enable demo mode | Toggle | Off | When on, demo is offered to students who meet prerequisites |
| `mfsd_ss_demo_time_limit_mins` | Demo game time limit (minutes) | Number | 3 | Admin-configurable |

The three SteveGPT chatbot IDs for demo mode (`mfsd_stevegpt_map_ss_demo_picker`, `mfsd_stevegpt_map_ss_demo_summary`, and the welcome intro/chat shared with the family game) are configured in the SteveGPT settings section — see §4.5 for the full list of all 7 chatbot option keys.

---

### 16.10 New REST API Endpoints for Demo Mode

Added to `class-ss-api.php` under the `mfsd-ss/v1/` namespace.

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/demo/status` | Returns whether demo is available, locked, in-progress, or complete for this student |
| `POST` | `/demo/self-submit` | Student submits their 5 self-strengths; triggers Steve's pick generation |
| `GET` | `/demo/board` | Returns board state (same structure as `/memory/board`) |
| `POST` | `/demo/flip` | Student flips a card (same logic as `/memory/flip`, no turn validation needed) |
| `POST` | `/demo/heartbeat` | Keeps session alive; also used to sync server-side timer check |
| `GET` | `/demo/summary` | Returns full summary data including Steve's rationale cards and AI summary |

---

### 16.11 New File — `class-ss-demo.php`

A dedicated class handles all demo mode logic, keeping it cleanly separated from the family game engine.

```
includes/
  class-ss-demo.php    ← NEW
    ::get_status($student_id)
    ::check_prerequisites($student_id)
    ::generate_steve_picks($student_id, $game_id)
    ::fetch_lens_data($student_id)
    ::fetch_word_assoc_data($student_id)
    ::fetch_personality_data($student_id)
    ::build_steve_prompt($student_id, $game_id, $self_strengths)
    ::parse_steve_response($raw_json, $game_id)
    ::get_summary_data($game_id, $student_id)
    ::generate_demo_ai_summary($game_id, $student_id)
```

---

### 16.12 Demo Mode in the State Machine

Two new screen IDs are added to the JS state machine:

| Screen ID | Function | Triggered when |
|---|---|---|
| `DEMO_SELF_WRITE` | `renderDemoSelfWrite()` | Demo mode, no active game, status = entry |
| `DEMO_STEVE_THINKING` | `renderSteveThinking()` | Self-strengths submitted, Steve pick API call in flight |
| `DEMO_BOARD` | `renderDemoBoard()` | Demo game status = `playing` |
| `DEMO_TIMEOUT` | `renderDemoTimeout()` | Timer reached zero |
| `DEMO_SUMMARY` | `renderDemoSummary()` | Demo game status = `complete` |

`renderSteveThinking()` shows an animated Steve avatar with rotating messages:
- *"Steve is reviewing your Solution Lens results…"*
- *"Steve is looking at your Word Association answers…"*
- *"Steve is thinking about your personality type…"*
- *"Almost there — Steve is making his picks…"*

These are display only — the messages rotate on a 2-second timer while the real API call completes in the background.

---

### 16.13 Demo Mode & `mfsd_sm_games` Table

Demo games use the existing `mfsd_sm_games` table with one additional column:

```sql
ALTER TABLE mfsd_sm_games
  ADD COLUMN game_type VARCHAR(20) NOT NULL DEFAULT 'family'
  AFTER game_key;
  -- Values: 'family' | 'demo'
```

The `winner_player_id` column is `NULL` for demo games (no winner — purely reflective). The `score` on the student's player row records how many pairs they matched.

---

### 16.14 Summary: How Demo Mode Differs from Family Mode

| Aspect | Family Mode | Demo Mode |
|---|---|---|
| Players | 2–4 (student + family) | 1 (student + Steve) |
| Card authorship | Family members write cards | Student writes 5; Steve generates 5 |
| Game end | Admin-set mode (all_match / first_to_x / timed) | Always timed |
| Turn rotation | Yes | No — student plays continuously |
| Special match | — | Student + Steve both picked same strength |
| Summary Tab 2 | Parent self-reflection (parent only) | Steve's rationale cards (student only) |
| Badge awarded | Yes — on full family game completion | No |
| Replay | — | One attempt only |
| Prerequisites | Tasks completed per ordering gate | All three: Solution Lens + Word Association + Who Am I |

---

## 17. Responsive Screen Sizes & Layout Parameters

Super Strengths v5.0 follows the exact width conventions confirmed in the Solution Lens CSS files (`lens-student.css`, `lens-parent.css`) — the reference implementation for all MFSD activity plugins.

---

### 17.1 The Solution Lens Pattern — Source of Truth

The Solution Lens CSS contains **no `@media` breakpoints**. Responsive behaviour is achieved entirely through:

- A `max-width: 640px` centred screen container that naturally scales down on narrow viewports
- `clamp()` for typography (e.g. `clamp(28px, 6vw, 48px)`) so font sizes scale fluidly
- `flex-wrap` on button rows so choices reflow on small screens
- `max-width: 100%` on the outer root so the theme's content column is the true layout constraint

Super Strengths v5.0 follows this pattern exactly — **no breakpoints are added** unless a specific element genuinely cannot scale gracefully without one. The memory board grid is the only exception (see §17.3).

---

### 17.2 Container Width Values

Taken directly from `lens-student.css` / `lens-parent.css` and applied consistently:

| Layer | Class | max-width | Source reference |
|---|---|---|---|
| Outer plugin root | `#mfsd-ss-root` | `100%` (implicit) | `#mfsd-lens-root` |
| Screen container | `.ss-screen` | `640px`, centred | `.lens-screen` |
| Intro / welcome text | `.ss-intro-text` | `480px`, centred | `.lens-intro-text` |
| Video embed | `.ss-video-wrap` | `560px`, centred | `.lens-video-wrap` |
| Memory card tile (constrained) | `.ss-memory-card` | `400px` max | `.lens-card` |
| Summary AI panel | `.ss-ai-summary` | `640px` (inherits screen) | `.lens-ai-summary` |
| Chat widget | `.ss-chat-wrap` | `100%` of parent | fills card footer |
| Board grid | `.ss-board` | `100%` of screen | tiles constrain via grid |

The screen container at `640px` is the primary constraint. Everything inside it scales naturally within that width — no additional wrapper max-widths are needed except where explicitly listed.

---

### 17.3 Typography

All heading sizes use `clamp()` matching the Solution Lens pattern:

```css
/* Student theme — Exo 2 */
.ss-game-title  { font-size: clamp(28px, 6vw, 48px); }  /* matches .lens-title */
.ss-summary-title { font-size: clamp(22px, 5vw, 36px); } /* matches .lens-summary-title */

/* Parent theme — Playfair Display */
.ss-game-title  { font-size: clamp(26px, 6vw, 42px); }  /* matches .lens-title parent */
.ss-summary-title { font-size: clamp(20px, 5vw, 32px); } /* matches .lens-summary-title parent */
```

Body text: `15px` / `line-height: 1.7` (parent), `15px` / `line-height: 1.7` (student) — matching `.lens-ai-summary`.

---

### 17.4 The One Exception — Memory Board Grid

The board grid cannot scale gracefully via `clamp()` alone because column count is discrete. One targeted breakpoint is added for the board only:

```css
/* Board grid — the only breakpoint in the plugin */
@media (max-width: 480px) {
    .ss-board-grid-6 {
        grid-template-columns: repeat(4, minmax(60px, 1fr));
    }
}
```

This collapses 6-column desktop boards to 4 columns on mobile. 4-column boards (demo and 2-player) are unchanged at all sizes.

Minimum tile touch target: `60px wide × 80px tall` on mobile — the student theme requires the game feel like a mobile game.

| Board size | Desktop | Mobile (≤480px) |
|---|---|---|
| 20 tiles — demo / 2-player | 4 × 5 | 4 × 5 (unchanged) |
| 60 tiles — 3-player | 6 × 10 | 4 × 15 |
| 120 tiles — 4-player | 6 × 20 | 4 × 30 — admin should use `first_to_x` |

---

### 17.5 CTA Button Sizing

All primary CTA buttons across the plugin use the `.ss-btn-full` modifier which constrains them to a consistent size:

```css
.ss-btn-full {
  display: flex;
  width: 100%;
  max-width: 480px;
  margin-left: auto;
  margin-right: auto;
}
```

This applies to every full-width CTA button across all screens (entry, self-write picker, demo board, submission, memory game). It ensures no button stretches to a full-width banner on wide viewports.

**Summary navigation** uses `.ss-summary-nav` with a row layout and per-button `max-width: 240px; flex: 1` so two buttons sit side by side at any reasonable viewport width.

### 17.6 Root Padding & Min-Height

Copied exactly from Solution Lens:

```css
#mfsd-ss-root {
    padding: 24px 16px;
    box-sizing: border-box;
    min-height: 60vh;
}
```

---

### 17.7 Admin CSS

The plugin admin page uses the same `admin.css` class conventions as Solution Lens (`mfsd-btn`, `mfsd-table`, `mfsd-form-table`, `mfsd-status-badge`, `mfsd-modal` etc.) — the black/gold corporate theme with Montserrat/Playfair Display. No new admin CSS classes are invented; all new settings sections reuse existing classes from `admin.css`.

### 17.8 Student vs Parent View

All width parameters in §17.2–17.5 apply equally to both views. The sizing system is theme-agnostic — the same `640px` screen container, the same `480px` intro text, the same board grid rules apply whether the user is a student (dark gamer theme) or a parent (corporate gold theme).

The distinction between views is handled entirely through:
- CSS scoping under `body.mfsd-role-student` and `body.mfsd-role-parent` (colours, typography, animations)
- JS logic reading `MFSD_SS_CFG.playerRole` (tab visibility, copy, badge award sequence)

Neither of these affects layout dimensions. One set of width values serves both roles.

---

## 18. Theme Integration — Student & Parent

Super Strengths v5.0 is rendered inside the MFSD custom theme (`myfutureself-theme`). The theme serves two visually and functionally distinct experiences depending on the logged-in user's role. The plugin CSS must respect and extend both themes — it must never override global theme variables or impose its own root-level palette.

---

### 16.1 How the Theme System Works

The WordPress theme detects the current user's `mfsd_role` meta value on page load and applies a role-specific body class:

- **Students (age 11–14):** `body.mfsd-role-student` — dark gamer theme
- **Parents / Teachers / Admins:** `body.mfsd-role-parent` — corporate black/gold theme

All plugin CSS must be scoped under these body classes. The plugin must not define `:root` CSS variables — it reads the theme's variables.

---

### 16.2 Student Theme

**Design philosophy:** Dark-mode dominant, gamified, feels like a modern video game interface. Motivates through visual reward and progression.

| Property | Value |
|---|---|
| Background | Deep dark (`#0A0E1A` and variants) |
| Primary accent | Glowing cyan (`#00D4FF`) |
| CTA / action | Neon purple (`#9333EA`) |
| Secondary accent | Amber / gold neon |
| Heading font | Exo 2 (bold, futuristic) |
| Body font | Nunito (legible, friendly) |
| Buttons | Arcade-style — neon borders, glow on hover |
| Progress bars | Textured fills, gradient blue-to-purple |
| Badges | Full-colour with glow and particle effects |
| Locked states | Dark hooded silhouettes |

**Already established** in `lens-student.css` — the plugin's student-facing CSS must mirror this pattern exactly. The Solution Lens student CSS is the reference implementation.

#### Student-specific Super Strengths game UI

| Element | Student treatment |
|---|---|
| Card backs (face down) | Dark tile with subtle neon border glow |
| Card fronts (face up) | Dark card, cyan strength text, author line in muted purple |
| Match moment | Gold/amber particle flash, neon glow pulse on matched pair |
| Scoreboard | Neon score pills, cyan accent for current player |
| Turn indicator | Glowing "Your Turn" badge with pulse animation |
| Away banner | Muted amber warning strip — non-alarming |
| Summary — self strengths | Displayed as badge-style chips with glow |
| Summary — family wrote | Tag cloud in cyan, author label in muted purple |
| SteveGPT chat widget | Dark panel, cyan border |
| Badge award | Full-colour badge with particle animation — matches Quest Log style |

---

### 16.3 Parent Theme

**Design philosophy:** Corporate, clean, structured. Instils trust. Palette inherited from `myfutureself.academy` — black, gold, off-white.

| Property | Value |
|---|---|
| Background | Off-white / warm parchment (`#F5F0E6`) |
| Primary accent | Gold (`#C9A84C`) |
| Text | Near-black (`#1a1208`, `#2a2010`) |
| Secondary text | Warm mid-tone (`#5a4828`, `#7a6040`) |
| Heading font | Playfair Display (elegant, serif) |
| Body font | Lato (professional, clean) |
| Buttons | Gold border, transparent fill; primary = solid gold |
| Progress indicators | Read-only, clean bar — no gamified texture |
| Cards / panels | `#ede8dc` background, `#C9A84C44` border |

**Already established** in `lens-parent.css` — the plugin's parent-facing CSS must mirror this pattern exactly.

#### Parent-specific Super Strengths game UI

| Element | Parent treatment |
|---|---|
| Card backs (face down) | Warm parchment tile, gold border |
| Card fronts (face up) | White card, dark strength text, gold author line |
| Match moment | Gold border flash, brief scale animation — understated |
| Scoreboard | Clean table-style, gold accent for leading player |
| Turn indicator | Gold-bordered label — "Your Turn" — no animation pulse |
| Away banner | Neutral grey information strip |
| Summary Tab 1 — student | Same structured layout as student sees, corporate styling |
| Summary Tab 2 — parent self | Two-column comparison: self-view vs student-view; gold highlight on matches |
| SteveGPT chat widget | Parchment panel, gold border |
| No badge | Parents do not receive badge or coin rewards |

---

### 16.4 CSS Architecture for the Plugin

The plugin ships **one CSS file** (`mfsd-super-strengths.css`) containing both theme variants. All rules are scoped:

```css
/* ── Student theme ─────────────────────────────── */
body.mfsd-role-student .ss-card-tile { ... }
body.mfsd-role-student .ss-match-flash { ... }

/* ── Parent / corporate theme ──────────────────── */
body.mfsd-role-parent .ss-card-tile { ... }
body.mfsd-role-parent .ss-match-flash { ... }
```

Where both themes share structural rules (grid layout, position, dimensions) these live in an unscoped base layer, with only colour/typography/animation overridden per role.

Shared variables available from the theme (do not redefine, only consume):

```css
/* Student theme provides */
--ss-bg:           #0A0E1A;
--ss-accent:       #00D4FF;
--ss-cta:          #9333EA;
--ss-text:         #e0f0ff;
--ss-text-dim:     #5a7a9a;
--ss-gold:         #C9A84C;   /* shared across both themes */
--ss-gold-lt:      #dbb85c;

/* Parent theme provides */
--ss-bg:           #F5F0E6;
--ss-accent:       #C9A84C;
--ss-text:         #2a2010;
--ss-text-dim:     #7a6040;
--ss-card-bg:      #ede8dc;
--ss-border:       rgba(201,162,39,0.27);
```

These map to the variable names already established in `mfsd-super-strengths.css` v4 — no breaking change.

---

### 16.5 Responsive Behaviour

Both themes must be fully functional on mobile (minimum 320px width). The memory board grid collapses to 4 columns on phones regardless of theme. The match moment flash banner, away notice, and turn indicator must be legible at small sizes without horizontal scroll.

The theme document notes that the student theme must feel like a **modern mobile game** — touch targets on the card tiles must be a minimum of 60×60px to be comfortably tappable.

---

### 16.6 Role Detection in JS

The JS engine reads the player's role from `MFSD_SS_CFG.playerRole` (already passed via `wp_localize_script`). This is used to:

- Apply role-appropriate copy ("My Progress" for students, "[Name]'s Progress" for parents)
- Show or hide the parent Tab 2 on the summary screen
- Suppress the badge award sequence for non-student roles

The body class (`mfsd-role-student` / `mfsd-role-parent`) handles all visual differentiation via CSS — JS does not manually toggle styles.

---

## 19. Badges & Quest Log Integration

---

### 19.1 Overview

Two badges are available in Super Strengths v5.0. Both are student-only — parents have no badge section and are never awarded badges through this plugin.

| Badge | Slug | Earned when |
|---|---|---|
| Completion | `badge_ss_complete` | Student completes the full family game summary screen **or** completes demo mode |
| Winner | `badge_ss_winner` | Student wins the family game (most pairs in `all_match`; first to target in `first_to_x`; most pairs when timer ends in `timed`) |

A student who wins also receives both badges — the completion badge is always awarded alongside the winner badge, not instead of it.

---

### 19.2 Badge Designs

Four enamel pin designs exist for each badge type (`superstrenghtsv3.png`):

- **Steverman** — Spider-Man style
- **Supersteve** — Superman style
- **Wondersteve** — Wonder Woman style
- **Harley Steve** — Harley Quinn style

**Selection:** One design is chosen at random at the point of award. The student receives whichever design is picked — there is no selection mechanism. The randomisation happens server-side in `MFSD_SS_Badges::award()` so the same design is stored consistently and shown the same way every time the student views their badges.

```php
// Server-side random design selection
$designs = ['steverman', 'supersteve', 'wondersteve', 'harley_steve'];
$design  = $designs[ array_rand($designs) ];
// Stored in Quest Log as badge_slug + '_' + $design
// e.g. 'badge_ss_complete_supersteve'
```

Both badge types use the same four designs. A student could in theory receive the same design for both completion and winner — this is intentional and acceptable.

---

### 19.3 Quest Log Updates Required

The Quest Log plugin (`mfsd-quest-log`) needs the following additions before Super Strengths v5.0 can award badges. This work is separate from the plugin build and should be completed as part of Phase F (QA & Polish) or earlier if Quest Log work is scheduled before then.

**New badge registrations needed (×8 total — 4 designs × 2 types):**

| Slug | Display name | Image file |
|---|---|---|
| `badge_ss_complete_steverman` | Super Strengths — Steverman | `badge_ss_complete_steverman.png` |
| `badge_ss_complete_supersteve` | Super Strengths — Supersteve | `badge_ss_complete_supersteve.png` |
| `badge_ss_complete_wondersteve` | Super Strengths — Wondersteve | `badge_ss_complete_wondersteve.png` |
| `badge_ss_complete_harley_steve` | Super Strengths — Harley Steve | `badge_ss_complete_harley_steve.png` |
| `badge_ss_winner_steverman` | Super Strengths Winner — Steverman | `badge_ss_winner_steverman.png` |
| `badge_ss_winner_supersteve` | Super Strengths Winner — Supersteve | `badge_ss_winner_supersteve.png` |
| `badge_ss_winner_wondersteve` | Super Strengths Winner — Wondersteve | `badge_ss_winner_wondersteve.png` |
| `badge_ss_winner_harley_steve` | Super Strengths Winner — Harley Steve | `badge_ss_winner_harley_steve.png` |

Badge images are to be cropped from `superstrenghtsv3.png` and saved as individual PNGs with transparent backgrounds, following the same process used for existing Quest Log badges (see MYF-148 for the correct export method).

**Coin values** — to be confirmed, but suggested:

| Badge | Coins |
|---|---|
| `badge_ss_complete_*` | 10 (matches `badge_solution_lens`) |
| `badge_ss_winner_*` | 15 (winner premium) |

---

### 19.4 Award Logic

Handled in a new `class-ss-badges.php` file, mirroring `class-lens-badges.php` exactly.

```php
class MFSD_SS_Badges {

    const DESIGNS      = ['steverman', 'supersteve', 'wondersteve', 'harley_steve'];
    const COINS_COMPLETE = 10;
    const COINS_WINNER   = 15;

    public static function award_completion( $student_id, $game_id ) {
        // Called from summary screen AJAX — same pattern as MFSD_Lens_Badges::award_completion()
        // Checks: is_user_logged_in, game status = complete, student_id matches
        // Calls: do_award( $student_id, 'complete' )
    }

    public static function award_winner( $student_id, $game_id ) {
        // Called when game end condition is met and winner_player_id = student
        // Tied students in all_match mode: both receive the winner badge
        // Calls: do_award( $student_id, 'winner' )
    }

    public static function do_award( $student_id, $type ) {
        if ( !class_exists('MFSD_Quest_Log_DB') || !class_exists('MFSD_Quest_Log_Wallet') ) {
            return false;
        }

        $design = self::DESIGNS[ array_rand( self::DESIGNS ) ];
        $slug   = 'badge_ss_' . $type . '_' . $design;
        $coins  = $type === 'winner' ? self::COINS_WINNER : self::COINS_COMPLETE;

        $db = new MFSD_Quest_Log_DB();
        if ( $db->has_badge( $student_id, 'badge_ss_' . $type . '_' ) ) {
            // Check if student already has ANY design of this badge type — don't award twice
            return false;
        }

        $awarded = $db->award_badge( $student_id, $slug, $coins );
        if ( $awarded ) {
            $wallet = new MFSD_Quest_Log_Wallet();
            $wallet->earn( $student_id, $slug, $coins, 'Super Strengths ' . $type . ' badge earned' );
        }

        return $awarded ? $slug : false;
        // Returns the slug so JS can display the correct badge image on the summary screen
    }
}
```

**Note on `has_badge` prefix check:** The Quest Log `has_badge()` method currently checks for an exact slug match. It will need a prefix-match variant (or a wrapper) to correctly detect "does this student already have any design of `badge_ss_complete_*`". This is a small Quest Log update — a new method `has_badge_type( $student_id, $prefix )` that checks `LIKE 'badge_ss_complete_%'`. This should be raised as a Quest Log task before Phase F.

---

### 19.5 Summary Screen Badge Display

The badge image and earned message are shown at the bottom of the student summary screen, after the AI panel and chat widget — same position as Solution Lens.

The awarded slug is returned from the `award_badge` AJAX call and used to construct the image URL:

```javascript
// JS — on award_badge response
const badgeUrl = `${MFSD_SS_CFG.pluginUrl}images/${data.badge_slug}.png`;
```

If the student already had the badge (repeat visit to summary), the badge is shown silently without re-animating. The `badge_earned: false` response suppresses the animation; the badge image is still displayed if the student has it.

**Winner badge display:** If the student earned both badges, both are shown side by side with a small label beneath each ("Completed" / "Winner").

**Demo mode:** Only the completion badge is shown — there is no winner badge for demo mode.

**Parents:** The badge section is not rendered in the parent summary view at all.

---

## 20. Migration Notes — Snap Mode

### What happens to existing snap games

Snap tables are **left in the database** — they are not dropped by the v5 install routine. Any game currently in `playing` state in the old system will be left in that state but the frontend will no longer route to snap screens, so it effectively freezes gracefully.

**Recommendation:** Before deploying v5, run a manual SQL update to close any open snap games:

```sql
UPDATE wp_mfsd_ss_games SET status = 'complete'
WHERE mode = 'snap' AND status != 'complete';
```

### Admin UI

The snap settings section in the admin page is kept but visually marked as **"Deprecated — Snap mode has been replaced by Memory mode"** with a muted appearance. Settings are not deleted so the data is preserved.

### Snap cron

The `mfsd_ss_timeout_check` cron event that ran the snap session expiry is **deregistered** on plugin activation in v5. The new cron event is `mfsd_ss_turn_timeout_check` (memory turn timeout).

---

## 21. Implementation Phases

To manage risk and allow testing at each stage, the build is divided into phases.

### Phase A — Database & Admin (no user-facing changes)
- Install 8 new tables via `class-ss-db.php` (7 core + `mfsd_sm_demo_rationale`)
- Add `game_type` column to `mfsd_sm_games`
- Add new admin settings sections to `admin-page.php` (memory mode, demo mode, turn settings, SteveGPT IDs)
- Register all 7 SteveGPT option keys (see §4.5): welcome intro, welcome chat, student summary, parent summary, family chat, demo picker, demo summary
- Deregister snap cron; register memory turn cron
- **Deliverable:** `class-ss-db.php` (v5), `admin-page.php` (v5)

### Phase B — Submission Flow (Phase 1 + Phase 2)
- `class-ss-memory.php` — Phase 1 save/submit, Phase 2 save/submit, dealing
- `class-ss-api.php` — `/memory/self-save`, `/memory/self-submit`, updates to `/state`
- JS — `renderSelfIntro()`, `renderSelfWrite()`, updated `renderSubmissionOverview()`, `renderSubmissionWaiting()`
- **Deliverable:** Updated `class-ss-api.php`, `class-ss-memory.php` (Phase 1+2), JS (submission screens)

### Phase C — Board & Game Play
- `class-ss-memory.php` — `deal_board()`, `flip_card()`, match detection, scoring, turn rotation, timeout check
- `class-ss-api.php` — `/memory/board`, `/memory/flip`, `/memory/heartbeat`, `/memory/state`
- JS — `renderBoard()`, `renderAwayNotice()`, `renderGameOver()`
- CSS — board grid, card flip animation, match moment, presence indicators
- **Deliverable:** Full playable family game

### Phase D — Summary & AI (Family)
- `class-ss-summary.php` — data builder, Steve prompt, Solution Lens fetch, badge award
- `class-ss-api.php` — `/memory/summary`, `/memory/award-badge`
- JS — `renderSummary()` with student/parent tabs
- CSS — summary screen, tabs, AI panel
- **Deliverable:** Complete end-to-end family game

### Phase E — Demo Mode
- `class-ss-demo.php` — full class: prerequisite check, Steve pick generation, data fetchers, board dealing, summary builder
- Confirm Word Association and Who Am I schema before starting — safe fallbacks must be in place before any Steve API call is made
- `class-ss-api.php` — all `/demo/*` routes
- JS — `renderDemoSelfWrite()`, `renderSteveThinking()`, `renderDemoBoard()`, `renderDemoTimeout()`, `renderDemoSummary()`
- CSS — Steve rationale cards, special match animation, demo-specific summary panels
- **Deliverable:** Complete end-to-end demo mode

### Phase F — QA & Polish
- Test family mode with 2, 3 and 4-player families
- Test all three family game end conditions
- Test demo mode with all three prior tasks complete and with partial data (fallback paths)
- Test async turn timeout on slow connections
- Mobile layout check on board grids (4-column minimum)
- Validator edge cases on self-strength free-text
- Steve fallback JSON parse failure path
- Theme check — student and parent CSS scoping, both in same browser session via role switch
- **Deliverable:** Production-ready versioned files

---

## Appendix A — Match Moment

When a pair is matched, the frontend triggers:
1. Both cards play a brief scale-up animation (CSS keyframe, ~300ms)
2. A flash banner appears over the board — text depends on mode:
   - Family mode: **"⭐ Match! [Author] thinks [Target] is… [Strength]"**
   - Demo mode (both student cards): **"✨ You believe you are… [Strength]"**
   - Demo mode (both Steve cards): **"🤖 Steve thinks you are… [Strength]"**
   - Demo mode (student + Steve same strength — special match): **"⭐ You and Steve both see this in you… [Strength]"** — held 3 seconds, distinct gold animation
3. If in family mode and the matched strength also appears in the active player's self-strengths: **"✨ And [Name] sees this in themselves too!"** appended
4. Banner auto-dismisses after 2.5 seconds (3 seconds for special demo match)
5. The matched cards remain face-up but are greyed/muted (still readable)
6. Family mode only — if the player scored the winning point in `first_to_x` mode, the board dims and a **"🏆 You win!"** overlay fires immediately

---

## Appendix B — Board Grid Sizing

The matching pairs plugin (uploaded as reference) uses responsive CSS grids. We adopt the same approach.

| Mode | Players | Pairs | Tiles | Recommended grid |
|---|---|---|---|---|
| Demo | 1 (student + Steve) | 10 | 20 | 4 × 5 |
| Family | 2 (student + 1 parent) | 10 | 20 | 4 × 5 |
| Family | 3 (student + 2 parents) | 30 | 60 | 6 × 10 (desktop) / 4 × 15 (mobile) |
| Family | 4 players | 60 | 120 | Admin should use `first_to_x` for large families |

For very large boards, a **"Show only unmatched"** toggle will be added (Phase F) that hides matched pairs to reduce scrolling.

---

## Appendix C — Prerequisite Task Slugs

| Task | Slug used in ordering system | Data location |
|---|---|---|
| Solution Lens | `solution_lens` | `mfsd_lens_sessions`, `mfsd_lens_responses` |
| Word Association | `word_association` | **To be confirmed against plugin schema** |
| Who Am I | `who_am_i` | **To be confirmed against plugin schema** |
| Super Strengths | `super_strengths` | `mfsd_sm_games` |

The ordering gate checks all three in sequence. The locked message names whichever tasks remain incomplete so the student knows exactly what to do next.

---

## Appendix D — Version History

| Version | Notes |
|---|---|
| 4.6.0 | Snap mode added |
| 5.0.0 | Snap replaced with Memory game; self-strengths phase added; differentiated summary; SteveGPT integration; demo mode with Steve AI picks from prior course data |
| 5.5.8 | MYF-233: Demo compare panel column heading alignment (min-height). MYF-234: Badge click-to-zoom modal on summary screen. MYF-235: Summary nav buttons constrained to max-width 480px via `.ss-summary-nav` CSS class. MYF-236: Student age in chat widgets resolved via SteveGPT `content_aware` — no code change. |
| 5.5.9 | MYF-238 (part 1): `.ss-btn-full` globally constrained to `max-width: 480px; display: flex; margin: auto` — all primary CTA buttons across every screen now consistent size. |
| 5.5.10 | MYF-238 (part 2): Summary nav buttons now side-by-side (row layout). Demo intro button renamed "Play 1 player with Steve". Spacing increased between demo button and chatbot. Strengths picker Save button spacing increased. |

---

*End of specification — v5.0.0 — MFSD Super Strengths Cards*

