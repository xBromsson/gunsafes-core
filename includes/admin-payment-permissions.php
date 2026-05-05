<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'GScore_Admin_Payment_Permissions' ) ) {
    /**
     * Lets WooCommerce managers use customer payment pages for phone orders.
     */
    class GScore_Admin_Payment_Permissions {
        public function register(): void {
            add_filter( 'user_has_cap', [ $this, 'allow_managers_to_pay_customer_orders' ], 20, 3 );
        }

        /**
         * WooCommerce's pay-for-order capabilities normally only pass for the
         * order's customer or for guest orders. Admin phone payments are run by
         * staff accounts, and PayPal also checks view_order during create-order.
         */
        public function allow_managers_to_pay_customer_orders( $allcaps, $caps, $args ) {
            $requested_cap = $args[0] ?? '';
            if ( ! in_array( $requested_cap, [ 'pay_for_order', 'view_order' ], true ) ) {
                return $allcaps;
            }

            $order_id = isset( $args[2] ) ? absint( $args[2] ) : 0;
            if ( ! $order_id || ! empty( $allcaps[ $requested_cap ] ) || ! function_exists( 'wc_get_order' ) ) {
                return $allcaps;
            }

            if ( empty( $allcaps['manage_woocommerce'] ) ) {
                return $allcaps;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order || $order instanceof WC_Order_Refund ) {
                return $allcaps;
            }

            $allcaps[ $requested_cap ] = true;

            return $allcaps;
        }
    }
}
