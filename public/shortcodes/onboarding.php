<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'TW_Onboarding_Shortcode' ) ) {
	class TW_Onboarding_Shortcode {

		const SHORTCODE = 'tw_onboarding_slider';

		public static function init(): void {
			add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		}

		public static function render_shortcode( $atts = array() ): string {
			unset( $atts );

			if ( ! is_user_logged_in() ) {
				return '';
			}

			$wp_user_id = get_current_user_id();

			if ( ! $wp_user_id ) {
				return '';
			}

			if (
				function_exists( 'tw_get_user_setting' ) &&
				'1' === (string) tw_get_user_setting( $wp_user_id, 'onboarding_dismissed' )
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

			if ( function_exists( 'tw_enqueue_onboarding_assets' ) ) {
				tw_enqueue_onboarding_assets(
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( 'neoweaver_onboarding' ),
					)
				);
			}

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
					title="Collapse onboarding"
				>
					<span class="tw-onboarding-slider__toggle-text"></span>
					<span class="tw-onboarding-slider__toggle-icon">&#9654;</span>
				</button>

				<aside id="tw-onboarding-slider-panel" class="tw-onboarding-slider__panel">
					<button
						type="button"
						class="tw-onboarding-slider__dismiss"
						aria-label="Dismiss onboarding panel"
						title="Dismiss onboarding panel"
					>
						<span aria-hidden="true">&times;</span>
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
								<a href="<?php echo esc_url( site_url( '/new-node' ) ); ?>">create new world Node</a>
							</div>
						</li>

						<li class="tw-onboarding-slider__item<?php echo ! empty( $progress['character'] ) ? ' is-done' : ''; ?>" data-step="character">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Create an Agent</strong>
								<p>Bind your Operator to an Agent</p>
								<a href="<?php echo esc_url( site_url( '/new-agent' ) ); ?>">Agent dossier</a>
							</div>
						</li>

						<li class="tw-onboarding-slider__item<?php echo ! empty( $progress['campaign'] ) ? ' is-done' : ''; ?>" data-step="campaign">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Create a Deployment</strong>
								<p>Launch campaign inside a Node</p>
								<a href="<?php echo esc_url( site_url( '/new-deployment' ) ); ?>">brief Deployment</a>
							</div>
						</li>

						<li class="tw-onboarding-slider__item<?php echo $terminal_done ? ' is-done' : ''; ?>" data-step="terminal">
							<span class="tw-onboarding-slider__status" aria-hidden="true"></span>
							<div class="tw-onboarding-slider__body">
								<strong>Enter the Terminal</strong>
								<p>Launch a session | Go to team lobby</p>
								<div class="tw-onboarding-slider__actions">
									<a href="<?php echo esc_url( site_url( '/terminal/' ) ); ?>">Open terminal</a> //
									<a href="<?php echo esc_url( site_url( '/join-terminal/' ) ); ?>">Join a team</a>
								</div>
							</div>
						</li>
					</ol>
				</aside>
			</div>
			<?php

			return (string) ob_get_clean();
		}

		protected static function user_has_game_session( int $wp_user_id ): bool {
			if ( ! function_exists( 'tw_supabase_get' ) ) {
				return false;
			}

			$rows = tw_supabase_get(
				'cyber_game_sessions',
				array(
					'wp_user_id' => 'eq.' . (int) $wp_user_id,
					'select'     => 'id',
					'limit'      => 1,
				)
			);

			return ! empty( $rows );
		}

		protected static function get_user_progress( int $wp_user_id ): array {
    if ( ! function_exists( 'tw_supabase_get' ) ) {
        return [ 'world' => false, 'character' => false, 'campaign' => false, 'terminal' => false ];
    }

    if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
        return [ 'world' => false, 'character' => false, 'campaign' => false, 'terminal' => false ];
    }

    $service_headers = [
        'headers' => [
            'apikey'        => TW_SUPABASE_SERVICE_KEY,
            'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
        ],
    ];

    $wp_user_id = (int) $wp_user_id;

    $worlds     = tw_supabase_get( 'cyber_worlds',       [ 'wp_user_id' => 'eq.' . $wp_user_id, 'select' => 'id', 'limit' => 1 ], $service_headers );
    $characters = tw_supabase_get( 'cyber_characters',   [ 'wp_user_id' => 'eq.' . $wp_user_id, 'select' => 'id', 'limit' => 1 ], $service_headers );
    $campaigns  = tw_supabase_get( 'cyber_campaign',     [ 'wp_user_id' => 'eq.' . $wp_user_id, 'select' => 'id', 'limit' => 1 ], $service_headers );
    $sessions   = tw_supabase_get( 'cyber_game_sessions',[ 'wp_user_id' => 'eq.' . $wp_user_id, 'select' => 'id', 'status' => 'eq.active', 'limit' => 1 ], $service_headers );

    return [
        'world'     => ! is_wp_error( $worlds )     && ! empty( $worlds ),
        'character' => ! is_wp_error( $characters ) && ! empty( $characters ),
        'campaign'  => ! is_wp_error( $campaigns )  && ! empty( $campaigns ),
        'terminal'  => ! is_wp_error( $sessions )   && ! empty( $sessions ),
    ];
}
	}

	TW_Onboarding_Shortcode::init();
}
