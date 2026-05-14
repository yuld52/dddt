<?php
// Inicia buffer de saída para capturar qualquer output indesejado
ob_start();

// Desabilita exibição de erros antes de qualquer output
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/process_payment_log.txt');
error_reporting(E_ALL);

// Função para retornar erro JSON de forma segura
function returnJsonError($message, $code = 500) {
    ob_clean(); // Limpa qualquer output anterior
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

// Função para retornar sucesso JSON
function returnJsonSuccess($data) {
    ob_clean(); // Limpa qualquer output anterior
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Tenta carregar config.php (pode estar na raiz ou em config/)
$config_paths = [
    __DIR__ . '/config.php',
    __DIR__ . '/config/config.php',
    dirname(__DIR__) . '/config/config.php'
];

$config_loaded = false;
foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        try {
            // Captura qualquer output do config.php
            ob_start();
            require $config_path;
            ob_end_clean();
            $config_loaded = true;
            break;
        } catch (Exception $e) {
            ob_end_clean();
            returnJsonError('Erro ao carregar configuração: ' . $e->getMessage(), 500);
        } catch (Error $e) {
            ob_end_clean();
            returnJsonError('Erro fatal ao carregar configuração: ' . $e->getMessage(), 500);
        }
    }
}

if (!$config_loaded) {
    returnJsonError('Arquivo de configuração não encontrado.', 500);
}

// Limpa o buffer inicial
ob_end_clean();

// Define header JSON
header('Content-Type: application/json');

// Inclui o helper da UTMfy
$utmfy_paths = [
    __DIR__ . '/helpers/utmfy_helper.php',
    dirname(__DIR__) . '/helpers/utmfy_helper.php',
    __DIR__ . '/utmfy_helper.php'
];

foreach ($utmfy_paths as $utmfy_path) {
    if (file_exists($utmfy_path)) {
        try {
            ob_start();
            require_once $utmfy_path;
            ob_end_clean();
            break;
        } catch (Exception $e) {
            ob_end_clean();
            error_log('Erro ao carregar utmfy_helper: ' . $e->getMessage());
        } catch (Error $e) {
            ob_end_clean();
            error_log('Erro fatal ao carregar utmfy_helper: ' . $e->getMessage());
        }
    }
}

// Inclui o helper de push para pedidos
$push_pedidos_paths = [
    __DIR__ . '/helpers/push_pedidos_helper.php',
    dirname(__DIR__) . '/helpers/push_pedidos_helper.php',
    __DIR__ . '/push_pedidos_helper.php'
];

foreach ($push_pedidos_paths as $push_path) {
    if (file_exists($push_path)) {
        try {
            ob_start();
            require_once $push_path;
            ob_end_clean();
            break;
        } catch (Exception $e) {
            ob_end_clean();
            error_log('Erro ao carregar push_pedidos_helper: ' . $e->getMessage());
        } catch (Error $e) {
            ob_end_clean();
            error_log('Erro fatal ao carregar push_pedidos_helper: ' . $e->getMessage());
        }
    }
}

// Carrega helpers de segurança e validação
require_once __DIR__ . '/helpers/security_helper.php';
require_once __DIR__ . '/helpers/validation_helper.php';
require_once __DIR__ . '/helpers/webhook_helper.php';

// Rate limiting para endpoint de pagamento
$client_ip = get_client_ip();
$rate_limit = check_rate_limit_db('payment_process', 10, 60, $client_ip); // 10 requisições por minuto
if (!$rate_limit['allowed']) {
    log_security_event('rate_limit_exceeded_payment', [
        'ip' => $client_ip,
        'reset_at' => $rate_limit['reset_at']
    ]);
    returnJsonError('Muitas requisições. Tente novamente mais tarde.', 429);
}

function log_process($msg) {
    $log_file = __DIR__ . '/process_payment_log.txt';
    // Usar secure_log ao invés de file_put_contents direto
    if (function_exists('secure_log')) {
        secure_log($log_file, $msg, 'info');
    } else {
        @file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $msg . "\n", FILE_APPEND);
    }
}

