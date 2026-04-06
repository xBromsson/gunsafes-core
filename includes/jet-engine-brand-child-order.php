<?php
/**
 * Applies saved parent-brand child term ordering to a specific JetEngine terms query.
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GScore_Jet_Engine_Brand_Child_Order' ) ) {
	class GScore_Jet_Engine_Brand_Child_Order {
		private const QUERY_ID = 6;
		private const TAXONOMY = 'product_brand';
		private const META_KEY = 'gscore_child_brand_order';

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

			$current_term = get_queried_object();
			if ( ! ( $current_term instanceof WP_Term ) || self::TAXONOMY !== $current_term->taxonomy ) {
				return;
			}

			$taxonomies = $this->get_query_taxonomies( $query );
			if ( empty( $taxonomies ) || ! in_array( self::TAXONOMY, $taxonomies, true ) ) {
				return;
			}

			$ordered_child_ids = $this->get_saved_child_order( (int) $current_term->term_id );
			if ( empty( $ordered_child_ids ) ) {
				return;
			}

			$query->final_query['include']  = $ordered_child_ids;
			$query->final_query['orderby']  = 'include';
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

		private function get_saved_child_order( int $parent_term_id ): array {
			$saved_order = get_term_meta( $parent_term_id, self::META_KEY, true );
			if ( ! is_array( $saved_order ) || empty( $saved_order ) ) {
				return array();
			}

			$current_children = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'parent'     => $parent_term_id,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( is_wp_error( $current_children ) || empty( $current_children ) ) {
				return array();
			}

			$current_children = array_map( 'intval', $current_children );
			$current_lookup   = array_fill_keys( $current_children, true );
			$ordered          = array();

			foreach ( array_map( 'intval', $saved_order ) as $child_id ) {
				if ( isset( $current_lookup[ $child_id ] ) && ! in_array( $child_id, $ordered, true ) ) {
					$ordered[] = $child_id;
				}
			}

			$missing_children = array_values( array_diff( $current_children, $ordered ) );
			if ( empty( $missing_children ) ) {
				return $ordered;
			}

			$missing_terms = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'include'    => $missing_children,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $missing_terms ) || empty( $missing_terms ) ) {
				return $ordered;
			}

			usort(
				$missing_terms,
				static function ( $a, $b ) {
					return strcasecmp( $a->name, $b->name );
				}
			);

			foreach ( $missing_terms as $missing_term ) {
				$ordered[] = (int) $missing_term->term_id;
			}

			return $ordered;
		}
	}
}
