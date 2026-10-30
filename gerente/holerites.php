<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/protecao.php';
$gerente = exigir_nivel('gerente');
$pdo = db();

$ativos = $pdo->query(
    'SELECT c.codigo, u.nome, c.salario
       FROM colaboradores c JOIN usuarios u ON u.id = c.usuario_id
      WHERE u.ativo = 1 ORDER BY u.nome'
)->fetchAll();

/* Holerite em edição (GET ?editar=ID) */
$editarId = (int) ($_GET['editar'] ?? $_POST['id'] ?? 0);
$edit = null;
if ($editarId > 0) {
    $st = $pdo->prepare(
        'SELECT h.*, u.nome FROM holerites h
           JOIN colaboradores c ON c.codigo = h.colaborador_codigo
           JOIN usuarios u ON u.id = c.usuario_id WHERE h.id = ?'
    );
    $st->execute([$editarId]);
    $edit = $st->fetch() ?: null;
    if (!$edit) {
        flash('erro', 'Holerite não encontrado.');
        redirecionar('gerente/holerites.php#lista');
    }
}

$v = [
    'colaborador'  => '',
    'competencia'  => date('Y-m'),
    'salario_base' => '',
    'proventos'    => '',
    'descontos'    => '',
];
if ($edit) {
    $v = [
        'colaborador'  => (string) $edit['colaborador_codigo'],
        'competencia'  => substr($edit['competencia'], 0, 7),
        'salario_base' => numero_br($edit['salario_base']),
        'proventos'    => numero_br($edit['proventos']),
        'descontos'    => numero_br($edit['descontos']),
    ];
}
$erros = [];

if (metodo_post()) {
    csrf_validar();
    foreach ($v as $campo => $_) {
        if ($edit && in_array($campo, ['colaborador', 'competencia'], true)) {
            continue; // não se troca colaborador/competência de um holerite existente
        }
        $v[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }

    $codigo = (int) $v['colaborador'];
    $colab = null;
    if (!$edit) {
        foreach ($ativos as $a) {
            if ((int) $a['codigo'] === $codigo) {
                $colab = $a;
            }
        }
        if (!$colab) {
            $erros['colaborador'] = 'Selecione um colaborador ativo.';
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $v['competencia']) || !data_valida($v['competencia'] . '-01')) {
            $erros['competencia'] = 'Competência inválida.';
        }
    }

    // Em branco no salário base = usa o salário cadastrado (lido do banco, nunca do formulário).
    if ($v['salario_base'] === '' && $colab) {
        $base = (float) $colab['salario'];
    } else {
        $base = decimal_br($v['salario_base']);
        if ($base === null) {
            $erros['salario_base'] = 'Informe um valor válido.';
        }
    }
    $prov = $v['proventos'] === '' ? 0.0 : decimal_br($v['proventos']);
    $desc = $v['descontos'] === '' ? 0.0 : decimal_br($v['descontos']);
    if ($prov === null) {
        $erros['proventos'] = 'Valor inválido.';
    }
    if ($desc === null) {
        $erros['descontos'] = 'Valor inválido.';
    }

    $liquido = null;
    if ($base !== null && $prov !== null && $desc !== null) {
        $liquido = round($base + $prov - $desc, 2);
        if ($liquido < 0) {
            $erros['descontos'] = 'Os descontos não podem superar o total de proventos.';
        }
    }

    if (!$erros) {
        try {
            if ($edit) {
                $pdo->prepare('UPDATE holerites SET salario_base=?, proventos=?, descontos=?, liquido=? WHERE id=?')
                    ->execute([$base, $prov, $desc, $liquido, $editarId]);
                flash('ok', 'Holerite atualizado.');
            } else {
                $pdo->prepare('INSERT INTO holerites (colaborador_codigo, competencia, salario_base, proventos, descontos, liquido) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$codigo, $v['competencia'] . '-01', $base, $prov, $desc, $liquido]);
                flash('ok', 'Holerite registrado. Líquido: ' . moeda($liquido) . '.');
            }
            redirecionar('gerente/holerites.php#lista');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $erros['competencia'] = 'Já existe holerite deste colaborador nesta competência. Edite o existente.';
            } else {
                error_log('[RH] holerites: ' . $ex->getMessage());
                $erros['geral'] = 'Não foi possível salvar.';
            }
        }
    }
}

