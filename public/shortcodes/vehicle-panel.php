<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_vehicle_panel_render_progress' ) ) {
	function tw_vehicle_panel_render_progress( int $current, int $max, string $bar_class, string $text_class, string $color ): string {
		$max     = max( 1, $max );
		$current = max( 0, min( $current, $max ) );
		$percent = (int) round( ( $current / $max ) * 100 );

		ob_start();
		?>
		<div class="stat-row">
			<div class="progress-bar">
				<div class="<?php echo esc_attr( $bar_class ); ?>" style="width: <?php echo esc_attr( (string) $percent ); ?>%; background: <?php echo esc_attr( $color ); ?>;"></div>
			</div>
			<span class="<?php echo esc_attr( $text_class ); ?>"><?php echo esc_html( $current . '/' . $max ); ?></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'tw_vehicle_panel_get_module_name' ) ) {
	function tw_vehicle_panel_get_module_name( array $vehicle, string $slot_key ): string {
		if ( empty( $vehicle[ $slot_key ] ) || ! is_array( $vehicle[ $slot_key ] ) ) {
			return '---';
		}

		return (string) ( $vehicle[ $slot_key ]['name'] ?? '---' );
	}
}

if ( ! function_exists( 'tw_vehicle_panel_fetch_vehicles' ) ) {
	function tw_vehicle_panel_fetch_vehicles( string $character_id ): array {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return array();
		}

		return tw_supabase_get(
			'cyber_vehicles',
			array(
				'owner_id' => 'eq.' . $character_id,
				'select'   => implode(
					',',
					array(
						'id',
						'owner_id',
						'campaign_id',
						'blueprint_id',
						'custom_name',
						'current_durability',
						'current_fuel',
						'is_active',
						'created_at',
						'cyber_vehicle_blueprints!cyber_vehicles_blueprint_id_fkey(id,name,max_durability,max_fuel)',
						'slot_core:cyber_vehicle_module_types!cyber_vehicles_slot_core_fkey(id,name,slot_type,weight,durability_bonus)',
						'slot_lateral_l:cyber_vehicle_module_types!cyber_vehicles_slot_lateral_l_fkey(id,name,slot_type,weight,durability_bonus)',
						'slot_lateral_r:cyber_vehicle_module_types!cyber_vehicles_slot_lateral_r_fkey(id,name,slot_type,weight,durability_bonus)',
						'slot_utility:cyber_vehicle_module_types!cyber_vehicles_slot_utility_fkey(id,name,slot_type,weight,durability_bonus)',
					)
				),
				'order'    => 'is_active.desc,created_at.asc',
			)
		);
	}
}

