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
