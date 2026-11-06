<?php
/**
 * Appearance > Page Template Usage report.
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
 * Lists every template with its usage, and moves posts off a template in one go.
 */
final class ReportPage implements Service {

	public const SLUG = 'page-template-usage';

	private const ACTION = 'page_template_usage_reassign';

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Sets up the report.
	 *
	 * @param Templates $templates Template data.
	 */
	public function __construct( Templates $templates ) {
		$this->templates = $templates;
	}

	/**
	 * Adds the menu entry and the form handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_reassign' ) );
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
		return max( 1, (int) apply_filters( 'page_template_usage_move_limit', self::MOVE_LIMIT, $post_type ) );
	}

	/**
	 * Capability needed to open Appearance > Page Template Usage.
	 *
	 * Moving posts also needs edit_posts on the post type, and edit_post on each post.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to open Appearance > Page Template Usage.
		 *
		 * @param string $capability Default edit_pages.
		 */
		return (string) apply_filters( 'page_template_usage_capability', 'edit_pages' );
	}

	/**
	 * Registers Appearance > Page Template Usage.
	 */
	public function add_page(): void {
		$hook = add_theme_page(
			__( 'Page Template Usage', 'page-template-usage' ),
			__( 'Page Template Usage', 'page-template-usage' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_styles' ) );
		}
	}

	/**
	 * Report styles.
	 */
	public function enqueue_styles(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'page-template-usage', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
	}

	/**
	 * Prints the report.
	 */
	public function render(): void {
		$post_types = $this->templates->get_post_types();
		?>
		<div class="wrap axle-ui ptu-report">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Page Template Usage', 'page-template-usage' ); ?></h1>
			<hr class="wp-header-end">

			<?php $this->render_notice(); ?>

			<p class="axle-intro ptu-report__intro">
				<?php esc_html_e( 'Every template your theme offers, how many items use it, and items that point at a template the theme no longer has.', 'page-template-usage' ); ?>
			</p>

			<?php if ( ! $post_types ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'The active theme has no templates to choose from. When it offers some, they will show up here with how many items use each one.', 'page-template-usage' ); ?></p>
				</div>
			<?php endif; ?>

			<?php foreach ( $post_types as $post_type ) : ?>
				<?php $this->render_post_type( $post_type ); ?>
			<?php endforeach; ?>

			<?php AxleUi::footer(); ?>
		</div>
		<?php
	}

	/**
	 * Prints the summary and table for one post type.
	 *
	 * @param \WP_Post_Type $post_type Post type.
	 */
	private function render_post_type( \WP_Post_Type $post_type ): void {
		$rows        = $this->templates->get_report( $post_type->name );
		$templates   = $this->templates->get_templates( $post_type->name );
		$can_edit    = current_user_can( $post_type->cap->edit_posts );
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
			'default' => __( 'Default', 'page-template-usage' ),
			'used'    => __( 'In use', 'page-template-usage' ),
			'unused'  => __( 'Unused', 'page-template-usage' ),
			'missing' => __( 'Missing', 'page-template-usage' ),
		);
		?>
		<section class="axle-card ptu-report__section" aria-labelledby="tuc-<?php echo esc_attr( $post_type->name ); ?>">
			<h2 class="axle-card__title" id="tuc-<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->name ); ?></h2>

			<div class="axle-stats">
				<?php /* translators: label under the number of templates. */ ?>
				<?php $this->stat( count( $templates ), _n( 'template', 'templates', count( $templates ), 'page-template-usage' ), '' ); ?>
				<?php /* translators: label under the number of unused templates. */ ?>
				<?php $this->stat( $unused, __( 'unused', 'page-template-usage' ), $unused ? 'warning' : '' ); ?>
				<?php
				if ( 'page' === $post_type->name ) {
					/* translators: label under the number of pages on missing templates. */
					$missing_label = _n( 'page on a missing template', 'pages on missing templates', $missing, 'page-template-usage' );
				} elseif ( 'post' === $post_type->name ) {
					/* translators: label under the number of posts on missing templates. */
					$missing_label = _n( 'post on a missing template', 'posts on missing templates', $missing, 'page-template-usage' );
				} else {
					/* translators: label under the number of items on missing templates. */
					$missing_label = _n( 'item on a missing template', 'items on missing templates', $missing, 'page-template-usage' );
				}
				$this->stat( $missing, $missing_label, $missing ? 'error' : '' );
				?>
			</div>

			<table class="widefat striped ptu-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Template', 'page-template-usage' ); ?></th>
						<th scope="col" class="num"><?php echo esc_html( $post_type->labels->name ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'page-template-usage' ); ?></th>
						<?php if ( $can_edit ) : ?>
							<th scope="col"><?php esc_html_e( 'Move everything to', 'page-template-usage' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr class="ptu-row ptu-row--<?php echo esc_attr( $row['status'] ); ?>" data-template="<?php echo esc_attr( $row['slug'] ); ?>">
							<td>
								<strong><?php echo esc_html( $row['name'] ); ?></strong>
								<?php if ( 'default' !== $row['status'] && 'missing' !== $row['status'] ) : ?>
									<code class="ptu-slug"><?php echo esc_html( $row['slug'] ); ?></code>
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
							<?php if ( $can_edit ) : ?>
								<td>
									<?php if ( $row['count'] ) : ?>
										<?php $this->render_reassign_form( $post_type->name, $row['slug'], $templates ); ?>
									<?php endif; ?>
								</td>
							<?php endif; ?>
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
		$targets = array( Templates::DEFAULT_TEMPLATE => __( 'Default', 'page-template-usage' ) ) + $templates;
		unset( $targets[ $from ] );
		$field_id = 'ptu-to-' . md5( $post_type . $from );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptu-reassign">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
			<input type="hidden" name="from" value="<?php echo esc_attr( $from ); ?>">
			<?php /* translators: %s: template name or file. */ ?>
			<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( sprintf( __( 'New template for everything on %s', 'page-template-usage' ), $this->templates->get_name( $post_type, $from ) ?? $from ) ); ?></label>
			<select name="to" id="<?php echo esc_attr( $field_id ); ?>">
				<?php foreach ( $targets as $slug => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $slug ); ?>"><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button" aria-describedby="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Move', 'page-template-usage' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Prints the result of the last move.
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		if ( isset( $_GET['ptu_error'] ) ) {
			// Only known codes, so a crafted link cannot put its own text in a notice.
			$errors = array(
				'unknown_template' => __( 'The theme does not have that template.', 'page-template-usage' ),
			);
			$code   = sanitize_key( wp_unslash( $_GET['ptu_error'] ) );
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $errors[ $code ] ?? __( 'The templates could not be changed.', 'page-template-usage' ) ) );
			return;
		}

		if ( ! isset( $_GET['ptu_moved'] ) ) {
			return;
		}

		$moved   = absint( $_GET['ptu_moved'] );
		$left    = isset( $_GET['ptu_left'] ) ? absint( $_GET['ptu_left'] ) : 0;
		$skipped = isset( $_GET['ptu_skipped'] ) ? absint( $_GET['ptu_skipped'] ) : 0;
		$type    = isset( $_GET['ptu_type'] ) ? sanitize_key( wp_unslash( $_GET['ptu_type'] ) ) : 'page';
		$to      = isset( $_GET['ptu_to'] ) ? sanitize_text_field( wp_unslash( $_GET['ptu_to'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$lines = array(
			sprintf(
				/* translators: 1: number of items, 2: template name. */
				_n( 'Moved %1$s item to "%2$s".', 'Moved %1$s items to "%2$s".', $moved, 'page-template-usage' ),
				number_format_i18n( $moved ),
				(string) ( $this->templates->get_name( $type, $to ) ?? $to )
			),
		);
		if ( $skipped ) {
			/* translators: %s: number of items. */
			$lines[] = sprintf( _n( 'Skipped %s item you are not allowed to edit.', 'Skipped %s items you are not allowed to edit.', $skipped, 'page-template-usage' ), number_format_i18n( $skipped ) );
		}
		if ( $left ) {
			/* translators: %s: number of items. */
			$lines[] = sprintf( _n( '%s more is left. Click Move again, or run wp page-template-usage reassign for all of them at once.', '%s more are left. Click Move again, or run wp page-template-usage reassign for all of them at once.', $left, 'page-template-usage' ), number_format_i18n( $left ) );
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( implode( ' ', $lines ) ) );
	}

	/**
	 * Moves every post from one template to another.
	 */
	public function handle_reassign(): void {
		check_admin_referer( self::ACTION );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$from      = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to        = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$object    = get_post_type_object( $post_type );
		$back      = admin_url( 'themes.php?page=' . self::SLUG );

		if ( ! $object || ! current_user_can( self::capability() ) || ! current_user_can( $object->cap->edit_posts ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change templates for these items.', 'page-template-usage' ), 403 );
		}

		$result = $this->templates->reassign( $post_type, $from, $to, self::move_limit( $post_type ), true );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'ptu_error', 'unknown_template', $back ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'ptu_moved'   => $result['moved'],
					'ptu_skipped' => $result['skipped'],
					'ptu_left'    => $result['remaining'],
					'ptu_type'    => $post_type,
					'ptu_to'      => rawurlencode( $to ),
				),
				$back
			)
		);
		exit;
	}
}
