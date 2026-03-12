<?php
?>
<script>
window.twAdventureData = window.twAdventureData || {};
window.twAdventureData.supabase_url         = '<?= esc_js( tw_supabase_url() ); ?>';
window.twAdventureData.supabase_anon_key    = '<?= esc_js( tw_supabase_anon_key() ); ?>';
window.twAdventureData.active_character_id  = <?= (int) $char_id; ?>;

document.addEventListener('DOMContentLoaded', function() {
    const charPanel = document.getElementById('charPanel');
    const syncFill = document.querySelector('.tw-progress-fill.sync-stable, .tw-progress-fill.sync-warning, .tw-progress-fill.sync-critical');
    
    if (!syncFill || !charPanel) return;

    // Pobieramy wartość procentową z szerokości paska (ustawionej przez PHP)
    const syncValue = parseInt(syncFill.style.width);

    function applyGlitchEffects(value) {
        // RESET EFEKTÓW
        charPanel.style.transform = "none";
        charPanel.style.filter = "none";
        charPanel.classList.remove('glitch-active');

        if (value <= 20) {
            // POZIOM KRYTYCZNY: Silne drżenie i artefakty
            charPanel.classList.add('glitch-active');
            startHardGlitch();
        } else if (value <= 50) {
            // POZIOM OSTRZEGAWCZY: Lekkie migotanie i desaturacja
            charPanel.style.filter = "contrast(1.2) brightness(1.1) sepia(0.2)";
            startLightShake();
        }
    }

    function startLightShake() {
        setInterval(() => {
            if (Math.random() > 0.95) {
                charPanel.style.transform = `translate(${Math.random() * 2 - 1}px, ${Math.random() * 2 - 1}px)`;
                setTimeout(() => charPanel.style.transform = "none", 50);
            }
        }, 100);
    }

    function startHardGlitch() {
        setInterval(() => {
            if (Math.random() > 0.8) {
                const x = Math.random() * 6 - 3;
                const y = Math.random() * 6 - 3;
                const skew = Math.random() * 2 - 1;
                charPanel.style.transform = `translate(${x}px, ${y}px) skewX(${skew}deg)`;
                charPanel.style.filter = `hue-rotate(${Math.random() * 90}deg) contrast(1.5)`;
                
                setTimeout(() => {
                    charPanel.style.transform = "none";
                    charPanel.style.filter = "none";
                }, 70);
            }
        }, 150);
    }

    applyGlitchEffects(syncValue);
});
</script>

<!-- Nawigacja boczna (Prawa) -->
<div class="tw-side-nav" id="twSideNav">
    <!-- Status: Serce lub DNA (klimat cyber) -->
    <button class="tw-nav-btn" data-tab="status" title="Status">
        <span class="icon">🧬</span>
    </button>
    
    <!-- Ekwipunek: Plecak -->
    <button class="tw-nav-btn" data-tab="inventory" title="Gear">
        <span class="icon">🎒</span>
    </button>
    
    <!-- Weavers (Magia/Umiejętności): Kryształ lub Błyskawica -->
    <button class="tw-nav-btn" data-tab="weavers" title="Weavers">
        <span class="icon">🔮</span>
    </button>
    
    <!-- Questy: Zwój lub Cel -->
    <button class="tw-nav-btn" data-tab="player_quests" title="Quests">
        <span class="icon">📜</span>
    </button>
    
    <!-- Echo: Znak cyklonu/powtarzania lub Dymek -->
    <button class="tw-nav-btn" data-tab="echo" title="Echo">
        <span class="icon">💠</span>
    </button>
    
    <!-- Logi: Dyskietka (Dane) lub Notatnik -->
    <button class="tw-nav-btn" data-tab="logs" title="Logs">
        <span class="icon">💾</span>
    </button>
    
    <!-- Notatki: Ołówek -->
    <button class="tw-nav-btn" data-tab="player_notes" title="Notes">
        <span class="icon">📝</span>
    </button>
    
    <!-- Loom of Fate: Karta (Joker) -->
    <button class="tw-nav-btn" data-tab="loom" title="Loom of Fate">
        <span class="icon">🃏</span>
    </button>
</div>

<div class="tw-character-panel-container" id="charPanel">
    <div class="tw-character-card">
