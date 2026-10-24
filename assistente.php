<?php
require_once "config/database.php";
require_once "src/Security.php";
require_once "src/ClienteIA.php";

$userId = Security::requireAuth();
$ai = new ClienteIA();

$periodo = $_POST['periodo'] ?? ($_GET['periodo'] ?? 'mes_atual');
$foco    = $_POST['foco'] ?? ($_GET['foco'] ?? 'geral');

// Consulta cota diária
$usage = $ai->getDailyUsage($pdo, $userId);

$analiseGerada = null;
$tipoAnalise   = null; // 'ai' ou 'local'
$mensagemAlerta = null;

// Preparação dos dados financeiros agregados para análise
function compilarDadosFinanceiros(PDO $pdo, int $userId, string $periodo): array {
    $whereDate = "";
    $labelPeriodo = "";

    switch ($periodo) {
        case 'ultimos_30':
            $whereDate = "t.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            $labelPeriodo = "Últimos 30 dias";
            break;
        case 'ultimos_90':
            $whereDate = "t.date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
            $labelPeriodo = "Últimos 90 dias";
            break;
        case 'ano_vigente':
            $whereDate = "YEAR(t.date) = YEAR(CURDATE())";
            $labelPeriodo = "Ano de " . date('Y');
            break;
        case 'mes_atual':
        default:
            $whereDate = "MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())";
            $labelPeriodo = "Mês atual (" . date('m/Y') . ")";
            break;
    }

    // Receitas realizadas
    $stmtRec = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions t WHERE t.user_id = ? AND t.type = 'income' AND t.status = 'completed' AND {$whereDate}");
    $stmtRec->execute([$userId]);
    $receitas = (float)$stmtRec->fetchColumn();

    // Despesas realizadas
    $stmtDesp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions t WHERE t.user_id = ? AND t.type = 'expense' AND t.status = 'completed' AND {$whereDate}");
    $stmtDesp->execute([$userId]);
    $despesas = (float)$stmtDesp->fetchColumn();

    // Top 5 maiores categorias de despesas
    $stmtCats = $pdo->prepare("
        SELECT COALESCE(c.name, 'Sem categoria') AS categoria, SUM(t.amount) AS total
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = ? AND t.type = 'expense' AND t.status = 'completed' AND {$whereDate}
        GROUP BY c.id, c.name
        ORDER BY total DESC
        LIMIT 5
    ");
    $stmtCats->execute([$userId]);
    $maioresDespesas = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

    // Metas ativas
    $stmtMetas = $pdo->prepare("SELECT name AS title, target_amount, current_amount FROM goals WHERE user_id = ? LIMIT 3");
    $stmtMetas->execute([$userId]);
    $metas = $stmtMetas->fetchAll(PDO::FETCH_ASSOC);

    // Total em faturas pendentes de cartão
    $stmtCartoes = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND credit_card_id IS NOT NULL AND status = 'pending'");
    $stmtCartoes->execute([$userId]);
    $faturasPendentes = (float)$stmtCartoes->fetchColumn();

    return [
        'periodo'          => $labelPeriodo,
        'receitas'         => $receitas,
        'despesas'         => $despesas,
        'saldo'            => $receitas - $despesas,
        'taxa_poupanca'    => $receitas > 0 ? round((($receitas - $despesas) / $receitas) * 100, 1) : 0,
        'maiores_despesas' => $maioresDespesas,
        'faturas_cartao'   => $faturasPendentes,
        'metas'            => $metas
    ];
}

$dadosAgregados = compilarDadosFinanceiros($pdo, $userId, $periodo);

// Processamento da solicitação de análise
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $mensagemAlerta = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } elseif ($usage['allowed']) {
        try {
            $dadosParaIA = $dadosAgregados;
            $dadosParaIA['foco_do_usuario'] = $foco;
            $analiseGerada = $ai->analisar($dadosParaIA);
            $ai->recordUsage($pdo, $userId);
            $tipoAnalise = 'ai';
            // Recarrega contagem atualizada
            $usage = $ai->getDailyUsage($pdo, $userId);
        } catch (Throwable $e) {
            $mensagemAlerta = "Nota: Não foi possível obter resposta em tempo real do serviço externo (" . $e->getMessage() . "). O Droma gerou um diagnóstico analítico local com base nos seus registros.";
            $analiseGerada = $ai->gerarAnaliseLocal($dadosAgregados);
            $tipoAnalise = 'local';
        }
    } else {
        $proxima = $usage['proxima_liberacao'] ?? 'no próximo ciclo';
        $ciclo   = $usage['ciclo_nome'] ?? 'atual';
        $mensagemAlerta = "Você já utilizou sua consulta de IA para este período ({$ciclo}). Sua próxima consultoria com IA será liberada em {$proxima}! Abaixo está o diagnóstico inteligente gerado pelo motor analítico local do Droma.";
        $analiseGerada = $ai->gerarAnaliseLocal($dadosAgregados);
        $tipoAnalise = 'local';
    }
}

