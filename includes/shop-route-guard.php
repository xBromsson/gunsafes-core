<?php
/**
 * Keep the WooCommerce shop URL on the product archive query.
 *
 * The shop page is also a real WP page. If rewrite rules are stale, /shop/ can
 * fall back to pagename=shop and bypass the JetThemeCore product archive.
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GScore_Shop_Route_Guard {

    private const GUARD_LOG_TRANSIENT = 'gscore_shop_route_guard_last_log';
    private const GUARD_LOG_INTERVAL  = 300;
    private const LOG_DIR_NAME        = 'gscore-logs';
    private const LOG_FILE_NAME       = 'shop-route-guard.log';
    private const LOG_MAX_BYTES       = 524288;

    /**
     * Register hooks.
     */
    public function register(): void {
        add_filter( 'request', [ $this, 'force_shop_archive_query' ], 1 );
        add_action( 'updated_option', [ $this, 'log_rewrite_rules_update' ], 10, 3 );
        add_action( 'added_option', [ $this, 'log_rewrite_rules_add' ], 10, 2 );
        add_action( 'deleted_option', [ $this, 'log_rewrite_rules_delete' ], 10, 1 );
    }

    /**
     * Force exact /shop/ requests back to the product archive if rewrites fall
     * through to the backing Shop page.
     *
     * @param array $query_vars Parsed query vars.
     * @return array
     */
    public function force_shop_archive_query( array $query_vars ): array {
        if ( is_admin() || wp_doing_ajax() || $this->is_rest_request() ) {
            return $query_vars;
        }

        if ( ! $this->is_exact_shop_request() ) {
            return $query_vars;
        }

        if ( isset( $query_vars['post_type'] ) && 'product' === $query_vars['post_type'] ) {
            return $query_vars;
        }

        $original_query_vars = $query_vars;

        unset(
            $query_vars['pagename'],
            $query_vars['page_id'],
            $query_vars['name'],
            $query_vars['page']
        );

        $query_vars['post_type'] = 'product';

        if ( $this->should_log_guard_activation() ) {
            $this->log_event(
                'shop_route_guard_activated',
                [
                    'original_query_vars' => $original_query_vars,
                    'new_query_vars'      => $query_vars,
                ]
            );
        }

        return $query_vars;
    }

    /**
     * Log rewrite rule updates.
     *
     * @param string $option Option name.
     * @param mixed  $old_value Old value.
     * @param mixed  $value New value.
     */
    public function log_rewrite_rules_update( string $option, $old_value, $value ): void {
        if ( 'rewrite_rules' !== $option ) {
            return;
        }

        $this->log_event(
            'rewrite_rules_updated',
            [
                'old_shop_rule' => $this->get_shop_rule_target( $old_value ),
                'new_shop_rule' => $this->get_shop_rule_target( $value ),
            ]
        );
    }

    /**
     * Log rewrite rule creation.
     *
     * @param string $option Option name.
     * @param mixed  $value Option value.
     */
    public function log_rewrite_rules_add( string $option, $value ): void {
        if ( 'rewrite_rules' !== $option ) {
            return;
        }

        $this->log_event(
            'rewrite_rules_added',
            [
                'new_shop_rule' => $this->get_shop_rule_target( $value ),
            ]
        );
    }

    /**
     * Log rewrite rule deletion.
     *
     * @param string $option Option name.
     */
    public function log_rewrite_rules_delete( string $option ): void {
        if ( 'rewrite_rules' !== $option ) {
            return;
        }

        $this->log_event( 'rewrite_rules_deleted' );
    }

    /**
     * Check whether the current request path exactly matches the configured
     * WooCommerce shop page path.
     *
     * @return bool
     */
    private function is_exact_shop_request(): bool {
        $request_path = $this->get_request_path();
        $shop_path    = $this->get_shop_path();

        return '' !== $shop_path && $request_path === $shop_path;
    }

    /**
     * Get the current request path relative to the site home path.
     *
     * @return string
     */
    private function get_request_path(): string {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
        $path        = trim( rawurldecode( $path ), '/' );

        $home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

        if ( '' !== $home_path && ( $path === $home_path || 0 === strpos( $path, $home_path . '/' ) ) ) {
            $path = trim( substr( $path, strlen( $home_path ) ), '/' );
        }

        return $path;
    }

    /**
     * Get the configured WooCommerce shop page path.
     *
     * @return string
     */
    private function get_shop_path(): string {
        $shop_page_id = $this->get_shop_page_id();

        if ( $shop_page_id <= 0 ) {
            return 'shop';
        }

        $shop_path = get_page_uri( $shop_page_id );

        return $shop_path ? trim( rawurldecode( $shop_path ), '/' ) : 'shop';
    }

    /**
     * Get the configured WooCommerce shop page ID.
     *
     * @return int
     */
    private function get_shop_page_id(): int {
        if ( function_exists( 'wc_get_page_id' ) ) {
            return (int) wc_get_page_id( 'shop' );
        }

        return (int) get_option( 'woocommerce_shop_page_id' );
    }

    /**
     * Get the stored target for the shop archive rewrite rule.
     *
     * @param mixed $rules Rewrite rules option value.
     * @return string|null
     */
    private function get_shop_rule_target( $rules ): ?string {
        if ( ! is_array( $rules ) ) {
            return null;
        }

        $shop_rule = $this->get_shop_rewrite_rule();

        return isset( $rules[ $shop_rule ] ) ? (string) $rules[ $shop_rule ] : null;
    }

    /**
     * Get the expected rewrite rule regex for the shop archive.
     *
     * @return string
     */
    private function get_shop_rewrite_rule(): string {
        return $this->get_shop_path() . '/?$';
    }

    /**
     * Detect REST requests without requiring newer WP helper functions.
     *
     * @return bool
     */
    private function is_rest_request(): bool {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }

        if ( function_exists( 'wp_is_serving_rest_request' ) && did_action( 'parse_request' ) ) {
            return wp_is_serving_rest_request();
        }

        return false;
    }

    /**
     * Write a compact diagnostic entry to this plugin's capped log file.
     *
     * @param string $event Event name.
     * @param array  $context Extra context.
     */
    private function log_event( string $event, array $context = [] ): void {
        if ( defined( 'GUNSAFES_SHOP_ROUTE_GUARD_LOG' ) && ! GUNSAFES_SHOP_ROUTE_GUARD_LOG ) {
            return;
        }

        $rules            = get_option( 'rewrite_rules' );
        $shop_rule        = $this->get_shop_rewrite_rule();
        $shop_rule_target = $this->get_shop_rule_target( $rules );

        $payload = array_merge(
            [
                'event'            => $event,
                'request_uri'      => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
                'is_admin'         => is_admin(),
                'doing_ajax'       => wp_doing_ajax(),
                'doing_cron'       => defined( 'DOING_CRON' ) && DOING_CRON,
                'user_id'          => get_current_user_id(),
                'shop_page_id'     => $this->get_shop_page_id(),
                'shop_path'        => $this->get_shop_path(),
                'shop_rule'        => $shop_rule,
                'shop_rule_target' => $shop_rule_target,
                'shop_rule_ok'     => 'index.php?post_type=product' === $shop_rule_target,
                'trace'            => $this->get_relevant_trace(),
            ],
            $context
        );

        $this->write_log_line( '[Gunsafes Shop Route Guard] ' . wp_json_encode( $payload ) );
    }

    /**
     * Append one line to a capped plugin-specific log file.
     *
     * @param string $line Log line.
     */
    private function write_log_line( string $line ): void {
        $log_file = $this->get_log_file_path();

        if ( ! $log_file ) {
            return;
        }

        $max_bytes = defined( 'GUNSAFES_SHOP_ROUTE_GUARD_LOG_MAX_BYTES' )
            ? (int) GUNSAFES_SHOP_ROUTE_GUARD_LOG_MAX_BYTES
            : self::LOG_MAX_BYTES;

        if ( $max_bytes > 0 && file_exists( $log_file ) && filesize( $log_file ) > $max_bytes ) {
            @rename( $log_file, $log_file . '.1' );
        }

        $entry = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . $line . PHP_EOL;

        @file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );
    }

    /**
     * Get or create the plugin-specific log file path.
     *
     * @return string|null
     */
    private function get_log_file_path(): ?string {
        $log_dir = defined( 'GUNSAFES_SHOP_ROUTE_GUARD_LOG_DIR' )
            ? (string) GUNSAFES_SHOP_ROUTE_GUARD_LOG_DIR
            : trailingslashit( WP_CONTENT_DIR ) . self::LOG_DIR_NAME;

        if ( ! is_dir( $log_dir ) && ! wp_mkdir_p( $log_dir ) ) {
            return null;
        }

        $this->protect_log_dir( $log_dir );

        return trailingslashit( $log_dir ) . self::LOG_FILE_NAME;
    }

    /**
     * Add basic direct-access protection for the log directory.
     *
     * @param string $log_dir Log directory path.
     */
    private function protect_log_dir( string $log_dir ): void {
        $htaccess = trailingslashit( $log_dir ) . '.htaccess';
        $index    = trailingslashit( $log_dir ) . 'index.php';

        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Deny from all\n" );
        }

        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
    }

    /**
     * Limit front-end activation logs if the shop route is broken under traffic.
     *
     * @return bool
     */
    private function should_log_guard_activation(): bool {
        if ( get_transient( self::GUARD_LOG_TRANSIENT ) ) {
            return false;
        }

        set_transient( self::GUARD_LOG_TRANSIENT, 1, self::GUARD_LOG_INTERVAL );

        return true;
    }

    /**
     * Return a short stack trace focused on site code.
     *
     * @return array
     */
    private function get_relevant_trace(): array {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );
        $items = [];

        foreach ( $trace as $frame ) {
            if ( empty( $frame['file'] ) ) {
                continue;
            }

            $file = wp_normalize_path( (string) $frame['file'] );

            if ( false === strpos( $file, '/wp-content/' ) ) {
                continue;
            }

            $items[] = [
                'file'     => $this->relative_content_path( $file ),
                'line'     => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
                'function' => isset( $frame['function'] ) ? (string) $frame['function'] : '',
            ];

            if ( count( $items ) >= 8 ) {
                break;
            }
        }

        return $items;
    }

    /**
     * Convert an absolute wp-content path into a shorter relative path.
     *
     * @param string $file Absolute file path.
     * @return string
     */
    private function relative_content_path( string $file ): string {
        $content_dir = wp_normalize_path( WP_CONTENT_DIR );

        if ( 0 === strpos( $file, $content_dir ) ) {
            return 'wp-content' . substr( $file, strlen( $content_dir ) );
        }

        return $file;
    }
}
