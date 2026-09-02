<?php
/**
 * Save Errors
 *
 * @package     ArrayPress\RegisterFields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterFields;

use ArrayPress\FieldKit\Utils\Runtime;

/**
 * Carries a save's validation messages to the next load of the screen.
 *
 * A metabox, a term form and a profile form all save on a hook core fires
 * before it redirects, so the request that refused a value is never the one
 * that draws the form again, and the set that refused it is gone by then.
 * What survives is a transient: written after the save under the user, the
 * object and the set, read and deleted by the render that follows, and gone
 * on its own a minute later if nothing follows.
 *
 * Keyed by the user as well as the object, because the messages are about
 * the values this person posted. Another editor opening the same post a
 * moment later has done nothing wrong and should not be told otherwise.
 */
final class Errors {

	/**
	 * Keep a save's messages for the next load of the object's screen.
	 *
	 * A clean save clears what an earlier one left. The block editor saves
	 * a metabox without redrawing it, so a field corrected on the second
	 * attempt would otherwise still be reported on the next full load.
	 *
	 * @param string                $type      Object type: post, term or user.
	 * @param int                   $object_id The object.
	 * @param string                $set       Which set on that screen saved.
	 * @param array<string, string> $errors    Messages keyed by field.
	 *
	 * @return void
	 */
	public static function remember( string $type, int $object_id, string $set, array $errors ): void {
		$key = self::key( $type, $object_id, $set );

		if ( [] === $errors ) {
			delete_transient( $key );

			return;
		}

		set_transient( $key, $errors, MINUTE_IN_SECONDS );
	}

	/**
	 * The messages waiting for this screen, read once.
	 *
	 * Deleted as they are read, so a refresh does not repeat them: the form
	 * now shows the stored values, which are the ones that passed.
	 *
	 * @param string $type      Object type: post, term or user.
	 * @param int    $object_id The object.
	 * @param string $set       Which set on that screen is drawing.
	 *
	 * @return array<string, string> Messages keyed by field. Empty when
	 *                               nothing is waiting.
	 */
	public static function recall( string $type, int $object_id, string $set ): array {
		$key    = self::key( $type, $object_id, $set );
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		delete_transient( $key );

		$errors = [];

		foreach ( $stored as $field => $message ) {
			$errors[ (string) $field ] = (string) $message;
		}

		return $errors;
	}

	/**
	 * One notice listing the messages, or nothing.
	 *
	 * `inline`, because common.js moves every other .notice to the top of
	 * the page under the heading — the right place for "Post updated." and
	 * the wrong place for a message about a field two screens of scrolling
	 * below it. Each message already names its field.
	 *
	 * @param array<string, string> $errors Messages keyed by field.
	 *
	 * @return string
	 */
	public static function notice( array $errors ): string {
		if ( [] === $errors ) {
			return '';
		}

		$lines = '';

		foreach ( $errors as $message ) {
			$lines .= sprintf( '<p>%s</p>', esc_html( $message ) );
		}

		return sprintf( '<div class="notice notice-error inline">%s</div>', $lines );
	}

	/**
	 * The transient name for one user, object and set.
	 *
	 * Hashed so the name has a fixed length whatever the set is called — a
	 * metabox id is the consumer's to choose, and a transient name is an
	 * option name with a limit. Prefixed through the kit's runtime so a
	 * plugin that bundles this library under its own namespace gets names
	 * of its own, and cannot read or delete another plugin's.
	 *
	 * @param string $type      Object type.
	 * @param int    $object_id The object.
	 * @param string $set       The set.
	 *
	 * @return string
	 */
	private static function key( string $type, int $object_id, string $set ): string {
		return Runtime::key(
			'errors_' . md5( implode( '|', [ get_current_user_id(), $type, $object_id, $set ] ) )
		);
	}
}
