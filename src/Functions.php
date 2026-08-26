<?php
/**
 * Field Registration Functions
 *
 * @package     ArrayPress\RegisterFields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

// Exit if accessed directly
// return, not exit. This file is a Composer `files` autoload entry, so it runs
// whenever anything requires the autoloader -- phpunit, phpcs, a composer
// script. Ending the process there kills the tool with status 0 and no output,
// which reads as success: a lint that never looked at a file, or a test suite
// that never ran, both report as passing.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

use ArrayPress\RegisterFields\BulkEditFields;
use ArrayPress\RegisterFields\PostFields;
use ArrayPress\RegisterFields\QuickEditFields;
use ArrayPress\RegisterFields\TermFields;
use ArrayPress\RegisterFields\UserFields;

if ( ! function_exists( 'register_post_fields' ) ) {
	/**
	 * Register custom fields for posts via a metabox.
	 *
	 * This function provides a simple API for adding custom fields to post edit
	 * screens. Fields are automatically saved to post meta with proper sanitization
	 * and REST API integration.
	 *
	 * Supported field types:
	 * - text: Single line text input
	 * - textarea: Multi-line text input
	 * - wysiwyg: WordPress rich text editor
	 * - number: Numeric input with optional min/max/step
	 * - select: Dropdown with options (supports multiple)
	 * - checkbox: Boolean checkbox
	 * - url: URL input with validation
	 * - email: Email input with validation
	 * - color: Color picker
	 * - date: Date picker
	 * - datetime: Date and time picker
	 * - time: Time picker
	 * - image: Single image picker from media library
	 * - file: Single file picker from media library
	 * - gallery: Multiple images picker
	 * - post: Post/page selector
	 * - user: User selector
	 * - term: Taxonomy term selector
	 * - amount_type: Combined numeric input with type selector
	 * - group: Static group of fields
	 * - repeater: Dynamic repeatable group of fields
	 *
	 * @param string $id     Unique metabox identifier.
	 * @param array  $config Metabox configuration.
	 *
	 * @return PostFields|null The PostFields instance, or null on error.
	 *
	 * @example
	 * // Register simple fields for a product
	 * register_post_fields( 'product_info', [
	 *     'title'      => 'Product Information',
	 *     'post_types' => 'product',
	 *     'fields'     => [
	 *         'sku' => [
	 *             'label'       => 'SKU',
	 *             'type'        => 'text',
	 *             'placeholder' => 'PRD-0000',
	 *         ],
	 *         'price' => [
	 *             'label' => 'Price',
	 *             'type'  => 'number',
	 *             'min'   => 0,
	 *             'step'  => 0.01,
	 *         ],
	 *     ],
	 * ] );
	 *
	 * @example
	 * // Register with conditional fields
	 * register_post_fields( 'shipping_options', [
	 *     'title'      => 'Shipping Options',
	 *     'post_types' => 'product',
	 *     'fields'     => [
	 *         'is_physical' => [
	 *             'label' => 'Physical Product',
	 *             'type'  => 'checkbox',
	 *         ],
	 *         'weight' => [
	 *             'label'     => 'Weight (kg)',
	 *             'type'      => 'number',
	 *             'show_when' => [ 'is_physical' => 1 ],
	 *         ],
	 *         'dimensions' => [
	 *             'label'     => 'Dimensions',
	 *             'type'      => 'text',
	 *             'show_when' => [ 'is_physical' => 1 ],
	 *         ],
	 *     ],
	 * ] );
	 */
	function register_post_fields( string $id, array $config ): PostFields {
		return new PostFields( $id, $config );
	}
}

