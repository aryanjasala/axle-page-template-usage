<?php
/**
 * Tests for template choices, usage counts and reassigning.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

use AryanX\Axle\PageTemplateUsage\Admin\ListColumn;
use AryanX\Axle\PageTemplateUsage\Templates;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;

final class TemplatesTest extends TestCase {

	/** @var Templates */
	private $templates;

	protected function setUp(): void {
		parent::setUp();

		$theme = Mockery::mock();
		$theme->shouldReceive( 'get_page_templates' )->andReturn(
			array(
				'page-wide'     => 'Wide',
				'page-no-title' => 'No title',
			)
		);
		Functions\when( 'wp_get_theme' )->justReturn( $theme );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'wp_cache_get_last_changed' )->justReturn( '1' );
		// Stored template value => number of posts, as the grouped count query returns it.
		Functions\when( 'wp_cache_get' )->justReturn(
			array(
				'default'                  => 3,
				'page-wide'                => 2,
				'templates/old-landing.php' => 1,
			)
		);
		Functions\when( 'wp_count_posts' )->justReturn(
			(object) array(
				'publish'    => 8,
				'draft'      => 1,
				'trash'      => 4,
				'auto-draft' => 2,
			)
		);

		$this->templates = new Templates();
	}

	public function test_empty_and_default_both_mean_the_default_template(): void {
		$this->assertTrue( $this->templates->is_default( '' ) );
		$this->assertTrue( $this->templates->is_default( 'default' ) );
		$this->assertFalse( $this->templates->is_default( 'page-wide' ) );
	}

	public function test_missing_counts_only_list_templates_the_theme_does_not_have(): void {
		$this->assertSame( array( 'templates/old-landing.php' => 1 ), $this->templates->get_missing_counts( 'page' ) );
	}

	public function test_default_count_includes_posts_without_the_meta_and_skips_trash(): void {
		// 9 live pages (8 published + 1 draft), 3 of them on a named template.
		$this->assertSame( 6, $this->templates->get_default_count( 'page' ) );
	}

	public function test_report_lists_default_then_templates_by_name_then_missing(): void {
		$report = $this->templates->get_report( 'page' );

		$this->assertSame(
			array(
				array( 'default', 6, 'default' ),
				array( 'page-no-title', 0, 'unused' ),
				array( 'page-wide', 2, 'used' ),
				array( 'templates/old-landing.php', 1, 'missing' ),
			),
			array_map(
				static function ( array $row ): array {
					return array( $row['slug'], $row['count'], $row['status'] );
				},
				$report
			)
		);
	}

	public function test_default_filter_matches_posts_with_and_without_the_meta(): void {
		$query = $this->templates->meta_query_for( 'page', Templates::DEFAULT_TEMPLATE );

		$this->assertSame( 'OR', $query['relation'] );
		$this->assertSame( 'NOT EXISTS', $query[0]['compare'] );
		$this->assertSame( array( '', 'default' ), $query[1]['value'] );
	}

	public function test_missing_filter_excludes_every_known_template(): void {
		$query = $this->templates->meta_query_for( 'page', Templates::MISSING );

		$this->assertSame( 'NOT IN', $query[0]['compare'] );
		$this->assertSame( array( 'page-no-title', 'page-wide', '', 'default' ), $query[0]['value'] );
	}

	public function test_reassign_refuses_a_template_the_theme_does_not_have(): void {
		$result = $this->templates->reassign( 'page', Templates::MISSING, 'templates/nope.php' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_page_template_usage_unknown_template', $result->get_error_code() );
	}

	/**
	 * Queues what reassign() will find: batches of IDs, then the count left.
	 *
	 * @param int[][] $batches Post IDs per batch.
	 * @param int     $left    Posts still on the template afterwards.
	 */
	private function queue( array $batches, int $left = 0 ): void {
		Functions\when( 'wp_parse_id_list' )->alias(
			static function ( $ids ) {
				return array_map( 'intval', (array) $ids );
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		foreach ( $batches as $ids ) {
			\WP_Query::$queue[] = array( 'posts' => $ids );
		}
		\WP_Query::$queue[] = array( 'found_posts' => $left );
	}

	public function test_reassign_moves_every_matching_post(): void {
		$this->queue( array( array( 11, 12 ), array() ) );
		Actions\expectDone( 'axle_page_template_usage_before_reassign' )->once()->with( 'page', Templates::MISSING, 'page-wide', 0 );
		Actions\expectDone( 'axle_page_template_usage_reassigned' )->once()->with( 'page', Templates::MISSING, 'page-wide', array( 11, 12 ) );
		Functions\expect( 'update_post_meta' )->twice()->with( Mockery::anyOf( 11, 12 ), '_wp_page_template', 'page-wide' );

		$this->assertSame(
			array(
				'moved'     => 2,
				'skipped'   => 0,
				'remaining' => 0,
			),
			$this->templates->reassign( 'page', Templates::MISSING, 'page-wide' )
		);
	}

	public function test_reassign_to_default_stores_the_default_value(): void {
		$this->queue( array( array( 7 ), array() ) );
		Functions\expect( 'update_post_meta' )->once()->with( 7, '_wp_page_template', 'default' );

		$this->assertSame( 1, $this->templates->reassign( 'page', 'page-wide', '' )['moved'] );
	}

	public function test_reassign_stops_at_the_limit_and_skips_posts_the_user_cannot_edit(): void {
		$this->queue( array( array( 1, 2, 3 ), array( 2, 4, 5 ) ), 4 );
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability, $post_id ) {
				return 2 !== $post_id;
			}
		);
		Functions\expect( 'update_post_meta' )->times( 4 );

		$result = $this->templates->reassign( 'page', Templates::MISSING, 'page-wide', 4, true );

		$this->assertSame( 4, $result['moved'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 3, $result['remaining'], 'The count left leaves out the skipped post.' );
		$this->assertSame( 3, \WP_Query::$made[1]['posts_per_page'], 'The next batch asks for one more, to make up for the skipped post.' );
	}

	public function test_column_is_added_right_after_the_title(): void {
		$columns = ( new ListColumn( $this->templates ) )->add_column(
			array(
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'title', 'axle_page_template_usage', 'date' ), array_keys( $columns ) );
	}
}
