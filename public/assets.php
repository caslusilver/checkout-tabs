<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Hook para enfileirar scripts e estilos
add_action('wp_enqueue_scripts', 'cc_enqueue_checkout_scripts');

/**
 * Enfileira scripts e estilos para a página de checkout.
 */
function cc_enqueue_checkout_scripts() {
    // Verifica se estamos na página de checkout e não é um endpoint
    if (is_checkout() && !is_wc_endpoint_url()) {

        // Registra o script principal das abas (ainda vazio)
        // O caminho é relativo ao arquivo do plugin principal (checkout-tabs.php)
        wp_register_script(
            'checkout-tabs-js',
            plugins_url('public/js/checkout-tabs.js', CHECKOUT_TABS_FILE),
            ['jquery', 'wc-checkout'], // Dependências
            null, // Versão (null para não versionar por enquanto)
            true // Carregar no footer
        );

        // Localiza dados para o script principal
        wp_localize_script('checkout-tabs-js', 'cc_params', [
            'debug'      => defined('CC_DEBUG') && CC_DEBUG, // Usa a constante de core-hooks.php
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('store_webhook_shipping'),
            'webhook_url'=> 'https://webhook.cubensisstore.com.br/webhook/consulta-frete' // Substitua pelo seu webhook real
        ]);

        // Enfileira o script principal
        wp_enqueue_script('checkout-tabs-js');

        // Adiciona script de máscara, se não for carregado por outro plugin
        if (!wp_script_is('jquery-mask', 'enqueued') && !wp_script_is('jquery.mask.min', 'enqueued') && !wp_script_is('jquery-maskmoney', 'enqueued')) {
            wp_enqueue_script('jquery-mask', '//cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js', ['jquery'], '1.14.16', true);
        } else {
            if (wp_script_is('jquery-maskmoney', 'enqueued') && !wp_script_is('jquery-mask', 'enqueued') && !wp_script_is('jquery.mask.min', 'enqueued')) {
                 wp_enqueue_script('jquery-mask', '//cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js', ['jquery'], '1.14.16', true);
            }
        }
    }
}

// Hook para adicionar assets customizados (HTML/CSS/JS inline antigo)
// TODO: Encontrar o hook correto no código original. Usando um comum como placeholder.
add_action('woocommerce_checkout_before_customer_details', 'cc_custom_checkout_assets', 10);

/**
 * Adiciona o HTML das abas, CSS inline e o JS inline (a ser movido).
 */
