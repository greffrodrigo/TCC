#!/bin/bash
# === CONFIGURAÇÕES ===
# As credenciais ficam fora do script, em um arquivo só legível pelo usuário
# que executa a coleta (ex.: /etc/cmdb/proxmox.env, chmod 640):
#   PVE_HOST="..."
#   PVE_USER="usuario@pam!nome-do-token"
#   PVE_TOKEN="..."
PVE_ENV_FILE="${PVE_ENV_FILE:-/etc/cmdb/proxmox.env}"
if [[ -r "$PVE_ENV_FILE" ]]; then
    # shellcheck disable=SC1090
    source "$PVE_ENV_FILE"
fi
if [[ -z "$PVE_HOST" || -z "$PVE_USER" || -z "$PVE_TOKEN" ]]; then
    echo "❌ Defina PVE_HOST, PVE_USER e PVE_TOKEN (ver $PVE_ENV_FILE)" >&2
    exit 1
fi

API_URL="https://$PVE_HOST:${PVE_PORT:-8006}/api2/json"
CSV_FILE="${CSV_FILE:-IVELOZ2026.csv}"

# === Limites de tempo ===
# Códigos de saída: 4 = ambiente inacessível (timeout/conexão), 5 = credenciais recusadas
CONNECT_TIMEOUT="${CONNECT_TIMEOUT:-60}"   # tempo máximo para conectar (segundos)
PRECHECK_MAX_TIME="${PRECHECK_MAX_TIME:-60}" # tempo máximo do teste inicial da API
CALL_MAX_TIME="${CALL_MAX_TIME:-300}"      # limite de segurança por chamada

# Qualquer falha de conexão no meio da coleta (api_get envia USR1) cancela tudo
trap 'echo "❌ Ambiente Inacessível para coleta" >&2; exit 4' USR1
# Permissão negada (401/403) em chamada essencial (api_get envia USR2): a mensagem já foi impressa
trap 'exit 5' USR2

# === Teste inicial: a API precisa responder em até 60 s ===
precheck_code=$(curl -s -k -o /dev/null -w '%{http_code}' \
    --connect-timeout "$CONNECT_TIMEOUT" --max-time "$PRECHECK_MAX_TIME" \
    -H "Authorization: PVEAPIToken=$PVE_USER=$PVE_TOKEN" "$API_URL/version")
precheck_rc=$?
if [[ $precheck_rc -ne 0 ]]; then
    echo "❌ Ambiente Inacessível para coleta (sem resposta em ${PRECHECK_MAX_TIME}s, curl código $precheck_rc)" >&2
    exit 4
fi
if [[ "$precheck_code" == "401" || "$precheck_code" == "403" ]]; then
    echo "❌ Credenciais do Proxmox recusadas (HTTP $precheck_code)" >&2
    exit 5
fi

# === Permissões do token ===
# Sem VM.Audit o Proxmox NÃO dá erro: devolve a lista de VMs vazia. Por isso as permissões
# efetivas do token são conferidas antes de coletar (papel PVEAuditor em "/" cobre tudo).
# Para pular esta checagem: SKIP_PERM_CHECK=1 no proxmox.env.
PERMS_NEEDED='["Sys.Audit","VM.Audit","Datastore.Audit"]'
if [[ "${SKIP_PERM_CHECK:-0}" != "1" ]]; then
    perm_json=$(curl -s -k --connect-timeout "$CONNECT_TIMEOUT" --max-time "$PRECHECK_MAX_TIME" \
        -H "Authorization: PVEAPIToken=$PVE_USER=$PVE_TOKEN" "$API_URL/access/permissions")
    if [[ $? -ne 0 ]]; then
        echo "❌ Ambiente Inacessível para coleta (sem resposta em ${PRECHECK_MAX_TIME}s ao consultar permissões)" >&2
        exit 4
    fi
    if echo "$perm_json" | jq -e '.data | type == "object"' >/dev/null 2>&1; then
        perm_missing=$(echo "$perm_json" | jq -r --argjson need "$PERMS_NEEDED" \
            '$need - [.data["/"] // {} | keys[]] | join(", ")')
        if [[ -n "$perm_missing" ]]; then
            echo "❌ Permissões insuficientes: o token $PVE_USER não tem $perm_missing em '/'. Atribua o papel PVEAuditor em / (com propagar) ao token e ao usuário." >&2
            exit 5
        fi
    else
        echo "⚠️ Não foi possível conferir as permissões do token (resposta inesperada de access/permissions); seguindo." >&2
    fi
