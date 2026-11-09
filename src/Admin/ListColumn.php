<?php
/**
 * Template column, filter and sorting on post list screens.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Admin;

use AryanX\Axle\PageTemplateUsage\Service;
use AryanX\Axle\PageTemplateUsage\Templates;
use const AryanX\Axle\PageTemplateUsage\PLUGIN_FILE;
use const AryanX\Axle\PageTemplateUsage\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Template column to Pages (and any post type with templates).
 */
final class ListColumn implements Service {

	public const COLUMN    = 'axle_page_template_usage';
	public const QUERY_VAR = 'axle_page_template_usage';

	private const SORT_CLAUSE = 'axle_page_template_usage_sort';

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Sets up the column.
	 *
	 * @param Templates $templates Template data.
	 */
	public function __construct( Templates $templates ) {
		$this->templates = $templates;
	}

	/**
	 * Hooks in once the current screen is known.
	 */
	public function register(): void {
		add_action( 'current_screen', array( $this, 'setup_screen' ) );
		add_action( 'admin_init', array( $this, 'setup_quick_edit' ) );
	}

	/**
	 * Adds the column, filter and sorting to list screens of post types with templates.
	 *
	 * `current_screen` fires before the list table query on edit.php.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 */
	public function setup_screen( \WP_Screen $screen ): void {
		if ( 'edit' !== $screen->base || ! $this->templates->uses_templates( (string) $screen->post_type ) ) {
			return;
		}

		$post_type = $screen->post_type;

		add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
		add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'make_sortable' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_notices', array( $this, 'missing_notice' ) );
	}

