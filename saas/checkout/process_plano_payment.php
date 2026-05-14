<?php
/**
 * Processamento de Pagamento de Planos SaaS
 * Similar ao process_payment.php mas para planos
 */

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function returnJsonError($message, $code = 500) {
    ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

function returnJsonSuccess($data) {
    ob_clean();
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Carregar config
$config_paths = [
    __DIR__ . '/../../config/config.php',
    __DIR__ . '/../../config.php'
];

$config_loaded = false;
foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        try {
            ob_start();
            require $config_path;
            ob_end_clean();
            $config_loaded = true;
            break;
        } catch (Exception $e) {
            ob_end_clean();
            returnJsonError('Erro ao carregar configuração: ' . $e->getMessage(), 500);
        }
    }
}

if (!$config_loaded) {
    returnJsonError('Arquivo de configuração não encontrado.', 500);
}

ob_end_clean();
header('Content-Type: application/json');

// Verificar se usuário está logado
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    returnJsonError('Usuário não autenticado.', 401);
}

// Verificar se é infoprodutor
if ($_SESSION["tipo"] !== 'infoprodutor') {
    returnJsonError('Acesso negado.', 403);
}

$raw_post_data = file_get_contents('php://input');
$data = json_decode($raw_post_data, true);

if (!$data) {
    returnJsonError('Dados inválidos.', 400);
}

// Obter nome e email da sessão se não vierem no POST
$name = $data['name'] ?? $_SESSION['nome'] ?? '';
$email = $data['email'] ?? $_SESSION['usuario'] ?? '';

// Campos obrigatórios (nome e email podem vir da sessão)
$required_fields = ['transaction_amount', 'cpf', 'phone', 'plano_id', 'gateway'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        returnJsonError("Campo obrigatório ausente: $field", 400);
    }
}

// Validar nome e email (devem existir na sessão ou no POST)
if (empty($name)) {
    returnJsonError("Nome não encontrado. Por favor, faça login novamente.", 400);
}

if (empty($email)) {
    returnJsonError("E-mail não encontrado. Por favor, faça login novamente.", 400);
}

$plano_id = intval($data['plano_id']);
$gateway_choice = $data['gateway'] ?? 'mercadopago';

