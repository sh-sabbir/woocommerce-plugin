<?php

declare(strict_types=1);

defined('ABSPATH') or exit;

if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

use CoinGate\Exception\ApiErrorException;
use CoinGate\Client;

/**
 * The functionality of the coingate payment gateway.
 *
 * @since      1.0.0
 * @package    Coingate_For_Woocommerce
 * @subpackage Coingate_For_Woocommerce/includes
 * @author     CoinGate <support@coingate.com>
 */
class Coingate_Payment_Gateway extends WC_Payment_Gateway
{

    public const ORDER_TOKEN_META_KEY = 'coingate_order_token';

    public const SETTINGS_KEY = 'woocommerce_coingate_settings';

    /**
     * Coingate_Payment_Gateway constructor.
     */
    public function __construct() {
        $this->id = 'coingate';
        $this->has_fields = false;
        $this->method_title = 'CoinGate';
        $this->icon = apply_filters('woocommerce_coingate_icon', COINGATE_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/bitcoin.png');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_secret = $this->get_option('api_secret');
        $this->api_auth_token = (empty($this->get_option('api_auth_token')) ? $this->get_option('api_secret') : $this->get_option('api_auth_token'));
        $this->receive_currency = $this->get_option('receive_currency');
        $this->order_statuses = $this->get_option('order_statuses');
        $this->test = ('yes' === $this->get_option('test', 'no'));

        add_action('woocommerce_update_options_payment_gateways_coingate', array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_payment_gateways_coingate', array($this, 'save_order_statuses'));
        add_action('woocommerce_thankyou_coingate', array($this, 'thankyou'));
        add_action('woocommerce_api_wc_gateway_coingate', array($this, 'payment_callback'));
    }

    /**
     * Output the gateway settings screen.
     */
    public function admin_options() {
        ?>
        <h3><?php _e('CoinGate', COINGATE_TRANSLATIONS); ?></h3>
        <p><?php _e('Accept Bitcoin through the CoinGate.com and receive payments in euros and US dollars.<br>
        <a href="https://developer.coingate.com/docs/issues" target="_blank">Not working? Common issues</a> &middot; <a href="mailto:support@coingate.com">support@coingate.com</a>', COINGATE_TRANSLATIONS); ?></p>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }

    /**
     * Initialise settings form fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable CoinGate', COINGATE_TRANSLATIONS),
                'label' => __('Enable Cryptocurrency payments via CoinGate', COINGATE_TRANSLATIONS),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ),
            'description' => array(
                'title' => __('Description', COINGATE_TRANSLATIONS),
                'type' => 'textarea',
                'description' => __('The payment method description which a user sees at the checkout of your store.', COINGATE_TRANSLATIONS),
                'default' => __('Pay with BTC, LTC, ETH, XMR, XRP, BCH and other cryptocurrencies. Powered by CoinGate.', COINGATE_TRANSLATIONS),
            ),
            'title' => array(
                'title' => __('Title', COINGATE_TRANSLATIONS),
                'type' => 'text',
                'description' => __('The payment method title which a customer sees at the checkout of your store.', COINGATE_TRANSLATIONS),
                'default' => __('Cryptocurrencies via CoinGate (more than 50 supported)', COINGATE_TRANSLATIONS),
            ),
            'api_auth_token' => array(
                'title' => __('API Auth Token', COINGATE_TRANSLATIONS),
                'type' => 'text',
                'description' => __('CoinGate API Auth Token', COINGATE_TRANSLATIONS),
                'default' => (empty($this->get_option('api_secret')) ? '' : $this->get_option('api_secret')),
            ),
            'receive_currency' => array(
                'title' => __('Payout Currency', COINGATE_TRANSLATIONS),
                'type' => 'select',
                'options' => array(
                    'BTC' => __('Bitcoin (฿)', COINGATE_TRANSLATIONS),
                    'USDT' => __('USDT', COINGATE_TRANSLATIONS),
                    'EUR' => __('Euros (€)', COINGATE_TRANSLATIONS),
                    'USD' => __('U.S. Dollars ($)', COINGATE_TRANSLATIONS),
                    'DO_NOT_CONVERT' => __('Do not convert', COINGATE_TRANSLATIONS)
                ),
                'description' => __('Choose the currency in which your payouts will be made (BTC, EUR or USD). For real-time EUR or USD settlements, you must verify as a merchant on CoinGate. Do not forget to add your Bitcoin address or bank details for payouts on <a href="https://coingate.com" target="_blank">your CoinGate account</a>.', COINGATE_TRANSLATIONS),
                'default' => 'BTC',
            ),
            'order_statuses' => array(
                'type' => 'order_statuses'
            ),
            'test' => array(
                'title' => __('Test (Sandbox)', COINGATE_TRANSLATIONS),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode (Sandbox)', COINGATE_TRANSLATIONS),
                'default' => 'no',
                'description' => __('To test on <a href="https://sandbox.coingate.com" target="_blank">CoinGate Sandbox</a>, turn Test Mode "On".
                    Please note, for Test Mode you must create a separate account on <a href="https://sandbox.coingate.com" target="_blank">sandbox.coingate.com</a> and generate API credentials there.
                    API credentials generated on <a href="https://coingate.com" target="_blank">coingate.com</a> are "Live" credentials and will not work for "Test" mode.', COINGATE_TRANSLATIONS),
            ),
        );
    }

    /**
     * Thank you page.
     */
    public function thankyou() {
        if ($description = $this->get_description()) {
            echo "<p>" . esc_html($description) . "</p>";
        }
    }

    /**
     * Validate api_auth_token field.
     *
     * @param $key
     * @param $value
     * @return string
     */
    public function validate_api_auth_token_field($key, $value) {
        $post_data = $this->get_post_data();
        $mode = $post_data['woocommerce_coingate_test'];

        if (!empty($value)) {
            $client = new Client();
            $result = $client::testConnection($value, (bool)$mode);

            if ($result) {
                return $value;
            }
        }

        WC_Admin_Settings::add_error( esc_html__( 'API Auth Token is invalid. Your changes have not been saved.', COINGATE_TRANSLATIONS ) );

        return '';
    }

    /**
     * Payment process.
     *
     * @param int $order_id
     * @return string[]
     */
    public function process_payment($order_id) {
        global $woocommerce, $page, $paged;
        $order = wc_get_order($order_id);

        $client = $this->init_coingate();

        $description = array();
        foreach ($order->get_items() as $item) {
            $description[] = $item['qty'] . ' × ' . $item['name'];
        }

        $params = [
            'order_id'          => $order->get_id(),
            'price_amount'      => $order->get_total(),
            'price_currency'    => $order->get_currency(),
            'receive_currency'  => $this->receive_currency,
            'callback_url'      => trailingslashit(get_bloginfo('wpurl')) . '?wc-api=wc_gateway_coingate',
            'cancel_url'        => $this->get_cancel_order_url($order),
            'success_url'       => add_query_arg('order-received', $order->get_id(), add_query_arg('key', $order->get_order_key(), $this->get_return_url($order))),
            'title'             => get_bloginfo('name', 'raw') . ' Order #' . $order->get_id(),
            'description'       => implode(', ', $description)
        ];

        $response = ['result' => 'fail'];

        try {
            $gateway_order = $client->order->create($params);
            if ($gateway_order) {
                update_post_meta($order->get_id(), static::ORDER_TOKEN_META_KEY, $gateway_order->token);

                $response['result'] = 'success';
                $response['redirect'] = $gateway_order->payment_url;
            }

        } catch (ApiErrorException $exception) {
            error_log($exception);
        }

        return $response;
    }

    /**
     * Payment callback.
     *
     * @throws Exception
     */
    public function payment_callback() {
        $request = $_POST;
        $order = wc_get_order(sanitize_text_field($request['order_id']));
        
        if (!$this->is_token_valid($order, sanitize_text_field($request['token']))) {
            throw new Exception('CoinGate callback token does not match');
        }

        if (!$order || !$order->get_id()) {
            throw new Exception('Order #' . $order->get_id() . ' does not exists');
        }

        if ($order->get_payment_method() !== $this->id) {
            throw new Exception('Order #' . $order->get_id() . ' payment method is not ' . $this->method_title);
        }

        // Get payment data from request due to security reason.
        $client = $this->init_coingate();
        $cg_order = $client->order->get((int) sanitize_key($request['id']));
        if (!$cg_order || $order->get_id() !== (int)$cg_order->order_id) {
            throw new Exception('CoinGate Order #' . $order->get_id() . ' does not exists.');
        }

        $callback_order_status = sanitize_text_field($cg_order->status);

        $order_statuses = $this->get_option('order_statuses');
        $wc_order_status = isset($order_statuses[$callback_order_status]) ? $order_statuses[$callback_order_status] : NULL;
        if (!$wc_order_status) {
            return;
        }

        switch ($callback_order_status) {
            case 'paid':
                if (!$this->is_order_paid_status_valid($order, $cg_order->price_amount)) {
                    throw new Exception('CoinGate Order #' . $order->get_id() . ' amounts do not match');
                }

                $status_was = "wc-" . $order->get_status();

                $this->handle_order_status($order, $wc_order_status);
                $order->add_order_note(__('Payment is confirmed on the network, and has been credited to the merchant. Purchased goods/services can be securely delivered to the buyer.', COINGATE_TRANSLATIONS));
                $order->payment_complete();

                $wc_expired_status = $order_statuses['expired'];
                $wc_canceled_status = $order_statuses['canceled'];

                if ($order->status == 'processing' && ($status_was == $wc_expired_status || $status_was == $wc_canceled_status)) {
                    WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id());
                }
                if (($order->status == 'processing' || $order->status == 'completed') && ($status_was == $wc_expired_status || $status_was == $wc_canceled_status)) {
                    WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id());
                }
                break;
            case 'confirming' :
                $this->handle_order_status($order, $wc_order_status);
                $order->add_order_note(__('Shopper transferred the payment for the invoice. Awaiting blockchain network confirmation.', COINGATE_TRANSLATIONS));
                break;
            case 'invalid':
                $this->handle_order_status($order, $wc_order_status);
                $order->add_order_note(__('Payment rejected by the network or did not confirm within 7 days.', COINGATE_TRANSLATIONS));
                break;
            case 'expired':
                $this->handle_order_status($order, $wc_order_status);
                $order->add_order_note(__('Buyer did not pay within the required time and the invoice expired.', COINGATE_TRANSLATIONS));
                break;
            case 'canceled':
                $this->handle_order_status($order, $wc_order_status);
                $order->add_order_note(__('Buyer canceled the invoice.', COINGATE_TRANSLATIONS));
                break;
            case 'refunded':
                $this->handle_order_status($order, $wc_order_status);
                $order->add_order_note(__('Payment was refunded to the buyer.', COINGATE_TRANSLATIONS));
                break;
        }
    }

    /**
     * Generates a URL so that a customer can cancel their (unpaid - pending) order.
     *
     * @param WC_Order $order Order.
     * @param string $redirect Redirect URL.
     * @return string
     */
    public function get_cancel_order_url($order, $redirect = '' ) {
        return apply_filters(
            'woocommerce_get_cancel_order_url',
            wp_nonce_url(
                add_query_arg(
                    array(
                        'order'        => $order->get_order_key(),
                        'order_id'     => $order->get_id(),
                        'redirect'     => $redirect,
                    ),
                    $order->get_cancel_endpoint()
                ),
                'woocommerce-cancel_order'
            )
        );
    }

    /**
     * Generate order statuses.
     *
     * @return false|string
     */
    public function generate_order_statuses_html() {
        ob_start();

        $cg_statuses = $this->coingate_order_statuses();
        $default_status['ignore'] = __('Do nothing', COINGATE_TRANSLATIONS);
        $wc_statuses = array_merge($default_status, wc_get_order_statuses());

        $default_statuses = array(
            'paid' => 'wc-processing',
            'confirming' => 'ignore',
            'invalid' => 'ignore',
            'expired' => 'ignore',
            'canceled' => 'ignore',
            'refunded' => 'ignore',
        );

        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"> <?php _e('Order Statuses:', COINGATE_TRANSLATIONS) ?></th>
            <td class="forminp" id="coingate_order_statuses">
                <table cellspacing="0">
                    <?php
                    foreach ($cg_statuses as $cg_status_name => $cg_status_title) {
                        ?>
                        <tr>
                            <th><?php echo esc_html($cg_status_title); ?></th>
                            <td>
                                <select name="woocommerce_coingate_order_statuses[<?php echo esc_html($cg_status_name); ?>]">
                                    <?php
                                    $cg_settings = get_option(static::SETTINGS_KEY);
                                    $order_statuses = $cg_settings['order_statuses'];

                                    foreach ($wc_statuses as $wc_status_name => $wc_status_title) {
                                        $current_status = isset( $order_statuses[$cg_status_name] ) ? $order_statuses[$cg_status_name] : "";

                                        if (empty($current_status))
                                            $current_status = $default_statuses[$cg_status_name];

                                        if ($current_status == $wc_status_name)
                                            echo "<option value='". esc_attr($wc_status_name) ."' selected>". esc_html($wc_status_title) ."</option>";
                                        else
                                            echo "<option value='". esc_attr($wc_status_name) ."'>". esc_html($wc_status_title) ."</option>";
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                </table>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    /**
     * Validate order statuses field.
     *
     * @return mixed|string
     */
    public function validate_order_statuses_field() {
        $order_statuses = $this->get_option('order_statuses');

        if (isset($_POST[$this->plugin_id . $this->id . '_order_statuses'])) {
            $order_statuses = $_POST[$this->plugin_id . $this->id . '_order_statuses'];
            return array_map( 'sanitize_text_field', $order_statuses );
        }

        return $order_statuses;
    }

    /**
     * Save order statuses.
     */
    public function save_order_statuses() {
        $coingate_order_statuses = $this->coingate_order_statuses();
        $wc_statuses = wc_get_order_statuses();

        if (isset($_POST['woocommerce_coingate_order_statuses'])) {
            $cg_settings = get_option(static::SETTINGS_KEY);
            $order_statuses = $cg_settings['order_statuses'];

            foreach ($coingate_order_statuses as $cg_status_name => $cg_status_title) {
                if (!isset($_POST['woocommerce_coingate_order_statuses'][$cg_status_name]))
                    continue;

                $wc_status_name = sanitize_text_field($_POST['woocommerce_coingate_order_statuses'][$cg_status_name]);

                if (array_key_exists($wc_status_name, $wc_statuses))
                    $order_statuses[$cg_status_name] = $wc_status_name;
            }

            $cg_settings['order_statuses'] = $order_statuses;
            update_option(static::SETTINGS_KEY, $cg_settings);
        }
    }

    /**
     * Handle order status.
     *
     * @param WC_Order $order
     * @param string $status
     */
    protected function handle_order_status(WC_Order $order, string $status) {
        if ($status !== 'ignore') {
            $order->update_status($status);
        }
    }

    /**
     * List of coingate order statuses.
     *
     * @return string[]
     */
    private function coingate_order_statuses() {
        return array(
            'paid' => 'Paid',
            'confirming' => 'Confirming',
            'invalid' => 'Invalid',
            'expired' => 'Expired',
            'canceled' => 'Canceled',
            'refunded' => 'Refunded',
        );
    }

    /**
     * Initial client.
     *
     * @return Client
     */
    private function init_coingate() {
        $auth_token = (empty($this->api_auth_token) ? $this->api_secret : $this->api_auth_token);
        $client = new Client($auth_token, $this->test);
        $client::setAppInfo('Coingate For Woocommerce', COINGATE_FOR_WOOCOMMERCE_VERSION);

        return $client;
    }

    /**
     * Check if order status is valid.
     *
     * @param WC_Order $order
     * @param $price
     * @return bool
     */
    private function is_order_paid_status_valid(WC_Order $order, $price) {
        return $order->get_total() >= (float) $price;
    }

    /**
     * Check token match.
     *
     * @param WC_Order $order
     * @param string $token
     * @return bool
     */
    private function is_token_valid(WC_Order $order, string $token) {
        $order_token = $order->get_meta(static::ORDER_TOKEN_META_KEY);

        return !empty($order_token) && $token === $order_token;

    }

}
