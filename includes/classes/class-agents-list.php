<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TW_Agents_List_Shortcode' ) ) {

	class TW_Agents_List_Shortcode {

		const SHORTCODE = 'tw_characters_list';

		private static ?Neoweaver_Agents_List $service = null;

		public static function init(): void {
			add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
		}

		protected static function get_service(): ?Neoweaver_Agents_List {
			if ( self::$service instanceof Neoweaver_Agents_List ) {
				return self::$service;
			}

			if ( ! class_exists( 'Neoweaver_Agents_Repository' ) || ! class_exists( 'Neoweaver_Agents_List' ) ) {
				return null;
			}

			self::$service = new Neoweaver_Agents_List( new Neoweaver_Agents_Repository() );

			return self::$service;
		}

		public static function render_shortcode( $atts = [] ): string {
			if ( ! is_user_logged_in() ) {
				return '<p>Please log in to view your Field Agents.</p>';
			}

			$service = self::get_service();

			if ( ! $service ) {
				return '<p>System Error: Agents module unavailable.</p>';
			}

			return $service->render_roster( get_current_user_id() );
		}
	}

	TW_Agents_List_Shortcode::init();
}
