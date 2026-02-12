/**
 * GarageMinder API Admin Tester
 */
// Auto-detect API base from current page location
// Works whether app is at /api/v1/ or /gm/api/v1/ etc.
const TESTER_PATH = window.location.pathname.replace(/\/admin-tester\/?.*$/, '');
const API_BASE = window.location.origin + TESTER_PATH + '/index.php';
let accessToken = localStorage.getItem('gm_admin_token');
let refreshToken = localStorage.getItem('gm_admin_refresh');
let currentUser = null;

const ENDPOINTS = [
    { group: 'Authentication', items: [
        { method: 'POST', path: '/auth/login', auth: false, body: { username: '', password: '', device_name: 'Admin Tester', platform: 'web' }},
        { method: 'POST', path: '/auth/token-exchange', auth: false, body: { device_name: 'Admin Tester', platform: 'web' }},
        { method: 'POST', path: '/auth/refresh', auth: false, body: { refresh_token: '' }},
        { method: 'POST', path: '/auth/logout', auth: true, body: { refresh_token: '', all_devices: false }},
        { method: 'GET',  path: '/auth/verify', auth: true },
    ]},
    { group: 'User', items: [
        { method: 'GET', path: '/user/profile', auth: true },
        { method: 'GET', path: '/user/preferences', auth: true },
        { method: 'PUT', path: '/user/preferences', auth: true, body: { auto_sync_enabled: true, sync_frequency: 'DAILY' }},
    ]},
    { group: 'Vehicles', items: [
        { method: 'GET', path: '/vehicles', auth: true },
        { method: 'GET', path: '/vehicles/{id}', auth: true, params: { id: '1' }},
        { method: 'PUT', path: '/vehicles/{id}/odometer', auth: true, params: { id: '1' }, body: { odometer: 0 }},
        { method: 'GET', path: '/vehicles/{id}/reminders', auth: true, params: { id: '1' }},
        { method: 'GET', path: '/vehicles/{id}/reminders/due', auth: true, params: { id: '1' }},
    ]},
    { group: 'Reminders', items: [
        { method: 'GET', path: '/reminders', auth: true },
        { method: 'GET', path: '/reminders/due', auth: true },
        { method: 'GET', path: '/reminders/{id}', auth: true, params: { id: '1' }},
    ]},
    { group: 'Sync', items: [
        { method: 'POST', path: '/sync/push', auth: true, body: { vehicles: [{ id: 1, odometer: 0 }] }},
        { method: 'GET',  path: '/sync/status', auth: true },
        { method: 'POST', path: '/sync/register-device', auth: true, body: { device_id: 'test-001', platform: 'android', device_name: 'Test Device' }},
    ]},
    { group: 'Subscription', items: [
        { method: 'GET', path: '/subscription/status', auth: true },
    ]},
    { group: 'Admin', items: [
        { method: 'GET',  path: '/admin/test', auth: true },
        { method: 'GET',  path: '/admin/users', auth: true },
        { method: 'GET',  path: '/admin/logs', auth: true },
        { method: 'GET',  path: '/admin/stats', auth: true },
    ]},
];

let selectedEndpoint = null;
let activeTab = 'body';
let activeRightTab = 'response';

document.addEventListener('DOMContentLoaded', () => {
    if (accessToken) {
        verifyToken().then(valid => valid ? showApp() : showLogin());
    } else {
        showLogin();
    }
});

function showLogin() {
    document.getElementById('login-screen').style.display = 'flex';
    document.getElementById('app').style.display = 'none';
}

function showApp() {
    document.getElementById('login-screen').style.display = 'none';
    document.getElementById('app').style.display = 'flex';
    renderSidebar();
    selectEndpoint(ENDPOINTS[0].items[0]);
}

// === Auth ===
async function handleLogin() {
    const u = document.getElementById('login-user').value;
    const p = document.getElementById('login-pass').value;
    const errEl = document.getElementById('login-error');
    errEl.textContent = '';
    try {
        const res = await fetch(API_BASE + '/auth/login', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: u, password: p, device_name: 'Admin Tester', platform: 'web' })
        });
        const data = await res.json();
        if (data.success) {
            accessToken = data.data.access_token;
            refreshToken = data.data.refresh_token;
            currentUser = data.data.user;
            localStorage.setItem('gm_admin_token', accessToken);
            localStorage.setItem('gm_admin_refresh', refreshToken);
            showApp();
        } else { errEl.textContent = data.error?.message || 'Login failed'; }
    } catch (e) { errEl.textContent = 'Connection error: ' + e.message; }
}

async function verifyToken() {
    try {
        const res = await fetch(API_BASE + '/auth/verify', { headers: { 'Authorization': 'Bearer ' + accessToken }});
        const data = await res.json();
        if (data.success) { currentUser = data.data.user; return true; }
        if (refreshToken) {
            const r2 = await fetch(API_BASE + '/auth/refresh', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ refresh_token: refreshToken })
            });
            const d2 = await r2.json();
            if (d2.success) {
                accessToken = d2.data.access_token; refreshToken = d2.data.refresh_token;
                localStorage.setItem('gm_admin_token', accessToken);
                localStorage.setItem('gm_admin_refresh', refreshToken);
                return true;
            }
        }
        return false;
    } catch { return false; }
}

