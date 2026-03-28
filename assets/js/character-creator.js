/**
 * NeoWeaver – Field Agent Creator Wizard
 * Handle: neoweaver-char-creator
 * Config injected via: twCharCreatorConfig (wp_localize_script)
 */
(function () {
  'use strict';

  // ── Config ────────────────────────────────────────────────────────────────
  const cfg = window.twCharCreatorConfig || {};
  const REST   = cfg.restBase   || '';
  const NONCE  = cfg.restNonce  || '';
  const SUBMIT = cfg.restUrl    || '';
  const AGENTS = cfg.agentsUrl  || '/agents/';

  // ── State ─────────────────────────────────────────────────────────────────
  const state = {
    step      : 1,
    totalSteps: 7,
    data      : {
      character_name : '',
      pronouns       : '',
      pronouns_custom: '',
      backstory      : '',
      race_id        : null,
      race_label     : '',
      class_id       : null,
      class_label    : '',
      attrs          : { body: 1, reflex: 1, mind: 1, spirit: 1 },
      node_id        : null,
      node_label     : '',
      avatar_file    : null,
    },
  };

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const wrapper        = document.getElementById('tw-char-creator-wrapper');
  if (!wrapper) return; // shortcode not on this page

  const progressFill   = document.getElementById('tw-char-progress-fill');
  const progressPhase  = document.getElementById('tw-char-progress-phase');
  const stepCurrent    = document.getElementById('tw-char-step-current');
  const attrRemaining  = document.getElementById('tw-attr-remaining');
  const attrPoolInput  = document.getElementById('tw-attr-pool');
  const statusBox      = wrapper.querySelector('.tw-char-status');

  // ── Helpers ───────────────────────────────────────────────────────────────
  function getStep(n) {
    return wrapper.querySelector(`.tw-step[data-step="${n}"]`);
  }

  function setProgress(n) {
    const total = state.totalSteps;
    const pct   = Math.round((n / total) * 100);
    if (progressFill)  progressFill.style.width = pct + '%';
    if (stepCurrent)   stepCurrent.textContent  = n;
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
    if (statusBox) {
      statusBox.innerHTML = `<div class="tw-error">${msg}</div>`;
    }
  }
  function clearError() {
    if (statusBox) statusBox.innerHTML = '';
  }

  // ── Step 1 validation ─────────────────────────────────────────────────────
  function validateStep1() {
    const name = document.getElementById('tw-char-name')?.value.trim();
    if (!name) {
      alert('Agent Designation is required.');
      return false;
    }
    state.data.character_name  = name;
    state.data.backstory       = document.getElementById('tw-char-backstory')?.value.trim() || '';

    const pronoun = wrapper.querySelector('input[name="pronouns"]:checked');
    state.data.pronouns        = pronoun?.value || '';
    if (state.data.pronouns === 'custom') {
      state.data.pronouns_custom = document.getElementById('tw-char-pronouns-custom')?.value.trim() || '';
    }
    return true;
  }

  // ── Dynamic grids (races / classes / nodes) ───────────────────────────────
  async function loadGrid(endpoint, gridId, field) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    grid.innerHTML = '<div class="tw-loading-state"><span class="tw-loading-dot"></span>FETCHING DATA…</div>';
    try {
      const res  = await fetch(`${REST}/${endpoint}`, {
        headers: { 'X-WP-Nonce': NONCE },
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const items = await res.json();

      if (!items.length) {
        grid.innerHTML = '<div class="tw-empty-state">No data available. Contact your GM.</div>';
        return;
      }

      grid.innerHTML = '';
      items.forEach(item => {
        const card = document.createElement('div');
        card.className  = 'tw-grid-card';
        card.dataset.id    = item.id;
        card.dataset.label = item.name || item.title || item.label || item.id;
        card.innerHTML  = `
          <strong>${card.dataset.label}</strong>
          ${item.description ? `<p>${item.description}</p>` : ''}
          ${item.tags        ? `<small>${item.tags}</small>`  : ''}
        `;
        card.addEventListener('click', () => {
          grid.querySelectorAll('.tw-grid-card').forEach(c => c.classList.remove('selected'));
          card.classList.add('selected');
          state.data[field + '_id']    = item.id;
          state.data[field + '_label'] = card.dataset.label;
        });
        grid.appendChild(card);
      });
    } catch (e) {
      grid.innerHTML = `<div class="tw-error">LOAD FAILED: ${e.message}</div>`;
    }
  }

  function validateGridStep(field, stepEl) {
    if (!state.data[field + '_id']) {
      const label = stepEl?.dataset?.phase || 'selection';
      alert(`Please make a selection in: ${label}`);
      return false;
    }
    return true;
  }

  // ── Attributes ────────────────────────────────────────────────────────────
  function initAttrs() {
    const pool    = Number(attrPoolInput?.value || 12);
    const min     = 1;
    const max     = 5;
    const numKeys = Object.keys(state.data.attrs).length; // 4

    function remaining() {
      return pool - Object.values(state.data.attrs).reduce((a, b) => a + b, 0);
    }

    wrapper.querySelectorAll('.tw-attr-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const key   = btn.dataset.attr;
        const delta = btn.classList.contains('tw-attr-plus') ? 1 : -1;
        const cur   = state.data.attrs[key];
        const rem   = remaining();

        if (delta === 1 && (cur >= max || rem <= 0)) return;
        if (delta === -1 && cur <= min)              return;

        state.data.attrs[key] = cur + delta;

        const input = document.getElementById(`tw-attr-${key}`);
        if (input) input.value = state.data.attrs[key];

        // pips
        const row  = btn.closest('.tw-attr-row');
        row?.querySelectorAll('.tw-pip').forEach(pip => {
          pip.classList.toggle('active', Number(pip.dataset.pip) <= state.data.attrs[key]);
        });

        if (attrRemaining) attrRemaining.textContent = remaining();
      });
    });
  }

  function validateAttrs() {
    const pool = Number(attrPoolInput?.value || 12);
    const used = Object.values(state.data.attrs).reduce((a, b) => a + b, 0);
    if (used !== pool) {
      alert(`You must spend all ${pool} attribute points. Currently used: ${used}.`);
      return false;
    }
    return true;
  }

  // ── Avatar ────────────────────────────────────────────────────────────────
  function initAvatar() {
    const fileInput  = document.getElementById('tw-char-avatar');
    const trigger    = wrapper.querySelector('.tw-upload-trigger');
    const dropZone   = document.getElementById('tw-avatar-drop');
    const preview    = document.getElementById('tw-avatar-preview');
    const selected   = document.getElementById('tw-avatar-selected');
    const imgEl      = document.getElementById('tw-avatar-img');
    const clearBtn   = document.getElementById('tw-avatar-clear');

    function handleFile(file) {
      if (!file || !file.type.startsWith('image/')) return;
      if (file.size > 2 * 1024 * 1024) { alert('Max file size is 2 MB.'); return; }
      state.data.avatar_file = file;
      const url = URL.createObjectURL(file);
      if (imgEl)    imgEl.src          = url;
      if (preview)  preview.style.display  = 'none';
      if (selected) selected.style.display = '';
    }

    trigger?.addEventListener('click', () => fileInput?.click());
    fileInput?.addEventListener('change', e => handleFile(e.target.files[0]));
    clearBtn?.addEventListener('click', () => {
      state.data.avatar_file   = null;
      if (fileInput)  fileInput.value      = '';
      if (imgEl)      imgEl.src            = '';
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

  // ── Pronouns custom field toggle ──────────────────────────────────────────
  function initPronouns() {
    const customInput = document.getElementById('tw-char-pronouns-custom');
    wrapper.querySelectorAll('.tw-pronoun-radio').forEach(r => {
      r.addEventListener('change', () => {
        if (customInput)
          customInput.style.display = r.value === 'custom' ? '' : 'none';
      });
    });
  }

  // ── Summary ───────────────────────────────────────────────────────────────
  function populateSummary() {
    const d = state.data;
    function set(id, val) {
      const el = document.getElementById(`tw-summary-${id}`);
      if (el) el.textContent = val || '—';
    }
    set('character_name', d.character_name);
    set('pronouns',       d.pronouns === 'custom' ? d.pronouns_custom : d.pronouns);
    set('backstory',      d.backstory ? d.backstory.substring(0, 80) + (d.backstory.length > 80 ? '…' : '') : '');
    set('race',           d.race_label);
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
      form.append('character_name',  d.character_name);
      form.append('pronouns',        d.pronouns === 'custom' ? d.pronouns_custom : d.pronouns);
      form.append('backstory',       d.backstory);
      form.append('race_id',         d.race_id);
      form.append('class_id',        d.class_id);
      form.append('node_id',         d.node_id);
      Object.entries(d.attrs).forEach(([k, v]) => form.append(`attr_${k}`, v));
      if (d.avatar_file) form.append('avatar', d.avatar_file);

      const res = await fetch(SUBMIT, {
        method : 'POST',
        headers: { 'X-WP-Nonce': NONCE },
        body   : form,
      });
      const json = await res.json();

      if (!res.ok || json.success === false) {
        throw new Error(json.message || json.data?.message || 'Unknown error');
      }

      if (statusBox) {
        statusBox.innerHTML = '<div class="tw-success">✓ AGENT SYNCHRONIZED. Redirecting…</div>';
      }
      setTimeout(() => { window.location.href = AGENTS; }, 1800);

    } catch (err) {
      setError('SYNC FAILED: ' + err.message);
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '▶ SYNCHRONIZE AGENT'; }
    }
  }

  // ── Navigation wiring ─────────────────────────────────────────────────────
  function initNav() {
    // Step 1 dedicated Next button
    document.getElementById('tw-char-step1-next')?.addEventListener('click', () => {
      if (validateStep1()) {
        loadGrid('races', 'tw-race-grid', 'race');
        showStep(2);
      }
    });

    // Generic NEXT buttons on steps 2–6
    wrapper.querySelectorAll('.tw-btn-next').forEach(btn => {
      btn.addEventListener('click', () => {
        const stepEl = btn.closest('.tw-step');
        const n      = Number(stepEl?.dataset?.step);
        const field  = stepEl?.dataset?.field;

        // Validate steps that require a grid selection
        if (field && !validateGridStep(field, stepEl)) return;

        // Step 4: attribute point check
        if (n === 4 && !validateAttrs()) return;

        const next = n + 1;
        if (next === 3) loadGrid('classes', 'tw-class-grid', 'class');
        if (next === 5) loadGrid('nodes',   'tw-node-grid',  'node');
        showStep(next);
      });
    });

    // BACK buttons
    wrapper.querySelectorAll('.tw-btn-prev').forEach(btn => {
      btn.addEventListener('click', () => {
        const n = Number(btn.closest('.tw-step')?.dataset?.step);
        if (n > 1) showStep(n - 1);
      });
    });

    // Summary edit buttons
    wrapper.querySelectorAll('.tw-summary-edit').forEach(btn => {
      btn.addEventListener('click', () => {
        const target = Number(btn.dataset.goto);
        if (target) showStep(target);
      });
    });

    // Final submit
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
