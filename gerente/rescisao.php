<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/protecao.php';
$gerente = exigir_nivel('gerente');
$pdo = db();

$erros = [];
$v = ['colaborador' => '', 'desligamento' => date('Y-m-d'), 'motivo' => 'sem_justa_causa'];

/* ---------- Ações (POST) ---------- */
if (metodo_post()) {
    csrf_validar();
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'criar') {
        $v['colaborador']  = (string) ($_POST['colaborador'] ?? '');
        $v['desligamento'] = trim((string) ($_POST['desligamento'] ?? ''));
        $v['motivo']       = (string) ($_POST['motivo'] ?? '');

        $st = $pdo->prepare(
            'SELECT c.codigo, c.salario, c.admissao FROM colaboradores c
               JOIN usuarios u ON u.id = c.usuario_id WHERE c.codigo = ? AND u.ativo = 1'
        );
        $st->execute([(int) $v['colaborador']]);
        $c = $st->fetch();

        if (!$c) {
            $erros['colaborador'] = 'Selecione um colaborador ativo.';
        }
        if (!data_valida($v['desligamento'])) {
            $erros['desligamento'] = 'Data inválida.';
        } elseif ($c && $v['desligamento'] < $c['admissao']) {
            $erros['desligamento'] = 'O desligamento não pode ser anterior à admissão (' . data_br($c['admissao']) . ').';
        }
        if (!array_key_exists($v['motivo'], motivos_rescisao())) {
            $erros['motivo'] = 'Motivo inválido.';
        }

        if (!$erros) {
            $r = calcular_rescisao((float) $c['salario'], $c['admissao'], $v['desligamento'], $v['motivo']);
            $pdo->prepare(
                'INSERT INTO rescisoes (colaborador_codigo, desligamento, motivo, saldo_salario, decimo_terceiro, ferias_proporcionais, terco_ferias, total_estimado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$c['codigo'], $v['desligamento'], $v['motivo'], $r['saldo_salario'], $r['decimo_terceiro'], $r['ferias_proporcionais'], $r['terco_ferias'], $r['total_estimado']]);
            $novo = (int) $pdo->lastInsertId();
            flash('ok', sprintf('Estimativa criada como rascunho (%d dias de saldo, %d/12 de 13º, %d/12 de férias).', $r['dias_saldo'], $r['avos_13'], $r['avos_ferias']));
            redirecionar('gerente/rescisao.php?ver=' . $novo . '#detalhe');
        }
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM rescisoes WHERE id = ?');
        $st->execute([$id]);
        $res = $st->fetch();

        if (!$res) {
            flash('erro', 'Rescisão não encontrada.');
        } elseif ($res['status'] === 'finalizada') {
            flash('erro', 'Rescisões finalizadas não podem ser alteradas.');
        } elseif ($acao === 'status') {
            $novoStatus = (string) ($_POST['novo_status'] ?? '');
            $permitidos = ['rascunho' => ['revisao'], 'revisao' => ['rascunho', 'finalizada']];
            if (in_array($novoStatus, $permitidos[$res['status']] ?? [], true)) {
                $pdo->prepare('UPDATE rescisoes SET status = ? WHERE id = ? AND status = ?')->execute([$novoStatus, $id, $res['status']]);
                flash('ok', 'Status alterado para “' . rotulo_status_rescisao($novoStatus) . '”.');
            } else {
                flash('erro', 'Mudança de status não permitida.');
            }
        } elseif ($acao === 'recalcular' && $res['status'] === 'rascunho') {
            $st = $pdo->prepare('SELECT salario, admissao FROM colaboradores WHERE codigo = ?');
            $st->execute([$res['colaborador_codigo']]);
            $c = $st->fetch();
            $r = calcular_rescisao((float) $c['salario'], $c['admissao'], $res['desligamento'], $res['motivo']);
            $pdo->prepare('UPDATE rescisoes SET saldo_salario=?, decimo_terceiro=?, ferias_proporcionais=?, terco_ferias=?, total_estimado=? WHERE id=?')
                ->execute([$r['saldo_salario'], $r['decimo_terceiro'], $r['ferias_proporcionais'], $r['terco_ferias'], $r['total_estimado'], $id]);
            flash('ok', 'Estimativa recalculada com o salário atual.');
        } elseif ($acao === 'excluir' && $res['status'] === 'rascunho') {
            $pdo->prepare("DELETE FROM rescisoes WHERE id = ? AND status = 'rascunho'")->execute([$id]);
            flash('ok', 'Rascunho excluído.');
            redirecionar('gerente/rescisao.php#lista');
        } else {
            flash('erro', 'Ação não permitida neste status.');
        }
        redirecionar('gerente/rescisao.php?ver=' . $id . '#detalhe');
    }
}

