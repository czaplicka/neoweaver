<?php
/**
 * shortcode-character-creator.php
 *
 * Shortcode: [taleweaver_character_creator]
 * Renders the 11-step Field Agent creation wizard.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'neoweaver_shortcode_character_creator' ) ) {
	function neoweaver_shortcode_character_creator(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="neoweaver-screen"><div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div></div>';
		}

		// Spinner CSS for global modal.
		$spinner_css = defined( 'NEOWEAVER_PLUGIN_DIR' ) ? NEOWEAVER_PLUGIN_DIR . 'assets/css/tw-node-spinner.css' : '';
		if ( $spinner_css && file_exists( $spinner_css ) ) {
			wp_enqueue_style(
				'neoweaver-node-spinner',
				NEOWEAVER_PLUGIN_URL . 'assets/css/tw-node-spinner.css',
				[],
				(string) filemtime( $spinner_css )
			);
		}

		$attrs = [
			'body'   => [
				'label' => 'BODY',
				'sub'   => 'STR / CON',
				'desc'  => 'Brute force, health pool, heavy lifting, physical endurance.',
				'icon'  => '⬢',
			],
			'reflex' => [
				'label' => 'REFLEX',
				'sub'   => 'DEX',
				'desc'  => 'Speed, evasion, precision aiming, reaction time.',
				'icon'  => '✦',
			],
			'mind'   => [
				'label' => 'MIND',
				'sub'   => 'INT / WIS',
				'desc'  => 'Logic, repair, investigation, hacking, situational awareness.',
				'icon'  => '◈',
			],
			'spirit' => [
				'label' => 'SPIRIT',
				'sub'   => 'CHA / WILL',
				'desc'  => 'Magic power, persuasion, willpower, social influence.',
				'icon'  => '✺',
			],
		];

		$pronoun_options = [
			'she/her'   => 'she/her',
			'he/him'    => 'he/him',
			'they/them' => 'they/them',
			'xe/xem'    => 'xe/xem',
			'custom'    => 'custom',
		];

		$attr_pool   = 12;
		$attr_min    = 1;
		$attr_max    = 5;
		$total_steps = 11;

		$attr_presets = [
			[
				'key'    => 'body-builder',
				'label'  => 'BODY BUILDER',
				'values' => [ 5, 3, 2, 2 ],
			],
			[
				'key'    => 'gunslinger',
				'label'  => 'GUNSLINGER',
				'values' => [ 2, 5, 2, 3 ],
			],
			[
				'key'    => 'genius',
				'label'  => 'GENIUS',
				'values' => [ 2, 2, 5, 3 ],
			],
			[
				'key'    => 'warlock',
				'label'  => 'WARLOCK',
				'values' => [ 2, 2, 3, 5 ],
			],
			[
				'key'    => 'balanced',
				'label'  => 'BALANCED',
				'values' => [ 3, 3, 3, 3 ],
			],
		];

		ob_start();
		?>
		<div id="tw-char-creator-wrapper" data-total-steps="<?php echo esc_attr( $total_steps ); ?>">

			<div class="tw-progress-bar" aria-label="Agent configuration progress">
				<div class="tw-progress-header">
					<span class="tw-progress-label">AGENT_INIT <span class="tw-blink"></span></span>
					<span class="tw-progress-counter"><span id="tw-char-step-current">1</span> / <?php echo esc_html( $total_steps ); ?></span>
				</div>
				<div class="tw-progress-track">
					<div class="tw-progress-fill" id="tw-char-progress-fill" style="width: <?php echo esc_attr( round( 100 / $total_steps ) ); ?>%;"></div>
					<?php for ( $i = 1; $i <= $total_steps; $i++ ) : ?>
						<span class="tw-progress-tick <?php echo 1 === $i ? 'active' : ''; ?>" data-tick="<?php echo esc_attr( $i ); ?>"></span>
					<?php endfor; ?>
				</div>
				<div class="tw-progress-phase" id="tw-char-progress-phase">IDENTITY MATRIX</div>
			</div>

			<div class="tw-step active" data-step="1" data-phase="IDENTITY MATRIX">
				<h2>INITIALIZE AGENT</h2>
				<p class="tw-question-text">Define the operative identity before synchronization.</p>

				<label class="tw-field-label">
					<span>Agent Designation <span class="tw-required">*</span></span>
					<input
						type="text"
						id="tw-char-name"
						name="character_name"
						placeholder="e.g. Ghost-7, Mara Voss, The Architect"
						maxlength="80"
						required
					>
				</label>

				<fieldset class="tw-pronoun-fieldset">
					<legend class="tw-field-label">Pronouns</legend>
					<div class="tw-pronoun-options">
						<?php foreach ( $pronoun_options as $value => $label ) : ?>
							<label class="tw-pronoun-option">
								<input
									type="radio"
									name="pronouns"
									id="tw-pronoun-<?php echo esc_attr( sanitize_title( $value ) ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									class="tw-pronoun-radio"
								>
								<span class="tw-pronoun-label"><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<input
						type="text"
						id="tw-char-pronouns-custom"
						name="pronouns_custom"
						placeholder="e.g. ze/zir, fae/faer"
						maxlength="40"
						style="display:none; margin-top:12px;"
					>
				</fieldset>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<span></span>
					<button type="button" class="tw-btn-nav tw-btn-next" id="tw-char-step1-next">NEXT &rarr;</button>
				</div>
			</div>

			<div class="tw-step" data-step="2" data-phase="RACE PROTOCOL">
				<h2>RACE PROTOCOL</h2>
				<p class="tw-question-text">Select the operative's biological or synthetic origin.</p>

				<div class="tw-dynamic-grid tw-race-grid" id="tw-race-grid"></div>

				<div id="tw-subrace-section" class="tw-subrace-section" hidden>
					<h3 class="tw-subrace-heading">SELECT SUBRACE</h3>
					<div class="tw-dynamic-grid tw-subrace-grid" id="tw-subrace-grid"></div>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<div class="tw-step" data-step="3" data-phase="CLASS MATRIX">
				<h2>CLASS MATRIX</h2>
				<p class="tw-question-text">Select the operative's combat and skill archetype.</p>

				<div class="tw-dynamic-grid" id="tw-class-grid">
					<div class="tw-loading-state"><span class="tw-loading-dot"></span>FETCHING CLASS DATA FROM NODE…</div>
				</div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<div class="tw-step" data-step="4" data-phase="BIOMETRIC CALIBRATION">
				<h2>BIOMETRIC CALIBRATION</h2>
				<p class="tw-question-text">
					Distribute <strong id="tw-attr-pool-display"><?php echo esc_html( $attr_pool ); ?></strong> attribute points across four core systems.
					Each attribute starts at <?php echo esc_html( $attr_min ); ?> and caps at <?php echo esc_html( $attr_max ); ?>.
					<span class="tw-attr-remaining-label">
						Remaining
						<span id="tw-attr-remaining">
							<?php echo esc_html( $attr_pool - count( $attrs ) ); ?>
						</span>
					</span>
				</p>

				<div class="tw-attr-presets" aria-label="Quick-build presets">
					<span class="tw-attr-presets__label">QUICK BUILD</span>
					<?php foreach ( $attr_presets as $preset ) : ?>
						<button
							type="button"
							class="tw-attr-preset-btn"
							data-preset="<?php echo esc_attr( $preset['key'] ); ?>"
							data-body="<?php echo esc_attr( $preset['values'][0] ); ?>"
							data-reflex="<?php echo esc_attr( $preset['values'][1] ); ?>"
							data-mind="<?php echo esc_attr( $preset['values'][2] ); ?>"
							data-spirit="<?php echo esc_attr( $preset['values'][3] ); ?>"
							aria-label="<?php echo esc_attr( $preset['label'] ); ?>"
						>
							<?php echo esc_html( $preset['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="tw-attr-grid">
					<?php foreach ( $attrs as $key => $attr ) : ?>
						<div class="tw-attr-row" data-attr="<?php echo esc_attr( $key ); ?>">
							<span class="tw-attr-icon"><?php echo esc_html( $attr['icon'] ); ?></span>
							<div class="tw-attr-info">
								<h4>
									<?php echo esc_html( $attr['label'] ); ?>
									<small><?php echo esc_html( $attr['sub'] ); ?></small>
								</h4>
								<span><?php echo esc_html( $attr['desc'] ); ?></span>
							</div>
							<div class="tw-attr-controls">
								<div class="tw-attr-stepper">
									<button
										type="button"
										class="tw-attr-btn tw-attr-minus"
										data-attr="<?php echo esc_attr( $key ); ?>"
										aria-label="Decrease <?php echo esc_attr( $attr['label'] ); ?>"
									>−</button>
									<input
										type="number"
										class="tw-attr-val"
										name="attr_<?php echo esc_attr( $key ); ?>"
										id="tw-attr-<?php echo esc_attr( $key ); ?>"
										value="<?php echo esc_attr( $attr_min ); ?>"
										min="<?php echo esc_attr( $attr_min ); ?>"
										max="<?php echo esc_attr( $attr_max ); ?>"
										readonly
										aria-label="<?php echo esc_attr( $attr['label'] ); ?> value"
									>
									<button
										type="button"
										class="tw-attr-btn tw-attr-plus"
										data-attr="<?php echo esc_attr( $key ); ?>"
										aria-label="Increase <?php echo esc_attr( $attr['label'] ); ?>"
									>+</button>
								</div>
								<div class="tw-attr-pips">
									<?php for ( $p = 1; $p <= $attr_max; $p++ ) : ?>
										<span
											class="tw-pip <?php echo $p <= $attr_min ? 'active' : ''; ?>"
											data-pip="<?php echo esc_attr( $p ); ?>"
										></span>
									<?php endfor; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<input type="hidden" id="tw-attr-pool" value="<?php echo esc_attr( $attr_pool ); ?>">

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<div class="tw-step" data-step="5" data-phase="SKILL SELECTION">
				<h2>SKILL SELECTION</h2>
				<p class="tw-question-text">Choose active skills unlocked for this operative class.</p>

				<div class="tw-skill-counter" id="tw-skill-counter">0 / 5 skills</div>
				<div id="tw-skill-grid" class="tw-skill-grid"></div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<div class="tw-step" data-step="6" data-phase="STARTING PACKAGE">
				<h2>STARTING PACKAGE</h2>
				<p class="tw-question-text">Select the initial equipment loadout available to the chosen class.</p>

				<div class="tw-dynamic-grid" id="tw-package-grid"></div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<div class="tw-step" data-step="7" data-phase="DATA ORIGIN">
				<h2>DATA ORIGIN</h2>
				<p class="tw-question-text">Where was your consciousness first stabilized?</p>

				<div class="tw-dynamic-grid" id="tw-origin-grid"></div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<div class="tw-step" data-step="8" data-phase="PREVIOUS OPERATION">
				<h2>PREVIOUS OPERATION</h2>
				<p class="tw-question-text">What was your primary function before current Deployment?</p>

				<div class="tw-dynamic-grid" id="tw-operation-grid"></div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<div class="tw-step" data-step="9" data-phase="SYNCHRONIZATION CRISIS">
				<h2>SYNCHRONIZATION CRISIS</h2>
				<p class="tw-question-text">How did you react to the first contact with Entropy (The Fray)?</p>

				<div class="tw-dynamic-grid" id="tw-crisis-grid"></div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<button type="button" class="tw-btn-nav tw-btn-next">NEXT &rarr;</button>
				</div>
			</div>

			<div class="tw-step" data-step="10" data-phase="VISUAL SIGNATURE">
				<h2>VISUAL SIGNATURE</h2>
				<p class="tw-question-text">Upload an operative portrait and add a manual bio. Both are optional.</p>

				<div class="tw-upload-box" id="tw-avatar-drop">
					<input
						type="file"
						id="tw-char-avatar"
						name="avatar"
						accept="image/jpeg,image/png,image/webp"
						style="display:none;"
					>
					<div class="tw-upload-preview" id="tw-avatar-preview">
						<span class="tw-upload-icon">⬒</span>
						<p>
							Drag &amp; drop or
							<button type="button" class="tw-upload-trigger tw-link-btn">browse files</button>
						</p>
						<small>JPG / PNG / WEBP, max 2 MB</small>
					</div>
					<div class="tw-avatar-selected" id="tw-avatar-selected" style="display:none;">
						<img id="tw-avatar-img" src="" alt="Avatar preview">
						<button type="button" class="tw-avatar-clear" id="tw-avatar-clear">Remove</button>
					</div>
				</div>

				<label class="tw-field-label" style="margin-top:24px;">
					<span>Manual Bio</span>
					<textarea
						id="tw-char-bio"
						name="bio"
						rows="5"
						placeholder="Write the in-world bio for this Field Agent."
					></textarea>
				</label>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn-nav tw-btn-review-return" data-review-return hidden>REVIEW &rarr;</button>
					<button type="button" class="tw-btn-nav tw-btn-next">REVIEW &rarr;</button>
				</div>
			</div>

			<div class="tw-step tw-step--summary" data-step="11" data-phase="SYSTEM REVIEW">
				<h2>SYSTEM REVIEW</h2>
				<p class="tw-question-text">Verify operative parameters before synchronization.</p>

				<div class="tw-summary-grid">
					<div class="tw-summary-row" data-summary-field="character_name">
						<span class="tw-summary-key">AGENT ID</span>
						<span class="tw-summary-val" id="tw-summary-character-name">—</span>
						<button type="button" class="tw-summary-edit" data-goto="1">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="pronouns">
						<span class="tw-summary-key">PRONOUNS</span>
						<span class="tw-summary-val" id="tw-summary-pronouns">—</span>
						<button type="button" class="tw-summary-edit" data-goto="1">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="race">
						<span class="tw-summary-key">RACE</span>
						<span class="tw-summary-val" id="tw-summary-race">—</span>
						<button type="button" class="tw-summary-edit" data-goto="2">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="class">
						<span class="tw-summary-key">CLASS</span>
						<span class="tw-summary-val" id="tw-summary-class">—</span>
						<button type="button" class="tw-summary-edit" data-goto="3">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="attrs">
						<span class="tw-summary-key">ATTRIBUTES</span>
						<span class="tw-summary-val" id="tw-summary-attrs">—</span>
						<button type="button" class="tw-summary-edit" data-goto="4">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="skills">
						<span class="tw-summary-key">SKILLS</span>
						<span class="tw-summary-val" id="tw-summary-skills">—</span>
						<button type="button" class="tw-summary-edit" data-goto="5">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="package">
						<span class="tw-summary-key">PACKAGE</span>
						<span class="tw-summary-val" id="tw-summary-package">—</span>
						<button type="button" class="tw-summary-edit" data-goto="6">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="origin">
						<span class="tw-summary-key">DATA ORIGIN</span>
						<span class="tw-summary-val" id="tw-summary-origin">—</span>
						<button type="button" class="tw-summary-edit" data-goto="7">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="operation">
						<span class="tw-summary-key">PREVIOUS OP</span>
						<span class="tw-summary-val" id="tw-summary-operation">—</span>
						<button type="button" class="tw-summary-edit" data-goto="8">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="crisis">
						<span class="tw-summary-key">SYNC CRISIS</span>
						<span class="tw-summary-val" id="tw-summary-crisis">—</span>
						<button type="button" class="tw-summary-edit" data-goto="9">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="tag_bundle">
						<span class="tw-summary-key">BACKSTORY TAGS</span>
						<span class="tw-summary-val" id="tw-summary-tag-bundle">—</span>
						<button type="button" class="tw-summary-edit" data-goto="9">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="avatar">
						<span class="tw-summary-key">PORTRAIT</span>
						<span class="tw-summary-val" id="tw-summary-avatar">—</span>
						<button type="button" class="tw-summary-edit" data-goto="10">EDIT</button>
					</div>
					<div class="tw-summary-row" data-summary-field="bio">
						<span class="tw-summary-key">MANUAL BIO</span>
						<span class="tw-summary-val" id="tw-summary-bio">—</span>
						<button type="button" class="tw-summary-edit" data-goto="10">EDIT</button>
					</div>
				</div>

				<div id="tw-char-status-msg" class="tw-char-status"></div>

				<div class="tw-nav-row">
					<button type="button" class="tw-btn-nav tw-btn-prev">&larr; BACK</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-deploy" id="tw-char-submit">⌘ SYNCHRONIZE AGENT</button>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'taleweaver_character_creator', 'neoweaver_shortcode_character_creator' );
}
