<?php
/**
 * Droma API v1.0 — Console Interativo & Documentação
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Security.php';

// Exige autenticação prévia: apenas usuários logados têm acesso ao console da API
$userId = Security::requireAuth();
$userToken = '';

if ($userId) {
    $stmt = $pdo->prepare("SELECT api_token FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userToken = $stmt->fetchColumn() ?: '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Droma - API RESTful v1.0</title>
    <link rel="stylesheet" href="../../style_index.css">
    <style>
        .api-container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .api-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.3);
        }
        .endpoint-row {
            border-left: 4px solid var(--color-gold);
            background: var(--color-surface-secondary);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-bottom: 16px;
        }
        .method-badge {
            display: inline-block;
            font-weight: 800;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 4px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .method-get { background: rgba(0, 200, 150, 0.2); color: var(--color-emerald); }
        .method-post { background: rgba(50, 130, 246, 0.2); color: var(--color-blue); }
        .method-delete { background: rgba(239, 68, 68, 0.2); color: var(--color-danger); }

        .token-box {
            display: flex;
            gap: 12px;
            align-items: center;
            background: var(--color-surface-secondary);
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-top: 14px;
            border: 1px solid var(--color-border);
        }
        .token-box input {
            background: transparent;
            border: none;
            color: var(--color-gold);
            font-family: monospace;
            font-size: 14px;
            flex: 1;
            outline: none;
        }
        pre.json-view {
            background: #06090c;
            border: 1px solid var(--color-border);
            padding: 14px;
            border-radius: var(--radius-sm);
            color: #E8C982;
            font-family: monospace;
            font-size: 13px;
            max-height: 280px;
            overflow-y: auto;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<header class="topo">
    <div class="brand-wrapper">
        <div class="brand-icon">D</div>
        <div class="brand-text">Droma<span>.API</span></div>
        <span style="font-size:12px; background:rgba(212,175,108,0.15); color:var(--color-gold); padding:2px 8px; border-radius:4px; font-weight:700; margin-left:12px;">v1.0 RESTful</span>
    </div>
    <div class="acoes-topo">
        <a class="sair" href="../../index.php">Painel Web</a>
        <a class="sair" href="../../relatorios.php">Relatórios</a>
        <a class="sair" href="../../perfil.php">Meu Perfil</a>
    </div>
</header>

<main class="api-container">

    <div class="api-card">
        <h1 style="font-size:24px; color:var(--color-text); margin-bottom:8px;">Console & Documentação da API</h1>
        <p style="color:var(--color-text-secondary); font-size:14px; line-height:1.6;">
            A API RESTful do Droma disponibiliza todos os recursos financeiros com respostas JSON padronizadas, proteção estrita contra IDOR e autenticação por Bearer Token.
        </p>

        <div class="token-box">
            <span style="font-size:13px; font-weight:600; color:var(--color-text-secondary);">Seu Bearer Token:</span>
            <input type="text" id="activeToken" value="<?= htmlspecialchars($userToken) ?>" placeholder="<?= $userId ? 'Nenhuma chave gerada ainda no perfil' : 'Faça login para ver seu token' ?>">
            <?php if ($userToken): ?>
                <button type="button" class="sair" style="font-size:12px; padding:6px 12px; cursor:pointer;" onclick="navigator.clipboard.writeText(document.getElementById('activeToken').value); this.textContent='Copiado!'; setTimeout(()=>this.textContent='Copiar', 2000);">Copiar</button>
            <?php else: ?>
                <a href="../../perfil.php" class="btn-header-primary" style="font-size:12px; padding:6px 14px;">Gerar no Perfil</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Endpoint 1: Resumo -->
    <div class="api-card">
        <div class="endpoint-row">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span class="method-badge method-get">GET</span>
                    <strong style="margin-left:8px; font-size:15px; color:var(--color-text);">/api/v1/resumo.php</strong>
                </div>
                <button class="sair" style="font-size:12px; padding:4px 10px; cursor:pointer;" onclick="testarEndpoint('resumo.php', 'GET', 'res-resumo')">Executar Teste ▶</button>
            </div>
            <p style="color:var(--color-text-secondary); font-size:13px; margin-top:8px;">
                Retorna o saldo consolidado, receitas e despesas do mês e contadores gerais.
            </p>
            <pre id="res-resumo" class="json-view">// Clique em "Executar Teste" para ver a resposta em tempo real</pre>
        </div>

        <!-- Endpoint 2: Contas -->
        <div class="endpoint-row">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span class="method-badge method-get">GET</span>
                    <strong style="margin-left:8px; font-size:15px; color:var(--color-text);">/api/v1/contas.php</strong>
                </div>
                <button class="sair" style="font-size:12px; padding:4px 10px; cursor:pointer;" onclick="testarEndpoint('contas.php', 'GET', 'res-contas')">Executar Teste ▶</button>
            </div>
            <p style="color:var(--color-text-secondary); font-size:13px; margin-top:8px;">
                Lista as contas bancárias do usuário autenticado com saldo atual individual.
            </p>
            <pre id="res-contas" class="json-view">// Clique em "Executar Teste" para ver a resposta em tempo real</pre>
        </div>

        <!-- Endpoint 3: Categorias -->
        <div class="endpoint-row">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span class="method-badge method-get">GET</span>
                    <strong style="margin-left:8px; font-size:15px; color:var(--color-text);">/api/v1/categorias.php</strong>
                </div>
                <button class="sair" style="font-size:12px; padding:4px 10px; cursor:pointer;" onclick="testarEndpoint('categorias.php', 'GET', 'res-categorias')">Executar Teste ▶</button>
            </div>
            <p style="color:var(--color-text-secondary); font-size:13px; margin-top:8px;">
                Lista categorias de receitas e despesas com contagem de transações.
            </p>
            <pre id="res-categorias" class="json-view">// Clique em "Executar Teste" para ver a resposta em tempo real</pre>
        </div>

        <!-- Endpoint 4: Transações -->
        <div class="endpoint-row">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span class="method-badge method-get">GET</span>
                    <span class="method-badge method-post" style="margin-left:4px;">POST</span>
                    <span class="method-badge method-delete" style="margin-left:4px;">DELETE</span>
                    <strong style="margin-left:8px; font-size:15px; color:var(--color-text);">/api/v1/transacoes.php</strong>
                </div>
                <button class="sair" style="font-size:12px; padding:4px 10px; cursor:pointer;" onclick="testarEndpoint('transacoes.php?limit=5', 'GET', 'res-transacoes')">Listar 5 Lançamentos ▶</button>
            </div>
            <p style="color:var(--color-text-secondary); font-size:13px; margin-top:8px;">
                CRUD completo de movimentações com filtros, paginação e validação de propriedade.
            </p>
            <pre id="res-transacoes" class="json-view">// Clique no botão para listar transações recentes</pre>
        </div>
    </div>

</main>

<script>
async function testarEndpoint(url, method, outputId) {
    const out = document.getElementById(outputId);
    out.textContent = "Carregando requisição...";
    const token = document.getElementById('activeToken').value.trim();

    const headers = { 'Accept': 'application/json' };
    if (token) {
        headers['Authorization'] = 'Bearer ' + token;
    }

    try {
        const resp = await fetch(url, { method, headers });
        const json = await resp.json();
        out.textContent = JSON.stringify(json, null, 2);
    } catch (err) {
        out.textContent = "Erro na requisição: " + err.message;
    }
}
</script>

</body>
</html>
