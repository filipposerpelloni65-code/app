<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND active=1 LIMIT 1");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['full_name'];
        logActivity($user['id'], 'login', 'user', $user['id'], 'Login effettuato');
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Credenziali non valide.';
    }
}
$company = getSetting('company_name') ?: 'HelpDesk Aziendale';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login – <?= h($company) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/app.css">
<style>
body { font-family: 'Montserrat', sans-serif; background: #0f172a; }
.login-bg {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f2b5a 100%);
    position: relative;
    overflow: hidden;
    padding: 2rem 1rem;
}
.login-bg::before {
    content: '';
    position: absolute;
    width: 700px; height: 700px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(59,130,246,.12) 0%, transparent 65%);
    top: -200px; right: -200px;
    animation: loginPulse 7s ease infinite;
}
.login-bg::after {
    content: '';
    position: absolute;
    width: 500px; height: 500px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(99,102,241,.1) 0%, transparent 65%);
    bottom: -150px; left: -150px;
    animation: loginPulse 9s ease infinite reverse;
}
@keyframes loginPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.08); }
}
@media (prefers-reduced-motion: reduce) {
    .login-bg::before, .login-bg::after { animation: none; }
    .login-card { animation: none !important; opacity: 1; transform: none; }
    .btn-login { transition: none; }
}
@keyframes scaleIn {
    from { opacity: 0; transform: scale(0.93) translateY(20px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.login-card {
    width: 100%;
    max-width: 450px;
    position: relative;
    z-index: 1;
    animation: scaleIn 0.45s cubic-bezier(.34,1.56,.64,1) both;
}
.login-card .card {
    border: 1px solid rgba(255,255,255,.07) !important;
    border-radius: 20px !important;
    box-shadow: 0 30px 80px rgba(0,0,0,.4) !important;
    background: rgba(255,255,255,.97);
    backdrop-filter: blur(20px);
}
.login-logo {
    width: 64px; height: 64px;
    border-radius: 16px;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.25rem;
    box-shadow: 0 8px 24px rgba(59,130,246,.4);
}
.login-logo i { font-size: 2rem; color: white; }
.form-control {
    border-radius: 10px;
    border: 1.5px solid #e2e8f0;
    padding: 0.65rem 1rem;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.9rem;
    transition: border-color .2s, box-shadow .2s;
}
.form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}
.form-label {
    font-weight: 600;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #64748b;
    margin-bottom: .4rem;
}
.btn-login {
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: .9rem;
    padding: .7rem 1rem;
    letter-spacing: .02em;
    box-shadow: 0 4px 14px rgba(59,130,246,.4);
    transition: transform .15s, box-shadow .15s;
}
.btn-login:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(59,130,246,.5);
}
.input-icon-wrapper {
    position: relative;
}
.input-icon-wrapper .bi {
    position: absolute;
    left: 12px; top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 1rem;
    pointer-events: none;
}
.input-icon-wrapper .form-control {
    padding-left: 2.4rem;
}
</style>
</head>
<body>
<div class="login-bg">
  <div class="login-card">
    <div class="card">
      <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
          <div class="login-logo">
            <i class="bi bi-headset"></i>
          </div>
          <h4 class="fw-800 mb-1" style="color:#0f172a;letter-spacing:-.02em"><?= h($company) ?></h4>
          <p class="text-muted small mb-0">Accedi al sistema di assistenza</p>
        </div>
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2" style="border-radius:10px;border:none;background:#fee2e2;color:#b91c1c">
          <i class="bi bi-exclamation-circle-fill"></i><?= h($error) ?>
        </div>
        <?php endif; ?>
        <form method="post">
          <div class="mb-3">
            <label class="form-label">Username o Email</label>
            <div class="input-icon-wrapper">
              <i class="bi bi-person"></i>
              <input type="text" name="username" class="form-control" required autofocus placeholder="username o email">
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label">Password</label>
            <div class="input-icon-wrapper">
              <i class="bi bi-lock"></i>
              <input type="password" name="password" class="form-control" required placeholder="••••••••">
            </div>
          </div>
          <button type="submit" class="btn btn-login btn-primary w-100 text-white">
            <i class="bi bi-box-arrow-in-right me-2"></i>Accedi
          </button>
        </form>
        <div class="text-center mt-4">
          <small class="text-muted" style="font-size:.72rem">Sistema di gestione assistenza tecnica</small>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
