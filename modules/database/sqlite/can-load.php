<?php
/**
 * Can load function to determine if SQLite can be activated.
 *
 * @since 1.8.0
 * @package performance-lab
 */

/**
 * Checks whether the given module can be activated.
 *
 * @since 1.8.0
 */
return static function() {

	// If the PERFLAB_SQLITE_DB_DROPIN_VERSION constant is defined, then the module is already active.
	if ( defined( 'PERFLAB_SQLITE_DB_DROPIN_VERSION' ) ) {
		return true;
	}

	// If a db.php file already exists in the wp-content directory, then the module cannot be activated.
	// Except if it is the standalone plugin's drop-in.
	if ( file_exists( WP_CONTENT_DIR . '/db.php' ) && ! defined( 'SQLITE_DB_DROPIN_VERSION' ) ) {
		return false;
	}

	// If the SQLite3 class does not exist, then the module cannot be activated.
	if ( ! class_exists( 'SQLite3' ) ) {
		return false;
	}

	// If the db.php file can't be written to the wp-content directory, then the module cannot be activated.
	if ( ! wp_is_writable( WP_CONTENT_DIR ) ) {
		return false;
	}
	return true;
};
