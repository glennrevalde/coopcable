/* G-SMaRT Coop Cable System: the behaviour of the cable / internet pages (plain JavaScript, no library).
 *   input[data-isp-account]   subscriber account picker (api.isp lists the matches as one types; the id goes in the hidden field)
 *   [data-countup]            KPI figures counting up when they come into view
 *   [data-check-all="name"]   a check box that ticks every check box named name[]
 *   [data-autosubmit]         a select that sends its form when changed
 *   [data-copy="text"]        copies the text to the clipboard
 */
(function () {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  // ---------------------------------------------------------------- account picker
  function picker(input) {
    var name = input.getAttribute('data-isp-account');
    var wrap = input.closest('.isp-acct-field') || input.parentNode;
    var hidden = wrap.querySelector('input[type=hidden][name="' + name + '"]');
    var menu = wrap.querySelector('.isp-acct-menu');
    if (!menu) { menu = document.createElement('div'); menu.className = 'isp-acct-menu'; menu.hidden = true; wrap.appendChild(menu); }
    var timer = null, items = [], active = -1, lastQ = null;

    function close() { menu.hidden = true; active = -1; }
    function choose(it) {
      hidden.value = it.id;
      input.value = it.no + ' - ' + it.name;
      close();
      input.dispatchEvent(new CustomEvent('isp:account', { bubbles: true, detail: it }));
    }
    function render() {
      if (!items.length) { menu.innerHTML = '<div class="isp-acct-none">No account matches</div>'; menu.hidden = false; return; }
      menu.innerHTML = items.map(function (it, i) {
        return '<button type="button" class="isp-acct-opt' + (i === active ? ' is-active' : '') + '" data-i="' + i + '">'
          + '<span class="isp-acct-l1"><strong>' + esc(it.no) + '</strong> ' + esc(it.name) + '</span>'
          + '<span class="isp-acct-l2"><span class="st st-' + esc(it.tone) + '">' + esc(it.status) + '</span> ' + esc(it.plan) + (it.balance !== '0.00' ? ', balance ' + esc(it.balance) : '') + '</span></button>';
      }).join('');
      menu.hidden = false;
    }
    function search() {
      var q = input.value.trim();
      if (q.length < 2 || q === lastQ) { if (q.length < 2) close(); return; }
      lastQ = q;
      fetch(input.getAttribute('data-url') + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { items = (j && j.items) || []; active = items.length ? 0 : -1; render(); })
        .catch(function () { close(); });
    }
    input.addEventListener('input', function () { hidden.value = ''; lastQ = null; clearTimeout(timer); timer = setTimeout(search, 220); });
    input.addEventListener('focus', function () { if (input.value.trim().length >= 2 && !hidden.value) search(); });
    input.addEventListener('keydown', function (e) {
      if (menu.hidden) return;
      if (e.key === 'ArrowDown') { active = Math.min(items.length - 1, active + 1); render(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { active = Math.max(0, active - 1); render(); e.preventDefault(); }
      else if (e.key === 'Enter' && active >= 0 && items[active]) { choose(items[active]); e.preventDefault(); }
      else if (e.key === 'Escape') { close(); }
    });
    menu.addEventListener('mousedown', function (e) {
      var b = e.target.closest('.isp-acct-opt');
      if (b) { e.preventDefault(); choose(items[+b.getAttribute('data-i')]); }
    });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
  }

  // ---------------------------------------------------------------- KPI count-up
  function countUp(el) {
    var txt = el.textContent.trim();
    var m = txt.match(/^([^0-9\-]*)(-?[0-9][0-9,]*)(\.[0-9]+)?(.*)$/);
    if (!m || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var target = parseFloat((m[2] + (m[3] || '')).replace(/,/g, ''));
    var dec = m[3] ? m[3].length - 1 : 0;
    if (!isFinite(target) || target === 0) return;
    var start = null, dur = 900;
    function fmt(v) { return m[1] + v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + m[4]; }
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min(1, (ts - start) / dur);
      var e = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(target * e);
      if (p < 1) requestAnimationFrame(step); else el.textContent = txt;
    }
    el.textContent = fmt(0);
    requestAnimationFrame(step);
  }

  function init(root) {
    root.querySelectorAll('input[data-isp-account]').forEach(function (i) { if (!i.dataset.ready) { i.dataset.ready = '1'; picker(i); } });
    var nums = root.querySelectorAll('[data-countup]');
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (ents) {
        ents.forEach(function (en) { if (en.isIntersecting) { io.unobserve(en.target); countUp(en.target); } });
      }, { threshold: 0.4 });
      nums.forEach(function (n) { io.observe(n); });
    }
    root.querySelectorAll('[data-check-all]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var form = cb.form || document;
        form.querySelectorAll('input[type=checkbox][name="' + cb.getAttribute('data-check-all') + '[]"]').forEach(function (x) { if (!x.disabled) x.checked = cb.checked; });
      });
    });
    root.querySelectorAll('[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { if (s.form) s.form.submit(); }); });
    root.querySelectorAll('[data-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (navigator.clipboard) navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { b.classList.add('is-copied'); setTimeout(function () { b.classList.remove('is-copied'); }, 1200); });
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); });
  else init(document);
  window.IspUi = { init: init };
})();
