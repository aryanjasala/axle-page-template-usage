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
wp axle-page-template-usage missing [--post_type=<type>] [--format=<format>]
```

Looks at the pages now and lists every template that items use but the active theme lacks: post type, template, item count, since when, and the suggested replacement with the reason (`same_file`, `same_name` or `default`). Changes nothing. Exits with code 1 when any template is missing and 0 when none is, so a deploy pipeline can fail on it.

```sh
wp axle-page-template-usage missing-alert <on|off> [--to=<email>]
```

Turns the email about missing templates on or off, and sets who gets it. The email is off by default and goes to the site admin address unless you give one.

```sh
wp --user=<user> axle-page-template-usage preview-url <post_id> <template>
```

Prints a front end link that shows the page with that template. The link works for the user given with `--user`, for one day, and saves nothing. The command fails when that user may not edit the page or the theme lacks the template.

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
| `axle_page_template_usage_scan_schedule` | filter | `( string $schedule )`. How often the theme's template list is compared with the last scan. Default `daily` |
| `axle_page_template_usage_scanned` | action | `( array $state, array $new, string $source )`. Fires after a scan. `$new` holds the templates that went missing since the last one |
| `axle_page_template_usage_missing_detected` | action | `( array $new, array $state )`. Fires when pages point at a template the theme lost since the last scan. Not fired by the first scan, which only sets the baseline |
| `axle_page_template_usage_alert_capability` | filter | `( string $capability )`. Who may turn the email on and choose who gets it. Default `manage_options` |
| `axle_page_template_usage_alert_recipients` | filter | `( string[] $recipients, array $new )`. Who gets the missing template email |
| `axle_page_template_usage_alert_email` | filter | `( array $mail, array $new )`. Subject and plain text body of the email |
| `axle_page_template_usage_alert_sent` | action | `( string[] $recipients, array $new )`. Fires after the email was sent |
| `axle_page_template_usage_missing_suggestion` | filter | `( array $suggestion, string $post_type, string $slug, array $available )`. The template suggested for pages on a lost template, `template` and `reason`. A template the theme does not offer falls back to the default |
| `axle_page_template_usage_suggestion_reasons` | filter | `( array $labels )`. The labels that say why a template is suggested |
| `axle_page_template_usage_missing_notice_screens` | filter | `( string[] $screen_ids )`. Admin screens that show the missing template notice. Default the Dashboard, Themes and Plugins |
| `axle_page_template_usage_missing_notice_message` | filter | `( string $message, array $rows, int $total )`. The sentence of that notice |
| `axle_page_template_usage_move_preview_size` | filter | `( int $size )`. Example pages the Missing tab lists before a move. Default 10 |
| `axle_page_template_usage_health_result` | filter | `( array $result, array $rows )`. The Site Health result for missing templates |
| `axle_page_template_usage_can_preview` | filter | `( bool $allowed, WP_Post $post )`. Last word on whether the user may preview a page. The default needs access to the post type and the right to edit the page |
| `axle_page_template_usage_preview_lifetime` | filter | `( int $seconds )`. How long a preview link works. Default one day |
| `axle_page_template_usage_preview_message` | filter | `( string $message, int $post_id, string $template )`. The sentence on the preview bar |
| `axle_page_template_usage_preview_started` | action | `( int $post_id, string $template )`. Fires when a page is shown with another template as a preview |
| `axle_page_template_usage_preview_applied` | action | `( int $post_id, string $template, string $previous )`. Fires after "Use this template" saved it |
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
| `axle-page-template-usage/get-missing` | Read-only. Templates that items point at but the theme lacks, with the item count, since when, and the suggested replacement. Only covers post types the user can edit | the Axle Page Template Usage capability, and `edit_posts` on `post_type` when one is given |
| `axle-page-template-usage/get-preview-url` | Read-only. A front end link that shows `post_id` with `template`, for the current user only, saving nothing | the right to edit that page, and access to its post type |
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
$plugin->missing()->get_missing( array( 'page' ) );    // templates pages use but the theme lacks, with a suggestion each
$plugin->missing()->suggest( 'page', 'templates/old.php' ); // [ 'template' => ..., 'reason' => 'same_file' | 'same_name' | 'default' ]
$plugin->preview()->url( 42, 'page-wide' );            // front end link for the current user, or '' when not allowed
```

`Templates::MISSING` and `Templates::DEFAULT_TEMPLATE` are the special values for `reassign()` and `meta_query_for()`.

## Data

Templates come from `wp_get_theme()->get_page_templates()` and usage from the `_wp_page_template` post meta WordPress already keeps. For a theme that is not active, templates come from its PHP files (`Template Name`, `Template Post Type`), `customTemplates` in its `theme.json` (and its parent's), and custom templates saved for it in the Site Editor.

The plugin stores these:

- **History**: one row per template change in the `{prefix}axle_ptu_history` table (post, old and new template, user ID, source, batch, time, whether it was undone). Rows are written in groups, one query per 100 changes. The table keeps the latest 10,000 rows and is trimmed every 50 inserts, and a daily event deletes rows older than 365 days. Both limits have filters. The user ID is covered by the personal data exporter, the eraser (the rows stay, the user becomes 0) and the privacy policy text, and deleting a user clears their ID from the history.
- **Saved plans**: the option `axle_page_template_usage_theme_maps` (not autoloaded), one plan per theme. A plan is removed once it has been applied.
- **Last switch result**: the option `axle_page_template_usage_switch_result`, for the notice with the Undo. It goes when you dismiss the notice or undo the switch.
- **Missing template record**: the option `axle_page_template_usage_missing_state` (not autoloaded). A hash of the theme's template list, the names of the templates it offered, and which templates are missing since when. A scan compares the hash first, so a day with no change costs one option read.
- **Email settings**: the option `axle_page_template_usage_missing_alert`, off by default, with the address.
- **Dismissed notice**: the user meta `axle_page_template_usage_missing_dismissed`, a short code of the missing set that user dismissed. The notice comes back when something new goes missing.

The scan runs after a theme update (a minute later, in its own request), after a theme switch, and once a day from WP-Cron (`axle_page_template_usage_scan`). The Check now button runs it at once. WordPress keeps a lost template in the page's meta and shows the default layout, so nothing is changed by a scan.

A preview link is the page's own address plus two arguments: the template, and a token signed for the user, the page, the template and an expiry time. For that one request the page's stored template reads as the previewed one, which works for classic template files and block templates alike. The response is `noindex` and not cached.

Uninstalling the plugin drops the table, removes the options and the user meta, and clears the scheduled events.

Counts come from one grouped query per post type and stay in the object cache until a template or a post status changes, and an hour at most. Busy sites that save posts all day keep their cache.
