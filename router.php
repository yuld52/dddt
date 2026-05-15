<?php
/**
 * PHP Built-in Server Router
 * Handles URL routing to mimic Apache .htaccess behavior
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$base = __DIR__;

// Fix PHP_SELF and SCRIPT_NAME so forms inside required files post to the correct URL
$_SERVER['PHP_SELF'] = $uri;
$_SERVER['SCRIPT_NAME'] = $uri;

// Serve static files directly (images, css, js, etc.)
if ($uri !== '/' && file_exists($base . $uri) && !is_dir($base . $uri)) {
    // Let the built-in server handle static files
    return false;
}

// Strip query string
$path = strtok($uri, '?');

// Remove leading slash
$path = ltrim($path, '/');

// Exact PHP file mapping
$php_mappings = [
    ''                    => 'index.php',
    'login'               => 'login.php',
    'logout'              => 'logout.php',
    'register'            => 'register.php',
    'dashboard'           => 'dashboard.php',
    'admin'               => 'admin.php',
    'admin_dashboard'     => 'admin_dashboard.php',
    'admin_usuarios'      => 'admin_usuarios.php',
    'admin_configuracoes' => 'admin_configuracoes.php',
    'vendas'              => 'vendas.php',
    'vendas_actions'      => 'vendas_actions.php',
    'checkout_editor'     => 'checkout_editor.php',
    'checkout'            => 'checkout.php',
    'process_payment'     => 'process_payment.php',
    'obrigado'            => 'obrigado.php',
    'aguardando'          => 'aguardando.php',
    'notification'        => 'notification.php',
    'forgot_password'     => 'forgot_password.php',
    'reset_password'      => 'reset_password.php',
    'check_status'        => 'check_status.php',
    'verificar_subscriptions' => 'verificar_subscriptions.php',
    'verificar_utmfy'     => 'verificar_utmfy.php',
    'member_login'        => 'member_login.php',
    'member_logout'       => 'member_logout.php',
    'member_area_dashboard' => 'member_area_dashboard.php',
    'member_course_view'  => 'member_course_view.php',
    'member_forgot_password' => 'member_forgot_password.php',
    'member_setup_password' => 'member_setup_password.php',
    'curso_preview'       => 'views/curso_preview.php',
    'cloned_site_viewer'  => 'views/cloned_site_viewer.php',
    'api'                 => 'api.php',
    'sw.js'               => 'sw.js',
    'clear_template'      => 'clear_template.php',
];

// Check exact mapping first
if (isset($php_mappings[$path])) {
    $file = $base . '/' . $php_mappings[$path];
    if (file_exists($file)) {
        $_GET['url'] = $path;
        require $file;
        return;
    }
}

// Handle API routes: /api/something -> /api/something.php
if (preg_match('#^api/([^/]+)$#', $path, $m)) {
    $api_file = $base . '/api/' . $m[1] . '.php';
    if (file_exists($api_file)) {
        require $api_file;
        return;
    }
}

// Try direct .php file
if (file_exists($base . '/' . $path . '.php')) {
    $_GET['url'] = $path;
    require $base . '/' . $path . '.php';
    return;
}

// Fallback: route through index.php with url param
$_GET['url'] = $path;
require $base . '/index.php';
