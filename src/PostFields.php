<?php
/**
 * Post Fields
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
use ArrayPress\FieldKit\Context\PostMetaContext;
use ArrayPress\FieldKit\Contracts\Context;
use ArrayPress\FieldKit\Field;
use ArrayPress\FieldKit\FieldSet;
use ArrayPress\FieldKit\Registry as TypeRegistry;
use ArrayPress\FieldKit\Support\Badge;
use ArrayPress\FieldKit\Support\Markup;
use ArrayPress\FieldKit\Support\PanelTabs;
use ArrayPress\FieldKit\Support\Sections;
use ArrayPress\FieldKit\Support\Tooltip;
use WP_Post;

/**
 * Registers a metabox of custom fields on one or more post types.
 *
 * Rendering, sanitizing, conditional logic, accessibility, the search
 * endpoint and the action endpoint all come from wp-field-kit. What is left
 * here is what is genuinely about a metabox: registering it with WordPress,
 * the nonce that guards it, and the two capability questions a post screen
 * asks that a term screen does not — may this user edit *this* post, and may
 * they see *this* field.
 *
 * One default changed in the port, deliberately. `show_in_rest` was true for
 * every field, which publishes every custom field a plugin has ever
 * registered to the REST API and the block editor, including the ones holding
 * a licence key. It is off unless a field asks.
 */
class PostFields {

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
	 * This metabox's identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Metabox configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

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
	 * Registered instances, so fields can be read back by metabox.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = [];

	/**
	 * Construct.
	 *
	 * @param string               $id     Metabox identifier.
	 * @param array<string, mixed> $config Metabox configuration.
	 */
	public function __construct( string $id, array $config ) {
		$this->id     = $id;
		$this->config = $this->defaults( $config );
		// Panels are a way of grouping the same fields, not a second place to
		// declare them: they are flattened into one set here so saving,
		// registration and permissions have nothing to know about layout.
		$this->fields = $this->prefixed( $this->from_panels( $config ) );
		// One context, shared by every field set this class builds. The
		// encrypting decorator is what makes `encrypted` mean anything:
		// without it the flag rendered a password control, had REST exposure
		// refused on its account, and stored the value in plain text.
		$this->context = new EncryptedContext( new PostMetaContext() );

		$this->set    = new FieldSet( $this->fields, $this->context, '', new TypeRegistry() );

		// The configuration carries the prefixed keys too. Two getters
		// disagreeing about what a field is called is how a default lookup
		// silently misses.
		$this->config['fields'] = $this->fields;

		self::$instances[ $id ] = $this;

		// On init, not admin_init: a registered meta key is how REST, the
		// block editor and the capability check learn the field exists, and
		// all three run on requests that never reach the admin.
		if ( did_action( 'init' ) ) {
			$this->register_meta();
		} else {
			add_action( 'init', [ $this, 'register_meta' ] );
		}

		if ( did_action( 'admin_init' ) ) {
			$this->load_hooks();
		} else {
			add_action( 'admin_init', [ $this, 'load_hooks' ] );
		}
	}

