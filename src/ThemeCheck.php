<?php
/**
 * Checks what a theme switch would do to templates, and applies a saved plan on activation.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

defined( 'ABSPATH' ) || exit;

/**
 * For an installed theme that is not active: which items would lose their
 * template, what to use instead (the mapping), and moving them when the theme
 * is activated.
 */
final class ThemeCheck implements Service {

	public const OPTION_MAPS   = 'axle_page_template_usage_theme_maps';
	public const OPTION_RESULT = 'axle_page_template_usage_switch_result';
	public const CRON          = 'axle_page_template_usage_continue_switch';

	private const DEFAULT_BATCH = 500;

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Template history.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Sets up the theme check.
	 *
	 * @param Templates $templates Template data.
	 * @param History   $history   Template history.
	 */
	public function __construct( Templates $templates, History $history ) {
		$this->templates = $templates;
		$this->history   = $history;
	}

	/**
	 * Applies a saved mapping when its theme becomes active.
	 */
	public function register(): void {
		add_action( 'after_switch_theme', array( $this, 'on_switch' ), 20 );
		add_action( self::CRON, array( $this, 'continue_switch' ) );
		add_action( 'axle_page_template_usage_batch_undone', array( $this, 'on_batch_undone' ), 10, 2 );
	}

	/**
	 * Capability needed to check a theme, and to save or clear a mapping, which edits templates when the theme goes live.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to check a theme before switching, and to save a template mapping for it.
		 *
		 * The check lists the installed themes. Saving a mapping makes the next
		 * activation of that theme move items to other templates.
		 *
		 * @param string $capability Default switch_themes.
		 */
		return (string) apply_filters( 'axle_page_template_usage_theme_check_capability', 'switch_themes' );
	}

	/**
	 * Most items one theme switch moves in one request. The rest follows in the background.
	 *
	 * @param string $stylesheet Theme being activated.
	 * @return int
	 */
	public static function batch_size( string $stylesheet ): int {
		/**
		 * Filters how many items moving to a theme's templates are handled per request.
		 *
		 * The rest is moved in the background, a batch at a time, or with the
		 * button in the notice. WP-CLI has no limit.
		 *
		 * @param int    $size       Default 500.
		 * @param string $stylesheet Theme being activated.
		 */
		return max( 1, (int) apply_filters( 'axle_page_template_usage_switch_batch_size', self::DEFAULT_BATCH, $stylesheet ) );
	}

	/**
	 * Installed themes that are not active, for the picker.
	 *
	 * @return array<string, \WP_Theme> Stylesheet => theme.
	 */
	public function get_inactive_themes(): array {
		$themes = array();
		foreach ( wp_get_themes( array( 'allowed' => true ) ) as $stylesheet => $theme ) {
			if ( get_stylesheet() !== (string) $stylesheet && ! $theme->errors() ) {
				$themes[ (string) $stylesheet ] = $theme;
			}
		}

		return $themes;
	}

	/**
	 * Templates a theme offers for a post type, without needing it to be active.
	 *
	 * Classic themes: PHP files with `Template Name` (and `Template Post Type`).
	 * Block themes: `customTemplates` in theme.json, in the theme and its parent,
	 * and custom templates saved in the Site Editor for that theme.
	 *
	 * @param \WP_Theme $theme     Theme, active or not.
	 * @param string    $post_type Post type name.
	 * @return array<string, string> Template file or slug => display name.
	 */
	public function get_theme_templates( \WP_Theme $theme, string $post_type ): array {
		// Core adds the ACTIVE theme's block templates inside get_post_templates(). Not wanted here.
		add_filter( 'current_theme_supports-block-templates', '__return_false', 99 );
		$files = $theme->get_post_templates();
		remove_filter( 'current_theme_supports-block-templates', '__return_false', 99 );

		$templates = isset( $files[ $post_type ] ) ? (array) $files[ $post_type ] : array();
		$allowed   = array();

		foreach ( $this->theme_json_templates( $theme ) as $name => $custom ) {
			$allowed[ $name ] = $custom['postTypes'];
			if ( in_array( $post_type, $custom['postTypes'], true ) ) {
				$templates[ $name ] = $templates[ $name ] ?? $custom['title'];
			}
		}

		foreach ( $this->saved_templates( $theme ) as $slug => $title ) {
			if ( ! isset( $allowed[ $slug ] ) || in_array( $post_type, $allowed[ $slug ], true ) ) {
				$templates[ $slug ] = $title;
			}
		}

		asort( $templates, SORT_NATURAL | SORT_FLAG_CASE );

		/**
		 * Filters the templates a theme offers for a post type, when checking a theme that is not active.
		 *
		 * @param array<string, string> $templates  Template file or slug => display name.
		 * @param string                $stylesheet Theme to check.
		 * @param string                $post_type  Post type name.
		 * @param \WP_Theme             $theme      The theme.
		 */
		return (array) apply_filters( 'axle_page_template_usage_theme_templates', $templates, $theme->get_stylesheet(), $post_type, $theme );
	}

	/**
	 * Custom templates a block theme declares in theme.json, with its parent's.
	 *
	 * @param \WP_Theme $theme Theme.
	 * @return array<string, array{title: string, postTypes: string[]}>
	 */
	private function theme_json_templates( \WP_Theme $theme ): array {
		$found = array();
		$chain = array( $theme );
		if ( $theme->parent() ) {
			$chain[] = $theme->parent();
		}

		foreach ( $chain as $item ) {
			$file = trailingslashit( $item->get_stylesheet_directory() ) . 'theme.json';
			if ( ! is_readable( $file ) ) {
				continue;
			}

			$data = wp_json_file_decode( $file, array( 'associative' => true ) );
			foreach ( (array) ( $data['customTemplates'] ?? array() ) as $custom ) {
				if ( ! is_array( $custom ) || empty( $custom['name'] ) ) {
					continue;
				}
				$name = (string) $custom['name'];
				if ( isset( $found[ $name ] ) ) {
					continue;
				}
				$found[ $name ] = array(
					'title'     => isset( $custom['title'] ) ? (string) $custom['title'] : $name,
					'postTypes' => isset( $custom['postTypes'] ) ? array_map( 'strval', (array) $custom['postTypes'] ) : array( 'page' ),
				);
			}
		}

		return $found;
	}

	/**
	 * Custom templates saved in the Site Editor for a theme (slug => title).
	 *
	 * @param \WP_Theme $theme Theme.
	 * @return array<string, string>
	 */
	private function saved_templates( \WP_Theme $theme ): array {
		$defaults = function_exists( 'get_default_block_template_types' ) ? array_keys( get_default_block_template_types() ) : array();
		$posts    = get_posts(
			array(
				'post_type'              => 'wp_template',
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Templates are tied to a theme only through this taxonomy.
				'tax_query'              => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme->get_stylesheet(),
					),
				),
			)
		);

		$saved = array();
		foreach ( $posts as $post ) {
			if ( in_array( $post->post_name, $defaults, true ) || get_post_meta( $post->ID, 'is_wp_suggestion', true ) ) {
				continue;
			}
			$saved[ $post->post_name ] = $post->post_title;
		}

		return $saved;
	}

	/**
	 * What would lose its template if a theme became active.
	 *
	 * @param string        $stylesheet Theme folder name.
	 * @param string|null   $post_type  Only this post type, or null for every type that uses templates.
	 * @param string[]|null $allowed    Post types the answer may cover, such as the ones the current user can edit. Null for every one.
	 * @return array{theme: array{stylesheet: string, name: string}, groups: array<string, array<int, array{slug: string, name: string, count: int, already_missing: bool}>>, total: int}|\WP_Error
	 */
	public function check( string $stylesheet, ?string $post_type = null, ?array $allowed = null ) {
		$themes = $this->get_inactive_themes();

		if ( get_stylesheet() === $stylesheet ) {
			return new \WP_Error( 'axle_page_template_usage_active_theme', __( 'That theme is already active. The Templates tab shows what its pages use.', 'axle-page-template-usage' ) );
		}

		if ( ! isset( $themes[ $stylesheet ] ) ) {
			return new \WP_Error( 'axle_page_template_usage_unknown_theme', __( 'That theme is not installed, or it has errors.', 'axle-page-template-usage' ) );
		}

		$theme  = $themes[ $stylesheet ];
		$groups = array();
		$total  = 0;
		$types  = null === $post_type ? wp_list_pluck( $this->templates->get_post_types(), 'name' ) : array( $post_type );
		if ( null !== $allowed ) {
			$types = array_intersect( $types, $allowed );
		}

		foreach ( $types as $type ) {
			$available = $this->get_theme_templates( $theme, (string) $type );

			foreach ( $this->templates->get_usage_counts( (string) $type ) as $slug => $count ) {
				$slug = (string) $slug;
				if ( $this->templates->is_default( $slug ) || isset( $available[ $slug ] ) ) {
					continue;
				}

				$groups[ $type ][] = array(
					'slug'            => $slug,
					'name'            => $this->templates->get_name( (string) $type, $slug ) ?? $slug,
					'count'           => (int) $count,
					'already_missing' => null === $this->templates->get_name( (string) $type, $slug ),
				);
				$total            += (int) $count;
			}
		}

		return array(
			'theme'  => array(
				'stylesheet' => $stylesheet,
				'name'       => (string) $theme->get( 'Name' ),
			),
			'groups' => $groups,
			'total'  => $total,
		);
	}

	/**
	 * A few pages that use a template, for the check list.
	 *
	 * @param string $post_type Post type name.
	 * @param string $template  Stored template value.
	 * @return array<int, array{id: int, title: string}>
	 */
	public function get_sample( string $post_type, string $template ): array {
		/**
		 * Filters how many example items are listed under each template in the theme check.
		 *
		 * @param int $size Default 5. Use 0 to list none.
		 */
		$size = max( 0, (int) apply_filters( 'axle_page_template_usage_theme_check_sample_size', 5 ) );
		if ( ! $size ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => $size,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Templates are only stored in meta.
				'meta_query'             => $this->templates->meta_query_for( $post_type, $template ),
			)
		);

		$sample = array();
		foreach ( $posts as $post ) {
			$sample[] = array(
				'id'    => (int) $post->ID,
				'title' => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'axle-page-template-usage' ),
			);
		}

		return $sample;
	}

	/**
	 * Templates an item can use under a theme: the default template, and what the theme offers.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @param string $post_type  Post type name.
	 * @return array<string, string> Value => display name. The default template comes first.
	 */
	public function get_targets( string $stylesheet, string $post_type ): array {
		$theme = wp_get_theme( $stylesheet );

		return array( Templates::DEFAULT_TEMPLATE => __( 'Default', 'axle-page-template-usage' ) ) + ( $theme->exists() ? $this->get_theme_templates( $theme, $post_type ) : array() );
	}

	/**
	 * Saved mapping for a theme.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @return array<string, array<string, string>> Post type => ( template to replace => template to use ).
	 */
	public function get_mapping( string $stylesheet ): array {
		$maps    = get_option( self::OPTION_MAPS, array() );
		$mapping = is_array( $maps ) && isset( $maps[ $stylesheet ]['map'] ) && is_array( $maps[ $stylesheet ]['map'] ) ? $maps[ $stylesheet ]['map'] : array();

		/**
		 * Filters the template mapping used when a theme is activated.
		 *
		 * Use it to supply a mapping from code. The special key `missing`
		 * stands for every template the new theme does not have.
		 *
		 * @param array<string, array<string, string>> $mapping    Post type => ( template to replace => template to use ).
		 * @param string                               $stylesheet Theme being activated.
		 */
		return (array) apply_filters( 'axle_page_template_usage_theme_mapping', $mapping, $stylesheet );
	}

	/**
	 * When the saved mapping was saved, and by whom.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @return array{time: int, user: int}|null
	 */
	public function get_mapping_meta( string $stylesheet ): ?array {
		$maps = get_option( self::OPTION_MAPS, array() );
		if ( ! is_array( $maps ) || empty( $maps[ $stylesheet ]['map'] ) ) {
			return null;
		}

		return array(
			'time' => (int) ( $maps[ $stylesheet ]['time'] ?? 0 ),
			'user' => (int) ( $maps[ $stylesheet ]['user'] ?? 0 ),
		);
	}

	/**
	 * Saves the mapping for a theme, after checking every entry against what the theme offers.
	 *
	 * @param string                               $stylesheet Theme folder name.
	 * @param array<string, array<string, string>> $mapping    Post type => ( template to replace => template to use ).
	 * @return array<string, array<string, string>>|\WP_Error The mapping as saved.
	 */
	public function save_mapping( string $stylesheet, array $mapping ) {
		$check = $this->check( $stylesheet );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$clean = array();
		foreach ( $mapping as $post_type => $pairs ) {
			$post_type = sanitize_key( (string) $post_type );
			$targets   = $this->get_targets( $stylesheet, $post_type );
			foreach ( (array) $pairs as $from => $to ) {
				$from = (string) $from;
				$to   = '' === (string) $to ? Templates::DEFAULT_TEMPLATE : (string) $to;
				$ok   = 'missing' === $from || in_array( $from, wp_list_pluck( (array) ( $check['groups'][ $post_type ] ?? array() ), 'slug' ), true );
				if ( ! $ok ) {
					continue;
				}
				if ( ! isset( $targets[ $to ] ) ) {
					/* translators: 1: template to use, 2: theme name. */
					return new \WP_Error( 'axle_page_template_usage_unknown_template', sprintf( __( 'The theme "%2$s" does not have the template "%1$s".', 'axle-page-template-usage' ), $to, $check['theme']['name'] ) );
				}
				$clean[ $post_type ][ $from ] = $to;
			}
		}

		$maps = get_option( self::OPTION_MAPS, array() );
		$maps = is_array( $maps ) ? $maps : array();
		if ( $clean ) {
			$maps[ $stylesheet ] = array(
				'map'  => $clean,
				'time' => time(),
				'user' => get_current_user_id(),
			);
		} else {
			unset( $maps[ $stylesheet ] );
		}
		update_option( self::OPTION_MAPS, $maps, false );

		/**
		 * Fires after a mapping was saved for a theme. An empty mapping means it was cleared.
		 *
		 * @param string                               $stylesheet Theme folder name.
		 * @param array<string, array<string, string>> $clean      Post type => ( template to replace => template to use ).
		 */
		do_action( 'axle_page_template_usage_mapping_saved', $stylesheet, $clean );

		return $clean;
	}

	/**
	 * Forgets the mapping saved for a theme.
	 *
	 * @param string $stylesheet Theme folder name.
	 */
	public function clear_mapping( string $stylesheet ): void {
		$maps = get_option( self::OPTION_MAPS, array() );
		if ( is_array( $maps ) && isset( $maps[ $stylesheet ] ) ) {
			unset( $maps[ $stylesheet ] );
			update_option( self::OPTION_MAPS, $maps, false );
		}
	}

	/**
	 * Runs on the first request after a theme switch: moves items per the saved mapping.
	 */
	public function on_switch(): void {
		$stylesheet = get_stylesheet();

		/**
		 * Filters whether the saved mapping runs by itself when its theme is activated.
		 *
		 * Return false to keep the mapping and run it by hand with
		 * `wp axle-page-template-usage apply-mapping`.
		 *
		 * @param bool   $apply      Default true.
		 * @param string $stylesheet Theme that was just activated.
		 */
		if ( apply_filters( 'axle_page_template_usage_apply_on_switch', true, $stylesheet ) ) {
			$this->start( self::batch_size( $stylesheet ) );
		}
	}

	/**
	 * Moves items per the saved mapping of the active theme, as one batch.
	 *
	 * @param int $limit Most items to move now, 0 for all.
	 * @return array<string, mixed>|null The switch state, or null when the active theme has no mapping.
	 */
	public function start( int $limit ): ?array {
		$stylesheet = get_stylesheet();
		if ( ! $this->get_mapping( $stylesheet ) ) {
			return null;
		}

		$state = array(
			'stylesheet' => $stylesheet,
			'theme'      => (string) wp_get_theme()->get( 'Name' ),
			'batch'      => $this->history->new_batch(),
			'user'       => get_current_user_id(),
			'time'       => time(),
			'moved'      => 0,
			'skipped'    => 0,
			'remaining'  => 0,
			'errors'     => 0,
		);

		return $this->run( $state, $limit );
	}

	/**
	 * Moves what is left of a switch that was too big for one request.
	 */
	public function continue_switch(): void {
		$state = get_option( self::OPTION_RESULT );
		if ( is_array( $state ) && ! empty( $state['remaining'] ) && get_stylesheet() === ( $state['stylesheet'] ?? '' ) ) {
			$this->run( $state, self::batch_size( (string) $state['stylesheet'] ) );
		}
	}

	/**
	 * Moves items per the mapping of the active theme, up to $limit, and saves the result for the notice.
	 *
	 * @param array<string, mixed> $state Switch state: stylesheet, theme, batch, user, time, moved, skipped, remaining, errors.
	 * @param int                  $limit Most items to move now, 0 for all.
	 * @return array<string, mixed> The state after the run.
	 */
	public function run( array $state, int $limit ): array {
		$stylesheet = (string) $state['stylesheet'];
		$mapping    = $this->get_mapping( $stylesheet );
		$budget     = $limit;
		$remaining  = 0;
		$errors     = 0;

		foreach ( $mapping as $post_type => $pairs ) {
			$post_type = (string) $post_type;
			$known     = $this->templates->get_templates( $post_type );

			foreach ( (array) $pairs as $from => $to ) {
				$from = 'missing' === (string) $from ? Templates::MISSING : (string) $from;

				// A template this theme has needs no move. Everything else is checked by reassign().
				if ( isset( $known[ $from ] ) ) {
					continue;
				}

				if ( $limit && $budget < 1 ) {
					$remaining += $this->templates->count_matching( $post_type, $from );
					continue;
				}

				$result = $this->templates->reassign( $post_type, $from, (string) $to, $limit ? $budget : 0, false, 'theme_switch', (string) $state['batch'], (int) $state['user'] );
				if ( is_wp_error( $result ) ) {
					++$errors;
					continue;
				}

				$state['moved'] += $result['moved'];
				$budget         -= $result['moved'];
				$remaining      += $result['remaining'];
			}
		}

		$state['remaining'] = $remaining;
		$state['errors']    = (int) $state['errors'] + $errors;
		update_option( self::OPTION_RESULT, $state, false );

		wp_clear_scheduled_hook( self::CRON );
		if ( $remaining ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON );
		} else {
			$this->clear_mapping( $stylesheet );
		}

		/**
		 * Fires after items moved to match a theme that was just activated.
		 *
		 * Fires once per run: the first one on the switch, more while items are left.
		 *
		 * @param string               $stylesheet Theme that is active now.
		 * @param array<string, mixed> $state      theme, batch, user, time, moved, skipped, remaining, errors.
		 */
		do_action( 'axle_page_template_usage_mapping_applied', $stylesheet, $state );

		return $state;
	}

	/**
	 * The result of the last theme switch, for the notice. Null when there is none to show.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_result(): ?array {
		$state = get_option( self::OPTION_RESULT );

		return is_array( $state ) && ! empty( $state['batch'] ) ? $state : null;
	}

	/**
	 * Hides the result notice.
	 */
	public function dismiss_result(): void {
		delete_option( self::OPTION_RESULT );
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Stops following up a theme switch that was undone.
	 *
	 * @param string               $batch   Batch that was undone.
	 * @param array<string, mixed> $summary Result of the undo: undone, skipped, remaining, batch.
	 */
	public function on_batch_undone( $batch, $summary = array() ): void {
		$state = $this->get_result();
		if ( $state && (string) $batch === $state['batch'] && empty( $summary['remaining'] ) ) {
			$this->dismiss_result();
			$this->clear_mapping( (string) $state['stylesheet'] );
		}
	}
}
