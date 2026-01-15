<?php
/**
 * Plugin Name: Gunsafes Core
 * Description: Custom plugin to handle gunsafes unique ecommerce integrations and workflows
 * Version: 0.1.0
 * Author: Code Blueprint
 * Text Domain: gunsafes-core
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GUNSAFES_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'GUNSAFES_CORE_URL',  plugin_dir_url( __FILE__ ) );
define( 'GUNSAFES_CORE_VER',  '0.1.0' );

// Load everything safely on plugins_loaded (or later)
add_action( 'plugins_loaded', function() {
    // These files are safe to load early (they don't use current_user_can, wp_enqueue, etc.)
    require_once __DIR__ . '/includes/call-for-pricing.php';
    require_once __DIR__ . '/includes/dropship-notifier.php';
    require_once __DIR__ . '/includes/email-bcc-replyto.php';
    require_once __DIR__ . '/includes/admin-bcc-settings.php';
    require_once __DIR__ . '/includes/admin-regional-markups.php';
    require_once __DIR__ . '/includes/jet-smart-filters-guard.php';

    // This one uses current_user_can() → must wait until pluggable.php is loaded
    require_once __DIR__ . '/includes/admin/admin-order.php';

    // Load text domain
    load_plugin_textdomain( 'gunsafes-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // Instantiate the admin class (only in admin area to save resources)
    if ( is_admin() && class_exists( 'Admin_Order' ) ) {
        new Admin_Order();
    }
    if ( class_exists( 'GScore_Jet_Smart_Filters_Guard' ) ) {
        new GScore_Jet_Smart_Filters_Guard();
    }
});
