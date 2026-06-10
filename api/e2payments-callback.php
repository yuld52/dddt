<?php
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

function e2p_cb_log($msg) {
    $logfile = __DIR__ . '/e2payments_log.txt';
    file_put_contents($logfile, date('Y-m-d H:i:s') . ' [callback] ' . $msg . "\n", FILE_APPEND);
}

require_once __DIR__ . '/../config/config.php';

if (file_exists(__DIR__ . '/../helpers/webhook_helper.php'))
    require_once __DIR__ . '/../helpers/webhook_helper.php';
if (file_exists(__DIR__ . '/../helpers/utmfy_helper.php'))
    require_once __DIR__ . '/../helpers/utmfy_helper.php';
if (file_exists(__DIR__ . '/../helpers/push_pedidos_helper.php'))
    require_once __DIR__ . '/../helpers/push_pedidos_helper.php';
if (file_exists(__DIR__ . '/../helpers/password_setup_helper.php'))
    require_once __DIR__ . '/../helpers/password_setup_helper.php';
if (file_exists(__DIR__ . '/../helpers/security_helper.php'))
    require_once __DIR__ . '/../helpers/security_helper.php';

$phpmailer_path = __DIR__ . '/../PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) {
    require_once $phpmailer_path . 'Exception.php';
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
}
if (file_exists(__DIR__ . '/../helpers/email_template_helper.php'))
    require_once __DIR__ . '/../helpers/email_template_helper.php';

$raw_body = file_get_contents('php://input');
e2p_cb_log("Received: " . substr($raw_body, 0, 500));

$data = json_decode($raw_body, true);
if (!$data) {
    parse_str($raw_body, $data);
}

$reference = $data['reference'] ?? $data['transactionReference'] ?? $data['transactionID'] ?? $data['transaction_id'] ?? null;
$status_raw = $data['status'] ?? $data['resultCode'] ?? $data['ResponseCode'] ?? null;

