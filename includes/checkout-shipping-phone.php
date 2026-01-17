<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GScore_Checkout_Shipping_Phone {

    public function register(): void {
        add_filter( 'woocommerce_checkout_fields', [ $this, 'add_shipping_phone_field' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_shipping_phone' ] );
        add_filter( 'woocommerce_admin_shipping_fields', [ $this, 'add_admin_shipping_phone_field' ] );
        add_filter( 'woocommerce_email_customer_details_fields', [ $this, 'add_email_shipping_phone_field' ], 10, 3 );
    }

    public function add_shipping_phone_field( array $fields ): array {
        if ( empty( $fields['shipping'] ) ) {
            $fields['shipping'] = [];
        }

        if ( empty( $fields['shipping']['shipping_phone'] ) ) {
            $fields['shipping']['shipping_phone'] = [
                'label'       => __( 'Phone', 'gunsafes-core' ),
                'type'        => 'tel',
                'required'    => false,
                'class'       => [ 'form-row-wide' ],
                'priority'    => 95,
                'autocomplete'=> 'tel',
            ];
        }

        return $fields;
    }

    public function save_shipping_phone( $order_id ): void {
        if ( empty( $_POST['shipping_phone'] ) ) {
            return;
        }
        $phone = sanitize_text_field( wp_unslash( $_POST['shipping_phone'] ) );
        if ( $phone === '' ) {
            return;
        }
        update_post_meta( $order_id, '_shipping_phone', $phone );
    }

    public function add_admin_shipping_phone_field( array $fields ): array {
        if ( isset( $fields['phone'] ) ) {
            return $fields;
        }

        $fields['phone'] = [
            'label' => __( 'Phone', 'gunsafes-core' ),
        ];

        return $fields;
    }

    public function add_email_shipping_phone_field( array $fields, $sent_to_admin, $order ): array {
        if ( ! $order instanceof WC_Order ) {
            return $fields;
        }

        $phone = $order->get_meta( '_shipping_phone', true );
        if ( $phone === '' ) {
            $shipping = $order->get_address( 'shipping' );
            $phone = $shipping['phone'] ?? '';
        }

        if ( $phone !== '' ) {
            $fields['shipping_phone'] = [
                'label' => __( 'Shipping Phone', 'gunsafes-core' ),
                'value' => $phone,
            ];
        }

        return $fields;
    }
}
