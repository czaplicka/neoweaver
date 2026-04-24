<?php
/**
 * NeoWeaver Character Creator Shortcode
 * Full renderer for [taleweaver_character_creator]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register assets
 */
if ( ! function_exists( 'neoweaver_register_character_creator_assets' ) ) {
	function neoweaver_register_character_creator_assets(): void {
		$base_url = plugin_dir_url( __FILE__ );

		wp_register_style(
			'neoweaver-character-creator',
			$base_url . '../assets/css/tw-character-creator.css',
			array(),
			'4.0.0'
		);

		wp_register_script(
			'neoweaver-character-creator',
			$base_url . '../assets/js/tw-character-creator.js',
			array(),
			'4.0.0',
			true
		);

		$uploads = wp_upload_dir();

		wp_localize_script(
			'neoweaver-character-creator',
			'twCharCreatorConfig',
			array(
				'ajaxurl'       => admin_url( 'admin-ajax.php' ),
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'neoweaver_nonce' ),
				'site_base'     => home_url(),
				'uploads_base'  => trailingslashit( $uploads['baseurl'] ),
				'avatar_gallery'=> array(
					array(
						'id'   => 'avatar-1',
						'name' => 'Avatar',
						'url'  => trailingslashit( $uploads['baseurl'] ) . 'Avatar.svg',
					),
					array(
						'id'   => 'avatar-2',
						'name' => 'Avatar 2',
						'url'  => trailingslashit( $uploads['baseurl'] ) . 'Avatar-1.svg',
					),
				),
			)
		);
	}
	add_action( 'wp_enqueue_scripts', 'neoweaver_register_character_creator_assets' );
}

/**
 * Shortcode renderer
 */
