<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/protecao.php';
$usuario = exigir_nivel('funcionario');
$pdo = db();

$st = $pdo->prepare('SELECT codigo, cargo, admissao FROM colaboradores WHERE usuario_id = ?');
$st->execute([$usuario['id']]);
$colab = $st->fetch();

$ultimos = [];
if ($colab) {
    $st = $pdo->prepare('SELECT id, competencia, liquido FROM holerites WHERE colaborador_codigo = ? ORDER BY competencia DESC LIMIT 3');
    $st->execute([$colab['codigo']]);
    $ultimos = $st->fetchAll();
}

$titulo = 'Início';
$menu = 'inicio';
require __DIR__ . '/../includes/topo.php';
?>
<header class="pagina-cabecalho">
    <p class="sobretitulo">Área do funcionário</p>
    <h1>Olá, <?= e(explode(' ', $usuario['nome'])[0]) ?></h1>
    <p class="lead">Seus dados e holerites em um só lugar.</p>
</header>

<nav class="nav-ancoras" aria-label="Nesta página">
    <a href="#resumo">Resumo</a>
    <a href="#recentes">Holerites recentes</a>
</nav>

<?php if (!$colab): ?>
    <div class="alerta alerta-aviso">Seu usuário ainda não possui cadastro de colaborador. Fale com o RH.</div>
<?php else: ?>
<section id="resumo" class="secao">
    <h2>Resumo</h2>
    <div class="grade grade-3">
        <article class="card indicador"><p class="indicador-rotulo">Código</p><p class="indicador-valor"><?= (int) $colab['codigo'] ?></p></article>
        <article class="card indicador"><p class="indicador-rotulo">Cargo</p><p class="indicador-texto"><?= e($colab['cargo']) ?></p></article>
        <article class="card indicador"><p class="indicador-rotulo">Admissão</p><p class="indicador-texto"><?= e(data_br($colab['admissao'])) ?></p></article>
    </div>
    <p><a href="<?= e(url('funcionario/perfil.php#dados')) ?>">Ver e atualizar meus dados →</a></p>
</section>

<section id="recentes" class="secao">
    <h2>Holerites recentes</h2>
    <?php if (!$ultimos): ?>
        <div class="card vazio"><p>Nenhum holerite disponível ainda.</p></div>
    <?php else: ?>
        <div class="card tabela-caixa">
            <table class="tabela">
                <thead><tr><th>Competência</th><th class="num">Líquido</th><th class="acoes-col">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($ultimos as $h): ?>
                    <tr>
                        <td data-rotulo="Competência"><?= e(competencia_br($h['competencia'])) ?></td>
                        <td class="num" data-rotulo="Líquido"><strong><?= e(moeda($h['liquido'])) ?></strong></td>
                        <td class="acoes"><a class="btn btn-secundario btn-p" href="<?= e(url('holerite-pdf.php')) ?>?id=<?= (int) $h['id'] ?>" target="_blank" rel="noopener">PDF</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p><a href="<?= e(url('funcionario/holerites.php#lista')) ?>">Ver todos os holerites →</a></p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/rodape.php'; ?>
