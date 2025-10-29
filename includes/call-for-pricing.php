<?php
// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Add "Call for Pricing" checkbox to the General tab in product data
add_action('woocommerce_product_options_general_product_data', 'gc_add_call_for_pricing_field');
function gc_add_call_for_pricing_field() {
    woocommerce_wp_checkbox(
        array(
            'id'          => '_call_for_pricing',
            'label'       => __('Call for Pricing', 'gunsafes-core'),
            'description' => __('Check this box to hide the price and display "Call for pricing" on the front end.', 'gunsafes-core'),
        )
    );
}

// Save the "Call for Pricing" checkbox value
add_action('woocommerce_process_product_meta', 'gc_save_call_for_pricing_field');
function gc_save_call_for_pricing_field($post_id) {
    $call_for_pricing = isset($_POST['_call_for_pricing']) ? 'yes' : 'no';
    update_post_meta($post_id, '_call_for_pricing', $call_for_pricing);
}

/* ------------------------------------------------------------------
   2. PRICE HTML – bust WooCommerce price cache when meta changes
   ------------------------------------------------------------------ */
add_filter( 'woocommerce_get_price_html', 'gc_modify_price_display', 100, 2 );
function gc_modify_price_display( $price_html, $product ) {
    $cfp = get_post_meta( $product->get_id(), '_call_for_pricing', true );

    // ----------------------------------------------------------------
    // If CFP is ON → replace price + force cache clear
    // ----------------------------------------------------------------
    if ( $cfp === 'yes' ) {
        // Delete the transient that WC caches for the price HTML
        delete_transient( 'wc_product_price_' . $product->get_id() );

        return '<span class="call-for-pricing">Call for pricing</span>';
    }

    // ----------------------------------------------------------------
    // CFP is OFF → make sure any old transient is gone
    // ----------------------------------------------------------------
    delete_transient( 'wc_product_price_' . $product->get_id() );

    return $price_html;
}

// Add body class for flagged products to enable CSS targeting
add_filter('body_class', 'gc_add_body_class_for_cfp');
function gc_add_body_class_for_cfp($classes) {
    if (is_product()) {
        global $post;
        if (get_post_meta($post->ID, '_call_for_pricing', true) === 'yes') {
            $classes[] = 'gc-call-for-pricing';
        }
    }
    return $classes;
}

/* ------------------------------------------------------------------
   1. INLINE CSS – hide WAPF totals + force late execution
   ------------------------------------------------------------------ */
add_action( 'wp_enqueue_scripts', 'gc_enqueue_call_for_pricing_css', 99 );
function gc_enqueue_call_for_pricing_css() {
    if ( ! ( is_product() || is_shop() || is_product_category() ) ) {
        return;
    }

    $css = '
        /* Existing generic price-hiders (keep them) */
        body.gc-call-for-pricing .apf-total-price,
        body.gc-call-for-pricing .bawfe-price,
        body.gc-call-for-pricing [data-price] {
            display: none !important;
        }

        body.gc-call-for-pricing .call-for-pricing {
            color: #d9534f;
            font-weight: bold;
            font-size: 16px;
            background: #f9f9f9;
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        /* -------------------------------------------------------------
           WAPF TOTALS – hide the whole block AND every inner span
           ------------------------------------------------------------- */
        body.gc-call-for-pricing .wapf-product-totals,
        body.gc-call-for-pricing .wapf-product-totals * {
            display: none !important;
        }

        /* Keep the add-on fields visible (just in case) */
        body.gc-call-for-pricing .wapf-wrapper,
        body.gc-call-for-pricing .wapf-field-container,
        body.gc-call-for-pricing .wapf-field-input,
        body.gc-call-for-pricing .wapf-pricing-hint {
            display: block !important;
        }
    ';

    // Attach to a style that is *always* loaded on product pages
    wp_add_inline_style( 'woocommerce-general', $css );

    // ----------------------------------------------------------------
    // 2. Print a <style> tag in <head> *after* all enqueued CSS
    // ----------------------------------------------------------------
    add_action( 'wp_head', function() use ( $css ) {
        echo '<style id="gc-cfp-inline-late" type="text/css">' . wp_strip_all_tags( $css ) . '</style>';
    }, 999 );
}
// Change "Add to Cart" button text to "Call for Pricing"
add_filter('woocommerce_product_add_to_cart_text', 'gc_change_add_to_cart_text', 10, 2);
function gc_change_add_to_cart_text($text, $product) {
    if (get_post_meta($product->get_id(), '_call_for_pricing', true) === 'yes') {
        return __('Call for Pricing', 'gunsafes-core');
    }
    return $text;
}

// Disable add-to-cart functionality for flagged products
add_filter('woocommerce_add_to_cart_validation', 'gc_disable_add_to_cart_for_cfp', 10, 3);
function gc_disable_add_to_cart_for_cfp($passed, $product_id, $quantity) {
    $product = wc_get_product($product_id);
    if (get_post_meta($product_id, '_call_for_pricing', true) === 'yes') {
        wc_add_notice(__('This product requires a call for pricing. Please contact us.', 'gunsafes-core'), 'error');
        return false; // Prevent adding to cart
    }
    return $passed;
}
?>