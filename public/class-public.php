<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Neoweaver_Public', false ) ) :

class Neoweaver_Public {

	protected Neoweaver_Agents_List $agents_list;
	protected Neoweaver_Deployments_Creator $deployments_creator;
	protected Neoweaver_Nodes_Creator $nodes_creator;

	public function __construct(
		Neoweaver_Agents_List $agents_list,
		Neoweaver_Deployments_Creator $deployments_creator,
		Neoweaver_Nodes_Creator $nodes_creator
	) {
		$this->agents_list         = $agents_list;
		$this->deployments_creator = $deployments_creator;
		$this->nodes_creator       = $nodes_creator;

		add_shortcode( 'tw_list_characters',            [ $this, 'shortcode_list_characters' ] );
		add_shortcode( 'tale_weaver_character_creator', [ $this, 'shortcode_character_creator' ] );
		add_shortcode( 'tw_create_campaign',            [ $this, 'shortcode_campaign_creator' ] );
		add_shortcode( 'tw_world_creator',              [ $this, 'shortcode_world_creator' ] );
		add_shortcode( 'tw_active_node',                [ $this, 'shortcode_active_node' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	private static function asset_version( string $absolute_path ): string {
		return file_exists( $absolute_path )
			? (string) filemtime( $absolute_path )
			: NEOWEAVER_VERSION;
	}

	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$url = trailingslashit( NEOWEAVER_PLUGIN_URL );
		$dir = trailingslashit( NEOWEAVER_PLUGIN_DIR );

		Neoweaver_Agents_List::enqueue_assets();

		if ( wp_script_is( 'neoweaver-char-creator', 'registered' ) || wp_script_is( 'neoweaver-char-creator', 'enqueued' ) ) {
			wp_localize_script(
				'neoweaver-char-creator',
				'twCharCreatorConfig',
				[
					'nonce'       => wp_create_nonce( 'neoweaver_nonce' ),
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
					'restNonce'   => wp_create_nonce( 'wp_rest' ),
					'restUrl'     => home_url( '/wp-json/neoweaver/v1/character/create' ),
					'agentsUrl'   => home_url( '/agents/' ),
					'restBase'    => home_url( '/wp-json/neoweaver/v1' ),
					'supabaseUrl' => function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '',
					'supabaseKey' => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
				]
			);
		}

		if ( is_page_template( 'templates/adventure.php' ) ) {
			$script_rel  = 'assets/js/public/class-public.js';
			$script_path = $dir . $script_rel;
			$script_url  = $url . $script_rel;

			wp_enqueue_script(
				'neoweaver-public-runtime',
				$script_url,
				[],
				self::asset_version( $script_path ),
				true
			);
		}
	}

	public static function enqueue_public_assets(): void {
		wp_enqueue_script(
			'nw-lucide-public',
			'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
			[],
			'0.468.0',
			true
		);

		wp_enqueue_style(
			'neoweaver-public',
			NEOWEAVER_PLUGIN_URL . 'assets/css/public/public.css',
			[],
			self::asset_version( NEOWEAVER_PLUGIN_DIR . 'assets/css/public/public.css' )
		);

		wp_enqueue_script(
			'neoweaver-public',
			NEOWEAVER_PLUGIN_URL . 'assets/js/public/public.js',
			[ 'jquery', 'nw-lucide-public' ],
			self::asset_version( NEOWEAVER_PLUGIN_DIR . 'assets/js/public/public.js' ),
			true
		);
	}

	private function screen( string $html ): string {
		return '<div class="neoweaver-screen">' . $html . '</div>';
	}

	private function load_template( string $partial, array $tw_data = [] ): string {
		$path = get_stylesheet_directory() . '/templates/partials/' . $partial;

		if ( ! file_exists( $path ) ) {
			return '<!-- Neoweaver: missing partial ' . esc_html( $partial ) . ' -->';
		}

		ob_start();
		(
			static function ( array $tw_data, string $__path ): void {
				extract( [ 'tw_data' => $tw_data ], EXTR_SKIP );
				include $__path;
			}
		)( $tw_data, $path );

		return ob_get_clean() ?: '';
	}

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

	public function shortcode_character_creator(): string {
		if ( ! function_exists( 'neoweaver_shortcode_character_creator' ) ) {
			return $this->screen( '<!-- Neoweaver: shortcode-character-creator.php not loaded -->' );
		}

		return neoweaver_shortcode_character_creator();
	}

	public function shortcode_campaign_creator(): string {
		if ( ! function_exists( 'neoweaver_shortcode_campaign_creator' ) ) {
			return $this->screen( '<!-- Neoweaver: shortcode-campaign-creator.php not loaded -->' );
		}

		return neoweaver_shortcode_campaign_creator();
	}

	public function shortcode_world_creator(): string {
		if ( ! function_exists( 'neoweaver_shortcode_world_creator' ) ) {
			return $this->screen( '<!-- Neoweaver: shortcode-world-creator.php not loaded -->' );
		}

		return neoweaver_shortcode_world_creator();
	}

	public function shortcode_active_node(): string {
		if ( ! get_current_user_id() ) {
			return '<span id="node-name-display">NO_UPLINK</span>';
		}

		return '<span id="node-name-display">LOADING_NODE...</span>';
	}
}

endif;
