<?php
/**
 * Shortcode: [tw_create_campaign]
 *
 * Renders the 7-step Deployment (Campaign) creation wizard.
 * Self-contained — no external partial needed (same pattern as character creator).
 *
 * Steps:
 *   1. Deployment Identity  — name + optional brief/notes
 *   2. GM Style             — narrative archetype (cinematic_heroic / harsh_grounded / fast_tactical)
 *   3. Game Mode            — solo / co-op
 *   4. Game Length          — short / medium / standard / epic / endless
 *   5. Difficulty           — easy / casual / standard / hardcore / nightmare
 *   6. Node Binding         — pick one of the user's worlds (required)
 *   7. Agent Assignment     — pick one of the user's living characters (OPTIONAL)
 *   8. Summary              — review + deploy
 *
 * The endpoint at /wp-json/neoweaver/v1/campaign/create handles the Supabase
 * write via Neoweaver_Deployments_Creator.
 *
 * Dependencies:
 *   - tw_supabase_url() / tw_supabase_anon_key()   (supabase-helpers.php)
 *   - neoweaver-campaign-creator CSS/JS             (enqueued by Neoweaver_Public::enqueue_assets)
 *   - REST route neoweaver/v1/campaign/create       (includes/api-endpoints.php)
 *
 * CSS scope : .neoweaver-screen #tw-campaign-creator-wrapper
 * JS file   : assets/js/tw-campaign-creator.js
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Transient lifetime (seconds) for cached Supabase look-ups. */
if ( ! defined( 'NW_CAMPAIGN_CREATOR_CACHE_TTL' ) ) {
	define( 'NW_CAMPAIGN_CREATOR_CACHE_TTL', 60 );
}

