/* ============================================================
   DevBio — front-end application
   Minimal, hard-edged, auth-gated directory.
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
    return function () {
      const args = arguments;
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), wait);
    };
  }

  function langColor(lang) {
    if (!lang) return '#8a8e98';
    let hash = 0;
    for (let i = 0; i < lang.length; i++) hash = (hash * 31 + lang.charCodeAt(i)) >>> 0;
    return 'hsl(' + (hash % 360) + ' 30% 52%)';
  }

  /* ---------------- Icons ---------------- */

  const svg = (path) =>
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';

  const ICON = {
    search: svg('<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'),
    github: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 .5A11.5 11.5 0 0 0 .5 12a11.5 11.5 0 0 0 7.86 10.94c.58.1.79-.25.79-.56v-2c-3.2.7-3.88-1.37-3.88-1.37-.53-1.34-1.3-1.7-1.3-1.7-1.05-.72.08-.71.08-.71 1.17.08 1.78 1.2 1.78 1.2 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.55-.29-5.23-1.28-5.23-5.7 0-1.26.45-2.29 1.19-3.1-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.18 1.18a11 11 0 0 1 5.8 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.12 3.05.74.81 1.18 1.84 1.18 3.1 0 4.43-2.69 5.4-5.25 5.69.41.36.78 1.06.78 2.14v3.17c0 .31.2.67.8.56A11.5 11.5 0 0 0 23.5 12 11.5 11.5 0 0 0 12 .5Z"/></svg>',
    star: svg('<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9Z"/>'),
    fork: svg('<circle cx="6" cy="5" r="2"/><circle cx="18" cy="5" r="2"/><circle cx="12" cy="19" r="2"/><path d="M6 7v3a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7M12 12v5"/>'),
    location: svg('<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/>'),
    briefcase: svg('<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5.5A2.5 2.5 0 0 1 10.5 3h3A2.5 2.5 0 0 1 16 5.5V7M3 12h18"/>'),
    link: svg('<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>'),
    mail: svg('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>'),
    send: svg('<path d="M22 2 11 13M22 2l-7 20-4-9-9-4Z"/>'),
    plus: svg('<path d="M12 5v14M5 12h14"/>'),
    x: svg('<path d="M6 6l12 12M18 6 6 18"/>'),
    sun: svg('<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>'),
    moon: svg('<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z"/>'),
    compare: svg('<path d="M8 3v18M16 3v18M3 8h5M16 16h5M3 16h5M16 8h5"/>'),
    back: svg('<path d="M15 6l-6 6 6 6"/>'),
    external: svg('<path d="M14 5h5v5M19 5l-7 7M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>'),
    sparkles: svg('<path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6ZM19 14l.8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8Z"/>'),
    refresh: svg('<path d="M21 12a9 9 0 1 1-2.6-6.4M21 4v5h-5"/>'),
    logout: svg('<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11"/>'),
    user: svg('<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>'),
    share: svg('<circle cx="6" cy="12" r="2.6"/><circle cx="18" cy="6" r="2.6"/><circle cx="18" cy="18" r="2.6"/><path d="m8.4 10.8 7.2-3.6M8.4 13.2l7.2 3.6"/>'),
    commit: svg('<circle cx="12" cy="12" r="3"/><path d="M4 12h5M15 12h5"/>'),
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

  const state = { user: null, profiles: [], convos: [] };
  const isLoggedIn = () => !!state.user;

  /* ---------------- Avatars ---------------- */

  function avatarHtml(dev, size = '') {
    const name = dev.displayName || dev.display_name || dev.name || dev.login || '?';
    const url = dev.avatarUrl || dev.avatar_url || '';
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

  function skillChips(skills) {
    const list = (skills || []).slice(0, 30);
    if (!list.length) return '<span class="empty-inline">None listed.</span>';
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
    const cells = days.map((v) =>
      '<span class="cell l' + level(v) + '" title="' + v + ' commits"></span>'
    ).join('');
    const legend = [0, 1, 2, 3, 4].map((l) =>
      '<span class="cell l' + l + '"></span>').join('');
    return '<div class="heatmap">' + cells + '</div>' +
      '<div class="heatmap-meta">' +
      '<span>' + total + ' commits · last 12 weeks</span>' +
      '<span class="heatmap-legend"><span class="faint">less</span>' + legend +
      '<span class="faint">more</span></span>' +
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
      '<div class="repo-top"><span class="repo-name">' + escapeHtml(repo.name) +
      (Number(repo.is_featured) ? ' <span class="badge accent">featured</span>' : '') + '</span>' +
      '<span class="repo-spark">' + sparkline(repo.weekly_commits) + '</span></div>' +
      (repo.description ? '<div class="repo-desc">' + escapeHtml(repo.description) + '</div>' : '') +
      '<div class="repo-foot">' +
      (lang ? '<span class="item"><span class="lang-dot" style="background:' + langColor(lang) + '"></span>' + escapeHtml(lang) + '</span>' : '') +
      '<span class="item">' + ICON.star + escapeHtml(fmtNum(stars)) + '</span>' +
      '<span class="item">' + ICON.fork + escapeHtml(fmtNum(forks)) + '</span>' +
      '<span class="item">' + ICON.commit + escapeHtml(commits) + ' <span class="faint">30d</span></span>' +
      '</div></a>';
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

  function renderAuthSlot() {
    const slot = $('#auth-slot');
    if (!state.user) {
      slot.innerHTML = '<button class="btn btn-primary btn-sm" id="signin-btn">Sign in</button>';
      $('#signin-btn').addEventListener('click', () => openAuthModal('login'));
      return;
    }
    const user = state.user;
    slot.innerHTML =
      '<div class="user-menu">' +
      '<button class="btn btn-sm" id="user-btn">' + avatarHtml(user, 'xs') +
      '<span>' + escapeHtml(user.firstName || user.login) + '</span></button>' +
      '<div class="menu hidden" id="user-menu">' +
      '<a href="#/dev/' + user.userid + '">' + ICON.user + ' My profile</a>' +
      '<button id="menu-new-msg">' + ICON.plus + ' New message</button>' +
      '<div class="sep"></div>' +
      '<button id="menu-logout">' + ICON.logout + ' Sign out</button>' +
      '</div></div>';

    const menu = $('#user-menu');
    $('#user-btn').addEventListener('click', (e) => {
      e.stopPropagation();
      menu.classList.toggle('hidden');
    });
    document.addEventListener('click', () => menu.classList.add('hidden'), { once: true });
    $('#menu-new-msg').addEventListener('click', openNewMessageModal);
    $('#menu-logout').addEventListener('click', async () => {
      try { await API.logout(); } catch (e) { /* ignore */ }
      API.setToken(null);
      state.user = null;
      renderAuthSlot();
      toast('Signed out', 'success');
      render();
    });
  }

  function openAuthModal(tab = 'login') {
    const root = $('#modal-root');
    root.innerHTML =
      '<div class="modal" id="modal-backdrop"><div class="modal-card">' +
      '<h2>~/devbio</h2><p class="sub">Sign in or create an account. Profiles are private and shared by link.</p>' +
      '<div class="tabs"><button class="tab" data-tab="login">Sign in</button>' +
      '<button class="tab" data-tab="register">Create account</button></div>' +
      '<form class="form-grid" id="auth-form"></form>' +
      '</div></div>';

    let mode = tab;
    const form = $('#auth-form');

    const draw = () => {
      $$('.tab', root).forEach((t) => t.classList.toggle('active', t.dataset.tab === mode));
      form.innerHTML = mode === 'login'
        ? '<div class="field"><label>Login or email</label><input class="input" name="login" autocomplete="username" required></div>' +
          '<div class="field"><label>Password</label><input class="input" name="password" type="password" autocomplete="current-password" required></div>' +
          '<button class="btn btn-primary" type="submit">Sign in</button>'
        : '<div class="field"><label>Login</label><input class="input" name="login" autocomplete="username" required minlength="3"></div>' +
          '<div class="field"><label>Email</label><input class="input" name="email" type="email" autocomplete="email" required></div>' +
          '<div class="field"><label>First name</label><input class="input" name="firstName"></div>' +
          '<div class="field"><label>Last name</label><input class="input" name="lastName"></div>' +
          '<div class="field"><label>Password</label><input class="input" name="password" type="password" autocomplete="new-password" required minlength="8"></div>' +
          '<button class="btn btn-primary" type="submit">Create account</button>';
    };

    $$('.tab', root).forEach((t) =>
      t.addEventListener('click', () => { mode = t.dataset.tab; draw(); }));
    draw();

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(form));
      const submit = $('button[type="submit"]', form);
      submit.disabled = true;
      submit.textContent = 'Please wait…';
      try {
        const result = mode === 'login'
          ? await API.login(data.login, data.password)
          : await API.register(data);
        API.setToken(result.token);
        state.user = await API.session();
        closeModal();
        renderAuthSlot();
        toast(mode === 'login' ? 'Welcome back' : 'Account created', 'success');
        render();
      } catch (err) {
        toast(err.message, 'error');
        submit.disabled = false;
        submit.textContent = mode === 'login' ? 'Sign in' : 'Create account';
      }
    });

    $('#modal-backdrop').addEventListener('click', (e) => {
      if (e.target.id === 'modal-backdrop') closeModal();
    });
  }

  function closeModal() {
    $('#modal-root').innerHTML = '';
  }

  /* ---------------- Routing ---------------- */

  function parseHash() {
    const raw = location.hash.replace(/^#\/?/, '');
    const [pathPart, queryPart] = raw.split('?');
    const parts = pathPart.split('/').filter(Boolean);
    const query = Object.fromEntries(new URLSearchParams(queryPart || ''));
    return { parts, query };
  }

  function setActiveNav(resource) {
    $$('.nav-link').forEach((link) => {
      const route = link.dataset.route;
      let active;
      if (route === 'compare') active = resource === 'compare';
      else if (route === 'messages') active = resource === 'messages';
      else active = !['compare', 'messages'].includes(resource);
      link.classList.toggle('active', active);
    });
  }

  function requireGate(root) {
    if (isLoggedIn()) return true;
    root.innerHTML = '<div class="container">' + emptyState(ICON.user, 'Sign in required',
      'Profiles are private and shared by link. Sign in to view them.',
      '<button class="btn btn-primary" id="empty-signin">Sign in</button>') + '</div>';
    $('#empty-signin').addEventListener('click', () => openAuthModal('login'));
    return false;
  }

  async function render() {
    const { parts, query } = parseHash();
    const root = $('#app');
    const resource = parts[0] || 'home';
    setActiveNav(resource);
    window.scrollTo(0, 0);

    try {
      if (resource === 'home') return await viewHome(root, query);
      if (resource === 'dev') return await viewProfile(root, parts[1]);
      if (resource === 'u') return await resolveByLogin(root, parts[1]);
      if (resource === 'compare') return await viewCompare(root, query);
      if (resource === 'messages') return await viewMessages(root, parts[1]);
      root.innerHTML = emptyState('404', 'Not found', 'That page does not exist.');
    } catch (err) {
      root.innerHTML = emptyState('!', 'Something went wrong', err.message || 'Unexpected error.');
    }
  }

  /* ---------------- Home (search only) ---------------- */

  async function viewHome(root, query) {
    root.innerHTML =
      '<div class="home"><div class="home-inner">' +
      '<h1 class="home-title">~/devbio</h1>' +
      '<p class="home-sub">private developer directory</p>' +
      '<form class="search-form" id="search-form">' +
      '<input class="search-input" id="search-input" type="search" placeholder="search by name or @login" ' +
      'autocomplete="off" value="' + escapeHtml(query.q || '') + '">' +
      '<button class="btn btn-primary" type="submit">' + ICON.search + ' search</button>' +
      '</form>' +
      '<p class="home-hint" id="home-hint">' +
      (isLoggedIn() ? 'find someone you build with' : 'sign in to search shared profiles') +
      '</p>' +
      '<div class="results" id="results"></div>' +
      '</div></div>';

    const input = $('#search-input');
    const form = $('#search-form');
    const results = $('#results');
    const hint = $('#home-hint');

    if (query.q) {
      input.value = query.q;
      runSearch(query.q, results, hint);
    } else {
      input.focus();
    }

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const q = input.value.trim();
      if (!q) return;
      history.replaceState(null, '', '#/?q=' + encodeURIComponent(q));
      runSearch(q, results, hint);
    });
  }

  async function runSearch(q, results, hint) {
    if (!isLoggedIn()) {
      hint.textContent = 'sign in to search shared profiles';
      results.innerHTML = '';
      return openAuthModal('login');
    }
    hint.textContent = 'searching…';
    results.innerHTML = skeleton(3);
    try {
      const data = await API.profiles({ q });
      hint.textContent = data.length
        ? data.length + ' result' + (data.length === 1 ? '' : 's') + ' for "' + q + '"'
        : 'no results for "' + q + '"';
      results.innerHTML = data.length
        ? data.map((p) =>
            '<div class="result-row" data-id="' + p.userid + '">' + avatarHtml(p, 'sm') +
            '<div class="result-main"><div class="result-name">' + escapeHtml(p.displayName || p.login) + '</div>' +
            '<div class="result-login">@' + escapeHtml(p.login) + '</div></div>' +
            '<div class="result-meta">' + escapeHtml(p.jobTitle || '') +
            (p.totalStars ? ' · ' + ICON.star + ' ' + fmtNum(p.totalStars) : '') +
            '</div></div>').join('')
        : emptyState(ICON.search, 'No matches', 'Try a different name or handle.');
      $$('.result-row', results).forEach((row) =>
        row.addEventListener('click', () => { location.hash = '#/dev/' + row.dataset.id; }));
    } catch (err) {
      hint.textContent = 'sign in to search shared profiles';
      results.innerHTML = emptyState('!', 'Search failed', err.message);
    }
  }

  /* ---------------- Profile ---------------- */

  async function resolveByLogin(root, login) {
    root.innerHTML = skeleton(2);
    if (!requireGate(root)) return;
    try {
      const data = await API.profiles({ q: login });
      const match = data.find((p) => p.login.toLowerCase() === String(login).toLowerCase());
      if (!match) {
        root.innerHTML = emptyState('404', 'Not found', 'No profile matches @' + escapeHtml(login));
        return;
      }
      await viewProfile(root, match.userid);
    } catch (err) {
      root.innerHTML = emptyState('!', 'Something went wrong', err.message);
    }
  }

  async function viewProfile(root, userid) {
    if (!userid) {
      root.innerHTML = emptyState('404', 'Not found', 'No developer was specified.');
      return;
    }
    if (!requireGate(root)) return;
    root.innerHTML = skeleton(4);

    const profile = await API.profile(userid);
    if (!profile) {
      root.innerHTML = emptyState('404', 'Not found', 'This profile does not exist or is not shared with you.');
      return;
    }

    const gh = profile.github;
    const isOwner = state.user && state.user.userid === profile.userid;
    const heroAvatar = Object.assign({}, profile, {
      avatarUrl: /^https?:/i.test(profile.avatarUrl || '') ? profile.avatarUrl : (gh && gh.avatarUrl),
    });

    const social = (profile.socialLinks || []).map((link) =>
      '<a class="chip" href="' + escapeHtml(link.url) + '" target="_blank" rel="noopener">' +
      ICON.link + escapeHtml(link.platform) + '</a>').join('');

    root.innerHTML =
      '<div class="container">' +
      '<a class="btn btn-sm btn-ghost" href="#/">' + ICON.back + ' back</a>' +
      '<section class="section profile-head">' + avatarHtml(heroAvatar, 'xl') +
      '<div class="flex1">' +
      '<h1 class="profile-name">' + escapeHtml(profile.displayName || profile.login) + '</h1>' +
      '<div class="profile-handle">@' + escapeHtml(profile.login) + '</div>' +
      '<div class="profile-meta">' +
      (profile.jobTitle ? '<span class="meta-item">' + ICON.briefcase + escapeHtml(profile.jobTitle) + '</span>' : '') +
      (profile.location ? '<span class="meta-item">' + ICON.location + escapeHtml(profile.location) + '</span>' : '') +
      (gh && gh.username ? '<a class="meta-item" href="' + escapeHtml(gh.profileUrl) + '" target="_blank" rel="noopener">' + ICON.github + escapeHtml(gh.username) + '</a>' : '') +
      '</div></div>' +
      '<div class="profile-actions">' +
      '<button class="btn btn-ghost btn-sm" id="share-btn" title="Copy share link">' + ICON.share + ' share</button>' +
      (isOwner ? '<button class="btn btn-sm" id="sync-btn">' + ICON.refresh + ' sync</button>' : '') +
      (isOwner ? '<button class="btn btn-sm" id="edit-btn">edit</button>' : '') +
      (isOwner ? '' : '<button class="btn btn-primary btn-sm" id="message-btn">' + ICON.mail + ' message</button>') +
      '<button class="btn btn-sm" id="compare-btn">' + ICON.compare + ' compare</button>' +
      '</div></section>' +
      (profile.bio ? '<p class="bio section">' + escapeHtml(profile.bio) + '</p>' : '') +
      (social ? '<div class="chips section">' + social + '</div>' : '') +
      '<section class="section" id="common-ground"></section>' +
      '<section class="section"><h3 class="section-title">skills</h3><div class="chips">' + skillChips(profile.skills) + '</div></section>' +
      '<section class="section" id="github-section"></section>' +
      '</div>';

    $('#share-btn').addEventListener('click', () => shareProfile(profile));
    $('#compare-btn').addEventListener('click', () => { location.hash = '#/compare?a=' + profile.userid; });
    const messageBtn = $('#message-btn');
    if (messageBtn) messageBtn.addEventListener('click', () => messageUser(profile.userid));
    const syncBtn = $('#sync-btn');
    if (syncBtn) {
      syncBtn.addEventListener('click', async () => {
        const username = prompt('GitHub username to sync', (gh && gh.username) || profile.login);
        if (!username) return;
        syncBtn.disabled = true;
        try {
          await API.syncGithub(username);
          toast('GitHub synced', 'success');
          viewProfile(root, userid);
        } catch (err) {
          toast(err.message, 'error');
          syncBtn.disabled = false;
        }
      });
    }
    const editBtn = $('#edit-btn');
    if (editBtn) editBtn.addEventListener('click', () => openEditProfileModal(profile));

    if (isLoggedIn() && !isOwner) loadCommonGround(profile.userid);
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
      const repos = full.repositories || [];
      const totalStars = repos.reduce((sum, r) => sum + (Number(r.stars) || 0), 0);

      const langMap = {};
      repos.forEach((r) => { if (r.language) langMap[r.language] = (langMap[r.language] || 0) + 1; });
      const languages = Object.keys(langMap)
        .map((name) => ({ language: name, repos: langMap[name] }))
        .sort((a, b) => b.repos - a.repos);
      const totalCommits = repos.reduce((sum, r) => sum + (Number(r.commits_30d) || 0), 0);

      const bar = langBarHtml(languages);
      const legend = langLegend(languages);
      const activity = activityHeatmap(repos);

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
        (bar ? '<div class="panel section" style="padding:20px"><h3 class="section-title">languages</h3>' +
          bar + '<div class="mt">' + legend + '</div></div>' : '') +
        (activity ? '<div class="panel section" style="padding:20px"><h3 class="section-title">activity</h3>' +
          activity + '</div>' : '') +
        (sortedRepos.length ? '<div class="section"><div class="spread"><h3 class="section-title">repositories</h3>' +
          '<span class="mono faint" style="font-size:12px">' +
          (full.lastSynced ? 'synced ' + timeAgo(full.lastSynced) : '') + '</span></div>' +
          '<div class="repo-list">' + sortedRepos.slice(0, 20).map(repoRow).join('') + '</div></div>' : '');
    } catch (err) {
      section.innerHTML = '<h3 class="section-title">github</h3>' +
        emptyState('!', 'Could not load GitHub data', err.message);
    }
  }

  async function shareProfile(profile) {
    const url = location.origin + location.pathname + '#/u/' + profile.login;
    try {
      await navigator.clipboard.writeText(url);
      toast('Link copied: ' + url, 'success');
    } catch (e) {
      toast('Share link: ' + url, 'info');
    }
  }

  function openEditProfileModal(profile) {
    const root = $('#modal-root');
    root.innerHTML =
      '<div class="modal" id="modal-backdrop"><div class="modal-card">' +
      '<h2>edit profile</h2><p class="sub">Update how other developers see you.</p>' +
      '<form class="form-grid" id="edit-form">' +
      '<div class="field"><label>Display name</label><input class="input" name="displayName" value="' + escapeHtml(profile.displayName || '') + '"></div>' +
      '<div class="field"><label>Job title</label><input class="input" name="jobTitle" value="' + escapeHtml(profile.jobTitle || '') + '"></div>' +
      '<div class="field"><label>Location</label><input class="input" name="location" value="' + escapeHtml(profile.location || '') + '"></div>' +
      '<div class="field"><label>Bio</label><textarea class="textarea" name="bio">' + escapeHtml(profile.bio || '') + '</textarea></div>' +
      '<div class="row"><button class="btn btn-primary flex1" type="submit">save</button>' +
      '<button class="btn btn-ghost" type="button" id="cancel">cancel</button></div>' +
      '</form></div></div>';

    $('#cancel').addEventListener('click', closeModal);
    $('#modal-backdrop').addEventListener('click', (e) => { if (e.target.id === 'modal-backdrop') closeModal(); });
    $('#edit-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fields = Object.fromEntries(new FormData(e.target));
      try {
        await API.updateProfile(profile.userid, fields);
        closeModal();
        toast('Profile updated', 'success');
        render();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  async function loadCommonGround(userid) {
    const el = $('#common-ground');
    if (!el) return;
    try {
      const data = await API.compare(state.user.userid, userid);
      const [me, them] = data.developers;
      const hasOverlap = data.commonSkills.length || data.sharedProjects.length || data.commonLanguages.length;

      const skillNamesA = (me.skills || []).map((s) => s.name);
      const skillNamesB = (them.skills || []).map((s) => s.name);
      const union = new Set(skillNamesA.concat(skillNamesB));
      const overlapPct = union.size ? Math.round((data.commonSkills.length / union.size) * 100) : 0;

      if (!hasOverlap) {
        el.innerHTML = '<h3 class="section-title">common ground</h3>' +
          '<div class="panel" style="padding:18px"><span class="empty-inline">No overlap yet — a good reason to say hello.</span></div>';
        return;
      }

      const skills = data.commonSkills.length
        ? '<div class="chips">' + data.commonSkills.map((s) =>
            '<span class="chip static"><span class="dot" style="background:' + langColor(s.name) + '"></span>' +
            escapeHtml(s.name) + '</span>').join('') + '</div>'
        : '<span class="empty-inline">No shared skills.</span>';

      const projects = data.sharedProjects.length
        ? data.sharedProjects.map((project) =>
            '<div class="shared-item"><div class="sname">' + ICON.sparkles + ' ' + escapeHtml(project.name) +
            ' <span class="badge accent">both worked on</span></div><div class="shared-links">' +
            project.developers.map((repo) => {
              const dev = repo.userid === me.userid ? me : them;
              return '<a class="shared-link" href="' + escapeHtml(repo.url || '#') + '" target="_blank" rel="noopener">' +
                escapeHtml(dev.displayName || dev.login) + ' · ' + ICON.star + escapeHtml(fmtNum(repo.stars)) + '</a>';
            }).join('') + '</div></div>').join('')
        : '<span class="empty-inline">No shared projects.</span>';

      el.innerHTML = '<h3 class="section-title">common ground with you</h3>' +
        '<div class="overlap">' +
        '<div class="spread"><h3 class="section-title" style="margin:0">skill overlap</h3>' +
        '<span class="meter-num">' + overlapPct + '%</span></div>' +
        '<div class="meter-track" style="width:100%;max-width:none"><span class="meter-fill" style="width:' + overlapPct + '%"></span></div>' +
        '<div class="overlap-score">' +
        statCell('Shared skills', data.commonSkills.length) +
        statCell('Shared projects', data.sharedProjects.length) +
        statCell('Shared langs', data.commonLanguages.length) +
        '</div>' +
        '<div><h3 class="section-title">shared projects</h3><div class="stack">' + projects + '</div></div>' +
        '<div><h3 class="section-title">shared skills</h3>' + skills + '</div>' +
        '</div>';
    } catch (e) {
      el.innerHTML = '';
    }
  }

  /* ---------------- Compare ---------------- */

  function attachAutocomplete(inputEl, suggestionsEl, candidates, onPick) {
    const render = () => {
      const q = inputEl.value.trim().toLowerCase();
      if (!q) { suggestionsEl.classList.add('hidden'); suggestionsEl.innerHTML = ''; return; }
      const matches = candidates.filter((p) =>
        p.login.toLowerCase().includes(q) || (p.displayName || '').toLowerCase().includes(q)
      ).slice(0, 8);
      if (!matches.length) { suggestionsEl.classList.add('hidden'); suggestionsEl.innerHTML = ''; return; }
      suggestionsEl.innerHTML = matches.map((p) =>
        '<button type="button" class="suggestion" data-id="' + p.userid + '" data-login="' + escapeHtml(p.login) + '">' +
        avatarHtml(p, 'xs') +
        '<span class="s-name">' + escapeHtml(p.displayName || p.login) + '</span>' +
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
      '<h1 class="home-title" style="font-size:20px">compare</h1>' +
      '<div class="row mt" style="justify-content:center;align-items:flex-start">' +
      '<div class="autocomplete" style="flex:0 1 300px;min-width:200px">' +
      '<input class="input" id="pick-a-input" placeholder="@login or name" autocomplete="off">' +
      '<div class="suggestions hidden" id="suggest-a"></div></div>' +
      '<button class="btn btn-ghost btn-icon" id="swap" title="Swap">⇄</button>' +
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
      result.innerHTML = emptyState(ICON.compare, 'Pick two developers', 'Select two developers to see what you have in common.');
      return;
    }
    if (a === b) {
      result.innerHTML = emptyState(ICON.compare, 'Choose different people', 'The same developer is selected twice.');
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
      : '<span class="empty-inline">No shared skills yet.</span>';

    const sharedProjects = data.sharedProjects.length
      ? data.sharedProjects.map((project) =>
          '<div class="shared-item"><div class="sname">' + ICON.sparkles + ' ' + escapeHtml(project.name) +
          ' <span class="badge accent">both worked on</span></div>' +
          '<div class="shared-links">' + project.developers.map((repo) => {
            const dev = repo.userid === devA.userid ? devA : devB;
            return '<a class="shared-link" href="' + escapeHtml(repo.url || '#') + '" target="_blank" rel="noopener">' +
              escapeHtml(dev.displayName || dev.login) + ' · ' + ICON.star + escapeHtml(fmtNum(repo.stars)) + '</a>';
          }).join('') + '</div></div>').join('')
      : '<span class="empty-inline">No repositories with matching names.</span>';

    const commonLanguages = data.commonLanguages.length
      ? '<div class="chips">' + data.commonLanguages.map((lang) =>
          '<span class="chip static"><span class="dot" style="background:' + langColor(lang) + '"></span>' +
          escapeHtml(lang) + '</span>').join('') + '</div>'
      : '<span class="empty-inline">No overlapping languages.</span>';

    const miniLang = (dev) => {
      const total = (dev.languages || []).reduce((sum, l) => sum + l.repos, 0);
      if (!total) return '<span class="empty-inline">No language data.</span>';
      return '<div style="width:100%">' + langBarHtml(dev.languages) + '</div>';
    };

    const col = (dev) =>
      '<div class="compare-col">' + avatarHtml(dev, 'lg') +
      '<div><div class="profile-name">' + escapeHtml(dev.displayName || dev.login) + '</div>' +
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
      '<span class="mono faint" style="font-size:12px">' + escapeHtml(devA.displayName || devA.login) + ' · ' +
      escapeHtml(devB.displayName || devB.login) + '</span></div>' +
      '<div class="duo-bar" style="margin-top:10px"><span class="a" style="width:' + pctA + '%"></span>' +
      '<span class="b" style="width:' + (100 - pctA) + '%"></span></div>' +
      '<div class="duo-labels"><span>' + escapeHtml(devA.displayName || devA.login) + ' ' + fmtNum(devA.totalStars) + '★</span>' +
      '<span>' + fmtNum(devB.totalStars) + '★ ' + escapeHtml(devB.displayName || devB.login) + '</span></div></div>' +
      '</div>' +
      '<div><h3 class="section-title">shared projects</h3><div class="stack">' + sharedProjects + '</div></div>' +
      '<div><h3 class="section-title">shared skills</h3>' + commonSkills + '</div>' +
      '<div><h3 class="section-title">shared languages</h3>' + commonLanguages + '</div>' +
      '</div>';
  }

  /* ---------------- Messaging ---------------- */

  async function loadDirectory() {
    if (state.profiles.length) return;
    state.profiles = await API.profiles() || [];
  }

  async function messageUser(userid) {
    if (!isLoggedIn()) return openAuthModal('login');
    if (state.user.userid === Number(userid)) return toast('That is you!', 'info');
    try {
      const result = await API.startConversation({ recipientId: Number(userid) });
      location.hash = '#/messages/' + result.conversationid;
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  function openNewMessageModal() {
    if (!isLoggedIn()) return openAuthModal('login');
    loadDirectory().then(() => {
      const candidates = state.profiles.filter((p) => p.userid !== state.user.userid);
      const root = $('#modal-root');
      root.innerHTML =
        '<div class="modal" id="modal-backdrop"><div class="modal-card">' +
        '<h2>new message</h2><p class="sub">Type a name or @handle to start a conversation.</p>' +
        '<form class="form-grid" id="new-msg-form">' +
        '<div class="field"><label>to</label>' +
        '<div class="autocomplete">' +
        '<input class="input" id="to-input" name="to" autocomplete="off" placeholder="@login or name">' +
        '<div class="suggestions hidden" id="to-suggestions"></div>' +
        '</div></div>' +
        '<div class="field"><label>message (optional)</label><textarea class="textarea" name="message" placeholder="say hello…"></textarea></div>' +
        '<div class="row"><button class="btn btn-primary flex1" type="submit">start</button>' +
        '<button class="btn btn-ghost" type="button" id="cancel">cancel</button></div>' +
        '</form></div></div>';

      const input = $('#to-input');
      const suggestions = $('#to-suggestions');
      let recipientId = null;

      const renderSuggestions = () => {
        const q = input.value.trim().toLowerCase();
        if (!q) { suggestions.classList.add('hidden'); suggestions.innerHTML = ''; return; }
        const matches = candidates.filter((p) =>
          p.login.toLowerCase().includes(q) || (p.displayName || '').toLowerCase().includes(q)
        ).slice(0, 8);
        if (!matches.length) { suggestions.classList.add('hidden'); suggestions.innerHTML = ''; return; }
        suggestions.innerHTML = matches.map((p) =>
          '<button type="button" class="suggestion" data-id="' + p.userid + '" data-login="' + escapeHtml(p.login) + '">' +
          avatarHtml(p, 'xs') +
          '<span class="s-name">' + escapeHtml(p.displayName || p.login) + '</span>' +
          '<span class="s-login">@' + escapeHtml(p.login) + '</span></button>').join('');
        suggestions.classList.remove('hidden');
      };

      input.addEventListener('input', () => { recipientId = null; renderSuggestions(); });
      input.addEventListener('focus', renderSuggestions);
      document.addEventListener('click', (e) => {
        if (!root.contains(e.target)) suggestions.classList.add('hidden');
      });
      suggestions.addEventListener('click', (e) => {
        const btn = e.target.closest('.suggestion');
        if (!btn) return;
        recipientId = Number(btn.dataset.id);
        input.value = '@' + btn.dataset.login;
        suggestions.classList.add('hidden');
      });

      $('#cancel').addEventListener('click', closeModal);
      $('#modal-backdrop').addEventListener('click', (e) => { if (e.target.id === 'modal-backdrop') closeModal(); });

      const resolve = () => {
        if (recipientId) return recipientId;
        const raw = input.value.trim().replace(/^@/, '').toLowerCase();
        if (!raw) return null;
        const exact = candidates.filter((p) =>
          p.login.toLowerCase() === raw || (p.displayName || '').toLowerCase() === raw);
        if (exact.length === 1) return exact[0].userid;
        if (exact.length > 1) throw new Error('Multiple developers match — pick one from the list.');
        const partial = candidates.filter((p) => p.login.toLowerCase().includes(raw));
        if (partial.length === 1) return partial[0].userid;
        return null;
      };

      $('#new-msg-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        let target;
        try {
          target = resolve();
        } catch (err) {
          toast(err.message, 'error');
          return;
        }
        if (!target) {
          toast('No developer matches that name or handle.', 'error');
          return;
        }
        const data = Object.fromEntries(new FormData(e.target));
        try {
          const result = await API.startConversation({
            recipientId: Number(target),
            message: data.message || undefined,
          });
          closeModal();
          location.hash = '#/messages/' + result.conversationid;
        } catch (err) {
          toast(err.message, 'error');
        }
      });

      if (!candidates.length) {
        $('#new-msg-form').innerHTML = '<p class="muted">No other developers to message yet.</p>';
      }
    });
  }

  async function viewMessages(root, conversationid) {
    if (!requireGate(root)) return;

    root.innerHTML =
      '<div class="container"><div class="messages section"><div class="convo-list">' +
      Array.from({ length: 4 }, () => '<div class="skeleton sk-line" style="height:56px;margin:10px"></div>').join('') +
      '</div><div class="thread"></div></div></div>';

    state.convos = await API.conversations() || [];
    drawConvoList();

    if (conversationid) {
      await drawThread(Number(conversationid));
    } else {
      $('.thread').innerHTML = emptyState(ICON.mail, 'Messages',
        'Select a conversation or start a new one.',
        '<button class="btn btn-primary" id="new-convo">' + ICON.plus + ' new message</button>');
      $('#new-convo').addEventListener('click', openNewMessageModal);
    }
  }

  function drawConvoList() {
    const list = $('.convo-list');
    if (!list) return;
    if (!state.convos.length) {
      list.innerHTML = '<div class="empty"><p class="muted">No conversations yet.</p>' +
        '<button class="btn btn-primary btn-sm" id="new-convo-2">' + ICON.plus + ' new message</button></div>';
      const btn = $('#new-convo-2');
      if (btn) btn.addEventListener('click', openNewMessageModal);
      return;
    }
    const active = parseHash().parts[1];
    list.innerHTML = state.convos.map((convo) => {
      const other = (convo.participants || [])[0] || { display_name: 'Unknown' };
      return '<div class="convo-item' + (String(convo.conversationid) === String(active) ? ' active' : '') +
        '" data-id="' + convo.conversationid + '">' + avatarHtml(other, 'sm') +
        '<div class="convo-main"><div class="convo-name">' + escapeHtml(other.display_name || other.login) + '</div>' +
        '<div class="convo-preview">' + escapeHtml(convo.lastMessage || 'No messages yet') + '</div></div>' +
        '<div class="convo-side">' + (convo.unreadCount ? '<span class="unread">' + convo.unreadCount + '</span>' : '<span class="convo-time">' + escapeHtml(timeAgo(convo.lastMessageAt)) + '</span>') + '</div></div>';
    }).join('');
    $$('.convo-item', list).forEach((item) =>
      item.addEventListener('click', () => { location.hash = '#/messages/' + item.dataset.id; }));
  }

  async function drawThread(conversationid) {
    const thread = $('.thread');
    if (!thread) return;

    const convo = state.convos.find((c) => Number(c.conversationid) === conversationid);
    const other = convo && convo.participants && convo.participants[0];
    if (!convo) {
      thread.innerHTML = emptyState('!', 'Conversation not found', 'You are not part of this conversation.');
      return;
    }

    const messages = await API.messages(conversationid) || [];
    await API.markRead(conversationid).catch(() => {});
    const idx = state.convos.indexOf(convo);
    if (idx >= 0) state.convos[idx].unreadCount = 0;

    const bubbles = messages.length
      ? messages.map((m) => {
          const mine = Number(m.sender_userid) === state.user.userid;
          return '<div class="bubble' + (mine ? ' me' : '') + '">' + escapeHtml(m.body) +
            '<span class="time">' + escapeHtml(timeAgo(m.created_at)) + '</span></div>';
        }).join('')
      : '<div class="empty"><p class="muted">No messages yet. Say hello!</p></div>';

    thread.innerHTML =
      '<div class="thread-head">' + avatarHtml(other || {}, 'sm') +
      '<div><div class="convo-name">' + escapeHtml((other && (other.display_name || other.login)) || 'Conversation') + '</div>' +
      '<div class="convo-preview">#' + conversationid + '</div></div></div>' +
      '<div class="thread-body" id="thread-body">' + bubbles + '</div>' +
      '<form class="composer" id="composer">' +
      '<textarea class="textarea flex1" id="composer-input" placeholder="write a message…" rows="1"></textarea>' +
      '<button class="btn btn-primary btn-icon" type="submit" title="Send">' + ICON.send + '</button>' +
      '</form>';

    const body = $('#thread-body');
    body.scrollTop = body.scrollHeight;
    drawConvoList();

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
    const stored = localStorage.getItem('devbio.theme');
    applyTheme(stored || '');
    $('#theme-toggle').addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme');
      const dark = current ? current === 'dark'
        : window.matchMedia('(prefers-color-scheme: dark)').matches;
      const next = dark ? 'light' : 'dark';
      localStorage.setItem('devbio.theme', next);
      applyTheme(next);
    });
  }

  /* ---------------- Boot ---------------- */

  window.addEventListener('hashchange', render);

  (async function init() {
    initTheme();
    await resolveSession();
    renderAuthSlot();
    await render();
  })();
})();
