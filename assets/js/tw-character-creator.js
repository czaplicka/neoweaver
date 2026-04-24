(function () {
  'use strict';

  var NW_SFX = (function () {
    var ctx = null;

    function getCtx() {
      if (!ctx) {
        ctx = new (window.AudioContext || window.webkitAudioContext)();
      }
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
      nav: function () {
        beep(660, 'square', 0.06, 0.15);
      },
      select: function () {
        beep(880, 'sine', 0.10, 0.20);
      },
      back: function () {
        beep(330, 'sawtooth', 0.08, 0.12);
      },
      deploy: function () {
        beep(440, 'square', 0.10, 0.20);
        setTimeout(function () {
          beep(660, 'sine', 0.15, 0.25);
        }, 120);
      },
      error: function () {
        beep(180, 'sawtooth', 0.18, 0.20);
      },
      preset: function () {
        beep(740, 'sine', 0.12, 0.22);
      }
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
  var TW_AVATAR_GALLERY = Array.isArray(cfg.avatar_gallery) && cfg.avatar_gallery.length ? cfg.avatar_gallery : [
    { id: 'avatar-1', name: 'Avatar', url: TW_UPLOADS_BASE + 'Avatar.svg' },
    { id: 'avatar-2', name: 'Avatar 2', url: TW_UPLOADS_BASE + 'Avatar-1.svg' }
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

  function normalizeTag(tag) {
    if (!tag) return '';
    if (typeof tag === 'string') return tag.trim();
    if (typeof tag === 'object') {
      if (typeof tag.name === 'string') return tag.name.trim();
      if (typeof tag.label === 'string') return tag.label.trim();
      if (typeof tag.tag === 'string') return tag.tag.trim();
    }
    return '';
  }

  function uniqueStrings(list) {
    var out = [];
    (list || []).forEach(function (item) {
      item = String(item || '').trim();
      if (item && out.indexOf(item) === -1) {
        out.push(item);
      }
    });
    return out;
  }

  function buildTagsHtml(tags, cls) {
    tags = Array.isArray(tags) ? uniqueStrings(tags.map(normalizeTag).filter(Boolean)) : [];
    if (!tags.length) return '';
    cls = cls || 'tw-card-tag';
    return '<div class="tw-card-tags">' + tags.map(function (tag) {
      return '<span class="' + esc(cls) + '">' + esc(tag) + '</span>';
    }).join('') + '</div>';
  }

  function buildImageHtml(url, alt, cls, placeholderCls, placeholderLabel) {
    var normalizedUrl = twNormalizeMediaUrl(url);
    cls = cls || 'tw-card-media-img';
    placeholderCls = placeholderCls || 'tw-card-media-placeholder';
    placeholderLabel = placeholderLabel || 'NO SIGNAL';

    if (normalizedUrl) {
      return (
        '<div class="tw-card-media">' +
          '<img class="' + esc(cls) + '" src="' + esc(normalizedUrl) + '" alt="' + esc(alt || '') + '" loading="lazy" decoding="async">' +
        '</div>'
      );
    }

    return (
      '<div class="tw-card-media tw-card-media--placeholder">' +
        '<div class="' + esc(placeholderCls) + '">' + esc(placeholderLabel) + '</div>' +
      '</div>'
    );
  }

  function getLoreConfig(type) {
    if (type === 'data_origin') return DATA_ORIGIN_OPTIONS;
    if (type === 'previous_operation') return PREVIOUS_OPERATION_OPTIONS;
    if (type === 'sync_crisis') return SYNC_CRISIS_OPTIONS;
    return [];
  }

  function choiceByKey(type, key) {
    var options = getLoreConfig(type);
    for (var i = 0; i < options.length; i += 1) {
      if (options[i].key === key) return options[i];
    }
    return null;
  }

  function recomputeBackstoryTags() {
    var tags = [];
    var origin = choiceByKey('data_origin', formState.data_origin);
    var operation = choiceByKey('previous_operation', formState.previous_operation);
    var crisis = choiceByKey('sync_crisis', formState.sync_crisis);

    [origin, operation, crisis].forEach(function (entry) {
      if (!entry) return;
      if (entry.bonus_tag) tags.push(entry.bonus_tag);
      if (entry.flaw_tag) tags.push(entry.flaw_tag);
    });

    formState.backstory_tags = uniqueStrings(tags);
  }

  function setStatus(message, isError) {
    qa(document, '.tw-char-status').forEach(function (el) {
      el.textContent = message || '';
      el.classList.toggle('is-error', !!isError);
      el.classList.toggle('is-visible', !!message);
    });
  }

  function showStepError(step, message) {
    if (!step) return;
    var box = q(step, '.tw-step-error');
    if (box) {
      box.textContent = message || '';
      box.classList.add('is-visible');
    }
  }

  function clearStepError(step) {
    if (!step) return;
    var box = q(step, '.tw-step-error');
    if (box) {
      box.textContent = '';
      box.classList.remove('is-visible');
    }
  }

  function clearAllStepErrors(wrapper) {
    qa(wrapper, '.tw-step-error').forEach(function (box) {
      box.textContent = '';
      box.classList.remove('is-visible');
    });
  }

  function selectedClassTag() {
    return formState.character_class || '';
  }

  function resetSubraceState(wrapper) {
    formState.subrace = '';
    formState.subrace_label = '';
    syncSingleSelection(wrapper, '.tw-subrace-card', '', 'subraceId');
  }

  function showSubraceSection(wrapper) {
    var section = q(wrapper, '#tw-subrace-section');
    if (section) {
      section.hidden = false;
      section.style.display = '';
    }
  }

  function hideSubraceSection(wrapper) {
    var section = q(wrapper, '#tw-subrace-section');
    if (section) {
      section.hidden = true;
      section.style.display = 'none';
    }
  }

  function resetPackageState(wrapper) {
    formState.starting_package_id = '';
    formState.starting_package_label = '';
    var grid = q(wrapper, '#tw-package-grid');
    if (grid) {
      grid.dataset.rendered = '';
      grid.innerHTML = '';
    }
  }

  function buildRaceCard(item) {
    item = item || {};

    var img = item.img_url || item.img || '';
    var title = item.label || item.name || 'Unknown race';
    var desc = item.desc || item.description || '';
    var bonus = item.bonus || '';
    var tagsHtml = buildTagsHtml(item.tags || [], 'tw-race-tag');

    return (
      '<button type="button" class="tw-race-card" data-race-id="' + esc(item.id || item.key || '') + '" aria-pressed="false">' +
        buildImageHtml(img, title, 'tw-race-card__img', 'tw-race-card__placeholder', 'RACE') +
        '<div class="tw-race-card__body">' +
          '<h3 class="tw-race-card__title">' + esc(title) + '</h3>' +
          (tagsHtml ? tagsHtml : '') +
          (bonus ? '<p class="tw-race-card__bonus">' + esc(bonus) + '</p>' : '') +
          (desc ? '<p class="tw-race-card__desc">' + esc(desc) + '</p>' : '') +
        '</div>' +
      '</button>'
    );
  }

  function buildSubraceCard(item) {
    item = item || {};

    var img = item.img_url || item.img || '';
    var title = item.label || item.name || 'Unknown subrace';
    var desc = item.desc || item.description || '';
    var bonus = item.bonus || '';
    var tagsHtml = buildTagsHtml(item.tags || [], 'tw-race-tag');

    return (
      '<button type="button" class="tw-race-card tw-subrace-card" data-subrace-id="' + esc(item.id || item.key || '') + '" aria-pressed="false">' +
        buildImageHtml(img, title, 'tw-race-card__img', 'tw-race-card__placeholder', 'SUBRACE') +
        '<div class="tw-race-card__body">' +
          '<h3 class="tw-race-card__title">' + esc(title) + '</h3>' +
          (tagsHtml ? tagsHtml : '') +
          (bonus ? '<p class="tw-race-card__bonus">' + esc(bonus) + '</p>' : '') +
          (desc ? '<p class="tw-race-card__desc">' + esc(desc) + '</p>' : '') +
        '</div>' +
      '</button>'
    );
  }

  function buildClassCard(item) {
    item = item || {};

    var title = item.name || 'Unknown class';
    var desc = item.description || '';
    var img = item.img_url || item.icon_slug || '';
    var tagsHtml = buildTagsHtml(item.tags || [], 'tw-class-tag');
    var skillLimit = parseInt(item.skill_limit, 10);
    if (!skillLimit || skillLimit < 1) skillLimit = 5;

    return (
      '<button type="button" class="tw-class-card" data-char-class="' + esc(item.id || '') + '" data-class-name="' + esc(title) + '" data-skill-limit="' + esc(skillLimit) + '" aria-pressed="false">' +
        buildImageHtml(img, title, 'tw-class-card__img', 'tw-class-card__placeholder', 'CLASS') +
        '<div class="tw-class-card__body">' +
          '<div class="tw-class-card__head">' +
            '<h3 class="tw-class-card__title">' + esc(title) + '</h3>' +
            '<span class="tw-class-card__limit">' + esc(skillLimit) + ' skills</span>' +
          '</div>' +
          (tagsHtml ? tagsHtml : '') +
          (desc ? '<p class="tw-class-card__desc">' + esc(desc) + '</p>' : '') +
        '</div>' +
      '</button>'
    );
  }

  function buildSkillCard(item) {
    item = item || {};

    var title = item.name || 'Unknown skill';
    var desc = item.description || '';
    var img = item.img_url || '';
    var category = item.category || 'Other';
    var tags = [];
    if (item.category) tags.push(item.category);
    if (Array.isArray(item.linked_attributes)) {
      tags = tags.concat(item.linked_attributes);
    }
    if (Array.isArray(item.tags)) {
      tags = tags.concat(item.tags);
    }

    var tagsHtml = buildTagsHtml(tags, 'tw-skill-tag');

    return (
      '<button type="button" class="tw-skill-card" data-skill-id="' + esc(item.id || '') + '" data-skill-name="' + esc(title) + '" aria-pressed="false">' +
        buildImageHtml(img, title, 'tw-skill-card__img', 'tw-skill-card__placeholder', category) +
        '<div class="tw-skill-card__body">' +
          '<h3 class="tw-skill-card__title">' + esc(title) + '</h3>' +
          (tagsHtml ? tagsHtml : '') +
          (desc ? '<p class="tw-skill-card__desc">' + esc(desc) + '</p>' : '') +
        '</div>' +
      '</button>'
    );
  }

  function buildSkillCategoryBlock(category, items) {
    return (
      '<section class="tw-skill-category">' +
        '<header class="tw-skill-category__header">' +
          '<h3 class="tw-skill-category__title">' + esc(category) + '</h3>' +
        '</header>' +
        '<div class="tw-skill-category__grid">' +
          items.map(buildSkillCard).join('') +
        '</div>' +
      '</section>'
    );
  }

  function buildPackageCard(item) {
    item = item || {};

    var title = item.name || item.package_name || 'Unknown package';
    var desc = item.description || '';
    var items = Array.isArray(item.items) ? item.items : (Array.isArray(item.items_list) ? item.items_list : []);
    var tagsHtml = buildTagsHtml(item.compatibility_tags || [], 'tw-package-tag');

    return (
      '<button type="button" class="tw-package-card" data-package-id="' + esc(item.id || '') + '" data-package-name="' + esc(title) + '" aria-pressed="false">' +
        '<div class="tw-package-card__body">' +
          '<div class="tw-package-card__head">' +
            '<h3 class="tw-package-card__title">' + esc(title) + '</h3>' +
            '<span class="tw-package-card__armor">Armor ' + esc(item.base_armor || 0) + '</span>' +
          '</div>' +
          (tagsHtml ? tagsHtml : '') +
          (desc ? '<p class="tw-package-card__desc">' + esc(desc) + '</p>' : '') +
          (items.length ? '<p class="tw-package-card__items">' + esc(items.join(' · ')) + '</p>' : '') +
        '</div>' +
      '</button>'
    );
  }

  function buildLoreCard(item, type) {
    return (
      '<button type="button" class="tw-lore-card" data-choice-type="' + esc(type) + '" data-choice-key="' + esc(item.key) + '" aria-pressed="false">' +
        '<div class="tw-lore-card__body">' +
          '<h3 class="tw-lore-card__title">' + esc(item.label) + '</h3>' +
          '<div class="tw-lore-card__meta">' +
            '<span class="tw-lore-chip tw-lore-chip--bonus">' + esc(item.bonus_tag) + '</span>' +
            '<span class="tw-lore-chip tw-lore-chip--flaw">' + esc(item.flaw_tag) + '</span>' +
          '</div>' +
          '<p class="tw-lore-card__desc">' + esc(item.desc) + '</p>' +
          '<p class="tw-lore-card__sub tw-lore-card__sub--bonus"><strong>Bonus:</strong> ' + esc(item.bonus_desc) + '</p>' +
          '<p class="tw-lore-card__sub tw-lore-card__sub--flaw"><strong>Flaw:</strong> ' + esc(item.flaw_desc) + '</p>' +
        '</div>' +
      '</button>'
    );
  }

  function fetchRaceGrid(wrapper) {
    var grid = q(wrapper, '#tw-race-grid');
    if (!grid || grid.dataset.rendered) return;

    grid.innerHTML = '<div class="tw-loading">SCANNING RACE DATABASE…</div>';

    fetchPost('neoweaver_get_races', {})
      .then(function (res) {
        var rows = hasRows(res) ? res.data : RACES_FALLBACK;
        grid.innerHTML = rows.length ? rows.map(buildRaceCard).join('') : '<div class="tw-empty">No races available.</div>';
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = RACES_FALLBACK.length ? RACES_FALLBACK.map(buildRaceCard).join('') : '<div class="tw-empty">ERROR: Race data unavailable.</div>';
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      });
  }

  function fetchSubraces(wrapper, raceId) {
    var grid = q(wrapper, '#tw-subrace-grid');
    if (!grid) return;

    resetSubraceState(wrapper);

    if (!raceId) {
      hideSubraceSection(wrapper);
      updateSummary(wrapper);
      return;
    }

    showSubraceSection(wrapper);
    grid.innerHTML = '<div class="tw-loading">SCANNING SUBRACE DATA…</div>';

    fetchPost('neoweaver_get_subraces', { parent: raceId })
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

    grid.innerHTML = '<div class="tw-loading">SCANNING CLASS MATRIX…</div>';

    fetchPost('neoweaver_get_classes', {})
      .then(function (res) {
        grid.innerHTML = hasRows(res) ? res.data.map(buildClassCard).join('') : '<div class="tw-empty">No classes available.</div>';
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<div class="tw-empty">ERROR: Class data unavailable.</div>';
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

    grid.innerHTML = '<div class="tw-loading">SCANNING SKILL ARCHIVE…</div>';

    fetchPost('neoweaver_get_skills', {})
      .then(function (res) {
        if (!hasRows(res)) {
          grid.innerHTML = '<div class="tw-empty">No skills available.</div>';
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
          return buildSkillCategoryBlock(cat, byCat[cat]);
        }).join('');

        grid.innerHTML = html;
        grid.dataset.rendered = '1';
        updateSkillCounter(wrapper);
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<div class="tw-empty">ERROR: Skill data unavailable.</div>';
      });
  }

  function fetchPackageGrid(wrapper) {
    var grid = q(wrapper, '#tw-package-grid');
    if (!grid) return;

    var classId = selectedClassTag();
    if (!classId) {
      grid.innerHTML = '<div class="tw-empty">Select a class first.</div>';
      return;
    }

    if (grid.dataset.rendered && grid.dataset.rendered === classId) {
      restoreSelections(wrapper);
      return;
    }

    grid.innerHTML = '<div class="tw-loading">SCANNING STARTING PACKAGE…</div>';

    fetchPost('neoweaver_get_packages', { class_tag: classId })
      .then(function (res) {
        grid.innerHTML = hasRows(res) ? res.data.map(buildPackageCard).join('') : '<div class="tw-empty">No starting packages available for this class.</div>';
        grid.dataset.rendered = classId;
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<div class="tw-empty">ERROR: Starting packages unavailable.</div>';
      });
  }

  function attrUsed() {
    return ATTR_KEYS.reduce(function (sum, key) {
      return sum + (parseInt(formState['attr_' + key], 10) || ATTR_MIN);
    }, 0);
  }

  function renderAttrDisplay(wrapper) {
    ATTR_KEYS.forEach(function (key) {
      var val = parseInt(formState['attr_' + key], 10) || ATTR_MIN;
      var inputEl = q(wrapper, '#tw-attr-' + key);
      if (inputEl) inputEl.value = val;

      qa(wrapper, '[data-attr="' + key + '"] .tw-pip').forEach(function (pip) {
        pip.classList.toggle('active', parseInt(pip.dataset.pip, 10) <= val);
      });
    });

    var remainEl = q(wrapper, '#tw-attr-remaining');
    if (remainEl) {
      remainEl.textContent = ATTR_POOL - attrUsed();
    }
  }

  function clearPresetSelection(wrapper) {
    qa(wrapper, '.tw-attr-preset-btn').forEach(function (btn) {
      btn.classList.remove('active');
      btn.setAttribute('aria-pressed', 'false');
    });
  }

  function setPresetActive(wrapper, presetKey) {
    clearPresetSelection(wrapper);
    var btn = q(wrapper, '.tw-attr-preset-btn[data-preset="' + presetKey + '"]');
    if (btn) {
      btn.classList.add('active');
      btn.setAttribute('aria-pressed', 'true');
    }
  }

  function applyPreset(wrapper, presetKey) {
    var presets = {
      balanced: { body: 3, reflex: 3, mind: 3, spirit: 3 },
      agile: { body: 2, reflex: 5, mind: 3, spirit: 2 },
      tank: { body: 5, reflex: 2, mind: 2, spirit: 3 },
      bodybuilder: { body: 5, reflex: 3, mind: 2, spirit: 2 }
    };

    var preset = presets[presetKey];
    if (!preset) return;

    ATTR_KEYS.forEach(function (key) {
      formState['attr_' + key] = preset[key];
    });

    renderAttrDisplay(wrapper);
    setPresetActive(wrapper, presetKey);
    updateSummary(wrapper);
    NW_SFX.preset();
  }

  function canSetAttr(key, nextValue) {
    nextValue = parseInt(nextValue, 10) || ATTR_MIN;
    if (nextValue < ATTR_MIN || nextValue > ATTR_MAX) return false;

    var current = parseInt(formState['attr_' + key], 10) || ATTR_MIN;
    var usedWithoutCurrent = attrUsed() - current;
    return usedWithoutCurrent + nextValue <= ATTR_POOL;
  }

  function setAttrValue(wrapper, key, nextValue) {
    nextValue = parseInt(nextValue, 10) || ATTR_MIN;
    if (nextValue < ATTR_MIN) nextValue = ATTR_MIN;
    if (nextValue > ATTR_MAX) nextValue = ATTR_MAX;

    if (!canSetAttr(key, nextValue)) {
      setStatus('ERROR: Attribute pool exceeded.', true);
      NW_SFX.error();
      renderAttrDisplay(wrapper);
      return false;
    }

    formState['attr_' + key] = nextValue;
    clearPresetSelection(wrapper);
    renderAttrDisplay(wrapper);
    updateSummary(wrapper);
    setStatus('', false);
    NW_SFX.select();
    return true;
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
      return (
        '<button type="button" class="tw-avatar-option" data-avatar-url="' + esc(normalizedUrl) + '" aria-pressed="false">' +
          '<img src="' + esc(normalizedUrl) + '" alt="' + esc(item.name || 'Avatar') + '" loading="lazy" decoding="async">' +
          '<span>' + esc(item.name || 'Avatar') + '</span>' +
        '</button>'
      );
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

      if (gallery) {
        qa(gallery, '.tw-avatar-option').forEach(function (x) {
          x.classList.remove('selected');
          x.setAttribute('aria-pressed', 'false');
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
        x.setAttribute('aria-pressed', 'false');
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

    recomputeBackstoryTags();

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
      if (formState.avatar_file) {
        avatarEl.textContent = formState.avatar_file.name;
      } else if (formState.avatar_url) {
        avatarEl.textContent = formState.avatar_url.split('/').pop();
      } else {
        avatarEl.textContent = '—';
      }
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

    var checkedRadio = q(wrapper, '.tw-pronoun-radio:checked');
    var customWrap = q(wrapper, '#tw-char-pronouns-custom-wrap');
    if (customWrap) {
      customWrap.hidden = !(checkedRadio && checkedRadio.value === 'custom');
      customWrap.style.display = checkedRadio && checkedRadio.value === 'custom' ? '' : 'none';
    }
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

    updateSummary(wrapper);
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
      var used = attrUsed();
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
    if (phase === 'BIOMETRIC CALIBRATION') renderAttrDisplay(wrapper);
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

  function createSpinner(wrapper) {
    var el = q(wrapper, '#tw-char-spinner');
    return {
      show: function () {
        if (el) el.classList.add('is-visible');
      },
      hide: function () {
        if (el) el.classList.remove('is-visible');
      }
    };
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

    recomputeBackstoryTags();
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
            NW_SFX.deploy();

            wrapper.innerHTML =
              '<div class="tw-char-success">' +
                '<h3>✓ ' + esc((res.data && res.data.message) || 'Character created!') + '</h3>' +
                ((res.data && res.data.redirect)
                  ? '<p><a class="tw-btn tw-btn-primary" href="' + esc(res.data.redirect) + '">Enter the Grid</a></p>'
                  : '') +
              '</div>';

            return;
          }

          var message = (res && res.data && res.data.message) ? res.data.message : 'Character creation failed.';
          setStatus(message, true);
          NW_SFX.error();

          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'DEPLOY OPERATIVE';
          }
        }, wait);
      })
      .catch(function () {
        spinner.hide();
        setStatus('Network error. Character creation failed.', true);
        NW_SFX.error();

        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'DEPLOY OPERATIVE';
        }
      });
  }

  function bindEvents(wrapper, steps, spinner) {
    var currentStep = 0;

    wrapper.addEventListener('input', function (ev) {
      var target = ev.target;

      if (target.matches('#tw-char-name')) {
        formState.character_name = target.value.trim();
        updateSummary(wrapper);
      }

      if (target.matches('#tw-char-pronouns-custom')) {
        var customRadio = q(wrapper, '.tw-pronoun-radio[value="custom"]');
        if (customRadio && customRadio.checked) {
          formState.pronouns = target.value.trim() || 'custom';
          updateSummary(wrapper);
        }
      }

      if (target.matches('#tw-char-bio')) {
        formState.bio = target.value.trim();
        updateSummary(wrapper);
      }

      if (target.matches('#tw-char-avatar')) {
        var file = target.files && target.files[0] ? target.files[0] : null;
        if (file) handleAvatarFile(wrapper, file);
      }
    });

    wrapper.addEventListener('change', function (ev) {
      var target = ev.target;

      if (target.matches('.tw-pronoun-radio')) {
        var customWrap = q(wrapper, '#tw-char-pronouns-custom-wrap');
        var customInput = q(wrapper, '#tw-char-pronouns-custom');

        if (customWrap) {
          customWrap.hidden = target.value !== 'custom';
          customWrap.style.display = target.value === 'custom' ? '' : 'none';
        }

        if (target.value === 'custom') {
          formState.pronouns = customInput && customInput.value.trim() ? customInput.value.trim() : 'custom';
          if (customInput) customInput.focus();
        } else {
          formState.pronouns = target.value;
        }

        updateSummary(wrapper);
        NW_SFX.select();
      }
    });

    wrapper.addEventListener('click', function (ev) {
      var target = ev.target;

      var nextBtn = resolveNextButton(target);
      if (nextBtn) {
        ev.preventDefault();

        if (!validateStep(wrapper, steps, currentStep)) return;

        if (currentStep < steps.length - 1) {
          currentStep += 1;
          showStep(wrapper, steps, currentStep);
          NW_SFX.nav();
        }
        return;
      }

      var prevBtn = resolvePrevButton(target);
      if (prevBtn) {
        ev.preventDefault();
        if (currentStep > 0) {
          currentStep -= 1;
          showStep(wrapper, steps, currentStep);
          NW_SFX.back();
        }
        return;
      }

      var raceCard = target.closest('.tw-race-card:not(.tw-subrace-card)');
      if (raceCard) {
        ev.preventDefault();

        formState.race = raceCard.dataset.raceId || '';
        formState.race_label = q(raceCard, '.tw-race-card__title') ? q(raceCard, '.tw-race-card__title').textContent.trim() : '';
        resetSubraceState(wrapper);
        syncSingleSelection(wrapper, '.tw-race-card:not(.tw-subrace-card)', formState.race, 'raceId');
        fetchSubraces(wrapper, formState.race);
        updateSummary(wrapper);
        NW_SFX.select();
        return;
      }

      var subraceCard = target.closest('.tw-subrace-card');
      if (subraceCard) {
        ev.preventDefault();

        formState.subrace = subraceCard.dataset.subraceId || '';
        formState.subrace_label = q(subraceCard, '.tw-race-card__title') ? q(subraceCard, '.tw-race-card__title').textContent.trim() : '';
        syncSingleSelection(wrapper, '.tw-subrace-card', formState.subrace, 'subraceId');
        updateSummary(wrapper);
        NW_SFX.select();
        return;
      }

      var classCard = target.closest('.tw-class-card');
      if (classCard) {
        ev.preventDefault();

        formState.character_class = classCard.dataset.charClass || '';
        formState.class_label = classCard.dataset.className || '';
        formState.skill_limit = parseInt(classCard.dataset.skillLimit, 10) || 5;
        formState.skills = [];
        resetPackageState(wrapper);

        syncSingleSelection(wrapper, '.tw-class-card', formState.character_class, 'charClass');
        updateSkillCounter(wrapper);

        var skillGrid = q(wrapper, '#tw-skill-grid');
        if (skillGrid) {
          qa(skillGrid, '.tw-skill-card').forEach(function (card) {
            card.classList.remove('selected');
            card.setAttribute('aria-pressed', 'false');
          });
        }

        updateSummary(wrapper);
        NW_SFX.select();
        return;
      }

      var skillCard = target.closest('.tw-skill-card');
      if (skillCard) {
        ev.preventDefault();

        var skillId = skillCard.dataset.skillId || '';
        if (!skillId) return;

        var idx = formState.skills.indexOf(skillId);
        if (idx !== -1) {
          formState.skills.splice(idx, 1);
          skillCard.classList.remove('selected');
          skillCard.setAttribute('aria-pressed', 'false');
          updateSkillCounter(wrapper);
          updateSummary(wrapper);
          NW_SFX.back();
          return;
        }

        if (formState.skills.length >= (formState.skill_limit || 5)) {
          setStatus('ERROR: You can select up to ' + (formState.skill_limit || 5) + ' skills for this class.', true);
          NW_SFX.error();
          return;
        }

        formState.skills.push(skillId);
        skillCard.classList.add('selected');
        skillCard.setAttribute('aria-pressed', 'true');
        updateSkillCounter(wrapper);
        updateSummary(wrapper);
        setStatus('', false);
        NW_SFX.select();
        return;
      }

      var packageCard = target.closest('.tw-package-card');
      if (packageCard) {
        ev.preventDefault();

        formState.starting_package_id = packageCard.dataset.packageId || '';
        formState.starting_package_label = packageCard.dataset.packageName || '';
        syncSingleSelection(wrapper, '.tw-package-card', formState.starting_package_id, 'packageId');
        updateSummary(wrapper);
        NW_SFX.select();
        return;
      }

      var loreCard = target.closest('.tw-lore-card');
      if (loreCard) {
        ev.preventDefault();

        var type = loreCard.dataset.choiceType;
        var key = loreCard.dataset.choiceKey;

        if (type && key) {
          formState[type] = key;
          qa(wrapper, '.tw-lore-card[data-choice-type="' + type + '"]').forEach(function (card) {
            var selected = card === loreCard;
            card.classList.toggle('selected', selected);
            card.setAttribute('aria-pressed', selected ? 'true' : 'false');
          });

          recomputeBackstoryTags();
          updateSummary(wrapper);
          NW_SFX.select();
        }
        return;
      }

      var presetBtn = target.closest('.tw-attr-preset-btn');
      if (presetBtn) {
        ev.preventDefault();
        applyPreset(wrapper, presetBtn.dataset.preset || '');
        return;
      }

      var pip = target.closest('.tw-pip');
      if (pip) {
        ev.preventDefault();

        var attrWrap = pip.closest('[data-attr]');
        if (!attrWrap) return;

        var attrKey = attrWrap.dataset.attr || '';
        var pipVal = parseInt(pip.dataset.pip, 10) || ATTR_MIN;
        setAttrValue(wrapper, attrKey, pipVal);
        return;
      }

      var minusBtn = target.closest('.tw-attr-minus');
      if (minusBtn) {
        ev.preventDefault();

        var minusWrap = minusBtn.closest('[data-attr]');
        if (!minusWrap) return;

        var minusKey = minusWrap.dataset.attr || '';
        var minusCurrent = parseInt(formState['attr_' + minusKey], 10) || ATTR_MIN;
        setAttrValue(wrapper, minusKey, Math.max(ATTR_MIN, minusCurrent - 1));
        return;
      }

      var plusBtn = target.closest('.tw-attr-plus');
      if (plusBtn) {
        ev.preventDefault();

        var plusWrap = plusBtn.closest('[data-attr]');
        if (!plusWrap) return;

        var plusKey = plusWrap.dataset.attr || '';
        var plusCurrent = parseInt(formState['attr_' + plusKey], 10) || ATTR_MIN;
        setAttrValue(wrapper, plusKey, Math.min(ATTR_MAX, plusCurrent + 1));
        return;
      }

      var clearAvatarBtn = target.closest('#tw-char-avatar-clear, .tw-avatar-clear');
      if (clearAvatarBtn) {
        ev.preventDefault();
        clearAvatar(wrapper);
        return;
      }

      var submitBtn = target.closest('#tw-char-submit');
      if (submitBtn) {
        ev.preventDefault();

        clearAllStepErrors(wrapper);

        var valid = true;
        for (var i = 0; i < steps.length; i += 1) {
          if (!validateStep(wrapper, steps, i)) {
            currentStep = i;
            showStep(wrapper, steps, currentStep);
            valid = false;
            break;
          }
        }

        if (!valid) return;

        submitCharacter(wrapper, spinner);
      }
    });
  }

  function initOne(wrapper) {
    var steps = qa(wrapper, '.tw-char-step');
    if (!steps.length) return;

    var spinner = createSpinner(wrapper);

    clearAllStepErrors(wrapper);
    renderAttrDisplay(wrapper);
    recomputeBackstoryTags();
    updateSkillCounter(wrapper);
    updateSummary(wrapper);
    hideSubraceSection(wrapper);
    bindEvents(wrapper, steps, spinner);
    showStep(wrapper, steps, 0);
  }

  function init() {
    qa(document, '.tw-character-creator, #tw-character-creator, .tw-char-creator').forEach(function (wrapper) {
      initOne(wrapper);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