if ( ! function_exists( 'neoweaver_shortcode_campaign_creator' ) ) {

	function neoweaver_shortcode_campaign_creator(): string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '<div class="neoweaver-screen"><div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div></div>';
		}

		$nonce    = wp_create_nonce( 'tw_campaign_nonce' );
		$rest_url = home_url( '/wp-json/neoweaver/v1/campaign/create' );

		wp_localize_script(
			'neoweaver-campaign-creator',
			'twCampaignConfig',
			[
				'nonce'       => $nonce,
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'restUrl'     => $rest_url,
				'campaignsUrl'=> home_url( '/campaigns/' ),
				'supabaseUrl' => function_exists( 'tw_supabase_url' )      ? tw_supabase_url()      : '',
				'supabaseKey' => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
				'userId'      => $user_id,
			]
		);

		// Spinner CSS — same file reused across all wizards.
		$spinner_css = NEOWEAVER_PLUGIN_DIR . 'assets/css/tw-node-spinner.css';
		if ( file_exists( $spinner_css ) ) {
			wp_enqueue_style(
				'neoweaver-node-spinner',
				NEOWEAVER_PLUGIN_URL . 'assets/css/tw-node-spinner.css',
				[],
				(string) filemtime( $spinner_css )
			);
		}

		// ── Step option definitions ───────────────────────────────────────────
		// Each entry: [ field_key, phase_label, question_text, options[] ]
		// Options: [ value (string for gm_style / int for others), label, description, emoji ]

		$gm_styles = [
			[ 'cinematic_heroic', 'Cinematic Heroic', 'Epic, legendary tone. Actions feel like destiny. Lethal but stylish.', '🎬' ],
			[ 'harsh_grounded',   'Harsh Grounded',   'Survival horror. Every victory costs. Descriptions are brutal.', '🩸' ],
			[ 'fast_tactical',    'Fast Tactical',     'Board-state focus. Dry wit. Brief descriptions, max player agency.', '⚡' ],
		];

		// Competitive removed — only Solo and Co-op remain.
		$game_modes = [
			[ 1, 'Solo',  'One Operator. One Agent. The Node is yours alone.',  '🕵️' ],
			[ 2, 'Co-op', 'Multiple Operators. Shared Node, shared Entropy.',   '🤝' ],
		];

		// 5 campaign lengths.
		$game_lengths = [
			[ 1, 'Short',    '1–3 sessions. Tight objective, fast resolution.',              '⚡' ],
			[ 2, 'Medium',   '4–6 sessions. Extended arc with a twist.',                     '⏱️' ],
			[ 3, 'Standard', '7–12 sessions. Full arc with mid-game pivot.',                 '📡' ],
			[ 4, 'Epic',     '13–25 sessions. Major faction wars. World-shaping outcomes.',  '🌐' ],
			[ 5, 'Endless',  'No defined end. Node evolves until Hard Reset.',               '♾️' ],
		];

		// 5 difficulty levels.
		$difficulties = [
			[ 1, 'Easy',      'Training protocol. Entropy is forgiving. Great for newcomers.',   '🌱' ],
			[ 2, 'Casual',    'Low stakes. Story over challenge. No permadeath pressure.',        '☕' ],
			[ 3, 'Standard',  'Balanced risk. Protocol-default experience.',                      '🎯' ],
			[ 4, 'Hardcore',  'Elevated lethality. Entropy rises faster.',                        '💀' ],
			[ 5, 'Nightmare', 'Maximum entropy pressure. Time itself costs Sync.',               '☠️' ],
		];

		$total_steps = 8; // 1 identity + 1 gm_style + 1 mode + 1 length + 1 difficulty + 1 node + 1 agent + 1 summary

		ob_start();
		?>
		<div id="tw-campaign-creator-wrapper" data-total-steps="<?php echo esc_attr( $total_steps ); ?>">

			<!-- ═══════════════════════════════════════════════════════════════
			     PROGRESS BAR
			     ═══════════════════════════════════════════════════════════════ -->
			<div class="tw-progress-bar" aria-label="Deployment configuration progress">
				<div class="tw-progress-header">
					<span class="tw-progress-label">DEPLOYMENT_INIT<span class="tw-blink">_</span></span>
					<span class="tw-progress-counter">
						<span id="tw-camp-step-current">1</span> / <?php echo esc_html( $total_steps ); ?>
					</span>
				</div>
				<div class="tw-progress-track">
					<div class="tw-progress-fill" id="tw-camp-progress-fill"
					     style="width:<?php echo round( 100 / $total_steps ); ?>%"></div>
					<?php for ( $i = 1; $i <= $total_steps; $i++ ) : ?>
						<span class="tw-progress-tick<?php echo $i === 1 ? ' active' : ''; ?>"
						      data-tick="<?php echo esc_attr( $i ); ?>"></span>
					<?php endfor; ?>
				</div>
				<div class="tw-progress-phase" id="tw-camp-progress-phase">DEPLOYMENT MATRIX</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     STEP 1 — Identity
			     ═══════════════════════════════════════════════════════════════ -->
			<div class="tw-step active" data-step="1" data-phase="DEPLOYMENT MATRIX">
				<h2>// INITIALIZE DEPLOYMENT</h2>
				<p class="tw-question-text">Define the operation before uplink synchronization.</p>

				<label class="tw-field-label">
					<span>Deployment Name <span class="tw-required">*</span></span>
					<input type="text" id="tw-camp-name" name="campaign_name"
					       placeholder="e.g. Operation Pale Signal · The Fray Protocol · Exodus Arc"
					       maxlength="100" required />
				</label>

				<label class="tw-field-label" style="margin-top:24px;">
					<span>Operative Brief &amp; Custom Directives</span>
					<textarea id="tw-camp-notes" name="customize" rows="5"
					          placeholder="Optional: lore, GM constraints, theme notes, world-specific rules…"></textarea>
				</label>

				<div class="tw-nav-row">
					<span></span>
					<button type="button" class="tw-btn-nav" id="tw-camp-step1-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     STEP 2 — GM Style
			     ═══════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="2" data-phase="GM PROTOCOL" data-field="gm_style">
				<h2>// GM PROTOCOL</h2>
				<p class="tw-question-text">
					Select the AI Game Master's narrative lens for this deployment.
					This shapes tone, description depth, and combat grittiness.
				</p>

				<div class="tw-option-grid tw-option-grid--3">
					<?php foreach ( $gm_styles as $opt ) :
						[ $val, $label, $desc, $emoji ] = $opt;
						$id = 'tw-gm-' . esc_attr( $val );
					?>
					<label class="tw-card-label" for="<?php echo $id; ?>">
						<input type="radio" id="<?php echo $id; ?>" name="gm_style"
						       value="<?php echo esc_attr( $val ); ?>" required />
						<div class="tw-card-visual">
							<span class="tw-card-emoji"><?php echo $emoji; ?></span>
							<strong><?php echo esc_html( $label ); ?></strong>
							<span><?php echo esc_html( $desc ); ?></span>
						</div>
					</label>
					<?php endforeach; ?>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     STEP 3 — Game Mode (Solo / Co-op only)
			     ═══════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="3" data-phase="OPERATIVE MODE" data-field="game_mode">
				<h2>// OPERATIVE MODE</h2>
				<p class="tw-question-text">How many Operators will be synchronized to this deployment?</p>

				<div class="tw-option-grid tw-option-grid--2">
					<?php foreach ( $game_modes as $opt ) :
						[ $val, $label, $desc, $emoji ] = $opt;
						$id = 'tw-mode-' . esc_attr( $val );
					?>
					<label class="tw-card-label" for="<?php echo $id; ?>">
						<input type="radio" id="<?php echo $id; ?>" name="game_mode"
						       value="<?php echo esc_attr( $val ); ?>" required />
						<div class="tw-card-visual">
							<span class="tw-card-emoji"><?php echo $emoji; ?></span>
							<strong><?php echo esc_html( $label ); ?></strong>
							<span><?php echo esc_html( $desc ); ?></span>
						</div>
					</label>
					<?php endforeach; ?>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     STEP 4 — Game Length (5 options)
			     ═══════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="4" data-phase="OPERATION SCOPE" data-field="game_length">
				<h2>// OPERATION SCOPE</h2>
				<p class="tw-question-text">Define the temporal arc of this deployment.</p>

				<div class="tw-option-grid tw-option-grid--5">
					<?php foreach ( $game_lengths as $opt ) :
						[ $val, $label, $desc, $emoji ] = $opt;
						$id = 'tw-length-' . esc_attr( $val );
					?>
					<label class="tw-card-label" for="<?php echo $id; ?>">
						<input type="radio" id="<?php echo $id; ?>" name="game_length"
						       value="<?php echo esc_attr( $val ); ?>" required />
						<div class="tw-card-visual">
							<span class="tw-card-emoji"><?php echo $emoji; ?></span>
							<strong><?php echo esc_html( $label ); ?></strong>
							<span><?php echo esc_html( $desc ); ?></span>
						</div>
					</label>
					<?php endforeach; ?>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     STEP 5 — Difficulty (5 options)
			     ═══════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="5" data-phase="THREAT CALIBRATION" data-field="difficulty">
				<h2>// THREAT CALIBRATION</h2>
				<p class="tw-question-text">Set the lethality and entropy pressure level for this deployment.</p>

				<div class="tw-option-grid tw-option-grid--5">
					<?php foreach ( $difficulties as $opt ) :
						[ $val, $label, $desc, $emoji ] = $opt;
						$id = 'tw-difficulty-' . esc_attr( $val );
					?>
					<label class="tw-card-label" for="<?php echo $id; ?>">
						<input type="radio" id="<?php echo $id; ?>" name="difficulty"
						       value="<?php echo esc_attr( $val ); ?>" required />
						<div class="tw-card-visual">
							<span class="tw-card-emoji"><?php echo $emoji; ?></span>
							<strong><?php echo esc_html( $label ); ?></strong>
							<span><?php echo esc_html( $desc ); ?></span>
						</div>
					</label>
					<?php endforeach; ?>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     STEP 6 — Node Binding (required)
			     ═══════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="6" data-phase="NODE UPLINK" data-field="world_id">
				<h2>// NODE UPLINK</h2>
				<p class="tw-question-text">
					Select the Node (world) this deployment will run inside.
					All Entropy changes, Legacies and Agent deaths will affect this world permanently.
				</p>

				<div class="tw-dynamic-grid" id="tw-camp-node-grid">
					<div class="tw-loading-state">
						<span class="tw-loading-dot"></span>
						SCANNING AVAILABLE NODES…
					</div>
				</div>

				<p class="tw-helper-text">
					No worlds yet? <a href="<?php echo esc_url( home_url( '/create-world/' ) ); ?>" class="tw-link">Deploy a Node first &rarr;</a>
				</p>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     STEP 7 — Agent Assignment (OPTIONAL)
			     ═══════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="7" data-phase="AGENT ASSIGNMENT" data-field="character_id" data-optional="true">
				<h2>// AGENT ASSIGNMENT <span class="tw-optional-badge">OPTIONAL</span></h2>
				<p class="tw-question-text">
					Assign a Field Agent to this deployment — or skip and assign one later.
					Only living agents compatible with the selected Node are shown.
				</p>

				<div class="tw-dynamic-grid" id="tw-camp-agent-grid">
					<div class="tw-loading-state">
						<span class="tw-loading-dot"></span>
						FETCHING AVAILABLE AGENTS…
					</div>
				</div>

				<p class="tw-helper-text">
					No agents yet? <a href="<?php echo esc_url( home_url( '/create-agent/' ) ); ?>" class="tw-link">Create a Field Agent first &rarr;</a>
				</p>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">REVIEW &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     STEP 8 — Summary + Deploy
			     ═══════════════════════════════════════════════════════════════ -->
			<div class="tw-step tw-step--summary" data-step="8" data-phase="SYSTEM REVIEW">
				<h2>// SYSTEM REVIEW</h2>
				<p class="tw-question-text">Verify deployment parameters before uplink. Edit any field to reconfigure.</p>

				<div class="tw-summary-grid">
					<div class="tw-summary-row" data-summary-field="campaign_name">
						<span class="tw-summary-key">DEPLOY_ID</span>
						<span class="tw-summary-val" id="tw-summary-campaign_name">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="1">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="customize">
						<span class="tw-summary-key">DIRECTIVES</span>
						<span class="tw-summary-val" id="tw-summary-customize">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="1">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="gm_style">
						<span class="tw-summary-key">GM_PROTOCOL</span>
						<span class="tw-summary-val" id="tw-summary-gm_style">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="2">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="game_mode">
						<span class="tw-summary-key">OP_MODE</span>
						<span class="tw-summary-val" id="tw-summary-game_mode">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="3">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="game_length">
						<span class="tw-summary-key">OP_SCOPE</span>
						<span class="tw-summary-val" id="tw-summary-game_length">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="4">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="difficulty">
						<span class="tw-summary-key">THREAT_LVL</span>
						<span class="tw-summary-val" id="tw-summary-difficulty">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="5">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="world_id">
						<span class="tw-summary-key">NODE</span>
						<span class="tw-summary-val" id="tw-summary-world_id">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="6">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="character_id">
						<span class="tw-summary-key">AGENT <span class="tw-optional-badge">OPTIONAL</span></span>
						<span class="tw-summary-val" id="tw-summary-character_id">— (unassigned)</span>
						<button type="button" class="tw-summary-edit" data-goto="7">[ EDIT ]</button>
					</div>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-deploy" id="tw-camp-submit">
						&#9658; UPLINK DEPLOYMENT
					</button>
				</div>

				<div class="tw-camp-status" aria-live="polite"></div>
			</div>

		</div><!-- /#tw-campaign-creator-wrapper -->

		<style>
		/* Optional badge styling */
		.tw-optional-badge {
			font-size: 0.6rem;
			font-weight: 700;
			letter-spacing: 1px;
			color: #000;
			background: #adff00;
			padding: 2px 6px;
			vertical-align: middle;
			margin-left: 8px;
			clip-path: polygon(4px 0%, 100% 0%, calc(100% - 4px) 100%, 0% 100%);
		}
		/* 5-column grid for length & difficulty */
		.tw-option-grid--5 {
			grid-template-columns: repeat(5, 1fr);
		}
		/* 2-column grid for mode */
		.tw-option-grid--2 {
			grid-template-columns: repeat(2, 1fr);
			max-width: 600px;
		}
		</style>
		<?php
		$html = ob_get_clean() ?: '';
		return '<div class="neoweaver-screen">' . $html . '</div>';
	}
}

