<?php
/*
Plugin Name: پلاگین اشتراک سفارشات ووکامرس در ایتا و بله
Plugin URI: https://hamidarab.ir
Description: ارسال سفارشات ووکامرس به Eitaayar (ایتا) و Bale (بله) - توسعه دهنده حمید اعراب
Author: حمید اعراب
Version: 2.1.0
Licence: GPLv2 or Later
Author URI: https://hamidarab.ir
*/

defined('ABSPATH') || exit;

define('EITA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EITA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EITA_PLUGIN_DIR . '/class/EitaaAPI.php';

/**
 * Settings + Admin UI
 */
add_action('admin_init', 'eitaa_register_settings');
add_action('admin_menu', 'eitaa_add_settings_page');

/**
 * Flush status cache when credentials change
 */
add_action('update_option_eitaa_api_token', 'eitaa_flush_all_status_cache', 10, 3);
add_action('update_option_eitaa_chat_id', 'eitaa_flush_all_status_cache', 10, 3);
add_action('update_option_eitaa_eitaayar_base_url', 'eitaa_flush_all_status_cache', 10, 3);
add_action('update_option_eitaa_eitaayar_enabled', 'eitaa_flush_all_status_cache', 10, 3);

add_action('update_option_eitaa_bale_api_token', 'eitaa_flush_all_status_cache', 10, 3);
add_action('update_option_eitaa_bale_chat_id', 'eitaa_flush_all_status_cache', 10, 3);
add_action('update_option_eitaa_bale_base_url', 'eitaa_flush_all_status_cache', 10, 3);
add_action('update_option_eitaa_bale_enabled', 'eitaa_flush_all_status_cache', 10, 3);

add_action('add_option_eitaa_api_token', 'eitaa_flush_all_status_cache_on_add', 10, 2);
add_action('add_option_eitaa_chat_id', 'eitaa_flush_all_status_cache_on_add', 10, 2);
add_action('add_option_eitaa_eitaayar_base_url', 'eitaa_flush_all_status_cache_on_add', 10, 2);
add_action('add_option_eitaa_eitaayar_enabled', 'eitaa_flush_all_status_cache_on_add', 10, 2);

add_action('add_option_eitaa_bale_api_token', 'eitaa_flush_all_status_cache_on_add', 10, 2);
add_action('add_option_eitaa_bale_chat_id', 'eitaa_flush_all_status_cache_on_add', 10, 2);
add_action('add_option_eitaa_bale_base_url', 'eitaa_flush_all_status_cache_on_add', 10, 2);
add_action('add_option_eitaa_bale_enabled', 'eitaa_flush_all_status_cache_on_add', 10, 2);

/**
 * Admin toolbar badge
 */
add_action('admin_bar_menu', 'eitaa_add_toolbar_status_node', 100);
add_action('admin_head', 'eitaa_toolbar_inline_styles');
add_action('wp_head', 'eitaa_toolbar_inline_styles');

function eitaa_register_settings()
{
    // Eitaayar (existing options token/chat_id kept for backward-compat)
    register_setting('eitaa_settings_group', 'eitaa_eitaayar_enabled', [
        'sanitize_callback' => 'eitaa_sanitize_checkbox',
        'default' => 1,
    ]);

    register_setting('eitaa_settings_group', 'eitaa_eitaayar_base_url', [
        'sanitize_callback' => 'eitaa_sanitize_base_url',
        'default' => 'https://eitaayar.ir',
    ]);

    register_setting('eitaa_settings_group', 'eitaa_api_token', [
        'sanitize_callback' => 'eitaa_encode_setting_value',
    ]);

    register_setting('eitaa_settings_group', 'eitaa_chat_id', [
        'sanitize_callback' => 'eitaa_encode_setting_value',
    ]);

    // Bale
    register_setting('eitaa_settings_group', 'eitaa_bale_enabled', [
        'sanitize_callback' => 'eitaa_sanitize_checkbox',
        'default' => 0,
    ]);

    register_setting('eitaa_settings_group', 'eitaa_bale_base_url', [
        'sanitize_callback' => 'eitaa_sanitize_base_url',
        'default' => 'https://tapi.bale.ai',
    ]);

    register_setting('eitaa_settings_group', 'eitaa_bale_api_token', [
        'sanitize_callback' => 'eitaa_encode_setting_value',
    ]);

    register_setting('eitaa_settings_group', 'eitaa_bale_chat_id', [
        'sanitize_callback' => 'eitaa_encode_setting_value',
    ]);
}

function eitaa_add_settings_page()
{
    add_menu_page(
        'تنظیمات ایتا/بله',
        'ایتــا/بله',
        'manage_options',
        'eitaa-settings',
        'eitaa_render_settings_page',
        'dashicons-format-chat'
    );
}

function eitaa_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $eitaayar_enabled = (int) get_option('eitaa_eitaayar_enabled', 1);
    $eitaayar_base    = (string) get_option('eitaa_eitaayar_base_url', 'https://eitaayar.ir');
    $eitaayar_token   = eitaa_get_decoded_option_value('eitaa_api_token');
    $eitaayar_chat    = eitaa_get_decoded_option_value('eitaa_chat_id');

    $bale_enabled = (int) get_option('eitaa_bale_enabled', 0);
    $bale_base    = (string) get_option('eitaa_bale_base_url', 'https://tapi.bale.ai');
    $bale_token   = eitaa_get_decoded_option_value('eitaa_bale_api_token');
    $bale_chat    = eitaa_get_decoded_option_value('eitaa_bale_chat_id');

    $status_eitaayar = wp_parse_args(EitaaAPI::get_api_status(EitaaAPI::PROVIDER_EITAAYAR, false), [
        'status' => 'unknown', 'label' => 'نامشخص', 'description' => '',
    ]);

    $status_bale = wp_parse_args(EitaaAPI::get_api_status(EitaaAPI::PROVIDER_BALE, false), [
        'status' => 'unknown', 'label' => 'نامشخص', 'description' => '',
    ]);
    ?>
    <div class="wrap">
        <h1>تنظیمات اتصال ایتا/بله</h1>

        <form method="post" action="options.php">
            <?php settings_fields('eitaa_settings_group'); ?>

            <h2>ایتا (از طریق Eitaayar)</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">فعال باشد؟</th>
                    <td>
                        <label>
                            <input type="checkbox" name="eitaa_eitaayar_enabled" value="1" <?php checked(1, $eitaayar_enabled); ?> />
                            ارسال سفارش‌ها به ای‌تا فعال شود
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eitaa_eitaayar_base_url">Base URL</label></th>
                    <td>
                        <input type="url" class="regular-text" id="eitaa_eitaayar_base_url" name="eitaa_eitaayar_base_url" value="<?php echo esc_attr($eitaayar_base); ?>" />
                        <p class="description">پیش‌فرض: https://eitaayar.ir (الگو: /api/TOKEN/METHOD)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eitaa_api_token">توکن</label></th>
                    <td>
                        <input type="text" class="regular-text" id="eitaa_api_token" name="eitaa_api_token" value="<?php echo esc_attr($eitaayar_token); ?>" autocomplete="off" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eitaa_chat_id">Chat ID</label></th>
                    <td>
                        <input type="text" class="regular-text" id="eitaa_chat_id" name="eitaa_chat_id" value="<?php echo esc_attr($eitaayar_chat); ?>" autocomplete="off" />
                    </td>
                </tr>
            </table>

            <p>
                وضعیت: <?php echo wp_kses_post(eitaa_get_status_badge_html($status_eitaayar)); ?>
                <?php if (!empty($status_eitaayar['description']) && 'connected' !== $status_eitaayar['status']) : ?>
                    <br><span class="description"><?php echo esc_html($status_eitaayar['description']); ?></span>
                <?php endif; ?>
            </p>

            <hr>

            <h2>بله (Bale Bot API)</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">فعال باشد؟</th>
                    <td>
                        <label>
                            <input type="checkbox" name="eitaa_bale_enabled" value="1" <?php checked(1, $bale_enabled); ?> />
                            ارسال سفارش‌ها به بله فعال شود
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eitaa_bale_base_url">Base URL</label></th>
                    <td>
                        <input type="url" class="regular-text" id="eitaa_bale_base_url" name="eitaa_bale_base_url" value="<?php echo esc_attr($bale_base); ?>" />
                        <p class="description">پیش‌فرض: https://tapi.bale.ai (الگو: /bot&lt;TOKEN&gt;/METHOD)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eitaa_bale_api_token">توکن بله</label></th>
                    <td>
                        <input type="text" class="regular-text" id="eitaa_bale_api_token" name="eitaa_bale_api_token" value="<?php echo esc_attr($bale_token); ?>" autocomplete="off" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eitaa_bale_chat_id">Chat ID بله</label></th>
                    <td>
                        <input type="text" class="regular-text" id="eitaa_bale_chat_id" name="eitaa_bale_chat_id" value="<?php echo esc_attr($bale_chat); ?>" autocomplete="off" />
                    </td>
                </tr>
            </table>

            <p>
                وضعیت: <?php echo wp_kses_post(eitaa_get_status_badge_html($status_bale)); ?>
                <?php if (!empty($status_bale['description']) && 'connected' !== $status_bale['status']) : ?>
                    <br><span class="description"><?php echo esc_html($status_bale['description']); ?></span>
                <?php endif; ?>
            </p>

            <?php submit_button('ذخیره تنظیمات'); ?>
        </form>
    </div>
    <?php
}