try {
    // Buscar plano
    $stmt_plano = $pdo->prepare("SELECT * FROM saas_planos WHERE id = ? AND ativo = 1");
    $stmt_plano->execute([$plano_id]);
    $plano = $stmt_plano->fetch(PDO::FETCH_ASSOC);
    
    if (!$plano) {
        throw new Exception("Plano não encontrado ou inativo.");
    }
    
    // Buscar credenciais do gateway admin
    $stmt_gateway = $pdo->prepare("SELECT * FROM saas_admin_gateways WHERE gateway = ?");
    $stmt_gateway->execute([$gateway_choice]);
    $gateway_config = $stmt_gateway->fetch(PDO::FETCH_ASSOC);
    
    if (!$gateway_config) {
        throw new Exception("Gateway não configurado no painel admin.");
    }
    
    $usuario_id = $_SESSION['id'];
    $webhook_url = "https://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/../../notification.php';
    
    // Processar conforme gateway
    if ($gateway_choice === 'efi' && $data['payment_method'] === 'pix') {
        require_once __DIR__ . '/../../gateways/efi.php';
        
        $client_id = trim($gateway_config['efi_client_id'] ?? '');
        $client_secret = trim($gateway_config['efi_client_secret'] ?? '');
        $certificate_path = trim($gateway_config['efi_certificate_path'] ?? '');
        $pix_key = trim($gateway_config['efi_pix_key'] ?? '');
        
        if (empty($client_id) || empty($client_secret) || empty($certificate_path) || empty($pix_key)) {
            throw new Exception("Credenciais Efí não configuradas completamente.");
        }
        
        $full_cert_path = __DIR__ . '/../../' . str_replace('\\', '/', $certificate_path);
        if (!file_exists($full_cert_path)) {
            throw new Exception("Certificado Efí não encontrado.");
        }
        
        $token_data = efi_get_access_token($client_id, $client_secret, $full_cert_path);
        if (!$token_data) {
            throw new Exception("Erro ao obter token de acesso Efí.");
        }
        
        $pix_result = efi_create_pix_charge(
            $token_data['access_token'],
            (float)$data['transaction_amount'],
            $pix_key,
            [
                'name' => $name,
                'cpf' => $data['cpf'],
                'email' => $email
            ],
            'Assinatura: ' . $plano['nome'],
            60,
            $full_cert_path
        );
        
        if (!$pix_result || !isset($pix_result['txid'])) {
            throw new Exception("Erro ao criar cobrança Pix na Efí.");
        }
        
        $payment_id = $pix_result['txid'];
        $status = 'pending';
        
        // Criar assinatura
        $data_inicio = date('Y-m-d');
        $dias_periodo = $plano['periodo'] === 'anual' ? 365 : 30;
        $data_vencimento = date('Y-m-d', strtotime("+{$dias_periodo} days"));
        
        $stmt = $pdo->prepare("
            INSERT INTO saas_assinaturas 
            (usuario_id, plano_id, status, data_inicio, data_vencimento, transacao_id, metodo_pagamento) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$usuario_id, $plano_id, $status, $data_inicio, $data_vencimento, $payment_id, 'Pix']);
        
        returnJsonSuccess([
            'status' => 'pix_created',
            'pix_data' => [
                'qr_code_base64' => $pix_result['qr_code_base64'] ?? null,
                'qr_code' => $pix_result['qr_code'] ?? '',
                'payment_id' => $payment_id
            ],
            'redirect_url' => '/index?pagina=saas_planos?payment_id=' . $payment_id
        ]);
        
    } elseif ($gateway_choice === 'pushinpay' && $data['payment_method'] === 'pix') {
        $token = $gateway_config['pushinpay_token'] ?? '';
        if (empty($token)) {
            throw new Exception("Token PushinPay não configurado.");
        }
        
        $amount_cents = (int)(round((float)$data['transaction_amount'], 2) * 100);
            $payload = [
                "value" => $amount_cents,
                "webhook_url" => $webhook_url,
                "payer" => [
                    "name" => $name,
                    "document" => preg_replace('/[^0-9]/', '', $data['cpf']),
                    "email" => $email
                ]
            ];
        
        $ch = curl_init('https://api.pushinpay.com.br/api/pix/cashIn');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $res_data = json_decode($response, true);
        
        if ($http_code >= 200 && $http_code < 300 && isset($res_data['qr_code_base64'])) {
            $payment_id = $res_data['id'] ?? null;
            if (!$payment_id) {
                throw new Exception("Resposta inválida da API PushinPay: ID não encontrado");
            }
            
            $status = 'pending';
            
            // Criar assinatura
            $data_inicio = date('Y-m-d');
            $dias_periodo = $plano['periodo'] === 'anual' ? 365 : 30;
            $data_vencimento = date('Y-m-d', strtotime("+{$dias_periodo} days"));
            
            $stmt = $pdo->prepare("
                INSERT INTO saas_assinaturas 
                (usuario_id, plano_id, status, data_inicio, data_vencimento, transacao_id, metodo_pagamento) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$usuario_id, $plano_id, $status, $data_inicio, $data_vencimento, $payment_id, 'Pix']);
            
            returnJsonSuccess([
                'status' => 'pix_created',
                'pix_data' => [
                    'qr_code_base64' => $res_data['qr_code_base64'],
                    'qr_code' => $res_data['qr_code'] ?? '',
                    'payment_id' => $payment_id
                ],
                'redirect_url' => '/index?pagina=saas_planos?payment_id=' . $payment_id
            ]);
        } else {
            $error_msg = $res_data['message'] ?? 'Erro ao processar pagamento';
            throw new Exception("PushinPay Error ($http_code): " . $error_msg);
        }
        
    } elseif ($gateway_choice === 'mercadopago' && $data['payment_method'] === 'pix') {
        $token = $gateway_config['mp_access_token'] ?? '';
        if (empty($token)) {
            throw new Exception("Token Mercado Pago não configurado.");
        }
        
        $payment_data = [
            'transaction_amount' => (float)$data['transaction_amount'],
            'description' => 'Assinatura: ' . $plano['nome'],
            'payment_method_id' => 'pix',
            'payer' => [
                'email' => $email,
                'first_name' => explode(' ', $name)[0],
                'last_name' => substr(strstr($name, ' '), 1) ?: '',
                'identification' => ['type' => 'CPF', 'number' => preg_replace('/[^0-9]/', '', $data['cpf'])],
            ],
            'notification_url' => $webhook_url
        ];
        
        $ch = curl_init('https://api.mercadopago.com/v1/payments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $res_data = json_decode($response, true);
        
        if ($http_code >= 200 && $http_code < 300 && isset($res_data['status'])) {
            $status = $res_data['status'];
            $payment_id = $res_data['id'];
            
            // Criar assinatura
            $data_inicio = date('Y-m-d');
            $dias_periodo = $plano['periodo'] === 'anual' ? 365 : 30;
            $data_vencimento = date('Y-m-d', strtotime("+{$dias_periodo} days"));
            
            $stmt = $pdo->prepare("
                INSERT INTO saas_assinaturas 
                (usuario_id, plano_id, status, data_inicio, data_vencimento, transacao_id, metodo_pagamento) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$usuario_id, $plano_id, $status, $data_inicio, $data_vencimento, $payment_id, 'Pix']);
            
            if ($status == 'pending') {
                returnJsonSuccess([
                    'status' => 'pix_created',
                    'pix_data' => [
                        'qr_code_base64' => $res_data['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null,
                        'qr_code' => $res_data['point_of_interaction']['transaction_data']['qr_code'] ?? '',
                        'payment_id' => $payment_id
                    ],
                    'redirect_url' => '/index?pagina=saas_planos?payment_id=' . $payment_id
                ]);
            } else {
                returnJsonSuccess([
                    'status' => $status,
                    'payment_id' => $payment_id,
                    'redirect_url' => '/index?pagina=saas_planos?payment_id=' . $payment_id
                ]);
            }
        } else {
            throw new Exception("Mercado Pago Error");
        }
        
    } else {
        throw new Exception("Gateway ou método de pagamento não suportado.");
    }
    
} catch (Exception $e) {
    returnJsonError($e->getMessage(), 500);
} catch (Error $e) {
    returnJsonError('Erro interno do servidor', 500);
}


