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

  var TW_SITE_BASE = String(cfg.site_base || 'https://neoweaver.nieodparady.pl').replace(/\/+$/, '');
  var TW_UPLOADS_BASE = String(cfg.uploads_base || (TW_SITE_BASE + '/wp-content/uploads/')).replace(/\/?$/, '/');
  var TW_AVATAR_GALLERY = Array.isArray(cfg.avatar_gallery) && cfg.avatar_gallery.length
    ? cfg.avatar_gallery
    : [
        { id: 'avatar-1', name: 'Avatar', url: TW_UPLOADS_BASE + 'Avatar.svg' },
        { id: 'avatar-2', name: 'Avatar 2', url: TW_UPLOADS_BASE + 'Avatar-1.svg' }
      ];

  var DATA_ORIGIN_OPTIONS = [
    { key: 'palace', label: 'Palace', desc: 'Your consciousness was stabilized among luxury systems, court protocols, and prototype-grade environments.', bonus_tag: 'Wealthy', bonus_desc: '+100 Credits at initialization.', flaw_tag: 'Fragile-Gear', flaw_desc: 'Base Durability of starting gear -2; using expensive but delicate prototypes.' },
    { key: 'slums', label: 'Slums', desc: 'Your core pattern held together in the noise of city rubble, scarcity, and improvised survival.', bonus_tag: 'Street-Smart', bonus_desc: 'Reveal hidden mechanics in locations tagged #city or #shady.', flaw_tag: 'Malnourished', flaw_desc: 'Max Satiety -2.' },
    { key: 'void-labs', label: 'Void Labs', desc: 'Your consciousness was first stabilized in isolated research arrays and experimental sync chambers.', bonus_tag: 'Fast-Sync', bonus_desc: 'Resting recovers +2 additional Sync.', flaw_tag: 'Social-Glitch', flaw_desc: '-10% success rate on Social actions vs #human targets.' },
    { key: 'borderlines', label: 'Borderlines', desc: 'Your first stable thoughts formed on the edge of mapped zones, between signal, wasteland, and frontier.', bonus_tag: 'Scout', bonus_desc: 'Travel between nodes consumes -1 Satiety.', flaw_tag: 'Analog-Mind', flaw_desc: 'Cannot use #Digital cards during the first 3 turns of a Deployment.' }
  ];

  var PREVIOUS_OPERATION_OPTIONS = [
    { key: 'repair-unit', label: '[REPAIR UNIT]', desc: 'You were built to restore, patch, and keep fractured systems functional under pressure.', bonus_tag: 'Technician', bonus_desc: 'Utility items restore +50% more Durability.', flaw_tag: 'Heavy-Handed', flaw_desc: '-5% success rate on Acrobatics and Stealth tests.' },
    { key: 'void-runner', label: '[VOID-RUNNER]', desc: 'Your primary function was speed, transit, and surviving dangerous movement through unstable space.', bonus_tag: 'Agile', bonus_desc: 'Playing a Dodge card allows drawing an extra card on the next turn.', flaw_tag: 'Light-Frame', flaw_desc: 'Starting Max HP -1.' },
    { key: 'archive-analyst', label: '[ARCHIVE ANALYST]', desc: 'You processed forbidden knowledge, recovered fragmented data, and interpreted arcane or scientific records.', bonus_tag: 'Researcher', bonus_desc: '+5% success rate on Arcana and Science tests.', flaw_tag: 'Code-Bound', flaw_desc: 'Cannot equip two-handed weapons.' },
    { key: 'enforcer', label: '[ENFORCER]', desc: 'You existed to apply force, hold the line, and suppress escalation when systems failed.', bonus_tag: 'Unyielding', bonus_desc: 'Ignore the first Pressure or Panic card encountered in every combat.', flaw_tag: 'Loud-Footsteps', flaw_desc: 'Cannot obtain "First Strike" bonus from stealth.' }
  ];

  var SYNC_CRISIS_OPTIONS = [
    { key: 'system-stabilizer', label: '[SYSTEM STABILIZER]', desc: 'You answered the first touch of Entropy by reinforcing the pattern and learning from the breach.', bonus_tag: 'Glitch-Learner', bonus_desc: '+10% global XP gain.', flaw_tag: 'System-Spasm', flaw_desc: 'Every 10 turns, one random card from your hand is discarded/burned.' },
    { key: 'aggressive-response', label: '[AGGRESSIVE RESPONSE]', desc: 'You met the Fray by pushing back harder, turning survival into pressure and violence.', bonus_tag: 'Striker', bonus_desc: 'Every played Attack card generates +1 additional XP for itself.', flaw_tag: 'Reckless', flaw_desc: 'On failure in a Physical test, lose an additional 1 Durability on armor.' },
    { key: 'data-ghost-adaptation', label: '[DATA-GHOST ADAPTATION]', desc: 'You adapted by becoming difficult to hold, half-solid in action and difficult to disrupt.', bonus_tag: 'Iron-Grip', bonus_desc: 'Your physical attack cards cannot be countered.', flaw_tag: 'Feedback-Vulnerability', flaw_desc: 'Receive double damage from enemies with the #Hacker or #Digital tag.' },
    { key: 'sensory-overload', label: '[SENSORY OVERLOAD]', desc: 'You survived by embracing the flood of input, turning collapse into unstable power.', bonus_tag: 'Wild-Card', bonus_desc: 'Critical successes deal triple damage instead of double.', flaw_tag: 'Magnetized', flaw_desc: 'In locations tagged #High-Technology, suffer -5% to all tests.' }
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
      .replace(/"/g, '&quot;')
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

  function twNormalizeMediaUrl(url) {
    url = String(url || '').trim();
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    if (url.indexOf('/wp-content/uploads/') === 0) return TW_SITE_BASE + url;
    return TW_UPLOADS_BASE + url.replace(/^\/+/, '');
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

  function buildTagsHtml(tags) {
    tags = Array.isArray(tags) ? tags.filter(Boolean) : [];
    if (!tags.length) return '';
    return '<div class="tw-race-tags">' + tags.map(function (tag) {
      return '<span class="tw-race-tag">' + esc(tag) + '</span>';
    }).join('') + '</div>';
  }

  function buildImageHtml(url, alt, cls, placeholderCls) {
    var normalizedUrl = twNormalizeMediaUrl(url);
    if (normalizedUrl) {
      return '<div class="' + esc(cls) + '"><img src="' + esc(normalizedUrl) + '" alt="' + esc(alt || '') + '" loading="lazy"></div>';
    }
    return '<div class="' + esc(cls) + '"><div class="' + esc(placeholderCls || (cls + '--placeholder')) + '">◎</div></div>';
  }

  function buildRaceCard(item) {
    var img = item.image_url || item.img_url || '';
    return '' +
      '<button type="button" class="tw-grid-card tw-race-card" data-race-id="' + esc(item.id) + '" aria-pressed="false">' +
        buildImageHtml(img, item.name, 'tw-race-img', 'tw-race-img--placeholder') +
        '<div class="tw-race-body">' +
          '<h3 class="tw-race-name">' + esc(item.name) + '</h3>' +
          buildTagsHtml(item.tags || []) +
          '<span class="tw-race-select-hint">Select</span>' +
        '</div>' +
      '</button>';
  }

  function buildSubraceCard(item) {
    var img = item.image_url || item.img_url || '';
    return '' +
      '<button type="button" class="tw-grid-card tw-race-card tw-subrace-card" data-subrace-id="' + esc(item.id) + '" aria-pressed="false">' +
        buildImageHtml(img, item.name, 'tw-race-img', 'tw-race-img--placeholder') +
        '<div class="tw-race-body">' +
          '<h3 class="tw-race-name">' + esc(item.name) + '</h3>' +
          buildTagsHtml(item.tags || []) +
          '<span class="tw-race-select-hint">Select</span>' +
        '</div>' +
      '</button>';
  }

  function buildClassCard(cls) {
    var img = cls.image_url || cls.img_url || '';
    return '' +
      '<button type="button" class="tw-class-card" data-char-class="' + esc(cls.id) + '" data-class-name="' + esc(cls.name) + '" aria-pressed="false">' +
        buildImageHtml(img, cls.name, 'tw-class-card__img-wrap', 'tw-class-card__img-wrap--placeholder') +
        '<div class="tw-class-card__body">' +
          '<h3 class="tw-class-card__name">' + esc(cls.name) + '</h3>' +
          buildTagsHtml(cls.tags || []) +
          '<span class="tw-race-select-hint">Select</span>' +
        '</div>' +
      '</button>';
  }

  function buildSkillCard(skill) {
    var img = skill.image_url || skill.img_url || '';
    return '' +
      '<button type="button" class="tw-skill-card tw-grid-card" data-skill-id="' + esc(skill.id) + '" data-skill-name="' + esc(skill.name) + '" aria-pressed="false">' +
        buildImageHtml(img, skill.name, 'tw-race-img', 'tw-race-img--placeholder') +
        '<div class="tw-race-body">' +
          '<h3 class="tw-race-name">' + esc(skill.name) + '</h3>' +
          '<p class="tw-race-desc">' + esc(skill.description || '') + '</p>' +
        '</div>' +
      '</button>';
  }

  function buildPackageCard(pkg) {
    var img = pkg.image_url || pkg.img_url || '';
    var tags = Array.isArray(pkg.tags) ? pkg.tags : [];
    var items = Array.isArray(pkg.items) ? pkg.items : [];
    var itemsPreview = items.length
      ? '<div class="tw-package-items">' + items.map(function (it) {
          return '<span class="tw-race-tag">' + esc(it) + '</span>';
        }).join('') + '</div>'
      : '';

    return '' +
      '<button type="button" class="tw-package-card tw-grid-card" data-package-id="' + esc(pkg.id) + '" data-package-name="' + esc(pkg.name) + '" aria-pressed="false">' +
        buildImageHtml(img, pkg.name, 'tw-race-img', 'tw-race-img--placeholder') +
        '<div class="tw-race-body">' +
          '<h3 class="tw-race-name">' + esc(pkg.name) + '</h3>' +
          (pkg.description ? '<p class="tw-race-desc">' + esc(pkg.description) + '</p>' : '') +
          (pkg.base_armor !== undefined && pkg.base_armor !== null && pkg.base_armor !== '' ? '<span class="tw-race-tag">Armor ' + esc(pkg.base_armor) + '</span>' : '') +
          buildTagsHtml(tags) +
          itemsPreview +
          '<span class="tw-race-select-hint">Select</span>' +
        '</div>' +
      '</button>';
  }

  function choiceByKey(type, key) {
    var list = [];
    if (type === 'data_origin') list = DATA_ORIGIN_OPTIONS;
    if (type === 'previous_operation') list = PREVIOUS_OPERATION_OPTIONS;
    if (type === 'sync_crisis') list = SYNC_CRISIS_OPTIONS;
    return list.filter(function (item) { return item.key === key; })[0] || null;
  }

  function buildLoreCard(item, type) {
    return '' +
      '<button type="button" class="tw-lore-card tw-grid-card" data-choice-type="' + esc(type) + '" data-choice-key="' + esc(item.key) + '" aria-pressed="false">' +
        '<div class="tw-race-body">' +
          '<h3 class="tw-race-name">' + esc(item.label) + '</h3>' +
          '<p class="tw-race-desc">' + esc(item.desc || '') + '</p>' +
          '<div class="tw-lore-card__effects">' +
            '<div class="tw-lore-card__effect"><strong>' + esc(item.bonus_tag) + ':</strong> ' + esc(item.bonus_desc) + '</div>' +
            '<div class="tw-lore-card__effect"><strong>' + esc(item.flaw_tag) + ':</strong> ' + esc(item.flaw_desc) + '</div>' +
          '</div>' +
          '<span class="tw-race-select-hint">Select</span>' +
        '</div>' +
      '</button>';
  }

  function setStatus(msg, isError) {
    var statusEl = document.getElementById('tw-char-status');
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.classList.toggle('tw-char-status--error', !!isError);
  }

  function clearStepError(step) {
    if (!step) return;
    var box = q(step, '.tw-step-error');
    if (!box) return;
    box.classList.remove('visible', 'tw-step-error--shake');
    var msg = q(box, '.tw-step-error__msg');
    if (msg) msg.textContent = '';
  }

  function showStepError(step, msg) {
    if (!step) return;
    var box = q(step, '.tw-step-error');
    if (!box) return;
    var msgEl = q(box, '.tw-step-error__msg');
    if (msgEl) msgEl.textContent = msg || 'Validation error.';
    box.classList.add('visible');
    box.classList.remove('tw-step-error--shake');
    void box.offsetWidth;
    box.classList.add('tw-step-error--shake');
    NW_SFX.error();
  }

  function showSubraceSection(wrapper) {
    var section = q(wrapper, '#tw-subrace-section');
    if (!section) return;
    section.hidden = false;
    section.style.display = '';
  }

  function hideSubraceSection(wrapper) {
    var section = q(wrapper, '#tw-subrace-section');
    if (!section) return;
    section.hidden = true;
    section.style.display = 'none';
  }

  function resetSubraceState(wrapper) {
    formState.subrace = '';
    formState.subrace_label = '';
    var grid = q(wrapper, '#tw-subrace-grid');
    if (grid) grid.innerHTML = '';
    hideSubraceSection(wrapper);
  }

  function selectedClassTag(wrapper) {
    return formState.character_class || '';
  }

  function recomputeBackstoryTags() {
    var tags = [];
    var origin = choiceByKey('data_origin', formState.data_origin);
    var operation = choiceByKey('previous_operation', formState.previous_operation);
    var crisis = choiceByKey('sync_crisis', formState.sync_crisis);

    [origin, operation, crisis].forEach(function (item) {
      if (!item) return;
      if (item.bonus_tag) tags.push(item.bonus_tag);
      if (item.flaw_tag) tags.push(item.flaw_tag);
    });

    formState.backstory_tags = tags;
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
        '<div class="tw-spinner-ring--2"></div>' +
        '<p class="tw-spinner-text">' + esc(title) + '</p>' +
        '<p class="tw-spinner-sub">' + esc(subtitle) + '</p>' +
      '</div>';

    document.body.appendChild(el);

    return {
      show: function () { el.classList.add('active'); },
      hide: function () { el.classList.remove('active'); }
    };
  }

  function fetchRaceGrid(wrapper) {
    var grid = q(wrapper, '#tw-race-grid');
    if (!grid || grid.dataset.rendered) return;

    grid.innerHTML = '<p class="tw-loading-state"><span class="tw-loading-dot"></span>SCANNING RACE DATABASE…</p>';

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
    grid.innerHTML = '<p class="tw-loading-state"><span class="tw-loading-dot"></span>SCANNING SUBRACE DATA…</p>';

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

    grid.innerHTML = '<p class="tw-loading-state"><span class="tw-loading-dot"></span>SCANNING CLASS MATRIX…</p>';

    fetchPost('neoweaver_get_classes', {})
      .then(function (res) {
        grid.innerHTML = hasRows(res) ? res.data.map(buildClassCard).join('') : '<p class="tw-empty-state">No classes available.</p>';
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<p class="tw-error-msg">ERROR: Class data unavailable.</p>';
      });
  }

  function updateSkillCounter(wrapper) {
    var counter = q(wrapper, '#tw-skill-counter');
    if (counter) counter.textContent = formState.skills.length + ' / ' + (formState.skill_limit || 5) + ' skills';
  }

  function fetchSkillGrid(wrapper) {
    var grid = q(wrapper, '#tw-skill-grid');
    if (!grid) return;

    if (grid.dataset.rendered) {
      updateSkillCounter(wrapper);
      restoreSelections(wrapper);
      return;
    }

    grid.innerHTML = '<p class="tw-loading-state"><span class="tw-loading-dot"></span>SCANNING SKILL ARCHIVE…</p>';

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
            '<section class="tw-skill-category">' +
              '<h3 class="tw-skill-cat-label">' + esc(cat) + '</h3>' +
              '<div class="tw-skill-cat-grid">' +
                byCat[cat].map(buildSkillCard).join('') +
              '</div>' +
            '</section>';
        }).join('');

        grid.innerHTML = html;
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

    grid.innerHTML = '<p class="tw-loading-state"><span class="tw-loading-dot"></span>SCANNING STARTING PACKAGE…</p>';

    fetchPost('neoweaver_get_packages', { class_tag: classTag })
      .then(function (res) {
        grid.innerHTML = hasRows(res) ? res.data.map(buildPackageCard).join('') : '<p class="tw-empty-state">No starting packages available for this class.</p>';
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

  function renderAvatarGallery(wrapper) {
    var avatarGalleryEl = q(wrapper, '#tw-avatar-gallery');
    var avatarPreviewImg = q(wrapper, '#tw-avatar-img');
    var avatarSelectedWrap = q(wrapper, '#tw-avatar-selected');
    var avatarPreview = q(wrapper, '#tw-avatar-preview');
    var avatarInput = q(wrapper, '#tw-char-avatar');

    if (!avatarGalleryEl) return;

    avatarGalleryEl.innerHTML = TW_AVATAR_GALLERY.map(function (item) {
      var normalizedUrl = twNormalizeMediaUrl(item.url);
      return '' +
        '<button type="button" class="tw-avatar-option" data-avatar-url="' + esc(normalizedUrl) + '" aria-pressed="' + (formState.avatar_url === normalizedUrl ? 'true' : 'false') + '">' +
          '<img src="' + esc(normalizedUrl) + '" alt="' + esc(item.name || 'Avatar option') + '" loading="lazy">' +
          '<span>' + esc(item.name || 'Avatar') + '</span>' +
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
        if (avatarPreview) avatarPreview.style.display = 'none';
        if (avatarSelectedWrap) avatarSelectedWrap.style.display = 'grid';
        if (avatarInput) avatarInput.value = '';

        qa(avatarGalleryEl, '.tw-avatar-option').forEach(function (x) {
          var selected = x === btn;
          x.classList.toggle('selected', selected);
          x.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });

        updateSummary(wrapper);
        NW_SFX.select();
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

      if (imgEl) {
        imgEl.src = ev.target.result;
        imgEl.alt = file.name || 'Uploaded avatar';
      }
      if (preview) preview.style.display = 'none';
      if (selected) selected.style.display = 'grid';
      if (gallery) qa(gallery, '.tw-avatar-option').forEach(function (x) {
        x.classList.remove('selected');
        x.setAttribute('aria-pressed', 'false');
      });

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
    if (gallery) qa(gallery, '.tw-avatar-option').forEach(function (x) {
      x.classList.remove('selected');
      x.setAttribute('aria-pressed', 'false');
    });

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

  function renderLoreChoices(wrapper) {
    var originGrid = q(wrapper, '#tw-origin-grid');
    var operationGrid = q(wrapper, '#tw-operation-grid');
    var crisisGrid = q(wrapper, '#tw-crisis-grid');

    if (originGrid && !originGrid.dataset.rendered) {
      originGrid.innerHTML = DATA_ORIGIN_OPTIONS.map(function (item) {
        return buildLoreCard(item, 'data_origin');
      }).join('');
      originGrid.dataset.rendered = '1';
    }

    if (operationGrid && !operationGrid.dataset.rendered) {
      operationGrid.innerHTML = PREVIOUS_OPERATION_OPTIONS.map(function (item) {
        return buildLoreCard(item, 'previous_operation');
      }).join('');
      operationGrid.dataset.rendered = '1';
    }

    if (crisisGrid && !crisisGrid.dataset.rendered) {
      crisisGrid.innerHTML = SYNC_CRISIS_OPTIONS.map(function (item) {
        return buildLoreCard(item, 'sync_crisis');
      }).join('');
      crisisGrid.dataset.rendered = '1';
    }

    restoreSelections(wrapper);
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

    if (idx === 0) return validateIdentityStep(wrapper, step);

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
    if (phase === 'RACE PROTOCOL') fetchRaceGrid(wrapper);
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

  function submitCharacter(wrapper, spinner) {
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
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var wait = Math.max(0, 1200 - (Date.now() - t0));
        setTimeout(function () {
          spinner.hide();

          if (res && res.success) {
            setStatus('Agent profile created. Welcome to the Grid.', false);
            NW_SFX.deploy();
            wrapper.innerHTML =
              '<div class="tw-success">' +
                '<p class="tw-success__msg">✓ ' + esc((res.data && res.data.message) || 'Character created!') + '</p>' +
                ((res.data && res.data.redirect)
                  ? '<a class="tw-btn tw-btn--primary" href="' + esc(res.data.redirect) + '">Enter the Grid</a>'
                  : '') +
              '</div>';
            return;
          }

          setStatus((res && res.data && res.data.message) ? res.data.message : 'ERROR: Character creation failed.', true);
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create character';
          }
          NW_SFX.error();
        }, wait);
      })
      .catch(function () {
        spinner.hide();
        setStatus('ERROR: Network failure while creating character.', true);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Create character';
        }
        NW_SFX.error();
      });
  }

  function bindEvents(wrapper, steps) {
    var current = 0;
    var spinner = makeSpinner('tw-char-spinner', 'Synchronizing', 'Writing Field Agent profile into the Grid…');

    wrapper.addEventListener('click', function (ev) {
      var nextBtn = resolveNextButton(ev.target);
      var prevBtn = resolvePrevButton(ev.target);

      if (nextBtn) {
        ev.preventDefault();
        if (!validateStep(wrapper, steps, current)) return;
        if (current < steps.length - 1) {
          current += 1;
          showStep(wrapper, steps, current);
          NW_SFX.nav();
        }
        return;
      }

      if (prevBtn) {
        ev.preventDefault();
        if (current > 0) {
          current -= 1;
          showStep(wrapper, steps, current);
          NW_SFX.back();
        }
        return;
      }

      var raceCard = ev.target.closest('.tw-race-card:not(.tw-subrace-card)');
      if (raceCard) {
        formState.race = raceCard.dataset.raceId || '';
        formState.race_label = q(raceCard, '.tw-race-name') ? q(raceCard, '.tw-race-name').textContent.trim() : formState.race;
        formState.subrace = '';
        formState.subrace_label = '';
        fetchSubraces(wrapper, formState.race);
        restoreSelections(wrapper);
        updateSummary(wrapper);
        NW_SFX.select();
        return;
      }

      var subraceCard = ev.target.closest('.tw-subrace-card');
      if (subraceCard) {
        formState.subrace = subraceCard.dataset.subraceId || '';
        formState.subrace_label = q(subraceCard, '.tw-race-name') ? q(subraceCard, '.tw-race-name').textContent.trim() : formState.subrace;
        restoreSelections(wrapper);
        updateSummary(wrapper);
        NW_SFX.select();
        return;
      }

      var classCard = ev.target.closest('.tw-class-card');
      if (classCard) {
        formState.character_class = classCard.dataset.charClass || '';
        formState.class_label = classCard.dataset.className || '';
        formState.starting_package_id = '';
        formState.starting_package_label = '';
        var pkgGrid = q(wrapper, '#tw-package-grid');
        if (pkgGrid) pkgGrid.dataset.rendered = '';
        restoreSelections(wrapper);
        updateSummary(wrapper);
        NW_SFX.select();
        return;
      }

      var skillCard = ev.target.closest('.tw-skill-card');
      if (skillCard) {
        var skillId = skillCard.dataset.skillId;
        var idx = formState.skills.indexOf(skillId);

        if (idx === -1) {
          if (formState.skills.length >= (formState.skill_limit || 5)) {
            setStatus('ERROR: Skill limit reached for this class.', true);
            NW_SFX.error();
            return;
          }
          formState.skills.push(skillId);
        } else {
          formState.skills.splice(idx, 1);
        }

        restoreSelections(wrapper);
        updateSummary(wrapper);
        NW_SFX.select();
        return;
      }

      var packageCard = ev.target.closest('.tw-package-card');
      if (packageCard) {
        formState.starting_package_id = packageCard.dataset.packageId || '';
        formState.starting_package_label = packageCard.dataset.packageName || '';
        restoreSelections(wrapper);
        updateSummary(wrapper);
        NW_SFX.select();
        return;
      }

      var loreCard = ev.target.closest('.tw-lore-card');
      if (loreCard) {
        var type = loreCard.dataset.choiceType;
        var key = loreCard.dataset.choiceKey;
        if (type) {
          formState[type] = key || '';
          recomputeBackstoryTags();
          restoreSelections(wrapper);
          updateSummary(wrapper);
          NW_SFX.select();
        }
        return;
      }

      var summaryEdit = ev.target.closest('.tw-summary-edit');
      if (summaryEdit) {
        var targetStep = parseInt(summaryEdit.dataset.editStep, 10);
        if (!isNaN(targetStep) && targetStep >= 0 && targetStep < steps.length) {
          current = targetStep;
          showStep(wrapper, steps, current);
          NW_SFX.nav();
        }
        return;
      }

      var submitBtn = ev.target.closest('#tw-char-submit');
      if (submitBtn) {
        ev.preventDefault();
        if (!validateStep(wrapper, steps, current)) return;
        submitCharacter(wrapper, spinner);
        return;
      }

      var avatarTrigger = ev.target.closest('#tw-avatar-trigger');
      if (avatarTrigger) {
        ev.preventDefault();
        var input = q(wrapper, '#tw-char-avatar');
        if (input) input.click();
        return;
      }

      var avatarClear = ev.target.closest('#tw-avatar-clear');
      if (avatarClear) {
        ev.preventDefault();
        clearAvatar(wrapper);
        return;
      }

      var attrBtn = ev.target.closest('.tw-attr-btn');
      if (attrBtn) {
        var key = attrBtn.dataset.attrKey;
        var action = attrBtn.dataset.attrAction;
        if (!key || ATTR_KEYS.indexOf(key) === -1) return;

        var currentVal = formState['attr_' + key] || ATTR_MIN;
        var used = ATTR_KEYS.reduce(function (sum, item) {
          return sum + (formState['attr_' + item] || ATTR_MIN);
        }, 0);

        if (action === 'plus') {
          if (currentVal >= ATTR_MAX) return;
          if (used >= ATTR_POOL) {
            setStatus('ERROR: No attribute points remaining.', true);
            NW_SFX.error();
            return;
          }
          formState['attr_' + key] = currentVal + 1;
        }

        if (action === 'minus') {
          if (currentVal <= ATTR_MIN) return;
          formState['attr_' + key] = currentVal - 1;
        }

        renderAttrDisplay(wrapper);
        updateSummary(wrapper);
        NW_SFX.select();
      }
    });

    var avatarInput = q(wrapper, '#tw-char-avatar');
    if (avatarInput) {
      avatarInput.addEventListener('change', function () {
        if (avatarInput.files && avatarInput.files[0]) handleAvatarFile(wrapper, avatarInput.files[0]);
      });
    }

    var bioEl = q(wrapper, '#tw-char-bio');
    if (bioEl) {
      bioEl.addEventListener('input', function () {
        formState.bio = bioEl.value.trim();
        updateSummary(wrapper);
      });
    }

    var nameEl = q(wrapper, '#tw-char-name');
    if (nameEl) {
      nameEl.addEventListener('input', function () {
        formState.character_name = nameEl.value.trim();
        updateSummary(wrapper);
      });
    }

    qa(wrapper, '.tw-pronoun-radio').forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (radio.checked) {
          if (radio.value === 'custom') {
            var customEl = q(wrapper, '#tw-char-pronouns-custom');
            formState.pronouns = customEl && customEl.value.trim() ? customEl.value.trim() : 'custom';
          } else {
            formState.pronouns = radio.value;
          }
          updateSummary(wrapper);
        }
      });
    });

    var customPronouns = q(wrapper, '#tw-char-pronouns-custom');
    if (customPronouns) {
      customPronouns.addEventListener('input', function () {
        var customRadio = q(wrapper, '.tw-pronoun-radio[value="custom"]');
        if (customRadio && customRadio.checked) {
          formState.pronouns = customPronouns.value.trim() || 'custom';
          updateSummary(wrapper);
        }
      });
    }

    var uploadBox = q(wrapper, '#tw-upload-box');
    if (uploadBox) {
      ['dragenter', 'dragover'].forEach(function (evtName) {
        uploadBox.addEventListener(evtName, function (ev) {
          ev.preventDefault();
          uploadBox.classList.add('tw-upload-box--drag');
        });
      });

      ['dragleave', 'dragend', 'drop'].forEach(function (evtName) {
        uploadBox.addEventListener(evtName, function (ev) {
          ev.preventDefault();
          uploadBox.classList.remove('tw-upload-box--drag');
        });
      });

      uploadBox.addEventListener('drop', function (ev) {
        var file = ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0];
        if (file) handleAvatarFile(wrapper, file);
      });
    }

    renderAttrDisplay(wrapper);
    updateSummary(wrapper);
    showStep(wrapper, steps, current);
  }

  function init() {
    var wrapper = document.getElementById('tw-char-creator-wrapper');
    if (!wrapper) return;

    var steps = qa(wrapper, '.tw-step');
    if (!steps.length) return;

    bindEvents(wrapper, steps);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