function cc_custom_checkout_assets() {
    // Verifica se estamos na página de checkout e não é um endpoint
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
        body.woocommerce-checkout form.checkout .checkout-tab{
            display:block !important;
            flex-direction: initial !important;
            order: initial !important;
        }
        body.woocommerce-checkout form.checkout .checkout-tab:not(.active){
            display:none !important;
        }
        /* -------------------------------------------------------- */

        /* Botão de Finalizar Pedido do WooCommerce */
         .checkout-tab:not(#tab-pagamento) .place-order{
            display:none!important;
         }
         #tab-pagamento #payment .place-order {
             display: block !important;
             width: 100%;
             margin-top: 20px;
             margin-bottom: 0;
        }
         @media (max-width:768px){
             #tab-pagamento #payment .place-order{
                 margin-top:0;
                 margin-bottom:0;
             }
         }
        #tab-pagamento #payment .place-order #place_order {
             padding: 10px 20px;
             background: #0075FF;
             color: #fff;
             border: none;
             cursor: pointer;
             font-weight: 500;
             border-radius: 3px !important;
             width: 100%;
             text-align: center;
        }
         #tab-pagamento #payment .place-order #place_order:hover:not(:disabled) {
             background: #005BC7;
         }
          #tab-pagamento #payment .place-order #place_order:disabled {
             background-color: #cccccc !important;
             cursor: not-allowed !important;
             opacity: 0.7;
         }
         #tab-pagamento #payment .place-order #place_order:disabled span {
              color: #fff !important;
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
         .checkout-next-btn:disabled {
             background-color: #cccccc !important;
             cursor: not-allowed !important;
             opacity: 0.7;
         }
         .checkout-next-btn:disabled span {
              color: #fff !important;
         }

        /* Botão Voltar */
        .checkout-back-btn {
            background: #E2EDFB;
            color: #005BC7 !important;
            font-weight: 500;
            margin-right: 10px;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            margin-top: 0;
        }
        .checkout-back-btn:hover:not(:disabled) {
            background: #9DCAFF;
        }

        /* Responsividade dos botões */
        @media (max-width: 768px) {
             .checkout-next-btn, .checkout-back-btn {
                 width: 100%;
                 margin-right: 0;
             }
             .tab-buttons {
                  display: flex !important;
                  flex-direction: column !important;
                  align-items: flex-start !important;
                  padding: 0 !important;
                  width: 100% !important;
                  margin-top: 20px !important;
                  gap: 10px !important;
             }
             #tab-dados-pessoais, #tab-cep, #tab-dados-entrega, #tab-resumo-frete, #tab-pagamento {
                 display: flex !important;
                 flex-direction: column !important;
                 gap: 15px !important;
             }
             #tab-pagamento > * {
                 order: 0 !important;
             }
             #tab-pagamento .tab-buttons {
                  order: 1 !important;
                  margin-top: 0 !important;
             }
             #tab-pagamento #payment {
                  display: flex !important;
                  flex-direction: column !important;
                  gap: 15px !important;
             }
             #tab-pagamento #payment .payment_methods {
                  order: 0 !important;
                  margin-bottom: 0 !important;
             }
             #tab-pagamento #payment .payment-total-dup {
                 order: 1 !important;
                 margin-bottom: 0 !important;
                 margin-top: 0 !important;
             }
             #tab-pagamento #payment .place-order {
                  order: 2 !important;
                  margin-bottom: 0 !important;
                  margin-top: 0 !important;
             }
              #tab-pagamento .checkout_coupon {
                display: flex !important;
                flex-wrap: nowrap !important;
                gap: 10px !important;
                align-items: center !important;
              }
              #tab-pagamento .checkout_coupon input#coupon_code {
                flex: 1 1 auto !important;
                width: auto !important;
              }
              #tab-pagamento .checkout_coupon .button {
                flex: 0 0 auto !important;
                width: auto !important;
              }
        } /* End media query */

        /* Ocultar campos específicos */
        #billing_persontype_field,
        .person-type-field.thwcfd-optional.thwcfd-field-wrapper.thwcfd-field-select.is-active {
            display: none !important;
        }

        /* Continua CSS... (obtido parcialmente) */

    </style>

    <!-- Estrutura HTML das Abas -->
    <div id="checkout-tab-container">
        <!-- Barra de Progresso -->
        <div class="progress-bar-container">
            <div class="progress-step active" data-tab="tab-dados-pessoais"><span>1</span> Dados Pessoais</div>
            <div class="progress-step" data-tab="tab-cep"><span>2</span> CEP</div>
            <div class="progress-step" data-tab="tab-dados-entrega"><span>3</span> Endereço</div>
            <div class="progress-step" data-tab="tab-resumo-frete"><span>4</span> Frete</div>
            <div class="progress-step" data-tab="tab-pagamento"><span>5</span> Pagamento</div>
        </div>

        <!-- Container das Abas -->
        <div class="checkout-tabs">
            <div id="tab-dados-pessoais" class="checkout-tab active">
                <h2><span class="step-number">1</span> Dados Pessoais</h2>
                <!-- Conteúdo original de #customer_details .col-1 será movido aqui -->
                <div class="tab-buttons">
                    <button type="button" class="button checkout-next-btn" id="btn-avancar-para-cep">Avançar</button>
                </div>
            </div>

            <div id="tab-cep" class="checkout-tab">
                <h2><span class="step-number">2</span> Consulte seu CEP</h2>
                <!-- Formulário de CEP e botão de consulta -->
                 <p class="form-row form-row-wide" id="billing_postcode_field_tab" data-priority="65">
                     <label for="billing_postcode_tab" class="">CEP&nbsp;<abbr class="required" title="obrigatório">*</abbr></label>
                     <span class="woocommerce-input-wrapper">
                         <input type="text" class="input-text" name="billing_postcode_tab" id="billing_postcode_tab" placeholder="00000-000" value="" autocomplete="postal-code" inputmode="numeric">
                     </span>
                     <span class="cep-status"></span>
                     <span class="cep-erro" style="display:none; color:red; font-size:0.9em;"></span>
                     <span class="whatsapp-invalido" style="display:none; color:red; font-size:0.9em;">WhatsApp inválido. Verifique o número ou use outro para entrega.</span>
                 </p>
                <div class="tab-buttons">
                    <button type="button" class="button alt checkout-back-btn" id="btn-voltar-para-dados">Voltar</button>
                    <button type="button" class="button checkout-next-btn" id="btn-avancar-para-endereco">Avançar</button>
                </div>
            </div>

            <div id="tab-dados-entrega" class="checkout-tab">
                 <h2><span class="step-number">3</span> Endereço de Entrega</h2>
                 <!-- Conteúdo original de #customer_details .col-2 será movido aqui -->
                <div class="tab-buttons">
                    <button type="button" class="button alt checkout-back-btn" id="btn-voltar-para-cep">Voltar</button>
                    <button type="button" class="button checkout-next-btn" id="btn-avancar-para-frete">Avançar</button>
                </div>
            </div>

            <div id="tab-resumo-frete" class="checkout-tab">
                <h2><span class="step-number">4</span> Opções de Frete</h2>
                <!-- Conteúdo original de #order_review .woocommerce-shipping-totals será movido aqui -->
                <div class="tab-buttons">
                    <button type="button" class="button alt checkout-back-btn" id="btn-voltar-para-endereco">Voltar</button>
                    <button type="button" class="button checkout-next-btn" id="btn-avancar-para-pagamento">Avançar</button>
                </div>
            </div>

            <div id="tab-pagamento" class="checkout-tab">
                <h2><span class="step-number">5</span> Pagamento</h2>
                <!-- Conteúdo original de #order_review, #payment, .checkout_coupon serão movidos aqui -->
                <div class="tab-buttons">
                    <button type="button" class="button alt checkout-back-btn" id="btn-voltar-para-frete">Voltar</button>
                    <!-- Botão Finalizar Compra original (#place_order) será movido para dentro do #payment -->
                </div>
            </div>
        </div>

        <!-- Overlay de Carregamento -->
        <div id="checkout-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background-color: rgba(0, 0, 0, 0.6); z-index: 9999; backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); justify-content: center; align-items: center;">
             <div class="spinner" style="border: 4px solid rgba(255, 255, 255, 0.3); border-radius: 50%; border-top-color: #0075FF; width: 40px; height: 40px; animation: spin 1s ease-in-out infinite;"></div>
        </div>
        <style>@keyframes spin { to { transform: rotate(360deg); } }</style>

        <!-- Painel de Debug (v3.1.9) -->
        <?php if ( defined( 'CC_DEBUG' ) && CC_DEBUG ) : ?>
        <div id="debug-panel" style="position: fixed; bottom: 10px; right: 10px; width: 350px; max-height: 400px; overflow-y: auto; background: rgba(0,0,0,0.8); color: #0f0; font-family: monospace; font-size: 11px; padding: 10px; border: 1px solid #333; z-index: 10000; display: block;">
            <h4 style="color: #ff0; margin: 0 0 5px 0; padding: 0; border-bottom: 1px solid #555;">Debug Log (Checkout Tabs) <button id="clear-debug" style="float: right; background: #555; color: #fff; border: none; font-size: 10px; cursor: pointer; padding: 1px 4px;">Limpar</button></h4>
            <pre id="debug-log" style="margin: 0; padding: 0; white-space: pre-wrap; word-wrap: break-word;"></pre>
        </div>
        <script type="text/javascript">
             // Add clear functionality
             document.addEventListener('DOMContentLoaded', function() {
                 var clearButton = document.getElementById('clear-debug');
                 var debugLog = document.getElementById('debug-log');
                 if (clearButton && debugLog) {
                     clearButton.addEventListener('click', function() {
                         debugLog.innerHTML = '';
                     });
                 }
             });
        </script>
        <?php endif; ?>

    </div> <!-- Fim #checkout-tab-container -->

    <!-- TODO: Mover todo o JavaScript abaixo para public/js/checkout-tabs.js -->
    <!-- <script type="text/javascript">
        jQuery(function($) {
            // ... Todo o código JS original estava aqui ...
        });
    </script> -->

    <?php
    endif; // Fim if is_checkout
} 