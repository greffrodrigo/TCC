#!/bin/bash
# Executa a coleta no Proxmox e importa o resultado no CMDB.
# Pode ser chamado pelo botão "Atualizar Dados" da página ou pelo cron:
#   */30 * * * *  www-data  /var/www/cmdb/bin/coletar.sh
# Só uma coleta roda por vez (flock).

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATA_DIR="$(php -r '$c = require $argv[1]; echo $c["data_dir"];' "$APP_DIR/config.php")"
JSON_NAME="$(php -r '$c = require $argv[1]; echo $c["json_name"];' "$APP_DIR/config.php")"

if [[ -z "$DATA_DIR" || -z "$JSON_NAME" ]]; then
    echo "Não foi possível ler data_dir/json_name de config.php" >&2
    exit 1
fi

mkdir -p "$DATA_DIR" || exit 1
LOCK="$DATA_DIR/coleta.lock"
STATUS="$DATA_DIR/status.json"
LOG="$DATA_DIR/coleta.log"

exec 9>"$LOCK"
if ! flock -n 9; then
    echo "Já existe uma coleta em andamento." >&2
    exit 3
fi

grava_status() {  # grava_status <estado> <mensagem>
    jq -n --arg e "$1" --arg m "$2" --arg i "$INICIO" --arg f "$(date -Iseconds)" \
        '{estado: $e, mensagem: $m, inicio: $i, atualizado_em: $f}' > "$STATUS.tmp" && mv "$STATUS.tmp" "$STATUS"
}

INICIO="$(date -Iseconds)"
grava_status "executando" "Consultando a API do Proxmox"

cd "$DATA_DIR" || exit 1
export CSV_FILE="${JSON_NAME%.json}.csv"

echo "=== Coleta iniciada em $INICIO ===" >> "$LOG"
saida_coleta="$("$APP_DIR/bin/consulta-api-proxmox.sh" 2>&1)"
rc=$?
echo "$saida_coleta" >> "$LOG"
if [[ $rc -eq 4 ]]; then
    # timeout de 60 s ou conexão perdida: nada é importado, dados anteriores ficam intactos
    grava_status "inacessivel" "Ambiente Inacessível para coleta"
    exit $rc
elif [[ $rc -eq 5 ]]; then
    # mostra o motivo exato impresso pelo script (última linha iniciada por ❌)
    motivo="$(printf '%s\n' "$saida_coleta" | grep '^❌' | tail -n 1 | sed 's/^❌ *//')"
    grava_status "erro" "${motivo:-Permissões ou credenciais do Proxmox recusadas. Veja coleta.log}"
    exit $rc
elif [[ $rc -ne 0 ]]; then
    grava_status "erro" "Falha ao consultar o Proxmox (código $rc). Veja coleta.log"
    exit $rc
fi

grava_status "executando" "Importando dados no banco"
saida="$(php "$APP_DIR/bin/importar.php" "$DATA_DIR/$JSON_NAME" 2>&1)"
rc=$?
echo "$saida" >> "$LOG"
if [[ $rc -ne 0 ]]; then
    grava_status "erro" "Falha na importação: $saida"
    exit $rc
fi

grava_status "ok" "$saida"
