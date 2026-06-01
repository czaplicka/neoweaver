<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Upewniamy się, że tw_prepare_character_data() i tw_prepare_tactical_data() są dostępne.
if ( ! function_exists( 'tw_prepare_character_data' ) ) {
	require_once NEOWEAVER_PLUGIN_DIR . 'includes/adventure-data.php';
}

if ( ! class_exists( 'TW_Adventure_Terminal_Shortcode' ) ) {

	class TW_Adventure_Terminal_Shortcode {

		const SHORTCODE = 'tw_adventure_terminal';

		/**
		 * BUG 11 FIX — init() było wywoływane wprost z poziomu pliku (file scope),
		 * co oznaczało, że add_shortcode() odpalało się przy każdym require_once,
		 * bez względu na to czy strona shortcode'a jest wyświetlana.
		 *
		 * Teraz rejestrujemy się na hooku 'init' (priorytet 10), który:
		 * - jest standardowym miejscem dla add_shortcode() w WordPressie
		 * - uruchamia się po wczytaniu pluginu, ale nadal przed renderą strony
		 * - izoluje ewentualne przyszłe efekty uboczne w init() przed globalnym scope’em
		 */
		public static function init(): void {
			add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
		}

		public static function render_shortcode( $atts = [] ): string {
			if ( ! is_user_logged_in() ) {
				return '<p class="tw-error">Please log in to access the Terminal.</p>';
			}

			/**
			 * BUG 9 FIX — shortcode zawiera dane sesji zalogowanego użytkownika
			 * (active_character_id, active_session_id, active_campaign_id).
			 * Jeśli WP Super Cache / W3TC zcachuje tę stronę po renderze dla
			 * zalogowanego gracza, kolejny (niezalogowany) visitor dostanie cudze UUID.
			 *
			 * Rozwiązanie: ustawiamy nagłówki HTTP zabraniające cachowania
			 * ORAZ domyślną stałą DONOTCACHEPAGE, którą obsługują WP Super Cache,
			 * W3TC, LiteSpeed Cache i inne popularne pluginy.
			 *
			 * UWAGA: anon key sam w sobie jest publiczny (przeznaczony do klienta),
			 * ale reszta obiektu twAdventureData jest per-user i nie może wyciec.
			 */
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'Pragma: no-cache' );
			}

			$user_id = get_current_user_id();

			$game_data = function_exists( 'get_user_game_data_from_supabase' )
				? get_user_game_data_from_supabase( $user_id )
				: ( function_exists( 'tw_game_data_defaults' ) ? tw_game_data_defaults() : [] );

			// active_location_id to UUID — nigdy nie castuj na int
			$adventure_payload = [
				'active_session_id'   => (string) ( $game_data['active_session_id'] ?? '' ),
				'active_campaign_id'  => (string) ( $game_data['active_campaign_id'] ?? '' ),
				'active_character_id' => (string) ( $game_data['active_character_id'] ?? '' ),
				'active_world_id'     => (string) ( $game_data['active_world_id'] ?? '' ),
				'active_location_id'  => (string) ( $game_data['active_location_id'] ?? '' ),
				'char_name'           => $game_data['char_name'] ?? 'Unknown',
				'char_tags'           => $game_data['char_tags'] ?? [],
				'nonce'               => wp_create_nonce( 'tw_adventure_nonce' ),
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'supabase_url'        => function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '',
				/**
				 * supabase_anon_key: celowo do JS — wymagane do Supabase Realtime
				 * (subskrypcje kanałów chatów po stronie klienta).
				 * To NIE jest service key. Service key — NIGDY do JS.
				 * Patrz: cyber-hud.php poświęcony temu samemu wzorcowi.
				 */
				'supabase_anon_key'   => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
				'content_url'         => content_url(),
			];

			$tactical_data = function_exists( 'tw_prepare_tactical_data' )
				? tw_prepare_tactical_data( $game_data, $user_id )
				: [];

			$char = function_exists( 'tw_prepare_character_data' )
				? tw_prepare_character_data( $game_data )
				: [];

			$char_data = $char['char_data'] ?? [];
			$c_hp      = $char['c_hp'] ?? 0;
			$hp_class  = $char['hp_class'] ?? '';
			$c_mp      = $char['c_mp'] ?? 0;
			$mp_class  = $char['mp_class'] ?? '';
			$c_gold    = $char['c_gold'] ?? 0;
			$c_xp      = $char['c_xp'] ?? 0;
			$c_status  = $char['c_status'] ?? '';

			/**
			 * BUG 10 FIX — JSON_HEX_TAG zamienia < i > na sekwencje Unicode
			 * (\u003C, \u003E), co uniemożliwia wyjście z bloku <script> przez
			 * wartość zawierającą </script> (np. złośliwa nazwa postaci).
			 *
			 * JSON_HEX_AMP i JSON_HEX_APOS dla kompletności (ochrona przed
			 * amp-encoding i apostrofami w kontekstach HTML atrybutu).
			 *
			 * JSON_UNESCAPED_UNICODE zostawiamy OFF (domyślne) — bezpieczniejsze
			 * w kontekstach gdzie charset strony może być nieokreślony.
			 */
			$json_flags    = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;
			$inline_js     = '<script id="tw-adventure-data-js">';
			$inline_js    .= 'window.twAdventureData = ' . wp_json_encode( $adventure_payload, $json_flags ) . ';';
			$inline_js    .= 'window.twTacticalData = ' . wp_json_encode( $tactical_data, $json_flags ) . ';';
			$inline_js    .= '</script>';

			ob_start();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS celowo nie jest escapowany przez esc_html;
			// wszystkie dane użytkownika przeszły przez wp_json_encode z JSON_HEX_TAG
			// (konwertuje </script> na \u003C/script\u003E), więc XSS jest niemożliwy.
			echo $inline_js;
			?>
			<div class="adventure-shell chat-only" id="adventure-shell">
				<section class="chat-panel">
					<header class="chat-header">
						<h1 class="chat-title">TERMINAL <span>CONNECTED</span></h1>
						<p class="chat-subtitle">Instruction: write, play cards and have fun</p>
					</header>

					<?php echo do_shortcode( '[cyber_hud]' ); ?>
					<?php echo do_shortcode( '[tw_time_wheel]' ); ?>
					<?php echo do_shortcode( '[tw_compass]' ); ?>

					<section id="adventure-chat">
						<div class="chat-tabs">
							<button class="chat-tab is-active" data-chat-target="player-chat">Mission Chat</button>
							<button class="chat-tab" data-chat-target="gm-chat">GM Channel</button>
						</div>

						<div id="player-chat" class="chat-window is-active"></div>
						<div id="gm-chat" class="chat-window" style="display:none;"></div>

						<div id="quick-actions-container" style="display:flex;margin:15px 0;font-family:'Chakra Petch',sans-serif;">
							<div id="qa-static-zone" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;">
								<div id="quick-actions-bar" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
								<button
									onclick="window.toggleQAManager()"
									id="qa-manager-toggle"
									style="background:#000;border:1px dashed #adff00;color:#adff00;padding:10px 15px;border-radius:8px;cursor:pointer;font-weight:bold;font-family:'Chakra Petch',sans-serif;"
								>
									[+] CMD_CENTER
								</button>
							</div>

							<div id="qa-manager-panel" style="display:none;background:rgba(0,20,0,0.95);border:1px solid #adff00;padding:15px;border-radius:10px;box-shadow:0 0 20px rgba(0,255,0,0.3);margin-top:10px;">
								<div style="display:flex;gap:10px;margin-bottom:15px;align-items:center;flex-wrap:wrap;">
									<div style="position:relative;flex-grow:1;">
										<input
											type="text"
											id="qa-search-input"
											oninput="window.twLoadQuickActions()"
											placeholder="SEARCH_DATABASE..."
											style="width:100%;background:#000;color:#adff00;border:1px solid #adff00;padding:8px 8px 8px 30px;font-family:monospace;"
										>
										<span style="position:absolute;left:10px;top:8px;">🔍</span>
									</div>

									<button
										onclick="window.toggleDeleteMode()"
										id="toggle-delete-mode-btn"
										style="background:none;border:1px solid #666;color:#666;padding:8px 15px;cursor:pointer;font-size:0.8em;"
									>
										[x] DEL_MODE
									</button>
								</div>

								<div id="qa-category-filters" style="display:flex;gap:8px;margin-bottom:15px;">
									<button onclick="window.setQAFilter('ALL')" class="filter-btn active" style="background:#adff00;color:#000;border:none;padding:5px 15px;cursor:pointer;font-size:0.75em;font-weight:bold;border-radius:3px;">ALL</button>
									<button onclick="window.setQAFilter('Red')" class="filter-btn" style="background:none;color:#ff4444;border:1px solid #ff4444;padding:5px 15px;cursor:pointer;font-size:0.75em;">COMBAT</button>
									<button onclick="window.setQAFilter('Blue')" class="filter-btn" style="background:none;color:#00ccff;border:1px solid #00ccff;padding:5px 15px;cursor:pointer;font-size:0.75em;">TECH</button>
								</div>

								<div id="user-actions-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;border-top:1px solid rgba(173,255,0,0.2);padding-top:15px;"></div>

								<div id="custom-action-form" style="border-top:1px solid rgba(173,255,0,0.2);padding-top:15px;">
									<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px;">
										<input type="text" id="custom-label" placeholder="LABEL" style="background:#000;color:#adff00;border:1px solid #333;padding:8px;">
										<select id="custom-category" style="background:#000;color:#adff00;border:1px solid #333;padding:8px;">
											<option value="Custom">USER</option>
											<option value="Red">COMBAT</option>
											<option value="Blue">TECH</option>
										</select>
									</div>

									<textarea id="custom-template" placeholder="PROMPT" rows="2" style="width:100%;background:#000;color:#adff00;border:1px solid #333;padding:8px;margin-bottom:8px;font-family:monospace;"></textarea>

									<button onclick="window.saveCustomAction()" style="width:100%;background:#adff00;color:#000;font-weight:bold;padding:10px;border:none;cursor:pointer;">
										[EXECUTE_SAVE]
									</button>
								</div>
							</div>
						</div>

						<div class="chat-input-wrapper">
							<div class="chat-input-inner">
								<textarea id="chat-input" class="chat-input" placeholder="What will you do?"></textarea>
							</div>
							<div class="chat-action-row">
							<button id="send-btn" class="btn-send">TRANSMIT</button>
							</div>
						</div>
					</section>
				</section>
			</div>

			<aside id="scenario-panel" class="scenario-panel">
				<div class="scenario-panel-body">
					<!-- ID zmienione z deck-panel na deck-panel-terminal, żeby uniknąć konfliktu z [tw_deck_panel] -->
					<div id="deck-panel-terminal" class="deck-panel is-open">
						<div class="deck-tabs-wrapper">
							<button class="panel-tab is-active" data-tab="tab-scenarios">Mission</button>
							<button class="panel-tab" data-tab="tab-hand">Augments</button>
							<button class="panel-tab" data-tab="tab-skills">Skills</button>
							<button id="toggle-deck" class="panel-tab">✕</button>
						</div>

						<div id="deck-container">
							<div id="tab-scenarios" class="deck-tab-content is-active">
								<div class="deck-cards" id="scenarios-list">
									<p style="text-align:center;padding:20px;">Loading missions...</p>
								</div>
							</div>

							<div id="tab-hand" class="deck-tab-content">
								<div id="hand-frame">
									<div class="hand-type-tabs">
										<button class="hand-type-tab is-active" data-type-tab="all">All</button>
										<button class="hand-type-tab" data-type-tab="attack">Attack</button>
										<button class="hand-type-tab" data-type-tab="social">Social</button>
										<button class="hand-type-tab" data-type-tab="control">Control</button>
										<button class="hand-type-tab" data-type-tab="passive">Passive</button>
										<button class="hand-type-tab" data-type-tab="special">Special</button>
										<button class="hand-type-tab" data-type-tab="tech">Tech</button>
									</div>

									<div class="hand-type-views">
										<div class="deck-cards hand-cards is-active" id="hand-cards-all"></div>
										<div class="deck-cards hand-cards" id="hand-cards-attack"></div>
										<div class="deck-cards hand-cards" id="hand-cards-social"></div>
										<div class="deck-cards hand-cards" id="hand-cards-control"></div>
										<div class="deck-cards hand-cards" id="hand-cards-passive"></div>
										<div class="deck-cards hand-cards" id="hand-cards-special"></div>
										<div class="deck-cards hand-cards" id="hand-cards-tech"></div>
									</div>
								</div>
							</div>

							<div id="tab-skills" class="deck-tab-content">
								<div class="deck-cards deck-cards-skills"></div>
								<div class="deck-cards deck-cards-abilities"></div>
							</div>
						</div>
					</div>
				</div>
			</aside>

			<?php
			$character_card_path   = NEOWEAVER_PLUGIN_DIR . 'templates/parts/character-card.php';
			$tactical_overlay_path = NEOWEAVER_PLUGIN_DIR . 'templates/parts/tactical-overlay.php';

			if ( file_exists( $character_card_path ) ) {
				$args = [
					'char_id'              => (string) ( $game_data['active_character_id'] ?? '' ),
					'char_data'            => $char['char_data'] ?? [],
					'c_hp'                 => (int) ( $char['c_hp'] ?? 0 ),
					'm_hp'                 => (int) ( $char['m_hp'] ?? 0 ),
					'hp_class'             => (string) ( $char['hp_class'] ?? '' ),
					'c_mp'                 => (int) ( $char['c_mp'] ?? 0 ),
					'm_mp'                 => (int) ( $char['m_mp'] ?? 0 ),
					'mp_p'                 => (int) ( $char['mp_p'] ?? 0 ),
					'sync_p'               => (int) ( $char['sync_p'] ?? 0 ),
					'sync_class'           => (string) ( $char['sync_class'] ?? '' ),
					'c_satiety'            => (int) ( $char['c_satiety'] ?? 0 ),
					'c_hydration'          => (int) ( $char['c_hydration'] ?? 0 ),
					'c_rest'               => (int) ( $char['c_rest'] ?? 0 ),
					'skills_and_abilities' => $char['skills_and_abilities'] ?? [],
					'inventory'            => $char['inventory'] ?? [],
					'logs_data'            => $char['logs_data'] ?? [],
					'total_mass'           => $char['total_mass'] ?? 0,
					'mass_limit'           => $char['mass_limit'] ?? 0,
					'total_power'          => $char['total_power'] ?? 0,
				];

				include $character_card_path;
			}

			if ( file_exists( $tactical_overlay_path ) ) {
				include $tactical_overlay_path;
			}

			return (string) ob_get_clean();
		}
	}

	/**
	 * BUG 11 FIX — rejestracja na hooku 'init' zamiast file scope.
	 * add_shortcode() wywołane na 'init' jest idiomatycznym wzorcem WP;
	 * chroni przed niezamierzonymi efektami ubocznymi przy przyszłych
	 * zmianach w metodzie init().
	 */
	add_action( 'init', [ 'TW_Adventure_Terminal_Shortcode', 'init' ] );
}
