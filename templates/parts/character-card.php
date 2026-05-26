<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = wp_parse_args(
	$args ?? array(),
	array(
		'char_id'              => '',
		'char_data'            => array(),
		'c_hp'                 => 0,
		'm_hp'                 => 0,
		'hp_class'             => '',
		'c_mp'                 => 0,
		'm_mp'                 => 0,
		'mp_p'                 => 0,
		'sync_p'               => 0,
		'sync_class'           => '',
		'c_satiety'            => 0,
		'c_hydration'          => 0,
		'c_rest'               => 0,
		'skills_and_abilities' => array(),
		'inventory'            => array(),
		'logs_data'            => array(),
		'total_mass'           => 0,
		'mass_limit'           => 0,
		'total_power'          => 0,
	)
);

$char_id              = (string) $args['char_id'];
$char_data            = is_array( $args['char_data'] ) ? $args['char_data'] : array();
$c_hp                 = (int) $args['c_hp'];
$m_hp                 = (int) $args['m_hp'];
$hp_class             = (string) $args['hp_class'];
$c_mp                 = (int) $args['c_mp'];
$m_mp                 = (int) $args['m_mp'];
$mp_p                 = (int) $args['mp_p'];
$sync_p               = (int) $args['sync_p'];
$sync_class           = (string) $args['sync_class'];
$c_satiety            = (int) $args['c_satiety'];
$c_hydration          = (int) $args['c_hydration'];
$c_rest               = (int) $args['c_rest'];
$skills_and_abilities = is_array( $args['skills_and_abilities'] ) ? $args['skills_and_abilities'] : array();
$inventory            = is_array( $args['inventory'] ) ? $args['inventory'] : array();
$logs_data            = is_array( $args['logs_data'] ) ? $args['logs_data'] : array();
$total_mass           = $args['total_mass'];
$mass_limit           = $args['mass_limit'];
$total_power          = $args['total_power'];

// Pet slot/tab unlocked at level 3
$char_lvl     = (int) ( $char_data['lvl'] ?? 1 );
$has_pet_slot = $char_lvl >= 3;

// Build equipped items map: slot => item row
$equipped_by_slot = array();
foreach ( $inventory as $r ) {
	if ( ! empty( $r['is_equipped'] ) && ! empty( $r['info']['slot'] ) ) {
		$equipped_by_slot[ $r['info']['slot'] ] = $r;
	}
}
?>

<div class="tw-side-nav" id="twSideNav">
	<button class="tw-nav-btn" data-tab="status" title="Status" aria-label="Status" type="button">
		<i data-lucide="activity"></i>
	</button>
	<button class="tw-nav-btn" data-tab="inventory" title="Gear" aria-label="Gear" type="button">
		<i data-lucide="backpack"></i>
	</button>
	<button class="tw-nav-btn" data-tab="weavers" title="Weavers" aria-label="Weavers" type="button">
		<i data-lucide="wand-sparkles"></i>
	</button>
	<button class="tw-nav-btn" data-tab="player_quests" title="Quests" aria-label="Quests" type="button">
		<i data-lucide="scroll-text"></i>
	</button>
	<button class="tw-nav-btn" data-tab="echo" title="Echo" aria-label="Echo" type="button">
		<i data-lucide="radio"></i>
	</button>
	<button class="tw-nav-btn" data-tab="logs" title="Logs" aria-label="Logs" type="button">
		<i data-lucide="terminal"></i>
	</button>
	<button class="tw-nav-btn" data-tab="player_notes" title="Notes" aria-label="Notes" type="button">
		<i data-lucide="notebook-pen"></i>
	</button>
	<button class="tw-nav-btn" data-tab="loom" title="Loom of Fate" aria-label="Loom of Fate" type="button">
		<i data-lucide="layers"></i>
	</button>
	<button class="tw-nav-btn" data-tab="vehicles" title="Garage" aria-label="Garage" type="button">
		<i data-lucide="car"></i>
	</button>
	<?php if ( $has_pet_slot ) : ?>
	<button class="tw-nav-btn" data-tab="pets" title="Pets" aria-label="Pets" type="button">
		<i data-lucide="paw-print"></i>
	</button>
	<?php endif; ?>
