/* Online Classes page: browse, search and filter the college's programmes.
   Availability comes from the server (programmes the administration has enabled). */
(function () {
  'use strict';
  var HX = window.HX, el = HX.el, $ = HX.$, DEPTS = window.HX_DEPTS || [];
  var list = $('#progList'); if (!list) return;
  var q = $('#progSearch'), dept = $('#progDept'), only = $('#progOnly'), count = $('#progCount'), notice = $('#progNotice');
  var enabled = null, loaded = false;

  var all = [];
  DEPTS.forEach(function (d) { d.courses.forEach(function (c) { all.push({ name: c.n, slug: c.s, dept: d }); }); });
  DEPTS.forEach(function (d) { dept.appendChild(el('option', { value: d.key, text: d.name })); });

  var params = new URLSearchParams(location.search);
  if (params.get('dept') && HX.deptByKey(params.get('dept'))) dept.value = params.get('dept');
  if (params.get('q')) q.value = params.get('q').slice(0, 80);

  function isOn(slug) { return !!enabled && Object.prototype.hasOwnProperty.call(enabled, slug); }

  function row(p) {
    var on = isOn(p.slug);
    var status = on ? 'Online learning is available for this programme.' : 'Online learning availability: Contact admissions.';
    var info = el('div', { class: 'prog-info' }, [
      el('p', { class: 'prog-name', text: p.name }),
      el('p', { class: 'prog-dept', text: p.dept.name }),
      el('p', { class: 'prog-status', text: status })
    ]);
    var action = on
      ? el('a', { class: 'btn btn-primary btn-sm', href: 'student-login.html?programme=' + encodeURIComponent(p.slug), 'aria-label': 'Sign in to study ' + p.name, text: 'Sign in to study' })
      : el('a', { class: 'btn btn-outline btn-sm', href: 'contact.html', 'aria-label': 'Ask admissions about online learning for ' + p.name, text: 'Ask admissions' });
    var summary = on && enabled[p.slug] ? el('p', { text: enabled[p.slug] }) : null;
    var more = el('details', {}, [
      el('summary', { text: 'Programme information' }),
      el('div', {}, [
        el('p', {}, [el('strong', { text: 'Department: ' }), p.dept.name]),
        summary,
        el('p', { text: on ? 'Students enrolled in this programme sign in with their registered name and admission number to reach their notes, lessons and announcements.' : 'Online learning has not been confirmed for this programme. Admissions can tell you whether it is offered online.' }),
        el('p', { text: 'Duration, fees and certification: contact admissions on 0705 355 475.' })
      ])
    ]);
    return el('li', { class: 'prog' }, [el('div', { class: 'prog-row' }, [info, el('div', {}, [action])]), more]);
  }

  function render() {
    var term = q.value.trim().toLowerCase(), key = dept.value, on = only.checked;
    var rows = all.filter(function (p) {
      return (!key || p.dept.key === key) && (!term || p.name.toLowerCase().indexOf(term) > -1) && (!on || isOn(p.slug));
    });
    list.textContent = '';
    rows.forEach(function (p) { list.appendChild(row(p)); });
    $('#progEmpty').classList.toggle('hidden', rows.length > 0);
    list.classList.toggle('hidden', rows.length === 0);
    if (!rows.length) {
      $('#progEmptyText').textContent = on && loaded && (!enabled || !Object.keys(enabled).length)
        ? 'No programmes have been opened for online learning yet. Contact admissions to ask about availability.'
        : 'No programmes match your search. Try a shorter term or choose all departments.';
    }
    count.textContent = rows.length + (rows.length === 1 ? ' programme' : ' programmes') + ' shown';
  }

  function setNotice() {
    var msg = '', kind = 'info';
    if (enabled === null) msg = 'Online learning availability is confirmed by admissions. Contact admissions to ask which programmes are open online.';
    else if (!Object.keys(enabled).length) msg = 'No programmes have been opened for online learning yet. Contact admissions to ask about availability.';
    notice.textContent = '';
    if (msg) { notice.appendChild(HX.alert(kind, msg)); notice.classList.remove('hidden'); } else notice.classList.add('hidden');
    only.disabled = enabled === null || !Object.keys(enabled).length;
  }

  /* Text search re-renders on typing only. A 'change' event on blur would replace the list under the user's cursor and swallow the click. */
  q.addEventListener('input', render);
  dept.addEventListener('change', render);
  only.addEventListener('change', render);
  $('#progForm').addEventListener('submit', function (e) { e.preventDefault(); render(); });
  render();
  HX.availability().then(function (m) { enabled = m; loaded = true; setNotice(); render(); });
})();
