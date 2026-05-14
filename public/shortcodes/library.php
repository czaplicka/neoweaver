<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function cyber_deck_builder_shortcode() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return 'Log in to manage cards.';
    }

    $character_id = get_cyber_character_id_by_wp_id( $user_id );
    if ( empty( $character_id ) ) {
        return 'No character found for this account.';
    }

    $safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $character_id );

    // Pobieramy karty postaci z join do cyber_cards (name, img_url, level)
    $all_cards = tw_supabase_get(
        'cyber_character_cards',
        [
            'character_id' => 'eq.' . $safe_id,
            'select'       => 'instance_id,location,level,cyber_cards(name,img_url)',
        ]
    );

    ob_start();
    ?>
    <div class="deck-builder-container" style="font-family: 'Chakra Petch', sans-serif; color: #adff00;">
        <div id="deck-warning" style="color: #ff3333; margin-bottom: 10px; font-size: 12px; height: 20px;"></div>

        <div class="deck-section">
            <h3>ACTIVE DECK (20 - 50)</h3>
            <div id="active-deck" class="card-slot-container"
                 ondrop="drop(event)" ondragover="allowDrop(event)"
                 style="min-height: 150px; border: 1px dashed #adff00; display: flex; flex-wrap: wrap; gap: 10px; padding: 10px;">
                <?php foreach ( $all_cards as $card ) :
                    $loc = $card['location'] ?? '';
                    if ( in_array( $loc, [ 'pile', 'hand', 'discard' ], true ) ) :
                        $iid     = esc_attr( $card['instance_id'] ?? '' );
                        $img_url = esc_url( $card['cyber_cards']['img_url'] ?? '' );
                        $name    = esc_html( $card['cyber_cards']['name']    ?? '' );
                        $level   = esc_html( $card['level'] ?? '' );
                    ?>
                        <div class="cyber-card" draggable="true" ondragstart="drag(event)"
                             id="card-<?php echo $iid; ?>"
                             data-instance-id="<?php echo $iid; ?>"
                             style="width: 80px; text-align: center; border: 1px solid #adff00; padding: 5px; cursor: move;">
                            <img src="<?php echo $img_url; ?>" style="width: 100%; height: auto;"
                                 alt="<?php echo $name; ?>" loading="lazy">
                            <div class="card-info" style="font-size: 10px;">
                                <div class="card-name"><?php echo $name; ?></div>
                                <div class="card-lvl">LVL <?php echo $level; ?></div>
                            </div>
                        </div>
                    <?php endif;
                endforeach; ?>
            </div>
        </div>

        <div class="deck-section" style="margin-top: 20px;">
            <h3>LIBRARY (REPOSITORY)</h3>
            <div id="library-deck" class="card-slot-container"
                 ondrop="drop(event)" ondragover="allowDrop(event)"
                 style="min-height: 150px; border: 1px dashed #444; display: flex; flex-wrap: wrap; gap: 10px; padding: 10px;">
                <?php foreach ( $all_cards as $card ) :
                    if ( ( $card['location'] ?? '' ) === 'library' ) :
                        $iid     = esc_attr( $card['instance_id'] ?? '' );
                        $img_url = esc_url( $card['cyber_cards']['img_url'] ?? '' );
                        $name    = esc_html( $card['cyber_cards']['name']    ?? '' );
                        $level   = esc_html( $card['level'] ?? '' );
                    ?>
                        <div class="cyber-card" draggable="true" ondragstart="drag(event)"
                             id="card-<?php echo $iid; ?>"
                             data-instance-id="<?php echo $iid; ?>"
                             style="width: 80px; text-align: center; border: 1px solid #444; padding: 5px; cursor: move;">
                            <img src="<?php echo $img_url; ?>" style="width: 100%; height: auto; filter: grayscale(1);"
                                 alt="<?php echo $name; ?>" loading="lazy">
                            <div class="card-info" style="font-size: 10px; color: #666;">
                                <div class="card-name"><?php echo $name; ?></div>
                                <div class="card-lvl">LVL <?php echo $level; ?></div>
                            </div>
                        </div>
                    <?php endif;
                endforeach; ?>
            </div>
        </div>

        <button id="save-deck-btn" onclick="saveDeckState()"
                style="margin-top: 20px; background: #adff00; color: #000; border: none; padding: 10px 20px; cursor: pointer; font-weight: bold;">
            SYNC WITH TERMINAL
        </button>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'cyber_deck_builder', 'cyber_deck_builder_shortcode' );