</div>

<div
	class="tw-character-panel-container"
	id="charPanel"
	data-sync-value="<?php echo esc_attr( (int) $sync_p ); ?>"
>
	<div class="tw-character-card">

		<div class="tw-char-header">
			<div
				class="tw-char-avatar"
				style="background-image:url('<?php echo esc_url( $char_data['avatar'] ?? '' ); ?>');"
			></div>

			<div class="tw-char-info">
				<div class="tw-lvl-frame">
					LVL <?php echo $char_lvl; ?>
				</div>

				<h3 class="tw-char-name">
					<?php echo esc_html( $char_data['name'] ?? '' ); ?>
				</h3>

				<div class="tw-char-class-line">
					<?php echo esc_html( $char_data['race'] ?? 'Human' ); ?>
					//
					<span class="highlight">
						<?php echo esc_html( $char_data['class'] ?? 'Mercenary' ); ?>
					</span>
				</div>

				<div class="tw-char-gold-line">
					<span class="tw-gold-label">credits:</span>
					<?php echo (int) ( $char_data['gold'] ?? 0 ); ?>
				</div>
			</div>
		</div>

		<div class="tw-panel-scroll-area">

			<div class="tw-tab-content active" id="status">

				<?php
				$vitalis_args = array(
					'c_hp'        => $c_hp,
					'm_hp'        => $m_hp,
					'c_mp'        => $c_mp,
					'm_mp'        => $m_mp,
					'sync_p'      => $sync_p,
					'c_satiety'   => $c_satiety,
					'c_hydration' => $c_hydration,
					'c_rest'      => $c_rest,
				);

				$vitalis_partial = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . 'templates/partials/character-vitalis.php';

				if ( file_exists( $vitalis_partial ) ) {
					// FIX: use separate variable so $args is not overwritten
					$vitalis_data = $vitalis_args;
					include $vitalis_partial;
					unset( $vitalis_data );
				}
				?>

				<div class="tw-accordion-group">
					<details>
						<summary>Attributes</summary>
						<div class="tw-attr-grid">
							<div class="tw-attr-box">
								<span class="tw-at-l">BODY</span>
								<span class="tw-at-v"><?php echo (int) ( $char_data['body'] ?? 0 ); ?></span>
							</div>
							<div class="tw-attr-box">
								<span class="tw-at-l">MIND</span>
								<span class="tw-at-v"><?php echo (int) ( $char_data['mind'] ?? 0 ); ?></span>
							</div>
							<div class="tw-attr-box">
								<span class="tw-at-l">REFL</span>
								<span class="tw-at-v"><?php echo (int) ( $char_data['reflex'] ?? 0 ); ?></span>
							</div>
							<div class="tw-attr-box">
								<span class="tw-at-l">SPRT</span>
								<span class="tw-at-v"><?php echo (int) ( $char_data['spirit'] ?? 0 ); ?></span>
							</div>
						</div>
					</details>

					<details>
						<summary>Skills &amp; Abilities</summary>
						<div class="tw-skills-list">
							<?php foreach ( $skills_and_abilities as $r ) : ?>
								<?php
								$d = $r['info'] ?? null;
								if ( ! $d ) {
									continue;
								}
								?>
								<button class="tw-skill-chip" type="button">
									<span class="tw-skill-chip-name">
										<?php echo esc_html( $d['name'] ); ?>
									</span>

									<?php if ( isset( $d['cost'] ) ) : ?>
										<span class="tw-skill-chip-cost">
											<?php echo esc_html( $d['cost'] ); ?>
										</span>
									<?php endif; ?>

									<div class="tw-skill-tooltip">
										<div class="tw-skill-tooltip-header">
											<?php echo esc_html( $d['name'] ); ?>
										</div>
										<div class="tw-skill-tooltip-body">
											<?php echo esc_html( $d['description'] ); ?>
										</div>
									</div>
								</button>
							<?php endforeach; ?>
						</div>
					</details>

					<details>
						<summary>Biography</summary>
						<div class="tw-bio-text">
							<?php echo nl2br( esc_html( $char_data['bio'] ?? 'No data found in neural link.' ) ); ?>
						</div>
					</details>
				</div>
			</div>

			<div class="tw-tab-content" id="inventory">
				<div class="equipment-container">

					<div class="tw-essences-block">
						<div class="tw-inv-title">ESSENCES</div>
						<?php echo do_shortcode( '[tw_essences]' ); ?>
					</div>

					<div class="paperdoll-wrapper">
						<div class="corner-stat stat-left">
							<span class="stat-label">LOAD (KG)</span>
							<span class="stat-value"><?php echo esc_html( $total_mass ); ?> / <?php echo esc_html( $mass_limit ); ?></span>
						</div>

						<div class="corner-stat stat-right">
							<span class="stat-label">COMBAT</span>
							<span class="stat-value" id="total-power-value">
								<?php echo isset( $total_power ) ? (int) $total_power : 0; ?>
							</span>
						</div>

						<?php
						// FIX: use NEOWEAVER_PLUGIN_URL instead of hardcoded domain
						$paperdoll_url = trailingslashit( NEOWEAVER_PLUGIN_URL ) . 'assets/images/postac.png';
						?>
						<img src="<?php echo esc_url( $paperdoll_url ); ?>" class="char-bg" alt="Character" width="300" height="420" loading="lazy">

						<?php
						// Helper: render a single paperdoll slot, fill with equipped item if present
						$render_slot = function( $slot_key, $label, $style = '', $extra_class = '' ) use ( $equipped_by_slot ) {
							$r  = $equipped_by_slot[ $slot_key ] ?? null;
							$it = $r['info'] ?? null;
							$style_attr = $style ? ' style="' . esc_attr( $style ) . '"' : '';
							$classes    = 'inv-slot' . ( $extra_class ? ' ' . $extra_class : '' ) . ( $it ? ' is-equipped' : '' );
							echo '<div class="' . esc_attr( $classes ) . '" data-slot="' . esc_attr( $slot_key ) . '"' . $style_attr . '>';
							if ( $label ) {
								echo '<span class="slot-label">' . esc_html( $label ) . '</span>';
							}
							echo '<div class="item-icon">';
							if ( $it ) {
								echo '<span class="item-icon-name" title="' . esc_attr( $it['name'] ) . '">' . esc_html( $it['name'] ) . '</span>';
							}
							echo '</div>';
							echo '</div>';
						};
						?>

						<?php $render_slot( 'head',   'HEAD',       'top:0%;left:50%;transform:translateX(-50%);' ); ?>
						<?php $render_slot( 'neck',   'NECK',       'top:12%;left:50%;transform:translateX(-50%);', 'tiny' ); ?>
						<?php $render_slot( 'torso',  'TORSO',      'top:22%;left:52%;transform:translateX(-50%);' ); ?>
						<?php $render_slot( 'bag',    'BAG',        'top:20%;left:9%;transform:translateX(-50%);' ); ?>
						<?php $render_slot( 'pouch',  'POUCH',      'top:20%;right:0%;' ); ?>

						<div class="belt-section">
							<span class="belt-label">UTILITY BELT</span>
							<div class="belt-slots">
								<?php $render_slot( 'belt_1', '', '', 'tiny' ); ?>
								<?php $render_slot( 'belt_2', '', '', 'tiny' ); ?>
								<?php $render_slot( 'belt_3', '', '', 'tiny' ); ?>
							</div>
						</div>

						<?php $render_slot( 'hand_l', 'LEFT HAND',  'top:46%;left:6%;' ); ?>
						<?php $render_slot( 'hand_r', 'RIGHT HAND', 'top:46%;right:6%;' ); ?>
						<?php $render_slot( 'ring_1', 'RING',       'top:58%;left:12%;', 'tiny' ); ?>
						<?php $render_slot( 'ring_2', 'RING',       'top:58%;right:12%;', 'tiny' ); ?>
						<?php $render_slot( 'legs',   'LEGS',       'top:90%;left:50%;transform:translateX(-50%);' ); ?>

						<?php if ( $has_pet_slot ) : ?>
							<?php $render_slot( 'pet', 'PET', 'top:105%;left:50%;transform:translateX(-50%);', 'pet-slot' ); ?>
						<?php endif; ?>
					</div>

					<div id="tw-inventory-app">
						<h4 class="tw-inv-title">CARRIED ITEMS</h4>
						<div id="tw-inventory-list" class="tw-item-list">
							<?php foreach ( $inventory as $r ) : ?>
								<?php
								$it = $r['info'] ?? null;
								if ( ! $it || ! empty( $r['is_equipped'] ) ) {
									continue;
								}
								?>
								<div
									class="tw-item-card"
									draggable="true"
									data-inventory-id="<?php echo esc_attr( $r['id'] ); ?>"
									data-item-slot="<?php echo esc_attr( $it['slot'] ?? '' ); ?>"
								>
									<span class="tw-item-name">
										<?php echo esc_html( $it['name'] ); ?>
										<small class="tw-item-qty">x<?php echo (int) $r['quantity']; ?></small>
										<?php if ( isset( $it['mass'] ) ) : ?>
											<small class="tw-item-mass"><?php echo esc_html( $it['mass'] ); ?> kg</small>
										<?php endif; ?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

				</div>
			</div>

			<div class="tw-tab-content" id="player_quests">
				<div class="tw-inv-title">MISSIONS</div>
				<?php echo do_shortcode( '[active_scenarios]' ); ?>
			</div>

			<div class="tw-tab-content" id="logs">
				<div class="tw-logs-list">
					<?php if ( empty( $logs_data ) ) : ?>
						<p class="tw-bio-text">Logs empty.</p>
					<?php else : ?>
						<?php foreach ( $logs_data as $log ) : ?>
							<?php
							$log_date = '';
							if ( ! empty( $log['created_at'] ) ) {
								$ts = is_numeric( $log['created_at'] )
									? (int) $log['created_at']
									: strtotime( $log['created_at'] );
								// FIX: use wp_date() for timezone-aware formatting (respects WP Settings > Timezone)
								$log_date = ( false !== $ts && $ts > 0 ) ? wp_date( 'd.m.Y H:i', $ts ) : '';
							}
							?>
							<div class="tw-log-entry">
								<?php if ( $log_date ) : ?>
									<small class="tw-log-date"><?php echo esc_html( $log_date ); ?></small>
								<?php endif; ?>
								<p class="tw-log-text">
									<?php echo nl2br( esc_html( $log['log'] ?? '' ) ); ?>
								</p>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="tw-tab-content" id="player_notes">
				<div class="tw-notes-tab-container">
					<textarea
						class="tw-notes-area"
						id="twNotesField"
						placeholder="Enter notes..."
					><?php echo esc_textarea( $char_data['notes'] ?? '' ); ?></textarea>
					<button
						class="tw-save-notes-btn"
						id="twSaveNotes"
						type="button"
						data-char-id="<?php echo esc_attr( (string) $char_id ); ?>"
					>SYNC DATA</button>
				</div>
			</div>

			<div class="tw-tab-content" id="echo">
				<?php echo do_shortcode( '[character_echo]' ); ?>
			</div>

			<div class="tw-tab-content" id="weavers">
				<?php echo do_shortcode( '[tw_weaver_list]' ); ?>
			</div>

			<div class="tw-tab-content" id="loom">
				<?php echo do_shortcode( '[tw_loom_of_fate]' ); ?>
			</div>

			<div class="tw-tab-content" id="vehicles">
				<?php echo do_shortcode( '[neoweave_vehicle_panel]' ); ?>
			</div>

			<?php if ( $has_pet_slot ) : ?>
			<div class="tw-tab-content" id="pets">
				<?php echo do_shortcode( '[neoweaver_pets]' ); ?>
			</div>
			<?php endif; ?>

		</div>
	</div>
</div>
