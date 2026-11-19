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

	public const TEST        = 'axle_page_template_usage_missing';
	public const TEST_UPDATE = 'axle_page_template_usage_update';

	/**
	 * Finder of missing templates.
	 *
	 * @var Missing
	 */
	private Missing $missing;

	/**
	 * Check of waiting theme updates.
	 *
	 * @var UpdateCheck|null
	 */
	private ?UpdateCheck $updates;

	/**
	 * Sets up the tests.
	 *
	 * @param Missing          $missing Finder of missing templates.
	 * @param UpdateCheck|null $updates Check of waiting theme updates.
	 */
	public function __construct( Missing $missing, ?UpdateCheck $updates = null ) {
		$this->missing = $missing;
		$this->updates = $updates;
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

		if ( $this->updates ) {
			$tests['direct'][ self::TEST_UPDATE ] = array(
				'label' => __( 'A theme update keeps the templates pages use', 'axle-page-template-usage' ),
				'test'  => array( $this, 'run_update' ),
			);
		}

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

	/**
	 * Runs the test for a waiting theme update that was checked and would take away templates in use.
	 *
	 * It only reports what the person looking at it may see: their post types and items.
	 *
	 * @return array<string, mixed>
	 */
	public function run_update(): array {
		$result = array(
			'label'       => __( 'No waiting theme update takes away a template in use', 'axle-page-template-usage' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Templates', 'axle-page-template-usage' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'No theme update that was checked would remove a template that pages use. Updates are checked from Appearance > Themes or Dashboard > Updates, before you install them.', 'axle-page-template-usage' ) . '</p>',
			'actions'     => '',
			'test'        => self::TEST_UPDATE,
		);

		$affected = array();
		$total    = 0;
		if ( $this->updates && UpdateCheck::can_check() ) {
			foreach ( $this->updates->pending_updates() as $stylesheet => $update ) {
				$report = $this->updates->report( (string) $stylesheet );
				if ( $report && $report['total'] ) {
					$affected[] = $update['name'];
					$total     += $report['total'];
				}
			}
		}

		if ( $affected ) {
			$result['status'] = 'recommended';
			$result['label']  = sprintf(
				/* translators: %s: number of items. */
				_n( 'A theme update would take the template away from %s item', 'A theme update would take the templates away from %s items', $total, 'axle-page-template-usage' ),
				number_format_i18n( $total )
			);
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: theme names, separated by commas. */
					__( 'The waiting update of %s removes templates that items use. They would show the default layout. Choose what they use instead before you update.', 'axle-page-template-usage' ),
					implode( ', ', $affected )
				)
			) . '</p>';
			$result['actions'] = sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'themes.php' ) ), esc_html__( 'Open Appearance > Themes', 'axle-page-template-usage' ) );
		}

		/**
		 * Filters the Site Health result for a theme update that takes away templates.
		 *
		 * @param array<string, mixed> $result   Site Health result.
		 * @param string[]             $affected Names of the themes whose update takes away templates in use.
		 */
		return (array) apply_filters( 'axle_page_template_usage_update_health_result', $result, $affected );
	}
}