if ( ! function_exists( 'get_post_field_value' ) ) {
	/**
	 * Get a post meta field value with default fallback.
	 *
	 * This function retrieves a post meta value and falls back to the registered
	 * default if no value exists. Useful for ensuring consistent default values.
	 *
	 * @param int    $post_id    The post ID.
	 * @param string $meta_key   The meta key to retrieve.
	 * @param string $metabox_id Optional. The metabox ID to look up defaults from.
	 *
	 * @return mixed The field value or default.
	 *
	 * @example
	 * $price = get_post_field_value( $post_id, 'price', 'product_info' );
	 */
	function get_post_field_value( int $post_id, string $meta_key, string $metabox_id = '' ) {
		// Through the field set rather than get_post_meta(): the context is
		// where decryption happens and where the configured default comes
		// from. Reading the meta directly hands back ciphertext for an
		// encrypted field, which is what this used to do.
		if ( '' !== $metabox_id ) {
			$fields = PostFields::get_instance( $metabox_id );

			return $fields ? $fields->get_value( $post_id, $meta_key ) : null;
		}

		foreach ( array_keys( PostFields::get_all_metaboxes() ) as $id ) {
			$fields = PostFields::get_instance( (string) $id );

			if ( $fields && null !== PostFields::get_field_config( (string) $id, $meta_key ) ) {
				return $fields->get_value( $post_id, $meta_key );
			}
		}

		return null;
	}
}

if ( ! function_exists( 'get_post_fields' ) ) {
	/**
	 * Get all registered fields for a metabox.
	 *
	 * @param string $metabox_id The metabox ID.
	 *
	 * @return array Array of field configurations.
	 *
	 * @example
	 * $fields = get_post_fields( 'product_info' );
	 * foreach ( $fields as $meta_key => $config ) {
	 *     echo $config['label'] . ': ' . get_post_meta( $post_id, $meta_key, true );
	 * }
	 */
	function get_post_fields( string $metabox_id ): array {
		return PostFields::get_metabox_fields( $metabox_id );
	}
}

if ( ! function_exists( 'get_post_field_config' ) ) {
	/**
	 * Get configuration for a specific field.
	 *
	 * @param string $metabox_id The metabox ID.
	 * @param string $meta_key   The field's meta key.
	 *
	 * @return array|null The field configuration or null if not found.
	 *
	 * @example
	 * $config = get_post_field_config( 'product_info', 'price' );
	 * if ( $config ) {
	 *     echo 'Label: ' . $config['label'];
	 *     echo 'Type: ' . $config['type'];
	 * }
	 */
	function get_post_field_config( string $metabox_id, string $meta_key ): ?array {
		return PostFields::get_field_config( $metabox_id, $meta_key );
	}
}

if ( ! function_exists( 'get_all_post_field_groups' ) ) {
	/**
	 * Get all registered post field groups (metaboxes).
	 *
	 * @return array Array of metabox configurations keyed by metabox ID.
	 *
	 * @example
	 * $groups = get_all_post_field_groups();
	 * foreach ( $groups as $id => $config ) {
	 *     echo $config['title'] . ' (' . count( $config['fields'] ) . ' fields)';
	 * }
	 */
	function get_all_post_field_groups(): array {
		return PostFields::get_all_metaboxes();
	}
}


