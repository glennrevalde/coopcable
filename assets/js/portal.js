/* G-SMaRT Coop Cable System: the subscriber portal (plain JavaScript).
 *   [data-print]          prints the page (the bill, receipt or statement; "Save as PDF" in the print dialog)
 *   [data-autosubmit]     a select that sends its form when changed
 *   form[data-confirm]    asks before sending
 *   [data-show-pw=id]     shows / hides a password
 *   [data-countup]        figures counting up when they appear
 *   [data-request-type]   shows the fields of the chosen kind of service request ([data-for=kind])
 */
(function () {
  'use strict';
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function countUp(el) {
    var txt = el.textContent.trim();
    var m = txt.match(/^([^0-9\-]*)(-?[0-9][0-9,]*)(\.[0-9]+)?(.*)$/);
    if (!m || reduced) return;
    var target = parseFloat((m[2] + (m[3] || '')).replace(/,/g, ''));
    var dec = m[3] ? m[3].length - 1 : 0;
    if (!isFinite(target) || target === 0) return;
    var start = null;
    function fmt(v) { return m[1] + v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + m[4]; }
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min(1, (ts - start) / 800);
      el.textContent = fmt(target * (1 - Math.pow(1 - p, 3)));
      if (p < 1) requestAnimationFrame(step); else el.textContent = txt;
    }
    el.textContent = fmt(0);
    requestAnimationFrame(step);
  }

  function requestForm(sel) {
    var form = sel.form;
    function sync() {
      form.querySelectorAll('[data-for]').forEach(function (box) {
        var on = box.getAttribute('data-for') === sel.value;
        box.hidden = !on;
        box.querySelectorAll('input, select, textarea').forEach(function (f) {
          if (f.name === 'new_address' || f.name === 'plan_id' || f.name === 'from' || f.name === 'to') f.required = on;
        });
      });
    }
    sel.addEventListener('change', sync);
    sync();
  }

  function init() {
    document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
    document.querySelectorAll('[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { if (s.form) s.form.submit(); }); });
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
      f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault(); });
    });
    document.querySelectorAll('[data-show-pw]').forEach(function (b) {
      b.addEventListener('click', function () {
        var i = document.getElementById(b.getAttribute('data-show-pw'));
        if (!i) return;
        var show = i.type === 'password';
        i.type = show ? 'text' : 'password';
        b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        b.innerHTML = '<i class="bi bi-eye' + (show ? '-slash' : '') + '"></i>';
      });
    });
    document.querySelectorAll('[data-request-type]').forEach(requestForm);
    // a form is sent once: the button is held while the page loads
    document.querySelectorAll('form[method=post]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        var b = f.querySelector('button:not([type=button])');
        if (b) setTimeout(function () { b.disabled = true; }, 0);
      });
    });
    var nums = document.querySelectorAll('[data-countup]');
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (ents) {
        ents.forEach(function (en) { if (en.isIntersecting) { io.unobserve(en.target); countUp(en.target); } });
      }, { threshold: 0.4 });
      nums.forEach(function (n) { io.observe(n); });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
