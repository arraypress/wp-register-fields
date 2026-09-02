<?php
/**
 * User field tests.
 *
 * @package ArrayPress\RegisterFields
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterFields\Tests;

use ArrayPress\RegisterFields\UserFields;
use PHPUnit\Framework\TestCase;
use WP_User;

/**
 * The field layer is the kit's and is tested there. What is tested here is
 * what this library still decides: which of the five hooks it attaches to,
 * what a profile row looks like, and — the part that is genuinely specific to
 * users — who may see and set a field.
 *
 * A profile screen is the one place in WordPress where the object being
 * edited may be the person editing it, and a field an administrator may set
 * on someone else is not always one the subject should set on themselves.
 */
final class UserFieldsTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		uf_reset_globals();

		$_POST = [];
	}

	/**
	 * Build a set of user fields.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 *
	 * @return UserFields
	 */
	private function fields( array $fields = [] ): UserFields {
		return new UserFields(
			[] === $fields
				? [
					'department' => [
						'type'  => 'text',
						'label' => 'Department',
					],
				]
				: $fields
		);
	}

	/**
	 * A user object to render against.
	 *
	 * @param int $id The user id.
	 *
	 * @return WP_User
	 */
	private function user( int $id = 1 ): WP_User {
		$user     = new WP_User();
		$user->ID = $id;

		return $user;
	}

	/**
	 * Render a profile screen and return what it printed.
	 *
	 * @param UserFields $fields The field set.
	 * @param int        $id     The user being edited.
	 *
	 * @return string
	 */
	private function render( UserFields $fields, int $id = 1 ): string {
		ob_start();

		try {
			$fields->render_profile( $this->user( $id ) );
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Both profile screens are hooked, and both save hooks, and registration.
	 *
	 * Missing one of the pair is the classic user-fields bug: the fields
	 * appear on your own profile and not on anyone else's, or save from one
	 * screen and silently not the other.
	 */
	public function test_every_screen_and_both_save_paths_are_hooked(): void {
		$this->fields()->load_hooks();

		foreach (
			[
				'show_user_profile',
				'edit_user_profile',
				'user_new_form',
				'personal_options_update',
				'edit_user_profile_update',
				'user_register',
			] as $hook
		) {
			$this->assertArrayHasKey( $hook, $GLOBALS['fk_actions'], sprintf( '%s is not hooked.', $hook ) );
		}
	}

	/**
	 * The meta keys are declared to WordPress.
	 */
	public function test_the_meta_keys_are_registered(): void {
		$this->fields()->register_meta();

		$this->assertArrayHasKey( 'department', $GLOBALS['fk_meta_registry']['user'] ?? [] );

		// Nothing reaches REST unless it asks.
		$this->assertFalse( $GLOBALS['fk_meta_registry']['user']['department']['show_in_rest'] );
	}

	/**
	 * A field renders as a labelled table row.
	 */
	public function test_a_field_renders_as_a_labelled_row(): void {
		$html = $this->render( $this->fields() );

		$this->assertStringContainsString( '<table class="form-table" role="presentation">', $html );
		$this->assertStringContainsString( '<th scope="row">', $html );
		$this->assertStringContainsString( '<label for="department">Department</label>', $html );
		$this->assertStringContainsString( 'name="department"', $html );
	}

	/**
	 * A layout field spans the row rather than being labelled as a control.
	 */
	public function test_a_layout_field_spans_the_row(): void {
		$html = $this->render(
			$this->fields(
				[
					'intro' => [
						'type'  => 'heading',
						'label' => 'About this person',
					],
				]
			)
		);

		$this->assertStringContainsString( '<td colspan="2">', $html );
		$this->assertStringNotContainsString( '<th scope="row">', $html );
	}

	/**
	 * A field the current user cannot see is not rendered.
	 */
	public function test_a_field_without_the_capability_is_not_rendered(): void {
		$fields = $this->fields(
			[
				'notes' => [
					'type'       => 'text',
					'label'      => 'Notes',
					'capability' => 'manage_options',
				],
			]
		);

		$this->assertStringContainsString( 'name="notes"', $this->render( $fields, 2 ) );

		$GLOBALS['uf_denied'] = [ 'manage_options' ];

		$this->assertStringNotContainsString( 'name="notes"', $this->render( $fields, 2 ) );
	}

	/**
	 * Nothing renders at all when no field is permitted.
	 *
	 * Not even the heading: a section title with nothing under it reads as a
	 * screen that failed to load rather than one with nothing to show.
	 */
	public function test_no_permitted_fields_renders_nothing(): void {
		$GLOBALS['uf_denied'] = [ 'manage_options' ];

		$html = $this->render(
			$this->fields(
				[
					'notes' => [
						'type'       => 'text',
						'capability' => 'manage_options',
					],
				]
			),
			2
		);

		$this->assertSame( '', $html );
	}

	/**
	 * Editing your own profile can use a different capability.
	 *
	 * The distinction the profile screen exists to make: an administrator may
	 * set someone else's role notes, and should not be able to grant
	 * themselves the same thing by editing their own profile.
	 */
	public function test_editing_your_own_profile_can_use_its_own_capability(): void {
		$fields = $this->fields(
			[
				'internal' => [
					'type'           => 'text',
					'label'          => 'Internal',
					'capability'     => 'edit_users',
					'own_capability' => 'do_not_have_this',
				],
			]
		);

		$GLOBALS['uf_denied']       = [ 'do_not_have_this' ];
		$GLOBALS['uf_current_user'] = 1;

		// Someone else's profile: the ordinary capability applies.
		$this->assertStringContainsString( 'name="internal"', $this->render( $fields, 2 ) );

		// Their own: the stricter one does.
		$this->assertStringNotContainsString( 'name="internal"', $this->render( $fields, 1 ) );
	}

	/**
	 * A field can be kept off the add-user screen.
	 *
	 * There is no user yet, so a field that only makes sense against an
	 * existing account has nothing to show but its default.
	 */
	public function test_a_field_can_be_kept_off_the_add_screen(): void {
		$fields = $this->fields(
			[
				'department' => [
					'type'  => 'text',
					'label' => 'Department',
				],
				'last_seen'  => [
					'type'        => 'text',
					'label'       => 'Last seen',
					'show_on_add' => false,
				],
			]
		);

		ob_start();
		$fields->render_add_form( 'add-new-user' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="department"', $html );
		$this->assertStringNotContainsString( 'name="last_seen"', $html );
	}

	/**
	 * The add-existing-user form on multisite is left alone.
	 *
	 * It adds an account that already has its meta; there is nothing to fill
	 * in and filling it in would overwrite what is there.
	 */
	public function test_the_add_existing_user_form_is_left_alone(): void {
		ob_start();
		$this->fields()->render_add_form( 'add-existing-user' );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * A submitted value is saved.
	 */
	public function test_a_value_is_saved(): void {
		$_POST = [ 'department' => 'Engineering' ];

		$this->fields()->save( 7 );

		$this->assertSame( 'Engineering', $GLOBALS['fk_meta']['user'][7]['department'] );
	}

	/**
	 * Someone who cannot edit the user saves nothing.
	 */
	public function test_saving_requires_the_capability_to_edit_the_user(): void {
		$GLOBALS['uf_denied'] = [ 'edit_user' ];

		$_POST = [ 'department' => 'Engineering' ];

		$this->fields()->save( 7 );

		$this->assertArrayNotHasKey( 7, $GLOBALS['fk_meta']['user'] ?? [] );
	}

	/**
	 * A field the user cannot see cannot be written by a crafted submission.
	 *
	 * The screen hides it, and without this the hiding would be the only
	 * thing stopping it — which is not a check, it is an absence.
	 */
	public function test_a_hidden_field_cannot_be_written_by_a_submission(): void {
		$GLOBALS['uf_denied'] = [ 'manage_options' ];

		$_POST = [
			'department' => 'Engineering',
			'notes'      => 'Sneaked in',
		];

		$this->fields(
			[
				'department' => [ 'type' => 'text' ],
				'notes'      => [
					'type'       => 'text',
					'capability' => 'manage_options',
				],
			]
		)->save( 7 );

		$this->assertSame( 'Engineering', $GLOBALS['fk_meta']['user'][7]['department'] );
		$this->assertArrayNotHasKey( 'notes', $GLOBALS['fk_meta']['user'][7] );
	}

	/**
	 * A value is sanitized by its own field type on the way in.
	 */
	public function test_a_value_is_sanitized_by_its_type(): void {
		$_POST = [ 'headcount' => '9999' ];

		$this->fields(
			[
				'headcount' => [
					'type' => 'number',
					'max'  => 100,
				],
			]
		)->save( 7 );

		$this->assertSame( 100, $GLOBALS['fk_meta']['user'][7]['headcount'] );
	}

	/**
	 * Assets load on the user screens and nowhere else.
	 */
	public function test_assets_load_on_the_user_screens_only(): void {
		$GLOBALS['uf_screen'] = 'edit-post';

		$this->fields()->enqueue();

		$this->assertArrayNotHasKey( 'field-kit', $GLOBALS['fk_styles'] ?? [] );
	}

	/**
	 * Fields can be read back without an instance to hand.
	 */
	public function test_fields_can_be_read_back_statically(): void {
		$this->fields( [ 'department' => [ 'type' => 'text' ] ] );

		$this->assertArrayHasKey( 'department', UserFields::get_all_fields() );
		$this->assertSame( 'text', UserFields::get_field_by_key( 'department' )['type'] );
		$this->assertNull( UserFields::get_field_by_key( 'not_a_field' ) );
	}

	/**
	 * An encrypted field is encrypted, and reads back.
	 *
	 * The flag used to do nothing here: it rendered a password control, had
	 * REST exposure refused on its account by the meta registrar, and then
	 * stored the value in plain text in wp_usermeta — which is worse than not
	 * offering encryption at all, because everything visible says it is on.
	 *
	 * Both halves are asserted. Either alone passes for the wrong reason: a
	 * context that encrypts but cannot decrypt satisfies the first, and one
	 * that does neither satisfies the second.
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

		$_POST = [ 'api_key' => 'sk-secret-value' ];
		$fields->save( 7 );

		$stored = $GLOBALS['fk_meta']['user'][7]['api_key'];

		$this->assertNotSame( 'sk-secret-value', $stored, 'The value was stored in the clear.' );
		$this->assertStringStartsWith( 'fkenc:', (string) $stored, 'The value is not marked as encrypted.' );

		$this->assertSame( 'sk-secret-value', $fields->get_value( 7, 'api_key' ) );
	}

	/**
	 * An unsaved field reads back its configured default.
	 */
	public function test_an_unsaved_field_reads_back_its_default(): void {
		$fields = $this->fields( [ 'department' => [ 'type' => 'text', 'default' => 'Support' ] ] );

		$this->assertSame( 'Support', $fields->get_value( 7, 'department' ) );
	}

	/**
	 * The instance holding a field is found by the field's key alone.
	 *
	 * User fields are registered as sets rather than against a screen, so a
	 * field key is the only handle a caller has to find one by.
	 */
	public function test_an_instance_is_found_by_field_key(): void {
		$this->fields( [ 'shoe_size' => [ 'type' => 'number' ] ] );

		$this->assertNotNull( UserFields::get_instance_for( 'shoe_size' ) );
		$this->assertNull( UserFields::get_instance_for( 'not_a_field' ) );
	}


	/**
	 * Encryption survives the capability-restricted path.
	 *
	 * This is the one that was actually broken. The context was built inline
	 * at each `new FieldSet()`, and the set built for a user whose
	 * permissions narrowed the field list got a plain one — so an encrypted
	 * field was written in plain text, but only for those users, and only on
	 * that path. Everything on the ordinary path looked right.
	 *
	 * There is one context now, built once and shared, which is why this can
	 * only be got wrong in one place.
	 */
	public function test_a_restricted_save_still_encrypts(): void {
		$fields = $this->fields(
			[
				'api_key'  => [
					'type'      => 'password',
					'encrypted' => true,
				],
				// A field the current user may not write, which is what makes
				// the restricted set differ from the full one.
				'internal' => [
					'type'       => 'text',
					'capability' => 'manage_network',
				],
			]
		);

		$GLOBALS['uf_denied'] = [ 'manage_network' ];

		$_POST = [ 'api_key' => 'sk-restricted', 'internal' => 'nope' ];
		$fields->save( 7 );

		$this->assertArrayNotHasKey(
			'internal',
			$GLOBALS['fk_meta']['user'][7] ?? [],
			'The restricted field was written after all; this test is not exercising the path it claims.'
		);

		$stored = $GLOBALS['fk_meta']['user'][7]['api_key'];

		$this->assertStringStartsWith( 'fkenc:', (string) $stored, 'The restricted path stored plaintext.' );
		$this->assertSame( 'sk-restricted', $fields->get_value( 7, 'api_key' ) );
	}

	/**
	 * A refused value is kept, and the profile says so on the next load.
	 */
	public function test_a_refused_value_is_kept_and_reported_on_the_profile(): void {
		$GLOBALS['fk_meta']['user'][1] = [ 'department' => 'Sales' ];

		$fields = $this->fields(
			[
				'department' => [
					'type'     => 'text',
					'label'    => 'Department',
					'required' => true,
				],
			]
		);

		$_POST = [ 'department' => '' ];
		$fields->save( 1 );

		$this->assertSame( 'Sales', $GLOBALS['fk_meta']['user'][1]['department'] );
		$this->assertCount( 1, $GLOBALS['rf_transients'] );

		$html = $this->render( $fields );

		// After the heading, before the fields.
		$this->assertStringContainsString( '</h2><div class="notice notice-error inline">', $html );
		$this->assertSame( 2, substr_count( $html, 'Department is required.' ) );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );

		// Read once.
		$this->assertSame( [], $GLOBALS['rf_transients'] );
		$this->assertStringNotContainsString( 'notice-error', $this->render( $fields ) );
	}

}
