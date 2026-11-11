=== Axle Page Template Usage ===
Contributors: aryanjasala
Tags: page template, admin column, filter, block theme, templates
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which template every page uses, filter pages by template, and find pages whose template no longer exists.

== Description ==

WordPress lets you pick a template for each page, but the Pages screen never shows which one. To answer "which pages use the Full Width template?" you have to open every page.

Axle Page Template Usage adds a **Template** column to the Pages list and a **template filter** above it.

* The column shows the template name. Click it to see every page that uses the same template.
* The filter lists every template the theme offers, with the number of pages using each one. A count of 0 means the template is unused.
* Click the column heading to sort pages by template.
* **Missing templates** are shown in red. If you switch themes or delete a template file, pages that used it fall back to the default template without any warning. This plugin lists them so you can fix them.

**Appearance > Axle Page Template Usage** shows every template on one screen: how many pages use it, which templates are unused, and which pages point at templates that no longer exist. Move every page off a template in one click, for example from a missing template to the default one, or from an old template you want to delete.
**Check a theme before switching.** Pick an installed theme that is not active, and the plugin lists every page and post that would lose its template under it, grouped by template with counts. For each missing template, choose what to use instead (the default template is preselected) and save the plan. When you activate that theme, the pages move by themselves, in batches, and a notice shows the result with an **Undo** button. Appearance > Themes links to the check from each theme's details.

**Template history.** Every change of a page template is recorded: which page, the old and new template, who made the change, when, and where it came from (the editor, Quick Edit, the Move button, a theme switch, WP-CLI or an ability). The **History** tab filters by page, source, user, template and date, and a "Template history" link sits in the row actions on the Pages list. Undo a single change while the page still has the template that change set, or undo a whole batch (one Move click or one theme switch) after a confirm step.

Works with:

* Classic themes (PHP page templates, including `Template Post Type` for posts and custom post types).
* Block themes (custom templates from `theme.json` and templates created in the Site Editor).
* Any post type that has templates.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= WP-CLI =

* `wp axle-page-template-usage list` (add `--status=missing` to see only problems)
* `wp axle-page-template-usage reassign missing default` moves every page on a missing template to the default one.
* `wp axle-page-template-usage check-theme <theme>` lists what would lose its template under an installed theme.
* `wp axle-page-template-usage set-mapping <theme> <from> <to>` saves what to use instead, and `apply-mapping` runs the saved plan for the active theme.
* `wp axle-page-template-usage history` lists recorded changes, `undo <id>` and `undo-batch <batch>` undo them.

= Privacy =

The history stores the ID of the user who changed a template, with the page, the templates and the time. It keeps the latest 10,000 changes and deletes entries older than a year (both can be changed with filters). It is shown only to users who can edit pages. The plugin adds this to the privacy policy guide, to the personal data exporter, and to the eraser, which keeps each change but removes the link to the person. Uninstalling the plugin deletes the history table and saved plans.

Besides that, nothing is sent anywhere. Free and open source under the GPL, no tracking.

**Try it before you install.** [try.axlewp.com/axle-page-template-usage](https://try.axlewp.com/axle-page-template-usage) opens a private WordPress in your browser with this plugin and some demo content.

Part of [Axle](https://axlewp.com/) by AryanX, a family of free plugins for running WordPress sites safely.

== Frequently Asked Questions ==

= Why does a page show "Missing template"? =

The page was saved with a template that the active theme does not provide any more. This usually happens after a theme switch, or after a template file is renamed or deleted. WordPress shows the default template for that page. Pick a new template in the editor, or in Quick Edit, to clear it.

= Does checking a theme change anything? =

No. The check only reads. Saving a plan stores it, and it runs only when you activate that theme. Until then no page changes. If you never activate the theme, the plan does nothing, and you can remove it from the same screen.

= What happens on a theme switch with thousands of pages? =

The first 500 pages move during the switch (the number can be changed with the `axle_page_template_usage_switch_batch_size` filter). The rest follows in the background, a minute later, or at once with the "Move the rest now" button in the notice. Over WP-CLI, `wp axle-page-template-usage apply-mapping` moves them all.

= Why can a change not be undone? =

A change can be undone only while the page still has the template that change set. If the page was changed again since, or deleted, the History tab says so. Undoing is recorded in the history too, so you can undo an undo.

= How much does the history store? =

One small row per change, in one table. It keeps the latest 10,000 changes, drops the oldest first, and deletes entries older than a year. Both limits have filters. Changes are written in groups, so moving thousands of pages stays fast.

= Does it slow down the Pages screen? =

No. It runs one grouped count query when the screen loads and caches the result for a minute.

= Does it work on posts? =

Yes, if your theme offers templates for posts. The column only appears on post types that have templates, or that have posts pointing at a template.

== Screenshots ==

1. The Template column and filter on the Pages screen.
2. A page whose template no longer exists.
3. Appearance > Axle Page Template Usage.

== Changelog ==

= 3.1.0 =
* New: check a theme before switching and record template history

= 3.0.0 =
* Changed (breaking change): rename to Axle Page Template Usage for WordPress.org

= 2.0.0 =
* Changed (breaking change): rename to Axle Page Template Usage for WordPress.org

= 1.3.0 =
* Fix: make the abilities visible to MCP agents
* New: add abilities so AI agents can audit template usage and move items off missing templates

= 1.2.0 =
* New: add filters for the move limit and the missing-template notice, and an action before a move
* Fix: move templates in batches with a check per post, and keep the counts cached

= 1.1.0 =
* New: Appearance > Axle Page Template Usage report with one-click "move everything to" another template.
* New: sort the list by template.
* New: `wp axle-page-template-usage list` and `wp axle-page-template-usage reassign` commands.
* Rewritten as PSR-4 classes.

= 1.0.0 =
* First release.
