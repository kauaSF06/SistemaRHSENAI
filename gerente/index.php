<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/protecao.php';
$gerente = exigir_nivel('gerente');
$pdo = db();

$ativos = (int) $pdo->query('SELECT COUNT(*) FROM colaboradores c JOIN usuarios u ON u.id = c.usuario_id WHERE u.ativo = 1')->fetchColumn();
$st = $pdo->prepare('SELECT COUNT(*) FROM holerites WHERE competencia = ?');
$st->execute([date('Y-m-01')]);
$holeritesMes = (int) $st->fetchColumn();
$rescAbertas = (int) $pdo->query("SELECT COUNT(*) FROM rescisoes WHERE status <> 'finalizada'")->fetchColumn();

$titulo = 'Painel';
$menu = 'painel';
require __DIR__ . '/../includes/topo.php';
?>
<header class="pagina-cabecalho">
    <p class="sobretitulo">Gerência</p>
    <h1>Olá, <?= e(explode(' ', $gerente['nome'])[0]) ?></h1>
    <p class="lead">Um resumo rápido do que precisa da sua atenção.</p>
</header>

<nav class="nav-ancoras" aria-label="Nesta página">
    <a href="#resumo">Resumo</a>
    <a href="#atalhos">Atalhos</a>
    <a href="#validacao">Antes de usar em produção</a>
</nav>

<section id="resumo" class="secao">
    <h2>Resumo</h2>
    <div class="grade grade-3">
        <article class="card indicador">
            <p class="indicador-rotulo">Colaboradores ativos</p>
            <p class="indicador-valor"><?= $ativos ?></p>
            <a href="<?= e(url('gerente/colaboradores.php#lista')) ?>">Ver colaboradores</a>
        </article>
        <article class="card indicador">
            <p class="indicador-rotulo">Holerites em <?= e(date('m/Y')) ?></p>
            <p class="indicador-valor"><?= $holeritesMes ?></p>
            <a href="<?= e(url('gerente/holerites.php#lista')) ?>">Ver holerites</a>
        </article>
        <article class="card indicador">
            <p class="indicador-rotulo">Rescisões em andamento</p>
            <p class="indicador-valor"><?= $rescAbertas ?></p>
            <a href="<?= e(url('gerente/rescisao.php#lista')) ?>">Ver rescisões</a>
        </article>
    </div>
</section>

<section id="atalhos" class="secao">
    <h2>Atalhos</h2>
    <div class="grade grade-3">
        <a class="card card-link" href="<?= e(url('gerente/colaborador-form.php#dados')) ?>">
            <h3>Novo colaborador</h3><p>Cadastre dados pessoais, cargo e salário.</p>
        </a>
        <a class="card card-link" href="<?= e(url('gerente/holerites.php#novo')) ?>">
            <h3>Registrar holerite</h3><p>Lance a competência e gere o PDF.</p>
        </a>
        <a class="card card-link" href="<?= e(url('gerente/rescisao.php#nova')) ?>">
            <h3>Estimar rescisão</h3><p>Prepare uma estimativa para revisão.</p>
        </a>
    </div>
</section>

<section id="validacao" class="secao">
    <div class="alerta alerta-aviso">
        <strong>Antes de usar em produção:</strong> holerites são demonstrativos simplificados (sem INSS/IRRF/FGTS detalhados)
        e a rescisão é uma <em>estimativa</em>. Valide as regras com contabilidade/jurídico. Veja o README.
    </div>
</section>
<?php require __DIR__ . '/../includes/rodape.php'; ?>
