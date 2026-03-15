<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER - SCENARIOS LOADER
 * Wstrzykuje JS loadera scenariuszy tylko na stronie gry (templates/adventure.php).
 * Hook: wp_footer, priorytet 30 (po char-panel.php który ma 25).
 */
add_action( 'wp_footer', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
		return;
	}
	?>
	<script>
	(function () {
	    async function loadScenarios() {
	        const shell = document.getElementById('adventure-shell');
	        if (!shell) return;

	        const list = document.getElementById('scenarios-list');
	        if (!list) {
	            console.warn('⚠️ scenarios-list not found in DOM');
	            return;
	        }

	        list.innerHTML = '<p class="empty-msg">Scanning network for missions...</p>';

	        try {
	            const campaignId = window.twGameState?.currentCampaignId || window.twAdventureData?.active_campaign_id;
	            console.log('🔍 Scenarios: Resolved campaignId:', campaignId);

	            if (!campaignId) {
	                list.innerHTML = '<p class="empty-msg">No active campaign detected.</p>';
	                return;
	            }

	            const formData = new URLSearchParams({
	                action: 'tw_get_scenarios_ajax',
	                campaign_id: campaignId
	            });

	            const ajaxUrl = window.twAdventureData?.ajax_url || '/wp-admin/admin-ajax.php';
	            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });

	            if (!response.ok) throw new Error('AJAX HTTP error ' + response.status);

	            const json = await response.json();

	            if (!json.success || !Array.isArray(json.data)) {
	                list.innerHTML = '<p class="empty-msg">No missions available for this campaign yet.</p>';
	                return;
	            }

	            const scenarios = json.data.slice(0, 3);

	            if (!scenarios.length) {
	                list.innerHTML = '<p class="empty-msg">No missions available. Ask your GM to sync the campaign.</p>';
	                return;
	            }

	            list.innerHTML = '';

	            scenarios.forEach((s) => {
	                const tags = (s.tags || '').split(',').map((t) => t.trim()).filter(Boolean);

	                const card = document.createElement('article');
	                card.className = 'deck-card scenario-card';
	                card.dataset.scenarioId = s.id;

	                card.innerHTML = `
	                    <div class="deck-card-inner">
	                        ${s.img_url ? `<div class="scenario-image-wrap"><img src="${s.img_url}" alt="${s.name || ''}" class="scenario-image"></div>` : ''}
	                        <header class="scenario-header">
	                            <span class="scenario-difficulty">${s.difficulty || ''}</span>
	                            <h4 class="scenario-title">${s.name || 'Untitled mission'}</h4>
	                        </header>
	                        <div class="scenario-body">
	                            <p class="scenario-goal">${s.goal || ''}</p>
	                            <p class="scenario-tags">
	                                ${tags.map((t) => `<span class="scenario-tag">#${t}</span>`).join('')}
	                                ${s.is_boss ? '<span class="scenario-tag">#boss</span>' : ''}
	                                ${s.is_key_arc ? '<span class="scenario-tag">#key_arc</span>' : ''}
	                            </p>
	                        </div>
	                        <footer class="scenario-footer">
	                            <span class="scenario-type">${s.type || ''}</span>
	                            <span class="scenario-category">${s.category || ''}</span>
	                        </footer>
	                    </div>
	                `;

	                list.appendChild(card);
	            });

	            console.log('✅ Loaded', scenarios.length, 'scenario cards');

	        } catch (error) {
	            console.error('❌ Error loading scenarios:', error);
	            list.innerHTML = '<p class="empty-msg">Mission panel offline. Please refresh the terminal.</p>';
	        }
	    }

	    window.twLoadScenarios = loadScenarios;

	    if (window.twGameReady) {
	        loadScenarios();
	    } else {
	        document.addEventListener('twGameStateHydrated', loadScenarios);
	    }

	    console.log('🎮 Tale Weaver Scenarios Loader - Ready & Waiting');
	})();
	</script>
	<?php
}, 30 );
