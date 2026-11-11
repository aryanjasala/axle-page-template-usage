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

The Move button on Appearance > Axle Page Template Usage moves at most 500 items per click, skips items you are not allowed to edit, and says how many are left. Every move gets a batch ID, shown on the Move notice and printed by the command.

```sh
wp axle-page-template-usage check-theme <theme> [--post_type=<type>] [--format=<format>]
```

Lists what would lose its template if the installed theme `<theme>` (not the active one) became active: one row per post type and template, with the item count, whether the template is already missing now (`already_missing`), and what the saved plan uses instead (`planned`). Changes nothing.

```sh
wp axle-page-template-usage set-mapping <theme> <from> <to> [--post_type=<type>]
wp axle-page-template-usage apply-mapping
```

`set-mapping` saves one entry of the plan for `<theme>`: items on `<from>` (a template, or `missing` for every template the theme lacks) get `<to>` (a template of that theme, or `default`). `apply-mapping` moves items per the saved plan of the **active** theme, with no limit. Core runs the plan by itself on the first request after a theme switch, including the first WP-CLI command after `wp theme activate`. The `axle_page_template_usage_apply_on_switch` filter turns that off.

```sh
wp axle-page-template-usage history [--post=<id>] [--source=<source>] [--user=<id>] [--batch=<batch>] [--limit=<n>] [--format=<format>]
wp axle-page-template-usage undo <id>
wp axle-page-template-usage undo-batch <batch>
```

`history` lists recorded changes, newest first, and whether each can still be undone. `undo` undoes one change if the page still has the template it set. `undo-batch` undoes a whole batch (one move, one theme switch), skipping pages that changed since. WP-CLI has no user, so no edit checks and no limit.

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
| `axle_page_template_usage_theme_check_capability` | filter | `( string $capability )`. Who may save or remove a plan for a theme. Default `switch_themes` |
| `axle_page_template_usage_theme_templates` | filter | `( array $templates, string $stylesheet, string $post_type, WP_Theme $theme )`. Templates a theme that is not active offers, when checking it |
| `axle_page_template_usage_theme_check_sample_size` | filter | `( int $size )`. Example items listed under each template in the check. Default 5 |
| `axle_page_template_usage_theme_mapping` | filter | `( array $mapping, string $stylesheet )`. The plan used on activation, `post type => ( from => to )`. `missing` as `from` means every template the theme lacks |
| `axle_page_template_usage_switch_batch_size` | filter | `( int $size, string $stylesheet )`. Items moved per request after a theme switch. Default 500, the rest follows in the background |
| `axle_page_template_usage_apply_on_switch` | filter | `( bool $apply, string $stylesheet )`. Return `false` to keep the plan and run it by hand |
| `axle_page_template_usage_show_switch_notice` | filter | `( bool $show, array $state )`. Whether the notice with the Undo shows after a switch |
| `axle_page_template_usage_mapping_saved` | action | `( string $stylesheet, array $mapping )`. Fires after a plan is saved or cleared (empty mapping) |
| `axle_page_template_usage_mapping_applied` | action | `( string $stylesheet, array $state )`. Fires after items moved to match a theme that was just activated, once per run |
| `axle_page_template_usage_history_cap` | filter | `( int $cap )`. Changes the history keeps, oldest dropped first. Default 10000, minimum 100 |
| `axle_page_template_usage_history_retention_days` | filter | `( int $days )`. Days an entry is kept. Default 365, 0 for no age limit |
| `axle_page_template_usage_history_should_record` | filter | `( bool $record, array $entry )`. Return `false` to leave a change out of the history |
| `axle_page_template_usage_history_recorded` | action | `( array $entry )`. Fires when a change is recorded, before it is written |
| `axle_page_template_usage_history_per_page` | filter | `( int $per_page )`. Entries per page on the History tab. Default 20 |
| `axle_page_template_usage_history_source_labels` | filter | `( array $labels )`. Names shown for the source of a change |
| `axle_page_template_usage_can_undo` | filter | `( bool $allowed, object $entry )`. Last word on whether an entry may be undone |
| `axle_page_template_usage_undo_limit` | filter | `( int $limit )`. Changes one "Undo this batch" click handles. Default 500 |
| `axle_page_template_usage_undone` | action | `( object $entry )`. Fires after one change was undone |
| `axle_page_template_usage_batch_undone` | action | `( string $batch, array $summary )`. Fires after a batch was undone, in whole or in part |

