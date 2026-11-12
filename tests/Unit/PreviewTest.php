<?php
/**
 * Tests for the front end template preview.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

use AryanX\Axle\PageTemplateUsage\Preview;
use AryanX\Axle\PageTemplateUsage\Templates;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;

final class PreviewTest extends TestCase {

	/**
	 * Capabilities the current user has.
	 *
	 * @var string[]
	 */
	private $caps = array();

	/**
	 * Pages the user may edit, by ID.
	 *
	 * @var int[]
	 */
	private $editable = array();

	/** @var Preview */
	private $preview;

	protected function setUp(): void {
		parent::setUp();

		$theme = Mockery::mock();
		$theme->shouldReceive( 'get_page_templates' )->andReturn( array( 'page-wide' => 'Wide' ) );
		Functions\when( 'wp_get_theme' )->justReturn( $theme );

		$types = array(
			'page' => new \WP_Post_Type( 'page' ),
			'post' => new \WP_Post_Type( 'post' ),
		);
		Functions\when( 'get_post_type_object' )->alias(
			static function ( $name ) use ( $types ) {
				return $types[ $name ] ?? null;
			}
		);
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				$post             = new \WP_Post();
				$post->ID         = (int) $id;
				$post->post_type  = 1 === (int) $id ? 'page' : 'post';
				$post->post_status = 'publish';
				return $post;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $cap, $id = 0 ) {
				if ( 'edit_post' === $cap ) {
					return in_array( (int) $id, $this->editable, true );
				}
				return in_array( $cap, $this->caps, true );
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $post ) {
				return 'https://example.test/?p=' . $post->ID;
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '&' . http_build_query( $args );
			}
		);

		$this->caps     = array( 'edit_pages' );
		$this->editable = array( 1 );
		$this->preview  = new Preview( new Templates() );
	}

	protected function tearDown(): void {
		unset( $_GET[ Preview::ARG ], $_GET[ Preview::TOKEN_ARG ] );
		parent::tearDown();
	}

	public function test_previewing_needs_access_to_the_post_type_and_the_right_to_edit_the_page(): void {
		$this->assertTrue( Preview::can_preview( 1 ) );

		$this->editable = array();
		$this->assertFalse( Preview::can_preview( 1 ), 'Can edit pages in general, but not this one.' );

		// A post: edit_post is granted, but the user has no edit_posts, so no access to the type.
		$this->editable = array( 2 );
		$this->assertFalse( Preview::can_preview( 2 ) );
	}

	public function test_a_preview_link_is_only_made_for_a_template_the_theme_offers(): void {
		$this->assertStringContainsString( Preview::ARG . '=page-wide', $this->preview->url( 1, 'page-wide' ) );
		$this->assertStringContainsString( Preview::TOKEN_ARG . '=', $this->preview->url( 1, 'default' ) );
		$this->assertSame( '', $this->preview->url( 1, 'templates/not-there.php' ) );
	}

	public function test_no_link_is_made_for_a_page_the_user_cannot_edit(): void {
		$this->editable = array();

		$this->assertSame( '', $this->preview->url( 1, 'page-wide' ) );
		$this->assertSame( '', $this->preview->url( 2, 'page-wide' ) );
	}

	/**
	 * The token from a link this user got for a page and template.
	 *
	 * @param int    $post_id  Page.
	 * @param string $template Template.
	 * @return string
	 */
	private function token( int $post_id, string $template ): string {
		$url = $this->preview->url( $post_id, $template );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		return (string) ( $query[ Preview::TOKEN_ARG ] ?? '' );
	}

	private function request( int $post_id, string $template, string $token ): void {
		$_GET[ Preview::ARG ]       = $template;
		$_GET[ Preview::TOKEN_ARG ] = $token;

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( $post_id );
		Functions\when( 'nocache_headers' )->justReturn( null );
	}

	public function test_a_good_request_swaps_the_stored_template_for_that_page_only(): void {
		$this->request( 1, 'page-wide', $this->token( 1, 'page-wide' ) );
		Actions\expectDone( 'axle_page_template_usage_preview_started' )->once()->with( 1, 'page-wide' );

		$this->preview->maybe_start();

		$this->assertSame( array( 'page-wide' ), $this->preview->filter_template_meta( null, 1, '_wp_page_template' ) );
		$this->assertNull( $this->preview->filter_template_meta( null, 7, '_wp_page_template' ), 'Another page.' );
		$this->assertNull( $this->preview->filter_template_meta( null, 1, '_thumbnail_id' ), 'Another meta key.' );
	}

	public function test_a_nonce_made_for_another_template_is_refused(): void {
		$this->request( 1, 'page-wide', $this->token( 1, 'default' ) );
		Actions\expectDone( 'axle_page_template_usage_preview_started' )->never();

		$this->preview->maybe_start();

		$this->assertNull( $this->preview->filter_template_meta( null, 1, '_wp_page_template' ) );
	}

	public function test_a_user_who_cannot_edit_the_page_gets_the_normal_page(): void {
		$this->request( 1, 'page-wide', $this->token( 1, 'page-wide' ) );
		$this->editable = array();
		Actions\expectDone( 'axle_page_template_usage_preview_started' )->never();

		$this->preview->maybe_start();

		$this->assertNull( $this->preview->filter_template_meta( null, 1, '_wp_page_template' ) );
	}

	public function test_a_template_the_theme_does_not_have_is_refused_even_with_a_valid_nonce(): void {
		$this->request( 1, 'templates/gone.php', $this->token( 1, 'templates/gone.php' ) );
		Actions\expectDone( 'axle_page_template_usage_preview_started' )->never();

		$this->preview->maybe_start();

		$this->assertNull( $this->preview->filter_template_meta( null, 1, '_wp_page_template' ) );
	}

	public function test_a_token_made_for_another_user_or_an_old_one_is_refused(): void {
		$token = $this->token( 1, 'page-wide' );

		// The same link, opened by someone else.
		Functions\when( 'get_current_user_id' )->justReturn( 8 );
		$this->request( 1, 'page-wide', $token );
		Actions\expectDone( 'axle_page_template_usage_preview_started' )->never();
		$this->preview->maybe_start();
		$this->assertNull( $this->preview->filter_template_meta( null, 1, '_wp_page_template' ) );

		// A link that expired a minute ago, signed correctly.
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$expired = ( time() - 60 ) . '.' . hash_hmac( 'sha256', 'axle_page_template_usage_preview|7|1|page-wide|' . ( time() - 60 ), 'test-salt' );
		$this->request( 1, 'page-wide', $expired );
		$this->preview->maybe_start();
		$this->assertNull( $this->preview->filter_template_meta( null, 1, '_wp_page_template' ) );
	}
}
