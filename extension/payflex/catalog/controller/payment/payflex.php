<?php
namespace Opencart\Catalog\Controller\Extension\Payflex\Payment;

/**
 * Payflex Catalog Controller
 *
 * Handles all customer-facing payment actions:
 *   index()               – Renders the payment method button at checkout
 *   confirm()             – Creates the Payflex order and returns the redirect URL (AJAX)
 *   callback()            – Handles the redirect back from Payflex (success or cancel)
 *   cron()                – Checks abandoned/pending orders on a schedule
 *   eventProductWidget()  – OC4 event: injects the installment widget on product pages
 */
class Payflex extends \Opencart\System\Engine\Controller {

    // -------------------------------------------------------------------------
    // Checkout – Payment Form
    // -------------------------------------------------------------------------

    /**
     * Returns the HTML for the payment option shown during checkout confirmation.
     */
    public function index(): string {
        $this->load->language('extension/payflex/payment/payflex');

        $data['language']       = $this->config->get('config_language');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_loading']   = $this->language->get('text_loading');

        return $this->load->view('extension/payflex/payment/payflex', $data);
    }

    // -------------------------------------------------------------------------
    // Checkout – Confirm (AJAX)
    // -------------------------------------------------------------------------

    /**
     * Called via AJAX when the customer clicks "Pay with Payflex".
     * Creates the Payflex order and returns the redirect URL.
     */
    public function confirm(): void {
        $this->load->language('extension/payflex/payment/payflex');

        $json = [];

        // Basic session validation
        if (empty($this->session->data['order_id'])) {
            $json['error'] = $this->language->get('error_order');
        }

        if (
            empty($this->session->data['payment_method']['code'])
            || $this->session->data['payment_method']['code'] !== 'payflex.payflex'
        ) {
            $json['error'] = $this->language->get('error_payment_method');
        }

        if (!$json) {
            $this->load->model('extension/payflex/payment/payflex');
            $this->load->model('checkout/order');

            $order_id   = (int)$this->session->data['order_id'];
            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                $json['error'] = $this->language->get('error_order');
            } else {
                // Block retry if the order was already approved to prevent duplicate payment
                $existing = $this->model_extension_payflex_payment_payflex->getPayflexOrder($order_id);
                if ($existing && $existing['status'] === 'Approved') {
                    $json['redirect'] = $this->url->link(
                        'checkout/success',
                        'language=' . $this->config->get('config_language'),
                        true
                    );
                    $this->response->addHeader('Content-Type: application/json');
                    $this->response->setOutput(json_encode($json));
                    return;
                }

                $products = $this->model_checkout_order->getProducts($order_id);

                $confirm_url = $this->url->link(
                    'extension/payflex/payment/payflex.callback',
                    'language=' . $this->config->get('config_language') . '&order_id=' . $order_id . '&status=confirmed',
                    true
                );

                $cancel_url = $this->url->link(
                    'extension/payflex/payment/payflex.callback',
                    'language=' . $this->config->get('config_language') . '&order_id=' . $order_id . '&status=cancelled',
                    true
                );

                $payflex = $this->model_extension_payflex_payment_payflex->createOrder(
                    $order_info,
                    $products,
                    $confirm_url,
                    $cancel_url
                );

                if (empty($payflex['orderId']) || empty($payflex['redirectUrl'])) {
                    $json['error'] = $this->language->get('error_create_order');
                } elseif (!$this->isValidPayflexRedirectUrl($payflex['redirectUrl'])) {
                    $json['error'] = $this->language->get('error_create_order');
                } else {
                    // Persist Payflex order data so we can verify the callback later
                    $this->model_extension_payflex_payment_payflex->addPayflexOrder(
                        $order_id,
                        $payflex['orderId'],
                        (float)$order_info['total']
                    );

                    // Mark OC4 order as pending
                    $this->model_checkout_order->addHistory(
                        $order_id,
                        $this->orderStatusId('payment_payflex_order_status_pending_id'),
                        'Payflex order created. Awaiting customer payment.',
                        false
                    );

                    $json['redirect'] = $payflex['redirectUrl'];
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // -------------------------------------------------------------------------
    // Checkout – Callback (return from Payflex)
    // -------------------------------------------------------------------------

    /**
     * Called when Payflex redirects the customer back to the store.
     * Verifies the order status with the Payflex API before updating OC4.
     *
     * Payflex will call either:
     *   redirectConfirmUrl  → ?status=confirmed
     *   redirectCancelUrl   → ?status=cancelled
     *
     * We always verify with the API regardless of the incoming status parameter
     * to prevent trivial manipulation of the URL.
     */
    public function callback(): void {
        $this->load->language('extension/payflex/payment/payflex');
        $this->load->model('extension/payflex/payment/payflex');
        $this->load->model('checkout/order');

        $order_id        = (int)($this->request->get['order_id'] ?? 0);
        $incoming_status = $this->request->get['status'] ?? '';

        // Reject obviously bad requests
        if (!$order_id || !in_array($incoming_status, ['confirmed', 'cancelled'], true)) {
            $this->redirectToCart();
            return;
        }

        $payflex_record = $this->model_extension_payflex_payment_payflex->getPayflexOrder($order_id);

        if (!$payflex_record) {
            $this->redirectToCart();
            return;
        }

        // Always confirm status with Payflex API
        $remote = $this->model_extension_payflex_payment_payflex->getOrder($payflex_record['payflex_id']);
        $remote_status = $remote['orderStatus'] ?? '';

        switch ($remote_status) {
            case 'Approved':
                $this->handleApproved($order_id);
                break;

            case 'Declined':
                $this->handleFailed($order_id, 'failed');
                break;

            case 'Cancelled':
                $this->handleFailed($order_id, 'cancelled');
                break;

            case 'Abandoned':
                $this->handleFailed($order_id, 'expired');
                break;

            default:
                // Status is still 'Created' or unknown – send back to checkout
                $this->redirectToCheckout('Unable to confirm your payment status. Please try again.');
                break;
        }
    }

    // -------------------------------------------------------------------------
    // CRON – Abandoned Order Check
    // -------------------------------------------------------------------------

    /**
     * Checks orders that were initiated 30 minutes to 2 hours ago and are still
     * in "Initiated" state. Called by an external cron job.
     *
     * Recommended cron schedule: every 5 minutes.
     *
     * Example URL:
     *   https://yourstore.com/index.php?route=extension/payflex/payment/payflex.cron&cron_token=YOUR_SECRET
     */
    public function cron(): void {
        $cron_token = $this->config->get('payment_payflex_cron_token');
        $provided   = $this->request->get['cron_token'] ?? '';

        if (empty($cron_token) || !hash_equals($cron_token, $provided)) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('Forbidden');
            return;
        }

        $this->load->model('extension/payflex/payment/payflex');
        $this->load->model('checkout/order');

        $pending   = $this->model_extension_payflex_payment_payflex->getPendingOrders();
        $processed = 0;

        foreach ($pending as $record) {
            $order_id = (int)$record['order_id'];
            $remote   = $this->model_extension_payflex_payment_payflex->getOrder($record['payflex_id']);
            $status   = $remote['orderStatus'] ?? '';

            switch ($status) {
                case 'Approved':
                    if ($this->model_extension_payflex_payment_payflex->claimOrderStatus($order_id, 'Approved')) {
                        $this->model_checkout_order->addHistory(
                            $order_id,
                            $this->orderStatusId('payment_payflex_order_status_approved_id'),
                            'Payflex payment approved (CRON check).',
                            true
                        );
                        $processed++;
                    }
                    break;

                case 'Abandoned':
                    if ($this->model_extension_payflex_payment_payflex->claimOrderStatus($order_id, 'Abandoned')) {
                        $this->model_checkout_order->addHistory(
                            $order_id,
                            $this->orderStatusId('payment_payflex_order_status_expired_id'),
                            'Payflex order abandoned (CRON check).',
                            false
                        );
                        $processed++;
                    }
                    break;

                case 'Declined':
                    if ($this->model_extension_payflex_payment_payflex->claimOrderStatus($order_id, 'Declined')) {
                        $this->model_checkout_order->addHistory(
                            $order_id,
                            $this->orderStatusId('payment_payflex_order_status_failed_id'),
                            'Payflex payment declined (CRON check).',
                            false
                        );
                        $processed++;
                    }
                    break;

                case 'Cancelled':
                    if ($this->model_extension_payflex_payment_payflex->claimOrderStatus($order_id, 'Cancelled')) {
                        $this->model_checkout_order->addHistory(
                            $order_id,
                            $this->orderStatusId('payment_payflex_order_status_cancelled_id'),
                            'Payflex payment cancelled (CRON check).',
                            false
                        );
                        $processed++;
                    }
                    break;

                // 'Created' / 'Initiated' – still in progress; leave as-is
            }
        }

        // Verbose output only when debug mode is on AND the request includes a
        // user_token that matches the active admin session — ensuring only a
        // logged-in admin who explicitly passes their token sees the detail.
        // Automated cron jobs (no session, no user_token) always get plain OK.
        $request_token = $this->request->get['user_token'] ?? '';
        $debug = $this->config->get('payment_payflex_debug')
            && $request_token !== ''
            && isset($this->session->data['user_token'])
            && hash_equals($this->session->data['user_token'], $request_token);

        if ($debug) {
            $this->response->setOutput('OK. Processed: ' . $processed . ' / ' . count($pending) . ' orders.');
        } else {
            $this->response->setOutput('OK');
        }
    }

    // -------------------------------------------------------------------------
    // OC4 Event – Product Widget
    // -------------------------------------------------------------------------

    /**
     * Injected into the product page via the OC4 event system.
     * Trigger: catalog/view/template/product/product/after
     *
     * Appends the Payflex installment widget script to the rendered product page HTML.
     * The widget reads the current displayed price from the DOM and updates itself.
     */
    public function eventProductWidget(string &$route, array &$data, string &$output): void {
        if (!$this->config->get('payment_payflex_product_widget')) {
            return;
        }

        $merchant_ref = $this->config->get('payment_payflex_widget_merchant_ref');
        $style        = $this->config->get('payment_payflex_widget_style') ?: 'purple';
        $theme        = $this->config->get('payment_payflex_widget_theme') ?: '';
        $pay_type     = $this->config->get('payment_payflex_widget_pay_type') ?: '4';

        // Build the widget script base URL
        if ($merchant_ref) {
            $widget_base = 'https://widgets.payflex.co.za/' . rawurlencode($merchant_ref) . '/2.0.3/payflex-widget.js';
        } else {
            $widget_base = 'https://widgets.payflex.co.za/2.0.3/payflex-widget.js';
        }

        // Build query params; amount=0 is a placeholder that the JS replaces immediately.
        // Note: do NOT use array_filter() here — it would strip '0' as falsy.
        $query_params = [
            'type'      => 'calculator',
            'logo_type' => $style,
            'pay_type'  => $pay_type,
            'amount'    => '0',
        ];

        if ($theme !== '') {
            $query_params['theme'] = $theme;
        }

        $widget_params = http_build_query($query_params);

        $widget_url = $widget_base . '?' . $widget_params;

        $widget_html = $this->load->view('extension/payflex/payment/payflex_widget', [
            'widget_url' => $widget_url,
            'pay_type'   => $pay_type,
        ]);

        // Inject the widget before the product form (#product div)
        $output = str_replace('<div id="product">', $widget_html . "\n" . '<div id="product">', $output);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function handleApproved(int $order_id): void {
        // claimOrderStatus atomically transitions Initiated → Approved.
        // If it returns false, another request already processed this order.
        if ($this->model_extension_payflex_payment_payflex->claimOrderStatus($order_id, 'Approved')) {
            $this->model_checkout_order->addHistory(
                $order_id,
                $this->orderStatusId('payment_payflex_order_status_approved_id'),
                'Payflex payment approved.',
                true
            );
        }

        unset($this->session->data['order_id']);

        $this->response->redirect(
            $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true)
        );
    }

    private function handleFailed(int $order_id, string $payflex_status): void {
        [$oc_status, $db_status, $lang_key] = match ($payflex_status) {
            'cancelled' => ['payment_payflex_order_status_cancelled_id', 'Cancelled', 'text_payment_cancelled'],
            'expired'   => ['payment_payflex_order_status_expired_id',   'Abandoned', 'text_payment_abandoned'],
            default     => ['payment_payflex_order_status_failed_id',    'Declined',  'text_payment_declined'],
        };

        $message = $this->language->get($lang_key);

        if ($this->model_extension_payflex_payment_payflex->claimOrderStatus($order_id, $db_status)) {
            $this->model_checkout_order->addHistory(
                $order_id,
                $this->orderStatusId($oc_status),
                'Payflex: ' . $message,
                false
            );
        }

        $this->redirectToCheckout($message);
    }

    /**
     * Returns the configured OC4 order status ID for the given setting key,
     * falling back to standard OC4 defaults if the setting is empty or zero.
     */
    private function orderStatusId(string $key): int {
        $id = (int)$this->config->get($key);
        if ($id > 0) {
            return $id;
        }
        // Standard OC4 order status IDs used as fallback
        $defaults = [
            'payment_payflex_order_status_pending_id'   => 1,  // Pending
            'payment_payflex_order_status_approved_id'  => 2,  // Processing
            'payment_payflex_order_status_failed_id'    => 10, // Failed
            'payment_payflex_order_status_cancelled_id' => 7,  // Canceled
            'payment_payflex_order_status_expired_id'   => 14, // Expired
        ];
        return $defaults[$key] ?? 0;
    }

    private function redirectToCart(): void {
        $this->response->redirect(
            $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'), true)
        );
    }

    private function redirectToCheckout(string $error = ''): void {
        if ($error) {
            $this->session->data['error'] = $error;
        }

        $this->response->redirect(
            $this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language'), true)
        );
    }

    /**
     * Validates that a redirect URL returned by the Payflex API is on a known
     * Payflex domain before we pass it to the customer's browser.
     * Prevents an open redirect if the API response were ever tampered with.
     */
    private function isValidPayflexRedirectUrl(string $url): bool {
        $parsed = parse_url($url);

        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        if ($parsed['scheme'] !== 'https') {
            return false;
        }

        $host = strtolower($parsed['host']);

        return $host === 'payflex.co.za'
            || str_ends_with($host, '.payflex.co.za');
    }
}
