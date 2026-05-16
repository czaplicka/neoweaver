<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'TW_Onboarding_Shortcode' ) ) {

	class TW_Onboarding_Shortcode {

		const SHORTCODE = 'tw_onboarding_slider';

		public static function init() {
			add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
			add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		}

		public static function render_shortcode( $atts = [] ) {
			if ( ! is_user_logged_in() ) {
				return '';
			}

			$wp_user_id = get_current_user_id();
			if ( ! $wp_user_id ) {
				return '';
			}

			// Nie pokazuj, jeśli użytkownik już zamknął onboarding.
			if (
				function_exists( 'tw_get_user_setting' ) &&
				tw_get_user_setting( $wp_user_id, 'onboarding_dismissed' ) === '1'
			) {
				return '';
			}

			if ( self::user_has_game_session( $wp_user_id ) ) {
				return '';
			}

			$progress      = self::get_user_progress( $wp_user_id );
			$terminal_done = ! empty( $progress['terminal'] );
			$all_done      = ! empty( $progress['world'] )
				&& ! empty( $progress['character'] )
				&& ! empty( $progress['campaign'] )
				&& ! empty( $progress['terminal'] );

			ob_start();
			?>
			<div
				id="tw-onboarding-slider"
				class="tw-onboarding-slider<?php echo $all_done ? ' is-complete' : ''; ?>"
				data-all-complete="<?php echo $all_done ? '1' : '0'; ?>"
				aria-label="NeoWeave onboarding"
			>
				<aside id="tw-onboarding-slider-panel" class="tw-onboarding-slider__panel">
					<button
						type="button"
						class="tw-onboarding-slider__dismiss"
						aria-label="Dismiss onboarding panel"
					>
						<span aria-hidden="true">×</span>
					</button>

					<div class="tw-onboarding-slider__scanline"></div>

					<div class="tw-onboarding-slider__header">
						<p class="tw-onboarding-slider__eyebrow">NEOWEAVE // CRT INIT</p>
						<h3 class="tw-onboarding-slider__title">ONBOARDING</h3>
						<p class="tw-onboarding-slider__desc">
							Complete the setup to start
						</p>
					</div>

					<ol class="tw-onboarding-slider__list">
						<li class="tw-onboarding-slider__item<?php echo ! empty( $progress['world'] ) ? ' is-done' : ''; ?>" data-step="world">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Create a Node</strong>
								<p>Generate a world to save</p>
								<a href="<?php echo esc_url( site_url( '/new-node' ) ); ?>">Open node creator</a>
							</div>
						</li>

						<li class="tw-onboarding-slider__item<?php echo ! empty( $progress['character'] ) ? ' is-done' : ''; ?>" data-step="character">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Create an Agent</strong>
								<p>Bind your Operator to an Agent</p>
								<a href="<?php echo esc_url( site_url( '/new-agent' ) ); ?>">Open agent creator</a>
							</div>
						</li>

						<li class="tw-onboarding-slider__item<?php echo ! empty( $progress['campaign'] ) ? ' is-done' : ''; ?>" data-step="campaign">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Create a Deployment</strong>
								<p>Launch campaign inside a Node</p>
								<a href="<?php echo esc_url( site_url( '/new-deployment' ) ); ?>">Open deployment creator</a>
							</div>
						</li>

						<li class="tw-onboarding-slider__item<?php echo $terminal_done ? ' is-done' : ''; ?>" data-step="terminal">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Enter the Terminal</strong>
								<p>Launch a session | join a team</p>
								<div class="tw-onboarding-slider__actions">
									<a href="<?php echo esc_url( site_url( '/terminal/' ) ); ?>">Open terminal</a> //
									<a href="<?php echo esc_url( site_url( '/join-terminal/' ) ); ?>">Join team</a>
								</div>
							</div>
						</li>
					</ol>
				</aside>

				<button
					type="button"
					class="tw-onboarding-slider__toggle"
					aria-expanded="true"
					aria-controls="tw-onboarding-slider-panel"
					aria-label="Collapse onboarding"
				>
					<span class="tw-onboarding-slider__toggle-text"></span>
					<span class="tw-onboarding-slider__toggle-icon">▸</span>
				</button>
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

			$terminal_sessions = tw_supabase_get(
				'cyber_game_sessions',
				[
					'wp_user_id' => 'eq.' . $wp_user_id,
					'select'     => 'id,status,campaign_id,character_id,world_id',
					'status'     => 'eq.active',
					'limit'      => 1,
				]
			);

			return [
				'world'     => ! empty( $worlds ),
				'character' => ! empty( $characters ),
				'campaign'  => ! empty( $campaigns ),
				'terminal'  => ! empty( $terminal_sessions ),
			];
		}
	}

	TW_Onboarding_Shortcode::init();
}