The templates themselves come from core, so the core `theme_{$post_type}_templates` filter adds or removes them everywhere, including in this plugin.

## Abilities

On WordPress 6.9 and later, Axle Page Template Usage registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. Handy after a theme switch.

| Ability | Does | Needs |
| --- | --- | --- |
| `axle-page-template-usage/get-report` | Read-only. Every template for `post_type` (default `page`) with how many items use it, and the number on missing templates | the Axle Page Template Usage capability |
| `axle-page-template-usage/reassign` | Moves items from `from` (a template, `default` or `missing`) to `to`, at most 500 per run, skipping items the user cannot edit. Returns `moved`, `skipped`, `remaining` and the `batch` ID of the move | the Axle Page Template Usage capability and `edit_posts` on the post type |
| `axle-page-template-usage/check-theme` | Read-only. For an installed theme that is not active (`theme`), which items would lose their template, grouped by template with counts, and what a saved plan uses instead | the Axle Page Template Usage capability |
| `axle-page-template-usage/get-history` | Read-only. Recorded template changes, newest first, filtered by `post_id`, `source` or `batch`, with whether each can still be undone | the Axle Page Template Usage capability |

## PHP API

```php
$templates = AryanX\Axle\PageTemplateUsage\Plugin::instance()->templates();

$templates->get_report( 'page' );                     // one row per template: slug, name, count, status
$templates->get_missing_counts( 'page' );             // [ 'templates/old.php' => 3 ]
$templates->reassign( 'page', 'templates/old.php', 'default' );        // [ 'moved' => n, 'skipped' => 0, 'remaining' => 0 ] or WP_Error
$templates->reassign( 'page', 'templates/old.php', 'default', 500, true ); // at most 500, skipping items the current user cannot edit

$plugin = AryanX\Axle\PageTemplateUsage\Plugin::instance();
$plugin->theme_check()->check( 'twentytwentyfour' );   // what would lose its template, grouped by post type and template
$plugin->history()->query( array( 'post_id' => 42 ) ); // [ 'items' => rows, 'total' => n ]
$plugin->history()->undo_batch( $batch );              // [ 'undone' => n, 'skipped' => n, 'remaining' => n, 'batch' => id ]
```

`Templates::MISSING` and `Templates::DEFAULT_TEMPLATE` are the special values for `reassign()` and `meta_query_for()`.

## Data

Templates come from `wp_get_theme()->get_page_templates()` and usage from the `_wp_page_template` post meta WordPress already keeps. For a theme that is not active, templates come from its PHP files (`Template Name`, `Template Post Type`), `customTemplates` in its `theme.json` (and its parent's), and custom templates saved for it in the Site Editor.

The plugin stores three things:

- **History**: one row per template change in the `{prefix}axle_ptu_history` table (post, old and new template, user ID, source, batch, time, whether it was undone). Rows are written in groups, one query per 100 changes. The table keeps the latest 10,000 rows and is trimmed every 50 inserts, and a daily event deletes rows older than 365 days. Both limits have filters. The user ID is covered by the personal data exporter, the eraser (the rows stay, the user becomes 0) and the privacy policy text, and deleting a user clears their ID from the history.
- **Saved plans**: the option `axle_page_template_usage_theme_maps` (not autoloaded), one plan per theme. A plan is removed once it has been applied.
- **Last switch result**: the option `axle_page_template_usage_switch_result`, for the notice with the Undo. It goes when you dismiss the notice or undo the switch.

Uninstalling the plugin drops the table and removes both options.

Counts come from one grouped query per post type and stay in the object cache until a template or a post status changes, and an hour at most. Busy sites that save posts all day keep their cache.
