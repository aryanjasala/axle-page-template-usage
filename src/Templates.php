<?php
/**
 * Template choices and usage counts.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads which templates the theme offers and which posts use them, and moves
 * posts from one template to another.
 */
final class Templates implements Service {

	public const META_KEY         = '_wp_page_template';
	public const DEFAULT_TEMPLATE = 'default';
	public const MISSING          = '__missing';

	private const CACHE_GROUP = 'axle-page-template-usage';

	/**
	 * Posts moved per query in reassign().
	 */
	private const BATCH = 100;

	/**
	 * Keeps the cached usage counts fresh.
	 */
	public function register(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_meta_change' ), 10, 3 );
		}
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
	}

	/**
	 * Templates the active theme offers for a post type, keyed by file or slug.
	 *
	 * Covers PHP templates in classic themes and custom templates in block
	 * themes, including ones created in the Site Editor.
	 *
	 * @param string $post_type Post type name.
	 * @return array<string, string> Template file or slug => display name.
	 */
	public function get_templates( string $post_type ): array {
		$templates = wp_get_theme()->get_page_templates( null, $post_type );
		asort( $templates, SORT_NATURAL | SORT_FLAG_CASE );

		return $templates;
	}

	/**
	 * Whether a stored template value means "use the default template".
	 *
	 * @param string $slug Stored template value.
	 * @return bool
	 */
	public function is_default( string $slug ): bool {
		return '' === $slug || self::DEFAULT_TEMPLATE === $slug;
	}

	/**
	 * Display name of a stored template value.
	 *
	 * @param string $post_type Post type name.
	 * @param string $slug      Stored template value.
	 * @return string|null Name, or null when the theme does not have the template.
	 */
	public function get_name( string $post_type, string $slug ): ?string {
		if ( $this->is_default( $slug ) ) {
			return __( 'Default', 'axle-page-template-usage' );
		}

		$templates = $this->get_templates( $post_type );

		return $templates[ $slug ] ?? null;
	}

	/**
	 * Number of posts per stored template value, excluding trashed posts.
	 *
	 * Cached against the `posts` last-changed key, which core bumps whenever
	 * a post or its meta changes, so counts are never stale.
	 *
	 * @param string $post_type Post type name.
	 * @return array<string, int> Stored template value => post count.
	 */
	public function get_usage_counts( string $post_type ): array {
		global $wpdb;

		// Kept until a template changes (see on_meta_change), and an hour at most.
		$cache_key = 'counts:' . $post_type;
		$counts    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $counts ) ) {
			return $counts;
		}

		// A grouped count across postmeta has no WP_Query equivalent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS template, COUNT(*) AS total
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
				GROUP BY pm.meta_value",
				self::META_KEY,
				$post_type
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row->template ] = (int) $row->total;
		}

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $counts;
	}

	/**
	 * Number of posts that use the default template, with or without the meta set.
	 *
	 * @param string $post_type Post type name.
	 * @return int
	 */
	public function get_default_count( string $post_type ): int {
		$all = 0;
		foreach ( (array) wp_count_posts( $post_type ) as $status => $total ) {
			if ( ! in_array( $status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
				$all += (int) $total;
			}
		}

		$custom = 0;
		foreach ( $this->get_usage_counts( $post_type ) as $slug => $total ) {
			if ( ! $this->is_default( (string) $slug ) ) {
				$custom += $total;
			}
		}

		return max( 0, $all - $custom );
	}

	/**
	 * Stored template values the theme does not have any more, with post counts.
	 *
	 * @param string $post_type Post type name.
	 * @return array<string, int>
	 */
	public function get_missing_counts( string $post_type ): array {
		$templates = $this->get_templates( $post_type );
		$missing   = array();

		foreach ( $this->get_usage_counts( $post_type ) as $slug => $total ) {
			$slug = (string) $slug;
			if ( ! $this->is_default( $slug ) && ! isset( $templates[ $slug ] ) ) {
				$missing[ $slug ] = $total;
			}
		}

		return $missing;
	}

	/**
	 * Whether a post type has templates to choose from, or posts still pointing at one.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public function uses_templates( string $post_type ): bool {
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return false;
		}

		return $this->get_templates( $post_type ) || $this->get_missing_counts( $post_type );
	}

	/**
	 * Post types with an admin screen that use templates.
	 *
	 * @return \WP_Post_Type[]
	 */
	public function get_post_types(): array {
		$candidates = array_filter(
			get_post_types( array( 'show_ui' => true ), 'names' ),
			static function ( string $name ): bool {
				return 'attachment' !== $name && 0 !== strpos( $name, 'wp_' );
			}
		);

		/**
		 * Filters the post types Axle Page Template Usage looks at.
		 *
		 * Post types without templates are left out on their own. Use this to
		 * skip a huge post type, or to add one that is hidden from wp-admin.
		 *
		 * @param string[] $post_types Post type names.
		 */
		$candidates = (array) apply_filters( 'axle_page_template_usage_post_types', array_values( $candidates ) );

		return array_values(
			array_filter(
				array_map( 'get_post_type_object', array_map( 'strval', $candidates ) ),
				function ( $post_type ) {
					return $post_type instanceof \WP_Post_Type && $this->uses_templates( $post_type->name );
				}
			)
		);
	}

	/**
	 * One row per template for the report: offered templates, then missing ones.
	 *
	 * @param string $post_type Post type name.
	 * @return array<int, array{slug: string, name: string, count: int, status: string}>
	 */
	public function get_report( string $post_type ): array {
		$counts = $this->get_usage_counts( $post_type );
		$rows   = array(
			array(
				'slug'   => self::DEFAULT_TEMPLATE,
				'name'   => __( 'Default', 'axle-page-template-usage' ),
				'count'  => $this->get_default_count( $post_type ),
				'status' => 'default',
			),
		);

		foreach ( $this->get_templates( $post_type ) as $slug => $name ) {
			$count  = $counts[ $slug ] ?? 0;
			$rows[] = array(
				'slug'   => (string) $slug,
				'name'   => $name,
				'count'  => $count,
				'status' => $count ? 'used' : 'unused',
			);
		}

		foreach ( $this->get_missing_counts( $post_type ) as $slug => $count ) {
			$rows[] = array(
				'slug'   => $slug,
				'name'   => $slug,
				'count'  => $count,
				'status' => 'missing',
			);
		}

		return $rows;
	}

	/**
	 * Meta query that matches posts using a template.
	 *
	 * @param string $post_type Post type name.
	 * @param string $template  Template value, DEFAULT_TEMPLATE, or MISSING for every missing template.
	 * @return array
	 */
	public function meta_query_for( string $post_type, string $template ): array {
		if ( self::DEFAULT_TEMPLATE === $template ) {
			return array(
				'relation' => 'OR',
				array(
					'key'     => self::META_KEY,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_KEY,
					'value'   => array( '', self::DEFAULT_TEMPLATE ),
					'compare' => 'IN',
				),
			);
		}

		if ( self::MISSING === $template ) {
			return array(
				array(
					'key'     => self::META_KEY,
					'value'   => array_merge( array_keys( $this->get_templates( $post_type ) ), array( '', self::DEFAULT_TEMPLATE ) ),
					'compare' => 'NOT IN',
				),
			);
		}

		return array(
			array(
				'key'   => self::META_KEY,
				'value' => $template,
			),
		);
	}

	/**
	 * Moves posts from one template to another, a batch at a time.
	 *
	 * Moves at most $limit posts, so a click in wp-admin never times out on a
	 * site with thousands of pages. Posts the current user may not edit are
	 * skipped when $check_user is on. WP-CLI passes no limit and no check.
	 *
	 * @param string $post_type  Post type.
	 * @param string $from       Template slug, DEFAULT_TEMPLATE or MISSING.
	 * @param string $to         Template slug or DEFAULT_TEMPLATE.
	 * @param int    $limit      Most posts to move, 0 for all.
	 * @param bool   $check_user Skip posts the current user may not edit.
	 * @return array{moved: int, skipped: int, remaining: int}|\WP_Error
	 */
	public function reassign( string $post_type, string $from, string $to, int $limit = 0, bool $check_user = false ) {
		if ( ! $this->is_default( $to ) && ! isset( $this->get_templates( $post_type )[ $to ] ) ) {
			return new \WP_Error( 'axle_page_template_usage_unknown_template', __( 'The theme does not have that template.', 'axle-page-template-usage' ) );
		}

		$value   = $this->is_default( $to ) ? self::DEFAULT_TEMPLATE : $to;
		$moved   = 0;
		$skipped = array();

		/**
		 * Fires before items move from one template to another.
		 *
		 * @param string $post_type Post type.
		 * @param string $from      Template they are on, DEFAULT_TEMPLATE or MISSING.
		 * @param string $to        Template they move to.
		 * @param int    $limit     Most items this run moves, 0 for all.
		 */
		do_action( 'axle_page_template_usage_before_reassign', $post_type, $from, $value, $limit );

		do {
			$batch = $limit ? min( self::BATCH, $limit - $moved ) : self::BATCH;
			// Moved posts no longer match, so every batch is the first page of what is left.
			$ids = $this->matching_ids( $post_type, $from, $batch, $skipped );

			$done = array();
			foreach ( $ids as $post_id ) {
				if ( $check_user && ! current_user_can( 'edit_post', $post_id ) ) {
					$skipped[] = $post_id;
					continue;
				}
				update_post_meta( $post_id, self::META_KEY, $value );
				$done[] = $post_id;
			}
			$moved += count( $done );

			if ( $done ) {
				/**
				 * Fires after a batch of posts moved to another template.
				 *
				 * @param string $post_type Post type.
				 * @param string $from      Template they were on.
				 * @param string $to        Template they are on now.
				 * @param int[]  $post_ids  Posts in this batch.
				 */
				do_action( 'axle_page_template_usage_reassigned', $post_type, $from, $value, $done );
			}

			$count = count( $ids );
		} while ( $count && ( ! $limit || $moved < $limit ) );

		$this->flush_counts( $post_type );

		return array(
			'moved'     => $moved,
			'skipped'   => count( $skipped ),
			'remaining' => max( 0, $this->count_matching( $post_type, $from ) - count( $skipped ) ),
		);
	}

	/**
	 * IDs of posts on a template.
	 *
	 * @param string $post_type Post type.
	 * @param string $template  Template slug, DEFAULT_TEMPLATE or MISSING.
	 * @param int    $limit     Most IDs to return.
	 * @param int[]  $exclude   IDs to leave out.
	 * @return int[]
	 */
	private function matching_ids( string $post_type, string $template, int $limit, array $exclude ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				// Skipped posts are dropped below rather than with post__not_in, which is slow on big tables.
				'posts_per_page'         => max( 1, $limit ) + count( $exclude ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Templates are only stored in meta.
				'meta_query'             => $this->meta_query_for( $post_type, $template ),
			)
		);

		return array_slice( array_values( array_diff( wp_parse_id_list( $query->posts ), $exclude ) ), 0, max( 1, $limit ) );
	}

	/**
	 * Number of posts on a template.
	 *
	 * @param string $post_type Post type.
	 * @param string $template  Template slug, DEFAULT_TEMPLATE or MISSING.
	 * @return int
	 */
	private function count_matching( string $post_type, string $template ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Templates are only stored in meta.
				'meta_query'     => $this->meta_query_for( $post_type, $template ),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Forgets the cached usage counts of a post type.
	 *
	 * @param string $post_type Post type.
	 */
	public function flush_counts( string $post_type ): void {
		wp_cache_delete( 'counts:' . $post_type, self::CACHE_GROUP );
	}

	/**
	 * Forgets cached counts when a post's template meta changes.
	 *
	 * @param int|int[] $meta_ids  Meta IDs.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public function on_meta_change( $meta_ids, $object_id, $meta_key ): void {
		if ( self::META_KEY === $meta_key ) {
			$this->flush_counts( (string) get_post_type( (int) $object_id ) );
		}
	}

	/**
	 * Forgets cached counts when a post with a template changes status (trash, publish, delete).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_status_change( $new_status, $old_status, \WP_Post $post ): void {
		if ( $new_status !== $old_status ) {
			$this->flush_counts( $post->post_type );
		}
	}
}
