document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('node-name-display');
    if (!el) return;

    const worldId = el.dataset.worldId;
    if (!worldId) { el.textContent = 'NO_NODE'; return; }

    // twNeoWeaverData jest wstrzykiwany przez wp_localize_script w pluginie
    const { supabaseUrl, supabaseKey } = window.twNeoWeaverData;

    fetch(`${supabaseUrl}/rest/v1/cyber_worlds?id=eq.${worldId}&select=name`, {
        headers: { 'apikey': supabaseKey, 'Authorization': `Bearer ${supabaseKey}` }
    })
    .then(r => r.json())
    .then(data => {
        el.textContent = data[0]?.name ?? 'UNKNOWN_NODE';
        el.style.color = 'var(--neon-green)';
    })
    .catch(() => { el.textContent = 'CONN_ERROR'; });
});
