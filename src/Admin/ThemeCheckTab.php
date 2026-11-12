<?php
/**
 * Appearance > Axle Page Template Usage, theme check tab, and the switch notice.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Admin;

use AryanX\Axle\PageTemplateUsage\Service;
use AryanX\Axle\PageTemplateUsage\Templates;
use AryanX\Axle\PageTemplateUsage\ThemeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * "Check a theme before switching": what would lose its template, what to use
 * instead, and the notice with an Undo after the theme is activated.
 */
final class ThemeCheckTab implements Service {

	public const ACTION_SAVE     = 'axle_page_template_usage_save_mapping';
	public const ACTION_CLEAR    = 'axle_page_template_usage_clear_mapping';
	public const ACTION_CONTINUE = 'axle_page_template_usage_continue_switch';
	public const ACTION_DISMISS  = 'axle_page_template_usage_dismiss_switch';

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
	private ThemeCheck $check;

	/**
	 * Sets up the tab.
	 *
	 * @param Templates  $templates Template data.
	 * @param ThemeCheck $check     Theme check.
	 */
	public function __construct( Templates $templates, ThemeCheck $check ) {
		$this->templates = $templates;
		$this->check     = $check;
	}

	/**
	 * Registers the handlers, the switch notice and the link on Appearance > Themes.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR, array( $this, 'handle_clear' ) );
		add_action( 'admin_post_' . self::ACTION_CONTINUE, array( $this, 'handle_continue' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'render_switch_notice' ) );
		add_filter( 'wp_prepare_themes_for_js', array( $this, 'add_themes_link' ) );
	}

	/**
	 * URL of the theme check tab, with a theme already chosen.
	 *
	 * @param string $stylesheet Theme folder name, or empty for none.
	 * @return string
	 */
	public static function url( string $stylesheet = '' ): string {
		$args = array( 'tab' => 'theme' );
		if ( '' !== $stylesheet ) {
			$args['theme'] = $stylesheet;
		}

		return ReportPage::url( $args );
	}

