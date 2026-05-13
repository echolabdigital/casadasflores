<?php
/**
 * Casa das Flores — crm.php
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function cdb(): PDO {
    static $p;
    if (!$p) $p = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $p;
}
function cok(array $d = []): never  { echo json_encode(array_merge(['ok' => true], $d)); exit; }
function cerr(string $m, int $c = 400): never { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

/**
 * Normaliza um número de telefone brasileiro para o padrão Z-API: 55DDD9XXXXXXXX (13 dígitos).
 * Retorna string normalizada ou null se o número for inválido/impossível de usar.
 */
function normalizePhone(string $raw): ?string {
    static $ddds = [
        '11','12','13','14','15','16','17','18','19',
        '21','22','24','27','28',
        '31','32','33','34','35','37','38',
        '41','42','43','44','45','46','47','48','49',
        '51','53','54','55','61','62','63','64','65','66','67','68','69',
        '71','73','74','75','77','79',
        '81','82','83','84','85','86','87','88','89',
        '91','92','93','94','95','96','97','98','99',
    ];

    $d = preg_replace('/\D/', '', $raw);
    if ($d === '') return null;

    // Remove 0055 (discagem internacional com zero)
    if (str_starts_with($d, '0055')) $d = substr($d, 4);

    // Remove CSP (código de operadora: 0XX) se sobrar 10 ou 11 dígitos após remoção
    if (isset($d[0]) && $d[0] === '0' && !str_starts_with($d, '00')) {
        $sem = substr($d, 3);
        $l   = strlen($sem);
        if ($l === 10 || $l === 11) {
            $d = $sem;
        } elseif ($l === 13 && str_starts_with($sem, '55')) {
            $d = $sem;
        }
    }

    // Garante prefixo 55
    if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;

    // Adiciona 9 em celulares antigos (55+DDD+8dig = 12 → 13)
    if (strlen($d) === 12 && str_starts_with($d, '55')) {
        $ddd2 = substr($d, 2, 2);
        $num2 = substr($d, 4);
        if (in_array($num2[0], ['6','7','8','9'])) $d = '55' . $ddd2 . '9' . $num2;
    }

    // Validação: 13 dígitos, prefixo 55
    if (strlen($d) !== 13 || !str_starts_with($d, '55')) return null;

    $ddd = substr($d, 2, 2);
    $num = substr($d, 4);

    if (!in_array($ddd, $ddds))                         return null; // DDD inexistente
    if (!in_array($num[0], ['2','3','4','5','9']))      return null; // 1 = inválido; 0 = inválido
    if (preg_match('/^(\d)\1{8}$/', $num))              return null; // sequência repetida (fake)

    return $d;
}


