<?php
declare(strict_types=1);

/**
 * Chamado pelo cron a cada minuto (uma linha fixa, ver cron/cmdb.cron).
 * Lê o agendamento gravado na tela Ambientes e, se chegou a hora, executa bin/coletar.sh
 * (todos os ambientes ativos, em sequência). Seguro rodar a cada minuto: só coleta uma vez por horário.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/agendamento.php';

$log = fn(string $m) => print('[' . date('Y-m-d H:i:s') . "] $m\n");

try {
    $pdo = db();
    $agora = new DateTime('now');
    $a = agendamento_obter($pdo);      // garante que a linha existe
    agendamento_pulso($pdo, $agora);
    $slot = agendamento_slot_atual((string)$a['modo'], (string)$a['hora'], $agora);
    if ($slot === null) {
        exit(0);                       // desligado
    }
    $anterior = $a['ultimo_slot'];
    if ($anterior !== null && new DateTime((string)$anterior) >= $slot) {
        exit(0);                       // este horário já foi atendido
    }
    $slotTxt = $slot->format(AGENDA_FMT);
    if (!agendamento_reservar($pdo, $slotTxt)) {
        exit(0);                       // outra execução do agendador pegou este horário
    }

    $script = getenv('CMDB_COLETAR') ?: __DIR__ . '/coletar.sh';
    $log("Horário $slotTxt: iniciando coleta ({$a['modo']})");
    exec(escapeshellarg($script) . ' >/dev/null 2>&1', $saida, $rc);

    if ($rc === 3) {
        // já havia uma coleta em andamento: devolve o horário e tenta de novo no próximo minuto
        agendamento_devolver($pdo, $slotTxt, $anterior !== null ? (string)$anterior : null);
        $log('Coleta em andamento; nova tentativa no próximo minuto.');
        exit(0);
    }

    $estado = $rc === 0 ? 'ok' : 'erro';
    $msg = $rc === 0 ? 'Coleta concluída' : "Coleta terminou com código $rc";
    $arq = rtrim((string)(cfg()['data_dir'] ?? ''), '/') . '/status.json';
    if (is_readable($arq) && ($st = json_decode((string)file_get_contents($arq), true)) && isset($st['estado'])) {
        $estado = (string)$st['estado'];
        $msg = (string)($st['mensagem'] ?? $msg);
    }
    agendamento_resultado($pdo, new DateTime('now'), $estado, $msg);
    $log("Resultado: $estado - $msg");
    exit($estado === 'ok' ? 0 : 1);
} catch (Throwable $e) {
    $log('Erro: ' . $e->getMessage());
    exit(2);
}
