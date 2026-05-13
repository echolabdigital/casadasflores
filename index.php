<?php
/**
 * Casa das Flores — Painel de Disparo WhatsApp
 * index.php — interface principal
 */
session_start();
date_default_timezone_set('America/Sao_Paulo');
if (empty($_SESSION['cdf_logged'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/config.php';

function db_connect(): PDO {
    return new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/* ─── Métricas para o Dashboard ─────────────────────────────────────── */
$metrics = [
    'total_jobs'     => 0,
    'total_sent'     => 0,
    'total_errors'   => 0,
    'success_rate'   => 0,
    'last_job_date'  => null,
    'last_job_name'  => null,
    'jobs_recent'    => [],
    // CRM
    'crm_total'      => 0,
    'crm_pendente'   => 0,
    'crm_em_contato' => 0,
    'crm_ganho'      => 0,
    'crm_perdido'    => 0,
    'crm_recent'     => [],
    'top_listas'     => [],
];
try {
    $pdo = db_connect();

    // Jobs
    $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(sent),0) AS s, COALESCE(SUM(errors),0) AS e, COALESCE(SUM(total),0) AS t FROM jobs")->fetch(PDO::FETCH_ASSOC);
    $metrics['total_jobs']   = (int)$row['c'];
    $metrics['total_sent']   = (int)$row['s'];
    $metrics['total_errors'] = (int)$row['e'];
    $totalAttempts           = (int)$row['t'];
    $metrics['success_rate'] = $totalAttempts > 0 ? round(($metrics['total_sent'] / $totalAttempts) * 100, 1) : 0;

    $last = $pdo->query("SELECT job_name, started_at FROM jobs ORDER BY started_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $metrics['last_job_date'] = $last['started_at'];
        $metrics['last_job_name'] = $last['job_name'];
    }
    $metrics['jobs_recent'] = $pdo->query("SELECT id, job_name, total, sent, errors, started_at FROM jobs ORDER BY started_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    // CRM — contatos por status
    $crmStats = $pdo->query("SELECT status, COUNT(*) as c FROM contacts GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($crmStats as $s) {
        $metrics['crm_' . $s['status']] = (int)$s['c'];
        $metrics['crm_total'] += (int)$s['c'];
    }

    // CRM — top 5 listas
    $metrics['top_listas'] = $pdo->query(
        "SELECT source, COUNT(*) as total,
         SUM(status='ganho') as ganhos
         FROM contacts WHERE source IS NOT NULL AND source != ''
         GROUP BY source ORDER BY total DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    // CRM — contatos recentes
    $metrics['crm_recent'] = $pdo->query(
        "SELECT phone, name, status, source, created_at FROM contacts ORDER BY created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) { /* zeros */ }

function fmt_date(?string $d): string {
    if (!$d) return '—';
    $ts = strtotime($d);
    if (!$ts) return $d;
    return date('d/m/Y H:i', $ts);
}
function fmt_relative(?string $d): string {
    if (!$d) return 'nenhum disparo ainda';
    $ts = strtotime($d);
    if (!$ts) return $d;
    $diff = time() - $ts;
    if ($diff < 60)        return 'há instantes';
    if ($diff < 3600)      return 'há ' . floor($diff / 60) . ' min';
    if ($diff < 86400)     return 'há ' . floor($diff / 3600) . ' h';
    if ($diff < 86400 * 7) return 'há ' . floor($diff / 86400) . ' dias';
    return date('d/m/Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Casa das Flores — Painel WhatsApp</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<style>
/* ═══════════════════════════════════════════════════════════════
   DESIGN TOKENS
═══════════════════════════════════════════════════════════════ */
:root {
  --verde-900:   #0f3a1a;
  --verde-800:   #133f1e;
  --verde:       #1a5c2a;
  --verde-600:   #2d7d3f;
  --verde-100:   #e3f0e6;

  --azul:        #1E5EBE;
  --azul-100:    #e0eaf8;

  --ouro:        #c8a96e;
  --ouro-claro:  #e8d9b3;

  --creme:       #F4F0EA;
  --creme-2:     #faf6ee;
  --branco:      #ffffff;

  --texto:       #1f2421;
  --texto-2:     #4a4f4b;
  --cinza:       #8a8f8b;
  --cinza-2:     #c2c5c0;
  --borda:       #e3dfd5;
  --borda-2:     #d5cfc4;

  --success:     #2e7d32;
  --error:       #c62828;
  --warn:        #e65100;

  --shadow-sm:   0 1px 2px rgba(15, 58, 26, .04);
  --shadow:      0 4px 14px rgba(15, 58, 26, .07);
  --shadow-lg:   0 10px 30px rgba(15, 58, 26, .12);

  --radius:      10px;
  --radius-lg:   14px;

  --sidebar-w:   248px;
  --topbar-h:    64px;

  --font-display: 'Montserrat', system-ui, sans-serif;
  --font-body:    'Montserrat', system-ui, sans-serif;
  --font-mono:    'JetBrains Mono', ui-monospace, Menlo, monospace;
}

/* ═══════════════════════════════════════════════════════════════
   RESET + BASE
═══════════════════════════════════════════════════════════════ */
* { box-sizing: border-box; margin: 0; padding: 0; }
*::-webkit-scrollbar { width: 8px; height: 8px; }
*::-webkit-scrollbar-track { background: transparent; }
*::-webkit-scrollbar-thumb { background: var(--cinza-2); border-radius: 4px; }
*::-webkit-scrollbar-thumb:hover { background: var(--cinza); }

html, body { height: 100%; }
body {
  font-family: var(--font-body);
  font-size: 14.5px;
  line-height: 1.55;
  color: var(--texto);
  background: var(--creme);
  -webkit-font-smoothing: antialiased;
}
button, input, textarea, select { font: inherit; color: inherit; }
button { cursor: pointer; }
a { color: var(--azul); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ═══════════════════════════════════════════════════════════════
   LAYOUT — SIDEBAR + MAIN
═══════════════════════════════════════════════════════════════ */
.app-shell {
  display: block;
  min-height: 100vh;
}

/* ── Sidebar ── */
.sidebar {
  background: linear-gradient(180deg, var(--verde-800) 0%, var(--verde-900) 100%);
  color: rgba(255,255,255,.92);
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0;
  left: 0;
  width: var(--sidebar-w);
  height: 100vh;
  z-index: 100;
  border-right: 1px solid rgba(255,255,255,.06);
  overflow-y: auto;
}

/* ── Main offset ── */
main {
  margin-left: var(--sidebar-w);
}
.sidebar .brand {
  padding: 22px 20px 18px;
  display: flex;
  gap: 12px;
  align-items: center;
  border-bottom: 1px solid rgba(255,255,255,.08);
}
.sidebar .brand img {
  width: 42px; height: 42px;
  border-radius: 50%;
  border: 2px solid var(--ouro);
  background: #fff;
  flex-shrink: 0;
  object-fit: cover;
}
.sidebar .brand h1 {
  font-family: var(--font-display);
  font-size: 1.05rem;
  font-weight: 600;
  color: #fff;
  line-height: 1.1;
  letter-spacing: .01em;
}
.sidebar .brand small {
  font-size: .68rem;
  color: var(--ouro-claro);
  letter-spacing: .12em;
  text-transform: uppercase;
  display: block;
  margin-top: 3px;
}

/* ── Nav ── */
.nav-section {
  padding: 18px 12px 0;
  flex: 1;
  overflow-y: auto;
}
.nav-label {
  font-size: .65rem;
  letter-spacing: .14em;
  text-transform: uppercase;
  color: rgba(255,255,255,.45);
  padding: 0 12px 8px;
  font-weight: 500;
}
.nav-link {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 8px;
  color: rgba(255,255,255,.78);
  font-size: .9rem;
  font-weight: 500;
  margin-bottom: 2px;
  border: none;
  background: none;
  width: 100%;
  text-align: left;
  cursor: pointer;
  position: relative;
  transition: background .18s, color .18s;
}
.nav-link:hover { background: rgba(255,255,255,.06); color: #fff; }
.nav-link.active {
  background: rgba(200, 169, 110, .14);
  color: #fff;
}
.nav-link.active::before {
  content: '';
  position: absolute;
  left: -12px; top: 8px; bottom: 8px;
  width: 3px;
  background: var(--ouro);
  border-radius: 0 3px 3px 0;
}
.nav-link svg {
  width: 18px; height: 18px;
  flex-shrink: 0;
  stroke-width: 1.8;
}
.nav-link.disabled {
  color: rgba(255,255,255,.32);
  cursor: not-allowed;
}
.nav-link.disabled:hover { background: none; color: rgba(255,255,255,.32); }
.nav-link .soon {
  margin-left: auto;
  font-size: .6rem;
  background: rgba(255,255,255,.08);
  padding: 2px 6px;
  border-radius: 10px;
  letter-spacing: .04em;
  text-transform: uppercase;
  font-weight: 600;
}
.nav-divider {
  height: 1px;
  background: rgba(255,255,255,.08);
  margin: 10px 12px;
}

.sidebar-footer {
  padding: 16px 20px;
  border-top: 1px solid rgba(255,255,255,.08);
  font-size: .72rem;
  color: rgba(255,255,255,.45);
  font-family: var(--font-mono);
  letter-spacing: .02em;
}

/* ── Main ── */
main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.topbar {
  height: var(--topbar-h);
  background: var(--branco);
  border-bottom: 1px solid var(--borda);
  padding: 0 30px;
  display: flex;
  align-items: center;
  gap: 20px;
  position: sticky;
  top: 0;
  z-index: 50;
}
.topbar .menu-toggle {
  display: none;
  background: none;
  border: none;
  color: var(--verde);
  font-size: 1.4rem;
}
.topbar h2 {
  font-family: var(--font-display);
  font-size: 1.4rem;
  font-weight: 600;
  color: var(--verde-800);
  letter-spacing: -.005em;
}
.topbar h2 .crumb {
  color: var(--cinza);
  font-weight: 400;
  font-size: .9rem;
  margin-left: 8px;
  font-family: var(--font-body);
}
.topbar .spacer { flex: 1; }

#status-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--creme-2);
  padding: 7px 14px;
  border-radius: 24px;
  border: 1px solid var(--borda);
  font-size: .82rem;
  color: var(--texto-2);
  font-weight: 500;
}
#status-dot {
  width: 9px; height: 9px;
  border-radius: 50%;
  background: var(--cinza-2);
  flex-shrink: 0;
  transition: background .3s;
}
#status-dot.connected   { background: var(--success); box-shadow: 0 0 0 3px rgba(46,125,50,.18); }
#status-dot.connecting  { background: var(--ouro); animation: pulse 1.2s infinite; }
#status-dot.error       { background: var(--error); }
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.4} }

/* ═══════════════════════════════════════════════════════════════
   PAGES
═══════════════════════════════════════════════════════════════ */
.page-wrap {
  padding: 28px 30px 60px;
  max-width: 1240px;
  width: 100%;
  margin: 0 auto;
  flex: 1;
}
.page { display: none; animation: fadeIn .35s ease; }
.page.active { display: block; }
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ═══════════════════════════════════════════════════════════════
   CARDS
═══════════════════════════════════════════════════════════════ */
.card {
  background: var(--branco);
  border: 1px solid var(--borda);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: 24px 26px;
  margin-bottom: 20px;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--borda);
}
.card-head h3 {
  font-family: var(--font-display);
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--verde-800);
  display: flex;
  align-items: center;
  gap: 10px;
}
.card-head h3 svg { width: 18px; height: 18px; color: var(--verde); }

/* ═══════════════════════════════════════════════════════════════
   DASHBOARD
═══════════════════════════════════════════════════════════════ */
.dash-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 14px; margin-bottom: 24px;
}
.dash-header h1 {
  font-size: 1.65rem; font-weight: 800;
  color: var(--verde-800); letter-spacing: -.02em;
  line-height: 1.1;
}
.dash-header h1 span { color: var(--verde); }
.dash-header p { font-size: .88rem; color: var(--cinza); margin-top: 3px; }
.dash-quick {
  display: flex; gap: 8px; flex-wrap: wrap;
}

/* Status banner */
.dash-status-banner {
  display: flex; align-items: center; gap: 16px;
  padding: 14px 20px; border-radius: var(--radius-lg);
  margin-bottom: 22px; border: 1px solid;
  transition: all .3s;
}
.dash-status-banner.connected {
  background: var(--verde-100); border-color: rgba(46,125,50,.25);
}
.dash-status-banner.disconnected {
  background: #fdecea; border-color: rgba(198,40,40,.2);
}
.dash-status-banner.checking {
  background: var(--creme-2); border-color: var(--borda);
}
.dash-status-banner .icon-wrap {
  width: 42px; height: 42px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.dash-status-banner.connected   .icon-wrap { background: rgba(46,125,50,.15); }
.dash-status-banner.disconnected .icon-wrap { background: rgba(198,40,40,.12); }
.dash-status-banner.checking    .icon-wrap { background: var(--borda); }
.dash-status-banner svg { width: 22px; height: 22px; }
.dash-status-banner .info h4 { font-size: .95rem; font-weight: 700; }
.dash-status-banner.connected    .info h4 { color: var(--success); }
.dash-status-banner.disconnected .info h4 { color: var(--error); }
.dash-status-banner.checking     .info h4 { color: var(--cinza); }
.dash-status-banner .info p { font-size: .8rem; color: var(--cinza); margin-top: 1px; }
.dash-status-banner .spacer { flex: 1; }

/* Métricas */
.metrics-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px; margin-bottom: 20px;
}
.metric {
  background: var(--branco);
  border: 1px solid var(--borda);
  border-radius: var(--radius-lg);
  padding: 20px 22px;
  position: relative; overflow: hidden;
  transition: transform .2s, box-shadow .2s;
}
.metric:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
.metric .lbl {
  font-size: .68rem; color: var(--cinza);
  text-transform: uppercase; letter-spacing: .12em;
  font-weight: 700; margin-bottom: 10px;
  display: flex; align-items: center; gap: 6px;
}
.metric .lbl svg { width: 13px; height: 13px; }
.metric .val {
  font-size: 2rem; font-weight: 800;
  color: var(--verde-800); line-height: 1;
  letter-spacing: -.02em;
}
.metric .sub {
  font-size: .75rem; color: var(--cinza);
  margin-top: 7px; font-family: var(--font-mono);
}
.metric .accent {
  position: absolute; bottom: 0; left: 0; right: 0;
  height: 3px; background: var(--verde);
}
.metric.azul   .accent { background: var(--azul); }
.metric.azul   .val    { color: var(--azul); }
.metric.ouro   .accent { background: var(--ouro); }
.metric.ouro   .val    { color: #8a6200; }
.metric.roxo   .accent { background: #7c3aed; }
.metric.roxo   .val    { color: #7c3aed; }
.metric.success .accent { background: var(--success); }
.metric.success .val   { color: var(--success); }
.metric.error  .accent { background: var(--error); }
.metric.error  .val    { color: var(--error); }

/* Dashboard grid 2 colunas */
.dash-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px; margin-bottom: 20px;
}
@media (max-width: 900px) { .dash-grid-2 { grid-template-columns: 1fr; } }

/* Pipeline CRM */
.pipeline-row {
  display: flex; gap: 0;
  border-radius: 8px; overflow: hidden;
  height: 10px; margin: 12px 0;
}
.pipeline-seg { height: 100%; transition: width .5s ease; }
.pipeline-seg.pend   { background: var(--cinza-2); }
.pipeline-seg.cont   { background: var(--azul); }
.pipeline-seg.ganho  { background: var(--success); }
.pipeline-seg.perd   { background: var(--error); }

.pipeline-legend {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 8px; margin-top: 16px;
}
.pip-item {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px; border-radius: 8px;
  background: var(--creme-2); border: 1px solid var(--borda);
  cursor: pointer; transition: all .15s;
}
.pip-item:hover { border-color: var(--verde); }
.pip-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.pip-item .pip-lbl { font-size: .75rem; color: var(--cinza); font-weight: 600; flex: 1; }
.pip-item .pip-val { font-size: 1.1rem; font-weight: 800; font-family: var(--font-mono); color: var(--texto); }
.pip-item .pip-pct { font-size: .68rem; color: var(--cinza); font-family: var(--font-mono); }

/* Tabela recent */
.recent-table {
  width: 100%; border-collapse: collapse; font-size: .86rem;
}
.recent-table th {
  text-align: left; padding: 9px 12px;
  font-size: .65rem; text-transform: uppercase;
  letter-spacing: .1em; color: var(--cinza); font-weight: 700;
  border-bottom: 1px solid var(--borda);
}
.recent-table td { padding: 12px 12px; border-bottom: 1px solid var(--borda); }
.recent-table tr:last-child td { border-bottom: none; }
.recent-table tr:hover td { background: var(--creme-2); }
.recent-table .name { font-weight: 700; color: var(--verde-800); }
.recent-table .date-mono { font-family: var(--font-mono); font-size: .76rem; color: var(--cinza); }

/* Top listas */
.lista-row {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid var(--borda);
}
.lista-row:last-child { border-bottom: none; }
.lista-row .lista-name { flex: 1; font-weight: 600; font-size: .87rem; color: var(--texto); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lista-row .lista-bar-wrap { flex: 0 0 80px; height: 5px; background: var(--borda); border-radius: 3px; overflow: hidden; }
.lista-row .lista-bar { height: 100%; background: var(--verde); border-radius: 3px; }
.lista-row .lista-num { font-family: var(--font-mono); font-size: .75rem; color: var(--cinza); width: 32px; text-align: right; }

.empty-state {
  text-align: center; padding: 36px 20px; color: var(--cinza);
}
.empty-state svg { width: 48px; height: 48px; margin-bottom: 10px; opacity: .35; }
.empty-state h4 { font-size: 1rem; font-weight: 700; margin-bottom: 4px; color: var(--texto-2); }

/* ═══════════════════════════════════════════════════════════════
   FORMS
═══════════════════════════════════════════════════════════════ */
.form-group { margin-bottom: 18px; }
.form-group label {
  display: block;
  font-size: .76rem;
  font-weight: 600;
  color: var(--texto-2);
  text-transform: uppercase;
  letter-spacing: .08em;
  margin-bottom: 7px;
}
.form-group .hint {
  font-size: .76rem;
  color: var(--cinza);
  margin-top: 6px;
}
.form-group .hint code {
  background: var(--creme-2);
  padding: 1px 6px;
  border-radius: 4px;
  font-family: var(--font-mono);
  font-size: .76rem;
}

input[type="text"],
input[type="number"],
input[type="file"],
textarea,
select {
  width: 100%;
  padding: 11px 14px;
  border: 1px solid var(--borda-2);
  border-radius: 8px;
  background: var(--branco);
  font-size: .9rem;
  font-family: var(--font-body);
  color: var(--texto);
  transition: border-color .15s, box-shadow .15s;
}
input[type="text"]:focus,
input[type="number"]:focus,
textarea:focus,
select:focus {
  outline: none;
  border-color: var(--verde);
  box-shadow: 0 0 0 3px rgba(46,125,50,.12);
}
textarea { resize: vertical; min-height: 110px; line-height: 1.5; }
input[type="file"] { padding: 9px 12px; cursor: pointer; }

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 18px;
  border-radius: 8px;
  border: 1px solid transparent;
  font-size: .87rem;
  font-weight: 600;
  letter-spacing: .01em;
  cursor: pointer;
  transition: all .15s;
  background: none;
  text-decoration: none;
  white-space: nowrap;
}
.btn:hover { transform: translateY(-1px); }
.btn:active { transform: translateY(0); }
.btn svg { width: 15px; height: 15px; }

.btn-primary {
  background: var(--verde);
  color: #fff;
  box-shadow: 0 2px 8px rgba(26, 92, 42, .25);
  position: relative;
}
.btn-primary:hover { background: var(--verde-600); box-shadow: 0 4px 14px rgba(26, 92, 42, .35); }
.btn-primary.loading, .btn-azul.loading, .btn-outline.loading, .btn-danger.loading {
  opacity: .7; cursor: not-allowed; pointer-events: none;
}
.btn-primary.loading::after, .btn-azul.loading::after, .btn-outline.loading::after, .btn-danger.loading::after {
  content: '';
  display: inline-block;
  width: 12px; height: 12px;
  border: 2px solid rgba(255,255,255,.5);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin .6s linear infinite;
  margin-left: 8px;
  vertical-align: middle;
}
.btn-outline.loading::after { border-color: rgba(26,92,42,.3); border-top-color: var(--verde); }
@keyframes spin { to { transform: rotate(360deg); } }

/* Toast notifications */
#toast-container {
  position: fixed; bottom: 24px; right: 24px;
  z-index: 9999; display: flex; flex-direction: column; gap: 8px;
  pointer-events: none;
}
.toast {
  background: #1a2e1e; color: #fff;
  padding: 12px 16px; border-radius: 10px;
  font-size: .85rem; font-weight: 500;
  box-shadow: 0 4px 20px rgba(0,0,0,.3);
  max-width: 320px; pointer-events: all;
  display: flex; align-items: center; gap: 10px;
  animation: toastIn .25s ease;
  border-left: 4px solid var(--verde);
}
.toast.success { border-color: #4caf50; }
.toast.error   { border-color: #f44336; background: #2e1a1a; }
.toast.warn    { border-color: #ff9800; background: #2e2618; }
@keyframes toastIn { from { opacity:0; transform:translateX(20px); } to { opacity:1; transform:none; } }
@keyframes toastOut { to { opacity:0; transform:translateX(20px); } }

.btn-azul {
  background: var(--azul);
  color: #fff;
  box-shadow: 0 2px 8px rgba(30, 94, 190, .25);
}
.btn-azul:hover { background: #1750a8; }

.btn-outline {
  background: var(--branco);
  color: var(--verde);
  border: 1px solid var(--borda-2);
}
.btn-outline:hover { background: var(--verde-100); border-color: var(--verde); }

.btn-danger {
  background: var(--error);
  color: #fff;
  box-shadow: 0 2px 8px rgba(198,40,40,.25);
}
.btn-danger:hover { background: #a72020; }

.btn-ghost {
  background: none;
  color: var(--cinza);
  padding: 6px 10px;
}
.btn-ghost:hover { background: var(--creme-2); color: var(--texto); }

/* ═══════════════════════════════════════════════════════════════
   ALERTS
═══════════════════════════════════════════════════════════════ */
.alert {
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 18px;
  font-size: .87rem;
  border: 1px solid transparent;
  display: flex;
  gap: 10px;
  align-items: flex-start;
}
.alert.warn { background: #fff8e8; border-color: #f0d489; color: #8a5800; }
.alert.error { background: #fdecea; border-color: #f1b3ad; color: #8b1e1e; }
.alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

/* ═══════════════════════════════════════════════════════════════
   QR CODE
═══════════════════════════════════════════════════════════════ */
.qr-grid {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 30px;
  align-items: start;
}
.qr-box {
  background: linear-gradient(135deg, var(--creme-2), var(--branco));
  border: 1px solid var(--borda);
  border-radius: 14px;
  padding: 18px;
  width: 280px;
  height: 280px;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}
.qr-box img { max-width: 100%; max-height: 100%; border-radius: 6px; }
.qr-placeholder {
  text-align: center;
  color: var(--cinza);
  font-size: .8rem;
}
.qr-placeholder svg {
  width: 56px; height: 56px;
  margin-bottom: 10px;
  opacity: .5;
}
.qr-spinner {
  width: 36px; height: 36px;
  border: 3px solid var(--borda);
  border-top-color: var(--verde);
  border-radius: 50%;
  animation: spin 1s linear infinite;
  display: none;
}
@keyframes spin { to { transform: rotate(360deg); } }

.qr-info h4 {
  font-family: var(--font-display);
  font-size: 1.2rem;
  color: var(--verde-800);
  margin-bottom: 8px;
}
.qr-info p { color: var(--texto-2); margin-bottom: 16px; font-size: .9rem; }
.qr-info ol { padding-left: 22px; color: var(--texto-2); font-size: .87rem; }
.qr-info ol li { margin-bottom: 6px; }
.qr-info ol li strong { color: var(--verde); }

.qr-actions { margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap; }

.conn-info dl {
  display: grid;
  grid-template-columns: 130px 1fr;
  gap: 8px 16px;
  font-size: .87rem;
}
.conn-info dt { color: var(--cinza); font-weight: 500; }
.conn-info dd { color: var(--texto); font-family: var(--font-mono); font-size: .83rem; }

/* ═══════════════════════════════════════════════════════════════
   IMAGE PREVIEW
═══════════════════════════════════════════════════════════════ */
.img-preview-wrap {
  margin-top: 12px;
  display: none;
  background: var(--creme-2);
  border: 1px solid var(--borda);
  border-radius: 8px;
  padding: 12px;
}
.img-preview-wrap img {
  max-width: 200px;
  max-height: 200px;
  border-radius: 6px;
  display: block;
  margin-bottom: 8px;
}
.img-preview-wrap .url-out {
  font-family: var(--font-mono);
  font-size: .73rem;
  word-break: break-all;
  color: var(--cinza);
}

/* ═══════════════════════════════════════════════════════════════
   PROGRESS
═══════════════════════════════════════════════════════════════ */
.progress-wrap { margin-top: 18px; display: none; }
.progress-bar {
  width: 100%; height: 10px;
  background: var(--borda);
  border-radius: 6px;
  overflow: hidden;
  margin-bottom: 8px;
}
#progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--verde) 0%, var(--verde-600) 100%);
  width: 0%;
  transition: width .3s;
}
#progress-text {
  font-size: .82rem;
  color: var(--texto-2);
  font-family: var(--font-mono);
  margin-bottom: 12px;
}
#progress-log {
  max-height: 220px;
  overflow-y: auto;
  background: var(--creme-2);
  border: 1px solid var(--borda);
  border-radius: 6px;
  padding: 10px 14px;
  font-size: .8rem;
  font-family: var(--font-mono);
  line-height: 1.7;
}
.log-line.ok  { color: var(--success); }
.log-line.err { color: var(--error); }
.log-line.info { color: var(--cinza); font-style: italic; }

/* ═══════════════════════════════════════════════════════════════
   HISTORY TABLE
═══════════════════════════════════════════════════════════════ */
.history-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .88rem;
}
.history-table th {
  text-align: left;
  padding: 10px 12px;
  font-size: .68rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--cinza);
  font-weight: 600;
  background: var(--creme-2);
  border-bottom: 1px solid var(--borda);
}
.history-table th:first-child { border-radius: 8px 0 0 0; }
.history-table th:last-child  { border-radius: 0 8px 0 0; }
.history-table td {
  padding: 14px 12px;
  border-bottom: 1px solid var(--borda);
  vertical-align: middle;
}
.history-table tr:hover td { background: var(--creme-2); }
.badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 9px;
  border-radius: 12px;
  font-size: .73rem;
  font-weight: 600;
  font-family: var(--font-mono);
}
.badge.ok  { background: var(--verde-100); color: var(--success); }
.badge.err { background: #fdecea; color: var(--error); }
.badge.zero { background: var(--creme-2); color: var(--cinza); }

/* ═══════════════════════════════════════════════════════════════
   CONTACTS PREVIEW
═══════════════════════════════════════════════════════════════ */
.contacts-count {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--verde-100);
  color: var(--verde);
  padding: 6px 14px;
  border-radius: 18px;
  font-size: .82rem;
  font-weight: 600;
}
#contacts-preview {
  display: none;
  margin-top: 12px;
  font-family: var(--font-mono);
  font-size: .77rem;
  color: var(--texto-2);
  max-height: 130px;
  overflow-y: auto;
  background: var(--creme-2);
  border: 1px solid var(--borda);
  border-radius: 8px;
  padding: 10px 14px;
  line-height: 1.7;
}

/* ═══════════════════════════════════════════════════════════════
   CRM
═══════════════════════════════════════════════════════════════ */
.crm-tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 18px;
}
.crm-tab {
  padding: 7px 16px;
  border-radius: 20px;
  border: 1px solid var(--borda-2);
  background: var(--branco);
  font-size: .82rem;
  font-weight: 600;
  color: var(--cinza);
  cursor: pointer;
  transition: all .15s;
  display: flex;
  align-items: center;
  gap: 6px;
}
.crm-tab:hover { border-color: var(--verde); color: var(--verde); }
.crm-tab.active { background: var(--verde); color: #fff; border-color: var(--verde); }
.crm-tab .cnt {
  background: rgba(255,255,255,.25);
  border-radius: 10px;
  padding: 1px 7px;
  font-size: .75rem;
  font-family: var(--font-mono);
}
.crm-tab:not(.active) .cnt {
  background: var(--creme-2);
  color: var(--cinza);
}

.crm-search {
  position: relative;
  margin-bottom: 18px;
}
.crm-search svg {
  position: absolute;
  left: 12px; top: 50%;
  transform: translateY(-50%);
  width: 16px; height: 16px;
  color: var(--cinza);
  pointer-events: none;
}
.crm-search input {
  padding-left: 38px !important;
}

/* ══ KANBAN ══════════════════════════════════════════════════════ */
.kanban-wrap {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  align-items: start;
  min-height: 400px;
}
@media (max-width: 1100px) { .kanban-wrap { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 600px)  { .kanban-wrap { grid-template-columns: 1fr; } }

.kanban-col {
  background: var(--creme-2);
  border-radius: 12px;
  border: 1px solid var(--borda);
  overflow: hidden;
  min-height: 120px;
}
.kanban-col-head {
  padding: 11px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: .75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  border-bottom: 2px solid transparent;
}
.kanban-col-head.pendente   { background:#f5f5f5; border-color:#ccc; color:#555; }
.kanban-col-head.em_contato { background:#eff6ff; border-color:#3b82f6; color:#1d4ed8; }
.kanban-col-head.ganho      { background:#f0fdf4; border-color:#16a34a; color:#15803d; }
.kanban-col-head.perdido    { background:#fef2f2; border-color:#dc2626; color:#b91c1c; }

.kanban-col-head .kcnt {
  background: rgba(0,0,0,.08);
  border-radius: 10px;
  padding: 1px 8px;
  font-family: var(--font-mono);
  font-size: .72rem;
}
.kanban-cards {
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 60px;
}
/* drag-over highlight */
.kanban-cards.drag-over { background: rgba(200,169,110,.15); border-radius: 8px; }

.kcard {
  background: #fff;
  border-radius: 10px;
  border: 1px solid var(--borda);
  padding: 12px 13px;
  cursor: pointer;
  transition: box-shadow .15s, transform .1s;
  box-shadow: 0 1px 3px rgba(0,0,0,.06);
  position: relative;
}
.kcard:hover {
  box-shadow: 0 4px 14px rgba(0,0,0,.10);
  transform: translateY(-1px);
}
.kcard.dragging { opacity: .4; transform: rotate(1.5deg); }
.kcard-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}
.kcard-empresa {
  font-weight: 700;
  font-size: .87rem;
  color: var(--verde-800);
  line-height: 1.3;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.kcard-avatar {
  width: 30px; height: 30px;
  border-radius: 50%;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: .78rem;
  font-weight: 800;
  color: #fff;
}
.kcard-phone {
  font-family: var(--font-mono);
  font-size: .74rem;
  color: var(--cinza);
  margin-bottom: 5px;
}
.kcard-camp {
  font-size: .7rem;
  font-weight: 600;
  color: #1a5c2a;
  background: #f0f7f0;
  padding: 2px 7px;
  border-radius: 6px;
  display: inline-block;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.kcard-blocked {
  position: absolute;
  top: 8px; right: 8px;
  font-size: .6rem;
  background: #fef2f2;
  color: #c62828;
  border: 1px solid #fca5a5;
  border-radius: 6px;
  padding: 1px 5px;
}
.kcard-notes {
  font-size: .72rem;
  color: var(--cinza);
  margin-top: 5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Toggle lista/kanban */
.view-toggle {
  display: flex;
  background: var(--creme-2);
  border: 1px solid var(--borda);
  border-radius: 8px;
  padding: 3px;
  gap: 2px;
}
.view-toggle button {
  padding: 5px 10px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: var(--cinza);
  cursor: pointer;
  font-size: .78rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 5px;
  transition: all .15s;
}
.view-toggle button.active {
  background: #fff;
  color: var(--verde);
  box-shadow: 0 1px 4px rgba(0,0,0,.08);
}

.crm-table { width: 100%; border-collapse: collapse; font-size: .87rem; }
.crm-table th {
  text-align: left;
  padding: 10px 12px;
  font-size: .68rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--cinza);
  font-weight: 600;
  background: var(--creme-2);
  border-bottom: 1px solid var(--borda);
}
.crm-table td { padding: 12px 12px; border-bottom: 1px solid var(--borda); vertical-align: middle; }
.crm-table tr:hover td { background: var(--creme-2); }
.crm-table td .phone { font-family: var(--font-mono); font-size: .82rem; color: var(--texto); }
.crm-table td .source { font-size: .76rem; color: var(--cinza); margin-top: 2px; }

.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 14px;
  font-size: .73rem;
  font-weight: 600;
  cursor: pointer;
  border: none;
  transition: opacity .15s;
}
.status-pill:hover { opacity: .8; }
.status-pill.pendente   { background: #f0f0f0;   color: #555; }
.status-pill.em_contato { background: var(--azul-100); color: var(--azul); }
.status-pill.ganho      { background: var(--verde-100); color: var(--success); }
.status-pill.perdido    { background: #fdecea;   color: var(--error); }

.crm-actions { display: flex; gap: 8px; }
.crm-pagination {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 14px;
  font-size: .82rem;
  color: var(--cinza);
}
.crm-pagination .pages { display: flex; gap: 6px; }
.crm-pagination button {
  padding: 5px 12px;
  border-radius: 6px;
  border: 1px solid var(--borda-2);
  background: var(--branco);
  font-size: .82rem;
  cursor: pointer;
  font-weight: 600;
  transition: all .15s;
}
.crm-pagination button:hover:not(:disabled) { background: var(--verde); color: #fff; border-color: var(--verde); }
.crm-pagination button:disabled { opacity: .4; cursor: not-allowed; }

/* ═══════════════════════════════════════════════════════════════
   FASE 2 — TEMPLATES / VARIAÇÕES / PAUSA
═══════════════════════════════════════════════════════════════ */
.tpl-bar {
  display: flex; gap: 8px; align-items: center;
  background: var(--creme-2); border: 1px solid var(--borda);
  border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; flex-wrap: wrap;
}
.tpl-bar label { font-size: .72rem; font-weight: 600; color: var(--cinza); text-transform: uppercase; letter-spacing: .08em; white-space: nowrap; }
.tpl-bar select { flex: 1; min-width: 160px; padding: 6px 10px; border-radius: 6px; border: 1px solid var(--borda-2); background: var(--branco); font-size: .84rem; }
.tpl-bar select:focus { outline: none; border-color: var(--verde); }

.var-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.var-chip {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 10px; border-radius: 12px;
  background: var(--azul-100); color: var(--azul);
  font-family: var(--font-mono); font-size: .72rem; font-weight: 600;
  cursor: pointer; border: 1px solid rgba(30,94,190,.2);
  transition: all .15s; user-select: none;
}
.var-chip:hover { background: var(--azul); color: #fff; }

.variations-wrap { display: flex; flex-direction: column; gap: 10px; }
.variation-item {
  display: flex; gap: 8px; align-items: flex-start;
}
.variation-item textarea { flex: 1; min-height: 80px; border-radius: 8px; }
.variation-num {
  width: 24px; height: 24px; border-radius: 50%;
  background: var(--verde); color: #fff;
  font-size: .72rem; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; margin-top: 11px; font-family: var(--font-mono);
}
.variation-del {
  background: none; border: none; color: var(--cinza);
  cursor: pointer; padding: 8px 4px; margin-top: 4px;
  transition: color .15s;
}
.variation-del:hover { color: var(--error); }

.progress-paused { background: var(--ouro) !important; }

/* ═══════════════════════════════════════════════════════════════
   ORGANIZADOR
═══════════════════════════════════════════════════════════════ */
.org-tabs { display: flex; gap: 0; margin-bottom: 18px; background: var(--creme-2); border-radius: 10px; padding: 4px; border: 1px solid var(--borda); }
.org-tab {
  flex: 1; padding: 9px 16px; border-radius: 8px; border: none;
  background: none; font-size: .85rem; font-weight: 600; color: var(--cinza);
  cursor: pointer; transition: all .15s;
}
.org-tab.active { background: var(--branco); color: var(--verde); box-shadow: var(--shadow-sm); }

.org-results-grid { display: grid; gap: 8px; max-height: 440px; overflow-y: auto; padding-right: 2px; }
.org-item {
  display: grid; grid-template-columns: 1fr auto;
  gap: 12px; padding: 12px 16px;
  background: var(--creme-2); border: 1px solid var(--borda);
  border-radius: 8px; transition: border-color .2s;
}
.org-item:hover { border-color: var(--verde); }
.org-item .name { font-weight: 600; font-size: .87rem; color: var(--texto); }
.org-item .phone {
  font-family: var(--font-mono); font-size: .75rem; color: var(--verde);
  margin-top: 3px; display: flex; align-items: center; gap: 5px;
}
.org-item .phone.none { color: var(--cinza); }
.org-stats { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
.org-stat {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 12px; border-radius: 18px;
  background: var(--creme-2); border: 1px solid var(--borda);
  font-family: var(--font-mono); font-size: .75rem; color: var(--cinza);
}
.org-stat.ready { border-color: var(--verde); color: var(--verde); background: var(--verde-100); }
.org-stat.warn  { border-color: #e57c00; color: #e57c00; background: #fff8f0; }

.org-send-bar {
  display: none; margin-top: 18px; padding: 16px;
  background: var(--verde-100); border: 1px solid var(--verde);
  border-radius: 10px;
}
.org-send-bar p { font-size: .85rem; color: var(--verde-800); margin-bottom: 12px; font-weight: 600; }
.org-send-bar .actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* ── Organizador — settings panel ── */
.org-settings-toggle {
  display: flex; align-items: center; gap: 6px; cursor: pointer;
  font-size: .8rem; color: var(--cinza); font-weight: 600;
  padding: 6px 0; user-select: none; border: none; background: none;
  transition: color .2s;
}
.org-settings-toggle:hover { color: var(--verde); }
.org-settings-panel {
  display: none; margin-top: 12px; padding: 14px 16px;
  background: var(--creme); border-radius: 10px; border: 1px solid var(--borda);
  display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;
}
.org-settings-panel.hidden { display: none !important; }
.org-setting-item label { font-size: .78rem; font-weight: 700; color: var(--texto); margin-bottom: 5px; display: block; }
.org-setting-item input[type=text], .org-setting-item select {
  width: 100%; padding: 7px 10px; border: 1px solid var(--borda);
  border-radius: 7px; font-size: .82rem; background: var(--branco);
}
.org-setting-item .toggle-row { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.org-setting-item .toggle-row input[type=checkbox] { accent-color: var(--verde); width: 16px; height: 16px; }
.org-setting-item .toggle-row span { font-size: .82rem; color: var(--cinza); }

/* ── Extrator de Leads ── */
.ext-mode-tabs {
  display: flex; gap: 4px; margin-bottom: 14px;
  background: var(--creme); padding: 4px; border-radius: 10px;
  border: 1px solid var(--borda); width: fit-content;
}
.ext-mode-tab {
  padding: 8px 18px; border: none; background: transparent; cursor: pointer;
  font-size: .82rem; font-weight: 700; color: var(--cinza);
  border-radius: 7px; transition: all .15s; letter-spacing: .02em;
  display: inline-flex; align-items: center; gap: 6px;
}
.ext-mode-tab:hover { color: var(--texto); }
.ext-mode-tab.active {
  background: var(--branco); color: var(--verde);
  box-shadow: 0 1px 3px rgba(0,0,0,.06);
}

.ext-form-grid {
  display: grid;
  grid-template-columns: 1.1fr 1fr 1.3fr;
  gap: 12px; margin-bottom: 14px;
}

/* ═══ Wizard de 3 passos (cidade → bairro → categoria) ═══ */
.ext-steps-indicator {
  display: flex; align-items: center; gap: 8px;
  margin: 6px 0 18px; padding: 12px 16px;
  background: linear-gradient(135deg, var(--creme) 0%, #fff 100%);
  border-radius: 12px; border: 1px solid var(--borda);
  flex-wrap: wrap;
}
.ext-step-dot {
  display: flex; align-items: center; gap: 8px;
  opacity: .4; transition: opacity .25s;
}
.ext-step-dot.active   { opacity: 1; }
.ext-step-dot.complete { opacity: 1; }
.ext-step-dot > span {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--cinza); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: .78rem; flex-shrink: 0;
  transition: background .25s;
}
.ext-step-dot.active   > span { background: var(--verde); box-shadow: 0 0 0 4px rgba(26,92,42,.12); }
.ext-step-dot.complete > span { background: var(--verde); }
.ext-step-dot.complete > span::before { content: '✓'; }
.ext-step-dot.complete > span > :not(::before) { display: none; }
.ext-step-dot label {
  font-size: .8rem; font-weight: 700; color: var(--texto); cursor: default;
}
.ext-step-dot label em {
  font-style: normal; font-weight: 500; color: var(--cinza); font-size: .72rem;
}
.ext-step-line {
  flex: 1; min-width: 24px; height: 2px;
  background: var(--borda); border-radius: 99px;
}

.ext-step-card {
  background: var(--branco); border: 1px solid var(--borda); border-radius: 12px;
  padding: 16px 18px; margin-bottom: 10px;
  transition: all .25s; opacity: 1;
}
.ext-step-card.disabled {
  opacity: .45; pointer-events: none; background: var(--creme);
}
.ext-step-card.complete {
  background: linear-gradient(135deg, #f0f7f0 0%, #fff 100%);
  border-color: #86c190;
}
.ext-step-card.active {
  border-color: var(--verde); box-shadow: 0 4px 14px rgba(26,92,42,.08);
}
.ext-step-head {
  display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px;
}
.ext-step-num {
  width: 30px; height: 30px; border-radius: 50%;
  background: var(--verde); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: .9rem; flex-shrink: 0;
}
.ext-step-card.disabled .ext-step-num { background: var(--cinza); }
.ext-step-card.complete .ext-step-num::before { content: '✓'; font-size: 1rem; }
.ext-step-card.complete .ext-step-num > :not(::before) { display: none; }
.ext-step-head h4 {
  margin: 0; font-size: .96rem; font-weight: 700; color: var(--texto);
}
.ext-step-head h4 .opt {
  font-style: normal; font-weight: 500; color: var(--cinza); font-size: .72rem;
  background: var(--creme); padding: 2px 8px; border-radius: 99px; margin-left: 6px;
}
.ext-step-head p {
  margin: 3px 0 0; font-size: .78rem; color: var(--cinza); line-height: 1.4;
}
.ext-step-body {
  display: flex; gap: 10px; align-items: stretch; flex-wrap: wrap;
}
.ext-step-body input[type=text] {
  flex: 1; min-width: 220px; padding: 11px 14px; font-size: .92rem;
  border: 1px solid var(--borda); border-radius: 9px;
  background: var(--branco); transition: all .15s;
}
.ext-step-body input[type=text]:focus {
  outline: none; border-color: var(--verde);
  box-shadow: 0 0 0 3px rgba(26,92,42,.1);
}
.ext-step-card.disabled .ext-step-body input[type=text] { background: transparent; }
.ext-step-next, .ext-step-skip {
  padding: 10px 18px; font-weight: 700; font-size: .85rem;
  display: inline-flex; align-items: center; gap: 6px;
  white-space: nowrap;
}

.ext-form-field { display: flex; flex-direction: column; gap: 5px; }
.ext-form-field label {
  font-size: .72rem; font-weight: 700; color: var(--cinza);
  text-transform: uppercase; letter-spacing: .08em;
  display: flex; align-items: center; gap: 5px;
}
.ext-form-field label .req { color: var(--error); }
.ext-form-field label .opt {
  font-size: .65rem; color: var(--cinza); font-weight: 500;
  text-transform: none; letter-spacing: 0;
  background: var(--creme); padding: 2px 7px; border-radius: 99px;
}
.ext-form-field input[type=text] {
  width: 100%; padding: 10px 12px; font-size: .88rem;
  border: 1px solid var(--borda); border-radius: 9px;
  background: var(--branco); transition: border-color .15s, box-shadow .15s;
}
.ext-form-field input[type=text]:focus {
  outline: none; border-color: var(--verde);
  box-shadow: 0 0 0 3px rgba(26,92,42,.08);
}
.ext-form-field small {
  font-size: .7rem; color: var(--cinza); font-style: italic;
}

.ext-search-actions {
  display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
  padding-top: 4px;
}
.ext-search-actions .btn-buscar {
  font-size: .92rem; padding: 11px 24px; font-weight: 700;
  display: inline-flex; align-items: center; gap: 8px;
}
.ext-search-hint {
  flex: 1; font-size: .75rem; color: var(--cinza);
  display: flex; align-items: center; gap: 6px;
}

.ext-progress-card {
  background: var(--creme); border: 1px solid var(--borda); border-radius: 10px;
  padding: 12px 16px; margin-top: 12px; display: none;
}
.ext-progress-card.active { display: block; }
.ext-progress-bar {
  height: 6px; background: var(--borda); border-radius: 99px; overflow: hidden;
  margin-top: 8px;
}
.ext-progress-bar > div {
  height: 100%; background: linear-gradient(90deg, var(--verde) 0%, #5a8a5a 100%);
  width: 0%; transition: width .35s ease;
}
.ext-progress-text {
  font-size: .8rem; color: var(--texto); font-weight: 600;
  display: flex; align-items: center; gap: 8px; justify-content: space-between;
}
.ext-progress-text .count {
  font-family: var(--font-mono); color: var(--verde); font-weight: 700;
}

.ext-manual-steps {
  display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap;
}
.ext-manual-step {
  flex: 1; min-width: 140px; background: var(--creme); border-radius: 8px;
  padding: 9px 11px; font-size: .76rem; font-weight: 600; color: var(--texto);
  border: 1px solid var(--borda); text-align: center; line-height: 1.35;
}
.ext-manual-step b { color: var(--verde); }

.ext-filter-bar {
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
  margin-bottom: 14px; padding: 12px 16px;
}
.ext-filter-bar .ext-stats { display: flex; gap: 8px; flex-wrap: wrap; flex: 1; }
.ext-filters {
  display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}
.ext-filters input[type=text], .ext-filters select {
  padding: 7px 10px; border: 1px solid var(--borda); border-radius: 8px;
  font-size: .82rem; background: var(--branco); min-width: 120px;
}
.ext-filters .toggle-label {
  display: flex; align-items: center; gap: 6px;
  font-size: .8rem; font-weight: 600; cursor: pointer; color: var(--cinza);
  white-space: nowrap;
}
.ext-filters .toggle-label input { accent-color: var(--verde); width: 15px; height: 15px; }

.ext-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
}
.ext-card {
  background: var(--branco); border-radius: 12px; padding: 16px;
  border: 1px solid var(--borda); transition: all .18s; position: relative;
  display: flex; flex-direction: column; gap: 8px;
}
.ext-card:hover { border-color: var(--verde); box-shadow: var(--shadow); transform: translateY(-1px); }
.ext-card.no-phone { opacity: .65; }
.ext-card-header { display: flex; align-items: flex-start; gap: 10px; }
.ext-avatar {
  width: 38px; height: 38px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; font-weight: 800; color: #fff; flex-shrink: 0;
}
.ext-card-name { font-weight: 700; font-size: .9rem; color: var(--texto); line-height: 1.3; }
.ext-card-cat  { font-size: .75rem; color: var(--cinza); margin-top: 2px; }
.ext-rating {
  display: flex; align-items: center; gap: 5px;
  font-size: .82rem; font-weight: 700; color: #e67e00;
}
.ext-rating .stars { letter-spacing: -.5px; }
.ext-rating .count { font-weight: 400; color: var(--cinza); font-size: .75rem; }
.ext-phone {
  display: flex; align-items: center; gap: 6px;
  font-size: .85rem; font-weight: 700; color: var(--verde);
  background: var(--verde-100); border-radius: 7px; padding: 6px 10px;
}
.ext-phone svg { flex-shrink: 0; }
.ext-phone.none { color: var(--cinza); background: var(--creme); font-weight: 400; font-size: .8rem; }
.ext-addr { font-size: .78rem; color: var(--cinza); display: flex; align-items: flex-start; gap: 5px; line-height: 1.4; }
.ext-card-actions { display: flex; gap: 6px; margin-top: 4px; }
.ext-card-actions button {
  flex: 1; padding: 6px 0; border-radius: 7px; border: 1px solid var(--borda);
  background: var(--creme); font-size: .75rem; font-weight: 700; cursor: pointer;
  color: var(--cinza); transition: all .15s;
}
.ext-card-actions button:hover { background: var(--verde); color: #fff; border-color: var(--verde); }
.ext-card-actions button.primary { background: var(--verde); color: #fff; border-color: var(--verde); }
.ext-card-actions button.primary:hover { background: var(--verde-800); }

.ext-empty {
  grid-column: 1/-1; text-align: center; padding: 50px 20px;
  color: var(--cinza); font-size: .9rem;
}
.ext-select-cb {
  position: absolute; top: 10px; right: 10px;
  width: 17px; height: 17px; accent-color: var(--verde); cursor: pointer;
}

@media (max-width: 820px) {
  .ext-form-grid { grid-template-columns: 1fr; }
  .ext-filter-bar { flex-direction: column; align-items: flex-start; }
  .ext-grid { grid-template-columns: 1fr; }
}

.sched-toggle {
  display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap;
}
.sched-toggle label {
  display: flex; align-items: center; gap: 7px; padding: 9px 16px;
  border: 1px solid var(--borda); border-radius: 9px; cursor: pointer;
  font-size: .88rem; font-weight: 600; color: var(--cinza);
  background: var(--creme); transition: all .15s; user-select: none;
}
.sched-toggle label:has(input:checked) {
  border-color: var(--verde); color: var(--verde); background: #f0f7f0;
}
.sched-toggle input[type=radio] { accent-color: var(--verde); width: 15px; height: 15px; }

.sched-picker-wrap {
  background: #fffbf0; border: 1px solid #c8a96e44;
  border-radius: 10px; padding: 14px 16px; margin-bottom: 14px;
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.sched-picker-wrap label { font-size: .8rem; font-weight: 700; color: var(--cinza); white-space: nowrap; }
#sched-datetime {
  padding: 9px 12px; border: 1px solid var(--borda); border-radius: 8px;
  font-size: .9rem; color: var(--texto); background: var(--branco);
  flex: 1; min-width: 220px;
}
#sched-datetime:focus { outline: none; border-color: var(--ouro,#c8a96e); }
.sched-hint-text {
  font-size: .8rem; color: var(--ouro,#c8a96e); font-weight: 600;
  display: flex; align-items: center; gap: 5px;
}

/* badge scheduled no histórico */
.badge-scheduled {
  display: inline-flex; align-items: center; gap: 5px;
  background: #fffbf0; border: 1px solid #c8a96e55; color: #8a6f3a;
  font-size: .72rem; font-weight: 700; border-radius: 99px;
  padding: 3px 10px;
}
.badge-scheduled svg { flex-shrink: 0; }

/* ── CRM upgrades ── */
.crm-pill-wrap { display: flex; gap: 4px; }
.crm-pill {
  padding: 4px 10px; border-radius: 14px; border: 1px solid transparent;
  font-size: .73rem; font-weight: 600; cursor: pointer; transition: all .12s; background: none;
}
.crm-pill.pendente   { background:#f0f0f0; color:#555; }
.crm-pill.em_contato { background:var(--azul-100); color:var(--azul); }
.crm-pill.ganho      { background:var(--verde-100); color:var(--success); }
.crm-pill.perdido    { background:#fdecea; color:var(--error); }
.crm-pill:hover { opacity:.75; transform:scale(1.05); }
.crm-pill.active { box-shadow: 0 0 0 2px currentColor; }

/* ═══════════════════════════════════════════════════════════════
   SOON / PLACEHOLDER PAGES
═══════════════════════════════════════════════════════════════ */
.soon-page {
  text-align: center;
  padding: 80px 20px;
  color: var(--cinza);
}
.soon-page svg { width: 72px; height: 72px; opacity: .35; margin-bottom: 18px; }
.soon-page h2 {
  font-family: var(--font-display);
  font-size: 1.6rem;
  color: var(--verde-800);
  margin-bottom: 10px;
}

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVO
═══════════════════════════════════════════════════════════════ */
/* ═══ RESPONSIVO ═══════════════════════════════════════════════ */
@media (max-width: 980px) {
  .metrics-grid { grid-template-columns: repeat(2, 1fr); }
  .dash-grid-2  { grid-template-columns: 1fr; }
  .qr-grid { grid-template-columns: 1fr; justify-items: center; }
  .qr-info { text-align: left; }
  .pipeline-legend { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 760px) {
  /* Sidebar como drawer */
  main { margin-left: 0; }
  .sidebar {
    z-index: 200;
    transform: translateX(-100%);
    transition: transform .28s cubic-bezier(.4,0,.2,1);
  }
  .sidebar.open { transform: translateX(0); box-shadow: 0 0 40px rgba(0,0,0,.4); }
  .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 150; }
  .sidebar-overlay.show { display: block; }

  /* Topbar mobile */
  .topbar { padding: 0 16px; height: 56px; gap: 12px; }
  .topbar .menu-toggle { display: flex; align-items: center; }
  .topbar h2 { font-size: 1rem; }
  .topbar h2 .crumb { display: none; }
  #status-bar { padding: 5px 10px; }
  #status-bar .status-text { display: none; }

  /* Conteúdo */
  .page-wrap { padding: 16px 14px 60px; }
  .card { padding: 16px 14px; margin-bottom: 14px; }
  .card-head { flex-wrap: wrap; gap: 10px; margin-bottom: 14px; padding-bottom: 12px; }
  .card-head h3 { font-size: 1rem; }

  /* Dashboard */
  .dash-header { flex-direction: column; align-items: flex-start; gap: 12px; }
  .dash-header h1 { font-size: 1.35rem; }
  .dash-quick { width: 100%; }
  .dash-quick .btn { flex: 1; font-size: .8rem; padding: 9px 10px; }
  .dash-status-banner { padding: 12px 14px; gap: 12px; }
  .dash-status-banner .icon-wrap { width: 36px; height: 36px; }
  .dash-status-banner svg { width: 18px; height: 18px; }
  .dash-status-banner .info h4 { font-size: .88rem; }
  .metrics-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
  .metric { padding: 14px 14px; }
  .metric .val { font-size: 1.6rem; }
  .metric .lbl { font-size: .63rem; }

  /* Pipeline */
  .pipeline-legend { grid-template-columns: 1fr 1fr; gap: 6px; }
  .pip-item { padding: 8px 10px; }
  .pip-item .pip-val { font-size: .95rem; }

  /* QR */
  .qr-box { width: 100%; max-width: 260px; height: 260px; }
  .qr-actions { flex-direction: column; }
  .conn-info dl { grid-template-columns: 100px 1fr; }

  /* Disparo */
  .var-chips { gap: 5px; }
  .var-chip { font-size: .68rem; padding: 3px 8px; }
  .tpl-bar { gap: 6px; }
  .tpl-bar select { min-width: 0; }
  #progress-log { font-size: .76rem; max-height: 160px; }

  /* Contatos preview */
  #contacts-preview { font-size: .78rem; }

  /* Tabelas — scroll horizontal */
  .crm-table, .recent-table, .history-table { font-size: .8rem; }
  .crm-pill-wrap { flex-wrap: wrap; gap: 3px; }
  .crm-pill { font-size: .68rem; padding: 3px 8px; }

  /* Org tabs */
  .org-tabs { overflow-x: auto; flex-wrap: nowrap; }
  .org-tab { white-space: nowrap; font-size: .78rem; padding: 7px 12px; }

  /* CRM source select */
  #crm-source-select { font-size: .82rem; }

  /* Buttons */
  .btn { font-size: .82rem; padding: 9px 14px; }
  .btn svg { width: 14px; height: 14px; }

  /* Formulários */
  input[type="text"], input[type="number"], input[type="file"], textarea, select {
    font-size: .88rem; padding: 10px 12px;
  }
  .form-group label { font-size: .68rem; }
  .form-group .hint { font-size: .72rem; }
}

/* ── CONVERSAS ─────────────────────── */
.chat-wrap { display:grid; grid-template-columns:300px 1fr; gap:0; height:calc(100vh - 120px); border:1px solid var(--borda); border-radius:14px; overflow:hidden; background:#fff; }
.chat-list  { border-right:1px solid var(--borda); overflow-y:auto; display:flex; flex-direction:column; }
.chat-list-header { padding:14px 16px; font-weight:700; font-size:.9rem; border-bottom:1px solid var(--borda); display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
.chat-item  { padding:12px 16px; cursor:pointer; border-bottom:1px solid var(--borda-2); transition:background .1s; display:flex; gap:12px; align-items:flex-start; }
.chat-item:hover { background:var(--creme); }
.chat-item.active { background:#f0f7f0; border-left:3px solid var(--verde); }
.chat-item.unread .chat-item-name { font-weight:700; }
.chat-avatar { width:42px; height:42px; border-radius:50%; background:var(--verde); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1rem; flex-shrink:0; }
.chat-item-body { flex:1; min-width:0; }
.chat-item-name { font-size:.87rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.chat-item-last { font-size:.75rem; color:var(--cinza); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:2px; }
.chat-item-meta { display:flex; flex-direction:column; align-items:flex-end; gap:4px; flex-shrink:0; }
.chat-item-time { font-size:.68rem; color:var(--cinza); white-space:nowrap; }
.chat-badge { background:var(--verde); color:#fff; border-radius:10px; font-size:.65rem; font-weight:700; padding:2px 6px; min-width:18px; text-align:center; }
.chat-crm-badge { font-size:.62rem; padding:2px 6px; border-radius:8px; font-weight:600; }
.chat-main { display:flex; flex-direction:column; }
.chat-contact-header { padding:14px 18px; border-bottom:1px solid var(--borda); display:flex; align-items:center; gap:12px; flex-shrink:0; position:relative; }
.chat-contact-name { font-weight:700; font-size:.95rem; }
.chat-contact-sub { font-size:.75rem; color:var(--cinza); margin-top:1px; }
.chat-messages { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:8px; background:#f4f0ea; }
.chat-bubble { max-width:72%; padding:9px 13px; border-radius:12px; font-size:.85rem; line-height:1.5; word-break:break-word; }
.chat-bubble.received { background:#fff; border-bottom-left-radius:3px; align-self:flex-start; box-shadow:0 1px 3px rgba(0,0,0,.08); }
.chat-bubble.sent { background:#d9fdd3; border-bottom-right-radius:3px; align-self:flex-end; }
.chat-bubble-time { font-size:.65rem; color:var(--cinza); margin-top:3px; text-align:right; }
.chat-input-area { padding:12px 16px; border-top:1px solid var(--borda); display:flex; gap:10px; align-items:flex-end; flex-shrink:0; background:#fff; }
.chat-input { flex:1; resize:none; border:1px solid var(--borda-2); border-radius:22px; padding:10px 16px; font-size:.87rem; max-height:120px; outline:none; font-family:inherit; line-height:1.4; }
.chat-input:focus { border-color:var(--verde); }
.chat-send-btn { width:42px; height:42px; background:var(--verde); border:none; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background .15s; }
.chat-send-btn:hover { background:var(--verde-600); }
.chat-empty { flex:1; display:flex; align-items:center; justify-content:center; flex-direction:column; gap:10px; color:var(--cinza); }
@media (max-width:768px) { .chat-wrap { grid-template-columns:1fr; } .chat-list { display:none; } .chat-list.show { display:flex; } }

@media (max-width: 480px) {
  .metrics-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
  .dash-quick .btn .btn-label { display: none; }
  .topbar h2 { font-size: .95rem; }

  /* Tabelas CRM — esconder colunas menos importantes */
  .crm-table th:nth-child(5),
  .crm-table td:nth-child(5) { display: none; }

  /* History table compact */
  .history-table th:nth-child(6),
  .history-table td:nth-child(6) { display: none; }

  /* Pipeline 1 col */
  .pipeline-legend { grid-template-columns: 1fr 1fr; }

  /* Sidebar brand menor */
  .sidebar .brand { padding: 16px 14px; }
  .sidebar .brand img { width: 34px; height: 34px; }
  .sidebar .brand h1 { font-size: .95rem; }

  /* Overflow tables */
  .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }

  .page-wrap { padding: 12px 10px 60px; }
  .card { padding: 14px 12px; border-radius: 10px; }

  /* Variações — stack vertical */
  .variations-wrap .variation-item { flex-direction: column; }

  /* Disparo — botões em coluna */
  .send-actions { flex-direction: column; }
  .send-actions .btn { width: 100%; }

  /* Toast — ocupa mais tela */
  #toast-container { bottom: 16px; right: 12px; left: 12px; }
  .toast { max-width: 100%; }

  /* Grid de aquecimento — 1 col */
  .dispatch-warmup-grid { grid-template-columns: 1fr !important; }

  /* CRM filtros em coluna */
  #crm-source-select { min-width: unset; }

  /* Modal de confirmação responsivo */
  .confirm-modal-inner { max-width: 100% !important; margin: 0 !important; border-radius: 14px 14px 0 0 !important; position: fixed !important; bottom: 0; left: 0; right: 0; }

  /* Botões do cabeçalho CRM — scroll horizontal */
  .crm-header-btns { overflow-x: auto; flex-wrap: nowrap !important; padding-bottom: 4px; }

  /* Inputs maiores no mobile para acessibilidade */
  input, textarea, select { font-size: 16px !important; } /* evita zoom no iOS */
}

@media (max-width: 380px) {
  .metrics-grid { grid-template-columns: 1fr; }
  .metric .val { font-size: 1.6rem; }
  .sidebar { width: 85vw; }
}

/* Touch improvements */
@media (hover: none) {
  .btn:hover { transform: none; }
  .metric:hover { transform: none; box-shadow: none; }
  .nav-link:hover { background: none; }
  .nav-link.active { background: rgba(200,169,110,.14); }
  /* Aumentar área de toque */
  .btn { min-height: 44px; }
  .nav-link { min-height: 44px; }
}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>

<body>
<div id="toast-container"></div>

<div class="app-shell">

  <!-- ═══ SIDEBAR ═══ -->
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <img src="logo.png" alt="" onerror="this.style.display='none'">
      <div>
        <h1>Casa das Flores</h1>
        <small>Painel WhatsApp</small>
      </div>
    </div>


    <div class="nav-section">

      <button class="nav-link active" data-page="dashboard">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
        Dashboard
      </button>

      <button class="nav-link" data-page="conversas" id="nav-conversas">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Conversas
        <span id="nav-unread-badge" style="display:none;background:#ef4444;color:#fff;border-radius:10px;font-size:.62rem;font-weight:700;padding:1px 6px;margin-left:auto">0</span>
      </button>

      <div class="nav-divider"></div>

      <button class="nav-link" data-page="disparo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>
        Disparo
      </button>

      <button class="nav-link" data-page="crm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        CRM
      </button>

      <button class="nav-link" data-page="extrator">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
        Extrator Maps
      </button>

      <button class="nav-link" data-page="organizador">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        Organizador de Lista
      </button>

      <div class="nav-divider"></div>

      <button class="nav-link" data-page="historico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
        Histórico
      </button>

      <button class="nav-link" data-page="conexao">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
        Conexão
      </button>

      <div class="nav-divider"></div>
      <div class="nav-label" style="padding:6px 12px 4px;font-size:.6rem">Em breve</div>

      <button class="nav-link disabled" disabled>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        Gerador de Arte <span class="soon">Em breve</span>
      </button>

      <div class="nav-divider"></div>

      <button class="nav-link" data-page="boaspraticas" onclick="goPage('boaspraticas')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Boas Práticas
      </button>

    </div>

    <div class="sidebar-footer">
      Casa das Flores · desde 1983<br>
      Florianópolis · SC
      <button onclick="goPage('configuracoes')" style="display:block;width:100%;text-align:left;background:none;border:none;margin-top:10px;color:rgba(255,255,255,.4);font-size:.7rem;cursor:pointer;padding:0;transition:color .15s" onmouseover="this.style.color='rgba(255,255,255,.8)'" onmouseout="this.style.color='rgba(255,255,255,.4)'">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11" style="vertical-align:middle;margin-right:4px"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Configurações
      </button>
      <button onclick="showOnboardingStep(0)" style="display:block;width:100%;text-align:left;background:none;border:none;margin-top:6px;color:rgba(255,255,255,.4);font-size:.7rem;cursor:pointer;padding:0;transition:color .15s" onmouseover="this.style.color='rgba(255,255,255,.8)'" onmouseout="this.style.color='rgba(255,255,255,.4)'">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11" style="vertical-align:middle;margin-right:4px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Ver tutorial de início
      </button>
      <a href="logout.php" style="display:block;margin-top:8px;color:rgba(255,255,255,.4);font-size:.7rem;text-decoration:none;transition:color .15s" onmouseover="this.style.color='rgba(255,255,255,.8)'" onmouseout="this.style.color='rgba(255,255,255,.4)'">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11" style="vertical-align:middle;margin-right:4px"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sair da conta
      </a>
      <a href="https://www.echolab.digital" target="_blank" style="display:block;margin-top:12px;padding-top:10px;border-top:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.25);font-size:.62rem;text-decoration:none;letter-spacing:.04em;transition:color .15s" onmouseover="this.style.color='rgba(200,169,110,.7)'" onmouseout="this.style.color='rgba(255,255,255,.25)'">
        Desenvolvido por echo_lab_digital
      </a>
    </div>
  </aside>

  <div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar(false)"></div>

  <!-- ═══ MAIN ═══ -->
  <main>
    <header class="topbar">
      <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Menu">☰</button>
      <h2 id="page-title">Dashboard <span class="crumb">Visão geral</span></h2>
      <div class="spacer"></div>
      <div id="status-bar">
        <div id="status-dot"></div>
        <span class="status-text" id="status-text">Verificando…</span>
      </div>
    </header>

    <div class="page-wrap">

      <!-- ═══════════════════════════════════════════════════
           PÁGINA — CONVERSAS
      ═══════════════════════════════════════════════════ -->
      <section id="page-conversas" class="page" style="padding:0;height:calc(100vh - 60px)">
        <div class="chat-wrap">

          <!-- Lista de conversas -->
          <div class="chat-list" id="chat-list-panel" style="position:relative">

            <!-- Overlay de offline: cobre o painel inteiro, não deixa rolar -->
            <div id="chat-offline-overlay" style="display:none;position:absolute;inset:0;background:var(--creme,#F4F0EA);z-index:20;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;padding:24px">
              <svg viewBox="0 0 24 24" fill="none" stroke="#c62828" stroke-width="1.5" width="40" height="40" style="opacity:.6"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55M5 12.55a10.94 10.94 0 0 1 5.17-2.39M10.71 5.05A16 16 0 0 1 22.56 9M1.42 9a15.91 15.91 0 0 1 4.7-2.88M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01"/></svg>
              <div style="font-size:.9rem;font-weight:700;color:#c62828">WhatsApp desconectado</div>
              <div style="font-size:.78rem;color:var(--cinza);line-height:1.5;max-width:220px">Novas mensagens não chegam.<br>Reconecte em <strong>Conexão</strong> para retomar.</div>
              <button class="btn btn-outline" style="font-size:.78rem;padding:7px 16px;margin-top:4px;border-color:#c62828;color:#c62828" onclick="goPage('conexao')">Ir para Conexão →</button>
            </div>
            <div class="chat-list-header">
              <span>💬 Conversas</span>
              <div style="display:flex;gap:4px">
                <button class="btn btn-ghost" style="padding:4px 8px;font-size:.72rem" onclick="loadChatList()" title="Atualizar lista">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                </button>
                <button class="btn btn-ghost" style="padding:4px 8px;font-size:.72rem;color:var(--cinza)" onclick="clearChatHistory()" title="Sincronizar conversas do celular conectado">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </div>
            </div>

            <!-- Banner de status de conexão -->
            <div id="chat-conn-banner" style="display:none;padding:8px 14px;font-size:.78rem;font-weight:600;align-items:center;gap:8px;border-bottom:1px solid var(--borda)">
              <span id="chat-conn-dot" style="width:8px;height:8px;border-radius:50%;flex-shrink:0;background:#888"></span>
              <span id="chat-conn-text">Verificando conexão…</span>
              <a onclick="goPage('conexao')" style="margin-left:auto;font-size:.72rem;color:var(--verde);cursor:pointer;white-space:nowrap">Gerenciar →</a>
            </div>

            <!-- Search dentro da lista -->
            <div style="padding:8px 10px;border-bottom:1px solid var(--borda);background:var(--creme)">
              <div style="display:flex;align-items:center;gap:6px;background:var(--branco);border:1px solid var(--borda);border-radius:8px;padding:6px 10px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13" style="opacity:.4;flex-shrink:0"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input id="chat-search" type="text" placeholder="Buscar conversa…"
                  style="border:none;outline:none;background:transparent;font-size:.83rem;width:100%;color:var(--texto)"
                  oninput="chatFilterList()">
              </div>
            </div>

            <div id="chat-list-body" style="flex:1;overflow-y:auto">
              <div style="padding:24px;text-align:center;color:var(--cinza);font-size:.83rem">Nenhuma conversa ainda.<br>As mensagens recebidas aparecerão aqui.</div>
            </div>
          </div>

          <!-- Área da conversa -->
          <div class="chat-main" id="chat-main-panel">
            <div class="chat-empty" id="chat-empty-state">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" width="52" height="52" style="opacity:.2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              <div style="font-weight:600;font-size:.9rem;color:var(--cinza)">Selecione uma conversa</div>
              <div style="font-size:.78rem;color:var(--cinza);opacity:.7">As mensagens aparecem aqui</div>
            </div>
            <div id="chat-active" style="display:none;flex-direction:column;flex:1;overflow:hidden">
              <div class="chat-contact-header">
                <div id="chat-active-avatar" class="chat-avatar" style="width:38px;height:38px;font-size:.85rem">?</div>
                <div style="flex:1;min-width:0">
                  <div class="chat-contact-name" id="chat-active-name">—</div>
                  <div class="chat-contact-sub" id="chat-active-sub">—</div>
                </div>
                <div style="display:flex;gap:6px;align-items:center">
                  <span id="chat-active-crm" class="chat-crm-badge" style="display:none"></span>
                  <!-- Botão + CRM (quando contato NÃO está no CRM) -->
                  <button id="chat-btn-add-crm" class="btn btn-primary" style="padding:5px 12px;font-size:.75rem;display:none" onclick="chatQuickAddCRM()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    + Cadastrar
                  </button>
                  <!-- Botão CRM (quando JÁ está no CRM) -->
                  <button id="chat-btn-open-crm" class="btn btn-outline" style="padding:5px 10px;font-size:.75rem" onclick="chatOpenCRM()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    CRM
                  </button>
                </div>

                <!-- Modal rápido de cadastro -->
                <div id="chat-add-crm-modal" style="display:none;position:absolute;top:60px;right:12px;z-index:999;background:#fff;border:1px solid var(--borda);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.15);padding:18px;min-width:280px;max-width:320px">
                  <div style="font-weight:700;font-size:.9rem;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between">
                    <span>Cadastrar no CRM</span>
                    <button onclick="chatCloseAddCRM()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--cinza)">×</button>
                  </div>
                  <div style="display:flex;flex-direction:column;gap:8px">
                    <input id="chat-add-nome" type="text" placeholder="Nome (opcional)" style="padding:9px 12px;border:1px solid var(--borda);border-radius:8px;font-size:.86rem;width:100%;box-sizing:border-box">
                    <input id="chat-add-empresa" type="text" placeholder="Empresa (opcional)" style="padding:9px 12px;border:1px solid var(--borda);border-radius:8px;font-size:.86rem;width:100%;box-sizing:border-box">
                    <button onclick="chatConfirmAddCRM()" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:4px">
                      Salvar no CRM
                    </button>
                  </div>
                </div>
              </div>
              <div class="chat-messages" id="chat-messages-body"></div>
              <div class="chat-input-area">
                <textarea id="chat-input" class="chat-input" rows="1" placeholder="Digite sua mensagem..." onkeydown="chatInputKeydown(event)" oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px'"></textarea>
                <button class="chat-send-btn" onclick="chatSend()">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" width="18" height="18"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>
                </button>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- ═══════════════════════════════════════════════════
           PÁGINA — DASHBOARD
      ═══════════════════════════════════════════════════ -->
      <section id="page-dashboard" class="page active">

        <?php
        $crmTotal = $metrics['crm_total'];
        $conversao = $crmTotal > 0 ? round($metrics['crm_ganho'] / $crmTotal * 100, 1) : 0;
        $pPend = $crmTotal > 0 ? round($metrics['crm_pendente']   / $crmTotal * 100) : 0;
        $pCont = $crmTotal > 0 ? round($metrics['crm_em_contato'] / $crmTotal * 100) : 0;
        $pGanh = $crmTotal > 0 ? round($metrics['crm_ganho']      / $crmTotal * 100) : 0;
        $pPerd = $crmTotal > 0 ? round($metrics['crm_perdido']     / $crmTotal * 100) : 0;
        $maxLista = !empty($metrics['top_listas']) ? max(array_column($metrics['top_listas'], 'total')) : 1;
        ?>

        <!-- Header + ações rápidas -->
        <div class="dash-header">
          <div>
            <h1>Casa das <span>Flores</span></h1>
            <p>Painel de disparos WhatsApp · <?= date('d/m/Y') ?></p>
          </div>
          <div class="dash-quick">
            <button class="btn btn-primary" onclick="goPage('disparo')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>
              Novo Disparo
            </button>
            <button class="btn btn-outline" onclick="goPage('crm')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
              CRM
            </button>
            <button class="btn btn-outline" onclick="goPage('organizador')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Organizador
            </button>
            <button class="btn btn-ghost" onclick="loadDashboard()" title="Atualizar métricas" style="padding:9px 12px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            </button>
          </div>
        </div>

        <!-- Banner WhatsApp status -->
        <div class="dash-status-banner checking" id="dash-status-banner">
          <div class="icon-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          </div>
          <div class="info">
            <h4 id="dash-status-title">Verificando conexão…</h4>
            <p id="dash-status-sub">Instância: <?= htmlspecialchars(ZAPI_INSTANCE) ?></p>
          </div>
          <div class="spacer"></div>
          <button class="btn btn-ghost" style="font-size:.8rem" onclick="goPage('conexao')">Gerenciar →</button>
        </div>

        <!-- Row 1 — 6 métricas -->
        <div class="metrics-grid">
          <div class="metric">
            <div class="lbl"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>Campanhas</div>
            <div class="val" id="dash-m-jobs"><?= number_format($metrics['total_jobs'], 0, ',', '.') ?></div>
            <div class="sub">disparos realizados</div>
            <div class="accent"></div>
          </div>
          <div class="metric azul">
            <div class="lbl"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 12c0 5.25-4.25 9.5-9.5 9.5-1.5 0-2.92-.35-4.18-.97L2 22l1.47-5.82A9.46 9.46 0 0 1 2.5 12C2.5 6.75 6.75 2.5 12 2.5s9.5 4.25 9.5 9.5z"/></svg>Mensagens enviadas</div>
            <div class="val" id="dash-m-sent"><?= number_format($metrics['total_sent'], 0, ',', '.') ?></div>
            <div class="sub" id="dash-m-errors"><?= $metrics['total_errors'] ?> erros no total</div>
            <div class="accent"></div>
          </div>
          <div class="metric ouro">
            <div class="lbl"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Taxa de entrega</div>
            <div class="val" id="dash-m-rate"><?= $metrics['success_rate'] ?>%</div>
            <div class="sub">média das campanhas</div>
            <div class="accent"></div>
          </div>
          <div class="metric">
            <div class="lbl"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Total no CRM</div>
            <div class="val" id="dash-m-crm"><?= number_format($crmTotal, 0, ',', '.') ?></div>
            <div class="sub">contatos cadastrados</div>
            <div class="accent"></div>
          </div>
          <div class="metric success">
            <div class="lbl"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Ganhos</div>
            <div class="val" id="dash-m-ganhos"><?= number_format($metrics['crm_ganho'], 0, ',', '.') ?></div>
            <div class="sub" id="dash-m-negoc"><?= $metrics['crm_em_contato'] ?> em negociação</div>
            <div class="accent"></div>
          </div>
          <div class="metric roxo">
            <div class="lbl"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>Taxa de conversão</div>
            <div class="val" id="dash-m-conv"><?= $conversao ?>%</div>
            <div class="sub">ganhos / total CRM</div>
            <div class="accent"></div>
          </div>
        </div>

        <!-- Limite diário -->
        <div class="card" id="daily-limit-card" style="border-left:4px solid #c8a96e">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:10px">
            <div>
              <div style="font-weight:700;font-size:.95rem;color:var(--verde-800)">📊 Limite diário</div>
              <div style="font-size:.78rem;color:var(--cinza);margin-top:2px">Envios feitos hoje vs. limite recomendado</div>
            </div>
            <div style="text-align:right">
              <div style="font-size:1.5rem;font-weight:800;color:var(--verde-800)" id="daily-sent-num">0</div>
              <div style="font-size:.72rem;color:var(--cinza)">de <span id="daily-limit-num">1.000</span> recomendados</div>
            </div>
          </div>
          <div style="background:#f0eee5;border-radius:8px;height:10px;overflow:hidden;position:relative">
            <div id="daily-bar" style="height:100%;background:linear-gradient(90deg,#5a8a5a,#1a5c2a);width:0%;transition:width .5s ease;border-radius:8px"></div>
          </div>
          <div id="daily-status-msg" style="margin-top:8px;font-size:.78rem;color:var(--cinza)"></div>
        </div>

        <!-- Row 2 — Pipeline + Campanhas recentes -->
        <div class="dash-grid-2">

          <!-- Pipeline CRM -->
          <div class="card">
            <div class="card-head">
              <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Pipeline CRM</h3>
              <button class="btn btn-ghost" onclick="goPage('crm')" style="font-size:.8rem">Ver CRM →</button>
            </div>
            <?php if ($crmTotal === 0): ?>
              <div id="dash-pipeline-wrap">
              <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                <h4>CRM vazio</h4>
                <p style="font-size:.82rem;margin-top:4px">Faça um disparo para popular automaticamente.</p>
              </div>
              </div>
            <?php else: ?>
              <div id="dash-pipeline-wrap">
              <div class="pipeline-row">
                <div class="pipeline-seg pend"  id="dash-pip-pend"  style="width:<?= $pPend ?>%"></div>
                <div class="pipeline-seg cont"  id="dash-pip-cont"  style="width:<?= $pCont ?>%"></div>
                <div class="pipeline-seg ganho" id="dash-pip-ganh"  style="width:<?= $pGanh ?>%"></div>
                <div class="pipeline-seg perd"  id="dash-pip-perd"  style="width:<?= $pPerd ?>%"></div>
              </div>
              <div class="pipeline-legend">
                <div class="pip-item" onclick="goPage('crm')">
                  <div class="pip-dot" style="background:var(--cinza-2)"></div>
                  <div class="pip-lbl">Pendentes</div>
                  <div class="pip-val" id="dash-pip-pend-n"><?= $metrics['crm_pendente'] ?></div>
                  <div class="pip-pct" id="dash-pip-pend-p"><?= $pPend ?>%</div>
                </div>
                <div class="pip-item" onclick="goPage('crm')">
                  <div class="pip-dot" style="background:var(--azul)"></div>
                  <div class="pip-lbl">Em contato</div>
                  <div class="pip-val" id="dash-pip-cont-n"><?= $metrics['crm_em_contato'] ?></div>
                  <div class="pip-pct" id="dash-pip-cont-p"><?= $pCont ?>%</div>
                </div>
                <div class="pip-item" onclick="goPage('crm')">
                  <div class="pip-dot" style="background:var(--success)"></div>
                  <div class="pip-lbl">Ganhos</div>
                  <div class="pip-val" id="dash-pip-ganh-n" style="color:var(--success)"><?= $metrics['crm_ganho'] ?></div>
                  <div class="pip-pct" id="dash-pip-ganh-p"><?= $pGanh ?>%</div>
                </div>
                <div class="pip-item" onclick="goPage('crm')">
                  <div class="pip-dot" style="background:var(--error)"></div>
                  <div class="pip-lbl">Perdidos</div>
                  <div class="pip-val" id="dash-pip-perd-n" style="color:var(--error)"><?= $metrics['crm_perdido'] ?></div>
                  <div class="pip-pct" id="dash-pip-perd-p"><?= $pPerd ?>%</div>
                </div>
              </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- Últimas campanhas -->
          <div class="card">
            <div class="card-head">
              <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>Últimas campanhas</h3>
              <button class="btn btn-ghost" onclick="goPage('historico')" style="font-size:.8rem">Ver tudo →</button>
            </div>
            <div id="dash-campanhas">
            <?php if (empty($metrics['jobs_recent'])): ?>
              <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>
                <h4>Nenhum disparo ainda</h4>
                <p style="font-size:.82rem;margin-top:4px"><a onclick="goPage('disparo')" style="cursor:pointer;color:var(--verde);font-weight:600">Criar primeiro disparo →</a></p>
              </div>
            <?php else: ?>
              <table class="recent-table">
                <thead><tr><th>Campanha</th><th>Env.</th><th>%</th><th>Quando</th></tr></thead>
                <tbody>
                  <?php foreach ($metrics['jobs_recent'] as $j):
                    $pct = $j['total'] > 0 ? round($j['sent']/$j['total']*100) : 0; ?>
                    <tr>
                      <td class="name" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($j['job_name']) ?></td>
                      <td class="date-mono"><?= (int)$j['sent'] ?>/<?= (int)$j['total'] ?></td>
                      <td><span class="badge <?= $pct>=80?'ok':'zero' ?>"><?= $pct ?>%</span></td>
                      <td class="date-mono"><?= htmlspecialchars(fmt_relative($j['started_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Row 3 — Top listas + Contatos recentes -->
        <?php if (!empty($metrics['top_listas']) || !empty($metrics['crm_recent'])): ?>
        <div class="dash-grid-2">

          <!-- Top listas -->
          <?php if (!empty($metrics['top_listas'])): ?>
          <div class="card">
            <div class="card-head">
              <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>Top listas</h3>
            </div>
            <?php foreach ($metrics['top_listas'] as $l):
              $pct = $maxLista > 0 ? round($l['total'] / $maxLista * 100) : 0;
              $conv = $l['total'] > 0 ? round($l['ganhos'] / $l['total'] * 100) : 0;
            ?>
              <div class="lista-row">
                <div class="lista-name" title="<?= htmlspecialchars($l['source']) ?>"><?= htmlspecialchars($l['source']) ?></div>
                <div class="lista-bar-wrap"><div class="lista-bar" style="width:<?= $pct ?>%"></div></div>
                <div class="lista-num"><?= (int)$l['total'] ?></div>
                <?php if ($conv > 0): ?>
                  <span class="badge ok" style="font-size:.66rem;padding:2px 7px"><?= $conv ?>%</span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Contatos recentes -->
          <?php if (!empty($metrics['crm_recent'])): ?>
          <div class="card">
            <div class="card-head">
              <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>Contatos recentes</h3>
              <button class="btn btn-ghost" onclick="goPage('crm')" style="font-size:.8rem">Ver CRM →</button>
            </div>
            <table class="recent-table">
              <thead><tr><th>Contato</th><th>Status</th><th>Lista</th></tr></thead>
              <tbody>
                <?php foreach ($metrics['crm_recent'] as $c):
                  $statusLabels = ['pendente'=>'Pendente','em_contato'=>'Em Contato','ganho'=>'Ganho','perdido'=>'Perdido'];
                  $statusClasses = ['pendente'=>'zero','em_contato'=>'','ganho'=>'ok','perdido'=>'err'];
                  $sl = $statusLabels[$c['status']] ?? $c['status'];
                  $sc = $statusClasses[$c['status']] ?? '';
                ?>
                  <tr>
                    <td>
                      <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($c['name'] ?: $c['phone']) ?></div>
                      <?php if ($c['name']): ?><div class="date-mono"><?= htmlspecialchars($c['phone']) ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $sc ?>"><?= $sl ?></span></td>
                    <td class="date-mono" style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($c['source']??'') ?>"><?= htmlspecialchars(mb_strimwidth($c['source']??'—',0,18,'…')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

        </div>
        <?php endif; ?>

      </section>

      <!-- ═══════════════════════════════════════════════════
           PÁGINA — CONEXÃO
      ═══════════════════════════════════════════════════ -->
      <section id="page-conexao" class="page">
        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
              Conectar WhatsApp
            </h3>
          </div>

          <div class="qr-grid">
            <div class="qr-box" id="qr-container">
              <div class="qr-placeholder" id="qr-placeholder">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h.01M14 17h3M17 14v3M20 17v3M20 20h-3"/></svg>
                Carregando QR Code…
              </div>
              <div class="qr-spinner" id="qr-spinner"></div>
              <img id="qr-img" src="" alt="QR Code" style="display:none;max-width:100%;max-height:100%;border-radius:6px">
            </div>

            <div class="qr-info">
              <h4>Escaneie com seu WhatsApp</h4>
              <p>Abra o WhatsApp no celular e aponte a câmera para o QR Code.</p>
              <ol>
                <li>Abra o <strong>WhatsApp</strong> → Menu (⋮) → <strong>Aparelhos conectados</strong></li>
                <li>Toque em <strong>Conectar um aparelho</strong></li>
                <li>Aponte a câmera para o <strong>QR Code</strong> ao lado</li>
              </ol>
              <div class="qr-actions">
                <button class="btn btn-primary" onclick="loadQr()">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                  Novo QR Code
                </button>
                <button class="btn btn-outline" onclick="restartConnection()" title="Reinicia a sessão sem desconectar">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                  Reiniciar
                </button>
                <button class="btn btn-danger" onclick="disconnectWA()" title="Desconecta o WhatsApp para reconectar">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                  Desconectar
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
              Informações
            </h3>
          </div>
          <div class="conn-info" id="conn-info">
            <em style="color:var(--cinza)">Aguardando leitura do status…</em>
          </div>
        </div>
      </section>

      <!-- ═══════════════════════════════════════════════════
           PÁGINA — DISPARO
      ═══════════════════════════════════════════════════ -->
      <section id="page-disparo" class="page">
        <div id="alert-not-connected" class="alert warn" style="display:none">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <div>
            <strong>WhatsApp desconectado.</strong> Vá até <a onclick="goPage('conexao')" style="cursor:pointer;font-weight:600">Conexão</a> e escaneie o QR Code antes de disparar.
          </div>
        </div>

        <!-- Aquecimento e boas práticas -->
        <details style="background:#fffdf5;border:1px solid #e8d9b3;border-radius:10px;padding:0;overflow:hidden">
          <summary style="padding:10px 16px;cursor:pointer;font-size:.82rem;font-weight:600;color:#8a5800;list-style:none;display:flex;align-items:center;gap:8px;user-select:none">
            <span>🔥</span> Boas práticas de disparo
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="margin-left:auto;opacity:.5"><polyline points="6 9 12 15 18 9"/></svg>
          </summary>
          <div style="padding:0 16px 14px;border-top:1px solid #f0e0c0">
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px">
              <span style="background:rgba(46,125,50,.1);color:#2e7d32;padding:3px 10px;border-radius:10px;font-size:.73rem;font-weight:600">✅ Use {{nome}} e variações</span>
              <span style="background:rgba(46,125,50,.1);color:#2e7d32;padding:3px 10px;border-radius:10px;font-size:.73rem;font-weight:600">✅ Intervalo mínimo 6s</span>
              <span style="background:rgba(46,125,50,.1);color:#2e7d32;padding:3px 10px;border-radius:10px;font-size:.73rem;font-weight:600">✅ Dispare para quem te conhece</span>
              <span style="background:rgba(46,125,50,.1);color:#2e7d32;padding:3px 10px;border-radius:10px;font-size:.73rem;font-weight:600">✅ Responda as mensagens recebidas</span>
              <span style="background:rgba(198,40,40,.08);color:#c62828;padding:3px 10px;border-radius:10px;font-size:.73rem;font-weight:600">❌ Evite listas frias</span>
              <span style="background:rgba(198,40,40,.08);color:#c62828;padding:3px 10px;border-radius:10px;font-size:.73rem;font-weight:600">❌ Sem números inválidos</span>
            </div>
          </div>
        </details>


        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
              Mensagem
            </h3>
          </div>

          <div class="form-group">
            <label>Nome do disparo</label>
            <input type="text" id="job-name" placeholder="Ex: Promoção Dia das Mães 2026">
          </div>

          <!-- Gerador de IA -->
          <div style="background:linear-gradient(135deg,#f0f7f0,#e8f4e8);border:1px solid #c5dfca;border-radius:12px;padding:16px;margin-bottom:18px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
              <div style="width:28px;height:28px;background:var(--verde);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" width="15" height="15"><path d="M12 2a10 10 0 1 0 10 10"/><path d="M12 6v6l4 2"/><circle cx="18" cy="6" r="3" fill="#fff" stroke="none"/><path d="M17 6h2M18 5v2" stroke="var(--verde)" stroke-width="1.5"/></svg>
              </div>
              <div>
                <div style="font-weight:700;font-size:.9rem;color:#1a5c2a">Gerar com IA</div>
                <div style="font-size:.73rem;color:#5a8a5a">Descreva o objetivo e a AI cria as variações para você</div>
              </div>
            </div>

            <div class="form-group" style="margin-bottom:10px">
              <label style="font-size:.77rem">O que você quer comunicar?</label>
              <textarea id="ai-objective" placeholder="Ex: Promoção de arranjos para Dia das Mães com 20% de desconto, válido até domingo. Público: mulheres adultas, tom afetuoso." style="min-height:72px;font-size:.85rem;resize:vertical"></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px">
              <div>
                <label style="font-size:.73rem;font-weight:600;color:var(--cinza);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:5px">Variações</label>
                <select id="ai-variations-count" style="width:100%;padding:7px 10px;border-radius:8px;border:1px solid var(--borda-2);font-size:.85rem;background:#fff">
                  <option value="2">2 variações</option>
                  <option value="3" selected>3 variações</option>
                  <option value="4">4 variações</option>
                  <option value="5">5 variações</option>
                </select>
              </div>
              <div>
                <label style="font-size:.73rem;font-weight:600;color:var(--cinza);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:5px">Tom</label>
                <select id="ai-tone" style="width:100%;padding:7px 10px;border-radius:8px;border:1px solid var(--borda-2);font-size:.85rem;background:#fff">
                  <option value="descontraido">Descontraído 😊</option>
                  <option value="neutro" selected>Neutro</option>
                  <option value="formal">Formal</option>
                  <option value="urgente">Urgente 🔥</option>
                  <option value="emocional">Emocional 💚</option>
                </select>
              </div>
              <div>
                <label style="font-size:.73rem;font-weight:600;color:var(--cinza);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:5px">Tamanho</label>
                <select id="ai-length" style="width:100%;padding:7px 10px;border-radius:8px;border:1px solid var(--borda-2);font-size:.85rem;background:#fff">
                  <option value="curto">Curto (até 3 linhas)</option>
                  <option value="medio" selected>Médio (4–6 linhas)</option>
                  <option value="longo">Longo (7–10 linhas)</option>
                </select>
              </div>
            </div>

            <button class="btn btn-primary" id="btn-ai-generate" onclick="generateWithAI()" style="width:100%;justify-content:center;gap:10px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
              Gerar variações com IA
            </button>
            <div id="ai-status" style="display:none;text-align:center;font-size:.8rem;color:var(--cinza);margin-top:10px;padding:8px;background:#fff;border-radius:8px"></div>
          </div>

          <!-- Barra de templates -->
          <div class="tpl-bar">
            <label>Template:</label>
            <select id="tpl-select" onchange="tplLoad()">
              <option value="">— Selecionar —</option>
            </select>
            <button class="btn btn-outline" style="padding:6px 12px;font-size:.8rem" onclick="tplSave()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Salvar
            </button>
            <button class="btn btn-ghost" style="padding:6px 10px;font-size:.8rem;color:var(--error)" onclick="tplDelete()" title="Excluir template selecionado">✕</button>
          </div>

          <!-- Variações -->
          <div class="form-group">
            <label style="display:flex;align-items:center;justify-content:space-between">
              <span>Texto da mensagem</span>
              <button class="btn btn-ghost" style="padding:4px 10px;font-size:.78rem;color:var(--verde)" onclick="addVariation()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                + Variação
              </button>
            </label>
            <div class="variations-wrap" id="variations-wrap">
              <div class="variation-item" data-idx="0">
                <div class="variation-num">1</div>
                <textarea class="variation-text" placeholder="Digite a mensagem ou use o gerador de IA acima...&#10;&#10;Use variáveis: {{nome}}, {{empresa}}, {{cidade}}"></textarea>
              </div>
            </div>
            <div class="var-chips" style="margin-top:10px">
              <span style="font-size:.72rem;color:var(--cinza);font-weight:600;align-self:center">Inserir variável:</span>
              <span class="var-chip" onclick="insertVar('{{nome}}')">{{nome}}</span>
              <span class="var-chip" onclick="insertVar('{{telefone}}')">{{telefone}}</span>
              <span class="var-chip" onclick="insertVar('{{empresa}}')">{{empresa}}</span>
              <span class="var-chip" onclick="insertVar('{{cidade}}')">{{cidade}}</span>
            </div>
            <div class="hint" style="margin-top:6px">
              Formatação: <code>*negrito*</code> <code>_itálico_</code> <code>~tachado~</code> · 
              Com múltiplas variações, o sistema <strong>alterna automaticamente</strong> entre elas.
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              Contatos
            </h3>
            <span class="contacts-count">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
              <span id="contacts-num">0</span> contatos
            </span>
          </div>

          <div class="form-group">
            <label>Usar lista(s) do CRM</label>
            <div style="border:1px solid var(--borda-2);border-radius:10px;overflow:hidden">
              <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--creme);border-bottom:1px solid var(--borda-2)">
                <span style="font-size:.78rem;color:var(--cinza);flex:1" id="crm-lists-summary">Nenhuma lista selecionada</span>
                <button class="btn btn-ghost" style="padding:4px 8px;font-size:.72rem" onclick="refreshCrmLists()" title="Atualizar">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                </button>
                <button class="btn btn-ghost" style="padding:4px 8px;font-size:.72rem" onclick="selectAllCrmLists(true)">Todas</button>
                <button class="btn btn-ghost" style="padding:4px 8px;font-size:.72rem" onclick="selectAllCrmLists(false)">Nenhuma</button>
              </div>
              <div id="crm-lists-checkboxes" style="max-height:180px;overflow-y:auto;padding:6px 0">
                <div style="padding:12px;text-align:center;color:var(--cinza);font-size:.82rem">Carregando listas...</div>
              </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button class="btn btn-outline" style="flex:1;font-size:.82rem" onclick="loadFromCrmLists()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Carregar listas selecionadas
              </button>
            </div>
            <div class="hint">Selecione uma ou mais listas — os contatos serão mesclados automaticamente (sem duplicatas)</div>
          </div>

          <div class="form-group">
            <label>Importar CSV / Excel</label>
            <input type="file" id="csv-file" accept=".csv,.txt,.xlsx,.xls" onchange="loadCsv(this)">
            <div class="hint">Colunas detectadas: <code>telefone</code>, <code>nome</code>, <code>empresa</code>, <code>cidade</code> — usadas nas variáveis <code>{{nome}}</code> etc.</div>
          </div>

          <div class="form-group">
            <label style="display:flex;align-items:center;justify-content:space-between">
              <span>Adicionar manualmente</span>
              <button type="button" class="btn btn-ghost" style="font-size:.75rem;color:var(--error);padding:3px 8px" onclick="clearContacts()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                Limpar lista
              </button>
            </label>
            <textarea id="manual-phones" placeholder="Um número por linha:&#10;(48) 99999-0001&#10;(48) 99999-0002&#10;48999990003" style="min-height:90px"></textarea>
          </div>

          <button class="btn btn-outline" onclick="previewContacts()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            Visualizar lista
          </button>
          <div id="contacts-preview"></div>
        </div>

        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              Imagem (opcional)
            </h3>
          </div>
          <div class="form-group">
            <label>Selecionar imagem</label>
            <input type="file" id="img-file" accept="image/*" onchange="uploadImg(this)">
            <div class="hint">JPG, PNG, GIF ou WEBP — máximo 5MB · A AI usa a imagem para gerar textos mais relevantes</div>
          </div>
          <div class="img-preview-wrap" id="img-preview-wrap">
            <img id="img-preview" src="" alt="">
            <div class="url-out" id="img-url-out"></div>
          </div>
          <input type="hidden" id="img-url">
        </div>

        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>
              Enviar
            </h3>
          </div>

          <!-- Linha 1: Intervalo + Modo de envio -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
            <div class="ext-form-field">
              <label>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Intervalo entre envios
              </label>
              <div style="display:flex;gap:6px">
                <input type="number" id="delay-secs" value="2" min="1" max="3600" style="width:72px;flex-shrink:0">
                <select id="delay-unit" onchange="updateDelayHint()" style="flex:1;padding:10px 8px;border:1px solid var(--borda);border-radius:9px;font-size:.85rem;background:var(--branco)">
                  <option value="1">segundos</option>
                  <option value="60" selected>minutos</option>
                </select>
              </div>
              <small id="delay-hint" style="color:var(--cinza)">≈ 5–11s por mensagem · delayTyping automático</small>
            </div>
            <div class="ext-form-field">
              <label>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Perfil de segurança
              </label>
              <select id="dispatch-safety" onchange="updateSafetyMode()" style="width:100%;padding:10px 12px;border:1px solid var(--borda);border-radius:9px;font-size:.88rem;background:var(--branco)">
                <option value="normal">🟡 Normal — 30 a 60s</option>
                <option value="safe" selected>🟢 Seguro — 2 a 3 min (recomendado)</option>
                <option value="cautious">🔵 Cuidadoso — 5 a 10 min</option>
                <option value="custom">⚙️ Personalizado</option>
              </select>
              <small id="variation-alert-inline" style="display:none;color:var(--ouro,#c8a96e)">⚠️ Crie ao menos 3 variações de texto</small>
            </div>
          </div>

          <!-- Linha 2: Quando enviar (tabs compactas) -->
          <div style="border:1px solid var(--borda);border-radius:12px;overflow:hidden;margin-bottom:14px">

            <!-- Tab header -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;border-bottom:1px solid var(--borda)">
              <label id="tab-now-label" style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;cursor:pointer;font-size:.85rem;font-weight:700;color:var(--verde);background:var(--branco);border-right:1px solid var(--borda)">
                <input type="radio" name="dispatch-mode" value="now" checked onchange="schedToggle()" style="display:none">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Enviar agora
              </label>
              <label id="tab-sched-label" style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;cursor:pointer;font-size:.85rem;font-weight:600;color:var(--cinza);background:var(--creme);border-right:1px solid var(--borda)">
                <input type="radio" name="dispatch-mode" value="scheduled" onchange="schedToggle()" style="display:none">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Agendar
              </label>
              <label id="tab-batch-label" style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;cursor:pointer;font-size:.85rem;font-weight:600;color:var(--cinza);background:var(--creme)">
                <input type="checkbox" id="batch-mode" onchange="batchModeToggle()" style="display:none">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Em lotes
              </label>
            </div>

            <!-- Conteúdo das tabs -->
            <div id="tab-content" style="padding:14px 16px;background:var(--branco);min-height:52px">

              <!-- Enviar agora: sem opção extra -->
              <div id="tab-content-now">
                <span style="font-size:.82rem;color:var(--cinza)">O disparo inicia imediatamente após confirmar.</span>
              </div>

              <!-- Agendar: datepicker -->
              <div id="tab-content-sched" style="display:none">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                  <label style="font-size:.8rem;font-weight:700;color:var(--cinza);white-space:nowrap">📅 Data e hora:</label>
                  <input type="datetime-local" id="sched-datetime" oninput="schedUpdateHint()" style="flex:1;min-width:200px;padding:9px 12px;border:1px solid var(--borda);border-radius:8px;font-size:.9rem">
                  <div class="sched-hint-text" id="sched-hint" style="font-size:.78rem"></div>
                </div>
              </div>

              <!-- Em lotes: configuração de batch -->
              <div id="tab-content-batch" style="display:none">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:10px">
                  <div class="ext-form-field">
                    <label>Nº de lotes</label>
                    <input type="number" id="batch-count" value="5" min="2" max="10" oninput="updateBatchPreview()">
                  </div>
                  <div class="ext-form-field">
                    <label>Intervalo (horas)</label>
                    <input type="number" id="batch-interval-h" value="2.5" min="1" max="12" step="0.5" oninput="updateBatchPreview()">
                  </div>
                  <div class="ext-form-field">
                    <label>Primeiro lote às</label>
                    <input type="time" id="batch-start-time" value="09:00" oninput="updateBatchPreview()">
                  </div>
                </div>
                <div id="batch-preview" style="font-size:.78rem;color:#1a5c2a;background:#f0f7f0;border-radius:8px;padding:8px 12px;line-height:1.6"></div>
              </div>

            </div>
          </div>

          <!-- Botões de ação -->
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button class="btn btn-primary" id="btn-send" onclick="startDisparo()" style="min-width:160px;justify-content:center">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              <span id="btn-send-label">Iniciar Disparo</span>
            </button>
            <button class="btn btn-outline" id="btn-pause" style="display:none" onclick="pauseDisparo()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
              Pausar
            </button>
            <button class="btn btn-primary" id="btn-resume" style="display:none" onclick="resumeDisparo()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              Retomar
            </button>
            <button class="btn btn-danger" id="btn-stop" style="display:none" onclick="stopDisparo()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
              Parar
            </button>
          </div>

          <div class="progress-wrap" id="send-progress">
            <div class="progress-bar"><div id="progress-fill"></div></div>
            <div id="progress-text">0 / 0 enviados</div>
            <div id="progress-log"></div>
          </div>
        </div>
      </section>

      <!-- ═══════════════════════════════════════════════════
           PÁGINA — HISTÓRICO
      ═══════════════════════════════════════════════════ -->
      <section id="page-historico" class="page">

        <!-- Painel de status da fila -->
        <div id="queue-status-panel" style="display:none;background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1px solid #fde68a;border-radius:12px;padding:16px 20px;margin-bottom:14px">
          <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap">
            <div style="flex:1;min-width:200px">
              <div style="font-weight:700;color:#92400e;font-size:.92rem;margin-bottom:4px" id="queue-status-title">⚠️ Disparo em andamento</div>
              <div style="font-size:.82rem;color:#78350f" id="queue-status-detail">—</div>
              <div style="margin-top:8px;background:#fff;border-radius:6px;height:8px;overflow:hidden;border:1px solid #fde68a">
                <div id="queue-progress-bar" style="background:linear-gradient(90deg,#f59e0b,#d97706);height:100%;width:0%;transition:width .3s"></div>
              </div>
              <div id="queue-progress-text" style="font-size:.74rem;color:#78350f;margin-top:4px"></div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <button class="btn btn-outline" id="queue-resume-btn" style="background:#fff;font-size:.78rem;display:none" onclick="resumeQueueJob()">▶ Retomar</button>
              <button class="btn btn-ghost" style="font-size:.78rem;color:#c62828" onclick="cancelQueueJob()">✕ Cancelar</button>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
              Disparos Realizados
            </h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-outline" onclick="forceWorker()" style="background:#fffbeb;border-color:#f59e0b;color:#92400e" title="Aciona manualmente o worker para retomar disparos travados">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                Forçar execução
              </button>
              <button class="btn btn-outline" onclick="loadHistorico()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Atualizar
              </button>
              <button class="btn btn-danger" style="font-size:.8rem" onclick="showClearHistory()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                Limpar
              </button>
            </div>
          </div>
          <div id="historico-wrap">
            <em style="color:var(--cinza)">Carregando…</em>
          </div>
        </div>
      </section>

      <!-- ═══════════════════════════════════════════════════
           PÁGINA — CRM
      ═══════════════════════════════════════════════════ -->
      <section id="page-crm" class="page">

        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              CRM de Contatos
            </h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-outline" onclick="syncCampaigns()" style="background:#f0f7f0;border-color:#86c190;color:#1a5c2a" title="Popula a coluna 'Última campanha' nos contatos com base nos disparos já realizados">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Sincronizar
              </button>
              <button class="btn btn-outline" onclick="normalizePhones()" style="background:#fffbeb;border-color:#f59e0b;color:#92400e" title="Remove códigos de operadora (15, 21, 41) e padroniza para o formato BR (55XX9XXXXXXXX)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3 8-8"/><path d="M21 12c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9c1.66 0 3.21.45 4.55 1.24"/></svg>
                Limpar telefones
              </button>
              <label class="btn btn-outline" style="cursor:pointer">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Import Excel
                <input type="file" accept=".xlsx,.xls,.csv" style="display:none" onchange="crmImportExcel(this)">
              </label>
              <button class="btn btn-outline" onclick="crmDownloadExcel()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Excel
              </button>
              <button class="btn btn-outline" onclick="openMergeListsModal()" title="Juntar duas ou mais listas em uma única lista (máx. 100 por lista)" style="background:#fffbf0;border-color:#c8a96e;color:#8a6f3a">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/><path d="M9 3l-3 3 3 3" transform="rotate(90 9 6)" /></svg>
                Juntar Listas
              </button>
              <button class="btn btn-outline" id="crm-export-btn" onclick="crmExport()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                .txt
              </button>
              <button class="btn btn-primary" onclick="crmLoad(1)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Atualizar
              </button>
              <div class="view-toggle">
                <button id="btn-view-list" class="active" onclick="setCrmView('list')" title="Visualização em lista">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                  Lista
                </button>
                <button id="btn-view-kanban" onclick="setCrmView('kanban')" title="Visualização Kanban">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="5" height="15" rx="1"/></svg>
                  Kanban
                </button>
              </div>
            </div>
          </div>

          <!-- Tabs de status -->
          <div style="margin-bottom:14px">
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap">
              <label style="font-size:.72rem;font-weight:600;color:var(--cinza);text-transform:uppercase;letter-spacing:.08em;white-space:nowrap">Lista:</label>
              <select id="crm-source-select" style="flex:1;min-width:160px;padding:7px 10px;border-radius:7px;border:1px solid var(--borda-2);font-size:.85rem;background:var(--branco)" onchange="crmSetSource(this.value)">
                <option value="">Todos os contatos</option>
              </select>
              <label style="font-size:.72rem;font-weight:600;color:var(--cinza);text-transform:uppercase;letter-spacing:.08em;white-space:nowrap">Campanha:</label>
              <select id="crm-campaign-select" style="flex:1;min-width:160px;padding:7px 10px;border-radius:7px;border:1px solid var(--borda-2);font-size:.85rem;background:var(--branco)" onchange="crmSetCampaign(this.value)">
                <option value="">Todas as campanhas</option>
              </select>
              <button class="btn btn-ghost" style="padding:6px 10px;font-size:.78rem" onclick="loadCrmSources();loadCrmCampaigns()" title="Atualizar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              </button>
              <button class="btn btn-outline" style="padding:6px 10px;font-size:.78rem" onclick="exportCrmExcel()" title="Exportar para Excel">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Exportar
              </button>
              <button id="btn-rename-list" class="btn btn-outline" style="padding:6px 10px;font-size:.78rem;display:none" onclick="renameCrmList()" title="Renomear lista selecionada">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Renomear
              </button>
              <button id="btn-delete-list" class="btn btn-danger" style="padding:6px 10px;font-size:.78rem;display:none" onclick="deleteCrmListBtn()" title="Excluir lista selecionada">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                Excluir lista
              </button>
            </div>
          </div>

          <div class="crm-tabs">
            <button class="crm-tab active" data-status="all" onclick="crmSetStatus('all',this)">
              Todos <span class="cnt" id="cnt-all">0</span>
            </button>
            <button class="crm-tab" data-status="pendente" onclick="crmSetStatus('pendente',this)">
              🕐 Pendentes <span class="cnt" id="cnt-pendente">0</span>
            </button>
            <button class="crm-tab" data-status="em_contato" onclick="crmSetStatus('em_contato',this)">
              💬 Em Contato <span class="cnt" id="cnt-em_contato">0</span>
            </button>
            <button class="crm-tab" data-status="ganho" onclick="crmSetStatus('ganho',this)">
              ✅ Ganhos <span class="cnt" id="cnt-ganho">0</span>
            </button>
            <button class="crm-tab" data-status="perdido" onclick="crmSetStatus('perdido',this)">
              ❌ Perdidos <span class="cnt" id="cnt-perdido">0</span>
            </button>
            <button class="crm-tab" data-status="dispatched" onclick="crmSetStatus('dispatched',this)" title="Contatos que receberam pelo menos um disparo">
              📢 Enviados
            </button>
          </div>

          <!-- Busca -->
          <div class="crm-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="crm-search-input" placeholder="Buscar por telefone ou nome…" oninput="crmSearchDebounce()">
          </div>

          <!-- Tabela -->
          <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
            <div id="crm-body">
              <em style="color:var(--cinza)">Carregando…</em>
            </div>
          </div>

          <!-- Paginação -->
          <div class="crm-pagination" id="crm-pagination" style="display:none">
            <span id="crm-total-info"></span>
            <div class="pages">
              <button id="crm-prev" onclick="crmLoad(crmPage-1)">← Anterior</button>
              <button id="crm-next" onclick="crmLoad(crmPage+1)">Próxima →</button>
            </div>
          </div>
        </div>
        <!-- Modal: Juntar Listas -->
        <div id="merge-modal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px">
          <div style="background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
            <div style="padding:20px 24px 14px;border-bottom:1px solid var(--borda);display:flex;align-items:center;justify-content:space-between;gap:12px">
              <div>
                <h3 style="margin:0;font-size:1.1rem;color:var(--texto);display:flex;align-items:center;gap:8px">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M8 17V5l-3 3M8 17l3-3M8 17v.01"/><path d="M16 7v12l3-3M16 7l-3 3M16 7v-.01"/></svg>
                  Juntar Listas em Uma
                </h3>
                <p style="margin:4px 0 0;font-size:.78rem;color:var(--cinza)">Selecione 2 ou mais listas para unificar. Limite de 100 por lista — se exceder, será dividido automaticamente.</p>
              </div>
              <button onclick="closeMergeListsModal()" style="background:none;border:none;font-size:1.5rem;color:var(--cinza);cursor:pointer;padding:0 4px">×</button>
            </div>
            <div style="padding:18px 24px">
              <label style="font-size:.72rem;font-weight:700;color:var(--cinza);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:8px">Listas a juntar</label>
              <div id="merge-lists-options" style="max-height:240px;overflow-y:auto;border:1px solid var(--borda);border-radius:9px;padding:8px 12px;background:var(--creme)">
                <em style="color:var(--cinza);font-size:.85rem">Carregando listas…</em>
              </div>
              <div id="merge-summary" style="margin-top:10px;font-size:.78rem;color:var(--cinza);min-height:18px"></div>

              <label style="font-size:.72rem;font-weight:700;color:var(--cinza);text-transform:uppercase;letter-spacing:.08em;display:block;margin:16px 0 6px">Nome da lista unificada</label>
              <input type="text" id="merge-target-name" placeholder="Ex: Floricultura - Florianópolis (consolidada)"
                     style="width:100%;padding:11px 13px;border:1px solid var(--borda);border-radius:9px;font-size:.9rem;background:var(--branco)">
              <div id="merge-preview" style="margin-top:10px;font-size:.8rem;color:var(--verde);min-height:18px"></div>
            </div>
            <div style="padding:14px 24px;border-top:1px solid var(--borda);display:flex;gap:10px;justify-content:flex-end;background:var(--creme);border-radius:0 0 16px 16px">
              <button class="btn btn-ghost" onclick="closeMergeListsModal()">Cancelar</button>
              <button id="merge-btn-confirm" class="btn btn-primary" onclick="confirmMergeLists()" disabled style="opacity:.5">
                Juntar
              </button>
            </div>
          </div>
        </div>

      </section>

      <!-- ═══════════════════════════════════════════════════
           PÁGINA — ORGANIZADOR DE LISTA
      ═══════════════════════════════════════════════════ -->
      <section id="page-organizador" class="page">

        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
              Organizador de Listas
            </h3>
            <button class="btn btn-outline" onclick="goPage('extrator')" style="font-size:.78rem">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Extrator de Leads (Maps)
            </button>
          </div>

          <!-- Tabs -->
          <div class="org-tabs">
            <button class="org-tab active" onclick="orgSwitchTab('manual',this)">📋 Colar Texto</button>
            <button class="org-tab" onclick="orgSwitchTab('txt',this)">📄 Arquivo .txt</button>
            <button class="org-tab" onclick="orgSwitchTab('excel',this)">📊 Excel / CSV</button>
            <button class="org-tab" onclick="orgSwitchTab('vcard',this)">📱 vCard (.vcf)</button>
          </div>

          <!-- Tab: Manual -->
          <div id="org-tab-manual">
            <div class="form-group">
              <label>Cole a lista aqui</label>
              <textarea id="org-input" placeholder="Cole qualquer lista: nomes, telefones, planilha copiada...&#10;&#10;O organizador detecta automaticamente telefones e nomes.&#10;Para leads do Google Maps use o módulo Extrator." style="min-height:160px"></textarea>
            </div>
          </div>

          <!-- Tab: TXT -->
          <div id="org-tab-txt" style="display:none">
            <div class="form-group">
              <label>Selecionar arquivo .txt</label>
              <input type="file" id="org-txt-file" accept=".txt" onchange="orgImportTxt(this)">
              <div class="hint">Arquivo de texto simples — uma linha por contato. Detecta automaticamente telefones e nomes.</div>
            </div>
          </div>

          <!-- Tab: Excel -->
          <div id="org-tab-excel" style="display:none">
            <div class="form-group">
              <label>Selecionar Excel ou CSV</label>
              <input type="file" id="org-excel-file" accept=".xlsx,.xls,.csv" onchange="orgImportExcel(this)">
              <div class="hint">O sistema detecta automaticamente as colunas de nome e telefone</div>
            </div>
          </div>

          <!-- Tab: vCard -->
          <div id="org-tab-vcard" style="display:none">
            <div class="alert warn" style="margin-bottom:16px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;flex-shrink:0"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
              <div>
                <strong>Como exportar contatos do celular:</strong><br>
                <b>iOS:</b> Contatos → seleciona → Compartilhar → enviar .vcf para o computador<br>
                <b>Android:</b> Contatos → Menu → Importar/Exportar → Exportar para arquivo .vcf
              </div>
            </div>
            <div class="form-group">
              <label>Selecionar arquivo .vcf</label>
              <input type="file" id="org-vcf-file" accept=".vcf,.vcard" multiple onchange="orgImportVcf(this)">
              <div class="hint">Aceita múltiplos arquivos vCard — extrai nome e telefone automaticamente</div>
            </div>
          </div>

          <!-- Configurações colapsáveis -->
          <div style="margin-top:14px;border-top:1px solid var(--borda);padding-top:12px">
            <button class="org-settings-toggle" onclick="orgToggleSettings(this)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M14 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0"/></svg>
              ⚙️ Configurações de normalização
              <svg id="org-settings-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="12" height="12" style="transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="org-settings-panel hidden" id="org-settings-panel">
              <div class="org-setting-item">
                <label>DDD padrão (quando ausente)</label>
                <input type="text" id="org-cfg-ddd" placeholder="Ex: 48" maxlength="2" style="width:80px">
              </div>
              <div class="org-setting-item">
                <label>Filtros automáticos</label>
                <div class="toggle-row">
                  <input type="checkbox" id="org-cfg-remove-notel" checked>
                  <span>Remover contatos sem telefone</span>
                </div>
                <div class="toggle-row" style="margin-top:5px">
                  <input type="checkbox" id="org-cfg-dedup" checked>
                  <span>Remover duplicatas por telefone</span>
                </div>
              </div>
              <div class="org-setting-item">
                <label>Coluna telefone (Excel)</label>
                <input type="text" id="org-cfg-phone-col" placeholder="telefone, celular, phone..." style="width:100%">
                <div class="hint" style="margin-top:4px">Deixe vazio para detecção automática</div>
              </div>
            </div>
          </div>

          <!-- Botões de ação -->
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
            <button class="btn btn-primary" onclick="orgProcess()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
              Processar
            </button>
            <button class="btn btn-ghost" onclick="orgClear()" style="color:var(--error)" id="org-btn-clear">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
              Limpar
            </button>
          </div>
        </div>

        <!-- Resultados -->
        <div class="card" id="org-results-card" style="display:none">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              Resultado
            </h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-outline" id="org-btn-excel" onclick="orgDownloadExcel()" style="display:none">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Excel
              </button>
              <button class="btn btn-outline" id="org-btn-copy" onclick="orgCopyList()" style="display:none">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Copiar
              </button>
            </div>
          </div>

          <div class="org-stats">
            <span class="org-stat" id="org-stat-total">0 registros</span>
            <span class="org-stat" id="org-stat-phones">0 com telefone</span>
            <span class="org-stat warn" id="org-stat-notel" style="display:none">0 sem telefone</span>
            <span class="org-stat" id="org-stat-dupes" style="display:none">0 duplicatas removidas</span>
          </div>

          <div class="org-results-grid" id="org-results-list"></div>

          <!-- Barra de envio -->
          <div class="org-send-bar" id="org-send-bar">
            <p>✅ Lista pronta — salve no CRM para depois disparar por lista</p>
            <div class="actions">
              <button class="btn btn-azul" onclick="orgSendToCRM()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Salvar no CRM
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- ══════════════════════════════════════════════════
           PÁGINA — EXTRATOR DE LEADS
      ══════════════════════════════════════════════════ -->
      <section id="page-extrator" class="page">

        <!-- Cartão único: tabs + form + resultados -->
        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              Extrator de Leads — Google Maps
            </h3>
          </div>

          <!-- Tabs Auto/Manual (mutuamente exclusivos) -->
          <div class="ext-mode-tabs" role="tablist">
            <button class="ext-mode-tab active" id="ext-tab-auto" onclick="extSetMode('auto')" role="tab">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Automático
            </button>
            <button class="ext-mode-tab" id="ext-tab-manual" onclick="extSetMode('manual')" role="tab">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              Manual (colar)
            </button>
          </div>

          <!-- ═══ MODO AUTOMÁTICO (wizard 3 passos: cidade → bairro → categoria) ═══ -->
          <div id="ext-mode-auto">
            <!-- Indicador de progresso -->
            <div class="ext-steps-indicator">
              <div class="ext-step-dot active" id="ext-dot-1"><span>1</span><label>Cidade</label></div>
              <div class="ext-step-line"></div>
              <div class="ext-step-dot" id="ext-dot-2"><span>2</span><label>Bairro <em>(opcional)</em></label></div>
              <div class="ext-step-line"></div>
              <div class="ext-step-dot" id="ext-dot-3"><span>3</span><label>Categoria</label></div>
            </div>

            <!-- Passo 1: Cidade -->
            <div class="ext-step-card" id="ext-step-1" data-step="1">
              <div class="ext-step-head">
                <div class="ext-step-num">1</div>
                <div>
                  <h4>Onde buscar?</h4>
                  <p>Comece pela cidade — vamos calibrar o mapa antes de buscar.</p>
                </div>
              </div>
              <div class="ext-step-body">
                <input type="text" id="ext-cidade" placeholder="Ex: Florianópolis SC"
                  oninput="extUpdateHint()"
                  onkeydown="if(event.key==='Enter'){extWizardNext(1);return false}">
                <button class="btn btn-primary ext-step-next" onclick="extWizardNext(1)">
                  Próximo <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </button>
              </div>
            </div>

            <!-- Passo 2: Bairro (opcional) -->
            <div class="ext-step-card disabled" id="ext-step-2" data-step="2">
              <div class="ext-step-head">
                <div class="ext-step-num">2</div>
                <div>
                  <h4>Refinar por bairro? <em class="opt">opcional</em></h4>
                  <p>Liste bairros separados por vírgula para buscas mais profundas. Cada bairro = até 60 leads extras.</p>
                </div>
              </div>
              <div class="ext-step-body">
                <input type="text" id="ext-bairro" placeholder="Centro, Trindade, Itacorubi…"
                  oninput="extUpdateHint()"
                  onkeydown="if(event.key==='Enter'){extWizardNext(2);return false}">
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <button class="btn btn-ghost ext-step-skip" onclick="extWizardSkipBairro()" title="Pular: buscar em toda a cidade">
                    Pular →
                  </button>
                  <button class="btn btn-primary ext-step-next" onclick="extWizardNext(2)">
                    Próximo <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                  </button>
                </div>
              </div>
            </div>

            <!-- Passo 3: Categoria + Buscar -->
            <div class="ext-step-card disabled" id="ext-step-3" data-step="3">
              <div class="ext-step-head">
                <div class="ext-step-num">3</div>
                <div>
                  <h4>O que buscar?</h4>
                  <p>Agora com o mapa calibrado, escolha a categoria.</p>
                </div>
              </div>
              <div class="ext-step-body">
                <input type="text" id="ext-keyword" placeholder="floricultura, advogado, salão de beleza, restaurante…"
                  oninput="extUpdateHint()"
                  onkeydown="if(event.key==='Enter')extSearch()">
                <button id="ext-btn-search" class="btn btn-primary btn-buscar" onclick="extSearch()">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  Buscar Leads
                </button>
              </div>
            </div>

            <!-- Botão voltar (resetar wizard) -->
            <div style="text-align:center;margin-top:6px">
              <button class="btn btn-ghost" onclick="extWizardReset()" id="ext-wizard-reset" style="display:none;font-size:.78rem">
                ↺ Recomeçar (mudar cidade/bairro)
              </button>
            </div>

            <!-- Hint dinâmico + progress -->
            <div class="ext-search-hint" id="ext-search-hint" style="margin-top:12px">
              <span style="color:var(--ouro,#c8a96e)">💡</span>
              Comece preenchendo a <strong>cidade</strong> acima.
            </div>

            <div class="ext-progress-card" id="ext-progress-card">
              <div class="ext-progress-text">
                <span id="ext-progress-label">Buscando…</span>
                <span class="count" id="ext-progress-count">0 leads</span>
              </div>
              <div class="ext-progress-bar"><div id="ext-progress-fill"></div></div>
            </div>
          </div>

          <!-- ═══ MODO MANUAL ═══ -->
          <div id="ext-mode-manual" style="display:none">
            <div class="ext-manual-steps">
              <div class="ext-manual-step"><b>1.</b> Abra o Google Maps e busque</div>
              <div class="ext-manual-step"><b>2.</b> Role a lista até carregar tudo</div>
              <div class="ext-manual-step"><b>3.</b> <b>Ctrl+A</b> → <b>Ctrl+C</b></div>
              <div class="ext-manual-step"><b>4.</b> Cole abaixo e clique Extrair</div>
            </div>
            <textarea id="ext-paste" placeholder="Cole aqui o texto copiado do Google Maps (Ctrl+A → Ctrl+C na página de resultados)…" style="min-height:140px"></textarea>
            <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap">
              <button class="btn btn-primary" onclick="extExtract()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Extrair
              </button>
              <button class="btn btn-ghost" onclick="extClear()" style="color:var(--error)">Limpar</button>
              <div style="flex:1;font-size:.75rem;color:var(--cinza);display:flex;align-items:center">
                💡 Sem limite de tamanho — funciona com listas de 100+ empresas.
              </div>
            </div>
          </div>
        </div>

        <!-- ═══ RESULTADOS (compartilhado entre os dois modos) ═══ -->
        <div id="ext-results-section" style="display:none">
          <div class="card ext-filter-bar" id="ext-filter-bar">
            <div class="ext-stats">
              <span class="org-stat ready" id="ext-stat-total">0 empresas</span>
              <span class="org-stat" id="ext-stat-phones">0 com tel.</span>
              <span class="org-stat warn" id="ext-stat-notel">0 sem tel.</span>
            </div>
            <div class="ext-filters">
              <label class="toggle-label">
                <input type="checkbox" id="ext-filter-phone" checked onchange="extRender()">
                Apenas com telefone
              </label>
              <select id="ext-filter-rating" onchange="extRender()" title="Filtrar por avaliação mínima">
                <option value="0">Toda avaliação</option>
                <option value="3">≥ 3 ★</option>
                <option value="4">≥ 4 ★</option>
                <option value="4.5">≥ 4.5 ★</option>
              </select>
              <input type="text" id="ext-filter-text" placeholder="🔎 Buscar empresa…" oninput="extRender()">
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-outline" onclick="extExportExcel()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Excel
              </button>
              <button class="btn btn-azul" onclick="extSendToCRM()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Salvar no CRM
              </button>
            </div>
          </div>
          <div class="ext-grid" id="ext-grid"></div>
        </div>

      </section>

      <!-- ══════════════════════════════════════════════════
           PÁGINA — BOAS PRÁTICAS
      ══════════════════════════════════════════════════ -->
      <section id="page-boaspraticas" class="page">

        <!-- Banner echo_lab_digital -->
        <div style="background:linear-gradient(135deg,#0f3a1a 0%,#1a5c2a 100%);border-radius:14px;padding:24px 28px;color:#fff;margin-bottom:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap">
          <div style="flex:1;min-width:200px">
            <div style="font-size:.65rem;color:rgba(200,169,110,.85);text-transform:uppercase;letter-spacing:.14em;font-weight:700;margin-bottom:6px">Guia completo desenvolvido por</div>
            <div style="font-size:1.5rem;font-weight:800;letter-spacing:-.02em;margin-bottom:4px">echo_lab_digital</div>
            <div style="font-size:.83rem;color:rgba(255,255,255,.55)">Automações e sistemas digitais sob medida para o seu negócio</div>
          </div>
          <a href="https://www.echolab.digital" target="_blank" style="background:rgba(200,169,110,.18);border:1px solid rgba(200,169,110,.45);color:#c8a96e;padding:11px 22px;border-radius:10px;text-decoration:none;font-size:.83rem;font-weight:700;white-space:nowrap;transition:all .2s">
            Visitar echolab.digital →
          </a>
        </div>

        <!-- BLOCO 1 — Estratégia de mensagem -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-head" style="margin-bottom:18px">
            <h3>✍️ Como escrever mensagens que convertem</h3>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
            <?php foreach([
              ['🎯','Personalize sempre','Use {{nome}} no início da mensagem. "Olá, {{nome}}!" é significativamente mais eficaz que "Olá!" genérico. O cérebro humano responde ao próprio nome.'],
              ['⚡','Seja direto no início','As primeiras 2 linhas definem se a pessoa vai ler o resto. Comece com o benefício, não com apresentação.'],
              ['📱','Mensagens curtas convertem mais','Mensagens de WhatsApp com até 160 caracteres têm taxas de resposta até 3× maiores. Reserve textos longos para clientes já engajados.'],
              ['🔄','Crie 3 a 5 variações','O sistema alterna entre elas automaticamente. Isso evita que o WhatsApp detecte padrão repetitivo e reduz o risco de bloqueio.'],
              ['💬','Use formatação nativa','*negrito* para destacar ofertas, _itálico_ para ênfase, ~tachado~ para preços originais. Evite CAPS LOCK — parece spam.'],
              ['📅','Horário faz diferença','Terça a quinta entre 9h–11h e 14h–17h têm as maiores taxas de abertura. Evite segunda de manhã, sexta à tarde e fins de semana.'],
            ] as [$ic,$t,$d]): ?>
            <div style="background:var(--creme);border-radius:10px;padding:14px 16px">
              <div style="font-size:.88rem;font-weight:700;margin-bottom:5px"><?= $ic ?> <?= $t ?></div>
              <div style="font-size:.8rem;color:var(--cinza);line-height:1.6"><?= $d ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="background:#f0f7f0;border:1px solid #c5dfca;border-radius:10px;padding:14px 16px">
            <div style="font-weight:700;font-size:.85rem;color:#1a5c2a;margin-bottom:8px">💡 Template validado pela echo_lab_digital:</div>
            <div style="background:#fff;border-radius:8px;padding:12px 16px;font-size:.85rem;color:#2a2a2a;line-height:1.7;border-left:3px solid var(--verde)">
              Olá, <strong>{{nome}}</strong>! 🌹<br>
              A <strong>Casa das Flores</strong> tem uma condição especial pra você hoje.<br>
              [Descreva o benefício em 1 frase curta]<br><br>
              Quer saber mais? Responda aqui que te conto tudo! 😊
            </div>
            <div style="font-size:.75rem;color:var(--cinza);margin-top:8px">✔ Personalizado · ✔ Curto · ✔ Tem CTA claro · ✔ Gera resposta</div>
          </div>
        </div>

        <!-- BLOCO 2 — Segmentação e listas -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-head" style="margin-bottom:18px">
            <h3>🎯 Segmentação — o segredo de quem converte</h3>
          </div>
          <p style="font-size:.85rem;color:var(--cinza);margin-bottom:16px">Disparar para todos da mesma forma é o erro mais comum. Segmentar significa enviar a mensagem <strong>certa</strong> para a <strong>pessoa certa</strong> no <strong>momento certo</strong>.</p>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
            <?php foreach([
              ['🟢','Qualidade Alta','Clientes recentes, quem já comprou nos últimos 90 dias, indicações. Taxa de resposta: 15–40%.'],
              ['🟡','Qualidade Média','Ex-clientes (+ de 90 dias), contatos do Google Maps já filtrados, leads de formulários. Taxa: 5–15%.'],
              ['🔴','Qualidade Baixa','Listas compradas, números coletados sem contexto, contatos sem relação com o negócio. Evite — alto risco de bloqueio.'],
            ] as [$ic,$t,$d]): ?>
            <div style="border-radius:10px;padding:14px;background:var(--creme);border-top:3px solid <?= $t==='Qualidade Alta'?'#4caf50':($t==='Qualidade Média'?'#ff9800':'#f44336') ?>">
              <div style="font-weight:700;font-size:.85rem;margin-bottom:6px"><?= $ic ?> <?= $t ?></div>
              <div style="font-size:.78rem;color:var(--cinza);line-height:1.55"><?= $d ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;font-size:.82rem;color:#78350f">
            <strong>💡 Estratégia echo_lab_digital:</strong> Use o CRM para separar contatos por status. Crie listas específicas como "Clientes VIP", "Aniversariantes do Mês", "Orçamentos Pendentes". Mensagem segmentada = muito mais resultado com menos disparo.
          </div>
        </div>

        <!-- BLOCO 3 — Como usar o sistema -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-head" style="margin-bottom:18px"><h3>📋 Passo a passo do sistema</h3></div>
          <div>
            <?php $passos = [
              ['1','🗂️ Preparar a lista no Organizador','Importe Excel, cole texto do Google Maps ou suba um .vcf do celular. Revise e edite os nomes antes de salvar — qualidade da lista impacta diretamente nos resultados.','Dica: use o botão de editar (✏️) para corrigir nomes antes de salvar. Remova entradas sem telefone ou com nomes inválidos.'],
              ['2','📋 Salvar no CRM com nome descritivo','Clique em "Salvar no CRM" com nome claro como "Clínicas Floripa - Jun/26". Isso cria histórico e permite reuso futuro.','Dica: nomeie por campanha e data. Facilita análise do que funcionou em cada disparo.'],
              ['3','✍️ Escrever a mensagem com variações','Selecione a lista no Disparo. Escreva a mensagem usando {{nome}}, {{empresa}}, {{cidade}}. Crie pelo menos 2–3 variações clicando em "+ Variação".','Dica: cada variação deve ter tom ligeiramente diferente — uma mais formal, outra mais descontraída. O sistema alterna automaticamente.'],
              ['4','⏱️ Configurar o intervalo entre envios','Use mínimo 6 segundos. Para disparos grandes (+100 contatos), use 8–12 segundos. Para listas super qualificadas, 4–5s é aceitável.','Dica: intervalo maior = disparo mais lento, mas muito mais seguro. Para aquecimento inicial, sempre 10s+.'],
              ['5','🚀 Revisar e confirmar o disparo','O modal de confirmação mostra: contatos, tempo estimado e prévia da mensagem. Confirme só depois de revisar tudo.','Dica: o painel mostra "Você ainda pode disparar X mensagens hoje com segurança" no Dashboard. Verifique antes de cada campanha grande.'],
              ['6','📊 Acompanhar em tempo real','O log exibe cada envio com ✅ ou ❌. Você pode pausar e retomar. O sistema continua mesmo fechando o navegador.','Dica: em caso de erro recorrente (❌), a instância pode estar com problema. Vá em Conexão e verifique o status.'],
              ['7','💚 Converter respostas no CRM','Quem responde é marcado automaticamente como "Em Contato". Atualize para "Ganho" ou "Perdido" após o atendimento.','Dica: responda todos os contatos que interagirem. Isso melhora sua reputação no WhatsApp e aumenta o limite de envio.'],
            ]; foreach($passos as $i => [$n,$t,$d,$tip]): ?>
            <div style="display:flex;gap:16px;padding:16px 0;<?= $i < count($passos)-1 ? 'border-bottom:1px solid var(--borda)' : '' ?>">
              <div style="width:36px;height:36px;background:var(--verde);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.88rem;font-weight:800;flex-shrink:0;box-shadow:0 2px 8px rgba(26,92,42,.3)"><?= $n ?></div>
              <div style="flex:1">
                <div style="font-weight:700;font-size:.9rem;margin-bottom:4px"><?= $t ?></div>
                <div style="font-size:.81rem;color:var(--cinza);margin-bottom:6px;line-height:1.6"><?= $d ?></div>
                <div style="font-size:.76rem;color:#1a5c2a;background:#f0f7f0;padding:5px 10px;border-radius:6px;display:inline-block"><?= $tip ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- BLOCO 4 — CRM e conversão -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-head" style="margin-bottom:16px"><h3>💼 Como usar o CRM para converter mais</h3></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <?php foreach([
              ['📊','Acompanhe o pipeline','O dashboard mostra Pendente → Em Contato → Ganho → Perdido. Mova os contatos conforme o andamento do atendimento.'],
              ['🏷️','Use listas com intenção','Crie listas como "Orçamento Maio", "VIP", "Aniversariantes". Permite recontato futuro com contexto.'],
              ['📤','Exporte antes de limpar','Sempre exporte em Excel antes de excluir uma lista. Dados de contato têm valor comercial.'],
              ['🔁','Recontate com estratégia','Espere pelo menos 30 dias antes de recontatar o mesmo contato. Mensagem diferente, ângulo diferente.'],
              ['📝','Registre observações','O campo "Observação" no CRM serve para anotar o que foi conversado — use para atendimentos futuros.'],
              ['📈','Analise o que funciona','Compare a taxa de resposta entre campanhas diferentes no Histórico. Repita o que converte.'],
            ] as [$ic,$t,$d]): ?>
            <div style="background:var(--creme);border-radius:10px;padding:13px 15px">
              <div style="font-size:.86rem;font-weight:700;margin-bottom:4px"><?= $ic ?> <?= $t ?></div>
              <div style="font-size:.78rem;color:var(--cinza);line-height:1.55"><?= $d ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- BLOCO 5 — Sinais de problema e o que fazer -->
        <div class="card" style="margin-bottom:16px;border-left:4px solid #f59e0b">
          <div class="card-head" style="margin-bottom:16px"><h3>⚠️ Sinais de problema — e como agir</h3></div>
          <div style="display:flex;flex-direction:column;gap:0">
            <?php foreach([
              ['🟡 Mensagens sendo enviadas mas não chegando','Sessão fantasma. Vá em Conexão → Desconectar. No celular, remova o aparelho em Aparelhos Conectados. Reconecte do zero.','alta'],
              ['🔴 "Sua conta está restringida" (com contador)','Restrição temporária por volume ou denúncias. Aguarde o prazo indicado. Não tente forçar envios. Use o WhatsApp normalmente para conversas.','alta'],
              ['🔴 Número não conecta mais na instância','Possível banimento permanente. Tente no celular: se o WhatsApp abrir, o problema é na instância — reconecte. Se não abrir, o número foi banido.','critico'],
              ['🟡 Muitos erros (❌) no log de disparo','Números inválidos na lista ou sem WhatsApp. Use pré-validação antes do próximo disparo. Limpe contatos com falha do CRM.','media'],
              ['🟡 Worker não processa os jobs','Verifique o cron no cPanel. Deve estar como "* * * * *" (5 asteriscos). Também pode acessar worker.php diretamente no browser para forçar.','media'],
            ] as [$s,$a,$nivel]): ?>
            <div style="padding:14px 0;border-bottom:1px solid var(--borda);display:flex;gap:14px;align-items:flex-start">
              <div style="width:10px;height:10px;border-radius:50%;background:<?= $nivel==='critico'?'#dc2626':($nivel==='alta'?'#f59e0b':'#3b82f6') ?>;flex-shrink:0;margin-top:5px"></div>
              <div>
                <div style="font-weight:700;font-size:.84rem;margin-bottom:4px"><?= $s ?></div>
                <div style="font-size:.79rem;color:var(--cinza);line-height:1.55"><?= $a ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- BLOCO 6 — Perguntas frequentes -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-head" style="margin-bottom:16px"><h3>❓ Perguntas frequentes</h3></div>
          <div style="display:flex;flex-direction:column;gap:0">
            <?php foreach([
              ['Quantas mensagens posso enviar por dia?','Depende da idade e histórico da sua conta WhatsApp. Contas consolidadas com mais de 1 ano costumam suportar 1.000+/dia. Monitore o card "Limite diário" no Dashboard — ele reflete o que já foi enviado hoje.'],
              ['Posso usar o número do WhatsApp pessoal?','Sim, mas não é recomendado. O ideal é ter um número dedicado ao negócio (WhatsApp Business). Caso o número seja banido, você perde o histórico de conversas pessoais.'],
              ['O disparo para quando eu fecha o navegador?','Não. O worker.php roda no servidor a cada minuto via cron. Depois de iniciar o disparo, você pode fechar o navegador com segurança.'],
              ['Como sei se as mensagens estão chegando?','Confira o worker.log. Se aparecer ✅ mas você não vê no celular destino, pode ser sessão fantasma — reconecte a instância.'],
              ['Posso enviar imagem junto com o texto?','Sim. No Disparo, faça upload da imagem na seção "Imagem". O texto vai como legenda da foto.'],
              ['Como funciona o rodízio de variações?','O sistema alterna entre as variações na ordem que foram criadas. Contato 1 recebe variação 1, contato 2 recebe variação 2, e assim por diante.'],
              ['Posso pausar e retomar um disparo?','Sim. Clique em "Pausar" durante o disparo. O sistema salva o progresso. Clique em "Retomar" quando quiser continuar — os números já enviados não são reenviados.'],
              ['Como reconectar o WhatsApp corretamente?','1. No celular: WhatsApp → Aparelhos Conectados → remova o aparelho. 2. No painel: Conexão → Desconectar. 3. Aguarde 1 minuto. 4. Clique em Conectar e escaneie o QR.'],
            ] as $i => [$p,$r]): ?>
            <div style="padding:14px 0;border-bottom:1px solid var(--borda)">
              <div style="font-weight:700;font-size:.85rem;margin-bottom:5px;color:var(--verde-800)">❓ <?= $p ?></div>
              <div style="font-size:.81rem;color:var(--cinza);line-height:1.65"><?= $r ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Footer echo_lab -->
        <div style="background:linear-gradient(135deg,#0f3a1a,#1a5c2a);border-radius:14px;padding:20px 24px;text-align:center;color:#fff;margin-bottom:8px">
          <div style="font-size:1rem;font-weight:700;margin-bottom:4px">Precisa de ajuda ou quer evoluir o sistema?</div>
          <div style="font-size:.83rem;color:rgba(255,255,255,.6);margin-bottom:14px">A echo_lab_digital desenvolve automações, agentes de IA e sistemas digitais sob medida.</div>
          <a href="https://www.echolab.digital" target="_blank" style="background:#c8a96e;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:700;display:inline-block">
            Falar com a echo_lab_digital →
          </a>
        </div>

      </section>

      <!-- ═══════════════════════════════════════════════════
           PÁGINA — CONFIGURAÇÕES
      ═══════════════════════════════════════════════════ -->
      <section id="page-configuracoes" class="page">

        <div class="card">
          <div class="card-head">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
              Configurações do Painel
            </h3>
          </div>

          <!-- Empresa -->
          <div style="margin-bottom:24px">
            <div style="font-size:.72rem;font-weight:700;color:var(--verde);text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;display:flex;align-items:center;gap:8px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
              Dados da Empresa
            </div>
            <div class="form-group">
              <label>Nome da empresa</label>
              <input type="text" id="cfg-company-name" placeholder="Ex: Casa das Flores">
            </div>
            <div class="form-group">
              <label>Cidade / Estado</label>
              <input type="text" id="cfg-city" placeholder="Ex: Florianópolis · SC">
            </div>
            <div class="form-group">
              <label>Rodapé (texto do sidebar)</label>
              <input type="text" id="cfg-footer" placeholder="Ex: desde 1983">
            </div>
          </div>

          <!-- Limites de disparo (cresce semana a semana) -->
          <div style="margin-bottom:24px;padding-top:20px;border-top:1px solid var(--borda)">
            <div style="font-size:.72rem;font-weight:700;color:var(--verde);text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;display:flex;align-items:center;gap:8px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              Segurança — Limite Diário de Disparos
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
              <div class="form-group" style="margin-bottom:0">
                <label>Limite diário de mensagens</label>
                <input type="number" id="cfg-daily-limit" min="50" max="5000" step="50" placeholder="200">
                <div class="hint">Comece com 200. Aumente 50–100 por semana se não houver problemas.</div>
              </div>
              <div class="form-group" style="margin-bottom:0">
                <label>Tipo de conta WhatsApp</label>
                <select id="cfg-is-business" style="width:100%;padding:10px 12px;border:1px solid var(--borda);border-radius:9px;font-size:.88rem">
                  <option value="0">Pessoal (limite mais conservador)</option>
                  <option value="1">Business / API (maior capacidade)</option>
                </select>
                <div class="hint">Business suporta volumes maiores, mas ainda exige aquecimento gradual.</div>
              </div>
            </div>
            <div style="margin-top:12px;background:#fffbf0;border-left:3px solid var(--ouro,#c8a96e);padding:10px 14px;border-radius:6px;font-size:.8rem;color:#7a6030">
              💡 <strong>Plano de aquecimento sugerido:</strong>
              Sem. 1: 200/dia → Sem. 2: 300/dia → Sem. 3: 400/dia → Sem. 4: 500/dia.
              Só avance se a semana anterior foi sem bloqueios ou avisos.
            </div>
          </div>

          <!-- Google Maps API -->
          <div style="margin-bottom:24px;padding-top:20px;border-top:1px solid var(--borda)">
            <div style="font-size:.72rem;font-weight:700;color:var(--verde);text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;display:flex;align-items:center;gap:8px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              Extrator de Leads — Google Maps
            </div>
            <div style="background:#0f3a1a;color:#c8f0c8;border-radius:8px;padding:12px 16px;font-size:.82rem;display:flex;align-items:center;gap:10px">
              <span style="font-size:1.1rem">🔒</span>
              <span>Chave da API configurada de forma segura no servidor. O Extrator de Leads está pronto para uso.</span>
            </div>
          </div>

          <!-- Segurança -->
          <div style="margin-bottom:24px;padding-top:20px;border-top:1px solid var(--borda)">
            <div style="font-size:.72rem;font-weight:700;color:var(--verde);text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;display:flex;align-items:center;gap:8px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              Alterar Senha de Acesso
            </div>
            <div class="form-group">
              <label>Senha atual</label>
              <input type="password" id="cfg-pw-current" placeholder="••••••••">
            </div>
            <div class="form-group">
              <label>Nova senha</label>
              <input type="password" id="cfg-pw-new" placeholder="••••••••">
            </div>
            <div class="form-group">
              <label>Confirmar nova senha</label>
              <input type="password" id="cfg-pw-confirm" placeholder="••••••••">
            </div>
          </div>

          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn btn-primary" onclick="saveSettings()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Salvar Configurações
            </button>
            <div id="cfg-feedback" style="display:none;align-items:center;gap:8px;font-size:.85rem;font-weight:600;color:var(--success)"></div>
          </div>
        </div>

      </section>

    </div><!-- /page-wrap -->
  </main>

</div><!-- /app-shell -->

<!-- ═══════════════════════════════════════════════════════════════
     SCRIPTS
═══════════════════════════════════════════════════════════════ -->
<script>
const PAGE_TITLES = {
  dashboard:      ['Dashboard',      'Visão geral'],
  conexao:        ['Conexão',        'WhatsApp'],
  disparo:        ['Disparo',        'Nova campanha'],
  historico:      ['Histórico',      'Campanhas realizadas'],
  crm:            ['CRM',            'Gestão de contatos'],
  organizador:    ['Organizador de Lista', 'Organizar contatos'],
  extrator:       ['Extrator',       'Leads do Google Maps'],
  configuracoes:  ['Configurações',  'Painel e conta'],
};

let contacts     = [];
let stopFlag     = false;
let pauseFlag    = false;
let pauseResolve = null;
let imgUrl       = '';
// Token diário para autenticação do groq.php
const GROQ_PANEL_TOKEN = '<?= md5(GROQ_API_KEY . date('Y-m-d')) ?>';

let statusInterval = null;

/* ── NAVIGATION ─────────────────────────── */
function goPage(id) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-' + id)?.classList.add('active');
  document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
  document.querySelectorAll(`.nav-link[data-page="${id}"]`).forEach(a => a.classList.add('active'));
  const t = PAGE_TITLES[id] || [id, ''];
  document.getElementById('page-title').innerHTML =
    `${t[0]} <span class="crumb">${t[1]}</span>`;
  if (window.innerWidth <= 760) toggleSidebar(false);
  if (id === 'historico') loadHistorico();
  if (id === 'crm') { crmLoad(1); loadCrmSources(); loadCrmCampaigns(); }
  if (id === 'extrator') {
    // Inicia wizard no estado correto (passo 1 se cidade vazia, etc)
    if (typeof extWizardSync === 'function') extWizardSync();
  }
  if (id === 'conversas') {
    startChatPolling();
  } else {
    // Para polling quando sai da aba conversas e limpa conversa ativa
    if (typeof stopChatPolling === 'function' && chatPollInterval) { stopChatPolling(); chatActive = null; }
  }
  if (id === 'disparo') {
    refreshCrmLists();
    csvContacts = []; manualContacts = []; contacts = [];
    document.getElementById('contacts-num').textContent = '0';
    document.querySelectorAll('.crm-list-cb').forEach(cb => cb.checked = false);
    updateCrmListsSummary();
    // Verifica job ativo (para recuperar após refresh)
    fetch('historico.php?action=active', {credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{
        if (!d.ok || !d.jobs?.length) return;
        const j = d.jobs[0];
        const statusLabel = {running:'em andamento ⏳', paused:'pausado ⏸', pending:'aguardando 🕐', scheduled:'agendado 📅'}[j.status]||j.status;
        const bar = document.getElementById('send-progress');
        const log = document.getElementById('progress-log');
        if (bar) bar.style.display = 'block';
        if (log) log.innerHTML = `<div class="log-line warn">
          ⚠️ Disparo <strong>#${j.id} "${esc(j.job_name)}"</strong> está ${statusLabel} — ${j.sent}/${j.total} enviados.
          ${j.status==='paused'?`<button class="btn btn-outline" style="padding:3px 10px;font-size:.75rem;margin-left:8px" onclick="resumeJob(${j.id})">▶ Retomar</button>`:''}
          <button class="btn btn-ghost" style="padding:3px 8px;font-size:.75rem;color:var(--cinza);margin-left:4px" onclick="goPage('historico')">Ver histórico →</button>
        </div>`;
        currentJobId = j.id;
        if (j.status === 'running') startPoll();
      }).catch(()=>{});
    // Status de limite de imagem
    fetch('groq.php?action=img_status', {credentials:'same-origin', headers:{'X-Panel-Token':GROQ_PANEL_TOKEN}})
      .then(r=>r.json()).then(d=>{
        if (!d.ok) return;
        const imgInput = document.getElementById('img-file');
        if (d.remaining === 0 && imgInput) imgInput.title = `Limite de ${d.limit} análises de imagem/dia atingido.`;
      }).catch(()=>{});
  }
  if (id === 'configuracoes') loadSettings();
  if (id === 'dashboard') loadDashboard();
  if (id === 'boaspraticas') {}
  if (id === 'conexao' && !document.querySelector('#qr-container img')) loadQr();
}
document.querySelectorAll('.nav-link[data-page]').forEach(a => {
  a.addEventListener('click', () => goPage(a.dataset.page));
});
function toggleSidebar(force) {
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sidebar-overlay');
  const open = force === undefined ? !sb.classList.contains('open') : force;
  sb.classList.toggle('open', open);
  ov.classList.toggle('show', open);
}

/* ── STATUS ─────────────────────────────── */
async function checkStatus() {
  try {
    // Primeiro tenta status via webhook (mais confiável)
    const rw = await fetch('settings.php?action=get', {credentials:'same-origin'});
    const dw = await rw.json();
    const waStatus = dw.settings?.wa_status ?? null;

    const r = await fetch('api.php?action=status');
    const d = await r.json();
    const connected = d.connected === true || d.status === 'CONNECTED' || d.status === 'connected' || (d._http_code === 403 && !d.qrcode);
    updateStatus(connected, d);
  } catch(e) { updateStatus(false, {error:'Sem resposta da API'}); }
}
function updateStatus(connected, data) {
  const dot  = document.getElementById('status-dot');
  const txt  = document.getElementById('status-text');
  const warn = document.getElementById('alert-not-connected');
  // Banner dashboard
  const banner = document.getElementById('dash-status-banner');
  const bannerTitle = document.getElementById('dash-status-title');
  const bannerSub   = document.getElementById('dash-status-sub');

  dot.className = '';
  if (connected) {
    dot.classList.add('connected');
    txt.textContent = 'Conectado';
    if (warn) warn.style.display = 'none';
    if (banner) {
      banner.className = 'dash-status-banner connected';
      bannerTitle.textContent = '✅ WhatsApp conectado — pronto para disparar';
      bannerSub.textContent   = 'Instância: ' + (data.instanceId || data.profileName || '<?= htmlspecialchars(ZAPI_INSTANCE) ?>');
    }
    updateConnInfo(data, true);
  } else if (data.qrcode || data.qr) {
    dot.classList.add('connecting');
    txt.textContent = 'Aguardando QR';
    if (warn) warn.style.display = 'flex';
    if (banner) {
      banner.className = 'dash-status-banner disconnected';
      bannerTitle.textContent = '⚠️ WhatsApp desconectado — escaneie o QR Code';
      bannerSub.textContent   = 'Vá em Conexão para conectar o WhatsApp';
    }
    updateConnInfo(data, false);
    showQrFromData(data);
  } else {
    dot.classList.add('error');
    txt.textContent = 'Desconectado';
    if (warn) warn.style.display = 'flex';
    if (banner) {
      banner.className = 'dash-status-banner disconnected';
      bannerTitle.textContent = '❌ WhatsApp desconectado';
      bannerSub.textContent   = 'Vá em Conexão para reconectar';
    }
    updateConnInfo(data, false);
  }
}
function updateConnInfo(data, connected) {
  const el = document.getElementById('conn-info');
  if (connected) {
    // Mostra placeholder enquanto carrega o /device
    el.innerHTML = `<dl>
      <dt>Status</dt><dd style="color:var(--success);font-weight:600">● Conectado</dd>
      <dt>Perfil</dt><dd id="conn-perfil-cell"><em style="color:var(--cinza)">carregando…</em></dd>
      <dt>Número</dt><dd id="conn-numero-cell"><em style="color:var(--cinza)">carregando…</em></dd>
      <dt>Tipo</dt><dd id="conn-tipo-cell"><em style="color:var(--cinza)">carregando…</em></dd>
    </dl>`;
    // Busca info do device da Z-API (phone, name, isBusiness)
    fetch('api.php?action=device').then(r => r.json()).then(d => {
      const perfilEl = document.getElementById('conn-perfil-cell');
      const numEl    = document.getElementById('conn-numero-cell');
      const tipoEl   = document.getElementById('conn-tipo-cell');
      if (perfilEl) perfilEl.textContent = d.name || window._cfgProfileName || '—';
      if (numEl)    numEl.textContent    = fmtPhoneDisplay(d.phone) || window._cfgPhone || '—';
      if (tipoEl)   tipoEl.innerHTML     = d.isBusiness
        ? '<span style="background:rgba(46,125,50,.1);color:#2e7d32;padding:2px 8px;border-radius:8px;font-size:.75rem;font-weight:600">WhatsApp Business</span>'
        : '<span style="background:rgba(127,127,127,.1);color:#666;padding:2px 8px;border-radius:8px;font-size:.75rem;font-weight:600">WhatsApp Pessoal</span>';
      // Salva número detectado nas configurações se ainda não tem
      if (d.phone && !window._cfgPhone) {
        window._cfgPhone = d.phone;
        // Atualiza também banner do dashboard se existir
        const sub = document.getElementById('dash-status-sub');
        if (sub) sub.textContent = 'Conectado: ' + fmtPhoneDisplay(d.phone);
      }
      window._cfgIsBusiness = !!d.isBusiness;
    }).catch(() => {
      const cells = ['conn-perfil-cell','conn-numero-cell','conn-tipo-cell'];
      cells.forEach(id => { const e = document.getElementById(id); if (e) e.textContent = '—'; });
    });
  } else {
    el.innerHTML = `<dl>
      <dt>Status</dt><dd style="color:var(--error);font-weight:600">● Desconectado</dd>
    </dl><p style="margin-top:12px;font-size:.85rem;color:var(--cinza)">Escaneie o QR Code para conectar.</p>`;
  }
}

function fmtPhoneDisplay(phone) {
  if (!phone) return '';
  const p = String(phone).replace(/\D/g,'');
  // 5548999999999 → +55 48 99999-9999
  if (p.length === 13 && p.startsWith('55')) {
    return `+55 ${p.slice(2,4)} ${p.slice(4,9)}-${p.slice(9)}`;
  }
  if (p.length === 12 && p.startsWith('55')) {
    return `+55 ${p.slice(2,4)} ${p.slice(4,8)}-${p.slice(8)}`;
  }
  if (p.length === 11) {
    return `(${p.slice(0,2)}) ${p.slice(2,7)}-${p.slice(7)}`;
  }
  return phone;
}
function showQrFromData(data) {
  const qrRaw = data.qrcode ?? data.qr ?? data.base64 ?? null;
  if (!qrRaw) return;
  setQrImg(qrRaw.startsWith('data:') ? qrRaw : 'data:image/png;base64,' + qrRaw);
}

function setQrImg(src) {
  const ph  = document.getElementById('qr-placeholder');
  const sp  = document.getElementById('qr-spinner');
  const img = document.getElementById('qr-img');
  if (ph)  ph.style.display  = 'none';
  if (sp)  sp.style.display  = 'none';
  if (img) { img.src = src; img.style.display = 'block'; }
}

function clearQrImg() {
  const ph  = document.getElementById('qr-placeholder');
  const img = document.getElementById('qr-img');
  if (img) { img.src = ''; img.style.display = 'none'; }
  if (ph)  ph.style.display = 'block';
}

/* ── QR ─────────────────────────────────── */
async function restartConnection() {
  if (!confirm('Reiniciar a sessão do WhatsApp?\n\nA conexão será reestabelecida automaticamente em alguns segundos.')) return;
  try {
    const r = await fetch('api.php?action=restart', {method:'POST'});
    const d = await r.json();
    if (d.ok) {
      alert('✅ Sessão reiniciada. Aguarde alguns segundos para reconectar.');
      setTimeout(checkStatus, 3000);
    } else {
      alert('❌ Não foi possível reiniciar:\n' + (d.error || JSON.stringify(d.tried || {})));
    }
  } catch(e) { alert('❌ Erro de rede: ' + e.message); }
}

async function disconnectWA() {
  if (!confirm('Desconectar o WhatsApp da instância?\n\nVocê precisará escanear o QR Code novamente para conectar.')) return;
  try {
    const r = await fetch('api.php?action=disconnect', {method:'POST'});
    const d = await r.json();
    if (d.ok) {
      alert('✅ WhatsApp desconectado. Clique em "Novo QR Code" para reconectar.');
      setTimeout(() => { checkStatus(); loadQr(); }, 2000);
    } else {
      alert('❌ Não foi possível desconectar pelo painel.\n\nFaça pelo painel da W-API → SAIR.\n\nDetalhes: ' + (d.error || ''));
    }
  } catch(e) { alert('❌ Erro de rede: ' + e.message); }
}

async function loadQr() {
  const ph = document.getElementById('qr-placeholder');
  const sp = document.getElementById('qr-spinner');
  const img = document.getElementById('qr-img');
  if (ph)  ph.style.display  = 'none';
  if (img) img.style.display = 'none';
  if (sp)  sp.style.display  = 'block';
  try {
    const r = await fetch('api.php?action=qr');
    const d = await r.json();
    if (sp) sp.style.display = 'none';
    const qrRaw = d.qrcode ?? d.qr ?? d.base64 ?? d.code ?? null;
    if (qrRaw) {
      setQrImg(qrRaw.startsWith('data:') ? qrRaw : 'data:image/png;base64,' + qrRaw);
    } else if (d.connected === true) {
      if (ph) { ph.innerHTML = '✅ WhatsApp já conectado'; ph.style.display = 'block'; }
    } else {
      if (ph) { ph.textContent = 'QR Code não disponível.'; ph.style.display = 'block'; }
    }
  } catch(e) {
    if (sp) sp.style.display = 'none';
    if (ph) { ph.textContent = 'Erro ao buscar QR Code.'; ph.style.display = 'block'; }
  }
}
async function logout() {
  if (!confirm('Desconectar o WhatsApp?')) return;
  await fetch('api.php?action=logout');
  checkStatus(); loadQr();
}

/* ── UPLOAD ─────────────────────────────── */
async function uploadImg(input) {
  if (!input.files[0]) return;
  const fd = new FormData();
  fd.append('image', input.files[0]);
  const wrap = document.getElementById('img-preview-wrap');
  const prev = document.getElementById('img-preview');
  const out  = document.getElementById('img-url-out');
  const hid  = document.getElementById('img-url');
  wrap.style.display = 'block';
  out.textContent = '⏳ Fazendo upload…';
  prev.style.display = 'none';
  try {
    const r = await fetch('upload.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      imgUrl = d.url; hid.value = imgUrl;
      prev.src = imgUrl; prev.style.display = 'block';
      out.textContent = '✅ ' + imgUrl;
    } else {
      out.textContent = '❌ ' + (d.error ?? 'Erro no upload');
      imgUrl = '';
    }
  } catch(e) {
    out.textContent = '❌ Falha de rede';
    imgUrl = '';
  }
}

/* ── CSV / CONTATOS ─────────────────────── */
let csvContacts    = []; // contatos vindos de CSV/Excel/CRM
let manualContacts = []; // contatos digitados manualmente

// contacts = union de csvContacts + manualContacts (sem duplicata)
function clearContacts() {
  csvContacts = []; manualContacts = []; contacts = [];
  document.getElementById('manual-phones').value = '';
  document.getElementById('contacts-num').textContent = '0';
  document.getElementById('contacts-preview').style.display = 'none';
  document.getElementById('crm-list-select').value = '';
  document.getElementById('csv-file').value = '';
}

function rebuildContacts() {
  const seen = new Set();
  contacts = [];
  [...csvContacts, ...manualContacts].forEach(c => {
    if (!seen.has(c.phone)) { seen.add(c.phone); contacts.push(c); }
  });
  document.getElementById('contacts-num').textContent = contacts.length;
}

function mergeManualPhones() {
  manualContacts = document.getElementById('manual-phones').value
    .split('\n')
    .map(l => l.replace(/\D/g,''))
    .filter(p => p.length >= 10)
    .map(p => ({phone:p, nome:'', empresa:'', cidade:''}));
  rebuildContacts();
}
function loadCsv(input) {
  if (!input.files[0]) return;
  const ext = input.files[0].name.split('.').pop().toLowerCase();
  if (ext === 'xlsx' || ext === 'xls') {
    const r = new FileReader();
    r.onload = e => {
      const wb = XLSX.read(e.target.result, { type:'binary' });
      const ws = wb.Sheets[wb.SheetNames[0]];
      const rows = XLSX.utils.sheet_to_json(ws, { defval:'' });
      parseContactsFromRows(rows);
    };
    r.readAsBinaryString(input.files[0]);
  } else {
    const r = new FileReader();
    r.onload = (e) => parseContacts(e.target.result);
    r.readAsText(input.files[0]);
  }
}
function parseContacts(text) {
  const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  if (!lines.length) return;
  const headerLine = lines[0].toLowerCase();
  const hasHeader = /telefone|phone|numero|número|whatsapp|nome|empresa|cidade/i.test(headerLine);
  const cols = hasHeader ? headerLine.split(/[,;\t]/) : [];
  const idx = (patterns) => cols.findIndex(c => patterns.some(p => p.test(c)));
  const phoneIdx  = hasHeader ? idx([/telefone|phone|numero|número|whatsapp|fone/i]) : 0;
  const nomeIdx   = hasHeader ? idx([/^nome$|^name$/i]) : -1;
  const empIdx    = hasHeader ? idx([/empresa|company|cliente/i]) : -1;
  const cidIdx    = hasHeader ? idx([/cidade|city/i]) : -1;
  contacts = [];
  for (let i = hasHeader ? 1 : 0; i < lines.length; i++) {
    const parts = lines[i].split(/[,;\t]/);
    const raw = (parts[phoneIdx < 0 ? 0 : phoneIdx] ?? '').replace(/\D/g,'');
    if (raw.length >= 10) contacts.push({
      phone:   raw,
      nome:    nomeIdx  >= 0 ? (parts[nomeIdx]  ?? '').trim() : '',
      empresa: empIdx   >= 0 ? (parts[empIdx]   ?? '').trim() : '',
      cidade:  cidIdx   >= 0 ? (parts[cidIdx]   ?? '').trim() : '',
    });
  }
  csvContacts = [...contacts];
  rebuildContacts();
}
function parseContactsFromRows(rows) {
  if (!rows.length) return;
  const headers = Object.keys(rows[0]);
  const find = (pats) => headers.find(h => pats.some(p => p.test(h))) ?? null;
  const phoneCol  = find([/telefone|phone|numero|número|whatsapp|fone/i]);
  const nomeCol   = find([/^nome$|^name$/i]);
  const empCol    = find([/empresa|company|cliente/i]);
  const cidCol    = find([/cidade|city/i]);
  if (!phoneCol) { alert('Coluna de telefone não encontrada.'); return; }
  csvContacts = rows.map(r => ({
    phone:   String(r[phoneCol]||'').replace(/\D/g,''),
    nome:    nomeCol ? String(r[nomeCol]  ||'').trim() : '',
    empresa: empCol  ? String(r[empCol]   ||'').trim() : '',
    cidade:  cidCol  ? String(r[cidCol]   ||'').trim() : '',
  })).filter(c => c.phone.length >= 10);
  rebuildContacts();
}
function previewContacts() {
  mergeManualPhones();
  const el = document.getElementById('contacts-preview');
  if (!contacts.length) { el.style.display='none'; return; }
  el.style.display = 'block';
  el.innerHTML = contacts.slice(0, 50).map(c =>
    `📱 ${c.phone}${c.nome ? ` — <em>${esc(c.nome)}</em>` : ''}`
  ).join('<br>') + (contacts.length > 50 ? `<br><em>…e mais ${contacts.length - 50}</em>` : '');
}

/* ── DISPARO — Fase 2 ────────────────────── */
let disparoLog = [];

function getVariations() {
  return Array.from(document.querySelectorAll('.variation-text'))
    .map(t => t.value.trim()).filter(Boolean);
}

function applyVars(text, contact) {
  return text
    .replace(/\{\{nome\}\}/gi,     contact.nome     || '')
    .replace(/\{\{empresa\}\}/gi,  contact.empresa  || '')
    .replace(/\{\{cidade\}\}/gi,   contact.cidade   || '')
    .replace(/\{\{telefone\}\}/gi, contact.phone    || '');
}

function pickVariation(variations, idx) {
  // Rodízio sequencial — distribui uniformemente
  return variations[idx % variations.length];
}

/* ── DISPARO SERVER-SIDE (via worker.php + queue.php) ──────── */
let currentJobId  = null;
let pollInterval  = null;

/* ── TOAST NOTIFICATIONS ─────────────────── */
function toast(msg, type='success', duration=3500) {
  const icons = { success:'✅', error:'❌', warn:'⚠️', info:'💡' };
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<span>${icons[type]||'💬'}</span><span style="flex:1">${msg}</span>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;color:rgba(255,255,255,.5);cursor:pointer;font-size:1rem;line-height:1;padding:0 0 0 8px">×</button>`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => {
    el.style.animation = 'toastOut .3s ease forwards';
    setTimeout(() => el.remove(), 300);
  }, duration);
}

function btnLoading(btn, loading=true) {
  if (!btn) return;
  if (loading) { btn.dataset.origText = btn.innerHTML; btn.classList.add('loading'); btn.disabled = true; }
  else { if (btn.dataset.origText) btn.innerHTML = btn.dataset.origText; btn.classList.remove('loading'); btn.disabled = false; }
}

/* ── ONBOARDING — primeiro acesso ───────────── */
function maybeShowOnboarding() {
  // Verifica via settings se já viu
  fetch('settings.php?action=get', {credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if (d.ok && d.settings && d.settings.onboarded === '1') return;
    showOnboardingStep(0);
  }).catch(()=>{});
}

const onboardingSteps = [
  {
    icon: '👋',
    title: 'Bem-vindo ao painel da Casa das Flores!',
    body: `Este é seu novo sistema de disparos via WhatsApp. Em 3 passos você vai aprender a usar tudo.<br><br>O sistema é <strong>profissional</strong>, foi desenvolvido sob medida para seu negócio e cuida do envio mesmo se você fechar o navegador.`,
    btn: 'Vamos lá →',
  },
  {
    icon: '📱',
    title: 'Passo 1 — Conectar o WhatsApp',
    body: `Vá em <strong>"Conexão"</strong> no menu lateral e escaneie o QR Code com o celular cadastrado.<br><br>Use sempre o <strong>mesmo número</strong> de WhatsApp para manter a estabilidade. Quanto mais antiga a conta, maior o limite de disparos por dia.`,
    btn: 'Continuar →',
  },
  {
    icon: '📋',
    title: 'Passo 2 — Criar listas de contatos',
    body: `O <strong>Organizador</strong> aceita arquivos do Excel, contatos do celular (.vcf) ou texto colado do Google Maps.<br><br>Após processar, salve a lista no <strong>CRM</strong> com um nome — depois ela aparecerá no Disparo prontinha pra usar.`,
    btn: 'Continuar →',
  },
  {
    icon: '🚀',
    title: 'Passo 3 — Fazer o primeiro disparo',
    body: `Vá em <strong>"Disparo"</strong>, selecione uma lista do CRM, escreva sua mensagem usando <code style="background:#f4f0ea;padding:2px 6px;border-radius:4px">{{nome}}</code> para personalizar e clique em <strong>Iniciar Disparo</strong>.<br><br>💡 <strong>Comece com poucos contatos</strong> nos primeiros dias para "aquecer" sua conta.`,
    btn: 'Tudo pronto! 🌹',
  },
];

function showOnboardingStep(idx) {
  if (idx >= onboardingSteps.length) {
    // Salva que completou
    fetch('settings.php?action=save', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({onboarded: '1'})
    }).catch(()=>{});
    return;
  }
  const step = onboardingSteps[idx];
  const total = onboardingSteps.length;

  const modal = document.createElement('div');
  modal.className = 'onboard-modal';
  modal.style.cssText = 'position:fixed;inset:0;background:linear-gradient(135deg,rgba(15,58,26,.85),rgba(26,92,42,.85));z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);animation:onboardFadeIn .3s ease';
  modal.innerHTML = `
    <style>@keyframes onboardFadeIn{from{opacity:0}to{opacity:1}}@keyframes onboardSlideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}</style>
    <div style="background:#fff;border-radius:18px;max-width:500px;width:100%;box-shadow:0 25px 80px rgba(0,0,0,.4);overflow:hidden;animation:onboardSlideUp .35s ease">
      <div style="background:linear-gradient(135deg,#0f3a1a,#1a5c2a);padding:32px 28px;text-align:center;color:#fff">
        <div style="font-size:3.5rem;margin-bottom:8px;line-height:1">${step.icon}</div>
        <h2 style="margin:0;font-size:1.3rem;font-weight:700;letter-spacing:-.01em">${step.title}</h2>
      </div>
      <div style="padding:24px 28px;font-size:.95rem;color:#3a3a3a;line-height:1.65">
        ${step.body}
      </div>
      <div style="padding:0 28px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div style="display:flex;gap:6px">
          ${onboardingSteps.map((_, i) => `
            <div style="width:${i===idx?'24px':'8px'};height:8px;border-radius:4px;background:${i===idx?'#1a5c2a':i<idx?'#c5dfca':'#e8e6df'};transition:all .3s ease"></div>
          `).join('')}
        </div>
      ${idx === 0 ? `
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.78rem;color:rgba(255,255,255,.5)">
          <input type="checkbox" id="ob-no-more" style="width:14px;height:14px;cursor:pointer">
          Não mostrar mais ao entrar
        </label>
      ` : ''}
      <div style="display:flex;gap:8px">
        ${idx > 0 ? `<button class="btn btn-outline" id="ob-back" style="padding:8px 14px;font-size:.85rem;background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);color:#fff">← Voltar</button>` : ''}
        ${idx === 0 ? `<button class="btn btn-ghost" id="ob-skip" style="padding:8px 14px;font-size:.85rem;color:rgba(255,255,255,.5)">Pular</button>` : ''}
        <button class="btn btn-primary" id="ob-next" style="padding:8px 18px">${step.btn}</button>
      </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  const close = () => modal.remove();
  const markDone = () => {
    fetch('settings.php?action=save', {
      method:'POST',headers:{'Content-Type':'application/json'},
      body: JSON.stringify({onboarded: '1'})
    }).catch(()=>{});
  };
  document.getElementById('ob-next').onclick = () => {
    if (idx === 0) {
      const noMore = document.getElementById('ob-no-more');
      if (noMore && noMore.checked) markDone();
    }
    if (idx >= onboardingSteps.length - 1) markDone();
    close(); showOnboardingStep(idx + 1);
  };
  if (idx > 0) document.getElementById('ob-back').onclick = () => { close(); showOnboardingStep(idx - 1); };
  if (idx === 0) document.getElementById('ob-skip').onclick = () => {
    const noMore = document.getElementById('ob-no-more');
    if (noMore && noMore.checked) markDone();
    close();
  };
}

/* ── MODAL DE CONFIRMAÇÃO DE DISPARO ─────── */
function showConfirmDispatch({ jobName, contacts, variations, delay, imgUrl, scheduledAt = null }) {
  return new Promise((resolve) => {
    const totalTime = contacts.length * delay;
    const minutes = Math.floor(totalTime / 60);
    const seconds = totalTime % 60;
    const timeStr = minutes > 0 ? `~${minutes}min ${seconds}s` : `~${seconds}s`;

    // Limites por tipo de conta (assumindo padrão para conta antiga)
    const isBusiness  = window._cfgIsBusiness === true;
    const recommended = window._cfgDailyLimit || (isBusiness ? 300 : 200);
    const warningLevel =
      contacts.length > recommended ? 'high' :
      contacts.length > recommended * 0.5 ? 'medium' : 'low';

    const previewMsg = variations[0].slice(0, 200) + (variations[0].length > 200 ? '…' : '');
    const schedLabel = scheduledAt
      ? new Date(scheduledAt).toLocaleString('pt-BR', {weekday:'long',day:'2-digit',month:'long',hour:'2-digit',minute:'2-digit'})
      : null;

    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(15,58,26,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px)';
    modal.innerHTML = `
      <div style="background:#fff;border-radius:14px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <div style="padding:22px 24px;border-bottom:1px solid var(--borda)">
          <h3 style="margin:0;font-size:1.15rem;font-weight:700;color:var(--verde-800);display:flex;align-items:center;gap:10px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>
            Confirmar disparo
          </h3>
        </div>
        <div style="padding:20px 24px">
          <div style="background:var(--creme);border-radius:10px;padding:14px 16px;margin-bottom:14px">
            <div style="font-size:.7rem;color:var(--cinza);text-transform:uppercase;font-weight:700;letter-spacing:.08em;margin-bottom:4px">Campanha</div>
            <div style="font-size:1rem;font-weight:600;color:var(--texto)">${esc(jobName)}</div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
            <div style="background:#f4f8f5;border:1px solid #c5dfca;border-radius:10px;padding:12px">
              <div style="font-size:.65rem;color:#1a5c2a;text-transform:uppercase;font-weight:700;letter-spacing:.08em;margin-bottom:2px">Contatos</div>
              <div style="font-size:1.4rem;font-weight:800;color:#1a5c2a">${contacts.length}</div>
            </div>
            <div style="background:#f4f8f5;border:1px solid #c5dfca;border-radius:10px;padding:12px">
              <div style="font-size:.65rem;color:#1a5c2a;text-transform:uppercase;font-weight:700;letter-spacing:.08em;margin-bottom:2px">Tempo estimado</div>
              <div style="font-size:1.4rem;font-weight:800;color:#1a5c2a">${timeStr}</div>
            </div>
          </div>

          <div style="background:#fbfaf7;border:1px solid var(--borda);border-radius:10px;padding:12px 14px;margin-bottom:14px">
            <div style="font-size:.7rem;color:var(--cinza);text-transform:uppercase;font-weight:700;letter-spacing:.08em;margin-bottom:6px">Mensagem ${variations.length > 1 ? `(${variations.length} variações em rodízio)` : ''}</div>
            <div style="font-size:.85rem;color:var(--texto);white-space:pre-wrap;max-height:120px;overflow-y:auto;line-height:1.5">${esc(previewMsg)}</div>
            ${imgUrl ? `<div style="margin-top:8px;font-size:.75rem;color:var(--ouro);font-weight:600">📎 Com imagem anexada</div>` : ''}
          </div>

          <div style="display:flex;justify-content:space-between;font-size:.78rem;color:var(--cinza);margin-bottom:14px;padding:0 4px">
            <span>⏱ Intervalo: <strong>${delay}s</strong></span>
            <span>📋 Total: <strong>${contacts.length}</strong></span>
          </div>
          ${schedLabel ? `
            <div style="background:#fffbf0;border:1px solid #c8a96e44;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem;color:#8a6f3a;display:flex;align-items:center;gap:8px">
              <svg viewBox="0 0 24 24" fill="none" stroke="#c8a96e" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <div><strong>Agendado para:</strong> ${schedLabel}</div>
            </div>
          ` : ''}

          ${warningLevel !== 'low' ? `
            <div style="background:${warningLevel==='high'?'#fef2f2':'#fffbeb'};border-left:3px solid ${warningLevel==='high'?'#c62828':'#c8a96e'};padding:10px 12px;border-radius:6px;font-size:.8rem;color:${warningLevel==='high'?'#7a1f1f':'#7a6030'};margin-bottom:14px">
              ${warningLevel==='high'
                ? `⚠️ <strong>Atenção:</strong> ${contacts.length} disparos pode exceder o limite recomendado (${recommended}/dia). Risco de bloqueio.`
                : `💡 <strong>Dica:</strong> Você está disparando ${Math.round(contacts.length/recommended*100)}% do recomendado diário (${recommended}/dia).`
              }
            </div>
          ` : ''}
        </div>
        <div style="padding:16px 24px;border-top:1px solid var(--borda);display:flex;gap:10px;justify-content:flex-end;background:#fafaf8;border-radius:0 0 14px 14px">
          <button class="btn btn-outline" id="cd-cancel">Cancelar</button>
          <button class="btn btn-primary" id="cd-confirm">
            ${schedLabel
              ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Confirmar agendamento'
              : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg> Confirmar e disparar'}
          </button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    const close = (val) => { modal.remove(); resolve(val); };
    document.getElementById('cd-cancel').onclick  = () => close(false);
    document.getElementById('cd-confirm').onclick = () => close(true);
    modal.onclick = (e) => { if (e.target === modal) close(false); };
  });
}

/* ── Configurações anti-bloqueio ─────────────────────────── */
const SAFETY_PRESETS = {
  normal:   { min: 30,  max: 60,  unit: 1  },   // 30–60s
  safe:     { min: 2,   max: 3,   unit: 60 },   // 2–3 min
  cautious: { min: 5,   max: 10,  unit: 60 },   // 5–10 min
};

function updateSafetyMode() {
  const mode = document.getElementById('dispatch-safety')?.value;
  const preset = SAFETY_PRESETS[mode];
  if (!preset) return; // custom: deixa o campo livre
  const unitSel = document.getElementById('delay-unit');
  if (unitSel) unitSel.value = String(preset.unit);
  document.getElementById('delay-secs').value = preset.min;
  updateDelayHint();
}

function updateDelayHint() {
  const val  = parseFloat(document.getElementById('delay-secs')?.value || '2');
  const unit = parseInt(document.getElementById('delay-unit')?.value   || '1');
  const secs = val * unit;
  const min  = Math.round(secs * 0.6);
  const max  = Math.round(secs * 1.4);
  const fmt = (s) => s >= 60 ? `${Math.round(s/60)}min` : `${s}s`;
  const hint = document.getElementById('delay-hint');
  if (hint) hint.textContent = `≈ ${fmt(min)}–${fmt(max)} entre cada mensagem (randomizado ±40% + delayTyping)`;
  // Avisa se delay muito baixo
  if (secs < 5) hint && (hint.style.color = 'var(--error)');
  else hint && (hint.style.color = '');
}

function updateVariationAlert() {
  const count = document.querySelectorAll('.variation-text').length;
  const el = document.getElementById('variation-alert-inline');
  if (el) el.style.display = count < 3 ? '' : 'none';
}

function batchModeToggle() {
  const on = document.getElementById('batch-mode')?.checked;
  document.getElementById('tab-content-now').style.display   = on ? 'none' : '';
  document.getElementById('tab-content-sched').style.display = 'none';
  document.getElementById('tab-content-batch').style.display = on ? '' : 'none';
  const tabNow   = document.getElementById('tab-now-label');
  const tabSched = document.getElementById('tab-sched-label');
  const tabBatch = document.getElementById('tab-batch-label');
  if (tabBatch) { tabBatch.style.color = on ? 'var(--verde)' : 'var(--cinza)'; tabBatch.style.background = on ? 'var(--branco)' : 'var(--creme)'; tabBatch.style.fontWeight = on ? '700' : '600'; }
  if (tabNow)   { tabNow.style.color   = on ? 'var(--cinza)' : 'var(--verde)'; tabNow.style.background   = on ? 'var(--creme)' : 'var(--branco)'; }
  if (tabSched) { tabSched.style.color = 'var(--cinza)'; tabSched.style.background = 'var(--creme)'; }
  if (!on) { const r = document.querySelector('input[name="dispatch-mode"][value="now"]'); if (r) r.checked = true; }
  const label = document.getElementById('btn-send-label');
  if (label) label.textContent = on ? 'Criar Lotes' : 'Iniciar Disparo';
  if (on) updateBatchPreview();
}

function updateBatchPreview() {
  const n         = parseInt(document.getElementById('batch-count')?.value || '5');
  const intervalH = parseFloat(document.getElementById('batch-interval-h')?.value || '2.5');
  const startTime = document.getElementById('batch-start-time')?.value || '09:00';
  const preview   = document.getElementById('batch-preview');
  if (!preview) return;

  const [h, m] = startTime.split(':').map(Number);
  const base   = new Date(); base.setHours(h, m, 0, 0);

  let html = `📋 <strong>${n} lotes</strong> · intervalo de <strong>${intervalH}h</strong>:<br>`;
  let hasNight = false;
  for (let i = 0; i < n; i++) {
    const t    = new Date(base.getTime() + i * intervalH * 3600000);
    const hh   = t.getHours();
    const lbl  = t.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
    const warn = (hh >= 22 || hh < 7) ? ' ⚠️' : '';
    if (warn) hasNight = true;
    html += `&nbsp;&nbsp;${i+1}. ${lbl}${warn}`;
    if (i < n-1) html += ' ·';
  }
  if (hasNight) html += `<br><span style="color:var(--error);font-size:.72rem">⚠️ Evite horários entre 22h e 7h — maior risco de bloqueio.</span>`;
  html += `<br><span style="opacity:.75;font-size:.72rem">Contatos divididos igualmente. Recomendado: máx. 200–300 mensagens/dia no total.</span>`;
  preview.innerHTML = html;
}

// Observa adição de variações para atualizar o alerta
const _varObserver = new MutationObserver(updateVariationAlert);
document.addEventListener('DOMContentLoaded', () => {
  const container = document.querySelector('.variations-container');
  if (container) _varObserver.observe(container, { childList: true });
  updateDelayHint();
  updateBatchPreview();
  updateVariationAlert();
});

/* ── Agendamento de disparo ─────────────────────────────── */
function schedToggle() {
  const mode    = document.querySelector('input[name="dispatch-mode"]:checked')?.value || 'now';
  const isNow   = mode === 'now';
  const isSched = mode === 'scheduled';
  document.getElementById('tab-content-now').style.display   = isNow   ? '' : 'none';
  document.getElementById('tab-content-sched').style.display = isSched ? '' : 'none';
  document.getElementById('tab-content-batch').style.display = 'none';
  const tabNow   = document.getElementById('tab-now-label');
  const tabSched = document.getElementById('tab-sched-label');
  const tabBatch = document.getElementById('tab-batch-label');
  if (tabNow)   { tabNow.style.color   = isNow   ? 'var(--verde)' : 'var(--cinza)'; tabNow.style.background   = isNow   ? 'var(--branco)' : 'var(--creme)'; }
  if (tabSched) { tabSched.style.color = isSched ? 'var(--verde)' : 'var(--cinza)'; tabSched.style.background = isSched ? 'var(--branco)' : 'var(--creme)'; }
  if (tabBatch) { tabBatch.style.color = 'var(--cinza)'; tabBatch.style.background = 'var(--creme)'; }
  const bm = document.getElementById('batch-mode'); if (bm) bm.checked = false;
  const label = document.getElementById('btn-send-label');
  if (label) label.textContent = isSched ? 'Agendar Disparo' : 'Iniciar Disparo';
  if (isSched) {
    const dt = document.getElementById('sched-datetime');
    if (dt && !dt.value) {
      const t = new Date(); t.setDate(t.getDate()+1); t.setHours(9,0,0,0);
      dt.value = t.toISOString().slice(0,16);
      schedUpdateHint();
    }
  }
}

function schedUpdateHint() {
  const dt   = document.getElementById('sched-datetime')?.value;
  const hint = document.getElementById('sched-hint');
  if (!hint) return;
  if (!dt) { hint.textContent = ''; return; }
  const d = new Date(dt);
  const now = new Date();
  if (d <= now) {
    hint.innerHTML = '⚠️ Horário já passou. Escolha um horário futuro.';
    hint.style.color = 'var(--error)';
    return;
  }
  const diffMs = d - now;
  const diffH  = Math.floor(diffMs / 36e5);
  const diffM  = Math.floor((diffMs % 36e5) / 6e4);
  const label  = diffH > 0 ? `em ${diffH}h ${diffM}min` : `em ${diffM} minutos`;
  hint.innerHTML = `🕐 Disparo programado para ${d.toLocaleString('pt-BR', {weekday:'short',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})} (${label})`;
  hint.style.color = 'var(--ouro, #c8a96e)';
}

async function startDisparo() {
  // Recalcula contatos do zero a partir do estado atual do formulário
  const manualNow = document.getElementById('manual-phones').value
    .split('\n').map(l => l.replace(/\D/g,'')).filter(p => p.length >= 10)
    .map(p => ({phone:p, nome:'', empresa:'', cidade:''}));

  const seen = new Set();
  const finalContacts = [...csvContacts, ...manualNow].filter(c => {
    if (seen.has(c.phone)) return false;
    seen.add(c.phone); return true;
  });
  contacts = finalContacts;
  document.getElementById('contacts-num').textContent = contacts.length;

  const jobName    = document.getElementById('job-name').value.trim();
  const variations = getVariations();
  const unit       = parseInt(document.getElementById('delay-unit')?.value || '1');
  const delay      = Math.max(1, Math.round(parseFloat(document.getElementById('delay-secs').value) * unit) || 8);
  imgUrl           = document.getElementById('img-url').value;

  if (!jobName)           return alert('Informe o nome do disparo.');
  if (!variations.length) return alert('Escreva pelo menos uma mensagem.');
  if (!contacts.length)   return alert('Adicione pelo menos 1 contato.');

  // Checa limite diário
  try {
    const dr = await fetch('dashboard.php', {credentials:'same-origin'});
    const dd = await dr.json();
    const sentToday = dd.metrics?.sent_today || 0;
    const isBusiness = window._cfgIsBusiness === true;
    const limit = window._cfgDailyLimit || (isBusiness ? 1000 : 500);
    if (sentToday + contacts.length > limit) {
      const remaining = Math.max(0, limit - sentToday);
      const warn = `⚠️ LIMITE DIÁRIO\n\nVocê já disparou ${sentToday} mensagens hoje.\nLimite recomendado: ${limit}/dia\nRestam: ${remaining} disparos\n\nVocê está prestes a disparar +${contacts.length}, total: ${sentToday + contacts.length}.\n\nIsso aumenta MUITO o risco de bloqueio do número.\n\nDeseja continuar mesmo assim?`;
      if (!confirm(warn)) return;
    }
  } catch(e) { /* se falhar a checagem, deixa passar */ }

  // Aviso de lista grande
  if (contacts.length > 100) {
    if (!confirm(`Você tem ${contacts.length} contatos. Recomendamos até 100 por disparo para evitar bloqueios.\n\nContinuar mesmo assim?`)) return;
  }

  // Mostrar modal de confirmação detalhado
  // ── MODO LOTES ──────────────────────────────────────────
  const batchOn = document.getElementById('batch-mode')?.checked;
  if (batchOn) {
    const n         = parseInt(document.getElementById('batch-count')?.value || '4');
    const intervalH = parseFloat(document.getElementById('batch-interval-h')?.value || '2');
    const startTime = document.getElementById('batch-start-time')?.value || '09:00';

    if (!confirm(`Criar ${n} lotes de ≈${Math.ceil(contacts.length/n)} contatos cada, iniciando às ${startTime}?\n\nTotal: ${contacts.length} contatos em ${n} disparos agendados.`)) return;

    const [h, m] = startTime.split(':').map(Number);
    const baseDate = new Date(); baseDate.setHours(h, m, 0, 0);
    if (baseDate <= new Date()) baseDate.setDate(baseDate.getDate() + 1); // amanhã se já passou

    const chunkSize = Math.ceil(contacts.length / n);
    let created = 0;
    document.getElementById('btn-send').innerHTML = '⏳ Criando lotes…';
    document.getElementById('btn-send').disabled = true;

    for (let i = 0; i < n; i++) {
      const chunk = contacts.slice(i * chunkSize, (i + 1) * chunkSize);
      if (!chunk.length) break;
      const schedAt = new Date(baseDate.getTime() + i * intervalH * 3600000);
      const batchName = `${jobName} — Lote ${i+1}/${n}`;
      try {
        const r = await fetch('queue.php?action=create', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ job_name: batchName, variations, image_url: imgUrl||null, contacts: chunk, delay_secs: delay, scheduled_at: schedAt.toISOString() })
        });
        const d = await r.json();
        if (d.ok) { created++; addLog('info', `📅 Lote ${i+1}: ${chunk.length} contatos → ${schedAt.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})}`); }
        else addLog('warn', `❌ Lote ${i+1} falhou: ${d.error||'?'}`);
      } catch(e) { addLog('warn', `❌ Lote ${i+1}: erro de rede`); }
    }

    document.getElementById('send-progress').style.display = 'block';
    document.getElementById('btn-send').innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> <span id="btn-send-label">Iniciar Disparo</span>';
    document.getElementById('btn-send').disabled = false;
    addLog('info', `✅ ${created} lotes agendados! Acesse o Histórico para ver o cronograma.`);
    toast(`✅ ${created} lotes agendados com sucesso!`, 'success');
    return; // não vai para o fluxo normal
  }

  // ── MODO SINGLE (normal ou agendado) ────────────────────
  const schedMode = document.querySelector('input[name="dispatch-mode"]:checked')?.value || 'now';
  let scheduledAt = null;
  if (schedMode === 'scheduled') {
    const dtVal = document.getElementById('sched-datetime')?.value;
    if (!dtVal) { alert('Escolha a data e hora para o agendamento.'); return; }
    const dtObj = new Date(dtVal);
    if (dtObj <= new Date()) { alert('O horário do agendamento precisa ser no futuro.'); return; }
    scheduledAt = dtObj.toISOString();
  }

  const ok = await showConfirmDispatch({
    jobName, contacts, variations, delay, imgUrl, scheduledAt
  });
  if (!ok) return;

  document.getElementById('btn-send').style.display   = 'none';
  document.getElementById('send-progress').style.display = 'block';
  document.getElementById('progress-log').innerHTML   = '';
  document.getElementById('progress-fill').style.width = '0%';

  // Se agendado: não mostra pause/stop (job não está rodando ainda)
  if (!scheduledAt) {
    document.getElementById('btn-pause').style.display  = '';
    document.getElementById('btn-stop').style.display   = '';
    document.getElementById('btn-resume').style.display = 'none';
  }

  try {
    const r = await fetch('queue.php?action=create', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ job_name: jobName, variations, image_url: imgUrl || null, contacts, delay_secs: delay, scheduled_at: scheduledAt })
    });
    const d = await r.json();
    if (!d.ok) { alert('Erro ao criar disparo: ' + (d.error||'?')); resetDisparoUI(); return; }
    currentJobId = d.job_id;

    if (scheduledAt) {
      // Agendado: mostra confirmação e não faz poll imediato
      const dtLabel = new Date(scheduledAt).toLocaleString('pt-BR', {weekday:'long',day:'2-digit',month:'long',hour:'2-digit',minute:'2-digit'});
      addLog('info', `📅 Disparo <strong>#${currentJobId}</strong> agendado para <strong>${dtLabel}</strong>.`);
      addLog('info', `✅ O servidor iniciará automaticamente no horário programado. Você pode fechar o browser.`);
      addLog('info', `💡 O disparo aparecerá no Histórico com status "Agendado".`);
      // Reseta o form para evitar duplo-agendamento
      document.querySelector('input[name="dispatch-mode"][value="now"]').checked = true;
      schedToggle();
    } else {
      addLog('info', `🚀 Disparo enviado ao servidor (job #${currentJobId}). ${d.total} contatos na fila.`);
      addLog('info', `⚡ O disparo continua mesmo se fechar o browser.`);
      // Aciona o worker imediatamente
      fetch('worker.php?trigger=1', {method:'GET', cache:'no-store'}).catch(()=>{});
      startPoll();
    }
  } catch(e) {
    alert('Erro de rede: ' + e.message); resetDisparoUI();
  }
}

function startPoll() {
  if (pollInterval) clearInterval(pollInterval);
  pollInterval = setInterval(pollJob, 3000);
}

let lastLogCount = 0;

async function pollJob() {
  if (!currentJobId) return;
  try {
    // Status do job
    const r = await fetch(`queue.php?action=status&job_id=${currentJobId}`);
    const d = await r.json();
    if (!d.ok) return;
    const job = d.job;
    updateProgress(job.current_index, job.total, job.sent, job.errors);

    // Busca log do worker e exibe linhas novas
    const lr = await fetch(`queue.php?action=log&lines=60`);
    const ld = await lr.json();
    if (ld.ok && ld.lines) {
      const logEl = document.getElementById('progress-log');
      // Filtra só as linhas relevantes ao job atual e novas
      const relevant = ld.lines.filter(l =>
        l.includes(`Job #${currentJobId}`) || l.includes('✅') || l.includes('❌') || l.includes('🏁')
      );
      if (relevant.length > lastLogCount) {
        const newLines = relevant.slice(lastLogCount);
        newLines.forEach(l => {
          const div = document.createElement('div');
          div.className = 'log-line ' + (l.includes('✅') ? 'ok' : l.includes('❌') ? 'err' : 'info');
          // Remove o timestamp [2026-05-04 15:27:01] do início
          div.textContent = l.replace(/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] /, '');
          logEl.appendChild(div);
        });
        logEl.scrollTop = logEl.scrollHeight;
        lastLogCount = relevant.length;
      }
    }

    if (job.status === 'done' || job.status === 'cancelled') {
      clearInterval(pollInterval); pollInterval = null;
      addLog('info', `🏁 Finalizado: ${job.sent} enviados, ${job.errors} erros.`);
      resetDisparoUI();
      await crmImportFromDisparo([], job.job_name);
      currentJobId = null; lastLogCount = 0;
    } else if (job.status === 'paused') {
      document.getElementById('btn-pause').style.display  = 'none';
      document.getElementById('btn-resume').style.display = '';
      document.getElementById('progress-fill').classList.add('progress-paused');
    } else if (job.status === 'running') {
      document.getElementById('btn-pause').style.display  = '';
      document.getElementById('btn-resume').style.display = 'none';
      document.getElementById('progress-fill').classList.remove('progress-paused');
    }
  } catch(e) {}
}

async function pauseDisparo() {
  if (!currentJobId) return;
  await fetch('queue.php?action=pause', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({job_id:currentJobId}) });
  document.getElementById('btn-pause').style.display  = 'none';
  document.getElementById('btn-resume').style.display = '';
  addLog('info','⏸ Pausando… (aguarda próxima verificação do servidor)');
}
async function resumeDisparo() {
  if (!currentJobId) return;
  await fetch('queue.php?action=resume', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({job_id:currentJobId}) });
  document.getElementById('btn-resume').style.display = 'none';
  document.getElementById('btn-pause').style.display  = '';
  document.getElementById('progress-fill').classList.remove('progress-paused');
  addLog('info','▶️ Retomando…');
}
async function stopDisparo() {
  if (!currentJobId) return;
  if (!confirm('Parar o disparo definitivamente?')) return;
  await fetch('queue.php?action=cancel', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({job_id:currentJobId}) });
  clearInterval(pollInterval); pollInterval = null;
  addLog('info','⛔ Disparo cancelado.');
  resetDisparoUI(); currentJobId = null;
}
function resetDisparoUI() {
  document.getElementById('btn-send').style.display   = '';
  document.getElementById('btn-pause').style.display  = 'none';
  document.getElementById('btn-resume').style.display = 'none';
  document.getElementById('btn-stop').style.display   = 'none';
}
function updateProgress(curr, total, sent, errors) {
  const pct = total > 0 ? (curr / total) * 100 : 0;
  document.getElementById('progress-fill').style.width = pct + '%';
  document.getElementById('progress-text').textContent =
    `${curr} / ${total} processados — ✓ ${sent} enviados · ✕ ${errors} erros`;
}
function addLog(type, msg) {
  const log = document.getElementById('progress-log');
  const div = document.createElement('div');
  div.className = 'log-line ' + type;
  div.textContent = msg;
  log.appendChild(div);
  log.scrollTop = log.scrollHeight;
}
async function saveHistorico(jobName, message, imageUrl, total, sent, errors) {
  try {
    await fetch('historico.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        job_name: jobName, message_text: message,
        image_url: imageUrl || null,
        total, sent, errors,
        started_at: new Date().toISOString().slice(0,19).replace('T',' '),
        contacts: disparoLog
      })
    });
  } catch(e) {}
}

async function crmImportFromDisparo(log, source) {
  if (!log.length) return;
  const contacts = log.map(c => ({ phone: c.phone, source }));
  try {
    await fetch('crm.php?action=import', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ contacts })
    });
  } catch(e) { /* silencia */ }
}

/* ── HISTÓRICO ──────────────────────────── */
async function loadHistorico() {
  const wrap = document.getElementById('historico-wrap');
  wrap.innerHTML = '<em style="color:var(--cinza)">Carregando…</em>';

  // Atualiza painel de status da fila
  try {
    const ar = await fetch('historico.php?action=active', {credentials:'same-origin'});
    const ad = await ar.json();
    const panel = document.getElementById('queue-status-panel');
    if (ad.ok && ad.jobs?.length && panel) {
      const j = ad.jobs[0];
      window._activeQueueJobId = j.id;
      panel.style.display = 'block';
      const labels = {running:'⏳ Em andamento', paused:'⏸ Pausado', pending:'🕐 Aguardando início', scheduled:'📅 Agendado'};
      document.getElementById('queue-status-title').textContent = `${labels[j.status]||j.status} — "${j.job_name}" (#${j.id})`;
      const pct = j.total > 0 ? Math.round(j.sent / j.total * 100) : 0;
      document.getElementById('queue-progress-bar').style.width = pct + '%';
      document.getElementById('queue-progress-text').textContent = `${j.sent} de ${j.total} enviados · ${j.errors||0} erros · ${pct}%`;
      document.getElementById('queue-status-detail').textContent = 
        j.status === 'paused' ? `Pausado. Clique em Retomar para continuar de onde parou.` :
        j.status === 'running' ? `Disparando agora. Atualize a página em alguns segundos para ver o progresso.` :
        `Aguardando o worker iniciar.`;
      document.getElementById('queue-resume-btn').style.display = j.status === 'paused' ? 'inline-flex' : 'none';
    } else if (panel) {
      panel.style.display = 'none';
      window._activeQueueJobId = null;
    }
  } catch(e) {}

  try {
    const r = await fetch('historico.php?page=1', {credentials:'same-origin'});
    const d = await r.json();
    if (!d.ok || !d.jobs?.length) {
      wrap.innerHTML = `<div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <h4>Nenhum disparo registrado</h4></div>`;
      return;
    }

    const statusBadge = {
      done:      `<span class="badge ok">✅ Concluído</span>`,
      running:   `<span class="badge" style="background:#e8f4e8;color:#1a5c2a;animation:pulse 1.5s infinite">⏳ Em andamento</span>`,
      paused:    `<span class="badge" style="background:#fff3cd;color:#856404">⏸ Pausado</span>`,
      pending:   `<span class="badge zero">🕐 Aguardando</span>`,
      scheduled: `<span class="badge-scheduled"><svg viewBox="0 0 24 24" fill="none" stroke="#c8a96e" stroke-width="2" width="11" height="11"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Agendado</span>`,
      cancelled: `<span class="badge" style="background:#fce8e8;color:#c62828">✕ Cancelado</span>`,
      '':        `<span class="badge ok">✅ Concluído</span>`,
    };

    // Função para calcular countdown de agendado
    function schedCountdown(scheduledAt) {
      if (!scheduledAt) return '';
      const d = new Date(scheduledAt);
      const now = new Date();
      if (d <= now) return ' (em instantes)';
      const diffMs = d - now;
      const diffH  = Math.floor(diffMs / 36e5);
      const diffM  = Math.floor((diffMs % 36e5) / 6e4);
      if (diffH >= 24) { const days = Math.floor(diffH/24); return ` (em ${days} dia${days>1?'s':''})` }
      if (diffH > 0) return ` (em ${diffH}h ${diffM}min)`;
      return ` (em ${diffM} min)`;
    }

    let html = `<div style="overflow-x:auto;-webkit-overflow-scrolling:touch"><table class="history-table">
      <thead><tr><th>#</th><th>Campanha</th><th>Status</th><th>Progresso</th><th>Data</th><th></th></tr></thead><tbody>`;

    d.jobs.forEach(j => {
      const pct  = j.total > 0 ? Math.round(j.sent / j.total * 100) : 0;
      const dt   = (j.started_at||j.scheduled_at||'—').replace('T',' ').slice(0,16);
      const st   = j._status || j.queue_status || '';
      const isActive = ['running','paused','pending','scheduled'].includes(st);
      const isScheduled = st === 'scheduled';

      const queued = j.queued ?? Math.max(0, (j.total||0) - (j.sent||0) - (j.errors||0));
      const progressBar = `<div style="min-width:140px">
        <div style="background:#eee;border-radius:4px;height:6px;margin-bottom:4px;overflow:hidden;display:flex">
          <div style="background:var(--verde);height:100%;width:${pct}%;border-radius:4px 0 0 4px;transition:width .3s"></div>
          ${j.errors>0?`<div style="background:var(--error);height:100%;width:${j.total>0?Math.round(j.errors/j.total*100):0}%;transition:width .3s"></div>`:''}
        </div>
        <div style="font-size:.71rem;color:var(--cinza);display:flex;gap:8px;flex-wrap:wrap">
          <span style="color:var(--success)">✓ ${j.sent} enviados</span>
          ${j.errors>0?`<span style="color:var(--error)">✕ ${j.errors} erros</span>`:''}
          ${queued>0?`<span style="color:#b45309">⏳ ${queued} em fila</span>`:''}
        </div>
      </div>`;

      const actions = isActive
        ? `<div style="display:flex;gap:4px">
            ${st==='paused'?`<button class="btn btn-outline" style="font-size:.72rem;padding:4px 8px;color:var(--verde)" onclick="event.stopPropagation();resumeJob(${j.id})">▶ Retomar</button>`:''}
            <button class="btn btn-ghost" style="font-size:.72rem;padding:4px 8px;color:var(--cinza)" onclick="event.stopPropagation();openHistoricoPopup(${j.id})">Detalhes</button>
            <button class="btn btn-ghost" style="font-size:.72rem;padding:4px 8px;color:var(--error)" onclick="event.stopPropagation();cancelJob(${j.id})">✕ Cancelar</button>
           </div>`
        : `<button class="btn btn-ghost" style="font-size:.78rem;padding:5px 10px" onclick="openHistoricoPopup(${j.id})">Ver →</button>`;

      // Data: scheduled mostra quando vai disparar; outros mostram started_at
      const dtLabel = isScheduled && j.scheduled_at
        ? `<span style="color:var(--ouro,#c8a96e);font-size:.75rem">📅 ${j.scheduled_at.slice(0,16).replace('T',' ')}${schedCountdown(j.scheduled_at)}</span>`
        : `<span>${dt}</span>`;

      html += `<tr style="cursor:pointer;${isActive?'background:#fffdf5;':''}" onclick="openHistoricoPopup(${j.id})">
        <td class="date-mono" style="color:var(--cinza)">${j.id}</td>
        <td><b style="color:var(--verde-800)">${esc(j.job_name)}</b></td>
        <td>${statusBadge[st] || statusBadge['']}</td>
        <td>${isScheduled ? `<div style="font-size:.78rem;color:var(--cinza)">${j.total} contatos agendados</div>` : progressBar}</td>
        <td class="date-mono" style="white-space:nowrap;font-size:.75rem">${dtLabel}</td>
        <td>${actions}</td>
      </tr>`;
    });

    html += `</tbody></table></div>
      <div style="margin-top:12px;font-size:.78rem;color:var(--cinza)">${d.total} disparos no total</div>`;
    wrap.innerHTML = html;
  } catch(e) {
    wrap.innerHTML = '<em style="color:var(--error)">Erro ao carregar histórico.</em>';
  }
}

async function resumeQueueJob() {
  if (!window._activeQueueJobId) return;
  await resumeJob(window._activeQueueJobId);
  setTimeout(() => forceWorker(), 500);
}

async function cancelQueueJob() {
  if (!window._activeQueueJobId) return;
  if (!confirm('Cancelar o disparo em andamento? Os contatos ainda não enviados ficarão na fila como cancelados.')) return;
  await cancelJob(window._activeQueueJobId);
}

async function forceWorker() {
  toast('🚀 Acionando worker manualmente...', 'info');
  try {
    fetch('worker.php?trigger=force&t=' + Date.now(), {credentials:'same-origin'}).catch(()=>{});
    setTimeout(() => {
      loadHistorico();
      toast('Worker acionado! Aguarde alguns segundos e atualize.', 'success');
    }, 2000);
  } catch(e) { toast('Erro: ' + e.message, 'error'); }
}

async function resumeJob(id) {
  try {
    const r = await fetch('historico.php?action=resume', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.ok) { toast('Disparo retomado!', 'success'); loadHistorico(); }
    else toast('Erro ao retomar: ' + (d.error||''), 'error');
  } catch(e) { toast('Erro de rede.', 'error'); }
}

async function cancelJob(id) {
  if (!confirm('Cancelar este disparo?')) return;
  try {
    const r = await fetch('historico.php?action=cancel', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.ok) { toast('Disparo cancelado.', 'success'); loadHistorico(); }
    else toast('Erro: ' + (d.error||''), 'error');
  } catch(e) { toast('Erro de rede.', 'error'); }
}

async function openHistoricoPopup(jobId) {
  const modal = document.getElementById('hist-popup');
  const body  = document.getElementById('hist-popup-body');
  modal.style.display = 'flex';
  body.innerHTML = '<em style="color:var(--cinza)">Carregando…</em>';
  try {
    const r = await fetch(`historico.php?job_id=${jobId}`);
    const d = await r.json();
    if (!d.ok) { body.innerHTML = '<em style="color:var(--error)">Erro ao carregar.</em>'; return; }
    const j = d.job;
    const pct = j.total > 0 ? Math.round(j.sent/j.total*100) : 0;
    const sentList   = (d.contacts||[]).filter(c=>c.status==='sent');
    const errList    = (d.contacts||[]).filter(c=>c.status==='error');
    const queuedList = (d.contacts||[]).filter(c=>c.status==='queued');
    const queuedCount = (j.queued != null ? j.queued : queuedList.length);

    const totalPop = j.total || (sentList.length + errList.length + queuedList.length) || 1;
    const sentPct  = Math.round(sentList.length   / totalPop * 100);
    const errPct   = Math.round(errList.length    / totalPop * 100);
    const qPct     = Math.round(queuedList.length / totalPop * 100);

    body.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
        <div>
          <div style="font-size:.68rem;font-weight:700;color:var(--cinza);text-transform:uppercase;letter-spacing:.1em;margin-bottom:6px">Campanha</div>
          <div style="font-weight:700;font-size:1.05rem;color:var(--verde-800)">${esc(j.job_name)}</div>
          <div style="font-size:.8rem;color:var(--cinza);margin-top:4px;font-family:var(--font-mono)">${(j.started_at||'').slice(0,16)}</div>

          <div style="margin-top:14px">
            <div style="background:#eee;border-radius:6px;height:8px;overflow:hidden;display:flex;margin-bottom:8px">
              <div style="background:var(--success);width:${sentPct}%;transition:width .3s" title="${sentList.length} enviados"></div>
              <div style="background:var(--error);width:${errPct}%;transition:width .3s" title="${errList.length} erros"></div>
              <div style="background:#f59e0b;width:${qPct}%;transition:width .3s" title="${queuedList.length} em fila"></div>
            </div>
            <div style="display:flex;gap:14px;flex-wrap:wrap">
              <div style="text-align:center">
                <div style="font-size:1.35rem;font-weight:800;color:var(--success)">${j.sent}</div>
                <div style="font-size:.68rem;color:var(--cinza)">enviados</div>
              </div>
              <div style="text-align:center">
                <div style="font-size:1.35rem;font-weight:800;color:var(--error)">${j.errors}</div>
                <div style="font-size:.68rem;color:var(--cinza)">erros</div>
              </div>
              ${queuedCount > 0 ? `
              <div style="text-align:center">
                <div style="font-size:1.35rem;font-weight:800;color:#b45309">${queuedCount}</div>
                <div style="font-size:.68rem;color:var(--cinza)">em fila</div>
              </div>` : ''}
              <div style="text-align:center">
                <div style="font-size:1.35rem;font-weight:800;color:var(--azul)">${pct}%</div>
                <div style="font-size:.68rem;color:var(--cinza)">taxa envio</div>
              </div>
            </div>
          </div>

          ${queuedCount > 0 ? `
          <div style="margin-top:12px;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:10px 12px;font-size:.78rem;color:#92400e;line-height:1.5">
            ⚠️ <b>${queuedCount} contatos não foram enviados</b> — ficaram na fila quando o job encerrou.<br>
            Eles NÃO receberão a mensagem automaticamente. Crie um novo disparo se necessário.
          </div>` : ''}
        </div>
        <div>
          ${j.image_url ? `<img src="${esc(j.image_url)}" style="max-width:100%;max-height:160px;border-radius:10px;object-fit:contain;border:1px solid var(--borda)">` : '<div style="color:var(--cinza);font-size:.82rem">Sem imagem</div>'}
        </div>
      </div>
      <div style="margin-bottom:14px">
        <div style="font-size:.68rem;font-weight:700;color:var(--cinza);text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px">Mensagem enviada</div>
        <div style="background:var(--creme-2);border:1px solid var(--borda);border-radius:10px;padding:14px;font-size:.88rem;white-space:pre-wrap;color:var(--texto);line-height:1.6;max-height:180px;overflow-y:auto">${esc(j.message_text||'—')}</div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr${queuedList.length > 0 ? ' 1fr' : ''};gap:14px">
        <div>
          <div style="font-size:.68rem;font-weight:700;color:var(--success);text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px">✓ Enviados (${sentList.length})</div>
          <div style="max-height:160px;overflow-y:auto;font-family:var(--font-mono);font-size:.76rem;background:var(--verde-100);border-radius:8px;padding:10px;color:var(--success)">
            ${sentList.map(c=>`${c.phone}`).join('<br>') || '—'}
          </div>
        </div>
        <div>
          <div style="font-size:.68rem;font-weight:700;color:var(--error);text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px">✕ Erros (${errList.length})</div>
          <div style="max-height:160px;overflow-y:auto;font-family:var(--font-mono);font-size:.76rem;background:#fdecea;border-radius:8px;padding:10px;color:var(--error)">
            ${errList.map(c=>`${c.phone}${c.error_msg?` — ${c.error_msg}`:''}`).join('<br>') || '—'}
          </div>
        </div>
        ${queuedList.length > 0 ? `
        <div>
          <div style="font-size:.68rem;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px">⏳ Não enviados — em fila (${queuedList.length})</div>
          <div style="max-height:160px;overflow-y:auto;font-family:var(--font-mono);font-size:.76rem;background:#fffbeb;border-radius:8px;padding:10px;color:#92400e">
            ${queuedList.map(c=>`${c.phone}`).join('<br>') || '—'}
          </div>
        </div>` : ''}
      </div>`;
  } catch(e) {
    body.innerHTML = '<em style="color:var(--error)">Erro de rede.</em>';
  }
}
function closeHistoricoPopup() { document.getElementById('hist-popup').style.display='none'; }

/* ── TEMPLATES (servidor via settings.php) ── */
async function tplLoadAll() {
  try {
    const r = await fetch('settings.php?action=get', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) return {};
    const raw = d.settings?.templates;
    return raw ? JSON.parse(raw) : {};
  } catch { return {}; }
}
async function tplSaveAll(obj) {
  await fetch('settings.php?action=save', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ templates: JSON.stringify(obj) })
  });
}
async function tplRenderSelect() {
  const all = await tplLoadAll();
  const sel = document.getElementById('tpl-select');
  const keys = Object.keys(all);
  sel.innerHTML = '<option value="">— Selecionar —</option>' +
    keys.map(k => `<option value="${esc(k)}">${esc(k)}</option>`).join('');
}
async function tplSave() {
  const variations = getVariations();
  if (!variations.length) return alert('Escreva pelo menos uma mensagem antes de salvar.');
  const name = prompt('Nome do template:');
  if (!name?.trim()) return;
  const all = await tplLoadAll();
  all[name.trim()] = variations;
  await tplSaveAll(all);
  await tplRenderSelect();
  alert(`✅ Template "${name.trim()}" salvo no servidor!`);
}
async function tplLoad() {
  const sel = document.getElementById('tpl-select').value;
  if (!sel) return;
  const all = await tplLoadAll();
  const variations = all[sel];
  if (!variations?.length) return;
  document.getElementById('variations-wrap').innerHTML = '';
  varCount = 0;
  variations.forEach(text => { addVariation(); document.querySelectorAll('.variation-text').at(-1).value = text; });
}
async function tplDelete() {
  const sel = document.getElementById('tpl-select').value;
  if (!sel) return;
  if (!confirm(`Excluir template "${sel}"?`)) return;
  const all = await tplLoadAll();
  delete all[sel];
  await tplSaveAll(all);
  await tplRenderSelect();
}

/* ── VARIAÇÕES (rodízio) ─────────────────── */
let varCount = 1;
let lastFocusedVariation = null;
function addVariation() {
  varCount++;
  const wrap = document.getElementById('variations-wrap');
  const div = document.createElement('div');
  div.className = 'variation-item';
  div.innerHTML = `
    <div class="variation-num">${varCount}</div>
    <textarea class="variation-text" placeholder="Variação ${varCount}... use {{nome}}, {{empresa}}, {{cidade}}"></textarea>
    <button class="variation-del" title="Remover" onclick="removeVariation(this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>`;
  wrap.appendChild(div);
  div.querySelector('textarea').focus();
  attachVarFocus();
}
function removeVariation(btn) {
  if (document.querySelectorAll('.variation-item').length <= 1) return alert('Precisa ter pelo menos uma mensagem.');
  btn.closest('.variation-item').remove();
  document.querySelectorAll('.variation-item .variation-num').forEach((el,i) => el.textContent = i+1);
}
function attachVarFocus() {
  document.querySelectorAll('.variation-text').forEach(ta => {
    ta.addEventListener('focus', () => { lastFocusedVariation = ta; });
  });
}
function insertVar(token) {
  const ta = lastFocusedVariation || document.querySelector('.variation-text');
  if (!ta) return;
  const s = ta.selectionStart;
  ta.value = ta.value.slice(0,s) + token + ta.value.slice(ta.selectionEnd);
  ta.selectionStart = ta.selectionEnd = s + token.length;
  ta.focus();
}

/* ── ORGANIZADOR ─────────────────────────── */
let orgCompanies = [];
let orgCurrentTab = 'manual';
let orgDupesRemoved = 0;
let orgOriginalCount = 0;

function orgSwitchTab(tab, btn) {
  orgCurrentTab = tab;
  ['manual','txt','excel','vcard'].forEach(t => {
    const el = document.getElementById('org-tab-'+t);
    if (el) el.style.display = t === tab ? '' : 'none';
  });
  document.querySelectorAll('.org-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

function orgToggleSettings(btn) {
  const panel = document.getElementById('org-settings-panel');
  const arrow = document.getElementById('org-settings-arrow');
  panel.classList.toggle('hidden');
  arrow.style.transform = panel.classList.contains('hidden') ? '' : 'rotate(180deg)';
}

function orgProcess() {
  let input = '';
  if (orgCurrentTab === 'manual')  input = document.getElementById('org-input').value.trim();
  if (orgCurrentTab === 'vcard')   { alert('Use o seletor de arquivo .vcf acima — o processamento é automático.'); return; }
  if (orgCurrentTab === 'excel')   { alert('Use o seletor de arquivo Excel acima — o processamento é automático na importação.'); return; }
  if (orgCurrentTab === 'txt')     { alert('Use o seletor de arquivo .txt acima — o processamento é automático.'); return; }
  if (!input) { alert('Cole os dados primeiro.'); return; }
  orgParseText(input);
}

function orgImportTxt(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const reader = new FileReader();
  reader.onload = e => {
    const text = e.target.result;
    if (!text.trim()) { toast('Arquivo .txt vazio.', 'warn'); return; }
    orgParseText(text);
    toast(`Arquivo "${file.name}" importado com sucesso!`, 'success');
  };
  reader.onerror = () => toast('Erro ao ler o arquivo.', 'error');
  reader.readAsText(file, 'UTF-8');
}

function orgImportVcf(input) {
  if (!input.files.length) return;
  const results = [];
  let pending = input.files.length;
  Array.from(input.files).forEach(file => {
    const r = new FileReader();
    r.onload = e => {
      parseVcf(e.target.result, results);
      pending--;
      if (pending === 0) {
        // Deduplica e junta com existentes
        const seen = new Set(orgCompanies.map(c => c.phone?.replace(/\D/g,'')));
        results.forEach(c => {
          const p = c.phone?.replace(/\D/g,'');
          if (p && !seen.has(p)) { orgCompanies.push(c); seen.add(p); }
        });
        orgRenderResults();
      }
    };
    r.readAsText(file, 'UTF-8');
  });
}

function parseVcf(text, out) {
  // Unfold: linhas que começam com espaço/tab são continuação
  text = text.replace(/\r\n[ \t]/g, '').replace(/\n[ \t]/g, '');
  const cards = text.split(/END:VCARD/i).filter(c => c.includes('BEGIN:VCARD'));

  cards.forEach(card => {
    // Extrai campo com suporte a CHARSET e ENCODING=QUOTED-PRINTABLE
    function getField(rx) {
      const m = card.match(rx);
      if (!m) return '';
      let val = m[1].trim();
      // Decodifica Quoted-Printable se necessário
      if (/ENCODING=QUOTED-PRINTABLE/i.test(m[0]) || val.includes('=')) {
        val = decodeQP(val);
      }
      // Tenta decodificar UTF-8 de latin1 (caso comum em vcf de Android)
      try {
        const bytes = val.split('').map(c => c.charCodeAt(0));
        if (bytes.some(b => b > 127)) {
          const uint8 = new Uint8Array(bytes);
          val = new TextDecoder('utf-8').decode(uint8);
        }
      } catch(e) {}
      return val.replace(/\\n/g,' ').replace(/\\,/g,',').trim();
    }

    // Nome: prefere FN, fallback N
    let name = getField(/^FN[^:\r\n]*:(.+)$/mi);
    if (!name) {
      const nRaw = getField(/^N[;:\r\n][^:\r\n]*:(.+)$/mi);
      if (nRaw) {
        const parts = nRaw.split(';');
        name = [parts[1], parts[0]].filter(Boolean).join(' ').trim();
      }
    }

    // Telefones (pode ter múltiplos)
    const telMatches = [...card.matchAll(/^TEL[^:\r\n]*:(.+)$/gmi)];
    telMatches.forEach(m => {
      const raw = m[1].trim().replace(/[^\d+]/g,'');
      if (raw.replace(/\D/g,'').length >= 8) {
        out.push({ name: name || null, empresa: null, phone: raw });
      }
    });
  });
}

function orgParseText(text) {
  // Limpa resultados do extrator automático para evitar conflito
  if (extLeads && extLeads.length) {
    extLeads = [];
    const sec = document.getElementById('ext-results-section');
    if (sec) sec.style.display = 'none';
  }

  const phoneRx = /\(?\d{2}\)?\s?\d{4,5}-?\d{4}|\+\d{1,3}\s?\d{2}\s?\d{3,5}\s?\d{4}/g;

  // Linhas a ignorar completamente
  const ignoreRx = /^(Resultados|Compartilhar|Website|Rotas|nan|None|·|Agendamento on-line|Serviços no local|Serviços indisponíveis no local|Acessibilidade|Mais informações|Aberto|Fechado|Fecha|Abre|Sem avaliações|Avaliações|Reivindique|Adicione|Patrocinado)$/i;

  // Linhas que são metadados (rating, endereço, categoria, horário, site)
  const isMetaRx = /^[\d][.,]\d/; // rating: 4.5, 3,2…
  const isAddressRx = /\b(rua|av\.|avenida|alameda|travessa|praça|estrada|rod\.|rodovia|trav\.|r\.|al\.)\b/i;
  const isHourRx = /^\d{1,2}h|\b(seg|ter|qua|qui|sex|sáb|dom|aberto|fechado|abre|fecha)\b/i;
  const isSiteRx = /^https?:\/\/|^www\./i;
  const isCategoryRx = /^(restaurante|clínica|farmácia|supermercado|academia|loja|salão|barbearia|escritório|advogado|médico|dentista|imobiliária|construtora|serviços)/i;

  function isMeta(line) {
    return isMetaRx.test(line) || isAddressRx.test(line) || isHourRx.test(line) || isSiteRx.test(line);
  }

  const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
  const out = [];
  let i = 0;

  // Pega DDD padrão das configurações
  const defaultDDD = (document.getElementById('org-cfg-ddd')?.value || '').trim();

  while (i < lines.length) {
    const line = lines[i];

    // Ignora linhas de controle
    if (ignoreRx.test(line)) { i++; continue; }

    // Linha só de telefone (sem nome)
    const onlyPhone = line.match(phoneRx);
    if (onlyPhone && line.replace(phoneRx,'').replace(/[\s\(\)\-\+]/g,'').length === 0) {
      out.push({ empresa: null, name: null, phone: onlyPhone[0] });
      i++; continue;
    }

    // Linha com telefone embutido junto a texto
    const inlinePhone = line.match(phoneRx);
    if (inlinePhone) {
      const nome = line.replace(inlinePhone[0],'').replace(/[,;\-·]/g,'').trim() || null;
      if (nome && !isMeta(nome)) {
        out.push({ empresa: nome, name: null, phone: inlinePhone[0] });
      } else {
        out.push({ empresa: null, name: null, phone: inlinePhone[0] });
      }
      i++; continue;
    }

    // Linha com tab/vírgula: Empresa\tTelefone
    const parts = line.split(/[\t,;]/);
    if (parts.length >= 2) {
      const maybePhone   = parts.find(p => phoneRx.test(p));
      const maybeEmpresa = parts.find(p => !phoneRx.test(p) && p.length > 2);
      if (maybePhone) {
        out.push({ empresa: maybeEmpresa?.trim() || null, name: null, phone: maybePhone.match(phoneRx)[0] });
        i++; continue;
      }
    }

    // Linha que é metadata — pula
    if (isMeta(line)) { i++; continue; }

    // Candidato a nome de empresa: busca telefone nas próximas 25 linhas
    // (ignora linhas de meta durante a busca)
    let phone = null;
    let skipTo = i + 1;
    for (let j = i + 1; j < Math.min(i + 25, lines.length); j++) {
      const nxt = lines[j];
      // Se encontrar outra empresa candidata antes do telefone, para
      if (!isMeta(nxt) && !ignoreRx.test(nxt) && !phoneRx.test(nxt) && nxt.length > 3 && j > i + 3) {
        skipTo = j; break;
      }
      const m = nxt.match(phoneRx);
      if (m) { phone = m[0]; skipTo = i + 1; break; }
    }

    out.push({ empresa: line, name: null, phone });
    i++; continue;
  }

  // Aplica DDD padrão em telefones incompletos
  if (defaultDDD) {
    out.forEach(c => {
      if (!c.phone) return;
      const digits = c.phone.replace(/\D/g,'');
      if (digits.length === 8 || digits.length === 9) c.phone = defaultDDD + digits;
    });
  }

  // Remove duplicatas
  const shouldDedup = document.getElementById('org-cfg-dedup')?.checked !== false;
  const seen = new Set();
  let dupesRemoved = 0;
  const deduped = out.filter(c => {
    const key = c.phone ? c.phone.replace(/\D/g,'') : ('notel_' + (c.empresa || Math.random()));
    if (shouldDedup && seen.has(key)) { dupesRemoved++; return false; }
    seen.add(key); return true;
  });

  // Filtra sem telefone se configurado
  const removeNoTel = document.getElementById('org-cfg-remove-notel')?.checked !== false;
  orgCompanies = removeNoTel ? deduped.filter(c => c.phone) : deduped;
  orgDupesRemoved = dupesRemoved;
  orgOriginalCount = out.length;

  orgRenderResults();
}

function orgImportExcel(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const wb = XLSX.read(e.target.result, { type: 'binary' });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const rows = XLSX.utils.sheet_to_json(ws, { defval: '' });
    if (!rows.length) { alert('Arquivo vazio ou sem dados.'); return; }

    const headers = Object.keys(rows[0]);
    const phoneCol   = headers.find(h => /telefone|celular|whatsapp|fone|phone/i.test(h));
    const empresaCol = headers.find(h => /^empresa$|^company$|^cliente$/i.test(h));
    const nameCol    = headers.find(h => /^nome$|^name$|^contato$/i.test(h));
    // Fallback: se não tem coluna específica, usa qualquer coluna de texto
    const textCol    = !empresaCol && !nameCol
      ? headers.find(h => !phoneCol || h !== phoneCol)
      : null;

    orgCompanies = rows.map(r => ({
      empresa: empresaCol ? String(r[empresaCol] || '').trim() || null
               : (textCol ? String(r[textCol] || '').trim() || null : null),
      name:    nameCol ? String(r[nameCol] || '').trim() || null : null,
      phone:   phoneCol ? String(r[phoneCol] || '').trim() || null : null,
    })).filter(c => c.empresa || c.name || c.phone);

    orgRenderResults();
  };
  reader.readAsBinaryString(input.files[0]);
}

function decodeQP(str) {
  if (!str) return str;
  return str.replace(/=\r?\n/g,'').replace(/=([0-9A-Fa-f]{2})/g,(_,h)=>String.fromCharCode(parseInt(h,16))).trim();
}

function orgRenderResults() {
  const card = document.getElementById('org-results-card');
  const list = document.getElementById('org-results-list');
  const bar  = document.getElementById('org-send-bar');
  card.style.display = 'block';
  bar.style.display = orgCompanies.length ? 'block' : 'none';

  const withPhone = orgCompanies.filter(c => c.phone).length;
  const noPhone   = orgCompanies.length - withPhone;

  document.getElementById('org-stat-total').textContent   = `${orgCompanies.length} registros`;
  document.getElementById('org-stat-phones').textContent  = `${withPhone} com telefone`;
  document.getElementById('org-stat-total').className  = 'org-stat' + (orgCompanies.length ? ' ready' : '');
  document.getElementById('org-stat-phones').className = 'org-stat' + (withPhone ? ' ready' : '');

  const notelEl = document.getElementById('org-stat-notel');
  if (notelEl) {
    notelEl.style.display = noPhone > 0 ? '' : 'none';
    notelEl.textContent   = `${noPhone} sem telefone`;
  }
  const dupesEl = document.getElementById('org-stat-dupes');
  if (dupesEl && orgDupesRemoved > 0) {
    dupesEl.style.display = '';
    dupesEl.textContent   = `${orgDupesRemoved} duplicatas removidas`;
    dupesEl.className     = 'org-stat warn';
  } else if (dupesEl) {
    dupesEl.style.display = 'none';
  }

  ['org-btn-excel','org-btn-copy'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = orgCompanies.length ? '' : 'none';
  });

  if (!orgCompanies.length) {
    list.innerHTML = '<div class="empty-state"><h4>Nenhum registro encontrado</h4><p style="margin-top:4px;font-size:.85rem">Tente colar no formato: Nome\\tTelefone ou texto com telefones.</p></div>';
    return;
  }

  list.innerHTML = orgCompanies.map((c, i) => `
    <div class="org-item" id="org-item-${i}" style="display:flex;align-items:center;gap:8px">
      <div style="flex:1;min-width:0">
        <div class="name" style="cursor:pointer;display:flex;align-items:center;gap:5px" onclick="orgEditName(${i})" title="Clique para editar o nome">
          ${esc(c.empresa || c.name || '—')}
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="10" height="10" style="opacity:.35;flex-shrink:0"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </div>
        <div class="phone ${c.phone ? '' : 'none'}" style="cursor:pointer" onclick="orgEditPhone(${i})" title="Clique para editar o telefone">
          ${c.phone
            ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.62 3.38 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> ${esc(c.phone)}`
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> adicionar telefone'
          }
        </div>
      </div>
      <button onclick="orgRemove(${i})" title="Remover contato" style="background:none;border:none;cursor:pointer;color:var(--error);opacity:.5;padding:5px;flex-shrink:0;border-radius:50%;transition:all .15s" onmouseover="this.style.opacity=1;this.style.background='rgba(198,40,40,.1)'" onmouseout="this.style.opacity=.5;this.style.background='none'">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
  `).join('');
}

function orgEditName(i) {
  const c = orgCompanies[i];
  const current = c.empresa || c.name || '';
  const novo = prompt('Editar nome:', current);
  if (novo === null) return;
  orgCompanies[i].empresa = novo.trim();
  orgCompanies[i].name    = null;
  orgRenderResults();
}

function orgEditPhone(i) {
  const c = orgCompanies[i];
  const novo = prompt('Editar telefone:', c.phone || '');
  if (novo === null) return;
  orgCompanies[i].phone = novo.trim() || null;
  orgRenderResults();
}

function orgRemove(i) {
  orgCompanies.splice(i, 1);
  orgRenderResults();
}

function orgSendToDisparo() {
  const phones = orgCompanies.filter(c => c.phone).map(c => ({
    phone:   c.phone.replace(/\D/g,''),
    nome:    c.name    || '',
    empresa: c.empresa || '',
    cidade:  '',
  }));
  if (!phones.length) { alert('Nenhum número válido para enviar.'); return; }
  csvContacts    = phones;
  manualContacts = [];
  document.getElementById('manual-phones').value = '';
  rebuildContacts();
  goPage('disparo');
}

async function orgSendToCRM() {
  const withPhone = orgCompanies.filter(c => c.phone);
  if (!withPhone.length) { alert('Nenhum número válido para enviar.'); return; }

  const listName = prompt(`Nome da lista no CRM:\n(${withPhone.length} contatos serão salvos)`, `Lista ${new Date().toLocaleDateString('pt-BR')}`);
  if (listName === null) return;
  const source = listName.trim() || `Lista ${new Date().toLocaleDateString('pt-BR')}`;

  const contacts = withPhone.map(c => ({
    phone:   c.phone,
    empresa: c.empresa || null,
    name:    c.name    || null,
    source,
  }));

  const btn = document.querySelector('#org-send-bar .btn-azul');
  const origText = btn.innerHTML;
  btn.innerHTML = '⏳ Salvando…';
  btn.disabled = true;

  try {
    const r = await fetch('crm.php?action=import', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ contacts, source })
    });
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch(e) {
      alert('❌ Erro no servidor:\n' + text.slice(0,200));
      btn.innerHTML = origText; btn.disabled = false;
      return;
    }
    if (d.ok) {
      btn.innerHTML = `✅ ${d.inserted} salvos!`;
      refreshCrmLists();
      // Após 1.5s vai para Disparo com lista pré-selecionada
      setTimeout(() => {
        btn.innerHTML = origText; btn.disabled = false;
        goPage('disparo');
        // Marca o checkbox da lista recém-criada
        setTimeout(async () => {
          await refreshCrmLists();
          setTimeout(() => {
            const cb = document.querySelector(`.crm-list-cb[value="${source.replace(/"/g, '\\"')}"]`);
            if (cb) { cb.checked = true; updateCrmListsSummary(); }
          }, 300);
        }, 500);
      }, 1500);
    } else {
      alert('❌ Erro: ' + (d.error || 'Falha desconhecida'));
      btn.innerHTML = origText; btn.disabled = false;
    }
  } catch(e) {
    alert('❌ Falha de rede ao salvar no CRM: ' + e.message);
    btn.innerHTML = origText; btn.disabled = false;
  }
}

function orgDownloadExcel() {
  if (!orgCompanies.length) return;
  const data = [['Empresa','Telefone'], ...orgCompanies.map(c => [c.empresa||c.name||'', c.phone||''])];
  const ws = XLSX.utils.aoa_to_sheet(data);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Lista');
  XLSX.writeFile(wb, `lista_${new Date().toISOString().split('T')[0]}.xlsx`);
}

function orgCopyList() {
  const text = orgCompanies.map(c => `${c.empresa||c.name||''}\t${c.phone||''}`).join('\n');
  navigator.clipboard.writeText(text).then(() => alert(`${orgCompanies.length} itens copiados!`));
}

function orgClear() {
  if (!confirm('Limpar tudo?')) return;
  orgCompanies = [];
  orgDupesRemoved = 0;
  orgOriginalCount = 0;
  const el = document.getElementById('org-input');
  if (el) el.value = '';
  document.getElementById('org-results-card').style.display = 'none';
}

/* ══════════════════════════════════════════════════════════
   EXTRATOR DE LEADS — Google Places API (automático)
══════════════════════════════════════════════════════════ */
let extLeads = [];
let extSearching = false;
let extMode = 'auto'; // 'auto' | 'manual'
let extLastQuery = null;     // {kw,cidade,bairros}
let extTotalSkipped = 0;     // leads ignorados por já estarem no CRM

const EXT_AVATAR_COLORS = [
  '#1a5c2a','#2e7d32','#388e3c','#c8a96e','#795548',
  '#546e7a','#5c6bc0','#8d6e63','#00838f','#558b2f'
];
function extAvatarColor(str) {
  if (!str) return '#888';
  let h = 0;
  for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) >>> 0;
  return EXT_AVATAR_COLORS[h % EXT_AVATAR_COLORS.length];
}

/* ── Tabs Auto/Manual (exclusivos) ── */
function extSetMode(mode) {
  if (extSearching) { toast('Aguarde a busca terminar.', 'warn'); return; }
  extMode = mode;
  document.getElementById('ext-tab-auto').classList.toggle('active', mode === 'auto');
  document.getElementById('ext-tab-manual').classList.toggle('active', mode === 'manual');
  document.getElementById('ext-mode-auto').style.display   = mode === 'auto'   ? '' : 'none';
  document.getElementById('ext-mode-manual').style.display = mode === 'manual' ? '' : 'none';
  // Trocar de modo limpa os resultados anteriores (um elimina o outro)
  if (extLeads.length) {
    extLeads = [];
    document.getElementById('ext-results-section').style.display = 'none';
  }
  // Ao entrar no modo auto, garante que o wizard está no passo 1
  if (mode === 'auto') extWizardSync();
}

/* ── Wizard de 3 passos: Cidade → Bairro (opcional) → Categoria ── */
function extWizardSetActive(step) {
  // step: 1, 2 ou 3 — qual está ativo agora
  for (let i = 1; i <= 3; i++) {
    const card = document.getElementById(`ext-step-${i}`);
    const dot  = document.getElementById(`ext-dot-${i}`);
    if (!card || !dot) continue;
    card.classList.remove('active', 'complete', 'disabled');
    dot.classList.remove('active', 'complete');
    if (i < step) {
      card.classList.add('complete');
      dot.classList.add('complete');
    } else if (i === step) {
      card.classList.add('active');
      dot.classList.add('active');
    } else {
      card.classList.add('disabled');
    }
  }
  // Foca no input do passo ativo
  setTimeout(() => {
    const inp = document.querySelector(`#ext-step-${step} input[type=text]`);
    if (inp) inp.focus();
  }, 60);
  // Mostra "Recomeçar" só depois que sai do passo 1
  const reset = document.getElementById('ext-wizard-reset');
  if (reset) reset.style.display = step > 1 ? '' : 'none';
}

function extWizardNext(fromStep) {
  if (fromStep === 1) {
    const cidade = document.getElementById('ext-cidade')?.value.trim();
    if (!cidade) {
      toast('⚠️ Preencha a cidade primeiro.', 'warn');
      document.getElementById('ext-cidade')?.focus();
      return;
    }
    extWizardSetActive(2);
  } else if (fromStep === 2) {
    extWizardSetActive(3);
  }
  extUpdateHint();
}

function extWizardSkipBairro() {
  // Ignora bairro — limpa o campo e vai pro passo 3
  const b = document.getElementById('ext-bairro');
  if (b) b.value = '';
  extWizardSetActive(3);
  extUpdateHint();
}

function extWizardReset() {
  // Reset suave: mantém valores mas volta o wizard pro passo 1 (caso queira mudar cidade)
  extWizardSetActive(1);
  extUpdateHint();
}

function extWizardSync() {
  // Decide qual passo deve estar ativo com base no que está preenchido
  const cidade = document.getElementById('ext-cidade')?.value.trim();
  const bairro = document.getElementById('ext-bairro')?.value.trim();
  if (!cidade) extWizardSetActive(1);
  else if (!bairro) extWizardSetActive(2);
  else extWizardSetActive(3);
}

/* ── Hint dinâmico durante o preenchimento ── */
function extUpdateHint() {
  const kw     = (document.getElementById('ext-keyword')?.value || '').trim();
  const cidade = (document.getElementById('ext-cidade')?.value  || '').trim();
  const bairros= (document.getElementById('ext-bairro')?.value  || '').trim();
  const hint   = document.getElementById('ext-search-hint');
  if (!hint) return;

  if (!cidade) {
    hint.innerHTML = '<span style="color:var(--ouro,#c8a96e)">💡</span> Comece preenchendo a <strong>cidade</strong> acima.';
    return;
  }
  const bairroList = bairros.split(',').map(s => s.trim()).filter(Boolean);

  if (!kw) {
    if (bairroList.length === 0) {
      hint.innerHTML = `<span style="color:var(--verde-claro,#5a8a5a)">✓</span> Mapa calibrado em <strong>${esc(cidade)}</strong>. Agora preencha a categoria.`;
    } else {
      hint.innerHTML = `<span style="color:var(--verde-claro,#5a8a5a)">✓</span> Mapa calibrado: ${bairroList.length} bairro${bairroList.length>1?'s':''} de <strong>${esc(cidade)}</strong>. Agora preencha a categoria.`;
    }
    return;
  }

  if (bairroList.length === 0) {
    hint.innerHTML = `<span style="color:var(--verde-claro,#5a8a5a)">✓</span> 1 busca: <strong>${esc(kw)}</strong> em <strong>${esc(cidade)}</strong> — até 60 leads.`;
  } else {
    const max = bairroList.length * 60;
    hint.innerHTML = `<span style="color:var(--verde-claro,#5a8a5a)">✓</span> ${bairroList.length} busca${bairroList.length>1?'s':''}: <strong>${esc(kw)}</strong> em <strong>${bairroList.length} bairro${bairroList.length>1?'s':''}</strong> de ${esc(cidade)} — até <strong>${max} leads</strong> (após dedup).`;
  }
}

/* ── Progress bar ── */
function extSetProgress(label, count, pct) {
  const card = document.getElementById('ext-progress-card');
  if (!card) return;
  card.classList.add('active');
  document.getElementById('ext-progress-label').textContent = label;
  document.getElementById('ext-progress-count').textContent = `${count} lead${count!==1?'s':''}`;
  document.getElementById('ext-progress-fill').style.width = `${Math.min(100, Math.max(0, pct))}%`;
}
function extHideProgress() {
  document.getElementById('ext-progress-card')?.classList.remove('active');
}

/* ════════════════════════════════════════════════════════
   BUSCA AUTOMÁTICA — multi-bairro × paginação 3 páginas
   Cada bairro = 1 query independente, deduplicada por place_id.
   Backend usa a NEW Places API (telefone vem direto, sem /details).
═══════════════════════════════════════════════════════════ */
async function extSearch() {
  if (extSearching) return;

  const kw     = (document.getElementById('ext-keyword')?.value || '').trim();
  const cidade = (document.getElementById('ext-cidade')?.value  || '').trim();
  const bairrosRaw = (document.getElementById('ext-bairro')?.value || '').trim();

  if (!kw)     { toast('⚠️ Preencha a categoria.', 'warn'); document.getElementById('ext-keyword')?.focus(); return; }
  if (!cidade) { toast('⚠️ Preencha a cidade.', 'warn');    document.getElementById('ext-cidade')?.focus();  return; }

  // Lista de bairros (vazia = uma busca só na cidade toda)
  const bairros = bairrosRaw
    ? bairrosRaw.split(',').map(s => s.trim()).filter(Boolean)
    : [''];

  extSearching = true;
  extLeads = [];
  extLastQuery = { kw, cidade, bairros };
  extTotalSkipped = 0; // leads já no CRM (somados de todas as queries)
  // Limpa resultados de outras telas
  if (typeof orgCompanies !== 'undefined') {
    orgCompanies = []; orgDupesRemoved = 0; orgOriginalCount = 0;
    const oc = document.getElementById('org-results-card'); if (oc) oc.style.display = 'none';
  }
  document.getElementById('ext-results-section').style.display = 'none';

  const btn = document.getElementById('ext-btn-search');
  const origHtml = btn?.innerHTML;
  if (btn) {
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="animation:spin 1s linear infinite"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Buscando…';
    btn.disabled = true;
  }

  const seenPlaceIds = new Set();
  const HARD_CAP = 80; // trava: nunca mais de 80 novos por execução
  const totalQueries = bairros.length * 3;
  let queriesRun = 0;
  let stoppedAtCap = false;

  try {
    outerLoop:
    for (let bi = 0; bi < bairros.length; bi++) {
      const bairro = bairros[bi];
      const localLabel = bairro ? `${bairro}, ${cidade}` : cidade;

      // ─── Página 1 ───
      extSetProgress(
        bairros.length > 1
          ? `Bairro ${bi+1}/${bairros.length} (${bairro || cidade}) — página 1`
          : `Buscando — página 1`,
        extLeads.length,
        (queriesRun / totalQueries) * 100
      );

      const params = new URLSearchParams({ keyword: kw, city: localLabel, exclude_in_crm: '1' });
      const r = await fetch(`extrator.php?action=search&${params}`, { credentials: 'same-origin' });
      const d = await r.json();

      if (!d.ok) {
        if (d.error?.includes('não configurada')) { extShowKeyWarning(); return; }
        toast(`⚠️ ${bairro || cidade}: ${d.error || 'falha'}`, 'warn');
        queriesRun += 3;
        continue;
      }
      extTotalSkipped += (d.excluded_in_crm || 0);
      addUniqueLeads(d.results, seenPlaceIds, HARD_CAP);
      queriesRun++;
      renderResultsLive();
      if (extLeads.length >= HARD_CAP) { stoppedAtCap = true; break outerLoop; }

      // ─── Páginas 2 e 3 ───
      let nextToken = d.next_page_token;
      let pageNum = 2;
      while (nextToken && pageNum <= 3) {
        await new Promise(res => setTimeout(res, 2200));
        try {
          const p2 = new URLSearchParams({ keyword: kw, city: localLabel, pagetoken: nextToken, exclude_in_crm: '1' });
          const r2 = await fetch(`extrator.php?action=search&${p2}`, { credentials: 'same-origin' });
          const d2 = await r2.json();
          if (d2.ok && d2.results?.length) {
            extTotalSkipped += (d2.excluded_in_crm || 0);
            addUniqueLeads(d2.results, seenPlaceIds, HARD_CAP);
            nextToken = d2.next_page_token;
          } else {
            nextToken = null;
          }
        } catch(e2) { nextToken = null; }
        queriesRun++;
        renderResultsLive();
        if (extLeads.length >= HARD_CAP) { stoppedAtCap = true; break outerLoop; }
        pageNum++;
      }
      if (pageNum <= 3) queriesRun += (4 - pageNum);
    }

    extSetProgress(
      stoppedAtCap
        ? `✓ Limite de ${HARD_CAP} atingido — ${extLeads.length} novos`
        : `Concluído — ${extLeads.length} leads novos`,
      extLeads.length, 100
    );
    setTimeout(extHideProgress, 2200);

    if (!extLeads.length) {
      const msg = extTotalSkipped > 0
        ? `Nenhum lead novo. Todos os ${extTotalSkipped} resultados já estão no CRM.`
        : 'Nenhum resultado encontrado. Tente outra categoria/cidade.';
      toast(msg, 'warn');
    } else {
      const withPhone = extLeads.filter(l => l.phone).length;
      let msg = `✅ ${extLeads.length} novos — ${withPhone} com telefone`;
      if (extTotalSkipped > 0) msg += ` · ${extTotalSkipped} já no CRM (ignorados)`;
      toast(msg, 'success');
    }

  } catch(e) {
    toast('Erro: ' + e.message, 'error');
    extHideProgress();
  } finally {
    extSearching = false;
    if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
  }
}

function addUniqueLeads(results, seenSet, hardCap) {
  for (const r of results) {
    if (extLeads.length >= hardCap) return;
    if (!r.place_id || seenSet.has(r.place_id)) continue;
    seenSet.add(r.place_id);
    extLeads.push(r);
  }
}

function renderResultsLive() {
  const sec = document.getElementById('ext-results-section');
  if (sec.style.display === 'none' && extLeads.length) {
    sec.style.display = '';
    sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  extUpdateStats();
  extRender();
}

function extShowKeyWarning() {
  const section = document.getElementById('ext-results-section');
  section.style.display = '';
  document.getElementById('ext-grid').innerHTML = `
    <div class="ext-empty" style="grid-column:1/-1">
      <div style="font-size:2rem;margin-bottom:12px">🔒</div>
      <div style="font-weight:700;font-size:1rem;margin-bottom:8px">Busca automática indisponível</div>
      <p style="font-size:.85rem;color:var(--cinza);margin-bottom:16px;max-width:460px;margin-inline:auto">
        A chave da Google Places API não está configurada no servidor.
        Entre em contato com o desenvolvedor para ativá-la.
      </p>
      <div style="margin-top:8px;font-size:.8rem;color:var(--cinza);max-width:460px;margin-inline:auto">
        Enquanto isso, use o <strong>modo manual</strong>: clique na aba acima e cole o texto do Google Maps.
      </div>
    </div>`;
  document.getElementById('ext-filter-bar')?.style && (document.getElementById('ext-filter-bar').style.display = 'none');
}

/* ════════════════════════════════════════════════════════
   MODO MANUAL — colar texto do Google Maps
   Parser robusto, index-based: encontra todas as linhas de
   rating, e para cada uma extrai empresa (linha anterior) e
   telefone (próximas linhas até o próximo rating).
   Sem limite de quantidade — escala para 100+ leads.
═══════════════════════════════════════════════════════════ */
function extExtract() {
  const raw = (document.getElementById('ext-paste')?.value || '').trim();
  if (!raw) { alert('Cole o texto do Google Maps primeiro.'); return; }
  extLeads = extParseMaps(raw);
  if (!extLeads.length) {
    alert('Nenhum lead encontrado. Certifique-se de copiar a lista completa do Maps (Ctrl+A → Ctrl+C).');
    return;
  }
  extUpdateStats();
  extRender();
  const sec = document.getElementById('ext-results-section');
  sec.style.display = '';
  sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
  toast(`✅ ${extLeads.length} leads extraídos do texto.`, 'success');
}

function extParseMaps(text) {
  // Telefones BR — captura formatos do Maps:
  //   (48) 2132-2383   → fixo
  //   (48) 99936-2590  → móvel com 9
  //   (48) 3037 2828   → fixo com espaço
  const phoneRx = /\(\d{2}\)\s*\d{4,5}[-.\s]?\d{4}/g;
  // Fallback sem parênteses: 48 99936-2590 ou 48 9 9936-2590
  const phoneAltRx = /\b\d{2}\s+9?\s?\d{4}[-.\s]\d{4}\b/g;
  // Avaliação numérica: "4,8(123)" | "4.8 (1.234)"
  const ratingRx = /^(\d[.,]\d)\s*\(?\s*([\d.,]+)\s*\)?\s*$/;
  // Marcador "sem avaliação" — também separa cards
  const noRatingRx = /^(Nenhuma avaliação|Sem avaliações|Avalie este lugar|Seja o primeiro a avaliar)$/i;

  // RUÍDO PURO (não tem telefone, são botões/UI). NÃO incluir Aberto/Fechado/Fecha às:
  // essas linhas costumam ter telefone embutido — "Fechado · Abre às 13:00 · (48) 2132-2383"
  const skipExact = new Set([
    'Resultados','Mais informações','Abrir no Maps','Fechar',
    'Mostrar mais resultados','Compartilhar','Website','Rotas','Ligar','Salvar',
    'Sugerir edição','Adicionar foto','Reivindicar este local','Mais',
    '·','—','-','Patrocinado','Anúncio','Anúncios',
    'Pesquisa de hotéis','Definir como local de partida',
    'Enviar para o celular','Agendamento on-line',
    'Fazer reserva','Reservar mesa','Pedir agora','Pedir on-line',
    'Menu','Menus','nan','None'
  ]);
  // Filtro de prefixo conservador — só lixo claro, nada que possa carregar telefone
  const skipRx = /^(https?:\/\/|www\.|R\$|Adicione foto|Sugerir edição|Reivindicar|Definir como|Enviar para o|Menos info\b|Mais info\b|"|Recomend|Atendimento|Avalie\b)/i;

  const lines = text.split(/\r?\n/).map(l => l.trim())
    .filter(l => l && !skipExact.has(l) && !skipRx.test(l));

  // Indexa qualquer marcador de "fim de cabeçalho do card":
  // rating numérico OU "Nenhuma avaliação"
  const ratingIdxs = [];
  for (let i = 0; i < lines.length; i++) {
    if (ratingRx.test(lines[i]) || noRatingRx.test(lines[i])) ratingIdxs.push(i);
  }

  const leads = [];
  const seen  = new Set();

  for (let k = 0; k < ratingIdxs.length; k++) {
    const ri      = ratingIdxs[k];
    const nextRi  = ratingIdxs[k+1] ?? lines.length;

    // Empresa = linha imediatamente anterior ao marcador
    let empresa = (ri > 0) ? lines[ri-1] : null;
    if (!empresa) continue;
    if (empresa.length < 2 || /^[\d\s.,]+$/.test(empresa)) continue;

    // Avaliação (0/0 quando "Nenhuma avaliação")
    let rating = 0, reviews = 0;
    const rm = lines[ri].match(ratingRx);
    if (rm) {
      rating  = parseFloat(rm[1].replace(',','.'));
      reviews = parseInt(rm[2].replace(/\D/g,'')) || 0;
    }

    // Telefone: varre TODAS as linhas até o próximo card,
    // SEM se importar com o que a linha começa.
    let phone = null;
    for (let j = ri + 1; j < nextRi; j++) {
      const pm = lines[j].match(phoneRx) || lines[j].match(phoneAltRx);
      if (pm) { phone = pm[0].trim(); break; }
    }

    // Dedup: por telefone (forte) ou nome em lowercase (fraco)
    const dedupKey = phone ? phone.replace(/\D/g,'') : ('name_' + empresa.toLowerCase());
    if (seen.has(dedupKey)) continue;
    seen.add(dedupKey);

    leads.push({
      place_id: null,
      empresa,
      rating,
      reviews,
      phone,
      address: null,
      types: [],
      website: null
    });
  }

  return leads;
}

/* ════════════════════════════════════════════════════════
   STATS · FILTROS · RENDER · AÇÕES
═══════════════════════════════════════════════════════════ */
function extUpdateStats() {
  const withPhone = extLeads.filter(l => l.phone).length;
  const noPhone   = extLeads.length - withPhone;
  document.getElementById('ext-stat-total').textContent  = `${extLeads.length} empresa${extLeads.length !== 1 ? 's' : ''}`;
  document.getElementById('ext-stat-phones').textContent = `${withPhone} com tel.`;
  document.getElementById('ext-stat-notel').textContent  = `${noPhone} sem tel.`;
  document.getElementById('ext-stat-phones').className   = 'org-stat' + (withPhone ? ' ready' : '');
  document.getElementById('ext-stat-notel').className    = 'org-stat' + (noPhone ? ' warn' : '');
}

function extGetFiltered() {
  const onlyPhone = document.getElementById('ext-filter-phone')?.checked;
  const minRating = parseFloat(document.getElementById('ext-filter-rating')?.value || '0');
  const search    = (document.getElementById('ext-filter-text')?.value || '').toLowerCase().trim();
  return extLeads.filter(l => {
    if (onlyPhone && !l.phone) return false;
    if (minRating > 0 && (l.rating || 0) < minRating) return false;
    if (search && !(l.empresa || '').toLowerCase().includes(search) &&
                  !(l.address || '').toLowerCase().includes(search)) return false;
    return true;
  });
}

function extStars(r) {
  if (!r) return '';
  const full = Math.floor(r), half = r - full >= 0.5 ? 1 : 0, empty = 5 - full - half;
  return '★'.repeat(full) + (half ? '½' : '') + '☆'.repeat(empty);
}

function extRender() {
  const grid      = document.getElementById('ext-grid');
  const filterBar = document.getElementById('ext-filter-bar');
  if (filterBar) filterBar.style.display = '';
  const filtered = extGetFiltered();
  if (!filtered.length) {
    grid.innerHTML = '<div class="ext-empty">Nenhum lead com esses filtros.</div>';
    return;
  }
  grid.innerHTML = filtered.map((l) => {
    const i     = extLeads.indexOf(l);
    const color = extAvatarColor(l.empresa || '');
    const ini   = (l.empresa || '?').charAt(0).toUpperCase();
    const ratingHtml = l.rating
      ? `<div class="ext-rating"><span class="stars">${extStars(l.rating)}</span><strong>${l.rating.toFixed(1)}</strong><span class="count">(${(l.reviews||0).toLocaleString('pt-BR')})</span></div>`
      : '';
    const phoneHtml = l.phone
      ? `<div class="ext-phone"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.62 3.38 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>${esc(l.phone)}</div>`
      : `<div class="ext-phone none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>sem telefone</div>`;
    return `
      <div class="ext-card${l.phone ? '' : ' no-phone'}">
        <div class="ext-card-header">
          <div class="ext-avatar" style="background:${color}">${ini}</div>
          <div style="flex:1;min-width:0">
            <div class="ext-card-name">${esc(l.empresa || '(sem nome)')}</div>
          </div>
        </div>
        ${ratingHtml}
        ${phoneHtml}
        <div class="ext-card-actions">
          <button onclick="extEditLead(${i})" title="Editar">✏️ Editar</button>
          ${l.phone ? `<button class="primary" onclick="extAddToCRMSingle(${i})">+ CRM</button>` : ''}
          <button onclick="extRemoveLead(${i})" style="color:var(--error)">🗑</button>
        </div>
      </div>`;
  }).join('');
}

function extEditLead(i) {
  const l = extLeads[i];
  const novoNome = prompt('Nome da empresa:', l.empresa || '');
  if (novoNome === null) return;
  const novoTel = prompt('Telefone:', l.phone || '');
  if (novoTel === null) return;
  extLeads[i].empresa = novoNome.trim() || l.empresa;
  extLeads[i].phone   = novoTel.trim() || null;
  extUpdateStats();
  extRender();
}

function extRemoveLead(i) {
  extLeads.splice(i, 1);
  extUpdateStats();
  extRender();
  if (!extLeads.length) document.getElementById('ext-results-section').style.display = 'none';
}

async function extAddToCRMSingle(i) {
  const l = extLeads[i];
  if (!l.phone) { alert('Sem telefone — não é possível salvar no CRM.'); return; }
  const kw = (document.getElementById('ext-keyword')?.value || '').trim();
  const cy = (document.getElementById('ext-cidade')?.value  || '').trim();
  const source = [kw, cy].filter(Boolean).join(' - ') || `Extrator ${new Date().toLocaleDateString('pt-BR')}`;
  const r = await fetch('crm.php?action=import', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ contacts: [{ phone: l.phone, empresa: l.empresa||null, name: null, source }], source })
  });
  const d = await r.json();
  if (d.ok) toast(`✅ ${l.empresa || l.phone} salvo no CRM!`, 'success');
  else toast('❌ Erro: ' + (d.error || 'Falha'), 'error');
}

function extExportExcel() {
  const filtered = extGetFiltered();
  if (!filtered.length) { alert('Nada para exportar com esses filtros.'); return; }
  // Por solicitação: apenas Empresa e Telefone (foco da aplicação)
  const data = [
    ['Empresa','Telefone'],
    ...filtered.map(l => [l.empresa||'', l.phone||''])
  ];
  const ws = XLSX.utils.aoa_to_sheet(data);
  ws['!cols'] = [{wch:42},{wch:20}];
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Leads');
  const kw = (document.getElementById('ext-keyword')?.value || 'leads').trim().replace(/\s+/g,'_') || 'leads';
  XLSX.writeFile(wb, `extrator_${kw}_${new Date().toISOString().split('T')[0]}.xlsx`);
}

async function extSendToCRM() {
  const filtered = extGetFiltered().filter(l => l.phone);
  if (!filtered.length) { alert('Nenhum lead com telefone para salvar.'); return; }

  const kw = (document.getElementById('ext-keyword')?.value || '').trim();
  const cy = (document.getElementById('ext-cidade')?.value  || '').trim();
  const br = (document.getElementById('ext-bairro')?.value  || '').trim();

  // Nome base inteligente: "Categoria - Cidade [Bairros]"
  let basePrefix = [kw, cy].filter(Boolean).join(' - ') || `Extrator ${new Date().toLocaleDateString('pt-BR')}`;
  if (br) basePrefix += ` (${br.split(',').map(s=>s.trim()).slice(0,2).join('/')}${br.split(',').length>2?'…':''})`;

  // Consulta o backend para descobrir o próximo slot disponível (#2, #3 se #1 lotada)
  let suggestedTarget = basePrefix;
  let slotInfo = null;
  try {
    const r = await fetch(`crm.php?action=next_list_slot&prefix=${encodeURIComponent(basePrefix)}`);
    const d = await r.json();
    if (d.ok) {
      suggestedTarget = d.target;
      slotInfo = d;
    }
  } catch(e) { /* segue com o fallback */ }

  let promptMsg = `Nome da lista no CRM:\n(${filtered.length} leads com telefone)`;
  if (slotInfo && !slotInfo.is_new_slot && slotInfo.existing_count > 0) {
    promptMsg = `Nome da lista no CRM:\n(${filtered.length} leads com telefone)\n\n💡 "${suggestedTarget}" já tem ${slotInfo.existing_count} contatos. Cabem mais ${slotInfo.capacity}.`;
  }

  const listName = prompt(promptMsg, suggestedTarget);
  if (listName === null) return;
  const source = listName.trim() || suggestedTarget;

  // Dedup local antes de enviar (telefone normalizado)
  const seenPhones = new Set();
  let dupesLocal = 0;
  const unique = filtered.filter(l => {
    const digits = (l.phone || '').replace(/\D/g, '');
    if (seenPhones.has(digits)) { dupesLocal++; return false; }
    seenPhones.add(digits); return true;
  });
  if (dupesLocal > 0) toast(`⚠️ ${dupesLocal} duplicata${dupesLocal>1?'s':''} removida${dupesLocal>1?'s':''} antes de salvar.`, 'warn');

  // Manda place_id junto — habilita dedup forte no servidor
  const contacts = unique.map(l => ({
    phone:    l.phone,
    empresa:  l.empresa || null,
    name:     null,
    place_id: l.place_id || null,
    source
  }));

  const btn = document.querySelector('#ext-results-section .btn-azul');
  const orig = btn?.innerHTML;
  if (btn) { btn.innerHTML = '⏳ Salvando…'; btn.disabled = true; }
  try {
    const r = await fetch('crm.php?action=import', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ contacts, source })
    });
    const d = await r.json();
    if (d.ok) {
      const lists = d.lists || [source];
      const skipped = contacts.length - (d.inserted || 0);
      let msg = `✅ ${d.inserted} salvos`;
      if (lists.length > 1) msg += ` em ${lists.length} listas (split de 100)`;
      if (skipped > 0) msg += ` · ${skipped} já existiam`;
      if (btn) btn.innerHTML = msg;
      toast(msg, 'success');
      // Remove os salvos da grid (já estão no CRM)
      const savedPhones = new Set(unique.map(l => (l.phone||'').replace(/\D/g,'')));
      extLeads = extLeads.filter(l => !savedPhones.has((l.phone||'').replace(/\D/g,'')));
      extTotalSkipped += unique.length;
      extUpdateStats();
      extRender();
      refreshCrmLists();
      setTimeout(() => {
        if (btn) { btn.innerHTML = orig; btn.disabled = false; }
        if (!extLeads.length) {
          // Tudo salvo → oferece nova rodada
          if (confirm(`✅ Todos os ${unique.length} leads salvos!\n\nQuer buscar mais leads novos? (a próxima rodada vai ignorar automaticamente todos que já estão no CRM)`)) {
            extSearch();
          }
        }
      }, 1700);
    } else {
      toast('❌ Erro: ' + (d.error || 'Falha'), 'error');
      if (btn) { btn.innerHTML = orig; btn.disabled = false; }
    }
  } catch(e) {
    toast('❌ Falha de rede: ' + e.message, 'error');
    if (btn) { btn.innerHTML = orig; btn.disabled = false; }
  }
}

function extClear() {
  if (extLeads.length && !confirm('Limpar todos os leads?')) return;
  extLeads = [];
  const el = document.getElementById('ext-paste');
  if (el) el.value = '';
  document.getElementById('ext-results-section').style.display = 'none';
  extHideProgress();
}

/* ── CRM — Excel Import/Export ──────────── */
async function crmImportExcel(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = async e => {
    const wb = XLSX.read(e.target.result, { type: 'binary' });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const rows = XLSX.utils.sheet_to_json(ws, { defval: '' });
    if (!rows.length) { alert('Arquivo vazio.'); return; }

    const headers = Object.keys(rows[0]);
    const phoneCol = headers.find(h => /telefone|celular|whatsapp|fone|phone/i.test(h));
    const nameCol  = headers.find(h => /nome|empresa|cliente|name/i.test(h));
    if (!phoneCol) { alert('Coluna de telefone não encontrada. Use "telefone", "celular" ou "whatsapp".'); return; }

    const contacts = rows.map(r => ({
      phone:  String(r[phoneCol]||'').replace(/\D/g,''),
      name:   nameCol ? String(r[nameCol]||'').trim() || null : null,
      source: 'Import Excel',
    })).filter(c => c.phone.length >= 10);

    const r = await fetch('crm.php?action=import', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ contacts })
    });
    const d = await r.json();
    if (d.ok) {
      alert(`✅ ${d.inserted} contatos importados! ${d.skipped ? `(${d.skipped} já existiam)` : ''}`);
      crmLoad(1);
    }
  };
  reader.readAsBinaryString(input.files[0]);
}

function crmDownloadExcel() {
  // Exporta o que está visível atualmente (respeita filtro de status)
  const params = new URLSearchParams({ action:'list', status:crmStatus, search:crmSearch, page:1, per:9999 });
  fetch('crm.php?' + params)
    .then(r => r.json())
    .then(d => {
      if (!d.ok || !d.contacts.length) { alert('Nenhum contato para exportar.'); return; }
      const data = [['Telefone','Nome','Status','Origem','Adicionado'],
        ...d.contacts.map(c => [c.phone, c.name||'', c.status, c.source||'', c.created_at?.slice(0,16)||''])];
      const ws = XLSX.utils.aoa_to_sheet(data);
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'CRM');
      XLSX.writeFile(wb, `crm_${crmStatus}_${new Date().toISOString().split('T')[0]}.xlsx`);
    });
}

/* ── LISTAS CRM ──────────────────────────── */
let crmSource = '';

/* ── CRM — Juntar Listas (merge_lists) ────────────────── */
async function openMergeListsModal() {
  const overlay = document.getElementById('merge-modal');
  if (!overlay) return;
  overlay.style.display = 'flex';
  document.getElementById('merge-target-name').value = '';
  document.getElementById('merge-summary').textContent = '';
  document.getElementById('merge-preview').textContent = '';
  document.getElementById('merge-btn-confirm').disabled = true;
  document.getElementById('merge-btn-confirm').style.opacity = '.5';

  // Carrega listas disponíveis
  const box = document.getElementById('merge-lists-options');
  box.innerHTML = '<em style="color:var(--cinza);font-size:.85rem">Carregando listas…</em>';
  try {
    const r = await fetch('crm.php?action=sources');
    const d = await r.json();
    if (!d.ok || !d.sources?.length) {
      box.innerHTML = '<em style="color:var(--cinza);font-size:.85rem">Você ainda não tem listas no CRM.</em>';
      return;
    }
    box.innerHTML = d.sources.map(s => `
      <label style="display:flex;align-items:center;gap:9px;padding:7px 4px;cursor:pointer;border-radius:6px;font-size:.86rem" onmouseover="this.style.background='#fff'" onmouseout="this.style.background=''">
        <input type="checkbox" class="merge-list-cb" value="${esc(s.source)}" data-total="${s.total}"
               style="width:15px;height:15px;cursor:pointer;accent-color:var(--verde)" onchange="updateMergePreview()">
        <span style="flex:1;color:var(--texto);font-weight:500">${esc(s.source)}</span>
        <span style="font-family:var(--font-mono);color:var(--cinza);font-size:.78rem">${s.total} ${s.total === 1 ? 'contato' : 'contatos'}</span>
      </label>
    `).join('');
  } catch(e) {
    box.innerHTML = '<em style="color:var(--error);font-size:.85rem">Erro ao carregar listas.</em>';
  }
}

function closeMergeListsModal() {
  const overlay = document.getElementById('merge-modal');
  if (overlay) overlay.style.display = 'none';
}

function updateMergePreview() {
  const cbs = [...document.querySelectorAll('.merge-list-cb:checked')];
  const summary = document.getElementById('merge-summary');
  const preview = document.getElementById('merge-preview');
  const btn     = document.getElementById('merge-btn-confirm');
  const target  = document.getElementById('merge-target-name');

  if (cbs.length < 2) {
    summary.textContent = cbs.length === 1
      ? '⚠️ Selecione pelo menos mais uma lista.'
      : '';
    preview.textContent = '';
    btn.disabled = true; btn.style.opacity = '.5';
    return;
  }

  const total = cbs.reduce((acc, cb) => acc + (parseInt(cb.dataset.total) || 0), 0);
  summary.innerHTML = `✓ <strong>${cbs.length} listas</strong> selecionadas · <strong>${total} contatos</strong> no total`;

  // Pré-preenche nome se vazio
  if (!target.value.trim()) {
    target.value = cbs[0].value + ' (juntada)';
  }

  // Preview do split
  const targetName = target.value.trim();
  if (!targetName) { btn.disabled = true; btn.style.opacity = '.5'; preview.textContent = ''; return; }

  if (total <= 100) {
    preview.innerHTML = `📋 Tudo cabe em <strong>"${esc(targetName)}"</strong> (${total}/100).`;
  } else {
    const splits = Math.ceil(total / 100);
    preview.innerHTML = `📋 Será dividido em <strong>${splits} listas</strong>: <em>"${esc(targetName)}"</em>, <em>"${esc(targetName)} #2"</em>${splits > 2 ? `…<em>"${esc(targetName)} #${splits}"</em>` : ''} (100 por lista).`;
  }
  btn.disabled = false; btn.style.opacity = '1';
}

// Reage a mudanças no nome também
document.addEventListener('input', e => {
  if (e.target?.id === 'merge-target-name') updateMergePreview();
});
// ESC fecha o modal
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    const m = document.getElementById('merge-modal');
    if (m && m.style.display !== 'none') closeMergeListsModal();
  }
});
// Click no overlay (fora do conteúdo) também fecha
document.addEventListener('click', e => {
  const m = document.getElementById('merge-modal');
  if (m && e.target === m) closeMergeListsModal();
});

async function confirmMergeLists() {
  const cbs = [...document.querySelectorAll('.merge-list-cb:checked')];
  const target = document.getElementById('merge-target-name').value.trim();
  if (cbs.length < 2 || !target) return;

  const sources = cbs.map(cb => cb.value);
  const totalContatos = cbs.reduce((acc, cb) => acc + (parseInt(cb.dataset.total) || 0), 0);

  // Confirmação extra se for grande
  if (totalContatos > 100) {
    const splits = Math.ceil(totalContatos / 100);
    if (!confirm(`Vai juntar ${cbs.length} listas (${totalContatos} contatos) em ${splits} listas:\n\n"${target}", "${target} #2"${splits > 2 ? '…' : ''}\n\nConfirmar?`)) return;
  }

  const btn = document.getElementById('merge-btn-confirm');
  const orig = btn.innerHTML;
  btn.innerHTML = '⏳ Juntando…'; btn.disabled = true;

  try {
    const r = await fetch('crm.php?action=merge_lists', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ sources, target })
    });
    const d = await r.json();
    if (d.ok) {
      const listNames = Object.keys(d.lists || {});
      toast(`✅ ${d.merged} contatos juntados em ${listNames.length} lista${listNames.length>1?'s':''}: ${listNames.join(', ')}`, 'success');
      closeMergeListsModal();
      // Atualiza tudo que depende das listas
      loadCrmSources();
      refreshCrmLists();
      crmLoad(1);
    } else {
      toast('❌ Erro: ' + (d.error || 'Falha'), 'error');
      btn.innerHTML = orig; btn.disabled = false;
    }
  } catch(e) {
    toast('❌ Falha de rede: ' + e.message, 'error');
    btn.innerHTML = orig; btn.disabled = false;
  }
}

async function loadCrmSources() {
  try {
    const r = await fetch('crm.php?action=sources');
    const d = await r.json();
    if (!d.ok) return;
    const sel = document.getElementById('crm-source-select');
    if (!sel) return;
    sel.innerHTML = '<option value="">Todos os contatos</option>' +
      (d.sources||[]).map(s =>
        `<option value="${esc(s.source)}">${esc(s.source)} (${s.total})</option>`
      ).join('');
  } catch(e) {}
}

function crmSetSource(source) {
  crmSource = source;
  // Mostra/esconde botões de ação por lista
  const showActions = !!source;
  document.getElementById('btn-rename-list').style.display = showActions ? '' : 'none';
  document.getElementById('btn-delete-list').style.display = showActions ? '' : 'none';
  crmLoad(1);
}

async function renameCrmList() {
  if (!crmSource) return;
  const novo = prompt(`Renomear lista "${crmSource}":\n\nDigite o novo nome:`, crmSource);
  if (novo === null) return;
  const newName = novo.trim();
  if (!newName) { alert('O nome não pode ficar vazio.'); return; }
  if (newName === crmSource) return;

  try {
    const r = await fetch('crm.php?action=rename_list', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({old: crmSource, new: newName})
    });
    const d = await r.json();
    if (d.ok) {
      toast(`Lista renomeada de "${crmSource}" para "${newName}" (${d.affected} contatos atualizados).`, 'success');
      crmSource = newName;
      await loadCrmSources();
      // Reseleciona a lista renomeada
      const sel = document.getElementById('crm-source-select');
      if (sel) sel.value = newName;
      crmLoad(1);
      refreshCrmLists();
    } else {
      alert('❌ Erro: ' + (d.error || 'Falha ao renomear'));
    }
  } catch(e) { alert('❌ Erro de rede: ' + e.message); }
}

async function toggleBlockContact() {
  if (!window._popupContactId) return;
  const isBlocked = window._popupBlocked === true;
  const action = isBlocked ? 'unblock' : 'block';
  const label  = isBlocked ? 'desbloqueado' : 'bloqueado';
  try {
    const r = await fetch(`crm.php?action=${action}`, {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id: window._popupContactId})
    });
    const d = await r.json();
    if (d.ok) {
      toast(`Contato ${label}!`, 'success');
      crmClosePopup();
      crmLoad(crmPage);
    } else {
      toast('Erro: ' + (d.error||'?'), 'error');
    }
  } catch(e) { toast('Erro de rede.', 'error'); }
}

async function syncCampaigns() {
  if (!confirm('Sincronizar campanhas?\n\nIsso vai marcar nos contatos do CRM quem foi disparado em cada campanha (baseado nos disparos já realizados).')) return;
  toast('🔄 Sincronizando campanhas...', 'info');
  try {
    const r = await fetch('crm.php?action=sync_campaigns', {method:'POST', credentials:'same-origin'});
    const d = await r.json();
    if (d.ok) {
      toast(`✅ ${d.updated} contatos sincronizados!`, 'success');
      loadCrmCampaigns();
      crmLoad(1);
    } else {
      toast('Erro: ' + (d.error||'?'), 'error');
    }
  } catch(e) { toast('Erro de rede.', 'error'); }
}

async function normalizePhones() {
  if (!confirm(
    'Limpar e validar telefones?\n\n' +
    'Isso vai:\n' +
    '• Remover códigos de operadora (014, 015, 021, 041 etc)\n' +
    '• Adicionar 55 (Brasil) onde faltar\n' +
    '• Adicionar 9 em celulares antigos (8 dígitos → 9)\n' +
    '• Remover duplicatas geradas pela limpeza\n' +
    '• EXCLUIR contatos com número inválido (DDD inexistente,\n' +
    '  formato impossível, número fake)\n\n' +
    '⚠️ Não pode ser desfeito. Continuar?'
  )) return;

  toast('🔧 Normalizando e validando telefones...', 'info');
  try {
    const r = await fetch('crm.php?action=normalize_phones', {method:'POST', credentials:'same-origin'});
    const d = await r.json();
    if (d.ok) {
      const parts = [];
      if (d.updated > 0)           parts.push(`${d.updated} normalizados`);
      if (d.duplicates_removed > 0) parts.push(`${d.duplicates_removed} duplicatas removidas`);
      if (d.deleted_invalid > 0)    parts.push(`${d.deleted_invalid} inválidos excluídos`);
      const resumo = parts.length ? parts.join(' · ') : 'Nenhuma alteração necessária';
      toast(`✅ ${resumo} · ${d.total_processed} processados no total`, 'success', 8000);
      crmLoad(1);
    } else {
      toast('Erro: ' + (d.error||'?'), 'error');
    }
  } catch(e) { toast('Erro de rede.', 'error'); }
}

async function exportCrmExcel() {
  // Carrega TODOS os contatos (com filtro de lista, se houver)
  const params = new URLSearchParams({ action:'list', page:'1', per_page:'10000' });
  if (crmSource) params.set('source', crmSource);
  try {
    const r = await fetch('crm.php?' + params.toString());
    const d = await r.json();
    if (!d.ok || !d.contacts || !d.contacts.length) {
      alert('Nenhum contato para exportar.');
      return;
    }
    const rows = d.contacts.map(c => ({
      Empresa:    c.empresa || '',
      Nome:       c.name || '',
      Telefone:   c.phone || '',
      Status:     ({pendente:'Pendente',em_contato:'Em Contato',ganho:'Ganho',perdido:'Perdido'})[c.status] || c.status,
      Lista:      c.source || '',
      Observação: c.notes || '',
      Criado:     c.created_at || '',
    }));
    const ws = XLSX.utils.json_to_sheet(rows);
    ws['!cols'] = [{wch:25},{wch:25},{wch:18},{wch:14},{wch:25},{wch:30},{wch:20}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Contatos');
    const fname = crmSource
      ? `CRM_${crmSource.replace(/[^a-z0-9]/gi,'_')}_${new Date().toISOString().slice(0,10)}.xlsx`
      : `CRM_completo_${new Date().toISOString().slice(0,10)}.xlsx`;
    XLSX.writeFile(wb, fname);
  } catch(e) { alert('Erro ao exportar: ' + e.message); }
}

async function deleteCrmListBtn() {
  if (!crmSource) return;
  if (!confirm(`Excluir TODOS os contatos da lista "${crmSource}"?\n\nEsta ação não pode ser desfeita.`)) return;
  try {
    const r = await fetch('crm.php?action=delete_list', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({source: crmSource})
    });
    const d = await r.json();
    if (d.ok) {
      toast(`${d.deleted} contatos excluídos.`, 'success');
      crmSource = '';
      await loadCrmSources();
      const sel = document.getElementById('crm-source-select');
      if (sel) sel.value = '';
      document.getElementById('btn-rename-list').style.display = 'none';
      document.getElementById('btn-delete-list').style.display = 'none';
      crmLoad(1);
      refreshCrmLists();
    } else {
      alert('❌ Erro: ' + (d.error || 'Falha ao excluir'));
    }
  } catch(e) { alert('❌ Erro de rede: ' + e.message); }
}

// Injetar source no crmLoad
const _origCrmLoad = crmLoad;

// Preencher dropdown de listas no Disparo
async function refreshCrmLists() {
  try {
    const r = await fetch('crm.php?action=sources');
    const d = await r.json();
    if (!d.ok) return;
    const sources = d.sources || [];

    // Atualiza dropdown do Disparo (checkboxes)
    const box = document.getElementById('crm-lists-checkboxes');
    if (box) {
      if (!sources.length) {
        box.innerHTML = '<div style="padding:12px;text-align:center;color:var(--cinza);font-size:.82rem">Nenhuma lista no CRM ainda</div>';
      } else {
        box.innerHTML = sources.map(s => `
          <label style="display:flex;align-items:center;gap:10px;padding:8px 14px;cursor:pointer;transition:background .1s;font-size:.84rem" onmouseover="this.style.background='var(--creme)'" onmouseout="this.style.background='none'">
            <input type="checkbox" class="crm-list-cb" value="${esc(s.source)}" data-total="${s.total}" style="width:15px;height:15px;cursor:pointer;accent-color:var(--verde)" onchange="updateCrmListsSummary()">
            <span style="flex:1">${esc(s.source)}</span>
            <span style="font-size:.75rem;color:var(--cinza);background:var(--creme);padding:2px 8px;border-radius:10px">${s.total} contatos</span>
          </label>
        `).join('');
      }
      updateCrmListsSummary();
    }

    // Atualiza dropdown do CRM (select)
    const sel = document.getElementById('crm-source-select');
    if (sel) {
      const cur = sel.value;
      sel.innerHTML = '<option value="">Todos os contatos</option>' +
        sources.map(s => `<option value="${esc(s.source)}">${esc(s.source)} (${s.total})</option>`).join('');
      if (cur) sel.value = cur;
    }

    // Atualiza dropdown do Disparo legado (para orgSendToCRM)
    const selD = document.getElementById('crm-list-select');
    if (selD) {
      selD.innerHTML = '<option value="">— Selecionar lista salva —</option>' +
        sources.map(s => `<option value="${esc(s.source)}">${esc(s.source)} (${s.total})</option>`).join('');
    }
  } catch(e) {}
}

function updateCrmListsSummary() {
  const cbs = document.querySelectorAll('.crm-list-cb:checked');
  const summary = document.getElementById('crm-lists-summary');
  if (!summary) return;
  if (!cbs.length) {
    summary.textContent = 'Nenhuma lista selecionada';
  } else {
    const total = [...cbs].reduce((acc, cb) => acc + parseInt(cb.dataset.total || 0), 0);
    const names = [...cbs].map(cb => cb.value).join(', ');
    summary.textContent = `${cbs.length} lista(s) · ${total} contatos — ${names.length > 50 ? names.slice(0,50)+'…' : names}`;
  }
}

function selectAllCrmLists(checked) {
  document.querySelectorAll('.crm-list-cb').forEach(cb => cb.checked = checked);
  updateCrmListsSummary();
}

async function loadFromCrmLists() {
  const cbs = [...document.querySelectorAll('.crm-list-cb:checked')];
  if (!cbs.length) { toast('Selecione pelo menos uma lista.', 'warn'); return; }

  const btn = document.querySelector('[onclick="loadFromCrmLists()"]');
  btnLoading(btn, true);

  try {
    const allContacts = [];
    const seenPhones = new Set();
    let totalDupes = 0;

    for (const cb of cbs) {
      const source = cb.value;
      const r = await fetch(`crm.php?action=list&source=${encodeURIComponent(source)}&status=all&per=9999&page=1`);
      const d = await r.json();
      if (!d.ok || !d.contacts?.length) continue;

      for (const c of d.contacts) {
        if (seenPhones.has(c.phone)) { totalDupes++; continue; }
        seenPhones.add(c.phone);
        allContacts.push({
          phone:   c.phone,
          nome:    c.name || '',
          empresa: c.empresa || '',
          cidade:  '',
        });
      }
    }

    if (!allContacts.length) { toast('Nenhum contato encontrado nas listas selecionadas.', 'warn'); return; }

    csvContacts = allContacts;
    manualContacts = [];
    document.getElementById('manual-phones').value = '';
    rebuildContacts();

    const listNames = cbs.map(cb => cb.value).join(', ');
    const msg = `📋 ${contacts.length} contatos carregados (${cbs.length} lista(s))${totalDupes > 0 ? ` · ${totalDupes} duplicatas removidas` : ''}.`;
    toast(msg.replace('📋 ',''), 'success');

    document.getElementById('send-progress').style.display = 'block';
    document.getElementById('progress-log').innerHTML = `<div class="log-line info">📋 ${contacts.length} contatos · ${cbs.length} lista(s): ${esc(listNames)}${totalDupes>0?` · ${totalDupes} duplicatas removidas`:''}</div>`;

  } catch(e) {
    toast('Erro ao carregar listas do CRM: ' + e.message, 'error');
  } finally {
    btnLoading(btn, false);
  }
}

async function loadFromCrmList() {
  // Mantido para compatibilidade com orgSendToCRM
  const sel = document.getElementById('crm-list-select');
  if (!sel || !sel.value) return;
  const cb = document.querySelector(`.crm-list-cb[value="${sel.value}"]`);
  if (cb) { cb.checked = true; updateCrmListsSummary(); }
  await loadFromCrmLists();
}

/* ── CRM ─────────────────────────────────── */
let crmStatus = 'all';
let crmPage   = 1;
let crmSearch = '';
let crmCampaign = '';
let crmSearchTimer = null;
let crmDispatched  = '';      // '1' = só contatos que receberam disparos
window._crmView    = 'list';  // inicializado aqui para estar disponível no primeiro crmLoad

async function loadCrmCampaigns() {
  try {
    const r = await fetch('crm.php?action=campaigns', {credentials:'same-origin'});
    const d = await r.json();
    if (!d.ok) return;
    const sel = document.getElementById('crm-campaign-select');
    if (!sel) return;
    const cur = sel.value;
    sel.innerHTML = '<option value="">Todas as campanhas</option>' +
      (d.campaigns||[]).map(c =>
        `<option value="${esc(c.campaign)}">${esc(c.campaign)} (${c.total})</option>`
      ).join('');
    if (cur) sel.value = cur;
  } catch(e) {}
}

function crmSetCampaign(c) {
  crmCampaign = c;
  crmLoad(1);
}

const STATUS_LABELS = {
  pendente:   '🕐 Pendente',
  em_contato: '💬 Em Contato',
  ganho:      '✅ Ganho',
  perdido:    '❌ Perdido',
};

async function crmLoad(page = 1) {
  crmPage = page;
  const body = document.getElementById('crm-body');
  body.innerHTML = '<em style="color:var(--cinza)">Carregando…</em>';
  const isKanban = window._crmView === 'kanban';
  const params = new URLSearchParams({
    action: 'list',
    status: crmStatus === 'dispatched' ? 'all' : (isKanban ? 'all' : crmStatus),
    search: crmSearch, source: crmSource, campaign: crmCampaign,
    dispatched: crmDispatched,
    page: isKanban ? 1 : crmPage,
    per: isKanban ? 200 : 50
  });
  try {
    const r = await fetch('crm.php?' + params);
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch(e) {
      const hasTable = text.includes("doesn't exist") || text.includes("Table") || text.includes("SQLSTATE");
      body.innerHTML = `<div class="alert error" style="margin:0">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
          ${hasTable
            ? `<strong>Tabela "contacts" não existe no banco.</strong><br>
               <span style="font-size:.82rem">Rode o SQL abaixo no phpMyAdmin:<br>
               <code style="background:rgba(0,0,0,.08);padding:2px 6px;border-radius:4px;font-size:.75rem;display:block;margin-top:6px;white-space:pre-wrap">CREATE TABLE IF NOT EXISTS contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(30) NOT NULL,
  name VARCHAR(255) DEFAULT NULL,
  status ENUM('pendente','em_contato','ganho','perdido') DEFAULT 'pendente',
  notes TEXT DEFAULT NULL,
  source VARCHAR(255) DEFAULT NULL,
  job_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</code></span>`
            : `<strong>Erro no servidor:</strong> ${esc(text.slice(0,300))}`
          }
        </div>
      </div>`;
      return;
    }
    if (!d.ok) {
      body.innerHTML = `<div class="alert error" style="margin:0"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg><div><strong>Erro:</strong> ${esc(d.error || 'Falha desconhecida')}</div></div>`;
      return;
    }

    // Atualiza contadores nas tabs
    const c = d.counts || {};
    ['all','pendente','em_contato','ganho','perdido'].forEach(s => {
      const el = document.getElementById('cnt-' + s);
      if (el) el.textContent = c[s] ?? 0;
    });

    if (!d.contacts.length) {
      body.innerHTML = `<div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <h4>Nenhum contato aqui</h4>
        <p style="margin-top:4px;font-size:.85rem">Os contatos aparecem automaticamente após cada disparo.</p>
      </div>`;
      document.getElementById('crm-pagination').style.display = 'none';
      return;
    }

    // Guarda globalmente para o kanban também usar
    window._crmLastData = d;

    if (window._crmView === 'kanban') {
      renderKanban(d.contacts, body);
    } else {
      renderList(d.contacts, body);
    }

    // Botão excluir lista (se filtrado por source) — acrescenta após o render
    if (crmSource && window._crmView !== 'kanban') {
      body.innerHTML += `<div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--borda);display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:.8rem;color:var(--cinza)">Lista: <strong>${esc(crmSource)}</strong> · ${d.total} contatos</span>
        <button class="btn btn-danger" style="font-size:.78rem;padding:7px 12px" onclick="crmDeleteList('${esc(crmSource)}')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
          Excluir lista inteira
        </button>
      </div>`;
    }


    // Paginação (oculta no kanban)
    const pg   = document.getElementById('crm-pagination');
    const info = document.getElementById('crm-total-info');
    const prev = document.getElementById('crm-prev');
    const next = document.getElementById('crm-next');
    pg.style.display = isKanban ? 'none' : 'flex';
    const from = (crmPage-1)*50+1, to = Math.min(crmPage*50, d.total);
    info.textContent = `Mostrando ${from}–${to} de ${d.total} contatos`;
    prev.disabled = crmPage <= 1;
    next.disabled = to >= d.total;

    // Guarda contacts para popup e kanban
    window._crmContactsCache = window._crmContactsCache || {};
    d.contacts.forEach(c => { window._crmContactsCache[c.id] = c; });

  } catch(e) {
    console.error('[crmLoad] Erro:', e);
    body.innerHTML = `<em style="color:var(--error)">Erro: ${e?.message || 'Erro de rede.'}</em>`;
  }
}

function crmStatusPills(id, status) {
  const statuses = [
    { v:'pendente',   l:'Pendente'   },
    { v:'em_contato', l:'Em Contato' },
    { v:'ganho',      l:'Ganho'      },
    { v:'perdido',    l:'Perdido'    },
  ];
  return `<div class="crm-pill-wrap">${statuses.map(s =>
    `<button class="crm-pill ${s.v}${s.v===status?' active':''}"
      onclick="crmUpdateStatus(${id},'${s.v}')"
      title="${s.l}">${s.l}</button>`
  ).join('')}</div>`;
}

async function crmUpdateStatus(id, status) {
  await fetch('crm.php?action=update', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, status})
  });
  const wrap = document.getElementById('crm-status-'+id);
  if (wrap) wrap.innerHTML = crmStatusPills(id, status);
  if (window._crmContactsCache?.[id]) window._crmContactsCache[id].status = status;
  // Atualiza pill no popup se aberto
  const popupStatus = document.getElementById('popup-status-pills');
  if (popupStatus && popupStatus.dataset.id == id) {
    popupStatus.innerHTML = crmStatusPills(id, status);
    popupStatus.dataset.status = status;
    document.getElementById('popup-del-btn').style.display = status === 'perdido' ? '' : 'none';
  }
  crmLoad(crmPage);
}

/* ── POPUP Lead ─────────────────────────── */
async function crmOpenPopup(id) {
  const c = window._crmContactsCache?.[id];
  if (!c) return;
  window._popupContactId = id;
  const popup = document.getElementById('crm-popup');

  // Preenche dados básicos imediatamente
  document.getElementById('popup-empresa').textContent = c.empresa || c.name || '—';
  document.getElementById('popup-phone').textContent   = c.phone;
  document.getElementById('popup-source').textContent  = c.source || '—';
  document.getElementById('popup-date').textContent    = (c.created_at||'—').slice(0,16);
  document.getElementById('popup-name-input').value   = c.name || '';
  document.getElementById('popup-notes-input').value  = c.notes || '';
  document.getElementById('popup-save-btn').dataset.id = id;
  document.getElementById('popup-del-btn').dataset.id  = id;
  document.getElementById('popup-del-btn').style.display = c.status === 'perdido' ? '' : 'none';

  // Botão de bloqueio
  const blockBtn  = document.getElementById('popup-block-btn');
  const blockText = document.getElementById('popup-block-text');
  if (c.blocked == 1) {
    blockBtn.style.cssText = 'background:#fef2f2;border-color:#fca5a5;color:#c62828';
    blockText.textContent = '🚫 Bloqueado — Desbloquear';
  } else {
    blockBtn.style.cssText = '';
    blockText.textContent = 'Bloquear de disparos';
  }
  window._popupBlocked = (c.blocked == 1);

  // Status pills
  const ps = document.getElementById('popup-status-pills');
  ps.innerHTML = crmStatusPills(id, c.status);
  ps.dataset.id = id; ps.dataset.status = c.status;

  // Placeholder enquanto carrega campanhas
  const campEl = document.getElementById('popup-campaign');
  campEl.innerHTML = '<em style="color:var(--cinza);font-size:.8rem">Carregando campanhas…</em>';

  popup.style.display = 'flex';

  // Busca histórico completo de campanhas deste contato
  try {
    const r = await fetch(`crm.php?action=contact_campaigns&phone=${encodeURIComponent(c.phone)}`, {credentials:'same-origin'});
    const d = await r.json();
    const list = d.campaigns || [];

    if (!list.length) {
      campEl.innerHTML = `
        <div style="color:var(--cinza);font-size:.82rem;padding:8px 0">
          Nenhum disparo registrado para este contato.
        </div>`;
    } else {
      const statusIcon = { sent:'✅', error:'❌', queued:'⏳' };
      const statusLabel = { sent:'Enviado', error:'Erro no envio', queued:'Ficou na fila' };
      const statusColor = { sent:'#15803d', error:'#c62828', queued:'#b45309' };
      const statusBg    = { sent:'#f0fdf4', error:'#fef2f2', queued:'#fffbeb' };

      campEl.innerHTML = `
        <div style="margin-bottom:6px;font-size:.68rem;font-weight:700;color:var(--cinza);text-transform:uppercase;letter-spacing:.08em">
          ${list.length} disparo${list.length>1?'s':''} registrado${list.length>1?'s':''}
        </div>
        <div style="display:flex;flex-direction:column;gap:7px;max-height:200px;overflow-y:auto;padding-right:2px">
          ${list.map(camp => {
            const st  = camp.contact_status || 'sent';
            const dt  = (camp.started_at||camp.sent_at||'').slice(0,16).replace('T',' ');
            const bg  = statusBg[st]  || '#f0fdf4';
            const col = statusColor[st] || '#15803d';
            const ico = statusIcon[st] || '📢';
            const lbl = statusLabel[st] || 'Enviado';
            const errMsg = camp.error_msg ? `<div style="font-size:.68rem;color:#c62828;margin-top:2px">↳ ${esc(camp.error_msg)}</div>` : '';
            return `
              <div style="background:${bg};border:1px solid ${col}22;border-left:3px solid ${col};border-radius:8px;padding:8px 11px">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                  <div style="font-weight:600;font-size:.82rem;color:#1a1a1a;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    📢 ${esc(camp.job_name)}
                  </div>
                  <div style="font-size:.7rem;font-weight:700;color:${col};white-space:nowrap;flex-shrink:0">
                    ${ico} ${lbl}
                  </div>
                </div>
                <div style="font-size:.72rem;color:var(--cinza);margin-top:3px;font-family:var(--font-mono)">${dt||'—'}</div>
                ${errMsg}
              </div>`;
          }).join('')}
        </div>`;
    }
  } catch(e) {
    campEl.innerHTML = '<span style="color:var(--cinza);font-size:.8rem">Não foi possível carregar campanhas.</span>';
  }
}

function crmClosePopup() {
  document.getElementById('crm-popup').style.display = 'none';
}

/* Abre conversa no WhatsApp a partir do popup do CRM */
function openChatFromCrm() {
  const phone = document.getElementById('popup-phone')?.textContent?.trim();
  if (!phone) return;
  crmClosePopup();
  openChatFromPhone(phone);
}

/* Navega para Conversas e abre o chat do número */
async function openChatFromPhone(phone) {
  // Verifica status via dot de status (verde = conectado)
  const dot = document.getElementById('status-dot');
  const connected = dot && (dot.style.background === 'var(--success)' || dot.classList.contains('connected'));
  if (dot && !connected) {
    toast('WhatsApp desconectado. Conecte primeiro na aba Conexão.', 'warn', 4000);
    return;
  }

  // Normaliza o telefone
  const normalized = phone.replace(/[^0-9]/g, '');

  // Navega para Conversas
  goPage('conversas');

  // Aguarda render da página e abre o chat
  setTimeout(async () => {
    try {
      // Recarrega lista de chats para incluir este contato
      if (typeof loadChatList === 'function') await loadChatList();

      // Aguarda a lista aparecer e procura o contato
      setTimeout(() => {
        // Tenta abrir diretamente pelo phone
        if (typeof openChat === 'function') {
          openChat(normalized);
          return;
        }
        // Fallback: clica no item da lista
        const items = document.querySelectorAll('.chat-item');
        for (const item of items) {
          const p = (item.dataset.phone || '').replace(/[^0-9]/g,'');
          if (p === normalized || p.slice(-9) === normalized.slice(-9)) {
            item.click();
            return;
          }
        }
        // Se não achou na lista, avisa mas já está na tela de conversas
        toast('Conversa aberta. Busque o contato ' + phone + ' na lista.', 'info', 4000);
      }, 700);
    } catch(e) {
      toast('Erro ao abrir conversa.', 'error');
    }
  }, 350);
}

async function crmSavePopup() {
  const id    = parseInt(document.getElementById('popup-save-btn').dataset.id);
  const name  = document.getElementById('popup-name-input').value.trim();
  const notes = document.getElementById('popup-notes-input').value.trim();
  await fetch('crm.php?action=update', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, name, notes})
  });
  if (window._crmContactsCache?.[id]) {
    window._crmContactsCache[id].name  = name;
    window._crmContactsCache[id].notes = notes;
  }
  crmClosePopup(); crmLoad(crmPage);
}

async function crmDeleteLeadPopup() {
  const id = parseInt(document.getElementById('popup-del-btn').dataset.id);
  const c  = window._crmContactsCache?.[id];
  if (!c || c.status !== 'perdido') { alert('Só é possível excluir leads com status "Perdido".'); return; }
  if (!confirm(`Excluir permanentemente o lead ${c.phone}?`)) return;
  await fetch('crm.php?action=delete_lead', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id})
  });
  crmClosePopup(); crmLoad(crmPage);
}

async function crmDeleteList(source) {
  if (!confirm(`Excluir TODOS os ${d?.total||''} contatos da lista "${source}"?\n\nEsta ação não pode ser desfeita.`)) return;
  const r = await fetch('crm.php?action=delete_list', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({source})
  });
  const data = await r.json();
  if (data.ok) { toast(`${data.deleted} contatos excluídos.`, 'success'); crmSource = ''; crmLoad(1); loadCrmSources(); }
  else alert('Erro: ' + (data.error||'?'));
}

/* ── CRM VIEW MODE ────────────────────────────────────── */

function setCrmView(mode) {
  window._crmView = mode;
  document.getElementById('btn-view-list').classList.toggle('active', mode === 'list');
  document.getElementById('btn-view-kanban').classList.toggle('active', mode === 'kanban');
  // Esconde/mostra tabs de status (no kanban as 4 colunas já mostram tudo)
  document.querySelector('.crm-tabs').style.display = mode === 'kanban' ? 'none' : '';
  document.getElementById('crm-pagination').style.display = 'none';
  if (window._crmLastData) {
    const body = document.getElementById('crm-body');
    if (mode === 'kanban') renderKanban(window._crmLastData.contacts, body);
    else renderList(window._crmLastData.contacts, body);
  } else {
    crmLoad(1);
  }
}

/* ── Cor de avatar por inicial ── */
function avatarColor(str) {
  const colors = ['#1a5c2a','#2563eb','#7c3aed','#b45309','#be185d','#0f766e','#c2410c'];
  const s = (str ?? '').toString();
  let h = 0;
  for (let i=0;i<s.length;i++) h = (h*31+s.charCodeAt(i)) & 0xFFFFFFFF;
  return colors[Math.abs(h) % colors.length];
}

/* ── RENDER LISTA (tabela original) ────────────────────── */
function renderList(contacts, body) {
  try {
  if (!contacts.length) {
    body.innerHTML = `<div class="empty-state">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      <h4>Nenhum contato aqui</h4></div>`;
    return;
  }
  let html = `<table class="crm-table">
    <thead><tr>
      <th>Empresa / Nome</th><th>Telefone</th><th>Status</th><th>Última campanha</th><th>Observação</th>
    </tr></thead><tbody>`;
  contacts.forEach(c => {
    const camp = c.last_campaign
      ? `<div style="font-size:.78rem;color:#1a5c2a;font-weight:600;background:#f0f7f0;padding:2px 7px;border-radius:6px;display:inline-block;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">📢 ${esc(c.last_campaign)}</div>
         <div style="font-size:.68rem;color:var(--cinza);margin-top:1px">${(c.last_dispatched_at||'').slice(0,16).replace('T',' ')}</div>`
      : `<span style="color:var(--cinza);font-size:.78rem">—</span>`;
    const blockedBadge = c.blocked==1 ? `<span style="background:#fef2f2;color:#c62828;border:1px solid #fca5a5;padding:1px 5px;border-radius:6px;font-size:.62rem;font-weight:700;margin-right:4px">🚫</span>` : '';
    const initials = (String(c.empresa||c.name||'?')[0]||'?').toUpperCase();
    html += `<tr id="crmrow-${c.id}" onclick="crmOpenPopup(${c.id})" style="cursor:pointer;${c.blocked==1?'opacity:.55;':''}">
      <td>
        <div style="display:flex;align-items:center;gap:9px">
          <div class="kcard-avatar" style="background:${avatarColor(c.empresa||c.name||'')};width:28px;height:28px;font-size:.7rem;flex-shrink:0">${initials}</div>
          <div>
            <div style="font-weight:700;font-size:.85rem;color:var(--verde-800)">${blockedBadge}${esc(c.empresa||'—')}</div>
            <div style="font-size:.76rem;color:var(--cinza)">${esc(c.name||'')}</div>
          </div>
        </div>
      </td>
      <td><div class="phone">${esc(c.phone)}</div></td>
      <td><div class="crm-status-wrap" id="crm-status-${c.id}" onclick="event.stopPropagation()">${crmStatusPills(c.id, c.status)}</div></td>
      <td>${camp}</td>
      <td><div style="font-size:.78rem;color:var(--cinza);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(c.notes||'—')}</div></td>
    </tr>`;
  });
  html += '</tbody></table>';
  body.innerHTML = html;
  } catch(e) { console.error('[renderList]', e); body.innerHTML = `<em style="color:red">Erro renderList: ${e.message}</em>`; }
}

/* ── RENDER KANBAN (Pipedrive-style) ─────────────────── */
function renderKanban(contacts, body) {
  const cols = [
    { key:'pendente',   label:'🕐 Pendente',   cls:'pendente'   },
    { key:'em_contato', label:'💬 Em Contato',  cls:'em_contato' },
    { key:'ganho',      label:'✅ Ganho',       cls:'ganho'      },
    { key:'perdido',    label:'❌ Perdido',     cls:'perdido'    },
  ];

  const byStatus = {};
  cols.forEach(col => byStatus[col.key] = []);
  contacts.forEach(c => {
    if (byStatus[c.status] !== undefined) byStatus[c.status].push(c);
    else byStatus['pendente'].push(c);
  });

  const totalVal = contacts.length || 1;

  let html = `<div class="kanban-wrap" id="kanban-board">`;
  cols.forEach(col => {
    const cards = byStatus[col.key];
    html += `
      <div class="kanban-col" data-status="${col.key}">
        <div class="kanban-col-head ${col.cls}">
          <span>${col.label}</span>
          <span class="kcnt">${cards.length}</span>
        </div>
        <div class="kanban-cards" id="kcol-${col.key}"
          ondragover="event.preventDefault();this.classList.add('drag-over')"
          ondragleave="this.classList.remove('drag-over')"
          ondrop="kanbanDrop(event,'${col.key}')">
          ${cards.length === 0
            ? `<div style="text-align:center;padding:20px 10px;color:var(--cinza);font-size:.78rem">Nenhum contato</div>`
            : cards.map(c => kanbanCard(c)).join('')
          }
        </div>
      </div>`;
  });
  html += `</div>`;
  body.innerHTML = html;
}

function kanbanCard(c) {
  const initials = (String(c.empresa||c.name||'?')[0]||'?').toUpperCase();
  const campTag  = c.last_campaign
    ? `<div class="kcard-camp" title="${esc(c.last_campaign)}">📢 ${esc(c.last_campaign.length>22?c.last_campaign.slice(0,22)+'…':c.last_campaign)}</div>`
    : '';
  const notesTag = c.notes
    ? `<div class="kcard-notes">${esc(c.notes)}</div>`
    : '';
  const blockedTag = c.blocked==1
    ? `<div class="kcard-blocked">🚫 Bloqueado</div>` : '';
  const dt = c.last_dispatched_at
    ? `<span style="font-size:.66rem;color:var(--cinza);margin-left:4px">${c.last_dispatched_at.slice(0,10)}</span>` : '';

  return `
    <div class="kcard" id="kcard-${c.id}"
      draggable="true"
      ondragstart="kanbanDragStart(event,${c.id})"
      ondragend="kanbanDragEnd(event)"
      onclick="crmOpenPopup(${c.id})"
      style="${c.blocked==1?'opacity:.55;':''}">
      ${blockedTag}
      <div class="kcard-top">
        <div>
          <div class="kcard-empresa">${esc(c.empresa||c.name||'—')}</div>
          ${c.empresa&&c.name ? `<div style="font-size:.74rem;color:var(--cinza)">${esc(c.name)}</div>` : ''}
        </div>
        <div class="kcard-avatar" style="background:${avatarColor(c.empresa||c.name||'')}">${initials}</div>
      </div>
      <div class="kcard-phone">${esc(c.phone)}</div>
      ${campTag}${dt}
      ${notesTag}
      <div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--borda);display:flex;justify-content:flex-end">
        <button onclick="event.stopPropagation();openChatFromPhone('${esc(c.phone)}')"
          style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:4px 9px;color:#15803d;cursor:pointer;font-size:.7rem;font-weight:600;display:flex;align-items:center;gap:4px"
          title="Abrir conversa no WhatsApp">
          <svg viewBox="0 0 24 24" fill="currentColor" width="12" height="12"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M11.5 2C6.253 2 2 6.253 2 11.5c0 1.87.518 3.618 1.418 5.112L2 22l5.558-1.396A9.458 9.458 0 0 0 11.5 21C16.747 21 21 16.747 21 11.5S16.747 2 11.5 2z"/></svg>
          Chat
        </button>
      </div>
    </div>`;
}

/* ── DRAG & DROP Kanban ──────────────────────────────── */
let _dragId = null;

function kanbanDragStart(e, id) {
  _dragId = id;
  e.dataTransfer.effectAllowed = 'move';
  setTimeout(() => { const el = document.getElementById('kcard-'+id); if(el) el.classList.add('dragging'); }, 0);
}
function kanbanDragEnd(e) {
  document.querySelectorAll('.kcard').forEach(el => el.classList.remove('dragging'));
  document.querySelectorAll('.kanban-cards').forEach(el => el.classList.remove('drag-over'));
}
async function kanbanDrop(e, newStatus) {
  e.preventDefault();
  e.currentTarget.classList.remove('drag-over');
  if (!_dragId) return;
  const id = _dragId;
  _dragId = null;
  const c = window._crmContactsCache?.[id];
  if (!c || c.status === newStatus) return;

  // Otimista: move o card visualmente antes da resposta
  const card = document.getElementById('kcard-'+id);
  const dest = document.getElementById('kcol-'+newStatus);
  if (card && dest) {
    const placeholder = dest.querySelector('[style*="Nenhum contato"]');
    if (placeholder) placeholder.remove();
    dest.appendChild(card);
    // Atualiza contadores
    ['pendente','em_contato','ganho','perdido'].forEach(s => {
      const col = document.querySelector(`.kanban-col[data-status="${s}"] .kcnt`);
      if (col) {
        const n = document.getElementById('kcol-'+s)?.querySelectorAll('.kcard').length || 0;
        col.textContent = n;
      }
    });
  }

  // Persiste no banco
  try {
    await fetch('crm.php?action=update', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, status: newStatus })
    });
    if (c) c.status = newStatus;
    toast(`Movido para ${newStatus.replace('_',' ')}`, 'success', 2000);
  } catch(err) {
    toast('Erro ao salvar status', 'error');
  }
}

function crmSetStatus(status, btn) {
  crmStatus = status;
  crmDispatched = (status === 'dispatched') ? '1' : '';
  document.querySelectorAll('.crm-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  crmLoad(1);
}

function crmSearchDebounce() {
  clearTimeout(crmSearchTimer);
  crmSearchTimer = setTimeout(() => {
    crmSearch = document.getElementById('crm-search-input').value.trim();
    crmLoad(1);
  }, 400);
}

/* ── Limpar histórico ───────────────────── */
function showClearHistory() {
  document.getElementById('clear-history-modal').style.display = 'flex';
  document.getElementById('clear-history-pw').value = '';
  document.getElementById('clear-history-pw').focus();
}
function closeClearHistory() {
  document.getElementById('clear-history-modal').style.display = 'none';
}
async function confirmClearHistory() {
  const pw = document.getElementById('clear-history-pw').value;
  if (!pw) return;
  const r = await fetch('historico.php?action=clear', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({password: pw})
  });
  const d = await r.json();
  if (d.ok) { alert('✅ Histórico apagado.'); closeClearHistory(); loadHistorico(); }
  else alert('❌ ' + (d.error||'Senha incorreta'));
}

function crmExport() {
  const url = `crm.php?action=export&status=${crmStatus}`;
  window.open(url, '_blank');
}

/* ── DASHBOARD DINÂMICO ─────────────────── */
async function loadDashboard() {
  try {
    const r = await fetch('dashboard.php', {credentials:'same-origin'});
    const d = await r.json();
    if (!d.ok) return;
    const m = d.metrics;

    // Métricas principais
    setDash('dash-m-jobs',     m.total_jobs);
    setDash('dash-m-sent',     m.total_sent);
    setDash('dash-m-rate',     m.success_rate + '%');
    setDash('dash-m-crm',      m.crm_total);
    setDash('dash-m-ganhos',   m.crm_ganho);
    setDash('dash-m-conv',     m.crm_total > 0 ? Math.round(m.crm_ganho / m.crm_total * 100) + '%' : '0%');
    setDash('dash-m-errors',   m.total_errors + ' falhas no total');
    setDash('dash-m-negoc',    m.crm_em_contato + ' em negociação');

    // Medidor de limite diário
    const sentToday = m.sent_today || 0;
    const isBusiness = window._cfgIsBusiness === true;
    const limit = window._cfgDailyLimit || (isBusiness ? 1000 : 500);
    const pct = Math.min(100, Math.round(sentToday / limit * 100));
    setDash('daily-sent-num', sentToday);
    setDash('daily-limit-num', limit.toLocaleString('pt-BR'));
    setWidth('daily-bar', pct);
    const barEl = document.getElementById('daily-bar');
    const msgEl = document.getElementById('daily-status-msg');
    if (barEl) {
      if (pct >= 90)      barEl.style.background = 'linear-gradient(90deg,#dc2626,#991b1b)';
      else if (pct >= 70) barEl.style.background = 'linear-gradient(90deg,#f59e0b,#c8a96e)';
      else                barEl.style.background = 'linear-gradient(90deg,#5a8a5a,#1a5c2a)';
    }
    if (msgEl) {
      if (pct >= 100)     msgEl.innerHTML = `<span style="color:#dc2626;font-weight:600">🚫 Limite diário atingido. Aguarde até amanhã para mais disparos.</span>`;
      else if (pct >= 90) msgEl.innerHTML = `<span style="color:#dc2626;font-weight:600">⚠️ Próximo do limite. Restam ${limit-sentToday} disparos seguros.</span>`;
      else if (pct >= 70) msgEl.innerHTML = `<span style="color:#b8860b;font-weight:600">⚠️ Atenção: já usou ${pct}% do recomendado. Restam ${limit-sentToday}.</span>`;
      else                msgEl.innerHTML = `✅ Você ainda pode disparar até <strong>${limit-sentToday}</strong> mensagens hoje com segurança.`;
    }

    // Pipeline
    const tot = m.crm_total || 1;
    setWidth('dash-pip-pend',  Math.round(m.crm_pendente   / tot * 100));
    setWidth('dash-pip-cont',  Math.round(m.crm_em_contato / tot * 100));
    setWidth('dash-pip-ganh',  Math.round(m.crm_ganho      / tot * 100));
    setWidth('dash-pip-perd',  Math.round(m.crm_perdido    / tot * 100));
    setDash('dash-pip-pend-n', m.crm_pendente);
    setDash('dash-pip-cont-n', m.crm_em_contato);
    setDash('dash-pip-ganh-n', m.crm_ganho);
    setDash('dash-pip-perd-n', m.crm_perdido);
    setDash('dash-pip-pend-p', Math.round(m.crm_pendente   / tot * 100) + '%');
    setDash('dash-pip-cont-p', Math.round(m.crm_em_contato / tot * 100) + '%');
    setDash('dash-pip-ganh-p', Math.round(m.crm_ganho      / tot * 100) + '%');
    setDash('dash-pip-perd-p', Math.round(m.crm_perdido    / tot * 100) + '%');

    // Últimas campanhas
    const campanha = document.getElementById('dash-campanhas');
    if (campanha) {
      if (!m.jobs_recent?.length) {
        campanha.innerHTML = `<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg><h4>Nenhum disparo ainda</h4><p style="font-size:.82rem;margin-top:4px"><a onclick="goPage('disparo')" style="cursor:pointer;color:var(--verde);font-weight:600">Criar primeiro disparo →</a></p></div>`;
      } else {
        let html = `<table class="recent-table"><thead><tr><th>Campanha</th><th>Env.</th><th>%</th><th>Quando</th></tr></thead><tbody>`;
        m.jobs_recent.forEach(j => {
          const pct = j.total > 0 ? Math.round(j.sent / j.total * 100) : 0;
          html += `<tr><td class="name" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(j.job_name)}</td><td class="date-mono">${j.sent}/${j.total}</td><td><span class="badge ${pct>=80?'ok':'zero'}">${pct}%</span></td><td class="date-mono">${fmtRelative(j.started_at)}</td></tr>`;
        });
        html += `</tbody></table>`;
        campanha.innerHTML = html;
      }
    }

    // Contatos recentes
    const recentes = document.getElementById('dash-crm-recent');
    if (recentes && m.crm_recent?.length) {
      const statusCls = {pendente:'zero',em_contato:'',ganho:'ok',perdido:'err'};
      const statusLbl = {pendente:'Pendente',em_contato:'Em Contato',ganho:'Ganho',perdido:'Perdido'};
      let html = `<table class="recent-table"><thead><tr><th>Contato</th><th>Status</th><th>Lista</th></tr></thead><tbody>`;
      m.crm_recent.forEach(c => {
        html += `<tr><td><div style="font-weight:600;font-size:.85rem">${esc(c.empresa||c.name||c.phone)}</div>${c.empresa&&c.name?`<div class="date-mono">${esc(c.phone)}</div>`:''}</td><td><span class="badge ${statusCls[c.status]||''}">${statusLbl[c.status]||c.status}</span></td><td class="date-mono" style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc((c.source||'—').slice(0,18))}</td></tr>`;
      });
      html += `</tbody></table>`;
      recentes.innerHTML = html;
    }

  } catch(e) { console.error('loadDashboard error:', e); }
}

function setDash(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}
function setWidth(id, pct) {
  const el = document.getElementById(id);
  if (el) el.style.width = pct + '%';
}
function fmtRelative(d) {
  if (!d) return '—';
  const diff = Math.floor((Date.now() - new Date(d)) / 1000);
  if (diff < 60)        return 'há instantes';
  if (diff < 3600)      return 'há ' + Math.floor(diff/60) + ' min';
  if (diff < 86400)     return 'há ' + Math.floor(diff/3600) + ' h';
  if (diff < 604800)    return 'há ' + Math.floor(diff/86400) + ' dias';
  return new Date(d).toLocaleDateString('pt-BR');
}

/* ── CONVERSAS ───────────────────────────── */
let chatActive = null;      // phone ativo
let chatLastId = 0;         // último ID de mensagem visto
let chatPollInterval  = null;
let chatListData      = [];
let chatWAConnected   = null; // null=desconhecido, true=conectado, false=desconectado

/* ── Filtro local de busca ──────────────────────────────── */
function chatFilterList() {
  const q = (document.getElementById('chat-search')?.value || '').toLowerCase().trim();
  if (!q) { renderChatList(chatListData); return; }
  const filtered = chatListData.filter(c => {
    return (c.display_name || '').toLowerCase().includes(q) || (c.phone || '').includes(q);
  });
  renderChatList(filtered);
}

/* ── Status de conexão (banner + overlay no topo da lista) ── */
let _chatLastConnected = null; // null=desconhecido, true/false

async function checkChatConnection() {
  try {
    const r = await fetch('chat.php?action=connection_status', {credentials:'same-origin'});
    const d = await r.json();
    if (!d.ok) return;

    const isNowConnected = d.connected;
    const wasConnected   = _chatLastConnected;

    // Detecta mudança de estado
    const justConnected    = wasConnected === false && isNowConnected === true;
    const justDisconnected = wasConnected === true  && isNowConnected === false;

    _chatLastConnected = isNowConnected;
    chatWAConnected    = isNowConnected;

    // Atualiza banner
    const banner = document.getElementById('chat-conn-banner');
    const dot    = document.getElementById('chat-conn-dot');
    const txt    = document.getElementById('chat-conn-text');
    const overlay = document.getElementById('chat-offline-overlay');

    if (banner) {
      banner.style.display = 'flex';
      if (isNowConnected) {
        dot.style.background  = '#2e7d32';
        banner.style.background   = '#f0f7f0';
        banner.style.borderColor  = '#86c190';
        // Quando conectado, mostra o desde quando
        const since = d.connected_at
          ? new Date(d.connected_at).toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'})
          : null;
        txt.textContent = since ? `WhatsApp conectado desde ${since}` : 'WhatsApp conectado';
      } else {
        dot.style.background      = '#c62828';
        banner.style.background   = '#fff5f5';
        banner.style.borderColor  = '#f5c6c6';
        const since = d.disconnected_at
          ? new Date(d.disconnected_at).toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'})
          : null;
        txt.textContent = since
          ? `Desconectado às ${since} — novas mensagens pausadas`
          : 'WhatsApp desconectado — novas mensagens pausadas';
      }
    }

    // Overlay escurece a lista quando desconectado
    if (overlay) {
      overlay.style.display = isNowConnected ? 'none' : 'flex';
    }

    // Reconectou → recarrega lista automaticamente + toast
    if (justConnected) {
      await loadChatList();
      toast('✅ WhatsApp reconectado! Conversas atualizadas.', 'success');
    }

    // Desconectou → apenas toast de aviso
    if (justDisconnected) {
      toast('⚠️ WhatsApp desconectado. Novas mensagens não chegam até reconectar.', 'warn');
    }

  } catch(e) {}
}

/* ── Carregar lista de conversas ────────────────────────── */
async function loadChatList() {
  try {
    const r = await fetch('chat.php?action=list', {credentials:'same-origin'});
    const d = await r.json();
    if (!d.ok) return;
    chatListData = d.chats || [];
    renderChatList(chatListData);
    updateUnreadBadge();
  } catch(e) {}
}

function renderChatList(chats) {
  const body = document.getElementById('chat-list-body');
  if (!body) return;

  body.innerHTML = '';

  if (!chats.length) {
    body.innerHTML = '<div style="padding:24px;text-align:center;color:var(--cinza);font-size:.83rem">Nenhuma conversa ainda.<br>As mensagens recebidas aparecerão aqui.</div>';
    return;
  }

  const crmColors = { pendente:'#888', em_contato:'#1a5c2a', ganho:'#2e7d32', perdido:'#c62828' };
  const crmLabels = { pendente:'Pendente', em_contato:'Em Contato', ganho:'Ganho', perdido:'Perdido' };

  const fragment = document.createDocumentFragment();
  chats.forEach(c => {
    const name    = c.display_name || c.phone;
    const initial = (name[0] || '?').toUpperCase();
    const isActive = chatActive === c.phone;
    const unread  = parseInt(c.unread) || 0;
    const preview = c.preview || c.last_body || '';
    const lastMsg = c.last_from_me == 1 ? '✓ ' + preview : preview;
    const time    = c.last_at ? fmtRelative(c.last_at) : '';
    const crmSt   = c.crm_status;
    const inCRM   = c.in_crm;
    const div = document.createElement('div');
    div.className = `chat-item${isActive?' active':''}${unread>0?' unread':''}`;
    div.onclick = () => openChat(c.phone);
    div.innerHTML = `
      <div class="chat-avatar" style="background:${avatarColor(c.phone)}">${esc(initial)}</div>
      <div class="chat-item-body">
        <div class="chat-item-name">
          ${esc(name)}
          ${!inCRM ? '<span style="font-size:.65rem;color:var(--cinza);font-weight:500;margin-left:4px">• não no CRM</span>' : ''}
        </div>
        <div class="chat-item-last">${esc(lastMsg.slice(0,55))}</div>
      </div>
      <div class="chat-item-meta">
        <span class="chat-item-time">${esc(time)}</span>
        ${unread > 0 ? `<span class="chat-badge">${unread}</span>` : ''}
        ${crmSt ? `<span class="chat-crm-badge" style="background:${crmColors[crmSt]||'#888'}22;color:${crmColors[crmSt]||'#888'}">${crmLabels[crmSt]||crmSt}</span>` : ''}
      </div>`;
    fragment.appendChild(div);
  });
  body.appendChild(fragment);
}

function avatarColor(phone) {
  const colors = ['#1a5c2a','#2e7d32','#388e3c','#1565c0','#6a1b9a','#c62828','#e65100','#4527a0'];
  const n = parseInt((phone||'').toString().slice(-2)) || 0;
  return colors[n % colors.length];
}

function updateUnreadBadge() {
  const total = chatListData.reduce((acc, c) => acc + (parseInt(c.unread)||0), 0);
  const badge = document.getElementById('nav-unread-badge');
  if (!badge) return;
  if (total > 0) { badge.style.display = 'inline'; badge.textContent = total > 99 ? '99+' : total; }
  else badge.style.display = 'none';
}

/* ── Abrir conversa ──────────────────────────────────────── */
let _chatActiveInCRM = false;

async function openChat(phone) {
  chatActive = phone;
  chatCloseAddCRM(); // fecha modal se aberto
  document.getElementById('chat-empty-state').style.display = 'none';
  const active = document.getElementById('chat-active');
  active.style.display = 'flex';
  renderChatList(chatListData);

  const msgBody = document.getElementById('chat-messages-body');
  msgBody.innerHTML = '<div style="text-align:center;padding:20px;color:var(--cinza);font-size:.82rem">Carregando…</div>';

  try {
    const r = await fetch(`chat.php?action=messages&phone=${encodeURIComponent(phone)}`, {credentials:'same-origin'});
    const d = await r.json();
    if (!d.ok) return;

    _chatActiveInCRM = d.in_crm;

    // Cabeçalho
    const name    = d.contact?.empresa || d.contact?.name || d.phone_display || phone;
    const initial = (name[0]||'?').toUpperCase();
    document.getElementById('chat-active-avatar').textContent = initial;
    document.getElementById('chat-active-avatar').style.background = avatarColor(phone);
    document.getElementById('chat-active-name').textContent = name;
    document.getElementById('chat-active-sub').textContent  = d.phone_display || phone;

    // Badge CRM
    const crmBadge = document.getElementById('chat-active-crm');
    if (d.contact?.status) {
      const crmColors = {pendente:'#888',em_contato:'#1a5c2a',ganho:'#2e7d32',perdido:'#c62828'};
      const crmLabels = {pendente:'Pendente',em_contato:'Em Contato',ganho:'Ganho',perdido:'Perdido'};
      crmBadge.style.display = 'inline';
      crmBadge.style.background = (crmColors[d.contact.status]||'#888') + '22';
      crmBadge.style.color = crmColors[d.contact.status] || '#888';
      crmBadge.textContent = crmLabels[d.contact.status] || d.contact.status;
    } else crmBadge.style.display = 'none';

    // Botões CRM: se não está no CRM → mostra "+ Cadastrar", esconde "CRM"
    const btnAdd  = document.getElementById('chat-btn-add-crm');
    const btnOpen = document.getElementById('chat-btn-open-crm');
    if (btnAdd && btnOpen) {
      btnAdd.style.display  = d.in_crm ? 'none' : '';
      btnOpen.style.display = d.in_crm ? ''     : 'none';
    }

    // Mensagens
    renderMessages(d.messages || []);
    if (d.messages?.length) chatLastId = Math.max(...d.messages.map(m => parseInt(m.id)||0));

    chatListData = chatListData.map(c => c.phone === phone ? {...c, unread:0} : c);
    updateUnreadBadge();

  } catch(e) {
    msgBody.innerHTML = '<div style="text-align:center;padding:20px;color:var(--error)">Erro ao carregar mensagens.</div>';
  }
}

/* ── Renderizar mensagens ───────────────────────────────── */
function renderMessages(msgs) {
  const body = document.getElementById('chat-messages-body');
  if (!msgs.length) {
    body.innerHTML = '<div style="text-align:center;padding:20px;color:var(--cinza);font-size:.82rem">Nenhuma mensagem ainda.</div>';
    return;
  }
  body.innerHTML = msgs.map(m => {
    const cls  = m.from_me == 1 ? 'sent' : 'received';
    const time = m.created_at ? new Date(m.created_at).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}) : '';
    // usa preview para renderizar (já trata [mensagem] → emoji)
    const displayBody = m.preview || m.body || '';
    let content = '';
    if (m.msg_type === 'image' && m.media_url) {
      content = `<img src="${esc(m.media_url)}" style="max-width:200px;border-radius:8px;display:block;margin-bottom:4px" onerror="this.style.display='none'">${esc(displayBody)}`;
    } else {
      content = esc(displayBody).replace(/\n/g,'<br>');
    }
    return `<div class="chat-bubble ${cls}" data-id="${m.id}">
      ${content}
      <div class="chat-bubble-time">${time}${m.from_me==1?' ✓':''}</div>
    </div>`;
  }).join('');
  body.scrollTop = body.scrollHeight;
}

function appendMessage(m) {
  const body = document.getElementById('chat-messages-body');
  const cls  = m.from_me == 1 ? 'sent' : 'received';
  const time = m.created_at ? new Date(m.created_at).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}) : new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
  const displayBody = m.preview || m.body || '';
  const div = document.createElement('div');
  div.className = `chat-bubble ${cls}`;
  div.dataset.id = m.id || '';
  div.innerHTML = `${esc(displayBody).replace(/\n/g,'<br>')}<div class="chat-bubble-time">${time}${m.from_me==1?' ✓':''}</div>`;
  body.appendChild(div);
  body.scrollTop = body.scrollHeight;
}

/* ── Enviar mensagem ────────────────────────────────────── */
async function chatSend() {
  const input = document.getElementById('chat-input');
  const text  = input.value.trim();
  if (!text || !chatActive) return;
  input.value = '';
  input.style.height = 'auto';
  appendMessage({ from_me:1, body:text, created_at: new Date().toISOString() });
  try {
    const r = await fetch('chat.php?action=send', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({phone:chatActive, message:text})
    });
    const d = await r.json();
    if (!d.ok) toast('Falha ao enviar mensagem. Verifique a conexão.', 'error');
    else {
      const existing = chatListData.find(c => c.phone === chatActive);
      if (existing) { existing.last_body = text; existing.preview = text; existing.last_from_me = 1; existing.last_at = new Date().toISOString(); }
      renderChatList(chatListData);
    }
  } catch(e) { toast('Erro de rede ao enviar.', 'error'); }
}

function chatInputKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chatSend(); }
}

/* ── Abrir contato no CRM ──────────────────────────────── */
function chatOpenCRM() {
  if (!chatActive) return;
  goPage('crm');
  setTimeout(async () => {
    const cleanPhone = chatActive.replace(/\D/g, '');
    const search = cleanPhone.length > 4 ? cleanPhone.slice(-9) : cleanPhone;
    try {
      const r = await fetch(`crm.php?action=list&search=${encodeURIComponent(search)}&per=5`, {credentials:'same-origin'});
      const d = await r.json();
      if (d.ok && d.contacts?.length) {
        crmOpenPopup(d.contacts[0].id);
      } else {
        const inp = document.getElementById('crm-search-input');
        if (inp) { inp.value = chatActive; inp.dispatchEvent(new Event('input')); }
        toast('Contato não encontrado no CRM.', 'warn');
      }
    } catch(e) { toast('Erro ao buscar contato.', 'error'); }
  }, 300);
}

/* ── Cadastrar no CRM (modal rápido) ──────────────────── */
function chatQuickAddCRM() {
  const modal = document.getElementById('chat-add-crm-modal');
  if (!modal) return;
  document.getElementById('chat-add-nome').value    = '';
  document.getElementById('chat-add-empresa').value = '';
  modal.style.display = modal.style.display === 'none' ? '' : 'none';
  if (modal.style.display !== 'none') document.getElementById('chat-add-nome').focus();
}
function chatCloseAddCRM() {
  const modal = document.getElementById('chat-add-crm-modal');
  if (modal) modal.style.display = 'none';
}
async function chatConfirmAddCRM() {
  if (!chatActive) return;
  const nome    = document.getElementById('chat-add-nome')?.value.trim()    || '';
  const empresa = document.getElementById('chat-add-empresa')?.value.trim() || '';
  const btn = document.querySelector('#chat-add-crm-modal button.btn-primary');
  const orig = btn?.innerHTML;
  if (btn) { btn.innerHTML = '⏳ Salvando…'; btn.disabled = true; }
  try {
    const r = await fetch('chat.php?action=add_crm', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ phone: chatActive, name: nome, empresa })
    });
    const d = await r.json();
    if (d.ok) {
      chatCloseAddCRM();
      _chatActiveInCRM = true;
      const btnAdd  = document.getElementById('chat-btn-add-crm');
      const btnOpen = document.getElementById('chat-btn-open-crm');
      if (btnAdd)  btnAdd.style.display  = 'none';
      if (btnOpen) btnOpen.style.display = '';
      // Mostra badge CRM no header
      const crmBadge = document.getElementById('chat-active-crm');
      if (crmBadge) {
        crmBadge.style.display = 'inline';
        crmBadge.style.background = '#1a5c2a22';
        crmBadge.style.color = '#1a5c2a';
        crmBadge.textContent = 'Em Contato';
      }
      // Atualiza o item na lista
      chatListData = chatListData.map(c => c.phone === chatActive ? {...c, in_crm:true, crm_status:'em_contato', display_name: empresa || nome || c.display_name} : c);
      renderChatList(chatListData);
      toast(`✅ ${empresa || nome || chatActive} cadastrado no CRM!`, 'success');
      refreshCrmLists();
    } else {
      toast('❌ Erro: ' + (d.error || 'Falha'), 'error');
      if (btn) { btn.innerHTML = orig; btn.disabled = false; }
    }
  } catch(e) {
    toast('❌ Falha de rede: ' + e.message, 'error');
    if (btn) { btn.innerHTML = orig; btn.disabled = false; }
  }
}

// ESC fecha o modal de add CRM
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') chatCloseAddCRM();
});

/* ── Polling ─────────────────────────────────────────────── */
async function chatPoll() {
  if (!chatActive) {
    try {
      const r = await fetch(`chat.php?action=poll&since_id=${chatLastId}`, {credentials:'same-origin'});
      const d = await r.json();
      if (d.ok && d.rows?.length) {
        chatLastId = d.last_id;
        await loadChatList();
      }
    } catch(e) {}
    return;
  }
  try {
    const r = await fetch(`chat.php?action=poll&phone=${encodeURIComponent(chatActive)}&since_id=${chatLastId}`, {credentials:'same-origin'});
    const d = await r.json();
    if (d.ok && d.rows?.length) {
      chatLastId = d.last_id;
      d.rows.forEach(m => appendMessage(m));
      await loadChatList();
    }
  } catch(e) {}
}

async function clearChatHistory() {
  if (!confirm('Sincronizar conversas do celular conectado?\n\nIsso vai limpar o histórico atual e puxar as conversas do WhatsApp conectado agora.')) return;

  const btn = document.querySelector('.chat-list-header button[title*="Limpar"]');
  if (btn) { btn.disabled = true; btn.style.opacity = '.4'; }

  try {
    const r = await fetch('chat.php?action=sync_now', { method: 'POST', credentials: 'same-origin' });
    const d = await r.json();
    if (d.ok) {
      chatActive = null;
      document.getElementById('chat-active').style.display      = 'none';
      document.getElementById('chat-empty-state').style.display = '';
      await loadChatList();
      toast('✅ Histórico limpo. Novas mensagens da Gisele aparecem em tempo real.', 'success');
    } else {
      toast('❌ Erro: ' + (d.error || 'Falha'), 'error');
    }
  } catch(e) {
    toast('❌ Falha de rede: ' + e.message, 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
  }
}

function startChatPolling() {
  if (chatPollInterval) return;
  checkChatConnection();  // checa conexão WA ao entrar
  loadChatList();
  chatPollInterval = setInterval(chatPoll, 4000);
  // Re-checa conexão a cada 15s (detecta reconexão rapidamente)
  if (!window._chatConnInterval) window._chatConnInterval = setInterval(checkChatConnection, 15000);
}

function stopChatPolling() {
  if (chatPollInterval) { clearInterval(chatPollInterval); chatPollInterval = null; }
  if (window._chatConnInterval) { clearInterval(window._chatConnInterval); window._chatConnInterval = null; }
}

/* ── GERADOR DE IA ───────────────────────── */
async function generateWithAI() {
  const objective = document.getElementById('ai-objective').value.trim();
  if (!objective) { toast('Descreva o objetivo da mensagem antes de gerar.', 'warn'); return; }

  const count   = parseInt(document.getElementById('ai-variations-count').value) || 3;
  const tone    = document.getElementById('ai-tone').value;
  const length  = document.getElementById('ai-length').value;
  const imgFile = document.getElementById('img-file').files?.[0];
  const hasImg  = !!imgFile;

  const btn    = document.getElementById('btn-ai-generate');
  const status = document.getElementById('ai-status');
  btnLoading(btn, true);
  status.style.display  = 'block';
  status.style.background = '#fff';
  status.textContent    = hasImg ? '🖼️ Verificando limite de uso de imagem...' : '⚡ Gerando com Groq AI...';

  const toneMap = {
    descontraido: 'descontraído, amigável e próximo — use emoji com moderação',
    neutro:       'neutro e profissional, mas humano',
    formal:       'formal e respeitoso, sem gírias',
    urgente:      'urgente e direto, transmite escassez/oportunidade',
    emocional:    'emocional e afetuoso, cria conexão afetiva',
  };
  const lengthMap = {
    curto: 'até 3 linhas curtas — seja extremamente conciso',
    medio: '4 a 6 linhas — equilibre brevidade e detalhes',
    longo: '7 a 10 linhas — pode detalhar mais',
  };

  const businessName = window._cfgProfileName || 'Casa das Flores';

  const systemPrompt = `Você é um especialista em copywriting para WhatsApp Marketing no Brasil.
Gere exatamente ${count} variações de mensagem para disparo em massa pelo WhatsApp.

REGRAS OBRIGATÓRIAS:
- Sempre inicie com "Olá, {{nome}}!" ou variação criativa com {{nome}}
- Tom: ${toneMap[tone] || toneMap.neutro}
- Tamanho: ${lengthMap[length] || lengthMap.medio}
- Negócio: ${businessName}
- Use formatação WhatsApp: *negrito*, _itálico_ quando adequado
- NUNCA use HTML, markdown com # ou formatação que não funcione no WhatsApp
- Cada variação deve ter ângulo/abordagem DIFERENTE (não só trocar palavras)
- Termine com CTA claro (responda aqui, entre em contato, etc.)
- ${hasImg ? 'Há uma imagem sendo enviada — o texto COMPLEMENTA a imagem, não a descreve' : 'Sem imagem — o texto precisa transmitir a mensagem sozinho'}

FORMATO DE RESPOSTA — JSON puro, sem explicações, sem markdown:
{"variations":["texto 1","texto 2","..."]}`;

  try {

    /* ── COM IMAGEM → Claude (limite diário) ── */
    if (hasImg) {
      // Verifica limite
      const lr = await fetch('groq.php?action=img_status', {credentials:'same-origin', headers:{'X-Panel-Token':GROQ_PANEL_TOKEN}});
      const ld = await lr.json();
      if (!ld.ok || !ld.allowed) {
        status.style.background = '#fef2f2';
        status.innerHTML = `🚫 Limite diário de análise de imagem atingido (${ld.used}/${ld.limit}).<br>
          <span style="font-size:.77rem">Reseta automaticamente à meia-noite. Para texto puro, remova a imagem e tente novamente.</span>`;
        toast(`Limite de ${ld.limit} análises de imagem/dia atingido.`, 'warn');
        return;
      }

      status.textContent = `🖼️ Analisando imagem com Claude... (${ld.remaining} restante(s) hoje)`;

      // Lê imagem como base64
      const b64 = await new Promise((res, rej) => {
        const fr = new FileReader();
        fr.onload = () => res(fr.result.split(',')[1]);
        fr.onerror = rej;
        fr.readAsDataURL(imgFile);
      });

      // Chama Claude API (browser → funciona no ambiente claude.ai)
      const response = await fetch('https://api.anthropic.com/v1/messages', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
          model:      'claude-sonnet-4-20250514',
          max_tokens: 1500,
          system:     systemPrompt,
          messages:   [{ role:'user', content:[
            { type:'image', source:{ type:'base64', media_type: imgFile.type, data: b64 }},
            { type:'text',  text: `Objetivo da campanha: ${objective}` }
          ]}],
        })
      });

      const data = await response.json();
      if (!response.ok) throw new Error(data.error?.message || 'Erro na API Claude');

      // Incrementa contador
      await fetch('groq.php?action=img_increment', {credentials:'same-origin', headers:{'X-Panel-Token':GROQ_PANEL_TOKEN}});

      const raw = data.content?.find(b => b.type === 'text')?.text || '';
      await _applyGeneratedVariations(raw, count, ld.remaining - 1);

    /* ── SEM IMAGEM → Groq (gratuito) ── */
    } else {
      const response = await fetch('groq.php?action=generate', {
        method:  'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Panel-Token': GROQ_PANEL_TOKEN },
        body:    JSON.stringify({
          system: systemPrompt,
          prompt: `Objetivo da campanha: ${objective}`,
        })
      });

      const data = await response.json();
      if (!data.ok) throw new Error(data.error || 'Erro no Groq');
      await _applyGeneratedVariations(data.text, count, null);
    }

  } catch(e) {
    status.style.background = '#fef2f2';
    status.innerHTML = `❌ ${e.message}`;
    toast('Erro ao gerar: ' + e.message, 'error');
  } finally {
    btnLoading(btn, false);
  }
}

async function _applyGeneratedVariations(raw, expectedCount, imgRemaining) {
  const status = document.getElementById('ai-status');
  let parsed;
  try {
    const clean = raw.replace(/```json|```/g, '').trim();
    parsed = JSON.parse(clean);
  } catch {
    throw new Error('Resposta inválida da IA. Tente novamente.');
  }

  const variations = parsed.variations || [];
  if (!variations.length) throw new Error('Nenhuma variação gerada.');

  const wrap = document.getElementById('variations-wrap');
  wrap.innerHTML = '';
  variations.forEach((txt, i) => {
    const div = document.createElement('div');
    div.className = 'variation-item';
    div.dataset.idx = i;
    div.innerHTML = `<div class="variation-num">${i+1}</div>
      <textarea class="variation-text">${txt.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</textarea>
      ${i > 0 ? `<button class="var-del" onclick="this.closest('.variation-item').remove();renumberVariations()" title="Remover">✕</button>` : ''}`;
    wrap.appendChild(div);
  });
  attachVarFocus();

  status.style.background = '#f0f7f0';
  const imgInfo = imgRemaining !== null
    ? ` · <span style="color:#5a8a5a">${imgRemaining} análise(s) de imagem restante(s) hoje</span>`
    : ' · <span style="color:#5a8a5a">via Groq AI ⚡</span>';
  status.innerHTML = `✅ <strong>${variations.length} variações geradas!</strong> Revise e edite se necessário.${imgInfo}`;
  toast(`${variations.length} variações geradas!`, 'success');
}

/* ── CONFIGURAÇÕES ───────────────────────── */
async function loadSettings() {
  try {
    const r = await fetch('settings.php?action=get', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) return;
    const s = d.settings;
    document.getElementById('cfg-profile-name').value = s.profile_name || '';
    document.getElementById('cfg-phone').value        = s.phone        || '';
    document.getElementById('cfg-company-name').value = s.company_name || '';
    document.getElementById('cfg-city').value         = s.city         || '';
    document.getElementById('cfg-footer').value       = s.footer       || '';
    document.getElementById('cfg-daily-limit').value  = s.daily_limit  || '200';
    document.getElementById('cfg-is-business').value  = s.is_business  || '0';
    // Expõe globalmente para o startDisparo usar
    window._cfgDailyLimit  = parseInt(s.daily_limit || '200');
    window._cfgIsBusiness  = s.is_business === '1';

  } catch(e) { console.error('loadSettings error:', e); }
}

async function saveSettings() {
  const pwCurrent = document.getElementById('cfg-pw-current').value;
  const pwNew     = document.getElementById('cfg-pw-new').value;
  const pwConfirm = document.getElementById('cfg-pw-confirm').value;

  if (pwNew || pwConfirm) {
    if (!pwCurrent) { toast('Informe a senha atual para alterá-la.', 'warn'); return; }
    if (pwNew !== pwConfirm) { toast('A nova senha e a confirmação não coincidem.', 'warn'); return; }
    if (pwNew.length < 6) { toast('A nova senha deve ter pelo menos 6 caracteres.', 'warn'); return; }
  }

  const payload = {
    company_name:   document.getElementById('cfg-company-name').value.trim(),
    city:           document.getElementById('cfg-city').value.trim(),
    footer:         document.getElementById('cfg-footer').value.trim(),
    daily_limit:    document.getElementById('cfg-daily-limit').value.trim(),
    is_business:    document.getElementById('cfg-is-business').value,
  };
  if (pwNew) { payload.pw_current = pwCurrent; payload.pw_new = pwNew; }

  const btn = document.querySelector('#page-configuracoes .btn-primary');
  btnLoading(btn, true);

  try {
    const r = await fetch('settings.php?action=save', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); } catch(e) {
      toast('Erro no servidor. Tente novamente.', 'error'); return;
    }

    if (d.ok) {
      window._cfgDailyLimit = parseInt(payload.daily_limit || '200');
      window._cfgIsBusiness = payload.is_business === '1';
      document.getElementById('cfg-pw-current').value = '';
      document.getElementById('cfg-pw-new').value     = '';
      document.getElementById('cfg-pw-confirm').value = '';
      toast('Configurações salvas com sucesso!', 'success');
    } else {
      const erros = {
        'Senha atual incorreta': 'Senha atual incorreta. Tente novamente.',
        'default': d.error || 'Erro ao salvar as configurações.'
      };
      toast(erros[d.error] || erros.default, 'error');
    }
  } catch(e) {
    toast('Sem conexão com o servidor. Verifique sua internet.', 'error');
  } finally {
    btnLoading(btn, false);
  }
}

/* ── UTIL ───────────────────────────────── */
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function esc(s) { return (s ?? '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── INIT ───────────────────────────────── */
window.addEventListener('DOMContentLoaded', () => {
  checkStatus();
  statusInterval = setInterval(checkStatus, 15000);
  document.getElementById('manual-phones').addEventListener('input', mergeManualPhones);
  tplRenderSelect();
  attachVarFocus();
  refreshCrmLists();
  loadCrmSources();
  loadDashboard();
  setTimeout(maybeShowOnboarding, 800);
  // Poll leve de badge de não lidas (a cada 10s, sempre)
  setInterval(async () => {
    try {
      const r = await fetch('chat.php?action=list', {credentials:'same-origin'});
      const d = await r.json();
      if (d.ok) { chatListData = d.chats || []; updateUnreadBadge(); }
    } catch(e) {}
  }, 10000); // pequeno delay para não atrapalhar load
  fetch('settings.php?action=get', {credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if (d.ok && d.settings) {
      window._cfgProfileName = d.settings.profile_name;
      window._cfgPhone       = d.settings.phone;
    }
  }).catch(()=>{});
});
</script>

<!-- ═══ MODAL: CRM Lead Popup ═══ -->
<div id="crm-popup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;padding:16px" onclick="if(event.target===this)crmClosePopup()">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;max-height:90vh;display:flex;flex-direction:column">
    <div style="background:linear-gradient(135deg,var(--verde-800),var(--verde-900));padding:18px 22px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
      <div>
        <div id="popup-empresa" style="font-size:1rem;font-weight:700;color:#fff;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div>
        <div id="popup-phone" style="font-size:.82rem;color:rgba(255,255,255,.6);font-family:var(--font-mono);margin-top:2px"></div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <button id="popup-wa-btn" onclick="openChatFromCrm()" title="Abrir conversa no WhatsApp"
          style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:6px 10px;color:#fff;cursor:pointer;font-size:.75rem;font-weight:600;display:flex;align-items:center;gap:5px;transition:background .15s"
          onmouseover="this.style.background='rgba(37,211,102,.4)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">
          <svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M11.5 2C6.253 2 2 6.253 2 11.5c0 1.87.518 3.618 1.418 5.112L2 22l5.558-1.396A9.458 9.458 0 0 0 11.5 21C16.747 21 21 16.747 21 11.5S16.747 2 11.5 2z"/></svg>
          WhatsApp
        </button>
        <button onclick="crmClosePopup()" style="background:rgba(255,255,255,.12);border:none;border-radius:50%;width:32px;height:32px;color:#fff;cursor:pointer;font-size:1.2rem;display:flex;align-items:center;justify-content:center;flex-shrink:0">×</button>
      </div>
    </div>
    <div style="padding:20px 22px;overflow-y:auto;flex:1">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
        <div><span style="color:var(--cinza);font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em">Origem</span><div id="popup-source" style="color:var(--texto);margin-top:3px;font-size:.85rem"></div></div>
        <div><span style="color:var(--cinza);font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em">Adicionado</span><div id="popup-date" style="color:var(--texto);margin-top:3px;font-family:var(--font-mono);font-size:.82rem"></div></div>
        <div style="grid-column:1/-1">
          <span style="color:var(--cinza);font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em">Histórico de disparos</span>
          <div id="popup-campaign" style="margin-top:8px"></div>
        </div>
      </div>
      <div style="margin-bottom:16px">
        <div style="font-size:.65rem;font-weight:700;color:var(--cinza);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">Status</div>
        <div id="popup-status-pills"></div>
      </div>
      <div class="form-group">
        <label>Nome da pessoa</label>
        <input type="text" id="popup-name-input" placeholder="Nome do contato">
      </div>
      <div class="form-group">
        <label>Observação</label>
        <textarea id="popup-notes-input" placeholder="Anotações sobre este lead…" style="min-height:80px"></textarea>
      </div>
    </div>
    <div style="padding:14px 22px;border-top:1px solid var(--borda);display:flex;gap:10px;justify-content:space-between;flex-shrink:0;flex-wrap:wrap">
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button id="popup-del-btn" class="btn btn-danger" style="font-size:.82rem" onclick="crmDeleteLeadPopup()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
          Excluir
        </button>
        <button id="popup-block-btn" class="btn btn-outline" style="font-size:.82rem" onclick="toggleBlockContact()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
          <span id="popup-block-text">Bloquear de disparos</span>
        </button>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-outline" onclick="crmClosePopup()">Cancelar</button>
        <button id="popup-save-btn" class="btn btn-primary" onclick="crmSavePopup()">Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL: Limpar Histórico ═══ -->
<div id="clear-history-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;padding:16px" onclick="if(event.target===this)closeClearHistory()">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:380px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden">
    <div style="background:#c62828;padding:18px 22px">
      <div style="font-size:1rem;font-weight:700;color:#fff">⚠️ Limpar Histórico</div>
      <div style="font-size:.8rem;color:rgba(255,255,255,.75);margin-top:4px">Esta ação é permanente e irreversível.</div>
    </div>
    <div style="padding:20px 22px">
      <div class="form-group" style="margin-bottom:20px">
        <label>Digite a senha de acesso para confirmar</label>
        <input type="password" id="clear-history-pw" placeholder="••••••••">
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button class="btn btn-outline" onclick="closeClearHistory()">Cancelar</button>
        <button class="btn btn-danger" onclick="confirmClearHistory()">Confirmar e Limpar</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL: Histórico Detalhe ═══ -->
<div id="hist-popup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto" onclick="if(event.target===this)closeHistoricoPopup()">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:680px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;margin:auto">
    <div style="background:linear-gradient(135deg,var(--verde-800),var(--verde-900));padding:18px 22px;display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:1rem;font-weight:700;color:#fff">📊 Detalhes do Disparo</div>
      <button onclick="closeHistoricoPopup()" style="background:rgba(255,255,255,.12);border:none;border-radius:50%;width:32px;height:32px;color:#fff;cursor:pointer;font-size:1.2rem;display:flex;align-items:center;justify-content:center">×</button>
    </div>
    <div id="hist-popup-body" style="padding:22px"><em style="color:var(--cinza)">Carregando…</em></div>
    <div style="padding:14px 22px;border-top:1px solid var(--borda);text-align:right">
      <button class="btn btn-outline" onclick="closeHistoricoPopup()">Fechar</button>
    </div>
  </div>
</div>

</body>
</html>