<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_render_quick_actions_cmd_center' ) ) {
	function tw_render_quick_actions_cmd_center(): string {
		if ( is_admin() ) {
			return '';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return '<div class="tw-quick-actions-cmd-center-error">Supabase config missing.</div>';
		}

		if ( function_exists( 'tw_enqueue_quick_actions_cmd_center_assets' ) ) {
			tw_enqueue_quick_actions_cmd_center_assets(
				array(
					'supabaseUrl'               => trailingslashit( tw_supabase_url() ),
					'anonKey'                   => tw_supabase_anon_key(),
					'searchDebounce'            => 200,
					'confirmDeleteCustomAction' => 'Delete custom action?',
					'requiredFieldsMessage'     => 'Label and Prompt are required!',
				)
			);
		}

		ob_start();
		?>
		<div class="tw-quick-actions-cmd-center" data-quick-actions-cmd-center="1"></div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'tw_quick_actions_cmd_center', 'tw_render_quick_actions_cmd_center' );
}
