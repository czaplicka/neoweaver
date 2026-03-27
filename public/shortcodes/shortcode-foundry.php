<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function cyber_foundry_shortcode() {
    $user_id = get_current_user_id();
    if (!$user_id) return '<div class="foundry-container">ERROR: UPLINK REQUIRED. PLEASE LOG IN.</div>';

    // Zakładamy, że masz te funkcje w supabase-helpers.php
    $character_id = get_cyber_character_id_by_wp_id($user_id);
    $library_cards = fetch_foundry_data($character_id);
    
    // Musisz pobrać aktualne kredyty gracza, aby zmienna $current_player_credits istniała
    $current_player_credits = get_cyber_player_credits($character_id); 

    ob_start();
    ?>
    <div class="foundry-container">
        <h2 class="foundry-title"> <span class="blink">_</span> NANITE FOUNDRY</h2>
        
        <div class="foundry-grid">
            <?php 
            // POPRAWKA: Brakowało otwarcia pętli PHP <?php
            if (!empty($library_cards)):
                foreach ($library_cards as $card): 
                    $needed_duplicates = $card->level * 2;
                    $needed_credits = $card->level * 100;
                    
                    $has_duplicates = $card->duplicate_count >= $needed_duplicates;
                    $has_credits = $current_player_credits >= $needed_credits; 
                    
                    $can_upgrade = $has_duplicates && $has_credits;
            ?>
                <div class="foundry-item <?php echo $can_upgrade ? 'ready' : ''; ?>">
                    <div class="card-preview">
                        <span class="lvl-badge">v.<?php echo esc_html($card->level); ?></span>
                        <div class="card-name"><?php echo esc_html($card->name); ?></div>
                    </div>
                    
                    <div class="upgrade-info">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo min(100, ($card->duplicate_count / $needed_duplicates) * 100); ?>%"></div>
                        </div>
                        <span class="count-text">
                            DATA NODES: <?php echo (int)$card->duplicate_count; ?> / <?php echo (int)$needed_duplicates; ?>
                        </span>
                        
                        <div class="credit-cost <?php echo $has_credits ? '' : 'insufficient'; ?>">
                            COST: <?php echo (int)$needed_credits; ?> CC
                        </div>
                    </div>

                    <button class="upgrade-btn" 
                            onclick="upgradeCard('<?php echo esc_js($card->instance_id); ?>')"
                            <?php echo !$can_upgrade ? 'disabled' : ''; ?>>
                        <?php 
                            if (!$has_duplicates) echo 'NEED MORE DATA';
                            elseif (!$has_credits) echo 'INSUFFICIENT CC';
                            else echo 'START FUSION';
                        ?>
                    </button>
                </div>
            <?php 
                endforeach; 
            else:
                echo '<p class="buffer-empty">NO DATA NODES DETECTED IN ARCHIVE.</p>';
            endif;
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
// Shortcode jest już zarejestrowany w głównym pliku, ale warto go mieć też tutaj jako fallback
if(!shortcode_exists('cyber_foundry')) {
    add_shortcode('cyber_foundry', 'cyber_foundry_shortcode');
}
