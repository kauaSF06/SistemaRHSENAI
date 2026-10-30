<?php
declare(strict_types=1);
/**
 * Cabeçalho comum. Defina antes de incluir:
 *   $titulo        (string)  título da página
 *   $menu          (string)  chave do item ativo do menu
 *   $corpo_classe  (string)  opcional, classe extra no <body>
 */
$usuario = usuario_logado();
$titulo = $titulo ?? 'RH Simples';
$menu = $menu ?? '';
$corpo_classe = $corpo_classe ?? '';
$versao_css = (int) @filemtime(RAIZ . '/style.css');

$itens = [];
if ($usuario && $usuario['nivel'] === 'gerente') {
    $itens = [
        'painel'        => ['gerente/index.php', 'Painel'],
        'colaboradores' => ['gerente/colaboradores.php', 'Colaboradores'],
        'holerites'     => ['gerente/holerites.php', 'Holerites'],
        'rescisao'      => ['gerente/rescisao.php', 'Rescisões'],
    ];
} elseif ($usuario) {
    $itens = [
        'inicio'    => ['funcionario/index.php', 'Início'],
        'perfil'    => ['funcionario/perfil.php', 'Meu perfil'],
        'holerites' => ['funcionario/holerites.php', 'Meus holerites'],
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · RH Simples</title>
    <link rel="stylesheet" href="<?= e(url('style.css')) ?>?v=<?= $versao_css ?>">
</head>
<body class="<?= e($corpo_classe) ?>">
<a class="pular" href="#conteudo">Ir para o conteúdo</a>

<header class="topo">
    <div class="topo-interno">
        <a class="marca" href="<?= e(url($usuario ? pagina_inicial($usuario['nivel']) : 'login.php')) ?>">
            <span class="marca-ponto" aria-hidden="true"></span>RH Simples
        </a>

        <?php if ($usuario): ?>
        <nav class="menu" aria-label="Menu principal">
            <?php foreach ($itens as $chave => [$href, $rotulo]): ?>
                <a href="<?= e(url($href)) ?>"<?= $chave === $menu ? ' class="ativo" aria-current="page"' : '' ?>><?= e($rotulo) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="usuario">
            <span class="usuario-nome"><?= e($usuario['nome']) ?> · <?= e(rotulo_nivel($usuario['nivel'])) ?></span>
            <form method="post" action="<?= e(url('logout.php')) ?>">
                <?= csrf_campo() ?>
                <button type="submit" class="btn btn-fantasma btn-p">Sair</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</header>

<main id="conteudo" class="container">
<?php foreach (flash_obter() as $f): ?>
    <div class="alerta alerta-<?= e($f['tipo']) ?>" role="<?= $f['tipo'] === 'erro' ? 'alert' : 'status' ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
