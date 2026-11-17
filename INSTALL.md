# CMDB - Proxmox (Debian 13 + MariaDB)

Página que importa as coletas do Proxmox para o MariaDB, cria os itens
(Cluster, NODE, VM/CT) com um identificador único **ICONF** e guarda o histórico
de alterações de recursos. Nenhum item pode ser removido.

## Estrutura

```
sql/schema.sql                 banco, tabelas, triggers anti-DELETE e usuário do app
config.sample.php              copie para config.php
src/                           db.php, importer.php (regras do CMDB), auth.php
public/                        index.php (árvore), historico.php, login/logout, atualizar/status, assets/
bin/consulta-api-proxmox.sh    seu script (ajustado, veja abaixo)
bin/coletar.sh                 roda o script e importa; usado pelo botão e pelo cron
bin/importar.php               importa um JSON manualmente
bin/criar-usuario.php          cria usuários da página
```

## Instalação

```bash
# 1) Pacotes
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php-cli php-mysql mariadb-server jq curl gawk

# 2) Arquivos
sudo mkdir -p /var/www/cmdb
sudo cp -r cmdb/. /var/www/cmdb/
sudo chown -R root:www-data /var/www/cmdb
sudo chmod 750 /var/www/cmdb/bin/*.sh /var/www/cmdb/bin/*.php

# 3) Banco  (edite a senha do usuário cmdb_app no final do schema.sql antes)
sudo mariadb < /var/www/cmdb/sql/schema.sql

# 4) Configuração
sudo cp /var/www/cmdb/config.sample.php /var/www/cmdb/config.php
sudo nano /var/www/cmdb/config.php          # senha do banco, título, etc.
sudo chmod 640 /var/www/cmdb/config.php

# 5) Credenciais do Proxmox (fora do script e fora do site)
sudo mkdir -p /etc/cmdb
sudo tee /etc/cmdb/proxmox.env >/dev/null <<'EOF'
PVE_HOST="IP_OU_NOME_DO_PROXMOX"
PVE_USER="usuario@pam!nome-do-token"
PVE_TOKEN="SEGREDO_DO_TOKEN"
EOF
sudo chown root:www-data /etc/cmdb/proxmox.env
sudo chmod 640 /etc/cmdb/proxmox.env

# 6) Pasta de trabalho da coleta (CSV, JSON, log, status)
sudo install -d -o www-data -g www-data -m 750 /var/lib/cmdb

# 7) Apache: aponte o DocumentRoot para a pasta public/
sudo tee /etc/apache2/sites-available/cmdb.conf >/dev/null <<'EOF'
<VirtualHost *:80>
    ServerName cmdb.exemplo.local
    DocumentRoot /var/www/cmdb/public
    <Directory /var/www/cmdb/public>
        Require all granted
    </Directory>
</VirtualHost>
EOF
sudo a2ensite cmdb && sudo systemctl reload apache2

# 8) Primeiro usuário
cd /var/www/cmdb
sudo -u www-data php bin/criar-usuario.php cmdb "Seu Nome" ADMIN

# 9) Teste a coleta pela linha de comando
sudo -u www-data /var/www/cmdb/bin/coletar.sh
cat /var/lib/cmdb/status.json
```

Depois é só abrir a página e usar **Atualizar Dados**. Para coletar automaticamente,
crie `/etc/cron.d/cmdb`:

```
*/30 * * * *  www-data  /var/www/cmdb/bin/coletar.sh
```

Use HTTPS no Apache (certbot ou certificado interno): a página tem login.

## Como o CMDB funciona

- **ICONF**: `ICONF-000001`, `ICONF-000002`... gerado uma única vez por item e nunca alterado
  (trigger no banco bloqueia mudança).
- **Hierarquia**: VM/CT → NODE → CLUSTER (coluna `pai_id`). O nome do cluster vem da API
  (`cluster/status`); se o Proxmox for standalone, usa `cluster_fallback` do `config.php`.
- **Chave de cada item**: cluster e node pelo nome; VM/CT pelo vmid. Se uma VM migrar de
  node, continua sendo o mesmo item (mesmo ICONF) e a troca fica no histórico (campo "Node").
- **Nova coleta**: VM/CT/node novo é criado; item existente só gera histórico se algum campo mudou.
- **Histórico** (`iconf_historico`): status, vCPU, memória, disco, rede, snapshots, node, nome,
  e nos nodes versão do PVE, cores, RAM e desgaste dos discos. Também registra criação e
  inativação/reativação.