fi

# === Função API GET ===
api_get() {
    local endpoint=$1 out rc code body
    out=$(curl -s -k --connect-timeout "$CONNECT_TIMEOUT" --max-time "$CALL_MAX_TIME" \
        -w $'\n%{http_code}' -H "Authorization: PVEAPIToken=$PVE_USER=$PVE_TOKEN" "$API_URL/$endpoint")
    rc=$?
    if [[ $rc -ne 0 ]]; then
        echo "❌ Falha de conexão em $endpoint (curl código $rc)" >&2
        kill -USR1 $$   # $$ continua sendo o PID do script principal dentro de $(...)
        return $rc
    fi
    code="${out##*$'\n'}"
    body="${out%$'\n'*}"
    if [[ "$code" == "401" || "$code" == "403" ]]; then
        # chamadas essenciais (nodes, lista/config/status de VM e CT): sem elas não há coleta confiável
        if [[ "$endpoint" =~ ^nodes$|^nodes/[^/]+/(qemu|lxc)$|^nodes/[^/]+/(qemu|lxc)/[0-9]+/(config|status/current)$ ]]; then
            echo "❌ Permissão negada (HTTP $code) em $endpoint. Revise as permissões do token no Proxmox." >&2
            kill -USR2 $$
            return 1
        fi
        echo "⚠️ Permissão negada (HTTP $code) em $endpoint; dado opcional ignorado." >&2
    fi
    printf '%s' "$body"
}

# === Converte tamanho para GB ===
convert_to_gb() {
    local raw_size="$1"
    if [[ "$raw_size" == *T ]]; then
        awk -v t="${raw_size%T}" 'BEGIN {printf "%.2f", t * 1024}'
    elif [[ "$raw_size" == *G ]]; then
        echo "${raw_size%G}"
    elif [[ "$raw_size" == *M ]]; then
        awk -v m="${raw_size%M}" 'BEGIN {printf "%.2f", m / 1024}'
    elif [[ "$raw_size" =~ ^[0-9]+$ ]]; then
        awk -v b="$raw_size" 'BEGIN {printf "%.2f", b / 1073741824}'
    else
        echo "0"
    fi
}

