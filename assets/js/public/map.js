document.addEventListener('DOMContentLoaded', function () {
    if (!window.twSupabase || typeof d3 === 'undefined') return;

    let currentSessionLocationId = null;
    let allNodes = [];
    let simulation = null;
    let linkElements = null;
    let nodeElements = null;
    let hullGroup = null;
    let locationPoller = null;

    const kingdomColorMap = new Map();
    const baseColors = [
        '#adff00', '#00f3ff', '#ff0055', '#ffd700', '#b800ff', '#ff6600'
    ];

    function getKingdomColor(id) {
        if (!id) return '#444';

        if (!kingdomColorMap.has(id)) {
            const colorIndex = kingdomColorMap.size % baseColors.length;
            kingdomColorMap.set(id, baseColors[colorIndex]);
        }

        return kingdomColorMap.get(id);
    }

    function safeUpper(value, fallback = '') {
        return String(value || fallback).toUpperCase();
    }

    function safeTextWidth(textEl, fallback = 40) {
        try {
            if (textEl && typeof textEl.getComputedTextLength === 'function') {
                const w = textEl.getComputedTextLength();
                return Number.isFinite(w) && w > 0 ? w : fallback;
            }
        } catch (e) {}
        return fallback;
    }

    function fitLabelBoxes(selection) {
        selection.each(function (d) {
            const textEl = this.querySelector('text');
            const rectEl = d3.select(this).select('rect');

            if (!textEl || rectEl.empty()) return;

            const width = Math.max(40, safeTextWidth(textEl, 40) + 16);
            rectEl.attr('width', width).attr('x', -width / 2);
        });
    }

    async function initActiveMap() {
        const wpUserId = Number(<?php echo (int) $wp_user_id; ?>);

        const container = document.getElementById('tw-map-container');
        const svgDom = document.getElementById('cyber-map');
        const legendContainer = document.getElementById('map-legend-container');

        if (!container || !svgDom || !legendContainer) return;

        const width = container.clientWidth || 800;
        const height = container.clientHeight || 600;

        const { data: mapRows, error: sError } = await window.twSupabase
            .from('v_cyber_map_view')
            .select('current_location_id, world_id')
            .eq('wp_user_id', wpUserId)
            .limit(1);

        if (sError || !Array.isArray(mapRows) || !mapRows.length) return;

        const mapData = mapRows[0];
        currentSessionLocationId = mapData.current_location_id;
        const worldId = mapData.world_id;

        if (!worldId) return;

        const { data: nodes, error: nError } = await window.twSupabase
            .from('v_cyber_world_nodes')
            .select('*')
            .eq('world_id', worldId);

        if (nError || !Array.isArray(nodes) || !nodes.length) return;

        allNodes = nodes;

        legendContainer.innerHTML = '<div style="margin-bottom:5px; color:#adff00; font-weight:bold; text-transform:uppercase;">Territory Key</div>';

        const uniqueKingdoms = new Map();

        nodes.forEach(node => {
            if (node.kingdom_id) {
                const kName = node.kingdom_name ? node.kingdom_name : `REGION ${node.kingdom_id}`;
                uniqueKingdoms.set(node.kingdom_id, kName);
            }
        });

        if (uniqueKingdoms.size > 0) {
            uniqueKingdoms.forEach((name, id) => {
                const color = getKingdomColor(id);
                const item = document.createElement('div');
                item.className = 'legend-item';
                item.innerHTML = `<div class="legend-color" style="background:${color}"></div><span>${safeUpper(name)}</span>`;
                legendContainer.appendChild(item);
            });
        } else {
            legendContainer.innerHTML += '<div class="legend-item" style="color:#666;">Unknown Territories</div>';
        }

        const links = [];
        nodes.forEach(node => {
            if (Array.isArray(node.neighbour_ids)) {
                node.neighbour_ids.forEach(nId => {
                    if (node.id < nId) {
                        links.push({ source: node.id, target: nId });
                    }
                });
            }
        });

        const svg = d3.select('#cyber-map');
        svg.selectAll('*').remove();

        const g = svg.append('g');

        const zoom = d3.zoom()
            .scaleExtent([0.3, 3])
            .translateExtent([[-2000, -2000], [3000, 3000]])
            .on('zoom', e => {
                g.attr('transform', e.transform);
            });

        svg.call(zoom).on('dblclick.zoom', null);
        svg.call(
            zoom.transform,
            d3.zoomIdentity.translate(width / 2, height / 2).scale(0.8)
        );

        simulation = d3.forceSimulation(nodes)
            .force('link', d3.forceLink(links).id(d => d.id).distance(140))
            .force('charge', d3.forceManyBody().strength(-600))
            .force('center', d3.forceCenter(0, 0))
            .force('collide', d3.forceCollide().radius(60));

        hullGroup = g.append('g').attr('class', 'hulls');

        linkElements = g.append('g')
            .selectAll('line')
            .data(links)
            .join('line')
            .attr('stroke', '#444')
            .attr('stroke-width', 2)
            .attr('opacity', 0.6);

        const nodeGroup = g.append('g')
            .selectAll('g')
            .data(nodes)
            .join('g')
            .attr('cursor', 'pointer')
            .call(
                d3.drag()
                    .on('start', (e, d) => {
                        if (!e.active) simulation.alphaTarget(0.3).restart();
                        d.fx = d.x;
                        d.fy = d.y;
                        svg.style('cursor', 'grabbing');
                    })
                    .on('drag', (e, d) => {
                        d.fx = e.x;
                        d.fy = e.y;
                    })
                    .on('end', (e, d) => {
                        if (!e.active) simulation.alphaTarget(0);
                        d.fx = null;
                        d.fy = null;
                        svg.style('cursor', 'grab');
                    })
            );

        nodeGroup.append('circle')
            .attr('r', 12)
            .attr('fill', '#000')
            .attr('stroke-width', 2);

        nodeGroup.append('rect')
            .attr('class', 'label-bg')
            .attr('rx', 3)
            .attr('ry', 3)
            .attr('fill', 'rgba(0,0,0,0.85)')
            .attr('stroke', '#333')
            .attr('stroke-width', 1)
            .attr('height', 22)
            .attr('y', 20);

        nodeGroup.append('text')
            .attr('dy', 35)
            .attr('text-anchor', 'middle')
            .style('font-family', 'Chakra Petch')
            .style('font-size', '11px')
            .style('font-weight', '500')
            .style('letter-spacing', '0.5px')
            .style('pointer-events', 'none')
            .style('fill', '#fff')
            .text(d => safeUpper(d.location_name));

        fitLabelBoxes(nodeGroup);

        nodeElements = nodeGroup;

        window.updateMapVisuals = function (newLocationId) {
            currentSessionLocationId = newLocationId;

            nodeElements.select('circle')
                .transition()
                .duration(400)
                .attr('r', d => d.id === currentSessionLocationId ? 18 : (d.is_discovered ? 10 : 6))
                .attr('fill', d => d.id === currentSessionLocationId ? '#adff00' : '#0a0a0a')
                .attr('stroke', d => d.id === currentSessionLocationId ? '#fff' : getKingdomColor(d.kingdom_id))
                .style('filter', d => d.id === currentSessionLocationId ? 'drop-shadow(0 0 15px #adff00)' : 'none');

            nodeElements.select('text')
                .text(d => (d.is_discovered || d.id === currentSessionLocationId) ? safeUpper(d.location_name) : '???')
                .style('opacity', d => (d.is_discovered || d.id === currentSessionLocationId) ? 1 : 0)
                .style('fill', d => d.id === currentSessionLocationId ? '#adff00' : '#fff');

            nodeElements.select('rect')
                .style('opacity', d => (d.is_discovered || d.id === currentSessionLocationId) ? 1 : 0)
                .attr('stroke', d => d.id === currentSessionLocationId ? '#adff00' : '#333');

            fitLabelBoxes(nodeElements);
        };

        simulation.on('tick', () => {
            linkElements
                .attr('x1', d => d.source.x)
                .attr('y1', d => d.source.y)
                .attr('x2', d => d.target.x)
                .attr('y2', d => d.target.y);

            nodeElements.attr('transform', d => `translate(${d.x},${d.y})`);

            const kingdomGroups = d3.group(nodes, d => d.kingdom_id);
            const hullData = [];

            for (const [kId, kNodes] of kingdomGroups) {
                if (!kId) continue;

                const points = kNodes
                    .filter(d => Number.isFinite(d.x) && Number.isFinite(d.y))
                    .map(d => [d.x, d.y]);

                if (points.length < 3) {
                    points.forEach(p => hullData.push({ id: kId, type: 'circle', x: p[0], y: p[1] }));
                } else {
                    const hull = d3.polygonHull(points);
                    if (hull) {
                        hullData.push({ id: kId, type: 'poly', path: hull });
                    }
                }
            }

            const paths = hullData.filter(d => d.type === 'poly');
            hullGroup.selectAll('path.poly')
                .data(paths, d => `poly-${d.id}`)
                .join('path')
                .attr('class', 'poly')
                .attr('fill', d => getKingdomColor(d.id))
                .attr('stroke', d => getKingdomColor(d.id))
                .attr('stroke-width', 60)
                .attr('stroke-linejoin', 'round')
                .attr('opacity', 0.15)
                .attr('d', d => 'M' + d.path.join('L') + 'Z');

            const circles = hullData.filter(d => d.type === 'circle');
            hullGroup.selectAll('circle.hull-bg')
                .data(circles, d => `circle-${d.id}-${d.x}-${d.y}`)
                .join('circle')
                .attr('class', 'hull-bg')
                .attr('cx', d => d.x)
                .attr('cy', d => d.y)
                .attr('r', 50)
                .attr('fill', d => getKingdomColor(d.id))
                .attr('opacity', 0.15);
        });

        window.updateMapVisuals(currentSessionLocationId);

        nodeElements.on('click', (event, d) => {
            if (!d.is_discovered && d.id !== currentSessionLocationId) return;

            const card = document.getElementById('location-card');
            const titleEl = document.getElementById('loc-title');
            const kingdomEl = document.getElementById('loc-kingdom');
            const descEl = document.getElementById('loc-desc');
            const statusEl = document.getElementById('loc-status');

            if (!card || !titleEl || !kingdomEl || !descEl || !statusEl) return;

            titleEl.innerText = d.location_name || 'Unknown Location';

            const kName = d.kingdom_name
                ? safeUpper(d.kingdom_name)
                : (d.kingdom_id ? `REGION ${d.kingdom_id}` : 'NEUTRAL ZONE');

            kingdomEl.innerText = kName;
            kingdomEl.style.color = getKingdomColor(d.kingdom_id);

            const desc = d.custom_description
                || (d.archetype_data ? d.archetype_data.mechanic_effect : 'No data.');

            descEl.innerText = desc;

            const statusText = d.id === currentSessionLocationId ? 'CURRENT POSITION' : 'DISCOVERED';
            statusEl.innerText = 'STATUS: ' + statusText;

            card.style.display = 'block';
        });

        if (locationPoller) {
            clearInterval(locationPoller);
        }

        locationPoller = setInterval(async () => {
            const { data: pollRows, error: pollError } = await window.twSupabase
                .from('v_cyber_map_view')
                .select('current_location_id')
                .eq('wp_user_id', wpUserId)
                .limit(1);

            if (pollError || !Array.isArray(pollRows) || !pollRows.length) return;

            const latestLocationId = pollRows[0].current_location_id;

            if (latestLocationId !== currentSessionLocationId && typeof window.updateMapVisuals === 'function') {
                window.updateMapVisuals(latestLocationId);
            }
        }, 5000);
    }

    initActiveMap();
});
