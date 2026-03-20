<?php
?>
<script>
window.twAdventureData = window.twAdventureData || {};
window.twAdventureData.supabase_url        = '<?= esc_js( tw_supabase_url() ); ?>';
window.twAdventureData.active_character_id = <?= (int) $char_id; ?>;

document.addEventListener('DOMContentLoaded', function() {
    const charPanel = document.getElementById('charPanel');
    if ( ! charPanel ) return;

    // FIX 1: Scope the selector inside charPanel, not globally.
    // FIX 2: Use querySelectorAll + find, so the comma-list doesn't match
    //         a sibling panel on the page.
    const syncFill = charPanel.querySelector(
        '.tw-progress-fill.sync-stable, ' +
        '.tw-progress-fill.sync-warning, ' +
        '.tw-progress-fill.sync-critical'
    );
    if ( ! syncFill ) return;

    // FIX 3: style.width arrives as "42%" — parseFloat is safer than parseInt
    //         and we guard against NaN so glitch never runs on bad data.
    const syncValue = parseFloat( syncFill.style.width );
    if ( isNaN( syncValue ) ) return;

    // FIX 4: Store interval IDs so we can clear them if needed (e.g. on
    //         tab switch or component teardown). Without this the callbacks
    //         keep firing and compound each time the template re-renders.
    let shakeInterval = null;
    let glitchInterval = null;

    function clearGlitchIntervals() {
        if ( shakeInterval  !== null ) { clearInterval( shakeInterval );  shakeInterval  = null; }
        if ( glitchInterval !== null ) { clearInterval( glitchInterval ); glitchInterval = null; }
    }

    function applyGlitchEffects( value ) {
        // Always clear before applying new state so timers don't stack.
        clearGlitchIntervals();

        charPanel.style.transform = '';
        charPanel.style.filter    = '';
        charPanel.classList.remove('glitch-active');

        if ( value <= 20 ) {
            charPanel.classList.add('glitch-active');
            startHardGlitch();
        } else if ( value <= 50 ) {
            charPanel.style.filter = 'contrast(1.2) brightness(1.1) sepia(0.2)';
            startLightShake();
        }
        // value > 50: no effects, already reset above.
    }

    function startLightShake() {
        shakeInterval = setInterval( function() {
            if ( Math.random() > 0.95 ) {
                charPanel.style.transform = 'translate(' +
                    ( Math.random() * 2 - 1 ).toFixed(2) + 'px,' +
                    ( Math.random() * 2 - 1 ).toFixed(2) + 'px)';
                setTimeout( function() { charPanel.style.transform = ''; }, 50 );
            }
        }, 100 );
    }

    function startHardGlitch() {
        glitchInterval = setInterval( function() {
            if ( Math.random() > 0.8 ) {
                const x    = ( Math.random() * 6 - 3 ).toFixed(2);
                const y    = ( Math.random() * 6 - 3 ).toFixed(2);
                const skew = ( Math.random() * 2 - 1 ).toFixed(2);
                charPanel.style.transform = 'translate(' + x + 'px,' + y + 'px) skewX(' + skew + 'deg)';
                charPanel.style.filter    = 'hue-rotate(' + Math.round( Math.random() * 90 ) + 'deg) contrast(1.5)';

                setTimeout( function() {
                    charPanel.style.transform = '';
                    charPanel.style.filter    = '';
                }, 70 );
            }
        }, 150 );
    }

    applyGlitchEffects( syncValue );
});
</script>

