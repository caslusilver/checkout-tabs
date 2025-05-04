<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Endpoint AJAX para salvar na sessão do WooCommerce e retornar fragments
add_action('wp_ajax_store_webhook_shipping', 'store_webhook_shipping');
add_action('wp_ajax_nopriv_store_webhook_shipping', 'store_webhook_shipping');
function store_webhook_shipping() {
    $t1 = microtime(true);
    $initial_memory = memory_get_peak_usage();

    // Verificar nonce para segurança
    check_ajax_referer('store_webhook_shipping', 'security');

    // Definir DEBUG no backend para logs
    $is_debug_enabled = defined('CC_DEBUG') && CC_DEBUG;
    if ($is_debug_enabled) {
         error_log('[SWHS DEBUG] DEBUG MODE IS ACTIVE.');
         error_log('[SWHS DEBUG] Request POST data: ' . print_r($_POST, true)); // Log all POST data
    }


    $fragments = [];
    $data_processed_successfully = false; // Indica se a data de entrada foi válida e processada (não se o recálculo aconteceu)
    $recalculated = false;
    $response_data = []; // Initialize response data structure

    if (!empty($_POST['shipping_data'])) {
        $data = json_decode(wp_unslash($_POST['shipping_data']), true);

        if (is_array($data)) {
            $data_processed_successfully = true; // Data de entrada é um array válido
            $existing_data = WC()->session->get('webhook_shipping');

            // Otimização A2: Comparar dados recebidos com os dados já na sessão.
            // Se forem idênticos, pular recálculo custoso, mas ainda gerar fragments.
            // Usar json_encode para comparação profunda confiável.
            if (json_encode($existing_data) !== json_encode($data)) {
                 if ($is_debug_enabled) error_log('[SWHS DEBUG] Nova data recebida, recalculando WC. Memória inicial: ' . size_format($initial_memory));
                 WC()->session->set('webhook_shipping', $data);

                 // Força WooCommerce a refazer as shipping rates (aciona o filtro)
                 // WC()->cart->calculate_shipping(); // calculate_totals() also triggers this

                 // Agora recalcula tudo (subtotal+frete+impostos)
                 WC()->cart->calculate_totals();

                 // Persiste na sessão (o calculate_totals já deve fazer isso, mas garantir não custa)
                 if (method_exists(WC()->cart, 'set_session')) {
                     WC()->cart->set_session();
                 }
                 $recalculated = true;
                 if ($is_debug_enabled) error_log('[SWHS DEBUG] Recálculo WC concluído.');

             } else {
                 if ($is_debug_enabled) error_log('[SWHS DEBUG] Dados idênticos aos da sessão. Pulando recálculo.');
             }

            // A3: Retornar os fragments de revisão de pedido diretamente
            // WC_AJAX::get_refreshed_fragments() já retorna a estrutura { fragments: {...}, cart_hash: '...' }
            // INVESTIGAÇÃO FRAGMENTOS (v3.1.16): Verificar o conteúdo de $fragments_data antes de retornar.
            if ( class_exists( 'WC_AJAX' ) && method_exists( 'WC_AJAX', 'get_refreshed_fragments' ) ) {
                 if ($is_debug_enabled) error_log('[SWHS DEBUG] Gerando fragments via WC_AJAX::get_refreshed_fragments().');
                 $fragments_data = WC_AJAX::get_refreshed_fragments();

                 // Ensure data is an array or object before accessing fragments
                 $fragments_data = is_array($fragments_data) || is_object($fragments_data) ? $fragments_data : [];
                 $fragments  = isset( $fragments_data['fragments'] ) ? $fragments_data['fragments'] : [];

                 if ($is_debug_enabled) {
                      error_log('[SWHS DEBUG] Conteúdo de fragments gerados (chaves): ' . print_r(array_keys($fragments), true)); // Log fragment keys
                      // Avoid logging full large fragments unless strictly necessary for deep debugging
                      // error_log('[SWHS DEBUG] Conteúdo completo dos fragments gerados: ' . print_r($fragments, true)); // Log full fragments content (can be very large)
                 }

                 // Retornar a estrutura esperada pelo JS e pelo WC_AJAX handler padrão (fallback)
                 $response_data = $fragments_data;
                 $response_data['success'] = true; // Adiciona explicitamente a flag de sucesso para o JS (v3.1.1)

                 if ($is_debug_enabled) error_log('[SWHS DEBUG] Fragments gerados.');
            } else {
                 if ($is_debug_enabled) error_log('[SWHS ERROR] WC_AJAX ou get_refreshed_fragments não disponível. Não é possível gerar fragments.');
                 // Retorna uma estrutura compatível mesmo sem fragments
                 $response_data = [
                     'fragments' => [], // Retorna fragments vazios
                     'cart_hash' => WC()->cart ? WC()->cart->get_cart_hash() : '',
                     'wc_ajax_url' => class_exists('WC_AJAX') ? WC_AJAX::get_endpoint( "%%endpoint%%" ) : '',
                     'success' => true // Considera sucesso se os dados foram salvos, mesmo sem fragments
                 ];
                 if ($is_debug_enabled) error_log('[SWHS DEBUG] Response data fallback (sem fragments): ' . print_r($response_data, true));
            }
        } else {
             if ($is_debug_enabled) error_log('[SWHS ERROR] Dados recebidos não são um array válido. $_POST['shipping_data'] estava: ' . print_r($_POST['shipping_data'], true));
             // Retorna error se os dados de entrada são inválidos
             wp_send_json_error(['message' => 'Dados de frete inválidos na entrada.']);
             exit; // Termina a execução após enviar JSON
        }
    } else {
        if ($is_debug_enabled) error_log('[SWHS ERROR] shipping_data vazio na requisição AJAX.');
         // Retorna error se os dados de entrada estão vazios
        wp_send_json_error(['message' => 'Dados de frete vazios na entrada.']);
         exit; // Termina a execução após enviar JSON
    }

    $t2 = microtime(true);
    $peak_memory = memory_get_peak_usage();
    $cart_item_count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    $chosen_methods_raw = WC()->session->get('chosen_shipping_methods');
    $chosen_method_log = is_array($chosen_methods_raw) ? implode(',', $chosen_methods_raw) : 'none';


    // Log extra
    if ($is_debug_enabled) error_log('[SWHS] chosen='. maybe_serialize($chosen_methods_raw) . ' Packages available: ' . count(WC()->cart->get_shipping_packages())); // Count packages instead of rates

    // Definir cabeçalhos de debug (Item B3, Item 5 Backend) - Sempre adicionar cabeçalhos se possível
    header('X-StoreWebhook: store_webhook_shipping');
    header('X-Exec-Time: ' . sprintf('%.3f', ($t2 - $t1)) . 's');
    header('X-Peak-Memory: ' . size_format($peak_memory));
    header('X-Cart-Items: ' . $cart_item_count);
    header('X-Recalculated: ' . ($recalculated ? 'yes' : 'no'));
    header('X-Chosen-Method: ' . $chosen_method_log);
    header('X-CC-Debug: ' . ($is_debug_enabled ? 'true' : 'false')); // Adicionar cabeçalho para debug state


    // Retornar sucesso com os dados de fragments (Item A3)
    // Apenas retornamos sucesso se os dados de entrada foram válidos e processados inicialmente.
    if ($data_processed_successfully) {
        wp_send_json_success( $response_data );
    } else {
        // Este caso já foi tratado acima com wp_send_json_error, mas como fallback:
         if ($is_debug_enabled) error_log('[SWHS ERROR] Fallback: sending json_error because data_processed_successfully is false.');
         wp_send_json_error(['message' => 'Falha no processamento dos dados de frete no backend (fallback error).']);
    }

    exit; // Termina a execução após enviar JSON (se não terminou antes)
}

