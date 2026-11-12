<?php
/**
 * Shows a page on the front end with another template, without saving anything.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

use AryanX\Axle\PageTemplateUsage\Admin\ReportPage;
use const AryanX\Axle\PageTemplateUsage\PLUGIN_FILE;
use const AryanX\Axle\PageTemplateUsage\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * A preview is the normal front end URL of the page plus two query arguments:
 * the template, and a signed token made for that user, page and template. For that one
 * request, the page's stored template reads as the previewed one, which works
 * for classic template files and for block templates alike, because core finds
 * both through the page's template value.
 */
final class Preview implements Service {

	public const ARG       = 'axle_ptu_preview';
	public const TOKEN_ARG = 'axle_ptu_token';
	public const ACTION    = 'axle_page_template_usage_use_preview';

	/**
	 * The page and template being previewed in this request.
	 *
	 * @var array{id: int, template: string}|null
	 */
	private ?array $active = null;

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Sets up the preview.
	 *
	 * @param Templates $templates Template data.
	 */
	public function __construct( Templates $templates ) {
		$this->templates = $templates;
	}

	/**
	 * Hooks in the front end check and the "Use this template" handler.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_start' ), 1 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_use' ) );
	}

	/**
	 * A signed token for one user's preview of one page with one template.
	 *
	 * Not a WordPress nonce: those are tied to the browser session, so a link made
	 * by WP-CLI or an ability call would never work in a browser. This one is tied
	 * to the user, the page, the template and an expiry time instead.
	 *
	 * @param int    $post_id  Page.
	 * @param string $template Template value.
	 * @param int    $expires  Unix time the link stops working.
	 * @return string
	 */
	private static function sign( int $post_id, string $template, int $expires ): string {
		return hash_hmac( 'sha256', 'axle_page_template_usage_preview|' . get_current_user_id() . '|' . $post_id . '|' . $template . '|' . $expires, wp_salt( 'nonce' ) );
	}

	/**
	 * Makes the token in a preview link.
	 *
	 * @param int    $post_id  Page.
	 * @param string $template Template value.
	 * @return string Expiry time, a dot, and the signature.
	 */
	private static function make_token( int $post_id, string $template ): string {
		/**
		 * Filters how long a preview link works.
		 *
		 * @param int $seconds Default one day.
		 */
		$expires = time() + max( MINUTE_IN_SECONDS, (int) apply_filters( 'axle_page_template_usage_preview_lifetime', DAY_IN_SECONDS ) );

		return $expires . '.' . self::sign( $post_id, $template, $expires );
	}

	/**
	 * Whether a token is good for the current user, this page and this template, and not expired.
	 *
	 * @param string $token    Token from the link.
	 * @param int    $post_id  Page.
	 * @param string $template Template value.
	 * @return bool
	 */
	private static function verify_token( string $token, int $post_id, string $template ): bool {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < time() ) {
			return false;
		}