<div class="tw-char-header">
    <div class="tw-char-avatar" style="background-image:url('<?= esc_url( $char_data['avatar'] ?? '' ); ?>');"></div>
    <div class="tw-char-info">
        <div class="tw-lvl-frame">
            LVL <?= (int) ( $char_data['lvl'] ?? 1 ); ?>
        </div>

        <h3 class="tw-char-name">
            <?= esc_html( $char_data['name'] ); ?>
        </h3>

        <div class="tw-char-class-line">
            <?= esc_html( $char_data['race'] ?? 'Human' ); ?>
            //
            <span class="highlight">
                <?= esc_html( $char_data['class'] ?? 'Mercenary' ); ?>
            </span>
        </div>

        <div class="tw-char-gold-line">
            <span class="tw-gold-label">credits:</span>
            <!-- <span class="tw-gold-value"> -->
                <?= (int) ( $char_data['gold'] ?? 0 ); ?>
           <!-- </span> -->
        </div>
    </div>
</div>

        <div class="tw-panel-scroll-area">
            <!-- STATUS -->
            <div class="tw-tab-content active" id="status">
<div class="tw-bars-block">
    <div class="tw-stat-bar-container">
        <div class="tw-stat-label main-label">
            <span>HEALTH</span>
            <span><?= (int) $c_hp; ?>/<?= (int) $m_hp; ?></span>
        </div>
        <div class="tw-progress-bg big-bar bordered">
            <div class="tw-progress-fill <?= esc_attr( $hp_class ); ?>" style="width:<?= esc_attr( $hp_p ); ?>%;"></div>
        </div>
    </div>

    <div class="tw-stat-bar-container">
        <div class="tw-stat-label main-label">
            <span>ENERGY</span>
            <span><?= (int) $c_mp; ?>/<?= (int) $m_mp; ?></span>
        </div>
        <div class="tw-progress-bg big-bar bordered">
            <div class="tw-progress-fill mp-blue" style="width:<?= esc_attr( $mp_p ); ?>%;"></div>
        </div>
    </div>
	<div class="tw-stat-bar-container">
        <div class="tw-stat-label main-label">
            <span>SYNC-RATE</span>
            <span><?= (int) $sync_p; ?>%</span>
        </div>
        <div class="tw-progress-bg big-bar bordered glitch-border">
            <div class="tw-progress-fill <?= esc_attr( $sync_class ); ?>" style="width:<?= esc_attr( $sync_p ); ?>%;"></div>
        </div>
    </div>

    <div class="tw-survival-bars">
        <div class="tw-stat-bar-container small">
            <div class="tw-stat-label small-label">
                <span>SATIETY</span>
                <span><?= $c_satiety; ?>%</span>
            </div>
            <div class="tw-progress-bg small-bar">
                <div class="tw-progress-fill satiety-orange" style="width:<?= $c_satiety; ?>%;"></div>
            </div>
        </div>

        <div class="tw-stat-bar-container small">
            <div class="tw-stat-label small-label">
                <span>HYDRATION</span>
                <span><?= $c_hydration; ?>%</span>
            </div>
            <div class="tw-progress-bg small-bar">
                <div class="tw-progress-fill hydration-cyan" style="width:<?= $c_hydration; ?>%;"></div>
            </div>
        </div>

        <div class="tw-stat-bar-container small">
            <div class="tw-stat-label small-label">
                <span>REST</span>
                <span><?= $c_rest; ?>%</span>
            </div>
            <div class="tw-progress-bg small-bar">
                <div class="tw-progress-fill rest-purple" style="width:<?= $c_rest; ?>%;"></div>
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
                                <span class="tw-at-v"><?= (int) ( $char_data['body'] ?? 0 ); ?></span>
                            </div>
                            <div class="tw-attr-box">
                                <span class="tw-at-l">MIND</span>
                                <span class="tw-at-v"><?= (int) ( $char_data['mind'] ?? 0 ); ?></span>
                            </div>
                            <div class="tw-attr-box">
                                <span class="tw-at-l">REFL</span>
                                <span class="tw-at-v"><?= (int) ( $char_data['reflex'] ?? 0 ); ?></span>
                            </div>
                            <div class="tw-attr-box">
                                <span class="tw-at-l">SPRT</span>
                                <span class="tw-at-v"><?= (int) ( $char_data['spirit'] ?? 0 ); ?></span>
                            </div>
                        </div>
                    </details>

                    <details>
                        <summary>Skills & Abilities</summary>
                        <div class="tw-skills-list">
                            <?php foreach ( $skills_and_abilities as $r ) :
                                $d = $r['info'] ?? null;
                                if ( ! $d ) {
                                    continue;
                                }
                                ?>
                                <button class="tw-skill-chip" type="button">
                                    <span class="tw-skill-chip-name">
                                        <?= esc_html( $d['name'] ); ?>
                                    </span>
                                    <?php if ( isset( $d['cost'] ) ) : ?>
                                        <span class="tw-skill-chip-cost">
                                            <?= esc_html( $d['cost'] ); ?>
                                        </span>
                                    <?php endif; ?>

                                    <div class="tw-skill-tooltip">
                                        <div class="tw-skill-tooltip-header">
                                            <?= esc_html( $d['name'] ); ?>
                                        </div>
                                        <div class="tw-skill-tooltip-body">
                                            <?= esc_html( $d['description'] ); ?>
                                        </div>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </details>

                    <details>
                        <summary>Biography</summary>
                        <div class="tw-bio-text">
                            <?= nl2br(
                                esc_html(
                                    $char_data['bio'] ?? 'No data found in neural link.'
                                )
                            ); ?>
                        </div>
                    </details>
                </div>
            </div>

