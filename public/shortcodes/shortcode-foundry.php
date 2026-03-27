<?php
function cyber_foundry_shortcode() {
    $user_id = get_current_user_id();
    $character_id = get_cyber_character_id_by_wp_id($user_id);
    
    // Pobieramy unikalne karty gracza z ich ilością duplikatów
    $library_cards = fetch_foundry_data($character_id);

    ob_start();
    ?>
    <div class="foundry-container">
        <h2 class="foundry-title"> <span class="blink">_</span> NANITE FOUNDRY</h2>
        
        <div class="foundry-grid">
            foreach ($library_cards as $card): 
        $needed_duplicates = $card->level * 2;
        $needed_credits = $card->level * 100; // Koszt kredytów
        
        $has_duplicates = $card->duplicate_count >= $needed_duplicates;
        // Zakładamy, że $card->player_credits jest przekazane z bazy lub pobrane wcześniej
        $has_credits = $current_player_credits >= $needed_credits; 
        
        $can_upgrade = $has_duplicates && $has_credits;
    ?>
        <div class="foundry-item <?php echo $can_upgrade ? 'ready' : ''; ?>">
            <div class="card-preview">
                <span class="lvl-badge">v.<?php echo $card->level; ?></span>
                <div class="card-name"><?php echo $card->name; ?></div>
            </div>
            
            <div class="upgrade-info">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($card->duplicate_count / $needed_duplicates) * 100); ?>%"></div>
                </div>
                <span class="count-text">
                    DATA NODES: <?php echo $card->duplicate_count; ?> / <?php echo $needed_duplicates; ?>
                </span>
                
                <div class="credit-cost <?php echo $has_credits ? '' : 'insufficient'; ?>">
                    COST: <?php echo $needed_credits; ?> CC
                </div>
            </div>

            <button class="upgrade-btn" 
                    onclick="upgradeCard('<?php echo $card->instance_id; ?>')"
                    <?php echo !$can_upgrade ? 'disabled' : ''; ?>>
                <?php 
                    if (!$has_duplicates) echo 'NEED MORE DATA';
                    elseif (!$has_credits) echo 'INSUFFICIENT CC';
                    else echo 'START FUSION';
                ?>
            </button>
        </div>
    <?php endforeach; ?>
        </div>
    </div>

    <style>
        .foundry-container { background: #000; border: 1px solid #adff00; padding: 20px; font-family: 'Chakra Petch'; color: #adff00; }
        .foundry-title { border-bottom: 2px solid #adff00; padding-bottom: 10px; margin-bottom: 20px; }
        .foundry-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
        
        .foundry-item { background: rgba(173, 255, 0, 0.05); border: 1px solid rgba(173, 255, 0, 0.3); padding: 15px; transition: 0.3s; }
        .foundry-item.ready { border-color: #adff00; box-shadow: 0 0 10px rgba(173, 255, 0, 0.2); }
        
        .lvl-badge { background: #adff00; color: #000; padding: 2px 5px; font-weight: bold; font-size: 12px; }
        .card-name { margin-top: 10px; font-weight: bold; text-transform: uppercase; }
        
        .progress-bar { height: 6px; background: #111; margin-top: 15px; border: 1px solid rgba(173, 255, 0, 0.2); }
        .progress-fill { height: 100%; background: #adff00; box-shadow: 0 0 10px #adff00; }
        .count-text { font-size: 10px; opacity: 0.7; margin-top: 5px; display: block; }
        
        .upgrade-btn { width: 100%; margin-top: 15px; background: transparent; border: 1px solid #adff00; color: #adff00; padding: 8px; cursor: pointer; }
        .upgrade-btn:not(:disabled):hover { background: #adff00; color: #000; }
        .upgrade-btn:disabled { opacity: 0.3; cursor: not-allowed; }
        
        .blink { animation: blinker 1s linear infinite; }
        @keyframes blinker { 50% { opacity: 0; } }
      .credit-cost {
    font-size: 11px;
    margin-top: 8px;
    color: #adff00;
    letter-spacing: 1px;
}
.credit-cost.insufficient {
    color: #ff3333;
    text-shadow: 0 0 5px #ff3333;
}
.upgrade-info {
    border-top: 1px solid rgba(173, 255, 0, 0.1);
    margin-top: 10px;
    padding-top: 5px;
}
    </style>

    <script>
    function upgradeCard(instanceId) {
        if(!confirm("Start Nano-Fusion process?")) return;
        
        const formData = new FormData();
        formData.append('action', 'foundry_upgrade');
        formData.append('instance_id', instanceId);
        formData.append('nonce', '<?php echo wp_create_nonce("foundry_nonce"); ?>');

        fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                alert("STABILIZED: " + data.data.message);
                location.reload();
            } else {
                alert("STABILIZATION FAILED: " + data.data);
            }
        });
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('cyber_foundry', 'cyber_foundry_shortcode');
