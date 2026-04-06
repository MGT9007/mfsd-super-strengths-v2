/* =============================================================================
   MFSD SUPER STRENGTHS CARDS — FRONTEND GAME ENGINE
   State machine: submission → dealing → playing → complete
   ============================================================================= */
(function () {
  'use strict';

  const cfg  = window.MFSD_SS_CFG || {};
  const root = document.getElementById('mfsd-ss-root');
  if (!root) return;

  // ---- State ----------------------------------------------------------------
  let state = {
    gameId:        null,
    gameStatus:    null,  // submission | dealing | playing | complete
    gameMode:      cfg.gameMode || 'full',
    player:        null,
    allPlayers:    [],
    strengths:     {},    // { category: [{id,text}] }
    // Submission
    draftCards:    {},    // { target_player_id: [{type,text,strength_id}] }
    currentTarget: null,
    // Game
    hand:          [],
    currentTurn:   null,
    selectedCard:  null,
    myVote:        null,
    isConfident:   false,
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
    const themeClass = cfg.roleHint === 'student' ? 'ss-wrap gamer' : 'ss-wrap corporate';
    const wrap = el('div', themeClass);
    wrap.appendChild(screen);
    root.replaceChildren(wrap);
  }

  // ---- INIT -----------------------------------------------------------------
  async function init() {
    const lv = loading();
    try {
      const data = await api('state');
      unload(lv);

      if (data.status === 'no_game') {
        renderNoGame(data.message);
        return;
      }

      state.gameId     = data.game_id;
      state.gameStatus = data.status;
      state.gameMode   = data.game_mode;
      state.player     = data.player;
      state.allPlayers = data.all_players || [];
      state.hand       = data.hand || [];

      // Load strengths (needed for submission)
      if (data.status === 'submission') {
        await loadStrengths();
      }

      routeToScreen();
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
      case 'submission': renderSubmissionIntro(); break;
      case 'dealing':    renderDealing(); break;
      case 'playing':    renderGameTable(); break;
      case 'complete':   renderFinalResults(); break;
      default:           renderNoGame('Unknown game state.');
    }
  }

  // =========================================================================
  // NO GAME
  // =========================================================================
  function renderNoGame(msg) {
    const body = el('div', 'ss-screen-body');
    const card = el('div', 'ss-card');
    card.style.textAlign = 'center';
    card.innerHTML = '<div style="font-size:40px;margin-bottom:12px;">🃏</div>' +
      '<h2 style="color:#fff;margin:0 0 10px;">Super Strengths Cards</h2>' +
      '<p style="color:var(--ss-text-dim);font-size:14px;">' + escHtml(msg) + '</p>';
    body.appendChild(card);
    render(body);
  }

  // =========================================================================
  // SUBMISSION INTRO (ST0 / PC0)
  // =========================================================================
  function renderSubmissionIntro() {
    const isStudent = cfg.roleHint === 'student';
    const body = el('div', 'ss-screen-body');

    if (isStudent) {
      // ST0 — student entry
      body.innerHTML = `
        <div class="ss-game-header">
          <div class="ss-game-title">🃏 Super Strengths Cards</div>
          <div class="ss-game-sub">Week 1 · Family Game</div>
        </div>
      `;
      const inner = el('div', '');
      inner.style.padding = '20px';
      inner.innerHTML = `
        <div class="ss-section-label" style="margin-bottom:12px;">How it works</div>
        <div class="ss-intro-rule"><div class="ss-intro-rule-num">1</div>Write 5 Super Strength cards for each person in your game.</div>
        <div class="ss-intro-rule"><div class="ss-intro-rule-num">2</div>Once everyone submits, cards are dealt and the guessing game begins.</div>
        <div class="ss-intro-rule"><div class="ss-intro-rule-num">3</div>In each round, one card is played face-up. Everyone guesses who it's about!</div>
        <div class="ss-intro-rule"><div class="ss-intro-rule-num">4</div>Then guess who wrote it. Score points for correct guesses.</div>
        <div class="ss-intro-rule"><div class="ss-intro-rule-num">5</div>Use Confidence Tokens to bet big — right gets you +3, wrong costs you 3!</div>
        <hr class="ss-divider">
        <div class="ss-section-label" style="margin-bottom:10px;">Your family will be notified to join</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;">
          ${state.allPlayers.filter(p => p.id != state.player.id).map(p =>
            `<div class="ss-player-pill"><span class="status-dot ${p.submission_status === 'submitted' ? 'submitted' : 'pending'}"></span>${escHtml(p.display_name)}</div>`
          ).join('')}
        </div>
      `;
      const startBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '✍️ Start Writing Cards →');
      startBtn.onclick = renderSubmissionOverview;
      inner.appendChild(startBtn);
      body.appendChild(inner);
    } else {
      // PC0 — parent/carer entry
      const submitter = state.allPlayers.find(p => p.role === 'student');
      body.innerHTML = `
        <div class="ss-game-header">
          <div class="ss-game-title">🔴 ${escHtml(submitter ? submitter.display_name : 'Someone')} has started a game!</div>
        </div>
      `;
      const inner = el('div', '');
      inner.style.padding = '20px';
      inner.innerHTML = `
        <div style="background:rgba(201,162,39,0.08);border:1px solid rgba(201,162,39,0.2);border-radius:8px;padding:14px;margin-bottom:16px;font-size:13px;line-height:1.6;color:var(--ss-text);">
          You've been invited to write Super Strength cards for your family. Write 5 strengths for each person — they'll be used in the guessing game once everyone submits.
        </div>
        <div class="ss-section-label" style="margin-bottom:10px;">Player status</div>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px;">
          ${state.allPlayers.map(p =>
            `<div class="ss-player-pill"><span class="status-dot ${p.submission_status === 'submitted' ? 'submitted' : 'pending'}"></span>${escHtml(p.display_name)} <span style="color:var(--ss-text-dim);font-size:10px;">${p.submission_status === 'submitted' ? '— submitted ✓' : '— waiting'}</span></div>`
          ).join('')}
        </div>
      `;
      const startBtn = el('button', 'ss-btn ss-btn-gold ss-btn-full', '✍️ Write My Super Strength Cards →');
      startBtn.onclick = renderSubmissionOverview;
      inner.appendChild(startBtn);
      body.appendChild(inner);
    }

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
        state.gameStatus = 'dealing';
        renderDealing();
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
          state.hand = data.hand || [];
          if (data.status === 'playing') renderGameTable();
          else renderDealing();
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

    startPoll(async () => {
      try {
        const data = await api('state');
        if (data.status === 'playing') {
          stopPoll();
          state.gameStatus = 'playing';
          state.player = data.player || state.player;
          state.allPlayers = data.all_players || state.allPlayers;
          state.hand = data.hand || [];
          renderGameTable();
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
      const c = el('div', 'ss-hand-card' + (card.played ? ' played' : '') + (state.selectedCard?.id == card.id ? ' selected' : ''));
      if (!card.played) {
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
  // BOOT
  // =========================================================================
  init();

})();
