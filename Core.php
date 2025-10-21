<?php
/*
Plugin Name: پلاگین اشتراک سفارشات ووکامرس در ایتا
Plugin URI: https://hamidarab.ir
Description: پلاگین اشتراک گذاری سفارشات ووکامرسی در ایتا - توسعه دهنده حمید اعراب
Author: حمید اعراب
Version: 2.0.0
Licence: GPLv2 or Later
Author URI: https://hamidarab.ir
*/


defined('ABSPATH') || exit;
define('EITA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EITA_PLUGIN_URL', plugin_dir_url(__FILE__));


require_once EITA_PLUGIN_DIR . '/class/EitaaAPI.php';

//EitaaAPI::send_order_to_eitaa_group();

add_action('admin_init', 'eitaa_register_settings');
add_action('admin_menu', 'eitaa_add_settings_page');
add_action('update_option_eitaa_api_token', 'eitaa_handle_credentials_change', 10, 3);
add_action('update_option_eitaa_chat_id', 'eitaa_handle_credentials_change', 10, 3);
add_action('add_option_eitaa_api_token', 'eitaa_handle_credentials_change_on_add', 10, 2);
add_action('add_option_eitaa_chat_id', 'eitaa_handle_credentials_change_on_add', 10, 2);
add_action('admin_bar_menu', 'eitaa_add_toolbar_status_node', 100);
add_action('admin_head', 'eitaa_toolbar_inline_styles');
add_action('wp_head', 'eitaa_toolbar_inline_styles');

function eitaa_register_settings()
{
    register_setting(
        'eitaa_settings_group',
        'eitaa_api_token',
        [
            'sanitize_callback' => 'eitaa_encode_setting_value',
        ]
    );

    register_setting(
        'eitaa_settings_group',
        'eitaa_chat_id',
        [
            'sanitize_callback' => 'eitaa_encode_setting_value',
        ]
    );
}

