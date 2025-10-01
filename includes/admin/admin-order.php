<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Prevent direct file access and ensure WooCommerce is active
if (!class_exists('WooCommerce')) {
    return; // Exit if WooCommerce is not active
}

/**
 * Handles custom admin order screen functionality, including Sales Rep field.
 */
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
        // Hook into WooCommerce admin order actions
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'add_custom_fields']);
        add_action('woocommerce_admin_order_item_add_line_buttons', [$this, 'add_custom_buttons']);

        // Save the Sales Rep field with higher priority for HPOS compatibility
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_sales_rep_field'], 100, 2);

        // Enqueue assets for the order screen
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Adds the Sales Rep dropdown field to the order edit screen.
     *
     * @param WC_Order $order The current order object.
     */
    public function add_custom_fields($order): void {
        // Get the current logged-in user as default (only if no saved value)
        $current_user = wp_get_current_user();
        $default_sales_rep = $current_user->user_login;
        $saved_sales_rep = $order->get_meta('_sales_rep', true); // Use WC_Order method for HPOS compatibility

        // Get admin users for the dropdown
        $admin_users = get_users([
            'role__in' => ['administrator'],
            'fields' => ['ID', 'user_login', 'display_name']
        ]);

        // Use saved value if exists, otherwise default to current user
        $selected_sales_rep = $saved_sales_rep ?: $default_sales_rep;
        ?>
        <div class="form-field form-field-wide">
            <label for="_sales_rep"><?php esc_html_e('Sales Rep', 'gunsafes-core'); ?></label>
            <select name="_sales_rep" id="_sales_rep" class="regular-text">
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

    /**
     * Adds custom buttons to the order item section (placeholder for future features).
     *
     * @param WC_Order $order The current order object.
     */
    public function add_custom_buttons($order): void {
        // Placeholder for custom buttons (e.g., APF modal trigger)
        // Will be implemented step-by-step
    }

    /**
     * Saves the Sales Rep field during order update.
     *
     * @param int $post_id The order ID.
     * @param WP_Post $post The order post object.
     */
    public function save_sales_rep_field($post_id, $post): void {
        $order = wc_get_order($post_id); // Load WC_Order object for HPOS compatibility
        if (!$order || !isset($_POST['_sales_rep'])) {
            return;
        }

        $new_value = sanitize_text_field($_POST['_sales_rep']);
        $current_value = $order->get_meta('_sales_rep', true);

        // Only update if the value has changed
        if ($new_value !== $current_value) {
            $order->update_meta_data('_sales_rep', $new_value); // Use CRUD method for HPOS
            $order->save(); // Persist to HPOS tables
        }
    }

    /**
     * Enqueues styles and scripts specific to the order screen.
     */
    public function enqueue_assets(): void {
        // Restrict to order edit screen for efficiency
        $screen = get_current_screen();
        if ($screen && $screen->id === 'shop_order') {
            wp_enqueue_style(
                'gunsafes-core-order',
                GUNSAFES_CORE_URL . 'assets/css/admin.css',
                [],
                GUNSAFES_CORE_VER
            );
            wp_enqueue_script(
                'gunsafes-core-order',
                GUNSAFES_CORE_URL . 'assets/js/admin.js',
                ['jquery'],
                GUNSAFES_CORE_VER,
                true
            );
        }
    }
}