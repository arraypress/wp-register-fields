# WordPress Register Fields

Add custom fields to any WordPress screen with an array, not a class hierarchy.

## What it does

Adding fields to a post, a term, a user profile, quick edit or bulk edit means
five different WordPress APIs, each with its own render callback, its own save
handler, and its own nonce and capability dance. The field markup is the same
every time; only the plumbing differs.

This takes one field definition and handles whichever plumbing applies. Values
save themselves, each type sanitises appropriately, and a post field is exposed
over the REST API with one flag.

Field rendering, types and sanitisation come from
[wp-field-kit](https://github.com/arraypress/wp-field-kit), so a field looks and
behaves the same wherever it appears.

## Features

- One field definition, usable on posts, terms, users, quick edit and bulk edit
- Over 30 field types, including media pickers, colour, date and WYSIWYG
- Show a field only when another field has a particular value
- Repeatable groups of fields, reorderable by dragging
- Searchable dropdowns for posts, terms and users that load over AJAX
- Hide fields from users who lack a capability, on render *and* on save
- Bulk edit understands "no change", so an empty field does not wipe a value
- Read values back with helpers that apply the same prefixing rules

## Installation

```bash
composer require arraypress/wp-register-fields
```

## Quick start

```php
add_action( 'init', function () {
    register_post_fields( 'product_info', [
        'title'      => __( 'Product Information', 'textdomain' ),
        'post_types' => 'product',
        'fields'     => [
            'sku'   => [
                'label' => __( 'SKU', 'textdomain' ),
                'type'  => 'text',
            ],
            'price' => [
                'label' => __( 'Price', 'textdomain' ),
                'type'  => 'number',
                'min'   => 0,
                'step'  => 0.01,
            ],
        ],
    ] );
} );

// Reading it back.
$sku = get_post_field_value( $post_id, 'sku' );
```

The same field array works on the other screens:

```php
register_term_fields( 'category', [ /* ... */ ] );
register_user_fields( [ /* ... */ ] );
register_quick_edit_fields( 'product', [ /* ... */ ] );
register_bulk_edit_fields( 'product', [ /* ... */ ] );

// And read back the same way.
$colour = get_term_field_value( $term_id, 'colour' );
$phone  = get_user_field_value( $user_id, 'phone' );
$fields = get_term_fields( 'category' ); // The registered set, for get_value() and friends.
```

## Documentation

Every field type, the conditional-display rules, repeaters, and the full list
of configuration options are documented on the docs site.

<!-- TODO: link the docs site once it exists. -->

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
