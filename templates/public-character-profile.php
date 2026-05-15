<?php
/**
 * Template Name: Public Character Profile
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared helper: render terminal error screen.
 */
if ( ! function_exists( 'tw_render_public_character_terminal' ) ) {
	function tw_render_public_character_terminal( int $status_code, string $status_line ): void {
		status_header( $status_code );
		get_header();
		?>
		<div class="neo-monitor-frame neo-crt neo-public-character-error">
			<div class="neo-scanlines"></div>
			<div class="neo-noise"></div>

			<div class="neo-terminal-wrapper">
				<header class="neo-os-header">
					<div class="neo-os-header-left">
						<span class="neo-status-dot neo-blink"></span>
						<span class="neo-os-brand">NEO_WEAVE_OS_1.0.0</span>
					</div>
					<div class="neo-os-header-right">
						<span class="neo-node-id">SYSTEM_STREAM: CHARACTER_TRACE</span>
					</div>
				</header>

				<main class="neo-os-content">
					<div class="neo-status-bar">
						<span class="neo-sys-path">
							UPLINK_PATH: <span class="neo-accent">CORE://LEGEND/LOOKUP</span>
						</span>
					</div>

					<div class="neo-content-area">
						<p>&gt; QUERY: FIELD_AGENT_SIGNATURE</p>
						<p class="neo-accent">&gt; RESULT: NO MATCH FOUND</p>
						<p>&gt; STATUS: <?php echo esc_html( $status_line ); ?></p>
						<p>&gt; HINT: VERIFY LINK OR REQUEST NEW UPLINK FROM GAME MASTER</p>
					</div>
				</main>

				<footer class="neo-os-footer">
					<div class="neo-progress-container">
						<div class="neo-progress-bar"></div>
					</div>
					<div class="neo-os-footer-meta">
						<div class="neo-meta-item">
							<span class="status-dot"></span><span class="neo-label"> SESSION:</span>
							<span class="neo-value neo-accent">IDLE</span>
						</div>
						<div class="neo-meta-item">
							<span class="neo-label">SYNC:</span>
							<span id="sync-value" class="neo-value">00.0%</span>
						</div>
					</div>
				</footer>
			</div>
		</div>
		<?php
		get_footer();
		exit;
	}
}

$char_id = isset( $_GET['char_id'] ) ? sanitize_text_field( wp_unslash( $_GET['char_id'] ) ) : '';

if ( empty( $char_id ) || ! wp_is_uuid( $char_id ) ) {
	tw_render_public_character_terminal( 404, 'CHARACTER PROFILE NOT FOUND OR ACCESS LOCKED' );
}

$supabase_url = tw_supabase_url();
$anon_key     = tw_supabase_anon_key();

if ( empty( $supabase_url ) || empty( $anon_key ) ) {
	wp_die( esc_html__( 'Configuration error.', 'neoweaver' ), '', array( 'response' => 503 ) );
}

if ( ! function_exists( 'tw_supabase_args' ) ) {
	function tw_supabase_args( string $anon_key ): array {
		return array(
			'headers' => array(
				'apikey'        => $anon_key,
				'Authorization' => 'Bearer ' . $anon_key,
			),
			'timeout' => 10,
		);
	}
}

