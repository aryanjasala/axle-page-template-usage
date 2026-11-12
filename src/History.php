<?php
/**
 * Template history: every change of a post's template, and undoing it.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

defined( 'ABSPATH' ) || exit;

/**
 * Records changes of the `_wp_page_template` meta in one small table, keeps it
 * bounded, and undoes single changes or a whole batch.
 *
 * Rows are written in groups (one query per 100 changes, and one at the end of
 * the request), so a move of thousands of pages stays cheap. Only the newest
 * entries are kept (see cap()), and entries older than the retention period go
 * once a day.
 */
final class History implements Service {

	public const TABLE      = 'axle_ptu_history';
	public const DB_VERSION = '1';
	public const OPTION_DB  = 'axle_page_template_usage_db_version';
	public const CRON       = 'axle_page_template_usage_purge';

	public const SOURCES = array( 'editor', 'quick_edit', 'bulk_move', 'theme_switch', 'cli', 'ability', 'undo', 'other' );

	private const DEFAULT_CAP       = 10000;
	private const DEFAULT_RETENTION = 365;
	private const FLUSH_AT          = 100;
	private const TRIM_EVERY        = 50;

	/**
	 * Entries waiting to be written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $buffer = array();

	/**
	 * Template a post had just before the meta changed, by post ID.
	 *
	 * @var array<int, string>
	 */
	private array $before = array();

	/**
	 * Source, batch and user the next changes belong to, set by enter().
	 *
	 * @var array{source: string, batch: string, user: int|null}|null
	 */
	private ?array $context = null;

