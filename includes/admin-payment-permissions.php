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
            add_action( 'template_redirect', [ $this, 'redirect_managers_after_completed_payment' ], 1 );
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

        /**
         * PayPal can return staff to the customer pay URL after the order has
         * already been paid. Woo then shows a misleading "cannot be paid for"
         * notice because the order no longer needs payment. Send staff back to
         * the order screen instead, without changing customer-facing behavior.
         */
        public function redirect_managers_after_completed_payment(): void {
            if ( is_admin() || wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }

            $order_id = absint( get_query_var( 'order-pay' ) );
            if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
                return;
            }

            $order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
            if ( $order_key === '' ) {
                return;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order || $order instanceof WC_Order_Refund || ! hash_equals( $order->get_order_key(), $order_key ) ) {
                return;
            }

            if ( $order->needs_payment() || ! $this->order_has_completed_payment( $order ) ) {
                return;
            }

            wp_safe_redirect( $order->get_edit_order_url() );
            exit;
        }

        private function order_has_completed_payment( WC_Order $order ): bool {
            return (bool) $order->get_date_paid() || $order->get_transaction_id() !== '';
        }
    }
}
