<?php
/**
 * Casa das Flores — extrator.php
 * Proxy para Google Places API (New) — searchText
 *
 * Migrado em 2026-05 da Places API legada para a NOVA Places API (v1):
 *   • Telefone vem direto na busca (não precisa mais de chamada /details)
 *   • FieldMask explícito reduz custo de billing
 *   • Token de paginação responde mais rápido (~2s vs ~5s)
 *
 * Endpoints:
 *   GET  ?action=search   — busca empresas: ?keyword=X&city=Y[&pagetoken=...]
 *   GET  ?action=status   — verifica se chave está configurada
 *
 * Frontend orquestra paginação (até 3 páginas/60 leads por query) e
 * multi-bairro (várias queries deduplicadas pelo place_id).
 */
session_start();
if (empty($_SESSION['cdf_logged'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ── Helpers ─────────────────────────────── */
function ok(array $d = []): never  { echo json_encode(array_merge(['ok' => true], $d)); exit; }
function err(string $m, int $c = 400): never {
    http_response_code($c);
    echo json_encode(['ok' => false, 'error' => $m]);
    exit;
}

/**
 * Chama a NEW Places API — searchText.
 * Quando $pageToken é fornecido, manda APENAS o token (regra da API).
 * Lança RuntimeException com mensagem amigável em caso de erro.
 */
function gmaps_search_new(string $textQuery, ?string $pageToken, string $apiKey): array {
    // New Places API: pageToken é ADICIONAL aos parâmetros originais, não substitui.
    // Mandar só o token (como o código antigo fazia) → a API ignora a paginação.
    $payload = [
        'textQuery'    => $textQuery,
        'languageCode' => 'pt-BR',
        'regionCode'   => 'BR',
    ];
    if ($pageToken) {
        $payload['pageToken'] = $pageToken;
    }

    // FieldMask = controle de custo. Trazemos só o que importa.
    $fieldMask = implode(',', [
        'places.id',
        'places.displayName',
        'places.nationalPhoneNumber',
        'places.internationalPhoneNumber',
        'places.rating',
        'places.userRatingCount',
        'places.formattedAddress',
        'places.location',           // lat/lng — útil para mapa/rota futuros
        'places.types',
        'places.websiteUri',
        'nextPageToken',
    ]);

    $ch = curl_init('https://places.googleapis.com/v1/places:searchText');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'CasadasFlores/2.0',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $apiKey,
            'X-Goog-FieldMask: ' . $fieldMask,
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($cerr) {
        throw new RuntimeException("Falha de rede ao chamar Google: $cerr");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Resposta inválida do Google (HTTP $code).");
    }
    if ($code !== 200) {
        $msg = $data['error']['message'] ?? "HTTP $code";
        $st  = $data['error']['status']  ?? '';
        throw new RuntimeException(trim("Google API ($st): $msg"));
    }

    return [
        'places'        => $data['places']        ?? [],
        'nextPageToken' => $data['nextPageToken'] ?? null,
    ];
}

/* ── Chave (config.php) ──────────────────── */
$API_KEY = defined('GMAPS_API_KEY') ? GMAPS_API_KEY : '';
$action  = $_GET['action'] ?? '';
$method  = $_SERVER['REQUEST_METHOD'];

/* ── STATUS ──────────────────────────────── */
if ($action === 'status') {
    ok([
        'configured'  => $API_KEY !== '',
        'key_preview' => $API_KEY ? substr($API_KEY, 0, 8) . '…' : null,
        'api_version' => 'places-api-new-v1',
    ]);
}

/* ── SEARCH ──────────────────────────────── */
if ($action === 'search' && $method === 'GET') {
    if (!$API_KEY) {
        err('Chave da Google Places API não configurada no servidor. Defina a constante GMAPS_API_KEY em config.php.');
    }

    $keyword   = trim($_GET['keyword']   ?? '');
    $city      = trim($_GET['city']      ?? '');
    $pagetoken = trim($_GET['pagetoken'] ?? '');

    if (!$keyword && !$pagetoken) {
        err('Informe ao menos uma categoria ou termo de busca.');
    }

    // textQuery: junta tudo que veio (frontend já formata "categoria bairro cidade").
    $textQuery = trim("$keyword $city");
    if (!$textQuery) $textQuery = $keyword ?: $city;

    try {
        $res = gmaps_search_new($textQuery, $pagetoken ?: null, $API_KEY);
    } catch (RuntimeException $e) {
        err($e->getMessage());
    }

    $results = [];
    foreach ($res['places'] as $p) {
        // Telefone: prefere nacional (formato BR), fallback internacional
        $phone = $p['nationalPhoneNumber']
              ?? $p['internationalPhoneNumber']
              ?? null;

        $results[] = [
            'place_id' => $p['id']                  ?? '',
            'empresa'  => $p['displayName']['text'] ?? '',
            'phone'    => $phone,
            'rating'   => (float)($p['rating']          ?? 0),
            'reviews'  => (int)  ($p['userRatingCount'] ?? 0),
            'address'  => $p['formattedAddress']    ?? '',
            'lat'      => isset($p['location']['latitude'])  ? (float)$p['location']['latitude']  : null,
            'lng'      => isset($p['location']['longitude']) ? (float)$p['location']['longitude'] : null,
            'types'    => $p['types']               ?? [],
            'website'  => $p['websiteUri']          ?? null,
        ];
    }

    // ── Dedup contra CRM (busca rolante) ──
    // Quando o frontend pede ?exclude_in_crm=1, removemos os place_ids que
    // já estão na tabela contacts. Resultado: cada nova busca traz só novos.
    $excludedInCrm = 0;
    if (!empty($_GET['exclude_in_crm']) && $results) {
        try {
            $pdo = new PDO(
                'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            // Garante coluna existir antes da consulta
            try { $pdo->exec("ALTER TABLE contacts ADD COLUMN place_id VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { $pdo->exec("CREATE INDEX idx_contacts_place_id ON contacts(place_id)"); } catch (\Throwable $e) {}

            $ids = array_values(array_filter(array_column($results, 'place_id')));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare("SELECT place_id FROM contacts WHERE place_id IN ($ph)");
                $st->execute($ids);
                $existing = array_flip($st->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $before = count($results);
                $results = array_values(array_filter($results, fn($r) => !isset($existing[$r['place_id']])));
                $excludedInCrm = $before - count($results);
            }
        } catch (\Throwable $e) {
            // Se DB falhar, segue sem dedup (não derruba a busca)
        }
    }

    ok([
        'results'          => $results,
        'total'            => count($results),
        'next_page_token'  => $res['nextPageToken'],
        'query_used'       => $textQuery,
        'excluded_in_crm'  => $excludedInCrm,
    ]);
}

err('Ação desconhecida', 400);
