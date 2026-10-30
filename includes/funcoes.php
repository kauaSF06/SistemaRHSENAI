<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';

/* ------------------------------------------------------------------ *
 * Utilidades gerais
 * ------------------------------------------------------------------ */

/** Escapa texto para HTML (use SEMPRE ao imprimir dados). */
function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function metodo_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Redireciona para um caminho do projeto (ex.: "gerente/index.php"). */
function redirecionar(string $caminho): never
{
    header('Location: ' . url($caminho));
    exit;
}

/* ------------------------------------------------------------------ *
 * CSRF
 * ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_validar(): void
{
    $enviado = (string) ($_POST['csrf_token'] ?? '');
    if ($enviado === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $enviado)) {
        http_response_code(419);
        exit('Requisição inválida ou expirada. Volte, recarregue a página e tente novamente.');
    }
}

/* ------------------------------------------------------------------ *
 * Mensagens (flash)
 * ------------------------------------------------------------------ */

/** $tipo: ok | erro | aviso */
function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $mensagem];
}

function flash_obter(): array
{
    $mensagens = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $mensagens;
}

/* ------------------------------------------------------------------ *
 * Formatação e validação
 * ------------------------------------------------------------------ */

function moeda(float|int|string|null $valor): string
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

function numero_br(float|int|string|null $valor): string
{
    return number_format((float) $valor, 2, ',', '.');
}

function data_br(?string $data): string
{
    if (!$data) {
        return '—';
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', substr($data, 0, 10));
    return $dt ? $dt->format('d/m/Y') : '—';
}

function competencia_br(string $data): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', substr($data, 0, 10));
    return $dt ? $dt->format('m/Y') : '—';
}

function data_valida(string $data): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $dt !== false && $dt->format('Y-m-d') === $data;
}

/** Converte "5.000,50", "5000,5" ou "5000.50" em float. Retorna null se inválido. */
function decimal_br(string $texto): ?float
{
    $t = trim(str_replace(['R$', ' '], '', $texto));
    if ($t === '') {
        return null;
    }
    if (str_contains($t, ',')) {
        $t = str_replace('.', '', $t);
        $t = str_replace(',', '.', $t);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $t)) {
        $t = str_replace('.', '', $t);
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $t)) {
        return null;
    }
    $valor = round((float) $t, 2);
    return $valor <= 9999999999.99 ? $valor : null;
}

function senha_forte(string $senha): bool
{
    return mb_strlen($senha) >= 8 && preg_match('/[A-Za-z]/', $senha) === 1 && preg_match('/\d/', $senha) === 1;
}

/** Mensagem de erro abaixo de um campo de formulário. */
function erro_campo(array $erros, string $campo): string
{
    return isset($erros[$campo]) ? '<small class="erro-campo">' . e($erros[$campo]) . '</small>' : '';
}

function rotulo_nivel(string $nivel): string
{
    return $nivel === 'gerente' ? 'Gerente' : 'Funcionário';
}

/* ------------------------------------------------------------------ *
 * Rescisão (ESTIMATIVA — validar com contabilidade/jurídico)
 * ------------------------------------------------------------------ */

function motivos_rescisao(): array
{
    return [
        'sem_justa_causa' => 'Dispensa sem justa causa',
        'pedido_demissao' => 'Pedido de demissão',
        'acordo'          => 'Acordo entre as partes (art. 484-A da CLT)',
        'fim_contrato'    => 'Término de contrato por prazo determinado',
        'justa_causa'     => 'Dispensa por justa causa',
    ];
}

function rotulo_motivo(string $codigo): string
{
    return motivos_rescisao()[$codigo] ?? $codigo;
}

function rotulo_status_rescisao(string $status): string
{
    return ['rascunho' => 'Rascunho', 'revisao' => 'Em revisão', 'finalizada' => 'Finalizada'][$status] ?? $status;
}

/** Avos de 13º: cada mês do ano civil com 15 dias ou mais trabalhados conta 1/12. */
function avos_decimo_terceiro(DateTimeImmutable $inicio, DateTimeImmutable $fim): int
{
    $avos = 0;
    $cursor = $inicio->modify('first day of this month');
    while ($cursor <= $fim) {
        $primeiro = $cursor;
        $ultimo   = $cursor->modify('last day of this month');
        $de  = $inicio > $primeiro ? $inicio : $primeiro;
        $ate = $fim < $ultimo ? $fim : $ultimo;
        if ($ate >= $de && ((int) $de->diff($ate)->days + 1) >= 15) {
            $avos++;
        }
        $cursor = $cursor->modify('first day of next month');
    }
    return min($avos, 12);
}

/** Avos de férias proporcionais: meses completos desde o último aniversário de admissão (fração >= 15 dias = 1). */
function avos_ferias(DateTimeImmutable $admissao, DateTimeImmutable $fim): int
{
    $anos = 0;
    while ($admissao->modify('+' . ($anos + 1) . ' years') <= $fim) {
        $anos++;
    }
    $inicio = $admissao->modify("+{$anos} years");
    $d = $inicio->diff($fim->modify('+1 day'));
    $meses = $d->y * 12 + $d->m;
    if ($d->d >= 15) {
        $meses++;
    }
    return min($meses, 12);
}

/**
 * Estimativa simplificada. NÃO inclui: aviso prévio, multa do FGTS, férias vencidas,
 * médias de variáveis, INSS/IRRF, faltas, adiantamentos, pensão, convenção coletiva.
 */
function calcular_rescisao(float $salario, string $admissao, string $desligamento, string $motivo): array
{
    $adm = DateTimeImmutable::createFromFormat('!Y-m-d', $admissao);
    $des = DateTimeImmutable::createFromFormat('!Y-m-d', $desligamento);
    if (!$adm || !$des) {
        throw new InvalidArgumentException('Datas inválidas.');
    }

    // Saldo de salário (base 30 dias; último dia do mês = mês cheio)
    $dias = (int) $des->format('j');
    if ($des->format('Y-m-d') === $des->modify('last day of this month')->format('Y-m-d')) {
        $dias = 30;
    }
    $dias  = min($dias, 30);
    $saldo = round($salario / 30 * $dias, 2);

    $inicio13 = new DateTimeImmutable($des->format('Y') . '-01-01');
    if ($adm > $inicio13) {
        $inicio13 = $adm;
    }
    $avos13 = avos_decimo_terceiro($inicio13, $des);
    $avosF  = avos_ferias($adm, $des);

    $perde  = ($motivo === 'justa_causa'); // sem 13º e sem férias proporcionais
    $decimo = $perde ? 0.0 : round($salario / 12 * $avos13, 2);
    $ferias = $perde ? 0.0 : round($salario / 12 * $avosF, 2);
    $terco  = round($ferias / 3, 2);

    return [
        'dias_saldo'           => $dias,
        'avos_13'              => $perde ? 0 : $avos13,
        'avos_ferias'          => $perde ? 0 : $avosF,
        'saldo_salario'        => $saldo,
        'decimo_terceiro'      => $decimo,
        'ferias_proporcionais' => $ferias,
        'terco_ferias'         => $terco,
        'total_estimado'       => round($saldo + $decimo + $ferias + $terco, 2),
    ];
}
