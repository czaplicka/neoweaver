<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shortcode [cyber_deck_builder]
 * Wyświetla deck buildera dla wybranej postaci gracza.
 * Selektor postaci wzorowany na [achievements].
 */
function cyber_deck_builder_shortcode( $atts ) {

    // Ładujemy ten sam CSS co achievements — zawiera style selektora postaci
    add_action( 'wp_footer', static function () {
        wp_enqueue_style(
            'neoweaver-achievements',
            NW_PLUGIN_URL . 'assets/css/public/achievements.css',
            [],
            NW_VERSION
        );
    }, 5 );

    $a = shortcode_atts(
        [
            'user_id' => get_current_user_id(),
            'char_id' => null,
        ],
        $atts
    );

    $current_user_id = (int) $a['user_id'];

    if ( ! $current_user_id ) {
        return '<p>Log in to manage your deck.</p>';
    }

    // Wybrana postać: z GET lub z atrybutu shortcode
    $selected_char_id = null;
    if ( ! empty( $_GET['char_id'] ) ) {
        $selected_char_id = sanitize_text_field( wp_unslash( $_GET['char_id'] ) );
    } elseif ( ! empty( $a['char_id'] ) ) {
        $selected_char_id = (string) $a['char_id'];
    }

    // Lista postaci usera
    $characters = [];
    if ( function_exists( 'tw_get_user_characters' ) ) {
        $characters = tw_get_user_characters( $current_user_id );
    }

    if ( empty( $characters ) ) {
        return '<p>No characters found. Create one first.</p>';
    }

    // Bezpieczeństwo: nie pozwól na char_id spoza listy usera
    $allowed_char_ids = array_map(
        static function ( $char ) {
            return (string) $char->id;
        },
        $characters
    );

    if ( ! empty( $selected_char_id ) && ! in_array( $selected_char_id, $allowed_char_ids, true ) ) {
        $selected_char_id = null;
    }

    // Domyślnie pierwsza postać
    if ( empty( $selected_char_id ) ) {
        $selected_char_id = $allowed_char_ids[0];
    }

    $safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $selected_char_id );

    // Pobieramy karty wybranej postaci
    $all_cards = [];
    if ( function_exists( 'tw_supabase_get' ) ) {
        $all_cards = tw_supabase_get(
            'cyber_character_deck',
            [
                'character_id' => 'eq.' . $safe_id,
                'select'       => '*,cyber_deck(id,name,img_url,deck_category,type,rarity,level,description,effect)',
            ]
        );
    }

    if ( ! is_array( $all_cards ) ) {
        $all_cards = [];
    }

    ob_start();
    ?>
    <div class="deck-builder-wrap" style="font-family: 'Chakra Petch', sans-serif; color: #adff00;">

        <?php if ( count( $characters ) > 1 ) : ?>
        <form method="get" class="ach-filter-form">
            <?php foreach ( $_GET as $key => $value ) :
                if ( $key === 'char_id' ) {
                    continue;
                }
                if ( is_scalar( $value ) ) : ?>
                    <input type="hidden"
                           name="<?php echo esc_attr( $key ); ?>"
                           value="<?php echo esc_attr( wp_unslash( $value ) ); ?>">
                <?php endif;
            endforeach; ?>

            <label for="deck-char-select" class="ach-filter-label">Character</label>
            <select id="deck-char-select" name="char_id" onchange="this.form.submit()">
                <?php foreach ( $characters as $char ) :
                    $sel   = selected( $selected_char_id, (string) $char->id, false );
                    $label = $char->name;
                    if ( isset( $char->lvl ) ) {
                        $label .= ' (Lv. ' . (int) $char->lvl . ')';
                    }
                    ?>
                    <option value="<?php echo esc_attr( $char->id ); ?>" <?php echo $sel; ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>

        <div class="deck-builder-container">
            <div id="deck-warning" style="color:#ff3333; margin-bottom:10px; font-size:12px; min-height:20px;"></div>

            <div class="deck-section">
                <h3>ACTIVE DECK (20 – 50)</h3>
                <div id="active-deck" class="card-slot-container"
                     ondrop="drop(event)" ondragover="allowDrop(event)"
                     style="min-height:150px; border:1px dashed #adff00; display:flex; flex-wrap:wrap; gap:10px; padding:10px;">
                    <?php
                    $active_locations = [ 'pile', 'hand', 'discard' ];
                    foreach ( $all_cards as $card ) :
                        $loc = $card['location'] ?? '';
                        if ( ! in_array( $loc, $active_locations, true ) ) {
                            continue;
                        }
                        $iid     = esc_attr( $card['instance_id'] ?? $card['id'] ?? '' );
                        $cdata   = $card['cyber_deck'] ?? [];
                        $img_url = esc_url( $cdata['img_url'] ?? '' );
                        $name    = esc_html( $cdata['name']    ?? '' );
                        $level   = esc_html( $cdata['level']   ?? '' );
                        ?>
                        <div class="cyber-card"
                             draggable="true" ondragstart="drag(event)"
                             id="card-<?php echo $iid; ?>"
                             data-instance-id="<?php echo $iid; ?>"
                             style="width:80px; text-align:center; border:1px solid #adff00; padding:5px; cursor:move;">
                            <img src="<?php echo $img_url; ?>"
                                 alt="<?php echo $name; ?>"
                                 style="width:100%; height:auto;"
                                 loading="lazy">
                            <div class="card-info" style="font-size:10px;">
                                <div class="card-name"><?php echo $name; ?></div>
                                <div class="card-lvl">LVL <?php echo $level; ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="deck-section" style="margin-top:20px;">
                <h3>LIBRARY (REPOSITORY)</h3>
                <div id="library-deck" class="card-slot-container"
                     ondrop="drop(event)" ondragover="allowDrop(event)"
                     style="min-height:150px; border:1px dashed #444; display:flex; flex-wrap:wrap; gap:10px; padding:10px;">
                    <?php foreach ( $all_cards as $card ) :
                        if ( ( $card['location'] ?? '' ) !== 'library' ) {
                            continue;
                        }
                        $iid     = esc_attr( $card['instance_id'] ?? $card['id'] ?? '' );
                        $cdata   = $card['cyber_deck'] ?? [];
                        $img_url = esc_url( $cdata['img_url'] ?? '' );
                        $name    = esc_html( $cdata['name']    ?? '' );
                        $level   = esc_html( $cdata['level']   ?? '' );
                        ?>
                        <div class="cyber-card"
                             draggable="true" ondragstart="drag(event)"
                             id="card-<?php echo $iid; ?>"
                             data-instance-id="<?php echo $iid; ?>"
                             style="width:80px; text-align:center; border:1px solid #444; padding:5px; cursor:move;">
                            <img src="<?php echo $img_url; ?>"
                                 alt="<?php echo $name; ?>"
                                 style="width:100%; height:auto; filter:grayscale(1);"
                                 loading="lazy">
                            <div class="card-info" style="font-size:10px; color:#666;">
                                <div class="card-name"><?php echo $name; ?></div>
                                <div class="card-lvl">LVL <?php echo $level; ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button id="save-deck-btn" onclick="saveDeckState()"
                    style="margin-top:20px; background:#adff00; color:#000; border:none; padding:10px 20px; cursor:pointer; font-weight:bold;">
                SYNC WITH TERMINAL
            </button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'cyber_deck_builder', 'cyber_deck_builder_shortcode' );
