<?php

/**
 * Plugin Name: Gunsafes Core
 * Description: Custom plugin to handle gunsafes unique ecommerce needs
 * Version: 0.1.0
 * Author: Code Blueprint
 * Text Domain: gunsafes-core
 */

if (!defined('ABSPATH')) exit;

define('GUNSAFES_CORE_PATH', plugin_dir_path(__FILE__));
define('GUNSAFES_CORE_URL', plugin_dir_url(__FILE__));
define('GUNSAFES_CORE_VER', '0.1.0');

require __DIR__ . '/includes/Plugin.php';

add_action('plugins_loaded', function () {
    (new Plugin())->boot();
});
