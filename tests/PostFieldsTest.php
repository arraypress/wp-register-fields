<?php
/**
 * Post metabox tests.
 *
 * @package ArrayPress\RegisterFields
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterFields\Tests;

use ArrayPress\RegisterFields\PostFields;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The field layer is the kit's and is tested there. What is tested here is
 * what a metabox still decides: registering the box, the nonce that guards
 * it, and the two questions a post screen asks that a term screen does not —
 * may this user edit *this* post, and may they see *this* field.
 *
 * The save path gets the most attention, because a metabox has three ways to
 * be absent from a submission that each look like "clear every field" if
 * nobody checks: an autosave, a box hidden in Screen Options, and a screen
 * for another post type entirely.
 */
final class PostFieldsTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		pf_reset_globals();

		$_POST = [];
	}

	/**
	 * Build a metabox.
	 *
	 * @param array<string, mixed> $config Configuration overrides.
	 *
	 * @return PostFields
	 */
	private function metabox( array $config = [] ): PostFields {
		return new PostFields(
			'demo_box',
			array_merge(
				[
					'title'      => 'Demo',
					'post_types' => [ 'post' ],
					'fields'     => [
						'colour' => [
							'type'  => 'text',
							'label' => 'Colour',
						],
					],
				],
				$config
			)
		);
	}

	/**
	 * A post to render or save against.
	 *
	 * @param int    $id   Post id.
	 * @param string $type Post type.
	 *
	 * @return WP_Post
	 */
	private function post( int $id = 7, string $type = 'post' ): WP_Post {
		$post            = new WP_Post();
		$post->ID        = $id;
		$post->post_type = $type;

		return $post;
	}

	/**
	 * A submission carrying this metabox's nonce.
	 *
	 * @param array<string, mixed> $values Submitted values.
	 *
	 * @return void
	 */
	private function submit( array $values ): void {
		$_POST = array_merge( [ 'demo_box_nonce' => 'nonce-save_demo_box' ], $values );
	}

	/**
	 * Render the metabox and return what it printed.
	 *
	 * @param PostFields $metabox The metabox.
	 * @param int        $post_id The post.
	 *
	 * @return string
	 */
	private function render( PostFields $metabox, int $post_id = 7 ): string {
		ob_start();

		try {
			$metabox->render( $this->post( $post_id ) );
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * The box is registered for every post type it names.
	 */
	public function test_the_box_is_registered_for_each_post_type(): void {
		$this->metabox( [ 'post_types' => [ 'post', 'page' ] ] )->add_meta_box();

		$this->assertCount( 2, $GLOBALS['pf_boxes'] );
		$this->assertSame( [ 'post', 'page' ], array_column( $GLOBALS['pf_boxes'], 'screen' ) );
		$this->assertSame( 'demo_box', $GLOBALS['pf_boxes'][0]['id'] );
	}

	/**
	 * The meta keys are declared, once per post type.
	 *
	 * A key registered without a subtype applies to every post type there is,
	 * which is not what a metabox on two of them means.
	 */
	public function test_the_meta_keys_are_registered_per_post_type(): void {
		$this->metabox( [ 'post_types' => [ 'post', 'page' ] ] )->register_meta();

		$this->assertArrayHasKey( 'colour', $GLOBALS['fk_meta_registry']['post'] ?? [] );
		$this->assertSame( 'page', $GLOBALS['fk_meta_registry']['post']['colour']['object_subtype'] );
	}

	/**
	 * Nothing reaches REST unless a field asks.
	 *
	 * The default was true for every field, which publishes every custom
	 * field a plugin has ever registered to the REST API and the block
	 * editor, including the ones holding a licence key.
	 */
	public function test_rest_exposure_is_off_by_default(): void {
		$this->metabox(
			[
				'fields' => [
					'quiet' => [ 'type' => 'text' ],
					'loud'  => [
						'type'         => 'text',
						'show_in_rest' => true,
					],
				],
			]
		)->register_meta();

		$this->assertFalse( $GLOBALS['fk_meta_registry']['post']['quiet']['show_in_rest'] );
		$this->assertIsArray( $GLOBALS['fk_meta_registry']['post']['loud']['show_in_rest'] );
	}

	/**
	 * A prefix reaches the meta key, and both getters agree about it.
	 */
	public function test_a_prefix_is_applied_once_and_consistently(): void {
		$metabox = $this->metabox( [ 'prefix' => '_demo_' ] );

		$this->assertArrayHasKey( '_demo_colour', $metabox->get_fields() );
		$this->assertArrayHasKey( '_demo_colour', $metabox->get_config()['fields'] );
		$this->assertStringContainsString( 'name="_demo_colour"', $this->render( $metabox ) );
	}

	/**
	 * The metabox renders a nonce and a labelled row per field.
	 */
	public function test_it_renders_a_nonce_and_a_row_per_field(): void {
		$html = $this->render( $this->metabox() );

		$this->assertStringContainsString( 'name="demo_box_nonce"', $html );
		$this->assertStringContainsString( '<label for="colour">Colour</label>', $html );
		$this->assertStringContainsString( 'name="colour"', $html );
	}

	/**
	 * A layout field spans the row.
	 */
	public function test_a_layout_field_spans_the_row(): void {
		$html = $this->render(
			$this->metabox(
				[
					'fields' => [
						'intro' => [
							'type'  => 'heading',
							'label' => 'About',
						],
					],
				]
			)
		);

		$this->assertStringContainsString( '<td colspan="2">', $html );
		$this->assertStringNotContainsString( '<th scope="row">', $html );
	}

	/**
	 * Panels render as a tab list.
	 *
	 * The shape EDD's download metabox uses: a handful of named sections with
	 * an icon each, only one on screen.
	 */
	public function test_panels_render_as_tabs(): void {
		$html = $this->render(
			$this->metabox(
				[
					'fields' => [],
					'panels' => [
						'files' => [
							'label'  => 'Files',
							'icon'   => 'media-default',
							'fields' => [ 'file_url' => [ 'type' => 'url' ] ],
						],
						'notes' => [
							'label'  => 'Notes',
							'fields' => [ 'note' => [ 'type' => 'textarea' ] ],
						],
					],
				]
			)
		);

		$this->assertStringContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( '>Files</span>', $html );
		$this->assertStringContainsString( 'dashicons-media-default', $html );
		$this->assertStringContainsString( 'name="file_url"', $html );
		$this->assertStringContainsString( 'name="note"', $html );
	}

	/**
	 * A panel is a layout, not a second place to declare fields.
	 *
	 * Flattened at construction, so the save path, the meta registration and
	 * the capability checks never learn there was a panel — which is the
	 * difference between a layout and a second concept.
	 */
	public function test_panel_fields_save_and_register_like_any_other(): void {
		$metabox = $this->metabox(
			[
				'fields' => [],
				'panels' => [
					'files' => [
						'label'  => 'Files',
						'fields' => [ 'file_url' => [ 'type' => 'url' ] ],
					],
				],
			]
		);

		$this->assertArrayHasKey( 'file_url', $metabox->get_fields() );

		$metabox->register_meta();
		$this->assertArrayHasKey( 'file_url', $GLOBALS['fk_meta_registry']['post'] ?? [] );

		$this->submit( [ 'file_url' => 'https://example.test/file.zip' ] );
		$metabox->save( 7, $this->post() );

		$this->assertSame( 'https://example.test/file.zip', $GLOBALS['fk_meta']['post'][7]['file_url'] );
	}

	/**
	 * A prefix reaches a panel's fields too.
	 */
	public function test_a_prefix_applies_inside_a_panel(): void {
		$html = $this->render(
			$this->metabox(
				[
					'prefix' => '_demo_',
					'fields' => [],
					'panels' => [
						'files' => [
							'label'  => 'Files',
							'fields' => [ 'file_url' => [ 'type' => 'url' ] ],
						],
					],
				]
			)
		);

		$this->assertStringContainsString( 'name="_demo_file_url"', $html );
	}

	/**
	 * A panel with nothing left in it is dropped, not shown empty.
	 *
	 * A tab that opens onto nothing reads as a screen that failed to load.
	 */
	public function test_an_empty_panel_is_dropped(): void {
		$GLOBALS['pf_denied'] = [ 'manage_options' ];

		$html = $this->render(
			$this->metabox(
				[
					'fields' => [],
					'panels' => [
						'open'   => [
							'label'  => 'Open',
							'fields' => [ 'colour' => [ 'type' => 'text' ] ],
						],
						'closed' => [
							'label'  => 'Closed',
							'fields' => [
								'secret' => [
									'type'       => 'text',
									'capability' => 'manage_options',
								],
							],
						],
					],
				]
			)
		);

		$this->assertStringContainsString( '>Open</span>', $html );
		$this->assertStringNotContainsString( '>Closed</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'role="tab"' ) );
	}

	/**
	 * Without panels the metabox is still one table.
	 */
	public function test_a_metabox_without_panels_is_a_plain_table(): void {
		$html = $this->render( $this->metabox() );

		$this->assertStringContainsString( '<table class="form-table"', $html );
		$this->assertStringNotContainsString( 'role="tablist"', $html );
	}

	/**
	 * A field the current user cannot see is not rendered.
	 */
	public function test_a_field_without_the_capability_is_not_rendered(): void {
		$metabox = $this->metabox(
			[
				'fields' => [
					'secret' => [
						'type'       => 'text',
						'capability' => 'manage_options',
					],
				],
			]
		);

		$this->assertStringContainsString( 'name="secret"', $this->render( $metabox ) );

		$GLOBALS['pf_denied'] = [ 'manage_options' ];

		$this->assertStringNotContainsString( 'name="secret"', $this->render( $metabox ) );
	}

	/**
	 * A submitted value is saved.
	 */
	public function test_a_value_is_saved(): void {
		$this->submit( [ 'colour' => 'blue' ] );

		$this->metabox()->save( 7, $this->post() );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['post'][7]['colour'] );
	}

	/**
	 * An autosave saves nothing.
	 *
	 * The fields are not in an autosave, so saving would read every one of
	 * them as cleared — which is how a metabox empties itself while someone
	 * is typing in the title.
	 *
	 * In its own process because DOING_AUTOSAVE is a constant: defined here,
	 * it stays defined, and every save after this one in the same run would
	 * do nothing. Which is exactly what happened when it was not.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_an_autosave_saves_nothing(): void {
		if ( ! defined( 'DOING_AUTOSAVE' ) ) {
			define( 'DOING_AUTOSAVE', true );
		}

		$GLOBALS['fk_meta']['post'][7] = [ 'colour' => 'blue' ];

		$this->submit( [] );

		$this->metabox()->save( 7, $this->post() );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['post'][7]['colour'] );
	}

	/**
	 * A submission without this metabox's nonce saves nothing.
	 *
	 * A metabox can be absent from a submission — hidden in Screen Options,
	 * or a quick edit — and its nonce with it. Saving then would clear every
	 * field it holds.
	 */
	public function test_a_submission_without_the_nonce_saves_nothing(): void {
		$GLOBALS['fk_meta']['post'][7] = [ 'colour' => 'blue' ];

		$_POST = [ 'colour' => 'red' ];

		$this->metabox()->save( 7, $this->post() );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['post'][7]['colour'] );
	}

	/**
	 * An invalid nonce saves nothing either.
	 */
	public function test_an_invalid_nonce_saves_nothing(): void {
		$GLOBALS['pf_nonce_ok'] = false;

		$this->submit( [ 'colour' => 'red' ] );

		$this->metabox()->save( 7, $this->post() );

		$this->assertArrayNotHasKey( 7, $GLOBALS['fk_meta']['post'] ?? [] );
	}

	/**
	 * Another post type's save is left alone.
	 */
	public function test_another_post_type_is_left_alone(): void {
		$this->submit( [ 'colour' => 'red' ] );

		$this->metabox()->save( 7, $this->post( 7, 'page' ) );

		$this->assertArrayNotHasKey( 7, $GLOBALS['fk_meta']['post'] ?? [] );
	}

	/**
	 * Someone who cannot edit the post saves nothing.
	 */
	public function test_saving_requires_the_capability_to_edit_the_post(): void {
		$GLOBALS['pf_denied'] = [ 'edit_post' ];

		$this->submit( [ 'colour' => 'red' ] );

		$this->metabox()->save( 7, $this->post() );

		$this->assertArrayNotHasKey( 7, $GLOBALS['fk_meta']['post'] ?? [] );
	}

	/**
	 * A field the user cannot see cannot be written by a crafted submission.
	 *
	 * Hiding a control is not a check; without this the hiding would be the
	 * only thing stopping it.
	 */
	public function test_a_hidden_field_cannot_be_written(): void {
		$GLOBALS['pf_denied'] = [ 'manage_options' ];

		$this->submit(
			[
				'colour' => 'blue',
				'secret' => 'sneaked in',
			]
		);

		$this->metabox(
			[
				'fields' => [
					'colour' => [ 'type' => 'text' ],
					'secret' => [
						'type'       => 'text',
						'capability' => 'manage_options',
					],
				],
			]
		)->save( 7, $this->post() );

		$this->assertSame( 'blue', $GLOBALS['fk_meta']['post'][7]['colour'] );
		$this->assertArrayNotHasKey( 'secret', $GLOBALS['fk_meta']['post'][7] );
	}

	/**
	 * A value is sanitized by its own field type.
	 */
	public function test_a_value_is_sanitized_by_its_type(): void {
		$this->submit( [ 'count' => '9999' ] );

		$this->metabox(
			[
				'fields' => [
					'count' => [
						'type' => 'number',
						'max'  => 10,
					],
				],
			]
		)->save( 7, $this->post() );

		$this->assertSame( 10, $GLOBALS['fk_meta']['post'][7]['count'] );
	}

	/**
	 * Assets load on this metabox's post types and nowhere else.
	 */
	public function test_assets_load_on_the_right_screens_only(): void {
		$GLOBALS['pf_screen'] = (object) [
			'base'      => 'post',
			'post_type' => 'page',
		];

		$this->metabox()->enqueue();

		$this->assertArrayNotHasKey( 'field-kit', $GLOBALS['fk_styles'] ?? [] );
	}

	/**
	 * Metaboxes can be read back without an instance to hand.
	 */
	public function test_metaboxes_can_be_read_back_statically(): void {
		$this->metabox();

		$this->assertArrayHasKey( 'demo_box', PostFields::get_all_metaboxes() );
		$this->assertSame( 'Demo', PostFields::get_metabox( 'demo_box' )['title'] );
		$this->assertArrayHasKey( 'colour', PostFields::get_metabox_fields( 'demo_box' ) );
		$this->assertSame( 'text', PostFields::get_field_config( 'demo_box', 'colour' )['type'] );
		$this->assertNull( PostFields::get_field_config( 'demo_box', 'nope' ) );
	}

	/**
	 * An encrypted field is encrypted, and reads back.
	 *
	 * Two things were wrong at once here. The metabox's context was not
	 * wrapped in EncryptedContext, so `encrypted` stored plaintext while the
	 * control rendered as a password field and the meta registrar refused
	 * REST exposure on its account. And get_post_field_value() read
	 * get_post_meta() directly, so even once it did encrypt, the accessor
	 * would have handed the caller ciphertext.
	 *
	 * Both halves are asserted. Either alone passes for the wrong reason: a
	 * context that encrypts but cannot decrypt satisfies the first, and one
	 * that does neither satisfies the second.
	 */
	public function test_an_encrypted_field_is_stored_encrypted_and_reads_back(): void {
		$metabox = $this->metabox(
			[
				'fields' => [
					'api_key' => [
						'type'      => 'password',
						'label'     => 'API key',
						'encrypted' => true,
					],
				],
			]
		);

		$this->submit( [ 'api_key' => 'sk-secret-value' ] );
		$metabox->save( 11, $this->post() );

		$stored = $GLOBALS['fk_meta']['post'][11]['api_key'];

		$this->assertNotSame( 'sk-secret-value', $stored, 'The value was stored in the clear.' );
		$this->assertStringStartsWith( 'fkenc:', (string) $stored, 'The value is not marked as encrypted.' );

		$this->assertSame( 'sk-secret-value', $metabox->get_value( 11, 'api_key' ) );
	}

	/**
	 * An unsaved field reads back its configured default.
	 */
	public function test_an_unsaved_field_reads_back_its_default(): void {
		$metabox = $this->metabox(
			[
				'fields' => [
					'colour' => [
						'type'    => 'text',
						'default' => 'blue',
					],
				],
			]
		);

		$this->assertSame( 'blue', $metabox->get_value( 11, 'colour' ) );
	}

}
