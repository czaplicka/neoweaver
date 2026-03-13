<?php
/**
 * [TW] Deck Panel – UI tabs (Mission / Augments / Skills)
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
		<button id="toggle-deck" class="panel-tab" aria-label="Toggle deck panel">☰</button>

		<!-- Tab buttons -->
		<button class="panel-tab" data-tab="tab-mission">MISSION</button>
		<button class="panel-tab" data-tab="tab-augments">AUGMENTS</button>
		<button class="panel-tab" data-tab="tab-skills">SKILLS</button>

	</div>

	<!-- Tab content areas -->
	<div id="tab-mission"  class="deck-tab-content is-active">
		<h3 class="deck-tab-title">// MISSION_DATA</h3>
		<div id="tw-mission-content">Loading mission data…</div>
	</div>

	<div id="tab-augments" class="deck-tab-content">
		<h3 class="deck-tab-title">// AUGMENTS</h3>
		<div id="tw-augments-content">Loading augments…</div>
	</div>

	<div id="tab-skills" class="deck-tab-content">
		<h3 class="deck-tab-title">// SKILLS &amp; ABILITIES</h3>
		<div id="tw-skills-content">Loading skills…</div>
	</div>
</div>

<script>
(function () {
    const sounds = {
        tab:    new Audio('https://cyber.nieodparady.pl/wp-content/uploads/sounds/ui-click.mp3'),
        glitch: new Audio('https://cyber.nieodparady.pl/wp-content/uploads/sounds/glitch-static.mp3'),
    };

    function playSound(name) {
        const sound = sounds[name];
        if (!sound) return;
        sound.currentTime = 0;
        sound.volume = 0.2;
        sound.play().catch(() => {});
    }

    function initDeckPanel() {
        console.log('initDeckPanel CALLED');
        const deckPanel = document.getElementById('deck-panel');
        if (!deckPanel) return;

        deckPanel.addEventListener('click', (e) => e.stopPropagation());

        const panelTabs       = document.querySelectorAll('.panel-tab');
        const toggleBtn       = document.getElementById('toggle-deck');
        const deckTabsWrapper = document.querySelector('.deck-tabs-wrapper');

        function switchTab(targetId) {
            const tabContents = document.querySelectorAll('.deck-tab-content');
            const tabs        = document.querySelectorAll('.panel-tab');

            playSound('tab');
            playSound('glitch');

            if (deckPanel.classList.contains('is-collapsed')) {
                deckPanel.classList.remove('is-collapsed');
                deckPanel.classList.add('is-open');
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
                const isOpen = deckPanel.classList.contains('is-open');
                deckPanel.classList.toggle('is-open',      !isOpen);
                deckPanel.classList.toggle('is-collapsed',  isOpen);
            });
        }

        if (deckTabsWrapper) {
            deckTabsWrapper.addEventListener('click', (e) => {
                if (e.target === deckTabsWrapper) {
                    const isOpen = deckPanel.classList.contains('is-open');
                    deckPanel.classList.toggle('is-open',      !isOpen);
                    deckPanel.classList.toggle('is-collapsed',  isOpen);
                }
            });
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && deckPanel.classList.contains('is-open')) {
                deckPanel.classList.add('is-collapsed');
                deckPanel.classList.remove('is-open');
            }
        });
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
