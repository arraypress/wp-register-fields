<?php
/**
 * Term Fields
 *
 * @package     ArrayPress\RegisterFields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterFields;

use ArrayPress\FieldKit\Assets;
use ArrayPress\FieldKit\Context\EncryptedContext;
use ArrayPress\FieldKit\Context\TermMetaContext;
use ArrayPress\FieldKit\Contracts\Context;
use ArrayPress\FieldKit\Field;
use ArrayPress\FieldKit\FieldSet;
use ArrayPress\FieldKit\Registry;
use ArrayPress\FieldKit\Support\Badge;
use ArrayPress\FieldKit\Support\Markup;
use ArrayPress\FieldKit\Support\Sections;
use ArrayPress\FieldKit\Support\Tooltip;
use Exception;
use WP_Term;

/**
 * Registers custom fields on a taxonomy's add and edit screens.
 *
 * Rendering, sanitizing, conditional logic and accessibility all come from
 * wp-field-kit. What is left here is what is genuinely specific to terms: the
 * four hooks WordPress fires, the two different wrappers the add and edit
 * screens want, and the companion meta key an amount field writes its unit
 * to.
 *
 * That is the whole point of the split. This class carried its own renderer,
 * its own sanitizer and its own eight-type switch, and supported eight of the
 * fifty-odd types its sibling libraries did. It now supports all of them.
 */
class TermFields {

	/**
	 * Where values are read from and written to.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The taxonomy these fields belong to.
	 *
	 * @var string
	 */
	private string $taxonomy;

	/**
	 * This field set's identifier.
	 *
	 * Derived from the taxonomy, so the nonce that guards the save and the
	 * scope that keeps one taxonomy's tab strip from driving another's panels
	 * are both unique to it.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Field configuration, keyed by meta key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * The field set doing the work.
	 *
	 * @var FieldSet
	 */
	private FieldSet $set;

	/**
	 * Registered instances, so fields can be read back by taxonomy.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = [];

	/**
	 * Construct.
	 *
	 * @param string                              $taxonomy The taxonomy slug.
	 * @param array<string, array<string, mixed>> $fields   Field configuration.
	 *
	 * @throws Exception If the taxonomy is empty.
	 */
	public function __construct( string $taxonomy, array $fields ) {
		self::declare_config_keys();

		if ( '' === trim( $taxonomy ) ) {
			throw new Exception( 'Taxonomy cannot be empty.' );
		}

		$this->taxonomy = $taxonomy;
		$this->id       = 'term-' . $taxonomy;
		$this->fields   = $fields;
		// One context, shared by every field set this class builds. The
		// encrypting decorator is what makes `encrypted` mean anything:
		// without it the flag rendered a password control, had REST exposure
		// refused on its account, and stored the value in plain text.
		$this->context = new EncryptedContext( new TermMetaContext() );

		$this->set      = new FieldSet( $fields, $this->context, '', new Registry() );

		self::$instances[ $taxonomy ] = $this;

		// Registered before admin_init on a plain include, and immediately
		// when something registers fields later than that.
		if ( did_action( 'admin_init' ) ) {
			$this->load_hooks();
		} else {
			add_action( 'admin_init', [ $this, 'load_hooks' ] );
		}

		// On init, not admin_init: a registered meta key is how REST, the
		// block editor and the capability check learn the field exists, and
		// all three run on requests that never reach the admin.
		if ( did_action( 'init' ) ) {
			$this->register_meta();
		} else {
			add_action( 'init', [ $this, 'register_meta' ] );
		}
	}

