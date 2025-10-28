<?php
/**
 * Simple BCC + Reply-To for *all* WooCommerce customer emails
 * FIXED: Use correct $email object
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* --------------------------------------------------------------
 * 1. EDIT THESE
 * ------------------------------------------------------------ */
$bcc_addresses = [
    'marvinbrown.me@gmail.com',
    // 'sales@gunsafes.com',
];

$reply_to_address = 'sales@gunsafes.com';

/* --------------------------------------------------------------
 * 2. Filter – 4 arguments: $headers, $email_id, $order, $email
 * ------------------------------------------------------------ */
add_filter(
    'woocommerce_email_headers',
    function ( $headers, $email_id, $order, $email ) use ( $bcc_addresses, $reply_to_address ) {

        error_log( "GScore BCC: Filter fired – email_id = '{$email_id}'" );

        // 1. $email is the WC_Email instance
        if ( ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) ) {
            error_log( "GScore BCC: No valid WC_Email object – skipping." );
            return $headers;
        }

        $is_customer = $email->is_customer_email();
        error_log( "GScore BCC: is_customer_email = " . ( $is_customer ? 'YES' : 'NO' ) );

        if ( ! $is_customer ) {
            error_log( "GScore BCC: Not a customer email – skipping." );
            return $headers;
        }

        // 2. Add BCCs
        foreach ( $bcc_addresses as $addr ) {
            $addr = trim( $addr );
            if ( $addr && is_email( $addr ) && stripos( $headers, "Bcc: {$addr}" ) === false ) {
                $headers .= "Bcc: {$addr}\r\n";
                error_log( "GScore BCC: Added BCC → {$addr}" );
            }
        }

        // 3. Force Reply-To
        $headers = preg_replace( '/^Reply-To:.*$/mi', '', $headers );
        $headers .= "Reply-To: {$reply_to_address}\r\n";
        error_log( "GScore BCC: Added Reply-To → {$reply_to_address}" );

        error_log( "GScore BCC: SUCCESS – BCC applied for '{$email_id}'" );

        return $headers;
    },
    999,
    4  // 4 arguments!
);