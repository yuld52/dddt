<?php
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

$uuid = trim($_GET['uuid'] ?? '');

if (empty($uuid) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $uuid) || strlen($uuid) > 128) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'UUID inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT status_pagamento FROM vendas WHERE checkout_session_uuid = ? ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute([$uuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno']);
    exit;
}

if (!$row) {
    ob_clean();
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Venda não encontrada']);
    exit;
}

$raw = strtolower($row['status_pagamento']);

if (in_array($raw, ['approved', 'paid', 'pago', 'completed'], true)) {
    $status = 'pago';
} elseif (in_array($raw, ['failed', 'failure', 'falha', 'rejected', 'cancelled', 'canceled'], true)) {
    $status = 'falha';
} else {
    $status = 'pendente';
}

ob_clean();
echo json_encode(['status' => $status]);