		return hash_equals( self::sign( $post_id, $template, (int) $parts[0] ), $parts[1] );
	}

	/**
	 * Whether the current user may preview a page.
	 *
	 * Needs access to the post type (see ReportPage::can_access()), and the
	 * right to edit this page.
	 *
	 * @param int $post_id Page.
	 * @return bool
	 */
	public static function can_preview( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! ReportPage::can_access( $post->post_type ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		/**
		 * Filters whether the current user may preview a page with another template.
		 *
		 * @param bool     $allowed Default: the user can edit the page and has access to its post type.
		 * @param \WP_Post $post    The page.
		 */
		return (bool) apply_filters( 'axle_page_template_usage_can_preview', true, $post );
	}

	/**
	 * Front end URL that shows a page with a template, for the current user only.
	 *
	 * @param int    $post_id  Page.
	 * @param string $template Template the theme offers, or default.
	 * @return string Empty when the user may not preview the page or the theme lacks the template.
	 */
	public function url( int $post_id, string $template ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::can_preview( $post_id ) || ! $this->valid_template( $post->post_type, $template ) ) {
			return '';
		}

		$base = 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post );
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		return add_query_arg(
			array(
				self::ARG       => $template,
				self::TOKEN_ARG => self::make_token( $post_id, $template ),
			),
			$base
		);
	}

	/**
	 * Whether the theme offers a template for a post type.
	 *
	 * @param string $post_type Post type.
	 * @param string $template  Template value.
	 * @return bool
	 */
	private function valid_template( string $post_type, string $template ): bool {
		return $this->templates->is_default( $template ) || isset( $this->templates->get_templates( $post_type )[ $template ] );
	}

	/**
	 * Starts the preview when the request asks for one and is allowed to.
	 */
	public function maybe_start(): void {
		// The signed token is checked below, once the page and template are known.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::ARG ], $_GET[ self::TOKEN_ARG ] ) || is_admin() || ! is_singular() ) {
			return;
		}
		$template = sanitize_text_field( wp_unslash( $_GET[ self::ARG ] ) );
		$token    = sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_ARG ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$post_id = (int) get_queried_object_id();
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post
			|| ! is_user_logged_in()
			|| ! self::can_preview( $post_id )
			|| ! $this->valid_template( $post->post_type, $template )
			|| ! self::verify_token( $token, $post_id, $template )
		) {
			return;
		}

		$this->active = array(
			'id'       => $post_id,
			'template' => $template,
		);

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant page caches look for.
		}

		add_filter( 'get_post_metadata', array( $this, 'filter_template_meta' ), 10, 3 );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render_bar' ), 100 );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		/**
		 * Fires when a page is shown with another template as a preview.
		 *
		 * @param int    $post_id  Page.
		 * @param string $template Template being previewed.
		 */
		do_action( 'axle_page_template_usage_preview_started', $post_id, $template );
	}

	/**
	 * Makes the previewed page report the previewed template, for this request.
	 *
	 * @param mixed  $value    Short-circuit value.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public function filter_template_meta( $value, $post_id, $meta_key ) {
		if ( $this->active && Templates::META_KEY === $meta_key && (int) $post_id === $this->active['id'] ) {
			return array( $this->active['template'] );
		}

		return $value;
	}

	/**
	 * Adds a class to the body so the bar has room.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		$classes   = (array) $classes;
		$classes[] = 'aptu-previewing';

		return $classes;
	}

	/**
	 * Styles for the preview bar.
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'axle-page-template-usage-preview', plugins_url( 'assets/preview.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Prints the bar: which template is shown, "Use this template" and "Close".
	 */
	public function render_bar(): void {
		if ( ! $this->active ) {
			return;
		}

		$id       = $this->active['id'];
		$template = $this->active['template'];
		$post     = get_post( $id );
		$name     = $post instanceof \WP_Post ? ( $this->templates->get_name( $post->post_type, $template ) ?? $template ) : $template;
		// The stored template, not the one this request pretends the page has.
		remove_filter( 'get_post_metadata', array( $this, 'filter_template_meta' ), 10 );
		$current = (string) get_post_meta( $id, Templates::META_KEY, true );
		add_filter( 'get_post_metadata', array( $this, 'filter_template_meta' ), 10, 3 );
		$same  = $this->templates->is_default( $current ) ? $this->templates->is_default( $template ) : $current === $template;
		$close = get_edit_post_link( $id, 'raw' );
		$close = $close ? $close : admin_url();

		$message = sprintf(
			/* translators: 1: page title, 2: template name. */
			__( 'Previewing "%1$s" with the template "%2$s". Nothing is saved.', 'axle-page-template-usage' ),
			get_the_title( $id ),
			$name
		);

		/**
		 * Filters the sentence on the preview bar.
		 *
		 * @param string $message  Text.
		 * @param int    $post_id  Page.
		 * @param string $template Template being previewed.
		 */
		$message = (string) apply_filters( 'axle_page_template_usage_preview_message', $message, $id, $template );
		?>
		<div class="aptu-preview-bar" role="region" aria-label="<?php esc_attr_e( 'Template preview', 'axle-page-template-usage' ); ?>">
			<p class="aptu-preview-bar__text"><?php echo esc_html( $message ); ?></p>
			<div class="aptu-preview-bar__actions">
				<?php if ( ! $same ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::ACTION . $id ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<input type="hidden" name="post" value="<?php echo esc_attr( (string) $id ); ?>">
						<input type="hidden" name="template" value="<?php echo esc_attr( $template ); ?>">
						<button type="submit" class="aptu-preview-bar__button aptu-preview-bar__button--primary"><?php esc_html_e( 'Use this template', 'axle-page-template-usage' ); ?></button>
					</form>
				<?php endif; ?>
				<a class="aptu-preview-bar__button" href="<?php echo esc_url( $close ); ?>"><?php esc_html_e( 'Close', 'axle-page-template-usage' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves the previewed template on the page, as a change in the history.
	 */
	public function handle_use(): void {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_admin_referer( self::ACTION . $post_id );

		$template = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';

		if ( ! self::can_preview( $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change the template of this page.', 'axle-page-template-usage' ), 403 );
		}

		$post   = get_post( $post_id );
		$result = $this->templates->set_template( $post_id, $template, 'preview' );

		if ( is_wp_error( $result ) || ! $post instanceof \WP_Post ) {
			ReportPage::redirect_back( array( 'aptu_error' => 'unknown_template' ), ReportPage::url(), false );
		}

		/**
		 * Fires after a previewed template was saved on a page.
		 *
		 * @param int    $post_id  Page.
		 * @param string $template Template now on the page.
		 * @param string $previous Template it had before.
		 */
		do_action( 'axle_page_template_usage_preview_applied', $post_id, $template, $result['previous'] );

		wp_safe_redirect(
			add_query_arg(
				array(
					'aptu_moved'   => 1,
					'aptu_skipped' => 0,
					'aptu_left'    => 0,
					'aptu_type'    => $post->post_type,
					'aptu_to'      => rawurlencode( $template ),
					'aptu_batch'   => $result['batch'],
				),
				\AryanX\Axle\PageTemplateUsage\Admin\HistoryTab::url( array( 'post' => $post_id ) )
			)
		);
		exit;
	}
}
