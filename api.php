<?php
/**
 * Casa das Flores — api.php (Z-API)
 *
 * Endpoints Z-API confirmados pela documentação oficial:
 *   GET  /instances/{id}/token/{tk}/qr-code/image      → QR base64
 *   GET  /instances/{id}/token/{tk}/status             → { connected, smartphoneConnected }
 *   POST /instances/{id}/token/{tk}/send-text          → envia texto
 *   POST /instances/{id}/token/{tk}/send-image         → envia imagem
 *   GET  /instances/{id}/token/{tk}/restart            → reinicia sessão
 *   GET  /instances/{id}/token/{tk}/disconnect         → desconecta
 *
 * Header obrigatório: Client-Token: <SECURITY_TOKEN>
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function zapi_url(string $path): string {
    return ZAPI_BASE . '/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN . $path;
}

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'status': {
        $res = zapi_request('GET', zapi_url('/status'));
        // Z-API devolve { connected: bool, smartphoneConnected: bool }
        $res['connected'] = !empty($res['connected']);
        $res['status']    = $res['connected'] ? 'CONNECTED' : 'DISCONNECTED';
        echo json_encode($res);
        break;
    }

    case 'device': {
        // Retorna { phone, name, imgUrl, isBusiness, ... } da conta conectada
        $res = zapi_request('GET', zapi_url('/device'));
        echo json_encode($res);
        break;
    }

    case 'phone_exists': {
        // Verifica se um número tem WhatsApp ativo
        $phone = sanitize_phone($_GET['phone'] ?? '');
        if (!$phone) json_error('phone obrigatório');
        $res = zapi_request('GET', zapi_url('/phone-exists/' . $phone));
        // Z-API: { exists: bool, phone: "..." }
        echo json_encode($res);
        break;
    }

    case 'phone_exists_batch': {
        // Verifica vários números em uma chamada
        $body = json_decode(file_get_contents('php://input'), true);
        $phones = $body['phones'] ?? [];
        if (!is_array($phones) || !count($phones)) json_error('phones obrigatório (array)');
        $results = [];
        foreach ($phones as $p) {
            $clean = sanitize_phone($p);
            if (!$clean) { $results[] = ['phone'=>$p, 'exists'=>false, 'error'=>'invalid']; continue; }
            $r = zapi_request('GET', zapi_url('/phone-exists/' . $clean));
            $results[] = [
                'phone'  => $p,
                'clean'  => $clean,
                'exists' => !empty($r['exists']),
            ];
            usleep(200000); // 200ms entre chamadas pra não sobrecarregar
        }
        echo json_encode(['ok'=>true, 'results'=>$results]);
        break;
    }

    case 'qr': {
        $res = zapi_request('GET', zapi_url('/qr-code/image'));
        // Retorna { value: "data:image/png;base64,..." } ou { connected: true }
        if (!empty($res['value'])) {
            $res['qrcode'] = $res['value'];
        }
        echo json_encode($res);
        break;
    }

    case 'restart': {
        $res = zapi_request('GET', zapi_url('/restart'));
        echo json_encode($res);
        break;
    }

    case 'disconnect': {
        $res = zapi_request('GET', zapi_url('/disconnect'));
        echo json_encode($res);
        break;
    }

    case 'send_text': {
        $body = json_decode(file_get_contents('php://input'), true);
        if (empty($body['phone']) || empty($body['message'])) json_error('phone e message obrigatórios');
        $res = zapi_request('POST', zapi_url('/send-text'), [
            'phone'   => sanitize_phone($body['phone']),
            'message' => $body['message'],
        ]);
        echo json_encode($res);
        break;
    }

    case 'send_media': {
        $body = json_decode(file_get_contents('php://input'), true);
        if (empty($body['phone']) || empty($body['image_url'])) json_error('phone e image_url obrigatórios');
        $res = zapi_request('POST', zapi_url('/send-image'), [
            'phone'   => sanitize_phone($body['phone']),
            'image'   => $body['image_url'],
            'caption' => $body['message'] ?? '',
        ]);
        echo json_encode($res);
        break;
    }

    case 'send_document': {
        $body = json_decode(file_get_contents('php://input'), true);
        if (empty($body['phone']) || empty($body['document_url'])) json_error('phone e document_url obrigatórios');
        // Z-API pede o tipo na URL: /send-document/{extension}
        $ext = strtolower(pathinfo(parse_url($body['document_url'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'pdf');
        $res = zapi_request('POST', zapi_url('/send-document/' . $ext), [
            'phone'    => sanitize_phone($body['phone']),
            'document' => $body['document_url'],
            'fileName' => $body['filename'] ?? ('documento.' . $ext),
            'caption'  => $body['message'] ?? '',
        ]);
        echo json_encode($res);
        break;
    }

    case 'send_audio': {
        $body = json_decode(file_get_contents('php://input'), true);
        if (empty($body['phone']) || empty($body['audio_url'])) json_error('phone e audio_url obrigatórios');
        $res = zapi_request('POST', zapi_url('/send-audio'), [
            'phone'  => sanitize_phone($body['phone']),
            'audio'  => $body['audio_url'],
            'viewOnce' => false,
            'waveform' => true,
        ]);
        echo json_encode($res);
        break;
    }

    default:
        json_error('Ação desconhecida', 400);
}

/* ── Helpers ──────────────────────────────────────────────── */

function zapi_request(string $method, string $url, ?array $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Client-Token: ' . ZAPI_CLIENT_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    if ($body !== null && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok'=>false,'error'=>$err,'_http_code'=>$code];
    $decoded = json_decode($raw, true);
    if ($decoded === null) return ['ok'=>false,'raw'=>$raw,'_http_code'=>$code];
    return array_merge(['ok'=>($code>=200 && $code<300),'_http_code'=>$code], $decoded);
}

function sanitize_phone(string $phone): string {
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 13 && substr($phone, 0, 2) === '55') $phone = substr($phone, 2);
    if (strlen($phone) === 12 && substr($phone, 0, 2) === '55') $phone = substr($phone, 2);
    if (strlen($phone) === 10) $phone = substr($phone, 0, 2) . '9' . substr($phone, 2);
    if (strlen($phone) === 11 || strlen($phone) === 10) $phone = '55' . $phone;
    return $phone;
}

function json_error(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$msg]);
    exit;
}
