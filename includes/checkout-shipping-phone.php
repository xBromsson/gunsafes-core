<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GScore_Checkout_Shipping_Phone {

    private const PHONE_PLACEHOLDER = '555-555-5555';
    private const PHONE_MAX_LENGTH  = 18;

    public function register(): void {
        add_filter( 'woocommerce_checkout_fields', [ $this, 'add_shipping_phone_field' ] );
        add_filter( 'woocommerce_checkout_posted_data', [ $this, 'normalize_checkout_phone_fields' ] );
        add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout_phone_fields' ], 10, 2 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_phone_script' ] );
        add_filter( 'woocommerce_admin_shipping_fields', [ $this, 'add_admin_shipping_phone_field' ] );
        add_filter( 'woocommerce_email_customer_details_fields', [ $this, 'add_email_shipping_phone_field' ], 10, 3 );
    }

    public function add_shipping_phone_field( array $fields ): array {
        if ( ! empty( $fields['billing']['billing_phone'] ) ) {
            $fields['billing']['billing_phone'] = $this->configure_phone_field(
                $fields['billing']['billing_phone']
            );
        }

        if ( empty( $fields['shipping'] ) ) {
            $fields['shipping'] = [];
        }

        if ( empty( $fields['shipping']['shipping_phone'] ) ) {
            $fields['shipping']['shipping_phone'] = [
                'label'       => __( 'Phone', 'gunsafes-core' ),
                'type'        => 'tel',
                'required'    => true,
                'class'       => [ 'form-row-wide' ],
                'priority'    => 95,
                'autocomplete'=> 'tel',
            ];
        }

        $fields['shipping']['shipping_phone'] = $this->configure_phone_field(
            $fields['shipping']['shipping_phone']
        );

        return $fields;
    }

    private function configure_phone_field( array $field ): array {
        $field['placeholder'] = self::PHONE_PLACEHOLDER;

        if ( empty( $field['custom_attributes'] ) || ! is_array( $field['custom_attributes'] ) ) {
            $field['custom_attributes'] = [];
        }

        $field['custom_attributes']['maxlength'] = (string) self::PHONE_MAX_LENGTH;
        $field['custom_attributes']['inputmode'] = 'tel';

        return $field;
    }

    public function normalize_checkout_phone_fields( array $data ): array {
        foreach ( [ 'billing_phone', 'shipping_phone' ] as $field_key ) {
            if ( ! isset( $data[ $field_key ] ) || $data[ $field_key ] === '' ) {
                continue;
            }

            $normalized = $this->normalize_phone_number( $data[ $field_key ] );
            if ( $normalized !== null ) {
                $data[ $field_key ] = $normalized;
            }
        }

        return $data;
    }

    public function validate_checkout_phone_fields( array $data, WP_Error $errors ): void {
        $fields = [
            'billing_phone' => __( 'Please enter a valid 10-digit billing phone number.', 'gunsafes-core' ),
        ];

        if ( ! empty( $data['ship_to_different_address'] ) ) {
            $fields['shipping_phone'] = __( 'Please enter a valid 10-digit shipping phone number.', 'gunsafes-core' );
        }

        foreach ( $fields as $field_key => $message ) {
            if ( ! array_key_exists( $field_key, $data ) || $data[ $field_key ] === '' ) {
                continue;
            }

            if ( $this->normalize_phone_number( $data[ $field_key ] ) !== null ) {
                continue;
            }

            // WooCommerce may have already rejected unsupported phone characters.
            if ( $errors->get_error_messages( $field_key . '_validation' ) ) {
                continue;
            }

            $errors->add(
                'gscore_' . $field_key . '_validation',
                $message,
                [ 'id' => $field_key ]
            );
        }
    }

    private function normalize_phone_number( $value ): ?string {
        if ( ! is_scalar( $value ) ) {
            return null;
        }

        $phone = trim( (string) $value );
        if ( $phone === '' || strlen( $phone ) > 32 ) {
            return null;
        }

        // Permit common North American display characters, with "+" only at the start.
        if ( ! preg_match( '/^\+?[0-9\s().-]+$/', $phone ) ) {
            return null;
        }

        $digits = preg_replace( '/\D+/', '', $phone );
        if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
            $digits = substr( $digits, 1 );
        }

        if ( strlen( $digits ) !== 10 ) {
            return null;
        }

        return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
    }

    public function enqueue_checkout_phone_script(): void {
        if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }

        $script_path = GUNSAFES_CORE_PATH . 'assets/js/checkout-phone.js';
        $version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : GUNSAFES_CORE_VER;

        wp_enqueue_script(
            'gunsafes-core-checkout-phone',
            GUNSAFES_CORE_URL . 'assets/js/checkout-phone.js',
            [ 'jquery' ],
            $version,
            true
        );
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

        $phone = $order->get_shipping_phone();

        if ( $phone !== '' ) {
            $fields['shipping_phone'] = [
                'label' => __( 'Shipping Phone', 'gunsafes-core' ),
                'value' => $phone,
            ];
        }

        return $fields;
    }
}
