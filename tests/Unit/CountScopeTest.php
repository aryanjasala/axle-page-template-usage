<?php
/**
 * Tests that usage counts only cover items the user could see.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

use AryanX\Axle\PageTemplateUsage\Templates;
use Brain\Monkey\Functions;

final class CountScopeTest extends TestCase {

	/** @var FakeWpdb */
	private $db;

	/** @var string[] Capabilities of the current user. */
	private $caps = array();

	/** @var Templates */
	private $templates;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->db = new FakeWpdb();
		$wpdb     = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_post_type_object' )->alias(
			static function ( $name ) {
				return new \WP_Post_Type( $name );
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, $this->caps, true );
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->templates = new Templates();
	}

	public function test_an_editor_of_pages_without_private_access_gets_counts_that_leave_other_peoples_private_pages_out(): void {
		$this->caps = array( 'edit_pages', 'edit_others_pages' );
		$this->db->results = array( array( (object) array( 'template' => 'page-wide', 'total' => 2 ) ) );

		$counts = $this->templates->get_usage_counts( 'page' );

		$this->assertSame( array( 'page-wide' => 2 ), $counts );
		$query = $this->db->queries[0];
		$this->assertStringContainsString( 'post_author = 7', $query );
		$this->assertStringContainsString( "( 1 = 1 AND p.post_status <> 'private' )", $query );
		$this->assertStringContainsString( "( 0 = 1 AND p.post_status = 'private' )", $query );
	}

	public function test_a_user_who_can_edit_others_and_read_private_gets_the_plain_count(): void {
		$this->caps = array( 'edit_pages', 'edit_others_pages', 'read_private_pages' );
		$this->db->results = array( array() );

		$this->templates->get_usage_counts( 'page' );

		$this->assertStringNotContainsString( 'post_author', $this->db->queries[0] );
	}

	public function test_a_user_who_can_only_edit_their_own_pages_sees_published_pages_and_their_own(): void {
		$this->caps = array( 'edit_pages' );
		$this->db->results = array( array() );

		$this->templates->get_usage_counts( 'page' );

		$this->assertStringContainsString( "( 0 = 1 AND p.post_status <> 'private' )", $this->db->queries[0] );
		$this->assertStringContainsString( "( 0 = 1 AND p.post_status = 'private' )", $this->db->queries[0] );
	}

	public function test_the_total_behind_the_default_count_is_scoped_the_same_way(): void {
		$this->caps = array( 'edit_pages' );
		$this->db->results = array( array() );
		$this->db->vars    = array( '5' );

		$this->assertSame( 5, $this->templates->get_default_count( 'page' ) );
		$this->assertStringContainsString( 'post_author = 7', $this->db->queries[0] );
	}

	public function test_a_filter_can_widen_the_counts(): void {
		$this->caps = array( 'edit_pages' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'axle_page_template_usage_counts_cover_all' === $hook ? true : $value;
			}
		);
		$this->db->results = array( array() );

		$this->templates->get_usage_counts( 'page' );

		$this->assertStringNotContainsString( 'post_author', $this->db->queries[0] );
	}
}
