<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/protecao.php';
$gerente = exigir_nivel('gerente');
$pdo = db();

$codigo = (int) ($_GET['codigo'] ?? $_POST['codigo'] ?? 0);
$edicao = $codigo > 0;
$atual = null;

if ($edicao) {
    $st = $pdo->prepare(
        'SELECT c.*, u.nome, u.email, u.nivel, u.ativo
           FROM colaboradores c JOIN usuarios u ON u.id = c.usuario_id
          WHERE c.codigo = ?'
    );
    $st->execute([$codigo]);
    $atual = $st->fetch();
    if (!$atual) {
        flash('erro', 'Colaborador não encontrado.');
        redirecionar('gerente/colaboradores.php');
    }
}

$v = [
    'nome'       => $atual['nome'] ?? '',
    'email'      => $atual['email'] ?? '',
    'nivel'      => $atual['nivel'] ?? 'funcionario',
    'nascimento' => $atual['nascimento'] ?? '',
    'telefone'   => $atual['telefone'] ?? '',
    'cargo'      => $atual['cargo'] ?? '',
    'admissao'   => $atual['admissao'] ?? '',
    'salario'    => $atual ? numero_br($atual['salario']) : '',
];
$erros = [];

if (metodo_post()) {
    csrf_validar();
    foreach ($v as $campo => $_) {
        $v[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }
    $v['email'] = strtolower($v['email']);
    $senha  = (string) ($_POST['senha'] ?? '');
    $senha2 = (string) ($_POST['senha2'] ?? '');
    $usuarioId = $atual ? (int) $atual['usuario_id'] : 0;

    if (mb_strlen($v['nome']) < 2 || mb_strlen($v['nome']) > 150) {
        $erros['nome'] = 'Informe o nome (2 a 150 caracteres).';
    }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 180) {
        $erros['email'] = 'Informe um e-mail válido.';
    }
    if (!in_array($v['nivel'], ['gerente', 'funcionario'], true)) {
        $erros['nivel'] = 'Perfil inválido.';
    } elseif ($usuarioId === (int) $gerente['id'] && $v['nivel'] !== 'gerente') {
        $erros['nivel'] = 'Você não pode rebaixar o seu próprio perfil.';
    }
    if (!data_valida($v['nascimento']) || $v['nascimento'] >= date('Y-m-d')) {
        $erros['nascimento'] = 'Informe uma data de nascimento válida.';
    }
    if (!data_valida($v['admissao'])) {
        $erros['admissao'] = 'Informe uma data de admissão válida.';
    }
    if ($v['telefone'] !== '' && !preg_match('/^[0-9 ()+\-]{8,25}$/', $v['telefone'])) {
        $erros['telefone'] = 'Telefone inválido (use números, espaços, +, - e parênteses).';
    }
    if (mb_strlen($v['cargo']) < 2 || mb_strlen($v['cargo']) > 100) {
        $erros['cargo'] = 'Informe o cargo (2 a 100 caracteres).';
    }
    $salario = decimal_br($v['salario']);
    if ($salario === null) {
        $erros['salario'] = 'Informe um salário válido (ex.: 3.500,00).';
    }
    if (!$edicao || $senha !== '') {
        if (!senha_forte($senha)) {
            $erros['senha'] = 'A senha deve ter 8+ caracteres, com letras e números.';
        } elseif ($senha !== $senha2) {
            $erros['senha'] = 'A confirmação de senha não confere.';
        }
    }
    if (!isset($erros['email'])) {
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
        $st->execute([$v['email'], $usuarioId]);
        if ($st->fetch()) {
            $erros['email'] = 'Este e-mail já está em uso.';
        }
    }

    if (!$erros) {
        try {
            $pdo->beginTransaction();
            if ($edicao) {
                if ($senha !== '') {
                    $pdo->prepare('UPDATE usuarios SET nome=?, email=?, nivel=?, senha=? WHERE id=?')
                        ->execute([$v['nome'], $v['email'], $v['nivel'], password_hash($senha, PASSWORD_DEFAULT), $usuarioId]);
                } else {
                    $pdo->prepare('UPDATE usuarios SET nome=?, email=?, nivel=? WHERE id=?')
                        ->execute([$v['nome'], $v['email'], $v['nivel'], $usuarioId]);
                }
                $pdo->prepare('UPDATE colaboradores SET nascimento=?, telefone=?, cargo=?, admissao=?, salario=? WHERE codigo=?')
                    ->execute([$v['nascimento'], $v['telefone'] ?: null, $v['cargo'], $v['admissao'], $salario, $codigo]);
                $mensagem = 'Cadastro atualizado.';
            } else {
                $pdo->prepare('INSERT INTO usuarios (nome, email, senha, nivel) VALUES (?, ?, ?, ?)')
                    ->execute([$v['nome'], $v['email'], password_hash($senha, PASSWORD_DEFAULT), $v['nivel']]);
                $novoUsuario = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO colaboradores (usuario_id, nascimento, telefone, cargo, admissao, salario) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$novoUsuario, $v['nascimento'], $v['telefone'] ?: null, $v['cargo'], $v['admissao'], $salario]);
                $mensagem = 'Colaborador cadastrado com o código ' . (int) $pdo->lastInsertId() . '.';
            }
            $pdo->commit();
            flash('ok', $mensagem);
            redirecionar('gerente/colaboradores.php#lista');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {
                $erros['email'] = 'Este e-mail já está em uso.';
            } else {
                error_log('[RH] colaborador-form: ' . $ex->getMessage());
                $erros['geral'] = 'Não foi possível salvar. Tente novamente.';
            }
        }
    }
}

