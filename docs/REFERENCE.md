# Reference

## WP-CLI

```sh
wp axle-page-template-usage list [--post_type=<type>] [--status=<status>] [--format=<format>]
```

Lists every template with the number of items that use it. `--status` is `default`, `used`, `unused` or `missing`. `--format` is `table` (default), `csv`, `json`, `yaml` or `count`.

```sh
wp axle-page-template-usage reassign <from> <to> [--post_type=<type>]
```

Moves every item from one template to another, 100 at a time, with no limit. `<from>` is a template file or slug, `default`, or `missing` for every template the theme no longer has. `<to>` is a template the theme offers, or `default`. `--post_type` defaults to `page`.

The Move button on Appearance > Axle Page Template Usage moves at most 500 items per click, skips items you are not allowed to edit, and says how many are left.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `axle_page_template_usage_post_types` | filter | `( string[] $post_types )`. Post types to look at. Types without templates are left out on their own |
| `axle_page_template_usage_sortable` | filter | `( bool $sortable, string $post_type )`. Return `false` to turn off sorting by template, which joins postmeta and is slow on very large sites |
| `axle_page_template_usage_capability` | filter | `( string $capability )`. Who may open Appearance > Axle Page Template Usage. Default `edit_pages` |
| `axle_page_template_usage_move_limit` | filter | `( int $limit, string $post_type )`. Items one Move click handles. Default 500 |
| `axle_page_template_usage_show_missing_notice` | filter | `( bool $show, string $post_type, int $missing )`. Whether the list screen warns about items on missing templates |
| `axle_page_template_usage_before_reassign` | action | `( string $post_type, string $from, string $to, int $limit )`. Fires before items move, from the screen or WP-CLI |
| `axle_page_template_usage_reassigned` | action | `( string $post_type, string $from, string $to, int[] $post_ids )`. Fires for every batch of items moved |

The templates themselves come from core, so the core `theme_{$post_type}_templates` filter adds or removes them everywhere, including in this plugin.

## Abilities

On WordPress 6.9 and later, Axle Page Template Usage registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. Handy after a theme switch.

| Ability | Does | Needs |
| --- | --- | --- |
| `axle-page-template-usage/get-report` | Read-only. Every template for `post_type` (default `page`) with how many items use it, and the number on missing templates | the Axle Page Template Usage capability |
| `axle-page-template-usage/reassign` | Moves items from `from` (a template, `default` or `missing`) to `to`, at most 500 per run, skipping items the user cannot edit. Returns `moved`, `skipped` and `remaining` | the Axle Page Template Usage capability and `edit_posts` on the post type |

## PHP API

```php
$templates = AryanX\Axle\PageTemplateUsage\Plugin::instance()->templates();

$templates->get_report( 'page' );                     // one row per template: slug, name, count, status
$templates->get_missing_counts( 'page' );             // [ 'templates/old.php' => 3 ]
$templates->reassign( 'page', 'templates/old.php', 'default' );        // [ 'moved' => n, 'skipped' => 0, 'remaining' => 0 ] or WP_Error
$templates->reassign( 'page', 'templates/old.php', 'default', 500, true ); // at most 500, skipping items the current user cannot edit
```

`Templates::MISSING` and `Templates::DEFAULT_TEMPLATE` are the special values for `reassign()` and `meta_query_for()`.

## Data

Nothing is stored. Templates come from `wp_get_theme()->get_page_templates()` and usage from the `_wp_page_template` post meta WordPress already keeps.

Counts come from one grouped query per post type and stay in the object cache until a template or a post status changes, and an hour at most. Busy sites that save posts all day keep their cache.
