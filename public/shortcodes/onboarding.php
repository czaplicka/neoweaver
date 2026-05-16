<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'TW_Onboarding_Shortcode' ) ) {

	class TW_Onboarding_Shortcode {

		const SHORTCODE = 'tw_onboarding_slider';

		protected static $should_enqueue = false;
		protected static $localized_data = [];

		public static function init() {
			add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
			add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ], 5 );
			add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_assets' ], 20 );
		}

		public static function maybe_enqueue_assets() {
			if ( ! self::$should_enqueue ) {
				return;
			}

			if ( wp_style_is( 'tw-onboarding-slider', 'registered' ) ) {
				wp_enqueue_style( 'tw-onboarding-slider' );
			}

			if ( wp_script_is( 'tw-onboarding-slider', 'registered' ) ) {
				wp_enqueue_script( 'tw-onboarding-slider' );

				if ( ! empty( self::$localized_data ) ) {
					wp_localize_script(
						'tw-onboarding-slider',
						'twOnboardingSlider',
						self::$localized_data
					);
				}
			}
		}

		public static function render_shortcode( $atts = [] ) {
			if ( ! is_user_logged_in() ) {
				return '';
			}

			$wp_user_id = get_current_user_id();
			if ( ! $wp_user_id ) {
				return '';
			}

			if ( self::user_has_game_session( $wp_user_id ) ) {
				return '';
			}

			$progress = self::get_user_progress( $wp_user_id );

			self::$should_enqueue = true;
			self::$localized_data = [
				'steps' => [
					'world' => [
						'completed' => ! empty( $progress['world'] ),
						'url'       => site_url( '/new-node' ),
					],
					'character' => [
						'completed' => ! empty( $progress['character'] ),
						'url'       => site_url( '/new-agent' ),
					],
					'campaign' => [
						'completed' => ! empty( $progress['campaign'] ),
						'url'       => site_url( '/new-deployment' ),
					],
				],
				'labels' => [
					'collapsed' => 'Open onboarding',
					'expanded'  => 'Collapse onboarding',
				],
			];

			$all_done = ! empty( $progress['world'] ) && ! empty( $progress['character'] ) && ! empty( $progress['campaign'] );

			ob_start();
			?>
			<div 
				id="tw-onboarding-slider"
				class="tw-onboarding-slider<?php echo $all_done ? ' is-complete' : ''; ?>"
				data-all-complete="<?php echo $all_done ? '1' : '0'; ?>"
				aria-label="NeoWeave onboarding"
			>
				<button 
					type="button"
					class="tw-onboarding-slider__toggle"
					aria-expanded="true"
					aria-controls="tw-onboarding-slider-panel"
					aria-label="Collapse onboarding"
				>
					<span class="tw-onboarding-slider__toggle-text">BOOT</span>
					<span class="tw-onboarding-slider__toggle-icon">▸</span>
				</button>

				<aside id="tw-onboarding-slider-panel" class="tw-onboarding-slider__panel">
					<div class="tw-onboarding-slider__scanline"></div>

					<div class="tw-onboarding-slider__header">
						<p class="tw-onboarding-slider__eyebrow">NEOWEAVE // CRT INIT</p>
						<h3 class="tw-onboarding-slider__title">Initialize access</h3>
						<p class="tw-onboarding-slider__desc">
							Complete the setup sequence to enter the Terminal.
						</p>
					</div>

					<ol class="tw-onboarding-slider__list">
						<li class="tw-onboarding-slider__item<?php echo ! empty( $progress['world'] ) ? ' is-done' : ''; ?>" data-step="world">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Create a Node</strong>
								<p>Generate the world instance your run will persist in.</p>
								<a href="<?php echo esc_url( site_url( '/new-node' ) ); ?>">Open node creator</a>
							</div>
						</li>

						<li class="tw-onboarding-slider__item<?php echo ! empty( $progress['character'] ) ? ' is-done' : ''; ?>" data-step="character">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Create an Agent</strong>
								<p>Bind your Operator to a Field Agent.</p>
								<a href="<?php echo esc_url( site_url( '/new-agent' ) ); ?>">Open agent creator</a>
							</div>
						</li>

						<li class="tw-onboarding-slider__item<?php echo ! empty( $progress['campaign'] ) ? ' is-done' : ''; ?>" data-step="campaign">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Create a Deployment</strong>
								<p>Launch your first campaign inside the active Node.</p>
								<a href="<?php echo esc_url( site_url( '/new-deployment' ) ); ?>">Open deployment creator</a>
							</div>
						</li>
					</ol>
				</aside>
			</div>
			<?php
			return ob_get_clean();
		}

		protected static function user_has_game_session( $wp_user_id ) {
			$rows = tw_supabase_get(
				'cyber_game_sessions',
				[
					'wp_user_id' => 'eq.' . intval( $wp_user_id ),
					'select'     => 'id',
					'limit'      => 1,
				]
			);

			return ! empty( $rows );
		}

		protected static function get_user_progress( $wp_user_id ) {
			$wp_user_id = intval( $wp_user_id );

			$worlds = tw_supabase_get(
				'cyber_worlds',
				[
					'wp_user_id' => 'eq.' . $wp_user_id,
					'select'     => 'id',
					'limit'      => 1,
				]
			);

			$characters = tw_supabase_get(
				'cyber_characters',
				[
					'wp_user_id' => 'eq.' . $wp_user_id,
					'select'     => 'id',
					'limit'      => 1,
				]
			);

			$campaigns = tw_supabase_get(
				'cyber_campaigns',
				[
					'wp_user_id' => 'eq.' . $wp_user_id,
					'select'     => 'id',
					'limit'      => 1,
				]
			);

			return [
				'world'     => ! empty( $worlds ),
				'character' => ! empty( $characters ),
				'campaign'  => ! empty( $campaigns ),
			];
		}
	}

	TW_Onboarding_Shortcode::init();
}
