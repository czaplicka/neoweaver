<?php
add_shortcode('SIGNAL_QUALITY', function() use ($signal_strength, $world_tech_level) {
    ob_start(); ?>
    <div class="neoweave-signal-monitor">
        <div class="signal-label">SIGNAL INTEGRITY: <?php echo $world_tech_level; ?>/5</div>
        <div class="signal-bar-container">
            <div class="signal-bar-fill" style="width: <?php echo $signal_strength; ?>%;"></div>
        </div>
        <div class="signal-status"><?php 
            if($world_tech_level <= 2) echo "STATUS: UNSTABLE / ANALOG INTERFERENCE DETECTED";
            else echo "STATUS: ENCRYPTED LINK ESTABLISHED";
        ?></div>
    </div>
    <?php
    return ob_get_clean();
});