$titulo = $edicao ? 'Editar colaborador' : 'Novo colaborador';
$menu = 'colaboradores';
require __DIR__ . '/../includes/topo.php';
?>
<header class="pagina-cabecalho">
    <p class="sobretitulo"><a href="<?= e(url('gerente/colaboradores.php#lista')) ?>">← Colaboradores</a></p>
    <h1><?= e($titulo) ?><?= $edicao ? ' · Cód. ' . $codigo : '' ?></h1>
</header>

<?php if (isset($erros['geral'])): ?>
    <div class="alerta alerta-erro" role="alert"><?= e($erros['geral']) ?></div>
<?php endif; ?>

<form method="post" action="<?= e(url('gerente/colaborador-form.php')) ?>#dados" novalidate>
    <?= csrf_campo() ?>
    <?php if ($edicao): ?><input type="hidden" name="codigo" value="<?= $codigo ?>"><?php endif; ?>

    <section id="dados" class="card secao-form">
        <h2>Dados pessoais</h2>
        <div class="grade-form">
            <div class="campo campo-largo">
                <label for="nome">Nome completo</label>
                <input type="text" id="nome" name="nome" value="<?= e($v['nome']) ?>" maxlength="150" required>
                <?= erro_campo($erros, 'nome') ?>
            </div>
            <div class="campo">
                <label for="nascimento">Nascimento</label>
                <input type="date" id="nascimento" name="nascimento" value="<?= e($v['nascimento']) ?>" required>
                <?= erro_campo($erros, 'nascimento') ?>
            </div>
            <div class="campo">
                <label for="telefone">Telefone</label>
                <input type="tel" id="telefone" name="telefone" value="<?= e($v['telefone']) ?>" maxlength="25">
                <?= erro_campo($erros, 'telefone') ?>
            </div>
        </div>
    </section>

    <section id="contrato" class="card secao-form">
        <h2>Contrato</h2>
        <div class="grade-form">
            <div class="campo">
                <label for="cargo">Cargo</label>
                <input type="text" id="cargo" name="cargo" value="<?= e($v['cargo']) ?>" maxlength="100" required>
                <?= erro_campo($erros, 'cargo') ?>
            </div>
            <div class="campo">
                <label for="admissao">Admissão</label>
                <input type="date" id="admissao" name="admissao" value="<?= e($v['admissao']) ?>" required>
                <?= erro_campo($erros, 'admissao') ?>
            </div>
            <div class="campo">
                <label for="salario">Salário (R$)</label>
                <input type="text" id="salario" name="salario" value="<?= e($v['salario']) ?>" inputmode="decimal" placeholder="3.500,00" required>
                <?= erro_campo($erros, 'salario') ?>
            </div>
        </div>
    </section>

    <section id="acesso" class="card secao-form">
        <h2>Acesso ao sistema</h2>
        <div class="grade-form">
            <div class="campo">
                <label for="email">E-mail (login)</label>
                <input type="email" id="email" name="email" value="<?= e($v['email']) ?>" maxlength="180" autocomplete="off" required>
                <?= erro_campo($erros, 'email') ?>
            </div>
            <div class="campo">
                <label for="nivel">Perfil</label>
                <select id="nivel" name="nivel">
                    <option value="funcionario"<?= $v['nivel'] === 'funcionario' ? ' selected' : '' ?>>Funcionário</option>
                    <option value="gerente"<?= $v['nivel'] === 'gerente' ? ' selected' : '' ?>>Gerente</option>
                </select>
                <?= erro_campo($erros, 'nivel') ?>
            </div>
            <div class="campo">
                <label for="senha"><?= $edicao ? 'Nova senha (opcional)' : 'Senha' ?></label>
                <input type="password" id="senha" name="senha" autocomplete="new-password"<?= $edicao ? '' : ' required' ?>>
                <small>Mínimo de 8 caracteres, com letras e números.</small>
                <?= erro_campo($erros, 'senha') ?>
            </div>
            <div class="campo">
                <label for="senha2">Confirmar senha</label>
                <input type="password" id="senha2" name="senha2" autocomplete="new-password">
            </div>
        </div>
    </section>

    <div class="barra-acoes">
        <button type="submit" class="btn btn-primario">Salvar</button>
        <a class="btn btn-fantasma" href="<?= e(url('gerente/colaboradores.php#lista')) ?>">Cancelar</a>
    </div>
</form>
<?php require __DIR__ . '/../includes/rodape.php'; ?>
