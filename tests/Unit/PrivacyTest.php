<?php
/**
 * Tests for the privacy exporter, eraser and policy text.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

use AryanX\Axle\PageTemplateUsage\History;
use AryanX\Axle\PageTemplateUsage\Privacy;
use Brain\Monkey\Functions;

final class PrivacyTest extends TestCase {

	/** @var FakeWpdb */
	private $db;

	/** @var Privacy */
	private $privacy;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->db      = new FakeWpdb();
		$wpdb          = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->privacy = new Privacy( new History() );
		Functions\when( 'get_gmt_from_date' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_exporter_and_eraser_are_registered(): void {
		$this->assertArrayHasKey( 'axle-page-template-usage', $this->privacy->add_exporter( array() ) );
		$this->assertArrayHasKey( 'axle-page-template-usage', $this->privacy->add_eraser( array() ) );
	}

	public function test_export_lists_the_changes_of_the_person(): void {
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 4 ) );
		$this->db->results = array(
			array(
				(object) array(
					'id'           => 9,
					'post_id'      => 5,
					'old_template' => '',
					'new_template' => 'page-wide',
					'source'       => 'editor',
					'created'      => '2026-11-12 10:00:00',
				),
			),
		);

		$out = $this->privacy->export( 'a@example.com' );

		$this->assertTrue( $out['done'] );
		$this->assertCount( 1, $out['data'] );
		$this->assertSame( 'Default', $out['data'][0]['data'][1]['value'] );
		$this->assertSame( 'page-wide', $out['data'][0]['data'][2]['value'] );
		$this->assertStringContainsString( 'user_id = 4', implode( ' ', $this->db->queries ) );
	}

	public function test_export_of_an_unknown_email_is_empty(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$this->privacy->export( 'nobody@example.com' )
		);
	}

	public function test_erase_keeps_the_changes_and_removes_the_person(): void {
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 4 ) );

		$out = $this->privacy->erase( 'a@example.com' );

		$this->assertTrue( $out['items_removed'] );
		$this->assertFalse( $out['items_retained'] );
		$this->assertTrue( $out['done'] );
		$this->assertSame( array( 'user_id' => 0 ), $this->db->updates[0][1] );
	}

	public function test_erase_of_an_unknown_email_removes_nothing(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$this->assertFalse( $this->privacy->erase( 'nobody@example.com' )['items_removed'] );
	}

	public function test_the_policy_text_mentions_the_user_id_and_how_long_entries_are_kept(): void {
		$text = '';
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			static function ( $name, $content ) use ( &$text ) {
				$text = $name . $content;
			}
		);

		$this->privacy->add_policy_text();

		$this->assertStringContainsString( 'the ID of the user who made the change', $text );
		$this->assertStringContainsString( '10000', $text );
		$this->assertStringContainsString( '365', $text );
	}
}
