<?php

if (!defined('ABSPATH')) exit;

require __DIR__ . '/admin/admin-order.php';

class Plugin
{
    public function boot(): void
    {
        if (is_admin()) {
            $admin_order = new Admin_Order();
        }
        // add public hooks later as needed.
    }
}