function handleLogout() {
    localStorage.removeItem('gm_admin_token');
    localStorage.removeItem('gm_admin_refresh');
    accessToken = null; refreshToken = null; currentUser = null;
    showLogin();
}

// === Sidebar ===
function renderSidebar() {
    const nav = document.getElementById('nav-groups');
    nav.innerHTML = '';
    ENDPOINTS.forEach(group => {
        const div = document.createElement('div');
        div.className = 'nav-group';
        div.innerHTML = '<div class="nav-group-title">' + group.group + '</div>';
        group.items.forEach(ep => {
            const item = document.createElement('div');
            item.className = 'nav-item';
            item.setAttribute('data-path', ep.method + ':' + ep.path);
            item.innerHTML = '<span class="method method-' + ep.method.toLowerCase() + '">' + ep.method + '</span><span>' + ep.path + '</span>';
            item.onclick = function() { selectEndpoint(ep, this); };
            div.appendChild(item);
        });
        nav.appendChild(div);
    });
    if (currentUser) {
        document.getElementById('user-display').textContent = currentUser.display_name || currentUser.username;
        document.getElementById('user-sub').textContent = currentUser.subscription_level || 'unknown';
    }
}

// === Endpoint Selection ===
function selectEndpoint(ep, clickedEl) {
    selectedEndpoint = ep;
    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
    if (clickedEl) clickedEl.classList.add('active');

    const mb = document.getElementById('method-badge');
    mb.textContent = ep.method;
    mb.className = 'method-badge method-' + ep.method.toLowerCase();

    let path = ep.path;
    if (ep.params) Object.entries(ep.params).forEach(([k, v]) => { path = path.replace('{' + k + '}', v); });
    document.getElementById('url-input').value = API_BASE + path;

    const bodyEl = document.getElementById('request-body');
    if (ep.body) { bodyEl.value = JSON.stringify(ep.body, null, 2); bodyEl.disabled = false; }
    else { bodyEl.value = '// No body for ' + ep.method + ' requests'; bodyEl.disabled = true; }

    document.getElementById('response-status').innerHTML = '<span style="color:var(--text-muted)">Send a request to see the response</span>';
    document.getElementById('response-body').innerHTML = '';
    showRightTab('response');
}

// === Send Request ===
async function sendRequest() {
    if (!selectedEndpoint) return;
    const btn = document.getElementById('send-btn');
    btn.disabled = true; btn.textContent = 'Sending...';

    const url = document.getElementById('url-input').value;
    const method = selectedEndpoint.method;
    const start = performance.now();
    const headers = { 'Content-Type': 'application/json' };
    if (selectedEndpoint.auth && accessToken) headers['Authorization'] = 'Bearer ' + accessToken;

    const opts = { method, headers };
    if (method !== 'GET' && method !== 'HEAD') {
        const bt = document.getElementById('request-body').value;
        try { opts.body = JSON.stringify(JSON.parse(bt)); } catch { opts.body = bt; }
    }

    try {
        const res = await fetch(url, opts);
        const elapsed = Math.round(performance.now() - start);
        const data = await res.json();

        const sc = res.status < 300 ? 'status-2xx' : res.status < 500 ? 'status-4xx' : 'status-5xx';
        document.getElementById('response-status').innerHTML =
            '<span class="status-code ' + sc + '">' + res.status + ' ' + res.statusText + '</span>' +
            '<span class="response-time">' + elapsed + 'ms</span>';
        document.getElementById('response-body').innerHTML = '<pre class="json">' + syntaxHL(data) + '</pre>';

        if (selectedEndpoint.path === '/auth/login' && data.success) {
            accessToken = data.data.access_token; refreshToken = data.data.refresh_token;
            localStorage.setItem('gm_admin_token', accessToken);
            localStorage.setItem('gm_admin_refresh', refreshToken);
            if (data.data.user) currentUser = data.data.user;
        }
    } catch (e) {
        document.getElementById('response-status').innerHTML =
            '<span class="status-code status-5xx">ERROR</span><span class="response-time">' + e.message + '</span>';
        document.getElementById('response-body').innerHTML = '<pre class="json">' + (e.stack || e.message) + '</pre>';
    }
    btn.disabled = false; btn.textContent = 'Send';
}

// === Syntax Highlighting ===
function syntaxHL(obj) {
    const j = JSON.stringify(obj, null, 2);
    return j.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"([^"]+)"(?=\s*:)/g, '<span class="key">"$1"</span>')
        .replace(/:\s*"([^"]*)"/g, ': <span class="string">"$1"</span>')
        .replace(/:\s*(\d+\.?\d*)/g, ': <span class="number">$1</span>')
        .replace(/:\s*(true|false)/g, ': <span class="boolean">$1</span>')
        .replace(/:\s*(null)/g, ': <span class="null">$1</span>');
}

// === Tab Switching ===
function showRightTab(tab) {
    activeRightTab = tab;
    document.querySelectorAll('.right-tab').forEach(el => el.classList.remove('active'));
    document.querySelector('[data-right-tab="' + tab + '"]')?.classList.add('active');
    document.getElementById('response-content').style.display = tab === 'response' ? 'block' : 'none';
    document.getElementById('docs-content').style.display = tab === 'docs' ? 'block' : 'none';
}
