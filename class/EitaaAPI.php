<?php

class EitaaAPI
{
    public const PROVIDER_EITAAYAR = 'eitaayar';
    public const PROVIDER_BALE     = 'bale';

    /**
     * Flush all provider caches.
     */
    public static function flush_status_cache_all()
    {
        self::flush_status_cache(self::PROVIDER_EITAAYAR);
        self::flush_status_cache(self::PROVIDER_BALE);
    }

    protected static function get_decoded_option($option_name)
    {
        $value = get_option($option_name, '');
        if (empty($value)) {
            return '';
        }
        $decoded = base64_decode($value, true);
        return $decoded !== false ? $decoded : $value;
    }

    protected static function get_provider_config($provider)
    {
        $provider = (string) $provider;

        if ($provider === self::PROVIDER_BALE) {
            return [
                'label'           => 'بله',
                'enabled_option'  => 'eitaa_bale_enabled',
                'base_url_option' => 'eitaa_bale_base_url',
                'token_option'    => 'eitaa_bale_api_token',
                'chat_option'     => 'eitaa_bale_chat_id',
                'default_base'    => 'https://tapi.bale.ai',
            ];
        }

        // default: Eitaayar (ای‌تا فعلی پلاگین)
        return [
            'label'           => 'ای‌تا',
            'enabled_option'  => 'eitaa_eitaayar_enabled',
            'base_url_option' => 'eitaa_eitaayar_base_url',
            'token_option'    => 'eitaa_api_token',
            'chat_option'     => 'eitaa_chat_id',
            'default_base'    => 'https://eitaayar.ir',
        ];
    }

    protected static function is_provider_enabled($provider)
    {
        $cfg = self::get_provider_config($provider);
        return (int) get_option($cfg['enabled_option'], ($provider === self::PROVIDER_EITAAYAR ? 1 : 0)) === 1;
    }

    protected static function get_provider_base_url($provider)
    {
        $cfg = self::get_provider_config($provider);
        $base = (string) get_option($cfg['base_url_option'], $cfg['default_base']);
        $base = $base ? untrailingslashit($base) : '';
        return $base;
    }

    protected static function get_provider_token($provider)
    {
        $cfg = self::get_provider_config($provider);
        return trim((string) self::get_decoded_option($cfg['token_option']));
    }

    protected static function get_provider_chat_id($provider)
    {
        $cfg = self::get_provider_config($provider);
        return trim((string) self::get_decoded_option($cfg['chat_option']));
    }

    protected static function get_status_cache_key($provider)
    {
        return 'eitaa_api_status_cache_' . sanitize_key((string) $provider);
    }

    protected static function cache_status($provider, $base_url, $token, array $status)
    {
        if (empty($token)) {
            return;
        }

        $cache_payload = [
            'fingerprint' => md5($provider . '|' . $base_url . '|' . $token),
            'data'        => $status,
            'timestamp'   => time(),
        ];

        set_transient(self::get_status_cache_key($provider), $cache_payload, 5 * MINUTE_IN_SECONDS);
    }

    protected static function get_cached_status($provider, $base_url, $token)
    {
        if (empty($token)) {
            return null;
        }

        $cached = get_transient(self::get_status_cache_key($provider));
        $fp = md5($provider . '|' . $base_url . '|' . $token);

        if (is_array($cached) && isset($cached['fingerprint'], $cached['data']) && $cached['fingerprint'] === $fp) {
            return $cached['data'];
        }

        return null;
    }

    public static function flush_status_cache($provider)
    {
        delete_transient(self::get_status_cache_key($provider));
    }

    protected static function format_order_datetime($datetime, $format = 'j F Y ساعت H:i')
    {
        if (!$datetime instanceof DateTimeInterface) {
            return 'تاریخ نامشخص';
        }

        try {
            $year = (int) $datetime->format('Y');
            if ($year >= 1000 && $year <= 3000 && class_exists('Morilog\Jalali\Jalalian')) {
                return Morilog\Jalali\Jalalian::fromDateTime($datetime)->format($format);
            }
        } catch (\Throwable $e) {
            // fallback below
        }

        if (function_exists('wc_format_datetime')) {
            return wc_format_datetime($datetime, $format);
        }

        return $datetime->format('Y-m-d H:i');
    }

