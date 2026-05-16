<?php
/**
 * SHORTCODE: [tw_connect_character_campaign]
 * NEOWEAVE AGENT INJECTION (World-Lock Protocol)
 * World-Lock: agent locked to world via cyber_campaign_worlds junction table
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register (not enqueue) assets once — on wp_enqueue_scripts hook.
 * Actual enqueuing happens inside the shortcode callback.
 */
function neoweaver_deployment_register_assets(): void {

	wp_register_style(
		'neoweaver-deployment',
		NEOWEAVER_PLUGIN_URL . 'assets/css/public/deployment2.css',
		[],
		NEOWEAVER_VERSION
	);

	wp_register_script(
		'neoweaver-deployment',
		NEOWEAVER_PLUGIN_URL . 'assets/js/public/deployment2.js',
		[],
		NEOWEAVER_VERSION,
		true
	);
}
wp_localize_script( 'nw-agents', 'nwAgentsConfig', [
    'url' => $supabase_url,
    'key' => $anon_key,
    'uid' => (int) $user_id,
] );
add_action( 'wp_enqueue_scripts', 'neoweaver_deployment_register_assets' );


/**
 * Shortcode callback: [tw_connect_character_campaign]
 */
function tw_connect_character_campaign_direct_v2(): string {

	if ( ! is_user_logged_in() ) {
		return '<p class="tw-message">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';
	}

	$user_id      = get_current_user_id();
	$supabase_url = trailingslashit( function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '' );
	$anon_key     = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';

	// Enqueue styles & scripts only when shortcode is actually rendered.
	wp_enqueue_style( 'neoweaver-deployment' );
	wp_enqueue_script( 'neoweaver-deployment' );

	// Pass server-side data to JavaScript.
	wp_localize_script(
		'neoweaver-deployment',
		'twDeploymentConfig',
		[
			'userId'         => $user_id,
			'supabaseUrl'    => $supabase_url,
			'supabaseKey'    => $anon_key,
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'nonce'          => wp_create_nonce( 'tw_deployment_nonce' ),
			'deploymentsUrl' => home_url( '/deployments/' ),
		]
	);

	ob_start();
	?>
	<div id="tw-deployment-root" class="tw-deployment-main-container">

		<audio id="tw-glitch-sound"
			src="https://cyber.nieodparady.pl/wp-content/uploads/2026/02/soundreality-glitch-177348.mp3"
			preload="auto"></audio>

		<!-- Fullscreen overlay shown during injection + polling -->
		<div id="tw-inject-overlay" class="tw-inject-overlay" hidden>
			<div class="tw-spinner-box">
				<div class="tw-spinner"></div>
				<p id="tw-spinner-msg">INJECTING AGENT INTO MATRIX…</p>
			</div>
		</div>

		<section class="tw-briefing-hero">
			<div class="tw-hero-overlay"></div>
			<div class="tw-hero-content">
				<div class="tw-hero-text">
					<span class="tw-label-alt">AGENT INJECTION PROTOCOL</span>
					<h1>DEPLOYING THE AGENT</h1>
					<p>Operator, select a verified Agent entity to inhabit the targeted Deployment matrix.
					   Note: Agents are locked to the World Node of their first deployment.</p>
				</div>
				<div class="tw-hero-stats">
					<div class="tw-hero-stat-item">
						<span class="n" id="stat-latency">0.024</span>
						<span class="l">LATENCY</span>
					</div>
					<div class="tw-hero-stat-item">
						<span class="n">STABLE</span>
						<span class="l">NODE SYNC</span>
					</div>
					<div class="tw-hero-stat-item tw-pulse-stat">
						<span class="n">READY</span>
						<span class="l">INJECTION</span>
					</div>
				</div>
			</div>
		</section>

		<div class="tw-deploy-grid">
			<div class="tw-deploy-controls">

				<div id="tw-char-status-console" class="tw-console-box">
					> System: Initializing Agent Assignment Interface...
				</div>

				<form id="tw-char-connect-form" class="tw-form-layout">

					<div class="tw-selection-group">

						<div class="tw-field-box">
							<label for="select-camp-char">
								<i class="dashicons dashicons-backup"></i>
								TARGET: DEPLOYMENTS (without agent)
							</label>
							<div class="tw-input-wrapper">
								<input
									type="text"
									id="search-camp-char"
									class="tw-input-cyber"
									placeholder="Filter matrices..."
									autocomplete="off"
								>
								<select
									id="select-camp-char"
									class="tw-select-cyber"
									size="6"
									required
									aria-label="Select deployment"
								></select>
							</div>
						</div>

						<div class="tw-field-box">
							<label for="select-char">
								<i class="dashicons dashicons-admin-users"></i>
								SUBJECT: AGENTS (Persona)
							</label>
							<div class="tw-input-wrapper">
								<input
									type="text"
									id="search-char"
									class="tw-input-cyber"
									placeholder="Locate entity..."
									autocomplete="off"
								>
								<select
									id="select-char"
									class="tw-select-cyber"
									size="6"
									required
									aria-label="Select agent"
								></select>
							</div>
						</div>

					</div><!-- /.tw-selection-group -->

					<button
						type="submit"
						id="btn-connect-char"
						class="tw-btn-deploy"
						disabled
						aria-disabled="true"
					>
						EXECUTE INJECTION [ENTER]
					</button>

					<div class="tw-world-lock-note">
						<h4>WORLD-LOCK PROTOCOL</h4>
						<p>
							An Agent can join multiple Deployments only within its origin World Node.
							Cross-world injection will be rejected by Neoweave safety protocols.
						</p>
					</div>

				</form><!-- /#tw-char-connect-form -->

			</div><!-- /.tw-deploy-controls -->
		</div><!-- /.tw-deploy-grid -->

	</div><!-- /#tw-deployment-root -->
	<?php
	return ob_get_clean() ?: '';
}
add_shortcode( 'tw_connect_character_campaign', 'tw_connect_character_campaign_direct_v2' );
