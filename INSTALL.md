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
bin/coletar.sh                 coleta e importa os ambientes EM SEQUÊNCIA; usado pelo botão e pelo cron
bin/ambiente-add.sh            cadastra um ambiente Proxmox (arquivo em /etc/cmdb/ambientes)
bin/importar.php               importa o JSON de um ambiente manualmente
bin/criar-usuario.php          cria usuários da página
```

## Instalação

```bash
# 1) Pacotes
sudo apt update
sudo apt install -y nginx php-fpm php-cli php-mysql mariadb-server jq curl gawk

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

# 5) Ambientes Proxmox (um arquivo por ambiente, fora do site; o segredo do token é pedido no terminal)
sudo /var/www/cmdb/bin/ambiente-add.sh 01-avato-dr "PVE AVATO DR" IP_OU_NOME_DO_PROXMOX 'usuario@pam!nome-do-token'
# repita para cada ambiente (02-..., 03-...); veja "Vários ambientes" abaixo

# 6) Pasta de trabalho da coleta (CSV, JSON, log, status)
sudo install -d -o www-data -g www-data -m 750 /var/lib/cmdb

# 7) Nginx: o site serve SOMENTE a pasta public/  (confira o nome do socket com: ls /run/php/)
sudo tee /etc/nginx/sites-available/cmdb >/dev/null <<'EOF'
server {
    listen 80 default_server;
    server_name _;

    root /var/www/cmdb/public;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location ~ /\. {
        deny all;
    }
}
EOF
sudo ln -s /etc/nginx/sites-available/cmdb /etc/nginx/sites-enabled/cmdb
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

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

Use HTTPS no Nginx (certbot ou certificado interno): a página tem login.

## Telas de Usuários e Ambientes (novo)

Menu superior (só para o perfil ADMIN):

- **Usuários**: cadastrar, editar, trocar perfil (ADMIN / LEITOR), redefinir senha, ativar/desativar. Usuários não são excluídos, apenas desativados (a sessão de quem for desativado cai na hora). Não é possível desativar/rebaixar a si mesmo, e sempre resta ao menos um ADMIN ativo. Qualquer usuário troca a própria senha em "Alterar senha".
- **Ambientes**: informe nome, endereço (IP/DNS), porta, usuário da API (`usuario@realm!token`) e o segredo do token. Cada linha tem **Coletar agora**, e há **Coletar todos** (em sequência, pela coluna "Ordem"). Mostra o resultado da última coleta de cada ambiente ("Ambiente Inacessível para coleta" quando passa de 60 s). Para parar de coletar um ambiente, desmarque "Ativo" (nada é apagado).

### Aplicar em instalação existente

```bash
# 1) tabela de ambientes e coluna "ativo" dos usuários (pode rodar mais de uma vez)
sudo mariadb cmdb < sql/migracao-v2.sql
# 2) chave de cifra dos tokens: gere e cole em config.php ('chave_cifra'); guarde uma cópia
php -r 'echo base64_encode(random_bytes(32)), "\n";'
# 3) o PHP precisa da extensão openssl (já vem no php-cli/php-fpm do Debian)
# 4) copie os arquivos novos, mantendo config.php; recarregue o php-fpm
sudo systemctl reload php8.4-fpm   # ajuste a versão
```

Instalação nova: `sql/schema.sql` já traz tudo; crie o primeiro admin com `php bin/criar-usuario.php` (passo 8).

### Onde ficam os tokens

Ambientes cadastrados na tela guardam o token **cifrado no banco**; a chave fica só no `config.php` (fora de `public/`, leitura restrita: `chmod 640 config.php; chown root:www-data config.php`). O token nunca é exibido de volta nem escrito em log; na coleta é decifrado num arquivo temporário modo 600 que é apagado logo após a consulta. Ordem de coleta: ambientes da tela, depois arquivos de `/etc/cmdb/ambientes` (slug que não esteja na tela), depois o legado `/etc/cmdb/proxmox.env`.

## Como o CMDB funciona

- **ICONF**: `ICONF-000001`, `ICONF-000002`... gerado uma única vez por item e nunca alterado
  (trigger no banco bloqueia mudança).
- **Hierarquia**: VM/CT → NODE → CLUSTER (coluna `pai_id`). Cada ambiente cadastrado é um CLUSTER.
- **Chave de cada item**: o cluster pelo identificador do ambiente (slug); o node pelo nome **dentro do
  seu cluster**; VM/CT pelo vmid **dentro do seu cluster**. Por isso o mesmo VMID (ou o mesmo nome de
  node) pode existir em ambientes diferentes sem conflito: são itens distintos, cada um com seu ICONF.
  Se uma VM migrar de node dentro do mesmo cluster, continua sendo o mesmo item (mesmo ICONF) e a
  troca fica no histórico (campo "Node").
