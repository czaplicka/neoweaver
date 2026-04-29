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
if ( ! function_exists( 'neoweaver_register_character_creator_assets' ) ) {
    function neoweaver_register_character_creator_assets(): void {
        $css_handle = 'neoweaver-character-creator';
        $js_handle  = 'neoweaver-character-creator';

        $css_path = plugin_dir_path( __FILE__ ) . '../../assets/css/tw-character-creator.css';
        $js_path  = plugin_dir_path( __FILE__ ) . '../../assets/js/tw-character-creator.js';

        $css_url = plugin_dir_url( __FILE__ ) . '../../assets/css/tw-character-creator.css';
        $js_url  = plugin_dir_url( __FILE__ ) . '../../assets/js/tw-character-creator.js';

        wp_register_style(
            $css_handle,
            $css_url,
            array(),
            file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.23'
        );

        wp_register_script(
            $js_handle,
            $js_url,
            array(),
            file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.22',
            true
        );

        $uploads = wp_get_upload_dir();

        wp_localize_script(
            $js_handle,
            'twCharCreatorConfig',
            array(
                'ajaxurl'        => admin_url( 'admin-ajax.php' ),
                'ajax_url'       => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( 'neoweaver_nonce' ),
                'sitebase'       => home_url(),
                'site_base'      => home_url(),
                'uploadsbase'    => trailingslashit( $uploads['baseurl'] ),
                'uploads_base'   => trailingslashit( $uploads['baseurl'] ),
                'avatar_gallery' => array(
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
}
add_action( 'wp_enqueue_scripts', 'neoweaver_register_character_creator_assets' );

/**
 * Shortcode renderer.
 */
if ( ! function_exists( 'neoweaver_shortcode_character_creator' ) ) {
    function neoweaver_shortcode_character_creator(): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="tw-char-login-required">You need to be logged in to create a character.</div>';
        }

        wp_enqueue_style( 'neoweaver-character-creator' );
        wp_enqueue_script( 'neoweaver-character-creator' );

        ob_start();
        ?>
        <div id="tw-char-creator-wrapper" class="tw-character-creator tw-char-creator">
            <div class="tw-progress-bar">
                <div class="tw-progress-header">
                    <div class="tw-progress-label">
                        OPERATIVE INITIALIZATION <span class="tw-blink" aria-hidden="true"></span>
                    </div>
                    <div class="tw-progress-counter">
                        STEP <span id="tw-char-step-current">1</span> / 11
                    </div>
                </div>

                <div class="tw-progress-track" aria-hidden="true">
                    <div id="tw-char-progress-fill" class="tw-progress-fill"></div>
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
                    <span class="tw-progress-tick" data-tick="11"></span>
                </div>

                <div id="tw-char-progress-phase" class="tw-progress-phase">IDENTITY SYNC</div>
            </div>

            <div class="tw-char-status" aria-live="polite"></div>

            <section class="tw-char-step active" data-phase="IDENTITY SYNC">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Identity Sync</h2>
                <p class="tw-question-text">Define your operative signal, visible identity, and pronoun channel before entering the Node.</p>

                <label class="tw-field-label" for="tw-char-name">
                    <span>Character name <span class="tw-required">*</span></span>
                    <input type="text" id="tw-char-name" name="character_name" placeholder="Enter operative designation" autocomplete="off" maxlength="80">
                </label>

                <fieldset class="tw-pronoun-fieldset">
                    <legend>Pronouns <span class="tw-required">*</span></legend>
                    <div class="tw-pronoun-options">
                        <label class="tw-pronoun-option">
                            <input type="radio" class="tw-pronoun-radio" name="tw-char-pronouns" value="she">
                            <span class="tw-pronoun-label">she / her</span>
                        </label>
                        <label class="tw-pronoun-option">
                            <input type="radio" class="tw-pronoun-radio" name="tw-char-pronouns" value="he">
                            <span class="tw-pronoun-label">he / him</span>
                        </label>
                        <label class="tw-pronoun-option">
                            <input type="radio" class="tw-pronoun-radio" name="tw-char-pronouns" value="they">
                            <span class="tw-pronoun-label">they / them</span>
                        </label>
                        <label class="tw-pronoun-option">
                            <input type="radio" class="tw-pronoun-radio" name="tw-char-pronouns" value="xe">
                            <span class="tw-pronoun-label">xe / xem</span>
                        </label>
                        <label class="tw-pronoun-option">
                            <input type="radio" class="tw-pronoun-radio" name="tw-char-pronouns" value="custom">
                            <span class="tw-pronoun-label">custom</span>
                        </label>
                    </div>
                </fieldset>

                <div id="tw-char-pronouns-custom-wrap" class="tw-pronouns-custom-wrap" hidden style="display:none;">
                    <label class="tw-field-label" for="tw-char-pronouns-custom">
                        <span>Custom pronouns</span>
                        <input type="text" id="tw-char-pronouns-custom" name="tw-char-pronouns-custom" placeholder="Enter custom pronouns" autocomplete="off" maxlength="80">
                    </label>
                </div>

                <div class="tw-nav-row">
                    <div></div>
                    <button type="button" id="tw-char-step1-next" class="tw-btn tw-btn--primary tw-btn-next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="RACE PROTOCOL">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Race Protocol</h2>
                <p class="tw-question-text">Choose your race first. Then pick a subrace. Only the subrace is stored in the character record.</p>

                <div id="tw-race-grid" class="tw-dynamic-grid" aria-live="polite"></div>

                <div id="tw-subrace-section" class="tw-subrace-section" hidden style="display:none;">
                    <h3 class="tw-subrace-heading">Subrace selection</h3>
                    <div id="tw-subrace-grid" class="tw-dynamic-grid" aria-live="polite"></div>
                </div>

                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
                    <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="CLASS MATRIX">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Class Matrix</h2>
                <p class="tw-question-text">Select your operative class. The chosen class sets your skill limit and filters compatible starting packages by class tag.</p>

                <div id="tw-class-grid" class="tw-dynamic-grid" aria-live="polite"></div>

                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
                    <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="BIOMETRIC CALIBRATION">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Biometric Calibration</h2>
                <p class="tw-question-text">
                    Distribute all 12 attribute points. Each attribute starts at 1 and caps at 5.
                    <span class="tw-attr-remaining-label">Remaining <strong id="tw-attr-remaining">8</strong></span>
                </p>

<div class="tw-attr-presets">
  <span class="tw-attr-presets-label">Presets</span>
  <button type="button" class="tw-attr-preset-btn" data-preset="balanced" aria-pressed="false">Balanced</button>
  <button type="button" class="tw-attr-preset-btn" data-preset="gunslinger" aria-pressed="false">Gunslinger</button>
  <button type="button" class="tw-attr-preset-btn" data-preset="genius" aria-pressed="false">Genius</button>
  <button type="button" class="tw-attr-preset-btn" data-preset="warlock" aria-pressed="false">Warlock</button>
  <button type="button" class="tw-attr-preset-btn" data-preset="bodybuilder" aria-pressed="false">Body Builder</button>
</div>

                <div class="tw-attr-grid">
                    <div class="tw-attr-row" data-attr="body">
                        <div class="tw-attr-icon" aria-hidden="true">⬢</div>
                        <div class="tw-attr-info">
                            <h4>Body <small>BODY</small></h4>
                            <span>Strength, endurance, damage soak.</span>
                        </div>
                        <div class="tw-attr-controls">
                            <div class="tw-attr-stepper">
<button type="button" class="tw-attr-btn tw-attr-minus" aria-label="Decrease Body">−</button>
<input type="number" id="tw-attr-body" class="tw-attr-val" value="1" min="1" max="5" readonly>
<button type="button" class="tw-attr-btn tw-attr-plus" aria-label="Increase Body">+</button>
                            </div>
                            <div class="tw-attr-pips" aria-hidden="true">
                                <button type="button" class="tw-pip active" data-pip="1" aria-label="Set Body to 1"></button>
                                <button type="button" class="tw-pip" data-pip="2" aria-label="Set Body to 2"></button>
                                <button type="button" class="tw-pip" data-pip="3" aria-label="Set Body to 3"></button>
                                <button type="button" class="tw-pip" data-pip="4" aria-label="Set Body to 4"></button>
                                <button type="button" class="tw-pip" data-pip="5" aria-label="Set Body to 5"></button>
                            </div>
                        </div>
                    </div>

                    <div class="tw-attr-row" data-attr="reflex">
                        <div class="tw-attr-icon" aria-hidden="true">⬡</div>
                        <div class="tw-attr-info">
                            <h4>Reflex <small>REFLEX</small></h4>
                            <span>Speed, initiative, evasion.</span>
                        </div>
                        <div class="tw-attr-controls">
                            <div class="tw-attr-stepper">
                                <button type="button" class="tw-attr-btn tw-attr-minus" aria-label="Decrease Reflex">-</button>
                                <input type="number" id="tw-attr-reflex" class="tw-attr-val" value="1" min="1" max="5" readonly>
                                <button type="button" class="tw-attr-btn tw-attr-plus" aria-label="Increase Reflex">=</button>
                            </div>
                            <div class="tw-attr-pips" aria-hidden="true">
                                <button type="button" class="tw-pip active" data-pip="1" aria-label="Set Reflex to 1"></button>
                                <button type="button" class="tw-pip" data-pip="2" aria-label="Set Reflex to 2"></button>
                                <button type="button" class="tw-pip" data-pip="3" aria-label="Set Reflex to 3"></button>
                                <button type="button" class="tw-pip" data-pip="4" aria-label="Set Reflex to 4"></button>
                                <button type="button" class="tw-pip" data-pip="5" aria-label="Set Reflex to 5"></button>
                            </div>
                        </div>
                    </div>

                    <div class="tw-attr-row" data-attr="mind">
                        <div class="tw-attr-icon" aria-hidden="true">◈</div>
                        <div class="tw-attr-info">
                            <h4>Mind <small>MIND</small></h4>
                            <span>Logic, analysis, arcane-tech control.</span>
                        </div>
                        <div class="tw-attr-controls">
                            <div class="tw-attr-stepper">
                                <button type="button" class="tw-attr-btn tw-attr-minus" aria-label="Decrease Mind">-</button>
                                <input type="number" id="tw-attr-mind" class="tw-attr-val" value="1" min="1" max="5" readonly>
                                <button type="button" class="tw-attr-btn tw-attr-plus" aria-label="Increase Mind">+</button>
                            </div>
                            <div class="tw-attr-pips" aria-hidden="true">
                                <button type="button" class="tw-pip active" data-pip="1" aria-label="Set Mind to 1"></button>
                                <button type="button" class="tw-pip" data-pip="2" aria-label="Set Mind to 2"></button>
                                <button type="button" class="tw-pip" data-pip="3" aria-label="Set Mind to 3"></button>
                                <button type="button" class="tw-pip" data-pip="4" aria-label="Set Mind to 4"></button>
                                <button type="button" class="tw-pip" data-pip="5" aria-label="Set Mind to 5"></button>
                            </div>
                        </div>
                    </div>

                    <div class="tw-attr-row" data-attr="spirit">
                        <div class="tw-attr-icon" aria-hidden="true">✦</div>
                        <div class="tw-attr-info">
                            <h4>Spirit <small>SPIRIT</small></h4>
                            <span>Will, sync stability, magical resonance.</span>
                        </div>
                        <div class="tw-attr-controls">
                            <div class="tw-attr-stepper">
                                <button type="button" class="tw-attr-btn tw-attr-minus" aria-label="Decrease Spirit">-</button>
                                <input type="number" id="tw-attr-spirit" class="tw-attr-val" value="1" min="1" max="5" readonly>
                                <button type="button" class="tw-attr-btn tw-attr-plus" aria-label="Increase Spirit">+</button>
                            </div>
                            <div class="tw-attr-pips" aria-hidden="true">
                                <button type="button" class="tw-pip active" data-pip="1" aria-label="Set Spirit to 1"></button>
                                <button type="button" class="tw-pip" data-pip="2" aria-label="Set Spirit to 2"></button>
                                <button type="button" class="tw-pip" data-pip="3" aria-label="Set Spirit to 3"></button>
                                <button type="button" class="tw-pip" data-pip="4" aria-label="Set Spirit to 4"></button>
                                <button type="button" class="tw-pip" data-pip="5" aria-label="Set Spirit to 5"></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
                    <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="SKILL SELECTION">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Skill Selection</h2>
                <p class="tw-question-text">Choose your active skills. The maximum number depends on the class selected in the previous step.</p>

                <div id="tw-skill-counter" class="tw-skill-counter">0 / 5 skills</div>
                <div id="tw-skill-grid" class="tw-skill-grid" aria-live="polite"></div>

                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
                    <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="STARTING PACKAGE">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Starting Package</h2>
                <p class="tw-question-text">Choose one player-selectable package compatible with your class tag.</p>

                <div id="tw-package-grid" class="tw-dynamic-grid" aria-live="polite"></div>

                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
                    <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="DATA ORIGIN">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Data Origin</h2>
                <p class="tw-question-text">Select the environment where your consciousness first stabilized.</p>

                <div id="tw-origin-grid" class="tw-dynamic-grid" aria-live="polite"></div>

                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
                    <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="PREVIOUS OPERATION">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Previous Operation</h2>
                <p class="tw-question-text">Choose the main operational pattern your unit was shaped by before this deployment.</p>

                <div id="tw-operation-grid" class="tw-dynamic-grid" aria-live="polite"></div>

                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
                    <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="SYNCHRONIZATION CRISIS">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Synchronization Crisis</h2>
                <p class="tw-question-text">Select your crisis response profile.</p>

                <div id="tw-crisis-grid" class="tw-dynamic-grid" aria-live="polite"></div>

                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
                    <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="VISUAL SIGNATURE">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>Visual Signature</h2>
                <p class="tw-question-text">Add a portrait and optional short bio for your operative.</p>

                <div class="tw-upload-box" id="tw-avatar-preview">
                    <div class="tw-upload-preview">
                        <div class="tw-upload-icon" aria-hidden="true"></div>
                        <p>Drag &amp; drop or upload a portrait file.</p>
                        <p>JPG / PNG / WEBP / SVG, max 2 MB</p>
                        <label class="tw-btn tw-btn--primary" for="tw-char-avatar">Upload portrait</label>
                        <input type="file" id="tw-char-avatar" name="avatar" accept=".jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml" hidden>
                    </div>
                </div>

                <div id="tw-avatar-selected" class="tw-avatar-selected" style="display:none;">
                    <img id="tw-avatar-img" src="" alt="">
                    <button type="button" id="tw-char-avatar-clear" class="tw-avatar-clear">Remove portrait</button>
                </div>

                <div class="tw-avatar-gallery-wrap">
                    <p class="tw-question-text">Or choose from gallery.</p>
                    <div id="tw-avatar-gallery" class="tw-dynamic-grid" aria-live="polite"></div>
                </div><br>

                <label class="tw-field-label" for="tw-char-bio">
                    <span>Short bio</span>
                    <textarea id="tw-char-bio" name="bio" placeholder="Write a short bio, personality trace, or external-facing profile." maxlength="1000"></textarea>
                </label>

                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
                    <button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
                </div>
            </section>

            <section class="tw-char-step" data-phase="SYSTEM REVIEW">
                <div class="tw-step-error">
                    <span class="tw-step-error-icon" aria-hidden="true"></span>
                    <span class="tw-step-error-msg"></span>
                </div>

                <h2>System Review</h2>
                <p class="tw-question-text">Review the full configuration before deployment.</p>

<div class="tw-summary-grid">
    <div class="tw-summary-row">
        <div class="tw-summary-key">Name</div>
        <div class="tw-summary-val" id="tw-summary-character-name"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="0">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Pronouns</div>
        <div class="tw-summary-val" id="tw-summary-pronouns"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="0">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Race / subrace</div>
        <div class="tw-summary-val" id="tw-summary-race"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="1">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Class</div>
        <div class="tw-summary-val" id="tw-summary-class"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="2">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Attributes</div>
        <div class="tw-summary-val" id="tw-summary-attrs"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="3">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Skills</div>
        <div class="tw-summary-val" id="tw-summary-skills"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="4">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Package</div>
        <div class="tw-summary-val" id="tw-summary-package"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="5">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Data origin</div>
        <div class="tw-summary-val" id="tw-summary-origin"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="6">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Previous operation</div>
        <div class="tw-summary-val" id="tw-summary-operation"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="7">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Sync crisis</div>
        <div class="tw-summary-val" id="tw-summary-crisis"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="8">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Backstory tags</div>
        <div class="tw-summary-val" id="tw-summary-tag-bundle"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="8">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Bio</div>
        <div class="tw-summary-val" id="tw-summary-bio"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="9">Edit</button>
    </div>

    <div class="tw-summary-row">
        <div class="tw-summary-key">Portrait</div>
        <div class="tw-summary-val" id="tw-summary-avatar"></div>
        <button type="button" class="tw-summary-edit tw-btn-review-edit" data-target-step="9">Edit</button>
    </div>
</div>
                <div class="tw-nav-row">
                    <button type="button" class="tw-btn-nav tw-btn-prev tw-btn-review-return" data-dir="prev">Back</button>
                    <button type="button" id="tw-char-submit" class="tw-btn tw-btn--primary">DEPLOY OPERATIVE</button>
                </div>
            </section>

            <div id="tw-char-spinner" aria-hidden="true">
                <div class="tw-spinner-inner">
                    <div class="tw-spinner-ring"></div>
                    <div class="tw-spinner-ring tw-spinner-ring--2"></div>
                    <p class="tw-spinner-text">Synchronizing operative profile</p>
                    <p class="tw-spinner-sub">Writing race, subrace, class, skills, package, avatar, and backstory tags to the Node.</p>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
add_shortcode( 'neoweaver_character_creator', 'neoweaver_shortcode_character_creator' );