	/**
	 * Adds "Check this theme's templates first" to the theme details on Appearance > Themes.
	 *
	 * The details overlay prints the description as HTML, so a link there needs no script and no menu item.
	 *
	 * @param mixed $themes Themes as the Themes screen gets them.
	 * @return mixed
	 */
	public function add_themes_link( $themes ) {
		if ( ! is_array( $themes ) || ! ReportPage::can_check_themes() ) {
			return $themes;
		}

		foreach ( $themes as $stylesheet => $data ) {
			if ( ! empty( $data['active'] ) ) {
				continue;
			}

			$themes[ $stylesheet ]['description'] = ( $data['description'] ?? '' ) . sprintf(
				'<p class="aptu-theme-link"><a href="%s">%s</a></p>',
				esc_url( self::url( (string) $stylesheet ) ),
				esc_html__( 'Check this theme\'s templates first', 'axle-page-template-usage' )
			);
		}

		return $themes;
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		// A choice in a read-only form, like a list table filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$chosen = isset( $_GET['theme'] ) ? sanitize_text_field( wp_unslash( $_GET['theme'] ) ) : '';
		$themes = $this->check->get_inactive_themes();
		?>
		<p class="axle-intro aptu-report__intro">
			<?php esc_html_e( 'See which pages would lose their template if you switched to another installed theme, and choose what they use instead. The plan is applied when you activate the theme.', 'axle-page-template-usage' ); ?>
		</p>

		<section class="axle-card" aria-labelledby="aptu-theme-title">
			<h2 class="axle-card__title" id="aptu-theme-title"><?php esc_html_e( 'Check a theme before switching', 'axle-page-template-usage' ); ?></h2>

			<?php if ( ! $themes ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'No other theme is installed. Install the theme you are thinking of, without activating it, and check it here.', 'axle-page-template-usage' ); ?></p>
					<a class="button" href="<?php echo esc_url( admin_url( 'theme-install.php' ) ); ?>"><?php esc_html_e( 'Add a theme', 'axle-page-template-usage' ); ?></a>
				</div>
			<?php else : ?>
				<form method="get" class="aptu-theme-picker">
					<input type="hidden" name="page" value="<?php echo esc_attr( ReportPage::SLUG ); ?>">
					<input type="hidden" name="tab" value="theme">
					<label for="aptu-theme"><?php esc_html_e( 'Theme to check', 'axle-page-template-usage' ); ?></label>
					<select name="theme" id="aptu-theme">
						<option value=""><?php esc_html_e( 'Choose a theme', 'axle-page-template-usage' ); ?></option>
						<?php foreach ( $themes as $stylesheet => $theme ) : ?>
							<option value="<?php echo esc_attr( $stylesheet ); ?>" <?php selected( $chosen, $stylesheet ); ?>><?php echo esc_html( (string) $theme->get( 'Name' ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Check theme', 'axle-page-template-usage' ); ?></button>
				</form>

				<?php
				if ( '' !== $chosen ) {
					$this->render_result( $chosen );
				}
				?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints what a switch to one theme would do to the post types the user can edit, with the mapping form.
	 *
	 * @param string $stylesheet Theme folder name.
	 */
	private function render_result( string $stylesheet ): void {
		$result = $this->check->check( $stylesheet, null, ReportPage::accessible_types() );

		if ( is_wp_error( $result ) ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $result->get_error_message() ) );
			return;
		}

		$name    = $result['theme']['name'];
		$mapping = $this->check->get_mapping( $stylesheet );
		$saved   = $this->check->get_mapping_meta( $stylesheet );
		$types   = array();
		$count   = 0;
		foreach ( $result['groups'] as $rows ) {
			$count += count( $rows );
		}
		?>
		<div class="aptu-theme-result" aria-live="polite">
			<h3>
				<?php
				/* translators: %s: theme name. */
				echo esc_html( sprintf( __( 'Switching to %s', 'axle-page-template-usage' ), $name ) );
				?>
			</h3>

			<?php if ( ! $result['total'] ) : ?>
				<p><span class="axle-badge axle-badge--good"><?php esc_html_e( 'Nothing is lost', 'axle-page-template-usage' ); ?></span></p>
				<p><?php esc_html_e( 'Every page and post that uses a template would find it in this theme. Pages on the default template are not affected.', 'axle-page-template-usage' ); ?></p>
			<?php else : ?>
				<div class="axle-stats">
					<div class="axle-stat axle-stat--warning">
						<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $result['total'] ) ); ?></span>
						<span class="axle-stat__label"><?php echo esc_html( _n( 'item would lose its template', 'items would lose their template', $result['total'], 'axle-page-template-usage' ) ); ?></span>
					</div>
					<div class="axle-stat">
						<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
						<span class="axle-stat__label"><?php echo esc_html( _n( 'template is missing', 'templates are missing', $count, 'axle-page-template-usage' ) ); ?></span>
					</div>
				</div>