<!-- Nawigacja boczna (Prawa) -->
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
                    <?= esc_html( $char_data['name'] ?? '' ); ?>
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
                    <?= (int) ( $char_data['gold'] ?? 0 ); ?>
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
                            <!-- FIX 5: Cast to int before echo to avoid XSS via malformed floats/strings -->
                            <span><?= (int) $sync_p; ?>%</span>
                        </div>
                        <div class="tw-progress-bg big-bar bordered glitch-border">
                            <!-- FIX 5 cont.: esc_attr() on both class and style value -->
                            <div class="tw-progress-fill <?= esc_attr( $sync_class ); ?>"
                                 style="width:<?= (int) $sync_p; ?>%;"></div>
                        </div>
                    </div>

                    <div class="tw-survival-bars">
                        <div class="tw-stat-bar-container small">
                            <div class="tw-stat-label small-label">
                                <span>SATIETY</span>
                                <!-- FIX 6: Cast survival stats to int everywhere -->
                                <span><?= (int) $c_satiety; ?>%</span>
                            </div>
                            <div class="tw-progress-bg small-bar">
                                <div class="tw-progress-fill satiety-orange"
                                     style="width:<?= (int) $c_satiety; ?>%;"></div>
                            </div>
                        </div>

                        <div class="tw-stat-bar-container small">
                            <div class="tw-stat-label small-label">
                                <span>HYDRATION</span>
                                <span><?= (int) $c_hydration; ?>%</span>
                            </div>
                            <div class="tw-progress-bg small-bar">
                                <div class="tw-progress-fill hydration-cyan"
                                     style="width:<?= (int) $c_hydration; ?>%;"></div>
                            </div>
                        </div>

                        <div class="tw-stat-bar-container small">
                            <div class="tw-stat-label small-label">
                                <span>REST</span>
                                <span><?= (int) $c_rest; ?>%</span>
                            </div>
                            <div class="tw-progress-bg small-bar">
                                <div class="tw-progress-fill rest-purple"
                                     style="width:<?= (int) $c_rest; ?>%;"></div>
                            </div>
                        </div>
                    </div>

                </div><!-- /.tw-bars-block -->

                <div class="tw-accordion-group">
                    <details>
                        <summary>Attributes</summary>
                        <div class="tw-attr-grid">
                            <div class="tw-attr-box">
                                <span class="tw-at-l">BODY</span>
                                <span class="tw-at-v"><?= (int) ( $char_data['body']   ?? 0 ); ?></span>
                            </div>
                            <div class="tw-attr-box">
                                <span class="tw-at-l">MIND</span>
                                <span class="tw-at-v"><?= (int) ( $char_data['mind']   ?? 0 ); ?></span>
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
                        <summary>Skills &amp; Abilities</summary>
                        <div class="tw-skills-list">
                            <?php foreach ( $skills_and_abilities as $r ) :
                                $d = $r['info'] ?? null;
                                if ( ! $d ) continue;
                            ?>
                                <!-- FIX 7: type="button" prevents accidental form submission -->
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
                            <?= nl2br( esc_html( $char_data['bio'] ?? 'No data found in neural link.' ) ); ?>
                        </div>
                    </details>
                </div>

            </div><!-- /#status -->

            <!-- INVENTORY -->
            <div class="tw-tab-content" id="inventory">
                <div class="equipment-container">

                    <!-- Essences above paperdoll -->
                    <div style="margin-bottom:15px;padding:10px;background:rgba(0,0,0,0.5);border:1px solid #adff00;border-radius:8px;">
                        <div style="font-size:0.75rem;color:#adff00;margin-bottom:5px;font-weight:bold;letter-spacing:1px;">ESSENCES</div>
                        <?php echo do_shortcode('[tw_essences]'); ?>
                    </div>

                    <div class="paperdoll-wrapper">
                        <div class="corner-stat stat-left">
                            <span class="stat-label">LOAD (KG)</span>
                            <span class="stat-value"><?= esc_html( $total_mass ); ?> / <?= esc_html( $mass_limit ); ?></span>
                        </div>

                        <div class="corner-stat stat-right">
                            <span class="stat-label">COMBAT</span>
                            <span class="stat-value" id="total-power-value">
                                <?= isset( $total_power ) ? (int) $total_power : 0; ?>
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
                    </div><!-- /.paperdoll-wrapper -->

                    <div id="tw-inventory-app" style="margin-top:20px;">
                        <h4 class="tw-inv-title" style="font-size:0.8rem;border-bottom:1px solid #adff00;padding-bottom:5px;">
                            CARRIED ITEMS
                        </h4>
                        <div id="tw-inventory-list" class="tw-item-list" style="min-height:50px;">
                            <?php foreach ( $inventory as $r ) :
                                $it = $r['info'] ?? null;
                                if ( $it && empty( $r['is_equipped'] ) ) :
                            ?>
                                <div class="tw-item-card"
                                     draggable="true"
                                     data-inventory-id="<?= esc_attr( $r['id'] ); ?>"
                                     data-item-slot="<?= esc_attr( $it['slot'] ?? '' ); ?>"
                                     style="background:rgba(255,255,255,0.05);margin-bottom:2px;padding:5px;cursor:grab;">
                                    <span class="tw-item-name" style="font-size:0.85rem;">
                                        <?= esc_html( $it['name'] ); ?>
                                        <small style="opacity:0.6;"> x<?= (int) $r['quantity']; ?></small>
                                        <?php if ( isset( $it['mass'] ) ) : ?>
                                            <small style="float:right;color:#adff00;"><?= esc_html( $it['mass'] ); ?> kg</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php
                                endif;
                            endforeach; ?>
                        </div>
                    </div>

                </div><!-- /.equipment-container -->
            </div><!-- /#inventory -->

            <!-- QUESTS -->
            <div class="tw-tab-content" id="player_quests">
                <div class="tw-gold-hud">
                    <div class="tw-gold-label">MISSIONS</div>
                </div>
                <?php echo do_shortcode('[active_scenarios]'); ?>
            </div>

            <!-- LOGS -->
            <div class="tw-tab-content" id="logs">
                <div class="tw-logs-list">
                    <?php if ( empty( $logs_data ) ) : ?>
                        <p class="tw-bio-text">Logs empty.</p>
                    <?php endif; ?>

                    <?php foreach ( $logs_data as $log ) :
                        // FIX 8: Guard against null/invalid created_at before passing to date()
                        $log_date = '';
                        if ( ! empty( $log['created_at'] ) ) {
                            $ts = is_numeric( $log['created_at'] )
                                ? (int) $log['created_at']
                                : strtotime( $log['created_at'] );
                            $log_date = ( $ts !== false && $ts > 0 )
                                ? date( 'd.m.Y H:i', $ts )
                                : '';
                        }
                    ?>
                        <div class="tw-log-entry">
                            <?php if ( $log_date ) : ?>
                                <small class="tw-log-date"><?= esc_html( $log_date ); ?></small>
                            <?php endif; ?>
                            <p class="tw-log-text">
                                <?= nl2br( esc_html( $log['log'] ?? '' ) ); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- NOTES -->
            <div class="tw-tab-content" id="player_notes">
                <div class="tw-notes-tab-container">
                    <textarea class="tw-notes-area" id="twNotesField" placeholder="Enter notes..."><?= esc_textarea( $char_data['notes'] ?? '' ); ?></textarea>
                    <button class="tw-save-notes-btn" id="twSaveNotes" type="button" data-char-id="<?= (int) $char_id; ?>">SYNC DATA</button>
                </div>
            </div>

            <!-- ECHO -->
            <div class="tw-tab-content" id="echo">
                <?php echo do_shortcode('[character_echo]'); ?>
            </div>

            <!-- WEAVERS -->
            <div class="tw-tab-content" id="weavers">
                <?php echo do_shortcode('[tw_weaver_list]'); ?>
            </div>

            <!-- LOOM -->
            <div class="tw-tab-content" id="loom">
                <?php echo do_shortcode('[tw_loom_of_fate]'); ?>
            </div>

        </div><!-- /.tw-panel-scroll-area -->
    </div><!-- /.tw-character-card -->
</div><!-- /#charPanel -->
