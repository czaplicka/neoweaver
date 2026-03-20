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

	// ── Transient TTL (seconds) for campaign-creator lookup lists ──────────
	private const CAMPAIGN_CACHE_TTL = 60;

	public function __construct(
		Neoweaver_Agents_List $agents_list,
		Neoweaver_Agents_Creator $agents_creator,
		Neoweaver_Deployments_Creator $deployments_creator,
		Neoweaver_Nodes_Creator $nodes_creator
	) {
		$this->agents_list          = $agents_list;
		$this->agents_creator       = $agents_creator;
		$this->deployments_creator  = $deployments_creator;
		$this->nodes_creator        = $nodes_creator;

		add_shortcode( 'tw_list_characters',            [ $this, 'shortcode_list_characters' ] );
		add_shortcode( 'tale_weaver_character_creator', [ $this, 'shortcode_character_creator' ] );
		add_shortcode( 'tw_create_campaign',            [ $this, 'shortcode_campaign_creator' ] );
		add_shortcode( 'tw_world_creator',              [ $this, 'shortcode_world_creator' ] );
		add_shortcode( 'tw_active_node',                [ $this, 'shortcode_active_node' ] );

		add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'wp_footer',           [ $this, 'enqueue_quick_actions_bridge' ] );
		add_action( 'wp_footer',           [ $this, 'render_tag_update_popup' ] );
	}

	// =========================================================================
	// ASSET REGISTRATION
	// =========================================================================

	/**
	 * Enqueue per-wizard CSS and JS on the front-end.
	 *
	 * Each wizard gets its own stylesheet and script file so browsers can cache
	 * them independently.  All files live under the child-theme's assets/
	 * directory and are versioned via filemtime() for automatic cache-busting.
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$theme_uri = get_stylesheet_directory_uri();
		$theme_dir = get_stylesheet_directory();

		$assets = [
			// [ handle, css-path, js-path, js-deps, js-in-footer ]
			[
				'neoweaver-char-creator',
				'/assets/css/tw-character-creator.css',
				'/assets/js/tw-character-creator.js',
				[ 'jquery' ],
				true,
			],
			[
				'neoweaver-campaign-creator',
				'/assets/css/tw-campaign-creator.css',
				'/assets/js/tw-campaign-creator.js',
				[],
				true,
			],
			[
				'neoweaver-world-creator',
				'/assets/css/tw-world-creator.css',
				'/assets/js/tw-world-creator.js',
				[],
				true,
			],
		];

		foreach ( $assets as [ $handle, $css_rel, $js_rel, $deps, $in_footer ] ) {
			$css_file = $theme_dir . $css_rel;
			$js_file  = $theme_dir . $js_rel;

			if ( file_exists( $css_file ) ) {
				wp_enqueue_style(
					$handle,
					$theme_uri . $css_rel,
					[ 'neoweaver-public' ],
					(string) filemtime( $css_file )
				);
			}

			if ( file_exists( $js_file ) ) {
				wp_enqueue_script(
					$handle,
					$theme_uri . $js_rel,
					$deps,
					(string) filemtime( $js_file ),
					$in_footer
				);
			}
		}
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
			// Surface a visible dev-only hint; harmless in production (outputs nothing visible).
			return '<!-- Neoweaver: missing partial ' . esc_html( $partial ) . ' -->';
		}

		ob_start();
		// Import $tw_data into the partial's local scope via extract — all
		// keys are prefixed with 'tw_' in the callers to prevent collisions.
		( static function ( $tw_data, $__path ) {
			extract( [ 'tw_data' => $tw_data ], EXTR_SKIP ); // expose as $tw_data
			include $__path;
		} )( $tw_data, $path );

		return ob_get_clean() ?: '';
	}

	/**
	 * Fetch a Supabase REST resource with optional per-user transient caching.
	 *
	 * @param string $table      Table name (appended to the REST base URL).
	 * @param array  $query_args Query-string parameters (e.g. ['select' => 'id,name']).
	 * @param int    $user_id    Cache key component; 0 = no caching.
	 * @param int    $ttl        Transient lifetime in seconds.
	 * @return array  Decoded JSON rows, or an empty array on failure.
	 */
	private function supabase_get( string $table, array $query_args, int $user_id = 0, int $ttl = 0 ): array {
		$cache_key = $ttl > 0 && $user_id > 0
			? 'tw_sb_' . $user_id . '_' . md5( $table . serialize( $query_args ) )
			: '';

		if ( $cache_key ) {
			$cached = get_transient( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		$url_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$anon_key = tw_supabase_anon_key();
		$headers  = [
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
		];

		$response = wp_remote_get(
			add_query_arg( $query_args, $url_base . $table ),
			[ 'headers' => $headers ]
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$reason = is_wp_error( $response )
				? $response->get_error_message()
				: wp_remote_retrieve_response_code( $response );
			error_log( "NeoWeaver: Supabase fetch failed for '{$table}' – {$reason}" );
			return [];
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];

		if ( $cache_key ) {
			set_transient( $cache_key, $rows, $ttl );
		}

		return $rows;
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

	/**
	 * [tale_weaver_character_creator]
	 *
	 * Renders the 9-step character creation wizard.
	 *
	 * RENDER-ONLY. The form submits via fetch() to the theme endpoint at
	 * {stylesheet_dir}/endpoint/tw-endpoint-character.php.
	 *
	 * BUG FIX #8:
	 *   The old selectClass() JS inferred the skill limit from the visible
	 *   <strong> class-name text (hardcoded "PSYCHIC" check). That broke
	 *   whenever a class was renamed in Supabase.
	 *
	 *   Fix: loadClasses() now stores each class's skill_limit value as a
	 *   data-skill-limit attribute on the radio <input>.  selectClass() reads
	 *   that attribute — no string comparison needed, and any class can have
	 *   any limit set directly in Supabase.
	 *
	 *   The JS change lives in assets/js/tw-character-creator.js.
	 *   This method only passes the nonce and endpoint URL through wp_localize_script().
	 *
	 * CSS scope: .neoweaver-screen #tw-char-creator  (tw-character-creator.css)
	 * JS file:   assets/js/tw-character-creator.js   (enqueued by enqueue_assets())
	 */
	public function shortcode_character_creator(): string {
		if ( ! is_user_logged_in() ) {
			return $this->screen( '<div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div>' );
		}

		$nonce    = wp_create_nonce( 'tw_character_nonce' );
		$endpoint = get_stylesheet_directory_uri() . '/endpoint/tw-endpoint-character.php';

		// Pass nonce and endpoint to the already-enqueued JS file.
		wp_localize_script(
			'neoweaver-char-creator',
			'twCharCreatorConfig',
			[
				'nonce'    => $nonce,
				'endpoint' => $endpoint,
			]
		);

		// Attribute definitions rendered server-side so the PHP loop stays in PHP.
		$attrs = [
			'body'   => [ 'BODY (STR+CON)',    'Brute force, health pool, heavy lifting.' ],
			'reflex' => [ 'REFLEX (DEX)',       'Speed, evasion, precision aiming.' ],
			'mind'   => [ 'MIND (INT+WIS)',     'Logic, repair, investigation, awareness.' ],
			'spirit' => [ 'SPIRIT (CHA+WILL)', 'Magic power, persuasion, willpower.' ],
		];

		$html = $this->load_template( 'character-creator.php', [
			'attrs' => $attrs,
		] );

		return $this->screen( $html );
	}

	// =========================================================================
	// SHORTCODE: campaign / deployment creator
	// =========================================================================

	/**
	 * [tw_create_campaign]
	 *
	 * Renders the 8-step deployment (campaign) creation wizard.
	 *
	 * OPTIMISATION 1:
	 *   The two Supabase look-up lists (worlds, characters) are now cached per
	 *   user with a short transient (CAMPAIGN_CACHE_TTL seconds).  On a warm
	 *   cache this page renders with zero outbound HTTP calls.  The transient
	 *   key includes the user ID so different operators never see each other's
	 *   data.
	 *
	 * CSS scope: .neoweaver-screen #tw-campaign-creator-container
	 * JS file:   assets/js/tw-campaign-creator.js
	 */
	public function shortcode_campaign_creator(): string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $this->screen( '<p class="tw-error">UPLINK REQUIRED. LOG IN.</p>' );
		}

		// OPTIMISATION 1 — cached Supabase look-ups.
		$worlds     = $this->supabase_get(
			'cyber_worlds',
			[ 'wp_user_id' => 'eq.' . $user_id, 'select' => 'id,name' ],
			$user_id,
			self::CAMPAIGN_CACHE_TTL
		);
		$characters = $this->supabase_get(
			'cyber_characters',
			[ 'wp_user_id' => 'eq.' . $user_id, 'select' => 'id,name' ],
			$user_id,
			self::CAMPAIGN_CACHE_TTL
		);

		$campaign_nonce = wp_create_nonce( 'tw_campaign_nonce' );

		wp_localize_script(
			'neoweaver-campaign-creator',
			'twCampaignConfig',
			[ 'nonce' => $campaign_nonce ]
		);

		$html = $this->load_template( 'campaign-creator.php', [
			'worlds'     => $worlds,
			'characters' => $characters,
		] );

		return $this->screen( $html );
	}

	// =========================================================================
	// SHORTCODE: node / world creator
	// =========================================================================

	/**
	 * [tw_world_creator]
	 *
	 * Renders the 11-step Node (World) creation wizard.
	 *
	 * CSS scope: .neoweaver-screen #tw-world-creator-container
	 * JS file:   assets/js/tw-world-creator.js
	 */
	public function shortcode_world_creator(): string {
		if ( ! is_user_logged_in() ) {
			return $this->screen( '<div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div>' );
		}

		$nonce    = wp_create_nonce( 'tw_world_nonce' );
		$endpoint = get_stylesheet_directory_uri() . '/endpoint/tw-endpoint-world.php';
		$nodes_url = home_url( '/nodes/' );

		wp_localize_script(
			'neoweaver-world-creator',
			'twWorldCreatorConfig',
			[
				'nonce'     => $nonce,
				'endpoint'  => $endpoint,
				'nodesUrl'  => $nodes_url,
			]
		);

		// World-step option definitions stay in PHP — they are static config
		// with no business logic, and keeping them here lets translators use
		// standard WP i18n functions in the future without touching JS.
		$world_steps = [
			3  => [ 'WORLD_SIZE',     'Define expansion magnitude',  [ ['Local Node','A single, dense micro-world.'], ['Few Nodes','A vast region.'], ['Multi Nodes','Full nodes simulation.'], ['World','Multiple systems.'], ['Infinite','Infinite reality stream.'] ],          'size'       ],
			4  => [ 'NODE_ECONOMY',   'Resource availability',       [ ['Frayed','Survival is a miracle.'], ['Scarcity','Basic scavenge economy.'], ['Balanced','Stable commerce.'], ['Wealthy','High consumerism.'], ['Abundant','Digital abundance.'] ],                       'wealth'     ],
			5  => [ 'ENTROPY_DANGER', 'Entropy & Threat Rate',       [ ['Coherent','Stable world.'], ['Stable','Manageable threats.'], ['Unstable','Standard risks.'], ['Critical','The Fray is strong.'], ['Catastrophic','Systemic collapse.'] ],                             'difficulty' ],
			6  => [ 'NODE_MAGIC',     'Weave Permeability',          [ ['None','Strict logic.'], ['Glitched','Rare anomalies.'], ['Standard','Standard utility.'], ['High','Reality is soft.'], ['Extreme','Chaos rules.'] ],                                                   'magic'      ],
			7  => [ 'NODE_GODS',      'Higher Protocols / Admins',   [ ['Absent','No entities.'], ['Echoes','Forgotten Admins.'], ['Observers','Silent code.'], ['Active','Demanding data.'], ['Manifested','God-AI active.'] ],                                                'gods'       ],
			8  => [ 'NODE_TECH',      'Technological Anchor',        [ ['Retro','Analog/CRT, late \'90.'], ['Modern','Networked. Today'], ['Advanced','Cybernetics. Tomorrow'], ['Future','Sentient AI. Close future'], ['Transcendent','Post-human. Apocalyptic future'] ],    'technology' ],
			9  => [ 'NODE_SOCIAL',    'Thread interaction',          [ ['Hostile','Tribal survival.'], ['Strained','Faction tension.'], ['Pragmatic','Uneasy peace.'], ['Integrated','Common goals.'], ['Unified','Hive-mind.'] ],                                               'relations'  ],
			10 => [ 'NODE_MORALITY',  'Ethical Framework',           [ ['Chaotic','Fittest survives.'], ['Gray','Ambiguity.'], ['Lawful','Strict codes.'] ],                                                                                                                     'moral'      ],
		];

		$html = $this->load_template( 'world-creator.php', [
			'world_steps' => $world_steps,
		] );

		return $this->screen( $html );
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

	/**
	 * Outputs the twQuickActionsBridge script in wp_footer,
	 * only on pages using the adventure template.
	 */
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

	/**
	 * Outputs showTagUpdate() JS helper in wp_footer, only on page ID 2857.
	 */
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
