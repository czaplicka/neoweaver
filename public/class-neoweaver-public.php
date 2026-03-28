<?php
/**
 * LAYOUT CONTRACT (mandatory for all current and future shortcodes):
 *   Every shortcode return value MUST be wrapped in:
 *     <div class="neoweaver-screen">…</div>
 *   The shared layout rules in assets/css/neoweaver-public.css rely on this
 *   wrapper to control z-index, margin and overflow relative to the theme's
 *   CTA section and footer.
 *
 * CSS SCOPING RULE:
 *   Every rule in the per-wizard CSS files MUST be scoped under both the
 *   shared wrapper AND the screen's unique root ID, e.g.:
 *     .neoweaver-screen #tw-char-creator .tw-screen-bezel { … }
 *   This prevents collisions between shortcodes that share generic class names.
 *
 * SEPARATION OF CONCERNS:
 *   - PHP methods prepare data and pass it to template partials via $tw_data.
 *   - HTML lives in templates/partials/*.php.
 *   - CSS lives in assets/css/ and is enqueued via wp_enqueue_style().
 *   - JS lives in assets/js/ and is enqueued via wp_enqueue_script().
 *   No inline <style> or <script> blocks belong in this class.
 *
 * WIZARD SHORTCODES:
 *   The three creation wizards (character, campaign, world) have been extracted
 *   into their own files under public/shortcodes/:
 *     - shortcode-character-creator.php  → [tale_weaver_character_creator]
 *     - shortcode-campaign-creator.php   → [tw_create_campaign]
 *     - shortcode-world-creator.php      → [tw_world_creator]
 *   The methods here delegate to the standalone functions defined in those files.
 *   Asset enqueueing and shortcode registration remain in this class.
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Public {

	/** @var Neoweaver_Agents_List */
	protected Neoweaver_Agents_List $agents_list;

	/** @var Neoweaver_Agents_Creator */
	protected Neoweaver_Agents_Creator $agents_creator;

	/** @var Neoweaver_Deployments_Creator */
	protected Neoweaver_Deployments_Creator $deployments_creator;

	/** @var Neoweaver_Nodes_Creator */
	protected Neoweaver_Nodes_Creator $nodes_creator;

	public function __construct(
		Neoweaver_Agents_List $agents_list,
		Neoweaver_Agents_Creator $agents_creator,
		Neoweaver_Deployments_Creator $deployments_creator,
		Neoweaver_Nodes_Creator $nodes_creator
	) {
		$this->agents_list         = $agents_list;
		$this->agents_creator      = $agents_creator;
		$this->deployments_creator = $deployments_creator;
		$this->nodes_creator       = $nodes_creator;

		add_shortcode( 'tw_list_characters',            [ $this, 'shortcode_list_characters' ] );
		add_shortcode( 'tale_weaver_character_creator', [ $this, 'shortcode_character_creator' ] );
		add_shortcode( 'tw_create_campaign',            [ $this, 'shortcode_campaign_creator' ] );
		add_shortcode( 'tw_world_creator',              [ $this, 'shortcode_world_creator' ] );
		add_shortcode( 'tw_active_node',                [ $this, 'shortcode_active_node' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_footer',          [ $this, 'enqueue_quick_actions_bridge' ] );
		add_action( 'wp_footer',          [ $this, 'render_tag_update_popup' ] );
	}

	// =========================================================================
	// ASSET REGISTRATION
	// =========================================================================

	/**
	 * Enqueue per-wizard CSS and JS on the front-end.
	 *
	 * All assets live in the plugin's own assets/ directory.
	 * Files are loaded only if they exist (file_exists check), so adding a new
	 * wizard is as simple as dropping css/js files here and adding a row below.
	 * Versioning via filemtime() ensures automatic cache-busting.
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$plugin_url = NEOWEAVER_PLUGIN_URL;
		$plugin_dir = NEOWEAVER_PLUGIN_DIR;

		// ── Pronoun chip styles (character creator step 1) ──────────────────
		// Enqueued globally so they're ready before the wizard JS fires.
		$pronouns_css = $plugin_dir . 'assets/css/tw-pronouns.css';
		if ( file_exists( $pronouns_css ) ) {
			wp_enqueue_style(
				'neoweaver-pronouns',
				$plugin_url . 'assets/css/tw-pronouns.css',
				[ 'neoweaver-public' ],
				(string) filemtime( $pronouns_css )
			);
		}

		// [ handle, css-file, js-file, js-deps, js-in-footer ]
		$assets = [
			[
				'neoweaver-char-creator',
				'assets/css/tw-character-creator.css',
				'assets/js/character-creator.js',
				[ 'jquery', 'neoweaver-pronouns' ],
				true,
			],
			[
				'neoweaver-campaign-creator',
				'assets/css/tw-campaign-creator.css',
				'assets/js/tw-campaign-creator.js',
				[],
				true,
			],
			[
				'neoweaver-world-creator',
				'assets/css/world-creator.css',
				'assets/js/tw-world-creator.js',
				[],
				true,
			],
		];

		foreach ( $assets as [ $handle, $css_rel, $js_rel, $deps, $in_footer ] ) {
			$css_file = $plugin_dir . $css_rel;
			$js_file  = $plugin_dir . $js_rel;

			if ( file_exists( $css_file ) ) {
				wp_enqueue_style(
					$handle,
					$plugin_url . $css_rel,
					[ 'neoweaver-public' ],
					(string) filemtime( $css_file )
				);
			}

			if ( file_exists( $js_file ) ) {
				wp_enqueue_script(
					$handle,
					$plugin_url . $js_rel,
					$deps,
					(string) filemtime( $js_file ),
					$in_footer
				);
			}
		}

		// ── Character creator JS config ──────────────────────────────────────
		// wp_localize_script MUST be called here (during wp_enqueue_scripts),
		// NOT inside the shortcode render function — by the time shortcode runs,
		// wp_head has already fired and localize would be silently ignored.
		wp_localize_script(
			'neoweaver-char-creator',
			'twCharCreatorConfig',
			[
				'nonce'       => wp_create_nonce( 'tw_character_nonce' ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'restUrl'     => home_url( '/wp-json/neoweaver/v1/character/create' ),
				'agentsUrl'   => home_url( '/agents/' ),
				'restBase'    => home_url( '/wp-json/neoweaver/v1' ),
				'supabaseUrl' => function_exists( 'tw_supabase_url' )      ? tw_supabase_url()      : '',
				'supabaseKey' => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
			]
		);
	}

	// =========================================================================
	// PRIVATE HELPERS
	// =========================================================================

	/**
	 * Wrap any shortcode HTML in the mandatory .neoweaver-screen container.
	 */
	private function screen( string $html ): string {
		return '<div class="neoweaver-screen">' . $html . '</div>';
	}

	/**
	 * Load a template partial and capture its output.
	 *
	 * The partial receives a single $tw_data array in its local scope so it
	 * never needs to reach into global state.
	 *
	 * @param string $partial  Relative path under templates/partials/, e.g. 'character-creator.php'.
	 * @param array  $tw_data  Variables available inside the partial as $tw_data['key'].
	 * @return string  Rendered HTML, or an error comment on failure.
	 */
	private function load_template( string $partial, array $tw_data = [] ): string {
		$path = get_stylesheet_directory() . '/templates/partials/' . $partial;

		if ( ! file_exists( $path ) ) {
			return '<!-- Neoweaver: missing partial ' . esc_html( $partial ) . ' -->';
		}

		ob_start();
		( static function ( $tw_data, $__path ) {
			extract( [ 'tw_data' => $tw_data ], EXTR_SKIP );
			include $__path;
		} )( $tw_data, $path );

		return ob_get_clean() ?: '';
	}

	// =========================================================================
	// SHORTCODE: character list
	// =========================================================================

	/**
	 * [tw_list_characters]
	 * Renders the full agent roster for the currently logged-in Operator.
	 */
	public function shortcode_list_characters(): string {
		if ( is_admin() ) {
			return '';
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $this->screen( '<p>Please log in.</p>' );
		}
		return $this->screen( $this->agents_list->render_roster( $user_id ) );
	}

	// =========================================================================
	// SHORTCODE: character creator
	// =========================================================================

	public function shortcode_character_creator(): string {
		return neoweaver_shortcode_character_creator();
	}

	// =========================================================================
	// SHORTCODE: campaign / deployment creator
	// =========================================================================

	public function shortcode_campaign_creator(): string {
		return neoweaver_shortcode_campaign_creator();
	}

	// =========================================================================
	// SHORTCODE: node / world creator
	// =========================================================================

	public function shortcode_world_creator(): string {
		return neoweaver_shortcode_world_creator();
	}

	// =========================================================================
	// SHORTCODE: active node display
	// =========================================================================

	public function shortcode_active_node(): string {
		if ( ! get_current_user_id() ) {
			return '<span id="node-name-display">NO_UPLINK</span>';
		}
		return '<span id="node-name-display">LOADING_NODE...</span>';
	}

	// =========================================================================
	// FOOTER SCRIPT: Quick Actions Bridge (game page only)
	// =========================================================================

	public function enqueue_quick_actions_bridge(): void {
		if ( ! is_page_template( 'templates/adventure.php' ) ) {
			return;
		}
		?>
		<script>
		(function () {
			function updateQuickActionsFromHand(cards) {
				const tags = (cards || []).flatMap((c) =>
					(c.tags || '')
						.split(',')
						.map((t) => t.trim())
						.filter(Boolean)
				);
				if (window.twUpdatePlayerTags) {
					window.twUpdatePlayerTags(tags);
				} else {
					console.warn('twUpdatePlayerTags is not defined – quick actions bridge has nothing to call.');
				}
			}
			window.twQuickActionsBridge = {
				updateFromCards: updateQuickActionsFromHand,
			};
		})();
		</script>
		<?php
	}

	// =========================================================================
	// FOOTER SCRIPT: Tag Update Popup (game page only)
	// =========================================================================

	public function render_tag_update_popup(): void {
		if ( ! is_page( 2857 ) ) {
			return;
		}
		?>
		<script>
		function showTagUpdate(tagName, isSuccess = true) {
			const popup = document.createElement('div');
			popup.className = `tag-update-popup ${isSuccess ? '' : 'failure'}`;
			popup.innerHTML = `
				<span class="tag-label">// DATA SYNC: NEW ECHO TAG ACQUIRED</span>
				<span class="tag-name"></span>
			`;
			popup.querySelector('.tag-name').textContent = tagName;
			document.body.appendChild(popup);

			if (window.jQuery) {
				jQuery(popup).fadeIn(300).delay(3000).fadeOut(500, function() {
					this.remove();
				});
			} else {
				popup.style.opacity = '1';
				setTimeout(() => {
					popup.style.transition = 'opacity 0.5s';
					popup.style.opacity = '0';
					setTimeout(() => popup.remove(), 500);
				}, 3000);
			}
		}
		</script>
		<?php
	}
}