	/**
	 * Hooks in the recorder, the cleanup and the table upgrade.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_install' ), 5 );
		add_action( 'update_post_meta', array( $this, 'remember_before' ), 10, 3 );
		add_action( 'delete_post_meta', array( $this, 'remember_before' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'on_added' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_updated' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_deleted' ), 10, 4 );
		add_action( 'shutdown', array( $this, 'flush' ) );
		add_action( 'deleted_user', array( $this, 'anonymize_user' ) );
		add_action( self::CRON, array( $this, 'purge' ) );
	}

	/**
	 * Table name for this site.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates or upgrades the table, and schedules the daily cleanup.
	 *
	 * Runs on `init`, so sites that update the plugin without activating it
	 * get the table too. It costs one autoloaded option read when nothing is due.
	 */
	public function maybe_install(): void {
		if ( get_option( self::OPTION_DB ) !== self::DB_VERSION ) {
			self::install();
		}

		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Creates the history table.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				post_type varchar(20) NOT NULL DEFAULT '',
				old_template varchar(255) NOT NULL DEFAULT '',
				new_template varchar(255) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(20) NOT NULL DEFAULT 'other',
				batch varchar(36) NOT NULL DEFAULT '',
				undone tinyint(1) unsigned NOT NULL DEFAULT 0,
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id,id),
				KEY batch (batch,id),
				KEY user_id (user_id),
				KEY created (created)
			) {$collate};"
		);

		update_option( self::OPTION_DB, self::DB_VERSION, true );
	}

	/**
	 * Drops the table and everything else the history stored. Used by uninstall.php.
	 */
	public static function uninstall(): void {
		global $wpdb;

		// Schema change on uninstall, table name is built from the prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );
		delete_option( self::OPTION_DB );
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Most entries kept per site.
	 *
	 * @return int
	 */
	public static function cap(): int {
		/**
		 * Filters how many template changes the history keeps per site.
		 *
		 * The oldest entries are dropped first. Needs a few percent of slack:
		 * the table is trimmed every 50 entries, not on every one.
		 *
		 * @param int $cap Default 10000.
		 */
		return max( 100, (int) apply_filters( 'axle_page_template_usage_history_cap', self::DEFAULT_CAP ) );
	}

	/**
	 * Days an entry is kept, 0 for no age limit.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		/**
		 * Filters how many days the history keeps an entry.
		 *
		 * The entry count is capped anyway (see axle_page_template_usage_history_cap).
		 * Return 0 to keep entries until the cap pushes them out.
		 *
		 * @param int $days Default 365.
		 */
		return max( 0, (int) apply_filters( 'axle_page_template_usage_history_retention_days', self::DEFAULT_RETENTION ) );
	}

	/**
	 * Whether a stored template value means the default template.
	 *
	 * @param string $slug Stored value.
	 * @return bool
	 */
	private static function is_default( string $slug ): bool {
		return '' === $slug || Templates::DEFAULT_TEMPLATE === $slug;
	}

	/**
	 * Stored value with every spelling of "default" made the same.
	 *
	 * @param string $slug Stored value.
	 * @return string
	 */
	private static function normalize( string $slug ): string {
		return self::is_default( $slug ) ? Templates::DEFAULT_TEMPLATE : $slug;
	}

	/**
	 * Values for the post type filter in the queries below: `( %d = 1 OR post_type IN ( %s, ... ) )`.
	 *
	 * @param string[]|null $post_types Post types to keep, null for every one, an empty list for none.
	 * @return array{0: int, 1: string[]} 1 to skip the filter or 0 to apply it, and the post types to match (at least one).
	 */
	private static function post_type_filter( ?array $post_types ): array {
		if ( null === $post_types ) {
			return array( 1, array( '' ) );
		}

		$post_types = array_values( array_unique( array_map( 'strval', $post_types ) ) );

		// Every entry has a post type, so an empty name matches none.
		return array( 0, $post_types ? $post_types : array( '' ) );
	}

	/**
	 * A new batch ID, to tie the changes of one move or one theme switch together.
	 *
	 * @return string 32 characters.
	 */
	public function new_batch(): string {
		return str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * Marks the changes that follow as coming from one place, until leave().
	 *
	 * @param string   $source Source key, one of SOURCES.
	 * @param string   $batch  Batch ID, or an empty string for none.
	 * @param int|null $user   User ID to record, or null for the current user.
	 * @return array{source: string, batch: string, user: int|null}|null The previous context, to hand to leave().
	 */
	public function enter( string $source, string $batch = '', ?int $user = null ): ?array {
		$previous      = $this->context;
		$this->context = array(
			'source' => in_array( $source, self::SOURCES, true ) ? $source : 'other',
			'batch'  => $batch,
			'user'   => $user,
		);

		return $previous;
	}

	/**
	 * Ends enter(), and writes what was recorded since.
	 *
	 * @param array{source: string, batch: string, user: int|null}|null $previous What enter() returned.
	 */
	public function leave( ?array $previous ): void {
		$this->context = $previous;
		$this->flush();
	}

	/**
	 * Where a change comes from when no code set a context.
	 *
	 * @return string One of SOURCES.
	 */
	private function detect_source(): string {
		global $pagenow;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		// Only reads which request this is, nothing is saved from it.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( wp_doing_ajax() && isset( $_POST['action'] ) && 'inline-save' === $_POST['action'] ) {
			return 'quick_edit';
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
			return 'editor';
		}

		return 'other';
	}

	/**
	 * Remembers the template a post has right before its meta changes.
	 *
	 * @param int|int[] $meta_id   Meta ID or IDs.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public function remember_before( $meta_id, $object_id, $meta_key ): void {
		if ( Templates::META_KEY === $meta_key ) {
			$this->before[ (int) $object_id ] = (string) get_post_meta( (int) $object_id, Templates::META_KEY, true );
		}
	}

	/**
	 * A template was set on a post that had none.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public function on_added( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( Templates::META_KEY === $meta_key ) {
			$this->record( (int) $object_id, '', is_scalar( $meta_value ) ? (string) $meta_value : '' );
		}
	}

	/**
	 * A post's template changed.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public function on_updated( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( Templates::META_KEY !== $meta_key ) {
			return;
		}

		$object_id = (int) $object_id;
		$old       = $this->before[ $object_id ] ?? '';
		unset( $this->before[ $object_id ] );
		$this->record( $object_id, $old, is_scalar( $meta_value ) ? (string) $meta_value : '' );
	}

	/**
	 * A post's template meta was deleted, which means the default template.
	 *
	 * @param int|int[] $meta_ids   Meta IDs.
	 * @param int       $object_id  Post ID.
	 * @param string    $meta_key   Meta key.
	 * @param mixed     $meta_value Deleted value, if one was given.
	 */
	public function on_deleted( $meta_ids, $object_id, $meta_key, $meta_value ): void {
		if ( Templates::META_KEY !== $meta_key ) {
			return;
		}

		$object_id = (int) $object_id;
		$old       = $this->before[ $object_id ] ?? '';
		unset( $this->before[ $object_id ] );
		$this->record( $object_id, $old, '' );
	}

	/**
	 * Queues one entry.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $old     Template before, empty for the default.
	 * @param string $current Template after, empty for the default.
	 */
	private function record( int $post_id, string $old, string $current ): void {
		if ( self::normalize( $old ) === self::normalize( $current ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'auto-draft' === $post->post_status || in_array( $post->post_type, array( 'revision', 'nav_menu_item', 'customize_changeset' ), true ) ) {
			return;
		}

		$context = $this->context ?? array(
			'source' => $this->detect_source(),
			'batch'  => '',
			'user'   => null,
		);
		$entry   = array(
			'post_id'      => $post_id,
			'post_type'    => (string) $post->post_type,
			'old_template' => substr( $old, 0, 255 ),
			'new_template' => substr( $current, 0, 255 ),
			'user_id'      => null === $context['user'] ? get_current_user_id() : $context['user'],
			'source'       => $context['source'],
			'batch'        => $context['batch'],
			'created'      => gmdate( 'Y-m-d H:i:s' ),
		);

		/**
		 * Filters whether a template change is written to the history.
		 *
		 * @param bool                 $record Default true.
		 * @param array<string, mixed> $entry  post_id, post_type, old_template, new_template, user_id, source, batch, created.
		 */
		if ( ! apply_filters( 'axle_page_template_usage_history_should_record', true, $entry ) ) {
			return;
		}

		$this->buffer[] = $entry;

		/**
		 * Fires when a template change is recorded. The entry has no ID yet: it is written in a group, a moment later.
		 *
		 * @param array<string, mixed> $entry post_id, post_type, old_template, new_template, user_id, source, batch, created.
		 */
		do_action( 'axle_page_template_usage_history_recorded', $entry );

		if ( count( $this->buffer ) >= self::FLUSH_AT ) {
			$this->flush();
		}
	}

	/**
	 * Writes the queued entries in one query, and trims the table when due.
	 */
	public function flush(): void {
		global $wpdb;

		if ( ! $this->buffer ) {
			return;
		}

		$entries      = $this->buffer;
		$this->buffer = array();
		$values       = array( self::table() );

		foreach ( $entries as $entry ) {
			array_push(
				$values,
				$entry['post_id'],
				$entry['post_type'],
				$entry['old_template'],
				$entry['new_template'],
				$entry['user_id'],
				$entry['source'],
				$entry['batch'],
				$entry['created']
			);
		}

		$groups = implode( ', ', array_fill( 0, count( $entries ), '( %d, %s, %s, %s, %d, %s, %s, %s )' ) );

		// One grouped insert into our own table. A write cannot be cached.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i ( post_id, post_type, old_template, new_template, user_id, source, batch, created ) VALUES {$groups}", $values ) );

		$first = (int) $wpdb->insert_id;
		$last  = $first + count( $entries ) - 1;
		if ( $first > 0 && (int) floor( $last / self::TRIM_EVERY ) > (int) floor( ( $first - 1 ) / self::TRIM_EVERY ) ) {
			$this->trim();
		}
	}

	/**
	 * Deletes everything older than the newest cap() entries.
	 */
	public function trim(): void {
		global $wpdb;

		$table = self::table();

		// Our own table, indexed by its primary key. Nothing to cache.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$boundary = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, self::cap() ) );
		if ( $boundary ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, (int) $boundary ) );
		}
		// phpcs:enable
	}

	/**
	 * Daily cleanup: drops entries past the retention period and trims to the cap.
	 */
	public function purge(): void {
		global $wpdb;

		$days = self::retention_days();
		if ( $days ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created < %s', $table, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		}

		$this->trim();
	}

	/**
	 * Keeps the history of a deleted user, without the person.
	 *
	 * @param int $user_id Deleted user.
	 */
	public function anonymize_user( $user_id ): void {
		$this->erase_user( (int) $user_id );
	}

	/**
	 * Sets the user of every entry by one person to 0, which shows as "System".
	 *
	 * @param int $user_id User ID.
	 * @return int Entries changed.
	 */
	public function erase_user( int $user_id ): int {
		global $wpdb;

		if ( $user_id < 1 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->update( self::table(), array( 'user_id' => 0 ), array( 'user_id' => $user_id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Entries of one person, a page at a time, for the privacy exporter.
	 *
	 * @param int $user_id  User ID.
	 * @param int $page     Page, from 1.
	 * @param int $per_page Entries per page.
	 * @return object[]
	 */
	public function for_user( int $user_id, int $page, int $per_page ): array {
		return $this->query(
			array(
				'user_id'  => $user_id,
				'page'     => $page,
				'per_page' => $per_page,
			)
		)['items'];
	}

	/**
	 * Entries, newest first.
	 *
	 * @param array<string, mixed> $args post_id, post_type, source, user_id, batch, template, from (Y-m-d), to (Y-m-d), page, per_page,
	 *                                   and post_types: only entries of these post types (string[]), such as the ones the current user can edit.
	 * @return array{items: object[], total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$this->flush();

		$table    = self::table();
		$template = isset( $args['template'] ) ? (string) $args['template'] : '';
		$like     = '%' . $wpdb->esc_like( $template ) . '%';
		$from     = ! empty( $args['from'] ) ? get_gmt_from_date( $args['from'] . ' 00:00:00' ) : '';
		$to       = ! empty( $args['to'] ) ? get_gmt_from_date( $args['to'] . ' 23:59:59' ) : '';
		$post_id  = (int) ( $args['post_id'] ?? 0 );
		$user_id  = isset( $args['user_id'] ) && '' !== $args['user_id'] ? (int) $args['user_id'] : -1;
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$type     = (string) ( $args['post_type'] ?? '' );
		$source   = (string) ( $args['source'] ?? '' );
		$batch    = (string) ( $args['batch'] ?? '' );

		list( $skip_types, $types ) = self::post_type_filter( isset( $args['post_types'] ) && is_array( $args['post_types'] ) ? $args['post_types'] : null );
		$marks                      = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// One fixed query, so there is nothing to build by hand: each filter is "skip it, or match it".
		// A post of 0 is no filter. A user of 0 means "System", so it is a filter. The table keeps 10,000 rows at most.
		// The post types the user may see get one placeholder each.
		$where = "( %d = 1 OR post_id = %d )
			AND ( %s = '' OR post_type = %s )
			AND ( %s = '' OR source = %s )
			AND ( %d = 1 OR user_id = %d )
			AND ( %s = '' OR batch = %s )
			AND ( %s = '' OR old_template LIKE %s OR new_template LIKE %s )
			AND ( %s = '' OR created >= %s )
			AND ( %s = '' OR created <= %s )
			AND ( %d = 1 OR post_type IN ( {$marks} ) )";
		$vars  = array(
			$post_id ? 0 : 1,
			$post_id,
			$type,
			$type,
			$source,
			$source,
			$user_id < 0 ? 1 : 0,
			max( 0, $user_id ),
			$batch,
			$batch,
			$template,
			$like,
			$like,
			$from,
			$from,
			$to,
			$to,
			$skip_types,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_args = array_merge( array( $table ), $vars, $types );
		$list_args  = array_merge( array( $table ), $vars, $types, array( $per_page, $offset ) );
		$total      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $count_args ) );
		$list_sql   = "SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$items      = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * One entry.
	 *
	 * @param int $id Entry ID.
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		global $wpdb;

		$this->flush();
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );

		return $row ? $row : null;
	}

	/**
	 * Summary of one batch: how many changes, who, when, and how many are still undone-able on paper.
	 *
	 * @param string        $batch      Batch ID.
	 * @param string[]|null $post_types Only count entries of these post types, or null for every one.
	 * @return array{source: string, user_id: int, created: string, total: int, open: int}|null Null for an unknown batch, or one with no entries of those post types.
	 */
	public function get_batch( string $batch, ?array $post_types = null ): ?array {
		global $wpdb;

		if ( '' === $batch ) {
			return null;
		}

		$this->flush();
		$table = self::table();

		list( $skip_types, $types ) = self::post_type_filter( $post_types );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT source, user_id, MIN(created) AS created, COUNT(*) AS total, SUM(undone = 0) AS open FROM %i WHERE batch = %s AND ( %d = 1 OR post_type IN ( ' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ' ) )', array_merge( array( $table, $batch, $skip_types ), $types ) ) );
		if ( ! $row || ! (int) $row->total ) {
			return null;
		}

		return array(
			'source'  => (string) $row->source,
			'user_id' => (int) $row->user_id,
			'created' => (string) $row->created,
			'total'   => (int) $row->total,
			'open'    => (int) $row->open,
		);
	}

	/**
	 * Users with entries, for the filter dropdown.
	 *
	 * @param int           $limit      Most users to return.
	 * @param string[]|null $post_types Only users with entries of these post types, or null for every one.
	 * @return int[]
	 */
	public function get_user_ids( int $limit = 50, ?array $post_types = null ): array {
		global $wpdb;

		$this->flush();
		$table = self::table();

		list( $skip_types, $types ) = self::post_type_filter( $post_types );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE user_id > 0 AND ( %d = 1 OR post_type IN ( ' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ' ) ) ORDER BY user_id LIMIT %d', array_merge( array( $table, $skip_types ), $types, array( $limit ) ) ) ) );
	}

	/**
	 * Why an entry cannot be undone.
	 *
	 * @param object $entry      History row.
	 * @param bool   $check_user Also require that the current user may edit the post.
	 * @return array{code: string, current: string}|null Null when it can be undone. Codes: undone, post_gone, changed, cannot_edit, blocked.
	 */
	public function blocker( object $entry, bool $check_user = true ): ?array {
		if ( ! empty( $entry->undone ) ) {
			return array(
				'code'    => 'undone',
				'current' => '',
			);
		}

		$post_id = (int) $entry->post_id;
		if ( ! get_post( $post_id ) ) {
			return array(
				'code'    => 'post_gone',
				'current' => '',
			);
		}

		// Checked before the template, so someone who may not edit the post learns nothing about it.
		if ( $check_user && ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'code'    => 'cannot_edit',
				'current' => '',
			);
		}

		$current = (string) get_post_meta( $post_id, Templates::META_KEY, true );
		if ( self::normalize( $current ) !== self::normalize( (string) $entry->new_template ) ) {
			return array(
				'code'    => 'changed',
				'current' => $current,
			);
		}

		/**
		 * Filters whether a history entry may be undone, after the built-in checks passed.
		 *
		 * @param bool   $allowed Default true.
		 * @param object $entry   History row.
		 */
		if ( ! apply_filters( 'axle_page_template_usage_can_undo', true, $entry ) ) {
			return array(
				'code'    => 'blocked',
				'current' => $current,
			);
		}

		return null;
	}

	/**
	 * Sentence that says why an entry cannot be undone.
	 *
	 * @param array{code: string, current: string} $blocker  What blocker() returned.
	 * @param object                               $entry    History row.
	 * @param callable|null                        $namer    Turns a stored template value into a display name. Default: the value itself.
	 * @return string
	 */
	public function blocker_message( array $blocker, object $entry, ?callable $namer = null ): string {
		$name = $namer ?? static function ( string $slug ): string {
			return self::is_default( $slug ) ? __( 'Default', 'axle-page-template-usage' ) : $slug;
		};

		switch ( $blocker['code'] ) {
			case 'undone':
				return __( 'Already undone.', 'axle-page-template-usage' );
			case 'post_gone':
				return __( 'The page no longer exists.', 'axle-page-template-usage' );
			case 'changed':
				return sprintf(
					/* translators: 1: the template the page uses now, 2: the template this change set. */
					__( 'The page uses "%1$s" now, not "%2$s", which this change set.', 'axle-page-template-usage' ),
					$name( $blocker['current'] ),
					$name( (string) $entry->new_template )
				);
			case 'cannot_edit':
				return __( 'You are not allowed to edit this page.', 'axle-page-template-usage' );
			default:
				return __( 'This change cannot be undone.', 'axle-page-template-usage' );
		}
	}

	/**
	 * Undoes one entry: puts the old template back, if the page still has the one this entry set.
	 *
	 * @param int  $id         Entry ID.
	 * @param bool $check_user Require that the current user may edit the post.
	 * @return array{code: string, current: string}|null|\WP_Error Null when undone, the blocker when not, WP_Error for an unknown entry.
	 */
	public function undo_entry( int $id, bool $check_user = true ) {
		$entry = $this->get( $id );
		if ( ! $entry ) {
			return new \WP_Error( 'axle_page_template_usage_unknown_entry', __( 'That history entry does not exist any more.', 'axle-page-template-usage' ) );
		}

		$result = $this->undo_entries( array( $entry ), $check_user );

		return $result['undone'] ? null : ( $result['blockers'][0] ?? array(
			'code'    => 'blocked',
			'current' => '',
		) );
	}

	/**
	 * Undoes a whole batch, newest change first, at most $limit changes per call.
	 *
	 * Entries that cannot be undone (the page changed since, or is gone) are skipped.
	 * Run it again while `remaining` is above 0.
	 *
	 * @param string        $batch      Batch ID.
	 * @param int           $limit      Most changes to undo in this call, 0 for all.
	 * @param bool          $check_user Skip pages the current user may not edit.
	 * @param string[]|null $post_types Only undo entries of these post types, such as the ones the current user can edit. Null for every one.
	 * @return array{undone: int, skipped: int, remaining: int, batch: string}|\WP_Error `batch` is the batch the undo itself was recorded under.
	 */
	public function undo_batch( string $batch, int $limit = 0, bool $check_user = true, ?array $post_types = null ) {
		global $wpdb;

		if ( null === $this->get_batch( $batch, $post_types ) ) {
			return new \WP_Error( 'axle_page_template_usage_unknown_batch', __( 'That batch does not exist any more.', 'axle-page-template-usage' ) );
		}

		$table   = self::table();
		$undone  = 0;
		$skipped = 0;
		$cursor  = PHP_INT_MAX;
		$undo_id = $this->new_batch();
		$chunk   = 200;

		list( $skip_types, $types ) = self::post_type_filter( $post_types );

		$previous = $this->enter( 'undo', $undo_id );
		try {
			while ( true ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$entries = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE batch = %s AND undone = 0 AND id < %d AND ( %d = 1 OR post_type IN ( ' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ' ) ) ORDER BY id DESC LIMIT %d', array_merge( array( $table, $batch, $cursor, $skip_types ), $types, array( $chunk ) ) ) );
				if ( ! $entries ) {
					break;
				}

				update_meta_cache( 'post', array_map( 'intval', wp_list_pluck( $entries, 'post_id' ) ) );

				$take   = $limit ? min( count( $entries ), $limit - $undone ) : count( $entries );
				$slice  = array_slice( $entries, 0, $take );
				$result = $this->undo_entries( $slice, $check_user );
				$cursor = (int) $slice[ count( $slice ) - 1 ]->id;

				$undone  += $result['undone'];
				$skipped += $result['skipped'];

				if ( $limit && $undone >= $limit ) {
					break;
				}
			}
		} finally {
			$this->leave( $previous );
		}

		$remaining = 0;
		if ( PHP_INT_MAX !== $cursor ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch = %s AND undone = 0 AND id < %d AND ( %d = 1 OR post_type IN ( ' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ' ) )', array_merge( array( $table, $batch, $cursor, $skip_types ), $types ) ) );
		}
		if ( ! $limit || $undone < $limit ) {
			$remaining = 0;
		}

		$summary = array(
			'undone'    => $undone,
			'skipped'   => $skipped,
			'remaining' => $remaining,
			'batch'     => $undone ? $undo_id : '',
		);

		/**
		 * Fires after a batch was undone, in whole or in part.
		 *
		 * @param string                $batch   The batch that was undone.
		 * @param array<string, mixed>  $summary undone, skipped, remaining and the batch the undo was recorded under.
		 */
		do_action( 'axle_page_template_usage_batch_undone', $batch, $summary );

		return $summary;
	}

	/**
	 * Undoes entries one by one. Each one is checked on its own.
	 *
	 * @param object[] $entries    History rows.
	 * @param bool     $check_user Require that the current user may edit each post.
	 * @return array{undone: int, skipped: int, blockers: array<int, array{code: string, current: string}>}
	 */
	private function undo_entries( array $entries, bool $check_user ): array {
		global $wpdb;

		$undone   = 0;
		$skipped  = 0;
		$blockers = array();
		$done_ids = array();

		// Only enter our own context when the caller did not (undo_batch does).
		$own      = null === $this->context || 'undo' !== $this->context['source'];
		$previous = $own ? $this->enter( 'undo', $this->new_batch() ) : null;

		try {
			foreach ( $entries as $entry ) {
				$blocker = $this->blocker( $entry, $check_user );
				if ( $blocker ) {
					++$skipped;
					$blockers[] = $blocker;
					continue;
				}

				$old = (string) $entry->old_template;
				update_post_meta( (int) $entry->post_id, Templates::META_KEY, self::is_default( $old ) ? Templates::DEFAULT_TEMPLATE : $old );
				$done_ids[] = (int) $entry->id;
				++$undone;

				/**
				 * Fires after one change was undone.
				 *
				 * @param object $entry History row of the change that was undone.
				 */
				do_action( 'axle_page_template_usage_undone', $entry );
			}
		} finally {
			if ( $own ) {
				$this->leave( $previous );
			}
		}

		if ( $done_ids ) {
			$table = self::table();
			$marks = implode( ', ', array_fill( 0, count( $done_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET undone = 1 WHERE id IN ( {$marks} )", array_merge( array( $table ), $done_ids ) ) );
		}

		return array(
			'undone'   => $undone,
			'skipped'  => $skipped,
			'blockers' => $blockers,
		);
	}
}
