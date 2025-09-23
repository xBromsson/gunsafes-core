<?php

if (!defined('ABSPATH')) exit;

require __DIR__ . '/Admin.php';

class Plugin
{
    public function boot(): void
    {
        if (is_admin()) {
            $admin = new Admin();
            $admin->register();
        }
        // add public hooks later as needed l
    }
}
