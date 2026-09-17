/* ============================================================
   DevBio API client — thin wrapper over the PHP JSON API
   ============================================================ */
(function () {
  const base = (function () {
    const path = location.pathname;
    const dir = path.endsWith('/') ? path : path.slice(0, path.lastIndexOf('/') + 1);
    return dir + 'api/index.php';
  })();

  const API = {
    base,

    token: localStorage.getItem('devbio.token') || null,

    setToken(token) {
      this.token = token || null;
      if (token) {
        localStorage.setItem('devbio.token', token);
      } else {
        localStorage.removeItem('devbio.token');
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
      return payload ? payload.data : null;
    },

    query(params) {
      const qs = new URLSearchParams();
      Object.keys(params).forEach((key) => {
        if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
          qs.set(key, params[key]);
        }
      });
      const str = qs.toString();
      return str ? '?' + str : '';
    },

    /* auth */
    login(login, password) {
      return this.request('POST', '/auth/login', { login, password });
    },
    register(payload) {
      return this.request('POST', '/auth/register', payload);
    },
    logout() {
      return this.request('POST', '/auth/logout');
    },
    session() {
      return this.request('GET', '/auth/session');
    },

    /* profiles */
    profiles(params = {}) {
      return this.request('GET', '/profiles' + this.query(params));
    },
    profile(userid) {
      return this.request('GET', '/profiles/' + userid);
    },
    updateProfile(userid, fields) {
      return this.request('PUT', '/profiles/' + userid, fields);
    },
    userSkills(userid) {
      return this.request('GET', '/profiles/' + userid + '/skills');
    },
    setSkills(userid, skills) {
      return this.request('PUT', '/profiles/' + userid + '/skills', { skills });
    },
    addSocialLink(userid, link) {
      return this.request('POST', '/profiles/' + userid + '/social_links', link);
    },
    removeSocialLink(userid, linkid) {
      return this.request('DELETE', '/profiles/' + userid + '/social_links/' + linkid);
    },

    /* skills + github */
    skills(q) {
      return this.request('GET', '/skills' + this.query({ q }));
    },
    github(userid) {
      return this.request('GET', '/github/' + userid);
    },
    syncGithub(username) {
      return this.request('POST', '/github/sync', username ? { username } : {});
    },

    /* compare */
    compare(a, b) {
      return this.request('GET', '/compare?users=' + a + ',' + b);
    },

    /* messaging */
    conversations() {
      return this.request('GET', '/conversations');
    },
    startConversation(payload) {
      return this.request('POST', '/conversations', payload);
    },
    messages(conversationid, params = {}) {
      return this.request('GET', '/conversations/' + conversationid + '/messages' + this.query(params));
    },
    sendMessage(conversationid, body) {
      return this.request('POST', '/conversations/' + conversationid + '/messages', { body });
    },
    markRead(conversationid) {
      return this.request('PUT', '/conversations/' + conversationid + '/read');
    },
    deleteMessage(id) {
      return this.request('DELETE', '/messages/' + id);
    },
  };

  window.API = API;
})();