- **Campos sem histórico** (mudam o tempo todo): dias desligada e storage livre — só o valor atual é guardado.
- **Item que some da coleta**: fica **inativo** (aparece esmaecido), nunca é apagado.
  Se um node não responde à API (versão `N/A`), nem ele nem suas VMs são inativados.
  JSON sem nodes é rejeitado.
- **Sem remoção**: não existe botão/rota de exclusão; triggers `BEFORE DELETE` bloqueiam
  DELETE em itens, atributos, histórico e coletas; o histórico também não pode ser alterado;
  o usuário `cmdb_app` não tem privilégio `DELETE`.

## Permissões do token no Proxmox

O token precisa de leitura em todo o datacenter: papel **PVEAuditor** no caminho `/` (com propagar).
Sem isso o Proxmox **não devolve erro**: ele entrega a lista de VMs vazia e o cluster sem nome.
Por isso a coleta confere as permissões do token antes de começar; se faltar `Sys.Audit`,
`VM.Audit` ou `Datastore.Audit`, ela é cancelada, nada é importado e a página mostra o que falta.

Um token criado com "Privilege Separation" ligado não herda as permissões do usuário
(mesmo `root@pam`): ele precisa de ACL própria. No shell de um node do Proxmox:

```bash
# Opção rápida: dar leitura ao token que já existe
pveum acl modify / --tokens 'root@pam!root' --roles PVEAuditor

# Opção recomendada: usuário e token só de leitura, dedicados ao CMDB
pveum user add cmdb@pve
pveum acl modify / --users cmdb@pve --roles PVEAuditor
pveum user token add cmdb@pve coleta --privsep 0     # mostra o segredo uma única vez
# depois ajuste /etc/cmdb/proxmox.env: PVE_USER="cmdb@pve!coleta" e PVE_TOKEN="<segredo>"
```

Para pular a conferência (não recomendado): `SKIP_PERM_CHECK=1` no `proxmox.env`.

## Nome do cluster

- Se a API informar o nome do cluster, ele é usado e o item CLUSTER existente é **renomeado**
  (mesmo ICONF; a troca fica no histórico). Nunca é criado um segundo cluster.
- Em Proxmox standalone (sem cluster) ou sem permissão, a API não informa o nome. Nesse caso o item
  mantém o nome atual; só na criação usa `cluster_fallback`.
- Para definir o nome na mão, ative `'cluster_nome' => 'NOME'` no `config.php`: na próxima
  coleta o cluster é renomeado para ele, sempre prevalecendo sobre a API.

## Tempo limite de conexão (60 s)

- Antes de coletar, o script testa a API do Proxmox (`/version`). Se não houver resposta em até
  **60 segundos**, a coleta é cancelada, nada é importado (os dados anteriores permanecem) e a
  página mostra **"Ambiente Inacessível para coleta"**.
- A mesma regra vale se a conexão cair no meio da coleta: ela é abortada na hora, sem importar dados parciais.
- A mensagem também aparece ao abrir a página, enquanto a última tentativa tiver sido essa; some na próxima coleta bem-sucedida.
- Credenciais recusadas (HTTP 401/403) mostram uma mensagem diferente ("Credenciais do Proxmox recusadas").
- Os limites podem ser ajustados no `proxmox.env`: `CONNECT_TIMEOUT` e `PRECHECK_MAX_TIME` (padrão 60) e
  `CALL_MAX_TIME` (padrão 300, limite de segurança por chamada).

## Mudanças feitas no seu script (`bin/consulta-api-proxmox.sh`)

1. Host, usuário e token saíram do script: são lidos de `/etc/cmdb/proxmox.env`.
2. O JSON agora traz `cluster.name`, `cpu_cores` e `memory_total_gb` por node
   (para o "128 Cores / 1007.72 GB RAM" do cabeçalho) e `snapshots` por VM/CT.
3. Aborta com erro se a API não devolver nenhum node, para não gerar um JSON vazio.
4. O arquivo colado tinha espaços não separáveis (U+00A0) na indentação, que o bash não
   aceita; foram trocados por espaços comuns.

## Segurança

- **Revogue o token que estava no script** (foi exposto em texto) e gere outro.
  Prefira um token de um usuário só de leitura (papel `PVEAuditor` no caminho `/`)
  em vez do `root@pam`.
- O script usa `curl -k` (não valida o certificado do Proxmox). Se possível, instale um
  certificado válido no PVE e remova o `-k`.
- Este módulo traz um login mínimo próprio (tabela `usuario`). Se for integrar a outro sistema com login,
  troque `src/auth.php` pelo login e pela sessão que ele já usa e remova `login.php`/`logout.php`.
