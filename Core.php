<?php
/*
Plugin Name: پلاگین اشتراک سفارشات ووکامرس در ایتا
Plugin URI: https://hamidarab.ir
Description: پلاگین اشتراک گذاری سفارشات ووکامرسی در ایتا - توسعه دهنده حمید اعراب
Author: حمید اعراب
Version: 1.0.0
Licence: GPLv2 or Later
Author URI: https://hamidarab.ir
*/

defined('ABSPATH') || exit;
define('EITA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EITA_PLUGIN_URL', plugin_dir_url(__FILE__));


require_once EITA_PLUGIN_DIR . '/class/EitaaAPI.php';

// EitaaAPI::send_order_to_eitaa_group();

add_action('woocommerce_new_order', 'EitaaAPI::send_order_to_eitaa_group', 10);
