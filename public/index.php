<?php
declare(strict_types=1);
require __DIR__ . '/../src/auth.php';

$user = exigir_login();
$pdo  = db();

// ---- Carrega itens e atributos (2 consultas) ----
$itens = [];
foreach ($pdo->query('SELECT id, iconf, tipo, chave_origem, nome, pai_id, ativo FROM iconf_item ORDER BY nome')->fetchAll() as $r) {
    $r['id'] = (int)$r['id'];
    $r['pai_id'] = $r['pai_id'] === null ? null : (int)$r['pai_id'];
    $r['attr'] = [];
    $itens[$r['id']] = $r;
}
foreach ($pdo->query('SELECT item_id, campo, valor FROM iconf_atributo')->fetchAll() as $a) {
    if (isset($itens[(int)$a['item_id']])) {
        $itens[(int)$a['item_id']]['attr'][$a['campo']] = $a['valor'];
    }
}

$clusters = $nodesPorCluster = $guestsPorNode = [];
foreach ($itens as $it) {
    if ($it['tipo'] === 'CLUSTER') {
        $clusters[] = $it;
    } elseif ($it['tipo'] === 'NODE') {
        $nodesPorCluster[$it['pai_id']][] = $it;
    } else {
        $guestsPorNode[$it['pai_id']][] = $it;
    }
}
// guests ordenados por ID numérico
foreach ($guestsPorNode as &$lista) {
    usort($lista, fn($a, $b) => (int)$a['chave_origem'] <=> (int)$b['chave_origem']);
}
unset($lista);

$ultima = $pdo->query('SELECT MAX(importada_em) FROM coleta')->fetchColumn();
$sincronizado = $ultima ? (new DateTime((string)$ultima))->format('d/m/Y, H:i:s') : 'nunca';
$titulo = cfg()['site_title'] ?? 'CMDB';
$ehAdmin = ($user['perfil'] ?? '') === 'ADMIN';

// Última tentativa de coleta falhou por ambiente inacessível? Mostra o aviso para todos.
$avisoInacessivel = null;
$arqStatus = (cfg()['data_dir'] ?? '') . '/status.json';
if (is_readable($arqStatus)) {
    $js = json_decode((string)file_get_contents($arqStatus), true);
    if (is_array($js) && ($js['estado'] ?? '') === 'inacessivel') {
        try {
            $quando = (new DateTime((string)($js['atualizado_em'] ?? 'now')))->format('d/m/Y H:i:s');
        } catch (Throwable) {
            $quando = '';
        }
        $avisoInacessivel = 'Ambiente Inacessível para coleta' . ($quando !== '' ? ' (tentativa em ' . $quando . ')' : '');
    }
}

function rede_html(?string $net): string
{
    if ($net === null || $net === '' || $net === 'none') {
        return '<span class="muted">-</span>';
    }
    $out = '';
    foreach (explode(';', $net) as $n) {
        [$nome, $st] = array_pad(explode(':', $n, 2), 2, '');
        $cls = $st === 'up' ? 'net-up' : ($st === 'down' ? 'net-down' : 'net-off');
        $out .= '<span class="net ' . $cls . '" title="' . h($st) . '">' . h($nome) . '</span> ';
    }
    return $out;
}

