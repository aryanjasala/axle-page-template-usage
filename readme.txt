=== Axle Page Template Usage ===
Contributors: aryanjasala
Tags: page template, admin column, filter, block theme, templates
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
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
Works with:

* Classic themes (PHP page templates, including `Template Post Type` for posts and custom post types).
* Block themes (custom templates from `theme.json` and templates created in the Site Editor).
* Any post type that has templates.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= WP-CLI =

* `wp axle-page-template-usage list` (add `--status=missing` to see only problems)
* `wp axle-page-template-usage reassign missing default` moves every page on a missing template to the default one.

No settings and nothing stored in the database. Deactivate it and it is gone.

**Try it before you install.** [try.axlewp.com/axle-page-template-usage](https://try.axlewp.com/axle-page-template-usage) opens a private WordPress in your browser with this plugin and some demo content.

Part of [Axle](https://axlewp.com/) by AryanX, a family of free plugins for running WordPress sites safely.

== Frequently Asked Questions ==

= Why does a page show "Missing template"? =

The page was saved with a template that the active theme does not provide any more. This usually happens after a theme switch, or after a template file is renamed or deleted. WordPress shows the default template for that page. Pick a new template in the editor, or in Quick Edit, to clear it.

= Does it slow down the Pages screen? =

No. It runs one grouped count query when the screen loads and caches the result for a minute.

= Does it work on posts? =

Yes, if your theme offers templates for posts. The column only appears on post types that have templates, or that have posts pointing at a template.

== Screenshots ==

1. The Template column and filter on the Pages screen.
2. A page whose template no longer exists.
3. Appearance > Axle Page Template Usage.

== Changelog ==

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
