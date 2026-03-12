function tw_render_active_game_map() {
    $wp_user_id = get_current_user_id();
    if (!$wp_user_id) return '<div style="padding:20px; color:red;">[ACCESS DENIED]: Link not established.</div>';

    ob_start();
    ?>
    <!-- KONTENER MAPY -->
    <div id="tw-map-container" style="width: 100%; height: 100%; min-height: 400px; background: rgba(5, 5, 12, 0.4); backdrop-filter: blur(8px); position: relative; overflow: hidden;">
        
        <!-- SIATKA TŁA -->
        <div style="position: absolute; inset: 0; background-image: 
            linear-gradient(rgba(173, 255, 0, 0.07) 1px, transparent 1px),
            linear-gradient(90deg, rgba(173, 255, 0, 0.07) 1px, transparent 1px);
            background-size: 60px 60px; opacity: 1; pointer-events: none;">
        </div>
        
        <svg id="cyber-map" style="width: 100%; height: 100%; position: relative; z-index: 10; cursor: grab;"></svg>
        
        <!-- LEGENDA KOLORÓW -->
        <div id="map-legend-container" class="tw-map-legend"></div>

        <!-- KARTA INFORMACYJNA -->
        <div id="location-card" style="position: absolute; top: 15px; right: 15px; width: 240px; background: rgba(0,0,0,0.9); border: 1px solid #adff00; padding: 12px; display: none; color: #fff; font-family: 'Chakra Petch', monospace; z-index: 20; box-shadow: 0 4px 15px rgba(0,0,0,0.8); backdrop-filter: blur(10px);">
            <h4 id="loc-title" style="color: #adff00; margin: 0 0 8px 0; border-bottom: 1px solid #333; padding-bottom: 5px; font-size: 1rem; text-transform: uppercase;"></h4>
            <div id="loc-kingdom" style="font-size: 0.7rem; color: #888; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: bold;"></div>
            <p id="loc-desc" style="font-size: 0.8rem; line-height: 1.4; color: #ccc; margin: 0;"></p>
            <div id="loc-status" style="font-size: 0.7rem; color: #adff00; font-weight: bold; margin-top: 10px; border-top: 1px solid #222; padding-top: 5px;"></div>
        </div>
    </div>

    <script src="https://d3js.org/d3.v7.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (!window.twSupabase) return;

        let currentSessionLocationId = null;
        let allNodes = [];
        let simulation = null;
        let linkElements, nodeElements, hullGroup;
        
        // Mapa kolorów - dynamicznie przypisywana do ID
        const kingdomColorMap = new Map();
        const baseColors = [
            "#adff00", "#00f3ff", "#ff0055", "#ffd700", "#b800ff", "#ff6600"
        ];

        function getKingdomColor(id) {
            if (!id) return "#444";
            if (!kingdomColorMap.has(id)) {
                const colorIndex = (kingdomColorMap.size) % baseColors.length;
                kingdomColorMap.set(id, baseColors[colorIndex]);
            }
            return kingdomColorMap.get(id);
        }

        async function initActiveMap() {
            const wpUserId = <?php echo $wp_user_id; ?>;
            const container = document.getElementById('tw-map-container');
            let width = container.clientWidth || 800;
            let height = container.clientHeight || 600;

            // 1. Pobierz ID świata i obecną lokację
            const { data: mapData, error: sError } = await window.twSupabase
                .from('v_cyber_map_view')
                .select('current_location_id, world_id')
                .eq('wp_user_id', wpUserId)
                .maybeSingle();

            if (sError || !mapData) return;

            currentSessionLocationId = mapData.current_location_id;
            const worldId = mapData.world_id;

            // 2. Pobierz węzły z widoku (uwzględnia nazwy królestw dzięki RLS fix)
             const { data: nodes, error: nError } = await window.twSupabase
                .from('v_cyber_world_nodes')
                .select('*') 
                .eq('world_id', worldId);
            
            if (nError || !nodes) return;
            allNodes = nodes;

            // --- GENEROWANIE LEGENDY ---
            const legendContainer = document.getElementById('map-legend-container');
            legendContainer.innerHTML = '<div style="margin-bottom:5px; color:#adff00; font-weight:bold; text-transform:uppercase;">Territory Key</div>';
            
            const uniqueKingdoms = new Map();
            
            nodes.forEach(n => {
                if (n.kingdom_id) {
                    const kName = n.kingdom_name ? n.kingdom_name : `REGION ${n.kingdom_id}`;
                    uniqueKingdoms.set(n.kingdom_id, kName);
                }
            });

            if (uniqueKingdoms.size > 0) {
                uniqueKingdoms.forEach((name, id) => {
                    const color = getKingdomColor(id);
                    const item = document.createElement('div');
                    item.className = 'legend-item';
                    item.innerHTML = `<div class="legend-color" style="background:${color}"></div><span>${name.toUpperCase()}</span>`;
                    legendContainer.appendChild(item);
                });
            } else {
                legendContainer.innerHTML += '<div class="legend-item" style="color:#666;">Unknown Territories</div>';
            }

            // --- D3 SETUP ---
            const links = [];
            nodes.forEach(node => {
                if (node.neighbour_ids) {
                    node.neighbour_ids.forEach(nId => {
                        if (node.id < nId) links.push({ source: node.id, target: nId });
                    });
                }
            });

            const svg = d3.select("#cyber-map");
            svg.selectAll("*").remove();
            
            const g = svg.append("g");

            const zoom = d3.zoom()
                .scaleExtent([0.3, 3])
                .translateExtent([[-2000, -2000], [3000, 3000]])
                .on("zoom", (e) => g.attr("transform", e.transform));

            svg.call(zoom).on("dblclick.zoom", null);
            svg.call(zoom.transform, d3.zoomIdentity.translate(width / 2, height / 2).scale(0.8));

            simulation = d3.forceSimulation(nodes)
                .force("link", d3.forceLink(links).id(d => d.id).distance(140))
                .force("charge", d3.forceManyBody().strength(-600))
                .force("center", d3.forceCenter(0, 0))
                .force("collide", d3.forceCollide().radius(60));

            hullGroup = g.append("g").attr("class", "hulls");
            
            linkElements = g.append("g").selectAll("line")
                .data(links).join("line")
                .attr("stroke", "#444")
                .attr("stroke-width", 2)
                .attr("opacity", 0.6);

            const nodeGroup = g.append("g").selectAll("g")
                .data(nodes).join("g")
                .attr("cursor", "pointer")
                .call(d3.drag()
                    .on("start", (e, d) => { if (!e.active) simulation.alphaTarget(0.3).restart(); d.fx = d.x; d.fy = d.y; svg.style("cursor", "grabbing"); })
                    .on("drag", (e, d) => { d.fx = e.x; d.fy = e.y; })
                    .on("end", (e, d) => { if (!e.active) simulation.alphaTarget(0); d.fx = null; d.fy = null; svg.style("cursor", "grab"); }));

            nodeGroup.append("circle")
                .attr("r", 12).attr("fill", "#000").attr("stroke-width", 2);

            // Tło pod tekst (Label Box)
            nodeGroup.append("rect")
                .attr("class", "label-bg").attr("rx", 3).attr("ry", 3)
                .attr("fill", "rgba(0,0,0,0.85)").attr("stroke", "#333").attr("stroke-width", 1)
                .attr("height", 22).attr("y", 20);

            // Tekst Etykiety
            nodeGroup.append("text")
                .attr("dy", 35).attr("text-anchor", "middle")
                .style("font-family", "Chakra Petch").style("font-size", "11px")
                .style("font-weight", "500").style("letter-spacing", "0.5px")
                .style("pointer-events", "none").style("fill", "#fff")
                .text(d => d.location_name.toUpperCase());

            // Dopasowanie tła do szerokości tekstu
            nodeGroup.each(function(d) {
                const textEl = this.querySelector("text");
                const width = textEl.getComputedTextLength() + 16;
                d3.select(this).select("rect").attr("width", width).attr("x", -width / 2);
            });

            nodeElements = nodeGroup;

            window.updateMapVisuals = function(newLocationId) {
                currentSessionLocationId = newLocationId;
                
                nodeElements.select("circle")
                    .transition().duration(400)
                    .attr("r", d => d.id === currentSessionLocationId ? 18 : (d.is_discovered ? 10 : 6))
                    .attr("fill", d => d.id === currentSessionLocationId ? "#adff00" : "#0a0a0a")
                    .attr("stroke", d => d.id === currentSessionLocationId ? "#fff" : getKingdomColor(d.kingdom_id))
                    .style("filter", d => d.id === currentSessionLocationId ? "drop-shadow(0 0 15px #adff00)" : "none");

                nodeElements.select("text")
                    .text(d => (d.is_discovered || d.id === currentSessionLocationId) ? d.location_name.toUpperCase() : "???")
                    .style("opacity", d => (d.is_discovered || d.id === currentSessionLocationId) ? 1 : 0)
                    .style("fill", d => d.id === currentSessionLocationId ? "#adff00" : "#fff");

                nodeElements.select("rect")
                    .style("opacity", d => (d.is_discovered || d.id === currentSessionLocationId) ? 1 : 0)
                    .attr("stroke", d => d.id === currentSessionLocationId ? "#adff00" : "#333");
                
                nodeElements.each(function(d) {
                    if (!d.is_discovered && d.id !== currentSessionLocationId) return;
                    const textEl = this.querySelector("text");
                    const width = textEl.getComputedTextLength() + 16;
                    d3.select(this).select("rect").attr("width", width).attr("x", -width / 2);
                });
            };

            simulation.on("tick", () => {
                linkElements.attr("x1", d => d.source.x).attr("y1", d => d.source.y).attr("x2", d => d.target.x).attr("y2", d => d.target.y);
                nodeElements.attr("transform", d => `translate(${d.x},${d.y})`);

                const kingdomGroups = d3.group(nodes, d => d.kingdom_id);
                const hullData = [];
                for (const [kId, kNodes] of kingdomGroups) {
                    if(!kId) continue;
                    const points = kNodes.map(d => [d.x, d.y]);
                    if (points.length < 3) {
                         points.forEach(p => hullData.push({ id: kId, type: 'circle', x: p[0], y: p[1] }));
                    } else {
                        const hull = d3.polygonHull(points);
                        if(hull) hullData.push({ id: kId, type: 'poly', path: hull });
                    }
                }

                const paths = hullData.filter(d => d.type === 'poly');
                hullGroup.selectAll("path.poly").data(paths).join("path")
                    .attr("class", "poly").attr("fill", d => getKingdomColor(d.id))
                    .attr("stroke", d => getKingdomColor(d.id)).attr("stroke-width", 60)
                    .attr("stroke-linejoin", "round").attr("opacity", 0.15)
                    .attr("d", d => "M" + d.path.join("L") + "Z");

                const circles = hullData.filter(d => d.type === 'circle');
                hullGroup.selectAll("circle.hull-bg").data(circles).join("circle")
                    .attr("class", "hull-bg").attr("cx", d => d.x).attr("cy", d => d.y)
                    .attr("r", 50).attr("fill", d => getKingdomColor(d.id)).attr("opacity", 0.15);
            });

            window.updateMapVisuals(currentSessionLocationId);

            nodeElements.on("click", (event, d) => {
                if (!d.is_discovered && d.id !== currentSessionLocationId) return;
                const card = document.getElementById('location-card');
                document.getElementById('loc-title').innerText = d.location_name;
                
                const kName = d.kingdom_name ? d.kingdom_name.toUpperCase() : (d.kingdom_id ? `REGION ${d.kingdom_id}` : 'NEUTRAL ZONE');
                
                document.getElementById('loc-kingdom').innerText = kName;
                document.getElementById('loc-kingdom').style.color = getKingdomColor(d.kingdom_id);
                
                let desc = d.custom_description || (d.archetype_data ? d.archetype_data.mechanic_effect : "No data.");
                document.getElementById('loc-desc').innerText = desc;
                const statusText = d.id === currentSessionLocationId ? "CURRENT POSITION" : "DISCOVERED";
                document.getElementById('loc-status').innerText = "STATUS: " + statusText;
                card.style.display = 'block';
            });

            setInterval(async () => {
                const { data } = await window.twSupabase
                    .from('v_cyber_map_view')
                    .select('current_location_id')
                    .eq('wp_user_id', wpUserId)
                    .maybeSingle();
                if (data && data.current_location_id !== currentSessionLocationId) {
                    window.updateMapVisuals(data.current_location_id);
                }
            }, 5000);
        }

        initActiveMap();
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('cyber_active_map', 'tw_render_active_game_map');