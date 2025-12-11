<?php
/**
 * Plugin Name: Fix Events Manager + WPML Slug Issue
 * Description: Fixes empty post_name before Events Manager reads it, preventing slug issues with WPML duplicates
 * Version: 1.0.0
 * Author: RSdesign – www.rs-design.at
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fix_Events_Manager_WPML_Slug {

	/**
	 * Initialize the fix
	 */
	public static function init() {
		// Run BEFORE Events Manager's wp_insert_post_data (priority 100)
		// This ensures post_name is never empty when EM reads it
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'fix_empty_post_name_before_em' ), 50, 2 );

		// Also fix when post is loaded (before EM reads it)
		add_filter( 'the_posts', array( __CLASS__, 'fix_posts_array' ), 1, 2 );
	}

	/**
	 * Fix empty post_name BEFORE Events Manager reads it in wp_insert_post_data
	 * EM runs at priority 100, we run at priority 50
	 *
	 * @param array $data Sanitized post data
	 * @param array $postarr Raw post data
	 * @return array Modified post data
	 */
	public static function fix_empty_post_name_before_em( $data, $postarr ) {
		// Skip autosaves and revisions
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		if ( wp_is_post_revision( $postarr['ID'] ?? 0 ) ) {
			return $data;
		}

		// Only for Events Manager post types
		if ( ! self::is_em_post_type( $data['post_type'] ) ) {
			return $data;
		}

		// Check if post_name is empty or problematic
		$needs_fix = false;
		$post_id = $postarr['ID'] ?? 0;

		if ( empty( $data['post_name'] ) ) {
			$needs_fix = true;
		} elseif ( $post_id && is_numeric( $data['post_name'] ) && (int) $data['post_name'] === $post_id ) {
			$needs_fix = true;
		}

		// Fix it by generating from title
		if ( $needs_fix && ! empty( $data['post_title'] ) ) {
			$slug = sanitize_title( $data['post_title'] );

			if ( empty( $slug ) ) {
				$slug = $data['post_type'] . '-' . $post_id;
			}

			// Make it unique
			$unique_slug = wp_unique_post_slug(
				$slug,
				$post_id,
				$data['post_status'],
				$data['post_type'],
				$data['post_parent'] ?? 0
			);

			$data['post_name'] = $unique_slug;

			// Log for debugging
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'[EM WPML Fix] Fixed empty post_name for %s ID %d: "" → "%s"',
					$data['post_type'],
					$post_id,
					$unique_slug
				) );
			}
		}

		return $data;
	}

	/**
	 * Fix empty post_name in posts array before Events Manager processes them
	 * This catches cases where EM loads posts directly
	 *
	 * @param array $posts Array of post objects
	 * @param WP_Query $query Query object
	 * @return array Modified posts array
	 */
	public static function fix_posts_array( $posts, $query ) {
		if ( empty( $posts ) || ! is_array( $posts ) ) {
			return $posts;
		}

		foreach ( $posts as $post ) {
			if ( ! self::is_em_post_type( $post->post_type ) ) {
				continue;
			}

			// Check if post_name is empty or problematic
			$needs_fix = false;

			if ( empty( $post->post_name ) ) {
				$needs_fix = true;
			} elseif ( is_numeric( $post->post_name ) && (int) $post->post_name === $post->ID ) {
				$needs_fix = true;
			}

			// Fix by generating from title
			if ( $needs_fix && ! empty( $post->post_title ) ) {
				$slug = sanitize_title( $post->post_title );

				if ( empty( $slug ) ) {
					$slug = $post->post_type . '-' . $post->ID;
				}

				$unique_slug = wp_unique_post_slug(
					$slug,
					$post->ID,
					$post->post_status,
					$post->post_type,
					$post->post_parent
				);

				// Update the post object
				$post->post_name = $unique_slug;

				// Also update in database
				global $wpdb;
				$wpdb->update(
					$wpdb->posts,
					array( 'post_name' => $unique_slug ),
					array( 'ID' => $post->ID ),
					array( '%s' ),
					array( '%d' )
				);

				// Clear cache
				clean_post_cache( $post->ID );

				// Log for debugging
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf(
						'[EM WPML Fix] Fixed empty post_name in posts array for %s ID %d: "" → "%s"',
						$post->post_type,
						$post->ID,
						$unique_slug
					) );
				}
			}
		}

		return $posts;
	}

	/**
	 * Check if post type is an Events Manager post type
	 *
	 * @param string $post_type Post type
	 * @return bool
	 */
	private static function is_em_post_type( $post_type ) {
		if ( ! $post_type ) {
			return false;
		}

		// Events Manager post types
		$em_post_types = array();

		if ( defined( 'EM_POST_TYPE_EVENT' ) ) {
			$em_post_types[] = EM_POST_TYPE_EVENT;
		}

		if ( defined( 'EM_POST_TYPE_LOCATION' ) ) {
			$em_post_types[] = EM_POST_TYPE_LOCATION;
		}

		// Also include recurring events
		$em_post_types[] = 'event-recurring';

		// Also support regular pages/posts if they're affected
		// (Sometimes WPML duplicates affect regular posts too)
		$em_post_types[] = 'page';
		$em_post_types[] = 'post';

		return in_array( $post_type, $em_post_types, true );
	}
}

// Initialize the fix
Fix_Events_Manager_WPML_Slug::init();