if ( ! function_exists( 'tw_get_public_character_data' ) ) {
	function tw_get_public_character_data( string $character_id, string $supabase_url, string $anon_key ): ?array {
		$url = add_query_arg(
			array(
				'id'        => 'eq.' . $character_id,
				'is_public' => 'eq.true',
				'select'    => 'id,name,bio,avatar,hp,mp,body,mind,reflex,spirit,lvl,view_count,created_at,cyber_classes(name),cyber_races(name)',
				'limit'     => 1,
			),
			trailingslashit( $supabase_url ) . 'rest/v1/cyber_characters'
		);

		$response = wp_remote_get( $url, tw_supabase_args( $anon_key ) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return ! empty( $data[0] ) && is_array( $data[0] ) ? $data[0] : null;
	}
}

if ( ! function_exists( 'tw_get_public_character_inventory' ) ) {
	function tw_get_public_character_inventory( string $character_id, string $supabase_url, string $anon_key ): array {
		$url = add_query_arg(
			array(
				'character_id' => 'eq.' . $character_id,
				'select'       => 'is_equipped,equipped_slot,custom_name,cyber_items(name,description,rarity,slot,img_url)',
			),
			trailingslashit( $supabase_url ) . 'rest/v1/cyber_character_inventory'
		);

		$response = wp_remote_get( $url, tw_supabase_args( $anon_key ) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array();
	}
}

$char = tw_get_public_character_data( $char_id, $supabase_url, $anon_key );

if ( empty( $char ) ) {
	tw_render_public_character_terminal( 404, 'CHARACTER PROFILE NOT FOUND OR ACCESS LOCKED' );
}

$is_bot       = false;
$user_agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
$bot_patterns = array(
	'bot',
	'crawl',
	'spider',
	'slurp',
	'mediapartners',
	'facebookexternalhit',
	'twitterbot',
	'linkedinbot',
	'whatsapp',
	'telegrambot',
	'applebot',
	'bingpreview',
	'pinterest',
	'semrush',
	'ahrefsbot',
	'mj12bot',
);

foreach ( $bot_patterns as $pattern ) {
	if ( str_contains( $user_agent, $pattern ) ) {
		$is_bot = true;
		break;
	}
}

$view_count_incremented = false;

if ( ! $is_bot ) {
	$rpc_url  = trailingslashit( $supabase_url ) . 'rest/v1/rpc/fn_increment_view_count';
	$rpc_resp = wp_remote_post(
		$rpc_url,
		array(
			'headers' => array(
				'apikey'        => $anon_key,
				'Authorization' => 'Bearer ' . $anon_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'char_id' => $char_id,
				)
			),
			'timeout' => 10,
		)
	);

	if ( ! is_wp_error( $rpc_resp ) && 200 === wp_remote_retrieve_response_code( $rpc_resp ) ) {
		$view_count_incremented = true;
	}
}

$display_views = ( isset( $char['view_count'] ) ? (int) $char['view_count'] : 0 ) + ( $view_count_incremented ? 1 : 0 );
$inventory     = tw_get_public_character_inventory( $char_id, $supabase_url, $anon_key );
$profile_url   = add_query_arg( 'char_id', rawurlencode( $char_id ), site_url( '/legend/' ) );

$avatar = ! empty( $char['avatar'] ) ? $char['avatar'] : 'https://via.placeholder.com/140x180?text=No+Data';
$char_name  = isset( $char['name'] ) ? $char['name'] : 'Unknown';
$class_name = isset( $char['cyber_classes']['name'] ) ? $char['cyber_classes']['name'] : 'Operative';
$race_name  = isset( $char['cyber_races']['name'] ) ? $char['cyber_races']['name'] : 'Unknown';
$level      = isset( $char['lvl'] ) ? (int) $char['lvl'] : 1;
$bio        = ! empty( $char['bio'] ) ? $char['bio'] : 'No records found in the archives.';

$qr_api_url = add_query_arg(
	array(
		'size' => '80x80',
		'data' => rawurlencode( $profile_url ),
	),
	'https://api.qrserver.com/v1/create-qr-code/'
);

$qr_resp = wp_remote_get(
	$qr_api_url,
	array(
		'timeout' => 5,
	)
);

$qr_src = '';

if ( ! is_wp_error( $qr_resp ) && 200 === wp_remote_retrieve_response_code( $qr_resp ) ) {
	$qr_src = 'data:image/png;base64,' . base64_encode( wp_remote_retrieve_body( $qr_resp ) );
}

wp_enqueue_style( 'neo-public-character-profile' );
wp_enqueue_script( 'chart-js' );
wp_enqueue_script( 'neo-public-character-profile' );

wp_localize_script(
	'neo-public-character-profile',
	'neoPublicCharacterProfile',
	array(
		'charId'        => $char_id,
		'archetypeText' => array(
			'empty' => 'VOID SOUL',
			'error' => 'DATA UNAVAILABLE',
		),
		'titles'        => array(
			'brutality' => 'THE JUGGERNAUT',
			'cunning'   => 'THE GHOST',
			'intellect' => 'THE ARCHITECT',
			'spirit'    => 'THE CONDUIT',
			'presence'  => 'THE ICON',
		),
	)
);

add_filter(
	'body_class',
	static function( array $classes ): array {
		$classes[] = 'character-profile';
		return $classes;
	}
);

get_header();
?>

<div class="character-card">
	<div class="profile-meta-bar">
		<div class="profile-meta-left">
			<span>ID: <?php echo esc_html( $char['id'] ); ?></span> |
			<span>Created: <?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $char['created_at'] ) ) ); ?></span>
		</div>
		<div class="profile-meta-right">
			<span>Views: <?php echo esc_html( $display_views ); ?></span>
			<button class="share-btn" type="button" data-share-url="<?php echo esc_url( $profile_url ); ?>">Share</button>
		</div>
	</div>

	<div class="character-header">
		<div class="character-avatar">
			<img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $char_name ); ?> avatar" width="140" height="180">
		</div>

		<div class="character-basic">
			<h1 class="character-name"><?php echo esc_html( $char_name ); ?></h1>
			<p class="character-meta-line"><?php echo esc_html( $class_name . ' • ' . $race_name . ' • Lvl ' . $level ); ?></p>

			<div class="loom-container">
				<div class="loom-header">
					<span class="loom-label">SOUL ARCHETYPE:</span><br>
					<span id="archetype-name">CALCULATING...</span>
				</div>
				<div class="chart-wrapper">
					<canvas id="fateChart"></canvas>
				</div>
			</div>
		</div>
	</div>

	<div class="character-panels">
		<div class="character-panel">
			<h2>Combat Parameters</h2>
			<ul class="stats-list">
				<li><span>HP</span><span><?php echo esc_html( $char['hp'] ); ?></span></li>
				<li><span>MP</span><span><?php echo esc_html( $char['mp'] ); ?></span></li>
				<li><span>Body</span><span><?php echo esc_html( $char['body'] ); ?></span></li>
				<li><span>Mind</span><span><?php echo esc_html( $char['mind'] ); ?></span></li>
				<li><span>Reflex</span><span><?php echo esc_html( $char['reflex'] ); ?></span></li>
				<li><span>Spirit</span><span><?php echo esc_html( $char['spirit'] ); ?></span></li>
			</ul>
		</div>

		<div class="character-panel">
			<h2>Biography</h2>
			<p><?php echo nl2br( esc_html( $bio ) ); ?></p>
		</div>

		<div class="character-panel">
			<h2>Field Loadout</h2>
			<?php if ( empty( $inventory ) ) : ?>
				<p>No visible gear registered.</p>
			<?php else : ?>
				<ul class="loadout-list">
					<?php foreach ( $inventory as $item ) : ?>
						<?php
						$item_core = isset( $item['cyber_items'] ) && is_array( $item['cyber_items'] ) ? $item['cyber_items'] : array();
						$name      = ! empty( $item['custom_name'] ) ? $item['custom_name'] : ( $item_core['name'] ?? 'Unknown Item' );
						$rarity    = $item_core['rarity'] ?? '';
						$slot      = ! empty( $item['equipped_slot'] ) ? $item['equipped_slot'] : ( $item_core['slot'] ?? '' );
						$desc      = $item_core['description'] ?? '';
						$equipped  = ! empty( $item['is_equipped'] );
						?>
						<li>
							<div class="loadout-item-main">
								<span>
									<?php if ( $equipped ) : ?>
										⭐
									<?php endif; ?>
									<?php echo esc_html( $name ); ?>
									<?php if ( $slot ) : ?>
										<span class="loadout-slot">(<?php echo esc_html( $slot ); ?>)</span>
									<?php endif; ?>
								</span>
								<span class="loadout-rarity"><?php echo esc_html( $rarity ); ?></span>
							</div>
							<?php if ( $desc ) : ?>
								<div class="loadout-item-desc">
									<?php echo esc_html( $desc ); ?>
								</div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>

	<div class="qr-container">
		<?php if ( $qr_src ) : ?>
			<img src="<?php echo esc_attr( $qr_src ); ?>" alt="QR code for this profile" width="80" height="80">
		<?php endif; ?>
	</div>
</div>

<?php
get_footer();
