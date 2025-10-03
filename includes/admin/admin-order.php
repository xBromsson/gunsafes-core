<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
// Prevent direct file access and ensure WooCommerce is active
if (!class_exists('WooCommerce')) {
    return; // Exit if WooCommerce is not active
}
/**
 * Handles custom admin order screen functionality, including Sales Rep field and Flexible Shipping methods.
 */
class Admin_Order {
    public function __construct() {
        // Restrict to admins
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
        // Add Flexible Shipping instances to the shipping methods dropdown
        add_filter('woocommerce_shipping_methods', [$this, 'add_flexible_shipping_instances']);
        // Update shipping name and calculate cost on save
        add_action('woocommerce_saved_order_items', [$this, 'update_shipping_name'], 10, 2);
        // Calculate shipping cost on new item add (e.g., adding shipping method)
        add_action('woocommerce_new_order_item', [$this, 'handle_new_order_item'], 10, 3);
        // Enqueue assets for the order screen
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    /**
     * Adds the Sales Rep dropdown field to the order edit screen.
     *
     * @param WC_Order $order The current order object.
     */
    public function add_custom_fields($order): void {
        // Get the current logged-in user as default
        $current_user = wp_get_current_user();
        $default_sales_rep = $current_user->user_login;
        $saved_sales_rep = $order->get_meta('_sales_rep', true);
        // Get admin users for the Sales Rep dropdown
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
     * Adds each Flexible Shipping instance as a separate shipping method for the "Add shipping" dropdown.
     *
     * @param array $methods Existing shipping methods.
     * @return array Modified shipping methods.
     */
    public function add_flexible_shipping_instances($methods): array {
        global $wpdb;
        // Fetch all enabled Flexible Shipping instances from the database
        $instances = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods
                 WHERE method_id = 'flexible_shipping_single' AND is_enabled = 1",
                []
            ),
            ARRAY_A
        );
        if ($instances) {
            foreach ($instances as $instance) {
                $instance_id = $instance['instance_id'];
                $settings = get_option("woocommerce_flexible_shipping_single_{$instance_id}_settings", []);
                if (isset($settings['title'])) {
                    $method_id = "flexible_shipping_{$instance_id}";
                    // Create a custom shipping method instance with public properties
                    $methods[$method_id] = new class($instance_id, $settings['title']) extends WC_Shipping_Method {
                        public $instance_id;
                        public $method_title;
                        public function __construct($instance_id, $title) {
                            $this->id = "flexible_shipping_{$instance_id}";
                            $this->instance_id = $instance_id;
                            $this->method_title = $title;
                            $this->title = $title;
                        }
                        public function init() {
                            // Minimal initialization for admin dropdown
                        }
                        public function is_available($package) {
                            return true; // Allow selection in admin regardless of package
                        }
                    };
                }
            }
        }
        return $methods;
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
     * Updates the "Shipping name" field and recalculates cost for Flexible Shipping methods after saving.
     *
     * @param int $order_id The order ID.
     * @param array $items The order items.
     */
    public function update_shipping_name($order_id, $items): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $shipping_items = $order->get_items('shipping');
        foreach ($shipping_items as $item_id => $item) {
            $this->calculate_and_update_shipping_item($item, $order);
        }
        // Recalculate order totals after updates
        $order->calculate_totals();
    }
    /**
     * Handles new order items (e.g., shipping method addition) to calculate cost.
     *
     * @param int $item_id The order item ID.
     * @param WC_Order_Item $item The order item object.
     * @param int $order_id The order ID.
     */
    public function handle_new_order_item($item_id, $item, $order_id): void {
        if (!($item instanceof WC_Order_Item_Shipping)) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $this->calculate_and_update_shipping_item($item, $order);
    }
    /**
     * Calculates and updates shipping cost for a Flexible Shipping item based on order contents.
     *
     * @param WC_Order_Item_Shipping $item The shipping order item.
     * @param WC_Order $order The order object.
     */
    private function calculate_and_update_shipping_item(WC_Order_Item_Shipping $item, WC_Order $order): void {
        $method_id = $item->get_method_id();
        if (strpos($method_id, 'flexible_shipping_') !== 0) {
            return;
        }
        $instance_id = (int) str_replace('flexible_shipping_', '', $method_id);
        // Get the real Flexible Shipping method instance
        $shipping_method = WC_Shipping_Zones::get_shipping_method($instance_id);
        if (!$shipping_method || $shipping_method->id !== 'flexible_shipping_single') {
            return;
        }
        // Build package from order data (mimics cart package for shipping calculation)
        $contents = [];
        foreach ($order->get_items() as $order_item_id => $order_item) {
            if ($order_item->get_type() !== 'line_item') {
                continue;
            }
            $product = $order_item->get_product();
            if (!$product || !$product->needs_shipping()) {
                continue;
            }
            $contents[$order_item_id] = [
                'key' => $order_item_id,
                'product_id' => $order_item->get_product_id(),
                'variation_id' => $order_item->get_variation_id(),
                'variation' => $order_item->get_meta_data(), // Array of variation attributes
                'quantity' => $order_item->get_quantity(),
                'line_total' => $order_item->get_total(), // After discounts, excl. tax
                'line_tax' => $order_item->get_total_tax(),
                'line_subtotal' => $order_item->get_subtotal(), // Before discounts, excl. tax
                'line_subtotal_tax' => $order_item->get_subtotal_tax(),
                'data' => $product,
            ];
        }
        if (empty($contents)) {
            return; // No shippable items; leave cost as 0
        }
        $package = [
            'contents' => $contents,
            'contents_cost' => array_sum(wp_list_pluck($contents, 'line_total')),
            'applied_coupons' => $order->get_coupon_codes(),
            'user' => ['ID' => $order->get_user_id()],
            'destination' => [
                'country' => $order->get_shipping_country(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'city' => $order->get_shipping_city(),
                'address' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
            ],
        ];
        // Run calculation
        $shipping_method->calculate_shipping($package);
        // Get rates (Flexible Shipping typically adds one rate)
        $rates = $shipping_method->rates;
        if (empty($rates)) {
            return; // No rate available; leave as 0
        }
        $rate = reset($rates); // Use the first rate
        // Update the shipping item
        $item->set_total((float) $rate->cost);
        $item->set_taxes(['total' => $rate->taxes]);
        $item->set_name($rate->label); // Or use $shipping_method->title if label isn't customized
        $item->save();
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