if ( ! function_exists( 'neoweaver_shortcode_character_creator' ) ) {
	function neoweaver_shortcode_character_creator(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="tw-login-required">You must be logged in to create a character.</div>';
		}

		wp_enqueue_style( 'neoweaver-character-creator' );
		wp_enqueue_script( 'neoweaver-character-creator' );

		ob_start();
		?>
		<div id="tw-char-creator-wrapper" class="tw-char-creator">

			<style>
				#tw-char-creator-wrapper .tw-custom-pronouns-field[hidden]{display:none!important}
				#tw-char-creator-wrapper .tw-step{display:none}
				#tw-char-creator-wrapper .tw-step.active{display:block}
				#tw-char-creator-wrapper .tw-subrace-section[hidden]{display:none!important}
				#tw-char-creator-wrapper .tw-attr-presets{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 18px}
				#tw-char-creator-wrapper .tw-preset-btn{
					background:rgba(173,255,0,.08);
					border:1px solid rgba(173,255,0,.24);
					color:#d8ff7a;
					padding:10px 14px;
					border-radius:999px;
					cursor:pointer;
					font:inherit;
					transition:.2s ease;
				}
				#tw-char-creator-wrapper .tw-preset-btn:hover,
				#tw-char-creator-wrapper .tw-preset-btn.active{
					background:rgba(173,255,0,.18);
					border-color:rgba(173,255,0,.7);
					box-shadow:0 0 0 2px rgba(173,255,0,.14);
				}
				#tw-char-creator-wrapper .tw-status[hidden]{display:none!important}
				#tw-char-creator-wrapper .tw-step-error[hidden]{display:none!important}
				#tw-char-creator-wrapper .tw-card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
				#tw-char-creator-wrapper .tw-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
				#tw-char-creator-wrapper .tw-summary-item{padding:14px;border:1px solid rgba(173,255,0,.18);border-radius:16px;background:rgba(255,255,255,.02)}
				#tw-char-creator-wrapper .tw-summary-item dt{font-weight:700;margin-bottom:6px}
				#tw-char-creator-wrapper .tw-progress-ticks{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
				#tw-char-creator-wrapper .tw-progress-tick{width:12px;height:12px;border-radius:999px;background:rgba(255,255,255,.18)}
				#tw-char-creator-wrapper .tw-progress-tick.active{background:#adff00}
				#tw-char-creator-wrapper .tw-attr-grid{display:grid;gap:16px}
				#tw-char-creator-wrapper .tw-attr-row{padding:14px;border:1px solid rgba(173,255,0,.18);border-radius:16px}
				#tw-char-creator-wrapper .tw-attr-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:10px}
				#tw-char-creator-wrapper .tw-attr-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
				#tw-char-creator-wrapper .tw-attr-btn{width:36px;height:36px;border-radius:999px;border:1px solid rgba(173,255,0,.35);background:rgba(173,255,0,.08);color:#d8ff7a;cursor:pointer}
				#tw-char-creator-wrapper .tw-attr-pips{display:flex;gap:8px}
				#tw-char-creator-wrapper .tw-pip{width:14px;height:14px;border-radius:999px;background:rgba(255,255,255,.15);border:1px solid rgba(173,255,0,.2)}
				#tw-char-creator-wrapper .tw-pip.active{background:#adff00;border-color:#adff00}
				#tw-char-creator-wrapper .tw-avatar-layout{display:grid;grid-template-columns:1.1fr .9fr;gap:20px}
				@media (max-width: 900px){
					#tw-char-creator-wrapper .tw-avatar-layout{grid-template-columns:1fr}
				}
				#tw-char-creator-wrapper .tw-avatar-dropzone{border:1px dashed rgba(173,255,0,.35);border-radius:18px;padding:18px}
				#tw-char-creator-wrapper .tw-avatar-selected{display:none;gap:14px;align-items:center}
				#tw-char-creator-wrapper .tw-avatar-selected img{width:96px;height:96px;object-fit:cover;border-radius:18px}
				#tw-char-creator-wrapper .tw-avatar-gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px}
				#tw-char-creator-wrapper .tw-avatar-option{border:1px solid rgba(173,255,0,.18);background:rgba(255,255,255,.02);border-radius:16px;padding:10px;cursor:pointer}
				#tw-char-creator-wrapper .tw-avatar-option.selected{border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.15)}
				#tw-char-creator-wrapper .tw-avatar-option img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:12px}
				#tw-char-creator-wrapper .tw-nav{display:flex;justify-content:space-between;gap:12px;margin-top:24px;flex-wrap:wrap}
				#tw-char-creator-wrapper .tw-btn-nav,
				#tw-char-creator-wrapper .tw-btn-next,
				#tw-char-creator-wrapper .tw-btn-prev,
				#tw-char-creator-wrapper .tw-btn-review-return,
				#tw-char-creator-wrapper #tw-char-submit{
					padding:12px 18px;border-radius:999px;border:1px solid rgba(173,255,0,.35);background:rgba(173,255,0,.08);color:#e7ffad;cursor:pointer;font:inherit
				}
				#tw-char-creator-wrapper #tw-char-submit{background:#adff00;color:#111;font-weight:700}
				#tw-char-creator-wrapper .tw-spinner[hidden]{display:none!important}
			</style>

			<header class="tw-char-header">
				<p class="tw-kicker">NeoWeaver</p>
				<h2>Create Field Agent</h2>
				<p class="tw-intro">Build your operative profile for a single world deployment.</p>

				<div class="tw-progress">
					<div class="tw-progress-meta">
						<span>Step <span id="tw-char-step-current">1</span> / 10</span>
						<strong id="tw-char-progress-phase">IDENTITY SEED</strong>
					</div>

					<div class="tw-progress-bar">
						<div id="tw-char-progress-fill" class="tw-progress-bar-fill" style="width:10%"></div>
					</div>

					<div class="tw-progress-ticks" aria-hidden="true">
						<span class="tw-progress-tick active" data-tick="1"></span>
						<span class="tw-progress-tick" data-tick="2"></span>
						<span class="tw-progress-tick" data-tick="3"></span>
						<span class="tw-progress-tick" data-tick="4"></span>
						<span class="tw-progress-tick" data-tick="5"></span>
						<span class="tw-progress-tick" data-tick="6"></span>
						<span class="tw-progress-tick" data-tick="7"></span>
						<span class="tw-progress-tick" data-tick="8"></span>
						<span class="tw-progress-tick" data-tick="9"></span>
						<span class="tw-progress-tick" data-tick="10"></span>
					</div>
				</div>

				<div id="tw-char-status" class="tw-status" hidden></div>
			</header>

			<div class="tw-steps">

				<section class="tw-step active" data-phase="IDENTITY SEED">
					<div class="tw-step-copy">
						<h3>Define the operative identity before synchronization.</h3>
					</div>

					<div class="tw-form-grid">
						<label class="tw-field-label">
							<span>Agent designation</span>
							<input type="text" id="tw-char-name" maxlength="80" placeholder="Enter character name">
						</label>

						<fieldset class="tw-fieldset">
							<legend>Pronouns</legend>

							<div class="tw-radio-grid">
								<label class="tw-radio-card">
									<input class="tw-pronoun-radio" type="radio" name="tw-char-pronouns" value="she/her">
									<span>She / Her</span>
								</label>

								<label class="tw-radio-card">
									<input class="tw-pronoun-radio" type="radio" name="tw-char-pronouns" value="he/him">
									<span>He / Him</span>
								</label>

								<label class="tw-radio-card">
									<input class="tw-pronoun-radio" type="radio" name="tw-char-pronouns" value="they/them">
									<span>They / Them</span>
								</label>

								<label class="tw-radio-card">
									<input class="tw-pronoun-radio" type="radio" name="tw-char-pronouns" value="xe/xem">
									<span>Xe / Xem</span>
								</label>

								<label class="tw-radio-card">
									<input class="tw-pronoun-radio" type="radio" name="tw-char-pronouns" value="custom">
									<span>Custom</span>
								</label>
							</div>

							<label class="tw-field-label tw-custom-pronouns-field" id="tw-custom-pronouns-field" hidden>
								<span>Custom pronouns</span>
								<input type="text" id="tw-char-pronouns-custom" placeholder="Optional">
							</label>
						</fieldset>
					</div>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<span></span>
						<button type="button" id="tw-char-step1-next" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="RACE PROTOCOL">
					<div class="tw-step-copy">
						<h3>Select the operative's biological or synthetic origin.</h3>
					</div>

					<div id="tw-race-grid" class="tw-card-grid" aria-live="polite"></div>

					<div id="tw-subrace-section" class="tw-subrace-section" hidden>
						<div class="tw-step-copy">
							<h4>Subrace</h4>
							<p>Choose a specialization branch if available.</p>
						</div>
						<div id="tw-subrace-grid" class="tw-card-grid" aria-live="polite"></div>
					</div>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-prev">Back</button>
						<button type="button" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="CLASS MATRIX">
					<div class="tw-step-copy">
						<h3>Select the operative's combat and skill archetype.</h3>
					</div>

					<div id="tw-class-grid" class="tw-card-grid" aria-live="polite"></div>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-prev">Back</button>
						<button type="button" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="BIOMETRIC CALIBRATION">
					<div class="tw-step-copy">
						<h3>Distribute attribute points across four core systems.</h3>
						<p>Each attribute starts at 1 and caps at 5. Remaining <strong id="tw-attr-remaining">8</strong></p>
					</div>

					<div class="tw-attr-presets">
						<button type="button" class="tw-preset-btn" data-preset="gunslinger">Gunslinger</button>
						<button type="button" class="tw-preset-btn" data-preset="tank">Tank</button>
						<button type="button" class="tw-preset-btn" data-preset="technomancer">Technomancer</button>
						<button type="button" class="tw-preset-btn" data-preset="balanced">Balanced</button>
					</div>

					<div class="tw-attr-grid">
						<div class="tw-attr-row" data-attr="body">
							<div class="tw-attr-head">
								<div>
									<h4>Body</h4>
									<p>Strength, endurance, damage soak.</p>
								</div>
								<input type="hidden" id="tw-attr-body" value="1">
							</div>
							<div class="tw-attr-controls">
								<button type="button" class="tw-attr-btn" data-attr-action="minus" data-attr-key="body">−</button>
								<div class="tw-attr-pips">
									<span class="tw-pip active" data-pip="1"></span>
									<span class="tw-pip" data-pip="2"></span>
									<span class="tw-pip" data-pip="3"></span>
									<span class="tw-pip" data-pip="4"></span>
									<span class="tw-pip" data-pip="5"></span>
								</div>
								<button type="button" class="tw-attr-btn" data-attr-action="plus" data-attr-key="body">+</button>
							</div>
						</div>

						<div class="tw-attr-row" data-attr="reflex">
							<div class="tw-attr-head">
								<div>
									<h4>Reflex</h4>
									<p>Speed, initiative, evasion.</p>
								</div>
								<input type="hidden" id="tw-attr-reflex" value="1">
							</div>
							<div class="tw-attr-controls">
								<button type="button" class="tw-attr-btn" data-attr-action="minus" data-attr-key="reflex">−</button>
								<div class="tw-attr-pips">
									<span class="tw-pip active" data-pip="1"></span>
									<span class="tw-pip" data-pip="2"></span>
									<span class="tw-pip" data-pip="3"></span>
									<span class="tw-pip" data-pip="4"></span>
									<span class="tw-pip" data-pip="5"></span>
								</div>
								<button type="button" class="tw-attr-btn" data-attr-action="plus" data-attr-key="reflex">+</button>
							</div>
						</div>

						<div class="tw-attr-row" data-attr="mind">
							<div class="tw-attr-head">
								<div>
									<h4>Mind</h4>
									<p>Logic, analysis, arcane-tech control.</p>
								</div>
								<input type="hidden" id="tw-attr-mind" value="1">
							</div>
							<div class="tw-attr-controls">
								<button type="button" class="tw-attr-btn" data-attr-action="minus" data-attr-key="mind">−</button>
								<div class="tw-attr-pips">
									<span class="tw-pip active" data-pip="1"></span>
									<span class="tw-pip" data-pip="2"></span>
									<span class="tw-pip" data-pip="3"></span>
									<span class="tw-pip" data-pip="4"></span>
									<span class="tw-pip" data-pip="5"></span>
								</div>
								<button type="button" class="tw-attr-btn" data-attr-action="plus" data-attr-key="mind">+</button>
							</div>
						</div>

						<div class="tw-attr-row" data-attr="spirit">
							<div class="tw-attr-head">
								<div>
									<h4>Spirit</h4>
									<p>Will, sync stability, magical resonance.</p>
								</div>
								<input type="hidden" id="tw-attr-spirit" value="1">
							</div>
							<div class="tw-attr-controls">
								<button type="button" class="tw-attr-btn" data-attr-action="minus" data-attr-key="spirit">−</button>
								<div class="tw-attr-pips">
									<span class="tw-pip active" data-pip="1"></span>
									<span class="tw-pip" data-pip="2"></span>
									<span class="tw-pip" data-pip="3"></span>
									<span class="tw-pip" data-pip="4"></span>
									<span class="tw-pip" data-pip="5"></span>
								</div>
								<button type="button" class="tw-attr-btn" data-attr-action="plus" data-attr-key="spirit">+</button>
							</div>
						</div>
					</div>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-prev">Back</button>
						<button type="button" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="SKILL SELECTION">
					<div class="tw-step-copy">
						<h3>Choose active skills unlocked for this operative class.</h3>
						<p id="tw-skill-counter">0 / 5 skills</p>
					</div>

					<div id="tw-skill-grid" class="tw-skill-grid" aria-live="polite"></div>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-prev">Back</button>
						<button type="button" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="STARTING PACKAGE">
					<div class="tw-step-copy">
						<h3>Select the initial equipment loadout available to the chosen class.</h3>
					</div>

					<div id="tw-package-grid" class="tw-card-grid" aria-live="polite"></div>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-prev">Back</button>
						<button type="button" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="DATA ORIGIN">
					<div class="tw-step-copy">
						<h3>Where was your consciousness first stabilized?</h3>
					</div>

					<div id="tw-origin-grid" class="tw-card-grid" aria-live="polite"></div>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-prev">Back</button>
						<button type="button" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="PREVIOUS OPERATION">
					<div class="tw-step-copy">
						<h3>What was your primary function before current Deployment?</h3>
					</div>

					<div id="tw-operation-grid" class="tw-card-grid" aria-live="polite"></div>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-prev">Back</button>
						<button type="button" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="SYNCHRONIZATION CRISIS">
					<div class="tw-step-copy">
						<h3>How did you react to the first contact with Entropy (The Fray)?</h3>
					</div>

					<div id="tw-crisis-grid" class="tw-card-grid" aria-live="polite"></div>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-prev">Back</button>
						<button type="button" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="VISUAL SIGNATURE">
					<div class="tw-step-copy">
						<h3>Upload an operative portrait and add a manual bio. Both are optional.</h3>
					</div>

					<div class="tw-avatar-layout">
						<div class="tw-avatar-dropzone">
							<div id="tw-avatar-preview" class="tw-avatar-preview">
								<p>Drag &amp; drop or</p>
								<label class="tw-btn-nav" for="tw-char-avatar">Choose file</label>
								<p>JPG / PNG / WEBP / SVG, max 2 MB</p>
							</div>

							<input type="file" id="tw-char-avatar" accept=".jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml" hidden>

							<div id="tw-avatar-selected" class="tw-avatar-selected">
								<img id="tw-avatar-img" src="" alt="">
								<div>
									<p>Portrait selected.</p>
									<button type="button" id="tw-avatar-clear" class="tw-btn-nav">Remove</button>
								</div>
							</div>
						</div>

						<div>
							<p>Or choose from gallery</p>
							<div id="tw-avatar-gallery" class="tw-avatar-gallery"></div>
						</div>
					</div>

					<label class="tw-field-label">
						<span>Bio</span>
						<textarea id="tw-char-bio" rows="6" maxlength="1200" placeholder="Add your Field Agent bio"></textarea>
					</label>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-prev">Back</button>
						<button type="button" class="tw-btn-next">Continue</button>
					</div>
				</section>

				<section class="tw-step" data-phase="SYSTEM REVIEW">
					<div class="tw-step-copy">
						<h3>Verify operative parameters before synchronization.</h3>
					</div>

					<dl class="tw-summary-grid">
						<div class="tw-summary-item">
							<dt>Name</dt>
							<dd id="tw-summary-character-name">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Pronouns</dt>
							<dd id="tw-summary-pronouns">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Race / Subrace</dt>
							<dd id="tw-summary-race">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Class</dt>
							<dd id="tw-summary-class">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Attributes</dt>
							<dd id="tw-summary-attrs">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Skills</dt>
							<dd id="tw-summary-skills">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Starting package</dt>
							<dd id="tw-summary-package">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Data origin</dt>
							<dd id="tw-summary-origin">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Previous operation</dt>
							<dd id="tw-summary-operation">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Sync crisis</dt>
							<dd id="tw-summary-crisis">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Backstory tags</dt>
							<dd id="tw-summary-tag-bundle">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Bio</dt>
							<dd id="tw-summary-bio">—</dd>
						</div>
						<div class="tw-summary-item">
							<dt>Avatar</dt>
							<dd id="tw-summary-avatar">—</dd>
						</div>
					</dl>

					<div class="tw-step-error" hidden></div>

					<div class="tw-nav">
						<button type="button" class="tw-btn-review-return">Back</button>
						<button type="button" id="tw-char-submit">Create character</button>
					</div>
				</section>

			</div>

			<div id="tw-char-spinner" class="tw-spinner" hidden>
				<div class="tw-spinner-core"></div>
				<p>Synchronizing operative profile…</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	add_shortcode( 'taleweaver_character_creator', 'neoweaver_shortcode_character_creator' );
}