/**
 * Converte Markdown simples para HTML seguro
 */
function markdownToHtml(string $markdown): string {
    $text = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');
    // **negrito**
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    // Linhas começando com • ou * ou -
    $linhas = explode("\n", $text);
    $out = [];
    $inList = false;

    foreach ($linhas as $linha) {
        $trimmed = trim($linha);
        if (preg_match('/^(?:•|\*|-)\s+(.*)/', $trimmed, $m)) {
            if (!$inList) {
                $out[] = '<ul class="lista-insights">';
                $inList = true;
            }
            $out[] = '<li>' . $m[1] . '</li>';
        } else {
            if ($inList) {
                $out[] = '</ul>';
                $inList = false;
            }
            if ($trimmed !== '') {
                $out[] = '<p>' . $trimmed . '</p>';
            }
        }
    }
    if ($inList) {
        $out[] = '</ul>';
    }
    return implode("\n", $out);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Droma - Assistente Financeiro IA</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <style>
        .ia-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .ia-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
        }
        .ia-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
            gap: 16px;
            align-items: flex-end;
            margin-top: 16px;
            width: 100%;
            min-width: 0;
        }
        .ia-form .campo {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .ia-form label {
            font-size: 13px;
            font-weight: 600;
            color: var(--color-text-secondary);
        }
        .ia-form select {
            background: var(--color-surface-secondary);
            color: var(--color-text);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 10px 14px;
            font-size: 14px;
            outline: none;
        }
        .ia-form select:focus {
            border-color: var(--color-gold);
        }
        .badge-quota {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: var(--radius-pill);
            font-size: 13px;
            font-weight: 700;
            background: rgba(212, 175, 108, 0.12);
            color: var(--color-gold-light);
            border: 1px solid rgba(212, 175, 108, 0.3);
        }
        .resposta-box {
            background: linear-gradient(145deg, #0f171b 0%, #152228 100%);
            border: 1px solid rgba(212, 175, 108, 0.35);
            border-radius: var(--radius-lg);
            padding: 28px;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
        }
        .resposta-box .badge-tipo {
            position: absolute;
            top: 20px;
            right: 24px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: var(--radius-pill);
            letter-spacing: 0.5px;
        }
        .badge-tipo.ai {
            background: rgba(0, 200, 150, 0.15);
            color: var(--color-emerald);
            border: 1px solid var(--color-emerald);
        }
        .badge-tipo.local {
            background: rgba(212, 175, 108, 0.15);
            color: var(--color-gold);
            border: 1px solid var(--color-gold);
        }
        .lista-insights {
            list-style: none;
            padding: 0;
            margin: 16px 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .lista-insights li {
            position: relative;
            padding-left: 24px;
            font-size: 15px;
            line-height: 1.6;
            color: var(--color-text);
        }
        .lista-insights li::before {
            content: '✦';
            position: absolute;
            left: 0;
            color: var(--color-gold);
            font-size: 14px;
        }
        .resumo-dados {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 150px), 1fr));
            gap: 12px;
            background: var(--color-surface-secondary);
            padding: 16px;
            border-radius: var(--radius-md);
            margin-top: 16px;
            border: 1px solid var(--color-border);
            width: 100%;
            min-width: 0;
        }
        .resumo-item {
            display: flex;
            flex-direction: column;
        }
        .resumo-item span.lbl {
            font-size: 11px;
            color: var(--color-text-secondary);
            text-transform: uppercase;
        }
        .resumo-item span.val {
            font-size: 15px;
            font-weight: 700;
            color: var(--color-text);
        }
    </style>