// Filtro para injetar as taxas no front-end do WooCommerce - prioridade alta (999)
add_filter('woocommerce_package_rates', 'override_shipping_rates_with_webhook', 999, 2);
function override_shipping_rates_with_webhook($rates, $package) {
    $is_debug_enabled = defined('CC_DEBUG') && CC_DEBUG;
    // if ($is_debug_enabled) error_log('[DEBUG] Aplicando override_shipping_rates_with_webhook.'); // Log em cada aplicação
    $web = WC()->session->get('webhook_shipping');
    if (is_array($web)) {
        if ($is_debug_enabled) error_log('[DEBUG] Dados do webhook_shipping encontrados na sessão. Aplicando taxas...');

        // Não é necessário clonar, o array $rates já é uma cópia que podemos modificar

        $modified_rates = []; // Usaremos um novo array para as taxas filtradas/modificadas

        foreach ($rates as $rate_id => $rate) {
            $rate_identifier = $rate->get_method_id() . ':' . $rate->get_instance_id();
            $modified = false; // Flag para saber se a taxa foi modificada ou removida

            switch ($rate_identifier) {
                case 'flat_rate:1': // Assumindo flat_rate:1 é o PAC MINI
                    $valor = $web['fretePACMini']['valor'] ?? '';
                    // TAREFA 1 (v3.1.8): Ocultar se valor for vazio, nulo, não numérico ou <= 0
                    if ( empty( $valor ) || ! is_numeric( $valor ) || floatval( $valor ) <= 0 ) {
                        if ($is_debug_enabled) error_log('[DEBUG] Removendo flat_rate:1 (PAC Mini) devido a valor inválido/zerado: ' . ($valor === '' ? 'empty' : ($valor === null ? 'null' : $valor)));
                        // Não adiciona esta taxa a $modified_rates
                    } else {
                        $rate->set_cost( floatval( $valor ) );
                        $modified_rates[$rate_id] = $rate; // Adiciona a taxa modificada
                        if ($is_debug_enabled) error_log('[DEBUG] Atualizado flat_rate:1 (PAC Mini) para ' . $valor);
                    }
                    $modified = true;
                    break;

                case 'flat_rate:5': // SEDEX
                    $valor = $web['freteSedex']['valor'] ?? '';
                     // TAREFA 1 (v3.1.8): Ocultar se valor for vazio, nulo, não numérico ou <= 0
                    if ( empty( $valor ) || ! is_numeric( $valor ) || floatval( $valor ) <= 0 ) {
                         if ($is_debug_enabled) error_log('[DEBUG] Removendo flat_rate:5 (SEDEX) devido a valor inválido/zerado: ' . ($valor === '' ? 'empty' : ($valor === null ? 'null' : $valor)));
                         // Não adiciona esta taxa a $modified_rates
                    } else {
                        $rate->set_cost( floatval( $valor ) );
                        $modified_rates[$rate_id] = $rate; // Adiciona a taxa modificada
                         if ($is_debug_enabled) error_log('[DEBUG] Atualizado flat_rate:5 (SEDEX) para ' . $valor);
                    }
                    $modified = true;
                    break;

                case 'flat_rate:3': // Motoboy
                    $valor = $web['freteMotoboy']['valor'] ?? '';
                     // TAREFA 1 (v3.1.8): Ocultar se valor for vazio, nulo, não numérico ou <= 0
                    if ( empty( $valor ) || ! is_numeric( $valor ) || floatval( $valor ) <= 0 ) {
                         if ($is_debug_enabled) error_log('[DEBUG] Removendo flat_rate:3 (Motoboy) devido a valor inválido/zerado: ' . ($valor === '' ? 'empty' : ($valor === null ? 'null' : $valor)));
                         // Não adiciona esta taxa a $modified_rates
                    } else {
                         $rate->set_cost( floatval( $valor ) );
                         $modified_rates[$rate_id] = $rate; // Adiciona a taxa modificada
                         if ($is_debug_enabled) error_log('[DEBUG] Atualizado flat_rate:3 (Motoboy) para ' . $valor);
                    }
                    $modified = true;
                    break;
                // Adicione outros cases conforme necessário para outros métodos
            }

            // Se a taxa não corresponde a nenhum dos casos acima, mantê-la no resultado
            if (!$modified) {
                $modified_rates[$rate_id] = $rate;
            }
        }
         if ($is_debug_enabled) error_log('[DEBUG] Finalizado override_shipping_rates_with_webhook. Retornando ' . count($modified_rates) . ' taxas.');
         return $modified_rates; // Retorna o array com as taxas modificadas/filtradas

    } else {
         if ($is_debug_enabled) error_log('[DEBUG] webhook_shipping não encontrado na sessão, retornando taxas originais.');
         return $rates; // Retorna as taxas originais se não houver dados do webhook na sessão
    }
} 