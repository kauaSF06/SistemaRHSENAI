<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/protecao.php';
$gerente = exigir_nivel('gerente');
$pdo = db();

/* Ações (POST): desativar / reativar */
if (metodo_post()) {
    csrf_validar();
    $acao = (string) ($_POST['acao'] ?? '');
    $alvo = (int) ($_POST['usuario_id'] ?? 0);

    $st = $pdo->prepare('SELECT id, nome FROM usuarios WHERE id = ?');
    $st->execute([$alvo]);
    $u = $st->fetch();

    if (!$u) {
        flash('erro', 'Colaborador não encontrado.');
    } elseif ($acao === 'desativar') {
        if ($alvo === (int) $gerente['id']) {
            flash('erro', 'Você não pode desativar o seu próprio acesso.');
        } else {
            $pdo->prepare('UPDATE usuarios SET ativo = 0 WHERE id = ?')->execute([$alvo]);
            flash('ok', $u['nome'] . ' foi desativado(a). O acesso foi bloqueado e o histórico foi mantido.');
        }
    } elseif ($acao === 'reativar') {
        $pdo->prepare('UPDATE usuarios SET ativo = 1 WHERE id = ?')->execute([$alvo]);
        flash('ok', $u['nome'] . ' foi reativado(a).');
    }
    redirecionar('gerente/colaboradores.php#lista');
}

/* Filtros e paginação (GET) */
$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'ativos');
if (!in_array($status, ['ativos', 'inativos', 'todos'], true)) {
    $status = 'ativos';
}
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 15;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.nome LIKE ? OR u.email LIKE ? OR c.cargo LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($status === 'ativos') {
    $where[] = 'u.ativo = 1';
} elseif ($status === 'inativos') {
    $where[] = 'u.ativo = 0';
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM colaboradores c JOIN usuarios u ON u.id = c.usuario_id $sqlWhere");
$st->execute($params);
$total = (int) $st->fetchColumn();
$paginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $porPagina;

$st = $pdo->prepare(
    "SELECT c.codigo, c.cargo, c.admissao, u.id AS usuario_id, u.nome, u.email, u.nivel, u.ativo
       FROM colaboradores c JOIN usuarios u ON u.id = c.usuario_id
       $sqlWhere ORDER BY u.nome LIMIT $porPagina OFFSET $offset"
);
$st->execute($params);
$linhas = $st->fetchAll();

function link_pagina(int $p, string $q, string $status): string
{
    return url('gerente/colaboradores.php') . '?' . http_build_query(['q' => $q, 'status' => $status, 'p' => $p]) . '#lista';
}

$titulo = 'Colaboradores';
$menu = 'colaboradores';
require __DIR__ . '/../includes/topo.php';
?>
<header class="pagina-cabecalho">
    <p class="sobretitulo">Gerência</p>
    <h1>Colaboradores</h1>
    <p class="lead">Consulte, edite e desative cadastros.</p>
</header>

<nav class="nav-ancoras" aria-label="Nesta página">
    <a href="#filtros">Filtros</a>
    <a href="#lista">Lista</a>
</nav>

<section id="filtros" class="secao">
    <form method="get" action="<?= e(url('gerente/colaboradores.php')) ?>#lista" class="card filtros">
        <div class="campo">
            <label for="q">Buscar</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Nome, e-mail ou cargo">
        </div>
        <div class="campo">
            <label for="status">Situação</label>
            <select id="status" name="status">
                <option value="ativos"<?= $status === 'ativos' ? ' selected' : '' ?>>Ativos</option>
                <option value="inativos"<?= $status === 'inativos' ? ' selected' : '' ?>>Inativos</option>
                <option value="todos"<?= $status === 'todos' ? ' selected' : '' ?>>Todos</option>
            </select>
        </div>
        <div class="campo campo-acao">
            <button type="submit" class="btn btn-secundario">Filtrar</button>
        </div>
    </form>
</section>

<section id="lista" class="secao">
    <div class="secao-topo">
        <h2><?= $total ?> colaborador<?= $total === 1 ? '' : 'es' ?></h2>
        <a class="btn btn-primario" href="<?= e(url('gerente/colaborador-form.php')) ?>#dados">Novo colaborador</a>
    </div>

    <?php if (!$linhas): ?>
        <div class="card vazio"><p>Nenhum colaborador encontrado com esses filtros.</p></div>
    <?php else: ?>
    <div class="card tabela-caixa">
        <table class="tabela">
            <thead>
                <tr><th>Cód.</th><th>Nome</th><th>Cargo</th><th>Admissão</th><th>Situação</th><th class="acoes-col">Ações</th></tr>
            </thead>
            <tbody>
            <?php foreach ($linhas as $l): ?>
                <tr>
                    <td data-rotulo="Cód."><?= (int) $l['codigo'] ?></td>
                    <td data-rotulo="Nome">
                        <strong><?= e($l['nome']) ?></strong>
                        <span class="secundario"><?= e($l['email']) ?> · <?= e(rotulo_nivel($l['nivel'])) ?></span>
                    </td>
                    <td data-rotulo="Cargo"><?= e($l['cargo']) ?></td>
                    <td data-rotulo="Admissão"><?= e(data_br($l['admissao'])) ?></td>
                    <td data-rotulo="Situação">
                        <span class="selo <?= $l['ativo'] ? 'selo-ok' : 'selo-neutro' ?>"><?= $l['ativo'] ? 'Ativo' : 'Inativo' ?></span>
                    </td>
                    <td class="acoes">
                        <a class="btn btn-fantasma btn-p" href="<?= e(url('gerente/colaborador-form.php')) ?>?codigo=<?= (int) $l['codigo'] ?>#dados">Editar</a>
                        <?php if ((int) $l['usuario_id'] !== (int) $gerente['id']): ?>
                        <form method="post" action="<?= e(url('gerente/colaboradores.php')) ?>#lista">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="usuario_id" value="<?= (int) $l['usuario_id'] ?>">
                            <?php if ($l['ativo']): ?>
                                <input type="hidden" name="acao" value="desativar">
                                <button type="submit" class="btn btn-perigo-suave btn-p">Desativar</button>
                            <?php else: ?>
                                <input type="hidden" name="acao" value="reativar">
                                <button type="submit" class="btn btn-secundario btn-p">Reativar</button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($paginas > 1): ?>
    <nav class="paginacao" aria-label="Paginação">
        <?php if ($pagina > 1): ?><a href="<?= e(link_pagina($pagina - 1, $q, $status)) ?>">← Anterior</a><?php endif; ?>
        <span>Página <?= $pagina ?> de <?= $paginas ?></span>
        <?php if ($pagina < $paginas): ?><a href="<?= e(link_pagina($pagina + 1, $q, $status)) ?>">Próxima →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/rodape.php'; ?>
