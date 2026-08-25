<?php
namespace Opencart\Admin\Controller\Extension\Payflex\Payment;

/**
 * Payflex Admin Controller
 *
 * Provides the settings page in the OC4 admin and handles install/uninstall.
 *
 * Routes:
 *   extension/payflex/payment/payflex         → index()
 *   extension/payflex/payment/payflex.save    → save()
 *   extension/payflex/payment/payflex.install → install()
 *   extension/payflex/payment/payflex.uninstall → uninstall()
 */
class Payflex extends \Opencart\System\Engine\Controller {

    // -------------------------------------------------------------------------
    // Settings Page
    // -------------------------------------------------------------------------

    public function index(): void {
        $this->load->language('extension/payflex/payment/payflex');
        $this->document->setTitle($this->language->get('heading_title'));

        $data['breadcrumbs'] = [
            [
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token']),
            ],
            [
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment'),
            ],
            [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/payflex/payment/payflex', 'user_token=' . $this->session->data['user_token']),
            ],
        ];

        $data['save'] = $this->url->link('extension/payflex/payment/payflex.save', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

        // --- Current setting values (fall back to sensible defaults) ---

        $settings = [
            'payment_payflex_status'                  => '0',
            'payment_payflex_environment'             => 'sandbox',
            'payment_payflex_client_id'               => '',
            'payment_payflex_client_secret'           => '',
            'payment_payflex_order_status_pending_id'   => '1',  // OC4 default: Pending
            'payment_payflex_order_status_approved_id'  => '2',  // OC4 default: Processing
            'payment_payflex_order_status_failed_id'    => '10', // OC4 default: Failed
            'payment_payflex_order_status_cancelled_id' => '7',  // OC4 default: Canceled
            'payment_payflex_order_status_expired_id'   => '14', // OC4 default: Expired
            'payment_payflex_cron_token'              => '',
            'payment_payflex_sort_order'              => '0',
            'payment_payflex_debug'                   => '0',
            'payment_payflex_product_widget'          => '0',
            'payment_payflex_widget_style'            => 'purple',
            'payment_payflex_widget_theme'            => '',
            'payment_payflex_widget_pay_type'         => '4',
            'payment_payflex_widget_merchant_ref'     => '',
        ];

        // Status ID fields must also fall back when saved as empty string, so use ?: not ??
        $status_id_keys = [
            'payment_payflex_order_status_pending_id',
            'payment_payflex_order_status_approved_id',
            'payment_payflex_order_status_failed_id',
            'payment_payflex_order_status_cancelled_id',
            'payment_payflex_order_status_expired_id',
        ];

        foreach ($settings as $key => $default) {
            $value = $this->config->get($key);
            $data[$key] = in_array($key, $status_id_keys, true)
                ? ($value ?: $default)   // empty string → fall back to default
                : ($value ?? $default);  // null only → fall back to default
        }

        // Auto-generate the CRON token on first visit if not yet set
        if (empty($data['payment_payflex_cron_token'])) {
            $data['payment_payflex_cron_token'] = bin2hex(random_bytes(20));
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSettingValue(
                'payment_payflex',
                'payment_payflex_cron_token',
                $data['payment_payflex_cron_token']
            );
        }

        // --- Order status dropdown ---
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        // --- CRON URL (display only) ---
        $data['cron_url'] = HTTP_CATALOG . 'index.php?route=extension/payflex/payment/payflex.cron&cron_token='
            . ($this->config->get('payment_payflex_cron_token') ?: 'YOUR_TOKEN_HERE');

        // --- Page layout ---
        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payflex/payment/payflex', $data));
    }

    // -------------------------------------------------------------------------
    // Save Settings (AJAX)
    // -------------------------------------------------------------------------

    public function save(): void {
        $this->load->language('extension/payflex/payment/payflex');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/payflex/payment/payflex')) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }

        // Validation
        if (empty($this->request->post['payment_payflex_client_id'])) {
            $json['error']['client_id'] = $this->language->get('error_client_id');
        }

        if (empty($this->request->post['payment_payflex_client_secret'])) {
            $json['error']['client_secret'] = $this->language->get('error_client_secret');
        }

        if (empty($this->request->post['payment_payflex_cron_token'])) {
            $json['error']['cron_token'] = $this->language->get('error_cron_token');
        }

        if (!$json) {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_payflex', $this->request->post);

            // Clear cached token and configuration so new credentials take effect immediately
            $this->cache->delete('payflex.token.sandbox');
            $this->cache->delete('payflex.token.production');
            $this->cache->delete('payflex.config.sandbox');
            $this->cache->delete('payflex.config.production');

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // -------------------------------------------------------------------------
    // Install / Uninstall
    // -------------------------------------------------------------------------

    /**
     * Called when the admin clicks "Install" in the Extensions list.
     * Creates the oc_payflex_order table (if it doesn't exist) and applies any
     * schema migrations so reinstalls and upgrades are safe. Also registers
     * the product widget event.
     */
    public function install(): void {
        // Ensure the extension path is registered in oc_extension_path.
        // The zip-based Extension Installer writes this automatically, but a
        // manual FTP install does not — causing routes and the autoloader to
        // fail. This makes both install paths behave identically.
        $path_check = $this->db->query(
            "SELECT `extension_path_id` FROM `" . DB_PREFIX . "extension_path`
             WHERE `path` = 'extension/payflex' LIMIT 1"
        );

        if (!$path_check->num_rows) {
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "extension_path`
                 SET `extension_install_id` = 0, `path` = 'extension/payflex'"
            );
        }

        $this->load->model('user/user_group');

        $route = 'extension/payflex/payment/payflex';

        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', $route);
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', $route);

        // Create table only if it doesn't already exist (preserves existing data on reinstall)
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "payflex_order` (
                `payflex_order_id` INT(11)         NOT NULL AUTO_INCREMENT,
                `order_id`         INT(11)         NOT NULL,
                `payflex_id`       VARCHAR(255)    NOT NULL DEFAULT '',
                `environment`      VARCHAR(20)     NOT NULL DEFAULT '',
                `status`           VARCHAR(50)     NOT NULL DEFAULT '',
                `amount`           DECIMAL(15, 4)  NOT NULL DEFAULT '0.0000',
                `date_added`       DATETIME        NOT NULL,
                `date_modified`    DATETIME        NOT NULL,
                PRIMARY KEY (`payflex_order_id`),
                UNIQUE KEY `order_id` (`order_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // Apply any schema migrations (adds missing columns on upgrade/reinstall)
        $this->migrateSchema();

        // Register the product widget event
        $this->load->model('setting/event');
        // Trigger format: 'catalog/...' prefix is stored in DB.
        // OC4 startup/event.php strips 'catalog/' before registering with the event engine,
        // so the loader's 'view/product/product/after' will match correctly.
        $this->model_setting_event->addEvent([
            'code'        => 'payment_payflex_widget',
            'description' => 'Payflex installment widget on product pages',
            'trigger'     => 'catalog/view/product/product/after',
            'action'      => 'extension/payflex/payment/payflex.eventProductWidget',
            'status'      => true,
            'sort_order'  => 0,
        ]);
    }

    /**
     * Ensures the oc_payflex_order table has all expected columns.
     * Add new column definitions here as the schema evolves — they will be
     * applied automatically on the next install() call (upgrade / reinstall).
     */
    private function migrateSchema(): void {
        $result   = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "payflex_order`");
        $existing = array_column($result->rows, 'Field');

        // Column name => ALTER TABLE fragment (type + constraints, no column name)
        // Add new entries here when the schema changes.
        $columns = [
            'payflex_order_id' => "INT(11) NOT NULL AUTO_INCREMENT FIRST",
            'order_id'         => "INT(11) NOT NULL AFTER `payflex_order_id`",
            'payflex_id'       => "VARCHAR(255) NOT NULL DEFAULT '' AFTER `order_id`",
            'environment'      => "VARCHAR(20) NOT NULL DEFAULT '' AFTER `payflex_id`",
            'status'           => "VARCHAR(50) NOT NULL DEFAULT '' AFTER `environment`",
            'amount'           => "DECIMAL(15,4) NOT NULL DEFAULT '0.0000' AFTER `status`",
            'date_added'       => "DATETIME NOT NULL AFTER `amount`",
            'date_modified'    => "DATETIME NOT NULL AFTER `date_added`",
        ];

        foreach ($columns as $column => $definition) {
            if (!in_array($column, $existing)) {
                $this->db->query(
                    "ALTER TABLE `" . DB_PREFIX . "payflex_order` ADD COLUMN `" . $column . "` " . $definition
                );
            }
        }
    }

    /**
     * Called when the admin clicks "Uninstall" in the Extensions list.
     * Removes the widget event but intentionally preserves the order table
     * and settings so historical data is not lost and the module can be
     * reinstalled without reconfiguration.
     */
    public function uninstall(): void {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('payment_payflex_widget');
    }
}
