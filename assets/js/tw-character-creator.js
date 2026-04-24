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
      deploy: function () {
        beep(440, 'square', 0.10, 0.20);
        setTimeout(function () { beep(660, 'sine', 0.15, 0.25); }, 120);
      },
      error: function () { beep(180, 'sawtooth', 0.18, 0.20); },
      preset: function () { beep(740, 'sine', 0.12, 0.22); }
    };
  })();

  var ATTR_KEYS = ['body', 'reflex', 'mind', 'spirit'];
  var ATTR_MIN = 1;
  var ATTR_MAX = 5;
  var ATTR_POOL = 12;

  var cfg = window.twCharCreatorConfig || window.twCharCreatorAjax || window.neoweaverAjax || {};
  var RACES_FALLBACK = [];

  var TW_SITE_BASE = 'https://neoweaver.nieodparady.pl';
  var TW_UPLOADS_BASE = TW_SITE_BASE + '/wp-content/uploads/';

  var TW_AVATAR_GALLERY = [
    {
      id: 'avatar-1',
      name: 'Avatar',
      url: TW_UPLOADS_BASE + 'Avatar.svg'
    },
    {
      id: 'avatar-2',
      name: 'Avatar 2',
      url: TW_UPLOADS_BASE + 'Avatar-1.svg'
    }
  ];

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
    race: '',
    subrace: '',
    race_label: '',
    subrace_label: '',
    character_class: '',
    class_label: '',
    avatar_file: null,
    avatar_url: '',
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
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function q(root, sel) {
    return (root || document).querySelector(sel);
  }

  function qa(root, sel) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function hasRows(res) {
    return !!(res && res.success && Array.isArray(res.data) && res.data.length);
  }

  function ajaxUrl() {
    return cfg.ajaxurl || cfg.ajax_url || '/wp-admin/admin-ajax.php';
  }

  function nonce() {
    return cfg.nonce || '';
  }

  function fetchPost(action, extraData) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', nonce());

    Object.keys(extraData || {}).forEach(function (key) {
      fd.append(key, extraData[key]);
    });

    return fetch(ajaxUrl(), {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    }).then(function (r) {
      return r.json();
    });
  }

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
    el.innerHTML =
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

  function setStatus(msg, isError) {
    var el = document.querySelector('#tw-char-status-msg');
    if (!el) return;
    el.textContent = msg || '';
    el.className = 'tw-char-status' + (isError ? ' tw-char-status--error' : '');
  }

  function getCurrentStepErrorBox(stepEl) {
    if (!stepEl) return null;

    var errEl = q(stepEl, '.tw-step-error');
    if (!errEl) {
      errEl = document.createElement('div');
      errEl.className = 'tw-step-error';
      var navRow = q(stepEl, '.tw-nav-row');
      if (navRow) stepEl.insertBefore(errEl, navRow);
      else stepEl.appendChild(errEl);
    }

    return errEl;
  }

  function showStepError(stepEl, msg) {
    if (!stepEl) return;

    var errEl = getCurrentStepErrorBox(stepEl);
    if (!errEl) return;

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
    var errEl = q(stepEl, '.tw-step-error');
    if (errEl) errEl.classList.remove('visible', 'tw-step-error--shake');
  }

  function clearAllSelected(root, selector) {
    qa(root, selector).forEach(function (card) {
      card.classList.remove('selected');
      card.setAttribute('aria-pressed', 'false');
    });
  }

  function markSelected(card) {
    if (!card) return;
    card.classList.add('selected');
    card.setAttribute('aria-pressed', 'true');
  }

  function twNormalizeMediaUrl(value) {
    if (!value) return '';

    var raw = String(value).trim();
    if (!raw) return '';

    if (/^https?:\/\//i.test(raw)) return raw;
    if (raw.indexOf('//') === 0) return 'https:' + raw;

    if (raw.indexOf('/wp-content/uploads/') === 0) {
      return TW_SITE_BASE + raw;
    }

    if (raw.indexOf('wp-content/uploads/') === 0) {
      return TW_SITE_BASE + '/' + raw;
    }

    if (raw.indexOf('/wp-content/uploads/') !== -1) {
      if (raw.charAt(0) === '/') return TW_SITE_BASE + raw;
      if (/^[a-z0-9.-]+\//i.test(raw)) return 'https://' + raw;
    }

    return TW_UPLOADS_BASE + raw.replace(/^\/+/, '');
  }

  function firstNonEmpty(list) {
    for (var i = 0; i < list.length; i++) {
      var val = list[i];
      if (val !== null && val !== undefined && String(val).trim() !== '') {
        return val;
      }
    }
    return '';
  }

  function twGetImageUrl(item) {
    if (!item) return '';

    var direct = firstNonEmpty([
      item.image_url,
      item.imageurl,
      item.imageUrl,
      item.img_url,
      item.imgurl,
      item.img,
      item.image,
      item.avatar,
      item.icon,
      item.thumbnail,
      item.graphic,
      item.file,
      item.url
    ]);

    if (direct) return twNormalizeMediaUrl(direct);

    if (item.media && typeof item.media === 'object') {
      var nested = firstNonEmpty([
        item.media.url,
        item.media.image_url,
        item.media.imageurl,
        item.media.imageUrl,
        item.media.img_url,
        item.media.imgurl,
        item.media.img,
        item.media.image,
        item.media.thumbnail
      ]);
      if (nested) return twNormalizeMediaUrl(nested);
    }

    if (Array.isArray(item.images) && item.images.length) {
      var firstImage = item.images[0];
      if (typeof firstImage === 'string') return twNormalizeMediaUrl(firstImage);

      if (firstImage && typeof firstImage === 'object') {
        var nestedArrayImage = firstNonEmpty([
          firstImage.url,
          firstImage.image_url,
          firstImage.imageurl,
          firstImage.imageUrl,
          firstImage.img_url,
          firstImage.imgurl,
          firstImage.img,
          firstImage.image
        ]);
        if (nestedArrayImage) return twNormalizeMediaUrl(nestedArrayImage);
      }
    }

    return '';
  }

  function buildTagsHtml(tags) {
    if (!tags || !tags.length) return '';
    return '<div class="tw-race-tags">' + tags.map(function (t) {
      var label = typeof t === 'string' ? t : (t && (t.name || t.label || t.slug)) || '';
      return label ? '<span class="tw-race-tag">' + esc(label) + '</span>' : '';
    }).join('') + '</div>';
  }

  function buildRaceCard(race) {
    var label = race.label || race.name || 'Unknown race';
    var imgSrc = twGetImageUrl(race);
    var imgHtml = imgSrc
      ? '<div class="tw-race-img tw-race-img--full"><img src="' + esc(imgSrc) + '" alt="' + esc(label) + '" loading="lazy" decoding="async"></div>'
      : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">✦</span></div>';

    return '' +
      '<div class="tw-grid-card tw-race-card"' +
        ' data-race="' + esc(label) + '"' +
        ' data-race-id="' + esc(race.id || '') + '"' +
        ' role="button" tabindex="0" aria-pressed="false">' +
        imgHtml +
        '<div class="tw-race-body">' +
          '<h4 class="tw-race-name">' + esc(label) + '</h4>' +
          buildTagsHtml(race.tags || []) +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function buildSubraceCard(sub) {
    var label = sub.label || sub.name || 'Unknown subrace';
    var imgSrc = twGetImageUrl(sub);
    var imgHtml = imgSrc
      ? '<div class="tw-race-img tw-race-img--full"><img src="' + esc(imgSrc) + '" alt="' + esc(label) + '" loading="lazy" decoding="async"></div>'
      : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">✦</span></div>';

    return '' +
      '<div class="tw-grid-card tw-race-card tw-subrace-card"' +
        ' data-subrace="' + esc(label) + '"' +
        ' data-subrace-id="' + esc(sub.id || '') + '"' +
        ' role="button" tabindex="0" aria-pressed="false">' +
        imgHtml +
        '<div class="tw-race-body">' +
          '<h4 class="tw-race-name">' + esc(label) + '</h4>' +
          buildTagsHtml(sub.tags || []) +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function buildLoreChoiceCard(item, kind) {
    return '' +
      '<div class="tw-lore-card tw-grid-card" data-choice-type="' + esc(kind) + '" data-choice-key="' + esc(item.key) + '" data-label="' + esc(item.label) + '" role="button" tabindex="0" aria-pressed="false">' +
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

  function buildClassCard(cls) {
    var label = cls.name || 'Unknown class';
    var imgSrc = twGetImageUrl(cls);
    var skillLimit = parseInt(cls.skill_limit || cls.skilllimit, 10) || 5;
    var imgHtml = imgSrc
      ? '<div class="tw-class-card__img-wrap"><img src="' + esc(imgSrc) + '" alt="' + esc(label) + '" width="220" height="220" loading="lazy" decoding="async"></div>'
      : '<div class="tw-class-card__img-wrap tw-class-card__img-wrap--placeholder"><span>' + esc(cls.icon_slug || cls.iconslug || '✦') + '</span></div>';

    return '' +
      '<div class="tw-class-card" data-char-class="' + esc(cls.id || cls.name || '') + '" data-label="' + esc(label) + '" data-class-tag="' + esc((label || '').toLowerCase()) + '" data-skilllimit="' + esc(skillLimit) + '" role="button" tabindex="0" aria-pressed="false">' +
        imgHtml +
        '<div class="tw-class-card__body">' +
          '<h4 class="tw-class-card__name">' + esc(label) + '</h4>' +
          (cls.description ? '<p class="tw-class-card__desc">' + esc(cls.description) + '</p>' : '') +
          buildTagsHtml(cls.tags || []) +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function buildSkillCard(skill) {
    var label = skill.name || 'Unknown skill';
    var imgSrc = twGetImageUrl(skill);
    var tags = [].concat(skill.tags || [], skill.linked_attributes || skill.linkedattributes || []);
    var imgHtml = imgSrc
      ? '<div class="tw-race-img"><img src="' + esc(imgSrc) + '" alt="' + esc(label) + '" width="220" height="220" loading="lazy" decoding="async"></div>'
      : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">✦</span></div>';

    return '' +
      '<div class="tw-skill-card tw-grid-card" data-skill-id="' + esc(skill.id || '') + '" data-label="' + esc(label) + '" role="button" tabindex="0" aria-pressed="false">' +
        imgHtml +
        '<div class="tw-race-body">' +
          '<h4 class="tw-race-name">' + esc(label) + '</h4>' +
          (skill.description ? '<p class="tw-race-desc">' + esc(skill.description) + '</p>' : '') +
          buildTagsHtml(tags) +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function buildPackageCard(pkg) {
    var tags = pkg.compatibility_tags || pkg.compatibilitytags || [];
    var items = pkg.items_list || pkg.itemslist || [];
    var name = pkg.package_name || pkg.packagename || '';
    var itemsPreview = Array.isArray(items) && items.length
      ? '<div class="tw-package-items">' + items.slice(0, 5).map(function (item) {
          return '<span class="tw-race-tag">' + esc(typeof item === 'string' ? item : (item.name || item.label || 'item')) + '</span>';
        }).join('') + '</div>'
      : '';

    return '' +
      '<div class="tw-package-card tw-grid-card" data-package-id="' + esc(pkg.id || '') + '" data-label="' + esc(name) + '" role="button" tabindex="0" aria-pressed="false">' +
        '<div class="tw-race-body">' +
          '<h4 class="tw-race-name">' + esc(name) + '</h4>' +
          (pkg.description ? '<p class="tw-race-desc">' + esc(pkg.description) + '</p>' : '') +
          (pkg.base_armor != null ? '<span class="tw-race-bonus">Armor ' + esc(pkg.base_armor) + '</span>' : '') +
          buildTagsHtml(tags) +
          itemsPreview +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function choiceSet(kind) {
    if (kind === 'data_origin') return DATA_ORIGIN_OPTIONS;
    if (kind === 'previous_operation') return PREVIOUS_OPERATION_OPTIONS;
    if (kind === 'sync_crisis') return SYNC_CRISIS_OPTIONS;
    return [];
  }

  function choiceByKey(kind, key) {
    var set = choiceSet(kind);
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

  function hideSubraceSection(wrapper) {
    var section = q(wrapper, '#tw-subrace-section');
    if (!section) return;
    section.hidden = true;
    section.style.display = 'none';
  }

  function showSubraceSection(wrapper) {
    var section = q(wrapper, '#tw-subrace-section');
    if (!section) return;
    section.hidden = false;
    section.style.display = '';
  }

  function resetSubraceState(wrapper) {
    formState.subrace = '';
    formState.subrace_label = '';

    var grid = q(wrapper, '#tw-subrace-grid');
    if (grid) grid.innerHTML = '';
    hideSubraceSection(wrapper);
  }

  function resetClassDependentState(wrapper) {
    formState.skills = [];
    formState.starting_package_id = '';
    formState.starting_package_label = '';

    var skillGrid = q(wrapper, '#tw-skill-grid');
    var packageGrid = q(wrapper, '#tw-package-grid');

    if (skillGrid) {
      delete skillGrid.dataset.rendered;
      skillGrid.innerHTML = '';
    }

    if (packageGrid) {
      delete packageGrid.dataset.rendered;
      packageGrid.innerHTML = '';
    }
  }

  function selectedClassTag(wrapper) {
    if (!formState.class_label) return '';
    var selected = q(wrapper, '.tw-class-card.selected');
    if (selected && selected.dataset.classTag) return String(selected.dataset.classTag).trim().toLowerCase();
    return String(formState.class_label).trim().toLowerCase();
  }

  function renderLoreChoices(wrapper) {
    [
      { id: '#tw-origin-grid', kind: 'data_origin', items: DATA_ORIGIN_OPTIONS },
      { id: '#tw-operation-grid', kind: 'previous_operation', items: PREVIOUS_OPERATION_OPTIONS },
      { id: '#tw-crisis-grid', kind: 'sync_crisis', items: SYNC_CRISIS_OPTIONS }
    ].forEach(function (entry) {
      var grid = q(wrapper, entry.id);
      if (!grid || grid.dataset.rendered) return;
      grid.innerHTML = entry.items.map(function (item) {
        return buildLoreChoiceCard(item, entry.kind);
      }).join('');
      grid.dataset.rendered = '1';
    });
  }

  function fetchRaceGrid(wrapper) {
    var grid = q(wrapper, '#tw-race-grid');
    if (!grid || grid.dataset.rendered) return;

    grid.innerHTML = '<p class="tw-loading">SCANNING RACE DATABASE…</p>';

    fetchPost('neoweaver_get_races', {})
      .then(function (res) {
        var rows = hasRows(res) ? res.data : RACES_FALLBACK;
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
    var grid = q(wrapper, '#tw-subrace-grid');
    if (!grid) return;

    resetSubraceState(wrapper);
    if (!raceKey) return;

    showSubraceSection(wrapper);
    grid.innerHTML = '<p class="tw-loading">SCANNING SUBRACE DATA…</p>';

    fetchPost('neoweaver_get_subraces', { parent: raceKey })
      .then(function (res) {
        if (hasRows(res)) {
          grid.innerHTML = res.data.map(buildSubraceCard).join('');
          showSubraceSection(wrapper);
        } else {
          grid.innerHTML = '';
          hideSubraceSection(wrapper);
        }
        restoreSelections(wrapper);
        updateSummary(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '';
        hideSubraceSection(wrapper);
        restoreSelections(wrapper);
        updateSummary(wrapper);
      });
  }

  function fetchClassGrid(wrapper) {
    var grid = q(wrapper, '#tw-class-grid');
    if (!grid || grid.dataset.rendered) return;

    grid.innerHTML = '<p class="tw-loading">SCANNING CLASS MATRIX…</p>';

    fetchPost('neoweaver_get_classes', {})
      .then(function (res) {
        grid.innerHTML = hasRows(res)
          ? res.data.map(buildClassCard).join('')
          : '<p class="tw-empty-state">No classes available.</p>';
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<p class="tw-error-msg">ERROR: Class data unavailable.</p>';
      });
  }

  function updateSkillCounter(wrapper) {
    var counter = q(wrapper, '#tw-skill-counter');
    if (counter) {
      counter.textContent = formState.skills.length + ' / ' + (formState.skill_limit || 5) + ' skills';
    }
  }

  function fetchSkillGrid(wrapper) {
    var grid = q(wrapper, '#tw-skill-grid');
    if (!grid) return;

    if (grid.dataset.rendered) {
      updateSkillCounter(wrapper);
      restoreSelections(wrapper);
      return;
    }

    grid.innerHTML = '<p class="tw-loading">SCANNING SKILL ARCHIVE…</p>';

    fetchPost('neoweaver_get_skills', {})
      .then(function (res) {
        if (!hasRows(res)) {
          grid.innerHTML = '<p class="tw-empty-state">No skills available.</p>';
          grid.dataset.rendered = '1';
          updateSkillCounter(wrapper);
          restoreSelections(wrapper);
          return;
        }

        var byCat = {};
        res.data.forEach(function (skill) {
          var cat = skill.category || 'Other';
          if (!byCat[cat]) byCat[cat] = [];
          byCat[cat].push(skill);
        });

        var html = Object.keys(byCat).map(function (cat) {
          return '' +
            '<div class="tw-skill-category">' +
              '<div class="tw-skill-cat-label">' + esc(cat) + '</div>' +
              '<div class="tw-skill-cat-grid">' +
                byCat[cat].map(buildSkillCard).join('') +
              '</div>' +
            '</div>';
        }).join('');

        grid.innerHTML = html || '<p class="tw-empty-state">No skills available.</p>';
        grid.dataset.rendered = '1';
        updateSkillCounter(wrapper);
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<p class="tw-error-msg">ERROR: Skill data unavailable.</p>';
      });
  }

  function fetchPackageGrid(wrapper) {
    var grid = q(wrapper, '#tw-package-grid');
    if (!grid) return;

    var classTag = selectedClassTag(wrapper);

    if (!classTag) {
      grid.innerHTML = '<p class="tw-empty-state">Select a class first.</p>';
      return;
    }

    if (grid.dataset.rendered && grid.dataset.rendered === classTag) {
      restoreSelections(wrapper);
      return;
    }

    grid.innerHTML = '<div class="tw-loading-state"><div class="tw-loading-dot"></div>FETCHING STARTING PACKAGES…</div>';

    fetchPost('neoweaver_get_starting_packages', { class_tag: classTag })
      .then(function (res) {
        grid.innerHTML = hasRows(res)
          ? res.data.map(buildPackageCard).join('')
          : '<p class="tw-empty-state">No starting packages available for this class.</p>';
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
      var inputEl = q(wrapper, '#tw-attr-' + key);
      if (inputEl) inputEl.value = val;

      qa(wrapper, '[data-attr="' + key + '"] .tw-pip').forEach(function (pip) {
        pip.classList.toggle('active', parseInt(pip.dataset.pip, 10) <= val);
      });
    });

    var used = ATTR_KEYS.reduce(function (sum, key) {
      return sum + (formState['attr_' + key] || ATTR_MIN);
    }, 0);

    var remainEl = q(wrapper, '#tw-attr-remaining');
    if (remainEl) remainEl.textContent = ATTR_POOL - used;
  }

  function applyAttrPreset(wrapper, presetBtn) {
    var valid = true;

    ATTR_KEYS.forEach(function (key) {
      var v = parseInt(presetBtn.dataset[key], 10);
      if (isNaN(v) || v < ATTR_MIN || v > ATTR_MAX) valid = false;
    });

    if (!valid) return;

    ATTR_KEYS.forEach(function (key) {
      formState['attr_' + key] = parseInt(presetBtn.dataset[key], 10);
    });

    qa(wrapper, '.tw-attr-preset-btn').forEach(function (btn) {
      btn.classList.toggle('active', btn === presetBtn);
    });

    renderAttrDisplay(wrapper);
    updateSummary(wrapper);
    NW_SFX.preset();
  }

  function renderAvatarGallery(wrapper) {
    var avatarGalleryEl = q(wrapper, '#tw-avatar-gallery');
    var avatarPreviewImg = q(wrapper, '#tw-avatar-img');
    var avatarSelectedWrap = q(wrapper, '#tw-avatar-selected');
    var avatarInput = q(wrapper, '#tw-char-avatar');

    if (!avatarGalleryEl) return;

    avatarGalleryEl.innerHTML = TW_AVATAR_GALLERY.map(function (item) {
      var normalizedUrl = twNormalizeMediaUrl(item.url);
      return '' +
        '<button type="button" class="tw-avatar-option" data-avatar-url="' + esc(normalizedUrl) + '" data-avatar-id="' + esc(item.id) + '" aria-label="Choose ' + esc(item.name) + '">' +
          '<img src="' + esc(normalizedUrl) + '" alt="' + esc(item.name) + '" loading="lazy" decoding="async">' +
          '<span class="tw-avatar-option__label">' + esc(item.name) + '</span>' +
        '</button>';
    }).join('');

    qa(avatarGalleryEl, '.tw-avatar-option').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var url = twNormalizeMediaUrl(btn.dataset.avatarUrl || '');
        formState.avatar_file = null;
        formState.avatar_url = url;

        if (avatarPreviewImg) {
          avatarPreviewImg.src = url;
          avatarPreviewImg.alt = 'Selected gallery avatar';
        }

        if (avatarSelectedWrap) {
          avatarSelectedWrap.style.display = 'grid';
        }

        if (avatarInput) {
          avatarInput.value = '';
        }

        qa(avatarGalleryEl, '.tw-avatar-option').forEach(function (x) {
          x.classList.toggle('selected', x === btn);
        });

        updateSummary(wrapper);
      });
    });
  }

  function handleAvatarFile(wrapper, file) {
    var allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];

    if (!file || allowed.indexOf(file.type) === -1 || file.size > 2 * 1024 * 1024) {
      setStatus('ERROR: Invalid file. JPG / PNG / WEBP / SVG under 2 MB only.', true);
      NW_SFX.error();
      return;
    }

    formState.avatar_file = file;
    formState.avatar_url = '';
    NW_SFX.select();

    var reader = new FileReader();
    reader.onload = function (ev) {
      var imgEl = q(wrapper, '#tw-avatar-img');
      var preview = q(wrapper, '#tw-avatar-preview');
      var selected = q(wrapper, '#tw-avatar-selected');
      var gallery = q(wrapper, '#tw-avatar-gallery');

      if (imgEl) imgEl.src = ev.target.result;
      if (preview) preview.style.display = 'none';
      if (selected) selected.style.display = 'grid';

      if (gallery) {
        qa(gallery, '.tw-avatar-option').forEach(function (x) {
          x.classList.remove('selected');
        });
      }

      updateSummary(wrapper);
    };

    reader.readAsDataURL(file);
  }

  function clearAvatar(wrapper) {
    formState.avatar_file = null;
    formState.avatar_url = '';

    var imgEl = q(wrapper, '#tw-avatar-img');
    var preview = q(wrapper, '#tw-avatar-preview');
    var selected = q(wrapper, '#tw-avatar-selected');
    var fileInput = q(wrapper, '#tw-char-avatar');
    var gallery = q(wrapper, '#tw-avatar-gallery');

    if (imgEl) {
      imgEl.src = '';
      imgEl.alt = '';
    }
    if (preview) preview.style.display = '';
    if (selected) selected.style.display = 'none';
    if (fileInput) fileInput.value = '';

    if (gallery) {
      qa(gallery, '.tw-avatar-option').forEach(function (x) {
        x.classList.remove('selected');
      });
    }

    updateSummary(wrapper);
    NW_SFX.back();
  }

  function updateSummary(wrapper) {
    function set(id, val) {
      var el = q(wrapper, '#tw-summary-' + id);
      if (el) el.textContent = val || '—';
    }

    set('character-name', formState.character_name);
    set('pronouns', formState.pronouns);
    set('race', [formState.race_label, formState.subrace_label].filter(Boolean).join(' / ') || formState.race || '—');
    set('class', formState.class_label || formState.character_class || '—');
    set('attrs', ATTR_KEYS.map(function (key) {
      return key.toUpperCase() + ' ' + formState['attr_' + key];
    }).join(' · '));
    set('skills', formState.skills.length ? (formState.skills.length + ' / ' + (formState.skill_limit || 5)) : '—');
    set('package', formState.starting_package_label || '—');

    var origin = choiceByKey('data_origin', formState.data_origin);
    var operation = choiceByKey('previous_operation', formState.previous_operation);
    var crisis = choiceByKey('sync_crisis', formState.sync_crisis);

    set('origin', origin ? origin.label : '—');
    set('operation', operation ? operation.label : '—');
    set('crisis', crisis ? crisis.label : '—');
    set('tag-bundle', formState.backstory_tags.length ? formState.backstory_tags.join(' · ') : '—');
    set('bio', formState.bio ? (formState.bio.length > 80 ? formState.bio.substring(0, 80) + '…' : formState.bio) : '—');

    var avatarEl = q(wrapper, '#tw-summary-avatar');
    if (avatarEl) {
      if (formState.avatar_file) avatarEl.textContent = formState.avatar_file.name;
      else if (formState.avatar_url) avatarEl.textContent = formState.avatar_url.split('/').pop();
      else avatarEl.textContent = '—';
    }
  }

  function syncSingleSelection(wrapper, selector, selectedId, dataAttr) {
    qa(wrapper, selector).forEach(function (card) {
      var isMatch = !!selectedId && String(card.dataset[dataAttr]) === String(selectedId);
      card.classList.toggle('selected', isMatch);
      card.setAttribute('aria-pressed', isMatch ? 'true' : 'false');
    });
  }

  function restoreSelections(wrapper) {
    syncSingleSelection(wrapper, '.tw-race-card:not(.tw-subrace-card)', formState.race, 'raceId');
    syncSingleSelection(wrapper, '.tw-subrace-card', formState.subrace, 'subraceId');
    syncSingleSelection(wrapper, '.tw-class-card', formState.character_class, 'charClass');
    syncSingleSelection(wrapper, '.tw-package-card', formState.starting_package_id, 'packageId');

    qa(wrapper, '.tw-skill-card').forEach(function (card) {
      var isMatch = formState.skills.indexOf(card.dataset.skillId) !== -1;
      card.classList.toggle('selected', isMatch);
      card.setAttribute('aria-pressed', isMatch ? 'true' : 'false');
    });

    qa(wrapper, '.tw-lore-card').forEach(function (card) {
      var type = card.dataset.choiceType;
      var key = card.dataset.choiceKey;
      var isMatch = !!type && formState[type] === key;
      card.classList.toggle('selected', isMatch);
      card.setAttribute('aria-pressed', isMatch ? 'true' : 'false');
    });

    updateSkillCounter(wrapper);
  }

  function stepPhase(step) {
    return step ? (step.dataset.phase || '') : '';
  }

  function validateIdentityStep(wrapper, step) {
    var nameInput = q(wrapper, '#tw-char-name');

    if (!nameInput || !nameInput.value.trim()) {
      if (nameInput) nameInput.focus();
      showStepError(step, 'ERROR: Agent designation is required.');
      setStatus('ERROR: Agent designation is required.', true);
      return false;
    }

    formState.character_name = nameInput.value.trim();

    var checkedRadio = q(wrapper, '.tw-pronoun-radio:checked');
    if (checkedRadio) {
      if (checkedRadio.value === 'custom') {
        var customEl = q(wrapper, '#tw-char-pronouns-custom');
        formState.pronouns = customEl && customEl.value.trim() ? customEl.value.trim() : 'custom';
      } else {
        formState.pronouns = checkedRadio.value;
      }
    } else {
      formState.pronouns = '';
    }

    return true;
  }

  function validateStep(wrapper, steps, idx) {
    var step = steps[idx];
    if (!step) return true;

    clearStepError(step);

    if (idx === 0) {
      return validateIdentityStep(wrapper, step);
    }

    var phase = stepPhase(step);

    if (phase === 'RACE PROTOCOL') {
      if (!formState.race) {
        showStepError(step, 'ERROR: Select a race to continue.');
        setStatus('ERROR: Select a race to continue.', true);
        return false;
      }
      return true;
    }

    if (phase === 'CLASS MATRIX') {
      if (!formState.character_class) {
        showStepError(step, 'ERROR: Select a class to continue.');
        setStatus('ERROR: Select a class to continue.', true);
        return false;
      }
      return true;
    }

    if (phase === 'BIOMETRIC CALIBRATION') {
      var used = ATTR_KEYS.reduce(function (sum, key) {
        return sum + (formState['attr_' + key] || ATTR_MIN);
      }, 0);

      if (used !== ATTR_POOL) {
        showStepError(step, 'ERROR: Distribute all ' + ATTR_POOL + ' attribute points.');
        setStatus('ERROR: Distribute all ' + ATTR_POOL + ' attribute points.', true);
        return false;
      }
      return true;
    }

    if (phase === 'SKILL SELECTION') {
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

    if (phase === 'STARTING PACKAGE') {
      if (!formState.starting_package_id) {
        showStepError(step, 'ERROR: Select a starting package to continue.');
        setStatus('ERROR: Select a starting package to continue.', true);
        return false;
      }
      return true;
    }

    if (phase === 'DATA ORIGIN') {
      if (!formState.data_origin) {
        showStepError(step, 'ERROR: Select a data origin to continue.');
        setStatus('ERROR: Select a data origin to continue.', true);
        return false;
      }
      return true;
    }

    if (phase === 'PREVIOUS OPERATION') {
      if (!formState.previous_operation) {
        showStepError(step, 'ERROR: Select a previous operation to continue.');
        setStatus('ERROR: Select a previous operation to continue.', true);
        return false;
      }
      return true;
    }

    if (phase === 'SYNCHRONIZATION CRISIS') {
      if (!formState.sync_crisis) {
        showStepError(step, 'ERROR: Select a synchronization crisis response to continue.');
        setStatus('ERROR: Select a synchronization crisis response to continue.', true);
        return false;
      }
      recomputeBackstoryTags();
      return true;
    }

    if (phase === 'VISUAL SIGNATURE') {
      var bioEl = q(wrapper, '#tw-char-bio');
      formState.bio = bioEl ? bioEl.value.trim() : '';
      return true;
    }

    return true;
  }

  function resolveNextButton(target) {
    return target.closest('#tw-char-step1-next, .tw-btn-next, .tw-btn-nav[data-dir="next"]');
  }

  function resolvePrevButton(target) {
    return target.closest('.tw-btn-prev, .tw-btn-nav[data-dir="prev"], .tw-btn-review-return');
  }

  function showStep(wrapper, steps, idx) {
    steps.forEach(function (step, i) {
      step.classList.toggle('active', i === idx);
    });

    setStatus('', false);

    var phase = stepPhase(steps[idx]);

    if (phase === 'CLASS MATRIX') fetchClassGrid(wrapper);
    if (phase === 'SKILL SELECTION') fetchSkillGrid(wrapper);
    if (phase === 'STARTING PACKAGE') fetchPackageGrid(wrapper);
    if (phase === 'DATA ORIGIN' || phase === 'PREVIOUS OPERATION' || phase === 'SYNCHRONIZATION CRISIS') renderLoreChoices(wrapper);
    if (phase === 'VISUAL SIGNATURE') renderAvatarGallery(wrapper);
    if (phase === 'SYSTEM REVIEW') updateSummary(wrapper);

    var fillEl = q(wrapper, '#tw-char-progress-fill');
    var stepElCounter = q(wrapper, '#tw-char-step-current');
    var phaseEl = q(wrapper, '#tw-char-progress-phase');

    if (fillEl) fillEl.style.width = Math.round(((idx + 1) / steps.length) * 100) + '%';
    if (stepElCounter) stepElCounter.textContent = idx + 1;
    if (phaseEl) phaseEl.textContent = phase;

    qa(wrapper, '.tw-progress-tick').forEach(function (tick) {
      tick.classList.toggle('active', parseInt(tick.dataset.tick, 10) <= idx + 1);
    });

    restoreSelections(wrapper);
  }

  function submitCharacter(wrapper, steps, current, spinner) {
    setStatus('Uploading agent profile…', false);

    var submitBtn = q(wrapper, '#tw-char-submit');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '⏳ SYNCHRONIZING…';
    }

    spinner.show();

    var data = new FormData();
    data.append('action', 'neoweaver_create_character');
    data.append('nonce', nonce());
    data.append('character_name', formState.character_name);
    data.append('pronouns', formState.pronouns);
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

    ATTR_KEYS.forEach(function (key) {
      data.append('attr_' + key, formState['attr_' + key]);
    });

    if (formState.avatar_file) data.append('avatar', formState.avatar_file);
    if (formState.avatar_url) data.append('avatar_url', formState.avatar_url);

    var t0 = Date.now();

    fetch(ajaxUrl(), {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        var wait = Math.max(0, 1200 - (Date.now() - t0));

        setTimeout(function () {
          spinner.hide();

          if (res && res.success) {
            setStatus('Agent profile created. Welcome to the Grid.', false);
            wrapper.innerHTML =
              '<div class="tw-success">' +
                '<p class="tw-success__msg">✓ ' + esc((res.data && res.data.message) || 'Character created!') + '</p>' +
                ((res.data && res.data.redirect)
                  ? '<a href="' + esc(res.data.redirect) + '" class="tw-btn tw-btn--primary">Enter the Grid</a>'
                  : '') +
              '</div>';
            NW_SFX.deploy();
            return;
          }

          var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Submission failed. Retry.';
          setStatus('ERROR: ' + errMsg, true);
          showStepError(steps[current], errMsg);

          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '⌘ SYNCHRONIZE AGENT';
          }
        }, wait);
      })
      .catch(function () {
        spinner.hide();
        setStatus('ERROR: Connection lost. Check your link and retry.', true);
        showStepError(steps[current], 'Connection lost. Check your link and retry.');
        NW_SFX.error();

        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = '⌘ SYNCHRONIZE AGENT';
        }
      });
  }

  function handleRaceSelection(wrapper, card, currentStep) {
    clearAllSelected(wrapper, '.tw-race-card:not(.tw-subrace-card)');
    markSelected(card);

    formState.race = card.dataset.raceId || '';
    formState.race_label = q(card, '.tw-race-name') ? q(card, '.tw-race-name').textContent.trim() : '';

    resetSubraceState(wrapper);
    fetchSubraces(wrapper, card.dataset.race || '');

    clearStepError(currentStep);
    restoreSelections(wrapper);
    updateSummary(wrapper);
    NW_SFX.select();
  }

  function handleSubraceSelection(wrapper, card, currentStep) {
    clearAllSelected(wrapper, '.tw-subrace-card');
    markSelected(card);

    formState.subrace = card.dataset.subraceId || '';
    formState.subrace_label = q(card, '.tw-race-name') ? q(card, '.tw-race-name').textContent.trim() : '';

    clearStepError(currentStep);
    restoreSelections(wrapper);
    updateSummary(wrapper);
    NW_SFX.select();
  }

  function handleClassSelection(wrapper, card, currentStep) {
    clearAllSelected(wrapper, '.tw-class-card');
    markSelected(card);

    formState.character_class = card.dataset.charClass || '';
    formState.class_label = card.dataset.label || (q(card, '.tw-class-card__name') ? q(card, '.tw-class-card__name').textContent.trim() : '');
    formState.skill_limit = parseInt(card.dataset.skilllimit, 10) || 5;

    resetClassDependentState(wrapper);
    fetchSkillGrid(wrapper);
    fetchPackageGrid(wrapper);

    clearStepError(currentStep);
    restoreSelections(wrapper);
    updateSummary(wrapper);
    NW_SFX.select();
  }

  function handleSkillSelection(wrapper, card, currentStep) {
    var skillId = card.dataset.skillId;
    if (!skillId) return;

    var idx = formState.skills.indexOf(skillId);

    if (idx === -1) {
      if (formState.skills.length >= (formState.skill_limit || 5)) {
        setStatus('ERROR: Skill limit reached for this class.', true);
        NW_SFX.error();
        return;
      }
      formState.skills.push(skillId);
      markSelected(card);
    } else {
      formState.skills.splice(idx, 1);
      card.classList.remove('selected');
      card.setAttribute('aria-pressed', 'false');
    }

    clearStepError(currentStep);
    updateSkillCounter(wrapper);
    updateSummary(wrapper);
    NW_SFX.select();
  }

  function handlePackageSelection(wrapper, card, currentStep) {
    clearAllSelected(wrapper, '.tw-package-card');
    markSelected(card);

    formState.starting_package_id = card.dataset.packageId || '';
    formState.starting_package_label = card.dataset.label || (q(card, '.tw-race-name') ? q(card, '.tw-race-name').textContent.trim() : '');

    clearStepError(currentStep);
    restoreSelections(wrapper);
    updateSummary(wrapper);
    NW_SFX.select();
  }

  function handleLoreSelection(wrapper, card, currentStep) {
    var type = card.dataset.choiceType;
    var key = card.dataset.choiceKey;
    if (!type || !key) return;

    clearAllSelected(wrapper, '.tw-lore-card[data-choice-type="' + type + '"]');
    markSelected(card);

    formState[type] = key;
    recomputeBackstoryTags();

    clearStepError(currentStep);
    restoreSelections(wrapper);
    updateSummary(wrapper);
    NW_SFX.select();
  }

  function adjustAttribute(wrapper, btn, currentStep) {
    var key = btn.dataset.attr;
    if (ATTR_KEYS.indexOf(key) === -1) return;

    var isPlus = btn.classList.contains('tw-attr-plus') || btn.dataset.dir === 'up' || btn.dataset.dir === 'plus';
    var isMinus = btn.classList.contains('tw-attr-minus') || btn.dataset.dir === 'down' || btn.dataset.dir === 'minus';

    var currentVal = formState['attr_' + key] || ATTR_MIN;
    var used = ATTR_KEYS.reduce(function (sum, attrKey) {
      return sum + (formState['attr_' + attrKey] || ATTR_MIN);
    }, 0);

    if (isPlus) {
      if (currentVal >= ATTR_MAX) return;
      if (used >= ATTR_POOL) {
        NW_SFX.error();
        return;
      }
      formState['attr_' + key] = currentVal + 1;
    } else if (isMinus) {
      if (currentVal <= ATTR_MIN) return;
      formState['attr_' + key] = currentVal - 1;
    } else {
      return;
    }

    renderAttrDisplay(wrapper);
    clearStepError(currentStep);
    updateSummary(wrapper);
    NW_SFX.nav();
  }

  function init() {
    var wrapper = document.getElementById('tw-char-creator-wrapper');
    if (!wrapper || wrapper.dataset.nwInit) return;

    wrapper.dataset.nwInit = '1';

    var steps = qa(wrapper, '.tw-step');
    var current = 0;
    if (!steps.length) return;

    var spinner = makeSpinner('tw-char-spinner', 'SYNCHRONIZING AGENT…', 'Writing operative data to the NeoWeave grid.');

    function goNext() {
      if (!validateStep(wrapper, steps, current)) return;
      clearStepError(steps[current]);
      setStatus('', false);

      if (current < steps.length - 1) {
        current++;
        NW_SFX.nav();
        showStep(wrapper, steps, current);
      }
    }

    function goPrev() {
      clearStepError(steps[current]);
      setStatus('', false);

      if (current > 0) {
        current--;
        NW_SFX.back();
        showStep(wrapper, steps, current);
      }
    }

    fetchRaceGrid(wrapper);
    renderAttrDisplay(wrapper);
    renderLoreChoices(wrapper);
    renderAvatarGallery(wrapper);
    updateSummary(wrapper);
    resetSubraceState(wrapper);

    wrapper.addEventListener('click', function (e) {
      var target = e.target;
      var currentStep = steps[current];

      var presetBtn = target.closest('.tw-attr-preset-btn');
      if (presetBtn) {
        e.preventDefault();
        applyAttrPreset(wrapper, presetBtn);
        clearStepError(currentStep);
        return;
      }

      var nextBtn = resolveNextButton(target);
      if (nextBtn) {
        e.preventDefault();
        goNext();
        return;
      }

      var prevBtn = resolvePrevButton(target);
      if (prevBtn) {
        e.preventDefault();
        goPrev();
        return;
      }

      var submitBtn = target.closest('#tw-char-submit');
      if (submitBtn) {
        e.preventDefault();
        if (validateStep(wrapper, steps, current)) {
          submitCharacter(wrapper, steps, current, spinner);
        }
        return;
      }

      var raceCard = target.closest('.tw-race-card:not(.tw-subrace-card)');
      if (raceCard) {
        handleRaceSelection(wrapper, raceCard, currentStep);
        return;
      }

      var subCard = target.closest('.tw-subrace-card');
      if (subCard) {
        handleSubraceSelection(wrapper, subCard, currentStep);
        return;
      }

      var classCard = target.closest('.tw-class-card');
      if (classCard) {
        handleClassSelection(wrapper, classCard, currentStep);
        return;
      }

      var skillCard = target.closest('.tw-skill-card');
      if (skillCard) {
        handleSkillSelection(wrapper, skillCard, currentStep);
        return;
      }

      var packageCard = target.closest('.tw-package-card');
      if (packageCard) {
        handlePackageSelection(wrapper, packageCard, currentStep);
        return;
      }

      var loreCard = target.closest('.tw-lore-card');
      if (loreCard) {
        handleLoreSelection(wrapper, loreCard, currentStep);
        return;
      }

      var editBtn = target.closest('.tw-summary-edit');
      if (editBtn && editBtn.dataset.goto) {
        e.preventDefault();
        var go = parseInt(editBtn.dataset.goto, 10);
        if (!isNaN(go) && go >= 1 && go <= steps.length) {
          current = go - 1;
          showStep(wrapper, steps, current);
          NW_SFX.nav();
        }
        return;
      }

      var clearAvatarBtn = target.closest('#tw-avatar-clear, .tw-avatar-clear');
      if (clearAvatarBtn) {
        e.preventDefault();
        clearAvatar(wrapper);
        return;
      }

      var uploadTrigger = target.closest('.tw-upload-trigger');
      if (uploadTrigger) {
        e.preventDefault();
        var hiddenFileInput = q(wrapper, '#tw-char-avatar');
        if (hiddenFileInput) hiddenFileInput.click();
        return;
      }

      var avatarOption = target.closest('.tw-avatar-option');
      if (avatarOption) {
        return;
      }

      var attrBtn = target.closest('.tw-attr-btn');
      if (attrBtn && attrBtn.dataset.attr) {
        e.preventDefault();
        adjustAttribute(wrapper, attrBtn, currentStep);
      }
    });

    wrapper.addEventListener('input', function (e) {
      var t = e.target;

      if (t.id === 'tw-char-name') {
        formState.character_name = t.value || '';
        updateSummary(wrapper);
      }

      if (t.id === 'tw-char-pronouns-custom') {
        var customRadio = q(wrapper, '.tw-pronoun-radio[value="custom"]');
        if (customRadio && customRadio.checked) {
          formState.pronouns = t.value.trim() || 'custom';
          updateSummary(wrapper);
        }
      }

      if (t.id === 'tw-char-bio') {
        formState.bio = t.value || '';
        updateSummary(wrapper);
      }
    });

    wrapper.addEventListener('change', function (e) {
      if (e.target && e.target.id === 'tw-char-avatar') {
        if (e.target.files && e.target.files[0]) {
          handleAvatarFile(wrapper, e.target.files[0]);
        }
        return;
      }

      if (!(e.target && e.target.classList.contains('tw-pronoun-radio'))) return;

      var customInput = q(wrapper, '#tw-char-pronouns-custom');
      if (!customInput) return;

      customInput.style.display = e.target.value === 'custom' ? '' : 'none';

      if (e.target.value === 'custom') {
        customInput.focus();
        formState.pronouns = customInput.value.trim() || 'custom';
      } else {
        formState.pronouns = e.target.value;
      }

      updateSummary(wrapper);
    });

    wrapper.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;

      var card = e.target.closest('.tw-race-card, .tw-class-card, .tw-skill-card, .tw-package-card, .tw-lore-card, .tw-subrace-card, .tw-avatar-option');
      if (!card) return;

      e.preventDefault();
      card.click();
    });

    var dropBox = q(wrapper, '#tw-avatar-drop');
    var fileInput = q(wrapper, '#tw-char-avatar');

    if (dropBox) {
      dropBox.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropBox.classList.add('tw-upload-box--drag');
      });

      dropBox.addEventListener('dragleave', function () {
        dropBox.classList.remove('tw-upload-box--drag');
      });

      dropBox.addEventListener('drop', function (e) {
        e.preventDefault();
        dropBox.classList.remove('tw-upload-box--drag');
        var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (file) handleAvatarFile(wrapper, file);
      });
    }

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) {
          handleAvatarFile(wrapper, fileInput.files[0]);
        }
      });
    }

    showStep(wrapper, steps, 0);
  }

  function boot() {
    var wrapper = document.getElementById('tw-char-creator-wrapper');

    if (wrapper) {
      init();
      return;
    }

    var retry = 0;
    var poll = setInterval(function () {
      retry++;

      if (document.getElementById('tw-char-creator-wrapper')) {
        clearInterval(poll);
        init();
      } else if (retry > 50) {
        clearInterval(poll);
      }
    }, 100);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
