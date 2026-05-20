<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TW_Adventure_Terminal_Shortcode' ) ) {

	class TW_Adventure_Terminal_Shortcode {

		const SHORTCODE = 'tw_adventure_terminal';

		public static function init(): void {
			add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
		}

		public static function render_shortcode( $atts = [] ): string {
			if ( ! is_user_logged_in() ) {
				return '<p class="tw-error">Please log in to access the Terminal.</p>';
			}

			$user_id = get_current_user_id();

			$game_data = function_exists( 'get_user_game_data_from_supabase' )
				? get_user_game_data_from_supabase( $user_id )
				: ( function_exists( 'tw_game_data_defaults' ) ? tw_game_data_defaults() : [] );

			$adventure_payload = [
				'active_session_id'   => (string) ( $game_data['active_session_id'] ?? '' ),
				'active_campaign_id'  => (string) ( $game_data['active_campaign_id'] ?? '' ),
				'active_character_id' => (string) ( $game_data['active_character_id'] ?? '' ),
				'active_world_id'     => (string) ( $game_data['active_world_id'] ?? '' ),
				'active_location_id'  => (int) ( $game_data['active_location_id'] ?? 0 ),
				'char_name'           => $game_data['char_name'] ?? 'Unknown',
				'char_tags'           => $game_data['char_tags'] ?? [],
				'nonce'               => wp_create_nonce( 'tw_adventure_nonce' ),
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'supabase_url'        => function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '',
				'supabase_anon_key'   => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
				'content_url'         => content_url(),
			];

			$tactical_data = function_exists( 'tw_prepare_tactical_data' )
				? tw_prepare_tactical_data( $game_data, $user_id )
				: [];

if ( wp_script_is( 'tw-adventure', 'enqueued' ) ) {
				wp_add_inline_script(
					'tw-adventure',
					'window.twAdventureData = ' . wp_json_encode( $adventure_payload ) . ';',
					'before'
				);

				wp_add_inline_script(
					'tw-adventure',
					'window.twTacticalData = ' . wp_json_encode( $tactical_data ) . ';',
					'before'
				);
			}

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

			ob_start();
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
					<div id="deck-panel" class="is-open">
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
			$character_card_path = NEOWEAVER_PLUGIN_DIR . 'templates/parts/character-card.php';
			$tactical_overlay_path = NEOWEAVER_PLUGIN_DIR . 'templates/parts/tactical-overlay.php';

			if ( file_exists( $character_card_path ) ) {
				include $character_card_path;
			}

			if ( file_exists( $tactical_overlay_path ) ) {
				include $tactical_overlay_path;
			}

			return (string) ob_get_clean();
		}
	}

	TW_Adventure_Terminal_Shortcode::init();
}
