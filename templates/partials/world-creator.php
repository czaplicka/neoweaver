<?php
/**
 * Partials: World Creator Wizard
 *
 * Expects $tw_data['world_steps'] from neoweaver_shortcode_world_creator().
 * Adds:
 *   - Progress bar above all steps (updated by JS)
 *   - Summary screen (last step) listing all choices with edit-jump buttons
 *
 * @var array $tw_data
 */
if ( ! isset( $tw_data['world_steps'] ) || ! is_array( $tw_data['world_steps'] ) ) {
	echo '<div class="tw-error">World creator config missing.</div>';
	return;
}

$world_steps = $tw_data['world_steps'];
$choice_count = count( $world_steps );
// Total steps: 1 (name/desc) + N choices + 1 (customize) + 1 (summary)
$total_steps = 1 + $choice_count + 1 + 1;
?>

<div id="tw-creator-wrapper" data-total-steps="<?php echo esc_attr( $total_steps ); ?>">

	<!-- ═══════════════════════════════════════════════════════════════════════════
	     PROGRESS BAR  — always visible, updated by JS
	     ═══════════════════════════════════════════════════════════════════════════ -->
	<div class="tw-progress-bar" aria-label="Node configuration progress">
		<div class="tw-progress-header">
			<span class="tw-progress-label">NODE_INIT<span class="tw-blink">_</span></span>
			<span class="tw-progress-counter"><span id="tw-step-current">1</span> / <?php echo esc_html( $total_steps ); ?></span>
		</div>
		<div class="tw-progress-track">
			<div class="tw-progress-fill" id="tw-progress-fill" style="width:<?php echo round( 100 / $total_steps ); ?>%"></div>
			<?php for ( $i = 1; $i <= $total_steps; $i++ ) : ?>
				<span class="tw-progress-tick<?php echo $i === 1 ? ' active' : ''; ?>" data-tick="<?php echo esc_attr( $i ); ?>"></span>
			<?php endfor; ?>
		</div>
		<div class="tw-progress-phase" id="tw-progress-phase">IDENTITY MATRIX</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════════
	     STATUS BAR — always in DOM, displays errors/info on all steps
	     ═══════════════════════════════════════════════════════════════════════════ -->
	<div class="tw-world-status" aria-live="polite"></div>

	<!-- ═══════════════════════════════════════════════════════════════════════════
	     STEP 1 — Name & Description
	     ═══════════════════════════════════════════════════════════════════════════ -->
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
				placeholder="Describe the world's atmosphere, lore fragments, key factions…" required></textarea>
		</label>

		<div class="tw-nav-row">
			<span></span>
			<button type="button" class="tw-btn-nav" id="tw-step1-next">NEXT &rarr;</button>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════════
	     STEPS 2–N+1 — Choice steps
	     ═══════════════════════════════════════════════════════════════════════════ -->
	<?php
	$step_num = 1;
	foreach ( $world_steps as $step_index => $step_def ) :
		$step_title = $step_def[0] ?? '';
		$label      = $step_def[1] ?? '';
		$options    = $step_def[2] ?? [];
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

	<!-- ═══════════════════════════════════════════════════════════════════════════
	     STEP N+2 — Customize
	     ═══════════════════════════════════════════════════════════════════════════ -->
	<?php $customize_step = $step_num + 1; ?>
	<div class="tw-step" data-step="<?php echo esc_attr( $customize_step ); ?>" data-phase="CUSTOM DIRECTIVES">
		<h2>// CUSTOM DIRECTIVES</h2>
		<p class="tw-question-text">Optional: add lore, constraints or AI GM directives.</p>

		<label>
			<span>Custom notes</span>
			<textarea name="customize" rows="5"
				placeholder="Optional flavour, constraints, themes…"></textarea>
		</label>

		<div class="tw-nav-row">
			<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
			<button type="button" class="tw-btn-nav tw-btn-next">REVIEW &rarr;</button>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════════
	     STEP N+3 — Summary + Deploy
	     ═══════════════════════════════════════════════════════════════════════════ -->
	<?php $summary_step = $customize_step + 1; ?>
	<div class="tw-step tw-step--summary" data-step="<?php echo esc_attr( $summary_step ); ?>" data-phase="SYSTEM REVIEW">

		<h2>// SYSTEM REVIEW</h2>
		<p class="tw-question-text">Verify node parameters before uplink. Edit any field to reconfigure.</p>

		<div class="tw-summary-grid">

			<!-- Static fields -->
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

			<!-- Dynamic choice fields — populated by JS -->
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

			<!-- Customize -->
			<div class="tw-summary-row" data-summary-field="customize">
				<span class="tw-summary-key">GM_DIRECTIVES</span>
				<span class="tw-summary-val" id="tw-summary-customize">&mdash;</span>
				<button type="button" class="tw-summary-edit" data-goto="<?php echo esc_attr( $customize_step ); ?>">[ EDIT ]</button>
			</div>

		</div><!-- /.tw-summary-grid -->

		<div class="tw-nav-row">
			<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
			<button type="button" class="tw-btn-nav tw-btn-deploy" id="tw-world-submit">&#9658; DEPLOY NODE</button>
		</div>

	</div>

</div><!-- /#tw-creator-wrapper -->
