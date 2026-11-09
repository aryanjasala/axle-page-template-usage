<?php
/**
 * `wp axle-page-template-usage` commands.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Cli;

use AryanX\Axle\PageTemplateUsage\Templates;

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
	 * Sets up the command.
	 *
	 * @param Templates $templates Template data.
	 */
	public function __construct( Templates $templates ) {
		$this->templates = $templates;
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
		$result            = $this->templates->reassign( $assoc_args['post_type'] ?? 'page', $from, $to );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Moved %d item(s) to %s.', $result['moved'], $to ) );
	}
}
