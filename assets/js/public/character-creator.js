(function () {
  'use strict';
  // ─── Config ──────────────────────────────────────────────────────────────────
  var cfg = Object.assign(
    {},
    window.neoweaverAjax       || {},
    window.twCharCreatorAjax   || {},
    window.twCharCreatorConfig || {},
    window.twCharCreatorGalleryConfig || {}
  );

  // ─── Constants ───────────────────────────────────────────────────────────────
  var ATTR_MIN   = 1;
  var ATTR_MAX   = 5;
  var ATTR_POOL  = 12;
  var ATTR_KEYS  = ['body', 'reflex', 'mind', 'spirit'];
  var TOTAL_STEPS = 11;
  var IMG_BASE   = 'https://neoweaver.nieodparady.pl/wp-content/uploads/';

  var uploads = cfg.uploadsbase
    ? String(cfg.uploadsbase).replace(/\/$/, '')
    : IMG_BASE.replace(/\/$/, '');

  // ─── Audio ───────────────────────────────────────────────────────────────────
  var sndTuning = new Audio(uploads + '/kreatory.mp3');
  var sndDeploy = new Audio(uploads + '/create-world.mp3');
  var audioUnlocked = false;

  sndTuning.preload = 'auto';
  sndDeploy.preload = 'auto';

  function playSound(audio) {
    try {
      audio.currentTime = 0;
      audio.play().catch(function () {});
    } catch (e) {}
  }

  function unlockAudio() {
    if (audioUnlocked) return;
    audioUnlocked = true;
    [sndTuning, sndDeploy].forEach(function (audio) {
      try {
        audio.volume = 0.01;
        var p = audio.play();
        if (p && typeof p.then === 'function') {
          p.then(function () {
            audio.pause();
            audio.currentTime = 0;
            audio.volume = 1;
          }).catch(function () {});
        }
      } catch (e) {}
    });
  }

  // ─── Lore option data ────────────────────────────────────────────────────────
  var DATA_ORIGIN_OPTIONS = [
    { key: 'palace',      label: 'Palace',      desc: 'Your consciousness was stabilized among luxury systems, court protocols, and prototype-grade environments.',                      bonus_tag: 'Wealthy',       bonus_desc: '+100 Credits at initialization.',                                               flaw_tag: 'Fragile-Gear',             flaw_desc: 'Base Durability of starting gear -2; using expensive but delicate prototypes.' },
    { key: 'slums',       label: 'Slums',        desc: 'Your core pattern held together in the noise of city rubble, scarcity, and improvised survival.',                               bonus_tag: 'Street-Smart',  bonus_desc: 'Reveal hidden mechanics in locations tagged #city or #shady.',                   flaw_tag: 'Malnourished',             flaw_desc: 'Max Satiety -2.' },
    { key: 'void-labs',   label: 'Void Labs',    desc: 'Your consciousness was first stabilized in isolated research arrays and experimental sync chambers.',                           bonus_tag: 'Fast-Sync',     bonus_desc: 'Resting recovers +2 additional Sync.',                                          flaw_tag: 'Social-Glitch',            flaw_desc: '-10% success rate on Social actions vs #human targets.' },
    { key: 'borderlines', label: 'Borderlines',  desc: 'Your first stable thoughts formed on the edge of mapped zones, between signal, wasteland, and frontier.',                      bonus_tag: 'Scout',         bonus_desc: 'Travel between nodes consumes -1 Satiety.',                                     flaw_tag: 'Analog-Mind',              flaw_desc: 'Cannot use #Digital cards during the first 3 turns of a Deployment.' }
  ];

  var PREVIOUS_OPERATION_OPTIONS = [
    { key: 'repair-unit',      label: '[REPAIR UNIT]',      desc: 'You were built to restore, patch, and keep fractured systems functional under pressure.',                            bonus_tag: 'Technician',  bonus_desc: 'Utility items restore +50% more Durability.',                                   flaw_tag: 'Heavy-Handed',   flaw_desc: '-5% success rate on Acrobatics and Stealth tests.' },
    { key: 'void-runner',      label: '[VOID-RUNNER]',      desc: 'Your primary function was speed, transit, and surviving dangerous movement through unstable space.',                bonus_tag: 'Agile',       bonus_desc: 'Playing a Dodge card allows drawing an extra card on the next turn.',           flaw_tag: 'Light-Frame',    flaw_desc: 'Starting Max HP -1.' },
    { key: 'archive-analyst',  label: '[ARCHIVE ANALYST]',  desc: 'You processed forbidden knowledge, recovered fragmented data, and interpreted arcane or scientific records.',       bonus_tag: 'Researcher',  bonus_desc: '+5% success rate on Arcana and Science tests.',                                 flaw_tag: 'Code-Bound',     flaw_desc: 'Cannot equip two-handed weapons.' },
    { key: 'enforcer',         label: '[ENFORCER]',         desc: 'You existed to apply force, hold the line, and suppress escalation when systems failed.',                           bonus_tag: 'Unyielding',  bonus_desc: 'Ignore the first Pressure or Panic card encountered in every combat.',         flaw_tag: 'Loud-Footsteps', flaw_desc: 'Cannot obtain "First Strike" bonus from stealth.' }
  ];

  var SYNC_CRISIS_OPTIONS = [
    { key: 'system-stabilizer',      label: '[SYSTEM STABILIZER]',      desc: 'You answered the first touch of Entropy by reinforcing the pattern and learning from the breach.',     bonus_tag: 'Glitch-Learner', bonus_desc: '+10% global XP gain.',                                                        flaw_tag: 'System-Spasm',           flaw_desc: 'Every 10 turns, one random card from your hand is discarded/burned.' },
    { key: 'aggressive-response',    label: '[AGGRESSIVE RESPONSE]',    desc: 'You met the Fray by pushing back harder, turning survival into pressure and violence.',               bonus_tag: 'Striker',        bonus_desc: 'Every played Attack card generates +1 additional XP for itself.',             flaw_tag: 'Reckless',               flaw_desc: 'On failure in a Physical test, lose an additional 1 Durability on armor.' },
    { key: 'data-ghost-adaptation',  label: '[DATA-GHOST ADAPTATION]',  desc: 'You adapted by becoming difficult to hold, half-solid in action and difficult to disrupt.',           bonus_tag: 'Iron-Grip',      bonus_desc: 'Your physical attack cards cannot be countered.',                             flaw_tag: 'Feedback-Vulnerability', flaw_desc: 'Receive double damage from enemies with the #Hacker or #Digital tag.' },
    { key: 'sensory-overload',       label: '[SENSORY OVERLOAD]',       desc: 'You survived by embracing the flood of input, turning collapse into unstable power.',                 bonus_tag: 'Wild-Card',      bonus_desc: 'Critical successes deal triple damage instead of double.',                   flaw_tag: 'Magnetized',             flaw_desc: 'In locations tagged #High-Technology, suffer -5% to all tests.' }
  ];

  // ─── Tag ID lookup ────────────────────────────────────────────────────────────
  var TAG_DEFS = {
    'wealthy': 1, 'fragile-gear': 2, 'street-smart': 3, 'malnourished': 4,
    'fast-sync': 5, 'social-glitch': 6, 'scout': 7, 'analog-mind': 8,
    'striker': 9, 'reckless': 10, 'glitch-learner': 11, 'system-spasm': 12,
    'iron-grip': 15, 'feedback-vulnerability': 16, 'wild-card': 17, 'magnetized': 18,
    'technician': 19, 'heavy-handed': 20, 'agile': 21, 'light-frame': 22,
    'researcher': 23, 'code-bound': 24, 'unyielding': 25, 'loud-footsteps': 26
  };

  // ─── State ───────────────────────────────────────────────────────────────────
  var state = {
    character_name: '', pronouns: '', pronouns_custom: '',
    race: '', race_label: '', subrace: '', subrace_label: '',
    char_class: '', class_label: '', class_slug: '', skill_limit: 3, skills: [],
    starting_package_id: '', starting_package_label: '',
    data_origin: '', data_origin_label: '',
    previous_operation: '', previous_operation_label: '',
    sync_crisis: '', sync_crisis_label: '',
    backstory_tags: [], avatar_file: null, avatar_url: '', bio: '',
    attr_body: ATTR_MIN, attr_reflex: ATTR_MIN, attr_mind: ATTR_MIN, attr_spirit: ATTR_MIN,
    races: [], subraces: [], classes: [], skills_data: [], packages: []
  };

  // ─── Wizard state ─────────────────────────────────────────────────────────────
  var currentStep        = 0;
  var returnToReviewStep = null;
  var root               = null;
  var stepEls            = [];
  // BUG FIX: was declared twice (once here, once just before init()), causing the
  // second declaration to reset it to `false` on every module evaluation.
  var isInitialized      = false;
  var isSubmitting       = false;

  // ─── DOM helpers ─────────────────────────────────────────────────────────────
  function q(sel, ctx)  { return (ctx || document).querySelector(sel); }
  function qa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function esc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function ajaxUrl() { return cfg.ajaxurl || cfg.ajax_url || '/wp-admin/admin-ajax.php'; }
  function nonce()   { return cfg.nonce || ''; }

  // BUG FIX: was using IMG_BASE as fallback inside the function even though
  // `uploads` already holds the resolved base URL from config.
  function normalizeMediaUrl(url) {
    url = String(url || '').trim();
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    return uploads + '/' + url.replace(/^\/+/, '');
  }

  function slugifyTagLabel(value) {
    return String(value || '').trim().toLowerCase()
      .replace(/[^a-z0-9\- ]+/g, '').replace(/\s+/g, '-')
      .replace(/-+/g, '-').replace(/^-|-$/g, '');
  }

  function slugify(str) {
    return String(str || '').trim().toLowerCase()
      .replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
  }

  // ─── Backstory tag resolution ─────────────────────────────────────────────────
  function resolveBackstoryTags() {
    var ids = [];
    var sources = [DATA_ORIGIN_OPTIONS, PREVIOUS_OPERATION_OPTIONS, SYNC_CRISIS_OPTIONS];
    var keys    = [state.data_origin, state.previous_operation, state.sync_crisis];

    keys.forEach(function (key, index) {
      var item = null;
      var src  = sources[index];
      for (var i = 0; i < src.length; i++) {
        if (src[i].key === key) { item = src[i]; break; }
      }
      if (!item) return;
      [item.bonus_tag, item.flaw_tag].forEach(function (label) {
        var slug = slugifyTagLabel(label);
        var id   = TAG_DEFS[slug];
        if (id && ids.indexOf(id) === -1) ids.push(id);
      });
    });

    state.backstory_tags = ids;
  }

  // ─── AJAX helper ─────────────────────────────────────────────────────────────
  function fetchPost(action, extraData) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', nonce());
    Object.keys(extraData || {}).forEach(function (key) { fd.append(key, extraData[key]); });
    return fetch(ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); });
  }

  // ─── Status / error helpers ───────────────────────────────────────────────────
  function setStatus(message, type) {
    var box = q('.tw-char-status', root);
    if (!box) return;
    box.className = 'tw-char-status' + (type ? ' is-' + type : '');
    box.textContent = message || '';
  }

  function clearStepErrors() {
    qa('.tw-char-step .tw-step-error', root).forEach(function (el) {
      el.classList.remove('is-visible');
      var msg = q('.tw-step-error-msg', el);
      if (msg) msg.textContent = '';
    });
  }

  function showStepError(stepIndex, message) {
    clearStepErrors();
    var step = stepEls[stepIndex];
    if (!step) return;
    var box = q('.tw-step-error', step);
    var msg = q('.tw-step-error-msg', step);
    if (msg) msg.textContent = message || 'Please review this step.';
    if (box) box.classList.add('is-visible');
    setStatus(message || 'Validation error.', 'error');
  }

  // ─── Pronoun toggle ───────────────────────────────────────────────────────────
  function toggleCustomPronouns() {
    var wrap = q('#tw-char-pronouns-custom-wrap', root);
    if (!wrap) return;
    var active = state.pronouns === 'custom';
    wrap.hidden       = !active;
    wrap.style.display = active ? '' : 'none';
  }

  // ─── Step navigation ──────────────────────────────────────────────────────────
  function updateStepUI() {
    stepEls.forEach(function (step, index) {
      var active = index === currentStep;
      step.classList.toggle('active', active);
      step.hidden        = !active;
      step.style.display = active ? 'block' : 'none';
    });

    var stepCurrent  = q('#tw-char-step-current', root);
    var progressFill = q('#tw-char-progress-fill', root);
    var progressPhase = q('#tw-char-progress-phase', root);

    if (stepCurrent)  stepCurrent.textContent  = String(currentStep + 1);
    if (progressFill) progressFill.style.width = (((currentStep + 1) / TOTAL_STEPS) * 100) + '%';
    if (progressPhase && stepEls[currentStep]) {
      progressPhase.textContent = stepEls[currentStep].getAttribute('data-phase') || '';
    }

    qa('.tw-progress-tick', root).forEach(function (tick, index) {
      tick.classList.toggle('active', index <= currentStep);
    });

    window.requestAnimationFrame(function () {
      var rect = root.getBoundingClientRect();
      if (rect.top < 0) window.scrollTo({ top: window.scrollY + rect.top - 16, behavior: 'smooth' });
    });
  }

  function goToStep(index) {
    if (index < 0 || index >= stepEls.length) return;
    currentStep = index;
    updateStepUI();
    if (currentStep === 10) updateSummary();
  }

  function nextStep() {
    var error = validateStep(currentStep);
    if (error) { showStepError(currentStep, error); return; }

    playSound(sndTuning);
    clearStepErrors();
    setStatus('', '');

    if (returnToReviewStep !== null && currentStep < returnToReviewStep) {
      var target = returnToReviewStep;
      returnToReviewStep = null;
      goToStep(target);
      return;
    }

    if (currentStep < stepEls.length - 1) goToStep(currentStep + 1);
  }

  function prevStep() {
    playSound(sndTuning);
    clearStepErrors();
    setStatus('', '');
    if (currentStep > 0) goToStep(currentStep - 1);
  }

  // ─── Attribute helpers ────────────────────────────────────────────────────────
  function getAttrTotal()     { return ATTR_KEYS.reduce(function (s, k) { return s + Number(state['attr_' + k]); }, 0); }
  function getAttrRemaining() { return ATTR_POOL - getAttrTotal(); }

  function updateAttrUI() {
    ATTR_KEYS.forEach(function (key) {
      var value = Number(state['attr_' + key]);
      var input = q('#tw-attr-' + key, root);
      if (input) input.value = String(value);

      var row = q('.tw-attr-row[data-attr="' + key + '"]', root);
      if (row) {
        qa('.tw-pip', row).forEach(function (pip, index) {
          pip.classList.toggle('active', index < value);
        });
      }
    });

    var rem = q('#tw-attr-remaining', root);
    if (rem) rem.textContent = String(getAttrRemaining());
  }

  function setAttr(key, next) {
    next = Math.max(ATTR_MIN, Math.min(ATTR_MAX, next));
    var current      = Number(state['attr_' + key]);
    var totalWithout = getAttrTotal() - current;
    if (totalWithout + next > ATTR_POOL) return;
    state['attr_' + key] = next;
    updateAttrUI();
  }

  function applyPreset(name) {
    var presets = {
      balanced:    { body: 3, reflex: 3, mind: 3, spirit: 3 },
      agile:       { body: 2, reflex: 4, mind: 3, spirit: 3 },
      tank:        { body: 5, reflex: 2, mind: 2, spirit: 3 },
      bodybuilder: { body: 5, reflex: 3, mind: 2, spirit: 2 },
      gunslinger:  { body: 2, reflex: 5, mind: 3, spirit: 2 },
      genius:      { body: 2, reflex: 2, mind: 5, spirit: 3 },
      warlock:     { body: 2, reflex: 2, mind: 3, spirit: 5 }
    };
    if (!presets[name]) return;
    ATTR_KEYS.forEach(function (key) { state['attr_' + key] = presets[name][key]; });
    updateAttrUI();
    qa('.tw-attr-preset-btn', root).forEach(function (btn) {
      btn.setAttribute('aria-pressed', btn.getAttribute('data-preset') === name ? 'true' : 'false');
    });
  }

  // ─── Skill counter ────────────────────────────────────────────────────────────
  function updateSkillCounter() {
    var el = q('#tw-skill-counter', root);
    if (el) el.textContent = state.skills.length + ' / ' + state.skill_limit + ' skills';
  }

  // ─── Summary ─────────────────────────────────────────────────────────────────
  function updateSummary() {
    resolveBackstoryTags();

    var raceText   = state.subrace_label ? (state.race_label + ' / ' + state.subrace_label) : state.race_label;
    var skillsText = state.skills.map(function (id) {
      var item = null;
      for (var i = 0; i < state.skills_data.length; i++) {
        if (state.skills_data[i].id === id) { item = state.skills_data[i]; break; }
      }
      return item ? item.name : id;
    }).join(', ');

    var map = {
      '#tw-summary-character-name': state.character_name || '—',
      '#tw-summary-pronouns':       state.pronouns === 'custom' ? (state.pronouns_custom || 'custom') : (state.pronouns || '—'),
      '#tw-summary-race':           raceText || '—',
      '#tw-summary-class':          state.class_label || '—',
      '#tw-summary-attrs':          'Body ' + state.attr_body + ' · Reflex ' + state.attr_reflex + ' · Mind ' + state.attr_mind + ' · Spirit ' + state.attr_spirit,
      '#tw-summary-skills':         skillsText || '—',
      '#tw-summary-package':        state.starting_package_label || '—',
      '#tw-summary-origin':         state.data_origin_label || '—',
      '#tw-summary-operation':      state.previous_operation_label || '—',
      '#tw-summary-crisis':         state.sync_crisis_label || '—',
      '#tw-summary-tag-bundle':     state.backstory_tags.join(', ') || '—',
      '#tw-summary-bio':            state.bio || '—',
      '#tw-summary-avatar':         state.avatar_file ? state.avatar_file.name : (state.avatar_url ? 'Gallery avatar selected' : '—')
    };

    Object.keys(map).forEach(function (selector) {
      var el = q(selector, root);
      if (el) el.textContent = map[selector];
    });
  }

  // ─── Step validation ──────────────────────────────────────────────────────────
  function validateStep(index) {
    if (index === 0) {
      state.character_name  = q('#tw-char-name', root) ? q('#tw-char-name', root).value.trim() : '';
      var checked           = q('input[name="tw-char-pronouns"]:checked', root);
      state.pronouns        = checked ? checked.value : '';
      state.pronouns_custom = q('#tw-char-pronouns-custom', root) ? q('#tw-char-pronouns-custom', root).value.trim() : '';

      if (!state.character_name) return 'Character name is required.';
      if (!state.pronouns)       return 'Pronouns are required.';
      if (state.pronouns === 'custom' && !state.pronouns_custom) return 'Custom pronouns are required.';
    }

    if (index === 1) {
      if (!state.race)    return 'Choose a race.';
      if (!state.subrace) return 'Choose a subrace.';
    }

    if (index === 2 && !state.char_class) return 'Choose a class.';

    if (index === 3 && getAttrTotal() !== ATTR_POOL) {
      return 'Attribute total must equal ' + ATTR_POOL + '.';
    }

    if (index === 4) {
      if (!state.skills.length)              return 'Choose at least one skill.';
      if (state.skills.length > state.skill_limit) return 'Too many skills selected for this class.';
    }

    if (index === 5 && !state.starting_package_id) return 'Choose a starting package.';
    if (index === 6 && !state.data_origin)          return 'Choose data origin.';
    if (index === 7 && !state.previous_operation)   return 'Choose previous operation.';

    if (index === 8) {
      if (!state.sync_crisis) return 'Choose sync crisis.';
      resolveBackstoryTags();
      if (!state.backstory_tags.length) return 'Backstory tags could not be resolved.';
    }

    if (index === 9) {
      state.bio = q('#tw-char-bio', root) ? q('#tw-char-bio', root).value.trim() : '';
    }

    if (index === 10) updateSummary();

    return '';
  }

  // ─── Render helpers ───────────────────────────────────────────────────────────
  function buildTagPills(tags) {
    if (!Array.isArray(tags) || !tags.length) return '';
    return '<div class="tw-card-tags">' + tags.map(function (tag) {
      var name = typeof tag === 'string' ? tag : (tag && (tag.name || tag.label) ? (tag.name || tag.label) : '');
      return '<span class="tw-card-tag">' + esc(name) + '</span>';
    }).join('') + '</div>';
  }

  function buildImage(url, alt, cls, placeholder) {
    var src = normalizeMediaUrl(url);
    if (src) {
      return '<div class="' + esc(cls || 'tw-card-media') + '"><img src="' + esc(src) + '" alt="' + esc(alt || '') + '" loading="lazy"></div>';
    }
    return '<div class="' + esc(cls || 'tw-card-media') + ' tw-card-media--placeholder"><span>' + esc(placeholder || 'NO IMAGE') + '</span></div>';
  }

  function selectExclusive(selector, activeEl) {
    qa(selector, root).forEach(function (el) {
      var selected = el === activeEl;
      el.classList.toggle('is-selected', selected);
      el.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
  }

  // ─── Grid renderers ───────────────────────────────────────────────────────────
  function renderRaceGrid(rows, targetSel, mode) {
    var target = q(targetSel, root);
    if (!target) return;

    if (!Array.isArray(rows) || !rows.length) {
      target.innerHTML = '<div class="tw-empty-state">No options available.</div>';
      return;
    }

    target.innerHTML = rows.map(function (row) {
      var tags = Array.isArray(row.tags) ? row.tags : [];
      return (
        '<button type="button" class="tw-race-card" data-mode="' + esc(mode) + '" data-id="' + esc(row.id) + '" data-name="' + esc(row.name || row.label) + '">' +
          buildImage(row.img_url || row.img, row.name || row.label, 'tw-race-img', 'RACE') +
          '<div class="tw-race-body">' +
            '<h3 class="tw-race-name">' + esc(row.name || row.label) + '</h3>' +
            (row.bonus ? '<p class="tw-race-bonus">' + esc(row.bonus) + '</p>' : '') +
            buildTagPills(tags) +
          '</div>' +
        '</button>'
      );
    }).join('');
  }

  function renderClassGrid(rows) {
    var target = q('#tw-class-grid', root);
    if (!target) return;

    if (!Array.isArray(rows) || !rows.length) {
      target.innerHTML = '<div class="tw-empty-state">No classes available.</div>';
      return;
    }

    target.innerHTML = rows.map(function (row) {
      var className = row.name || '';
      return (
        '<button type="button" class="tw-class-card" data-id="' + esc(row.id) + '" data-name="' + esc(className) + '" data-slug="' + esc(slugify(className)) + '" data-limit="' + esc(row.skill_limit || 3) + '">' +
          buildImage(row.img_url || row.icon_slug, className, 'tw-class-cardimg-wrap', 'CLASS') +
          '<div class="tw-class-cardbody">' +
            '<h3 class="tw-class-cardname">' + esc(className) + '</h3>' +
            buildTagPills(row.tags || []) +
          '</div>' +
        '</button>'
      );
    }).join('');
  }

  function renderSkills(rows) {
    var target = q('#tw-skill-grid', root);
    if (!target) { updateSkillCounter(); return; }

    if (!Array.isArray(rows) || !rows.length) {
      target.innerHTML = '<div class="tw-empty-state">No skills available.</div>';
      updateSkillCounter();
      return;
    }

    var byCat = {};
    rows.forEach(function (row) {
      var cat = ((row.category || '').trim()) || 'Other';
      if (!byCat[cat]) byCat[cat] = [];
      byCat[cat].push(row);
    });

    target.innerHTML = Object.keys(byCat).map(function (cat) {
      var cards = byCat[cat].map(function (row) {
        var selected = state.skills.indexOf(row.id) !== -1;
        return (
          '<button type="button" class="tw-skill-card' + (selected ? ' is-selected' : '') + '" data-id="' + esc(row.id) + '" data-name="' + esc(row.name) + '">' +
            buildImage(row.img_url, row.name, 'tw-skill-cardimg', 'SKILL') +
            '<div class="tw-skill-cardbody">' +
              '<h3 class="tw-skill-cardname">' + esc(row.name) + '</h3>' +
              (row.description ? '<p class="tw-race-desc">' + esc(row.description) + '</p>' : '') +
              buildTagPills(row.tags) +
            '</div>' +
          '</button>'
        );
      }).join('');
      return (
        '<section class="tw-skill-category">' +
          '<h3 class="tw-skill-cat-label">' + esc(cat) + '</h3>' +
          '<div class="tw-skill-cat-grid">' + cards + '</div>' +
        '</section>'
      );
    }).join('');

    updateSkillCounter();
  }

  function renderPackages(rows) {
    var grid = q('#tw-package-grid', root);
    if (!grid) return;

    if (!Array.isArray(rows) || !rows.length) {
      grid.innerHTML = '<div class="tw-empty-state">No starting packages available.</div>';
      return;
    }

    grid.innerHTML = rows.map(function (pkg) {
      var selected = state.starting_package_id === String(pkg.id || '');
      // BUG FIX: Supabase returns snake_case; also accept camelCase fallback.
      var compatTags = pkg.compatibility_tags || pkg.compatibilitytags || [];
      var items      = Array.isArray(pkg.itemslist) ? pkg.itemslist : (Array.isArray(pkg.items) ? pkg.items : []);
      var name       = pkg.name || pkg.packagename || '';

      return (
        '<button type="button" class="tw-package-card' + (selected ? ' selected' : '') + '" data-id="' + esc(pkg.id || '') + '" data-name="' + esc(name) + '">' +
          '<div class="tw-package-cardbody">' +
            '<h3 class="tw-package-cardtitle">' + esc(name) + '</h3>' +
            buildTagPills(compatTags) +
            (pkg.description ? '<p class="tw-package-desc">' + esc(pkg.description) + '</p>' : '') +
            (items.length ? '<div class="tw-package-items">' + buildTagPills(items) + '</div>' : '') +
          '</div>' +
        '</button>'
      );
    }).join('');
  }

  function renderLoreOptions(targetSel, rows, typeKey) {
    var target = q(targetSel, root);
    if (!target) return;

    target.innerHTML = rows.map(function (row) {
      var selected  = state[typeKey] === row.key;
      var bonusTag  = row.bonus_tag  || row.bonustag  || '';
      var bonusDesc = row.bonus_desc || row.bonusdesc || '';
      var flawTag   = row.flaw_tag   || row.flawtag   || '';
      var flawDesc  = row.flaw_desc  || row.flawdesc  || '';

      return (
        '<button type="button" class="tw-lore-card tw-grid-card' + (selected ? ' is-selected' : '') + '"' +
        ' data-kind="' + esc(typeKey) + '"' +
        ' data-key="' + esc(row.key) + '"' +
        ' data-label="' + esc(row.label) + '"' +
        ' aria-pressed="' + (selected ? 'true' : 'false') + '">' +
          '<div class="tw-race-body">' +
            '<h3 class="tw-race-name">' + esc(row.label) + '</h3>' +
            (row.desc ? '<p class="tw-race-desc">' + esc(row.desc) + '</p>' : '') +
            '<div class="tw-lore-card__effects">' +
              '<div class="tw-lore-card__effect"><strong>' + esc(bonusTag) + ':</strong> ' + esc(bonusDesc) + '</div>' +
              '<div class="tw-lore-card__effect"><strong>' + esc(flawTag) + ':</strong> ' + esc(flawDesc) + '</div>' +
            '</div>' +
            '<span class="tw-race-select-hint">Select</span>' +
          '</div>' +
        '</button>'
      );
    }).join('');
  }

  // ─── Avatar ───────────────────────────────────────────────────────────────────
  function renderAvatarGallery() {
  var target = q('#tw-avatar-gallery', root);
  if (!target) return;

  var gallery =
    Array.isArray(cfg.avatar_gallery) ? cfg.avatar_gallery :
    Array.isArray(cfg.avatarGallery) ? cfg.avatarGallery :
    Array.isArray(cfg.avatargallery) ? cfg.avatargallery :
    [];

  target.innerHTML = gallery.map(function (item) {
    var url = normalizeMediaUrl(item.url || item.file || '');
    var selected = state.avatar_url === url;

    return (
      '<button type="button" class="tw-avatar-card' + (selected ? ' is-selected' : '') + '" data-url="' + esc(url) + '">' +
        '<img src="' + esc(url) + '" alt="' + esc(item.name || 'Avatar') + '" loading="lazy">' +
      '</button>'
    );
  }).join('');
}

  function updateAvatarPreview() {
    var wrap = q('#tw-avatar-selected', root);
    var img  = q('#tw-avatar-img', root);
    if (!wrap || !img) return;

    if (state.avatar_file) {
      img.src = URL.createObjectURL(state.avatar_file);
      img.alt = state.avatar_file.name || 'Uploaded avatar';
      wrap.style.display = '';
      return;
    }

    if (state.avatar_url) {
      img.src = state.avatar_url;
      img.alt = 'Selected avatar';
      wrap.style.display = '';
      return;
    }

    img.src = img.alt = '';
    wrap.style.display = 'none';
  }

  // ─── Event binding ────────────────────────────────────────────────────────────
  function bindStaticEvents() {
    document.addEventListener('click', unlockAudio, { once: true });

    // Form submit (if wrapped in a <form>)
    var form = root.closest('form') || q('form', root);
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopPropagation();
        submitCharacter();
      });
    }

    // BUG FIX: submit button was wired up twice. One block set type="button"
    // AND added a listener; a second identical block added a second listener.
    // Now there is exactly one listener, and the button type is set once.
    var submitBtn = q('#tw-char-submit', root);
    if (submitBtn) {
      submitBtn.setAttribute('type', 'button');
      submitBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        submitCharacter();
      });
    }

    // Pronouns
    qa('input[name="tw-char-pronouns"]', root).forEach(function (input) {
      input.addEventListener('change', function () {
        state.pronouns = input.value;
        toggleCustomPronouns();
      });
    });

    var customPronouns = q('#tw-char-pronouns-custom', root);
    if (customPronouns) {
      // Seed state from current DOM value on bind (in case of pre-filled value)
      state.pronouns_custom = customPronouns.value.trim();
      customPronouns.addEventListener('input', function (e) {
        state.pronouns_custom = e.target.value;
      });
    }

    var bioInput = q('#tw-char-bio', root);
    if (bioInput) {
      bioInput.addEventListener('input', function (e) { state.bio = e.target.value; });
    }

    // Step navigation buttons
    qa('.tw-btn-next', root).forEach(function (btn) { btn.addEventListener('click', nextStep); });
    qa('.tw-btn-prev', root).forEach(function (btn) { btn.addEventListener('click', prevStep); });

    // Attribute rows
    qa('.tw-attr-row', root).forEach(function (row) {
      var key = row.getAttribute('data-attr');

      var minus = q('.tw-attr-minus', row);
      if (minus) minus.addEventListener('click', function () { setAttr(key, Number(state['attr_' + key]) - 1); });

      var plus = q('.tw-attr-plus', row);
      if (plus)  plus.addEventListener('click',  function () { setAttr(key, Number(state['attr_' + key]) + 1); });

      qa('.tw-pip', row).forEach(function (pip) {
        pip.addEventListener('click', function () {
          setAttr(key, Number(pip.getAttribute('data-pip')) || ATTR_MIN);
        });
      });
    });

    // Attribute presets
    qa('.tw-attr-preset-btn', root).forEach(function (btn) {
      btn.addEventListener('click', function () { applyPreset(btn.getAttribute('data-preset')); });
    });

    // Avatar file upload
    var avatarInput = q('#tw-char-avatar', root);
    if (avatarInput) {
      avatarInput.addEventListener('change', function (e) {
        var file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
        state.avatar_file = file;
        if (file) state.avatar_url = '';
        renderAvatarGallery();
        updateAvatarPreview();
      });
    }

    var avatarClear = q('#tw-char-avatar-clear', root);
    if (avatarClear) {
      avatarClear.addEventListener('click', function () {
        state.avatar_file = null;
        state.avatar_url  = '';
        var inp = q('#tw-char-avatar', root);
        if (inp) inp.value = '';
        renderAvatarGallery();
        updateAvatarPreview();
      });
    }

    // Delegated click handler for all card interactions
    root.addEventListener('click', function (e) {

      // Review-step edit buttons
      var editBtn = e.target.closest('.tw-btn-review-edit');
      if (editBtn) {
        var editTarget = parseInt(editBtn.getAttribute('data-target-step'), 10);
        if (!isNaN(editTarget) && stepEls[editTarget]) {
          returnToReviewStep = 10;
          goToStep(editTarget);
        }
        return;
      }

      // Summary inline edit links
      var summaryEdit = e.target.closest('.tw-summary-edit');
      if (summaryEdit) {
        var summaryTarget = Number(summaryEdit.getAttribute('data-step'));
        if (!isNaN(summaryTarget) && stepEls[summaryTarget]) {
          returnToReviewStep = 10;
          goToStep(summaryTarget);
        }
        return;
      }

      // Race / subrace card
      var raceCard = e.target.closest('.tw-race-card');
      if (raceCard) {
        playSound(sndTuning);
        var mode = raceCard.getAttribute('data-mode');
        var id   = raceCard.getAttribute('data-id');
        var name = raceCard.getAttribute('data-name');

        if (mode === 'race') {
          // Store parent race UUID; clear subrace until user picks one
          state.race        = id;
          state.race_label  = name;
          state.subrace     = '';
          state.subrace_label = '';
          selectExclusive('.tw-race-card[data-mode="race"]', raceCard);

          var subraceSection = q('#tw-subrace-section', root);
          var subraceGrid    = q('#tw-subrace-grid', root);
          if (subraceGrid) subraceGrid.innerHTML = '<p class="tw-loading-state"><span class="tw-loading-dot"></span><span>Loading subraces</span></p>';
          if (subraceSection) {
            subraceSection.hidden        = false;
            subraceSection.style.display = 'block';
            subraceSection.classList.remove('is-hidden');
            subraceSection.classList.add('is-visible');
          }
          loadSubraces(id, name);
          // Scroll to subrace heading
window.requestAnimationFrame(function () {
  var subraceHeading = document.getElementById('subrace-selection');
  if (subraceHeading) {
    subraceHeading.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
});

        } else if (mode === 'subrace') {
          // UUID of the subrace — do NOT overwrite state.race (parent UUID)
          state.subrace       = id;
          state.subrace_label = name;
          selectExclusive('.tw-race-card[data-mode="subrace"]', raceCard);
          setStatus('', '');
        }
        return;
      }

      // Class card
      var classCard = e.target.closest('.tw-class-card');
      if (classCard) {
        playSound(sndTuning);
        state.char_class             = classCard.getAttribute('data-id')   || '';
        state.class_label            = classCard.getAttribute('data-name') || '';
        state.class_slug             = classCard.getAttribute('data-slug') || slugify(state.class_label);
        state.skill_limit            = Number(classCard.getAttribute('data-limit')) || 3;
        state.skills                 = [];
        state.starting_package_id    = '';
        state.starting_package_label = '';
        state.packages               = [];

        selectExclusive('.tw-class-card', classCard);
        renderSkills(state.skills_data);
        renderPackages([]);

        loadPackages(state.char_class).then(function (rows) {
          if (!rows.length) setStatus('No starting packages found for class: ' + state.class_label, 'error');
          else setStatus('', '');
        });
        return;
      }

      // Skill card
      var skillCard = e.target.closest('.tw-skill-card');
      if (skillCard) {
        playSound(sndTuning);
        var skillId = skillCard.getAttribute('data-id') || '';
        var idx     = state.skills.indexOf(skillId);
        if (idx !== -1) state.skills.splice(idx, 1);
        else if (state.skills.length < state.skill_limit) state.skills.push(skillId);
        renderSkills(state.skills_data);
        return;
      }

      // Package card
      var packageCard = e.target.closest('.tw-package-card');
      if (packageCard) {
        playSound(sndTuning);
        state.starting_package_id    = packageCard.getAttribute('data-id')   || '';
        state.starting_package_label = packageCard.getAttribute('data-name') || '';
        selectExclusive('.tw-package-card', packageCard);
        return;
      }

      // Lore card (origin / operation / crisis)
      var loreCard = e.target.closest('.tw-lore-card');
      if (loreCard) {
        playSound(sndTuning);
        var kind  = loreCard.getAttribute('data-kind')  || '';
        var lkey  = loreCard.getAttribute('data-key')   || '';
        var label = loreCard.getAttribute('data-label') || '';

        if (kind === 'data_origin')        { state.data_origin        = lkey; state.data_origin_label        = label; selectExclusive('.tw-lore-card[data-kind="data_origin"]',       loreCard); }
        if (kind === 'previous_operation') { state.previous_operation = lkey; state.previous_operation_label = label; selectExclusive('.tw-lore-card[data-kind="previous_operation"]', loreCard); }
        if (kind === 'sync_crisis')        { state.sync_crisis        = lkey; state.sync_crisis_label        = label; selectExclusive('.tw-lore-card[data-kind="sync_crisis"]',        loreCard); }

        resolveBackstoryTags();
        return;
      }

      // Avatar gallery card
      var avatarCard = e.target.closest('.tw-avatar-card');
      if (avatarCard) {
        playSound(sndTuning);
        state.avatar_file = null;
        state.avatar_url  = avatarCard.getAttribute('data-url') || '';
        var avatarInput2  = q('#tw-char-avatar', root);
        if (avatarInput2) avatarInput2.value = '';
        renderAvatarGallery();
        updateAvatarPreview();
      }
    });
  }

  // ─── Character submission ─────────────────────────────────────────────────────
  function submitCharacter() {
    if (isSubmitting) return;
    isSubmitting = true;

    var submitBtn = q('#tw-char-submit', root);
    if (submitBtn) submitBtn.disabled = true;

    var error = validateStep(10);
    if (error) {
      isSubmitting = false;
      if (submitBtn) submitBtn.disabled = false;
      showStepError(10, error);
      return;
    }

    playSound(sndDeploy);

    var spinner = q('#tw-char-spinner', root);
    if (spinner) spinner.classList.add('active');
    setStatus('Creating character…', 'info');

    var fd = new FormData();
    fd.append('action',              'neoweaver_create_character');
    fd.append('nonce',               nonce());
    fd.append('character_name',      state.character_name);
    fd.append('pronouns',            state.pronouns === 'custom' ? 'custom' : state.pronouns);
    fd.append('bio',                 state.bio || '');
    fd.append('race',                state.race);
    fd.append('subrace',             state.subrace);
    fd.append('char_class',          state.char_class || '');
    fd.append('starting_package_id', state.starting_package_id || '');
    fd.append('data_origin',         state.data_origin || '');
    fd.append('previous_operation',  state.previous_operation || '');
    fd.append('sync_crisis',         state.sync_crisis || '');
    fd.append('skills',              JSON.stringify(state.skills));
    fd.append('backstory_tags',      JSON.stringify(state.backstory_tags));
    fd.append('attr_body',           String(state.attr_body));
    fd.append('attr_reflex',         String(state.attr_reflex));
    fd.append('attr_mind',           String(state.attr_mind));
    fd.append('attr_spirit',         String(state.attr_spirit));

    if (state.avatar_url)  fd.append('avatar_url', state.avatar_url);
    if (state.avatar_file) fd.append('avatar', state.avatar_file, state.avatar_file.name);

    fetch(ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) {
          throw new Error(res && res.data && res.data.message ? res.data.message : 'Character creation failed.');
        }
        setStatus(res.data && res.data.message ? res.data.message : 'Character created.', 'success');
        if (res.data && res.data.redirect) window.location.href = res.data.redirect;
      })
      .catch(function (err) {
        showStepError(10, err && err.message ? err.message : 'Character creation failed.');
      })
      .finally(function () {
        isSubmitting = false;
        if (submitBtn) submitBtn.disabled = false;
        if (spinner)   spinner.classList.remove('active');
      });
  }

  // ─── Data loaders ─────────────────────────────────────────────────────────────
  function loadRaces() {
    return fetchPost('neoweaver_get_races', {}).then(function (res) {
      state.races = res && res.success && Array.isArray(res.data) ? res.data : [];
      renderRaceGrid(state.races, '#tw-race-grid', 'race');
    });
  }

  function loadSubraces(parentId, parentName) {
    var section = q('#tw-subrace-section', root);
    var grid    = q('#tw-subrace-grid', root);

    state.subrace       = '';
    state.subrace_label = '';
    state.subraces      = [];

    if (grid) grid.innerHTML = '';

    if (!parentId && !parentName) {
      if (section) {
        section.hidden        = true;
        section.style.display = 'none';
        section.classList.remove('is-visible');
        section.classList.add('is-hidden');
      }
      return Promise.resolve([]);
    }

    if (grid) grid.innerHTML = '<p class="tw-loading-state"><span class="tw-loading-dot"></span><span>Loading subraces…</span></p>';
    if (section) {
      section.hidden        = false;
      section.style.display = 'block';
      section.classList.remove('is-hidden');
      section.classList.add('is-visible');
    }

    function applyRows(rows) {
      state.subraces = Array.isArray(rows) ? rows : [];

      if (!state.subraces.length) {
        if (section) {
          section.hidden        = true;
          section.style.display = 'none';
          section.classList.remove('is-visible');
          section.classList.add('is-hidden');
        }
        if (grid) grid.innerHTML = '';
        return [];
      }

      if (section) {
        section.hidden        = false;
        section.style.display = 'block';
        section.classList.remove('is-hidden');
        section.classList.add('is-visible');
      }

      renderRaceGrid(state.subraces, '#tw-subrace-grid', 'subrace');
      return state.subraces;
    }

    function fetchByParent(parentValue) {
      if (!parentValue) return Promise.resolve([]);
      return fetchPost('neoweaver_get_subraces', { parent: parentValue })
        .then(function (res) { return res && res.success && Array.isArray(res.data) ? res.data : []; })
        .catch(function () { return []; });
    }

    return fetchByParent(parentId)
      .then(function (rows) {
        if (Array.isArray(rows) && rows.length) return applyRows(rows);
        return fetchByParent(parentName).then(applyRows);
      })
      .then(function (rows) {
        if (!rows.length) setStatus('', '');
        return rows;
      })
      .catch(function () {
        state.subraces = [];
        if (grid) grid.innerHTML = '<p class="tw-error-msg">Could not load subraces.</p>';
        setStatus('Could not load subraces.', 'error');
        return [];
      });
  }

  function loadClasses() {
    return fetchPost('neoweaver_get_classes', {}).then(function (res) {
      state.classes = res && res.success && Array.isArray(res.data) ? res.data : [];
      renderClassGrid(state.classes);
    });
  }

  function loadSkills() {
    return fetchPost('neoweaver_get_skills', {}).then(function (res) {
      state.skills_data = res && res.success && Array.isArray(res.data) ? res.data : [];
      renderSkills(state.skills_data);
    });
  }

