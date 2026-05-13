<?php
/**
 * Casa das Flores — historico.php
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'];

function hpdo(): PDO {
    static $p;
    if (!$p) $p = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    return $p;
}
function hok(array $d=[]): never  { echo json_encode(array_merge(['ok'=>true],$d)); exit; }
function herr(string $m,int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

// Garante colunas novas sem travar se já existirem
try { hpdo()->exec("ALTER TABLE jobs ADD COLUMN queued INT UNSIGNED NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
try { hpdo()->exec("ALTER TABLE job_contacts ADD COLUMN queued_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
try { hpdo()->exec("ALTER TABLE job_contacts MODIFY COLUMN status ENUM('sent','error','queued') NOT NULL DEFAULT 'sent'"); } catch (\Throwable $e) {}

$action = $_GET['action'] ?? '';

/* ── GET lista / detalhe ─────────────────────────────────────── */
if ($method === 'GET' && $action === '') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = 20;
    $off  = ($page-1)*$per;
    $job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : null;

    if ($job_id) {
        // Tenta histórico (jobs) primeiro
        $job = hpdo()->query("SELECT * FROM jobs WHERE id=$job_id")->fetch();
        if ($job) {
            // Enriquece com dados do dispatch_queue se ainda existir (job recém-finalizado)
            $dq = hpdo()->prepare("SELECT image_url, variations FROM dispatch_queue WHERE id=?");
            $dq->execute([$job_id]);
            $dqData = $dq->fetch();
            if ($dqData) {
                $job['image_url'] = $dqData['image_url'];
                $vars = json_decode($dqData['variations'] ?? '[]', true);
                $job['message_text'] = is_array($vars) ? implode("\n\n--- VARIAÇÃO ---\n\n", $vars) : '';
            }
            $contacts = hpdo()->query(
                "SELECT phone, status, error_msg, sent_at, queued_at FROM job_contacts WHERE job_id=$job_id ORDER BY id"
            )->fetchAll();
            hok(['job'=>$job,'contacts'=>$contacts,'source'=>'history']);
        }

        // Fallback: job ainda na fila (não gravado em jobs ainda)
        $qjob = hpdo()->query("SELECT * FROM dispatch_queue WHERE id=$job_id")->fetch();
        if (!$qjob) herr('Job nao encontrado', 404);

        $contacts_raw = json_decode($qjob['contacts'] ?? '[]', true) ?: [];
        $done_idx     = (int)$qjob['current_index'];
        $contacts = array_map(function($c, $i) use ($done_idx) {
            $st = $i < $done_idx ? 'sent' : 'queued';
            return [
                'phone'     => $c['phone'],
                'status'    => $st,
                'error_msg' => null,
                'sent_at'   => $st === 'sent' ? null : null,
                'queued_at' => $st === 'queued' ? date('Y-m-d H:i:s') : null,
            ];
        }, $contacts_raw, array_keys($contacts_raw));

        $vars = json_decode($qjob['variations'] ?? '[]', true);
        $queued_count = max(0, count($contacts_raw) - $done_idx);
        hok(['job'=>[
            'id'           => $qjob['id'],
            'job_name'     => $qjob['job_name'],
            'total'        => $qjob['total'],
            'sent'         => $qjob['sent'],
            'errors'       => $qjob['errors'],
            'queued'       => $queued_count,
            'started_at'   => $qjob['started_at'],
            'finished_at'  => $qjob['finished_at'],
            '_status'      => $qjob['status'],
            'image_url'    => $qjob['image_url'],
            'message_text' => is_array($vars) ? implode("\n\n--- VARIAÇÃO ---\n\n", $vars) : '',
        ],'contacts'=>$contacts,'source'=>'queue']);
    }

    // Listagem geral: jobs concluídos + jobs ainda na fila
    $jobs_done = hpdo()->query(
        "SELECT id, job_name, '' AS _status, 'done' as queue_status,
                total, sent, errors, COALESCE(queued,0) AS queued, started_at, finished_at
         FROM jobs ORDER BY started_at DESC LIMIT 200"
    )->fetchAll();

    $jobs_queue = hpdo()->query(
        "SELECT id, job_name, status AS _status, status as queue_status,
                total, sent, errors,
                GREATEST(0, total - sent - errors) AS queued,
                started_at, finished_at, scheduled_at
         FROM dispatch_queue
         WHERE status IN ('running','paused','pending','cancelled','scheduled')
         ORDER BY created_at DESC LIMIT 50"
    )->fetchAll();

    $done_ids       = array_column($jobs_done, 'id');
    $queue_filtered = array_values(array_filter($jobs_queue, fn($j) => !in_array($j['id'], $done_ids)));
    $all = array_merge($jobs_done, $queue_filtered);
    // Scheduled jobs sem started_at: ordenar por scheduled_at no topo
    usort($all, function($a, $b) {
        $aTime = $a['scheduled_at'] ?? $a['started_at'] ?? '';
        $bTime = $b['scheduled_at'] ?? $b['started_at'] ?? '';
        // Scheduled sem started_at = mais recentes primeiro por scheduled_at
        $aSt = $a['_status'] ?? '';
        $bSt = $b['_status'] ?? '';
        if ($aSt === 'scheduled' && $bSt !== 'scheduled') return -1;
        if ($bSt === 'scheduled' && $aSt !== 'scheduled') return 1;
        return strcmp($b['started_at']??'', $a['started_at']??'');
    });
    $total = count($all);
    hok(['total'=>$total,'page'=>$page,'jobs'=>array_slice($all,$off,$per)]);
}

