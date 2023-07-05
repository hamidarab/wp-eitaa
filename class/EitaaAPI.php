<?php

class EitaaAPI
{
    protected static $ApiToken = 'YOUR_API_KEY';
    protected static $baseUrl = 'https://eitaayar.ir';
    protected static $chatID = 'YOUR_CHAT_ID_EITAA';

    public static function send_order_to_eitaa_group($order_id)
    {
        $order = new WC_Order($order_id);
        $firstName = $order->get_billing_first_name();
        $lastName  = $order->get_billing_last_name();
        $userID  = $order->get_customer_id();
        $city = get_user_meta($userID, 'billing_city', true);
        $state = get_user_meta($userID, 'billing_state', true);
        $postcode = get_user_meta($userID, 'billing_postcode', true);
        $address1 = get_user_meta($userID, 'billing_address_1', true);
        $address = $state . ' - ' . $city . ' - ' . $address1;
        $phone =  $order->get_billing_phone();
        $date_created = $order->get_date_created();
        $date_paid = $order->get_date_paid();
        $fullPrice = $order->get_total();
        $order_items = $order->get_items();
        $text = '♦️ سفارش جدید به شماره : ' . $order_id . PHP_EOL . PHP_EOL;
        $text .= '⏰ تاریخ ایجاد سفارش : ' . $date_created . PHP_EOL;
        $text .= '💳 زمان پرداخت سفارش : ' . $date_paid . PHP_EOL;
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
        $text .=  PHP_EOL . '💵 مبلغ  کل سفارش : ' . number_format($fullPrice) . ' تومان ' . PHP_EOL;
        self::connect_to_api(self::$chatID, $text);
    }

    public static function connect_to_api($chatID, $text)
    {
        $request_url = self::$baseUrl . '/api/' . self::$ApiToken . '/sendMessage/?chat_id=' . $chatID . '&text=' . $text;
        $response = wp_remote_get($request_url);
        return $response;
    }
}
