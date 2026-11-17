<?php
declare(strict_types=1);

/**
 * Importa o JSON gerado pelo script de coleta do Proxmox para o CMDB.
 *
 * Regras:
 *  - Cluster, NODE e VM/CT viram itens individuais, cada um com um ICONF único.
 *  - Item novo na coleta => criado. Item conhecido => só as diferenças geram histórico.
 *  - Item que sumiu da coleta => marcado inativo (nunca removido).
 *  - Se um node não respondeu (pve_version = N/A) nada dele é alterado nem inativado.
 */

// Campos que geram histórico quando mudam
const CAMPOS_HISTORICO_NODE  = ['pve_version', 'cpu_cores', 'memory_total_gb', 'disk_wearout'];
const CAMPOS_HISTORICO_GUEST = ['node', 'status', 'vcpus', 'memory_gb', 'disk_gb', 'network', 'snapshots'];
// Campos guardados só como valor atual (mudam o tempo todo, geram ruído)
const CAMPOS_VOLATEIS_NODE   = ['storage_free'];
const CAMPOS_VOLATEIS_GUEST  = ['days_stopped'];

function norm(mixed $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
        $s = number_format((float)$v, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string)$v;
}

function fmt_network(mixed $net): ?string
{
    if (!is_array($net) || !$net) {
        return 'none';
    }
    $p = [];
    foreach ($net as $n) {
        $p[] = ($n['interface'] ?? '?') . ':' . ($n['status'] ?? '?');
    }
    return implode(';', $p);
}

function fmt_wearout(mixed $w): ?string
{
    if (!is_array($w) || !$w) {
        return null;
    }
    $p = [];
    foreach ($w as $d) {
        $pct = $d['wearout_pct'] ?? null;
        $p[] = ($d['disk'] ?? '?') . ':' . ($pct === null ? 'N/A' : $pct . '%');
    }
    return implode(';', $p);
}

function fmt_storage(mixed $s): ?string
{
    if (!is_array($s) || !$s) {
        return null;
    }
    $p = [];
    foreach ($s as $d) {
        $p[] = ($d['storage'] ?? '?') . ':' . norm($d['free_gb'] ?? null) . 'GB';
    }
    return implode(';', $p);
}

