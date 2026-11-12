<?php
/**
 * Appearance > Axle Page Template Usage report.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Admin;

use AryanX\Axle\PageTemplateUsage\Service;
use AryanX\Axle\PageTemplateUsage\Templates;
use AryanX\Axle\PageTemplateUsage\ThemeCheck;
use const AryanX\Axle\PageTemplateUsage\PLUGIN_FILE;
use const AryanX\Axle\PageTemplateUsage\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every template with its usage, and moves posts off a template in one go.
 */
final class ReportPage implements Service {

	public const SLUG = 'axle-page-template-usage';

	private const ACTION = 'axle_page_template_usage_reassign';

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * The theme check tab.
	 *
	 * @var ThemeCheckTab|null
	 */
	private ?ThemeCheckTab $theme_tab;

	/**
	 * The history tab.
	 *
	 * @var HistoryTab|null
	 */
	private ?HistoryTab $history_tab;

	/**
	 * The missing templates tab.
	 *
	 * @var MissingTab|null
	 */
	private ?MissingTab $missing_tab;

	/**
	 * The preview picker.
	 *
	 * @var PreviewTab|null
	 */
	private ?PreviewTab $preview_tab;

	/**
	 * Sets up the report.
	 *
	 * @param Templates          $templates   Template data.
	 * @param ThemeCheckTab|null $theme_tab   The theme check tab.
	 * @param HistoryTab|null    $history_tab The history tab.
	 * @param MissingTab|null    $missing_tab The missing templates tab.
	 * @param PreviewTab|null    $preview_tab The preview picker.
	 */
	public function __construct( Templates $templates, ?ThemeCheckTab $theme_tab = null, ?HistoryTab $history_tab = null, ?MissingTab $missing_tab = null, ?PreviewTab $preview_tab = null ) {
		$this->templates   = $templates;
		$this->theme_tab   = $theme_tab;
		$this->history_tab = $history_tab;
		$this->missing_tab = $missing_tab;
		$this->preview_tab = $preview_tab;
	}

	/**
	 * URL of the report, optionally on another tab.
	 *
	 * @param array<string, scalar> $args Query arguments: tab, theme, and so on.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'themes.php' ) );
	}

	/**
	 * Redirects to the screen the form came from, or a fallback, with result codes added. Never returns.
	 *
	 * @param array<string, scalar> $args     Result codes for the notice.
	 * @param string                $fallback Where to go when the referrer is not usable.
	 * @param bool                  $use_referer Go back to the screen the form came from. Default true.
	 */
	public static function redirect_back( array $args, string $fallback, bool $use_referer = true ): void {
		$target = $use_referer ? wp_get_referer() : false;
		$target = $target ? remove_query_arg( array( 'aptu_undone', 'aptu_undo_skipped', 'aptu_undo_left', 'aptu_blocked', 'aptu_saved', 'aptu_error', 'aptu_moved', 'aptu_scanned', 'aptu_alert', 'aptu_left', 'aptu_skipped', 'aptu_type', 'aptu_to', 'aptu_batch', 'activated' ), $target ) : $fallback;

		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}

	/**
	 * Loads the script that asks before a destructive form is sent.
	 */
	public static function enqueue_script(): void {
		wp_enqueue_script( 'axle-page-template-usage', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-a11y' ), VERSION, true );
	}

	/**
	 * Adds the menu entry and the form handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_reassign' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Adds a link to the report in the plugin's row on the Plugins screen.
	 *
	 * @param mixed $links Action links.
	 * @return mixed
	 */
	public function add_action_link( $links ) {
		if ( ! is_array( $links ) || ! current_user_can( self::capability() ) ) {
			return $links;
		}

		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Template usage', 'axle-page-template-usage' ) ) );

		return $links;
	}

	/**
	 * Most posts one click in wp-admin moves. WP-CLI has no limit.
	 */
	private const MOVE_LIMIT = 500;

	/**
	 * Items one move handles, from the screen or an ability.
	 *
	 * @param string $post_type Post type being moved.
	 * @return int
	 */
	public static function move_limit( string $post_type ): int {
		/**
		 * Filters how many items one Move click, or one reassign ability run, handles.
		 *
		 * @param int    $limit     Default 500.
		 * @param string $post_type Post type being moved.
		 */
		return max( 1, (int) apply_filters( 'axle_page_template_usage_move_limit', self::MOVE_LIMIT, $post_type ) );
	}

	/**
	 * Capability needed to open Appearance > Axle Page Template Usage.
	 *
	 * Moving posts also needs edit_posts on the post type, and edit_post on each post.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to open Appearance > Axle Page Template Usage.
		 *
		 * @param string $capability Default edit_pages.
		 */
		return (string) apply_filters( 'axle_page_template_usage_capability', 'edit_pages' );
	}

