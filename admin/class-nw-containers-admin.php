<?php
/**
 * NeoWeaver – Containers Admin
 *
 * Handles the admin UI for the Containers (Node definitions) CPT.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NW_Containers_Admin {

	private string $page_slug = 'nw-containers';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_container_save',   [ $this, 'ajax_save'   ] );
		add_action( 'wp_ajax_nw_container_delete', [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_container_list',   [ $this, 'ajax_list'   ] );
	}

	public function register_submenu(): void {
		add_submenu_page(
			'neoweaver',
			__( 'Containers', 'neoweaver' ),
			__( '📦 Containers', 'neoweaver' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) return;

		$plugin_url = plugin_dir_url( dirname( __FILE__ ) );

		if ( ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
		wp_enqueue_style(
			'chakra-petch',
			'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
			[],
			null
		);
		}

		wp_enqueue_style(
			'nw-admin-core',
			$plugin_url . 'assets/css/nw-admin-core.css',
			[ 'chakra-petch' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_style(
			'nw-containers-style',
			$plugin_url . 'assets/css/containers-admin.css',
			[ 'chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'nw-containers-script',
			$plugin_url . 'assets/js/containers-admin.js',
			[ 'jquery' ],
			NEOWEAVER_VERSION,
			true
		);

		wp_localize_script( 'nw-containers-script', 'NWContainers', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'nw_containers_nonce' ),
		] );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX – save                                                        */
	/* ------------------------------------------------------------------ */

	public function ajax_save(): void {
		check_ajax_referer( 'nw_containers_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name    = sanitize_text_field( $_POST['name']    ?? '' );
		$slug    = sanitize_key(        $_POST['slug']    ?? '' );
		$desc    = sanitize_textarea_field( $_POST['description'] ?? '' );
		$cap     = absint( $_POST['capacity'] ?? 0 );
		$world   = absint( $_POST['world_id'] ?? 0 );
		$tags    = array_map( 'absint', (array) ( $_POST['tags'] ?? [] ) );

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_containers';

		$data = [
			'name'        => $name,
			'slug'        => $slug,
			'description' => $desc,
			'capacity'    => $cap,
			'world_id'    => $world ?: null,
		];

		if ( $id ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
		} else {
			$wpdb->insert( $table, $data );
			$id = $wpdb->insert_id;
		}

		// sync tags
		$tag_table = $wpdb->prefix . 'cyber_container_tags';
		$wpdb->delete( $tag_table, [ 'container_id' => $id ] );
		foreach ( $tags as $tid ) {
			$wpdb->insert( $tag_table, [ 'container_id' => $id, 'tag_id' => $tid ] );
		}

		wp_send_json_success( [ 'id' => $id ] );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX – delete                                                      */
	/* ------------------------------------------------------------------ */

	public function ajax_delete(): void {
		check_ajax_referer( 'nw_containers_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) wp_send_json_error( 'Invalid ID' );

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'cyber_container_tags', [ 'container_id' => $id ] );
		$wpdb->delete( $wpdb->prefix . 'cyber_containers',     [ 'id'           => $id ] );

		wp_send_json_success();
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX – list                                                        */
	/* ------------------------------------------------------------------ */

	public function ajax_list(): void {
		check_ajax_referer( 'nw_containers_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT c.*, GROUP_CONCAT(ct.tag_id) AS tag_ids
			 FROM {$wpdb->prefix}cyber_containers c
			 LEFT JOIN {$wpdb->prefix}cyber_container_tags ct ON ct.container_id = c.id
			 GROUP BY c.id
			 ORDER BY c.name ASC"
		);

		wp_send_json_success( $rows );
	}

	/* ------------------------------------------------------------------ */
	/*  Page HTML                                                          */
	/* ------------------------------------------------------------------ */

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		?>
		<div class="wrap nw-containers-wrap">
			<h1><?php esc_html_e( 'NeoWeaver – Containers', 'neoweaver' ); ?></h1>
			<div id="nw-containers-app">
				<p><?php esc_html_e( 'Loading…', 'neoweaver' ); ?></p>
			</div>
		</div>
		<?php
	}
}
