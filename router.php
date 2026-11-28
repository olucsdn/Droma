<?php
declare(strict_types=1);

chdir(__DIR__);

$route = trim((string)($_GET['route'] ?? ''), '/');
$route = preg_replace('/\.php$/i', '', $route);

$routes = [
    // Auth
    'accept_terms'    => 'pages/auth/accept_terms.php',
    'login'           => 'pages/auth/login.php',
    'logout'          => 'pages/auth/logout.php',
    'profile'         => 'pages/auth/profile.php',
    'recover'         => 'pages/auth/recover.php',
    'register'        => 'pages/auth/register.php',
    'reset_password'  => 'pages/auth/reset_password.php',
    'terms'           => 'pages/auth/terms.php',
    'verify_email'    => 'pages/auth/verify_email.php',

    // Finance
    'accounts'        => 'pages/finance/accounts.php',
    'add_expense'     => 'pages/finance/add_expense.php',
    'assistant'       => 'pages/finance/assistant.php',
    'backup'          => 'pages/finance/backup.php',
    'calendar'        => 'pages/finance/calendar.php',
    'cards'           => 'pages/finance/cards.php',
    'categories'      => 'pages/finance/categories.php',
    'edit_expense'    => 'pages/finance/edit_expense.php',
    'goals'           => 'pages/finance/goals.php',
    'import'          => 'pages/finance/import.php',
    'investments'     => 'pages/finance/investments.php',
    'recurring'       => 'pages/finance/recurring.php',
    'reports'         => 'pages/finance/reports.php',
    'statement'       => 'pages/finance/statement.php',
    'transfer'        => 'pages/finance/transfer.php',

    // Public
    '404'             => 'pages/public/404.php',
    'apresentacao'    => 'pages/public/apresentacao.php',
    'landing'         => 'pages/public/landing.php',

    // Actions
    'delete_expense'  => 'actions/delete_expense.php',
    'due_alerts'      => 'actions/due_alerts.php',
    'push_cron'       => 'actions/push_cron.php',
    'push_subscribe'  => 'actions/push_subscribe.php',
    'restore_expense' => 'actions/restore_expense.php',
    'save_expense'    => 'actions/save_expense.php',
];

$legacyRedirects = [
    'adicionar_gasto' => 'add_expense',
    'assistente'      => 'assistant',
    'cadastrar'       => 'register',
    'calendario'      => 'calendar',
    'cartoes'         => 'cards',
    'categorias'      => 'categories',
    'contas'          => 'accounts',
    'editar_gasto'    => 'edit_expense',
    'excluir_gasto'   => 'delete_expense',
    'extrato'         => 'statement',
    'importar'        => 'import',
    'investimentos'   => 'investments',
    'metas'           => 'goals',
    'paginalogar'     => 'login',
    'perfil'          => 'profile',
    'recorrencias'    => 'recurring',
    'recuperar'       => 'recover',
    'registro'        => 'register',
    'relatorios'      => 'reports',
    'salvar_gasto'    => 'save_expense',
    'termos'          => 'terms',
    'transferir'      => 'transfer',
];

unset($_GET['route']);

if (isset($legacyRedirects[$route])) {
    $qs = $_GET ? '?' . http_build_query($_GET) : '';
    header("Location: {$legacyRedirects[$route]}{$qs}", true, 301);
    exit;
}

$target = $routes[$route] ?? 'pages/public/404.php';
require __DIR__ . '/' . $target;
