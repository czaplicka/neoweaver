<?php
/**
 * Partials: World Creator Wizard
 *
 * Expects $tw_data['world_steps'] from neoweaver_shortcode_world_creator().
 * Steps start at array index 3 (step 3 = first choice step).
 * Steps 1-2 are the name/description fields rendered separately.
 *
 * @var array $tw_data
 */
if ( ! isset( $tw_data['world_steps'] ) || ! is_array( $tw_data['world_steps'] ) ) {
	echo '<div class="tw-error">World creator config missing.</div>';
	return;
}

$world_steps  = $tw_data['world_steps'];
$total_steps  = 2 + count( $world_steps ) + 1; // name+desc | choices | customize+submit
?>

<div id="tw-creator-wrapper">

	<!-- STEP 1: Name & Description -->
	<div class="tw-step active" data-step="1">
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

	<?php
	$step_num = 2;
	foreach ( $world_steps as $step_index => $step_def ) :
		$label     = $step_def[1] ?? '';
		$options   = $step_def[2] ?? [];
		$field_key = $step_def[3] ?? '';
		$step_num++;
		?>
		<!-- STEP <?php echo esc_html( $step_num ); ?>: <?php echo esc_html( $label ); ?> -->
		<div class="tw-step" data-step="<?php echo esc_attr( $step_num ); ?>">
			<h2>// <?php echo esc_html( strtoupper( $step_def[0] ?? '' ) ); ?></h2>
			<p class="tw-question-text"><?php echo esc_html( $label ); ?></p>

			<div class="tw-radio-grid">
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

	<!-- LAST STEP: Customize + Submit -->
	<?php $last_step = $step_num + 1; ?>
	<div class="tw-step" data-step="<?php echo esc_attr( $last_step ); ?>">
		<h2>// FINALIZE NODE</h2>
		<p class="tw-question-text">Optional: add custom lore, constraints or AI GM directives.</p>

		<label>
			<span>Custom notes</span>
			<textarea name="customize" rows="5"
				placeholder="Optional flavour, constraints, themes…"></textarea>
		</label>

		<div class="tw-nav-row">
			<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
			<button type="button" class="tw-btn-nav" id="tw-world-submit">DEPLOY NODE &rarr;</button>
		</div>

		<div class="tw-world-status" aria-live="polite" style="margin-top:20px;"></div>
	</div>

</div><!-- /#tw-creator-wrapper -->
