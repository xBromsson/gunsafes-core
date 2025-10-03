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
        // Save the Sales Rep field and APF addons with higher priority for HPOS compatibility
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_sales_rep_and_addons'], 100, 2);
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
        // Placeholder for custom buttons
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
     * Saves the Sales Rep field and APF addons during order update.
     *
     * @param int $post_id The order ID.
     * @param WP_Post $post The order post object.
     */
    public function save_sales_rep_and_addons($post_id, $post): void {
        $order = wc_get_order($post_id); // Load WC_Order object for HPOS compatibility
        if (!$order) {
            return;
        }
        // Save Sales Rep
        if (isset($_POST['_sales_rep'])) {
            $new_value = sanitize_text_field($_POST['_sales_rep']);
            $current_value = $order->get_meta('_sales_rep', true);
            // Only update if the value has changed
            if ($new_value !== $current_value) {
                $order->update_meta_data('_sales_rep', $new_value); // Use CRUD method for HPOS
                $order->save(); // Persist to HPOS tables
            }
        }
        // Save APF Addons
        if (isset($_POST['order_item_addons']) && is_array($_POST['order_item_addons'])) {
            foreach ($order->get_items() as $item_id => $item) {
                if ($item->get_type() !== 'line_item') {
                    continue;
                }
                if (!isset($_POST['order_item_addons'][$item_id])) {
                    continue;
                }
                $addons = wp_unslash($_POST['order_item_addons'][$item_id]);
                $product = $item->get_product();
                if (!$product) {
                    continue;
                }
                $fields = $this->get_apf_fields_for_product($product);
                foreach ($fields as $field) {
                    $field_id = $field['id'];
                    $meta_key = '_wapf_' . $field_id;
                    // Clear existing addon meta
                    $item->delete_meta_data($meta_key);
                    // Save new selection
                    if (isset($addons[$field_id]) && !empty($addons[$field_id])) {
                        $value = is_array($addons[$field_id]) ? array_map('sanitize_text_field', $addons[$field_id]) : sanitize_text_field($addons[$field_id]);
                        $item->update_meta_data($meta_key, $value);
                    }
                }
                $item->save();
            }
            $order->calculate_totals(); // Recalculate to include addon costs
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
            $this->calculate_and_update_shipping_item($item, $order, $items);
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
     * @param array $items Optional order items from save action for checking manual cost.
     */
    private function calculate_and_update_shipping_item(WC_Order_Item_Shipping $item, WC_Order $order, $items = []): void {
        $method_id = $item->get_method_id();
        if (strpos($method_id, 'flexible_shipping_') !== 0) {
            return;
        }
        $instance_id = (int) str_replace('flexible_shipping_', '', $method_id);
        // Check for manual override in POST data or existing item cost
        $manual_cost = null;
        if (!empty($items) && isset($items[$item->get_id()]['order_item_total'])) {
            $manual_cost = floatval($items[$item->get_id()]['order_item_total']);
        } elseif ($item->get_total() > 0) {
            $manual_cost = $item->get_total();
        }
        // Skip recalculation if a manual cost is set
        if ($manual_cost !== null && $manual_cost >= 0) {
            $settings = get_option("woocommerce_flexible_shipping_single_{$instance_id}_settings", []);
            if (isset($settings['title'])) {
                $item->set_name($settings['title']); // Update name only
                $item->save();
            }
            return;
        }
        // Get the real Flexible Shipping method instance
        $shipping_method = WC_Shipping_Zones::get_shipping_method($instance_id);
        if (!$shipping_method || $shipping_method->id !== 'flexible_shipping_single') {
            return;
        }
        // Build package from order data (mimics cart package for shipping calculation)
        $contents = $this->get_order_contents($order);
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
                'variation' => $order_item->get_meta_data(), // Array of variation attributes
                'quantity' => $order_item->get_quantity(),
                'line_total' => $order_item->get_total(), // After discounts, excl. tax
                'line_tax' => $order_item->get_total_tax(),
                'line_subtotal' => $order_item->get_subtotal(), // Before discounts, excl. tax
                'line_subtotal_tax' => $order_item->get_subtotal_tax(),
                'data' => $product,
            ];
        }
        return $contents;
    }
    /**
     * Adds the "Addons" column header to the order items table (hidden, used as placeholder).
     */
    public function add_addons_column_header(): void {
        ?>
        <th class="item_addons sortable" data-sort="string-ins"><?php esc_html_e('Addons', 'gunsafes-core'); ?></th>
        <?php
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
        ?>
        <td class="item_addons">
            <?php foreach ($fields as $field) : ?>
                <div class="addon-field">
                    <?php
                    $field_id = $field['id'];
                    $meta_key = '_wapf_' . $field_id;
                    $saved_value = $item->get_meta($meta_key, true);
                    $required = !empty($field['required']) ? ' <span class="required">*</span>' : '';
                    ?>
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
            <?php endforeach; ?>
        </td>
        <?php
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
            // Add inline JS to move addons to sub-row
            $js = "
            jQuery(document).ready(function($) {
                function moveAddons() {
                    $('tr.item').each(function() {
                        var \$row = \$(this);
                        var \$addonsTd = \$row.find('.item_addons');
                        if (\$addonsTd.length && \$addonsTd.html().trim() !== '' && \$addonsTd.html().trim() !== '" . esc_js(esc_html__('No addons available', 'gunsafes-core')) . "') {
                            var colspan = \$row.children('th, td').length;
                            var \$newRow = \$('<tr class=\"addons-row\"><td colspan=\"' + colspan + '\"></td></tr>');
                            \$newRow.find('td').append(\$addonsTd.html());
                            \$row.after(\$newRow);
                            \$addonsTd.empty();
                        }
                    });
                }
                moveAddons();
                \$('body').on('added_order_item', moveAddons);
                \$('body').on('woocommerce_saved_order_items', moveAddons);
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
            ';
            wp_add_inline_style('gunsafes-core-order', $css);
        }
    }
}