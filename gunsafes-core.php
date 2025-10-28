<?php

/**
 * Plugin Name: Gunsafes Core
 * Description: Custom plugin to handle gunsafes unique ecommerce integrations and workflows
 * Version: 0.1.0
 * Author: Code Blueprint
 * Text Domain: gunsafes-core
 */

if (!defined('ABSPATH')) exit;

define('GUNSAFES_CORE_PATH', plugin_dir_path(__FILE__));
define('GUNSAFES_CORE_URL', plugin_dir_url(__FILE__));
define('GUNSAFES_CORE_VER', '0.1.0');

// Include feature files
require_once __DIR__ . '/includes/call-for-pricing.php';
require_once __DIR__ . '/includes/admin/admin-order.php';
// require_once __DIR__ . '/includes/dropship-notifier.php';
require_once __DIR__ . '/includes/email-bcc-replyto.php';

// Load text domain for translations and instantiate classes
add_action('plugins_loaded', function () {
    load_plugin_textdomain('gunsafes-core', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Instantiate Admin_Order class
    if (class_exists('Admin_Order')) {
        new Admin_Order();
    }
});