	/**
	 * Whether the current user may see and change the templates of a post type.
	 *
	 * Needs the capability above, and edit_posts on the post type. Every screen
	 * and ability shows only the post types that pass, so nobody sees the
	 * counts, items or history of a post type they cannot edit.
	 *
	 * @param string $post_type Post type name.
	 * @return bool False for a post type that does not exist.
	 */
	public static function can_access( string $post_type ): bool {
		$object  = '' !== $post_type ? get_post_type_object( $post_type ) : null;
		$allowed = $object instanceof \WP_Post_Type && current_user_can( self::capability() ) && current_user_can( $object->cap->edit_posts );

		/**
		 * Filters whether the current user may see and change the templates of a post type.
		 *
		 * @param bool   $allowed   Default: the Axle Page Template Usage capability, and edit_posts on the post type.
		 * @param string $post_type Post type name.
		 */
		return (bool) apply_filters( 'axle_page_template_usage_can_access_post_type', $allowed, $post_type );
	}

	/**
	 * Post types the current user may see and change the templates of.
	 *
	 * @return string[] Post type names.
	 */
	public static function accessible_types(): array {
		return array_values( array_filter( array_map( 'strval', get_post_types() ), array( self::class, 'can_access' ) ) );
	}

	/**
	 * Whether the current user may check a theme before switching, and plan its templates.
	 *
	 * The check lists installed themes, so it needs the theme check capability
	 * (switch_themes by default) as well as the Axle Page Template Usage one.
	 *
	 * @return bool
	 */
	public static function can_check_themes(): bool {
		return current_user_can( self::capability() ) && current_user_can( ThemeCheck::capability() );
	}

