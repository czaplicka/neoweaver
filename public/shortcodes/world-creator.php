<?php
/**
 * NeoWeaver World Creator shortcode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'neoweaver_shortcode_world_creator' ) ) {
	function neoweaver_shortcode_world_creator(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="neoweaver-screen"><div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div></div>';
		}

		$nonce       = wp_create_nonce( 'tw_world_nonce' );
		$nodes_url   = home_url( '/nodes/' );
		$uploads     = wp_upload_dir();
		$uploads_url = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';

		if ( function_exists( 'tw_enqueue_world_creator_assets' ) ) {
			tw_enqueue_world_creator_assets(
				array(
					'nonce'      => $nonce,
					'restNonce'  => wp_create_nonce( 'wp_rest' ),
					'nodesUrl'   => $nodes_url,
					'uploadsUrl' => $uploads_url,
				)
			);
		}

		$world_steps = array(
			1 => array( 'WORLD_SIZE',    'Define expansion magnitude',    array( array( 'Local Node', 'A single, dense micro-world.' ), array( 'Few Nodes', 'A vast region.' ), array( 'Multi Nodes', 'Full nodes simulation.' ), array( 'World', 'Multiple systems.' ), array( 'Infinite', 'Infinite reality stream.' ) ),    'size' ),
			2 => array( 'NODE_ECONOMY',  'Resource availability',         array( array( 'Frayed', 'Survival is a miracle.' ), array( 'Scarcity', 'Basic scavenge economy.' ), array( 'Balanced', 'Stable commerce.' ), array( 'Wealthy', 'High consumerism.' ), array( 'Abundant', 'Digital abundance.' ) ),         'wealth' ),
			3 => array( 'ENTROPY_DANGER','Entropy & Threat Rate',          array( array( 'Coherent', 'Stable world.' ), array( 'Stable', 'Manageable threats.' ), array( 'Unstable', 'Standard risks.' ), array( 'Critical', 'The Fray is strong.' ), array( 'Catastrophic', 'Systemic collapse.' ) ),          'difficulty' ),
			4 => array( 'NODE_MAGIC',    'Weave Permeability',             array( array( 'None', 'Strict logic.' ), array( 'Glitched', 'Rare anomalies.' ), array( 'Standard', 'Standard utility.' ), array( 'High', 'Reality is soft.' ), array( 'Extreme', 'Chaos rules.' ) ),             'magic' ),
			5 => array( 'NODE_GODS',     'Higher Protocols / Admins',      array( array( 'Absent', 'No entities.' ), array( 'Echoes', 'Forgotten Admins.' ), array( 'Observers', 'Silent code.' ), array( 'Active', 'Demanding data.' ), array( 'Manifested', 'God-AI active.' ) ),      'gods' ),
			6 => array( 'NODE_TECH',     'Technological Anchor',           array( array( 'None existant', 'Almost analog.' ), array( 'Somewhat', 'Tech can be found' ), array( 'Normal', 'Easy to find a terminal' ), array( 'Popular', 'Sentient AI. Androids' ), array( 'Transcendent', 'Post-human. Apocalyptic future' ) ),           'technology' ),
			7 => array( 'NODE_SOCIAL',   'Race interaction',               array( array( 'Hostile', 'Tribal survival.' ), array( 'Strained', 'Faction tension.' ), array( 'Pragmatic', 'Uneasy peace.' ), array( 'Integrated', 'Common goals.' ), array( 'Unified', 'Hive-mind.' ) ),               'relations' ),
			8 => array( 'NODE_MORALITY', 'Ethical Framework',              array( array( 'Chaotic', 'Fittest survives.' ), array( 'Gray', 'Ambiguity.' ), array( 'Lawful', 'Strict codes.' ) ),              'moral' ),
		);

		$choice_count = count( $world_steps );
		// Total: 1 (name/desc) + N choices + 1 (customize) + 1 (summary)
		$total_steps    = 1 + $choice_count + 1 + 1;
		$customize_step = 1 + $choice_count + 1;
		$summary_step   = $customize_step + 1;

		ob_start();
		?>
		<div class="neoweaver-screen">
		<div id="tw-creator-wrapper" data-total-steps="<?php echo esc_attr( $total_steps ); ?>">

			<!-- PROGRESS BAR -->
			<div class="tw-progress-bar" aria-label="Node configuration progress">
				<div class="tw-progress-header">
					<span class="tw-progress-label">NODE_INIT<span class="tw-blink">_</span></span>
					<span class="tw-progress-counter"><span id="tw-step-current">1</span> / <?php echo esc_html( $total_steps ); ?></span>
				</div>
				<div class="tw-progress-track">
					<div class="tw-progress-fill" id="tw-progress-fill" style="width:<?php echo esc_attr( round( 100 / $total_steps ) ); ?>%"></div>
					<?php for ( $i = 1; $i <= $total_steps; $i++ ) : ?>
						<span class="tw-progress-tick<?php echo 1 === $i ? ' active' : ''; ?>" data-tick="<?php echo esc_attr( $i ); ?>"></span>
					<?php endfor; ?>
				</div>
				<div class="tw-progress-phase" id="tw-progress-phase">IDENTITY MATRIX</div>
			</div>

			<!-- STATUS BAR -->
			<div class="tw-world-status" aria-live="polite"></div>

			<!-- STEP 1: Name & Description -->
			<div class="tw-step active" data-step="1" data-phase="IDENTITY MATRIX">
				<h2>// INITIALIZE NODE</h2>
				<p class="tw-question-text">Define the node identity before deploying.</p>

				<label>
					<span>Node name</span>
					<input type="text" id="tw-world-name" name="name" placeholder="e.g. Fractured Spire" required />
				</label>

				<br><br>

				<label>
					<span>Description</span>
					<textarea id="tw-world-description" name="description" rows="5"
						placeholder="Describe the world&#39;s atmosphere, lore fragments, key factions&#8230;" required></textarea>
				</label>

				<div class="tw-nav-row">
					<span></span>
					<button type="button" class="tw-btn-nav" id="tw-step1-next">NEXT &rarr;</button>
				</div>
			</div>

			<!-- STEPS 2–N+1: Choice steps -->
			<?php
			$step_num = 1;
			foreach ( $world_steps as $step_def ) :
				$step_title = $step_def[0] ?? '';
				$label      = $step_def[1] ?? '';
				$options    = $step_def[2] ?? array();
				$field_key  = $step_def[3] ?? '';
				$step_num++;
				?>
				<div class="tw-step"
				     data-step="<?php echo esc_attr( $step_num ); ?>"
				     data-field="<?php echo esc_attr( $field_key ); ?>"
				     data-phase="<?php echo esc_attr( strtoupper( $step_title ) ); ?>">

					<h2>// <?php echo esc_html( strtoupper( $step_title ) ); ?></h2>
					<p class="tw-question-text"><?php echo esc_html( $label ); ?></p>

					<div class="tw-radio-grid" data-field="<?php echo esc_attr( $field_key ); ?>">
						<?php foreach ( $options as $idx => $opt ) :
							$opt_label = $opt[0] ?? '';
							$opt_desc  = $opt[1] ?? '';
							$value     = $idx + 1;
							$input_id  = 'tw-' . esc_attr( $field_key ) . '-' . $value;
							?>
							<label class="tw-card-label" for="<?php echo esc_attr( $input_id ); ?>">
								<input
									type="radio"
									name="<?php echo esc_attr( $field_key ); ?>"
									id="<?php echo esc_attr( $input_id ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									required
								/>
								<div class="tw-card-visual">
									<strong><?php echo esc_html( $opt_label ); ?></strong>
									<span><?php echo esc_html( $opt_desc ); ?></span>
								</div>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
						<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
					</div>
				</div>
			<?php endforeach; ?>

			<!-- STEP N+2: Customize -->
			<div class="tw-step" data-step="<?php echo esc_attr( $customize_step ); ?>" data-phase="CUSTOM DIRECTIVES">
				<h2>// CUSTOM DIRECTIVES</h2>
				<p class="tw-question-text">Optional: add lore, constraints or AI GM directives.</p>

				<label>
					<span>Custom notes</span>
					<textarea name="customize" rows="5"
						placeholder="Optional flavour, constraints, themes&#8230;"></textarea>
				</label>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-next">REVIEW &rarr;</button>
				</div>
			</div>

			<!-- STEP N+3: Summary + Deploy -->
			<div class="tw-step tw-step--summary" data-step="<?php echo esc_attr( $summary_step ); ?>" data-phase="SYSTEM REVIEW">
				<h2>// SYSTEM REVIEW</h2>
				<p class="tw-question-text">Verify node parameters before uplink. Edit any field to reconfigure.</p>

				<div class="tw-summary-grid">
					<div class="tw-summary-row" data-summary-field="name">
						<span class="tw-summary-key">NODE_ID</span>
						<span class="tw-summary-val" id="tw-summary-name">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="1">[ EDIT ]</button>
					</div>
					<div class="tw-summary-row" data-summary-field="description">
						<span class="tw-summary-key">LORE_FRAG</span>
						<span class="tw-summary-val" id="tw-summary-desc">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="1">[ EDIT ]</button>
					</div>

					<?php
					$s = 1;
					foreach ( $world_steps as $step_def ) :
						$s++;
						$field_key  = $step_def[3] ?? '';
						$step_title = $step_def[0] ?? $field_key;
						?>
						<div class="tw-summary-row" data-summary-field="<?php echo esc_attr( $field_key ); ?>">
							<span class="tw-summary-key"><?php echo esc_html( strtoupper( $step_title ) ); ?></span>
							<span class="tw-summary-val" id="tw-summary-<?php echo esc_attr( $field_key ); ?>">&mdash;</span>
							<button type="button" class="tw-summary-edit" data-goto="<?php echo esc_attr( $s ); ?>">[ EDIT ]</button>
						</div>
					<?php endforeach; ?>

					<div class="tw-summary-row" data-summary-field="customize">
						<span class="tw-summary-key">GM_DIRECTIVES</span>
						<span class="tw-summary-val" id="tw-summary-customize">&mdash;</span>
						<button type="button" class="tw-summary-edit" data-goto="<?php echo esc_attr( $customize_step ); ?>">[ EDIT ]</button>
					</div>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-deploy" id="tw-world-submit">&#9658; DEPLOY NODE</button>
				</div>
			</div>

		</div><!-- /#tw-creator-wrapper -->
		</div><!-- /.neoweaver-screen -->
		<?php
		return (string) ob_get_clean();
	}

	add_shortcode( 'tw_world_creator', 'neoweaver_shortcode_world_creator' );
}
