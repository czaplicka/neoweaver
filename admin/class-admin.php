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
 * Order matters: admin.php (root menu) MUST come first so the parent slug
 * exists before any add_submenu_page() calls in the modules below.
 *
 * IMPORTANT: every file listed here MUST NOT call `new ClassName()` or
 * `add_action( 'plugins_loaded', ... )` at the bottom of the file itself.
 * Bootstrap owns all instantiation. Individual files: class definition only.
 */
final class NW_Admin_Bootstrap {

	private const MODULES = [
		// Root menu & dashboard — must be first.
		'admin.php'             => 'NeoWeaver_Admin',
		// Subpages (alphabetical after the root).
		'achievements.php'      => 'NeoWeaver_Achievements_Admin',
		'abilities.php'         => 'NW_Abilities_Admin',
		'classes.php'           => 'NW_Classes_Admin',
		'containers.php'        => 'NeoWeaver_Containers_Admin',
		'deck.php'              => 'NW_Deck_Admin',
		'items.php'             => 'NW_Items_Admin',
		'races.php'             => 'NeoWeaver_Races_Admin',
		'scenarios.php'         => 'NeoWeaver_Scenarios_Admin',
		'seasons.php'           => 'NeoWeaver_Seasons_Admin',
		'skills.php'            => 'NW_Skills_Admin',
		'starting-packages.php' => 'NeoWeaver_Starting_Packages_Admin',
		'status-tags.php'       => 'NW_Status_Tags_Admin',
		'style-dictionary.php'  => 'NeoWeaver_Style_Dictionary_Admin',
		'widget.php'            => 'NeoWeaver_Stats_Widget',
		'world-tag-defs.php'    => 'NeoWeaver_World_Tag_Defs_Admin',
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
	}
}

NW_Admin_Bootstrap::init();
