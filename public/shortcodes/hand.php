<script src="https://unpkg.com/swiper/swiper-bundle.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css" />
<?php
function cyber_buffer_hand_shortcode() {
    $user_id = get_current_user_id();
    $character_id = get_cyber_character_id_by_wp_id($user_id); // Twoja funkcja mapująca ID

    if (!$character_id) return "<div class='terminal-error'>UPLINK LOST: Character not identified.</div>";

    // Pobieramy dane kart o statusie 'hand' (z JOINem do tabeli cyber_buffer)
    $hand_cards = fetch_cyber_hand_with_details($character_id);

    ob_start();
    ?>
    <link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css" />
    <script src="https://unpkg.com/swiper/swiper-bundle.min.js"></script>

    <div class="buffer-wrapper">
        <div class="swiper-container buffer-slider">
            <div class="swiper-wrapper" id="buffer-hand-slots">
                <?php foreach ($hand_cards as $card): ?>
                    <div class="swiper-slide">
                        <div class="cyber-card-css <?php echo strtolower($card->category); ?>" 
                             onclick="zoomCard(this)"
                             data-instance-id="<?php echo $card->instance_id; ?>">
                            
                            <div class="card-glitch-overlay"></div>
                            
                            <div class="card-header">
                                <span class="card-cat"><?php echo strtoupper($card->category); ?></span>
                                <span class="card-lvl">v.<?php echo $card->level; ?></span>
                            </div>

                            <div class="card-content">
                                <h3 class="card-title"><?php echo $card->name; ?></h3>
                                <p class="card-desc"><?php echo $card->description; ?></p>
                            </div>
<div class="buffer-hud">
    <div class="hud-stat pile-count" title="Talia (Pile)">
        <span class="hud-label">PILE</span>
        <span id="count-pile" class="hud-value">--</span>
    </div>
    
    <div class="buffer-slider-container"> ... </div>

    <div class="hud-stat discard-count" title="Kosz (Discard)">
        <span class="hud-label">DISCARD</span>
        <span id="count-discard" class="hud-value">--</span>
    </div>
</div>
                            <div class="card-footer">
                                <button class="inject-btn" onclick="useBufferCard('<?php echo $card->instance_id; ?>', '<?php echo esc_js($card->name); ?>', event)">
                                    INJECT PROTOCOL
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="swiper-pagination"></div>
        </div>
    </div>

    <div id="card-zoom-overlay" onclick="closeZoom()">
        <div id="zoom-content"></div>
        <div class="zoom-hint">CLICK ANYWHERE TO CLOSE</div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('cyber_buffer_hand', 'cyber_buffer_hand_shortcode');
