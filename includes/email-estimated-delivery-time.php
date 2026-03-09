<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'GScore_Email_Estimated_Delivery_Time' ) ) {
    class GScore_Email_Estimated_Delivery_Time {

        public function register(): void {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return;
            }

            add_action( 'woocommerce_email_order_item_meta', [ $this, 'render_email_item_meta' ], 9, 4 );
            add_action( 'woocommerce_order_item_meta_end', [ $this, 'render_order_item_meta_end' ], 9, 4 );
        }

        public function render_email_item_meta( $item, $sent_to_admin, $plain_text, $email ): void {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                return;
            }

            $this->render_estimated_delivery_output( $item, (bool) $plain_text );
        }

        public function render_order_item_meta_end( $item_id, $item, $order, $plain_text ): void {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                return;
            }

            $this->render_estimated_delivery_output( $item, (bool) $plain_text );
        }

        private function get_estimated_delivery_time( WC_Product $product ): string {
            $product_id = $product->get_id();
            $value = get_post_meta( $product_id, 'estimated_delivery_time', true );

            if ( $value === '' ) {
                $value = get_post_meta( $product_id, '_estimated_delivery_time', true );
            }

            if ( $value === '' && $product->is_type( 'variation' ) ) {
                $parent_id = $product->get_parent_id();
                if ( $parent_id ) {
                    $value = get_post_meta( $parent_id, 'estimated_delivery_time', true );
                    if ( $value === '' ) {
                        $value = get_post_meta( $parent_id, '_estimated_delivery_time', true );
                    }
                }
            }

            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'strval', $value ) );
            }

            return trim( wp_strip_all_tags( (string) $value ) );
        }

        private function render_estimated_delivery_output( WC_Order_Item_Product $item, bool $plain_text ): void {
            $product = $item->get_product();
            if ( ! $product ) {
                return;
            }

            $estimated_delivery = $this->get_estimated_delivery_time( $product );
            if ( $estimated_delivery === '' ) {
                return;
            }

            if ( $plain_text ) {
                echo "\n" . sprintf(
                    /* translators: %s: estimated delivery time value. */
                    esc_html__( 'Estimated delivery time: %s', 'gunsafes-core' ),
                    $estimated_delivery
                ) . "\n";
                return;
            }

            echo '<div class="gscore-item-estimated-delivery" style="margin-top:6px;line-height:1.4;">';
            echo '<strong>' . esc_html__( 'Estimated delivery time:', 'gunsafes-core' ) . '</strong> ' . esc_html( $estimated_delivery );
            echo '</div>';
        }
    }
}
