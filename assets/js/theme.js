/* G-SMaRT Coop Cable System: the motion of the shell (plain JavaScript, no library).
 *   - the top bar deepens its shadow once the page scrolls
 *   - buttons answer a press with a ripple from the point touched
 *   - children of .stagger appear one after the other (--i)
 *   - the open menu item is scrolled into view; on phones the menu closes when a link is chosen
 * Nothing moves for people whose system asks for reduced motion.
 */
(function () {
  'use strict';
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function onReady(fn) { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn(); }

  onReady(function () {
    var bar = document.querySelector('.navbar.app-bar');
    if (bar) {
      var tick = false;
      var mark = function () { bar.classList.toggle('is-scrolled', window.scrollY > 6); tick = false; };
      window.addEventListener('scroll', function () { if (!tick) { tick = true; requestAnimationFrame(mark); } }, { passive: true });
      mark();
    }

    document.querySelectorAll('.stagger').forEach(function (box) {
      Array.prototype.forEach.call(box.children, function (el, i) { el.style.setProperty('--i', Math.min(i, 12)); });
    });

    var side = document.getElementById('sidebar');
    if (side) {
      var active = side.querySelector('.menu-link.active');
      if (active && active.scrollIntoView && window.innerWidth >= 992) {
        var r = active.getBoundingClientRect();
        if (r.bottom > window.innerHeight - 40) active.scrollIntoView({ block: 'center' });
      }
      side.addEventListener('click', function (e) {
        var a = e.target.closest('a.menu-link');
        if (a && window.innerWidth < 992 && window.bootstrap && bootstrap.Offcanvas) {
          var oc = bootstrap.Offcanvas.getInstance(side);
          if (oc) oc.hide();
        }
      });
    }

    if (!still) {
      document.addEventListener('pointerdown', function (e) {
        var b = e.target.closest && e.target.closest('.btn');
        if (!b || b.disabled || b.classList.contains('btn-close') || b.classList.contains('dropdown-toggle')) return;
        var rect = b.getBoundingClientRect();
        var d = Math.max(rect.width, rect.height);
        var s = document.createElement('span');
        s.className = 'gs-ripple';
        s.style.width = s.style.height = d + 'px';
        s.style.left = (e.clientX - rect.left - d / 2) + 'px';
        s.style.top = (e.clientY - rect.top - d / 2) + 'px';
        b.classList.add('is-rippling');
        b.appendChild(s);
        setTimeout(function () { s.remove(); if (!b.querySelector('.gs-ripple')) b.classList.remove('is-rippling'); }, 600);
      }, { passive: true });
    }
  });
})();
