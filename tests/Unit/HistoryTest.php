<?php
/**
 * Tests for the template history: what is recorded, how it is bounded and when undo is allowed.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

use AryanX\Axle\PageTemplateUsage\History;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class HistoryTest extends TestCase {

	/** @var FakeWpdb */
	private $db;

	/** @var History */
	private $history;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->db      = new FakeWpdb();
		$wpdb          = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->history = new History();

		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				return $id > 0 ? new \WP_Post(
					array(
						'ID'        => $id,
						'post_type' => 99 === $id ? 'revision' : 'page',
					)
				) : null;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * The INSERT queries so far.
	 *
	 * @return string[]
	 */
	private function inserts(): array {
		return array_values(
			array_filter(
				$this->db->queries,
				static function ( string $query ): bool {
					return 0 === strpos( $query, 'INSERT INTO' );
				}
			)
		);
	}

	public function test_a_change_is_written_with_post_old_new_user_and_source(): void {
		$this->history->on_updated( 1, 5, '_wp_page_template', 'page-wide' );
		$this->assertSame( array(), $this->inserts(), 'Nothing is written until the group is flushed.' );

		$this->history->flush();
		$inserts = $this->inserts();

		$this->assertCount( 1, $inserts );
		$this->assertStringContainsString( "( 5, 'page', '', 'page-wide', 7, 'other', ''", $inserts[0] );
	}

	public function test_the_old_value_comes_from_just_before_the_update(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'page-old' );
		$this->history->remember_before( 1, 5, '_wp_page_template' );
		$this->history->on_updated( 1, 5, '_wp_page_template', 'page-new' );
		$this->history->flush();

		$this->assertStringContainsString( "'page-old', 'page-new'", $this->inserts()[0] );
	}

	public function test_deleting_the_meta_is_a_change_to_the_default(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'page-wide' );
		$this->history->remember_before( 1, 5, '_wp_page_template' );
		$this->history->on_deleted( array( 1 ), 5, '_wp_page_template', '' );
		$this->history->flush();

		$this->assertStringContainsString( "'page-wide', ''", $this->inserts()[0] );
	}

	public function test_other_meta_keys_and_same_value_changes_are_ignored(): void {
		$this->history->on_updated( 1, 5, '_thumbnail_id', '12' );
		$this->history->on_added( 1, 5, '_wp_page_template', 'default' );
		$this->history->on_updated( 1, 5, '_wp_page_template', '' );
		$this->history->flush();

		$this->assertSame( array(), $this->inserts(), 'default and an empty value are the same template.' );
	}

	public function test_revisions_are_not_recorded(): void {
		$this->history->on_added( 1, 99, '_wp_page_template', 'page-wide' );
		$this->history->flush();

		$this->assertSame( array(), $this->inserts() );
	}

	public function test_a_context_sets_source_batch_and_user(): void {
		$previous = $this->history->enter( 'theme_switch', 'batch1', 3 );
		$this->history->on_updated( 1, 5, '_wp_page_template', 'page-wide' );
		$this->history->leave( $previous );

		$this->assertStringContainsString( "( 5, 'page', '', 'page-wide', 3, 'theme_switch', 'batch1'", $this->inserts()[0], 'leave() writes what was recorded.' );
	}

	public function test_an_unknown_source_is_stored_as_other(): void {
		$previous = $this->history->enter( 'made-up' );
		$this->history->on_added( 1, 5, '_wp_page_template', 'page-wide' );
		$this->history->leave( $previous );

		$this->assertStringContainsString( "7, 'other'", $this->inserts()[0] );
	}

	public function test_the_should_record_filter_can_skip_an_entry(): void {
		Filters\expectApplied( 'axle_page_template_usage_history_should_record' )->once()->andReturn( false );
		$this->history->on_added( 1, 5, '_wp_page_template', 'page-wide' );
		$this->history->flush();

		$this->assertSame( array(), $this->inserts() );
	}

	public function test_recording_fires_an_action(): void {
		Actions\expectDone( 'axle_page_template_usage_history_recorded' )->once();
		$this->history->on_added( 1, 5, '_wp_page_template', 'page-wide' );
	}

	public function test_a_hundred_changes_are_written_by_themselves_in_one_query(): void {
		for ( $i = 0; $i < 100; $i++ ) {
			$this->history->on_added( 1, 5, '_wp_page_template', 't' . $i );
		}

		$this->assertCount( 1, $this->inserts() );
		$this->assertSame( 99, substr_count( $this->inserts()[0], '), (' ) );
	}

	public function test_the_table_is_trimmed_when_the_ids_cross_a_multiple_of_fifty(): void {
		$this->db->insert_id = 48;
		$this->db->vars      = array( '12' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->history->on_added( 1, 5, '_wp_page_template', 't' . $i );
		}
		$this->history->flush();

		$this->assertContains( 'DELETE FROM wp_axle_ptu_history WHERE id <= 12', $this->db->queries );
		$this->assertStringContainsString( 'ORDER BY id DESC LIMIT 1 OFFSET 10000', implode( ' ', $this->db->queries ), 'The default cap is 10000 entries.' );
	}

	public function test_the_table_is_left_alone_between_multiples_of_fifty(): void {
		$this->db->insert_id = 10;
		$this->history->on_added( 1, 5, '_wp_page_template', 'page-wide' );
		$this->history->flush();

		$this->assertCount( 1, $this->db->queries );
	}

	public function test_the_cap_can_be_changed_but_never_below_a_hundred(): void {
		Filters\expectApplied( 'axle_page_template_usage_history_cap' )->once()->andReturn( 5 );
		$this->assertSame( 100, History::cap() );
	}

	public function test_the_retention_default_is_a_year_and_zero_turns_it_off(): void {
		$this->assertSame( 365, History::retention_days() );
		Filters\expectApplied( 'axle_page_template_usage_history_retention_days' )->once()->andReturn( -4 );
		$this->assertSame( 0, History::retention_days() );
	}

	public function test_purge_deletes_old_entries_then_trims(): void {
		$this->history->purge();

		$this->assertStringContainsString( 'DELETE FROM wp_axle_ptu_history WHERE created <', $this->db->queries[0] );
		$this->assertStringContainsString( 'OFFSET 10000', $this->db->queries[1] );
	}

	/**
	 * A history row.
	 *
	 * @param array<string, mixed> $fields Overrides.
	 * @return object
	 */
	private function entry( array $fields = array() ): object {
		return (object) array_merge(
			array(
				'id'           => 1,
				'post_id'      => 5,
				'post_type'    => 'page',
				'old_template' => 'page-old',
				'new_template' => 'page-new',
				'undone'       => 0,
			),
			$fields
		);
	}

	public function test_an_entry_can_be_undone_while_the_page_still_has_its_template(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'page-new' );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertNull( $this->history->blocker( $this->entry() ) );
	}

	public function test_an_entry_is_blocked_when_the_page_changed_again(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'page-third' );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertSame(
			array(
				'code'    => 'changed',
				'current' => 'page-third',
			),
			$this->history->blocker( $this->entry() )
		);
	}

	public function test_default_and_an_empty_value_count_as_the_same_template(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertNull( $this->history->blocker( $this->entry( array( 'new_template' => 'default' ) ) ) );
	}

	public function test_an_entry_is_blocked_when_undone_gone_or_not_editable(): void {
		$this->assertSame( 'undone', $this->history->blocker( $this->entry( array( 'undone' => 1 ) ) )['code'] );
		$this->assertSame( 'post_gone', $this->history->blocker( $this->entry( array( 'post_id' => 0 ) ) )['code'] );

		Functions\when( 'get_post_meta' )->justReturn( 'page-new' );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( 'cannot_edit', $this->history->blocker( $this->entry() )['code'] );
		$this->assertNull( $this->history->blocker( $this->entry(), false ), 'WP-CLI has no current user to check.' );
	}

	public function test_someone_who_may_not_edit_the_page_is_not_told_its_current_template(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'page-secret' );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame(
			array(
				'code'    => 'cannot_edit',
				'current' => '',
			),
			$this->history->blocker( $this->entry() ),
			'Not "changed", which would name the template the page uses now.'
		);
	}

	public function test_the_can_undo_filter_has_the_last_word(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'page-new' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Filters\expectApplied( 'axle_page_template_usage_can_undo' )->once()->andReturn( false );

		$this->assertSame( 'blocked', $this->history->blocker( $this->entry() )['code'] );
	}

	public function test_blocker_messages_name_both_templates(): void {
		$message = $this->history->blocker_message(
			array(
				'code'    => 'changed',
				'current' => 'page-third',
			),
			$this->entry()
		);

		$this->assertStringContainsString( 'page-third', $message );
		$this->assertStringContainsString( 'page-new', $message );
	}

	public function test_undoing_an_entry_puts_the_old_template_back_and_marks_it_undone(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'page-new' );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->db->row = $this->entry();
		Functions\expect( 'update_post_meta' )->once()->with( 5, '_wp_page_template', 'page-old' );
		Actions\expectDone( 'axle_page_template_usage_undone' )->once();

		$this->assertNull( $this->history->undo_entry( 1 ) );
		$this->assertContains( 'UPDATE wp_axle_ptu_history SET undone = 1 WHERE id IN ( 1 )', $this->db->queries );
	}

	public function test_undoing_back_to_the_default_stores_the_default_value(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'page-new' );
		$this->db->row = $this->entry( array( 'old_template' => '' ) );
		Functions\expect( 'update_post_meta' )->once()->with( 5, '_wp_page_template', 'default' );

		$this->assertNull( $this->history->undo_entry( 1, false ) );
	}

	public function test_undoing_a_blocked_entry_changes_nothing_and_says_why(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'page-third' );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->db->row = $this->entry();
		Functions\expect( 'update_post_meta' )->never();

		$this->assertSame( 'changed', $this->history->undo_entry( 1 )['code'] );
	}

	public function test_an_unknown_entry_is_an_error(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->history->undo_entry( 404 ) );
	}

	public function test_an_unknown_batch_is_an_error(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->history->undo_batch( 'nope' ) );
	}

	public function test_a_batch_undoes_what_it_can_and_counts_the_rest_as_skipped(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'update_meta_cache' )->justReturn( array() );
		Functions\when( 'wp_list_pluck' )->alias(
			static function ( $list, $field ) {
				return array_map(
					static function ( $item ) use ( $field ) {
						return $item->$field;
					},
					$list
				);
			}
		);
		// Post 5 still has the template the entry set, post 6 was changed since.
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id ) {
				return 5 === $id ? 'page-new' : 'page-other';
			}
		);
		$this->db->row     = (object) array(
			'source'  => 'bulk_move',
			'user_id' => 1,
			'created' => '2026-11-12 10:00:00',
			'total'   => 2,
			'open'    => 2,
		);
		$this->db->results = array(
			array( $this->entry( array( 'id' => 11 ) ), $this->entry( array( 'id' => 10, 'post_id' => 6 ) ) ),
			array(),
		);
		$this->db->vars    = array();
		Functions\expect( 'update_post_meta' )->once()->with( 5, '_wp_page_template', 'page-old' );
		Actions\expectDone( 'axle_page_template_usage_batch_undone' )->once();

		$result = $this->history->undo_batch( 'abc' );

		$this->assertSame( 1, $result['undone'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertSame( 'aaaaaaaabbbbccccddddeeeeeeeeeeee', $result['batch'], 'The undo is recorded under its own batch.' );
	}

	public function test_erasing_a_user_sets_the_user_to_zero(): void {
		$this->assertSame( 3, $this->history->erase_user( 4 ) );
		$this->assertSame( array( 'wp_axle_ptu_history', array( 'user_id' => 0 ), array( 'user_id' => 4 ) ), $this->db->updates[0] );
		$this->assertSame( 0, $this->history->erase_user( 0 ) );
	}

	public function test_a_post_filter_of_zero_is_no_filter_but_a_user_of_zero_is(): void {
		Functions\when( 'get_gmt_from_date' )->returnArg();
		$this->history->query(
			array(
				'post_id' => 0,
				'user_id' => 0,
				'source'  => 'cli',
			)
		);

		$sql = $this->db->queries[0];
		$this->assertStringContainsString( '( 1 = 1 OR post_id = 0 )', $sql, 'A post of 0 skips the filter.' );
		$this->assertStringContainsString( '( 0 = 1 OR user_id = 0 )', $sql, 'A user of 0 is the System filter.' );
		$this->assertStringContainsString( "( 'cli' = '' OR source = 'cli' )", $sql );
	}

	public function test_no_user_argument_means_any_user(): void {
		$this->history->query( array() );

		$this->assertStringContainsString( '( 1 = 1 OR user_id = 0 )', $this->db->queries[0] );
	}

	public function test_query_filters_by_template_text_and_dates(): void {
		Functions\when( 'get_gmt_from_date' )->returnArg();
		$this->history->query(
			array(
				'template' => 'wide_page',
				'from'     => '2026-11-01',
				'to'       => '2026-11-12',
			)
		);

		$sql = $this->db->queries[0];
		$this->assertStringContainsString( "old_template LIKE '%wide\\_page%'", $sql );
		$this->assertStringContainsString( "created >= '2026-11-01 00:00:00'", $sql );
		$this->assertStringContainsString( "created <= '2026-11-12 23:59:59'", $sql );
	}

	public function test_query_keeps_only_the_post_types_given_and_an_empty_list_keeps_none(): void {
		$this->history->query( array( 'post_types' => array( 'page', 'post' ) ) );
		$this->history->query( array( 'post_types' => array() ) );
		$this->history->query( array() );

		$this->assertStringContainsString( "( 0 = 1 OR post_type IN ( 'page', 'post' ) )", $this->db->queries[0] );
		$this->assertStringContainsString( "( 0 = 1 OR post_type IN ( '' ) )", $this->db->queries[2], 'Every entry has a post type, so this matches none.' );
		$this->assertStringContainsString( "( 1 = 1 OR post_type IN ( '' ) )", $this->db->queries[4], 'No list means every post type, for WP-CLI and the privacy exporter.' );
	}

	public function test_a_batch_only_counts_and_undoes_the_post_types_given(): void {
		Functions\when( 'update_meta_cache' )->justReturn( array() );
		$this->db->row     = (object) array(
			'source'  => 'theme_switch',
			'user_id' => 1,
			'created' => '2026-11-12 10:00:00',
			'total'   => 1,
			'open'    => 1,
		);
		$this->db->results = array( array() );

		$this->history->undo_batch( 'abc', 0, true, array( 'page' ) );

		$this->assertStringContainsString( "WHERE batch = 'abc' AND ( 0 = 1 OR post_type IN ( 'page' ) )", $this->db->queries[0], 'The batch summary.' );
		$this->assertStringContainsString( "AND id < 9223372036854775807 AND ( 0 = 1 OR post_type IN ( 'page' ) ) ORDER BY id DESC", $this->db->queries[1], 'The entries to undo.' );
	}

	public function test_a_batch_with_no_entries_of_the_post_types_given_is_unknown(): void {
		$this->db->row = (object) array( 'total' => 0 );

		$this->assertInstanceOf( \WP_Error::class, $this->history->undo_batch( 'abc', 0, true, array( 'page' ) ) );
		$this->assertNull( $this->history->get_batch( 'abc', array() ) );
	}

	public function test_the_users_filter_only_lists_people_with_changes_of_the_post_types_given(): void {
		$this->history->get_user_ids( 50, array( 'page' ) );

		$this->assertStringContainsString( "WHERE user_id > 0 AND ( 0 = 1 OR post_type IN ( 'page' ) ) ORDER BY user_id LIMIT 50", $this->db->queries[0] );
	}
}
