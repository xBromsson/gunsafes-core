<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'GScore_Email_Coupon_Discount_Label' ) ) {
    class GScore_Email_Coupon_Discount_Label {

        public function register(): void {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return;
            }

            add_action( 'woocommerce_email_before_order_table', [ $this, 'enable_for_customer_email' ], 10, 4 );
            add_action( 'woocommerce_email_after_order_table', [ $this, 'disable_for_email' ], 10, 4 );
            add_filter( 'yaymail_order_details_layout', [ $this, 'add_coupon_descriptions_to_yaymail_discount_label' ], 20, 3 );
        }

        public function enable_for_customer_email( $order, $sent_to_admin, $plain_text, $email ): void {
            if ( $sent_to_admin || ! ( $order instanceof WC_Order ) ) {
                return;
            }

            if ( ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) || ! $email->is_customer_email() ) {
                return;
            }

            add_filter( 'woocommerce_get_order_item_totals', [ $this, 'add_coupon_descriptions_to_discount_label' ], 20, 3 );
        }

        public function disable_for_email( $order, $sent_to_admin, $plain_text, $email ): void {
            unset( $order, $sent_to_admin, $plain_text, $email );

            remove_filter( 'woocommerce_get_order_item_totals', [ $this, 'add_coupon_descriptions_to_discount_label' ], 20 );
        }

        public function add_coupon_descriptions_to_discount_label( array $total_rows, WC_Order $order, string $tax_display ): array {
            unset( $tax_display );

            if ( empty( $total_rows['discount'] ) || $order->get_total_discount() <= 0 ) {
                return $total_rows;
            }

            $discount_label = $this->get_discount_label( $order );
            if ( $discount_label === '' ) {
                return $total_rows;
            }

            $total_rows['discount']['label'] = $discount_label;

            return $total_rows;
        }

        public function add_coupon_descriptions_to_yaymail_discount_label( string $layout, array $element_data, array $args ): string {
            unset( $element_data );

            $order = $args['render_data']['order'] ?? null;
            if ( ! ( $order instanceof WC_Order ) || $order->get_total_discount() <= 0 ) {
                return $layout;
            }

            if ( ! $this->is_yaymail_customer_template( $args ) ) {
                return $layout;
            }

            $discount_label = $this->get_discount_label( $order );
            if ( $discount_label === '' || strpos( $layout, 'yaymail-order-detail-row-discount' ) === false ) {
                return $layout;
            }

            $updated_layout = preg_replace_callback(
                '/(<tr[^>]*class="[^"]*yaymail-order-detail-row-discount[^"]*"[^>]*>.*?<th\b[^>]*>)(.*?)(<\/th>)/is',
                function( array $matches ) use ( $discount_label ): string {
                    return $matches[1] . esc_html( $discount_label ) . $matches[3];
                },
                $layout,
                1
            );

            return is_string( $updated_layout ) ? $updated_layout : $layout;
        }

        private function get_discount_label( WC_Order $order ): string {
            $coupon_labels = $this->get_coupon_labels( $order );
            if ( empty( $coupon_labels ) ) {
                return '';
            }

            return sprintf(
                /* translators: %s: comma-separated coupon descriptions. */
                __( 'Discount (%s):', 'gunsafes-core' ),
                implode( ', ', $coupon_labels )
            );
        }

        private function get_coupon_labels( WC_Order $order ): array {
            $labels = array();

            foreach ( $order->get_coupon_codes() as $code ) {
                $label = $this->get_coupon_description( $code );

                $label = $this->normalize_label( $label );

                if ( $label !== '' ) {
                    $labels[] = $label;
                }
            }

            return array_values( array_unique( $labels ) );
        }

        private function is_yaymail_customer_template( array $args ): bool {
            $template = $args['template'] ?? null;

            if ( is_object( $template ) && method_exists( $template, 'get_name' ) ) {
                $template_name = (string) $template->get_name();
            } elseif ( is_object( $template ) && method_exists( $template, 'get_data' ) ) {
                $template_data = $template->get_data();
                $template_name = is_array( $template_data ) && isset( $template_data['name'] ) ? (string) $template_data['name'] : '';
            } else {
                $template_name = '';
            }

            return $template_name === '' || strpos( $template_name, 'admin-' ) !== 0;
        }

        private function get_coupon_description( string $code ): string {
            if ( ! class_exists( 'WC_Coupon' ) ) {
                return '';
            }

            try {
                $coupon = new WC_Coupon( $code );
            } catch ( Exception $exception ) {
                return '';
            }

            if ( ! $coupon->get_id() ) {
                return '';
            }

            return (string) $coupon->get_description();
        }

        private function normalize_label( string $label ): string {
            $label = html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES, get_bloginfo( 'charset' ) );
            $label = preg_replace( '/\s+/', ' ', $label );

            return trim( (string) $label );
        }
    }
}
