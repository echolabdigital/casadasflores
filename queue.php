<?php
/**
 * Casa das Flores — queue.php
 * API para criação e controle de disparos server-side.
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function qpdo(): PDO {
    static $p;
    if (!$p) $p = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    return $p;
}
function qok(array $d=[]): never  { echo json_encode(array_merge(['ok'=>true],$d)); exit; }
function qerr(string $m,int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/* ── POST create ─────────────────────────────────────────── */
if ($action === 'create' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['contacts']) || empty($body['variations'])) qerr('contacts e variations obrigatórios');

    // Garante coluna blocked
    try { qpdo()->exec("ALTER TABLE contacts ADD COLUMN blocked TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}

    // Filtra contatos bloqueados
    $allPhones = array_filter(array_map(fn($c) => preg_replace('/\D/','',$c['phone']??''), $body['contacts']));
    $blockedPhones = [];
    if ($allPhones) {
        $likes = implode(' OR ', array_fill(0, count($allPhones), "REGEXP_REPLACE(phone,'[^0-9]','') LIKE ?"));
        $params = array_map(fn($p) => '%'.(strlen($p)>=12 && str_starts_with($p,'55')?substr($p,2):$p), $allPhones);
        $st = qpdo()->prepare("SELECT phone FROM contacts WHERE blocked=1 AND ($likes)");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $bp) {
            $blockedPhones[] = preg_replace('/\D/', '', $bp);
        }
    }

    // Remove bloqueados da lista
    $filteredContacts = [];
    $excluded = 0;
    foreach ($body['contacts'] as $c) {
        $cp = preg_replace('/\D/', '', $c['phone'] ?? '');
        $isBlocked = false;
        foreach ($blockedPhones as $bp) {
            if (str_contains($cp, $bp) || str_contains($bp, $cp)) { $isBlocked = true; break; }
        }
        if ($isBlocked) { $excluded++; continue; }
        $filteredContacts[] = $c;
    }

    if (!$filteredContacts) qerr('Todos os contatos estão bloqueados');

    $jobName = $body['job_name'] ?? 'Disparo '.date('d/m H:i');
    $total   = count($filteredContacts);

    // Agendamento: se scheduled_at fornecido e no futuro, usa status 'scheduled'
    $scheduledAt = null;
    if (!empty($body['scheduled_at'])) {
        $ts = strtotime($body['scheduled_at']);
        if ($ts && $ts > time()) {
            $scheduledAt = date('Y-m-d H:i:s', $ts);
        }
    }
    $initialStatus = $scheduledAt ? 'scheduled' : 'pending';

    // Garante coluna scheduled_at
    try { qpdo()->exec("ALTER TABLE dispatch_queue ADD COLUMN scheduled_at DATETIME NULL DEFAULT NULL"); } catch (\Throwable $e) {}

    qpdo()->prepare(
        "INSERT INTO dispatch_queue (job_name,variations,image_url,contacts,delay_secs,total,status,scheduled_at)
         VALUES(?,?,?,?,?,?,?,?)"
    )->execute([
        $jobName,
        json_encode($body['variations']),
        $body['image_url']  ?? null,
        json_encode($filteredContacts),
        max(1, (int)($body['delay_secs'] ?? 6)),
        $total,
        $initialStatus,
        $scheduledAt,
    ]);
    $jobId = (int)qpdo()->lastInsertId();

    // Marca contatos com a campanha
    if (!empty($filteredContacts)) {
        try { qpdo()->exec("ALTER TABLE contacts ADD COLUMN last_campaign VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
        try { qpdo()->exec("ALTER TABLE contacts ADD COLUMN last_dispatched_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        $stmt = qpdo()->prepare(
            "UPDATE contacts SET last_campaign=?, last_dispatched_at=NOW() 
             WHERE REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?
                OR REGEXP_REPLACE(phone, '[^0-9]', '') = ?
                OR phone = ?"
        );
        foreach ($filteredContacts as $c) {
            $rawPhone = $c['phone'] ?? '';
            $clean    = preg_replace('/\D/', '', $rawPhone);
            if (strlen($clean) < 10) continue;
            $shortNum = (strlen($clean) >= 12 && str_starts_with($clean, '55')) ? substr($clean, 2) : $clean;
            $stmt->execute([$jobName, '%'.$shortNum, $clean, $rawPhone]);
        }
    }

    qok(['job_id' => $jobId, 'total' => $total, 'excluded_blocked' => $excluded, 'scheduled_at' => $scheduledAt, 'status' => $initialStatus]);
}

/* ── GET status ──────────────────────────────────────────── */
if ($action === 'status' && $method === 'GET') {
    $id = (int)($_GET['job_id'] ?? 0);
    if (!$id) qerr('job_id obrigatório');
    $job = qpdo()->query("SELECT id,job_name,status,total,sent,errors,current_index,started_at,finished_at,scheduled_at FROM dispatch_queue WHERE id=$id")->fetch();
    if (!$job) qerr('Job não encontrado', 404);
    $pct = $job['total'] > 0 ? round($job['current_index'] / $job['total'] * 100) : 0;
    qok(['job'=>$job,'pct'=>$pct]);
}

/* ── POST pause ──────────────────────────────────────────── */
if ($action === 'pause' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = (int)($body['job_id'] ?? 0);
    if (!$id) qerr('job_id obrigatório');
    qpdo()->prepare("UPDATE dispatch_queue SET status='paused' WHERE id=? AND status='running'")->execute([$id]);
    qok();
}

/* ── POST resume ─────────────────────────────────────────── */
if ($action === 'resume' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = (int)($body['job_id'] ?? 0);
    if (!$id) qerr('job_id obrigatório');
    qpdo()->prepare("UPDATE dispatch_queue SET status='running' WHERE id=? AND status='paused'")->execute([$id]);
    qok();
}

/* ── POST cancel ─────────────────────────────────────────── */
if ($action === 'cancel' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = (int)($body['job_id'] ?? 0);
    if (!$id) qerr('job_id obrigatório');
    qpdo()->prepare("UPDATE dispatch_queue SET status='cancelled', finished_at=NOW() WHERE id=? AND status IN ('running','paused','pending')")->execute([$id]);
    qok();
}

/* ── GET log ────────────────────────────────────────────── */
if ($action === 'log' && $method === 'GET') {
    $logFile = __DIR__ . '/worker.log';
    $lines   = max(10, min(100, (int)($_GET['lines'] ?? 40)));
    if (!file_exists($logFile)) { qok(['lines' => []]); }
    $all  = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $last = array_slice($all, -$lines);
    qok(['lines' => array_values($last)]);
}

/* ── GET list ────────────────────────────────────────────── */
if ($action === 'list' && $method === 'GET') {
    $jobs = qpdo()->query(
        "SELECT id,job_name,status,total,sent,errors,current_index,started_at,finished_at,scheduled_at
         FROM dispatch_queue ORDER BY created_at DESC LIMIT 20"
    )->fetchAll();
    qok(['jobs'=>$jobs]);
}

qerr('Ação desconhecida', 400);
