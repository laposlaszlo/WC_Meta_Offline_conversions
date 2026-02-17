<?php
/**
 * Plugin Name: Meta Offline Conversions for WooCommerce
 * Description: Automatically sends WooCommerce Purchase events to the Meta Conversions API and stores FBP/FBC cookies on orders.
 * Version: 1.0.15
 * Author: Lapos László
 * Text Domain: meta-offline-conversions
 * Plugin URI: https://github.com/laposlaszlo/WC_Meta_Offline_conversions
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MOC_VERSION', '1.0.15');
define('MOC_OPTION_KEY', 'moc_settings');
define('MOC_CAPABILITY', 'manage_woocommerce');
define('MOC_CRON_HOOK', 'moc_cron_send_past_orders');
define('MOC_BULK_LOG_OPTION', 'moc_bulk_log');
define('MOC_ADMIN_LOG_OPTION', 'moc_admin_log');
define('MOC_PLUGIN_FILE', __FILE__);
define('MOC_PLUGIN_DIR', __DIR__);
define('MOC_UPDATE_REPO_URL', 'https://github.com/laposlaszlo/WC_Meta_Offline_conversions');

$GLOBALS['moc_update_checker_status'] = 'unknown';

register_activation_hook(__FILE__, 'moc_activate');

add_action('plugins_loaded', 'moc_init');
add_action('plugins_loaded', 'moc_init_update_checker');
add_action(MOC_CRON_HOOK, 'moc_cron_send_past_orders');
add_filter('cron_schedules', 'moc_cron_schedules');

function moc_activate() {
    if (get_option(MOC_OPTION_KEY, null) === null) {
        add_option(MOC_OPTION_KEY, [], '', 'no');
    }

    $settings = moc_get_settings();
    if (!empty($settings['enable_cron'])) {
        $interval = !empty($settings['cron_interval']) ? $settings['cron_interval'] : 'hourly';
        moc_schedule_cron($interval);
    }
}

function moc_cron_schedules($schedules) {
    $schedules['moc_5min'] = [
        'interval' => 5 * MINUTE_IN_SECONDS,
        'display' => __('Every 5 minutes', 'meta-offline-conversions'),
    ];
    $schedules['moc_15min'] = [
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display' => __('Every 15 minutes', 'meta-offline-conversions'),
    ];
    $schedules['moc_30min'] = [
        'interval' => 30 * MINUTE_IN_SECONDS,
        'display' => __('Every 30 minutes', 'meta-offline-conversions'),
    ];

    return $schedules;
}

function moc_get_cron_interval_options() {
    return [
        'moc_5min' => __('Every 5 minutes', 'meta-offline-conversions'),
        'moc_15min' => __('Every 15 minutes', 'meta-offline-conversions'),
        'moc_30min' => __('Every 30 minutes', 'meta-offline-conversions'),
        'hourly' => __('Hourly', 'meta-offline-conversions'),
        'twicedaily' => __('Twice Daily', 'meta-offline-conversions'),
        'daily' => __('Daily', 'meta-offline-conversions'),
    ];
}

function moc_schedule_cron($interval) {
    if (!moc_is_valid_cron_interval($interval)) {
        $interval = 'hourly';
    }

    wp_clear_scheduled_hook(MOC_CRON_HOOK);

    if (!wp_next_scheduled(MOC_CRON_HOOK)) {
        wp_schedule_event(time() + 60, $interval, MOC_CRON_HOOK);
    }
}

function moc_clear_cron() {
    wp_clear_scheduled_hook(MOC_CRON_HOOK);
}

function moc_is_valid_cron_interval($interval) {
    $options = moc_get_cron_interval_options();
    return isset($options[$interval]);
}

function moc_sync_cron_settings($old_settings, $new_settings) {
    $old_enabled = !empty($old_settings['enable_cron']);
    $new_enabled = !empty($new_settings['enable_cron']);
    $old_interval = !empty($old_settings['cron_interval']) ? $old_settings['cron_interval'] : 'hourly';
    $new_interval = !empty($new_settings['cron_interval']) ? $new_settings['cron_interval'] : 'hourly';

    if ($new_enabled) {
        if (!$old_enabled || $old_interval !== $new_interval) {
            moc_schedule_cron($new_interval);
        }
    } elseif ($old_enabled) {
        moc_clear_cron();
    }
}

function moc_ensure_cron_scheduled() {
    $settings = moc_get_settings();
    $enabled = !empty($settings['enable_cron']);
    $interval = !empty($settings['cron_interval']) ? $settings['cron_interval'] : 'hourly';

    if ($enabled) {
        if (!wp_next_scheduled(MOC_CRON_HOOK)) {
            moc_schedule_cron($interval);
        }
    } else {
        if (wp_next_scheduled(MOC_CRON_HOOK)) {
            moc_clear_cron();
        }
    }
}

function moc_init() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'moc_admin_notice_wc_missing');
        return;
    }

    add_action('init', 'moc_maybe_set_fb_cookies', 1);
    add_action('woocommerce_checkout_order_processed', 'moc_save_fb_cookies_to_order', 10, 1);
    add_action('woocommerce_order_status_completed', 'moc_send_purchase_to_meta', 10, 1);
    add_action('woocommerce_order_status_on-hold', 'moc_send_purchase_to_meta_cheque_test', 10, 1);
    add_action('woocommerce_order_status_processing', 'moc_send_purchase_to_meta_cheque_test', 10, 1);
    add_action('init', 'moc_ensure_cron_scheduled', 5);
}

function moc_init_update_checker() {
    $repo_url = apply_filters('moc_update_repo_url', MOC_UPDATE_REPO_URL);
    if (empty($repo_url)) {
        $GLOBALS['moc_update_checker_status'] = 'disabled';
        return;
    }

    $autoload_file = MOC_PLUGIN_DIR . '/vendor/autoload.php';
    if (!file_exists($autoload_file)) {
        $GLOBALS['moc_update_checker_status'] = 'missing_vendor';
        return;
    }

    require_once $autoload_file;

    if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
        $GLOBALS['moc_update_checker_status'] = 'missing_class';
        return;
    }

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        $repo_url,
        MOC_PLUGIN_FILE,
        'meta-offline-conversions'
    );

    $update_checker->getVcsApi()->enableReleaseAssets();
    $update_checker->setBranch('main');

    if (defined('MOC_GITHUB_TOKEN') && MOC_GITHUB_TOKEN) {
        $update_checker->setAuthentication(MOC_GITHUB_TOKEN);
    }

    $GLOBALS['moc_update_checker_status'] = 'ok';
}

if (is_admin()) {
    add_action('admin_menu', 'moc_add_admin_page');
    add_action('admin_init', 'moc_register_settings');
    add_action('admin_post_moc_send_past_orders', 'moc_handle_send_past_orders');
    add_action('admin_post_moc_clear_admin_log', 'moc_handle_clear_admin_log');
    add_action('admin_notices', 'moc_admin_notices');
}

function moc_admin_notice_wc_missing() {
    if (!current_user_can(MOC_CAPABILITY)) {
        return;
    }

    echo '<div class="notice notice-warning"><p>';
    echo esc_html__('Meta Offline Conversions for WooCommerce requires WooCommerce to be active.', 'meta-offline-conversions');
    echo '</p></div>';
}

function moc_admin_notices() {
    if (!current_user_can(MOC_CAPABILITY)) {
        return;
    }

    if (!isset($_GET['page']) || $_GET['page'] !== 'moc-settings') {
        return;
    }

    if (isset($_GET['moc_bulk_sent'])) {
        $sent = intval($_GET['moc_bulk_sent']);
        $total = intval($_GET['moc_bulk_total']);
        $errors = intval($_GET['moc_bulk_errors']);
        $skipped = isset($_GET['moc_bulk_skipped']) ? intval($_GET['moc_bulk_skipped']) : 0;

        echo '<div class="notice notice-success"><p>';
        printf(
            esc_html__('Bulk send completed. Sent: %d / %d. Skipped: %d. Errors: %d.', 'meta-offline-conversions'),
            $sent,
            $total,
            $skipped,
            $errors
        );
        echo '</p></div>';
    }

    if (isset($_GET['moc_bulk_locked'])) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('A bulk send is already running. Please wait for it to finish.', 'meta-offline-conversions');
        echo '</p></div>';
    }

    if (isset($_GET['moc_admin_log_cleared'])) {
        echo '<div class="notice notice-success"><p>';
        echo esc_html__('Event log was cleared.', 'meta-offline-conversions');
        echo '</p></div>';
    }

    if (!moc_get_pixel_id() || !moc_get_access_token()) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Meta Offline Conversions is not fully configured. Please set the Pixel ID and Access Token.', 'meta-offline-conversions');
        echo '</p></div>';
    }

    $settings = moc_get_settings();
    if (!empty($settings['access_token']) && strpos($settings['access_token'], 'raw:') === 0) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Access token is stored without encryption because OpenSSL is unavailable. Consider enabling OpenSSL.', 'meta-offline-conversions');
        echo '</p></div>';
    }

    $update_status = isset($GLOBALS['moc_update_checker_status']) ? $GLOBALS['moc_update_checker_status'] : 'unknown';
    if ($update_status === 'missing_vendor') {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Update checker is inactive because vendor dependencies are missing. Install with Composer or include the vendor directory.', 'meta-offline-conversions');
        echo '</p></div>';
    } elseif ($update_status === 'missing_class') {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Update checker is inactive because Plugin Update Checker class is missing.', 'meta-offline-conversions');
        echo '</p></div>';
    }
}

function moc_add_admin_page() {
    add_submenu_page(
        'woocommerce',
        __('Meta Offline Conversions', 'meta-offline-conversions'),
        __('Meta Offline Conversions', 'meta-offline-conversions'),
        MOC_CAPABILITY,
        'moc-settings',
        'moc_render_settings_page'
    );
}

function moc_register_settings() {
    register_setting('moc_settings_group', MOC_OPTION_KEY, 'moc_sanitize_settings');
}

function moc_render_settings_page() {
    if (!current_user_can(MOC_CAPABILITY)) {
        return;
    }

    $settings = moc_get_settings();
    $pixel_id = isset($settings['pixel_id']) ? $settings['pixel_id'] : '';
    $token_last4 = isset($settings['token_last4']) ? $settings['token_last4'] : '';
    $token_hint = $token_last4 ? '****' . $token_last4 : __('not set', 'meta-offline-conversions');
    $debug_log = !empty($settings['debug_log']);
    $cheque_test_mode = !empty($settings['cheque_test_mode']);
    $event_name = !empty($settings['event_name']) ? $settings['event_name'] : 'Purchase';
    $minimal_data_mode = !empty($settings['minimal_data_mode']);
    $eu_compliant_mode = !empty($settings['eu_compliant_mode']);
    $send_event_source_url = isset($settings['send_event_source_url']) ? (bool)$settings['send_event_source_url'] : true;
    $log_request_payload = !empty($settings['log_request_payload']);
    $test_event_code = isset($settings['test_event_code']) ? $settings['test_event_code'] : '';
    $enable_test_events = !empty($settings['enable_test_events']);
    $enable_cron = !empty($settings['enable_cron']);
    $cron_interval = !empty($settings['cron_interval']) ? $settings['cron_interval'] : 'hourly';
    $cron_batch_size = !empty($settings['cron_batch_size']) ? (int) $settings['cron_batch_size'] : 50;
    $cron_next = wp_next_scheduled(MOC_CRON_HOOK);
    $cron_next_human = $cron_next ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cron_next) : __('Not scheduled', 'meta-offline-conversions');

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Meta Offline Conversions', 'meta-offline-conversions') . '</h1>';

    echo '<form method="post" action="options.php">';
    settings_fields('moc_settings_group');

    echo '<table class="form-table" role="presentation">';

    echo '<tr><th scope="row">' . esc_html__('Pixel ID', 'meta-offline-conversions') . '</th><td>';
    echo '<input type="text" name="' . esc_attr(MOC_OPTION_KEY) . '[pixel_id]" value="' . esc_attr($pixel_id) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('Example: 1830160027863323', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Access Token', 'meta-offline-conversions') . '</th><td>';
    echo '<input type="password" name="' . esc_attr(MOC_OPTION_KEY) . '[access_token]" value="" class="regular-text" autocomplete="new-password" />';
    echo '<p class="description">' . sprintf(esc_html__('Leave blank to keep existing token. Current: %s', 'meta-offline-conversions'), esc_html($token_hint)) . '</p>';
    echo '<label><input type="checkbox" name="' . esc_attr(MOC_OPTION_KEY) . '[clear_token]" value="1" /> ' . esc_html__('Clear stored token', 'meta-offline-conversions') . '</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Test Event Code', 'meta-offline-conversions') . '</th><td>';
    echo '<input type="text" name="' . esc_attr(MOC_OPTION_KEY) . '[test_event_code]" value="' . esc_attr($test_event_code) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('Test event code from Meta Events Manager (e.g., TEST12345). Get it from Events Manager > Test Events.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Enable Test Events', 'meta-offline-conversions') . '</th><td>';
    echo '<label><input type="checkbox" name="' . esc_attr(MOC_OPTION_KEY) . '[enable_test_events]" value="1" ' . checked($enable_test_events, true, false) . ' /> ';
    echo esc_html__('Send events in test mode', 'meta-offline-conversions') . '</label>';
    echo '<p class="description">' . esc_html__('When enabled, events will be sent as test events using the Test Event Code above. Test events appear in Meta Events Manager but don\'t affect live data.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Debug Logging', 'meta-offline-conversions') . '</th><td>';
    echo '<label><input type="checkbox" name="' . esc_attr(MOC_OPTION_KEY) . '[debug_log]" value="1" ' . checked($debug_log, true, false) . ' /> ';
    echo esc_html__('Enable verbose logs for testing', 'meta-offline-conversions') . '</label>';
    echo '<p class="description">' . esc_html__('Logs are written to WooCommerce logs, PHP error log, and shown below in Event Log while enabled.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Cheque Test Mode', 'meta-offline-conversions') . '</th><td>';
    echo '<label><input type="checkbox" name="' . esc_attr(MOC_OPTION_KEY) . '[cheque_test_mode]" value="1" ' . checked($cheque_test_mode, true, false) . ' /> ';
    echo esc_html__('Send Purchase event for cheque orders on On hold/Processing status (testing only)', 'meta-offline-conversions') . '</label>';
    echo '<p class="description">' . esc_html__('Use this to test on live shop without switching to Completed status. Disable after testing.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Event Name', 'meta-offline-conversions') . '</th><td>';
    echo '<input type="text" name="' . esc_attr(MOC_OPTION_KEY) . '[event_name]" value="' . esc_attr($event_name) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('Meta event name to send (default: Purchase). Other options: CompleteRegistration, AddToCart, InitiateCheckout, etc.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Minimal Data Mode', 'meta-offline-conversions') . '</th><td>';
    echo '<label><input type="checkbox" name="' . esc_attr(MOC_OPTION_KEY) . '[minimal_data_mode]" value="1" ' . checked($minimal_data_mode, true, false) . ' /> ';
    echo esc_html__('Send only value and currency (no product details)', 'meta-offline-conversions') . '</label>';
    echo '<p class="description">' . esc_html__('Enable if your products contain health/medical terms that may cause policy violations. Only order total and currency will be sent.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('EU Compliant Mode', 'meta-offline-conversions') . '</th><td>';
    echo '<label><input type="checkbox" name="' . esc_attr(MOC_OPTION_KEY) . '[eu_compliant_mode]" value="1" ' . checked($eu_compliant_mode, true, false) . ' /> ';
    echo esc_html__('EU compliance mode (recommended for health/medical products)', 'meta-offline-conversions') . '</label>';
    echo '<p class="description"><strong>' . esc_html__('Enable this if Meta blocked your website for health-related content.', 'meta-offline-conversions') . '</strong><br />';
    echo esc_html__('Removes: product IDs, Facebook cookies (fbp/fbc), and event_source_url to comply with EU regulations.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Send Event Source URL', 'meta-offline-conversions') . '</th><td>';
    echo '<label><input type="checkbox" name="' . esc_attr(MOC_OPTION_KEY) . '[send_event_source_url]" value="1" ' . checked($send_event_source_url, true, false) . ' /> ';
    echo esc_html__('Include event_source_url in API requests', 'meta-offline-conversions') . '</label>';
    echo '<p class="description">' . esc_html__('When enabled, sends the checkout order URL to Meta. Disable for additional privacy or if Meta flags URL data.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Log Request Payload', 'meta-offline-conversions') . '</th><td>';
    echo '<label><input type="checkbox" name="' . esc_attr(MOC_OPTION_KEY) . '[log_request_payload]" value="1" ' . checked($log_request_payload, true, false) . ' /> ';
    echo esc_html__('Log request data sent to Meta API', 'meta-offline-conversions') . '</label>';
    echo '<p class="description">' . esc_html__('Enable to see the full payload sent to Meta in the Event Log. Useful for debugging. Disable to only see API responses.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Auto Backfill (WP-Cron)', 'meta-offline-conversions') . '</th><td>';
    echo '<label><input type="checkbox" name="' . esc_attr(MOC_OPTION_KEY) . '[enable_cron]" value="1" ' . checked($enable_cron, true, false) . ' /> ';
    echo esc_html__('Enable automatic backfill of past orders', 'meta-offline-conversions') . '</label>';
    echo '<p class="description">' . esc_html__('WP-Cron must be running for scheduled backfills.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Backfill Interval', 'meta-offline-conversions') . '</th><td>';
    echo '<select name="' . esc_attr(MOC_OPTION_KEY) . '[cron_interval]">';
    foreach (moc_get_cron_interval_options() as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' . selected($cron_interval, $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__('Next run:', 'meta-offline-conversions') . ' ' . esc_html($cron_next_human) . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Backfill Batch Size', 'meta-offline-conversions') . '</th><td>';
    echo '<input type="number" name="' . esc_attr(MOC_OPTION_KEY) . '[cron_batch_size]" min="1" max="200" value="' . esc_attr((string) $cron_batch_size) . '" />';
    echo '<p class="description">' . esc_html__('Number of completed orders per cron run.', 'meta-offline-conversions') . '</p>';
    echo '</td></tr>';

    echo '</table>';

    submit_button();
    echo '</form>';

    echo '<hr />';
    echo '<h2>' . esc_html__('Send Past Orders', 'meta-offline-conversions') . '</h2>';
    echo '<p>' . esc_html__('Click the button to send completed orders that have not been sent yet.', 'meta-offline-conversions') . '</p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="moc_send_past_orders" />';
    wp_nonce_field('moc_send_past_orders');
    echo '<label>' . esc_html__('Limit', 'meta-offline-conversions') . ' '; 
    echo '<input type="number" name="limit" min="1" max="200" value="50" /></label> ';
    submit_button(__('Send Now', 'meta-offline-conversions'), 'secondary', 'submit', false);
    echo '</form>';

    $log = moc_get_bulk_log();
    echo '<hr />';
    echo '<h2>' . esc_html__('Bulk Log', 'meta-offline-conversions') . '</h2>';
    if (empty($log)) {
        echo '<p>' . esc_html__('No bulk log available yet.', 'meta-offline-conversions') . '</p>';
    } else {
        $run_at = !empty($log['run_at']) ? esc_html($log['run_at']) : '';
        $trigger = !empty($log['trigger']) ? esc_html($log['trigger']) : '';
        $total = isset($log['total']) ? (int) $log['total'] : 0;
        $sent = isset($log['sent']) ? (int) $log['sent'] : 0;
        $errors = isset($log['errors']) ? (int) $log['errors'] : 0;
        $skipped = isset($log['skipped']) ? (int) $log['skipped'] : 0;

        echo '<p>' . sprintf(
            esc_html__('Last run: %s | Trigger: %s | Total: %d | Sent: %d | Skipped: %d | Errors: %d', 'meta-offline-conversions'),
            $run_at ? $run_at : esc_html__('unknown', 'meta-offline-conversions'),
            $trigger ? $trigger : esc_html__('unknown', 'meta-offline-conversions'),
            $total,
            $sent,
            $skipped,
            $errors
        ) . '</p>';

        if (!empty($log['items']) && is_array($log['items'])) {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Order ID', 'meta-offline-conversions') . '</th>';
            echo '<th>' . esc_html__('Status', 'meta-offline-conversions') . '</th>';
            echo '<th>' . esc_html__('Message', 'meta-offline-conversions') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($log['items'] as $item) {
                $order_id = isset($item['order_id']) ? (int) $item['order_id'] : 0;
                $status = isset($item['status']) ? esc_html($item['status']) : '';
                $message = isset($item['message']) ? esc_html($item['message']) : '';
                echo '<tr>';
                echo '<td>' . esc_html((string) $order_id) . '</td>';
                echo '<td>' . $status . '</td>';
                echo '<td>' . $message . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    echo '<hr />';
    echo '<h2>' . esc_html__('Event Log', 'meta-offline-conversions') . '</h2>';
    echo '<p>' . esc_html__('Recent plugin events from manual send, cron, and direct order send.', 'meta-offline-conversions') . '</p>';

    echo '<div style="margin-bottom:12px;">';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:10px;">';
    echo '<input type="hidden" name="action" value="moc_clear_admin_log" />';
    wp_nonce_field('moc_clear_admin_log');
    submit_button(__('Clear Log', 'meta-offline-conversions'), 'secondary', 'submit', false);
    echo '</form>';
    
    $log_filter = isset($_GET['log_filter']) ? sanitize_text_field($_GET['log_filter']) : 'all';
    echo '<div style="display:inline-block;">';
    echo '<a href="' . esc_url(add_query_arg(['page' => 'moc-settings', 'log_filter' => 'all'], admin_url('admin.php'))) . '" class="button' . ($log_filter === 'all' ? ' button-primary' : '') . '">' . esc_html__('All', 'meta-offline-conversions') . '</a> ';
    echo '<a href="' . esc_url(add_query_arg(['page' => 'moc-settings', 'log_filter' => 'request'], admin_url('admin.php'))) . '" class="button' . ($log_filter === 'request' ? ' button-primary' : '') . '">' . esc_html__('Requests', 'meta-offline-conversions') . '</a> ';
    echo '<a href="' . esc_url(add_query_arg(['page' => 'moc-settings', 'log_filter' => 'response'], admin_url('admin.php'))) . '" class="button' . ($log_filter === 'response' ? ' button-primary' : '') . '">' . esc_html__('Responses', 'meta-offline-conversions') . '</a>';
    echo '</div>';
    echo '</div>';

    $admin_log = moc_get_admin_log();
    if (empty($admin_log)) {
        echo '<p>' . esc_html__('No event log entries yet.', 'meta-offline-conversions') . '</p>';
    } else {
        $display_max = (int) apply_filters('moc_admin_log_display_max_items', 150);
        if ($display_max < 10) {
            $display_max = 10;
        }

        $entries = array_reverse($admin_log);
        
        // Filter entries based on selection
        if ($log_filter !== 'all') {
            $entries = array_filter($entries, function($entry) use ($log_filter) {
                $entry_type = isset($entry['type']) ? $entry['type'] : 'response';
                return $entry_type === $log_filter;
            });
        }
        
        if (count($entries) > $display_max) {
            $entries = array_slice($entries, 0, $display_max);
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Time', 'meta-offline-conversions') . '</th>';
        echo '<th>' . esc_html__('Level', 'meta-offline-conversions') . '</th>';
        echo '<th>' . esc_html__('Message', 'meta-offline-conversions') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $time = isset($entry['time']) ? (string) $entry['time'] : '';
            $level = isset($entry['level']) ? strtolower((string) $entry['level']) : 'info';
            $message = isset($entry['message']) ? (string) $entry['message'] : '';
            $context = isset($entry['context']) ? (string) $entry['context'] : '';

            echo '<tr>';
            echo '<td>' . esc_html($time !== '' ? $time : __('unknown', 'meta-offline-conversions')) . '</td>';
            echo '<td>' . esc_html(strtoupper($level)) . '</td>';
            echo '<td>' . esc_html($message);
            if ($context !== '') {
                echo '<br /><code>' . esc_html($context) . '</code>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div>';
}

function moc_sanitize_settings($input) {
    $settings = moc_get_settings();
    $output = $settings;

    $pixel_id = isset($input['pixel_id']) ? sanitize_text_field($input['pixel_id']) : '';
    $output['pixel_id'] = $pixel_id;

    $output['debug_log'] = !empty($input['debug_log']) ? 1 : 0;
    $output['cheque_test_mode'] = !empty($input['cheque_test_mode']) ? 1 : 0;

    $event_name = isset($input['event_name']) ? sanitize_text_field($input['event_name']) : 'Purchase';
    if (empty($event_name)) {
        $event_name = 'Purchase';
    }
    $output['event_name'] = $event_name;

    $output['minimal_data_mode'] = !empty($input['minimal_data_mode']) ? 1 : 0;
    $output['eu_compliant_mode'] = !empty($input['eu_compliant_mode']) ? 1 : 0;
    $output['log_request_payload'] = !empty($input['log_request_payload']) ? 1 : 0;

    $test_event_code = isset($input['test_event_code']) ? sanitize_text_field($input['test_event_code']) : '';
    $output['test_event_code'] = $test_event_code;
    $output['enable_test_events'] = !empty($input['enable_test_events']) ? 1 : 0;

    $output['enable_cron'] = !empty($input['enable_cron']) ? 1 : 0;
    $interval = isset($input['cron_interval']) ? sanitize_text_field($input['cron_interval']) : 'hourly';
    if (!moc_is_valid_cron_interval($interval)) {
        $interval = 'hourly';
    }
    $output['cron_interval'] = $interval;

    $batch_size = isset($input['cron_batch_size']) ? (int) $input['cron_batch_size'] : 50;
    if ($batch_size < 1) {
        $batch_size = 1;
    } elseif ($batch_size > 200) {
        $batch_size = 200;
    }
    $output['cron_batch_size'] = $batch_size;

    $clear_token = !empty($input['clear_token']);

    if ($clear_token) {
        $output['access_token'] = '';
        $output['token_last4'] = '';
        $output['token_set_at'] = '';
    } elseif (!empty($input['access_token'])) {
        $token = sanitize_text_field($input['access_token']);
        $output['access_token'] = moc_encrypt($token);
        $output['token_last4'] = substr($token, -4);
        $output['token_set_at'] = current_time('mysql');
    }

    moc_sync_cron_settings($settings, $output);

    return $output;
}

function moc_get_settings() {
    $settings = get_option(MOC_OPTION_KEY, []);
    return is_array($settings) ? $settings : [];
}

function moc_debug_enabled() {
    $settings = moc_get_settings();
    return !empty($settings['debug_log']);
}

function moc_cheque_test_mode_enabled() {
    $settings = moc_get_settings();
    return !empty($settings['cheque_test_mode']);
}

function moc_send_purchase_to_meta_cheque_test($order_id) {
    if (!moc_cheque_test_mode_enabled()) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        moc_log("Cheque test mode: order #{$order_id} not found.", 'error');
        return;
    }

    if ($order->get_payment_method() !== 'cheque') {
        return;
    }

    moc_log("Cheque test mode: sending order #{$order_id} on status '{$order->get_status()}'.", 'info');
    moc_send_purchase_to_meta($order_id, false);
}

function moc_get_admin_log() {
    $log = get_option(MOC_ADMIN_LOG_OPTION, []);
    return is_array($log) ? $log : [];
}

function moc_get_admin_log_limit() {
    $limit = (int) apply_filters('moc_admin_log_max_items', 500);
    if ($limit < 50) {
        $limit = 50;
    }
    return $limit;
}

function moc_store_admin_log($entries) {
    update_option(MOC_ADMIN_LOG_OPTION, $entries, false);
}

function moc_clear_admin_log() {
    delete_option(MOC_ADMIN_LOG_OPTION);
}

function moc_add_admin_log_entry($message, $level = 'info', $context = [], $type = 'response') {
    $entries = moc_get_admin_log();

    $context_text = '';
    if (!empty($context)) {
        $context_json = wp_json_encode((array) $context);
        if (is_string($context_json)) {
            $context_text = moc_shorten_message($context_json, 1000);
        }
    }

    $entries[] = [
        'time' => current_time('mysql'),
        'level' => sanitize_text_field((string) $level),
        'message' => moc_shorten_message((string) $message, 1000),
        'context' => $context_text,
        'type' => sanitize_text_field((string) $type),
    ];

    $limit = moc_get_admin_log_limit();
    if (count($entries) > $limit) {
        $entries = array_slice($entries, -$limit);
    }

    moc_store_admin_log($entries);
}

function moc_log($message, $level = 'info', $context = []) {
    $message = '[Meta Offline] ' . $message;

    if ($level === 'debug' && !moc_debug_enabled()) {
        return;
    }

    moc_add_admin_log_entry($message, $level, $context);

    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $logger->log($level, $message, array_merge(['source' => 'meta-offline-conversions'], (array) $context));
    }

    if (moc_debug_enabled() || $level !== 'debug') {
        error_log($message);
    }
}

function moc_log_with_type($message, $level = 'info', $context = [], $type = 'response') {
    $message = '[Meta Offline] ' . $message;

    if ($level === 'debug' && !moc_debug_enabled()) {
        return;
    }

    moc_add_admin_log_entry($message, $level, $context, $type);

    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $logger->log($level, $message, array_merge(['source' => 'meta-offline-conversions'], (array) $context));
    }

    if (moc_debug_enabled() || $level !== 'debug') {
        error_log($message);
    }
}

function moc_get_bulk_log() {
    $log = get_option(MOC_BULK_LOG_OPTION, []);
    return is_array($log) ? $log : [];
}

function moc_store_bulk_log($log) {
    update_option(MOC_BULK_LOG_OPTION, $log, false);
}

function moc_bulk_lock() {
    return (bool) get_transient('moc_bulk_lock');
}

function moc_set_bulk_lock($ttl_seconds = 900) {
    set_transient('moc_bulk_lock', 1, $ttl_seconds);
}

function moc_clear_bulk_lock() {
    delete_transient('moc_bulk_lock');
}

function moc_shorten_message($message, $limit = 200) {
    $message = (string) $message;
    if (strlen($message) <= $limit) {
        return $message;
    }
    return substr($message, 0, $limit - 3) . '...';
}

function moc_result($status, $message = '') {
    return [
        'status' => $status,
        'message' => $message,
    ];
}

function moc_get_pixel_id() {
    $settings = moc_get_settings();
    return !empty($settings['pixel_id']) ? $settings['pixel_id'] : '';
}

function moc_get_access_token() {
    $settings = moc_get_settings();
    if (empty($settings['access_token'])) {
        return '';
    }

    return moc_decrypt($settings['access_token']);
}

function moc_get_crypto_key() {
    $key = wp_salt('auth') . wp_salt('secure_auth');
    return hash('sha256', $key, true);
}

function moc_encrypt($plaintext) {
    if ($plaintext === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt')) {
        return 'raw:' . $plaintext;
    }

    try {
        $iv = random_bytes(16);
    } catch (Exception $e) {
        return 'raw:' . $plaintext;
    }

    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', moc_get_crypto_key(), OPENSSL_RAW_DATA, $iv);

    if ($ciphertext === false) {
        return 'raw:' . $plaintext;
    }

    return 'enc:' . base64_encode($iv . $ciphertext);
}

function moc_decrypt($stored) {
    if ($stored === '') {
        return '';
    }

    if (strpos($stored, 'enc:') === 0) {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $encoded = substr($stored, 4);
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) <= 16) {
            return '';
        }

        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', moc_get_crypto_key(), OPENSSL_RAW_DATA, $iv);

        return $plaintext === false ? '' : $plaintext;
    }

    if (strpos($stored, 'raw:') === 0) {
        return substr($stored, 4);
    }

    return $stored;
}

function moc_maybe_set_fb_cookies() {
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    $expiry = time() + apply_filters('moc_cookie_expiry', 90 * DAY_IN_SECONDS);
    $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
    $secure = is_ssl();
    $http_only = false;

    if (isset($_GET['fbclid'])) {
        $fbclid = sanitize_text_field(wp_unslash($_GET['fbclid']));
        if ($fbclid !== '') {
            $fbc = 'fb.1.' . time() . '.' . $fbclid;
            setcookie('_fbc', $fbc, $expiry, $path, $domain, $secure, $http_only);
            $_COOKIE['_fbc'] = $fbc;
        }
    } elseif (!empty($_COOKIE['_fbc'])) {
        $fbc = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
        setcookie('_fbc', $fbc, $expiry, $path, $domain, $secure, $http_only);
    }

    if (!empty($_COOKIE['_fbp'])) {
        $fbp = sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
        setcookie('_fbp', $fbp, $expiry, $path, $domain, $secure, $http_only);
    }
}

function moc_save_fb_cookies_to_order($order_id) {
    if (isset($_COOKIE['_fbp'])) {
        update_post_meta($order_id, '_fbp_cookie', sanitize_text_field(wp_unslash($_COOKIE['_fbp'])));
    }

    if (isset($_COOKIE['_fbc'])) {
        update_post_meta($order_id, '_fbc_cookie', sanitize_text_field(wp_unslash($_COOKIE['_fbc'])));
    }

    if (isset($_GET['fbclid'])) {
        update_post_meta($order_id, '_fbclid', sanitize_text_field(wp_unslash($_GET['fbclid'])));
    }

    if (!empty($_SERVER['REMOTE_ADDR'])) {
        update_post_meta($order_id, '_client_ip', sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])));
    }

    if (!empty($_SERVER['HTTP_USER_AGENT'])) {
        update_post_meta($order_id, '_client_user_agent', sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])));
    }
}

function moc_send_purchase_to_meta($order_id, $force = false) {
    if (!$force && get_post_meta($order_id, '_meta_offline_sent', true)) {
        moc_log("Order #{$order_id} already sent, skipping.", 'debug');
        return moc_result('skipped', 'already_sent');
    }

    $pixel_id = moc_get_pixel_id();
    $access_token = moc_get_access_token();

    if (empty($pixel_id) || empty($access_token)) {
        moc_log("Missing Pixel ID or Access Token. Order #{$order_id} skipped.", 'error');
        return moc_result('error', 'missing_config');
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        moc_log("Order #{$order_id} not found.", 'error');
        return moc_result('error', 'order_not_found');
    }

    $email = $order->get_billing_email();
    if (empty($email)) {
        moc_log("Order #{$order_id} has no email, skipping.", 'error');
        return moc_result('error', 'missing_email');
    }

    $user_data = [
        'em' => hash('sha256', strtolower(trim($email))),
    ];

    $phone = $order->get_billing_phone();
    if (!empty($phone)) {
        $phone_clean = preg_replace('/[^0-9]/', '', $phone);
        if ($phone_clean !== '') {
            $user_data['ph'] = hash('sha256', $phone_clean);
        }
    }

    $first_name = $order->get_billing_first_name();
    if (!empty($first_name)) {
        $user_data['fn'] = hash('sha256', strtolower(trim($first_name)));
    }

    $last_name = $order->get_billing_last_name();
    if (!empty($last_name)) {
        $user_data['ln'] = hash('sha256', strtolower(trim($last_name)));
    }

    $city = $order->get_billing_city();
    if (!empty($city)) {
        $user_data['ct'] = hash('sha256', strtolower(trim($city)));
    }

    $state = $order->get_billing_state();
    if (!empty($state)) {
        $user_data['st'] = hash('sha256', strtolower(trim($state)));
    }

    $postcode = $order->get_billing_postcode();
    if (!empty($postcode)) {
        $user_data['zp'] = hash('sha256', trim($postcode));
    }

    $country = $order->get_billing_country();
    if (!empty($country)) {
        $user_data['country'] = hash('sha256', strtolower($country));
    }

    $settings = moc_get_settings();
    $eu_compliant_mode = !empty($settings['eu_compliant_mode']);

    // FBP cookie - skip in EU compliant mode
    if (!$eu_compliant_mode) {
        $fbp = get_post_meta($order_id, '_fbp_cookie', true);
        if (!empty($fbp)) {
            $user_data['fbp'] = $fbp;
        }
    }

    // FBC cookie - skip in EU compliant mode
    if (!$eu_compliant_mode) {
        $fbc = get_post_meta($order_id, '_fbc_cookie', true);
        if (empty($fbc)) {
            $fbclid = get_post_meta($order_id, '_fbclid', true);
            if (!empty($fbclid)) {
                $fbc = 'fb.1.' . time() . '.' . $fbclid;
            }
        }
        if (!empty($fbc)) {
            $user_data['fbc'] = $fbc;
        }
    }

    $client_ip = get_post_meta($order_id, '_client_ip', true);
    if (!empty($client_ip)) {
        $user_data['client_ip_address'] = $client_ip;
    }

    $user_agent = get_post_meta($order_id, '_client_user_agent', true);
    if (!empty($user_agent)) {
        $user_data['client_user_agent'] = $user_agent;
    }

    $settings = moc_get_settings();
    $minimal_data_mode = !empty($settings['minimal_data_mode']);

    if ($minimal_data_mode) {
        // Minimal mode: only value and currency to avoid policy violations
        $custom_data = [
            'value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
        ];
    } else {
        // Normal mode: include product information
        $content_ids = [];
        $contents = [];
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $content_ids[] = (string) $product_id;
            $contents[] = [
                'id' => (string) $product_id,
                'quantity' => $item->get_quantity(),
            ];
        }

        $custom_data = [
            'value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'content_type' => 'product',
            'content_ids' => $content_ids,
            'contents' => $contents,
            'num_items' => $order->get_item_count(),
        ];
    }

    $event_time_obj = $order->get_date_completed() ? $order->get_date_completed() : $order->get_date_created();
    $event_time = $event_time_obj ? $event_time_obj->getTimestamp() : time();
    
    $settings = moc_get_settings();
    $event_name = !empty($settings['event_name']) ? $settings['event_name'] : 'Purchase';

    // Build base event data
    $event_data = [
        'event_name' => $event_name,
        'event_time' => $event_time,
        'event_id' => (string) $order_id,
        'action_source' => 'website',
        'user_data' => $user_data,
        'custom_data' => $custom_data,
    ];

    // Add event_source_url only if enabled
    $send_event_source_url = isset($settings['send_event_source_url']) ? (bool)$settings['send_event_source_url'] : true;
    if ($send_event_source_url) {
        $event_source_url = $order->get_checkout_order_received_url();
        if (empty($event_source_url)) {
            $event_source_url = home_url('/');
        }
        $event_data['event_source_url'] = $event_source_url;
    }

    $api_version = apply_filters('moc_meta_api_version', 'v21.0');
    $endpoint = 'https://graph.facebook.com/' . $api_version . '/' . rawurlencode($pixel_id) . '/events';

    // Log request payload if enabled
    if (!empty($settings['log_request_payload'])) {
        $payload_for_log = [
            'data' => [$event_data],
            'access_token' => '****' . substr($access_token, -4)
        ];
        moc_log_with_type("Request payload for order #{$order_id}: " . wp_json_encode($payload_for_log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'debug', [], 'request');
    }

    // Build API request body
    $api_body = [
        'data' => [$event_data],
        'access_token' => $access_token,
    ];

    // Add test_event_code if test mode is enabled
    if (!empty($settings['enable_test_events']) && !empty($settings['test_event_code'])) {
        $api_body['test_event_code'] = sanitize_text_field($settings['test_event_code']);
        moc_log("Test mode enabled - using test_event_code: {$api_body['test_event_code']}", 'debug');
    }

    moc_log("Sending {$event_name} event for order #{$order_id} to {$endpoint}.", 'debug');

    $response = wp_remote_post(
        $endpoint,
        [
            'body' => wp_json_encode($api_body),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {
        moc_log("Meta Offline Error (Order #{$order_id}): " . $response->get_error_message(), 'error');
        return moc_result('error', moc_shorten_message($response->get_error_message()));
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status_code === 200) {
        $response_data = json_decode($body, true);
        if (isset($response_data['events_received']) && $response_data['events_received'] > 0) {
            update_post_meta($order_id, '_meta_offline_sent', current_time('mysql'));
            moc_log_with_type("Meta response 200 OK for order #{$order_id}. Full response: " . $body, 'debug', [], 'response');
            return moc_result('sent', 'events_received:' . (int) $response_data['events_received']);
        }
    }

    moc_log_with_type("Meta Offline Response (Order #{$order_id}): Status {$status_code} - " . moc_shorten_message($body, 500), 'error', [], 'response');
    $message = 'http_' . $status_code . ': ' . moc_shorten_message($body);
    return moc_result('error', $message);
}

function moc_send_past_orders_to_meta_bulk($limit = 50, $trigger = 'manual') {
    if (moc_bulk_lock()) {
        return [
            'locked' => true,
            'total' => 0,
            'sent' => 0,
            'errors' => 0,
            'skipped' => 0,
            'items' => [],
        ];
    }

    moc_set_bulk_lock();
    moc_log("Bulk send started. trigger={$trigger}, limit={$limit}.", 'info');

    $args = [
        'status' => 'completed',
        'limit' => $limit,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => [
            [
                'key' => '_meta_offline_sent',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ];

    $orders = wc_get_orders($args);

    $total = count($orders);
    $sent = 0;
    $errors = 0;
    $skipped = 0;
    $items = [];

    $sleep_ms = (int) apply_filters('moc_bulk_sleep_ms', 250);
    $max_items = (int) apply_filters('moc_bulk_log_max_items', 200);

    foreach ($orders as $order) {
        $result = moc_send_purchase_to_meta($order->get_id());
        $status = isset($result['status']) ? $result['status'] : 'error';
        $message = isset($result['message']) ? $result['message'] : '';

        if ($status === 'sent') {
            $sent++;
        } elseif ($status === 'skipped') {
            $skipped++;
        } else {
            $errors++;
        }

        if (count($items) < $max_items) {
            $items[] = [
                'order_id' => $order->get_id(),
                'status' => $status,
                'message' => moc_shorten_message($message),
            ];
        }

        if ($sleep_ms > 0) {
            usleep($sleep_ms * 1000);
        }
    }

    $log = [
        'run_at' => current_time('mysql'),
        'trigger' => $trigger,
        'total' => $total,
        'sent' => $sent,
        'errors' => $errors,
        'skipped' => $skipped,
        'items' => $items,
    ];

    moc_store_bulk_log($log);
    moc_clear_bulk_lock();
    moc_log("Bulk send finished. trigger={$trigger}, total={$total}, sent={$sent}, skipped={$skipped}, errors={$errors}.", 'info');

    return $log;
}

function moc_cron_send_past_orders() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    $settings = moc_get_settings();
    if (empty($settings['enable_cron'])) {
        return;
    }

    $limit = !empty($settings['cron_batch_size']) ? (int) $settings['cron_batch_size'] : 50;
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 200) {
        $limit = 200;
    }

    $result = moc_send_past_orders_to_meta_bulk($limit, 'cron');
    if (!empty($result['locked'])) {
        return;
    }
}

function moc_handle_send_past_orders() {
    if (!current_user_can(MOC_CAPABILITY)) {
        wp_die(esc_html__('Insufficient permissions.', 'meta-offline-conversions'));
    }

    check_admin_referer('moc_send_past_orders');

    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 200) {
        $limit = 200;
    }

    $result = moc_send_past_orders_to_meta_bulk($limit, 'manual');

    if (!empty($result['locked'])) {
        $redirect = add_query_arg(
            [
                'page' => 'moc-settings',
                'moc_bulk_locked' => 1,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    $redirect = add_query_arg(
        [
            'page' => 'moc-settings',
            'moc_bulk_sent' => isset($result['sent']) ? $result['sent'] : 0,
            'moc_bulk_total' => isset($result['total']) ? $result['total'] : 0,
            'moc_bulk_errors' => isset($result['errors']) ? $result['errors'] : 0,
            'moc_bulk_skipped' => isset($result['skipped']) ? $result['skipped'] : 0,
        ],
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}

function moc_handle_clear_admin_log() {
    if (!current_user_can(MOC_CAPABILITY)) {
        wp_die(esc_html__('Insufficient permissions.', 'meta-offline-conversions'));
    }

    check_admin_referer('moc_clear_admin_log');
    moc_clear_admin_log();

    $redirect = add_query_arg(
        [
            'page' => 'moc-settings',
            'moc_admin_log_cleared' => 1,
        ],
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
