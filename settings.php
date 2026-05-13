<?php
/**
 * Casa das Flores — settings.php
 * Salva configurações do painel na tabela `settings` do banco.
 *
 * SQL (rode no phpMyAdmin se ainda não existir):
 * CREATE TABLE IF NOT EXISTS settings (
 *   k VARCHAR(100) PRIMARY KEY,
 *   v TEXT DEFAULT NULL,
 *   updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */
session_start();
if (empty($_SESSION['cdf_logged'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Não autorizado']);
    exit;
}

require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function sdb(): PDO {
    static $p;
    if (!$p) $p = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $p;
}

// Garante que a tabela existe
try {
    sdb()->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(100) PRIMARY KEY,
        v TEXT DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'Erro ao criar tabela settings: '.$e->getMessage()]);
    exit;
}

function sget_val(string $key, string $default = ''): string {
    try {
        $r = sdb()->prepare("SELECT v FROM settings WHERE k = ?");
        $r->execute([$key]);
        $v = $r->fetchColumn();
        return $v !== false ? (string)$v : $default;
    } catch (\Throwable $e) { return $default; }
}
function sset_val(string $key, string $value): void {
    sdb()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute([$key,$value]);
}

function ok(array $d=[]): never  { echo json_encode(array_merge(['ok'=>true],$d)); exit; }
function err(string $m,int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/* ── GET ─────────────────────────────────── */
if ($action === 'get' && $method === 'GET') {
    ok(['settings' => [
        'profile_name'  => sget_val('profile_name', 'Casa das Flores'),
        'phone'         => sget_val('phone', ''),
        'company_name'  => sget_val('company_name', 'Casa das Flores'),
        'city'          => sget_val('city', 'Florianópolis · SC'),
        'footer'        => sget_val('footer', 'desde 1983'),
        'templates'     => sget_val('templates', '{}'),
        'wa_status'     => sget_val('wa_status', ''),
        'onboarded'     => sget_val('onboarded', ''),
        'daily_limit'   => sget_val('daily_limit', '200'),
        'is_business'   => sget_val('is_business', '0'),
    ]]);
}

/* ── SAVE ────────────────────────────────── */
if ($action === 'save' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) err('Dados inválidos — JSON mal formado');

    // Campos de perfil
    $fields = ['profile_name','phone','company_name','city','footer','templates','onboarded','login_pass','daily_limit','is_business'];
    foreach ($fields as $f) {
        if (isset($body[$f])) sset_val($f, trim($body[$f]));
    }

    // Troca de senha
    if (!empty($body['pw_new'])) {
        $currentPass = sget_val('login_pass', 'casa123#');
        if (($body['pw_current'] ?? '') !== $currentPass) {
            err('Senha atual incorreta.');
        }
        if (strlen($body['pw_new']) < 6) {
            err('A nova senha deve ter pelo menos 6 caracteres.');
        }
        sset_val('login_pass', $body['pw_new']);
    }

    ok(['message' => 'Configurações salvas.']);
}

err('Ação desconhecida', 400);