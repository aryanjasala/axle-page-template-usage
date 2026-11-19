<?php
/**
 * Checks a theme update before it runs: which pages would lose their template.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

use AryanX\Axle\PageTemplateUsage\Admin\ReportPage;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads the update package of the active theme (or its parent) to a
 * temporary file, reads the templates it offers without running any of its
 * code (see TemplateFiles), compares them with the installed version, and
 * keeps the answer: template names only, never page titles or counts, so
 * what a person sees is worked out for that person (post types they can
 * edit, items they can see).
 *
 * A plan for the update reuses the theme switch mapping: items move right
 * after the update is installed, as one history batch with Undo.
 */
final class UpdateCheck implements Service {

	public const OPTION_CHECKS = 'axle_page_template_usage_update_checks';
	public const OPTION_PLANS  = 'axle_page_template_usage_update_plans';
	public const CRON          = 'axle_page_template_usage_apply_update_plan';

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Theme check, which runs the moves.
	 *
	 * @var ThemeCheck
	 */
	private ThemeCheck $theme;

	/**
	 * Sets up the update check.
	 *
	 * @param Templates  $templates Template data.
	 * @param ThemeCheck $theme     Theme check.
	 */
	public function __construct( Templates $templates, ThemeCheck $theme ) {
		$this->templates = $templates;
		$this->theme     = $theme;
	}

