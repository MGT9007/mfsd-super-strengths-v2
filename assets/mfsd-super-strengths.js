/* =============================================================================
   MFSD SUPER STRENGTHS CARDS — FRONTEND GAME ENGINE
   State machine: submission → dealing → playing → complete
   ============================================================================= */
(function () {
  'use strict';

  function boot() {
    const cfg  = window.MFSD_SS_CFG || {};
    const root = document.getElementById('mfsd-ss-root');
    if (!root) return;

  // ---- State ----------------------------------------------------------------
  let state = {
    // Memory game (v5)
    gameId:               null,
    gameKey:              null,   // set for memory games; null for legacy guessing game
    gameType:             null,   // 'family' | 'demo'
    gameStatus:           null,   // submission_self | submission_others | dealing | playing | complete
    memoryMode:           null,   // 'all_match' | 'first_to_x' | 'timed'
    draftSelf:            [],     // Phase 1 self-strength picks
    board:                [],     // array of position objects from /memory/board
    currentTurnPlayerId:  null,   // player.id of whoever has the current turn
    gameEndsAt:           null,   // ISO datetime string (timed mode only)
    heartbeatTimer:       null,   // setInterval reference
    pendingFlipPos:       null,   // position of flip 1 while awaiting flip 2
    winnerPlayerId:       null,   // set when game_complete
    // Legacy guessing game
    gameMode:      cfg.gameMode || 'full',
    hand:          [],
    currentTurn:   null,
    selectedCard:  null,
    myVote:        null,
    isConfident:   false,
    // Shared
    player:        null,
    allPlayers:    [],
    strengths:     {},     // { category: [{id,text}] }
    draftCards:    {},     // Phase 2 cards { target_player_id: [{type,text,strength_id}] }
    currentTarget: null,
    pollTimer:     null,
  };

  // ---- Utility --------------------------------------------------------------
  const el = (tag, cls, txt) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (txt !== undefined) n.textContent = txt;
    return n;
  };

  const html = (tag, cls, innerHTML) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (innerHTML !== undefined) n.innerHTML = innerHTML;
    return n;
  };

  async function api(endpoint, method = 'GET', body = null) {
    const opts = {
      method,
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
    };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(cfg.restUrl + endpoint, opts);
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || 'API error ' + res.status);
    }
    return res.json();
  }

  function loading(msg = 'Loading…') {
    const ov = el('div', 'ss-loading-overlay');
    ov.innerHTML = '<div class="ss-spinner"></div><div class="ss-loading-text">' + msg + '</div>';
    document.body.appendChild(ov);
    return ov;
  }
  function unload(ov) { if (ov && ov.parentNode) ov.remove(); }

  function stopPoll() { if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; } }

  function startPoll(fn, ms = 5000) {
    stopPoll();
    state.pollTimer = setInterval(fn, ms);
  }

  function topBar() {
    const bar = el('div', 'ss-topbar');
    bar.innerHTML = '<div class="ss-topbar-logo">My Future <span>Self</span></div>' +
      '<div class="ss-topbar-game">🃏 Super Strengths Cards</div>' +
      '<div class="ss-topbar-user">👤 ' + escHtml(cfg.displayName) + '</div>';
    return bar;
  }

  function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function render(screenEl) {
    stopPoll();
    const screen = el('div', 'ss-screen');
    screen.appendChild(topBar());
    screen.appendChild(screenEl);
    const wrap = el('div', 'ss-wrap');
    wrap.appendChild(screen);
    root.replaceChildren(wrap);
  }

  // ---- INIT -----------------------------------------------------------------
  async function init() {
    const lv = loading();
    try {
      // v5: check memory game state first
      const data = await api('memory/state');
      unload(lv);

      if (data.status !== 'no_game') {
        // Active or completed memory game
        state.gameId              = data.game_id;
        state.gameKey             = data.game_key;
        state.gameType            = data.game_type;
        state.gameStatus          = data.status;
        state.memoryMode          = data.memory_mode;
        state.player              = data.player;
        state.allPlayers          = data.all_players || [];
        state.currentTurnPlayerId = data.current_turn_player_id || null;
        state.gameEndsAt          = data.game_ends_at           || null;
        state.winnerPlayerId      = data.winner_player_id       || null;

        if (data.status === 'submission_self' || data.status === 'submission_others') {
          await loadStrengths();
        }

        routeToScreen();
        return;
      }

      // No memory game — check for legacy guessing game
      try {
        const legacy = await api('state');
        if (legacy.status !== 'no_game') {
          state.gameId     = legacy.game_id;
          state.gameStatus = legacy.status;
          state.gameMode   = legacy.game_mode;
          state.player     = legacy.player;
          state.allPlayers = legacy.all_players || [];
          state.hand       = legacy.hand || [];
          if (legacy.status === 'submission') await loadStrengths();
          routeToScreen();
          return;
        }
      } catch (_) {}

      // Resume active demo game if one exists
      if (cfg.demoModeEnabled) {
        try {
          const demoSt = await api('demo/status');
          if (demoSt.prerequisites_met && demoSt.game && demoSt.game.found) {
            state.gameId   = demoSt.game.game_id;
            state.gameType = 'demo';
            if (demoSt.game.status === 'complete') {
              state.gameStatus = 'complete';
              renderDemoGameOver();
            } else {
              state.gameStatus = 'playing';
              await renderDemoBoard(true);
            }
            return;
          }
        } catch(_) {}
      }

      renderNoGame(data);
    } catch (e) {
      unload(lv);
      renderError(e.message);
    }
  }

  async function loadStrengths() {
    const data = await api('strengths');
    state.strengths = data.strengths || {};
  }

  function routeToScreen() {
    switch (state.gameStatus) {
      // ── Memory game statuses (v5) ────────────────────────────────────────
      case 'submission_self':
        if (!state.player.self_submitted) {
          if ((state.player.self_count || 0) > 0) renderSelfWrite();
          else renderGameIntro();
        } else {
          renderSelfWaiting();
        }
        break;
      case 'submission_others':
        renderMemorySubmissionOverview();
        break;
      // ── Shared ──────────────────────────────────────────────────────────
      case 'dealing':    renderDealing(); break;
      // ── Legacy guessing game statuses ───────────────────────────────────
      case 'submission': renderSubmissionIntro(); break;
      case 'playing':
        if (state.gameKey) renderBoard();
        else if (state.gameMode === 'snap') renderSnapWaiting();
        else renderGameTable();
        break;
      case 'complete':
        if (state.gameKey) renderGameOver(state.winnerPlayerId);
        else renderFinalResults();
        break;
      default:           renderNoGame({status:'no_game', message:'Unknown game state.'});
    }
  }

  // =========================================================================
  // NO GAME — smart screen based on viewer_role returned by state
  // =========================================================================
  function renderNoGame(data) {
    const body = el('div', 'ss-screen-body');
    const isStudentViewer = data.viewer_role === 'student' || data.viewer_role === 'unknown';

    // ── Demo mode takes priority: show demo entry for any student viewer ───
    // Students without linked parents get viewer_role:'unknown' from the API,
    // so we must check demo mode BEFORE the can_start gate.
    if (cfg.demoModeEnabled && isStudentViewer) {
      const inner = el('div', '');
      inner.style.padding = '28px 24px';
      const topAvatarHtml = cfg.steveAvatarUrl
        ? `<img src="${escHtml(cfg.steveAvatarUrl)}" alt="Steve" style="width:80px;height:80px;border-radius:50%;object-fit:cover;margin-bottom:12px;">`
        : `<div style="font-size:52px;margin-bottom:12px;">🤖</div>`;
      inner.innerHTML = `
        <div style="text-align:center;margin-bottom:24px;">
          ${topAvatarHtml}
          <h2 style="color:#fff;margin:0 0 8px;font-size:22px;">Super Strengths Cards</h2>
          <p style="color:var(--ss-text-dim);font-size:14px;margin:0;">Discover your strengths with Steve</p>
        </div>
        <div class="ss-card" style="margin-bottom:16px;">${renderStaticIntroRules()}</div>
        <div style="background:rgba(109,63,192,0.1);border:1px solid rgba(109,63,192,0.25);border-radius:8px;padding:14px;margin-bottom:20px;font-size:13px;line-height:1.6;color:var(--ss-text);">
          Try the demo — Steve will select strength cards for you based on your previous activities. No family members needed to get started.
        </div>
      `;
      const demoBtn = el('button', 'ss-btn ss-btn-demo ss-btn-full', '🤖 Try Demo with Steve');
      demoBtn.onclick = handleDemoStart;
      inner.appendChild(demoBtn);

      if (cfg.welcomeChatChatbotId) {
        const chatPlaceholder = el('div', '');
        chatPlaceholder.id = 'ss-welcome-chat-placeholder';
        chatPlaceholder.style.marginTop = '16px';
        inner.appendChild(chatPlaceholder);
      }

      body.appendChild(inner);
      render(body);

      if (cfg.welcomeChatChatbotId) {
        initWelcomeChatWidget(document.getElementById('ss-welcome-chat-placeholder'));
      }
      return;

    // ── Student with linked parents: can start a family game ───────────────
    } else if (data.can_start && data.viewer_role === 'student') {
      const inner = el('div', '');
      inner.style.padding = '28px 24px';
      inner.innerHTML = `
        <div style="text-align:center;margin-bottom:24px;">
          <div style="font-size:52px;margin-bottom:12px;">🃏</div>
          <h2 style="color:#fff;margin:0 0 8px;font-size:22px;">Super Strengths Cards</h2>
          <p style="color:var(--ss-text-dim);font-size:14px;margin:0;">A family card game about your strengths</p>
        </div>
        <div class="ss-card" style="margin-bottom:16px;">${renderStaticIntroRules()}</div>
        <div class="ss-section-label" style="margin-bottom:10px;">Your family players</div>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;">
          ${data.linked_parents.map(p =>
            `<div class="ss-player-pill">
              <span style="font-size:16px;">${roleEmoji(p.role)}</span>
              ${escHtml(p.display_name)}
              <span style="color:var(--ss-text-dim);font-size:11px;">— ${escHtml(p.role)}</span>
            </div>`
          ).join('')}
        </div>
        <div style="background:rgba(109,63,192,0.1);border:1px solid rgba(109,63,192,0.25);border-radius:8px;padding:14px;margin-bottom:20px;font-size:13px;line-height:1.6;color:var(--ss-text);">
          When you start the game, your family will be able to log in and write Super Strength cards too. The game begins once everyone has submitted their cards.
        </div>
      `;
      const startBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '🚀 Start Game');
      startBtn.onclick = async () => {
        startBtn.disabled = true;
        startBtn.textContent = 'Starting…';
        const lv = loading('Setting up your game…');
        try {
          const result = await api('memory/start', 'POST');
          unload(lv);
          if (result.ok && result.status !== 'no_game') {
            state.gameId     = result.game_id;
            state.gameKey    = result.game_key;
            state.gameType   = result.game_type;
            state.gameStatus = result.status;
            state.memoryMode = result.memory_mode;
            state.player     = result.player;
            state.allPlayers = result.all_players || [];
            await loadStrengths();
            routeToScreen();
          } else {
            renderError(result.message || 'Could not start game. Please try again.');
          }
        } catch (e) {
          unload(lv);
          startBtn.disabled = false;
          startBtn.textContent = '🚀 Start Game';
          renderError(e.message);
        }
      };
      inner.appendChild(startBtn);
      body.appendChild(inner);

    // ── Parent: waiting for student to start ───────────────────────────────
    } else if (data.viewer_role === 'parent') {
      const inner = el('div', '');
      inner.style.cssText = 'padding:40px 24px;text-align:center;';
      inner.innerHTML = `
        <div style="font-size:48px;margin-bottom:16px;">⏳</div>
        <h2 style="color:#fff;margin:0 0 12px;font-size:20px;">Game Not Started Yet</h2>
        <p style="color:var(--ss-text-dim);font-size:14px;line-height:1.6;max-width:400px;margin:0 auto 24px;">
          ${escHtml(data.message)}
        </p>
        <div class="ss-waiting" style="justify-content:center;">
          <div class="ss-dots"><span></span><span></span><span></span></div>
          Checking for a new game…
        </div>
      `;
      body.appendChild(inner);
      // Poll every 15s so parent sees the game appear without refreshing
      startPoll(async () => {
        try {
          const fresh = await api('memory/state');
          if (fresh.status !== 'no_game') {
            stopPoll();
            state.gameId     = fresh.game_id;
            state.gameKey    = fresh.game_key;
            state.gameType   = fresh.game_type;
            state.gameStatus = fresh.status;
            state.memoryMode = fresh.memory_mode;
            state.player     = fresh.player;
            state.allPlayers = fresh.all_players || [];
            if (fresh.status === 'submission_self' || fresh.status === 'submission_others') {
              await loadStrengths();
            }
            routeToScreen();
          }
        } catch(_) {}
      }, 15000);

    // ── Fallback: no active game and no relevant role ──────────────────────
    } else {
      const inner = el('div', '');
      inner.style.cssText = 'padding:40px 24px;text-align:center;';
      inner.innerHTML = `
        <div style="font-size:48px;margin-bottom:16px;">🃏</div>
        <h2 style="color:#fff;margin:0 0 12px;">Super Strengths Cards</h2>
        <p style="color:var(--ss-text-dim);font-size:14px;line-height:1.6;">
          ${escHtml(data.message || 'No active game found.')}
        </p>
      `;
      body.appendChild(inner);
    }

    render(body);
  }

  function roleEmoji(role) {
    const map = { student:'🎓', parent:'👨‍👩‍👧', carer:'🤝', sibling:'👫', other:'👤' };
    return map[role] || '👤';
  }

  // =========================================================================
  // MEMORY GAME — INTRO (MG0) — shown before Phase 1 self-strengths
  // Chatbot welcome if configured; otherwise static rules.
  // =========================================================================
  function renderGameIntro() {
    const isStudent   = state.player.role === 'student';
    const hasIntroBot = !!(cfg.welcomeIntroChatbotId);
    const body        = el('div', 'ss-screen-body');

    body.innerHTML = `
      <div class="ss-game-header">
        <div class="ss-game-title">🃏 Super Strengths Memory</div>
        <div class="ss-game-sub">${isStudent ? 'Family Memory Game' : 'You\'ve been invited!'}</div>
      </div>
    `;
    const inner = el('div', '');
    inner.style.padding = '20px';

    const student = state.allPlayers.find(p => p.role === 'student');

    if (!isStudent) {
      inner.innerHTML += `
        <div style="background:rgba(201,162,39,0.08);border:1px solid rgba(201,162,39,0.2);border-radius:8px;padding:14px;margin-bottom:16px;font-size:13px;line-height:1.6;color:var(--ss-text);">
          <strong style="color:var(--ss-gold-lt);">${escHtml(student ? student.display_name : 'The student')}</strong>
          has started a Super Strengths Memory game! Start by choosing 5 strengths that describe <em>you</em>, then write 5 strength cards for each other player.
        </div>
      `;
    }

    // Steve AI intro panel — shown if chatbot is configured, otherwise static
    const introPanel = el('div', 'ss-card');
    introPanel.style.marginBottom = '16px';

    if (hasIntroBot) {
      introPanel.innerHTML = '<div class="ss-section-label" style="margin-bottom:8px;">💬 A message from Steve</div>' +
        '<div id="ss-intro-msg" style="color:var(--ss-text);font-size:14px;line-height:1.7;">' +
        '<div class="ss-waiting"><div class="ss-dots"><span></span><span></span><span></span></div> Steve is preparing your welcome…</div></div>';
      inner.appendChild(introPanel);

      // Fetch Steve's intro message async (fallback to static rules on error)
      (async () => {
        try {
          const res = await api('memory/intro', 'GET');
          const msgEl = document.getElementById('ss-intro-msg');
          if (msgEl) msgEl.innerHTML = `<p style="white-space:pre-wrap;">${escHtml(res.intro_text || '')}</p>`;
        } catch (_) {
          const msgEl = document.getElementById('ss-intro-msg');
          if (msgEl) msgEl.innerHTML = renderStaticIntroRules();
        }
      })();
    } else {
      introPanel.innerHTML = '<div class="ss-section-label" style="margin-bottom:8px;">How it works</div>' +
        renderStaticIntroRules();
      inner.appendChild(introPanel);
    }

    // Players in game
    const playersDiv = el('div', '');
    playersDiv.innerHTML = `
      <div class="ss-section-label" style="margin-bottom:10px;">Players in this game</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
        ${state.allPlayers.map(p =>
          `<div class="ss-player-pill">
            <span style="font-size:15px;">${roleEmoji(p.role)}</span>
            ${escHtml(p.display_name)}
            ${p.id === state.player.id ? '<span style="color:var(--ss-text-dim);font-size:10px;">— you</span>' : ''}
          </div>`
        ).join('')}
      </div>
    `;
    inner.appendChild(playersDiv);

    const startBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '✨ Pick My Strengths →');
    startBtn.onclick = renderSelfWrite;
    inner.appendChild(startBtn);

    if (cfg.welcomeChatChatbotId) {
      const chatPlaceholder = el('div', '');
      chatPlaceholder.id = 'ss-welcome-chat-placeholder';
      chatPlaceholder.style.marginTop = '16px';
      inner.appendChild(chatPlaceholder);
    }

    body.appendChild(inner);
    render(body);

    if (cfg.welcomeChatChatbotId) {
      initWelcomeChatWidget(document.getElementById('ss-welcome-chat-placeholder'));
    }
  }

  function renderStaticIntroRules() {
    const rules = [
      'Start by picking <strong>5 strengths</strong> that describe yourself.',
      'Then write <strong>5 strength cards</strong> for each person in your game.',
      'Once everyone has submitted, the cards are shuffled into a memory board.',
      'Take turns flipping pairs — match a card to score a point!',
      'The player with the most matched pairs wins.',
    ];
    return rules.map((r, i) => `<div class="ss-intro-rule"><div class="ss-intro-rule-num">${i+1}</div><span>${r}</span></div>`).join('');
  }

  function initWelcomeChatWidget(placeholder) {
    if (!placeholder || !cfg.welcomeChatChatbotId) return;

    const widget = el('div', 'ss-chat-widget');

    const chatHeader = el('div', 'ss-chat-header');
    chatHeader.innerHTML = cfg.steveAvatarUrl
      ? `<img src="${escHtml(cfg.steveAvatarUrl)}" alt="Steve" class="ss-chat-avatar-img"><span class="ss-chat-name">Steve</span>`
      : '<span class="ss-chat-avatar-text">🤖</span><span class="ss-chat-name">Steve</span>';
    widget.appendChild(chatHeader);

    // Wrap each Steve message in a row with his avatar beside it
    function makeAiRow(text) {
      const row = el('div', 'ss-chat-msg-row');
      if (cfg.steveAvatarUrl) {
        const img = document.createElement('img');
        img.src       = cfg.steveAvatarUrl;
        img.alt       = 'Steve';
        img.className = 'ss-chat-msg-avatar';
        row.appendChild(img);
      }
      const bubble = el('div', 'ss-chat-msg ai');
      bubble.textContent = text;
      row.appendChild(bubble);
      return row;
    }

    function makeTypingRow() {
      const row = el('div', 'ss-chat-msg-row');
      if (cfg.steveAvatarUrl) {
        const img = document.createElement('img');
        img.src       = cfg.steveAvatarUrl;
        img.alt       = 'Steve';
        img.className = 'ss-chat-msg-avatar';
        row.appendChild(img);
      }
      const bubble = html('div', 'ss-chat-msg ai typing-dots',
        '<div class="ss-dots"><span></span><span></span><span></span></div>');
      row.appendChild(bubble);
      return row;
    }

    const msgs = el('div', 'ss-chat-messages');
    msgs.appendChild(makeAiRow('Hi! Ask me anything about the game — I\'m here to help before you get started.'));

    const chips = [
      'What if my family haven\'t written cards yet?',
      'How long does it take?',
      'What happens at the end?',
    ];
    const chipsDiv = el('div', 'ss-chat-chips');
    chips.forEach(chip => {
      const btn = el('button', 'ss-chat-chip', chip);
      btn.onclick = () => { chipsDiv.remove(); sendWelcomeMsg(chip); };
      chipsDiv.appendChild(btn);
    });
    msgs.appendChild(chipsDiv);
    widget.appendChild(msgs);

    const inputRow = el('div', 'ss-chat-input-row');
    const input    = el('textarea', 'ss-chat-input');
    input.placeholder = 'Ask me anything…';
    input.rows = 1;
    const sendBtn = el('button', 'ss-chat-send-btn', 'Send');

    const ajaxUrl = (window.stevegpt || {}).ajax_url || '/wp-admin/admin-ajax.php';
    const nonce   = (window.stevegpt || {}).nonce   || '';

    // Build game context once; prepend to the first message so Steve knows the mode
    let contextPrefix = '';
    {
      const mode     = cfg.demoModeEnabled ? 'demo' : 'family';
      const timeSecs = (cfg.demoTimeLimitMins || 3) * 60;
      contextPrefix  = `STUDENT CONTEXT:\nName: ${cfg.displayName}\nAge: ${cfg.playerAge || 'unknown'}\n\nGAME CONTEXT:\nMode: ${mode}\n`;
      if (cfg.demoModeEnabled) {
        contextPrefix += `Time limit: ${timeSecs} seconds\nBoard: 20 tiles (5 student picks + 5 Steve picks, each duplicated into pairs)\n`;
      }
      contextPrefix += '\nQuestion: ';
    }
    let contextAttached = false;

    async function sendWelcomeMsg(msg) {
      if (!msg || sendBtn.disabled) return;
      input.value = '';
      input.style.height = 'auto';
      sendBtn.disabled = true;

      const userMsg = el('div', 'ss-chat-msg user');
      userMsg.textContent = msg;
      msgs.appendChild(userMsg);

      // Prepend context to first message only — shown cleanly in UI, sent with context to AI
      const serverMsg = contextAttached ? msg : (contextAttached = true, contextPrefix + msg);

      const typingRow = makeTypingRow();
      msgs.appendChild(typingRow);
      msgs.scrollTop = msgs.scrollHeight;

      try {
        const res  = await fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          credentials: 'same-origin',
          body: new URLSearchParams({
            action:     'stevegpt_send_message',
            nonce,
            chatbot_id: cfg.welcomeChatChatbotId,
            message:    serverMsg,
          }),
        });
        const json = await res.json();
        typingRow.remove();
        msgs.appendChild(makeAiRow(json.success ? json.data.response : 'Sorry, I had trouble with that. Please try again.'));
      } catch (_) {
        typingRow.remove();
        msgs.appendChild(makeAiRow('Connection error. Please try again.'));
      }
      sendBtn.disabled = false;
      msgs.scrollTop = msgs.scrollHeight;
    }

    sendBtn.onclick = () => sendWelcomeMsg(input.value.trim());
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendWelcomeMsg(input.value.trim()); }
    });
    input.addEventListener('input', () => {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 100) + 'px';
    });

    inputRow.appendChild(input);
    inputRow.appendChild(sendBtn);
    widget.appendChild(inputRow);
    placeholder.appendChild(widget);
  }

  // =========================================================================
  // MEMORY GAME — PHASE 1: SELF-WRITE (MG1)
  // Player picks 5 strengths that describe themselves.
  // =========================================================================
  function renderSelfWrite() {
    const required = 5;
    const body = el('div', 'ss-screen-body');
    body.innerHTML = `
      <div class="ss-game-header">
        <div class="ss-game-title">✨ Your Strengths</div>
        <div class="ss-game-sub" id="ss-self-count">${state.draftSelf.length}/${required} selected</div>
      </div>
    `;
    const inner = el('div', '');
    inner.style.padding = '20px';

    inner.innerHTML += `
      <div class="ss-card" style="margin-bottom:16px;font-size:13px;line-height:1.6;color:var(--ss-text);">
        Before writing about others, tell us about <strong>you</strong>.
        Pick the 5 strengths from the list below that you feel describe you best.
      </div>
    `;

    // Selected tags
    const tagLabel = el('div', 'ss-section-label', 'Your selected strengths');
    inner.appendChild(tagLabel);
    const tagCloud = el('div', 'ss-tag-cloud');
    tagCloud.id = 'ss-self-tags';
    inner.appendChild(tagCloud);
    refreshSelfTags(tagCloud, required);

    // Search
    const search = el('input', 'ss-input');
    search.placeholder = '🔍 Search strengths…';
    search.style.marginBottom = '10px';
    search.oninput = () => filterSelfStrengths(search.value);
    inner.appendChild(search);

    // Category tabs
    const cats   = Object.keys(state.strengths);
    const tabBar = el('div', 'ss-cat-tabs');
    const allTab = el('button', 'ss-cat-tab active', 'All');
    allTab.dataset.cat = 'all';
    tabBar.appendChild(allTab);
    cats.forEach(cat => {
      const shortNames = { 'Creative & Expressive': 'Creative', 'Mind & Learning': 'Mind', 'Leadership & Drive': 'Leadership', 'Practical & Dependable': 'Practical', 'Growth & Mindset': 'Growth', 'Social & Caring': 'Social' };
      const btn = el('button', 'ss-cat-tab' + (cat === 'Family' ? ' family-tab' : ''), shortNames[cat] || cat);
      btn.dataset.cat = cat;
      tabBar.appendChild(btn);
    });
    inner.appendChild(tabBar);

    tabBar.addEventListener('click', e => {
      const btn = e.target.closest('.ss-cat-tab');
      if (!btn) return;
      tabBar.querySelectorAll('.ss-cat-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      filterSelfStrengths(search.value, btn.dataset.cat);
    });

    const grid = el('div', '');
    grid.id = 'ss-self-grid';
    grid.style.cssText = 'max-height:260px;overflow-y:auto;padding-right:4px;';
    inner.appendChild(grid);
    renderSelfStrengthList(grid);

    // Save + submit
    const saveBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '💾 Save my strengths');
    saveBtn.style.marginTop = '16px';
    saveBtn.id = 'ss-self-save';
    saveBtn.onclick = () => saveSelfStrengths(false);
    inner.appendChild(saveBtn);

    const submitBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '✅ Submit & Continue →');
    submitBtn.style.marginTop = '8px';
    submitBtn.id = 'ss-self-submit';
    if (state.draftSelf.length < required) submitBtn.disabled = true;
    submitBtn.onclick = () => saveSelfStrengths(true);
    inner.appendChild(submitBtn);

    body.appendChild(inner);
    render(body);

    function filterSelfStrengths(q, cat) {
      const activeCat = cat || tabBar.querySelector('.ss-cat-tab.active')?.dataset.cat || 'all';
      renderSelfStrengthList(document.getElementById('ss-self-grid'), activeCat, q);
    }
  }

  function renderSelfStrengthList(container, cat = 'all', query = '') {
    if (!container) return;
    const usedTexts = state.draftSelf.map(d => d.strength_text.toLowerCase());
    container.innerHTML = '';

    Object.entries(state.strengths).forEach(([category, items]) => {
      if (cat !== 'all' && cat !== category) return;
      const filtered = query ? items.filter(s => s.text.toLowerCase().includes(query.toLowerCase())) : items;
      if (!filtered.length) return;

      const label = el('div', 'ss-cat-label');
      label.style.color = category === 'Family' ? 'rgba(201,162,39,0.7)' : 'rgba(155,110,243,0.7)';
      label.textContent = category;
      container.appendChild(label);

      const grid = el('div', 'ss-strength-grid');
      filtered.forEach(s => {
        const used = usedTexts.includes(s.text.toLowerCase());
        const chip = el('div', 'ss-strength-chip' + (used ? ' used' : ''), s.text + (used ? ' ✓' : ''));
        if (!used) {
          chip.onclick = () => {
            if (state.draftSelf.length >= 5) return;
            state.draftSelf.push({ strength_id: s.id, strength_text: s.text });
            chip.classList.add('used');
            chip.textContent = s.text + ' ✓';
            chip.onclick = null;
            updateSelfUI();
          };
        }
        grid.appendChild(chip);
      });
      container.appendChild(grid);
    });
  }

  function refreshSelfTags(container, required) {
    container.innerHTML = '';
    if (!state.draftSelf.length) {
      container.innerHTML = '<span style="color:var(--ss-text-dim);font-size:12px;">No strengths selected yet — tap one below</span>';
      return;
    }
    state.draftSelf.forEach(d => {
      const tag = el('div', 'ss-tag');
      tag.innerHTML = escHtml(d.strength_text) + '<span class="ss-tag-remove" data-text="' + escHtml(d.strength_text) + '">×</span>';
      tag.querySelector('.ss-tag-remove').onclick = () => {
        state.draftSelf = state.draftSelf.filter(x => x.strength_text !== d.strength_text);
        refreshSelfTags(container, required);
        updateSelfUI();
        // Re-render grid to uncheck
        const grid = document.getElementById('ss-self-grid');
        if (grid) renderSelfStrengthList(grid, 'all');
      };
      container.appendChild(tag);
    });
  }

  function updateSelfUI() {
    const required = 5;
    const sub = document.getElementById('ss-self-count');
    if (sub) sub.textContent = state.draftSelf.length + '/' + required + ' selected';
    const submitBtn = document.getElementById('ss-self-submit');
    if (submitBtn) submitBtn.disabled = state.draftSelf.length < required;
    const tags = document.getElementById('ss-self-tags');
    if (tags) refreshSelfTags(tags, required);
  }

  async function saveSelfStrengths(andSubmit) {
    if (state.draftSelf.length === 0) { alert('Please select at least one strength first.'); return; }
    const lv = loading(andSubmit ? 'Submitting your strengths…' : 'Saving…');
    try {
      await api('memory/self-save', 'POST', {
        game_id:   state.gameId,
        strengths: state.draftSelf,
      });
      if (andSubmit) {
        if (state.draftSelf.length < 5) { unload(lv); alert('Please select 5 strengths before submitting.'); return; }
        const res = await api('memory/self-submit', 'POST', { game_id: state.gameId });
        unload(lv);
        if (res.all_submitted) {
          // All players done with Phase 1 — refresh state and go to Phase 2
          const fresh = await api('memory/state');
          state.gameStatus = fresh.status;
          state.player     = fresh.player || state.player;
          state.allPlayers = fresh.all_players || state.allPlayers;
        } else {
          state.player = Object.assign({}, state.player, { self_submitted: true });
        }
        routeToScreen();
      } else {
        unload(lv);
        state.player = Object.assign({}, state.player, { self_count: state.draftSelf.length });
      }
    } catch (e) {
      unload(lv);
      alert('Error: ' + e.message);
    }
  }

  // =========================================================================
  // MEMORY GAME — SELF WAITING (MG1W)
  // Player submitted Phase 1, waiting for others to finish.
  // =========================================================================
  function renderSelfWaiting() {
    const body = el('div', 'ss-screen-body');
    body.innerHTML = '<div class="ss-game-header"><div class="ss-game-title">✅ Strengths Submitted!</div><div class="ss-game-sub">Waiting for everyone else…</div></div>';

    const inner = el('div', '');
    inner.style.padding = '20px';

    const statusDiv = el('div', '');
    statusDiv.id = 'ss-self-wait-status';
    updateSelfWaitStatus(statusDiv);
    inner.appendChild(statusDiv);
    body.appendChild(inner);
    render(body);

    startPoll(async () => {
      try {
        const data = await api('memory/state');
        state.allPlayers = data.all_players || state.allPlayers;
        if (data.status === 'submission_others') {
          stopPoll();
          state.gameStatus = data.status;
          state.player     = data.player || state.player;
          routeToScreen();
        } else {
          updateSelfWaitStatus(document.getElementById('ss-self-wait-status'));
        }
      } catch (_) {}
    }, 8000);
  }

  function updateSelfWaitStatus(container) {
    if (!container) return;
    container.innerHTML = `
      <div class="ss-section-label" style="margin-bottom:10px;">Phase 1 — choosing their own strengths</div>
      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
        ${state.allPlayers.map(p =>
          `<div class="ss-player-pill">
            <span class="status-dot ${p.self_submitted ? 'submitted' : 'pending'}"></span>
            ${escHtml(p.display_name)}
            <span style="color:var(--ss-text-dim);font-size:10px;">${p.self_submitted ? '— done ✓' : '— choosing…'}</span>
          </div>`
        ).join('')}
      </div>
      <div class="ss-waiting"><div class="ss-dots"><span></span><span></span><span></span></div>
        Waiting for everyone to finish Phase 1…
      </div>
    `;
  }

  // =========================================================================
  // MEMORY GAME — PHASE 2 OVERVIEW (MG2)
  // Shown when game status = 'submission_others'
  // =========================================================================
  function renderMemorySubmissionOverview() {
    const targets     = state.allPlayers.filter(p => p.id !== state.player.id);
    const cardCounts  = state.player.card_counts || {};
    const required    = 5;
    const allComplete = targets.every(t => (cardCounts[t.id] || 0) >= required);

    const body = el('div', 'ss-screen-body');
    body.innerHTML = `
      <div class="ss-game-header">
        <div class="ss-game-title">✍️ Write Strength Cards</div>
        <div class="ss-game-sub">Phase 2 — ${required} cards per person</div>
      </div>
    `;
    const inner = el('div', '');
    inner.style.padding = '20px';

    // Phase 1 completion status
    const phase1Done = state.allPlayers.every(p => p.self_submitted);
    if (!phase1Done) {
      const pending1 = state.allPlayers.filter(p => !p.self_submitted);
      inner.innerHTML += `
        <div style="background:rgba(109,63,192,0.1);border:1px solid rgba(109,63,192,0.25);border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:var(--ss-text);">
          <strong style="color:var(--ss-gold-lt);">⏳ Phase 1 in progress</strong> —
          ${pending1.map(p => escHtml(p.display_name)).join(', ')} ${pending1.length === 1 ? 'is' : 'are'} still picking their own strengths.
          You can write cards for others in the meantime!
        </div>
      `;
    }

    // Target list
    const list = el('div', 'ss-target-list');
    targets.forEach(target => {
      const saved = cardCounts[target.id] || 0;
      const done  = saved >= required;
      const card  = el('div', 'ss-target-card' + (done ? ' complete' : ''));
      card.innerHTML = `
        <div>
          <div class="ss-target-name">${escHtml(target.display_name)}</div>
          <div class="ss-target-role">${escHtml(target.role)}</div>
        </div>
        <div class="ss-target-progress">
          <div class="ss-progress-bar"><div class="ss-progress-fill" style="width:${Math.min(100, saved/required*100)}%"></div></div>
          <div class="ss-progress-label">${saved}/${required} ${done ? '✓' : ''}</div>
        </div>
      `;
      card.onclick = () => renderMemoryPickStrengths(target);
      list.appendChild(card);
    });
    inner.appendChild(list);

    if (allComplete && !state.player.others_submitted) {
      const reviewBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '📋 Review & Submit →');
      reviewBtn.style.marginTop = '14px';
      reviewBtn.onclick = renderMemoryReview;
      inner.appendChild(reviewBtn);
    } else if (state.player.others_submitted) {
      inner.appendChild(renderMemorySubmissionWaitingInline());
    }

    body.appendChild(inner);
    render(body);
  }

  // =========================================================================
  // MEMORY GAME — PHASE 2 CARD PICKER
  // Reuses the existing strength list but saves to memory/others-save
  // =========================================================================
  function renderMemoryPickStrengths(target) {
    state.currentTarget = target;
    const required = 5;
    const draft    = state.draftCards[target.id] || [];

    const body = el('div', 'ss-screen-body');
    body.innerHTML = `
      <div class="ss-game-header">
        <div class="ss-game-title">✍️ Cards for: <span class="highlight">${escHtml(target.display_name)}</span></div>
        <div class="ss-game-sub" id="ss-card-count">${draft.length}/${required} added</div>
      </div>
    `;
    const inner = el('div', '');
    inner.style.padding = '20px';

    const tagLabel = el('div', 'ss-section-label', 'Your cards for ' + target.display_name);
    inner.appendChild(tagLabel);
    const tagCloud = el('div', 'ss-tag-cloud');
    tagCloud.id = 'ss-tags';
    inner.appendChild(tagCloud);
    refreshTags(tagCloud, target, required);

    const search = el('input', 'ss-input');
    search.placeholder = '🔍 Search all strengths…';
    search.style.marginBottom = '10px';
    search.oninput = () => filterStrengths(search.value);
    inner.appendChild(search);

    const cats   = Object.keys(state.strengths);
    const tabBar = el('div', 'ss-cat-tabs');
    const allTab = el('button', 'ss-cat-tab active', 'All');
    allTab.dataset.cat = 'all';
    tabBar.appendChild(allTab);
    cats.forEach(cat => {
      const shortNames = { 'Creative & Expressive': 'Creative', 'Mind & Learning': 'Mind', 'Leadership & Drive': 'Leadership', 'Practical & Dependable': 'Practical', 'Growth & Mindset': 'Growth', 'Social & Caring': 'Social' };
      const btn = el('button', 'ss-cat-tab' + (cat === 'Family' ? ' family-tab' : ''), shortNames[cat] || cat);
      btn.dataset.cat = cat;
      tabBar.appendChild(btn);
    });
    inner.appendChild(tabBar);
    tabBar.addEventListener('click', e => {
      const btn = e.target.closest('.ss-cat-tab');
      if (!btn) return;
      tabBar.querySelectorAll('.ss-cat-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      filterStrengths(search.value, btn.dataset.cat);
    });

    const grid = el('div', '');
    grid.id = 'ss-strength-grid';
    grid.style.cssText = 'max-height:260px;overflow-y:auto;padding-right:4px;';
    inner.appendChild(grid);
    renderStrengthList(grid, target, 'all');

    if (cfg.ftEnabled) {
      const ftSection = el('div', '');
      ftSection.style.cssText = 'background:rgba(201,162,39,0.08);border:1px solid rgba(201,162,39,0.2);border-radius:8px;padding:12px 14px;margin-top:14px;';
      ftSection.innerHTML = '<div class="ss-section-label" style="color:var(--ss-gold-lt);margin-bottom:8px;">✏️ Add your own (free text)</div>';
      const ftRow = el('div', '');
      ftRow.style.cssText = 'display:flex;gap:8px;';
      const ftInput = el('input', 'ss-input');
      ftInput.placeholder = 'Type a strength (' + cfg.ftMinLen + '–' + cfg.ftMaxLen + ' chars)';
      ftInput.style.flex = '1';
      const ftBtn = el('button', 'ss-btn ss-btn-ghost ss-btn-sm', 'Add');
      const ftMsg = el('div', '');
      ftMsg.id = 'ss-ft-msg';
      ftBtn.onclick = async () => {
        const txt = ftInput.value.trim();
        if (txt.length < cfg.ftMinLen || txt.length > cfg.ftMaxLen) {
          showFtError(ftMsg, 'block', null, 'Please enter between ' + cfg.ftMinLen + ' and ' + cfg.ftMaxLen + ' characters.'); return;
        }
        ftBtn.disabled = true;
        const vr = await api('validate-text', 'POST', { text: txt });
        ftBtn.disabled = false;
        const result = vr.result;
        if (result.action === 'block') { showFtError(ftMsg, 'block', '🚫', result.message); ftInput.classList.add('error'); return; }
        if (result.action === 'flag') {
          showFtError(ftMsg, 'flag', '⏳', result.message);
          addDraftCard(target.id, { type: 'free', text: txt, strength_id: null, flagged: true });
        } else {
          addDraftCard(target.id, { type: 'free', text: txt, strength_id: null });
        }
        ftInput.value = ''; ftInput.classList.remove('error'); ftMsg.innerHTML = '';
        refreshTags(document.getElementById('ss-tags'), target, required);
        const sub = document.getElementById('ss-card-count');
        if (sub) sub.textContent = (state.draftCards[target.id]||[]).length + '/' + required + ' added';
      };
      ftRow.appendChild(ftInput); ftRow.appendChild(ftBtn);
      ftSection.appendChild(ftRow); ftSection.appendChild(ftMsg);
      inner.appendChild(ftSection);
    }

    const btnGrp = el('div', 'ss-btn-group');
    const saveBtn = el('button', 'ss-btn ss-btn-gold', '💾 Save Cards for ' + target.display_name);
    saveBtn.onclick = () => saveMemoryTargetCards(target);
    const backBtn = el('button', 'ss-btn ss-btn-ghost', '↩ Back to overview');
    backBtn.onclick = renderMemorySubmissionOverview;
    btnGrp.appendChild(saveBtn); btnGrp.appendChild(backBtn);
    inner.appendChild(btnGrp);

    body.appendChild(inner);
    render(body);

    function filterStrengths(q, cat) {
      const activeCat = cat || tabBar.querySelector('.ss-cat-tab.active')?.dataset.cat || 'all';
      renderStrengthList(document.getElementById('ss-strength-grid'), target, activeCat, q);
    }
  }

  async function saveMemoryTargetCards(target) {
    const draft    = state.draftCards[target.id] || [];
    const required = 5;
    if (draft.length < required) { alert('Please write exactly ' + required + ' cards for ' + target.display_name + '.'); return; }
    const lv = loading('Saving cards for ' + target.display_name + '…');
    try {
      const res = await api('memory/others-save', 'POST', {
        game_id:          state.gameId,
        target_player_id: target.id,
        strengths:        draft,
      });
      unload(lv);
      if (!state.player.card_counts) state.player.card_counts = {};
      state.player.card_counts[target.id] = res.saved;
      renderMemorySubmissionOverview();
    } catch (e) {
      unload(lv);
      alert('Error saving: ' + e.message);
    }
  }

  // =========================================================================
  // MEMORY GAME — PHASE 2 REVIEW
  // =========================================================================
  function renderMemoryReview() {
    const targets  = state.allPlayers.filter(p => p.id !== state.player.id);
    const required = 5;

    const body = el('div', 'ss-screen-body');
    body.innerHTML = '<div class="ss-game-header"><div class="ss-game-title">📋 Review Your Cards</div><div class="ss-game-sub">Check everything before locking</div></div>';
    const inner = el('div', '');
    inner.style.padding = '20px';

    let total = 0;
    targets.forEach(target => {
      const draft = state.draftCards[target.id] || [];
      total += draft.length;
      const section = el('div', 'ss-card');
      section.style.marginBottom = '12px';
      section.innerHTML = `<div class="ss-section-label" style="margin-bottom:8px;">Cards for ${escHtml(target.display_name)} · ${draft.length}/${required}</div>`;
      if (draft.length) {
        const tags = el('div', 'ss-tag-cloud');
        draft.forEach(d => {
          const t = el('div', 'ss-tag' + (d.flagged ? ' pending' : ''), d.text + (d.flagged ? ' ⏳' : ''));
          tags.appendChild(t);
        });
        section.appendChild(tags);
      }
      const editBtn = el('button', 'ss-btn ss-btn-ghost ss-btn-sm', '✏️ Edit');
      editBtn.style.marginTop = '8px';
      editBtn.onclick = () => renderMemoryPickStrengths(target);
      section.appendChild(editBtn);
      inner.appendChild(section);
    });

    const submitBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '🔒 Submit All ' + total + ' Cards');
    submitBtn.style.marginTop = '6px';
    submitBtn.onclick = submitMemoryCards;
    inner.appendChild(submitBtn);

    const note = el('p', '', 'Once submitted, your cards are locked and the board will be built.');
    note.style.cssText = 'text-align:center;font-size:12px;color:var(--ss-text-dim);margin-top:8px;';
    inner.appendChild(note);

    const backBtn = el('button', 'ss-btn ss-btn-ghost', '↩ Back to overview');
    backBtn.style.marginTop = '8px';
    backBtn.onclick = renderMemorySubmissionOverview;
    inner.appendChild(backBtn);

    body.appendChild(inner);
    render(body);
  }

  async function submitMemoryCards() {
    const lv = loading('Submitting your cards…');
    try {
      const res = await api('memory/others-submit', 'POST', { game_id: state.gameId });
      unload(lv);
      state.player = Object.assign({}, state.player, { others_submitted: true });
      state.allPlayers = res.players || state.allPlayers;

      if (res.all_submitted) {
        const fresh = await api('memory/state');
        state.gameStatus = fresh.status;
        state.player     = fresh.player  || state.player;
        state.allPlayers = fresh.all_players || state.allPlayers;
        routeToScreen();
      } else {
        renderMemorySubmissionOverview();
      }
    } catch (e) {
      unload(lv);
      alert('Error: ' + e.message);
    }
  }

  function renderMemorySubmissionWaitingInline() {
    const div = el('div', '');
    div.style.marginTop = '14px';
    const pending = state.allPlayers.filter(p => !p.others_submitted);
    div.innerHTML = `
      <div class="ss-reveal-banner info">✅ You've submitted! Waiting for ${pending.length} more player${pending.length !== 1 ? 's' : ''}…</div>
      <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
        ${state.allPlayers.map(p =>
          `<div class="ss-player-pill">
            <span class="status-dot ${p.others_submitted ? 'submitted' : 'pending'}"></span>
            ${escHtml(p.display_name)}
            <span style="color:var(--ss-text-dim);font-size:10px;">${p.others_submitted ? '— submitted ✓' : '— writing…'}</span>
          </div>`
        ).join('')}
      </div>
      <div class="ss-waiting" style="margin-top:12px;"><div class="ss-dots"><span></span><span></span><span></span></div> Building the board when everyone's done…</div>
    `;
    startPoll(async () => {
      try {
        const data = await api('memory/state');
        state.allPlayers = data.all_players || state.allPlayers;
        if (data.status === 'dealing' || data.status === 'playing') {
          stopPoll();
          state.gameStatus = data.status;
          state.player     = data.player || state.player;
          routeToScreen();
        } else {
          // Refresh overview to update status dots
          renderMemorySubmissionOverview();
        }
      } catch (_) {}
    }, 8000);
    return div;
  }

  // =========================================================================
  // SUBMISSION INTRO (ST0 / PC0)
  // =========================================================================
  function renderSubmissionIntro() {
    const isStudent = state.player.role === 'student';
    const isSnap    = state.gameMode === 'snap';
    const body = el('div', 'ss-screen-body');

    body.innerHTML = `
      <div class="ss-game-header">
        <div class="ss-game-title">${isSnap ? '🎯 Super Strengths Snap' : '🃏 Super Strengths Cards'}</div>
        <div class="ss-game-sub">${isStudent ? (isSnap ? 'Snap Mode' : 'Family Game') : 'You\'ve been invited!'}</div>
      </div>
    `;
    const inner = el('div', '');
    inner.style.padding = '20px';

    if (isStudent) {
      const rules = isSnap ? [
        'Write 5 Super Strength cards for each person in your game — same as always.',
        'Once everyone submits, all cards are doubled to create matched pairs and dealt out.',
        'All players must be online at the same time — a 3-2-1 countdown starts when everyone joins.',
        'Take turns playing cards face-up onto the pile. Spot a matching pair — hit SNAP!',
        'On desktop: right-click the bullseye. On mobile: double-tap it. First to claim wins the point!',
      ] : [
        'Write 5 Super Strength cards for each person in your game.',
        'Once everyone submits, cards are dealt and the guessing game begins.',
        'In each round one card is played face-up — everyone guesses who it\'s about!',
        'Then guess who wrote it. Score points for correct guesses.',
        'Use Confidence Tokens to bet big — right gets you +3, wrong costs you 3!',
      ];

      inner.innerHTML = `
        <div class="ss-section-label" style="margin-bottom:12px;">How it works</div>
        ${rules.map((r, i) =>
          `<div class="ss-intro-rule"><div class="ss-intro-rule-num">${i+1}</div>${r}</div>`
        ).join('')}
        <hr class="ss-divider">
        <div class="ss-section-label" style="margin-bottom:10px;">Players in this game</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;">
          ${state.allPlayers.map(p =>
            `<div class="ss-player-pill">
              <span class="status-dot ${p.submission_status === 'submitted' ? 'submitted' : 'pending'}"></span>
              ${escHtml(p.display_name)}
              <span style="color:var(--ss-text-dim);font-size:10px;">${p.role === 'student' ? '— you' : ''}</span>
            </div>`
          ).join('')}
        </div>
      `;
    } else {
      const student = state.allPlayers.find(p => p.role === 'student');
      const gameDesc = isSnap
        ? 'has started a Super Strengths Snap game! Write 5 strength cards for each person — everyone needs to be online at the same time to play.'
        : 'has started a Super Strengths Cards game! Write 5 strength cards for each person — your cards will be used in the guessing game once everyone submits.';
      inner.innerHTML = `
        <div style="background:rgba(201,162,39,0.08);border:1px solid rgba(201,162,39,0.2);border-radius:8px;padding:14px;margin-bottom:16px;font-size:13px;line-height:1.6;color:var(--ss-text);">
          <strong style="color:var(--ss-gold-lt);">${escHtml(student ? student.display_name : 'Your student')}</strong>
          ${gameDesc}
        </div>
        <div class="ss-section-label" style="margin-bottom:10px;">Players in this game</div>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px;">
          ${state.allPlayers.map(p =>
            `<div class="ss-player-pill">
              <span class="status-dot ${p.submission_status === 'submitted' ? 'submitted' : 'pending'}"></span>
              ${escHtml(p.display_name)}
              <span style="color:var(--ss-text-dim);font-size:10px;">${p.submission_status === 'submitted' ? '— submitted ✓' : '— waiting'}</span>
            </div>`
          ).join('')}
        </div>
      `;
    }

    const startBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '✍️ Start Writing Cards →');
    startBtn.onclick = renderSubmissionOverview;
    inner.appendChild(startBtn);
    body.appendChild(inner);
    render(body);
  }

  // =========================================================================
  // SUBMISSION OVERVIEW (SS0)
  // =========================================================================
  function renderSubmissionOverview() {
    const targets = state.allPlayers.filter(p => p.id != state.player.id);
    const savedCounts = state.player.saved_counts || {};
    const cardsNeeded = cfg.cardsPerTarget || 5;

    const allComplete = targets.every(t => (savedCounts[t.id] || 0) >= cardsNeeded);

    const body = el('div', 'ss-screen-body');
    body.innerHTML = `
      <div class="ss-game-header">
        <div class="ss-game-title">✍️ Write Super Strength Cards</div>
        <div class="ss-game-sub">${cardsNeeded} cards per person · mandatory</div>
      </div>
    `;
    const inner = el('div', '');
    inner.style.padding = '20px';

    const list = el('div', 'ss-target-list');
    targets.forEach(target => {
      const saved = savedCounts[target.id] || 0;
      const done  = saved >= cardsNeeded;
      const card  = el('div', 'ss-target-card' + (done ? ' complete' : ''));
      card.innerHTML = `
        <div>
          <div class="ss-target-name">${escHtml(target.display_name)}</div>
          <div class="ss-target-role">${escHtml(target.role)}</div>
        </div>
        <div class="ss-target-progress">
          <div class="ss-progress-bar"><div class="ss-progress-fill" style="width:${Math.min(100,saved/cardsNeeded*100)}%"></div></div>
          <div class="ss-progress-label">${saved}/${cardsNeeded} ${done ? '✓' : ''}</div>
        </div>
      `;
      card.onclick = () => renderPickStrengths(target);
      list.appendChild(card);
    });
    inner.appendChild(list);

    if (allComplete && state.player.submission_status !== 'submitted') {
      const reviewBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '📋 Review All Cards →');
      reviewBtn.style.marginTop = '14px';
      reviewBtn.onclick = renderReview;
      inner.appendChild(reviewBtn);
    } else if (state.player.submission_status === 'submitted') {
      const submitted = el('div', 'ss-reveal-banner info');
      submitted.innerHTML = '✓ You\'ve submitted! Waiting for other players…';
      inner.appendChild(submitted);
      renderWaitingHub(inner);
    }

    body.appendChild(inner);
    render(body);
  }

  // =========================================================================
  // PICK STRENGTHS (SS1)
  // =========================================================================
  function renderPickStrengths(target) {
    state.currentTarget = target;
    const savedCounts   = state.player.saved_counts || {};
    const cardsNeeded   = cfg.cardsPerTarget || 5;
    const draft         = state.draftCards[target.id] || [];

    const body = el('div', 'ss-screen-body');
    body.innerHTML = `
      <div class="ss-game-header">
        <div class="ss-game-title">✍️ Cards for: <span class="highlight">${escHtml(target.display_name)}</span></div>
        <div class="ss-game-sub" id="ss-card-count">${draft.length}/${cardsNeeded} added</div>
      </div>
    `;
    const inner = el('div', '');
    inner.style.padding = '20px';

    // Selected tags
    const tagLabel = el('div', 'ss-section-label', 'Your cards for ' + target.display_name);
    inner.appendChild(tagLabel);
    const tagCloud = el('div', 'ss-tag-cloud', '');
    tagCloud.id = 'ss-tags';
    inner.appendChild(tagCloud);

    refreshTags(tagCloud, target, cardsNeeded);

    // Search
    const search = el('input', 'ss-input');
    search.placeholder = '🔍 Search all 100 strengths…';
    search.style.marginBottom = '10px';
    search.oninput = () => filterStrengths(search.value);
    inner.appendChild(search);

    // Category tabs
    const cats = Object.keys(state.strengths);
    const tabBar = el('div', 'ss-cat-tabs');
    const allTab = el('button', 'ss-cat-tab active', 'All');
    allTab.dataset.cat = 'all';
    tabBar.appendChild(allTab);
    cats.forEach(cat => {
      const shortNames = { 'Creative & Expressive': 'Creative', 'Mind & Learning': 'Mind', 'Leadership & Drive': 'Leadership', 'Practical & Dependable': 'Practical', 'Growth & Mindset': 'Growth', 'Social & Caring': 'Social' };
      const btn = el('button', 'ss-cat-tab' + (cat === 'Family' ? ' family-tab' : ''), shortNames[cat] || cat);
      btn.dataset.cat = cat;
      tabBar.appendChild(btn);
    });
    inner.appendChild(tabBar);

    tabBar.addEventListener('click', e => {
      const btn = e.target.closest('.ss-cat-tab');
      if (!btn) return;
      tabBar.querySelectorAll('.ss-cat-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      filterStrengths(search.value, btn.dataset.cat);
    });

    // Strength list
    const grid = el('div', '');
    grid.id = 'ss-strength-grid';
    grid.style.maxHeight = '260px';
    grid.style.overflowY = 'auto';
    grid.style.paddingRight = '4px';
    inner.appendChild(grid);

    renderStrengthList(grid, target, 'all');

    // Free text
    if (cfg.ftEnabled) {
      const ftSection = el('div', '');
      ftSection.style.cssText = 'background:rgba(201,162,39,0.08);border:1px solid rgba(201,162,39,0.2);border-radius:8px;padding:12px 14px;margin-top:14px;';
      ftSection.innerHTML = '<div class="ss-section-label" style="color:var(--ss-gold-lt);margin-bottom:8px;">✏️ Add your own (free text)</div>';
      const ftRow = el('div', '');
      ftRow.style.cssText = 'display:flex;gap:8px;';
      const ftInput = el('input', 'ss-input');
      ftInput.placeholder = 'Type a strength (' + cfg.ftMinLen + '–' + cfg.ftMaxLen + ' chars)';
      ftInput.style.flex = '1';
      const ftBtn = el('button', 'ss-btn ss-btn-ghost ss-btn-sm', 'Add');
      const ftMsg = el('div', '');
      ftMsg.id = 'ss-ft-msg';

      ftBtn.onclick = async () => {
        const txt = ftInput.value.trim();
        if (txt.length < cfg.ftMinLen || txt.length > cfg.ftMaxLen) {
          showFtError(ftMsg, 'block', null, 'Please enter between ' + cfg.ftMinLen + ' and ' + cfg.ftMaxLen + ' characters.');
          return;
        }
        ftBtn.disabled = true;
        const vr = await api('validate-text', 'POST', { text: txt });
        ftBtn.disabled = false;
        const result = vr.result;
        if (result.action === 'block') {
          showFtError(ftMsg, 'block', '🚫', result.message);
          ftInput.classList.add('error');
          return;
        }
        if (result.action === 'flag') {
          showFtError(ftMsg, 'flag', '⏳', result.message);
          addDraftCard(target.id, { type: 'free', text: txt, strength_id: null, flagged: true });
        } else {
          addDraftCard(target.id, { type: 'free', text: txt, strength_id: null });
        }
        ftInput.value = '';
        ftInput.classList.remove('error');
        ftMsg.innerHTML = '';
        refreshTags(document.getElementById('ss-tags'), target, cardsNeeded);
        updateCount(target.id, cardsNeeded);
      };

      ftRow.appendChild(ftInput);
      ftRow.appendChild(ftBtn);
      ftSection.appendChild(ftRow);
      ftSection.appendChild(ftMsg);
      inner.appendChild(ftSection);
    }

    // Action buttons
    const btnGrp = el('div', 'ss-btn-group');
    const saveBtn = el('button', 'ss-btn ss-btn-gold', '💾 Save Cards for ' + target.display_name);
    saveBtn.onclick = () => saveTargetCards(target);
    const backBtn = el('button', 'ss-btn ss-btn-ghost', '↩ Back to overview');
    backBtn.onclick = renderSubmissionOverview;
    btnGrp.appendChild(saveBtn);
    btnGrp.appendChild(backBtn);
    inner.appendChild(btnGrp);

    body.appendChild(inner);
    render(body);

    // Helpers
    function filterStrengths(q, cat) {
      const activeCat = cat || tabBar.querySelector('.ss-cat-tab.active')?.dataset.cat || 'all';
      renderStrengthList(document.getElementById('ss-strength-grid'), target, activeCat, q);
    }

    function updateCount(tid, needed) {
      const d = state.draftCards[tid] || [];
      const sub = document.getElementById('ss-card-count');
      if (sub) sub.textContent = d.length + '/' + needed + ' added';
    }
  }

  function showFtError(container, type, icon, msg) {
    container.innerHTML = `<div class="ss-validation ${type}"><div class="ss-validation-icon">${icon || ''}</div><div class="ss-validation-msg"><strong>${type === 'block' ? 'Not allowed' : 'Pending review'}</strong>${escHtml(msg)}</div></div>`;
  }

  function renderStrengthList(container, target, cat, query = '') {
    const draft = state.draftCards[target.id] || [];
    const usedTexts = draft.map(d => d.text.toLowerCase());
    container.innerHTML = '';

    Object.entries(state.strengths).forEach(([category, items]) => {
      if (cat !== 'all' && cat !== category) return;

      const filtered = query
        ? items.filter(s => s.text.toLowerCase().includes(query.toLowerCase()))
        : items;

      if (!filtered.length) return;

      const label = el('div', 'ss-cat-label');
      label.style.color = category === 'Family' ? 'rgba(201,162,39,0.7)' : 'rgba(155,110,243,0.7)';
      label.textContent = category;
      container.appendChild(label);

      const grid = el('div', 'ss-strength-grid');
      filtered.forEach(s => {
        const used = usedTexts.includes(s.text.toLowerCase());
        const chip = el('div', 'ss-strength-chip' +
          (used ? ' used' : '') +
          (category === 'Family' ? ' family' : ''),
          s.text + (used ? ' ✓' : ''));
        if (!used) {
          chip.onclick = () => {
            const currentDraft = state.draftCards[target.id] || [];
            if (currentDraft.length >= (cfg.cardsPerTarget || 5)) return;
            addDraftCard(target.id, { type: 'list', text: s.text, strength_id: s.id });
            refreshTags(document.getElementById('ss-tags'), target, cfg.cardsPerTarget || 5);
            chip.classList.add('used');
            chip.textContent = s.text + ' ✓';
            chip.onclick = null;
            const sub = document.getElementById('ss-card-count');
            if (sub) {
              const d2 = state.draftCards[target.id] || [];
              sub.textContent = d2.length + '/' + (cfg.cardsPerTarget || 5) + ' added';
            }
          };
        }
        grid.appendChild(chip);
      });
      container.appendChild(grid);
    });
  }

  function addDraftCard(tid, card) {
    if (!state.draftCards[tid]) state.draftCards[tid] = [];
    const max = cfg.cardsPerTarget || 5;
    if (state.draftCards[tid].length >= max) return;
    state.draftCards[tid].push(card);
  }

  function removeDraftCard(tid, text) {
    if (!state.draftCards[tid]) return;
    state.draftCards[tid] = state.draftCards[tid].filter(c => c.text !== text);
  }

  function refreshTags(container, target, needed) {
    const draft = state.draftCards[target.id] || [];
    container.innerHTML = '';
    if (!draft.length) {
      container.innerHTML = '<span style="color:var(--ss-text-dim);font-size:12px;">No cards selected yet — tap a strength below</span>';
      return;
    }
    draft.forEach(d => {
      const tag = el('div', 'ss-tag' + (d.flagged ? ' pending' : ''));
      tag.innerHTML = escHtml(d.text) + (d.flagged ? ' ⏳' : '') +
        '<span class="ss-tag-remove" data-text="' + escHtml(d.text) + '">×</span>';
      tag.querySelector('.ss-tag-remove').onclick = () => {
        removeDraftCard(target.id, d.text);
        refreshTags(container, target, needed);
        const sub = document.getElementById('ss-card-count');
        if (sub) sub.textContent = (state.draftCards[target.id]||[]).length + '/' + needed + ' added';
        // Re-render grid to uncheck removed chip
        const grid = document.getElementById('ss-strength-grid');
        if (grid) renderStrengthList(grid, target, 'all');
      };
      container.appendChild(tag);
    });
  }

  async function saveTargetCards(target) {
    const draft = state.draftCards[target.id] || [];
    if (draft.length < (cfg.cardsPerTarget || 5)) {
      alert('Please write exactly ' + (cfg.cardsPerTarget || 5) + ' cards for ' + target.display_name + '.');
      return;
    }
    const lv = loading('Saving cards for ' + target.display_name + '…');
    try {
      const res = await api('submission/save', 'POST', {
        game_id:          state.gameId,
        target_player_id: target.id,
        strengths:        draft,
      });
      unload(lv);
      if (!state.player.saved_counts) state.player.saved_counts = {};
      state.player.saved_counts[target.id] = res.saved;
      renderSubmissionOverview();
    } catch (e) {
      unload(lv);
      alert('Error saving: ' + e.message);
    }
  }

  // =========================================================================
  // REVIEW (SS4)
  // =========================================================================
  function renderReview() {
    const targets     = state.allPlayers.filter(p => p.id != state.player.id);
    const savedCounts = state.player.saved_counts || {};
    const cardsNeeded = cfg.cardsPerTarget || 5;

    const body = el('div', 'ss-screen-body');
    body.innerHTML = '<div class="ss-game-header"><div class="ss-game-title">📋 Review Your Cards</div><div class="ss-game-sub">Check everything before locking</div></div>';

    const inner = el('div', '');
    inner.style.padding = '20px';

    let totalCards = 0;
    targets.forEach(target => {
      const draft = state.draftCards[target.id] || [];
      totalCards += draft.length;

      const section = el('div', 'ss-card');
      section.style.marginBottom = '12px';
      section.innerHTML = `<div class="ss-section-label" style="margin-bottom:8px;">Cards for ${escHtml(target.display_name)} (${escHtml(target.role)}) · ${draft.length}/${cardsNeeded}</div>`;

      if (draft.length) {
        const tags = el('div', 'ss-tag-cloud');
        draft.forEach(d => {
          const t = el('div', 'ss-tag' + (d.flagged ? ' pending' : ''), d.text + (d.flagged ? ' ⏳' : ''));
          tags.appendChild(t);
        });
        section.appendChild(tags);
      }

      const editBtn = el('button', 'ss-btn ss-btn-ghost ss-btn-sm', '✏️ Edit');
      editBtn.style.marginTop = '8px';
      editBtn.onclick = () => renderPickStrengths(target);
      section.appendChild(editBtn);

      inner.appendChild(section);
    });

    const submitBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '🔒 Submit All ' + totalCards + ' Cards');
    submitBtn.style.marginTop = '6px';
    submitBtn.onclick = submitAllCards;
    inner.appendChild(submitBtn);

    const note = el('p', '', 'Once submitted, your cards are locked until the game starts.');
    note.style.cssText = 'text-align:center;font-size:12px;color:var(--ss-text-dim);margin-top:8px;';
    inner.appendChild(note);

    const backBtn = el('button', 'ss-btn ss-btn-ghost', '↩ Back to overview');
    backBtn.style.marginTop = '8px';
    backBtn.onclick = renderSubmissionOverview;
    inner.appendChild(backBtn);

    body.appendChild(inner);
    render(body);
  }

  async function submitAllCards() {
    const lv = loading('Submitting your cards…');
    try {
      const res = await api('submission/submit', 'POST', { game_id: state.gameId });
      unload(lv);
      state.player.submission_status = 'submitted';
      state.allPlayers = res.players || state.allPlayers;

      if (res.all_submitted) {
        // Re-fetch full state so gameMode is current, then route correctly
        const fresh = await api('state');
        state.gameStatus = fresh.status;
        state.gameMode   = fresh.game_mode || state.gameMode;
        state.player     = fresh.player     || state.player;
        state.allPlayers = fresh.all_players || state.allPlayers;
        state.hand       = fresh.hand || [];
        routeToScreen();
      } else {
        renderSubmissionWaiting();
      }
    } catch (e) {
      unload(lv);
      alert('Error: ' + e.message);
    }
  }

  // =========================================================================
  // SUBMISSION WAITING (SS5)
  // =========================================================================
  function renderSubmissionWaiting() {
    const body = el('div', 'ss-screen-body');
    body.innerHTML = '<div class="ss-game-header"><div class="ss-game-title">✅ Cards Submitted!</div><div class="ss-game-sub">Waiting for everyone else…</div></div>';
    const inner = el('div', '');
    inner.style.padding = '20px';

    const statusDiv = el('div', '');
    statusDiv.id = 'ss-wait-status';

    function updateWaitStatus() {
      const pending = state.allPlayers.filter(p => p.submission_status !== 'submitted');
      const submitted = state.allPlayers.filter(p => p.submission_status === 'submitted');
      statusDiv.innerHTML = `
        <div class="ss-section-label" style="margin-bottom:10px;">Player status</div>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
          ${state.allPlayers.map(p =>
            `<div class="ss-player-pill">
              <span class="status-dot ${p.submission_status === 'submitted' ? 'submitted' : 'pending'}"></span>
              ${escHtml(p.display_name)}
              <span style="color:var(--ss-text-dim);font-size:10px;">${p.submission_status === 'submitted' ? '— submitted ✓' : '— writing…'}</span>
            </div>`
          ).join('')}
        </div>
        ${pending.length ? `<div class="ss-waiting"><div class="ss-dots"><span></span><span></span><span></span></div> Waiting for ${pending.length} more player${pending.length > 1 ? 's' : ''}…</div>` : ''}
      `;
    }

    updateWaitStatus();
    inner.appendChild(statusDiv);
    body.appendChild(inner);
    render(body);

    // Poll for game status change
    startPoll(async () => {
      try {
        const data = await api('state');
        state.allPlayers = data.all_players || state.allPlayers;
        if (data.status === 'playing' || data.status === 'dealing') {
          stopPoll();
          state.gameStatus = data.status;
          state.gameMode   = data.game_mode || state.gameMode;
          state.player     = data.player    || state.player;
          state.hand       = data.hand || [];
          routeToScreen();
        } else {
          updateWaitStatus();
        }
      } catch(_) {}
    }, 6000);
  }

  function renderWaitingHub(container) {
    const div = el('div', '');
    div.style.marginTop = '14px';
    div.innerHTML = `<div class="ss-section-label" style="margin-bottom:10px;">Waiting for everyone</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        ${state.allPlayers.map(p =>
          `<div class="ss-player-pill"><span class="status-dot ${p.submission_status === 'submitted' ? 'submitted' : 'pending'}"></span>${escHtml(p.display_name)}</div>`
        ).join('')}
      </div>
      <div class="ss-waiting" style="margin-top:12px;"><div class="ss-dots"><span></span><span></span><span></span></div> Game starts when everyone has submitted</div>`;
    container.appendChild(div);
  }

  // =========================================================================
  // DEALING (R0)
  // =========================================================================
  function renderDealing() {
    const body = el('div', 'ss-screen-body');
    body.innerHTML = `<div class="ss-game-header"><div class="ss-game-title">🃏 Dealing Cards…</div></div>`;
    const inner = el('div', '');
    inner.style.cssText = 'padding:40px 20px;text-align:center;';
    inner.innerHTML = `
      <div style="font-size:48px;margin-bottom:16px;animation:ss-bounce 1s infinite;">🃏</div>
      <div style="font-size:16px;color:var(--ss-text);margin-bottom:8px;">Shuffling and dealing your cards…</div>
      <div class="ss-waiting" style="justify-content:center;"><div class="ss-dots"><span></span><span></span><span></span></div></div>
    `;
    body.appendChild(inner);
    render(body);

    const stateEndpoint = state.gameKey ? 'memory/state' : 'state';
    startPoll(async () => {
      try {
        const data = await api(stateEndpoint);
        if (data.status === 'playing') {
          stopPoll();
          state.gameStatus = 'playing';
          state.allPlayers = data.all_players || state.allPlayers;
          if (state.gameKey) {
            state.player              = data.player || state.player;
            state.currentTurnPlayerId = data.current_turn_player_id || null;
            state.gameEndsAt          = data.game_ends_at           || null;
          } else {
            state.gameMode = data.game_mode || state.gameMode;
            state.player   = data.player    || state.player;
            state.hand     = data.hand || [];
          }
          routeToScreen();
        }
      } catch(_) {}
    }, 4000);
  }

  // =========================================================================
  // GAME TABLE (T0/P0/W0)
  // =========================================================================
  async function renderGameTable() {
    // Fetch turn state
    const lv = loading('Loading game…');
    try {
      const ts = await api('game/turn?game_id=' + state.gameId);
      unload(lv);
      if (ts.game_status === 'complete') { renderFinalResults(); return; }
      state.currentTurn = ts.turn;
      state.allPlayers  = ts.all_players || state.allPlayers;
      renderTurnView(ts);
    } catch (e) {
      unload(lv);
      renderError(e.message);
    }
  }

  function renderTurnView(ts) {
    const turn     = ts.turn;
    const isMyTurn = ts.is_card_player;
    const phase    = turn.phase;

    const body = el('div', 'ss-screen-body');

    // Header
    const hdr = el('div', 'ss-game-header');
    hdr.innerHTML = `
      <div class="ss-game-title">Turn ${turn.turn_number} · Phase ${phase === 'complete' ? 'Complete' : phase}</div>
      <div class="ss-game-sub">${isMyTurn ? "It's your turn!" : "Played by " + escHtml(turn.played_by_name)}</div>
    `;
    body.appendChild(hdr);

    const inner = el('div', '');
    inner.style.padding = '16px 20px';

    // --- Scoreboard (compact) ---
    const scores = el('div', 'ss-scoreboard');
    ts.all_players.forEach(p => {
      const row = el('div', 'ss-score-row' + (p.id == ts.player_id ? ' me' : ''));
      row.innerHTML = `<div class="ss-score-name">${escHtml(p.display_name)}</div><div class="ss-score-pts">${p.score_total} pts</div>`;
      scores.appendChild(row);
    });
    inner.appendChild(scores);
    inner.appendChild(el('hr', 'ss-divider'));

    // ---- No card played yet — it's this player's turn ----
    if (!turn.card_id) {
      if (isMyTurn) {
        renderPlayCardUI(inner, ts);
      } else {
        inner.innerHTML += `<div class="ss-reveal-banner info">⏳ Waiting for ${escHtml(turn.played_by_name)} to play a card…</div>`;
        // Poll
        startPoll(() => renderGameTable(), 5000);
      }
    }

    // ---- Card played, in Phase A voting ----
    else if (phase === 'A') {
      renderPhaseAUI(inner, ts);
    }

    // ---- Phase A done, in Phase B ----
    else if (phase === 'B') {
      renderPhaseBUI(inner, ts);
    }

    // ---- Turn complete, show reveal summary ----
    else if (phase === 'complete') {
      renderTurnComplete(inner, ts);
    }

    body.appendChild(inner);
    render(body);
  }

  // Play card from hand
  function renderPlayCardUI(container, ts) {
    const hand = state.hand.filter(c => !c.played);

    container.innerHTML += '<div class="ss-section-label" style="margin-bottom:8px;">Your hand — pick a card to play</div>';

    // Hand display (all 5 slots, face-down = played)
    const handEl = el('div', 'ss-hand');
    state.hand.forEach(card => {
      const isPlayed = card.played == 1; // DB returns string "0"/"1" — use == not truthy check
      const isSelected = state.selectedCard?.id == card.id;
      const c = el('div', 'ss-hand-card' + (isPlayed ? ' played' : '') + (isSelected ? ' selected' : ''));
      if (!isPlayed) {
        const txt = el('div', 'ss-hand-card-text', card.strength_text);
        const about = el('div', 'ss-hand-card-about', 'about ' + (card.target_name || '?'));
        c.appendChild(txt);
        c.appendChild(about);
        c.onclick = () => {
          state.selectedCard = card;
          // Re-render just hand
          renderPlayCardUI(container, ts);
        };
      }
      handEl.appendChild(c);
    });
    container.appendChild(handEl);

    if (state.selectedCard) {
      const preview = el('div', 'ss-playing-card');
      preview.innerHTML = `<div class="ss-card-label">You're playing this card</div>
        <div class="ss-card-strength">${escHtml(state.selectedCard.strength_text)}</div>
        <div class="ss-card-about">about <span class="reveal">${escHtml(state.selectedCard.target_name || '?')}</span></div>`;
      container.appendChild(preview);

      const playBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '▶ Play This Card');
      playBtn.onclick = async () => {
        const lv = loading('Playing card…');
        try {
          await api('game/play', 'POST', { game_id: state.gameId, card_id: state.selectedCard.id });
          // Mark as played locally
          const hc = state.hand.find(c => c.id == state.selectedCard.id);
          if (hc) hc.played = true;
          state.selectedCard = null;
          unload(lv);
          renderGameTable();
        } catch (e) {
          unload(lv);
          alert('Error: ' + e.message);
        }
      };
      container.appendChild(playBtn);
    }
  }

  // Phase A — guess who the card is about
  function renderPhaseAUI(container, ts) {
    const turn = ts.turn;
    const alreadyVoted = ts.my_vote && ts.my_vote.is_correct !== null ? true : (ts.my_vote != null);
    const isCardPlayer  = ts.is_card_player;

    // Card display (target hidden)
    const card = el('div', 'ss-playing-card');
    card.innerHTML = `<div class="ss-card-label">Phase A — Who is this card about?</div>
      <div class="ss-card-strength">${escHtml(turn.strength_text)}</div>
      <div class="ss-card-about">___ has this strength</div>`;
    container.appendChild(card);

    if (isCardPlayer) {
      container.innerHTML += '<div class="ss-reveal-banner gold">🃏 You played this card — you\'re sitting out Phase A</div>';
      const waitDiv = el('div', '');
      waitDiv.innerHTML = `<div class="ss-waiting"><div class="ss-dots"><span></span><span></span><span></span></div> ${ts.votes_in}/${ts.expected_voters} votes in…</div>`;
      container.appendChild(waitDiv);
      startPoll(() => renderGameTable(), 4000);
      return;
    }

    if (!alreadyVoted) {
      renderVotingUI(container, ts, 'A');
    } else {
      container.innerHTML += `<div class="ss-reveal-banner info">✓ Vote locked in — waiting for others (${ts.votes_in}/${ts.expected_voters})</div>`;
      startPoll(() => renderGameTable(), 4000);
    }
  }

  // Phase B — guess who wrote it
  function renderPhaseBUI(container, ts) {
    const turn = ts.turn;
    const alreadyVoted = ts.my_vote != null;

    // Phase A reveal banner
    container.innerHTML += `<div class="ss-reveal-banner gold">📣 Phase A Reveal: That card was about <strong>${escHtml(turn.target_name)}</strong></div>`;

    const card = el('div', 'ss-playing-card');
    card.innerHTML = `<div class="ss-card-label">Phase B — Who WROTE this card?</div>
      <div class="ss-card-strength">${escHtml(turn.strength_text)}</div>
      <div class="ss-card-about"><span class="reveal">${escHtml(turn.target_name)}</span> has this strength</div>`;
    container.appendChild(card);

    // Show Phase A vote summary
    if (ts.reveal_votes && ts.reveal_votes.length) {
      renderVoteReveal(container, ts.reveal_votes, 'A', ts.player_id);
    }

    container.appendChild(el('hr', 'ss-divider'));

    if (!alreadyVoted) {
      renderVotingUI(container, ts, 'B');
    } else {
      container.innerHTML += `<div class="ss-reveal-banner info">✓ Phase B vote locked — waiting for others (${ts.votes_in}/${ts.expected_voters})</div>`;
      startPoll(() => renderGameTable(), 4000);
    }
  }

  // Voting UI (shared A/B)
  function renderVotingUI(container, ts, phase) {
    const candidateLabel = el('div', 'ss-section-label', 'Your guess:');
    container.appendChild(candidateLabel);

    const voteGrid = el('div', 'ss-vote-grid');
    const eligible = phase === 'A'
      ? ts.all_players.filter(p => p.id != ts.turn.played_by_id)
      : ts.all_players;

    eligible.forEach(p => {
      const opt = el('div', 'ss-vote-option' + (state.myVote?.id == p.id ? ' selected' : ''), p.display_name);
      opt.onclick = () => {
        state.myVote = { id: p.id };
        eligible.forEach(pp => {
          const prevOpt = voteGrid.querySelector('[data-pid="' + pp.id + '"]');
          if (prevOpt) prevOpt.classList.toggle('selected', pp.id == p.id);
        });
      };
      opt.dataset.pid = p.id;
      voteGrid.appendChild(opt);
    });
    container.appendChild(voteGrid);

    // Confidence toggle
    if ((ts.all_players.find(p => p.id == ts.player_id)?.confidence_tokens || 0) > 0) {
      const confBtn = el('div', 'ss-confidence' + (state.isConfident ? ' active' : ''));
      confBtn.innerHTML = `
        <div>
          <div class="ss-confidence-label">🎯 Use Confidence Token</div>
          <div class="ss-confidence-sub">Correct +3pts · Wrong −3pts (card player gains)</div>
        </div>
        <div class="ss-confidence-tokens">${state.isConfident ? '🟡' : '⚪'}</div>`;
      confBtn.onclick = () => {
        state.isConfident = !state.isConfident;
        confBtn.classList.toggle('active', state.isConfident);
        confBtn.querySelector('.ss-confidence-tokens').textContent = state.isConfident ? '🟡' : '⚪';
      };
      container.appendChild(confBtn);
    }

    const lockBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '🔒 Lock In My Vote');
    lockBtn.onclick = async () => {
      if (!state.myVote) { alert('Please select a player first.'); return; }
      lockBtn.disabled = true;
      try {
        await api('game/vote', 'POST', {
          game_id:            state.gameId,
          turn_id:            ts.turn.id,
          phase,
          selected_player_id: state.myVote.id,
          is_confident:       state.isConfident,
        });
        state.myVote        = null;
        state.isConfident   = false;
        renderGameTable();
      } catch (e) {
        lockBtn.disabled = false;
        alert('Error: ' + e.message);
      }
    };
    container.appendChild(lockBtn);
  }

  // Vote reveal summary
  function renderVoteReveal(container, votes, phase, myId) {
    const div = el('div', '');
    div.style.marginBottom = '12px';
    div.innerHTML = `<div class="ss-section-label" style="margin-bottom:8px;">Phase ${phase} results</div>`;
    votes.forEach(v => {
      const banner = el('div', 'ss-reveal-banner ' + (v.is_correct ? 'correct' : 'wrong'));
      banner.innerHTML = `<span>${v.is_correct ? '✅' : '❌'}</span>
        <span>${escHtml(v.voter_name)} guessed <strong>${escHtml(v.selected_name)}</strong>
        ${v.is_confident ? ' 🎯 CONFIDENT' : ''}
        — ${v.points_earned > 0 ? '+' + v.points_earned : v.points_earned} pts</span>`;
      div.appendChild(banner);
    });
    container.appendChild(div);
  }

  // Turn complete
  function renderTurnComplete(container, ts) {
    const turn = ts.turn;
    container.innerHTML += `<div class="ss-reveal-banner gold">🎉 Turn complete! ${escHtml(turn.author_name)} wrote this card about ${escHtml(turn.target_name)}</div>`;

    // Phase B reveal
    if (ts.reveal_votes && ts.reveal_votes.length) {
      renderVoteReveal(container, ts.reveal_votes, 'B', ts.player_id);
    }

    // Round winner
    if (turn.round_winner_id) {
      const winner = ts.all_players.find(p => p.id == turn.round_winner_id);
      if (winner) {
        container.innerHTML += `<div class="ss-reveal-banner gold">🏅 Round winner: <strong>${escHtml(winner.display_name)}</strong> +1 bonus point!</div>`;
      }
    }

    const nextBtn = el('button', 'ss-btn ss-btn-primary ss-btn-full', '▶ Next Turn');
    nextBtn.style.marginTop = '14px';
    nextBtn.onclick = () => renderGameTable();
    container.appendChild(nextBtn);
  }

  // =========================================================================
  // FINAL RESULTS (F1)
  // =========================================================================
  async function renderFinalResults() {
    const lv = loading('Loading results…');
    try {
      const data = await api('game/results?game_id=' + state.gameId);
      unload(lv);

      const body = el('div', 'ss-screen-body');
      body.innerHTML = '<div class="ss-game-header"><div class="ss-game-title">🏆 Game Complete!</div><div class="ss-game-sub">Super Strengths summary</div></div>';
      const inner = el('div', '');
      inner.style.padding = '20px';

      // Leaderboard
      inner.innerHTML += '<div class="ss-section-label" style="margin-bottom:10px;">Final scores</div>';
      const sb = el('div', 'ss-scoreboard');
      data.scores.forEach((p, i) => {
        const row = el('div', 'ss-score-row' + (i === 0 ? ' winner' : '') + (p.id == data.player_id ? ' me' : ''));
        row.innerHTML = `<div class="ss-score-name">${i === 0 ? '🏆 ' : ''}${escHtml(p.display_name)}</div><div class="ss-score-pts">${p.score_total} pts</div>`;
        sb.appendChild(row);
      });
      inner.appendChild(sb);
      inner.appendChild(el('hr', 'ss-divider'));

      // Cards received
      inner.innerHTML += '<div class="ss-section-label" style="margin-bottom:10px;">Your Super Strength cards</div>';
      const tagCloud = el('div', 'ss-tag-cloud');
      data.my_cards.forEach(c => {
        const t = el('div', 'ss-tag', c.strength_text);
        tagCloud.appendChild(t);
      });
      inner.appendChild(tagCloud);

      // Steve Says AI summary
      if (data.ai_summary) {
        const ss = el('div', 'ss-steve-says');
        ss.innerHTML = `<div class="ss-steve-says-label">💬 Steve Says:</div><div class="ss-steve-says-text">${formatMarkdown(data.ai_summary)}</div>`;
        inner.appendChild(ss);
      }

      body.appendChild(inner);
      render(body);
    } catch (e) {
      unload(lv);
      renderError(e.message);
    }
  }

  // =========================================================================
  // ERROR
  // =========================================================================
  function renderError(msg) {
    const body = el('div', 'ss-screen-body');
    body.innerHTML = `<div style="padding:20px"><div class="ss-error">⚠️ ${escHtml(msg)}</div>
      <button class="ss-btn ss-btn-ghost" onclick="location.reload()">↩ Retry</button></div>`;
    render(body);
  }

  // =========================================================================
  // FORMAT MARKDOWN → HTML
  // =========================================================================
  function formatMarkdown(text) {
    if (!text) return '';
    return text
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\*([^*]+)\*/g, '<em>$1</em>')
      .replace(/\n/g, '<br>');
  }

  // =========================================================================
  // SNAP — STATE
  // =========================================================================
  let snapPollTimer = null;
  let snapBullseye  = null;
  let snapCountdownTimer = null;
  let mobileTapCount = 0;
  let mobileTapTimer = null;

  function stopSnapPoll() {
    if (snapPollTimer) { clearInterval(snapPollTimer); snapPollTimer = null; }
  }
  function startSnapPoll(fn, ms = 500) {
    stopSnapPoll();
    snapPollTimer = setInterval(fn, ms);
  }

  function isMobile() {
    return cfg.isMobileHint || ('ontouchstart' in window) || navigator.maxTouchPoints > 0;
  }

  // =========================================================================
  // SNAP — WAITING ROOM
  // Shown immediately when snap game mode is detected.
  // Each player calls /snap/join which marks them present.
  // When all present → server triggers 3-2-1 countdown.
  // =========================================================================
  async function renderSnapWaiting() {
    stopPoll();
    document.querySelector('.ss-wrap')?.classList.add('ss-snap-mode');

    // Check current snap session state FIRST — player may be rejoining mid-game
    try {
      const current = await api(`snap/session?game_id=${state.gameId}`);
      if (current.status === 'playing' || current.status === 'snap_active' || current.status === 'tiebreaker') {
        renderSnapGame({}); return;
      }
      if (current.status === 'countdown') {
        renderSnapCountdown(current); return;
      }
      if (current.status === 'complete') {
        renderSnapComplete(current); return;
      }
    } catch(e) {}

    // Session is 'waiting' — show the waiting room
    const body = el('div', 'ss-screen-body');
    body.innerHTML = `
      <div class="ss-snap-header">
        <div class="ss-snap-title">🃏 Super Strengths Snap</div>
        <div class="ss-snap-sub">Waiting for all players to join…</div>
      </div>
      <div style="padding:20px;">
        <div class="ss-section-label" style="margin-bottom:12px;">Players joining</div>
        <div id="ss-snap-waiting-players" class="ss-snap-players-grid"></div>
        <div class="ss-snap-info-box" style="margin-top:20px;">
          All players need to be on this page at the same time before the game can start.
          Share the link with your family!
        </div>
      </div>
    `;
    render(body);

    // Signal to server that this player is present
    try {
      await api('snap/join', 'POST', { game_id: state.gameId });
    } catch(e) { /* already joined */ }

    // Poll until all joined and countdown/game starts
    startSnapPoll(async () => {
      try {
        const data = await api(`snap/session?game_id=${state.gameId}`);
        updateWaitingPlayers(data);
        if (data.status === 'countdown') {
          stopSnapPoll();
          renderSnapCountdown(data);
        } else if (data.status === 'playing' || data.status === 'snap_active' || data.status === 'tiebreaker') {
          stopSnapPoll();
          renderSnapGame({});
        } else if (data.status === 'complete') {
          stopSnapPoll();
          renderSnapComplete(data);
        }
      } catch(e) {}
    }, 1500);
  }

  function updateWaitingPlayers(data) {
    const grid = document.getElementById('ss-snap-waiting-players');
    if (!grid) return;
    grid.innerHTML = (data.players || []).map(p => `
      <div class="ss-snap-player-pill ${p.is_present ? 'present' : 'waiting'}">
        <span class="ss-snap-presence-dot"></span>
        <span>${escHtml(p.display_name)}</span>
        <span class="ss-snap-role">${p.is_present ? '✓ Ready' : '⏳ Joining…'}</span>
      </div>
    `).join('');
  }

  // =========================================================================
  // SNAP — COUNTDOWN (3-2-1)
  // =========================================================================
  function renderSnapCountdown(data) {
    stopPoll();
    if (snapCountdownTimer) { clearInterval(snapCountdownTimer); }

    const body = el('div', 'ss-screen-body');
    body.innerHTML = `
      <div class="ss-snap-header">
        <div class="ss-snap-title">🃏 Super Strengths Snap</div>
      </div>
      <div class="ss-snap-countdown-wrap">
        <div id="ss-snap-count-num" class="ss-snap-countdown-num">3</div>
        <div class="ss-snap-countdown-label">Get ready!</div>
      </div>
    `;
    render(body);

    const endsAt = new Date(data.countdown_ends_at.replace(' ','T') + 'Z');
    snapCountdownTimer = setInterval(() => {
      const remaining = Math.ceil((endsAt - Date.now()) / 1000);
      const numEl = document.getElementById('ss-snap-count-num');
      if (numEl) numEl.textContent = Math.max(0, remaining);
      if (remaining <= 0) {
        clearInterval(snapCountdownTimer);
        renderSnapGame({});
      }
    }, 200);
  }

  // =========================================================================
  // SNAP — MAIN GAME SCREEN
  // =========================================================================
  async function renderSnapGame(initial) {
    stopPoll();
    stopSnapPoll();

    const body = el('div', 'ss-screen-body ss-snap-game-body');
    body.innerHTML = `
      <div class="ss-snap-scoreboard" id="ss-snap-scoreboard"></div>
      <div class="ss-snap-arena" id="ss-snap-arena">
        <div class="ss-snap-hands-row" id="ss-snap-hands-row"></div>
        <div class="ss-snap-pile-area" id="ss-snap-pile-area">
          <div class="ss-snap-pile" id="ss-snap-pile"></div>
          <div class="ss-snap-pile-label" id="ss-snap-pile-label"></div>
        </div>
        <div class="ss-snap-action-area" id="ss-snap-action-area"></div>
      </div>
      <div class="ss-snap-status" id="ss-snap-status"></div>
      <div id="ss-snap-bullseye-container" class="ss-snap-bullseye-container"></div>
    `;
    render(body);

    // Create bullseye overlay
    createBullseye();

    // Initial state load + then poll
    await refreshSnapGame();
    startSnapPoll(refreshSnapGame, 500);
  }

  async function refreshSnapGame() {
    try {
      const data = await api(`snap/session?game_id=${state.gameId}`);
      updateSnapGame(data);
      if (data.status === 'complete') {
        stopSnapPoll();
        setTimeout(() => renderSnapComplete(data), 600);
      }
    } catch(e) {}
  }

  function updateSnapGame(data) {
    updateSnapScoreboard(data);
    updateSnapHands(data);
    updateSnapPile(data);
    updateSnapAction(data);
    updateSnapBullseye(data);
  }

  function updateSnapScoreboard(data) {
    const el2 = document.getElementById('ss-snap-scoreboard');
    if (!el2) return;
    const mode = data.snap_mode === 'quick_draw'
      ? `Quick Draw — first to ${data.quick_draw_target} snap${data.quick_draw_target !== 1 ? 's' : ''}`
      : 'Until the Death';
    el2.innerHTML = `
      <div class="ss-snap-mode-label">${escHtml(mode)}</div>
      <div class="ss-snap-scores">
        ${(data.players || []).map(p => `
          <div class="ss-snap-score-pill ${p.is_me ? 'me' : ''}">
            <span class="ss-snap-score-name">${escHtml(p.display_name)}</span>
            <span class="ss-snap-score-pts">${p.snap_score} 🃏</span>
          </div>
        `).join('')}
      </div>
    `;
  }

  function updateSnapHands(data) {
    const row = document.getElementById('ss-snap-hands-row');
    if (!row) return;
    row.innerHTML = (data.players || []).map(p => `
      <div class="ss-snap-hand-block ${p.is_me ? 'me' : ''}">
        <div class="ss-snap-hand-count">${p.hand_count}</div>
        <div class="ss-snap-hand-cards">
          ${Array.from({length: Math.min(p.hand_count, 5)}).map(() =>
            `<div class="ss-snap-mini-card"></div>`
          ).join('')}
        </div>
        <div class="ss-snap-hand-name">${escHtml(p.display_name)}</div>
      </div>
    `).join('');
  }

  function updateSnapPile(data) {
    const pile      = document.getElementById('ss-snap-pile');
    const pileLabel = document.getElementById('ss-snap-pile-label');
    if (!pile) return;

    if (data.pile_top) {
      const t = data.pile_top;

      // Current top card — full sentence: "[Author] thinks [Target] is [Strength]"
      const topHtml = `
        <div class="ss-snap-card ss-snap-card-face-up">
          <div class="ss-snap-card-suit tl">♦</div>
          ${t.author_name ? `<div class="ss-snap-card-author">${escHtml(t.author_name)} thinks</div>` : ''}
          ${t.target_name ? `<div class="ss-snap-card-target-big">${escHtml(t.target_name)}</div>` : ''}
          <div class="ss-snap-card-is-label">is</div>
          <div class="ss-snap-card-text">${escHtml(t.strength_text)}</div>
          <div class="ss-snap-card-suit br">♦</div>
        </div>`;

      // Previous card (straddle) — show behind and slightly offset, full sentence
      let behindHtml = '';
      if (data.pile_second) {
        const s = data.pile_second;
        behindHtml = `
          <div class="ss-snap-card ss-snap-card-behind">
            <div class="ss-snap-card-suit tl">♦</div>
            ${s.author_name ? `<div class="ss-snap-card-author">${escHtml(s.author_name)} thinks</div>` : ''}
            ${s.target_name ? `<div class="ss-snap-card-target-big">${escHtml(s.target_name)}</div>` : ''}
            <div class="ss-snap-card-is-label">is</div>
            <div class="ss-snap-card-text">${escHtml(s.strength_text)}</div>
            <div class="ss-snap-card-suit br">♦</div>
          </div>`;
      }

      pile.innerHTML = behindHtml + topHtml;
      if (pileLabel) pileLabel.textContent = data.pile_count > 1
        ? `${data.pile_count} cards in pile`
        : '1 card';
    } else {
      pile.innerHTML = `<div class="ss-snap-pile-empty">Play a card to start</div>`;
      if (pileLabel) pileLabel.textContent = '';
    }
  }

  function updateSnapAction(data) {
    const area   = document.getElementById('ss-snap-action-area');
    const status = document.getElementById('ss-snap-status');
    if (!area) return;

    const myData = (data.players || []).find(p => p.is_me);
    const isMyTurn = data.current_turn_player_id === data.player_id;
    const snapActive = data.snap_active;
    const currentName = (data.players || []).find(p => p.player_id === data.current_turn_player_id)?.display_name || 'Someone';

    if (snapActive) {
      // Snap is active — show snap instruction, not play button
      area.innerHTML = `
        <div class="ss-snap-match-banner">
          🎯 MATCH! ${isMobile() ? 'Double-tap the bullseye!' : 'Right-click the bullseye!'}
        </div>
      `;
      if (status) status.innerHTML = '';
    } else if (isMyTurn && myData && myData.hand_count > 0) {
      area.innerHTML = `
        <button class="ss-snap-play-btn" id="ss-snap-play-btn">
          ${isMobile() ? '👆 Tap to play card' : '▶ Play Card'}
        </button>
      `;
      if (status) status.textContent = "It's your turn — play a card!";
      document.getElementById('ss-snap-play-btn')?.addEventListener('click', snapPlayCard);
    } else if (isMyTurn && myData && myData.hand_count === 0) {
      area.innerHTML = `<div class="ss-snap-waiting-msg">You have no cards — waiting for a snap!</div>`;
      if (status) status.textContent = '';
    } else {
      area.innerHTML = `<div class="ss-snap-waiting-msg">⏳ Waiting for ${escHtml(currentName)} to play…</div>`;
      if (status) status.textContent = '';
    }
  }

  // =========================================================================
  // SNAP — BULLSEYE SYSTEM
  // =========================================================================
  function createBullseye() {
    const container = document.getElementById('ss-snap-bullseye-container');
    if (!container) return;

    snapBullseye = document.createElement('div');
    snapBullseye.id = 'ss-snap-bullseye';
    snapBullseye.className = 'ss-snap-bullseye hidden';
    snapBullseye.innerHTML = `
      <div class="ss-snap-bullseye-ring outer"></div>
      <div class="ss-snap-bullseye-ring middle"></div>
      <div class="ss-snap-bullseye-inner">
        <div class="ss-snap-bullseye-word">SNAP!</div>
        <div class="ss-snap-bullseye-timer" id="ss-snap-bullseye-timer"></div>
      </div>
    `;

    // Both left-click and right-click claim snap on all devices.
    // The moving random position + short timer is the anti-cheat — not the button type.
    snapBullseye.addEventListener('click', () => {
      if (!snapBullseye.classList.contains('hidden')) claimSnap();
    });
    snapBullseye.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      if (!snapBullseye.classList.contains('hidden')) claimSnap();
    });

    container.appendChild(snapBullseye);
  }

  function updateSnapBullseye(data) {
    if (!snapBullseye) return;

    if (data.snap_active && data.snap_x !== null) {
      // Position bullseye at server-specified random location
      snapBullseye.style.left = data.snap_x + '%';
      snapBullseye.style.top  = data.snap_y + '%';
      snapBullseye.classList.remove('hidden');
      if (data.is_tiebreaker) snapBullseye.classList.add('tiebreaker');
      else snapBullseye.classList.remove('tiebreaker');

      // Client-side countdown display
      if (data.snap_expires_at) {
        const expiresAt = new Date(data.snap_expires_at.replace(' ','T') + 'Z');
        const timerEl = document.getElementById('ss-snap-bullseye-timer');
        if (timerEl) {
          const remaining = Math.max(0, (expiresAt - Date.now()) / 1000);
          timerEl.textContent = remaining.toFixed(1) + 's';
        }
      }
    } else {
      snapBullseye.classList.add('hidden');
      mobileTapCount = 0;
    }
  }

  // =========================================================================
  // SNAP — PLAY CARD
  // =========================================================================
  async function snapPlayCard() {
    const btn = document.getElementById('ss-snap-play-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Playing…'; }
    try {
      await api('snap/play-card', 'POST', { game_id: state.gameId });
      // State will update via next poll — don't re-enable button
    } catch(e) {
      // 409 = not your turn (stale state) — let poll refresh, don't re-enable
      if (btn && e.message && !e.message.includes('409') && !e.message.includes('Conflict')) {
        btn.disabled = false;
        btn.textContent = isMobile() ? '👆 Tap to play card' : '▶ Play Card';
      }
    }
  }

  // =========================================================================
  // SNAP — CLAIM SNAP
  // =========================================================================
  let snapClaiming = false; // guard against double-fire

  async function claimSnap() {
    if (snapClaiming) return;
    if (!snapBullseye || snapBullseye.classList.contains('hidden')) return;
    snapClaiming = true;

    // Hide bullseye immediately — optimistic UI, prevents double-click 409s
    snapBullseye.classList.add('hidden');
    snapBullseye.classList.add('claiming');

    try {
      const data = await api('snap/claim', 'POST', { game_id: state.gameId });
      showSnapResult(data);
    } catch(e) {
      console.log('Snap claim failed — reason:', e.message);
      snapBullseye.classList.remove('claiming');
    } finally {
      snapClaiming = false;
    }
  }

  function showSnapResult(data) {
    const myData  = (data.players || []).find(p => p.is_me);
    const winner  = (data.players || []).find(p => p.player_id === data.last_snap_winner_id);
    const iWon    = myData && data.last_snap_winner_id === data.player_id;

    const flash = document.createElement('div');
    flash.className = 'ss-snap-result-flash ' + (iWon ? 'win' : 'lose');
    flash.innerHTML = iWon
      ? `<div class="ss-snap-flash-text">⚡ SNAP! You got it!</div>`
      : `<div class="ss-snap-flash-text">⚡ ${escHtml(winner?.display_name || 'Someone')} snapped it!</div>`;

    const arena = document.getElementById('ss-snap-arena');
    if (arena) arena.appendChild(flash);
    setTimeout(() => flash.remove(), 2000);
  }

  // =========================================================================
  // SNAP — COMPLETE / RESULTS
  // =========================================================================
  function renderSnapComplete(data) {
    stopSnapPoll();
    if (snapBullseye) snapBullseye.classList.add('hidden');
    document.querySelector('.ss-wrap')?.classList.remove('ss-snap-mode');

    const winner = (data.players || []).find(p => p.player_id === data.winner_player_id);
    const iWon   = data.winner_player_id === data.player_id;
    const sorted = [...(data.players || [])].sort((a,b) => b.snap_score - a.snap_score);

    const body = el('div', 'ss-screen-body');
    body.innerHTML = `
      <div class="ss-snap-header ${iWon ? 'winner-header' : ''}">
        <div class="ss-snap-title">${iWon ? '🏆 You Won!' : '🎉 Game Over!'}</div>
        <div class="ss-snap-sub">${iWon ? 'Amazing snapping!' : `${escHtml(winner?.display_name || 'Someone')} wins!`}</div>
      </div>
      <div style="padding:20px;">
        <div class="ss-section-label" style="margin-bottom:12px;">Final Scores</div>
        <div class="ss-snap-final-scores">
          ${sorted.map((p, i) => `
            <div class="ss-snap-final-row ${p.is_me ? 'me' : ''} ${i === 0 ? 'winner' : ''}">
              <span class="ss-snap-final-pos">${i === 0 ? '🏆' : (i+1)}</span>
              <span class="ss-snap-final-name">${escHtml(p.display_name)}</span>
              <span class="ss-snap-final-score">${p.snap_score} snap${p.snap_score !== 1 ? 's' : ''}</span>
            </div>
          `).join('')}
        </div>

        <hr class="ss-divider" style="margin:20px 0;">
        <div class="ss-section-label" style="margin-bottom:14px;">🌟 Strengths Revealed</div>
        <div id="ss-summary-area">
          <div class="ss-waiting"><div class="ss-dots"><span></span><span></span><span></span></div> Loading your strengths…</div>
        </div>
      </div>
    `;
    render(body);

    // Fetch summary + Steve AI
    api(`game/summary?game_id=${state.gameId}`)
      .then(summary => {
        const area = document.getElementById('ss-summary-area');
        if (!area) return;
        let html = '';

        // Steve AI block
        if (summary.ai_summary) {
          html += `
            <div class="ss-steve-summary-block">
              <div class="ss-steve-avatar-row">
                <span class="ss-steve-icon">💬</span>
                <span class="ss-steve-label">Steve Says</span>
              </div>
              <div class="ss-steve-summary-text">${escHtml(summary.ai_summary).replace(/\n/g,'<br>')}</div>
            </div>`;
        }

        // Cards by target
        (summary.cards || []).forEach(group => {
          html += `
            <div class="ss-summary-group ${group.is_me ? 'is-me' : ''}">
              <div class="ss-summary-group-title">
                ${group.is_me ? '⭐ Your Strengths' : `${escHtml(group.target_name)}'s Strengths`}
              </div>
              <div class="ss-summary-cards-grid">
                ${(group.strengths || []).map(s => `
                  <div class="ss-summary-card">
                    <div class="ss-summary-card-strength">${escHtml(s.text)}</div>
                    <div class="ss-summary-card-from">from ${escHtml(s.author)}</div>
                  </div>`).join('')}
              </div>
            </div>`;
        });

        // Role-based navigation buttons
        const isStudent = state.player?.role === 'student';
        const studentPlayer = state.allPlayers.find(p => p.role === 'student');
        const studentUserId = isStudent ? cfg.userId : (studentPlayer?.user_id || '');
        const courseUrl = cfg.portalUrl + '?course_id=' + cfg.courseId + '&student_id=' + studentUserId;

        let btnHtml = `<div style="display:flex;flex-direction:column;gap:10px;margin-top:20px;">`;
        if (isStudent) {
          btnHtml += `<a href="${escHtml(cfg.badgesUrl)}" class="ss-btn ss-btn-gold ss-btn-full">🏅 See My Badges</a>`;
        }
        btnHtml += `<a href="${escHtml(courseUrl)}" class="ss-btn ss-btn-ghost ss-btn-full">📚 Course Details</a>`;
        btnHtml += `</div>`;

        html += btnHtml;

        area.innerHTML = html;
      })
      .catch(() => {
        const area = document.getElementById('ss-summary-area');
        if (!area) return;
        const isStudent = state.player?.role === 'student';
        const studentPlayer = state.allPlayers.find(p => p.role === 'student');
        const studentUserId = isStudent ? cfg.userId : (studentPlayer?.user_id || '');
        const courseUrl = cfg.portalUrl + '?course_id=' + cfg.courseId + '&student_id=' + studentUserId;
        area.innerHTML = `
          <div style="display:flex;flex-direction:column;gap:10px;margin-top:12px;">
            ${isStudent ? `<a href="${escHtml(cfg.badgesUrl)}" class="ss-btn ss-btn-gold ss-btn-full">🏅 See My Badges</a>` : ''}
            <a href="${escHtml(courseUrl)}" class="ss-btn ss-btn-ghost ss-btn-full">📚 Course Details</a>
          </div>`;
      });
  }

  // =========================================================================
  // MEMORY GAME — HEARTBEAT (MYF-167)
  // =========================================================================
  function startHeartbeat() {
    stopHeartbeat();
    state.heartbeatTimer = setInterval(async () => {
      try { await api('memory/heartbeat', 'POST', { game_id: state.gameId }); } catch(_) {}
    }, 30000);
  }

  function stopHeartbeat() {
    if (state.heartbeatTimer) { clearInterval(state.heartbeatTimer); state.heartbeatTimer = null; }
  }

  function getCurrentStateForUI() {
    return {
      status:                 state.gameStatus,
      current_turn_player_id: state.currentTurnPlayerId,
      game_ends_at:           state.gameEndsAt,
      all_players:            state.allPlayers,
      winner_player_id:       state.winnerPlayerId,
    };
  }

  // =========================================================================
  // MEMORY GAME — BOARD (MYF-167)
  // =========================================================================
  async function renderBoard() {
    stopPoll();
    startHeartbeat();

    const lv = loading('Loading board…');
    try {
      const [stateData, boardData] = await Promise.all([
        api('memory/state'),
        api('memory/board?game_id=' + state.gameId),
      ]);
      unload(lv);

      state.allPlayers          = stateData.all_players          || state.allPlayers;
      state.player              = stateData.player               || state.player;
      state.currentTurnPlayerId = stateData.current_turn_player_id || null;
      state.gameEndsAt          = stateData.game_ends_at         || null;
      state.board               = boardData.positions            || [];

      if (stateData.status === 'complete') {
        stopHeartbeat();
        state.gameStatus     = 'complete';
        state.winnerPlayerId = stateData.winner_player_id || null;
        renderGameOver(state.winnerPlayerId);
        return;
      }

      renderBoardUI(stateData, state.board);

      const myPlayerId = state.player ? state.player.id : null;
      if (state.currentTurnPlayerId !== myPlayerId) {
        startPoll(async () => {
          try {
            const [sd, bd] = await Promise.all([
              api('memory/state'),
              api('memory/board?game_id=' + state.gameId),
            ]);
            state.allPlayers          = sd.all_players              || state.allPlayers;
            state.player              = sd.player                   || state.player;
            state.currentTurnPlayerId = sd.current_turn_player_id   || null;
            state.gameEndsAt          = sd.game_ends_at             || null;
            state.board               = bd.positions                || [];

            if (sd.status === 'complete') {
              stopPoll(); stopHeartbeat();
              state.gameStatus     = 'complete';
              state.winnerPlayerId = sd.winner_player_id || null;
              renderGameOver(state.winnerPlayerId);
              return;
            }
            renderBoardUI(sd, state.board);
            if (sd.current_turn_player_id === (state.player && state.player.id)) {
              stopPoll();
            }
          } catch(_) {}
        }, 4000);
      }
    } catch(e) {
      unload(lv);
      renderError(e.message);
    }
  }

  function renderBoardUI(stateData, positions) {
    const myPlayerId  = state.player ? state.player.id : null;
    const isMyTurn    = stateData.current_turn_player_id === myPlayerId;
    const allPlayers  = stateData.all_players || state.allPlayers || [];

    const body = el('div', 'ss-screen-body');

    const header = el('div', 'ss-game-header');
    header.innerHTML = `<div class="ss-game-title">🧠 Super Strengths Memory</div>`;
    body.appendChild(header);

    // Scoreboard
    const scoreboard = el('div', 'ss-scoreboard');
    allPlayers.forEach(p => {
      const isActive  = p.id === stateData.current_turn_player_id;
      const presence  = p.is_present ? 'online' : 'offline';
      const pill      = el('div', 'ss-score-pill' + (isActive ? ' active' : ''));
      pill.innerHTML  = `<span class="ss-presence-dot ${presence}"></span><span class="ss-score-name">${escHtml(p.display_name)}</span><span class="ss-score-val">${p.score}</span>`;
      scoreboard.appendChild(pill);
    });
    body.appendChild(scoreboard);

    // Turn indicator
    const turnBar = el('div', 'ss-turn-indicator' + (isMyTurn ? ' my-turn' : ''));
    if (isMyTurn) {
      turnBar.innerHTML = `<span class="ss-turn-pulse"></span> Your turn — flip a card!`;
    } else {
      const turnPlayer = allPlayers.find(p => p.id === stateData.current_turn_player_id);
      turnBar.textContent = (turnPlayer ? escHtml(turnPlayer.display_name) : 'Someone') + '\'s turn…';
    }
    body.appendChild(turnBar);

    // Away banner
    const awayPlayer = allPlayers.find(p => p.id === stateData.current_turn_player_id && p.is_present === false);
    if (awayPlayer) body.appendChild(renderAwayNoticeBanner(awayPlayer));

    // Board progress
    const totalPairs   = Math.floor(positions.length / 2);
    const matchedPairs = positions.filter(p => p.is_matched).length / 2;
    const progress     = el('div', 'ss-board-progress');
    progress.textContent = `${Math.round(matchedPairs)} / ${totalPairs} pairs matched`;
    body.appendChild(progress);

    // Game timer (timed mode)
    if (stateData.game_ends_at) {
      const timerEl   = el('div', 'ss-game-timer');
      const endsAt    = new Date(stateData.game_ends_at.replace(' ', 'T') + 'Z');
      const updateTimer = () => {
        const secs = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
        const m    = Math.floor(secs / 60).toString().padStart(2, '0');
        const s    = (secs % 60).toString().padStart(2, '0');
        timerEl.textContent = `⏱ ${m}:${s}`;
      };
      updateTimer();
      const clockInterval = setInterval(updateTimer, 1000);
      timerEl._clockInterval = clockInterval;
      body.appendChild(timerEl);
    }

    // Board grid
    const colCount  = positions.length > 24 ? 6 : 4;
    const boardGrid = el('div', `ss-board-grid ss-board-grid-${colCount}`);

    positions.forEach((pos, i) => {
      const tile = el('div', 'ss-card-tile');
      if (pos.is_matched) {
        tile.classList.add('matched');
        tile.innerHTML = `<div class="ss-card-tile-front"><div class="ss-ct-label">${escHtml(pos.content ? pos.content.label : '')}</div></div>`;
      } else if (pos.is_face_up) {
        tile.classList.add('face-up');
        tile.innerHTML = `<div class="ss-card-tile-front"><div class="ss-ct-label">${escHtml(pos.content ? pos.content.label : '')}</div></div>`;
      } else {
        tile.innerHTML = `<div class="ss-card-tile-back"><span class="ss-ct-back-icon">⭐</span></div>`;
        if (isMyTurn) {
          tile.classList.add('flippable');
          tile.addEventListener('click', () => handleFlip(pos.position, tile, boardGrid));
        }
      }
      boardGrid.appendChild(tile);
    });

    body.appendChild(boardGrid);
    render(body);
  }

  async function handleFlip(position, tileEl, boardGrid) {
    if (tileEl.classList.contains('flipping')) return;
    tileEl.classList.add('flipping');

    // Disable all tile clicks during the API round-trip
    boardGrid.querySelectorAll('.flippable').forEach(t => {
      t.classList.remove('flippable');
      t.onclick = null;
    });

    try {
      const res = await api('memory/flip', 'POST', { game_id: state.gameId, position });

      if (res.flip_number === 1) {
        // Optimistic update: mark this position face-up in local board state
        const boardPos = state.board.find(p => p.position === position);
        if (boardPos) { boardPos.is_face_up = true; boardPos.content = res.content; }
        state.pendingFlipPos = position;
        renderBoardUI(getCurrentStateForUI(), state.board);
        return;
      }

      // Flip 2 response — update local board state
      const boardPos = state.board.find(p => p.position === position);
      if (boardPos) {
        boardPos.is_face_up = true;
        boardPos.content    = res.is_match ? res.matched_pair[1] : res.flip2_content;
      }

      if (res.is_match) {
        // Mark both as matched locally
        state.board.forEach(p => {
          if (p.is_face_up && !p.is_matched) {
            p.is_matched   = true;
            p.is_face_up   = false;
            p.matched_by   = myPlayerId();
          }
        });
        state.pendingFlipPos = null;
        if (state.player) {
          state.player.score = res.new_score;
          const ap = state.allPlayers.find(p => p.id === state.player.id);
          if (ap) ap.score = res.new_score;
        }

        renderBoardUI(getCurrentStateForUI(), state.board);
        await showMatchMoment(res.matched_pair, res.is_self_strength_match);

        if (res.game_complete) {
          stopHeartbeat(); stopPoll();
          state.gameStatus     = 'complete';
          state.winnerPlayerId = res.winner_player_id;
          renderGameOver(res.winner_player_id);
          return;
        }

        // Same player goes again — re-render with updated board
        renderBoardUI(getCurrentStateForUI(), state.board);

      } else {
        // No match — show both face-up for 1.5 s then rotate
        renderBoardUI(getCurrentStateForUI(), state.board);
        await new Promise(r => setTimeout(r, 1500));

        state.board.forEach(p => { if (p.is_face_up && !p.is_matched) p.is_face_up = false; });
        state.pendingFlipPos      = null;
        state.currentTurnPlayerId = res.next_player_id || null;

        if (res.next_player_id !== myPlayerId()) {
          renderBoardUI(getCurrentStateForUI(), state.board);
          // Start polling since it's not our turn
          startPoll(async () => {
            try {
              const [sd, bd] = await Promise.all([
                api('memory/state'),
                api('memory/board?game_id=' + state.gameId),
              ]);
              state.allPlayers          = sd.all_players              || state.allPlayers;
              state.player              = sd.player                   || state.player;
              state.currentTurnPlayerId = sd.current_turn_player_id   || null;
              state.gameEndsAt          = sd.game_ends_at             || null;
              state.board               = bd.positions                || [];

              if (sd.status === 'complete') {
                stopPoll(); stopHeartbeat();
                state.gameStatus     = 'complete';
                state.winnerPlayerId = sd.winner_player_id || null;
                renderGameOver(state.winnerPlayerId);
                return;
              }
              renderBoardUI(sd, state.board);
              if (sd.current_turn_player_id === myPlayerId()) stopPoll();
            } catch(_) {}
          }, 4000);
        } else {
          renderBoardUI(getCurrentStateForUI(), state.board);
        }
      }
    } catch(e) {
      renderError(e.message);
    }
  }

  function myPlayerId() {
    return state.player ? state.player.id : null;
  }

  function showMatchMoment(matchedPair, isSelfStrengthMatch) {
    return new Promise(resolve => {
      const icon    = isSelfStrengthMatch ? '✨' : '🎉';
      const label   = matchedPair && matchedPair[0] ? escHtml(matchedPair[0].label) : '';
      const overlay = el('div', 'ss-match-flash');
      overlay.innerHTML = `
        <div class="ss-match-flash-inner">
          <div class="ss-match-icon">${icon}</div>
          <div class="ss-match-text">Match!</div>
          <div class="ss-match-label">${label}</div>
        </div>`;
      document.getElementById('mfsd-ss-root').appendChild(overlay);
      setTimeout(() => {
        overlay.classList.add('fade-out');
        setTimeout(() => { overlay.remove(); resolve(); }, 400);
      }, 2100);
    });
  }

  function renderAwayNoticeBanner(awayPlayer) {
    const banner = el('div', 'ss-away-banner');
    banner.textContent = `${escHtml(awayPlayer.display_name)} hasn't been seen recently — waiting for them to return.`;
    return banner;
  }

  // =========================================================================
  // MEMORY GAME — GAME OVER (MYF-167)
  // =========================================================================
  function renderGameOver(winnerPlayerId) {
    stopPoll(); stopHeartbeat();

    api('memory/state').then(data => {
      if (data.game_id) state.gameId = data.game_id;
      const allPlayers = data.all_players || state.allPlayers || [];
      const winnerId   = winnerPlayerId || data.winner_player_id || null;
      const winnerRow  = allPlayers.find(p => p.id === winnerId);
      const winnerName = winnerRow ? winnerRow.display_name : 'the family';
      const isWinner   = winnerId === myPlayerId();

      const body   = el('div', 'ss-screen-body');
      const header = el('div', 'ss-game-header');
      header.innerHTML = `<div class="ss-game-title">🏆 Game Over!</div>`;
      body.appendChild(header);

      const inner = el('div', '');
      inner.style.cssText = 'padding:32px 20px;text-align:center;';

      const trophy   = isWinner ? '🏆' : '🌟';
      const headline = isWinner ? `You won, ${escHtml(winnerName)}!` : `${escHtml(winnerName)} wins!`;

      inner.innerHTML = `
        <div style="font-size:56px;margin-bottom:16px;">${trophy}</div>
        <div style="font-size:22px;font-weight:700;margin-bottom:24px;">${headline}</div>`;

      const scoreTable = el('div', 'ss-scoreboard ss-scoreboard-final');
      const sorted     = [...allPlayers].sort((a, b) => b.score - a.score);
      sorted.forEach((p, i) => {
        const pill = el('div', 'ss-score-pill' + (p.id === winnerId ? ' active' : ''));
        pill.innerHTML = `<span>${i + 1}.</span><span class="ss-score-name">${escHtml(p.display_name)}</span><span class="ss-score-val">${p.score} pair${p.score !== 1 ? 's' : ''}</span>`;
        scoreTable.appendChild(pill);
      });
      inner.appendChild(scoreTable);
      body.appendChild(inner);
      body.appendChild(renderPostGamePlaceholder());
      render(body);
    }).catch(() => {
      const body = el('div', 'ss-screen-body');
      body.innerHTML = `<div style="padding:40px;text-align:center;"><div style="font-size:48px;">🏆</div><div style="margin-top:16px;">Game complete!</div></div>`;
      body.appendChild(renderPostGamePlaceholder());
      render(body);
    });
  }

  function renderPostGamePlaceholder() {
    const wrap = el('div', '');
    wrap.style.cssText = 'padding:16px 20px 32px;text-align:center;';
    const btn = el('button', 'ss-btn ss-btn-gold', '⭐ View Your Strengths Summary →');
    btn.style.cssText = 'display:block;width:100%;max-width:320px;margin:0 auto;';
    btn.onclick = renderSummary;
    wrap.appendChild(btn);
    const note = el('p', '');
    note.style.cssText = 'color:var(--ss-text-dim);font-size:12px;margin-top:12px;';
    note.textContent = "See Steve's analysis and earn your badge!";
    wrap.appendChild(note);
    return wrap;
  }

  // =========================================================================
  // MEMORY GAME — SUMMARY SCREEN (MYF-170)
  // =========================================================================
  async function renderSummary() {
    const body = el('div', 'ss-screen-body');
    const loadDiv = el('div', '');
    loadDiv.style.cssText = 'padding:60px 20px;text-align:center;';
    loadDiv.innerHTML = '<div class="ss-waiting"><div class="ss-dots"><span></span><span></span><span></span></div>'
      + '<div style="margin-top:14px;color:var(--ss-text-dim);font-size:14px;">Generating your strengths summary…</div></div>';
    body.appendChild(loadDiv);
    render(body);

    try {
      const summaryData = await api('memory/summary?game_id=' + state.gameId, 'GET');
      const isStudent   = summaryData.player_role === 'student';

      let badgeInfo = null;
      if (isStudent) {
        try { badgeInfo = await api('memory/award-badge', 'POST', { game_id: state.gameId }); }
        catch (_) {}
      }

      renderSummaryUI(summaryData, badgeInfo);
    } catch (e) {
      const b = el('div', 'ss-screen-body');
      b.innerHTML = '<div style="padding:40px;text-align:center;">'
        + '<div style="color:var(--ss-red);margin-bottom:16px;">Could not load summary. Please try again.</div>'
        + '<button class="ss-btn ss-btn-gold" onclick="location.reload()">Reload</button></div>';
      render(b);
    }
  }

  function renderSummaryUI(data, badgeInfo) {
    const isStudent = data.player_role === 'student';
    const body      = el('div', 'ss-screen-body');

    const header = el('div', 'ss-game-header');
    header.innerHTML = '<div class="ss-game-title">⭐ Strengths Summary</div>'
      + '<div class="ss-game-sub">' + escHtml(data.student_name) + ' &amp; Family</div>';
    body.appendChild(header);

    const inner = el('div', '');
    inner.style.paddingBottom = '32px';

    if (isStudent) {
      renderStudentSummaryContent(inner, data, badgeInfo);
    } else {
      renderParentSummaryContent(inner, data);
    }

    body.appendChild(inner);
    render(body);

    // Init chat after render — parents need to click Tab 2, students get it immediately
    if (isStudent) initSummaryChatWidget();
  }

  function renderStudentSummaryContent(container, data, badgeInfo) {
    // Self-strengths
    const selfCard = el('div', 'ss-card');
    selfCard.style.margin = '16px';
    selfCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:10px;">Your 5 Super Strengths</div>';
    const chips = el('div', 'ss-strength-chips');
    (data.self_strengths || []).forEach(s => chips.appendChild(el('span', 'ss-strength-chip', s)));
    selfCard.appendChild(chips);
    container.appendChild(selfCard);

    // Family wrote about me
    if ((data.family_wrote_about_me || []).length) {
      const famCard = el('div', 'ss-card');
      famCard.style.margin = '0 16px 16px';
      famCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:10px;">How Your Family Sees You</div>';
      const list = el('div', 'ss-family-card-list');
      data.family_wrote_about_me.forEach(c => {
        const row = el('div', 'ss-family-card-row');
        row.innerHTML = '<span>' + escHtml(c.strength_text) + '</span>'
          + '<span class="ss-family-card-author">— ' + escHtml(c.author_display) + '</span>';
        list.appendChild(row);
      });
      famCard.appendChild(list);
      container.appendChild(famCard);
    }

    // AI sections
    const sections = data.sections || {};
    if (Object.keys(sections).length) {
      const aiCard = el('div', 'ss-card');
      aiCard.style.margin = '0 16px 16px';
      aiCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:12px;">💡 Steve\'s Analysis</div>';
      aiCard.appendChild(renderAISectionTabs(sections));
      container.appendChild(aiCard);
    }

    // Badge award
    if (badgeInfo && (badgeInfo.completion_earned || badgeInfo.winner_badge_slug)) {
      const badgeWrap = el('div', 'ss-card');
      badgeWrap.style.margin = '0 16px 16px';
      container.appendChild(badgeWrap);
      setTimeout(() => {
        badgeWrap.innerHTML = '<div class="ss-section-label" style="margin-bottom:12px;text-align:center;">🎖️ Badge' + (badgeInfo.winner_badge_slug ? 's' : '') + ' Earned!</div>';
        if (badgeInfo.completion_earned) {
          renderBadgeAward(badgeWrap, badgeInfo.completion_badge_url, 'Super Strengths', 10);
        }
        if (badgeInfo.winner_badge_slug) {
          renderBadgeAward(badgeWrap, badgeInfo.winner_badge_url, 'Winner', 15);
        }
      }, 600);
    }

    // Chat widget placeholder
    const chatEl = el('div', '');
    chatEl.id = 'ss-chat-placeholder';
    chatEl.style.padding = '0 16px 16px';
    container.appendChild(chatEl);
  }

  function renderParentSummaryContent(container, data) {
    // Main tabs
    const tabBar = el('div', 'ss-summary-tabs');
    const tab1   = el('button', 'ss-summary-tab-btn active', '📚 Student View');
    const tab2   = el('button', 'ss-summary-tab-btn', '👁️ Your Analysis');
    tabBar.appendChild(tab1);
    tabBar.appendChild(tab2);
    container.appendChild(tabBar);

    const pane1 = el('div', 'ss-summary-tab-content active');
    const pane2 = el('div', 'ss-summary-tab-content');

    // ── Pane 1: Student summary (read-only) ─────────────────────────────────

    const ssCard = el('div', 'ss-card');
    ssCard.style.margin = '16px';
    ssCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:10px;">'
      + escHtml(data.student_name) + "'s Chosen Strengths</div>";
    const ssChips = el('div', 'ss-strength-chips');
    (data.student_self_strengths || []).forEach(s => ssChips.appendChild(el('span', 'ss-strength-chip', s)));
    ssCard.appendChild(ssChips);
    pane1.appendChild(ssCard);

    if ((data.family_wrote_about_student || []).length) {
      const fCard = el('div', 'ss-card');
      fCard.style.margin = '0 16px 16px';
      fCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:10px;">Family wrote…</div>';
      const list = el('div', 'ss-family-card-list');
      data.family_wrote_about_student.forEach(c => {
        const row = el('div', 'ss-family-card-row');
        row.innerHTML = '<span>' + escHtml(c.strength_text) + '</span>'
          + '<span class="ss-family-card-author">— ' + escHtml(c.author_display) + '</span>';
        list.appendChild(row);
      });
      fCard.appendChild(list);
      pane1.appendChild(fCard);
    }

    if (data.student_ai_summary) {
      const aiCard = el('div', 'ss-card');
      aiCard.style.margin = '0 16px 16px';
      aiCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:8px;">💡 '
        + escHtml(data.student_name) + "'s Insight</div>";
      const p = el('p', '');
      p.style.cssText = 'font-size:14px;line-height:1.75;color:var(--ss-text);white-space:pre-wrap;margin:0;';
      p.textContent = data.student_ai_summary;
      aiCard.appendChild(p);
      pane1.appendChild(aiCard);
    } else {
      const note = el('p', '');
      note.style.cssText = 'padding:0 16px 16px;color:var(--ss-text-dim);font-size:13px;';
      note.textContent = data.student_name + "'s personal analysis will appear here once they've viewed their summary.";
      pane1.appendChild(note);
    }

    // ── Pane 2: Parent view ──────────────────────────────────────────────────

    if ((data.parent_self_strengths || []).length) {
      const psCard = el('div', 'ss-card');
      psCard.style.margin = '16px';
      psCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:10px;">Your Chosen Strengths</div>';
      const psChips = el('div', 'ss-strength-chips');
      data.parent_self_strengths.forEach(s => psChips.appendChild(el('span', 'ss-strength-chip', s)));
      psCard.appendChild(psChips);
      pane2.appendChild(psCard);
    }

    if ((data.student_wrote_about_parent || []).length) {
      const swCard = el('div', 'ss-card');
      swCard.style.margin = '0 16px 16px';
      swCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:10px;">'
        + escHtml(data.student_name) + ' wrote about you…</div>';
      const list = el('div', 'ss-family-card-list');
      data.student_wrote_about_parent.forEach(c => {
        const row = el('div', 'ss-family-card-row');
        row.innerHTML = '<span>' + escHtml(c.strength_text) + '</span>';
        list.appendChild(row);
      });
      swCard.appendChild(list);
      pane2.appendChild(swCard);
    }

    const sections = data.sections || {};
    if (Object.keys(sections).length) {
      const aiCard2 = el('div', 'ss-card');
      aiCard2.style.margin = '0 16px 16px';
      aiCard2.innerHTML = '<div class="ss-section-label" style="margin-bottom:12px;">💡 Steve\'s Analysis</div>';
      aiCard2.appendChild(renderAISectionTabs(sections));
      pane2.appendChild(aiCard2);
    }

    const chatEl2 = el('div', '');
    chatEl2.id = 'ss-chat-placeholder';
    chatEl2.style.padding = '0 16px 16px';
    pane2.appendChild(chatEl2);

    container.appendChild(pane1);
    container.appendChild(pane2);

    tab1.onclick = () => {
      tab1.classList.add('active'); tab2.classList.remove('active');
      pane1.classList.add('active'); pane2.classList.remove('active');
    };
    tab2.onclick = () => {
      tab2.classList.add('active'); tab1.classList.remove('active');
      pane2.classList.add('active'); pane1.classList.remove('active');
      const ph = document.getElementById('ss-chat-placeholder');
      if (ph && !ph.dataset.initialized) initSummaryChatWidget();
    };
  }

  function renderAISectionTabs(sections) {
    const wrap  = el('div', '');
    const keys  = Object.keys(sections);
    if (!keys.length) return wrap;

    const tabRow = el('div', 'ss-ai-section-tabs');
    const panes  = [];

    keys.forEach((key, i) => {
      const s   = sections[key];
      const btn = el('button', 'ss-ai-section-btn' + (i === 0 ? ' active' : ''), s.label);
      const pane = el('div', 'ss-ai-section-content' + (i === 0 ? ' active' : ''));
      pane.textContent = s.content;
      panes.push(pane);

      btn.onclick = () => {
        tabRow.querySelectorAll('.ss-ai-section-btn').forEach(b => b.classList.remove('active'));
        panes.forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        pane.classList.add('active');
      };
      tabRow.appendChild(btn);
    });

    wrap.appendChild(tabRow);
    panes.forEach(p => wrap.appendChild(p));
    return wrap;
  }

  function renderBadgeAward(container, imageUrl, type, coins) {
    const div = el('div', 'ss-badge-award-item');
    if (imageUrl) {
      const img = document.createElement('img');
      img.className = 'ss-badge-award-img';
      img.src       = imageUrl;
      img.alt       = type + ' badge';
      img.onerror   = () => { img.replaceWith(el('div', 'ss-badge-award-fallback', type === 'Winner' ? '🏆' : '⭐')); };
      div.appendChild(img);
    } else {
      div.appendChild(el('div', 'ss-badge-award-fallback', type === 'Winner' ? '🏆' : '⭐'));
    }
    div.appendChild(el('div', 'ss-badge-award-name', 'Super Strengths ' + type + ' Badge'));
    div.appendChild(el('div', 'ss-badge-award-coins', '+' + coins + ' coins'));
    container.appendChild(div);
  }

  async function initSummaryChatWidget() {
    const placeholder = document.getElementById('ss-chat-placeholder');
    if (!placeholder || placeholder.dataset.initialized) return;
    placeholder.dataset.initialized = 'true';

    try {
      const config = await api('memory/chat-widget?game_id=' + state.gameId, 'GET');
      if (!config.ok || !config.chatbot_id) return;

      const widget = el('div', 'ss-chat-widget');

      const chatHeader = el('div', 'ss-chat-header');
      chatHeader.innerHTML = '<span class="ss-chat-avatar-text">' + escHtml(config.avatar || '💬') + '</span>'
        + '<span class="ss-chat-name">' + escHtml(config.ai_name || 'Steve') + '</span>';
      widget.appendChild(chatHeader);

      const msgs = el('div', 'ss-chat-messages');
      const greeting = el('div', 'ss-chat-msg ai');
      greeting.textContent = config.greeting || 'Ask me about your Super Strengths!';
      msgs.appendChild(greeting);
      widget.appendChild(msgs);

      const inputRow = el('div', 'ss-chat-input-row');
      const input    = el('textarea', 'ss-chat-input');
      input.placeholder = 'Ask me anything…';
      input.rows = 1;
      const sendBtn = el('button', 'ss-chat-send-btn', 'Send');

      const ajaxUrl = (window.stevegpt || {}).ajax_url || '/wp-admin/admin-ajax.php';
      const nonce   = (window.stevegpt || {}).nonce || '';

      async function sendMessage() {
        const msg = input.value.trim();
        if (!msg || sendBtn.disabled) return;
        input.value = '';
        input.style.height = 'auto';
        sendBtn.disabled = true;

        const userMsg = el('div', 'ss-chat-msg user');
        userMsg.textContent = msg;
        msgs.appendChild(userMsg);

        const typing = html('div', 'ss-chat-msg ai typing-dots',
          '<div class="ss-dots"><span></span><span></span><span></span></div>');
        msgs.appendChild(typing);
        msgs.scrollTop = msgs.scrollHeight;

        try {
          const res  = await fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: new URLSearchParams({
              action:          'stevegpt_send_message',
              nonce,
              chatbot_id:      config.chatbot_id,
              conversation_id: config.conversation_id || '',
              message:         msg,
              context:         config.context || '',
            }),
          });
          const json = await res.json();
          typing.remove();
          const aiMsg = el('div', 'ss-chat-msg ai');
          aiMsg.textContent = json.success ? json.data.response : 'Sorry, I had trouble with that. Please try again.';
          msgs.appendChild(aiMsg);
        } catch (_) {
          typing.remove();
          const errMsg = el('div', 'ss-chat-msg ai');
          errMsg.textContent = 'Connection error. Please try again.';
          msgs.appendChild(errMsg);
        }

        sendBtn.disabled = false;
        msgs.scrollTop = msgs.scrollHeight;
      }

      sendBtn.onclick = sendMessage;
      input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
      });
      input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 100) + 'px';
      });

      inputRow.appendChild(input);
      inputRow.appendChild(sendBtn);
      widget.appendChild(inputRow);

      placeholder.appendChild(widget);
    } catch (_) {
      // Chat unavailable — non-fatal, no UI needed
    }
  }

  // =========================================================================
  // DEMO MODE (MYF-174)
  // =========================================================================

  async function handleDemoStart() {
    const lv = loading('Checking…');
    try {
      const data = await api('demo/status');
      unload(lv);
      if (!data.prerequisites_met) {
        const body  = el('div', 'ss-screen-body');
        const inner = el('div', '');
        inner.style.cssText = 'padding:48px 24px;text-align:center;';
        inner.innerHTML = `
          <div style="font-size:52px;margin-bottom:16px;">🔒</div>
          <h2 style="color:#fff;margin:0 0 12px;font-size:20px;">Not Quite Ready</h2>
          <p style="color:var(--ss-text-dim);font-size:14px;line-height:1.6;max-width:380px;margin:0 auto 24px;">
            Complete the <strong>Lens</strong>, <strong>Word Association</strong>, and <strong>Personality Test</strong> activities before trying Demo Mode.
          </p>
        `;
        const backBtn = el('button', 'ss-btn ss-btn-ghost', '← Back');
        backBtn.onclick = () => init();
        inner.appendChild(backBtn);
        body.appendChild(inner);
        render(body);
        return;
      }
      if (data.game && data.game.found && data.game.status === 'complete') {
        state.gameId     = data.game.game_id;
        state.gameType   = 'demo';
        state.gameStatus = 'complete';
        renderDemoGameOver();
        return;
      }
      if (data.game && data.game.found) {
        state.gameId     = data.game.game_id;
        state.gameType   = 'demo';
        state.gameStatus = 'playing';
        await renderDemoBoard(true);
        return;
      }
      if (!Object.keys(state.strengths).length) await loadStrengths();
      renderDemoSelfWrite();
    } catch (e) {
      unload(lv);
      renderError(e.message);
    }
  }

  function renderDemoSelfWrite() {
    const required = 5;
    const body     = el('div', 'ss-screen-body');
    body.innerHTML = `
      <div class="ss-game-header">
        <div class="ss-game-title">✨ Choose Your Strengths</div>
        <div class="ss-game-sub" id="ss-demo-self-count">${state.draftSelf.length}/${required} selected</div>
      </div>
    `;
    const inner = el('div', '');
    inner.style.padding = '20px';
    inner.innerHTML += `
      <div class="ss-card" style="margin-bottom:16px;font-size:13px;line-height:1.6;color:var(--ss-text);">
        Pick the <strong>5 strengths</strong> that best describe you.
        Steve will study your choices alongside your Lens, Word Association, and Personality Test data — then pick 5 more he thinks match you.
        You'll play a solo memory game to discover what he found!
      </div>
    `;

    const tagLabel = el('div', 'ss-section-label', 'Your selected strengths');
    inner.appendChild(tagLabel);
    const tagCloud = el('div', 'ss-tag-cloud');
    tagCloud.id = 'ss-demo-self-tags';
    inner.appendChild(tagCloud);
    refreshDemoSelfTags(tagCloud, required);

    const search = el('input', 'ss-input');
    search.placeholder = '🔍 Search strengths…';
    search.style.marginBottom = '10px';
    search.oninput = () => filterDemoSelf(search.value);
    inner.appendChild(search);

    const cats   = Object.keys(state.strengths);
    const tabBar = el('div', 'ss-cat-tabs');
    const allTab = el('button', 'ss-cat-tab active', 'All');
    allTab.dataset.cat = 'all';
    tabBar.appendChild(allTab);
    cats.forEach(cat => {
      if (cat === 'Family') return;
      const shortNames = { 'Creative & Expressive': 'Creative', 'Mind & Learning': 'Mind', 'Leadership & Drive': 'Leadership', 'Practical & Dependable': 'Practical', 'Growth & Mindset': 'Growth', 'Social & Caring': 'Social' };
      const btn = el('button', 'ss-cat-tab', shortNames[cat] || cat);
      btn.dataset.cat = cat;
      tabBar.appendChild(btn);
    });
    inner.appendChild(tabBar);
    tabBar.addEventListener('click', e => {
      const btn = e.target.closest('.ss-cat-tab');
      if (!btn) return;
      tabBar.querySelectorAll('.ss-cat-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      filterDemoSelf(search.value, btn.dataset.cat);
    });

    const grid = el('div', '');
    grid.id = 'ss-demo-self-grid';
    grid.style.cssText = 'max-height:260px;overflow-y:auto;padding-right:4px;';
    inner.appendChild(grid);
    renderDemoSelfList(grid);

    const submitBtn = el('button', 'ss-btn ss-btn-demo ss-btn-full', '🤖 Let Steve Analyse →');
    submitBtn.style.marginTop = '16px';
    submitBtn.id = 'ss-demo-self-submit';
    if (state.draftSelf.length < required) submitBtn.disabled = true;
    submitBtn.onclick = () => {
      if (state.draftSelf.length < required) return;
      renderSteveThinking(state.draftSelf.map(d => d.strength_text));
    };
    inner.appendChild(submitBtn);

    body.appendChild(inner);
    render(body);

    function filterDemoSelf(q, cat) {
      const activeCat = cat || tabBar.querySelector('.ss-cat-tab.active')?.dataset.cat || 'all';
      renderDemoSelfList(document.getElementById('ss-demo-self-grid'), activeCat, q);
    }
  }

  function renderDemoSelfList(container, cat = 'all', query = '') {
    if (!container) return;
    const used = state.draftSelf.map(d => d.strength_text.toLowerCase());
    container.innerHTML = '';
    Object.entries(state.strengths).forEach(([category, items]) => {
      if (category === 'Family') return;
      if (cat !== 'all' && cat !== category) return;
      const filtered = query ? items.filter(s => s.text.toLowerCase().includes(query.toLowerCase())) : items;
      if (!filtered.length) return;
      const label = el('div', 'ss-cat-label');
      label.style.color = 'rgba(155,110,243,0.7)';
      label.textContent = category;
      container.appendChild(label);
      const g = el('div', 'ss-strength-grid');
      filtered.forEach(s => {
        const isUsed = used.includes(s.text.toLowerCase());
        const chip   = el('div', 'ss-strength-chip' + (isUsed ? ' used' : ''), s.text + (isUsed ? ' ✓' : ''));
        if (!isUsed) {
          chip.onclick = () => {
            if (state.draftSelf.length >= 5) return;
            state.draftSelf.push({ strength_id: s.id, strength_text: s.text });
            chip.classList.add('used');
            chip.textContent = s.text + ' ✓';
            chip.onclick = null;
            updateDemoSelfUI();
          };
        }
        g.appendChild(chip);
      });
      container.appendChild(g);
    });
  }

  function refreshDemoSelfTags(container, required) {
    container.innerHTML = '';
    if (!state.draftSelf.length) {
      container.innerHTML = '<span style="color:var(--ss-text-dim);font-size:12px;">No strengths selected yet — tap one below</span>';
      return;
    }
    state.draftSelf.forEach(d => {
      const tag = el('div', 'ss-tag');
      tag.innerHTML = escHtml(d.strength_text) + '<span class="ss-tag-remove" data-text="' + escHtml(d.strength_text) + '">×</span>';
      tag.querySelector('.ss-tag-remove').onclick = () => {
        state.draftSelf = state.draftSelf.filter(x => x.strength_text !== d.strength_text);
        refreshDemoSelfTags(container, required);
        updateDemoSelfUI();
        const grid = document.getElementById('ss-demo-self-grid');
        if (grid) renderDemoSelfList(grid, 'all');
      };
      container.appendChild(tag);
    });
  }

  function updateDemoSelfUI() {
    const required = 5;
    const sub = document.getElementById('ss-demo-self-count');
    if (sub) sub.textContent = state.draftSelf.length + '/' + required + ' selected';
    const btn = document.getElementById('ss-demo-self-submit');
    if (btn) btn.disabled = state.draftSelf.length < required;
    const tags = document.getElementById('ss-demo-self-tags');
    if (tags) refreshDemoSelfTags(tags, required);
  }

  async function renderSteveThinking(selfStrengths) {
    const messages = [
      '🔍 Scanning your strengths library…',
      '🧠 Analysing your Word Association results…',
      '🎭 Reviewing your personality profile…',
      '💡 Cross-referencing Lens activity data…',
      '⭐ Selecting Steve\'s top 5 picks…',
      '🃏 Shuffling and dealing your board…',
    ];
    let msgIdx  = 0;
    const body  = el('div', 'ss-screen-body');
    const inner = el('div', '');
    inner.style.cssText = 'padding:60px 24px;text-align:center;';
    const avatarHtml = cfg.steveAvatarUrl
      ? `<img src="${escHtml(cfg.steveAvatarUrl)}" alt="Steve" style="width:80px;height:80px;border-radius:50%;object-fit:cover;margin-bottom:16px;animation:ss-bounce 1.2s ease-in-out infinite;">`
      : `<div style="font-size:64px;margin-bottom:16px;animation:ss-bounce 1.2s ease-in-out infinite;">🤖</div>`;
    inner.innerHTML = `
      ${avatarHtml}
      <h2 style="color:#fff;margin:0 0 12px;font-size:20px;">Steve is thinking…</h2>
      <div id="ss-steve-msg" style="color:var(--ss-text-dim);font-size:14px;min-height:22px;">${messages[0]}</div>
      <div class="ss-waiting" style="justify-content:center;margin-top:24px;">
        <div class="ss-dots"><span></span><span></span><span></span></div>
      </div>
    `;
    body.appendChild(inner);
    render(body);

    const msgEl    = document.getElementById('ss-steve-msg');
    const msgTimer = setInterval(() => {
      msgIdx = (msgIdx + 1) % messages.length;
      if (msgEl) msgEl.textContent = messages[msgIdx];
    }, 1800);

    try {
      const res = await api('demo/self-submit', 'POST', { self_strengths: selfStrengths });
      clearInterval(msgTimer);
      if (!res.ok) { renderError(res.message || 'Steve could not analyse your strengths. Please try again.'); return; }
      state.gameId     = res.game_id;
      state.gameType   = 'demo';
      state.gameStatus = 'playing';
      state.board      = res.positions || [];
      state.gameEndsAt = res.game_ends_at || null;
      await renderDemoBoard(false);
    } catch (e) {
      clearInterval(msgTimer);
      renderError(e.message);
    }
  }

  async function renderDemoBoard(fetchFresh) {
    stopPoll();
    startDemoHeartbeat();
    if (fetchFresh) {
      const lv = loading('Loading board…');
      try {
        const boardData = await api('demo/board?game_id=' + state.gameId);
        unload(lv);
        if (boardData.status === 'complete') {
          stopDemoHeartbeat();
          state.gameStatus = 'complete';
          renderDemoGameOver();
          return;
        }
        state.board      = boardData.positions    || [];
        state.gameEndsAt = boardData.game_ends_at || null;
      } catch (e) {
        unload(lv);
        renderError(e.message);
        return;
      }
    }
    renderDemoBoardUI(state.board);
  }

  function startDemoHeartbeat() {
    stopHeartbeat();
    state.heartbeatTimer = setInterval(async () => {
      try {
        const res = await api('demo/heartbeat', 'POST', { game_id: state.gameId });
        if (res.time_expired) {
          stopHeartbeat();
          state.gameStatus = 'complete';
          renderDemoGameOver();
        }
      } catch(_) {}
    }, 30000);
  }

  function renderDemoBoardUI(positions) {
    const body = el('div', 'ss-screen-body');

    const header = el('div', 'ss-game-header');
    header.innerHTML = '<div class="ss-game-title">🤖 Steve\'s Demo</div>';
    body.appendChild(header);

    const matchedPairs = positions.filter(p => p.is_matched).length / 2;
    const totalPairs   = positions.length / 2;

    const scoreBar = el('div', 'ss-demo-score-bar');
    scoreBar.innerHTML = `<span>⭐ Pairs matched: <strong id="ss-demo-score">${Math.round(matchedPairs)}</strong> / ${totalPairs}</span>`;
    body.appendChild(scoreBar);

    if (state.gameEndsAt) {
      const timerEl = el('div', 'ss-game-timer');
      timerEl.id    = 'ss-demo-timer';
      const endsAt  = new Date(state.gameEndsAt.replace(' ', 'T') + 'Z');
      const updateTimer = () => {
        const secs = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
        const m    = Math.floor(secs / 60).toString().padStart(2, '0');
        const s    = (secs % 60).toString().padStart(2, '0');
        timerEl.textContent = '⏱ ' + m + ':' + s;
        if (secs === 0) {
          clearInterval(timerEl._clockInterval);
          stopHeartbeat();
          state.gameStatus = 'complete';
          setTimeout(() => renderDemoGameOver(), 800);
        }
      };
      updateTimer();
      timerEl._clockInterval = setInterval(updateTimer, 1000);
      body.appendChild(timerEl);
    }

    const boardGrid = el('div', 'ss-board-grid ss-board-grid-4');
    positions.forEach(pos => {
      const tile = el('div', 'ss-card-tile');
      if (pos.is_matched) {
        tile.classList.add('matched');
        const steveCls = pos.content && pos.content.card_type === 'steve_pick' ? ' ss-ct-steve' : '';
        tile.innerHTML = `<div class="ss-card-tile-front"><div class="ss-ct-label${steveCls}">${escHtml(pos.content ? pos.content.label : '')}</div></div>`;
      } else if (pos.is_face_up) {
        tile.classList.add('face-up');
        tile.innerHTML = `<div class="ss-card-tile-front"><div class="ss-ct-label">${escHtml(pos.content ? pos.content.label : '')}</div></div>`;
      } else {
        tile.innerHTML = `<div class="ss-card-tile-back"><span class="ss-ct-back-icon">⭐</span></div>`;
        tile.classList.add('flippable');
        tile.addEventListener('click', () => handleDemoFlip(pos.position, tile, boardGrid));
      }
      boardGrid.appendChild(tile);
    });

    body.appendChild(boardGrid);
    render(body);
  }

  async function handleDemoFlip(position, tileEl, boardGrid) {
    if (tileEl.classList.contains('flipping')) return;
    tileEl.classList.add('flipping');
    boardGrid.querySelectorAll('.flippable').forEach(t => {
      t.classList.remove('flippable');
      t.onclick = null;
    });

    try {
      const res = await api('demo/flip', 'POST', { game_id: state.gameId, position });

      if (res.flip_number === 1) {
        const boardPos = state.board.find(p => p.position === position);
        if (boardPos) { boardPos.is_face_up = true; boardPos.content = res.content; }
        state.pendingFlipPos = position;
        renderDemoBoardUI(state.board);
        return;
      }

      const boardPos = state.board.find(p => p.position === position);
      if (boardPos) {
        boardPos.is_face_up = true;
        boardPos.content    = res.is_match ? (res.matched_pair ? res.matched_pair[1] : boardPos.content) : (res.flip2_content || boardPos.content);
      }

      if (res.is_match) {
        state.board.forEach(p => { if (p.is_face_up && !p.is_matched) { p.is_matched = true; p.is_face_up = false; } });
        state.pendingFlipPos = null;

        const scoreEl = document.getElementById('ss-demo-score');
        if (scoreEl) scoreEl.textContent = Math.round(state.board.filter(p => p.is_matched).length / 2);

        const isSteveMatch = res.matched_pair && res.matched_pair[0] && res.matched_pair[0].card_type === 'steve_pick';

        if (res.game_complete) {
          stopHeartbeat();
          state.gameStatus = 'complete';
          if (isSteveMatch && res.rationale) await showDemoRationale(res.rationale, res.source_activity);
          else await showMatchMoment(res.matched_pair, false);
          renderDemoGameOver();
          return;
        }

        if (isSteveMatch && res.rationale) await showDemoRationale(res.rationale, res.source_activity);
        else await showMatchMoment(res.matched_pair, false);
        renderDemoBoardUI(state.board);
      } else {
        renderDemoBoardUI(state.board);
        await new Promise(r => setTimeout(r, 1500));
        state.board.forEach(p => { if (p.is_face_up && !p.is_matched) p.is_face_up = false; });
        state.pendingFlipPos = null;
        renderDemoBoardUI(state.board);
      }
    } catch (e) {
      renderError(e.message);
    }
  }

  function showDemoRationale(rationale, sourceActivity) {
    return new Promise(resolve => {
      const sourceLabel = {
        'lens':          '🔭 Lens',
        'word_assoc':    '💬 Word Association',
        'personality':   '🎭 Personality Test',
        'self_strength': '⭐ Your Strengths',
      }[sourceActivity] || String(sourceActivity || 'Steve');

      const overlay = el('div', 'ss-demo-rationale-overlay');
      overlay.innerHTML = `
        <div class="ss-demo-rationale-box">
          <div class="ss-demo-rationale-icon">🤖</div>
          <div class="ss-demo-rationale-title">Steve's Pick!</div>
          <div class="ss-demo-rationale-source">${escHtml(sourceLabel)}</div>
          <div class="ss-demo-rationale-text">${escHtml(rationale || '')}</div>
          <button class="ss-btn ss-btn-demo ss-btn-sm ss-demo-rationale-ok">Got it ✓</button>
        </div>
      `;
      document.getElementById('mfsd-ss-root').appendChild(overlay);
      overlay.querySelector('.ss-demo-rationale-ok').onclick = () => {
        overlay.classList.add('fade-out');
        setTimeout(() => { overlay.remove(); resolve(); }, 300);
      };
    });
  }

  function renderDemoGameOver() {
    stopPoll(); stopHeartbeat();
    const matchedPairs = state.board.filter(p => p.is_matched).length / 2;
    const totalPairs   = state.board.length / 2;
    const perfect      = matchedPairs >= totalPairs && totalPairs > 0;

    const body   = el('div', 'ss-screen-body');
    const header = el('div', 'ss-game-header');
    header.innerHTML = '<div class="ss-game-title">🤖 Demo Complete!</div>';
    body.appendChild(header);

    const inner = el('div', '');
    inner.style.cssText = 'padding:32px 20px;text-align:center;';
    inner.innerHTML = `
      <div style="font-size:56px;margin-bottom:16px;">${perfect ? '🏆' : '🌟'}</div>
      <div style="font-size:20px;font-weight:700;margin-bottom:8px;">${perfect ? 'Perfect Score!' : 'Nice work!'}</div>
      <div style="font-size:15px;color:var(--ss-text-dim);margin-bottom:28px;">
        You matched ${Math.round(matchedPairs)} of ${totalPairs} pairs
      </div>
    `;
    body.appendChild(inner);

    const btnWrap = el('div', '');
    btnWrap.style.cssText = 'padding:0 20px 32px;display:flex;flex-direction:column;gap:12px;';
    const summaryBtn = el('button', 'ss-btn ss-btn-demo ss-btn-full', '💡 See Steve\'s Analysis →');
    summaryBtn.onclick = renderDemoSummary;
    btnWrap.appendChild(summaryBtn);
    body.appendChild(btnWrap);
    render(body);
  }

  async function renderDemoSummary() {
    const body    = el('div', 'ss-screen-body');
    const loadDiv = el('div', '');
    loadDiv.style.cssText = 'padding:60px 20px;text-align:center;';
    loadDiv.innerHTML = '<div class="ss-waiting"><div class="ss-dots"><span></span><span></span><span></span></div>'
      + '<div style="margin-top:14px;color:var(--ss-text-dim);font-size:14px;">Steve is writing your analysis…</div></div>';
    body.appendChild(loadDiv);
    render(body);

    try {
      const data = await api('demo/summary?game_id=' + state.gameId, 'GET');
      renderDemoSummaryUI(data);
    } catch (e) {
      const b = el('div', 'ss-screen-body');
      b.innerHTML = '<div style="padding:40px;text-align:center;">'
        + '<div style="color:var(--ss-red);margin-bottom:16px;">Could not load summary. Please try again.</div>'
        + '<button class="ss-btn ss-btn-demo" onclick="location.reload()">Reload</button></div>';
      render(b);
    }
  }

  function renderDemoSummaryUI(data) {
    const body   = el('div', 'ss-screen-body');
    const header = el('div', 'ss-game-header');
    header.innerHTML = '<div class="ss-game-title">💡 Steve\'s Analysis</div>'
      + '<div class="ss-game-sub">' + escHtml(data.student_name || cfg.displayName) + '</div>';
    body.appendChild(header);

    const inner = el('div', '');
    inner.style.paddingBottom = '32px';

    // Your 5 vs Steve's 5
    const compareCard = el('div', 'ss-card');
    compareCard.style.margin = '16px';
    compareCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:12px;">Your 5 vs Steve\'s 5</div>';

    const compareGrid = el('div', 'ss-demo-compare-grid');

    const selfCol = el('div', 'ss-demo-compare-col');
    selfCol.innerHTML = '<div class="ss-demo-compare-heading">You chose</div>';
    (data.self_strengths || []).forEach(s => selfCol.appendChild(el('div', 'ss-strength-chip', s)));
    compareGrid.appendChild(selfCol);

    const steveCol = el('div', 'ss-demo-compare-col ss-demo-compare-col-steve');
    steveCol.innerHTML = '<div class="ss-demo-compare-heading">🤖 Steve picked</div>';
    (data.picks || []).forEach(p => steveCol.appendChild(el('div', 'ss-strength-chip ss-chip-steve', p.strength_text || p)));
    compareGrid.appendChild(steveCol);
    compareCard.appendChild(compareGrid);

    if ((data.shared_strengths || []).length || (data.hidden_strengths || []).length) {
      const insightRow = el('div', 'ss-demo-insight-row');
      if ((data.shared_strengths || []).length) {
        const shared = el('div', 'ss-demo-insight-box ss-demo-insight-shared');
        shared.innerHTML = '<div class="ss-demo-insight-label">💚 You both agree</div>';
        data.shared_strengths.forEach(s => shared.appendChild(el('div', 'ss-demo-insight-item', s)));
        insightRow.appendChild(shared);
      }
      if ((data.hidden_strengths || []).length) {
        const hidden = el('div', 'ss-demo-insight-box ss-demo-insight-hidden');
        hidden.innerHTML = '<div class="ss-demo-insight-label">🔭 Steve sees in you</div>';
        data.hidden_strengths.forEach(s => hidden.appendChild(el('div', 'ss-demo-insight-item', s)));
        insightRow.appendChild(hidden);
      }
      compareCard.appendChild(insightRow);
    }
    inner.appendChild(compareCard);

    // AI sections
    const sections = data.sections || {};
    if (Object.keys(sections).length) {
      const aiCard = el('div', 'ss-card');
      aiCard.style.margin = '0 16px 16px';
      aiCard.innerHTML = '<div class="ss-section-label" style="margin-bottom:12px;">🤖 Steve says…</div>';
      aiCard.appendChild(renderAISectionTabs(sections));
      inner.appendChild(aiCard);
    }

    const chatEl = el('div', '');
    chatEl.id    = 'ss-demo-chat-placeholder';
    chatEl.style.padding = '0 16px 16px';
    inner.appendChild(chatEl);

    body.appendChild(inner);
    render(body);
    initDemoChatWidget(document.getElementById('ss-demo-chat-placeholder'));
  }

  async function initDemoChatWidget(placeholder) {
    if (!placeholder || placeholder.dataset.initialized) return;
    placeholder.dataset.initialized = 'true';
    if (!cfg.demoChatbotId) return;

    try {
      const config = await api('demo/chat-widget?game_id=' + state.gameId, 'GET');
      if (!config.ok || !config.chatbot_id) return;

      const widget     = el('div', 'ss-chat-widget');
      const chatHeader = el('div', 'ss-chat-header');
      chatHeader.innerHTML = '<span class="ss-chat-avatar-text">' + escHtml(config.avatar || '🤖') + '</span>'
        + '<span class="ss-chat-name">' + escHtml(config.ai_name || 'Steve') + '</span>';
      widget.appendChild(chatHeader);

      const msgs     = el('div', 'ss-chat-messages');
      const greeting = el('div', 'ss-chat-msg ai');
      greeting.textContent = config.greeting || 'Ask me about your strengths analysis!';
      msgs.appendChild(greeting);
      widget.appendChild(msgs);

      const inputRow = el('div', 'ss-chat-input-row');
      const input    = el('textarea', 'ss-chat-input');
      input.placeholder = 'Ask me anything…';
      input.rows = 1;
      const sendBtn = el('button', 'ss-chat-send-btn', 'Send');

      const ajaxUrl = (window.stevegpt || {}).ajax_url || '/wp-admin/admin-ajax.php';
      const nonce   = (window.stevegpt || {}).nonce   || '';

      async function sendMessage() {
        const msg = input.value.trim();
        if (!msg || sendBtn.disabled) return;
        input.value = '';
        input.style.height = 'auto';
        sendBtn.disabled = true;

        const userMsg = el('div', 'ss-chat-msg user');
        userMsg.textContent = msg;
        msgs.appendChild(userMsg);

        const typing = html('div', 'ss-chat-msg ai typing-dots',
          '<div class="ss-dots"><span></span><span></span><span></span></div>');
        msgs.appendChild(typing);
        msgs.scrollTop = msgs.scrollHeight;

        try {
          const res  = await fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: new URLSearchParams({
              action:          'stevegpt_send_message',
              nonce,
              chatbot_id:      config.chatbot_id,
              conversation_id: config.conversation_id || '',
              message:         msg,
              context:         config.context || '',
            }),
          });
          const json = await res.json();
          typing.remove();
          const aiMsg = el('div', 'ss-chat-msg ai');
          aiMsg.textContent = json.success ? json.data.response : 'Sorry, I had trouble with that. Please try again.';
          msgs.appendChild(aiMsg);
        } catch (_) {
          typing.remove();
          const errMsg = el('div', 'ss-chat-msg ai');
          errMsg.textContent = 'Connection error. Please try again.';
          msgs.appendChild(errMsg);
        }
        sendBtn.disabled = false;
        msgs.scrollTop = msgs.scrollHeight;
      }

      sendBtn.onclick = sendMessage;
      input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
      });
      input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 100) + 'px';
      });
      inputRow.appendChild(input);
      inputRow.appendChild(sendBtn);
      widget.appendChild(inputRow);
      placeholder.appendChild(widget);
    } catch (_) {}
  }

  // =========================================================================

  init(); // start the app

  } // end boot()

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot(); // DOM already ready
  }

})();