<?php
/**
 * NeoWeaver Character Creator shortcode
 * File: public/shortcodes/shortcode-character-creator.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register assets.
 */
if ( ! function_exists( 'neoweaver_character_creator_enqueue' ) ) {
	function neoweaver_character_creator_enqueue() {
		$base = plugin_dir_url( dirname( __FILE__, 2 ) );

		wp_enqueue_style(
			'neoweaver-char-creator',
			$base . 'assets/css/public/character-creator.css',
			[],
			filemtime( plugin_dir_path( dirname( __FILE__, 2 ) ) . 'assets/css/public/character-creator.css' )
		);

		wp_enqueue_script(
			'neoweaver-char-creator',
			$base . 'assets/js/public/character-creator.js',
			[],
			filemtime( plugin_dir_path( dirname( __FILE__, 2 ) ) . 'assets/js/public/character-creator.js' ),
			true
		);

		$cfg = neoweaver_char_creator_js_config();
		wp_localize_script( 'neoweaver-char-creator', 'twCharCreatorAjax',   $cfg );
		wp_localize_script( 'neoweaver-char-creator', 'twCharCreatorConfig', $cfg );
	}
}
add_action( 'wp_enqueue_scripts', 'neoweaver_character_creator_enqueue' );

/**
 * Build JS config array (shared between enqueue and inline fallback).
 */
if ( ! function_exists( 'neoweaver_char_creator_js_config' ) ) {
	function neoweaver_char_creator_js_config() {
		// ── Avatar gallery ──────────────────────────────────────────────────
		$gallery     = [];
		$gallery_ids = get_option( 'neoweaver_avatar_gallery', [] );
		if ( is_array( $gallery_ids ) ) {
			foreach ( $gallery_ids as $id ) {
				$url = wp_get_attachment_url( (int) $id );
				if ( $url ) {
					$gallery[] = [
						'id'   => (int) $id,
						'url'  => $url,
						'name' => get_the_title( (int) $id ) ?: basename( $url ),
					];
				}
			}
		}

		return [
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'neoweaver_char_creator' ),
			'uploadsbase' => wp_upload_dir()['baseurl'],
			'avatar_gallery' => $gallery,
		];
	}
}

/**
 * Shortcode callback.
 */
if ( ! function_exists( 'neoweaver_character_creator_shortcode' ) ) {
	function neoweaver_character_creator_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p class="tw-login-notice">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to create a character.</p>';
		}

		// ── Inline JS config fallback (covers edge cases where wp_localize runs
		//    before the shortcode decides to enqueue). ─────────────────────────
		$cfg = neoweaver_char_creator_js_config();
		$inline = '<script>window.twCharCreatorAjax=' . wp_json_encode( $cfg ) . ';'
		        . 'window.twCharCreatorConfig=' . wp_json_encode( $cfg ) . ';'
		        . 'window.neoweaverAjax=' . wp_json_encode( $cfg ) . ';</script>';

		ob_start();
		echo $inline; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

