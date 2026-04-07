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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/app.css">
<style>body{background:#f0f2f5;} .login-card{max-width:420px;margin:100px auto;}</style>
</head>
<body>
<div class="login-card">
  <div class="card shadow-sm border-0">
    <div class="card-body p-4">
      <div class="text-center mb-4">
        <i class="bi bi-headset fs-1 text-primary"></i>
        <h4 class="mt-2 fw-bold"><?= h($company) ?></h4>
        <p class="text-muted small">Accedi al sistema</p>
      </div>
      <?php if ($error): ?>
      <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= h($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label">Username o Email</label>
          <input type="text" name="username" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Accedi</button>
      </form>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
