<?php
/**
 * Tests for finding templates the theme lost, suggesting a replacement and telling people.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

use AryanX\Axle\PageTemplateUsage\Missing;
use AryanX\Axle\PageTemplateUsage\Templates;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

final class MissingTest extends TestCase {

	/**
	 * Templates the theme offers per post type.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $offered = array();

	/**
	 * Stored template value => items, per post type.
	 *
	 * @var array<string, array<string, int>>
	 */
	private $counts = array();

	/** @var Missing */
	private $missing;

	protected function setUp(): void {
		parent::setUp();

		$this->offered = array(
			'page' => array(
				'page-templates/landing.php' => 'Landing',
				'page-wide'                  => 'Wide',
			),
			'post' => array(),
		);
		$this->counts  = array(
			'page' => array(
				'default'            => 4,
				'page-wide'          => 2,
				'templates/gone.php' => 3,
			),
			'post' => array(),
		);

		$offered = &$this->offered;
		$counts  = &$this->counts;

		Functions\when( 'wp_get_theme' )->alias(
			static function () use ( &$offered ) {
				$theme = Mockery::mock();
				$theme->shouldReceive( 'get_page_templates' )->andReturnUsing(
					static function ( $post, $type ) use ( &$offered ) {
						return $offered[ $type ] ?? array();
					}
				);
				return $theme;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			static function ( $key ) use ( &$counts ) {
				return $counts[ substr( (string) $key, 7 ) ] ?? false;
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'page' => 'page', 'post' => 'post' ) );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_post_type_object' )->alias(
			static function ( $name ) {
				return new \WP_Post_Type( $name, array( 'labels' => array( 'name' => ucfirst( $name ) . 's' ) ) );
			}
		);
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'get_template' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_list_pluck' )->alias(
			static function ( $list, $field ) {
				return array_map(
					static function ( $item ) use ( $field ) {
						return is_object( $item ) ? $item->$field : $item[ $field ];
					},
					$list
				);
			}
		);
		Functions\when( 'sanitize_email' )->alias( 'trim' );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false !== strpos( (string) $email, '@' );
			}
		);
		Functions\when( 'number_format_i18n' )->alias( 'strval' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_get_schedules' )->justReturn( array( 'daily' => array(), 'hourly' => array() ) );

		$this->missing = new Missing( new Templates() );
	}

	public function test_a_template_is_suggested_by_file_name_when_it_moved_folders(): void {
		$suggestion = $this->missing->suggest( 'page', 'templates/landing.php' );

		$this->assertSame( 'page-templates/landing.php', $suggestion['template'] );
		$this->assertSame( 'same_file', $suggestion['reason'] );
		$this->assertSame( 'Landing', $suggestion['name'] );
	}

	public function test_a_renamed_file_with_the_same_template_name_is_suggested(): void {
		$suggestion = $this->missing->suggest( 'page', 'templates/old-wide-layout.php', 'Wide' );

		$this->assertSame( 'page-wide', $suggestion['template'] );
		$this->assertSame( 'same_name', $suggestion['reason'] );
	}

	public function test_the_default_template_is_the_last_suggestion(): void {
		$suggestion = $this->missing->suggest( 'page', 'templates/something-else.php', 'Something else' );

		$this->assertSame( 'default', $suggestion['template'] );
		$this->assertSame( 'default', $suggestion['reason'] );
	}

	public function test_a_filtered_suggestion_the_theme_does_not_offer_falls_back_to_default(): void {
		Filters\expectApplied( 'axle_page_template_usage_missing_suggestion' )->once()->andReturn(
			array(
				'template' => 'not-in-the-theme',
				'reason'   => 'same_file',
			)
		);

		$suggestion = $this->missing->suggest( 'page', 'templates/landing.php' );

		$this->assertSame( 'default', $suggestion['template'] );
		$this->assertSame( 'default', $suggestion['reason'] );
	}

	public function test_the_fingerprint_changes_when_the_theme_template_list_changes(): void {
		$before = $this->missing->fingerprint();
		$this->assertSame( $before, $this->missing->fingerprint() );

		unset( $this->offered['page']['page-wide'] );
		$this->assertNotSame( $before, $this->missing->fingerprint() );
	}

	public function test_the_first_scan_is_a_baseline_and_announces_nothing(): void {
		Actions\expectDone( 'axle_page_template_usage_scanned' )->once();
		Actions\expectDone( 'axle_page_template_usage_missing_detected' )->never();
		Functions\expect( 'wp_mail' )->never();
		$this->options[ Missing::OPTION_ALERT ] = array(
			'enabled' => true,
			'to'      => 'ops@example.test',
		);

		$state = $this->missing->scan( true, 'first' );

		$this->assertSame( 3, $state['missing']['page']['templates/gone.php']['count'] );
		$this->assertSame( $state, $this->options[ Missing::OPTION_STATE ] );
	}

	public function test_a_new_missing_template_fires_the_action_and_sends_the_email_when_it_is_on(): void {
		$this->missing->scan( true, 'first' );

		// The theme update drops the Wide template. Two pages still use it.
		unset( $this->offered['page']['page-wide'] );

		Actions\expectDone( 'axle_page_template_usage_missing_detected' )->once()->whenHappen(
			function ( $new ) {
				$this->assertSame( array( 'page' ), array_keys( $new ) );
				$this->assertSame( array( 'page-wide' ), array_keys( $new['page'] ) );
				$this->assertSame( 'Wide', $new['page']['page-wide']['name'], 'The name it had while the theme still had it.' );
			}
		);
		Functions\expect( 'wp_mail' )->once()->andReturnUsing(
			function ( $to, $subject, $message ) {
				$this->assertSame( array( 'ops@example.test' ), $to );
				$this->assertStringContainsString( 'page-wide (Pages: 2)', $message );
				$this->assertStringNotContainsString( 'templates/gone.php', $message, 'Only what is new.' );
				return true;
			}
		);
		Actions\expectDone( 'axle_page_template_usage_alert_sent' )->once();
		$this->options[ Missing::OPTION_ALERT ] = array(
			'enabled' => true,
			'to'      => 'ops@example.test',
		);

		$state = $this->missing->scan( false, 'cron' );

		$this->assertArrayHasKey( 'page-wide', $state['missing']['page'] );
		$this->assertArrayHasKey( 'templates/gone.php', $state['missing']['page'], 'Still missing.' );
	}

	public function test_no_email_is_sent_while_the_alert_is_off(): void {
		$this->missing->scan( true, 'first' );
		unset( $this->offered['page']['page-wide'] );

		Actions\expectDone( 'axle_page_template_usage_missing_detected' )->once();
		Functions\expect( 'wp_mail' )->never();

		$this->missing->scan( false, 'cron' );
	}

	public function test_an_unchanged_template_list_stops_after_the_hash_compare(): void {
		$this->missing->scan( true, 'first' );

		// If the scan looked at the pages again it would see this new one and announce it.
		$this->counts['page']['templates/new-gone.php'] = 9;
		Actions\expectDone( 'axle_page_template_usage_scanned' )->never();

		$state = $this->missing->scan( false, 'cron' );

		$this->assertArrayNotHasKey( 'templates/new-gone.php', $state['missing']['page'] );
	}

	public function test_missing_templates_only_cover_the_post_types_asked_for(): void {
		$this->counts['post'] = array( 'templates/secret-post.php' => 5 );
		$this->offered['post'] = array( 'post-wide' => 'Wide post' );

		$all = $this->missing->get_missing();
		$this->assertSame( array( 'page', 'post' ), array_values( array_unique( array_column( $all, 'post_type' ) ) ) );

		$pages = $this->missing->get_missing( array( 'page' ) );
		$this->assertSame( array( 'page' ), array_values( array_unique( array_column( $pages, 'post_type' ) ) ) );
		$this->assertSame( 3, $this->missing->count_missing( array( 'page' ) ) );
		$this->assertSame( 0, $this->missing->count_missing( array() ) );
	}

	public function test_only_a_theme_update_schedules_a_look(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once();

		$this->missing->on_upgrade( null, array( 'type' => 'plugin' ) );
		$this->missing->on_upgrade( null, array( 'type' => 'theme' ) );
	}

	public function test_the_email_address_is_checked_before_it_is_saved(): void {
		$bad = $this->missing->save_alert( true, 'not-an-address' );
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertArrayNotHasKey( Missing::OPTION_ALERT, $this->options );

		$this->assertTrue( $this->missing->save_alert( true, 'ops@example.test' ) );
		$this->assertSame(
			array(
				'enabled' => true,
				'to'      => 'ops@example.test',
			),
			$this->missing->get_alert()
		);
	}

	public function test_the_alert_is_off_by_default(): void {
		$this->assertFalse( $this->missing->get_alert()['enabled'] );
	}
}
