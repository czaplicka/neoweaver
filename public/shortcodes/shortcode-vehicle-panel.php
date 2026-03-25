<?php
/**
 * Shortcode: [neoweave_vehicle_panel vehicle_id="UUID" character_id="UUID"]
 */

// 1. Rejestracja skryptów i styli
function neoweaver_enqueue_vehicle_assets() {
    if ( is_admin() ) {
        return;
    }

    // Sprawdzamy czy shortcode jest obecny w treści postu
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'neoweave_vehicle_panel' ) ) {
        
        $plugin_url = plugin_dir_url( dirname( __FILE__, 1 ) );

        // CSS
        wp_enqueue_style(
            'neoweave-vehicle-css',
            $plugin_url . 'public/assets/css/vehicle-panel.css',
            [],
            '1.0.1'
        );

        // JS - poprawione z style na script
        wp_enqueue_script(
            'neoweave-vehicle-js',
            $plugin_url . 'public/assets/js/vehicle-panel.js',
            ['jquery'],
            '1.0.1',
            true
        );

        // Przekazanie ajaxurl do JS
        wp_localize_script('neoweave-vehicle-js', 'neoweave_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);
    }
}
add_action('wp_enqueue_scripts', 'neoweaver_enqueue_vehicle_assets');

// 2. Funkcja Shortcode
function neoweave_vehicle_panel_shortcode($atts) {
    // Pobranie atrybutów z shortcode'u
    $atts = shortcode_atts([
        'vehicle_id'   => '',
        'character_id' => ''
    ], $atts);

    // TODO: Tutaj powinna znaleźć się logika pobierania danych z Supabase (current_hp, fuel, itp.)
    // Na potrzeby interfejsu używamy placeholderów, które JS wypełni po załadowaniu
    
    ob_start(); ?>
    <div id="neoweave-vehicle-interface" 
         class="terminal-border" 
         data-vehicle-id="<?php echo esc_attr($atts['vehicle_id']); ?>"
         data-character-id="<?php echo esc_attr($atts['character_id']); ?>">
        
        <h3 class="neoweave-neon-text">VEHICLE DIAGNOSTICS</h3>
        
        <div class="vehicle-stats">
            <div class="stat-row">
                <span>DURABILITY:</span>
                <div class="progress-bar">
                    <div id="v-hp-bar" style="width: 80%; background: #adff00;"></div>
                </div>
                <span id="v-hp-text">80/100</span>
            </div>
            <div class="stat-row">
                <span>FUEL/ENERGY:</span>
                <div class="progress-bar">
                    <div id="v-fuel-bar" style="width: 40%; background: #00e5ff;"></div>
                </div>
                <span id="v-fuel-text">40/100</span>
            </div>
        </div>

        <div class="vehicle-blueprint">
            <div class="slot-grid">
                <div class="v-slot core" data-slot="slot_core" ondrop="drop(event)" ondragover="allowDrop(event)">
                    <label>CORE</label>
                    <div class="slot-occupant" id="active-core">V8 Steam Engine</div>
                </div>
                <div class="v-slot lateral" data-slot="slot_lateral_l" ondrop="drop(event)" ondragover="allowDrop(event)">
                    <label>LATERAL L</label>
                    <div class="slot-occupant" id="active-lateral-l">---</div>
                </div>
                <div class="v-slot lateral" data-slot="slot_lateral_r" ondrop="drop(event)" ondragover="allowDrop(event)">
                    <label>LATERAL R</label>
                    <div class="slot-occupant" id="active-lateral-r">---</div>
                </div>
                <div class="v-slot utility" data-slot="slot_utility" ondrop="drop(event)" ondragover="allowDrop(event)">
                    <label>CARGO/UTIL</label>
                    <div class="slot-occupant" id="active-utility">Trunk (Cap: 50)</div>
                </div>
            </div>
        </div>

        <hr class="terminal-hr">

        <h4 class="neoweave-neon-text">GARAGE (STORAGE)</h4>
        <div id="garage-container" data-slot="garage" ondrop="drop(event)" ondragover="allowDrop(event)">
            <div class="module-item" draggable="true" ondragstart="drag(event)" id="mod-gatling-001" data-type="lateral">
                Side Gatling [Mass: 5]
            </div>
            <div class="module-item" draggable="true" ondragstart="drag(event)" id="mod-smoke-002" data-type="utility">
                Smoke Launcher [Mass: 2]
            </div>
        </div>

        <div class="cargo-manifest">
            <h4>CARGO MANIFEST: <span id="cargo-weight">12</span> / <span id="cargo-max">50</span></h4>
            <ul id="cargo-list">
                <li>* Scrap Metal (Mass: 10)</li>
                <li>* Ration Pack (Mass: 2)</li>
            </ul>
        </div>
    </div>

    <script>
        function allowDrop(ev) { 
            ev.preventDefault(); 
        }

        function drag(ev) { 
            ev.dataTransfer.setData("text", ev.target.id); 
        }

        function drop(ev) {
            ev.preventDefault();
            const data = ev.dataTransfer.getData("text");
            const draggedElement = document.getElementById(data);
            const dropTarget = ev.target.closest('.v-slot, #garage-container');

            if (!dropTarget) return;

            const itemType = draggedElement.getAttribute('data-type');
            const slotName = dropTarget.getAttribute('data-slot'); // np. slot_core

            // Walidacja typów (uproszczona)
            if (slotName !== 'garage') {
                if (!slotName.includes(itemType)) {
                    console.error("Incompatible Slot!");
                    return;
                }
            }

            dropTarget.appendChild(draggedElement);
            
            // Wywołanie funkcji synchronizacji z Supabase (powinna być w vehicle-panel.js)
            if (typeof updateVehicleInSupabase === "function") {
                updateVehicleInSupabase(data, slotName);
            }
        }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('neoweave_vehicle_panel', 'neoweave_vehicle_panel_shortcode');