<div id="tw-char-creator" class="tw-char-creator" data-initialized="false">

  <!-- ── Progress bar ─────────────────────────────────────────── -->
  <div class="tw-progress-bar">
    <div class="tw-progress-info">
      <span class="tw-progress-label">Step <span id="tw-char-step-current">1</span> of 11</span>
      <span id="tw-char-progress-phase" class="tw-progress-phase"></span>
    </div>
    <div class="tw-progress-track">
      <div id="tw-char-progress-fill" class="tw-progress-fill" style="width:9.09%"></div>
    </div>
    <div class="tw-progress-ticks">
      <?php for ( $i = 0; $i < 11; $i++ ) : ?>
        <span class="tw-progress-tick<?php echo $i === 0 ? ' active' : ''; ?>"></span>
      <?php endfor; ?>
    </div>
  </div>

  <!-- ── Status message ──────────────────────────────────────── -->
  <div class="tw-char-status" role="status" aria-live="polite"></div>

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 1 – Name & Pronouns                                  -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="0" data-phase="Identity">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Who are you?</h2>
      <p class="tw-step-subtitle">Give your Field Agent a name and pronouns.</p>
    </div>

    <div class="tw-form-group">
      <label class="tw-label" for="tw-char-name">Agent designation</label>
      <input id="tw-char-name" class="tw-input" type="text" maxlength="60"
             placeholder="Enter character name…" autocomplete="off">
    </div>

    <fieldset class="tw-fieldset">
      <legend class="tw-label">Pronouns</legend>
      <div class="tw-pronoun-grid">
        <?php
        $pronouns = [
          'he/him'   => 'He / Him',
          'she/her'  => 'She / Her',
          'they/them'=> 'They / Them',
          'it/its'   => 'It / Its',
          'custom'   => 'Custom…',
        ];
        foreach ( $pronouns as $val => $label ) :
        ?>
          <label class="tw-pronoun-option">
            <input type="radio" name="tw-char-pronouns" value="<?php echo esc_attr( $val ); ?>">
            <span class="tw-pronoun-label"><?php echo esc_html( $label ); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div id="tw-char-pronouns-custom-wrap" hidden style="display:none;">
      <label class="tw-label" for="tw-char-pronouns-custom">Custom pronouns</label>
      <input id="tw-char-pronouns-custom" class="tw-input" type="text" maxlength="40"
             placeholder="e.g. xe/xem">
    </div>

    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
    <div class="tw-nav-row tw-nav-row--right">
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
  </div><!-- /step 0 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 2 – Race & Subrace                                   -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="1" data-phase="Origin" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Choose your race</h2>
      <p class="tw-question-text">Choose your race first. Then pick a subrace. Only the subrace is stored in the character record.</p>
    </div>

    <div id="tw-race-grid" class="tw-dynamic-grid" aria-live="polite"></div>

    <div id="tw-subrace-section" class="tw-subrace-section" hidden style="display:none;">
        <h3 id="subrace-selection" class="tw-subrace-heading">Subrace selection</h3>
        <div id="tw-subrace-grid" class="tw-dynamic-grid" aria-live="polite"></div>
    </div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 1 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 3 – Class                                            -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="2" data-phase="Class" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Choose your class</h2>
      <p class="tw-step-subtitle">Your class shapes your skill set and combat approach.</p>
    </div>

    <div id="tw-class-grid" class="tw-dynamic-grid" aria-live="polite"></div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 2 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 4 – Attributes                                       -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="3" data-phase="Attributes" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Allocate attributes</h2>
      <p class="tw-step-subtitle">Distribute <strong>12 points</strong> across Body, Reflex, Mind, and Spirit (1–5 each).</p>
    </div>

    <p class="tw-attr-remaining-label">Points remaining: <strong id="tw-attr-remaining">8</strong></p>

    <div class="tw-attr-presets">
      <span class="tw-attr-presets-label">Quick presets:</span>
      <?php
      $presets = [
        'balanced'    => 'Balanced',
        'agile'       => 'Agile',
        'tank'        => 'Tank',
        'bodybuilder' => 'Bodybuilder',
        'gunslinger'  => 'Gunslinger',
        'genius'      => 'Genius',
        'warlock'     => 'Warlock',
      ];
      foreach ( $presets as $key => $label ) :
      ?>
        <button type="button" class="tw-attr-preset-btn" data-preset="<?php echo esc_attr( $key ); ?>"
                aria-pressed="false"><?php echo esc_html( $label ); ?></button>
      <?php endforeach; ?>
    </div>

    <div class="tw-attr-list">
      <?php
      $attrs = [
        'body'   => [ 'label' => 'Body',   'icon' => '💪' ],
        'reflex' => [ 'label' => 'Reflex', 'icon' => '⚡' ],
        'mind'   => [ 'label' => 'Mind',   'icon' => '🧠' ],
        'spirit' => [ 'label' => 'Spirit', 'icon' => '✨' ],
      ];
      foreach ( $attrs as $key => $meta ) :
      ?>
        <div class="tw-attr-row" data-attr="<?php echo esc_attr( $key ); ?>">
          <span class="tw-attr-icon"><?php echo $meta['icon']; // phpcs:ignore ?></span>
          <span class="tw-attr-label"><?php echo esc_html( $meta['label'] ); ?></span>
          <div class="tw-attr-pips">
            <?php for ( $p = 1; $p <= 5; $p++ ) : ?>
              <button type="button" class="tw-pip" data-pip="<?php echo $p; ?>" aria-label="Set <?php echo esc_attr( $meta['label'] ); ?> to <?php echo $p; ?>"></button>
            <?php endfor; ?>
          </div>
          <button type="button" class="tw-attr-minus" aria-label="Decrease <?php echo esc_attr( $meta['label'] ); ?>">−</button>
          <input id="tw-attr-<?php echo esc_attr( $key ); ?>" class="tw-attr-input" type="number"
                 min="1" max="5" value="1" readonly aria-label="<?php echo esc_attr( $meta['label'] ); ?> value">
          <button type="button" class="tw-attr-plus" aria-label="Increase <?php echo esc_attr( $meta['label'] ); ?>">+</button>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 3 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 5 – Skills                                           -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="4" data-phase="Skills" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Choose your skills</h2>
      <p class="tw-step-subtitle" id="tw-skill-counter">0 / 3 skills</p>
    </div>

    <div id="tw-skill-grid" class="tw-skill-grid" aria-live="polite"></div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 4 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 6 – Starting Package                                 -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="5" data-phase="Equipment" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Starting package</h2>
      <p class="tw-step-subtitle">Your initial gear and credits loadout.</p>
    </div>

    <div id="tw-package-grid" class="tw-dynamic-grid" aria-live="polite"></div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 5 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 7 – Data Origin                                      -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="6" data-phase="Backstory" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Data origin</h2>
      <p class="tw-step-subtitle">Where did your consciousness first stabilize?</p>
    </div>

    <div id="tw-origin-grid" class="tw-dynamic-grid" aria-live="polite"></div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 6 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 8 – Previous Operation                               -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="7" data-phase="Backstory" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Previous operation</h2>
      <p class="tw-step-subtitle">What was your primary function before the current deployment?</p>
    </div>

    <div id="tw-operation-grid" class="tw-dynamic-grid" aria-live="polite"></div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 7 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 9 – Sync Crisis                                      -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="8" data-phase="Backstory" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Sync crisis</h2>
      <p class="tw-step-subtitle">How did you first encounter entropy — and how did you respond?</p>
    </div>

    <div id="tw-crisis-grid" class="tw-dynamic-grid" aria-live="polite"></div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 8 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 10 – Bio & Avatar                                    -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="9" data-phase="Identity" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Bio &amp; Avatar</h2>
      <p class="tw-step-subtitle">Optional — write a short background and pick a visual for your agent.</p>
    </div>

    <div class="tw-form-group">
      <label class="tw-label" for="tw-char-bio">Background story</label>
      <textarea id="tw-char-bio" class="tw-input tw-textarea" rows="5"
                placeholder="What shaped this agent before deployment…"></textarea>
    </div>

    <div class="tw-avatar-section">
      <p class="tw-label">Avatar</p>
      <p class="tw-step-subtitle">Upload your own image or pick from the gallery below.</p>

      <label class="tw-btn tw-btn--secondary tw-avatar-upload-btn" for="tw-char-avatar">
        Upload image
        <input id="tw-char-avatar" type="file" accept="image/*" class="tw-sr-only" aria-label="Upload avatar image">
      </label>

      <div id="tw-avatar-selected" style="display:none;" class="tw-avatar-preview">
        <img id="tw-avatar-img" src="" alt="" loading="lazy">
        <button type="button" id="tw-char-avatar-clear" class="tw-avatar-clear-btn" aria-label="Remove selected avatar">✕</button>
      </div>

      <div id="tw-avatar-gallery" class="tw-avatar-gallery" aria-label="Avatar gallery"></div>
    </div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 9 -->

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- STEP 11 – Summary & Submit                                -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="tw-char-step" data-step="10" data-phase="Review" hidden style="display:none;">

    <div class="tw-step-header">
      <h2 class="tw-step-title">Review &amp; confirm</h2>
      <p class="tw-step-subtitle">Check all your choices before creating the agent.</p>
    </div>

    <div class="tw-summary-grid">
      <div class="tw-summary-row">
        <div class="tw-summary-key">Name</div>
        <div id="tw-summary-character-name" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Pronouns</div>
        <div id="tw-summary-pronouns" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Race / subrace</div>
        <div id="tw-summary-race" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Class</div>
        <div id="tw-summary-class" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Attributes</div>
        <div id="tw-summary-attrs" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Skills</div>
        <div id="tw-summary-skills" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Starting package</div>
        <div id="tw-summary-package" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Data origin</div>
        <div id="tw-summary-origin" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Previous operation</div>
        <div id="tw-summary-operation" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Sync crisis</div>
        <div id="tw-summary-crisis" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Backstory tags</div>
        <div id="tw-summary-tag-bundle" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Bio</div>
        <div id="tw-summary-bio" class="tw-summary-val">—</div>
      </div>
      <div class="tw-summary-row">
        <div class="tw-summary-key">Avatar</div>
        <div id="tw-summary-avatar" class="tw-summary-val">—</div>
      </div>
    </div>

    <div class="tw-nav-row">
      <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
      <button type="button" id="tw-char-submit" class="tw-btn tw-btn--primary">Create Agent</button>
    </div>
    <div class="tw-step-error"><span class="tw-step-error-msg"></span></div>
  </div><!-- /step 10 -->

</div><!-- /#tw-char-creator -->

		<?php
		return ob_get_clean();
	}
}
add_shortcode( 'neoweaver_character_creator', 'neoweaver_character_creator_shortcode' );
