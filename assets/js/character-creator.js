/**
 * NeoWeaver – Field Agent Creator Wizard
 * Handle: neoweaver-char-creator
 * Config injected via: twCharCreatorConfig (wp_localize_script)
 */
(function () {
  'use strict';

  const cfg    = window.twCharCreatorConfig || {};
  const REST   = cfg.restBase  || '';
  const NONCE  = cfg.restNonce || '';
  const SUBMIT = cfg.restUrl   || '';
  const AGENTS = cfg.agentsUrl || '/agents/';
  const UPLOADS = '/wp-content/uploads/';

  // ── State ─────────────────────────────────────────────────────────────────
  const state = {
    step      : 1,
    totalSteps: 7,
    data      : {
      character_name  : '',
      pronouns        : '',
      pronouns_custom : '',
      backstory       : '',
      race_id         : null,
      race_label      : '',
      subrace_id      : null,
      subrace_label   : '',
      class_id        : null,
      class_label     : '',
      attrs           : { body: 1, reflex: 1, mind: 1, spirit: 1 },
      node_id         : null,
      node_label      : '',
      avatar_file     : null,
    },
  };

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const wrapper       = document.getElementById('tw-char-creator-wrapper');
  if (!wrapper) return;

  const progressFill  = document.getElementById('tw-char-progress-fill');
  const progressPhase = document.getElementById('tw-char-progress-phase');
  const stepCurrent   = document.getElementById('tw-char-step-current');
  const attrRemaining = document.getElementById('tw-attr-remaining');
  const attrPoolInput = document.getElementById('tw-attr-pool');
  const statusBox     = wrapper.querySelector('.tw-char-status');

  // ── Inline error helpers ─────────────────────────────────────────────────
  // Show a red error message directly under an element.
  // anchorEl = the input / grid / section to annotate.
  function showFieldError(anchorEl, msg) {
    if (!anchorEl) return;
    clearFieldError(anchorEl);
    anchorEl.classList.add('tw-field--invalid');
    const err = document.createElement('span');
    err.className   = 'tw-field-error';
    err.textContent = '⚠ ' + msg;
    anchorEl.insertAdjacentElement('afterend', err);
    // Scroll the error into view smoothly
    err.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function clearFieldError(anchorEl) {
    if (!anchorEl) return;
    anchorEl.classList.remove('tw-field--invalid');
    const next = anchorEl.nextElementSibling;
    if (next && next.classList.contains('tw-field-error')) next.remove();
  }

  // Clear all errors inside a step
  function clearStepErrors(stepEl) {
    if (!stepEl) return;
    stepEl.querySelectorAll('.tw-field-error').forEach(e => e.remove());
    stepEl.querySelectorAll('.tw-field--invalid').forEach(e => e.classList.remove('tw-field--invalid'));
  }

  // ── Progress / step helpers ──────────────────────────────────────────────
  function getStep(n) {
    return wrapper.querySelector(`.tw-step[data-step="${n}"]`);
  }

  function setProgress(n) {
    const pct = Math.round((n / state.totalSteps) * 100);
    if (progressFill)  progressFill.style.width  = pct + '%';
    if (stepCurrent)   stepCurrent.textContent   = n;
    const phase = getStep(n)?.dataset?.phase || '';
    if (progressPhase) progressPhase.textContent = phase;
    wrapper.querySelectorAll('.tw-progress-tick').forEach(t => {
      t.classList.toggle('active', Number(t.dataset.tick) <= n);
    });
  }

  function showStep(n) {
    wrapper.querySelectorAll('.tw-step').forEach(el => el.classList.remove('active'));
    const next = getStep(n);
    if (next) {
      next.classList.add('active');
      next.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    state.step = n;
    setProgress(n);
    if (n === 7) populateSummary();
  }

  function setError(msg) {
    if (statusBox) statusBox.innerHTML = `<div class="tw-error">${msg}</div>`;
  }
  function clearError() {
    if (statusBox) statusBox.innerHTML = '';
  }

  function raceImgUrl(img_url) {
    if (!img_url) return '';
    if (img_url.startsWith('http')) return img_url;
    return UPLOADS + img_url;
  }

  function renderTags(tags) {
    if (!tags || !tags.length) return '';
    const chips = tags.slice(0, 3).map(t => `<span class="tw-race-tag">${t}</span>`).join('');
    return `<div class="tw-race-tags">${chips}</div>`;
  }

  // ── Step 1 validation ─────────────────────────────────────────────────────
  function validateStep1() {
    const stepEl  = getStep(1);
    const nameEl  = document.getElementById('tw-char-name');
    const name    = nameEl?.value.trim();
    let valid     = true;

    clearStepErrors(stepEl);

    if (!name) {
      showFieldError(nameEl, 'Agent Designation is required.');
      valid = false;
    }

    // Custom pronouns filled in?
    const pronoun = wrapper.querySelector('input[name="pronouns"]:checked');
    state.data.pronouns = pronoun?.value || '';
    if (state.data.pronouns === 'custom') {
      const customEl = document.getElementById('tw-char-pronouns-custom');
      const customVal = customEl?.value.trim();
      if (!customVal) {
        showFieldError(customEl, 'Please enter your custom pronouns.');
        valid = false;
      } else {
        state.data.pronouns_custom = customVal;
      }
    }

    if (!valid) return false;

    state.data.character_name = name;
    state.data.backstory      = document.getElementById('tw-char-backstory')?.value.trim() || '';
    return true;
  }

  // ── Race grid — two-step ───────────────────────────────────────────────────────
  async function loadRaces() {
    const grid = document.getElementById('tw-race-grid');
    if (!grid) return;

    state.data.race_id       = null;
    state.data.race_label    = '';
    state.data.subrace_id    = null;
    state.data.subrace_label = '';

    grid.innerHTML = '<div class="tw-loading-state"><span class="tw-loading-dot"></span>FETCHING RACE DATA FROM NODE…</div>';

    try {
      const res   = await fetch(`${REST}/races`, { headers: { 'X-WP-Nonce': NONCE } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const races = await res.json();
      if (!races.length) {
        grid.innerHTML = '<div class="tw-empty-state">No races available. Contact your GM.</div>';
        return;
      }
      renderBaseRaces(grid, races);
    } catch (e) {
      grid.innerHTML = `<div class="tw-error">LOAD FAILED: ${e.message}</div>`;
    }
  }

  function renderBaseRaces(grid, races) {
    grid.innerHTML    = '';
    grid.dataset.mode = 'base';
    races.forEach(race => {
      const card = document.createElement('div');
      card.className    = 'tw-grid-card tw-race-card';
      card.dataset.id   = race.id;
      card.dataset.name = race.name;
      const img  = raceImgUrl(race.img_url);
      const tags = Array.isArray(race.tags) ? race.tags : (typeof race.tags === 'string' ? JSON.parse(race.tags) : []);
      card.innerHTML = `
        ${img ? `<div class="tw-race-img"><img src="${img}" alt="${race.name}" loading="lazy"/></div>` : ''}
        <div class="tw-race-body">
          <strong class="tw-race-name">${race.name}</strong>
          ${renderTags(tags)}
          ${race.description ? `<p class="tw-race-desc">${race.description}</p>` : ''}
          <span class="tw-race-select-hint">▶ SELECT / VIEW SUBRACES</span>
        </div>`;
      card.addEventListener('click', () => {
        // Clear race error on interaction
        clearFieldError(grid);
        onBaseRaceClick(card, race, grid);
      });
      grid.appendChild(card);
    });
  }

  async function onBaseRaceClick(card, race, grid) {
    grid.querySelectorAll('.tw-race-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    state.data.race_id       = race.id;
    state.data.race_label    = race.name;
    state.data.subrace_id    = null;
    state.data.subrace_label = '';

    const subGrid    = document.getElementById('tw-subrace-grid');
    const subSection = document.getElementById('tw-subrace-section');
    if (!subGrid || !subSection) return;

    subSection.style.display = 'block';
    subGrid.innerHTML = '<div class="tw-loading-state"><span class="tw-loading-dot"></span>LOADING SUBRACES…</div>';

    try {
      const res      = await fetch(`${REST}/subraces?parent=${encodeURIComponent(race.name)}`, { headers: { 'X-WP-Nonce': NONCE } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const subraces = await res.json();
      if (!subraces.length) {
        subGrid.innerHTML = '<div class="tw-empty-state tw-subrace-none">No subraces — base race selected. ✓</div>';
        return;
      }
      renderSubraces(subGrid, subraces);
    } catch (e) {
      subGrid.innerHTML = `<div class="tw-error">LOAD FAILED: ${e.message}</div>`;
    }
  }

  function renderSubraces(subGrid, subraces) {
    subGrid.innerHTML = '';
    subraces.forEach(sub => {
      const card = document.createElement('div');
      card.className    = 'tw-grid-card tw-subrace-card';
      card.dataset.id   = sub.id;
      card.dataset.name = sub.name;
      const img  = raceImgUrl(sub.img_url);
      const tags = Array.isArray(sub.tags) ? sub.tags : (typeof sub.tags === 'string' ? JSON.parse(sub.tags) : []);
      card.innerHTML = `
        ${img ? `<div class="tw-race-img"><img src="${img}" alt="${sub.name}" loading="lazy"/></div>` : ''}
        <div class="tw-race-body">
          <strong class="tw-race-name">${sub.name}</strong>
          ${renderTags(tags)}
          ${sub.description ? `<p class="tw-race-desc">${sub.description}</p>` : ''}
        </div>`;
      card.addEventListener('click', () => {
        clearFieldError(subGrid);
        subGrid.querySelectorAll('.tw-subrace-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        state.data.subrace_id    = sub.id;
        state.data.subrace_label = sub.name;
        state.data.race_id       = sub.id;
        state.data.race_label    = sub.name;
      });
      subGrid.appendChild(card);
    });
  }

  function validateRaceStep() {
    const grid = document.getElementById('tw-race-grid');
    if (!state.data.race_id) {
      showFieldError(grid, 'Select a race before continuing.');
      return false;
    }
    clearFieldError(grid);
    return true;
  }

  // ── Generic grid (classes, nodes) ─────────────────────────────────────────
  async function loadGrid(endpoint, gridId, field) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    grid.innerHTML = '<div class="tw-loading-state"><span class="tw-loading-dot"></span>FETCHING DATA…</div>';
    try {
      const res   = await fetch(`${REST}/${endpoint}`, { headers: { 'X-WP-Nonce': NONCE } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const items = await res.json();
      if (!items.length) {
        grid.innerHTML = '<div class="tw-empty-state">No data available. Contact your GM.</div>';
        return;
      }
      grid.innerHTML = '';
      items.forEach(item => {
        const card  = document.createElement('div');
        card.className     = 'tw-grid-card';
        card.dataset.id    = item.id;
        const label        = item.name || item.title || item.label || item.id;
        card.dataset.label = label;
        card.innerHTML     = `
          <div class="tw-race-body">
            <strong class="tw-race-name">${label}</strong>
            ${item.description ? `<p class="tw-race-desc">${item.description}</p>` : ''}
          </div>`;
        card.addEventListener('click', () => {
          clearFieldError(grid);
          grid.querySelectorAll('.tw-grid-card').forEach(c => c.classList.remove('selected'));
          card.classList.add('selected');
          state.data[field + '_id']    = item.id;
          state.data[field + '_label'] = label;
        });
        grid.appendChild(card);
      });
    } catch (e) {
      grid.innerHTML = `<div class="tw-error">LOAD FAILED: ${e.message}</div>`;
    }
  }

  function validateGridStep(field, stepEl) {
    const gridId = stepEl?.querySelector('.tw-dynamic-grid')?.id;
    const grid   = gridId ? document.getElementById(gridId) : null;
    if (!state.data[field + '_id']) {
      if (grid) showFieldError(grid, 'Please make a selection before continuing.');
      return false;
    }
    if (grid) clearFieldError(grid);
    return true;
  }

  // ── Attributes ────────────────────────────────────────────────────────────
  function initAttrs() {
    const pool = Number(attrPoolInput?.value || 12);
    const min  = 1, max = 5;
    function remaining() {
      return pool - Object.values(state.data.attrs).reduce((a, b) => a + b, 0);
    }
    wrapper.querySelectorAll('.tw-attr-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const key   = btn.dataset.attr;
        const delta = btn.classList.contains('tw-attr-plus') ? 1 : -1;
        const cur   = state.data.attrs[key];
        const rem   = remaining();
        if (delta === 1  && (cur >= max || rem <= 0)) return;
        if (delta === -1 && cur <= min)               return;
        state.data.attrs[key] = cur + delta;
        const input = document.getElementById(`tw-attr-${key}`);
        if (input) input.value = state.data.attrs[key];
        const row = btn.closest('.tw-attr-row');
        row?.querySelectorAll('.tw-pip').forEach(pip => {
          pip.classList.toggle('active', Number(pip.dataset.pip) <= state.data.attrs[key]);
        });
        if (attrRemaining) attrRemaining.textContent = remaining();
        // Clear attr error on any change
        const attrGrid = getStep(4)?.querySelector('.tw-attr-grid');
        if (attrGrid) clearFieldError(attrGrid);
      });
    });
  }

  function validateAttrs() {
    const pool    = Number(attrPoolInput?.value || 12);
    const used    = Object.values(state.data.attrs).reduce((a, b) => a + b, 0);
    const attrGrid = getStep(4)?.querySelector('.tw-attr-grid');
    if (used !== pool) {
      const rem = pool - used;
      const msg = rem > 0
        ? `You still have ${rem} point${rem > 1 ? 's' : ''} to spend.`
        : `You have spent ${Math.abs(rem)} point${Math.abs(rem) > 1 ? 's' : ''} too many.`;
      if (attrGrid) showFieldError(attrGrid, msg);
      return false;
    }
    if (attrGrid) clearFieldError(attrGrid);
    return true;
  }

  // ── Avatar ────────────────────────────────────────────────────────────────
  function initAvatar() {
    const fileInput = document.getElementById('tw-char-avatar');
    const trigger   = wrapper.querySelector('.tw-upload-trigger');
    const dropZone  = document.getElementById('tw-avatar-drop');
    const preview   = document.getElementById('tw-avatar-preview');
    const selected  = document.getElementById('tw-avatar-selected');
    const imgEl     = document.getElementById('tw-avatar-img');
    const clearBtn  = document.getElementById('tw-avatar-clear');
    function handleFile(file) {
      if (!file || !file.type.startsWith('image/')) return;
      if (file.size > 2 * 1024 * 1024) {
        showFieldError(dropZone, 'Max file size is 2 MB.');
        return;
      }
      clearFieldError(dropZone);
      state.data.avatar_file = file;
      const url = URL.createObjectURL(file);
      if (imgEl)    imgEl.src              = url;
      if (preview)  preview.style.display  = 'none';
      if (selected) selected.style.display = '';
    }
    trigger?.addEventListener('click', () => fileInput?.click());
    fileInput?.addEventListener('change', e => handleFile(e.target.files[0]));
    clearBtn?.addEventListener('click', () => {
      state.data.avatar_file = null;
      if (fileInput)  fileInput.value        = '';
      if (imgEl)      imgEl.src              = '';
      if (preview)    preview.style.display  = '';
      if (selected)   selected.style.display = 'none';
    });
    ['dragover', 'dragleave', 'drop'].forEach(evt =>
      dropZone?.addEventListener(evt, e => {
        e.preventDefault();
        if (evt === 'drop') handleFile(e.dataTransfer.files[0]);
      })
    );
  }

  // ── Pronouns ──────────────────────────────────────────────────────────────
  function initPronouns() {
    const customInput = document.getElementById('tw-char-pronouns-custom');
    wrapper.querySelectorAll('.tw-pronoun-radio').forEach(r => {
      r.addEventListener('change', () => {
        if (customInput) customInput.style.display = r.value === 'custom' ? '' : 'none';
        clearFieldError(customInput);
      });
    });
    // Clear name error on typing
    document.getElementById('tw-char-name')?.addEventListener('input', e => {
      if (e.target.value.trim()) clearFieldError(e.target);
    });
  }

  // ── Summary ───────────────────────────────────────────────────────────────
  function populateSummary() {
    const d = state.data;
    function set(id, val) {
      const el = document.getElementById(`tw-summary-${id}`);
      if (el) el.textContent = val || '—';
    }
    const raceDisplay = d.subrace_label
      ? `${d.subrace_label} (${d.race_label || ''})`
      : d.race_label;
    set('character_name', d.character_name);
    set('pronouns',       d.pronouns === 'custom' ? d.pronouns_custom : d.pronouns);
    set('backstory',      d.backstory ? d.backstory.substring(0, 80) + (d.backstory.length > 80 ? '…' : '') : '');
    set('race',           raceDisplay);
    set('class',          d.class_label);
    set('attrs',          Object.entries(d.attrs).map(([k, v]) => `${k.toUpperCase()}:${v}`).join(' · '));
    set('node_id',        d.node_label);
    set('avatar',         d.avatar_file ? d.avatar_file.name : 'None');
  }

  // ── Submit ────────────────────────────────────────────────────────────────
  async function submitCharacter() {
    clearError();
    const submitBtn = document.getElementById('tw-char-submit');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'SYNCHRONIZING…'; }
    try {
      const form = new FormData();
      const d    = state.data;
      form.append('character_name', d.character_name);
      form.append('pronouns',       d.pronouns === 'custom' ? d.pronouns_custom : d.pronouns);
      form.append('backstory',      d.backstory);
      form.append('race_id',        d.race_id);
      form.append('class_id',       d.class_id);
      form.append('node_id',        d.node_id);
      Object.entries(d.attrs).forEach(([k, v]) => form.append(`attr_${k}`, v));
      if (d.avatar_file) form.append('avatar', d.avatar_file);
      const res  = await fetch(SUBMIT, { method: 'POST', headers: { 'X-WP-Nonce': NONCE }, body: form });
      const json = await res.json();
      if (!res.ok || json.success === false) throw new Error(json.message || json.data?.message || 'Unknown error');
      if (statusBox) statusBox.innerHTML = '<div class="tw-success">✓ AGENT SYNCHRONIZED. Redirecting…</div>';
      setTimeout(() => { window.location.href = AGENTS; }, 1800);
    } catch (err) {
      setError('SYNC FAILED: ' + err.message);
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '▶ SYNCHRONIZE AGENT'; }
    }
  }

  // ── Navigation wiring ─────────────────────────────────────────────────────
  function initNav() {
    document.getElementById('tw-char-step1-next')?.addEventListener('click', () => {
      if (validateStep1()) {
        loadRaces();
        showStep(2);
      }
    });

    wrapper.querySelectorAll('.tw-btn-next').forEach(btn => {
      btn.addEventListener('click', () => {
        const stepEl = btn.closest('.tw-step');
        const n      = Number(stepEl?.dataset?.step);
        const field  = stepEl?.dataset?.field;

        if (n === 2) {
          if (!validateRaceStep()) return;
        } else if (field && !validateGridStep(field, stepEl)) {
          return;
        }
        if (n === 4 && !validateAttrs()) return;

        const next = n + 1;
        if (next === 3) loadGrid('classes', 'tw-class-grid', 'class');
        if (next === 5) loadGrid('nodes',   'tw-node-grid',  'node');
        showStep(next);
      });
    });

    wrapper.querySelectorAll('.tw-btn-prev').forEach(btn => {
      btn.addEventListener('click', () => {
        const n = Number(btn.closest('.tw-step')?.dataset?.step);
        if (n > 1) showStep(n - 1);
      });
    });

    wrapper.querySelectorAll('.tw-summary-edit').forEach(btn => {
      btn.addEventListener('click', () => {
        const target = Number(btn.dataset.goto);
        if (target) showStep(target);
      });
    });

    document.getElementById('tw-char-submit')?.addEventListener('click', submitCharacter);
  }

  // ── Boot ──────────────────────────────────────────────────────────────────
  function init() {
    initPronouns();
    initAttrs();
    initAvatar();
    initNav();
    setProgress(1);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
