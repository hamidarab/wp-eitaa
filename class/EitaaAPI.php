<?php

class EitaaAPI
{
    protected static $baseUrl = 'https://eitaayar.ir';
    protected static $statusCacheKey = 'eitaa_api_status_cache';

    protected static function get_decoded_option($option_name)
    {
        $value = get_option($option_name, '');
        if (empty($value)) {
            return '';
        }

        $decoded = base64_decode($value, true);

        return $decoded !== false ? $decoded : $value;
    }

    protected static function get_status_cache_key()
    {
        return self::$statusCacheKey;
    }

    protected static function cache_status($token, array $status)
    {
        if (empty($token)) {
            return;
        }

        $cache_payload = [
            'token_hash' => md5($token),
            'data'       => $status,
            'timestamp'  => time(),
        ];

        set_transient(self::get_status_cache_key(), $cache_payload, 5 * MINUTE_IN_SECONDS);
    }

    protected static function get_cached_status($token)
    {
        if (empty($token)) {
            return null;
        }

        $cached = get_transient(self::get_status_cache_key());

        if (
            is_array($cached)
            && isset($cached['token_hash'], $cached['data'])
            && md5($token) === $cached['token_hash']
        ) {
            return $cached['data'];
        }

        return null;
    }

    public static function flush_status_cache()
    {
        delete_transient(self::get_status_cache_key());
    }

    protected static function get_api_token()
    {
        return trim(self::get_decoded_option('eitaa_api_token'));
    }

    protected static function get_chat_id()
    {
        return trim(self::get_decoded_option('eitaa_chat_id'));
    }

    public static function send_order_to_eitaa_group($order_id)
    {
        $apiToken = self::get_api_token();
        $chatID = self::get_chat_id();

        if (empty($apiToken) || empty($chatID)) {
            return new WP_Error('eitaa_missing_credentials', __('اطلاعات اتصال به ای‌تا تنظیم نشده است.', 'wp-eitaa'));
        }

        $order = new WC_Order($order_id);
        $firstName = $order->get_billing_first_name();
        $lastName  = $order->get_billing_last_name();
        $userID  = $order->get_customer_id();
        $city_id = get_user_meta($userID, 'billing_city', true);
        $state_id = get_user_meta($userID, 'billing_state', true);

        $city_name = get_term( $city_id )->name;
        $state_name = get_term( $state_id )->name;

        $postcode = get_user_meta($userID, 'billing_postcode', true);
        $address1 = get_user_meta($userID, 'billing_address_1', true);
        $address = $state_name . ' - ' . $city_name . ' - ' . $address1;
        $phone =  $order->get_billing_phone();
        $date_created = $order->get_date_created();
        $date_paid = $order->get_date_paid();

        $date_created = $order->get_date_created();
        $date_paid = $order->get_date_paid();

        $date_created_jalali = $date_created ? Morilog\Jalali\Jalalian::fromDateTime($date_created)->format('j F Y ساعت H:i') : 'تاریخ نامشخص';
        $date_paid_jalali = $date_paid ? Morilog\Jalali\Jalalian::fromDateTime($date_paid)->format('j F Y ساعت H:i') : 'تاریخ نامشخص';

        $shipping_methods = $order->get_shipping_methods();
        $shipping_text = '📦 روش ارسال : ';
        if (!empty($shipping_methods)) {
            $shipping_icons = [
                18 => '🚚', // پست پیشتاز (رایگان - خارج استان)
                17 => '🆓', // پست پیشتاز (رایگان - خارج استان)
                16 => '🛵', // پیک موتوری (داخل اصفهان)
                14 => '🫴🏼', // تحویل حضوری
                5  => '🚚', // پست پیشتاز (سایر شهرها)
                12 => '🆓', // حمل و نقل رایگان (شهرستان‌ها)
            ];

            foreach ($shipping_methods as $shipping_item) {
                $method_id = $shipping_item->get_method_id();
                $instance_id = $shipping_item->get_instance_id();
                $method_title = $shipping_item->get_method_title();

                // Set icon with instance_id
                $icon = isset($shipping_icons[$instance_id]) ? $shipping_icons[$instance_id] : '🚚';

                $shipping_text = '📦 روش ارسال: ' . $icon . ' ' . $method_title;
                break; // Just show first shipping method
            }
        }

        $fullPrice = $order->get_total();
        $order_items = $order->get_items();
        $text = '♦️ سفارش جدید به شماره : ' . $order_id . PHP_EOL. PHP_EOL;
        $text .= '⏰ تاریخ ایجاد سفارش : ' . $date_created_jalali . PHP_EOL;
        $text .= '💳 زمان پرداخت سفارش : ' . $date_paid_jalali . PHP_EOL;
        $text .= '👤 نام و نام خانوادگی : ' . $firstName . ' ' . $lastName . PHP_EOL;
        $text .= '📍 آدرس : ' . $address . PHP_EOL;
        $text .= '📬 کد پستی : ' . $postcode . PHP_EOL;
        $text .= '📞 تلفن : ' . $phone . PHP_EOL;
        $text .= '📌 آیتم های سفارش : ' . PHP_EOL;
        foreach ($order_items as $item_id => $item) {
            $product        = $item->get_product();
            $product_name   = $item->get_name();
            $item_quantity  = $item->get_quantity();
            $item_total     = $item->get_total();
            $text .= '🔹نام محصول: ' . $product_name . ' | تعداد: ' . $item_quantity . ' | قیمت محصول: ' . number_format($item_total) . ' تومان ' . PHP_EOL;
        }
        $text .= PHP_EOL . $shipping_text . PHP_EOL;
        $text .= PHP_EOL . '💵 مبلغ  کل سفارش : ' . number_format($fullPrice) . ' تومان ' . PHP_EOL;
        $title = 'سفارش '.$order_id; 

        return self::connect_to_api($chatID, $title, $text, $apiToken);
    }

