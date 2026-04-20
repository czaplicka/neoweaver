(function () {
  'use strict';

  // ==========================
  // AUDIO FEEDBACK
  // ==========================
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

  // ==========================
  // UTILS
  // ==========================
  function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function ajaxUrl() {
    return (window.twCharCreatorAjax && window.twCharCreatorAjax.ajax_url) || (window.ajaxurl || '');
  }
  function nonce() {
    return (window.twCharCreatorAjax && window.twCharCreatorAjax.nonce) || '';
  }

  function fetchPost(action, payload) {
    var data = new FormData();
    data.append('action', action);
    data.append('nonce', nonce());
    if (payload && typeof payload === 'object') {
      Object.keys(payload).forEach(function (key) {
        data.append(key, payload[key]);
      });
    }
    return fetch(ajaxUrl(), {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    }).then(function (r) { return r.json(); });
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

  // ==========================
  // STATE
  // ==========================
  var ATTR_KEYS = ['body', 'reflex', 'mind', 'spirit'];
  var ATTR_MIN = 1;
  var ATTR_MAX = 5;
  var ATTR_POOL = 16;

  var formState = {
    character_name: '',
    pronouns: '',
    backstory: '',
    bio: '',
    race: '',
    race_label: '',
    subrace: '',
    subrace_label: '',
    character_class: '',
    class_label: '',
    skill_limit: 5,
    skills: [],
    starting_package_id: '',
    starting_package_label: '',
    data_origin: '',
    previous_operation: '',
    sync_crisis: '',
    backstory_tags: [],
    avatar_file: null,
    attr_body: ATTR_MIN,
    attr_reflex: ATTR_MIN,
    attr_mind: ATTR_MIN,
    attr_spirit: ATTR_MIN
  };

  // ==========================
  // CARD BUILDERS
  // ==========================
  function buildTagsHtml(tags) {
    if (!tags || !tags.length) return '';
    return '<div class="tw-race-tags">' + tags.map(function (t) {
      return '<span class="tw-race-tag">' + esc(t.name || t) + '</span>';
    }).join('') + '</div>';
  }

  function buildRaceCard(race) {
    var imgSrc = race.img || race.img_url || '';
    var imgHtml = imgSrc
      ? '<div class="tw-race-img tw-race-img--full"><img src="' + esc(imgSrc) + '" alt="' + esc(race.label || race.name) + '" loading="lazy"></div>'
      : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">✦</span></div>';

    return '' +
      '<div class="tw-grid-card tw-race-card"' +
        ' data-race="' + esc(race.label || race.name || '') + '"' +
        ' data-race-id="' + esc(race.id || '') + '"' +
        ' role="button" tabindex="0" aria-pressed="false">' +
        imgHtml +
        '<div class="tw-race-body">' +
          '<h4 class="tw-race-name">' + esc(race.label || race.name) + '</h4>' +
          buildTagsHtml(race.tags || []) +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function buildSubraceCard(sub) {
    var imgSrc = sub.img || sub.img_url || '';
    var imgHtml = imgSrc
      ? '<div class="tw-race-img tw-race-img--full"><img src="' + esc(sub.label || sub.name) + '" alt="' + esc(sub.label || sub.name) + '" loading="lazy"></div>'
      : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">✦</span></div>';

    return '' +
      '<div class="tw-grid-card tw-race-card tw-subrace-card"' +
        ' data-subrace="' + esc(sub.label || sub.name || '') + '"' +
        ' data-subrace-id="' + esc(sub.id || '') + '"' +
        ' role="button" tabindex="0" aria-pressed="false">' +
        imgHtml +
        '<div class="tw-race-body">' +
          '<h4 class="tw-race-name">' + esc(sub.label || sub.name) + '</h4>' +
          buildTagsHtml(sub.tags || []) +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function buildClassCard(cls) {
    var imgSrc = cls.img_url || '';
    var imgHtml = imgSrc
      ? '<div class="tw-class-card__img-wrap tw-race-img--full"><img src="' + esc(imgSrc) + '" alt="' + esc(cls.name) + '" loading="lazy"></div>'
      : '<div class="tw-class-card__img-wrap tw-class-card__img-wrap--placeholder"><span class="tw-race-card__icon">◎</span></div>';

    var tags = (cls.tags || []).map(function (t) { return { name: t }; });

    return '' +
      '<div class="tw-class-card"' +
        ' data-char-class="' + esc(cls.id || '') + '"' +
        ' data-class-tag="' + esc(cls.name || '') + '"' +
        ' data-skilllimit="' + String(cls.skill_limit != null ? cls.skill_limit : 5) + '"' +
        ' role="button" tabindex="0" aria-pressed="false">' +
        imgHtml +
        '<div class="tw-class-card__body">' +
          '<h4 class="tw-class-card__name">' + esc(cls.name) + '</h4>' +
          (cls.description ? '<p class="tw-class-card__desc">' + esc(cls.description) + '</p>' : '') +
          buildTagsHtml(tags) +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function buildSkillCard(skill) {
    var imgSrc = skill.img_url || '';
    var imgHtml = imgSrc
      ? '<div class="tw-race-img tw-race-img--full"><img src="' + esc(imgSrc) + '" alt="' + esc(skill.name) + '" loading="lazy"></div>'
      : '';

    var tags = (skill.tags || []).map(function (t) { return { name: t }; });

    return '' +
      '<div class="tw-grid-card tw-skill-card" data-skill-id="' + esc(skill.id || '') + '"' +
        ' role="button" tabindex="0" aria-pressed="false">' +
        (imgHtml || '') +
        '<div class="tw-race-body">' +
          '<h4 class="tw-race-name">' + esc(skill.name) + '</h4>' +
          (skill.description ? '<p class="tw-race-desc">' + esc(skill.description) + '</p>' : '') +
          buildTagsHtml(tags) +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  function buildPackageCard(pkg) {
    var tags = (pkg.compatibility_tags || []).map(function (t) { return { name: t }; });
    var items = pkg.items_list || [];
    var itemsPreview = items.length
      ? '<div class="tw-package-items">' + items.slice(0, 4).map(function (it) {
          return '<span class="tw-race-tag">' + esc(it) + '</span>';
        }).join('') + '</div>'
      : '';

    return '' +
      '<div class="tw-grid-card tw-package-card" data-package-id="' + esc(pkg.id || '') + '"' +
        ' role="button" tabindex="0" aria-pressed="false">' +
        '<div class="tw-race-body">' +
          '<h4 class="tw-race-name">' + esc(pkg.package_name || '') + '</h4>' +
          (pkg.description ? '<p class="tw-class-card__desc">' + esc(pkg.description) + '</p>' : '') +
          ((pkg.base_armor != null) ? '<span class="tw-race-bonus">Armor ' + esc(pkg.base_armor) + '</span>' : '') +
          buildTagsHtml(tags) +
          itemsPreview +
          '<span class="tw-race-select-hint">select</span>' +
        '</div>' +
      '</div>';
  }

  // ==========================
  // FETCHERS
  // ==========================
  var RACES_FALLBACK = [];

  function fetchRaceGrid(wrapper) {
    var grid = wrapper.querySelector('#tw-race-grid');
    if (!grid || grid.dataset.rendered) return;
    grid.innerHTML = '<p class="tw-loading">SCANNING RACE DATABASE…</p>';

    fetchPost('neoweaver_get_races', {})
      .then(function (res) {
        var rows = (res && res.success && res.data && res.data.length) ? res.data : RACES_FALLBACK;
        grid.innerHTML = rows.length
          ? rows.map(buildRaceCard).join('')
          : '<p class="tw-empty-state">No races available.</p>';
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = RACES_FALLBACK.length
          ? RACES_FALLBACK.map(buildRaceCard).join('')
          : '<p class="tw-error-msg">ERROR: Race data unavailable.</p>';
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

    grid.innerHTML = '<p class="tw-loading">SCANNING CLASS MATRIX…</p>';

    fetchPost('neoweaver_get_classes', {})
      .then(function (res) {
        if (res && res.success && res.data && res.data.length) {
          grid.innerHTML = res.data.map(buildClassCard).join('');
        } else {
          grid.innerHTML = '<p class="tw-empty-state">No classes available.</p>';
        }
        grid.dataset.rendered = '1';
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<p class="tw-error-msg">ERROR: Class data unavailable.</p>';
      });
  }

  function updateSkillCounter(wrapper) {
    var counter = wrapper.querySelector('#tw-skill-counter');
    if (counter) {
      counter.textContent = formState.skills.length + ' / ' + (formState.skill_limit || 5);
    }
  }

  function fetchSkillGrid(wrapper) {
    var grid = wrapper.querySelector('#tw-skill-grid');
    if (!grid || grid.dataset.rendered) {
      updateSkillCounter(wrapper);
      restoreSelections(wrapper);
      return;
    }

    grid.innerHTML = '<p class="tw-loading">SCANNING SKILL ARCHIVE…</p>';

    fetchPost('neoweaver_get_skills', {})
      .then(function (res) {
        if (res && res.success && res.data && res.data.length) {
          var byCat = {};
          res.data.forEach(function (skill) {
            var cat = skill.category || 'Other';
            if (!byCat[cat]) byCat[cat] = [];
            byCat[cat].push(skill);
          });

          var html = Object.keys(byCat).map(function (cat) {
            var skills = byCat[cat];
            return '' +
              '<div class="tw-skill-category">' +
                '<div class="tw-skill-cat-label">' + esc(cat) + '</div>' +
                '<div class="tw-skill-cat-grid">' +
                  skills.map(buildSkillCard).join('') +
                '</div>' +
              '</div>';
          }).join('');

          grid.innerHTML = html || '<p class="tw-empty-state">No skills available.</p>';
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
    if (selected && selected.dataset.classTag) {
      return String(selected.dataset.classTag).trim().toLowerCase();
    }
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

    grid.innerHTML = '<p class="tw-loading">SCANNING LOADOUT PROTOCOLS…</p>';

    fetchPost('neoweaver_get_starting_packages', { class_tag: classTag })
      .then(function (res) {
        if (res && res.success && res.data && res.data.length) {
          grid.innerHTML = res.data.map(buildPackageCard).join('');
        } else {
          grid.innerHTML = '<p class="tw-empty-state">No starting packages available for this class.</p>';
        }
        grid.dataset.rendered = classTag;
        restoreSelections(wrapper);
      })
      .catch(function () {
        grid.innerHTML = '<p class="tw-error-msg">ERROR: Starting packages unavailable.</p>';
      });
  }

  // ==========================
  // ATTRIBUTES
  // ==========================
  function renderAttrDisplay(wrapper) {
    ATTR_KEYS.forEach(function (key) {
      var val = formState['attr_' + key] || ATTR_MIN;
      var inputEl = wrapper.querySelector('#tw-attr-' + key);
      if (inputEl) inputEl.value = val;
      var pips = wrapper.querySelectorAll('[data-attr="' + key + '"] .tw-pip');
      for (var i = 0; i < pips.length; i++) {
        pips[i].classList.toggle('active', parseInt(pips[i].dataset.pip, 10) <= val);
      }
    });

    var used = ATTR_KEYS.reduce(function (sum, k) {
      return sum + (formState['attr_' + k] || ATTR_MIN);
    }, 0);
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

    ATTR_KEYS.forEach(function (k) {
      formState['attr_' + k] = parseInt(presetBtn.dataset[k], 10);
    });
    var allPresets = wrapper.querySelectorAll('.tw-attr-preset-btn');
    for (var i = 0; i < allPresets.length; i++) {
      allPresets[i].classList.toggle('active', allPresets[i] === presetBtn);
    }
    renderAttrDisplay(wrapper);
    NW_SFX.preset();
  }

  // ==========================
  // AVATAR
  // ==========================
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

  // ==========================
  // SUMMARY
  // ==========================
  function choiceByKey(type, key) {
    if (!window.twCharCreatorChoices || !key) return null;
    var list = window.twCharCreatorChoices[type] || [];
    for (var i = 0; i < list.length; i++) {
      if (String(list[i].key) === String(key)) return list[i];
    }
    return null;
  }

  function updateSummary(wrapper) {
    function set(id, val) {
      var el = wrapper.querySelector('#tw-summary-' + id);
      if (el) el.textContent = val || '—';
    }

    set('character-name', formState.character_name);
    set('pronouns', formState.pronouns);

    set('backstory', formState.backstory
      ? (formState.backstory.length > 80 ? formState.backstory.substring(0, 80) + '…' : formState.backstory)
      : '—'
    );

    set('race',
      [formState.race_label, formState.subrace_label].filter(Boolean).join(' / ') ||
      formState.race ||
      '—'
    );

    set('class', formState.class_label || formState.character_class || '—');

    set('attrs', ATTR_KEYS.map(function (k) {
      return k.toUpperCase() + ' ' + (formState['attr_' + k] || ATTR_MIN);
    }).join(' · '));

    set('skills', formState.skills.length ? (formState.skills.length + ' selected') : '—');
    set('package', formState.starting_package_label || '—');

    var origin    = choiceByKey('data_origin', formState.data_origin);
    var operation = choiceByKey('previous_operation', formState.previous_operation);
    var crisis    = choiceByKey('sync_crisis', formState.sync_crisis);

    set('origin',    origin    ? origin.label    : '—');
    set('operation', operation ? operation.label : '—');
    set('crisis',    crisis    ? crisis.label    : '—');

    set('tag-bundle',
      formState.backstory_tags.length ? formState.backstory_tags.join(' · ') : '—'
    );

    set('bio', formState.bio
      ? (formState.bio.length > 80 ? formState.bio.substring(0, 80) + '…' : formState.bio)
      : '—'
    );

    var avatarEl = wrapper.querySelector('#tw-summary-avatar');
    if (avatarEl) {
      avatarEl.textContent = formState.avatar_file ? formState.avatar_file.name : '—';
    }
  }

  // ==========================
  // STATUS
  // ==========================
  var statusEl = null;
  function setStatus(msg, isError) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.classList.toggle('tw-char-status--error', !!isError);
  }

  // ==========================
  // SUBMIT
  // ==========================
  function submitCharacter(wrapper, steps, current, spinner) {
    setStatus('Uploading agent profile…', false);
    var submitBtn = wrapper.querySelector('#tw-char-submit');
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

    ATTR_KEYS.forEach(function (k) {
      data.append('attr_' + k, formState['attr_' + k]);
    });

    if (formState.avatar_file) {
      data.append('avatar', formState.avatar_file);
    }

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
            var data = res.data || {};
            wrapper.innerHTML =
              '<div class="tw-success">' +
                '<p class="tw-success__msg">✓ ' + esc(data.message || 'Character created!') + '</p>' +
                (data.redirect
                  ? '<a class="tw-btn tw-btn--primary" href="' + esc(data.redirect) + '">Enter the Grid</a>'
                  : ''
                ) +
              '</div>';
          } else {
            var msg = (res && res.data && res.data.message) || (res && res.message) || 'Unknown error.';
            setStatus('ERROR: ' + msg, true);
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Deploy Agent';
            }
            NW_SFX.error();
          }
        }, wait);
      })
      .catch(function () {
        spinner.hide();
        setStatus('ERROR: Network failure.', true);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Deploy Agent';
        }
        NW_SFX.error();
      });
  }

  // ==========================
  // RESTORE SELECTIONS
  // ==========================
  function restoreSelections(wrapper) {
    // race
    var raceCards = wrapper.querySelectorAll('.tw-race-card:not(.tw-subrace-card)');
    for (var i = 0; i < raceCards.length; i++) {
      var rc = raceCards[i];
      var match = !!formState.race && rc.dataset.raceId === formState.race;
      rc.classList.toggle('selected', match);
      rc.setAttribute('aria-pressed', match ? 'true' : 'false');
    }
    // subrace
    var subCards = wrapper.querySelectorAll('.tw-subrace-card');
    for (var j = 0; j < subCards.length; j++) {
      var sc = subCards[j];
      var sm = !!formState.subrace && sc.dataset.subraceId === formState.subrace;
      sc.classList.toggle('selected', sm);
      sc.setAttribute('aria-pressed', sm ? 'true' : 'false');
    }
    // class
    var classCards = wrapper.querySelectorAll('.tw-class-card');
    for (var k = 0; k < classCards.length; k++) {
      var cc = classCards[k];
      var cm = !!formState.character_class && (cc.dataset.charClass === formState.character_class || cc.dataset.charclass === formState.character_class);
      cc.classList.toggle('selected', cm);
      cc.setAttribute('aria-pressed', cm ? 'true' : 'false');
    }
    // skills
    var skillCards = wrapper.querySelectorAll('.tw-skill-card');
    for (var s = 0; s < skillCards.length; s++) {
      var card = skillCards[s];
      var id = card.dataset.skillId;
      var has = id && formState.skills.indexOf(id) !== -1;
      card.classList.toggle('selected', has);
      card.setAttribute('aria-pressed', has ? 'true' : 'false');
    }
    // packages
    var pkgCards = wrapper.querySelectorAll('.tw-package-card');
    for (var p = 0; p < pkgCards.length; p++) {
      var pc = pkgCards[p];
      var pm = !!formState.starting_package_id && pc.dataset.packageId === formState.starting_package_id;
      pc.classList.toggle('selected', pm);
      pc.setAttribute('aria-pressed', pm ? 'true' : 'false');
    }
    // attributes
    renderAttrDisplay(wrapper);
    // summary
    updateSummary(wrapper);
    // skill counter
    updateSkillCounter(wrapper);
  }

  // ==========================
  // VALIDATION
  // ==========================
  function validateAttrs() {
    var total = ATTR_KEYS.reduce(function (sum, key) {
      var v = formState['attr_' + key];
      return sum + (typeof v === 'number' ? v : ATTR_MIN);
    }, 0);
    return total === ATTR_POOL;
  }

  function showStepError(wrapper, msg) {
    var box = wrapper.querySelector('.tw-step-error');
    if (!box) {
      setStatus(msg, true);
      return;
    }
    box.textContent = msg;
    box.classList.add('visible');
    box.classList.add('tw-step-error--shake');
    setTimeout(function () {
      box.classList.remove('tw-step-error--shake');
    }, 280);
  }

  function clearStepError(wrapper) {
    var box = wrapper.querySelector('.tw-step-error');
    if (box) {
      box.classList.remove('visible');
    }
  }

  function validateStep(wrapper, idx) {
    clearStepError(wrapper);
    // 0: name / pronouns
    if (idx === 0) {
      if (!formState.character_name || !formState.character_name.trim()) {
        showStepError(wrapper, 'Character name is required.');
        return false;
      }
      if (!formState.pronouns) {
        showStepError(wrapper, 'Select pronouns for this agent.');
        return false;
      }
      return true;
    }
    // 1: race
    if (idx === 1) {
      if (!formState.race) {
        showStepError(wrapper, 'Select a race to continue.');
        return false;
      }
      return true;
    }
    // 2: class
    if (idx === 2) {
      if (!formState.character_class) {
        showStepError(wrapper, 'Select a class to continue.');
        return false;
      }
      return true;
    }
    // 3: attributes
    if (idx === 3) {
      if (!validateAttrs()) {
        showStepError(wrapper, 'Allocate all attribute points (must total ' + ATTR_POOL + ').');
        return false;
      }
      return true;
    }
    // 4: skills
    if (idx === 4) {
      if (!formState.skills.length) {
        showStepError(wrapper, 'Select at least one skill.');
        return false;
      }
      if (formState.skills.length > (formState.skill_limit || 5)) {
        showStepError(wrapper, 'Too many skills selected for this class.');
        return false;
      }
      return true;
    }
    // 5: starting package
    if (idx === 5) {
      if (!formState.starting_package_id) {
        showStepError(wrapper, 'Select a starting package to continue.');
        return false;
      }
      return true;
    }
    // 6–8: narrative choices (at least one each)
    if (idx === 6) {
      if (!formState.data_origin) {
        showStepError(wrapper, 'Select a data origin.');
        return false;
      }
      return true;
    }
    if (idx === 7) {
      if (!formState.previous_operation) {
        showStepError(wrapper, 'Select a previous operation.');
        return false;
      }
      return true;
    }
    if (idx === 8) {
      if (!formState.sync_crisis) {
        showStepError(wrapper, 'Select a synchronization crisis profile.');
        return false;
      }
      return true;
    }
    // 9: avatar / bio – nic nie jest wymagane
    if (idx === 9) {
      return true;
    }
    // 10: summary
    if (idx === 10) {
      return true;
    }
    return true;
  }

  // ==========================
  // STEP NAVIGATION
  // ==========================
  function phases() {
    return [
      'IDENTITY PROTOCOL',
      'RACE PROTOCOL',
      'CLASS MATRIX',
      'BIOMETRIC CALIBRATION',
      'SKILL SELECTION',
      'STARTING PACKAGE',
      'DATA ORIGIN',
      'PREVIOUS OPERATION',
      'SYNCHRONIZATION CRISIS',
      'VISUAL SIGNATURE',
      'SYSTEM REVIEW'
    ];
  }

  function showStep(wrapper, steps, idx) {
    for (var i = 0; i < steps.length; i++) {
      steps[i].classList.toggle('active', i === idx);
    }
    var label = wrapper.querySelector('.tw-progress-label');
    var counter = wrapper.querySelector('.tw-progress-counter');
    var phase = wrapper.querySelector('.tw-progress-phase');
    var fill = wrapper.querySelector('.tw-progress-fill');
    var ticks = wrapper.querySelectorAll('.tw-progress-tick');

    if (label) label.textContent = 'NEOWEAVER: ARCHITECT CORE';
    if (counter) counter.textContent = 'STEP ' + (idx + 1) + ' / ' + steps.length;
    if (phase) phase.textContent = phases()[idx] || '';

    if (fill) fill.style.width = ((idx) / (steps.length - 1) * 100) + '%';

    for (var t = 0; t < ticks.length; t++) {
      var stepNo = parseInt(ticks[t].dataset.tick, 10);
      ticks[t].classList.toggle('active', stepNo <= (idx + 1));
    }

    clearStepError(wrapper);

    var step = steps[idx];
    var phaseName = phases()[idx] || '';

    if (phaseName === 'RACE PROTOCOL') {
      fetchRaceGrid(wrapper);
    }
    if (phaseName === 'CLASS MATRIX') {
      fetchClassGrid(wrapper);
    }
    if (phaseName === 'SKILL SELECTION') {
      fetchSkillGrid(wrapper);
    }
    if (phaseName === 'STARTING PACKAGE') {
      fetchPackageGrid(wrapper);
    }
    if (phaseName === 'SYSTEM REVIEW') {
      updateSummary(wrapper);
    }
  }

  // ==========================
  // MAIN INIT
  // ==========================
  function initCharacterCreator() {
    var wrapper = document.getElementById('tw-char-creator-wrapper');
    if (!wrapper) return;

    statusEl = wrapper.querySelector('.tw-char-status');

    var steps = wrapper.querySelectorAll('.tw-step');
    if (!steps.length) return;

    var current = 0;
    var spinner = makeSpinner('tw-char-spinner', 'DEPLOYING AGENT…', 'Synchronizing character with the NeoWeave grid.');

    showStep(wrapper, steps, current);
    renderAttrDisplay(wrapper);
    updateSummary(wrapper);

    // NAV BUTTONS
    wrapper.addEventListener('click', function (e) {
      var target = e.target;

      // next
      if (target.closest('.tw-btn-nav[data-dir="next"]')) {
        e.preventDefault();
        if (!validateStep(wrapper, current)) {
          NW_SFX.error();
          return;
        }
        if (current < steps.length - 1) {
          current++;
          showStep(wrapper, steps, current);
          NW_SFX.nav();
        }
      }

      // prev
      if (target.closest('.tw-btn-nav[data-dir="prev"]')) {
        e.preventDefault();
        if (current > 0) {
          current--;
          showStep(wrapper, steps, current);
          NW_SFX.back();
        }
      }

      // submit
      if (target.id === 'tw-char-submit' || target.closest('#tw-char-submit')) {
        e.preventDefault();
        if (!validateStep(wrapper, current)) {
          NW_SFX.error();
          return;
        }
        submitCharacter(wrapper, steps, current, spinner);
      }

      // race click
      var raceCard = target.closest('.tw-race-card');
      if (raceCard && !raceCard.classList.contains('tw-subrace-card')) {
        e.preventDefault();
        var allR = wrapper.querySelectorAll('.tw-race-card:not(.tw-subrace-card)');
        for (var r = 0; r < allR.length; r++) {
          allR[r].classList.remove('selected');
          allR[r].setAttribute('aria-pressed', 'false');
        }
        raceCard.classList.add('selected');
        raceCard.setAttribute('aria-pressed', 'true');

        formState.race = raceCard.dataset.raceId || '';
        formState.race_label = (raceCard.querySelector('.tw-race-name') || {}).textContent || formState.race;
        formState.subrace = '';
        formState.subrace_label = '';

        fetchSubraces(wrapper, raceCard.dataset.race || '');
        restoreSelections(wrapper);
        NW_SFX.select();
      }

      // subrace click
      var subCard = target.closest('.tw-subrace-card');
      if (subCard) {
        e.preventDefault();
        var allS = wrapper.querySelectorAll('.tw-subrace-card');
        for (var s = 0; s < allS.length; s++) {
          allS[s].classList.remove('selected');
          allS[s].setAttribute('aria-pressed', 'false');
        }
        subCard.classList.add('selected');
        subCard.setAttribute('aria-pressed', 'true');

        formState.subrace = subCard.dataset.subraceId || '';
        formState.subrace_label = (subCard.querySelector('.tw-race-name') || {}).textContent || formState.subrace;
        restoreSelections(wrapper);
        NW_SFX.select();
      }

      // class click
      var classCard = target.closest('.tw-class-card');
      if (classCard) {
        e.preventDefault();
        var allC = wrapper.querySelectorAll('.tw-class-card');
        for (var c = 0; c < allC.length; c++) {
          allC[c].classList.remove('selected');
          allC[c].setAttribute('aria-pressed', 'false');
        }
        classCard.classList.add('selected');
        classCard.setAttribute('aria-pressed', 'true');

        formState.character_class = classCard.dataset.charClass || classCard.dataset.charclass || '';
        formState.class_label = (classCard.querySelector('.tw-class-card__name') || {}).textContent || formState.character_class;
        formState.skill_limit = parseInt(classCard.dataset.skilllimit, 10) || 5;

        formState.skills = [];
        updateSkillCounter(wrapper);

        fetchPackageGrid(wrapper);
        restoreSelections(wrapper);
        NW_SFX.select();
      }

      // skill click
      var skillCard = target.closest('.tw-skill-card');
      if (skillCard) {
        e.preventDefault();
        var id = skillCard.dataset.skillId;
        if (!id) return;
        var idx = formState.skills.indexOf(id);
        if (idx === -1) {
          if (formState.skills.length >= (formState.skill_limit || 5)) {
            setStatus('Skill limit reached for this class.', true);
            NW_SFX.error();
            return;
          }
          formState.skills.push(id);
          skillCard.classList.add('selected');
          skillCard.setAttribute('aria-pressed', 'true');
        } else {
          formState.skills.splice(idx, 1);
          skillCard.classList.remove('selected');
          skillCard.setAttribute('aria-pressed', 'false');
        }
        updateSkillCounter(wrapper);
        NW_SFX.select();
      }

      // package click
      var pkgCard = target.closest('.tw-package-card');
      if (pkgCard) {
        e.preventDefault();
        var allP = wrapper.querySelectorAll('.tw-package-card');
        for (var pp = 0; pp < allP.length; pp++) {
          allP[pp].classList.remove('selected');
          allP[pp].setAttribute('aria-pressed', 'false');
        }
        pkgCard.classList.add('selected');
        pkgCard.setAttribute('aria-pressed', 'true');

        formState.starting_package_id = pkgCard.dataset.packageId || '';
        formState.starting_package_label = (pkgCard.querySelector('.tw-race-name') || {}).textContent || formState.starting_package_id;
        restoreSelections(wrapper);
        NW_SFX.select();
      }

      // narrative cards (origin/operation/crisis) – wybór po data-choice-type / data-choice-key
      var loreCard = target.closest('.tw-lore-card');
      if (loreCard) {
        e.preventDefault();
        var type = loreCard.dataset.choiceType;
        var key  = loreCard.dataset.choiceKey;
        if (!type || !key) return;

        var groupCards = wrapper.querySelectorAll('.tw-lore-card[data-choice-type="' + type + '"]');
        for (var lc = 0; lc < groupCards.length; lc++) {
          groupCards[lc].classList.remove('selected');
          groupCards[lc].setAttribute('aria-pressed', 'false');
        }
        loreCard.classList.add('selected');
        loreCard.setAttribute('aria-pressed', 'true');

        if (type === 'data_origin') {
          formState.data_origin = key;
        } else if (type === 'previous_operation') {
          formState.previous_operation = key;
        } else if (type === 'sync_crisis') {
          formState.sync_crisis = key;
        }

        var tags = [];
        if (window.twCharCreatorChoices && window.twCharCreatorChoices.backstory_tags) {
          var defs = window.twCharCreatorChoices.backstory_tags;
          defs.forEach(function (def) {
            if (def.choice_key === key && def.tag_label) {
              tags.push(def.tag_label);
            }
          });
        }
        if (tags.length) {
          formState.backstory_tags = (formState.backstory_tags || []).concat(tags);
          formState.backstory_tags = Array.from(new Set(formState.backstory_tags));
        }
        restoreSelections(wrapper);
        NW_SFX.select();
      }

      // summary EDIT buttons
      var editBtn = target.closest('.tw-summary-edit');
      if (editBtn && editBtn.dataset.goto) {
        e.preventDefault();
        var go = parseInt(editBtn.dataset.goto, 10);
        if (!isNaN(go) && go >= 1 && go <= steps.length) {
          current = go - 1;
          showStep(wrapper, steps, current);
          NW_SFX.nav();
        }
      }

      // clear avatar
      if (target.classList.contains('tw-avatar-clear')) {
        e.preventDefault();
        formState.avatar_file = null;
        var imgEl = wrapper.querySelector('#tw-avatar-img');
        var preview = wrapper.querySelector('#tw-avatar-preview');
        var selected = wrapper.querySelector('#tw-avatar-selected');
        if (imgEl) imgEl.src = '';
        if (preview) preview.style.display = '';
        if (selected) selected.style.display = 'none';
        NW_SFX.back();
      }
    });

    // INPUT HANDLERS
    wrapper.addEventListener('input', function (e) {
      var t = e.target;
      if (t.id === 'tw-char-name') {
        formState.character_name = t.value || '';
        updateSummary(wrapper);
      }
      if (t.id === 'tw-char-backstory') {
        formState.backstory = t.value || '';
        updateSummary(wrapper);
      }
      if (t.id === 'tw-char-bio') {
        formState.bio = t.value || '';
        updateSummary(wrapper);
      }
      if (t.classList.contains('tw-attr-val')) {
        var key = t.dataset.attr;
        if (ATTR_KEYS.indexOf(key) !== -1) {
          var v = parseInt(t.value, 10);
          if (isNaN(v)) v = ATTR_MIN;
          v = Math.max(ATTR_MIN, Math.min(ATTR_MAX, v));
          formState['attr_' + key] = v;
          renderAttrDisplay(wrapper);
        }
      }
    });

    // PRONOUN radios
    wrapper.addEventListener('change', function (e) {
      var t = e.target;
      if (t.name === 'tw-pronouns') {
        formState.pronouns = t.value || '';
        updateSummary(wrapper);
      }
    });

    // ATTR buttons
    wrapper.addEventListener('click', function (e) {
      var t = e.target;
      var preset = t.closest('.tw-attr-preset-btn');
      if (preset) {
        e.preventDefault();
        applyAttrPreset(wrapper, preset);
      }

      var attrBtn = t.closest('.tw-attr-btn');
      if (attrBtn && attrBtn.dataset.dir && attrBtn.dataset.attr) {
        e.preventDefault();
        var key = attrBtn.dataset.attr;
        var dir = attrBtn.dataset.dir === 'up' ? 1 : -1;
        if (ATTR_KEYS.indexOf(key) === -1) return;
        var currentVal = formState['attr_' + key] || ATTR_MIN;
        var newVal = currentVal + dir;
        if (newVal < ATTR_MIN || newVal > ATTR_MAX) return;
        formState['attr_' + key] = newVal;
        renderAttrDisplay(wrapper);
        NW_SFX.nav();
      }
    });

    // AVATAR drag & drop + file input
    var fileInput = wrapper.querySelector('#tw-avatar-file');
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) {
          handleAvatarFile(wrapper, fileInput.files[0]);
        }
      });
    }

    var uploadBox = wrapper.querySelector('.tw-upload-box');
    if (uploadBox) {
      uploadBox.addEventListener('dragover', function (e) {
        e.preventDefault();
        uploadBox.classList.add('tw-upload-box--drag');
      });
      uploadBox.addEventListener('dragleave', function (e) {
        e.preventDefault();
        uploadBox.classList.remove('tw-upload-box--drag');
      });
      uploadBox.addEventListener('drop', function (e) {
        e.preventDefault();
        uploadBox.classList.remove('tw-upload-box--drag');
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files[0]) {
          handleAvatarFile(wrapper, files[0]);
        }
      });
    }

    // STEP KEYS (Enter as next, arrows for attr)
    wrapper.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        var isButton = e.target.closest('button, a, .tw-btn, .tw-btn-nav, .tw-summary-edit');
        if (!isButton) {
          e.preventDefault();
          var nextBtn = wrapper.querySelector('.tw-btn-nav[data-dir="next"]');
          if (nextBtn) nextBtn.click();
        }
      }
    });
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(initCharacterCreator, 0);
  } else {
    document.addEventListener('DOMContentLoaded', initCharacterCreator);
  }
})();
