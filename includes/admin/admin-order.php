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
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_tax_exempt_fields' ], 90, 2 );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'preserve_coupons_before_save' ], 5, 2 );
        add_action( 'woocommerce_saved_order_items', [ $this, 'preserve_coupons_before_save' ], 5, 2 );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_order_item_addons' ], 100, 2 );
        add_action( 'woocommerce_saved_order_items', [ $this, 'save_order_item_addons' ], 20, 2 );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'force_recalculate_after_addons' ], 101, 2 );
        add_action( 'wp_ajax_save_order_item_addons', [ $this, 'ajax_save_order_item_addons' ] );
        add_action( 'wp_ajax_gscore_get_tax_exempt', [ $this, 'ajax_get_tax_exempt' ] );
        add_filter( 'woocommerce_shipping_methods', [ $this, 'add_flexible_shipping_instances' ] );
        add_action( 'woocommerce_saved_order_items', [ $this, 'update_shipping_name' ], 10, 2 );
        add_action( 'woocommerce_new_order_item', [ $this, 'handle_new_order_item' ], 10, 3 );
        add_action( 'woocommerce_admin_order_item_headers', [ $this, 'add_addons_column_header' ] );
        add_action( 'woocommerce_admin_order_item_values', [ $this, 'display_addons_column' ], 10, 3 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'woocommerce_order_item_quantity', [ $this, 'prevent_stock_reduction_for_quote' ], 10, 3 );
        add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_manual_override_meta' ] );
        add_action( 'woocommerce_order_before_calculate_totals', [ $this, 'apply_manual_override_before_order_totals' ], 10, 1 );
        add_filter( 'woocommerce_email_enabled_customer_completed_order', [ $this, 'disable_emails_for_quote' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_new_order', [ $this, 'disable_emails_for_quote' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_customer_processing_order', [ $this, 'disable_emails_for_quote' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_customer_quote', [ $this, 'disable_emails_for_quote' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_admin_quote', [ $this, 'disable_emails_for_quote' ], 10, 2 );
        add_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'custom_single_add_to_cart_text' ], 20, 2 );
        add_filter( 'woocommerce_product_add_to_cart_text',        [ $this, 'custom_loop_add_to_cart_text' ],     20, 2 );
        add_action( 'woocommerce_before_save_order_items', [ $this, 'detect_manual_shipping_override' ], 10, 2 );
        add_action( 'woocommerce_before_save_order_items', [ $this, 'detect_manual_line_item_override' ], 10, 2 );
    }

    /* --------------------------------------------------------------------- */
    /*  COUPON BACKUP / RESTORE                                            */
    /* --------------------------------------------------------------------- */
    public function preserve_coupons_before_save( $post_id, $post = null ): void {
        if ( $this->is_coupon_request() ) {
            return;
        }
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

    /**
     * Detect manual shipping cost edits using raw $_POST (since $items['shipping'] is often empty in admin AJAX saves)
     */
    public function detect_manual_shipping_override( $order_id, $items ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $posted_shipping_costs = $this->get_posted_shipping_costs();   // This is the key field WooCommerce uses for shipping edits

        $shipping_items = $order->get_items( 'shipping' );
        if ( empty( $shipping_items ) ) {
            return;
        }

        $shipping_item_ids = array_keys( $shipping_items );
        $manual_flags = $this->get_posted_manual_shipping_override_flags();
        $fallback_costs = [];
        foreach ( $posted_shipping_costs as $posted_key => $posted_value ) {
            if ( is_string( $posted_key ) && ! ctype_digit( $posted_key ) ) {
                $fallback_costs[] = $posted_value;
            } elseif ( is_numeric( $posted_key ) && ! in_array( (int) $posted_key, $shipping_item_ids, true ) ) {
                $fallback_costs[] = $posted_value;
            }
        }

        $posted_methods = $this->get_posted_shipping_methods();
        $fallback_methods = [];
        foreach ( $posted_methods as $posted_key => $posted_value ) {
            if ( is_string( $posted_key ) && ! ctype_digit( $posted_key ) ) {
                $fallback_methods[] = $posted_value;
            } elseif ( is_numeric( $posted_key ) && ! in_array( (int) $posted_key, $shipping_item_ids, true ) ) {
                $fallback_methods[] = $posted_value;
            }
        }

        foreach ( $shipping_items as $item_id => $item ) {
            $method_id = $item->get_method_id();
            $posted_method_id = $posted_methods[ $item_id ] ?? null;
            if ( $posted_method_id === null && count( $fallback_methods ) === 1 && count( $shipping_items ) === 1 ) {
                $posted_method_id = $fallback_methods[0];
            }
            $effective_method_id = $method_id ?: $posted_method_id;
            if ( $effective_method_id && $effective_method_id !== 'other' ) {
                $item->set_method_id( $effective_method_id );
            }
            if ( ! $effective_method_id || strpos( $effective_method_id, 'flexible_shipping_' ) !== 0 ) {
                continue;
            }

            $current_cost = wc_format_decimal( $item->get_total(), '' );

            $posted_cost_raw = $posted_shipping_costs[ $item_id ] ?? null;
            $manual_flag_for_item = ! empty( $manual_flags[ $item_id ] );
            if ( ! $manual_flag_for_item && count( $manual_flags ) === 1 && count( $shipping_items ) === 1 ) {
                $manual_flag_for_item = true;
            }
            if ( $posted_cost_raw === null && count( $fallback_costs ) === 1 && count( $shipping_items ) === 1 ) {
                $posted_cost_raw = $fallback_costs[0];
                if ( ! $manual_flag_for_item && count( $manual_flags ) === 1 ) {
                    $manual_flag_for_item = true;
                }
            }
            if ( $posted_cost_raw === null ) {
                continue;
            }
            $posted_cost = wc_format_decimal( $posted_cost_raw, '' );

            $tolerance = 0.01;

            if ( $manual_flag_for_item || abs( (float) $posted_cost - (float) $current_cost ) > $tolerance ) {
                $item->update_meta_data( '_manual_shipping_override', $posted_cost );
                $item->set_total( $posted_cost );
                if ( wc_tax_enabled() ) {
                    $tax_rates = WC_Tax::get_shipping_tax_rates();
                    $taxes = [ 'total' => WC_Tax::calc_tax( $posted_cost, $tax_rates, false ) ];
                    $item->set_taxes( $taxes );
                }
                $item->save();  // Save meta immediately
            }
        }
    }

    /**
     * Detect manual line item price edits and persist overrides for later recalculation.
     */
    public function detect_manual_line_item_override( $order_id, $items ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $posted_totals    = $_POST['line_total'] ?? [];
        $posted_subtotals = $_POST['line_subtotal'] ?? [];
        $manual_flags     = $this->get_posted_manual_override_flags();
        $addons_post      = $this->parse_addons_post_data();

        if ( empty( $posted_totals ) && empty( $posted_subtotals ) && isset( $_POST['items'] ) && is_string( $_POST['items'] ) ) {
            parse_str( $_POST['items'], $items_array );
            $posted_totals    = $items_array['line_total'] ?? [];
            $posted_subtotals = $items_array['line_subtotal'] ?? [];
        }

        if ( empty( $posted_totals ) && empty( $posted_subtotals ) ) {
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

            if ( ! isset( $posted_totals[ $item_id ] ) && ! isset( $posted_subtotals[ $item_id ] ) ) {
                continue;
            }

            $current_total    = wc_format_decimal( $item->get_total(), '' );
            $current_subtotal = wc_format_decimal( $item->get_subtotal(), '' );

            $posted_total_raw    = $posted_totals[ $item_id ] ?? $current_total;
            $posted_subtotal_raw = $posted_subtotals[ $item_id ] ?? $posted_total_raw;

            $posted_total    = wc_format_decimal( $posted_total_raw, '' );
            $posted_subtotal = wc_format_decimal( $posted_subtotal_raw, '' );

            $manual_flag = ! empty( $manual_flags[ $item_id ] );
            if ( ! $manual_flag && $posted_total === $current_total && $posted_subtotal === $current_subtotal ) {
                continue;
            }
            if ( ! $manual_flag && $posted_subtotal === $current_subtotal && $posted_total !== $posted_subtotal ) {
                $posted_subtotal = $posted_total;
            }
            $expected    = $this->get_expected_line_totals_from_posted_addons( $item, $product, $addons_post );
            if ( ! $expected ) {
                $expected = $this->get_expected_line_totals_from_saved_addons( $item, $product );
            }
            $tolerance   = 0.01;

            $fallback_override = false;
            if ( ! $manual_flag && $expected ) {
                if ( abs( (float) $posted_total - (float) $expected['total'] ) > $tolerance
                    || abs( (float) $posted_subtotal - (float) $expected['subtotal'] ) > $tolerance ) {
                    $fallback_override = true;
                }
            } elseif ( ! $manual_flag && ! $expected ) {
                if ( $posted_total !== $current_total || $posted_subtotal !== $current_subtotal ) {
                    $fallback_override = true;
                }
            }

            if ( $manual_flag || $fallback_override ) {
                $this->store_manual_line_item_override( $item, [
                    'total'    => $posted_total,
                    'subtotal' => $posted_subtotal,
                ] );
            } elseif ( $expected
                && abs( (float) $posted_total - (float) $expected['total'] ) <= $tolerance
                && abs( (float) $posted_subtotal - (float) $expected['subtotal'] ) <= $tolerance ) {
                $this->set_manual_line_item_override_enabled( $item, false );
                $item->delete_meta_data( '_manual_line_total_override' );
                $item->delete_meta_data( '_manual_line_subtotal_override' );
                $item->save();
            }
        }
    }

    /* --------------------------------------------------------------------- */
    /*  SHIPPING MARKUP HELPERS                                            */
    /* --------------------------------------------------------------------- */
    private function apply_regional_shipping_markups( $cost, $package ): float {
        $zip_text   = get_option( 'gscore_regional_markups_zip', '' );
        $state_text = get_option( 'gscore_regional_markups_state', '' );

        $zip_markups   = $zip_text === '' ? [] : $this->text_to_array( $zip_text, $this->get_default_zip() );
        $state_markups = $state_text === '' ? [] : $this->text_to_array( $state_text, $this->get_default_state() );

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
            'role'   => 'sales_rep',
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
        $user_id = $order->get_user_id();
        $order_exempt = $order->get_meta( '_gscore_tax_exempt', true );
        $order_exempt_number = $order->get_meta( '_gscore_tax_exempt_number', true );
        if ( $order_exempt === '' && $user_id ) {
            $order_exempt = get_user_meta( $user_id, '_gscore_tax_exempt', true );
        }
        if ( $order_exempt_number === '' && $user_id ) {
            $order_exempt_number = get_user_meta( $user_id, '_gscore_tax_exempt_number', true );
        }
        $is_exempt = $order_exempt === 'yes';
        ?>
        <div class="form-field form-field-wide">
            <label for="_gscore_tax_exempt">
                <input type="checkbox" name="_gscore_tax_exempt" id="_gscore_tax_exempt" value="yes" <?php checked( $is_exempt ); ?> />
                <?php esc_html_e( 'Tax Exempt', 'gunsafes-core' ); ?>
            </label>
        </div>
        <div class="form-field form-field-wide" id="gscore_tax_exempt_number_row" style="<?php echo $is_exempt ? '' : 'display:none;'; ?>">
            <label for="_gscore_tax_exempt_number"><?php esc_html_e( 'Tax Exempt Number', 'gunsafes-core' ); ?></label>
            <input type="text" name="_gscore_tax_exempt_number" id="_gscore_tax_exempt_number" class="regular-text" value="<?php echo esc_attr( $order_exempt_number ); ?>" />
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

    public function save_tax_exempt_fields( $post_id, $post ): void {
        if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $order = wc_get_order( $post_id );
        if ( ! $order ) {
            return;
        }

        error_log( sprintf( 'Gunsafes Core: save_tax_exempt_fields order #%d start', $post_id ) );

        $prev_exempt = $order->get_meta( '_gscore_tax_exempt', true );
        $prev_number = $order->get_meta( '_gscore_tax_exempt_number', true );
        $user_id     = $order->get_user_id();
        $user_exempt = $user_id ? get_user_meta( $user_id, '_gscore_tax_exempt', true ) : '';
        $user_number = $user_id ? get_user_meta( $user_id, '_gscore_tax_exempt_number', true ) : '';

        $new_exempt = isset( $_POST['_gscore_tax_exempt'] ) ? 'yes' : 'no';
        $new_number = isset( $_POST['_gscore_tax_exempt_number'] )
            ? sanitize_text_field( wp_unslash( $_POST['_gscore_tax_exempt_number'] ) )
            : '';

        if (
            $prev_exempt === ''
            && $prev_number === ''
            && $new_exempt === 'no'
            && $new_number === ''
            && ( $user_exempt !== '' || $user_number !== '' )
        ) {
            $new_exempt = $user_exempt !== '' ? $user_exempt : 'no';
            $new_number = $user_number;
        }

        if ( $new_exempt !== $prev_exempt ) {
            $order->update_meta_data( '_gscore_tax_exempt', $new_exempt );
        }
        if ( $new_number !== $prev_number ) {
            $order->update_meta_data( '_gscore_tax_exempt_number', $new_number );
        }

        $order->update_meta_data( 'is_vat_exempt', $new_exempt );

        if ( $user_id ) {
            update_user_meta( $user_id, '_gscore_tax_exempt', $new_exempt );
            update_user_meta( $user_id, '_gscore_tax_exempt_number', $new_number );
        }

        error_log(
            sprintf(
                'Gunsafes Core: save_tax_exempt_fields order #%d user_id %s new_exempt %s new_number %s prev_exempt %s prev_number %s user_exempt %s user_number %s',
                $post_id,
                $user_id ? (string) $user_id : 'none',
                $new_exempt,
                $new_number === '' ? '(empty)' : $new_number,
                $prev_exempt === '' ? '(empty)' : $prev_exempt,
                $prev_number === '' ? '(empty)' : $prev_number,
                $user_exempt === '' ? '(empty)' : $user_exempt,
                $user_number === '' ? '(empty)' : $user_number
            )
        );

        $order->save();

        if ( $new_exempt !== $prev_exempt ) {
            $order->calculate_taxes();
            $order->calculate_totals( false );
        }
    }

    public function ajax_get_tax_exempt(): void {
        if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        check_ajax_referer( 'gscore_tax_exempt', 'nonce' );

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            wp_send_json_success( [ 'exempt' => 'no', 'number' => '' ] );
        }

        $exempt = get_user_meta( $user_id, '_gscore_tax_exempt', true );
        $number = get_user_meta( $user_id, '_gscore_tax_exempt_number', true );

        wp_send_json_success( [
            'exempt' => $exempt === 'yes' ? 'yes' : 'no',
            'number' => (string) $number,
        ] );
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
            $manual_override = $this->is_manual_line_item_override_enabled( $item )
                ? $this->get_manual_line_item_override( $item )
                : null;

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
                if ( $manual_override ) {
                    $this->apply_manual_line_item_override( $item, $manual_override );
                    continue;
                }
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

            if ( $manual_override ) {
                $this->apply_manual_line_item_override( $item, $manual_override );
                continue;
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
        if ( ! empty( $backup_codes ) && ! $this->is_coupon_request() ) {
            foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
                $order->remove_item( $coupon_item->get_id() );
            }

            foreach ( $backup_codes as $code ) {
                $result = $order->apply_coupon( $code );
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
        $manual_override = $this->is_manual_line_item_override_enabled( $item )
            ? $this->get_manual_line_item_override( $item )
            : null;

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
            if ( $manual_override ) {
                $this->apply_manual_line_item_override( $item, $manual_override );
            } else {
                $quantity     = $item->get_quantity();
                $base_price   = (float) $product->get_price();
                $new_subtotal = $base_price * $quantity;

                $item->set_subtotal( $new_subtotal );
                $item->set_total( $new_subtotal );

                $tax_data = $item->calculate_taxes();
                $item->set_taxes( $tax_data );
                $item->save();
            }

            $this->restore_coupons_ajax( $order_id );

            ob_start();
            wc_get_template( 'admin/meta-boxes/views/html-order-item-taxes.php', [ 'item' => $item ] );
            $response['html']      = ob_get_clean();
            $response['subtotal']  = html_entity_decode( wc_price( $item->get_subtotal() ), ENT_QUOTES, 'UTF-8' );
            $response['total']     = html_entity_decode( wc_price( $item->get_total() ), ENT_QUOTES, 'UTF-8' );
            $response['subtotal_raw'] = wc_format_decimal( $item->get_subtotal(), '' );
            $response['total_raw']    = wc_format_decimal( $item->get_total(), '' );
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

        if ( $manual_override ) {
            $this->apply_manual_line_item_override( $item, $manual_override );
        } else {
            // ---- 5. Set totals & recalc taxes ----
            $quantity     = $item->get_quantity();
            $base_price   = (float) $product->get_price();
            $new_subtotal = ( $base_price + $addon_cost ) * $quantity;

            $item->set_subtotal( $new_subtotal );
            $item->set_total( $new_subtotal );

            $tax_data = $item->calculate_taxes();
            $item->set_taxes( $tax_data );
            $item->save();
        }

        $this->restore_coupons_ajax( $order_id );

        ob_start();
        wc_get_template( 'admin/meta-boxes/views/html-order-item-taxes.php', [ 'item' => $item ] );
        $response['html']      = ob_get_clean();
        $response['subtotal']  = html_entity_decode( wc_price( $item->get_subtotal() ), ENT_QUOTES, 'UTF-8' );
        $response['total']     = html_entity_decode( wc_price( $item->get_total() ), ENT_QUOTES, 'UTF-8' );
        $response['subtotal_raw'] = wc_format_decimal( $item->get_subtotal(), '' );
        $response['total_raw']    = wc_format_decimal( $item->get_total(), '' );
        $response['success']   = true;
        wp_send_json( $response );
    }

    private function get_manual_line_item_override( WC_Order_Item $item ): ?array {
        $manual_total    = $item->get_meta( '_manual_line_total_override', true );
        $manual_subtotal = $item->get_meta( '_manual_line_subtotal_override', true );

        if ( $manual_total === '' ) {
            return null;
        }

        if ( $manual_subtotal === '' ) {
            $manual_subtotal = $manual_total;
        }

        return [
            'total'    => (float) $manual_total,
            'subtotal' => (float) $manual_subtotal,
        ];
    }

    private function get_posted_line_item_override( WC_Order_Item $item ): ?array {
        $item_id = $item->get_id();
        $manual_flags = $this->get_posted_manual_override_flags();

        $posted_totals    = $_POST['line_total'] ?? [];
        $posted_subtotals = $_POST['line_subtotal'] ?? [];

        if ( empty( $posted_totals ) && empty( $posted_subtotals ) && isset( $_POST['items'] ) && is_string( $_POST['items'] ) ) {
            parse_str( $_POST['items'], $items_array );
            $posted_totals    = $items_array['line_total'] ?? [];
            $posted_subtotals = $items_array['line_subtotal'] ?? [];
        }

        if ( ! isset( $posted_totals[ $item_id ] ) && ! isset( $posted_subtotals[ $item_id ] ) ) {
            return null;
        }

        $posted_total_raw    = $posted_totals[ $item_id ] ?? '';
        $posted_subtotal_raw = $posted_subtotals[ $item_id ] ?? $posted_total_raw;

        if ( $posted_total_raw === '' && $posted_subtotal_raw === '' ) {
            return null;
        }

        $posted_total    = (float) wc_format_decimal( $posted_total_raw, '' );
        $posted_subtotal = (float) wc_format_decimal( $posted_subtotal_raw, '' );
        $current_total   = (float) wc_format_decimal( $item->get_total(), '' );
        $current_subtotal = (float) wc_format_decimal( $item->get_subtotal(), '' );

        if ( $posted_total === $current_total && $posted_subtotal === $current_subtotal ) {
            return null;
        }

        $manual_flag = ! empty( $manual_flags[ $item_id ] );
        if ( ! $manual_flag && $posted_subtotal === $current_subtotal && $posted_total !== $posted_subtotal ) {
            $posted_subtotal = $posted_total;
        }
        if ( $manual_flag ) {
            return [
                'total'    => $posted_total,
                'subtotal' => $posted_subtotal,
            ];
        }

        $product = $item->get_product();
        if ( ! $product ) {
            return null;
        }

        $addons_post = $this->parse_addons_post_data();
        $expected    = $this->get_expected_line_totals_from_posted_addons( $item, $product, $addons_post );
        if ( ! $expected ) {
            $expected = $this->get_expected_line_totals_from_saved_addons( $item, $product );
        }
        if ( ! $expected ) {
            if ( $posted_total !== $current_total || $posted_subtotal !== $current_subtotal ) {
                return [
                    'total'    => $posted_total,
                    'subtotal' => $posted_subtotal,
                ];
            }
            return null;
        }

        $tolerance = 0.01;
        if ( abs( $posted_total - (float) $expected['total'] ) <= $tolerance
            && abs( $posted_subtotal - (float) $expected['subtotal'] ) <= $tolerance ) {
            return null;
        }

        return [
            'total'    => $posted_total,
            'subtotal' => $posted_subtotal,
        ];
    }

    private function store_manual_line_item_override( WC_Order_Item $item, array $override ): void {
        $this->set_manual_line_item_override_enabled( $item, true );
        $item->update_meta_data( '_manual_line_total_override', $override['total'] );
        $item->update_meta_data( '_manual_line_subtotal_override', $override['subtotal'] );
        $item->save();
    }

    private function apply_manual_line_item_override( WC_Order_Item $item, array $override ): void {
        $item->set_subtotal( $override['subtotal'] );
        $item->set_total( $override['total'] );

        $tax_data = $item->calculate_taxes();
        $item->set_taxes( $tax_data );
        $item->save();
    }

    public function apply_manual_override_before_order_totals( $order ): void {
        if ( ! $order || ! ( $order instanceof WC_Order ) ) {
            return;
        }
        foreach ( $order->get_items() as $item ) {
            if ( $item->get_type() !== 'line_item' ) {
                continue;
            }
            if ( ! $this->is_manual_line_item_override_enabled( $item ) ) {
                continue;
            }
            $manual_override = $this->get_manual_line_item_override( $item );
            if ( ! $manual_override ) {
                continue;
            }
            $item->set_subtotal( $manual_override['subtotal'] );
            $item->set_total( $manual_override['total'] );
        }
    }

    private function is_manual_line_item_override_enabled( WC_Order_Item $item ): bool {
        return $item->get_meta( '_manual_line_item_override_enabled', true ) === 'yes';
    }

    private function set_manual_line_item_override_enabled( WC_Order_Item $item, bool $enabled ): void {
        if ( $enabled ) {
            $item->update_meta_data( '_manual_line_item_override_enabled', 'yes' );
        } else {
            $item->delete_meta_data( '_manual_line_item_override_enabled' );
        }
    }

    private function get_posted_manual_override_flags(): array {
        $manual_flags = $_POST['manual_line_item_override'] ?? [];
        if ( empty( $manual_flags ) && isset( $_POST['items'] ) && is_string( $_POST['items'] ) ) {
            parse_str( $_POST['items'], $items_array );
            $manual_flags = $items_array['manual_line_item_override'] ?? [];
        }
        return is_array( $manual_flags ) ? $manual_flags : [];
    }

    private function restore_coupons_ajax( $order_id ) {
        if ( $this->is_coupon_request() ) {
            return;
        }
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
        }
        delete_post_meta( $order_id, '_temp_coupon_backup' );
    }

    private function is_coupon_request(): bool {
        $action = $_POST['action'] ?? '';
        return in_array( $action, [ 'woocommerce_add_coupon_discount', 'woocommerce_remove_order_coupon' ], true );
    }

    private function get_expected_line_totals_from_posted_addons( WC_Order_Item $item, WC_Product $product, array $addons_post ): ?array {
        $item_id = $item->get_id();
        if ( ! isset( $addons_post[ $item_id ] ) ) {
            return null;
        }
        $fields = $this->get_apf_fields_for_product( $product );
        if ( empty( $fields ) ) {
            return null;
        }
        $addons     = $addons_post[ $item_id ];
        $addon_cost = 0.0;
        foreach ( $fields as $field ) {
            $field_id = $field['id'];
            if ( ! array_key_exists( $field_id, $addons ) ) {
                continue;
            }
            $value = is_array( $addons[ $field_id ] )
                ? array_map( 'sanitize_text_field', $addons[ $field_id ] )
                : sanitize_text_field( $addons[ $field_id ] );
            $addon_cost += $this->get_addon_cost_from_value( $value, $field );
        }
        $quantity     = $item->get_quantity();
        $base_price   = (float) $product->get_price();
        $new_subtotal = ( $base_price + $addon_cost ) * $quantity;

        return [
            'total'    => $new_subtotal,
            'subtotal' => $new_subtotal,
        ];
    }

    private function get_expected_line_totals_from_saved_addons( WC_Order_Item $item, WC_Product $product ): ?array {
        $fields = $this->get_apf_fields_for_product( $product );
        if ( empty( $fields ) ) {
            return null;
        }
        $addon_cost = 0.0;
        foreach ( $fields as $field ) {
            $display_key = sanitize_text_field( $field['label'] );
            $saved_formatted = $item->get_meta( $display_key, true );
            if ( ! $saved_formatted ) {
                continue;
            }
            $parsed_value = $this->parse_formatted_to_value( $saved_formatted, $field );
            if ( $field['type'] === 'checkboxes' && ! empty( $parsed_value ) ) {
                $parsed_value = explode( ',', $parsed_value );
            } else {
                $parsed_value = (array) $parsed_value;
            }
            $addon_cost += $this->get_addon_cost_from_value( $parsed_value, $field );
        }
        $quantity     = $item->get_quantity();
        $base_price   = (float) $product->get_price();
        $new_subtotal = ( $base_price + $addon_cost ) * $quantity;

        return [
            'total'    => $new_subtotal,
            'subtotal' => $new_subtotal,
        ];
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
        if ( ! $method_id ) {
            $posted_methods = $this->get_posted_shipping_methods();
            if ( ! empty( $posted_methods ) ) {
                $shipping_item_ids = array_keys( $order->get_items( 'shipping' ) );
                $fallback_methods = [];
                foreach ( $posted_methods as $posted_key => $posted_value ) {
                    if ( is_string( $posted_key ) && ! ctype_digit( $posted_key ) ) {
                        $fallback_methods[] = $posted_value;
                    } elseif ( is_numeric( $posted_key ) && ! in_array( (int) $posted_key, $shipping_item_ids, true ) ) {
                        $fallback_methods[] = $posted_value;
                    }
                }
                $posted_method_id = $posted_methods[ $item->get_id() ] ?? null;
                if ( $posted_method_id === null && count( $fallback_methods ) === 1 && count( $shipping_item_ids ) === 1 ) {
                    $posted_method_id = $fallback_methods[0];
                }
                if ( $posted_method_id && $posted_method_id !== 'other' ) {
                    $item->set_method_id( $posted_method_id );
                }
            }
        }

        $calculated = $this->get_calculated_shipping_data( $item, $order );
        if ( ! $calculated ) {
            return;
        }

        $calculated_cost  = (float) $calculated['cost'];
        $calculated_taxes = $calculated['taxes'];
        $label            = $calculated['label'];
        $shipping_method  = $calculated['method'];

        $tolerance       = 0.01;
        $current_total   = (float) $item->get_total();
        $manual_override = $item->get_meta( '_manual_shipping_override', true );

        $posted_shipping_costs = $this->get_posted_shipping_costs();
        if ( ! empty( $posted_shipping_costs ) ) {
            $shipping_item_ids = array_keys( $order->get_items( 'shipping' ) );
            $manual_flags = $this->get_posted_manual_shipping_override_flags();
            $fallback_costs = [];
            foreach ( $posted_shipping_costs as $posted_key => $posted_value ) {
                if ( is_string( $posted_key ) && ! ctype_digit( $posted_key ) ) {
                    $fallback_costs[] = $posted_value;
                } elseif ( is_numeric( $posted_key ) && ! in_array( (int) $posted_key, $shipping_item_ids, true ) ) {
                    $fallback_costs[] = $posted_value;
                }
            }

            $posted_cost_raw = $posted_shipping_costs[ $item->get_id() ] ?? null;
            $manual_flag_for_item = ! empty( $manual_flags[ $item->get_id() ] );
            if ( ! $manual_flag_for_item && count( $manual_flags ) === 1 && count( $shipping_item_ids ) === 1 ) {
                $manual_flag_for_item = true;
            }
            if ( $posted_cost_raw === null && count( $fallback_costs ) === 1 && count( $shipping_item_ids ) === 1 ) {
                $posted_cost_raw = $fallback_costs[0];
                if ( ! $manual_flag_for_item && count( $manual_flags ) === 1 ) {
                    $manual_flag_for_item = true;
                }
            }

            if ( $posted_cost_raw !== null ) {
                $posted_cost = (float) wc_format_decimal( $posted_cost_raw, '' );
                if ( $manual_flag_for_item || abs( $posted_cost - $current_total ) > $tolerance ) {
                    $item->update_meta_data( '_manual_shipping_override', $posted_cost );
                    $item->set_total( $posted_cost );
                    if ( wc_tax_enabled() && $shipping_method->tax_status === 'taxable' ) {
                        $tax_rates = WC_Tax::get_shipping_tax_rates();
                        $calculated_taxes = [ 'total' => WC_Tax::calc_tax( $posted_cost, $tax_rates, false ) ];
                    }
                    $item->set_taxes( $calculated_taxes );
                    if ( $item->get_name() !== $label ) {
                        $item->set_name( $label );
                    }
                    $item->save();
                    return;
                }
                if ( $manual_override === '' ) {
                    $item->delete_meta_data( '_manual_shipping_override' );
                }
            }
        }

        // If a manual override exists, keep cost but still update the label.
        if ( $manual_override !== '' ) {
            $manual_override = (float) wc_format_decimal( $manual_override, '' );
            if ( abs( $manual_override - $calculated_cost ) <= $tolerance ) {
                $item->delete_meta_data( '_manual_shipping_override' );
            } else {
                $item->set_total( $manual_override );
                if ( wc_tax_enabled() && $shipping_method->tax_status === 'taxable' ) {
                    $tax_rates = WC_Tax::get_shipping_tax_rates();
                    $calculated_taxes = [ 'total' => WC_Tax::calc_tax( $manual_override, $tax_rates, false ) ];
                }
                $item->set_taxes( $calculated_taxes );
                if ( $item->get_name() !== $label ) {
                    $item->set_name( $label );
                }
                $item->save();
                return;
            }
        }

        // Safe to apply calculated values
        $item->set_total( $calculated_cost );
        $item->set_taxes( $calculated_taxes );
        $item->set_name( $label );
        $item->save();
    }

    private function get_calculated_shipping_data( WC_Order_Item_Shipping $item, WC_Order $order ): ?array {
        $method_id = $item->get_method_id();
        if ( strpos( $method_id, 'flexible_shipping_' ) !== 0 ) {
            return null;
        }

        $instance_id = (int) str_replace( 'flexible_shipping_', '', $method_id );
        $settings    = get_option( "woocommerce_flexible_shipping_single_{$instance_id}_settings", [] );
        if ( ! isset( $settings['title'] ) ) {
            return null;
        }

        $shipping_method = WC_Shipping_Zones::get_shipping_method( $instance_id );
        if ( ! $shipping_method || $shipping_method->id !== 'flexible_shipping_single' ) {
            return null;
        }

        $contents = $this->get_order_contents( $order );
        $calculated_cost = 0.0;
        $rates = [];

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
            $shipping_method->calculate_shipping( $package );
            $rates = $shipping_method->rates ?? [];

            if ( ! empty( $rates ) ) {
                $rate = reset( $rates );
                $calculated_cost = (float) $rate->cost;
            }

            $calculated_cost = $this->apply_regional_shipping_markups( $calculated_cost, $package );
        }

        $label = $settings['title'];
        $calculated_taxes = [ 'total' => [] ];

        if ( ! empty( $rates ) ) {
            $rate = reset( $rates );
            $label = $rate->label;
            $calculated_taxes = [ 'total' => $rate->taxes ];
        }

        if ( wc_tax_enabled() && $shipping_method->tax_status === 'taxable' ) {
            $tax_rates = WC_Tax::get_shipping_tax_rates();
            $calculated_taxes = [ 'total' => WC_Tax::calc_tax( $calculated_cost, $tax_rates, false ) ];
        }

        return [
            'cost'   => $calculated_cost,
            'taxes'  => $calculated_taxes,
            'label'  => $label,
            'method' => $shipping_method,
        ];
    }

    private function get_posted_shipping_costs(): array {
        $posted_shipping_costs = $_POST['shipping_cost'] ?? [];
        if ( empty( $posted_shipping_costs ) && isset( $_POST['items'] ) && is_string( $_POST['items'] ) ) {
            parse_str( $_POST['items'], $items_array );
            $posted_shipping_costs = $items_array['shipping_cost'] ?? [];
        }
        return is_array( $posted_shipping_costs ) ? $posted_shipping_costs : [];
    }

    private function get_posted_shipping_methods(): array {
        $posted_methods = $_POST['shipping_method'] ?? [];
        if ( empty( $posted_methods ) && isset( $_POST['items'] ) && is_string( $_POST['items'] ) ) {
            parse_str( $_POST['items'], $items_array );
            $posted_methods = $items_array['shipping_method'] ?? [];
        }
        return is_array( $posted_methods ) ? $posted_methods : [];
    }

    private function get_posted_manual_shipping_override_flags(): array {
        $manual_flags = $_POST['manual_shipping_override'] ?? [];
        if ( empty( $manual_flags ) && isset( $_POST['items'] ) && is_string( $_POST['items'] ) ) {
            parse_str( $_POST['items'], $items_array );
            $manual_flags = $items_array['manual_shipping_override'] ?? [];
        }
        return is_array( $manual_flags ) ? $manual_flags : [];
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

    /**
     * Single product page – Add to Cart button text
     */
    public function custom_single_add_to_cart_text( $text, $product ) {

        // Change these to whatever you want
        $normal_text       = 'Buy Now';          // simple products
        $customizable_text = 'Buy Now';      // APF or variable products

        // 1. APF / WAPF products (reuses your existing method)
        if ( ! empty( $this->get_apf_fields_for_product( $product ) ) ) {
            return $customizable_text;
        }

        // 2. Variable products (optional – remove this block if you want them to say "Add to Cart")
        if ( $product->is_type( 'variable' ) ) {
            return $customizable_text;
        }

        // 3. Everything else
        return $normal_text;
    }

    /**
     * Shop / archive / grid – button text
     */
    public function custom_loop_add_to_cart_text( $text, $product ) {

        // Change these to whatever you want
        $normal_text     = 'Buy Now';           // simple
        $variable_text   = 'Buy Now';        // variable
        $apf_text        = 'Buy Now';     // APF/WAPF products

        // Highest priority: APF products
        if ( ! empty( $this->get_apf_fields_for_product( $product ) ) ) {
            return $apf_text;
        }

        // Variable products
        if ( $product->is_type( 'variable' ) ) {
            return $variable_text;
        }

        // Simple products
        return $normal_text;
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

    public function hide_manual_override_meta( array $hidden ): array {
        $hidden[] = '_manual_line_item_override_enabled';
        $hidden[] = '_manual_line_total_override';
        $hidden[] = '_manual_line_subtotal_override';
        $hidden[] = '_manual_shipping_override';
        return $hidden;
    }

    /* --------------------------------------------------------------------- */
    /*  ADMIN ASSETS                                                       */
    /* --------------------------------------------------------------------- */
    public function enqueue_assets(): void {
        $screen = get_current_screen();
        $is_order_screen = $screen && (
            $screen->post_type === 'shop_order'
            || $screen->id === 'shop_order'
            || $screen->id === 'woocommerce_page_wc-orders'
            || $screen->base === 'woocommerce_page_wc-orders'
        );
        if ( $is_order_screen ) {
            wp_enqueue_style( 'gunsafes-core-order', GUNSAFES_CORE_URL . 'assets/css/admin.css', [], GUNSAFES_CORE_VER );
            wp_enqueue_script( 'gunsafes-core-order', GUNSAFES_CORE_URL . 'assets/js/admin.js', [ 'jquery' ], GUNSAFES_CORE_VER, true );

            // Your existing add-ons JS (unchanged)
            $addons_js = "
            jQuery(document).ready(function($){
                var gscoreTaxExemptNonce = '" . esc_js( wp_create_nonce( 'gscore_tax_exempt' ) ) . "';
                function toggleTaxExemptNumber(){
                    var \$row = $('#gscore_tax_exempt_number_row');
                    if(!\$row.length){
                        return;
                    }
                    if($('#_gscore_tax_exempt').is(':checked')){
                        \$row.css('display','block');
                    } else {
                        \$row.css('display','none');
                    }
                }
                function ensureManualFlag(\$row, value){
                    var item_id = \$row.find('input.order_item_id').val();
                    if(!item_id){
                        return;
                    }
                    var name = 'manual_line_item_override['+item_id+']';
                    var \$flag = \$row.find('input[name=\"'+name+'\"]');
                    if(!\$flag.length){
                        var \$flagTarget = \$row.find('.line_cost .edit');
                        if(!\$flagTarget.length){
                            \$flagTarget = \$row.find('.line_cost');
                        }
                        if(\$flagTarget.length){
                            \$flagTarget.append('<input type=\"hidden\" name=\"'+name+'\" value=\"0\" />');
                            \$flag = \$row.find('input[name=\"'+name+'\"]');
                        }
                    }
                    if(\$flag.length && value !== undefined){
                        \$flag.val(value);
                    }
                }
                function moveAddons(){
                    var moved = false;
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
                            moved = true;
                        }
                        ensureManualFlag(\$row, 0);
                    });
                    if(moved){
                        $('body').addClass('gscore-addons-ready');
                    } else {
                        $('body').removeClass('gscore-addons-ready');
                    }
                }
                moveAddons();
                $('body').on('added_order_item',moveAddons);
                $('body').on('woocommerce_saved_order_items',moveAddons);
                $('body').on('order-totals-recalculate-success',function(){
                    setTimeout(moveAddons, 0);
                });
                $('body').on('order-totals-recalculate-complete',function(){
                    setTimeout(moveAddons, 0);
                });
                $('#woocommerce-order-items').on('wc_order_items_reloaded',function(){
                    setTimeout(moveAddons, 0);
                });
                $('#woocommerce-order-items').on('wc_order_items_reload',function(){
                    setTimeout(moveAddons, 0);
                });
                toggleTaxExemptNumber();
                $('body').on('change click','#_gscore_tax_exempt',toggleTaxExemptNumber);
                function fetchTaxExemptForUser(userId){
                    userId = parseInt(userId, 10) || 0;
                    if(!userId){
                        $('#_gscore_tax_exempt').prop('checked', false);
                        $('#_gscore_tax_exempt_number').val('');
                        toggleTaxExemptNumber();
                        return;
                    }
                    $.post(ajaxurl, {
                        action: 'gscore_get_tax_exempt',
                        nonce: gscoreTaxExemptNonce,
                        user_id: userId
                    }, function(response){
                        if(!response || !response.success || !response.data){
                            return;
                        }
                        var isExempt = response.data.exempt === 'yes';
                        $('#_gscore_tax_exempt').prop('checked', isExempt);
                        $('#_gscore_tax_exempt_number').val(response.data.number || '');
                        toggleTaxExemptNumber();
                    });
                }
                function maybeFetchTaxExempt(){
                    var userId = $('#customer_user').val() || '';
                    fetchTaxExemptForUser(userId);
                }
                $('body').on('change','#customer_user',function(){
                    setTimeout(maybeFetchTaxExempt, 0);
                });
                $('body').on('select2:select','#customer_user',function(e){
                    if(e && e.params && e.params.data && e.params.data.id){
                        fetchTaxExemptForUser(e.params.data.id);
                        return;
                    }
                    setTimeout(maybeFetchTaxExempt, 0);
                });
                $(document).ajaxSuccess(function(e, xhr, settings){
                    if(!settings || !settings.data){
                        return;
                    }
                    if(settings.data.indexOf('action=woocommerce_get_customer_details') !== -1){
                        setTimeout(maybeFetchTaxExempt, 0);
                    }
                });
                $('body').on('click','.edit-order-item',function(e){
                    var item_id = $(this).closest('tr.item').find('input.order_item_id').val();
                    setTimeout(function(){
                        var \$row = $('tr.item').has('input.order_item_id[value=\"'+item_id+'\"]');
                        var \$addonsRow = \$row.next('.addons-row[data-item-id=\"'+item_id+'\"]');
                        if(\$addonsRow.length){
                            var \$editForm = \$row.find('.edit_item');
                            \$editForm.append('<div class=\"gunsafes-addons-edit\">'+ \$addonsRow.find('td').html() +'</div>');
                        }
                        ensureManualFlag(\$row, 0);
                    },100);
                });
                $('body').on('input change','tr.item input.line_total, tr.item input.line_subtotal',function(){
                    var \$row = $(this).closest('tr.item');
                    var item_id = \$row.find('input.order_item_id').val();
                    if(!item_id){
                        return;
                    }
                    ensureManualFlag(\$row, 1);
                    if($(this).hasClass('line_subtotal')){
                        $(this).data('manual-subtotal','1');
                    }
                    if($(this).hasClass('line_total')){
                        var \$subtotal = \$row.find('input.line_subtotal');
                        if(\$subtotal.length && !\$subtotal.data('manual-subtotal')){
                            \$subtotal.val($(this).val());
                        }
                    }
                });
                $('body').on('input change','tr.shipping input.line_total',function(){
                    var \$row = $(this).closest('tr.shipping');
                    var item_id = \$row.attr('data-order_item_id');
                    if(!item_id){
                        return;
                    }
                    var name = 'manual_shipping_override['+item_id+']';
                    var \$flag = \$row.find('input[name=\"'+name+'\"]');
                    if(\$flag.length){
                        \$flag.val('1');
                        return;
                    }
                    var \$target = \$row.find('.line_cost .edit');
                    if(\$target.length){
                        \$target.append('<input type=\"hidden\" name=\"'+name+'\" value=\"1\" />');
                    }
                });
                $('#woocommerce-order-items').on('woocommerce_order_meta_box_save_line_items_ajax_data', function(e, data){
                    $('tr.item').each(function(){
                        var \$row = $(this);
                        var item_id = \$row.find('input.order_item_id').val();
                        if(!item_id){
                            return;
                        }
                        var name = 'manual_line_item_override['+item_id+']';
                        var \$flag = \$row.find('input[name=\"'+name+'\"]');
                        if(\$flag.length && \$flag.val() === '1'){
                            data.items += '&' + encodeURIComponent(name) + '=1';
                        }
                    });
                    return data;
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
                            if(response.subtotal_raw !== undefined){
                                \$row.find('input[name=\"line_subtotal['+item_id+']\"]').val(response.subtotal_raw);
                            }
                            if(response.total_raw !== undefined){
                                \$row.find('input[name=\"line_total['+item_id+']\"]').val(response.total_raw);
                            }
                            $('.button.calc_totals').trigger('click');
                        }
                    });
                });
            });
            ";
            wp_add_inline_script( 'gunsafes-core-order', $addons_js );

            // Your CSS (unchanged)
            $css = '
            body.gscore-addons-ready th.item_addons,
            body.gscore-addons-ready td.item_addons { display:none; }
            .addons-row td { padding:10px; background:#f8f8f8; border-top:1px solid #ddd; }
            .addon-field { margin-bottom:8px; }
            .addon-field label { display:block; font-weight:bold; }
            .addon-radio-group, .addon-checkbox-group { margin-top:5px; }
            .required { color:red; }
            .gunsafes-addons-edit { margin-top:10px; padding:10px; background:#f8f8f8; border:1px solid #ddd; }
            #gscore_tax_exempt_number_row { display:none; }
            #woocommerce-order-data #_gscore_tax_exempt,
            #order_data #_gscore_tax_exempt {
                width:16px !important;
                height:16px !important;
                min-width:16px !important;
                margin:0 6px 0 0 !important;
                display:inline-block;
            }
            #woocommerce-order-data label[for="_gscore_tax_exempt"],
            #order_data label[for="_gscore_tax_exempt"] {
                display:inline-flex;
                align-items:center;
                gap:6px;
            }
            ';
            wp_add_inline_style( 'gunsafes-core-order', $css );
        }
    }
}

// commenting out because instantiating in my gunsafes core file
// // Initialize the class
// new Admin_Order();
