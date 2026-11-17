<?php
// Copie para config.php e ajuste. Não deixe config.php dentro de public/.
return [
    'db_dsn'   => 'mysql:host=localhost;dbname=cmdb;charset=utf8mb4',
    'db_user'  => 'cmdb_app',
    'db_pass'  => 'TROQUE_ESTA_SENHA',

    'timezone' => 'America/Sao_Paulo',

    // Título exibido no topo da página
    'site_title' => 'CMDB',

    // Pasta onde o coletor grava o CSV/JSON de cada ambiente, o log e o status (gravável pelo www-data)
    'data_dir' => '/var/lib/cmdb',

    // Chave que cifra (AES-256-GCM) os tokens dos ambientes cadastrados na tela "Ambientes".
    // Gere com:  php -r 'echo base64_encode(random_bytes(32)), "\n";'
    // GUARDE UMA CÓPIA: sem ela os tokens já cadastrados não podem ser lidos (teria de recadastrá-los).
    'chave_cifra' => '',

    // Os ambientes são cadastrados na tela "Ambientes" (ficam no banco, token cifrado).
    // Opcionalmente ainda funcionam arquivos em /etc/cmdb/ambientes/<slug>.env (bin/ambiente-add.sh).
];
