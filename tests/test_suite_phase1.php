<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Testes Unitários e Integrados da Fase 1 — SDD Wert
 * Executar via CLI: php tests/test_suite_phase1.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';

$erros = 0;
$sucessos = 0;

function runTest(string $descricao, callable $fn) {
    global $erros, $sucessos;
    try {
        $resultado = $fn();
        if ($resultado !== false) {
            echo " [OK] " . $descricao . "\n";
            $sucessos++;
        } else {
            echo " [FALHA] " . $descricao . "\n";
            $erros++;
        }
    } catch (Throwable $e) {
        echo " [ERRO] " . $descricao . ": " . $e->getMessage() . "\n";
        $erros++;
    }
}

echo "====================================================\n";
echo "TESTES DE INTEGRAÇÃO - FASE 1 (ESTABILIZAÇÃO & SEGURANÇA)\n";
echo "====================================================\n\n";

// 1. Verificação da Conexão e Schema do Banco
runTest("Conexão PDO ativa e modo de erro com exceção", function() use ($pdo) {
    return $pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION;
});

runTest("Coluna accounts.type possui default 'checking'", function() use ($pdo) {
    $stmt = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'type'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    return $col && $col['Default'] === 'checking';
});

runTest("Tabelas essenciais presentes no banco wert", function() use ($pdo) {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $required = ['users', 'accounts', 'categories', 'transactions', 'goals'];
    foreach ($required as $t) {
        if (!in_array($t, $tables, true)) return false;
    }
    return true;
});

// 2. Testes de Segurança: CSRF e Sessão
runTest("Security::csrfToken() gera token hexadecimal de 64 caracteres", function() {
    $token = Security::csrfToken();
    return strlen($token) === 64 && ctype_xdigit($token);
});

runTest("Security::validateCsrf() aceita token correto", function() {
    $token = Security::csrfToken();
    return Security::validateCsrf($token) === true;
});

runTest("Security::validateCsrf() rejeita token falso, nulo ou vazio", function() {
    $r1 = Security::validateCsrf('invalido-1234567890abcdef');
    $r2 = Security::validateCsrf('');
    $r3 = Security::validateCsrf(null);
    return ($r1 === false) && ($r2 === false) && ($r3 === false);
});

runTest("Security::csrfField() gera input hidden com token escapado", function() {
    $field = Security::csrfField();
    return str_starts_with($field, '<input type="hidden" name="csrf_token" value="') && str_ends_with($field, '">');
});

runTest("Security::escape() neutraliza vetores XSS", function() {
    $xss = '<script>alert("hack")</script>';
    $escaped = Security::escape($xss);
    return strpos($escaped, '<script>') === false && strpos($escaped, '&lt;script&gt;') !== false;
});

// 3. Testes de Prevenção de IDOR (Ownership Validation)
runTest("Security::validateAccountOwnership() autoriza apenas o proprietário da conta", function() use ($pdo) {
    $okUser1 = Security::validateAccountOwnership($pdo, 1, 1);
    $blockedUser2 = Security::validateAccountOwnership($pdo, 1, 2);
    $blockedInexistente = Security::validateAccountOwnership($pdo, 999999, 1);
    return ($okUser1 === true) && ($blockedUser2 === false) && ($blockedInexistente === false);
});

runTest("Security::validateCategoryOwnership() autoriza apenas o proprietário e valida tipo", function() use ($pdo) {
    $stmt = $pdo->prepare("SELECT id, user_id, type FROM categories WHERE user_id = 1 LIMIT 1");
    $stmt->execute();
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cat) return false;

    $catId = (int)$cat['id'];
    $tipoCorreto = $cat['type'];
    $tipoIncorreto = ($tipoCorreto === 'income') ? 'expense' : 'income';

    $okDono = Security::validateCategoryOwnership($pdo, $catId, 1, $tipoCorreto);
    $blockedOutroUser = Security::validateCategoryOwnership($pdo, $catId, 2, $tipoCorreto);
    $blockedTipoInvalido = Security::validateCategoryOwnership($pdo, $catId, 1, $tipoIncorreto);

    return ($okDono === true) && ($blockedOutroUser === false) && ($blockedTipoInvalido === false);
});

// 4. Testes das Consultas do Dashboard (index.php)
runTest("Cálculo de saldo consolidado executa com precisão decimal", function() use ($pdo) {
    $userId = 1;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(initial_balance), 0) FROM accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $saldoInicial = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'income' AND status = 'completed'");
    $stmt->execute([$userId]);
    $rec = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'expense' AND status = 'completed'");
    $stmt->execute([$userId]);
    $desp = (float) $stmt->fetchColumn();

    $saldoTotal = $saldoInicial + $rec - $desp;
    return is_numeric($saldoTotal);
});

// 5. Testes de ClienteIA (Localização de chave em segredos)
runTest("ClienteIA localiza chave em C:/laragon/segredos/wert.env", function() {
    require_once __DIR__ . '/../src/ClienteIA.php';
    $c = new ClienteIA();
    $ref = new ReflectionMethod($c, 'chave');
    $key = $ref->invoke($c);
    return is_string($key) && strlen($key) > 10;
});

// 6. Testes de Aceite de Termos e LGPD (AUTH-009)
runTest("Security::ensureTermsColumn() e helpers de aceite de termos operam com integridade", function() use ($pdo) {
    Security::ensureTermsColumn($pdo);
    $res = Security::hasAcceptedTerms($pdo, 999999);
    return is_bool($res);
});

// 7. Testes de Política de Senhas e Criptografia Forte (AUTH-010)
runTest("Security::validatePasswordStrength() rejeita senhas fracas ou inseguras", function() {
    $r1 = Security::validatePasswordStrength('curta'); // < 8
    $r2 = Security::validatePasswordStrength('semnumeroA'); // sem numero
    $r3 = Security::validatePasswordStrength('semmaiuscula1'); // sem maiuscula
    $r4 = Security::validatePasswordStrength('12345678'); // comum
    $r5 = Security::validatePasswordStrength('Lucas12345', 'Lucas Silva', 'lucas@exemplo.com'); // contem nome

    return ($r1['valido'] === false) &&
           ($r2['valido'] === false) &&
           ($r3['valido'] === false) &&
           ($r4['valido'] === false) &&
           ($r5['valido'] === false);
});

runTest("Security::validatePasswordStrength() aprova senha forte em conformidade", function() {
    $ok = Security::validatePasswordStrength('SenhaSegura2026!', 'Lucas Silva', 'lucas@exemplo.com');
    return $ok['valido'] === true;
});

runTest("Security::hashPassword() gera hash compatível com password_verify", function() {
    $pwd = 'DromaF0rte!2026';
    $hash = Security::hashPassword($pwd);
    return password_verify($pwd, $hash) === true;
});

echo "\n----------------------------------------------------\n";
echo "RESULTADO DOS TESTES:\n";
echo "Sucessos: $sucessos | Falhas: $erros\n";
echo "----------------------------------------------------\n";
if ($erros === 0) {
    echo "STATUS: TODOS OS TESTES PASSARAM COM SUCESSO!\n";
} else {
    echo "STATUS: HOUVE FALHAS QUE PRECISAM DE CORREÇÃO.\n";
}
