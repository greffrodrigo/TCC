<?php
declare(strict_types=1);

/**
 * Importa um JSON de coleta para o CMDB.
 * Uso: php bin/importar.php /var/lib/cmdb/IVELOZ2026.json
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/db.php';
require dirname(__DIR__) . '/src/importer.php';

$arquivo = $argv[1] ?? '';
if ($arquivo === '' || !is_readable($arquivo)) {
    fwrite(STDERR, "Uso: php importar.php <arquivo.json>\n");
    exit(1);
}

try {
    $json = json_decode((string)file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR);
    $r = (new Importador(db()))->importar(
        $json,
        cfg()['cluster_fallback'] ?? 'CLUSTER',
        cfg()['cluster_nome'] ?? null
    );
    printf(
        "Coleta #%d importada: %d lidos, %d criados, %d alterados, %d inativados\n",
        $r['coleta_id'], $r['lidos'], $r['criados'], $r['alterados'], $r['inativados']
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro na importação: ' . $e->getMessage() . "\n");
    exit(2);
}
