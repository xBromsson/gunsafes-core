<?php
/**
 * Regional Shipping Markups Settings
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GScore_Regional_Markups_Settings {

    public function __construct() {
        // Add the submenu VERY late (after WooCommerce registers its parent menu)
        add_action( 'admin_menu', [ $this, 'add_settings_page' ], 999 );

        // Register settings and ensure defaults on admin_init
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'ensure_defaults' ] );
    }

    /**
     * Add submenu under the top-level WooCommerce menu
     */
    public function add_settings_page() {
        // Debug: confirm this method runs
        error_log( 'GScore_Regional_Markups_Settings: add_settings_page() executed – adding submenu' );

        add_submenu_page(
            'woocommerce',                          // Parent slug – correct for top-level WooCommerce menu
            'Regional Shipping Markups',
            'Regional Shipping Markups',
            'manage_woocommerce',
            'gscore-regional-markups',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Register settings, sections, and fields
     */
    public function register_settings() {
        // Restrict who can save these options
        add_filter( 'option_page_capability_gscore_regional_group', function() {
            return 'manage_woocommerce';
        } );

        register_setting(
            'gscore_regional_group',
            'gscore_regional_markups_zip',
            [ $this, 'sanitize_zip' ]
        );

        register_setting(
            'gscore_regional_group',
            'gscore_regional_markups_state',
            [ $this, 'sanitize_state' ]
        );

        add_settings_section(
            'zip_section',
            'ZIP Code Markups',
            null,
            'gscore-regional-markups'
        );

        add_settings_section(
            'state_section',
            'State Markups',
            null,
            'gscore-regional-markups'
        );

        add_settings_field(
            'zip_markups',
            'ZIP Codes (one per line: ZIP MARKUP%)',
            [ $this, 'zip_field' ],
            'gscore-regional-markups',
            'zip_section'
        );

        add_settings_field(
            'state_markups',
            'States (one per line: STATE MARKUP%)',
            [ $this, 'state_field' ],
            'gscore-regional-markups',
            'state_section'
        );
    }

    /**
     * Ensure default values exist in the database
     */
    public function ensure_defaults() {
        if ( false === get_option( 'gscore_regional_markups_zip' ) ) {
            update_option( 'gscore_regional_markups_zip', $this->array_to_text( $this->get_default_zip() ) );
        }

        if ( false === get_option( 'gscore_regional_markups_state' ) ) {
            update_option( 'gscore_regional_markups_state', $this->array_to_text( $this->get_default_state() ) );
        }
    }

    public function sanitize_zip( $input ) {
        return $this->sanitize_text( $input, '/^(\d{5})\s+([\d.]+)%?$/' );
    }

    public function sanitize_state( $input ) {
        return $this->sanitize_text( $input, '/^([A-Z]{2})\s+([\d.]+)%?$/' );
    }

    private function sanitize_text( $input, $pattern ) {
        if ( ! is_string( $input ) ) {
            return '';
        }

        $lines = array_filter( array_map( 'trim', explode( "\n", $input ) ) );
        $valid = [];

        foreach ( $lines as $line ) {
            if ( preg_match( $pattern, $line, $m ) ) {
                $valid[ $m[1] ] = (float) $m[2];
            }
        }

        return $this->array_to_text( $valid );
    }

    private function array_to_text( $array ) {
        $lines = [];
        foreach ( $array as $k => $v ) {
            $lines[] = "$k $v";
        }
        return implode( "\n", $lines );
    }

    public function zip_field() {
        $value = get_option( 'gscore_regional_markups_zip', $this->array_to_text( $this->get_default_zip() ) );
        ?>
        <textarea name="gscore_regional_markups_zip" rows="10" cols="50" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">Example: <code>07876 20</code> → 20% markup (percent sign optional)</p>
        <?php
    }

    public function state_field() {
        $value = get_option( 'gscore_regional_markups_state', $this->array_to_text( $this->get_default_state() ) );
        ?>
        <textarea name="gscore_regional_markups_state" rows="10" cols="50" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">Example: <code>NJ 20</code> → 20% markup (percent sign optional)</p>
        <?php
    }

    private function get_default_zip() {
        return [
            '07876' => 20.0, '05001' => 25.0, '02901' => 25.0,
            '81120' => 30.0, '81302' => 30.0, '81303' => 30.0, '81301' => 30.0,
            '80435' => 30.0, '80438' => 30.0, '80442' => 30.0, '80443' => 30.0,
            '80446' => 30.0, '80447' => 30.0, '80451' => 30.0, '80452' => 30.0,
            '80459' => 30.0, '80468' => 30.0, '80473' => 30.0, '80478' => 30.0,
            '80482' => 30.0,
        ];
    }

    private function get_default_state() {
        return [
            'NJ' => 20.0, 'NY' => 20.0, 'VT' => 25.0, 'RI' => 25.0, 'CO' => 30.0,
            'ME' => 25.0, 'NH' => 25.0, 'CT' => 25.0, 'VA' => 25.0, 'ND' => 35.0,
            'WI' => 65.0, 'WY' => 30.0, 'CA' => 75.0, 'MA' => 40.0, 'MT' => 75.0,
            'AL' => 30.0, 'MD' => 20.0, 'MI' => 150.0, 'UT' => 100.0, 'IL' => 50.0,
        ];
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Regional Shipping Markups</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'gscore_regional_group' );
                do_settings_sections( 'gscore-regional-markups' );
                submit_button( 'Save Markups' );
                ?>
            </form>
        </div>
        <?php
    }
}

// Instantiate the class on admin_menu with a high priority (after WooCommerce adds its menu)
add_action( 'admin_menu', function() {
    static $instance = null;
    if ( $instance === null ) {
        error_log( 'GScore_Regional_Markups_Settings: Class instantiated successfully' );
        $instance = new GScore_Regional_Markups_Settings();
    }
}, 998 );