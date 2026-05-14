<?php
// Aplicar headers de segurança antes de qualquer output
require_once __DIR__ . '/../config/security_headers.php';
if (function_exists('apply_security_headers')) {
    apply_security_headers(false); // CSP permissivo para APIs
}

header('Content-Type: application/json');
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/security_helper.php';

// Verificação CSRF obrigatória para requisições POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = null;
    
    // Tenta obter token de diferentes fontes
    if (isset($_POST['csrf_token'])) {
        $csrf_token = $_POST['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['csrf_token'])) {
            $csrf_token = $input['csrf_token'];
        }
    }
    
    // CSRF é obrigatório para processamento de pagamento
    if (empty($csrf_token)) {
        log_security_event('missing_csrf_token', [
            'endpoint' => '/api/process_payment.php',
            'ip' => get_client_ip(),
            'method' => 'POST'
        ]);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF ausente']);
        exit;
    }
    
    if (!verify_csrf_token($csrf_token)) {
        log_security_event('invalid_csrf_token', [
            'endpoint' => '/api/process_payment.php',
            'ip' => get_client_ip(),
            'method' => 'POST'
        ]);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
        exit;
    }
}

// Inclui o helper da UTMfy
if (file_exists('utmfy_helper.php')) {
    require_once __DIR__ . '/helpers/utmfy_helper.php';
}

// Inclui o helper de push para pedidos
if (file_exists(__DIR__ . '/../helpers/push_pedidos_helper.php')) {
    require_once __DIR__ . '/../helpers/push_pedidos_helper.php';
} elseif (file_exists(__DIR__ . '/helpers/push_pedidos_helper.php')) {
    require_once __DIR__ . '/helpers/push_pedidos_helper.php';
}

// Inclui o helper de webhooks
if (file_exists(__DIR__ . '/../helpers/webhook_helper.php')) {
    require_once __DIR__ . '/../helpers/webhook_helper.php';
}

// Ativa o log de erros detalhado
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../process_payment_log.txt');

function log_process($msg) {
    require_once __DIR__ . '/../helpers/security_helper.php';
    $log_file = __DIR__ . '/../process_payment_log.txt';
    secure_log($log_file, $msg);
}

// Função para traduzir mensagens de erro do Mercado Pago
function getMercadoPagoErrorMessage($status, $status_detail, $custom_message = null) {
    if ($custom_message) {
        return $custom_message;
    }
    
    $messages = [
        'rejected' => [
            'cc_rejected_insufficient_amount' => 'Saldo insuficiente no cartão. Tente outro cartão ou método de pagamento.',
            'cc_rejected_bad_filled_security_code' => 'Código de segurança (CVV) incorreto. Verifique e tente novamente.',
            'cc_rejected_bad_filled_date' => 'Data de validade do cartão incorreta. Verifique e tente novamente.',
            'cc_rejected_bad_filled_card_number' => 'Número do cartão incorreto. Verifique e tente novamente.',
            'cc_rejected_high_risk' => 'Pagamento recusado por medidas de segurança. Tente outro cartão ou método de pagamento.',
            'cc_rejected_blacklist' => 'Cartão não autorizado. Tente outro cartão ou método de pagamento.',
            'cc_rejected_other_reason' => 'Pagamento recusado. Tente outro cartão ou método de pagamento.',
        ],
        'cancelled' => 'Pagamento cancelado. Você pode tentar novamente ou escolher outro método de pagamento.',
        'refunded' => 'Pagamento reembolsado.',
        'charged_back' => 'Pagamento contestado.',
    ];
    
    if ($status === 'rejected' && $status_detail && isset($messages['rejected'][$status_detail])) {
        return $messages['rejected'][$status_detail];
    }
    
    if (isset($messages[$status])) {
        return is_string($messages[$status]) ? $messages[$status] : 'Pagamento recusado. Tente outro método de pagamento.';
    }
    
    return 'Pagamento não aprovado. Tente outro método de pagamento.';
}

// Rate limiting para prevenir abuso
$client_ip = get_client_ip();
$rate_check = check_rate_limit_db('process_payment', 30, 60, $client_ip); // 30 requisições por minuto

if (!$rate_check['allowed']) {
    log_security_event('rate_limit_exceeded', [
        'ip' => $client_ip,
        'endpoint' => '/api/process_payment.php',
        'reset_at' => $rate_check['reset_at'] ?? null
    ]);
    http_response_code(429);
    echo json_encode(['error' => 'Muitas requisições. Aguarde alguns instantes antes de tentar novamente.']);
    exit;
}

// Limitar tamanho da requisição (prevenção de DoS) - reduzido para 512KB
$max_request_size = 512 * 1024; // 512KB
$content_length = $_SERVER['CONTENT_LENGTH'] ?? 0;
if ($content_length > $max_request_size) {
    log_security_event('request_too_large', [
        'ip' => $client_ip,
        'size' => $content_length,
        'max' => $max_request_size
    ]);
    http_response_code(413);
    echo json_encode(['error' => 'Requisição muito grande.']);
    exit;
}

log_process("INÍCIO DO PROCESSAMENTO");

$raw_post_data = file_get_contents('php://input');
$data = json_decode($raw_post_data, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados inválidos.']);
    exit;
}

// Campos comuns
$required_fields = ['transaction_amount', 'email', 'cpf', 'name', 'phone', 'product_id'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Campo obrigatório ausente: $field"]);
        exit;
    }
}

// Carrega helper de validação
require_once __DIR__ . '/../helpers/validation_helper.php';

