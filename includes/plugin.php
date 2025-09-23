<?php

if (!defined('ABSPATH')) exit;

require __DIR__ . '/Admin.php';

class Plugin
{
    public function boot(): void
    {
        if (is_admin()) {
            (new Admin())->register();
        }
        // add public hooks later as needed
    }
}