	/**
	 * Warns above the list when items use a template the theme no longer has.
	 */
	public function missing_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || Templates::MISSING === $this->requested_template() ) {
			return;
		}

		$post_type = get_post_type_object( (string) $screen->post_type );
		$missing   = array_sum( $this->templates->get_missing_counts( (string) $screen->post_type ) );

		/**
		 * Filters whether the list screen warns about items on missing templates.
		 *
		 * @param bool   $show      Default true when any item is on a missing template.
		 * @param string $post_type Post type of the list.
		 * @param int    $missing   Items on missing templates.
		 */
		if ( ! $post_type || ! $missing || ! apply_filters( 'axle_page_template_usage_show_missing_notice', true, $post_type->name, $missing ) ) {
			return;
		}

		if ( 'page' === $post_type->name ) {
			/* translators: %s: number of pages. */
			$message = _n( '%s page uses a template the theme no longer has, so it shows the default template.', '%s pages use a template the theme no longer has, so they show the default template.', $missing, 'axle-page-template-usage' );
		} elseif ( 'post' === $post_type->name ) {
			/* translators: %s: number of posts. */
			$message = _n( '%s post uses a template the theme no longer has, so it shows the default template.', '%s posts use a template the theme no longer has, so they show the default template.', $missing, 'axle-page-template-usage' );
		} else {
			/* translators: %s: number of items. */
			$message = _n( '%s item uses a template the theme no longer has, so it shows the default template.', '%s items use a template the theme no longer has, so they show the default template.', $missing, 'axle-page-template-usage' );
		}

		printf(
			'<div class="notice notice-warning aptu-missing-notice"><p>%s <a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
			esc_html( sprintf( $message, number_format_i18n( $missing ) ) ),
			esc_url( $this->filter_url( $post_type->name, Templates::MISSING ) ),
			esc_html__( 'Show them', 'axle-page-template-usage' ),
			esc_url( admin_url( 'themes.php?page=' . ReportPage::SLUG ) ),
			esc_html__( 'Fix in Axle Page Template Usage', 'axle-page-template-usage' )
		);
	}

	/**
	 * Adds the column to the row that Quick Edit re-renders after saving.
	 *
	 * The inline-save ajax request builds the list table without firing
	 * `current_screen`, so the screen is taken from the request instead.
	 */
	public function setup_quick_edit(): void {
		// Core verifies the inline-save nonce. This only decides whether to render a column.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! wp_doing_ajax() || ! isset( $_POST['action'], $_POST['screen'] ) || 'inline-save' !== $_POST['action'] ) {
			return;
		}

		$this->setup_screen( \WP_Screen::get( sanitize_key( wp_unslash( $_POST['screen'] ) ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Adds the Template column after the title.
	 *
	 * @param array<string, string> $columns List table columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$label   = __( 'Template', 'axle-page-template-usage' );
		$updated = array();

		foreach ( $columns as $key => $title ) {
			$updated[ $key ] = $title;
			if ( 'title' === $key ) {
				$updated[ self::COLUMN ] = $label;
			}
		}

		$updated[ self::COLUMN ] = $updated[ self::COLUMN ] ?? $label;

		return $updated;
	}

	/**
	 * Makes the column sortable.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public function make_sortable( array $columns ): array {
		$screen = get_current_screen();

		/**
		 * Filters whether the Template column can sort the list.
		 *
		 * Sorting joins postmeta, which is slow on very large sites. Return
		 * false there to keep the column and the filter without sorting.
		 *
		 * @param bool   $sortable  Default true.
		 * @param string $post_type Post type of the list.
		 */
		if ( apply_filters( 'axle_page_template_usage_sortable', true, $screen ? (string) $screen->post_type : '' ) ) {
			$columns[ self::COLUMN ] = self::COLUMN;
		}

		return $columns;
	}

	/**
	 * Prints the template name, linked to the list filtered by that template.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$post_type = (string) get_post_type( $post_id );
		$slug      = (string) get_page_template_slug( $post_id );
		$name      = $this->templates->get_name( $post_type, $slug );

		if ( $this->templates->is_default( $slug ) ) {
			printf(
				'<a href="%s" class="aptu-default">%s</a>',
				esc_url( $this->filter_url( $post_type, Templates::DEFAULT_TEMPLATE ) ),
				esc_html( (string) $name )
			);
			return;
		}

		if ( null !== $name ) {
			printf( '<a href="%s">%s</a>', esc_url( $this->filter_url( $post_type, $slug ) ), esc_html( $name ) );
			return;
		}

		printf(
			'<a href="%1$s" class="aptu-missing" title="%2$s"><span class="dashicons dashicons-warning" aria-hidden="true"></span>%3$s</a><code class="aptu-slug">%4$s</code>',
			esc_url( $this->filter_url( $post_type, Templates::MISSING ) ),
			esc_attr__( 'The active theme no longer has this template, so WordPress shows the default template instead.', 'axle-page-template-usage' ),
			esc_html__( 'Missing template', 'axle-page-template-usage' ),
			esc_html( $slug )
		);
	}

	/**
	 * Prints the template dropdown above the list, with post counts.
	 *
	 * Templates with a count of 0 are unused, which is the quickest way to spot
	 * templates that are safe to remove from a theme.
	 *
	 * @param string $post_type Post type of the current list.
	 */
	public function render_filter( string $post_type ): void {
		$current = $this->requested_template();
		$options = array( '' => __( 'All templates', 'axle-page-template-usage' ) );

		foreach ( $this->templates->get_report( $post_type ) as $row ) {
			if ( 'missing' === $row['status'] ) {
				continue;
			}
			$options[ $row['slug'] ] = sprintf( '%s (%d)', $row['name'], $row['count'] );
		}

		$missing = array_sum( $this->templates->get_missing_counts( $post_type ) );
		if ( $missing ) {
			$options[ Templates::MISSING ] = sprintf(
				/* translators: %d: number of posts. */
				__( 'Missing template (%d)', 'axle-page-template-usage' ),
				$missing
			);
		}

		printf(
			'<label for="filter-by-template" class="screen-reader-text">%s</label><select name="%s" id="filter-by-template">',
			esc_html__( 'Filter by template', 'axle-page-template-usage' ),
			esc_attr( self::QUERY_VAR )
		);

		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $label ) );
		}

		echo '</select>';
	}

	/**
	 * Narrows the list to the requested template, and sorts by template.
	 *
	 * @param \WP_Query $query Query being prepared.
	 */
	public function filter_query( \WP_Query $query ): void {
		if ( ! $query->is_main_query() ) {
			return;
		}

		$post_type = (string) get_current_screen()->post_type;
		$clauses   = array();
		$requested = $this->requested_template();

		if ( '' !== $requested ) {
			$clauses[] = $this->templates->meta_query_for( $post_type, $requested );
		}

		if ( self::COLUMN === $query->get( 'orderby' ) ) {
			// Keep posts without the meta in the list while sorting by it.
			$clauses[] = array(
				'relation'        => 'OR',
				self::SORT_CLAUSE => array(
					'key'     => Templates::META_KEY,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => Templates::META_KEY,
					'compare' => 'NOT EXISTS',
				),
			);
			$query->set( 'orderby', array( self::SORT_CLAUSE => $query->get( 'order' ) ? $query->get( 'order' ) : 'ASC' ) );
		}

		if ( ! $clauses ) {
			return;
		}

		$existing = $query->get( 'meta_query' );
		if ( $existing ) {
			$clauses[] = $existing;
		}

		$query->set( 'meta_query', array_merge( array( 'relation' => 'AND' ), $clauses ) );
	}

	/**
	 * Column styles.
	 */
	public function enqueue_styles(): void {
		wp_enqueue_style( 'axle-page-template-usage', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * URL of the list screen filtered by a template.
	 *
	 * @param string $post_type Post type name.
	 * @param string $template  Template value to filter by.
	 * @return string
	 */
	public function filter_url( string $post_type, string $template ): string {
		return add_query_arg(
			array(
				'post_type'     => $post_type,
				self::QUERY_VAR => $template,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Template filter requested in the URL, or an empty string.
	 *
	 * @return string
	 */
	private function requested_template(): string {
		// Read-only list filter, like core's own list table filters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
	}
}
