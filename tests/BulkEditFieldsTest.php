<?php
/**
 * Bulk edit tests.
 *
 * @package ArrayPress\RegisterFields
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterFields\Tests;

use ArrayPress\RegisterFields\BulkEditFields;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Bulk edit has a third state every other screen does without, and it is the
 * whole design of this class. A field is not "set to this" or "set to empty";
 * it is "set to this", "set to empty", or leave alone — and the last is the
 * default, because a bulk edit of forty posts that quietly cleared a field
 * nobody touched would be the worst thing this library could do.
 *
 * So most of what follows is one question asked several ways: what does *not*
 * get written.
 */
final class BulkEditFieldsTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		be_reset_globals();

		$_REQUEST = [];
	}

	/**
	 * Build a bulk edit set.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 *
	 * @return BulkEditFields
	 */
	private function fields( array $fields = [] ): BulkEditFields {
		return new BulkEditFields(
			[] === $fields
				? [
					'colour' => [
						'type'  => 'text',
						'label' => 'Colour',
					],
				]
				: $fields,
			'post'
		);
	}

	/**
	 * A post to save against.
	 *
	 * @param string $type Post type.
	 *
	 * @return WP_Post
	 */
	private function post( string $type = 'post' ): WP_Post {
		$post            = new WP_Post();
		$post->ID        = 7;
		$post->post_type = $type;

		return $post;
	}

	/**
	 * A bulk edit request.
	 *
	 * @param array<string, mixed> $values Submitted values.
	 *
	 * @return void
	 */
	private function submit( array $values ): void {
		$_REQUEST = array_merge( [ 'bulk_edit' => 'Update' ], $values );
	}

	/**
	 * Only types that fit a list-table row are kept.
	 *
	 * Dropped rather than rendered badly: a wysiwyg here is not a smaller
	 * wysiwyg, it is a textarea that looks like a mistake.
	 */
	public function test_types_that_cannot_be_inline_are_dropped(): void {
		$fields = $this->fields(
			[
				'colour'  => [ 'type' => 'text' ],
				'body'    => [ 'type' => 'wysiwyg' ],
				'gallery' => [ 'type' => 'gallery' ],
				'rows'    => [ 'type' => 'repeater' ],
				'email'   => [ 'type' => 'email_editor' ],
				'status'  => [ 'type' => 'select' ],
			]
		);

		$this->assertSame( [ 'colour', 'status' ], array_keys( $fields->get_fields() ) );
	}

	/**
	 * The panel is drawn once, against the first column.
	 *
	 * Core calls the hook per column, so a post type with three custom
	 * columns would otherwise show the same fields three times.
	 */
	public function test_the_panel_is_drawn_once(): void {
		$fields = $this->fields(
			[
				'colour' => [
					'type'  => 'text',
					'label' => 'Colour',
				],
				'size'   => [
					'type'  => 'text',
					'label' => 'Size',
				],
			]
		);

		ob_start();
		$fields->render( 'colour', 'post' );
		$first = (string) ob_get_clean();

		ob_start();
		$fields->render( 'size', 'post' );
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="colour"', $first );
		$this->assertStringContainsString( 'name="size"', $first );
		$this->assertSame( '', $second );
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
	 * A text field says that empty means no change.
	 */
	public function test_a_text_field_says_empty_means_no_change(): void {
		ob_start();
		$this->fields()->render( 'colour', 'post' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Leave empty to make no change.', $html );
	}

	/**
	 * A checkbox becomes a select of three, because it cannot say "no change".
	 */
	public function test_a_checkbox_becomes_a_three_state_select(): void {
		ob_start();
		$this->fields(
			[
				'featured' => [
					'type'  => 'checkbox',
					'label' => 'Featured',
				],
			]
		)->render( 'featured', 'post' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<select', $html );
		$this->assertStringContainsString( '— No change —', $html );
		$this->assertStringContainsString( '>Yes<', $html );
		$this->assertStringContainsString( '>No<', $html );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
	}

	/**
	 * A choice grows a "no change" option, selected by default.
	 */
	public function test_a_select_grows_a_no_change_option(): void {
		ob_start();
		$this->fields(
			[
				'status' => [
					'type'    => 'select',
					'label'   => 'Status',
					'options' => [
						'a' => 'A',
						'b' => 'B',
					],
					'default' => 'a',
				],
			]
		)->render( 'status', 'post' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '— No change —', $html );

		// And the field's own default does not preselect anything: on this
		// screen a preselected default is a change nobody asked for.
		$this->assertStringNotContainsString( 'selected', $html );
	}

	/**
	 * A submitted value is written.
	 */
	public function test_a_submitted_value_is_written(): void {
		$this->submit( [ 'colour' => 'blue' ] );

		$this->fields()->save( 7, $this->post() );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['post'][7]['colour'] );
	}

	/**
	 * A field left empty is left alone.
	 */
	public function test_an_empty_field_is_left_alone(): void {
		$GLOBALS['fk_meta']['post'][7] = [ 'colour' => 'blue' ];

		$this->submit( [ 'colour' => '' ] );

		$this->fields()->save( 7, $this->post() );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['post'][7]['colour'] );
	}

	/**
	 * A field that opted in can be cleared by being submitted empty.
	 */
	public function test_a_field_can_opt_into_being_cleared_when_empty(): void {
		$GLOBALS['fk_meta']['post'][7] = [ 'colour' => 'blue' ];

		$this->submit( [ 'colour' => '' ] );

		$this->fields(
			[
				'colour' => [
					'type'        => 'text',
					'allow_clear' => true,
				],
			]
		)->save( 7, $this->post() );

		$this->assertArrayNotHasKey( 'colour', $GLOBALS['fk_meta']['post'][7] );
	}

	/**
	 * A field not in the submission at all is left alone.
	 *
	 * This is the one that matters most: handing the whole set to save()
	 * would read every untouched field as cleared, across every selected
	 * post.
	 */
	public function test_a_field_absent_from_the_submission_is_left_alone(): void {
		$GLOBALS['fk_meta']['post'][7] = [
			'colour' => 'blue',
			'size'   => 'large',
		];

		$this->submit( [ 'colour' => 'red' ] );

		$this->fields(
			[
				'colour' => [ 'type' => 'text' ],
				'size'   => [ 'type' => 'text' ],
			]
		)->save( 7, $this->post() );

		$this->assertSame( 'red', $GLOBALS['fk_meta']['post'][7]['colour'] );
		$this->assertSame( 'large', $GLOBALS['fk_meta']['post'][7]['size'] );
	}

	/**
	 * An unticked "no change" select writes nothing.
	 */
	public function test_a_no_change_choice_writes_nothing(): void {
		$GLOBALS['fk_meta']['post'][7] = [ 'featured' => 1 ];

		$this->submit( [ 'featured' => '' ] );

		$this->fields( [ 'featured' => [ 'type' => 'checkbox' ] ] )->save( 7, $this->post() );

		$this->assertSame( 1, $GLOBALS['fk_meta']['post'][7]['featured'] );
	}

	/**
	 * A choice set to a real value is written, including zero.
	 *
	 * Zero is the answer "no", not the absence of one — and on this screen
	 * the difference is a checkbox someone deliberately turned off.
	 */
	public function test_a_choice_set_to_zero_is_written(): void {
		$GLOBALS['fk_meta']['post'][7] = [ 'featured' => 1 ];

		$this->submit( [ 'featured' => '0' ] );

		$this->fields( [ 'featured' => [ 'type' => 'checkbox' ] ] )->save( 7, $this->post() );

		$this->assertSame( 0, $GLOBALS['fk_meta']['post'][7]['featured'] );
	}

	/**
	 * A save that is not a bulk edit writes nothing.
	 *
	 * save_post fires for every save there is, including the one from the
	 * post editor where none of these fields were on screen.
	 */
	public function test_a_save_that_is_not_a_bulk_edit_writes_nothing(): void {
		$_REQUEST = [ 'colour' => 'red' ];

		$this->fields()->save( 7, $this->post() );

		$this->assertArrayNotHasKey( 7, $GLOBALS['fk_meta']['post'] ?? [] );
	}

	/**
	 * Another post type's save is left alone.
	 */
	public function test_another_post_types_save_is_left_alone(): void {
		$this->submit( [ 'colour' => 'red' ] );

		$this->fields()->save( 7, $this->post( 'page' ) );

		$this->assertArrayNotHasKey( 7, $GLOBALS['fk_meta']['post'] ?? [] );
	}

	/**
	 * Someone who cannot edit the post writes nothing.
	 */
	public function test_saving_requires_the_capability(): void {
		$GLOBALS['be_denied'] = [ 'edit_post' ];

		$this->submit( [ 'colour' => 'red' ] );

		$this->fields()->save( 7, $this->post() );

		$this->assertArrayNotHasKey( 7, $GLOBALS['fk_meta']['post'] ?? [] );
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
		)->save( 7, $this->post() );

		$this->assertSame( 10, $GLOBALS['fk_meta']['post'][7]['count'] );
	}

	/**
	 * Assets load on this post type's list table and nowhere else.
	 */
	public function test_assets_load_on_the_list_table_only(): void {
		$GLOBALS['be_screen'] = (object) [
			'base'      => 'post',
			'post_type' => 'post',
		];

		$this->fields()->enqueue();

		$this->assertArrayNotHasKey( 'field-kit', $GLOBALS['fk_styles'] ?? [] );
	}

	/**
	 * The panel is drawn against whatever column comes first.
	 *
	 * This used to compare the column's name with the first field's key,
	 * which is a different thing entirely: it matched only where a list table
	 * happened to have a column named after a field, and drew nothing at all
	 * otherwise. On a post type with ordinary columns — title, date — bulk
	 * edit was simply empty, and nothing said why.
	 */
	public function test_the_panel_is_drawn_against_a_column_with_no_field_of_that_name(): void {
		$fields = $this->fields();

		ob_start();
		$fields->render( 'field-kit-inline', 'post' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="colour"', $html );
	}

	/**
	 * A column is added when the post type has none of its own.
	 *
	 * core fires bulk_edit_custom_box once per column and only for columns
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