if ( ! function_exists( 'tw_vehicle_panel_shortcode' ) ) {
	function tw_vehicle_panel_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'character_id' => '',
			),
			$atts,
			'neoweave_vehicle_panel'
		);

		$character_id = sanitize_text_field( (string) $atts['character_id'] );

		if ( '' === $character_id && function_exists( 'tw_get_current_character_id' ) ) {
			$character_id = (string) tw_get_current_character_id();
		}

		if ( '' === $character_id ) {
			return '<div class="neoweave-vehicle-error">Missing character_id.</div>';
		}

		$vehicles = tw_vehicle_panel_fetch_vehicles( $character_id );

		if ( empty( $vehicles ) || ! is_array( $vehicles ) ) {
			return '<div class="neoweave-vehicle-error">No vehicles found.</div>';
		}

		if ( function_exists( 'tw_enqueue_vehicle_panel_assets' ) ) {
			tw_enqueue_vehicle_panel_assets(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'neoweave_vehicle_nonce' ),
				)
			);
		}

		ob_start();
		?>
		<div class="neoweave-vehicle-panels" data-vehicle-panels-root="1">
			<?php foreach ( $vehicles as $index => $vehicle ) : ?>
				<?php
				$instance_id     = 'nw-vehicle-' . wp_generate_uuid4();
				$blueprint       = isset( $vehicle['cyber_vehicle_blueprints'] ) && is_array( $vehicle['cyber_vehicle_blueprints'] ) ? $vehicle['cyber_vehicle_blueprints'] : array();
				$vehicle_name    = (string) ( $vehicle['custom_name'] ?? '' );
				$blueprint_name  = (string) ( $blueprint['name'] ?? 'Unknown vehicle' );
				$display_name    = '' !== $vehicle_name ? $vehicle_name : $blueprint_name;
				$max_durability  = isset( $blueprint['max_durability'] ) ? (int) $blueprint['max_durability'] : 100;
				$max_fuel        = isset( $blueprint['max_fuel'] ) ? (int) $blueprint['max_fuel'] : 100;
				$current_hp      = isset( $vehicle['current_durability'] ) ? (int) $vehicle['current_durability'] : 0;
				$current_fuel    = isset( $vehicle['current_fuel'] ) ? (int) $vehicle['current_fuel'] : 0;
				$is_active       = ! empty( $vehicle['is_active'] );
				?>
				<div
					id="<?php echo esc_attr( $instance_id ); ?>"
					class="terminal-border neoweave-vehicle-interface<?php echo $is_active ? ' is-active-vehicle' : ''; ?><?php echo 0 === $index ? ' is-first-vehicle' : ''; ?>"
					data-vehicle-panel-root="1"
					data-vehicle-id="<?php echo esc_attr( (string) ( $vehicle['id'] ?? '' ) ); ?>"
					data-character-id="<?php echo esc_attr( $character_id ); ?>"
					data-is-active="<?php echo $is_active ? '1' : '0'; ?>"
				>
					<div class="neoweave-vehicle-header">
						<h3 class="neoweave-neon-text">VEHICLE DIAGNOSTICS</h3>
						<div class="neoweave-vehicle-name">
							<?php echo esc_html( $display_name ); ?>
							<?php if ( $is_active ) : ?>
								<span class="neoweave-vehicle-badge">ACTIVE</span>
							<?php endif; ?>
						</div>
					</div>

					<div class="vehicle-stats">
						<div class="stat-line">
							<span>DURABILITY:</span>
							<?php echo tw_vehicle_panel_render_progress( $current_hp, $max_durability, 'v-hp-bar', 'v-hp-text', '#adff00' ); ?>
						</div>

						<div class="stat-line">
							<span>FUEL/ENERGY:</span>
							<?php echo tw_vehicle_panel_render_progress( $current_fuel, $max_fuel, 'v-fuel-bar', 'v-fuel-text', '#00e5ff' ); ?>
						</div>
					</div>

					<div class="vehicle-blueprint">
						<div class="slot-grid">
							<div class="v-slot core" data-slot="slot_core">
								<label>CORE</label>
								<div class="slot-occupant" data-occupant="core">
									<?php echo esc_html( tw_vehicle_panel_get_module_name( $vehicle, 'slot_core' ) ); ?>
								</div>
							</div>

							<div class="v-slot lateral" data-slot="slot_lateral_l">
								<label>LATERAL L</label>
								<div class="slot-occupant" data-occupant="lateral-l">
									<?php echo esc_html( tw_vehicle_panel_get_module_name( $vehicle, 'slot_lateral_l' ) ); ?>
								</div>
							</div>

							<div class="v-slot lateral" data-slot="slot_lateral_r">
								<label>LATERAL R</label>
								<div class="slot-occupant" data-occupant="lateral-r">
									<?php echo esc_html( tw_vehicle_panel_get_module_name( $vehicle, 'slot_lateral_r' ) ); ?>
								</div>
							</div>

							<div class="v-slot utility" data-slot="slot_utility">
								<label>CARGO/UTIL</label>
								<div class="slot-occupant" data-occupant="utility">
									<?php echo esc_html( tw_vehicle_panel_get_module_name( $vehicle, 'slot_utility' ) ); ?>
								</div>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	add_shortcode( 'neoweave_vehicle_panel', 'tw_vehicle_panel_shortcode' );
}
