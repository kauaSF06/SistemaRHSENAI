<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/protecao.php';
$usuario = exigir_nivel('funcionario');
$pdo = db();

$st = $pdo->prepare(
    'SELECT c.codigo, c.nascimento, c.telefone, c.cargo, c.admissao, c.salario, u.nome, u.email
       FROM colaboradores c JOIN usuarios u ON u.id = c.usuario_id WHERE u.id = ?'
);
$st->execute([$usuario['id']]);
$p = $st->fetch();
if (!$p) {
    flash('aviso', 'Seu usuário ainda não possui cadastro de colaborador. Fale com o RH.');
    redirecionar('funcionario/index.php');
}

$erros = [];
$v = ['nome' => $p['nome'], 'telefone' => (string) $p['telefone']];

if (metodo_post()) {
    csrf_validar();
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'dados') {
        // Somente nome e telefone são editáveis pelo próprio funcionário.
        $v['nome'] = trim((string) ($_POST['nome'] ?? ''));
        $v['telefone'] = trim((string) ($_POST['telefone'] ?? ''));
        if (mb_strlen($v['nome']) < 2 || mb_strlen($v['nome']) > 150) {
            $erros['nome'] = 'Informe o nome (2 a 150 caracteres).';
        }
        if ($v['telefone'] !== '' && !preg_match('/^[0-9 ()+\-]{8,25}$/', $v['telefone'])) {
            $erros['telefone'] = 'Telefone inválido (use números, espaços, +, - e parênteses).';
        }
        if (!$erros) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE usuarios SET nome = ? WHERE id = ?')->execute([$v['nome'], $usuario['id']]);
            $pdo->prepare('UPDATE colaboradores SET telefone = ? WHERE codigo = ?')->execute([$v['telefone'] ?: null, $p['codigo']]);
            $pdo->commit();
            flash('ok', 'Dados atualizados.');
            redirecionar('funcionario/perfil.php#dados');
        }
    } elseif ($acao === 'senha') {
        $atual = (string) ($_POST['senha_atual'] ?? '');
        $nova  = (string) ($_POST['nova_senha'] ?? '');
        $conf  = (string) ($_POST['confirmar_senha'] ?? '');

        $st = $pdo->prepare('SELECT senha FROM usuarios WHERE id = ?');
        $st->execute([$usuario['id']]);
        if (!password_verify($atual, (string) $st->fetchColumn())) {
            $erros['senha_atual'] = 'Senha atual incorreta.';
        } elseif (!senha_forte($nova)) {
            $erros['nova_senha'] = 'A nova senha deve ter 8+ caracteres, com letras e números.';
        } elseif ($nova !== $conf) {
            $erros['nova_senha'] = 'A confirmação de senha não confere.';
        } else {
            $pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $usuario['id']]);
            session_regenerate_id(true);
            flash('ok', 'Senha alterada com sucesso.');
            redirecionar('funcionario/perfil.php#senha');
        }
    }
}

$titulo = 'Meu perfil';
$menu = 'perfil';
require __DIR__ . '/../includes/topo.php';
?>
<header class="pagina-cabecalho">
    <p class="sobretitulo">Área do funcionário</p>
    <h1>Meu perfil</h1>
    <p class="lead">Consulte seus dados e atualize o que for permitido.</p>
</header>

<nav class="nav-ancoras" aria-label="Nesta página">
    <a href="#dados">Meus dados</a>
    <a href="#contrato">Contrato</a>
    <a href="#senha">Senha</a>
</nav>

<section id="dados" class="card secao-form">
    <h2>Meus dados</h2>
    <form method="post" action="<?= e(url('funcionario/perfil.php')) ?>#dados" novalidate>
        <?= csrf_campo() ?><input type="hidden" name="acao" value="dados">
        <div class="grade-form">
            <div class="campo campo-largo">
                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" value="<?= e($v['nome']) ?>" maxlength="150" required>
                <?= erro_campo($erros, 'nome') ?>
            </div>
            <div class="campo">
                <label for="telefone">Telefone</label>
                <input type="tel" id="telefone" name="telefone" value="<?= e($v['telefone']) ?>" maxlength="25">
                <?= erro_campo($erros, 'telefone') ?>
            </div>
            <div class="campo">
                <label for="email">E-mail (login)</label>
                <input type="email" id="email" value="<?= e($p['email']) ?>" disabled>
                <small>Para alterar o e-mail, fale com o RH.</small>
            </div>
        </div>
        <div class="barra-acoes"><button type="submit" class="btn btn-primario">Salvar dados</button></div>
    </form>
</section>

<section id="contrato" class="card secao-form">
    <h2>Contrato</h2>
    <p class="ajuda">Somente o RH pode alterar estas informações.</p>
    <dl class="lista-dados">
        <div><dt>Código</dt><dd><?= (int) $p['codigo'] ?></dd></div>
        <div><dt>Cargo</dt><dd><?= e($p['cargo']) ?></dd></div>
        <div><dt>Admissão</dt><dd><?= e(data_br($p['admissao'])) ?></dd></div>
        <div><dt>Nascimento</dt><dd><?= e(data_br($p['nascimento'])) ?></dd></div>
        <div><dt>Salário</dt><dd><?= e(moeda($p['salario'])) ?></dd></div>
    </dl>
</section>

<section id="senha" class="card secao-form">
    <h2>Alterar senha</h2>
    <form method="post" action="<?= e(url('funcionario/perfil.php')) ?>#senha" novalidate>
        <?= csrf_campo() ?><input type="hidden" name="acao" value="senha">
        <div class="grade-form">
            <div class="campo campo-largo">
                <label for="senha_atual">Senha atual</label>
                <input type="password" id="senha_atual" name="senha_atual" autocomplete="current-password" required>
                <?= erro_campo($erros, 'senha_atual') ?>
            </div>
            <div class="campo">
                <label for="nova_senha">Nova senha</label>
                <input type="password" id="nova_senha" name="nova_senha" autocomplete="new-password" required>
                <small>Mínimo de 8 caracteres, com letras e números.</small>
                <?= erro_campo($erros, 'nova_senha') ?>
            </div>
            <div class="campo">
                <label for="confirmar_senha">Confirmar nova senha</label>
                <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" required>
            </div>
        </div>
        <div class="barra-acoes"><button type="submit" class="btn btn-secundario">Alterar senha</button></div>
    </form>
</section>
<?php require __DIR__ . '/../includes/rodape.php'; ?>
