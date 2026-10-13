<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $posted = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    if (!$stored || !hash_equals($stored, $posted)) {
        http_response_code(419);
        exit('Invalid request token.');
    }
}

function redirect_home(string $message = '', string $type = 'success'): never
{
    if ($message !== '') {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }

    header('Location: ../index.php');
    exit;
}

function format_dose(string|float|int|null $amount): string
{
    if ($amount === null || $amount === '') {
        return '';
    }

    $n = (float)$amount;

    if (abs($n - round($n)) < 0.00001) {
        return (string)(int)round($n);
    }

    return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
}
