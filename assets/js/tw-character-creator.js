/**
 * NeoWeaver — Character Creator
 * Unified file: identity • race (+ subrace) • class • attributes • node • avatar • summary
 * FIXES: subrace images, race scrollbars, race selection highlight, subrace tags,
 *        class cards mapped to cyber_classes columns (id/name/img_url/icon_slug/description/tags)
 * v2: preset quick-build buttons in BIOMETRIC CALIBRATION (step 4)
 */

( function () {
    'use strict';

    // ── Shared audio engine ───────────────────────────────────────────────────
    var NW_SFX = ( function () {
        var ctx = null;
        function get() {
            return ctx || ( ctx = new ( window.AudioContext || window.webkitAudioContext )() );
        }
        function beep( freq, type, duration, vol ) {
            try {
                var ac = get(), o = ac.createOscillator(), g = ac.createGain();
                o.type = type || 'square';
                o.frequency.value = freq || 440;
                g.gain.setValueAtTime( vol || 0.18, ac.currentTime );
                g.gain.exponentialRampToValueAtTime( 0.001, ac.currentTime + ( duration || 0.08 ) );
                o.connect( g ); g.connect( ac.destination );
                o.start(); o.stop( ac.currentTime + ( duration || 0.08 ) );
            } catch ( e ) {}
        }
        return {
            nav:    function () { beep( 660, 'square',   0.06, 0.15 ); },
            select: function () { beep( 880, 'sine',     0.10, 0.20 ); },
            back:   function () { beep( 330, 'sawtooth', 0.08, 0.12 ); },
            deploy: function () {
                beep( 440, 'square', 0.1, 0.2 );
                setTimeout( function () { beep( 660, 'sine', 0.15, 0.25 ); }, 120 );
            },
            error:  function () { beep( 180, 'sawtooth', 0.18, 0.20 ); },
            preset: function () { beep( 740, 'sine', 0.12, 0.22 ); },
        };
    } )();

    // ── Spinner factory ───────────────────────────────────────────────────────
    function makeSpinner( id, title, subtitle ) {
        var existing = document.getElementById( id );
        if ( existing ) {
            return {
                show: function () { existing.classList.add( 'active' ); },
                hide: function () { existing.classList.remove( 'active' ); },
            };
        }
        var el = document.createElement( 'div' );
        el.id = id;
        el.innerHTML =
            '<div class="tw-spinner-inner">' +
                '<div class="tw-spinner-ring"></div>' +
                '<div class="tw-spinner-ring tw-spinner-ring--2"></div>' +
                '<p class="tw-spinner-text">' + title + '</p>' +
                '<p class="tw-spinner-sub">' + subtitle + '</p>' +
            '</div>';
        document.body.appendChild( el );
        return {
            show: function () { el.classList.add( 'active' ); },
            hide: function () { el.classList.remove( 'active' ); },
        };
    }

    // ── Constants ─────────────────────────────────────────────────────────────
    var ATTR_KEYS = [ 'body', 'reflex', 'mind', 'spirit' ];
    var ATTR_MIN  = 1;
    var ATTR_MAX  = 5;
    var ATTR_POOL = 12;

    // ── Config ────────────────────────────────────────────────────────────────
    var _cfg = window.twCharCreatorConfig || window.neoweaver_ajax || {};

    // Hardcoded races kept ONLY as emergency offline fallback.
    var RACES_FALLBACK = [
        {
            key: 'human', label: 'Human', icon: '&#128100;', img: '',
            desc: 'Adaptable generalists. Bonus feat at character creation.', bonus: '+1 to any attribute',
            subraces: [
                { key: 'human_corp',   label: 'Corp Human',   desc: 'Raised in megacorp culture. Starts with extra Credits.', img: '', tags: [] },
                { key: 'human_fringe', label: 'Fringe Human', desc: 'Grew up in the undercity. Stealth & survival instincts.', img: '', tags: [] },
                { key: 'human_nomad',  label: 'Nomad Human',  desc: 'Migrant bloodline. Bonus to REFLEX and Endurance rolls.', img: '', tags: [] },
            ],
        },
        {
            key: 'beastman', label: 'Beastman', icon: '&#128060;', img: '',
            desc: 'Hybrid of human and animal genetics. Enhanced senses and raw power.', bonus: '+1 BODY, darkvision',
            subraces: [],
        },
        {
            key: 'synth', label: 'Synth', icon: '&#129302;', img: '',
            desc: 'Fully synthetic android. Immune to bio-hazards, requires maintenance.', bonus: '+1 MIND, no sleep needed',
            subraces: [],
        },
        {
            key: 'weaver', label: 'Weaver', icon: '&#10024;', img: '',
            desc: 'Born with innate connection to the NeoWeave. Arcane conduit in human form.', bonus: '+1 SPIRIT, mana sense',
            subraces: [],
        },
    ];

    // ── State ─────────────────────────────────────────────────────────────────
    var formState = {
        character_name: '', pronouns: '', backstory: '',
        race: '', subrace: '', race_label: '',
        'class': '', class_label: '',
        node_id: '', node_label: '',
        avatar_file: null,
        attr_body: ATTR_MIN, attr_reflex: ATTR_MIN, attr_mind: ATTR_MIN, attr_spirit: ATTR_MIN,
    };

    // ── Helpers ───────────────────────────────────────────────────────────────
    function esc( str ) {
        return String( str )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }
    function ajaxUrl() { return _cfg.ajax_url || _cfg.ajaxurl || '/wp-admin/admin-ajax.php'; }
    function nonce()   { return _cfg.nonce || ''; }

    function setStatus( msg, isError ) {
        var el = document.querySelector( '.tw-char-status' );
        if ( ! el ) return;
        el.textContent = msg;
        el.className   = 'tw-char-status' + ( isError ? ' tw-char-status--error' : '' );
    }

    // ── Step errors ───────────────────────────────────────────────────────────
    function showStepError( stepEl, msg ) {
        var errEl = stepEl.querySelector( '.tw-step-error' );
        if ( ! errEl ) {
            errEl = document.createElement( 'div' );
            errEl.className = 'tw-step-error';
            var navRow = stepEl.querySelector( '.tw-nav-row' );
            if ( navRow ) { stepEl.insertBefore( errEl, navRow ); } else { stepEl.appendChild( errEl ); }
        }
        errEl.innerHTML =
            '<span class="tw-step-error__icon">&#9888;</span>' +
            '<span class="tw-step-error__msg">' + esc( msg ) + '</span>';
        errEl.classList.add( 'visible' );
        errEl.classList.remove( 'tw-step-error--shake' );
        void errEl.offsetWidth;
        errEl.classList.add( 'tw-step-error--shake' );
        errEl.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
        NW_SFX.error();
    }

    function clearStepError( stepEl ) {
        if ( ! stepEl ) return;
        var errEl = stepEl.querySelector( '.tw-step-error' );
        if ( errEl ) { errEl.classList.remove( 'visible', 'tw-step-error--shake' ); }
    }

    // ── Tag renderer ──────────────────────────────────────────────────────────
    // tags may be an array of strings or objects {name, slug, ...}
    function buildTagsHtml( tags ) {
        if ( ! tags || ! tags.length ) return '';
        var items = tags.slice( 0, 4 ).map( function ( t ) {
            var label = ( typeof t === 'string' ) ? t : ( t.name || t.slug || t );
            return '<span class="tw-race-tag">' + esc( label ) + '</span>';
        } );
        return '<div class="tw-race-tags">' + items.join( '' ) + '</div>';
    }

    // ── Card builders ─────────────────────────────────────────────────────────

    function buildRaceCard( race ) {
        var imgSrc  = race.img || '';
        var imgHtml = imgSrc
            ? '<div class="tw-race-img"><img src="' + esc( imgSrc ) + '" alt="' + esc( race.label ) + '" width="220" height="220" loading="lazy" /></div>'
            : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">' + ( race.icon || '&#10067;' ) + '</span></div>';
        var tagsHtml = buildTagsHtml( race.tags );
        return '<div class="tw-grid-card tw-race-card" data-race="' + esc( race.key ) + '" role="button" tabindex="0" aria-pressed="false">' +
            imgHtml +
            '<div class="tw-race-body">' +
                '<h4 class="tw-race-name">' + esc( race.label ) + '</h4>' +
                '<p class="tw-race-desc">' + esc( race.desc ) + '</p>' +
                ( race.bonus ? '<span class="tw-race-bonus">' + esc( race.bonus ) + '</span>' : '' ) +
                tagsHtml +
                '<span class="tw-race-select-hint">[ select ]</span>' +
            '</div></div>';
    }

    // FIX 1: subrace card teraz obsługuje img z API (sub.img || sub.image || sub.thumbnail)
    // FIX 4: subrace card teraz renderuje tagi identycznie jak karta rasy
    function buildSubraceCard( sub ) {
        var imgSrc  = sub.img || sub.image || sub.thumbnail || '';
        var imgHtml = imgSrc
            ? '<div class="tw-race-img"><img src="' + esc( imgSrc ) + '" alt="' + esc( sub.label ) + '" width="220" height="220" loading="lazy" /></div>'
            : '<div class="tw-race-img tw-race-img--placeholder"><span class="tw-race-card__icon">&#10022;</span></div>';
        var tagsHtml = buildTagsHtml( sub.tags );
        return '<div class="tw-grid-card tw-race-card tw-subrace-card" data-subrace="' + esc( sub.key ) + '" role="button" tabindex="0" aria-pressed="false">' +
            imgHtml +
            '<div class="tw-race-body">' +
                '<h4 class="tw-race-name">' + esc( sub.label ) + '</h4>' +
                '<p class="tw-race-desc">' + esc( sub.desc ) + '</p>' +
                tagsHtml +
                '<span class="tw-race-select-hint">[ select ]</span>' +
            '</div></div>';
    }

    // ── Race grid: fetch from AJAX, fallback to hardcoded ─────────────────────────
    function fetchRaceGrid( wrapper ) {
        var grid = wrapper.querySelector( '#tw-race-grid' );
        if ( ! grid || grid.dataset.rendered ) return;

        grid.innerHTML = '<p class="tw-loading">// SCANNING RACE DATABASE…</p>';

        var fd = new FormData();
        fd.append( 'action', 'neoweaver_get_races' );
        fd.append( 'nonce',  nonce() );

        fetch( ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: fd } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res.success && res.data && res.data.length ) {
                    grid.innerHTML = res.data.map( buildRaceCard ).join( '' );
                    grid.dataset.rendered = '1';
                } else {
                    grid.innerHTML = RACES_FALLBACK.map( buildRaceCard ).join( '' );
                    grid.dataset.rendered = '1';
                }
            } )
            .catch( function () {
                grid.innerHTML = RACES_FALLBACK.map( buildRaceCard ).join( '' );
                grid.dataset.rendered = '1';
            } );
    }

    // ── Subrace grid: fetch from AJAX ───────────────────────────────────────────
    function fetchSubraces( wrapper, raceKey ) {
        var section = wrapper.querySelector( '#tw-subrace-section' );
        var grid    = wrapper.querySelector( '#tw-subrace-grid' );
        if ( ! section || ! grid ) return;

        section.style.display = '';
        grid.innerHTML = '<p class="tw-loading">// SCANNING SUBRACE DATA…</p>';

        var fd = new FormData();
        fd.append( 'action', 'neoweaver_get_subraces' );
        fd.append( 'nonce',  nonce() );
        fd.append( 'parent', raceKey );

        fetch( ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: fd } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res.success && res.data && res.data.length ) {
                    // FIX 1 + FIX 4: buildSubraceCard obsługuje teraz img i tags z API
                    grid.innerHTML = res.data.map( buildSubraceCard ).join( '' );
                } else {
                    section.style.display = 'none';
                }
            } )
            .catch( function () {
                section.style.display = 'none';
            } );
    }

    // ── Class grid (AJAX) — mapped to cyber_classes columns ──────────────────
    // cyber_classes: id, name, description, tags (jsonb), img_url, icon_slug
    function fetchClassGrid( wrapper ) {
        var grid = wrapper.querySelector( '#tw-class-grid' );
        if ( ! grid || grid.dataset.rendered ) return;

        grid.innerHTML = '<div class="tw-loading-state"><div class="tw-loading-dot"></div>// SCANNING CLASS MATRIX…</div>';

        var fd = new FormData();
        fd.append( 'action', 'neoweaver_get_classes' );
        fd.append( 'nonce',  nonce() );

        fetch( ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: fd } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res.success && res.data && res.data.length ) {
                    grid.innerHTML = res.data.map( function ( cls ) {
                        // cyber_classes columns: id, name, img_url, icon_slug, description, tags
                        var imgSrc  = cls.img_url || '';
                        var icon    = cls.icon_slug || '&#128100;';
                        var name    = cls.name || '';
                        var desc    = cls.description || '';
                        var tags    = cls.tags || [];
                        var id      = cls.id || '';

                        var imgHtml = imgSrc
                            ? '<div class="tw-class-card__img-wrap"><img src="' + esc( imgSrc ) + '" alt="' + esc( name ) + '" width="220" height="220" loading="lazy"/></div>'
                            : '<div class="tw-class-card__img-wrap tw-class-card__img-wrap--placeholder"><span>' + icon + '</span></div>';

                        var tagsHtml = buildTagsHtml( tags );

                        return '<div class="tw-class-card" data-char-class="' + esc( id ) + '" data-label="' + esc( name ) + '" role="button" tabindex="0">' +
                            imgHtml +
                            '<div class="tw-class-card__body">' +
                                '<h4 class="tw-class-card__name">' + esc( name ) + '</h4>' +
                                ( desc ? '<p class="tw-class-card__desc">' + esc( desc ) + '</p>' : '' ) +
                                tagsHtml +
                                '<span class="tw-race-select-hint">[ select ]</span>' +
                            '</div>' +
                        '</div>';
                    } ).join( '' );
                    grid.dataset.rendered = '1';
                } else {
                    grid.innerHTML = '<p class="tw-empty-state">No classes available.</p>';
                }
            } )
            .catch( function () {
                grid.innerHTML = '<p class="tw-error-msg">ERROR: Class data unavailable.</p>';
            } );
    }

    // ── Node grid (AJAX) ──────────────────────────────────────────────────────
    function fetchNodeGrid( wrapper ) {
        var grid = wrapper.querySelector( '#tw-node-grid' );
        if ( ! grid || grid.dataset.rendered ) return;
        var fd = new FormData();
        fd.append( 'action', 'neoweaver_get_nodes' );
        fd.append( 'nonce',  nonce() );
        fetch( ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: fd } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res.success && res.data && res.data.length ) {
                    grid.innerHTML = res.data.map( function ( node ) {
                        return '<div class="tw-node-card" data-node-id="' + esc( node.id ) + '" data-label="' + esc( node.label ) + '" role="button" tabindex="0">' +
                            '<div class="tw-node-card__body"><h4 class="tw-node-card__name">' + esc( node.label ) + '</h4><p class="tw-node-card__desc">' + esc( node.desc || '' ) + '</p></div></div>';
                    } ).join( '' );
                    grid.dataset.rendered = '1';
                } else {
                    grid.innerHTML = '<p class="tw-empty-state">No nodes available. <a href="/create-world/">Deploy a Node first &rarr;</a></p>';
                }
            } )
            .catch( function () {
                grid.innerHTML = '<p class="tw-error-msg">ERROR: Node scan failed.</p>';
            } );
    }

    // ── Summary ───────────────────────────────────────────────────────────────
    function updateSummary( wrapper ) {
        function set( id, val ) {
            var el = wrapper.querySelector( '#tw-summary-' + id );
            if ( el ) el.textContent = val || '\u2014';
        }
        set( 'character_name', formState.character_name );
        set( 'pronouns',       formState.pronouns );
        set( 'backstory',      formState.backstory
            ? formState.backstory.substring( 0, 80 ) + ( formState.backstory.length > 80 ? '\u2026' : '' )
            : '' );
        set( 'race',    formState.race_label  || formState.race );
        set( 'class',   formState.class_label || formState[ 'class' ] );
        set( 'node_id', formState.node_label  || formState.node_id );
        var attrsStr = ATTR_KEYS.map( function ( k ) {
            return k.toUpperCase() + ':' + ( formState[ 'attr_' + k ] || ATTR_MIN );
        } ).join( ' \u00b7 ' );
        set( 'attrs', attrsStr );
        var avatarEl = wrapper.querySelector( '#tw-summary-avatar' );
        if ( avatarEl ) avatarEl.textContent = formState.avatar_file ? formState.avatar_file.name : '\u2014';
    }

    // ── Attribute display ─────────────────────────────────────────────────────
    function renderAttrDisplay( wrapper ) {
        ATTR_KEYS.forEach( function ( key ) {
            var val = formState[ 'attr_' + key ] || ATTR_MIN;
            var inputEl = wrapper.querySelector( '#tw-attr-' + key );
            if ( inputEl ) inputEl.value = val;
            var rows = wrapper.querySelectorAll( '[data-attr="' + key + '"] .tw-pip' );
            for ( var i = 0; i < rows.length; i++ ) {
                rows[ i ].classList.toggle( 'active', parseInt( rows[ i ].dataset.pip, 10 ) <= val );
            }
        } );
        var used = ATTR_KEYS.reduce( function ( s, k ) {
            return s + ( formState[ 'attr_' + k ] || ATTR_MIN );
        }, 0 );
        var remainEl = document.getElementById( 'tw-attr-remaining' );
        if ( remainEl ) remainEl.textContent = ATTR_POOL - used;
    }

    // ── Apply preset to formState ─────────────────────────────────────────────
    // presetBtn: the button element with data-body / data-reflex / data-mind / data-spirit
    function applyAttrPreset( wrapper, presetBtn ) {
        var keys = ATTR_KEYS;
        var valid = true;
        keys.forEach( function ( k ) {
            var v = parseInt( presetBtn.dataset[ k ], 10 );
            if ( isNaN( v ) || v < ATTR_MIN || v > ATTR_MAX ) { valid = false; }
        } );
        if ( ! valid ) return;

        keys.forEach( function ( k ) {
            formState[ 'attr_' + k ] = parseInt( presetBtn.dataset[ k ], 10 );
        } );

        // Highlight active preset button, clear others
        var allPresets = wrapper.querySelectorAll( '.tw-attr-preset-btn' );
        for ( var i = 0; i < allPresets.length; i++ ) {
            allPresets[ i ].classList.toggle( 'active', allPresets[ i ] === presetBtn );
        }

        renderAttrDisplay( wrapper );
        NW_SFX.preset();
    }

    // ── Avatar file handler ───────────────────────────────────────────────────
    function handleAvatarFile( wrapper, file ) {
        var allowed = [ 'image/jpeg', 'image/png', 'image/webp' ];
        if ( ! file || allowed.indexOf( file.type ) === -1 || file.size > 2 * 1024 * 1024 ) {
            setStatus( 'ERROR: Invalid file. JPG/PNG/WEBP under 2 MB only.', true );
            NW_SFX.error();
            return;
        }
        formState.avatar_file = file;
        NW_SFX.select();
        var reader = new FileReader();
        reader.onload = function ( ev ) {
            var imgEl    = wrapper.querySelector( '#tw-avatar-img' );
            var preview  = wrapper.querySelector( '#tw-avatar-preview' );
            var selected = wrapper.querySelector( '#tw-avatar-selected' );
            if ( imgEl )    imgEl.src            = ev.target.result;
            if ( preview )  preview.style.display = 'none';
            if ( selected ) selected.style.display = '';
        };
        reader.readAsDataURL( file );
    }

    // ── Submit ────────────────────────────────────────────────────────────────
    function submitCharacter( wrapper, steps, current, spinner ) {
        setStatus( 'Uploading agent profile\u2026', false );
        var submitBtn = wrapper.querySelector( '#tw-char-submit' );
        if ( submitBtn ) { submitBtn.disabled = true; submitBtn.textContent = 'SYNCHRONIZING\u2026'; }
        NW_SFX.deploy();
        spinner.show();

        var data = new FormData();
        data.append( 'action',         'neoweaver_create_character' );
        data.append( 'nonce',          nonce() );
        data.append( 'character_name', formState.character_name );
        data.append( 'pronouns',       formState.pronouns );
        data.append( 'backstory',      formState.backstory );
        data.append( 'race',           formState.race );
        data.append( 'subrace',        formState.subrace );
        data.append( 'char_class',     formState[ 'class' ] );
        data.append( 'node_id',        formState.node_id );
        if ( formState.avatar_file ) data.append( 'avatar', formState.avatar_file );
        ATTR_KEYS.forEach( function ( k ) {
            data.append( 'attr_' + k, formState[ 'attr_' + k ] || ATTR_MIN );
        } );

        var t0 = Date.now();
        fetch( ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                var wait = Math.max( 0, 2500 - ( Date.now() - t0 ) );
                setTimeout( function () {
                    spinner.hide();
                    if ( res.success ) {
                        setStatus( 'Agent profile created. Welcome to the Grid.', false );
                        wrapper.innerHTML =
                            '<div class="tw-success">' +
                            '<p class="tw-success__msg">&#10003; ' + esc( ( res.data && res.data.message ) ? res.data.message : 'Character created!' ) + '</p>' +
                            ( ( res.data && res.data.redirect )
                                ? '<a href="' + esc( res.data.redirect ) + '" class="tw-btn tw-btn--primary">Enter the Grid</a>'
                                : '' ) +
                            '</div>';
                    } else {
                        var errMsg = ( res.data && res.data.message ) ? res.data.message : 'Submission failed. Retry.';
                        setStatus( 'ERROR: ' + errMsg, true );
                        NW_SFX.error();
                        if ( submitBtn ) { submitBtn.disabled = false; submitBtn.textContent = '&#9658; SYNCHRONIZE AGENT'; }
                        if ( steps[ current ] ) showStepError( steps[ current ], errMsg );
                    }
                }, wait );
            } )
            .catch( function () {
                spinner.hide();
                setStatus( 'ERROR: Connection lost. Check your link and retry.', true );
                NW_SFX.error();
                if ( submitBtn ) { submitBtn.disabled = false; submitBtn.textContent = '&#9658; SYNCHRONIZE AGENT'; }
            } );
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    function init() {
        var wrapper = document.getElementById( 'tw-char-creator-wrapper' );
        if ( ! wrapper ) return;

        // GUARD: prevent double-init
        if ( wrapper.dataset.nwInit ) return;
        wrapper.dataset.nwInit = '1';

        var steps   = Array.prototype.slice.call( wrapper.querySelectorAll( '.tw-step' ) );
        var current = 0;
        if ( ! steps.length ) return;

        var spinner = makeSpinner(
            'tw-char-spinner',
            '// SYNCHRONIZING AGENT\u2026',
            'Writing operative data to the NeoWeave grid.'
        );

        // Fetch races from DB immediately
        fetchRaceGrid( wrapper );
        renderAttrDisplay( wrapper );

        // ── showStep ──────────────────────────────────────────────────────────
        function showStep( idx ) {
            steps.forEach( function ( s, i ) {
                s.classList.toggle( 'active', i === idx );
            } );
            current = idx;
            setStatus( '', false );

            var phase = ( steps[ idx ] && steps[ idx ].dataset.phase ) || '';
            if ( phase === 'CLASS MATRIX' )  fetchClassGrid( wrapper );
            if ( phase === 'NODE BINDING' )  fetchNodeGrid( wrapper );
            if ( phase === 'SYSTEM REVIEW' ) updateSummary( wrapper );

            var fillEl  = document.getElementById( 'tw-char-progress-fill' );
            var stepEl  = document.getElementById( 'tw-char-step-current' );
            var phaseEl = document.getElementById( 'tw-char-progress-phase' );
            if ( fillEl )  fillEl.style.width = Math.round( ( ( idx + 1 ) / steps.length ) * 100 ) + '%';
            if ( stepEl )  stepEl.textContent  = idx + 1;
            if ( phaseEl ) phaseEl.textContent = phase;

            var ticks = wrapper.querySelectorAll( '.tw-progress-tick' );
            for ( var t = 0; t < ticks.length; t++ ) {
                var n = parseInt( ticks[ t ].dataset.tick, 10 );
                ticks[ t ].classList.toggle( 'active', n <= idx + 1 );
            }
            function showStep( idx ) {
    steps.forEach( function ( s, i ) {
        s.classList.toggle( 'active', i === idx );
    } );
    current = idx;
    setStatus( '', false );

    var phase = ( steps[ idx ] && steps[ idx ].dataset.phase ) || '';
    if ( phase === 'CLASS MATRIX' )  fetchClassGrid( wrapper );
    if ( phase === 'NODE BINDING' )  fetchNodeGrid( wrapper );
    if ( phase === 'SYSTEM REVIEW' ) updateSummary( wrapper );

    var fillEl  = document.getElementById( 'tw-char-progress-fill' );
    var stepEl  = document.getElementById( 'tw-char-step-current' );
    var phaseEl = document.getElementById( 'tw-char-progress-phase' );
    if ( fillEl )  fillEl.style.width = Math.round( ( ( idx + 1 ) / steps.length ) * 100 ) + '%';
    if ( stepEl )  stepEl.textContent  = idx + 1;
    if ( phaseEl ) phaseEl.textContent = phase;

    var ticks = wrapper.querySelectorAll( '.tw-progress-tick' );
    for ( var t = 0; t < ticks.length; t++ ) {
        var n = parseInt( ticks[ t ].dataset.tick, 10 );
        ticks[ t ].classList.toggle( 'active', n <= idx + 1 );
    }

    // ── Przywróć podświetlenie zaznaczonych kart po powrocie ──
    restoreSelections();
}
        }

        // ── validateStep ──────────────────────────────────────────────────────
        function validateStep( idx ) {
            var step = steps[ idx ];
            if ( ! step ) return true;
            clearStepError( step );

            if ( idx === 0 ) {
                var nameInput = wrapper.querySelector( '#tw-char-name' );
                if ( ! nameInput || ! nameInput.value.trim() ) {
                    if ( nameInput ) {
                        nameInput.focus();
                        nameInput.classList.add( 'tw-input--error' );
                        nameInput.addEventListener( 'input', function onFix() {
                            nameInput.classList.remove( 'tw-input--error' );
                            clearStepError( step );
                            nameInput.removeEventListener( 'input', onFix );
                        } );
                    }
                    showStepError( step, 'ERROR: Agent designation is required.' );
                    setStatus( 'ERROR: Agent designation is required.', true );
                    return false;
                }
                formState.character_name = nameInput.value.trim();
                var checkedRadio = wrapper.querySelector( '.tw-pronoun-radio:checked' );
                if ( checkedRadio ) {
                    if ( checkedRadio.value === 'custom' ) {
                        var customEl = document.getElementById( 'tw-char-pronouns-custom' );
                        formState.pronouns = ( customEl ? customEl.value.trim() : '' ) || 'custom';
                    } else {
                        formState.pronouns = checkedRadio.value;
                    }
                }
                var backstoryEl = wrapper.querySelector( '#tw-char-backstory' );
                formState.backstory = backstoryEl ? backstoryEl.value : '';
                return true;
            }

            if ( step.dataset.phase === 'RACE PROTOCOL' ) {
                if ( ! formState.race ) {
                    showStepError( step, 'ERROR: Select a race to continue.' );
                    setStatus( 'ERROR: Select a race to continue.', true );
                    return false;
                }
                return true;
            }

            if ( step.dataset.phase === 'CLASS MATRIX' ) {
                if ( ! formState[ 'class' ] ) {
                    showStepError( step, 'ERROR: Select a class to continue.' );
                    setStatus( 'ERROR: Select a class to continue.', true );
                    return false;
                }
                return true;
            }

            if ( step.dataset.phase === 'BIOMETRIC CALIBRATION' ) {
                var used = ATTR_KEYS.reduce( function ( sum, k ) {
                    return sum + ( formState[ 'attr_' + k ] || ATTR_MIN );
                }, 0 );
                if ( used !== ATTR_POOL ) {
                    showStepError( step, 'ERROR: Distribute all ' + ATTR_POOL + ' attribute points (' + used + '/' + ATTR_POOL + ' used).' );
                    setStatus( 'ERROR: Distribute all ' + ATTR_POOL + ' attribute points.', true );
                    return false;
                }
                return true;
            }

            if ( step.dataset.phase === 'NODE BINDING' ) {
                if ( ! formState.node_id ) {
                    showStepError( step, 'ERROR: Bind the agent to a Node before continuing.' );
                    setStatus( 'ERROR: Bind the agent to a Node before continuing.', true );
                    return false;
                }
                return true;
            }

            return true;
        }

        // ── goNext / goPrev ────────────────────────────────────────────────────────────
        function goNext() {
            if ( validateStep( current ) ) {
                clearStepError( steps[ current ] );
                setStatus( '', false );
                if ( current < steps.length - 1 ) { NW_SFX.nav(); showStep( current + 1 ); }
            }
        }
        function goPrev() {
            clearStepError( steps[ current ] );
            setStatus( '', false );
            if ( current > 0 ) { NW_SFX.back(); showStep( current - 1 ); }
        }

        // ── Direct listener on Step 1 NEXT button (belt-and-suspenders) ───────
        var step1Next = document.getElementById( 'tw-char-step1-next' );
        if ( step1Next ) {
            step1Next.addEventListener( 'click', function ( e ) {
                e.stopPropagation();
                goNext();
            } );
        }

        // ── Single delegated click handler ────────────────────────────────────
        wrapper.addEventListener( 'click', function ( e ) {

            // ── Preset quick-build button ──────────────────────────────────────
            var presetBtn = e.target.closest( '.tw-attr-preset-btn' );
            if ( presetBtn ) {
                applyAttrPreset( wrapper, presetBtn );
                clearStepError( steps[ current ] );
                return;
            }

            // Subrace card
            var subCard = e.target.closest( '.tw-subrace-card' );
            if ( subCard ) {
                var allSub = wrapper.querySelectorAll( '.tw-subrace-card' );
                for ( var s = 0; s < allSub.length; s++ ) {
                    allSub[ s ].classList.remove( 'selected' );
                    allSub[ s ].setAttribute( 'aria-pressed', 'false' );
                }
                subCard.classList.add( 'selected' );
                subCard.setAttribute( 'aria-pressed', 'true' );
                formState.subrace = subCard.dataset.subrace || '';
                NW_SFX.select();
                clearStepError( steps[ current ] );
                return;
            }

            // FIX 3: base race card — .selected dodawane poniżej; podświetlenie przez CSS .tw-race-card.selected
            var raceCard = e.target.closest( '.tw-race-card:not(.tw-subrace-card)' );
            if ( raceCard && ! e.target.closest( 'button' ) ) {
                var allRace = wrapper.querySelectorAll( '.tw-race-card:not(.tw-subrace-card)' );
                for ( var r = 0; r < allRace.length; r++ ) {
                    allRace[ r ].classList.remove( 'selected' );
                    allRace[ r ].setAttribute( 'aria-pressed', 'false' );
                }
                raceCard.classList.add( 'selected' );
                raceCard.setAttribute( 'aria-pressed', 'true' );
                formState.race       = raceCard.dataset.race || '';
                formState.race_label = ( raceCard.querySelector( '.tw-race-name' ) || {} ).textContent || formState.race;
                formState.subrace    = '';
                // Reset previously selected subrace cards
                var allSubReset = wrapper.querySelectorAll( '.tw-subrace-card' );
                for ( var sr = 0; sr < allSubReset.length; sr++ ) {
                    allSubReset[ sr ].classList.remove( 'selected' );
                    allSubReset[ sr ].setAttribute( 'aria-pressed', 'false' );
                }
                // Fetch subraces from DB
                fetchSubraces( wrapper, formState.race );
                NW_SFX.select();
                clearStepError( steps[ current ] );
                return;
            }

            // Class card
var classCard = e.target.closest( '.tw-class-card' );
if ( classCard && ! e.target.closest( 'button' ) ) {
    var allClass = wrapper.querySelectorAll( '.tw-class-card' );
    for ( var c = 0; c < allClass.length; c++ ) {
        allClass[ c ].classList.remove( 'selected' );
        allClass[ c ].setAttribute( 'aria-pressed', 'false' );
    }
    classCard.classList.add( 'selected' );
    classCard.setAttribute( 'aria-pressed', 'true' );
    formState[ 'class' ] = classCard.dataset.charClass || classCard.dataset[ 'class' ] || '';
                NW_SFX.select();
                clearStepError( steps[ current ] );
                return;
            }

            // Node card
            var nodeCard = e.target.closest( '.tw-node-card' );
            if ( nodeCard && ! e.target.closest( 'button' ) ) {
                var allNode = wrapper.querySelectorAll( '.tw-node-card' );
                for ( var nd = 0; nd < allNode.length; nd++ ) allNode[ nd ].classList.remove( 'selected' );
                nodeCard.classList.add( 'selected' );
                formState.node_id    = nodeCard.dataset.nodeId || '';
                formState.node_label = nodeCard.dataset.label  || ( ( nodeCard.querySelector( '.tw-node-card__name' ) || {} ).textContent || '' );
                NW_SFX.select();
                clearStepError( steps[ current ] );
                return;
            }

            var btn = e.target.closest( 'button' );
            if ( ! btn ) return;

            // Avatar clear
            if ( btn.id === 'tw-avatar-clear' ) {
                formState.avatar_file = null;
                var fileInput = wrapper.querySelector( '#tw-char-avatar' );
                if ( fileInput ) fileInput.value = '';
                var preview  = wrapper.querySelector( '#tw-avatar-preview' );
                var selected = wrapper.querySelector( '#tw-avatar-selected' );
                if ( preview )  preview.style.display  = '';
                if ( selected ) selected.style.display = 'none';
                return;
            }

            // File browse trigger
            if ( btn.classList.contains( 'tw-upload-trigger' ) ) {
                var fi = wrapper.querySelector( '#tw-char-avatar' );
                if ( fi ) fi.click();
                return;
            }

            // Summary edit
            if ( btn.classList.contains( 'tw-summary-edit' ) ) {
                var goTo = parseInt( btn.dataset.goto, 10 );
                if ( ! isNaN( goTo ) ) { NW_SFX.nav(); showStep( goTo - 1 ); }
                return;
            }

            // Attribute stepper
            if ( btn.classList.contains( 'tw-attr-btn' ) ) {
                var attrKey = btn.dataset.attr;
                var dir     = btn.classList.contains( 'tw-attr-plus' ) ? 'up' : 'down';
                if ( attrKey ) {
                    var stateKey = 'attr_' + attrKey;
                    var val      = formState[ stateKey ] || ATTR_MIN;
                    var usedNow  = ATTR_KEYS.reduce( function ( s, k ) { return s + ( formState[ 'attr_' + k ] || ATTR_MIN ); }, 0 );
                    if ( dir === 'up' && val < ATTR_MAX && usedNow < ATTR_POOL ) {
                        formState[ stateKey ] = val + 1;
                        NW_SFX.nav();
                    } else if ( dir === 'down' && val > ATTR_MIN ) {
                        formState[ stateKey ] = val - 1;
                        NW_SFX.back();
                    }
                    // Stepper use de-activates preset highlight
                    var allPresetBtns = wrapper.querySelectorAll( '.tw-attr-preset-btn' );
                    for ( var pb = 0; pb < allPresetBtns.length; pb++ ) {
                        allPresetBtns[ pb ].classList.remove( 'active' );
                    }
                    renderAttrDisplay( wrapper );
                }
                return;
            }

            // Navigation
            var action = '';
            if      ( btn.classList.contains( 'tw-btn-deploy' ) ) action = 'submit';
            else if ( btn.classList.contains( 'tw-btn-prev' ) )   action = 'prev';
            else if ( btn.classList.contains( 'tw-btn-next' ) )   action = 'next';
            else if ( btn.classList.contains( 'tw-btn-nav' ) )    action = 'next';

            if ( action === 'prev' )   { goPrev(); return; }
            if ( action === 'next' )   { goNext(); return; }
            if ( action === 'submit' ) {
                if ( validateStep( current ) ) {
                    submitCharacter( wrapper, steps, current, spinner );
                }
            }
        } );

        // ── Change events ─────────────────────────────────────────────────────
        wrapper.addEventListener( 'change', function ( e ) {
            if ( e.target && e.target.classList.contains( 'tw-pronoun-radio' ) ) {
                var customInput = document.getElementById( 'tw-char-pronouns-custom' );
                if ( customInput ) {
                    customInput.style.display = e.target.value === 'custom' ? '' : 'none';
                    if ( e.target.value === 'custom' ) customInput.focus();
                }
                return;
            }
            if ( e.target && e.target.id === 'tw-node-select' ) {
                formState.node_id    = e.target.value || '';
                formState.node_label = e.target.options[ e.target.selectedIndex ]
                    ? e.target.options[ e.target.selectedIndex ].text : '';
                if ( formState.node_id ) { NW_SFX.select(); clearStepError( steps[ current ] ); }
            }
        } );

        // ── Keyboard: Enter/Space on cards ────────────────────────────────────
        wrapper.addEventListener( 'keydown', function ( e ) {
            if ( e.key !== 'Enter' && e.key !== ' ' ) return;
            var card = e.target.closest( '.tw-race-card, .tw-class-card, .tw-node-card' );
            if ( card ) { e.preventDefault(); card.click(); }
        } );

        // ── Avatar drag & drop ────────────────────────────────────────────────
        var dropBox   = wrapper.querySelector( '#tw-avatar-drop' );
        var fileInput = wrapper.querySelector( '#tw-char-avatar' );

        if ( dropBox ) {
            dropBox.addEventListener( 'dragover', function ( e ) {
                e.preventDefault();
                dropBox.classList.add( 'tw-upload-box--drag' );
            } );
            dropBox.addEventListener( 'dragleave', function () {
                dropBox.classList.remove( 'tw-upload-box--drag' );
            } );
            dropBox.addEventListener( 'drop', function ( e ) {
                e.preventDefault();
                dropBox.classList.remove( 'tw-upload-box--drag' );
                var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ];
                if ( file ) handleAvatarFile( wrapper, file );
            } );
        }

        if ( fileInput ) {
            fileInput.addEventListener( 'change', function () {
                if ( fileInput.files && fileInput.files[ 0 ] ) handleAvatarFile( wrapper, fileInput.files[ 0 ] );
            } );
        }

        showStep( 0 );
    }

    // ── Boot ──────────────────────────────────────────────────────────────────────
    function boot() {
        var wrapper = document.getElementById( 'tw-char-creator-wrapper' );
        if ( wrapper ) {
            init();
        } else {
            var _retry = 0;
            var _poll  = setInterval( function () {
                _retry++;
                if ( document.getElementById( 'tw-char-creator-wrapper' ) ) {
                    clearInterval( _poll );
                    init();
                } else if ( _retry > 50 ) {
                    clearInterval( _poll );
                }
            }, 100 );
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }

} )();
