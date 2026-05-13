-- Casa das Flores — tabela de mensagens para painel de Conversas
CREATE TABLE IF NOT EXISTS messages (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone       VARCHAR(20) NOT NULL,
  from_me     TINYINT(1)  NOT NULL DEFAULT 0,
  body        TEXT        NOT NULL,
  msg_type    VARCHAR(20) NOT NULL DEFAULT 'text', -- text, image, audio, document
  media_url   TEXT        DEFAULT NULL,
  msg_id      VARCHAR(100) DEFAULT NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'received', -- received, sent, read
  created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_phone (phone),
  INDEX idx_created (created_at),
  INDEX idx_phone_created (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- View para última mensagem por conversa (usada na lista de chats)
CREATE OR REPLACE VIEW chat_list AS
SELECT
  m.phone,
  m.body        AS last_message,
  m.from_me     AS last_from_me,
  m.created_at  AS last_at,
  m.msg_type    AS last_type,
  COUNT(CASE WHEN m.from_me=0 AND m.status='received' THEN 1 END) AS unread,
  c.name,
  c.empresa,
  c.status      AS crm_status
FROM messages m
LEFT JOIN contacts c ON c.phone = m.phone
WHERE m.created_at = (
  SELECT MAX(m2.created_at) FROM messages m2 WHERE m2.phone = m.phone
)
GROUP BY m.phone
ORDER BY m.created_at DESC;