function eitaa_get_decoded_option_value($option_name)
{
    $value = get_option($option_name, '');
    if (empty($value)) {
        return '';
    }
    $decoded = base64_decode($value, true);
    return $decoded !== false ? $decoded : $value;
}

function eitaa_encode_setting_value($value)
{
    if (!is_string($value)) {
        return '';
    }

    $value = trim(wp_unslash($value));
    if ($value === '') {
        return '';
    }

    $sanitized = base64_encode(sanitize_text_field($value));
    EitaaAPI::flush_status_cache_all();

    return $sanitized;
}

function eitaa_sanitize_base_url($value)
{
    $value = is_string($value) ? trim(wp_unslash($value)) : '';
    if ($value === '') {
        return '';
    }

    $value = esc_url_raw($value);
    // remove trailing slash for consistent URL building
    return untrailingslashit($value);
}

function eitaa_sanitize_checkbox($value)
{
    return (int) (!empty($value) ? 1 : 0);
}

function eitaa_get_status_badge_html($status)
{
    $status_key = isset($status['status']) ? $status['status'] : 'unknown';
    $label      = isset($status['label']) ? $status['label'] : 'نامشخص';

    $color = '#555d66';
    if ('connected' === $status_key) {
        $color = '#1a7f1a';
    } elseif ('disconnected' === $status_key || 'error' === $status_key) {
        $color = '#d63638';
    } elseif ('not_configured' === $status_key) {
        $color = '#dba617';
    }

    $class = 'eitaa-status-badge eitaa-status-' . sanitize_html_class($status_key);

    return sprintf(
        '<span class="%s" style="font-weight:600;color:%s;">%s</span>',
        esc_attr($class),
        esc_attr($color),
        esc_html($label)
    );
}

