(function () {
  if (typeof document === "undefined") {
    return;
  }

  function initVitalis() {
    var panel = document.querySelector(".tw-vitalis-panel");
    if (!panel) {
      return;
    }

    var rings = panel.querySelectorAll(".tw-vitalis-ring");
    rings.forEach(function (ring) {
      var percentAttr = ring.getAttribute("data-percent");
      var percent = parseFloat(percentAttr || "0");
      if (isNaN(percent)) {
        percent = 0;
      }
      percent = Math.max(0, Math.min(100, percent));

      var fg = ring.querySelector(".tw-vitalis-ring-fg");
      if (!fg) {
        return;
      }

      // Zakładamy stroke-dasharray = 100; więc offset = 100 - percent
      var offset = 100 - percent;
      fg.style.strokeDashoffset = String(offset);

      // Progi kolorów / klas
      if (percent <= 25) {
        ring.classList.add("critical");
      } else if (percent <= 50) {
        ring.classList.add("warning");
      }

      // Dodatkowy efekt dla sync
      if (ring.getAttribute("data-meter") === "sync" && percent <= 40) {
        ring.classList.add("tw-vitalis-sync-low");
      }
    });

    // Prosty flicker dla niskiego sync (opcjonalny, ale klimatyczny).
    var syncRing = panel.querySelector('.tw-vitalis-ring[data-meter="sync"].tw-vitalis-sync-low');
    if (syncRing) {
      var fg = syncRing.querySelector(".tw-vitalis-ring-fg.sync");
      if (!fg) {
        return;
      }

      var baseOpacity = 1;
      var t = 0;

      function flicker() {
        t += 0.1;
        // prosty, trochę niestabilny flicker
        var noise = (Math.sin(t * 7) + Math.sin(t * 13)) / 4;
        var opacity = baseOpacity - Math.abs(noise) * 0.5;
        fg.style.opacity = String(Math.max(0.3, opacity));
        requestAnimationFrame(flicker);
      }

      requestAnimationFrame(flicker);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initVitalis);
  } else {
    initVitalis();
  }
})();
