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
