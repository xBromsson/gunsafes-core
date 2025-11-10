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
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function add_settings_page() {
        // Parent = WooCommerce
        add_submenu_page(
            'woocommerce',                     // <--- CHANGED
            'BCC Email Settings',
            'BCC Emails',
            'manage_woocommerce',
            'gscore-bcc-settings',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'gscore_bcc_group', $this->option_name, [ $this, 'sanitize' ] );

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

    public function sanitize( $input ) {
        $sanitized = '';
        if ( is_string( $input ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $input ) ) );
            $valid = [];
            foreach ( $lines as $email ) {
                if ( is_email( $email ) ) {
                    $valid[] = $email;
                }
            }
            $sanitized = implode( "\n", $valid );
        }
        return $sanitized;
    }

    public function field_callback() {
        $value = get_option( $this->option_name, "marvin@codeblueprint.co\nsales@gunsafes.com" );
        ?>
        <textarea name="<?php echo esc_attr( $this->option_name ); ?>" rows="6" cols="50" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">Enter one email address per line. Only valid emails will be saved.<br><br>These emails will receive 
        copies of all customer related email notifications for example shipping notifications, order notifications, etc.</p>
        <?php
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>BCC Email Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'gscore_bcc_group' );
                do_settings_sections( 'gscore-bcc-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

// Initialize
add_action( 'plugins_loaded', function() {
    if ( is_admin() ) {
        new GScore_BCC_Settings();
    }
} );