function loadPackages(classId) {
  if (!classId) { state.packages = []; renderPackages([]); return Promise.resolve([]); }

 return fetchPost('neoweaver_get_starting_packages', { class_name: state.class_label.toLowerCase() })
    .then(function (res) {
      var rows = res && res.success && Array.isArray(res.data) ? res.data : [];
      state.packages = rows;
      renderPackages(state.packages);
      return rows;
    })
    .catch(function () {
      state.packages = [];
      renderPackages([]);
      setStatus('Could not load starting packages.', 'error');
      return [];
    });
}

  // ─── Lore section init ────────────────────────────────────────────────────────
  function initLoreSections() {
    renderLoreOptions('#tw-origin-grid',    DATA_ORIGIN_OPTIONS,        'data_origin');
    renderLoreOptions('#tw-operation-grid', PREVIOUS_OPERATION_OPTIONS, 'previous_operation');
    renderLoreOptions('#tw-crisis-grid',    SYNC_CRISIS_OPTIONS,        'sync_crisis');
  }

  // ─── Init ─────────────────────────────────────────────────────────────────────
  function init() {
    // BUG FIX: original code had `var isInitialized` declared twice — once at
    // the top of the IIFE and again just before init(). The second declaration
    // reset it to `false` on every script evaluation, making the guard useless.
    // The single declaration at the top of the IIFE is now the only one.
    if (isInitialized) return;
    isInitialized = true;

    // Also expose on window so other scripts can check
    window.twCharCreatorInitialized = true;

    root    = document.getElementById('tw-char-creator-wrapper');
    if (!root) return;

    stepEls = qa('.tw-char-step', root);
    if (!stepEls.length) return;

    stepEls.forEach(function (step, index) {
      var active         = index === 0;
      step.classList.toggle('active', active);
      step.hidden        = !active;
      step.style.display = active ? 'block' : 'none';
    });

    clearStepErrors();
    bindStaticEvents();
    initLoreSections();
    renderAvatarGallery();
    updateAvatarPreview();
    updateAttrUI();
    updateSkillCounter();
    updateStepUI();
    toggleCustomPronouns();

    Promise.all([loadRaces(), loadClasses(), loadSkills()]).catch(function () {
      setStatus('Some creator data could not be loaded.', 'error');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
