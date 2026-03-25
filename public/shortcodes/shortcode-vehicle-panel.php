<?php
// Enqueue CSS/JS tylko tam, gdzie shortcode
function neoweaver_enqueue_vehicle_assets() {
	// Ładuj tylko na front-endzie
	if ( is_admin() ) {
		return;
	}

	// Opcjonalnie: tylko jeśli jest shortcode na stronie
	if ( is_singular() ) {
		global $post;
		if ( empty( $post ) || false === strpos( $post->post_content, '[neoweave_vehicle_panel' ) ) {
			return;
		}
	}

	// Dostosuj w zależności od miejsca pliku shortcode (tu: /public/shortcodes/)
	$plugin_url = plugin_dir_url( dirname( __FILE__ ) );

	wp_enqueue_style(
		'vehicle-panel',
		$plugin_url . 'public/assets/css/vehicle-panel.css',
		[],
		'1.0.0'
	);
    	wp_enqueue_style(
		'vehicle-panel',
		$plugin_url . 'public/assets/js/vehicle-panel.js',
		[],
		'1.0.0'
	);
    
function neoweave_vehicle_panel_shortcode() {
    // Pobranie danych o aktywnym pojeździe Agenta z sesji/Supa
    // Zakładamy, że mamy $character_id i $campaign_id
    
    ob_start(); ?>
    <div id="neoweave-vehicle-interface" class="terminal-border">
        <h3 class="neoweave-neon-text">VEHICLE DIAGNOSTICS</h3>
        
        <div class="vehicle-stats">
            <div class="stat-row">
                <span>DURABILITY:</span>
                <div class="progress-bar"><div id="v-hp-bar" style="width: 80%; background: #adff00;"></div></div>
                <span id="v-hp-text">80/100</span>
            </div>
            <div class="stat-row">
                <span>FUEL/ENERGY:</span>
                <div class="progress-bar"><div id="v-fuel-bar" style="width: 40%; background: #00e5ff;"></div></div>
                <span id="v-fuel-text">40/100</span>
            </div>
        </div>

        <div class="vehicle-blueprint">
            <div class="slot-grid">
                <div class="v-slot core" data-slot="core" ondrop="drop(event)" ondragover="allowDrop(event)">
                    <label>CORE</label>
                    <div class="slot-occupant" id="active-core">V8 Steam Engine</div>
                </div>
                <div class="v-slot lateral" data-slot="lateral_l" ondrop="drop(event)" ondragover="allowDrop(event)">
                    <label>LATERAL L</label>
                </div>
                <div class="v-slot lateral" data-slot="lateral_r" ondrop="drop(event)" ondragover="allowDrop(event)">
                    <label>LATERAL R</label>
                </div>
                <div class="v-slot utility" data-slot="utility" ondrop="drop(event)" ondragover="allowDrop(event)">
                    <label>CARGO/UTIL</label>
                    <div class="slot-occupant" id="active-utility">Trunk (Cap: 50)</div>
                </div>
            </div>
        </div>

        <hr class="terminal-hr">

        <h4 class="neoweave-neon-text">GARAGE (STORAGE)</h4>
        <div id="garage-container" ondrop="drop(event)" ondragover="allowDrop(event)">
            <div class="module-item" draggable="true" ondragstart="drag(event)" id="mod-1" data-type="lateral">
                Side Gatling [Mass: 5]
            </div>
            <div class="module-item" draggable="true" ondragstart="drag(event)" id="mod-2" data-type="utility">
                Smoke Launcher [Mass: 2]
            </div>
        </div>

        <div class="cargo-manifest">
            <h4>CARGO MANIFEST: <span id="cargo-weight">12</span> / 50</h4>
            <ul id="cargo-list">
                <li>* Scrap Metal (Mass: 10)</li>
                <li>* Ration Pack (Mass: 2)</li>
            </ul>
        </div>
    </div>

    <script>
        function allowDrop(ev) { ev.preventDefault(); }
        function drag(ev) { ev.dataTransfer.setData("text", ev.target.id); }

        function drop(ev) {
            ev.preventDefault();
            var data = ev.dataTransfer.getData("text");
            var draggedElement = document.getElementById(data);
            var dropTarget = ev.target.closest('.v-slot, #garage-container');

            if (!dropTarget) return;

            // Logika walidacji typu slotu
            const itemType = draggedElement.getAttribute('data-type');
            const slotType = dropTarget.getAttribute('data-slot');

            if (slotType && itemType !== slotType && !slotType.includes(itemType)) {
                console.error("Incompatible Slot Type!");
                return;
            }

            dropTarget.appendChild(draggedElement);
            updateVehicleInSupabase(data, slotType); // Funkcja do update'u bazy
        }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('neoweave_vehicle_panel', 'neoweave_vehicle_panel_shortcode');