// Validações de dados de pagamento
if (!validate_email($data['email'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email inválido.']);
    exit;
}

if (!validate_cpf($data['cpf'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CPF inválido.']);
    exit;
}

if (!validate_phone_br($data['phone'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Telefone inválido.']);
    exit;
}

if (!validate_transaction_amount($data['transaction_amount'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Valor da transação inválido. Deve estar entre R$ 0,01 e R$ 100.000,00.']);
    exit;
}

// Sanitizar inputs
$data['name'] = sanitize_input($data['name'] ?? '');
$data['email'] = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$data['cpf'] = preg_replace('/[^0-9]/', '', $data['cpf']);
$data['phone'] = preg_replace('/[^0-9]/', '', $data['phone']);

// 1. Descobrir Gateway e Credenciais
$main_product_id = $data['product_id'];
$gateway_choice = $data['gateway'] ?? 'mercadopago';

try {
    $stmt_prod = $pdo->prepare("SELECT usuario_id, nome FROM produtos WHERE id = ?");
    $stmt_prod->execute([$main_product_id]);
    $product_info = $stmt_prod->fetch(PDO::FETCH_ASSOC);
    if (!$product_info) throw new Exception("Produto não encontrado.");
    
    $usuario_id = $product_info['usuario_id'];
    $main_product_name = $product_info['nome'];

    $stmt_user = $pdo->prepare("SELECT mp_access_token, pushinpay_token, efi_client_id, efi_client_secret, efi_certificate_path, efi_pix_key, efi_payee_code, beehive_secret_key, beehive_public_key FROM usuarios WHERE id = ?");
    $stmt_user->execute([$usuario_id]);
    $credentials = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    // Log para debug - verificar se credenciais foram buscadas
    log_process("Efí: Usuario ID: $usuario_id");
    if ($credentials) {
        log_process("Efí: Credenciais encontradas no banco");
        log_process("Efí: efi_client_id presente: " . (!empty($credentials['efi_client_id']) ? 'sim (' . substr($credentials['efi_client_id'], 0, 8) . '...)' : 'não'));
        log_process("Efí: efi_client_secret presente: " . (!empty($credentials['efi_client_secret']) ? 'sim' : 'não'));
        log_process("Efí: efi_certificate_path: " . ($credentials['efi_certificate_path'] ?? 'vazio'));
        log_process("Efí: efi_pix_key presente: " . (!empty($credentials['efi_pix_key']) ? 'sim' : 'não'));
    } else {
        log_process("Efí: ERRO - Credenciais não encontradas no banco para usuario_id: $usuario_id");
    }
    
    // URL Webhook
    $domainName = $_SERVER['HTTP_HOST'];
    $scriptDir = dirname($_SERVER['PHP_SELF']);
    $path = rtrim(str_replace('\\', '/', $scriptDir), '/');
    $webhook_url = "https://" . $domainName . $path . '/notification.php';
    
    // URL Obrigado
    $stmt_prod_conf = $pdo->prepare("SELECT checkout_config FROM produtos WHERE id = ?");
    $stmt_prod_conf->execute([$main_product_id]);
    $p_conf = $stmt_prod_conf->fetch(PDO::FETCH_ASSOC);
    $checkout_config = json_decode($p_conf['checkout_config'] ?? '{}', true);
    
    // Incluir helper de segurança para validação SSRF
    require_once __DIR__ . '/../helpers/security_helper.php';
    
    // Função para validar e limpar URL, removendo caminhos absolutos e validando SSRF
    $clean_redirect_url = function($url) use ($domainName, $path) {
        if (empty($url)) {
            return '/obrigado.php'; // Sempre usar caminho relativo
        }
        // Se contém caminho absoluto do sistema de arquivos, ignorar
        if (preg_match('/^[A-Z]:[\\\\\/]/i', $url) || strpos($url, 'C:/') !== false || strpos($url, 'C:\\') !== false || strpos($url, 'xampp') !== false || strpos($url, 'htdocs') !== false) {
            return '/obrigado.php'; // Sempre usar caminho relativo
        }
        // Se é uma URL HTTP/HTTPS válida, validar SSRF antes de usar
        if (preg_match('/^https?:\/\//', $url)) {
            $ssrf_validation = validate_url_for_ssrf($url);
            if (!$ssrf_validation['valid']) {
                log_security_event('ssrf_blocked_redirect_url', [
                    'url' => $url,
                    'ip' => get_client_ip(),
                    'error' => $ssrf_validation['error']
                ]);
                return '/obrigado.php'; // Bloquear e usar URL padrão
            }
            return $url;
        }
        // Se começa com /, é um caminho relativo válido
        if (strpos($url, '/') === 0) {
            return $url;
        }
        // Caso contrário, usar caminho relativo padrão
        return '/obrigado.php';
    };
    
    $redirect_url_raw = $checkout_config['redirectUrl'] ?? '';
    $redirect_url_after_approval = $clean_redirect_url($redirect_url_raw);

    log_process("Webhook URL gerada: " . $webhook_url);
    $checkout_session_uuid = uniqid('checkout_') . bin2hex(random_bytes(8));
    
    // UTMs
    $utm_parameters = $data['utm_parameters'] ?? [];

    // ==========================================================
    // FLUXO EFÍ
    // ==========================================================
    if ($gateway_choice === 'efi') {
        // Incluir arquivo do gateway Efí
        require_once __DIR__ . '/../gateways/efi.php';
        
        // Remover espaços em branco e caracteres invisíveis das credenciais
        $client_id = trim($credentials['efi_client_id'] ?? '');
        $client_secret = trim($credentials['efi_client_secret'] ?? '');
        $certificate_path = trim($credentials['efi_certificate_path'] ?? '');
        $pix_key = trim($credentials['efi_pix_key'] ?? '');
        
        // Log detalhado antes de processar
        log_process("Efí: Iniciando processamento de pagamento");
        log_process("Efí: Client ID presente: " . (!empty($client_id) ? 'sim (tamanho: ' . strlen($client_id) . ')' : 'não'));
        log_process("Efí: Client Secret presente: " . (!empty($client_secret) ? 'sim (tamanho: ' . strlen($client_secret) . ')' : 'não'));
        log_process("Efí: Caminho certificado (relativo): " . $certificate_path);
        log_process("Efí: Chave Pix presente: " . (!empty($pix_key) ? 'sim' : 'não'));
        
        if (empty($client_id) || empty($client_secret) || empty($certificate_path) || empty($pix_key)) {
            $missing = [];
            if (empty($client_id)) $missing[] = 'Client ID';
            if (empty($client_secret)) $missing[] = 'Client Secret';
            if (empty($certificate_path)) $missing[] = 'Caminho do Certificado';
            if (empty($pix_key)) $missing[] = 'Chave Pix';
            log_process("Efí: Credenciais faltando: " . implode(', ', $missing));
            http_response_code(400);
            echo json_encode(['error' => 'Credenciais Efí não configuradas completamente. Verifique as configurações do gateway.']);
            exit;
        }
        
        // Validar se certificado existe
        // Normalizar caminho (Windows usa \, mas precisamos de / para cURL)
        $certificate_path_normalized = str_replace('\\', '/', $certificate_path);
        $full_cert_path = dirname(__DIR__) . '/' . $certificate_path_normalized;
        // Normalizar também o caminho completo para Windows
        $full_cert_path = str_replace('\\', '/', $full_cert_path);
        log_process("Efí: Caminho completo do certificado (normalizado): " . $full_cert_path);
        
        if (!file_exists($full_cert_path)) {
            log_process("Efí: Certificado não encontrado no caminho: " . $full_cert_path);
            log_process("Efí: Diretório atual: " . dirname(__DIR__));
            log_process("Efí: Caminho relativo do banco: " . $certificate_path);
            http_response_code(400);
            echo json_encode(['error' => 'Certificado Efí não encontrado. Verifique o upload do certificado nas configurações.']);
            exit;
        }
        
        log_process("Efí: Certificado encontrado, obtendo token de acesso...");
        
        // Obter access token
        $token_data = efi_get_access_token($client_id, $client_secret, $full_cert_path);
        if (!$token_data) {
            log_process("Efí: Falha ao obter token de acesso");
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao obter token de acesso Efí (401 - Invalid credentials). Verifique: 1) Se o Client ID e Client Secret estão corretos na conta Efí, 2) Se o certificado P12 corresponde a essas credenciais, 3) Se as credenciais estão ativas. Consulte os logs para mais detalhes.']);
            exit;
        }
        
        log_process("Efí: Token obtido com sucesso");
        
        $access_token = $token_data['access_token'];
        
        // Criar cobrança Pix
        $payer_data = [
            'name' => $data['name'],
            'cpf' => $data['cpf'],
            'email' => $data['email']
        ];
        
        $pix_result = efi_create_pix_charge(
            $access_token,
            (float)$data['transaction_amount'],
            $pix_key,
            $payer_data,
            'Compra: ' . $main_product_name,
            60, // 60 minutos de expiração
            $full_cert_path // Passar certificado para mutual TLS
        );
        
        if (!$pix_result || !isset($pix_result['txid'])) {
            // Verificar se o erro foi relacionado a CPF inválido
            if (isset($pix_result['error']) && $pix_result['error']) {
                $error_msg = $pix_result['message'] ?? 'Erro ao criar cobrança Pix na Efí';
                // Mensagens específicas para CPF inválido
                if (stripos($error_msg, 'cpf') !== false || stripos($error_msg, 'documento') !== false) {
                    log_process("Efí: CPF inválido - " . $error_msg);
                    http_response_code(400);
                    echo json_encode(['error' => 'CPF inválido. Por favor, verifique o CPF informado.']);
                    exit;
                }
                log_process("Efí: Erro ao criar cobrança - " . $error_msg);
                http_response_code(500);
                echo json_encode(['error' => $error_msg]);
                exit;
            }
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar cobrança Pix na Efí.']);
            exit;
        }
        
        $payment_id = $pix_result['txid'];
        $status = 'pending';
        
        // Salva Venda
        save_sales($pdo, $data, $main_product_id, $payment_id, $status, 'Pix', $checkout_session_uuid, $utm_parameters);
        
        // --- DISPARO IMEDIATO PARA UTMFY (Status: Waiting Payment) ---
        if (function_exists('trigger_utmfy_integrations')) {
            $event_data_utmfy = [
                'transacao_id' => $payment_id,
                'valor_total_compra' => $data['transaction_amount'],
                'comprador' => [
                    'nome' => $data['name'], 'email' => $data['email'], 
                    'telefone' => $data['phone'], 'cpf' => $data['cpf']
                ],
                'metodo_pagamento' => 'Pix',
                'produtos_comprados' => [[
                    'produto_id' => $main_product_id, 'nome' => $main_product_name, 'valor' => $data['transaction_amount']
                ]],
                'utm_parameters' => $utm_parameters,
                'data_venda' => date('Y-m-d H:i:s')
            ];
            trigger_utmfy_integrations($usuario_id, $event_data_utmfy, 'pending', $main_product_id);
        }
        // -------------------------------------------------------------
        
        // --- DISPARO IMEDIATO PARA PUSH NOTIFICATIONS (Status: Waiting Payment) ---
        if (function_exists('trigger_push_pedidos_notifications') && isset($event_data_utmfy)) {
            trigger_push_pedidos_notifications($usuario_id, $event_data_utmfy, 'pending', $main_product_id);
        }
        // -------------------------------------------------------------
        
        // --- DISPARO IMEDIATO DE WEBHOOK PARA PIX PENDENTE ---
        if (function_exists('trigger_webhooks')) {
            $webhook_payload = [
                'transacao_id' => $payment_id,
                'status_pagamento' => 'pending',
                'valor_total_compra' => $data['transaction_amount'],
                'comprador' => [
                    'email' => $data['email'],
                    'nome' => $data['name'],
                    'cpf' => $data['cpf'],
                    'telefone' => $data['phone']
                ],
                'metodo_pagamento' => 'Pix',
                'produtos_comprados' => [[
                    'produto_id' => $main_product_id,
                    'nome' => $main_product_name,
                    'valor' => $data['transaction_amount']
                ]],
                'utm_parameters' => $utm_parameters,
                'data_venda' => date('Y-m-d H:i:s')
            ];
            trigger_webhooks($usuario_id, $webhook_payload, 'pending', $main_product_id);
        }
        // -------------------------------------------------------------
        
        echo json_encode([
            'status' => 'pix_created',
            'pix_data' => [
                'qr_code_base64' => $pix_result['qr_code_base64'] ?? null,
                'qr_code' => $pix_result['qr_code'] ?? '',
                'payment_id' => $payment_id
            ],
            'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
        ]);
        exit;
    }
    
    // ==========================================================
    // FLUXO PUSHINPAY
    // ==========================================================
    elseif ($gateway_choice === 'pushinpay') {
        
        $token = $credentials['pushinpay_token'] ?? '';
        if (empty($token)) throw new Exception("Token PushinPay não configurado.");

        $amount_cents = (int)(round((float)$data['transaction_amount'], 2) * 100);
        $payload = [
            "value" => $amount_cents,
            "webhook_url" => $webhook_url,
            "payer" => [
                 "name" => $data['name'],
                 "document" => preg_replace('/[^0-9]/', '', $data['cpf']),
                 "email" => $data['email']
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
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        log_process("PushinPay Response HTTP Code: $http_code");
        log_process("PushinPay Response: " . substr($response, 0, 500));
        
        if ($curl_error) {
            log_process("PushinPay cURL Error: " . $curl_error);
            throw new Exception("Erro de conexão com PushinPay: " . $curl_error);
        }
        
        $res_data = json_decode($response, true);
        
        if ($http_code >= 200 && $http_code < 300 && isset($res_data['qr_code_base64'])) {
            $payment_id = $res_data['id'] ?? null;
            if (!$payment_id) {
                log_process("PushinPay: Resposta sem ID de pagamento");
                throw new Exception("Resposta inválida da API PushinPay: ID não encontrado");
            }
            
            $status = 'pending';
            
            // Salva Venda
            save_sales($pdo, $data, $main_product_id, $payment_id, $status, 'Pix', $checkout_session_uuid, $utm_parameters);

            // --- DISPARO IMEDIATO PARA UTMFY (Status: Waiting Payment) ---
            if (function_exists('trigger_utmfy_integrations')) {
                // Monta estrutura de evento compatível
                $event_data_utmfy = [
                    'transacao_id' => $payment_id,
                    'valor_total_compra' => $data['transaction_amount'],
                    'comprador' => [
                        'nome' => $data['name'], 'email' => $data['email'], 
                        'telefone' => $data['phone'], 'cpf' => $data['cpf']
                    ],
                    'metodo_pagamento' => 'Pix',
                    'produtos_comprados' => [[
                        'produto_id' => $main_product_id, 'nome' => $main_product_name, 'valor' => $data['transaction_amount']
                    ]],
                    'utm_parameters' => $utm_parameters,
                    'data_venda' => date('Y-m-d H:i:s')
                ];
                trigger_utmfy_integrations($usuario_id, $event_data_utmfy, 'pending', $main_product_id);
            }
            // -------------------------------------------------------------
            
            // --- DISPARO IMEDIATO PARA PUSH NOTIFICATIONS (Status: Waiting Payment) ---
            if (function_exists('trigger_push_pedidos_notifications') && isset($event_data_utmfy)) {
                trigger_push_pedidos_notifications($usuario_id, $event_data_utmfy, 'pending', $main_product_id);
            }
            // -------------------------------------------------------------
            
            // --- DISPARO IMEDIATO DE WEBHOOK PARA PIX PENDENTE ---
            if (function_exists('trigger_webhooks')) {
                $webhook_payload = [
                    'transacao_id' => $payment_id,
                    'status_pagamento' => 'pending',
                    'valor_total_compra' => $data['transaction_amount'],
                    'comprador' => [
                        'email' => $data['email'],
                        'nome' => $data['name'],
                        'cpf' => $data['cpf'],
                        'telefone' => $data['phone']
                    ],
                    'metodo_pagamento' => 'Pix',
                    'produtos_comprados' => [[
                        'produto_id' => $main_product_id,
                        'nome' => $main_product_name,
                        'valor' => $data['transaction_amount']
                    ]],
                    'utm_parameters' => $utm_parameters,
                    'data_venda' => date('Y-m-d H:i:s')
                ];
                trigger_webhooks($usuario_id, $webhook_payload, 'pending', $main_product_id);
            }
            // -------------------------------------------------------------

            echo json_encode([
                'status' => 'pix_created',
                'pix_data' => [
                    'qr_code_base64' => $res_data['qr_code_base64'],
                    'qr_code' => $res_data['qr_code'] ?? '',
                    'payment_id' => $payment_id
                ],
                'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
            ]);
            exit;

        } else {
            $error_msg = "Erro ao processar pagamento";
            if (isset($res_data['message'])) {
                $error_msg = $res_data['message'];
            } elseif (isset($res_data['error'])) {
                $error_msg = is_array($res_data['error']) ? implode(', ', $res_data['error']) : $res_data['error'];
            } elseif (!empty($response)) {
                $error_msg = "Resposta inesperada: " . substr($response, 0, 200);
            }
            
            log_process("PushinPay Error ($http_code): " . $error_msg);
            throw new Exception("PushinPay Error ($http_code): " . $error_msg);
        }
    }

    // ==========================================================
    // FLUXO BEEHIVE
    // ==========================================================
    elseif ($gateway_choice === 'beehive') {
        require_once __DIR__ . '/../gateways/beehive.php';
        
        $secret_key = $credentials['beehive_secret_key'] ?? '';
        $public_key = $credentials['beehive_public_key'] ?? '';
        
        // Validações backend
        if (empty($secret_key) || empty($public_key)) {
            http_response_code(400);
            echo json_encode(['error' => 'Credenciais Beehive não configuradas.']);
            exit;
        }
        
        if (empty($data['card_token'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Token do cartão não fornecido.']);
            exit;
        }
        
        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (strlen($cpf) !== 11) {
            http_response_code(400);
            echo json_encode(['error' => 'CPF inválido.']);
            exit;
        }
        
        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email inválido.']);
            exit;
        }
        
        // Validar valor
        $amount = (float)($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Valor inválido.']);
            exit;
        }
        
        // Criar pagamento
        // get_client_ip() já está disponível via require_once do beehive.php acima
        $card_data = $data['card_data'] ?? null; // Dados do cartão do frontend
        $client_ip = get_client_ip(); // Usar função helper para capturar IP real
        $payment_result = beehive_create_payment(
            $secret_key,
            $public_key,
            $amount,
            $data['card_token'] ?? '',
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'cpf' => $cpf,
                'phone' => preg_replace('/[^0-9]/', '', $data['phone'] ?? '')
            ],
            'Compra: ' . $main_product_name,
            $webhook_url,
            $card_data, // Dados do cartão
            $client_ip // IP do cliente
        );
        
        if (!$payment_result || (isset($payment_result['error']) && $payment_result['error'])) {
            $error_message = $payment_result['message'] ?? 'Erro ao processar pagamento Beehive.';
            log_process("Beehive Error: " . $error_message);
            // Se o erro tem http_code, usar ele; caso contrário, usar 400 (Bad Request) em vez de 500
            $error_code = $payment_result['http_code'] ?? 400;
            http_response_code($error_code);
            echo json_encode(['error' => $error_message]);
            exit;
        }
        
        $status = $payment_result['status']; // 'approved', 'pending', 'rejected'
        $payment_id = $payment_result['payment_id'];
        $metodo = 'Cartão de crédito';
        
        // Salvar venda
        save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);
        
        // Disparar UTMfy
        if (function_exists('trigger_utmfy_integrations')) {
            $event_data_utmfy = [
                'transacao_id' => $payment_id,
                'valor_total_compra' => $amount,
                'comprador' => [
                    'nome' => $data['name'],
                    'email' => $data['email'],
                    'telefone' => $data['phone'],
                    'cpf' => $data['cpf']
                ],
                'metodo_pagamento' => $metodo,
                'produtos_comprados' => [[
                    'produto_id' => $main_product_id,
                    'nome' => $main_product_name,
                    'valor' => $amount
                ]],
                'utm_parameters' => $utm_parameters,
                'data_venda' => date('Y-m-d H:i:s')
            ];
            // Se for aprovado instantaneamente, manda approved, senão pending
            $trigger_status = ($status === 'approved') ? 'approved' : 'pending';
            trigger_utmfy_integrations($usuario_id, $event_data_utmfy, $trigger_status, $main_product_id);
        }
        
        // Disparar Push Notifications
        if (function_exists('trigger_push_pedidos_notifications')) {
            trigger_push_pedidos_notifications($usuario_id, $event_data_utmfy, $trigger_status, $main_product_id);
        }
        
        // --- DISPARO DE WEBHOOK PARA COMPRA APROVADA ---
        if ($status === 'approved' && function_exists('trigger_webhooks')) {
            $webhook_payload = [
                'transacao_id' => $payment_id,
                'status_pagamento' => 'approved',
                'valor_total_compra' => $amount,
                'comprador' => [
                    'email' => $data['email'],
                    'nome' => $data['name'],
                    'cpf' => $data['cpf'],
                    'telefone' => $data['phone']
                ],
                'metodo_pagamento' => $metodo,
                'produtos_comprados' => [[
                    'produto_id' => $main_product_id,
                    'nome' => $main_product_name,
                    'valor' => $amount
                ]],
                'utm_parameters' => $utm_parameters,
                'data_venda' => date('Y-m-d H:i:s')
            ];
            trigger_webhooks($usuario_id, $webhook_payload, 'approved', $main_product_id);
        }
        // -------------------------------------------------------------
        
        // Retornar resposta
        $response_data = [
            'status' => $status,
            'payment_id' => $payment_id
        ];
        
        if ($status === 'approved') {
            $response_data['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
        }
        
        echo json_encode($response_data);
        exit;
    }

    // ==========================================================
    // FLUXO EFÍ CARTÃO
    // ==========================================================
    elseif ($gateway_choice === 'efi_card') {
        require_once __DIR__ . '/../gateways/efi.php';
        
        $client_id = trim($credentials['efi_client_id'] ?? '');
        $client_secret = trim($credentials['efi_client_secret'] ?? '');
        $certificate_path = trim($credentials['efi_certificate_path'] ?? '');
        
        // Validações backend
        if (empty($client_id) || empty($client_secret) || empty($certificate_path)) {
            http_response_code(400);
            echo json_encode(['error' => 'Credenciais Efí não configuradas completamente.']);
            exit;
        }
        
        if (empty($data['payment_token'])) {
            log_process("Efí Cartão: Payment token não fornecido no POST");
            http_response_code(400);
            echo json_encode(['error' => 'Payment token não fornecido.']);
            exit;
        }
        
        log_process("Efí Cartão: Payment token recebido (primeiros 30 chars): " . substr($data['payment_token'], 0, 30) . "... (tamanho: " . strlen($data['payment_token']) . ")");
        
        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (strlen($cpf) !== 11) {
            http_response_code(400);
            echo json_encode(['error' => 'CPF inválido.']);
            exit;
        }
        
        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email inválido.']);
            exit;
        }
        
        // Validar valor
        $amount = (float)($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Valor inválido.']);
            exit;
        }
        
        // Obter access token
        $full_cert_path = __DIR__ . '/../' . str_replace('\\', '/', $certificate_path);
        $token_data = efi_get_access_token($client_id, $client_secret, $full_cert_path);
        
        if (!$token_data || !isset($token_data['access_token'])) {
            log_process("Efí Cartão: Erro ao obter access token");
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao autenticar com Efí. Verifique as credenciais.']);
            exit;
        }
        
        // Obter número de parcelas (padrão: 1)
        $installments = (int)($data['installments'] ?? 1);
        if ($installments < 1 || $installments > 12) {
            $installments = 1;
        }
        
        // Criar cobrança
        $payment_result = efi_create_card_charge(
            $token_data['access_token'],
            $amount,
            $data['payment_token'],
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'cpf' => $cpf,
                'phone' => $data['phone']
            ],
            'Compra: ' . $main_product_name,
            $webhook_url,
            $full_cert_path,
            $installments
        );
        
        if (!$payment_result || (isset($payment_result['error']) && $payment_result['error'])) {
            $error_message = $payment_result['message'] ?? 'Erro ao processar pagamento Efí.';
            log_process("Efí Cartão Error: " . $error_message);
            // Se o erro tem http_code, usar ele; caso contrário, usar 400 (Bad Request)
            $error_code = $payment_result['http_code'] ?? 400;
            http_response_code($error_code);
            echo json_encode(['error' => $error_message]);
            exit;
        }
        
        $status = $payment_result['status']; // 'approved', 'pending', 'rejected'
        $payment_id = $payment_result['charge_id'];
        $metodo = 'Cartão de crédito';
        
        // Salvar venda
        save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);
        
        // Disparar UTMfy
        if (function_exists('trigger_utmfy_integrations')) {
            $event_data_utmfy = [
                'transacao_id' => $payment_id,
                'valor_total_compra' => $amount,
                'comprador' => [
                    'nome' => $data['name'],
                    'email' => $data['email'],
                    'telefone' => $data['phone'],
                    'cpf' => $data['cpf']
                ],
                'metodo_pagamento' => $metodo,
                'produtos_comprados' => [[
                    'produto_id' => $main_product_id,
                    'nome' => $main_product_name,
                    'valor' => $amount
                ]],
                'utm_parameters' => $utm_parameters,
                'data_venda' => date('Y-m-d H:i:s')
            ];
            // Se for aprovado instantaneamente, manda approved, senão pending
            $trigger_status = ($status === 'approved') ? 'approved' : 'pending';
            trigger_utmfy_integrations($usuario_id, $event_data_utmfy, $trigger_status, $main_product_id);
        }
        
        // Disparar Push Notifications
        if (function_exists('trigger_push_pedidos_notifications')) {
            trigger_push_pedidos_notifications($usuario_id, $event_data_utmfy, $trigger_status, $main_product_id);
        }
        
        // --- DISPARO DE WEBHOOK PARA COMPRA APROVADA ---
        if ($status === 'approved' && function_exists('trigger_webhooks')) {
            $webhook_payload = [
                'transacao_id' => $payment_id,
                'status_pagamento' => 'approved',
                'valor_total_compra' => $amount,
                'comprador' => [
                    'email' => $data['email'],
                    'nome' => $data['name'],
                    'cpf' => $data['cpf'],
                    'telefone' => $data['phone']
                ],
                'metodo_pagamento' => $metodo,
                'produtos_comprados' => [[
                    'produto_id' => $main_product_id,
                    'nome' => $main_product_name,
                    'valor' => $amount
                ]],
                'utm_parameters' => $utm_parameters,
                'data_venda' => date('Y-m-d H:i:s')
            ];
            trigger_webhooks($usuario_id, $webhook_payload, 'approved', $main_product_id);
        }
        // -------------------------------------------------------------
        
        // Retornar resposta
        $response_data = [
            'status' => $status,
            'payment_id' => $payment_id
        ];
        
        if ($status === 'approved') {
            $response_data['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
        } elseif ($status === 'pending') {
            // Para pending, redirecionar para página de aguardando processamento
            $response_data['redirect_url'] = '/aguardando.php?payment_id=' . $payment_id;
        }
        
        echo json_encode($response_data);
        exit;
    }
    
    // ==========================================================
    // FLUXO MERCADO PAGO (fallback)
    // ==========================================================
    else {
        $token = $credentials['mp_access_token'] ?? '';
        if (empty($token)) throw new Exception("Token Mercado Pago não configurado.");
        
        // Log dos dados recebidos para debug
        log_process("Mercado Pago: Dados recebidos - " . json_encode(array_keys($data)));
        log_process("Mercado Pago: payment_method_id = " . ($data['payment_method_id'] ?? 'NÃO ENVIADO'));
        
        // O Payment Brick envia os dados no formData, então vamos usar diretamente
        // e apenas adicionar/completar os campos necessários
        $payment_data = [];
        
        // Copia todos os dados do formData (Payment Brick já envia no formato correto)
        foreach ($data as $key => $value) {
            // Ignora campos que não são do Payment Brick
            if (!in_array($key, ['name', 'email', 'cpf', 'phone', 'product_id', 'transaction_amount', 
                                  'order_bump_product_ids', 'utm_parameters', 'gateway', 'csrf_token'])) {
                $payment_data[$key] = $value;
            }
        }
        
        // Garante campos obrigatórios
        $payment_data['transaction_amount'] = (float)$data['transaction_amount'];
        $payment_data['description'] = 'Compra: ' . $main_product_name;
        
        // Se payment_method_id não foi enviado pelo Payment Brick, tenta inferir
        if (empty($payment_data['payment_method_id'])) {
            // Tenta inferir do paymentTypeId ou outros campos
            if (isset($data['paymentTypeId'])) {
                $payment_data['payment_method_id'] = $data['paymentTypeId'];
            } elseif (isset($data['payment_type_id'])) {
                $payment_data['payment_method_id'] = $data['payment_type_id'];
            } else {
                log_process("Mercado Pago: ERRO - payment_method_id não foi enviado e não foi possível inferir");
                log_process("Mercado Pago: Dados completos recebidos: " . json_encode($data));
                http_response_code(400);
                echo json_encode(['error' => 'Método de pagamento não especificado. Por favor, tente novamente.']);
                exit;
            }
        }
        
        // Adiciona/sobrescreve dados do payer
        $payment_data['payer'] = [
            'email' => $data['email'],
            'first_name' => explode(' ', $data['name'])[0],
            'last_name' => substr(strstr($data['name'], ' '), 1) ?: '',
            'identification' => ['type' => 'CPF', 'number' => preg_replace('/[^0-9]/', '', $data['cpf'])],
        ];
        
        // Adiciona campos de referência e notificação
        $payment_data['external_reference'] = $checkout_session_uuid;
        $payment_data['notification_url'] = $webhook_url;
        
        // Log do payload que será enviado (sem token por segurança)
        $log_payload = $payment_data;
        if (isset($log_payload['token'])) {
            $log_payload['token'] = substr($log_payload['token'], 0, 10) . '...';
        }
        log_process("Mercado Pago: Payload a ser enviado - " . json_encode($log_payload));

        $ch = curl_init('https://api.mercadopago.com/v1/payments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'X-Idempotency-Key: ' . $checkout_session_uuid
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log da resposta
        log_process("Mercado Pago: HTTP Code = $http_code");
        if ($curl_error) {
            log_process("Mercado Pago: cURL Error = $curl_error");
            throw new Exception("Erro de conexão com Mercado Pago: " . $curl_error);
        }
        log_process("Mercado Pago: Response (primeiros 500 chars) = " . substr($response, 0, 500));

        $res_data = json_decode($response, true);
        
        if (!$res_data) {
            log_process("Mercado Pago: ERRO - Resposta não é JSON válido");
            log_process("Mercado Pago: Resposta completa = " . $response);
            throw new Exception("Resposta inválida do Mercado Pago. Tente novamente.");
        }

        if ($http_code >= 200 && $http_code < 300 && isset($res_data['status'])) {
            $status = $res_data['status'];
            $payment_id = $res_data['id'];
            $metodo = ($data['payment_method_id'] === 'pix') ? 'Pix' : (($data['payment_method_id'] === 'ticket') ? 'Boleto' : 'Cartão de crédito');
            
            // Extrai mensagem de erro se o pagamento foi recusado/rejeitado
            $error_message = null;
            $status_detail = null;
            if (in_array($status, ['rejected', 'cancelled', 'refunded', 'charged_back'])) {
                // Tenta extrair mensagem de erro do Mercado Pago
                if (isset($res_data['status_detail'])) {
                    $status_detail = $res_data['status_detail'];
                }
                if (isset($res_data['cause']) && is_array($res_data['cause'])) {
                    $error_codes = array_column($res_data['cause'], 'code');
                    $error_messages = array_column($res_data['cause'], 'description');
                    if (!empty($error_messages)) {
                        $error_message = implode('. ', $error_messages);
                    }
                }
                if (!$error_message && isset($res_data['message'])) {
                    $error_message = $res_data['message'];
                }
            }

            save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);

            // --- DISPARO IMEDIATO PARA UTMFY ---
            if (function_exists('trigger_utmfy_integrations')) {
                $event_data_utmfy = [
                    'transacao_id' => $payment_id,
                    'valor_total_compra' => $data['transaction_amount'],
                    'comprador' => [
                        'nome' => $data['name'], 'email' => $data['email'], 
                        'telefone' => $data['phone'], 'cpf' => $data['cpf']
                    ],
                    'metodo_pagamento' => $metodo,
                    'produtos_comprados' => [[
                        'produto_id' => $main_product_id, 'nome' => $main_product_name, 'valor' => $data['transaction_amount']
                    ]],
                    'utm_parameters' => $utm_parameters,
                    'data_venda' => date('Y-m-d H:i:s')
                ];
                // Se for aprovado instantaneamente (Cartão), manda approved, senão pending
                $trigger_status = ($status === 'approved') ? 'approved' : 'pending';
                trigger_utmfy_integrations($usuario_id, $event_data_utmfy, $trigger_status, $main_product_id);
            }
            // ------------------------------------
            
            // --- DISPARO IMEDIATO PARA PUSH NOTIFICATIONS ---
            if (function_exists('trigger_push_pedidos_notifications')) {
                trigger_push_pedidos_notifications($usuario_id, $event_data_utmfy, $trigger_status, $main_product_id);
            }
            // ------------------------------------
            
            // --- DISPARO DE WEBHOOK PARA COMPRA APROVADA ---
            if ($status === 'approved' && function_exists('trigger_webhooks')) {
                $webhook_payload = [
                    'transacao_id' => $payment_id,
                    'status_pagamento' => 'approved',
                    'valor_total_compra' => $data['transaction_amount'],
                    'comprador' => [
                        'email' => $data['email'],
                        'nome' => $data['name'],
                        'cpf' => $data['cpf'],
                        'telefone' => $data['phone']
                    ],
                    'metodo_pagamento' => $metodo,
                    'produtos_comprados' => [[
                        'produto_id' => $main_product_id,
                        'nome' => $main_product_name,
                        'valor' => $data['transaction_amount']
                    ]],
                    'utm_parameters' => $utm_parameters,
                    'data_venda' => date('Y-m-d H:i:s')
                ];
                trigger_webhooks($usuario_id, $webhook_payload, 'approved', $main_product_id);
            }
            // -------------------------------------------------------------

            if ($status == 'pending' && $data['payment_method_id'] == 'pix') {
                // --- DISPARO IMEDIATO DE WEBHOOK PARA PIX PENDENTE ---
                if (function_exists('trigger_webhooks')) {
                    $webhook_payload = [
                        'transacao_id' => $payment_id,
                        'status_pagamento' => 'pending',
                        'valor_total_compra' => $data['transaction_amount'],
                        'comprador' => [
                            'email' => $data['email'],
                            'nome' => $data['name'],
                            'cpf' => $data['cpf'],
                            'telefone' => $data['phone']
                        ],
                        'metodo_pagamento' => 'Pix',
                        'produtos_comprados' => [[
                            'produto_id' => $main_product_id,
                            'nome' => $main_product_name,
                            'valor' => $data['transaction_amount']
                        ]],
                        'utm_parameters' => $utm_parameters,
                        'data_venda' => date('Y-m-d H:i:s')
                    ];
                    trigger_webhooks($usuario_id, $webhook_payload, 'pending', $main_product_id);
                }
                // -------------------------------------------------------------
                
                echo json_encode([
                    'status' => 'pix_created',
                    'pix_data' => [
                        'qr_code_base64' => $res_data['point_of_interaction']['transaction_data']['qr_code_base64'],
                        'qr_code' => $res_data['point_of_interaction']['transaction_data']['qr_code'],
                        'payment_id' => $payment_id
                    ],
                    'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
                ]);
                exit;
            }

            $response_front = ['status' => $status, 'message' => 'Processado.', 'payment_id' => $payment_id];
            
            // Se o pagamento foi recusado/rejeitado, inclui mensagem de erro
            if (in_array($status, ['rejected', 'cancelled', 'refunded', 'charged_back'])) {
                $response_front['error'] = getMercadoPagoErrorMessage($status, $status_detail, $error_message);
                $response_front['status_detail'] = $status_detail;
            }
            
            // Se o pagamento foi aprovado, inclui URL de redirecionamento
            if ($status == 'approved') {
                $response_front['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
            }
            
            // Se o pagamento está pendente ou em processamento, inclui informação para polling
            if (in_array($status, ['pending', 'in_process'])) {
                $response_front['message'] = 'Pagamento em processamento. Aguarde a confirmação.';
            }
            
            echo json_encode($response_front);

        } else {
            // Extrai mensagem de erro da resposta do Mercado Pago
            $error_msg = "Erro ao processar pagamento";
            if (isset($res_data['message'])) {
                $error_msg = $res_data['message'];
            } elseif (isset($res_data['error'])) {
                $error_msg = is_array($res_data['error']) ? implode(', ', $res_data['error']) : $res_data['error'];
            } elseif (isset($res_data['cause']) && is_array($res_data['cause'])) {
                $error_descriptions = array_column($res_data['cause'], 'description');
                if (!empty($error_descriptions)) {
                    $error_msg = implode('. ', $error_descriptions);
                }
            }
            log_process("Mercado Pago Error ($http_code): " . $error_msg);
            log_process("Mercado Pago Error: Resposta completa = " . json_encode($res_data));
            
            // Se não conseguiu extrair mensagem, usar mensagem genérica
            if ($error_msg === "Erro ao processar pagamento") {
                $error_msg = "Erro ao processar pagamento no Mercado Pago. Verifique os dados e tente novamente.";
            }
            
            throw new Exception($error_msg);
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    log_process("Erro Exception: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}

function save_sales($pdo, $data, $main_id, $payment_id, $status, $metodo, $uuid, $utm_params) {
    // Extrai UTMs
    $utm_source = $utm_params['utm_source'] ?? null;
    $utm_campaign = $utm_params['utm_campaign'] ?? null;
    $utm_medium = $utm_params['utm_medium'] ?? null;
    $utm_content = $utm_params['utm_content'] ?? null;
    $utm_term = $utm_params['utm_term'] ?? null;
    $src = $utm_params['src'] ?? null;
    $sck = $utm_params['sck'] ?? null;

    $pdo->beginTransaction();
    try {
        // Validar IDs de produtos para prevenir SQL injection
        $products = [$main_id];
        if (isset($data['order_bump_product_ids']) && is_array($data['order_bump_product_ids'])) {
            $products = array_merge($products, $data['order_bump_product_ids']);
        }
        
        // Validar e sanitizar IDs (converte para inteiros e limita quantidade)
        try {
            $products = validate_product_ids($products, 10); // Máximo 10 produtos
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
        
        $placeholders = implode(',', array_fill(0, count($products), '?'));
        $stmt_info = $pdo->prepare("SELECT id, preco FROM produtos WHERE id IN ($placeholders)");
        $stmt_info->execute($products);
        $prod_map = $stmt_info->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        // Extrair dados de endereço se existirem
        $address = $data['address'] ?? null;
        $comprador_cep = null;
        $comprador_logradouro = null;
        $comprador_numero = null;
        $comprador_complemento = null;
        $comprador_bairro = null;
        $comprador_cidade = null;
        $comprador_estado = null;
        
        if ($address && is_array($address)) {
            $comprador_cep = $address['cep'] ?? null;
            $comprador_logradouro = $address['logradouro'] ?? null;
            $comprador_numero = $address['numero'] ?? null;
            $comprador_complemento = $address['complemento'] ?? null;
            $comprador_bairro = $address['bairro'] ?? null;
            $comprador_cidade = $address['cidade'] ?? null;
            $comprador_estado = $address['estado'] ?? null;
        }
        
        $stmt_insert = $pdo->prepare("INSERT INTO vendas (produto_id, comprador_nome, comprador_email, comprador_cpf, comprador_telefone, comprador_cep, comprador_logradouro, comprador_numero, comprador_complemento, comprador_bairro, comprador_cidade, comprador_estado, valor, status_pagamento, transacao_id, metodo_pagamento, checkout_session_uuid, email_entrega_enviado, utm_source, utm_campaign, utm_medium, utm_content, utm_term, src, sck) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($products as $pid) {
            if (isset($prod_map[$pid])) {
                $val = $prod_map[$pid]['preco'];
                $stmt_insert->execute([
                    $pid, $data['name'], $data['email'], 
                    preg_replace('/[^0-9]/', '', $data['cpf']), 
                    preg_replace('/[^0-9]/', '', $data['phone']),
                    $comprador_cep, $comprador_logradouro, $comprador_numero, $comprador_complemento,
                    $comprador_bairro, $comprador_cidade, $comprador_estado,
                    $val, $status, $payment_id, $metodo, $uuid,
                    $utm_source, $utm_campaign, $utm_medium, $utm_content, $utm_term, $src, $sck
                ]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Erro ao salvar vendas: " . $e->getMessage());
    }
}
?>