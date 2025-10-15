<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
// Prevent direct file access and ensure WooCommerce is active
if (!class_exists('WooCommerce')) {
    return; // Exit if WooCommerce is not active
}
/**
 * Handles custom admin order screen functionality, including Sales Rep field, Flexible Shipping methods, and APF addons.
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
        // Auto-set Sales Rep on new admin-created orders
        add_action('woocommerce_new_order', [$this, 'auto_set_sales_rep_on_creation'], 10, 1);
        // Save the Sales Rep field on order update (only if explicitly changed)
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_sales_rep'], 100, 2);
        // Save APF addons on order update and item save
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_order_item_addons'], 100, 2);
        add_action('woocommerce_saved_order_items', [$this, 'save_order_item_addons'], 20, 2);
        // AJAX for saving addons on inline item save (fallback)
        add_action('wp_ajax_save_order_item_addons', [$this, 'ajax_save_order_item_addons']);
        // Add Flexible Shipping instances to the shipping methods dropdown
        add_filter('woocommerce_shipping_methods', [$this, 'add_flexible_shipping_instances']);
        // Update shipping name and calculate cost on save
        add_action('woocommerce_saved_order_items', [$this, 'update_shipping_name'], 10, 2);
        // Calculate shipping cost on new item add (e.g., adding shipping method)
        add_action('woocommerce_new_order_item', [$this, 'handle_new_order_item'], 10, 3);
        // Add Addons column to order items table (hidden, used as placeholder)
        add_action('woocommerce_admin_order_item_headers', [$this, 'add_addons_column_header']);
        add_action('woocommerce_admin_order_item_values', [$this, 'display_addons_column'], 10, 3);
        // Enqueue assets for the order screen
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    /**
     * Adds the Sales Rep dropdown field to the order edit screen.
     *
     * @param WC_Order $order The current order object.
     */
    public function add_custom_fields($order): void {
        // Get the current logged-in user
        $current_user = wp_get_current_user();
        $default_sales_rep = $current_user->user_login;
        $saved_sales_rep = $order->get_meta('_sales_rep', true);
        // Use saved value if exists; default to 'N/A' for new/existing orders without a sales rep
        $selected_sales_rep = $saved_sales_rep ?: 'N/A';
        // Get admin users for the Sales Rep dropdown
        $admin_users = get_users([
            'role__in' => ['administrator'],
            'fields' => ['ID', 'user_login', 'display_name']
        ]);
        ?>
        <div class="form-field form-field-wide">
            <label for="_sales_rep"><?php esc_html_e('Sales Rep', 'gunsafes-core'); ?></label>
            <select name="_sales_rep" id="_sales_rep" class="regular-text">
                <option value="N/A" <?php selected($selected_sales_rep, 'N/A'); ?>><?php esc_html_e('N/A', 'gunsafes-core'); ?></option>
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
     * Auto-sets the Sales Rep on new orders created in the admin (e.g., manual/phone orders).
     *
     * @param int $order_id The new order ID.
     */
    public function auto_set_sales_rep_on_creation($order_id): void {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return; // Skip if not in admin or user lacks permissions
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        // Set flag to indicate this is a manual admin-created order
        $order->update_meta_data('_is_manual_admin_order', true);
        // Auto-set sales rep to current user
        $current_user = wp_get_current_user();
        $order->update_meta_data('_sales_rep', $current_user->user_login);
        $order->save();
    }
    /**
     * Saves the Sales Rep field during order update (only if explicitly changed).
     *
     * @param int $post_id The order ID.
     * @param WP_Post $post The order post object.
     */
    public function save_sales_rep($post_id, $post): void {
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        // Save Sales Rep only if POST value is set and differs from current
        if (isset($_POST['_sales_rep'])) {
            $new_value = sanitize_text_field($_POST['_sales_rep']);
            $current_value = $order->get_meta('_sales_rep', true) ?: 'N/A';
            if ($new_value !== $current_value) {
                $order->update_meta_data('_sales_rep', $new_value);
                $order->save();
            }
        }
    }
    /**
     * Adds custom buttons to the order item section (placeholder for future features).
     *
     * @param WC_Order $order The current order object.
     */
    public function add_custom_buttons($order): void {
        // Placeholder for custom buttons
    }
    /**
     * Adds each Flexible Shipping instance as a separate shipping method for the "Add shipping" dropdown, but only if available for the current order.
     *
     * @param array $methods Existing shipping methods.
     * @return array Modified shipping methods.
     */
    public function add_flexible_shipping_instances($methods): array {
        global $wpdb;
        // Fetch all enabled Flexible Shipping instances from the database
        $instances = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s AND is_enabled = 1",
                'flexible_shipping_single'
            ),
            ARRAY_A
        );
        if (!$instances) {
            return $methods;
        }
        $available_instances = $instances; // Default to all if not on order edit page
        // Check if on order edit screen with existing order
        $screen = get_current_screen();
        if (is_admin() && $screen && $screen->id === 'shop_order' && isset($_GET['post'])) {
            $order_id = absint($_GET['post']);
            $order = wc_get_order($order_id);
            if ($order) {
                $contents = $this->get_order_contents($order);
                if (!empty($contents)) {
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
                    $available_instances = [];
                    foreach ($instances as $instance) {
                        $instance_id = $instance['instance_id'];
                        $shipping_method = WC_Shipping_Zones::get_shipping_method($instance_id);
                        if ($shipping_method && $shipping_method->id === 'flexible_shipping_single') {
                            $shipping_method->calculate_shipping($package);
                            if (!empty($shipping_method->rates)) {
                                $available_instances[] = $instance;
                            }
                        }
                    }
                }
            }
        }
        // Add available instances as custom methods
        foreach ($available_instances as $instance) {
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
        return $methods;
    }
    /**
     * Saves the APF addons during order update or item save.
     *
     * @param int $order_id The order ID.
     * @param array|WP_Post $items_or_post The items array or post object (depending on hook).
     */
    public function save_order_item_addons($order_id, $items_or_post = null): void {
        // Check for order_item_addons in $_POST or parse from $_POST['items']
        $addons_post = [];
        if (isset($_POST['order_item_addons']) && is_array($_POST['order_item_addons'])) {
            $addons_post = wp_unslash($_POST['order_item_addons']);
        } elseif (isset($_POST['items']) && is_string($_POST['items'])) {
            // Parse URL-encoded items string
            parse_str($_POST['items'], $items_array);
            if (isset($items_array['order_item_addons']) && is_array($items_array['order_item_addons'])) {
                $addons_post = wp_unslash($items_array['order_item_addons']);
            }
        }
        if (empty($addons_post)) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        foreach ($order->get_items() as $item_id => $item) {
            if ($item->get_type() !== 'line_item') {
                continue;
            }
            if (!isset($addons_post[$item_id])) {
                continue;
            }
            $addons = $addons_post[$item_id];
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $fields = $this->get_apf_fields_for_product($product);
            // Calculate previous addon cost from existing display metas
            $previous_addon_cost = 0.0;
            foreach ($fields as $field) {
                $display_key = sanitize_text_field($field['label']);
                $saved_formatted = $item->get_meta($display_key, true);
                if ($saved_formatted) {
                    $parsed_value = $this->parse_formatted_to_value($saved_formatted, $field);
                    $previous_addon_cost += $this->get_addon_cost_from_value($parsed_value, $field);
                }
            }
            // Process new addons
            $addon_cost = 0.0;
            foreach ($fields as $field) {
                $field_id = $field['id'];
                $display_key = sanitize_text_field($field['label']);
                // Clear existing meta
                $item->delete_meta_data($display_key);
                // Save new selection
                if (isset($addons[$field_id]) && $addons[$field_id] !== '') {
                    $value = is_array($addons[$field_id]) ? array_map('sanitize_text_field', $addons[$field_id]) : sanitize_text_field($addons[$field_id]);
                    // Build formatted display value
                    $formatted = [];
                    if ($field['type'] === 'select' || $field['type'] === 'radio') {
                        foreach ($field['options']['choices'] as $option) {
                            if ($option['slug'] === $value) {
                                $sel = $option['label'];
                                if (!empty($option['pricing_amount'])) {
                                    $sel .= ' (+$' . number_format($option['pricing_amount'], 2) . ')';
                                }
                                $formatted[] = $sel;
                                break;
                            }
                        }
                    } elseif ($field['type'] === 'checkbox' || $field['type'] === 'checkboxes') {
                        if ($field['type'] === 'checkbox' && $value === '1') {
                            $sel = $field['label'];
                            if (!empty($field['pricing']['amount'])) {
                                $sel .= ' (+$' . number_format($field['pricing']['amount'], 2) . ')';
                            }
                            $formatted[] = $sel;
                        } elseif ($field['type'] === 'checkboxes' && is_array($value)) {
                            foreach ($field['options']['choices'] as $option) {
                                if (in_array($option['slug'], $value)) {
                                    $sel = $option['label'];
                                    if (!empty($option['pricing_amount'])) {
                                        $sel .= ' (+$' . number_format($option['pricing_amount'], 2) . ')';
                                    }
                                    $formatted[] = $sel;
                                }
                            }
                        }
                    }
                    if (!empty($formatted)) {
                        $item->update_meta_data($display_key, implode(', ', $formatted));
                    }
                    // Add to new addon cost
                    $addon_cost += $this->get_addon_cost_from_value($value, $field);
                }
            }
            // Adjust item prices based on addon cost difference
            $quantity = $item->get_quantity();
            $previous_addon_total = $previous_addon_cost * $quantity;
            $new_addon_total = $addon_cost * $quantity;
            $delta = $new_addon_total - $previous_addon_total;
            if ($delta != 0) {
                $item->set_subtotal($item->get_subtotal() + $delta);
                $item->set_total($item->get_total() + $delta);
            }
            $item->save();
        }
        $order->calculate_totals();
    }
    /**
     * AJAX handler for saving addons on inline item save (fallback).
     */
    public function ajax_save_order_item_addons(): void {
        check_ajax_referer('order-item', 'security');
        if (!current_user_can('edit_shop_orders')) {
            wp_die(-1);
        }
        $response = ['success' => false];
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json($response);
        }
        $item = $order->get_item($item_id);
        if (!$item || $item->get_type() !== 'line_item') {
            wp_send_json($response);
        }
        $addons = [];
        if (isset($_POST['order_item_addons']) && is_array($_POST['order_item_addons'])) {
            foreach ($_POST['order_item_addons'] as $input) {
                if (!isset($input['name'], $input['value'])) {
                    continue;
                }
                $name = $input['name'];
                $value = sanitize_text_field($input['value']);
                if (preg_match('/^order_item_addons\[(\d+)\]\[([^\]]+)\](\[\])?$/', $name, $matches)) {
                    $parsed_item_id = $matches[1];
                    $field_id = $matches[2];
                    $is_array = !empty($matches[3]);
                    if ($parsed_item_id != $item_id) {
                        continue;
                    }
                    if ($is_array) {
                        $addons[$field_id][] = $value;
                    } else {
                        $addons[$field_id] = $value;
                    }
                }
            }
        }
        $product = $item->get_product();
        if (!$product) {
            wp_send_json($response);
        }
        $fields = $this->get_apf_fields_for_product($product);
        $previous_addon_cost = 0.0;
        foreach ($fields as $field) {
            $display_key = sanitize_text_field($field['label']);
            $saved_formatted = $item->get_meta($display_key, true);
            if ($saved_formatted) {
                $parsed_value = $this->parse_formatted_to_value($saved_formatted, $field);
                $previous_addon_cost += $this->get_addon_cost_from_value($parsed_value, $field);
            }
        }
        $addon_cost = 0.0;
        foreach ($fields as $field) {
            $field_id = $field['id'];
            $display_key = sanitize_text_field($field['label']);
            $item->delete_meta_data($display_key);
            if (isset($addons[$field_id]) && $addons[$field_id] !== '') {
                $value = is_array($addons[$field_id]) ? $addons[$field_id] : $addons[$field_id];
                $formatted = [];
                if ($field['type'] === 'select' || $field['type'] === 'radio') {
                    foreach ($field['options']['choices'] as $option) {
                        if ($option['slug'] === $value) {
                            $sel = $option['label'];
                            if (!empty($option['pricing_amount'])) {
                                $sel .= ' (+$' . number_format($option['pricing_amount'], 2) . ')';
                            }
                            $formatted[] = $sel;
                            break;
                        }
                    }
                } elseif ($field['type'] === 'checkbox' || $field['type'] === 'checkboxes') {
                    if ($field['type'] === 'checkbox' && $value === '1') {
                        $sel = $field['label'];
                        if (!empty($field['pricing']['amount'])) {
                            $sel .= ' (+$' . number_format($field['pricing']['amount'], 2) . ')';
                        }
                        $formatted[] = $sel;
                    } elseif ($field['type'] === 'checkboxes' && is_array($value)) {
                        foreach ($field['options']['choices'] as $option) {
                            if (in_array($option['slug'], $value)) {
                                $sel = $option['label'];
                                if (!empty($option['pricing_amount'])) {
                                    $sel .= ' (+$' . number_format($option['pricing_amount'], 2) . ')';
                                }
                                $formatted[] = $sel;
                            }
                        }
                    }
                }
                if (!empty($formatted)) {
                    $item->update_meta_data($display_key, implode(', ', $formatted));
                }
                $addon_cost += $this->get_addon_cost_from_value($value, $field);
            }
        }
        $quantity = $item->get_quantity();
        $previous_addon_total = $previous_addon_cost * $quantity;
        $new_addon_total = $addon_cost * $quantity;
        $delta = $new_addon_total - $previous_addon_total;
        if ($delta != 0) {
            $item->set_subtotal($item->get_subtotal() + $delta);
            $item->set_total($item->get_total() + $delta);
        }
        $item->calculate_taxes();
        $item->save();
        $order->calculate_totals();
        ob_start();
        wc_get_template('admin/meta-boxes/views/html-order-item-taxes.php', ['item' => $item]);
        $html = ob_get_clean();
        $response['success'] = true;
        $response['html'] = $html;
        $response['subtotal'] = html_entity_decode(wc_price($item->get_subtotal()), ENT_QUOTES, 'UTF-8');
        $response['total'] = html_entity_decode(wc_price($item->get_total()), ENT_QUOTES, 'UTF-8');
        wp_send_json($response);
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
     * Calculates and updates shipping cost for a Flexible Shipping item based on order contents, allowing manual overrides.
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
        $settings = get_option("woocommerce_flexible_shipping_single_{$instance_id}_settings", []);
        if (!isset($settings['title'])) {
            return;
        }
        // Get the real Flexible Shipping method instance
        $shipping_method = WC_Shipping_Zones::get_shipping_method($instance_id);
        if (!$shipping_method || $shipping_method->id !== 'flexible_shipping_single') {
            return;
        }
        // Build package from order data
        $contents = $this->get_order_contents($order);
        if (empty($contents)) {
            $calculated_cost = 0.0;
            $calculated_taxes = ['total' => []];
            $label = $settings['title'];
        } else {
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
            $calculated_cost = 0.0;
            $calculated_taxes = ['total' => []];
            $label = $settings['title'];
            if (!empty($rates)) {
                $rate = reset($rates); // Use the first rate
                $calculated_cost = (float) $rate->cost;
                $calculated_taxes = ['total' => $rate->taxes];
                $label = $rate->label;
            }
        }
        // Get last calculated cost from meta
        $last_calculated = (float) $item->get_meta('_last_calculated_cost', true);
        // Get current total (after any POST updates)
        $current_total = (float) $item->get_total();
        if (abs($current_total - $last_calculated) < 0.01) {
            // Not manual override: update to new calculated
            $item->set_total($calculated_cost);
            $item->set_taxes($calculated_taxes);
            $item->update_meta_data('_last_calculated_cost', $calculated_cost);
        } else {
            // Manual override: keep current total, recalculate taxes
            if ($shipping_method->tax_status === 'taxable') {
                $tax_rates = WC_Tax::get_shipping_tax_rates();
                $taxes = WC_Tax::calc_tax($current_total, $tax_rates, false);
                $item->set_taxes(['total' => $taxes]);
            } else {
                $item->set_taxes(['total' => []]);
            }
            // Do not update _last_calculated_cost to maintain manual flag
        }
        // Update name to (possibly new) label
        $item->set_name($label);
        $item->save();
    }
    /**
     * Builds the order contents array for reuse in shipping and addon calculations.
     *
     * @param WC_Order $order The order object.
     * @return array The order contents array.
     */
    private function get_order_contents(WC_Order $order): array {
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
                'variation' => $order_item->get_meta_data(),
                'quantity' => $order_item->get_quantity(),
                'line_total' => $order_item->get_total(),
                'line_tax' => $order_item->get_total_tax(),
                'line_subtotal' => $order_item->get_subtotal(),
                'line_subtotal_tax' => $order_item->get_subtotal_tax(),
                'data' => $product,
            ];
        }
        return $contents;
    }
    /**
     * Adds the "Addons" column header to the order items table (hidden via CSS).
     */
    public function add_addons_column_header(): void {
        echo '<th class="item_addons sortable" data-sort="string-ins">' . esc_html__('Addons', 'gunsafes-core') . '</th>';
    }
    /**
     * Displays the APF addons in the order items table under the "Addons" column (will be moved to sub-row via JS).
     *
     * @param WC_Product|null $product The product object.
     * @param WC_Order_Item $item The order item object.
     * @param int $item_id The order item ID.
     */
    public function display_addons_column($product, $item, $item_id): void {
        if ($item->get_type() !== 'line_item' || !$product) {
            echo '<td class="item_addons"></td>';
            return;
        }
        $fields = $this->get_apf_fields_for_product($product);
        if (empty($fields)) {
            echo '<td class="item_addons">' . esc_html__('No addons available', 'gunsafes-core') . '</td>';
            return;
        }
        echo '<td class="item_addons">';
        foreach ($fields as $field) {
            $field_id = $field['id'];
            $display_key = sanitize_text_field($field['label']);
            $saved_formatted = $item->get_meta($display_key, true);
            $saved_value = $saved_formatted ? $this->parse_formatted_to_value($saved_formatted, $field) : '';
            $required = !empty($field['required']) ? ' <span class="required">*</span>' : '';
            ?>
            <div class="addon-field">
                <label><?php echo esc_html($field['label']) . $required; ?></label>
                <?php if ($field['type'] === 'checkbox' || $field['type'] === 'checkboxes') : ?>
                    <div class="addon-checkbox-group">
                        <?php foreach ($field['options']['choices'] as $option) : ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox"
                                       name="order_item_addons[<?php echo esc_attr($item_id); ?>][<?php echo esc_attr($field_id); ?>][]"
                                       value="<?php echo esc_attr($option['slug']); ?>"
                                       <?php if (is_array($saved_value) && in_array($option['slug'], $saved_value)) echo 'checked'; ?> />
                                <?php echo esc_html($option['label']); ?>
                                <?php if (!empty($option['pricing_amount'])) : ?>
                                    (+<?php echo wc_price($option['pricing_amount']); ?>)
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($field['type'] === 'select') : ?>
                    <select name="order_item_addons[<?php echo esc_attr($item_id); ?>][<?php echo esc_attr($field_id); ?>]" <?php echo $field['required'] ? 'required' : ''; ?>>
                        <option value=""><?php esc_html_e('Select an option', 'gunsafes-core'); ?></option>
                        <?php foreach ($field['options']['choices'] as $option) : ?>
                            <option value="<?php echo esc_attr($option['slug']); ?>"
                                    <?php selected($saved_value, $option['slug']); ?>>
                                <?php echo esc_html($option['label']); ?>
                                <?php if (!empty($option['pricing_amount'])) : ?>
                                    (+<?php echo wc_price($option['pricing_amount']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($field['type'] === 'radio') : ?>
                    <div class="addon-radio-group">
                        <?php foreach ($field['options']['choices'] as $option) : ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="radio"
                                       name="order_item_addons[<?php echo esc_attr($item_id); ?>][<?php echo esc_attr($field_id); ?>]"
                                       value="<?php echo esc_attr($option['slug']); ?>"
                                       <?php checked($saved_value, $option['slug']); ?>
                                       <?php echo $field['required'] ? 'required' : ''; ?> />
                                <?php echo esc_html($option['label']); ?>
                                <?php if (!empty($option['pricing_amount'])) : ?>
                                    (+<?php echo wc_price($option['pricing_amount']); ?>)
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }
        echo '</td>';
    }
    /**
     * Parses the formatted display value back to the raw value (slug or array of slugs).
     *
     * @param string $formatted The formatted string saved in meta.
     * @param array $field The field configuration.
     * @return mixed The parsed raw value (string, array, or '1' for single checkbox).
     */
    private function parse_formatted_to_value($formatted, $field): mixed {
        // Split by comma for multi-values
        $parts = explode(', ', $formatted);
        $clean_parts = [];
        foreach ($parts as $part) {
            // Remove the (+$xx.xx) part
            $part = preg_replace('/ \(([^)]+)\)$/', '', $part);
            $clean_parts[] = trim($part);
        }
        $type = $field['type'];
        if ($type === 'select' || $type === 'radio') {
            if (count($clean_parts) !== 1) {
                return '';
            }
            $clean = $clean_parts[0];
            foreach ($field['options']['choices'] as $option) {
                if ($option['label'] === $clean) {
                    return $option['slug'];
                }
            }
            return '';
        } elseif ($type === 'checkbox') {
            if (count($clean_parts) !== 1) {
                return '';
            }
            $clean = $clean_parts[0];
            if ($clean === $field['label']) {
                return '1';
            }
            return '';
        } elseif ($type === 'checkboxes') {
            $selected = [];
            foreach ($clean_parts as $clean) {
                foreach ($field['options']['choices'] as $option) {
                    if ($option['label'] === $clean) {
                        $selected[] = $option['slug'];
                        break;
                    }
                }
            }
            return $selected;
        }
        return '';
    }
    /**
     * Calculates the addon cost based on the raw value and field configuration.
     *
     * @param mixed $value The raw value (string, array, or '1').
     * @param array $field The field configuration.
     * @return float The calculated cost.
     */
    private function get_addon_cost_from_value($value, $field): float {
        $cost = 0.0;
        $type = $field['type'];
        if ($type === 'select' || $type === 'radio') {
            if (!is_string($value)) {
                return $cost;
            }
            foreach ($field['options']['choices'] as $option) {
                if ($option['slug'] === $value) {
                    $cost += (float) ($option['pricing_amount'] ?? 0);
                    break;
                }
            }
        } elseif ($type === 'checkbox') {
            if ($value === '1') {
                $cost += (float) ($field['pricing']['amount'] ?? 0);
            }
        } elseif ($type === 'checkboxes') {
            if (!is_array($value)) {
                $value = [];
            }
            foreach ($field['options']['choices'] as $option) {
                if (in_array($option['slug'], $value)) {
                    $cost += (float) ($option['pricing_amount'] ?? 0);
                }
            }
        }
        return $cost;
    }
    /**
     * Fetches APF fields for a given product or variation.
     *
     * @param WC_Product $product The product object.
     * @return array List of APF fields.
     */
    private function get_apf_fields_for_product(WC_Product $product): array {
        $fields = [];
        $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $variation_id = $product->is_type('variation') ? $product->get_id() : 0;
        // Try variation first, then parent product
        $field_group = $variation_id ? get_post_meta($variation_id, '_wapf_fieldgroup', true) : [];
        if (empty($field_group) || !is_array($field_group)) {
            $field_group = get_post_meta($product_id, '_wapf_fieldgroup', true);
        }
        if (empty($field_group) || !is_array($field_group) || empty($field_group['fields'])) {
            return [];
        }
        // Verify product matches rule_groups
        $applies = false;
        if (!empty($field_group['rule_groups'])) {
            foreach ($field_group['rule_groups'] as $group) {
                foreach ($group['rules'] as $rule) {
                    if ($rule['condition'] === 'product' && !empty($rule['value'])) {
                        foreach ($rule['value'] as $val) {
                            if ((string) $val['id'] === (string) $product_id || (string) $val['id'] === (string) $variation_id) {
                                $applies = true;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        if (!$applies) {
            return [];
        }
        foreach ($field_group['fields'] as $field) {
            if (in_array($field['type'], ['checkbox', 'checkboxes', 'select', 'radio'], true)) {
                $fields[] = $field;
            }
        }
        return $fields;
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
            // Add inline JS to move addons to sub-row and handle inline save
            $js = "
            jQuery(document).ready(function($) {
                function moveAddons() {
                    $('tr.item').each(function() {
                        var \$row = \$(this);
                        var \$addonsTd = \$row.find('.item_addons');
                        var item_id = \$row.find('input.order_item_id').val();
                        if (\$addonsTd.length && \$addonsTd.html().trim() !== '' && \$addonsTd.html().trim() !== '" . esc_js(esc_html__('No addons available', 'gunsafes-core')) . "') {
                            var colspan = \$row.children('th, td').length;
                            var \$newRow = \$('<tr class=\"addons-row\" data-item-id=\"' + item_id + '\"><td colspan=\"' + colspan + '\"></td></tr>');
                            \$newRow.find('td').append(\$addonsTd.html());
                            \$row.after(\$newRow);
                            \$addonsTd.empty();
                        }
                    });
                }
                moveAddons();
                $('body').on('added_order_item', moveAddons);
                $('body').on('woocommerce_saved_order_items', moveAddons);
                // Move addons to inline edit form
                $('body').on('click', '.edit-order-item', function(e) {
                    var item_id = $(this).data('order_item_id');
                    setTimeout(function() {
                        var \$row = $('tr.item').has('input.order_item_id[value=\"' + item_id + '\"]');
                        var \$addonsRow = \$row.next('.addons-row[data-item-id=\"' + item_id + '\"]');
                        if (\$addonsRow.length) {
                            var \$editForm = \$row.find('.edit_item');
                            \$editForm.append('<div class=\"gunsafes-addons-edit\">' + \$addonsRow.find('td').html() + '</div>');
                        }
                    }, 100);
                });
                // Handle inline save
                $('body').on('click', '.save', function(e) {
                    var \$row = $(this).closest('tr.item');
                    var item_id = \$row.find('input.order_item_id').val();
                    var \$editForm = \$row.find('.edit_item');
                    var addonData = \$editForm.find('.gunsafes-addons-edit').find('input, select').serializeArray();
                    var data = {
                        action: 'save_order_item_addons',
                        security: woocommerce_admin_meta_boxes.order_item_nonce,
                        order_id: woocommerce_admin_meta_boxes.post_id,
                        item_id: item_id,
                        order_item_addons: addonData
                    };
                    $.post(woocommerce_admin_meta_boxes.ajax_url, data, function(response) {
                        if (response.success) {
                            \$row.find('.line_subtotal .view').html(response.subtotal);
                            \$row.find('.line_total .view').html(response.total);
                            \$row.find('.line_tax .view').html(response.html);
                            $('.button.calc_totals').trigger('click');
                        }
                    });
                });
            });
            ";
            wp_add_inline_script('gunsafes-core-order', $js);
            // Add inline CSS to hide addons column and style sub-row
            $css = '
            th.item_addons, td.item_addons { display: none; }
            .addons-row td { padding: 10px; background: #f8f8f8; border-top: 1px solid #ddd; }
            .addon-field { margin-bottom: 8px; }
            .addon-field label { display: block; font-weight: bold; }
            .addon-radio-group { margin-top: 5px; }
            .addon-checkbox-group { margin-top: 5px; }
            .required { color: red; }
            .gunsafes-addons-edit { margin-top: 10px; padding: 10px; background: #f8f8f8; border: 1px solid #ddd; }
            ';
            wp_add_inline_style('gunsafes-core-order', $css);
        }
    }
}