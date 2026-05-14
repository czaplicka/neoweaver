<?php
/**
 * Shortcode: [tw_create_campaign]  —  8-step Deployment creation wizard.
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NW_CAMPAIGN_CREATOR_CACHE_TTL' ) ) {
	define( 'NW_CAMPAIGN_CREATOR_CACHE_TTL', 60 );
}

if ( ! function_exists( 'neoweaver_shortcode_campaign_creator' ) ) {

	function neoweaver_shortcode_campaign_creator(): string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '<div class="neoweaver-screen"><div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div></div>';
		}

		$campaign_css = NEOWEAVER_PLUGIN_DIR . 'assets/css/public/campaign-creator.css';
		if ( file_exists( $campaign_css ) ) {
			wp_enqueue_style(
				'neoweaver-campaign-creator',
				NEOWEAVER_PLUGIN_URL . 'assets/css/public/campaign-creator.css',
				[],
				NEOWEAVER_VERSION
			);
		}

		$campaign_js = NEOWEAVER_PLUGIN_DIR . 'assets/js/public/campaign-creator.js';
		if ( file_exists( $campaign_js ) ) {
			wp_enqueue_script(
				'neoweaver-campaign-creator',
				NEOWEAVER_PLUGIN_URL . 'assets/js/public/campaign-creator.js',
				[],
				NEOWEAVER_VERSION,
				true
			);
		}

		$nonce    = wp_create_nonce( 'tw_campaign_nonce' );
		$rest_url = home_url( '/wp-json/neoweaver/v1/campaign/create' );

		wp_localize_script(
			'neoweaver-campaign-creator',
			'twCampaignConfig',
			[
				'nonce'        => $nonce,
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'restUrl'      => $rest_url,
				'campaignsUrl' => home_url( '/deployments/' ),
				'supabaseUrl'  => function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '',
				'supabaseKey'  => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
				'userId'       => $user_id,
				'uploadsUrl'   => wp_upload_dir()['baseurl'],
			]
		);

		$spinner_css = NEOWEAVER_PLUGIN_DIR . 'assets/css/public/node-spinner.css';
		if ( file_exists( $spinner_css ) ) {
			wp_enqueue_style(
				'neoweaver-node-spinner',
				NEOWEAVER_PLUGIN_URL . 'assets/css/public/node-spinner.css',
				[],
				(string) filemtime( $spinner_css )
			);
		}

		$gm_styles = [
			[ 'cinematic_heroic', 'Cinematic Heroic', 'Epic, legendary tone. Actions feel like destiny. Lethal but stylish.', '🎬' ],
			[ 'harsh_grounded', 'Harsh Grounded', 'Survival horror. Every victory costs. Descriptions are brutal.', '🩸' ],
			[ 'fast_tactical', 'Fast Tactical', 'Board-state focus. Dry wit. Brief descriptions, max player agency.', '⚡' ],
		];

		$game_modes = [
			[ 1, 'Solo', 'One Operator. One Agent. The Node is yours alone.', '🕵️' ],
			[ 2, 'Team', 'Multiple Operators. Shared Node, shared Entropy.', '🤝' ],
		];

		$game_lengths = [
			[ 1, 'Short', '1–3 sessions. Tight objective, fast resolution.', '⚡' ],
			[ 2, 'Medium', '4–6 sessions. Extended arc with a twist.', '⏱️' ],
			[ 3, 'Standard', '7–12 sessions. Full arc with mid-game pivot.', '📡' ],
			[ 4, 'Epic', '13–25 sessions. Major faction wars. World-shaping outcomes.', '🌐' ],
			[ 5, 'Endless', 'No defined end. Node evolves until Hard Reset.', '♾️' ],
		];

		$world_types = [
			[ 1, 'Easy', 'Training protocol. Entropy is forgiving. Great for newcomers.', '🌱' ],
			[ 2, 'Casual', 'Low stakes. Story over challenge. No permadeath pressure.', '☕' ],
			[ 3, 'Standard', 'Balanced risk. Protocol-default experience.', '🎯' ],
			[ 4, 'Hardcore', 'Elevated lethality. Entropy rises faster.', '💀' ],
			[ 5, 'Nightmare', 'Maximum entropy pressure. Time itself costs Sync.', '☠️' ],
		];

		$priorities = [
			[ 1, 'Combat', 'Battles, skirmishes and tactical threats dominate the arc.', '⚔️' ],
			[ 2, 'Wealth', 'Resources, trade routes and economic control drive the story.', '💰' ],
			[ 3, 'Discovery', 'Ancient secrets, uncharted zones, lore and exploration intertwined.', '🔍' ],
			[ 4, 'Relations', 'Alliances, betrayals and social dynamics are the main engine.', '🤝' ],
			[ 5, 'Mix', 'Balanced blend. The Node decides what surfaces each session.', '🎲' ],
		];

		$total_steps = 8;

		ob_start();
		?>
		<div id="tw-campaign-creator-wrapper" data-total-steps="<?php echo esc_attr( $total_steps ); ?>">

			<div class="tw-progress-bar" aria-label="Deployment configuration progress">
				<div class="tw-progress-header">
					<span class="tw-progress-label">DEPLOYMENT_INIT<span class="tw-blink">_</span></span>
					<span class="tw-progress-counter"><span id="tw-camp-step-current">1</span> / <?php echo esc_html( $total_steps ); ?></span>
				</div>
				<div class="tw-progress-track">
					<div class="tw-progress-fill" id="tw-camp-progress-fill" style="width:<?php echo round( 100 / $total_steps ); ?>%"></div>
					<?php for ( $i = 1; $i <= $total_steps; $i++ ) : ?>
						<span class="tw-progress-tick<?php echo $i === 1 ? ' active' : ''; ?>" data-tick="<?php echo esc_attr( $i ); ?>"></span>
					<?php endfor; ?>
				</div>
				<div class="tw-progress-phase" id="tw-camp-progress-phase">DEPLOYMENT MATRIX</div>
			</div>

			<!-- reszta HTML bez zmian -->
		</div>
		<?php
		return '<div class="neoweaver-screen">' . ( ob_get_clean() ?: '' ) . '</div>';
	}
}
