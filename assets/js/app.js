/* ============================================================
   collab.dev — front-end application
   Auth-gated developer directory: search, browse, follow.
   ============================================================ */
(function () {
  'use strict';

  /* ---------------- Utilities ---------------- */

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function initials(name) {
    const parts = String(name || '?').trim().split(/\s+/).slice(0, 2);
    return parts.map((p) => p[0]).join('').toUpperCase() || '?';
  }

  function fmtNum(n) {
    const num = Number(n) || 0;
    if (num >= 1000000) return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
    return String(num);
  }

  function timeAgo(value) {
    if (!value) return '';
    const then = new Date(String(value).replace(' ', 'T'));
    if (isNaN(then)) return '';
    const secs = Math.floor((Date.now() - then.getTime()) / 1000);
    if (secs < 60) return 'just now';
    const mins = Math.floor(secs / 60);
    if (mins < 60) return mins + 'm ago';
    const hours = Math.floor(mins / 60);
    if (hours < 24) return hours + 'h ago';
    const days = Math.floor(hours / 24);
    if (days < 30) return days + 'd ago';
    return then.toLocaleDateString();
  }

  function debounce(fn, wait) {
    let timer;
    function debounced() {
      const args = arguments;
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), wait);
    }
    debounced.cancel = () => clearTimeout(timer);
    return debounced;
  }

  function langColor(lang) {
    if (!lang) return '#8a8e98';
    let hash = 0;
    for (let i = 0; i < lang.length; i++) hash = (hash * 31 + lang.charCodeAt(i)) >>> 0;
    return 'hsl(' + (hash % 360) + ' 30% 52%)';
  }

  /* ---------------- Icons ---------------- */

  const icon = (name) =>
    '<svg class="icon" aria-hidden="true"><use href="assets/icons.svg#icon-' + name + '"></use></svg>';

  const ICON = {
    search: icon('search'),
    github: icon('github'),
    star: icon('star'),
    fork: icon('fork'),
    location: icon('location'),
    briefcase: icon('briefcase'),
    link: icon('link'),
    mail: icon('mail'),
    send: icon('send'),
    plus: icon('plus'),
    x: icon('x'),
    sun: icon('sun'),
    moon: icon('moon'),
    compare: icon('compare'),
    back: icon('back'),
    external: icon('external'),
    sparkles: icon('sparkles'),
    refresh: icon('refresh'),
    logout: icon('logout'),
    user: icon('user'),
    share: icon('share'),
    commit: icon('commit'),
    settings: icon('settings'),
    slash: icon('slash'),
    shield: icon('shield'),
    edit: icon('edit'),
    chevron: icon('chevron'),
  };

  /* ---------------- Toast ---------------- */

  function toast(message, type = 'info') {
    const root = $('#toast-root');
    const node = document.createElement('div');
    node.className = 'toast ' + type;
    node.innerHTML = '<span class="dot"></span><span>' + escapeHtml(message) + '</span>';
    root.appendChild(node);
    setTimeout(() => {
      node.style.transition = 'opacity .25s, transform .25s';
      node.style.opacity = '0';
      node.style.transform = 'translateY(8px)';
      setTimeout(() => node.remove(), 280);
    }, 3400);
  }

  /* ---------------- State ---------------- */

  const state = { user: null, profiles: [], convos: [], facets: null };
  let profileMenuBound = false;
  const isLoggedIn = () => !!state.user;

  /* ---------------- Avatars ---------------- */

  function displayNameOf(dev) {
    return dev.displayName || dev.displayname || dev.name || dev.login || '?';
  }

  function avatarUrlOf(dev) {
    return dev.avatarUrl || dev.avatar || dev.avatar_url || '';
  }

  function avatarHtml(dev, size = '') {
    const name = displayNameOf(dev);
    const url = avatarUrlOf(dev);
    if (url && /^https?:\/\//i.test(url)) {
      return '<span class="avatar ' + size + '" data-initials="' + escapeHtml(initials(name)) + '">' +
        '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(name) + '" loading="lazy"></span>';
    }
    return '<span class="avatar ' + size + '">' + escapeHtml(initials(name)) + '</span>';
  }

  document.addEventListener('error', (e) => {
    const target = e.target;
    if (target && target.tagName === 'IMG' && target.parentNode &&
        target.parentNode.classList.contains('avatar')) {
      const span = target.parentNode;
      span.textContent = span.getAttribute('data-initials') || '?';
    }
  }, true);

  function uniqueSharedLanguages(skills, languages) {
    const names = new Set((skills || []).map((s) => String(s).toLowerCase()));
    return (languages || []).filter((l) => !names.has(String(l).toLowerCase()));
  }

  function skillChips(skills, limit) {
    const list = (skills || []).slice(0, limit || 30);
    if (!list.length) return '<span class="empty-inline">none listed.</span>';
    return list.map((s) =>
      '<span class="chip static" title="' + escapeHtml(s.proficiency || '') + '">' +
      '<span class="dot" style="background:' + langColor(s.name) + '"></span>' +
      escapeHtml(s.name) + proficiencyMeter(s.proficiency) + '</span>'
    ).join('');
  }

  function statCell(label, value) {
    return '<div class="stat-cell"><div class="stat-value">' + escapeHtml(value) +
      '</div><div class="stat-label">' + escapeHtml(label) + '</div></div>';
  }

  function langBarHtml(languages) {
    const total = languages.reduce((sum, l) => sum + l.repos, 0);
    if (!total) return '';
    return '<div class="langbar">' + languages.map((l) =>
      '<span style="width:' + (l.repos / total) * 100 + '%;background:' + langColor(l.language) + '"></span>'
    ).join('') + '</div>';
  }

  function langLegend(languages) {
    return '<div class="legend">' + languages.map((l) =>
      '<div class="legend-item"><span class="dot" style="background:' + langColor(l.language) + '"></span>' +
      '<span class="lname">' + escapeHtml(l.language) + '</span>' +
      '<span class="lcount">' + l.repos + ' repos</span></div>'
    ).join('') + '</div>';
  }

  function activityHeatmap(repos) {
    const days = new Array(84).fill(0);
    (repos || []).forEach((r) => {
      let data = r.daily_commits;
      if (typeof data === 'string') {
        try { data = JSON.parse(data); } catch (e) { data = []; }
      }
      (Array.isArray(data) ? data : []).map(Number).slice(-84).forEach((v, i) => {
        days[i] += v || 0;
      });
    });
    const total = days.reduce((a, b) => a + b, 0);
    const level = (v) => {
      if (v <= 0) return 0;
      if (v === 1) return 1;
      if (v <= 3) return 2;
      if (v <= 5) return 3;
      return 4;
    };
    const today = new Date();
    const cells = days.map((v, i) => {
      const day = new Date(today);
      day.setDate(today.getDate() - (days.length - 1 - i));
      const label = day.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      return '<span class="cell l' + level(v) + '" data-count="' + v +
        '" data-date="' + escapeHtml(label) + '"></span>';
    }).join('');
    const legend = [0, 1, 2, 3, 4].map((l) =>
      '<span class="cell l' + l + '"></span>').join('');
    return '<div class="heatmap-wrap"><div class="heatmap">' + cells + '</div>' +
      '<div class="heatmap-tip"></div></div>' +
      '<div class="heatmap-meta">' +
      '<span>' + total + ' commits · last 12 weeks</span>' +
      '<span class="heatmap-legend"><span class="faint">less</span>' + legend +
      '<span class="faint">more</span></span>' +
      '</div>';
  }

  function wireHeatmap(root) {
    const wrap = root.querySelector('.heatmap-wrap');
    if (!wrap) return;
    const tip = wrap.querySelector('.heatmap-tip');

    wrap.addEventListener('mousemove', (e) => {
      const cell = e.target.closest('.cell');
      if (!cell) {
        tip.classList.remove('show');
        return;
      }
      const count = Number(cell.dataset.count) || 0;
      const date = cell.dataset.date || '';
      tip.textContent = count + ' commit' + (count === 1 ? '' : 's') + (date ? ' · ' + date : '');
      const rect = wrap.getBoundingClientRect();
      tip.style.left = (e.clientX - rect.left) + 'px';
      tip.style.top = (e.clientY - rect.top) + 'px';
      tip.classList.add('show');
    });
    wrap.addEventListener('mouseleave', () => tip.classList.remove('show'));
  }

  function repoDailyCommits(repo) {
    let data = repo.daily_commits;
    if (typeof data === 'string') {
      try { data = JSON.parse(data); } catch (e) { data = []; }
    }
    return (Array.isArray(data) ? data : []).map(Number);
  }

  function repoCommitTotal(repo) {
    const fromDaily = repoDailyCommits(repo).reduce((sum, v) => sum + (v || 0), 0);
    return fromDaily || Number(repo.commits_30d) || 0;
  }

  function repoCommitChart(repos) {
    const data = (repos || [])
      .map((repo) => ({ name: repo.name, commits: repoCommitTotal(repo) }))
      .filter((entry) => entry.commits > 0)
      .sort((a, b) => b.commits - a.commits);

    const total = data.reduce((sum, entry) => sum + entry.commits, 0);
    if (!total) {
      return '<div class="empty-inline">no commit activity to chart yet.</div>';
    }

    const radius = 15.915;
    let accumulated = 0;
    const slices = data.map((entry) => {
      const pct = (entry.commits / total) * 100;
      const slice =
        '<circle class="donut-slice" cx="21" cy="21" r="' + radius + '" fill="none" stroke-width="6"' +
        ' stroke="' + langColor(entry.name) + '"' +
        ' stroke-dasharray="' + pct.toFixed(3) + ' ' + (100 - pct).toFixed(3) + '"' +
        ' stroke-dashoffset="' + (25 - accumulated).toFixed(3) + '">' +
        '<title>' + escapeHtml(entry.name) + ': ' + entry.commits + ' commits</title></circle>';
      accumulated += pct;
      return slice;
    }).join('');

    const legend = data.map((entry) =>
      '<div class="legend-item"><span class="dot" style="background:' + langColor(entry.name) + '"></span>' +
      '<span class="lname ellipsis">' + escapeHtml(entry.name) + '</span>' +
      '<span class="lcount">' + entry.commits + '</span></div>').join('');

    return '<div class="donut-layout">' +
      '<div class="donut">' +
      '<svg viewBox="0 0 42 42" class="donut-svg" role="img" aria-label="commits by repository">' +
      '<circle cx="21" cy="21" r="' + radius + '" fill="none" stroke-width="6" class="donut-track"></circle>' +
      slices +
      '<text x="21" y="20.6" class="donut-total">' + escapeHtml(String(total)) + '</text>' +
      '<text x="21" y="25.4" class="donut-label">commits</text>' +
      '</svg>' +
      '</div>' +
      '<div class="donut-legend legend">' + legend + '</div>' +
      '</div>';
  }

  function activityCharts(repos) {
    return '<div class="activity-grid">' +
      '<div class="panel activity-card"><h3 class="section-title">weekly activity</h3>' +
      activityHeatmap(repos) + '</div>' +
      '<div class="panel activity-card"><h3 class="section-title">commits by repository</h3>' +
      repoCommitChart(repos) + '</div>' +
      '</div>';
  }

  function proficiencyMeter(proficiency) {
    const level = { beginner: 1, intermediate: 2, advanced: 3, expert: 4 }[String(proficiency || '').toLowerCase()] || 0;
    if (!level) return '';
    let segs = '';
    for (let i = 1; i <= 4; i++) segs += '<span class="seg' + (i <= level ? ' on' : '') + '"></span>';
    return '<span class="prof-meter">' + segs + '</span>';
  }

  function repoRow(repo) {
    const stars = repo.stars != null ? repo.stars : 0;
    const forks = repo.forks != null ? repo.forks : 0;
    const lang = repo.language || '';
    const commits = repo.commits_30d != null ? Number(repo.commits_30d) : 0;
    return '<a class="repo-row" href="' + escapeHtml(repo.url || '#') + '" target="_blank" rel="noopener">' +
      '<div class="repo-main">' +
      '<div class="repo-top"><span class="repo-name-text">' + escapeHtml(repo.name) + '</span>' +
      (Number(repo.is_featured) ? ' <span class="badge accent">featured</span>' : '') + '</div>' +
      (repo.description ? '<div class="repo-desc">' + escapeHtml(repo.description) + '</div>' : '') +
      '<div class="repo-foot">' +
      (lang ? '<span class="item"><span class="lang-dot" style="background:' + langColor(lang) + '"></span>' + escapeHtml(lang) + '</span>' : '') +
      '<span class="item">' + ICON.star + escapeHtml(fmtNum(stars)) + '</span>' +
      '<span class="item">' + ICON.fork + escapeHtml(fmtNum(forks)) + '</span>' +
      '<span class="item">' + ICON.commit + escapeHtml(commits) + ' <span class="faint">30d</span></span>' +
      '</div></div>' +
      '<span class="repo-spark" title="commits per week, last 12 weeks" aria-label="weekly commit activity">' +
      sparkline(repo.weekly_commits) + '</span></a>';
  }

  function sparkline(weekly) {
    let data = weekly;
    if (typeof data === 'string') {
      try { data = JSON.parse(data); } catch (e) { data = []; }
    }
    data = (Array.isArray(data) ? data : []).map(Number).slice(-12);
    if (!data.length) return '';
    const max = Math.max.apply(null, data) || 1;
    const W = 76, H = 18, step = W / data.length, bw = Math.max(2, step - 2);
    const bars = data.map((v, i) => {
      const h = v > 0 ? Math.max(2, Math.round((v / max) * H)) : 0;
      return '<rect x="' + (i * step).toFixed(1) + '" y="' + (H - h) +
        '" width="' + bw.toFixed(1) + '" height="' + h + '" rx="1"></rect>';
    }).join('');
    return '<svg class="spark" width="' + W + '" height="' + H + '" aria-hidden="true">' + bars + '</svg>';
  }

  function skeleton(lines) {
    return '<div class="stack">' + Array.from({ length: lines || 4 }, () =>
      '<div class="skeleton sk-line" style="height:44px"></div>').join('') + '</div>';
  }

  function emptyState(icon, title, text, action) {
    return '<div class="empty"><div class="big">' + icon + '</div><h2>' +
      escapeHtml(title) + '</h2><p>' + escapeHtml(text) + '</p>' + (action || '') + '</div>';
  }

  /* ---------------- Auth ---------------- */

  async function resolveSession() {
    if (!API.token) return;
    try {
      state.user = await API.session();
    } catch (e) {
      API.setToken(null);
      state.user = null;
    }
  }

  function renderProfileSlot() {
    const slot = $('#profile-slot');
    const navContacts = $('#nav-contacts');
    const navAdmin = $('#nav-admin');

    if (!isLoggedIn()) {
      if (navContacts) navContacts.classList.add('hidden');
      if (navAdmin) navAdmin.classList.add('hidden');
      slot.innerHTML =
        '<div class="row" style="gap:8px">' +
        '<button class="btn btn-sm btn-ghost" id="nav-login-btn">sign in</button>' +
        '<button class="btn btn-sm btn-primary" id="nav-reg-btn">register</button>' +
        '</div>';
      $('#nav-login-btn').addEventListener('click', () => openAuthModal('login'));
      $('#nav-reg-btn').addEventListener('click', () => openAuthModal('register'));
      return;
    }

    if (navContacts) navContacts.classList.remove('hidden');

    const user = state.user || {};
    if (navAdmin) {
      if (user.isAdmin) navAdmin.classList.remove('hidden');
      else navAdmin.classList.add('hidden');
    }

    slot.innerHTML =
      '<div class="user-menu">' +
      '<button class="btn btn-icon btn-ghost" id="profile-btn" title="account" aria-label="account">' + avatarHtml(user, 'xs') + '</button>' +
      '<div class="menu hidden" id="user-menu">' +
      '<div class="menu-head"><b>' + escapeHtml(displayNameOf(user)) + '</b><span class="faint">@' + escapeHtml(user.login) + '</span>' +
      (user.isAdmin ? ' <span class="badge accent" style="font-size:10px;padding:2px 6px">ADMIN</span>' : '') +
      '</div>' +
      '<div class="sep"></div>' +
      '<a href="#/contacts">' + ICON.user + ' my contacts</a>' +
      '<a href="#/browse">' + ICON.search + ' browse directory</a>' +
      '<a href="#/messages">' + ICON.mail + ' messages</a>' +
      '<a href="#/compare">' + ICON.compare + ' compare</a>' +
      '<a href="#/dev/' + user.userid + '">' + ICON.user + ' my profile</a>' +
      '<a href="#/settings">' + ICON.settings + ' settings</a>' +
      (user.isAdmin ? '<a href="#/admin">' + ICON.shield + ' admin</a>' : '') +
      '<div class="sep"></div>' +
      '<button id="menu-logout">' + ICON.logout + ' sign out</button>' +
      '</div></div>';

    const menu = $('#user-menu');
    $('#profile-btn').addEventListener('click', (e) => {
      e.stopPropagation();
      menu.classList.toggle('hidden');
    });

    if (!profileMenuBound) {
      profileMenuBound = true;
      document.addEventListener('click', (e) => {
        const openMenu = document.getElementById('user-menu');
        if (openMenu && !openMenu.contains(e.target)) {
          openMenu.classList.add('hidden');
        }
      });
    }
    $('#menu-logout').addEventListener('click', async () => {
      try { await API.logout(); } catch (e) { /* ignore */ }
      API.setToken(null);
      state.user = null;
      renderProfileSlot();
      toast('signed out', 'success');
      location.hash = '#/';
      render();
    });
  }

  let modalOpener = null;

  function field(name, label, options = {}, value = '') {
    const attrs = Object.entries(options).map(([key, val]) =>
      val === false ? '' : ' ' + key + (val === true ? '' : '="' + escapeHtml(val) + '"')).join('');
    return '<div class="field"><label for="field-' + name + '">' + escapeHtml(label) + '</label>' +
      '<input class="input" id="field-' + name + '" name="' + name + '" value="' + escapeHtml(value) + '"' + attrs + '></div>';
  }

  function passwordFields() {
    return field('newPassword', 'New password', {type:'password', required:true, minlength:8, maxlength:72, autocomplete:'new-password'}) +
      field('confirmPassword', 'Confirm password', {type:'password', required:true, minlength:8, maxlength:72, autocomplete:'new-password'});
  }

  function checkPassword(data) {
    if (data.newPassword !== data.confirmPassword) throw new Error('Passwords do not match.');
    if (new TextEncoder().encode(data.newPassword).length > 72) throw new Error('Use a password of at most 72 bytes.');
  }

  function formDialog(title, description, fields, submitLabel, onSubmit, footer = '') {
    const host = $('#modal-root');
    if (!host.firstElementChild) modalOpener = document.activeElement;
    document.body.style.overflow = 'hidden';
    host.innerHTML = '<div class="modal" id="modal-backdrop"><section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="dialog-title" aria-describedby="dialog-description">' +
      '<div class="spread"><h2 id="dialog-title">' + escapeHtml(title) + '</h2><button class="btn btn-icon btn-ghost" type="button" data-close aria-label="Close dialog">' + ICON.x + '</button></div>' +
      '<p class="sub" id="dialog-description">' + escapeHtml(description) + '</p>' +
      '<form id="dialog-form" class="form-grid">' + fields +
      '<p class="form-error hidden" role="alert" id="dialog-error"></p>' +
      '<div class="dialog-actions"><button class="btn" type="button" data-close>Cancel</button>' +
      '<button class="btn btn-primary" type="submit">' + escapeHtml(submitLabel) + '</button></div></form>' + footer + '</section></div>';
    const form = $('#dialog-form');
    const error = $('#dialog-error');
    $$('[data-close]',host).forEach(button => button.addEventListener('click', closeModal));
    $('#modal-backdrop').addEventListener('click', e => { if (e.target.id === 'modal-backdrop') closeModal(); });
    form.addEventListener('submit', async e => {
      e.preventDefault();
      if (form.dataset.busy) return;
      const data = Object.fromEntries(new FormData(form));
      form.dataset.busy = '1';
      form.setAttribute('aria-busy','true');
      const button = form.querySelector('[type=submit]');
      button.textContent = 'Please wait…';
      $$('button',host).forEach(b => b.disabled = true);
      error.classList.add('hidden');
      try {
        await onSubmit(data, form);
        delete form.dataset.busy;
        if (form.isConnected) closeModal();
      } catch (err) {
        if (form.isConnected) {
          error.textContent = err.message;
          error.classList.remove('hidden');
          error.tabIndex = -1;
          error.focus();
        }
      } finally {
        delete form.dataset.busy;
        form.removeAttribute('aria-busy');
        if (form.isConnected) {
          $$('button',host).forEach(b => b.disabled = false);
          button.textContent = submitLabel;
        }
      }
    });
    const first = host.querySelector('input:not([readonly]), select, textarea, [type=submit]');
    if (first) first.focus();
  }

  function openAuthModal(mode = 'login') {
    const registering = mode === 'register';
    const fields = field('login', registering ? 'Username' : 'Username or email', {required:true, maxlength:registering ? 50 : 255, minlength:registering ? 3 : 1, autocomplete:'username'}) +
      (registering ? field('email','Email',{type:'email',required:true,maxlength:255,autocomplete:'email'}) +
        '<div class="form-columns">' + field('firstName','First name',{maxlength:50,autocomplete:'given-name'}) + field('lastName','Last name',{maxlength:50,autocomplete:'family-name'}) + '</div>' +
        field('githubUsername','GitHub username',{required:true,maxlength:39,placeholder:'Your GitHub username','aria-describedby':'github-help'}) +
        '<p class="form-hint" id="github-help">Use a GitHub account you own that is not already linked to another account.</p>' + passwordFields() :
        field('password','Password',{type:'password',required:true,autocomplete:'current-password'}));
    formDialog(registering ? 'Create account' : 'Sign in', registering ? 'Keep your contacts together and connect with your team.' : 'Welcome back. Sign in to manage your contacts.', fields,
      registering ? 'Create account' : 'Sign in', async data => {
        let result;
        if (registering) {
          checkPassword(data);
          const {newPassword, confirmPassword, ...profile} = data;
          result = await API.register({...profile, password:newPassword});
        } else result = await API.login(data.login,data.password);
        if (!result?.token) throw new Error('Sign-in could not be completed. Please try again.');
        API.setToken(result.token);
        try { state.user = await API.session(); }
        catch (err) {
          API.setToken(null);
          if (registering) throw new Error('Your account was created, but sign-in could not finish. Choose Sign in below to continue.');
          throw err;
        }
        renderProfileSlot();
        await render();
        toast(registering ? 'Account created. You are signed in.' : 'Welcome back','success');
      }, '<p class="auth-switch">' + (registering ? 'Already have an account?' : 'New here?') +
      ' <button class="text-button" type="button" id="auth-switch">' + (registering ? 'Sign in' : 'Create account') + '</button></p>');
    $('#auth-switch').addEventListener('click', () => openAuthModal(registering ? 'login' : 'register'));
  }

  function closeModal() {
    if ($('#dialog-form')?.dataset.busy) return;
    $('#modal-root').innerHTML = '';
    document.body.style.overflow = '';
    if (modalOpener?.isConnected) modalOpener.focus();
    modalOpener = null;
  }

  document.addEventListener('keydown', e => {
    const modal = $('#modal-root .modal-card');
    if (!modal) return;
    if (e.key === 'Escape') { e.preventDefault(); closeModal(); }
    if (e.key !== 'Tab') return;
    const focusable = $$('button:not([disabled]), input:not([disabled]), select, textarea, a[href]',modal).filter(el => el.getClientRects().length);
    if (!focusable.length) { e.preventDefault(); return; }
    const first = focusable[0], last = focusable[focusable.length-1];
    if (e.shiftKey && (document.activeElement === first || !focusable.includes(document.activeElement))) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && (document.activeElement === last || !focusable.includes(document.activeElement))) { e.preventDefault(); first.focus(); }
  });

  /* ---------------- Routing ---------------- */

  function parseHash() {
    const raw = location.hash.replace(/^#\/?/, '');
    const [pathPart, queryPart] = raw.split('?');
    const parts = pathPart.split('/').filter(Boolean);
    const query = Object.fromEntries(new URLSearchParams(queryPart || ''));
    return { parts, query };
  }

  function requireGate(root) {
    if (isLoggedIn()) return true;
    root.innerHTML = '<div class="container">' + emptyState(ICON.user, 'sign in required',
      'collab.dev developer profiles are visible to members. sign in to continue.',
      '<button class="btn btn-primary" id="empty-signin">sign in</button>') + '</div>';
    $('#empty-signin').addEventListener('click', () => openAuthModal('login'));
    return false;
  }

  async function render() {
    const { parts, query } = parseHash();
    const root = $('#app');
    const resource = parts[0] || 'home';
    window.scrollTo(0, 0);

    // Update active nav links
    $$('.nav-link').forEach((el) => {
      const href = el.getAttribute('href') || '';
      el.classList.toggle('active', href.includes('#/' + resource));
    });

    try {
      if (resource === 'home') return await viewHome(root, query);
      if (resource === 'dev') return await viewProfile(root, parts[1]);
      if (resource === 'u') return await resolveByLogin(root, parts[1]);
      if (resource === 'browse') return await viewBrowse(root, query);
      if (resource === 'following' || resource === 'contacts') return await viewFollowing(root, query);
      if (resource === 'compare') return await viewCompare(root, query);
      if (resource === 'messages') return await viewMessages(root, parts[1]);
      if (resource === 'settings') return await viewSettings(root, query);
      if (resource === 'admin') return await viewAdmin(root, query);
      if (resource === 'reset') return await viewReset(root, query);
      root.innerHTML = emptyState('404', 'not found', 'That page does not exist.');
    } catch (err) {
      root.innerHTML = emptyState('!', 'something went wrong', err.message || 'Unexpected error.');
    }
  }

  /* ---------------- Landing ---------------- */

  async function viewHome(root, query) {
    if (isLoggedIn()) {
      await viewProfile(root, state.user.userid);
      return;
    }

    root.innerHTML =
      '<div class="home lab-login"><div class="login-layout">' +
      '<section class="login-intro">' +
      '<h1 class="home-title">collab<span class="tick">.dev</span></h1>' +
      '<p class="home-sub">Sign in to manage your contacts and developer connections.</p>' +
      '</section>' +
      '<section class="login-panel panel" aria-labelledby="login-title">' +
      '<h2 id="login-title">Sign in</h2><p class="sub">Enter your account credentials to continue.</p>' +
      '<form id="landing-login-form" class="form-grid">' +
      field('login','Username or email',{required:true,autocomplete:'username'}) +
      field('password','Password',{type:'password',required:true,autocomplete:'current-password'}) +
      '<button class="btn btn-primary" type="submit">Sign in</button></form>' +
      '<p class="auth-switch">Need an account? <button class="text-button" type="button" id="hero-reg-btn">Register</button></p>' +
      '</section></div></div>';

    $('#hero-reg-btn').addEventListener('click', () => openAuthModal('register'));
    const form = $('#landing-login-form');
    form.addEventListener('submit', async e => {
      e.preventDefault();
      if (form.dataset.busy) return;
      const data = Object.fromEntries(new FormData(form));
      const button = form.querySelector('[type=submit]');
      form.dataset.busy = '1'; button.disabled = true; button.textContent = 'Signing in…';
      try {
        const result = await API.login(data.login,data.password);
        if (!result?.token) throw new Error('invalid');
        API.setToken(result.token);
        state.user = await API.session();
        renderProfileSlot();
        await render();
        toast('Welcome back','success');
      } catch (err) {
        API.setToken(null); state.user = null;
        root.innerHTML = '<div class="container">' + emptyState('!', 'Login failed',
          'The username or password was incorrect. Please try again.',
          '<button class="btn btn-primary" id="retry-login">Try again</button>') + '</div>';
        $('#retry-login').addEventListener('click', () => render());
        root.focus();
      } finally {
        delete form.dataset.busy;
        if (button.isConnected) { button.disabled = false; button.textContent = 'Sign in'; }
      }
    });
    $('#field-login').focus();
  }

  /* ---------------- Browse ---------------- */

  const browseState = { filters: null, facets: null, offset: 0, total: 0, loading: false };

  function readBrowseFilters(query) {
    return {
      q: query.q || '',
      skills: query.skill ? query.skill.split(',').filter(Boolean) : [],
      skillMode: query.skillMode === 'all' ? 'all' : 'any',
      languages: query.language ? query.language.split(',').filter(Boolean) : [],
      location: query.location || '',
      jobtitle: query.jobtitle ? query.jobtitle.split(',').filter(Boolean) : [],
      minStars: query.minStars || '',
      hasGithub: query.hasGithub === '1',
      following: query.following === '1',
      sort: query.sort || 'match',
    };
  }

  function writeBrowseFilters(filters) {
    const params = new URLSearchParams();
    if (filters.q) params.set('q', filters.q);
    if (filters.skills.length) params.set('skill', filters.skills.join(','));
    if (filters.skillMode === 'all') params.set('skillMode', 'all');
    if (filters.languages.length) params.set('language', filters.languages.join(','));
    if (filters.location) params.set('location', filters.location);
    if (filters.jobtitle.length) params.set('jobtitle', filters.jobtitle.join(','));
    if (filters.minStars) params.set('minStars', filters.minStars);
    if (filters.hasGithub) params.set('hasGithub', '1');
    if (filters.following) params.set('following', '1');
    if (filters.sort) params.set('sort', filters.sort);
    const qs = params.toString();
    history.replaceState(null, '', '#/browse' + (qs ? '?' + qs : ''));
  }

  async function viewBrowse(root, query) {
    if (!requireGate(root)) return;

    browseState.filters = readBrowseFilters(query);
    browseState.offset = 0;

    root.innerHTML =
      '<div class="container browse">' +
      '<div class="browse-head">' +
      '<form class="search-form" id="browse-search">' +
      '<input class="search-input" id="browse-q" type="search" aria-label="Search developer directory" placeholder="search name, @login, skill…" value="' + escapeHtml(browseState.filters.q) + '">' +
      '<button class="btn btn-primary" type="submit">' + ICON.search + '</button>' +
      '</form>' +
      '</div>' +
      '<div class="browse-layout">' +
      '<aside class="filters" id="filters"></aside>' +
      '<div class="browse-main">' +
      '<section id="suggestions"></section>' +
      '<div class="spread browse-toolbar">' +
      '<span class="results-count" id="browse-count"></span>' +
      '<label class="inline-field">sort <select class="select" id="sort-select">' +
      '<option value="match">best match</option>' +
      '<option value="stars">most stars</option>' +
      '<option value="followers">most followers</option>' +
      '<option value="repos">most repos</option>' +
      '<option value="recent">newest</option>' +
      '<option value="name">name</option>' +
      '</select></label>' +
      '</div>' +
      '<div id="browse-grid" class="card-grid"></div>' +
      '<div id="browse-more" class="pager"></div>' +
      '</div></div></div>';

    $('#sort-select').value = browseState.filters.sort;

    if (!browseState.facets) {
      try { browseState.facets = await API.facets(); } catch (e) { browseState.facets = { skills: [], languages: [], locations: [], jobTitles: [] }; }
    }
    drawFilters();
    loadSuggestions();

    $('#browse-search').addEventListener('submit', (e) => {
      e.preventDefault();
      browseState.filters.q = $('#browse-q').value.trim();
      applyBrowse();
    });
    $('#sort-select').addEventListener('change', (e) => {
      browseState.filters.sort = e.target.value;
      applyBrowse();
    });

    loadBrowse(true);
  }

  function applyBrowse() {
    writeBrowseFilters(browseState.filters);
    browseState.offset = 0;
    loadBrowse(true);
  }

  function createMultiSelect(host, options, selected, placeholder, onChange) {
    host.innerHTML =
      '<div class="ms" tabindex="-1">' +
      '<div class="ms-controls"><div class="ms-tokens"></div>' +
      '<input class="ms-input" type="text" placeholder="' + escapeHtml(placeholder) + '"></div>' +
      '<div class="ms-menu hidden"></div>' +
      '</div>';

    const ms = $('.ms', host);
    const tokens = $('.ms-tokens', host);
    const input = $('.ms-input', host);
    const menu = $('.ms-menu', host);

    const drawTokens = () => {
      tokens.innerHTML = selected.map((value) =>
        '<span class="ms-token">' + escapeHtml(value) +
        '<button type="button" class="x" data-value="' + escapeHtml(value) + '" aria-label="Remove">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>' +
        '</button></span>').join('');
      $$('.ms-token .x', tokens).forEach((btn) =>
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const idx = selected.indexOf(btn.dataset.value);
          if (idx >= 0) selected.splice(idx, 1);
          drawTokens();
          onChange();
        }));
    };

    const select = (value) => {
      if (!value || selected.includes(value)) return;
      selected.push(value);
      input.value = '';
      drawTokens();
      drawMenu();
      onChange();
    };

    const drawMenu = () => {
      const q = input.value.trim().toLowerCase();
      const matches = options
        .filter((option) => !selected.includes(option) && option.toLowerCase().includes(q))
        .slice(0, 60);
      if (!matches.length) {
        menu.classList.add('hidden');
        menu.innerHTML = '';
        return;
      }
      menu.innerHTML = matches.map((option) =>
        '<button type="button" class="ms-option" data-value="' + escapeHtml(option) + '">' +
        escapeHtml(option) + '</button>').join('');
      menu.classList.remove('hidden');
      $$('.ms-option', menu).forEach((btn) =>
        btn.addEventListener('mousedown', (e) => {
          e.preventDefault();
          select(btn.dataset.value);
          input.focus();
        }));
    };

    input.addEventListener('input', drawMenu);
    input.addEventListener('focus', drawMenu);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const first = menu.querySelector('.ms-option');
        if (first) select(first.dataset.value);
      } else if (e.key === 'Backspace' && input.value === '' && selected.length) {
        selected.pop();
        drawTokens();
        onChange();
      } else if (e.key === 'Escape') {
        menu.classList.add('hidden');
        input.blur();
      }
    });
    ms.addEventListener('click', () => input.focus());
    document.addEventListener('click', (e) => {
      if (!host.contains(e.target)) menu.classList.add('hidden');
    });

    drawTokens();
  }

  function drawFilters() {
    const el = $('#filters');
    const f = browseState.filters;
    const facets = browseState.facets;

    el.innerHTML =
      '<div class="filter-head spread"><h3 class="section-title" style="margin:0">filters</h3>' +
      '<button class="btn btn-sm btn-ghost" id="clear-filters">clear</button></div>' +
      '<div class="filter-group"><div class="filter-title">skills</div>' +
      '<div id="f-skills"></div>' +
      (f.skills.length > 1 ? '<label class="inline-field">match <select class="select" id="skill-mode">' +
        '<option value="any"' + (f.skillMode === 'any' ? ' selected' : '') + '>any</option>' +
        '<option value="all"' + (f.skillMode === 'all' ? ' selected' : '') + '>all</option>' +
        '</select></label>' : '') +
      '</div>' +
      '<div class="filter-group"><div class="filter-title">languages</div><div id="f-languages"></div></div>' +
      '<div class="filter-group"><div class="filter-title">roles</div><div id="f-roles"></div></div>' +
      '<div class="filter-group"><div class="filter-title">location</div>' +
      '<input class="input" id="f-location" placeholder="e.g. Orlando" value="' + escapeHtml(f.location) + '"></div>' +
      '<div class="filter-group"><div class="filter-title">minimum stars</div>' +
      '<input class="input" id="f-minstars" type="number" min="0" value="' + escapeHtml(f.minStars) + '"></div>' +
      '<div class="filter-group"><label class="check"><input type="checkbox" id="f-following"' + (f.following ? ' checked' : '') + '> only following</label></div>';

    createMultiSelect($('#f-skills'), (facets.skills || []).map((s) => s.name), f.skills,
      'Type a skill…', applyBrowse);
    createMultiSelect($('#f-languages'), (facets.languages || []).map((l) => l.language), f.languages,
      'Type a language…', applyBrowse);
    createMultiSelect($('#f-roles'), (facets.jobTitles || []).map((r) => r.jobtitle), f.jobtitle,
      'Type a role…', applyBrowse);

    $('#clear-filters').addEventListener('click', () => {
      browseState.filters = readBrowseFilters({});
      drawFilters();
      $('#browse-q').value = '';
      $('#sort-select').value = browseState.filters.sort;
      applyBrowse();
    });

    const skillMode = $('#skill-mode');
    if (skillMode) skillMode.addEventListener('change', (e) => {
      browseState.filters.skillMode = e.target.value;
      applyBrowse();
    });

    const inputs = [
      ['#f-location', 'location'],
      ['#f-minstars', 'minStars'],
    ];
    inputs.forEach(([sel, key]) => {
      const input = $(sel);
      if (input) input.addEventListener('change', (e) => {
        browseState.filters[key] = e.target.value.trim();
        applyBrowse();
      });
    });
    $('#f-following').addEventListener('change', (e) => { browseState.filters.following = e.target.checked; applyBrowse(); });
  }

  function devCardHtml(dev) {
    const shared = (dev.sharedSkills || []).length +
      uniqueSharedLanguages(dev.sharedSkills, dev.sharedLanguages).length;
    const followLabel = dev.isFollowing ? 'following' : 'follow';
    const followCls = dev.isFollowing ? 'btn btn-sm follow-btn on' : 'btn btn-sm follow-btn';
    const actions = dev.isSelf
      ? '<a class="btn btn-sm" href="#/dev/' + dev.userid + '">view profile</a>'
      : '<a class="btn btn-sm" href="#/dev/' + dev.userid + '">view</a>' +
        '<button class="' + followCls + '" data-follow="' + dev.userid + '" data-following="' + (dev.isFollowing ? '1' : '0') + '">' +
        (dev.isFollowing ? '✓ ' + followLabel : '+ ' + followLabel) + '</button>' +
        '<button class="btn btn-sm btn-ghost" data-message="' + dev.userid + '">' + ICON.mail + '</button>';
    return '<article class="dev-card">' +
      '<div class="dev-card-head">' + avatarHtml(dev, 'sm') +
      '<div class="dev-card-id"><a class="dev-card-name" href="#/dev/' + dev.userid + '">' + escapeHtml(displayNameOf(dev)) + '</a>' +
      '<span class="dev-card-handle">@' + escapeHtml(dev.login) + '</span></div>' +
      (shared ? '<span class="badge accent" title="shared with you">' + shared + ' shared</span>' : '') +
      '</div>' +
      '<div class="dev-foot">' +
      '<span class="item">' + ICON.star + ' ' + fmtNum(dev.totalStars) + '</span>' +
      '<span class="item">' + ICON.user + ' ' + fmtNum(dev.followers) + '</span>' +
      '<span class="item">' + ICON.fork + ' ' + fmtNum(dev.publicRepos) + ' repos</span>' +
      '</div>' +
      '<div class="dev-card-meta">' +
      (dev.jobTitle ? '<span class="meta-item">' + ICON.briefcase + escapeHtml(dev.jobTitle) + '</span>' : '') +
      (dev.location ? '<span class="meta-item">' + ICON.location + escapeHtml(dev.location) + '</span>' : '') +
      '</div>' +
      '<div class="chips dev-skills">' + skillChips(dev.skills, 4) + '</div>' +
      ((dev.languages || []).length ? '<div class="card-langs">' + langBarHtml(dev.languages) + '</div>' : '') +
      '<div class="dev-actions">' + actions + '</div>' +
      '</article>';
  }

  function wireDevActions(scope) {
    $$('[data-follow]', scope).forEach((btn) => {
      if (btn.dataset.wired) return;
      btn.dataset.wired = '1';
      btn.addEventListener('click', async () => {
        const userid = btn.dataset.follow;
        const following = btn.dataset.following === '1';
        btn.disabled = true;
        try {
          if (following) await API.unfollow(userid); else await API.follow(userid);
          btn.dataset.following = following ? '0' : '1';
          btn.classList.toggle('on', !following);
          btn.textContent = !following ? '✓ following' : '+ follow';
          toast(!following ? 'Now following' : 'unfollowed', 'success');
        } catch (err) {
          toast(err.message, 'error');
        } finally {
          btn.disabled = false;
        }
      });
    });
    $$('[data-message]', scope).forEach((btn) => {
      if (btn.dataset.wired) return;
      btn.dataset.wired = '1';
      btn.addEventListener('click', () => messageUser(btn.dataset.message));
    });
  }

  async function loadBrowse(reset) {
    if (browseState.loading) return;
    browseState.loading = true;

    const grid = $('#browse-grid');
    const more = $('#browse-more');
    const count = $('#browse-count');
    const f = browseState.filters;

    if (reset) grid.innerHTML = skeleton(4);
    more.innerHTML = '';

    const params = {
      q: f.q,
      skill: f.skills,
      skillMode: f.skills.length > 1 ? f.skillMode : '',
      language: f.languages,
      location: f.location,
      jobtitle: f.jobtitle,
      minStars: f.minStars,
      hasGithub: f.hasGithub,
      following: f.following,
      sort: f.sort,
      limit: 24,
      offset: browseState.offset,
    };

    try {
      const payload = await API.profiles(params);
      const data = payload.data || [];
      browseState.total = payload.meta ? payload.meta.total : data.length;

      if (reset) grid.innerHTML = '';
      if (!data.length && reset) {
        grid.innerHTML = emptyState(ICON.search, 'no matches', 'Try removing a filter or searching differently.');
        count.textContent = '0 developers';
      } else {
        grid.insertAdjacentHTML('beforeend', data.map(devCardHtml).join(''));
        count.textContent = browseState.total + ' developer' + (browseState.total === 1 ? '' : 's');
      }
      wireDevActions(grid);

      browseState.offset += data.length;
      if (browseState.offset < browseState.total) {
        more.innerHTML = '<button class="btn" id="load-more">load more</button>';
        $('#load-more').addEventListener('click', () => loadBrowse(false));
      }
    } catch (err) {
      grid.innerHTML = emptyState('!', 'could not load developers', err.message);
    } finally {
      browseState.loading = false;
    }
  }

  async function loadSuggestions() {
    const el = $('#suggestions');
    if (!el || !isLoggedIn()) return;
    try {
      const data = await API.suggestions({ limit: 4 }) || [];
      if (!data.length) { el.innerHTML = ''; return; }
      el.innerHTML = '<div class="suggest-head"><h3 class="section-title" style="margin:0">' +
        ICON.sparkles + ' suggested for you</h3></div>' +
        '<div class="suggest-strip">' + data.map(suggestCardHtml).join('') + '</div>';
      wireDevActions(el);
    } catch (e) {
      el.innerHTML = '';
    }
  }

  function suggestCardHtml(dev) {
    const reasons = []
      .concat((dev.sharedSkills || []).slice(0, 3))
      .concat(uniqueSharedLanguages(dev.sharedSkills, dev.sharedLanguages).slice(0, 2));
    return '<a class="suggest-card" href="#/dev/' + dev.userid + '">' +
      avatarHtml(dev, 'sm') +
      '<div class="suggest-id"><b>' + escapeHtml(displayNameOf(dev)) + '</b>' +
      '<span class="faint">@' + escapeHtml(dev.login) + '</span></div>' +
      (reasons.length ? '<div class="suggest-reasons">' + reasons.map((r) =>
        '<span class="mini-chip">' + escapeHtml(r) + '</span>').join('') + '</div>' : '') +
      '</a>';
  }

  /* ---------------- Contacts / Following ---------------- */

  function pagination(host, meta, onPage) {
    if (!meta || !Number.isFinite(Number(meta.total)) || !Number(meta.limit)) return;
    const page = Math.max(1,Number(meta.page) || 1);
    const pages = Math.max(1,Math.ceil(Number(meta.total)/Number(meta.limit)));
    if (pages <= 1) return;
    const nav = document.createElement('nav');
    nav.className = 'pager';
    nav.setAttribute('aria-label','Result pages');
    nav.innerHTML = '<button class="btn btn-sm" data-page="' + (page-1) + '"' + (page <= 1 ? ' disabled' : '') + '>Previous</button>' +
      '<span>Page ' + page + ' of ' + pages + '</span><button class="btn btn-sm" data-page="' + (page+1) + '"' + (page >= pages ? ' disabled' : '') + '>Next</button>';
    $$('button',nav).forEach(button => button.addEventListener('click',() => onPage(Number(button.dataset.page))));
    host.appendChild(nav);
  }

  function contactName(contact) {
    return [contact.firstName ?? contact.firstname,contact.lastName ?? contact.lastname].filter(Boolean).join(' ').trim() || contact.displayName || contact.email || 'Unnamed contact';
  }

  async function viewFollowing(root, query = {}) {
    if (!requireGate(root)) return;
    root.innerHTML = '<div class="container"><div class="spread wrap"><div><h1 class="page-title">My contacts</h1><p class="muted">The people you want to keep close.</p></div>' +
      '<div class="row wrap"><a class="btn" href="#/browse">Discover developers</a><button class="btn btn-primary" id="add-contact">' + ICON.plus + ' New contact</button></div></div>' +
      '<form class="contact-search" id="contact-search"><label class="sr-only" for="contact-filter">Search contacts</label>' +
      '<input class="input" type="search" id="contact-filter" placeholder="Search name, email, phone or notes" maxlength="100" value="' + escapeHtml(query.q || '') + '">' +
      '<button class="btn" type="submit">Search</button></form><div id="contact-results" aria-live="polite" class="section"></div></div>';
    const results = $('#contact-results');
    const search = $('#contact-filter');
    let page = 1, generation = 0;
    const load = async (nextPage = 1) => {
      page = nextPage;
      const request = ++generation;
      const q = search.value.trim();
      results.innerHTML = skeleton(3);
      results.setAttribute('aria-busy','true');
      try {
        const payload = await API.contacts({q,page,limit:20});
        if (!results.isConnected || request !== generation) return;
        const contacts = payload.data || [];
        if (!Array.isArray(contacts)) throw new Error('The contact list could not be loaded.');
        if (contacts.length > 20) throw new Error('The server could not return a limited set of contacts. Please try again later.');
        if (!contacts.length && page > 1) return load(page-1);
        results.innerHTML = '<p class="results-count">' + (payload.meta?.total ?? contacts.length) + ' contact' + ((payload.meta?.total ?? contacts.length) === 1 ? '' : 's') + '</p>';
        if (!contacts.length) {
          results.innerHTML += emptyState(ICON.user, q ? 'No matching contacts' : 'Your address book starts here',q ? 'Try another name, email, phone number or note.' : 'Add your first contact or discover developers to follow.');
        } else {
          const list = document.createElement('div');
          list.className = 'stack';
          list.innerHTML = contacts.map(c => '<article class="contact-row"><div class="contact-main"><h2 class="contact-name">' + escapeHtml(contactName(c)) + '</h2>' +
            (c.login ? '<p class="faint mono">@' + escapeHtml(c.login) + '</p>' : '') +
            '<p class="contact-details">' + escapeHtml([c.email,c.phone].filter(Boolean).join(' · ')) + '</p>' +
            (c.description ? '<p class="muted contact-notes">' + escapeHtml(c.description) + '</p>' : '') + '</div><div class="contact-actions">' +
            '<button class="btn btn-sm" data-edit="' + Number(c.contactid) + '" aria-label="Edit ' + escapeHtml(contactName(c)) + '">Edit</button>' +
            '<button class="btn btn-sm btn-danger" data-delete="' + Number(c.contactid) + '" aria-label="Delete ' + escapeHtml(contactName(c)) + '">Delete</button></div></article>').join('');
          results.appendChild(list);
          $$('[data-edit]',list).forEach(button => button.addEventListener('click',async () => {
            button.disabled = true;
            try {
              const contact = await API.contact(button.dataset.edit);
              if (results.isConnected) editContact(contact, () => load(page));
            } catch (err) { toast(err.message,'error'); }
            finally { button.disabled = false; }
          }));
          $$('[data-delete]',list).forEach(button => button.addEventListener('click',() => {
            const contact = contacts.find(c => String(c.contactid) === button.dataset.delete);
            formDialog('Delete contact?', 'Remove ' + contactName(contact) + ' from your address book? This cannot be undone.', '', 'Delete contact', async () => {
              await API.removeContact(contact.contactid);
              toast('Contact deleted','success');
              if (results.isConnected) await load(page);
            });
          }));
        }
        pagination(results,payload.meta,load);
      } catch (err) {
        if (!results.isConnected || request !== generation) return;
        results.innerHTML = emptyState('!','Could not load contacts',err.message,'<button class="btn" id="retry-contacts">Retry</button>');
        $('#retry-contacts',results).addEventListener('click',() => load(page));
      } finally {
        if (request === generation) results.removeAttribute('aria-busy');
      }
    };
    $('#add-contact').addEventListener('click',() => editContact(null, () => { search.value = ''; return load(1); }));
    const schedule = debounce(() => { if (results.isConnected) load(1); },300);
    search.addEventListener('input',schedule);
    $('#contact-search').addEventListener('submit',e => { e.preventDefault(); schedule.cancel(); load(1); });
    await load();
  }

  function editContact(contact, onSaved) {
    const c = contact || {};
    const fields = (contact ? field('contactid','Contact ID',{readonly:true},c.contactid) : '') +
      '<div class="form-columns">' + field('firstName','First name',{maxlength:50,autocomplete:'given-name'},c.firstName ?? c.firstname) +
      field('lastName','Last name',{maxlength:50,autocomplete:'family-name'},c.lastName ?? c.lastname) + '</div>' +
      field('email','Email',{type:'email',maxlength:100,autocomplete:'email'},c.email) +
      field('phone','Phone',{type:'tel',maxlength:10,autocomplete:'tel','aria-describedby':'phone-help'},c.phone) +
      '<p class="form-hint" id="phone-help">Up to 10 characters.</p>' +
      '<div class="field"><label for="contact-notes">Notes</label><textarea id="contact-notes" class="textarea" name="description" maxlength="100">' + escapeHtml(c.description || '') + '</textarea></div>';
    formDialog(contact ? 'Edit contact' : 'New contact','Save the details that help you stay in touch.',fields,'Save contact',async data => {
      const {contactid,...fields} = data;
      Object.keys(fields).forEach(key => fields[key] = fields[key].trim());
      if (!fields.firstName && !fields.lastName && !fields.email) throw new Error('Enter a name or an email address.');
      if (contact) await API.updateContact(c.contactid,fields);
      else await API.createContact(fields);
      toast(contact ? 'Contact updated' : 'Contact created','success');
      await onSaved();
    });
  }

  async function resolveByLogin(root, login) {
    if (!requireGate(root)) return;
    root.innerHTML = skeleton(2);
    try {
      const payload = await API.profiles({ q: login, limit: 100 });
      const match = (payload.data || []).find((p) => p.login.toLowerCase() === String(login).toLowerCase());
      if (!match) {
        // Own profile is excluded from search results but is still reachable by link.
        if (state.user && state.user.login.toLowerCase() === String(login).toLowerCase()) {
          await viewProfile(root, state.user.userid);
          return;
        }
        root.innerHTML = emptyState('404', 'not found', 'No profile matches @' + escapeHtml(login));
        return;
      }
      await viewProfile(root, match.userid);
    } catch (err) {
      root.innerHTML = emptyState('!', 'something went wrong', err.message);
    }
  }

  async function viewProfile(root, userid) {
    if (!userid) {
      root.innerHTML = emptyState('404', 'not found', 'No developer was specified.');
      return;
    }
    if (!requireGate(root)) return;
    root.innerHTML = skeleton(4);

    const profile = await API.profile(userid);
    if (!profile) {
      root.innerHTML = emptyState('404', 'not found', 'This profile does not exist.');
      return;
    }

    const gh = profile.github;
    const isOwner = profile.isSelf || (state.user && state.user.userid === profile.userid);
    const heroAvatar = Object.assign({}, profile, {
      avatarUrl: /^https?:/i.test(profile.avatarUrl || '') ? profile.avatarUrl : (gh && gh.avatarUrl),
    });

    const social = (profile.socialLinks || []).map((link) =>
      '<a class="chip" href="' + escapeHtml(link.url) + '" target="_blank" rel="noopener">' +
      ICON.link + escapeHtml(link.platform) + '</a>').join('');
    const sharedLangs = uniqueSharedLanguages(profile.sharedSkills, profile.sharedLanguages);

    root.innerHTML =
      '<div class="container">' +
      (isOwner ? '' : '<a class="btn btn-sm btn-ghost" href="#/browse">' + ICON.back + ' back</a>') +
      '<section class="section profile-head">' + avatarHtml(heroAvatar, 'xl') +
      '<div class="flex1">' +
      '<h1 class="profile-name">' + escapeHtml(displayNameOf(profile)) + '</h1>' +
      '<div class="profile-handle">@' + escapeHtml(profile.login) + '</div>' +
      '<div class="profile-meta">' +
      (profile.jobTitle ? '<span class="meta-item">' + ICON.briefcase + escapeHtml(profile.jobTitle) + '</span>' : '') +
      (profile.location ? '<span class="meta-item">' + ICON.location + escapeHtml(profile.location) + '</span>' : '') +
      (isOwner && state.user.email ? '<span class="meta-item">' + ICON.mail + escapeHtml(state.user.email) + '</span>' : '') +
      (gh && gh.username ? '<a class="meta-item" href="' + escapeHtml(gh.profileUrl) + '" target="_blank" rel="noopener">' + ICON.github + escapeHtml(gh.username) + '</a>' : '') +
      '</div></div>' +
      '<div class="profile-actions">' +
      '<button class="btn btn-ghost btn-sm" id="share-btn" title="copy share link">' + ICON.share + ' share</button>' +
      (isOwner
        ? '<a class="btn btn-sm" href="#/settings">' + ICON.edit + ' edit</a>'
        : '<button class="btn ' + (profile.isFollowing ? 'btn-sm' : 'btn-primary btn-sm') + '" id="follow-btn">' +
          (profile.isFollowing ? '✓ following' : '+ follow') + '</button>' +
          '<button class="btn btn-primary btn-sm" id="message-btn">' + ICON.mail + ' message</button>') +
      '<a class="btn btn-sm" href="#/compare?a=' + profile.userid + '">' + ICON.compare + ' compare</a>' +
      '</div></section>' +
      (isOwner
        ? '<section class="section">' +
          '<form class="search-form" id="profile-search">' +
          '<input class="search-input" type="search" aria-label="Search developer directory" placeholder="search developers by name, @login, skill…" autocomplete="off">' +
          '<button class="btn btn-primary" type="submit">' + ICON.search + ' search</button>' +
          '</form>' +
          '<div class="row wrap mt">' +
          '<a class="btn btn-primary" href="#/contacts">' + ICON.user + ' my contacts</a>' +
          (state.user.isAdmin ? '<a class="btn" href="#/admin">' + ICON.shield + ' admin panel</a>' : '') +
          '</div></section>'
        : '') +
      (profile.bio ? '<p class="bio section">' + escapeHtml(profile.bio) + '</p>' : '') +
      (social ? '<div class="chips section">' + social + '</div>' : '') +
      ((profile.sharedSkills || []).length || sharedLangs.length
        ? '<section class="section"><h3 class="section-title">common ground</h3><div class="chips">' +
          (profile.sharedSkills || []).map((s) => '<span class="chip static"><span class="dot" style="background:' + langColor(s) + '"></span>' + escapeHtml(s) + '</span>').join('') +
          sharedLangs.map((l) => '<span class="chip static">' + escapeHtml(l) + '</span>').join('') +
          '</div></section>' : '') +
      '<section class="section"><h3 class="section-title">skills</h3><div class="chips">' + skillChips(profile.skills) + '</div></section>' +
      '<section class="section" id="github-section"></section>' +
      '</div>';

    $('#share-btn').addEventListener('click', () => shareProfile(profile));
    const profileSearch = $('#profile-search');
    if (profileSearch) {
      profileSearch.addEventListener('submit', (e) => {
        e.preventDefault();
        const q = profileSearch.querySelector('input').value.trim();
        location.hash = '#/browse' + (q ? '?q=' + encodeURIComponent(q) : '');
      });
    }
    const followBtn = $('#follow-btn');
    if (followBtn) {
      followBtn.addEventListener('click', async () => {
        followBtn.disabled = true;
        try {
          if (profile.isFollowing) await API.unfollow(profile.userid); else await API.follow(profile.userid);
          viewProfile(root, userid);
        } catch (err) {
          toast(err.message, 'error');
          followBtn.disabled = false;
        }
      });
    }
    const messageBtn = $('#message-btn');
    if (messageBtn) messageBtn.addEventListener('click', () => messageUser(profile.userid));

    await loadGithub(root, profile, gh);
  }

  async function loadGithub(root, profile, gh) {
    const section = $('#github-section');
    if (!section) return;
    if (!gh) {
      section.innerHTML = '<h3 class="section-title">github</h3>' +
        emptyState(ICON.github, 'Not linked', 'No GitHub account linked.');
      return;
    }

    section.innerHTML = '<h3 class="section-title">github</h3>' + skeleton(3);
    try {
      const full = await API.github(profile.userid);

      // Lazily refresh stale GitHub data in the background.
      if (full.stale) {
        API.syncGithub({ userid: profile.userid }).then(() => {
          if (parseHash().parts[1] === String(profile.userid)) viewProfile(root, profile.userid);
        }).catch(() => {});
      }

      const repos = full.repositories || [];
      const totalStars = repos.reduce((sum, r) => sum + (Number(r.stars) || 0), 0);
      const langMap = {};
      repos.forEach((r) => { if (r.language) langMap[r.language] = (langMap[r.language] || 0) + 1; });
      const languages = Object.keys(langMap)
        .map((name) => ({ language: name, repos: langMap[name] }))
        .sort((a, b) => b.repos - a.repos);
      const totalCommits = repos.reduce((sum, r) => sum + (Number(r.commits_30d) || 0), 0);
      const sortedRepos = repos.slice().sort((a, b) =>
        (Number(b.is_featured) - Number(a.is_featured)) ||
        ((Number(b.stars) || 0) - (Number(a.stars) || 0)));

      section.innerHTML =
        '<div class="stat-grid">' +
        statCell('Followers', fmtNum(full.followers)) +
        statCell('Repositories', fmtNum(full.publicRepos)) +
        statCell('Stars', fmtNum(totalStars)) +
        statCell('Commits · 30d', fmtNum(totalCommits)) +
        '</div>' +
        (languages.length ? '<div class="panel section" style="padding:20px"><h3 class="section-title">languages</h3>' +
          langBarHtml(languages) + '<div class="mt">' + langLegend(languages) + '</div></div>' : '') +
        (repos.length ? '<div class="section"><h3 class="section-title">activity</h3>' + activityCharts(repos) + '</div>' : '') +
        (sortedRepos.length ? '<div class="section"><div class="spread"><h3 class="section-title">repositories</h3>' +
          '<span class="mono faint" style="font-size:12px">bars = commits per week · ' +
          (full.lastSynced ? 'synced ' + timeAgo(full.lastSynced) : '') + '</span></div>' +
          '<div class="repo-list">' + sortedRepos.slice(0, 20).map(repoRow).join('') + '</div></div>' : '');

      wireHeatmap(section);
    } catch (err) {
      section.innerHTML = '<h3 class="section-title">github</h3>' +
        emptyState('!', 'could not load GitHub data', err.message);
    }
  }

  async function shareProfile(profile) {
    const url = location.origin + location.pathname + '#/u/' + profile.login;
    try {
      await navigator.clipboard.writeText(url);
      toast('link copied: ' + url, 'success');
    } catch (e) {
      toast('Share link: ' + url, 'info');
    }
  }

  /* ---------------- Compare ---------------- */

  function attachAutocomplete(inputEl, suggestionsEl, candidates, onPick) {
    const render = () => {
      const q = inputEl.value.trim().toLowerCase();
      if (!q) { suggestionsEl.classList.add('hidden'); suggestionsEl.innerHTML = ''; return; }
      const matches = candidates.filter((p) =>
        p.login.toLowerCase().includes(q) || displayNameOf(p).toLowerCase().includes(q)
      ).slice(0, 8);
      if (!matches.length) { suggestionsEl.classList.add('hidden'); suggestionsEl.innerHTML = ''; return; }
      suggestionsEl.innerHTML = matches.map((p) =>
        '<button type="button" class="suggestion" data-id="' + p.userid + '" data-login="' + escapeHtml(p.login) + '">' +
        avatarHtml(p, 'xs') +
        '<span class="s-name">' + escapeHtml(displayNameOf(p)) + '</span>' +
        '<span class="s-login">@' + escapeHtml(p.login) + '</span></button>').join('');
      suggestionsEl.classList.remove('hidden');
    };
    inputEl.addEventListener('input', render);
    inputEl.addEventListener('focus', render);
    suggestionsEl.addEventListener('click', (e) => {
      const btn = e.target.closest('.suggestion');
      if (!btn) return;
      onPick(Number(btn.dataset.id), btn.dataset.login);
      inputEl.value = '@' + btn.dataset.login;
      suggestionsEl.classList.add('hidden');
    });
    inputEl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const first = suggestionsEl.querySelector('.suggestion');
        if (first) {
          onPick(Number(first.dataset.id), first.dataset.login);
          inputEl.value = '@' + first.dataset.login;
          suggestionsEl.classList.add('hidden');
        }
      }
      if (e.key === 'Escape') { suggestionsEl.classList.add('hidden'); inputEl.blur(); }
    });
    document.addEventListener('click', (e) => {
      if (!inputEl.parentElement.contains(e.target)) suggestionsEl.classList.add('hidden');
    });
  }

  async function viewCompare(root, query) {
    if (!requireGate(root)) return;
    root.innerHTML = skeleton(4);

    await loadDirectory();
    const candidates = state.profiles;

    let a = query.a || '';
    let b = query.b || '';

    root.innerHTML =
      '<div class="container">' +
      '<h1 class="page-title">compare</h1>' +
      '<div class="row mt" style="justify-content:center;align-items:flex-start">' +
      '<div class="autocomplete" style="flex:0 1 300px;min-width:200px">' +
      '<input class="input" id="pick-a-input" placeholder="@login or name" autocomplete="off">' +
      '<div class="suggestions hidden" id="suggest-a"></div></div>' +
      '<button class="btn btn-ghost btn-icon" id="swap" title="swap">⇄</button>' +
      '<div class="autocomplete" style="flex:0 1 300px;min-width:200px">' +
      '<input class="input" id="pick-b-input" placeholder="@login or name" autocomplete="off">' +
      '<div class="suggestions hidden" id="suggest-b"></div></div>' +
      '</div>' +
      '<div id="compare-result" class="section"></div>' +
      '</div>';

    const inputA = $('#pick-a-input');
    const inputB = $('#pick-b-input');
    const prefill = (inputEl, userid) => {
      const p = candidates.find((x) => String(x.userid) === String(userid));
      inputEl.value = p ? '@' + p.login : '';
    };
    if (a) prefill(inputA, a);
    if (b) prefill(inputB, b);

    const go = () => {
      const params = new URLSearchParams();
      if (a) params.set('a', a);
      if (b) params.set('b', b);
      history.replaceState(null, '', '#/compare' + (params.toString() ? '?' + params.toString() : ''));
    };

    attachAutocomplete(inputA, $('#suggest-a'), candidates, (id) => { a = String(id); go(); drawCompare(a, b); });
    attachAutocomplete(inputB, $('#suggest-b'), candidates, (id) => { b = String(id); go(); drawCompare(a, b); });

    inputA.addEventListener('input', () => { if (!inputA.value.trim()) { a = ''; go(); drawCompare(a, b); } });
    inputB.addEventListener('input', () => { if (!inputB.value.trim()) { b = ''; go(); drawCompare(a, b); } });

    $('#swap').addEventListener('click', () => {
      const t = a; a = b; b = t;
      if (a) prefill(inputA, a); else inputA.value = '';
      if (b) prefill(inputB, b); else inputB.value = '';
      go(); drawCompare(a, b);
    });

    drawCompare(a, b);
  }

  async function drawCompare(a, b) {
    const result = $('#compare-result');
    if (!result) return;
    if (!a || !b) {
      result.innerHTML = emptyState(ICON.compare, 'pick two developers', 'Select two developers to see what you have in common.');
      return;
    }
    if (a === b) {
      result.innerHTML = emptyState(ICON.compare, 'choose different people', 'The same developer is selected twice.');
      return;
    }
    result.innerHTML = skeleton(4);
    const data = await API.compare(a, b);
    const [devA, devB] = data.developers;

    const skillNamesA = (devA.skills || []).map((s) => s.name);
    const skillNamesB = (devB.skills || []).map((s) => s.name);
    const union = new Set(skillNamesA.concat(skillNamesB));
    const overlapPct = union.size ? Math.round((data.commonSkills.length / union.size) * 100) : 0;

    const commonSkills = data.commonSkills.length
      ? '<div class="chips">' + data.commonSkills.map((s) =>
          '<span class="chip static"><span class="dot" style="background:' + langColor(s.name) + '"></span>' +
          escapeHtml(s.name) + '</span>').join('') + '</div>'
      : '<span class="empty-inline">no shared skills yet.</span>';

    const sharedProjects = data.sharedProjects.length
      ? data.sharedProjects.map((project) =>
          '<div class="shared-item"><div class="sname">' + ICON.sparkles + ' ' + escapeHtml(project.name) +
          ' <span class="badge accent">both worked on</span></div>' +
          '<div class="shared-links">' + project.developers.map((repo) => {
            const dev = repo.userid === devA.userid ? devA : devB;
            return '<a class="shared-link" href="' + escapeHtml(repo.url || '#') + '" target="_blank" rel="noopener">' +
              escapeHtml(displayNameOf(dev)) + ' · ' + ICON.star + escapeHtml(fmtNum(repo.stars)) + '</a>';
          }).join('') + '</div></div>').join('')
      : '<span class="empty-inline">no repositories with matching names.</span>';

    const commonLanguages = data.commonLanguages.length
      ? '<div class="chips">' + data.commonLanguages.map((lang) =>
          '<span class="chip static"><span class="dot" style="background:' + langColor(lang) + '"></span>' +
          escapeHtml(lang) + '</span>').join('') + '</div>'
      : '<span class="empty-inline">no overlapping languages.</span>';

    const miniLang = (dev) => {
      const total = (dev.languages || []).reduce((sum, l) => sum + l.repos, 0);
      if (!total) return '<span class="empty-inline">no language data.</span>';
      return '<div style="width:100%">' + langBarHtml(dev.languages) + '</div>';
    };

    const col = (dev) =>
      '<div class="compare-col">' + avatarHtml(dev, 'lg') +
      '<div><div class="profile-name">' + escapeHtml(displayNameOf(dev)) + '</div>' +
      '<div class="profile-handle">@' + escapeHtml(dev.login) + '</div>' +
      '<div class="profile-meta" style="justify-content:center">' +
      (dev.jobTitle ? escapeHtml(dev.jobTitle) : '') +
      (dev.location ? ' · ' + escapeHtml(dev.location) : '') + '</div></div>' +
      '<div class="mini-stats">' +
      '<span class="mini-stat"><b>' + escapeHtml(fmtNum(dev.followers)) + '</b><span>Followers</span></span>' +
      '<span class="mini-stat"><b>' + escapeHtml(fmtNum(dev.publicRepos)) + '</b><span>Repos</span></span>' +
      '<span class="mini-stat"><b>' + escapeHtml(fmtNum(dev.totalStars)) + '</b><span>Stars</span></span>' +
      '</div>' +
      '<div style="width:100%;text-align:left"><h4 class="section-title">languages</h4>' + miniLang(dev) + '</div>' +
      '<div style="width:100%;text-align:left"><h4 class="section-title">skills</h4><div class="chips">' +
      skillChips(dev.skills) + '</div></div>' +
      '<a class="btn btn-sm" href="#/dev/' + dev.userid + '">view profile</a>' +
      '</div>';

    const totalStarsBoth = (devA.totalStars || 0) + (devB.totalStars || 0);
    const pctA = totalStarsBoth ? Math.round((devA.totalStars / totalStarsBoth) * 100) : 0;

    result.innerHTML =
      '<div class="compare-cols">' + col(devA) + '<div class="vs">VS</div>' + col(devB) + '</div>' +
      '<div class="overlap section">' +
      '<div class="overlap-head">' + ICON.sparkles + '<h2>common ground</h2></div>' +
      '<div class="overlap-score">' +
      statCell('Shared skills', data.commonSkills.length) +
      statCell('Shared projects', data.sharedProjects.length) +
      statCell('Shared langs', data.commonLanguages.length) +
      statCell('Combined stars', fmtNum(data.combined.totalStars)) +
      '</div>' +
      '<div class="panel" style="padding:16px;display:grid;gap:14px">' +
      '<div><div class="spread"><h3 class="section-title" style="margin:0">skill overlap</h3>' +
      '<span class="meter-num">' + overlapPct + '%</span></div>' +
      '<div class="meter-track" style="width:100%;max-width:none;margin-top:10px"><span class="meter-fill" style="width:' + overlapPct + '%"></span></div></div>' +
      '<div><div class="spread"><h3 class="section-title" style="margin:0">stars</h3>' +
      '<span class="mono faint" style="font-size:12px">' + escapeHtml(displayNameOf(devA)) + ' · ' +
      escapeHtml(displayNameOf(devB)) + '</span></div>' +
      '<div class="duo-bar" style="margin-top:10px"><span class="a" style="width:' + pctA + '%"></span>' +
      '<span class="b" style="width:' + (100 - pctA) + '%"></span></div>' +
      '<div class="duo-labels"><span>' + escapeHtml(displayNameOf(devA)) + ' ' + fmtNum(devA.totalStars) + '★</span>' +
      '<span>' + fmtNum(devB.totalStars) + '★ ' + escapeHtml(displayNameOf(devB)) + '</span></div></div>' +
      '</div>' +
      '<div><h3 class="section-title">shared projects</h3><div class="stack">' + sharedProjects + '</div></div>' +
      '<div><h3 class="section-title">shared skills</h3>' + commonSkills + '</div>' +
      '<div><h3 class="section-title">shared languages</h3>' + commonLanguages + '</div>' +
      '</div>';
  }

  /* ---------------- Messaging ---------------- */

  async function loadDirectory() {
    if (state.profiles.length) return;
    const payload = await API.profiles({ limit: 100 });
    state.profiles = payload.data || [];
  }

  async function messageUser(userid) {
    if (!isLoggedIn()) return openAuthModal('login');
    if (state.user.userid === Number(userid)) return toast('that is you!', 'info');
    try {
      const result = await API.startConversation({ recipientId: Number(userid) });
      location.hash = '#/messages/' + result.conversationid;
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  function conversationTitle(convo) {
    if (convo.name) return convo.name;
    const names = (convo.participants || []).map(displayNameOf);
    if (!names.length) return 'conversation';
    if (names.length <= 3) return names.join(', ');
    return names.slice(0, 3).join(', ') + ' +' + (names.length - 3);
  }

  function conversationAvatar(convo) {
    const parts = convo.participants || [];
    if (parts.length === 1) return parts[0];
    return { displayName: conversationTitle(convo) };
  }

  function createPeoplePicker(host, candidates, selected, onChange) {
    const xSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';

    host.innerHTML =
      '<div class="ms" tabindex="-1">' +
      '<div class="ms-controls"><div class="ms-tokens"></div>' +
      '<input class="ms-input" type="text" placeholder="type a name or @handle…" autocomplete="off"></div>' +
      '<div class="ms-menu hidden"></div></div>';

    const ms = $('.ms', host);
    const tokens = $('.ms-tokens', host);
    const input = $('.ms-input', host);
    const menu = $('.ms-menu', host);

    const drawTokens = () => {
      tokens.innerHTML = selected.map((user) =>
        '<span class="ms-token">' + escapeHtml(displayNameOf(user)) +
        '<button type="button" class="x" data-id="' + user.userid + '" aria-label="remove">' + xSvg + '</button></span>').join('');
      $$('.ms-token .x', tokens).forEach((btn) =>
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const idx = selected.findIndex((u) => u.userid === Number(btn.dataset.id));
          if (idx >= 0) selected.splice(idx, 1);
          drawTokens();
          onChange();
        }));
    };

    const select = (user) => {
      if (!user || selected.some((u) => u.userid === user.userid)) return;
      selected.push(user);
      input.value = '';
      drawTokens();
      drawMenu();
      onChange();
    };

    const drawMenu = () => {
      const q = input.value.trim().toLowerCase();
      if (!q) { menu.classList.add('hidden'); menu.innerHTML = ''; return; }
      const matches = candidates
        .filter((u) => !selected.some((s) => s.userid === u.userid))
        .filter((u) => u.login.toLowerCase().includes(q) || displayNameOf(u).toLowerCase().includes(q))
        .slice(0, 8);
      if (!matches.length) { menu.classList.add('hidden'); menu.innerHTML = ''; return; }
      menu.innerHTML = matches.map((u) =>
        '<button type="button" class="ms-option" data-id="' + u.userid + '">' +
        avatarHtml(u, 'xs') + ' <span>' + escapeHtml(displayNameOf(u)) + '</span>' +
        '<span class="s-login">@' + escapeHtml(u.login) + '</span></button>').join('');
      menu.classList.remove('hidden');
      $$('.ms-option', menu).forEach((btn) =>
        btn.addEventListener('mousedown', (e) => {
          e.preventDefault();
          select(candidates.find((u) => u.userid === Number(btn.dataset.id)));
          input.focus();
        }));
    };

    input.addEventListener('input', drawMenu);
    input.addEventListener('focus', drawMenu);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const first = menu.querySelector('.ms-option');
        if (first) select(candidates.find((u) => u.userid === Number(first.dataset.id)));
      } else if (e.key === 'Backspace' && input.value === '' && selected.length) {
        selected.pop();
        drawTokens();
        onChange();
      } else if (e.key === 'Escape') {
        menu.classList.add('hidden');
        input.blur();
      }
    });
    ms.addEventListener('click', () => input.focus());
    document.addEventListener('click', (e) => {
      if (!host.contains(e.target)) menu.classList.add('hidden');
    });

    drawTokens();
  }

  function openNewMessageModal() {
    if (!isLoggedIn()) return openAuthModal('login');
    loadDirectory().then(() => {
      const candidates = state.profiles.filter((p) => p.userid !== state.user.userid);
      const root = $('#modal-root');
      root.innerHTML =
        '<div class="modal" id="modal-backdrop"><div class="modal-card">' +
        '<h2>new message</h2><p class="sub">add one person for a direct message, or several to start a group for a project.</p>' +
        '<form class="form-grid" id="new-msg-form">' +
        '<div class="field"><label>to</label><div id="to-picker"></div></div>' +
        '<div class="field" id="group-name-field" style="display:none">' +
        '<label>group name (optional)</label><input class="input" name="name" placeholder="e.g. collab.dev build team"></div>' +
        '<div class="field"><label>message (optional)</label><textarea class="textarea" name="message" placeholder="say hello…"></textarea></div>' +
        '<div class="row"><button class="btn btn-primary flex1" type="submit">start</button>' +
        '<button class="btn btn-ghost" type="button" id="cancel">cancel</button></div>' +
        '</form></div></div>';

      const selected = [];
      createPeoplePicker($('#to-picker'), candidates, selected, () => {
        $('#group-name-field').style.display = selected.length > 1 ? '' : 'none';
      });

      $('#cancel').addEventListener('click', closeModal);
      $('#modal-backdrop').addEventListener('click', (e) => { if (e.target.id === 'modal-backdrop') closeModal(); });

      $('#new-msg-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!selected.length) {
          toast('add at least one person', 'error');
          return;
        }
        const data = Object.fromEntries(new FormData(e.target));
        const payload = selected.length === 1 && !data.name
          ? { recipientId: selected[0].userid, message: data.message || undefined }
          : { participantIds: selected.map((u) => u.userid), name: data.name || undefined, message: data.message || undefined };
        try {
          const result = await API.startConversation(payload);
          closeModal();
          location.hash = '#/messages/' + result.conversationid;
        } catch (err) {
          toast(err.message, 'error');
        }
      });

      if (!candidates.length) {
        $('#new-msg-form').innerHTML = '<p class="muted">no other developers to message yet.</p>';
      }
    });
  }

  function openAddPeopleModal(conversationid, existingIds, onDone) {
    loadDirectory().then(() => {
      const candidates = state.profiles.filter((p) =>
        p.userid !== state.user.userid && !existingIds.includes(p.userid));
      const root = $('#modal-root');
      root.innerHTML =
        '<div class="modal" id="modal-backdrop"><div class="modal-card">' +
        '<h2>add people</h2><p class="sub">invite more developers to this conversation.</p>' +
        '<form class="form-grid" id="add-people-form">' +
        '<div class="field"><label>people</label><div id="add-picker"></div></div>' +
        '<div class="row"><button class="btn btn-primary flex1" type="submit">add</button>' +
        '<button class="btn btn-ghost" type="button" id="cancel">cancel</button></div>' +
        '</form></div></div>';

      const selected = [];
      createPeoplePicker($('#add-picker'), candidates, selected, () => {});

      $('#cancel').addEventListener('click', closeModal);
      $('#modal-backdrop').addEventListener('click', (e) => { if (e.target.id === 'modal-backdrop') closeModal(); });
      $('#add-people-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!selected.length) {
          toast('add at least one person', 'error');
          return;
        }
        try {
          await API.addParticipants(conversationid, selected.map((u) => u.userid));
          closeModal();
          toast('people added', 'success');
          if (onDone) onDone();
        } catch (err) {
          toast(err.message, 'error');
        }
      });

      if (!candidates.length) {
        $('#add-people-form').innerHTML = '<p class="muted">everyone is already in this conversation.</p>';
      }
    });
  }

  async function viewMessages(root, conversationid) {
    if (!requireGate(root)) return;

    root.innerHTML =
      '<div class="container">' +
      '<div class="spread" style="margin-bottom:12px"><h1 class="page-title">messages</h1>' +
      '<button class="btn btn-primary btn-sm" id="new-msg-top">' + ICON.plus + ' new message</button></div>' +
      '<div class="messages section"><div class="convo-list">' +
      Array.from({ length: 4 }, () => '<div class="skeleton sk-line" style="height:56px;margin:10px"></div>').join('') +
      '</div><div class="thread"></div></div></div>';

    $('#new-msg-top').addEventListener('click', openNewMessageModal);

    state.convos = await API.conversations() || [];
    drawConvoList();

    if (conversationid) {
      await drawThread(Number(conversationid));
    } else {
      $('.thread').innerHTML = emptyState(ICON.mail, 'Messages',
        'select a conversation or start a new one.',
        '<button class="btn btn-primary" id="new-convo">' + ICON.plus + ' new message</button>');
      $('#new-convo').addEventListener('click', openNewMessageModal);
    }
  }

  function drawConvoList() {
    const list = $('.convo-list');
    if (!list) return;
    if (!state.convos.length) {
      list.innerHTML = '<div class="empty"><p class="muted">no conversations yet.</p>' +
        '<button class="btn btn-primary btn-sm" id="new-convo-2">' + ICON.plus + ' new message</button></div>';
      const btn = $('#new-convo-2');
      if (btn) btn.addEventListener('click', openNewMessageModal);
      return;
    }
    const active = parseHash().parts[1];
    list.innerHTML = state.convos.map((convo) => {
      const title = conversationTitle(convo);
      const av = conversationAvatar(convo);
      return '<div class="convo-item' + (String(convo.conversationid) === String(active) ? ' active' : '') +
        '" data-id="' + convo.conversationid + '">' + avatarHtml(av, 'sm') +
        '<div class="convo-main"><div class="convo-name">' + escapeHtml(title) +
        (convo.isGroup ? ' <span class="badge">group</span>' : '') + '</div>' +
        '<div class="convo-preview">' + escapeHtml(convo.lastMessage || 'no messages yet') + '</div></div>' +
        '<div class="convo-side">' + (convo.unreadCount ? '<span class="unread">' + convo.unreadCount + '</span>' : '<span class="convo-time">' + escapeHtml(timeAgo(convo.lastMessageAt)) + '</span>') + '</div></div>';
    }).join('');
    $$('.convo-item', list).forEach((item) =>
      item.addEventListener('click', () => { location.hash = '#/messages/' + item.dataset.id; }));
  }

  async function refreshConversation(conversationid) {
    state.convos = await API.conversations() || [];
    drawConvoList();
    await drawThread(conversationid);
  }

  async function drawThread(conversationid) {
    const thread = $('.thread');
    if (!thread) return;

    const convo = state.convos.find((c) => Number(c.conversationid) === conversationid);
    if (!convo) {
      thread.innerHTML = emptyState('!', 'conversation not found', 'you are not part of this conversation.');
      return;
    }

    const title = conversationTitle(convo);
    const av = conversationAvatar(convo);
    const isGroup = !!convo.isGroup;
    const others = convo.participants || [];
    const participantIds = others.map((p) => p.userid);

    const messages = await API.messages(conversationid) || [];
    await API.markRead(conversationid).catch(() => {});
    const idx = state.convos.indexOf(convo);
    if (idx >= 0) state.convos[idx].unreadCount = 0;

    const bubbles = messages.length
      ? messages.map((m) => {
          const mine = Number(m.sender_userid) === state.user.userid;
          const sender = m.sender_displayname || m.sender_login || '';
          return '<div class="bubble' + (mine ? ' me' : '') + '">' +
            (!mine && isGroup ? '<span class="bubble-sender">' + escapeHtml(sender) + '</span>' : '') +
            escapeHtml(m.body) +
            '<span class="time">' + escapeHtml(timeAgo(m.created_at)) + '</span></div>';
        }).join('')
      : '<div class="empty"><p class="muted">no messages yet. say hello!</p></div>';

    const subtitle = isGroup
      ? (others.length + 1) + ' people'
      : (others[0] ? '@' + escapeHtml(others[0].login || '') : '');

    thread.innerHTML =
      '<div class="thread-head">' + avatarHtml(av, 'sm') +
      '<div class="thread-id"><div class="convo-name">' + escapeHtml(title) +
      (isGroup ? ' <span class="badge">group</span>' : '') + '</div>' +
      '<div class="convo-preview">' + subtitle + '</div></div>' +
      '<div class="thread-actions">' +
      '<button class="btn btn-sm" id="add-people-btn">' + ICON.plus + ' people</button>' +
      (isGroup ? '<button class="btn btn-sm btn-ghost" id="rename-btn">rename</button>' : '') +
      '</div></div>' +
      '<div class="thread-body" id="thread-body">' + bubbles + '</div>' +
      '<form class="composer" id="composer">' +
      '<textarea class="textarea flex1" id="composer-input" placeholder="write a message…" rows="1"></textarea>' +
      '<button class="btn btn-primary btn-icon" type="submit" title="send">' + ICON.send + '</button>' +
      '</form>';

    const body = $('#thread-body');
    body.scrollTop = body.scrollHeight;
    drawConvoList();

    $('#add-people-btn').addEventListener('click', () =>
      openAddPeopleModal(conversationid, participantIds, () => refreshConversation(conversationid)));

    const renameBtn = $('#rename-btn');
    if (renameBtn) {
      renameBtn.addEventListener('click', async () => {
        const name = prompt('group name', convo.name || '');
        if (name === null) return;
        try {
          await API.renameConversation(conversationid, name);
          refreshConversation(conversationid);
        } catch (err) {
          toast(err.message, 'error');
        }
      });
    }

    const input = $('#composer-input');
    input.focus();
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        $('#composer').requestSubmit();
      }
    });
    $('#composer').addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      try {
        await API.sendMessage(conversationid, text);
        drawThread(conversationid);
      } catch (err) {
        toast(err.message, 'error');
        input.value = text;
      }
    });
  }

  /* ---------------- Settings (profile editor) ---------------- */

  async function viewSettings(root, query) {
    if (!requireGate(root)) return;
    const tab = query.tab || 'profile';
    const userid = state.user.userid;

    root.innerHTML =
      '<div class="container">' +
      '<h1 class="page-title">settings</h1>' +
      '<div class="tabs settings-tabs">' +
      ['profile', 'skills', 'links', 'github', 'account'].map((t) =>
        '<button class="tab' + (t === tab ? ' active' : '') + '" data-tab="' + t + '">' + t + '</button>').join('') +
      '</div>' +
      '<div id="settings-panel" class="section"></div></div>';

    $$('.settings-tabs .tab', root).forEach((btn) =>
      btn.addEventListener('click', () => { location.hash = '#/settings?tab=' + btn.dataset.tab; }));

    const panel = $('#settings-panel');
    panel.innerHTML = skeleton(3);

    if (tab === 'profile') await settingsProfile(panel, userid);
    else if (tab === 'skills') await settingsSkills(panel, userid);
    else if (tab === 'links') await settingsLinks(panel, userid);
    else if (tab === 'github') await settingsGithub(panel, userid);
    else if (tab === 'account') settingsAccount(panel);
    else panel.innerHTML = emptyState('?', 'unknown tab', 'Pick a settings tab.');
  }

  async function settingsProfile(panel, userid) {
    const p = await API.profile(userid);
    panel.innerHTML =
      '<form class="form-grid panel" style="padding:20px" id="profile-form">' +
      '<div class="field"><label>display name</label><input class="input" name="displayName" value="' + escapeHtml(p.displayName || '') + '"></div>' +
      '<div class="row">' +
      '<div class="field flex1"><label>first name</label><input class="input" name="firstName" value="' + escapeHtml(p.firstName || '') + '"></div>' +
      '<div class="field flex1"><label>last name</label><input class="input" name="lastName" value="' + escapeHtml(p.lastName || '') + '"></div>' +
      '</div>' +
      '<div class="row">' +
      '<div class="field flex1"><label>job title</label><input class="input" name="jobTitle" value="' + escapeHtml(p.jobTitle || '') + '"></div>' +
      '<div class="field flex1"><label>location</label><input class="input" name="location" value="' + escapeHtml(p.location || '') + '"></div>' +
      '</div>' +
      '<div class="field"><label>bio</label><textarea class="textarea" name="bio">' + escapeHtml(p.bio || '') + '</textarea></div>' +
      '<div class="field"><label>avatar</label>' +
      '<div class="avatar-preview">' + avatarHtml(p, 'sm') +
      '<span class="faint mono">pulled from GitHub — update it on github.com</span></div></div>' +
      '<div class="field"><label>resume url</label><input class="input" name="resumeUrl" value="' + escapeHtml(p.resumeUrl || '') + '"></div>' +
      '<button class="btn btn-primary" type="submit">save profile</button>' +
      '</form>';

    $('#profile-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fields = Object.fromEntries(new FormData(e.target));
      try {
        await API.updateProfile(userid, fields);
        toast('profile updated', 'success');
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  async function settingsSkills(panel, userid) {
    const [catalog, mine] = await Promise.all([API.skills(), API.userSkills(userid)]);
    const rows = (mine || []).map((s) => ({ skillid: s.skillid, name: s.name, proficiency: s.proficiency || '' }));
    const catalogNames = (catalog || []).map((s) => s.name);

    panel.innerHTML =
      '<div class="panel editor">' +
      '<div class="field"><label>your skills</label>' +
      '<div id="skill-rows" class="skill-list"></div></div>' +
      '<div class="field"><label>add a skill</label>' +
      '<div class="add-skill">' +
      '<div class="autocomplete flex1">' +
      '<input class="input" id="new-skill" placeholder="start typing a skill…" autocomplete="off">' +
      '<div class="suggestions hidden" id="skill-suggest"></div>' +
      '</div>' +
      '<button class="btn btn-primary" id="add-skill">' + ICON.plus + ' add</button>' +
      '</div></div>' +
      '<div class="editor-actions"><button class="btn btn-primary" id="save-skills">save skills</button></div>' +
      '</div>';

    const drawRows = () => {
      const host = $('#skill-rows');
      if (!rows.length) {
        host.innerHTML = '<span class="empty-inline">no skills yet — add one below.</span>';
        return;
      }
      host.innerHTML = rows.map((r, i) =>
        '<div class="skill-edit-row">' +
        '<span class="skill-edit-name"><span class="dot" style="background:' + langColor(r.name) + '"></span>' +
        '<span class="ellipsis">' + escapeHtml(r.name) + '</span></span>' +
        '<select class="select" data-i="' + i + '" aria-label="proficiency">' +
        ['', 'beginner', 'intermediate', 'advanced', 'expert'].map((lvl) =>
          '<option value="' + lvl + '"' + (r.proficiency === lvl ? ' selected' : '') + '>' + (lvl || 'no level') + '</option>').join('') +
        '</select>' +
        '<button type="button" class="btn btn-sm btn-ghost remove-btn" data-remove="' + i + '" title="remove skill">' + ICON.x + '</button>' +
        '</div>').join('');

      $$('select[data-i]', host).forEach((sel) =>
        sel.addEventListener('change', () => { rows[Number(sel.dataset.i)].proficiency = sel.value; }));
      $$('[data-remove]', host).forEach((btn) =>
        btn.addEventListener('click', () => { rows.splice(Number(btn.dataset.remove), 1); drawRows(); }));
    };
    drawRows();

    const input = $('#new-skill');
    const suggest = $('#skill-suggest');
    const closeSuggest = () => { suggest.classList.add('hidden'); suggest.innerHTML = ''; };

    const renderSuggest = () => {
      const raw = input.value.trim();
      const q = raw.toLowerCase();
      closeSuggest();
      if (!q) return;

      const used = (name) => rows.some((r) => r.name.toLowerCase() === name.toLowerCase());
      const matches = catalogNames
        .filter((name) => name.toLowerCase().includes(q) && !used(name))
        .slice(0, 8);

      const items = matches.map((name) =>
        '<button type="button" class="suggestion" data-name="' + escapeHtml(name) + '">' +
        '<span class="s-name">' + escapeHtml(name) + '</span>' +
        '<span class="s-login">catalog</span></button>');

      if (!used(raw) && !catalogNames.some((n) => n.toLowerCase() === q) && /^[A-Za-z0-9 .+#/-]{1,50}$/.test(raw)) {
        items.push('<button type="button" class="suggestion" data-new="1">' +
          '<span class="s-name">create “' + escapeHtml(raw) + '”</span></button>');
      }

      if (!items.length) return;
      suggest.innerHTML = items.join('');
      suggest.classList.remove('hidden');
    };

    const addSkill = (name) => {
      const value = String(name).trim();
      if (!value) return;
      if (rows.some((r) => r.name.toLowerCase() === value.toLowerCase())) {
        toast('already added', 'info');
        return;
      }
      const found = (catalog || []).find((s) => s.name.toLowerCase() === value.toLowerCase());
      rows.push({ skillid: found ? found.skillid : undefined, name: found ? found.name : value, proficiency: '' });
      input.value = '';
      closeSuggest();
      drawRows();
    };

    input.addEventListener('input', renderSuggest);
    input.addEventListener('focus', renderSuggest);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const first = suggest.querySelector('.suggestion');
        if (first) first.click();
        else addSkill(input.value);
      } else if (e.key === 'Escape') {
        closeSuggest();
        input.blur();
      }
    });
    suggest.addEventListener('mousedown', (e) => {
      const btn = e.target.closest('.suggestion');
      if (!btn) return;
      e.preventDefault();
      if (btn.dataset.new) addSkill(input.value); else addSkill(btn.dataset.name);
      input.focus();
    });
    document.addEventListener('click', (e) => { if (!panel.contains(e.target)) closeSuggest(); });

    $('#add-skill').addEventListener('click', () => addSkill(input.value));

    $('#save-skills').addEventListener('click', async () => {
      try {
        await API.setSkills(userid, rows.map((r, i) => ({
          skillid: r.skillid,
          name: r.name,
          proficiency: r.proficiency || undefined,
          displayOrder: i,
        })));
        toast('skills updated', 'success');
        viewSettings($('#app'), { tab: 'skills' });
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  async function settingsLinks(panel, userid) {
    const links = await API.profile(userid).then((p) => p.socialLinks || []);
    panel.innerHTML =
      '<div class="panel editor">' +
      '<div class="field"><label>your links</label>' +
      '<div id="link-rows" class="skill-list"></div></div>' +
      '<div class="field"><label>add a link</label>' +
      '<div class="add-skill">' +
      '<input class="input" id="link-platform" placeholder="platform" style="flex:0 0 170px">' +
      '<input class="input" id="link-url" placeholder="https://…" style="flex:1">' +
      '<button class="btn btn-primary" id="add-link">' + ICON.plus + ' add</button>' +
      '</div></div></div>';

    const draw = () => {
      $('#link-rows').innerHTML = links.length ? links.map((l) =>
        '<div class="link-edit-row">' +
        '<a class="skill-edit-name ellipsis" href="' + escapeHtml(l.url) + '" target="_blank" rel="noopener">' + escapeHtml(l.platform) + '</a>' +
        '<span class="mono faint ellipsis">' + escapeHtml(l.url) + '</span>' +
        '<button class="btn btn-sm btn-ghost remove-btn" data-remove="' + l.linkid + '" title="remove link">' + ICON.x + '</button>' +
        '</div>').join('') : '<span class="empty-inline">no links yet.</span>';

      $$('[data-remove]', $('#link-rows')).forEach((btn) =>
        btn.addEventListener('click', async () => {
          try {
            await API.removeSocialLink(userid, btn.dataset.remove);
            toast('link removed', 'success');
            viewSettings($('#app'), { tab: 'links' });
          } catch (err) { toast(err.message, 'error'); }
        }));
    };
    draw();

    $('#add-link').addEventListener('click', async () => {
      const platform = $('#link-platform').value.trim();
      const url = $('#link-url').value.trim();
      if (!platform || !url) return toast('platform and url are required', 'error');
      try {
        await API.addSocialLink(userid, { platform, url });
        toast('link added', 'success');
        viewSettings($('#app'), { tab: 'links' });
      } catch (err) { toast(err.message, 'error'); }
    });
  }

  async function settingsGithub(panel, userid) {
    let gh = null;
    try { gh = await API.github(userid); } catch (e) { gh = null; }

    panel.innerHTML =
      '<div class="panel" style="padding:20px;display:grid;gap:14px">' +
      '<div><div class="section-title">linked account</div>' +
      '<div class="row">' +
      '<input class="input flex1" id="gh-username" placeholder="GitHub username" value="' + escapeHtml(gh ? gh.username : '') + '">' +
      '<button class="btn btn-primary" id="gh-sync">' + ICON.refresh + ' refresh now</button>' +
      '</div>' +
      '<p class="home-hint">' + (gh && gh.lastSynced ? 'last synced ' + escapeHtml(timeAgo(gh.lastSynced)) : 'never synced') + '</p>' +
      '</div>' +
      (gh ? '<div class="stat-grid">' +
        statCell('Followers', fmtNum(gh.followers)) +
        statCell('Repositories', fmtNum(gh.publicRepos)) +
        statCell('Public gists', fmtNum(gh.publicGists)) +
        statCell('Repos cached', fmtNum((gh.repositories || []).length)) +
        '</div>' : '') +
      '</div>';

    $('#gh-sync').addEventListener('click', async () => {
      const username = $('#gh-username').value.trim();
      const btn = $('#gh-sync');
      btn.disabled = true;
      try {
        await API.syncGithub(username ? { username } : {});
        toast('GitHub refreshed', 'success');
        viewSettings($('#app'), { tab: 'github' });
      } catch (err) {
        toast(err.message, 'error');
        btn.disabled = false;
      }
    });
  }

  function settingsAccount(panel) {
    panel.innerHTML =
      '<form class="form-grid panel" style="padding:20px" id="account-form">' +
      '<div class="field"><label>email</label><input class="input" name="email" type="email" value="' + escapeHtml(state.user.email || '') + '"></div>' +
      '<div class="field"><label>current password</label><input class="input" name="currentPassword" type="password" autocomplete="current-password" required></div>' +
      '<div class="field"><label>new password (leave blank to keep)</label><input class="input" name="newPassword" type="password" autocomplete="new-password" minlength="8"></div>' +
      '<button class="btn btn-primary" type="submit">update account</button>' +
      '</form>';

    $('#account-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(e.target));
      const payload = { currentPassword: data.currentPassword };
      if (data.email && data.email !== state.user.email) payload.email = data.email;
      if (data.newPassword) payload.newPassword = data.newPassword;
      try {
        await API.updateAccount(payload);
        toast('account updated', 'success');
        state.user = await API.session();
        renderProfileSlot();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  /* ---------------- Admin ---------------- */

  async function viewAdmin(root, query) {
    if (!requireGate(root)) return;
    if (!state.user.isAdmin) {
      root.innerHTML = '<div class="container">' + emptyState(ICON.shield, 'admins only', 'You do not have access to the admin dashboard.') + '</div>';
      return;
    }

    const tab = query.tab || 'overview';
    root.innerHTML =
      '<div class="container">' +
      '<h1 class="page-title">' + ICON.shield + ' admin</h1>' +
      '<div class="tabs settings-tabs">' +
      '<button class="tab' + (tab === 'overview' ? ' active' : '') + '" data-tab="overview">overview</button>' +
      '<button class="tab' + (tab === 'users' ? ' active' : '') + '" data-tab="users">users</button>' +
      '<button class="tab' + (tab === 'contacts' ? ' active' : '') + '" data-tab="contacts">contacts</button>' +
      '</div>' +
      '<div id="admin-panel" class="section"></div></div>';

    $$('.settings-tabs .tab', root).forEach((btn) =>
      btn.addEventListener('click', () => { location.hash = '#/admin?tab=' + btn.dataset.tab; }));

    const panel = $('#admin-panel');
    panel.innerHTML = skeleton(3);

    if (tab === 'contacts') await adminContactList(panel, query);
    else if (tab === 'users') await adminUsers(panel, query);
    else await adminOverview(panel);
  }

  async function adminOverview(panel) {
    try {
      const s = await API.adminStats();
      panel.innerHTML =
        '<div class="admin-grid">' +
        statCard('Users', s.users.total, s.users.active + ' active · ' + s.users.inactive + ' disabled') +
        statCard('New · 7d', s.users.new7, s.users.new30 + ' in 30d') +
        statCard('Linked GitHub', s.github.linked, s.github.stale + ' stale · ' + s.github.repos + ' repos') +
        statCard('Messages', s.messaging.messages, s.messaging.conversations + ' conversations') +
        '</div>' +
        '<div class="admin-cols">' +
        '<div class="panel" style="padding:20px"><h3 class="section-title">top skills</h3>' +
        (s.skills.top.length ? s.skills.top.map((r) =>
          '<div class="legend-item"><span class="lname">' + escapeHtml(r.name) + '</span><span class="lcount">' + r.developers + '</span></div>').join('') :
          '<span class="empty-inline">no skills.</span>') + '</div>' +
        '<div class="panel" style="padding:20px"><h3 class="section-title">top languages</h3>' +
        (s.languages.length ? s.languages.map((r) =>
          '<div class="legend-item"><span class="dot" style="background:' + langColor(r.language) + '"></span>' +
          '<span class="lname">' + escapeHtml(r.language) + '</span><span class="lcount">' + r.developers + '</span></div>').join('') :
          '<span class="empty-inline">no languages.</span>') + '</div>' +
        '</div>' +
        '<div class="panel" style="padding:20px;margin-top:20px"><h3 class="section-title">recent signups</h3>' +
        '<table class="data-table"><thead><tr><th>login</th><th>name</th><th>email</th><th>status</th><th>joined</th></tr></thead><tbody>' +
        s.recentSignups.map((u) =>
          '<tr><td>@' + escapeHtml(u.loginuid) + '</td><td>' + escapeHtml(u.displayname || '') + '</td>' +
          '<td>' + escapeHtml(u.email) + '</td><td>' + (Number(u.isactive) ? 'active' : 'disabled') + '</td>' +
          '<td>' + escapeHtml(timeAgo(u.created_at)) + '</td></tr>').join('') +
        '</tbody></table></div>';
    } catch (err) {
      panel.innerHTML = emptyState('!', 'could not load stats', err.message);
    }
  }

  function statCard(label, value, sub) {
    return '<div class="stat-card"><div class="stat-value">' + escapeHtml(value) + '</div>' +
      '<div class="stat-label">' + escapeHtml(label) + '</div>' +
      (sub ? '<div class="stat-sub">' + escapeHtml(sub) + '</div>' : '') + '</div>';
  }

  async function adminUsers(panel, query) {
    const q = query.q || '';
    const status = query.status || '';
    const page = Math.max(1, parseInt(query.page || '1', 10) || 1);

    panel.innerHTML =
      '<div class="spread wrap"><h2 class="section-title">Manage users</h2><button class="btn btn-primary" id="create-admin">New administrator</button></div>' +
      '<form class="row wrap mt" id="admin-search">' +
      '<input class="input flex1" id="admin-q" aria-label="Search users" placeholder="search login, name or email" value="' + escapeHtml(q) + '">' +
      '<select class="select" id="admin-status" aria-label="Account status">' +
      '<option value="">all</option>' +
      '<option value="active"' + (status === 'active' ? ' selected' : '') + '>active</option>' +
      '<option value="inactive"' + (status === 'inactive' ? ' selected' : '') + '>disabled</option>' +
      '<option value="admin"' + (status === 'admin' ? ' selected' : '') + '>admins</option>' +
      '</select>' +
      '<button class="btn btn-primary" type="submit">search</button>' +
      '</form>' +
      '<div id="admin-users" class="section"></div>';

    $('#create-admin').addEventListener('click', () => createAdmin(() => panel.isConnected && adminUsers(panel,query)));
    const go = (overrides) => {
      const params = new URLSearchParams({ tab: 'users' });
      const next = Object.assign({ q, status, page: '1' }, overrides);
      if (next.q) params.set('q', next.q);
      if (next.status) params.set('status', next.status);
      if (Number(next.page) > 1) params.set('page', next.page);
      location.hash = '#/admin?' + params.toString();
    };
    $('#admin-search').addEventListener('submit', (e) => {
      e.preventDefault();
      go({ q: $('#admin-q').value.trim(), status: $('#admin-status').value, page: '1' });
    });

    const host = $('#admin-users');
    host.innerHTML = skeleton(4);
    try {
      const payload = await API.adminUsers({ q, status, page, limit: 20 });
      if (!host.isConnected) return;
      const users = payload.data || [];
      const meta = payload.meta || { total: users.length, page: 1, limit: 20 };
      const pages = Math.max(1, Math.ceil(meta.total / meta.limit));

      host.innerHTML =
        '<div class="results-count">' + meta.total + ' users</div>' +
        '<div class="table-scroll" tabindex="0" role="region" aria-label="Users"><table class="data-table"><thead><tr>' +
        '<th>login</th><th>name</th><th>email</th><th>github</th><th>status</th><th>actions</th></tr></thead><tbody>' +
        users.map((u) =>
          '<tr' + (u.isActive ? '' : ' class="row-disabled"') + '>' +
          '<td>@' + escapeHtml(u.login) + (u.isAdmin ? ' <span class="badge accent">admin</span>' : '') + '</td>' +
          '<td>' + escapeHtml(u.displayName || '') + '</td>' +
          '<td class="mono">' + escapeHtml(u.email) + '</td>' +
          '<td>' + (u.githubUsername ? escapeHtml(u.githubUsername) : '<span class="faint">—</span>') + '</td>' +
          '<td>' + (u.isActive ? 'active' : 'disabled') + '</td>' +
          '<td class="row-actions">' +
          '<a class="btn btn-sm btn-ghost" href="#/dev/' + u.userid + '">view</a>' +
          '<a class="btn btn-sm" href="#/admin?tab=contacts&amp;userid=' + u.userid + '">contacts</a>' +
          '<button class="btn btn-sm" data-password="' + u.userid + '">change password</button>' +
          '<button class="btn btn-sm" data-reset="' + u.userid + '">reset link</button>' +
          (Number(u.userid) === Number(state.user.userid) ? '<span class="faint">current account</span>' :
            (u.isActive
              ? '<button class="btn btn-sm btn-danger" data-disable="' + u.userid + '">disable</button>'
              : '<button class="btn btn-sm" data-enable="' + u.userid + '">enable</button>')) +
          '</td></tr>').join('') +
        '</tbody></table></div>' +
        (pages > 1 ? '<div class="pager">' +
          '<button class="btn btn-sm" id="prev-page"' + (page <= 1 ? ' disabled' : '') + '>' + ICON.back + ' prev</button>' +
          '<span class="mono faint">page ' + page + ' / ' + pages + '</span>' +
          '<button class="btn btn-sm" id="next-page"' + (page >= pages ? ' disabled' : '') + '>next</button>' +
          '</div>' : '');

      if (!users.length) host.innerHTML = emptyState(ICON.search, 'No matching users', 'Try a different search or account status.');
      $$('[data-disable], [data-enable]', host).forEach(btn => btn.addEventListener('click', () => {
        const id = btn.dataset.disable || btn.dataset.enable;
        const disabling = !!btn.dataset.disable;
        const user = users.find(u => String(u.userid) === id);
        formDialog(disabling ? 'Disable account?' : 'Enable account?',
          (disabling ? 'Sign out and suspend @' : 'Restore access for @') + user.login + '? The account and its contacts will be kept.', '',
          disabling ? 'Disable account' : 'Enable account', async () => {
            if (disabling) await API.adminDisable(id); else await API.adminEnable(id);
            toast(disabling ? 'Account disabled' : 'Account enabled','success');
            if (panel.isConnected) await adminUsers(panel,query);
          });
      }));
      $$('[data-password]', host).forEach(btn => btn.addEventListener('click', () => {
        const user = users.find(u => String(u.userid) === btn.dataset.password);
        formDialog('Change password','Set a new password for @' + user.login + '. Existing sessions will be signed out.',passwordFields(),'Change password',async data => {
          checkPassword(data);
          await API.adminPassword(user.userid,data.newPassword);
          toast('Password changed','success');
          if (Number(user.userid) === Number(state.user.userid)) {
            API.setToken(null); state.user = null; renderProfileSlot(); location.hash = '#/'; await render();
          }
        });
      }));
      $$('[data-reset]', host).forEach((btn) => btn.addEventListener('click', () => showResetLink(btn.dataset.reset)));

      const prev = $('#prev-page');
      if (prev) prev.addEventListener('click', () => go({ page: String(page - 1) }));
      const next = $('#next-page');
      if (next) next.addEventListener('click', () => go({ page: String(page + 1) }));
    } catch (err) {
      host.innerHTML = emptyState('!', 'could not load users', err.message);
    }
  }

  function createAdmin(onSaved) {
    const fields = field('login','Username',{required:true,minlength:3,maxlength:50,autocomplete:'off'}) +
      field('email','Email',{type:'email',required:true,maxlength:255,autocomplete:'off'}) +
      field('firstName','First name',{required:true,maxlength:50}) + field('lastName','Last name',{required:true,maxlength:50}) + passwordFields();
    formDialog('New administrator','This account will be able to manage users and view their contacts.',fields,'Create administrator',async data => {
      checkPassword(data);
      const {newPassword,confirmPassword,...profile} = data;
      await API.adminCreateUser({...profile,password:newPassword});
      toast('Administrator created','success');
      await onSaved();
    });
  }

  async function adminContactList(panel, query) {
    const userid = /^\d+$/.test(query.userid || '') ? query.userid : '';
    panel.innerHTML = '<h2 class="section-title">' + (userid ? 'Contacts belonging to user #' + userid : 'All users’ contacts') + '</h2>' +
      '<form class="contact-search" id="admin-contact-search">' +
      '<input class="input" type="search" name="q" aria-label="Search all contacts" placeholder="Search contact name, email, phone or notes" maxlength="100" value="' + escapeHtml(query.q || '') + '">' +
      '<button class="btn" type="submit">Search</button></form>' +
      (userid ? '<a class="text-button" href="#/admin?tab=contacts">Show all users’ contacts</a>' : '') + '<div id="admin-contact-results" aria-live="polite" class="section"></div>';
    const form = $('#admin-contact-search');
    const results = $('#admin-contact-results');
    let generation = 0;
    async function load(page = 1) {
      const current = ++generation;
      results.innerHTML = skeleton(3);
      try {
        const payload = await API.adminContacts({q:form.elements.q.value.trim(),userid,page,limit:20});
        if (!results.isConnected || current !== generation) return;
        const contacts = payload.data || [];
        if (!Array.isArray(contacts) || contacts.length > 20) throw new Error('The contact list could not be loaded.');
        if (!contacts.length) { results.innerHTML = emptyState(ICON.search,'No matching contacts','Try a different search.'); return; }
        results.innerHTML = '<div class="table-scroll" tabindex="0" role="region" aria-label="All contacts"><table class="data-table"><thead><tr><th>Owner</th><th>Contact</th><th>Email</th><th>Phone</th><th>Notes</th></tr></thead><tbody>' +
          contacts.map(c => '<tr><td>' + escapeHtml(c.ownerLogin || c.ownerName || ('User #' + c.ownerId)) + '</td><td>' + escapeHtml(contactName(c)) + '</td><td>' + escapeHtml(c.email) + '</td><td>' + escapeHtml(c.phone) + '</td><td>' + escapeHtml(c.description) + '</td></tr>').join('') + '</tbody></table></div>';
        pagination(results,payload.meta,load);
      } catch (err) {
        if (!results.isConnected || current !== generation) return;
        results.innerHTML = emptyState('!','Could not load contacts',err.message,'<button class="btn" id="retry-admin-contacts">Retry</button>');
        $('#retry-admin-contacts',results).addEventListener('click',() => load(page));
      }
    }
    const schedule = debounce(() => { if (results.isConnected) load(1); },300);
    form.addEventListener('submit',e => { e.preventDefault(); schedule.cancel(); load(1); });
    form.elements.q.addEventListener('input',schedule);
    await load();
  }

  async function showResetLink(userid) {
    formDialog('Create password reset link','Generate a single-use link for this user. The link expires in 60 minutes.','','Generate link',async () => {
      const result = await API.adminReset(userid);
      const source = new URL(result.resetUrl,location.href);
      const token = new URLSearchParams(source.hash.split('?')[1] || '').get('token');
      if (!token) throw new Error('The server did not return a valid reset link.');
      const url = location.origin + location.pathname + '#/reset?token=' + encodeURIComponent(token);
      // Replace the generation form after it completes so errors remain visible.
      setTimeout(() => {
        formDialog('Password reset link','Give this link only to the account owner. It expires in 60 minutes.',
          field('resetUrl','Reset link',{readonly:true},url),'Done',async () => {},
          '<button class="btn mt" type="button" id="copy-reset">Copy link</button>');
        $('#copy-reset').addEventListener('click',async () => {
          try { await navigator.clipboard.writeText(url); toast('Link copied','success'); }
          catch (_) { $('#field-resetUrl').select(); toast('Select and copy the link','info'); }
        });
      },0);
    });
  }

  /* ---------------- Password reset ---------------- */

  async function viewReset(root, query) {
    const token = query.token || '';
    root.innerHTML =
      '<div class="home"><div class="home-inner" style="max-width:420px">' +
      '<h1 class="home-title">set a new password</h1>' +
      '<p class="home-sub" id="reset-msg">checking your link…</p>' +
      '<form class="form-grid" id="reset-form" style="display:none">' +
      '<div class="field"><label for="reset-new">new password</label><input id="reset-new" class="input" name="newPassword" type="password" minlength="8" required></div>' +
      '<div class="field"><label for="reset-confirm">confirm password</label><input id="reset-confirm" class="input" name="confirm" type="password" minlength="8" required></div>' +
      '<button class="btn btn-primary" type="submit">update password</button>' +
      '</form></div></div>';

    if (!token) {
      $('#reset-msg').textContent = 'this reset link is missing its token.';
      return;
    }

    try {
      await API.validateReset(token);
      $('#reset-msg').textContent = 'choose a new password for your account.';
      $('#reset-form').style.display = '';
    } catch (err) {
      $('#reset-msg').textContent = err.message;
      return;
    }

    $('#reset-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(e.target));
      if (data.newPassword !== data.confirm) return toast('passwords do not match', 'error');
      try {
        const button = e.target.querySelector('[type=submit]');
        if (button.disabled) return;
        checkPassword({newPassword:data.newPassword,confirmPassword:data.confirm});
        button.disabled = true;
        await API.resetPassword(token, data.newPassword);
        API.setToken(null); state.user = null; renderProfileSlot();
        toast('Password updated. Sign in with your new password.', 'success');
        location.hash = '#/';
      } catch (err) {
        toast(err.message, 'error');
      } finally {
        const button = e.target.querySelector('[type=submit]');
        if (button) button.disabled = false;
      }
    });
  }

  /* ---------------- Theme ---------------- */

  function applyTheme(theme) {
    if (theme === 'light' || theme === 'dark') {
      document.documentElement.setAttribute('data-theme', theme);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    const btn = $('#theme-toggle');
    const dark = theme === 'dark' ||
      (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
    btn.innerHTML = dark ? ICON.sun : ICON.moon;
  }

  function initTheme() {
    const stored = localStorage.getItem('collab.theme');
    applyTheme(stored || '');
    $('#theme-toggle').addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme');
      const dark = current ? current === 'dark'
        : window.matchMedia('(prefers-color-scheme: dark)').matches;
      const next = dark ? 'light' : 'dark';
      localStorage.setItem('collab.theme', next);
      applyTheme(next);
    });
  }

  /* ---------------- Boot ---------------- */

  window.addEventListener('hashchange', () => { closeModal(); render(); });
  window.addEventListener('session-expired', () => {
    state.user = null; renderProfileSlot();
    toast('Your session ended. Please sign in again.', 'error');
    render();
  });

  (async function init() {
    initTheme();
    await resolveSession();
    renderProfileSlot();
    await render();
  })();
})();
