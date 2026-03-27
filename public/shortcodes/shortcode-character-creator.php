<?php
/**
 * Shortcode: [tale_weaver_character_creator]
 *
 * Renders the 7-step Field Agent creation wizard.
 * Mirrors the world creator pattern: PHP prepares data, a template partial
 * renders the HTML, JS drives the multi-step UX, and the REST endpoint
 * at /wp-json/neoweaver/v1/character/create handles the Supabase write.
 *
 * Steps:
 *   1. Agent Identity  — name, pronouns (radio), backstory
 *   2. Race            — loaded dynamically from cyber_races via REST
 *   3. Class           — loaded dynamically from cyber_classes via REST
 *   4. Attributes      — Body / Reflex / Mind / Spirit (1–5 sliders)
 *   5. Node Binding    — pick one of the user's worlds (cyber_worlds)
 *   6. Avatar          — optional image upload
 *   7. Summary         — review + deploy
 *
 * Dependencies:
 *   - tw_supabase_url() / tw_supabase_anon_key()    (supabase-helpers.php)
 *   - neoweaver-char-creator CSS/JS                 (enqueued by Neoweaver_Public::enqueue_assets)
 *   - REST route neoweaver/v1/character/create      (includes/api-endpoints.php)
 *
 * CSS scope : .neoweaver-screen #tw-char-creator-wrapper
 * JS file   : assets/js/tw-character-creator.js
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'neoweaver_shortcode_character_creator' ) ) {

	function neoweaver_shortcode_character_creator(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="neoweaver-screen"><div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div></div>';
		}

		$nonce    = wp_create_nonce( 'tw_character_nonce' );
		$rest_url = home_url( '/wp-json/neoweaver/v1/character/create' );
		$agents_url = home_url( '/agents/' ); // redirect target after success

		// Pass config to JS — same pattern as world creator.
		wp_localize_script(
			'neoweaver-char-creator',
			'twCharCreatorConfig',
			[
				'nonce'      => $nonce,
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'restUrl'    => $rest_url,
				'agentsUrl'  => $agents_url,
				'supabaseUrl' => function_exists( 'tw_supabase_url' )  ? tw_supabase_url()  : '',
				'supabaseKey' => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
			]
		);

		// Enqueue spinner CSS (same spinner used by world creator).
		$spinner_css = NEOWEAVER_PLUGIN_DIR . 'assets/css/tw-node-spinner.css';
		if ( file_exists( $spinner_css ) ) {
			wp_enqueue_style(
				'neoweaver-node-spinner',
				NEOWEAVER_PLUGIN_URL . 'assets/css/tw-node-spinner.css',
				[],
				(string) filemtime( $spinner_css )
			);
		}

		// Attribute definitions — server-side so the PHP array is the single
		// source of truth for labels and descriptions.
		$attrs = [
			'body'   => [
				'label' => 'BODY',
				'sub'   => 'STR + CON',
				'desc'  => 'Brute force, health pool, heavy lifting, physical endurance.',
				'icon'  => '💪',
			],
			'reflex' => [
				'label' => 'REFLEX',
				'sub'   => 'DEX',
				'desc'  => 'Speed, evasion, precision aiming, reaction time.',
				'icon'  => '⚡',
			],
			'mind'   => [
				'label' => 'MIND',
				'sub'   => 'INT + WIS',
				'desc'  => 'Logic, repair, investigation, hacking, situational awareness.',
				'icon'  => '🧠',
			],
			'spirit' => [
				'label' => 'SPIRIT',
				'sub'   => 'CHA + WILL',
				'desc'  => 'Magic power, persuasion, willpower, social influence.',
				'icon'  => '✨',
			],
		];

		// Pronoun options — displayed as radio buttons.
		$pronoun_options = [
			'she/her'    => 'she/her',
			'he/him'     => 'he/him',
			'they/them'  => 'they/them',
			'xe/xem'     => 'xe/xem',
			'custom'     => 'custom…',
		];

		// Total attribute points the player can distribute (protocol: 12 pts, min 1 per attr).
		$attr_pool  = 12;
		$attr_min   = 1;
		$attr_max   = 5;

		// Total steps count: identity + race + class + attrs + node + avatar + summary = 7
		$total_steps = 7;

		ob_start();
		?>
		<div id="tw-char-creator-wrapper" data-total-steps="<?php echo esc_attr( $total_steps ); ?>">

			<!-- ═══════════════════════════════════════════════════════════════════
			     PROGRESS BAR
			     ═══════════════════════════════════════════════════════════════════ -->
			<div class="tw-progress-bar" aria-label="Agent configuration progress">
				<div class="tw-progress-header">
					<span class="tw-progress-label">AGENT_INIT<span class="tw-blink">_</span></span>
					<span class="tw-progress-counter">
						<span id="tw-char-step-current">1</span> / <?php echo esc_html( $total_steps ); ?>
					</span>
				</div>
				<div class="tw-progress-track">
					<div class="tw-progress-fill" id="tw-char-progress-fill"
					     style="width:<?php echo round( 100 / $total_steps ); ?>%"></div>
					<?php for ( $i = 1; $i <= $total_steps; $i++ ) : ?>
						<span class="tw-progress-tick<?php echo $i === 1 ? ' active' : ''; ?>"
						      data-tick="<?php echo esc_attr( $i ); ?>"></span>
					<?php endfor; ?>
				</div>
				<div class="tw-progress-phase" id="tw-char-progress-phase">IDENTITY MATRIX</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════════
			     STEP 1 — Identity
			     ═══════════════════════════════════════════════════════════════════ -->
			<div class="tw-step active" data-step="1" data-phase="IDENTITY MATRIX">
				<h2>// INITIALIZE AGENT</h2>
				<p class="tw-question-text">Define the operative's identity before synchronization.</p>

				<label class="tw-field-label">
					<span>Agent Designation <span class="tw-required">*</span></span>
					<input type="text" id="tw-char-name" name="character_name"
					       placeholder="e.g. Ghost-7 / Mara Voss / The Architect"
					       maxlength="80" required />
				</label>

				<fieldset class="tw-field-label tw-pronoun-fieldset" style="margin-top:24px;border:none;padding:0;">
					<legend class="tw-field-label__legend">Pronouns</legend>
					<div class="tw-pronoun-options">
						<?php foreach ( $pronoun_options as $value => $label ) : ?>
							<label class="tw-pronoun-option">
								<input type="radio"
								       name="pronouns"
								       id="tw-pronoun-<?php echo esc_attr( $value ); ?>"
								       value="<?php echo esc_attr( $value ); ?>"
								       class="tw-pronoun-radio" />
								<span class="tw-pronoun-label"><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<!-- Custom pronoun text field — shown only when "custom" radio is selected -->
					<input type="text"
					       id="tw-char-pronouns-custom"
					       name="pronouns_custom"
					       placeholder="e.g. ze/zir · fae/faer"
					       maxlength="40"
					       style="display:none;margin-top:10px;" />
				</fieldset>

				<label class="tw-field-label" style="margin-top:24px;">
					<span>Backstory &amp; Operative Brief</span>
					<textarea id="tw-char-backstory" name="backstory" rows="5"
					          placeholder="Who is this agent? What drove them to the NeoWeave? What do they want?"></textarea>
				</label>

				<div class="tw-nav-row">
					<span></span>
					<button type="button" class="tw-btn-nav" id="tw-char-step1-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════════
			     STEP 2 — Race
			     ═══════════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="2" data-phase="RACE PROTOCOL" data-field="race">
				<h2>// RACE PROTOCOL</h2>
				<p class="tw-question-text">Select the operative's biological or synthetic origin.</p>

				<div class="tw-dynamic-grid" id="tw-race-grid">
					<div class="tw-loading-state">
						<span class="tw-loading-dot"></span>
						FETCHING RACE DATA FROM NODE…
					</div>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════════
			     STEP 3 — Class
			     ═══════════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="3" data-phase="CLASS MATRIX" data-field="class">
				<h2>// CLASS MATRIX</h2>
				<p class="tw-question-text">Select the operative's combat and skill archetype.</p>

				<div class="tw-dynamic-grid" id="tw-class-grid">
					<div class="tw-loading-state">
						<span class="tw-loading-dot"></span>
						FETCHING CLASS DATA FROM NODE…
					</div>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════════
			     STEP 4 — Attributes
			     ═══════════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="4" data-phase="BIOMETRIC CALIBRATION">
				<h2>// BIOMETRIC CALIBRATION</h2>
				<p class="tw-question-text">
					Distribute <strong id="tw-attr-pool-display"><?php echo esc_html( $attr_pool ); ?></strong> attribute
					points across four core systems. Each attribute starts at
					<?php echo esc_html( $attr_min ); ?> and caps at <?php echo esc_html( $attr_max ); ?>.
					<span class="tw-attr-remaining-label">
						Remaining: <span id="tw-attr-remaining"><?php echo esc_html( $attr_pool - count( $attrs ) ); ?></span>
					</span>
				</p>

				<div class="tw-attr-grid">
					<?php foreach ( $attrs as $key => $attr ) : ?>
					<div class="tw-attr-row" data-attr="<?php echo esc_attr( $key ); ?>">
						<span class="tw-attr-icon"><?php echo $attr['icon']; ?></span>
						<div class="tw-attr-info">
							<h4><?php echo esc_html( $attr['label'] ); ?> <small><?php echo esc_html( $attr['sub'] ); ?></small></h4>
							<span><?php echo esc_html( $attr['desc'] ); ?></span>
						</div>
						<div class="tw-attr-stepper">
							<button type="button" class="tw-attr-btn tw-attr-minus" data-attr="<?php echo esc_attr( $key ); ?>" aria-label="Decrease <?php echo esc_attr( $attr['label'] ); ?>">−</button>
							<input type="number"
							       class="tw-attr-val"
							       name="attr_<?php echo esc_attr( $key ); ?>"
							       id="tw-attr-<?php echo esc_attr( $key ); ?>"
							       value="<?php echo esc_attr( $attr_min ); ?>"
							       min="<?php echo esc_attr( $attr_min ); ?>"
							       max="<?php echo esc_attr( $attr_max ); ?>"
							       readonly
							       aria-label="<?php echo esc_attr( $attr['label'] ); ?> value" />
							<button type="button" class="tw-attr-btn tw-attr-plus" data-attr="<?php echo esc_attr( $key ); ?>" aria-label="Increase <?php echo esc_attr( $attr['label'] ); ?>">+</button>
						</div>
						<!-- Visual pip bar -->
						<div class="tw-attr-pips">
							<?php for ( $p = 1; $p <= $attr_max; $p++ ) : ?>
								<span class="tw-pip<?php echo $p <= $attr_min ? ' active' : ''; ?>"
								      data-pip="<?php echo esc_attr( $p ); ?>"></span>
							<?php endfor; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>

				<!-- Hidden: total pool for JS validation -->
				<input type="hidden" id="tw-attr-pool" value="<?php echo esc_attr( $attr_pool ); ?>" />

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════════
			     STEP 5 — Node Binding
			     ═══════════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="5" data-phase="NODE BINDING" data-field="node_id">
				<h2>// NODE BINDING</h2>
				<p class="tw-question-text">
					Select the Node (world) this agent will be permanently synchronized to.
					<em>One agent · one world. This cannot be changed after deployment.</em>
				</p>

				<div class="tw-dynamic-grid" id="tw-node-grid">
					<div class="tw-loading-state">
						<span class="tw-loading-dot"></span>
						SCANNING AVAILABLE NODES…
					</div>
				</div>

				<p class="tw-helper-text">No worlds yet?
					<a href="<?php echo esc_url( home_url( '/create-world/' ) ); ?>" class="tw-link">Deploy a Node first &rarr;</a>
				</p>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════════
			     STEP 6 — Avatar
			     ═══════════════════════════════════════════════════════════════════ -->
			<div class="tw-step" data-step="6" data-phase="VISUAL SIGNATURE">
				<h2>// VISUAL SIGNATURE</h2>
				<p class="tw-question-text">Upload an operative portrait. Optional — skip to continue.</p>

				<div class="tw-upload-box" id="tw-avatar-drop">
					<input type="file" id="tw-char-avatar" name="avatar"
					       accept="image/jpeg,image/png,image/webp"
					       style="display:none;" />
					<div class="tw-upload-preview" id="tw-avatar-preview">
						<span class="tw-upload-icon">📷</span>
						<p>Drag &amp; drop or <button type="button" class="tw-upload-trigger tw-link-btn">browse files</button></p>
						<small>JPG · PNG · WEBP · max 2 MB</small>
					</div>
					<div class="tw-avatar-selected" id="tw-avatar-selected" style="display:none;">
						<img id="tw-avatar-img" src="" alt="Avatar preview" />
						<button type="button" class="tw-avatar-clear" id="tw-avatar-clear">✕ Remove</button>
					</div>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">REVIEW &rarr;</button>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════════
			     STEP 7 — Summary + Deploy
			     ═══════════════════════════════════════════════════════════════════ -->
			<div class="tw-step tw-step--summary" data-step="7" data-phase="SYSTEM REVIEW">
				<h2>// SYSTEM REVIEW</h2>
				<p class="tw-question-text">Verify operative parameters before synchronization. Edit any field to reconfigure.</p>

				<div class="tw-summary-grid">
					<div class="tw-summary-row" data-summary-field="character_name">
						<span class="tw-summary-key">AGENT_ID</span>
						<span class="tw-summary-val" id="tw-summary-character_name">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="1">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="pronouns">
						<span class="tw-summary-key">PRONOUNS</span>
						<span class="tw-summary-val" id="tw-summary-pronouns">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="1">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="backstory">
						<span class="tw-summary-key">BRIEF</span>
						<span class="tw-summary-val" id="tw-summary-backstory">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="1">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="race">
						<span class="tw-summary-key">RACE</span>
						<span class="tw-summary-val" id="tw-summary-race">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="2">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="class">
						<span class="tw-summary-key">CLASS</span>
						<span class="tw-summary-val" id="tw-summary-class">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="3">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="attrs">
						<span class="tw-summary-key">ATTRIBUTES</span>
						<span class="tw-summary-val" id="tw-summary-attrs">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="4">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="node_id">
						<span class="tw-summary-key">NODE</span>
						<span class="tw-summary-val" id="tw-summary-node_id">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="5">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="avatar">
						<span class="tw-summary-key">PORTRAIT</span>
						<span class="tw-summary-val" id="tw-summary-avatar">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="6">[ EDIT ]</button>
					</div>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-deploy" id="tw-char-submit">
						&#9658; SYNCHRONIZE AGENT
					</button>
				</div>

				<div class="tw-char-status" aria-live="polite"></div>
			</div>

		</div><!-- /#tw-char-creator-wrapper -->
		<?php
		$html = ob_get_clean() ?: '';
		return '<div class="neoweaver-screen">' . $html . '</div>';
	}
}
