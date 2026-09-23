/* ============================================================
   collab.dev API client — thin wrapper over the PHP JSON API
   ============================================================ */
(function () {
  const base = (function () {
    if (window.API_BASE_URL) return window.API_BASE_URL;
    const stored = localStorage.getItem('api.baseUrl');
    if (stored) return stored;

    const hostname = location.hostname;
    const isLocal = !hostname || hostname === 'localhost' || hostname === '127.0.0.1';
    // When running locally on a static dev port, point to the live server
    if (isLocal && location.port !== '' && location.port !== '80') {
      return 'http://lamp.morning.codes/api/index.php';
    }

    const path = location.pathname;
    const dir = path.endsWith('/') ? path : path.slice(0, path.lastIndexOf('/') + 1);
    return dir + 'api/index.php';
  })();

  const API = {
    base,

    token: localStorage.getItem('collab.token') || null,

    setToken(token) {
      this.token = token || null;
      if (token) {
        localStorage.setItem('collab.token', token);
      } else {
        localStorage.removeItem('collab.token');
      }
    },

    async request(method, path, body) {
      const headers = {};
      if (body !== undefined) headers['Content-Type'] = 'application/json';
      if (this.token) headers['Authorization'] = 'Bearer ' + this.token;

      const res = await fetch(this.base + path, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });

      const text = await res.text();
      let payload = null;
      try {
        payload = text ? JSON.parse(text) : null;
      } catch (e) {
        payload = { error: text };
      }

      if (!res.ok) {
        const err = new Error((payload && payload.error) || 'HTTP ' + res.status);
        err.status = res.status;
        throw err;
      }
      return payload;
    },

    async data(method, path, body) {
      const payload = await this.request(method, path, body);
      return payload ? payload.data : null;
    },

    query(params) {
      const qs = new URLSearchParams();
      Object.keys(params).forEach((key) => {
        const value = params[key];
        if (value === undefined || value === null || value === '') return;
        if (Array.isArray(value)) {
          if (value.length) qs.set(key, value.join(','));
        } else if (typeof value === 'boolean') {
          if (value) qs.set(key, '1');
        } else {
          qs.set(key, value);
        }
      });
      const str = qs.toString();
      return str ? '?' + str : '';
    },

    /* auth */
    login(login, password) {
      return this.data('POST', '/auth/login', { login, password });
    },
    register(payload) {
      return this.data('POST', '/auth/register', payload);
    },
    logout() {
      return this.data('POST', '/auth/logout');
    },
    session() {
      return this.data('GET', '/auth/session');
    },
    updateAccount(payload) {
      return this.data('PUT', '/auth/account', payload);
    },
    validateReset(token) {
      return this.data('GET', '/auth/reset' + this.query({ token }));
    },
    resetPassword(token, newPassword) {
      return this.data('POST', '/auth/reset', { token, newPassword });
    },

    /* profiles */
    profiles(params = {}) {
      return this.request('GET', '/profiles' + this.query(params));
    },
    profile(userid) {
      return this.data('GET', '/profiles/' + userid);
    },
    updateProfile(userid, fields) {
      return this.data('PUT', '/profiles/' + userid, fields);
    },
    facets() {
      return this.data('GET', '/profiles/facets');
    },
    suggestions(params = {}) {
      return this.data('GET', '/profiles/suggestions' + this.query(params));
    },
    userSkills(userid) {
      return this.data('GET', '/profiles/' + userid + '/skills');
    },
    setSkills(userid, skills) {
      return this.data('PUT', '/profiles/' + userid + '/skills', { skills });
    },
    addSocialLink(userid, link) {
      return this.data('POST', '/profiles/' + userid + '/social_links', link);
    },
    updateSocialLink(userid, linkid, link) {
      return this.data('PUT', '/profiles/' + userid + '/social_links/' + linkid, link);
    },
    removeSocialLink(userid, linkid) {
      return this.data('DELETE', '/profiles/' + userid + '/social_links/' + linkid);
    },
    follow(userid) {
      return this.data('POST', '/profiles/' + userid + '/follow');
    },
    unfollow(userid) {
      return this.data('DELETE', '/profiles/' + userid + '/follow');
    },

    /* skills + github */
    skills(q) {
      return this.data('GET', '/skills' + this.query({ q }));
    },
    createSkill(name, category) {
      return this.data('POST', '/skills', { name, category });
    },
    github(userid) {
      return this.data('GET', '/github/' + userid);
    },
    syncGithub(options = {}) {
      return this.data('POST', '/github/sync', options);
    },

    /* contacts */
    contacts() {
      return this.data('GET', '/contacts');
    },
    removeContact(contactid) {
      return this.data('DELETE', '/contacts/' + contactid);
    },

    /* compare */
    compare(a, b) {
      return this.data('GET', '/compare?users=' + a + ',' + b);
    },

    /* messaging */
    conversations() {
      return this.data('GET', '/conversations');
    },
    conversation(conversationid) {
      return this.data('GET', '/conversations/' + conversationid);
    },
    startConversation(payload) {
      return this.data('POST', '/conversations', payload);
    },
    addParticipants(conversationid, userIds) {
      return this.data('POST', '/conversations/' + conversationid + '/participants', { userIds });
    },
    removeParticipant(conversationid, userid) {
      return this.data('DELETE', '/conversations/' + conversationid + '/participants/' + userid);
    },
    renameConversation(conversationid, name) {
      return this.data('PUT', '/conversations/' + conversationid, { name });
    },
    messages(conversationid, params = {}) {
      return this.data('GET', '/conversations/' + conversationid + '/messages' + this.query(params));
    },
    sendMessage(conversationid, body) {
      return this.data('POST', '/conversations/' + conversationid + '/messages', { body });
    },
    markRead(conversationid) {
      return this.data('PUT', '/conversations/' + conversationid + '/read');
    },
    deleteMessage(id) {
      return this.data('DELETE', '/messages/' + id);
    },

    /* admin */
    adminStats() {
      return this.data('GET', '/admin/stats');
    },
    adminUsers(params = {}) {
      return this.request('GET', '/admin/users' + this.query(params));
    },
    adminDisable(userid) {
      return this.data('POST', '/admin/users/' + userid + '/disable');
    },
    adminEnable(userid) {
      return this.data('POST', '/admin/users/' + userid + '/enable');
    },
    adminReset(userid) {
      return this.data('POST', '/admin/users/' + userid + '/reset-password');
    },
  };

  window.API = API;
})();
