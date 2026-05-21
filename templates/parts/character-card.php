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
?>

<div class="tw-side-nav" id="twSideNav">
	<button class="tw-nav-btn" data-tab="status" title="Status" type="button">
		<span class="icon">🧬</span>
	</button>
	<button class="tw-nav-btn" data-tab="inventory" title="Gear" type="button">
		<span class="icon">🎒</span>
	</button>
	<button class="tw-nav-btn" data-tab="weavers" title="Weavers" type="button">
		<span class="icon">🔮</span>
	</button>
	<button class="tw-nav-btn" data-tab="player_quests" title="Quests" type="button">
		<span class="icon">📜</span>
	</button>
	<button class="tw-nav-btn" data-tab="echo" title="Echo" type="button">
		<span class="icon">💠</span>
	</button>
	<button class="tw-nav-btn" data-tab="logs" title="Logs" type="button">
		<span class="icon">💾</span>
	</button>
	<button class="tw-nav-btn" data-tab="player_notes" title="Notes" type="button">
		<span class="icon">📝</span>
	</button>
	<button class="tw-nav-btn" data-tab="loom" title="Loom of Fate" type="button">
		<span class="icon">🃏</span>
	</button>
		<button class="tw-nav-btn" data-tab="vechicles" title="Garage" type="button">
		<span class="icon">C</span>
	</button>
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
					LVL <?php echo (int) ( $char_data['lvl'] ?? 1 ); ?>
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
				<div class="tw-bars-block">

					<div class="tw-stat-bar-container">
						<div class="tw-stat-label main-label">
							<span>HEALTH</span>
							<span><?php echo (int) $c_hp; ?>/<?php echo (int) $m_hp; ?></span>
						</div>
						<div class="tw-progress-bg big-bar bordered">
							<div class="tw-progress-fill <?php echo esc_attr( $hp_class ); ?>" style="width:<?php echo esc_attr( $hp_p ); ?>%;"></div>
						</div>
					</div>

					<div class="tw-stat-bar-container">
						<div class="tw-stat-label main-label">
							<span>ENERGY</span>
							<span><?php echo (int) $c_mp; ?>/<?php echo (int) $m_mp; ?></span>
						</div>
						<div class="tw-progress-bg big-bar bordered">
							<div class="tw-progress-fill mp-blue" style="width:<?php echo esc_attr( $mp_p ); ?>%;"></div>
						</div>
					</div>

					<div class="tw-stat-bar-container">
						<div class="tw-stat-label main-label">
							<span>SYNC-RATE</span>
							<span><?php echo (int) $sync_p; ?>%</span>
						</div>
						<div class="tw-progress-bg big-bar bordered glitch-border">
							<div
								class="tw-progress-fill <?php echo esc_attr( $sync_class ); ?>"
								style="width:<?php echo (int) $sync_p; ?>%;"
							></div>
						</div>
					</div>

					<div class="tw-survival-bars">
						<div class="tw-stat-bar-container small">
							<div class="tw-stat-label small-label">
								<span>SATIETY</span>
								<span><?php echo (int) $c_satiety; ?>%</span>
							</div>
							<div class="tw-progress-bg small-bar">
								<div class="tw-progress-fill satiety-orange" style="width:<?php echo (int) $c_satiety; ?>%;"></div>
							</div>
						</div>

						<div class="tw-stat-bar-container small">
							<div class="tw-stat-label small-label">
								<span>HYDRATION</span>
								<span><?php echo (int) $c_hydration; ?>%</span>
							</div>
							<div class="tw-progress-bg small-bar">
								<div class="tw-progress-fill hydration-cyan" style="width:<?php echo (int) $c_hydration; ?>%;"></div>
							</div>
						</div>

						<div class="tw-stat-bar-container small">
							<div class="tw-stat-label small-label">
								<span>REST</span>
								<span><?php echo (int) $c_rest; ?>%</span>
							</div>
							<div class="tw-progress-bg small-bar">
								<div class="tw-progress-fill rest-purple" style="width:<?php echo (int) $c_rest; ?>%;"></div>
							</div>
						</div>
					</div>

				</div>

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

					<div style="margin-bottom:15px;padding:10px;background:rgba(0,0,0,0.5);border:1px solid #adff00;border-radius:8px;">
						<div style="font-size:0.75rem;color:#adff00;margin-bottom:5px;font-weight:bold;letter-spacing:1px;">ESSENCES</div>
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

						<img src="https://cyber.nieodparady.pl/wp-content/uploads/2026/01/postac.png" class="char-bg" alt="Character">

						<div class="inv-slot" data-slot="head" style="top:0%;left:50%;transform:translateX(-50%);">
							<span class="slot-label">HEAD</span><div class="item-icon"></div>
						</div>
						<div class="inv-slot tiny" data-slot="neck" style="top:12%;left:50%;transform:translateX(-50%);">
							<span class="slot-label">NECK</span><div class="item-icon"></div>
						</div>
						<div class="inv-slot" data-slot="torso" style="top:22%;left:52%;transform:translateX(-50%);">
							<span class="slot-label">TORSO</span><div class="item-icon"></div>
						</div>
						<div class="inv-slot" data-slot="bag" style="top:20%;left:9%;transform:translateX(-50%);">
							<span class="slot-label">BAG</span><div class="item-icon"></div>
						</div>
						<div class="inv-slot" data-slot="pouch" style="top:20%;right:0%;">
							<span class="slot-label">POUCH</span><div class="item-icon"></div>
						</div>
						<div class="belt-section">
							<span style="font-size:0.5rem;letter-spacing:1px;">UTILITY BELT</span>
							<div class="belt-slots">
								<div class="inv-slot tiny" data-slot="belt_1"><div class="item-icon"></div></div>
								<div class="inv-slot tiny" data-slot="belt_2"><div class="item-icon"></div></div>
								<div class="inv-slot tiny" data-slot="belt_3"><div class="item-icon"></div></div>
							</div>
						</div>
						<div class="inv-slot" data-slot="hand_l" style="top:46%;left:6%;">
							<span class="slot-label">LEFT HAND</span><div class="item-icon"></div>
						</div>
						<div class="inv-slot" data-slot="hand_r" style="top:46%;right:6%;">
							<span class="slot-label">RIGHT HAND</span><div class="item-icon"></div>
						</div>
						<div class="inv-slot tiny" data-slot="ring_1" style="top:58%;left:12%;">
							<span class="slot-label">RING</span><div class="item-icon"></div>
						</div>
						<div class="inv-slot tiny" data-slot="ring_2" style="top:58%;right:12%;">
							<span class="slot-label">RING</span><div class="item-icon"></div>
						</div>
						<div class="inv-slot" data-slot="legs" style="top:90%;left:50%;transform:translateX(-50%);">
							<span class="slot-label">LEGS</span><div class="item-icon"></div>
						</div>
					</div>

					<div id="tw-inventory-app" style="margin-top:20px;">
						<h4 class="tw-inv-title" style="font-size:0.8rem;border-bottom:1px solid #adff00;padding-bottom:5px;">
							CARRIED ITEMS
						</h4>
						<div id="tw-inventory-list" class="tw-item-list" style="min-height:50px;">
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
									style="background:rgba(255,255,255,0.05);margin-bottom:2px;padding:5px;cursor:grab;"
								>
									<span class="tw-item-name" style="font-size:0.85rem;">
										<?php echo esc_html( $it['name'] ); ?>
										<small style="opacity:0.6;"> x<?php echo (int) $r['quantity']; ?></small>
										<?php if ( isset( $it['mass'] ) ) : ?>
											<small style="float:right;color:#adff00;"><?php echo esc_html( $it['mass'] ); ?> kg</small>
										<?php endif; ?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

				</div>
			</div>

			<div class="tw-tab-content" id="player_quests">
				<div class="tw-gold-hud">
					<div class="tw-gold-label">MISSIONS</div>
				</div>
				<?php echo do_shortcode( '[active_scenarios]' ); ?>
			</div>

			<div class="tw-tab-content" id="logs">
				<div class="tw-logs-list">
					<?php if ( empty( $logs_data ) ) : ?>
						<p class="tw-bio-text">Logs empty.</p>
					<?php endif; ?>

					<?php foreach ( $logs_data as $log ) : ?>
						<?php
						$log_date = '';
						if ( ! empty( $log['created_at'] ) ) {
							$ts = is_numeric( $log['created_at'] )
								? (int) $log['created_at']
								: strtotime( $log['created_at'] );
							$log_date = ( false !== $ts && $ts > 0 ) ? date( 'd.m.Y H:i', $ts ) : '';
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
				</div>
			</div>

			<div class="tw-tab-content" id="player_notes">
				<div class="tw-notes-tab-container">
					<textarea class="tw-notes-area" id="twNotesField" placeholder="Enter notes..."><?php echo esc_textarea( $char_data['notes'] ?? '' ); ?></textarea>
					<button class="tw-save-notes-btn" id="twSaveNotes" type="button" data-char-id="<?php echo esc_attr( (string) $char_id ); ?>">SYNC DATA</button>
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
			<div class="tw-tab-content" id="vechicles">
				<?php echo do_shortcode( '[neoweave_vehicle_panel]' ); ?>
			</div>

		</div>
	</div>
</div>
