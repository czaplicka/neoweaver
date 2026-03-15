/**
 * Deck Panel — tab switching, toggle and keyboard interactions.
 *
 * Opt 3 fix: extracted from the inline <script> in shortcode-deck-panel.php.
 * Enqueued via wp_enqueue_script( 'tw-deck-panel' ) with in_footer:true,
 * so it loads after the HTML is parsed and never blocks rendering.
 *
 * Runtime config injected by wp_localize_script():
 *   window.twDeckPanelConfig.soundBase  — trailing-slash URL to uploads/sounds/
 *
 * Exposes window.twInitDeckPanel for any external caller that needs to
 * re-initialise the panel after a dynamic DOM update.
 *
 * @package Neoweaver
 */
(function () {
    'use strict';

    // Bug 7 fix: PHP content_url() value now arrives via wp_localize_script
    // instead of being echo'd inline.  Falls back to empty string so audio
    // simply silently fails rather than throwing a JS error.
    var config   = window.twDeckPanelConfig || {};
    var soundBase = config.soundBase || '';

    // Bug 4 fix: defer Audio construction to first user gesture.
    var SOUND_URLS = {
        tab:    soundBase + 'ui-click.mp3',
        glitch: soundBase + 'glitch-static.mp3',
    };
    var sounds = {};

    function playSound(name) {
        if (!SOUND_URLS[name]) return;
        if (!sounds[name]) {
            sounds[name] = new Audio(SOUND_URLS[name]);
            sounds[name].volume = 0.2;
        }
        sounds[name].currentTime = 0;
        sounds[name].play().catch(function () {});
    }

    function initDeckPanel() {
        var deckPanel = document.getElementById('deck-panel');
        if (!deckPanel) return;

        deckPanel.addEventListener('click', function (e) { e.stopPropagation(); });

        // Bug 1 fix: scope all selectors to deckPanel.
        // Bug 3 fix: deckPanel.querySelector instead of getElementById.
        // Opt 1 fix: cache NodeLists once; switchTab closes over them.
        var panelTabs       = deckPanel.querySelectorAll('.panel-tab');
        var tabContents     = deckPanel.querySelectorAll('.deck-tab-content');
        var toggleBtn       = deckPanel.querySelector('#toggle-deck');
        var deckTabsWrapper = deckPanel.querySelector('.deck-tabs-wrapper');

        // Bug 9 fix: load skills data only once per session.
        var skillsLoaded = false;

        // Opt 2 fix: single source of truth for panel state transitions.
        function openPanel() {
            deckPanel.classList.add('is-open');
            deckPanel.classList.remove('is-collapsed');
            playSound('glitch');
        }
        function closePanel() {
            deckPanel.classList.add('is-collapsed');
            deckPanel.classList.remove('is-open');
            playSound('glitch');
        }
        // Bug 5 fix: glitch sound on open/close only.
        function togglePanel() {
            deckPanel.classList.contains('is-open') ? closePanel() : openPanel();
        }

        function switchTab(targetId) {
            // Bug 5 fix: tab click plays only the click sound.
            playSound('tab');

            if (deckPanel.classList.contains('is-collapsed')) {
                openPanel();
            }

            tabContents.forEach(function (content) {
                content.classList.toggle('is-active', content.id === targetId);
            });

            panelTabs.forEach(function (btn) {
                btn.classList.toggle('is-active', btn.getAttribute('data-tab') === targetId);
            });

            if (targetId === 'tab-skills' && !skillsLoaded &&
                    typeof window.twLoadSkillsAndAbilities === 'function') {
                window.twLoadSkillsAndAbilities();
                skillsLoaded = true;
            }
        }

        panelTabs.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var targetId = btn.getAttribute('data-tab');
                if (targetId && btn.id !== 'toggle-deck') {
                    switchTab(targetId);
                }
            });
        });

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                togglePanel();
            });
        }

        if (deckTabsWrapper) {
            deckTabsWrapper.addEventListener('click', function (e) {
                if (e.target === deckTabsWrapper) {
                    togglePanel();
                }
            });
        }

        // Bug 6 fix: guard against stacking multiple keydown listeners on re-init.
        if (!deckPanel._escBound) {
            document.addEventListener('keydown', function handleEscape(e) {
                if (e.key === 'Escape' && deckPanel.classList.contains('is-open')) {
                    closePanel();
                }
            });
            deckPanel._escBound = true;
        }
    }

    // Script is in footer (in_footer:true), so DOM is already available.
    // The readyState guard is kept as a safety net for any edge-case where
    // the script might be loaded dynamically before parsing is complete.
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        initDeckPanel();
    } else {
        document.addEventListener('DOMContentLoaded', initDeckPanel);
    }

    window.twInitDeckPanel = initDeckPanel;
}());
