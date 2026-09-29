<?php
namespace Opencart\Admin\Controller\Extension\Payflex\Payment;

/**
 * Payflex Admin Controller
 *
 * Provides the settings page in the OC4 admin and handles install/uninstall.
 *
 * Routes:
 *   extension/payflex/payment/payflex         -> index()
 *   extension/payflex/payment/payflex.save    -> save()
 *   extension/payflex/payment/payflex.install -> install()
 *   extension/payflex/payment/payflex.uninstall -> uninstall()
 *   extension/payflex/payment/payflex.checkUpdate -> checkUpdate()
 *   extension/payflex/payment/payflex.update    -> update()
 *   extension/payflex/payment/payflex.rollback  -> rollback()
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
        // Passed as a JS URL (third argument), so the ampersands are not HTML encoded.
        $data['check_update'] = $this->url->link('extension/payflex/payment/payflex.checkUpdate', 'user_token=' . $this->session->data['user_token'], true);
        $data['install_update'] = $this->url->link('extension/payflex/payment/payflex.update', 'user_token=' . $this->session->data['user_token'], true);
        $data['rollback'] = $this->url->link('extension/payflex/payment/payflex.rollback', 'user_token=' . $this->session->data['user_token'], true);

        $data['rollback_version'] = $this->getVersion(DIR_EXTENSION . '.payflex-backup/');
        $data['button_update_rollback'] = sprintf($this->language->get('button_update_rollback'), $data['rollback_version']);

        $data['error_upgrade'] = '';

        if ($this->user->hasPermission('modify', 'extension/payflex/payment/payflex')) {
            // A failed upgrade must not take the page down with it, or the
            // Restore button goes too. It runs again on the next page load.
            try {
                $this->applyUpgrade();
            } catch (\Exception $e) {
                $this->log->write('Payflex upgrade failed: ' . $e->getMessage());
                $data['error_upgrade'] = $this->language->get('error_upgrade');
            }
        }

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
                ? ($value ?: $default)   // empty string -> fall back to default
                : ($value ?? $default);  // null only -> fall back to default
        }

        // Suggest a CRON token until one is saved. It is stored with the rest of
        // the form on Save. Nothing is written here: editValue() only updates
        // an existing row, and there is none before the first save.
        if (empty($data['payment_payflex_cron_token'])) {
            $data['payment_payflex_cron_token'] = bin2hex(random_bytes(20));
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
    // Update Check (AJAX)
    // -------------------------------------------------------------------------

    // The repo is public, so no auth token is needed. /releases/latest already
    // excludes drafts and prereleases.
    private const UPDATE_API_URL = 'https://api.github.com/repos/PayFlexSA/payflex-opencart-4-extension/releases/latest';
    private const UPDATE_RELEASE_URL = 'https://github.com/PayFlexSA/payflex-opencart-4-extension/releases';
    private const UPDATE_DOWNLOAD_URL = 'https://github.com/PayFlexSA/payflex-opencart-4-extension/releases/download/';
    private const UPDATE_PACKAGE = 'payflex.ocmod.zip';

    // The package is well under 100 KB. This only stops a runaway download.
    private const UPDATE_MAX_BYTES = 5242880;

    // Unauthenticated GitHub API calls are capped at 60 per hour per IP.
    private const UPDATE_CACHE_TTL = 43200; // 12 hours

    /**
     * Reports whether a newer release exists on GitHub.
     * Detection only. Nothing is downloaded or installed.
     *
     * Add &force=1 to bypass the cache.
     */
    public function checkUpdate(): void {
        $this->load->language('extension/payflex/payment/payflex');

        $json = [];

        if (!$this->user->hasPermission('access', 'extension/payflex/payment/payflex')) {
            $json['status'] = 'error';
            $json['text']   = $this->language->get('error_permission');
        } else {
            $this->load->model('setting/setting');

            // Kept under its own setting code on purpose: editSetting() deletes the
            // whole code group before reinserting, so anything stored under
            // payment_payflex would be wiped every time the settings form is saved.
            $cache = $this->model_setting_setting->getSetting('payflex_update');

            $cached = empty($this->request->get['force'])
                && !empty($cache['payflex_update_last_check'])
                && (time() - (int)$cache['payflex_update_last_check']) < self::UPDATE_CACHE_TTL;

            if ($cached) {
                $release = [
                    'version' => (string)($cache['payflex_update_latest_version'] ?? ''),
                    'url'     => (string)($cache['payflex_update_url'] ?? ''),
                    'error'   => (string)($cache['payflex_update_error'] ?? ''),
                ];
            } else {
                $release = $this->fetchLatestRelease();

                // A failed check keeps the last known release, so a transient
                // GitHub error does not hide an update the store already knows about.
                if ($release['error'] !== '') {
                    $release['version'] = (string)($cache['payflex_update_latest_version'] ?? '');
                    $release['url']     = (string)($cache['payflex_update_url'] ?? '');
                }

                // Failures are cached too, otherwise a firewalled or rate limited
                // store would retry on every admin page load.
                $this->model_setting_setting->editSetting('payflex_update', [
                    'payflex_update_last_check'     => (string)time(),
                    'payflex_update_latest_version' => $release['version'],
                    'payflex_update_url'            => $release['url'],
                    'payflex_update_error'          => $release['error'],
                ]);
            }

            $json = $this->buildUpdateResult($release);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Turns a release lookup (fresh or cached) into the response payload.
     * The comparison happens here rather than at fetch time so that a cached
     * result stops reporting an update as soon as the module is upgraded.
     */
    private function buildUpdateResult(array $release): array {
        $current = $this->getVersion(DIR_EXTENSION . 'payflex/');

        if ($current === '') {
            return [
                'status' => 'error',
                'text'   => sprintf($this->language->get('text_update_failed'), $this->language->get('error_update_manifest')),
            ];
        }

        if (version_compare($release['version'], $current, '>')) {
            return [
                'status'  => 'update',
                'text'    => sprintf($this->language->get('text_update_available'), $release['version'], $current),
                'version' => $release['version'],
                // The URL ends up in an href, so only ever hand back a github.com
                // address. Checked here so it covers the cached path as well.
                'url'     => strpos($release['url'], 'https://github.com/') === 0 ? $release['url'] : self::UPDATE_RELEASE_URL,
            ];
        }

        if ($release['error'] !== '') {
            return [
                'status' => 'error',
                'text'   => sprintf($this->language->get('text_update_failed'), $this->describeUpdateError($release['error'])),
            ];
        }

        return [
            'status' => 'current',
            'text'   => sprintf($this->language->get('text_update_current'), $current),
        ];
    }

    /**
     * Queries the GitHub releases API.
     * Returns the version and release page URL, or a short reason code in
     * 'error' that describeUpdateError() renders for the admin.
     */
    private function fetchLatestRelease(): array {
        $failure = ['version' => '', 'url' => '', 'download' => '', 'digest' => '', 'error' => 'network'];

        // Deliberately short so a store that cannot reach GitHub fails fast.
        [$result, $http_code] = $this->httpGet(self::UPDATE_API_URL, 10, ['Accept: application/vnd.github+json']);

        if ($result === false) {
            return $failure;
        }

        if ($http_code !== 200) {
            $failure['error'] = 'http:' . (int)$http_code;

            return $failure;
        }

        $release = json_decode((string)$result, true);

        if (!is_array($release) || empty($release['tag_name'])) {
            $failure['error'] = 'response';

            return $failure;
        }

        $asset = [];

        foreach ((array)($release['assets'] ?? []) as $item) {
            if (is_array($item) && ($item['name'] ?? '') === self::UPDATE_PACKAGE) {
                $asset = $item;
                break;
            }
        }

        return [
            // Release tags are published as vX.Y.Z; version_compare needs the bare number.
            'version'  => preg_replace('/^v/i', '', trim((string)$release['tag_name'])),
            'url'      => (string)($release['html_url'] ?? ''),
            // Both stay empty until the release workflow has attached the package.
            'download' => (string)($asset['browser_download_url'] ?? ''),
            'digest'   => (string)($asset['digest'] ?? ''),
            'error'    => '',
        ];
    }

    /**
     * GET request that only ever uses https, redirects included.
     * Returns [body or false, HTTP status code].
     */
    private function httpGet(string $url, int $timeout, array $headers = []): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE     => self::UPDATE_MAX_BYTES,
            CURLOPT_HTTPHEADER      => $headers,
            // The GitHub API rejects requests that send no User-Agent.
            CURLOPT_USERAGENT       => 'Payflex-OpenCart4',
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);

        // No curl_close(): it has done nothing since PHP 8.0 and is deprecated in 8.5.
        return [curl_exec($ch), (int)curl_getinfo($ch, CURLINFO_HTTP_CODE)];
    }

    /**
     * Maps the stored reason code to a readable message. A code is cached
     * rather than the finished sentence so a cached failure is still rendered
     * in the language the admin is currently using.
     */
    private function describeUpdateError(string $error): string {
        [$reason, $detail] = array_pad(explode(':', $error, 2), 2, '');

        switch ($reason) {
            case 'http':
                return sprintf($this->language->get('error_update_http'), (int)$detail);

            case 'response':
                return $this->language->get('error_update_response');

            default:
                return $this->language->get('error_update_network');
        }
    }

    /**
     * Reads the version from the install.json in $dir, which must end in a slash.
     * For the installed module this is the only reliable source.
     * OpenCart never updates oc_extension_install.version after the first
     * install, so that value goes stale.
     */
    private function getVersion(string $dir): string {
        $file = $dir . 'install.json';

        if (!is_file($file)) {
            return '';
        }

        $manifest = json_decode((string)file_get_contents($file), true);

        if (!is_array($manifest) || empty($manifest['version'])) {
            return '';
        }

        return trim((string)$manifest['version']);
    }

    // -------------------------------------------------------------------------
    // Update Install and Rollback (AJAX)
    // -------------------------------------------------------------------------
    //
    // OpenCart's own installer cannot upgrade an extension in place, and its
    // uninstall step deletes the module settings. So the new version is
    // unpacked next to the live folder and swapped in with two renames:
    //
    //   extension/payflex           the live module
    //   extension/.payflex-staging  the new version while it is being unpacked
    //   extension/.payflex-previous the old version for the moment of the swap
    //   extension/.payflex-backup   the old version afterwards, for rollback
    //
    // The leading dots matter. glob() skips them, so OpenCart never lists a
    // backup as a second Payflex payment method.
    //
    // Nothing may be loaded from extension/payflex after the swap. The request
    // would get the new version's files and mix them with the old code that
    // is already running. Database changes for the new version run from
    // index() on the next page load, in the new code.

    /**
     * Downloads the latest release and swaps it in.
     */
    public function update(): void {
        $this->runUpdateAction(fn(): string => $this->installUpdate());
    }

    /**
     * Swaps the backup and the live module.
     */
    public function rollback(): void {
        $this->runUpdateAction(fn(): string => $this->restoreBackup());
    }

    /**
     * Shared wrapper for update() and rollback(). It checks permissions, takes
     * the lock and checks the file system before $action runs. $action returns
     * a success message or throws a RuntimeException.
     */
    private function runUpdateAction(callable $action): void {
        $this->load->language('extension/payflex/payment/payflex');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/payflex/payment/payflex') || $this->request->server['REQUEST_METHOD'] !== 'POST') {
            $json['error'] = $this->language->get('error_permission');
        } else {
            // OpenCart's error handler prints warnings into the response, which
            // would break the JSON. Every file operation checks its own result.
            set_error_handler(function (): bool {
                return true;
            });

            // A swap stopped half way leaves the store without the module. A
            // closed browser tab or the time limit must not stop the request.
            ignore_user_abort(true);
            set_time_limit(180);

            // Stops two admins running an update at the same time. Closing the
            // handle releases the lock.
            $lock = fopen(DIR_STORAGE . 'payflex_update.lock', 'c');

            try {
                if (!$lock) {
                    throw new \RuntimeException(sprintf($this->language->get('error_update_writable'), DIR_STORAGE));
                }

                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new \RuntimeException($this->language->get('error_update_running'));
                }

                $this->checkCanUpdate();

                $json['success'] = $action();
            } catch (\RuntimeException $e) {
                $json['error'] = $e->getMessage();
            } finally {
                if ($lock) {
                    fclose($lock);
                }

                restore_error_handler();
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Makes sure every file operation the swap needs will work, before anything
     * is downloaded or moved.
     */
    private function checkCanUpdate(): void {
        $live = DIR_EXTENSION . 'payflex';

        // A symlinked module is a development checkout. The swap would replace
        // the link with a plain folder.
        if (is_link($live) || !is_dir($live)) {
            throw new \RuntimeException($this->language->get('error_update_link'));
        }

        // Both renames need write access to extension/. Actually try it,
        // because is_writable() can be wrong under ACLs, SELinux or network
        // file systems.
        $probe   = DIR_EXTENSION . '.payflex-probe-' . bin2hex(random_bytes(4));
        $renamed = $probe . '-renamed';

        $writable = mkdir($probe) && rename($probe, $renamed) && rmdir($renamed);

        $this->removeDirectory($probe);
        $this->removeDirectory($renamed);

        if (!$writable) {
            throw new \RuntimeException(sprintf($this->language->get('error_update_writable'), DIR_EXTENSION));
        }

        // The live folder becomes the backup. The next update can only delete
        // that backup if every folder in it is writable.
        if (!is_writable($live)) {
            throw new \RuntimeException(sprintf($this->language->get('error_update_writable'), $live));
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($live, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isWritable()) {
                throw new \RuntimeException(sprintf($this->language->get('error_update_writable'), $item->getPathname()));
            }
        }
    }

    private function installUpdate(): string {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException($this->language->get('error_update_zip'));
        }

        // Always fetched fresh. The cached result from checkUpdate() has no
        // download details and may be up to 12 hours old.
        $release = $this->fetchLatestRelease();

        if ($release['error'] !== '') {
            throw new \RuntimeException($this->describeUpdateError($release['error']));
        }

        if (!version_compare($release['version'], $this->getVersion(DIR_EXTENSION . 'payflex/'), '>')) {
            throw new \RuntimeException($this->language->get('error_update_none'));
        }

        $file    = DIR_STORAGE . 'payflex_update.zip';
        $staging = DIR_EXTENSION . '.payflex-staging';

        try {
            $this->downloadPackage($release, $file);
            $this->removeDirectory($staging);
            $this->extractPackage($file, $staging, $release['version']);
            $this->replaceLive($staging);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }

            // Only left behind when something failed before the swap.
            $this->removeDirectory($staging);
        }

        return sprintf($this->language->get('text_update_success'), $release['version']);
    }

    private function restoreBackup(): string {
        $backup  = DIR_EXTENSION . '.payflex-backup';
        $version = $this->getVersion($backup . '/');

        if ($version === '') {
            throw new \RuntimeException($this->language->get('error_update_backup'));
        }

        $this->replaceLive($backup);

        return sprintf($this->language->get('text_rollback_success'), $version);
    }

    /**
     * Downloads the release package to $file and checks it against the sha256
     * digest GitHub publishes for every release asset. The digest catches a
     * corrupted or truncated download. It does not prove who published the
     * release.
     */
    private function downloadPackage(array $release, string $file): void {
        if (strpos($release['download'], self::UPDATE_DOWNLOAD_URL) !== 0 || !preg_match('/^sha256:([0-9a-f]{64})$/', $release['digest'], $match)) {
            throw new \RuntimeException($this->language->get('error_update_asset'));
        }

        // GitHub redirects the download to its file storage host.
        [$data, $http_code] = $this->httpGet($release['download'], 60);

        if ($data === false || $http_code !== 200) {
            throw new \RuntimeException($this->language->get('error_update_download'));
        }

        if (!hash_equals($match[1], hash('sha256', $data))) {
            throw new \RuntimeException($this->language->get('error_update_digest'));
        }

        if (file_put_contents($file, $data) === false) {
            throw new \RuntimeException(sprintf($this->language->get('error_update_writable'), DIR_STORAGE));
        }
    }

    /**
     * Unpacks the package into $staging and checks that it is a complete module
     * of the expected version.
     */
    private function extractPackage(string $file, string $staging, string $version): void {
        $zip = new \ZipArchive();

        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException($this->language->get('error_update_package'));
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);

                // Only the paths the module ships. No path segment may start
                // with a dot, which rules out ../ and hidden files.
                if (!preg_match('#^(install\.json|LICENSE|(admin|catalog)(/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*/?)$#', $name)) {
                    throw new \RuntimeException($this->language->get('error_update_package'));
                }

                if (substr($name, -1) === '/') {
                    continue;
                }

                // Written by hand rather than with extractTo(), so a symlink
                // entry can only ever become a plain file.
                $target = $staging . '/' . $name;
                $data   = $zip->getFromIndex($i);

                if ($data === false || (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true)) || file_put_contents($target, $data) === false) {
                    throw new \RuntimeException(sprintf($this->language->get('error_update_writable'), DIR_EXTENSION));
                }
            }
        } finally {
            $zip->close();
        }

        // Checkout loads the catalog model of every enabled payment method, so
        // shipping without it would break checkout for all of them.
        foreach (['admin/controller/payment/payflex.php', 'catalog/controller/payment/payflex.php', 'catalog/model/payment/payflex.php'] as $required) {
            if (!is_file($staging . '/' . $required)) {
                throw new \RuntimeException($this->language->get('error_update_package'));
            }
        }

        if ($this->getVersion($staging . '/') !== $version) {
            throw new \RuntimeException($this->language->get('error_update_package'));
        }
    }

    /**
     * Moves $source into place as the live module and keeps the old live
     * module as the backup.
     */
    private function replaceLive(string $source): void {
        $live     = DIR_EXTENSION . 'payflex';
        $previous = DIR_EXTENSION . '.payflex-previous';
        $backup   = DIR_EXTENSION . '.payflex-backup';

        // Only exists if an earlier run failed after the swap.
        $this->removeDirectory($previous);

        if (!rename($live, $previous)) {
            throw new \RuntimeException($this->language->get('error_update_swap'));
        }

        // Without extension/payflex every checkout fails, not only Payflex. If
        // the request dies before the second rename, put the old version back.
        register_shutdown_function(function () use ($live, $previous): void {
            clearstatcache();

            if (!is_dir($live) && is_dir($previous)) {
                rename($previous, $live);
            }
        });

        if (!rename($source, $live)) {
            if (!rename($previous, $live)) {
                throw new \RuntimeException(sprintf($this->language->get('error_update_restore'), $previous, $live));
            }

            throw new \RuntimeException($this->language->get('error_update_swap'));
        }

        // For a rollback $source was the backup, so it is already gone. If the
        // old backup cannot be deleted, .payflex-previous stays behind and is
        // cleared by the next run.
        $this->removeDirectory($backup);
        rename($previous, $backup);

        // The paths are unchanged, so both caches need a nudge. Twig only
        // recompiles a template that is newer than its cache, and a restored
        // backup has old file times. OPcache on a host that never rechecks file
        // times would keep running the old code.
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($live, \FilesystemIterator::SKIP_DOTS)) as $item) {
            touch($item->getPathname());

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($item->getPathname(), true);
            }
        }

        clearstatcache();
    }

    private function removeDirectory(string $path): void {
        if (!is_dir($path) || is_link($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    /**
     * Brings the database in line with the module files after they change,
     * whether by update(), rollback() or a manual upload. It runs from index(),
     * in the new code, because only the new code knows what its version needs.
     */
    private function applyUpgrade(): void {
        $version = $this->getVersion(DIR_EXTENSION . 'payflex/');

        if ($version === '' || $this->config->get('payflex_version') === $version) {
            return;
        }

        $this->migrateSchema();
        $this->addEvents();

        // OpenCart only writes this on the first install, so the Extension
        // Installer page would keep showing the old version.
        $this->db->query("UPDATE `" . DB_PREFIX . "extension_install` SET `version` = '" . $this->db->escape($version) . "' WHERE `code` = 'payflex'");

        // Kept under its own code for the same reason as payflex_update.
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('payflex_version', ['payflex_version' => $version]);
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

        $this->addEvents();
    }

    /**
     * Registers the product widget event if it is missing. Safe to call on
     * every install and upgrade. addEvent() does not check for duplicates, and
     * a second copy would show the widget twice.
     */
    private function addEvents(): void {
        $this->load->model('setting/event');

        if ($this->model_setting_event->getEventByCode('payment_payflex_widget')) {
            return;
        }

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
