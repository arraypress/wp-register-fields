<?php
/**
 * User Fields
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
use ArrayPress\FieldKit\Contracts\Context;
use ArrayPress\FieldKit\Context\UserMetaContext;
use ArrayPress\FieldKit\Field;
use ArrayPress\FieldKit\FieldSet;
use ArrayPress\FieldKit\Registry;
use ArrayPress\FieldKit\Support\Badge;
use ArrayPress\FieldKit\Support\Sections;
use ArrayPress\FieldKit\Support\Tooltip;
use WP_User;

/**
 * Registers custom fields on the user profile and add-user screens.
 *
 * Rendering, sanitizing, conditional logic and accessibility all come from
 * wp-field-kit. What is left here is what is genuinely specific to users: the
 * five hooks WordPress fires, the section heading a profile screen wants, and
 * the two ways a field can be hidden from one — whether the current user may
 * see it, and whether it appears on someone else's profile as well as their
 * own.
 *
 * That last distinction is why this cannot simply be term-fields with the
 * nouns changed. A profile screen is the one place in WordPress where the
 * object being edited may be the person editing it, and a field that is
 * fine for an administrator to set on someone else is not always one the
 * subject should set on themselves.
 */
class UserFields {

	/**
	 * Where values are read from and written to.
	 *
	 * Built once and shared by every field set this class makes. It used to
	 * be constructed at each `new FieldSet()`, and the one built for a
	 * capability-restricted set was missing the encrypting decorator — so an
	 * encrypted field saved by a user whose permissions narrowed the set was
	 * written in plain text, and only then.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Field configuration, keyed by meta key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * The heading shown above the fields.
	 *
	 * @var string
	 */
	private string $section_title;

	/**
	 * The field set doing the work.
	 *
	 * @var FieldSet
	 */
	private FieldSet $set;

	/**
	 * Registered instances, so fields can be read back.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = [];

	/**
	 * This instance's identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Construct.
	 *
	 * @param array<string, array<string, mixed>> $fields        Field configuration.
	 * @param string                              $section_title Heading above the fields.
	 */
	public function __construct( array $fields, string $section_title = '' ) {
		self::declare_config_keys();

		$this->fields        = $fields;
		$this->section_title = '' !== $section_title ? $section_title : __( 'Additional Information', 'arraypress' );
		// One context, shared by every field set this class builds. The
		// encrypting decorator is what makes `encrypted` mean anything:
		// without it the flag rendered a password control, had REST exposure
		// refused on its account, and stored the value in plain text.
		$this->context = new EncryptedContext( new UserMetaContext() );

		$this->set           = new FieldSet( $fields, $this->context, '', new Registry() );

		// Keyed by the fields it carries, so two registrations of the same
		// set collapse rather than rendering the same inputs twice.
		$this->id = md5( (string) wp_json_encode( array_keys( $fields ) ) );

		self::$instances[ $this->id ] = $this;

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
	 * Declare these meta keys to WordPress.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$this->set->register_meta();
	}

	/**
	 * Attach the profile screens' hooks.
	 *
	 * @return void
	 */
	public function load_hooks(): void {
		// Your own profile, and someone else's.
		add_action( 'show_user_profile', [ $this, 'render_profile' ] );
		add_action( 'edit_user_profile', [ $this, 'render_profile' ] );

		add_action( 'user_new_form', [ $this, 'render_add_form' ] );

		add_action( 'personal_options_update', [ $this, 'save' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save' ] );
		add_action( 'user_register', [ $this, 'save' ] );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the kit on the user screens only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, [ 'profile', 'user-edit', 'user' ], true ) ) {
			return;
		}

		( new Assets() )->enqueue( $this->set->dependencies() );
	}