/* ---------- Dados da página ---------- */
$ativos = $pdo->query(
    'SELECT c.codigo, u.nome, c.salario FROM colaboradores c JOIN usuarios u ON u.id = c.usuario_id WHERE u.ativo = 1 ORDER BY u.nome'
)->fetchAll();

$ver = (int) ($_GET['ver'] ?? 0);
$detalhe = null;
if ($ver > 0) {
    $st = $pdo->prepare(
        'SELECT r.*, u.nome, c.cargo, c.admissao, c.salario
           FROM rescisoes r JOIN colaboradores c ON c.codigo = r.colaborador_codigo JOIN usuarios u ON u.id = c.usuario_id
          WHERE r.id = ?'
    );
    $st->execute([$ver]);
    $detalhe = $st->fetch() ?: null;
}

$lista = $pdo->query(
    'SELECT r.id, r.desligamento, r.motivo, r.total_estimado, r.status, u.nome
       FROM rescisoes r JOIN colaboradores c ON c.codigo = r.colaborador_codigo JOIN usuarios u ON u.id = c.usuario_id
      ORDER BY r.criado_em DESC, r.id DESC LIMIT 100'
)->fetchAll();

function selo_status(string $s): string
{
    $classe = ['rascunho' => 'selo-neutro', 'revisao' => 'selo-aviso', 'finalizada' => 'selo-ok'][$s] ?? 'selo-neutro';
    return '<span class="selo ' . $classe . '">' . e(rotulo_status_rescisao($s)) . '</span>';
}

$titulo = 'Rescisões';
$menu = 'rescisao';
require __DIR__ . '/../includes/topo.php';
?>
<header class="pagina-cabecalho">
    <p class="sobretitulo">Gerência</p>
    <h1>Estimativas de rescisão</h1>
    <p class="lead">Prepare, revise e finalize estimativas de verbas rescisórias.</p>
</header>

<div class="alerta alerta-aviso" id="aviso">
    <strong>Estimativa, não é o TRCT.</strong> O cálculo cobre apenas saldo de salário, 13º proporcional, férias proporcionais e 1/3.
    <strong>Não inclui</strong> aviso prévio, multa de 40% do FGTS, férias vencidas, médias de variáveis, INSS/IRRF, faltas,
    adiantamentos, pensão nem regras de convenção coletiva. Valide com a contabilidade antes de qualquer pagamento.
</div>

<nav class="nav-ancoras" aria-label="Nesta página">
    <a href="#nova">Nova estimativa</a>
    <?php if ($detalhe): ?><a href="#detalhe">Detalhe</a><?php endif; ?>
    <a href="#lista">Histórico</a>
</nav>

