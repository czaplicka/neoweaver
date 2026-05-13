<?php
/**
 * NeoWeaver Admin — Platform Settings
 *
 * Super-admin controls for the whole NeoWeaver platform instance.
 * Does NOT contain per-world or per-campaign game-mechanics parameters —
 * those live on the world / campaign / archetype editors.
 *
 * Sections:
 *  1. Platform Access   — registration, approval, public visibility
 *  2. Limits            — max worlds, campaigns, characters, messages per user
 *  3. Moderation        — report system, auto-hide, banned-user policy
 *  4. Operations        — maintenance mode, debug logging, log retention, cleanup
 *  5. Analytics         — platform stats dashboard, DAU tracking, audit log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'NeoWeaver_Settings', false ) ) {

	class NeoWeaver_Settings {

		private string $slug        = 'nw-settings';
		private string $parent_slug = 'neoweaver';
		private string $option_key  = 'nw_platform_settings';

		/* ------------------------------------------------------------------ */
		/* DEFAULTS                                                           */
		/* ------------------------------------------------------------------ */

		public static function defaults(): array {
			return [
				// Platform Access
				'allow_registration'   => 1,
				'require_approval'     => 0,
				'allow_public_profiles'=> 1,
				'allow_public_worlds'  => 1,
				'allow_public_campaigns'=> 0,
				'allow_invite_codes'   => 1,

				// Limits (0 = unlimited)
				'max_worlds_per_user'       => 0,
				'max_campaigns_per_world'   => 0,
				'max_characters_per_world'  => 0,
				'max_active_campaigns'      => 0,
				'max_chat_messages_per_hour'=> 0,
				'max_ai_gens_per_day'       => 0,

				// Moderation
				'enable_reports'          => 1,
				'autohide_reported'       => 0,
				'block_banned_from_create'=> 1,
				'global_name_blacklist'   => '',

				// Operations
				'maintenance_mode'   => 0,
				'readonly_mode'      => 0,
				'debug_logging'      => 0,
				'log_retention_days' => 30,
				'cleanup_frequency'  => 'weekly',

				// Analytics
				'show_stats_dashboard' => 1,
				'track_dau'            => 1,
				'admin_audit_log'      => 1,
			];
		}

		/* ------------------------------------------------------------------ */
		/* BOOT                                                               */
		/* ------------------------------------------------------------------ */

		public function __construct() {
			if ( ! is_admin() ) {
				return;
			}

			add_action( 'admin_menu',    [ $this, 'register_submenu' ] );
			add_action( 'admin_init',    [ $this, 'register_settings' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		}

		/* ------------------------------------------------------------------ */
		/* MENU                                                               */
		/* ------------------------------------------------------------------ */

		public function register_submenu(): void {
			add_submenu_page(
				$this->parent_slug,
				__( 'NeoWeaver Settings', 'neoweaver' ),
				__( 'Settings', 'neoweaver' ),
				'manage_options',
				$this->slug,
				[ $this, 'render_page' ]
			);
		}

		/* ------------------------------------------------------------------ */
		/* ASSETS                                                             */
		/* ------------------------------------------------------------------ */

		public function enqueue_assets( string $hook ): void {
			if ( strpos( $hook, $this->slug ) === false ) {
				return;
			}

			$plugin_url = plugin_dir_url( dirname( __FILE__ ) );
			$version    = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION
				: ( defined( 'NW_VERSION' ) ? NW_VERSION : null );

			if ( ! wp_style_is( 'chakra-petch', 'registered' ) && ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
				wp_enqueue_style(
					'chakra-petch',
					'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
					[],
					null
				);
			}

			wp_enqueue_style(
				'nw-settings-style',
				$plugin_url . 'assets/css/admin/settings.css',
				[ 'chakra-petch' ],
				$version
			);
		}

		/* ------------------------------------------------------------------ */
		/* SETTINGS API                                                       */
		/* ------------------------------------------------------------------ */

		public function register_settings(): void {
			register_setting(
				$this->option_key . '_group',
				$this->option_key,
				[ $this, 'sanitize_options' ]
			);

			/* ---- Section 1: Platform Access ---- */
			add_settings_section(
				'nw_section_access',
				__( 'Platform Access', 'neoweaver' ),
				[ $this, 'section_access_cb' ],
				$this->slug
			);

			$this->add_checkbox( 'nw_section_access', 'allow_registration',
				__( 'Allow new user registration', 'neoweaver' ),
				__( 'Users can create accounts on this NeoWeaver instance.', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_access', 'require_approval',
				__( 'Require admin approval for new accounts', 'neoweaver' ),
				__( 'New registrations are set to pending until a super-admin approves them.', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_access', 'allow_public_profiles',
				__( 'Allow public player profiles', 'neoweaver' ),
				__( 'Players may make their profiles visible to anyone.', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_access', 'allow_public_worlds',
				__( 'Allow public worlds', 'neoweaver' ),
				__( 'World owners may set their world visibility to public.', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_access', 'allow_public_campaigns',
				__( 'Allow public campaigns', 'neoweaver' ),
				__( 'Campaign owners may set their campaigns to public (visible in catalogue).', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_access', 'allow_invite_codes',
				__( 'Allow joining via invite code', 'neoweaver' ),
				__( 'Players can join worlds or campaigns by entering a generated invite code.', 'neoweaver' ) );

			/* ---- Section 2: Limits ---- */
			add_settings_section(
				'nw_section_limits',
				__( 'Limits', 'neoweaver' ),
				[ $this, 'section_limits_cb' ],
				$this->slug
			);

			$this->add_number( 'nw_section_limits', 'max_worlds_per_user',
				__( 'Max worlds per user', 'neoweaver' ),
				__( 'Maximum number of worlds a single user may own. 0 = unlimited.', 'neoweaver' ) );

			$this->add_number( 'nw_section_limits', 'max_campaigns_per_world',
				__( 'Max campaigns per world', 'neoweaver' ),
				__( 'Maximum campaigns per world. 0 = unlimited.', 'neoweaver' ) );

			$this->add_number( 'nw_section_limits', 'max_characters_per_world',
				__( 'Max characters per world', 'neoweaver' ),
				__( 'Maximum characters a world may contain. 0 = unlimited.', 'neoweaver' ) );

			$this->add_number( 'nw_section_limits', 'max_active_campaigns',
				__( 'Max active campaigns per user', 'neoweaver' ),
				__( 'Maximum simultaneously active campaigns a single user may run. 0 = unlimited.', 'neoweaver' ) );

			$this->add_number( 'nw_section_limits', 'max_chat_messages_per_hour',
				__( 'Max chat messages per hour (per user)', 'neoweaver' ),
				__( 'Rate-limit for chat across all game channels. 0 = no limit.', 'neoweaver' ) );

			$this->add_number( 'nw_section_limits', 'max_ai_gens_per_day',
				__( 'Max AI generations per day (per user)', 'neoweaver' ),
				__( 'Applies to any AI-assisted content generation features. 0 = no limit.', 'neoweaver' ) );

			/* ---- Section 3: Moderation ---- */
			add_settings_section(
				'nw_section_moderation',
				__( 'Moderation', 'neoweaver' ),
				[ $this, 'section_moderation_cb' ],
				$this->slug
			);

			$this->add_checkbox( 'nw_section_moderation', 'enable_reports',
				__( 'Enable content reporting', 'neoweaver' ),
				__( 'Players can flag worlds, campaigns, and profiles for review.', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_moderation', 'autohide_reported',
				__( 'Auto-hide reported public content', 'neoweaver' ),
				__( 'Reported public content is hidden from the catalogue until reviewed.', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_moderation', 'block_banned_from_create',
				__( 'Block banned users from creating worlds and campaigns', 'neoweaver' ),
				__( 'Banned accounts lose the ability to create new game content.', 'neoweaver' ) );

			$this->add_textarea( 'nw_section_moderation', 'global_name_blacklist',
				__( 'Reserved / blacklisted names', 'neoweaver' ),
				__( 'One name per line. These values cannot be used as world, campaign, or character names.', 'neoweaver' ) );

			/* ---- Section 4: Operations ---- */
			add_settings_section(
				'nw_section_operations',
				__( 'Operations', 'neoweaver' ),
				[ $this, 'section_operations_cb' ],
				$this->slug
			);

			$this->add_checkbox( 'nw_section_operations', 'maintenance_mode',
				__( 'Maintenance mode', 'neoweaver' ),
				__( 'Disables all front-end access. Only super-admins can log in.', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_operations', 'readonly_mode',
				__( 'Read-only mode', 'neoweaver' ),
				__( 'Allows browsing but blocks all writes (create, update, delete).', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_operations', 'debug_logging',
				__( 'Enable debug logging to cyber_logs', 'neoweaver' ),
				__( 'Verbose request/response logs written to the Supabase cyber_logs table.', 'neoweaver' ) );

			$this->add_number( 'nw_section_operations', 'log_retention_days',
				__( 'Log retention (days)', 'neoweaver' ),
				__( 'Entries older than this are removed by the cleanup cron. Min: 1.', 'neoweaver' ), 1 );

			$this->add_select( 'nw_section_operations', 'cleanup_frequency',
				__( 'Cleanup / snapshot frequency', 'neoweaver' ),
				__( 'How often the maintenance cron runs old-log cleanup and DB snapshots.', 'neoweaver' ),
				[
					'hourly'  => __( 'Hourly', 'neoweaver' ),
					'daily'   => __( 'Daily', 'neoweaver' ),
					'weekly'  => __( 'Weekly', 'neoweaver' ),
					'monthly' => __( 'Monthly', 'neoweaver' ),
				]
			);

			/* ---- Section 5: Analytics ---- */
			add_settings_section(
				'nw_section_analytics',
				__( 'Analytics', 'neoweaver' ),
				[ $this, 'section_analytics_cb' ],
				$this->slug
			);

			$this->add_checkbox( 'nw_section_analytics', 'show_stats_dashboard',
				__( 'Show platform stats on Dashboard', 'neoweaver' ),
				__( 'Enables the KPI / trend panels on the main NeoWeaver dashboard.', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_analytics', 'track_dau',
				__( 'Track DAU / worlds created / campaigns created / active sessions', 'neoweaver' ),
				__( 'Stores daily aggregate counters for growth charts.', 'neoweaver' ) );

			$this->add_checkbox( 'nw_section_analytics', 'admin_audit_log',
				__( 'Store admin audit log', 'neoweaver' ),
				__( 'Every settings change or admin action is logged with timestamp and user.', 'neoweaver' ) );
		}

		/* ------------------------------------------------------------------ */
		/* SECTION CALLBACKS                                                  */
		/* ------------------------------------------------------------------ */

		public function section_access_cb(): void {
			echo '<p class="nw-settings-desc">'
				. esc_html__( 'Control who can register, what they can make public, and how they can join games on this platform instance.', 'neoweaver' )
				. '</p>';
		}

		public function section_limits_cb(): void {
			echo '<p class="nw-settings-desc">'
				. esc_html__( 'Set platform-wide hard caps for resource creation. These apply to every user regardless of their world settings. Enter 0 for unlimited.', 'neoweaver' )
				. '</p>';
		}

		public function section_moderation_cb(): void {
			echo '<p class="nw-settings-desc">'
				. esc_html__( 'Content safety and abuse-prevention controls for the platform.', 'neoweaver' )
				. '</p>';
		}

		public function section_operations_cb(): void {
			echo '<p class="nw-settings-desc">'
				. esc_html__( 'Maintenance, logging, and scheduled tasks. Use maintenance mode before major database migrations.', 'neoweaver' )
				. '</p>';
		}

		public function section_analytics_cb(): void {
			echo '<p class="nw-settings-desc">'
				. esc_html__( 'Platform-level telemetry. Data is stored in Supabase and shown on the NeoWeaver dashboard.', 'neoweaver' )
				. '</p>';
		}

		/* ------------------------------------------------------------------ */
		/* FIELD HELPERS                                                      */
		/* ------------------------------------------------------------------ */

		private function get_option( string $key ) {
			$saved    = (array) get_option( $this->option_key, [] );
			$defaults = self::defaults();
			return array_key_exists( $key, $saved ) ? $saved[ $key ] : ( $defaults[ $key ] ?? '' );
		}

		private function add_checkbox( string $section, string $key, string $label, string $description ): void {
			add_settings_field(
				'nw_field_' . $key,
				$label,
				function() use ( $key, $description ) {
					$val = (int) $this->get_option( $key );
					printf(
						'<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> %4$s</label>',
						esc_attr( $this->option_key ),
						esc_attr( $key ),
						checked( 1, $val, false ),
						esc_html( $description )
					);
				},
				$this->slug,
				$section
			);
		}

		private function add_number( string $section, string $key, string $label, string $description, int $min = 0 ): void {
			add_settings_field(
				'nw_field_' . $key,
				$label,
				function() use ( $key, $description, $min ) {
					$val = (int) $this->get_option( $key );
					printf(
						'<input type="number" class="small-text" name="%1$s[%2$s]" value="%3$d" min="%4$d" step="1">
						<p class="description">%5$s</p>',
						esc_attr( $this->option_key ),
						esc_attr( $key ),
						$val,
						$min,
						esc_html( $description )
					);
				},
				$this->slug,
				$section
			);
		}

		private function add_textarea( string $section, string $key, string $label, string $description ): void {
			add_settings_field(
				'nw_field_' . $key,
				$label,
				function() use ( $key, $description ) {
					$val = (string) $this->get_option( $key );
					printf(
						'<textarea class="large-text" rows="5" name="%1$s[%2$s]">%3$s</textarea>
						<p class="description">%4$s</p>',
						esc_attr( $this->option_key ),
						esc_attr( $key ),
						esc_textarea( $val ),
						esc_html( $description )
					);
				},
				$this->slug,
				$section
			);
		}

		private function add_select( string $section, string $key, string $label, string $description, array $options ): void {
			add_settings_field(
				'nw_field_' . $key,
				$label,
				function() use ( $key, $description, $options ) {
					$val  = (string) $this->get_option( $key );
					$html = sprintf(
						'<select name="%1$s[%2$s]">',
						esc_attr( $this->option_key ),
						esc_attr( $key )
					);
					foreach ( $options as $opt_val => $opt_label ) {
						$html .= sprintf(
							'<option value="%1$s"%2$s>%3$s</option>',
							esc_attr( $opt_val ),
							selected( $val, $opt_val, false ),
							esc_html( $opt_label )
						);
					}
					$html .= '</select>';
					$html .= sprintf( '<p class="description">%s</p>', esc_html( $description ) );
					echo wp_kses_post( $html );
				},
				$this->slug,
				$section
			);
		}

		/* ------------------------------------------------------------------ */
		/* SANITIZE                                                           */
		/* ------------------------------------------------------------------ */

		public function sanitize_options( $input ): array {
			if ( ! is_array( $input ) ) {
				$input = [];
			}

			$defaults = self::defaults();
			$clean    = [];

			// Checkboxes
			$checkboxes = [
				'allow_registration', 'require_approval', 'allow_public_profiles',
				'allow_public_worlds', 'allow_public_campaigns', 'allow_invite_codes',
				'enable_reports', 'autohide_reported', 'block_banned_from_create',
				'maintenance_mode', 'readonly_mode', 'debug_logging',
				'show_stats_dashboard', 'track_dau', 'admin_audit_log',
			];
			foreach ( $checkboxes as $key ) {
				$clean[ $key ] = isset( $input[ $key ] ) ? 1 : 0;
			}

			// Numbers (≥0)
			$numbers = [
				'max_worlds_per_user', 'max_campaigns_per_world', 'max_characters_per_world',
				'max_active_campaigns', 'max_chat_messages_per_hour', 'max_ai_gens_per_day',
				'log_retention_days',
			];
			foreach ( $numbers as $key ) {
				$val = isset( $input[ $key ] ) ? (int) $input[ $key ] : $defaults[ $key ];
				$min = ( 'log_retention_days' === $key ) ? 1 : 0;
				$clean[ $key ] = max( $min, $val );
			}

			// Textarea
			$clean['global_name_blacklist'] = isset( $input['global_name_blacklist'] )
				? sanitize_textarea_field( wp_unslash( $input['global_name_blacklist'] ) )
				: '';

			// Select
			$freq_options = [ 'hourly', 'daily', 'weekly', 'monthly' ];
			$clean['cleanup_frequency'] = in_array( $input['cleanup_frequency'] ?? '', $freq_options, true )
				? $input['cleanup_frequency']
				: $defaults['cleanup_frequency'];

			// Audit log: record who changed what, when.
			if ( (int) $clean['admin_audit_log'] ) {
				$this->log_settings_change( $clean );
			}

			return $clean;
		}

		/* ------------------------------------------------------------------ */
		/* AUDIT                                                              */
		/* ------------------------------------------------------------------ */

		private function log_settings_change( array $new_values ): void {
			$entry = [
				'user_id'    => get_current_user_id(),
				'user_login' => wp_get_current_user()->user_login ?? 'unknown',
				'timestamp'  => current_time( 'mysql', true ),
				'changed'    => $new_values,
			];
			$log = (array) get_option( 'nw_settings_audit_log', [] );
			array_unshift( $log, $entry );
			// Keep last 100 entries.
			$log = array_slice( $log, 0, 100 );
			update_option( 'nw_settings_audit_log', $log, false );
		}

		/* ------------------------------------------------------------------ */
		/* RENDER                                                             */
		/* ------------------------------------------------------------------ */

		public function render_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access NeoWeaver Settings.', 'neoweaver' ) );
			}

			$maintenance = (int) $this->get_option( 'maintenance_mode' );
			$readonly    = (int) $this->get_option( 'readonly_mode' );
			?>
			<div class="wrap nw-settings-wrap">

				<div class="nw-settings-header">
					<h1>
						<span class="nw-accent">Neo</span>Weaver
						&mdash; <?php esc_html_e( 'Platform Settings', 'neoweaver' ); ?>
					</h1>
					<p class="nw-settings-subtitle">
						<?php esc_html_e( 'Super-admin controls for this NeoWeaver instance. These settings apply globally to the platform — not to individual worlds, campaigns, or characters.', 'neoweaver' ); ?>
					</p>

					<?php if ( $maintenance ) : ?>
						<div class="nw-settings-notice nw-notice-danger">
							&#9888; <?php esc_html_e( 'Maintenance mode is ON. Front-end access is blocked for all non-admins.', 'neoweaver' ); ?>
						</div>
					<?php endif; ?>

					<?php if ( $readonly ) : ?>
						<div class="nw-settings-notice nw-notice-warning">
							&#128274; <?php esc_html_e( 'Read-only mode is ON. All write operations are disabled.', 'neoweaver' ); ?>
						</div>
					<?php endif; ?>
				</div>

				<form method="post" action="options.php" class="nw-settings-form">
					<?php
					settings_fields( $this->option_key . '_group' );
					do_settings_sections( $this->slug );
					submit_button( __( 'Save Platform Settings', 'neoweaver' ), 'primary nw-btn-save', 'submit', true );
					?>
				</form>

			</div>
			<?php
		}
	}
}

if ( is_admin() && ! isset( $GLOBALS['neoweaver_settings_instance'] ) ) {
	$GLOBALS['neoweaver_settings_instance'] = new NeoWeaver_Settings();
}