	/**
	 * Merge the metabox configuration with its defaults.
	 *
	 * @param array<string, mixed> $config Supplied configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults( array $config ): array {
		$config = array_merge(
			[
				'title'      => __( 'Additional Information', 'arraypress' ),
				'post_types' => [ 'post' ],
				'context'    => 'normal',
				'priority'   => 'high',
				'prefix'     => '',
				'capability' => 'edit_posts',
				'layout'     => 'stacked',
				'panels'     => [],
				'fields'     => [],
			],
			$config
		);

		$config['post_types'] = (array) $config['post_types'];

		return $config;
	}

	/**
	 * Every field, whether it was declared flat or inside a panel.
	 *
	 * A panel is a layout. Flattening here means the save path, the meta
	 * registration and the capability checks never learn there was one —
	 * which is the difference between a layout and a second concept.
	 *
	 * @param array<string, mixed> $config Metabox configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function from_panels( array $config ): array {
		$fields = (array) ( $config['fields'] ?? [] );

		foreach ( (array) ( $config['panels'] ?? [] ) as $panel ) {
			$fields = array_merge( $fields, (array) ( $panel['fields'] ?? [] ) );
		}

		return $fields;
	}

	/**
	 * Apply the metabox's meta-key prefix to every field.
	 *
	 * Done once, here, so everything downstream — rendering, saving,
	 * registration — sees the key the database actually uses.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function prefixed( array $fields ): array {
		$prefix = (string) $this->config['prefix'];

		if ( '' === $prefix ) {
			return $fields;
		}

		$prefixed = [];

		foreach ( $fields as $key => $config ) {
			$prefixed[ $prefix . $key ] = $config;
		}

		return $prefixed;
	}

	/**
	 * Declare these meta keys to WordPress.
	 *
	 * One registration per post type, because a key registered without a
	 * subtype applies to every post type there is.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		foreach ( (array) $this->config['post_types'] as $post_type ) {
			$this->set->register_meta( (string) $post_type );
		}
	}

	/**
	 * Attach the metabox's hooks.
	 *
	 * @return void
	 */
	public function load_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Register the metabox with WordPress.
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		foreach ( (array) $this->config['post_types'] as $post_type ) {
			add_meta_box(
				$this->id,
				(string) $this->config['title'],
				[ $this, 'render' ],
				(string) $post_type,
				(string) $this->config['context'],
				(string) $this->config['priority']
			);
		}
	}

	/**
	 * Enqueue the kit on this metabox's screens only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		if ( ! in_array( $screen->post_type, (array) $this->config['post_types'], true ) ) {
			return;
		}

		( new Assets() )->enqueue( $this->set->dependencies() );
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post The post being edited.
	 *
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( 'save_' . $this->id, $this->id . '_nonce' );

		// What the last save of this post refused, if this user made it and
		// the screen has not shown it yet. Read here, once, so the notice
		// and the field markers agree.
		$errors = Errors::recall( 'post', $post->ID, $this->id );

		printf( '<div class="field-kit__metabox" data-metabox-id="%s">', esc_attr( $this->id ) );

		echo Errors::notice( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.

		if ( [] !== (array) $this->config['panels'] ) {
			echo $this->render_panels( $post, $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		} else {
			echo $this->render_sections( $this->visible_fields( $post->ID ), $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		}

		echo '</div>';
	}

	/**
	 * Render the metabox as tabbed panels.
	 *
	 * A panel with nothing in it — every field in it hidden by a capability —
	 * is dropped rather than shown empty. A tab that opens onto nothing reads
	 * as a screen that failed to load.
	 *
	 * @param WP_Post               $post   The post being edited.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private function render_panels( WP_Post $post, array $errors ): string {
		$visible = [];

		foreach ( $this->visible_fields( $post->ID ) as $field ) {
			$visible[ $field->key() ] = $field;
		}

		$panels = [];

		foreach ( (array) $this->config['panels'] as $slug => $panel ) {
			$keys = array_map(
				fn( $key ) => (string) $this->config['prefix'] . $key,
				array_keys( (array) ( $panel['fields'] ?? [] ) )
			);

			$in_panel = array_intersect_key( $visible, array_flip( $keys ) );

			if ( [] === $in_panel ) {
				continue;
			}

			// No icon: a panel is a tab, a tab is its label, and the kit
			// draws none.
			$panels[ (string) $slug ] = [
				'label'   => (string) ( $panel['label'] ?? $slug ),
				'content' => $this->render_table( array_values( $in_panel ), $errors ),
			];
		}

		return PanelTabs::render( $this->id, $panels );
	}

	/**
	 * Render fields, honouring any tab or accordion markers among them.
	 *
	 * `panels` is this class's own way of tabbing a metabox: the caller
	 * names the panels and lists which fields go in each. A `tab` or
	 * `accordion` field is the kit's way, and divides the list in place.
	 * Both end up as the same tab strip; they are different ways of saying
	 * it, and a metabox uses one or the other.
	 *
	 * Without this the markers arrived here as ordinary fields, rendered
	 * nothing, and a tabbed metabox came out as one flat list.
	 *
	 * @param Field[]               $fields The fields.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private function render_sections( array $fields, array $errors ): string {
		$layout = Sections::split( $fields );

		if ( [] === $layout ) {
			return $this->render_table( $fields, $errors );
		}

		return Sections::render(
			$layout,
			fn( array $group ): string => [] === $group ? '' : $this->render_table( $group, $errors ),
			$this->id
		);
	}

	/**
	 * Render a set of fields as a settings table.
	 *
	 * A marker draws nothing, so one left in the list emits an empty row.
	 *
	 * @param Field[]               $fields The fields.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private function render_table( array $fields, array $errors ): string {
		ob_start();

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $field ) {
			if ( Sections::is_marker( $field ) ) {
				continue;
			}

			$this->render_row( $field, $errors );
		}

		echo '</tbody></table>';

		return (string) ob_get_clean();
	}

	/**
	 * Render one field as a table row.
	 *
	 * @param Field                 $field  The field.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return void
	 */
	private function render_row( Field $field, array $errors ): void {
		$type = $field->type();

		// A heading, a notice or a panel has no label to sit beside: a header
		// cell would indent it as though it labelled a control.
		if ( $type->spans_row() ) {
			printf(
				'<tr class="field-kit__metabox-row"><td colspan="2">%s</td></tr>',
				$this->set->render_field( $field, '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
			);

			return;
		}

		$badge = Badge::for_field( $field );

		// Beside the heading, which this class draws rather than the kit.
		$tooltip = Tooltip::for_field( $field );

		// A self-labelling control already carries its own <label for>, and a
		// group of controls has no single element to point at, so both get
		// plain text rather than a second label.
		$header = $type->is_self_labelling() || $type->is_grouped()
			? sprintf(
				'<span class="field-kit__row-label">%s</span>%s',
				esc_html( $field->label() ),
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
			'<tr class="field-kit__metabox-row"><th scope="row">%s</th><td>%s</td></tr>',
			$header, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/esc_attr above.
			$this->set->render_field( $field, $errors[ $field->key() ] ?? '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
		);
	}

	/**
	 * Save a post's fields.
	 *
	 * @param int     $post_id The post id.
	 * @param WP_Post $post    The post.
	 *
	 * @return void
	 */
	public function save( int $post_id, WP_Post $post ): void {
		// An autosave is not a submission: the fields are not in it, and
		// saving would read every one of them as cleared.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->config['post_types'], true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line.
		$nonce = isset( $_POST[ $this->id . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $this->id . '_nonce' ] ) ) : '';

		// A metabox can be absent from a submission — hidden in Screen
		// Options, or a quick edit — and its nonce with it. Saving then would
		// clear every field it holds.
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'save_' . $this->id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$input = $_POST;

		// Only what this user may set. A field they cannot see is not one a
		// crafted submission gets to write either.
		$set = $this->permitted_set( $post_id );

		$set->save( $input, $post_id );

		// A refused value is left as it was and the rest are stored. Core
		// redirects before anything here could say so, so the messages wait
		// for the next load of this post's screen.
		Errors::remember( 'post', $post_id, $this->id, $set->errors() );
	}

	/**
	 * A field set limited to what the current user may write.
	 *
	 * @param int $post_id The post being edited.
	 *
	 * @return FieldSet
	 */
	private function permitted_set( int $post_id ): FieldSet {
		$allowed = array_filter(
			$this->fields,
			fn( $config ) => $this->permitted( (array) $config, $post_id )
		);

		return $allowed === $this->fields
			? $this->set
			: new FieldSet( $allowed, $this->context, '', new TypeRegistry() );
	}

	/**
	 * The fields to draw, as Field objects.
	 *
	 * @param int $post_id The post being edited.
	 *
	 * @return Field[]
	 */
	private function visible_fields( int $post_id ): array {
		$fields = [];

		foreach ( $this->set->fields( $post_id ) as $field ) {
			if ( $this->permitted( (array) ( $this->fields[ $field->key() ] ?? [] ), $post_id ) ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Whether the current user may see and set a field.
	 *
	 * @param array<string, mixed> $config  Field configuration.
	 * @param int                  $post_id The post being edited.
	 *
	 * @return bool
	 */
	private function permitted( array $config, int $post_id ): bool {
		$capability = (string) ( $config['capability'] ?? $this->config['capability'] );

		return '' === $capability || current_user_can( $capability, $post_id ?: null );
	}

	/**
	 * Get this metabox's identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get the metabox configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * Get the field configuration, with prefixes applied.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * Get a registered metabox.
	 *
	 * @param string $id Metabox identifier.
	 *
	 * @return self|null
	 */
	public static function get_instance( string $id ): ?self {
		return self::$instances[ $id ] ?? null;
	}

	/**
	 * Get every registered metabox's configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all_metaboxes(): array {
		$all = [];

		foreach ( self::$instances as $id => $instance ) {
			$all[ $id ] = $instance->get_config();
		}

		return $all;
	}

	/**
	 * Get one metabox's configuration.
	 *
	 * @param string $id Metabox identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_metabox( string $id ): ?array {
		return self::$instances[ $id ]?->get_config();
	}

	/**
	 * Get one metabox's fields.
	 *
	 * @param string $id Metabox identifier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_metabox_fields( string $id ): array {
		return self::$instances[ $id ]?->get_fields() ?? [];
	}

	/**
	 * Get one field's configuration.
	 *
	 * @param string $metabox_id Metabox identifier.
	 * @param string $meta_key   The meta key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_field_config( string $metabox_id, string $meta_key ): ?array {
		return self::get_metabox_fields( $metabox_id )[ $meta_key ] ?? null;
	}

	/**
	 * Read one field's value, through this set's context.
	 *
	 * The reason to use this rather than get_post_meta(): the context is
	 * where decryption happens and where a field's configured default comes
	 * from. Reading the meta directly hands back ciphertext for an encrypted
	 * field and an empty string for one that has never been saved, both of
	 * which look like ordinary values to the caller.
	 *
	 * @param int    $post_id  The post id.
	 * @param string $key       Field key.
	 * @param mixed  $fallback  Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	public function get_value( int $post_id, string $key, mixed $fallback = null ): mixed {
		$field = $this->set->field( $key, $post_id );

		if ( null === $field ) {
			return $fallback;
		}

		$value = $field->value();

		return null === $value || '' === $value ? $fallback : $value;
	}
}
