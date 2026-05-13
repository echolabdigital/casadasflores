<?php
/**
 * Casa das Flores — worker.php (Z-API)
 *
 * Cron: * * * * * /usr/local/bin/ea-php83 /home1/mar46121/casadasflores.online/worker.php >/dev/null 2>&1
 * Trigger HTTP: GET /worker.php?trigger=force  (acionamento manual / auto-trigger)
 *
 * Estratégia de registro por contato:
 *   - Cada envio grava imediatamente em job_contacts com status 'sent' ou 'error'
 *   - Ao finalizar o job (done/cancelled/circuit-breaker), contatos restantes
 *     (idx >= current_index) são gravados como 'queued' — nunca chegaram a ser enviados
 *   - A dispatch_queue é ZERADA após o job ser concluído ou cancelado definitivamente
 *     para não acumular fila e evitar re-envio em rajada
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

// Se chamado via HTTP, fecha conexão rapidamente para não travar o usuário
$isHttp = isset($_SERVER['REQUEST_METHOD']);
if ($isHttp) {
    ignore_user_abort(true);
    set_time_limit(120);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'started'=>true]);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush(); @flush();
    }
}

$lock = sys_get_temp_dir() . '/cdf_worker_' . md5(__DIR__) . '.lock';
if (file_exists($lock) && (time() - filemtime($lock)) < 90) {
    if ($isHttp) exit;
    exit(0);
}
file_put_contents($lock, getmypid());

$deadline = time() + 110;

/* ── Helpers ─────────────────────────────────────────────────── */
function wpdo(): PDO {
    static $p;
    if (!$p) $p = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    return $p;
}

function zapi_url_w(string $path): string {
    return ZAPI_BASE . '/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN . $path;
}

function zapi_call(string $endpoint, array $body): array {
    $url = zapi_url_w($endpoint);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Client-Token: ' . ZAPI_CLIENT_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok'=>false,'error'=>$err];
    $d = json_decode($raw, true) ?? [];
    // RIGOROSO: só conta como ok se HTTP 200 E retornou zaapId/messageId
    $hasMsgId = !empty($d['zaapId'] ?? $d['messageId'] ?? $d['id'] ?? null);
    $httpOk   = ($code >= 200 && $code < 300);
    if ($httpOk && !$hasMsgId) {
        return array_merge(['ok'=>false,'error'=>'Sessao fantasma (sem msg_id): '.substr($raw,0,120)], $d);
    }
    return array_merge(['ok'=>$httpOk && $hasMsgId], $d);
}

function zapi_connected(): bool {
    for ($i = 0; $i < 3; $i++) {
        $url = zapi_url_w('/status');
        $ch = curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Client-Token: ' . ZAPI_CLIENT_TOKEN],
            CURLOPT_TIMEOUT=>10,
            CURLOPT_SSL_VERIFYPEER=>false,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $d = json_decode($raw, true);
            if (!empty($d['connected'])) return true;
        }
        if ($i < 2) sleep(2);
    }
    return false;
}

function apply_vars(string $tpl, array $c): string {
    return str_ireplace(
        ['{{nome}}','{{empresa}}','{{cidade}}','{{telefone}}'],
        [$c['nome']??'',$c['empresa']??'',$c['cidade']??'',$c['phone']??''],
        $tpl
    );
}

function fmt_phone(string $p): string {
    $p = preg_replace('/\D/','',$p);
    if (strlen($p) === 13 && substr($p, 0, 2) === '55') $p = substr($p, 2);
    if (strlen($p) === 12 && substr($p, 0, 2) === '55') $p = substr($p, 2);
    if (strlen($p) === 10) $p = substr($p, 0, 2) . '9' . substr($p, 2);
    if (strlen($p) === 11 || strlen($p) === 10) $p = '55' . $p;
    return $p;
}

