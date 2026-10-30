<?php
declare(strict_types=1);

/**
 * Inclua este arquivo no TOPO de toda página (antes de qualquer HTML).
 * Ele inicia a sessão protegida e fornece as funções de autenticação.
 */

require_once __DIR__ . '/funcoes.php';

const TEMPO_SESSAO = 1800; // 30 min de inatividade

function enviar_cabecalhos_seguranca(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    header('Cache-Control: no-store');
}

function iniciar_sessao_segura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && env('APP_TRUST_PROXY', '0') === '1');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('RHSESSID');
    session_cache_limiter('');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() === '' ? '/' : base_path(),
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $expirou = isset($_SESSION['ultima_atividade'])
        && (time() - (int) $_SESSION['ultima_atividade']) > TEMPO_SESSAO;
    if ($expirou) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('aviso', 'Sua sessão expirou por inatividade. Entre novamente.');
    }
    $_SESSION['ultima_atividade'] = time();
}

function encerrar_sessao(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Usuário da sessão, revalidado no banco a cada requisição (desativação vale na hora). */
function usuario_logado(): ?array
{
    static $carregado = false;
    static $cache = null;
    if ($carregado) {
        return $cache;
    }
    $carregado = true;

    $id = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($id <= 0) {
        return $cache = null;
    }
    $st = db()->prepare(
        'SELECT u.id, u.nome, u.email, u.nivel, u.ativo, c.codigo
           FROM usuarios u
      LEFT JOIN colaboradores c ON c.usuario_id = u.id
          WHERE u.id = ?'
    );
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u || (int) $u['ativo'] !== 1) {
        encerrar_sessao();
        iniciar_sessao_segura();
        return $cache = null;
    }
    return $cache = $u;
}

function fazer_login(int $usuarioId): void
{
    session_regenerate_id(true);
    $_SESSION['usuario_id']       = $usuarioId;
    $_SESSION['ultima_atividade'] = time();
    $_SESSION['csrf']             = bin2hex(random_bytes(32));
}

function pagina_inicial(string $nivel): string
{
    return $nivel === 'gerente' ? 'gerente/index.php' : 'funcionario/index.php';
}

function exigir_login(): array
{
    $u = usuario_logado();
    if (!$u) {
        flash('aviso', 'Faça login para continuar.');
        redirecionar('login.php');
    }
    return $u;
}

/** Bloqueia quem não tem o perfil exigido ('gerente' ou 'funcionario'). */
function exigir_nivel(string $nivel): array
{
    $u = exigir_login();
    if ($u['nivel'] !== $nivel) {
        flash('erro', 'Você não tem permissão para acessar essa área.');
        redirecionar(pagina_inicial($u['nivel']));
    }
    return $u;
}

enviar_cabecalhos_seguranca();
iniciar_sessao_segura();
