/**
 * NeoWeaver — Character Creator
 * Unified file (merged from character-creator.js + tw-character-creator.js)
 * Includes inline step error validation handler.
 */

( function () {
    'use strict';

    // ── Constants ─────────────────────────────────────────────────────────────
    var ATTR_KEYS = [ 'strength', 'agility', 'intellect', 'charisma', 'endurance', 'perception' ];
    var ATTR_MIN  = 1;
    var ATTR_MAX  = 10;
    var ATTR_POOL = 30;

    // ── State ─────────────────────────────────────────────────────────────────
    var formState = {
        character_name : '',
        pronouns       : '',
        backstory      : '',
        race           : '',
        class          : '',
        node_id        : '',
        strength       : ATTR_MIN,
        agility        : ATTR_MIN,
        intellect      : ATTR_MIN,
        charisma       : ATTR_MIN,
        endurance      : ATTR_MIN,
        perception     : ATTR_MIN,
    };

    // ── Helpers ───────────────────────────────────────────────────────────────
    function esc( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    function setStatus( msg, isError ) {
        var el = document.getElementById( 'tw-char-status' );
        if ( ! el ) return;
        el.textContent  = msg;
        el.className    = 'tw-char-status' + ( isError ? ' tw-char-status--error' : '' );
    }

    // ── Inline step error helpers ─────────────────────────────────────────────
    function showStepError( stepEl, msg ) {
        var errEl = stepEl.querySelector( '.tw-step-error' );
        if ( ! errEl ) {
            errEl           = document.createElement( 'div' );
            errEl.className = 'tw-step-error';
            var navRow = stepEl.querySelector( '.tw-nav-row' );
            if ( navRow ) {
                stepEl.insertBefore( errEl, navRow );
            } else {
                stepEl.appendChild( errEl );
            }
        }
        errEl.innerHTML =
            '<span class="tw-step-error__icon">&#9888;</span>' +
            '<span class="tw-step-error__msg">' + esc( msg ) + '</span>';
        errEl.classList.add( 'visible' );
        errEl.classList.remove( 'tw-step-error--shake' );
        void errEl.offsetWidth; // reflow to restart animation
        errEl.classList.add( 'tw-step-error--shake' );
        errEl.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
    }

    function clearStepError( stepEl ) {
        if ( ! stepEl ) return;
        var errEl = stepEl.querySelector( '.tw-step-error' );
        if ( errEl ) {
            errEl.classList.remove( 'visible', 'tw-step-error--shake' );
        }
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    function init() {
        var wrapper = document.getElementById( 'tw-char-creator-wrapper' );
        if ( ! wrapper ) return;

        var steps   = wrapper.querySelectorAll( '.tw-step' );
        var current = 0;

        if ( ! steps.length ) return;

        // ── Show step ─────────────────────────────────────────────────────────
        function showStep( idx ) {
            steps.forEach( function ( s, i ) {
                s.classList.toggle( 'active', i === idx );
            } );
            current = idx;
            setStatus( '', false );
        }

        // ── Validation ────────────────────────────────────────────────────────
        function validateStep( idx ) {
            var step = steps[ idx ];
            if ( ! step ) return true;

            clearStepError( step );

            // Step 0 — identity
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
                        var customEl     = document.getElementById( 'tw-char-pronouns-custom' );
                        formState.pronouns = ( customEl ? customEl.value.trim() : '' ) || 'custom';
                    } else {
                        formState.pronouns = checkedRadio.value;
                    }
                }

                formState.backstory = ( wrapper.querySelector( '#tw-char-backstory' ) || {} ).value || '';
                return true;
            }

            // Race step
            if ( step.dataset.phase === 'RACE PROTOCOL' ) {
                if ( ! formState.race ) {
                    showStepError( step, 'ERROR: Select a race to continue. Click a race card above.' );
                    setStatus( 'ERROR: Select a race to continue.', true );
                    return false;
                }
                return true;
            }

            // Class step
            if ( step.dataset.phase === 'CLASS MATRIX' ) {
                if ( ! formState.class ) {
                    showStepError( step, 'ERROR: Select a class to continue. Click a class card above.' );
                    setStatus( 'ERROR: Select a class to continue.', true );
                    return false;
                }
                return true;
            }

            // Attributes step
            if ( step.dataset.phase === 'BIOMETRIC CALIBRATION' ) {
                var used = ATTR_KEYS.reduce( function ( sum, k ) {
                    return sum + ( formState[ 'attr_' + k ] || ATTR_MIN );
                }, 0 );
                if ( used !== ATTR_POOL ) {
                    showStepError( step,
                        'ERROR: Distribute all ' + ATTR_POOL + ' attribute points (' +
                        used + '/' + ATTR_POOL + ' used).'
                    );
                    setStatus( 'ERROR: Distribute all ' + ATTR_POOL + ' attribute points.', true );
                    return false;
                }
                return true;
            }

            // Node binding step
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

        // ── Navigation buttons ────────────────────────────────────────────────
wrapper.addEventListener( 'click', function ( e ) {
    var btn = e.target.closest( 'button' );
    if ( ! btn ) return;

    var action = btn.dataset.action;

    if ( ! action ) {
        if ( btn.classList.contains( 'tw-btn-nav' ) )    action = 'next';
        if ( btn.classList.contains( 'tw-btn-prev' ) )   action = 'prev';
        if ( btn.classList.contains( 'tw-btn-deploy' ) ) action = 'submit';
    }

    if ( ! action ) return;

            if ( action === 'prev' ) {
                clearStepError( steps[ current ] );
                setStatus( '', false );
                if ( current > 0 ) showStep( current - 1 );
                return;
            }

            if ( action === 'next' ) {
                if ( validateStep( current ) ) {
                    clearStepError( steps[ current ] );
                    setStatus( '', false );
                    if ( current < steps.length - 1 ) showStep( current + 1 );
                }
                return;
            }

            if ( action === 'submit' ) {
                if ( validateStep( current ) ) {
                    submitCharacter();
                }
            }
        } );

        // ── Race card selection ───────────────────────────────────────────────
        wrapper.addEventListener( 'click', function ( e ) {
            var card = e.target.closest( '.tw-race-card' );
            if ( ! card ) return;
            wrapper.querySelectorAll( '.tw-race-card' ).forEach( function ( c ) {
                c.classList.remove( 'selected' );
            } );
            card.classList.add( 'selected' );
            formState.race = card.dataset.race || '';
            var step = steps[ current ];
            if ( step ) clearStepError( step );
        } );

        // ── Class card selection ──────────────────────────────────────────────
        wrapper.addEventListener( 'click', function ( e ) {
            var card = e.target.closest( '.tw-class-card' );
            if ( ! card ) return;
            wrapper.querySelectorAll( '.tw-class-card' ).forEach( function ( c ) {
                c.classList.remove( 'selected' );
            } );
            card.classList.add( 'selected' );
            formState.class = card.dataset.charClass || card.dataset.class || '';
            var step = steps[ current ];
            if ( step ) clearStepError( step );
        } );

        // ── Node selection ────────────────────────────────────────────────────
        wrapper.addEventListener( 'change', function ( e ) {
            if ( e.target && e.target.id === 'tw-node-select' ) {
                formState.node_id = e.target.value || '';
                var step = steps[ current ];
                if ( step && formState.node_id ) clearStepError( step );
            }
        } );

        // ── Attribute controls ────────────────────────────────────────────────
        wrapper.addEventListener( 'click', function ( e ) {
            var btn  = e.target.closest( '.tw-attr-btn' );
            if ( ! btn ) return;
            var key  = btn.dataset.attr;
            var dir  = btn.dataset.dir; // 'up' | 'down'
            if ( ! key || ! dir ) return;

            var stateKey = 'attr_' + key;
            var val      = formState[ stateKey ] || ATTR_MIN;
            var used     = ATTR_KEYS.reduce( function ( s, k ) {
                return s + ( formState[ 'attr_' + k ] || ATTR_MIN );
            }, 0 );

            if ( dir === 'up' && val < ATTR_MAX && used < ATTR_POOL ) {
                formState[ stateKey ] = val + 1;
            } else if ( dir === 'down' && val > ATTR_MIN ) {
                formState[ stateKey ] = val - 1;
            }

            renderAttrDisplay( wrapper );
        } );

        // ── Submit ────────────────────────────────────────────────────────────
        function submitCharacter() {
            setStatus( 'Uploading agent profile…', false );

            var data = new FormData();
            data.append( 'action',         'neoweaver_create_character' );
            data.append( 'nonce',          ( window.neoweaver_ajax || {} ).nonce || '' );
            data.append( 'character_name', formState.character_name );
            data.append( 'pronouns',       formState.pronouns );
            data.append( 'backstory',      formState.backstory );
            data.append( 'race',           formState.race );
            data.append( 'char_class',     formState.class );
            data.append( 'node_id',        formState.node_id );

            ATTR_KEYS.forEach( function ( k ) {
                data.append( 'attr_' + k, formState[ 'attr_' + k ] || ATTR_MIN );
            } );

            fetch( ( window.neoweaver_ajax || {} ).ajax_url || '/wp-admin/admin-ajax.php', {
                method      : 'POST',
                credentials : 'same-origin',
                body        : data,
            } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
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
                    var step = steps[ current ];
                    if ( step ) showStepError( step, errMsg );
                }
            } )
            .catch( function () {
                setStatus( 'ERROR: Connection lost. Check your link and retry.', true );
                var step = steps[ current ];
                if ( step ) showStepError( step, 'Connection lost. Check your link and retry.' );
            } );
        }

        // ── Init display ──────────────────────────────────────────────────────
        renderAttrDisplay( wrapper );
        showStep( 0 );
    }

    // ── Attribute display renderer ────────────────────────────────────────────
    function renderAttrDisplay( wrapper ) {
        ATTR_KEYS.forEach( function ( key ) {
            var stateKey = 'attr_' + key;
            var val      = ( window._nwFormState && window._nwFormState[ stateKey ] ) || 1;
            var valEl    = wrapper.querySelector( '[data-attr-val="' + key + '"]' );
            if ( valEl ) valEl.textContent = val;
        } );

        // Pool remaining
        var used = ATTR_KEYS.reduce( function ( s, k ) {
            var v = ( window._nwFormState && window._nwFormState[ 'attr_' + k ] ) || 1;
            return s + v;
        }, 0 );
        var poolEl = wrapper.querySelector( '[data-attr-pool]' );
        if ( poolEl ) poolEl.textContent = ATTR_POOL - used;
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