function eitaa_flush_all_status_cache($old_value, $value, $option)
{
    unset($old_value, $value, $option);
    EitaaAPI::flush_status_cache_all();
}

function eitaa_flush_all_status_cache_on_add($option, $value)
{
    unset($option, $value);
    EitaaAPI::flush_status_cache_all();
}

function eitaa_add_toolbar_status_node($wp_admin_bar)
{
    if (!is_user_logged_in() || !is_admin_bar_showing() || !current_user_can('manage_options')) {
        return;
    }

    $status_eitaayar = EitaaAPI::get_api_status(EitaaAPI::PROVIDER_EITAAYAR);
    $status_bale     = EitaaAPI::get_api_status(EitaaAPI::PROVIDER_BALE);

    $parts = [];

    if ((int) get_option('eitaa_eitaayar_enabled', 1) === 1) {
        $parts[] = 'ای‌تا: ' . wp_strip_all_tags(eitaa_get_status_badge_html($status_eitaayar));
    }

    if ((int) get_option('eitaa_bale_enabled', 0) === 1) {
        $parts[] = 'بله: ' . wp_strip_all_tags(eitaa_get_status_badge_html($status_bale));
    }

    if (empty($parts)) {
        $parts[] = 'هیچ سرویسی فعال نیست';
    }

    $title = sprintf(
        '<span class="ab-icon dashicons dashicons-testimonial" style="font-family: dashicons !important;"></span><span class="ab-label">%s</span>',
        esc_html__('پیام‌رسان‌ها', 'wp-eitaa'),
        ''
    );

    $wp_admin_bar->add_node([
        'id'     => 'eitaa-status',
        'parent' => 'top-secondary',
        'title'  => $title,
        'href'   => admin_url('admin.php?page=eitaa-settings'),
        'meta'   => [
            'class' => 'eitaa-toolbar-status-node',
            'title' => implode(' | ', $parts),
        ],
    ]);
}