final class Importador
{
    private PDO $pdo;
    private int $coletaId = 0;
    public int $criados = 0;
    public int $alterados = 0;
    public int $inativados = 0;
    public int $lidos = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array{coleta_id:int,criados:int,alterados:int,inativados:int,lidos:int} */
    public function importar(array $json, string $clusterFallback, ?string $clusterNomeFixo = null): array
    {
        if (!isset($json['nodes']) || !is_array($json['nodes']) || count($json['nodes']) === 0) {
            throw new InvalidArgumentException('JSON inválido ou sem nodes: importação abortada para não inativar itens por engano.');
        }

        $geradaEm = null;
        if (!empty($json['generated_at'])) {
            try {
                $geradaEm = (new DateTime((string)$json['generated_at']))
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $geradaEm = null;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO coleta (gerada_em) VALUES (?)')->execute([$geradaEm]);
            $this->coletaId = (int)$this->pdo->lastInsertId();

            $vistos = [];       // ids de itens vistos nesta coleta
            $nodesFalha = [];   // ids de nodes que não responderam

            // ---- CLUSTER ----
            // Precedência do nome: config (cluster_nome) > nome vindo da API > nome já gravado > fallback.
            // Há um único item CLUSTER por instalação: mudou o nome => renomeia (mesmo ICONF).
            $nomeApi = trim((string)($json['cluster']['name'] ?? ''));
            $nomeFixo = trim((string)$clusterNomeFixo);
            $nomeConhecido = $nomeFixo !== '' ? $nomeFixo : $nomeApi;
            $clusterId = $this->garantirCluster($nomeConhecido, $clusterFallback);
            $vistos[$clusterId] = true;
            $this->lidos++;

            foreach ($json['nodes'] as $n) {
                $nodeNome = (string)($n['node'] ?? '');
                if ($nodeNome === '') {
                    continue;
                }
                $nodeId = $this->garantirItem('NODE', $nodeNome, $nodeNome, $clusterId);
                $vistos[$nodeId] = true;
                $this->lidos++;

                $falhou = (($n['pve_version'] ?? 'N/A') === 'N/A');
                if ($falhou) {
                    // node sem resposta da API: não mexe nele nem nos seus filhos
                    $nodesFalha[$nodeId] = true;
                    continue;
                }

                $this->gravarAtributos($nodeId, [
                    'pve_version'     => norm($n['pve_version'] ?? null),
                    'cpu_cores'       => norm($n['cpu_cores'] ?? null),
                    'memory_total_gb' => norm($n['memory_total_gb'] ?? null),
                    'disk_wearout'    => fmt_wearout($n['disk_wearout'] ?? null),
                    'storage_free'    => fmt_storage($n['storage_free'] ?? null),
                ], CAMPOS_HISTORICO_NODE);

                foreach (($n['guests'] ?? []) as $g) {
                    if (!isset($g['id'])) {
                        continue;
                    }
                    $tipo = (($g['type'] ?? 'VM') === 'CT') ? 'CT' : 'VM';
                    $nome = (string)($g['name'] ?? ('guest-' . $g['id']));
                    $gid  = $this->garantirItem($tipo, (string)(int)$g['id'], $nome, $nodeId);
                    $vistos[$gid] = true;
                    $this->lidos++;

                    $this->gravarAtributos($gid, [
                        'node'         => $nodeNome,
                        'status'       => norm($g['status'] ?? null),
                        'vcpus'        => norm($g['vcpus'] ?? null),
                        'memory_gb'    => norm($g['memory_gb'] ?? null),
                        'disk_gb'      => norm($g['disk_gb'] ?? null),
                        'network'      => fmt_network($g['network'] ?? null),
                        'snapshots'    => norm($g['snapshots'] ?? null),
                        'days_stopped' => norm($g['days_stopped'] ?? null),
                    ], CAMPOS_HISTORICO_GUEST);
                }
            }

            // ---- Itens que sumiram da coleta => inativos (nunca removidos) ----
            $rows = $this->pdo->query(
                "SELECT id, pai_id, ativo FROM iconf_item WHERE tipo IN ('NODE','VM','CT')"
            )->fetchAll();
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                if (isset($vistos[$id])) {
                    continue;
                }
                // filho de node que falhou: preserva
                if ($r['pai_id'] !== null && isset($nodesFalha[(int)$r['pai_id']])) {
                    continue;
                }
                if ((int)$r['ativo'] === 1) {
                    $this->pdo->prepare('UPDATE iconf_item SET ativo = 0, ultima_coleta_id = ultima_coleta_id WHERE id = ?')
                        ->execute([$id]);
                    $this->historico($id, 'ativo', '1', '0');
                    $this->inativados++;
                }
            }

            // ---- Totais ----
            // marca a última coleta em que cada item foi visto
            if ($vistos) {
                $ids = implode(',', array_map('intval', array_keys($vistos)));
                $this->pdo->exec("UPDATE iconf_item SET ultima_coleta_id = {$this->coletaId} WHERE id IN ($ids)");
            }
            $this->pdo->prepare(
                'UPDATE coleta SET itens_criados=?, itens_alterados=?, itens_inativados=?, itens_lidos=? WHERE id=?'
            )->execute([$this->criados, $this->alterados, $this->inativados, $this->lidos, $this->coletaId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'coleta_id'  => $this->coletaId,
            'criados'    => $this->criados,
            'alterados'  => $this->alterados,
            'inativados' => $this->inativados,
            'lidos'      => $this->lidos,
        ];
    }

    /**
     * Item CLUSTER único. Sem nome conhecido (API não informou) mantém o nome atual;
     * com nome conhecido e diferente, renomeia o item existente (ICONF preservado, histórico registrado).
     */
    private function garantirCluster(string $nomeConhecido, string $fallback): int
    {
        $item = $this->pdo->query("SELECT * FROM iconf_item WHERE tipo = 'CLUSTER' ORDER BY id LIMIT 1")->fetch();
        if (!$item) {
            $nome = $nomeConhecido !== '' ? $nomeConhecido : $fallback;
            return $this->garantirItem('CLUSTER', $nome, $nome, null);
        }

        $id = (int)$item['id'];
        $mudou = false;
        if ($nomeConhecido !== '' && $item['nome'] !== $nomeConhecido) {
            $this->pdo->prepare('UPDATE iconf_item SET nome = ?, chave_origem = ? WHERE id = ?')
                ->execute([$nomeConhecido, $nomeConhecido, $id]);
            $this->historico($id, 'nome', $item['nome'], $nomeConhecido);
            $mudou = true;
        }
        if ((int)$item['ativo'] === 0) {
            $this->pdo->prepare('UPDATE iconf_item SET ativo = 1 WHERE id = ?')->execute([$id]);
            $this->historico($id, 'ativo', '0', '1');
            $mudou = true;
        }
        if ($mudou) {
            $this->alterados++;
        }
        return $id;
    }

    /** Localiza o item; cria (com novo ICONF) se não existir. Atualiza nome/pai/ativo se mudaram. */
    private function garantirItem(string $tipo, string $chave, string $nome, ?int $paiId): int
    {
        if ($tipo === 'VM' || $tipo === 'CT') {
            $st = $this->pdo->prepare("SELECT * FROM iconf_item WHERE tipo IN ('VM','CT') AND chave_origem = ? LIMIT 1");
            $st->execute([$chave]);
        } elseif ($tipo === 'NODE') {
            $st = $this->pdo->prepare("SELECT * FROM iconf_item WHERE tipo = 'NODE' AND chave_origem = ? AND pai_id <=> ? LIMIT 1");
            $st->execute([$chave, $paiId]);
        } else {
            $st = $this->pdo->prepare("SELECT * FROM iconf_item WHERE tipo = 'CLUSTER' AND chave_origem = ? LIMIT 1");
            $st->execute([$chave]);
        }
        $item = $st->fetch();

        if (!$item) {
            $this->pdo->prepare(
                'INSERT INTO iconf_item (tipo, chave_origem, nome, pai_id, ativo, ultima_coleta_id) VALUES (?,?,?,?,1,?)'
            )->execute([$tipo, $chave, $nome, $paiId, $this->coletaId]);
            $id = (int)$this->pdo->lastInsertId();
            $iconf = sprintf('ICONF-%06d', $id);
            $this->pdo->prepare('UPDATE iconf_item SET iconf = ? WHERE id = ?')->execute([$iconf, $id]);
            $this->historico($id, 'item', null, "criado ($tipo) $iconf");
            $this->criados++;
            return $id;
        }

        $id = (int)$item['id'];
        $mudou = false;

        if ($item['nome'] !== $nome) {
            $this->pdo->prepare('UPDATE iconf_item SET nome = ? WHERE id = ?')->execute([$nome, $id]);
            $this->historico($id, 'nome', $item['nome'], $nome);
            $mudou = true;
        }
        if (($tipo === 'VM' || $tipo === 'CT') && (int)$item['pai_id'] !== (int)$paiId) {
            // migração entre nodes: o histórico do campo "node" é gravado em gravarAtributos
            $this->pdo->prepare('UPDATE iconf_item SET pai_id = ? WHERE id = ?')->execute([$paiId, $id]);
            $mudou = true;
        }
        if ((int)$item['ativo'] === 0) {
            $this->pdo->prepare('UPDATE iconf_item SET ativo = 1 WHERE id = ?')->execute([$id]);
            $this->historico($id, 'ativo', '0', '1');
            $mudou = true;
        }
        if ($mudou) {
            $this->alterados++;
        }
        return $id;
    }

    /**
     * Grava atributos atuais; só os campos em $historizar geram linhas de histórico.
     * @param array<string,?string> $novos
     * @param string[] $historizar
     */
    private function gravarAtributos(int $itemId, array $novos, array $historizar): void
    {
        $st = $this->pdo->prepare('SELECT campo, valor FROM iconf_atributo WHERE item_id = ?');
        $st->execute([$itemId]);
        $atuais = [];
        foreach ($st->fetchAll() as $r) {
            $atuais[$r['campo']] = $r['valor'];
        }

        $mudouAlgo = false;
        foreach ($novos as $campo => $valor) {
            $existe = array_key_exists($campo, $atuais);
            if (!$existe) {
                $this->pdo->prepare('INSERT INTO iconf_atributo (item_id, campo, valor) VALUES (?,?,?)')
                    ->execute([$itemId, $campo, $valor]);
                if (in_array($campo, $historizar, true) && $valor !== null) {
                    $this->historico($itemId, $campo, null, $valor);
                }
                continue;
            }
            if ($atuais[$campo] === $valor) {
                continue;
            }
            $this->pdo->prepare('UPDATE iconf_atributo SET valor = ? WHERE item_id = ? AND campo = ?')
                ->execute([$valor, $itemId, $campo]);
            if (in_array($campo, $historizar, true)) {
                $this->historico($itemId, $campo, $atuais[$campo], $valor);
                $mudouAlgo = true;
            }
        }
        if ($mudouAlgo) {
            $this->alterados++;
        }
    }

    private function historico(int $itemId, string $campo, ?string $ant, ?string $novo): void
    {
        $this->pdo->prepare(
            'INSERT INTO iconf_historico (item_id, coleta_id, campo, valor_anterior, valor_novo) VALUES (?,?,?,?,?)'
        )->execute([$itemId, $this->coletaId, $campo, $ant, $novo]);
    }
}
