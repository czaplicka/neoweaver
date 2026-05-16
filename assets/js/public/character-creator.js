(function () {
  'use strict';

  // ─── Config ──────────────────────────────────────────────────────────────────
  var cfg = Object.assign(
    {},
    window.neoweaverAjax       || {},
    window.twCharCreatorAjax   || {},
    window.twCharCreatorConfig || {},
    window.twCharCreatorGalleryConfig || {}
  );