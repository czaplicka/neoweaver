<?php
/**
 * NeoWeaver Admin — Bootstrap
 *
 * Loaded explicitly (before glob) by NeoWeaver_Core::load_admin_files().
 * Instantiates all admin subpage classes after the root menu (NeoWeaver_Admin)
 * is already registered, so every add_submenu_page() call finds the parent slug.
 *
 * Add new admin classes here — do NOT put `new ClassName()` at the bottom
 * of individual admin files.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List of admin class files to require and classes to instantiate.
 *
 * Format: 'filename.php' => 'ClassName'
 * Files are relative to the admin/ directory.
 * Order matters for loading, but NOT reliably for final submenu order.
 *
 * IMPORTANT: every file listed here MUST NOT call `new ClassName()` or
 * `add_action( 'plugins_loaded', ... )` at the bottom of the file itself.
 * Bootstrap owns all instantiation. Individual files: class definition only.
 */
final class NW_Admin_Bootstrap {

	private const PARENT_SLUG = 'neo-weaver';

	private const MODULES = [
		// Root menu & dashboard — must be first.
		'admin.php'              => 'NeoWeaver_Admin',

		// Subpages.
		'achievements.php'       => 'NeoWeaver_Achievements_Admin',
		'abilities.php'          => 'NW_Abilities_Admin',
		'classes.php'            => 'NW_Classes_Admin',
		'containers.php'         => 'NeoWeaver_Containers_Admin',
		'deck.php'               => 'NW_Deck_Admin',
		'items.php'              => 'NW_Items_Admin',
		'races.php'              => 'NeoWeaver_Races_Admin',
		'scenarios.php'          => 'NeoWeaver_Scenarios_Admin',
		'seasons.php'            => 'NeoWeaver_Seasons_Admin',
		'skills.php'             => 'NW_Skills_Admin',
		'starting-packages.php'  => 'NeoWeaver_Starting_Packages_Admin',
		'status-tags.php'        => 'NW_Status_Tags_Admin',
		'style-dictionary.php'   => 'NeoWeaver_Style_Dictionary_Admin',
		'widget.php'             => 'NeoWeaver_Stats_Widget',
		'world-tag-defs.php'     => 'NeoWeaver_World_Tag_Defs_Admin',
	];

	/**
	 * Desired final submenu order by menu slug.
	 *
	 * IMPORTANT:
	 * - these slugs must match the actual $menu_slug values used in add_menu_page()/add_submenu_page()
	 * - the parent slug entry should be first if you want the dashboard/root page first
	 */
	private const SUBMENU_ORDER = [
		'neo-weaver',
		'neo-weaver-achievements',
		'neo-weaver-abilities',
		'neo-weaver-classes',
		'neo-weaver-containers',
		'neo-weaver-deck',
		'neo-weaver-items',
		'neo-weaver-races',
		'neo-weaver-scenarios',
		'neo-weaver-seasons',
		'neo-weaver-skills',
		'neo-weaver-starting-packages',
		'neo-weaver-status-tags',
		'neo-weaver-style-dictionary',
		'neo-weaver-widget',
		'neo-weaver-world-tag-defs',
	];

	public static function init(): void {
		foreach ( self::MODULES as $file => $class ) {
			$path = NW_PLUGIN_DIR . 'admin/' . $file;

			if ( ! file_exists( $path ) ) {
				continue;
			}

			require_once $path;

			if ( $class && class_exists( $class, false ) && ! isset( $GLOBALS[ 'nw_admin_inst_' . $class ] ) ) {
				$GLOBALS[ 'nw_admin_inst_' . $class ] = new $class();
			}
		}

		add_action( 'admin_menu', [ __CLASS__, 'reorder_submenu' ], 999 );
	}

	/**
	 * Force final submenu order under the NeoWeaver parent menu.
	 */
	public static function reorder_submenu(): void {
		global $submenu;

		if ( empty( $submenu[ self::PARENT_SLUG ] ) || ! is_array( $submenu[ self::PARENT_SLUG ] ) ) {
			return;
		}

		$current_items = $submenu[ self::PARENT_SLUG ];
		$ordered_items = [];
		$remaining_items = [];

		foreach ( self::SUBMENU_ORDER as $wanted_slug ) {
			foreach ( $current_items as $index => $item ) {
				$item_slug = isset( $item[2] ) ? (string) $item[2] : '';

				if ( $item_slug === $wanted_slug ) {
					$ordered_items[] = $item;
					unset( $current_items[ $index ] );
					break;
				}
			}
		}

		if ( ! empty( $current_items ) ) {
			foreach ( $current_items as $item ) {
				$remaining_items[] = $item;
			}
		}

		$submenu[ self::PARENT_SLUG ] = array_values(
			array_merge( $ordered_items, $remaining_items )
		);
	}
}

// Klasa bazowa musi być załadowana przed wszystkimi modułami admin.
require_once NW_PLUGIN_DIR . 'admin/class-base-admin.php';

NW_Admin_Bootstrap::init();