$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/* ── LIST ──────────────────────────────────────────────── */
if ($action === 'list' && $method === 'GET') {
    $status   = $_GET['status'] ?? 'all';
    $search   = trim($_GET['search'] ?? '');
    $source   = trim($_GET['source'] ?? '');
    $campaign = trim($_GET['campaign'] ?? '');
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per      = min(200, max(10, (int)($_GET['per'] ?? 50)));
    $offset   = ($page - 1) * $per;

    // Garante que as colunas de campanha existem (antes de qualquer SELECT)
    try { cdb()->exec("ALTER TABLE contacts ADD COLUMN last_campaign VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { cdb()->exec("ALTER TABLE contacts ADD COLUMN last_dispatched_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
    try { cdb()->exec("ALTER TABLE contacts ADD COLUMN blocked TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}
    try { cdb()->exec("ALTER TABLE contacts ADD COLUMN place_id VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { cdb()->exec("CREATE INDEX idx_contacts_place_id ON contacts(place_id)"); } catch (\Throwable $e) {}

    $where = ['1=1']; $params = [];
    if ($status !== 'all') { $where[] = 'status = ?';  $params[] = $status; }
    if ($search !== '')    { $where[] = '(phone LIKE ? OR name LIKE ? OR empresa LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($source !== '')    { $where[] = 'source = ?';  $params[] = $source; }
    if ($campaign !== '')  { $where[] = 'last_campaign = ?'; $params[] = $campaign; }

    // Filtro especial: só contatos que já receberam algum disparo
    $dispatched = trim($_GET['dispatched'] ?? '');
    if ($dispatched === '1') {
        $where[] = 'last_campaign IS NOT NULL AND last_campaign != \'\'';
    }
    $wh = 'WHERE ' . implode(' AND ', $where);

    $totalStmt = cdb()->prepare("SELECT COUNT(*) FROM contacts $wh");
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();

    $rowsStmt = cdb()->prepare("SELECT id,phone,empresa,name,status,notes,source,job_id,last_campaign,last_dispatched_at,blocked,created_at,updated_at FROM contacts $wh ORDER BY created_at DESC LIMIT $per OFFSET $offset");
    $rowsStmt->execute($params);
    $rows = $rowsStmt->fetchAll();

    $counts = ['all' => 0, 'pendente' => 0, 'em_contato' => 0, 'ganho' => 0, 'perdido' => 0];
    $cntWhere = ['1=1']; $cntParams = [];
    if ($search !== '')   { $cntWhere[] = '(phone LIKE ? OR name LIKE ? OR empresa LIKE ?)'; $cntParams[] = "%$search%"; $cntParams[] = "%$search%"; $cntParams[] = "%$search%"; }
    if ($source !== '')   { $cntWhere[] = 'source = ?'; $cntParams[] = $source; }
    if ($campaign !== '') { $cntWhere[] = 'last_campaign = ?'; $cntParams[] = $campaign; }
    if ($dispatched === '1') { $cntWhere[] = 'last_campaign IS NOT NULL AND last_campaign != \'\''; }
    $cntSql = 'WHERE ' . implode(' AND ', $cntWhere);
    $cntStmt = cdb()->prepare("SELECT status, COUNT(*) as c FROM contacts $cntSql GROUP BY status");
    $cntStmt->execute($cntParams);
    foreach ($cntStmt->fetchAll() as $r) { $counts[$r['status']] = (int)$r['c']; $counts['all'] += (int)$r['c']; }

    cok(['contacts' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'counts' => $counts]);
}

/* ── IMPORT ────────────────────────────────────────────── */
if ($action === 'import' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['contacts']) || !is_array($body['contacts'])) cerr('contacts[] obrigatório');

    // Garante que as colunas auxiliares existem antes de inserir
    try { cdb()->exec("ALTER TABLE contacts ADD COLUMN last_campaign VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { cdb()->exec("ALTER TABLE contacts ADD COLUMN last_dispatched_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
    try { cdb()->exec("ALTER TABLE contacts ADD COLUMN place_id VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { cdb()->exec("CREATE INDEX idx_contacts_place_id ON contacts(place_id)"); } catch (\Throwable $e) {}

    $allContacts = $body['contacts'];

    // Detecta source: pode vir no body ou em cada contato
    $bodySource = $body['source'] ?? null;
    if (!$bodySource && !empty($allContacts[0]['source'])) {
        $bodySource = $allContacts[0]['source'];
    }

    /*
     * SPLIT INTELIGENTE — Lista máxima de 100.
     * Se o $bodySource já existe no banco e tem capacidade restante (<100),
     * preenche o saldo e abre nova "Nome #2" para o resto.
     * Se a base do nome já tem variações ("X", "X #2"), continua o ciclo.
     */
    $LIST_CAP = 100;
    $existingCount = 0;
    if ($bodySource) {
        $st = cdb()->prepare("SELECT COUNT(*) FROM contacts WHERE source = ?");
        $st->execute([$bodySource]);
        $existingCount = (int)$st->fetchColumn();
    }

    // Função auxiliar (closure) para gerar próximo nome com incremento
    $nextSlotName = function (string $base, int $startN = 2): string {
        // procura o maior #N atual e devolve N+1
        $stmt = cdb()->prepare("SELECT source FROM contacts WHERE source = ? OR source LIKE ?");
        $stmt->execute([$base, $base . ' #%']);
        $maxN = 1;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $s) {
            if ($s === $base) continue;
            if (preg_match('/ #(\d+)$/', $s, $m)) $maxN = max($maxN, (int)$m[1]);
        }
        return $base . ' #' . ($maxN + 1);
    };

    // Monta a sequência de "buckets" (label => quantos cabem)
    $buckets = []; // [['label' => 'Nome', 'cap' => 100, 'used' => 0]]
    $remaining = count($allContacts);
    if ($bodySource) {
        $firstCap = max(0, $LIST_CAP - $existingCount);
        if ($firstCap > 0) {
            $buckets[] = ['label' => $bodySource, 'cap' => $firstCap, 'used' => 0];
            $remaining -= $firstCap;
        }
        // Listas adicionais para o que sobrar
        while ($remaining > 0) {
            $newLabel = $nextSlotName($bodySource);
            $bucket = ['label' => $newLabel, 'cap' => $LIST_CAP, 'used' => 0];
            $buckets[] = $bucket;
            $remaining -= $LIST_CAP;
        }
    } else {
        $buckets[] = ['label' => null, 'cap' => count($allContacts), 'used' => 0];
    }

    $insertedTotal = 0;
    $skippedTotal  = 0;
    $listNames     = [];

    // Insert: ON DUPLICATE KEY também atualiza place_id (link forte com Google)
    $stmt = cdb()->prepare(
        'INSERT INTO contacts (phone, empresa, name, status, source, job_id, place_id, created_at, updated_at)
         VALUES (?, ?, ?, \'pendente\', ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           empresa    = COALESCE(empresa, VALUES(empresa)),
           name       = COALESCE(name, VALUES(name)),
           source     = COALESCE(source, VALUES(source)),
           place_id   = COALESCE(place_id, VALUES(place_id)),
           updated_at = NOW()'
    );

    $bucketIdx = 0;
    foreach ($allContacts as $c) {
        $phone = normalizePhone($c['phone'] ?? '');
        if ($phone === null) { $skippedTotal++; continue; }

        // Avança o bucket quando lota
        while ($bucketIdx < count($buckets) - 1 && $buckets[$bucketIdx]['used'] >= $buckets[$bucketIdx]['cap']) {
            $bucketIdx++;
        }
        $label = $buckets[$bucketIdx]['label'];
        $src   = $label ?? ($c['source'] ?? null);

        try {
            $stmt->execute([
                $phone,
                $c['empresa']  ?? null,
                $c['name']     ?? null,
                $src,
                $c['job_id']   ?? null,
                $c['place_id'] ?? null,
            ]);
            $insertedTotal++;
            $buckets[$bucketIdx]['used']++;
        } catch (\Throwable $e) {
            $skippedTotal++; // duplicata por unique key (phone)
        }
    }

    foreach ($buckets as $b) if ($b['used'] > 0) $listNames[] = $b['label'];

    cok([
        'inserted' => $insertedTotal,
        'skipped'  => $skippedTotal,
        'lists'    => $listNames,
        'chunks'   => count($listNames),
    ]);
}

/* ── UPDATE ────────────────────────────────────────────── */
if ($action === 'update' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['id'])) cerr('id obrigatório');

    $allowed = ['pendente', 'em_contato', 'ganho', 'perdido'];
    $sets = []; $params = [];

    if (isset($body['status']) && in_array($body['status'], $allowed)) { $sets[] = 'status = ?';  $params[] = $body['status']; }
    if (array_key_exists('name', $body))    { $sets[] = 'name = ?';    $params[] = $body['name']    ?: null; }
    if (array_key_exists('empresa', $body)) { $sets[] = 'empresa = ?'; $params[] = $body['empresa'] ?: null; }
    if (array_key_exists('notes', $body))   { $sets[] = 'notes = ?';   $params[] = $body['notes']   ?: null; }
    if (!$sets) cerr('Nada para atualizar');

    $sets[] = 'updated_at = NOW()';
    $params[] = (int)$body['id'];
    cdb()->prepare('UPDATE contacts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    cok();
}

/* ── DELETE LEAD (só se perdido) ───────────────────────── */
if ($action === 'delete_lead' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['id'])) cerr('id obrigatório');
    $lead = cdb()->query("SELECT status FROM contacts WHERE id = " . (int)$body['id'])->fetch();
    if (!$lead) cerr('Lead não encontrado', 404);
    if ($lead['status'] !== 'perdido') cerr('Só é possível excluir leads com status "Perdido".');
    cdb()->prepare('DELETE FROM contacts WHERE id = ?')->execute([(int)$body['id']]);
    cok();
}

/* ── RENAME LIST ───────────────────────────────────────── */
if ($action === 'rename_list' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $oldName = trim($body['old'] ?? '');
    $newName = trim($body['new'] ?? '');
    if ($oldName === '' || $newName === '') cerr('old e new obrigatórios');
    if ($oldName === $newName) cerr('Nome novo igual ao atual');

    $stmt = cdb()->prepare('UPDATE contacts SET source = ? WHERE source = ?');
    $stmt->execute([$newName, $oldName]);
    cok(['affected' => $stmt->rowCount()]);
}

/* ── DELETE LIST ───────────────────────────────────────── */
if ($action === 'delete_list' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['source'])) cerr('source obrigatório');
    $stmt = cdb()->prepare('DELETE FROM contacts WHERE source = ?');
    $stmt->execute([$body['source']]);
    cok(['deleted' => $stmt->rowCount()]);
}

/* ── SOURCES ───────────────────────────────────────────── */
if ($action === 'sources' && $method === 'GET') {
    $rows = cdb()->query(
        "SELECT source, COUNT(*) as total, SUM(status='ganho') as ganhos
         FROM contacts WHERE source IS NOT NULL AND source != ''
         GROUP BY source ORDER BY total DESC"
    )->fetchAll();
    cok(['sources' => $rows]);
}

/* ── CAMPAIGNS ─────────────────────────────────────────── */
/* ── CONTACT CAMPAIGNS — histórico de campanhas de um contato ── */
if ($action === 'contact_campaigns' && $method === 'GET') {
    $phone = trim($_GET['phone'] ?? '');
    if (!$phone) cerr('phone obrigatório');

    // Normaliza o phone para busca (aceita com ou sem 55)
    $phoneDigits = preg_replace('/\D/', '', $phone);
    $phoneShort  = (strlen($phoneDigits) === 13 && str_starts_with($phoneDigits, '55'))
        ? substr($phoneDigits, 2) : $phoneDigits;

    // Busca em job_contacts JOIN jobs — histórico real de envios
    $rows = cdb()->prepare(
        "SELECT j.job_name, j.started_at, j.total, j.sent, j.errors,
                COALESCE(j.queued, 0) AS queued,
                jc.status AS contact_status, jc.sent_at, jc.error_msg
         FROM job_contacts jc
         JOIN jobs j ON j.id = jc.job_id
         WHERE jc.phone = ? OR jc.phone = ?
         ORDER BY j.started_at DESC
         LIMIT 50"
    );
    $rows->execute([$phoneDigits, $phoneShort]);
    $campaigns = $rows->fetchAll();

    // Fallback: se não achou em job_contacts, usa last_campaign do contacts
    if (empty($campaigns)) {
        $c = cdb()->prepare("SELECT last_campaign, last_dispatched_at FROM contacts WHERE phone = ?");
        $c->execute([$phone]);
        $row = $c->fetch();
        if ($row && $row['last_campaign']) {
            $campaigns = [[
                'job_name'       => $row['last_campaign'],
                'started_at'     => $row['last_dispatched_at'],
                'total'          => null,
                'sent'           => null,
                'errors'         => null,
                'queued'         => null,
                'contact_status' => 'sent',
                'sent_at'        => $row['last_dispatched_at'],
                'error_msg'      => null,
            ]];
        }
    }

    cok(['campaigns' => $campaigns]);
}

if ($action === 'campaigns' && $method === 'GET') {
    try {
        cdb()->exec("ALTER TABLE contacts ADD COLUMN last_campaign VARCHAR(255) DEFAULT NULL");
        cdb()->exec("ALTER TABLE contacts ADD COLUMN last_dispatched_at DATETIME DEFAULT NULL");
    } catch (\Throwable $e) {}
    $rows = cdb()->query(
        "SELECT last_campaign as campaign, COUNT(*) as total, MAX(last_dispatched_at) as last_at
         FROM contacts WHERE last_campaign IS NOT NULL AND last_campaign != ''
         GROUP BY last_campaign ORDER BY last_at DESC"
    )->fetchAll();
    cok(['campaigns' => $rows]);
}

/* ── NORMALIZE PHONES — remove CSP, valida DDD, exclui inválidos ── */
if ($action === 'normalize_phones' && $method === 'POST') {
    /**
     * Usa normalizePhone() para padronizar todos os contatos.
     * Resultado:
     *   updated            — normalizados e atualizados no banco
     *   duplicates_removed — colidiram em UNIQUE após normalização → excluídos
     *   deleted_invalid    — número impossível/inválido → excluídos do banco
     *   total_processed    — total de contatos lidos
     */
    $rows = cdb()->query("SELECT id, phone FROM contacts")->fetchAll();
    $updated = 0; $duplicates = 0; $deleted_invalid = 0;

    foreach ($rows as $r) {
        $orig  = $r['phone'];
        $clean = normalizePhone($orig);

        // Número inválido → EXCLUIR do banco
        if ($clean === null) {
            try {
                cdb()->prepare("DELETE FROM contacts WHERE id=?")->execute([$r['id']]);
                $deleted_invalid++;
            } catch (\Throwable $e) {}
            continue;
        }

        // Número válido mas diferente do original → ATUALIZAR
        if ($clean !== $orig) {
            try {
                cdb()->prepare("UPDATE contacts SET phone=?, updated_at=NOW() WHERE id=?")->execute([$clean, $r['id']]);
                $updated++;
            } catch (\Throwable $e) {
                // UNIQUE key colide = duplicata gerada pela normalização → excluir este
                try {
                    cdb()->prepare("DELETE FROM contacts WHERE id=?")->execute([$r['id']]);
                    $duplicates++;
                } catch (\Throwable $e2) {}
            }
        }
    }

    cok([
        'updated'            => $updated,
        'duplicates_removed' => $duplicates,
        'deleted_invalid'    => $deleted_invalid,
        'total_processed'    => count($rows),
    ]);
}
/* ── BLOCK / UNBLOCK contact ───────────────────────────── */
if ($action === 'block' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) cerr('id obrigatório');
    try { cdb()->exec("ALTER TABLE contacts ADD COLUMN blocked TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}
    cdb()->prepare("UPDATE contacts SET blocked=1 WHERE id=?")->execute([$id]);
    cok();
}
if ($action === 'unblock' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) cerr('id obrigatório');
    cdb()->prepare("UPDATE contacts SET blocked=0 WHERE id=?")->execute([$id]);
    cok();
}

/* ── SYNC CAMPAIGNS — popula last_campaign retroativamente ── */
if ($action === 'sync_campaigns' && $method === 'POST') {
    try {
        cdb()->exec("ALTER TABLE contacts ADD COLUMN last_campaign VARCHAR(255) DEFAULT NULL");
        cdb()->exec("ALTER TABLE contacts ADD COLUMN last_dispatched_at DATETIME DEFAULT NULL");
    } catch (\Throwable $e) {}

    $updated = 0;
    $jobs = cdb()->query("
        (SELECT id, job_name, contacts as contacts_json, started_at FROM dispatch_queue WHERE contacts IS NOT NULL)
        UNION ALL
        (SELECT j.id, j.job_name, NULL as contacts_json, j.started_at FROM jobs j)
        ORDER BY started_at ASC
    ")->fetchAll();

    foreach ($jobs as $job) {
        $contacts = [];
        if (!empty($job['contacts_json'])) {
            $contacts = json_decode($job['contacts_json'], true) ?: [];
        } else {
            $jc = cdb()->prepare("SELECT phone FROM job_contacts WHERE job_id=?");
            $jc->execute([$job['id']]);
            $contacts = array_map(fn($r) => ['phone'=>$r['phone']], $jc->fetchAll());
        }
        if (!$contacts) continue;

        $stmt = cdb()->prepare(
            "UPDATE contacts SET last_campaign=?, last_dispatched_at=? 
             WHERE REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ? OR phone = ?"
        );
        $when = $job['started_at'] ?: date('Y-m-d H:i:s');
        foreach ($contacts as $c) {
            $clean = preg_replace('/\D/', '', $c['phone'] ?? '');
            if (strlen($clean) < 10) continue;
            $shortNum = (strlen($clean) >= 12 && str_starts_with($clean, '55')) ? substr($clean, 2) : $clean;
            $stmt->execute([$job['job_name'], $when, '%'.$shortNum, $c['phone']]);
            $updated += $stmt->rowCount();
        }
    }
    cok(['updated' => $updated]);
}
/* ── EXPORT ────────────────────────────────────────────── */
if ($action === 'export' && $method === 'GET') {
    $status = $_GET['status'] ?? 'all';
    $source = trim($_GET['source'] ?? '');
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="contatos_' . date('Ymd') . '.txt"');
    $where = ['1=1']; $params = [];
    if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }
    if ($source !== '')    { $where[] = 'source = ?'; $params[] = $source; }
    $stmt = cdb()->prepare("SELECT phone FROM contacts WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC");
    $stmt->execute($params);
    echo implode("\n", $stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

/* ── LOOKUP_EXISTING — quais place_ids já estão no CRM (dedup do extrator) ── */
if ($action === 'lookup_existing' && $method === 'POST') {
    try { cdb()->exec("ALTER TABLE contacts ADD COLUMN place_id VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { cdb()->exec("CREATE INDEX idx_contacts_place_id ON contacts(place_id)"); } catch (\Throwable $e) {}

    $body = json_decode(file_get_contents('php://input'), true);
    $placeIds = array_filter((array)($body['place_ids'] ?? []), 'strlen');
    $phones   = array_filter((array)($body['phones']    ?? []), 'strlen');

    $existingPlaceIds = [];
    $existingPhones   = [];

    if ($placeIds) {
        $ph = implode(',', array_fill(0, count($placeIds), '?'));
        $st = cdb()->prepare("SELECT place_id FROM contacts WHERE place_id IN ($ph)");
        $st->execute(array_values($placeIds));
        $existingPlaceIds = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    if ($phones) {
        // Normaliza antes de buscar (cliente pode passar formato cru)
        $normalized = array_values(array_filter(array_map('normalizePhone', $phones)));
        if ($normalized) {
            $ph = implode(',', array_fill(0, count($normalized), '?'));
            $st = cdb()->prepare("SELECT phone FROM contacts WHERE phone IN ($ph)");
            $st->execute($normalized);
            $existingPhones = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    }

    cok([
        'existing_place_ids' => $existingPlaceIds,
        'existing_phones'    => $existingPhones,
    ]);
}

/* ── NEXT_LIST_SLOT — sugere o próximo nome para uma lista (#2, #3...) ──
 * Cliente passa ?prefix=X.
 * Devolve:
 *   - target: nome a usar agora (preenche o saldo da lista X até 100, ou abre #N nova)
 *   - existing_count: quantos já existem na lista 'target'
 *   - capacity: quantos cabem ainda em 'target' (até 100)
 */
if ($action === 'next_list_slot' && $method === 'GET') {
    $prefix = trim($_GET['prefix'] ?? '');
    if ($prefix === '') cerr('prefix obrigatório');

    $LIST_CAP = 100;

    // Total na lista exata
    $st = cdb()->prepare("SELECT COUNT(*) FROM contacts WHERE source = ?");
    $st->execute([$prefix]);
    $countBase = (int)$st->fetchColumn();

    if ($countBase < $LIST_CAP) {
        cok([
            'target'         => $prefix,
            'existing_count' => $countBase,
            'capacity'       => $LIST_CAP - $countBase,
            'is_new_slot'    => $countBase === 0,
        ]);
    }

    // Já lotada → procura próximo #N livre
    $st2 = cdb()->prepare("SELECT source, COUNT(*) as c FROM contacts WHERE source LIKE ? GROUP BY source");
    $st2->execute([$prefix . ' #%']);
    $rows = $st2->fetchAll();
    $maxN = 1;
    $byName = [];
    foreach ($rows as $r) {
        $byName[$r['source']] = (int)$r['c'];
        if (preg_match('/ #(\d+)$/', $r['source'], $m)) $maxN = max($maxN, (int)$m[1]);
    }
    // Procura o primeiro slot com capacidade
    for ($n = 2; $n <= $maxN; $n++) {
        $name = $prefix . ' #' . $n;
        if (($byName[$name] ?? 0) < $LIST_CAP) {
            cok([
                'target'         => $name,
                'existing_count' => $byName[$name] ?? 0,
                'capacity'       => $LIST_CAP - ($byName[$name] ?? 0),
                'is_new_slot'    => false,
            ]);
        }
    }
    // Todas cheias → cria a próxima
    cok([
        'target'         => $prefix . ' #' . ($maxN + 1),
        'existing_count' => 0,
        'capacity'       => $LIST_CAP,
        'is_new_slot'    => true,
    ]);
}

/* ── MERGE_LISTS — junta N listas em uma; auto-split se exceder 100 ──
 * Body:  { sources: ['Lista A','Lista B'], target: 'Lista Unificada' }
 * Resultado:
 *   - Renomeia source de todos os contatos das listas origem para 'target'
 *   - Se total exceder 100, distribui em 'target', 'target #2', 'target #3'...
 *   - Origens vazias depois disso são removidas implicitamente (não aparecem mais em 'sources')
 */
if ($action === 'merge_lists' && $method === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true);
    $sources = array_values(array_filter((array)($body['sources'] ?? []), 'strlen'));
    $target  = trim($body['target'] ?? '');
    if (count($sources) < 2) cerr('Selecione pelo menos 2 listas para juntar.');
    if ($target === '')      cerr('Informe o nome da lista de destino.');

    $LIST_CAP = 100;

    // Pega todos os IDs das listas origem (ordem cronológica)
    $ph = implode(',', array_fill(0, count($sources), '?'));
    $st = cdb()->prepare("SELECT id FROM contacts WHERE source IN ($ph) ORDER BY created_at ASC");
    $st->execute($sources);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) cerr('Nenhum contato encontrado nessas listas.');

    // Capacidade já usada da lista 'target' (se existir)
    $stc = cdb()->prepare("SELECT COUNT(*) FROM contacts WHERE source = ?");
    $stc->execute([$target]);
    $existingTarget = (int)$stc->fetchColumn();

    $upd = cdb()->prepare("UPDATE contacts SET source = ?, updated_at = NOW() WHERE id = ?");
    $createdLists = []; // [label => count]
    $bucketLabel = $target;
    $bucketUsed  = $existingTarget;
    $createdLists[$bucketLabel] = $existingTarget;

    foreach ($ids as $id) {
        if ($bucketUsed >= $LIST_CAP) {
            // Avança para próximo slot incremental
            $n = 2;
            while (true) {
                $candidate = $target . ' #' . $n;
                $stCnt = cdb()->prepare("SELECT COUNT(*) FROM contacts WHERE source = ?");
                $stCnt->execute([$candidate]);
                $cnt = (int)$stCnt->fetchColumn();
                if ($cnt < $LIST_CAP) {
                    $bucketLabel = $candidate;
                    $bucketUsed  = $cnt;
                    $createdLists[$bucketLabel] = $cnt;
                    break;
                }
                $n++;
            }
        }
        $upd->execute([$bucketLabel, $id]);
        $bucketUsed++;
        $createdLists[$bucketLabel]++;
    }

    cok([
        'merged'  => count($ids),
        'lists'   => $createdLists,
        'sources' => $sources,
    ]);
}

cerr('Ação desconhecida', 400);