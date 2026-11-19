<?php
/**
 * "Which pages lose their template?" under a theme update.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Admin;

use AryanX\Axle\PageTemplateUsage\Service;
use AryanX\Axle\PageTemplateUsage\Templates;
use AryanX\Axle\PageTemplateUsage\ThemeCheck;
use AryanX\Axle\PageTemplateUsage\UpdateCheck;
use const AryanX\Axle\PageTemplateUsage\PLUGIN_FILE;
use const AryanX\Axle\PageTemplateUsage\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the panel on Appearance > Themes and Dashboard > Updates (and on the
 * Check a theme tab), and handles its three buttons: check the update, plan
 * what happens to the pages, and cancel the plan.
 */
final class UpdatePanel implements Service {

	public const ACTION_CHECK  = 'axle_page_template_usage_check_update';
	public const ACTION_PLAN   = 'axle_page_template_usage_plan_update';
	public const ACTION_CANCEL = 'axle_page_template_usage_cancel_update';
	public const FLASH_FLAG    = 'aptu_update';

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Theme check, for example pages.
	 *
	 * @var ThemeCheck
	 */
	private ThemeCheck $theme;

	/**
	 * Update check.
	 *
	 * @var UpdateCheck
	 */
	private UpdateCheck $updates;

	/**
	 * Sets up the panel.
	 *
	 * @param Templates   $templates Template data.
	 * @param ThemeCheck  $theme     Theme check.
	 * @param UpdateCheck $updates   Update check.
	 */
	public function __construct( Templates $templates, ThemeCheck $theme, UpdateCheck $updates ) {
		$this->templates = $templates;
		$this->theme     = $theme;
		$this->updates   = $updates;
	}

	/**
	 * Hooks in the notice, the styles, the buttons and the link in the theme details.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION_CHECK, array( $this, 'handle_check' ) );
		add_action( 'admin_post_' . self::ACTION_PLAN, array( $this, 'handle_plan' ) );
		add_action( 'admin_post_' . self::ACTION_CANCEL, array( $this, 'handle_cancel' ) );
		add_filter( 'wp_prepare_themes_for_js', array( $this, 'add_update_link' ) );
	}

	/**
	 * Screens that show the panel.
	 *
	 * @return string[] Screen IDs.
	 */
	private function screens(): array {
		/**
		 * Filters the admin screens that show "Which pages lose their template?" under a theme update.
		 *
		 * @param string[] $screen_ids Default the Themes screen and Dashboard > Updates.
		 */
		return array_map( 'strval', (array) apply_filters( 'axle_page_template_usage_update_notice_screens', array( 'themes', 'update-core', 'themes-network', 'update-core-network' ) ) );
	}

