<?php
/**
 * Casa das Flores — dashboard.php
 * Retorna métricas do dashboard em JSON para atualização dinâmica.
 */
session_start();
if (empty($_SESSION['cdf_logged'])) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }

require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json');

function dpdo(): PDO {
    static $p;
    if (!$p) $p = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    return $p;
}

$m = [
    'total_jobs'     => 0,
    'total_sent'     => 0,
    'total_errors'   => 0,
    'success_rate'   => 0,
    'last_job_name'  => null,
    'last_job_date'  => null,
    'jobs_recent'    => [],
    'crm_total'      => 0,
    'crm_pendente'   => 0,
    'crm_em_contato' => 0,
    'crm_ganho'      => 0,
    'crm_perdido'    => 0,
    'sent_today'     => 0,
    'top_listas'     => [],
    'crm_recent'     => [],
];

try {
    // Jobs
    $row = dpdo()->query("SELECT COUNT(*) AS c, COALESCE(SUM(sent),0) AS s, COALESCE(SUM(errors),0) AS e, COALESCE(SUM(total),0) AS t FROM jobs")->fetch();
    $m['total_jobs']   = (int)$row['c'];
    $m['total_sent']   = (int)$row['s'];
    $m['total_errors'] = (int)$row['e'];
    $total = (int)$row['t'];
    $m['success_rate'] = $total > 0 ? round($m['total_sent'] / $total * 100, 1) : 0;

    $last = dpdo()->query("SELECT job_name, started_at FROM jobs ORDER BY started_at DESC LIMIT 1")->fetch();
    if ($last) { $m['last_job_name'] = $last['job_name']; $m['last_job_date'] = $last['started_at']; }

    $m['jobs_recent'] = dpdo()->query("SELECT id,job_name,total,sent,errors,started_at FROM jobs ORDER BY started_at DESC LIMIT 5")->fetchAll();

    // CRM
    $counts = dpdo()->query("SELECT status, COUNT(*) as c FROM contacts GROUP BY status")->fetchAll();
    foreach ($counts as $r) { $m['crm_'.$r['status']] = (int)$r['c']; $m['crm_total'] += (int)$r['c']; }

    // Disparos hoje
    $today = dpdo()->query("SELECT COALESCE(SUM(sent),0) as sent_today FROM jobs WHERE DATE(started_at) = CURDATE()")->fetch();
    $m['sent_today'] = (int)$today['sent_today'];

    $m['top_listas'] = dpdo()->query("SELECT source, COUNT(*) as total, SUM(status='ganho') as ganhos FROM contacts WHERE source IS NOT NULL AND source != '' GROUP BY source ORDER BY total DESC LIMIT 5")->fetchAll();

    $m['crm_recent'] = dpdo()->query("SELECT phone,name,empresa,status,source,created_at FROM contacts ORDER BY created_at DESC LIMIT 5")->fetchAll();

} catch (\Throwable $e) {}

echo json_encode(['ok'=>true,'metrics'=>$m]);