    public static function connect_to_api($chatID, $title, $text, $token = '')
    {
        $apiToken = $token ?: self::get_api_token();

        if (empty($apiToken) || empty($chatID)) {
            return new WP_Error('eitaa_missing_credentials', __('اطلاعات اتصال به ای‌تا تنظیم نشده است.', 'wp-eitaa'));
        }

        $query = http_build_query(
            [
                'chat_id' => $chatID,
                'title'   => $title,
                'text'    => $text,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $request_url = trailingslashit(self::$baseUrl) . 'api/' . $apiToken . '/sendMessage/?' . $query;
        $response = wp_remote_get(
            $request_url,
            [
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('eitaa_http_error', $response->get_error_message(), $response->get_error_data());
        }

        $body = wp_remote_retrieve_body($response);
        $decodedBody = json_decode($body, true);

        if (!is_array($decodedBody)) {
            return new WP_Error(
                'eitaa_invalid_response',
                __('پاسخ نامعتبر از سرور ای‌تا دریافت شد.', 'wp-eitaa'),
                ['body' => $body]
            );
        }

        if (isset($decodedBody['ok']) && true === $decodedBody['ok']) {
            $message = isset($decodedBody['description']) && '' !== $decodedBody['description']
                ? $decodedBody['description']
                : __('سفارش با موفقیت به ای‌تا ارسال شد.', 'wp-eitaa');

            return [
                'status'    => 'success',
                'message'   => $message,
                'body'      => $decodedBody,
                'http_code' => wp_remote_retrieve_response_code($response),
            ];
        }

        $error_message = isset($decodedBody['description']) && '' !== $decodedBody['description']
            ? $decodedBody['description']
            : __('ارسال سفارش به ای‌تا با خطا مواجه شد.', 'wp-eitaa');

        return new WP_Error(
            'eitaa_api_error',
            $error_message,
            [
                'body'      => $decodedBody,
                'http_code' => wp_remote_retrieve_response_code($response),
            ]
        );
    }

    public static function get_api_status($use_cache = true)
    {
        $token = self::get_api_token();

        if (empty($token)) {
            return [
                'status'      => 'not_configured',
                'label'       => 'تعریف نشده',
                'description' => __('توکن ای‌تا تنظیم نشده است.', 'wp-eitaa'),
            ];
        }

        if ($use_cache) {
            $cached = self::get_cached_status($token);
            if (null !== $cached) {
                return $cached;
            }
        }

        $url = trailingslashit(self::$baseUrl) . 'api/' . $token . '/getMe';
        $response = wp_remote_get($url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            $status = [
                'status'      => 'error',
                'label'       => 'نامشخص',
                'description' => $response->get_error_message(),
            ];
            self::cache_status($token, $status);

            return $status;
        }

        $body = wp_remote_retrieve_body($response);
        $decodedBody = json_decode($body, true);

        if (isset($decodedBody['ok']) && true === $decodedBody['ok']) {
            $status = [
                'status' => 'connected',
                'label'  => 'وصل',
            ];
            self::cache_status($token, $status);

            return $status;
        }

        if (
            isset($decodedBody['ok'], $decodedBody['error_code'], $decodedBody['description'])
            && false === $decodedBody['ok']
            && 404 === (int) $decodedBody['error_code']
            && 'Not Found: method not found!' === $decodedBody['description']
        ) {
            $status = [
                'status'      => 'disconnected',
                'label'       => 'قطع',
                'description' => $decodedBody['description'],
            ];
            self::cache_status($token, $status);

            return $status;
        }

        $status = [
            'status'      => 'error',
            'label'       => 'نامشخص',
            'description' => isset($decodedBody['description']) ? $decodedBody['description'] : __('Unexpected response received from API.', 'wp-eitaa'),
        ];
        self::cache_status($token, $status);

        return $status;
    }
}
