<?php
namespace Opencart\Catalog\Model\Extension\Payflex\Payment;

/**
 * Payflex Payment Model
 *
 * Handles all Payflex API communication and the oc_payflex_order database table.
 */
class Payflex extends \Opencart\System\Engine\Model {

    /** @var array Environment API endpoints */
    private array $environments = [
        'sandbox' => [
            'api_url'  => 'https://api.uat.payflex.co.za',
            'auth_url' => 'https://auth-uat.payflex.co.za/auth/merchant',
            'audience' => 'https://auth-dev.payflex.co.za',
        ],
        'production' => [
            'api_url'  => 'https://api.payflex.co.za',
            'auth_url' => 'https://auth.payflex.co.za/auth/merchant',
            'audience' => 'https://auth-production.payflex.co.za',
        ],
    ];

    // -------------------------------------------------------------------------
    // Payment Method Availability
    // -------------------------------------------------------------------------

    /**
     * Returns the Payflex payment method if it is available for the current cart total.
     * Called by OC4 during checkout to build the list of available payment methods.
     */
    public function getMethods(array $address = []): array {
        $this->load->language('extension/payflex/payment/payflex');

        if (!$this->config->get('payment_payflex_status')) {
            return [];
        }

        $total  = $this->cart->getTotal();
        $config = $this->getConfiguration();

        if ($config) {
            $min = (float)($config['minimumAmount'] ?? 0);
            $max = (float)($config['maximumAmount'] ?? PHP_INT_MAX);

            if ($min > 0 && $total < $min) {
                return [];
            }

            if ($max > 0 && $total > $max) {
                return [];
            }
        }

        return [
            'code'       => 'payflex',
            'name'       => $this->language->get('text_title'),
            'option'     => [
                'payflex' => [
                    'code' => 'payflex.payflex',
                    'name' => $this->language->get('text_title'),
                ],
            ],
            'sort_order' => $this->config->get('payment_payflex_sort_order'),
        ];
    }

    // -------------------------------------------------------------------------
    // Payflex API – Authentication
    // -------------------------------------------------------------------------

    /**
     * Returns a valid Payflex Bearer token, using OC4's cache to avoid
     * re-authenticating on every request.
     */
    public function getToken(): string {
        $cache_key = 'payflex.token.' . $this->getEnvironmentKey();
        $cached    = $this->cache->get($cache_key);

        if ($cached) {
            return $cached;
        }

        $env = $this->getEnvironmentConfig();

        $payload = json_encode([
            'client_id'     => $this->config->get('payment_payflex_client_id'),
            'client_secret' => $this->config->get('payment_payflex_client_secret'),
            'audience'      => $env['audience'],
            'grant_type'    => 'client_credentials',
        ]);

        $response = $this->httpPost($env['auth_url'], $payload);

        if (empty($response['access_token'])) {
            return '';
        }

        // Cache until 2 minutes before expiry
        $ttl = max(60, (int)($response['expires_in'] ?? 86400) - 120);
        $this->cache->set($cache_key, $response['access_token'], $ttl);

        return $response['access_token'];
    }

    // -------------------------------------------------------------------------
    // Payflex API – Configuration
    // -------------------------------------------------------------------------

