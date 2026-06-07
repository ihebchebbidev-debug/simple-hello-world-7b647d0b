@php
    /** @var array<string, mixed> $payload */
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AgriSync API Reference</title>
  <style>
    :root { color-scheme: light; --bg:#f4f7f5; --panel:#ffffff; --text:#152018; --muted:#66746b; --line:#d9e4dc; --soft:#eef5f0; --primary:#187844; --primary-dark:#0f5d33; --code:#101828; --danger:#b42318; --warning:#b54708; --blue:#175cd3; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:var(--bg); color:var(--text); }
    .wrap { width:min(1180px,100%); margin:0 auto; }
    header { padding:34px 20px 26px; background:#123524; color:white; border-bottom:4px solid #74b879; }
    .eyebrow { margin:0 0 10px; font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#bfe7c5; }
    h1 { margin:0 0 10px; font-size:clamp(30px,4vw,46px); }
    p { margin:0; }
    .sub { color:#e5f4e8; max-width:820px; line-height:1.55; }
    .summary { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-top:22px; }
    .summary div { border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.08); border-radius:8px; padding:14px; }
    .summary strong { display:block; font-size:22px; }
    .summary span { display:block; margin-top:3px; color:#cfe8d2; font-size:13px; }
    main { padding:22px 20px 44px; }
    .toolbar, .card, .nav { background:var(--panel); border:1px solid var(--line); border-radius:8px; box-shadow:0 10px 28px rgba(16,24,40,.06); }
    .toolbar { padding:16px; margin-bottom:16px; display:grid; gap:12px; grid-template-columns:1fr 1.4fr auto; align-items:end; }
    label { display:grid; gap:6px; font-size:13px; font-weight:800; color:var(--muted); }
    input, textarea { width:100%; border:1px solid var(--line); border-radius:6px; padding:10px 12px; font:inherit; color:var(--text); background:#fff; }
    input:focus, textarea:focus { outline:2px solid rgba(24,120,68,.18); border-color:var(--primary); }
    textarea { min-height:112px; resize:vertical; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; }
    button { border:0; border-radius:6px; background:var(--primary); color:white; padding:11px 14px; font-weight:800; cursor:pointer; }
    button:hover { background:var(--primary-dark); }
    .grid { display:grid; grid-template-columns:300px 1fr; gap:16px; }
    .nav { padding:12px; position:sticky; top:12px; align-self:start; max-height:calc(100vh - 24px); overflow:auto; }
    .nav-title { margin:4px 6px 10px; font-size:12px; font-weight:900; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); }
    .nav button { width:100%; display:flex; align-items:center; gap:8px; margin:5px 0; background:transparent; color:var(--text); text-align:left; border:1px solid transparent; font-weight:800; }
    .nav button:hover { border-color:var(--line); background:var(--soft); }
    .method { display:inline-flex; min-width:66px; justify-content:center; border-radius:999px; padding:4px 8px; margin-right:8px; font-size:12px; color:white; background:#475467; }
    .GET{background:var(--blue)}.POST{background:var(--primary)}.PUT{background:var(--warning)}.DELETE{background:var(--danger)}
    .card { padding:18px; margin-bottom:14px; }
    .card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:10px; }
    .card h2 { margin:0; font-size:18px; }
    .path { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; color:var(--code); overflow-wrap:anywhere; }
    .badge { border:1px solid var(--line); border-radius:999px; padding:5px 9px; color:var(--muted); font-size:12px; font-weight:800; white-space:nowrap; }
    .meta { color:var(--muted); font-size:14px; line-height:1.5; margin:0 0 14px; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin:12px 0; }
    pre { white-space:pre-wrap; overflow:auto; background:#0f172a; color:#e5e7eb; border-radius:8px; padding:14px; min-height:120px; }
    .hint { color:var(--muted); font-size:13px; margin-top:8px; }
    .actions { margin-top:12px; display:flex; align-items:center; gap:10px; }
    .suite { padding:18px; margin-bottom:16px; }
    .suite-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .suite-head h2 { margin:0; font-size:18px; }
    .suite-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin:10px 0 14px; }
    .suite-grid label { font-size:12px; }
    .suite-controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .pill { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:800; border:1px solid var(--line); color:var(--muted); background:#fff; }
    .pill.pass { color:#067647; border-color:#abefc6; background:#ecfdf3; }
    .pill.fail { color:var(--danger); border-color:#fecdca; background:#fef3f2; }
    .pill.skip { color:#475467; border-color:var(--line); background:var(--soft); }
    .pill.run  { color:var(--blue); border-color:#b2ddff; background:#eff8ff; }
    .results { border:1px solid var(--line); border-radius:8px; overflow:hidden; }
    .result-row { display:grid; grid-template-columns:90px 1fr 110px 90px; gap:10px; align-items:center; padding:10px 12px; border-top:1px solid var(--line); font-size:13px; }
    .result-row:first-child { border-top:0; background:var(--soft); font-weight:800; color:var(--muted); text-transform:uppercase; font-size:11px; letter-spacing:.06em; }
    .result-row .path { font-size:13px; }
    .result-detail { grid-column:1 / -1; margin:0; padding:10px 12px; background:#0f172a; color:#e5e7eb; border-radius:6px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; white-space:pre-wrap; overflow:auto; max-height:220px; display:none; }
    .result-detail.open { display:block; }
    .result-row.clickable { cursor:pointer; }
    .result-row.clickable:hover { background:var(--soft); }
    @media (max-width: 820px) { .toolbar, .grid, .row, .summary, .suite-grid { grid-template-columns:1fr; } .nav { position:static; max-height:none; } .card-head { display:block; } .badge { display:inline-block; margin-top:10px; } .result-row { grid-template-columns:70px 1fr 80px; } .result-row > :nth-child(4) { display:none; } }
  </style>
</head>
<body>
  <header>
    <div class="wrap">
      <p class="eyebrow">Developer Console</p>
      <h1>AgriSync API Reference</h1>
      <p class="sub">A secure, interactive workspace for validating authentication, user management, and plot configuration endpoints against the live backend.</p>
      <div class="summary">
        <div><strong id="endpointCount">0</strong><span>documented endpoints</span></div>
        <div><strong>Bearer</strong><span>Sanctum token authentication</span></div>
        <div><strong>v1</strong><span>stable API namespace available</span></div>
      </div>
    </div>
  </header>
  <main class="wrap">
    <section class="toolbar">
      <label>Base URL<input id="baseUrl" autocomplete="off"></label>
      <label>Bearer token<input id="token" placeholder="Paste token from /api/auth/login"></label>
      <button id="clearToken" type="button">Clear token</button>
    </section>
    <section class="card suite" id="suite">
      <div class="suite-head">
        <div>
          <h2>One-click test suite</h2>
          <p class="hint" style="margin-top:4px">Logs in, then runs every documented endpoint in safe order. New endpoints are picked up automatically.</p>
        </div>
        <div class="suite-controls">
          <span class="pill" id="suiteSummary">Idle</span>
          <button id="runSuite" type="button">Run all tests</button>
        </div>
      </div>
      <div class="suite-grid">
        <label>Test admin email<input id="suiteEmail" value="admin@agrisync.test" autocomplete="off"></label>
        <label>Test admin password<input id="suitePassword" type="password" value="Password123!" autocomplete="off"></label>
      </div>
      <div class="results" id="suiteResults">
        <div class="result-row"><div>Method</div><div>Endpoint</div><div>Status</div><div>Time</div></div>
      </div>
    </section>
    <section class="grid">
      <aside class="card nav" id="nav"></aside>
      <section id="content"></section>
    </section>
  </main>
  <script id="api-data" type="application/json">{!! $json !!}</script>
  <script>
    const docs = JSON.parse(document.getElementById('api-data').textContent);
    const endpoints = docs.endpoints;
    const nav = document.getElementById('nav');
    const content = document.getElementById('content');
    const token = document.getElementById('token');
    const baseUrl = document.getElementById('baseUrl');
    document.getElementById('endpointCount').textContent = endpoints.length;
    token.value = localStorage.getItem('agrisync_api_token') || '';
    baseUrl.value = location.origin;
    token.addEventListener('input', () => localStorage.setItem('agrisync_api_token', token.value.trim()));
    document.getElementById('clearToken').onclick = () => { token.value = ''; localStorage.removeItem('agrisync_api_token'); };

    const pretty = (value) => JSON.stringify(value ?? {}, null, 2);

    function render() {
      nav.innerHTML = '';
      const navTitle = document.createElement('div');
      navTitle.className = 'nav-title';
      navTitle.textContent = 'Endpoints';
      nav.appendChild(navTitle);
      content.innerHTML = '';
      endpoints.forEach((endpoint, index) => {
        const navButton = document.createElement('button');
        navButton.innerHTML = `<span class="method ${endpoint.method}">${endpoint.method}</span><span>${endpoint.group}</span>`;
        navButton.onclick = () => document.getElementById(`endpoint-${index}`).scrollIntoView({ behavior: 'smooth', block: 'start' });
        nav.appendChild(navButton);

        const card = document.createElement('article');
        card.className = 'card';
        card.id = `endpoint-${index}`;
        card.innerHTML = `
          <div class="card-head"><h2><span class="method ${endpoint.method}">${endpoint.method}</span><span class="path">${endpoint.path}</span></h2><span class="badge">${endpoint.requires_auth ? 'Authenticated' : 'Public'}</span></div>
          <p class="meta">${endpoint.description}</p>
          <div class="row">
            <label>Path<input class="pathInput" value="${endpoint.path}"></label>
            <label>Query JSON<textarea class="queryInput">${pretty(endpoint.query)}</textarea></label>
          </div>
          <label>Body JSON<textarea class="bodyInput">${endpoint.body ? pretty(endpoint.body) : ''}</textarea></label>
          <div class="hint">For path placeholders like {id}, {plot}, or {fertilizer}, replace the placeholder before sending.</div>
          <div class="actions"><button type="button" class="sendBtn">Send request</button><span class="hint">Response status and body are shown below.</span></div>
          <pre class="result">Response will appear here.</pre>
        `;
        card.querySelector('.sendBtn').onclick = () => send(card, endpoint);
        content.appendChild(card);
      });
    }

    async function send(card, endpoint) {
      const result = card.querySelector('.result');
      const pathValue = card.querySelector('.pathInput').value.trim();
      const queryText = card.querySelector('.queryInput').value.trim();
      const bodyText = card.querySelector('.bodyInput').value.trim();
      try {
        const query = queryText ? JSON.parse(queryText) : {};
        const url = new URL(pathValue, baseUrl.value.trim() || location.origin);
        Object.entries(query).forEach(([key, value]) => value !== '' && value !== null && value !== undefined && url.searchParams.set(key, value));
        const headers = { Accept: 'application/json' };
        const options = { method: endpoint.method, headers };
        if (token.value.trim()) headers.Authorization = `Bearer ${token.value.trim()}`;
        if (!['GET', 'HEAD'].includes(endpoint.method) && bodyText) {
          headers['Content-Type'] = 'application/json';
          options.body = bodyText;
        }
        result.textContent = 'Loading...';
        const response = await fetch(url, options);
        const text = await response.text();
        let parsed = text;
        try { parsed = JSON.parse(text); } catch (_) {}
        result.textContent = `HTTP ${response.status} ${response.statusText}\n\n${typeof parsed === 'string' ? parsed : pretty(parsed)}`;
      } catch (error) {
        result.textContent = error.message;
      }
    }

    /* ----------------------------- Test suite ----------------------------- */
    const suiteResults = document.getElementById('suiteResults');
    const suiteSummary = document.getElementById('suiteSummary');
    const runSuiteBtn = document.getElementById('runSuite');
    const suiteEmail = document.getElementById('suiteEmail');
    const suitePassword = document.getElementById('suitePassword');

    const setSummary = (text, cls) => {
      suiteSummary.className = `pill ${cls || ''}`.trim();
      suiteSummary.textContent = text;
    };

    function resetResults() {
      suiteResults.innerHTML = '<div class="result-row"><div>Method</div><div>Endpoint</div><div>Status</div><div>Time</div></div>';
    }

    function appendRow(method, path, statusCls, statusText, timeMs, detail) {
      const row = document.createElement('div');
      row.className = 'result-row clickable';
      row.innerHTML = `
        <div><span class="method ${method}">${method}</span></div>
        <div class="path">${path}</div>
        <div><span class="pill ${statusCls}">${statusText}</span></div>
        <div>${timeMs}ms</div>
      `;
      const detailEl = document.createElement('pre');
      detailEl.className = 'result-detail';
      detailEl.textContent = detail;
      row.onclick = () => detailEl.classList.toggle('open');
      suiteResults.appendChild(row);
      suiteResults.appendChild(detailEl);
    }

    async function callApi(method, path, body, bearer) {
      const url = new URL(path, baseUrl.value.trim() || location.origin);
      const headers = { Accept: 'application/json' };
      if (bearer) headers.Authorization = `Bearer ${bearer}`;
      const options = { method, headers };
      if (body && !['GET', 'HEAD'].includes(method)) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
      }
      const started = performance.now();
      let status = 0, text = '', parsed = null, error = null;
      try {
        const response = await fetch(url, options);
        status = response.status;
        text = await response.text();
        try { parsed = JSON.parse(text); } catch (_) { parsed = text; }
      } catch (e) {
        error = e.message;
      }
      return { status, parsed, raw: text, error, ms: Math.round(performance.now() - started) };
    }

    /**
     * Resolve {placeholders} in a path. Creates short-lived resources where
     * needed (plot, fertilizer, user) so update/delete endpoints have a real id.
     */
    async function resolvePath(endpoint, ctx) {
      let path = endpoint.path;
      const cleanup = [];
      const ensure = async (placeholder, factory) => {
        if (!path.includes(`{${placeholder}}`)) return;
        if (!ctx.ids[placeholder]) {
          const created = await factory();
          if (!created) return;
          ctx.ids[placeholder] = created.id;
          if (created.cleanup) cleanup.push(created.cleanup);
        }
        path = path.replaceAll(`{${placeholder}}`, ctx.ids[placeholder]);
      };

      await ensure('plot', async () => {
        const res = await callApi('POST', '/api/plots', {
          name: `Suite Plot ${Date.now()}`, surface_area_ha: 1.5,
          crop_type: 'Olive', variety: 'Picholine',
          season_start_date: new Date().toISOString().slice(0, 10), is_active: true,
        }, ctx.token);
        const id = res.parsed?.data?.id;
        return id ? { id, cleanup: { method: 'DELETE', path: `/api/plots/${id}` } } : null;
      });

      await ensure('fertilizer', async () => {
        const res = await callApi('POST', '/api/fertilizers', {
          name: `Suite NPK ${Date.now()}`, unit: 'kg',
          n_percent: 10, p_percent: 10, k_percent: 10, is_active: true,
        }, ctx.token);
        const id = res.parsed?.data?.id;
        return id ? { id, cleanup: { method: 'DELETE', path: `/api/fertilizers/${id}` } } : null;
      });

      await ensure('pesticide', async () => {
        const res = await callApi('POST', '/api/pesticides', {
          name: `Suite Pesticide ${Date.now()}`, unit: 'L',
          chemical_composition: 'Test composition', is_active: true,
        }, ctx.token);
        const id = res.parsed?.data?.id;
        return id ? { id, cleanup: { method: 'DELETE', path: `/api/pesticides/${id}` } } : null;
      });

      await ensure('waterConfig', async () => {
        const res = await callApi('POST', '/api/water-config', {
          unit: 'm3', is_active: true,
        }, ctx.token);
        const id = res.parsed?.data?.id;
        return id ? { id, cleanup: { method: 'DELETE', path: `/api/water-config/${id}` } } : null;
      });

      await ensure('laborConfig', async () => {
        const res = await callApi('POST', '/api/labor-config', { is_active: true }, ctx.token);
        const id = res.parsed?.data?.id;
        return id ? { id, cleanup: { method: 'DELETE', path: `/api/labor-config/${id}` } } : null;
      });

      await ensure('priceHistory', async () => {
        const res = await callApi('POST', '/api/prices', {
          entity_type: 'water', entity_id: null, price_per_unit: 1.25,
          unit: 'm3', effective_from: new Date().toISOString().slice(0, 10),
        }, ctx.token);
        const id = res.parsed?.data?.id;
        return id ? { id, cleanup: { method: 'DELETE', path: `/api/prices/${id}` } } : null;
      });

      await ensure('id', async () => {
        const email = `suite.user.${Date.now()}@example.test`;
        const res = await callApi('POST', '/api/users', {
          name: 'Suite User', email, password: 'Password123!',
          roles: ['technician'], preferred_lang: 'fr',
        }, ctx.token);
        const id = res.parsed?.data?.id;
        return id ? { id, cleanup: { method: 'DELETE', path: `/api/users/${id}` } } : null;
      });

      return { path, cleanup };
    }

    const isOk = (method, status) => status !== 0 && status >= 200 && status < 300;

    async function runSuite() {
      runSuiteBtn.disabled = true;
      resetResults();
      setSummary('Logging in...', 'run');

      const login = await callApi('POST', '/api/auth/login', {
        email: suiteEmail.value.trim(), password: suitePassword.value, device_name: 'api-docs-suite',
      });
      const bearer = login.parsed?.data?.token || login.parsed?.token;
      appendRow('POST', '/api/auth/login', isOk('POST', login.status) && bearer ? 'pass' : 'fail',
        `HTTP ${login.status}`, login.ms, login.error || pretty(login.parsed));

      if (!bearer) {
        setSummary('Login failed — check credentials', 'fail');
        runSuiteBtn.disabled = false;
        return;
      }
      token.value = bearer;
      localStorage.setItem('agrisync_api_token', bearer);

      const ctx = { token: bearer, ids: {} };
      const cleanup = [];
      let pass = 1, fail = 0, skip = 0;

      // Skip endpoints we already exercised or that would kill the session mid-run.
      const skipPaths = new Set(['/api/auth/login', '/api/auth/logout', '/api/auth/logout-all']);

      for (const endpoint of endpoints) {
        if (skipPaths.has(endpoint.path)) {
          appendRow(endpoint.method, endpoint.path, 'skip', 'Skipped', 0, 'Skipped to keep the session alive.');
          skip++;
          continue;
        }
        setSummary(`Running ${endpoint.method} ${endpoint.path}`, 'run');
        let resolvedPath = endpoint.path;
        try {
          const resolved = await resolvePath(endpoint, ctx);
          resolvedPath = resolved.path;
          cleanup.push(...resolved.cleanup);
        } catch (e) {
          appendRow(endpoint.method, endpoint.path, 'fail', 'Setup error', 0, e.message);
          fail++;
          continue;
        }
        const body = endpoint.body && !['GET', 'HEAD', 'DELETE'].includes(endpoint.method) ? endpoint.body : null;
        const res = await callApi(endpoint.method, resolvedPath, body, endpoint.requires_auth ? bearer : null);
        const ok = isOk(endpoint.method, res.status);
        appendRow(endpoint.method, resolvedPath, ok ? 'pass' : 'fail',
          res.error ? 'Network' : `HTTP ${res.status}`, res.ms,
          res.error || (typeof res.parsed === 'string' ? res.parsed : pretty(res.parsed)));
        ok ? pass++ : fail++;
      }

      // Best-effort cleanup of the resources we created.
      for (const job of cleanup.reverse()) {
        await callApi(job.method, job.path, null, bearer);
      }

      setSummary(`${pass} passed · ${fail} failed · ${skip} skipped`, fail === 0 ? 'pass' : 'fail');
      runSuiteBtn.disabled = false;
    }

    runSuiteBtn.addEventListener('click', runSuite);

    render();
  </script>
</body>
</html>