<section id="nova" class="card secao-form">
    <h2>Nova estimativa</h2>
    <?php if (!$ativos): ?>
        <p class="ajuda">Não há colaboradores ativos.</p>
    <?php else: ?>
    <form method="post" action="<?= e(url('gerente/rescisao.php')) ?>#nova" novalidate>
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="criar">
        <div class="grade-form">
            <div class="campo campo-largo">
                <label for="colaborador">Colaborador</label>
                <select id="colaborador" name="colaborador" required>
                    <option value="">Selecione…</option>
                    <?php foreach ($ativos as $a): ?>
                        <option value="<?= (int) $a['codigo'] ?>"<?= (string) $a['codigo'] === $v['colaborador'] ? ' selected' : '' ?>>
                            <?= e($a['nome']) ?> (cód. <?= (int) $a['codigo'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= erro_campo($erros, 'colaborador') ?>
            </div>
            <div class="campo">
                <label for="desligamento">Data de desligamento</label>
                <input type="date" id="desligamento" name="desligamento" value="<?= e($v['desligamento']) ?>" required>
                <?= erro_campo($erros, 'desligamento') ?>
            </div>
            <div class="campo">
                <label for="motivo">Motivo</label>
                <select id="motivo" name="motivo">
                    <?php foreach (motivos_rescisao() as $cod => $rot): ?>
                        <option value="<?= e($cod) ?>"<?= $cod === $v['motivo'] ? ' selected' : '' ?>><?= e($rot) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= erro_campo($erros, 'motivo') ?>
            </div>
        </div>
        <div class="barra-acoes"><button type="submit" class="btn btn-primario">Calcular estimativa</button></div>
    </form>
    <?php endif; ?>
</section>

<?php if ($ver > 0 && !$detalhe): ?>
    <div class="alerta alerta-erro">Rescisão não encontrada.</div>
<?php endif; ?>

<?php if ($detalhe): $d = $detalhe; ?>
<section id="detalhe" class="card secao-form">
    <div class="secao-topo">
        <div>
            <h2><?= e($d['nome']) ?> <span class="secundario">· <?= e($d['cargo']) ?></span></h2>
            <p class="ajuda">Admissão <?= e(data_br($d['admissao'])) ?> · Desligamento <?= e(data_br($d['desligamento'])) ?> · <?= e(rotulo_motivo($d['motivo'])) ?></p>
        </div>
        <?= selo_status($d['status']) ?>
    </div>

    <table class="tabela tabela-resumo">
        <tbody>
            <tr><td>Saldo de salário</td><td class="num"><?= e(moeda($d['saldo_salario'])) ?></td></tr>
            <tr><td>13º salário proporcional</td><td class="num"><?= e(moeda($d['decimo_terceiro'])) ?></td></tr>
            <tr><td>Férias proporcionais</td><td class="num"><?= e(moeda($d['ferias_proporcionais'])) ?></td></tr>
            <tr><td>1/3 constitucional de férias</td><td class="num"><?= e(moeda($d['terco_ferias'])) ?></td></tr>
            <tr class="total"><td>Total estimado (bruto)</td><td class="num"><?= e(moeda($d['total_estimado'])) ?></td></tr>
        </tbody>
    </table>
    <p class="ajuda">Salário-base atual do cadastro: <?= e(moeda($d['salario'])) ?>. Valores brutos, antes de descontos legais.</p>

    <?php if ($d['status'] !== 'finalizada'): ?>
    <div class="barra-acoes">
        <?php if ($d['status'] === 'rascunho'): ?>
            <form method="post" action="<?= e(url('gerente/rescisao.php')) ?>#detalhe">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="acao" value="recalcular">
                <button class="btn btn-secundario" type="submit">Recalcular</button>
            </form>
            <form method="post" action="<?= e(url('gerente/rescisao.php')) ?>#detalhe">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="acao" value="status"><input type="hidden" name="novo_status" value="revisao">
                <button class="btn btn-primario" type="submit">Enviar para revisão</button>
            </form>
        <?php else: ?>
            <form method="post" action="<?= e(url('gerente/rescisao.php')) ?>#detalhe">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="acao" value="status"><input type="hidden" name="novo_status" value="rascunho">
                <button class="btn btn-secundario" type="submit">Voltar a rascunho</button>
            </form>
            <form method="post" action="<?= e(url('gerente/rescisao.php')) ?>#detalhe">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="acao" value="status"><input type="hidden" name="novo_status" value="finalizada">
                <button class="btn btn-primario" type="submit">Finalizar estimativa</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($d['status'] === 'rascunho'): ?>
    <details class="confirmar">
        <summary>Excluir este rascunho…</summary>
        <form method="post" action="<?= e(url('gerente/rescisao.php')) ?>#lista">
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="acao" value="excluir">
            <p>Esta ação não pode ser desfeita.</p>
            <button class="btn btn-perigo" type="submit">Confirmar exclusão</button>
        </form>
    </details>
    <?php endif; ?>
    <?php else: ?>
        <p class="ajuda">Estimativa finalizada: bloqueada para edição. O desligamento do colaborador (desativar cadastro) continua sendo um passo manual em <a href="<?= e(url('gerente/colaboradores.php#lista')) ?>">Colaboradores</a>.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section id="lista" class="secao">
    <div class="secao-topo"><h2>Histórico</h2></div>
    <?php if (!$lista): ?>
        <div class="card vazio"><p>Nenhuma estimativa criada ainda.</p></div>
    <?php else: ?>
    <div class="card tabela-caixa">
        <table class="tabela">
            <thead><tr><th>Colaborador</th><th>Desligamento</th><th>Motivo</th><th class="num">Total</th><th>Status</th><th class="acoes-col">Ações</th></tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
                <tr>
                    <td data-rotulo="Colaborador"><?= e($r['nome']) ?></td>
                    <td data-rotulo="Desligamento"><?= e(data_br($r['desligamento'])) ?></td>
                    <td data-rotulo="Motivo"><?= e(rotulo_motivo($r['motivo'])) ?></td>
                    <td class="num" data-rotulo="Total"><?= e(moeda($r['total_estimado'])) ?></td>
                    <td data-rotulo="Status"><?= selo_status($r['status']) ?></td>
                    <td class="acoes"><a class="btn btn-fantasma btn-p" href="<?= e(url('gerente/rescisao.php')) ?>?ver=<?= (int) $r['id'] ?>#detalhe">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/rodape.php'; ?>
