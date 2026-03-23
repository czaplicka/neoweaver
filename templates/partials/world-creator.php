<?php
/**
 * Partials: World Creator Wizard
 *
 * Oczekuje $tw_data['world_steps'] zdefiniowanego w neoweaver_shortcode_world_creator().
 *
 * @var array $tw_data
 */
if ( ! isset( $tw_data['world_steps'] ) || ! is_array( $tw_data['world_steps'] ) ) {
	echo '<div class="tw-error">World creator config missing.</div>';
	return;
}

$world_steps = $tw_data['world_steps'];
?>

<div id="tw-world-creator-container" class="tw-world-creator">
	<form id="tw-world-creator-form">
		<div class="tw-world-basic">
			<label>
				<span>Node name</span>
				<input type="text" name="name" required />
			</label>

			<label>
				<span>Description</span>
				<textarea name="description" rows="4" required></textarea>
			</label>
		</div>

		<div class="tw-world-steps">
			<?php foreach ( $world_steps as $step_index => $step_def ) :
				// [ CODE, LABEL, OPTIONS, FIELD_KEY ]
				$code      = $step_def[0] ?? '';
				$label     = $step_def[1] ?? '';
				$options   = $step_def[2] ?? [];
				$field_key = $step_def[3] ?? '';
				?>
				<div class="tw-world-step" data-step="<?php echo esc_attr( $step_index ); ?>">
					<h3><?php echo esc_html( $label ); ?></h3>

					<div class="tw-world-step-options">
						<?php foreach ( $options as $idx => $opt ) :
							$opt_label = $opt[0] ?? '';
							$opt_desc  = $opt[1] ?? '';
							$value     = $idx + 1; // 1–5, pasuje do DB
							$input_id  = 'tw-' . esc_attr( $field_key ) . '-' . $value;
							?>
							<label class="tw-world-step-option" for="<?php echo esc_attr( $input_id ); ?>">
								<input
									type="radio"
									name="<?php echo esc_attr( $field_key ); ?>"
									id="<?php echo esc_attr( $input_id ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									required
								/>
								<span class="tw-option-title"><?php echo esc_html( $opt_label ); ?></span>
								<span class="tw-option-desc"><?php echo esc_html( $opt_desc ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="tw-world-customize">
			<label>
				<span>Custom notes / tags for the AI GM</span>
				<textarea name="customize" rows="4" placeholder="Optional flavour, constraints, themes…"></textarea>
			</label>
		</div>

		<div class="tw-world-actions">
			<button type="submit" class="tw-btn tw-btn-primary">
				Create Node
			</button>
		</div>

		<div class="tw-world-status" aria-live="polite"></div>
	</form>
</div>
