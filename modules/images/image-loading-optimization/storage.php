<?php
/**
 * Metrics storage.
 *
 * @package performance-lab
 * @since n.e.x.t
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'ILO_PAGE_METRICS_POST_TYPE', 'ilo_page_metrics' );

/**
 * Gets the TTL for the page metric storage lock.
 *
 * @return int TTL.
 */
function ilo_get_page_metric_storage_lock_ttl() {

	/**
	 * Filters how long a given IP is locked from submitting another metric-storage REST API request.
	 *
	 * Filtering the TTL to zero will disable any metric storage locking. This is useful during development.
	 *
	 * @param int $ttl TTL.
	 */
	return (int) apply_filters( 'ilo_metrics_storage_lock_ttl', MINUTE_IN_SECONDS );
}

/**
 * Gets the breakpoint max widths to group page metrics for various viewports.
 *
 * Each max with represents the maximum width (inclusive) for a given breakpoint. So if there is one number, 480, then
 * this means there will be two viewport groupings, one for 0<=480, and another >480. If instead there were three
 * provided breakpoints (320, 480, 576) then this means there will be four viewport groupings:
 *
 *  1. 0-320 (small smartphone)
 *  2. 321-480 (normal smartphone)
 *  3. 481-576 (phablets)
 *  4. >576 (desktop)
 *
 * @return int[] Breakpoint max widths, sorted in ascending order.
 */
function ilo_get_breakpoint_max_widths() {

	/**
	 * Filters the breakpoint max widths to group page metrics for various viewports.
	 *
	 * @param int[] $breakpoint_max_widths Max widths for viewport breakpoints.
	 */
	$breakpoint_max_widths = array_map(
		static function ( $breakpoint_max_width ) {
			return (int) $breakpoint_max_width;
		},
		(array) apply_filters( 'ilo_viewport_breakpoint_max_widths', array( 480 ) )
	);

	sort( $breakpoint_max_widths );
	return $breakpoint_max_widths;
}

/**
 * Gets transient key for locking page metric storage (for the current IP).
 *
 * @todo Should the URL be included in the key? Or should a user only be allowed to store one metric?
 * @return string Transient key.
 */
function ilo_get_page_metric_storage_lock_transient_key() {
	$ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
	return 'page_metrics_storage_lock_' . wp_hash( $ip_address );
}

/**
 * Sets page metric storage lock (for the current IP).
 */
function ilo_set_page_metric_storage_lock() {
	$ttl = ilo_get_page_metric_storage_lock_ttl();
	$key = ilo_get_page_metric_storage_lock_transient_key();
	if ( 0 === $ttl ) {
		delete_transient( $key );
	} else {
		set_transient( $key, time(), $ttl );
	}
}

/**
 * Checks whether page metric storage is locked (for the current IP).
 *
 * @return bool Whether locked.
 */
function ilo_is_page_metric_storage_locked() {
	$ttl = ilo_get_page_metric_storage_lock_ttl();
	if ( 0 === $ttl ) {
		return false;
	}
	$locked_time = (int) get_transient( ilo_get_page_metric_storage_lock_transient_key() );
	if ( 0 === $locked_time ) {
		return false;
	}
	return time() - $locked_time < $ttl;
}

/**
 * Register post type for page metrics storage.
 *
 * This the configuration for this post type is similar to the oembed_cache in core.
 */
function ilo_register_page_metrics_post_type() {
	register_post_type(
		ILO_PAGE_METRICS_POST_TYPE,
		array(
			'labels'           => array(
				'name'          => __( 'Page Metrics', 'performance-lab' ),
				'singular_name' => __( 'Page Metrics', 'performance-lab' ),
			),
			'public'           => false,
			'hierarchical'     => false,
			'rewrite'          => false,
			'query_var'        => false,
			'delete_with_user' => false,
			'can_export'       => false,
			'supports'         => array( 'title' ), // The original URL is stored in the post_title, and the MD5 hash in the post_name.
		)
	);
}
add_action( 'init', 'ilo_register_page_metrics_post_type' );

/**
 * Gets desired sample size for a viewport's page metrics.
 *
 * @return int
 */
function ilo_get_page_metrics_breakpoint_sample_size() {
	/**
	 * Filters desired sample size for a viewport's page metrics.
	 *
	 * @param int $sample_size Sample size.
	 */
	return (int) apply_filters( 'ilo_page_metrics_viewport_sample_size', 10 );
}

/**
 * Gets slug for page metrics post.
 *
 * @param string $url URL.
 * @return string Slug for URL.
 */
function ilo_get_page_metrics_slug( $url ) {
	return md5( $url );
}

/**
 * Get page metrics post.
 *
 * @param string $url URL.
 * @return WP_Post|null Post object if exists.
 */
function ilo_get_page_metrics_post( $url ) {
	$post_query = new WP_Query(
		array(
			'post_type'              => ILO_PAGE_METRICS_POST_TYPE,
			'post_status'            => 'publish',
			'name'                   => ilo_get_page_metrics_slug( $url ),
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'cache_results'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'lazy_load_term_meta'    => false,
		)
	);

	$post = array_shift( $post_query->posts );
	if ( $post instanceof WP_Post ) {
		return $post;
	} else {
		return null;
	}
}

