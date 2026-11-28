<?php
require_once "src/Security.php";
require_once "config/database.php";

$userId = Security::requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        Security::recordTermsAcceptance($pdo, $userId);
        $_SESSION['terms_accepted_at'] = date('Y-m-d H:i:s');
    }
}

$retorno = (string)($_POST['redirect_to'] ?? './');
if (
    empty($retorno) 
    || str_starts_with($retorno, '//') 
    || str_starts_with($retorno, '\\')
    || preg_match('#^[a-z0-9+.-]+://#i', $retorno)
    || !preg_match('#^(\./|[a-zA-Z0-9_\-\./]+$)#', $retorno)
) {
    $retorno = './';
}

header("Location: " . $retorno);
exit;