if ( ! function_exists( 'register_term_fields' ) ) {
	/**
	 * Register custom fields for taxonomy terms.
	 *
	 * This function provides a simple API for adding custom fields to taxonomy
	 * term add/edit screens. Fields are automatically saved to term meta.
	 *
	 * Supported field types:
	 * - text: Single line text input
	 * - textarea: Multi-line text input
	 * - number: Numeric input with optional min/max/step
	 * - select: Dropdown with options
	 * - checkbox: Boolean checkbox
	 * - url: URL input with validation
	 * - email: Email input with validation
	 * - amount_type: Combined numeric input with type selector (e.g., 10 + %)
	 *
	 * @param string|array $taxonomies Taxonomy or array of taxonomies to register fields for.
	 * @param array        $fields     Array of field configurations keyed by meta key.
	 *
	 * @return array Array of TermFields instances, or empty array on error.
	 *
	 * @example
	 * // Register a simple text field
	 * register_term_fields( 'category', [
	 *     'subtitle' => [
	 *         'label'       => 'Subtitle',
	 *         'type'        => 'text',
	 *         'description' => 'A subtitle for this category.',
	 *     ],
	 * ] );
	 *
	 * @example
	 * // Register multiple fields across multiple taxonomies
	 * register_term_fields( [ 'category', 'post_tag' ], [
	 *     'color' => [
	 *         'label'       => 'Color',
	 *         'type'        => 'text',
	 *         'placeholder' => '#000000',
	 *     ],
	 *     'featured' => [
	 *         'label' => 'Featured',
	 *         'type'  => 'checkbox',
	 *     ],
	 * ] );
	 *
	 * @example
	 * // Register a select field with dynamic options
	 * register_term_fields( 'product_cat', [
	 *     'tax_class' => [
	 *         'label'   => 'Tax Class',
	 *         'type'    => 'select',
	 *         'options' => function() {
	 *             return [
	 *                 ''         => '— Default —',
	 *                 'reduced'  => 'Reduced Rate',
	 *                 'zero'     => 'Zero Rate',
	 *             ];
	 *         },
	 *     ],
	 * ] );
	 *
	 * @example
	 * // Register an amount_type field for discounts (percentage or flat amount)
	 * register_term_fields( 'download_category', [
	 *     '_sale_amount' => [
	 *         'label'         => 'Sale Discount',
	 *         'type'          => 'amount_type',
	 *         'description'   => 'Enter a discount amount. Leave empty for no discount.',
	 *         'type_meta_key' => '_sale_type',
	 *         'type_options'  => [
	 *             'percent' => '%',
	 *             'flat'    => '$',
	 *         ],
	 *         'type_default'  => 'percent',
	 *         'min'           => 0,
	 *         'max'           => 100, // Optional: limit for percentage
	 *         'placeholder'   => '0.00',
	 *     ],
	 * ] );
	 *
	 * @example
	 * // Amount type with dynamic currency symbol
	 * register_term_fields( 'product_cat', [
	 *     '_discount_amount' => [
	 *         'label'         => 'Category Discount',
	 *         'type'          => 'amount_type',
	 *         'type_meta_key' => '_discount_type',
	 *         'type_options'  => function() {
	 *             return [
	 *                 'percent' => '%',
	 *                 'flat'    => function_exists( 'get_currency_symbol' )
	 *                     ? get_currency_symbol()
	 *                     : '$',
	 *             ];
	 *         },
	 *     ],
	 * ] );
	 */
	function register_term_fields( $taxonomies, array $fields ): array {
		$instances = [];

		// Convert single taxonomy to array
		if ( is_string( $taxonomies ) ) {
			$taxonomies = [ $taxonomies ];
		}

		foreach ( $taxonomies as $taxonomy ) {
			try {
				$instances[] = new TermFields( $taxonomy, $fields );
			} catch ( Exception $e ) {
				error_log( 'WP Register Term Fields Error: ' . $e->getMessage() );
			}
		}

		return $instances;
	}
}

if ( ! function_exists( 'get_term_field_value' ) ) {
	/**
	 * Read a registered term field's value.
	 *
	 * Use this rather than get_term_meta(): the field's own context is where
	 * decryption happens and where its configured default comes from, so a
	 * direct meta read hands back ciphertext for an encrypted field and an
	 * empty string for one that has never been saved.
	 *
	 * @param int    $term_id  The term id.
	 * @param string $key      Field key.
	 * @param mixed  $fallback Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	function get_term_field_value( int $term_id, string $key, mixed $fallback = null ): mixed {
		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return $fallback;
		}

		$fields = TermFields::get_instance( $term->taxonomy );

		return $fields ? $fields->get_value( $term_id, $key, $fallback ) : $fallback;
	}
}

if ( ! function_exists( 'get_term_fields' ) ) {
	/**
	 * The field set registered for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return TermFields|null
	 */
	function get_term_fields( string $taxonomy ): ?TermFields {
		return TermFields::get_instance( $taxonomy );
	}
}


