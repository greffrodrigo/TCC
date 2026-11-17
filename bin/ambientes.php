<?php
declare(strict_types=1);

/**
 * Apoio do coletar.sh aos ambientes cadastrados na tela (banco).
 *   php bin/ambientes.php listar                       slugs dos ambientes ATIVOS, na ordem de coleta
 *   php bin/ambientes.php env <slug>                   credenciais em formato de .env (usa a chave_cifra do config.php)
 *   php bin/ambientes.php resultado <slug> <estado> <mensagem>   grava o resultado da última coleta
 * Só funciona na linha de comando. O token nunca é impresso em log: "env" é redirecionado a um arquivo temporário 600.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/ambientes.php';

$cmd = $argv[1] ?? '';
try {
    $pdo = db();
    switch ($cmd) {
        case 'listar':
            foreach (ambientes_listar($pdo, true) as $a) {
                echo $a['slug'], "\n";
            }
            break;
        case 'env':
            $slug = $argv[2] ?? '';
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
                throw new InvalidArgumentException('Slug inválido.');
            }
            echo ambiente_env_texto($pdo, $slug, chave_cifra());
            break;
        case 'resultado':
            $slug = $argv[2] ?? '';
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
                throw new InvalidArgumentException('Slug inválido.');
            }
            ambiente_registrar_resultado($pdo, $slug, (string)($argv[3] ?? 'erro'), (string)($argv[4] ?? ''));
            break;
        default:
            fwrite(STDERR, "Uso: php ambientes.php listar | env <slug> | resultado <slug> <estado> <mensagem>\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(2);
}
