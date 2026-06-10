<?php
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

define('E2P_BASE_URL', 'https://api.e2payments.explicador.co.mz');
define('E2P_CLIENT_ID', 'a0e7832c-3310-4d42-a2f9-1f819b542c6d');
define('E2P_CLIENT_SECRET', 'xT29MJ8x70582vo0LSN7AbRu8z4cttaCskdLBmo3');
define('E2P_MPESA_WALLET_ID', '583349');
define('E2P_EMOLA_WALLET_ID', '583349');

function e2p_log($msg) {
    $logfile = __DIR__ . '/e2payments_log.txt';
    file_put_contents($logfile, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

function e2p_get_token() {
    $ch = curl_init(E2P_BASE_URL . '/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'client_id'     => E2P_CLIENT_ID,
            'client_secret' => E2P_CLIENT_SECRET,
            'grant_type'    => 'client_credentials',
        ]),
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        e2p_log("TOKEN cURL error: $curl_err");
        return null;
    }
    if ($http_code !== 200) {
        e2p_log("TOKEN HTTP $http_code: $response");
        return null;
    }
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

function e2p_normalize_phone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 3) === '258') {
        $phone = substr($phone, 3);
    }
    return $phone;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$name                  = trim($input['name'] ?? '');
$email                 = trim($input['email'] ?? '');
$phone                 = trim($input['phone'] ?? '');
$product_id            = intval($input['product_id'] ?? 0);
$amount                = floatval($input['transaction_amount'] ?? $input['amount'] ?? 0);
$method                = strtolower(trim($input['e2p_method'] ?? 'mpesa'));
$checkout_session_uuid = trim($input['checkout_session_uuid'] ?? '');
$order_bump_ids        = $input['order_bump_product_ids'] ?? [];
$utm                   = $input['utm_parameters'] ?? [];

if (!$name || !$email || !$phone || !$product_id || $amount <= 0) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Campos obrigatórios em falta']);
    exit;
}

if (!in_array($method, ['mpesa', 'emola'], true)) {
    $method = 'mpesa';
}

$normalized_phone = e2p_normalize_phone($phone);
if (strlen($normalized_phone) < 8 || strlen($normalized_phone) > 9) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Número de telemóvel inválido. Use o formato 84 123 4567.']);
    exit;
}

if (empty($checkout_session_uuid)) {
    $checkout_session_uuid = 'e2p_' . bin2hex(random_bytes(16));
}

$stmt = $pdo->prepare("SELECT id, nome, preco, usuario_id FROM produtos WHERE id = ?");
$stmt->execute([$product_id]);
$produto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$produto) {
    ob_clean();
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Produto não encontrado']);
    exit;
}

$reference = substr(preg_replace('/[^a-zA-Z0-9]/', '', $checkout_session_uuid), 0, 16);
if (strlen($reference) < 4) {
    $reference = 'pay' . substr(md5($checkout_session_uuid), 0, 12);
}

$amount_int = intval(round($amount));

$scheme       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host         = $_SERVER['HTTP_HOST'] ?? 'localhost';
$callback_url = $scheme . '://' . $host . '/api/e2payments-callback';

$token = e2p_get_token();
if (!$token) {
    ob_clean();
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Erro ao autenticar com E2Payments. Tente novamente.']);
    exit;
}

if ($method === 'emola') {
    $wallet_id = E2P_EMOLA_WALLET_ID;
    $endpoint  = '/v1/c2b/emola-payment/' . $wallet_id;
} else {
    $wallet_id = E2P_MPESA_WALLET_ID;
    $endpoint  = '/v1/c2b/mpesa-payment/' . $wallet_id;
}

$stk_payload = [
    'phone'        => $normalized_phone,
    'amount'       => $amount_int,
    'reference'    => $reference,
    'callback_url' => $callback_url,
];

e2p_log("STK Push [$method] → phone=$normalized_phone amount=$amount_int ref=$reference");

$ch = curl_init(E2P_BASE_URL . $endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ],
    CURLOPT_POSTFIELDS => json_encode($stk_payload),
]);
$stk_response = curl_exec($ch);
$stk_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err     = curl_error($ch);
curl_close($ch);

e2p_log("STK HTTP $stk_code: " . substr($stk_response, 0, 300));

if ($curl_err) {
    ob_clean();
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Erro de ligação com E2Payments: ' . $curl_err]);
    exit;
}

if ($stk_code < 200 || $stk_code >= 300) {
    $stk_data = json_decode($stk_response, true);
    $api_msg  = $stk_data['message'] ?? ($stk_data['error'] ?? 'Erro desconhecido');
    ob_clean();
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Erro ao enviar pedido: ' . $api_msg]);
    exit;
}

$stk_data      = json_decode($stk_response, true);
$initial_status = 'pending';
if (isset($stk_data['status'])) {
    $s = strtolower((string)$stk_data['status']);
    if (in_array($s, ['success', 'successful', 'completed', 'paid', 'approved', 'captured', '1', 'true'], true)) {
        $initial_status = 'pago';
    } elseif (in_array($s, ['failed', 'failure', 'error', 'rejected', '0', 'false'], true)) {
        $initial_status = 'falha';
    }
}

$metodo_label = ($method === 'emola') ? 'e-Mola' : 'M-Pesa';

$products_to_save = array_merge([$product_id], array_map('intval', (array)$order_bump_ids));
$products_to_save = array_unique(array_filter($products_to_save));
$placeholders_ps  = implode(',', array_fill(0, count($products_to_save), '?'));
$stmt_prices      = $pdo->prepare("SELECT id, preco FROM produtos WHERE id IN ($placeholders_ps)");
$stmt_prices->execute($products_to_save);
$prod_map = $stmt_prices->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

$utm_source   = $utm['utm_source']   ?? null;
$utm_campaign = $utm['utm_campaign'] ?? null;
$utm_medium   = $utm['utm_medium']   ?? null;
$utm_content  = $utm['utm_content']  ?? null;
$utm_term     = $utm['utm_term']     ?? null;
$src          = $utm['src']          ?? null;
$sck          = $utm['sck']          ?? null;

try {
    $pdo->beginTransaction();
    $stmt_ins = $pdo->prepare(
        "INSERT INTO vendas
            (produto_id, comprador_nome, comprador_email, comprador_cpf,
             comprador_telefone, valor, status_pagamento, transacao_id,
             metodo_pagamento, checkout_session_uuid, email_entrega_enviado,
             utm_source, utm_campaign, utm_medium, utm_content, utm_term, src, sck)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($products_to_save as $pid) {
        if (!isset($prod_map[$pid])) continue;
        $valor = floatval($prod_map[$pid]['preco']);
        $stmt_ins->execute([
            $pid, $name, $email, $normalized_phone,
            $valor, $initial_status, $reference, $metodo_label,
            $checkout_session_uuid,
            $utm_source, $utm_campaign, $utm_medium, $utm_content, $utm_term, $src, $sck,
        ]);
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    e2p_log("DB insert error: " . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao registar venda']);
    exit;
}

ob_clean();
echo json_encode([
    'success'    => true,
    'status'     => $initial_status,
    'venda_uuid' => $checkout_session_uuid,
    'message'    => 'Pedido enviado para o seu telemóvel. Por favor confirme o pagamento.',
]);
