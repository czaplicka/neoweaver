<?php
/**
 * [TW] Deck Panel - UI tabs (Mission / Augments / Skills)
 *
 * Shortcode: [tw_deck_panel]
 *
 * Renders the sliding side-panel with three tabs and wires up all
 * toggle / keyboard / sound interactions via inline JS.
 *
 * CSS scope  : .neoweaver-screen #deck-panel
 * JS globals : window.twInitDeckPanel
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Deck Panel HTML + JS.
 * Called by Neoweaver_Public::shortcode_deck_panel().
 *
 * @return string
 */
function tw_deck_panel_render(): string {
	ob_start();
	?>
<div id="deck-panel" class="is-collapsed">
	<div class="deck-tabs-wrapper">

		<!-- Toggle button -->
		<button id="toggle-deck" class="panel-tab" aria-label="Toggle deck panel">&#9776;</button>

		<!-- Tab buttons -->
		<button class="panel-tab" data-tab="tab-mission">MISSION</button>
		<button class="panel-tab" data-tab="tab-augments">AUGMENTS</button>
		<button class="panel-tab" data-tab="tab-skills">SKILLS</button>

	</div>

	<!-- Tab content areas -->
	<div id="tab-mission"  class="deck-tab-content is-active">
		<h3 class="deck-tab-title">// MISSION_DATA</h3>
		<div id="tw-mission-content">Loading mission data...</div>
	</div>

	<div id="tab-augments" class="deck-tab-content">
		<h3 class="deck-tab-title">// AUGMENTS</h3>
		<div id="tw-augments-content">Loading augments...</div>
	</div>

	<div id="tab-skills" class="deck-tab-content">
		<h3 class="deck-tab-title">// SKILLS &amp; ABILITIES</h3>
		<div id="tw-skills-content">Loading skills...</div>
	</div>
</div>

<script>
(function () {
    // Bug 7 fix: hardcoded absolute URLs break on staging / local / domain migration.
    // content_url() resolves the correct wp-content URL for the current environment.
    const twSoundBase = '<?php echo esc_js( trailingslashit( content_url( 'uploads/sounds' ) ) ); ?>';

    // Bug 4 fix: defer Audio construction to first user gesture.
    const SOUND_URLS = {
        tab:    twSoundBase + 'ui-click.mp3',
        glitch: twSoundBase + 'glitch-static.mp3',
    };
    let sounds = {};

    function playSound(name) {
        if (!SOUND_URLS[name]) return;
        if (!sounds[name]) {
            sounds[name] = new Audio(SOUND_URLS[name]);
            sounds[name].volume = 0.2;
        }
        sounds[name].currentTime = 0;
        sounds[name].play().catch(() => {});
    }

    // Bug 5 fix: glitch sound only on panel open/close, not on tab switch.
    function togglePanel(deckPanel) {
        const isOpen = deckPanel.classList.contains('is-open');
        deckPanel.classList.toggle('is-open',      !isOpen);
        deckPanel.classList.toggle('is-collapsed',  isOpen);
        playSound('glitch');
    }

    function initDeckPanel() {
        console.log('initDeckPanel CALLED');
        const deckPanel = document.getElementById('deck-panel');
        if (!deckPanel) return;

        deckPanel.addEventListener('click', (e) => e.stopPropagation());

        // Bug 1 fix: scope all selectors to deckPanel.
        // Bug 3 fix: deckPanel.querySelector instead of getElementById.
        const panelTabs       = deckPanel.querySelectorAll('.panel-tab');
        const toggleBtn       = deckPanel.querySelector('#toggle-deck');
        const deckTabsWrapper = deckPanel.querySelector('.deck-tabs-wrapper');

        function switchTab(targetId) {
            const tabContents = deckPanel.querySelectorAll('.deck-tab-content');
            const tabs        = deckPanel.querySelectorAll('.panel-tab');

            // Bug 5 fix: tab click plays only the click sound.
            playSound('tab');

            if (deckPanel.classList.contains('is-collapsed')) {
                deckPanel.classList.remove('is-collapsed');
                deckPanel.classList.add('is-open');
                playSound('glitch');
            }

            tabContents.forEach((content) => {
                content.classList.toggle('is-active', content.id === targetId);
            });

            tabs.forEach((btn) => {
                btn.classList.toggle('is-active', btn.getAttribute('data-tab') === targetId);
            });

            if (targetId === 'tab-skills' && typeof window.twLoadSkillsAndAbilities === 'function') {
                window.twLoadSkillsAndAbilities();
            }
        }

        panelTabs.forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const targetId = btn.getAttribute('data-tab');
                if (targetId && btn.id !== 'toggle-deck') {
                    switchTab(targetId);
                }
            });
        });

        if (toggleBtn) {
            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                togglePanel(deckPanel);
            });
        }

        if (deckTabsWrapper) {
            deckTabsWrapper.addEventListener('click', (e) => {
                if (e.target === deckTabsWrapper) {
                    togglePanel(deckPanel);
                }
            });
        }

        // Bug 6 fix: guard against stacking multiple keydown listeners on re-init.
        if (!deckPanel._escBound) {
            function handleEscape(e) {
                if (e.key === 'Escape' && deckPanel.classList.contains('is-open')) {
                    deckPanel.classList.add('is-collapsed');
                    deckPanel.classList.remove('is-open');
                    playSound('glitch');
                }
            }
            document.addEventListener('keydown', handleEscape);
            deckPanel._escBound = true;
        }
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        initDeckPanel();
    } else {
        document.addEventListener('DOMContentLoaded', initDeckPanel);
    }

    window.twInitDeckPanel = initDeckPanel;
})();
</script>
	<?php
	return ob_get_clean();
}
