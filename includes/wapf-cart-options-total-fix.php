<?php
/**
 * Fix WAPF cart option totals not being added to line item price.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'wapf/pricing/cart_item_options', function( $options_total, $product, $quantity, $cart_item ) {
    if ( $options_total > 0 ) {
        return $options_total;
    }

    if ( empty( $cart_item['wapf'] ) || ! is_array( $cart_item['wapf'] ) ) {
        return $options_total;
    }

    $calculated_total = 0.0;

    foreach ( $cart_item['wapf'] as $field ) {
        if ( empty( $field['values'] ) || ! is_array( $field['values'] ) ) {
            continue;
        }

        foreach ( $field['values'] as $value ) {
            if ( ! isset( $value['calc_price'] ) ) {
                continue;
            }

            $price = (float) $value['calc_price'];
            if ( $price === 0.0 ) {
                continue;
            }

            $calculated_total += $price;
        }
    }

    return $calculated_total > 0 ? $calculated_total : $options_total;
}, 10, 4 );
