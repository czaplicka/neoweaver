<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ==========================================
// 5. GLOBAL DATA INJECTION (wp_head)
// ==========================================

/**
 * Game-page detection helper.
 *
 * Returns true only for pages that actually need window.twAdventureData:
 *   - Pages using a NeoWeaver PHP template (templates/*.php)
 *   - The hard-coded game page (ID 2857)
 *   - Any page whose slug starts with one of the game prefixes
 *
 * All other WordPress pages (blog, shop, etc.) are excluded so we never
 * fire 3 Supabase HTTP requests on irrelevant page loads.
 */
if ( ! function_exists( 'tw_is_game_page' ) ) {
	function tw_is_game_page(): bool {
		if ( ! is_singular() && ! is_page() ) {
			return false;
		}

		// Hard-coded game page ID
		if ( is_page( 2857 ) ) {
			return true;
		}

		// Any page using a NeoWeaver PHP template
		$template = get_page_template_slug( get_queried_object_id() );
		if ( $template && str_starts_with( $template, 'templates/' ) ) {
			return true;
		}

		// Slug-based guard for game section pages
		$game_slugs = [ 'game', 'play', 'legend', 'deployments', 'field-agents', 'nodes', 'inventory' ];
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		foreach ( $game_slugs as $prefix ) {
			if ( str_starts_with( $slug, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Wstrzykujemy:
 * - Supabase JS
 * - window.twAdventureData (dane gry + konfiguracja)
 *
 * BUG-FIX (original): duplicate function declaration removed.
 * BUG-FIX #4: added two-layer guard:
 *   1. tw_is_game_page() — skips all non-game pages entirely.
 *   2. 60-second per-user transient — eliminates repeated Supabase calls
 *      on the same game page across multiple requests within one minute.
 *      The transient is invalidated on session/character change by calling
 *      tw_invalidate_game_data_cache( $user_id ) from any write handler.
 */
if ( ! function_exists( 'tw_inject_global_data' ) ) {
	function tw_inject_global_data() {
		// Gate 1: only fire on game-related pages
		if ( ! tw_is_game_page() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$gm_avatar_url = get_option( 'gm_avatar_url', '' );

		// Gate 2: transient cache (60 s) — avoids 3 Supabase calls per page hit
		$cache_key = 'tw_game_data_' . $user_id;
		$game_data = get_transient( $cache_key );

		if ( false === $game_data ) {
			if ( function_exists( 'get_user_game_data_from_supabase' ) ) {
				$game_data = get_user_game_data_from_supabase( $user_id );
			} else {
				$game_data = [
					'active_session_id'   => null,
					'active_campaign_id'  => null,
					'active_character_id' => null,
					'active_scenario_id'  => null,
					'char_name'           => 'Unknown',
					'char_class'          => 'None',
					'char_tags'           => [],
					'campaign_world_type' => 1,
					'wp_user_id'          => $user_id,
				];
			}

			// Cache for 60 seconds. Short enough that game state feels live;
			// long enough to collapse burst requests (e.g. multi-tab reload).
			set_transient( $cache_key, $game_data, 60 );
		}

		$supabase_url = tw_supabase_url();
		?>
		<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
		<script id="tw-global-config">
		window.twAdventureData = {
			supabase_url: '<?php echo esc_js( $supabase_url ); ?>',
			supabase_anon_key: '<?php echo esc_js( tw_supabase_anon_key() ); ?>',
			active_session_id: <?php echo isset( $game_data['active_session_id'] ) ? (int) $game_data['active_session_id'] : 'null'; ?>,
			active_campaign_id: <?php echo isset( $game_data['active_campaign_id'] ) ? (int) $game_data['active_campaign_id'] : 'null'; ?>,
			active_character_id: <?php echo isset( $game_data['active_character_id'] ) ? (int) $game_data['active_character_id'] : 'null'; ?>,
			active_scenario_id: <?php echo isset( $game_data['active_scenario_id'] ) ? (int) $game_data['active_scenario_id'] : 'null'; ?>,
			char_name: '<?php echo isset( $game_data['char_name'] ) ? esc_js( $game_data['char_name'] ) : 'Unknown'; ?>',
			char_class: '<?php echo isset( $game_data['char_class'] ) ? esc_js( $game_data['char_class'] ) : 'None'; ?>',
			char_tags: <?php echo isset( $game_data['char_tags'] ) ? wp_json_encode( $game_data['char_tags'] ) : '[]'; ?>,
			campaign_world_type: <?php echo isset( $game_data['campaign_world_type'] ) ? (int) $game_data['campaign_world_type'] : 1; ?>,
			wp_user_id: <?php echo (int) $user_id; ?>,
			ajax_url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
			nonce: '<?php echo esc_js( wp_create_nonce( 'tw_nonce' ) ); ?>',
			gm_avatar: '<?php echo esc_url( $gm_avatar_url ); ?>'
		};
		console.log('🔗 twAdventureData injected (cached):', window.twAdventureData);
		</script>
		<?php
	}

	add_action( 'wp_head', 'tw_inject_global_data', 1 );
}

/**
 * Cache invalidation helper — call this whenever a user's session,
 * character, or campaign changes so the next page load fetches fresh data.
 *
 * Usage: tw_invalidate_game_data_cache( get_current_user_id() );
 */
if ( ! function_exists( 'tw_invalidate_game_data_cache' ) ) {
	function tw_invalidate_game_data_cache( int $user_id ): void {
		delete_transient( 'tw_game_data_' . $user_id );
	}
}

/**
 * Globalna inicjalizacja Supabase JS po załadowaniu DOM.
 */
add_action( 'wp_head', function () {
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
		(function () {
			if (window.twSupabase) return;

			if (!window.twAdventureData) {
				console.error('twAdventureData missing – cannot init Supabase');
				return;
			}

			const supabaseUrl = window.twAdventureData.supabase_url;
			const supabaseKey = window.twAdventureData.supabase_anon_key;

			if (!supabaseUrl || !supabaseKey) {
				console.error('Supabase config missing (url/key)');
				return;
			}

			if (!window.supabase) {
				console.error('Supabase JS library not loaded');
				return;
			}

			const client = window.supabase.createClient(supabaseUrl, supabaseKey);
			window.twSupabase = client;
			console.log('✅ Supabase client created globally for NeoWeaver');
			document.dispatchEvent(new Event('twSupabaseReady'));
		})();
	});
	</script>
	<?php
}, 5 );

// ==========================================
// TALE WEAVER – Quest Failure Effect
// Tylko na stronie gry (ID 2857)
// ==========================================

add_action( 'wp_head', function () {
	if ( ! is_page( 2857 ) ) {
		return;
	}
	?>
	<script>
	/**
	 * triggerQuestFailureEffect( questId )
	 *
	 * Wywołaj, gdy quest zakończy się porażką:
	 *   triggerQuestFailureEffect('q-42');
	 *
	 * Wymaga na karcie questa: id="quest-{questId}"
	 * Wymaga CSS klasy .failed-animation (zdefiniowanej w arkuszu gry).
	 */
	function triggerQuestFailureEffect(questId) {
		const card = document.getElementById('quest-' + questId);
		if (card) {
			card.classList.add('failed-animation');
		}

		const overlay = document.createElement('div');
		overlay.className = 'quest-failed-overlay';
		overlay.innerHTML = `
			<div class='failed-title'>CRITICAL FAILURE</div>
			<div class='failed-subtitle'>OBJECTIVE LOST // CONNECTION SEVERED</div>
		`;
		document.body.appendChild(overlay);

		setTimeout(() => {
			overlay.style.transition = 'opacity 1s ease';
			overlay.style.opacity = '0';
			setTimeout(() => overlay.remove(), 1000);
		}, 2500);
	}
	</script>
	<?php
}, 10 );

// ==========================================
// DECK PANEL – UI tabs (Mission / Augments / Skills)
// Tylko na stronie gry (ID 2857)
// ==========================================

add_action( 'wp_head', function () {
	if ( ! is_page( 2857 ) ) {
		return;
	}
	?>
	<script>
	/**
	 * DECK PANEL: tab switching, collapse/expand, keyboard escape.
	 *
	 * Eksposes window.twInitDeckPanel() for manual re-init (e.g. after
	 * dynamic DOM replacement). Tab "tab-skills" calls
	 * window.twLoadSkillsAndAbilities() if that function exists.
	 *
	 * Audio relies on files at:
	 *   /wp-content/uploads/sounds/ui-click.mp3
	 *   /wp-content/uploads/sounds/glitch-static.mp3
	 */
	(function () {
		const SOUND_BASE = '/wp-content/uploads/sounds/';
		const sounds = {
			tab:   new Audio(SOUND_BASE + 'ui-click.mp3'),
			glitch: new Audio(SOUND_BASE + 'glitch-static.mp3'),
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

			// Prevent clicks inside the panel from bubbling to body/overlay
			deckPanel.addEventListener('click', (e) => e.stopPropagation());

			const panelTabs      = document.querySelectorAll('.panel-tab');
			const toggleBtn      = document.getElementById('toggle-deck');
			const deckTabsWrapper = document.querySelector('.deck-tabs-wrapper');

			function switchTab(targetId) {
				const tabContents = document.querySelectorAll('.deck-tab-content');
				const tabs        = document.querySelectorAll('.panel-tab');

				playSound('tab');
				playSound('glitch');

				// Auto-expand if collapsed
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

				// Lazy-load skills tab
				if (targetId === 'tab-skills' && typeof window.twLoadSkillsAndAbilities === 'function') {
					window.twLoadSkillsAndAbilities();
				}
			}

			panelTabs.forEach((btn) => {
				btn.addEventListener('click', (e) => {
					e.stopPropagation();
					const targetId = btn.getAttribute('data-tab');
					// Exclude the toggle button itself
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
					// Only react to clicks directly on the wrapper background
					if (e.target === deckTabsWrapper) {
						const isOpen = deckPanel.classList.contains('is-open');
						deckPanel.classList.toggle('is-open',      !isOpen);
						deckPanel.classList.toggle('is-collapsed',  isOpen);
					}
				});
			}

			// Keyboard: Escape collapses the panel
			document.addEventListener('keydown', (e) => {
				if (e.key === 'Escape' && deckPanel.classList.contains('is-open')) {
					deckPanel.classList.add('is-collapsed');
					deckPanel.classList.remove('is-open');
				}
			});
		}

		// Run immediately if DOM is ready, otherwise wait
		if (document.readyState === 'complete' || document.readyState === 'interactive') {
			initDeckPanel();
		} else {
			document.addEventListener('DOMContentLoaded', initDeckPanel);
		}

		// Expose for external re-init calls
		window.twInitDeckPanel = initDeckPanel;
	})();
	</script>
	<?php
}, 15 );

// ==========================================
// TALE WEAVER FULL ENGINE v5
// Slow Motion card animation + Weaving Overlay
// + AI scenario polling
// Tylko na stronie gry (ID 2857)
// ==========================================

add_action( 'wp_head', function () {
	if ( ! is_page( 2857 ) ) {
		return;
	}

	// Seed campaign_id from server so JS never needs a hard-coded fallback.
	$user_id     = get_current_user_id();
	$cache_key   = 'tw_game_data_' . $user_id;
	$game_data   = get_transient( $cache_key );
	$campaign_id = ( $game_data && ! empty( $game_data['active_campaign_id'] ) )
		? (int) $game_data['active_campaign_id']
		: 0;
	$nonce       = wp_create_nonce( 'tw_nonce' );
	?>
	<script>
	/**
	 * TALE WEAVER: FULL ENGINE v5 (Slow Motion + UI Fixes)
	 *
	 * Reads campaign_id from window.twAdventureData (injected by tw_inject_global_data
	 * at priority 1) so there is no hard-coded fallback value in the JS.
	 * PHP also seeds window.twGameConfig directly below as a belt-and-suspenders guard.
	 */
	window.twGameConfig = window.twGameConfig || {};
	window.twGameConfig.campaign_id = <?php echo $campaign_id ?: 'window.twAdventureData && window.twAdventureData.active_campaign_id || 0'; ?>;
	window.twGameConfig.nonce       = '<?php echo esc_js( $nonce ); ?>';

	(function () {
		console.log('🎮 TALE WEAVER v5 SLOW MOTION ENGINE START');

		let loreTips = [], tipInterval, matrixInterval;

		// -------------------------------------------------------
		// 1. POLL + BIND — wait for scenario cards to appear in DOM
		// -------------------------------------------------------
		const init = setInterval(() => {
			const cards = document.querySelectorAll('.deck-card.scenario-card');
			if (cards.length > 0 && !window.scenarioListenerBound) {
				console.log('🎯 FULL BINDING ACTIVE!');

				document.body.addEventListener('click', (e) => {
					const card = e.target.closest('.deck-card.scenario-card');
					if (!card || !card.dataset.scenarioId) return;

					console.log('✅ SCENARIO CLICKED:', card.dataset.scenarioId);
					e.preventDefault();
					e.stopPropagation();

					const rect  = card.getBoundingClientRect();
					const clone = card.cloneNode(true);
					clone.className = '';
					clone.classList.add('tw-card-clone');
					clone.style.cssText  = card.style.cssText;
					clone.style.position = 'fixed';
					clone.style.top      = rect.top  + 'px';
					clone.style.left     = rect.left + 'px';
					clone.style.width    = rect.width  + 'px';
					clone.style.height   = rect.height + 'px';
					clone.style.zIndex   = '100000';
					clone.style.margin   = '0';
					document.body.appendChild(clone);

					card.style.opacity       = '0';
					card.style.pointerEvents = 'none';

					const deckPanelEl = document.getElementById('deck-panel');
					if (deckPanelEl) deckPanelEl.classList.add('fade-out-scenery');

					const missionTab = document.querySelector('button.panel-tab[data-tab="tab-scenarios"]');
					if (missionTab) {
						missionTab.classList.remove('is-active');
						missionTab.classList.add('ui-element-hidden');
					}

					void clone.offsetWidth;

					requestAnimationFrame(() => {
						clone.classList.add('centering');

						setTimeout(() => {
							clone.classList.add('burning');

							setTimeout(() => {
								clone.remove();
								showWeavingOverlay(card.dataset.scenarioId);
								if (deckPanelEl) deckPanelEl.style.display = 'none';
							}, 2400);

						}, 1500);
					});

				}, true);

				window.scenarioListenerBound = true;
				clearInterval(init);
			}
		}, 1000);

		async function loadLoreTips() {
			try {
				const response = await fetch(
					(window.twAdventureData && window.twAdventureData.ajax_url) ||
					'/wp-admin/admin-ajax.php',
					{ method: 'POST', body: new URLSearchParams({ action: 'tw_get_lore_tips' }) }
				);
				const result = await response.json();
				loreTips = result.success ? result.data : ['Compiling...', 'Linking...'];
			} catch (e) {
				loreTips = ['System loading...'];
			}
		}

		async function showWeavingOverlay(scenarioId) {
			if (!loreTips.length) await loadLoreTips();
			triggerScenarioGeneration(scenarioId);

			let overlay = document.getElementById('tw-weaving-overlay');
			if (!overlay) {
				overlay = createWeavingOverlay();
				document.body.appendChild(overlay);
			}
			overlay.classList.add('active');
			createMatrixEffect(overlay);
			rotateTips(overlay);

			let attempts = 0;
			const ajaxUrl = (window.twAdventureData && window.twAdventureData.ajax_url)
				|| '/wp-admin/admin-ajax.php';
			const campaignId = window.twGameConfig.campaign_id
				|| (window.twAdventureData && window.twAdventureData.active_campaign_id)
				|| 0;

			const checkMessage = async () => {
				attempts++;
				console.log(`Waiting for AI #${attempts} (Camp: ${campaignId})`);

				try {
					const res    = await fetch(`${ajaxUrl}?action=tw_get_ai_message&campaign_id=${campaignId}`);
					const result = await res.json();

					if (result.success && result.data.message) {
						console.log('✅ AI Message READY!');
						hideWeavingOverlay();

						const chatWindow = document.querySelector('#player-chat, .chat-window.is-active');
						if (chatWindow) {
							const msgDiv = document.createElement('div');
							msgDiv.innerHTML = `
								<div class="tw-ai-narrative">
									<div class="tw-ai-header">
										<span class="tw-gm-label">GM</span>
										<span class="tw-timestamp">${new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
									</div>
									<div class="tw-ai-text">${result.data.message.replace(/\n/g, '<br>')}</div>
								</div>
							`;
							chatWindow.appendChild(msgDiv);
							chatWindow.scrollTop = chatWindow.scrollHeight;
						}
						return;
					}
				} catch (e) {
					console.warn('AI poll error:', e);
				}

				if (attempts < 40) setTimeout(checkMessage, 2000);
				else hideWeavingOverlay();
			};
			checkMessage();
		}

		function createWeavingOverlay() {
			const overlay = document.createElement('div');
			overlay.id        = 'tw-weaving-overlay';
			overlay.innerHTML = `
				<div class="weaving-matrix"></div>
				<div class="weaving-title">WEAVING SCENARIO</div>
				<div class="weaving-tip" id="weaving-tip">Loading...</div>
			`;
			return overlay;
		}

		function createMatrixEffect(overlay) {
			const matrix = overlay.querySelector('.weaving-matrix');
			matrixInterval = setInterval(() => {
				const char = document.createElement('div');
				char.className   = 'matrix-char';
				char.textContent = String.fromCharCode(0x30A0 + Math.random() * 96);
				char.style.left              = Math.random() * 100 + '%';
				char.style.animationDuration = (Math.random() * 3 + 4) + 's';
				char.style.opacity           = Math.random() * 0.5 + 0.2;
				matrix.appendChild(char);
				setTimeout(() => char.remove(), 8000);
			}, 300);
		}

		function rotateTips(overlay) {
			const tipEl    = overlay.querySelector('#weaving-tip');
			let   tipIndex = 0;
			tipInterval = setInterval(() => {
				tipEl.textContent     = loreTips[tipIndex % loreTips.length];
				tipEl.style.animation = 'none';
				tipEl.offsetHeight;
				tipEl.style.animation = 'tip-fade 0.8s ease-out forwards';
				tipIndex++;
			}, 4500);
		}

		async function triggerScenarioGeneration(scenarioId) {
			const ajaxUrl    = (window.twAdventureData && window.twAdventureData.ajax_url)
				|| '/wp-admin/admin-ajax.php';
			const campaignId = window.twGameConfig.campaign_id
				|| (window.twAdventureData && window.twAdventureData.active_campaign_id)
				|| 0;
			const nonce      = window.twGameConfig.nonce
				|| (window.twAdventureData && window.twAdventureData.nonce)
				|| '';

			const formData = new URLSearchParams({
				action:      'tw_start_scenario_generation',
				scenario_id: scenarioId,
				campaign_id: campaignId,
				nonce:       nonce,
			});
			fetch(ajaxUrl, { method: 'POST', body: formData }).catch(e => console.error(e));
		}

		function hideWeavingOverlay() {
			const overlay = document.getElementById('tw-weaving-overlay');
			if (overlay) {
				overlay.classList.remove('active');
				clearInterval(matrixInterval);
				clearInterval(tipInterval);
				setTimeout(() => overlay.remove(), 800);
			}

			const missionTab = document.querySelector('button.panel-tab[data-tab="tab-scenarios"]');
			if (missionTab) missionTab.classList.remove('ui-element-hidden');
		}

		window.addEventListener('beforeunload', hideWeavingOverlay);
		loadLoreTips();
	})();
	</script>
	<?php
}, 20 );
