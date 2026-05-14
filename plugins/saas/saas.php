<?php
/**
 * Plugin SaaS - Arquivo Principal
 * Este arquivo é carregado pelo plugin_loader quando o plugin está ativo
 */

// Carregar funções SaaS
if (file_exists(__DIR__ . '/../../saas/includes/saas_functions.php')) {
    require_once __DIR__ . '/../../saas/includes/saas_functions.php';
}

if (file_exists(__DIR__ . '/../../saas/includes/saas_limits.php')) {
    require_once __DIR__ . '/../../saas/includes/saas_limits.php';
}

if (file_exists(__DIR__ . '/../../saas/saas.php')) {
    require_once __DIR__ . '/../../saas/saas.php';
}


