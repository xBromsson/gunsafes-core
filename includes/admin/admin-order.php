<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Prevent direct file access and ensure WooCommerce is active
if ( ! class_exists( 'WooCommerce' ) ) {
    return; // Exit if WooCommerce is not active
}

/**
 * Handles custom admin order screen functionality.
 */
class Admin_Order {

    public function __construct() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $this->register();

        add_filter( 'woocommerce_admin_billing_fields',  [ $this, 'default_country_to_us' ] );
        add_filter( 'woocommerce_admin_shipping_fields', [ $this, 'default_country_to_us' ] );
    }

    public function register(): void {
        add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'add_custom_fields' ] );
        add_action( 'woocommerce_admin_order_item_add_line_buttons', [ $this, 'add_custom_buttons' ] );
        add_action( 'woocommerce_new_order', [ $this, 'auto_set_sales_rep_on_creation' ], 10, 1 );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_sales_rep' ], 100, 2 );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'preserve_coupons_before_save' ], 5, 2 );
        add_action( 'woocommerce_saved_order_items', [ $this, 'preserve_coupons_before_save' ], 5, 2 );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_order_item_addons' ], 100, 2 );
        add_action( 'woocommerce_saved_order_items', [ $this, 'save_order_item_addons' ], 20, 2 );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'force_recalculate_after_addons' ], 101, 2 );
        add_action( 'wp_ajax_save_order_item_addons', [ $this, 'ajax_save_order_item_addons' ] );
        add_filter( 'woocommerce_shipping_methods', [ $this, 'add_flexible_shipping_instances' ] );
        add_action( 'woocommerce_saved_order_items', [ $this, 'update_shipping_name' ], 10, 2 );
        add_action( 'woocommerce_new_order_item', [ $this, 'handle_new_order_item' ], 10, 3 );
        add_action( 'woocommerce_admin_order_item_headers', [ $this, 'add_addons_column_header' ] );
        add_action( 'woocommerce_admin_order_item_values', [ $this, 'display_addons_column' ], 10, 3 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'woocommerce_order_item_quantity', [ $this, 'prevent_stock_reduction_for_quote' ], 10, 3 );
        add_filter( 'woocommerce_email_enabled_customer_completed_order', [ $this, 'disable_emails_for_quote' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_new_order', [ $this, 'disable_emails_for_quote' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_customer_processing_order', [ $this, 'disable_emails_for_quote' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_customer_quote', [ $this, 'disable_emails_for_quote' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_admin_quote', [ $this, 'disable_emails_for_quote' ], 10, 2 );
    }

    /* --------------------------------------------------------------------- */
    /*  COUPON BACKUP / RESTORE                                            */
    /* --------------------------------------------------------------------- */
    public function preserve_coupons_before_save( $post_id, $post = null ): void {
        $order = wc_get_order( $post_id );
        if ( ! $order ) {
            return;
        }

        $coupons       = $order->get_items( 'coupon' );
        $coupon_codes  = [];
        foreach ( $coupons as $item ) {
            $coupon_codes[] = $item->get_code();
        }
        update_post_meta( $post_id, '_temp_coupon_backup', $coupon_codes );

        $hook = current_filter();
        error_log( "[COUPON DEBUG] Backup saved via {$hook} for order {$post_id}: " . count( $coupon_codes ) . ' coupons' );
    }

    /* --------------------------------------------------------------------- */
    /*  DEFAULT ADMIN ORDER SCREEN ADDRESSES TO USA                          */
    /* --------------------------------------------------------------------- */

    public function default_country_to_us( $fields ) {
    if ( empty( $fields['country']['value'] ) ) {
        $fields['country']['value'] = 'US';
    }
    return $fields;
}

    /* --------------------------------------------------------------------- */
    /*  SHIPPING MARKUP HELPERS                                            */
    /* --------------------------------------------------------------------- */
    private function apply_regional_shipping_markups( $cost, $package ): float {
        $zip_text   = get_option( 'gscore_regional_markups_zip', '' );
        $state_text = get_option( 'gscore_regional_markups_state', '' );

        $zip_markups   = $this->text_to_array( $zip_text, $this->get_default_zip() );
        $state_markups = $this->text_to_array( $state_text, $this->get_default_state() );

        $state    = $package['destination']['state'] ?? '';
        $postcode = $package['destination']['postcode'] ?? '';

        $markup_percent = 0;
        if ( isset( $zip_markups[ $postcode ] ) ) {
            $markup_percent = $zip_markups[ $postcode ];
        } elseif ( isset( $state_markups[ $state ] ) ) {
            $markup_percent = $state_markups[ $state ];
        }

        if ( $markup_percent > 0 ) {
            return round( $cost * ( 1 + $markup_percent / 100 ), 2 );
        }
        return $cost;
    }

    private function text_to_array( $text, $defaults ) {
        if ( empty( $text ) ) {
            return $defaults;
        }
        $array = [];
        $lines = array_filter( array_map( 'trim', explode( "\n", $text ) ) );
        foreach ( $lines as $line ) {
            if ( preg_match( '/^(\w+)\s+([\d.]+)%?$/', $line, $m ) ) {
                $array[ $m[1] ] = (float) $m[2];
            }
        }
        return $array;
    }

    private function get_default_zip() {
        return [
            '07876' => 20.0, '05001' => 25.0, '02901' => 25.0,
            '81120' => 30.0, '81302' => 30.0, '81303' => 30.0, '81301' => 30.0,
            '80435' => 30.0, '80438' => 30.0, '80442' => 30.0, '80443' => 30.0,
            '80446' => 30.0, '80447' => 30.0, '80451' => 30.0, '80452' => 30.0,
            '80459' => 30.0, '80468' => 30.0, '80473' => 30.0, '80478' => 30.0,
            '80482' => 30.0,
        ];
    }

    private function get_default_state() {
        return [
            'NJ' => 20.0, 'NY' => 20.0, 'VT' => 25.0, 'RI' => 25.0, 'CO' => 30.0,
            'ME' => 25.0, 'NH' => 25.0, 'CT' => 25.0, 'VA' => 25.0, 'ND' => 35.0,
            'WI' => 65.0, 'WY' => 30.0, 'CA' => 75.0, 'MA' => 40.0, 'MT' => 75.0,
            'AL' => 30.0, 'MD' => 20.0, 'MI' => 150.0, 'UT' => 100.0, 'IL' => 50.0,
        ];
    }

    /* --------------------------------------------------------------------- */
    /*  SALES REP                                                          */
    /* --------------------------------------------------------------------- */
    public function add_custom_fields( $order ): void {
        $current_user      = wp_get_current_user();
        $saved_sales_rep   = $order->get_meta( '_sales_rep', true );
        $selected_sales_rep = $saved_sales_rep ?: 'N/A';

        $client_users = get_users( [
            'role'   => 'client',
            'fields' => [ 'ID', 'user_login', 'display_name' ],
        ] );
        $users_to_show = $client_users;
        ?>
        <div class="form-field form-field-wide">
            <label for="_sales_rep"><?php esc_html_e( 'Sales Rep', 'gunsafes-core' ); ?></label>
            <select name="_sales_rep" id="_sales_rep" class="regular-text">
                <option value="N/A" <?php selected( $selected_sales_rep, 'N/A' ); ?>>
                    <?php esc_html_e( 'N/A', 'gunsafes-core' ); ?>
                </option>
                <?php foreach ( $users_to_show as $user ) : ?>
                    <option value="<?php echo esc_attr( $user->user_login ); ?>"
                            <?php selected( $selected_sales_rep, $user->user_login ); ?>>
                        <?php echo esc_html( $user->display_name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    public function auto_set_sales_rep_on_creation( $order_id ): void {
        if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $order->update_meta_data( '_is_manual_admin_order', true );
        $current_user = wp_get_current_user();
        $order->update_meta_data( '_sales_rep', $current_user->user_login );
        $order->save();
    }

    public function save_sales_rep( $post_id, $post ): void {
        $order = wc_get_order( $post_id );
        if ( ! $order ) {
            return;
        }
        if ( isset( $_POST['_sales_rep'] ) ) {
            $new_value     = sanitize_text_field( $_POST['_sales_rep'] );
            $current_value = $order->get_meta( '_sales_rep', true ) ?: 'N/A';
            if ( $new_value !== $current_value ) {
                $order->update_meta_data( '_sales_rep', $new_value );
                $order->save();
            }
        }
    }

    public function add_custom_buttons( $order ): void {}

    /* --------------------------------------------------------------------- */
    /*  FLEXIBLE SHIPPING INSTANCES                                        */
    /* --------------------------------------------------------------------- */
    public function add_flexible_shipping_instances( $methods ): array {
        global $wpdb;
        $instances = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s AND is_enabled = 1",
                'flexible_shipping_single'
            ),
            ARRAY_A
        );
        if ( ! $instances ) {
            return $methods;
        }

        $available_instances = $instances;
        $screen              = get_current_screen();
        if ( is_admin() && $screen && $screen->id === 'shop_order' && isset( $_GET['post'] ) ) {
            $order_id = absint( $_GET['post'] );
            $order    = wc_get_order( $order_id );
            if ( $order ) {
                $contents = $this->get_order_contents( $order );
                if ( ! empty( $contents ) ) {
                    $package = [
                        'contents'       => $contents,
                        'contents_cost'  => array_sum( wp_list_pluck( $contents, 'line_total' ) ),
                        'applied_coupons'=> $order->get_coupon_codes(),
                        'user'           => [ 'ID' => $order->get_user_id() ],
                        'destination'    => [
                            'country'   => $order->get_shipping_country(),
                            'state'     => $order->get_shipping_state(),
                            'postcode'  => $order->get_shipping_postcode(),
                            'city'      => $order->get_shipping_city(),
                            'address'   => $order->get_shipping_address_1(),
                            'address_2' => $order->get_shipping_address_2(),
                        ],
                    ];
                    $available_instances = [];
                    foreach ( $instances as $instance ) {
                        $instance_id     = $instance['instance_id'];
                        $shipping_method = WC_Shipping_Zones::get_shipping_method( $instance_id );
                        if ( $shipping_method && $shipping_method->id === 'flexible_shipping_single' ) {
                            $shipping_method->calculate_shipping( $package );
                            if ( ! empty( $shipping_method->rates ) ) {
                                $available_instances[] = $instance;
                            }
                        }
                    }
                }
            }
        }

        foreach ( $available_instances as $instance ) {
            $instance_id = $instance['instance_id'];
            $settings    = get_option( "woocommerce_flexible_shipping_single_{$instance_id}_settings", [] );
            if ( isset( $settings['title'] ) ) {
                $method_id = "flexible_shipping_{$instance_id}";
                $methods[ $method_id ] = new class( $instance_id, $settings['title'] ) extends WC_Shipping_Method {
                    public $instance_id;
                    public $method_title;
                    public function __construct( $instance_id, $title ) {
                        $this->id           = "flexible_shipping_{$instance_id}";
                        $this->instance_id  = $instance_id;
                        $this->method_title = $title;
                        $this->title        = $title;
                    }
                    public function init() {}
                    public function is_available( $package ) { return true; }
                };
            }
        }
        return $methods;
    }

    /* --------------------------------------------------------------------- */
    /*  SAVE ORDER ITEM ADD-ONS (NORMAL & AJAX)                             */
    /* --------------------------------------------------------------------- */
    public function save_order_item_addons( $order_id, $items_or_post = null ): void {
        $addons_post = $this->parse_addons_post_data();

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( $item->get_type() !== 'line_item' ) {
                continue;
            }
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $fields = $this->get_apf_fields_for_product( $product );

            // ---- 1. Remove old add-on meta & calculate previous cost (for logging) ----
            $previous_addon_cost = 0.0;
            foreach ( $fields as $field ) {
                $display_key   = sanitize_text_field( $field['label'] );
                $saved_formatted = $item->get_meta( $display_key, true );
                if ( $saved_formatted ) {
                    $parsed_value = $this->parse_formatted_to_value( $saved_formatted, $field );
                    if ( $field['type'] === 'checkboxes' && ! empty( $parsed_value ) ) {
                        $parsed_value = explode( ',', $parsed_value );
                    } else {
                        $parsed_value = (array) $parsed_value;
                    }
                    $previous_addon_cost += $this->get_addon_cost_from_value( $parsed_value, $field );
                }
                $item->delete_meta_data( $display_key );
            }

            // ---- 2. No add-ons posted → reset to base price ----
            if ( ! isset( $addons_post[ $item_id ] ) ) {
                $quantity      = $item->get_quantity();
                $base_price    = (float) $product->get_price();
                $new_subtotal  = $base_price * $quantity;

                $item->set_subtotal( $new_subtotal );
                $item->set_total( $new_subtotal );

                $tax_data = $item->calculate_taxes();
                $item->set_taxes( $tax_data );
                $item->save();
                continue;
            }

            // ---- 3. Process posted add-ons ----
            $addons      = $addons_post[ $item_id ];
            $addon_cost  = 0.0;

            foreach ( $fields as $field ) {
                $field_id    = $field['id'];
                $display_key = sanitize_text_field( $field['label'] );

                if ( ! array_key_exists( $field_id, $addons ) ) {
                    continue;
                }

                $value = is_array( $addons[ $field_id ] )
                    ? array_map( 'sanitize_text_field', $addons[ $field_id ] )
                    : sanitize_text_field( $addons[ $field_id ] );

                // ---- format for display ----
                $formatted = $this->format_addon_display( $value, $field );
                if ( ! empty( $formatted ) ) {
                    $item->update_meta_data( $display_key, implode( ', ', $formatted ) );
                }

                // ---- add to cost ----
                $addon_cost += $this->get_addon_cost_from_value( $value, $field );
            }

            // ---- 4. Set new line totals & recalc taxes ----
            $quantity     = $item->get_quantity();
            $base_price   = (float) $product->get_price();
            $new_subtotal = ( $base_price + $addon_cost ) * $quantity;

            $item->set_subtotal( $new_subtotal );
            $item->set_total( $new_subtotal );

            $tax_data = $item->calculate_taxes();
            $item->set_taxes( $tax_data );
            $item->save();
        }

        // ---- COUPON RESTORE (unchanged) ----
        $backup_codes = get_post_meta( $order_id, '_temp_coupon_backup', true );
        if ( ! empty( $backup_codes ) ) {
            error_log( "[COUPON DEBUG] Restoring " . count( $backup_codes ) . " coupon codes for order {$order_id}" );

            foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
                $order->remove_item( $coupon_item->get_id() );
            }

            foreach ( $backup_codes as $code ) {
                $result = $order->apply_coupon( $code );
                if ( is_wp_error( $result ) ) {
                    error_log( "[COUPON DEBUG] Failed to apply coupon {$code}: " . $result->get_error_message() );
                } else {
                    error_log( "[COUPON DEBUG] Successfully applied coupon {$code}" );
                }
            }
            delete_post_meta( $order_id, '_temp_coupon_backup' );
        }

        // Re-calculate totals if triggered via saved_order_items (AJAX save button)
        if ( current_filter() === 'woocommerce_saved_order_items' ) {
            $order->calculate_taxes();
            $order->calculate_totals( false );
        }

        // **DO NOT** call calculate_totals here on process_shop_order_meta
        // That's handled by force_recalculate_after_addons at priority 101
    }

    /**
     * Force full recalculation after add-ons during main "Update" button
     */
    public function force_recalculate_after_addons( $post_id, $post ) {
        $order = wc_get_order( $post_id );
        if ( ! $order ) {
            return;
        }

        $has_addons = false;
        foreach ( $order->get_items() as $item ) {
            if ( $item->get_type() === 'line_item' ) {
                $product = $item->get_product();
                if ( $product && ! empty( $this->get_apf_fields_for_product( $product ) ) ) {
                    $has_addons = true;
                    break;
                }
            }
        }

        $backup_exists = ! empty( get_post_meta( $post_id, '_temp_coupon_backup', true ) );

        if ( $has_addons || $backup_exists ) {
            $order->calculate_taxes();
            $order->calculate_totals( false );
            delete_post_meta( $post_id, '_temp_coupon_backup' );
        }
    }

    public function ajax_save_order_item_addons(): void {
        check_ajax_referer( 'order-item', 'security' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( -1 );
        }

        $response = [ 'success' => false ];
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $item_id  = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json( $response );
        }

        $item = $order->get_item( $item_id );
        if ( ! $item || $item->get_type() !== 'line_item' ) {
            wp_send_json( $response );
        }

        $product = $item->get_product();
        if ( ! $product ) {
            wp_send_json( $response );
        }

        $fields = $this->get_apf_fields_for_product( $product );

        // ---- 1. Remove old meta ----
        foreach ( $fields as $field ) {
            $display_key = sanitize_text_field( $field['label'] );
            $item->delete_meta_data( $display_key );
        }

        // ---- 2. Parse AJAX add-on data ----
        $addons = [];
        if ( isset( $_POST['order_item_addons'] ) && is_array( $_POST['order_item_addons'] ) ) {
            foreach ( $_POST['order_item_addons'] as $input ) {
                if ( ! isset( $input['name'], $input['value'] ) ) {
                    continue;
                }
                $name  = $input['name'];
                $value = sanitize_text_field( $input['value'] );
                if ( preg_match( '/^order_item_addons\[(\d+)\]\[([^\]]+)\](\[\])?$/', $name, $m ) ) {
                    $parsed_item_id = $m[1];
                    $field_id       = $m[2];
                    $is_array       = ! empty( $m[3] );
                    if ( $parsed_item_id != $item_id ) {
                        continue;
                    }
                    if ( $is_array ) {
                        $addons[ $field_id ][] = $value;
                    } else {
                        $addons[ $field_id ] = $value;
                    }
                }
            }
        }

        // ---- 3. No add-ons → reset to base price ----
        if ( empty( $addons ) ) {
            $quantity     = $item->get_quantity();
            $base_price   = (float) $product->get_price();
            $new_subtotal = $base_price * $quantity;

            $item->set_subtotal( $new_subtotal );
            $item->set_total( $new_subtotal );

            $tax_data = $item->calculate_taxes();
            $item->set_taxes( $tax_data );
            $item->save();

            $this->restore_coupons_ajax( $order_id );

            ob_start();
            wc_get_template( 'admin/meta-boxes/views/html-order-item-taxes.php', [ 'item' => $item ] );
            $response['html']      = ob_get_clean();
            $response['subtotal']  = html_entity_decode( wc_price( $item->get_subtotal() ), ENT_QUOTES, 'UTF-8' );
            $response['total']     = html_entity_decode( wc_price( $item->get_total() ), ENT_QUOTES, 'UTF-8' );
            $response['success']   = true;
            wp_send_json( $response );
        }

        // ---- 4. Process add-ons ----
        $addon_cost = 0.0;
        foreach ( $fields as $field ) {
            $field_id    = $field['id'];
            $display_key = sanitize_text_field( $field['label'] );

            if ( ! array_key_exists( $field_id, $addons ) ) {
                continue;
            }

            $value = $addons[ $field_id ];

            $formatted = $this->format_addon_display( $value, $field );
            if ( ! empty( $formatted ) ) {
                $item->update_meta_data( $display_key, implode( ', ', $formatted ) );
            }

            $addon_cost += $this->get_addon_cost_from_value( $value, $field );
        }

        // ---- 5. Set totals & recalc taxes ----
        $quantity     = $item->get_quantity();
        $base_price   = (float) $product->get_price();
        $new_subtotal = ( $base_price + $addon_cost ) * $quantity;

        $item->set_subtotal( $new_subtotal );
        $item->set_total( $new_subtotal );

        $tax_data = $item->calculate_taxes();
        $item->set_taxes( $tax_data );
        $item->save();

        $this->restore_coupons_ajax( $order_id );

        ob_start();
        wc_get_template( 'admin/meta-boxes/views/html-order-item-taxes.php', [ 'item' => $item ] );
        $response['html']      = ob_get_clean();
        $response['subtotal']  = html_entity_decode( wc_price( $item->get_subtotal() ), ENT_QUOTES, 'UTF-8' );
        $response['total']     = html_entity_decode( wc_price( $item->get_total() ), ENT_QUOTES, 'UTF-8' );
        $response['success']   = true;
        wp_send_json( $response );
    }

    private function restore_coupons_ajax( $order_id ) {
        $backup_codes = get_post_meta( $order_id, '_temp_coupon_backup', true );
        if ( empty( $backup_codes ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
            $order->remove_item( $coupon_item->get_id() );
        }

        foreach ( $backup_codes as $code ) {
            $result = $order->apply_coupon( $code );
            if ( is_wp_error( $result ) ) {
                error_log( "[COUPON DEBUG] AJAX failed {$code}: " . $result->get_error_message() );
            } else {
                error_log( "[COUPON DEBUG] AJAX applied {$code}" );
            }
        }
        delete_post_meta( $order_id, '_temp_coupon_backup' );
    }

    private function parse_addons_post_data() {
        $addons_post = [];
        if ( isset( $_POST['order_item_addons'] ) && is_array( $_POST['order_item_addons'] ) ) {
            $addons_post = wp_unslash( $_POST['order_item_addons'] );
        } elseif ( isset( $_POST['items'] ) && is_string( $_POST['items'] ) ) {
            parse_str( $_POST['items'], $items_array );
            if ( isset( $items_array['order_item_addons'] ) && is_array( $items_array['order_item_addons'] ) ) {
                $addons_post = wp_unslash( $items_array['order_item_addons'] );
            }
        }
        return $addons_post;
    }

    private function format_addon_display( $value, $field ) {
        $formatted = [];
        $type      = $field['type'];

        if ( $type === 'select' || $type === 'radio' ) {
            if ( $value === '' ) {
                return $formatted;
            }
            foreach ( $field['options']['choices'] as $option ) {
                if ( $option['slug'] === $value ) {
                    $txt = $option['label'];
                    if ( ! empty( $option['pricing_amount'] ) ) {
                        $txt .= ' (+$' . number_format( $option['pricing_amount'], 2 ) . ')';
                    }
                    $formatted[] = $txt;
                    break;
                }
            }
        } elseif ( $type === 'checkbox' ) {
            if ( $value === '1' ) {
                $txt = $field['label'];
                if ( ! empty( $field['pricing']['amount'] ) ) {
                    $txt .= ' (+$' . number_format( $field['pricing']['amount'], 2 ) . ')';
                }
                $formatted[] = $txt;
            }
        } elseif ( $type === 'checkboxes' && is_array( $value ) ) {
            foreach ( $field['options']['choices'] as $option ) {
                if ( in_array( $option['slug'], $value ) ) {
                    $txt = $option['label'];
                    if ( ! empty( $option['pricing_amount'] ) ) {
                        $txt .= ' (+$' . number_format( $option['pricing_amount'], 2 ) . ')';
                    }
                    $formatted[] = $txt;
                }
            }
        }
        return $formatted;
    }

    /* --------------------------------------------------------------------- */
    /*  SHIPPING NAME / COST UPDATE                                        */
    /* --------------------------------------------------------------------- */
    public function update_shipping_name( $order_id, $items ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        foreach ( $order->get_items( 'shipping' ) as $item ) {
            $this->calculate_and_update_shipping_item( $item, $order );
        }
    }

    public function handle_new_order_item( $item_id, $item, $order_id ): void {
        if ( ! ( $item instanceof WC_Order_Item_Shipping ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $this->calculate_and_update_shipping_item( $item, $order );
    }

    private function calculate_and_update_shipping_item( WC_Order_Item_Shipping $item, WC_Order $order ): void {
        $method_id = $item->get_method_id();
        if ( strpos( $method_id, 'flexible_shipping_' ) !== 0 ) {
            return;
        }

        $instance_id = (int) str_replace( 'flexible_shipping_', '', $method_id );
        $settings    = get_option( "woocommerce_flexible_shipping_single_{$instance_id}_settings", [] );
        if ( ! isset( $settings['title'] ) ) {
            return;
        }

        $shipping_method = WC_Shipping_Zones::get_shipping_method( $instance_id );
        if ( ! $shipping_method || $shipping_method->id !== 'flexible_shipping_single' ) {
            return;
        }

        $contents = $this->get_order_contents( $order );
        if ( empty( $contents ) ) {
            $calculated_cost = 0.0;
            $calculated_taxes = [ 'total' => [] ];
            $label = $settings['title'];
        } else {
            $package = [
                'contents'       => $contents,
                'contents_cost'  => array_sum( wp_list_pluck( $contents, 'line_total' ) ),
                'applied_coupons'=> $order->get_coupon_codes(),
                'user'           => [ 'ID' => $order->get_user_id() ],
                'destination'    => [
                    'country'   => $order->get_shipping_country(),
                    'state'     => $order->get_shipping_state(),
                    'postcode'  => $order->get_shipping_postcode(),
                    'city'      => $order->get_shipping_city(),
                    'address'   => $order->get_shipping_address_1(),
                    'address_2' => $order->get_shipping_address_2(),
                ],
            ];
            $shipping_method->calculate_shipping( $package );
            $rates = $shipping_method->rates;

            $calculated_cost  = 0.0;
            $calculated_taxes = [ 'total' => [] ];
            $label            = $settings['title'];

            if ( ! empty( $rates ) ) {
                $rate            = reset( $rates );
                $calculated_cost = (float) $rate->cost;
                $calculated_taxes = [ 'total' => $rate->taxes ];
                $label           = $rate->label;
            }

            $calculated_cost = $this->apply_regional_shipping_markups( $calculated_cost, $package );

            if ( $shipping_method->tax_status === 'taxable' ) {
                $tax_rates        = WC_Tax::get_shipping_tax_rates();
                $calculated_taxes = [ 'total' => WC_Tax::calc_tax( $calculated_cost, $tax_rates, false ) ];
            }
        }

        $item->set_total( $calculated_cost );
        $item->set_taxes( $calculated_taxes );
        $item->set_name( $label );
        $item->save();
    }

    private function get_order_contents( WC_Order $order ): array {
        $contents = [];
        foreach ( $order->get_items() as $order_item_id => $order_item ) {
            if ( $order_item->get_type() !== 'line_item' ) {
                continue;
            }
            $product = $order_item->get_product();
            if ( ! $product || ! $product->needs_shipping() ) {
                continue;
            }
            $contents[ $order_item_id ] = [
                'key'               => $order_item_id,
                'product_id'        => $order_item->get_product_id(),
                'variation_id'      => $order_item->get_variation_id(),
                'variation'         => $order_item->get_meta_data(),
                'quantity'          => $order_item->get_quantity(),
                'line_total'        => $order_item->get_total(),
                'line_tax'          => $order_item->get_total_tax(),
                'line_subtotal'     => $order_item->get_subtotal(),
                'line_subtotal_tax' => $order_item->get_subtotal_tax(),
                'data'              => $product,
            ];
        }
        return $contents;
    }

    /* --------------------------------------------------------------------- */
    /*  ADD-ONS COLUMN UI                                                  */
    /* --------------------------------------------------------------------- */
    public function add_addons_column_header(): void {
        echo '<th class="item_addons sortable" data-sort="string-ins">' . esc_html__( 'Addons', 'gunsafes-core' ) . '</th>';
    }

    public function display_addons_column( $product, $item, $item_id ): void {
        if ( $item->get_type() !== 'line_item' || ! $product ) {
            echo '<td class="item_addons"></td>';
            return;
        }
        $fields = $this->get_apf_fields_for_product( $product );
        if ( empty( $fields ) ) {
            echo '<td class="item_addons">' . esc_html__( 'No addons available', 'gunsafes-core' ) . '</td>';
            return;
        }
        echo '<td class="item_addons">';
        foreach ( $fields as $field ) {
            $field_id        = $field['id'];
            $display_key     = sanitize_text_field( $field['label'] );
            $saved_formatted = $item->get_meta( $display_key, true );
            $saved_value     = $saved_formatted ? $this->parse_formatted_to_value( $saved_formatted, $field ) : '';
            $required        = ! empty( $field['required'] ) ? ' <span class="required">*</span>' : '';
            ?>
            <div class="addon-field">
                <label><?php echo esc_html( $field['label'] ) . $required; ?></label>
                <?php if ( $field['type'] === 'checkbox' || $field['type'] === 'checkboxes' ) : ?>
                    <div class="addon-checkbox-group">
                        <?php
                        $saved_values = ( $field['type'] === 'checkboxes' && ! empty( $saved_value ) )
                            ? explode( ',', $saved_value )
                            : ( $saved_value ? [ $saved_value ] : [] );
                        foreach ( $field['options']['choices'] as $option ) : ?>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox"
                                       name="order_item_addons[<?php echo esc_attr( $item_id ); ?>][<?php echo esc_attr( $field_id ); ?>][]"
                                       value="<?php echo esc_attr( $option['slug'] ); ?>"
                                       <?php checked( in_array( $option['slug'], $saved_values ) ); ?> />
                                <?php echo esc_html( $option['label'] ); ?>
                                <?php if ( ! empty( $option['pricing_amount'] ) ) : ?>
                                    (+<?php echo wc_price( $option['pricing_amount'] ); ?>)
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ( $field['type'] === 'select' ) : ?>
                    <select name="order_item_addons[<?php echo esc_attr( $item_id ); ?>][<?php echo esc_attr( $field_id ); ?>]" <?php echo $field['required'] ? 'required' : ''; ?>>
                        <option value=""><?php esc_html_e( 'Select an option', 'gunsafes-core' ); ?></option>
                        <?php foreach ( $field['options']['choices'] as $option ) : ?>
                            <option value="<?php echo esc_attr( $option['slug'] ); ?>"
                                    <?php selected( $saved_value, $option['slug'] ); ?>>
                                <?php echo esc_html( $option['label'] ); ?>
                                <?php if ( ! empty( $option['pricing_amount'] ) ) : ?>
                                    (+<?php echo wc_price( $option['pricing_amount'] ); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ( $field['type'] === 'radio' ) : ?>
                    <div class="addon-radio-group">
                        <?php foreach ( $field['options']['choices'] as $option ) : ?>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="radio"
                                       name="order_item_addons[<?php echo esc_attr( $item_id ); ?>][<?php echo esc_attr( $field_id ); ?>]"
                                       value="<?php echo esc_attr( $option['slug'] ); ?>"
                                       <?php checked( $saved_value, $option['slug'] ); ?>
                                       <?php echo $field['required'] ? 'required' : ''; ?> />
                                <?php echo esc_html( $option['label'] ); ?>
                                <?php if ( ! empty( $option['pricing_amount'] ) ) : ?>
                                    (+<?php echo wc_price( $option['pricing_amount'] ); ?>)
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

    /* --------------------------------------------------------------------- */
    /*  HELPERS FOR ADD-ON VALUES                                          */
    /* --------------------------------------------------------------------- */
    private function parse_formatted_to_value( $formatted, $field ): string {
        if ( empty( $formatted ) || ! is_string( $formatted ) ) {
            return '';
        }
        $parts       = explode( ', ', $formatted );
        $clean_parts = [];
        foreach ( $parts as $part ) {
            $part = preg_replace( '/ \(([^)]+)\)$/', '', $part );
            $clean_parts[] = trim( $part );
        }
        $type = $field['type'];

        if ( $type === 'select' || $type === 'radio' ) {
            if ( count( $clean_parts ) !== 1 ) {
                return '';
            }
            $clean = $clean_parts[0];
            foreach ( $field['options']['choices'] as $option ) {
                if ( $option['label'] === $clean ) {
                    return $option['slug'];
                }
            }
            return '';
        } elseif ( $type === 'checkbox' ) {
            if ( count( $clean_parts ) !== 1 ) {
                return '';
            }
            return $clean_parts[0] === $field['label'] ? '1' : '';
        } elseif ( $type === 'checkboxes' ) {
            $selected = [];
            foreach ( $clean_parts as $clean ) {
                foreach ( $field['options']['choices'] as $option ) {
                    if ( $option['label'] === $clean ) {
                        $selected[] = $option['slug'];
                        break;
                    }
                }
            }
            return implode( ',', $selected );
        }
        return '';
    }

    private function get_addon_cost_from_value( $value, $field ): float {
        $cost = 0.0;
        $type = $field['type'];

        if ( $type === 'select' || $type === 'radio' ) {
            if ( ! is_string( $value ) ) {
                return $cost;
            }
            foreach ( $field['options']['choices'] as $option ) {
                if ( $option['slug'] === $value ) {
                    $cost += (float) ( $option['pricing_amount'] ?? 0 );
                    break;
                }
            }
        } elseif ( $type === 'checkbox' ) {
            if ( $value === '1' ) {
                $cost += (float) ( $field['pricing']['amount'] ?? 0 );
            }
        } elseif ( $type === 'checkboxes' ) {
            $values = is_array( $value ) ? $value : ( empty( $value ) ? [] : explode( ',', $value ) );
            foreach ( $field['options']['choices'] as $option ) {
                if ( in_array( $option['slug'], $values ) ) {
                    $cost += (float) ( $option['pricing_amount'] ?? 0 );
                }
            }
        }
        return $cost;
    }

    private function get_apf_fields_for_product( WC_Product $product ): array {
        $fields       = [];
        $product_id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
        $variation_id = $product->is_type( 'variation' ) ? $product->get_id() : 0;

        $field_group = $variation_id ? get_post_meta( $variation_id, '_wapf_fieldgroup', true ) : [];
        if ( empty( $field_group ) || ! is_array( $field_group ) ) {
            $field_group = get_post_meta( $product_id, '_wapf_fieldgroup', true );
        }
        if ( empty( $field_group ) || ! is_array( $field_group ) || empty( $field_group['fields'] ) ) {
            return [];
        }

        $applies = false;
        if ( ! empty( $field_group['rule_groups'] ) ) {
            foreach ( $field_group['rule_groups'] as $group ) {
                foreach ( $group['rules'] as $rule ) {
                    if ( $rule['condition'] === 'product' && ! empty( $rule['value'] ) ) {
                        foreach ( $rule['value'] as $val ) {
                            if ( (string) $val['id'] === (string) $product_id || (string) $val['id'] === (string) $variation_id ) {
                                $applies = true;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        if ( ! $applies ) {
            return [];
        }

        foreach ( $field_group['fields'] as $field ) {
            if ( in_array( $field['type'], [ 'checkbox', 'checkboxes', 'select', 'radio' ], true ) ) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /* --------------------------------------------------------------------- */
    /*  QUOTE-RELATED FILTERS                                              */
    /* --------------------------------------------------------------------- */
    public function prevent_stock_reduction_for_quote( $qty, $order, $item ): int {
        return $order->get_status() === 'quote' ? 0 : $qty;
    }

    public function disable_emails_for_quote( $enabled, $order ): bool {
        return $order && $order->get_status() === 'quote' ? false : $enabled;
    }

    /* --------------------------------------------------------------------- */
    /*  ADMIN ASSETS                                                       */
    /* --------------------------------------------------------------------- */
    public function enqueue_assets(): void {
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'shop_order' ) {
            wp_enqueue_style( 'gunsafes-core-order', GUNSAFES_CORE_URL . 'assets/css/admin.css', [], GUNSAFES_CORE_VER );
            wp_enqueue_script( 'gunsafes-core-order', GUNSAFES_CORE_URL . 'assets/js/admin.js', [ 'jquery' ], GUNSAFES_CORE_VER, true );

            // Your existing add-ons JS (unchanged)
            $addons_js = "
            jQuery(document).ready(function($){
                function moveAddons(){
                    $('tr.item').each(function(){
                        var \$row = $(this);
                        var \$addonsTd = \$row.find('.item_addons');
                        var item_id = \$row.find('input.order_item_id').val();
                        if(\$addonsTd.length && \$addonsTd.html().trim() !== '' && \$addonsTd.html().trim() !== '" . esc_js( esc_html__( 'No addons available', 'gunsafes-core' ) ) . "'){
                            var colspan = \$row.children('th, td').length;
                            var \$newRow = $('<tr class=\"addons-row\" data-item-id=\"'+item_id+'\"><td colspan=\"'+colspan+'\"></td></tr>');
                            \$newRow.find('td').append(\$addonsTd.html());
                            \$row.after(\$newRow);
                            \$addonsTd.empty();
                        }
                    });
                }
                moveAddons();
                $('body').on('added_order_item',moveAddons);
                $('body').on('woocommerce_saved_order_items',moveAddons);
                $('body').on('click','.edit-order-item',function(e){
                    var item_id = $(this).data('order_item_id');
                    setTimeout(function(){
                        var \$row = $('tr.item').has('input.order_item_id[value=\"'+item_id+'\"]');
                        var \$addonsRow = \$row.next('.addons-row[data-item-id=\"'+item_id+'\"]');
                        if(\$addonsRow.length){
                            var \$editForm = \$row.find('.edit_item');
                            \$editForm.append('<div class=\"gunsafes-addons-edit\">'+ \$addonsRow.find('td').html() +'</div>');
                        }
                    },100);
                });
                $('body').on('click','.save',function(e){
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
                    $.post(woocommerce_admin_meta_boxes.ajax_url, data, function(response){
                        if(response.success){
                            \$row.find('.line_subtotal .view').html(response.subtotal);
                            \$row.find('.line_total .view').html(response.total);
                            \$row.find('.line_tax .view').html(response.html);
                            $('.button.calc_totals').trigger('click');
                        }
                    });
                });
            });
            ";
            wp_add_inline_script( 'gunsafes-core-order', $addons_js );

            // Your CSS (unchanged)
            $css = '
            th.item_addons, td.item_addons { display:none; }
            .addons-row td { padding:10px; background:#f8f8f8; border-top:1px solid #ddd; }
            .addon-field { margin-bottom:8px; }
            .addon-field label { display:block; font-weight:bold; }
            .addon-radio-group, .addon-checkbox-group { margin-top:5px; }
            .required { color:red; }
            .gunsafes-addons-edit { margin-top:10px; padding:10px; background:#f8f8f8; border:1px solid #ddd; }
            ';
            wp_add_inline_style( 'gunsafes-core-order', $css );
        }
    }
}

// Initialize the class
new Admin_Order();