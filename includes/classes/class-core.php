<?php
/**
 * Core orchestrator — wires up admin and public components.
 *
 * @package NeoWeaver_WP_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NEOWEAVER_PLUGIN_DIR . 'admin/class-neoweaver-admin.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/class-neoweaver-public.php';

/**
 * Class NeoWeaver_Core
 */
class NeoWeaver_Core {

	private NeoWeaver_Loader $loader;
	private string           $plugin_slug = 'neoweaver-wp-core';
	private string           $version;

	public function __construct() {
		$this->version = NEOWEAVER_VERSION;
		$this->loader  = new NeoWeaver_Loader();

		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/** Register all admin-side hooks. */
	private function define_admin_hooks(): void {
		$admin = new NeoWeaver_Admin( $this->plugin_slug, $this->version );

		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu',            $admin, 'add_admin_menu' );
	}

	/** Register all front-end hooks. */
	private function define_public_hooks(): void {
		// BUG-FIX: NeoWeaver_Public constructor requires four typed objects:
		//   Neoweaver_Agents_List, Neoweaver_Agents_Creator,
		//   Neoweaver_Deployments_Creator, Neoweaver_Nodes_Creator.
		// The previous code passed ( $this->plugin_slug, $this->version ) —
		// two plain strings — which caused a fatal TypeError on every page load.
		$repository          = new Neoweaver_Agents_Repository();
		$agents_list         = new Neoweaver_Agents_List( $repository );
		$agents_creator      = new Neoweaver_Agents_Creator();
		$deployments_creator = new Neoweaver_Deployments_Creator();
		$nodes_creator       = new Neoweaver_Nodes_Creator();

		$front = new NeoWeaver_Public(
			$agents_list,
			$agents_creator,
			$deployments_creator,
			$nodes_creator
		);

		$this->loader->add_action( 'wp_enqueue_scripts', $front, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $front, 'enqueue_scripts' );
	}

	/** Fire all registered hooks. */
	public function run(): void {
		$this->loader->run();
	}

	public function get_plugin_slug(): string { return $this->plugin_slug; }
	public function get_loader(): NeoWeaver_Loader { return $this->loader; }
	public function get_version(): string { return $this->version; }
}
