<?php
/**
 * The Missing tab, and the notice that points to it.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Admin;

use AryanX\Axle\PageTemplateUsage\Missing;
use AryanX\Axle\PageTemplateUsage\Preview;
use AryanX\Axle\PageTemplateUsage\Service;
use AryanX\Axle\PageTemplateUsage\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the templates pages still point at but the theme lost, suggests a
 * replacement, previews the move and runs it through the history.
 */
final class MissingTab implements Service {

	public const ACTION_SCAN    = 'axle_page_template_usage_scan_now';
	public const ACTION_ALERT   = 'axle_page_template_usage_save_alert';
	public const ACTION_DISMISS = 'axle_page_template_usage_dismiss_missing';

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

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
	 * Sets up the tab.
	 *
	 * @param Templates $templates Template data.
	 * @param Missing   $missing   Finder of missing templates.
	 * @param Preview   $preview   Front end preview.
	 */
	public function __construct( Templates $templates, Missing $missing, Preview $preview ) {
		$this->templates = $templates;
		$this->missing   = $missing;
		$this->preview   = $preview;
	}

	/**
	 * Registers the handlers and the notice.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SCAN, array( $this, 'handle_scan' ) );
		add_action( 'admin_post_' . self::ACTION_ALERT, array( $this, 'handle_alert' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * URL of the tab, optionally with a move to preview.
	 *
	 * @param array<string, scalar> $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return ReportPage::url( array_merge( array( 'tab' => 'missing' ), $args ) );
	}

	/**
	 * Items on missing templates in the post types the user can edit.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->missing->count_missing( ReportPage::accessible_types() );
	}

	/**
	 * A short code for the set of missing templates the user sees, so a dismissed
	 * notice comes back when something new goes missing.
	 *
	 * @param array<int, array{post_type: string, slug: string}> $rows Missing templates.
	 * @return string
	 */
	private function signature( array $rows ): string {
		$keys = array();
		foreach ( $rows as $row ) {
			$keys[] = $row['post_type'] . ':' . $row['slug'];
		}
		sort( $keys );

		return md5( implode( '|', $keys ) );
	}

	/**
	 * Warns on a few admin screens when pages use a template the theme lost.
	 */
	public function render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$types  = ReportPage::accessible_types();
		$state  = $this->missing->get_state();

		// The scan keeps a record, so screens without a problem cost one option read.
		if ( ! $screen || ! $types || empty( $state['missing'] ) ) {
			return;
		}

		/**
		 * Filters the admin screens that show the missing template notice.
		 *
		 * The list screens of each post type have their own notice.
		 *
		 * @param string[] $screen_ids Screen IDs. Default: the Dashboard, Themes and Plugins.
		 */
		$screen_ids = (array) apply_filters( 'axle_page_template_usage_missing_notice_screens', array( 'dashboard', 'themes', 'plugins' ) );
		if ( ! in_array( $screen->id, $screen_ids, true ) ) {
			return;
		}

		$rows = $this->missing->get_missing( $types );
		if ( ! $rows ) {
			return;
		}

		$signature = $this->signature( $rows );
		if ( get_user_meta( get_current_user_id(), Missing::META_DISMISS, true ) === $signature ) {
			return;
		}

		$total   = (int) array_sum( wp_list_pluck( $rows, 'count' ) );
		$message = sprintf(
			/* translators: 1: number of items, 2: number of templates. */
			_n(
				'%1$s item uses a template the theme no longer has, so it shows the default layout (%2$s template missing).',
				'%1$s items use a template the theme no longer has, so they show the default layout (%2$s templates missing).',
				$total,
				'axle-page-template-usage'
			),
			number_format_i18n( $total ),
			number_format_i18n( count( $rows ) )
		);

		/**
		 * Filters the sentence of the missing template notice.
		 *
		 * @param string               $message Text.
		 * @param array<int, array>    $rows    Missing templates the user may see.
		 * @param int                  $total   Items on them.
		 */
		$message = (string) apply_filters( 'axle_page_template_usage_missing_notice_message', $message, $rows, $total );

