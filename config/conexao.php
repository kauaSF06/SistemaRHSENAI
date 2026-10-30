<?php
declare(strict_types=1);

/**
 * Configuração central: .env, caminho-base (BASE_PATH) e conexão PDO.
 * Este arquivo é incluído por includes/funcoes.php.
 */

if (!defined('RAIZ')) {
    define('RAIZ', dirname(__DIR__));
}

date_default_timezone_set('America/Sao_Paulo');

/** Lê o arquivo .env (formato CHAVE=valor) uma única vez. */
function env_todos(): array
{
    static $dados = null;
    if ($dados !== null) {
        return $dados;
    }
    $dados = [];
    $arquivo = RAIZ . '/.env';
    if (is_readable($arquivo)) {
        foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
                continue;
            }
            [$chave, $valor] = explode('=', $linha, 2);
            $chave = trim($chave);
            $valor = trim($valor);
            if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && substr($valor, -1) === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }
            $dados[$chave] = $valor;
        }
    }
    return $dados;
}

function env(string $chave, ?string $padrao = null): ?string
{
    $todos = env_todos();
    return array_key_exists($chave, $todos) ? $todos[$chave] : $padrao;
}

// Erros: nunca exibir detalhes ao usuário em produção.
error_reporting(E_ALL);
ini_set('display_errors', env('APP_DEBUG', '0') === '1' ? '1' : '0');

/**
 * Caminho-base da aplicação na URL (ex.: "/rh-simples" ou "" quando está na raiz).
 *
 * Detecta sozinho comparando o caminho físico do script em execução com o
 * SCRIPT_NAME da URL. Se a detecção falhar (alias, proxy, symlink), defina
 * APP_BASE_PATH no .env (use "/" para raiz do domínio).
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $manual = env('APP_BASE_PATH');
    if ($manual !== null && $manual !== '') {
        return $base = ($manual === '/') ? '' : '/' . trim($manual, '/');
    }

    $arquivo = str_replace('\\', '/', (string) (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ''));
    $raiz    = str_replace('\\', '/', (string) (realpath(RAIZ) ?: RAIZ));
    $script  = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($arquivo !== '' && str_starts_with($arquivo, $raiz)) {
        $relativo = substr($arquivo, strlen($raiz)); // ex.: /gerente/index.php
        if ($relativo !== '' && str_ends_with($script, $relativo)) {
            return $base = substr($script, 0, strlen($script) - strlen($relativo));
        }
    }
    return $base = '';
}

/** Gera a URL (sem domínio) de qualquer arquivo do projeto: url('gerente/index.php'). */
function url(string $caminho = ''): string
{
    return base_path() . '/' . ltrim($caminho, '/');
}

/** Conexão PDO preguiçosa (só conecta quando necessário). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', 'localhost'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'rh_simples')
    );
    try {
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[RH] Falha de conexão: ' . $e->getMessage());
        http_response_code(500);
        exit('Não foi possível conectar ao banco de dados. Verifique o arquivo .env.');
    }
    return $pdo;
}
