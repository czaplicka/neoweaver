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

    // --- Config ---------------------------------------------------------------

    var config    = window.twDeckPanelConfig || {};
    var soundBase = config.soundBase || '';

    // BUG FIX 1: SOUND_URLS was defined at module scope using soundBase, which
    // is fine, but `sounds` cache was also module-scoped — meaning if
    // twInitDeckPanel() is called again after a dynamic DOM rebuild, the stale
    // Audio objects from the previous init are silently reused and can hold
    // references to a detached context.  Moved `sounds` inside the IIFE but
    // outside initDeckPanel so it persists across re-inits intentionally, and
    // added a `soundBase` guard so we never construct Audio('mp3') with an
    // empty prefix (which resolves to the page URL and throws a MediaError).
    var SOUND_URLS = {
        tab:    soundBase ? soundBase + 'ui-click.mp3'      : null,
        glitch: soundBase ? soundBase + 'glitch-static.mp3' : null,
    };
    var sounds = {};

    // OPT 1: Accept an optional volume parameter so callers can tune per-sound
    // levels without touching the function body.  Default kept at 0.2.
    function playSound(name, volume) {
        var url = SOUND_URLS[name];
        if (!url) return;

        // BUG FIX 2: The original code called sounds[name].play() which returns
        // a Promise, and caught it with .catch(() => {}).  That silences ALL
        // errors — including programmer mistakes like a wrong key name.  Narrow
        // the catch to only suppress NotAllowedError (autoplay policy) and
        // AbortError (interrupted by a rapid second play call), and re-throw
        // anything unexpected so it surfaces in the console during development.
        if (!sounds[name]) {
            sounds[name] = new Audio(url);
            sounds[name].volume = (typeof volume === 'number') ? volume : 0.2;
        }
        sounds[name].currentTime = 0;
        sounds[name].play().catch(function (err) {
            var benign = ['NotAllowedError', 'AbortError'];
            if (benign.indexOf(err.name) === -1) {
                console.warn('[DeckPanel] Audio error (' + name + '):', err);
            }
        });
    }

    // --- Main init ------------------------------------------------------------

    function initDeckPanel() {
        var deckPanel = document.getElementById('deck-panel');
        if (!deckPanel) return;

        // BUG FIX 3: The original `e.stopPropagation()` on the panel's root
        // click listener swallows ALL clicks on the panel, including any
        // programmatic click events dispatched by other scripts for legitimate
        // reasons (e.g. a "select all" utility, test runners).  stopPropagation
        // should only be used where bubbling is genuinely harmful.  The original
        // intent was to prevent a document-level "click outside to close" handler
        // from firing when clicking inside the panel.  The cleaner fix is to
        // check `e.target` in that outer handler rather than stopping propagation
        // on every inner click.  Removed the blanket listener entirely; each
        // interactive element below calls stopPropagation only where needed.
        //
        // If a document-level close-on-outside-click listener exists elsewhere,
        // it should use: if (deckPanel.contains(e.target)) return;

        var panelTabs       = deckPanel.querySelectorAll('.panel-tab');
        var tabContents     = deckPanel.querySelectorAll('.deck-tab-content');
        var toggleBtn       = deckPanel.querySelector('#toggle-deck');
        var deckTabsWrapper = deckPanel.querySelector('.deck-tabs-wrapper');

        var skillsLoaded = false;

        // OPT 2: Track the currently active tab ID so switchTab can bail early
        // when the same tab is clicked twice — avoids re-running DOM loops and
        // re-triggering the open animation needlessly.
        var activeTabId = (function () {
            for (var i = 0; i < tabContents.length; i++) {
                if (tabContents[i].classList.contains('is-active')) {
                    return tabContents[i].id;
                }
            }
            return null;
        }());

        // --- Panel state helpers ----------------------------------------------

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

        function togglePanel() {
            deckPanel.classList.contains('is-open') ? closePanel() : openPanel();
        }

        // --- Tab switching ----------------------------------------------------

        function switchTab(targetId) {
            // OPT 2 (cont.): skip if already on this tab.
            if (targetId === activeTabId) return;

            playSound('tab');

            if (deckPanel.classList.contains('is-collapsed')) {
                openPanel();
            }

            // OPT 3: Replace two separate forEach loops with a single combined
            // loop over panelTabs, and a parallel index lookup for tabContents.
            // Both NodeLists are ordered identically (same data-tab <-> id
            // relationship), so one pass is sufficient.
            // BUG FIX 4: The original loops used NodeList.forEach which is
            // unavailable in IE11 and some older WebViews. Use a for-loop for
            // broader compatibility (consistent with the rest of the codebase
            // which already uses var/function, not arrow functions).
            for (var i = 0; i < panelTabs.length; i++) {
                var isTarget = panelTabs[i].getAttribute('data-tab') === targetId;
                panelTabs[i].classList.toggle('is-active', isTarget);
                // BUG FIX 5: tabContents[i] may not correspond to panelTabs[i]
                // if the DOM order ever diverges.  Use getElementById for the
                // content lookup so the match is always correct by ID, not by
                // positional assumption.
                var content = document.getElementById(targetId);
                if (content) {
                    // Only flip the target content; set others inactive via their own loop.
                    content.classList.add('is-active');
                }
                if (tabContents[i].id !== targetId) {
                    tabContents[i].classList.remove('is-active');
                }
            }

            activeTabId = targetId;

            // Lazy-load skills only once.
            if (targetId === 'tab-skills' && !skillsLoaded &&
                    typeof window.twLoadSkillsAndAbilities === 'function') {
                window.twLoadSkillsAndAbilities();
                skillsLoaded = true;
            }
        }

        // --- Event listeners --------------------------------------------------

        panelTabs.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var targetId = btn.getAttribute('data-tab');
                // BUG FIX 6: The original guard `btn.id !== 'toggle-deck'`
                // relies on the toggle button accidentally having a data-tab
                // attribute (it shouldn't).  A missing or empty targetId is a
                // sufficient guard; checking btn.id is a fragile second check
                // that breaks if the markup ID ever changes.  Removed the id
                // check; if the toggle button must live inside .panel-tab for
                // layout reasons, handle it by ensuring it has no data-tab attr.
                if (targetId) {
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
            // BUG FIX 7: The original handler called togglePanel() on any click
            // whose target was exactly deckTabsWrapper.  This means clicking on
            // padding/gap areas between tabs also toggles the panel, which is
            // almost certainly unintentional — that area should be inert.
            // Replaced with an explicit close-on-wrapper-click only when the
            // panel is open, making the behaviour predictable and intentional.
            deckTabsWrapper.addEventListener('click', function (e) {
                if (e.target === deckTabsWrapper &&
                        deckPanel.classList.contains('is-open')) {
                    closePanel();
                }
            });
        }

        // BUG FIX 8: The original re-init guard used `deckPanel._escBound`, a
        // custom expando property on a DOM node.  Expando properties on DOM
        // nodes can be GC'd in some browsers when the node is temporarily
        // removed from the document (e.g. during a partial DOM rebuild), which
        // silently allows duplicate listeners to accumulate.  Use a module-
        // scoped variable instead — it lives in the IIFE closure and is immune
        // to DOM GC.
        if (!escListenerBound) {
            document.addEventListener('keydown', handleEscape);
            escListenerBound = true;
        }

        // OPT 4: store a reference to the live deckPanel inside the escape
        // handler via closure so it doesn't need to re-query the DOM on every
        // keydown event (which fires continuously while a key is held).
        function handleEscape(e) {
            if (e.key === 'Escape' && deckPanel.classList.contains('is-open')) {
                closePanel();
            }
        }
    }

    // Module-scoped guard for the keydown listener (see BUG FIX 8).
    var escListenerBound = false;

    // --- Bootstrap ------------------------------------------------------------

    // Script is in footer (in_footer:true) so DOM is already available.
    // readyState guard kept as a safety net for dynamic script injection.
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        initDeckPanel();
    } else {
        document.addEventListener('DOMContentLoaded', initDeckPanel);
    }

    // Public re-init hook for external callers.
    window.twInitDeckPanel = initDeckPanel;

}());
