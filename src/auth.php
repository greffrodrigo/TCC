<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function sessao(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('cmdb');
        session_start();
    }
}

function usuario_atual(): ?array
{
    sessao();
    return $_SESSION['usuario'] ?? null;
}

function exigir_login(): array
{
    $u = usuario_atual();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function csrf_token(): string
{
    sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valido(?string $t): bool
{
    sessao();
    return $t !== null && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
