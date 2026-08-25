<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'GScore_Email_Shipping_Method_Description' ) ) {
    class GScore_Email_Shipping_Method_Description {

        public function register(): void {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return;
            }

            add_filter( 'yaymail_order_details_layout', [ $this, 'add_description_to_shipping_row' ], 20, 3 );
        }

        public function add_description_to_shipping_row( string $layout, array $element_data, array $args ): string {
            unset( $element_data );

            $order = $args['render_data']['order'] ?? null;
            if ( ! ( $order instanceof WC_Order ) ) {
                return $layout;
            }

            if (
                strpos( $layout, 'yaymail-order-detail-row-shipping' ) === false
                || strpos( $layout, 'gscore-shipping-method-description' ) !== false
            ) {
                return $layout;
            }

            $descriptions = $this->get_shipping_method_descriptions( $order );
            $shipping_method_name = trim( wp_kses_post( (string) $order->get_shipping_method() ) );

            if ( empty( $descriptions ) && $shipping_method_name === '' ) {
                return $layout;
            }

            $description_html = '';
            foreach ( $descriptions as $description ) {
                $description_html .= sprintf(
                    '<div class="gscore-shipping-method-description" style="margin-top:4px;font-weight:normal;">%s</div>',
                    $description
                );
            }

            $updated_layout = preg_replace_callback(
                '/(<tr[^>]*class="[^"]*yaymail-order-detail-row-shipping[^"]*"[^>]*>.*?<th\b[^>]*>)(.*?)(<\/th>)/is',
                function ( array $matches ) use ( $description_html, $shipping_method_name ): string {
                    $shipping_label = $this->add_shipping_method_name_if_missing(
                        $matches[2],
                        $shipping_method_name
                    );

                    return $matches[1] . $shipping_label . $description_html . $matches[3];
                },
                $layout,
                1
            );

            return is_string( $updated_layout ) ? $updated_layout : $layout;
        }

        private function get_shipping_method_descriptions( WC_Order $order ): array {
            $descriptions = [];

            foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
                if ( ! ( $shipping_item instanceof WC_Order_Item_Shipping ) ) {
                    continue;
                }

                $description = $shipping_item->get_meta( 'description', true );
                if (
                    ( ! is_scalar( $description ) || trim( (string) $description ) === '' )
                    && $this->is_flexible_shipping_item( $shipping_item )
                ) {
                    $description = $this->get_flexible_shipping_settings_description( $shipping_item );
                }

                if ( ! is_scalar( $description ) ) {
                    continue;
                }

                $description = trim( wp_kses_post( (string) $description ) );
                if ( $description === '' ) {
                    continue;
                }

                $descriptions[ md5( $description ) ] = $description;
            }

            return array_values( $descriptions );
        }

        private function add_shipping_method_name_if_missing( string $shipping_label, string $shipping_method_name ): string {
            $method_name_text = $this->normalize_text( $shipping_method_name );
            if ( $method_name_text === '' ) {
                return $shipping_label;
            }

            $label_text = $this->normalize_text( $shipping_label );
            if ( stripos( $label_text, $method_name_text ) !== false ) {
                return $shipping_label;
            }

            $separator = $label_text === '' ? '' : ' ';

            return $shipping_label
                . $separator
                . '<span class="gscore-shipping-method-name">'
                . $shipping_method_name
                . '</span>';
        }

        private function normalize_text( string $value ): string {
            $value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
            $value = preg_replace( '/\s+/', ' ', $value );

            return trim( (string) $value );
        }

        private function is_flexible_shipping_item( WC_Order_Item_Shipping $shipping_item ): bool {
            $method_id = (string) $shipping_item->get_method_id();

            return in_array( $method_id, [ 'flexible_shipping', 'flexible_shipping_single' ], true )
                || preg_match( '/^flexible_shipping_\d+$/', $method_id ) === 1;
        }

        private function get_flexible_shipping_settings_description( WC_Order_Item_Shipping $shipping_item ): string {
            $method_id = (string) $shipping_item->get_method_id();
            $instance_id = method_exists( $shipping_item, 'get_instance_id' )
                ? (int) $shipping_item->get_instance_id()
                : 0;

            if ( $instance_id <= 0 && preg_match( '/^flexible_shipping_(\d+)$/', $method_id, $matches ) ) {
                $instance_id = (int) $matches[1];
            }

            if ( $instance_id <= 0 ) {
                return '';
            }

            $settings = get_option( "woocommerce_flexible_shipping_single_{$instance_id}_settings", [] );
            if ( ! is_array( $settings ) || ! isset( $settings['method_description'] ) ) {
                return '';
            }

            return is_scalar( $settings['method_description'] )
                ? (string) $settings['method_description']
                : '';
        }
    }
}
