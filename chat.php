<?php
/**
 * Casa das Flores — chat.php
 * API de conversas WhatsApp
 *
 * GET  ?action=list                 → lista de conversas (exclui grupos, JOIN com CRM)
 * GET  ?action=messages&phone=XX    → mensagens de uma conversa
 * GET  ?action=poll&since_id=N      → novas mensagens (polling leve)
 * GET  ?action=connection_status    → status de conexão Z-API (para banner no frontend)
 * POST ?action=send                 → envia mensagem
 * POST ?action=read&phone=XX        → marca como lida
 * POST ?action=add_crm              → cadastra contato no CRM a partir de uma conversa
 */
session_start();
if (empty($_SESSION['cdf_logged'])) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }

require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json');

function cpdo(): PDO {
    static $p;
    if (!$p) {
        $p = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
        );
        try {
            $p->exec("CREATE TABLE IF NOT EXISTS messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(30) NOT NULL,
                from_me TINYINT(1) NOT NULL DEFAULT 0,
                body TEXT NOT NULL,
                msg_type VARCHAR(20) NOT NULL DEFAULT 'text',
                media_url TEXT DEFAULT NULL,
                msg_id VARCHAR(100) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'received',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_phone (phone),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {}
    }
    return $p;
}

function zapi_url_c(string $path): string {
    return ZAPI_BASE . '/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN . $path;
}
function zapi_get_c(string $path): array {
    $ch = curl_init(zapi_url_c($path));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Client-Token: ' . ZAPI_CLIENT_TOKEN],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($cerr) return ['_error' => $cerr, '_http_code' => 0];
    return array_merge(['_http_code' => $code], json_decode($raw, true) ?? []);
}

function clean_phone(string $phone): string {
    return preg_replace('/\D/', '', $phone);
}
function fmt_phone_display(string $p): string {
    $p = clean_phone($p);
    if (strlen($p) === 13 && str_starts_with($p, '55'))
        return '+55 (' . substr($p,2,2) . ') ' . substr($p,4,5) . '-' . substr($p,9);
    if (strlen($p) === 11)
        return '(' . substr($p,0,2) . ') ' . substr($p,2,5) . '-' . substr($p,7);
    if (strlen($p) === 10)
        return '(' . substr($p,0,2) . ') ' . substr($p,2,4) . '-' . substr($p,6);
    return $p;
}

/**
 * Detecta grupo do WhatsApp:
 * - IDs de grupo têm tipicamente 18 dígitos ou mais (ex: 120363397414640724)
 * - Ou começam com "120363" (padrão de grupos do WhatsApp Business)
 */
function is_group_phone(string $phone): bool {
    $clean = clean_phone($phone);
    return strlen($clean) >= 17 || str_starts_with($clean, '120363');
}

/**
 * Mapeia [mensagem] e outros placeholders para texto legível
 */
function preview_body(string $body, string $type): string {
    if ($type === 'image')    return '📷 Imagem' . ($body && $body !== '[mensagem]' ? ': '.$body : '');
    if ($type === 'audio')    return '🎵 Áudio';
    if ($type === 'video')    return '🎬 Vídeo';
    if ($type === 'document') return '📄 Documento';
    if ($type === 'sticker')  return '😊 Figurinha';
    if ($body === '[mensagem]') return '📌 Mídia';
    return $body;
}

/**
 * Normaliza phone para fazer JOIN robusto com contacts:
 * Remove 55 do início se resultar em 11 dígitos (BR mobile/fixo).
 */
function normalize_for_join(string $phone): string {
    $clean = clean_phone($phone);
    if (strlen($clean) === 13 && str_starts_with($clean, '55')) return substr($clean, 2);
    return $clean;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/* ── CONNECTION STATUS ─────────────────────────────────────── */
if ($action === 'connection_status') {
    // Lê estado do banco (gravado pelo webhook) — rápido, sem timeout de rede
    $dbStatus      = null;
    $connectedAt   = null;
    $disconnectedAt = null;
    try {
        $rows = cpdo()->query(
            "SELECT k, v FROM settings WHERE k IN ('wa_status','wa_connected_at','wa_disconnected_at')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $dbStatus       = $rows['wa_status']        ?? null;
        $connectedAt    = $rows['wa_connected_at']   ?? null;
        $disconnectedAt = $rows['wa_disconnected_at'] ?? null;
    } catch (\Throwable $e) {}

    // Se o banco tem estado recente (< 5 min), confia nele e não pinga a Z-API
    $usedDB = false;
    if ($dbStatus) {
        $lastEvent = $dbStatus === 'connected' ? $connectedAt : $disconnectedAt;
        if ($lastEvent && (time() - strtotime($lastEvent)) < 300) {
            $usedDB = true;
        }
    }

    if ($usedDB) {
        echo json_encode([
            'ok'             => true,
            'connected'      => $dbStatus === 'connected',
            'status'         => $dbStatus === 'connected' ? 'CONNECTED' : 'DISCONNECTED',
            'connected_at'   => $connectedAt,
            'disconnected_at'=> $disconnectedAt,
            'source'         => 'webhook',
        ]);
        exit;
    }

    // Fallback: pinga a Z-API diretamente (estado desconhecido ou > 5 min sem evento)
    $st = zapi_get_c('/status');
    $connected = !empty($st['connected'])
              || ($st['_http_code'] === 200 && ($st['status'] ?? '') === 'CONNECTED');

    // Atualiza o banco com o resultado do ping (para futuros requests usarem o cache)
    if ($dbStatus !== ($connected ? 'connected' : 'disconnected')) {
        try {
            cpdo()->prepare(
                "INSERT INTO settings (k,v) VALUES ('wa_status',?)
                 ON DUPLICATE KEY UPDATE v=VALUES(v)"
            )->execute([$connected ? 'connected' : 'disconnected']);
            $tsKey = $connected ? 'wa_connected_at' : 'wa_disconnected_at';
            $now   = date('Y-m-d H:i:s');
            cpdo()->prepare(
                "INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)"
            )->execute([$tsKey, $now]);
            if ($connected) $connectedAt    = $now;
            else            $disconnectedAt = $now;
        } catch (\Throwable $e) {}
    }

    echo json_encode([
        'ok'              => true,
        'connected'       => $connected,
        'status'          => $st['status'] ?? ($connected ? 'CONNECTED' : 'DISCONNECTED'),
        'connected_at'    => $connectedAt,
        'disconnected_at' => $disconnectedAt,
        'source'          => 'zapi_ping',
        'http'            => $st['_http_code'],
    ]);
    exit;
}

/* ── LIST — conversas recentes (sem grupos) ────────────────── */
if ($action === 'list' && $method === 'GET') {
    $search = trim($_GET['search'] ?? '');
    try {
        $rows = cpdo()->query("
            SELECT
                m.phone,
                m.body          AS last_body,
                m.msg_type      AS last_type,
                m.from_me       AS last_from_me,
                m.created_at    AS last_at,
                SUM(CASE WHEN m2.from_me=0 AND m2.status='received' THEN 1 ELSE 0 END) AS unread,
                c.id            AS crm_id,
                c.name          AS crm_name,
                c.empresa       AS crm_empresa,
                c.status        AS crm_status
            FROM messages m
            LEFT JOIN messages m2 ON m2.phone = m.phone
            LEFT JOIN contacts c  ON (
                c.phone = m.phone
                OR c.phone = CONCAT('55', m.phone)
                OR (LENGTH(m.phone) = 13 AND c.phone = SUBSTRING(m.phone, 3))
            )
            WHERE m.id IN (SELECT MAX(id) FROM messages GROUP BY phone)
            GROUP BY m.phone, m.body, m.msg_type, m.from_me, m.created_at,
                     c.id, c.name, c.empresa, c.status
            ORDER BY m.created_at DESC
            LIMIT 200
        ")->fetchAll();

        // Filtra grupos e aplica search no PHP (mais simples que SQL complexo)
        $filtered = [];
        foreach ($rows as $r) {
            // Pula grupos do WhatsApp
            if (is_group_phone($r['phone'])) continue;
            // Search: filtra por nome/empresa/telefone se informado
            if ($search) {
                $haystack = strtolower(($r['crm_name'] ?? '') . ' ' . ($r['crm_empresa'] ?? '') . ' ' . $r['phone']);
                if (strpos($haystack, strtolower($search)) === false) continue;
            }
            $r['preview']    = preview_body($r['last_body'] ?? '', $r['last_type'] ?? 'text');
            $r['in_crm']     = !empty($r['crm_id']);
            $r['display_name'] = $r['crm_empresa'] ?: ($r['crm_name'] ?: fmt_phone_display($r['phone']));
            $filtered[] = $r;
        }

        echo json_encode(['ok' => true, 'chats' => $filtered, 'total' => count($filtered)]);
    } catch(\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ── MESSAGES — histórico de uma conversa ─────────────────── */
if ($action === 'messages' && $method === 'GET') {
    $phone = clean_phone($_GET['phone'] ?? '');
    if (!$phone) { echo json_encode(['ok'=>false,'error'=>'phone obrigatório']); exit; }
    try {
        $st = cpdo()->prepare("
            SELECT id, phone, from_me, body, msg_type, media_url, status, created_at
            FROM messages WHERE phone=? ORDER BY created_at ASC LIMIT 300
        ");
        $st->execute([$phone]);
        $msgs = $st->fetchAll();

        // Mapeia preview nos bodies
        foreach ($msgs as &$m) {
            $m['preview'] = preview_body($m['body'] ?? '', $m['msg_type'] ?? 'text');
        }
        unset($m);

        // Contato no CRM (busca flexível por telefone)
        $contact = null;
        $phones = array_unique(array_filter([$phone, 'br'===substr($phone,0,2)?substr($phone,2):null, '55'.$phone]));
        foreach ($phones as $ph) {
            $cst = cpdo()->prepare("SELECT id, name, empresa, status FROM contacts WHERE phone=? LIMIT 1");
            $cst->execute([$ph]);
            $contact = $cst->fetch() ?: null;
            if ($contact) break;
        }

        // Marca como lidas
        cpdo()->prepare("UPDATE messages SET status='read' WHERE phone=? AND from_me=0 AND status='received'")->execute([$phone]);

        echo json_encode([
            'ok'            => true,
            'messages'      => $msgs,
            'contact'       => $contact,
            'in_crm'        => !empty($contact),
            'phone_display' => fmt_phone_display($phone),
        ]);
    } catch(\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ── POLL — mensagens novas ─────────────────────────────────── */
if ($action === 'poll' && $method === 'GET') {
    $since_id    = (int)($_GET['since_id'] ?? 0);
    $since_phone = clean_phone($_GET['phone'] ?? '');
    try {
        if ($since_phone) {
            $st = cpdo()->prepare("SELECT id,phone,from_me,body,msg_type,media_url,status,created_at FROM messages WHERE phone=? AND id>? ORDER BY created_at ASC");
            $st->execute([$since_phone, $since_id]);
        } else {
            $st = cpdo()->prepare("SELECT MAX(id) as last_id, phone, body AS last_body, msg_type AS last_type, from_me AS last_from_me, created_at AS last_at, SUM(CASE WHEN from_me=0 AND status='received' THEN 1 ELSE 0 END) AS unread FROM messages WHERE id>? GROUP BY phone ORDER BY MAX(id) DESC LIMIT 50");
            $st->execute([$since_id]);
        }
        $rows = $st->fetchAll();
        // Adiciona preview em cada linha
        foreach ($rows as &$r) {
            $r['preview'] = preview_body($r['last_body'] ?? $r['body'] ?? '', $r['last_type'] ?? $r['msg_type'] ?? 'text');
        }
        unset($r);
        $last = (int)cpdo()->query("SELECT COALESCE(MAX(id),0) FROM messages")->fetchColumn();
        echo json_encode(['ok'=>true,'rows'=>$rows,'last_id'=>$last]);
    } catch(\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ── SEND ───────────────────────────────────────────────────── */
if ($action === 'send' && $method === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true);
    $phone = clean_phone($body['phone'] ?? '');
    $text  = trim($body['message'] ?? '');
    if (!$phone || !$text) { echo json_encode(['ok'=>false,'error'=>'phone e message obrigatórios']); exit; }

    $phoneZapi = strlen($phone) === 11 ? '55'.$phone : $phone;
    $ch = curl_init(zapi_url_c('/send-text'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['phone'=>$phoneZapi,'message'=>$text]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Client-Token: '.ZAPI_CLIENT_TOKEN],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resp = json_decode($raw, true) ?? [];
    $ok   = ($code >= 200 && $code < 300) && !empty($resp['zaapId'] ?? $resp['messageId'] ?? $resp['id'] ?? null);
    if ($ok) {
        try {
            cpdo()->prepare(
                "INSERT INTO messages (phone,from_me,body,msg_type,msg_id,status) VALUES (?,1,?,'text',?,'sent')"
            )->execute([$phone, $text, $resp['zaapId'] ?? $resp['messageId'] ?? null]);
        } catch(\Throwable $e) {}
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'Falha ao enviar: '.$raw]);
    }
    exit;
}

/* ── READ ───────────────────────────────────────────────────── */
if ($action === 'read' && $method === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true);
    $phone = clean_phone($body['phone'] ?? '');
    if (!$phone) { echo json_encode(['ok'=>false]); exit; }
    try {
        cpdo()->prepare("UPDATE messages SET status='read' WHERE phone=? AND from_me=0 AND status='received'")->execute([$phone]);
        echo json_encode(['ok'=>true]);
    } catch(\Throwable $e) { echo json_encode(['ok'=>false]); }
    exit;
}

/* ── ADD_CRM — cadastra contato a partir de uma conversa ────── */
if ($action === 'add_crm' && $method === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true);
    $phone   = clean_phone($body['phone']   ?? '');
    $name    = trim($body['name']    ?? '');
    $empresa = trim($body['empresa'] ?? '');
    if (!$phone) { echo json_encode(['ok'=>false,'error'=>'phone obrigatório']); exit; }

    // Normaliza para formato BR
    if (strlen($phone) === 13 && str_starts_with($phone, '55')) $phone = substr($phone, 2);

    try {
        // Garante coluna extra existe
        try { cpdo()->exec("ALTER TABLE contacts ADD COLUMN last_campaign VARCHAR(255) DEFAULT NULL"); } catch(\Throwable $e) {}
        try { cpdo()->exec("ALTER TABLE contacts ADD COLUMN blocked TINYINT(1) DEFAULT 0"); } catch(\Throwable $e) {}

        $st = cpdo()->prepare(
            "INSERT INTO contacts (phone, name, empresa, status, source, created_at, updated_at)
             VALUES (?, ?, ?, 'em_contato', 'Conversa WhatsApp', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               name    = IF(name    IS NULL OR name='',    VALUES(name),    name),
               empresa = IF(empresa IS NULL OR empresa='', VALUES(empresa), empresa),
               status  = IF(status='pendente', 'em_contato', status),
               updated_at = NOW()"
        );
        $st->execute([$phone, $name ?: null, $empresa ?: null]);
        $id = cpdo()->lastInsertId();

        // Busca o contato recém-criado/atualizado
        $contact = cpdo()->prepare("SELECT id,name,empresa,status FROM contacts WHERE phone=? LIMIT 1");
        $contact->execute([$phone]);
        $c = $contact->fetch() ?: ['id' => $id];

        echo json_encode(['ok'=>true,'contact'=>$c,'inserted'=> (int)$id > 0]);
    } catch(\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ── SYNC_NOW — limpa histórico, novas mensagens chegam via webhook ── */
if ($action === 'sync_now' && $method === 'POST') {
    try {
        cpdo()->exec("TRUNCATE TABLE messages");
        echo json_encode(['ok' => true, 'imported' => 0]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── CLEAR_HISTORY — limpa toda a tabela messages ── */
if ($action === 'clear_history' && $method === 'POST') {
    try {
        cpdo()->exec("TRUNCATE TABLE messages");
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Ação desconhecida']);