# === Status das interfaces de rede (netX) ===
# Retorna algo como: net0:up;net1:down
get_net_status() {
    local config="$1"
    local vm_state="$2"
    local result=""
    local nets net key val st

    mapfile -t nets < <(echo "$config" | jq -r '.data | to_entries[] | select(.key | test("^net[0-9]+$")) | "\(.key)|\(.value)"')

    if [[ ${#nets[@]} -eq 0 ]]; then
        echo "none"
        return
    fi

    for net in "${nets[@]}"; do
        key="${net%%|*}"
        val="${net#*|}"

        if [[ "$val" == *link_down=1* ]]; then
            st="down"
        elif [[ "$vm_state" == "running" ]]; then
            st="up"
        else
            st="stopped"
        fi

        result+="${result:+;}${key}:${st}"
    done

    echo "$result"
}

# === Dias desde que a VM/CT foi desligada (somente se STATUS = stopped) ===
# Uso: get_stopped_days <node> <qemu|lxc> <id> <state>
# 1) Usa o histórico de tarefas (parada/desligamento x início) -> data exata
# 2) Se não achar, ou se houve início depois da parada (desligado por dentro
#    do guest), usa o histórico RRD: último momento com CPU registrada
get_stopped_days() {
    local node="$1" type="$2" id="$3" state="$4"
    local stop_re start_re tasks last_stop last_start last_ts rrd now

    if [[ "$state" != "stopped" ]]; then
        echo ""
        return
    fi

    if [[ "$type" == "qemu" ]]; then
        stop_re="^(qmstop|qmshutdown)$"
        start_re="^(qmstart|qmresume)$"
    else
        stop_re="^(vzstop|vzshutdown)$"
        start_re="^vzstart$"
    fi

    now=$(date +%s)
    tasks=$(api_get "nodes/$node/tasks?vmid=$id&limit=0&source=all")

    last_stop=$(echo "$tasks" | jq -r --arg re "$stop_re" '[.data[]? | select(.type | test($re)) | .endtime // empty] | max // empty')
    last_start=$(echo "$tasks" | jq -r --arg re "$start_re" '[.data[]? | select(.type | test($re)) | .endtime // empty] | max // empty')

    last_ts=""
    if [[ -n "$last_stop" ]] && { [[ -z "$last_start" ]] || (( last_stop >= last_start )); }; then
        last_ts="$last_stop"
    else
        rrd=$(api_get "nodes/$node/$type/$id/rrddata?timeframe=year")
        last_ts=$(echo "$rrd" | jq -r '[.data[]? | select(.cpu != null) | .time] | max // empty')
    fi

    if [[ -z "$last_ts" ]]; then
        echo "N/A"
    else
        echo $(( (now - last_ts) / 86400 ))
    fi
}

# === Versão do Proxmox instalada no node ===
get_node_version() {
    local node="$1"
    local v
    v=$(api_get "nodes/$node/version" | jq -r '.data.version // empty')
    echo "${v:-N/A}"
}

# === Nome do cluster (vazio se o node for standalone) ===
get_cluster_name() {
    api_get "cluster/status" | jq -r '[.data[]? | select(.type == "cluster") | .name][0] // empty'
}

# === Cores e RAM total do node ===
# Resultado em NODE_CORES e NODE_RAM_GB (N/A se a API não responder)
get_node_hw() {
    local node="$1"
    local st
    st=$(api_get "nodes/$node/status")
    NODE_CORES=$(echo "$st" | jq -r '.data.cpuinfo.cpus // empty')
    NODE_RAM_GB=$(echo "$st" | jq -r 'if .data.memory.total then ((.data.memory.total / 1073741824 * 100 | round) / 100) else empty end')
    NODE_CORES="${NODE_CORES:-N/A}"
    NODE_RAM_GB="${NODE_RAM_GB:-N/A}"
}

# === Quantidade de snapshots de uma VM/CT (ignora o "current") ===
# Uso: get_snapshot_count <node> <qemu|lxc> <id>
get_snapshot_count() {
    local node="$1" type="$2" id="$3"
    local n
    n=$(api_get "nodes/$node/$type/$id/snapshot" | jq -r '[.data[]? | select(.name != "current")] | length')
    echo "${n:-0}"
}

# === Wearout dos discos do node (quando disponível) ===
# Retorna algo como: nvme0n1:8%;nvme1n1:9%;sdb:N/A
# A API devolve a vida restante (100 = novo); a interface web mostra o
# desgaste (100 - valor), e é esse o valor exibido aqui.
# Discos compartilhados (mesmo WWN/serial visto em mais de um node) são
# listados apenas no primeiro node em que aparecem; nos demais fica "-".
# Obs.: não usar dentro de $(...), pois o controle de "já visto" precisa
# persistir entre os nodes. O resultado fica em WEAROUT_RESULT.
declare -A SEEN_DISK
get_node_wearout() {
    local node="$1"
    local data dev wwn serial wear key out="" total=0 skipped=0

    data=$(api_get "nodes/$node/disks/list" | jq -r '.data[]? | "\(.devpath | split("/") | last)|\(.wwn // "")|\(.serial // "")|\(.wearout // "N/A")"')

    while IFS='|' read -r dev wwn serial wear; do
        [[ -z "$dev" ]] && continue
        total=$((total + 1))

        # Converte vida restante (API) em desgaste (igual à interface web)
        if [[ "$wear" =~ ^[0-9]+(\.[0-9]+)?$ ]]; then
            wear=$(awk -v w="$wear" 'BEGIN {printf "%d%%", 100 - w}')
        fi

        key=""
        if [[ -n "$wwn" && "$wwn" != "unknown" ]]; then
            key="$wwn"
        elif [[ -n "$serial" ]]; then
            key="$serial"
        fi

        if [[ -n "$key" ]]; then
            if [[ -n "${SEEN_DISK[$key]}" ]]; then
                skipped=$((skipped + 1))
                continue
            fi
            SEEN_DISK[$key]=1
        fi

        out+="${out:+;}${dev}:${wear}"
    done <<< "$data"

    if [[ $total -eq 0 ]]; then
        WEAROUT_RESULT="N/A"
    elif [[ -z "$out" ]]; then
        WEAROUT_RESULT="-"
    else
        WEAROUT_RESULT="$out"
    fi
}

# === Espaço livre em cada storage do node (GB) ===
# Retorna algo como: local:50.25GB;local-lvm:300.10GB
# Storages compartilhados (shared=1, mapeados em vários nodes) são listados
# apenas no primeiro node em que aparecem; nos demais fica "-".
# Storages locais (ex.: local, local-lvm) continuam sendo listados por node.
# Obs.: não usar dentro de $(...). O resultado fica em STORAGE_FREE_RESULT.
declare -A SEEN_STORAGE
get_node_storage_free() {
    local node="$1"
    local data st shared free out="" total=0

    data=$(api_get "nodes/$node/storage" | jq -r '.data[]? | select(.avail != null) | "\(.storage)|\(.shared // 0)|\(((.avail / 1073741824 * 100 | round) / 100))"')

    while IFS='|' read -r st shared free; do
        [[ -z "$st" ]] && continue
        total=$((total + 1))

        if [[ "$shared" == "1" ]]; then
            [[ -n "${SEEN_STORAGE[$st]}" ]] && continue
            SEEN_STORAGE[$st]=1
        fi

        out+="${out:+;}${st}:${free}GB"
    done <<< "$data"

    if [[ $total -eq 0 ]]; then
        STORAGE_FREE_RESULT="N/A"
    elif [[ -z "$out" ]]; then
        STORAGE_FREE_RESULT="-"
    else
        STORAGE_FREE_RESULT="$out"
    fi
}

# === Exportação JSON ===
# Os registros são acumulados em arquivos temporários e o JSON final é
# montado no fim do script (nodes -> guests).
JSON_FILE="${CSV_FILE%.csv}.json"
JSON_NODES_TMP=$(mktemp)
JSON_GUESTS_TMP=$(mktemp)
trap 'rm -f "$JSON_NODES_TMP" "$JSON_GUESTS_TMP"' EXIT

JQ_PARSERS='
def net_list: if . == "" or . == "none" then [] else split(";") | map(split(":") | {interface: .[0], status: .[1]}) end;
def wear_list: if . == "" or . == "N/A" or . == "-" then [] else split(";") | map(split(":") | {disk: .[0], wearout_pct: (.[1] | rtrimstr("%") | try tonumber catch null)}) end;
def storage_list: if . == "" or . == "N/A" or . == "-" then [] else split(";") | map(split(":") | {storage: .[0], free_gb: (.[1] | rtrimstr("GB") | try tonumber catch null)}) end;
'

# Uso: json_add_node <node> <versão> <wearout> <storage_free> <cores> <ram_gb>
json_add_node() {
    jq -nc --arg node "$1" --arg ver "$2" --arg wear "$3" --arg stor "$4" --arg cores "$5" --arg ram "${6//,/.}" \
        "$JQ_PARSERS"'{node: $node, pve_version: $ver, cpu_cores: ($cores | try tonumber catch null), memory_total_gb: ($ram | try tonumber catch null), disk_wearout: ($wear | wear_list), storage_free: ($stor | storage_list)}' \
        >> "$JSON_NODES_TMP"
}

# Uso: json_add_guest <tipo> <node> <id> <nome> <status> <mem_gb> <disk_gb> <vcpus> <net_status> <dias_parada> <snapshots>
json_add_guest() {
    jq -nc --arg type "$1" --arg node "$2" --arg id "$3" --arg name "$4" --arg state "$5" \
        --arg mem "${6//,/.}" --arg disk "${7//,/.}" --arg vcpus "$8" --arg net "$9" --arg days "${10}" --arg snaps "${11}" \
        "$JQ_PARSERS"'{type: $type, node: $node, id: ($id | tonumber), name: $name, status: $state, memory_gb: ($mem | tonumber), disk_gb: ($disk | tonumber), vcpus: ($vcpus | try tonumber catch $vcpus), network: ($net | net_list), days_stopped: ($days | try tonumber catch null), snapshots: ($snaps | try tonumber catch null)}' \
        >> "$JSON_GUESTS_TMP"
}

# === Cabeçalho do CSV ===
echo "TYPE,NODE,ID,NAME,STATUS,MEMORY_GB,DISK_GB,VCPUS,NET_STATUS,DAYS_STOPPED,PVE_VERSION,DISK_WEAROUT,STORAGE_FREE" > "$CSV_FILE"

# === Processa todos os nodes ===
CLUSTER_NAME=$(get_cluster_name)
nodes=$(api_get "nodes" | jq -r '.data[].node')

# Sem nodes = API fora do ar ou token inválido: aborta sem gerar JSON vazio
if [[ -z "$nodes" ]]; then
    echo "❌ Nenhum node retornado pela API (host, token ou permissões?)" >&2
    exit 2
fi

for NODE in $nodes; do
    # --- Informações do node (coletadas uma vez por node) ---
    pve_version=$(get_node_version "$NODE")
    get_node_hw "$NODE"
    get_node_wearout "$NODE";       disk_wearout="$WEAROUT_RESULT"
    get_node_storage_free "$NODE";  storage_free="$STORAGE_FREE_RESULT"
    json_add_node "$NODE" "$pve_version" "$disk_wearout" "$storage_free" "$NODE_CORES" "$NODE_RAM_GB"

    # --- VMs (QEMU) ---
    vms=$(api_get "nodes/$NODE/qemu" | jq -r '.data[].vmid')
    for VMID in $vms; do
        config=$(api_get "nodes/$NODE/qemu/$VMID/config")
        status=$(api_get "nodes/$NODE/qemu/$VMID/status/current")

        name=$(echo "$config" | jq -r '.data.name // "N/A"')
        memory_mb=$(echo "$config" | jq -r '.data.memory // 0')
        memory_gb=$(awk -v m="$memory_mb" 'BEGIN {printf "%.2f", m / 1024}')
        state=$(echo "$status" | jq -r '.data.status // "unknown"')

        # === Coleta vCPUs ===
        cores=$(echo "$config" | jq -r '.data.cores // 1')
        sockets=$(echo "$config" | jq -r '.data.sockets // 1')
        explicit_vcpus=$(echo "$config" | jq -r '.data.vcpus // empty')

        if [[ -n "$explicit_vcpus" ]]; then
            vcpus="$explicit_vcpus"
        else
            vcpus=$((cores * sockets))
        fi

        # === Discos ===
        mapfile -t disks < <(echo "$config" | jq -r '.data | to_entries[] | select(.key | test("^(scsi|virtio|sata|ide)[0-9]+$")) | .value')
        total_gb=0
        for disk in "${disks[@]}"; do
            raw_size=$(echo "$disk" | grep -oP 'size=\K[0-9]+[TGM]?' || echo 0)
            size_gb=$(convert_to_gb "$raw_size")
            total_gb=$(awk -v a="$total_gb" -v b="$size_gb" 'BEGIN {printf "%.2f", a + b}')
        done

        # === Rede ===
        net_status=$(get_net_status "$config" "$state")

        # === Dias desligada ===
        days_stopped=$(get_stopped_days "$NODE" "qemu" "$VMID" "$state")
        snapshots=$(get_snapshot_count "$NODE" "qemu" "$VMID")
        json_add_guest "VM" "$NODE" "$VMID" "$name" "$state" "$memory_gb" "$total_gb" "$vcpus" "$net_status" "$days_stopped" "$snapshots"

        echo "VM,$NODE,$VMID,\"$name\",$state,$memory_gb,$total_gb,$vcpus,\"$net_status\",$days_stopped,\"$pve_version\",\"$disk_wearout\",\"$storage_free\"" >> "$CSV_FILE"
    done

    # --- Containers (LXC) ---
    cts=$(api_get "nodes/$NODE/lxc" | jq -r '.data[].vmid')
    for CTID in $cts; do
        config=$(api_get "nodes/$NODE/lxc/$CTID/config")
        status=$(api_get "nodes/$NODE/lxc/$CTID/status/current")

        name=$(echo "$config" | jq -r '.data.hostname // "N/A"')
        memory_mb=$(echo "$config" | jq -r '.data.memory // 0')
        memory_gb=$(awk -v m="$memory_mb" 'BEGIN {printf "%.2f", m / 1024}')
        state=$(echo "$status" | jq -r '.data.status // "unknown"')

        #cpus=$(echo "$config" | jq -r '.data.cpus // "N/A"')
        cpus=$(echo "$config" | jq -r '.data.cores // "N/A"')
        
    mapfile -t disks < <(echo "$config" | jq -r '.data | to_entries[] | select(.key | test("^(rootfs|mp[0-9]+)$")) | .value')
        total_gb=0
        for disk in "${disks[@]}"; do
            raw_size=$(echo "$disk" | grep -oP 'size=\K[0-9]+[TGM]?' || echo 0)
            size_gb=$(convert_to_gb "$raw_size")
            total_gb=$(awk -v a="$total_gb" -v b="$size_gb" 'BEGIN {printf "%.2f", a + b}')
        done

        # === Rede ===
        net_status=$(get_net_status "$config" "$state")

        # === Dias desligado ===
        days_stopped=$(get_stopped_days "$NODE" "lxc" "$CTID" "$state")
        snapshots=$(get_snapshot_count "$NODE" "lxc" "$CTID")
        json_add_guest "CT" "$NODE" "$CTID" "$name" "$state" "$memory_gb" "$total_gb" "$cpus" "$net_status" "$days_stopped" "$snapshots"

        echo "CT,$NODE,$CTID,\"$name\",$state,$memory_gb,$total_gb,$cpus,\"$net_status\",$days_stopped,\"$pve_version\",\"$disk_wearout\",\"$storage_free\"" >> "$CSV_FILE"
    done
done

# === Monta o JSON final ===
jq -n --slurpfile nodes "$JSON_NODES_TMP" --slurpfile guests "$JSON_GUESTS_TMP" --arg ts "$(date -Iseconds)" --arg cl "$CLUSTER_NAME" '
{
  generated_at: $ts,
  cluster: {name: (if $cl == "" then null else $cl end)},
  nodes: ($nodes | map(. as $n | . + {guests: ($guests | map(select(.node == $n.node) | del(.node)))}))
}' > "$JSON_FILE"

echo "✅ Relatório gerado com sucesso: $CSV_FILE"
echo "✅ JSON gerado com sucesso: $JSON_FILE"