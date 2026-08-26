<?php
/**
 * Shared stubs for the field registration tests.
 *
 * Merged from the five packages this library replaces. Each brought its own
 * copy with its own helper prefix -- be_ for bulk edit, qe_ for quick edit and
 * so on -- so all of them are kept. Everything is guarded, so where two copies
 * defined the same stub the first one wins and the rest are no-ops.
 *
 * @package ArrayPress\RegisterFields
 */

declare( strict_types=1 );


/* ---- from wp-register-post-fields ---- */

if ( ! function_exists( 'add_meta_box' ) ) {
	function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $args = null ) {
		foreach ( [ 'pf_boxes', 'qe_boxes', 'be_boxes' ] as $key ) {
			if ( isset( $GLOBALS[ $key ] ) ) {
				$GLOBALS[ $key ][] = compact( 'id', 'title', 'callback', 'screen', 'context', 'priority', 'args' );
			}
		}
	}
}


if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		foreach ( [ 'pf_nonce_ok', 'qe_nonce_ok', 'be_nonce_ok' ] as $key ) {
			if ( array_key_exists( $key, $GLOBALS ) ) {
				return (bool) $GLOBALS[ $key ];
			}
		}

		return true;
	}
}


if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		/*
		 * A screen left set by an earlier test would otherwise win over the one
		 * this test set, because the prefixes are checked in a fixed order. Only
		 * something that actually looks like a screen counts.
		 */
		foreach ( [ 'pf_screen', 'tf_screen', 'uf_screen', 'qe_screen', 'be_screen' ] as $key ) {
			$screen = $GLOBALS[ $key ] ?? null;

			if ( is_object( $screen ) && isset( $screen->id ) ) {
				return $screen;
			}
		}

		return null;
	}
}


if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		foreach ( [ 'pf_denied', 'tf_denied', 'uf_denied', 'qe_denied', 'be_denied' ] as $key ) {
			if ( in_array( $capability, (array) ( $GLOBALS[ $key ] ?? [] ), true ) ) {
				return false;
			}
		}

		return true;
	}
}


if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Reset every stubbed global between tests.
 *
 * @return void
 */
function pf_reset_globals(): void {
	$GLOBALS['fk_meta']          = [];
	$GLOBALS['fk_actions']       = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['pf_boxes']         = [];
	$GLOBALS['pf_screen']        = (object) [
		'base'      => 'post',
		'post_type' => 'post',
	];

	// Capability => denied. Anything unlisted is granted, so a test only says
	// what it is actually about.
	$GLOBALS['pf_denied'] = [];

	// Whether wp_verify_nonce() should accept what it is given.
	$GLOBALS['pf_nonce_ok'] = true;
}

pf_reset_globals();



if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fk_actions'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return 0;
	}
}

if ( ! function_exists( 'register_meta' ) ) {
	function register_meta( $object_type, $meta_key, $args = [] ) {
		$GLOBALS['fk_meta_registry'][ $object_type ][ $meta_key ] = $args;

		return true;
	}
}



if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = sprintf( '<input type="hidden" name="%s" value="nonce-%s" />', $name, (string) $action );

		if ( $display ) {
			echo $field;
		}

		return $field;
	}
}





if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
}

/**
 * The parts of WP_Post a metabox touches.
 */
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {

		/**
		 * The post id.
		 *
		 * @var int
		 */
		public int $ID = 0;

		/**
		 * The post type.
		 *
		 * @var string
		 */
		public string $post_type = 'post';
	}
}


/* ---- from wp-register-term-fields ---- */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Reset every stubbed global between tests.
 *
 * @return void
 */
function tf_reset_globals(): void {
	$GLOBALS['fk_meta']          = [];
	$GLOBALS['fk_actions']       = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['tf_screen']        = (object) [
		'id'       => 'edit-demo_tax',
		'taxonomy' => 'demo_tax',
		'base'     => 'edit-tags',
	];
	$GLOBALS['tf_denied']        = [];
}

tf_reset_globals();



if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fk_actions'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return 0;
	}
}

if ( ! function_exists( 'register_meta' ) ) {
	function register_meta( $object_type, $meta_key, $args = [] ) {
		$GLOBALS['fk_meta_registry'][ $object_type ][ $meta_key ] = $args;

		return true;
	}
}



