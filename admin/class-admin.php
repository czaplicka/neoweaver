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
 * Order matters only if one class depends on another (rare).
 */
final class NW_Admin_Bootstrap {

	private const MODULES = [
		'achievements.php'        => 'NeoWeaver_Achievements_Admin',
		'abilities.php'           => 'NW_Abilities_Admin',
		'classes.php'             => null, // TODO: add class name when refactored
		'containers.php'          => null,
		'deck.php'                => null,
		'items.php'               => null,
		'races.php'               => null,
		'scenarios.php'           => null,
		'seasons.php'             => null,
		'skills.php'              => null,
		'starting-packages.php'   => null,
		'status-tags.php'         => null,
		'style-dictionary.php'    => null,
		'widget.php'              => null,
		'world-tag-defs.php'      => null,
	];

	public static function init(): void {
		foreach ( self::MODULES as $file => $class ) {
			$path = NW_PLUGIN_DIR . 'admin/' . $file;

			if ( ! file_exists( $path ) ) {
				continue;
			}

			require_once $path;

			if ( $class && class_exists( $class ) ) {
				new $class();
			}
		}
	}
}

NW_Admin_Bootstrap::init();
