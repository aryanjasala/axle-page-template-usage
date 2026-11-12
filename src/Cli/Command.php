<?php
/**
 * `wp axle-page-template-usage` commands.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Cli;

use AryanX\Axle\PageTemplateUsage\History;
use AryanX\Axle\PageTemplateUsage\Missing;
use AryanX\Axle\PageTemplateUsage\Preview;
use AryanX\Axle\PageTemplateUsage\Templates;
use AryanX\Axle\PageTemplateUsage\ThemeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Reports template usage and moves posts between templates.
 */
final class Command {

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Theme check.
	 *
	 * @var ThemeCheck
	 */
	private ThemeCheck $theme_check;

	/**
	 * Template history.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Finder of missing templates.
	 *
	 * @var Missing
	 */
	private Missing $missing;

	/**
	 * Front end preview.
	 *
	 * @var Preview
	 */
	private Preview $preview;

	/**
	 * Sets up the command.
	 *
	 * @param Templates    $templates   Template data.
	 * @param ThemeCheck   $theme_check Theme check.
	 * @param History      $history     Template history.
	 * @param Missing|null $missing     Finder of missing templates.
	 * @param Preview|null $preview     Front end preview.
	 */
	public function __construct( Templates $templates, ThemeCheck $theme_check, History $history, ?Missing $missing = null, ?Preview $preview = null ) {
		$this->templates   = $templates;
		$this->theme_check = $theme_check;
		$this->history     = $history;
		$this->missing     = $missing ?? new Missing( $templates );
		$this->preview     = $preview ?? new Preview( $templates );
	}

	/**
	 * Lists every template with the number of items that use it.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post_type>]
	 * : Only this post type. Default: every post type with templates.
	 *
	 * [--status=<status>]
	 * : Only rows with this status: default, used, unused or missing.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-page-template-usage list
	 *     wp axle-page-template-usage list --status=missing --format=csv
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$post_types = isset( $assoc_args['post_type'] ) ? array( $assoc_args['post_type'] ) : wp_list_pluck( $this->templates->get_post_types(), 'name' );
		$rows       = array();

		foreach ( $post_types as $post_type ) {
			foreach ( $this->templates->get_report( $post_type ) as $row ) {
				if ( isset( $assoc_args['status'] ) && $assoc_args['status'] !== $row['status'] ) {
					continue;
				}
				$rows[] = array( 'post_type' => $post_type ) + $row;
			}
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'post_type', 'slug', 'name', 'count', 'status' ) );
	}

	/**
	 * Moves every item from one template to another.
	 *
	 * ## OPTIONS
	 *
	 * <from>
	 * : Template to move away from: a file or slug, "default", or "missing" for every template the theme no longer has.
	 *
	 * <to>
	 * : Template to move to: a file or slug the theme offers, or "default".
	 *
	 * [--post_type=<post_type>]
	 * : Post type.
	 * ---
	 * default: page
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-page-template-usage reassign missing default
	 *     wp axle-page-template-usage reassign templates/full-width.php page-wide
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function reassign( array $args, array $assoc_args ): void {
		list( $from, $to ) = $args;
		$from              = 'missing' === $from ? Templates::MISSING : $from;
		$result            = $this->templates->reassign( $assoc_args['post_type'] ?? 'page', $from, $to, 0, false, 'cli' );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Moved %d item(s) to %s.%s', $result['moved'], $to, $result['batch'] ? ' Batch: ' . $result['batch'] : '' ) );
	}

	/**
	 * Lists templates that items use but the active theme no longer has.
	 *
	 * Looks at the pages now and changes nothing. Exits with code 1 when any
	 * template is missing, so a deploy pipeline can fail on it. For each one it
	 * shows the closest template the theme still has.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post_type>]
	 * : Only this post type. Default: every post type with templates.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-page-template-usage missing
	 *     wp axle-page-template-usage missing --format=json || echo "templates went missing"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function missing( array $args, array $assoc_args ): void {
		$this->missing->scan( true, 'cli' );
		$rows = array();

		foreach ( $this->missing->get_missing( isset( $assoc_args['post_type'] ) ? array( (string) $assoc_args['post_type'] ) : null ) as $row ) {
			$rows[] = array(
				'post_type'  => $row['post_type'],
				'slug'       => $row['slug'],
				'count'      => $row['count'],
				'since'      => $row['since'] ? gmdate( 'Y-m-d H:i:s', $row['since'] ) : '',
				'suggestion' => $row['suggestion']['template'],
				'reason'     => $row['suggestion']['reason'],
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'post_type', 'slug', 'count', 'since', 'suggestion', 'reason' ) );

		if ( $rows ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Turns the email about missing templates on or off.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on or off.
	 *
	 * [--to=<email>]
	 * : Who gets it. Default: the site admin email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-page-template-usage missing-alert on --to=ops@example.com
	 *     wp axle-page-template-usage missing-alert off
	 *
	 * @subcommand missing-alert
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function missing_alert( array $args, array $assoc_args ): void {
		if ( ! in_array( $args[0], array( 'on', 'off' ), true ) ) {
			\WP_CLI::error( 'Use "on" or "off".' );
		}

		$result = $this->missing->save_alert( 'on' === $args[0], isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : $this->missing->get_alert()['to'] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( 'on' === $args[0] ? 'You get an email when a template goes missing.' : 'No email is sent.' );
	}

	/**
	 * Prints a front end link that shows a page with another template.
	 *
	 * The link is made for the user given with --user, and works for that user only. Nothing is saved.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The page.
	 *
	 * <template>
	 * : A template the theme offers, or "default".
	 *
	 * ## EXAMPLES
	 *
	 *     wp --user=admin axle-page-template-usage preview-url 12 templates/full-width.php
	 *
	 * @subcommand preview-url
	 *
	 * @param array $args Positional arguments.
	 */
	public function preview_url( array $args ): void {
		$url = $this->preview->url( absint( $args[0] ), (string) $args[1] );

		if ( '' === $url ) {
			\WP_CLI::error( 'That page cannot be previewed with that template. Run with --user=<admin>, and check the page and the template.' );
		}

		\WP_CLI::line( $url );
	}

