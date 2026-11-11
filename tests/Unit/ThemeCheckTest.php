<?php
/**
 * Tests for the theme check: which templates a theme offers, and the saved mapping.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

use AryanX\Axle\PageTemplateUsage\History;
use AryanX\Axle\PageTemplateUsage\Templates;
use AryanX\Axle\PageTemplateUsage\ThemeCheck;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

final class ThemeCheckTest extends TestCase {

	/** @var ThemeCheck */
	private $check;

	/** @var string */
	private $dir;

	protected function setUp(): void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/aptu-theme-' . uniqid();
		mkdir( $this->dir );
		file_put_contents(
			$this->dir . '/theme.json',
			json_encode(
				array(
					'customTemplates' => array(
						array(
							'name'      => 'landing',
							'title'     => 'Landing',
						),
						array(
							'name'      => 'note',
							'title'     => 'Note',
							'postTypes' => array( 'post' ),
						),
					),
				)
			)
		);

		Functions\when( 'trailingslashit' )->alias(
			static function ( $path ) {
				return rtrim( $path, '/' ) . '/';
			}
		);
		Functions\when( 'wp_json_file_decode' )->alias(
			static function ( $file ) {
				return json_decode( (string) file_get_contents( $file ), true );
			}
		);
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_default_block_template_types' )->justReturn( array() );

		$this->check = new ThemeCheck( new Templates(), new History() );
	}

	protected function tearDown(): void {
		@unlink( $this->dir . '/theme.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		@rmdir( $this->dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tearDown();
	}

	/**
	 * A theme with PHP templates, theme.json from the temp folder, and no parent.
	 *
	 * @param array<string, array<string, string>> $files PHP templates by post type.
	 * @return \WP_Theme
	 */
	private function theme( array $files = array() ) {
		$theme = Mockery::mock( 'WP_Theme' );
		$theme->shouldReceive( 'get_post_templates' )->andReturn( $files );
		$theme->shouldReceive( 'parent' )->andReturn( false );
		$theme->shouldReceive( 'get_stylesheet_directory' )->andReturn( $this->dir );
		$theme->shouldReceive( 'get_stylesheet' )->andReturn( 'other' );

		return $theme;
	}

	public function test_a_theme_offers_its_php_templates_and_its_json_custom_templates(): void {
		$theme = $this->theme( array( 'page' => array( 'templates/wide.php' => 'Wide' ) ) );

		$this->assertSame(
			array(
				'landing'            => 'Landing',
				'templates/wide.php' => 'Wide',
			),
			$this->check->get_theme_templates( $theme, 'page' ),
			'Custom templates default to pages, and the note template is for posts only.'
		);
		$this->assertSame( array( 'note' => 'Note' ), $this->check->get_theme_templates( $theme, 'post' ) );
	}

	public function test_the_active_themes_block_templates_are_kept_out_of_the_answer(): void {
		Filters\expectAdded( 'current_theme_supports-block-templates' )->once()->with( '__return_false', 99 );

		$this->check->get_theme_templates( $this->theme(), 'page' );

		$this->assertFalse( has_filter( 'current_theme_supports-block-templates', '__return_false' ), 'The filter is removed again.' );
	}

	public function test_templates_saved_in_the_site_editor_count_but_core_defaults_do_not(): void {
		Functions\when( 'get_default_block_template_types' )->justReturn( array( 'page' => array(), 'single' => array() ) );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id ) {
				return 3 === $id ? true : '';
			}
		);
		Functions\when( 'get_posts' )->justReturn(
			array(
				new \WP_Post(
					array(
						'ID'         => 1,
						'post_name'  => 'my-landing',
						'post_title' => 'My landing',
					)
				),
				new \WP_Post(
					array(
						'ID'         => 2,
						'post_name'  => 'page',
						'post_title' => 'Page',
					)
				),
				new \WP_Post(
					array(
						'ID'         => 3,
						'post_name'  => 'suggested',
						'post_title' => 'Suggested',
					)
				),
			)
		);

		$templates = $this->check->get_theme_templates( $this->theme(), 'page' );

		$this->assertSame( 'My landing', $templates['my-landing'] );
		$this->assertArrayNotHasKey( 'page', $templates, 'A saved copy of a default template is not a custom template.' );
		$this->assertArrayNotHasKey( 'suggested', $templates, 'Neither is a template WordPress only suggested.' );
	}

	public function test_a_saved_template_tied_to_other_post_types_by_theme_json_is_not_offered_for_pages(): void {
		Functions\when( 'get_default_block_template_types' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_posts' )->justReturn( array( new \WP_Post( array( 'ID' => 1, 'post_name' => 'note', 'post_title' => 'Note edited' ) ) ) );

		$this->assertArrayNotHasKey( 'note', $this->check->get_theme_templates( $this->theme(), 'page' ) );
		$this->assertSame( 'Note edited', $this->check->get_theme_templates( $this->theme(), 'post' )['note'] );
	}

	public function test_the_theme_templates_filter_can_add_or_remove_templates(): void {
		Filters\expectApplied( 'axle_page_template_usage_theme_templates' )->once()->andReturn( array( 'x' => 'X' ) );

		$this->assertSame( array( 'x' => 'X' ), $this->check->get_theme_templates( $this->theme(), 'page' ) );
	}

	public function test_the_saved_mapping_is_read_from_the_option_and_can_be_filtered(): void {
		$this->options[ ThemeCheck::OPTION_MAPS ] = array(
			'other' => array(
				'map'  => array( 'page' => array( 'old.php' => 'landing' ) ),
				'time' => 1,
				'user' => 2,
			),
		);

		$this->assertSame( array( 'page' => array( 'old.php' => 'landing' ) ), $this->check->get_mapping( 'other' ) );
		$this->assertSame( array(), $this->check->get_mapping( 'unknown' ) );
		$this->assertSame(
			array(
				'time' => 1,
				'user' => 2,
			),
			$this->check->get_mapping_meta( 'other' )
		);

		Filters\expectApplied( 'axle_page_template_usage_theme_mapping' )->once()->with( array(), 'unknown' )->andReturn( array( 'page' => array( 'missing' => 'default' ) ) );
		$this->assertSame( array( 'page' => array( 'missing' => 'default' ) ), $this->check->get_mapping( 'unknown' ) );
	}

	public function test_the_batch_size_defaults_to_500_and_is_filterable(): void {
		$this->assertSame( 500, ThemeCheck::batch_size( 'other' ) );
		Filters\expectApplied( 'axle_page_template_usage_switch_batch_size' )->once()->with( 500, 'other' )->andReturn( 0 );
		$this->assertSame( 1, ThemeCheck::batch_size( 'other' ), 'A batch is at least one item.' );
	}

	public function test_the_capability_defaults_to_switch_themes(): void {
		$this->assertSame( 'switch_themes', ThemeCheck::capability() );
	}

	public function test_checking_the_active_theme_or_an_unknown_one_is_an_error(): void {
		Functions\when( 'get_stylesheet' )->justReturn( 'active' );
		Functions\when( 'wp_get_themes' )->justReturn( array() );

		$this->assertSame( 'axle_page_template_usage_active_theme', $this->check->check( 'active' )->get_error_code() );
		$this->assertSame( 'axle_page_template_usage_unknown_theme', $this->check->check( 'missing' )->get_error_code() );
	}

	public function test_clearing_a_mapping_removes_only_that_theme(): void {
		$this->options[ ThemeCheck::OPTION_MAPS ] = array(
			'a' => array( 'map' => array( 'page' => array( 'x' => 'default' ) ) ),
			'b' => array( 'map' => array( 'page' => array( 'y' => 'default' ) ) ),
		);

		$this->check->clear_mapping( 'a' );

		$this->assertSame( array( 'b' ), array_keys( $this->options[ ThemeCheck::OPTION_MAPS ] ) );
	}

	public function test_batch_undo_of_a_switch_stops_the_follow_up_but_a_partial_undo_does_not(): void {
		$this->options[ ThemeCheck::OPTION_RESULT ] = array(
			'batch'      => 'b1',
			'stylesheet' => 'other',
		);
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );

		$this->check->on_batch_undone( 'b1', array( 'remaining' => 5 ) );
		$this->assertArrayHasKey( ThemeCheck::OPTION_RESULT, $this->options );

		$this->check->on_batch_undone( 'b1', array( 'remaining' => 0 ) );
		$this->assertArrayNotHasKey( ThemeCheck::OPTION_RESULT, $this->options );
	}
}