				<p>
					<?php
					/* translators: %s: theme name. */
					echo esc_html( sprintf( __( 'WordPress would show these items with the default template of %s. Choose what each one uses instead, and save the plan.', 'axle-page-template-usage' ), $name ) );
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-mapping-form">
					<?php wp_nonce_field( self::ACTION_SAVE ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
					<input type="hidden" name="theme" value="<?php echo esc_attr( $stylesheet ); ?>">

					<?php foreach ( $result['groups'] as $post_type => $rows ) : ?>
						<?php $object = get_post_type_object( (string) $post_type ); ?>
						<?php $types[] = $post_type; ?>
						<h4><?php echo esc_html( $object ? $object->labels->name : (string) $post_type ); ?></h4>
						<table class="widefat striped aptu-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Template now', 'axle-page-template-usage' ); ?></th>
									<th scope="col" class="num"><?php echo esc_html( $object ? $object->labels->name : (string) $post_type ); ?></th>
									<th scope="col"><?php esc_html_e( 'Examples', 'axle-page-template-usage' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Use instead', 'axle-page-template-usage' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<?php $this->render_row( $stylesheet, (string) $post_type, $row, $mapping ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endforeach; ?>

					<p class="aptu-mapping-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save the plan', 'axle-page-template-usage' ); ?></button>
						<span class="description"><?php esc_html_e( 'Saving changes nothing yet. The items move when you activate the theme, and you get an Undo.', 'axle-page-template-usage' ); ?></span>
					</p>
				</form>
			<?php endif; ?>

			<?php if ( $saved ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: 1: theme name, 2: when the plan was saved, such as "5 minutes ago". */
							esc_html__( 'A plan is saved for %1$s, %2$s. It runs the next time you activate that theme.', 'axle-page-template-usage' ),
							esc_html( $name ),
							AxleUi::relative_time( (int) $saved['time'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi.
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-inline-form">
						<?php wp_nonce_field( self::ACTION_CLEAR ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEAR ); ?>">
						<input type="hidden" name="theme" value="<?php echo esc_attr( $stylesheet ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Remove the plan', 'axle-page-template-usage' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( current_user_can( 'switch_themes' ) ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>"><?php esc_html_e( 'Go to Appearance > Themes to activate it', 'axle-page-template-usage' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prints one row: a missing template, examples, and the choice of what to use instead.
	 *
	 * @param string                               $stylesheet Theme folder name.
	 * @param string                               $post_type  Post type name.
	 * @param array<string, mixed>                 $row        slug, name, count, already_missing.
	 * @param array<string, array<string, string>> $mapping    Saved mapping.
	 */
	private function render_row( string $stylesheet, string $post_type, array $row, array $mapping ): void {
		$targets  = $this->check->get_targets( $stylesheet, $post_type );
		$selected = $mapping[ $post_type ][ $row['slug'] ] ?? Templates::DEFAULT_TEMPLATE;
		$field_id = 'aptu-map-' . md5( $post_type . $row['slug'] );
		$list     = new ListColumn( $this->templates );
		?>
		<tr class="aptu-map-row" data-template="<?php echo esc_attr( $row['slug'] ); ?>">
			<td>
				<strong><?php echo esc_html( $row['name'] ); ?></strong>
				<?php if ( $row['name'] !== $row['slug'] ) : ?>
					<code class="aptu-slug"><?php echo esc_html( $row['slug'] ); ?></code>
				<?php endif; ?>
				<?php if ( $row['already_missing'] ) : ?>
					<span class="axle-badge axle-badge--error"><?php esc_html_e( 'Already missing', 'axle-page-template-usage' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="num"><a href="<?php echo esc_url( $list->filter_url( $post_type, $row['slug'] ) ); ?>"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></a></td>
			<td>
				<?php
				$sample = $this->check->get_sample( $post_type, $row['slug'] );
				$links  = array();
				foreach ( $sample as $item ) {
					// Drafts and private items of other people only show to those allowed to see them.
					if ( ! current_user_can( 'read_post', $item['id'] ) ) {
						continue;
					}
					$edit    = get_edit_post_link( $item['id'] );
					$links[] = $edit ? sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html( $item['title'] ) ) : esc_html( $item['title'] );
				}
				echo wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => true ) ) );
				?>
				<?php if ( $row['count'] > count( $sample ) ) : ?>
					<span class="aptu-more">&hellip;</span>
				<?php endif; ?>
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>">
					<?php
					/* translators: %s: template name or file. */
					echo esc_html( sprintf( __( 'Template to use instead of %s', 'axle-page-template-usage' ), $row['name'] ) );
					?>
				</label>
				<select name="map[<?php echo esc_attr( $post_type ); ?>][<?php echo esc_attr( $row['slug'] ); ?>]" id="<?php echo esc_attr( $field_id ); ?>">
					<?php foreach ( $targets as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $selected, (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the mapping from the form.
	 */
	public function handle_save(): void {
		check_admin_referer( self::ACTION_SAVE );
		$this->authorize();

		$stylesheet = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		$raw        = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? map_deep( wp_unslash( $_POST['map'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by map_deep.
		// A plan only covers the post types this user can edit.
		$raw    = array_intersect_key( (array) $raw, array_flip( ReportPage::accessible_types() ) );
		$result = $this->check->save_mapping( $stylesheet, $raw );

		if ( is_wp_error( $result ) ) {
			ReportPage::redirect_back( array( 'aptu_error' => 'mapping' ), self::url( $stylesheet ) );
		}

		ReportPage::redirect_back( array( 'aptu_saved' => $result ? 1 : 0 ), self::url( $stylesheet ) );
	}

	/**
	 * Removes a saved mapping.
	 */
	public function handle_clear(): void {
		check_admin_referer( self::ACTION_CLEAR );
		$this->authorize();

		$stylesheet = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		$this->check->save_mapping( $stylesheet, array() );
		ReportPage::redirect_back( array( 'aptu_saved' => 0 ), self::url( $stylesheet ) );
	}

	/**
	 * Moves the items a big switch left behind.
	 */
	public function handle_continue(): void {
		check_admin_referer( self::ACTION_CONTINUE );
		$this->authorize();

		$state = $this->check->get_result();
		if ( $state && ! empty( $state['remaining'] ) ) {
			$this->check->run( $state, ThemeCheck::batch_size( (string) $state['stylesheet'] ) );
		}

		ReportPage::redirect_back( array(), ReportPage::url() );
	}

	/**
	 * Hides the switch notice.
	 */
	public function handle_dismiss(): void {
		check_admin_referer( self::ACTION_DISMISS );
		$this->authorize();

		$this->check->dismiss_result();
		ReportPage::redirect_back( array(), ReportPage::url() );
	}

	/**
	 * Stops users who may not change templates this way.
	 */
	private function authorize(): void {
		if ( ! ReportPage::can_check_themes() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change templates for these items.', 'axle-page-template-usage' ), 403 );
		}
	}

	/**
	 * After a theme switch: what moved, with Undo.
	 */
	public function render_switch_notice(): void {
		$screen = get_current_screen();
		$state  = $this->check->get_result();
		if ( ! $state || ! $screen || ! ReportPage::can_check_themes() || ! in_array( $screen->base, array( 'dashboard', 'themes', 'edit', 'appearance_page_' . ReportPage::SLUG, 'theme-install' ), true ) ) {
			return;
		}

		/**
		 * Filters whether the notice about moved templates shows after a theme switch.
		 *
		 * @param bool                 $show  Default true.
		 * @param array<string, mixed> $state theme, batch, user, time, moved, remaining, errors.
		 */
		if ( ! apply_filters( 'axle_page_template_usage_show_switch_notice', true, $state ) ) {
			return;
		}

		$moved = (int) $state['moved'];
		$left  = (int) $state['remaining'];
		?>
		<div class="notice notice-success aptu-switch-notice">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of items, 2: theme name. */
						_n( 'Axle Page Template Usage moved %1$s item to a template of %2$s, as you planned.', 'Axle Page Template Usage moved %1$s items to templates of %2$s, as you planned.', $moved, 'axle-page-template-usage' ),
						number_format_i18n( $moved ),
						(string) $state['theme']
					)
				);
				?>
				<?php if ( $left ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of items. */
							_n( '%s more follows in the background.', '%s more follow in the background.', $left, 'axle-page-template-usage' ),
							number_format_i18n( $left )
						)
					);
					?>
				<?php endif; ?>
				<?php if ( ! empty( $state['errors'] ) ) : ?>
					<?php esc_html_e( 'Some entries of the plan were skipped because the theme does not have the template.', 'axle-page-template-usage' ); ?>
				<?php endif; ?>
			</p>
			<p class="aptu-switch-notice__actions">
				<?php if ( $moved ) : ?>
					<?php HistoryTab::render_undo_batch_form( (string) $state['batch'], $moved ); ?>
				<?php endif; ?>
				<?php if ( $left ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-inline-form">
						<?php wp_nonce_field( self::ACTION_CONTINUE ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONTINUE ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Move the rest now', 'axle-page-template-usage' ); ?></button>
					</form>
				<?php endif; ?>
				<a class="button aptu-notice-link" href="<?php echo esc_url( HistoryTab::url( array( 'batch' => $state['batch'] ) ) ); ?>"><?php esc_html_e( 'See what changed', 'axle-page-template-usage' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-inline-form">
					<?php wp_nonce_field( self::ACTION_DISMISS ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISMISS ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Dismiss', 'axle-page-template-usage' ); ?></button>
				</form>
			</p>
		</div>
		<?php
	}
}
