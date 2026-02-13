<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GScore_Checkout_PayPal_Cardholder_Required {

    private const PPCP_CARD_GATEWAY_ID = 'ppcp-credit-card-gateway';

    public function register(): void {
        add_filter( 'woocommerce_credit_card_form_fields', [ $this, 'enforce_cardholder_field' ], 20, 2 );
        add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_cardholder_name' ], 20, 2 );
    }

    public function enforce_cardholder_field( array $fields, string $gateway_id ): array {
        if ( self::PPCP_CARD_GATEWAY_ID !== $gateway_id ) {
            return $fields;
        }

        if ( empty( $fields['card-name-field'] ) || ! is_string( $fields['card-name-field'] ) ) {
            return $fields;
        }

        $updated_field_html = preg_replace_callback(
            '/<input\b[^>]*\bname="ppcp-credit-card-gateway-card-name"[^>]*>/i',
            static function ( array $matches ): string {
                $input = $matches[0];

                $input = preg_replace( '/\splaceholder="[^"]*"/i', '', $input );
                $input = preg_replace( '/\srequired(?:="required")?/i', '', $input );
                $input = preg_replace( '/\saria-required="[^"]*"/i', '', $input );

                $updated_input = preg_replace(
                    '/\s*\/?>$/',
                    ' placeholder="' . esc_attr__( 'Cardholder Name', 'gunsafes-core' ) . '" required aria-required="true">',
                    $input,
                    1
                );

                return is_string( $updated_input ) ? $updated_input : $input;
            },
            $fields['card-name-field']
        );
        if ( is_string( $updated_field_html ) ) {
            $fields['card-name-field'] = $updated_field_html;
        }

        return $fields;
    }

    public function validate_cardholder_name( array $data, WP_Error $errors ): void {
        if ( empty( $data['payment_method'] ) || self::PPCP_CARD_GATEWAY_ID !== $data['payment_method'] ) {
            return;
        }

        $selected_token = isset( $_POST['wc-ppcp-credit-card-gateway-payment-token'] ) ? wc_clean( wp_unslash( $_POST['wc-ppcp-credit-card-gateway-payment-token'] ) ) : '';
        if ( $selected_token !== '' && $selected_token !== 'new' ) {
            return;
        }

        $cardholder_name = isset( $_POST['ppcp-credit-card-gateway-card-name'] ) ? sanitize_text_field( wp_unslash( $_POST['ppcp-credit-card-gateway-card-name'] ) ) : '';
        if ( $cardholder_name !== '' ) {
            return;
        }

        $errors->add(
            'gscore_ppcp_cardholder_required',
            __( 'Please enter the cardholder name.', 'gunsafes-core' )
        );
    }
}
