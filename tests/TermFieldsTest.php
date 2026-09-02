<?php
/**
 * Term field tests.
 *
 * @package ArrayPress\RegisterFields
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterFields\Tests;

use ArrayPress\RegisterFields\TermFields;
use PHPUnit\Framework\TestCase;
use WP_Term;

/**
 * The field layer is the kit's and is tested there. What is tested here is
 * what this library still decides: the four hooks WordPress fires, and the
 * two different wrappers the add and edit screens want.
 *
 * That last one is the whole reason this class exists rather than being a
 * configuration of the kit. The add screen stacks fields in divs; the edit
 * screen lays them out as table rows with the label in a header cell. Same
 * fields, same values, two shapes — and the row layout has to know which
 * kinds of field want a header cell and which want the whole row.
 */
final class TermFieldsTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		tf_reset_globals();

		$_POST = [];
	}

	/**
	 * Build a term field set.
	 *
	 * @param array<string, array<string, mixed>> $fields   Field configuration.
	 * @param string                              $taxonomy The taxonomy.
	 *
	 * @return TermFields
	 */
	private function fields( array $fields = [], string $taxonomy = 'demo_tax' ): TermFields {
		return new TermFields(
			$taxonomy,
			[] === $fields
				? [
					'colour' => [
						'type'  => 'text',
						'label' => 'Colour',
					],
				]
				: $fields
		);
	}

	/**
	 * A term to render against.
	 *
	 * @param int $id The term id.
	 *
	 * @return WP_Term
	 */
	private function term( int $id = 5 ): WP_Term {
		$term          = new WP_Term();
		$term->term_id = $id;

		return $term;
	}

	/**
	 * A submission carrying this form's nonce.
	 *
	 * @param array<string, mixed> $values Submitted values.
	 *
	 * @return void
	 */
	private function submit( array $values ): void {
		$_POST = array_merge( [ 'term-demo_tax_nonce' => 'nonce-save_term-demo_tax' ], $values );
	}

	/**
	 * Render the edit screen and return what it printed.
	 *
	 * @param TermFields $fields The field set.
	 *
	 * @return string
	 */
	private function edit( TermFields $fields ): string {
		ob_start();

		try {
			$fields->render_edit_form( $this->term(), 'demo_tax' );
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Render the add screen and return what it printed.
	 *
	 * @param TermFields $fields The field set.
	 *
	 * @return string
	 */
	private function add( TermFields $fields ): string {
		ob_start();

		try {
			$fields->render_add_form();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Both screens and both save hooks are attached.
	 *
	 * Missing one of the save pair is the classic term-fields bug: values
	 * save when a term is edited and silently not when one is created.
	 */
	public function test_every_screen_and_both_save_paths_are_hooked(): void {
		$this->fields()->load_hooks();

		foreach (
			[
				'demo_tax_add_form_fields',
				'demo_tax_edit_form_fields',
				'created_demo_tax',
				'edited_demo_tax',
			] as $hook
		) {
			$this->assertArrayHasKey( $hook, $GLOBALS['fk_actions'], sprintf( '%s is not hooked.', $hook ) );
		}
	}

	/**
	 * The meta keys are declared, scoped to this taxonomy.
	 */
	public function test_the_meta_keys_are_registered(): void {
		$this->fields()->register_meta();

		$this->assertArrayHasKey( 'colour', $GLOBALS['fk_meta_registry']['term'] ?? [] );
		$this->assertSame( 'demo_tax', $GLOBALS['fk_meta_registry']['term']['colour']['object_subtype'] );
		$this->assertFalse( $GLOBALS['fk_meta_registry']['term']['colour']['show_in_rest'] );
	}

	/**
	 * The edit screen lays fields out as table rows with a header cell.
	 */
	public function test_the_edit_screen_uses_table_rows(): void {
		$html = $this->edit( $this->fields() );

		$this->assertStringContainsString( '<tr class="form-field term-colour-wrap">', $html );
		$this->assertStringContainsString( '<th scope="row">', $html );
		$this->assertStringContainsString( '<label for="colour">Colour</label>', $html );
	}

	/**
	 * The add screen stacks fields in divs.
	 *
	 * Same fields, different shape — which is the whole reason this class
	 * exists rather than being a configuration of the kit.
	 */
	public function test_the_add_screen_uses_stacked_divs(): void {
		$html = $this->add( $this->fields() );

		$this->assertStringContainsString( '<div class="form-field term-colour-wrap">', $html );
		$this->assertStringNotContainsString( '<tr', $html );

		// The kit draws the label here, because there is no header cell to
		// put it in.
		$this->assertStringContainsString( '<label for="colour">Colour</label>', $html );
	}

	/**
	 * A self-labelling control gets plain text in its header cell.
	 *
	 * A checkbox already carries its own <label for>. A second one pointing
	 * at the same control makes the field announce twice.
	 */
	public function test_a_self_labelling_control_gets_no_second_label(): void {
		$html = $this->edit(
			$this->fields(
				[
					'featured' => [
						'type'  => 'checkbox',
						'label' => 'Featured',
					],
				]
			)
		);

		$this->assertStringContainsString( 'field-kit__row-label', $html );

		// Exactly one label points at the control: the one the checkbox
		// carries itself.
		$this->assertSame( 1, substr_count( $html, 'for="featured"' ) );
	}

	/**
	 * A layout field spans the row.
	 */
	public function test_a_layout_field_spans_the_row(): void {
		$html = $this->edit(
			$this->fields(
				[
					'intro' => [
						'type'  => 'heading',
						'label' => 'About',
					],
				]
			)
		);

		$this->assertStringContainsString( '<td colspan="2">', $html );
		$this->assertStringNotContainsString( '<th scope="row">', $html );
	}

	/**
	 * A `tab` field divides the edit screen, rather than crashing it.
	 *
	 * Sections::render() takes its scope as a string, and this class handed
	 * it a property nobody had declared. Under strict types that is a
	 * TypeError on the edit-term screen for any set with a marker in it.
	 */
	public function test_tab_fields_divide_the_edit_screen(): void {
		$html = $this->edit(
			$this->fields(
				[
					'general'  => [ 'type' => 'tab', 'label' => 'General' ],
					'name'     => [ 'type' => 'text', 'label' => 'Name' ],
					'advanced' => [ 'type' => 'tab', 'label' => 'Advanced' ],
					'slug'     => [ 'type' => 'text', 'label' => 'Slug' ],
				]
			)
		);

		$this->assertStringContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( '>General</span>', $html );
		$this->assertStringContainsString( '>Advanced</span>', $html );
		$this->assertStringContainsString( 'name="name"', $html );
		$this->assertStringContainsString( 'name="slug"', $html );

		// The scope is what keeps this taxonomy's tab strip from driving
		// another's panels. The kit hashes it with the section count into
		// every id it emits, so its presence here is the scope arriving.
		$this->assertStringContainsString( substr( md5( 'term-demo_tax' . 2 ), 0, 8 ), $html );
	}

	/**
	 * The add screen divides the same way, under a scope of its own.
	 *
	 * The same undeclared property, concatenated rather than passed: a
	 * warning instead of a TypeError, and every add form on the site sharing
	 * the scope "-add".
	 */
	public function test_tab_fields_divide_the_add_screen(): void {
		$html = $this->add(
			$this->fields(
				[
					'general'  => [ 'type' => 'tab', 'label' => 'General' ],
					'name'     => [ 'type' => 'text', 'label' => 'Name' ],
					'advanced' => [ 'type' => 'tab', 'label' => 'Advanced' ],
					'slug'     => [ 'type' => 'text', 'label' => 'Slug' ],
				]
			)
		);

		$this->assertStringContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( substr( md5( 'term-demo_tax-add' . 2 ), 0, 8 ), $html );
	}

	/**
	 * Both screens carry the nonce the save path looks for.
	 */
	public function test_both_screens_carry_the_nonce(): void {
		$fields = $this->fields();

		$this->assertStringContainsString( 'name="term-demo_tax_nonce"', $this->add( $fields ) );
		$this->assertStringContainsString( 'name="term-demo_tax_nonce"', $this->edit( $fields ) );
	}

	/**
	 * A submitted value is saved.
	 */
	public function test_a_value_is_saved(): void {
		$this->submit( [ 'colour' => 'blue' ] );

		$this->fields()->save( 5 );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['term'][5]['colour'] );
	}

	/**
	 * A value is sanitized by its own field type.
	 */
	public function test_a_value_is_sanitized_by_its_type(): void {
		$this->submit( [ 'count' => '9999' ] );

		$this->fields(
			[
				'count' => [
					'type' => 'number',
					'max'  => 10,
				],
			]
		)->save( 5 );

		$this->assertSame( 10, $GLOBALS['fk_meta']['term'][5]['count'] );
	}

	/**
	 * A save without this form's nonce leaves the term alone.
	 *
	 * created_ and edited_ fire for every write to a term there is — Quick
	 * Edit on the list table, a term-order plugin, wp_update_term() from
	 * anywhere — and none of those carry these fields. The set reads an
	 * absent field as cleared, so saving then wiped every value the term
	 * had.
	 */
	public function test_a_save_without_the_nonce_leaves_the_term_alone(): void {
		$GLOBALS['fk_meta']['term'][5] = [ 'colour' => 'blue' ];

		$_POST = [ 'colour' => 'red' ];

		$this->fields()->save( 5 );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['term'][5]['colour'] );
	}

	/**
	 * Someone who cannot edit the term saves nothing.
	 */
	public function test_saving_requires_the_capability_to_edit_the_term(): void {
		$GLOBALS['tf_denied'] = [ 'edit_term' ];

		$this->submit( [ 'colour' => 'red' ] );

		$this->fields()->save( 5 );

		$this->assertArrayNotHasKey( 5, $GLOBALS['fk_meta']['term'] ?? [] );
	}

	/**
	 * A field the user cannot see cannot be written by a crafted submission.
	 *
	 * The callback filtered what was drawn and nothing else: save() wrote the
	 * whole set, so anyone who knew the key could post it.
	 */
	public function test_a_hidden_field_cannot_be_written(): void {
		$this->submit(
			[
				'colour' => 'blue',
				'secret' => 'sneaked in',
			]
		);

		$this->fields(
			[
				'colour' => [ 'type' => 'text' ],
				'secret' => [
					'type'                => 'text',
					'permission_callback' => static fn( $field ): bool => false,
				],
			]
		)->save( 5 );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['term'][5]['colour'] );
		$this->assertArrayNotHasKey( 'secret', $GLOBALS['fk_meta']['term'][5] );
	}

	/**
	 * An unticked checkbox stores off rather than reverting to its default.
	 */
	public function test_an_unticked_checkbox_stores_off(): void {
		$fields = $this->fields(
			[
				'featured' => [
					'type'    => 'checkbox',
					'default' => 1,
				],
			]
		);

		$this->submit( [ 'featured' => '1' ] );
		$fields->save( 5 );
		$this->assertSame( 1, $GLOBALS['fk_meta']['term'][5]['featured'] );

		// Unticked: absent from the submission entirely.
		$this->submit( [] );
		$fields->save( 5 );
		$this->assertSame( 0, $GLOBALS['fk_meta']['term'][5]['featured'] );
	}

	/**
	 * Fields can be read back by taxonomy.
	 */
	public function test_fields_can_be_read_back_by_taxonomy(): void {
		$this->fields( [ 'colour' => [ 'type' => 'text' ] ], 'other_tax' );

		$this->assertArrayHasKey( 'colour', TermFields::get_fields( 'other_tax' ) );
		$this->assertNotNull( TermFields::get_instance( 'other_tax' ) );
		$this->assertNull( TermFields::get_instance( 'nothing' ) );
	}

	/**
	 * An encrypted field is encrypted, and reads back.
	 *
	 * The flag used to do nothing here. It rendered a password control, had
	 * REST exposure refused on its account by the meta registrar, and then
	 * stored the value in plain text — which is the one arrangement worse
	 * than not offering encryption at all, because everything visible says it
	 * is on.
	 *
	 * Both halves are asserted: that the meta is not the plaintext, and that
	 * reading it back through the library returns it. Either alone passes for
	 * the wrong reason — a context that encrypts but cannot decrypt satisfies
	 * the first, and one that does neither satisfies the second.
	 */
	public function test_an_encrypted_field_is_stored_encrypted_and_reads_back(): void {
		$fields = $this->fields(
			[
				'api_key' => [
					'type'      => 'password',
					'label'     => 'API key',
					'encrypted' => true,
				],
			]
		);

		$this->submit( [ 'api_key' => 'sk-secret-value' ] );
		$fields->save( 5 );

		$stored = $GLOBALS['fk_meta']['term'][5]['api_key'];

		$this->assertNotSame( 'sk-secret-value', $stored, 'The value was stored in the clear.' );
		$this->assertStringStartsWith( 'fkenc:', (string) $stored, 'The value is not marked as encrypted.' );

		$this->assertSame( 'sk-secret-value', $fields->get_value( 5, 'api_key' ) );
	}

	/**
	 * A value read back comes with its configured default.
	 *
	 * The reason to have an accessor at all rather than telling consumers to
	 * call get_term_meta(): a field that has never been saved has no meta,
	 * and the default is in the configuration.
	 */
	public function test_an_unsaved_field_reads_back_its_default(): void {
		$fields = $this->fields(
			[
				'colour' => [
					'type'    => 'text',
					'default' => 'blue',
				],
			]
		);

		$this->assertSame( 'blue', $fields->get_value( 5, 'colour' ) );
	}

	/**
	 * An unknown field returns the fallback rather than null.
	 */
	public function test_an_unknown_field_returns_the_fallback(): void {
		$this->assertSame( 'nothing', $this->fields()->get_value( 5, 'not_a_field', 'nothing' ) );
	}

	/**
	 * Assets load on this taxonomy's screens and nowhere else.
	 */
	public function test_assets_load_on_the_right_screens_only(): void {
		$GLOBALS['tf_screen'] = (object) [
			'id'       => 'edit-post',
			'taxonomy' => 'category',
			'base'     => 'edit',
		];

		$this->fields()->enqueue();

		$this->assertArrayNotHasKey( 'field-kit', $GLOBALS['fk_styles'] ?? [] );
	}
}
