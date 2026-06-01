<?php
/**
 * Shortcode: [tw_create_campaign] — 8-step Deployment creation wizard.
 * Registration is handled by Neoweaver_Public::__construct() via shortcode_campaign_creator().
 * A safety-net add_shortcode() is also registered here on the `init` hook so
 * the shortcode works even if Neoweaver_Public fails to load (BUG 13).
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// BUG 13 — fallback registration: if Neoweaver_Public never called add_shortcode(),
// this fires on `init` and ensures [tw_create_campaign] is always available.
add_action( 'init', function () {
	if (
		function_exists( 'neoweaver_shortcode_campaign_creator' ) &&
		! shortcode_exists( 'tw_create_campaign' )
	) {
		add_shortcode( 'tw_create_campaign', 'neoweaver_shortcode_campaign_creator' );
	}
} );

if ( ! function_exists( 'neoweaver_shortcode_campaign_creator' ) ) {
	function neoweaver_shortcode_campaign_creator(): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '<div class="neoweaver-screen"><div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div></div>';
		}

		// BUG 12 — filterable page slugs; override via add_filter() in wp-config or a child plugin.
		$node_url  = esc_url( home_url( apply_filters( 'neoweaver_new_node_slug', '/new-node/' ) ) );
		$agent_url = esc_url( home_url( apply_filters( 'neoweaver_new_agent_slug', '/new-agent/' ) ) );

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
					<div class="tw-progress-fill" id="tw-camp-progress-fill" style="width:<?php echo esc_attr( (string) round( 100 / $total_steps ) ); ?>%"></div>
					<?php for ( $i = 1; $i <= $total_steps; $i++ ) : ?>
						<span class="tw-progress-tick<?php echo 1 === $i ? ' active' : ''; ?>" data-tick="<?php echo esc_attr( (string) $i ); ?>"></span>
					<?php endfor; ?>
				</div>
				<div class="tw-progress-phase" id="tw-camp-progress-phase">DEPLOYMENT MATRIX</div>
			</div>

			<div class="tw-step active" data-step="1" data-phase="DEPLOYMENT MATRIX">
				<h2>// INITIALIZE DEPLOYMENT</h2>
				<p class="tw-question-text">Define the operation before uplink synchronization.</p>
				<label class="tw-field-label">
					<span>Deployment Name <span class="tw-required">*</span></span>
					<input type="text" id="tw-camp-name" name="campaign_name" placeholder="e.g. Operation Pale Signal · The Fray Protocol" maxlength="100" required />
				</label>
				<label class="tw-field-label" style="margin-top:24px;">
					<span>Operative Brief &amp; Custom Directives</span>
					<textarea id="tw-camp-notes" name="customize" rows="5" placeholder="Optional: lore, GM constraints, theme notes…"></textarea>
				</label>
				<div class="tw-nav-row"><span></span><button type="button" class="tw-btn-nav" id="tw-camp-step1-next">NEXT &rarr;</button></div>
			</div>

			<div class="tw-step" data-step="2" data-phase="GM PROTOCOL" data-field="gm_style">
				<h2>// GM PROTOCOL</h2>
				<p class="tw-question-text">Select the AI Game Master's narrative lens for this deployment.</p>
				<div class="tw-option-grid tw-option-grid--3">
					<?php foreach ( $gm_styles as $item ) : ?>
						<?php
						$val   = (string) $item[0];
						$label = (string) $item[1];
						$desc  = (string) $item[2];
						$emoji = (string) $item[3];
						$id    = 'tw-gm-' . sanitize_html_class( $val );
						?>
						<label class="tw-card-label" for="<?php echo esc_attr( $id ); ?>">
							<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="gm_style" value="<?php echo esc_attr( $val ); ?>" />
							<div class="tw-card-visual"><span class="tw-card-emoji"><?php echo esc_html( $emoji ); ?></span><strong><?php echo esc_html( $label ); ?></strong><span><?php echo esc_html( $desc ); ?></span></div>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button><button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button></div>
			</div>

			<div class="tw-step" data-step="3" data-phase="OPERATIVE MODE" data-field="game_mode">
				<h2>// OPERATIVE MODE</h2>
				<p class="tw-question-text">How many Operators will be synchronized to this deployment?</p>
				<div class="tw-option-grid tw-option-grid--2 tw-option-grid--centered">
					<?php foreach ( $game_modes as $item ) : ?>
						<?php
						$val   = (string) $item[0];
						$label = (string) $item[1];
						$desc  = (string) $item[2];
						$emoji = (string) $item[3];
						$id    = 'tw-mode-' . sanitize_html_class( $val );
						?>
						<label class="tw-card-label" for="<?php echo esc_attr( $id ); ?>">
							<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="game_mode" value="<?php echo esc_attr( $val ); ?>" />
							<div class="tw-card-visual"><span class="tw-card-emoji"><?php echo esc_html( $emoji ); ?></span><strong><?php echo esc_html( $label ); ?></strong><span><?php echo esc_html( $desc ); ?></span></div>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button><button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button></div>
			</div>

			<div class="tw-step" data-step="4" data-phase="OPERATION SCOPE" data-field="game_length">
				<h2>// OPERATION SCOPE</h2>
				<p class="tw-question-text">Define the temporal arc of this deployment.</p>
				<div class="tw-option-grid tw-option-grid--5">
					<?php foreach ( $game_lengths as $item ) : ?>
						<?php
						$val   = (string) $item[0];
						$label = (string) $item[1];
						$desc  = (string) $item[2];
						$emoji = (string) $item[3];
						$id    = 'tw-length-' . sanitize_html_class( $val );
						?>
						<label class="tw-card-label" for="<?php echo esc_attr( $id ); ?>">
							<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="game_length" value="<?php echo esc_attr( $val ); ?>" />
							<div class="tw-card-visual"><span class="tw-card-emoji"><?php echo esc_html( $emoji ); ?></span><strong><?php echo esc_html( $label ); ?></strong><span><?php echo esc_html( $desc ); ?></span></div>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button><button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button></div>
			</div>

			<div class="tw-step" data-step="5" data-phase="THREAT CALIBRATION" data-field="world_type">
				<h2>// THREAT CALIBRATION</h2>
				<p class="tw-question-text">Set the lethality and entropy pressure level for this deployment.</p>
				<div class="tw-option-grid tw-option-grid--5">
					<?php foreach ( $world_types as $item ) : ?>
						<?php
						$val   = (string) $item[0];
						$label = (string) $item[1];
						$desc  = (string) $item[2];
						$emoji = (string) $item[3];
						$id    = 'tw-wtype-' . sanitize_html_class( $val );
						?>
						<label class="tw-card-label" for="<?php echo esc_attr( $id ); ?>">
							<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="world_type" value="<?php echo esc_attr( $val ); ?>" />
							<div class="tw-card-visual"><span class="tw-card-emoji"><?php echo esc_html( $emoji ); ?></span><strong><?php echo esc_html( $label ); ?></strong><span><?php echo esc_html( $desc ); ?></span></div>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button><button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button></div>
			</div>

			<div class="tw-step" data-step="6" data-phase="MISSION PRIORITY" data-field="priority">
				<h2>// MISSION PRIORITY</h2>
				<p class="tw-question-text">What drives this deployment? The GM will weight quests, rewards and encounters accordingly.</p>
				<div class="tw-option-grid tw-option-grid--5">
					<?php foreach ( $priorities as $item ) : ?>
						<?php
						$val   = (string) $item[0];
						$label = (string) $item[1];
						$desc  = (string) $item[2];
						$emoji = (string) $item[3];
						$id    = 'tw-priority-' . sanitize_html_class( $val );
						?>
						<label class="tw-card-label" for="<?php echo esc_attr( $id ); ?>">
							<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="priority" value="<?php echo esc_attr( $val ); ?>" />
							<div class="tw-card-visual"><span class="tw-card-emoji"><?php echo esc_html( $emoji ); ?></span><strong><?php echo esc_html( $label ); ?></strong><span><?php echo esc_html( $desc ); ?></span></div>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button><button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button></div>
			</div>

			<div class="tw-step" data-step="7" data-phase="NODE & AGENT BINDING" data-optional="true">
				<h2>// NODE &amp; AGENT BINDING <span class="tw-optional-badge">OPTIONAL</span></h2>
				<p class="tw-question-text">Bind a Node and assign a Field Agent — or skip both and configure later from your Campaign dashboard.</p>

				<div class="tw-binding-section">
					<h3 class="tw-binding-label">// NODE <span class="tw-optional-badge">OPTIONAL</span></h3>
					<div class="tw-dynamic-grid" id="tw-camp-node-grid">
						<div class="tw-loading-state"><span class="tw-loading-dot"></span>SCANNING AVAILABLE NODES…</div>
					</div>
					<p class="tw-helper-text">No worlds yet? <a href="<?php echo $node_url; ?>" class="tw-link">Deploy a Node first &rarr;</a></p>
				</div>

				<div class="tw-binding-section" style="margin-top:40px;">
					<h3 class="tw-binding-label">// AGENT <span class="tw-optional-badge">OPTIONAL</span></h3>
					<p class="tw-helper-text" id="tw-agent-hint" style="margin-bottom:12px;">Select a Node above to filter compatible agents.</p>
					<div class="tw-dynamic-grid" id="tw-camp-agent-grid"></div>
					<p class="tw-helper-text">No agents yet? <a href="<?php echo $agent_url; ?>" class="tw-link">Create a Field Agent first &rarr;</a></p>
				</div>

				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button><button type="button" class="tw-btn-nav tw-btn-next">REVIEW &rarr;</button></div>
			</div>

			<div class="tw-step tw-step--summary" data-step="8" data-phase="SYSTEM REVIEW">
				<h2>// SYSTEM REVIEW</h2>
				<p class="tw-question-text">Verify deployment parameters before uplink.</p>
				<div class="tw-summary-grid">
					<?php
					$rows = [
						[ 'DEPLOY_ID',        'campaign_name', 1 ],
						[ 'DIRECTIVES',       'customize',     1 ],
						[ 'GM_PROTOCOL',      'gm_style',      2 ],
						[ 'OP_MODE',          'game_mode',     3 ],
						[ 'OP_SCOPE',         'game_length',   4 ],
						[ 'THREAT_LVL',       'world_type',    5 ],
						[ 'MISSION_PRIORITY', 'priority',      6 ],
					];
					foreach ( $rows as $row ) :
						$key   = (string) $row[0];
						$field = (string) $row[1];
						$goto  = (int) $row[2];
						?>
						<div class="tw-summary-row">
							<span class="tw-summary-key"><?php echo esc_html( $key ); ?></span>
							<span class="tw-summary-val" id="tw-summary-<?php echo esc_attr( $field ); ?>">&mdash;</span>
							<button type="button" class="tw-summary-edit" data-goto="<?php echo esc_attr( (string) $goto ); ?>">[ EDIT ]</button>
						</div>
					<?php endforeach; ?>

					<div class="tw-summary-row">
						<span class="tw-summary-key">NODE <span class="tw-optional-badge">OPTIONAL</span></span>
						<span class="tw-summary-val" id="tw-summary-world_id">&mdash; (unbound)</span>
						<button type="button" class="tw-summary-edit" data-goto="7">[ EDIT ]</button>
					</div>

					<div class="tw-summary-row">
						<span class="tw-summary-key">AGENT <span class="tw-optional-badge">OPTIONAL</span></span>
						<span class="tw-summary-val" id="tw-summary-character_id">&mdash; (unassigned)</span>
						<button type="button" class="tw-summary-edit" data-goto="7">[ EDIT ]</button>
					</div>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-deploy" id="tw-camp-submit">&#9658; UPLINK DEPLOYMENT</button>
				</div>

				<div class="tw-camp-status" aria-live="polite"></div>
			</div>

		</div>
		<?php

		return '<div class="neoweaver-screen">' . ( ob_get_clean() ?: '' ) . '</div>';
	}
}
