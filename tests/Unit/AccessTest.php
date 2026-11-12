<?php
/**
 * Tests for who sees what: every screen and ability only covers the post types
 * the current user can edit.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

use AryanX\Axle\PageTemplateUsage\Abilities;
use AryanX\Axle\PageTemplateUsage\Admin\ReportPage;
use AryanX\Axle\PageTemplateUsage\History;
use AryanX\Axle\PageTemplateUsage\Templates;
use AryanX\Axle\PageTemplateUsage\ThemeCheck;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AccessTest extends TestCase {

	/**
	 * Capabilities the current user has.
	 *
	 * @var string[]
	 */
	private $caps = array();

	/** @var FakeWpdb */
	private $db;

	/** @var Abilities */
	private $abilities;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->db = new FakeWpdb();
		$wpdb     = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// page and post as in core, and a private post type with its own capabilities, like a shop's products.
		$types = array(
			'page'       => new \WP_Post_Type( 'page' ),
			'post'       => new \WP_Post_Type( 'post' ),
			'secret_doc' => new \WP_Post_Type( 'secret_doc', array( 'cap' => array( 'edit_posts' => 'edit_secret_docs' ) ) ),
		);

		Functions\when( 'get_post_type_object' )->alias(
			static function ( $name ) use ( $types ) {
				return $types[ $name ] ?? null;
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array_combine( array_keys( $types ), array_keys( $types ) ) );
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, $this->caps, true );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);

		$history         = new History();
		$templates       = new Templates( $history );
		$this->abilities = new Abilities( $templates, new ThemeCheck( $templates, $history ), $history );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_a_post_type_needs_the_report_capability_and_edit_posts_on_that_type(): void {
		$this->caps = array( 'edit_pages' );
		$this->assertTrue( ReportPage::can_access( 'page' ) );
		$this->assertFalse( ReportPage::can_access( 'post' ), 'No edit_posts.' );
		$this->assertFalse( ReportPage::can_access( 'secret_doc' ), 'No edit_secret_docs.' );
		$this->assertFalse( ReportPage::can_access( 'nope' ), 'A post type that does not exist.' );
		$this->assertFalse( ReportPage::can_access( '' ) );

		$this->caps = array( 'edit_posts', 'edit_secret_docs' );
		$this->assertFalse( ReportPage::can_access( 'secret_doc' ), 'No report capability.' );
	}

	public function test_the_accessible_post_types_are_the_ones_the_user_can_edit(): void {
		$this->caps = array( 'edit_pages', 'edit_posts' );

		$this->assertSame( array( 'page', 'post' ), ReportPage::accessible_types() );
	}

	public function test_a_filter_has_the_last_word_on_a_post_type(): void {
		$this->caps = array( 'edit_pages' );
		Filters\expectApplied( 'axle_page_template_usage_can_access_post_type' )->once()->with( true, 'page' )->andReturn( false );

		$this->assertFalse( ReportPage::can_access( 'page' ) );
	}

	public function test_checking_a_theme_also_needs_the_theme_check_capability(): void {
		$this->caps = array( 'edit_pages' );
		$this->assertFalse( ReportPage::can_check_themes() );

		$this->caps = array( 'edit_pages', 'switch_themes' );
		$this->assertTrue( ReportPage::can_check_themes() );
	}

	public function test_the_report_and_reassign_abilities_need_edit_posts_on_the_post_type(): void {
		$this->caps = array( 'edit_pages' );

		$this->assertTrue( $this->abilities->can_use_post_type( array() ), 'The default post type is page.' );
		$this->assertTrue( $this->abilities->can_use_post_type( array( 'post_type' => 'page' ) ) );
		$this->assertFalse( $this->abilities->can_use_post_type( array( 'post_type' => 'secret_doc' ) ) );
		$this->assertFalse( $this->abilities->can_use_post_type( array( 'post_type' => 'nope' ) ) );
	}

	public function test_another_spelling_of_a_post_type_is_checked_as_the_ability_reads_it(): void {
		$this->caps = array( 'edit_pages' );

		// The ability reads the post type through sanitize_key(), so SECRET_DOC means secret_doc.
		$this->assertFalse( $this->abilities->can_use_post_type( array( 'post_type' => 'SECRET_DOC' ) ) );
		$this->assertFalse( $this->abilities->can_use_post_type( array( 'post_type' => ' secret_doc' ) ) );

		$this->caps[] = 'edit_secret_docs';
		$this->assertTrue( $this->abilities->can_use_post_type( array( 'post_type' => 'SECRET_DOC' ) ) );
	}

	public function test_the_theme_check_ability_needs_switch_themes_and_access_to_a_named_post_type(): void {
		$this->caps = array( 'edit_pages' );
		$this->assertFalse( $this->abilities->can_check_theme( array( 'theme' => 'other' ) ), 'No switch_themes.' );

		$this->caps[] = 'switch_themes';
		$this->assertTrue( $this->abilities->can_check_theme( array( 'theme' => 'other' ) ), 'Every post type the user can edit.' );
		$this->assertTrue( $this->abilities->can_check_theme( array( 'theme' => 'other', 'post_type' => 'page' ) ) );
		$this->assertFalse( $this->abilities->can_check_theme( array( 'theme' => 'other', 'post_type' => 'Secret_Doc' ) ) );
	}

	public function test_the_history_ability_only_returns_post_types_the_user_can_edit(): void {
		$this->caps = array( 'edit_pages' );
		$this->assertTrue( $this->abilities->can_read_history() );

		$this->abilities->get_history( array() );

		$this->assertStringContainsString( "( 0 = 1 OR post_type IN ( 'page' ) )", $this->db->queries[0] );
	}

	public function test_the_history_ability_returns_nothing_to_a_user_who_can_edit_no_post_type(): void {
		$this->caps = array( 'edit_pages' );
		Filters\expectApplied( 'axle_page_template_usage_can_access_post_type' )->andReturn( false );

		$this->abilities->get_history( array() );

		$this->assertStringContainsString( "( 0 = 1 OR post_type IN ( '' ) )", $this->db->queries[0] );
	}
}