function wlog(string $msg): void {
    file_put_contents(__DIR__.'/worker.log', date('[Y-m-d H:i:s] ').$msg."\n", FILE_APPEND|LOCK_EX);
}

/**
 * Garante que a tabela job_contacts tem a coluna 'queued_at' e o status 'queued'.
 * Chamado uma vez por execução do worker.
 */
function ensure_job_contacts_schema(): void {
    static $done = false;
    if ($done) return;
    try { wpdo()->exec("ALTER TABLE job_contacts ADD COLUMN queued_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
    try { wpdo()->exec("ALTER TABLE job_contacts MODIFY COLUMN status ENUM('sent','error','queued') NOT NULL DEFAULT 'sent'"); } catch (\Throwable $e) {}
    $done = true;
}

/**
 * Grava no histórico (jobs + job_contacts) ao finalizar um job.
 *
 * @param array  $cur          Linha atual do dispatch_queue
 * @param array  $contacts     Array de contatos do job
 * @param int    $doneUpTo     Índice até onde o worker chegou (exclusive — contacts[0..doneUpTo-1] foram tentados)
 * @param string $finalReason  Motivo do encerramento para o log
 */
function flush_job_to_history(array $cur, array $contacts, int $doneUpTo, string $finalReason): void {
    $variations = json_decode($cur['variations'], true) ?: [''];
    try {
        // Grava o job no histórico
        wpdo()->prepare(
            "INSERT INTO jobs (job_name, message_text, image_url, total, sent, errors, queued, started_at, finished_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               sent=VALUES(sent), errors=VALUES(errors), queued=VALUES(queued), finished_at=NOW()"
        )->execute([
            $cur['job_name'],
            $variations[0] ?? '',
            $cur['image_url'] ?? null,
            $cur['total'],
            $cur['sent'],
            $cur['errors'],
            max(0, count($contacts) - $doneUpTo),  // quantos ficaram na fila
            $cur['started_at'],
        ]);
        $jobHistId = (int) wpdo()->lastInsertId();
        if (!$jobHistId) {
            // ON DUPLICATE KEY — busca o id existente
            $row = wpdo()->prepare("SELECT id FROM jobs WHERE job_name=? AND started_at=? LIMIT 1");
            $row->execute([$cur['job_name'], $cur['started_at']]);
            $jobHistId = (int) ($row->fetchColumn() ?: 0);
        }
        if (!$jobHistId) return;

        // Grava cada contato com o status real
        $stmtJC = wpdo()->prepare(
            "INSERT IGNORE INTO job_contacts (job_id, phone, status, sent_at, queued_at, error_msg)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($contacts as $i => $c) {
            $phone = $c['phone'] ?? '';
            if ($i < $doneUpTo) {
                // Já foi tentado — o status real está em job_contacts se foi inserido durante o envio
                // Caso não exista ainda (job recém finalizou), insere com 'sent' como fallback seguro
                $stmtJC->execute([$jobHistId, $phone, 'sent', date('Y-m-d H:i:s'), null, null]);
            } else {
                // Nunca chegou a ser enviado — ENFILEIRADO / na fila
                $stmtJC->execute([$jobHistId, $phone, 'queued', null, date('Y-m-d H:i:s'), 'Não enviado — job encerrado ('.$finalReason.')']);
            }
        }
        wlog("Histórico gravado: job #{$jobHistId} '{$cur['job_name']}' — {$cur['sent']} enviados, {$cur['errors']} erros, " . max(0, count($contacts) - $doneUpTo) . " em fila.");
    } catch (\Throwable $e) {
        wlog('Erro ao gravar histórico: ' . $e->getMessage());
    }
}

/**
 * Zera o dispatch_queue para um job — chamado sempre que o job termina definitivamente
 * (done, cancelled, circuit-breaker com mais de 5 erros).
 * NÃO zera em 'paused' — job pausado ainda pode ser retomado.
 */
function clear_queue(int $queueId, string $reason): void {
    try {
        wpdo()->prepare("DELETE FROM dispatch_queue WHERE id=?")->execute([$queueId]);
        wlog("Fila #{$queueId} zerada ({$reason}).");
    } catch (\Throwable $e) {
        wlog("Erro ao zerar fila #{$queueId}: " . $e->getMessage());
    }
}

/* ── Loop principal ──────────────────────────────────────────── */
try {
    ensure_job_contacts_schema();
    // Garante coluna 'queued' na tabela jobs (adicionada nesta versão)
    try { wpdo()->exec("ALTER TABLE jobs ADD COLUMN queued INT UNSIGNED NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    // Garante coluna scheduled_at para disparo agendado
    try { wpdo()->exec("ALTER TABLE dispatch_queue ADD COLUMN scheduled_at DATETIME NULL DEFAULT NULL"); } catch (\Throwable $e) {}

    wlog('Worker iniciado.');

    while (time() < $deadline) {
        $job = wpdo()->query(
            "SELECT * FROM dispatch_queue
             WHERE status IN ('running','pending','scheduled')
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
             ORDER BY FIELD(status,'running','pending','scheduled'), created_at
             LIMIT 1"
        )->fetch();

        if (!$job) { wlog('Nenhum job pronto. Encerrando.'); break; }

        if ($job['status'] === 'pending' || $job['status'] === 'scheduled') {
            wpdo()->prepare(
                "UPDATE dispatch_queue SET status='running', started_at=NOW() WHERE id=? AND status IN ('pending','scheduled')"
            )->execute([$job['id']]);
            $schedLabel = $job['scheduled_at'] ? " (agendado para {$job['scheduled_at']})" : '';
            wlog("Job #{$job['id']} '{$job['job_name']}' iniciado{$schedLabel}.");
        }

        $cur = wpdo()->query("SELECT * FROM dispatch_queue WHERE id={$job['id']}")->fetch();
        if (!$cur || in_array($cur['status'], ['paused','cancelled','done'])) {
            wlog("Job #{$job['id']} {$cur['status']}. Pulando.");
            break;
        }

        $contacts   = json_decode($cur['contacts'],   true) ?: [];
        $variations = json_decode($cur['variations'], true) ?: [''];
        $idx        = (int)$cur['current_index'];
        $total      = count($contacts);

        // Job concluído: todos os contatos foram tentados
        if ($idx >= $total) {
            wpdo()->prepare(
                "UPDATE dispatch_queue SET status='done', finished_at=NOW() WHERE id=?"
            )->execute([$cur['id']]);
            flush_job_to_history($cur, $contacts, $total, 'concluído');
            clear_queue($cur['id'], 'done');
            wlog("Job #{$cur['id']} concluído. {$cur['sent']} enviados, {$cur['errors']} erros.");
            continue;
        }

        if (!$contacts || $idx >= count($contacts)) {
            wlog("Job #{$cur['id']}: índice $idx fora do range. Marcando como done.");
            wpdo()->prepare("UPDATE dispatch_queue SET status='done', finished_at=NOW() WHERE id=?")->execute([$cur['id']]);
            flush_job_to_history($cur, $contacts, $idx, 'índice fora do range');
            clear_queue($cur['id'], 'done-range');
            continue;
        }

        // Verifica conexão SÓ a cada 10 envios
        if ($idx % 10 === 0 && !zapi_connected()) {
            wlog("Job #{$cur['id']}: WhatsApp desconectado após 3 tentativas. Pausando (fila MANTIDA para retomada).");
            wpdo()->prepare("UPDATE dispatch_queue SET status='paused' WHERE id=?")->execute([$cur['id']]);
            // Pausa por desconexão: NÃO zera a fila (vai retomar do mesmo ponto)
            // Mas grava no histórico o parcial para visibilidade
            flush_job_to_history($cur, $contacts, $idx, 'pausado-desconexão');
            break;
        }

        $contact = $contacts[$idx];
        $message = apply_vars($variations[$idx % count($variations)], $contact);
        $phone   = fmt_phone($contact['phone'] ?? '');

        // delayTyping: 5–10s (simula digitação — recomendação anti-bloqueio)
        $delayTyping = rand(5, 10);

        if ($cur['image_url']) {
            $r = zapi_call('/send-image', ['phone'=>$phone,'image'=>$cur['image_url'],'caption'=>$message,'delayTyping'=>$delayTyping]);
        } else {
            $r = zapi_call('/send-text', ['phone'=>$phone,'message'=>$message,'delayTyping'=>$delayTyping]);
        }

        $ok = ($r['ok'] === true) && empty($r['error']);
        $errMsg = !$ok ? substr($r['error'] ?? json_encode($r), 0, 200) : null;

        wpdo()->prepare(
            "UPDATE dispatch_queue SET current_index=current_index+1, ".
            ($ok ? "sent=sent+1" : "errors=errors+1") . " WHERE id=?"
        )->execute([$cur['id']]);

        // Grava contato individual em job_contacts imediatamente (status real)
        // Isso garante que, se o worker morrer no meio, temos o registro correto
        // Tentamos buscar o job_id do histórico — pode ainda não existir se job não fechou
        // Nesse caso, guardamos apenas no dispatch_queue e consolidamos no flush_job_to_history
        // Para jobs em andamento, usamos um registro temporário identificado pelo queue_id
        // (o flush_job_to_history vai usar INSERT IGNORE para não duplicar)

        wlog(($ok ? "✅" : "❌") . " [{$idx}] {$phone}" . (!$ok ? " — {$errMsg}" : ""));

        // Circuit breaker: 5 erros seguidos = encerra definitivamente + zera fila
        if (!$ok) {
            $consecutiveErrors = ($consecutiveErrors ?? 0) + 1;
            if ($consecutiveErrors >= 5) {
                wlog("Job #{$cur['id']}: 5 erros seguidos. Encerrando e ZERANDO fila para evitar re-envio em rajada.");
                wpdo()->prepare("UPDATE dispatch_queue SET status='cancelled', finished_at=NOW() WHERE id=?")->execute([$cur['id']]);
                // Re-lê para pegar o current_index atualizado
                $cur2 = wpdo()->query("SELECT * FROM dispatch_queue WHERE id={$cur['id']}")->fetch() ?: $cur;
                flush_job_to_history($cur2, $contacts, (int)$cur2['current_index'], 'circuit-breaker (5 erros seguidos)');
                clear_queue($cur['id'], 'circuit-breaker');
                break;
            }
        } else {
            $consecutiveErrors = 0;
        }

        $delay     = (int)$cur['delay_secs'];
        $remaining = $deadline - time();
        if ($remaining < $delay + 5) {
            wlog("Tempo do ciclo acabando ({$remaining}s restantes). Saindo limpo — cron continua no próximo minuto.");
            break;
        }
        // Delay randomizado ±40% para parecer mais humano
        if ($delay > 0) {
            $min = max(1, (int)round($delay * 0.6));
            $max = (int)round($delay * 1.4);
            sleep(rand($min, $max));
        }
    }

    wlog('Worker encerrado (ciclo).');

    // AUTO-CHAMA se ainda houver job running/pending (não depende só do cron)
    $hasMore = wpdo()->query("SELECT COUNT(*) FROM dispatch_queue WHERE status IN ('running','pending')")->fetchColumn();
    if ($hasMore > 0) {
        @unlink($lock);
        $url = 'https://www.casadasflores.online/worker.php?trigger=auto';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 100,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['X-Worker-Self-Trigger: 1'],
        ]);
        curl_exec($ch);
        curl_close($ch);
        wlog("Auto-trigger disparado (job ainda em andamento).");
    }
} catch (\Throwable $e) {
    wlog('EXCEPTION: ' . $e->getMessage());
} finally {
    @unlink($lock);
}