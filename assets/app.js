(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Liquid navigation indicator: flows from the previous page's item to the current one */
  var list = document.querySelector('.nav-list'), ind = document.querySelector('.nav-ind');
  if (list && ind) {
    var place = function () {
      var on = list.querySelector('a.on');
      if (!on) { ind.style.opacity = 0; return null; }
      ind.style.opacity = 1;
      ind.style.height = on.offsetHeight + 'px';
      ind.style.transform = 'translateY(' + on.offsetTop + 'px)';
      return on;
    };
    var on = place();
    if (on) {
      var cur = on.offsetTop, h = on.offsetHeight, prev = parseFloat(sessionStorage.getItem('navY'));
      if (!reduce && !isNaN(prev) && prev !== cur && ind.animate) {
        var top = Math.min(prev, cur), span = Math.abs(cur - prev) + h;
        ind.animate([
          { transform: 'translateY(' + prev + 'px) scaleX(1)', height: h + 'px' },
          { transform: 'translateY(' + top + 'px) scaleX(.92)', height: span + 'px', offset: .45 },
          { transform: 'translateY(' + cur + 'px) scaleX(1)', height: h + 'px' }
        ], { duration: 560, easing: 'cubic-bezier(.3,0,.15,1)' });
      }
      sessionStorage.setItem('navY', cur);
    }
    window.addEventListener('resize', place);
  }

  /* Mobile drawer */
  document.querySelectorAll('[data-drawer-toggle]').forEach(function (b) {
    b.addEventListener('click', function () { document.body.classList.toggle('drawer-open'); });
  });

  /* Ripple + press feedback */
  document.addEventListener('pointerdown', function (e) {
    var el = e.target.closest('.btn, .nav-list a, .seg, .icon-btn');
    if (!el || reduce) return;
    var r = el.getBoundingClientRect(), d = Math.max(r.width, r.height) * 2.2, s = document.createElement('span');
    s.className = 'ripple';
    s.style.cssText = 'width:' + d + 'px;height:' + d + 'px;left:' + (e.clientX - r.left - d / 2) + 'px;top:' + (e.clientY - r.top - d / 2) + 'px';
    el.appendChild(s);
    setTimeout(function () { s.remove(); }, 600);
  });

  /* Confirm dangerous actions */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });

  /* Select-all checkbox */
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-checkall]')) {
      e.target.closest('form').querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = e.target.checked; });
    }
    if (e.target.matches('.seg input')) {
      var group = e.target.closest('.segmented');
      group.querySelectorAll('.seg').forEach(function (s) { s.classList.toggle('on', s.contains(e.target)); });
      syncModeExtra();
      if (e.target.hasAttribute('data-autosubmit')) e.target.form.requestSubmit();
    }
  });
  function syncModeExtra() {
    document.querySelectorAll('[data-show-for]').forEach(function (box) {
      var form = box.closest('form'), sel = form && form.querySelector('input[name="mode"]:checked');
      box.classList.toggle('show', !!sel && sel.value === box.getAttribute('data-show-for'));
    });
  }
  syncModeExtra();

  /* Keep the clicked button's value and show progress on slow actions */
  document.addEventListener('submit', function (e) {
    var btn = e.submitter;
    if (!btn) return;
    if (btn.name) {
      var h = document.createElement('input'); h.type = 'hidden'; h.name = btn.name; h.value = btn.value; e.target.appendChild(h);
    }
    setTimeout(function () { btn.disabled = true; btn.classList.add('pressed'); }, 0);
  });

  /* Reveal secrets, copy values */
  document.addEventListener('click', function (e) {
    var rv = e.target.closest('[data-reveal]');
    if (rv) {
      var inp = rv.parentNode.querySelector('input');
      inp.type = inp.type === 'password' ? 'text' : 'password';
      rv.querySelector('.ms').textContent = inp.type === 'password' ? 'visibility' : 'visibility_off';
    }
    var cp = e.target.closest('[data-copy]');
    if (cp) {
      var fld = cp.parentNode.querySelector('input, textarea'), v = fld.value;
      (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(function () {
        var ic = cp.querySelector('.ms'); ic.textContent = 'check'; setTimeout(function () { ic.textContent = 'content_copy'; }, 1400);
        if (cp.dataset.copied) cp.closest('form, .li-task') && cp.closest('.li-task') && cp.closest('.li-task').classList.add('copied');
      }).catch(function () { fld.select(); });
    }
    var dm = e.target.closest('[data-dismiss]');
    if (dm) closeToast(dm.closest('.toast'));
  });

  /* Toasts: success messages leave on their own, errors stay until dismissed */
  function closeToast(t) { if (!t) return; t.classList.add('out'); setTimeout(function () { t.remove(); }, 260); }
  document.querySelectorAll('.toast.ok').forEach(function (t) { setTimeout(function () { closeToast(t); }, 6000); });

  /* Mailbox provider presets */
  var ps = document.querySelector('[data-provider-select]');
  if (ps) ps.addEventListener('change', function () {
    var o = ps.options[ps.selectedIndex], f = ps.form, custom = ps.value === 'custom';
    if (!custom) {
      f.smtp_host.value = o.dataset.smtpHost; f.smtp_port.value = o.dataset.smtpPort; f.smtp_secure.value = o.dataset.smtpSecure;
      f.imap_host.value = o.dataset.imapHost; f.imap_port.value = o.dataset.imapPort;
    } else { f.smtp_host.value = ''; f.imap_host.value = ''; }
    document.querySelectorAll('[data-provider-help]').forEach(function (h) { h.hidden = h.getAttribute('data-provider-help') !== ps.value; });
    var ss = f.querySelector('[data-server-settings]'); if (ss && custom) ss.open = true;
  });

  /* QR code for two-step verification setup */
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    if (!window.qrcode) return;
    var q = window.qrcode(0, 'M'); q.addData(el.getAttribute('data-qr')); q.make();
    el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
  });

  /* Responsive tables: wrap for sideways scrolling on tablets, and label every cell
     so phones can show each row as a card (see .t-stack in app.css). */
  document.querySelectorAll('table.t').forEach(function (t) {
    if (!t.parentElement.classList.contains('t-wrap')) {
      var w = document.createElement('div');
      w.className = 't-wrap' + (t.parentElement.matches('main, form') ? ' panel-t' : '');
      t.parentNode.insertBefore(w, t); w.appendChild(t);
    }
    var head = null, labels = [];
    Array.prototype.forEach.call(t.rows, function (tr) {
      if (!head && tr.querySelector('th')) {
        head = tr; tr.classList.add('t-head');
        Array.prototype.forEach.call(tr.cells, function (th) {
          var txt = th.textContent.replace(/\s+/g, ' ').trim();
          for (var i = 0; i < (th.colSpan || 1); i++) labels.push(txt);
        });
        return;
      }
      var col = 0, titled = false, cells = tr.cells;
      Array.prototype.forEach.call(cells, function (td, idx) {
        var label = td.colSpan > 1 ? '' : (labels[col] || '');
        col += td.colSpan || 1;
        if (td.children.length === 1 && td.querySelector(':scope > input[type=checkbox]') && !td.textContent.trim()) { td.classList.add('t-cb'); return; }
        if (!titled && td.colSpan === 1) { td.classList.add('t-title'); titled = true; return; }
        if (label) {
          td.setAttribute('data-label', label);
          var v = document.createElement('div'); v.className = 't-v';
          while (td.firstChild) v.appendChild(td.firstChild);
          td.appendChild(v);
        } else if (idx === cells.length - 1 && td.colSpan === 1) td.classList.add('t-actions');
      });
    });
    if (head) t.classList.add('t-stack');
  });

  /* Live character count for LinkedIn notes */
  document.querySelectorAll('[data-maxchars]').forEach(function (ta) {
    var out = document.getElementById(ta.dataset.counter), max = +ta.dataset.maxchars;
    var upd = function () { if (!out) return; var n = ta.value.length; out.textContent = n + ' / ' + max; out.classList.toggle('over', n > max); };
    ta.addEventListener('input', upd); upd();
  });

  /* Workspace plan presets fill in the allowances */
  var plan = document.querySelector('[data-plan-select]');
  if (plan) plan.addEventListener('change', function () {
    var o = plan.selectedOptions[0], f = plan.form;
    f.quota_leads.value = o.dataset.leads; f.quota_ai_usd.value = o.dataset.ai; f.quota_mailboxes.value = o.dataset.mbx;
  });

  /* Bring an opened reply into view */
  var focus = document.querySelector('.reply-card.focus');
  if (focus) focus.scrollIntoView({ block: 'center' });
})();