/* ── GET active ──────────────────────────────────────────────── */
if ($method === 'GET' && $action === 'active') {
    $jobs = hpdo()->query(
        "SELECT id, job_name, status, total, sent, errors,
                GREATEST(0, total - sent - errors) AS queued,
                current_index, started_at, delay_secs
         FROM dispatch_queue
         WHERE status IN ('running','paused','pending','scheduled')
         ORDER BY created_at DESC LIMIT 5"
    )->fetchAll();
    hok(['jobs'=>$jobs]);
}

/* ── POST resume ─────────────────────────────────────────────── */
if ($method === 'POST' && $action === 'resume') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = (int)($body['id'] ?? 0);
    if (!$id) herr('id obrigatorio');
    hpdo()->prepare("UPDATE dispatch_queue SET status='running' WHERE id=? AND status IN ('paused','cancelled')")->execute([$id]);
    hok();
}

/* ── POST cancel ─────────────────────────────────────────────── */
if ($method === 'POST' && $action === 'cancel') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = (int)($body['id'] ?? 0);
    if (!$id) herr('id obrigatorio');

    // Busca o job antes de cancelar para gravar histórico correto
    $qjob = hpdo()->query("SELECT * FROM dispatch_queue WHERE id=$id")->fetch();
    hpdo()->prepare("UPDATE dispatch_queue SET status='cancelled', finished_at=NOW() WHERE id=?")->execute([$id]);

    // Grava no histórico com contatos enfileirados marcados como 'queued'
    if ($qjob) {
        $contacts   = json_decode($qjob['contacts'] ?? '[]', true) ?: [];
        $variations = json_decode($qjob['variations'] ?? '[]', true) ?: [''];
        $done_idx   = (int)$qjob['current_index'];
        try {
            hpdo()->prepare(
                "INSERT INTO jobs (job_name, message_text, image_url, total, sent, errors, queued, started_at, finished_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE sent=VALUES(sent), errors=VALUES(errors), queued=VALUES(queued), finished_at=NOW()"
            )->execute([
                $qjob['job_name'], $variations[0] ?? '', $qjob['image_url'] ?? null,
                $qjob['total'], $qjob['sent'], $qjob['errors'],
                max(0, count($contacts) - $done_idx),
                $qjob['started_at'],
            ]);
            $jobHistId = (int) hpdo()->lastInsertId();
            if ($jobHistId) {
                $stmtJC = hpdo()->prepare(
                    "INSERT IGNORE INTO job_contacts (job_id, phone, status, sent_at, queued_at, error_msg) VALUES (?, ?, ?, ?, ?, ?)"
                );
                foreach ($contacts as $i => $c) {
                    if ($i < $done_idx) {
                        $stmtJC->execute([$jobHistId, $c['phone']??'', 'sent', date('Y-m-d H:i:s'), null, null]);
                    } else {
                        $stmtJC->execute([$jobHistId, $c['phone']??'', 'queued', null, date('Y-m-d H:i:s'), 'Cancelado manualmente']);
                    }
                }
            }
        } catch (\Throwable $e) {}

        // Zera a fila após cancelamento manual
        try { hpdo()->prepare("DELETE FROM dispatch_queue WHERE id=?")->execute([$id]); } catch (\Throwable $e) {}
    }
    hok();
}

/* ── POST clear (apagar histórico completo) ──────────────────── */
if ($method === 'POST' && $action === 'clear') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['password'])) herr('Senha obrigatoria');
    $stored = hpdo()->query("SELECT v FROM settings WHERE k='login_pass'")->fetchColumn() ?: 'casa123#';
    if ($body['password'] !== $stored) herr('Senha incorreta', 403);
    hpdo()->exec('DELETE FROM job_contacts');
    hpdo()->exec('DELETE FROM jobs');
    hpdo()->exec("DELETE FROM dispatch_queue WHERE status IN ('done','cancelled')");
    hok(['message'=>'Historico apagado.']);
}

herr('Acao nao encontrada', 405);