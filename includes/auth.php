<?php
declare(strict_types=1);

/*
 * Simple single-password protection.
 * Change APP_PASSWORD before deploying.
 */
const APP_PASSWORD = 'ChangeMe!';
const APP_SESSION_KEY = 'peptide_app_authenticated';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function app_is_authenticated(): bool
{
    return !empty($_SESSION[APP_SESSION_KEY]);
}

function app_require_auth(): void
{
    if (!app_is_authenticated()) {
        $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }
}

function app_login(string $password): bool
{
    if (hash_equals(APP_PASSWORD, $password)) {
        session_regenerate_id(true);
        $_SESSION[APP_SESSION_KEY] = true;
        return true;
    }

    return false;
}

function app_logout(): void
{
    unset($_SESSION[APP_SESSION_KEY]);
    session_regenerate_id(true);
}
