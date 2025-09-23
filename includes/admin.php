<?php

namespace GUNSAFES_Core;

if (!defined('ABSPATH')) exit;

class Admin
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void
    {
        add_menu_page(
            'Gunsafes Core',
            'Gunsafes Core',
            'manage_options',
            'gunsafes-core',
            [$this, 'screen'],
            'dashicons-admin-generic',
            60
        );
    }

    public function screen(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('gs_save')) {
            update_option('gs_demo', sanitize_text_field($_POST['gs_demo'] ?? ''));
            echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
        }
?>
        <div class="wrap">
            <h1>Gunsafes Core</h1>
            <form method="post">
                <?php wp_nonce_field('gs_save'); ?>
                <p>
                    <label>Example setting:
                        <input type="text" name="gs_demo" value="<?php echo esc_attr(get_option('gs_demo', 'Hello World')); ?>">
                    </label>
                </p>
                <p><button class="button button-primary">Save</button></p>
            </form>
        </div>
<?php
    }

    public function assets(): void
    {
        wp_enqueue_style('gs-admin', GUNSAFES_CORE_URL . 'assets/admin.css', [], GUNSAFES_CORE_VER);
        wp_enqueue_script('gs-admin', GUNSAFES_CORE_URL . 'assets/admin.js', ['jquery'], GUNSAFES_CORE_VER, true);
    }
}
