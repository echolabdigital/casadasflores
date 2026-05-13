<?php
session_start();
if (!empty($_SESSION['cdf_logged'])) { header('Location: index.php'); exit; }

define('LOGIN_USER', 'casadasflores');
define('LOGIN_PASS', 'casa123#');

// Lê senha personalizada do banco (tabela settings)
$customPass = LOGIN_PASS;
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r = $pdo->prepare("SELECT v FROM settings WHERE k='login_pass' LIMIT 1");
    $r->execute();
    $saved = $r->fetchColumn();
    if ($saved !== false && $saved !== '') $customPass = $saved;
} catch (\Throwable $e) { /* usa senha padrão */ }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u === LOGIN_USER && $p === $customPass) {
        $_SESSION['cdf_logged'] = true;
        header('Location: index.php'); exit;
    }
    $error = 'Usuário ou senha incorretos.';
    sleep(1);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Casa das Flores — Entrar</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: 'Montserrat', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }

/* ─── Layout split ─── */
.screen { min-height: 100vh; display: grid; grid-template-columns: 1fr 1fr; }

/* ─── Esquerda (verde) ─── */
.left {
  background: linear-gradient(160deg, #1a5c2a 0%, #0d2e14 100%);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: 48px 40px; position: relative; overflow: hidden;
}
.left::before {
  content: ''; position: absolute;
  width: 500px; height: 500px; border-radius: 50%;
  background: radial-gradient(circle, rgba(200,169,110,.12) 0%, transparent 65%);
  top: -150px; right: -180px; pointer-events: none;
}
.left::after {
  content: ''; position: absolute;
  width: 380px; height: 380px; border-radius: 50%;
  background: radial-gradient(circle, rgba(45,125,63,.22) 0%, transparent 65%);
  bottom: -130px; left: -100px; pointer-events: none;
}
.brand-logo {
  width: 150px; height: 150px; border-radius: 50%;
  background: #fff; border: 3px solid rgba(200,169,110,.55);
  object-fit: contain; box-shadow: 0 20px 50px rgba(0,0,0,.32);
  position: relative; z-index: 1; margin-bottom: 30px;
}
.brand-logo-fb {
  width: 150px; height: 150px; border-radius: 50%;
  background: #1a5c2a; border: 3px solid rgba(200,169,110,.55);
  display: none; align-items: center; justify-content: center;
  font-size: 4rem; box-shadow: 0 20px 50px rgba(0,0,0,.32);
  position: relative; z-index: 1; margin-bottom: 30px;
}
.brand-name {
  font-size: 2rem; font-weight: 800; color: #fff;
  text-align: center; letter-spacing: -.025em;
  line-height: 1.05; position: relative; z-index: 1; margin-bottom: 8px;
}
.brand-sub {
  font-size: .68rem; color: rgba(200,169,110,.85);
  letter-spacing: .2em; text-transform: uppercase;
  font-weight: 600; text-align: center;
  position: relative; z-index: 1; margin-bottom: 44px;
}
.brand-desc {
  font-size: .83rem; color: rgba(255,255,255,.45);
  text-align: center; line-height: 1.75; max-width: 260px;
  position: relative; z-index: 1;
}
.brand-since {
  margin-top: 50px; display: flex; align-items: center; gap: 14px;
  position: relative; z-index: 1;
}
.since-line { height: 1px; width: 44px; background: rgba(200,169,110,.35); }
.since-txt {
  font-size: .65rem; color: rgba(200,169,110,.65);
  letter-spacing: .14em; text-transform: uppercase; font-weight: 600;
}

/* ─── Direita (creme) ─── */
.right {
  background: #F4F0EA;
  display: flex; align-items: center; justify-content: center;
  padding: 48px 40px;
}
.form-wrap { width: 100%; max-width: 380px; }

.form-wrap h2 {
  font-size: 1.65rem; font-weight: 800; color: #133f1e;
  letter-spacing: -.025em; line-height: 1.1; margin-bottom: 6px;
}
.form-wrap > p { font-size: .83rem; color: #8a8f8b; margin-bottom: 24px; line-height: 1.55; }

.gold-bar { width: 36px; height: 3px; background: linear-gradient(90deg,#c8a96e,#e8d9b3); border-radius: 2px; margin-bottom: 28px; }

.form-group { margin-bottom: 17px; }
.form-group label {
  display: block; font-size: .67rem; font-weight: 700;
  color: #4a4f4b; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 8px;
}
.inp-wrap { position: relative; }
.inp-wrap .ico {
  position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
  width: 15px; height: 15px; color: #8a8f8b; pointer-events: none;
}
.inp-wrap input {
  width: 100%; padding: 13px 44px; border: 1.5px solid #e3dfd5;
  border-radius: 10px; font-family: 'Montserrat', sans-serif;
  font-size: .9rem; font-weight: 500; color: #1f2421;
  background: #fff; outline: none; transition: border-color .2s, box-shadow .2s;
  -webkit-appearance: none; appearance: none;
}
.inp-wrap input:focus { border-color: #1a5c2a; box-shadow: 0 0 0 3px rgba(26,92,42,.1); }
.inp-wrap input::placeholder { color: #c2c5c0; font-weight: 400; }
.eye-btn {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: #8a8f8b;
  padding: 4px; display: flex; align-items: center; transition: color .15s;
}
.eye-btn:hover { color: #1a5c2a; }
.eye-btn svg { width: 15px; height: 15px; }

.err {
  background: #fdecea; border: 1.5px solid rgba(198,40,40,.2);
  color: #c62828; border-radius: 10px; padding: 11px 14px;
  font-size: .82rem; font-weight: 600; margin-bottom: 18px;
  display: flex; align-items: center; gap: 8px;
  animation: shake .4s ease;
}
.err svg { width: 15px; height: 15px; flex-shrink: 0; }
@keyframes shake {
  0%,100%{transform:translateX(0)} 20%{transform:translateX(-5px)}
  40%{transform:translateX(5px)} 60%{transform:translateX(-3px)} 80%{transform:translateX(3px)}
}

.btn-enter {
  width: 100%; padding: 15px; margin-top: 8px;
  background: linear-gradient(135deg, #1a5c2a 0%, #2d7d3f 100%);
  color: #fff; border: none; border-radius: 10px;
  font-family: 'Montserrat', sans-serif; font-size: .92rem; font-weight: 700;
  cursor: pointer; box-shadow: 0 4px 18px rgba(26,92,42,.3);
  transition: all .2s; display: flex; align-items: center; justify-content: center; gap: 9px;
  -webkit-appearance: none; appearance: none; letter-spacing: .01em;
}
.btn-enter:hover { background: linear-gradient(135deg,#133f1e 0%,#1a5c2a 100%); transform: translateY(-1px); box-shadow: 0 8px 26px rgba(26,92,42,.4); }
.btn-enter:active { transform: translateY(0); }
.btn-enter svg { width: 17px; height: 17px; }

.f-footer {
  margin-top: 30px; padding-top: 20px; border-top: 1px solid #e3dfd5;
  text-align: center; font-size: .72rem; color: #8a8f8b;
}
.f-footer strong { color: #1a5c2a; }

/* ─── MOBILE ─── */
@media (max-width: 680px) {
  .screen { grid-template-columns: 1fr; }

  .left {
    padding: 28px 20px 26px;
    flex-direction: row; flex-wrap: nowrap;
    justify-content: center; align-items: center; gap: 16px;
    min-height: 0;
  }
  .brand-logo, .brand-logo-fb {
    width: 68px; height: 68px; margin-bottom: 0; flex-shrink: 0; font-size: 2rem;
  }
  .left-text { display: flex; flex-direction: column; }
  .brand-name { font-size: 1.25rem; text-align: left; margin-bottom: 2px; }
  .brand-sub  { text-align: left; margin-bottom: 0; font-size: .6rem; }
  .brand-desc, .brand-since { display: none; }

  .right { padding: 28px 20px 40px; align-items: flex-start; }
  .form-wrap h2 { font-size: 1.3rem; }
  .form-wrap > p { font-size: .8rem; }
}
</style>
</head>
<body>
<div class="screen">

  <!-- ── ESQUERDA ── -->
  <div class="left">
    <img src="logo.png" alt="Logo" class="brand-logo"
         onerror="this.style.display='none';document.getElementById('lfb').style.display='flex'">
    <div class="brand-logo-fb" id="lfb">🌸</div>
    <div class="left-text">
      <div class="brand-name">Casa das<br>Flores</div>
      <div class="brand-sub">Painel WhatsApp</div>
    </div>
    <div class="brand-desc">Disparos inteligentes, CRM e organização de contatos para o seu WhatsApp comercial.</div>
    <div class="brand-since">
      <div class="since-line"></div>
      <span class="since-txt">Florianópolis · Desde 1983</span>
      <div class="since-line"></div>
    </div>
  </div>

  <!-- ── DIREITA ── -->
  <div class="right">
    <div class="form-wrap">
      <h2>Bem-vindo<br>de volta 👋</h2>
      <p>Acesse sua conta para gerenciar disparos e contatos.</p>
      <div class="gold-bar"></div>

      <?php if ($error): ?>
        <div class="err">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label>Usuário</label>
          <div class="inp-wrap">
            <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input type="text" name="username" placeholder="Seu usuário"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              autocomplete="username" autofocus required>
          </div>
        </div>
        <div class="form-group">
          <label>Senha</label>
          <div class="inp-wrap">
            <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" name="password" id="pw" placeholder="••••••••" autocomplete="current-password" required>
            <button type="button" class="eye-btn" onclick="togglePw()">
              <svg id="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <button type="submit" class="btn-enter">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          Entrar no painel
        </button>
      </form>

      <div class="f-footer">
        Casa das Flores · <strong>Desde 1983</strong> · Florianópolis<br>
        <a href="https://www.echolab.digital" target="_blank" style="color:#8a8f8b;font-size:.68rem;text-decoration:none;margin-top:4px;display:inline-block;transition:color .15s" onmouseover="this.style.color='#1a5c2a'" onmouseout="this.style.color='#8a8f8b'">Desenvolvido por echo_lab_digital</a>
      </div>
    </div>
  </div>

</div>
<script>
function togglePw() {
  const pw = document.getElementById('pw'), eye = document.getElementById('eye');
  if (pw.type === 'password') {
    pw.type = 'text';
    eye.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
  } else {
    pw.type = 'password';
    eye.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  }
}
</script>
</body>
</html>