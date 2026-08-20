( function( $ ) {
    'use strict';

    const phoneSelector = '#billing_phone, #shipping_phone';

    function formatPhoneNumber( value ) {
        let digits = String( value || '' ).replace( /\D/g, '' );

        if ( digits.length === 11 && digits.charAt( 0 ) === '1' ) {
            digits = digits.substring( 1 );
        }

        // Leave an invalid over-length number visible so server validation can explain the issue.
        if ( digits.length > 10 ) {
            return digits;
        }

        if ( digits.length > 6 ) {
            return digits.substring( 0, 3 ) + '-' + digits.substring( 3, 6 ) + '-' + digits.substring( 6 );
        }

        if ( digits.length > 3 ) {
            return digits.substring( 0, 3 ) + '-' + digits.substring( 3 );
        }

        return digits;
    }

    function formatPhoneField() {
        const $field = $( this );
        const formatted = formatPhoneNumber( $field.val() );

        if ( $field.val() !== formatted ) {
            $field.val( formatted );
        }
    }

    function formatCheckoutPhoneFields() {
        $( phoneSelector ).each( formatPhoneField );
    }

    $( document.body )
        .on( 'input blur', phoneSelector, formatPhoneField )
        .on( 'updated_checkout', formatCheckoutPhoneFields );

    $( formatCheckoutPhoneFields );
} )( jQuery );