	/**
	 * Lists what would lose its template if a theme became active.
	 *
	 * Looks at every item that uses a template, grouped by template, and
	 * leaves out the ones the theme has. Changes nothing.
	 *
	 * ## OPTIONS
	 *
	 * <theme>
	 * : Folder name of an installed theme that is not active.
	 *
	 * [--post_type=<post_type>]
	 * : Only this post type. Default: every post type with templates.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-page-template-usage check-theme twentytwentyfour
	 *     wp axle-page-template-usage check-theme twentytwentyfour --format=json
	 *
	 * @subcommand check-theme
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function check_theme( array $args, array $assoc_args ): void {
		$result = $this->theme_check->check( $args[0], $assoc_args['post_type'] ?? null );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		$mapping = $this->theme_check->get_mapping( $args[0] );
		$rows    = array();
		foreach ( $result['groups'] as $post_type => $group ) {
			foreach ( $group as $row ) {
				$rows[] = array(
					'post_type'       => $post_type,
					'slug'            => $row['slug'],
					'name'            => $row['name'],
					'count'           => $row['count'],
					'already_missing' => $row['already_missing'] ? 'yes' : 'no',
					'planned'         => $mapping[ $post_type ][ $row['slug'] ] ?? '',
				);
			}
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'post_type', 'slug', 'name', 'count', 'already_missing', 'planned' ) );
	}

	/**
	 * Saves what to use instead of a template when a theme is activated.
	 *
	 * ## OPTIONS
	 *
	 * <theme>
	 * : Folder name of an installed theme that is not active.
	 *
	 * <from>
	 * : Template to replace: a file or slug, or "missing" for every template the theme does not have.
	 *
	 * <to>
	 * : Template of the theme to use, or "default".
	 *
	 * [--post_type=<post_type>]
	 * : Post type.
	 * ---
	 * default: page
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-page-template-usage set-mapping twentytwentyfour missing default
	 *     wp axle-page-template-usage set-mapping twentytwentyfour templates/wide.php page-wide
	 *
	 * @subcommand set-mapping
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function set_mapping( array $args, array $assoc_args ): void {
		list( $theme, $from, $to )      = $args;
		$post_type                      = $assoc_args['post_type'] ?? 'page';
		$mapping                        = $this->theme_check->get_mapping( $theme );
		$mapping[ $post_type ][ $from ] = $to;

		$result = $this->theme_check->save_mapping( $theme, $mapping );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		if ( ! isset( $result[ $post_type ][ $from ] ) ) {
			\WP_CLI::error( sprintf( 'No %s uses "%s", or the theme %s has it already, so there is nothing to plan.', $post_type, $from, $theme ) );
		}

		\WP_CLI::success( sprintf( 'Saved. When %s is activated, %s items on %s move to %s.', $theme, $post_type, $from, $to ) );
	}

	/**
	 * Moves items per the plan saved for the active theme, with no limit.
	 *
	 * Runs by itself when the theme is activated from wp-admin. Use this after
	 * `wp theme activate`, or to run the plan again.
	 *
	 * ## EXAMPLES
	 *
	 *     wp theme activate twentytwentyfour
	 *     wp axle-page-template-usage apply-mapping
	 *
	 * @subcommand apply-mapping
	 */
	public function apply_mapping(): void {
		$state = $this->theme_check->start( 0 );
		if ( null === $state ) {
			\WP_CLI::error( 'No plan is saved for the active theme.' );
		}

		\WP_CLI::success( sprintf( 'Moved %d item(s). Batch: %s', $state['moved'], $state['moved'] ? $state['batch'] : '-' ) );
	}

