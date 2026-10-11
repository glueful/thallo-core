<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * The /_update page (spec §11): one self-contained document, served by the guard, that never touches
 * the application, vendor/ or the admin bundle — so it loads during the vendor gap, after a failed
 * provision, and after a refresh. It holds no data; everything comes from /_update/status with the
 * operation token kept in sessionStorage.
 */
final class RecoveryPage
{
    // phpcs:disable Generic.Files.LineLength.TooLong -- inline CSS/JS of a self-contained page
    public static function html(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Thallo update</title>
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#1d2330;--muted:#5d6678;--line:#dfe3ea;--ok:#2e7d4f;--warn:#a5620c;--bad:#b3261e;--accent:#3557b7}
@media (prefers-color-scheme:dark){:root{--bg:#12151b;--card:#1b2029;--text:#e6e9ef;--muted:#9aa3b2;--line:#2c3340;--ok:#5bbf86;--warn:#e0a74a;--bad:#ef7a72;--accent:#8ea8ff}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:32px 16px}
main{max-width:46rem;margin:0 auto;display:grid;gap:16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px}
h1{font-size:1.35rem;margin:0 0 4px;text-wrap:balance}
p{margin:0}
.muted{color:var(--muted)}
ol{margin:0;padding:0;list-style:none;display:grid;gap:6px}
li{display:flex;justify-content:space-between;gap:12px;font-variant-numeric:tabular-nums}
li .s-done{color:var(--ok)} li .s-running{color:var(--accent)}
pre{margin:0;max-height:22rem;overflow:auto;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:12px;font:12px/1.45 ui-monospace,Menlo,monospace;white-space:pre-wrap;word-break:break-word}
code{font:13px ui-monospace,Menlo,monospace;word-break:break-all}
.warn{color:var(--warn)} .bad{color:var(--bad)} .ok{color:var(--ok)}
.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
button{font:inherit;border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:8px;padding:8px 14px;cursor:pointer}
button.primary{background:var(--accent);border-color:var(--accent);color:#fff}
input{font:inherit;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--text)}
[hidden]{display:none!important}
</style>
</head>
<body>
<main>
  <section class="card" id="head"><h1 id="title">Thallo update</h1><p class="muted" id="subtitle">Checking…</p></section>
  <section class="card" id="token-card" hidden>
    <p>Enter the update token. On the server, <code>php bin/thallo-updater token</code> prints a new one.</p>
    <form id="token-form" class="actions"><input id="token-input" autocomplete="off" aria-label="Update token"><button class="primary">Continue</button></form>
  </section>
  <section class="card" id="busy" hidden><p class="warn">The server is busy — still checking.</p></section>
  <section class="card" id="steps-card" hidden><ol id="steps"></ol></section>
  <section class="card" id="recovery" hidden>
    <p id="recovery-error" class="bad"></p>
    <p class="muted" style="margin-top:8px">The database is never restored automatically. Backup: <code id="backup-path"></code></p>
    <p class="muted">To restore it, as the code owner: <code id="restore-cmd"></code></p>
    <div class="actions">
      <button data-option="rollback">Roll back the code</button>
      <button data-option="retry_provision">Retry provision</button>
      <button data-option="reopen">Reopen the site</button>
    </div>
    <div id="reopen-confirm" hidden style="margin-top:12px">
      <p>Type <code>REOPEN</code> to reopen the site as it is now.</p>
      <div class="actions"><input id="reopen-input" autocomplete="off" aria-label="Type REOPEN"><button class="primary" id="reopen-go">Reopen</button></div>
    </div>
    <p id="waiting" class="muted" hidden style="margin-top:8px">Waiting for the runner (up to a minute)…</p>
  </section>
  <section class="card" id="log-card" hidden><pre id="log"></pre></section>
  <p class="muted"><a href="/admin" style="color:var(--accent)">Back to the admin</a></p>
</main>
<script>
(function () {
  var KEY = 'thallo.update.token';
  var $ = function (id) { return document.getElementById(id); };
  var token = '';
  try { token = sessionStorage.getItem(KEY) || ''; } catch (e) {}
  var TITLES = {
    requested: function (o) { return 'Waiting to update to ' + o.target; },
    running: function (o) { return 'Updating to ' + o.target; },
    succeeded: function (o) { return 'Updated to ' + o.target; },
    failed: function () { return 'Update failed — nothing changed'; },
    rolled_back: function (o) { return 'Rolled back to ' + o.from; },
    recovery_required: function () { return 'Your site needs attention'; },
    recovered_by_owner: function () { return 'The site is open again'; },
    cancelled: function () { return 'The update was cancelled'; }
  };
  function show(id, on) { $(id).hidden = !on; }
  function askToken() { show('token-card', true); show('busy', false); }
  $('token-form').addEventListener('submit', function (e) {
    e.preventDefault();
    token = $('token-input').value.trim();
    try { sessionStorage.setItem(KEY, token); } catch (err) {}
    show('token-card', false);
    poll();
  });
  function duration(s) {
    if (!s.started_at) { return ''; }
    var end = s.ended_at || Math.floor(Date.now() / 1000);
    return Math.max(0, end - s.started_at) + ' s';
  }
  function render(data) {
    var o = data.operation || {};
    show('busy', false);
    if (!o.status) {
      $('title').textContent = 'No update in progress';
      $('subtitle').textContent = '';
      return;
    }
    $('title').textContent = (TITLES[o.status] || function () { return o.status; })(o);
    $('subtitle').textContent = o.error || (o.step ? 'Step: ' + o.step : '');
    var list = $('steps');
    list.textContent = '';
    (o.steps || []).forEach(function (s) {
      var li = document.createElement('li');
      var name = document.createElement('span');
      name.textContent = s.name.replace(/_/g, ' ');
      var state = document.createElement('span');
      state.className = 's-' + s.status;
      state.textContent = (s.status === 'done' ? '✓ ' : '… ') + duration(s);
      li.appendChild(name);
      li.appendChild(state);
      list.appendChild(li);
    });
    show('steps-card', (o.steps || []).length > 0);
    var log = $('log');
    var atEnd = log.scrollTop + log.clientHeight >= log.scrollHeight - 4;
    log.textContent = data.log || '';
    if (atEnd) { log.scrollTop = log.scrollHeight; }
    show('log-card', !!data.log);
    var recovering = o.status === 'recovery_required';
    show('recovery', recovering);
    if (recovering) {
      var path = (o.backup && o.backup.path) || '(no backup was taken)';
      $('recovery-error').textContent = o.error || '';
      $('backup-path').textContent = path;
      // Into an empty schema: a cleaning restore drops only what the dump holds, so a table the
      // failed update created would survive it.
      $('restore-cmd').textContent = 'psql --dbname <database> -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;" && '
        + 'pg_restore --no-owner --dbname <database> ' + path + '/database.dump';
      show('waiting', !!o.recovery_request);
      // Only what the runner offers now, from its own journal: retry needs the whole swap live,
      // roll back needs something to undo.
      var offered = data.recovery_options || [];
      document.querySelectorAll('[data-option]').forEach(function (b) {
        b.hidden = offered.indexOf(b.getAttribute('data-option')) === -1;
      });
    }
  }
  function request(option, confirm) {
    var body = { option: option };
    if (confirm) { body.confirm = confirm; }
    fetch('/_update/recover', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'x-thallo-update-token': token },
      body: JSON.stringify(body),
      cache: 'no-store'
    }).then(function (r) { if (r.status === 202) { show('waiting', true); } });
  }
  document.querySelectorAll('[data-option]').forEach(function (b) {
    b.addEventListener('click', function () {
      var option = b.getAttribute('data-option');
      if (option === 'reopen') { show('reopen-confirm', true); return; }
      request(option);
    });
  });
  $('reopen-go').addEventListener('click', function () {
    if ($('reopen-input').value.trim() === 'REOPEN') { request('reopen', 'REOPEN'); show('reopen-confirm', false); }
  });
  function poll() {
    if (!token) { askToken(); return; }
    fetch('/_update/status', { headers: { 'x-thallo-update-token': token }, cache: 'no-store' })
      .then(function (r) {
        if (r.status === 403) { askToken(); return null; }
        if (!r.ok) { throw new Error('busy'); }
        return r.json();
      })
      .then(function (data) { if (data) { render(data); setTimeout(poll, 2000); } })
      .catch(function () { show('busy', true); setTimeout(poll, 2000); });
  }
  poll();
})();
</script>
</body>
</html>
HTML;
    }
    // phpcs:enable Generic.Files.LineLength.TooLong
}
