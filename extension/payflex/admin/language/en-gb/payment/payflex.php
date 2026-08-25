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
