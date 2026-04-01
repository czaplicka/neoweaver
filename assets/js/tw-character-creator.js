/**
 * NeoWeaver — Character Creator
 * Unified file: identity • race (+ subrace) • class • attributes • node • avatar • summary
 */

( function () {
    'use strict';

    // ── Shared audio engine (Web Audio API — no external files needed) ─────────
    // Identical NW_SFX module used across all three creator wizards.
    var NW_SFX = ( function () {
        var ctx = null;
        function get() { return ctx || ( ctx = new ( window.AudioContext || window.webkitAudioContext )() ); }

        function beep( freq, type, duration, vol ) {
            freq     = freq     || 440;
            type     = type     || 'square';
            duration = duration || 0.08;
            vol      = vol      || 0.18;
            try {
                var ac = get(), o = ac.createOscillator(), g = ac.createGain();
                o.type = type;
                o.frequency.value = freq;
                g.gain.setValueAtTime( vol, ac.currentTime );
                g.gain.exponentialRampToValueAtTime( 0.001, ac.currentTime + duration );
                o.connect( g ); g.connect( ac.destination );
                o.start(); o.stop( ac.currentTime + duration );
            } catch ( e ) {}
        }

        return {
            nav:    function () { beep( 660, 'square',   0.06, 0.15 ); },
            select: function () { beep( 880, 'sine',     0.10, 0.20 ); },
            back:   function () { beep( 330, 'sawtooth', 0.08, 0.12 ); },
            deploy: function () { beep( 440, 'square', 0.1, 0.2 ); setTimeout( function () { beep( 660, 'sine', 0.15, 0.25 ); }, 120 ); },
            error:  function () { beep( 180, 'sawtooth', 0.18, 0.20 ); },
        };
    } )();

    // ── Shared spinner factory ─────────────────────────────────────────────────
    // Uses the same #tw-node-spinner element structure and tw-node-spinner.css
    // rules as the world creator. Returns { show, hide }.
    function makeSpinner( id, title, subtitle ) {
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

    var RACES = _cfg.races || [
        {
            key: 'human', label: 'Human', icon: '&#128100;', img: '',
            desc: 'Adaptable generalists. Bonus feat at character creation.', bonus: '+1 to any attribute',
            subraces: [
                { key: 'human_corp',   label: 'Corp Human',  desc: 'Raised in megacorp culture. Starts with extra Credits.' },
                { key: 'human_fringe', label: 'Fringe Human', desc: 'Grew up in the undercity. Stealth & survival instincts.' },
                { key: 'human_nomad',  label: 'Nomad Human',  desc: 'Migrant bloodline. Bonus to REFLEX and Endurance rolls.' },
            ],
        },
        {
            key: 'beastman', label: 'Beastman', icon: '&#128060;', img: '',
            desc: 'Hybrid of human and animal genetics. Enhanced senses and raw power.', bonus: '+1 BODY, darkvision',
            subraces: [
                { key: 'beastman_felid',  label: 'Felid',  desc: 'Cat-based hybrid. High agility, retractable claws.' },
                { key: 'beastman_ursine', label: 'Ursine', desc: 'Bear-based hybrid. High body, resistance to cold.' },
                { key: 'beastman_lupine', label: 'Lupine', desc: 'Wolf-based hybrid. Pack tactics bonus in group combat.' },
            ],
        },
        {
            key: 'synth', label: 'Synth', icon: '&#129302;', img: '',
            desc: 'Fully synthetic android. Immune to bio-hazards, requires maintenance.', bonus: '+1 MIND, no sleep needed',
            subraces: [
                { key: 'synth_mk1',   label: 'Mark I',   desc: 'Early model. Rugged but archaic firmware.' },
                { key: 'synth_mk3',   label: 'Mark III', desc: 'Military chassis. Combat subroutines pre-loaded.' },
                { key: 'synth_ghost', label: 'Ghost',    desc: 'Stealth model. Can disable digital signature.' },
            ],
        },
        {
            key: 'weaver', label: 'Weaver', icon: '&#10024;', img: '',
            desc: 'Born with innate connection to the NeoWeave. Arcane conduit in human form.', bonus: '+1 SPIRIT, mana sense',
            subraces: [
                { key: 'weaver_bright', label: 'Bright', desc: 'Light-aspected. Healing and barrier spells enhanced.' },
                { key: 'weaver_void',   label: 'Void',   desc: 'Entropy-aspected. Curses and drain spells enhanced.' },
                { key: 'weaver_echo',   label: 'Echo',   desc: 'Memory-aspected. Can replay seen spells once per session.' },
            ],
        },
    ];

    // ── State ─────────────────────────────────────────────────────────────────
    var formState = {
        character_name: '', pronouns: '', backstory: '',
        race: '', subrace: '', race_label: '',
        class: '', class_label: '',
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
        var el = document.getElementById( 'tw-char-status' ) || document.querySelector( '.tw-char-status' );
        if ( ! el ) return;
        el.textContent = msg;
        el.className   = 'tw-char-status' + ( isError ? ' tw-char-status--error' : '' );
    }

    // ── Step error helpers ────────────────────────────────────────────────────
    function showStepError( stepEl, msg ) {
        var errEl = stepEl.querySelector( '.tw-step-error' );
        if ( ! errEl ) {
            errEl           = document.createElement( 'div' );
            errEl.className = 'tw-step-error';
            var navRow = stepEl.querySelector( '.tw-nav-row' );
            navRow ? stepEl.insertBefore( errEl, navRow ) : stepEl.appendChild( errEl );
        }
        errEl.innerHTML = '<span class="tw-step-error__icon">&#9888;</span>' +
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
        if ( errEl ) errEl.classList.remove( 'visible', 'tw-step-error--shake' );
    }

    // ── Race grid ─────────────────────────────────────────────────────────────
    function buildRaceCard( race ) {
        var imgSrc  = race.img || ( _cfg.race_images && _cfg.race_images[ race.key ] ) || '';
        var imgHtml = imgSrc
            ? '<div class="tw-race-card__img-wrap"><img class="tw-race-card__img" src="' + esc( imgSrc ) + '" alt="' + esc( race.label ) + '" width="220" height="220" loading="lazy" /></div>'
            : '<div class="tw-race-card__img-wrap tw-race-card__img-wrap--placeholder"><span class="tw-race-card__icon">' + ( race.icon || '&#10067;' ) + '</span></div>';
        return '<div class="tw-race-card" data-race="' + esc( race.key ) + '" role="button" tabindex="0" aria-label="Select race: ' + esc( race.label ) + '">' +
            imgHtml +
            '<div class="tw-race-card__body">' +
                '<h4 class="tw-race-card__name">' + esc( race.label ) + '</h4>' +
                '<p class="tw-race-card__desc">' + esc( race.desc ) + '</p>' +
                '<span class="tw-race-card__bonus">' + esc( race.bonus || '' ) + '</span>' +
            '</div></div>';
    }

    function buildSubraceCard( sub ) {
        return '<div class="tw-race-card tw-subrace-card" data-subrace="' + esc( sub.key ) + '" role="button" tabindex="0" aria-label="Select subrace: ' + esc( sub.label ) + '">' +
            '<div class="tw-race-card__body">' +
                '<h4 class="tw-race-card__name">' + esc( sub.label ) + '</h4>' +
                '<p class="tw-race-card__desc">' + esc( sub.desc ) + '</p>' +
            '</div></div>';
    }

    function renderRaceGrid( wrapper ) {
        var grid = wrapper.querySelector( '#tw-race-grid' );
        if ( ! grid || grid.dataset.rendered ) return;
        grid.innerHTML     = RACES.map( buildRaceCard ).join( '' );
        grid.dataset.rendered = '1';
    }

    function showSubraces( wrapper, raceKey ) {
        var raceData = RACES.filter( function ( r ) { return r.key === raceKey; } )[ 0 ];
        var section  = wrapper.querySelector( '#tw-subrace-section' );
        var grid     = wrapper.querySelector( '#tw-subrace-grid' );
        if ( ! section || ! grid ) return;
        if ( ! raceData || ! raceData.subraces || ! raceData.subraces.length ) {
            section.style.display = 'none';
            return;
        }
        grid.innerHTML        = raceData.subraces.map( buildSubraceCard ).join( '' );
        section.style.display = '';
    }

    // ── Class grid (AJAX) ─────────────────────────────────────────────────────
    function fetchClassGrid( wrapper ) {
        var grid = wrapper.querySelector( '#tw-class-grid' );
        if ( ! grid || grid.dataset.rendered ) return;
        var fd = new FormData();
        fd.append( 'action', 'neoweaver_get_classes' );
        fd.append( 'nonce',  nonce() );
        fetch( ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: fd } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res.success && res.data && res.data.length ) {
                    grid.innerHTML = res.data.map( function ( cls ) {
                        var imgH = cls.img
                            ? '<div class="tw-class-card__img-wrap"><img class="tw-class-card__img" src="' + esc( cls.img ) + '" alt="' + esc( cls.label ) + '" width="220" height="220" loading="lazy"/></div>'
                            : '<div class="tw-class-card__img-wrap tw-class-card__img-wrap--placeholder"><span class="tw-class-card__icon">' + ( cls.icon || '&#128100;' ) + '</span></div>';
                        return '<div class="tw-class-card" data-char-class="' + esc( cls.key ) + '" data-label="' + esc( cls.label ) + '" role="button" tabindex="0" aria-label="Select class: ' + esc( cls.label ) + '">' +
                            imgH + '<div class="tw-class-card__body"><h4 class="tw-class-card__name">' + esc( cls.label ) + '</h4><p class="tw-class-card__desc">' + esc( cls.desc || '' ) + '</p></div></div>';
                    } ).join( '' );
                    grid.dataset.rendered = '1';
                } else {
                    grid.innerHTML = '<p class="tw-empty-state">No classes available. Check back after world configuration.</p>';
                }
            } )
            .catch( function () {
                grid.innerHTML = '<p class="tw-error">ERROR: Class data unavailable. Check connection.</p>';
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
                        var imgH = node.img
                            ? '<div class="tw-node-card__img-wrap"><img class="tw-node-card__img" src="' + esc( node.img ) + '" alt="' + esc( node.label ) + '" width="280" height="160" loading="lazy"/></div>'
                            : '';
                        return '<div class="tw-node-card" data-node-id="' + esc( node.id ) + '" data-label="' + esc( node.label ) + '" role="button" tabindex="0" aria-label="Select node: ' + esc( node.label ) + '">' +
                            imgH + '<div class="tw-node-card__body"><h4 class="tw-node-card__name">' + esc( node.label ) + '</h4><p class="tw-node-card__desc">' + esc( node.desc || '' ) + '</p></div></div>';
                    } ).join( '' );
                    grid.dataset.rendered = '1';
                } else {
                    grid.innerHTML = '<p class="tw-empty-state">No nodes available. <a class="tw-link" href="/create-world/">Deploy a Node first &rarr;</a></p>';
                }
            } )
            .catch( function () {
                grid.innerHTML = '<p class="tw-error">ERROR: Node scan failed. Check connection.</p>';
            } );
    }

    // ── Summary ───────────────────────────────────────────────────────────────
    function updateSummary( wrapper ) {
        var set = function ( id, val ) {
            var el = wrapper.querySelector( '#tw-summary-' + id );
            if ( el ) el.textContent = val || '—';
        };
        set( 'character_name', formState.character_name );
        set( 'pronouns',       formState.pronouns );
        set( 'backstory',      formState.backstory ? formState.backstory.substring( 0, 80 ) + ( formState.backstory.length > 80 ? '…' : '' ) : '' );
        set( 'race',           formState.race_label  || formState.race );
        set( 'class',          formState.class_label || formState.class );
        set( 'node_id',        formState.node_label  || formState.node_id );
        var attrsStr = ATTR_KEYS.map( function ( k ) {
            return k.toUpperCase() + ':' + ( formState[ 'attr_' + k ] || ATTR_MIN );
        } ).join( ' · ' );
        set( 'attrs', attrsStr );
        var avatarEl = wrapper.querySelector( '#tw-summary-avatar' );
        if ( avatarEl ) avatarEl.textContent = formState.avatar_file ? formState.avatar_file.name : '—';
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    function init() {
        var wrapper = document.getElementById( 'tw-char-creator-wrapper' );
        if ( ! wrapper ) return;

        var steps   = wrapper.querySelectorAll( '.tw-step' );
        var current = 0;
        if ( ! steps.length ) return;

        // Spinner — uses tw-node-spinner.css (same as world creator)
        var spinner = makeSpinner(
            'tw-char-spinner',
            '// SYNCHRONIZING AGENT…',
            'Writing operative data to the NeoWeave grid.'
        );

        renderRaceGrid( wrapper );

        // ── showStep ──────────────────────────────────────────────────────────
        function showStep( idx ) {
            steps.forEach( function ( s, i ) { s.classList.toggle( 'active', i === idx ); } );
            current = idx;
            setStatus( '', false );

            var phase = steps[ idx ] && steps[ idx ].dataset.phase;
            if ( phase === 'CLASS MATRIX' )  fetchClassGrid( wrapper );
            if ( phase === 'NODE BINDING' )  fetchNodeGrid( wrapper );
            if ( phase === 'SYSTEM REVIEW' ) updateSummary( wrapper );

            var fillEl  = document.getElementById( 'tw-char-progress-fill' );
            var stepEl  = document.getElementById( 'tw-char-step-current' );
            var phaseEl = document.getElementById( 'tw-char-progress-phase' );
            if ( fillEl )  fillEl.style.width  = Math.round( ( ( idx + 1 ) / steps.length ) * 100 ) + '%';
            if ( stepEl )  stepEl.textContent   = idx + 1;
            if ( phaseEl ) phaseEl.textContent  = phase || '';
            wrapper.querySelectorAll( '.tw-progress-tick' ).forEach( function ( tick ) {
                var n = parseInt( tick.dataset.tick, 10 );
                tick.classList.toggle( 'active', n <= idx + 1 );
            } );
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
                    showStepError( step, 'ERROR: Agent designation is required. Enter a name to proceed.' );
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
                formState.backstory = ( wrapper.querySelector( '#tw-char-backstory' ) || {} ).value || '';
                return true;
            }

            if ( step.dataset.phase === 'RACE PROTOCOL' ) {
                if ( ! formState.race ) {
                    showStepError( step, 'ERROR: Select a race to continue. Click a race card above.' );
                    setStatus( 'ERROR: Select a race to continue.', true );
                    return false;
                }
                return true;
            }

            if ( step.dataset.phase === 'CLASS MATRIX' ) {
                if ( ! formState.class ) {
                    showStepError( step, 'ERROR: Select a class to continue. Click a class card above.' );
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
                    showStepError( step, 'ERROR: Bind the agent to a Node before continuing. Select a Node above.' );
                    setStatus( 'ERROR: Bind the agent to a Node before continuing.', true );
                    return false;
                }
                return true;
            }

            return true;
        }

        // ── Navigation ────────────────────────────────────────────────────────
        wrapper.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( 'button' );
            if ( ! btn ) return;

            if ( btn.classList.contains( 'tw-summary-edit' ) ) {
                var goTo = parseInt( btn.dataset.goto, 10 );
                if ( ! isNaN( goTo ) ) { NW_SFX.nav(); showStep( goTo - 1 ); }
                return;
            }

            var action = btn.dataset.action || '';
            if ( ! action ) {
                if      ( btn.classList.contains( 'tw-btn-deploy' ) ) action = 'submit';
                else if ( btn.classList.contains( 'tw-btn-prev' ) )   action = 'prev';
                else if ( btn.classList.contains( 'tw-btn-next' ) )   action = 'next';
                else if ( btn.classList.contains( 'tw-btn-nav' ) )    action = 'next';
            }
            if ( ! action ) return;

            if ( action === 'prev' ) {
                clearStepError( steps[ current ] );
                setStatus( '', false );
                if ( current > 0 ) { NW_SFX.back(); showStep( current - 1 ); }
                return;
            }
            if ( action === 'next' ) {
                if ( validateStep( current ) ) {
                    clearStepError( steps[ current ] );
                    setStatus( '', false );
                    if ( current < steps.length - 1 ) { NW_SFX.nav(); showStep( current + 1 ); }
                }
                return;
            }
            if ( action === 'submit' ) {
                if ( validateStep( current ) ) submitCharacter();
            }
        } );

        // ── Race card selection ───────────────────────────────────────────────
        wrapper.addEventListener( 'click', function ( e ) {
            var sub = e.target.closest( '.tw-subrace-card' );
            if ( sub ) {
                wrapper.querySelectorAll( '.tw-subrace-card' ).forEach( function ( c ) { c.classList.remove( 'selected' ); } );
                sub.classList.add( 'selected' );
                formState.subrace = sub.dataset.subrace || '';
                NW_SFX.select();
                clearStepError( steps[ current ] );
                return;
            }
            var card = e.target.closest( '.tw-race-card' );
            if ( ! card ) return;
            wrapper.querySelectorAll( '.tw-race-card:not(.tw-subrace-card)' ).forEach( function ( c ) { c.classList.remove( 'selected' ); } );
            card.classList.add( 'selected' );
            formState.race       = card.dataset.race || '';
            formState.race_label = card.querySelector( '.tw-race-card__name' ) ? card.querySelector( '.tw-race-card__name' ).textContent : formState.race;
            formState.subrace    = '';
            wrapper.querySelectorAll( '.tw-subrace-card' ).forEach( function ( c ) { c.classList.remove( 'selected' ); } );
            showSubraces( wrapper, formState.race );
            NW_SFX.select();
            clearStepError( steps[ current ] );
        } );

        wrapper.addEventListener( 'keydown', function ( e ) {
            if ( e.key !== 'Enter' && e.key !== ' ' ) return;
            var card = e.target.closest( '.tw-race-card, .tw-class-card, .tw-node-card' );
            if ( card ) { e.preventDefault(); card.click(); }
        } );

        // ── Class card selection ──────────────────────────────────────────────
        wrapper.addEventListener( 'click', function ( e ) {
            var card = e.target.closest( '.tw-class-card' );
            if ( ! card ) return;
            wrapper.querySelectorAll( '.tw-class-card' ).forEach( function ( c ) { c.classList.remove( 'selected' ); } );
            card.classList.add( 'selected' );
            formState.class = card.dataset.charClass || card.dataset.class || '';
            var nameEl = card.querySelector( '.tw-class-card__name' );
            formState.class_label = card.dataset.label || ( nameEl ? nameEl.textContent : formState.class );
            NW_SFX.select();
            clearStepError( steps[ current ] );
        } );

        // ── Node card selection ───────────────────────────────────────────────
        wrapper.addEventListener( 'click', function ( e ) {
            var card = e.target.closest( '.tw-node-card' );
            if ( ! card ) return;
            wrapper.querySelectorAll( '.tw-node-card' ).forEach( function ( c ) { c.classList.remove( 'selected' ); } );
            card.classList.add( 'selected' );
            formState.node_id    = card.dataset.nodeId || '';
            formState.node_label = card.dataset.label  || ( card.querySelector( '.tw-node-card__name' ) ? card.querySelector( '.tw-node-card__name' ).textContent : '' );
            NW_SFX.select();
            clearStepError( steps[ current ] );
        } );

        // ── Node <select> fallback ────────────────────────────────────────────
        wrapper.addEventListener( 'change', function ( e ) {
            if ( e.target && e.target.id === 'tw-node-select' ) {
                formState.node_id    = e.target.value || '';
                formState.node_label = e.target.options[ e.target.selectedIndex ] ? e.target.options[ e.target.selectedIndex ].text : '';
                if ( formState.node_id ) { NW_SFX.select(); clearStepError( steps[ current ] ); }
            }
        } );

        // ── Attribute controls ────────────────────────────────────────────────
        wrapper.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '.tw-attr-btn' );
            if ( ! btn ) return;
            var key = btn.dataset.attr;
            var dir = btn.dataset.dir;
            if ( ! dir ) {
                if ( btn.classList.contains( 'tw-attr-plus' ) )  dir = 'up';
                if ( btn.classList.contains( 'tw-attr-minus' ) ) dir = 'down';
            }
            if ( ! key || ! dir ) return;
            var stateKey = 'attr_' + key;
            var val      = formState[ stateKey ] || ATTR_MIN;
            var used     = ATTR_KEYS.reduce( function ( s, k ) { return s + ( formState[ 'attr_' + k ] || ATTR_MIN ); }, 0 );
            if ( dir === 'up' && val < ATTR_MAX && used < ATTR_POOL ) {
                formState[ stateKey ] = val + 1;
                NW_SFX.nav();
            } else if ( dir === 'down' && val > ATTR_MIN ) {
                formState[ stateKey ] = val - 1;
                NW_SFX.back();
            }
            renderAttrDisplay( wrapper );
        } );

        // ── Avatar upload ─────────────────────────────────────────────────────
        var fileInput = wrapper.querySelector( '#tw-char-avatar' );
        if ( fileInput ) {
            wrapper.addEventListener( 'click', function ( e ) {
                if ( e.target.closest( '.tw-upload-trigger' ) ) fileInput.click();
            } );
            var dropBox = wrapper.querySelector( '#tw-avatar-drop' );
            if ( dropBox ) {
                dropBox.addEventListener( 'dragover',  function ( e ) { e.preventDefault(); dropBox.classList.add( 'tw-upload-box--drag' ); } );
                dropBox.addEventListener( 'dragleave', function ()    { dropBox.classList.remove( 'tw-upload-box--drag' ); } );
                dropBox.addEventListener( 'drop',      function ( e ) {
                    e.preventDefault();
                    dropBox.classList.remove( 'tw-upload-box--drag' );
                    var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ];
                    if ( file ) handleAvatarFile( wrapper, file );
                } );
            }
            fileInput.addEventListener( 'change', function () {
                if ( fileInput.files && fileInput.files[ 0 ] ) handleAvatarFile( wrapper, fileInput.files[ 0 ] );
            } );
            var clearBtn = wrapper.querySelector( '#tw-avatar-clear' );
            if ( clearBtn ) {
                clearBtn.addEventListener( 'click', function () {
                    formState.avatar_file = null;
                    fileInput.value       = '';
                    var preview  = wrapper.querySelector( '#tw-avatar-preview' );
                    var selected = wrapper.querySelector( '#tw-avatar-selected' );
                    if ( preview )  preview.style.display  = '';
                    if ( selected ) selected.style.display = 'none';
                } );
            }
        }

        // ── Custom pronouns toggle ────────────────────────────────────────────
        wrapper.addEventListener( 'change', function ( e ) {
            if ( e.target && e.target.classList.contains( 'tw-pronoun-radio' ) ) {
                var customInput = document.getElementById( 'tw-char-pronouns-custom' );
                if ( customInput ) {
                    customInput.style.display = e.target.value === 'custom' ? '' : 'none';
                    if ( e.target.value === 'custom' ) customInput.focus();
                }
            }
        } );

        // ── Submit ────────────────────────────────────────────────────────────
        function submitCharacter() {
            setStatus( 'Uploading agent profile…', false );
            var submitBtn = wrapper.querySelector( '#tw-char-submit, .tw-btn-deploy' );
            if ( submitBtn ) { submitBtn.disabled = true; submitBtn.textContent = 'SYNCHRONIZING…'; }

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
            data.append( 'char_class',     formState.class );
            data.append( 'node_id',        formState.node_id );
            if ( formState.avatar_file ) data.append( 'avatar', formState.avatar_file );
            ATTR_KEYS.forEach( function ( k ) { data.append( 'attr_' + k, formState[ 'attr_' + k ] || ATTR_MIN ); } );

            var t0 = Date.now();
            fetch( ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: data } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( res ) {
                    var wait = Math.max( 0, 2500 - ( Date.now() - t0 ) );
                    setTimeout( function () {
                        spinner.hide();
                        if ( res.success ) {
                            setStatus( 'Agent profile created. Welcome to the Grid.', false );
                            wrapper.innerHTML = '<div class="tw-success">' +
                                '<p class="tw-success__msg">&#10003; ' + esc( res.data && res.data.message ? res.data.message : 'Character created!' ) + '</p>' +
                                ( res.data && res.data.redirect
                                    ? '<a href="' + esc( res.data.redirect ) + '" class="tw-btn tw-btn--primary">Enter the Grid</a>'
                                    : '' ) +
                                '</div>';
                        } else {
                            var errMsg = res.data && res.data.message ? res.data.message : 'Submission failed. Retry.';
                            setStatus( 'ERROR: ' + errMsg, true );
                            NW_SFX.error();
                            if ( submitBtn ) { submitBtn.disabled = false; submitBtn.textContent = '&#9658; SYNCHRONIZE AGENT'; }
                            showStepError( steps[ current ], errMsg );
                        }
                    }, wait );
                } )
                .catch( function () {
                    spinner.hide();
                    setStatus( 'ERROR: Connection lost. Check your link and retry.', true );
                    NW_SFX.error();
                    if ( submitBtn ) { submitBtn.disabled = false; submitBtn.textContent = '&#9658; SYNCHRONIZE AGENT'; }
                    showStepError( steps[ current ], 'Connection lost. Check your link and retry.' );
                } );
        }

        renderAttrDisplay( wrapper );
        showStep( 0 );
    }

    // ── Avatar file handler ───────────────────────────────────────────────────
    function handleAvatarFile( wrapper, file ) {
        var maxBytes = 2 * 1024 * 1024;
        var allowed  = [ 'image/jpeg', 'image/png', 'image/webp' ];
        if ( ! file || allowed.indexOf( file.type ) === -1 || file.size > maxBytes ) {
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
            if ( imgEl )    imgEl.src             = ev.target.result;
            if ( preview )  preview.style.display  = 'none';
            if ( selected ) selected.style.display = '';
        };
        reader.readAsDataURL( file );
    }

    // ── Attribute display renderer ────────────────────────────────────────────
    function renderAttrDisplay( wrapper ) {
        ATTR_KEYS.forEach( function ( key ) {
            var val     = formState[ 'attr_' + key ] || ATTR_MIN;
            var valEl   = wrapper.querySelector( '[data-attr-val="' + key + '"]' );
            var inputEl = wrapper.querySelector( '#tw-attr-' + key );
            if ( valEl   ) valEl.textContent = val;
            if ( inputEl ) inputEl.value     = val;
            wrapper.querySelectorAll( '[data-attr="' + key + '"] .tw-pip' ).forEach( function ( pip ) {
                pip.classList.toggle( 'active', parseInt( pip.dataset.pip, 10 ) <= val );
            } );
        } );
        var used     = ATTR_KEYS.reduce( function ( s, k ) { return s + ( formState[ 'attr_' + k ] || ATTR_MIN ); }, 0 );
        var poolEl   = wrapper.querySelector( '[data-attr-pool]' );
        var remainEl = document.getElementById( 'tw-attr-remaining' );
        if ( poolEl )   poolEl.textContent   = ATTR_POOL - used;
        if ( remainEl ) remainEl.textContent = ATTR_POOL - used;
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        var _nwRetry = 0;
        var _nwPoll  = setInterval( function () {
            _nwRetry++;
            if ( document.getElementById( 'tw-char-creator-wrapper' ) ) {
                clearInterval( _nwPoll );
                init();
            } else if ( _nwRetry > 50 ) {
                clearInterval( _nwPoll );
            }
        }, 100 );
    }

} )();
