<?php
/**
 * Abas do Checkout - Versão 3.1.16
 *
 * Personalização do Checkout do WooCommerce com Abas + Barra de Progresso e
 * consulta de frete via webhook - INTEGRAÇÃO COMPLETA COM WC_SESSION.
 *
 * Adicionado:
 * - Toggle de DEBUG via constante CC_DEBUG (true/false) no PHP, passado para o JS.
 * - O painel de logs no front-end e os console.log são exibidos apenas quando CC_DEBUG é true.
 *
 * Atualizações na versão 3.1.16:
 * - **Correção CRÍTICA de DEBUG:** Corrigido typo `ajaxWCEndEnd` para `ajaxWCEndTime` na função `log` do JS, habilitando corretamente o log de tempo final do AJAX do WC. Adicionado `try...catch` no `JSON.stringify` da função log para evitar que erros no objeto de dados quebrem o painel de debug.
 * - **Robustez na manipulação do DOM:** Aprimorada a lógica de re-ordenação dos elementos na aba Pagamento (`Order Notes`, `e-coupon-box`, `#payment`, `checkout_coupon`) após a aplicação de fragments nos listeners `armazenarDadosNoServidor` e `updated_checkout`. Adicionadas verificações `.length > 0` antes de tentar manipular elementos e garantida a re-seleção dos elementos *dentro* da aba correta (`#tab-pagamento .selector`).
 * - **Refinamento do Loading/Events:** Ajustada a lógica do listener `on('update_checkout', ...)` para SEMPRE mostrar o overlay de carregamento quando o evento WC padrão é disparado, pois ele precede uma requisição AJAX. A lógica de *ocultar* o overlay no listener `on('updated_checkout', ...)` mantém a deferência para o listener `.one()` específico do CEP quando esse fluxo está ativo, garantindo que a limpeza ocorra após a transição da aba.
 * - **Logs PHP:** Adicionados logs na função `store_webhook_shipping` para exibir as chaves dos fragments retornados por `WC_AJAX::get_refreshed_fragments()`, auxiliando na identificação de fragmentos inesperados (como mini-cart).
 * - **Verificação do Fluxo CEP (v3.1.15):** Validada a lógica do clique no botão CEP, da promise `consultarCepEFrete`, do registro `.one('updated_checkout', handleUpdatedCheckoutForCepAdvance)`, e da função `handleUpdatedCheckoutForCepAdvance`. Esta lógica central para o avanço após a atualização do WC foi confirmada e mantida.
 * - **Verificação de Transições de Aba:** Revisada a lógica de `removeClass('active').hide()` e `addClass('active').show()` em todos os handlers de botões de navegação para garantir consistência.
 *
 * Atualizações na versão 3.1.15:
 * - **Correção CRÍTICA do fluxo de "Avançar" na aba CEP:**
 *   - O clique no botão "Avançar" na aba CEP (`#btn-avancar-para-endereco`) agora apenas inicia o estado de processamento e chama a cadeia assíncrona (`consultarCepEFrete`).
 *   - A função `consultarCepEFrete` (promisificada) chama o webhook, processa frontend e armazena backend. Ela resolve com `true` SOMENTE se toda a cadeia foi bem-sucedida.
 *   - Se `consultarCepEFrete` resolve com `true`, SOMENTE ENTÃO o listener `.one('updated_checkout', handleUpdatedCheckoutForCepAdvance)` é registrado, e em seguida `$(document.body).trigger('update_checkout');` é disparado.
 *   - O avanço da aba CEP para Endereço (`#tab-dados-entrega`) e a limpeza final do estado de processamento (overlay, classe do botão) agora ocorrem EXCLUSIVAMENTE dentro do listener `.one('updated_checkout', handleUpdatedCheckoutForCepAdvance)`, garantindo que isso aconteça APÓS o WooCommerce ter aplicado os fragments e disparado `updated_checkout`.
 *   - Se `consultarCepEFrete` resolve com `false` (erro no webhook, processamento ou armazenamento backend), o estado de processamento é removido IMEDIATAMENTE no `.catch()`/`.then(false)` do click handler, e a aba NÃO avança.
 *   - O listener geral `$(document.body).on('updated_checkout', ...)` foi modificado para NÃO remover o estado de processamento GERAL (overlay) se a aba CEP estiver ativa E o botão do CEP estiver processando. Ele delega essa limpeza ao listener `.one()`. Em outros casos, ele limpa normalmente.
 *   - Melhoria nos logs para diferenciar os estados de processamento e os triggers de eventos.
 * - **Análise Fragmentos PHP:** Confirmado que `store_webhook_shipping` chama `WC_AJAX::get_refreshed_fragments()` após recalcular, o que é o método padrão para obter fragmentos de checkout. Se mini-cart fragments estão aparecendo, a causa provável é externa a esta função PHP específica (filtro, plugin, tema). A função mantém a lógica de retornar a estrutura esperada pelo WC AJAX. (Melhorado log nesta versão 3.1.16).
 *
 * Atualizações na versão 3.1.14:
 * - Inclusão do nome completo do cliente no payload enviado para o webhook externo de consulta de frete.
 *
 * Atualizações na versão 3.1.13:
 * - Ajustes no CSS para forçar a exibição das abas com `display: block !important;` e ocultar as não-ativas com `display: none;`, contornando possíveis conflitos com `!important` de temas ou plugins.
 * - Ajustes finos no CSS de posicionamento e gap dos botões de navegação e do formulário de cupom em mobile.
 * - Ajustes finos no CSS para garantir que campos importantes (nome, sobrenome, celular) usem 100% da largura em desktop, e reordenar logradouro antes do número na aba Endereço em desktop via flexbox.
 *
 * Atualizações na versão 3.1.12:
 * - Melhoria no overlay de carregamento:
 *   - Overlay agora é full-screen (position: fixed, 100vw/100vh) cobrindo toda a viewport.
 *   - Blur aumentado para 4px.
 *   - Spinner centralizado perfeitamente via transform.
 *   - Exibição/ocultação do overlay no JS agora força display: flex/none.
 *
 * Atualizações na versão 3.1.11:
 * - Substituída a barra de progresso superior (`.frete-loading`) por um overlay escurecido com blur e spinner azul no centro do formulário de checkout.
 *
 * (Restante das atualizações das versões anteriores mantidas para contexto histórico)
 */

// Registra script para checkout com parâmetros localizados
function enqueue_checkout_scripts() {
    if (is_checkout() && !is_wc_endpoint_url()) {

        // Adicionar DEBUG toggle (v3.1.9)
        if ( ! defined( 'CC_DEBUG' ) ) {
            define( 'CC_DEBUG', true ); // ← mudo para true quando quiser ver logs
        }

        // WC-checkout já carrega jquery. Incluir wc-checkout para garantir eventos.
        wp_register_script('child-checkout', '', ['jquery', 'wc-checkout'], null, true);
        wp_localize_script('child-checkout', 'cc_params', [
            'debug'      => CC_DEBUG,      // <-- nova chave DEBUG
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('store_webhook_shipping'),
            'webhook_url'=> 'https://webhook.cubensisstore.com.br/webhook/consulta-frete' // Substitua pelo seu webhook real
        ]);
        wp_enqueue_script('child-checkout');

        // Adicionar script de máscara, se não for carregado por outro plugin
        // Verifica se já existe script jquery-mask ou jquery.mask.min.js enfileirado
         if (!wp_script_is('jquery-mask', 'enqueued') && !wp_script_is('jquery-mask.min', 'enqueued') && !wp_script_is('jquery-maskmoney', 'enqueued')) {
             wp_enqueue_script('jquery-mask', '//cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js', ['jquery'], '1.14.16', true);
         } else {
             // Se jquery-maskmoney está carregado, pode não ter máscara para telefone/CEP
             if (wp_script_is('jquery-maskmoney', 'enqueued') && !wp_script_is('jquery-mask', 'enqueued') && !wp_script_is('jquery.mask.min', 'enqueued')) {
                  wp_enqueue_script('jquery-mask', '//cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js', ['jquery'], '1.14.16', true);
             }
         }
    }
}
add_action('wp_enqueue_scripts', 'enqueue_checkout_scripts');

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
             if ($is_debug_enabled) error_log('[SWHS ERROR] Dados recebidos não são um array válido. $_POST[\'shipping_data\'] estava: ' . print_r($_POST['shipping_data'], true));
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