	/**
	 * Whether the current screen shows the panel.
	 *
	 * @return bool
	 */
	private function on_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && in_array( $screen->id, $this->screens(), true ) && UpdateCheck::can_check();
	}

	/**
	 * Loads the styles on the screens that show the panel.
	 */
	public function enqueue(): void {
		if ( ! $this->on_screen() || ! $this->updates->pending_updates() ) {
			return;
		}

		AxleUi::enqueue();
		wp_enqueue_style( 'axle-page-template-usage', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
	}

	/**
	 * Adds a link under the update notice of a theme on Appearance > Themes.
	 *
	 * @param mixed $themes Themes as the Themes screen gets them.
	 * @return mixed
	 */
	public function add_update_link( $themes ) {
		if ( ! is_array( $themes ) || ! UpdateCheck::can_check() ) {
			return $themes;
		}

		foreach ( $this->updates->pending_updates() as $stylesheet => $update ) {
			if ( ! isset( $themes[ $stylesheet ] ) || empty( $themes[ $stylesheet ]['hasUpdate'] ) ) {
				continue;
			}

			$themes[ $stylesheet ]['update'] = ( $themes[ $stylesheet ]['update'] ?? '' ) . sprintf(
				'<p class="aptu-theme-link"><a href="%s">%s</a></p>',
				esc_url( ThemeCheckTab::url() . '#aptu-update-' . $stylesheet ),
				esc_html__( 'Which pages lose their template?', 'axle-page-template-usage' )
			);
		}

		return $themes;
	}

	/**
	 * Prints the panel as a notice on the Themes and Updates screens.
	 */
	public function render_notice(): void {
		if ( ! $this->on_screen() ) {
			return;
		}

		foreach ( $this->updates->pending_updates() as $update ) {
			echo '<div class="notice notice-info axle-ui aptu-update-check" id="aptu-update-' . esc_attr( $update['stylesheet'] ) . '">';
			$this->render_body( $update, false );
			echo '</div>';
		}
	}

	/**
	 * Prints the panel inside the Check a theme tab.
	 */
	public function render_cards(): void {
		if ( ! UpdateCheck::can_check() ) {
			return;
		}

		foreach ( $this->updates->pending_updates() as $update ) {
			echo '<section class="axle-card aptu-update-check" id="aptu-update-' . esc_attr( $update['stylesheet'] ) . '" aria-labelledby="aptu-update-title-' . esc_attr( $update['stylesheet'] ) . '">';
			$this->render_body( $update, true );
			echo '</section>';
		}
	}

	/**
	 * Prints what is inside one panel.
	 *
	 * @param array{stylesheet: string, name: string, role: string, from: string, to: string, package: string} $update  The waiting update.
	 * @param bool                                                                                             $in_tab Whether the panel sits in a card in the plugin's own screen.
	 */
	private function render_body( array $update, bool $in_tab ): void {
		$sheet  = $update['stylesheet'];
		$report = $this->updates->report( $sheet );
		$plan   = $this->updates->get_plan( $sheet );

		printf(
			'<h2 class="%1$s" id="aptu-update-title-%2$s">%3$s</h2>',
			$in_tab ? 'axle-card__title' : 'aptu-update-check__title',
			esc_attr( $sheet ),
			esc_html__( 'Which pages lose their template?', 'axle-page-template-usage' )
		);

		$this->render_flash();

		$parent = 'parent' === $update['role'] ? ' ' . __( 'It is the parent of your active theme.', 'axle-page-template-usage' ) : '';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: theme name, 2: installed version, 3: new version. */
				__( '%1$s has an update, from %2$s to %3$s.', 'axle-page-template-usage' ),
				$update['name'],
				'' !== $update['from'] ? $update['from'] : '?',
				'' !== $update['to'] ? $update['to'] : '?'
			) . $parent
		) . '</p>';

		if ( ! $report ) {
			echo '<p>' . esc_html__( 'Find out before you update. The update package is downloaded to a temporary folder and only its template names are read. Nothing is installed, and no code from the theme runs.', 'axle-page-template-usage' ) . '</p>';
			$this->render_check_form( $sheet, __( 'Check the update', 'axle-page-template-usage' ), true );
			return;
		}

		$summary = $report['total']
			? sprintf(
				/* translators: 1: number of items, 2: theme name. */
				_n( '%1$s item would lose its template when %2$s is updated, and show the default layout.', '%1$s items would lose their template when %2$s is updated, and show the default layout.', $report['total'], 'axle-page-template-usage' ),
				number_format_i18n( $report['total'] ),
				$update['name']
			)
			/* translators: %s: theme name. */
			: sprintf( __( 'No item you can edit would lose its template when %s is updated.', 'axle-page-template-usage' ), $update['name'] );

		/**
		 * Filters the sentence that says what a theme update would do to the pages.
		 *
		 * @param string               $summary Text.
		 * @param string               $stylesheet Theme folder name.
		 * @param array<string, mixed> $report  theme, groups, targets, total.
		 */
		$summary = (string) apply_filters( 'axle_page_template_usage_update_check_message', $summary, $sheet, $report );

		printf(
			'<p><span class="axle-badge axle-badge--%1$s">%2$s</span> %3$s</p>',
			$report['total'] ? 'warning' : 'good',
			esc_html( $report['total'] ? __( 'Some pages are affected', 'axle-page-template-usage' ) : __( 'Nothing is lost', 'axle-page-template-usage' ) ),
			esc_html( $summary )
		);

		if ( $report['total'] ) {
			$this->render_plan_form( $update, $report, $plan );
		}

		if ( $plan ) {
			$this->render_plan_note( $sheet, $plan );
		}

		echo '<p class="aptu-update-check__checked">';
		printf(
			/* translators: %s: when the update was checked, such as "5 minutes ago". */
			esc_html__( 'Checked %s.', 'axle-page-template-usage' ),
			AxleUi::relative_time( (int) $report['theme']['time'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi.
		);
		echo '</p>';
		$this->render_check_form( $sheet, __( 'Check again', 'axle-page-template-usage' ), false );
	}

	/**
	 * Prints the button that checks an update.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @param string $label      Button text.
	 * @param bool   $primary    Whether it is the main action of the panel.
	 */
	private function render_check_form( string $stylesheet, string $label, bool $primary ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-inline-form">
			<?php wp_nonce_field( self::ACTION_CHECK . $stylesheet ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CHECK ); ?>">
			<input type="hidden" name="theme" value="<?php echo esc_attr( $stylesheet ); ?>">
			<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Prints the pages that would lose their template, and what to use instead.
	 *
	 * @param array<string, string>                                 $update The waiting update.
	 * @param array<string, mixed>                                  $report From UpdateCheck::report().
	 * @param array{map: array<string, array<string, string>>}|null $plan   Saved plan, if any.
	 */
	private function render_plan_form( array $update, array $report, ?array $plan ): void {
		$sheet = $update['stylesheet'];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-mapping-form">
			<?php wp_nonce_field( self::ACTION_PLAN . $sheet ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_PLAN ); ?>">
			<input type="hidden" name="theme" value="<?php echo esc_attr( $sheet ); ?>">

			<?php foreach ( $report['groups'] as $post_type => $rows ) : ?>
				<?php $object = get_post_type_object( (string) $post_type ); ?>
				<?php $label = $object ? $object->labels->name : (string) $post_type; ?>
				<h3><?php echo esc_html( $label ); ?></h3>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php /* translators: %s: post type name. */ echo esc_attr( sprintf( __( 'Templates %s would lose', 'axle-page-template-usage' ), $label ) ); ?>">
					<table class="widefat striped aptu-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Template now', 'axle-page-template-usage' ); ?></th>
								<th scope="col" class="num"><?php echo esc_html( $label ); ?></th>
								<th scope="col"><?php esc_html_e( 'Examples', 'axle-page-template-usage' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Use instead', 'axle-page-template-usage' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php $this->render_row( (string) $post_type, $row, (array) ( $report['targets'][ $post_type ] ?? array() ), $plan ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>

			<p class="aptu-mapping-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Move them right after the update', 'axle-page-template-usage' ); ?></button>
				<span class="description"><?php esc_html_e( 'Saving changes nothing yet. The pages move when the update is installed, and you get an Undo.', 'axle-page-template-usage' ); ?></span>
			</p>
		</form>
		<?php
	}

	/**
	 * Prints one row: a template the update takes away, a few pages, and what to use instead.
	 *
	 * @param string                                                $post_type Post type name.
	 * @param array{slug: string, name: string, count: int}         $row      The template.
	 * @param array<string, string>                                 $targets   Templates the updated theme offers for the post type.
	 * @param array{map: array<string, array<string, string>>}|null $plan Saved plan.
	 */
	private function render_row( string $post_type, array $row, array $targets, ?array $plan ): void {
		$selected = $plan['map'][ $post_type ][ $row['slug'] ] ?? Templates::DEFAULT_TEMPLATE;
		$field_id = 'aptu-upd-' . md5( $post_type . $row['slug'] );
		$list     = new ListColumn( $this->templates );
		asort( $targets, SORT_NATURAL | SORT_FLAG_CASE );
		$targets = array( Templates::DEFAULT_TEMPLATE => __( 'Default', 'axle-page-template-usage' ) ) + $targets;
		?>
		<tr class="aptu-map-row" data-template="<?php echo esc_attr( $row['slug'] ); ?>">
			<td>
				<strong><?php echo esc_html( $row['name'] ); ?></strong>
				<?php if ( $row['name'] !== $row['slug'] ) : ?>
					<code class="aptu-slug"><?php echo esc_html( $row['slug'] ); ?></code>
				<?php endif; ?>
			</td>
			<td class="num"><a href="<?php echo esc_url( $list->filter_url( $post_type, $row['slug'] ) ); ?>"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></a></td>
			<td>
				<?php
				// Only titles of items the user may read: the sample drops the rest.
				$sample = $this->theme->get_sample( $post_type, $row['slug'] );
				$links  = array();
				foreach ( $sample as $item ) {
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
	 * Prints the note that a plan is waiting for the update, with a way to cancel it.
	 *
	 * @param string                                                      $stylesheet Theme folder name.
	 * @param array{map: array<string, array<string, string>>, time: int} $plan       Saved plan.
	 */
	private function render_plan_note( string $stylesheet, array $plan ): void {
		?>
		<div class="notice notice-info inline aptu-update-plan">
			<p>
				<?php
				printf(
					/* translators: %s: when the plan was saved, such as "5 minutes ago". */
					esc_html__( 'A plan is saved, %s. It runs right after the update is installed, and the move can be undone from the History tab.', 'axle-page-template-usage' ),
					AxleUi::relative_time( (int) $plan['time'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi.
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-inline-form">
				<?php wp_nonce_field( self::ACTION_CANCEL . $stylesheet ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CANCEL ); ?>">
				<input type="hidden" name="theme" value="<?php echo esc_attr( $stylesheet ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Remove the plan', 'axle-page-template-usage' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Remembers a message for the next screen, for this user only.
	 *
	 * @param string $type    notice type: success or error.
	 * @param string $message Plain text, never a title or a count of hidden items.
	 */
	private function flash( string $type, string $message ): void {
		set_transient( 'axle_ptu_flash_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
	}

	/**
	 * Prints and forgets the message left by a button.
	 */
	private function render_flash(): void {
		$key   = 'axle_ptu_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		// Only after our own redirect, so a screen that is not the answer to a button never eats the message.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::FLASH_FLAG ] ) || ! is_array( $flash ) || 2 !== count( $flash ) ) {
			return;
		}

		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s inline aptu-live" role="status"><p>%2$s</p></div>',
			'error' === $flash[0] ? 'error' : 'success',
			esc_html( (string) $flash[1] )
		);
	}

	/**
	 * Goes back to the screen the button was on.
	 *
	 * @param string $stylesheet Theme folder name, for the anchor.
	 */
	private function back( string $stylesheet ): void {
		$target = wp_get_referer();
		$target = $target ? $target : ThemeCheckTab::url();
		$target = add_query_arg( self::FLASH_FLAG, '1', remove_query_arg( self::FLASH_FLAG, $target ) );

		wp_safe_redirect( $target . '#aptu-update-' . rawurlencode( $stylesheet ) );
		exit;
	}

	/**
	 * Stops users who may not check updates or plan moves.
	 */
	private function authorize(): void {
		if ( ! UpdateCheck::can_check() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to check theme updates for template changes.', 'axle-page-template-usage' ), 403 );
		}
	}

	/**
	 * Downloads and reads the update.
	 */
	public function handle_check(): void {
		$stylesheet = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		check_admin_referer( self::ACTION_CHECK . $stylesheet );
		$this->authorize();

		$result = $this->updates->check( $stylesheet );
		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
		} else {
			$this->flash( 'success', __( 'Checked. The update was read and nothing was installed.', 'axle-page-template-usage' ) );
		}

		$this->back( $stylesheet );
	}

	/**
	 * Saves the plan from the form.
	 */
	public function handle_plan(): void {
		$stylesheet = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		check_admin_referer( self::ACTION_PLAN . $stylesheet );
		$this->authorize();

		$raw = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? map_deep( wp_unslash( $_POST['map'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by map_deep.
		// A plan only covers the post types this user can edit.
		$raw    = array_intersect_key( (array) $raw, array_flip( ReportPage::accessible_types() ) );
		$result = $this->updates->save_plan( $stylesheet, $raw );

		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
		} else {
			$this->flash( 'success', __( 'Saved. The pages move right after the update is installed.', 'axle-page-template-usage' ) );
		}

		$this->back( $stylesheet );
	}

	/**
	 * Removes the plan.
	 */
	public function handle_cancel(): void {
		$stylesheet = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		check_admin_referer( self::ACTION_CANCEL . $stylesheet );
		$this->authorize();

		$this->updates->clear_plan( $stylesheet );
		$this->flash( 'success', __( 'The plan was removed. Nothing will move after the update.', 'axle-page-template-usage' ) );

		$this->back( $stylesheet );
	}
}
