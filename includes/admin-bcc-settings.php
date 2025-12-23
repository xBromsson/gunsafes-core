<?php
/**
 * Admin Settings Page: BCC Email List
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GScore_BCC_Settings {

    private $option_name = 'gscore_bcc_emails';

    public function __construct() {
        // Add submenu very late – after WooCommerce has registered its top-level menu
        add_action( 'admin_menu', [ $this, 'add_settings_page' ], 999 );

        // Register settings on admin_init
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    /**
     * Add submenu under the top-level WooCommerce menu
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',                     // Parent slug – top-level WooCommerce menu
            'BCC Email Settings',
            'BCC Emails',
            'manage_woocommerce',
            'gscore-bcc-settings',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Register the setting, section, and field
     */
    public function register_settings() {
        // Restrict who can save this option
        add_filter( 'option_page_capability_gscore_bcc_group', function() {
            return 'manage_woocommerce';
        } );

        register_setting(
            'gscore_bcc_group',
            $this->option_name,
            [ $this, 'sanitize' ]
        );

        add_settings_section(
            'main_section',
            'BCC Recipient Emails',
            null,
            'gscore-bcc-settings'
        );

        add_settings_field(
            'bcc_list',
            'BCC Emails (one per line)',
            [ $this, 'field_callback' ],
            'gscore-bcc-settings',
            'main_section'
        );
    }

    /**
     * Sanitize input – only keep valid email addresses
     */
    public function sanitize( $input ) {
        $sanitized = '';

        if ( is_string( $input ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $input ) ) );
            $valid = [];

            foreach ( $lines as $email ) {
                if ( is_email( $email ) ) {
                    $valid[] = sanitize_email( $email );
                }
            }

            $sanitized = implode( "\n", $valid );
        }

        return $sanitized;
    }

    /**
     * Field output
     */
    public function field_callback() {
        $value = get_option( $this->option_name, "marvin@codeblueprint.co\nsales@gunsafes.com" );
        ?>
        <textarea name="<?php echo esc_attr( $this->option_name ); ?>" rows="8" cols="50" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            Enter one email address per line. Only valid emails will be saved.<br><br>
            These emails will receive BCC copies of all customer-related WooCommerce notifications (order confirmations, shipping updates, etc.).
        </p>
        <?php
    }

    /**
     * Render the settings page
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>BCC Email Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'gscore_bcc_group' );
                do_settings_sections( 'gscore-bcc-settings' );
                submit_button( 'Save BCC Emails' );
                ?>
            </form>
        </div>
        <?php
    }
}

// Instantiate on admin_menu with high priority (after WooCommerce menu is registered)
add_action( 'admin_menu', function() {
    static $instance = null;
    if ( $instance === null ) {
        $instance = new GScore_BCC_Settings();
    }
}, 998 );