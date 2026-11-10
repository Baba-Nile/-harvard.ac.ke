/* Harvard Extention Training College - shared behaviour
   Navigation, enquiry forms, department pages, API helper. No dependencies. */
(function () {
  'use strict';
  var HX = window.HX = window.HX || {};
  var DEPTS = window.HX_DEPTS || [];
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function icon(name, cls) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('class', 'icon' + (cls ? ' ' + cls : ''));
    s.setAttribute('aria-hidden', 'true');
    var u = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    u.setAttribute('href', 'assets/img/icons.svg#i-' + name);
    s.appendChild(u);
    return s;
  }
  HX.el = el; HX.icon = icon; HX.$ = $; HX.$$ = $$;
  HX.deptByKey = function (k) { return DEPTS.filter(function (d) { return d.key === k; })[0]; };
  HX.courseBySlug = function (s) {
    for (var i = 0; i < DEPTS.length; i++) for (var j = 0; j < DEPTS[i].courses.length; j++)
      if (DEPTS[i].courses[j].s === s) return { name: DEPTS[i].courses[j].n, slug: s, dept: DEPTS[i] };
    return null;
  };

  /* API helper. Same-origin only. POSTs fetch a fresh CSRF token from the server session first. */
  HX.json = function (url, opt) {
    opt = opt || {};
    var h = { Accept: 'application/json' };
    if (opt.body) h['Content-Type'] = 'application/json';
    if (opt.csrf) h['X-CSRF-Token'] = opt.csrf;
    return fetch(url, { method: opt.method || 'GET', headers: h, credentials: 'same-origin', cache: 'no-store',
      body: opt.body ? JSON.stringify(opt.body) : undefined })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, ok: r.ok, data: d || {} }; });
      })
      .catch(function () { return { status: 0, ok: false, data: {} }; });
  };
  HX.session = function () { return HX.json('api/session.php'); };
  HX.post = function (url, body) {
    return HX.session().then(function (s) {
      if (!s.ok) return s;
      return HX.json(url, { method: 'POST', body: body, csrf: s.data.csrf });
    });
  };
  HX.unavailable = function (r) { return r.status === 0 || r.status === 404 || r.status >= 500; };
  /* Courses the administration has opened for online learning: {slug: summary} or null when the service is unreachable */
  var availability;
  HX.availability = function () {
    if (!availability) availability = HX.json('api/programmes.php').then(function (r) {
      if (!r.ok || !Array.isArray(r.data.programmes)) return null;
      var m = {}; r.data.programmes.forEach(function (p) { m[p.slug] = p.summary || ''; }); return m;
    });
    return availability;
  };

  /* Mobile navigation */
  function initNav() {
    var nav = $('#mainNav'), btn = $('#navToggle'), menu = $('#navMenu');
    if (!nav || !btn) return;
    function set(open) {
      nav.classList.toggle('nav-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
    btn.addEventListener('click', function () { set(!nav.classList.contains('nav-open')); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('nav-open')) { set(false); btn.focus(); }
    });
    document.addEventListener('click', function (e) {
      if (nav.classList.contains('nav-open') && !nav.contains(e.target)) set(false);
    });
    menu.addEventListener('click', function (e) { if (e.target.closest('a')) set(false); });
    window.matchMedia('(min-width:1180px)').addEventListener('change', function (m) { if (m.matches) set(false); });
  }

  /* Password show/hide (used on all portal forms) */
  HX.initPasswordToggles = function () {
    $$('.pw').forEach(function (box) {
      var b = $('button', box), i = $('input', box);
      if (!b || !i) return;
      b.addEventListener('click', function () {
        var show = i.type === 'password';
        i.type = show ? 'text' : 'password';
        box.classList.toggle('show', show);
        b.setAttribute('aria-pressed', show ? 'true' : 'false');
        b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      });
    });
  };

  /* Form helpers shared with the portal */
  HX.clearErrors = function (form) {
    $$('.field-error', form).forEach(function (n) { n.remove(); });
    $$('[aria-invalid]', form).forEach(function (n) { n.removeAttribute('aria-invalid'); n.removeAttribute('aria-describedby'); });
  };
  HX.fieldError = function (input, msg) {
    var id = (input.id || input.name) + '-error';
    var holder = input.closest('.pw') || input;
    var p = el('p', { class: 'field-error', id: id, text: msg });
    holder.insertAdjacentElement('afterend', p);
    input.setAttribute('aria-invalid', 'true');
    input.setAttribute('aria-describedby', id);
  };
  HX.alert = function (kind, msgs, title) {
    var list = Array.isArray(msgs) ? msgs : [msgs];
    var body = el('div', {}, [title ? el('p', {}, [el('strong', { text: title })]) : null]);
    if (list.length === 1) body.appendChild(el('p', { text: list[0] }));
    else body.appendChild(el('ul', {}, list.map(function (m) { return el('li', { text: m }); })));
    return el('div', { class: 'alert alert-' + kind }, [icon(kind === 'ok' ? 'check' : kind === 'error' ? 'alert' : 'info'), body]);
  };
  HX.showStatus = function (form, kind, msgs, title) {
    var box = $('.form-status', form);
    if (!box) return;
    box.textContent = '';
    box.appendChild(HX.alert(kind, msgs, title));
    box.focus();
  };
  HX.validate = function (form) {
    HX.clearErrors(form);
    var first = null, msgs = [];
    $$('input[name],select[name],textarea[name]', form).forEach(function (f) {
      if (f.type === 'hidden' || f.closest('.trap')) return;
      var v = f.value.trim(), label = f.id && $('label[for="' + f.id + '"]'), name = label ? label.textContent.trim() : f.name, err = '';
      if (f.required && !v) err = f.getAttribute('data-required') || ('Enter your ' + name.toLowerCase() + '.');
      else if (v && f.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) err = 'Enter a valid email address, for example name@example.com.';
      else if (v && f.type === 'tel' && !/^\+?[0-9\s-]{9,16}$/.test(v)) err = 'Enter a valid phone number, for example 0705 355 475.';
      if (f.tagName === 'SELECT' && f.required && !v) err = f.getAttribute('data-required') || ('Choose a ' + name.toLowerCase() + '.');
      if (err) { HX.fieldError(f, err); msgs.push(err); first = first || f; }
    });
    return { first: first, msgs: msgs };
  };

  /* Enquiry and application forms (post to the PHP service; fall back to phone and WhatsApp) */
  function initEnquiryForms() {
    $$('form[data-endpoint]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var v = HX.validate(form);
        if (v.first) {
          HX.showStatus(form, 'error', v.msgs.length > 1 ? v.msgs : v.msgs[0], 'Check the highlighted fields');
          v.first.focus();
          return;
        }
        var btn = $('button[type=submit]', form), label = btn.textContent;
        btn.disabled = true; btn.textContent = 'Sending...';
        var data = { type: form.getAttribute('data-type') };
        $$('input[name],select[name],textarea[name]', form).forEach(function (f) { data[f.name] = f.value.trim(); });
        HX.json(form.getAttribute('data-endpoint'), { method: 'POST', body: data }).then(function (r) {
          btn.disabled = false; btn.textContent = label;
          if (r.ok) {
            form.reset();
            HX.showStatus(form, 'ok', 'Admissions will reply using the phone number or email address you provided.', 'Your message has been sent');
          } else if (r.status === 422 && r.data.errors) {
            Object.keys(r.data.errors).forEach(function (k) { var f = form.elements[k]; if (f) HX.fieldError(f, r.data.errors[k]); });
            HX.showStatus(form, 'error', 'Check the highlighted fields and send again.', 'Your message was not sent');
          } else if (r.status === 429) {
            HX.showStatus(form, 'error', 'Too many messages were sent from this connection. Wait a few minutes, or call 0705 355 475.', 'Please try again later');
          } else {
            HX.showStatus(form, 'error', 'The message could not be sent from this page. Call 0705 355 475, message admissions on WhatsApp, or email harvardextentioncollege@gmail.com.', 'Your message was not sent');
          }
        });
      });
    });
  }

  /* Application form: department and course selects */
  function initApplicationSelects() {
    var d = $('#appDept'), c = $('#appCourse');
    if (!d || !c || !DEPTS.length) return;
    d.appendChild(el('option', { value: '', text: 'Choose a department' }));
    DEPTS.forEach(function (x) { d.appendChild(el('option', { value: x.name, 'data-key': x.key, text: x.name })); });
    function fill() {
      c.textContent = '';
      c.appendChild(el('option', { value: '', text: d.value ? 'Choose a course' : 'Choose a department first' }));
      var k = d.selectedOptions[0] && d.selectedOptions[0].getAttribute('data-key'), dep = HX.deptByKey(k);
      if (dep) dep.courses.forEach(function (x) { c.appendChild(el('option', { value: x.n, text: x.n })); });
      c.disabled = !dep;
    }
    d.addEventListener('change', fill); fill();
  }

  /* Departments page: search and department filter */
  function initDepartments() {
    var box = $('#deptResults'); if (!box) return;
    var q = $('#deptSearch'), sel = $('#deptFilter'), count = $('#deptCount'), enabled = null;
    DEPTS.forEach(function (d) { sel.appendChild(el('option', { value: d.key, text: d.name })); });
    var pre = new URLSearchParams(location.search).get('dept'); if (pre && HX.deptByKey(pre)) sel.value = pre;
    function render() {
      var term = q.value.trim().toLowerCase(), key = sel.value, shown = 0, courses = 0;
      box.textContent = '';
      DEPTS.forEach(function (d) {
        if (key && key !== d.key) return;
        var list = d.courses.filter(function (c) { return !term || c.n.toLowerCase().indexOf(term) > -1 || d.name.toLowerCase().indexOf(term) > -1; });
        if (!list.length) return;
        shown++; courses += list.length;
        var h = el('h2', {}, [el('a', { href: 'department.html?dept=' + d.key, text: d.name }), el('span', { text: d.courses.length + ' courses' })]);
        var ul = el('ul', { class: 'course-rows' });
        list.forEach(function (c) {
          var li = el('li', {}, [el('span', { text: c.n })]);
          var right = el('span', { class: 'cluster' });
          if (enabled && Object.prototype.hasOwnProperty.call(enabled, c.s)) right.appendChild(el('a', { class: 'tag', href: 'online-classes.html?q=' + encodeURIComponent(c.n) + '#programmes', text: 'Online learning available' }));
          li.appendChild(right); ul.appendChild(li);
        });
        box.appendChild(el('section', { class: 'dept-block' }, [h, ul]));
      });
      if (!shown) box.appendChild(el('div', { class: 'empty' }, [el('strong', { text: 'No courses match your search' }), 'Try a shorter search term or choose all departments.']));
      count.textContent = shown ? courses + (courses === 1 ? ' course' : ' courses') + ' in ' + shown + (shown === 1 ? ' department' : ' departments') : '';
    }
    q.addEventListener('input', render); sel.addEventListener('change', render);
    $('#deptForm').addEventListener('submit', function (e) { e.preventDefault(); render(); });
    render();
    HX.availability().then(function (m) { enabled = m; render(); });
  }

  /* Single department page (department.html?dept=key) */
  function initDepartmentPage() {
    var box = $('#deptCourses'); if (!box) return;
    var key = new URLSearchParams(location.search).get('dept'), d = HX.deptByKey(key);
    var others = $('#otherDepts');
    if (!d) {
      $('#deptTitle').textContent = 'Department not found';
      $('#crumbName').textContent = 'Not found';
      $('#deptLead').textContent = 'Choose one of the seven departments below.';
      box.remove(); $('#deptNote').remove();
      DEPTS.forEach(function (x) { others.appendChild(el('li', {}, [el('a', { href: 'department.html?dept=' + x.key, text: x.name })])); });
      return;
    }
    document.title = d.name + ' | Harvard Extention Training College';
    $('#crumbName').textContent = d.name; $('#deptTitle').textContent = d.name;
    $('#deptLead').textContent = d.courses.length + ' courses in the ' + d.name + ' department.';
    var enabled = null;
    function render() {
      box.textContent = '';
      d.courses.forEach(function (c) {
        var li = el('li', {}, [el('span', { text: c.n })]);
        var right = el('span', { class: 'cluster' });
        if (enabled && Object.prototype.hasOwnProperty.call(enabled, c.s)) right.appendChild(el('a', { class: 'tag', href: 'student-login.html?programme=' + c.s, text: 'Online learning available' }));
        right.appendChild(el('a', { href: 'admissions.html#application', text: 'Enquire' }));
        li.appendChild(right); box.appendChild(li);
      });
    }
    render();
    DEPTS.forEach(function (x) { if (x.key !== d.key) others.appendChild(el('li', {}, [el('a', { href: 'department.html?dept=' + x.key, text: x.name })])); });
    HX.availability().then(function (m) { enabled = m; render(); });
  }

  /* Missing photographs: keep the layout intact */
  function initImages() {
    $$('img').forEach(function (img) {
      img.addEventListener('error', function () { img.classList.add('img-missing'); img.removeAttribute('alt'); img.setAttribute('role', 'presentation'); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initNav(); initEnquiryForms(); initApplicationSelects(); initDepartments(); initDepartmentPage(); initImages();
    $$('[data-year]').forEach(function (n) { n.textContent = new Date().getFullYear(); });
  });
})();