	/**
	 * Lists recorded template changes, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : Only this post.
	 *
	 * [--source=<source>]
	 * : editor, quick_edit, bulk_move, theme_switch, cli, ability, preview, undo or other.
	 *
	 * [--user=<id>]
	 * : Only changes by this user ID. 0 for WP-CLI and other system changes.
	 *
	 * [--batch=<batch>]
	 * : Only this batch.
	 *
	 * [--limit=<number>]
	 * : Most entries to list.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-page-template-usage history --post=42
	 *     wp axle-page-template-usage history --source=theme_switch --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function history( array $args, array $assoc_args ): void {
		$query = array(
			'per_page' => max( 1, (int) ( $assoc_args['limit'] ?? 50 ) ),
			'source'   => $assoc_args['source'] ?? '',
			'batch'    => $assoc_args['batch'] ?? '',
		);
		if ( isset( $assoc_args['post'] ) ) {
			$query['post_id'] = (int) $assoc_args['post'];
		}
		if ( isset( $assoc_args['user'] ) ) {
			$query['user_id'] = (int) $assoc_args['user'];
		}

		$rows = array();
		foreach ( $this->history->query( $query )['items'] as $entry ) {
			$rows[] = array(
				'id'       => (int) $entry->id,
				'when'     => $entry->created,
				'post_id'  => (int) $entry->post_id,
				'from'     => '' === $entry->old_template ? 'default' : $entry->old_template,
				'to'       => '' === $entry->new_template ? 'default' : $entry->new_template,
				'user_id'  => (int) $entry->user_id,
				'source'   => $entry->source,
				'batch'    => $entry->batch,
				'undoable' => $this->history->blocker( $entry, false ) ? 'no' : 'yes',
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'when', 'post_id', 'from', 'to', 'user_id', 'source', 'batch', 'undoable' ) );
	}

	/**
	 * Undoes one recorded change, if the page still has the template it set.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : History entry ID, from the history command.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-page-template-usage undo 17
	 *
	 * @param array $args Positional arguments.
	 */
	public function undo( array $args ): void {
		$result = $this->history->undo_entry( (int) $args[0], false );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( $result ) {
			\WP_CLI::error( $this->history->blocker_message( $result, (object) array( 'new_template' => $this->history->get( (int) $args[0] )->new_template ?? '' ) ) );
		}

		\WP_CLI::success( 'Undone.' );
	}

	/**
	 * Undoes a whole batch: one move or one theme switch.
	 *
	 * Pages that changed since, or are gone, are skipped.
	 *
	 * ## OPTIONS
	 *
	 * <batch>
	 * : Batch ID, from the history command or the output of reassign.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-page-template-usage undo-batch 3f2a9c0e1b7d4e5f8a6b2c1d0e9f8a7b
	 *
	 * @subcommand undo-batch
	 *
	 * @param array $args Positional arguments.
	 */
	public function undo_batch( array $args ): void {
		$result = $this->history->undo_batch( $args[0], 0, false );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Undone %d change(s), skipped %d.', $result['undone'], $result['skipped'] ) );
	}
}