    /**
     * Backward-compatible method name, but now sends to enabled providers:
     * - Eitaayar (ای‌تا) and/or Bale (بله)
     *
     * @param int  $order_id
     * @param bool $manual  true => allow resend without "already sent" guard
     */
    public static function send_order_to_eitaa_group($order_id, $manual = false)
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            return new WP_Error('eitaa_invalid_order', __('سفارش معتبر نیست.', 'wp-eitaa'));
        }

        $providers = [];
        if (self::is_provider_enabled(self::PROVIDER_EITAAYAR)) {
            $providers[] = self::PROVIDER_EITAAYAR;
        }
        if (self::is_provider_enabled(self::PROVIDER_BALE)) {
            $providers[] = self::PROVIDER_BALE;
        }

        if (empty($providers)) {
            return new WP_Error('eitaa_no_provider_enabled', __('هیچ پیام‌رسانی فعال نیست.', 'wp-eitaa'));
        }

        $title = 'سفارش ' . $order_id;
        $text  = self::build_order_text($order);

        $results = [];
        foreach ($providers as $provider) {
            // prevent duplicates on automatic hook
            $meta_key = '_eitaa_sent_' . $provider;
            $message_id_meta_key = '_eitaa_message_id_' . $provider;
            $already_sent = $order->get_meta($meta_key, true);
            if (!$manual && $already_sent) {
                $results[$provider] = [
                    'status'  => 'skipped',
                    'message' => 'قبلاً ارسال شده است.',
                ];
                continue;
            }

            $chat_id = self::get_provider_chat_id($provider);
            $token   = self::get_provider_token($provider);

            $message_id = $order->get_meta($message_id_meta_key, true);
            if ($provider === self::PROVIDER_BALE && $manual && !empty($message_id)) {
                $r = self::edit_message_text($chat_id, $message_id, $title, $text, $token);
            } elseif ($provider === self::PROVIDER_BALE && $manual && $already_sent) {
                $results[$provider] = [
                    'status'  => 'error',
                    'message' => 'پیام قبلی بله قابل ویرایش نیست چون message_id آن در سفارش ذخیره نشده است. برای پیام‌هایی که قبل از اضافه شدن قابلیت ویرایش ارسال شده‌اند، فقط ارسال جدید قابل انجام است.',
                ];
                continue;
            } else {
                $r = self::send_message($provider, $chat_id, $title, $text, $token);
            }

            if (is_wp_error($r)) {
                $results[$provider] = [
                    'status'  => 'error',
                    'message' => $r->get_error_message(),
                ];
                continue;
            }

            $results[$provider] = $r;

            $sent_message_id = self::extract_message_id($r);
            if ($sent_message_id !== '') {
                $order->update_meta_data($message_id_meta_key, $sent_message_id);
            }

            if (!$manual || $sent_message_id !== '') {
                $order->update_meta_data($meta_key, 1);
                $order->save();
            }
        }

        // compose a human-readable response for UI
        $lines = [];
        foreach ($results as $provider => $info) {
            $label = self::get_provider_config($provider)['label'];
            $lines[] = $label . ': ' . (isset($info['message']) ? $info['message'] : $info['status']);
        }

        return [
            'status'  => 'success',
            'message' => implode(' | ', $lines),
            'results' => $results,
        ];
    }

    protected static function build_order_text($order)
    {
        $order_id      = $order->get_id();
        $firstName     = $order->get_billing_first_name();
        $lastName      = $order->get_billing_last_name();
        $phone         = $order->get_billing_phone();
        $postcode      = $order->get_billing_postcode();
        $address1      = $order->get_billing_address_1();
        $city          = $order->get_billing_city();
        $state         = $order->get_billing_state();
        $country       = $order->get_billing_country();

        $state_name = $state;
        if (class_exists('WC_Countries')) {
            $wc_countries = new WC_Countries();
            $states = $wc_countries->get_states($country);
            if (is_array($states) && isset($states[$state])) {
                $state_name = $states[$state];
            }
        }

        $address_parts = array_filter([$state_name, $city, $address1], function ($v) {
            return is_string($v) && trim($v) !== '';
        });
        $address = implode(' - ', $address_parts);

        $date_created_jalali = self::format_order_datetime($order->get_date_created());
        $date_paid_jalali    = self::format_order_datetime($order->get_date_paid());

        $shipping_text = self::get_shipping_text($order);
        $customer_note = trim((string) $order->get_customer_note());
        $customer_note = $customer_note !== '' ? $customer_note : '-';

        $fullPrice  = (float) $order->get_total();
        $order_items = $order->get_items();

        $text  = '♦️ سفارش جدید به شماره: ' . $order_id . PHP_EOL . PHP_EOL;
        $text .= '⏰ تاریخ ایجاد سفارش: ' . $date_created_jalali . PHP_EOL;
        $text .= '💳 زمان پرداخت سفارش: ' . $date_paid_jalali . PHP_EOL;
        $text .= '👤 نام و نام خانوادگی: ' . $firstName . ' ' . $lastName . PHP_EOL;
        $text .= '📍 آدرس: ' . $address . PHP_EOL;
        $text .= '📬 کد پستی: ' . $postcode . PHP_EOL;
        $text .= '📞 تلفن: ' . $phone . PHP_EOL;
        $text .= '📌 آیتم‌های سفارش:' . PHP_EOL;

        foreach ($order_items as $item) {
            $product_name  = $item->get_name();
            $item_quantity = (int) $item->get_quantity();
            $item_total    = (float) $item->get_total();

            $text .= '🔹 ' . $product_name
                . ' | تعداد: ' . $item_quantity
                . ' | قیمت: ' . number_format($item_total) . ' تومان'
                . PHP_EOL;
        }

        $text .= PHP_EOL . $shipping_text . PHP_EOL;
        $text .= '📝 یادداشت خریدار: ' . $customer_note . PHP_EOL;
        $text .= PHP_EOL . '💵 مبلغ کل سفارش: ' . number_format($fullPrice) . ' تومان' . PHP_EOL;

        return $text;
    }

    protected static function get_shipping_text($order)
    {
        $shipping_methods = $order->get_shipping_methods();
        $shipping_text = '📦 روش ارسال: -';

        if (!empty($shipping_methods)) {
            $shipping_icons = [
                18 => '🚚',
                17 => '🆓',
                16 => '🛵',
                14 => '🫴🏼',
                5  => '🚚',
                12 => '🆓',
            ];

            foreach ($shipping_methods as $shipping_item) {
                $instance_id  = (int) $shipping_item->get_instance_id();
                $method_title = $shipping_item->get_method_title();

                $icon = isset($shipping_icons[$instance_id]) ? $shipping_icons[$instance_id] : '🚚';
                $shipping_text = '📦 روش ارسال: ' . $icon . ' ' . $method_title;
                break;
            }
        }

        return $shipping_text;
    }

    /**
     * Unified sendMessage for providers.
     */
    protected static function send_message($provider, $chatID, $title, $text, $token = '')
    {
        $provider = (string) $provider;

        $apiToken = $token ?: self::get_provider_token($provider);
        $baseUrl  = self::get_provider_base_url($provider);

        if (empty($apiToken) || empty($chatID) || empty($baseUrl)) {
            return new WP_Error('eitaa_missing_credentials', __('اطلاعات اتصال کامل نیست.', 'wp-eitaa'));
        }

        if ($provider === self::PROVIDER_BALE) {
            // Bale sendMessage: chat_id + text (title merged into text)
            $payload = [
                'chat_id' => $chatID,
                'text'    => "🧾 {$title}\n\n" . $text,
            ];

            return self::request($provider, 'sendMessage', $payload, $apiToken, $baseUrl, 'POST');
        }

        // Eitaayar sendMessage: chat_id + title + text
        $payload = [
            'chat_id' => $chatID,
            'title'   => $title,
            'text'    => $text,
        ];

        return self::request($provider, 'sendMessage', $payload, $apiToken, $baseUrl, 'POST');
    }

    /**
     * Bale editMessageText: chat_id + message_id + text.
     */
    protected static function edit_message_text($chatID, $messageID, $title, $text, $token = '')
    {
        $apiToken = $token ?: self::get_provider_token(self::PROVIDER_BALE);
        $baseUrl  = self::get_provider_base_url(self::PROVIDER_BALE);

        if (empty($apiToken) || empty($chatID) || empty($messageID) || empty($baseUrl)) {
            return new WP_Error('eitaa_missing_credentials', __('اطلاعات اتصال کامل نیست.', 'wp-eitaa'));
        }

        $payload = [
            'chat_id'    => $chatID,
            'message_id' => $messageID,
            'text'       => "🧾 {$title}\n\n" . $text,
        ];

        $result = self::request(self::PROVIDER_BALE, 'editMessageText', $payload, $apiToken, $baseUrl, 'POST');
        if (is_wp_error($result)) {
            return $result;
        }

        $result['message'] = __('پیام قبلی بله با موفقیت ویرایش شد.', 'wp-eitaa');

        return $result;
    }

    protected static function extract_message_id($response)
    {
        if (!is_array($response) || !isset($response['result']) || !is_array($response['result'])) {
            return '';
        }

        $result = $response['result'];
        foreach (['message_id', 'messageId', 'id'] as $key) {
            if (isset($result[$key])) {
                return (string) $result[$key];
            }
        }

        if (isset($result['message']) && is_array($result['message'])) {
            foreach (['message_id', 'messageId', 'id'] as $key) {
                if (isset($result['message'][$key])) {
                    return (string) $result['message'][$key];
                }
            }
        }

        return '';
    }

    /**
     * Request layer for both providers:
     * - Eitaayar: {base}/api/{token}/{method}
     * - Bale:     {base}/bot{token}/{method}
     */
    protected static function request($provider, $method, array $params, $token, $baseUrl, $http_method = 'POST')
    {
        $provider = (string) $provider;
        $method   = (string) $method;

        $baseUrl = untrailingslashit((string) $baseUrl);

        if ($provider === self::PROVIDER_BALE) {
            $url = $baseUrl . '/bot' . $token . '/' . $method;
        } else {
            $url = $baseUrl . '/api/' . $token . '/' . $method;
        }

        $args = [
            'timeout' => 15,
        ];

        $http_method = strtoupper((string) $http_method);
        if ($http_method === 'GET') {
            $url = add_query_arg($params, $url);
            $response = wp_remote_get($url, $args);
        } else {
            $args['body'] = $params; // application/x-www-form-urlencoded
            $response = wp_remote_post($url, $args);
        }

        if (is_wp_error($response)) {
            return new WP_Error('eitaa_http_error', $response->get_error_message(), $response->get_error_data());
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return new WP_Error('eitaa_invalid_response', __('پاسخ نامعتبر از سرور دریافت شد.', 'wp-eitaa'), [
                'body' => $body,
                'http_code' => wp_remote_retrieve_response_code($response),
            ]);
        }

        
        if (isset($decoded['ok']) && $decoded['ok'] === true) {
            // Success response structure differs slightly across providers, but both have ok/result.
            $result_obj = isset($decoded['result']) ? $decoded['result'] : null;

            $message = isset($decoded['description']) && $decoded['description'] !== ''
                ? (string) $decoded['description']
                : __('پیام با موفقیت ارسال شد.', 'wp-eitaa');

            return [
                'status'    => 'success',
                'message'   => $message,
                'body'      => $decoded,
                'result'    => $result_obj,
                'http_code' => wp_remote_retrieve_response_code($response),
            ];
        }

        // Error handling
        $error_message = isset($decoded['description']) && $decoded['description'] !== ''
            ? (string) $decoded['description']
            : __('درخواست ناموفق بود.', 'wp-eitaa');

        $error_code = isset($decoded['error_code']) ? (int) $decoded['error_code'] : 0;

        return new WP_Error(
            'eitaa_api_error',
            $error_message,
            [
                'provider'  => $provider,
                'error_code'=> $error_code,
                'body'      => $decoded,
                'http_code' => wp_remote_retrieve_response_code($response),
            ]
        );
    }

    /**
     * Provider-aware status check (getMe)
     */
    public static function get_api_status($provider = self::PROVIDER_EITAAYAR, $use_cache = true)
    {
        $provider = (string) $provider;

        $cfg      = self::get_provider_config($provider);
        $base_url = self::get_provider_base_url($provider);
        $token    = self::get_provider_token($provider);

        // Not enabled
        if (!self::is_provider_enabled($provider)) {
            return [
                'status'      => 'disabled',
                'label'       => 'غیرفعال',
                'description' => sprintf(__('ارسال به %s غیرفعال است.', 'wp-eitaa'), $cfg['label']),
            ];
        }

        // Not configured
        if (empty($token)) {
            return [
                'status'      => 'not_configured',
                'label'       => 'تعریف نشده',
                'description' => sprintf(__('توکن %s تنظیم نشده است.', 'wp-eitaa'), $cfg['label']),
            ];
        }

        if (empty($base_url)) {
            return [
                'status'      => 'error',
                'label'       => 'نامشخص',
                'description' => sprintf(__('Base URL برای %s تنظیم نشده است.', 'wp-eitaa'), $cfg['label']),
            ];
        }

        if ($use_cache) {
            $cached = self::get_cached_status($provider, $base_url, $token);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Build getMe URL via request layer (GET)
        $res = self::request($provider, 'getMe', [], $token, $base_url, 'GET');

        if (is_wp_error($res)) {
            $status = [
                'status'      => 'error',
                'label'       => 'خطا',
                'description' => $res->get_error_message(),
            ];
            self::cache_status($provider, $base_url, $token, $status);
            return $status;
        }

        // If ok:true, connected
        $status = [
            'status' => 'connected',
            'label'  => 'وصل',
        ];
        self::cache_status($provider, $base_url, $token, $status);

        return $status;
    }

    /**
     * Combined status for UI (returns both providers).
     */
    public static function get_all_statuses($use_cache = true)
    {
        return [
            self::PROVIDER_EITAAYAR => self::get_api_status(self::PROVIDER_EITAAYAR, $use_cache),
            self::PROVIDER_BALE     => self::get_api_status(self::PROVIDER_BALE, $use_cache),
        ];
    }
}
