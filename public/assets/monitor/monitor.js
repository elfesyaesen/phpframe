/*
 * Monitor dashboard — saat ve canlı izleme.
 *
 * CSP (script-src 'self') inline <script>'i engellediği için ayrı dosyadır.
 * Dosya yüklenemezse dashboard sunucu-render haliyle çalışmaya devam eder.
 *
 * Saat: sunucunun saati ve saat dilimi (`data-now`, `data-tz`); tarayıcı
 * saatiyle fark bir kez hesaplanıp her saniye ilerletilir.
 *
 * Canlı izleme (`<body data-live="/monitor/live">`): uç nokta birkaç saniyede
 * bir yalnızca son kaydın id'sini döndürür. Id değiştiğinde mevcut sayfa arka
 * planda yeniden alınır ve sadece içerik alanı değiştirilir; kaydırma konumu
 * korunur, yeni satırlar vurgulanır. Yeni kayıt yoksa hiçbir şey yapılmaz.
 */
(function () {
  'use strict';

  // ── Saat ──────────────────────────────────────────────────
  var clock = document.querySelector('[data-clock]');
  if (clock) {
    var offset = Number(clock.getAttribute('data-now')) * 1000 - Date.now();
    var opts = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
    var fmt;
    try {
      fmt = new Intl.DateTimeFormat('tr-TR', Object.assign({ timeZone: clock.getAttribute('data-tz') }, opts));
    } catch (e) {
      fmt = new Intl.DateTimeFormat('tr-TR', opts);
    }
    var tick = function () { clock.textContent = fmt.format(new Date(Date.now() + offset)); };
    tick();
    setInterval(tick, 1000);
  }

  // ── Canlı izleme ──────────────────────────────────────────
  var endpoint = document.body.getAttribute('data-live');
  if (!endpoint || !window.fetch || !window.DOMParser) return;

  var INTERVAL = 2000;
  var lastId = null;
  var busy = false;

  function rowIds(root) {
    var ids = {};
    root.querySelectorAll('[data-rid]').forEach(function (el) { ids[el.getAttribute('data-rid')] = true; });
    return ids;
  }

  // Filtre formuna yazarken içerik değişirse yazılan kaybolur; o an atla.
  function userIsEditing() {
    var el = document.activeElement;
    return !!(el && el.closest && el.closest('.content form'));
  }

  function refreshContent() {
    return fetch(location.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var before = rowIds(document);
        [['.content', true], ['.topbar-dynamic', false]].forEach(function (pair) {
          var cur = document.querySelector(pair[0]);
          var next = doc.querySelector(pair[0]);
          if (cur && next) cur.innerHTML = next.innerHTML;
        });
        if (Object.keys(before).length) {
          document.querySelectorAll('[data-rid]').forEach(function (el) {
            if (!before[el.getAttribute('data-rid')]) el.classList.add('is-new');
          });
        }
      });
  }

  function poll() {
    if (busy || document.hidden) return;
    busy = true;
    fetch(endpoint, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (data) {
        var id = Number(data.last_id) || 0;
        if (lastId === null) { lastId = id; return; }
        if (id === lastId || userIsEditing()) return;
        lastId = id;
        return refreshContent();
      })
      .catch(function () { /* geçici ağ hatası — sonraki turda tekrar */ })
      .then(function () { busy = false; });
  }

  poll();
  setInterval(poll, INTERVAL);
  document.addEventListener('visibilitychange', poll);
})();
