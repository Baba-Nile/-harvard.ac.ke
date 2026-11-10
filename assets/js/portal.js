/* Student portal: login, first-login password change, forgot password, dashboard.
   All authentication happens on the server. This file only talks to /api and renders what the server returns.
   Nothing about the student is stored in the browser (no localStorage, no cookies set from JavaScript). */
(function () {
  'use strict';
  var HX = window.HX, el = HX.el, $ = HX.$, icon = HX.icon;
  var page = document.body.getAttribute('data-page');
  var params = new URLSearchParams(location.search);
  var programme = HX.courseBySlug(params.get('programme') || '') ? params.get('programme') : '';
  var DOWN_MSG = 'The student portal is not reachable right now. Please try again later, or contact admissions on 0705 355 475.';

  function withProgramme(url) { return programme ? url + (url.indexOf('?') > -1 ? '&' : '?') + 'programme=' + encodeURIComponent(programme) : url; }
  function busy(btn, on, label) { btn.disabled = on; if (label) btn.textContent = on ? label : btn.getAttribute('data-label'); }
  function ready(form) { var b = $('button[type=submit]', form); b.setAttribute('data-label', b.textContent); return b; }

  /* ---------- Login ---------- */
  function initLogin() {
    var form = $('#loginForm'), btn = ready(form);
    var ctx = HX.courseBySlug(programme);
    if (ctx) { var box = $('#loginContext'); box.classList.remove('hidden'); $('#ctxName').textContent = ctx.name; $('#ctxDept').textContent = ctx.dept.name; }
    var top = $('#loginNotice');
    if (params.get('expired')) top.appendChild(HX.alert('info', 'Your session ended for security. Sign in again to continue.'));
    if (params.get('loggedout')) top.appendChild(HX.alert('ok', 'You have signed out.'));
    if (params.get('changed')) top.appendChild(HX.alert('ok', 'Your password has been changed. Sign in with your new password.'));
    HX.initPasswordToggles();
    HX.session().then(function (s) {
      if (s.ok && s.data.authenticated) location.replace(s.data.mustChangePassword ? withProgramme('student-password.html') : withProgramme('student-dashboard.html'));
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = HX.validate(form);
      if (v.first) { HX.showStatus(form, 'error', v.msgs.length > 1 ? v.msgs : v.msgs[0], 'Sign in failed'); v.first.focus(); return; }
      busy(btn, true, 'Signing in...');
      HX.post('api/login.php', { fullName: form.elements.fullName.value.trim(), password: form.elements.password.value }).then(function (r) {
        if (r.ok && r.data.ok) {
          form.elements.password.value = '';
          location.href = r.data.mustChangePassword ? withProgramme('student-password.html') : withProgramme('student-dashboard.html');
          return;
        }
        busy(btn, false, 'Signing in...');
        if (HX.unavailable(r)) HX.showStatus(form, 'error', DOWN_MSG, 'Sign in unavailable');
        else if (r.status === 429) HX.showStatus(form, 'error', 'Too many unsuccessful attempts. Wait 15 minutes before trying again, or contact admissions.', 'Sign in paused');
        else HX.showStatus(form, 'error', r.data.message || 'The name or admission number is incorrect. Use your name exactly as it was registered.', 'Sign in failed');
        form.elements.password.value = '';
        form.elements.password.focus();
      });
    });
  }

  /* ---------- Change password ---------- */
  function initPassword() {
    var form = $('#passwordForm'), btn = ready(form), first = $('#firstLoginNote');
    HX.initPasswordToggles();
    HX.session().then(function (s) {
      if (!s.ok) { HX.showStatus(form, 'error', DOWN_MSG, 'Password change unavailable'); btn.disabled = true; return; }
      if (!s.data.authenticated) { location.replace(withProgramme('student-login.html?expired=1')); return; }
      $('#pwName').textContent = s.data.student.fullName;
      if (s.data.mustChangePassword) first.classList.remove('hidden');
      else $('#pwCurrentHint').textContent = 'Enter the password you use to sign in now.';
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = HX.validate(form), cur = form.elements.currentPassword, nw = form.elements.newPassword, cf = form.elements.confirmPassword, msgs = v.msgs.slice(), bad = v.first;
      if (!v.first) {
        if (nw.value.length < 8) { HX.fieldError(nw, 'Use at least 8 characters.'); msgs.push('Use at least 8 characters.'); bad = nw; }
        else if (!/[A-Za-z]/.test(nw.value) || !/[0-9]/.test(nw.value)) { HX.fieldError(nw, 'Include at least one letter and one number.'); msgs.push('Include at least one letter and one number.'); bad = nw; }
        else if (nw.value === cur.value) { HX.fieldError(nw, 'Choose a password different from your current one.'); msgs.push('Choose a password different from your current one.'); bad = nw; }
        else if (nw.value !== cf.value) { HX.fieldError(cf, 'The two new passwords do not match.'); msgs.push('The two new passwords do not match.'); bad = cf; }
      }
      if (bad) { HX.showStatus(form, 'error', msgs.length > 1 ? msgs : msgs[0], 'Password not changed'); bad.focus(); return; }
      busy(btn, true, 'Saving...');
      HX.post('api/change-password.php', { currentPassword: cur.value, newPassword: nw.value, confirmPassword: cf.value }).then(function (r) {
        if (r.ok && r.data.ok) { location.href = withProgramme('student-dashboard.html?pw=1'); return; }
        busy(btn, false, 'Saving...');
        if (r.status === 401) { location.replace(withProgramme('student-login.html?expired=1')); return; }
        if (HX.unavailable(r)) { HX.showStatus(form, 'error', DOWN_MSG, 'Password change unavailable'); return; }
        var errs = r.data.errors || {}, any = false;
        Object.keys(errs).forEach(function (k) { if (form.elements[k]) { HX.fieldError(form.elements[k], errs[k]); any = true; } });
        HX.showStatus(form, 'error', r.data.message || 'Check the highlighted fields and try again.', 'Password not changed');
        if (any) { var f = form.querySelector('[aria-invalid=true]'); if (f) f.focus(); }
      });
    });
  }

  /* ---------- Forgot password ---------- */
  function initForgot() {
    var form = $('#forgotForm'), btn = ready(form);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = HX.validate(form);
      if (v.first) { HX.showStatus(form, 'error', v.msgs.length > 1 ? v.msgs : v.msgs[0], 'Request not sent'); v.first.focus(); return; }
      busy(btn, true, 'Sending...');
      HX.post('api/forgot-password.php', { fullName: form.elements.fullName.value.trim(), admissionNo: form.elements.admissionNo.value.trim(), contact: form.elements.contact.value.trim() }).then(function (r) {
        busy(btn, false, 'Sending...');
        if (r.ok && r.data.ok) {
          form.reset();
          HX.showStatus(form, 'ok', 'If the details match a student record, admissions will verify your identity and reset your access. After a reset, your admission number becomes your password again and you will be asked to create a new one.', 'Request received');
        } else if (r.status === 429) HX.showStatus(form, 'error', 'Too many requests from this connection. Try again later, or call 0705 355 475.', 'Please try again later');
        else HX.showStatus(form, 'error', HX.unavailable(r) ? DOWN_MSG : (r.data.message || 'The request could not be sent.'), 'Request not sent');
      });
    });
  }

  /* ---------- Dashboard ---------- */
  function fmtDate(s) {
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? '' : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
  }
  function fmtSize(b) { if (!b) return ''; return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function safeUrl(u) { return /^https:\/\//i.test(u || '') ? u : null; }

  function materialItem(m) {
    var li = el('li');
    if (m.kind === 'file') {
      li.appendChild(icon('download'));
      li.appendChild(el('div', {}, [el('a', { href: 'api/download.php?id=' + encodeURIComponent(m.id), text: m.title }), el('small', { text: [m.fileName, fmtSize(m.size)].filter(Boolean).join(', ') })]));
    } else {
      var u = safeUrl(m.url);
      li.appendChild(icon(m.kind === 'video' ? 'play' : 'link'));
      if (!u) li.appendChild(el('div', {}, [el('span', { text: m.title }), el('small', { text: 'Link unavailable. Contact your instructor.' })]));
      else li.appendChild(el('div', {}, [el('a', { href: u, target: '_blank', rel: 'noopener noreferrer', text: m.title }), el('small', { text: m.kind === 'video' ? 'Video lesson, opens in a new tab' : 'Opens in a new tab' })]));
    }
    return li;
  }

  function programmePanel(p) {
    var wrap = el('div', { id: 'prog-' + p.slug, role: 'tabpanel' });
    wrap.appendChild(el('h2', { text: p.name }));
    wrap.appendChild(el('p', { class: 'muted', text: p.department }));
    wrap.appendChild(el('h3', { text: 'Lessons and materials' }));
    if (!p.lessons.length) wrap.appendChild(el('div', { class: 'empty' }, [el('strong', { text: 'No lessons published yet' }), 'Your instructor has not added learning materials for this programme. Check announcements or contact admissions.']));
    p.lessons.forEach(function (l, i) {
      var d = el('details', { class: 'lesson' });
      if (i === 0) d.setAttribute('open', '');
      d.appendChild(el('summary', { text: l.title }));
      var body = el('div', { class: 'lesson-body' });
      if (l.description) body.appendChild(el('p', { text: l.description }));
      if (l.materials.length) body.appendChild(el('ul', { class: 'materials' }, l.materials.map(materialItem)));
      else body.appendChild(el('p', { class: 'muted', text: 'No materials have been added to this lesson yet.' }));
      d.appendChild(body); wrap.appendChild(d);
    });
    wrap.appendChild(el('h3', { text: 'Assignments' }));
    if (!p.assignments.length) wrap.appendChild(el('p', { class: 'muted', text: 'No assignments have been set for this programme.' }));
    p.assignments.forEach(function (a) {
      var box = el('div', { class: 'assign' }, [el('h4', { text: a.title })]);
      if (a.dueAt) box.appendChild(el('p', {}, [el('strong', { text: 'Due: ' }), fmtDate(a.dueAt)]));
      if (a.instructions) box.appendChild(el('p', { text: a.instructions }));
      wrap.appendChild(box);
    });
    return wrap;
  }

  function renderDashboard(d) {
    $('#dashLoading').remove();
    $('#dashName').textContent = d.student.fullName;
    $('#dashAdm').textContent = d.student.admissionNo;
    $('#dashIdle').textContent = d.idleMinutes;
    if (params.get('pw')) $('#dashFlash').appendChild(HX.alert('ok', 'Your new password is saved. Use it the next time you sign in.'));
    var main = $('#dashMain');
    var progs = d.programmes;
    if (!progs.length) {
      main.appendChild(el('div', { class: 'empty' }, [el('strong', { text: 'You are not enrolled in any online programme yet' }), 'Admissions enrol students into programmes that are open for online learning. Contact admissions to confirm your enrolment.']));
    } else {
      var sel = programme && progs.some(function (p) { return p.slug === programme; }) ? programme : progs[0].slug;
      if (programme && sel !== programme) main.appendChild(HX.alert('info', 'You are not enrolled in ' + HX.courseBySlug(programme).name + ' online. Contact admissions to confirm your enrolment.'));
      var panels = progs.map(programmePanel);
      if (progs.length > 1) {
        var tabs = el('div', { class: 'prog-tabs', role: 'tablist', 'aria-label': 'Your programmes' });
        progs.forEach(function (p, i) {
          var b = el('button', { type: 'button', role: 'tab', 'aria-controls': 'prog-' + p.slug, id: 'tab-' + p.slug, text: p.name });
          b.addEventListener('click', function () { show(i); });
          tabs.appendChild(b);
        });
        main.appendChild(tabs);
      }
      panels.forEach(function (p) { main.appendChild(p); });
      var show = function (i) {
        panels.forEach(function (p, j) { p.classList.toggle('hidden', i !== j); });
        $('.prog-tabs', main) && Array.prototype.forEach.call($('.prog-tabs', main).children, function (b, j) { b.setAttribute('aria-selected', i === j ? 'true' : 'false'); });
      };
      show(progs.map(function (p) { return p.slug; }).indexOf(sel));
    }
    var an = $('#dashNews');
    if (!d.announcements.length) an.appendChild(el('p', { class: 'muted', text: 'There are no announcements at the moment.' }));
    d.announcements.forEach(function (a) {
      an.appendChild(el('div', { class: 'news-item' }, [el('time', { text: fmtDate(a.postedAt) }), el('h3', { text: a.title }), el('p', { text: a.body })]));
    });
    $('#dashBody').classList.remove('hidden');
  }

  function initDashboard() {
    var out = $('#logoutBtn');
    out.addEventListener('click', function () {
      out.disabled = true;
      HX.post('api/logout.php', {}).then(function () { location.href = 'student-login.html?loggedout=1'; });
    });
    HX.json('api/dashboard.php').then(function (r) {
      if (r.status === 401) { location.replace(withProgramme('student-login.html?expired=1')); return; }
      if (r.status === 403 && r.data.code === 'password_change_required') { location.replace(withProgramme('student-password.html')); return; }
      if (!r.ok || !r.data.student) {
        $('#dashLoading').textContent = '';
        $('#dashLoading').appendChild(HX.alert('error', HX.unavailable(r) ? DOWN_MSG : 'Your dashboard could not be loaded. Try again shortly.', 'Dashboard unavailable'));
        return;
      }
      renderDashboard(r.data);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (page === 'student-login') initLogin();
    else if (page === 'student-password') initPassword();
    else if (page === 'student-forgot') initForgot();
    else if (page === 'student-dashboard') initDashboard();
  });
})();
