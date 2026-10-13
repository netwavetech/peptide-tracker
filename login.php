<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (app_is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
$next = $_GET['next'] ?? $_POST['next'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');

    if (app_login($password)) {
        if (
            !is_string($next)
            || $next === ''
            || str_starts_with($next, 'http://')
            || str_starts_with($next, 'https://')
            || str_starts_with($next, '//')
        ) {
            $next = 'index.php';
        }

        header('Location: ' . $next);
        exit;
    }

    $error = 'Incorrect password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Peptide Research</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: radial-gradient(circle at top right, rgba(13,148,136,.08), transparent 28rem), #f4f8f7;
        }
        .login-shell { width: min(420px, calc(100% - 2rem)); }
        .login-card { border-radius: 1.25rem; border: 1px solid rgba(203,213,225,.7); box-shadow: 0 20px 60px rgba(15,23,42,.09); }
        .login-mark { width: 48px; height: 48px; display: grid; place-items: center; margin: 0 auto 1rem; border-radius: 14px; background: #e9f8f4; color: #0f8f7d; font-size: 1.4rem; font-weight: 700; }
    </style>
</head>
<body>
<div class="login-shell">
    <div class="card login-card">
        <div class="card-body p-4 p-md-5">
            <div class="login-mark">+</div>
            <h1 class="h4 text-center mb-1">Peptide Research</h1>
            <p class="text-body-secondary text-center mb-4">Enter the access password to continue.</p>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="next" value="<?= htmlspecialchars((string)$next, ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control form-control-lg" id="password" name="password" required autofocus autocomplete="current-password">
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit">Continue</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