// neoweaver_campaign_creator_supabase_get() helper kept for backwards compat
// (used by nothing now but may be referenced in legacy Make.com scenarios).
if ( ! function_exists( 'neoweaver_campaign_creator_supabase_get' ) ) {

	function neoweaver_campaign_creator_supabase_get(
		string $table,
		array $query_args,
		int $user_id = 0,
		int $ttl = 0
	): array {
		$cache_key = ( $ttl > 0 && $user_id > 0 )
			? 'tw_sb_' . $user_id . '_' . md5( $table . serialize( $query_args ) )
			: '';

		if ( $cache_key ) {
			$cached = get_transient( $cache_key );
			if ( $cached !== false ) return $cached;
		}

		$anon_key = tw_supabase_anon_key();
		$response = wp_remote_get(
			add_query_arg( $query_args, trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table ),
			[
				'headers' => [
					'apikey'        => $anon_key,
					'Authorization' => 'Bearer ' . $anon_key,
				],
			]
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$reason = is_wp_error( $response )
				? $response->get_error_message()
				: wp_remote_retrieve_response_code( $response );
			error_log( "NeoWeaver campaign creator: Supabase fetch failed for '{$table}' – {$reason}" );
			return [];
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
		if ( $cache_key ) set_transient( $cache_key, $rows, $ttl );
		return $rows;
	}
}