log_process("INÍCIO DO PROCESSAMENTO");

$raw_post_data = file_get_contents('php://input');
$data = json_decode($raw_post_data, true);

if (!$data) {
    returnJsonError('Dados inválidos.', 400);
}

// Campos comuns
$required_fields = ['transaction_amount', 'email', 'cpf', 'name', 'phone', 'product_id'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        returnJsonError("Campo obrigatório ausente: $field", 400);
    }
}

// Validações de dados de pagamento
if (!validate_email($data['email'])) {
    returnJsonError('Email inválido.', 400);
}

if (!validate_cpf($data['cpf'])) {
    returnJsonError('CPF inválido.', 400);
}

if (!validate_phone_br($data['phone'])) {
    returnJsonError('Telefone inválido.', 400);
}

if (!validate_transaction_amount($data['transaction_amount'])) {
    returnJsonError('Valor da transação inválido. Deve estar entre R$ 0,01 e R$ 100.000,00.', 400);
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

    $stmt_user = $pdo->prepare("SELECT mp_access_token, pushinpay_token, efi_client_id, efi_client_secret, efi_certificate_path, efi_pix_key, efi_payee_code, beehive_secret_key, beehive_public_key, hypercash_secret_key, hypercash_public_key FROM usuarios WHERE id = ?");
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
    require_once __DIR__ . '/helpers/security_helper.php';
    
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
        require_once __DIR__ . '/gateways/efi.php';
        
        // Remover espaços em branco e caracteres invisíveis das credenciais
        $client_id = trim($credentials['efi_client_id'] ?? '');
        $client_secret = trim($credentials['efi_client_secret'] ?? '');
        $certificate_path = trim($credentials['efi_certificate_path'] ?? '');
        $pix_key = trim($credentials['efi_pix_key'] ?? '');
        
        // Log detalhado antes de processar
        error_log("Efí: Iniciando processamento de pagamento");
        error_log("Efí: Client ID presente: " . (!empty($client_id) ? 'sim (tamanho: ' . strlen($client_id) . ')' : 'não'));
        error_log("Efí: Client Secret presente: " . (!empty($client_secret) ? 'sim (tamanho: ' . strlen($client_secret) . ')' : 'não'));
        error_log("Efí: Caminho certificado (relativo): " . $certificate_path);
        error_log("Efí: Chave Pix presente: " . (!empty($pix_key) ? 'sim' : 'não'));
        
        if (empty($client_id) || empty($client_secret) || empty($certificate_path) || empty($pix_key)) {
            $missing = [];
            if (empty($client_id)) $missing[] = 'Client ID';
            if (empty($client_secret)) $missing[] = 'Client Secret';
            if (empty($certificate_path)) $missing[] = 'Caminho do Certificado';
            if (empty($pix_key)) $missing[] = 'Chave Pix';
            error_log("Efí: Credenciais faltando: " . implode(', ', $missing));
            throw new Exception("Credenciais Efí não configuradas completamente. Faltando: " . implode(', ', $missing));
        }
        
        // Validar se certificado existe
        // Normalizar caminho (Windows usa \, mas precisamos de / para cURL)
        $certificate_path_normalized = str_replace('\\', '/', $certificate_path);
        $full_cert_path = __DIR__ . '/' . $certificate_path_normalized;
        // Normalizar também o caminho completo para Windows
        $full_cert_path = str_replace('\\', '/', $full_cert_path);
        error_log("Efí: Caminho completo do certificado (normalizado): " . $full_cert_path);
        
        if (!file_exists($full_cert_path)) {
            error_log("Efí: Certificado não encontrado no caminho: " . $full_cert_path);
            error_log("Efí: Diretório atual: " . __DIR__);
            error_log("Efí: Caminho relativo do banco: " . $certificate_path);
            throw new Exception("Certificado Efí não encontrado em: " . $certificate_path);
        }
        
        error_log("Efí: Certificado encontrado, obtendo token de acesso...");
        
        // Obter access token
        $token_data = efi_get_access_token($client_id, $client_secret, $full_cert_path);
        if (!$token_data) {
            error_log("Efí: Falha ao obter token de acesso");
            throw new Exception("Erro ao obter token de acesso Efí (401 - Invalid credentials). Verifique: 1) Se o Client ID e Client Secret estão corretos na conta Efí, 2) Se o certificado P12 corresponde a essas credenciais, 3) Se as credenciais estão ativas. Consulte os logs para mais detalhes.");
        }
        
        error_log("Efí: Token obtido com sucesso");
        
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
                    throw new Exception("CPF inválido. Por favor, verifique o CPF informado.");
                }
                throw new Exception($error_msg);
            }
            $cpf_limpo = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
            if (strlen($cpf_limpo) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf_limpo)) {
                throw new Exception("CPF inválido. Por favor, verifique o CPF informado.");
            }
            throw new Exception("Erro ao criar cobrança Pix na Efí. Verifique os logs para mais detalhes.");
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
        
        returnJsonSuccess([
            'status' => 'pix_created',
            'pix_data' => [
                'qr_code_base64' => $pix_result['qr_code_base64'] ?? null,
                'qr_code' => $pix_result['qr_code'] ?? '',
                'payment_id' => $payment_id
            ],
            'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
        ]);
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

            returnJsonSuccess([
                'status' => 'pix_created',
                'pix_data' => [
                    'qr_code_base64' => $res_data['qr_code_base64'],
                    'qr_code' => $res_data['qr_code'] ?? '',
                    'payment_id' => $payment_id
                ],
                'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
            ]);

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
        require_once __DIR__ . '/gateways/beehive.php';
        
        $secret_key = $credentials['beehive_secret_key'] ?? '';
        $public_key = $credentials['beehive_public_key'] ?? '';
        
        // Validações backend
        if (empty($secret_key) || empty($public_key)) {
            throw new Exception("Credenciais Beehive não configuradas.");
        }
        
        if (empty($data['card_token'])) {
            log_process("Beehive: Token do cartão não fornecido no POST");
            throw new Exception("Token do cartão não fornecido.");
        }
        
        log_process("Beehive: Token recebido (primeiros 20 chars): " . substr($data['card_token'], 0, 20) . "... (tamanho: " . strlen($data['card_token']) . ")");
        
        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF inválido.");
        }
        
        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }
        
        // Validar valor
        $amount = (float)($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception("Valor inválido.");
        }
        
        // Criar pagamento
        // get_client_ip() já está disponível via require_once do beehive.php acima (linha 417)
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
            throw new Exception($error_message);
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
        
        returnJsonSuccess($response_data);
    }

    // ==========================================================
    // FLUXO HYPERCASH
    // ==========================================================
    elseif ($gateway_choice === 'hypercash') {
        require_once __DIR__ . '/gateways/hypercash.php';
        
        $secret_key = $credentials['hypercash_secret_key'] ?? '';
        $public_key = $credentials['hypercash_public_key'] ?? '';
        
        // Validações backend
        if (empty($secret_key) || empty($public_key)) {
            throw new Exception("Credenciais Hypercash não configuradas.");
        }
        
        if (empty($data['card_token'])) {
            log_process("Hypercash: Token do cartão não fornecido no POST");
            throw new Exception("Token do cartão não fornecido.");
        }
        
        log_process("Hypercash: Token recebido (primeiros 20 chars): " . substr($data['card_token'], 0, 20) . "... (tamanho: " . strlen($data['card_token']) . ")");
        
        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF inválido.");
        }
        
        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }
        
        // Validar valor
        $amount = (float)($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception("Valor inválido.");
        }
        
        // Criar pagamento
        // hypercash_get_client_ip() já está disponível via require_once do hypercash.php acima
        $card_data = $data['card_data'] ?? null; // Dados do cartão do frontend
        $client_ip = hypercash_get_client_ip(); // Usar função helper para capturar IP real
        $payment_result = hypercash_create_payment(
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
            $error_message = $payment_result['message'] ?? 'Erro ao processar pagamento Hypercash.';
            log_process("Hypercash Error: " . $error_message);
            throw new Exception($error_message);
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
        } elseif ($status === 'pending') {
            // Para pending, redirecionar para página de aguardando processamento
            $response_data['redirect_url'] = '/aguardando.php?payment_id=' . $payment_id;
        }
        
        returnJsonSuccess($response_data);
    }

    // ==========================================================
    // FLUXO EFÍ CARTÃO
    // ==========================================================
    elseif ($gateway_choice === 'efi_card') {
        log_process("Efí Cartão: Iniciando processamento");
        try {
            require_once __DIR__ . '/gateways/efi.php';
            log_process("Efí Cartão: Arquivo efi.php carregado com sucesso");
        } catch (Exception $e) {
            log_process("Efí Cartão: Erro ao carregar efi.php: " . $e->getMessage());
            throw new Exception("Erro ao carregar gateway Efí: " . $e->getMessage());
        }
        
        $client_id = trim($credentials['efi_client_id'] ?? '');
        $client_secret = trim($credentials['efi_client_secret'] ?? '');
        $certificate_path = trim($credentials['efi_certificate_path'] ?? '');
        
        // Validações backend
        if (empty($client_id) || empty($client_secret) || empty($certificate_path)) {
            throw new Exception("Credenciais Efí não configuradas completamente.");
        }
        
        if (empty($data['payment_token'])) {
            log_process("Efí Cartão: Payment token não fornecido no POST");
            throw new Exception("Payment token não fornecido.");
        }
        
        log_process("Efí Cartão: Payment token recebido (primeiros 30 chars): " . substr($data['payment_token'], 0, 30) . "... (tamanho: " . strlen($data['payment_token']) . ")");
        
        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF inválido.");
        }
        
        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }
        
        // Validar valor
        $amount = (float)($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception("Valor inválido.");
        }
        
        // Obter access token
        $full_cert_path = __DIR__ . '/' . str_replace('\\', '/', $certificate_path);
        log_process("Efí Cartão: Caminho completo do certificado: $full_cert_path");
        log_process("Efí Cartão: Certificado existe: " . (file_exists($full_cert_path) ? 'sim' : 'não'));
        
        if (!file_exists($full_cert_path)) {
            log_process("Efí Cartão: ERRO - Certificado não encontrado em: $full_cert_path");
            throw new Exception("Certificado Efí não encontrado. Verifique o caminho do certificado.");
        }
        
        log_process("Efí Cartão: Obtendo access token da API de Cobranças...");
        $token_data = efi_get_charges_access_token($client_id, $client_secret, $full_cert_path);
        
        if (!$token_data || !isset($token_data['access_token'])) {
            log_process("Efí Cartão: Erro ao obter access token");
            log_process("Efí Cartão: token_data: " . json_encode($token_data));
            throw new Exception("Erro ao autenticar com Efí. Verifique as credenciais.");
        }
        
        log_process("Efí Cartão: Access token obtido com sucesso");
        
        // Obter número de parcelas (padrão: 1)
        $installments = (int)($data['installments'] ?? 1);
        if ($installments < 1 || $installments > 12) {
            $installments = 1;
        }
        
        log_process("Efí Cartão: Criando cobrança - Valor: $amount, Parcelas: $installments");
        
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
            if (isset($payment_result['http_code'])) {
                log_process("Efí Cartão HTTP Code: " . $payment_result['http_code']);
            }
            if (isset($payment_result['error_data'])) {
                log_process("Efí Cartão Error Data: " . json_encode($payment_result['error_data']));
            }
            throw new Exception($error_message);
        }
        
        log_process("Efí Cartão: Payment result recebido - status: " . ($payment_result['status'] ?? 'não definido') . ", charge_id: " . ($payment_result['charge_id'] ?? 'não definido'));
        
        if (!isset($payment_result['status']) || !isset($payment_result['charge_id'])) {
            log_process("Efí Cartão: Resposta inválida - payment_result: " . json_encode($payment_result));
            throw new Exception("Resposta inválida da API Efí.");
        }
        
        $status = $payment_result['status']; // 'approved', 'pending', 'rejected'
        $payment_id = $payment_result['charge_id'];
        $metodo = 'Cartão de crédito';
        
        log_process("Efí Cartão: Salvando venda - payment_id: $payment_id, status: $status");
        
        // Salvar venda
        try {
            save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);
            log_process("Efí Cartão: Venda salva com sucesso");
        } catch (Exception $e) {
            log_process("Efí Cartão: Erro ao salvar venda: " . $e->getMessage());
            throw new Exception("Erro ao salvar venda: " . $e->getMessage());
        }
        
        // Disparar UTMfy
        if (function_exists('trigger_utmfy_integrations')) {
            try {
                log_process("Efí Cartão: Disparando UTMfy...");
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
                log_process("Efí Cartão: UTMfy disparado com sucesso");
            } catch (Exception $e) {
                log_process("Efí Cartão: Erro ao disparar UTMfy (não crítico): " . $e->getMessage());
                // Não lança exceção aqui, pois o pagamento já foi processado
            }
        }
        
        // Disparar Push Notifications
        if (function_exists('trigger_push_pedidos_notifications')) {
            try {
                trigger_push_pedidos_notifications($usuario_id, $event_data_utmfy, $trigger_status, $main_product_id);
            } catch (Exception $e) {
                log_process("Efí Cartão: Erro ao disparar Push Notifications (não crítico): " . $e->getMessage());
            }
        }
        
        // --- DISPARO DE WEBHOOK PARA COMPRA APROVADA ---
        if ($status === 'approved' && function_exists('trigger_webhooks')) {
            try {
                log_process("Efí Cartão: Disparando webhook para compra aprovada...");
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
                log_process("Efí Cartão: Webhook disparado com sucesso");
            } catch (Exception $e) {
                log_process("Efí Cartão: Erro ao disparar webhook (não crítico): " . $e->getMessage());
                // Não lança exceção aqui, pois o pagamento já foi processado
            }
        }
        // -------------------------------------------------------------
        
        log_process("Efí Cartão: Preparando resposta JSON...");
        
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
        
        log_process("Efí Cartão: Retornando resposta JSON - status: $status, payment_id: $payment_id");
        returnJsonSuccess($response_data);
    }
    
    // ==========================================================
    // FLUXO MERCADO PAGO (fallback)
    // ==========================================================
    else {
        $token = $credentials['mp_access_token'] ?? '';
        if (empty($token)) throw new Exception("Token Mercado Pago não configurado.");
        
        $payment_data = [
            'transaction_amount' => (float)$data['transaction_amount'],
            'description' => 'Compra: ' . $main_product_name,
            'payment_method_id' => $data['payment_method_id'],
            'payer' => [
                'email' => $data['email'],
                'first_name' => explode(' ', $data['name'])[0],
                'last_name' => substr(strstr($data['name'], ' '), 1) ?: '',
                'identification' => ['type' => 'CPF', 'number' => preg_replace('/[^0-9]/', '', $data['cpf'])],
            ],
            'external_reference' => $checkout_session_uuid,
            'notification_url' => $webhook_url
        ];

        if (isset($data['token'])) $payment_data['token'] = $data['token'];
        if (isset($data['installments'])) $payment_data['installments'] = (int)$data['installments'];
        if (isset($data['issuer_id'])) $payment_data['issuer_id'] = (int)$data['issuer_id'];

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
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res_data = json_decode($response, true);

        if ($http_code >= 200 && $http_code < 300 && isset($res_data['status'])) {
            $status = $res_data['status'];
            $payment_id = $res_data['id'];
            $metodo = ($data['payment_method_id'] === 'pix') ? 'Pix' : (($data['payment_method_id'] === 'ticket') ? 'Boleto' : 'Cartão de crédito');

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
            if (function_exists('trigger_push_pedidos_notifications') && isset($event_data_utmfy)) {
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
                // -----------------------------------------------------
                returnJsonSuccess([
                    'status' => 'pix_created',
                    'pix_data' => [
                        'qr_code_base64' => $res_data['point_of_interaction']['transaction_data']['qr_code_base64'],
                        'qr_code' => $res_data['point_of_interaction']['transaction_data']['qr_code'],
                        'payment_id' => $payment_id
                    ],
                    'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
                ]);
            }

            $response_front = ['status' => $status, 'message' => 'Processado.'];
            if ($status == 'approved') $response_front['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
            returnJsonSuccess($response_front);

        } else {
            throw new Exception("Mercado Pago Error");
        }
    }

} catch (Exception $e) {
    log_process("Erro Exception: " . $e->getMessage());
    log_process("Stack trace: " . $e->getTraceAsString());
    
    // Verifica se é erro de limite atingido
    $error_message = $e->getMessage();
    if (strpos($error_message, 'LIMITE_ATINGIDO|') === 0) {
        $parts = explode('|', $error_message, 3);
        $message = $parts[1] ?? 'Limite atingido';
        $upgrade_url = $parts[2] ?? '/index?pagina=saas_planos';
        
        ob_clean();
        http_response_code(403); // Forbidden
        header('Content-Type: application/json');
        echo json_encode([
            'error' => $message,
            'limit_reached' => true,
            'upgrade_url' => $upgrade_url
        ]);
        exit;
    }
    
    returnJsonError($error_message, 500);
} catch (Error $e) {
    log_process("Erro Fatal: " . $e->getMessage());
    log_process("Stack trace: " . $e->getTraceAsString());
    returnJsonError('Erro interno do servidor', 500);
}

