<?php
/**
 * Casa das Flores — upload.php
 *
 * Recebe imagem via POST multipart/form-data (campo: image).
 * Faz upload para o Supabase Storage (CDN global) em vez do disco local.
 * Retorna a URL pública do Supabase para usar no disparo via Z-API.
 *
 * Fluxo:
 *   1. Valida tipo e tamanho
 *   2. Calcula hash MD5 — se já existe no banco, retorna URL em cache
 *   3. Envia para Supabase Storage via REST API (PUT)
 *   4. Registra no MySQL (tabela images) com a URL pública do CDN
 *   5. Retorna { ok: true, url: "https://...supabase.co/..." }
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { upload_error('Método não permitido', 405); }

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    upload_error('Arquivo não recebido ou erro no upload (' . ($_FILES['image']['error'] ?? 'sem arquivo') . ')');
}

$file    = $_FILES['image'];
$mime    = mime_content_type($file['tmp_name']);
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if (!in_array($mime, $allowed))                        upload_error('Tipo não permitido. Use JPG, PNG, GIF ou WEBP.');
if ($file['size'] > MAX_SIZE_MB * 1024 * 1024)        upload_error('Imagem muito grande. Máximo ' . MAX_SIZE_MB . 'MB.');

$hash = md5_file($file['tmp_name']);
$ext  = match($mime) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    default      => 'jpg'
};

// ── Verifica cache (hash já enviado antes) ──────────────────────────
$pdo  = upload_db();
$stmt = $pdo->prepare('SELECT public_url FROM images WHERE file_hash = ? LIMIT 1');
$stmt->execute([$hash]);
$existing = $stmt->fetchColumn();
if ($existing) {
    echo json_encode(['ok' => true, 'url' => $existing, 'cached' => true]);
    exit;
}

// ── Nome único no bucket ────────────────────────────────────────────
$name    = date('Ymd_His') . '_' . substr($hash, 0, 8) . '.' . $ext;
$imgData = file_get_contents($file['tmp_name']);

// ── Upload para Supabase Storage via REST ───────────────────────────
$supabaseUrl = SUPABASE_URL . '/storage/v1/object/' . SUPABASE_BUCKET . '/' . $name;

$ch = curl_init($supabaseUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',       // POST = cria novo; PUT = upsert
    CURLOPT_POSTFIELDS     => $imgData,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: ' . $mime,
        'Content-Length: ' . strlen($imgData),
        'x-upsert: true',                  // sobrescreve se já existir mesmo nome
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    upload_error('Erro de conexão com Supabase: ' . $err, 500);
}

$resp = json_decode($raw, true);

// Supabase retorna 200 com { Key: "uploads/nome.jpg" } em sucesso
if ($code < 200 || $code >= 300) {
    $detail = $resp['message'] ?? $resp['error'] ?? substr($raw, 0, 200);
    upload_error('Supabase recusou o upload (HTTP ' . $code . '): ' . $detail, 500);
}

// ── URL pública do CDN ──────────────────────────────────────────────
$publicUrl = SUPABASE_PUBLIC_BASE . $name;

// ── Registra no MySQL ───────────────────────────────────────────────
try {
    $pdo->prepare(
        'INSERT INTO images (original_name, stored_name, file_hash, mime_type, size_bytes, public_url, uploaded_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$file['name'], $name, $hash, $mime, $file['size'], $publicUrl]);
} catch (\Throwable $e) {
    // Não falha o upload se o registro no banco falhar — a imagem já está no Supabase
    error_log('upload.php: falha ao registrar no MySQL: ' . $e->getMessage());
}

echo json_encode(['ok' => true, 'url' => $publicUrl, 'cached' => false, 'storage' => 'supabase']);

// ─── Helpers ────────────────────────────────────────────────────────
function upload_db(): PDO {
    try {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (\PDOException $e) {
        upload_error('Erro no banco de dados: ' . $e->getMessage(), 500);
    }
}

function upload_error(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}