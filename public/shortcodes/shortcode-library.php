<?php
function cyber_deck_builder_shortcode() {
    $user_id = get_current_user_id();
    if (!$user_id) return "Zaloguj się, aby zarządzać taliami.";

    // Pobranie Twojego ID postaci (zakładam że masz tę funkcję)
    $character_id = get_cyber_character_id_by_wp_id($user_id);
    
    // Pobieramy karty - musisz upewnić się, że ta funkcja zwraca tablicę obiektów
    $all_cards = fetch_cyber_cards_from_supabase($character_id); 

    ob_start();
    ?>
    <div class="deck-builder-container" style="font-family: 'Chakra Petch', sans-serif; color: #adff00;">
        <div id="deck-warning" style="color: #ff3333; margin-bottom: 10px; font-size: 12px; height: 20px;"></div>

        <div class="deck-section">
            <h3>ACTIVE DECK (20 - 50)</h3>
            <div id="active-deck" class="card-slot-container" ondrop="drop(event)" ondragover="allowDrop(event)" style="min-height: 150px; border: 1px dashed #adff00; display: flex; flex-wrap: wrap; gap: 10px; padding: 10px;">
                <?php foreach ($all_cards as $card): 
                    if ($card->location == 'pile' || $card->location == 'hand' || $card->location == 'discard'): ?>
                        <div class="cyber-card" draggable="true" ondragstart="drag(event)" id="card-<?php echo $card->instance_id; ?>" data-instance-id="<?php echo $card->instance_id; ?>" style="width: 80px; text-align: center; border: 1px solid #adff00; padding: 5px; cursor: move;">
                            <img src="<?php echo $card->img_url; ?>" style="width: 100%; height: auto;">
                            <div class="card-info" style="font-size: 10px;">
                                <div class="card-name"><?php echo $card->name; ?></div>
                                <div class="card-lvl">LVL <?php echo $card->level; ?></div>
                            </div>
                        </div>
                    <?php endif; 
                endforeach; ?>
            </div>
        </div>

        <div class="deck-section" style="margin-top: 20px;">
            <h3>LIBRARY (REPOSITORY)</h3>
            <div id="library-deck" class="card-slot-container" ondrop="drop(event)" ondragover="allowDrop(event)" style="min-height: 150px; border: 1px dashed #444; display: flex; flex-wrap: wrap; gap: 10px; padding: 10px;">
                <?php foreach ($all_cards as $card): 
                    if ($card->location == 'library'): ?>
                        <div class="cyber-card" draggable="true" ondragstart="drag(event)" id="card-<?php echo $card->instance_id; ?>" data-instance-id="<?php echo $card->instance_id; ?>" style="width: 80px; text-align: center; border: 1px solid #444; padding: 5px; cursor: move;">
                            <img src="<?php echo $card->img_url; ?>" style="width: 100%; height: auto; filter: grayscale(1);">
                            <div class="card-info" style="font-size: 10px; color: #666;">
                                <div class="card-name"><?php echo $card->name; ?></div>
                                <div class="card-lvl">LVL <?php echo $card->level; ?></div>
                            </div>
                        </div>
                    <?php endif; 
                endforeach; ?>
            </div>
        </div>
        
        <button id="save-deck-btn" onclick="saveDeckState()" style="margin-top: 20px; background: #adff00; color: #000; border: none; padding: 10px 20px; cursor: pointer; font-weight: bold;">
            SYNC WITH TERMINAL
        </button>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('cyber_deck_builder', 'cyber_deck_builder_shortcode');
