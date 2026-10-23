<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

// Exclusão permitida estritamente via método POST para prevenir ataques via GET/CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!Security::validateCsrf($csrfToken)) {
    die("Ação não autorizada ou sessão expirada. Por favor, atualize a página.");
}

$id = $_POST['id'] ?? 0;

if (ctype_digit((string)$id) && (int)$id > 0) {
    try {
        $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
        $stmt->execute([(int)$id, $userId]);
    } catch (PDOException $e) {
        error_log("Erro ao excluir lançamento: " . $e->getMessage());
    }
}

$origem = $_POST['origem'] ?? '';
if ($origem === 'calendario') {
    $redirect = "calendario.php?sucesso=" . urlencode("Lançamento excluído com sucesso!");
    if (!empty($_POST['mes']) && !empty($_POST['ano'])) {
        $redirect .= "&mes=" . (int)$_POST['mes'] . "&ano=" . (int)$_POST['ano'];
    }
    header("Location: " . $redirect);
} elseif ($origem === 'extrato') {
    header("Location: extrato.php?sucesso=" . urlencode("Lançamento excluído com sucesso!"));
} else {
    header("Location: index.php?sucesso=" . urlencode("Lançamento excluído com sucesso!"));
}
exit;
