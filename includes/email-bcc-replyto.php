<?php
/**
 * Simple BCC + Reply-To for *all* WooCommerce customer emails
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* --------------------------------------------------------------
 * 1. EDIT THESE
 * ------------------------------------------------------------ */
$bcc_list_raw = get_option( 'gscore_bcc_emails', "marvin@codeblueprint.co\nsales@gunsafes.com" );
$bcc_addresses = array_filter( array_map( 'trim', explode( "\n", $bcc_list_raw ) ) );

$reply_to_address = 'sales@gunsafes.com';

/* --------------------------------------------------------------
 * 2. Filter – 4 arguments: $headers, $email_id, $order, $email
 * ------------------------------------------------------------ */
add_filter(
    'woocommerce_email_headers',
    function ( $headers, $email_id, $order, $email ) use ( $bcc_addresses, $reply_to_address ) {

        // Bail early if we don't have a proper WC_Email object.
        if ( ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) || ! $email->is_customer_email() ) {
            return $headers;
        }

        // Add each BCC (skip duplicates).
        foreach ( $bcc_addresses as $addr ) {
            $addr = trim( $addr );
            if ( $addr && is_email( $addr ) && stripos( $headers, "Bcc: {$addr}" ) === false ) {
                $headers .= "Bcc: {$addr}\r\n";
            }
        }

        // Force Reply-To (remove any existing one first).
        $headers = preg_replace( '/^Reply-To:.*$/mi', '', $headers );
        $headers .= "Reply-To: {$reply_to_address}\r\n";

        return $headers;
    },
    999,
    4
);