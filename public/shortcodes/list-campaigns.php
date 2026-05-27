<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * NEOWEAVER - DEPLOYMENTS LIST
 * Shortcode: [tw_list_campaigns]
 */

if ( ! function_exists( 'tw_list_campaigns_final_v8_modes' ) ) {
	function tw_list_campaigns_final_v8_modes(): string {
		if ( is_admin() ) {
			return '';
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '<p class="tw-error">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'nw_supabase_service_headers' ) ) {
			return '<p class="tw-error">API Config missing.</p>';
		}

		if ( function_exists( 'tw_enqueue_list_campaigns_assets' ) ) {
			tw_enqueue_list_campaigns_assets(
				array(
					'nonce'       => wp_create_nonce( 'tw_game_nonce' ),
					'restNonce'   => wp_create_nonce( 'wp_rest' ),
					'sessionUrl'  => get_rest_url( null, 'neoweaver/v1/session/start' ),
					'terminalUrl' => home_url( '/game/' ),
					'agentsUrl'   => home_url( '/agents/?campaign_id=' ),
					'lobbyUrl'    => home_url( '/lobby/?campaign_id=' ),
				)
			);
		}

		$world_type_map = array(
			1 => array( 'label' => 'Easy',      'icon' => 'leaf',      'color' => '#4ade80' ),
			2 => array( 'label' => 'Casual',    'icon' => 'coffee',    'color' => '#86efac' ),
			3 => array( 'label' => 'Standard',  'icon' => 'crosshair', 'color' => '#adff00' ),
			4 => array( 'label' => 'Hardcore',  'icon' => 'skull',     'color' => '#f97316' ),
			5 => array( 'label' => 'Nightmare', 'icon' => 'skull',     'color' => '#ef4444' ),
		);

		// length → liczba segmentów: Short=3, Medium=5, Long=8, Epic=13, Endless=0 (ciągły)
		$game_length_map = array(
			1 => array( 'label' => 'Short',    'icon' => 'zap',         'segs' => 3  ),
			2 => array( 'label' => 'Medium',   'icon' => 'timer',        'segs' => 5  ),
			3 => array( 'label' => 'Standard', 'icon' => 'radio-tower',  'segs' => 8  ),
			4 => array( 'label' => 'Epic',     'icon' => 'globe',        'segs' => 13 ),
			5 => array( 'label' => 'Endless',  'icon' => 'infinity',     'segs' => 0  ),
		);

		$gm_style_map = array(
			'cinematic_heroic' => array(
				'label' => 'Cinematic',
				'icon'  => 'clapperboard',
				'bg'    => 'https://neoweaver.nieodparady.pl/wp-content/uploads/epic.svg',
			),
			'harsh_grounded'   => array(
				'label' => 'Harsh',
				'icon'  => 'droplets',
				'bg'    => 'https://neoweaver.nieodparady.pl/wp-content/uploads/gore.svg',
			),
			'fast_tactical'    => array(
				'label' => 'Tactical',
				'icon'  => 'zap',
				'bg'    => 'https://neoweaver.nieodparady.pl/wp-content/uploads/tactical.svg',
			),
		);

		$priority_map = array(
			1 => array( 'label' => 'Combat',    'icon' => 'swords' ),
			2 => array( 'label' => 'Wealth',    'icon' => 'coins' ),
			3 => array( 'label' => 'Discovery', 'icon' => 'search' ),
			4 => array( 'label' => 'Relations', 'icon' => 'handshake' ),
			5 => array( 'label' => 'Mix',       'icon' => 'shuffle' ),
		);

		$url_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$select   = '*,cyber_campaign_worlds(world_id,cyber_worlds(name,difficulty)),cyber_campaign_characters(character_id,cyber_characters(name,cyber_races(name),cyber_classes(name)))';

		$url = add_query_arg(
			array(
				'wp_user_id' => 'eq.' . (int) $user_id,
				'select'     => $select,
				'order'      => 'created_at.desc',
			),
			$url_base . 'cyber_campaign'
		);

		$response = wp_remote_get( $url, array( 'headers' => nw_supabase_service_headers(), 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return '<p class="tw-error">CRITICAL ERROR: ' . esc_html( $response->get_error_message() ) . '</p>';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			error_log( '[NeoWeaver] Supabase HTTP ' . $code . ' URL: ' . $url );
			return '<p class="tw-error">CRITICAL ERROR: Matrix Sync Failed [HTTP ' . esc_html( (string) $code ) . '].</p>';
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return '<p class="tw-error">CRITICAL ERROR: Invalid payload from Matrix.</p>';
		}

		if ( empty( $decoded ) ) {
			return '
			<div class="tw-campaigns-empty">
				<div class="tw-campaigns-empty-icon">⚠️</div>
				<p class="tw-campaigns-empty-main">NO DEPLOYMENTS DETECTED.</p>
				<small class="tw-campaigns-empty-sub">Create a new Deployment to begin the weaving process.</small>
				<div class="tw-campaigns-empty-actions">
					<a href="/new-deployment/" class="tw-btn-sync">NEW DEPLOYMENT</a>
					<a href="/new-node/" class="tw-btn-outline">NEW NODE</a>
				</div>
			</div>';
		}

		// ── SVG ikonki Lucide (inline, bez zależności od JS) ──────────────────
		$lucide_svg = array(
			'user'         => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
			'users'        => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
			'clapperboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.2 6 3 11l-.9-2.4c-.3-1.1.3-2.2 1.3-2.5l13.5-4c1.1-.3 2.2.3 2.5 1.3Z"/><path d="m6.2 5.3 3.1 3.9"/><path d="m12.4 3.4 3.1 4"/><path d="M3 11h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>',
			'droplets'     => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"/><path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97"/></svg>',
			'zap'          => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>',
			'swords'       => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="14.5 17.5 3 6 3 3 6 3 17.5 14.5"/><line x1="13" y1="19" x2="19" y2="13"/><line x1="16" y1="16" x2="20" y2="20"/><line x1="19" y1="21" x2="21" y2="19"/><polyline points="14.5 6.5 18 3 21 3 21 6 17.5 9.5"/><line x1="5" y1="14" x2="8.5" y2="17.5"/><line x1="3" y1="19" x2="5" y2="17"/></svg>',
			'coins'        => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><line x1="16.71" y1="13.88" x2="17.71" y2="13.88"/></svg>',
			'search'       => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
			'handshake'    => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/></svg>',
			'shuffle'      => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/></svg>',
			'leaf'         => '<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>',
			'coffee'       => '<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2v2"/><path d="M14 2v2"/><path d="M16 8a1 1 0 0 1 1 1v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1z"/><path d="M16 13h1a2 2 0 0 0 0-4h-1"/></svg>',
			'crosshair'    => '<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/><line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/><line x1="12" y1="22" x2="12" y2="18"/></svg>',
			'skull'        => '<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12.5 17-.5-1-.5 1h1z"/><path d="M15 22a1 1 0 0 0 1-1v-1a2 2 0 0 0 1.56-3.25 8 8 0 1 0-11.12 0A2 2 0 0 0 8 20v1a1 1 0 0 0 1 1z"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="12" r="1"/></svg>',
			'timer'        => '<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="10" y1="2" x2="14" y2="2"/><line x1="12" y1="14" x2="12" y2="8"/><path d="M4.93 10.93 1.1 14.76"/><path d="M19.07 10.93 22.9 14.76"/><circle cx="12" cy="14" r="8"/></svg>',
			'radio-tower'  => '<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4.9 16.1C1 12.2 1 5.8 4.9 1.9"/><path d="M7.8 4.7a6.14 6.14 0 0 0-.8 7.5"/><circle cx="12" cy="9" r="2"/><path d="M16.2 4.8c2 2 2.26 5.11.6 7.4"/><path d="M19.1 1.9a9.96 9.96 0 0 1 0 14.1"/><line x1="12" y1="9" x2="12" y2="22"/></svg>',
			'globe'        => '<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
			'infinity'     => '<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12c-2-2.5-4-4-6-4a4 4 0 0 0 0 8c2 0 4-1.5 6-4z"/><path d="M12 12c2 2.5 4 4 6 4a4 4 0 0 0 0-8c-2 0-4 1.5-6 4z"/></svg>',
			'copy'         => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>',
			'x'            => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
			'log-in'       => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
			'anchor'       => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22V8"/><path d="M5 12H2a10 10 0 0 0 20 0h-3"/><circle cx="12" cy="5" r="3"/></svg>',
			'syringe'      => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m18 2 4 4"/><path d="m17 7 3-3"/><path d="M19 9 8.7 19.3c-1 1-2.5 1-3.4 0l-.6-.6c-1-1-1-2.5 0-3.4L15 5"/><path d="m9 11 4 4"/><path d="m5 19-3 3"/><path d="m14 4 6 6"/></svg>',
		);

		ob_start();
		?>
		<div class="tw-campaigns-wrap">
			<div class="tw-campaigns-grid">
				<?php foreach ( $decoded as $c ) : ?>
					<?php
					$c_id_safe  = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $c['id'] ?? '' ) );
					$c_name_raw = ! empty( $c['name'] ) ? (string) $c['name'] : 'UNNAMED_' . $c_id_safe;
					$c_name     = esc_html( $c_name_raw );

					// World
					$world_junction = null;
					$cw_raw         = $c['cyber_campaign_worlds'] ?? null;
					if ( ! empty( $cw_raw ) ) {
						$world_junction = isset( $cw_raw[0] ) ? $cw_raw[0] : $cw_raw;
					}
					$world_rel = $world_junction ? ( $world_junction['cyber_worlds'] ?? null ) : null;
					$world_id  = $world_junction ? ( $world_junction['world_id'] ?? null ) : null;

					// Character
					$char_junction = null;
					$cc_raw        = $c['cyber_campaign_characters'] ?? null;
					if ( ! empty( $cc_raw ) ) {
						$char_junction = isset( $cc_raw[0] ) ? $cc_raw[0] : $cc_raw;
					}
					$char_rel = $char_junction ? ( $char_junction['cyber_characters'] ?? null ) : null;
					$char_id  = $char_junction ? ( $char_junction['character_id'] ?? null ) : null;

					// Mode
					$game_mode = isset( $c['game_mode'] ) ? (int) $c['game_mode'] : 1;
					$is_team   = 2 === $game_mode;
					$mode_str  = $is_team ? 'TEAM' : 'SOLO';
					$join_code = isset( $c['join_code'] ) ? (string) $c['join_code'] : '';

					// Metadata
					$world_type_val  = isset( $c['world_type'] ) ? (int) $c['world_type'] : 0;
					$game_length_val = isset( $c['game_length'] ) ? (int) $c['game_length'] : 0;
					$gm_style_val    = isset( $c['gm_style'] ) ? (string) $c['gm_style'] : '';
					$priority_val    = isset( $c['priority'] ) ? (int) $c['priority'] : 0;

					$length_data   = ( $game_length_val && isset( $game_length_map[ $game_length_val ] ) ) ? $game_length_map[ $game_length_val ] : null;
					$threat_data   = ( $world_type_val && isset( $world_type_map[ $world_type_val ] ) ) ? $world_type_map[ $world_type_val ] : null;
					$gm_data       = ( $gm_style_val && isset( $gm_style_map[ $gm_style_val ] ) ) ? $gm_style_map[ $gm_style_val ] : null;
					$priority_data = ( $priority_val && isset( $priority_map[ $priority_val ] ) ) ? $priority_map[ $priority_val ] : null;
					$threat_color  = $threat_data ? $threat_data['color'] : '#adff00';

					// Operative
					$operative_name  = '';
					$operative_class = '';
					$operative_race  = '';
					if ( is_array( $char_rel ) ) {
						$operative_race  = isset( $char_rel['cyber_races']['name'] ) ? (string) $char_rel['cyber_races']['name'] : '';
						$operative_class = isset( $char_rel['cyber_classes']['name'] ) ? (string) $char_rel['cyber_classes']['name'] : '';
						$operative_name  = isset( $char_rel['name'] ) ? (string) $char_rel['name'] : 'Unnamed';
					}

					// URLs
					$nodes_url  = '/nodes/?campaign_id=' . rawurlencode( $c_id_safe ) . '#tw-deployment-root';
					$agents_url = '/agents/?campaign_id=' . rawurlencode( $c_id_safe ) . '#tw-deployment-root';

					// Main action button
					if ( ! $world_rel ) {
						$main_btn = '<a href="' . esc_url( $nodes_url ) . '" class="tw-btn">' . $lucide_svg['anchor'] . 'Anchor Node</a>';
					} elseif ( ! $char_rel ) {
						$main_btn = '<a href="' . esc_url( $agents_url ) . '" class="tw-btn">' . $lucide_svg['syringe'] . 'Inject Agent</a>';
					} else {
						$main_btn = '<button class="tw-btn enter-matrix" type="button"'
							. ' data-id="' . esc_attr( $c_id_safe ) . '"'
							. ' data-character="' . esc_attr( (string) $char_id ) . '"'
							. ' data-mode="' . esc_attr( $mode_str ) . '"'
							. ' data-join="' . esc_attr( strtoupper( $join_code ) ) . '"'
							. ' data-world="' . esc_attr( (string) $world_id ) . '">'
							. $lucide_svg['log-in']
							. 'Enter Matrix</button>';
					}

					// Length progress bar HTML
					$length_bar_html = '';
					if ( $length_data ) {
						$segs = (int) $length_data['segs'];
						if ( 0 === $segs ) {
							// Endless — jeden ciągły pasek
							$length_bar_html = '
							<div class="tw-card-length-bar">
								<div class="tw-length-label"><span>' . esc_html( $length_data['label'] ) . '</span></div>
								<div class="tw-length-track tw-length--endless">
									<div class="tw-length-seg"></div>
								</div>
							</div>';
						} else {
							$segs_html = '';
							for ( $i = 0; $i < $segs; $i++ ) {
								$segs_html .= '<div class="tw-length-seg tw-seg--active" style="background:' . esc_attr( $threat_color ) . ';box-shadow:0 0 5px ' . esc_attr( $threat_color ) . '44;"></div>';
							}
							$length_bar_html = '
							<div class="tw-card-length-bar">
								<div class="tw-length-label"><span>' . esc_html( $length_data['label'] ) . '</span><span>' . $segs . ' segments</span></div>
								<div class="tw-length-track">' . $segs_html . '</div>
							</div>';
						}
					}
					?>

					<div id="campaign-card-<?php echo esc_attr( $c_id_safe ); ?>"
						 class="tw-campaign-card"
						 style="--threat-color:<?php echo esc_attr( $threat_color ); ?>">

						<!-- 1. SOLO/TEAM — wystawający lewy górny badge -->
						<div class="tw-card-mode-badge">
							<?php echo $is_team ? $lucide_svg['users'] : $lucide_svg['user']; // phpcs:ignore ?>
							<?php echo esc_html( $mode_str ); ?>
						</div>

						<!-- 6. DIFFICULTY — wystawający prawy górny tab -->
						<?php if ( $threat_data ) : ?>
						<div class="tw-card-difficulty-tab" style="color:<?php echo esc_attr( $threat_data['color'] ); ?>;border-color:<?php echo esc_attr( $threat_data['color'] ); ?>44;">
							<?php echo $lucide_svg[ $threat_data['icon'] ] ?? ''; // phpcs:ignore ?>
							<?php echo esc_html( $threat_data['label'] ); ?>
						</div>
						<?php endif; ?>
			<!-- gm -->
						<?php if ( $gm_data ) : ?>
<div class="tw-card-gm-style-block">
    <div class="tw-gm-style-badge">
        <?php echo $lucide_svg[ $gm_data['icon'] ] ?? ''; // phpcs:ignore ?>
        <?php echo esc_html( $gm_data['label'] ); ?>
    </div>
</div>
<?php endif; ?>

						<!-- 5. JOIN CODE — wystawający prawy środkowy panel (tylko TEAM) -->
<?php if ( $is_team && $join_code ) : ?>
<div class="tw-card-join">
    <div class="tw-join-invite-label">INVITE</div>
    <div class="tw-join-code-row">
        <span class="tw-join-code"><?php echo esc_html( strtoupper( $join_code ) ); ?></span>
        <button type="button"
            class="tw-join-copy-btn tw-copy-join-btn"
            data-code="<?php echo esc_attr( strtoupper( $join_code ) ); ?>"
            aria-label="Copy join code">
            <?php echo $lucide_svg['copy']; // phpcs:ignore ?>
        </button>
    </div>
</div>
<?php endif; ?>

						<!-- 3. SVG tło GM style -->
						<?php if ( $gm_data && ! empty( $gm_data['bg'] ) ) : ?>
						<div class="tw-card-bg" aria-hidden="true">
							<img src="<?php echo esc_url( $gm_data['bg'] ); ?>" alt="" width="300" height="200" loading="lazy">
						</div>
						<?php endif; ?>

						<div class="tw-card-inner">
							<div class="tw-card-scan-line"></div>

							<!-- 4. INFO —————————————————————————— -->
							<div class="tw-card-info">

								<!-- World name -->
								<div class="tw-info-world">
									<?php echo $world_rel ? esc_html( (string) ( $world_rel['name'] ?? '' ) ) : '<span class="tw-info-unset">No World</span>'; ?>
								</div>

								<!-- Campaign name -->
								<div class="tw-card-title"><?php echo $c_name; // phpcs:ignore ?></div>

								<!-- Agent -->
<?php if ( $operative_name ) : ?>
<div class="tw-info-agent-full">
    <span class="tw-info-label">Agent:</span>
    <span class="tw-info-value"><?php echo esc_html( $operative_name ); ?></span>
    <?php if ( $operative_race || $operative_class ) : ?>
    <span class="tw-info-sub"><?php echo esc_html( trim( $operative_race . ( $operative_class ? ' · ' . $operative_class : '' ) ) ); ?></span>
    <?php endif; ?>
</div>
<?php else : ?>
<div class="tw-info-agent-full">
    <span class="tw-info-label">Agent:</span>
    <span class="tw-info-value tw-info-unset">—</span>
</div>
<?php endif; ?>

								<!-- Objective / Priority -->
								<?php if ( $priority_data ) : ?>
								<div class="tw-info-objective">
									<span class="tw-info-label">Objective:</span>
									<span class="tw-info-value" style="display:inline-flex;align-items:center;gap:4px;">
										<?php echo $lucide_svg[ $priority_data['icon'] ] ?? ''; // phpcs:ignore ?>
										<?php echo esc_html( $priority_data['label'] ); ?>
									</span>
								</div>
								<?php endif; ?>

							</div><!-- .tw-card-info -->

							<!-- 2. LENGTH PROGRESS BAR -->
							<?php echo $length_bar_html; // phpcs:ignore ?>

							<!-- ACTIONS -->
							<div class="tw-campaign-actions">
								<?php echo $main_btn; // phpcs:ignore ?>

								<button type="button" class="tw-btn tw-btn-danger tw-delete-campaign-btn"
									data-id="<?php echo esc_attr( $c_id_safe ); ?>"
									data-name="<?php echo esc_attr( $c_name_raw ); ?>">
									<?php echo $lucide_svg['x']; // phpcs:ignore ?>
								</button>
							</div>

						</div><!-- .tw-card-inner -->
					</div><!-- .tw-campaign-card -->
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

add_shortcode( 'tw_list_campaigns', 'tw_list_campaigns_final_v8_modes' );