		printf(
			'<div class="notice notice-warning aptu-missing-alert"><p>%1$s</p><p><a class="button" href="%2$s">%3$s</a> <a class="button-link" href="%4$s">%5$s</a></p></div>',
			esc_html( $message ),
			esc_url( self::url() ),
			esc_html__( 'Review the missing templates', 'axle-page-template-usage' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ), self::ACTION_DISMISS ) ),
			esc_html__( 'Dismiss until something new goes missing', 'axle-page-template-usage' )
		);
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$types = ReportPage::accessible_types();
		$state = $this->missing->get_state();
		$rows  = $this->missing->get_missing( $types );
		$move  = $this->requested_move( $types );
		?>
		<p class="axle-intro aptu-report__intro">
			<?php esc_html_e( 'Pages that use a template the active theme no longer has. WordPress shows them with the default layout. Nothing is changed until you move them, and a move can be undone.', 'axle-page-template-usage' ); ?>
		</p>

		<div class="axle-toolbar">
			<span class="aptu-checked">
				<?php
				esc_html_e( 'Last checked:', 'axle-page-template-usage' );
				echo ' ';
				echo AxleUi::relative_time( (int) ( $state['checked'] ?? 0 ), __( 'not yet', 'axle-page-template-usage' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi.
				?>
			</span>
			<span class="axle-toolbar__end">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-inline-form">
					<?php wp_nonce_field( self::ACTION_SCAN ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SCAN ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Check now', 'axle-page-template-usage' ); ?></button>
				</form>
			</span>
		</div>

		<?php
		if ( $move ) {
			$this->render_move_preview( $move );
		}

		if ( ! $types ) :
			?>
			<div class="axle-empty">
				<p><?php esc_html_e( 'You are not allowed to edit any of the post types that use templates.', 'axle-page-template-usage' ); ?></p>
			</div>
		<?php elseif ( ! $rows ) : ?>
			<div class="axle-empty aptu-missing-empty">
				<p><?php esc_html_e( 'No page uses a template the theme has lost. After a theme update or a deploy that removes a template, the pages still pointing at it show up here with a suggested replacement.', 'axle-page-template-usage' ); ?></p>
			</div>
		<?php else : ?>
			<?php foreach ( $this->group( $rows ) as $type => $items ) : ?>
				<?php $this->render_type( (string) $type, $items ); ?>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php $this->render_alert_settings(); ?>
		<?php
	}

	/**
	 * Missing templates grouped by post type.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from Missing::get_missing().
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function group( array $rows ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ $row['post_type'] ][] = $row;
		}

		return $groups;
	}

	/**
	 * Prints the table of one post type.
	 *
	 * @param string                           $type  Post type.
	 * @param array<int, array<string, mixed>> $items Missing templates of the type.
	 */
	private function render_type( string $type, array $items ): void {
		$object    = get_post_type_object( $type );
		$templates = array( Templates::DEFAULT_TEMPLATE => __( 'Default', 'axle-page-template-usage' ) ) + $this->templates->get_templates( $type );
		$list      = new ListColumn( $this->templates );
		$title_id  = 'aptu-missing-' . $type;
		?>
		<section class="axle-card aptu-missing-section" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
			<h2 class="axle-card__title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $object ? $object->labels->name : $type ); ?></h2>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php /* translators: %s: post type name. */ echo esc_attr( sprintf( __( 'Missing templates of %s', 'axle-page-template-usage' ), $object ? $object->labels->name : $type ) ); ?>">
				<table class="widefat striped aptu-missing-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Missing template', 'axle-page-template-usage' ); ?></th>
							<th scope="col" class="num"><?php echo esc_html( $object ? $object->labels->name : $type ); ?></th>
							<th scope="col"><?php esc_html_e( 'Missing since', 'axle-page-template-usage' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Suggested instead', 'axle-page-template-usage' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Move these pages', 'axle-page-template-usage' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $item ) : ?>
							<?php $field_id = 'aptu-mv-' . md5( $type . $item['slug'] ); ?>
							<tr class="aptu-missing-row" data-template="<?php echo esc_attr( $item['slug'] ); ?>">
								<td>
									<?php if ( $item['name'] !== $item['slug'] ) : ?>
										<strong><?php echo esc_html( $item['name'] ); ?></strong><br>
									<?php endif; ?>
									<code class="aptu-slug"><?php echo esc_html( $item['slug'] ); ?></code>
								</td>
								<td class="num"><a href="<?php echo esc_url( $list->filter_url( $type, $item['slug'] ) ); ?>"><?php echo esc_html( number_format_i18n( $item['count'] ) ); ?></a></td>
								<td><?php echo AxleUi::relative_time( (int) $item['since'], __( 'Not known', 'axle-page-template-usage' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi. ?></td>
								<td>
									<strong><?php echo esc_html( $item['suggestion']['name'] ); ?></strong><br>
									<span class="axle-badge axle-badge--info"><?php echo esc_html( $item['suggestion']['reason_label'] ); ?></span>
								</td>
								<td>
									<form method="get" action="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>" class="aptu-reassign">
										<input type="hidden" name="page" value="<?php echo esc_attr( ReportPage::SLUG ); ?>">
										<input type="hidden" name="tab" value="missing">
										<input type="hidden" name="mv_type" value="<?php echo esc_attr( $type ); ?>">
										<input type="hidden" name="mv_from" value="<?php echo esc_attr( $item['slug'] ); ?>">
										<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>">
											<?php
											/* translators: %s: template file. */
											echo esc_html( sprintf( __( 'New template for the pages on %s', 'axle-page-template-usage' ), $item['slug'] ) );
											?>
										</label>
										<select name="mv_to" id="<?php echo esc_attr( $field_id ); ?>">
											<?php foreach ( $templates as $slug => $name ) : ?>
												<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( (string) $slug, $item['suggestion']['template'] ); ?>><?php echo esc_html( $name ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button"><?php esc_html_e( 'Preview the move', 'axle-page-template-usage' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * The move the URL asks to preview, when the user may see it and it makes sense.
	 *
	 * @param string[] $types Post types the user can edit.
	 * @return array{type: string, from: string, to: string}|null
	 */
	private function requested_move( array $types ): ?array {
		// Read-only choice of what to preview. The move itself is a nonce-checked form.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['mv_type'], $_GET['mv_from'], $_GET['mv_to'] ) ) {
			return null;
		}
		$type = sanitize_key( wp_unslash( $_GET['mv_type'] ) );
		$from = sanitize_text_field( wp_unslash( $_GET['mv_from'] ) );
		$to   = sanitize_text_field( wp_unslash( $_GET['mv_to'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$known = $this->templates->get_missing_counts( $type );
		if ( ! in_array( $type, $types, true ) || ! isset( $known[ $from ] ) || ( ! $this->templates->is_default( $to ) && null === $this->templates->get_name( $type, $to ) ) ) {
			return null;
		}

		return array(
			'type' => $type,
			'from' => $from,
			'to'   => $to,
		);
	}

	/**
	 * Prints what a move would change, and the step before it runs.
	 *
	 * @param array{type: string, from: string, to: string} $move Post type and templates.
	 */
	private function render_move_preview( array $move ): void {
		$type    = $move['type'];
		$limit   = ReportPage::move_limit( $type );
		$total   = $this->templates->count_matching( $type, $move['from'] );
		$ids     = $this->templates->ids_on_template( $type, $move['from'], $limit );
		$to_name = $this->templates->is_default( $move['to'] ) ? __( 'Default', 'axle-page-template-usage' ) : (string) $this->templates->get_name( $type, $move['to'] );

		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}

		$editable = array();
		foreach ( $ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) ) {
				$editable[] = $id;
			}
		}

		$moves = count( $editable );
		$left  = max( 0, $total - $moves );

		/**
		 * Filters how many example pages the move preview lists.
		 *
		 * @param int $size Default 10.
		 */
		$size   = max( 0, (int) apply_filters( 'axle_page_template_usage_move_preview_size', 10 ) );
		$sample = array_slice( $editable, 0, $size );
		?>
		<section class="axle-card aptu-move-preview" aria-labelledby="aptu-move-title">
			<h2 class="axle-card__title" id="aptu-move-title"><?php esc_html_e( 'Preview of the move', 'axle-page-template-usage' ); ?></h2>
			<div class="axle-diff aptu-move-diff">
				<del><span class="screen-reader-text"><?php esc_html_e( 'Before:', 'axle-page-template-usage' ); ?></span> <code><?php echo esc_html( $move['from'] ); ?></code></del>
				<ins><span class="screen-reader-text"><?php esc_html_e( 'After:', 'axle-page-template-usage' ); ?></span> <?php echo esc_html( $to_name ); ?></ins>
			</div>

			<?php if ( ! $moves ) : ?>
				<p><?php esc_html_e( 'You are not allowed to edit any of these pages, so nothing would move.', 'axle-page-template-usage' ); ?></p>
			<?php else : ?>
				<?php if ( $sample ) : ?>
					<p><?php esc_html_e( 'Pages that would move, with a preview of each in the new template:', 'axle-page-template-usage' ); ?></p>
					<ul class="aptu-move-sample">
						<?php foreach ( $sample as $id ) : ?>
							<?php $preview = $this->preview->url( $id, $move['to'] ); ?>
							<li>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( '' !== get_the_title( $id ) ? get_the_title( $id ) : __( '(no title)', 'axle-page-template-usage' ) ); ?></a>
								<?php if ( '' !== $preview ) : ?>
									<a class="aptu-preview-link" href="<?php echo esc_url( $preview ); ?>">
										<?php esc_html_e( 'Preview with this template', 'axle-page-template-usage' ); ?>
										<span class="screen-reader-text">
											<?php
											/* translators: %s: page title. */
											echo esc_html( sprintf( __( 'for "%s"', 'axle-page-template-usage' ), get_the_title( $id ) ) );
											?>
										</span>
									</a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $moves > count( $sample ) ) : ?>
						<p class="description">
							<?php
							/* translators: %s: number of pages. */
							echo esc_html( sprintf( _n( 'And %s more page.', 'And %s more pages.', $moves - count( $sample ), 'axle-page-template-usage' ), number_format_i18n( $moves - count( $sample ) ) ) );
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>

				<div class="axle-confirm" role="group" aria-labelledby="aptu-move-confirm">
					<p id="aptu-move-confirm">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of pages, 2: new template name. */
								_n( 'This changes the template of %1$s page to "%2$s".', 'This changes the template of %1$s pages to "%2$s".', $moves, 'axle-page-template-usage' ),
								number_format_i18n( $moves ),
								$to_name
							)
						);
						echo ' ';
						esc_html_e( 'You can undo it right after, or later from the History tab, as long as a page keeps the new template.', 'axle-page-template-usage' );
						if ( $left ) {
							echo ' ';
							echo esc_html(
								sprintf(
									/* translators: %s: number of pages. */
									_n( '%s page stays on the old template: you cannot edit it, or it is past the limit for one move. Move again for the rest.', '%s pages stay on the old template: you cannot edit them, or they are past the limit for one move. Move again for the rest.', $left, 'axle-page-template-usage' ),
									number_format_i18n( $left )
								)
							);
						}
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="axle-actions">
						<?php wp_nonce_field( 'axle_page_template_usage_reassign' ); ?>
						<input type="hidden" name="action" value="axle_page_template_usage_reassign">
						<input type="hidden" name="post_type" value="<?php echo esc_attr( $type ); ?>">
						<input type="hidden" name="from" value="<?php echo esc_attr( $move['from'] ); ?>">
						<input type="hidden" name="to" value="<?php echo esc_attr( $move['to'] ); ?>">
						<input type="hidden" name="return" value="missing">
						<button type="submit" class="button button-primary">
							<?php
							/* translators: %s: number of pages. */
							echo esc_html( sprintf( _n( 'Move %s page', 'Move %s pages', $moves, 'axle-page-template-usage' ), number_format_i18n( $moves ) ) );
							?>
						</button>
						<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'axle-page-template-usage' ); ?></a>
					</form>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints the email alert settings, for people who may change them.
	 */
	private function render_alert_settings(): void {
		if ( ! current_user_can( Missing::alert_capability() ) ) {
			return;
		}

		$alert = $this->missing->get_alert();
		?>
		<section class="axle-card aptu-alert-settings" aria-labelledby="aptu-alert-title">
			<h2 class="axle-card__title" id="aptu-alert-title"><?php esc_html_e( 'Email me when a template goes missing', 'axle-page-template-usage' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Off by default. When a theme update or a deploy removes a template that pages use, one email lists the template names and how many pages use each. Nothing is changed.', 'axle-page-template-usage' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION_ALERT ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ALERT ); ?>">
				<p>
					<label for="aptu-alert-on">
						<input type="checkbox" name="enabled" value="1" id="aptu-alert-on" <?php checked( $alert['enabled'] ); ?>>
						<?php esc_html_e( 'Send the email', 'axle-page-template-usage' ); ?>
					</label>
				</p>
				<p>
					<label for="aptu-alert-to"><?php esc_html_e( 'Send it to', 'axle-page-template-usage' ); ?></label><br>
					<input type="email" class="regular-text" name="to" id="aptu-alert-to" value="<?php echo esc_attr( $alert['to'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" aria-describedby="aptu-alert-to-help">
				</p>
				<p class="description" id="aptu-alert-to-help"><?php esc_html_e( 'Leave it empty to use the site admin email.', 'axle-page-template-usage' ); ?></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save the email settings', 'axle-page-template-usage' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * Looks at the pages now, from the button.
	 */
	public function handle_scan(): void {
		check_admin_referer( self::ACTION_SCAN );

		if ( ! ReportPage::accessible_types() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to check templates.', 'axle-page-template-usage' ), 403 );
		}

		$this->missing->scan( true, 'manual' );

		ReportPage::redirect_back( array( 'aptu_scanned' => $this->count() ), self::url(), false );
	}

	/**
	 * Saves the email alert settings.
	 */
	public function handle_alert(): void {
		check_admin_referer( self::ACTION_ALERT );

		if ( ! current_user_can( Missing::alert_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'axle-page-template-usage' ), 403 );
		}

		$result = $this->missing->save_alert(
			! empty( $_POST['enabled'] ),
			isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : ''
		);

		ReportPage::redirect_back( array( 'aptu_alert' => is_wp_error( $result ) ? 'bad_email' : ( $this->missing->get_alert()['enabled'] ? 'on' : 'off' ) ), self::url(), false );
	}

	/**
	 * Hides the notice for this user until something new goes missing.
	 */
	public function handle_dismiss(): void {
		check_admin_referer( self::ACTION_DISMISS );

		$rows = $this->missing->get_missing( ReportPage::accessible_types() );
		if ( $rows ) {
			update_user_meta( get_current_user_id(), Missing::META_DISMISS, $this->signature( $rows ) );
		}

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}
}
