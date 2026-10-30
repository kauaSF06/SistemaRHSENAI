<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/protecao.php';
$usuario = exigir_nivel('funcionario');

$lista = [];
if ($usuario['codigo']) {
    // Filtra SEMPRE pelo código do usuário logado (vindo da sessão/BD), nunca por parâmetro da URL.
    $st = db()->prepare('SELECT id, competencia, salario_base, proventos, descontos, liquido FROM holerites WHERE colaborador_codigo = ? ORDER BY competencia DESC');
    $st->execute([$usuario['codigo']]);
    $lista = $st->fetchAll();
}

$titulo = 'Meus holerites';
$menu = 'holerites';
require __DIR__ . '/../includes/topo.php';
?>
<header class="pagina-cabecalho">
    <p class="sobretitulo">Área do funcionário</p>
    <h1>Meus holerites</h1>
    <p class="lead">Consulte e baixe seus demonstrativos.</p>
</header>

<nav class="nav-ancoras" aria-label="Nesta página"><a href="#lista">Demonstrativos</a></nav>

<section id="lista" class="secao">
    <?php if (!$lista): ?>
        <div class="card vazio"><p>Nenhum holerite disponível ainda.</p></div>
    <?php else: ?>
    <div class="card tabela-caixa">
        <table class="tabela">
            <thead><tr><th>Competência</th><th class="num">Base</th><th class="num">Proventos</th><th class="num">Descontos</th><th class="num">Líquido</th><th class="acoes-col">Ações</th></tr></thead>
            <tbody>
            <?php foreach ($lista as $h): ?>
                <tr>
                    <td data-rotulo="Competência"><?= e(competencia_br($h['competencia'])) ?></td>
                    <td class="num" data-rotulo="Base"><?= e(moeda($h['salario_base'])) ?></td>
                    <td class="num" data-rotulo="Proventos"><?= e(moeda($h['proventos'])) ?></td>
                    <td class="num" data-rotulo="Descontos"><?= e(moeda($h['descontos'])) ?></td>
                    <td class="num" data-rotulo="Líquido"><strong><?= e(moeda($h['liquido'])) ?></strong></td>
                    <td class="acoes"><a class="btn btn-secundario btn-p" href="<?= e(url('holerite-pdf.php')) ?>?id=<?= (int) $h['id'] ?>" target="_blank" rel="noopener">PDF</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/rodape.php'; ?>
