<?php
/**
 * Applies saved featured brand ordering from the shop options page to the shop featured brands query.
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GScore_Jet_Engine_Shop_Featured_Brand_Order' ) ) {
	class GScore_Jet_Engine_Shop_Featured_Brand_Order {
		private const QUERY_ID   = 5;
		private const TAXONOMY   = 'product_brand';
		private const OPTION_KEY = 'shop-page-options_featured_brand_order_ids';
		private const LEGACY_KEY = 'shop-page-options_featured-brands';

		public function __construct() {
			add_action( 'jet-engine/query-builder/query/after-query-setup', array( $this, 'apply_manual_order' ) );
		}

		public function apply_manual_order( $query ): void {
			if ( ! is_object( $query ) ) {
				return;
			}

			$query_type = method_exists( $query, 'get_query_type' ) ? $query->get_query_type() : ( $query->query_type ?? null );
			if ( 'terms' !== $query_type ) {
				return;
			}

			$current_query_id = isset( $query->id ) ? (int) $query->id : 0;
			$real_query_id    = isset( $query->query_id ) ? (int) $query->query_id : 0;

			if ( self::QUERY_ID !== $current_query_id && self::QUERY_ID !== $real_query_id ) {
				return;
			}

			$taxonomies = $this->get_query_taxonomies( $query );
			if ( empty( $taxonomies ) || ! in_array( self::TAXONOMY, $taxonomies, true ) ) {
				return;
			}

			$ordered_brand_ids = $this->get_saved_brand_order();
			if ( empty( $ordered_brand_ids ) ) {
				$query->final_query['include'] = array( 0 );
				return;
			}

			$query->final_query['include']    = $ordered_brand_ids;
			$query->final_query['orderby']    = 'include';
			$query->final_query['hide_empty'] = ! empty( $query->final_query['hide_empty'] );
		}

		private function get_query_taxonomies( $query ): array {
			$taxonomies = array();

			if ( isset( $query->final_query['taxonomy'] ) ) {
				$taxonomies = (array) $query->final_query['taxonomy'];
			} elseif ( isset( $query->query['taxonomy'] ) ) {
				$taxonomies = (array) $query->query['taxonomy'];
			}

			$taxonomies = array_filter(
				array_map(
					static function ( $taxonomy ) {
						return is_string( $taxonomy ) ? sanitize_key( $taxonomy ) : '';
					},
					$taxonomies
				)
			);

			return array_values( array_unique( $taxonomies ) );
		}

		private function get_saved_brand_order(): array {
			$saved_order = get_option( self::OPTION_KEY, '' );
			$ordered     = array();

			if ( is_array( $saved_order ) ) {
				$values = $saved_order;
			} elseif ( is_string( $saved_order ) && '' !== trim( $saved_order ) ) {
				$values = explode( ',', $saved_order );
			} else {
				$values = array();
			}

			$current_brands = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( is_wp_error( $current_brands ) || empty( $current_brands ) ) {
				return array();
			}

			$current_brands = array_map( 'intval', $current_brands );
			$current_lookup = array_fill_keys( $current_brands, true );

			foreach ( $values as $value ) {
				$brand_id = (int) $value;
				if ( $brand_id > 0 && isset( $current_lookup[ $brand_id ] ) && ! in_array( $brand_id, $ordered, true ) ) {
					$ordered[] = $brand_id;
				}
			}

			if ( ! empty( $ordered ) ) {
				return $ordered;
			}

			$legacy_value = get_option( self::LEGACY_KEY, array() );
			if ( ! is_array( $legacy_value ) || empty( $legacy_value ) ) {
				return array();
			}

			$selected_names = array();

			foreach ( $legacy_value as $brand_name => $enabled ) {
				if ( filter_var( $enabled, FILTER_VALIDATE_BOOLEAN ) ) {
					$selected_names[] = (string) $brand_name;
				}
			}

			if ( empty( $selected_names ) ) {
				return array();
			}

			$selected_lookup = array_fill_keys( $selected_names, true );
			$brand_terms     = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $brand_terms ) || empty( $brand_terms ) ) {
				return array();
			}

			usort(
				$brand_terms,
				static function ( $a, $b ) {
					return strcasecmp( $a->name, $b->name );
				}
			);

			foreach ( $brand_terms as $brand_term ) {
				if ( isset( $selected_lookup[ $brand_term->name ] ) ) {
					$ordered[] = (int) $brand_term->term_id;
				}
			}

			return $ordered;
		}
	}
}