if ( ! function_exists( 'register_user_fields' ) ) {
	/**
	 * Register custom fields for user profiles.
	 *
	 * This function provides a simple API for adding custom fields to user
	 * profile screens. Fields are automatically saved to user meta.
	 *
	 * @param array  $fields        Array of field configurations keyed by meta key.
	 * @param string $section_title Optional. Section title displayed above fields.
	 *                              Default 'Additional Information'.
	 *
	 * @return UserFields|null The UserFields instance, or null on error.
	 *
	 * @example
	 * // Register simple text fields
	 * register_user_fields( [
	 *     'company' => [
	 *         'label'       => 'Company',
	 *         'type'        => 'text',
	 *         'description' => 'Your company or organization.',
	 *     ],
	 *     'job_title' => [
	 *         'label' => 'Job Title',
	 *         'type'  => 'text',
	 *     ],
	 * ] );
	 *
	 * @example
	 * // Register with custom section title
	 * register_user_fields( [
	 *     'department' => [
	 *         'label'   => 'Department',
	 *         'type'    => 'select',
	 *         'options' => [
	 *             ''        => '— Select —',
	 *             'sales'   => 'Sales',
	 *             'support' => 'Support',
	 *             'dev'     => 'Development',
	 *         ],
	 *     ],
	 * ], 'Employee Information' );
	 *
	 * @example
	 * // Register with dynamic options
	 * register_user_fields( [
	 *     'manager' => [
	 *         'label'   => 'Manager',
	 *         'type'    => 'select',
	 *         'options' => function() {
	 *             $managers = get_users( [ 'role' => 'administrator' ] );
	 *             $options  = [ '' => '— Select Manager —' ];
	 *             foreach ( $managers as $manager ) {
	 *                 $options[ $manager->ID ] = $manager->display_name;
	 *             }
	 *             return $options;
	 *         },
	 *     ],
	 * ] );
	 */
	function register_user_fields( array $fields, string $section_title = '' ): UserFields {
		return new UserFields( $fields, $section_title );
	}
}

if ( ! function_exists( 'get_user_field_value' ) ) {
	/**
	 * Read a registered user field's value.
	 *
	 * Use this rather than get_user_meta(): the field's own context is where
	 * decryption happens and where its configured default comes from, so a
	 * direct meta read hands back ciphertext for an encrypted field and an
	 * empty string for one that has never been saved.
	 *
	 * @param int    $user_id  The user id.
	 * @param string $key      Field key.
	 * @param mixed  $fallback Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	function get_user_field_value( int $user_id, string $key, mixed $fallback = null ): mixed {
		$fields = UserFields::get_instance_for( $key );

		return $fields ? $fields->get_value( $user_id, $key, $fallback ) : $fallback;
	}
}


if ( ! function_exists( 'register_quick_edit_fields' ) ) :
	/**
	 * Register quick edit fields for posts or custom post types.
	 *
	 * @param string|array $post_types Post type(s) to register fields for.
	 * @param array        $fields     Array of field configurations.
	 *
	 * @return void
	 * @throws Exception
	 */
	function register_quick_edit_fields( $post_types, array $fields ): void {
		$post_types = (array) $post_types;

		foreach ( $post_types as $post_type ) {
			new QuickEditFields( $fields, $post_type );
		}
	}
endif;


if ( ! function_exists( 'register_bulk_edit_fields' ) ) :
	/**
	 * Register bulk edit fields for posts or custom post types.
	 *
	 * @param string|array $post_types Post type(s) to register fields for.
	 * @param array        $fields     Array of field configurations.
	 *
	 * @return void
	 */
	function register_bulk_edit_fields( $post_types, array $fields ): void {
		$post_types = (array) $post_types;

		foreach ( $post_types as $post_type ) {
			new BulkEditFields( $fields, $post_type );
		}
	}
endif;