	/**
	 * Watches for the update to land, to run the plan.
	 */
	public function register(): void {
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 20, 2 );
		add_action( self::CRON, array( $this, 'apply_due' ) );
		add_action( 'admin_init', array( $this, 'maybe_apply' ) );
	}

	/**
	 * Capability needed to check a theme update and to plan its moves.
	 *
	 * The Axle Page Template Usage capability and the theme check one are needed as well.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to check a theme update before it runs, and to plan what happens to the pages.
		 *
		 * @param string $capability Default update_themes.
		 */
		return (string) apply_filters( 'axle_page_template_usage_update_check_capability', 'update_themes' );
	}

	/**
	 * Whether the current user may check theme updates and plan their moves.
	 *
	 * @return bool
	 */
	public static function can_check(): bool {
		return ReportPage::can_check_themes() && current_user_can( self::capability() );
	}

	/**
	 * Updates waiting for the active theme and its parent.
	 *
	 * Other themes cannot take a page's template away: pages use the active theme's templates.
	 *
	 * @return array<string, array{stylesheet: string, name: string, role: string, from: string, to: string, package: string}> Stylesheet => details.
	 */
	public function pending_updates(): array {
		$updates  = get_site_transient( 'update_themes' );
		$response = is_object( $updates ) && isset( $updates->response ) ? (array) $updates->response : array();
		$pending  = array();

		foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $stylesheet ) {
			$stylesheet = (string) $stylesheet;
			$theme      = wp_get_theme( $stylesheet );
			if ( ! isset( $response[ $stylesheet ] ) || ! $theme->exists() ) {
				continue;
			}

			$info                   = (array) $response[ $stylesheet ];
			$pending[ $stylesheet ] = array(
				'stylesheet' => $stylesheet,
				'name'       => (string) $theme->get( 'Name' ),
				'role'       => get_stylesheet() === $stylesheet ? 'active' : 'parent',
				'from'       => (string) $theme->get( 'Version' ),
				'to'         => isset( $info['new_version'] ) ? (string) $info['new_version'] : '',
				'package'    => isset( $info['package'] ) ? (string) $info['package'] : '',
			);
		}

		return $pending;
	}

	/**
	 * Downloads the package of a waiting update and works out which templates it takes away.
	 *
	 * Nothing is installed and nothing of the theme runs. The download and the
	 * folder it is read in are deleted before this returns.
	 *
	 * @param string $stylesheet Theme folder name: the active theme or its parent.
	 * @return array<string, mixed>|\WP_Error The result: stylesheet, name, from, to, time, lost, targets. Template names only.
	 */
	public function check( string $stylesheet ) {
		$pending = $this->pending_updates();
		if ( ! isset( $pending[ $stylesheet ] ) ) {
			return new \WP_Error( 'axle_page_template_usage_no_update', __( 'There is no update waiting for that theme. Only the active theme and its parent are checked.', 'axle-page-template-usage' ) );
		}

		$info = $pending[ $stylesheet ];

		/**
		 * Filters the address of the package a theme update would install.
		 *
		 * Premium themes often have no package until a license is checked: a
		 * license plugin can supply one here, or hook `upgrader_pre_download`
		 * as it does for the update itself.
		 *
		 * @param string               $package    Package URL, empty when the update has none.
		 * @param string               $stylesheet Theme folder name.
		 * @param array<string, mixed> $info       name, role, from, to.
		 */
		$package = (string) apply_filters( 'axle_page_template_usage_update_package', $info['package'], $stylesheet, $info );
		if ( '' === $package ) {
			return new \WP_Error( 'axle_page_template_usage_no_package', __( 'The update has no package to read. Premium themes may need a valid license first.', 'axle-page-template-usage' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
		$file     = $upgrader->download_package(
			$package,
			false,
			array(
				'type'   => 'theme',
				'action' => 'update',
				'theme'  => $stylesheet,
			)
		);
		if ( is_wp_error( $file ) ) {
			return new \WP_Error( 'axle_page_template_usage_download', __( 'The update package could not be downloaded.', 'axle-page-template-usage' ) );
		}

		try {
			/**
			 * Filters the largest update package that is read, in bytes.
			 *
			 * @param int $bytes Default 100 MB.
			 */
			$max = max( 1048576, (int) apply_filters( 'axle_page_template_usage_update_max_package_size', 100 * MB_IN_BYTES ) );
			if ( (int) filesize( $file ) > $max ) {
				return new \WP_Error( 'axle_page_template_usage_update_big', __( 'The update package is too big to read here.', 'axle-page-template-usage' ) );
			}

			$package_set = TemplateFiles::from_package( $file );
		} finally {
			// A package a license plugin cached for the update itself is left alone, like core does.
			if ( $file !== $package ) {
				wp_delete_file( $file );
			}
		}

		if ( is_wp_error( $package_set ) ) {
			return $package_set;
		}

		$result = $this->compare( $stylesheet, $package_set, $info );
		$this->store_result( $result );

		/**
		 * Fires after a theme update was checked.
		 *
		 * The result holds template names only: no page titles and no counts.
		 *
		 * @param array<string, mixed> $result     stylesheet, name, from, to, time, lost (post type => template => name), targets.
		 * @param string               $stylesheet Theme folder name.
		 */
		do_action( 'axle_page_template_usage_update_checked', $result, $stylesheet );

		return $result;
	}

	/**
	 * Which templates the installed theme chain offers and the updated chain would not.
	 *
	 * Both sides are read the same way, from files, so a template a plugin adds
	 * is on neither side and is never reported. Templates saved in the Site
	 * Editor stay, as an update does not touch the database.
	 *
	 * @param string                                        $stylesheet Theme being updated.
	 * @param array<string, array<string, string>>          $package_set What the package offers.
	 * @param array{name: string, from: string, to: string} $info       The update.
	 * @return array<string, mixed>
	 */
	private function compare( string $stylesheet, array $package_set, array $info ): array {
		$before = array();
		$after  = array();
		foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $folder ) {
			$installed = TemplateFiles::from_dir( wp_get_theme( (string) $folder )->get_stylesheet_directory() );
			$before[]  = $installed;
			$after[]   = (string) $folder === $stylesheet ? $package_set : $installed;
		}

		$saved    = array( TemplateFiles::ANY => $this->theme->saved_templates( wp_get_theme() ) );
		$before[] = $saved;
		$after[]  = $saved;
		$before   = TemplateFiles::merge( $before );
		$after    = TemplateFiles::merge( $after );

		$types = array_map( 'strval', wp_list_pluck( $this->templates->get_post_types(), 'name' ) );
		$lost  = array();
		$offer = array();
		foreach ( array_unique( array_merge( $types, array( 'page' ) ) ) as $type ) {
			$gone = array_diff_key( TemplateFiles::for_type( $before, $type ), TemplateFiles::for_type( $after, $type ) );
			if ( $gone ) {
				$lost[ $type ] = $gone;
			}
			$offer[ $type ] = TemplateFiles::for_type( $after, $type );
		}

		return array(
			'stylesheet' => $stylesheet,
			'name'       => $info['name'],
			'from'       => $info['from'],
			'to'         => $info['to'],
			'time'       => time(),
			'lost'       => $lost,
			'targets'    => $offer,
		);
	}

	/**
	 * Keeps a result until the update is installed or the result is old.
	 *
	 * @param array<string, mixed> $result The result.
	 */
	private function store_result( array $result ): void {
		$checks = get_option( self::OPTION_CHECKS, array() );
		$checks = is_array( $checks ) ? $checks : array();
		$keep   = array_flip( array_map( 'strval', array_keys( $this->pending_updates() ) ) );

		// Only the updates still waiting are kept.
		$checks                          = array_intersect_key( $checks, $keep );
		$checks[ $result['stylesheet'] ] = $result;
		update_option( self::OPTION_CHECKS, $checks, false );
	}

	/**
	 * The stored result of checking the update that is waiting now.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @return array<string, mixed>|null Null when never checked, checked for another version, or too old.
	 */
	public function get_result( string $stylesheet ): ?array {
		$checks  = get_option( self::OPTION_CHECKS, array() );
		$result  = is_array( $checks ) && isset( $checks[ $stylesheet ] ) && is_array( $checks[ $stylesheet ] ) ? $checks[ $stylesheet ] : null;
		$pending = $this->pending_updates();
		if ( ! $result || ! isset( $pending[ $stylesheet ] ) || ( $result['to'] ?? '' ) !== $pending[ $stylesheet ]['to'] || ( $result['from'] ?? '' ) !== $pending[ $stylesheet ]['from'] ) {
			return null;
		}

		/**
		 * Filters how long the result of checking an update is trusted, in seconds.
		 *
		 * Templates saved in the Site Editor can change meanwhile, so a day is the default.
		 *
		 * @param int $seconds Default one day.
		 */
		$lifetime = max( MINUTE_IN_SECONDS, (int) apply_filters( 'axle_page_template_usage_update_check_lifetime', DAY_IN_SECONDS ) );

		return time() - (int) ( $result['time'] ?? 0 ) > $lifetime ? null : $result;
	}

	/**
	 * What the current user would lose: pages on the templates the update takes away.
	 *
	 * Only post types the user can edit, and only the items they could see in the
	 * list screen are counted, so a role without access to private pages learns
	 * nothing about them.
	 *
	 * @param string        $stylesheet Theme folder name.
	 * @param string[]|null $types      Post types to cover. Null for the ones the current user can edit; WP-CLI passes every type.
	 * @return array{theme: array{stylesheet: string, name: string, from: string, to: string, time: int}, groups: array<string, array<int, array{slug: string, name: string, count: int}>>, targets: array<string, array<string, string>>, total: int}|null Null when there is no fresh result.
	 */
	public function report( string $stylesheet, ?array $types = null ): ?array {
		$result = $this->get_result( $stylesheet );
		if ( ! $result ) {
			return null;
		}

		$groups  = array();
		$total   = 0;
		$allowed = null === $types ? ReportPage::accessible_types() : $types;
		foreach ( (array) $result['lost'] as $type => $slugs ) {
			$type = (string) $type;
			if ( ! in_array( $type, $allowed, true ) ) {
				continue;
			}

			$counts = $this->templates->get_usage_counts( $type );
			foreach ( (array) $slugs as $slug => $name ) {
				$count = (int) ( $counts[ (string) $slug ] ?? 0 );
				if ( $count ) {
					$groups[ $type ][] = array(
						'slug'  => (string) $slug,
						'name'  => (string) $name,
						'count' => $count,
					);
					$total            += $count;
				}
			}
		}

		return array(
			'theme'   => array(
				'stylesheet' => $stylesheet,
				'name'       => (string) $result['name'],
				'from'       => (string) $result['from'],
				'to'         => (string) $result['to'],
				'time'       => (int) $result['time'],
			),
			'groups'  => $groups,
			'targets' => (array) $result['targets'],
			'total'   => $total,
		);
	}

	/**
	 * The plan saved for an update.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @return array{map: array<string, array<string, string>>, from: string, to: string, time: int, user: int}|null
	 */
	public function get_plan( string $stylesheet ): ?array {
		$plans = get_option( self::OPTION_PLANS, array() );
		$plan  = is_array( $plans ) && isset( $plans[ $stylesheet ]['map'] ) && is_array( $plans[ $stylesheet ]['map'] ) ? $plans[ $stylesheet ] : null;
		if ( ! $plan ) {
			return null;
		}

		return array(
			'map'  => $plan['map'],
			'from' => (string) ( $plan['from'] ?? '' ),
			'to'   => (string) ( $plan['to'] ?? '' ),
			'time' => (int) ( $plan['time'] ?? 0 ),
			'user' => (int) ( $plan['user'] ?? 0 ),
		);
	}

	/**
	 * Saves what should happen to the pages right after the update is installed.
	 *
	 * Entries for post types the user cannot edit are left as they were. An
	 * empty mapping for the user's post types removes their entries.
	 *
	 * @param string                               $stylesheet Theme folder name.
	 * @param array<string, array<string, string>> $mapping    Post type => ( template the update takes away => template to use, or default ).
	 * @param bool                                 $trusted    No capability checks and every post type: for WP-CLI, which has no user.
	 * @return array<string, array<string, string>>|\WP_Error The plan as saved.
	 */
	public function save_plan( string $stylesheet, array $mapping, bool $trusted = false ) {
		if ( ! $trusted && ! self::can_check() ) {
			return new \WP_Error( 'axle_page_template_usage_forbidden', __( 'Sorry, you are not allowed to plan template changes for a theme update.', 'axle-page-template-usage' ) );
		}

		$result = $this->get_result( $stylesheet );
		$types  = $trusted ? array_map( 'strval', array_keys( (array) ( $result['lost'] ?? array() ) ) ) : null;
		$report = $this->report( $stylesheet, $types );
		if ( ! $report ) {
			return new \WP_Error( 'axle_page_template_usage_update_unchecked', __( 'Check the update first: the result is missing or out of date.', 'axle-page-template-usage' ) );
		}

		$clean = array();
		foreach ( $mapping as $post_type => $pairs ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( ! isset( $report['groups'][ $post_type ] ) ) {
				continue;
			}

			$known   = wp_list_pluck( $report['groups'][ $post_type ], 'slug' );
			$targets = (array) ( $report['targets'][ $post_type ] ?? array() );
			foreach ( (array) $pairs as $from => $to ) {
				$from = (string) $from;
				$to   = '' === (string) $to ? Templates::DEFAULT_TEMPLATE : (string) $to;
				if ( ! in_array( $from, $known, true ) ) {
					continue;
				}
				if ( Templates::DEFAULT_TEMPLATE !== $to && ! isset( $targets[ $to ] ) ) {
					return new \WP_Error( 'axle_page_template_usage_unknown_template', __( 'The updated theme does not have one of the templates you chose.', 'axle-page-template-usage' ) );
				}
				$clean[ $post_type ][ $from ] = $to;
			}
		}

		$plans = get_option( self::OPTION_PLANS, array() );
		$plans = is_array( $plans ) ? $plans : array();
		$old   = isset( $plans[ $stylesheet ]['map'] ) && is_array( $plans[ $stylesheet ]['map'] ) ? $plans[ $stylesheet ]['map'] : array();
		// What this user may not edit stays as another person planned it.
		$keep = array_diff_key( $old, array_flip( null === $types ? ReportPage::accessible_types() : $types ) );
		$map  = array_merge( $keep, $clean );

		if ( $map ) {
			$plans[ $stylesheet ] = array(
				'map'  => $map,
				'from' => $report['theme']['from'],
				'to'   => $report['theme']['to'],
				'time' => time(),
				'user' => get_current_user_id(),
			);
		} else {
			unset( $plans[ $stylesheet ] );
		}
		update_option( self::OPTION_PLANS, $plans, true );

		/**
		 * Fires after the plan for a theme update was saved. An empty plan means it was cleared.
		 *
		 * @param string                               $stylesheet Theme folder name.
		 * @param array<string, array<string, string>> $map        Post type => ( template to replace => template to use ).
		 */
		do_action( 'axle_page_template_usage_update_plan_saved', $stylesheet, $map );

		return $clean;
	}

	/**
	 * Forgets the plan of an update, for every post type.
	 *
	 * @param string $stylesheet Theme folder name.
	 */
	public function clear_plan( string $stylesheet ): void {
		$plans = get_option( self::OPTION_PLANS, array() );
		if ( is_array( $plans ) && isset( $plans[ $stylesheet ] ) ) {
			unset( $plans[ $stylesheet ] );
			update_option( self::OPTION_PLANS, $plans, true );

			/** This action is documented in src/UpdateCheck.php. */
			do_action( 'axle_page_template_usage_update_plan_saved', $stylesheet, array() );
		}
	}

	/**
	 * A theme was updated: look at the plan a little later, in its own request.
	 *
	 * The update replaced files this request still has loaded.
	 *
	 * @param mixed $upgrader Upgrader.
	 * @param mixed $extra    What was upgraded: type, action, themes.
	 */
	public function on_upgrade( $upgrader, $extra = array() ): void {
		unset( $upgrader );

		if ( is_array( $extra ) && isset( $extra['type'] ) && 'theme' === $extra['type'] && get_option( self::OPTION_PLANS ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 15, self::CRON );
		}
	}

	/**
	 * Cron callback.
	 */
	public function apply_due(): void {
		$this->apply( false );
	}

	/**
	 * Admin requests also look, in case WP-Cron did not run.
	 */
	public function maybe_apply(): void {
		if ( get_option( self::OPTION_PLANS ) ) {
			$this->apply( false );
		}
	}

	/**
	 * Runs the plans whose update is installed, and drops the ones that are stale.
	 *
	 * A plan is due when the installed version is not the one it was made for.
	 * It is removed before it runs, so two requests never run it twice.
	 *
	 * @param bool $force Run due plans even when the filter says to wait for a manual run.
	 * @return array<string, array<string, mixed>> Stylesheet => switch state, for the plans that ran.
	 */
	public function apply( bool $force ): array {
		$plans = get_option( self::OPTION_PLANS, array() );
		if ( ! is_array( $plans ) || ! $plans ) {
			return array();
		}

		/**
		 * Filters whether a plan for a theme update runs by itself when the update is installed.
		 *
		 * Return false to keep the plan and run it by hand with
		 * `wp axle-page-template-usage apply-update-plan`.
		 *
		 * @param bool $apply Default true.
		 */
		$auto = (bool) apply_filters( 'axle_page_template_usage_apply_on_update', true );

		/**
		 * Filters how long a plan for a theme update waits for the update, in seconds.
		 *
		 * @param int $seconds Default 14 days.
		 */
		$lifetime = max( HOUR_IN_SECONDS, (int) apply_filters( 'axle_page_template_usage_update_plan_lifetime', 14 * DAY_IN_SECONDS ) );
		$ran      = array();

		foreach ( $plans as $stylesheet => $plan ) {
			$stylesheet = (string) $stylesheet;
			$theme      = wp_get_theme( $stylesheet );
			$plan       = (array) $plan;

			if ( ! $theme->exists() || time() - (int) ( $plan['time'] ?? 0 ) > $lifetime ) {
				unset( $plans[ $stylesheet ] );
				continue;
			}

			if ( (string) $theme->get( 'Version' ) === (string) ( $plan['from'] ?? '' ) || ( ! $auto && ! $force ) ) {
				continue;
			}

			unset( $plans[ $stylesheet ] );
			update_option( self::OPTION_PLANS, $plans, true );
			$ran[ $stylesheet ] = $this->theme->start_update( $stylesheet, (array) $plan['map'], (int) ( $plan['user'] ?? 0 ), ThemeCheck::batch_size( $stylesheet ) );
		}

		if ( get_option( self::OPTION_PLANS ) !== $plans ) {
			if ( $plans ) {
				update_option( self::OPTION_PLANS, $plans, true );
			} else {
				delete_option( self::OPTION_PLANS );
			}
		}

		return $ran;
	}
}
