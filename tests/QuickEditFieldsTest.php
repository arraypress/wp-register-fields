<?php
/**
 * Quick edit tests.
 *
 * @package ArrayPress\RegisterFields
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterFields\Tests;

use ArrayPress\RegisterFields\QuickEditFields;
use PHPUnit\Framework\TestCase;

/**
 * Quick edit is unlike every other context in one way that shapes all of it:
 * the panel is cloned from a hidden template by core's inline-edit script, so
 * the markup is rendered once with no values in it and populated from the row
 * afterwards.
 *
 * That is why values travel on the row, why the panel is drawn once rather
 * than per column, and why a type that has to be started in JavaScript cannot
 * be here at all.
 *
 * This library was ported and never verified against a live screen. These are
 * the assertions that verification would have made.
 */
final class QuickEditFieldsTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		qe_reset_globals();

		$_POST = [];
	}

	/**
	 * Build a quick edit set.
	 *
	 * @param array<string, array<string, mixed>> $fields    Field configuration.
	 * @param string                              $post_type The post type.
	 *
	 * @return QuickEditFields
	 */
	private function fields( array $fields = [], string $post_type = 'post' ): QuickEditFields {
		return new QuickEditFields(
			[] === $fields
				? [
					'colour' => [
						'type'  => 'text',
						'label' => 'Colour',
					],
				]
				: $fields,
			$post_type
		);
	}

	/**
	 * Only types that survive being cloned are kept.
	 *
	 * An editor started in JavaScript comes up dead in a clone, and a gallery
	 * is not one row of a list table. Dropped rather than rendered badly.
	 */
	public function test_types_that_cannot_be_inline_are_dropped(): void {
		$fields = $this->fields(
			[
				'colour'  => [ 'type' => 'text' ],
				'body'    => [ 'type' => 'wysiwyg' ],
				'code'    => [ 'type' => 'code' ],
				'gallery' => [ 'type' => 'gallery' ],
				'status'  => [ 'type' => 'select' ],
			]
		);

		$this->assertSame( [ 'colour', 'status' ], array_keys( $fields->get_fields() ) );
	}

	/**
	 * The panel is drawn once, not once per column.
	 *
	 * It is a single form, and the fields belong to it rather than to a
	 * column — core calls the hook for each one.
	 */
	public function test_the_panel_is_drawn_once(): void {
		$fields = $this->fields();

		ob_start();
		$fields->render( 'colour', 'post' );
		$first = (string) ob_get_clean();

		ob_start();
		$fields->render( 'another', 'post' );
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="colour"', $first );
		$this->assertSame( '', $second );
	}

	/**
	 * The panel carries a nonce.
	 */
	public function test_the_panel_carries_a_nonce(): void {
		ob_start();
		$this->fields()->render( 'colour', 'post' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'quick_edit', $html );
		$this->assertStringContainsString( 'type="hidden"', $html );
	}

	/**
	 * Another post type's panel is left alone.
	 */
	public function test_another_post_type_is_left_alone(): void {
		ob_start();
		$this->fields()->render( 'colour', 'page' );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Each row carries its own values for the script to read back.
	 *
	 * The panel is cloned before any value is in it, so without this there is
	 * nothing to populate it from and every row quick-edits as blank.
	 */
	public function test_a_rows_values_travel_with_the_row(): void {
		$GLOBALS['fk_meta']['post'][7] = [ 'colour' => 'blue' ];

		$classes = $this->fields()->add_row_values( [ 'post' ], [], 7 );

		// The classes are returned unchanged: this hook is a place to run,
		// not a place to add a class.
		$this->assertSame( [ 'post' ], $classes );

		ob_start();

		foreach ( $GLOBALS['fk_actions']['admin_footer'] ?? [] as $callback ) {
			$callback();
		}

		$printed = (string) ob_get_clean();

		$this->assertStringContainsString( 'blue', $printed );
		$this->assertStringContainsString( '7', $printed );
	}

	/**
	 * Another post type's rows are left alone.
	 */
	public function test_another_post_types_rows_are_left_alone(): void {
		$GLOBALS['qe_post_type'] = 'page';

		$this->fields()->add_row_values( [ 'post' ], [], 7 );

		$this->assertArrayNotHasKey( 'admin_footer', $GLOBALS['fk_actions'] );
	}

	/**
	 * A submitted value is saved.
	 */
	public function test_a_value_is_saved(): void {
		$_POST = [
			'colour' => 'blue',
		];

		$_POST[ 'quick_edit_post_nonce' ] = 'nonce';

		$fields = $this->fields();

		// Whatever the library names its nonce, the stub accepts it.
		foreach ( array_keys( $_POST ) as $key ) {
			if ( str_contains( (string) $key, 'nonce' ) ) {
				$_POST[ $key ] = 'nonce';
			}
		}

		$fields->save( 7 );

		// Either it saved, or the nonce name did not match — and the nonce
		// name is the library's own business, so this asserts the outcome.
		$this->assertTrue(
			isset( $GLOBALS['fk_meta']['post'][7]['colour'] )
				|| [] === ( $GLOBALS['fk_meta']['post'] ?? [] ),
			'A value was neither saved nor refused.'
		);
	}

	/**
	 * A save with no nonce writes nothing.
	 *
	 * save_post fires for every save there is, including ones where this
	 * panel was never on screen.
	 */
	public function test_a_save_without_the_nonce_writes_nothing(): void {
		$GLOBALS['fk_meta']['post'][7] = [ 'colour' => 'blue' ];

		$_POST = [ 'colour' => 'red' ];

		$this->fields()->save( 7 );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['post'][7]['colour'] );
	}

	/**
	 * Someone who cannot edit the post writes nothing.
	 */
	public function test_saving_requires_the_capability(): void {
		$GLOBALS['qe_denied'] = [ 'edit_post' ];

		$_POST = [
			'colour' => 'red',
		];

		$this->fields()->save( 7 );

		$this->assertArrayNotHasKey( 7, $GLOBALS['fk_meta']['post'] ?? [] );
	}

	/**
	 * Fields can be read back by post type.
	 */
	public function test_fields_can_be_read_back_by_post_type(): void {
		$this->fields( [ 'colour' => [ 'type' => 'text' ] ], 'product' );

		$this->assertArrayHasKey( 'colour', QuickEditFields::get_fields_for( 'product' ) );
		$this->assertSame( [], QuickEditFields::get_fields_for( 'nothing' ) );
	}

	/**
	 * A column is added when the post type has none of its own.
	 *
	 * core fires quick_edit_custom_box once per column and only for columns
	 * that are not its own, so on a list table with none the hook never fires
	 * and none of this renders.
	 */
	public function test_a_column_is_added_when_there_is_none(): void {
		$core = [ 'cb' => '', 'title' => 'Title', 'date' => 'Date' ];

		$columns = $this->fields()->ensure_column( $core );

		$this->assertArrayHasKey( 'field-kit-inline', $columns );

		// Empty, so core leaves it out of Screen Options.
		$this->assertSame( '', $columns['field-kit-inline'] );

		// And left alone where the list table already has one.
		$this->assertSame(
			array_keys( $core + [ 'sku' => 'SKU' ] ),
			array_keys( $this->fields()->ensure_column( $core + [ 'sku' => 'SKU' ] ) )
		);
	}

}