</head>
<body>

<?php
$paginaAtiva = 'assistente';
$tituloPagina = 'Assistente Financeiro IA';
require_once "includes/layout_header.php";
?>

<main class="painel" style="max-width: 1040px; margin: 0 auto; padding: 24px 20px;">

    <div class="ia-header">
        <div>
            <h1 style="font-size: 26px; font-weight: 800; color: var(--color-text); margin-bottom: 4px;">
                Assistente Financeiro Inteligente
            </h1>
            <p style="color: var(--color-text-secondary); font-size: 14px;">
                Diagnósticos financeiros personalizados, orientações práticas e insights com Inteligência Artificial.
            </p>
        </div>

        <div class="badge-quota" style="text-align: right;">
            <div style="font-weight: 700;">
                ⚡ <?= $usage['allowed'] ? '1 consulta de IA disponível' : 'Consulta do ciclo já utilizada' ?>
            </div>
            <div style="font-size: 11px; opacity: 0.85; margin-top: 2px;">
                <?= $usage['allowed'] ? 'Ciclo: ' . htmlspecialchars($usage['ciclo_nome']) : 'Próxima liberação: ' . htmlspecialchars($usage['proxima_liberacao']) ?>
            </div>
        </div>
    </div>

    <?php if ($mensagemAlerta): ?>
        <div style="background: rgba(212, 175, 108, 0.12); border-left: 4px solid var(--color-gold); padding: 14px 18px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 14px; color: var(--color-gold-light);">
            <?= htmlspecialchars($mensagemAlerta) ?>
        </div>
    <?php endif; ?>

    <!-- Formulário de Diagnóstico -->
    <div class="ia-card">
        <h2 style="font-size: 18px; color: var(--color-gold-light); margin-bottom: 8px;">
            Configurar Diagnóstico
        </h2>
        <p style="font-size: 13px; color: var(--color-text-secondary);">
            Selecione o período e o foco da consultoria financeira que você deseja receber.
        </p>

        <form method="POST" action="assistente.php" class="ia-form">
            <?= Security::csrfField() ?>

            <div class="campo">
                <label for="periodo">Período para Análise:</label>
                <select name="periodo" id="periodo">
                    <option value="mes_atual" <?= $periodo === 'mes_atual' ? 'selected' : '' ?>>Mês Atual (<?= date('m/Y') ?>)</option>
                    <option value="ultimos_30" <?= $periodo === 'ultimos_30' ? 'selected' : '' ?>>Últimos 30 Dias</option>
                    <option value="ultimos_90" <?= $periodo === 'ultimos_90' ? 'selected' : '' ?>>Últimos 90 Dias</option>
                    <option value="ano_vigente" <?= $periodo === 'ano_vigente' ? 'selected' : '' ?>>Ano Completo (<?= date('Y') ?>)</option>
                </select>
            </div>

            <div class="campo">
                <label for="foco">Foco da Orientação:</label>
                <select name="foco" id="foco">
                    <option value="geral" <?= $foco === 'geral' ? 'selected' : '' ?>>Diagnóstico Geral & Equilíbrio</option>
                    <option value="economia" <?= $foco === 'economia' ? 'selected' : '' ?>>Identificar Onde Economizar</option>
                    <option value="metas" <?= $foco === 'metas' ? 'selected' : '' ?>>Acelerar Metas de Poupança</option>
                    <option value="cartoes" <?= $foco === 'cartoes' ? 'selected' : '' ?>>Gestão de Cartões de Crédito</option>
                </select>
            </div>

            <div class="campo" style="flex:0 0 auto;">
                <button type="submit" class="btn-header-primary" style="height: 42px; padding: 0 24px; font-size: 14px;">
                    ✨ <?= $usage['allowed'] ? 'Gerar Análise com IA' : 'Gerar Diagnóstico Local' ?>
                </button>
            </div>
        </form>

        <!-- Resumo dos Dados que serão analisados -->
        <div class="resumo-dados">
            <div class="resumo-item">
                <span class="lbl">Período Selecionado</span>
                <span class="val"><?= htmlspecialchars($dadosAgregados['periodo']) ?></span>
            </div>
            <div class="resumo-item">
                <span class="lbl">Receitas Realizadas</span>
                <span class="val" style="color:var(--color-emerald);">R$ <?= number_format($dadosAgregados['receitas'], 2, ',', '.') ?></span>
            </div>
            <div class="resumo-item">
                <span class="lbl">Despesas Realizadas</span>
                <span class="val" style="color:var(--color-danger);">R$ <?= number_format($dadosAgregados['despesas'], 2, ',', '.') ?></span>
            </div>
            <div class="resumo-item">
                <span class="lbl">Saldo Operacional</span>
                <span class="val" style="color: <?= $dadosAgregados['saldo'] >= 0 ? 'var(--color-emerald)' : 'var(--color-danger)' ?>;">
                    R$ <?= number_format($dadosAgregados['saldo'], 2, ',', '.') ?>
                </span>
            </div>
            <div class="resumo-item">
                <span class="lbl">Taxa de Poupança</span>
                <span class="val" style="color:var(--color-gold);"><?= $dadosAgregados['taxa_poupanca'] ?>%</span>
            </div>
        </div>
    </div>

    <!-- Exibição do Resultado da Análise -->
    <?php if ($analiseGerada !== null): ?>
        <div class="resposta-box">
            <span class="badge-tipo <?= $tipoAnalise === 'ai' ? 'ai' : 'local' ?>">
                <?= $tipoAnalise === 'ai' ? 'Gemini 3.5 IA' : 'Droma Engine Local' ?>
            </span>

            <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px;">
                <div style="width:40px; height:40px; border-radius:50%; background:var(--gold-gradient); display:flex; align-items:center; justify-content:center; color:var(--color-on-gold); font-size:20px; font-weight:800;">
                    ✦
                </div>
                <div>
                    <h3 style="margin:0; font-size:18px; color:var(--color-gold-light);">
                        Parecer do Assistente Droma
                    </h3>
                    <span style="font-size:12px; color:var(--color-text-secondary);">
                        Gerado em <?= date('d/m/Y \à\s H:i') ?> para <?= htmlspecialchars($dadosAgregados['periodo']) ?>
                    </span>
                </div>
            </div>

            <div class="conteudo-analise" style="font-size: 15px; line-height: 1.7; color: var(--color-text);">
                <?= markdownToHtml($analiseGerada) ?>
            </div>

            <div style="margin-top:24px; padding-top:16px; border-top:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <span style="font-size:12px; color:var(--color-text-secondary);">
                    🛡️ Seus dados são confidenciais e agregados com estrita segurança.
                </span>
                <div style="display:flex; gap:10px;">
                    <a href="relatorios.php" class="sair" style="font-size:12px; padding:6px 12px;">Ver Relatório Completo →</a>
                    <a href="metas.php" class="sair" style="font-size:12px; padding:6px 12px;">Definir Novas Metas →</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="resposta-box" style="text-align: center; padding: 40px 20px;">
            <div style="font-size: 38px; margin-bottom: 12px;">✨</div>
            <h3 style="font-size: 18px; color: var(--color-gold-light); margin-bottom: 8px;">
                Pronto para analisar suas finanças
            </h3>
            <p style="font-size: 14px; color: var(--color-text-secondary); max-width: 520px; margin: 0 auto 20px auto;">
                Clique no botão <strong>"Gerar Análise"</strong> acima para receber um diagnóstico completo sobre suas despesas, oportunidades de economia e evolução de metas.
            </p>
        </div>
    <?php endif; ?>

</main>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>
