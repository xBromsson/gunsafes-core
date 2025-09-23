<?php

namespace GUNSAFES_Core;

if (!defined('ABSPATH')) exit;

class Admin {
    public function register(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void {
        add_menu_page(
            'GUNSAFES Core', 'GUNSAFES Core', 'manage_options', 'gunsafes-core', [$this, 'screen'], 'dashicons-admin-generic', 60
        );
    }
}