function save_sales($pdo, $data, $main_id, $payment_id, $status, $metodo, $uuid, $utm_params) {
    // Verifica limitações via hooks (SaaS) - antes de criar venda
    $hooks_paths = [
        __DIR__ . '/helpers/plugin_hooks.php',
        dirname(__DIR__) . '/helpers/plugin_hooks.php'
    ];
    
    foreach ($hooks_paths as $hooks_path) {
        if (file_exists($hooks_path)) {
            try {
                ob_start();
                require_once $hooks_path;
                ob_end_clean();
                break;
            } catch (Exception $e) {
                ob_end_clean();
                error_log("Erro ao carregar plugin_hooks: " . $e->getMessage());
            }
        }
    }
    
    // CORREÇÃO: Buscar usuario_id do produto ANTES de verificar limites
    // Isso garante que o hook before_create_venda tenha acesso ao usuario_id mesmo sem sessão
    $usuario_id_from_product = null;
    if (!empty($data['product_id'])) {
        try {
            $stmt_prod_check = $pdo->prepare("SELECT usuario_id FROM produtos WHERE id = ?");
            $stmt_prod_check->execute([$data['product_id']]);
            $prod_check = $stmt_prod_check->fetch(PDO::FETCH_ASSOC);
            if ($prod_check && !empty($prod_check['usuario_id'])) {
                $usuario_id_from_product = $prod_check['usuario_id'];
            }
        } catch (Exception $e) {
            log_process("Erro ao buscar usuario_id do produto: " . $e->getMessage());
        }
    }
    
    if (function_exists('do_action')) {
        $limit_check = do_action('before_create_venda', $data['product_id'] ?? 0);
        if ($limit_check && isset($limit_check['allowed']) && !$limit_check['allowed']) {
            $error_message = $limit_check['message'] ?? 'Limite de pedidos atingido';
            $upgrade_url = $limit_check['upgrade_url'] ?? '/index?pagina=saas_planos';
            // Lança exceção com informações de upgrade codificadas
            throw new Exception("LIMITE_ATINGIDO|" . $error_message . "|" . $upgrade_url);
        }
    }
    
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
            returnJsonError($e->getMessage(), 400);
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
        
        // Incrementa contador de pedidos mensais (SaaS)
        if (function_exists('do_action')) {
            do_action('after_create_venda', $main_id);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Erro ao salvar vendas: " . $e->getMessage());
    }
}
?>