/* Lista com filtros */
$fCol = (int) ($_GET['f_colaborador'] ?? 0);
$fCom = (string) ($_GET['f_competencia'] ?? '');
$where = [];
$params = [];
if ($fCol > 0) {
    $where[] = 'h.colaborador_codigo = ?';
    $params[] = $fCol;
}
if (preg_match('/^\d{4}-\d{2}$/', $fCom) && data_valida($fCom . '-01')) {
    $where[] = 'h.competencia = ?';
    $params[] = $fCom . '-01';
} else {
    $fCom = '';
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$st = $pdo->prepare(
    "SELECT h.id, h.competencia, h.salario_base, h.proventos, h.descontos, h.liquido, c.codigo, u.nome
       FROM holerites h
       JOIN colaboradores c ON c.codigo = h.colaborador_codigo
       JOIN usuarios u ON u.id = c.usuario_id
       $sqlWhere ORDER BY h.competencia DESC, u.nome LIMIT 100"
);
$st->execute($params);
$lista = $st->fetchAll();
$todosColab = $pdo->query('SELECT c.codigo, u.nome FROM colaboradores c JOIN usuarios u ON u.id = c.usuario_id ORDER BY u.nome')->fetchAll();

$titulo = 'Holerites';
$menu = 'holerites';
require __DIR__ . '/../includes/topo.php';
?>
<header class="pagina-cabecalho">
    <p class="sobretitulo">Gerência</p>
    <h1>Holerites</h1>
    <p class="lead">Registre os lançamentos mensais e gere o demonstrativo em PDF.</p>
</header>

<nav class="nav-ancoras" aria-label="Nesta página">
    <a href="#novo"><?= $edit ? 'Edição' : 'Novo holerite' ?></a>
    <a href="#lista">Lançamentos</a>
</nav>

<?php if (isset($erros['geral'])): ?>
    <div class="alerta alerta-erro" role="alert"><?= e($erros['geral']) ?></div>
<?php endif; ?>

<section id="novo" class="card secao-form">
    <h2><?= $edit ? 'Editar holerite de ' . e($edit['nome']) . ' · ' . e(competencia_br($edit['competencia'])) : 'Registrar holerite' ?></h2>
    <form method="post" action="<?= e(url('gerente/holerites.php')) ?>#novo" novalidate>
        <?= csrf_campo() ?>
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
        <div class="grade-form">
            <?php if (!$edit): ?>
            <div class="campo campo-largo">
                <label for="colaborador">Colaborador</label>
                <select id="colaborador" name="colaborador" required>
                    <option value="">Selecione…</option>
                    <?php foreach ($ativos as $a): ?>
                        <option value="<?= (int) $a['codigo'] ?>"<?= (string) $a['codigo'] === $v['colaborador'] ? ' selected' : '' ?>>
                            <?= e($a['nome']) ?> (cód. <?= (int) $a['codigo'] ?> · <?= e(moeda($a['salario'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= erro_campo($erros, 'colaborador') ?>
            </div>
            <div class="campo">
                <label for="competencia">Competência</label>
                <input type="month" id="competencia" name="competencia" value="<?= e($v['competencia']) ?>" required>
                <?= erro_campo($erros, 'competencia') ?>
            </div>
            <?php endif; ?>
            <div class="campo">
                <label for="salario_base">Salário base (R$)</label>
                <input type="text" id="salario_base" name="salario_base" value="<?= e($v['salario_base']) ?>" inputmode="decimal" placeholder="<?= $edit ? '' : 'Em branco = salário cadastrado' ?>">
                <?= erro_campo($erros, 'salario_base') ?>
            </div>
            <div class="campo">
                <label for="proventos">Proventos adicionais (R$)</label>
                <input type="text" id="proventos" name="proventos" value="<?= e($v['proventos']) ?>" inputmode="decimal" placeholder="0,00">
                <?= erro_campo($erros, 'proventos') ?>
            </div>
            <div class="campo">
                <label for="descontos">Descontos (R$)</label>
                <input type="text" id="descontos" name="descontos" value="<?= e($v['descontos']) ?>" inputmode="decimal" placeholder="0,00">
                <?= erro_campo($erros, 'descontos') ?>
            </div>
        </div>
        <p class="ajuda">Líquido = salário base + proventos − descontos (calculado no servidor). INSS, IRRF e FGTS devem ser informados já consolidados em “descontos”.</p>
        <div class="barra-acoes">
            <button type="submit" class="btn btn-primario"><?= $edit ? 'Salvar alterações' : 'Registrar holerite' ?></button>
            <?php if ($edit): ?><a class="btn btn-fantasma" href="<?= e(url('gerente/holerites.php#lista')) ?>">Cancelar</a><?php endif; ?>
        </div>
    </form>
</section>

<section id="lista" class="secao">
    <div class="secao-topo"><h2>Lançamentos</h2></div>

    <form method="get" action="<?= e(url('gerente/holerites.php')) ?>#lista" class="card filtros">
        <div class="campo">
            <label for="f_colaborador">Colaborador</label>
            <select id="f_colaborador" name="f_colaborador">
                <option value="0">Todos</option>
                <?php foreach ($todosColab as $c): ?>
                    <option value="<?= (int) $c['codigo'] ?>"<?= $fCol === (int) $c['codigo'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="f_competencia">Competência</label>
            <input type="month" id="f_competencia" name="f_competencia" value="<?= e($fCom) ?>">
        </div>
        <div class="campo campo-acao"><button type="submit" class="btn btn-secundario">Filtrar</button></div>
    </form>

    <?php if (!$lista): ?>
        <div class="card vazio"><p>Nenhum holerite encontrado.</p></div>
    <?php else: ?>
    <div class="card tabela-caixa">
        <table class="tabela">
            <thead><tr><th>Competência</th><th>Colaborador</th><th class="num">Base</th><th class="num">Proventos</th><th class="num">Descontos</th><th class="num">Líquido</th><th class="acoes-col">Ações</th></tr></thead>
            <tbody>
            <?php foreach ($lista as $h): ?>
                <tr>
                    <td data-rotulo="Competência"><?= e(competencia_br($h['competencia'])) ?></td>
                    <td data-rotulo="Colaborador"><?= e($h['nome']) ?> <span class="secundario">cód. <?= (int) $h['codigo'] ?></span></td>
                    <td class="num" data-rotulo="Base"><?= e(moeda($h['salario_base'])) ?></td>
                    <td class="num" data-rotulo="Proventos"><?= e(moeda($h['proventos'])) ?></td>
                    <td class="num" data-rotulo="Descontos"><?= e(moeda($h['descontos'])) ?></td>
                    <td class="num" data-rotulo="Líquido"><strong><?= e(moeda($h['liquido'])) ?></strong></td>
                    <td class="acoes">
                        <a class="btn btn-secundario btn-p" href="<?= e(url('holerite-pdf.php')) ?>?id=<?= (int) $h['id'] ?>" target="_blank" rel="noopener">PDF</a>
                        <a class="btn btn-fantasma btn-p" href="<?= e(url('gerente/holerites.php')) ?>?editar=<?= (int) $h['id'] ?>#novo">Editar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/rodape.php'; ?>
