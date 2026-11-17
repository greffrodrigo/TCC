<?php
// Copie para config.php e ajuste. Não deixe config.php dentro de public/.
return [
    'db_dsn'   => 'mysql:host=localhost;dbname=cmdb;charset=utf8mb4',
    'db_user'  => 'cmdb_app',
    'db_pass'  => 'TROQUE_ESTA_SENHA',

    'timezone' => 'America/Sao_Paulo',

    // Título exibido no topo da página
    'site_title' => 'CMDB',

    // Nome do item CLUSTER usado só na criação, se a API não informar o nome
    // (ex.: node standalone, sem cluster configurado)
    'cluster_fallback' => 'PVE AVATO DR',

    // OPCIONAL: força o nome do cluster. Se definido, o item CLUSTER existente é
    // renomeado para este valor na próxima coleta (ICONF preservado, histórico registrado).
    // Útil quando o Proxmox é standalone ou a API não devolve o nome.
    // 'cluster_nome' => 'PVE AVATO DR',

    // Pasta onde o coletor grava o CSV/JSON e o log (gravável pelo www-data)
    'data_dir' => '/var/lib/cmdb',

    // Nome do arquivo JSON gerado pelo script de coleta
    'json_name' => 'IVELOZ2026.json',
];
