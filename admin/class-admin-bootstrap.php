<?php
/**
 * NeoWeaver Admin — Bootstrap
 *
 * Ładuje root menu i wszystkie ekrany admina, a potem porządkuje submenu.
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NW_Admin_Bootstrap {

private const PARENT_SLUG = 'neoweaver';

	/**
	 * Finalny, pojedynczy rejestr modułów admin.
	 *
	 * slug = rzeczywisty menu slug użyty w add_menu_page/add_submenu_page
	 *
	 * @var array<int,array<string,string|bool>>
	 */
	private const MODULES = [
		[
			'file'  => 'admin-dashboard.php',
			'class' => 'NeoWeaver_Admin',
			'slug'  => 'neoweaver',
			'root'  => true,
		],
		[
			'file'  => 'abilities.php',
			'class' => 'NWAbilitiesAdmin',
			'slug'  => 'nw-abilities',
			'root'  => false,
		],
		[
			'file'  => 'achievements.php',
			'class' => 'NeoWeaver_Achievements_Admin',
			'slug'  => 'nw-achievements',
			'root'  => false,
		],
		[
			'file'  => 'action-tags.php',
			'class' => 'NW_Action_Tags_Admin',
			'slug'  => 'nw-action-tags',
			'root'  => false,
		],
		[
			'file'  => 'classes.php',
			'class' => 'NW_Classes_Admin',
			'slug'  => 'nw-classes',
			'root'  => false,
		],
		[
			'file'  => 'containers.php',
			'class' => 'NW_Containers_Admin',
			'slug'  => 'nw-containers',
			'root'  => false,
		],
		[
			'file'  => 'deck.php',
			'class' => 'NW_Admin_Deck',
			'slug'  => 'nw-deck',
			'root'  => false,
		],
		[
			'file'  => 'items.php',
			'class' => 'NW_Items_Admin',
			'slug'  => 'nw-items',
			'root'  => false,
		],
		[
			'file'  => 'races.php',
			'class' => 'NWRacesAdmin',
			'slug'  => 'nw-races',
			'root'  => false,
		],
		[
			'file'  => 'scenarios.php',
			'class' => 'NWScenariosAdmin',
			'slug'  => 'nw-scenarios',
			'root'  => false,
		],
		[
			'file'  => 'seasons.php',
			'class' => 'NWSeasonsAdmin',
			'slug'  => 'nw-seasons',
			'root'  => false,
		],
		[
			'file'  => 'skills.php',
			'class' => 'NWSkillsAdmin',
			'slug'  => 'nw-skills',
			'root'  => false,
		],
			[
			'file'  => 'starting-cards.php',
			'class' => 'NWStartingPackagesAdmin',
			'slug'  => 'nw-class-starting-cards',
			'root'  => false,
		],
		[
			'file'  => 'starting-packages.php',
			'class' => 'NWStartingPackagesAdmin',
			'slug'  => 'nw-starting-packages',
			'root'  => false,
		],
		[
			'file'  => 'status-tags.php',
			'class' => 'NWStatusTagsAdmin',
			'slug'  => 'nw-status-tags',
			'root'  => false,
		],
		[
			'file'  => 'style-dictionary.php',
			'class' => 'NWStyleDictionaryAdmin',
			'slug'  => 'nw-style-dictionary',
			'root'  => false,
		],
		[
			'file'  => 'widget.php',
			'class' => 'NeoWeaver_Stats_Widget',
			'slug'  => 'nw-widget',
			'root'  => false,
		],
		[
			'file'  => 'world-tag-defs.php',
			'class' => 'NWWorldTagDefsAdmin',
			'slug'  => 'nw-world-tag-defs',
			'root'  => false,
		],
		[
			'file'  => 'settings.php',
			'class' => 'NeoWeaver_Settings',
			'slug'  => 'nw-settings',
			'root'  => false,
		],
	];

	public static function init(): void {
		require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-base-admin.php';

		foreach ( self::MODULES as $module ) {
			$path = NEOWEAVER_PLUGIN_DIR . 'admin/' . $module['file'];

			if ( ! file_exists( $path ) ) {
				continue;
			}

			require_once $path;

			$class = (string) $module['class'];

			if ( $class && class_exists( $class, false ) && ! isset( $GLOBALS[ 'nw_admin_inst_' . $class ] ) ) {
				$GLOBALS[ 'nw_admin_inst_' . $class ] = new $class();
			}
		}

		add_action( 'admin_menu',            [ __CLASS__, 'reorder_submenu' ],  999 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_menu_icons' ] );
	}

	/**
	 * Ładuje Lucide + skrypt ikon menu globalnie w całym adminie.
	 */
	public static function enqueue_menu_icons(): void {
		wp_enqueue_script( 'nw-lucide' ); // zarejestrowany w vendors.php

		wp_enqueue_script(
			'nw-admin-menu-icons',
			NW_PLUGIN_URL . 'assets/js/admin/admin-menu-icons.js',
			[ 'nw-lucide' ],
			NW_VERSION,
			true
		);
	}

	public static function parent_slug(): string {
		return self::PARENT_SLUG;
	}

	/**
	 * Zwraca mapę pozycji submenu na bazie tej samej listy, z której ładujemy moduły.
	 *
	 * @return array<string,int>
	 */
	private static function submenu_order_map(): array {
		$map = [];

		foreach ( self::MODULES as $index => $module ) {
			$slug = (string) $module['slug'];

			if ( '' !== $slug ) {
				$map[ $slug ] = $index;
			}
		}

		return $map;
	}

	public static function reorder_submenu(): void {
		global $submenu;

		if ( empty( $submenu[ self::PARENT_SLUG ] ) || ! is_array( $submenu[ self::PARENT_SLUG ] ) ) {
			return;
		}

		$order_map = self::submenu_order_map();

		usort(
			$submenu[ self::PARENT_SLUG ],
			static function ( array $a, array $b ) use ( $order_map ): int {
				$slug_a = isset( $a[2] ) ? (string) $a[2] : '';
				$slug_b = isset( $b[2] ) ? (string) $b[2] : '';

				$pos_a = $order_map[ $slug_a ] ?? PHP_INT_MAX;
				$pos_b = $order_map[ $slug_b ] ?? PHP_INT_MAX;

				if ( $pos_a === $pos_b ) {
					return strcmp( $slug_a, $slug_b );
				}

				return $pos_a <=> $pos_b;
			}
		);
	}
}

NW_Admin_Bootstrap::init();
