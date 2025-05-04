<?php
/**
 * Plugin Name: Checkout Tabs
 * Description: Converte o checkout em etapas (era seu snippet).
 * Version:     0.1.0
 * Author:      @casluads
 * GitHub Plugin URI: caslusiver/checkout-tabs
 * Primary Branch: main
 * License:     GPL-2.0+
 * Text Domain: checkout-tabs
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define a constante para o caminho do arquivo principal do plugin
if ( ! defined( 'CHECKOUT_TABS_FILE' ) ) {
    define( 'CHECKOUT_TABS_FILE', __FILE__ );
}

// Core (Constantes, etc)
require_once __DIR__ . '/includes/core-hooks.php';

// Helpers (Funções utilitárias)
require_once __DIR__ . '/includes/helpers.php';

// Lógica do Admin (vazio por enquanto)
if ( is_admin() ) {
    // require_once __DIR__ . '/admin/admin-settings.php';
}
// Lógica do Frontend (Público)
else {
    // AJAX e Filtros de Frete
    require_once __DIR__ . '/includes/ajax-store.php';
    // Assets (CSS/JS Enqueue e HTML/CSS inline)
    require_once __DIR__ . '/public/assets.php';
}
