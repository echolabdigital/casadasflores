<?php
/**
 * Casa das Flores — config.php
 */

// ─── Z-API ──────────────────────────────────────────────────────────
define('ZAPI_BASE',         'https://api.z-api.io');
define('ZAPI_INSTANCE',     '3F2B0417C0B4A0BBA2E8FA4054C6596D');
define('ZAPI_TOKEN',        '56E4C5C3321DD78E1E748D7F');
define('ZAPI_CLIENT_TOKEN', 'Fed419d146a7a42e2abda7dae634a5650S');

// ─── Groq AI ─────────────────────────────────────────────────────────
define('GROQ_API_KEY',  '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');
define('GROQ_MODEL',    'llama-3.3-70b-versatile');

// ─── Limites diários de IA ───────────────────────────────────────────
define('AI_IMG_DAILY_LIMIT', 5);

// ─── Banco de Dados (MySQL — Hostgator) ─────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'casa_casadasflores');
define('DB_USER', 'casa_casadasflores');
define('DB_PASS', 'Zaqwsx12##@@!!');

// ─── Google Places API (Extrator de Leads) ───────────────────────────
define('GMAPS_API_KEY', 'AIzaSyC1P0-6MVRwY-ARu0AlIfrML1SEKaRvvbU');

// ─── Upload de Imagens — Supabase Storage ────────────────────────────
// As imagens agora são enviadas para o Supabase Storage e servidas via CDN global.
// Isso elimina a latência do servidor compartilhado Hostgator no worker.
define('SUPABASE_URL',          'https://ckdmdxdvzfkpeftbwbzi.supabase.co');
define('SUPABASE_SERVICE_KEY',  'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNrZG1keGR2emZrcGVmdGJ3YnppIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc3ODE1NzUwNywiZXhwIjoyMDkzNzMzNTA3fQ.f1uygUKAGFQg1Zbu88hbyp27z2jCtikcTq_4d3oVXLQ');
define('SUPABASE_BUCKET',       'uploads');
define('SUPABASE_PUBLIC_BASE',  'https://ckdmdxdvzfkpeftbwbzi.supabase.co/storage/v1/object/public/uploads/');

// Fallback local (mantido para compatibilidade com imagens antigas já no servidor)
define('UPLOAD_DIR',  __DIR__ . '/uploads/');
define('PUBLIC_BASE', 'https://www.casadasflores.online/uploads/');
define('MAX_SIZE_MB', 5);