<?php


/*
Plugin Name: PagCripto Gateway
Description: Gateway para pagamento com criptomoedas
Author: PagCripto
Author URI: https://pagcripto.com.br
*ck_b5537d6f1ce3e8d4fe1ecd2daa2037fcc7a59d37
*cs_ef00552df7513d51fc532ea704b84b8fdb4b1733

http://wcdemo.pagcripto.com.br/wc-api/pagcripto/?order_id=475
*/


// Interrompe o script caso o wordpress não tenha sido carregado
if (!defined('ABSPATH')) {
    exit;
}

/*
 * PagCripto Gateway WooCommerce.
 *
 * Cria uma instância do plugin para fins de teste.
 */
if (!function_exists('write_log')) {

    function write_log($log)
    {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }
}

add_action('plugins_loaded', 'init_pagcripto_gateway_class');

function init_pagcripto_gateway_class()
{


    class WC_Gateway_Custom extends WC_Payment_Gateway
    {

        public $domain;
        /**
         * Constructor do gateway.
         */
        public function __construct()
        {

            $plugin_dir = plugin_dir_url(__FILE__);
            $this->domain = 'pagcripto_payment';

            $this->id                 = 'pagcripto';
            $this->icon               = apply_filters('woocommerce_pagcripto_gateway_icon', $plugin_dir . '\assets\logo-pc.png');
            $this->has_fields         = false;
            $this->method_title       = __('Pagcripto Gateway', $this->domain);
            $this->method_description = __('Gateway de pagamento usando a plataforma PagCripto.', $this->domain);

            // Carregando as configurações.
            $this->init_form_fields();

            // Variáveis do usuário
            $this->title        = $this->get_option('title');
            $this->description  = $this->get_option('description');
            $this->instructions = $this->get_option('instructions');
            $this->order_status = $this->get_option('order_status', 'completed');
            $this->api_key = $this->get_option('api_key');

            // Ações 
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

            add_action('woocommerce_api_' . strtolower($this->id), array($this, 'payment_callback'));

            add_action('woocommerce_order_status_failed', array($this, 'cancel_payment'));
            add_action('woocommerce_order_status_cancelled', array($this, 'cancel_payment'));

            // Email para o cliente
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 4);
        }

        /**
         * Inicializando os campos do formulário.
         */
        public function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Ativar/Desativar', $this->domain),
                    'type'    => 'checkbox',
                    'label'   => __('Ativar ou desativar o Plugin', $this->domain),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => __('Título', $this->domain),
                    'type'        => 'text',
                    'description' => __('Título que o usuário vê durante o checkout.', $this->domain),
                    'default'     => __('', $this->domain),
                    'desc_tip'    => true,
                ),
                'api_key' => array(
                    'title'       => __('Chave API PagCripto', $this->domain),
                    'type'        => 'text',
                    'description' => __('Sua chave de API para identificar suas transações dentro da plataforma.', $this->domain),
                    'default'     => __('', $this->domain),
                    'desc_tip'    => true,
                ),
                'order_status' => array(
                    'title'       => __('Status pós Checkout', $this->domain),
                    'type'        => 'select',
                    'class'       => 'wc-enhanced-select',
                    'description' => __('Escolha o status que deseja após a finalização da compra.', $this->domain),
                    'default'     => 'wc-pending',
                    'desc_tip'    => true,
                    'options'     => wc_get_order_statuses()
                ),
                'description' => array(
                    'title'       => __('Descrição', $this->domain),
                    'type'        => 'textarea',
                    'description' => __('Descrição da forma de pagamento que o cliente verá em sua finalização da compra.', $this->domain),
                    'default'     => __('', $this->domain),
                    'desc_tip'    => true,
                ),
                'instructions' => array(
                    'title'       => __('Mensagem Personalizada', $this->domain),
                    'type'        => 'textarea',
                    'description' => __('Informações que serão adicionadas à página de agradecimento e aos e-mails.', $this->domain),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
            );
        }

        /**
         * Página de redirecionamento para ordens recebidas.
         */
        public function thankyou_page($order_id)
        {
            echo '<script>console.log("Nº do pedido: ' . $order_id . '")</script>';
            $order = new WC_Order($order_id);
            $method = get_post_meta($order->get_id(), '_payment_method', true);
            if ($method != 'pagcripto')
                return;

            $select_coin = get_post_meta($order->get_id(), 'select_coin', true);
            $wallet = get_post_meta($order->get_id(), 'wallet', true);
            $currency = get_post_meta($order->get_id(), 'currency', true);
            $amount = get_post_meta($order->get_id(), 'amount', true);
            $amount_brl = get_post_meta($order->get_id(), 'valor_brl', true);

            $payment_request = get_post_meta($order->get_id(), 'payment_request', true);

            $qrcode = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . $currency . ':' . $wallet . '&amount=' . $amount;
            $link = 'https://pagcripto.com.br/pagamento.php?wallet=' . $wallet;

?>
            <div>

                <h2 class="woocommerce-order-details__title">Instruções de Pagamento</h2>
                <p>Clique no botão abaixo para copiar seu link de pagamento ou leia o QRCode</p>
                <p>&#9888;&#65039; Seu link de pagamento expira após <strong>15 minutos</strong> a partir da hora da compra!</p>
                <p><strong> Moeda:</strong> <?php echo $select_coin; ?><br>
                    <strong>Valor em BRL :</strong> <?php echo number_format($amount_brl, 2); ?><br>
                    <strong>Valor em <?php echo $select_coin; ?> :</strong> <?php echo $amount; ?>
                </p>


                <button class="btn btn-warning" style="border: 1px solid #ccc" onclick="copy()">Copiar link de Pagamento</button>
                <br>
                <div style="padding-bottom:4px;display: none;" id="copy-alert">
                    <span style="color: #1e3464;">👉</span> <span><b> Link Copiado!</b></span>
                </div>

                <!-- <p>Link de Pagamento: </p> -->
                <input type="hidden" id="txt" value="<?php echo $link; ?>">

                <!-- <p>Texto QRCode: </p> -->
                <input type="hidden" id="txt_qrcode" value="<?php echo $qrcode; ?>">
                <img style="margin: 10px 0 20px 0" src="<?php echo $qrcode; ?>" />

                <div style="padding:6px 10px; background: #ccc;border-radius:6px;text-align: center;">
                    <h3 style="margin:0;padding:0"><strong>Obrigado</strong> por utilizar a PagCripto &#128588;</h3>
                </div>

                <hr>

            </div>

            <script>
                function copy() {
                    var myText = document.getElementById("txt");
                    console.log(myText.value)
                    document.body.appendChild(myText)
                    myText.focus();
                    myText.select();
                    document.execCommand('copy');
                    document.getElementById("copy-alert").style.display = 'block';
                }
            </script>
        <?php
        }

        // public function modify_price()
        // {
        //     add_filter('woocommerce_cart_total', 'wc_modify_cart_price');
        // }

        /**
         * Conteúdo do email do WC.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order_id, $sent_to_admin, $plain_text = false,  $email)
        {
            $order = new WC_Order($order_id);
            if ($this->instructions && !$sent_to_admin && 'pagcripto' === $order->get_payment_method() && $order->has_status('wc-pending')) {
                $select_coin = get_post_meta($order->get_id(), 'select_coin', true);
                $wallet = get_post_meta($order->get_id(), 'wallet', true);
                $currency = get_post_meta($order->get_id(), 'currency', true);
                $amount = get_post_meta($order->get_id(), 'amount', true);

                $payment_request = get_post_meta($order->get_id(), 'payment_request', true);

                echo '<p><strong>Obrigado</strong> por utilizar a PagCripto ;)</p>';

                echo '<p><strong>' . __('Moeda') . ':</strong> ' . $select_coin . '</p>';

                echo '<p><strong>' . __('Requisição') . ':</strong> ' . $payment_request . '</p>';
                echo '<p><strong>' . __('Carteira') . ':</strong> <a href="https://pagcripto.com.br/pagamento.php?wallet=' . $wallet . '" target="_blank">' . $wallet . '</a></p>';

                echo '<p><strong>' . __('QRCode') . ':</strong> <p> <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . $currency . ':' . $wallet . '&amount=' . $amount . '"/>';
            }
        }

        public function payment_fields()
        {

            if ($description = $this->get_description()) {
                echo wpautop(wptexturize($description));
            }

            // function wc_modify_cart_price($price)
            // {
            //     //aqui faz os calculos para mostrar o preço total de acordo com a moeda escolhida
            //     return 'BTC 0.004';
            // }

        ?>
            <div id="pagcripto_input">
                <p class="form-row form-row-wide">
                    <label for="select_coin" class=""><?php _e('Selecione a Moeda', $this->domain); ?></label>
                    <select class="" name="select_coin" id="select_coin" placeholder="" value="">
                        <option value="BTC">
                            <p>BTC - Bitcoin</p>
                        </option>
                        <option value="DASH">
                            <p>DASH</p>
                        </option>
                        <option value="TBTC">
                            <p>Bitcoin Teste</p>
                        </option>
                        <option value="NANO">
                            <p>NANO</p>
                        </option>
                        <option value="BCH">
                            <p>BCH - Bitcoin Cash</p>
                        </option>
                        <option value="LTC">
                            <p>LTC - Litecoin</p>
                        </option>
                        <option value="DOGE">
                            <p>DOGE - Dogecoin</p>
                        </option>
                    </select>
                </p>
                <p class="form-row form-row-wide">
                    <span id="hash_coin"></span>
                </p>

                <input type="hidden" name="api_key" value="<?php echo htmlspecialchars($this->get_option('api_key')); ?>">

            </div>
            <!-- <script>
                jQuery(document).ready(function($) {

                    $('#select_coin').on('change', function() {

                        var option = $(this).find('option:selected').val();
                        var pair = '' + option;
                        var ajaxurl = 'https://api.pagcripto.com.br/v2/public/ticker/' + pair;

                        $.ajax({
                            url: ajaxurl,
                            dataType: "json"
                        }).done(function(data) {
                            console.log(data)
                            // $('#hash_coin').html(data.content);
                        })
                    })
                })
            </script> -->

<?php
        }

        public function process_payment($order_id)
        {
            global $woocommerce;
            $curl = curl_init();

            $order = new WC_Order($order_id);

            $status = 'wc-' === substr($this->order_status, 0, 3) ? substr($this->order_status, 3) : $this->order_status;

            // Status da ordem
            $order->update_status($status, __('Checkout with pagcripto payment. ', $this->domain));

            $coin = get_post_meta($order->get_id(), 'select_coin', true);
            $amount = $woocommerce->cart->get_cart_contents_total();

            $urlhook = str_replace('http://', 'https://', get_site_url());

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.pagcripto.com.br/v2/gateway/createPayment',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => array(
                    'currency' => $coin,
                    'amount' => $amount,
                    'description' => 'Pedido ' . $order_id,
                    'callback' =>  $urlhook . '/wc-api/' . $this->id
                ),
                CURLOPT_HTTPHEADER => array(
                    'X-Authentication: ' . $this->get_option('api_key')
                ),
            ));


            if ($request = curl_exec($curl)) {

                $request = json_decode($request, true);

                $coins_list = array(
                    'btc' => 'bitcoin',
                    'tbtc' => 'bitcoin',
                    'bch' => 'bitcoincash',
                    'ltc' => 'litecoin',
                    'dash' => 'dash',
                    'doge' => 'dogecoin',
                    'nano' => 'nano'
                );


                update_post_meta($order_id, 'valor_brl', $amount);
                update_post_meta($order_id, 'wallet', $request['payment-details']['address']);
                update_post_meta($order_id, 'currency', $coins_list[$request['payment-details']['currency']]);
                update_post_meta($order_id, 'amount', $request['payment-details']['amount']);
                update_post_meta($order_id, 'payment_request', $request['payment-details']['payment_request']);

                $woocommerce->cart->empty_cart();

                curl_close($curl);

                //Redirecionamento para página de sucesso
                return array(
                    'result'    => 'success',
                    'redirect'  => $this->get_return_url($order)
                );
            } else {
                wc_add_notice(__('Payment error:', $this->domain), 'error');
                curl_close($curl);
                return;
            }
        }

        public function payment_callback()
        {
            header('HTTP/1.1 200 OK');
            $order_id = isset($_POST['order_id']) ? $_POST['order_id'] : null;
            if (is_null($order_id)) return;

            write_log('Callback via API');
            write_log('Pedido nº ' . $_POST['order_id']);

            $order = new WC_Order($order_id);
            $order->payment_complete();
            $order->update_status('completed', '', true);
            wc_reduce_stock_levels($order);
        }
    }
}

add_filter('woocommerce_payment_gateways', 'add_pagcripto_gateway_class');
function add_pagcripto_gateway_class($methods)
{
    $methods[] = 'WC_Gateway_Custom';
    return $methods;
}

add_action('woocommerce_checkout_process', 'process_pagcripto_payment');
function process_pagcripto_payment()
{
    if ($_POST['payment_method'] != 'pagcripto')
        return;

    if (!isset($_POST['select_coin']) || empty($_POST['select_coin']))
        wc_add_notice(__('Selecione uma Moeda', $this->domain), 'error');
}

/**
 * Atualizando o pedido 
 */
