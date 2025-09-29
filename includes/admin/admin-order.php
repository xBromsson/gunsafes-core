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

        // Save the Sales Rep Field
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_sales_rep_field']);

        // Enqueue assets for the order screen
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_custom_fields($order): void {
        // Get the current loggin-in user as default
        $current_user = wp_get_current_user();
        $default_sales_rep = $current_user->user_login;
        $saved_sales_rep = get_post_meta($order->get_id(), '_sales_rep', true);
    
        // Get admin users for the dropdown
        $admin_users = get_users([
            'role__in' => ['administrator'],
            'fields' => ['ID', 'user_login', 'display_name']
        ]);
    
        // Use saved value if exists, otherwise default to current user
        $selected_sales_rep = $saved_sales_rep ?: $default_sales_rep;
        ?>
        <div class="form-field form-field-wide">
            <label for="sales_rep"><?php esc_html_e('Sales Rep', 'gunsafes-core'); ?></label>
            <select name="sales_rep" id="sales_rep" class="regular-text">
                <?php foreach ($admin_users as $user) : ?>
                    <option value="<?php echo esc_attr($user->user_login); ?>" 
                            <?php selected($selected_sales_rep, $user->user_login); ?>>
                        <?php echo esc_html($user->display_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    public function add_custom_buttons($order): void {
        // Placeholder for custom buttons (e.g., APF modal trigger)
        // Will be implemented step-by-step
    }

    public function save_sales_rep_field($post_id): void {
        // Save the selected Sales Rep field
        if (isset($_POST['sales_rep'])) {
            update_post_meta($post_id, '_sales_rep', sanitize_text_field($_POST['sales_rep']));
        }
    }

    public function enqueue_assets(): void {
        // Enqueue styles and scripts specific to the order screen
        wp_enqueue_style('gunsafes-core-order', GUNSAFES_CORE_URL . 'assets/css/admin.css', [], GUNSAFES_CORE_VER);
        wp_enqueue_script('gunsafes-core-order', GUNSAFES_CORE_URL . 'assets/js/admin.js', ['jquery'], GUNSAFES_CORE_VER, true);
    }
}