	/**
	 * Declare this taxonomy's meta keys to WordPress.
	 *
	 * Saving works without it. What does not work without it is everything
	 * that asks WordPress *about* the meta — get_registered_meta_keys(), the
	 * REST API, the block editor, and the capability check for writing a key
	 * — and, most usefully, the sanitize callback: with one registered, a
	 * value written by update_term_meta() from anywhere passes through the
	 * field's own type, exactly as a submitted one does.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$this->set->register_meta( $this->taxonomy );
	}

	/**
	 * Attach the taxonomy's hooks.
	 *
	 * @return void
	 */
	public function load_hooks(): void {
		add_action( "{$this->taxonomy}_add_form_fields", [ $this, 'render_add_form' ] );
		add_action( "{$this->taxonomy}_edit_form_fields", [ $this, 'render_edit_form' ], 10, 2 );
		add_action( "created_{$this->taxonomy}", [ $this, 'save' ] );
		add_action( "edited_{$this->taxonomy}", [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the kit on this taxonomy's screens only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit-tags' !== $screen->base && 'term' !== $screen->base ) {
			return;
		}

		if ( $screen->taxonomy !== $this->taxonomy ) {
			return;
		}

		( new Assets() )->enqueue( $this->set->dependencies() );
	}

	/**
	 * Render the fields on the add-term screen.
	 *
	 * The add screen wraps each field in a div; the edit screen uses a table
	 * row. Everything inside the wrapper is identical, which is why only the
	 * wrapper differs here.
	 *
	 * @return void
	 */
	public function render_add_form(): void {
		wp_nonce_field( 'save_' . $this->id, $this->id . '_nonce' );

		// No messages here. The add form posts over AJAX and is never
		// reloaded, and a refused value belongs to the term that was just
		// created — its edit screen is where the message is read.
		$fields = $this->visible_fields();
		$layout = Sections::split( $fields );

		$render = function ( array $group ): string {
			$markup = '';

			foreach ( $group as $field ) {
				if ( Sections::is_marker( $field ) ) {
					continue;
				}

				$markup .= sprintf(
					'<div class="form-field term-%s-wrap">%s</div>',
					esc_attr( $field->key() ),
					// The kit escapes every value at the point it builds an
					// attribute, so this is already safe markup; a kses pass
					// here would only strip valid output.
					$this->set->render_field( $field ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see comment.
				);
			}

			return $markup;
		};

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		echo [] === $layout ? $render( $fields ) : Sections::render( $layout, $render, $this->id . '-add' );
	}

	/**
	 * Render the fields on the edit-term screen.
	 *
	 * @param WP_Term $term     The term being edited.
	 * @param string  $taxonomy The taxonomy.
	 *
	 * @return void
	 */
	public function render_edit_form( WP_Term $term, string $taxonomy ): void {
		// Inside the table core opened, so the nonce gets a row of its own
		// rather than sitting loose between two.
		echo '<tr class="hidden"><td colspan="2">';
		wp_nonce_field( 'save_' . $this->id, $this->id . '_nonce' );
		echo '</td></tr>';

		// What the last save of this term refused, if this user made it and
		// the screen has not shown it yet. In a row of its own, for the same
		// reason the nonce is: these rows go into a table core opened.
		$errors = Errors::recall( 'term', $term->term_id, $this->id );

		if ( [] !== $errors ) {
			printf(
				'<tr class="form-field field-kit__spans-row"><td colspan="2">%s</td></tr>',
				Errors::notice( $errors ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			);
		}

		$fields = $this->visible_fields( $term->term_id );
		$layout = Sections::split( $fields );

		if ( [] !== $layout ) {
			/*
			 * These rows go into a table core opened, so a <details> or a tab
			 * strip cannot sit between them -- neither is table content, and
			 * a browser hoists it out of the table entirely. The whole set of
			 * sections therefore goes inside one row that spans both columns,
			 * with each section holding a form-table of its own. That is the
			 * same shape a spans_row() field already gets here.
			 */
			$sections = Sections::render(
				$layout,
				fn( array $group ): string => [] === $group ? '' : $this->render_edit_table( $group, $errors ),
				$this->id
			);

			printf(
				'<tr class="form-field field-kit__spans-row"><td colspan="2">%s</td></tr>',
				$sections // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			);

			return;
		}

		$this->render_edit_rows( $fields, $errors );
	}

	/**
	 * One section's fields, as a table of their own.
	 *
	 * @param \ArrayPress\FieldKit\Field[] $fields The fields.
	 * @param array<string, string>        $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private function render_edit_table( array $fields, array $errors ): string {
		ob_start();

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_edit_rows( $fields, $errors );
		echo '</tbody></table>';

		return (string) ob_get_clean();
	}

	/**
	 * The fields as table rows.
	 *
	 * @param \ArrayPress\FieldKit\Field[] $fields The fields.
	 * @param array<string, string>        $errors Messages from the last save, keyed by field.
	 *
	 * @return void
	 */
	private function render_edit_rows( array $fields, array $errors ): void {
		foreach ( $fields as $field ) {
			// A marker draws nothing, so one left in emits an empty row.
			if ( Sections::is_marker( $field ) ) {
				continue;
			}

			// A layout field or a panel has no label to sit beside: a header
			// cell would indent it as though it labelled a control.
			if ( $field->type()->spans_row() ) {
				printf(
					'<tr class="form-field field-kit__spans-row term-%s-wrap"><td colspan="2">%s</td></tr>',
					esc_attr( $field->key() ),
					$this->set->render_field( $field, '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
				);

				continue;
			}

			// A self-labelling control — a checkbox or a toggle — already
			// carries its own <label for>. Adding a second one pointing at
			// the same control makes the field announce twice, so the header
			// cell gets plain text and the control keeps its own label.
			$labels_itself = $field->type()->is_self_labelling();

			// A group of controls has no single element to point at, so its
			// heading is the fieldset's legend rather than a label.
			$grouped = $field->type()->is_grouped();

			// A badge belongs beside the heading, and on this screen the
			// heading is here — the kit is told to draw none, so it draws no
			// badge either.
			$badge = Badge::for_field( $field );

			// Beside the heading, which this class draws rather than the kit.
			$tooltip = Tooltip::for_field( $field );

			$header = $labels_itself || $grouped
				? sprintf(
					'<span class="field-kit__row-label">%s%s%s</span>',
					esc_html( $field->label() ),
					Markup::required_marker( $field->is_required() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
					$badge . $tooltip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
				)
				: sprintf(
					'<label for="%s">%s%s</label>%s',
					esc_attr( $field->input_id() ),
					esc_html( $field->label() ),
					Markup::required_marker( $field->is_required() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
					$badge . $tooltip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
				);

			printf(
				'<tr class="form-field term-%s-wrap"><th scope="row">%s</th><td>%s</td></tr>',
				esc_attr( $field->key() ),
				$header, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/esc_attr above.
				// The header cell is the visible heading on this screen, so
				// the kit draws none: a self-labelling control keeps its own
				// text beside the box, and a group keeps its legend but
				// hidden, so the grouping is still announced without the
				// heading appearing twice.
				$this->set->render_field( $field, $errors[ $field->key() ] ?? '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
			);
		}
	}

	/**
	 * Save a term's fields.
	 *
	 * Hooked to created_ and edited_, which fire for every write to a term
	 * there is — Quick Edit on the list table, a term-order plugin, a plain
	 * wp_update_term() from anywhere — and none of those carry these fields.
	 * The set reads an absent field as cleared, so without a nonce of this
	 * form's own, any of them would wipe every value the term had.
	 *
	 * @param int $term_id The term id.
	 *
	 * @return void
	 */
	public function save( int $term_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line.
		$nonce = isset( $_POST[ $this->id . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $this->id . '_nonce' ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'save_' . $this->id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$input = $_POST;

		// Only what this user may set. A field they cannot see is not one a
		// crafted submission gets to write either — the screen hides it, and
		// without this the hiding would be the only thing stopping it.
		$allowed = $this->permitted_fields( $term_id );
		$set     = $this->permitted_set( $allowed );

		$set->save( $input, $term_id );
		$this->save_amount_units( $term_id, $input, $allowed );

		// A refused value is left as it was and the rest are stored. Core
		// redirects before anything here could say so, so the messages wait
		// for the next load of this term's edit screen — which is also where
		// they go for a term the add form just created over AJAX.
		Errors::remember( 'term', $term_id, $this->id, $set->errors() );
	}

	/**
	 * The field configuration limited to what the current user may write.
	 *
	 * Decided by the same callback the screens draw with, so "may they see
	 * it" and "may they set it" cannot drift apart.
	 *
	 * @param int $term_id The term being saved.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function permitted_fields( int $term_id ): array {
		$keys = array_map(
			static fn( Field $field ): string => $field->key(),
			$this->visible_fields( $term_id )
		);

		return array_intersect_key( $this->fields, array_flip( $keys ) );
	}

	/**
	 * A field set limited to the given fields.
	 *
	 * The full set is reused when nothing was withheld. A narrower one is
	 * built on the same context, because the encrypting decorator lives
	 * there and a set without it writes an encrypted field in the clear.
	 *
	 * @param array<string, array<string, mixed>> $allowed Field configuration.
	 *
	 * @return FieldSet
	 */
	private function permitted_set( array $allowed ): FieldSet {
		return $allowed === $this->fields
			? $this->set
			: new FieldSet( $allowed, $this->context, '', new Registry() );
	}

	/**
	 * Write the companion unit an amount field stores separately.
	 *
	 * The amount and its unit live under two meta keys because consumers
	 * query and sort on the unit independently. The field set writes the
	 * amount; only this context knows where the unit goes.
	 *
	 * @param int                                 $term_id The term id.
	 * @param array<string, mixed>                $input   Raw submission.
	 * @param array<string, array<string, mixed>> $fields  The fields this user may write.
	 *
	 * @return void
	 */
	private function save_amount_units( int $term_id, array $input, array $fields ): void {
		foreach ( $fields as $key => $config ) {
			if ( 'amount_type' !== ( $config['type'] ?? '' ) ) {
				continue;
			}

			$unit_key = (string) ( $config['type_meta_key'] ?? $key . '_type' );
			$options  = $config['type_options'] ?? [];
			$options  = is_callable( $options ) ? $options() : $options;
			$allowed  = array_map( 'strval', array_keys( (array) $options ) );
			$posted   = sanitize_text_field( wp_unslash( (string) ( $input[ $unit_key ] ?? '' ) ) );

			if ( in_array( $posted, $allowed, true ) ) {
				update_term_meta( $term_id, $unit_key, $posted );
			}
		}
	}

	/**
	 * The fields this user may see, with their values loaded.
	 *
	 * @param int $term_id The term id, or 0 on the add screen.
	 *
	 * @return \ArrayPress\FieldKit\Field[]
	 */
	private function visible_fields( int $term_id = 0 ): array {
		return array_values(
			array_filter(
				$this->set->fields( $term_id ),
				function ( $field ) {
					$callback = $field->get( 'permission_callback' );

					return ! is_callable( $callback ) || (bool) $callback( $field );
				}
			)
		);
	}


	/**
	 * Get the registered field configuration for a taxonomy.
	 *
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_fields( string $taxonomy ): array {
		return isset( self::$instances[ $taxonomy ] ) ? self::$instances[ $taxonomy ]->fields : [];
	}

	/**
	 * Get the instance registered for a taxonomy.
	 *
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return self|null
	 */
	public static function get_instance( string $taxonomy ): ?self {
		return self::$instances[ $taxonomy ] ?? null;
	}

	/**
	 * Tell the kit which configuration this library reads.
	 *
	 * A term field can gate itself with a callback of its own, which this
	 * class calls and the kit knows nothing about. Without this the kit
	 * would report each of them as configuration nothing reads — which is
	 * the warning it exists to give, aimed at the wrong thing.
	 *
	 * @return void
	 */
	private static function declare_config_keys(): void {
		static $declared = false;

		if ( $declared ) {
			return;
		}

		$declared = true;

		Field::allow_config_keys( [ 'permission_callback' ] );
	}

	/**
	 * Read one field's value, through this set's context.
	 *
	 * The reason to use this rather than get_term_meta(): the context is
	 * where decryption happens and where a field's configured default comes
	 * from. Reading the meta directly hands back ciphertext for an encrypted
	 * field and an empty string for one that has never been saved, both of
	 * which look like ordinary values to the caller.
	 *
	 * @param int    $term_id  The term id.
	 * @param string $key       Field key.
	 * @param mixed  $fallback  Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	public function get_value( int $term_id, string $key, mixed $fallback = null ): mixed {
		$field = $this->set->field( $key, $term_id );

		if ( null === $field ) {
			return $fallback;
		}

		$value = $field->value();

		return null === $value || '' === $value ? $fallback : $value;
	}
}
