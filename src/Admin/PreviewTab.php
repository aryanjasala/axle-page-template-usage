<?php
/**
 * "Preview with..." view: pick a template to see one page in.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Admin;

use AryanX\Axle\PageTemplateUsage\Preview;
use AryanX\Axle\PageTemplateUsage\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the templates the theme offers for one page, each with a link that
 * opens the page's front end with that template.
 */
final class PreviewTab {

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Front end preview.
	 *
	 * @var Preview
	 */
	private Preview $preview;

	/**
	 * Sets up the view.
	 *
	 * @param Templates $templates Template data.
	 * @param Preview   $preview   Front end preview.
	 */
	public function __construct( Templates $templates, Preview $preview ) {
		$this->templates = $templates;
		$this->preview   = $preview;
	}

	/**
	 * URL of the view for one page.
	 *
	 * @param int $post_id Page.
	 * @return string
	 */
	public static function url( int $post_id ): string {
		return ReportPage::url(
			array(
				'tab'  => 'preview',
				'post' => $post_id,
			)
		);
	}

	/**
	 * The page asked for in the URL.
	 *
	 * @return int
	 */
	public static function requested_post(): int {
		// Read-only choice of what to show. Previewing is checked per page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	}

	/**
	 * Prints the view.
	 */
	public function render(): void {
		$post_id = self::requested_post();
		$post    = $post_id ? get_post( $post_id ) : null;
		?>
		<p class="axle-intro aptu-report__intro">
			<?php esc_html_e( 'See one page in another template before you change it. The preview is only for you, nothing is saved, and search engines are told to ignore it.', 'axle-page-template-usage' ); ?>
		</p>
		<?php
		if ( ! $post instanceof \WP_Post || ! Preview::can_preview( $post_id ) ) :
			?>
			<div class="axle-empty aptu-preview-empty">
				<p><?php esc_html_e( 'Choose a page to preview from a list of pages: open its row actions and pick "Preview with...". You can only preview pages you can edit.', 'axle-page-template-usage' ); ?></p>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Go to Pages', 'axle-page-template-usage' ); ?></a>
			</div>
			<?php
			return;
		endif;

		$templates = array( Templates::DEFAULT_TEMPLATE => __( 'Default', 'axle-page-template-usage' ) ) + $this->templates->get_templates( $post->post_type );
		$current   = (string) get_post_meta( $post_id, Templates::META_KEY, true );
		$current   = $this->templates->is_default( $current ) ? Templates::DEFAULT_TEMPLATE : $current;
		$known     = isset( $templates[ $current ] );
		$title     = '' !== get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'axle-page-template-usage' );
		?>
		<section class="axle-card aptu-preview-picker" aria-labelledby="aptu-preview-title">
			<h2 class="axle-card__title" id="aptu-preview-title">
				<?php
				/* translators: %s: page title. */
				echo esc_html( sprintf( __( 'Preview "%s" with...', 'axle-page-template-usage' ), $title ) );
				?>
			</h2>
			<?php if ( ! $known ) : ?>
				<p class="description">
					<?php
					/* translators: %s: template file. */
					echo esc_html( sprintf( __( 'This page uses "%s", which the theme no longer has, so it shows the default layout now.', 'axle-page-template-usage' ), $current ) );
					?>
				</p>
			<?php endif; ?>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Templates to preview with', 'axle-page-template-usage' ); ?>">
				<table class="widefat striped aptu-preview-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Template', 'axle-page-template-usage' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'axle-page-template-usage' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Preview', 'axle-page-template-usage' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $templates as $slug => $name ) : ?>
							<tr data-template="<?php echo esc_attr( (string) $slug ); ?>">
								<td>
									<strong><?php echo esc_html( $name ); ?></strong>
									<?php if ( Templates::DEFAULT_TEMPLATE !== (string) $slug ) : ?>
										<code class="aptu-slug"><?php echo esc_html( (string) $slug ); ?></code>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( (string) $slug === $current ) : ?>
										<span class="axle-badge axle-badge--info"><?php esc_html_e( 'Current', 'axle-page-template-usage' ); ?></span>
									<?php else : ?>
										<span class="axle-muted">&nbsp;</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( (string) $slug !== $current ) : ?>
										<a class="button" href="<?php echo esc_url( $this->preview->url( $post_id, (string) $slug ) ); ?>">
											<?php esc_html_e( 'Open the preview', 'axle-page-template-usage' ); ?>
											<span class="screen-reader-text">
												<?php
												/* translators: %s: template name. */
												echo esc_html( sprintf( __( 'with %s', 'axle-page-template-usage' ), $name ) );
												?>
											</span>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}
}