function custom_checkout_assets() {
    if (is_checkout() && !is_wc_endpoint_url()) :
    ?>
    <style>
        /* =============================
           Estilização das Opções de Frete
           (Mantido o estilo do contêiner e itens, mas cores do preço são do template)
        ============================== */
        #shipping_method li,
        .woocommerce-shipping-methods li,
        .e-checkout__shipping-methods li {
            background: #F9FAFA;
            border-radius: 4px;
            margin-bottom: 10px;
            padding: 10px;
            cursor: pointer;
            transition: background 0.3s;
        }
        #shipping_method li.active,
        #shipping_method li.selected, /* Adicionado selected para compatibilidade */
        .woocommerce-shipping-methods li.active,
        .woocommerce-shipping-methods li.selected,
        .e-checkout__shipping-methods li.active,
        .e-checkout__shipping-methods li.selected {
            color: #000;
            /* Opcional: borda ou fundo diferente para destacar o selecionado */
            border: 1px solid #0075FF;
            background-color: #E2EDFB;
        }


        /* =============================
           Estilização das Abas do Checkout
        ============================== */
        /* --- HOTFIX (v3.1.9 / v3.1.13): faz as abas reaparecerem ------------------- */
        /* Isso contorna o display:none !important no form.checkout > div gerado por alguns plugins/temas */
        body.woocommerce-checkout form.checkout .checkout-tab{
            display:block !important; /* garante que fiquem visíveis */
            /* Reset flexbox/ordering if needed for default state */
            flex-direction: initial !important; /* Reset default direction */
            order: initial !important; /* Reset default order */
        }

        body.woocommerce-checkout form.checkout .checkout-tab:not(.active){
            display:none !important; /* mas só a aba corrente permanece exibida */
        }
        /* -------------------------------------------------------- */


        /* Esconde o formulário de checkout original e a seção de revisão do pedido, pagamentos, cupons */
        /* O conteúdo será movido para as abas pelo JavaScript */
        /* Estes elementos agora estão DENTRO das nossas abas.
           A regra CSS acima gerencia a visibilidade das abas container.
           Não precisamos mais desta regra global para esconder os originais. */


        /* Botão de Finalizar Pedido do WooCommerce */
         /* TAREFA 2 (v3.1.3): Oculta o contêiner .place-order nas abas que NÃO são a de pagamento */
         .checkout-tab:not(#tab-pagamento) .place-order{
            display:none!important;
         }

         /* TAREFA 2 (v3.1.3) / TAREFA 2 (v3.1.8): Estilo específico para o contêiner PLACE ORDER dentro da seção #payment */
         /* NOTA: Agora que ele fica DENTRO do #payment, o JS não move mais. */
         /* A visibilidade é controlada pela regra acima e pelo display:block padrão do #payment */
         #tab-pagamento #payment .place-order {
             display: block !important; /* Ensure it's visible when #payment is visible */
             width: 100%; /* Make the container 100% width */
             /* TAREFA 2 (v3.1.8): Espaço entre o botão Finalizar e o botão Voltar */
             margin-top: 20px;
             margin-bottom: 0; /* Margin controlled by parent (.tab-buttons) in mobile */
        }
         /* TAREFA 2 (v3.1.8): Ajuste de margem em mobile */
         @media (max-width:768px){
             #tab-pagamento #payment .place-order{
                 margin-top:0;
                 margin-bottom:0;
             }
         }


        /* TAREFA 2 (v3.1.3): Estilo específico para o botão #place_order dentro do contêiner .place-order */
        /* Mantendo estilos do checkout-next-btn */
        #tab-pagamento #payment .place-order #place_order {
             padding: 10px 20px;
             background: #0075FF;
             color: #fff;
             border: none;
             cursor: pointer;
             font-weight: 500;
             border-radius: 3px !important;
             width: 100%; /* Button inside 100% container */
             text-align: center;
        }
         #tab-pagamento #payment .place-order #place_order:hover:not(:disabled) {
             background: #005BC7;
         }
          #tab-pagamento #payment .place-order #place_order:disabled {
             background-color: #cccccc !important; /* Cor mais clara quando desabilitado */
             cursor: not-allowed !important;
             opacity: 0.7;
         }
         /* Garantir que a cor do texto do PLACE ORDER disabled não seja afetada por outras regras */
         #tab-pagamento #payment .place-order #place_order:disabled span {
              color: #fff !important; /* Mantém o texto branco no botão desabilitado */
         }

        /* Forçar arredondamento em todos os botões de navegação das abas */
        .checkout-next-btn, .checkout-back-btn {
            border-radius: 3px !important;
        }

        /* Botão Avançar */
        .checkout-next-btn {
            margin-top: 20px;
            padding: 10px 20px;
            background: #0075FF;
            color: #fff;
            border: none;
            cursor: pointer;
            font-weight: 500;
        }
        .checkout-next-btn:hover:not(:disabled) {
            background: #005BC7;
        }
        /* Botão desabilitado */
         .checkout-next-btn:disabled {
             background-color: #cccccc !important; /* Cor mais clara quando desabilitado */
             cursor: not-allowed !important;
             opacity: 0.7;
         }
         /* Garantir que a cor do texto do NEXT disabled não seja afetada por outras regras */
         .checkout-next-btn:disabled span {
              color: #fff !important; /* Mantém o texto branco no botão desabilitado */
         }


        /* Botão Voltar - com cor de texto #005BC7 e background #E2EDFB */
        .checkout-back-btn {
            background: #E2EDFB;
            color: #005BC7 !important; /* Define a COR DO TEXTO para #005BC7 */
            font-weight: 500;
            margin-right: 10px;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
             /* Remover margin-top para ser controlado pelo .tab-buttons */
            margin-top: 0;
        }
        .checkout-back-btn:hover:not(:disabled) {
            background: #9DCAFF;
        }

        /* Responsividade dos botões de navegação das abas - AJUSTADO PARA TAREFA 2 v3.1.3 */
        @media (max-width: 768px) {
             /* Buttons get full width */
             .checkout-next-btn, .checkout-back-btn {
                 width: 100%;
                 margin-right: 0;
             }
             /* The .place-order container within #payment also gets full width via the rule above */


             /* Stack buttons in vertical column on mobile */
             .tab-buttons {
                  display: flex !important; /* Ensure flex is applied */
                  flex-direction: column !important;
                  align-items: flex-start !important; /* Align buttons to the left when stacked */
                  padding: 0 !important; /* Remove extra side padding on mobile */
                  width: 100% !important; /* Ensure container is full width */
                  margin-top: 20px !important; /* Space above the back button */
                  gap: 10px !important; /* Espaço entre os botões empilhados (v3.1.13) */
             }


             /* Apply flexbox to the main tab content area for mobile stacking */
             /* This allows controlling the order of major blocks like #payment and .tab-buttons */
             /* Re-applying flex for mobile stack (v3.1.13) */
             #tab-dados-pessoais, #tab-cep, #tab-dados-entrega, #tab-resumo-frete, #tab-pagamento {
                 display: flex !important; /* Force flex on mobile */
                 flex-direction: column !important; /* Stack content vertically */
                 /* Default order is source order unless specified */
                 gap: 15px !important; /* Espaço entre os principais blocos na aba */
             }

             /* Visual ordering in mobile for tabs (buttons/elements stacked) */
             /* Keep default source order for elements within tabs, EXCEPT for the final tab where we want Place Order above Back */

             /* In Pagamento tab, explicitly order main blocks */
             /* Ensure all main blocks within #tab-pagamento have a base order */
             #tab-pagamento > * {
                 order: 0 !important; /* Default order */
             }

             /* Re-order the tab-buttons container to be after main content */
             #tab-pagamento .tab-buttons {
                  order: 1 !important; /* Place the back button container second */
                  margin-top: 0 !important; /* Space handled by elements above */
             }

             /* Order within the #payment block itself (Payment Methods vs Total Dup vs Place Order) */
             /* The .payment-total-dup and .place-order are now injected/kept inside #payment */
             #tab-pagamento #payment {
                  display: flex !important; /* Make #payment a flex container on mobile */
                  flex-direction: column !important; /* Stack its content vertically */
                  gap: 15px !important; /* Space between payment elements */
             }
             #tab-pagamento #payment .payment_methods { /* Payment methods list */
                  order: 0 !important; /* Show payment methods first */
                  margin-bottom: 0 !important; /* Reset default margin */
             }
             #tab-pagamento #payment .payment-total-dup { /* TAREFA 2 (v3.1.6/3.1.7) Duplicate Total */
                 order: 1 !important; /* Show total before place order */
                 margin-bottom: 0 !important; /* Reset default margin */
                 margin-top: 0 !important; /* Reset default margin */
             }
             #tab-pagamento #payment .place-order { /* Place Order container */
                  order: 2 !important; /* Show place order button second (above back button) */
                  margin-bottom: 0 !important; /* Reset default margin */
                  margin-top: 0 !important; /* Reset default margin */
             }

              /* Mobile Coupon form (v3.1.13) */
              #tab-pagamento .checkout_coupon {
                display: flex !important;
                flex-wrap: nowrap !important; /* Prevent wrapping */
                gap: 10px !important;
                align-items: center !important;
              }
              #tab-pagamento .checkout_coupon input#coupon_code {
                flex: 1 1 auto !important; /* Allow input to grow */
                width: auto !important; /* Override potential fixed width */
              }
              #tab-pagamento .checkout_coupon .button {
                flex: 0 0 auto !important; /* Keep button size fixed */
                width: auto !important; /* Override potential fixed width */
              }


        } /* End of media query */


        /* =============================
           Ocultar campos específicos (mantido)
        ============================== */
        #billing_persontype_field,
        .person-type-field.thwcfd-optional.thwcfd-field-wrapper.thwcfd-field-select.is-active {
            display: none !important;
        }
        #billing_country_field,
        .thwcfd-field-country {
            display: none !important;
        }
         /* Esconder títulos originais (mantido) */
        #order_review_heading,
         #payment_heading {
             display: none !important;
        }


        /* =============================
           Barra de Progresso (verde #33E480) - Ajustado para 5 passos
           (MANTIDA POR COMPATIBILIDADE, MAS OCULTADA PELO OVERLAY CSS)
        ============================== */
        .progress-container {
            position: relative;
            width: 100%;
            height: 3px;
            background-color: #e0e0e0;
            border-radius: 4px;
            margin-top: 5px;
             margin-bottom: 20px; /* Espaço abaixo da barra */
        }
        .progress-bar {
            height: 100%;
            background-color: #33E480;
            border-radius: 4px;
            width: 0%;
            transition: width 0.3s ease-in-out; /* Animação da barra */
        }
        .progress-indicator {
            position: absolute;
            top: -4px;
            width: 11px;
            height: 11px;
            background-color: #33E480;
            border-radius: 50%;
            transform: translateX(-50%);
             transition: left 0.3s ease-in-out; /* Animação do indicador */
        }

        /* =============================
           Notificação de WhatsApp inválido (mantido)
        ============================== */
        .whatsapp-invalido {
            color: #e63946;
            font-size: 14px;
            margin-top: 10px;
            margin-bottom: 10px;
            display: none;
            padding: 8px;
            background-color: #fff8f8;
            border-left: 3px solid #e63946;
            border-radius: 3px;
        }

        /* Prazo de entrega (mantido) */
        .prazo-entrega {
            display: block;
            color: #005BC7;
            font-size: 13px;
            margin-top: 5px;
            font-weight: normal;
        }

        /* Estilo para destacar as atualizações de frete (mantido) */
        .frete-atualizado {
            animation: highlight 1.5s ease-in-out;
        }

        @keyframes highlight {
            0% { background-color: #fdf2b3; }
            100% { background-color: transparent; }
        }

        /* =============================
           Painel de carregamento de frete original (mantido, mas ocultado pelo overlay CSS)
        ============================== */
        .frete-loading {
             /* display: none !important; */ /* <-- Já declarado no CSS do Overlay abaixo */
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: #f5f5f5;
            z-index: 9999; /* Z-index alto */
            overflow: hidden;
             display: none; /* Inicialmente oculto */
        }

        .frete-loading::after {
            content: "";
            position: absolute;
            left: -50%;
            width: 50%;
            height: 100%;
            background-color: #0075FF;
            animation: loading 1.5s infinite ease-in-out;
        }

        @keyframes loading {
            0% { left: -50%; }
            100% { left: 150%; }
        }


        /* =============================
           overlay com blur + spinner (NOVO - v3.1.11) (AJUSTADO v3.1.12)
        ============================== */
        /* Remove position relative do form para o overlay cobrir a viewport */
        form.checkout {
           position: static !important; /* Ou remove a regra de position se não for necessária para outros motivos */
        }

        .checkout-loading-overlay {
          position: fixed !important; /* Cobre toda a viewport */
          top: 0 !important;
          left: 0 !important;
          width: 100vw !important; /* Garante largura total da viewport */
          height: 100vh !important; /* Garante altura total da viewport */
          background: rgba(0,0,0,0.2) !important; /* Fundo semi-transparente */
          backdrop-filter: blur(4px) !important; /* Blur aumentado para 4px */
          -webkit-backdrop-filter: blur(4px) !important; /* Compatibilidade Safari */
          display: none; /* Inicialmente oculto (JS mudará para flex) */
          align-items: center; /* Centraliza verticalmente (flex) */
          justify-content: center; /* Centraliza horizontalmente (flex) */
          z-index: 10000; /* Fica acima da maioria dos elementos */
           /* Ocultar a barrinha original quando o overlay estiver ativo */
           &.active + .frete-loading { /* NÃO USE 'active', o display é controlado direto */
              display: none !important;
           }
        }

        .checkout-loading-overlay .spinner {
  position: absolute;
  top: 50%;
  left: 50%;
  /* removemos o translate daqui, pois já vamos controlar dentro do keyframe */
  border: 4px solid rgba(255,255,255,0.6);
  border-top: 4px solid #0075FF;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  transform-origin: center center;
  animation: spin 1s linear infinite;
}

/* Animação de rotação do spinner, mantendo o translate fixo */
@keyframes spin {
  from {
    transform: translate(-50%, -50%) rotate(0deg);
  }
  to {
    transform: translate(-50%, -50%) rotate(360deg);
  }
}

        /* opcional: esconda a barrinha original */
        /* JÁ DEFINIDO NO CSS DO OVERLAY */
        .frete-loading { display: none !important; }


        /* Debug Panel (v3.1.9) - Adicionado */
        #debug-panel-button {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999; /* Abaixo do overlay de loading principal */
            background: #ff5722;
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            font-size: 14px;
            font-family: sans-serif; /* Use a fonte padrão do sistema */
        }

        #debug-panel {
            position: fixed;
            bottom: 70px;
            right: 20px;
            width: 80%;
            max-width: 600px;
            height: 400px;
            background: white;
            z-index: 9999; /* Abaixo do overlay de loading principal */
            display: none;
            overflow: auto;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            border: 1px solid #ddd;
            font-family: sans-serif; /* Use a fonte padrão do sistema */
        }

        #debug-log-content {
            background: #f5f5f5;
            padding: 10px;
            border: 1px solid #ddd;
            height: 300px;
            overflow: auto;
            font-family: monospace;
            font-size: 12px;
            line-height: 1.4;
             white-space: pre-wrap; /* Wrap text in log content */
            word-wrap: break-word;
        }

        /* Botão de fechar painel de debug (v3.1.9) - Adicionado */
        #debug-panel-close {
            position: absolute;
            top: 8px;
            right: 8px;
            background: transparent;
            border: none;
            font-size: 18px;
            color: #000;
            cursor: pointer;
        }


        /* Aba CEP estilização (mantido) */
        #tab-cep {
            padding: 15px 0;
        }

        /* CEP digitado carregando (mantido) */
        .cep-loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Mensagem de erro de CEP (mantido) */
        .cep-erro {
            color: #e63946;
            font-size: 14px;
            margin-top: 5px;
            display: none;
        }

        /* Desabilitar botão durante processamento (mantido) */
        .btn-processing {
            opacity: 0.7;
            cursor: not-allowed !important;
            pointer-events: none;
        }

        /* Field CEP destaque - 100% de largura (mantido) */
        #tab-cep #billing_postcode_field {
            width: 100%;
            max-width: none;
            margin: 15px auto;
        }

        #tab-cep #billing_postcode,
        #tab-cep #billing_postcode_field .woocommerce-input-wrapper {
            width: 100% !important;
        }

        /* Título do CEP (mantido) */
        .cep-title {
            text-align: center;
            margin-bottom: 20px;
        }

        /* Descrição do CEP (mantido) */
        .cep-description {
            text-align: center;
            font-size: 14px;
            margin-bottom: 20px;
            color: #555;
        }

        /* Cursor de progresso para corpo (mantido) */
        body.processing {
            cursor: progress;
        }

        /* Ajustes para a tabela de revisão do pedido dentro da aba (mantido) */
         #tab-resumo-frete #order_review {
             margin-top: 20px; /* Espaço entre o título da aba e a tabela */
         }
          #tab-resumo-frete #order_review_heading {
               /* O título original já está escondido globalmente */
               display: none;
          }

         /* TAREFA 3 (v3.1.7): Ocultar elementos nativos de cupom fora da aba Pagamento */
         .woocommerce-info.woocommerce-coupon-message,
         .checkout_coupon{
             display: none !important;
         }
         /* TAREFA 1 (v3.1.7): Garantir que o .e-coupon-box custom esteja visível na aba Pagamento */
         #tab-pagamento .e-coupon-box {
            display: block !important; /* Override global hidden */
         }


        /* TAREFA 1 (v3.1.6): REMOVIDAS regras de Caixa de cupom custom */
        /* TAREFA 2 (v3.1.6): Resumo de Total duplicado */
        .payment-total-dup{
             margin-top: 20px ; /* Espaço antes do bloco total */
             margin-bottom: 20px; /* Espaço após o bloco total */
        }
        .payment-total-divider{
            border:0;
            height:2px;
            background:#979797 !important;
            margin:10px 0;
            
        }
        .payment-total-row{
            display:flex;
            justify-content:space-between;
            font-weight:600;
            font-size:16px;
            margin-bottom:10px;
        }


         /* ==============================
   CORREÇÃO POSICIONAMENTO BOTÕES (v3.1.13)
   ============================== */
/* Forçar os botões a ficarem sempre abaixo do campo e com 30px de distância */
/* Remove a regra display: block !important; width: 100% !important; margin-top: 30px !important; */
/* para permitir que o flexbox funcione corretamente em desktop */
.tab-buttons {
    /* display: block; -- Removido */
    /* width: 100%; -- Removido */
    margin-top: 30px; /* Espaço acima do container de botões */
    padding: 0; /* Garantir sem padding lateral */
    box-sizing: border-box;
}

/* ======= Desktop (v3.1.13) ======= */
@media (min-width: 769px) {
  /* Empilha os botões, 100% largura e 10px de gap */
  .tab-buttons {
    display: flex !important;
    flex-direction: column !important;
    gap: 10px !important; /* Espaço entre botões empilhados */
    width: 100% !important; /* Garantir que o container de botões ocupe 100% */
  }
  .tab-buttons .checkout-next-btn,
  .tab-buttons .checkout-back-btn {
    width: 100% !important; /* Botões com 100% da largura do container */
    margin-right: 0 !important; /* Remover margem lateral do botão Voltar */
  }

  /* 1) Campos nome, celular e sobrenome 100% de largura (v3.1.13) */
  #billing_first_name_field,
  #billing_cellphone_field,
  #billing_last_name_field {
    width: 100% !important;
    max-width: none !important;
  }

  /* 2) Na aba Endereço, reordena: logradouro ANTES do número (v3.1.13) */
  /* Primeiro garante que o wrapper seja flex container */
  #tab-dados-entrega .woocommerce-billing-fields__field-wrapper {
    display: flex !important;
    flex-wrap: wrap !important; /* Allow wrapping */
    gap: 15px !important; /* Space between fields */
  }
  /* Então define a ordem e largura 100% para cada field */
  #tab-dados-entrega #billing_address_1_field {
    order: 1 !important;
    flex: 0 0 100% !important; /* Full width, doesn't grow */
  }
  #tab-dados-entrega #billing_number_field {
    order: 2 !important;
    flex: 0 0 100% !important; /* Full width, doesn't grow */
  }
   #tab-dados-entrega #billing_neighborhood_field {
    order: 3 !important;
    flex: 0 0 100% !important;
  }
   #tab-dados-entrega #billing_complemento_field {
    order: 4 !important;
    flex: 0 0 100% !important;
  }
   #tab-dados-entrega #billing_city_field {
    order: 5 !important;
    flex: 0 0 100% !important;
  }
   #tab-dados-entrega #billing_state_field {
    order: 6 !important;
    flex: 0 0 100% !important;
  }

}

/* ======= Mobile (v3.1.13) ======= */
@media (max-width: 768px) {
   /* Botões de navegação já stackam via a regra .tab-buttons flex-direction: column; */
   /* Espaçamento entre eles é via gap: 10px na mesma regra. */
   /* Largura 100% já aplicada. */
}

/* ==============================
   AJUSTES ADICIONAIS (v3.1.13)
   ============================== */
/* Ensures payment methods list doesn't have extra margins */
#tab-pagamento #payment .payment_methods {
    margin-bottom: 0 !important;
}