    /**
     * Fetches the minimum and maximum order amounts from Payflex.
     * Cached for 24 hours to avoid repeated API calls during browsing.
     */
    public function getConfiguration(): array {
        $cache_key = 'payflex.config.' . $this->getEnvironmentKey();
        $cached    = $this->cache->get($cache_key);

        if ($cached) {
            return $cached;
        }

        $token = $this->getToken();

        if (!$token) {
            return [];
        }

        $env      = $this->getEnvironmentConfig();
        $response = $this->httpGet($env['api_url'] . '/configuration', $token);

        if ($response) {
            $this->cache->set($cache_key, $response, 86400);
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Payflex API – Orders
    // -------------------------------------------------------------------------

    /**
     * Creates a new order on Payflex and returns the response containing
     * the orderId, token, and redirectUrl.
     *
     * @param array  $order_info  OC4 order data (from model_checkout_order->getOrder())
     * @param array  $products    Order line items (from model_checkout_order->getProducts())
     * @param string $confirm_url URL Payflex redirects to on success
     * @param string $cancel_url  URL Payflex redirects to on cancellation
     */
    public function createOrder(array $order_info, array $products, string $confirm_url, string $cancel_url): array {
        $token = $this->getToken();

        if (!$token) {
            return [];
        }

        $env   = $this->getEnvironmentConfig();
        $items = [];

        foreach ($products as $product) {
            $items[] = [
                'name'     => $product['name'],
                'sku'      => $product['model'] ?? '',
                'quantity' => (int)$product['quantity'],
                'price'    => round((float)$product['price'], 2),
            ];
        }

        $payload = json_encode([
            'amount'            => round((float)$order_info['total'], 2),
            'consumer'          => [
                'phoneNumber' => $order_info['telephone'] ?? '',
                'givenNames'  => $order_info['payment_firstname'],
                'surname'     => $order_info['payment_lastname'],
                'email'       => $order_info['email'],
            ],
            'billing'           => [
                'addressLine1' => $order_info['payment_address_1'],
                'addressLine2' => $order_info['payment_address_2'] ?? '',
                'suburb'       => $order_info['payment_city'],
                'postcode'     => $order_info['payment_postcode'],
            ],
            'shipping'          => [
                'addressLine1' => $order_info['shipping_address_1'] ?? $order_info['payment_address_1'],
                'addressLine2' => $order_info['shipping_address_2'] ?? $order_info['payment_address_2'] ?? '',
                'suburb'       => $order_info['shipping_city'] ?? $order_info['payment_city'],
                'postcode'     => $order_info['shipping_postcode'] ?? $order_info['payment_postcode'],
            ],
            'items'             => $items,
            'merchant'          => [
                'redirectConfirmUrl' => $confirm_url,
                'redirectCancelUrl'  => $cancel_url,
            ],
            'merchantReference' => (string)$order_info['order_id'],
        ]);

        return $this->httpPost($env['api_url'] . '/order/productSelect', $payload, $token);
    }

    /**
     * Fetches the current status of a Payflex order from their API.
     * Used by the callback and the CRON job to verify order state.
     */
    public function getOrder(string $payflex_order_id): array {
        if (!$this->isValidPayflexOrderId($payflex_order_id)) {
            return [];
        }

        $token = $this->getToken();

        if (!$token) {
            return [];
        }

        $env = $this->getEnvironmentConfig();

        return $this->httpGet($env['api_url'] . '/order/' . $payflex_order_id, $token);
    }

    /**
     * Submits a refund request to Payflex.
     * Note: requires refunds to be enabled on the merchant account.
     */
    public function refundOrder(string $payflex_order_id, int $order_id, float $amount): array {
        if (!$this->isValidPayflexOrderId($payflex_order_id)) {
            return [];
        }

        $token = $this->getToken();

        if (!$token) {
            return [];
        }

        $env = $this->getEnvironmentConfig();
        $ref = 'Order #' . $order_id . '-' . substr(uniqid(), -6);

        $payload = json_encode([
            'requestId'               => $ref,
            'amount'                  => round($amount, 2),
            'merchantRefundReference' => $ref,
        ]);

        return $this->httpPost($env['api_url'] . '/order/' . $payflex_order_id . '/refund', $payload, $token);
    }

    // -------------------------------------------------------------------------
    // Database – oc_payflex_order
    // -------------------------------------------------------------------------

    /**
     * Saves (or updates) the Payflex order record for an OC4 order.
     * Uses INSERT … ON DUPLICATE KEY UPDATE so it is safe to call more than once.
     * Terminal statuses (Approved, Declined, Cancelled, Abandoned) are never
     * overwritten — only orders still in 'Initiated' state are reset.
     */
    public function addPayflexOrder(int $order_id, string $payflex_id, float $amount): void {
        $env = $this->getEnvironmentKey();

        $this->db->query("
            INSERT INTO `" . DB_PREFIX . "payflex_order`
                SET `order_id`      = '" . (int)$order_id . "',
                    `payflex_id`    = '" . $this->db->escape($payflex_id) . "',
                    `environment`   = '" . $this->db->escape($env) . "',
                    `status`        = 'Initiated',
                    `amount`        = '" . round((float)$amount, 2) . "',
                    `date_added`    = NOW(),
                    `date_modified` = NOW()
            ON DUPLICATE KEY UPDATE
                    `payflex_id`    = VALUES(`payflex_id`),
                    `environment`   = VALUES(`environment`),
                    `status`        = IF(`status` IN ('Approved','Declined','Cancelled','Abandoned'), `status`, 'Initiated'),
                    `amount`        = VALUES(`amount`),
                    `date_modified` = NOW()
        ");
    }

    /**
     * Atomically transitions an order from 'Initiated' to the given status.
     * Returns true only if this call performed the update (i.e. won the race).
     * Used by the callback and CRON to prevent duplicate processing when
     * concurrent requests arrive for the same order.
     */
    public function claimOrderStatus(int $order_id, string $status): bool {
        $this->db->query("
            UPDATE `" . DB_PREFIX . "payflex_order`
            SET    `status`        = '" . $this->db->escape($status) . "',
                   `date_modified` = NOW()
            WHERE  `order_id`      = '" . (int)$order_id . "'
              AND  `status`        = 'Initiated'
        ");

        return $this->db->countAffected() === 1;
    }

    /**
     * Returns the Payflex order record for a given OC4 order ID.
     */
    public function getPayflexOrder(int $order_id): array {
        $query = $this->db->query("
            SELECT * FROM `" . DB_PREFIX . "payflex_order`
            WHERE  `order_id` = '" . (int)$order_id . "'
        ");

        return $query->row ?: [];
    }

    /**
     * Returns Payflex orders that are still in 'Initiated' state and were
     * created between 30 minutes and 2 hours ago.
     *
     * This window avoids checking orders that the customer is still actively
     * completing, while also catching genuinely abandoned sessions.
     */
    public function getPendingOrders(): array {
        $query = $this->db->query("
            SELECT * FROM `" . DB_PREFIX . "payflex_order`
            WHERE  `status`     = 'Initiated'
              AND  `date_added` BETWEEN DATE_SUB(NOW(), INTERVAL 2 HOUR)
                                    AND DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ");

        return $query->rows;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validates a Payflex order ID before using it in a URL path.
     * Accepts UUIDs and alphanumeric IDs up to 64 characters.
     * Rejects anything containing path-traversal characters.
     */
    private function isValidPayflexOrderId(string $id): bool {
        return $id !== '' && (bool)preg_match('/^[a-zA-Z0-9\-]{8,64}$/', $id);
    }

    private function getEnvironmentKey(): string {
        return $this->config->get('payment_payflex_environment') === 'production' ? 'production' : 'sandbox';
    }

    private function getEnvironmentConfig(): array {
        return $this->environments[$this->getEnvironmentKey()];
    }

    /**
     * Makes an authenticated POST request to a Payflex API endpoint.
     * Pass $token = '' for the auth endpoint itself (which has no Bearer token).
     */
    private function httpPost(string $url, string $payload, string $token = ''): array {
        $headers = ['Content-Type: application/json'];

        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result    = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $http_code < 200 || $http_code >= 300) {
            return [];
        }

        return json_decode($result, true) ?: [];
    }

    /**
     * Makes an authenticated GET request to a Payflex API endpoint.
     */
    private function httpGet(string $url, string $token): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result    = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $http_code !== 200) {
            return [];
        }

        return json_decode($result, true) ?: [];
    }
}