	/**
	 * Render the fields on a profile screen.
	 *
	 * @param WP_User $user The user being edited.
	 *
	 * @return void
	 */
	public function render_profile( WP_User $user ): void {
		$fields = $this->visible_fields( $user->ID, 'profile' );

		if ( [] === $fields ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html( $this->section_title ) );

		echo $this->render_sections( $fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
	}

	/**
	 * Render the fields on the add-user screen.
	 *
	 * @param string $context Which add-user form is being drawn.
	 *
	 * @return void
	 */
	public function render_add_form( string $context ): void {
		// The other context is "add-existing-user" on multisite, which adds
		// an account that already has its meta.
		if ( 'add-new-user' !== $context ) {
			return;
		}

		$fields = $this->visible_fields( 0, 'add' );

		if ( [] === $fields ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html( $this->section_title ) );

		echo $this->render_sections( $fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
	}

	/**
	 * Render fields, honouring any tab or accordion markers among them.
	 *
	 * Without this the markers arrived at render_row() as ordinary fields,
	 * drew nothing, and a set meant to be tabbed came out as one flat list.
	 *
	 * @param \ArrayPress\FieldKit\Field[] $fields The fields.
	 *
	 * @return string
	 */
	private function render_sections( array $fields ): string {
		$layout = Sections::split( $fields );

		if ( [] === $layout ) {
			return $this->render_table( $fields );
		}

		return Sections::render(
			$layout,
			fn( array $group ): string => [] === $group ? '' : $this->render_table( $group ),
			$this->id
		);
	}

	/**
	 * Render a set of fields as a settings table.
	 *
	 * A marker draws nothing, so one left in the list emits an empty row.
	 *
	 * @param \ArrayPress\FieldKit\Field[] $fields The fields.
	 *
	 * @return string
	 */
	private function render_table( array $fields ): string {
		ob_start();

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $field ) {
			if ( Sections::is_marker( $field ) ) {
				continue;
			}

			$this->render_row( $field );
		}

		echo '</tbody></table>';

		return (string) ob_get_clean();
	}

	/**
	 * Render one field as a table row.
	 *
	 * @param \ArrayPress\FieldKit\Field $field The field.
	 *
	 * @return void
	 */
	private function render_row( $field ): void {
		$type = $field->type();

		// A heading, a notice or a panel has no label to sit beside: a header
		// cell would indent it as though it labelled a control.
		if ( $type->spans_row() ) {
			printf(
				'<tr class="user-%s-wrap field-kit__spans-row"><td colspan="2">%s</td></tr>',
				esc_attr( $field->key() ),
				$this->set->render_field( $field, '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
			);

			return;
		}

		// A self-labelling control already carries its own <label for>, and a
		// group of controls has no single element to point at, so both get
		// plain text rather than a second label.
		$badge = Badge::for_field( $field );

		// Beside the heading, which this class draws rather than the kit.
		$tooltip = Tooltip::for_field( $field );

		$header = $type->is_self_labelling() || $type->is_grouped()
			? sprintf(
				'<span class="field-kit__row-label">%s</span>%s',
				esc_html( $field->label() ),
				$badge . $tooltip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			)
			: sprintf(
				'<label for="%s">%s</label>%s',
				esc_attr( $field->input_id() ),
				esc_html( $field->label() ),
				$badge . $tooltip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			);

		printf(
			'<tr class="user-%s-wrap"><th scope="row">%s</th><td>%s</td></tr>',
			esc_attr( $field->key() ),
			$header, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/esc_attr above.
			// The header cell is the visible heading on this screen, so the
			// kit draws none.
			$this->set->render_field( $field, '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
		);
	}

	/**
	 * Save a user's fields.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return void
	 */
	public function save( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the profile form's nonce before firing these hooks.
		$input = $_POST;

		// Only what this user may set. A field they cannot see is not one a
		// crafted submission gets to write either — the screen hides it, and
		// without this the hiding would be the only thing stopping it.
		$this->restricted( $user_id )->save( $input, $user_id );
	}

	/**
	 * A field set limited to what the current user may write.
	 *
	 * @param int $user_id The user being edited.
	 *
	 * @return FieldSet
	 */
	private function restricted( int $user_id ): FieldSet {
		$allowed = array_filter(
			$this->fields,
			fn( $config ) => $this->permitted( (array) $config, $user_id )
		);

		return $allowed === $this->fields
			? $this->set
			: new FieldSet( $allowed, $this->context, '', new Registry() );
	}

	/**
	 * The fields to draw on a screen, as Field objects.
	 *
	 * @param int    $user_id The user being edited, or 0 on the add screen.
	 * @param string $screen  Which screen: profile or add.
	 *
	 * @return \ArrayPress\FieldKit\Field[]
	 */
	private function visible_fields( int $user_id, string $screen ): array {
		$fields = [];

		// FieldSet::fields() returns a list, not a map — the key to look the
		// configuration up by is on the field itself. Reading $key here gave
		// 0, 1, 2, so every lookup missed and every field came back
		// permitted, which is the wrong way round for a permission check to
		// fail.
		foreach ( $this->set->fields( $user_id ) as $field ) {
			$key    = $field->key();
			$config = (array) ( $this->fields[ $key ] ?? [] );

			if ( ! $this->permitted( $config, $user_id ) ) {
				continue;
			}

			// A field can be kept off the add-user screen, where there is no
			// user yet and a default is all it could show.
			if ( 'add' === $screen && ! ( $config['show_on_add'] ?? true ) ) {
				continue;
			}

			$fields[ $key ] = $field;
		}

		return $fields;
	}

	/**
	 * Whether the current user may see and set a field.
	 *
	 * `own_capability` is the profile screen's own problem: the object being
	 * edited may be the person editing it, and a field an administrator may
	 * set on someone else is not always one the subject should set on
	 * themselves.
	 *
	 * @param array<string, mixed> $config  Field configuration.
	 * @param int                  $user_id The user being edited.
	 *
	 * @return bool
	 */
	private function permitted( array $config, int $user_id ): bool {
		$editing_self = $user_id > 0 && get_current_user_id() === $user_id;

		$capability = $editing_self && isset( $config['own_capability'] )
			? (string) $config['own_capability']
			: (string) ( $config['capability'] ?? 'edit_users' );

		return '' === $capability || current_user_can( $capability, $user_id ?: null );
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
	 * @param string $meta_key The meta key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_field( string $meta_key ): ?array {
		return $this->fields[ $meta_key ] ?? null;
	}

	/**
	 * Get every registered field, across every instance.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all_fields(): array {
		$fields = [];

		foreach ( self::$instances as $instance ) {
			$fields = array_merge( $fields, $instance->get_fields() );
		}

		return $fields;
	}

	/**
	 * Get one field's configuration from any instance.
	 *
	 * @param string $meta_key The meta key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_field_by_key( string $meta_key ): ?array {
		return self::get_all_fields()[ $meta_key ] ?? null;
	}

	/**
	 * The instance that registered a given field.
	 *
	 * User fields are registered as sets rather than against one screen, so
	 * there is no id a caller would know to ask for — a field key is the only
	 * thing they have.
	 *
	 * @param string $meta_key The meta key.
	 *
	 * @return self|null
	 */
	public static function get_instance_for( string $meta_key ): ?self {
		foreach ( self::$instances as $instance ) {
			if ( isset( $instance->fields[ $meta_key ] ) ) {
				return $instance;
			}
		}

		return null;
	}

	/**
	 * Tell the kit which configuration this library reads.
	 *
	 * A user field can name the capability that applies when someone is
	 * editing their own profile, and can be kept off the add-user screen.
	 * Both are read here, so without this the kit would report each of them
	 * as configuration nothing reads — which is the warning it exists to
	 * give, aimed at the wrong thing.
	 *
	 * @return void
	 */
	private static function declare_config_keys(): void {
		static $declared = false;

		if ( $declared ) {
			return;
		}

		$declared = true;

		Field::allow_config_keys( [ 'own_capability', 'show_on_add' ] );
	}

	/**
	 * Read one field's value, through this set's context.
	 *
	 * The reason to use this rather than get_user_meta(): the context is
	 * where decryption happens and where a field's configured default comes
	 * from. Reading the meta directly hands back ciphertext for an encrypted
	 * field and an empty string for one that has never been saved, both of
	 * which look like ordinary values to the caller.
	 *
	 * @param int    $user_id  The user id.
	 * @param string $key       Field key.
	 * @param mixed  $fallback  Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	public function get_value( int $user_id, string $key, mixed $fallback = null ): mixed {
		$field = $this->set->field( $key, $user_id );

		if ( null === $field ) {
			return $fallback;
		}

		$value = $field->value();

		return null === $value || '' === $value ? $fallback : $value;
	}
}
