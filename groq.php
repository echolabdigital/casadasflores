<?php
/**
 * Casa das Flores — groq.php
 *
 * Proxy seguro para geração de texto com IA:
 *   action=generate      → Groq API (Llama, gratuito, texto puro)
 *   action=img_status    → Verifica limite diário de imagem (Claude)
 *   action=img_increment → Incrementa contador de imagem no banco
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

// Autenticação via sessão PHP
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['cdf_logged'])) {
    // Fallback: token diário no header (para fetch com credentials)
    $token = $_SERVER['HTTP_X_PANEL_TOKEN'] ?? '';
    if ($token !== md5(GROQ_API_KEY . date('Y-m-d'))) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Não autorizado']);
        exit;
    }
}


header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

/* ── Helpers ─────────────────────────────────────────── */
function gpdo(): PDO {
    static $p;
    if (!$p) $p = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );
    return $p;
}

function img_key(): string {
    return 'ai_img_' . date('Y-m-d');
}

function img_count(): int {
    try {
        $r = gpdo()->prepare("SELECT v FROM settings WHERE k=?");
        $r->execute([img_key()]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['v'] : 0;
    } catch(\Throwable $e) { return 0; }
}

function img_increment(): int {
    $key = img_key();
    $current = img_count();
    $next = $current + 1;
    try {
        gpdo()->prepare(
            "INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?"
        )->execute([$key, $next, $next]);
    } catch(\Throwable $e) {}
    return $next;
}

/* ── action=img_status ───────────────────────────────── */
if ($action === 'img_status') {
    $used      = img_count();
    $limit     = AI_IMG_DAILY_LIMIT;
    $remaining = max(0, $limit - $used);
    echo json_encode([
        'ok'        => true,
        'used'      => $used,
        'limit'     => $limit,
        'remaining' => $remaining,
        'allowed'   => $remaining > 0,
    ]);
    exit;
}

/* ── action=img_increment ────────────────────────────── */
if ($action === 'img_increment') {
    $used      = img_increment();
    $remaining = max(0, AI_IMG_DAILY_LIMIT - $used);
    echo json_encode([
        'ok'        => true,
        'used'      => $used,
        'remaining' => $remaining,
    ]);
    exit;
}

/* ── action=generate (Groq — texto puro) ─────────────── */
if ($action === 'generate') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['system']) || empty($body['prompt'])) {
        echo json_encode(['ok'=>false,'error'=>'system e prompt obrigatórios']); exit;
    }

    $payload = [
        'model'       => GROQ_MODEL,
        'max_tokens'  => 1500,
        'temperature' => 0.85,
        'messages'    => [
            ['role'=>'system', 'content'=>$body['system']],
            ['role'=>'user',   'content'=>$body['prompt']],
        ],
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { echo json_encode(['ok'=>false,'error'=>$err]); exit; }

    $data = json_decode($raw, true);
    if ($code !== 200) {
        $msg = $data['error']['message'] ?? "Erro HTTP $code";
        echo json_encode(['ok'=>false,'error'=>$msg]); exit;
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    echo json_encode(['ok'=>true,'text'=>$text]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Ação desconhecida']);
