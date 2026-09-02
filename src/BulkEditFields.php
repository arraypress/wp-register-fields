<?php
/**
 * Bulk Edit Fields
 *
 * @package     ArrayPress\RegisterFields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterFields;

use ArrayPress\FieldKit\Assets;
use ArrayPress\FieldKit\Context\PostMetaContext;
use ArrayPress\FieldKit\Field;
use ArrayPress\FieldKit\FieldSet;
use ArrayPress\FieldKit\Registry;

/**
 * Adds fields to the bulk edit panel on a post type's list table.
 *
 * Bulk edit has a third state every other screen does without, and it is the
 * whole design of this class. A field is not "set to this" or "set to empty";
 * it is "set to this", "set to empty", or **leave alone** — and the last one
 * is the default, because a bulk edit of forty posts that quietly cleared a
 * field nobody touched would be the worst thing this library could do.
 *
 * So a control has to be able to say nothing, and a submission that says
 * nothing has to be distinguishable from one that says empty:
 *
 * - A text or number field is left alone when it is submitted empty. That is
 *   WordPress's own convention on this screen, and it costs the ability to
 *   clear one — which is why `allow_clear` exists.
 * - A choice — a select, a checkbox, a set of radios — grows a "no change"
 *   option that is selected by default. A checkbox cannot express three
 *   states, so it is rendered as a select of three.
 *
 * Which types can appear here at all is the kit's decision, not this
 * library's: `supports_inline()` knows that an editor cannot be started in a
 * cloned panel and that a gallery is not one row of a list table.
 */
class BulkEditFields {

	/**
	 * The column added when a post type has none of its own.
	 *
	 * @var string
	 */
	private const COLUMN = 'field-kit-inline';

	/**
	 * Whether the panel has already been drawn this request.
	 *
	 * @var bool
	 */
	private bool $rendered = false;

	/**
	 * The value meaning "leave this field alone".
	 *
	 * A string no real value can collide with, because a text field can
	 * contain anything — including the words "no change".
	 */
	private const NO_CHANGE = '__field_kit_no_change__';

	/**
	 * The value meaning "clear this field".
	 */
	private const CLEAR = '__field_kit_clear__';

	/**
	 * The post type these fields belong to.
	 *
	 * @var string
	 */
	private string $post_type;

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
	 * Registered instances by post type.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = [];

	/**
	 * Construct.
	 *
	 * @param array<string, array<string, mixed>> $fields    Field configuration.
	 * @param string                              $post_type The post type.
	 */
	public function __construct( array $fields, string $post_type ) {
		$this->post_type = $post_type;
		$this->fields    = $this->inline_only( $fields );
		$this->set       = new FieldSet( $this->fields, new PostMetaContext(), '', new Registry() );

		self::$instances[ $post_type ] = $this;

		if ( did_action( 'admin_init' ) ) {
			$this->load_hooks();
		} else {
			add_action( 'admin_init', [ $this, 'load_hooks' ] );
		}
	}

