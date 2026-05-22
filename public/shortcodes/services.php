<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'neoweave_service_modal_shortcode' ) ) {
	function neoweave_service_modal_shortcode(): string {
		if ( function_exists( 'tw_enqueue_services_assets' ) ) {
			tw_enqueue_services_assets(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'neo_service_nonce' ),
				)
			);
		}

		ob_start();
		?>
		<div id="neo-service-trigger" class="neo-service-icon" style="display: none;">
			<svg viewBox="0 0 24 24" fill="none" stroke="#adff00" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
				<path d="M13 8h2m-5 0h.01M9 12h.01M12 12h.01M15 12h.01" stroke-width="3"></path>
			</svg>
			<span class="pulse-ring"></span>
		</div>

		<div id="neo-service-modal" class="neo-modal-overlay" hidden>
			<div class="neo-modal-container">
				<div class="crt-overlay"></div>

				<div class="neo-modal-header">
					<span class="terminal-prefix">CMD: <span id="neo-npc-name">SERVICE_LINK</span></span>
					<button type="button" class="close-modal" data-neo-service-close="1" aria-label="Close modal">[X]</button>
				</div>

				<div class="neo-modal-body">
					<div id="neo-npc-avatar" class="neo-npc-avatar" hidden></div>

					<div id="neo-services-list" class="services-grid"></div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'neoweave_services', 'neoweave_service_modal_shortcode' );
}
