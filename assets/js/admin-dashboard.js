jQuery(function ($) {

  /* ── Cached selectors (Optimisation #1) ─────────────────────────── */
  var $statChars     = $("#nw-stat-characters");
  var $statWorlds    = $("#nw-stat-worlds");
  var $statCampaigns = $("#nw-stat-campaigns");
  var $statDeck      = $("#nw-stat-deck-cards");
  var $recentChars   = $("#nw-recent-characters");
  var $recentWorlds  = $("#nw-recent-worlds");
  var $recentCamps   = $("#nw-recent-campaigns");
  var $recentDeck    = $("#nw-recent-deck-cards");
  var $healthWWC     = $("#nw-health-worlds-without-campaigns");
  var $healthCWC     = $("#nw-health-campaigns-without-character");
  var $alerts        = $("#nw-alerts");
  var $logs          = $("#nw-logs");
  var $chartChars    = $("#nw-chart-characters");
  var $chartWorlds   = $("#nw-chart-worlds");
  var $chartCamps    = $("#nw-chart-campaigns");
  var $chartDeck     = $("#nw-chart-deck-cards");
  var $deckCats      = $("#nw-deck-categories");
  var $deckRars      = $("#nw-deck-rarities");
  var $deckActive    = $("#nw-deck-active");
  var $deckInactive  = $("#nw-deck-inactive");

  /* ── In-flight guard (Bug #7) ────────────────────────────────────── */
  var activeXhr = null;

  /* ── escapeHtml with single-regex lookup (Optimisation #3) ─────── */
  var escapeMap = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" };
  function escapeHtml(str) {
    return String(str == null ? "" : str).replace(/[&<>"']/g, function (c) { return escapeMap[c]; });
  }

  /* ── Safe CSS colour validator (Bug #5) ─────────────────────────── */
  var safeCssColorRe = /^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]+$|^rgba?\(/;
  function safeCssColor(color, fallback) {
    return safeCssColorRe.test(String(color || "")) ? color : fallback;
  }

  /* ── Safe CSS class-name fragment (Bug #4) ──────────────────────── */
  function safeCssKey(str) {
    return String(str || "").toLowerCase().replace(/[^a-z0-9-_]/g, "_");
  }

  function drawSparkline(el, series, color) {
    var $el = $(el);
    if (!series || !series.length) { $el.html('<div class="nw-chart-empty">No data yet</div>'); return; }
    var values = series.map(function (p) { return parseInt(p.value ?? 0, 10); }); // Bug #8: ?? instead of ||
    // Optimisation #2: avoid Math.max.apply() stack-limit issue
    var max = values.reduce(function (a, b) { return a > b ? a : b; }, 0);
    if (max <= 0) { $el.html('<div class="nw-chart-empty">No growth yet</div>'); return; }
    // Optimisation #5: all-zero early exit already covered by max <= 0 check above

    // Bug #5: validate color before injecting into SVG
    var safeColor = safeCssColor(color, "#adff00");

    var W = 320, H = 120, pad = 10;
    var stepX = (W - pad * 2) / Math.max(values.length - 1, 1);
    var pts = values.map(function (v, i) { return [pad + i * stepX, H - pad - (v / max) * (H - pad * 2)]; });
    var line = pts.map(function (p) { return p[0].toFixed(2) + "," + p[1].toFixed(2); }).join(" ");
    var bars = values.map(function (v, i) {
      var x = pad + i * stepX - 3, h = (v / max) * (H - pad * 2), y = H - pad - h;
      return '<rect x="' + x.toFixed(2) + '" y="' + y.toFixed(2) + '" width="6" height="' + h.toFixed(2) + '" rx="2" fill="' + safeColor + '" opacity="0.22"></rect>';
    }).join("");
    var last = pts[pts.length - 1];
    var svg = '<svg viewBox="0 0 ' + W + " " + H + '" preserveAspectRatio="none" aria-hidden="true">'
      + '<line x1="0" y1="' + (H - pad) + '" x2="' + W + '" y2="' + (H - pad) + '" stroke="#2c2c2c" stroke-width="1"></line>'
      + bars
      + '<polyline fill="none" stroke="' + safeColor + '" stroke-width="3" points="' + line + '"></polyline>'
      + '<circle cx="' + last[0].toFixed(2) + '" cy="' + last[1].toFixed(2) + '" r="4" fill="' + safeColor + '"></circle>'
      + '</svg>';
    $el.html(svg);
  }

  function renderDeckBars(containerId, data, colorPrefix) {
    var $w = $(containerId);
    if (!data || !Object.keys(data).length) { $w.html('<div class="nw-empty-state">No data</div>'); return; }
    var keys = Object.keys(data);
    var max = keys.reduce(function (m, k) { return data[k] > m ? data[k] : m; }, 0); // Optimisation #2
    if (max <= 0) { $w.html('<div class="nw-empty-state">No cards yet</div>'); return; }
    var html = keys.map(function (k) {
      var pct = Math.round((data[k] / max) * 100);
      // Bug #4: sanitise key before using as CSS class fragment
      var safeKey = safeCssKey(k);
      return '<div class="nw-deck-bar-row">'
        + '<div class="nw-deck-bar-meta"><span>' + escapeHtml(k) + '</span><span class="nw-deck-bar-count">' + data[k] + '</span></div>'
        + '<div class="nw-deck-bar-track"><div class="nw-deck-bar-fill ' + escapeHtml(colorPrefix) + safeKey + '" style="width:' + pct + '%"></div></div>'
        + '</div>';
    }).join("");
    $w.html(html);
  }

  function renderAlerts(alerts) {
    if (!alerts || !alerts.length) {
      $alerts.html('<div class="nw-alert-card nw-alert-ok"><div class="nw-alert-dot"></div><div class="nw-alert-body"><div class="nw-alert-label">System</div><div class="nw-alert-text">No immediate operational issues detected.</div></div></div>');
      return;
    }
    $alerts.html(alerts.map(function (a) {
      return '<div class="nw-alert-card nw-alert-' + escapeHtml(a.level || "info") + '"><div class="nw-alert-dot"></div><div class="nw-alert-body"><div class="nw-alert-label">' + escapeHtml(a.label || "Notice") + '</div><div class="nw-alert-text">' + escapeHtml(a.text || "") + '</div></div></div>';
    }).join(""));
  }

  function renderLogs(logs) {
    if (!logs || !logs.length) { $logs.html('<div class="nw-empty-state">No recent system events.</div>'); return; }
    $logs.html(logs.map(function (log) {
      var lvl = String(log.level || "info").toLowerCase();
      if (["info", "warn", "error"].indexOf(lvl) === -1) lvl = "info";
      var dataText = "";
      if (log.data !== null && typeof log.data !== "undefined" && String(log.data).length) {
        dataText = typeof log.data === "object" ? JSON.stringify(log.data) : String(log.data);
      }
      // Bug #3: use \u2014 as fallback BEFORE escapeHtml so the entity isn't double-escaped
      var contextText = log.context ? escapeHtml(log.context) : "\u2014";
      return '<div class="nw-log-item">'
        + '<div class="nw-log-top"><span class="nw-log-level nw-log-level-' + escapeHtml(lvl) + '">' + escapeHtml(lvl) + '</span><span class="nw-log-date">' + escapeHtml(log.created_at || "") + '</span></div>'
        + '<div class="nw-log-message">' + escapeHtml(log.message || "(no message)") + '</div>'
        + '<div class="nw-log-meta">context: ' + contextText + (dataText ? " | data: " + escapeHtml(dataText) : "") + '</div>'
        + '</div>';
    }).join(""));
  }

  function loadDashboard() {
    /* ── In-flight guard: abort previous request if still running (Bug #7) */
    if (activeXhr) { activeXhr.abort(); activeXhr = null; }

    $statChars.add($statWorlds).add($statCampaigns).add($statDeck).html('<div class="nw-spinner"></div>');
    $recentChars.add($recentWorlds).add($recentCamps).add($recentDeck).text("Last 7d: \u2014");
    $healthWWC.add($healthCWC).text("\u2014");
    $alerts.html('<div class="nw-alert-card nw-alert-card-loading"><div class="nw-spinner"></div><span>Refreshing\u2026</span></div>');
    $logs.html('<div class="nw-empty-state">Loading recent events\u2026</div>');
    $chartChars.add($chartWorlds).add($chartCamps).add($chartDeck).html('<div class="nw-chart-empty">Loading\u2026</div>');
    $deckCats.add($deckRars).html('<div class="nw-spinner" style="margin:20px auto;display:block;"></div>');
    $deckActive.add($deckInactive).text("\u2014");

    // Bug #1: use NWDashData.nonce (localised variable), NOT the hidden input
    // Bug #2: use NWDashData.ajaxurl instead of bare global ajaxurl
    activeXhr = $.post(
      NWDashData.ajaxurl,
      { action: "nw_dashboard_data", nonce: NWDashData.nonce },
      function (res) {
        activeXhr = null;
        if (!res.success) {
          $statChars.add($statWorlds).add($statCampaigns).add($statDeck).text("\u2014");
          renderAlerts([{ level: "warn", label: "Dashboard", text: (res.data || "Could not load dashboard data.") }]);
          $logs.html('<div class="nw-empty-state">Could not load logs.</div>');
          return;
        }
        var d = res.data || {}, c = d.counts || {}, r = d.recent || {}, g = d.growth || {}, h = d.health || {}, deck = d.deck_breakdown || {};

        // Optimisation #6: debug output gated behind NWDashData.debug flag
        if (d._debug && NWDashData.debug === "1") {
          console.group("[NeoWeaver] Dashboard debug");
          console.log("Key type:", d._debug.key_type);
          console.table(d._debug.growth_meta);
          console.groupEnd();
        }

        $statChars.text(c.characters || 0);
        $statWorlds.text(c.worlds || 0);
        $statCampaigns.text(c.campaigns || 0);
        $statDeck.text(c.deck_cards || 0);

        $recentChars.text("Last 7d: +" + (r.characters_7d || 0));
        $recentWorlds.text("Last 7d: +" + (r.worlds_7d || 0));
        $recentCamps.text("Last 7d: +" + (r.campaigns_7d || 0));
        $recentDeck.text("Last 7d: +" + (r.deck_cards_7d || 0));

        $healthWWC.text(h.worlds_without_campaigns || 0);
        $healthCWC.text(h.campaigns_without_character || 0);

        drawSparkline("#nw-chart-characters", g.characters, "#adff00");
        drawSparkline("#nw-chart-worlds", g.worlds, "#00d4ff");
        drawSparkline("#nw-chart-campaigns", g.campaigns, "#ffb703");
        drawSparkline("#nw-chart-deck-cards", g.deck_cards, "#ff5c5c");

        if (deck.categories) { renderDeckBars("#nw-deck-categories", deck.categories, "nw-deck-cat-"); }
        if (deck.rarities)   { renderDeckBars("#nw-deck-rarities",   deck.rarities,   "nw-deck-rar-"); }
        if (typeof deck.active_count !== "undefined") {
          $deckActive.text(deck.active_count || 0);
          $deckInactive.text(deck.inactive_count || 0);
        }

        renderAlerts(d.alerts || []);
        renderLogs(d.logs || []);
      }
    )
    // Bug #6: .fail() handler — shows error when AJAX itself fails (network, PHP 500, etc.)
    .fail(function (xhr, status) {
      activeXhr = null;
      if (status === "abort") return; // intentional abort from in-flight guard — silently ignore
      $statChars.add($statWorlds).add($statCampaigns).add($statDeck).text("\u2014");
      renderAlerts([{ level: "error", label: "Network", text: "Dashboard request failed (" + status + "). Check your connection or server logs." }]);
      $logs.html('<div class="nw-empty-state">Could not load logs.</div>');
    });
  }

  loadDashboard();

  /* Optimisation #4: debounced refresh button — 2 s cooldown */
  var refreshTimeout = null;
  $("#nw-refresh-dashboard").on("click", function () {
    if (refreshTimeout) return;
    loadDashboard();
    refreshTimeout = setTimeout(function () { refreshTimeout = null; }, 2000);
  });

});