	/**
	 * Registers Appearance > Axle Page Template Usage.
	 */
	public function add_page(): void {
		$hook = add_theme_page(
			__( 'Axle Page Template Usage', 'axle-page-template-usage' ),
			__( 'Axle Page Template Usage', 'axle-page-template-usage' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_styles' ) );
			add_action( "load-{$hook}", array( $this, 'add_help' ) );
		}
	}

	/**
	 * Adds the Help tabs: an overview, and one tab per job of this screen.
	 */
	public function add_help(): void {
		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-page-template-usage' ),
					'content' => '<p>' . esc_html__( 'This screen shows which template each page uses. The Templates tab counts the pages on every template. Missing finds pages whose template is gone. Check a theme tells you what a theme switch would break. History lists every template change.', 'axle-page-template-usage' ) . '</p>',
				),
				array(
					'id'      => 'templates',
					'title'   => __( 'Templates', 'axle-page-template-usage' ),
					'content' => '<p>' . esc_html__( 'Each row is a template the theme offers, with the number of pages that use it. Use Move to put every page on one template onto another. At most 500 pages move per click, and the notice offers Undo.', 'axle-page-template-usage' ) . '</p>',
				),
				array(
					'id'      => 'missing',
					'title'   => __( 'Missing templates', 'axle-page-template-usage' ),
					'content' => '<p>' . esc_html__( 'A theme update or a deploy can remove a template that pages still use. WordPress then shows the default layout and says nothing. The Missing tab lists those templates, suggests the closest one the theme still has, and previews the move before it runs. Nothing changes by itself.', 'axle-page-template-usage' ) . '</p><p>' . esc_html__( 'The plugin looks after every theme update and theme switch, and once a day. You can also turn on an email.', 'axle-page-template-usage' ) . '</p>',
				),
				array(
					'id'      => 'preview',
					'title'   => __( 'Preview', 'axle-page-template-usage' ),
					'content' => '<p>' . esc_html__( 'Use "Preview with..." in a page row to open the page with another template. Only you see it, nothing is saved, and the bar at the bottom has "Use this template" and "Close". You can only preview pages you can edit.', 'axle-page-template-usage' ) . '</p>',
				),
				array(
					'id'      => 'theme',
					'title'   => __( 'Check a theme', 'axle-page-template-usage' ),
					'content' => '<p>' . esc_html__( 'Pick a theme that is installed but not active. The check lists the pages that would lose their template. Save a plan to choose a replacement for each, and the pages move when you activate the theme.', 'axle-page-template-usage' ) . '</p>',
				),
				array(
					'id'      => 'history',
					'title'   => __( 'History', 'axle-page-template-usage' ),
					'content' => '<p>' . esc_html__( 'Every template change is listed with who made it, when, and where it came from. Undo a single change, or a whole batch. A change cannot be undone once the page has another template.', 'axle-page-template-usage' ) . '</p>',
				),
			)
		);
	}

	/**
	 * Report styles.
	 */
	public function enqueue_styles(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'axle-page-template-usage', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		self::enqueue_script();
	}

	/**
	 * Prints the report.
	 */
	public function render(): void {
		$tab = $this->current_tab();
		?>
		<div class="wrap axle-ui aptu-report">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Axle Page Template Usage', 'axle-page-template-usage' ); ?></h1>
			<hr class="wp-header-end">

			<?php $this->render_tabs( $tab ); ?>
			<?php $this->render_notice(); ?>

			<?php
			if ( 'theme' === $tab && $this->theme_tab ) {
				$this->theme_tab->render();
			} elseif ( 'history' === $tab && $this->history_tab ) {
				$this->history_tab->render();
			} elseif ( 'missing' === $tab && $this->missing_tab ) {
				$this->missing_tab->render();
			} elseif ( 'preview' === $tab && $this->preview_tab ) {
				$this->preview_tab->render();
			} else {
				$this->render_templates();
			}
			?>

			<?php AxleUi::footer(); ?>
		</div>
		<?php
	}

	/**
	 * Tab shown, from the URL.
	 *
	 * @return string templates, missing, preview, theme or history.
	 */
	private function current_tab(): string {
		// Read-only choice of what to show.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 'theme' === $tab && ! self::can_check_themes() ) {
			return 'templates';
		}

		return in_array( $tab, array( 'theme', 'history', 'missing', 'preview' ), true ) ? $tab : 'templates';
	}

	/**
	 * Prints the tab links. One menu item, several views.
	 *
	 * @param string $current Current tab.
	 */
	private function render_tabs( string $current ): void {
		$tabs = array(
			'templates' => __( 'Templates', 'axle-page-template-usage' ),
			'missing'   => __( 'Missing', 'axle-page-template-usage' ),
			'theme'     => __( 'Check a theme', 'axle-page-template-usage' ),
			'history'   => __( 'History', 'axle-page-template-usage' ),
		);
		if ( ! self::can_check_themes() ) {
			unset( $tabs['theme'] );
		}
		if ( 'preview' === $current ) {
			$tabs['preview'] = __( 'Preview', 'axle-page-template-usage' );
		}
		$missing = $this->missing_tab ? $this->missing_tab->count() : 0;
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Axle Page Template Usage views', 'axle-page-template-usage' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( 'templates' === $key ? self::url() : self::url( array( 'tab' => $key ) ) ); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?>
				<?php
				if ( 'missing' === $key && $missing ) :
					?>
					<span class="axle-badge axle-badge--error"><?php echo esc_html( number_format_i18n( $missing ) ); ?></span><?php endif; ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Prints the template usage of the active theme.
	 */
	private function render_templates(): void {
		$all_types  = $this->templates->get_post_types();
		$post_types = array_values(
			array_filter(
				$all_types,
				static function ( \WP_Post_Type $post_type ): bool {
					return self::can_access( $post_type->name );
				}
			)
		);
		?>
			<p class="axle-intro aptu-report__intro">
				<?php esc_html_e( 'Every template your theme offers, how many items use it, and items that point at a template the theme no longer has.', 'axle-page-template-usage' ); ?>
			</p>

			<?php if ( ! $all_types ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'The active theme has no templates to choose from. When it offers some, they will show up here with how many items use each one.', 'axle-page-template-usage' ); ?></p>
				</div>
			<?php elseif ( ! $post_types ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'You are not allowed to edit any of the post types that use templates.', 'axle-page-template-usage' ); ?></p>
				</div>
			<?php endif; ?>

			<?php foreach ( $post_types as $post_type ) : ?>
				<?php $this->render_post_type( $post_type ); ?>
			<?php endforeach; ?>
		<?php
	}

	/**
	 * Prints the summary and table for one post type the user may edit.
	 *
	 * @param \WP_Post_Type $post_type Post type.
	 */
	private function render_post_type( \WP_Post_Type $post_type ): void {
		$rows        = $this->templates->get_report( $post_type->name );
		$templates   = $this->templates->get_templates( $post_type->name );
		$list_column = new ListColumn( $this->templates );
		$unused      = count( wp_list_filter( $rows, array( 'status' => 'unused' ) ) );
		$missing     = array_sum( wp_list_pluck( wp_list_filter( $rows, array( 'status' => 'missing' ) ), 'count' ) );
		$tones       = array(
			'default' => 'neutral',
			'used'    => 'good',
			'unused'  => 'warning',
			'missing' => 'error',
		);
		$labels      = array(
			'default' => __( 'Default', 'axle-page-template-usage' ),
			'used'    => __( 'In use', 'axle-page-template-usage' ),
			'unused'  => __( 'Unused', 'axle-page-template-usage' ),
			'missing' => __( 'Missing', 'axle-page-template-usage' ),
		);
		?>
		<section class="axle-card aptu-report__section" aria-labelledby="tuc-<?php echo esc_attr( $post_type->name ); ?>">
			<h2 class="axle-card__title" id="tuc-<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->name ); ?></h2>

			<div class="axle-stats">
				<?php /* translators: label under the number of templates. */ ?>
				<?php $this->stat( count( $templates ), _n( 'template', 'templates', count( $templates ), 'axle-page-template-usage' ), '' ); ?>
				<?php /* translators: label under the number of unused templates. */ ?>
				<?php $this->stat( $unused, __( 'unused', 'axle-page-template-usage' ), $unused ? 'warning' : '' ); ?>
				<?php
				if ( 'page' === $post_type->name ) {
					/* translators: label under the number of pages on missing templates. */
					$missing_label = _n( 'page on a missing template', 'pages on missing templates', $missing, 'axle-page-template-usage' );
				} elseif ( 'post' === $post_type->name ) {
					/* translators: label under the number of posts on missing templates. */
					$missing_label = _n( 'post on a missing template', 'posts on missing templates', $missing, 'axle-page-template-usage' );
				} else {
					/* translators: label under the number of items on missing templates. */
					$missing_label = _n( 'item on a missing template', 'items on missing templates', $missing, 'axle-page-template-usage' );
				}
				$this->stat( $missing, $missing_label, $missing ? 'error' : '' );
				?>
			</div>

			<table class="widefat striped aptu-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Template', 'axle-page-template-usage' ); ?></th>
						<th scope="col" class="num"><?php echo esc_html( $post_type->labels->name ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'axle-page-template-usage' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Move everything to', 'axle-page-template-usage' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr class="aptu-row aptu-row--<?php echo esc_attr( $row['status'] ); ?>" data-template="<?php echo esc_attr( $row['slug'] ); ?>">
							<td>
								<strong><?php echo esc_html( $row['name'] ); ?></strong>
								<?php if ( 'default' !== $row['status'] && 'missing' !== $row['status'] ) : ?>
									<code class="aptu-slug"><?php echo esc_html( $row['slug'] ); ?></code>
								<?php endif; ?>
							</td>
							<td class="num">
								<?php if ( $row['count'] ) : ?>
									<a href="<?php echo esc_url( $list_column->filter_url( $post_type->name, $row['slug'] ) ); ?>"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></a>
								<?php else : ?>
									0
								<?php endif; ?>
							</td>
							<td><span class="axle-badge axle-badge--<?php echo esc_attr( $tones[ $row['status'] ] ); ?>"><?php echo esc_html( $labels[ $row['status'] ] ); ?></span></td>
							<td>
								<?php if ( $row['count'] ) : ?>
									<?php $this->render_reassign_form( $post_type->name, $row['slug'], $templates ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Prints one summary number with its label.
	 *
	 * @param int    $number Number.
	 * @param string $label  Translated label.
	 * @param string $tone   Tone: good, warning, error, info, or empty for none.
	 */
	private function stat( int $number, string $label, string $tone ): void {
		printf(
			'<div class="axle-stat%1$s"><span class="axle-stat__value">%2$s</span><span class="axle-stat__label">%3$s</span></div>',
			$tone ? ' axle-stat--' . esc_attr( $tone ) : '',
			esc_html( number_format_i18n( $number ) ),
			esc_html( $label )
		);
	}

	/**
	 * Prints the "Move everything to" form for one template.
	 *
	 * @param string                $post_type Post type name.
	 * @param string                $from      Template to move away from.
	 * @param array<string, string> $templates Templates to move to.
	 */
	private function render_reassign_form( string $post_type, string $from, array $templates ): void {
		$targets = array( Templates::DEFAULT_TEMPLATE => __( 'Default', 'axle-page-template-usage' ) ) + $templates;
		unset( $targets[ $from ] );
		$field_id = 'aptu-to-' . md5( $post_type . $from );
		$confirm  = sprintf(
			/* translators: 1: number of items at most, 2: template name or file. {to} is replaced with the template chosen in the list, keep it as it is. */
			__( 'This changes the template of up to %1$s items on "%2$s" to "{to}". You can undo it right after, or later from the History tab, as long as the items keep the new template.', 'axle-page-template-usage' ),
			number_format_i18n( self::move_limit( $post_type ) ),
			$this->templates->get_name( $post_type, $from ) ?? $from
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-reassign" data-aptu-confirm="<?php echo esc_attr( $confirm ); ?>" data-aptu-confirm-label="<?php esc_attr_e( 'Move the items', 'axle-page-template-usage' ); ?>" data-aptu-cancel-label="<?php esc_attr_e( 'Cancel', 'axle-page-template-usage' ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
			<input type="hidden" name="from" value="<?php echo esc_attr( $from ); ?>">
			<?php /* translators: %s: template name or file. */ ?>
			<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( sprintf( __( 'New template for everything on %s', 'axle-page-template-usage' ), $this->templates->get_name( $post_type, $from ) ?? $from ) ); ?></label>
			<select name="to" id="<?php echo esc_attr( $field_id ); ?>">
				<?php foreach ( $targets as $slug => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $slug ); ?>"><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button" aria-describedby="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Move', 'axle-page-template-usage' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Prints the result of the last move.
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		if ( isset( $_GET['aptu_scanned'] ) || isset( $_GET['aptu_alert'] ) ) {
			$this->render_missing_notice();
			return;
		}

		if ( isset( $_GET['aptu_undone'] ) || isset( $_GET['aptu_blocked'] ) || isset( $_GET['aptu_saved'] ) ) {
			$this->render_result_notice();
			return;
		}

		if ( isset( $_GET['aptu_error'] ) ) {
			// Only known codes, so a crafted link cannot put its own text in a notice.
			$errors = array(
				'unknown_template' => __( 'The theme does not have that template.', 'axle-page-template-usage' ),
				'mapping'          => __( 'The plan was not saved: the theme does not have one of the templates you chose.', 'axle-page-template-usage' ),
			);
			$code   = sanitize_key( wp_unslash( $_GET['aptu_error'] ) );
			printf( '<div class="notice notice-error aptu-live"><p>%s</p></div>', esc_html( $errors[ $code ] ?? __( 'The templates could not be changed.', 'axle-page-template-usage' ) ) );
			return;
		}

		if ( ! isset( $_GET['aptu_moved'] ) ) {
			return;
		}

		$moved   = absint( $_GET['aptu_moved'] );
		$left    = isset( $_GET['aptu_left'] ) ? absint( $_GET['aptu_left'] ) : 0;
		$skipped = isset( $_GET['aptu_skipped'] ) ? absint( $_GET['aptu_skipped'] ) : 0;
		$type    = isset( $_GET['aptu_type'] ) ? sanitize_key( wp_unslash( $_GET['aptu_type'] ) ) : 'page';
		$to      = isset( $_GET['aptu_to'] ) ? sanitize_text_field( wp_unslash( $_GET['aptu_to'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$lines = array(
			sprintf(
				/* translators: 1: number of items, 2: template name. */
				_n( 'Moved %1$s item to "%2$s".', 'Moved %1$s items to "%2$s".', $moved, 'axle-page-template-usage' ),
				number_format_i18n( $moved ),
				(string) ( $this->templates->get_name( $type, $to ) ?? $to )
			),
		);
		if ( $skipped ) {
			/* translators: %s: number of items. */
			$lines[] = sprintf( _n( 'Skipped %s item you are not allowed to edit.', 'Skipped %s items you are not allowed to edit.', $skipped, 'axle-page-template-usage' ), number_format_i18n( $skipped ) );
		}
		if ( $left ) {
			/* translators: %s: number of items. */
			$lines[] = sprintf( _n( '%s more is left. Click Move again, or run wp axle-page-template-usage reassign for all of them at once.', '%s more are left. Click Move again, or run wp axle-page-template-usage reassign for all of them at once.', $left, 'axle-page-template-usage' ), number_format_i18n( $left ) );
		}

		$batch = isset( $_GET['aptu_batch'] ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_GET['aptu_batch'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="notice notice-success is-dismissible aptu-live"><p>' . esc_html( implode( ' ', $lines ) ) . '</p>';
		if ( $moved && $batch && $this->history_tab ) {
			echo '<p class="aptu-switch-notice__actions">';
			HistoryTab::render_undo_batch_form( (string) $batch, $moved );
			printf( ' <a class="button aptu-notice-link" href="%s">%s</a>', esc_url( HistoryTab::url( array( 'batch' => $batch ) ) ), esc_html__( 'See what changed', 'axle-page-template-usage' ) );
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Prints the result of a check from the Missing tab, or of saving the email settings.
	 */
	private function render_missing_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		if ( isset( $_GET['aptu_alert'] ) ) {
			$messages = array(
				'on'        => __( 'Saved. You get an email when a template goes missing.', 'axle-page-template-usage' ),
				'off'       => __( 'Saved. No email is sent.', 'axle-page-template-usage' ),
				'bad_email' => __( 'Nothing was saved: that email address is not valid. Check it and save again.', 'axle-page-template-usage' ),
			);
			$code     = sanitize_key( wp_unslash( $_GET['aptu_alert'] ) );
			printf(
				'<div class="notice notice-%1$s is-dismissible aptu-live"><p>%2$s</p></div>',
				'bad_email' === $code ? 'error' : 'success',
				esc_html( $messages[ $code ] ?? __( 'Saved.', 'axle-page-template-usage' ) )
			);
			return;
		}

		$count = isset( $_GET['aptu_scanned'] ) ? absint( $_GET['aptu_scanned'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = $count
			/* translators: %s: number of items. */
			? sprintf( _n( 'Checked. %s item uses a template the theme no longer has.', 'Checked. %s items use a template the theme no longer has.', $count, 'axle-page-template-usage' ), number_format_i18n( $count ) )
			: __( 'Checked. Every page uses a template the theme has.', 'axle-page-template-usage' );

		printf( '<div class="notice notice-%1$s is-dismissible aptu-live"><p>%2$s</p></div>', $count ? 'warning' : 'success', esc_html( $message ) );
	}

	/**
	 * Prints the result of an undo or of saving a plan.
	 */
	private function render_result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		if ( isset( $_GET['aptu_saved'] ) ) {
			$message = absint( $_GET['aptu_saved'] )
				? __( 'The plan is saved. The items move when you activate the theme, and you get an Undo.', 'axle-page-template-usage' )
				: __( 'No plan is saved for this theme.', 'axle-page-template-usage' );
			printf( '<div class="notice notice-success is-dismissible aptu-live"><p>%s</p></div>', esc_html( $message ) );
			return;
		}

		if ( isset( $_GET['aptu_blocked'] ) ) {
			// Only known codes, so a crafted link cannot put its own text in a notice.
			$reasons = array(
				'changed'     => __( 'Nothing was undone: the page uses another template now, so this change can no longer be undone.', 'axle-page-template-usage' ),
				'post_gone'   => __( 'Nothing was undone: the page no longer exists.', 'axle-page-template-usage' ),
				'cannot_edit' => __( 'Nothing was undone: you are not allowed to edit this page.', 'axle-page-template-usage' ),
				'undone'      => __( 'Nothing was undone: this change was undone already.', 'axle-page-template-usage' ),
				'gone'        => __( 'Nothing was undone: the history no longer has that entry.', 'axle-page-template-usage' ),
			);
			$code    = sanitize_key( wp_unslash( $_GET['aptu_blocked'] ) );
			printf( '<div class="notice notice-warning is-dismissible aptu-live"><p>%s</p></div>', esc_html( $reasons[ $code ] ?? __( 'Nothing was undone.', 'axle-page-template-usage' ) ) );
			return;
		}

		$undone  = isset( $_GET['aptu_undone'] ) ? absint( $_GET['aptu_undone'] ) : 0;
		$skipped = isset( $_GET['aptu_undo_skipped'] ) ? absint( $_GET['aptu_undo_skipped'] ) : 0;
		$left    = isset( $_GET['aptu_undo_left'] ) ? absint( $_GET['aptu_undo_left'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/* translators: %s: number of pages. */
		$lines = array( sprintf( _n( 'Undone: %s page is back on its earlier template.', 'Undone: %s pages are back on their earlier templates.', $undone, 'axle-page-template-usage' ), number_format_i18n( $undone ) ) );
		if ( $skipped ) {
			/* translators: %s: number of pages. */
			$lines[] = sprintf( _n( '%s page was left alone because it changed again, or is gone.', '%s pages were left alone because they changed again, or are gone.', $skipped, 'axle-page-template-usage' ), number_format_i18n( $skipped ) );
		}
		if ( $left ) {
			/* translators: %s: number of pages. */
			$lines[] = sprintf( _n( '%s more is left. Click Undo this batch again to continue.', '%s more are left. Click Undo this batch again to continue.', $left, 'axle-page-template-usage' ), number_format_i18n( $left ) );
		}

		printf( '<div class="notice notice-success is-dismissible aptu-live"><p>%s</p></div>', esc_html( implode( ' ', $lines ) ) );
	}

	/**
	 * Moves every post from one template to another.
	 */
	public function handle_reassign(): void {
		check_admin_referer( self::ACTION );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$from      = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to        = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$back      = admin_url( 'themes.php?page=' . self::SLUG );

		if ( ! self::can_access( $post_type ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change templates for these items.', 'axle-page-template-usage' ), 403 );
		}

		// Set by the Missing tab, to come back to it.
		if ( isset( $_POST['return'] ) && 'missing' === sanitize_key( wp_unslash( $_POST['return'] ) ) ) {
			$back = MissingTab::url();
		}

		$result = $this->templates->reassign( $post_type, $from, $to, self::move_limit( $post_type ), true, 'bulk_move' );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'aptu_error', 'unknown_template', $back ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'aptu_moved'   => $result['moved'],
					'aptu_skipped' => $result['skipped'],
					'aptu_left'    => $result['remaining'],
					'aptu_type'    => $post_type,
					'aptu_to'      => rawurlencode( $to ),
					'aptu_batch'   => $result['batch'],
				),
				$back
			)
		);
		exit;
	}
}
