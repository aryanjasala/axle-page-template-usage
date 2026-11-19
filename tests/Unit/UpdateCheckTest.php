<?php
/**
 * Tests for the theme update check: which updates count, and when a saved plan runs.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

use AryanX\Axle\PageTemplateUsage\History;
use AryanX\Axle\PageTemplateUsage\Templates;
use AryanX\Axle\PageTemplateUsage\ThemeCheck;
use AryanX\Axle\PageTemplateUsage\UpdateCheck;
use Brain\Monkey\Functions;
use Mockery;

final class UpdateCheckTest extends TestCase {

	/** @var UpdateCheck */
	private $updates;

	/** @var string Version of the installed theme. */
	private $installed = '1.0.0';

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_stylesheet' )->justReturn( 'child' );
		Functions\when( 'get_template' )->justReturn( 'parent' );
		Functions\when( 'wp_get_theme' )->alias(
			function ( $stylesheet = '' ) {
				$theme = Mockery::mock();
				$theme->shouldReceive( 'exists' )->andReturn( 'gone' !== $stylesheet );
				$theme->shouldReceive( 'get' )->andReturnUsing(
					function ( $field ) use ( $stylesheet ) {
						return 'Version' === $field ? $this->installed : ucfirst( (string) $stylesheet );
					}
				);
				return $theme;
			}
		);

		$history       = new History();
		$templates     = new Templates( $history );
		$this->updates = new UpdateCheck( $templates, new ThemeCheck( $templates, $history ) );
	}

	/**
	 * Announces updates the way the update check stores them.
	 *
	 * @param string[] $stylesheets Themes with an update.
	 */
	private function announce( array $stylesheets ): void {
		$response = array();
		foreach ( $stylesheets as $stylesheet ) {
			$response[ $stylesheet ] = array(
				'new_version' => '2.0.0',
				'package'     => 'https://example.test/' . $stylesheet . '.zip',
			);
		}
		Functions\when( 'get_site_transient' )->justReturn( (object) array( 'response' => $response ) );
	}

	public function test_only_the_active_theme_and_its_parent_are_looked_at(): void {
		$this->announce( array( 'child', 'parent', 'other' ) );

		$pending = $this->updates->pending_updates();

		$this->assertSame( array( 'child', 'parent' ), array_keys( $pending ) );
		$this->assertSame( 'active', $pending['child']['role'] );
		$this->assertSame( 'parent', $pending['parent']['role'] );
		$this->assertSame( '2.0.0', $pending['child']['to'] );
	}

	public function test_a_theme_that_is_not_active_has_no_update_to_check(): void {
		$this->announce( array( 'other' ) );

		$result = $this->updates->check( 'other' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_page_template_usage_no_update', $result->get_error_code() );
	}

	public function test_an_update_with_no_package_says_so_and_downloads_nothing(): void {
		Functions\when( 'get_site_transient' )->justReturn( (object) array( 'response' => array( 'child' => array( 'new_version' => '2.0.0' ) ) ) );

		$result = $this->updates->check( 'child' );

		$this->assertSame( 'axle_page_template_usage_no_package', $result->get_error_code() );
	}

	public function test_a_plan_waits_while_the_installed_version_is_the_one_it_was_made_for(): void {
		$this->options[ UpdateCheck::OPTION_PLANS ] = array(
			'child' => array(
				'map'  => array( 'page' => array( 'old.php' => 'default' ) ),
				'from' => '1.0.0',
				'to'   => '2.0.0',
				'time' => time(),
				'user' => 3,
			),
		);

		$this->assertSame( array(), $this->updates->apply( false ) );
		$this->assertArrayHasKey( 'child', $this->options[ UpdateCheck::OPTION_PLANS ] );
	}

	public function test_a_plan_that_waited_too_long_is_dropped_without_running(): void {
		$this->options[ UpdateCheck::OPTION_PLANS ] = array(
			'child' => array(
				'map'  => array( 'page' => array( 'old.php' => 'default' ) ),
				'from' => '0.9.0',
				'time' => time() - 40 * DAY_IN_SECONDS,
				'user' => 3,
			),
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);

		$this->assertSame( array(), $this->updates->apply( false ) );
		$this->assertArrayNotHasKey( UpdateCheck::OPTION_PLANS, $this->options );
	}

	public function test_a_plan_for_an_installed_update_stays_when_a_filter_turns_the_automatic_run_off(): void {
		$this->installed                            = '2.0.0';
		$this->options[ UpdateCheck::OPTION_PLANS ] = array(
			'child' => array(
				'map'  => array( 'page' => array( 'old.php' => 'default' ) ),
				'from' => '1.0.0',
				'time' => time(),
				'user' => 3,
			),
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'axle_page_template_usage_apply_on_update' === $hook ? false : $value;
			}
		);

		$this->assertSame( array(), $this->updates->apply( false ) );
		$this->assertArrayHasKey( 'child', $this->options[ UpdateCheck::OPTION_PLANS ] );
	}

	public function test_a_result_for_another_version_is_not_trusted(): void {
		$this->announce( array( 'child' ) );
		$this->options[ UpdateCheck::OPTION_CHECKS ] = array(
			'child' => array(
				'to'   => '1.5.0',
				'from' => '1.0.0',
				'time' => time(),
				'lost' => array( 'page' => array( 'x.php' => 'X' ) ),
			),
		);

		$this->assertNull( $this->updates->get_result( 'child' ) );

		$this->options[ UpdateCheck::OPTION_CHECKS ]['child']['to'] = '2.0.0';
		$this->assertIsArray( $this->updates->get_result( 'child' ) );
	}

	public function test_the_report_leaves_out_post_types_the_caller_may_not_cover_and_templates_with_no_items(): void {
		$this->announce( array( 'child' ) );
		$this->options[ UpdateCheck::OPTION_CHECKS ] = array(
			'child' => array(
				'name'    => 'Child',
				'to'      => '2.0.0',
				'from'    => '1.0.0',
				'time'    => time(),
				'lost'    => array(
					'page' => array(
						'a.php' => 'A',
						'b.php' => 'B',
					),
					'book' => array( 'c.php' => 'C' ),
				),
				'targets' => array(),
			),
		);
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_cache_get' )->alias(
			static function ( $key ) {
				return 'counts:page' === $key ? array( 'a.php' => 4 ) : ( 'counts:book' === $key ? array( 'c.php' => 9 ) : false );
			}
		);

		$report = $this->updates->report( 'child', array( 'page' ) );

		$this->assertSame( 4, $report['total'] );
		$this->assertSame( array( 'page' ), array_keys( $report['groups'] ) );
		$this->assertSame( array( 'a.php' ), array_column( $report['groups']['page'], 'slug' ) );
	}
}