/**
 * Parses post content in page metrics post.
 *
 * @param WP_Post $post Page metrics post.
 * @return array|WP_Error Page metrics when valid, or WP_Error otherwise.
 */
function ilo_parse_stored_page_metrics( WP_Post $post ) {
	$page_metrics = json_decode( $post->post_content, true );
	if ( json_last_error() ) {
		return new WP_Error(
			'page_metrics_json_parse_error',
			sprintf(
				/* translators: 1: Post type slug, 2: JSON error message */
				__( 'Contents of %1$s post type not valid JSON: %2$s', 'performance-lab' ),
				ILO_PAGE_METRICS_POST_TYPE,
				json_last_error_msg()
			)
		);
	}
	if ( ! is_array( $page_metrics ) ) {
		return new WP_Error(
			'page_metrics_invalid_data_format',
			sprintf(
				/* translators: %s is post type slug */
				__( 'Contents of %s post type was not a JSON array.', 'performance-lab' ),
				ILO_PAGE_METRICS_POST_TYPE
			)
		);
	}
	return $page_metrics;
}

/**
 * Groups page metrics by breakpoint.
 *
 * @param array $page_metrics Page metrics.
 * @param int[] $breakpoints  Viewport breakpoint max widths, sorted in ascending order.
 * @return array Grouped page metrics.
 */
function ilo_group_page_metrics_by_breakpoint( array $page_metrics, array $breakpoints ) {
	$max_index          = count( $breakpoints );
	$groups             = array_fill( 0, $max_index + 1, array() );
	$largest_breakpoint = $breakpoints[ $max_index - 1 ];
	foreach ( $page_metrics as $page_metric ) {
		if ( ! isset( $page_metric['viewport']['width'] ) ) {
			continue;
		}
		$viewport_width = $page_metric['viewport']['width'];
		if ( $viewport_width > $largest_breakpoint ) {
			$groups[ $max_index ][] = $page_metric;
		}
		foreach ( $breakpoints as $group => $breakpoint ) {
			if ( $viewport_width <= $breakpoint ) {
				$groups[ $group ][] = $page_metric;
			}
		}
	}
	return $groups;
}

/**
 * Stores page metric by merging it with the other page metrics for a given URL.
 *
 * The $validated_page_metric parameter has the following array shape:
 *
 * {
 *      'url': string,
 *      'viewport': array{
 *          'width': int,
 *          'height': int
 *      },
 *      'elements': array
 * }
 *
 * @param array $validated_page_metric Page metric, already validated by REST API.
 *
 * @return int|WP_Error Post ID or WP_Error otherwise.
 */
function ilo_store_page_metric( array $validated_page_metric ) {
	$url = $validated_page_metric['url'];
	unset( $validated_page_metric['url'] ); // Not stored in post_content but rather in post_title/post_name.
	$validated_page_metric['timestamp'] = time();

	// TODO: What about storing a version identifier?
	$post_data = array(
		'post_title' => $url,
	);

	$post = ilo_get_page_metrics_post( $url );

	if ( $post instanceof WP_Post ) {
		$post_data['ID']        = $post->ID;
		$post_data['post_name'] = $post->post_name;

		$page_metrics = ilo_parse_stored_page_metrics( $post );
		if ( $page_metrics instanceof WP_Error ) {
			if ( function_exists( 'wp_trigger_error' ) ) {
				wp_trigger_error( __FUNCTION__, esc_html( $page_metrics->get_error_message() ) );
			}
			$page_metrics = array();
		}
	} else {
		$post_data['post_name'] = ilo_get_page_metrics_slug( $url );
		$page_metrics           = array();
	}

	// Add the provided page metric to the page metrics.
	array_unshift( $page_metrics, $validated_page_metric );
	$breakpoints          = ilo_get_breakpoint_max_widths();
	$sample_size          = ilo_get_page_metrics_breakpoint_sample_size();
	$grouped_page_metrics = ilo_group_page_metrics_by_breakpoint( $page_metrics, $breakpoints );

	foreach ( $grouped_page_metrics as &$breakpoint_page_metrics ) {
		if ( count( $breakpoint_page_metrics ) > $sample_size ) {
			$breakpoint_page_metrics = array_slice( $breakpoint_page_metrics, 0, $sample_size );
		}
	}

	$page_metrics = array_merge( ...$grouped_page_metrics );

	// TODO: Also need to capture the current theme and template which can be used to invalidate the cached page metrics.
	$post_data['post_content'] = wp_json_encode( $page_metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // TODO: No need for pretty-printing.

	$has_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
	if ( $has_kses ) {
		// Prevent KSES from corrupting JSON in post_content.
		kses_remove_filters();
	}

	$post_data['post_type']   = ILO_PAGE_METRICS_POST_TYPE;
	$post_data['post_status'] = 'publish';
	if ( isset( $post_data['ID'] ) ) {
		$result = wp_update_post( wp_slash( $post_data ), true );
	} else {
		$result = wp_insert_post( wp_slash( $post_data ), true );
	}

	if ( $has_kses ) {
		kses_init_filters();
	}

	return $result;
}