add_action('woocommerce_checkout_update_order_meta', 'pagcripto_payment_update_order_meta');

function pagcripto_payment_update_order_meta($order_id)
{
    if ($_POST['payment_method'] != 'pagcripto')
        return;

    // echo "<pre>";
    // print_r($_POST);

    update_post_meta($order_id, 'select_coin', $_POST['select_coin']);
}

/**
 * Campos para o usuário preencher
 */
add_action('woocommerce_admin_order_data_after_billing_address', 'pagcripto_checkout_field_display_admin_order_meta', 10, 1);
function pagcripto_checkout_field_display_admin_order_meta($order)
{
    $method = get_post_meta($order->get_id(), '_payment_method', true);
    if ($method != 'pagcripto')
        return;

    $select_coin = get_post_meta($order->get_id(), 'select_coin', true);
    $wallet = get_post_meta($order->get_id(), 'wallet', true);
    $currency = get_post_meta($order->get_id(), 'currency', true);
    $amount = get_post_meta($order->get_id(), 'amount', true);

    $payment_request = get_post_meta($order->get_id(), 'payment_request', true);

    echo '<p><strong>' . __('Moeda') . ':</strong> ' . $select_coin . '</p>';

    echo '<p><strong>' . __('Requisição') . ':</strong> ' . $payment_request . '</p>';
    echo '<p><strong>' . __('Carteira') . ':</strong> <a href="https://pagcripto.com.br/pagamento.php?wallet=' . $wallet . '" target="_blank">' . $wallet . '</a></p>';

    echo '<p><strong>' . __('QRCode') . ':</strong> <p> <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . $currency . ':' . $wallet . '&amount=' . $amount . '"/>';
}
