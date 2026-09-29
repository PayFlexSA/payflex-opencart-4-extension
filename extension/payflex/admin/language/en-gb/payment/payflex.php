<?php
// Heading
$_['payflex_heading_title']             = 'Payflex';
$_['payflex_text_payflex']              = '';
$_['heading_title']                     = 'Payflex';

// Text
$_['text_extension']                    = 'Extensions';
$_['text_success']                      = 'Success: Payflex settings have been saved.';
$_['text_edit']                         = 'Edit Payflex Settings';
$_['text_enabled']                      = 'Enabled';
$_['text_disabled']                     = 'Disabled';
$_['text_sandbox']                      = 'Sandbox';
$_['text_production']                   = 'Production';

// Tabs
$_['tab_general']                       = 'General';
$_['tab_order_statuses']                = 'Order Statuses';
$_['tab_widget']                        = 'Product Widget';
$_['tab_cron']                          = 'CRON';

// General entries
$_['entry_status']                      = 'Status';
$_['entry_environment']                 = 'Environment';
$_['entry_client_id']                   = 'Client ID';
$_['entry_client_secret']               = 'Client Secret';
$_['entry_sort_order']                  = 'Sort Order';
$_['entry_debug']                       = 'Debug Mode';
$_['help_debug']                        = 'When enabled, extra diagnostic information is shown to logged-in admin users (e.g. CRON output). Disable in production.';

// Order status entries
$_['entry_order_status_pending']        = 'Pending Status';
$_['entry_order_status_approved']       = 'Approved Status';
$_['entry_order_status_failed']         = 'Failed / Declined Status';
$_['entry_order_status_cancelled']      = 'Cancelled Status';
$_['entry_order_status_expired']        = 'Expired / Abandoned Status';

// Widget entries
$_['entry_product_widget']              = 'Enable Product Widget';
$_['entry_widget_style']                = 'Widget Logo Style';
$_['entry_widget_theme']                = 'Widget Theme';
$_['entry_widget_pay_type']             = 'Payment Type';
$_['entry_widget_merchant_ref']         = 'Merchant Widget Reference';

// Widget options
$_['text_widget_style_purple']          = 'Purple';
$_['text_widget_style_navy']            = 'Navy';
$_['text_widget_theme_light']           = 'Light (default)';
$_['text_widget_theme_dark']            = 'Dark';
$_['text_widget_pay_type_4']            = 'Pay in 4';
$_['text_widget_pay_type_3']            = 'Pay in 3';

// CRON entries
$_['entry_cron_token']                  = 'CRON Secret Token';
$_['help_cron_token']                   = 'A secret string that protects the CRON endpoint. Set this in your server\'s cron job URL.';
$_['help_cron_url']                     = 'CRON Endpoint URL';
$_['help_client_id']                    = 'Provided by Payflex. Use sandbox credentials for testing.';
$_['help_client_secret']                = 'Provided by Payflex. Keep this value secret.';
$_['help_widget_merchant_ref']          = 'Optional. URL-safe slug provided by Payflex for custom widget branding.';

// Errors
$_['error_permission']                  = 'Warning: You do not have permission to modify Payflex settings.';
$_['error_client_id']                   = 'Client ID is required.';
$_['error_client_secret']               = 'Client Secret is required.';
$_['error_cron_token']                  = 'A CRON secret token is required.';

// Update check
$_['text_update_checking']              = 'Checking for updates...';
$_['text_update_current']               = 'Payflex %s is installed. This is the latest version.';
$_['text_update_available']             = 'Payflex %s is available. You are running %s.';
$_['text_update_failed']                = 'Could not check for Payflex updates: %s';
$_['text_update_error']                 = 'Could not check for Payflex updates.';
$_['button_update_check']               = 'Check now';
$_['button_update_view']                = 'View release';

// Update check failure reasons
$_['error_update_network']              = 'GitHub could not be reached.';
$_['error_update_http']                 = 'GitHub returned HTTP status %d.';
$_['error_update_response']             = 'GitHub returned an unexpected response.';
$_['error_update_manifest']             = 'The installed version could not be read from install.json.';

// Update install and rollback
$_['button_update_install']             = 'Install update';
$_['button_update_rollback']            = 'Restore %s';
$_['text_update_confirm']               = 'Install Payflex %s now? The current version is kept as a backup.';
$_['text_rollback_confirm']             = 'Restore Payflex %s from the backup? The current version becomes the backup.';
$_['text_update_working']               = 'Working, please do not leave this page...';
$_['text_update_success']               = 'Payflex %s has been installed.';
$_['text_rollback_success']             = 'Payflex %s has been restored.';
$_['text_update_install_error']         = 'The request failed. Reload the page to see which version is installed.';

// Update install and rollback failure reasons
$_['error_update_link']                 = 'extension/payflex is a symlink or is missing, so it cannot be updated from here. Update it manually.';
$_['error_update_writable']             = 'PHP cannot write to %s. Fix the file permissions or update manually.';
$_['error_update_running']              = 'Another update is already running.';
$_['error_update_zip']                  = 'The PHP zip extension is not installed. Update manually.';
$_['error_update_none']                 = 'There is no newer version to install.';
$_['error_update_asset']                = 'The release does not have a package with a digest attached yet. Try again in a few minutes.';
$_['error_update_download']             = 'The package could not be downloaded from GitHub.';
$_['error_update_digest']               = 'The downloaded package does not match the digest GitHub published for it.';
$_['error_update_package']              = 'The downloaded package is not a valid Payflex package.';
$_['error_update_swap']                 = 'The new files could not be moved into place. The current version is still installed.';
$_['error_update_restore']              = 'The module folder could not be put back. Rename %s to %s on the server to restore the store.';
$_['error_update_backup']               = 'There is no backup to restore.';
