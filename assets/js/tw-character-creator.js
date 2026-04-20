(function () {
  'use strict';

  var NW_SFX = (function () {
    var ctx = null;
    function getCtx() {
      if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
      return ctx;
    }
    function beep(freq, type, duration, vol) {
      try {
        var ac = getCtx();
        var osc = ac.createOscillator();
        var gain = ac.createGain();
        osc.type = type || 'sine';
        osc.frequency.value = freq || 440;
        gain.gain.setValueAtTime(vol || 0.15, ac.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + (duration || 0.08));
        osc.connect(gain);
        gain.connect(ac.destination);
        osc.start();
        osc.stop(ac.currentTime + (duration || 0.08));
      } catch (e) {}
    }
    return {
      nav: function () { beep(660, 'square', 0.06, 0.15); },
      select: function () { beep(880, 'sine', 0.10, 0.20); },
      back: function () { beep(330, 'sawtooth', 0.08, 0.12); },
      deploy: function () { beep(440, 'square', 0.10, 0.20); setTimeout(function(){ beep(660, 'sine', 0.15, 0.25); }, 120); },
      error: function () { beep(180, 'sawtooth', 0.18, 0.20); },
      preset: function () { beep(740, 'sine', 0.12, 0.22); }
    };
  })();

  function makeSpinner(id, title, subtitle) {
    var existing = document.getElementById(id);
    if (existing) {
      return {
        show: function () { existing.classList.add('active'); },
        hide: function () { existing.classList.remove('active'); }
      };
    }
    var el = document.createElement('div');
    el.id = id;
    el.innerHTML = '' +
      '<div class="tw-spinner-inner">' +
        '<div class="tw-spinner-ring"></div>' +
        '<div class="tw-spinner-ring tw-spinner-ring--2"></div>' +
        '<p class="tw-spinner-text">' + esc(title) + '</p>' +
        '<p class="tw-spinner-sub">' + esc(subtitle) + '</p>' +
      '</div>';
    document.body.appendChild(el);
    return {
      show: function () { el.classList.add('active'); },
      hide: function () { el.classList.remove('active'); }
    };
  }

  var ATTR_KEYS = ['body', 'reflex', 'mind', 'spirit'];
  var ATTR_MIN = 1;
  var ATTR_MAX = 5;
  var ATTR_POOL = 12;
  var cfg = window.twCharCreatorConfig || window.neoweaverAjax || {};
  var RACES_FALLBACK = [];

  var DATA_ORIGIN_OPTIONS = [
    {
      key: 'palace',
      label: 'Palace',
      desc: 'Your consciousness was stabilized among luxury systems, court protocols, and prototype-grade environments.',
      bonus_tag: 'Wealthy',
      bonus_desc: '+100 Credits at initialization.',
      flaw_tag: 'Fragile-Gear',
      flaw_desc: 'Base Durability of starting gear -2; using expensive but delicate prototypes.'
    },
    {
      key: 'slums',
      label: 'Slums',
      desc: 'Your core pattern held together in the noise of city rubble, scarcity, and improvised survival.',
      bonus_tag: 'Street-Smart',
      bonus_desc: 'Reveal hidden mechanics in locations tagged #city or #shady.',
      flaw_tag: 'Malnourished',
      flaw_desc: 'Max Satiety -2.'
    },
    {
      key: 'void-labs',
      label: 'Void Labs',
      desc: 'Your consciousness was first stabilized in isolated research arrays and experimental sync chambers.',
      bonus_tag: 'Fast-Sync',
      bonus_desc: 'Resting recovers +2 additional Sync.',
      flaw_tag: 'Social-Glitch',
      flaw_desc: '-10% success rate on Social actions vs #human targets.'
    },
    {
      key: 'borderlines',
      label: 'Borderlines',
      desc: 'Your first stable thoughts formed on the edge of mapped zones, between signal, wasteland, and frontier.',
      bonus_tag: 'Scout',
      bonus_desc: 'Travel between nodes consumes -1 Satiety.',
      flaw_tag: 'Analog-Mind',
      flaw_desc: 'Cannot use #Digital cards during the first 3 turns of a Deployment.'
    }
  ];

  var PREVIOUS_OPERATION_OPTIONS = [
    {
      key: 'repair-unit',
      label: '[REPAIR UNIT]',
      desc: 'You were built to restore, patch, and keep fractured systems functional under pressure.',
      bonus_tag: 'Technician',
      bonus_desc: 'Utility items restore +50% more Durability.',
      flaw_tag: 'Heavy-Handed',
      flaw_desc: '-5% success rate on Acrobatics and Stealth tests.'
    },
    {
      key: 'void-runner',
      label: '[VOID-RUNNER]',
      desc: 'Your primary function was speed, transit, and surviving dangerous movement through unstable space.',
      bonus_tag: 'Agile',
      bonus_desc: 'Playing a Dodge card allows drawing an extra card on the next turn.',
      flaw_tag: 'Light-Frame',
      flaw_desc: 'Starting Max HP -1.'
    },
    {
      key: 'archive-analyst',
      label: '[ARCHIVE ANALYST]',
      desc: 'You processed forbidden knowledge, recovered fragmented data, and interpreted arcane or scientific records.',
      bonus_tag: 'Researcher',
      bonus_desc: '+5% success rate on Arcana and Science tests.',
      flaw_tag: 'Code-Bound',
      flaw_desc: 'Cannot equip two-handed weapons.'
    },
    {
      key: 'enforcer',
      label: '[ENFORCER]',
      desc: 'You existed to apply force, hold the line, and suppress escalation when systems failed.',
      bonus_tag: 'Unyielding',
      bonus_desc: 'Ignore the first Pressure or Panic card encountered in every combat.',
      flaw_tag: 'Loud-Footsteps',
      flaw_desc: 'Cannot obtain "First Strike" bonus from stealth.'
    }
  ];

  var SYNC_CRISIS_OPTIONS = [
    {
      key: 'system-stabilizer',
      label: '[SYSTEM STABILIZER]',
      desc: 'You answered the first touch of Entropy by reinforcing the pattern and learning from the breach.',
      bonus_tag: 'Glitch-Learner',
      bonus_desc: '+10% global XP gain.',
      flaw_tag: 'System-Spasm',
      flaw_desc: 'Every 10 turns, one random card from your hand is discarded/burned.'
    },
    {
      key: 'aggressive-response',
      label: '[AGGRESSIVE RESPONSE]',
      desc: 'You met the Fray by pushing back harder, turning survival into pressure and violence.',
      bonus_tag: 'Striker',
      bonus_desc: 'Every played Attack card generates +1 additional XP for itself.',
      flaw_tag: 'Reckless',
      flaw_desc: 'On failure in a Physical test, lose an additional 1 Durability on armor.'
    },
    {
      key: 'data-ghost-adaptation',
      label: '[DATA-GHOST ADAPTATION]',
      desc: 'You adapted by becoming difficult to hold, half-solid in action and difficult to disrupt.',
      bonus_tag: 'Iron-Grip',
      bonus_desc: 'Your physical attack cards cannot be countered.',
      flaw_tag: 'Feedback-Vulnerability',
      flaw_desc: 'Receive double damage from enemies with the #Hacker or #Digital tag.'
    },
    {
      key: 'sensory-overload',
      label: '[SENSORY OVERLOAD]',
      desc: 'You survived by embracing the flood of input, turning collapse into unstable power.',
      bonus_tag: 'Wild-Card',
      bonus_desc: 'Critical successes deal triple damage instead of double.',
      flaw_tag: 'Magnetized',
      flaw_desc: 'In locations tagged #High-Technology, suffer -5% to all tests.'
    }
  ];

  var formState = {
    character_name: '',
    pronouns: '',
    backstory: '',
    race: '',
    subrace: '',
    race_label: '',
    subrace_label: '',
    character_class: '',
    class_label: '',
    avatar_file: null,
    bio: '',
    attr_body: ATTR_MIN,
    attr_reflex: ATTR_MIN,
    attr_mind: ATTR_MIN,
    attr_spirit: ATTR_MIN,
    skills: [],
    skill_limit: 5,
    starting_package_id: '',
    starting_package_label: '',
    data_origin: '',
    previous_operation: '',
    sync_crisis: '',
    backstory_tags: []
  };

  function esc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function ajaxUrl() { return cfg.ajaxurl || '/wp-admin/admin-ajax.php'; }
  function nonce() { return cfg.nonce || ''; }

  function setStatus(msg, isError) {
    var el = document.querySelector('#tw-char-status-msg');
    if (!el) return;
    el.textContent = msg || '';
    el.className = 'tw-char-status' + (isError ? ' tw-char-status--error' : '');
  }

  function showStepError(stepEl, msg) {
    if (!stepEl) return;
    var errEl = stepEl.querySelector('.tw-step-error');
    if (!errEl) {
      errEl = document.createElement('div');
      errEl.className = 'tw-step-error';
      var navRow = stepEl.querySelector('.tw-nav-row');
      if (navRow) stepEl.insertBefore(errEl, navRow);
      else stepEl.appendChild(errEl);
    }
    errEl.innerHTML = '<span class="tw-step-error__icon">⚠</span><span class="tw-step-error__msg">' + esc(msg) + '</span>';
    errEl.classList.add('visible');
    errEl.classList.remove('tw-step-error--shake');
    void errEl.offsetWidth;
    errEl.classList.add('tw-step-error--shake');
    errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    NW_SFX.error();
  }

  function clearStepError(stepEl) {
    if (!stepEl) return;
    var errEl = stepEl.querySelector('.tw-step-error');
    if (errEl) errEl.classList.remove('visible', 'tw-step-error--shake');
  }

  function buildTagsHtml(tags) {
    if (!tags || !tags.length) return '';
    var items = tags.slice(0, 6).map(function (t) {
      var label = typeof t === 'string' ? t : (t && (t.name || t.slug || t.label)) || '';
      return label ? '<span class="tw-race-tag">' + esc(label) + '</span>' : '';
    }).join('');
    return items ? '<div class="tw-race-tags">' + items + '</div>' : '';
  }

  function buildLoreChoiceCard(item, kind) {
    return '' +
      '<div class="tw-lore-card tw-grid-card" data-choice-type="' + esc(kind) + '" data-choice-key="' + esc(item.key) + '" data-label="' + esc(item.label) + '" data-bonus-tag="' + esc(item.bonus_tag) + '" data-flaw-tag="' + esc(item.flaw_tag) + '" role="button" tabindex="0" aria-pressed="false">' +
        '<div class="tw-race-body">' +
          '<h4 class="tw-race-name">' + esc(item.label) + '</h4>' +
          '<p class="tw-race-desc">' + esc(item.desc || '') + '</p>' +
          '<div class="tw-lore-card__effects">' +
            '<div class="tw-lore-card__effect"><strong>BONUS:</strong> ' + esc(item.bonus_tag) + ' — ' + esc(item.bonus_desc || '') + '</div>' +
            '<div class="tw-lore-card__effect"><strong>FLAW:</strong> ' + esc(item.flaw_tag) + ' — ' + esc(item.flaw_desc || '') + '</div>' +
          '</div>' +
          buildTagsHtml([item.bonus_tag, item.flaw_tag]) +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function renderLoreChoices(wrapper) {
    var originGrid = wrapper.querySelector('#tw-origin-grid');
    var operationGrid = wrapper.querySelector('#tw-operation-grid');
    var crisisGrid = wrapper.querySelector('#tw-crisis-grid');
    if (originGrid && !originGrid.dataset.rendered) {
      originGrid.innerHTML = DATA_ORIGIN_OPTIONS.map(function (item) { return buildLoreChoiceCard(item, 'data_origin'); }).join('');
      originGrid.dataset.rendered = '1';
    }
    if (operationGrid && !operationGrid.dataset.rendered) {
      operationGrid.innerHTML = PREVIOUS_OPERATION_OPTIONS.map(function (item) { return buildLoreChoiceCard(item, 'previous_operation'); }).join('');
      operationGrid.dataset.rendered = '1';
    }
    if (crisisGrid && !crisisGrid.dataset.rendered) {
      crisisGrid.innerHTML = SYNC_CRISIS_OPTIONS.map(function (item) { return buildLoreChoiceCard(item, 'sync_crisis'); }).join('');
      crisisGrid.dataset.rendered = '1';
    }
  }

  function choiceByKey(kind, key) {
    var set = [];
    if (kind === 'data_origin') set = DATA_ORIGIN_OPTIONS;
    else if (kind === 'previous_operation') set = PREVIOUS_OPERATION_OPTIONS;
    else if (kind === 'sync_crisis') set = SYNC_CRISIS_OPTIONS;
    for (var i = 0; i < set.length; i++) {
      if (set[i].key === key) return set[i];
    }
    return null;
  }

  function recomputeBackstoryTags() {
    var tags = [];
    ['data_origin', 'previous_operation', 'sync_crisis'].forEach(function (kind) {
      var row = choiceByKey(kind, formState[kind]);
      if (row) {
        if (row.bonus_tag) tags.push(row.bonus_tag);
        if (row.flaw_tag) tags.push(row.flaw_tag);
      }
    });
    formState.backstory_tags = tags;
  }

  function buildRaceCard(race) {
    var imgSrc = race.img || race.img_url || '';
    var imgHtml = imgSrc
      ? '<div class="tw-race-img"><img src="' + esc(imgSrc) + '" alt="' + esc(race.label || race.name) + '" width="220" height="220" loading="lazy"></div>'
      : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">✦</span></div>';
    return '<div class="tw-grid-card tw-race-card" data-race="' + esc(race.id || race.key || race.name) + '" role="button" tabindex="0" aria-pressed="false">' +
      imgHtml +
      '<div class="tw-race-body">' +
        '<h4 class="tw-race-name">' + esc(race.label || race.name) + '</h4>' +
        '<p class="tw-race-desc">' + esc(race.desc || race.description || '') + '</p>' +
        ((race.bonus) ? '<span class="tw-race-bonus">' + esc(race.bonus) + '</span>' : '') +
        buildTagsHtml(race.tags || []) +
        '<span class="tw-race-select-hint">select</span>' +
      '</div>' +
    '</div>';
  }

  function buildSubraceCard(sub) {
    var imgSrc = sub.img || sub.img_url || '';
    var imgHtml = imgSrc
      ? '<div class="tw-race-img"><img src="' + esc(imgSrc) + '" alt="' + esc(sub.label || sub.name) + '" width="220" height="220" loading="lazy"></div>'
      : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">✦</span></div>';
    return '<div class="tw-grid-card tw-race-card tw-subrace-card" data-subrace="' + esc(sub.id || sub.key || sub.name) + '" role="button" tabindex="0" aria-pressed="false">' +
      imgHtml +
      '<div class="tw-race-body">' +
        '<h4 class="tw-race-name">' + esc(sub.label || sub.name) + '</h4>' +
        '<p class="tw-race-desc">' + esc(sub.desc || sub.description || '') + '</p>' +
        buildTagsHtml(sub.tags || []) +
        '<span class="tw-race-select-hint">select</span>' +
      '</div>' +
    '</div>';
  }

  function buildClassCard(cls) {
    var imgSrc = cls.img_url || cls.imgurl || '';
    var imgHtml = imgSrc
      ? '<div class="tw-class-card__img-wrap"><img src="' + esc(imgSrc) + '" alt="' + esc(cls.name) + '" width="220" height="220" loading="lazy"></div>'
      : '<div class="tw-class-card__img-wrap tw-class-card__img-wrap--placeholder"><span>' + esc(cls.icon_slug || '✦') + '</span></div>';
    return '<div class="tw-class-card" data-char-class="' + esc(cls.id || cls.name) + '" data-label="' + esc(cls.name || '') + '" data-class-tag="' + esc((cls.name || '').toLowerCase()) + '" data-skilllimit="' + esc(parseInt(cls.skill_limit, 10) || 5) + '" role="button" tabindex="0" aria-pressed="false">' +
      imgHtml +
      '<div class="tw-class-card__body">' +
        '<h4 class="tw-class-card__name">' + esc(cls.name || '') + '</h4>' +
        ((cls.description) ? '<p class="tw-class-card__desc">' + esc(cls.description) + '</p>' : '') +
        buildTagsHtml(cls.tags || []) +
        '<span class="tw-race-select-hint">select</span>' +
      '</div>' +
    '</div>';
  }

  function buildSkillCard(skill) {
    var imgSrc = skill.img_url || skill.imgurl || '';
    var tags = [].concat(skill.tags || [], skill.linked_attributes || []);
    var imgHtml = imgSrc
      ? '<div class="tw-race-img"><img src="' + esc(imgSrc) + '" alt="' + esc(skill.name) + '" width="220" height="220" loading="lazy"></div>'
      : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">✦</span></div>';
    return '<div class="tw-skill-card tw-grid-card" data-skill-id="' + esc(skill.id) + '" data-label="' + esc(skill.name || '') + '" role="button" tabindex="0" aria-pressed="false">' +
      imgHtml +
      '<div class="tw-race-body">' +
        '<h4 class="tw-race-name">' + esc(skill.name || '') + '</h4>' +
        ((skill.description) ? '<p class="tw-race-desc">' + esc(skill.description) + '</p>' : '') +
        buildTagsHtml(tags) +
        '<span class="tw-race-select-hint">select</span>' +
      '</div>' +
    '</div>';
  }

  function buildPackageCard(pkg) {
    var tags = pkg.compatibility_tags || [];
    var items = pkg.items_list || [];
    var itemsPreview = Array.isArray(items) && items.length
      ? '<div class="tw-package-items">' + items.slice(0, 5).map(function (item) {
          return '<span class="tw-race-tag">' + esc(typeof item === 'string' ? item : (item.name || item.label || 'item')) + '</span>';
        }).join('') + '</div>'
      : '';
    return '<div class="tw-package-card tw-grid-card" data-package-id="' + esc(pkg.id || '') + '" data-label="' + esc(pkg.package_name || '') + '" role="button" tabindex="0" aria-pressed="false">' +
      '<div class="tw-race-body">' +
        '<h4 class="tw-race-name">' + esc(pkg.package_name || '') + '</h4>' +
        ((pkg.description) ? '<p class="tw-race-desc">' + esc(pkg.description) + '</p>' : '') +
        ((pkg.base_armor != null) ? '<span class="tw-race-bonus">Armor ' + esc(pkg.base_armor) + '</span>' : '') +
        buildTagsHtml(tags) +
        itemsPreview +
        '<span class="tw-race-select-hint">select</span>' +
      '</div>' +
    '</div>';
  }

  function fetchPost(action, extraData) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', nonce());
    Object.keys(extraData || {}).forEach(function (key) { fd.append(key, extraData[key]); });
    return fetch(ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
  }

  function fetchRaceGrid(wrapper) {
    var grid = wrapper.querySelector('#tw-race-grid');
    if (!grid || grid.dataset.rendered) return;
    grid.innerHTML = '<p class="tw-loading">SCANNING RACE DATABASE…</p>';
    fetchPost('neoweaver_get_races', {})
      .then(function (res) {
        var rows = (res && res.success && res.data && res.data.length) ? res.data : RACES_FALLBACK;
        grid.innerHTML = rows.length ? rows.map(buildRaceCard).join('') : '<p class="tw-empty-state">No races available.</p>';
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = RACES_FALLBACK.length ? RACES_FALLBACK.map(buildRaceCard).join('') : '<p class="tw-error-msg">ERROR: Race data unavailable.</p>';
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      });
  }

  function fetchSubraces(wrapper, raceKey) {
    var section = wrapper.querySelector('#tw-subrace-section');
    var grid = wrapper.querySelector('#tw-subrace-grid');
    if (!section || !grid || !raceKey) return;
    section.style.display = '';
    grid.innerHTML = '<p class="tw-loading">SCANNING SUBRACE DATA…</p>';
    fetchPost('neoweaver_get_subraces', { parent: raceKey })
      .then(function (res) {
        if (res && res.success && res.data && res.data.length) {
          grid.innerHTML = res.data.map(buildSubraceCard).join('');
          section.style.display = '';
        } else {
          grid.innerHTML = '';
          section.style.display = 'none';
        }
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '';
        section.style.display = 'none';
      });
  }

  function fetchClassGrid(wrapper) {
    var grid = wrapper.querySelector('#tw-class-grid');
    if (!grid || grid.dataset.rendered) return;
    grid.innerHTML = '<div class="tw-loading-state"><div class="tw-loading-dot"></div>SCANNING CLASS MATRIX…</div>';
    fetchPost('neoweaver_get_classes', {})
      .then(function (res) {
        if (res && res.success && res.data && res.data.length) grid.innerHTML = res.data.map(buildClassCard).join('');
        else grid.innerHTML = '<p class="tw-empty-state">No classes available.</p>';
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<p class="tw-error-msg">ERROR: Class data unavailable.</p>';
      });
  }

  function updateSkillCounter(wrapper) {
    var counter = wrapper.querySelector('#tw-skill-counter');
    if (counter) counter.textContent = formState.skills.length + ' / ' + (formState.skill_limit || 5);
  }

  function fetchSkillGrid(wrapper) {
    var grid = wrapper.querySelector('#tw-skill-grid');
    if (!grid || grid.dataset.rendered) { updateSkillCounter(wrapper); restoreSelections(wrapper); return; }
    grid.innerHTML = '<div class="tw-loading-state"><div class="tw-loading-dot"></div>SCANNING SKILL DATABASE…</div>';
    fetchPost('neoweaver_get_skills', {})
      .then(function (res) {
        if (res && res.success && res.data && res.data.length) {
          var categories = {};
          res.data.forEach(function (skill) {
            var cat = skill.category || 'Other';
            if (!categories[cat]) categories[cat] = [];
            categories[cat].push(skill);
          });
          var html = '';
          Object.keys(categories).forEach(function (cat) {
            html += '<div class="tw-skill-category"><h4 class="tw-skill-cat-label">' + esc(cat) + '</h4><div class="tw-skill-cat-grid">';
            categories[cat].forEach(function (skill) { html += buildSkillCard(skill); });
            html += '</div></div>';
          });
          grid.innerHTML = html;
        } else {
          grid.innerHTML = '<p class="tw-empty-state">No skills available.</p>';
        }
        grid.dataset.rendered = '1';
        updateSkillCounter(wrapper);
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<p class="tw-error-msg">ERROR: Skill data unavailable.</p>';
      });
  }

  function selectedClassTag(wrapper) {
    if (!formState.class_label) return '';
    var selected = wrapper.querySelector('.tw-class-card.selected');
    if (selected && selected.dataset.classTag) return String(selected.dataset.classTag).trim().toLowerCase();
    return String(formState.class_label).trim().toLowerCase();
  }

  function fetchPackageGrid(wrapper) {
    var grid = wrapper.querySelector('#tw-package-grid');
    if (!grid) return;
    var classTag = selectedClassTag(wrapper);
    if (!classTag) {
      grid.innerHTML = '<p class="tw-empty-state">Select a class first.</p>';
      return;
    }
    grid.innerHTML = '<div class="tw-loading-state"><div class="tw-loading-dot"></div>FETCHING STARTING PACKAGES…</div>';
    fetchPost('neoweaver_get_starting_packages', { class_tag: classTag })
      .then(function (res) {
        if (res && res.success && res.data && res.data.length) grid.innerHTML = res.data.map(buildPackageCard).join('');
        else grid.innerHTML = '<p class="tw-empty-state">No starting packages available for this class.</p>';
        grid.dataset.rendered = classTag;
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<p class="tw-error-msg">ERROR: Starting packages unavailable.</p>';
      });
  }

  function renderAttrDisplay(wrapper) {
    ATTR_KEYS.forEach(function (key) {
      var val = formState['attr_' + key] || ATTR_MIN;
      var inputEl = wrapper.querySelector('#tw-attr-' + key);
      if (inputEl) inputEl.value = val;
      var pips = wrapper.querySelectorAll('[data-attr="' + key + '"] .tw-pip');
      for (var i = 0; i < pips.length; i++) pips[i].classList.toggle('active', parseInt(pips[i].dataset.pip, 10) <= val);
    });
    var used = ATTR_KEYS.reduce(function (sum, k) { return sum + (formState['attr_' + k] || ATTR_MIN); }, 0);
    var remainEl = wrapper.querySelector('#tw-attr-remaining');
    if (remainEl) remainEl.textContent = ATTR_POOL - used;
  }

  function applyAttrPreset(wrapper, presetBtn) {
    var valid = true;
    ATTR_KEYS.forEach(function (k) {
      var v = parseInt(presetBtn.dataset[k], 10);
      if (isNaN(v) || v < ATTR_MIN || v > ATTR_MAX) valid = false;
    });
    if (!valid) return;
    ATTR_KEYS.forEach(function (k) { formState['attr_' + k] = parseInt(presetBtn.dataset[k], 10); });
    var allPresets = wrapper.querySelectorAll('.tw-attr-preset-btn');
    for (var i = 0; i < allPresets.length; i++) allPresets[i].classList.toggle('active', allPresets[i] === presetBtn);
    renderAttrDisplay(wrapper);
    NW_SFX.preset();
  }

  function handleAvatarFile(wrapper, file) {
    var allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!file || allowed.indexOf(file.type) === -1 || file.size > 2 * 1024 * 1024) {
      setStatus('ERROR: Invalid file. JPG / PNG / WEBP under 2 MB only.', true);
      NW_SFX.error();
      return;
    }
    formState.avatar_file = file;
    NW_SFX.select();
    var reader = new FileReader();
    reader.onload = function (ev) {
      var imgEl = wrapper.querySelector('#tw-avatar-img');
      var preview = wrapper.querySelector('#tw-avatar-preview');
      var selected = wrapper.querySelector('#tw-avatar-selected');
      if (imgEl) imgEl.src = ev.target.result;
      if (preview) preview.style.display = 'none';
      if (selected) selected.style.display = '';
    };
    reader.readAsDataURL(file);
  }

  function updateSummary(wrapper) {
    function set(id, val) {
      var el = wrapper.querySelector('#tw-summary-' + id);
      if (el) el.textContent = val || '—';
    }
    set('character-name', formState.character_name);
    set('pronouns', formState.pronouns);
    set('backstory', formState.backstory ? (formState.backstory.length > 80 ? formState.backstory.substring(0, 80) + '…' : formState.backstory) : '—');
    set('race', [formState.race_label, formState.subrace_label].filter(Boolean).join(' / ') || formState.race || '—');
    set('class', formState.class_label || formState.character_class || '—');
    set('attrs', ATTR_KEYS.map(function (k) { return k.toUpperCase() + ' ' + formState['attr_' + k]; }).join(' · '));
    set('skills', formState.skills.length ? (formState.skills.length + ' selected') : '—');
    set('package', formState.starting_package_label || '—');
    var origin = choiceByKey('data_origin', formState.data_origin);
    var operation = choiceByKey('previous_operation', formState.previous_operation);
    var crisis = choiceByKey('sync_crisis', formState.sync_crisis);
    set('origin', origin ? origin.label : '—');
    set('operation', operation ? operation.label : '—');
    set('crisis', crisis ? crisis.label : '—');
    set('tag-bundle', formState.backstory_tags.length ? formState.backstory_tags.join(' · ') : '—');
    set('bio', formState.bio ? (formState.bio.length > 80 ? formState.bio.substring(0, 80) + '…' : formState.bio) : '—');
    var avatarEl = wrapper.querySelector('#tw-summary-avatar');
    if (avatarEl) avatarEl.textContent = formState.avatar_file ? formState.avatar_file.name : '—';
  }

  function submitCharacter(wrapper, steps, current, spinner) {
    setStatus('Uploading agent profile…', false);
    var submitBtn = wrapper.querySelector('#tw-char-submit');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = '⏳ SYNCHRONIZING…'; }
    spinner.show();

    var data = new FormData();
    data.append('action', 'neoweaver_create_character');
    data.append('nonce', nonce());
    data.append('character_name', formState.character_name);
    data.append('pronouns', formState.pronouns);
    data.append('backstory', formState.backstory);
    data.append('bio', formState.bio);
    data.append('race', formState.race);
    data.append('subrace', formState.subrace);
    data.append('char_class', formState.character_class);
    data.append('starting_package_id', formState.starting_package_id);
    data.append('skills', JSON.stringify(formState.skills));
    data.append('data_origin', formState.data_origin);
    data.append('previous_operation', formState.previous_operation);
    data.append('sync_crisis', formState.sync_crisis);
    data.append('backstory_tags', JSON.stringify(formState.backstory_tags));
    ATTR_KEYS.forEach(function (k) { data.append('attr_' + k, formState['attr_' + k]); });
    if (formState.avatar_file) data.append('avatar', formState.avatar_file);

    var t0 = Date.now();
    fetch(ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var wait = Math.max(0, 1200 - (Date.now() - t0));
        setTimeout(function () {
          spinner.hide();
          if (res && res.success) {
            setStatus('Agent profile created. Welcome to the Grid.', false);
            wrapper.innerHTML = '<div class="tw-success"><p class="tw-success__msg">✓ ' + esc((res.data && res.data.message) || 'Character created!') + '</p>' + (((res.data && res.data.redirect)) ? '<a href="' + esc(res.data.redirect) + '" class="tw-btn tw-btn--primary">Enter the Grid</a>' : '') + '</div>';
          } else {
            var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Submission failed. Retry.';
            setStatus('ERROR: ' + errMsg, true);
            showStepError(steps[current], errMsg);
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '⌘ SYNCHRONIZE AGENT'; }
          }
        }, wait);
      })
      .catch(function () {
        spinner.hide();
        setStatus('ERROR: Connection lost. Check your link and retry.', true);
        NW_SFX.error();
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '⌘ SYNCHRONIZE AGENT'; }
      });
  }

  function restoreSelections(wrapper) {
    var i, cards, isMatch;

    cards = wrapper.querySelectorAll('.tw-race-card:not(.tw-subrace-card)');
    for (i = 0; i < cards.length; i++) {
      isMatch = !!formState.race && cards[i].dataset.race === formState.race;
      cards[i].classList.toggle('selected', isMatch);
      cards[i].setAttribute('aria-pressed', isMatch ? 'true' : 'false');
    }

    cards = wrapper.querySelectorAll('.tw-subrace-card');
    for (i = 0; i < cards.length; i++) {
      isMatch = !!formState.subrace && cards[i].dataset.subrace === formState.subrace;
      cards[i].classList.toggle('selected', isMatch);
      cards[i].setAttribute('aria-pressed', isMatch ? 'true' : 'false');
    }

    cards = wrapper.querySelectorAll('.tw-class-card');
    for (i = 0; i < cards.length; i++) {
      isMatch = !!formState.character_class && String(cards[i].dataset.charClass) === String(formState.character_class);
      cards[i].classList.toggle('selected', isMatch);
      cards[i].setAttribute('aria-pressed', isMatch ? 'true' : 'false');
    }

    cards = wrapper.querySelectorAll('.tw-skill-card');
    for (i = 0; i < cards.length; i++) {
      isMatch = formState.skills.indexOf(cards[i].dataset.skillId) !== -1;
      cards[i].classList.toggle('selected', isMatch);
      cards[i].setAttribute('aria-pressed', isMatch ? 'true' : 'false');
    }

    cards = wrapper.querySelectorAll('.tw-package-card');
    for (i = 0; i < cards.length; i++) {
      isMatch = !!formState.starting_package_id && String(cards[i].dataset.packageId) === String(formState.starting_package_id);
      cards[i].classList.toggle('selected', isMatch);
      cards[i].setAttribute('aria-pressed', isMatch ? 'true' : 'false');
    }

    cards = wrapper.querySelectorAll('.tw-lore-card');
    for (i = 0; i < cards.length; i++) {
      var type = cards[i].dataset.choiceType;
      var key = cards[i].dataset.choiceKey;
      isMatch = !!type && formState[type] === key;
      cards[i].classList.toggle('selected', isMatch);
      cards[i].setAttribute('aria-pressed', isMatch ? 'true' : 'false');
    }

    updateSkillCounter(wrapper);
  }

  function init() {
    var wrapper = document.getElementById('tw-char-creator-wrapper');
    if (!wrapper || wrapper.dataset.nwInit) return;
    wrapper.dataset.nwInit = '1';

    var steps = Array.prototype.slice.call(wrapper.querySelectorAll('.tw-step'));
    var current = 0;
    if (!steps.length) return;

    var spinner = makeSpinner('tw-char-spinner', 'SYNCHRONIZING AGENT…', 'Writing operative data to the NeoWeave grid.');

    function showStep(idx) {
      steps.forEach(function (step, i) { step.classList.toggle('active', i === idx); });
      current = idx;
      setStatus('', false);
      var phase = steps[idx] ? (steps[idx].dataset.phase || '') : '';
      if (phase === 'CLASS MATRIX') fetchClassGrid(wrapper);
      if (phase === 'SKILL SELECTION') fetchSkillGrid(wrapper);
      if (phase === 'STARTING PACKAGE') fetchPackageGrid(wrapper);
      if (phase === 'DATA ORIGIN' || phase === 'PREVIOUS OPERATION' || phase === 'SYNCHRONIZATION CRISIS') renderLoreChoices(wrapper);
      if (phase === 'SYSTEM REVIEW') updateSummary(wrapper);

      var fillEl = wrapper.querySelector('#tw-char-progress-fill');
      var stepElCounter = wrapper.querySelector('#tw-char-step-current');
      var phaseEl = wrapper.querySelector('#tw-char-progress-phase');
      if (fillEl) fillEl.style.width = Math.round(((idx + 1) / steps.length) * 100) + '%';
      if (stepElCounter) stepElCounter.textContent = idx + 1;
      if (phaseEl) phaseEl.textContent = phase;

      var ticks = wrapper.querySelectorAll('.tw-progress-tick');
      for (var t = 0; t < ticks.length; t++) ticks[t].classList.toggle('active', parseInt(ticks[t].dataset.tick, 10) <= idx + 1);
      restoreSelections(wrapper);
    }

    function validateStep(idx) {
      var step = steps[idx];
      if (!step) return true;
      clearStepError(step);

      if (idx === 0) {
        var nameInput = wrapper.querySelector('#tw-char-name');
        if (!nameInput || !nameInput.value.trim()) {
          if (nameInput) nameInput.focus();
          showStepError(step, 'ERROR: Agent designation is required.');
          setStatus('ERROR: Agent designation is required.', true);
          return false;
        }
        formState.character_name = nameInput.value.trim();
        var checkedRadio = wrapper.querySelector('.tw-pronoun-radio:checked');
        if (checkedRadio) {
          if (checkedRadio.value === 'custom') {
            var customEl = wrapper.querySelector('#tw-char-pronouns-custom');
            formState.pronouns = customEl && customEl.value.trim() ? customEl.value.trim() : 'custom';
          } else formState.pronouns = checkedRadio.value;
        }
        var backstoryEl = wrapper.querySelector('#tw-char-backstory');
        formState.backstory = backstoryEl ? backstoryEl.value : '';
        return true;
      }

      if (step.dataset.phase === 'RACE PROTOCOL') {
        if (!formState.race) {
          showStepError(step, 'ERROR: Select a race to continue.');
          setStatus('ERROR: Select a race to continue.', true);
          return false;
        }
        return true;
      }

      if (step.dataset.phase === 'CLASS MATRIX') {
        if (!formState.character_class) {
          showStepError(step, 'ERROR: Select a class to continue.');
          setStatus('ERROR: Select a class to continue.', true);
          return false;
        }
        return true;
      }

      if (step.dataset.phase === 'BIOMETRIC CALIBRATION') {
        var used = ATTR_KEYS.reduce(function (sum, k) { return sum + (formState['attr_' + k] || ATTR_MIN); }, 0);
        if (used !== ATTR_POOL) {
          showStepError(step, 'ERROR: Distribute all ' + ATTR_POOL + ' attribute points.');
          setStatus('ERROR: Distribute all ' + ATTR_POOL + ' attribute points.', true);
          return false;
        }
        return true;
      }

      if (step.dataset.phase === 'SKILL SELECTION') {
        if (!formState.skills.length) {
          showStepError(step, 'ERROR: Select at least 1 skill.');
          setStatus('ERROR: Select at least 1 skill.', true);
          return false;
        }
        if (formState.skills.length > (formState.skill_limit || 5)) {
          showStepError(step, 'ERROR: Too many skills selected for this class.');
          setStatus('ERROR: Too many skills selected for this class.', true);
          return false;
        }
        return true;
      }

      if (step.dataset.phase === 'STARTING PACKAGE') {
        if (!formState.starting_package_id) {
          showStepError(step, 'ERROR: Select a starting package to continue.');
          setStatus('ERROR: Select a starting package to continue.', true);
          return false;
        }
        return true;
      }

      if (step.dataset.phase === 'DATA ORIGIN') {
        if (!formState.data_origin) {
          showStepError(step, 'ERROR: Select a data origin to continue.');
          return false;
        }
        return true;
      }

      if (step.dataset.phase === 'PREVIOUS OPERATION') {
        if (!formState.previous_operation) {
          showStepError(step, 'ERROR: Select a previous operation to continue.');
          return false;
        }
        return true;
      }

      if (step.dataset.phase === 'SYNCHRONIZATION CRISIS') {
        if (!formState.sync_crisis) {
          showStepError(step, 'ERROR: Select a synchronization crisis response to continue.');
          return false;
        }
        recomputeBackstoryTags();
        return true;
      }

      if (step.dataset.phase === 'VISUAL SIGNATURE') {
        var bioEl = wrapper.querySelector('#tw-char-bio');
        formState.bio = bioEl ? bioEl.value.trim() : '';
        return true;
      }

      return true;
    }

    function goNext() {
      if (validateStep(current)) {
        clearStepError(steps[current]);
        setStatus('', false);
        if (current < steps.length - 1) {
          NW_SFX.nav();
          showStep(current + 1);
        }
      }
    }

    function goPrev() {
      clearStepError(steps[current]);
      setStatus('', false);
      if (current > 0) {
        NW_SFX.back();
        showStep(current - 1);
      }
    }

    fetchRaceGrid(wrapper);
    renderAttrDisplay(wrapper);
    renderLoreChoices(wrapper);

    var step1Next = wrapper.querySelector('#tw-char-step1-next');
    if (step1Next) step1Next.addEventListener('click', function (e) { e.stopPropagation(); goNext(); });

    wrapper.addEventListener('click', function (e) {
      var presetBtn = e.target.closest('.tw-attr-preset-btn');
      if (presetBtn) { applyAttrPreset(wrapper, presetBtn); clearStepError(steps[current]); return; }

      var subCard = e.target.closest('.tw-subrace-card');
      if (subCard) {
        var allSub = wrapper.querySelectorAll('.tw-subrace-card');
        for (var s = 0; s < allSub.length; s++) { allSub[s].classList.remove('selected'); allSub[s].setAttribute('aria-pressed', 'false'); }
        subCard.classList.add('selected');
        subCard.setAttribute('aria-pressed', 'true');
        formState.subrace = subCard.dataset.subrace;
        formState.subrace_label = (subCard.querySelector('.tw-race-name') || {}).textContent || formState.subrace;
        NW_SFX.select();
        clearStepError(steps[current]);
        return;
      }

      var raceCard = e.target.closest('.tw-race-card:not(.tw-subrace-card)');
      if (raceCard && !e.target.closest('button')) {
        var allRace = wrapper.querySelectorAll('.tw-race-card:not(.tw-subrace-card)');
        for (var r = 0; r < allRace.length; r++) { allRace[r].classList.remove('selected'); allRace[r].setAttribute('aria-pressed', 'false'); }
        raceCard.classList.add('selected');
        raceCard.setAttribute('aria-pressed', 'true');
        formState.race = raceCard.dataset.race;
        formState.race_label = (raceCard.querySelector('.tw-race-name') || {}).textContent || formState.race;
        formState.subrace = '';
        formState.subrace_label = '';
        fetchSubraces(wrapper, formState.race);
        NW_SFX.select();
        clearStepError(steps[current]);
        return;
      }

      var classCard = e.target.closest('.tw-class-card');
      if (classCard && !e.target.closest('button')) {
        var allClass = wrapper.querySelectorAll('.tw-class-card');
        for (var c = 0; c < allClass.length; c++) { allClass[c].classList.remove('selected'); allClass[c].setAttribute('aria-pressed', 'false'); }
        classCard.classList.add('selected');
        classCard.setAttribute('aria-pressed', 'true');
        formState.character_class = classCard.dataset.charClass;
        formState.class_label = classCard.dataset.label || (classCard.querySelector('.tw-class-card__name') || {}).textContent || '';
        formState.skill_limit = parseInt(classCard.dataset.skilllimit, 10) || 5;
        formState.skills = [];
        formState.starting_package_id = '';
        formState.starting_package_label = '';
        var packageGrid = wrapper.querySelector('#tw-package-grid');
        if (packageGrid) packageGrid.dataset.rendered = '';
        updateSkillCounter(wrapper);
        NW_SFX.select();
        clearStepError(steps[current]);
        return;
      }

      var skillCard = e.target.closest('.tw-skill-card');
      if (skillCard && !e.target.closest('button')) {
        var skillId = skillCard.dataset.skillId;
        var limit = formState.skill_limit || 5;
        var pos = formState.skills.indexOf(skillId);
        if (pos !== -1) {
          formState.skills.splice(pos, 1);
          skillCard.classList.remove('selected');
          skillCard.setAttribute('aria-pressed', 'false');
        } else {
          if (formState.skills.length >= limit) { showStepError(steps[current], 'ERROR: Max ' + limit + ' skills for your class.'); return; }
          formState.skills.push(skillId);
          skillCard.classList.add('selected');
          skillCard.setAttribute('aria-pressed', 'true');
        }
        updateSkillCounter(wrapper);
        clearStepError(steps[current]);
        NW_SFX.select();
        return;
      }

      var packageCard = e.target.closest('.tw-package-card');
      if (packageCard && !e.target.closest('button')) {
        var allPkg = wrapper.querySelectorAll('.tw-package-card');
        for (var p = 0; p < allPkg.length; p++) { allPkg[p].classList.remove('selected'); allPkg[p].setAttribute('aria-pressed', 'false'); }
        packageCard.classList.add('selected');
        packageCard.setAttribute('aria-pressed', 'true');
        formState.starting_package_id = packageCard.dataset.packageId;
        formState.starting_package_label = packageCard.dataset.label || (packageCard.querySelector('.tw-race-name') || {}).textContent || '';
        clearStepError(steps[current]);
        NW_SFX.select();
        return;
      }

      var loreCard = e.target.closest('.tw-lore-card');
      if (loreCard && !e.target.closest('button')) {
        var type = loreCard.dataset.choiceType;
        var allLore = wrapper.querySelectorAll('.tw-lore-card[data-choice-type="' + type + '"]');
        for (var l = 0; l < allLore.length; l++) {
          allLore[l].classList.remove('selected');
          allLore[l].setAttribute('aria-pressed', 'false');
        }
        loreCard.classList.add('selected');
        loreCard.setAttribute('aria-pressed', 'true');
        formState[type] = loreCard.dataset.choiceKey || '';
        recomputeBackstoryTags();
        clearStepError(steps[current]);
        NW_SFX.select();
        return;
      }

      var btn = e.target.closest('button');
      if (!btn) return;

      if (btn.id === 'tw-avatar-clear') {
        formState.avatar_file = null;
        var fileInput = wrapper.querySelector('#tw-char-avatar');
        if (fileInput) fileInput.value = '';
        var preview = wrapper.querySelector('#tw-avatar-preview');
        var selected = wrapper.querySelector('#tw-avatar-selected');
        if (preview) preview.style.display = '';
        if (selected) selected.style.display = 'none';
        return;
      }

      if (btn.classList.contains('tw-upload-trigger')) {
        var fi = wrapper.querySelector('#tw-char-avatar');
        if (fi) fi.click();
        return;
      }

      if (btn.classList.contains('tw-summary-edit')) {
        var goTo = parseInt(btn.dataset.goto, 10);
        if (!isNaN(goTo)) { NW_SFX.nav(); showStep(goTo - 1); }
        return;
      }

      if (btn.classList.contains('tw-attr-btn')) {
        var attrKey = btn.dataset.attr;
        var dir = btn.classList.contains('tw-attr-plus') ? 'up' : 'down';
        if (attrKey) {
          var stateKey = 'attr_' + attrKey;
          var val = formState[stateKey] || ATTR_MIN;
          var usedNow = ATTR_KEYS.reduce(function (sum, k) { return sum + (formState['attr_' + k] || ATTR_MIN); }, 0);
          if (dir === 'up' && val < ATTR_MAX && usedNow < ATTR_POOL) { formState[stateKey] = val + 1; NW_SFX.nav(); }
          else if (dir === 'down' && val > ATTR_MIN) { formState[stateKey] = val - 1; NW_SFX.back(); }
          var allPresetBtns = wrapper.querySelectorAll('.tw-attr-preset-btn');
          for (var pb = 0; pb < allPresetBtns.length; pb++) allPresetBtns[pb].classList.remove('active');
          renderAttrDisplay(wrapper);
        }
        return;
      }

      if (btn.classList.contains('tw-btn-deploy')) {
        if (validateStep(current)) submitCharacter(wrapper, steps, current, spinner);
        return;
      }
      if (btn.classList.contains('tw-btn-prev')) { goPrev(); return; }
      if (btn.classList.contains('tw-btn-next') || btn.classList.contains('tw-btn-nav')) goNext();
    });

    wrapper.addEventListener('change', function (e) {
      if (e.target && e.target.classList.contains('tw-pronoun-radio')) {
        var customInput = wrapper.querySelector('#tw-char-pronouns-custom');
        if (customInput) {
          customInput.style.display = e.target.value === 'custom' ? '' : 'none';
          if (e.target.value === 'custom') customInput.focus();
        }
      }
    });

    wrapper.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var card = e.target.closest('.tw-race-card, .tw-class-card, .tw-skill-card, .tw-package-card, .tw-lore-card');
      if (card) { e.preventDefault(); card.click(); }
    });

    var dropBox = wrapper.querySelector('#tw-avatar-drop');
    var fileInput = wrapper.querySelector('#tw-char-avatar');
    if (dropBox) {
      dropBox.addEventListener('dragover', function (e) { e.preventDefault(); dropBox.classList.add('tw-upload-box--drag'); });
      dropBox.addEventListener('dragleave', function () { dropBox.classList.remove('tw-upload-box--drag'); });
      dropBox.addEventListener('drop', function (e) {
        e.preventDefault();
        dropBox.classList.remove('tw-upload-box--drag');
        var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (file) handleAvatarFile(wrapper, file);
      });
    }
    if (fileInput) fileInput.addEventListener('change', function () { if (fileInput.files && fileInput.files[0]) handleAvatarFile(wrapper, fileInput.files[0]); });

    showStep(0);
  }

  function boot() {
    var wrapper = document.getElementById('tw-char-creator-wrapper');
    if (wrapper) init();
    else {
      var retry = 0;
      var poll = setInterval(function () {
        retry++;
        if (document.getElementById('tw-char-creator-wrapper')) { clearInterval(poll); init(); }
        else if (retry > 50) clearInterval(poll);
      }, 100);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
