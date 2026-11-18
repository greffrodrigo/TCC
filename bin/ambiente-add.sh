#!/bin/bash

set -e

if [[ $EUID -ne 0 ]]; then
    echo "Execute como root (sudo)." >&2
    exit 1
fi

slug="$1"; nome="$2"; host="$3"; usuario="$4"; porta="${5:-8006}"
if [[ -z "$slug" || -z "$nome" || -z "$host" || -z "$usuario" ]]; then
    cat >&2 <<'USO'
Cadastra (ou atualiza) um ambiente Proxmox em /etc/cmdb/ambientes/<slug>.env

Uso (como root):
  sudo bin/ambiente-add.sh <slug> "<Nome do cluster>" <host> <usuario!token> [porta]
Exemplo:
  sudo bin/ambiente-add.sh 01-avato-dr "PVE AVATO DR" 10.0.0.10 'cmdb@pve!coleta'

O segredo do token é pedido no terminal (não fica no histórico do shell).
A ordem da coleta é a ordem alfabética dos slugs: use prefixos (01-, 02-...) se importar.
O slug identifica o ambiente no banco e NÃO deve mudar depois; o nome pode ser alterado
a qualquer momento (editando AMBIENTE_NOME no arquivo).
USO
    exit 1
fi
if [[ ! "$slug" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
    echo "Slug inválido: use só minúsculas, números, _ e -" >&2
    exit 1
fi
if [[ ! "$porta" =~ ^[0-9]+$ ]]; then
    echo "Porta inválida: $porta" >&2
    exit 1
fi

read -r -s -p "Segredo do token de $usuario: " token
echo
if [[ -z "$token" ]]; then
    echo "Token vazio." >&2
    exit 1
fi

dir=/etc/cmdb/ambientes
install -d -m 750 -o root -g www-data /etc/cmdb "$dir"
arq="$dir/$slug.env"

umask 077
{
    printf 'AMBIENTE_NOME=%q\n' "$nome"
    printf 'PVE_HOST=%q\n'      "$host"
    printf 'PVE_PORT=%q\n'      "$porta"
    printf 'PVE_USER=%q\n'      "$usuario"
    printf 'PVE_TOKEN=%q\n'     "$token"
} > "$arq"
chown root:www-data "$arq"
chmod 640 "$arq"

echo "Ambiente '$slug' gravado em $arq"
echo "Teste agora:  sudo -u www-data /var/www/cmdb/bin/coletar.sh $slug"
