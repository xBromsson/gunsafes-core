<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Prevent direct file access and ensure WooCommerce is active
if (!class_exists('WooCommerce')) {
    return; // Exit if WooCommerce is not active
}

class Admin_Order {
    public function __construct() {
        // Restrict to admins (can adjust later if needed)
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Register hooks for order screen customizations
        $this->register();
    }

    public function register(): void {
        // Hook into WooCommerce admin order actions (to be filled in later)
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'add_custom_fields']);
        add_action('woocommerce_admin_order_item_add_line_buttons', [$this, 'add_custom_buttons']);

        // Enqueue assets for the order screen
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_custom_fields($order): void {
        // Placeholder for custom fields (e.g., Sales Rep)
        // Will be implemented step-by-step
    }

    public function add_custom_buttons($order): void {
        // Placeholder for custom buttons (e.g., APF modal trigger)
        // Will be implemented step-by-step
    }

    public function enqueue_assets(): void {
        // Enqueue styles and scripts specific to the order screen
        wp_enqueue_style('gunsafes-core-order', GUNSAFES_CORE_URL . 'assets/css/admin.css', [], GUNSAFES_CORE_VER);
        wp_enqueue_script('gunsafes-core-order', GUNSAFES_CORE_URL . 'assets/js/admin.js', ['jquery'], GUNSAFES_CORE_VER, true);
    }
}