if (!$reference) {
    e2p_cb_log("No reference found in callback body");
    ob_clean();
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

e2p_cb_log("Reference=$reference status_raw=" . json_encode($status_raw));

$s = strtolower((string)($status_raw ?? ''));
if (in_array($s, ['success', 'successful', 'completed', 'paid', 'approved', 'captured', '1', 'true', '0'], true)) {
    $new_status = 'pago';
} else {
    $new_status = 'falha';
}

$stmt_find = $pdo->prepare("SELECT id, status_pagamento, checkout_session_uuid FROM vendas WHERE transacao_id = ? LIMIT 1");
$stmt_find->execute([$reference]);
$venda = $stmt_find->fetch(PDO::FETCH_ASSOC);

if (!$venda) {
    e2p_cb_log("No venda found for reference=$reference");
    ob_clean();
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$prev_status = $venda['status_pagamento'];

if ($prev_status === $new_status) {
    e2p_cb_log("Status unchanged ($new_status) for reference=$reference — skipping");
    ob_clean();
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$pdo->prepare("UPDATE vendas SET status_pagamento = ? WHERE transacao_id = ?")->execute([$new_status, $reference]);
e2p_cb_log("Updated reference=$reference status $prev_status → $new_status");

if ($new_status !== 'pago') {
    ob_clean();
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$checkout_session_uuid = $venda['checkout_session_uuid'];

$stmt_all = $pdo->prepare("
    SELECT v.*, p.usuario_id, p.nome AS produto_nome, p.tipo_entrega,
           p.conteudo_entrega, p.checkout_config, p.checkout_hash
    FROM vendas v
    JOIN produtos p ON v.produto_id = p.id
    WHERE v.checkout_session_uuid = ?
");
$stmt_all->execute([$checkout_session_uuid]);
$all_sales = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

if (empty($all_sales)) {
    e2p_cb_log("No sales found for checkout_session_uuid=$checkout_session_uuid");
    ob_clean();
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$main_sale  = $all_sales[0];
$usuario_id = $main_sale['usuario_id'];

$valor_total = array_sum(array_column($all_sales, 'valor'));

$products_payload = [];
foreach ($all_sales as $s) {
    $products_payload[] = [
        'produto_id' => $s['produto_id'],
        'nome'       => $s['produto_nome'] ?? 'Produto',
        'valor'      => (float)$s['valor'],
    ];
}

$utm_payload = [
    'utm_source'   => $main_sale['utm_source']   ?? null,
    'utm_campaign' => $main_sale['utm_campaign'] ?? null,
    'utm_medium'   => $main_sale['utm_medium']   ?? null,
    'utm_content'  => $main_sale['utm_content']  ?? null,
    'utm_term'     => $main_sale['utm_term']     ?? null,
    'src'          => $main_sale['src']          ?? null,
    'sck'          => $main_sale['sck']          ?? null,
];

$webhook_payload = [
    'transacao_id'       => $reference,
    'status_pagamento'   => 'approved',
    'valor_total_compra' => $valor_total,
    'comprador'          => [
        'nome'      => $main_sale['comprador_nome'],
        'email'     => $main_sale['comprador_email'],
        'cpf'       => $main_sale['comprador_cpf'] ?? '',
        'telefone'  => $main_sale['comprador_telefone'],
    ],
    'metodo_pagamento'   => $main_sale['metodo_pagamento'],
    'produtos_comprados' => $products_payload,
    'data_venda'         => $main_sale['data_venda'] ?? date('Y-m-d H:i:s'),
    'utm_parameters'     => $utm_payload,
];

if (function_exists('trigger_webhooks')) {
    try { trigger_webhooks($usuario_id, $webhook_payload, 'approved', $main_sale['produto_id']); }
    catch (Exception $e) { e2p_cb_log("webhook error: " . $e->getMessage()); }
}
if (function_exists('trigger_utmfy_integrations')) {
    try { trigger_utmfy_integrations($usuario_id, $webhook_payload, 'approved', $main_sale['produto_id']); }
    catch (Exception $e) { e2p_cb_log("utmfy error: " . $e->getMessage()); }
}
if (function_exists('trigger_push_pedidos_notifications')) {
    try { trigger_push_pedidos_notifications($usuario_id, $webhook_payload, 'approved', $main_sale['produto_id']); }
    catch (Exception $e) { e2p_cb_log("push error: " . $e->getMessage()); }
}
if (function_exists('create_notification')) {
    $msg = "Venda Aprovada! MZN " . number_format($valor_total, 2, ',', '.');
    try { create_notification($usuario_id, 'Compra Aprovada', $msg, $valor_total, $main_sale['id'], $main_sale['metodo_pagamento']); }
    catch (Exception $e) { e2p_cb_log("notification error: " . $e->getMessage()); }
}

if ((int)$main_sale['email_entrega_enviado'] === 0 && function_exists('send_delivery_email_consolidated')) {
    $processed_prods = [];
    $pass            = null;
    $setup_token     = null;

    foreach ($all_sales as $sale) {
        if (function_exists('process_single_product_delivery')) {
            $res = process_single_product_delivery($sale, $sale['comprador_email']);
            if (!empty($res['success'])) {
                if (($res['content_type'] ?? '') === 'area_membros') {
                    $stmt_chk = $pdo->prepare("SELECT id, senha FROM usuarios WHERE usuario = ? AND tipo = 'usuario'");
                    $stmt_chk->execute([$sale['comprador_email']]);
                    $existing = $stmt_chk->fetch(PDO::FETCH_ASSOC);
                    if (!$existing) {
                        $tmp_pw = bin2hex(random_bytes(32));
                        $hashed = password_hash($tmp_pw, PASSWORD_DEFAULT);
                        try {
                            $pdo->prepare("INSERT INTO usuarios (usuario, nome, senha, tipo) VALUES (?, ?, ?, 'usuario')")->execute([
                                $sale['comprador_email'], $sale['comprador_nome'], $hashed
                            ]);
                            $new_uid = $pdo->lastInsertId();
                            if ($new_uid && function_exists('generate_setup_token')) {
                                $setup_token = generate_setup_token($new_uid);
                            }
                        } catch (PDOException $e) {
                            e2p_cb_log("user insert error: " . $e->getMessage());
                        }
                    }
                }
                $processed_prods[] = $res;
            }
        }
    }

    if (!empty($processed_prods)) {
        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $login_url = $protocol . '://' . $host . '/member_login';

        try {
            $lu_stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'member_area_login_url'");
            $lu_val  = $lu_stmt ? $lu_stmt->fetchColumn() : null;
            if (!empty($lu_val) && $lu_val !== '#') $login_url = $lu_val;
        } catch (Exception $e) {}

        try {
            send_delivery_email_consolidated(
                $main_sale['comprador_email'],
                $main_sale['comprador_nome'],
                $processed_prods, $pass, $login_url, null, $setup_token
            );
            $pdo->prepare("UPDATE vendas SET email_entrega_enviado = 1 WHERE checkout_session_uuid = ?")
                ->execute([$checkout_session_uuid]);
        } catch (Exception $e) {
            e2p_cb_log("email error: " . $e->getMessage());
        }
    }
}

ob_clean();
http_response_code(200);
echo json_encode(['received' => true, 'status' => 'processed']);
