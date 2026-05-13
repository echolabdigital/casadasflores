<?php
/**
 * Casa das Flores — webhook.php (Z-API)
 *
 * Z-API envia o tipo no body. URL configurada com ?event=connect|disconnect|send|receive
 * Payload Z-API:
 * - connect:    { type: "ConnectedCallback" }
 * - disconnect: { type: "DisconnectedCallback" }
 * - send:       { type: "SendMessageCallback", phone, messageId, ... }
 * - receive:    { type: "ReceivedCallback", phone, fromMe:false, text, ... }
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Sao_Paulo');

$raw   = file_get_contents('php://input');
$event = $_GET['event'] ?? 'unknown';
$data  = json_decode($raw, true) ?? [];

wh_log("EVENT: $event | " . substr($raw, 0, 500));

function wh_log(string $msg): void {
    file_put_contents(__DIR__ . '/webhook.log', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}
function whpdo(): PDO {
    static $p;
    if (!$p) {
        $p = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        try {
            $p->exec("CREATE TABLE IF NOT EXISTS messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(20) NOT NULL,
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
function save_status(string $status): void {
    try {
        whpdo()->prepare(
            "INSERT INTO settings (k,v) VALUES ('wa_status',?)
             ON DUPLICATE KEY UPDATE v=VALUES(v)"
        )->execute([$status]);
    } catch (\Throwable $e) { wh_log('Erro save_status: '.$e->getMessage()); }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);

switch ($event) {

    case 'connect':
        save_status('connected');
        // Salva timestamp da última conexão
        try {
            whpdo()->prepare(
                "INSERT INTO settings (k,v) VALUES ('wa_connected_at',?)
                 ON DUPLICATE KEY UPDATE v=VALUES(v)"
            )->execute([date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {}
        wh_log('WhatsApp CONECTADO.');

        // Retoma jobs pausados
        try {
            whpdo()->exec("UPDATE dispatch_queue SET status='running' WHERE status='paused'");
            wh_log('Jobs pausados retomados.');
        } catch (\Throwable $e) { wh_log('Erro retomar jobs: '.$e->getMessage()); }

        // Ao conectar: limpa histórico do número anterior.
        // Novas conversas chegam automaticamente via ReceivedCallback (tempo real).
        try {
            whpdo()->exec("TRUNCATE TABLE messages");
            wh_log("Histórico limpo. Aguardando mensagens em tempo real.");
        } catch (\Throwable $e) {
            wh_log("Aviso ao limpar messages: " . $e->getMessage());
        }
        break;

    case 'disconnect':
        save_status('disconnected');
        // Salva timestamp da desconexão
        try {
            whpdo()->prepare(
                "INSERT INTO settings (k,v) VALUES ('wa_disconnected_at',?)
                 ON DUPLICATE KEY UPDATE v=VALUES(v)"
            )->execute([date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {}
        wh_log('WhatsApp DESCONECTADO.');
        try {
            whpdo()->exec("UPDATE dispatch_queue SET status='paused' WHERE status='running'");
            wh_log('Jobs em andamento pausados.');
        } catch (\Throwable $e) { wh_log('Erro pausar jobs: '.$e->getMessage()); }
        break;

    case 'send':
        // Salva mensagem enviada no banco de conversas
        $phone = preg_replace('/\D/', '', $data['phone'] ?? '');
        $body  = $data['body'] ?? $data['message'] ?? $data['text']['message'] ?? '';
        $msgId = $data['messageId'] ?? $data['zaapId'] ?? $data['id'] ?? null;
        if ($phone && $body) {
            try {
                whpdo()->prepare(
                    "INSERT IGNORE INTO messages (phone,from_me,body,msg_type,msg_id,status)
                     VALUES (?,1,?,'text',?,'sent')"
                )->execute([$phone, $body, $msgId]);
            } catch (\Throwable $e) {}
        }
        wh_log("ENVIADO para $phone | msgId: $msgId");
        break;

    case 'receive':
        // Z-API: ignora mensagens enviadas por nós mesmos
        if (!empty($data['fromMe'])) {
            wh_log('Ignorado (fromMe=true)');
            break;
        }
        $from = preg_replace('/@.*/', '', $data['phone'] ?? $data['from'] ?? '');
        $from = preg_replace('/\D/', '', $from);

        if (strlen($from) >= 10) {
            // Extrai corpo e tipo
            $body     = $data['text']['message'] ?? $data['body'] ?? $data['message'] ?? '';
            $msgType  = 'text';
            $mediaUrl = null;
            if (!empty($data['image']['imageUrl'] ?? '')) {
                $msgType = 'image'; $mediaUrl = $data['image']['imageUrl'];
                if (!$body) $body = $data['image']['caption'] ?? '📷 Imagem';
            } elseif (!empty($data['audio'] ?? '')) {
                $msgType = 'audio';
                if (!$body) $body = '🎵 Áudio';
            } elseif (!empty($data['document'] ?? '')) {
                $msgType = 'document';
                if (!$body) $body = '📄 Documento';
            }
            if (!$body) $body = '[mensagem]';
            $msgId = $data['messageId'] ?? $data['id'] ?? null;

            try {
                // Salva mensagem
                whpdo()->prepare(
                    "INSERT IGNORE INTO messages (phone,from_me,body,msg_type,media_url,msg_id,status)
                     VALUES (?,0,?,?,?,?,'received')"
                )->execute([$from, $body, $msgType, $mediaUrl, $msgId]);

                // Atualiza CRM
                $stmt = whpdo()->prepare(
                    "UPDATE contacts SET status='em_contato', updated_at=NOW()
                     WHERE phone=? AND status='pendente'"
                );
                $stmt->execute([$from]);
                if ($stmt->rowCount() === 0) {
                    whpdo()->prepare(
                        "INSERT INTO contacts (phone,status,source,created_at,updated_at)
                         VALUES (?,'em_contato','Resposta WhatsApp',NOW(),NOW())
                         ON DUPLICATE KEY UPDATE
                         status=IF(status='pendente','em_contato',status), updated_at=NOW()"
                    )->execute([$from]);
                }
                wh_log("MENSAGEM salva de $from [$msgType]: " . substr($body, 0, 100));
            } catch (\Throwable $e) { wh_log('Erro salvar msg: '.$e->getMessage()); }
        }
        break;

    default:
        wh_log("Evento desconhecido: $event | data: " . substr($raw, 0, 300));
}
