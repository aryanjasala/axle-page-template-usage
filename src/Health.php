<?php
/**
 * Site Health test: pages on a template the theme no longer has.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

use AryanX\Axle\PageTemplateUsage\Admin\ReportPage;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the test to Tools > Site Health. It only covers the post types the
 * person looking at it may edit.
 */
final class Health implements Service {

	public const TEST = 'axle_page_template_usage_missing';

	/**
	 * Finder of missing templates.
	 *
	 * @var Missing
	 */
	private Missing $missing;

	/**
	 * Sets up the test.
	 *
	 * @param Missing $missing Finder of missing templates.
	 */
	public function __construct( Missing $missing ) {
		$this->missing = $missing;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the direct test.
	 *
	 * @param mixed $tests Site Health tests.
	 * @return mixed
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct'][ self::TEST ] = array(
			'label' => __( 'Pages use templates the theme has', 'axle-page-template-usage' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$rows  = $this->missing->get_missing( ReportPage::accessible_types() );
		$total = (int) array_sum( wp_list_pluck( $rows, 'count' ) );

		$result = array(
			'label'       => __( 'Every page uses a template the theme has', 'axle-page-template-usage' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Templates', 'axle-page-template-usage' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'No page, post or other item points at a template the active theme has lost.', 'axle-page-template-usage' ) . '</p>',
			'actions'     => '',
			'test'        => self::TEST,
		);

		if ( $total ) {
			$names = array_slice( wp_list_pluck( $rows, 'slug' ), 0, 5 );

			$result['status'] = 'recommended';
			$result['label']  = sprintf(
				/* translators: %s: number of items. */
				_n( '%s item uses a template the theme no longer has', '%s items use a template the theme no longer has', $total, 'axle-page-template-usage' ),
				number_format_i18n( $total )
			);
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: template names, separated by commas. */
					__( 'WordPress shows the default layout for these items. Missing: %s.', 'axle-page-template-usage' ),
					implode( ', ', $names )
				)
			) . '</p>';
			$result['actions'] = sprintf( '<p><a href="%s">%s</a></p>', esc_url( ReportPage::url( array( 'tab' => 'missing' ) ) ), esc_html__( 'Review the missing templates', 'axle-page-template-usage' ) );
		}

		/**
		 * Filters the Site Health result for missing templates.
		 *
		 * @param array<string, mixed>   $result Site Health result.
		 * @param array<int, array>      $rows   Missing templates the current user may see.
		 */
		return (array) apply_filters( 'axle_page_template_usage_health_result', $result, $rows );
	}
}