function eitaa_add_settings_page()
{
    add_menu_page(
        'تنظیمات ایتا',
        'ایتــا',
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

    $api_token = eitaa_get_decoded_option_value('eitaa_api_token');
    $chat_id   = eitaa_get_decoded_option_value('eitaa_chat_id');
    $status    = wp_parse_args(
        EitaaAPI::get_api_status(false),
        [
            'status'      => 'unknown',
            'label'       => 'نامشخص',
            'description' => '',
        ]
    );
    ?>
    <div class="wrap">
        <h1>تنظیمات اتصال ای‌تا</h1>
        <form method="post" action="options.php">
            <?php settings_fields('eitaa_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="eitaa_api_token">توکن ربات</label></th>
                    <td>
                        <input type="text" class="regular-text" id="eitaa_api_token" name="eitaa_api_token" value="<?php echo esc_attr($api_token); ?>" autocomplete="off" />
                        <p class="description">توکن دریافتی از وب‌سرویس ای‌تا را وارد کنید.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eitaa_chat_id">شناسه چت</label></th>
                    <td>
                        <input type="text" class="regular-text" id="eitaa_chat_id" name="eitaa_chat_id" value="<?php echo esc_attr($chat_id); ?>" autocomplete="off" />
                        <p class="description">شناسه گروه یا کانالی که اطلاع‌رسانی باید به آن ارسال شود.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('ذخیره تنظیمات'); ?>
        </form>
        <h2>وضعیت اتصال</h2>
        <p>
            وضعیت :
            <span>
                <?php echo wp_kses_post(eitaa_get_status_badge_html($status)); ?>
            </span>
        </p>
        <?php if (!empty($status['description']) && 'connected' !== $status['status']) : ?>
            <p class="description"><?php echo esc_html($status['description']); ?></p>
        <?php endif; ?>
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

    if ('' === $value) {
        return '';
    }

    $sanitized = base64_encode(sanitize_text_field($value));

    EitaaAPI::flush_status_cache();

    return $sanitized;
}

function eitaa_get_status_badge_html($status)
{
    $status_key = isset($status['status']) ? $status['status'] : 'unknown';
    $label      = isset($status['label']) ? $status['label'] : 'نامشخص';

    $color = '#555d66';

    if ('connected' === $status_key) {
        $color = '#1a7f1a';
    } elseif ('disconnected' === $status_key) {
        $color = '#d63638';
    } elseif ('error' === $status_key) {
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

function eitaa_handle_credentials_change($old_value, $value, $option)
{
    unset($old_value, $value, $option);
    EitaaAPI::flush_status_cache();
}

function eitaa_handle_credentials_change_on_add($option, $value)
{
    unset($option, $value);
    EitaaAPI::flush_status_cache();
}

function eitaa_add_toolbar_status_node($wp_admin_bar)
{
    if (!is_user_logged_in() || !is_admin_bar_showing() || !current_user_can('manage_options')) {
        return;
    }

    $status = wp_parse_args(
        EitaaAPI::get_api_status(),
        [
            'status'      => 'unknown',
            'label'       => 'نامشخص',
            'description' => '',
        ]
    );

    $badge_html = wp_kses_post(eitaa_get_status_badge_html($status));

    $title = sprintf(
        '<span class="ab-icon dashicons dashicons-testimonial" style="font-family: dashicons !important;"></span><span class="ab-label">%s</span>%s',
        esc_html__('وضعیت ای‌تا', 'wp-eitaa'),
        $badge_html
    );

    $tooltip = !empty($status['description'])
        ? wp_strip_all_tags($status['description'])
        : esc_html__('تنظیمات اتصال ای‌تا', 'wp-eitaa');

    $wp_admin_bar->add_node(
        [
            'id'     => 'eitaa-status',
            'parent' => 'top-secondary',
            'title'  => $title,
            'href'   => admin_url('admin.php?page=eitaa-settings'),
            'meta'   => [
                'class' => 'eitaa-toolbar-status-node',
                'title' => $tooltip,
            ],
        ]
    );
}

function eitaa_toolbar_inline_styles()
{
    if (!is_user_logged_in() || !is_admin_bar_showing()) {
        return;
    }

    echo '<style id="eitaa-toolbar-status-styles">#wp-admin-bar-eitaa-status > .ab-item{display:flex;align-items:center;gap:6px;}#wp-admin-bar-eitaa-status .eitaa-status-badge{margin-inline-start:6px;}</style>';
}

add_action('woocommerce_order_status_processing', 'EitaaAPI::send_order_to_eitaa_group', 10);
// add_action('woocommerce_admin_order_data_after_order_details', 'EitaaAPI::send_order_to_eitaa_group', 10);

add_action('woocommerce_admin_order_data_after_order_details', 'eitaa_order_button');
function eitaa_order_button($order) {
    $order_id = $order->get_id();
    $nonce = wp_create_nonce('send_order_to_eitaa_nonce');
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.send-order-to-eitaa').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var order_id = $button.data('order-id');
            var originalText = $button.text();

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'send_order_to_eitaa',
                    order_id: order_id,
                    nonce: '<?php echo $nonce; ?>'
                },
                beforeSend: function() {
                    $button.prop('disabled', true).text('در حال ارسال...');
                },
                success: function(response) {
                    var defaultSuccess = 'سفارش با موفقیت به ای‌تا ارسال شد.';
                    var defaultError = 'ارسال سفارش با خطا مواجه شد.';
                    var message = defaultSuccess;

                    if (response && typeof response === 'object') {
                        if (response.success) {
                            if (response.data && response.data.message) {
                                message = response.data.message;
                            }
                        } else {
                            message = defaultError;

                            if (response.data) {
                                message = response.data.message ? response.data.message : response.data;
                            }
                        }
                    }

                    alert(message);
                },
                error: function(xhr) {
                    var message = 'ارسال سفارش با خطا مواجه شد.';

                    if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                        var data = xhr.responseJSON.data;
                        message = data.message ? data.message : data;
                    } else if (xhr && xhr.responseText) {
                        message = xhr.responseText;
                    }

                    alert(message);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    });
    </script>
    <button class="button button-primary send-order-to-eitaa" data-order-id="<?php echo $order_id; ?>">
        ارسال به ای‌تا
    </button>
    <?php
}


add_action('wp_ajax_send_order_to_eitaa', 'send_order_to_eitaa_function');
function send_order_to_eitaa_function() {
    check_ajax_referer('send_order_to_eitaa_nonce', 'nonce');

    if (isset($_POST['order_id'])) {
        $order_id = intval($_POST['order_id']);

        if (class_exists('EitaaAPI')) {
            $result = EitaaAPI::send_order_to_eitaa_group($order_id);

            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                ]);
            }

            $success_message = isset($result['message']) ? $result['message'] : __('سفارش با موفقیت به ای‌تا ارسال شد.', 'wp-eitaa');

            wp_send_json_success([
                'message' => $success_message,
            ]);
        } else {
            wp_send_json_error([
                'message' => __('کلاس ای‌تا در دسترس نیست.', 'wp-eitaa'),
            ]);
        }
    } else {
        wp_send_json_error([
            'message' => __('شناسه سفارش ارسال نشده است.', 'wp-eitaa'),
        ]);
    }

    wp_die();
}
