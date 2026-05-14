<?php
require __DIR__ . '/config/config.php';
include __DIR__ . '/config/load_settings.php';

// Se vier payment_id, redireciona para obrigado.php
$payment_id = $_GET['payment_id'] ?? null;
if ($payment_id) {
    header('Location: /obrigado.php?payment_id=' . urlencode($payment_id));
    exit;
}

$checkout_hash = $_GET['p'] ?? null;
if (!$checkout_hash) {
    die("Produto não encontrado.");
}

try {
    // 1. Verificar se checkout_hash pertence a uma oferta
    $oferta = null;
    $stmt_oferta = $pdo->prepare("SELECT * FROM produto_ofertas WHERE checkout_hash = ? AND is_active = 1");
    $stmt_oferta->execute([$checkout_hash]);
    $oferta = $stmt_oferta->fetch(PDO::FETCH_ASSOC);
    
    if ($oferta) {
        // É uma oferta - buscar produto original
        $stmt_prod = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
        $stmt_prod->execute([$oferta['produto_id']]);
        $produto = $stmt_prod->fetch(PDO::FETCH_ASSOC);
        
        if (!$produto) {
            die("Produto original da oferta não encontrado.");
        }
        
        // Usar preço e nome da oferta, mas manter tudo mais do produto original
        $produto['preco'] = $oferta['preco'];
        // Opcional: usar nome da oferta no checkout (ou manter nome do produto)
        // $produto['nome'] = $oferta['nome']; // Descomente se quiser usar nome da oferta
    } else {
        // Não é oferta - buscar produto normalmente
        $stmt_prod = $pdo->prepare("SELECT * FROM produtos WHERE checkout_hash = ?");
        $stmt_prod->execute([$checkout_hash]);
        $produto = $stmt_prod->fetch(PDO::FETCH_ASSOC);
    }

    if (!$produto) {
        die("Produto inválido ou não existe mais.");
    }
    
    // Define o gateway (padrão mercadopago se estiver vazio)
    $gateway = $produto['gateway'] ?? 'mercadopago';

    // 2. Busca os order bumps
    $stmt_ob = $pdo->prepare("
        SELECT 
            ob.*, 
            p.id as ob_id,
            p.nome as ob_nome, 
            p.preco as ob_preco, 
            p.preco_anterior as ob_preco_anterior,
            p.foto as ob_foto 
        FROM order_bumps as ob
        JOIN produtos as p ON ob.offer_product_id = p.id
        WHERE ob.main_product_id = ? AND ob.is_active = 1
        ORDER BY ob.ordem ASC
    ");
    $stmt_ob->execute([$produto['id']]);
    $order_bumps = $stmt_ob->fetchAll(PDO::FETCH_ASSOC);

    $checkout_config = json_decode($produto['checkout_config'] ?? '{}', true);
    if (!is_array($checkout_config)) { $checkout_config = []; }

    // LÓGICA DE RASTREAMENTO
    $tracking_config = $checkout_config['tracking'] ?? [];
    if (empty($tracking_config['facebookPixelId']) && !empty($checkout_config['facebookPixelId'])) {
        $tracking_config['facebookPixelId'] = $checkout_config['facebookPixelId'];
    }
    $fbPixelId = $tracking_config['facebookPixelId'] ?? '';
    $gaId = $tracking_config['googleAnalyticsId'] ?? '';
    $gAdsId = $tracking_config['googleAdsId'] ?? '';
    $tracking_events = $tracking_config['events'] ?? [];
    $fb_events_enabled = $tracking_events['facebook'] ?? [];
    $gg_events_enabled = $tracking_events['google'] ?? [];

    $infoprodutor_id = $produto['usuario_id'];
    
    // Busca o nome do vendedor e as public keys (MP, Beehive, Hypercash e Efí)
    $stmt_vendedor = $pdo->prepare("SELECT nome, mp_public_key, beehive_public_key, hypercash_public_key, efi_payee_code FROM usuarios WHERE id = ?");
    $stmt_vendedor->execute([$infoprodutor_id]);
    $vendedor_data = $stmt_vendedor->fetch(PDO::FETCH_ASSOC);
    $public_key = $vendedor_data['mp_public_key'] ?? '';
    $beehive_public_key = $vendedor_data['beehive_public_key'] ?? '';
    $hypercash_public_key = $vendedor_data['hypercash_public_key'] ?? '';
    $efi_payee_code = $vendedor_data['efi_payee_code'] ?? '';
    $vendedor_nome = $vendedor_data['nome'] ?? 'Vendedor';

} catch (PDOException $e) {
    die("Erro de banco de dados: " . $e->getMessage());
}

// Garantir que $oferta esteja definida mesmo fora do try
if (!isset($oferta)) {
    $oferta = null;
}

$orderbump_active = !empty($order_bumps);

// Configurações de Estilo e Funcionalidade
$backgroundColor = $checkout_config['backgroundColor'] ?? '#E3E3E3';
$accentColor = $checkout_config['accentColor'] ?? '#7427F1';
$banners = $checkout_config['banners'] ?? [];
// Migrar bannerUrl antigo para array de banners se necessário
if (empty($banners) && !empty($checkout_config['bannerUrl'])) { 
    $banners = [$checkout_config['bannerUrl']]; 
}
// Garantir que seja um array e filtrar valores vazios
if (!is_array($banners)) {
    $banners = [];
}
$banners = array_filter($banners, function($banner) {
    return !empty($banner) && trim($banner) !== '';
});
// Normalizar caminhos dos banners (garantir / no início)
$banners = array_map(function($banner) {
    if (!empty($banner) && strpos($banner, '/') !== 0 && strpos($banner, 'http') !== 0) {
        return '/' . ltrim($banner, '/');
    }
    return $banner;
}, $banners);
// Reindexar array após filtro
$banners = array_values($banners);

$sideBanners = $checkout_config['sideBanners'] ?? [];
// Migrar sideBannerUrl antigo para array de banners se necessário
if (empty($sideBanners) && !empty($checkout_config['sideBannerUrl'])) { 
    $sideBanners = [$checkout_config['sideBannerUrl']]; 
}
// Garantir que seja um array e filtrar valores vazios
if (!is_array($sideBanners)) {
    $sideBanners = [];
}
$sideBanners = array_filter($sideBanners, function($banner) {
    return !empty($banner) && trim($banner) !== '';
});
// Normalizar caminhos dos banners laterais (garantir / no início)
$sideBanners = array_map(function($banner) {
    if (!empty($banner) && strpos($banner, '/') !== 0 && strpos($banner, 'http') !== 0) {
        return '/' . ltrim($banner, '/');
    }
    return $banner;
}, $sideBanners);
// Reindexar array após filtro
$sideBanners = array_values($sideBanners);
// Debug: log dos banners laterais carregados
error_log("Checkout: Banners laterais carregados: " . json_encode($sideBanners));
$youtubeUrl = $checkout_config['youtubeUrl'] ?? null;
$timerConfig = $checkout_config['timer'] ?? ['enabled' => false, 'minutes' => 15, 'text' => 'Esta oferta expira em:', 'bgcolor' => '#000000', 'textcolor' => '#FFFFFF', 'sticky' => true];
$salesNotificationConfig = $checkout_config['salesNotification'] ?? ['enabled' => false, 'names' => '', 'product' => '', 'tempo_exibicao' => 5, 'intervalo_notificacao' => 10];
$backRedirectConfig = $checkout_config['backRedirect'] ?? ['enabled' => false, 'url' => ''];
$redirectUrlConfig = $checkout_config['redirectUrl'] ?? '';
// Ler paymentMethods com retrocompatibilidade
$payment_methods_config = $checkout_config['paymentMethods'] ?? [];
if (empty($payment_methods_config) || !isset($payment_methods_config['pix']['gateway'])) {
    // Estrutura antiga - migrar para nova estrutura
    $old_payment_methods = $checkout_config['paymentMethods'] ?? ['credit_card' => false, 'pix' => false, 'ticket' => false];
    $payment_methods_config = [
        'pix' => [
            'gateway' => ($gateway === 'pushinpay') ? 'pushinpay' : 'mercadopago',
            'enabled' => $old_payment_methods['pix'] ?? false
        ],
        'credit_card' => [
            'gateway' => 'mercadopago',
            'enabled' => $old_payment_methods['credit_card'] ?? false
        ],
        'ticket' => [
            'gateway' => 'mercadopago',
            'enabled' => $old_payment_methods['ticket'] ?? false
        ]
    ];
}

$customer_fields_config = $checkout_config['customer_fields'] ?? ['enable_cpf' => true, 'enable_phone' => true];
// CPF sempre obrigatório, mesmo se configurado como false no banco
$customer_fields_config['enable_cpf'] = true;

// Calcular variáveis de métodos de pagamento habilitados no escopo global
// (necessário para inicialização do JavaScript do Mercado Pago, Beehive e Hypercash)
$pix_pushinpay_enabled = false;
$pix_mercadopago_enabled = false;
$pix_efi_enabled = false;
$credit_card_enabled = false;
$credit_card_beehive_enabled = false;
$credit_card_hypercash_enabled = false;
$credit_card_mercadopago_enabled = false;
$credit_card_efi_enabled = false;
$ticket_enabled = false;

// Ler nova estrutura com gateway por método
if (isset($payment_methods_config['pix']['gateway'])) {
    if ($payment_methods_config['pix']['gateway'] === 'pushinpay' && ($payment_methods_config['pix']['enabled'] ?? false)) {
        $pix_pushinpay_enabled = true;
    } elseif ($payment_methods_config['pix']['gateway'] === 'mercadopago' && ($payment_methods_config['pix']['enabled'] ?? false)) {
        $pix_mercadopago_enabled = true;
    } elseif ($payment_methods_config['pix']['gateway'] === 'efi' && ($payment_methods_config['pix']['enabled'] ?? false)) {
        $pix_efi_enabled = true;
    }
}

if (isset($payment_methods_config['credit_card']['enabled']) && $payment_methods_config['credit_card']['enabled']) {
    $credit_card_enabled = true;
    // Verificar qual gateway está configurado para cartão
    $credit_card_gateway = $payment_methods_config['credit_card']['gateway'] ?? 'mercadopago';
    if ($credit_card_gateway === 'hypercash') {
        $credit_card_hypercash_enabled = true;
    } elseif ($credit_card_gateway === 'beehive') {
        $credit_card_beehive_enabled = true;
    } elseif ($credit_card_gateway === 'efi') {
        $credit_card_efi_enabled = true;
    } else {
        $credit_card_mercadopago_enabled = true;
    }
}

if (isset($payment_methods_config['ticket']['enabled']) && $payment_methods_config['ticket']['enabled']) {
    $ticket_enabled = true;
}

// Variáveis de Resumo
$main_price = floatval($produto['preco']);
// Se for oferta, usar nome da oferta no resumo (ou manter nome do produto)
$main_name = !empty($checkout_config['summary']['product_name']) ? $checkout_config['summary']['product_name'] : ($oferta ? $oferta['nome'] : $produto['nome']);
$main_image = 'uploads/' . htmlspecialchars($produto['foto'] ?: 'placeholder.png');
$formattedMainPrice = 'R$ ' . number_format($main_price, 2, ',', '.');
$preco_anterior_raw = !empty($produto['preco_anterior']) ? floatval($produto['preco_anterior']) : null;
$formattedPrecoAnterior = $preco_anterior_raw ? 'R$ ' . number_format($preco_anterior_raw, 2, ',', '.') : null;
$discount_text = $checkout_config['summary']['discount_text'] ?? '';

// --- Funções de Renderização ---

function render_timer($timerConfig) {
    if (!($timerConfig['enabled'] ?? false)) return '';
    $text = htmlspecialchars($timerConfig['text'] ?? 'Esta oferta expira em:');
    $minutes = intval($timerConfig['minutes'] ?? 15);
    $bgcolor = htmlspecialchars($timerConfig['bgcolor'] ?? '#000000');
    $textcolor = htmlspecialchars($timerConfig['textcolor'] ?? '#FFFFFF');
    $is_sticky = $timerConfig['sticky'] ?? true;
    $transparent_bgcolor = $bgcolor . '99'; 
    $sticky_style = $is_sticky ? 'position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: background-color 0.3s ease, backdrop-filter 0.3s ease;' : 'position: relative;';
    $storage_key = 'checkoutTimer_' . htmlspecialchars($_GET['p'] ?? 'default');
    
    $js_script = "<script>
        document.addEventListener('DOMContentLoaded', () => {
            const timerData = { minutes: {$minutes}, storageKey: '{$storage_key}' };
            const timerElement = document.getElementById('timer-countdown-display');
            if (!timerElement) return;
            let endTime = localStorage.getItem(timerData.storageKey);
            if (!endTime || isNaN(endTime)) {
                endTime = new Date().getTime() + (timerData.minutes * 60 * 1000);
                localStorage.setItem(timerData.storageKey, endTime);
            }
            const interval = setInterval(() => {
                const now = new Date().getTime();
                const distance = endTime - now;
                if (distance < 0) {
                    clearInterval(interval);
                    timerElement.innerHTML = '00:00';
                    localStorage.removeItem(timerData.storageKey);
                    return;
                }
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                timerElement.innerHTML = (minutes < 10 ? '0' + minutes : minutes) + ':' + (seconds < 10 ? '0' + seconds : seconds);
            }, 1000);
            const timerBar = document.getElementById('timer-bar');
            if (timerBar && {$is_sticky}) {
                const solidColor = '{$bgcolor}';
                const transparentColor = '{$transparent_bgcolor}';
                window.addEventListener('scroll', () => {
                    if (window.scrollY > 0) {
                        timerBar.style.backgroundColor = transparentColor;
                        timerBar.style.backdropFilter = 'blur(8px)';
                        timerBar.style.webkitBackdropFilter = 'blur(8px)';
                    } else {
                        timerBar.style.backgroundColor = solidColor;
                        timerBar.style.backdropFilter = 'none';
                        timerBar.style.webkitBackdropFilter = 'none';
                    }
                });
            }
        });
        </script>";
    return "<div id='timer-bar' style='background-color: {$bgcolor}; color: {$textcolor}; {$sticky_style}'><div class='flex items-center justify-center p-3 text-center w-full'><i data-lucide='clock' class='w-5 h-5 mr-3 flex-shrink-0'></i><p class='font-semibold'>{$text}</p><span id='timer-countdown-display' class='font-bold text-lg ml-2 font-mono w-14'>{$minutes}:00</span></div></div>{$js_script}";
}

function render_youtube_video($youtubeUrl) {
    require_once __DIR__ . '/helpers/security_helper.php';
    if (!$youtubeUrl) return '';
    $sanitized_url = sanitize_url($youtubeUrl, false);
    if (empty($sanitized_url)) return '';
    preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $sanitized_url, $match);
    $youtube_id = $match[1] ?? null;
    if(!$youtube_id || !preg_match('/^[a-zA-Z0-9_-]{11}$/', $youtube_id)) return '';
    $youtube_id = htmlspecialchars($youtube_id, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return "<div data-id='youtube_video' class='mb-6'><div class='aspect-video rounded-lg overflow-hidden shadow-md'><iframe src='https://www.youtube.com/embed/{$youtube_id}' frameborder='0' allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture' allowfullscreen class='w-full h-full'></iframe></div></div>";
}

function render_order_bumps_section($order_bumps_array) {
    require_once __DIR__ . '/helpers/security_helper.php';
    if (empty($order_bumps_array)) return '';
    $html = "<div data-id='order_bump' class='space-y-6'>";
    foreach($order_bumps_array as $index => $bump) {
        $ob_image_path = $bump['ob_foto'] ?: 'placeholder.png';
        $ob_image = 'uploads/' . htmlspecialchars(basename($ob_image_path), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ob_headline = escape_html_output($bump['headline']);
        $ob_description = escape_html_output($bump['description']);
        $ob_name = escape_html_output($bump['ob_nome']);
        $ob_price_formatted = 'R$ ' . number_format(floatval($bump['ob_preco']), 2, ',', '.');
        $ob_price_raw = floatval($bump['ob_preco']);
        $ob_id = intval($bump['ob_id']);

        $html .= "<div class='order-bump-wrapper'>";
        $html .= "<input type='checkbox' id='orderbump-checkbox-{$ob_id}' data-product-id='{$ob_id}' data-price='{$ob_price_raw}' data-name='" . htmlspecialchars($ob_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "' class='orderbump-checkbox sr-only'>";
        $html .= "<label for='orderbump-checkbox-{$ob_id}' class='order-bump-block'>"; 
        $html .= "<div class='offer-badge'>Oferta Especial</div>";
        $html .= "<div class='flex items-start gap-4'><img src='{$ob_image}' class='w-16 h-16 rounded-md object-cover border shadow-sm flex-shrink-0' onerror=\"this.src='https://placehold.co/64x64/e2e8f0/334155?text=Produto'\"/><div class='flex-1'><h4 class='text-lg font-bold text-gray-800'>{$ob_headline}</h4><p class='text-sm text-gray-600 mt-1'>{$ob_description}</p></div></div>";
        $html .= "<hr class='my-3 border-dashed border-gray-300'>";
        $html .= "<div class='flex justify-between items-center'><div class='flex items-center gap-2'><div class='custom-checkbox flex-shrink-0'><i data-lucide='check' class='checkmark'></i></div><span class='font-semibold text-gray-800 text-sm sm:text-base'>Sim, quero esta oferta!</span></div><p class='font-bold text-green-600 text-lg'>+{$ob_price_formatted}</p></div>";
        $html .= "</label></div>";
    }
    $html .= "</div>";
    return $html;
}

function render_payment_methods_selector($pix_pushinpay_enabled, $pix_mercadopago_enabled, $pix_efi_enabled, $credit_card_enabled, $ticket_enabled, $accentColor, $credit_card_beehive_enabled = false, $credit_card_mercadopago_enabled = false, $credit_card_hypercash_enabled = false, $credit_card_efi_enabled = false) {
    $available_methods = [];
    
    // Pix - prioridade PushinPay > Efí > Mercado Pago
    if ($pix_pushinpay_enabled) {
        $available_methods[] = ['type' => 'pix_pushinpay', 'name' => 'Pix', 'icon' => 'qr-code', 'gateway' => 'pushinpay'];
    } elseif ($pix_efi_enabled) {
        $available_methods[] = ['type' => 'pix_efi', 'name' => 'Pix', 'icon' => 'qr-code', 'gateway' => 'efi'];
    } elseif ($pix_mercadopago_enabled) {
        $available_methods[] = ['type' => 'pix_mercadopago', 'name' => 'Pix', 'icon' => 'qr-code', 'gateway' => 'mercadopago'];
    }
    
    // Cartão de Crédito - prioridade Hypercash > Beehive > Efí > Mercado Pago
    if ($credit_card_hypercash_enabled) {
        $available_methods[] = ['type' => 'credit_card_hypercash', 'name' => 'Cartão de Crédito', 'icon' => 'credit-card', 'gateway' => 'hypercash'];
    } elseif ($credit_card_beehive_enabled) {
        $available_methods[] = ['type' => 'credit_card_beehive', 'name' => 'Cartão de Crédito', 'icon' => 'credit-card', 'gateway' => 'beehive'];
    } elseif ($credit_card_efi_enabled) {
        $available_methods[] = ['type' => 'credit_card_efi', 'name' => 'Cartão de Crédito', 'icon' => 'credit-card', 'gateway' => 'efi'];
    } elseif ($credit_card_mercadopago_enabled) {
        $available_methods[] = ['type' => 'credit_card', 'name' => 'Cartão de Crédito', 'icon' => 'credit-card', 'gateway' => 'mercadopago'];
    } elseif ($credit_card_enabled) {
        // Retrocompatibilidade
        $available_methods[] = ['type' => 'credit_card', 'name' => 'Cartão de Crédito', 'icon' => 'credit-card', 'gateway' => 'mercadopago'];
    }
    
    if ($ticket_enabled) {
        $available_methods[] = ['type' => 'ticket', 'name' => 'Boleto', 'icon' => 'file-text', 'gateway' => 'mercadopago'];
    }
    
    if (empty($available_methods)) {
        return '';
    }
    
    $accentColorEscaped = htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8');
    
    $html = "<div class='mb-6'>";
    $html .= "<h3 class='text-lg font-semibold mb-4 text-gray-800 flex items-center'><i data-lucide='wallet' class='w-5 h-5 mr-2'></i>Escolha a forma de pagamento</h3>";
    $html .= "<div class='grid grid-cols-2 lg:grid-cols-3 gap-4' id='payment-methods-selector'>";
    
    foreach ($available_methods as $method) {
        $methodType = htmlspecialchars($method['type'], ENT_QUOTES, 'UTF-8');
        $methodName = htmlspecialchars($method['name'], ENT_QUOTES, 'UTF-8');
        $methodIcon = htmlspecialchars($method['icon'], ENT_QUOTES, 'UTF-8');
        
        $html .= "<div class='payment-method-card bg-white rounded-lg border-2 border-gray-200 p-4 cursor-pointer transition-all hover:shadow-lg flex flex-col items-center justify-center text-center min-h-[120px]' data-payment-method='{$methodType}' style='border-color: #e5e7eb;'>";
        
        // Se for Pix, usar logo oficial ao invés de ícone
        if ($methodType === 'pix_pushinpay' || $methodType === 'pix_mercadopago' || $methodType === 'pix_efi') {
            $html .= "<svg width='32' height='32' viewBox='0 0 16 16' xmlns='http://www.w3.org/2000/svg' class='mb-2' style='color: {$accentColorEscaped};' fill='currentColor'><path d='M11.917 11.71a2.046 2.046 0 0 1-1.454-.602l-2.1-2.1a.4.4 0 0 0-.551 0l-2.108 2.108a2.044 2.044 0 0 1-1.454.602h-.414l2.66 2.66c.83.83 2.177.83 3.007 0l2.667-2.668h-.253zM4.25 4.282c.55 0 1.066.214 1.454.602l2.108 2.108a.39.39 0 0 0 .552 0l2.1-2.1a2.044 2.044 0 0 1 1.453-.602h.253L9.503 1.623a2.127 2.127 0 0 0-3.007 0l-2.66 2.66h.414z'/><path d='m14.377 6.496-1.612-1.612a.307.307 0 0 1-.114.023h-.733c-.379 0-.75.154-1.017.422l-2.1 2.1a1.005 1.005 0 0 1-1.425 0L5.268 5.32a1.448 1.448 0 0 0-1.018-.422h-.9a.306.306 0 0 1-.109-.021L1.623 6.496c-.83.83-.83 2.177 0 3.008l1.618 1.618a.305.305 0 0 1 .108-.022h.901c.38 0 .75-.153 1.018-.421L7.375 8.57a1.034 1.034 0 0 1 1.426 0l2.1 2.1c.267.268.638.421 1.017.421h.733c.04 0 .079.01.114.024l1.612-1.612c.83-.83.83-2.178 0-3.008z'/></svg>";
        } else {
            $html .= "<i data-lucide='{$methodIcon}' class='w-8 h-8 mb-2' style='color: {$accentColorEscaped};'></i>";
        }
        
        $html .= "<span class='font-semibold text-gray-800 text-sm'>{$methodName}</span>";
        $html .= "</div>";
    }
    
    $html .= "</div>";
    $html .= "</div>";
    
    return $html;
}

function render_payment_section($gateway, $accentColor, $payment_methods_config, $pix_pushinpay_enabled = null, $pix_mercadopago_enabled = null, $pix_efi_enabled = null, $credit_card_enabled = null, $ticket_enabled = null, $credit_card_beehive_enabled = null, $credit_card_mercadopago_enabled = null, $credit_card_hypercash_enabled = null, $credit_card_efi_enabled = null) {
    $html = "<div data-id='payment'>";
    $html .= "<div id='payment_section_wrapper'>";
    
    // Se as variáveis não foram passadas, calcular (retrocompatibilidade)
    if ($pix_pushinpay_enabled === null || $pix_mercadopago_enabled === null || $pix_efi_enabled === null || $credit_card_enabled === null || $ticket_enabled === null) {
        $pix_pushinpay_enabled = false;
        $pix_mercadopago_enabled = false;
        $pix_efi_enabled = false;
        $credit_card_enabled = false;
        $credit_card_beehive_enabled = false;
        $credit_card_hypercash_enabled = false;
        $credit_card_mercadopago_enabled = false;
        $credit_card_efi_enabled = false;
        $ticket_enabled = false;
        
        // Ler nova estrutura com gateway por método
        if (isset($payment_methods_config['pix']['gateway'])) {
            if ($payment_methods_config['pix']['gateway'] === 'pushinpay' && ($payment_methods_config['pix']['enabled'] ?? false)) {
                $pix_pushinpay_enabled = true;
            } elseif ($payment_methods_config['pix']['gateway'] === 'mercadopago' && ($payment_methods_config['pix']['enabled'] ?? false)) {
                $pix_mercadopago_enabled = true;
            } elseif ($payment_methods_config['pix']['gateway'] === 'efi' && ($payment_methods_config['pix']['enabled'] ?? false)) {
                $pix_efi_enabled = true;
            }
        }
        
        if (isset($payment_methods_config['credit_card']['enabled']) && $payment_methods_config['credit_card']['enabled']) {
            $credit_card_enabled = true;
            $credit_card_gateway = $payment_methods_config['credit_card']['gateway'] ?? 'mercadopago';
            if ($credit_card_gateway === 'beehive') {
                $credit_card_beehive_enabled = true;
            } elseif ($credit_card_gateway === 'hypercash') {
                $credit_card_hypercash_enabled = true;
            } elseif ($credit_card_gateway === 'efi') {
                $credit_card_efi_enabled = true;
            } else {
                $credit_card_mercadopago_enabled = true;
            }
        }
        
        if (isset($payment_methods_config['ticket']['enabled']) && $payment_methods_config['ticket']['enabled']) {
            $ticket_enabled = true;
        }
    }
    
    // Renderizar seletor de métodos de pagamento
    $html .= render_payment_methods_selector($pix_pushinpay_enabled, $pix_mercadopago_enabled, $pix_efi_enabled, $credit_card_enabled, $ticket_enabled, $accentColor, $credit_card_beehive_enabled, $credit_card_mercadopago_enabled, $credit_card_hypercash_enabled, $credit_card_efi_enabled ?? false);
    
    // Container PushinPay Pix
    if ($pix_pushinpay_enabled) {
        $html .= "<div class='payment-method-container hidden' data-method-type='pix_pushinpay'>";
        $html .= "<div class='bg-white rounded-lg border border-gray-200 p-5 shadow-sm'>";
        $html .= "<div class='border-2 border-green-500 bg-green-50 rounded-lg p-4 flex items-center justify-between cursor-default mb-4'>";
            $html .= "<div class='flex items-center gap-3'>";
                $html .= "<img src='/assets/pix.svg' class='h-6 w-auto' alt='Pix' style='filter: brightness(0) saturate(100%) invert(60%) sepia(95%) saturate(1200%) hue-rotate(140deg) brightness(0.9) contrast(0.9);'>";
                $html .= "<span class='font-bold text-gray-800'>Pix</span>";
            $html .= "</div>";
            $html .= "<div class='w-5 h-5 rounded-full border-4 border-green-500'></div>";
        $html .= "</div>";
        
        $html .= "<div class='text-sm text-gray-600 bg-gray-50 p-3 rounded border border-gray-200 mb-4'>";
            $html .= "<p>• Liberação imediata do acesso.</p>";
            $html .= "<p>• 100% Seguro.</p>";
        $html .= "</div>";
        
        $html .= "<button id='btn-pagar-pushinpay' class='w-full bg-green-600 text-white font-bold py-4 rounded-lg hover:bg-green-700 transition duration-300 text-lg flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transform active:scale-95'>";
            $html .= "<i data-lucide='qr-code' class='w-6 h-6'></i> GERAR PIX AGORA";
        $html .= "</button>";
        
        $html .= "</div>";
        $html .= "</div>";
    }
    
    // Container Mercado Pago Pix
    if ($pix_mercadopago_enabled) {
        $enabled_payment_methods = ['bankTransfer' => 'all'];
        $json_config = htmlspecialchars(json_encode($enabled_payment_methods), ENT_QUOTES, 'UTF-8');
        
        $html .= "<div class='payment-method-container hidden' data-method-type='pix_mercadopago'>";
        $html .= "<div id='payment_container_wrapper_pix_mp' class='bg-white rounded-lg border border-gray-200 p-5 shadow-sm' data-mp-config='{$json_config}'>";
        $html .= "<div id='loading_spinner_pix_mp' class='flex flex-col items-center justify-center py-12 text-gray-500'><svg class='animate-spin h-8 w-8' style='color: {$accentColor};' xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24'><circle class='opacity-25' cx='12' cy='12' r='10' stroke='currentColor' stroke-width='4'></circle><path class='opacity-75' fill='currentColor' d='M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z'></path></svg><p class='mt-4 font-medium'>Carregando pagamento seguro...</p></div>";
        $html .= "<div id='paymentBrick_container_pix_mp'></div>";
        $html .= "</div>";
        $html .= "</div>";
    }
    
    // Container Efí Pix
    if ($pix_efi_enabled) {
        $html .= "<div class='payment-method-container hidden' data-method-type='pix_efi'>";
        $html .= "<div class='bg-white rounded-lg border border-gray-200 p-5 shadow-sm'>";
        $html .= "<div class='border-2 border-purple-500 bg-purple-50 rounded-lg p-4 flex items-center justify-between cursor-default mb-4'>";
            $html .= "<div class='flex items-center gap-3'>";
                $html .= "<img src='/assets/pix.svg' class='h-6 w-auto' alt='Pix' style='filter: brightness(0) saturate(100%) invert(60%) sepia(95%) saturate(1200%) hue-rotate(140deg) brightness(0.9) contrast(0.9);'>";
                $html .= "<span class='font-bold text-gray-800'>Pix</span>";
            $html .= "</div>";
            $html .= "<div class='w-5 h-5 rounded-full border-4 border-purple-500'></div>";
        $html .= "</div>";
        
        $html .= "<div class='text-sm text-gray-600 bg-gray-50 p-3 rounded border border-gray-200 mb-4'>";
            $html .= "<p>• Liberação imediata do acesso.</p>";
            $html .= "<p>• 100% Seguro.</p>";
        $html .= "</div>";
        
        $html .= "<button id='btn-pagar-efi' class='w-full bg-purple-600 text-white font-bold py-4 rounded-lg hover:bg-purple-700 transition duration-300 text-lg flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transform active:scale-95'>";
            $html .= "<i data-lucide='qr-code' class='w-6 h-6'></i> GERAR PIX AGORA";
        $html .= "</button>";
        
        $html .= "</div>";
        $html .= "</div>";
    }
    
    // Container Cartão de Crédito Mercado Pago
    // Só renderizar se Mercado Pago estiver explicitamente habilitado OU se nenhum outro gateway de cartão estiver habilitado
    $has_other_card_gateway = (isset($credit_card_beehive_enabled) && $credit_card_beehive_enabled) || 
                              (isset($credit_card_hypercash_enabled) && $credit_card_hypercash_enabled) || 
                              (isset($credit_card_efi_enabled) && $credit_card_efi_enabled);
    if ($credit_card_mercadopago_enabled || ($credit_card_enabled && !$has_other_card_gateway)) {
        $enabled_payment_methods = ['creditCard' => 'all'];
        $json_config = htmlspecialchars(json_encode($enabled_payment_methods), ENT_QUOTES, 'UTF-8');
        
        $html .= "<div class='payment-method-container hidden' data-method-type='credit_card'>";
        $html .= "<div id='payment_container_wrapper_credit' class='bg-white rounded-lg border border-gray-200 p-5 shadow-sm' data-mp-config='{$json_config}'>";
        $html .= "<div id='loading_spinner_credit' class='flex flex-col items-center justify-center py-12 text-gray-500'><svg class='animate-spin h-8 w-8' style='color: {$accentColor};' xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24'><circle class='opacity-25' cx='12' cy='12' r='10' stroke='currentColor' stroke-width='4'></circle><path class='opacity-75' fill='currentColor' d='M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z'></path></svg><p class='mt-4 font-medium'>Carregando pagamento seguro...</p></div>";
        $html .= "<div id='paymentBrick_container_credit'></div>";
        $html .= "</div>";
        $html .= "</div>";
    }
    
    // Container Cartão de Crédito Beehive
    if ($credit_card_beehive_enabled) {
        $html .= "<div class='payment-method-container hidden' data-method-type='credit_card_beehive'>";
        $html .= "<div class='bg-white rounded-lg border border-gray-200 p-5 shadow-sm'>";
        $html .= "<div class='border-2 border-yellow-500 bg-yellow-50 rounded-lg p-4 flex items-center justify-between cursor-default mb-4'>";
            $html .= "<div class='flex items-center gap-3'>";
                $html .= "<i data-lucide='credit-card' class='w-6 h-6 text-yellow-600'></i>";
                $html .= "<span class='font-bold text-gray-800'>Cartão de Crédito</span>";
            $html .= "</div>";
            $html .= "<div class='w-5 h-5 rounded-full border-4 border-yellow-500'></div>";
        $html .= "</div>";
        
        $html .= "<form id='beehive-card-form' class='space-y-4'>";
        $html .= "<div>";
            $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>Número do Cartão</label>";
            $html .= "<input type='text' id='beehive-card-number' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500' placeholder='0000 0000 0000 0000' maxlength='19'>";
        $html .= "</div>";
        
        $html .= "<div>";
            $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>Nome no Cartão</label>";
            $html .= "<input type='text' id='beehive-card-holder' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500' placeholder='NOME COMPLETO'>";
        $html .= "</div>";
        
        $html .= "<div class='grid grid-cols-2 gap-4'>";
            $html .= "<div>";
                $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>Validade</label>";
                $html .= "<input type='text' id='beehive-card-expiry' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500' placeholder='MM/AA' maxlength='5'>";
            $html .= "</div>";
            $html .= "<div>";
                $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>CVV</label>";
                $html .= "<input type='text' id='beehive-card-cvv' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500' placeholder='123' maxlength='4'>";
            $html .= "</div>";
        $html .= "</div>";
        
        $html .= "<div class='text-sm text-gray-600 bg-gray-50 p-3 rounded border border-gray-200 mb-4'>";
            $html .= "<p>• Aprovação imediata do acesso.</p>";
            $html .= "<p>• 100% Seguro e criptografado.</p>";
        $html .= "</div>";
        
        $html .= "<button type='button' id='btn-pagar-beehive' class='w-full bg-yellow-600 text-white font-bold py-4 rounded-lg hover:bg-yellow-700 transition duration-300 text-lg flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transform active:scale-95'>";
            $html .= "<i data-lucide='credit-card' class='w-6 h-6'></i> FINALIZAR PAGAMENTO";
        $html .= "</button>";
        
        $html .= "</form>";
        $html .= "</div>";
        $html .= "</div>";
    }
    
    // Container Cartão de Crédito Hypercash
    if ($credit_card_hypercash_enabled) {
        $html .= "<div class='payment-method-container hidden' data-method-type='credit_card_hypercash'>";
        $html .= "<div class='bg-white rounded-lg border border-gray-200 p-5 shadow-sm'>";
        $html .= "<div class='border-2 border-indigo-500 bg-indigo-50 rounded-lg p-4 flex items-center justify-between cursor-default mb-4'>";
            $html .= "<div class='flex items-center gap-3'>";
                $html .= "<i data-lucide='credit-card' class='w-6 h-6 text-indigo-600'></i>";
                $html .= "<span class='font-bold text-gray-800'>Cartão de Crédito</span>";
            $html .= "</div>";
            $html .= "<div class='w-5 h-5 rounded-full border-4 border-indigo-500'></div>";
        $html .= "</div>";
        
        $html .= "<form id='hypercash-card-form' class='space-y-4'>";
        $html .= "<div>";
            $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>Número do Cartão</label>";
            $html .= "<input type='text' id='hypercash-card-number' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500' placeholder='0000 0000 0000 0000' maxlength='19'>";
        $html .= "</div>";
        
        $html .= "<div>";
            $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>Nome no Cartão</label>";
            $html .= "<input type='text' id='hypercash-card-holder' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500' placeholder='NOME COMPLETO'>";
        $html .= "</div>";
        
        $html .= "<div class='grid grid-cols-2 gap-4'>";
            $html .= "<div>";
                $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>Validade</label>";
                $html .= "<input type='text' id='hypercash-card-expiry' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500' placeholder='MM/AA' maxlength='5'>";
            $html .= "</div>";
            $html .= "<div>";
                $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>CVV</label>";
                $html .= "<input type='text' id='hypercash-card-cvv' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500' placeholder='123' maxlength='4'>";
            $html .= "</div>";
        $html .= "</div>";
        
        $html .= "<div class='text-sm text-gray-600 bg-gray-50 p-3 rounded border border-gray-200 mb-4'>";
            $html .= "<p>• Aprovação imediata do acesso.</p>";
            $html .= "<p>• 100% Seguro e criptografado.</p>";
        $html .= "</div>";
        
        $html .= "<button type='button' id='btn-pagar-hypercash' class='w-full bg-indigo-600 text-white font-bold py-4 rounded-lg hover:bg-indigo-700 transition duration-300 text-lg flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transform active:scale-95'>";
            $html .= "<i data-lucide='credit-card' class='w-6 h-6'></i> FINALIZAR PAGAMENTO";
        $html .= "</button>";
        
        $html .= "</form>";
        $html .= "</div>";
        $html .= "</div>";
    }
    
    // Container Cartão de Crédito Efí
    if (isset($credit_card_efi_enabled) && $credit_card_efi_enabled) {
        $html .= "<div class='payment-method-container hidden' data-method-type='credit_card_efi'>";
        $html .= "<div class='bg-white rounded-lg border border-gray-200 p-5 shadow-sm'>";
        $html .= "<div class='border-2 border-purple-500 bg-purple-50 rounded-lg p-4 flex items-center justify-between cursor-default mb-4'>";
            $html .= "<div class='flex items-center gap-3'>";
                $html .= "<i data-lucide='credit-card' class='w-6 h-6 text-purple-600'></i>";
                $html .= "<span class='font-bold text-gray-800'>Cartão de Crédito</span>";
            $html .= "</div>";
            $html .= "<div class='w-5 h-5 rounded-full border-4 border-purple-500'></div>";
        $html .= "</div>";
        
        $html .= "<form id='efi-card-form' class='space-y-4'>";
        $html .= "<div>";
            $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>Número do Cartão</label>";
            $html .= "<input type='text' id='efi-card-number' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500' placeholder='0000 0000 0000 0000' maxlength='19'>";
        $html .= "</div>";
        
        $html .= "<div>";
            $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>Nome no Cartão</label>";
            $html .= "<input type='text' id='efi-card-holder' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500' placeholder='NOME COMPLETO'>";
        $html .= "</div>";
        
        $html .= "<div class='grid grid-cols-2 gap-4'>";
            $html .= "<div>";
                $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>Validade</label>";
                $html .= "<input type='text' id='efi-card-expiry' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500' placeholder='MM/AA' maxlength='5'>";
            $html .= "</div>";
            $html .= "<div>";
                $html .= "<label class='block text-sm font-medium text-gray-700 mb-2'>CVV</label>";
                $html .= "<input type='text' id='efi-card-cvv' class='w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500' placeholder='123' maxlength='4'>";
            $html .= "</div>";
        $html .= "</div>";
        
        $html .= "<div class='text-sm text-gray-600 bg-gray-50 p-3 rounded border border-gray-200 mb-4'>";
            $html .= "<p>• Aprovação imediata do acesso.</p>";
            $html .= "<p>• 100% Seguro e criptografado.</p>";
        $html .= "</div>";
        
        $html .= "<button type='button' id='btn-pagar-efi-card' class='w-full bg-purple-600 text-white font-bold py-4 rounded-lg hover:bg-purple-700 transition duration-300 text-lg flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transform active:scale-95'>";
            $html .= "<i data-lucide='credit-card' class='w-6 h-6'></i> FINALIZAR PAGAMENTO";
        $html .= "</button>";
        
        $html .= "</form>";
        $html .= "</div>";
        $html .= "</div>";
    }
    
    // Container Boleto
    if ($ticket_enabled) {
        $enabled_payment_methods = ['ticket' => 'all'];
        $json_config = htmlspecialchars(json_encode($enabled_payment_methods), ENT_QUOTES, 'UTF-8');
        
        $html .= "<div class='payment-method-container hidden' data-method-type='ticket'>";
        $html .= "<div id='payment_container_wrapper_ticket' class='bg-white rounded-lg border border-gray-200 p-5 shadow-sm' data-mp-config='{$json_config}'>";
        $html .= "<div id='loading_spinner_ticket' class='flex flex-col items-center justify-center py-12 text-gray-500'><svg class='animate-spin h-8 w-8' style='color: {$accentColor};' xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24'><circle class='opacity-25' cx='12' cy='12' r='10' stroke='currentColor' stroke-width='4'></circle><path class='opacity-75' fill='currentColor' d='M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z'></path></svg><p class='mt-4 font-medium'>Carregando pagamento seguro...</p></div>";
        $html .= "<div id='paymentBrick_container_ticket'></div>";
        $html .= "</div>";
        $html .= "</div>";
    }
    
    $html .= "</div></div>";
    return $html;
}

function render_security_info($vendedor_nome) {
    global $logo_checkout_url, $nome_plataforma;
    $vendedor_nome_html = htmlspecialchars($vendedor_nome);
    $logo_html = htmlspecialchars($logo_checkout_url);
    $nome_plataforma_html = htmlspecialchars($nome_plataforma);
    $html = "<div data-id='security_info' class='text-center text-xs text-gray-500 space-y-4'>"; 
    $html .= "<img src='{$logo_html}' alt='Logo {$nome_plataforma_html}' class='h-10 mx-auto mb-4'>";
    $html .= "<p><strong>{$nome_plataforma_html}</strong> está processando este pagamento para o vendedor <strong>{$vendedor_nome_html}</strong>.</p>";
    $html .= "<div class='flex items-center justify-center space-x-4'><div class='flex items-center space-x-1.5'><i data-lucide='shield-check' class='w-4 h-4 text-gray-400'></i><span>Compra 100% segura</span></div></div>";
    $html .= "<p>Este site é protegido pelo reCAPTCHA do Google<br><a href='#' class='underline hover:text-gray-700'>Política de privacidade</a> e <a href='#' class='underline hover:text-gray-700'>Termos de serviço</a>.</p>";
    $html .= "<p class='pt-4 text-gray-400'>Copyright &copy; " . date("Y") . ". Todos os direitos reservados.</p>";
    $html .= "</div>";
    return $html;
}

function render_sales_notification($config, $produto_nome_fallback) {
    // Renderiza o HTML sempre que estiver habilitado, mesmo sem nomes (o JS vai controlar)
    if (!($config['enabled'] ?? false)) return '';
    $notification_product_display = !empty($config['product']) ? $config['product'] : $produto_nome_fallback;
    return "<div id='sales-notification' class='fixed lg:bottom-4 left-4 w-80 bg-white/95 backdrop-blur-sm border border-gray-200 rounded-lg shadow-lg p-4 flex items-center space-x-4 transform translate-y-full opacity-0 transition-all duration-500 z-[9999]'><div class='bg-blue-100 text-blue-600 p-2 rounded-full'><i data-lucide='shopping-cart'></i></div><div><p class='text-sm font-semibold text-gray-900'><span id='notification-name'></span> acabou de comprar!</p><p class='text-xs text-gray-600' id='notification-product' data-fallback-product-name='".htmlspecialchars($notification_product_display)."'></p></div></div>";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — <?php echo htmlspecialchars($produto['nome']); ?></title>
    <?php
    // Adiciona favicon se configurado
    require_once __DIR__ . '/config/config.php';
    $favicon_url_raw = getSystemSetting('favicon_url', '');
    if (!empty($favicon_url_raw)) {
        $favicon_url = ltrim($favicon_url_raw, '/');
        if (strpos($favicon_url, 'http') !== 0) {
            if (strpos($favicon_url, 'uploads/') === 0) {
                $favicon_url = '/' . $favicon_url;
            } else {
                $favicon_url = '/' . $favicon_url;
            }
        }
        $favicon_ext = strtolower(pathinfo($favicon_url, PATHINFO_EXTENSION));
        $favicon_type = 'image/x-icon';
        if ($favicon_ext === 'png') {
            $favicon_type = 'image/png';
        } elseif ($favicon_ext === 'svg') {
            $favicon_type = 'image/svg+xml';
        }
        echo '<link rel="icon" type="' . htmlspecialchars($favicon_type) . '" href="' . htmlspecialchars($favicon_url) . '">' . "\n";
    }
    ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { 'mono': ['"Roboto Mono"', 'monospace'], }, aspectRatio: { '1/1': '1 / 1', '16/9': '16 / 9' } } },
            plugins: [],
        }
    </script>
    
    <?php 
    // Carregar Mercado Pago SDK APENAS se houver métodos do MP habilitados E tiver public_key
    $has_mp_methods_for_script = ($pix_mercadopago_enabled || $credit_card_mercadopago_enabled || $ticket_enabled);
    $should_load_mp_script = $has_mp_methods_for_script && !empty($public_key) && !isset($_GET['preview']);
    if ($should_load_mp_script): ?>
    <script src="https://sdk.mercadopago.com/js/v2"></script>
    <?php endif; ?>
    
    <?php 
    // Carregar Beehive SDK APENAS se houver método Beehive habilitado E tiver public_key
    $should_load_beehive_script = $credit_card_beehive_enabled && !empty($beehive_public_key) && !isset($_GET['preview']);
    if ($should_load_beehive_script): ?>
    <script src="https://api.conta.paybeehive.com.br/v1/js"></script>
    <?php endif; ?>
    
    <?php 
    // Carregar FastSoft SDK (Hypercash) APENAS se houver método Hypercash habilitado E tiver public_key
    $should_load_hypercash_script = (isset($credit_card_hypercash_enabled) && $credit_card_hypercash_enabled) && !empty($hypercash_public_key) && !isset($_GET['preview']);
    if ($should_load_hypercash_script): ?>
    <script src="https://js.fastsoftbrasil.com/security.js"></script>
    <?php endif; ?>
    
    <?php 
    // Carregar Efí Payment Token SDK APENAS se houver método Efí Cartão habilitado E tiver payee_code
    $should_load_efi_script = (isset($credit_card_efi_enabled) && $credit_card_efi_enabled) && !empty($efi_payee_code) && !isset($_GET['preview']);
    if ($should_load_efi_script): ?>
    <script src="https://cdn.jsdelivr.net/gh/efipay/js-payment-token-efi/dist/payment-token-efi-umd.min.js"></script>
    <?php endif; ?>

    <!-- Rastreamento (Pixel, Analytics) -->
    <?php if (!empty($fbPixelId) && !isset($_GET['preview'])): ?>
    <script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window, document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '<?php echo htmlspecialchars($fbPixelId); ?>');
    fbq('track', 'PageView');
    <?php if (!empty($fb_events_enabled['initiate_checkout'])) { echo "fbq('track', 'InitiateCheckout');"; } ?>
    </script>
    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo htmlspecialchars($fbPixelId); ?>&ev=PageView&noscript=1"/></noscript>
    <?php endif; ?>
    <!-- Fim Rastreamento -->

    <!-- Script Manual de Rastreamento -->
    <?php 
    require_once __DIR__ . '/helpers/security_helper.php';
    $custom_script = $tracking_config['customScript'] ?? '';
    if (!empty($custom_script) && !isset($_GET['preview'])): 
        $sanitized_script = sanitize_custom_script($custom_script);
        if (!empty($sanitized_script)) {
            echo $sanitized_script;
        }
    endif; 
    ?>
    <!-- Fim Script Manual -->

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
    <!-- CSRF Token para JavaScript -->
    <?php
    require_once __DIR__ . '/helpers/security_helper.php';
    $csrf_token_js = generate_csrf_token();
    ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
    <script>
        // Variável global para token CSRF
        window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
        
        // Variável global para armazenar chaves de gateway (carregadas via API)
        window.gatewayKeys = {
            beehive_public_key: null,
            hypercash_public_key: null,
            efi_payee_code: null
        };
        
        // Função para carregar chaves de gateway via API protegida
        async function loadGatewayKeys() {
            try {
                const response = await fetch('/api/get_gateway_keys.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        csrf_token: window.csrfToken,
                        checkout_hash: '<?php echo htmlspecialchars($checkout_hash); ?>'
                    })
                });
                
                if (!response.ok) {
                    console.error('Erro ao carregar chaves de gateway:', response.status);
                    return;
                }
                
                const data = await response.json();
                if (data.success && data.keys) {
                    window.gatewayKeys = data.keys;
                }
            } catch (error) {
                console.error('Erro ao carregar chaves de gateway:', error);
            }
        }
        
        // Carregar chaves quando a página estiver pronta
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', loadGatewayKeys);
        } else {
            loadGatewayKeys();
        }
    </script>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: <?php echo htmlspecialchars($backgroundColor); ?>; }
        .custom-alert { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background-color: #ef4444; color: white; padding: 12px 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; }
        .custom-alert.show { opacity: 1; visibility: visible; }
        #pix-modal-overlay { transition: opacity 0.3s ease-in-out; }
        #pix-modal-content { transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; }
        #rejected-modal-overlay { transition: opacity 0.3s ease-in-out; }
        #rejected-modal-content { transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; }
        #cpf-info-modal-overlay { transition: opacity 0.3s ease-in-out; }
        #cpf-info-modal-content { transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; }
        .order-bump-block { display: block; cursor: pointer; background-color: #f3f4f6; border: 2px dashed #d1d5db; border-radius: 12px; padding: 1rem; position: relative; transition: all 0.3s ease-in-out; }
        .custom-checkbox { width: 24px; height: 24px; border: 2px solid #9ca3af; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease-in-out; }
        .custom-checkbox .checkmark { opacity: 0; transform: scale(0.5); color: white; transition: all 0.2s ease-in-out; width: 16px; height: 16px; }
        .offer-badge { position: absolute; top: -12px; right: 16px; background-color: #ef4444; color: white; font-size: 0.75rem; font-weight: 700; padding: 4px 10px; border-radius: 9999px; text-transform: uppercase; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 2px solid white; }
        .order-bump-wrapper input:checked + .order-bump-block { background-color: #f0fdf4; border-color: #22c55e; border-style: dashed; }
        .order-bump-wrapper input:checked + .order-bump-block .custom-checkbox { background-color: #22c55e; border-color: #22c55e; }
        .order-bump-wrapper input:checked + .order-bump-block .custom-checkbox .checkmark { opacity: 1; transform: scale(1); }
        #sales-notification { visibility: hidden; }
        #sales-notification.show { visibility: visible; transform: translateY(0); opacity: 1; }
        #sales-notification.hide { visibility: hidden; transform: translateY(100%); opacity: 0; }
        .checkout-input { transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }
        .checkout-input:focus { border-color: <?php echo htmlspecialchars($accentColor); ?>; box-shadow: 0 0 0 2px <?php echo htmlspecialchars($accentColor); ?>40; outline: none; }
        .payment-method-card { transition: all 0.3s ease-in-out; }
        .payment-method-card:hover { transform: translateY(-2px); }
        
        /* Ajustes para Payment Brick no mobile */
        @media (max-width: 1023px) {
            #payment_container_wrapper_credit,
            #payment_container_wrapper_ticket,
            #payment_container_wrapper_pix_mp {
                padding: 0.75rem !important;
                margin-left: -1.5rem;
                margin-right: -1.5rem;
                width: calc(100% + 3rem);
                max-width: calc(100% + 3rem);
                box-sizing: border-box;
            }
            
            #paymentBrick_container_credit,
            #paymentBrick_container_ticket,
            #paymentBrick_container_pix_mp {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            #paymentBrick_container_credit iframe,
            #paymentBrick_container_ticket iframe,
            #paymentBrick_container_pix_mp iframe {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 100% !important;
            }
            
            /* Garantir que inputs dentro do Payment Brick tenham largura completa */
            #payment_container_wrapper_credit *,
            #payment_container_wrapper_ticket *,
            #payment_container_wrapper_pix_mp * {
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            
            /* Ajustar o container pai para dar mais espaço */
            .payment-method-container {
                margin-left: -1rem;
                margin-right: -1rem;
                width: calc(100% + 2rem);
                max-width: calc(100% + 2rem);
            }
        }
    </style>
</head>
<body>
    
    <?php echo render_timer($timerConfig); ?>
    <div id="custom-alert-box" class="custom-alert"></div>
    
    <div class="mx-auto max-w-6xl p-4">
        <?php 
        require_once __DIR__ . '/helpers/security_helper.php';
        if (!empty($banners)): ?>
        <div data-id="banner" class="mb-4 space-y-4">
            <?php foreach ($banners as $banner_url): 
                $sanitized_banner_url = sanitize_url($banner_url, true);
                // Debug: log para verificar banners
                if (empty($sanitized_banner_url)) {
                    error_log("Checkout: Banner rejeitado pela sanitize_url. Original: " . $banner_url);
                }
                if (!empty($sanitized_banner_url)): ?>
            <img src="<?php echo $sanitized_banner_url; ?>" alt="Banner do Produto" class="w-full h-auto md:h-[300px] object-cover rounded-lg shadow-md" onerror="console.error('Erro ao carregar banner: <?php echo htmlspecialchars($sanitized_banner_url, ENT_QUOTES); ?>'); this.style.display='none';">
            <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>
        <?php echo render_youtube_video($youtubeUrl); ?>

        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Coluna Principal -->
            <div class="w-full lg:w-2/3">
                <div class="bg-white rounded-lg shadow-lg p-6 md:p-8 space-y-6">
                    <section data-id="summary" class="flex flex-row items-start gap-4">
                        <img src="<?php echo htmlspecialchars($main_image); ?>" alt="Imagem de <?php echo htmlspecialchars($main_name); ?>" class="w-24 h-24 object-cover rounded-lg shadow-md border border-gray-200 flex-shrink-0" onerror="this.src='https://placehold.co/96x96/e2e8f0/334155?text=Produto'">
                        <div class="flex-1">
                            <h1 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($main_name); ?></h1>
                            <div class="flex items-baseline flex-wrap gap-x-3 gap-y-1 mt-2">
                                <span class="text-2xl font-bold" style="color: <?php echo htmlspecialchars($accentColor); ?>;"><?php echo $formattedMainPrice; ?></span>
                                <?php if ($formattedPrecoAnterior): ?><span class="text-lg text-gray-400 line-through"><?php echo $formattedPrecoAnterior; ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($discount_text)): ?><span class="bg-red-100 text-red-700 text-xs font-bold uppercase px-3 py-1 rounded-full mt-2 inline-block"><?php echo htmlspecialchars($discount_text); ?></span><?php endif; ?>
                        </div>
                    </section>
                    <hr class="border-gray-200">
                    <section data-id="customer_info">
                        <div class="flex items-center gap-2.5 mb-4"><i data-lucide="clipboard-list" class="w-6 h-6 text-gray-700"></i><h2 class="text-xl font-semibold text-gray-800">Seus dados</h2></div>
                        <div class="space-y-4">
                            <div><label for="name" class="block text-sm font-medium text-gray-700">Qual é o seu nome completo?</label><div class="relative mt-1 rounded-lg shadow-sm"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="user" class="w-5 h-5 text-gray-400"></i></div><input type="text" id="name" name="name" required class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="Nome da Silva"></div></div>
                            <div><label for="email" class="block text-sm font-medium text-gray-700">Qual é o seu e-mail?</label><div class="relative mt-1 rounded-lg shadow-sm"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="mail" class="w-5 h-5 text-gray-400"></i></div><input type="email" id="email" name="email" required class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="Digite o e-mail que receberá o produto"></div></div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label for="phone" class="block text-sm font-medium text-gray-700">Qual é o número do seu celular?</label><div class="relative mt-1 rounded-lg shadow-sm"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="smartphone" class="w-5 h-5 text-gray-400"></i></div><input type="tel" id="phone" name="phone" required class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="(11) 99999-9999"></div></div>
                                <div><label for="cpf" class="block text-sm font-medium text-gray-700">Qual é o seu CPF?</label><div class="relative mt-1 rounded-lg shadow-sm"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="file-text" class="w-5 h-5 text-gray-400"></i></div><input type="text" id="cpf" name="cpf" required class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="000.000.000-00"></div><button type="button" id="btn-why-cpf" class="mt-2 text-xs text-gray-500 hover:text-gray-700 transition-colors flex items-center gap-1 cursor-pointer" style="background: none; border: none; padding: 0;"><i data-lucide="help-circle" class="w-3 h-3"></i> Porque pedimos esse dado?</button></div>
                            </div>
                        </div>
                    </section>
                    <?php if (($produto['tipo_entrega'] ?? '') === 'produto_fisico'): ?>
                        <hr class="border-gray-200">
                        <section data-id="address_info">
                            <div class="flex items-center gap-2.5 mb-4"><i data-lucide="map-pin" class="w-6 h-6 text-gray-700"></i><h2 class="text-xl font-semibold text-gray-800">Endereço de Entrega</h2></div>
                            <div class="space-y-4">
                                <!-- Campo CEP (sempre visível) -->
                                <div>
                                    <label for="cep" class="block text-sm font-medium text-gray-700">CEP</label>
                                    <div class="relative mt-1 rounded-lg shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="map-pin" class="w-5 h-5 text-gray-400"></i></div>
                                        <input type="text" id="cep" name="cep" required maxlength="9" class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="00000-000">
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">Digite o CEP para preencher automaticamente os demais campos</p>
                                </div>
                                
                                <!-- Demais campos (inicialmente ocultos) -->
                                <div id="address-other-fields" class="space-y-4 hidden">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div class="md:col-span-2">
                                            <label for="logradouro" class="block text-sm font-medium text-gray-700">Logradouro</label>
                                            <div class="relative mt-1 rounded-lg shadow-sm">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="navigation" class="w-5 h-5 text-gray-400"></i></div>
                                                <input type="text" id="logradouro" name="logradouro" required class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="Rua, Avenida, etc">
                                            </div>
                                        </div>
                                        <div>
                                            <label for="numero" class="block text-sm font-medium text-gray-700">Número</label>
                                            <div class="relative mt-1 rounded-lg shadow-sm">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="hash" class="w-5 h-5 text-gray-400"></i></div>
                                                <input type="text" id="numero" name="numero" required class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="123">
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label for="complemento" class="block text-sm font-medium text-gray-700">Complemento <span class="text-gray-400 text-xs font-normal">(opcional)</span></label>
                                        <div class="relative mt-1 rounded-lg shadow-sm">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="home" class="w-5 h-5 text-gray-400"></i></div>
                                            <input type="text" id="complemento" name="complemento" class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="Apto, Bloco, etc">
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label for="bairro" class="block text-sm font-medium text-gray-700">Bairro</label>
                                            <div class="relative mt-1 rounded-lg shadow-sm">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="map" class="w-5 h-5 text-gray-400"></i></div>
                                                <input type="text" id="bairro" name="bairro" required class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="Bairro">
                                            </div>
                                        </div>
                                        <div>
                                            <label for="cidade" class="block text-sm font-medium text-gray-700">Cidade</label>
                                            <div class="relative mt-1 rounded-lg shadow-sm">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="building" class="w-5 h-5 text-gray-400"></i></div>
                                                <input type="text" id="cidade" name="cidade" required class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base" placeholder="Cidade">
                                            </div>
                                        </div>
                                        <div>
                                            <label for="estado" class="block text-sm font-medium text-gray-700">Estado (UF)</label>
                                            <div class="relative mt-1 rounded-lg shadow-sm">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i data-lucide="map-pin" class="w-5 h-5 text-gray-400"></i></div>
                                                <input type="text" id="estado" name="estado" required maxlength="2" class="checkout-input mt-1 block w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 text-base uppercase" placeholder="SP">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
                    <?php if ($orderbump_active): ?>
                        <hr class="border-gray-200">
                        <section data-id="order_bump"><?php echo render_order_bumps_section($order_bumps); ?></section>
                    <?php endif; ?>
                    <hr class="border-gray-200">
                    <!-- Renderiza Pagamento (Mercado Pago, PushinPay ou Efí) -->
                    <section data-id="payment"><?php echo render_payment_section($gateway, $accentColor, $payment_methods_config, $pix_pushinpay_enabled, $pix_mercadopago_enabled, $pix_efi_enabled, $credit_card_enabled, $ticket_enabled, $credit_card_beehive_enabled, $credit_card_mercadopago_enabled, $credit_card_hypercash_enabled, $credit_card_efi_enabled); ?></section>
                    <hr class="border-gray-200">
                    <section data-id="security_info"><?php echo render_security_info($vendedor_nome); ?></section>
                </div>
            </div>

            <!-- Coluna Lateral: Resumo -->
            <aside class="w-full lg:w-1/3 hidden lg:block">
                <div class="sticky top-6 space-y-6">
                    <div class="bg-white rounded-lg shadow-lg p-6 space-y-4" data-id="final_summary">
                        <h2 class="text-xl font-semibold text-gray-800">Resumo da compra</h2>
                        <div class="space-y-2">
                            <div class="flex justify-between text-gray-700">
                                <span><?php echo htmlspecialchars($main_name); ?></span>
                                <div class="flex items-baseline gap-2">
                                    <?php if ($formattedPrecoAnterior): ?><span class="text-sm text-gray-400 line-through"><?php echo $formattedPrecoAnterior; ?></span><?php endif; ?>
                                    <span class="font-medium"><?php echo $formattedMainPrice; ?></span>
                                </div>
                            </div>
                            <?php foreach ($order_bumps as $bump) {
                                $ob_id = intval($bump['ob_id']); $ob_name = htmlspecialchars($bump['ob_nome']); $ob_price = floatval($bump['ob_preco']);
                                echo "<div id='orderbump-summary-{$ob_id}' class='orderbump-summary-item flex justify-between text-gray-700' style='display: none;'><span>".htmlspecialchars($ob_name)."</span><span>R$ ".number_format($ob_price, 2, ',', '.')."</span></div>";
                            } ?>
                        </div>
                        <hr class="border-gray-200">
                        <div class="flex justify-between items-center"><span class="text-lg font-bold text-gray-800">Total a pagar</span><span id="final-total-price" class="text-2xl font-bold text-[#348535]"><?php echo $formattedMainPrice; ?></span></div>
                        <div class="text-center text-gray-500 text-sm mt-4"><i data-lucide="lock" class="w-4 h-4 inline-block -mt-1"></i> Compra segura</div>
                    </div>
                    <?php 
                    if (!empty($sideBanners)): ?>
                    <div class="space-y-4">
                        <?php foreach ($sideBanners as $side_banner_url): 
                            $sanitized_side_banner = sanitize_url($side_banner_url, true);
                            // Debug: log para verificar banners laterais
                            if (empty($sanitized_side_banner)) {
                                error_log("Checkout: Banner lateral rejeitado pela sanitize_url. Original: " . $side_banner_url);
                            }
                            if (!empty($sanitized_side_banner)): ?>
                            <img src="<?php echo $sanitized_side_banner; ?>" alt="Banner Lateral" class="w-full h-auto object-cover rounded-lg shadow-md" onerror="console.error('Erro ao carregar banner lateral: <?php echo htmlspecialchars($sanitized_side_banner, ENT_QUOTES); ?>'); this.style.display='none';">
                            <?php endif; 
                        endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
        <?php 
        if (!empty($sideBanners)): ?><div class="mt-6 lg:hidden space-y-4"><?php foreach ($sideBanners as $side_banner_url): 
            $sanitized_side_banner = sanitize_url($side_banner_url, true);
            // Debug: log para verificar banners laterais mobile
            if (empty($sanitized_side_banner)) {
                error_log("Checkout: Banner lateral mobile rejeitado pela sanitize_url. Original: " . $side_banner_url);
            }
            if (!empty($sanitized_side_banner)): ?><img src="<?php echo $sanitized_side_banner; ?>" alt="Banner Lateral" class="w-full h-auto object-cover rounded-lg shadow-md" onerror="console.error('Erro ao carregar banner lateral: <?php echo htmlspecialchars($sanitized_side_banner, ENT_QUOTES); ?>'); this.style.display='none';"><?php endif; endforeach; ?></div><?php endif; ?>
    </div>

    <!-- Footer Mobile -->
    <footer id="mobile-footer" class="lg:hidden fixed bottom-0 left-0 right-0 bg-white shadow-lg p-4 border-t border-gray-200">
        <div id="mobile-summary-items" class="mb-2 text-sm text-gray-700 space-y-1 max-h-20 overflow-y-auto pr-2"></div>
        <div class="flex justify-between items-center mb-3 pt-2 border-t"><span class="text-lg font-bold text-gray-800">Total a pagar</span><span id="final-total-price-mobile" class="text-2xl font-bold text-[#348535]"><?php echo $formattedMainPrice; ?></span></div>
        <p class="text-center text-xs text-gray-500 flex items-center justify-center space-x-1"><i data-lucide="lock" class="w-3 h-3"></i><span>Compra segura processada pela <?php echo strtoupper(htmlspecialchars($nome_plataforma)); ?></span></p>
    </footer>
    <div id="mobile-footer-spacer" class="lg:hidden" style="height: 128px;"></div>

    <?php echo render_sales_notification($salesNotificationConfig, $produto['nome']); ?>

    <!-- Modal do PIX -->
    <div id="pix-modal-overlay" class="fixed inset-0 bg-black bg-opacity-70 z-[10000] flex items-center justify-center p-4 hidden opacity-0 overflow-y-auto">
        <div id="pix-modal-content" class="bg-white rounded-xl shadow-2xl w-full max-w-md transform scale-95 opacity-0 my-4 max-h-[90vh] overflow-y-auto">
            <div id="pix-waiting-state" class="p-4 sm:p-6 text-center">
                <img src="<?php echo htmlspecialchars($logo_checkout_url); ?>" alt="Logo" class="h-8 sm:h-10 mx-auto mb-4">
                <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-2">Escaneie para pagar com PIX</h2>
                <p class="text-xs sm:text-sm text-gray-600 mb-4">Abra o app do seu banco e aponte a câmera para o QR Code.</p>
                <div class="w-full max-w-[220px] sm:max-w-[260px] mx-auto mb-4">
                    <div class="aspect-square p-1.5 sm:p-2 bg-white border-4 rounded-lg shadow-lg" style="border-color: <?php echo htmlspecialchars($accentColor); ?>;">
                        <img id="pix-qr-code-img" src="" alt="PIX QR Code" class="w-full h-full object-contain rounded-sm" style="image-rendering: -webkit-optimize-contrast; image-rendering: crisp-edges; filter: none;">
                    </div>
                </div>
                <p class="text-center text-xs sm:text-sm text-gray-600 mb-2">Ou use o PIX Copia e Cola:</p>
                <div class="relative max-w-sm mx-auto mb-4">
                    <input type="text" id="pix-code-input" readonly class="w-full bg-gray-100 p-2.5 sm:p-3 rounded-lg text-xs sm:text-sm text-gray-800 pr-16 sm:pr-20 border border-gray-300">
                    <button id="copy-pix-code-btn" class="absolute right-1 top-1/2 -translate-y-1/2 text-white px-2 sm:px-2.5 py-1 sm:py-1.5 rounded-md text-xs sm:text-sm font-semibold transition-colors" style="background-color: <?php echo htmlspecialchars($accentColor); ?>;" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">Copiar</button>
                </div>
                <div class="mt-4 flex items-center justify-center gap-2 sm:gap-3 text-gray-500">
                    <svg class="animate-spin h-5 w-5 sm:h-6 sm:w-6" style="color: <?php echo htmlspecialchars($accentColor); ?>;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path></svg>
                    <span class="font-semibold text-sm sm:text-base">Aguardando pagamento...</span>
                </div>
            </div>
            <div id="pix-approved-state" class="hidden p-4 sm:p-6 text-center">
                 <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-full flex items-center justify-center mx-auto mb-4" style="background-color: <?php echo htmlspecialchars($accentColor); ?>20;"><i data-lucide="check" class="w-10 h-10 sm:w-12 sm:h-12" style="color: <?php echo htmlspecialchars($accentColor); ?>;"></i></div>
                 <h2 class="text-xl sm:text-2xl font-bold text-gray-800 mb-2">Pagamento Aprovado!</h2>
                 <p class="text-sm sm:text-base text-gray-600">Tudo certo! Você será redirecionado em instantes.</p>
            </div>
            <div class="bg-gray-50 p-3 sm:p-4 border-t border-gray-200 rounded-b-xl text-center">
                <p class="text-xs text-gray-600">Este pagamento será processado para <strong class="font-semibold"><?php echo htmlspecialchars($vendedor_nome); ?></strong>.</p>
            </div>
        </div>
    </div>


    <!-- Modal de Pagamento Rejeitado -->
    <div id="rejected-modal-overlay" class="fixed inset-0 bg-black bg-opacity-70 z-[10001] flex items-center justify-center p-4 hidden opacity-0 overflow-y-auto">
        <div id="rejected-modal-content" class="bg-white rounded-xl shadow-2xl w-full max-w-md transform scale-95 opacity-0 my-4 max-h-[90vh] overflow-y-auto">
            <div class="p-6 sm:p-8 text-center">
                <!-- Ícone de Erro -->
                <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-6">
                    <i data-lucide="x-circle" class="w-12 h-12 sm:w-16 sm:h-16 text-red-600"></i>
                </div>
                
                <!-- Título -->
                <h2 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-3">Pagamento Recusado</h2>
                
                <!-- Mensagem -->
                <p class="text-gray-600 mb-2 text-base sm:text-lg">
                    Seu pagamento não foi autorizado
                </p>
                <p class="text-sm text-gray-500 mb-6">
                    Isso pode acontecer por diversos motivos, como dados incorretos do cartão, limite insuficiente ou problemas com a operadora.
                </p>
                
                <!-- Informações Adicionais (se houver) -->
                <div id="rejected-reason" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg hidden">
                    <p class="text-sm text-red-700 font-medium" id="rejected-reason-text"></p>
                </div>
                
                <!-- Botões de Ação -->
                <div class="space-y-3">
                    <button id="btn-try-again-card" class="w-full px-6 py-3 rounded-lg font-semibold text-white text-center transition-all hover:opacity-90 shadow-lg hover:shadow-xl transform active:scale-95" style="background-color: <?php echo htmlspecialchars($accentColor); ?>;">
                        <i data-lucide="credit-card" class="w-5 h-5 inline-block mr-2"></i>
                        Tentar Novamente com Outro Cartão
                    </button>
                    
                    <button id="btn-choose-other-method" class="w-full px-6 py-3 rounded-lg font-semibold text-gray-700 text-center bg-gray-100 border border-gray-300 transition-all hover:bg-gray-200 shadow-md hover:shadow-lg transform active:scale-95">
                        <i data-lucide="refresh-ccw" class="w-5 h-5 inline-block mr-2"></i>
                        Escolher Outro Método de Pagamento
                    </button>
                </div>
                
                <!-- Aviso -->
                <div class="mt-6 p-3 bg-blue-50 border border-blue-100 rounded-lg">
                    <p class="text-xs text-blue-700 flex items-center justify-center gap-2">
                        <i data-lucide="info" class="w-4 h-4"></i>
                        Você pode tentar novamente ou escolher Pix ou Boleto
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Sobre CPF -->
    <div id="cpf-info-modal-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-[10002] flex items-center justify-center p-4 hidden opacity-0 overflow-y-auto">
        <div id="cpf-info-modal-content" class="bg-white rounded-xl shadow-2xl w-full max-w-md transform scale-95 opacity-0 my-4">
            <div class="p-6 sm:p-8">
                <!-- Cabeçalho -->
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl sm:text-2xl font-bold text-gray-800">Sobre CPF</h3>
                    <button type="button" id="btn-close-cpf-modal" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>
                
                <!-- Conteúdo -->
                <div class="text-gray-600 text-sm sm:text-base leading-relaxed">
                    <p>Esse dado é necessário para garantir a segurança da sua compra e cadastro em nossa plataforma, essa informação também pode ser usada para emissão de Nota Fiscal.</p>
                </div>
                
                <!-- Botão Fechar -->
                <div class="mt-6 flex justify-end">
                    <button type="button" id="btn-close-cpf-modal-footer" class="px-6 py-2 rounded-lg font-medium text-white transition-all hover:opacity-90 shadow-md hover:shadow-lg transform active:scale-95" style="background-color: <?php echo htmlspecialchars($accentColor); ?>;">
                        Entendi
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function generateUUID() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }
        let checkoutSessionUUID = generateUUID();
        localStorage.setItem('starfy_checkout_session_uuid', checkoutSessionUUID);

        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
            let paymentCheckInterval;
            let notificationTimer;
            
            const pixModalOverlay = document.getElementById('pix-modal-overlay');
            const pixModalContent = document.getElementById('pix-modal-content');
            const mainProductPrice = <?php echo (float)$produto['preco']; ?>;
            const infoprodutorId = <?php echo (int)$infoprodutor_id; ?>;
            const mainProductId = <?php echo (int)$produto['id']; ?>;
            const activeGateway = '<?php echo $gateway; ?>';
            let currentAmount = mainProductPrice;
            let acceptedOrderBumps = [];
            
            const finalTotalElement = document.getElementById('final-total-price');
            const finalTotalMobileElement = document.getElementById('final-total-price-mobile');
            const mobileSummaryItemsContainer = document.getElementById('mobile-summary-items');
            const orderbumpCheckboxes = document.querySelectorAll('.orderbump-checkbox');
            const nameInput = document.getElementById('name');
            const emailInput = document.getElementById('email');
            const phoneInput = document.getElementById('phone');
            const cpfInput = document.getElementById('cpf');
            const customerFieldsConfig = <?php echo json_encode($customer_fields_config); ?>;
            
            // Campos de endereço (se produto físico)
            const cepInput = document.getElementById('cep');
            const logradouroInput = document.getElementById('logradouro');
            const numeroInput = document.getElementById('numero');
            const complementoInput = document.getElementById('complemento');
            const bairroInput = document.getElementById('bairro');
            const cidadeInput = document.getElementById('cidade');
            const estadoInput = document.getElementById('estado');
            
            // Máscara de CEP
            if (cepInput) {
                cepInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value.length > 5) {
                        value = value.substring(0, 5) + '-' + value.substring(5, 8);
                    }
                    e.target.value = value;
                });
                
                // Busca automática de CEP ao sair do campo
                cepInput.addEventListener('blur', function(e) {
                    const cep = e.target.value.replace(/\D/g, '');
                    if (cep.length === 8) {
                        buscarCEP(cep);
                    } else if (cep.length > 0) {
                        // Se o CEP foi digitado mas está incompleto, mostrar campos para preenchimento manual
                        const addressFieldsContainer = document.getElementById('address-other-fields');
                        if (addressFieldsContainer && addressFieldsContainer.classList.contains('hidden')) {
                            addressFieldsContainer.classList.remove('hidden');
                            addressFieldsContainer.style.opacity = '0';
                            setTimeout(() => {
                                addressFieldsContainer.style.transition = 'opacity 0.3s ease-in-out';
                                addressFieldsContainer.style.opacity = '1';
                            }, 10);
                        }
                    }
                });
            }
            
            // Função para buscar CEP via ViaCEP
            async function buscarCEP(cep) {
                if (!cep || cep.length !== 8) return;
                
                const addressFieldsContainer = document.getElementById('address-other-fields');
                
                try {
                    // Mostrar loading
                    if (logradouroInput) logradouroInput.disabled = true;
                    if (bairroInput) bairroInput.disabled = true;
                    if (cidadeInput) cidadeInput.disabled = true;
                    if (estadoInput) estadoInput.disabled = true;
                    
                    const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
                    const data = await response.json();
                    
                    if (data.erro) {
                        showAlert('CEP não encontrado. Por favor, verifique o CEP informado.');
                        // Mostrar campos mesmo com erro para preenchimento manual
                        if (addressFieldsContainer) {
                            addressFieldsContainer.classList.remove('hidden');
                            addressFieldsContainer.style.opacity = '0';
                            setTimeout(() => {
                                addressFieldsContainer.style.transition = 'opacity 0.3s ease-in-out';
                                addressFieldsContainer.style.opacity = '1';
                            }, 10);
                        }
                        return;
                    }
                    
                    // Preencher campos automaticamente
                    if (logradouroInput) {
                        logradouroInput.value = data.logradouro || '';
                        logradouroInput.disabled = false;
                    }
                    if (bairroInput) {
                        bairroInput.value = data.bairro || '';
                        bairroInput.disabled = false;
                    }
                    if (cidadeInput) {
                        cidadeInput.value = data.localidade || '';
                        cidadeInput.disabled = false;
                    }
                    if (estadoInput) {
                        estadoInput.value = (data.uf || '').toUpperCase();
                        estadoInput.disabled = false;
                    }
                    
                    // Mostrar os demais campos com animação
                    if (addressFieldsContainer) {
                        addressFieldsContainer.classList.remove('hidden');
                        addressFieldsContainer.style.opacity = '0';
                        setTimeout(() => {
                            addressFieldsContainer.style.transition = 'opacity 0.3s ease-in-out';
                            addressFieldsContainer.style.opacity = '1';
                        }, 10);
                    }
                    
                    // Focar no campo número após preencher
                    if (numeroInput) {
                        setTimeout(() => numeroInput.focus(), 300);
                    }
                } catch (error) {
                    console.error('Erro ao buscar CEP:', error);
                    showAlert('Erro ao buscar CEP. Por favor, preencha os dados manualmente.');
                    
                    // Mostrar campos mesmo com erro para preenchimento manual
                    if (addressFieldsContainer) {
                        addressFieldsContainer.classList.remove('hidden');
                        addressFieldsContainer.style.opacity = '0';
                        setTimeout(() => {
                            addressFieldsContainer.style.transition = 'opacity 0.3s ease-in-out';
                            addressFieldsContainer.style.opacity = '1';
                        }, 10);
                    }
                    
                    // Reabilitar campos
                    if (logradouroInput) logradouroInput.disabled = false;
                    if (bairroInput) bairroInput.disabled = false;
                    if (cidadeInput) cidadeInput.disabled = false;
                    if (estadoInput) estadoInput.disabled = false;
                }
            }
            
            // Máscara para estado (apenas 2 letras maiúsculas)
            if (estadoInput) {
                estadoInput.addEventListener('input', function(e) {
                    e.target.value = e.target.value.replace(/[^a-zA-Z]/g, '').toUpperCase().substring(0, 2);
                });
            }

            function updateMobileLayout() {
                const footer = document.getElementById('mobile-footer');
                const spacer = document.getElementById('mobile-footer-spacer');
                const notification = document.getElementById('sales-notification');
                if (footer && spacer && window.innerWidth < 1024) {
                    const footerHeight = footer.offsetHeight;
                    spacer.style.height = footerHeight + 'px';
                    if (notification) notification.style.bottom = (footerHeight + 16) + 'px';
                } else if (spacer) {
                    spacer.style.height = '0px';
                    if (notification) notification.style.bottom = '';
                }
            }
            window.addEventListener('resize', updateMobileLayout);
            
            function getUrlUtmParameters() {
                const urlParams = new URLSearchParams(window.location.search);
                const utmParams = {};
                ['utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term', 'src', 'sck'].forEach(key => { utmParams[key] = urlParams.get(key); });
                return utmParams;
            }
            const utmParameters = getUrlUtmParameters();

            const updateSummaryAndTotal = () => {
                currentAmount = mainProductPrice;
                acceptedOrderBumps = [];
                document.querySelectorAll('.orderbump-summary-item').forEach(item => item.style.display = 'none');
                if (mobileSummaryItemsContainer) {
                    mobileSummaryItemsContainer.innerHTML = '';
                    const mainItemEl = document.createElement('div');
                    mainItemEl.className = 'flex justify-between';
                    mainItemEl.innerHTML = `<span><?php echo htmlspecialchars(addslashes($main_name)); ?></span><div class="flex items-baseline gap-2"><?php if ($formattedPrecoAnterior): ?><span class="text-sm text-gray-400 line-through"><?php echo $formattedPrecoAnterior; ?></span><?php endif; ?><span class="font-medium"><?php echo $formattedMainPrice; ?></span></div>`;
                    mobileSummaryItemsContainer.appendChild(mainItemEl);
                }
                orderbumpCheckboxes.forEach(checkbox => {
                    const productId = parseInt(checkbox.dataset.productId);
                    const summaryItem = document.getElementById(`orderbump-summary-${productId}`);
                    if (checkbox.checked) {
                        const price = parseFloat(checkbox.dataset.price);
                        const name = checkbox.dataset.name;
                        currentAmount += price;
                        acceptedOrderBumps.push(productId);
                        if(summaryItem) summaryItem.style.display = 'flex';
                        if (mobileSummaryItemsContainer && name) {
                            const itemEl = document.createElement('div');
                            itemEl.className = 'flex justify-between';
                            itemEl.innerHTML = `<span>${name}</span><span class="font-medium">R$ ${price.toFixed(2).replace('.', ',')}</span>`;
                            mobileSummaryItemsContainer.appendChild(itemEl);
                        }
                    }
                });
                const totalText = `R$ ${currentAmount.toFixed(2).replace('.', ',')}`;
                if (finalTotalElement) finalTotalElement.textContent = totalText;
                if (finalTotalMobileElement) finalTotalMobileElement.textContent = totalText;
                updateMobileLayout();
            };
            
            orderbumpCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    updateSummaryAndTotal();
                    // Atualizar Payment Brick se método MP estiver selecionado (apenas se a função existir)
                    if ((selectedPaymentMethod === 'credit_card' || selectedPaymentMethod === 'ticket' || selectedPaymentMethod === 'pix_mercadopago') && typeof initializePaymentBrickForMethod === 'function') {
                        const currentEmail = emailInput.value;
                        if (currentEmail && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(currentEmail)) {
                            initializePaymentBrickForMethod(selectedPaymentMethod, currentEmail, currentAmount);
                        } else {
                            initializePaymentBrickForMethod(selectedPaymentMethod, null, currentAmount);
                        }
                    }
                });
            });
            updateSummaryAndTotal();
            updateMobileLayout();

            // Função auxiliar para mensagens de erro por status
            function getStatusErrorMessage(status) {
                const statusMsg = {
                    'pending': 'Pagamento pendente. Aguarde a confirmação.',
                    'in_process': 'Pagamento em processamento. Aguarde a confirmação.',
                    'rejected': 'Pagamento recusado. Verifique os dados do cartão ou tente outro método de pagamento.',
                    'cancelled': 'Pagamento cancelado. Tente novamente ou escolha outro método de pagamento.',
                    'refunded': 'Pagamento reembolsado.',
                    'charged_back': 'Pagamento contestado. Entre em contato com o suporte.'
                };
                return statusMsg[status] || null;
            }

            function showAlert(message) {
                const alertBox = document.getElementById('custom-alert-box');
                alertBox.textContent = message;
                alertBox.classList.add('show');
                setTimeout(() => { alertBox.classList.remove('show'); }, 3000);
            }


            // Função para mostrar modal de pagamento rejeitado
            function showRejectedModal(reason = null) {
                const rejectedModalOverlay = document.getElementById('rejected-modal-overlay');
                const rejectedModalContent = document.getElementById('rejected-modal-content');
                const rejectedReason = document.getElementById('rejected-reason');
                const rejectedReasonText = document.getElementById('rejected-reason-text');
                
                // Se houver motivo específico, mostrar
                if (reason) {
                    rejectedReason.classList.remove('hidden');
                    rejectedReasonText.textContent = reason;
                } else {
                    rejectedReason.classList.add('hidden');
                }
                
                // Mostrar modal com animação
                rejectedModalOverlay.classList.remove('hidden');
                setTimeout(() => { 
                    rejectedModalOverlay.classList.remove('opacity-0'); 
                    rejectedModalContent.classList.remove('opacity-0', 'scale-95'); 
                    lucide.createIcons(); 
                }, 10);
                
                // Botão: Tentar novamente com outro cartão
                const btnTryAgain = document.getElementById('btn-try-again-card');
                if (btnTryAgain) {
                    btnTryAgain.onclick = function() {
                        // Fechar modal
                        closeRejectedModal();
                        // Limpar campos do cartão
                        const cardNumberInput = document.getElementById('efi-card-number');
                        const cardHolderInput = document.getElementById('efi-card-holder');
                        const cardExpiryInput = document.getElementById('efi-card-expiry');
                        const cardCvvInput = document.getElementById('efi-card-cvv');
                        
                        if (cardNumberInput) cardNumberInput.value = '';
                        if (cardHolderInput) cardHolderInput.value = '';
                        if (cardExpiryInput) cardExpiryInput.value = '';
                        if (cardCvvInput) cardCvvInput.value = '';
                        
                        // Focar no campo do número do cartão
                        if (cardNumberInput) {
                            setTimeout(() => cardNumberInput.focus(), 300);
                        }
                    };
                }
                
                // Botão: Escolher outro método de pagamento
                const btnChooseOther = document.getElementById('btn-choose-other-method');
                if (btnChooseOther) {
                    btnChooseOther.onclick = function() {
                        // Fechar modal
                        closeRejectedModal();
                        // Mostrar seletor de métodos de pagamento
                        const paymentMethodsSelector = document.querySelector('.payment-methods-selector');
                        if (paymentMethodsSelector) {
                            paymentMethodsSelector.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            // Destacar o seletor
                            paymentMethodsSelector.style.transition = 'all 0.3s ease';
                            paymentMethodsSelector.style.boxShadow = '0 0 0 4px ' + '<?php echo htmlspecialchars($accentColor); ?>40';
                            setTimeout(() => {
                                paymentMethodsSelector.style.boxShadow = '';
                            }, 2000);
                        }
                    };
                }
            }
            
            // Função para fechar modal de pagamento rejeitado
            function closeRejectedModal() {
                const rejectedModalOverlay = document.getElementById('rejected-modal-overlay');
                const rejectedModalContent = document.getElementById('rejected-modal-content');
                
                rejectedModalOverlay.classList.add('opacity-0');
                rejectedModalContent.classList.add('opacity-0', 'scale-95');
                setTimeout(() => {
                    rejectedModalOverlay.classList.add('hidden');
                }, 300);
            }

            // --- Lógica de Validação Comum ---
            function validateForm() {
                const cpfEl = document.getElementById('cpf');
                const phoneEl = document.getElementById('phone');
                
                // A lógica abaixo confia no DOM: 
                // Se o PHP renderizou um campo 'hidden', é porque foi desabilitado no backend.
                // Logo, não validamos e pegamos o valor padrão do hidden (ex: "000.000.000-00").
                const isCpfActive = cpfEl && cpfEl.type !== 'hidden';
                const isPhoneActive = phoneEl && phoneEl.type !== 'hidden';

                const payerData = {
                    name: nameInput.value,
                    email: emailInput.value,
                    phone: phoneEl ? phoneEl.value : '',
                    cpf: cpfEl ? cpfEl.value : '',
                    product_id: mainProductId,
                    checkout_session_uuid: checkoutSessionUUID
                };

                if (!payerData.name || !payerData.email) { showAlert('Por favor, preencha o nome e o e-mail.'); return null; }
                
                // Validação condicional baseada no estado VISUAL do campo
                if (isPhoneActive && !payerData.phone) { showAlert('Por favor, preencha o telefone.'); return null; }
                if (isCpfActive && !payerData.cpf) { showAlert('Por favor, preencha o CPF.'); return null; }
                
                // Validação de endereço se produto for físico
                const isProdutoFisico = <?php echo (($produto['tipo_entrega'] ?? '') === 'produto_fisico') ? 'true' : 'false'; ?>;
                if (isProdutoFisico) {
                    if (!cepInput || !cepInput.value) {
                        showAlert('Por favor, preencha o CEP.');
                        return null;
                    }
                    if (!logradouroInput || !logradouroInput.value) {
                        showAlert('Por favor, preencha o logradouro.');
                        return null;
                    }
                    if (!numeroInput || !numeroInput.value) {
                        showAlert('Por favor, preencha o número.');
                        return null;
                    }
                    if (!bairroInput || !bairroInput.value) {
                        showAlert('Por favor, preencha o bairro.');
                        return null;
                    }
                    if (!cidadeInput || !cidadeInput.value) {
                        showAlert('Por favor, preencha a cidade.');
                        return null;
                    }
                    if (!estadoInput || !estadoInput.value || estadoInput.value.length !== 2) {
                        showAlert('Por favor, preencha o estado (UF) corretamente.');
                        return null;
                    }
                    
                    // Adicionar dados de endereço ao payload
                    payerData.address = {
                        cep: cepInput.value.replace(/\D/g, ''),
                        logradouro: logradouroInput.value,
                        numero: numeroInput.value,
                        complemento: complementoInput ? complementoInput.value : '',
                        bairro: bairroInput.value,
                        cidade: cidadeInput.value,
                        estado: estadoInput.value.toUpperCase()
                    };
                }
                
                return payerData;
            }

            // --- LÓGICA DE SELEÇÃO DE MÉTODOS DE PAGAMENTO ---
            let selectedPaymentMethod = null;
            let paymentBrickControllers = {};
            
            function selectPaymentMethod(methodType) {
                const accentColor = '<?php echo htmlspecialchars($accentColor); ?>';
                
                // Remover classe active de todos os cards
                document.querySelectorAll('.payment-method-card').forEach(card => {
                    card.style.borderColor = '#e5e7eb';
                    card.style.backgroundColor = '#ffffff';
                });
                
                // Adicionar classe active ao card selecionado
                const selectedCard = document.querySelector(`[data-payment-method="${methodType}"]`);
                if (selectedCard) {
                    selectedCard.style.borderColor = accentColor;
                    // Converter hex para rgba com 5% de opacidade
                    const hex = accentColor.replace('#', '');
                    const r = parseInt(hex.substr(0, 2), 16);
                    const g = parseInt(hex.substr(2, 2), 16);
                    const b = parseInt(hex.substr(4, 2), 16);
                    selectedCard.style.backgroundColor = `rgba(${r}, ${g}, ${b}, 0.05)`;
                }
                
                // Oculta todos os containers de métodos
                document.querySelectorAll('.payment-method-container').forEach(container => {
                    container.classList.add('hidden');
                });
                
                // Mostra apenas o container do método selecionado
                const selectedContainer = document.querySelector(`[data-method-type="${methodType}"]`);
                if (selectedContainer) {
                    selectedContainer.classList.remove('hidden');
                }
                
                selectedPaymentMethod = methodType;
                
                // Se for método do Mercado Pago, inicializar Payment Brick (apenas se a função existir)
                if ((methodType === 'credit_card' || methodType === 'ticket' || methodType === 'pix_mercadopago') && typeof initializePaymentBrickForMethod === 'function') {
                    const currentEmail = emailInput.value;
                    if (currentEmail && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(currentEmail)) {
                        initializePaymentBrickForMethod(methodType, currentEmail, currentAmount);
                    } else {
                        initializePaymentBrickForMethod(methodType, null, currentAmount);
                    }
                }
                
                // Se for Beehive, não precisa inicializar nada (formulário já está visível)
                
                // Recriar ícones Lucide
                lucide.createIcons();
            }
            
            // Event listeners nos cards do grid
            document.querySelectorAll('.payment-method-card').forEach(card => {
                card.addEventListener('click', () => {
                    const methodType = card.getAttribute('data-payment-method');
                    selectPaymentMethod(methodType);
                });
            });
            
            // Seleção padrão: Pix (prioridade PushinPay)
            // --- LÓGICA PUSHINPAY ---
            const btnPagarPushin = document.getElementById('btn-pagar-pushinpay');
            if (btnPagarPushin) {
                btnPagarPushin.addEventListener('click', async () => {
                    const payerData = validateForm();
                    if (!payerData) return;

                    btnPagarPushin.disabled = true;
                    btnPagarPushin.innerHTML = '<i class="animate-spin h-6 w-6 mr-2" data-lucide="loader-2"></i> Gerando Pix...';
                    lucide.createIcons();

                    try {
                        const response = await fetch('/process_payment', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': window.csrfToken || ''
                            },
                            body: JSON.stringify({ 
                                ...payerData,
                                payment_method_id: 'pix', // Força Pix para PushinPay
                                transaction_amount: parseFloat(currentAmount).toFixed(2),
                                order_bump_product_ids: acceptedOrderBumps,
                                utm_parameters: utmParameters,
                                gateway: 'pushinpay', // Flag para o backend
                                csrf_token: window.csrfToken || ''
                            })
                        });
                        
                        // Verifica se a resposta é JSON válido
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await response.text();
                            console.error('Resposta não é JSON:', text);
                            showAlert('Erro: Resposta inválida do servidor. Tente novamente.');
                            return;
                        }
                        
                        const result = await response.json();

                        if (response.ok && result.status === 'pix_created') {
                            showPixModal(result.pix_data.qr_code_base64, result.pix_data.qr_code, result.pix_data.payment_id, 'pushinpay');
                        } else {
                            // Erro de limite atingido não deve aparecer no checkout
                            // Apenas mostra erro genérico
                            showAlert(result.error || 'Erro ao gerar Pix. Tente novamente mais tarde.');
                        }
                    } catch (e) {
                        console.error('Erro ao processar pagamento:', e);
                        if (e instanceof SyntaxError) {
                            showAlert('Erro: Resposta inválida do servidor. Verifique o console para mais detalhes.');
                        } else {
                            showAlert('Erro de conexão. Verifique sua internet e tente novamente.');
                        }
                    } finally {
                        btnPagarPushin.disabled = false;
                        btnPagarPushin.innerHTML = '<i data-lucide="qr-code" class="w-6 h-6"></i> GERAR PIX AGORA';
                        lucide.createIcons();
                    }
                });
            }

            // --- LÓGICA EFÍ ---
            const btnPagarEfi = document.getElementById('btn-pagar-efi');
            if (btnPagarEfi) {
                btnPagarEfi.addEventListener('click', async () => {
                    const payerData = validateForm();
                    if (!payerData) return;

                    btnPagarEfi.disabled = true;
                    btnPagarEfi.innerHTML = '<i class="animate-spin h-6 w-6 mr-2" data-lucide="loader-2"></i> Gerando Pix...';
                    lucide.createIcons();

                    try {
                        const response = await fetch('/process_payment', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': window.csrfToken || ''
                            },
                            body: JSON.stringify({ 
                                ...payerData,
                                payment_method_id: 'pix', // Força Pix para Efí
                                transaction_amount: parseFloat(currentAmount).toFixed(2),
                                order_bump_product_ids: acceptedOrderBumps,
                                utm_parameters: utmParameters,
                                gateway: 'efi', // Flag para o backend
                                csrf_token: window.csrfToken || ''
                            })
                        });
                        
                        // Verifica se a resposta é JSON válido
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await response.text();
                            console.error('Resposta não é JSON:', text);
                            showAlert('Erro: Resposta inválida do servidor. Tente novamente.');
                            return;
                        }
                        
                        const result = await response.json();

                        if (response.ok && result.status === 'pix_created') {
                            // Buscar seller_id do produto
                            const sellerId = <?php echo isset($infoprodutor_id) ? $infoprodutor_id : 'null'; ?>;
                            showPixModal(result.pix_data.qr_code_base64, result.pix_data.qr_code, result.pix_data.payment_id, 'efi');
                            // Inicia polling para verificar status do pagamento Efí (igual PushinPay)
                            if (sellerId) {
                                startPaymentCheck(result.pix_data.payment_id, sellerId, 'efi');
                            } else {
                                console.warn('Efí: sellerId não encontrado, polling não será iniciado');
                            }
                        } else {
                            // Erro de limite atingido não deve aparecer no checkout
                            // Apenas mostra erro genérico
                            showAlert(result.error || 'Erro ao gerar Pix. Tente novamente mais tarde.');
                        }
                    } catch (e) {
                        console.error('Erro ao processar pagamento:', e);
                        if (e instanceof SyntaxError) {
                            showAlert('Erro: Resposta inválida do servidor. Verifique o console para mais detalhes.');
                        } else {
                            showAlert('Erro de conexão. Verifique sua internet e tente novamente.');
                        }
                    } finally {
                        btnPagarEfi.disabled = false;
                        btnPagarEfi.innerHTML = '<i data-lucide="qr-code" class="w-6 h-6"></i> GERAR PIX AGORA';
                        lucide.createIcons();
                    }
                });
            }

            // --- LÓGICA BEEHIVE ---
            <?php if ($should_load_beehive_script): ?>
            const btnPagarBeehive = document.getElementById('btn-pagar-beehive');
            
            // Função para inicializar Beehive quando a chave estiver disponível
            function initBeehive() {
                if (!window.gatewayKeys.beehive_public_key) {
                    // Aguardar um pouco e tentar novamente
                    setTimeout(initBeehive, 100);
                    return;
                }
                
                if (btnPagarBeehive && typeof BeehivePay !== 'undefined') {
                    // Configurar BeehivePay
                    BeehivePay.setPublicKey(window.gatewayKeys.beehive_public_key);
                    // Modo de produção (false) - usar credenciais de produção
                    BeehivePay.setTestMode(false);
                    
                    // Máscaras para os campos
                    const cardNumberInput = document.getElementById('beehive-card-number');
                    const cardExpiryInput = document.getElementById('beehive-card-expiry');
                    const cardCvvInput = document.getElementById('beehive-card-cvv');
                    
                    if (cardNumberInput) {
                    cardNumberInput.addEventListener('input', function(e) {
                        let value = e.target.value.replace(/\s/g, '');
                        value = value.replace(/(\d{4})/g, '$1 ').trim();
                        e.target.value = value;
                    });
                }
                
                if (cardExpiryInput) {
                    cardExpiryInput.addEventListener('input', function(e) {
                        let value = e.target.value.replace(/\D/g, '');
                        if (value.length >= 2) {
                            value = value.substring(0, 2) + '/' + value.substring(2, 4);
                        }
                        e.target.value = value;
                    });
                }
                
                if (cardCvvInput) {
                    cardCvvInput.addEventListener('input', function(e) {
                        e.target.value = e.target.value.replace(/\D/g, '');
                    });
                }
                
                btnPagarBeehive.addEventListener('click', async function() {
                    const payerData = validateForm();
                    if (!payerData) return;
                    
                    // Validar campos do cartão
                    const cardNumber = cardNumberInput.value.replace(/\s/g, '');
                    const cardHolder = document.getElementById('beehive-card-holder').value.trim();
                    const cardExpiry = cardExpiryInput.value;
                    const cardCvv = cardCvvInput.value;
                    
                    if (!cardNumber || cardNumber.length < 13) {
                        showAlert('Por favor, informe o número do cartão corretamente.');
                        return;
                    }
                    if (!cardHolder || cardHolder.length < 3) {
                        showAlert('Por favor, informe o nome no cartão.');
                        return;
                    }
                    if (!cardExpiry || cardExpiry.length !== 5) {
                        showAlert('Por favor, informe a validade do cartão (MM/AA).');
                        return;
                    }
                    if (!cardCvv || cardCvv.length < 3) {
                        showAlert('Por favor, informe o CVV do cartão.');
                        return;
                    }
                    
                    // Extrair mês e ano da validade
                    const [month, year] = cardExpiry.split('/');
                    if (!month || !year || month.length !== 2 || year.length !== 2) {
                        showAlert('Por favor, informe a validade no formato MM/AA.');
                        return;
                    }
                    
                    btnPagarBeehive.disabled = true;
                    btnPagarBeehive.innerHTML = '<i class="animate-spin h-6 w-6 mr-2" data-lucide="loader-2"></i> Processando...';
                    lucide.createIcons();
                    
                    try {
                        // Tokenizar cartão
                        const tokenResult = await BeehivePay.encrypt({
                            number: cardNumber,
                            holderName: cardHolder,
                            expMonth: parseInt(month),
                            expYear: 2000 + parseInt(year),
                            cvv: cardCvv
                        });
                        
                        // BeehivePay.encrypt pode retornar um objeto com 'token' ou 'error'
                        let cardToken;
                        if (typeof tokenResult === 'string') {
                            cardToken = tokenResult;
                        } else if (tokenResult && tokenResult.token) {
                            cardToken = tokenResult.token;
                        } else if (tokenResult && tokenResult.error) {
                            console.error('Beehive: Erro na tokenização:', tokenResult.error);
                            throw new Error(tokenResult.error.message || tokenResult.error || 'Erro ao tokenizar cartão.');
                        } else {
                            console.error('Beehive: Formato de resposta inesperado:', tokenResult);
                            throw new Error('Erro ao tokenizar cartão. Verifique os dados e tente novamente.');
                        }
                        
                        if (!cardToken) {
                            console.error('Beehive: Token vazio após processamento');
                            throw new Error('Erro ao tokenizar cartão. Verifique os dados e tente novamente.');
                        }
                        
                        // Preparar dados do cartão no formato correto para a API
                        // A API parece exigir os dados do cartão diretamente
                        const cardData = {
                            number: cardNumber.replace(/\s/g, ''), // Apenas números, sem espaços, max 20 chars
                            holderName: cardHolder.trim().substring(0, 100), // Max 100 caracteres
                            expirationMonth: parseInt(month), // Integer entre 1 e 12
                            expirationYear: 2000 + parseInt(year), // Integer >= 2025 e <= 2065
                            cvv: cardCvv.replace(/\D/g, '').substring(0, 4) // Max 4 caracteres, apenas números
                        };
                        
                        // Validar ano (deve ser >= 2025)
                        if (cardData.expirationYear < 2025) {
                            showAlert('O ano de validade do cartão deve ser 2025 ou posterior.');
                            return;
                        }
                        
                        // Validar ano (deve ser <= 2065)
                        if (cardData.expirationYear > 2065) {
                            showAlert('O ano de validade do cartão não pode ser maior que 2065.');
                            return;
                        }
                        
                        // Enviar para backend (tanto token quanto dados do cartão)
                        const response = await fetch('/process_payment', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': window.csrfToken || ''
                            },
                            body: JSON.stringify({
                                ...payerData,
                                card_token: cardToken, // Token para referência
                                card_data: cardData, // Dados do cartão no formato da API
                                transaction_amount: parseFloat(currentAmount).toFixed(2),
                                order_bump_product_ids: acceptedOrderBumps,
                                utm_parameters: utmParameters,
                                gateway: 'beehive',
                                csrf_token: window.csrfToken || ''
                            })
                        });
                        
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await response.text();
                            console.error('Resposta não é JSON:', text);
                            showAlert('Erro: Resposta inválida do servidor. Tente novamente.');
                            return;
                        }
                        
                        const result = await response.json();
                        
                        if (response.ok && result.status === 'approved') {
                            // Redirecionar para página de obrigado
                            if (result.redirect_url) {
                                window.location.href = result.redirect_url;
                            } else {
                                window.location.href = '/obrigado.php?payment_id=' + result.payment_id;
                            }
                        } else if (response.ok && result.status === 'pending') {
                            showAlert('Pagamento em processamento. Aguarde a confirmação.');
                        } else if (response.ok && result.status === 'rejected') {
                            showRejectedModal(result.reason || result.message || null);
                        } else {
                            // Erro de limite atingido não deve aparecer no checkout
                            // Apenas mostra erro genérico
                            showAlert(result.error || result.message || 'Erro ao processar pagamento. Tente novamente mais tarde.');
                        }
                    } catch (e) {
                        console.error('Erro ao processar pagamento Beehive:', e);
                        if (e.message && e.message.includes('tokenizar')) {
                            showAlert(e.message);
                        } else {
                            showAlert('Erro ao processar pagamento. Verifique os dados do cartão e tente novamente.');
                        }
                    } finally {
                        btnPagarBeehive.disabled = false;
                        btnPagarBeehive.innerHTML = '<i data-lucide="credit-card" class="w-6 h-6"></i> FINALIZAR PAGAMENTO';
                        lucide.createIcons();
                    }
                });
                }
            }
            
            // Inicializar quando a página estiver pronta
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initBeehive);
            } else {
                initBeehive();
            }
            <?php endif; ?>

            // --- LÓGICA HYPERCASH ---
            <?php 
            // Inicializar FastSoft (Hypercash) APENAS se houver método Hypercash habilitado E tiver public_key
            $should_init_hypercash = (isset($credit_card_hypercash_enabled) && $credit_card_hypercash_enabled) && !empty($hypercash_public_key) && !isset($_GET['preview']);
            if ($should_init_hypercash): ?>
            const btnPagarHypercash = document.getElementById('btn-pagar-hypercash');
            
            // Função para inicializar Hypercash quando a chave estiver disponível
            function initHypercash() {
                if (!window.gatewayKeys || !window.gatewayKeys.hypercash_public_key) {
                    // Aguardar um pouco e tentar novamente (máximo 5 segundos)
                    if (!window.hypercashInitAttempts) {
                        window.hypercashInitAttempts = 0;
                    }
                    window.hypercashInitAttempts++;
                    if (window.hypercashInitAttempts < 50) { // 50 tentativas * 100ms = 5 segundos
                        setTimeout(initHypercash, 100);
                    } else {
                        console.error('Hypercash: Chave pública não carregada após 5 segundos');
                    }
                    return;
                }
                
                // Validar se a chave não está vazia
                if (!window.gatewayKeys.hypercash_public_key || window.gatewayKeys.hypercash_public_key.trim() === '') {
                    console.error('Hypercash: Chave pública vazia ou inválida');
                    return;
                }
                
                if (btnPagarHypercash && typeof FastSoft !== 'undefined') {
                    try {
                        // Validar formato da chave pública (deve ter pelo menos alguns caracteres)
                        const publicKey = window.gatewayKeys.hypercash_public_key.trim();
                        if (publicKey.length < 10) {
                            console.error('Hypercash: Chave pública muito curta ou inválida');
                            return;
                        }
                        
                        // Configurar FastSoft
                        FastSoft.setPublicKey(publicKey).catch(error => {
                            console.error('Hypercash: Erro ao configurar chave pública:', error);
                            // O erro 403 pode indicar que a chave está inválida ou expirada
                            if (error.message && error.message.includes('Forbidden')) {
                                console.error('Hypercash: Chave pública rejeitada pela API. Verifique se a chave está correta e ativa na conta FastSoft.');
                            }
                        });
                    } catch (error) {
                        console.error('Hypercash: Erro ao inicializar:', error);
                    }
                    
                    // Máscaras para os campos
                    const hypercashCardNumberInput = document.getElementById('hypercash-card-number');
                    const hypercashCardExpiryInput = document.getElementById('hypercash-card-expiry');
                    const hypercashCardCvvInput = document.getElementById('hypercash-card-cvv');
                    
                    if (hypercashCardNumberInput) {
                        hypercashCardNumberInput.addEventListener('input', function(e) {
                            let value = e.target.value.replace(/\s/g, '');
                            value = value.replace(/(\d{4})/g, '$1 ').trim();
                            e.target.value = value;
                        });
                    }
                    
                    if (hypercashCardExpiryInput) {
                        hypercashCardExpiryInput.addEventListener('input', function(e) {
                            let value = e.target.value.replace(/\D/g, '');
                            if (value.length >= 2) {
                                value = value.substring(0, 2) + '/' + value.substring(2, 4);
                            }
                            e.target.value = value;
                        });
                    }
                    
                    if (hypercashCardCvvInput) {
                        hypercashCardCvvInput.addEventListener('input', function(e) {
                            e.target.value = e.target.value.replace(/\D/g, '');
                        });
                    }
                    
                    btnPagarHypercash.addEventListener('click', async function() {
                    const payerData = validateForm();
                    if (!payerData) return;
                    
                    const cardNumber = document.getElementById('hypercash-card-number')?.value.replace(/\s/g, '') || '';
                    const cardHolder = document.getElementById('hypercash-card-holder')?.value.trim() || '';
                    const cardExpiry = document.getElementById('hypercash-card-expiry')?.value || '';
                    const cardCvv = document.getElementById('hypercash-card-cvv')?.value || '';
                    
                    // Validações básicas
                    if (!cardNumber || cardNumber.length < 13) {
                        showAlert('Por favor, informe o número do cartão.');
                        return;
                    }
                    if (!cardHolder || cardHolder.length < 3) {
                        showAlert('Por favor, informe o nome no cartão.');
                        return;
                    }
                    if (!cardExpiry || cardExpiry.length !== 5) {
                        showAlert('Por favor, informe a validade do cartão (MM/AA).');
                        return;
                    }
                    if (!cardCvv || cardCvv.length < 3) {
                        showAlert('Por favor, informe o CVV do cartão.');
                        return;
                    }
                    
                    // Extrair mês e ano
                    const [month, year] = cardExpiry.split('/');
                    if (!month || !year || month.length !== 2 || year.length !== 2) {
                        showAlert('Formato de validade inválido. Use MM/AA.');
                        return;
                    }
                    
                    btnPagarHypercash.disabled = true;
                    btnPagarHypercash.innerHTML = '<i class="animate-spin h-6 w-6 mr-2" data-lucide="loader-2"></i> Processando...';
                    lucide.createIcons();
                    
                    try {
                        // Tokenizar cartão
                        const cardData = {
                            number: cardNumber,
                            holderName: cardHolder,
                            expMonth: parseInt(month),
                            expYear: 2000 + parseInt(year),
                            cvv: cardCvv
                        };
                        
                        const tokenResult = await FastSoft.encrypt(cardData);
                        
                        // FastSoft.encrypt pode retornar um objeto com 'token' ou 'error'
                        let cardToken;
                        if (typeof tokenResult === 'string') {
                            cardToken = tokenResult;
                        } else if (tokenResult && tokenResult.token) {
                            cardToken = tokenResult.token;
                        } else if (tokenResult && tokenResult.error) {
                            console.error('Hypercash: Erro na tokenização:', tokenResult.error);
                            throw new Error(tokenResult.error.message || tokenResult.error || 'Erro ao tokenizar cartão.');
                        } else {
                            console.error('Hypercash: Formato de resposta inesperado:', tokenResult);
                            throw new Error('Erro ao tokenizar cartão. Verifique os dados e tente novamente.');
                        }
                        
                        if (!cardToken) {
                            throw new Error('Erro ao tokenizar cartão. Verifique os dados e tente novamente.');
                        }
                        
                        // Preparar dados do cartão no formato correto para a API
                        const cardDataForApi = {
                            number: cardNumber,
                            holderName: cardHolder.trim().substring(0, 100),
                            expirationMonth: parseInt(month),
                            expirationYear: 2000 + parseInt(year),
                            cvv: cardCvv.replace(/\D/g, '').substring(0, 4)
                        };
                        
                        // Validar ano (deve ser >= ano atual)
                        const currentYear = new Date().getFullYear();
                        if (cardDataForApi.expirationYear < currentYear) {
                            showAlert('O ano de validade do cartão deve ser ' + currentYear + ' ou posterior.');
                            return;
                        }
                        
                        // Enviar para backend (tanto token quanto dados do cartão)
                        const response = await fetch('/process_payment', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': window.csrfToken || ''
                            },
                            body: JSON.stringify({
                                ...payerData,
                                card_token: cardToken,
                                card_data: cardDataForApi,
                                transaction_amount: parseFloat(currentAmount).toFixed(2),
                                order_bump_product_ids: acceptedOrderBumps,
                                utm_parameters: utmParameters,
                                gateway: 'hypercash',
                                csrf_token: window.csrfToken || ''
                            })
                        });
                        
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await response.text();
                            console.error('Resposta não é JSON:', text);
                            showAlert('Erro: Resposta inválida do servidor. Tente novamente.');
                            return;
                        }
                        
                        const result = await response.json();
                        
                        if (response.ok && result.status === 'approved') {
                            window.location.href = result.redirect_url || '/obrigado.php?payment_id=' + result.payment_id;
                        } else if (response.ok && result.status === 'pending') {
                            // Redirecionar para página de aguardando processamento
                            window.location.href = '/aguardando.php?payment_id=' + result.payment_id;
                        } else {
                            // Erro de limite atingido não deve aparecer no checkout
                            // Apenas mostra erro genérico
                            const errorMsg = result.error || result.message || 'Erro ao processar pagamento. Tente novamente mais tarde.';
                            showAlert(errorMsg);
                        }
                    } catch (e) {
                        console.error('Hypercash Error:', e);
                        if (e.message) {
                            showAlert(e.message);
                        } else {
                            showAlert('Erro ao processar pagamento. Verifique os dados do cartão e tente novamente.');
                        }
                    } finally {
                        btnPagarHypercash.disabled = false;
                        btnPagarHypercash.innerHTML = '<i data-lucide="credit-card" class="w-6 h-6"></i> FINALIZAR PAGAMENTO';
                        lucide.createIcons();
                    }
                });
                }
            }
            
            // Inicializar quando a página estiver pronta
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initHypercash);
            } else {
                initHypercash();
            }
            <?php endif; ?>
            
            <?php
            // Inicializar Efí Payment Token
            $should_init_efi = (isset($credit_card_efi_enabled) && $credit_card_efi_enabled) && !empty($efi_payee_code) && !isset($_GET['preview']);
            if ($should_init_efi): ?>
            const btnPagarEfiCard = document.getElementById('btn-pagar-efi-card');
            
            // Função para inicializar Efí quando a chave estiver disponível
            function initEfi() {
                if (!window.gatewayKeys.efi_payee_code) {
                    // Aguardar um pouco e tentar novamente
                    setTimeout(initEfi, 100);
                    return;
                }
                
                const efiPayeeCode = window.gatewayKeys.efi_payee_code;
                
                // Função para verificar se EfiPay está disponível
                function waitForEfiPay(callback, maxAttempts = 50) {
                    let attempts = 0;
                    const checkEfiPay = setInterval(() => {
                        attempts++;
                        // Verificar diferentes formas de acesso à biblioteca
                        const efiPay = window.EfiPay || window.efiPay || (typeof EfiPay !== 'undefined' ? EfiPay : null);
                        // A biblioteca Efí usa EfiPay.CreditCard, não EfiPay.getPaymentToken diretamente
                        if (efiPay && efiPay.CreditCard && typeof efiPay.CreditCard.setAccount === 'function') {
                            clearInterval(checkEfiPay);
                            callback(efiPay);
                        } else if (attempts >= maxAttempts) {
                            clearInterval(checkEfiPay);
                        }
                    }, 100);
                }
                
                if (btnPagarEfiCard) {
                    waitForEfiPay((EfiPayInstance) => {
                        if (!EfiPayInstance || !EfiPayInstance.CreditCard || typeof EfiPayInstance.CreditCard.setAccount !== 'function') {
                            console.error('Efí: EfiPay.CreditCard não está disponível');
                            return;
                        }
                        const EfiPay = EfiPayInstance; // Usar a instância passada pelo callback
                        // Máscaras para os campos
                        const efiCardNumberInput = document.getElementById('efi-card-number');
                        const efiCardExpiryInput = document.getElementById('efi-card-expiry');
                        const efiCardCvvInput = document.getElementById('efi-card-cvv');
                        
                        if (efiCardNumberInput) {
                            efiCardNumberInput.addEventListener('input', function(e) {
                                let value = e.target.value.replace(/\s/g, '');
                                value = value.replace(/(\d{4})/g, '$1 ').trim();
                                e.target.value = value;
                            });
                        }
                        
                        if (efiCardExpiryInput) {
                            efiCardExpiryInput.addEventListener('input', function(e) {
                                let value = e.target.value.replace(/\D/g, '');
                                if (value.length >= 2) {
                                    value = value.substring(0, 2) + '/' + value.substring(2, 4);
                                }
                                e.target.value = value;
                            });
                        }
                        
                        if (efiCardCvvInput) {
                            efiCardCvvInput.addEventListener('input', function(e) {
                                e.target.value = e.target.value.replace(/\D/g, '');
                            });
                        }
                        
                        btnPagarEfiCard.addEventListener('click', async function() {
                            // Validar formulário primeiro para obter CPF
                            const payerData = validateForm();
                            if (!payerData) {
                                return; // validateForm já mostra o alerta
                            }
                            
                            const cardNumber = document.getElementById('efi-card-number')?.value.replace(/\s/g, '') || '';
                            const cardHolder = document.getElementById('efi-card-holder')?.value.trim() || '';
                            const cardExpiry = document.getElementById('efi-card-expiry')?.value || '';
                            const cardCvv = document.getElementById('efi-card-cvv')?.value || '';
                            const installments = 1; // Sempre à vista (sem parcelas)
                            
                            // Validar campos do cartão
                            if (!cardNumber || cardNumber.length < 13) {
                                showAlert('Por favor, informe o número do cartão.');
                                return;
                            }
                            if (!cardHolder || cardHolder.length < 3) {
                                showAlert('Por favor, informe o nome no cartão.');
                                return;
                            }
                            if (!cardExpiry || cardExpiry.length < 5) {
                                showAlert('Por favor, informe a validade do cartão.');
                                return;
                            }
                            if (!cardCvv || cardCvv.length < 3) {
                                showAlert('Por favor, informe o CVV do cartão.');
                                return;
                            }
                            
                            // Extrair mês e ano da validade
                            const [month, year] = cardExpiry.split('/');
                            if (!month || !year || month.length !== 2 || year.length !== 2) {
                                showAlert('Por favor, informe a validade no formato MM/AA.');
                                return;
                            }
                            
                            // Limpar CPF (remover formatação)
                            const cpfClean = payerData.cpf ? payerData.cpf.replace(/\D/g, '') : '';
                            if (!cpfClean || cpfClean.length !== 11) {
                                showAlert('Por favor, informe um CPF válido.');
                                return;
                            }
                            
                            btnPagarEfiCard.disabled = true;
                            btnPagarEfiCard.innerHTML = '<i class="animate-spin h-6 w-6 mr-2" data-lucide="loader-2"></i> Processando...';
                            lucide.createIcons();
                            
                            try {
                                // Gerar payment_token usando EfiPay (API correta: EfiPay.CreditCard.setAccount()...getPaymentToken())
                                const paymentTokenResult = await EfiPay.CreditCard
                                    .setAccount(window.gatewayKeys.efi_payee_code)
                                    .setEnvironment('production') // ou 'sandbox' para testes
                                    .setCreditCardData({
                                        number: cardNumber.replace(/\s/g, ''),
                                        expirationMonth: parseInt(month),
                                        expirationYear: 2000 + parseInt(year),
                                        cvv: cardCvv,
                                        holderName: cardHolder
                                    })
                                    .getPaymentToken();
                                
                                if (!paymentTokenResult || !paymentTokenResult.payment_token) {
                                    throw new Error('Erro ao gerar token de pagamento. Verifique os dados do cartão.');
                                }
                                
                                const paymentToken = paymentTokenResult.payment_token;
                                
                                // Enviar para backend
                                const response = await fetch('/process_payment', {
                                    method: 'POST',
                                    headers: { 
                                        'Content-Type': 'application/json',
                                        'X-CSRF-Token': window.csrfToken || ''
                                    },
                                    body: JSON.stringify({
                                        ...payerData,
                                        payment_token: paymentToken,
                                        card_data: {
                                            number: cardNumber.replace(/\s/g, ''),
                                            holderName: cardHolder.trim().substring(0, 100),
                                            expirationMonth: parseInt(month),
                                            expirationYear: 2000 + parseInt(year),
                                            cvv: cardCvv.replace(/\D/g, '').substring(0, 4)
                                        },
                                        transaction_amount: parseFloat(currentAmount).toFixed(2),
                                        order_bump_product_ids: acceptedOrderBumps,
                                        utm_parameters: utmParameters,
                                        gateway: 'efi',
                                        csrf_token: window.csrfToken || ''
                                    })
                                });
                                
                                const contentType = response.headers.get('content-type');
                                if (!contentType || !contentType.includes('application/json')) {
                                    const text = await response.text();
                                    console.error('Resposta não é JSON:', text);
                                    showAlert('Erro: Resposta inválida do servidor. Tente novamente.');
                                    return;
                                }
                                
                                const result = await response.json();
                                
                                // Debug: log da resposta
                                console.log('Efí Cartão - Resposta do servidor:', result);
                                
                                if (result.error) {
                                    // Erro de limite atingido não deve aparecer no checkout
                                    // Apenas mostra erro genérico
                                    showAlert(result.error);
                                    return;
                                }
                                
                                // Tratar diferentes status de resposta
                                if (result.status === 'approved' && result.redirect_url) {
                                    window.location.href = result.redirect_url;
                                } else if (result.status === 'pending' || result.status === 'processing' || result.status === 'in_process') {
                                    // Sempre redirecionar para aguardando quando status é pending/processing
                                    if (result.redirect_url) {
                                        window.location.href = result.redirect_url;
                                    } else if (result.payment_id) {
                                        // Se não tiver redirect_url mas tiver payment_id, construir URL manualmente
                                        window.location.href = '/aguardando.php?payment_id=' + result.payment_id;
                                    } else {
                                        console.error('Efí Cartão: Status pending mas sem payment_id ou redirect_url');
                                        showAlert('Pagamento processado. Aguarde a confirmação.');
                                    }
                                } else if (result.status === 'rejected' || result.status === 'refused') {
                                    // Se foi rejeitado, mostrar modal bonito
                                    showRejectedModal(result.reason || result.message || null);
                                } else {
                                    // Fallback: se tiver payment_id, redirecionar para aguardando (assumir que está processando)
                                    if (result.payment_id) {
                                        console.log('Efí Cartão: Status desconhecido (' + result.status + '), redirecionando para aguardando');
                                        window.location.href = '/aguardando.php?payment_id=' + result.payment_id;
                                    } else {
                                        console.error('Efí Cartão: Resposta sem status claro e sem payment_id:', result);
                                        showAlert('Pagamento processado. Aguarde a confirmação.');
                                    }
                                }
                            } catch (e) {
                                console.error('Efí Cartão Error:', e);
                                if (e.message) {
                                    showAlert(e.message);
                                } else {
                                    showAlert('Erro ao processar pagamento. Verifique os dados do cartão e tente novamente.');
                                }
                            } finally {
                                btnPagarEfiCard.disabled = false;
                                btnPagarEfiCard.innerHTML = '<i data-lucide="credit-card" class="w-6 h-6"></i> FINALIZAR PAGAMENTO';
                                lucide.createIcons();
                            }
                        });
                    }); // Fechamento do callback waitForEfiPay
                }
            }
            
            // Inicializar quando a página estiver pronta
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initEfi);
            } else {
                initEfi();
            }
            <?php endif; ?>
            
            // --- LÓGICA MERCADO PAGO ---
            <?php 
            // Inicializar Mercado Pago APENAS se houver métodos do MP habilitados E tiver public_key
            $has_mp_methods = ($pix_mercadopago_enabled || $credit_card_mercadopago_enabled || $ticket_enabled);
            $should_init_mp = $has_mp_methods && !empty($public_key) && !isset($_GET['preview']);
            if ($should_init_mp): ?>
            let mp; // Declara mp antes de usar
            try {
                mp = new MercadoPago('<?php echo $public_key; ?>', { locale: 'pt-BR' });
            } catch (error) {
                console.error('Erro ao inicializar Mercado Pago:', error);
            }
            
            async function initializePaymentBrickForMethod(methodType, payerEmail = null, amount = mainProductPrice) {
                // Verifica se mp está inicializado
                if (typeof mp === 'undefined') {
                    console.error('Mercado Pago não foi inicializado ainda. Aguardando...');
                    return;
                }
                
                let containerId, loadingSpinnerId, configWrapperId;
                
                // Determinar IDs baseado no método
                if (methodType === 'credit_card') {
                    containerId = 'paymentBrick_container_credit';
                    loadingSpinnerId = 'loading_spinner_credit';
                    configWrapperId = 'payment_container_wrapper_credit';
                } else if (methodType === 'ticket') {
                    containerId = 'paymentBrick_container_ticket';
                    loadingSpinnerId = 'loading_spinner_ticket';
                    configWrapperId = 'payment_container_wrapper_ticket';
                } else if (methodType === 'pix_mercadopago') {
                    containerId = 'paymentBrick_container_pix_mp';
                    loadingSpinnerId = 'loading_spinner_pix_mp';
                    configWrapperId = 'payment_container_wrapper_pix_mp';
                } else {
                    return; // Método não suportado
                }
                
                // Verificar se o container existe
                let container = document.getElementById(containerId);
                if (!container) {
                    console.error(`Container ${containerId} não encontrado`);
                    return;
                }
                
                // Desmontar controller anterior se existir
                if (paymentBrickControllers[methodType]) {
                    try { 
                        await paymentBrickControllers[methodType].unmount(); 
                    } catch(e) {
                        console.error('Erro ao desmontar Payment Brick:', e);
                    }
                }
                
                // Garantir que o container está limpo
                const newContainer = document.createElement('div');
                newContainer.id = containerId;
                if (container.parentNode) {
                    container.parentNode.replaceChild(newContainer, container);
                }
                container = newContainer;
                
                const loadingSpinner = document.getElementById(loadingSpinnerId);
                if (loadingSpinner) {
                    loadingSpinner.classList.remove('hidden');
                }
                
                // Recupera config do HTML
                const configEl = document.getElementById(configWrapperId);
                const paymentMethods = configEl ? JSON.parse(configEl.dataset.mpConfig || '{}') : {};

                try {
                    paymentBrickControllers[methodType] = await mp.bricks().create("payment", containerId, {
                        initialization: { amount: parseFloat(amount), ...(payerEmail && { payer: { email: payerEmail } }) },
                        customization: {
                            paymentMethods: paymentMethods,
                            visual: { style: { theme: 'flat', borderRadius: '8px', verticalPadding: '26px', primaryColor: '<?php echo htmlspecialchars($accentColor); ?>' } },
                        },
                        callbacks: {
                            onReady: () => { 
                                if (loadingSpinner) {
                                    loadingSpinner.classList.add('hidden');
                                }
                                
                                // Quando apenas um método está configurado, o Payment Brick já mostra apenas esse método
                                // Tentamos expandir automaticamente o formulário após um pequeno delay
                                setTimeout(() => {
                                    const container = document.getElementById(containerId);
                                    if (container) {
                                        // Tentar encontrar e clicar no primeiro elemento clicável relacionado ao método
                                        // O Payment Brick pode ter elementos em shadow DOM ou iframe, então tentamos múltiplas abordagens
                                        
                                        // Abordagem 1: Tentar clicar em elementos com role="button" ou labels
                                        const clickableElements = container.querySelectorAll('[role="button"], label, button, .payment-option, [class*="payment"], [class*="method"]');
                                        
                                        if (clickableElements.length > 0) {
                                            // Se houver apenas um método configurado, tentar clicar no primeiro elemento
                                            // Isso deve expandir o formulário automaticamente
                                            const firstClickable = clickableElements[0];
                                            if (firstClickable) {
                                                // Usar um pequeno delay adicional para garantir que o DOM está totalmente renderizado
                                                setTimeout(() => {
                                                    try {
                                                        firstClickable.click();
                                                    } catch (e) {
                                                        // Se não conseguir clicar, não é crítico
                                                    }
                                                }, 200);
                                            }
                                        }
                                        
                                        // Abordagem 2: Tentar focar no primeiro input do formulário
                                        setTimeout(() => {
                                            const inputs = container.querySelectorAll('input, select, textarea');
                                            if (inputs.length > 0 && inputs[0]) {
                                                try {
                                                    inputs[0].focus();
                                                } catch (e) {
                                                    // Input pode estar em iframe/shadow DOM
                                                }
                                            }
                                        }, 300);
                                    }
                                }, 600);
                            },
                            onSubmit: async ({ formData }) => {
                                const payerData = validateForm();
                                if (!payerData) return; // Validação já feita na função comum

                                const response = await fetch('/process_payment', {
                                    method: 'POST', 
                                    headers: { 
                                        'Content-Type': 'application/json',
                                        'X-CSRF-Token': window.csrfToken || ''
                                    },
                                    body: JSON.stringify({ 
                                        ...formData, 
                                        ...payerData,
                                        transaction_amount: parseFloat(currentAmount).toFixed(2),
                                        order_bump_product_ids: acceptedOrderBumps,
                                        utm_parameters: utmParameters,
                                        gateway: 'mercadopago',
                                        csrf_token: window.csrfToken || ''
                                    })
                                });
                                const result = await response.json();

                                // Remove loading spinner em todos os casos
                                if (loadingSpinner) {
                                    loadingSpinner.classList.add('hidden');
                                }

                                // PRIORIDADE 1: Status de erro (rejected, cancelled, etc.) - verificar ANTES de pending
                                if (response.ok && (result.status === 'rejected' || result.status === 'refused')) {
                                    // Pagamento recusado/rejeitado - mostra modal bonito
                                    showRejectedModal(result.reason || result.message || result.error || null);
                                } else if (response.ok && (result.status === 'cancelled' || result.status === 'refunded' || result.status === 'charged_back')) {
                                    // Outros status de erro - mostra mensagem específica
                                    const errorMsg = result.error || result.message || getStatusErrorMessage(result.status) || 'Pagamento não aprovado. Tente outro método de pagamento.';
                                    showAlert(errorMsg);
                                    
                                    // Permite que o usuário tente novamente ou escolha outro método
                                    // O Payment Brick já deve estar disponível para nova tentativa
                                } 
                                // PRIORIDADE 2: PIX criado
                                else if (response.ok && result.status === 'pix_created') {
                                    showPixModal(result.pix_data.qr_code_base64, result.pix_data.qr_code, result.pix_data.payment_id, 'mercadopago');
                                } 
                                // PRIORIDADE 3: Pagamento aprovado
                                else if (response.ok && result.status === 'approved') {
                                    // Pagamento aprovado - redireciona para obrigado
                                    const paymentId = result.payment_id || result.id || '';
                                    const defaultRedirectUrl = '/obrigado.php?payment_id=' + paymentId;
                                    
                                    // SEMPRE usar caminho relativo como padrão seguro
                                    // Validar redirect_url - ignorar se contiver caminhos absolutos
                                    let finalRedirectUrl = defaultRedirectUrl;
                                    if (result.redirect_url) {
                                        const redirectUrl = result.redirect_url;
                                        // Verificar se contém caminho absoluto do sistema de arquivos (mais estrito)
                                        const hasAbsolutePath = /^[A-Z]:[\\\/]/i.test(redirectUrl) || 
                                                               redirectUrl.includes('C:/') || 
                                                               redirectUrl.includes('C:\\') || 
                                                               redirectUrl.includes('xampp') || 
                                                               redirectUrl.includes('htdocs') ||
                                                               redirectUrl.includes('localhost/C:') ||
                                                               redirectUrl.includes('localhost/C%3A');
                                        
                                        // Se NÃO contém caminho absoluto E é uma URL HTTP/HTTPS válida ou caminho relativo válido, usar
                                        // Caso contrário, sempre usar o padrão seguro (caminho relativo)
                                        if (!hasAbsolutePath && (redirectUrl.startsWith('/') || (redirectUrl.startsWith('http://') && !redirectUrl.includes('localhost/C:')) || (redirectUrl.startsWith('https://') && !redirectUrl.includes('localhost/C:')))) {
                                            finalRedirectUrl = redirectUrl;
                                        }
                                        // Se passou pela validação mas ainda contém localhost com caminho absoluto, usar padrão
                                        if (finalRedirectUrl.includes('localhost/C:') || finalRedirectUrl.includes('localhost/C%3A')) {
                                            finalRedirectUrl = defaultRedirectUrl;
                                        }
                                    }
                                    window.location.href = finalRedirectUrl;
                                } 
                                // PRIORIDADE 4: Redirect URL (para casos especiais)
                                else if (response.ok && result.redirect_url) {
                                    // SEMPRE usar caminho relativo como padrão seguro
                                    const redirectUrl = result.redirect_url;
                                    const paymentId = result.payment_id || result.id || '';
                                    const defaultRedirectUrl = '/obrigado.php?payment_id=' + paymentId;
                                    
                                    // Verificar se contém caminho absoluto do sistema de arquivos (mais estrito)
                                    const hasAbsolutePath = /^[A-Z]:[\\\/]/i.test(redirectUrl) || 
                                                           redirectUrl.includes('C:/') || 
                                                           redirectUrl.includes('C:\\') || 
                                                           redirectUrl.includes('xampp') || 
                                                           redirectUrl.includes('htdocs') ||
                                                           redirectUrl.includes('localhost/C:') ||
                                                           redirectUrl.includes('localhost/C%3A');
                                    
                                    // Se NÃO contém caminho absoluto E é uma URL HTTP/HTTPS válida ou caminho relativo válido, usar
                                    // Caso contrário, sempre usar o padrão seguro (caminho relativo)
                                    let finalRedirectUrl = defaultRedirectUrl;
                                    if (!hasAbsolutePath && (redirectUrl.startsWith('/') || (redirectUrl.startsWith('http://') && !redirectUrl.includes('localhost/C:')) || (redirectUrl.startsWith('https://') && !redirectUrl.includes('localhost/C:')))) {
                                        finalRedirectUrl = redirectUrl;
                                    }
                                    // Se passou pela validação mas ainda contém localhost com caminho absoluto, usar padrão
                                    if (finalRedirectUrl.includes('localhost/C:') || finalRedirectUrl.includes('localhost/C%3A')) {
                                        finalRedirectUrl = defaultRedirectUrl;
                                    }
                                    window.location.href = finalRedirectUrl;
                                } 
                                // PRIORIDADE 5: Status pendente/em processamento
                                else if (response.ok && (result.status === 'pending' || result.status === 'in_process')) {
                                    // Pagamento pendente/em processamento - inicia verificação de status
                                    if (result.payment_id) {
                                        // Inicia polling para verificar status
                                        startPaymentCheck(result.payment_id, infoprodutorId, 'mercadopago');
                                        
                                        // Mostra modal de aguardando pagamento (sem QR code para cartão)
                                        showPixModal(null, null, result.payment_id, 'mercadopago');
                                    } else {
                                        showAlert('Pagamento em processamento. Aguarde a confirmação.');
                                    }
                                } 
                                // PRIORIDADE 6: Outros status conhecidos
                                else if (response.ok && result.status) {
                                    const msg = getStatusErrorMessage(result.status) || result.message || result.error || 'Status do pagamento: ' + result.status + '. Aguarde ou tente novamente.';
                                    showAlert(msg);
                                    
                                    // Se tiver payment_id e status pendente, inicia verificação
                                    if (result.payment_id && (result.status === 'pending' || result.status === 'in_process')) {
                                        startPaymentCheck(result.payment_id, infoprodutorId, 'mercadopago');
                                        showPixModal(null, null, result.payment_id, 'mercadopago');
                                    }
                                } 
                                // PRIORIDADE 7: Erro genérico ou resposta inválida
                                else {
                                    console.error('Resposta inesperada do servidor:', result);
                                    // Erro de limite atingido não deve aparecer no checkout
                                    // Apenas mostra erro genérico
                                    const errorMsg = result.error || result.message || 'Ocorreu um erro ao processar o pagamento. Tente novamente ou escolha outro método de pagamento.';
                                    showAlert(errorMsg);
                                }
                            },
                            onError: (error) => { 
                                console.error('Erro no Payment Brick:', error);
                                if (loadingSpinner) {
                                    loadingSpinner.classList.add('hidden');
                                }
                                
                                // Mensagens de erro mais específicas
                                let errorMessage = 'Erro ao processar pagamento.';
                                if (error && error.message) {
                                    if (error.message.includes('rejected') || error.message.includes('recusado')) {
                                        errorMessage = 'Pagamento recusado. Verifique os dados do cartão ou tente outro método de pagamento.';
                                    } else if (error.message.includes('insufficient') || error.message.includes('insuficiente')) {
                                        errorMessage = 'Saldo insuficiente. Tente outro cartão ou método de pagamento.';
                                    } else if (error.message.includes('security') || error.message.includes('CVV') || error.message.includes('código')) {
                                        errorMessage = 'Código de segurança (CVV) incorreto. Verifique e tente novamente.';
                                    } else if (error.message.includes('expired') || error.message.includes('vencido')) {
                                        errorMessage = 'Cartão vencido. Verifique a data de validade e tente novamente.';
                                    } else {
                                        errorMessage = 'Erro no Mercado Pago: ' + error.message + '. Tente outro método de pagamento.';
                                    }
                                } else {
                                    errorMessage = 'Erro ao processar pagamento. Tente novamente ou escolha outro método de pagamento.';
                                }
                                
                                showAlert(errorMessage);
                            },
                        },
                    });
                } catch (error) {
                    console.error('Erro ao criar Payment Brick:', error);
                    if (loadingSpinner) {
                        loadingSpinner.classList.add('hidden');
                    }
                    showAlert("Erro ao inicializar pagamento: " + (error.message || 'Erro desconhecido'));
                }
            }
            
            // Listener para atualizar Payment Brick quando email mudar (apenas para métodos MP)
            emailInput.addEventListener('blur', () => {
                const currentEmail = emailInput.value;
                if (currentEmail && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(currentEmail)) {
                    if ((selectedPaymentMethod === 'credit_card' || selectedPaymentMethod === 'ticket' || selectedPaymentMethod === 'pix_mercadopago') && typeof initializePaymentBrickForMethod === 'function') {
                        initializePaymentBrickForMethod(selectedPaymentMethod, currentEmail, currentAmount);
                    }
                }
            }); 
            
            // Seleciona método padrão APÓS inicializar Mercado Pago
            const pixPushinpayCard = document.querySelector('[data-payment-method="pix_pushinpay"]');
            const pixEfiCard = document.querySelector('[data-payment-method="pix_efi"]');
            const pixMercadopagoCard = document.querySelector('[data-payment-method="pix_mercadopago"]');
            const creditCardCard = document.querySelector('[data-payment-method="credit_card"]');
            const creditCardBeehiveCard = document.querySelector('[data-payment-method="credit_card_beehive"]');
            const creditCardHypercashCard = document.querySelector('[data-payment-method="credit_card_hypercash"]');
            const creditCardEfiCard = document.querySelector('[data-payment-method="credit_card_efi"]');
            
            if (pixPushinpayCard) {
                selectPaymentMethod('pix_pushinpay');
            } else if (pixEfiCard) {
                selectPaymentMethod('pix_efi');
            } else if (pixMercadopagoCard) {
                selectPaymentMethod('pix_mercadopago');
            } else if (creditCardHypercashCard) {
                selectPaymentMethod('credit_card_hypercash');
            } else if (creditCardBeehiveCard) {
                selectPaymentMethod('credit_card_beehive');
            } else if (creditCardEfiCard) {
                selectPaymentMethod('credit_card_efi');
            } else if (creditCardCard) {
                selectPaymentMethod('credit_card');
            } else {
                // Se não houver Pix, selecionar o primeiro método disponível
                const firstCard = document.querySelector('.payment-method-card');
                if (firstCard) {
                    const methodType = firstCard.getAttribute('data-payment-method');
                    selectPaymentMethod(methodType);
                }
            }
            <?php endif; ?>

            // --- Funções Auxiliares de Pix e Status ---
            document.getElementById('copy-pix-code-btn')?.addEventListener('click', (e) => {
                const input = document.getElementById('pix-code-input');
                input.select();
                document.execCommand('copy');
                e.target.textContent = 'Copiado!';
                setTimeout(() => { e.target.textContent = 'Copiar'; }, 2000);
            });

            function showPixModal(qrCodeBase64, pixCode, paymentId, gatewayUsed) {
                if (notificationTimer) clearInterval(notificationTimer);
                document.getElementById('sales-notification')?.classList.remove('show');
                
                // Se tiver QR code, configura a imagem
                if (qrCodeBase64) {
                    // Detecta o formato da imagem ou usa PNG como padrão (formato correto para QR codes)
                    let imageSrc = qrCodeBase64;
                    if (!qrCodeBase64.startsWith('data:')) {
                        // Se não tem prefixo data:, adiciona como PNG (formato padrão para QR codes)
                        imageSrc = `data:image/png;base64,${qrCodeBase64}`;
                    } else if (qrCodeBase64.includes('data:image/jpeg')) {
                        // Se for JPEG, converte para PNG (QR codes devem ser PNG)
                        imageSrc = qrCodeBase64.replace('data:image/jpeg', 'data:image/png');
                    }
                    
                    const qrCodeImg = document.getElementById('pix-qr-code-img');
                    if (qrCodeImg) {
                        qrCodeImg.src = imageSrc;
                        // Garante que a imagem seja renderizada corretamente sem filtros
                        qrCodeImg.style.imageRendering = 'pixelated';
                        qrCodeImg.style.filter = 'none';
                    }
                }
                
                // Se tiver código PIX, configura o input
                if (pixCode) {
                    const pixCodeInput = document.getElementById('pix-code-input');
                    if (pixCodeInput) {
                        pixCodeInput.value = pixCode;
                    }
                }
                
                // Mostra estado de aguardando pagamento
                const waitingState = document.getElementById('pix-waiting-state');
                const approvedState = document.getElementById('pix-approved-state');
                if (waitingState) waitingState.classList.remove('hidden');
                if (approvedState) approvedState.classList.add('hidden');
                
                // Mostra o modal
                pixModalOverlay.classList.remove('hidden');
                setTimeout(() => { 
                    pixModalOverlay.classList.remove('opacity-0'); 
                    pixModalContent.classList.remove('opacity-0', 'scale-95'); 
                    lucide.createIcons(); 
                }, 10);
                
                // Inicia verificação de status se tiver payment_id
                if (paymentId) {
                    startPaymentCheck(paymentId, infoprodutorId, gatewayUsed);
                }
            }

            function startPaymentCheck(paymentId, sellerId, gatewayUsed) {
                if (paymentCheckInterval) clearInterval(paymentCheckInterval);
                let attempts = 0;
                paymentCheckInterval = setInterval(async () => {
                    attempts++;
                    if (attempts > 120) { 
                        clearInterval(paymentCheckInterval); 
                        showAlert("Tempo expirou. Verifique o status do pagamento manualmente."); 
                        return; 
                    }
                    try {
                        // Passa o gateway para o check_status.php
                        const response = await fetch(`/check_status?id=${paymentId}&seller_id=${sellerId}&gateway=${gatewayUsed}`);
                        
                        // Se a resposta não for OK, tenta ler o texto para debug
                        if (!response.ok) {
                            const text = await response.text();
                            if (text) {
                                try {
                                    const errorResult = JSON.parse(text);
                                    console.warn('Erro ao verificar status:', errorResult.message || 'Erro desconhecido');
                                } catch (e) {
                                    console.error('Resposta de erro não é JSON válido:', text.substring(0, 200));
                                }
                            } else {
                                console.error('Resposta vazia do servidor (HTTP ' + response.status + ')');
                            }
                            // Continua tentando
                            return;
                        }
                        
                        // Verifica se a resposta é JSON válido
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await response.text();
                            console.error('Resposta não é JSON:', text.substring(0, 200));
                            // Não para o intervalo, tenta novamente na próxima iteração
                            return;
                        }
                        
                        const text = await response.text();
                        if (!text || text.trim() === '') {
                            console.error('Resposta vazia do servidor');
                            return;
                        }
                        
                        let result;
                        try {
                            result = JSON.parse(text);
                        } catch (e) {
                            console.error('Erro ao fazer parse do JSON:', e, 'Resposta:', text.substring(0, 200));
                            return;
                        }
                        
                        if (result.status === 'approved' || result.status === 'paid') {
                            clearInterval(paymentCheckInterval);
                            document.getElementById('pix-waiting-state').classList.add('hidden');
                            document.getElementById('pix-approved-state').classList.remove('hidden');
                            lucide.createIcons();
                            // SEMPRE usar caminho relativo simples da raiz (ignorar redirectUrlConfig se tiver caminho absoluto)
                            const defaultRedirectUrl = '/obrigado.php?payment_id=' + paymentId;
                            setTimeout(() => { window.location.href = defaultRedirectUrl; }, 2000);
                        } else if (result.status === 'error') {
                            // Se houver erro, loga mas continua tentando
                            console.warn('Erro ao verificar status:', result.message || 'Erro desconhecido');
                        } else if (result.status === 'pending') {
                            // Status ainda pendente, continua verificando
                            // Não faz nada, apenas continua o loop
                        }
                    } catch (error) { 
                        console.error('Erro ao verificar status do pagamento:', error);
                        // Não para o intervalo, continua tentando
                    }
                }, 5000);
            }
            
            // Modal Sobre CPF
            const btnWhyCpf = document.getElementById('btn-why-cpf');
            const cpfInfoModalOverlay = document.getElementById('cpf-info-modal-overlay');
            const cpfInfoModalContent = document.getElementById('cpf-info-modal-content');
            const btnCloseCpfModal = document.getElementById('btn-close-cpf-modal');
            const btnCloseCpfModalFooter = document.getElementById('btn-close-cpf-modal-footer');
            
            function openCpfModal() {
                if (cpfInfoModalOverlay && cpfInfoModalContent) {
                    cpfInfoModalOverlay.classList.remove('hidden');
                    setTimeout(() => {
                        cpfInfoModalOverlay.classList.remove('opacity-0');
                        cpfInfoModalContent.classList.remove('opacity-0', 'scale-95');
                        lucide.createIcons();
                    }, 10);
                }
            }
            
            function closeCpfModal() {
                if (cpfInfoModalOverlay && cpfInfoModalContent) {
                    cpfInfoModalOverlay.classList.add('opacity-0');
                    cpfInfoModalContent.classList.add('opacity-0', 'scale-95');
                    setTimeout(() => {
                        cpfInfoModalOverlay.classList.add('hidden');
                    }, 300);
                }
            }
            
            if (btnWhyCpf) {
                btnWhyCpf.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openCpfModal();
                });
            }
            
            if (btnCloseCpfModal) {
                btnCloseCpfModal.addEventListener('click', closeCpfModal);
            }
            
            if (btnCloseCpfModalFooter) {
                btnCloseCpfModalFooter.addEventListener('click', closeCpfModal);
            }
            
            if (cpfInfoModalOverlay) {
                cpfInfoModalOverlay.addEventListener('click', function(e) {
                    if (e.target === cpfInfoModalOverlay) {
                        closeCpfModal();
                    }
                });
            }
            
            // Fechar modal de pagamento rejeitado ao clicar no overlay
            const rejectedModalOverlay = document.getElementById('rejected-modal-overlay');
            if (rejectedModalOverlay) {
                rejectedModalOverlay.addEventListener('click', function(e) {
                    if (e.target === rejectedModalOverlay) {
                        closeRejectedModal();
                    }
                });
            }

            // Sistema de Notificações de Vendas
            <?php if ($salesNotificationConfig['enabled'] ?? false && !empty($salesNotificationConfig['names'] ?? '')): ?>
            const salesNotificationConfig = {
                enabled: <?php echo ($salesNotificationConfig['enabled'] ?? false) ? 'true' : 'false'; ?>,
                names: <?php echo json_encode(array_filter(array_map('trim', explode("\n", $salesNotificationConfig['names'] ?? '')))); ?>,
                product: <?php echo json_encode($salesNotificationConfig['product'] ?? $produto['nome']); ?>,
                tempo_exibicao: <?php echo intval($salesNotificationConfig['tempo_exibicao'] ?? 5); ?> * 1000,
                intervalo_notificacao: <?php echo intval($salesNotificationConfig['intervalo_notificacao'] ?? 10); ?> * 1000
            };

            function showSalesNotification() {
                const notificationEl = document.getElementById('sales-notification');
                const nameEl = document.getElementById('notification-name');
                const productEl = document.getElementById('notification-product');
                
                if (!notificationEl || !nameEl || !productEl || !salesNotificationConfig.enabled || salesNotificationConfig.names.length === 0) {
                    return;
                }

                // Seleciona um nome aleatório
                const randomName = salesNotificationConfig.names[Math.floor(Math.random() * salesNotificationConfig.names.length)];
                
                // Atualiza o nome e produto
                nameEl.textContent = randomName;
                productEl.textContent = salesNotificationConfig.product || productEl.dataset.fallbackProductName || '';

                // Mostra a notificação
                notificationEl.classList.remove('hide');
                notificationEl.classList.add('show');
                
                // Atualiza ícones do Lucide
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }

                // Esconde após tempo_exibicao
                setTimeout(() => {
                    notificationEl.classList.remove('show');
                    notificationEl.classList.add('hide');
                }, salesNotificationConfig.tempo_exibicao);
            }

            // Inicia o ciclo de notificações se estiver habilitado
            if (salesNotificationConfig.enabled && salesNotificationConfig.names.length > 0) {
                // Mostra a primeira notificação após um pequeno delay
                setTimeout(() => {
                    showSalesNotification();
                }, 2000);

                // Configura o intervalo para mostrar novas notificações
                notificationTimer = setInterval(() => {
                    showSalesNotification();
                }, salesNotificationConfig.intervalo_notificacao);
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>