function eitaa_toolbar_inline_styles()
{
    if (!is_user_logged_in() || !is_admin_bar_showing()) {
        return;
    }
    echo '<style id="eitaa-toolbar-status-styles">#wp-admin-bar-eitaa-status > .ab-item{display:flex;align-items:center;gap:6px;}#wp-admin-bar-eitaa-status .eitaa-status-badge{margin-inline-start:6px;}</style>';
}

/**
 * Auto send on processing
 */
add_action('woocommerce_order_status_processing', 'EitaaAPI::send_order_to_eitaa_group', 10, 1);

/**
 * Manual button in order admin
 */
add_action('woocommerce_admin_order_data_after_order_details', 'eitaa_order_button');
function eitaa_order_button($order) {
    $order_id = $order->get_id();
    $nonce    = wp_create_nonce('send_order_to_eitaa_nonce');
    ?>
    <style>
        /* Eitaa Modal (Admin) */
        .eitaa-modal-overlay{
            position:fixed; inset:0;
            background:rgba(0,0,0,.55);
            display:none;
            align-items:center;
            justify-content:center;
            z-index:100000; /* higher than WP admin */
        }
        .eitaa-modal{
            width:min(560px, calc(100% - 32px));
            background:#fff;
            border-radius:12px;
            box-shadow:0 10px 30px rgba(0,0,0,.25);
            overflow:hidden;
        }
        .eitaa-modal-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:14px 16px;
            border-bottom:1px solid #e5e5e5;
        }
        .eitaa-modal-title{
            margin:0;
            font-size:14px;
            font-weight:600;
        }
        .eitaa-modal-close{
            border:0;
            background:transparent;
            cursor:pointer;
            font-size:18px;
            line-height:1;
            padding:4px 8px;
        }
        .eitaa-modal-body{
            padding:14px 16px 16px;
            font-size:13px;
            line-height:1.8;
            white-space:pre-wrap;
            word-break:break-word;
        }
        .eitaa-modal-footer{
            padding:12px 16px;
            border-top:1px solid #e5e5e5;
            display:flex;
            justify-content:flex-end;
            gap:8px;
        }
        .eitaa-modal-badge{
            display:inline-flex;
            align-items:center;
            gap:6px;
            font-weight:600;
        }
        .eitaa-modal-badge.success{ color:#1a7f1a; }
        .eitaa-modal-badge.error{ color:#d63638; }
        .eitaa-modal-spinner{
            display:inline-block;
            width:14px;height:14px;
            border:2px solid rgba(0,0,0,.15);
            border-top-color:rgba(0,0,0,.55);
            border-radius:50%;
            animation:eitaaSpin .8s linear infinite;
            vertical-align:middle;
        }
        @keyframes eitaaSpin{ to{ transform:rotate(360deg); } }
    </style>

    <script>
    jQuery(function($){
        // Ensure modal exists once
        if (!$('#eitaa-modal-overlay').length) {
            $('body').append(
                '<div class="eitaa-modal-overlay" id="eitaa-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">' +
                    '<div class="eitaa-modal">' +
                        '<div class="eitaa-modal-header">' +
                            '<h3 class="eitaa-modal-title" id="eitaa-modal-title">ارسال به پیام‌رسان</h3>' +
                            '<button type="button" class="eitaa-modal-close" id="eitaa-modal-close" aria-label="بستن">×</button>' +
                        '</div>' +
                        '<div class="eitaa-modal-body" id="eitaa-modal-body"></div>' +
                        '<div class="eitaa-modal-footer">' +
                            '<button type="button" class="button" id="eitaa-modal-ok">باشه</button>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
        }

        function openEitaaModal(title, bodyHtml) {
            $('#eitaa-modal-title').text(title || 'پیام');
            $('#eitaa-modal-body').html(bodyHtml || '');
            $('#eitaa-modal-overlay')
                .attr('aria-hidden', 'false')
                .fadeIn(120);
        }

        function closeEitaaModal() {
            $('#eitaa-modal-overlay')
                .attr('aria-hidden', 'true')
                .fadeOut(120);
        }

        // close handlers
        $(document).on('click', '#eitaa-modal-close, #eitaa-modal-ok', function(){
            closeEitaaModal();
        });
        $(document).on('click', '#eitaa-modal-overlay', function(e){
            if (e.target === this) closeEitaaModal();
        });
        $(document).on('keydown', function(e){
            if (e.key === 'Escape') closeEitaaModal();
        });

        // button handler
        $(document).on('click', '.send-order-to-eitaa', function(e){
            e.preventDefault();

            var $button = $(this);
            var order_id = $button.data('order-id');
            var originalText = $button.text();

            $.ajax({
                url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'send_order_to_eitaa',
                    order_id: order_id,
                    nonce: '<?php echo esc_js($nonce); ?>'
                },
                beforeSend: function() {
                    $button.prop('disabled', true).html('<span class="eitaa-modal-spinner"></span> در حال ارسال...');
                    openEitaaModal('در حال ارسال...', '<div class="eitaa-modal-badge"><span class="eitaa-modal-spinner"></span> لطفاً صبر کنید...</div>');
                },
                success: function(response) {
                    var defaultSuccess = 'سفارش با موفقیت ارسال شد.';
                    var defaultError   = 'ارسال سفارش با خطا مواجه شد.';
                    var isOk = response && response.success;

                    var msg = defaultSuccess;
                    if (isOk) {
                        if (response.data && response.data.message) msg = response.data.message;
                        openEitaaModal('نتیجه ارسال', '<div class="eitaa-modal-badge success">موفق</div><hr style="border:none;border-top:1px solid #eee;margin:10px 0;">' + $('<div/>').text(msg).html());
                    } else {
                        msg = defaultError;
                        if (response && response.data) {
                            msg = (response.data.message) ? response.data.message : response.data;
                        }
                        openEitaaModal('نتیجه ارسال', '<div class="eitaa-modal-badge error">خطا</div><hr style="border:none;border-top:1px solid #eee;margin:10px 0;">' + $('<div/>').text(msg).html());
                    }
                },
                error: function(xhr) {
                    var msg = 'ارسال سفارش با خطا مواجه شد.';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                        var data = xhr.responseJSON.data;
                        msg = data.message ? data.message : data;
                    } else if (xhr && xhr.responseText) {
                        msg = xhr.responseText;
                    }
                    openEitaaModal('نتیجه ارسال', '<div class="eitaa-modal-badge error">خطا</div><hr style="border:none;border-top:1px solid #eee;margin:10px 0;">' + $('<div/>').text(msg).html());
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    });
    </script>

    <button class="button button-primary send-order-to-eitaa" data-order-id="<?php echo esc_attr($order_id); ?>">
     ارسال به پیام‌رسان‌ها
    </button>
    <?php
}

/**
 * AJAX manual send
 */
add_action('wp_ajax_send_order_to_eitaa', 'send_order_to_eitaa_function');
function send_order_to_eitaa_function()
{
    check_ajax_referer('send_order_to_eitaa_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('دسترسی غیرمجاز.', 'wp-eitaa')], 403);
    }

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error(['message' => __('شناسه سفارش ارسال نشده است.', 'wp-eitaa')], 400);
    }

    if (!class_exists('EitaaAPI')) {
        wp_send_json_error(['message' => __('کلاس API در دسترس نیست.', 'wp-eitaa')], 500);
    }

    $result = EitaaAPI::send_order_to_eitaa_group($order_id, true); // true => manual send (allow resend)

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    }

    wp_send_json_success([
        'message' => isset($result['message']) ? $result['message'] : __('ارسال انجام شد.', 'wp-eitaa'),
    ]);
}