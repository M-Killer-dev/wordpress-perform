<?php
/**
 * Class 'PLSR_URL_Pattern_Prefixer'.
 *
 * @package performance-lab
 * @since n.e.x.t
 */

/**
 * Class for prefixing URL patterns.
 *
 * @since n.e.x.t
 */
class PLSR_URL_Pattern_Prefixer {

	/**
	 * Map of `$context_string => $base_path` pairs.
	 *
	 * @since n.e.x.t
	 * @var array
	 */
	private $contexts;

	/**
	 * Constructor.
	 *
	 * @since n.e.x.t
	 *
	 * @param array $contexts Optional. Map of `$context_string => $base_path` pairs. Default is the contexts returned
	 *                        by the {@see PLSR_URL_Pattern_Prefixer::get_default_contexts()} method.
	 */
	public function __construct( array $contexts = array() ) {
		if ( $contexts ) {
			$this->contexts = array_map(
				static function ( string $str ): string {
					return self::escape_pattern_string( trailingslashit( $str ) );
				},
				$contexts
			);
		} else {
			$this->contexts = self::get_default_contexts();
		}
	}

	/**
	 * Prefixes the given URL path pattern with the base path for the given context.
	 *
	 * This ensures that these path patterns work correctly on WordPress subdirectory sites, for example in a multisite
	 * network, or when WordPress itself is installed in a subdirectory of the hostname.
	 *
	 * The given URL path pattern is only prefixed if it does not already include the expected prefix.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $path_pattern URL pattern starting with the path segment.
	 * @param string $context      Optional. Either 'home' (any frontend content) or 'site' (content relative to the
	 *                             directory that WordPress is installed in). Default 'home'.
	 * @return string URL pattern, prefixed as necessary.
	 */
	public function prefix_path_pattern( string $path_pattern, string $context = 'home' ): string {
		// If context path does not exist, the context is invalid.
		if ( ! isset( $this->contexts[ $context ] ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				esc_html(
					sprintf(
						/* translators: %s: context string */
						__( 'Invalid context %s.', 'performance-lab' ),
						$context
					)
				),
				'Performance Lab n.e.x.t'
			);
			return $path_pattern;
		}

		// In the event that the context path contains a :, ? or # (which can cause the URL pattern parser to
		// switch to another state, though only the latter two should be percent encoded anyway), we need to
		// additionally enclose it in grouping braces. The final forward slash (trailingslashit ensures there is
		// one) affects the meaning of the * wildcard, so is left outside the braces.
		$context_path         = $this->contexts[ $context ];
		$escaped_context_path = $context_path;
		if ( strcspn( $context_path, ':?#' ) !== strlen( $context_path ) ) {
			$escaped_context_path = '{' . substr( $context_path, 0, -1 ) . '}/';
		}

		// If the path already starts with the context path (including '/'), remove it first
		// since it is about to be added back.
		if ( str_starts_with( $path_pattern, $context_path ) ) {
			$path_pattern = substr( $path_pattern, strlen( $context_path ) );
		}

		return $escaped_context_path . ltrim( $path_pattern, '/' );
	}

	/**
	 * Returns the default contexts used by the class.
	 *
	 * @since n.e.x.t
	 *
	 * @return array Map of `$context_string => $base_path` pairs.
	 */
	public static function get_default_contexts(): array {
		return array(
			'home' => self::escape_pattern_string( trailingslashit( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) ),
			'site' => self::escape_pattern_string( trailingslashit( wp_parse_url( site_url( '/' ), PHP_URL_PATH ) ) ),
		);
	}

	/**
	 * Escapes a string for use in a URL pattern component.
	 *
	 * @link https://urlpattern.spec.whatwg.org/#escape-a-pattern-string
	 * @since n.e.x.t
	 *
	 * @param string $str String to be escaped.
	 * @return string String with backslashes added where required.
	 */
	private static function escape_pattern_string( string $str ): string {
		return addcslashes( $str, '+*?:{}()\\' );
	}
}