/* Ensures form-row elements within tabs take full width */
.checkout-tab .form-row {
    width: 100% !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}


    </style>

    <script>
    jQuery(document).ready(function($) {
        // Configurações e variáveis globais
        var webhookUrl = cc_params.webhook_url;
        var ajaxUrl = cc_params.ajax_url;
        var nonce = cc_params.nonce;

        // DEBUG toggle (v3.1.9) - Lê a flag do PHP
        var debugMode = !!cc_params.debug; // !! converte para boolean

        // Configuração centralizada dos IDs de frete (mantida)
        var freteConfigs = {
            pacMini: {
                method_id: 'flat_rate',
                instance_id: '1',
                nome: 'PAC MINI'
            },
            sedex: {
                method_id: 'flat_rate',
                instance_id: '5',
                nome: 'SEDEX'
            },
            motoboy: {
                method_id: 'flat_rate',
                instance_id: '3',
                nome: 'Motoboy (SP)'
            }
        };

        // Flags e variáveis de estado
        var freteData = null; // Armazenar os dados retornados pelo webhook
        var consultaEmAndamento = false; // Flag para prevenir chamadas duplicadas de consulta principal (CEP)

        // Variáveis de timing para logs de performance (B1)
        var actionStartTime = 0; // Tempo inicial da ação do usuário (clique no botão ou clique no frete)
        var ajaxWebhookStartTime = 0;
        var ajaxWebhookEndTime = 0;
        var ajaxStoreStartTime = 0;
        var ajaxStoreEndTime = 0;
        var ajaxWCStartTime = 0; // Para o AJAX padrão do WC update_order_review
        var ajaxWCEndTime = 0; // v3.1.16: Corrected typo
        var fragmentsAppliedTime = 0;
        var currentPhase = ''; // Para logs estruturados
        var cursorTimer = null; // Timer para o cursor de progress

        // Adicionar elementos de interface (mantido)
         // Ocultamos a barrinha original via CSS, mas mantemos a div no DOM por segurança,
         // caso a lógica de toggle precise dela ou para remover a regra !important no futuro.
        $('body').append('<div class="frete-loading"></div>');

         // NOVO (v3.1.11): Adiciona o overlay de loading ao formulário de checkout
         // O CSS agora o torna full-screen independente de onde está no DOM,
         // mas colocá-lo no form.checkout pode ser semanticamente útil.
         $('form.checkout').append(`
            <div class="checkout-loading-overlay">
              <div class="spinner"></div>
            </div>
         `);

        // Adicionar mensagem de error para o CEP (mantido)
        if ($('#billing_postcode_field .cep-erro').length === 0) {
            $('#billing_postcode_field').append('<div class="cep-erro">CEP não encontrado. Por favor, verifique e tente novamente.</div>');
        }

        // Melhorar a experiência mobile para o campo CEP e aplicar máscara (mantido)
        // Verificar se a máscara já foi aplicada ou se o script está disponível
        if (typeof $.fn.mask !== 'undefined') {
             $('#billing_postcode').attr({
                 'type': 'tel',
                 'inputmode': 'numeric',
                 'pattern': '[0-9]*'
             }).mask('00000-000'); // Aplicar máscara de CEP
              // Aplicar máscara de telefone/WhatsApp
             $('#billing_cellphone').mask('(00) 00000-0000'); // Exemplo para SP, ajuste conforme necessário
             log('DEBUG     Plugin jQuery Mask disponível. Máscaras aplicadas.');
        } else {
            if (debugMode) log('AVISO     Plugin jQuery Mask não disponível. Máscaras não aplicadas.');
        }

        // Adicionar painel de debug se habilitado (v3.1.9)
        if (debugMode) { // <-- Wrap the debug panel creation and listeners
            // Check if panel elements already exist before appending (avoids duplicates on script re-run)
            if ($('#debug-panel-button').length === 0) {
                 $('body').append(`
                    <div id="debug-panel-button">Ver Logs</div>
                    <div id="debug-panel">
                        <button id="debug-panel-close">×</button>
                        <h3>Logs de Debug</h3>
                        <button id="copy-logs" style="margin-bottom: 10px;">Copiar Logs</button>
                        <button id="clear-logs" style="margin-bottom: 10px; margin-left: 10px;">Limpar</button>
                        <pre id="debug-log-content"></pre>
                    </div>
                 `);
                 log('INIT      Painel de debug adicionado.');

                // Botão para mostrar/esconder logs
                $('#debug-panel-button').on('click', function() {
                    $('#debug-panel').toggle();
                });

                // Botão para fechar o painel
                $('#debug-panel-close').on('click', function() {
                    $('#debug-panel').hide();
                });

                // Botão para copiar logs
                $('#copy-logs').on('click', function() {
                    const logContent = $('#debug-log-content').text();
                    navigator.clipboard.writeText(logContent).then(function() {
                        alert('Logs copiados para a área de transferência!');
                    }).catch(err => {
                         console.error('Erro ao copiar logs:', err);
                         alert('Erro ao copiar logs.');
                    });
                });

                // Botão para limpar logs
                $('#clear-logs').on('click', function() {
                    $('#debug-log-content').empty();
                    console.clear(); // Limpa o console do navegador também
                    log('DEBUG     Logs limpos.'); // Log this action itself
                });
            } else {
                 log('INIT      Painel de debug já existe no DOM.');
            }
        }


        // Função para registrar logs no console e na área de debug (B1, D) (v3.1.9 + v3.1.16)
        function log(message, data = null, phase = null) {
            if (!debugMode) return; // <-- Add the debug check here

            const now = performance.now();
            const timestamp = new Date().toLocaleTimeString('pt-BR', { hour12: false, second: 'numeric', fractionalSecondDigits: 3 });

            let timing_info = '';
            if (phase) {
                 currentPhase = phase; // Atualiza a fase atual para logs subsequentes sem fase
            } else {
                 phase = currentPhase; // Usa a fase atual se não especificado
            }

             // Ajusta a fase para ser mais consistente no log
             if (phase === 'AJAX_OUT_WEBHOOK') phase = 'WEBHOOK_OUT';
             if (phase === 'AJAX_IN_WEBHOOK') phase = 'WEBHOOK_IN';
             if (phase === 'AJAX_OUT_STORE') phase = 'STORE_OUT';
             if (phase === 'AJAX_IN_STORE') phase = 'STORE_IN';
             if (phase === 'AJAX_OUT_WC') phase = 'WC_OUT';
             if (phase === 'AJAX_IN_WC') phase = 'WC_IN';
             if (phase === 'FRAG_APP') phase = 'APPLY_FRAG';
             if (phase === 'FRAG_DONE') phase = 'UPDATE_DONE'; // Renomeado para clareza

            if (phase === 'WEBHOOK_IN') {
                 const deltaAjax = (ajaxWebhookEndTime > ajaxWebhookStartTime ? ajaxWebhookEndTime - ajaxWebhookStartTime : 0).toFixed(0);
                 timing_info = ` Δajax=${deltaAjax}ms`;
            } else if (phase === 'STORE_IN') {
                 const deltaAjax = (ajaxStoreEndTime > ajaxStoreStartTime ? ajaxStoreEndTime - ajaxStoreStartTime : 0).toFixed(0);
                 timing_info = ` Δajax=${deltaAjax}ms`;
            } else if (phase === 'WC_IN') {
                 // v3.1.16: Corrected typo here
                 const deltaAjax = (ajaxWCEndTime > ajaxWCStartTime ? ajaxWCEndTime - ajaxWCStartTime : 0).toFixed(0);
                 timing_info = ` Δajax=${deltaAjax}ms`;
            }

            // Log total time when relevant UI updates happen or process ends
            // Also log total time on specific failure points
            if (['UPDATE_DONE', 'UI', 'CEP_FAIL', 'ACTION', 'VALIDAÇÃO', 'ERROR', 'AJAX_ERROR', 'STORE_FAIL', 'WEBHOOK_FAIL', 'CHAIN_FAIL', 'CHAIN_ERROR'].includes(phase)) {
                 const totalTime = (performance.now() > actionStartTime ? performance.now() - actionStartTime : 0).toFixed(0);
                 timing_info += ` Total time = ${totalTime}ms`; // Append total time
            }


            const logPrefix = `[${timestamp}] ${phase.padEnd(10)}`;
            const logMessage = `${logPrefix} ${message}${timing_info}`;

            console.log(logMessage);
            if (data) console.log(data);

            if ($('#debug-log-content').length) { // Only append to panel if it exists (debugMode is true)
                var logItem = document.createElement('div');
                logItem.textContent = logMessage;

                if (data !== null && data !== undefined) { // Ensure data is not null or undefined before processing
                    const dataText = document.createElement('pre');
                    // Usar JSON.stringify para formatar objetos/arrays, exceto strings simples
                    // v3.1.16: Add try...catch around JSON.stringify for robustness
                    try {
                       dataText.textContent = typeof data === 'string' || typeof data === 'number' || typeof data === 'boolean' ? data : JSON.stringify(data, null, 2);
                    } catch (e) {
                       dataText.textContent = '[Erro ao serializar dados para log: ' + e.message + ']';
                       console.error('Erro ao serializar dados para painel de log:', e, data);
                    }

                    dataText.style.marginLeft = '20px';
                    dataText.style.color = '#0066cc'; // Cor azul para dados
                    logItem.appendChild(dataText);
                }

                $('#debug-log-content').append(logItem);
                // Rolagem automática
                $('#debug-log-content').scrollTop($('#debug-log-content')[0].scrollHeight);
            }
        }

         // Helper para mostrar/esconder loading UI (C1, C2) (AJUSTADO v3.1.11 -> v3.1.12)
         function setProcessingState(isProcessing, source = 'generic') {
              // Ensure actionStartTime is set if this is the first processing state activation
              if (isProcessing && actionStartTime === 0) {
                  actionStartTime = performance.now(); // Set if not already set by a button click
                   log(`UI        Setando estado de processamento: ${isProcessing ? 'true' : 'false'} (Source: ${source}). Action Start Time Set.`);
              } else {
                   log(`UI        Setando estado de processamento: ${isProcessing ? 'true' : 'false'} (Source: ${source}).`);
              }


              if (isProcessing) {
                  // Usa a função toggleLoading, que agora controla o overlay
                  toggleLoading(true);
                  $('body').addClass('processing');
                   // Limpa timer anterior antes de setar um novo
                   if(cursorTimer) clearTimeout(cursorTimer);
                   // Definir um timer para mudar o cursor para "progress" se levar > 1s
                   cursorTimer = setTimeout(function() {
                       $('body').css('cursor', 'progress');
                        log('UI         Processamento demorando > 1s, mudando cursor para progress.');
                    }, 1000);
              } else {
                   // Usa a função toggleLoading, que agora controla o overlay
                  toggleLoading(false);
                  $('body').removeClass('processing').css('cursor', ''); // Reseta o cursor
                  if(cursorTimer) {
                      clearTimeout(cursorTimer);
                      cursorTimer = null;
                  }
              }
         }

        // Função para encontrar o container de fretes (verifica múltiplos seletores) (mantido)
        // NOTA: Esta função ainda é útil para encontrar a lista UL de métodos de frete dentro do #order_review
        function getFreteContainer() {
            // Agora o container UL#shipping_method estará dentro do #order_review
            var $container = $('#tab-resumo-frete #order_review #shipping_method, #tab-resumo-frete #order_review .woocommerce-shipping-methods, #tab-resumo-frete #order_review .e-checkout__shipping-methods, #tab-resumo-frete #order_review ul.shipping_method, #tab-resumo-frete #order_review ul[data-shipping-methods]');

             if (!$container.length) {
                 log('DEBUG     Container de frete UL não encontrado dentro de #tab-resumo-frete #order_review!');
             } else {
                  log('DEBUG     Container de frete UL encontrado dentro de #tab-resumo-frete #order_review.');
             }

            return $container.first(); // Retorna o primeiro encontrado
        }

        // Função para verificar se o container de fretes está disponível (mantido)
        function isFreteContainerAvailable() {
             // Agora verificamos se o #order_review foi movido e se ele contém a lista de fretes
            var $container = $('#tab-resumo-frete #order_review');
            return $container.length > 0 && $container.find('input[name^="shipping_method"]').length > 0;
        }

        // Função para atualizar o estado do botão "Finalizar Pedido" (mantido, com ajuste para 5 abas)
        function updatePlaceOrderButtonState() {
             const $placeOrderBtn = $('#place_order');
             // Localiza o total do pedido dentro do #order_review (agora na aba 4)
             const $orderTotal = $('#tab-resumo-frete #order_review .order-total .amount, #tab-resumo-frete #order_review .order_details .amount');
             let total = $orderTotal.text().trim();
             const isLastTab = $('#tab-pagamento').hasClass('active'); // Verifica se está na última aba (Pagamento)

             log(`READY-CHECK updatePlaceOrderButtonState: Total encontrado: "${total}", Na última aba: ${isLastTab}`);

             // Verifica se o total parece válido E se está na última aba para habilitar
             // Total válido é qualquer string que não seja vazia, 'R$ 0,00' ou contenha 'Calcular'.
             // A formatação R$0,00 sem vírgula também é possível.
             const totalIsValid = total !== '' && total !== 'R$ 0,00' && total !== 'R$0,00' && !total.includes('Calcular');

             if (isLastTab && totalIsValid) {
                  // Além do total e da aba, o WC padrão verifica se um método de pagamento foi selecionado.
                  // Vamos confiar no próprio WooCommerce para habilitar/desabilitar o `#place_order`
                  // com base na seleção do método de pagamento. Nós apenas garantimos a visibilidade
                  // e desabilitamos se o total for inválido ou não estiver na aba de pagamento.
                  // Definimos explicitamente disabled = false aqui, permitindo que o WC o desabilite novamente se não houver método de pagamento selecionado.
                  $placeOrderBtn.prop('disabled', false);
                   log('READY-CHECK Botão "Finalizar Pedido": Permitindo gerenciamento do WC. Total válido na aba Pagamento.');
             } else {
                  $placeOrderBtn.prop('disabled', true);
                   log('READY-CHECK Botão "Finalizar Pedido" DESABILITADO (Não está na aba Pagamento ou total inválido).');
             }
             // NOTA: A lógica final de HABILITAR/DESABILITAR com base no método de pagamento
             // é feita automaticamente pelo script padrão do WooCommerce (`checkout.js`)
             // quando os fragments são atualizados e o formulário de pagamento é renderizado.
             // Nossa função aqui garante que he NÃO seja habilitado nas abas anteriores.
        }

        // Função para mostrar/esconder o indicador de carregamento (C1) (AJUSTADO v3.1.11 -> v3.1.12)
        // Esta é a função que agora controla a visibilidade do overlay principal
        function toggleLoading(show) {
            log(`UI        Chamado toggleLoading(${show ? 'true' : 'false'}).`);
            // exibe/esconde a barrinha (se ainda quiser manter - CSS já a esconde)
            // Utilizamos .css('display', ...) com '!important' no CSS para garantir a ocultação.
            // $('.frete-loading').toggle(show); // Mantido por compatibilidade JS, mas ineficaz devido ao CSS !important

            // exibe/esconde agora o overlay com blur e spinner, forçando display: flex/none
            if (show) {
                 $('.checkout-loading-overlay').css('display', 'flex');
                 log('UI        Overlay de loading exibido (display: flex).');
            } else {
                 $('.checkout-loading-overlay').css('display', 'none');
                 log('UI        Overlay de loading ocultado (display: none).');
            }
        }


        /*** 1. ORGANIZAÇÃO EM ABAS ***/

        // NOTA: A criação das divs das abas (tab-dados-pessoais, tab-cep, etc.) e
        // a movimentação dos campos de faturamento para as abas está correta na versão anterior.
        // Apenas adicionaremos a aba de Pagamento e ajustaremos a movimentação do cupom e pagamento.

        var dadosPessoaisFields = [
            '#billing_cellphone_field',
            '#billing_first_name_field',
            '#billing_last_name_field',
            '#billing_cpf_field',
            '#billing_email_field'
        ];

        var dadosEntregaFields = [
            '#billing_address_1_field',
            '#billing_number_field',
            '#billing_neighborhood_field',
            '#billing_city_field',
            '#billing_state_field',
            '#billing_complemento_field'
        ];

        // Criação das divs das abas (Mantido, apenas adicionado #tab-pagamento)
        if ( $('#tab-dados-pessoais').length === 0 ) {
            $(
                '<div id="tab-dados-pessoais" class="checkout-tab active">' +
                    '<h3>Dados Pessoais</h3>' +
                    '<div class="progress-container">' +
                        '<div class="progress-bar" id="progressBar"></div>' +
                        '<div class="progress-indicator" id="progressIndicator"></div>' +
                    '</div>' +
                    '<p class="frete-info" style="margin-top:6px;">Preencha seus dados corretamente e garanta que seu WhatsApp esteja correto para facilitar nosso contato.</p>' +
                '</div>'
            ).insertBefore('#customer_details .col-1 .woocommerce-billing-fields__field-wrapper');
             log('INIT      Criada a aba #tab-dados-pessoais.');
        }

        if ( $('#tab-cep').length === 0 ) {
            $(
                '<div id="tab-cep" class="checkout-tab">' +
                    '<h3 class="cep-title">Informe seu CEP</h3>' +
                    '<div class="progress-container">' +
                        '<div class="progress-bar" id="progressBarCep"></div>' +
                        '<div class="progress-indicator" id="progressIndicatorCep"></div>' +
                    '</div>' +
                    '<p class="cep-description">Precisamos do seu CEP para calcular o frete e preencher seu endereço automaticamente.</p>' +
                '</div>'
            ).insertAfter('#tab-dados-pessoais');
             log('INIT      Criada a aba #tab-cep.');
        }

        if ( $('#tab-dados-entrega').length === 0 ) {
            $(
                '<div id="tab-dados-entrega" class="checkout-tab">' +
                    '<h3>Endereço</h3>' +
                    '<div class="progress-container">' +
                        '<div class="progress-bar" id="progressBarEndereco"></div>' +
                        '<div class="progress-indicator" id="progressIndicatorEndereco"></div>' +
                    '</div>' +
                    '<p class="frete-info" style="margin-top:8px;">Falta pouco para finalizar seu pedido...</p>' +
                '</div>'
            ).insertAfter('#tab-cep');
            log('INIT      Criada a aba #tab-dados-entrega.');
        }

         // Cria o contêiner da QUARTA aba com título "Resumo e Frete" (Mantido)
         if ( $('#tab-resumo-frete').length === 0 ) {
            $(
                 '<div id="tab-resumo-frete" class="checkout-tab">' +
                     '<h3>Resumo do Pedido e Frete</h3>' +
                     '<div class="progress-container">' +
                         '<div class="progress-bar" id="progressBarResumo"></div>' +
                         '<div class="progress-indicator" id="progressIndicatorResumo"></div>' +
                     '</div>' +
                     // O conteúdo de #order_review será movido para cá pelo JS
                 '</div>'
            ).insertAfter('#tab-dados-entrega'); // Insere após a aba de endereço
            log('INIT      Criada a aba #tab-resumo-frete.');
         }

        // Cria o contêiner da QUINTA aba com título "Pagamento" (Adicionado)
         if ( $('#tab-pagamento').length === 0 ) {
             $(
                 '<div id="tab-pagamento" class="checkout-tab">' +
                     '<h3>Pagamento</h3>' +
                     '<div class="progress-container">' +
                         '<div class="progress-bar" id="progressBarPagamento"></div>' +
                         '<div class="progress-indicator" id="progressIndicatorPagamento"></div>' +
                     '</div>' +
                     // O conteúdo do cupom e #payment será movido para cá pelo JS
                 '</div>'
             ).insertAfter('#tab-resumo-frete'); // Insere após a aba de resumo/frete
             log('INIT      Criada a aba #tab-pagamento.');
         }


        // Move os campos para as abas correspondentes (Mantido)
        $.each(dadosPessoaisFields, function(i, selector) {
            $(selector).appendTo('#tab-dados-pessoais');
             // log(`INIT      Movido ${selector} para #tab-dados-pessoais.`); // Too noisy
        });
        $('#billing_postcode_field').appendTo('#tab-cep');
         // log('INIT      Movido #billing_postcode_field para #tab-cep.'); // Too noisy

        $.each(dadosEntregaFields, function(i, selector) {
            $(selector).appendTo('#tab-dados-entrega');
             // log(`INIT      Movido ${selector} para #tab-dados-entrega.`); // Too noisy
        });


         // *** MOVIMENTAÇÃO CHAVE: Mover as seções para suas abas (AJUSTADO TAREFA 1 v3.1.6/3.1.7) ***
         // Isto deve ser feito no DOM Ready para garantir que os elementos existam.
         // O conteúdo de #order_review, .checkout_coupon e #payment são gerados pelo WooCommerce/templates
         // NÓS NÃO injetamos o HTML novo para cupom/total, apenas movemos os containers originais e injetamos o HTML do total duplicado.

         // Mover a seção de revisão do pedido (produtos, subtotais, frete, total) para a QUARTA aba (Mantido)
         var $orderReview = $('#order_review');
         if ($orderReview.length && $('#tab-resumo-frete').length) {
             log('INIT      Movendo #order_review para dentro de #tab-resumo-frete');
             $orderReview.appendTo('#tab-resumo-frete');
         } else {
              log('AVISO     #order_review ou #tab-resumo-frete não encontrados para mover #order_review!', null, 'INIT');
         }

         // TAREFA 2 (v3.1.2): Mover notas do pedido para a aba Pagamento
         var $orderNotesWrapper = $('.woocommerce-additional-fields__field-wrapper').first(); // Select the first if duplicates exist
         if ($orderNotesWrapper.length && $('#tab-pagamento').length) {
             log('INIT      Movendo .woocommerce-additional-fields__field-wrapper para dentro de #tab-pagamento');
             $orderNotesWrapper.appendTo('#tab-pagamento'); // Move para a aba Pagamento
              $('.woocommerce-additional-fields__field-wrapper:not(:first)').remove(); // Remove duplicates if any
         } else {
             log('AVISO     .woocommerce-additional-fields__field-wrapper ou #tab-pagamento não encontrados para mover notas do pedido!', null, 'INIT');
         }

         // TAREFA 1 (v3.1.7): Mover o bloco .e-coupon-box customizado (se existir)
         var $customCouponBox = $('.e-coupon-box').first(); // Pega o primeiro se houver duplicatas
         var $targetTab = $('#tab-pagamento');

         // TAREFA 1 (v3.1.7): Ocultar elementos nativos via CSS global - JS não precisa movê-los explicitamente
         // Manter apenas a movimentação do .e-coupon-box se ele existe no DOM
         if ($customCouponBox.length && $targetTab.length) {
             log('INIT      Movendo bloco de cupom customizado (.e-coupon-box) para dentro de #tab-pagamento (após order notes)');
             var $orderNotes = $('#tab-pagamento .woocommerce-additional-fields__field-wrapper').first();
              if ($orderNotes.length) {
                $customCouponBox.insertAfter($orderNotes);
              } else {
                 $customCouponBox.appendTo('#tab-pagamento'); // Fallback if order notes not found
              }
             $('.e-coupon-box:not(:first)').remove(); // Remove quaisquer outros .e-coupon-box se existirem
         } else {
              log('AVISO     Bloco .e-coupon-box customizado não encontrado para mover.', null, 'INIT');
              // TAREFA 1 (v3.1.6): Se o custom box não existe, garantir que o cupom padrão esteja na aba
              // Explicitly move standard coupon elements to the payment tab to ensure they are inside
              var $couponAnchorContainer = $('.woocommerce-info.woocommerce-coupon-message').first();
              var $checkoutCoupon = $('.checkout_coupon').first();

              if ($couponAnchorContainer.length && $targetTab.length) {
                   log('INIT      Movendo contêiner link cupom padrão para dentro de #tab-pagamento (e-coupon-box não encontrado)');
                   var $orderNotes = $('#tab-pagamento .woocommerce-additional-fields__field-wrapper').first();
                   if ($orderNotes.length) {
                      $couponAnchorContainer.insertAfter($orderNotes);
                   } else {
                      $couponAnchorContainer.appendTo('#tab-pagamento'); // Fallback
                   }
                    $('.woocommerce-info.woocommerce-coupon-message:not(:first)').remove(); // Remove duplicates
              } else {
                    log('AVISO     Contêiner link cupom padrão não encontrado para mover.', null, 'INIT');
              }
              if ($checkoutCoupon.length && $targetTab.length) {
                   log('INIT      Movendo formulário cupom padrão para dentro de #tab-pagamento (e-coupon-box não encontrado)');
                   var $anchorOrNotes = $('#tab-pagamento .woocommerce-info.woocommerce-coupon-message').first();
                   if ($anchorOrNotes.length) {
                       $checkoutCoupon.insertAfter($anchorOrNotes);
                   } else {
                       $checkoutCoupon.insertAfter('#tab-pagamento .woocommerce-additional-fields__field-wrapper').first(); // Fallback
                   }
                    $('.checkout_coupon:not(:first)').remove(); // Remove duplicates
              } else {
                    log('AVISO     Formulário cupom padrão não encontrado para mover.', null, 'INIT');
              }
         }


         // TAREFA 1 (v3.1.2): Mover a seção de pagamento para a QUINTA aba (Mantido)
         var $payment = $('#payment').first(); // Select the first if duplicates exist
         if ($payment.length && $('#tab-pagamento').length) {
             log('INIT      Movendo #payment para dentro de #tab-pagamento');
             // Move para a aba Pagamento. Posição será após custom coupon box se ele foi encontrado, ou order notes se não.
             var $couponOrNotes = $('#tab-pagamento .e-coupon-box').first().length ? $('#tab-pagamento .e-coupon-box').first() : $('#tab-pagamento .woocommerce-additional-fields__field-wrapper').first();
              if ($couponOrNotes.length) {
                 $payment.insertAfter($couponOrNotes);
              } else {
                 $payment.appendTo('#tab-pagamento'); // Fallback
              }
              $('#payment:not(:first)').remove(); // Remove duplicates if any
         } else {
             log('AVISO     #payment ou #tab-pagamento não encontrados para mover pagamento!', null, 'INIT');
         }

         // TAREFA 1 (v3.1.5): Adicionar o handler de toggle para o cupom customizado (uma única vez) (Mantido)
         $(document).on('click', '.e-show-coupon-form', function (e) {
             e.preventDefault();
             log('ACTION    Toggle custom coupon form');
             $(this).closest('.e-coupon-box').find('.e-coupon-anchor').slideToggle(300, function() {
                 if($(this).is(':visible')) {
                     $(this).find('input#coupon_code').focus();
                 }
             });
         });


        // Garantir que as abas não-ativas estejam inicialmente escondidas (Mantido)
        $('#tab-cep, #tab-dados-entrega, #tab-resumo-frete, #tab-pagamento').removeClass('active').hide();
        $('#tab-dados-pessoais').addClass('active').show();
        log('INIT      Abas inicializadas. #tab-dados-pessoais ativa.');

        // Oculta campos indesejados (mantido)
        $('#billing_persontype_field, .person-type-field').hide();
        $('#billing_country_field, .thwcfd-field-country').hide();
         // Esconder títulos originais (mantido)
         $('#order_review_heading, #payment_heading').hide();


        // Botões de navegação - Adicionar contêiner flex para botões (AJUSTADO PARA TAREFA 2 v3.1.3)
        // A ordem de adição aqui define a ordem em desktop (flex-direction: row por padrão)
        // A ordem em mobile será definida pelo CSS (order: 1/2)

        // Botões na aba Dados Pessoais (Só Avançar) (Mantido)
         if ( $('#tab-dados-pessoais .tab-buttons').length === 0 ) {
            $('#tab-dados-pessoais').append('<div class="tab-buttons"></div>');
            $('#tab-dados-pessoais .tab-buttons').append('<button type="button" id="btn-avancar-para-cep" class="checkout-next-btn">Avançar</button>');
             log('INIT      Adicionado botão "Avançar" na aba Dados Pessoais.');
         }

        // Botões na aba CEP (Voltar e Avançar) (Mantido)
        if ( $('#tab-cep .tab-buttons').length === 0 ) {
            $('#tab-cep').append('<div class="tab-buttons"></div>');
             // Adicionar Avançar primeiro na estrutura HTML
             $('#tab-cep .tab-buttons').append('<button type="button" id="btn-avancar-para-endereco" class="checkout-next-btn">Avançar</button>');
             // Adicionar Voltar segundo na estrutura HTML
             $('#tab-cep .tab-buttons').append('<button type="button" id="btn-voltar-dados" class="checkout-back-btn">Voltar</button>');
            log('INIT      Adicionados botões na aba CEP.');
        }

        // Botões na aba Endereço (Voltar e Avançar) (Mantido, texto do Avançar alterado)
        if ( $('#tab-dados-entrega .tab-buttons').length === 0 ) {
            $('#tab-dados-entrega').append('<div class="tab-buttons"></div>');
             // Adicionar Avançar primeiro
             $('#tab-dados-entrega .tab-buttons').append('<button type="button" id="btn-avancar-para-resumo" class="checkout-next-btn">Avançar para o Resumo</button>');
             // Adicionar Voltar segundo
             $('#tab-dados-entrega .tab-buttons').append('<button type="button" id="btn-voltar-cep" class="checkout-back-btn">Voltar</button>');
            log('INIT      Adicionados botões na aba Endereço.');
        }

         // Botões na aba Resumo e Frete (Voltar e Avançar) (Mantido, texto do Avançar alterado)
         if ( $('#tab-resumo-frete .tab-buttons').length === 0 ) {
            $('#tab-resumo-frete').append('<div class="tab-buttons"></div>');
             // Adicionar Avançar primeiro
             $('#tab-resumo-frete .tab-buttons').append('<button type="button" id="btn-avancar-para-pagamento" class="checkout-next-btn">Avançar para Pagamento</button>');
             // Adicionar Voltar segundo
             $('#tab-resumo-frete .tab-buttons').append('<button type="button" id="btn-voltar-endereco" class="checkout-back-btn">Voltar</button>');
            log('INIT      Adicionados botões na aba Resumo e Frete.');
         }

         // Botões na aba Pagamento (Só Voltar) (AJUSTADO - TAREFA 2 v3.1.3: NÃO MOVE .place-order AQUI)
         if ( $('#tab-pagamento .tab-buttons').length === 0 ) {
            $('#tab-pagamento').append('<div class="tab-buttons"></div>');
             // O .place-order (botão Finalizar pedido) JÁ EXISTE dentro de #payment.
             // Não o movemos mais aqui. Apenas adicionamos o botão Voltar.
             // O CSS gerencia a visibilidade e order em mobile.
             $('#tab-pagamento .tab-buttons').append('<button type="button" id="btn-voltar-resumo" class="checkout-back-btn">Voltar</button>');
            log('INIT      Adicionado botão "Voltar" na aba Pagamento.');
         }


        // Adiciona mensagem de error do WhatsApp abaixo do botão "Voltar" na aba Endereço (Mantido)
        // (Nota: Agora ela fica dentro do container flex .tab-buttons antes do botão Voltar,
        // o que funciona bem com a ordenação flexbox em mobile/desktop)
        if ($('#tab-dados-entrega .tab-buttons .whatsapp-invalido').length === 0) {
             // Inserir dentro do container de botões da aba endereço, antes do botão voltar
             $('<div class="whatsapp-invalido">Número de WhatsApp inválido. Por favor, verifique e corrija.</div>')
                 .insertBefore('#tab-dados-entrega .tab-buttons .checkout-back-btn');
             log('INIT      Adicionada mensagem de WhatsApp inválido na aba Endereço.');
         }


        // BARRA DE PROGresso: Inicializa as barras (5 passos)
        // 0% -> 25% -> 50% -> 75% -> 100%
        var step_percentage = 100 / 4; // Para 5 abas, são 4 transições (0 a 4)


        // Função auxiliar para atualizar a barra de progresso para uma aba específica (Mantido, com 5 abas)
        function updateProgressBar(tabId) {
             let step_index = 0; // 0 para a primeira aba

             switch (tabId) {
                 case 'tab-dados-pessoais':
                     step_index = 0;
                     break;
                 case 'tab-cep':
                     step_index = 1;
                     break;
                 case 'tab-dados-entrega':
                     step_index = 2;
                     break;
                 case 'tab-resumo-frete':
                     step_index = 3;
                     break;
                 case 'tab-pagamento': // Nova aba
                     step_index = 4;
                     break;
             }

             let progressWidth = step_index * step_percentage;

             $('#progressBar, #progressBarCep, #progressBarEndereco, #progressBarResumo, #progressBarPagamento').css('width', progressWidth + '%');
             $('#progressIndicator, #progressIndicatorCep, #progressIndicatorEndereco, #progressIndicatorResumo, #progressIndicatorPagamento').css('left', progressWidth + '%');

             log(`UI        Progresso atualizado para a aba ${tabId}. Width: ${progressWidth.toFixed(2)}%`);
        }


        // Navegação entre abas (Mantido, com adição da 5ª aba)
        // De Dados Pessoais para CEP
        $('#btn-avancar-para-cep').on('click', function(e) {
            e.preventDefault();
            actionStartTime = performance.now(); // Log B1: Início da ação do usuário
            log('ACTION    Clique em "Avançar" (Dados Pessoais -> CEP)');

            // Validação simples dos campos pessoais antes de avançar
            const nomeValido = $('#billing_first_name').val().trim().length > 1;
            const emailValido = $('#billing_email').val().trim().length > 5 && $('#billing_email').val().includes('@'); // Validação básica de email
            const telefoneValido = $('#billing_cellphone').val().replace(/\D/g,'').length >= 10; // Mínimo 10 dígitos para telefone

            if (!nomeValido || !emailValido || !telefoneValido) { // Adicione outras validações aqui
                alert('Por favor, preencha todos os campos obrigatórios (Nome, E-mail, Telefone).');
                log('VALIDAÇÃO Falha na validação dos Dados Pessoais.');
                return;
            }
             log('VALIDAÇÃO Dados Pessoais válidos.');

            $('#tab-dados-pessoais').removeClass('active').hide();
            $('#tab-cep').addClass('active').show();
            updateProgressBar('tab-cep'); // Atualiza barra para a próxima aba
            updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar
        });

        // De CEP para Dados Pessoais (voltar)
        $('#btn-voltar-dados').on('click', function(e) {
            e.preventDefault();
            log('ACTION    Clique em "Voltar" (CEP -> Dados Pessoais)');

            $('#tab-cep').removeClass('active').hide();
            $('#tab-dados-pessoais').addClass('active').show();
            updateProgressBar('tab-dados-pessoais'); // Atualiza barra para a aba anterior
            updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar
        });


        // TAREFA 3.1.15: Listener para avançar a aba DEPOIS que updated_checkout finalizar (Reintroduzido e ajustado)
        // Usamos `.one()` para garantir que ele só seja disparado uma vez por clique no botão "Avançar" do CEP
        // e verificamos se a aba CEP ainda está ativa, para não avançar se o usuário já mudou de aba manualmente
        function handleUpdatedCheckoutForCepAdvance() {
            log('DEBUG     handleUpdatedCheckoutForCepAdvance chamado (triggered by updated_checkout). Verificando aba ativa...');

            // Verifica se a aba CEP ainda está ativa E se o botão estava processando.
            // Isso garante que este listener só aja para o clique de "Avançar" da aba CEP.
            const $cepButton = $('#btn-avancar-para-endereco');
            if (!$('#tab-cep').hasClass('active') || !$cepButton.hasClass('btn-processing')) {
                 log('DEBUG     updated_checkout listener acionado, mas aba CEP não está ativa ou botão não processando. Pulando transição de aba e limpeza específica.');
                 // Garante que o botão seja re-habilitado e o loading global removido
                 // (Embora o listener geral já faça isso se a aba CEP não estiver ativa/processando)
                 $cepButton.removeClass('btn-processing');
                 setProcessingState(false, 'updated_checkout_cep_listener_skip');
                 // updatePlaceOrderButtonState() e renderDuplicateTotal() são chamados no listener geral updated_checkout
                 return; // Sai da função
            }

            log('ACTION    updated_checkout concluído APÓS fluxo do CEP bem-sucedido. Avançando para a aba Endereço...');

            // Executa a transição para a aba "Endereço"
            $('#tab-cep').removeClass('active').hide();
            $('#tab-dados-entrega').addClass('active').show();
            updateProgressBar('tab-dados-entrega'); // Atualiza barra de progresso

            // Remove o estado de processing e re-habilita o botão (este listener é quem faz a limpeza FINAL para o fluxo CEP)
            $cepButton.removeClass('btn-processing');
            setProcessingState(false, 'updated_checkout_cep_success');

            // updatePlaceOrderButtonState() e renderDuplicateTotal() são chamados no listener geral updated_checkout
            // Não precisamos chamá-los aqui novamente, pois updated_checkout já disparou e o listener geral rodou.
        }


        // De CEP para Endereço (consulta o CEP e frete, AGUARDA updated_checkout para avançar) (AJUSTADO v3.1.15)
        $('#btn-avancar-para-endereco').on('click', function(e) {
            e.preventDefault();
            actionStartTime = performance.now(); // Log B1: Início da ação do usuário
            log('ACTION    Clique em "Avançar" (CEP -> Endereço) para consultar CEP/Frete');

            // Hard Guard (Item 3) - Verifica se CEP está preenchido minimamente
            const cepValue = $('#billing_postcode').val().replace(/\D/g,'');

            if (!cepValue || cepValue.length !== 8) {
                log('VALIDAÇÃO CEP inválido ou vazio.', null, 'VALIDAÇÃO');
                $('.cep-erro').show().text('CEP inválido. Informe os 8 dígitos do CEP.');
                 // Remover processamento se falhar na validação inicial
                 $(this).removeClass('btn-processing');
                 setProcessingState(false, 'cep_validation_fail');
                 updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar
                return; // Não avança se CEP inválido
            } else {
                $('.cep-erro').hide(); // Esconde o error de CEP se a validação passou
                 log('VALIDAÇÃO CEP válido.');
            }

            var $this = $(this);

            // Impedir múltiplos cliques
            if ($this.hasClass('btn-processing')) {
                log('ACTION    Botão CEP: Já processando, ignorando clique');
                return;
            }

            // Adicionar estado de processamento ANTES da consulta
            $this.addClass('btn-processing');
            setProcessingState(true, 'cep_button_click');
            $('#tab-cep').addClass('cep-loading'); // Adiciona indicador específico do CEP


            // TAREFA 3.1.15: Chamar a cadeia de promises (webhook -> processamento frontend -> armazenamento backend)
            consultarCepEFrete().then(function(success_chain) {
                // Esta promise resolve com TRUE se toda a cadeia (webhook call -> processarDadosEnderecoFrete -> armazenarDadosNoServidor) foi bem-sucedida.
                // Resolve com FALSE se qualquer passo da cadeia (incluindo erros de requisição ou falha no processamento/armazenamento) falhou.
                log(`DEBUG     Promise chain (webhook->process->store) resolvida. Success: ${success_chain}`);

                if (success_chain) {
                    // TAREFA 3.1.15: AGORA que nosso fluxo customizado terminou (webhook consultado, frontend processado, backend armazenado)...
                    // ...REGISTRAMOS o listener para o PRÓXIMO updated_checkout...
                    // Este listener será o responsável por avançar a aba e limpar o estado,
                    // GARANTINDO que isso aconteça SÓ DEPOIS que o WC atualizar o DOM.
                    log('DEBUG     Fluxo customizado CEP bem-sucedido. Registrando .one("updated_checkout") para avanço e disparando update_checkout do WC.');
                    $(document.body).one('updated_checkout', handleUpdatedCheckoutForCepAdvance);

                    // ...e DISPARAMOS o evento padrão do WC para que ele atualize os fragments (métodos de frete, totais, etc.)
                    // Este trigger resultará em uma requisição AJAX do WC, aplicação dos fragments,
                    // e então no disparo do evento 'updated_checkout'.
                    // O listener '.one()' registrado acima vai capturar ESSE evento.
                    $(document.body).trigger('update_checkout');

                    // NOTA: NÃO removemos o estado de processamento NEM avançamos a aba AQUI.
                    // Isso acontece NO listener 'updated_checkout' registrado acima.

                } else {
                    // O fluxo customizado falhou em algum ponto (webhook erro, frontend process falhou, backend store falhou).
                    log('DEBUG     Fluxo customizado (webhook->process->store) falhou. Não disparando update_checkout nem avançando aba.', null, 'CHAIN_FAIL');
                    // Limpa estado de processamento diretamente, pois updated_checkout não será disparado por este fluxo de falha.
                    $this.removeClass('btn-processing');
                    setProcessingState(false, 'cep_chain_fail');
                    updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar (provavelmente desabilitado)
                     renderDuplicateTotal(); // Garante que o total duplicado reflita o estado atual (sem frete)
                    // A classe cep-loading é removida no complete da requisição AJAX dentro de consultarCepEFrete().
                }
            }).catch(function(error_chain) {
                 // Catch-all for unexpected errors in the promise chain itself (e.g., JS error)
                log('ERROR     Erro inesperado na promise chain (webhook->process->store).', error_chain, 'CHAIN_ERROR');
                 // Limpa estado de processamento
                 $this.removeClass('btn-processing');
                 setProcessingState(false, 'cep_chain_error');
                  updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar
                 // cep-loading removido no complete do AJAX do webhook
            });

            // A lógica no `complete` da requisição AJAX interna de `consultarCepEFrete`
            // garante a remoção da classe `cep-loading` e o reset da flag `consultaEmAndamento`.

        });

        // De Endereço para CEP (voltar) (Mantido)
        $('#btn-voltar-cep').on('click', function(e) {
            e.preventDefault();
            log('ACTION    Clique em "Voltar" (Endereço -> CEP)');

            $('#tab-dados-entrega').removeClass('active').hide();
            $('#tab-cep').addClass('active').show();
            updateProgressBar('tab-cep'); // Atualiza barra para a aba anterior
            updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar
        });

        // De Endereço para Resumo e Frete (Avançar para o Resumo) (Mantido, validação aprimorada)
         $('#btn-avancar-para-resumo').on('click', function(e) {
             e.preventDefault();
             actionStartTime = performance.now(); // Log B1: Início da ação do usuário
             log('ACTION    Clique em "Avançar para o Resumo" (Endereço -> Resumo/Frete)');

             // Validação simples dos campos de endereço antes de avançar
             const endereco1Valido = $('#billing_address_1').val().trim().length > 2;
             const bairroValido = $('#billing_neighborhood').val().trim().length > 1;
             const cidadeValida = $('#billing_city').val().trim().length > 1;
             // CORREÇÃO v3.1.1: Use .val() apenas uma vez
             const estadoValido = $('#billing_state').val() !== ''; // Verifica se o estado foi selecionado
             const numeroValido = $('#billing_number').val().trim().length > 0; // Verifica se o número foi preenchido

             if (!endereco1Valido || !bairroValido || !cidadeValida || !estadoValido || !numeroValido) {
                 alert('Por favor, preencha todos os campos obrigatórios de Endereço.');
                 log('VALIDAÇÃO Falha na validação dos campos de Endereço.');
                 return;
             }
             log('VALIDAÇÃO Campos de Endereço válidos.');


             $('#tab-dados-entrega').removeClass('active').hide();
             $('#tab-resumo-frete').addClass('active').show();
             updateProgressBar('tab-resumo-frete'); // Atualiza barra para a próxima aba
             updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar

             // Opcional: Disparar update_checkout aqui para garantir que o resumo esteja atualizado
             // caso o usuário tenha alterado o endereço manualmente.
             // Isso pode ser útil, mas pode ter efeitos colaterais dependendo de outros plugins.
             // Mantemos o foco principal no gatilho pela consulta de CEP.
             // $(document.body).trigger('update_checkout');
         });

         // De Resumo e Frete para Endereço (Voltar) (Mantido)
         $('#btn-voltar-endereco').on('click', function(e) {
             e.preventDefault();
             log('ACTION    Clique em "Voltar" (Resumo/Frete -> Endereço)');

             $('#tab-resumo-frete').removeClass('active').hide();
             $('#tab-dados-entrega').addClass('active').show();
             updateProgressBar('tab-dados-entrega'); // Atualiza barra para a aba anterior
             updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar
         });

         // De Resumo e Frete para Pagamento (Avançar para Pagamento) (Mantido)
         $('#btn-avancar-para-pagamento').on('click', function(e) {
             e.preventDefault();
             actionStartTime = performance.now(); // Log B1: Início da ação do usuário
             log('ACTION    Clique em "Avançar para Pagamento" (Resumo/Frete -> Pagamento)');

             // TODO: Opcional: Adicionar validação para selecionar método de frete aqui se for obrigatório.
             // Por enquanto, o WC já vai lidar com a seleção de frete na aba final.

             $('#tab-resumo-frete').removeClass('active').hide();
             $('#tab-pagamento').addClass('active').show();
             updateProgressBar('tab-pagamento'); // Atualiza barra para a última aba
             updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar
         });

         // De Pagamento para Resumo e Frete (Voltar) (Adicionado)
         $('#btn-voltar-resumo').on('click', function(e) {
             e.preventDefault();
             log('ACTION    Clique em "Voltar" (Pagamento -> Resumo/Frete)');

             $('#tab-pagamento').removeClass('active').hide();
             $('#tab-resumo-frete').addClass('active').show();
             updateProgressBar('tab-resumo-frete'); // Atualiza barra para a aba anterior
             updatePlaceOrderButtonState(); // Atualiza estado do botão finalizar
         });


        /*** 4. FUNÇÕES PARA CONSULTA E ARMAZENAMENTO DE FRETE (AJUSTADO TAREFA 1 v3.1.3 / 3.1.5 / 3.1.6) ***/

        // Função para armazenar dados no servidor via AJAX usando WC()->session (A3, Item 2.1) (Mantido, ajustado para resolver Promise)
        function armazenarDadosNoServidor(data_to_save) {
            currentPhase = 'STORE_OUT';
            log('STORE_OUT Chamando store_webhook_shipping para armazenar dados...');
            ajaxStoreStartTime = performance.now(); // Log B1

            // Retorna uma promise que resolve com true (sucesso no backend) ou false (falha no backend)
            return new Promise(function(resolve) {
                 $.ajax({
                    url: ajaxUrl, // Usar admin-ajax.php
                    type: 'POST',
                    dataType: 'json', // Espera JSON como resposta
                    data: {
                        action: 'store_webhook_shipping',
                        security: nonce,
                        shipping_data: JSON.stringify(data_to_save)
                    },
                    success: function(response) {
                         ajaxStoreEndTime = performance.now(); // Log B1
                         const deltaAjax = (ajaxStoreEndTime - ajaxStoreStartTime).toFixed(0);
                         currentPhase = 'STORE_IN';
                         log(`STORE_IN  store_webhook_shipping success (HTTP 200). Δajax=${deltaAjax}ms.`, response);

                         // Item 2: Tratar a resposta robustamente
                         // Assumir success=true se a chave não vier na resposta ou se a resposta for vazia (caso onde WC não retorna fragments)
                         const successFlag = (typeof response.success === 'undefined') ? true : response.success;
                         // Ensure response.data is an array or object if success and no data is present
                         const responseData = response.data || {};
                         const fragments = responseData.fragments || responseData; // Handles both potential formats

                        if (successFlag) { // Consider backend storage successful if successFlag is true
                            log('APPLY_FRAG store_webhook_shipping reportou sucesso. Aplicando fragments (se houver)...');

                             // Fragment application logic remains the same as it correctly targets elements within tabs
                             // Apply fragments directly (A3)
                             if (fragments && typeof fragments === 'object' && Object.keys(fragments).length > 0) {
                                 log('APPLY_FRAG Aplicando fragments do SWHS...');
                                 // Log do total antes (Item 5 Front-end) - Buscar total dentro de #order_review (agora na aba 4)
                                 const beforeTotal = $('#tab-resumo-frete #order_review .order-total .amount, #tab-resumo-frete #order_review .order_details .amount').text().trim();
                                 log(`DELTA     Total antes da aplicação de fragments (SWHS): ${beforeTotal}`, null,'TOTAL');


                                 $.each(fragments, function(key, value) {
                                     // Modificado para garantir que os elementos corretos nas abas sejam atualizados
                                     if (key === '#order_review') {
                                          $('#tab-resumo-frete #order_review').replaceWith(value);
                                          log('APPLY_FRAG #order_review fragment aplicado dentro da aba 4.');
                                     } else if (key === '#payment') { // Fragmento de pagamento
                                          $('#tab-pagamento #payment').replaceWith(value);
                                           log('APPLY_FRAG #payment fragment aplicado dentro da aba 5.');
                                     } else if (key === '.checkout_coupon') { // Fragmento do cupom
                                         // Não substituímos o .checkout_coupon, apenas garantimos que ele está lá e o CSS o esconde
                                          log('APPLY_FRAG Ignorando fragmento .checkout_coupon (gerenciado por CSS/movimentação).');
                                     } else if (key === '.woocommerce-info.woocommerce-coupon-message') { // Fragmento da mensagem/link do cupom
                                         // Não substituímos, apenas garantimos que está lá e o CSS o esconde
                                          log('APPLY_FRAG Ignorando fragmento .woocommerce-info.woocommerce-coupon-message (gerenciado por CSS/movimentação).');
                                     } else if (key === '#order_review_heading' || key === '#payment_heading') {
                                          // Ignora os títulos originais que estão escondidos
                                          log(`APPLY_FRAG Ignorando fragmento de título: "${key}".`);
                                     }
                                      // Fragmento potencial para campos adicionais/notas (pode vir aqui ou não, dependendo do tema/plugin)
                                     else if (key === '.woocommerce-additional-fields' || key === '.woocommerce-additional-fields__field-wrapper') {
                                         // Substitui o wrapper se ele vier no fragment
                                          // v3.1.16: Target the element *within* the tab
                                          $('#tab-pagamento .woocommerce-additional-fields__field-wrapper').replaceWith(value);
                                          log(`APPLY_FRAG Fragment "${key}" (Order Notes) aplicado dentro da aba 5. Re-posicionando...`);
                                         // O re-posicionamento final será feito após o loop
                                     }
                                     else {
                                          // Aplica outros fragments no corpo do documento (ex: mensagens, exceções)
                                          // Exclui fragmentos que são movidos explicitamente para as abas
                                          // Nota: .woocommerce-checkout-review-order-table é a tabela #order_review, .woocommerce-checkout-payment é #payment, .woocommerce-form-coupon é .checkout_coupon
                                          // v3.1.16: Added more specific selectors to ignore
                                          if (key !== '.woocommerce-checkout-review-order-table' && key !== '.woocommerce-checkout-payment' && key !== '.woocommerce-form-coupon' && key !== '.woocommerce-additional-fields' && key !== '.woocommerce-additional-fields__field-wrapper' && key !== '#shipping_method' && key !== '.woocommerce-shipping-methods') {
                                            $(key).replaceWith(value);
                                            log(`APPLY_FRAG Fragment "${key}" aplicado.`, null, 'APPLY_FRAG');
                                          } else {
                                               log(`APPLY_FRAG Ignorando fragmento que já está na aba ou gerenciado separadamente: "${key}".`);
                                          }
                                     }
                                 });

                                  // TAREFA 1 (v3.1.6/3.1.7) + TAREFA 3.1.15 + v3.1.16: Re-posicionar elementos após fragments
                                 // Re-seleciona os elementos *dentro* da aba de pagamento após a aplicação dos fragments
                                 // Garante a ordem: Order Notes -> e-coupon-box -> Payment -> Standard Coupon Form
                                  var $paymentTab = $('#tab-pagamento');
                                  var $paymentSectionAfterFrag = $paymentTab.find('#payment').first();
                                  var $couponFormAfterFrag = $paymentTab.find('.checkout_coupon').first(); // Standard form (kept hidden)
                                  var $orderNotesAfterFrag = $paymentTab.find('.woocommerce-additional-fields__field-wrapper').first();
                                  var $customCouponBoxAfterFrag = $paymentTab.find('.e-coupon-box').first(); // Custom coupon box

                                  log('DEBUG     Iniciando re-ordenação de elementos dentro da aba Pagamento após fragmentos...');

                                  // Remove quaisquer duplicatas que possam ter sido adicionadas
                                  $paymentTab.find('.woocommerce-additional-fields__field-wrapper:not(:first)').remove();
                                  $paymentTab.find('.checkout_coupon:not(:first)').remove();
                                  $paymentTab.find('#payment:not(:first)').remove();
                                  $paymentTab.find('.e-coupon-box:not(:first)').remove();
                                   log('DEBUG     Removidas duplicatas encontradas dentro da aba Pagamento.');


                                  // Re-anexar elementos se eles sumiram após fragments (menos comum, mas fallback)
                                  // e selecionar a referência correta novamente
                                  if ($orderNotesAfterFrag.length === 0) { $orderNotesAfterFrag = $('.woocommerce-additional-fields__field-wrapper').first(); if ($orderNotesAfterFrag.length) $orderNotesAfterFrag.appendTo($paymentTab); }
                                  if ($couponFormAfterFrag.length === 0) { $couponFormAfterFrag = $('.checkout_coupon').first(); if ($couponFormAfterFrag.length) $couponFormAfterFrag.appendTo($paymentTab); }
                                  if ($paymentSectionAfterFrag.length === 0) { $paymentSectionAfterFrag = $('#payment').first(); if ($paymentSectionAfterFrag.length) $paymentSectionAfterFrag.appendTo($paymentTab); }
                                  if ($customCouponBoxAfterFrag.length === 0) { $customCouponBoxAfterFrag = $('.e-coupon-box').first(); if ($customCouponBoxAfterFrag.length) $customCouponBoxAfterFrag.appendTo($paymentTab); }

                                   log('DEBUG     Re-anexados elementos que podem ter sumido: orderNotes=' + $orderNotesAfterFrag.length + ', couponForm=' + $couponFormAfterFrag.length + ', payment=' + $paymentSectionAfterFrag.length + ', customCoupon=' + $customCouponBoxAfterFrag.length);


                                  // Re-selecionar após re-anexar, se necessário
                                  $orderNotesAfterFrag = $paymentTab.find('.woocommerce-additional-fields__field-wrapper').first();
                                  $couponFormAfterFrag = $paymentTab.find('.checkout_coupon').first();
                                  $paymentSectionAfterFrag = $paymentTab.find('#payment').first();
                                  $customCouponBoxAfterFrag = $paymentTab.find('.e-coupon-box').first();


                                  // Ensure final order: Order Notes -> e-coupon-box -> Payment -> Standard Coupon Form
                                  var $currentAnchor = null;

                                   if ($orderNotesAfterFrag.length) {
                                       if ($currentAnchor) $orderNotesAfterFrag.insertAfter($currentAnchor); else $orderNotesAfterFrag.prependTo($paymentTab);
                                       $currentAnchor = $orderNotesAfterFrag;
                                        log('DEBUG     Posicionado Order Notes.');
                                   }

                                  if ($customCouponBoxAfterFrag.length) {
                                      if ($currentAnchor) $customCouponBoxAfterFrag.insertAfter($currentAnchor); else $customCouponBoxAfterFrag.prependTo($paymentTab);
                                      $currentAnchor = $customCouponBoxAfterFrag;
                                       log('DEBUG     Posicionado custom coupon box.');
                                  }

                                   if ($paymentSectionAfterFrag.length) {
                                       if ($currentAnchor) $paymentSectionAfterFrag.insertAfter($currentAnchor); else $paymentSectionAfterFrag.prependTo($paymentTab);
                                       $currentAnchor = $paymentSectionAfterFrag;
                                        log('DEBUG     Posicionado #payment.');
                                   }


                                  // Ensure standard coupon form is after payment (it's hidden anyway)
                                  if ($couponFormAfterFrag.length) {
                                      if ($currentAnchor) $couponFormAfterFrag.insertAfter($currentAnchor); else $couponFormAfterFrag.prependTo($paymentTab);
                                      // $currentAnchor = $couponFormAfterFrag; // No need to update anchor after the last element
                                       log('DEBUG     Posicionado standard coupon form.');
                                  }

                                   log('DEBUG     Finalizada re-ordenação de elementos dentro da aba Pagamento.');


                                  // TAREFA 2 (v3.1.6/3.1.7) + 3.1.15: Render and Update Duplicate Total
                                  renderDuplicateTotal();

                                  fragmentsAppliedTime = performance.now(); // Log B1
                                  log(`UPDATE_DONE Fragments aplicados (via SWHS AJAX).`);

                                  // Log do total depois (Item 5 Front-end) - Buscar total dentro de #order_review (agora na aba 4)
                                  const afterTotal = $('#tab-resumo-frete #order_review .order-total .amount, #tab-resumo-frete #order_review .order_details .amount').text().trim();
                                  log(`DELTA     Total depois da aplicação de fragments (SWHS): ${afterTotal}`, null,'TOTAL');

                              } else {
                                  log('DEBUG     store_webhook_shipping success, but no fragments returned.');
                              }

                              // Disparar updated_checkout para compatibilidade com outros scripts (passarelas, etc.)
                              // e para sinalizar a conclusão da atualização do DOM após aplicação dos fragments.
                              // NOTA: Este trigger NÃO deve causar nova chamada AJAX do WC, pois os fragments já foram aplicados.
                              // Ele serve apenas para notificar outros listeners que o DOM do checkout foi atualizado.
                              // O listener .one() E o listener geral 'on' para updated_checkout vão capturar este evento.
                              log('DEBUG     Disparando updated_checkout após aplicar fragments (via SWHS AJAX).');
                              $(document.body).trigger('updated_checkout'); // Não passar args opcionais por padrão

                             resolve(true); // Reporta sucesso na comunicação com o backend (mesmo que sem fragments)

                         } else { // successFlag === false or invalid response structure from backend
                              log('STORE_IN  Resposta de store_webhook_shipping com success=false ou estrutura inválida. Mensagem:', responseData?.message || 'Sem mensagem.');
                              // Exibir mensagem de error específica se fornecida pelo backend
                             if (responseData?.message) {
                                 $('.cep-erro').show().text('Erro ao salvar dados do frete: ' + responseData.message);
                             } else {
                                 $('.cep-erro').show().text('Erro desconhecido ao salvar dados do frete. Tente novamente.');
                             }
                             resolve(false);  // Reporta falha na comunicação com o backend
                         }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                         ajaxStoreEndTime = performance.now(); // Log B1
                         const deltaAjax = (ajaxStoreEndTime - ajaxStoreStartTime).toFixed(0);
                         currentPhase = 'STORE_IN';
                         log(`AJAX_ERROR  store_webhook_shipping error (HTTP ${jqXHR.status}). Δajax=${deltaAjax}ms.`, {
                            status: jqXHR.status,
                            textStatus: textStatus,
                            error: errorThrown,
                            responseText: jqXHR.responseText
                        }, 'STORE_FAIL'); // Added phase indicating failure

                        $('.cep-erro').show().text('Erro ao salvar dados do frete. Tente novamente.');
                        resolve(false); // Reporta falha na comunicação com o backend
                    },
                    complete: function() {
                         currentPhase = 'STORE_DONE';
                        log('STORE_DONE store_webhook_shipping request complete.');
                    }
                 });
            });
        }

        // Função para remover máscara do número de WhatsApp (Mantido)
        function removerMascaraWhatsApp(numero) {
            return (numero || '').replace(/\D/g, '');
        }

        // Funções formatarPreco, normalizarRespostaAPI, formatarPrazo (Mantido) - Não estão no código JS original, assumindo que existem ou não são usadas/necessárias aqui.

        // Função para normalizar a resposta da API (Mantido)
        function normalizarRespostaAPI(data) {
            log('DEBUG     Normalizando resposta da API bruta', data);

            // Se a resposta for um array, pegamos o primeiro elemento (ou null se vazio)
            if (Array.isArray(data)) {
                log('DEBUG     Resposta é um array, usando primeiro elemento');
                return data.length > 0 ? data[0] : null;
            }

             // Se a resposta for um objeto, retornamos como está
            if (typeof data === 'object' && data !== null) {
                 return data;
            }

            // Caso contrário, retorna null
            log('DEBUG     Resposta da API não é array nem objeto válido', null, 'DEBUG');
            return null;
        }

        // Função para formatar o prazo de entrega (Mantido) - Não está no código JS original, assumindo que existe ou não é usada/necessária aqui.
        // function formatarPrazo(prazo) { /* ... */ }


        // Função para processar dados de endereço e frete recebidos do webhook (AJUSTADO - TAREFA 1 v3.1.3 / 3.1.5)
        function processarDadosEnderecoFrete(dados) {
            log('DEBUG     Processando dados de endereço e frete recebidos do webhook...');

            // Se a resposta for completamente vazia ou nula, limpamos todos os campos de endereço.
            if (!dados) {
                log('DEBUG     Dados vazios ou inválidos para processamento frontend. Limpando todos os campos de endereço.');
                 $('#billing_address_1, #billing_number, #billing_neighborhood, #billing_city, #billing_state, #billing_complemento').val('').trigger('change');
                $('.cep-erro').show().text('Resposta do CEP inválida ou vazia. Verifique se o CEP existe.');
                freteData = null; // Garante que freteData esteja nulo
                return false; // Processamento falhou (sem dados)
            }

            // Normalizar a resposta para um formato padronizado
            dados = normalizarRespostaAPI(dados);

             if (!dados) {
                 log('DEBUG     Dados normalizados resultaram em vazio ou inválido. Limpando todos os campos de endereço.');
                  // Se a normalização falhou, limpamos todos os campos de endereço
                 $('#billing_address_1, #billing_number, #billing_neighborhood, #billing_city, #billing_state, #billing_complemento').val('').trigger('change');
                 $('.cep-erro').show().text('Resposta do CEP inválida após normalização. Verifique se o CEP existe.');
                 freteData = null; // Garante que freteData esteja nulo
                 return false; // Processamento falhou (normalização)
             }

            // Debug do objeto de dados normalizado
            log("DEBUG     Dados normalizados do webhook para processamento:", dados);

            // Armazenar os dados normalizados para uso posterior (store_webhook_shipping e updated_checkout)
            freteData = dados; // Armazena os dados para serem enviados ao backend

            try {
                let anyAddressFieldFilledByWebhook = false;
                // TAREFA 1 (v3.1.3 / 3.1.5): Iterar sobre os campos de endereço e preencher/limpar individualmente
                 const addressFieldsMapping = {
                     'logradouro': '#billing_address_1',
                     'numero': '#billing_number',
                     'bairro': '#billing_neighborhood',
                     'localidade': '#billing_city',
                     'uf': '#billing_state',
                     'complemento': '#billing_complemento'
                 };

                log('DEBUG     Preenchendo/Limpando campos de endereço individualmente...');

                 Object.keys(addressFieldsMapping).forEach(function(apiField) {
                     const formFieldSelector = addressFieldsMapping[apiField];
                     const $formField = $(formFieldSelector);

                     if ($formField.length) { // Check if the field exists in the DOM
                         // Verifica se o campo existe na resposta e não é uma string vazia ou null
                         const fieldValue = dados[apiField];

                         if (fieldValue !== undefined && fieldValue !== null && fieldValue !== '') {
                             // Preenche o campo se o valor for válido e diferente do atual (otimização)
                             if ($formField.val() !== fieldValue) {
                                 $formField.val(fieldValue).trigger('change');
                                 log(`DEBUG     Campo ${formFieldSelector} preenchido/atualizado com "${fieldValue}"`);
                             } else {
                                  log(`DEBUG     Campo ${formFieldSelector} já preenchido corretamente ("${fieldValue}")`);
                                  // Se o campo já estava preenchido e a resposta o confirma, ainda consideramos preenchido pelo webhook (indirectly)
                             }
                             // Mark as filled if logradouro, city, state, neighborhood, or number are present in response and not empty
                             if (['logradouro', 'localidade', 'uf', 'bairro', 'numero'].includes(apiField) && fieldValue && String(fieldValue).trim() !== '') {
                                anyAddressFieldFilledByWebhook = true; // Mark that at least one key address field was provided
                             }

                         } else {
                             // TAREFA 1 (v3.1.5): Limpa o campo APENAS se o valor for vazio, nulo, ou não existir na resposta
                             // Preserva valores preenchidos manualmente se o campo não veio na resposta
                             if (fieldValue === '' || fieldValue === null || fieldValue === undefined) {
                                  if ($formField.val() !== '') {
                                      $formField.val('').trigger('change');
                                       log(`DEBUG     Campo ${formFieldSelector} vazio/ausente na resposta, limpando campo existente.`);
                                  } else {
                                       log(`DEBUG     Campo ${formFieldSelector} já vazio e ausente na resposta.`);
                                  }
                             } else {
                                  // Should not happen based on check above, but for safety
                                  log(`DEBUG     Campo ${formFieldSelector} tem valor "${fieldValue}" na resposta, mas não foi preenchido (check logic).`);
                             }
                         }
                     } else {
                         log(`DEBUG     Campo ${formFieldSelector} não encontrado no DOM.`);
                     }
                 });


                // Atualizar a cidade exibida na seção de frete, se aplicável (varia conforme tema)
                // Procura por elementos com as classes dentro do #order_review (onde a tabela foi movida para aba 4)
                // Usa os valores preenchidos ou limpos nos campos do formulário como fallback, caso a resposta da API seja parcial ou ausente
                 const currentCity = $('#billing_city').val() || '';
                 const currentState = $('#billing_state').val() || '';
                 const $locationDisplay = $('#tab-resumo-frete #order_review .location-city.billing_city_field, #tab-resumo-frete #order_review .woocommerce-shipping-destination .location');

                 if ($locationDisplay.length) {
                     // Only update if the values are present
                     if (currentCity || currentState) {
                         $locationDisplay.text(`${currentCity}, ${currentState}`);
                         log('DEBUG     Atualizado display de cidade/estado no resumo.');
                     } else {
                         // Clear display if both are empty
                          $locationDisplay.text('');
                          log('DEBUG     Limpado display de cidade/estado no resumo.');
                     }
                 } else {
                     log('DEBUG     Elemento de display de cidade/estado no resumo não encontrado.');
                 }


                // Foco no próximo campo (número) se o logradouro foi preenchido automaticamente E o número está vazio
                if (anyAddressFieldFilledByWebhook && $('#billing_number').val().trim() === '') {
                     $('#billing_number').focus();
                     log('DEBUG     Logradouro ou outro campo chave preenchido automaticamente, focando no campo número.');
                } else if (anyAddressFieldFilledByWebhook) {
                     log('DEBUG     Logradouro e/ou outros campos chave já preenchidos.');
                     // Maybe focus on neighborhood or next empty field? Sticking to number for now.
                } else if ($('#billing_address_1').val().trim() === '') {
                    // If logradouro was not filled by webhook and is still empty, focus on it
                    $('#billing_address_1').focus();
                    log('DEBUG     Logradouro não preenchido, focando para preenchimento manual.');
                }


                // Exibir error de CEP se a consulta não preencheu nenhum campo chave de endereço
                 // Lógica de error: Mostrar error se a consulta retornou algo, mas NÃO preencheu nenhum dos campos chave esperados (logradouro, numero, bairro, cidade, uf).
                 // Se o usuário já preencheu, não mostrar error.
                 const mandatoryFieldsEmptyAfterProcess = !$('#billing_address_1').val().trim() || !$('#billing_neighborhood').val().trim() || !$('#billing_city').val().trim() || !$('#billing_state').val().trim() || !$('#billing_number').val().trim();

                 if (!anyAddressFieldFilledByWebhook && mandatoryFieldsEmptyAfterProcess) {
                      log('DEBUG     Nenhum campo chave preenchido pela resposta do webhook E campos obrigatórios ainda vazios. Mostrando error e solicitando preenchimento manual.');
                     $('.cep-erro').show().text('CEP encontrado, mas dados de endereço incompletos. Por favor, preencha o endereço manualmente.');
                 } else {
                     // Se algum campo chave foi preenchido OU se os campos obrigatórios JÁ estavam preenchidos manualmente, esconder error
                      log('DEBUG     Algum campo chave preenchido pelo webhook OU campos obrigatórios já preenchidos manualmente. Escondendo error.');
                     $('.cep-erro').hide(); // Esconder error se o logradouro foi preenchido
                 }


                // 2. Verificar a validade do WhatsApp
                if (dados.whatsappValido === false) {
                    $('.whatsapp-invalido').show();
                    log('DEBUG     WhatsApp inválido detectado na resposta do webhook');
                } else {
                    $('.whatsapp-invalido').hide();
                    log('DEBUG     WhatsApp válido ou não informado na resposta do webhook');
                }

                // 3. Verificar se há dados de frete disponíveis (somente valores válidos > 0)
                var temDadosFreteValidos = (dados.fretePACMini && typeof dados.fretePACMini.valor !== 'undefined' && parseFloat(dados.fretePACMini.valor) > 0) ||
                                    (dados.freteSedex && typeof dados.freteSedex.valor !== 'undefined' && parseFloat(dados.freteSedex.valor) > 0) ||
                                    (dados.freteMotoboy && typeof dados.freteMotoboy.valor !== 'undefined' && parseFloat(dados.freteMotoboy.valor) > 0);

                 if (temDadosFreteValidos) {
                      log('DEBUG     Dados de frete válidos (> 0) encontrados na resposta do webhook.');
                 } else {
                      log('DEBUG     Nenhum dado de frete válido (> 0) encontrado na resposta do webhook.');
                       // Se não tem frete válido, e campos obrigatórios de endereço não foram preenchidos automaticamente E estão vazios...
                       // already handled by the error logic above
                 }


                // O processamento frontend é considerado "bem-sucedido" se conseguimos preencher *algum* campo chave de endereço
                // OU se há *algum* dado de frete válido retornado.
                var processadoComSucessoNoFrontend = anyAddressFieldFilledByWebhook || temDadosFreteValidos;
                 log(`DEBUG     Processamento frontend concluído. Sucesso no frontend: ${processadoComSucessoNoFrontend}.`);


                // Retornamos esta flag. A chamada a armazenarDadosNoServidor será feita SE esta flag for TRUE
                // (e se houver freteData para enviar), e o resultado de store_webhook_shipping
                // determinará o sucesso final da cadeia.
                return processadoComSucessoNoFrontend;


            } catch (e) {
                log('ERROR     Erro fatal ao processar dados do webhook no frontend:', e.message, 'ERROR');
                console.error(e);
                 // Em caso de error fatal, limpamos todos os campos de endereço
                 $('#billing_address_1, #billing_number, #billing_neighborhood, #billing_city, #billing_state, #billing_complemento').val('').trigger('change');
                $('.cep-erro').show().text('Ocorreu um error ao processar os dados do CEP. Tente novamente.');
                 freteData = null; // Garante que freteData esteja nulo
                return false; // Processamento falhou (error)
            }
        }

        // Função para consultar o CEP e o frete, processar frontend e armazenar backend.
        // Esta função agora retorna uma Promise que resolve quando TODO O FLUXO (webhook + process + store) termina.
        // AJUSTADO (v3.1.12): Incluindo nome no payload para o webhook
        // AJUSTADO (v3.1.15): Resolve/Reject baseados no sucesso/falha do store_webhook_shipping promise
        function consultarCepEFrete() {
            return new Promise(function(resolve, reject) { // consultarCepEFrete returns a promise
                var cep = $('#billing_postcode').val().replace(/\D/g, '');

                // Validation and initial processing state are handled in the click handler.
                // Check consultaEmAndamento here to prevent concurrent calls if needed.
                 if (consultaEmAndamento) {
                     log('DEBUG     Consulta já em andamento (interno consultarCepEFrete), ignorando.');
                     resolve(false); // Resolve immediately as false if already running
                     return;
                 }
                 consultaEmAndamento = true; // Set flag here before AJAX chain starts

                freteData = null; // Limpa dados antigos

                var whatsapp = removerMascaraWhatsApp($('#billing_cellphone').val());
                // NOVO (v3.1.12): Captura o nome completo
                var firstName = $('#billing_first_name').val().trim();
                var lastName = $('#billing_last_name').val().trim();
                var nomeCompleto = (firstName + ' ' + lastName).trim();


                currentPhase = 'WEBHOOK_OUT';
                log('WEBHOOK_OUT Iniciando consulta de endereço e frete via webhook...', { cep: cep, whatsapp: whatsapp, nome: nomeCompleto });

                ajaxWebhookStartTime = performance.now();

                // NOVO (v3.1.12): Inclui o nome no payload JSON para o webhook
                var payload = {
                   cep: cep,
                   evento: 'consultaEnderecoFrete',
                   whatsapp: whatsapp,
                   nome: nomeCompleto // Inclui o nome no payload
                };

                $.ajax({
                    url: webhookUrl,
                    type: 'POST', // Usar POST para enviar dados no corpo
                    contentType: 'application/json', // Especifica o tipo de conteúdo JSON
                    dataType: 'json', // Espera JSON como resposta
                    data: JSON.stringify(payload), // Envia o payload como string JSON
                    success: function(data) {
                        ajaxWebhookEndTime = performance.now();
                        const deltaAjaxWebhook = (ajaxWebhookEndTime - ajaxWebhookStartTime).toFixed(0);
                        currentPhase = 'WEBHOOK_IN';
                        log(`WEBHOOK_IN  Resposta do webhook recebida (HTTP 200). Δajax=${deltaAjaxWebhook}ms.`, data);

                        // Processa os dados recebidos. processarDadosEnderecoFrete retorna true se os dados foram úteis e preenchidos, false caso contrário.
                        var processadoComSucessoNoFrontend = processarDadosEnderecoFrete(data); // Sets freteData if successful

                        if (processadoComSucessoNoFrontend && freteData) {
                             log('DEBUG     Processamento frontend obteve dados úteis e há dados para armazenar. Chamando store_webhook_shipping.');
                             // TAREFA 3.1.15: Chain the store promise. Resolve the main promise *based on the store promise*.
                             armazenarDadosNoServidor(freteData)
                                .then(function(success_storing) {
                                     log('DEBUG     store_webhook_shipping promise resolvida. Success:', success_storing);
                                    // Se armazenarDadosNoServidor teve sucesso, resolve a promise principal com true.
                                    // Caso contrário, ela já resolveu com false.
                                    resolve(success_storing); // Resolve the main promise chain (true/false from store)
                                })
                                .catch(function(error_storing){
                                     log('ERROR     store_webhook_shipping promise rejeitada. Error:', error_storing, 'ERROR');
                                     // Error message already set by armazenarDadosNoServidor
                                     // Em caso de rejeição da promise interna, a promise principal também deve indicar falha.
                                    resolve(false); // Resolve main promise as failure if store failed
                                });

                        } else {
                             // If processarDadosEnderecoFrete failed (no useful data)
                             log('DEBUG     Falha no processamento inicial dos dados do webhook (frontend).', null, 'WEBHOOK_FAIL'); // Added failure phase
                             // Error message already set by processarDadosEnderecoFrete
                             resolve(false); // Resolve a promise principal como false
                        }

                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        ajaxWebhookEndTime = performance.now();
                        const deltaAjaxWebhook = (ajaxWebhookEndTime - ajaxWebhookStartTime).toFixed(0);
                        currentPhase = 'WEBHOOK_IN';
                        log(`AJAX_ERROR  Erro na requisição AJAX para o webhook (${textStatus}). Δajax=${deltaAjaxWebhook}ms.`, {
                            status: jqXHR.status, textStatus: textStatus, error: errorThrown, responseText: jqXHR.responseText
                        }, 'WEBHOOK_FAIL'); // Added failure phase

                        $('.cep-erro').show().text('Não foi possível consultar o CEP. Tente novamente ou preencha o endereço manualmente.');

                        resolve(false); // Resolve main promise as false in case of webhook AJAX error
                    },
                    complete: function() {
                         currentPhase = 'WEBHOOK_DONE';
                         log('WEBHOOK_DONE Webhook AJAX request complete (callback complete)');
                         // TAREFA 3.1.15: A classe cep-loading *deve* ser removida assim que a comunicação com o webhook terminar.
                         $('#tab-cep').removeClass('cep-loading'); // Keep this specific visual cleanup here.
                         consultaEmAndamento = false; // Reset this flag here as the webhook AJAX is done
                         // Nota: A limpeza global do overlay é feita no listener 'updated_checkout' ou no handler do botão em caso de falha na cadeia.
                    }
                });
            }); // End Promise constructor
        }

        // Opcional: Lidar com a entrada de CEP para disparar consulta automática (Pode adicionar debounce aqui se quiser consultar no input/blur)
        // Atualmente, a consulta é disparada apenas no clique do botão "Avançar". (Mantido)
        $('#billing_postcode').on('change', function() {
             var cep = $(this).val().replace(/\D/g, '');
             if (cep.length === 8) {
                 log('DEBUG     CEP completo digitado/alterado:', cep);
                 // Não consulta automaticamente aqui no change, mantemos o fluxo do botão "Avançar".
                 // $('#btn-avancar-para-endereco').click(); // Descomente se quiser consultar no change/blur
                 $('.cep-erro').hide(); // Esconde o error de CEP se o CEP for alterado
             } else {
                  // Hide error only if CEP is incomplete or empty. If it was 8 digits and invalid, keep error visible until changed again.
                  if (cep.length < 8) {
                      $('.cep-erro').hide();
                  }
             }
        });

        // TAREFA 2 (v3.1.6/3.1.7): Função para renderizar/atualizar o valor do total duplicado na aba Pagamento
        function renderDuplicateTotal() {
             const $paymentTab = $('#tab-pagamento');
             const $paymentSection = $paymentTab.find('#payment').first();
             const $placeOrderContainer = $paymentSection.find('.place-order').first(); // v3.1.16: Select within payment and get first
             let $duplicateTotalContainer = $paymentSection.find('.payment-total-dup').first(); // v3.1.16: Select within payment and get first

             if (!$paymentTab.length || !$paymentSection.length) {
                 log('AVISO     Não foi possível renderizar o bloco de total duplicado. Aba Pagamento ou seção #payment não encontrados.', null, 'UI');
                 return; // Exit if required containers are missing
             }


             // TAREFA 2 (v3.1.7) + v3.1.16: Injete o bloco se ele não existir DENTRO de #payment, antes de .place-order
             if ($duplicateTotalContainer.length === 0) {
                  // Ensure place order container exists before injecting before it
                  if ($placeOrderContainer.length) {
                     log('DEBUG     Bloco de total duplicado (.payment-total-dup) não encontrado dentro de #payment, injetando antes de .place-order.');
                      $placeOrderContainer.before(`
                        <div class="payment-total-dup">
                          <hr class="payment-total-divider">
                          <div class="payment-total-row">
                             <span class="payment-total-label">Total</span>
                             <span class="payment-total-value">R$ 0,00</span>
                          </div>
                        </div>
                      `);
                     // Update the reference after injection, ensure it's inside #payment
                      $duplicateTotalContainer = $paymentSection.find('.payment-total-dup').first();
                  } else {
                      log('AVISO     Bloco de total duplicado (.payment-total-dup) não encontrado e não foi possível injetar antes de .place-order (place-order não encontrado dentro de #payment).', null, 'UI');
                      // If place order is missing too, maybe append to #payment as a fallback?
                      // This might mess up order in mobile, but better than nothing.
                       log('DEBUG     Tentando injetar bloco total duplicado no final de #payment como fallback.');
                       $paymentSection.append(`
                        <div class="payment-total-dup">
                          <hr class="payment-total-divider">
                          <div class="payment-total-row">
                             <span class="payment-total-label">Total</span>
                             <span class="payment-total-value">R$ 0,00</span>
                          </div>
                        </div>
                       `);
                       $duplicateTotalContainer = $paymentSection.find('.payment-total-dup').first();
                  }
             } else {
                  log('DEBUG     Bloco de total duplicado (.payment-total-dup) encontrado dentro de #payment.');
             }


             // TAREFA 2 (v3.1.6/3.1.7): Atualizar o valor
             const $orderTotal = $('#tab-resumo-frete #order_review .order-total .amount, #tab-resumo-frete #order_review .order_details .amount');
             // v3.1.16: Ensure we find the value element *within* the duplicated container
             const $duplicateTotalValue = $duplicateTotalContainer.find('.payment-total-value').first();

             if ($orderTotal.length && $duplicateTotalValue.length) {
                 const totalText = $orderTotal.text().trim();
                 if ($duplicateTotalValue.text().trim() !== totalText) {
                      $duplicateTotalValue.text(totalText);
                      log(`DEBUG     Total duplicado atualizado para: ${totalText}`);
                 } else {
                      log(`DEBUG     Total duplicado já está correto: ${totalText}`);
                 }
             } else {
                 log('AVISO     Não foi possível encontrar os elementos do total para atualizar o total duplicado.', null, 'UI');
             }
        }


        // === Eventos padrão do WooCommerce para controlar loading e atualizar UI ===

        // Listener para quando o WooCommerce inicia uma atualização de checkout via AJAX padrão (AJUSTADO v3.1.16)
        // (Isto acontece após trigger('change') em um método de frete, ou update_checkout trigger)
        $(document.body).on('update_checkout', function() {
            // Disparado ANTES da requisição AJAX padrão do WC para atualizar o checkout
            currentPhase = 'WC_OUT';
            log('WC_OUT    Evento update_checkout detectado (WC padrão). Mostrando loading...');
            // v3.1.16: Sempre mostrar o loading GERAL quando update_checkout é disparado.
            // A lógica de DEFERIR a limpeza para o listener .one() fica no 'updated_checkout'.
            setProcessingState(true, 'update_checkout_general');
             ajaxWCStartTime = performance.now(); // Log B1 para o AJAX padrão do WC

        });

        // Listener para quando o WooCommerce termina uma atualização de checkout via AJAX padrão (AJUSTADO TAREFA 1 e 2 v3.1.6/3.1.7 + 3.1.15 + 3.1.16)
        // IMPORTANTE: Este listener AGORA NÃO DEVE GERENCIAR A TRANSIÇÃO DE ABA DO CEP.
        // Essa tarefa específica é delegada ao listener .one() registrado no clique do botão CEP.
        $(document.body).on('updated_checkout', function() {
            // Disparado DEPOIS da requisição AJAX padrão do WC para atualizar o checkout
            // ou DEPOIS que fragments são aplicados via custom AJAX que chama este evento.

             ajaxWCEndTime = performance.now(); // Capture WC AJAX end time if applicable

            log('UI        Evento updated_checkout detectado. Finalizando UI loading geral (se não foi pelo .one() listener)...');

             // Determine qual AJAX terminou por último para calcular o tempo total relevante
             // (Isso é mais para logging e pode ser refinado, mas por enquanto mantém a lógica anterior)
             let lastAjaxEndTime = Math.max(ajaxStoreEndTime || 0, ajaxWCEndTime || 0, ajaxWebhookEndTime || 0); // Use 0 if not set
             if (lastAjaxEndTime < actionStartTime) lastAjaxEndTime = performance.now(); // Fallback if no AJAX recorded

             fragmentsAppliedTime = performance.now(); // Log B1: Assume fragments estão visíveis AGORA
             const totalTime = (fragmentsAppliedTime > actionStartTime ? fragmentsAppliedTime - actionStartTime : 0).toFixed(0);

             // Log timing information based on the last relevant event
             // v3.1.16: Added more robust check for AJAX times being set
             if (ajaxStoreEndTime > 0 && ajaxStoreEndTime >= ajaxWCEndTime && ajaxStoreEndTime >= ajaxWebhookEndTime) {
                  // SWHS terminou por último ou em paralelo com WC, e foi iniciado APÓS o webhook
                 log(`UPDATE_DONE Updated via SWHS/FRAGMENTS. Total time = ${totalTime}ms since action start.`);
             } else if (ajaxWCEndTime > 0 && ajaxWCEndTime > ajaxStoreEndTime && ajaxWCEndTime > ajaxWebhookEndTime) {
                 // WC padrão terminou por último
                  const deltaAjax = (ajaxWCEndTime > ajaxWCStartTime ? ajaxWCEndTime - ajaxWCStartTime : 0).toFixed(0);
                  log(`WC_IN     WC update_order_review success. Δajax=${deltaAjax}ms.`); // Log do AJAX padrão do WC
                  log(`UPDATE_DONE Updated via WC standard. Total time = ${totalTime}ms since action start.`);
             } else if (ajaxWebhookEndTime > 0 && ajaxWebhookEndTime > ajaxStoreEndTime && ajaxWebhookEndTime > ajaxWCStartTime) {
                  // Webhook terminou por último (raro, mas possível em error)
                  log(`WEBHOOK_IN  Webhook completed last.`); // Log do AJAX padrão do WC
                  log(`UPDATE_DONE Update complete. Total time = ${totalTime}ms since action start.`);
             } else {
                  // Caso Updated_checkout seja disparado por outro motivo não medido ainda ou no init
                  log(`DEBUG     updated_checkout disparado sem evento AJAX claro. Total time = ${totalTime}ms since action start.`);
                   log(`UPDATE_DONE Update complete.`);
             }


            // TAREFA 3.1.15 + v3.1.16: Remover estado de processamento GERAL AQUI SOMENTE SE o listener específico
            // para o avanço do CEP NÃO foi quem removeu o estado.
            // A lógica em handleUpdatedCheckoutForCepAdvance já remove se a aba CEP estava ativa E o botão processando.
            // Se updated_checkout disparou e a aba CEP NÃO está ativa, ou o botão não estava processando (atualização veio de outro lugar),
            // limpamos o estado geral aqui.
             const $cepButton = $('#btn-avancar-para-endereco');
             if ($('#tab-cep').hasClass('active') && $cepButton.hasClass('btn-processing')) {
                 // Se a aba CEP está ativa E o botão está em processamento, o listener .one() é quem vai limpar.
                 log('DEBUG     updated_checkout: Aba CEP ativa com botão processando. Limpeza de estado GERAL será feita pelo listener .one() handleUpdatedCheckoutForCepAdvance.');
             } else {
                  // Se a aba CEP NÃO está ativa, ou o botão não estava processando (atualização veio de outro lugar),
                  // limpamos o estado general aqui.
                  log('DEBUG     updated_checkout: Limpando estado geral de processamento.');
                 setProcessingState(false, 'updated_checkout_general');
                 // Garantir que o botão CEP não fique stuck se a atualização veio de outro lugar enquanto ele estava processando
                 $cepButton.removeClass('btn-processing'); // Ensure button class is removed
             }


             currentPhase = 'UI'; // Volta para fase UI após atualização

             // Após a atualização, re-garante que o método selecionado visualmente bate com o input checked
             // Procura a lista UL dentro do #order_review (agora na aba 4)
             var $shippingContainer = $('#tab-resumo-frete #order_review ul.shipping_method, #tab-resumo-frete #order_review ul[data-shipping-methods]').first();
             if ($shippingContainer.length) {
                  var checkedMethodValue = $shippingContainer.find('input[name^="shipping_method"]:checked').val();
                  log('DEBUG     updated_checkout: Capturando método selecionado após WC update: ' + checkedMethodValue);
                  // Aplica a classe 'active'/'selected' no LI correto usando o método checked
                  $shippingContainer.find('li').removeClass('active selected'); // Also remove 'selected'
                  $shippingContainer.find('input[value="' + checkedMethodValue + '"]').closest('li').addClass('active selected'); // Also add 'selected'

                  // Atualiza o valor do frete no resumo usando o preço ATUAL do método selecionado
                  // Procura dentro do container que foi movido para a aba 4
                  var selectedMethod = $shippingContainer.find('li.active, li.selected');
                   if (selectedMethod.length) {
                        // Pega o texto do span com a classe .amount dentro do item selecionado
                        // Use .amount which is standard WC
                        var priceElement = selectedMethod.find('.amount').first();
                        if (priceElement.length) {
                           var priceText = priceElement.text();
                            // Atualiza o span dentro do resumo que TAMBÉM foi movido para a aba 4
                            $('#tab-resumo-frete #order_review .shipping-totals .amount').text(priceText);
                            log('DEBUG     Atualizado o custo do frete no resumo (#order_review .shipping-totals .amount) com preço do método selecionado.');
                        } else {
                             log('DEBUG     Não encontrei .amount no método de frete selecionado para atualizar custo no resumo.');
                        }
                   } else {
                        log('DEBUG     Nenhum método de frete selecionado encontrado dentro de #order_review após updated_checkout.');
                   }

             } else {
                  log('DEBUG     updated_checkout: Container de frete UL não encontrado dentro de #order_review (aba 4) para atualizar seleção visual.');
             }

             // Item 4: Atualizar estado do botão "Finalizar Pedido" (agora na aba 5)
             updatePlaceOrderButtonState();

             // TAREFA 2 (v3.1.6/3.1.7) + 3.1.15 + v3.1.16: Atualizar Total duplicado após a atualização do checkout
             renderDuplicateTotal();


             // TAREFA 1 (v3.1.6/3.1.7) + 3.1.15 + v3.1.16: Ensure order of elements within #tab-pagamento after updated_checkout
             // This is important because fragments might re-insert elements in the wrong order
             // Order Notes -> e-coupon-box -> Payment -> Standard Coupon Form
              var $paymentTab = $('#tab-pagamento');
              var $paymentSectionAfterUpdate = $paymentTab.find('#payment').first();
              var $couponFormAfterUpdate = $paymentTab.find('.checkout_coupon').first(); // Standard form (kept hidden)
              var $orderNotesAfterUpdate = $paymentTab.find('.woocommerce-additional-fields__field-wrapper').first();
              var $customCouponBoxAfterUpdate = $paymentTab.find('.e-coupon-box').first(); // Custom coupon box

              log('DEBUG     Iniciando re-ordenação de elementos dentro da aba Pagamento após updated_checkout...');

              // Remove quaisquer duplicatas que possam ter sido adicionadas
              $paymentTab.find('.woocommerce-additional-fields__field-wrapper:not(:first)').remove();
              $paymentTab.find('.checkout_coupon:not(:first)').remove();
              $paymentTab.find('#payment:not(:first)').remove();
              $paymentTab.find('.e-coupon-box:not(:first)').remove();
               log('DEBUG     Removidas duplicatas encontradas dentro da aba Pagamento.');


              // Re-anexar elementos se eles sumiram após fragments (menos comum, mas fallback)
              // e selecionar a referência correta novamente
              if ($orderNotesAfterUpdate.length === 0) { $orderNotesAfterUpdate = $('.woocommerce-additional-fields__field-wrapper').first(); if ($orderNotesAfterUpdate.length) $orderNotesAfterUpdate.appendTo($paymentTab); }
              if ($couponFormAfterUpdate.length === 0) { $couponFormAfterUpdate = $('.checkout_coupon').first(); if ($couponFormAfterUpdate.length) $couponFormAfterUpdate.appendTo($paymentTab); }
              if ($paymentSectionAfterUpdate.length === 0) { $paymentSectionAfterUpdate = $('#payment').first(); if ($paymentSectionAfterUpdate.length) $paymentSectionAfterUpdate.appendTo($paymentTab); }
              if ($customCouponBoxAfterUpdate.length === 0) { $customCouponBoxAfterUpdate = $('.e-coupon-box').first(); if ($customCouponBoxAfterUpdate.length) $customCouponBoxAfterUpdate.appendTo($paymentTab); }

               log('DEBUG     Re-anexados elementos que podem ter sumido: orderNotes=' + $orderNotesAfterUpdate.length + ', couponForm=' + $couponFormAfterUpdate.length + ', payment=' + $paymentSectionAfterUpdate.length + ', customCoupon=' + $customCouponBoxAfterUpdate.length);


              // Re-selecionar após re-anexar, se necessário
              $orderNotesAfterUpdate = $paymentTab.find('.woocommerce-additional-fields__field-wrapper').first();
              $couponFormAfterUpdate = $paymentTab.find('.checkout_coupon').first();
              $paymentSectionAfterUpdate = $paymentTab.find('#payment').first();
              $customCouponBoxAfterUpdate = $paymentTab.find('.e-coupon-box').first();


              // Ensure final order: Order Notes -> e-coupon-box -> Payment -> Standard Coupon Form
              var $currentAnchor = null;

               if ($orderNotesAfterUpdate.length) {
                   if ($currentAnchor) $orderNotesAfterUpdate.insertAfter($currentAnchor); else $orderNotesAfterUpdate.prependTo($paymentTab);
                   $currentAnchor = $orderNotesAfterUpdate;
                    log('DEBUG     Posicionado Order Notes.');
               }

              if ($customCouponBoxAfterUpdate.length) {
                  if ($currentAnchor) $customCouponBoxAfterUpdate.insertAfter($currentAnchor); else $customCouponBoxAfterUpdate.prependTo($paymentTab);
                  $currentAnchor = $customCouponBoxAfterUpdate;
                   log('DEBUG     Posicionado custom coupon box.');
              }

               if ($paymentSectionAfterUpdate.length) {
                   if ($currentAnchor) $paymentSectionAfterUpdate.insertAfter($currentAnchor); else $paymentSectionAfterUpdate.prependTo($paymentTab);
                   $currentAnchor = $paymentSectionAfterUpdate;
                    log('DEBUG     Posicionado #payment.');
               }


              // Ensure standard coupon form is after payment (it's hidden anyway)
              if ($couponFormAfterUpdate.length) {
                  if ($currentAnchor) $couponFormAfterUpdate.insertAfter($currentAnchor); else $couponFormAfterUpdate.prependTo($paymentTab);
                  // $currentAnchor = $couponFormAfterUpdate; // No need to update anchor after the last element
                   log('DEBUG     Posicionado standard coupon form.');
              }

              log('DEBUG     Finalizada re-ordenação de elementos dentro da aba Pagamento após updated_checkout.');

        });

        // Listener para o evento 'frag_loaded' disparado pelo WooCommerce (para compatibilidade)
        // Isso acontece quando o WC carrega fragmentos, como ao aplicar um cupom ou mudar frete/pagamento
        // TAREFA 2 (v3.1.7) + 3.1.15 + v3.1.16: Chamar renderDuplicateTotal aqui também
        $(document.body).on('wc_fragment_refresh', function() {
             log('DEBUG     Evento wc_fragment_refresh detectado. Atualizando total duplicado.');
             // Call the rendering function directly, it handles presence check and updates value
             renderDuplicateTotal();
             // Note: updated_checkout fires after wc_fragment_refresh usually,
             // so main cleanup/state updates happen there. This is just for immediate fragment loading.
        });


        // Listen for changes in shipping method selection (Mantido)
        // IMPORTANT: Search for the shipping method UL inside the moved #order_review
        $(document).on('change', '#tab-resumo-frete #order_review input[name^="shipping_method"]', function() {
             actionStartTime = performance.now(); // Log B1: Início da ação do usuário (seleção de frete)
             log('ACTION    Método de frete selecionado alterado.');

             var $this = $(this);
             var selectedValue = $this.val();

             // Add 'active' class to the selected list item and remove from others
              var $shippingContainer = $this.closest('ul.shipping_method, ul[data-shipping-methods]');
              if ($shippingContainer.length) {
                  $shippingContainer.find('li').removeClass('active selected'); // Also remove 'selected'
                  $this.closest('li').addClass('active selected'); // Also add 'selected'
                  log('DEBUG     Classe visual "active/selected" aplicada ao método selecionado.');

                  // Atualiza o valor do frete no resumo usando o preço do item selecionado
                  // Procura dentro do container que foi movido para a aba 4
                  var selectedMethod = $shippingContainer.find('li.active, li.selected');
                   if (selectedMethod.length) {
                        // Pega o texto do span com a classe .amount dentro do item selecionado
                        // Use .amount which is standard WC
                        var priceElement = selectedMethod.find('.amount').first();
                        if (priceElement.length) {
                           var priceText = priceElement.text();
                            // Atualiza o span dentro do resumo que TAMBÉM foi movido para a aba 4
                            $('#tab-resumo-frete #order_review .shipping-totals .amount').text(priceText);
                            log('DEBUG     Atualizado o custo do frete no resumo (#order_review .shipping-totals .amount) com preço do método selecionado.');
                        } else {
                             log('DEBUG     Não encontrei .amount no método de frete selecionado para atualizar custo no resumo.');
                        }
                   } else {
                        log('DEBUG     Nenhum método de frete selecionado encontrado dentro de #order_review após selection change.');
                   }


              } else {
                   log('AVISO     Container de frete UL não encontrado para aplicar classes visuais.');
              }

             // Trigger WC's standard checkout update
             // This will trigger updated_checkout after WC finishes its own AJAX update.
             log('DEBUG     Disparando trigger("update_checkout") após seleção de frete.');
             $(document.body).trigger('update_checkout');

             // The updated_checkout listener will handle removing processing state and updating place order button state

        });



        // === Inicialização ===
        // Ao carregar a página, capturar o método de frete padrão selecionado pelo WC (Mantido)
         $(window).on('load', function() {
              currentPhase = 'INIT';
              log('DEBUG     Página carregada. Iniciando script de abas.');

               // No load, #order_review JÁ DEVE TER SIDO MOVIDO para #tab-resumo-frete
               // E #payment, .checkout_coupon e o anchor de cupom JÁ DEVEM TER SIDO MOVIDOS para #tab-pagamento
              var $shippingContainer = $('#tab-resumo-frete #order_review ul.shipping_method, #tab-resumo-frete #order_review ul[data-shipping-methods]').first();
              if ($shippingContainer.length) {
                   var checkedMethodValue = $shippingContainer.find('input[name^="shipping_method"]:checked').val();
                   log('DEBUG     Método de frete selecionado na carga da página:', checkedMethodValue);
                   // Ensure visual class is set on load
                    $shippingContainer.find('li').removeClass('active selected');
                    $shippingContainer.find('input[value="' + checkedMethodValue + '"]').closest('li').addClass('active selected');

                    // Atualiza o valor do frete no resumo usando o preço ATUAL do método selecionado
                    // Procura dentro do container que foi movido para a aba 4
                    var selectedMethod = $shippingContainer.find('li.active, li.selected');
                    if (selectedMethod.length) {
                         var priceElement = selectedMethod.find('.amount').first();
                         if (priceElement.length) {
                            var priceText = priceElement.text();
                             $('#tab-resumo-frete #order_review .shipping-totals .amount').text(priceText);
                             log('DEBUG     Atualizado o custo do frete no resumo na carga da página.');
                         } else {
                              log('DEBUG     Não encontrei .amount no método selecionado na carga.');
                         }
                    } else {
                         log('DEBUG     Nenhum método de frete selecionado encontrado na carga.');
                    }

              } else {
                   log('DEBUG     Container de frete UL não encontrado dentro de #order_review (aba 4) na carga da página.');
              }
              currentPhase = 'UI'; // Volta para fase UI após inicialização

              // Na carga, atualiza o estado do botão finalizar
              updatePlaceOrderButtonState();

              // TAREFA 2 (v3.1.6/3.1.7) + 3.1.15 + v3.1.16: Renderizar e Atualizar Total duplicado na carga da página
              renderDuplicateTotal();

              // TAREFA 2 (v3.1.3): Remover chamada hide() no botão PLACE ORDER.
              // Ocultar o botão PLACE ORDER padrão na carga é agora tratado SOMENTE pelo CSS.
              // $('#place_order').hide(); // REMOVIDO

         });


    });
    </script>
    <?php
    endif;
}
add_action('wp_head', 'custom_checkout_assets');