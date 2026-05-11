jQuery(function ($) {
  var $statChars = $("#nw-stat-characters");
  var $statWorlds = $("#nw-stat-worlds");
  var $statCampaigns = $("#nw-stat-campaigns");
  var $statActiveSessions = $("#nw-stat-active-sessions");

  var $recentChars = $("#nw-recent-characters");
  var $recentWorlds = $("#nw-recent-worlds");
  var $recentCamps = $("#nw-recent-campaigns");
  var $recentActive = $("#nw-recent-active-sessions");

  var $statMessagesTotal = $("#nw-stat-messages-total");
  var $statMessages7d = $("#nw-stat-messages-7d");
  var $statMessages30d = $("#nw-stat-messages-30d");

  var $healthWWC = $("#nw-health-worlds-without-campaigns");
  var $healthCWC = $("#nw-health-campaigns-without-character");

  var $alerts = $("#nw-alerts");
  var $logs = $("#nw-logs");

  var $chartChars = $("#nw-chart-characters");
  var $chartWorlds = $("#nw-chart-worlds");
  var $chartCamps = $("#nw-chart-campaigns");

  var $rangeBtns = $(".nw-range-btn");
  var currentRange = 30;
  var activeXhr = null;

  var escapeMap = {
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;"
  };

  function escapeHtml(str) {
    return String(str == null ? "" : str).replace(/[&<>"']/g, function (c) {
      return escapeMap[c];
    });
  }

  var safeCssColorRe = /^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]+$|^rgba?\(/;

  function safeCssColor(color, fallback) {
    return safeCssColorRe.test(String(color || "")) ? color : fallback;
  }

  function setRangeButtons(range) {
    $rangeBtns.removeClass("is-active").attr("aria-pressed", "false");
    $rangeBtns.filter('[data-range="' + range + '"]').addClass("is-active").attr("aria-pressed", "true");
  }

  function drawSparkline(el, series, color) {
    var $el = $(el);

    if (!series || !series.length) {
      $el.html('<div class="nw-chart-empty">No data yet</div>');
      return;
    }

    var values = series.map(function (p) {
      return parseInt(p.value || 0, 10);
    });

    var max = values.reduce(function (a, b) {
      return a > b ? a : b;
    }, 0);

    if (max <= 0) {
      $el.html('<div class="nw-chart-empty">No growth yet</div>');
      return;
    }

    var safeColor = safeCssColor(color, "#adff00");
    var W = 320, H = 120, pad = 10;
    var stepX = (W - pad * 2) / Math.max(values.length - 1, 1);

    var pts = values.map(function (v, i) {
      return [pad + i * stepX, H - pad - (v / max) * (H - pad * 2)];
    });

    var line = pts.map(function (p) {
      return p[0].toFixed(2) + "," + p[1].toFixed(2);
    }).join(" ");

    var bars = values.map(function (v, i) {
      var x = pad + i * stepX - 3;
      var h = (v / max) * (H - pad * 2);
      var y = H - pad - h;
      return '<rect x="' + x.toFixed(2) + '" y="' + y.toFixed(2) + '" width="6" height="' + h.toFixed(2) + '" rx="2" fill="' + safeColor + '" opacity="0.22"></rect>';
    }).join("");

    var last = pts[pts.length - 1];

    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" aria-hidden="true">'
      + '<line x1="0" y1="' + (H - pad) + '" x2="' + W + '" y2="' + (H - pad) + '" stroke="#2c2c2c" stroke-width="1"></line>'
      + bars
      + '<polyline fill="none" stroke="' + safeColor + '" stroke-width="3" points="' + line + '"></polyline>'
      + '<circle cx="' + last[0].toFixed(2) + '" cy="' + last[1].toFixed(2) + '" r="4" fill="' + safeColor + '"></circle>'
      + '</svg>';

    $el.html(svg);
  }

  function renderAlerts(alerts) {
    if (!alerts || !alerts.length) {
      $alerts.html(
        '<div class="nw-alert-card nw-alert-ok">' +
          '<div class="nw-alert-dot"></div>' +
          '<div class="nw-alert-body">' +
            '<div class="nw-alert-label">System</div>' +
            '<div class="nw-alert-text">No immediate operational issues detected.</div>' +
          '</div>' +
        '</div>'
      );
      return;
    }

    $alerts.html(alerts.map(function (a) {
      return '<div class="nw-alert-card nw-alert-' + escapeHtml(a.level || "info") + '">' +
        '<div class="nw-alert-dot"></div>' +
        '<div class="nw-alert-body">' +
          '<div class="nw-alert-label">' + escapeHtml(a.label || "Notice") + '</div>' +
          '<div class="nw-alert-text">' + escapeHtml(a.text || "") + '</div>' +
        '</div>' +
      '</div>';
    }).join(""));
  }

  function renderLogs(logs) {
    if (!logs || !logs.length) {
      $logs.html('<div class="nw-empty-state">No recent system events.</div>');
      return;
    }

    $logs.html(logs.map(function (log) {
      var lvl = String(log.level || "info").toLowerCase();
      if (["info", "warn", "error"].indexOf(lvl) === -1) {
        lvl = "info";
      }

      var dataText = "";
      if (log.data !== null && typeof log.data !== "undefined" && String(log.data).length) {
        dataText = typeof log.data === "object" ? JSON.stringify(log.data) : String(log.data);
      }

      var contextText = log.context ? escapeHtml(log.context) : "-";

      return '<div class="nw-log-item">' +
        '<div class="nw-log-top">' +
          '<span class="nw-log-level nw-log-level-' + escapeHtml(lvl) + '">' + escapeHtml(lvl) + '</span>' +
          '<span class="nw-log-date">' + escapeHtml(log.created_at || "") + '</span>' +
        '</div>' +
        '<div class="nw-log-message">' + escapeHtml(log.message || "(no message)") + '</div>' +
        '<div class="nw-log-meta">context: ' + contextText + (dataText ? " | data: " + escapeHtml(dataText) : "") + '</div>' +
      '</div>';
    }).join(""));
  }

  function setLoadingState() {
    $statChars
      .add($statWorlds)
      .add($statCampaigns)
      .add($statActiveSessions)
      .add($statMessagesTotal)
      .add($statMessages7d)
      .add($statMessages30d)
      .html('<div class="nw-spinner"></div>');

    $recentChars
      .add($recentWorlds)
      .add($recentCamps)
      .text("Last 7d: -");

    $recentActive.text("Campaigns live: -");
    $healthWWC.add($healthCWC).text("-");

    $alerts.html('<div class="nw-alert-card nw-alert-card-loading"><div class="nw-spinner"></div><span>Refreshing...</span></div>');
    $logs.html('<div class="nw-empty-state">Loading recent events...</div>');

    $chartChars
      .add($chartWorlds)
      .add($chartCamps)
      .html('<div class="nw-chart-empty">Loading...</div>');
  }

  function setErrorState(message) {
    $statChars
      .add($statWorlds)
      .add($statCampaigns)
      .add($statActiveSessions)
      .add($statMessagesTotal)
      .add($statMessages7d)
      .add($statMessages30d)
      .text("-");

    renderAlerts([{
      level: "error",
      label: "Dashboard",
      text: message || "Could not load dashboard data."
    }]);

    $logs.html('<div class="nw-empty-state">Could not load logs.</div>');
  }

  function loadDashboard(range) {
    if (activeXhr) {
      activeXhr.abort();
      activeXhr = null;
    }

    currentRange = (range === 7 || range === 30) ? range : 30;
    setRangeButtons(currentRange);
    setLoadingState();

    activeXhr = $.post(
      NWDashData.ajaxurl,
      {
        action: "nw_dashboard_data",
        nonce: NWDashData.nonce,
        range: currentRange
      },
      function (res) {
        activeXhr = null;

        if (!res || !res.success) {
          setErrorState((res && res.data) ? res.data : "Could not load dashboard data.");
          return;
        }

        var d = res.data || {};
        var c = d.counts || {};
        var r = d.recent || {};
        var g = d.growth || {};
        var h = d.health || {};

        if (d.range_days) {
          currentRange = parseInt(d.range_days, 10) || currentRange;
          setRangeButtons(currentRange);
        }

        if (d._debug && NWDashData.debug === "1") {
          console.group("[NeoWeaver] Dashboard debug");
          console.log("Key type:", d._debug.key_type);
          console.table(d._debug.growth_meta);
          console.groupEnd();
        }

        $statChars.text(c.characters || 0);
        $statWorlds.text(c.worlds || 0);
        $statCampaigns.text(c.campaigns || 0);
        $statActiveSessions.text(c.active_sessions || 0);

        $recentChars.text("Last 7d: +" + (r.characters_7d || 0));
        $recentWorlds.text("Last 7d: +" + (r.worlds_7d || 0));
        $recentCamps.text("Last 7d: +" + (r.campaigns_7d || 0));
        $recentActive.text("Campaigns live: " + (c.campaigns_with_active_session || 0));

        $statMessagesTotal.text(c.messages_total || 0);
        $statMessages7d.text(r.messages_7d || 0);
        $statMessages30d.text(r.messages_30d || 0);

        $healthWWC.text(h.worlds_without_campaigns || 0);
        $healthCWC.text(h.campaigns_without_character || 0);

        drawSparkline("#nw-chart-characters", g.characters, "#adff00");
        drawSparkline("#nw-chart-worlds", g.worlds, "#00d4ff");
        drawSparkline("#nw-chart-campaigns", g.campaigns, "#ffb703");

        renderAlerts(d.alerts || []);
        renderLogs(d.logs || []);
      }
    ).fail(function (xhr, status) {
      activeXhr = null;
      if (status === "abort") {
        return;
      }

      setErrorState("Dashboard request failed (" + status + "). Check your connection or server logs.");
    });
  }

  loadDashboard(currentRange);

  var refreshTimeout = null;
  $("#nw-refresh-dashboard").on("click", function () {
    if (refreshTimeout) {
      return;
    }
    loadDashboard(currentRange);
    refreshTimeout = setTimeout(function () {
      refreshTimeout = null;
    }, 2000);
  });

  $rangeBtns.on("click", function () {
    var nextRange = parseInt($(this).data("range"), 10);
    if (nextRange !== 7 && nextRange !== 30) {
      return;
    }
    if (nextRange === currentRange) {
      return;
    }
    loadDashboard(nextRange);
  });
});
