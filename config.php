<?php
// Copie para config.php e ajuste. Não deixe config.php dentro de public/.
return [
    'db_dsn'   => 'mysql:host=localhost;dbname=cmdb;charset=utf8mb4',
    'db_user'  => 'cmdb_app',
    'db_pass'  => 'C025ru477@!',

    'timezone' => 'America/Sao_Paulo',
    'chave_cifra' => 'eRLpbXq9c1YfsZhIxiXVMeyvSim0TuNux+T84T14ApU=',

    // Título exibido no topo da página
    'site_title' => 'CMDB',

    // Nome do item CLUSTER caso o script não consiga obter o nome via API
    // (ex.: node standalone, sem cluster configurado)
    'cluster_fallback' => 'PVE STANDALONE',

    // Pasta onde o coletor grava o CSV/JSON e o log (gravável pelo www-data)
    'data_dir' => '/var/lib/cmdb',

    // Nome do arquivo JSON gerado pelo script de coleta
    'json_name' => 'coleta.json',
];