	/**
	 * Drop the fields that cannot live in a list-table row.
	 *
	 * Dropped rather than rendered badly. A wysiwyg in a bulk edit panel is
	 * not a smaller wysiwyg; it is a textarea that looks like a mistake, and
	 * a gallery is a grid in a place with no room for one.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function inline_only( array $fields ): array {
		$registry = new Registry();

		return array_filter(
			$fields,
			static function ( $config ) use ( $registry ) {
				$type = (string) ( $config['type'] ?? 'text' );

				return $registry->has( $type ) && $registry->get( $type )->supports_inline();
			}
		);
	}

	/**
	 * Attach the list table's hooks.
	 *
	 * @return void
	 */
	public function load_hooks(): void {
		add_action( 'bulk_edit_custom_box', [ $this, 'render' ], 10, 2 );

		// Without a column of some kind the hook above never fires.
		add_filter( "manage_{$this->post_type}_posts_columns", [ $this, 'ensure_column' ] );
		add_action( "manage_{$this->post_type}_posts_custom_column", [ $this, 'render_column' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the kit on this post type's list table only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit' !== $screen->base || $screen->post_type !== $this->post_type ) {
			return;
		}

		( new Assets() )->enqueue( $this->set->dependencies() );
	}

	/**
	 * Render the fields into the bulk edit panel.
	 *
	 * Core calls this once per column, so everything is drawn against the
	 * first one — otherwise a page with three custom columns would show the
	 * same fields three times.
	 *
	 * @param string $column_name The column being rendered.
	 * @param string $post_type   The post type.
	 *
	 * @return void
	 */
	public function render( string $column_name, string $post_type ): void {
		// Fired once per column, so this runs only for the first one: the
		// panel is a single form and the fields belong to it, not to a
		// column.
		//
		// It used to compare the column's name with the first field's key,
		// which is a different thing entirely — it only ever matched where a
		// list table happened to have a column named after a field, and drew
		// nothing at all otherwise.
		//
		// An instance property rather than a static, because a static inside
		// a method lives for the whole request: a second set registered
		// against the same post type would find the flag already set.
		if ( $post_type !== $this->post_type || [] === $this->fields || $this->rendered ) {
			return;
		}

		$this->rendered = true;

		echo '<fieldset class="inline-edit-col-right field-kit__bulk-edit"><div class="inline-edit-col">';

		foreach ( $this->set->fields() as $field ) {
			$this->render_field( $field );
		}

		echo '</div></fieldset>';
	}

	/**
	 * Render one field, with its way of saying "leave alone".
	 *
	 * @param Field $field The field.
	 *
	 * @return void
	 */
	private function render_field( Field $field ): void {
		$rendered = $this->set->render_field( $this->with_no_change( $field ), '', false );

		printf(
			'<label class="field-kit__bulk-edit-field"><span class="title">%s</span>' .
			'<span class="input-text-wrap">%s</span></label>%s',
			esc_html( $field->label() ),
			$rendered, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
			'' === $field->description()
				? ''
				: sprintf( '<p class="description">%s</p>', wp_kses_post( $field->description() ) )
		);
	}

	/**
	 * A copy of the field able to say "leave alone".
	 *
	 * A choice grows an option for it. A text field does not need one: empty
	 * already means "leave alone" here, which is WordPress's own convention
	 * on this screen — so it says so instead.
	 *
	 * @param Field $field The field.
	 *
	 * @return Field
	 */
	private function with_no_change( Field $field ): Field {
		$type = $field->type()->id();

		// A checkbox has two states and bulk edit needs three, so it is a
		// select of three here rather than a box that cannot say "no change".
		//
		// A new Field, not with_config(): the type is resolved when the field
		// is built, so changing `type` in the configuration afterwards
		// changes what the array says and not what renders.
		if ( in_array( $type, [ 'checkbox', 'toggle' ], true ) ) {
			$registry = new Registry();
			$select   = $registry->get( 'select' );

			return new Field(
				$field->key(),
				$select,
				array_merge(
					$select->defaults(),
					$field->config(),
					[
						'type'        => 'select',
						'options'     => [
							'1' => __( 'Yes', 'arraypress' ),
							'0' => __( 'No', 'arraypress' ),
						],
						'empty_label' => __( '— No change —', 'arraypress' ),
						'default'     => '',
					]
				),
				''
			);
		}

		if ( $field->type()->is_grouped() || $field->has( 'options' ) ) {
			return $field->with_config(
				[
					'empty_label' => __( '— No change —', 'arraypress' ),
					'default'     => '',
				]
			)->with_value( '' );
		}

		$description = trim(
			$field->description() . ' ' . __( 'Leave empty to make no change.', 'arraypress' )
		);

		return $field->with_config( [ 'description' => $description ] )->with_value( '' );
	}

	/**
	 * Save the bulk edit.
	 *
	 * Core checks the list table's nonce before it runs a bulk edit, but
	 * save_post fires for every save there is, and `bulk_edit` in a request
	 * is a word anyone can type. Checking the same nonce here is what says
	 * core did the checking.
	 *
	 * The request is read through $_REQUEST rather than $_POST because the
	 * list table's form is method="get": a bulk edit arrives as a query
	 * string, which is why core hands $_REQUEST to bulk_edit_posts() itself.
	 * WordPress rebuilds $_REQUEST as $_GET and $_POST with no cookies in
	 * it, so nothing else gets in that way.
	 *
	 * @param int      $post_id The post id.
	 * @param \WP_Post $post    The post.
	 *
	 * @return void
	 */
	public function save( int $post_id, $post ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		if ( ! isset( $_REQUEST['bulk_edit'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'bulk-posts' ) ) {
			return;
		}

		if ( ! is_object( $post ) || ( $post->post_type ?? '' ) !== $this->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$input = $this->changed( $_REQUEST );

		if ( [] === $input ) {
			return;
		}

		// Only the fields actually being changed. Handing the whole set to
		// save() would read every untouched field as cleared, which across
		// forty selected posts is the worst thing this library could do.
		$changing = array_intersect_key( $this->fields, $input );

		// A value that fails validation is skipped on each post — kept as it
		// was, with the rest stored — and nothing says so. The list table
		// redraws with forty results and no place to report which post
		// refused what, and a notice per post would be forty notices.
		( new FieldSet( $changing, new PostMetaContext(), '', new Registry() ) )->save( $input, $post_id );
	}

	/**
	 * The submitted values that mean a change.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed>
	 */
	private function changed( array $request ): array {
		$changed = [];

		foreach ( array_keys( $this->fields ) as $key ) {
			if ( ! isset( $request[ $key ] ) ) {
				continue;
			}

			$value = $request[ $key ];

			if ( self::NO_CHANGE === $value ) {
				continue;
			}

			// An explicit clear, for a field that opted into offering one.
			if ( self::CLEAR === $value ) {
				$changed[ $key ] = '';

				continue;
			}

			// Empty means "leave alone" unless the field said otherwise. It
			// is the convention on this screen, and it costs the ability to
			// clear a field — which is what allow_clear buys back.
			if ( ( '' === $value || [] === $value ) && ! ( $this->fields[ $key ]['allow_clear'] ?? false ) ) {
				continue;
			}

			$changed[ $key ] = $value;
		}

		return $changed;
	}

	/**
	 * Get the field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * Get one field's configuration.
	 *
	 * @param string $key The meta key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_field( string $key ): ?array {
		return $this->fields[ $key ] ?? null;
	}

	/**
	 * Get the fields registered for a post type.
	 *
	 * @param string $post_type The post type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_fields_for( string $post_type ): array {
		return self::$instances[ $post_type ]?->get_fields() ?? [];
	}

	/**
	 * Make sure there is a column for core to fire the hook against.
	 *
	 * `bulk_edit_custom_box` is fired once per column, and only for columns that
	 * are not core's own — so on a post type whose list table has no custom
	 * column at all, it never fires and none of these fields are rendered.
	 * Nothing says so: the fields are registered, the screen is right, and
	 * the panel is simply empty.
	 *
	 * So one is added when there is none. Its label is empty, which means
	 * core leaves it out of Screen Options — WP_Screen skips a column with no
	 * title — and the stylesheet hides the cells, so the table looks exactly
	 * as it did.
	 *
	 * @param array<string, string> $columns The list table's columns.
	 *
	 * @return array<string, string>
	 */
	public function ensure_column( array $columns ): array {
		// Core's own, which the hook is never fired for.
		$core = [ 'cb', 'title', 'author', 'categories', 'tags', 'comments', 'date' ];

		if ( [] !== array_diff( array_keys( $columns ), $core ) ) {
			return $columns;
		}

		$columns[ self::COLUMN ] = '';

		return $columns;
	}

	/**
	 * The added column renders nothing.
	 *
	 * @param string $column The column being rendered.
	 *
	 * @return void
	 */
	public function render_column( string $column ): void {
	}
}