/**
 * The parts of WP_Term a term screen touches.
 */
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {

		/**
		 * The term id.
		 *
		 * @var int
		 */
		public int $term_id = 0;

		/**
		 * The taxonomy.
		 *
		 * @var string
		 */
		public string $taxonomy = 'demo_tax';
	}
}


/* ---- from wp-register-user-fields ---- */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Reset every stubbed global between tests.
 *
 * @return void
 */
function uf_reset_globals(): void {
	$GLOBALS['fk_meta']         = [];
	$GLOBALS['fk_actions']      = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['uf_current_user'] = 1;
	$GLOBALS['uf_screen']       = 'profile';

	// Capability => whether the current user has it. Anything unlisted is
	// granted, so a test only says what it is actually about.
	$GLOBALS['uf_denied'] = [];
}

uf_reset_globals();

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['uf_current_user'] ?? 0 );
	}
}



if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fk_actions'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return 0;
	}
}

if ( ! function_exists( 'register_meta' ) ) {
	function register_meta( $object_type, $meta_key, $args = [] ) {
		$GLOBALS['fk_meta_registry'][ $object_type ][ $meta_key ] = $args;

		return true;
	}
}



if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

/**
 * The parts of WP_User a profile screen touches.
 */
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {

		/**
		 * The user id.
		 *
		 * @var int
		 */
		public int $ID = 0;
	}
}


/* ---- from wp-register-quick-edit-fields ---- */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Reset every stubbed global between tests.
 *
 * @return void
 */
function qe_reset_globals(): void {
	$GLOBALS['fk_meta']          = [];
	$GLOBALS['fk_actions']       = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['qe_boxes']         = [];
	$GLOBALS['qe_screen']        = (object) [
		'base'      => 'edit',
		'post_type' => 'post',
	];

	// Capability => denied. Anything unlisted is granted, so a test only says
	// what it is actually about.
	$GLOBALS['qe_denied'] = [];

	// Whether wp_verify_nonce() should accept what it is given.
	$GLOBALS['qe_nonce_ok']  = true;
	$GLOBALS['qe_post_type'] = 'post';
	$GLOBALS['fk_filters']   = [];
}

qe_reset_globals();



if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fk_actions'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return 0;
	}
}

if ( ! function_exists( 'register_meta' ) ) {
	function register_meta( $object_type, $meta_key, $args = [] ) {
		$GLOBALS['fk_meta_registry'][ $object_type ][ $meta_key ] = $args;

		return true;
	}
}



if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = sprintf( '<input type="hidden" name="%s" value="nonce-%s" />', $name, (string) $action );

		if ( $display ) {
			echo $field;
		}

		return $field;
	}
}





if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
}

/**
 * The parts of WP_Post a metabox touches.
 */

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		return $GLOBALS['qe_post_type'] ?? 'post';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$value = $GLOBALS['fk_meta']['post'][ $post_id ][ $key ] ?? '';

		return $single ? $value : (array) $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fk_filters'][ $hook ][] = $callback;

		return true;
	}
}


/* ---- from wp-register-bulk-edit-fields ---- */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Reset every stubbed global between tests.
 *
 * @return void
 */
function be_reset_globals(): void {
	$GLOBALS['fk_meta']          = [];
	$GLOBALS['fk_actions']       = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['be_boxes']         = [];
	$GLOBALS['be_screen']        = (object) [
		'base'      => 'edit',
		'post_type' => 'post',
	];

	// Capability => denied. Anything unlisted is granted, so a test only says
	// what it is actually about.
	$GLOBALS['be_denied'] = [];

	// Whether wp_verify_nonce() should accept what it is given.
	$GLOBALS['be_nonce_ok'] = true;
}

be_reset_globals();



if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fk_actions'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return 0;
	}
}

if ( ! function_exists( 'register_meta' ) ) {
	function register_meta( $object_type, $meta_key, $args = [] ) {
		$GLOBALS['fk_meta_registry'][ $object_type ][ $meta_key ] = $args;

		return true;
	}
}



if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = sprintf( '<input type="hidden" name="%s" value="nonce-%s" />', $name, (string) $action );

		if ( $display ) {
			echo $field;
		}

		return $field;
	}
}





if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
}

/**
 * The parts of WP_Post a metabox touches.
 */
