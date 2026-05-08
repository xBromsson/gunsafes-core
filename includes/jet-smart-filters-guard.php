<?php
/**
 * Guard against Jet Smart Filters AJAX params breaking shop interactions.
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GScore_Jet_Smart_Filters_Guard {

    public function __construct() {
        add_action( 'elementor/widget/before_render_content', [ $this, 'store_products_widget_ajax_settings' ], 20 );
        add_filter( 'woocommerce_product_add_to_cart_url', [ $this, 'strip_jsf_params' ], 20, 2 );
        add_action( 'template_redirect', [ $this, 'redirect_clean_add_to_cart' ], 1 );
    }

    public function store_products_widget_ajax_settings( $widget ): void {
        if (
            ! is_object( $widget )
            || ! method_exists( $widget, 'get_name' )
            || 'woocommerce-products' !== $widget->get_name()
            || ! method_exists( $widget, 'get_settings' )
            || ! function_exists( 'jet_smart_filters' )
            || empty( jet_smart_filters()->providers )
        ) {
            return;
        }

        $settings = $widget->get_settings();

        if ( ! array_key_exists( 'automatically_align_buttons', $settings ) ) {
            return;
        }

        $query_id = ! empty( $settings['_element_id'] ) ? $settings['_element_id'] : 'default';

        jet_smart_filters()->providers->add_provider_settings(
            'epro-products',
            [
                'automatically_align_buttons' => $settings['automatically_align_buttons'],
            ],
            $query_id
        );
    }

    public function strip_jsf_params( $url, $product ) {
        return $this->remove_jsf_args( $url );
    }

    public function redirect_clean_add_to_cart(): void {
        if ( is_admin() ) {
            return;
        }

        if ( empty( $_GET['add-to-cart'] ) ) {
            return;
        }

        if ( empty( $_GET['jsf_ajax'] ) && empty( $_GET['jsf_force_referrer'] ) && empty( $_GET['jsf_referrer_sequence'] ) ) {
            return;
        }

        $requested_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $clean_url     = $this->remove_jsf_args( $requested_url );

        if ( $clean_url !== $requested_url ) {
            wp_safe_redirect( $clean_url );
            exit;
        }
    }

    private function remove_jsf_args( $url ) {
        return remove_query_arg(
            [
                'jsf_ajax',
                'jsf_force_referrer',
                'jsf_referrer_sequence',
                'provider',
                'query_id',
                'jsf',
            ],
            $url
        );
    }
}