function status_html(?string $st, ?string $dias): string
{
    $st = $st ?? 'unknown';
    $cls = $st === 'running' ? 'st-on' : ($st === 'stopped' ? 'st-off' : 'st-unk');
    $extra = ($st === 'stopped' && $dias !== null && $dias !== '') ? ' <small>(' . h($dias) . ' d)</small>' : '';
    return '<span class="pill ' . $cls . '">' . h(strtoupper($st)) . $extra . '</span>';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<title><?= h($titulo) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand"><span class="logo">&#9638;</span> <?= h($titulo) ?></div>
  <div class="userbox">
    <div><strong><?= h($user['nome']) ?></strong><br><small class="muted"><?= h($user['perfil']) ?></small></div>
    <a class="btn btn-danger-outline" href="logout.php">Sair</a>
  </div>
</header>

<main class="container">
  <div class="toolbar">
    <?php if ($ehAdmin): ?>
      <button id="btnAtualizar" class="btn btn-primary" type="button">&#8635; Atualizar Dados</button>
    <?php else: ?>
      <span></span>
    <?php endif; ?>
    <div class="sync">Sincronizado: <span id="syncTxt"><?= h($sincronizado) ?></span></div>
  </div>
  <div id="msgColeta" class="alert<?= $avisoInacessivel ? '' : ' info' ?>"<?= $avisoInacessivel ? '' : ' hidden' ?>><?= h($avisoInacessivel ?? '') ?></div>

  <?php if (!$clusters): ?>
    <div class="card empty">Nenhum item cadastrado ainda. <?= $ehAdmin ? 'Clique em <strong>Atualizar Dados</strong> para fazer a primeira coleta.' : '' ?></div>
  <?php endif; ?>

  <?php foreach ($clusters as $cl): ?>
    <section class="cluster <?= $cl['ativo'] ? '' : 'inativo' ?>">
      <div class="cluster-head">
        <span class="tag tag-cluster">CLUSTER</span>
        <strong><?= h($cl['nome']) ?></strong>
        <span class="iconf"><?= h($cl['iconf']) ?></span>
        <a class="btn btn-sm" href="historico.php?id=<?= $cl['id'] ?>">Histórico</a>
      </div>

      <?php foreach (($nodesPorCluster[$cl['id']] ?? []) as $nd):
          $guests = $guestsPorNode[$nd['id']] ?? [];
          $ativos = array_filter($guests, fn($g) => $g['ativo']);
          $on = count(array_filter($ativos, fn($g) => ($g['attr']['status'] ?? '') === 'running'));
          $a = $nd['attr']; ?>
        <article class="card node <?= $nd['ativo'] ? '' : 'inativo' ?>">
          <div class="node-head">
            <div class="node-title">
              <span class="tag tag-node">NODE</span>
              <h2>Node: <?= h($nd['nome']) ?></h2>
              <?php if (($a['cpu_cores'] ?? '') !== ''): ?><span class="chip chip-blue"><?= h($a['cpu_cores']) ?> Cores</span><?php endif; ?>
              <?php if (($a['memory_total_gb'] ?? '') !== ''): ?><span class="chip chip-purple"><?= h($a['memory_total_gb']) ?> GB RAM</span><?php endif; ?>
              <span class="iconf"><?= h($nd['iconf']) ?></span>
              <?php if (!$nd['ativo']): ?><span class="chip chip-gray">INATIVO</span><?php endif; ?>
            </div>
            <div class="node-meta">
              <?php if (($a['pve_version'] ?? '') !== ''): ?><span class="chip chip-blue">PVE v<?= h($a['pve_version']) ?></span><?php endif; ?>
              <span class="chip chip-dark"><?= count($ativos) ?> Máquinas (<?= $on ?> ON)</span>
              <a class="btn btn-sm" href="historico.php?id=<?= $nd['id'] ?>">Histórico</a>
            </div>
          </div>

          <?php if (!empty($a['disk_wearout']) || !empty($a['storage_free'])): ?>
            <div class="node-extra">
              <?php if (!empty($a['disk_wearout'])): ?><span><strong>Desgaste dos discos:</strong> <?= h($a['disk_wearout']) ?></span><?php endif; ?>
              <?php if (!empty($a['storage_free'])): ?><span><strong>Storage livre:</strong> <?= h($a['storage_free']) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="table-wrap">
          <table>
            <thead>
              <tr><th>ID / NOME</th><th>ICONF</th><th>STATUS</th><th>REDE</th><th>RECURSOS</th><th>DISCO</th><th>SNAPSHOTS</th><th>HISTÓRICO</th></tr>
            </thead>
            <tbody>
            <?php if (!$guests): ?>
              <tr><td colspan="8" class="muted center">Nenhuma máquina encontrada neste node.</td></tr>
            <?php endif; ?>
            <?php foreach ($guests as $g): $ga = $g['attr']; ?>
              <tr class="<?= $g['ativo'] ? '' : 'inativo' ?>">
                <td>
                  <span class="vmid">#<?= h($g['chave_origem']) ?></span>
                  <strong><?= h($g['nome']) ?></strong>
                  <span class="tag tag-<?= strtolower($g['tipo']) ?>"><?= h($g['tipo']) ?></span>
                  <?php if (!$g['ativo']): ?><span class="chip chip-gray">INATIVO</span><?php endif; ?>
                </td>
                <td><span class="iconf"><?= h($g['iconf']) ?></span></td>
                <td><?= status_html($ga['status'] ?? null, $ga['days_stopped'] ?? null) ?></td>
                <td><?= rede_html($ga['network'] ?? null) ?></td>
                <td class="res">
                  <div><?= h($ga['vcpus'] ?? '-') ?> vCPU</div>
                  <div class="muted"><?= h($ga['memory_gb'] ?? '-') ?> GB RAM</div>
                </td>
                <td><?= h($ga['disk_gb'] ?? '-') ?> GB</td>
                <td class="muted">
                  <?php $sn = $ga['snapshots'] ?? null;
                  echo ($sn === null || $sn === '') ? '-' : ((int)$sn === 0 ? 'Sem snaps' : h($sn) . ' snap' . ((int)$sn > 1 ? 's' : '')); ?>
                </td>
                <td><a class="btn btn-sm" href="historico.php?id=<?= $g['id'] ?>">Histórico</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
</main>

<?php if ($ehAdmin): ?>
<script>
(function () {
  var btn = document.getElementById('btnAtualizar');
  var msg = document.getElementById('msgColeta');
  var csrf = document.querySelector('meta[name="csrf"]').content;
  var timer = null;
  var inicioColeta = 0;   // horário (do servidor) em que o clique iniciou a coleta
  var esperas = 0;        // leituras de status ainda anteriores ao clique

  function mostra(t, erro) {
    msg.hidden = false;
    msg.className = 'alert ' + (erro ? '' : 'info');
    msg.textContent = t;
  }

  function checa() {
    fetch('status.php', {cache: 'no-store'})
      .then(function (r) { return r.json(); })
      .then(function (s) {
        if (s.estado === 'executando') {
          btn.disabled = true;
          btn.textContent = 'Coletando...';
          mostra(s.mensagem || 'Coleta em andamento');
          return;
        }
        // status antigo (de uma coleta anterior) logo após o clique: continua esperando
        if (inicioColeta && s.atualizado_em && Date.parse(s.atualizado_em) < inicioColeta - 2000 && esperas++ < 10) {
          return;
        }
        inicioColeta = 0;
        clearInterval(timer); timer = null;
        btn.disabled = false;
        btn.innerHTML = '&#8635; Atualizar Dados';
        if (s.estado === 'ok') {
          mostra('Coleta concluída: ' + s.mensagem);
          if (sessionStorage.getItem('coletaPendente')) {
            sessionStorage.removeItem('coletaPendente');
            setTimeout(function () { location.reload(); }, 1200);
          }
        } else if (s.estado === 'inacessivel') {
          sessionStorage.removeItem('coletaPendente');
          mostra('Ambiente Inacessível para coleta', true);
        } else if (s.estado === 'erro') {
          sessionStorage.removeItem('coletaPendente');
          mostra(s.mensagem || 'Erro na coleta', true);
        }
      })
      .catch(function () {});
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    fetch('atualizar.php', {method: 'POST', headers: {'X-CSRF-Token': csrf}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { btn.disabled = false; mostra(j.mensagem, true); return; }
        try { sessionStorage.setItem('coletaPendente', '1'); } catch (e) {}
        mostra(j.mensagem);
        inicioColeta = j.iniciado_em ? Date.parse(j.iniciado_em) : 0;
        esperas = 0;
        if (!timer) { timer = setInterval(checa, 3000); }
        setTimeout(checa, 1000);
      })
      .catch(function () { btn.disabled = false; mostra('Falha ao iniciar a coleta', true); });
  });

  // se já existe coleta rodando ao abrir a página, passa a acompanhar
  fetch('status.php', {cache: 'no-store'}).then(function (r) { return r.json(); }).then(function (s) {
    if (s.estado === 'executando') { try { sessionStorage.setItem('coletaPendente', '1'); } catch (e) {} timer = setInterval(checa, 3000); checa(); }
  }).catch(function () {});
})();
</script>
<?php endif; ?>
</body>
</html>