<!-- INVENTORY -->
<div class="tw-tab-content" id="inventory">
    <div class="equipment-container">
        <!-- ESENCJE NAD PAPERDOLL -->
        <div style="margin-bottom: 15px; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid #adff00; border-radius: 8px;">
            <div style="font-size: 0.75rem; color: #adff00; margin-bottom: 5px; font-weight: bold; letter-spacing: 1px;">ESSENCES</div>
            <?php echo do_shortcode('[tw_essences]'); ?>
        </div>
        <div class="paperdoll-wrapper">
											<div class="corner-stat stat-left">
                            <span class="stat-label">LOAD (KG)</span>
                            <span class="stat-value">
                                <?= $total_mass; ?> / <?= $mass_limit; ?>
                            </span>
                        </div>

                        <div class="corner-stat stat-right">
                            <span class="stat-label">COMBAT</span>
                            <span class="stat-value" id="total-power-value">
                                <?= isset( $total_power ) ? (int) $total_power : 0; ?>
                            </span>
                        </div>

                        <img src="https://cyber.nieodparady.pl/wp-content/uploads/2026/01/postac.png" class="char-bg" alt="Character">

                        <div class="inv-slot" data-slot="head" style="top: 0%; left: 50%; transform: translateX(-50%);">
                            <span class="slot-label">HEAD</span>
                            <div class="item-icon"></div>
                        </div>

                        <div class="inv-slot tiny" data-slot="neck" style="top: 12%; left: 50%; transform: translateX(-50%);">
                            <span class="slot-label">NECK</span>
                            <div class="item-icon"></div>
                        </div>

                        <div class="inv-slot" data-slot="torso" style="top: 22%; left: 52%; transform: translateX(-50%);">
                            <span class="slot-label">TORSO</span>
                            <div class="item-icon"></div>
                        </div>

                        <div class="inv-slot" data-slot="bag" style="top: 20%; left: 9%; transform: translateX(-50%);">
                            <span class="slot-label">BAG</span>
                            <div class="item-icon"></div>
                        </div>

                        <div class="inv-slot" data-slot="pouch" style="top: 20%; right: 0%;">
                            <span class="slot-label">POUCH</span>
                            <div class="item-icon"></div>
                        </div>

                        <div class="belt-section">
                            <span style="font-size: 0.5rem; letter-spacing: 1px;">UTILITY BELT</span>
                            <div class="belt-slots">
                                <div class="inv-slot tiny" data-slot="belt_1"><div class="item-icon"></div></div>
                                <div class="inv-slot tiny" data-slot="belt_2"><div class="item-icon"></div></div>
                                <div class="inv-slot tiny" data-slot="belt_3"><div class="item-icon"></div></div>
                            </div>
                        </div>

                        <div class="inv-slot" data-slot="hand_l" style="top: 46%; left: 6%;">
                            <span class="slot-label">LEFT HAND</span>
                            <div class="item-icon"></div>
                        </div>

                        <div class="inv-slot" data-slot="hand_r" style="top: 46%; right: 6%;">
                            <span class="slot-label">RIGHT HAND</span>
                            <div class="item-icon"></div>
                        </div>

                        <div class="inv-slot tiny" data-slot="ring_1" style="top: 58%; left: 12%;">
                            <span class="slot-label">RING</span>
                            <div class="item-icon"></div>
                        </div>

                        <div class="inv-slot tiny" data-slot="ring_2" style="top: 58%; right: 12%;">
                            <span class="slot-label">RING</span>
                            <div class="item-icon"></div>
                        </div>

                        <div class="inv-slot" data-slot="legs" style="top: 90%; left: 50%; transform: translateX(-50%);">
                            <span class="slot-label">LEGS</span>
                            <div class="item-icon"></div>
                        </div>
                    </div>

                    <div id="tw-inventory-app" style="margin-top: 20px;">
                        <h4 class="tw-inv-title" style="font-size: 0.8rem; border-bottom: 1px solid #adff00; padding-bottom: 5px;">
                            CARRIED ITEMS
                        </h4>

                        <div id="tw-inventory-list" class="tw-item-list" style="min-height: 50px;">
                            <?php foreach ( $inventory as $r ) :
                                $it = $r['info'] ?? null;
                                if ( $it && ( ! isset( $r['is_equipped'] ) || $r['is_equipped'] == false ) ) : ?>
                                    <div class="tw-item-card"
                                         draggable="true"
                                         data-inventory-id="<?= esc_attr( $r['id'] ); ?>"
                                         data-item-slot="<?= esc_attr( $it['slot'] ?? '' ); ?>"
                                         style="background: rgba(255,255,255,0.05); margin-bottom: 2px; padding: 5px; cursor: grab;">
                                        <span class="tw-item-name" style="font-size: 0.85rem;">
                                            <?= esc_html( $it['name'] ); ?>
                                            <small style="opacity: 0.6;"> x<?= (int) $r['quantity']; ?></small>
                                            <?php if ( isset( $it['mass'] ) ) : ?>
                                                <small style="float: right; color: #adff00;">
                                                    <?= esc_html( $it['mass'] ); ?> kg
                                                </small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif;
                            endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- QUESTS -->
            <div class="tw-tab-content" id="player_quests">
                <div class="tw-gold-hud">
                    <div class="tw-gold-label">MISSIONS</div>
                </div>
                <?php echo do_shortcode( '[active_scenarios]' ); ?>
            </div>

            <!-- LOGS -->
            <div class="tw-tab-content" id="logs">
                <div class="tw-logs-list">
                    <?php if ( empty( $logs_data ) ) : ?>
                        <p class="tw-bio-text">Logs empty.</p>
                    <?php endif; ?>

                    <?php foreach ( $logs_data as $log ) : ?>
                        <div class="tw-log-entry">
                            <small class="tw-log-date">
                                <?= esc_html( date( 'd.m.Y H:i', strtotime( $log['created_at'] ) ) ); ?>
                            </small>
                            <p class="tw-log-text">
                                <?= nl2br( esc_html( $log['log'] ) ); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- NOTES -->
            <div class="tw-tab-content" id="player_notes">
				<div class="tw-notes-tab-container">
                <textarea class="tw-notes-area" id="twNotesField" placeholder="Enter notes..."><?= esc_textarea( $char_data['notes'] ?? '' ); ?></textarea>
                <button class="tw-save-notes-btn" id="twSaveNotes" data-char-id="<?= (int) $char_id; ?>">SYNC DATA</button>
				</div></div>

            <!-- ECHO -->
            <div class="tw-tab-content" id="echo">
                <?php echo do_shortcode( '[character_echo]' ); ?>
            </div>
			
			            <!-- weavers -->
            <div class="tw-tab-content" id="weavers">
                <?php echo do_shortcode( '[tw_weaver_list]' ); ?>
            </div>

            <!-- LOOM -->
            <div class="tw-tab-content" id="loom">
                <?php echo do_shortcode( '[tw_loom_of_fate]' ); ?>
            </div>
        </div>
    </div>
</div>