- **Nova coleta**: VM/CT/node novo é criado; item existente só gera histórico se algum campo mudou.
- **Histórico** (`iconf_historico`): status, vCPU, memória, disco, rede, snapshots, node, nome,
  e nos nodes versão do PVE, cores, RAM e desgaste dos discos. Também registra criação e
  inativação/reativação.
- **Campos sem histórico** (mudam o tempo todo): dias desligada e storage livre — só o valor atual é guardado.
- **Item que some da coleta**: fica **inativo** (aparece esmaecido), nunca é apagado.
  A inativação só considera os itens do ambiente que acabou de ser coletado.
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
# depois cadastre-o:  sudo bin/ambiente-add.sh 01-avato-dr "PVE AVATO DR" IP 'cmdb@pve!coleta'
```

Para pular a conferência (não recomendado): `SKIP_PERM_CHECK=1` no arquivo do ambiente.

## Vários ambientes (coleta em sequência)

Cada ambiente (um Proxmox/cluster) é um arquivo em `/etc/cmdb/ambientes/<slug>.env`
(modo 640, dono root:www-data; fora do site e fora do banco, para o token não ficar exposto):

```bash
sudo bin/ambiente-add.sh 01-avato-dr "PVE AVATO DR" 10.0.0.10 'cmdb@pve!coleta'
sudo bin/ambiente-add.sh 02-filial   "Filial SP"    10.1.0.10 'cmdb@pve!coleta' 8006
ls /etc/cmdb/ambientes/
```

O script pede o segredo do token no terminal. Conteúdo do arquivo (pode ser editado à mão):

```
AMBIENTE_NOME="PVE AVATO DR"     # nome exibido do cluster
PVE_HOST=10.0.0.10
PVE_PORT=8006                    # opcional
PVE_USER='cmdb@pve!coleta'
PVE_TOKEN=segredo
```

- **Slug** (nome do arquivo, só minúsculas, números, `_` e `-`): identifica o ambiente no banco e
  **não deve mudar** depois. O nome exibido pode mudar a qualquer momento em `AMBIENTE_NOME`;
  o cluster é renomeado na próxima coleta (mesmo ICONF, troca registrada no histórico).
- **Ordem**: a coleta percorre os arquivos em ordem alfabética, um por vez; use prefixos (`01-`, `02-`) se importar.
- **Falha em um ambiente não interrompe os demais**: o ambiente com falha não importa nada
  (os dados anteriores dele ficam intactos) e a coleta segue para o próximo. A página mostra
  "Coleta parcial" com o motivo de cada ambiente, por exemplo
  `Filial SP: Ambiente Inacessível para coleta`.
- **Na página**: **Atualizar Dados** atualiza todos em sequência; o botão **Atualizar este ambiente**,
  no cabeçalho de cada cluster, atualiza só aquele.
- **Linha de comando / cron**: `coletar.sh` (todos) ou `coletar.sh 02-filial` (um só).
  Teste um ambiente novo com `sudo -u www-data /var/www/cmdb/bin/coletar.sh 02-filial`.
- **Nome do cluster**: vem de `AMBIENTE_NOME`; sem ele, da API (`cluster/status`); em Proxmox
  standalone, ou sem permissão, a API não informa nome e o item usa o slug.
- **Compatibilidade**: se `/etc/cmdb/ambientes` não tiver nenhum `.env`, o `/etc/cmdb/proxmox.env`
  antigo é usado como um único ambiente de slug `padrao`.
- Se já havia um cluster no banco criado pela versão anterior, associe-o ao ambiente com o slug
  certo **antes** da próxima coleta (para não criar um segundo cluster):
  `UPDATE iconf_item SET chave_origem = '01-avato-dr' WHERE tipo = 'CLUSTER';`
  (com um único cluster no banco; o `cmdb_app` tem permissão de UPDATE).

## Tempo limite de conexão (60 s)

- Antes de coletar, o script testa a API do Proxmox (`/version`). Se não houver resposta em até
  **60 segundos**, a coleta é cancelada, nada é importado (os dados anteriores permanecem) e a
  página mostra **"Ambiente Inacessível para coleta"**.
- A mesma regra vale se a conexão cair no meio da coleta: ela é abortada na hora, sem importar dados parciais.
- A mensagem também aparece ao abrir a página, enquanto a última tentativa tiver sido essa; some na próxima coleta bem-sucedida.
- Credenciais recusadas (HTTP 401/403) mostram uma mensagem diferente ("Credenciais do Proxmox recusadas").
- Os limites podem ser ajustados no arquivo do ambiente: `CONNECT_TIMEOUT` e `PRECHECK_MAX_TIME` (padrão 60) e
  `CALL_MAX_TIME` (padrão 300, limite de segurança por chamada).

## Mudanças feitas no seu script (`bin/consulta-api-proxmox.sh`)

1. Host, usuário e token saíram do script: são lidos do arquivo do ambiente em `/etc/cmdb/ambientes/`
   (variável `PVE_ENV_FILE`, definida pelo `coletar.sh` para cada ambiente).
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
