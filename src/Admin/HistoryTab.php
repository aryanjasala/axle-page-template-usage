<?php
/**
 * Appearance > Axle Page Template Usage, History tab.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Admin;

use AryanX\Axle\PageTemplateUsage\History;
use AryanX\Axle\PageTemplateUsage\Service;
use AryanX\Axle\PageTemplateUsage\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every template change with filters, and undoes one change or a whole batch.
 */
final class HistoryTab implements Service {

	public const ACTION_UNDO       = 'axle_page_template_usage_undo_entry';
	public const ACTION_UNDO_BATCH = 'axle_page_template_usage_undo_batch';

	private const UNDO_LIMIT = 500;

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Template history.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Sets up the tab.
	 *
	 * @param Templates $templates Template data.
	 * @param History   $history   Template history.
	 */
	public function __construct( Templates $templates, History $history ) {
		$this->templates = $templates;
		$this->history   = $history;
	}

	/**
	 * Registers the undo handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_UNDO, array( $this, 'handle_undo' ) );
		add_action( 'admin_post_' . self::ACTION_UNDO_BATCH, array( $this, 'handle_undo_batch' ) );
	}

	/**
	 * Names of the places a change can come from.
	 *
	 * @return array<string, string> Source key => label.
	 */
	public static function source_labels(): array {
		$labels = array(
			'editor'       => __( 'Editor', 'axle-page-template-usage' ),
			'quick_edit'   => __( 'Quick Edit', 'axle-page-template-usage' ),
			'bulk_move'    => __( 'Move button', 'axle-page-template-usage' ),
			'theme_switch' => __( 'Theme switch', 'axle-page-template-usage' ),
			'cli'          => __( 'WP-CLI', 'axle-page-template-usage' ),
			'ability'      => __( 'Ability', 'axle-page-template-usage' ),
			'undo'         => __( 'Undo', 'axle-page-template-usage' ),
			'other'        => __( 'Other', 'axle-page-template-usage' ),
		);

		/**
		 * Filters the labels shown for the source of a template change.
		 *
		 * @param array<string, string> $labels Source key => label.
		 */
		return (array) apply_filters( 'axle_page_template_usage_history_source_labels', $labels );
	}

	/**
	 * Entries shown per page.
	 *
	 * @return int
	 */
	public static function per_page(): int {
		/**
		 * Filters how many history entries one page of the History tab lists.
		 *
		 * @param int $per_page Default 20.
		 */
		return max( 1, (int) apply_filters( 'axle_page_template_usage_history_per_page', 20 ) );
	}

	/**
	 * Most changes one Undo batch click handles. WP-CLI has no limit.
	 *
	 * @return int
	 */
	public static function undo_limit(): int {
		/**
		 * Filters how many changes one "Undo this batch" click, or one undo ability run, handles.
		 *
		 * @param int $limit Default 500.
		 */
		return max( 1, (int) apply_filters( 'axle_page_template_usage_undo_limit', self::UNDO_LIMIT ) );
	}

	/**
	 * URL of the History tab, optionally filtered.
	 *
	 * @param array<string, scalar> $args Filters: post, batch, source, user, template, from, to.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return ReportPage::url( array_merge( array( 'tab' => 'history' ), $args ) );
	}

	/**
	 * The filters in the URL.
	 *
	 * @return array<string, mixed>
	 */
	private function requested(): array {
		// Read-only list filters, like core's own list table filters.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$text = static function ( string $key ): string {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		$date = static function ( string $key ) use ( $text ): string {
			$value = $text( $key );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};

		$args = array(
			'post_id'  => isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0,
			'source'   => sanitize_key( $text( 'source' ) ),
			'user_id'  => isset( $_GET['user'] ) && '' !== $_GET['user'] ? absint( $_GET['user'] ) : '',
			'template' => $text( 'template' ),
			'batch'    => preg_replace( '/[^a-f0-9-]/', '', strtolower( $text( 'batch' ) ) ),
			'from'     => $date( 'from' ),
			'to'       => $date( 'to' ),
			'page'     => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
			'per_page' => self::per_page(),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Only the changes of post types this user can edit.
		$args['post_types'] = ReportPage::accessible_types();

		return $args;
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$args   = $this->requested();
		$result = $this->history->query( $args );
		$items  = $result['items'];
		$ids    = array_unique( array_map( 'intval', wp_list_pluck( $items, 'post_id' ) ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}

		$filtered = (bool) array_filter(
			array(
				$args['post_id'],
				$args['source'],
				$args['user_id'],
				$args['template'],
				$args['batch'],
				$args['from'],
				$args['to'],
			),
			static function ( $value ): bool {
				return '' !== $value && 0 !== $value;
			}
		);
		?>
		<p class="axle-intro aptu-report__intro">
			<?php esc_html_e( 'Every change of a page template: who made it, when, and where it came from. Undo a change while the page still has the template it set.', 'axle-page-template-usage' ); ?>
		</p>

		<?php
		if ( '' !== $args['batch'] ) {
			$this->render_batch( (string) $args['batch'], (array) $args['post_types'] );
		}
		?>

		<section class="axle-card" aria-labelledby="aptu-history-title">
			<h2 class="axle-card__title" id="aptu-history-title"><?php esc_html_e( 'Template changes', 'axle-page-template-usage' ); ?></h2>

			<?php $this->render_filters( $args ); ?>

			<?php if ( $args['post_id'] ) : ?>
				<p class="aptu-history__scope">
					<?php
					printf(
						/* translators: %s: page title. */
						esc_html__( 'Showing the history of "%s".', 'axle-page-template-usage' ),
						esc_html( $this->post_title( (int) $args['post_id'] ) )
					);
					?>
					<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Show every page', 'axle-page-template-usage' ); ?></a>
				</p>
			<?php endif; ?>

			<?php if ( ! $items ) : ?>
				<div class="axle-empty">
					<?php if ( $filtered ) : ?>
						<p><?php esc_html_e( 'No template change matches these filters.', 'axle-page-template-usage' ); ?></p>
						<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Clear the filters', 'axle-page-template-usage' ); ?></a>
					<?php else : ?>
						<p><?php esc_html_e( 'Nothing has been recorded yet. From now on, every change of a page template shows up here: in the editor, in Quick Edit, with the Move button, on a theme switch and from WP-CLI.', 'axle-page-template-usage' ); ?></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php $this->render_table( $items ); ?>
				<?php $this->render_pagination( (int) $result['total'], (int) $args['page'], (int) $args['per_page'], $args ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints the filter form.
	 *
	 * @param array<string, mixed> $args Filters in use.
	 */
	private function render_filters( array $args ): void {
		$users = $this->history->get_user_ids( 50, (array) $args['post_types'] );
		?>
		<form method="get" class="aptu-history__filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( ReportPage::SLUG ); ?>">
			<input type="hidden" name="tab" value="history">
			<?php if ( '' !== $args['batch'] ) : ?>
				<input type="hidden" name="batch" value="<?php echo esc_attr( (string) $args['batch'] ); ?>">
			<?php endif; ?>

			<p>
				<label for="aptu-f-post"><?php esc_html_e( 'Page ID', 'axle-page-template-usage' ); ?></label>
				<input type="number" min="1" class="small-text" name="post" id="aptu-f-post" value="<?php echo esc_attr( $args['post_id'] ? (string) $args['post_id'] : '' ); ?>">
			</p>
			<p>
				<label for="aptu-f-source"><?php esc_html_e( 'Source', 'axle-page-template-usage' ); ?></label>
				<select name="source" id="aptu-f-source">
					<option value=""><?php esc_html_e( 'Any source', 'axle-page-template-usage' ); ?></option>
					<?php foreach ( self::source_labels() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['source'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="aptu-f-user"><?php esc_html_e( 'Changed by', 'axle-page-template-usage' ); ?></label>
				<select name="user" id="aptu-f-user">
					<option value=""><?php esc_html_e( 'Anyone', 'axle-page-template-usage' ); ?></option>
					<option value="0" <?php selected( (string) $args['user_id'], '0' ); ?>><?php esc_html_e( 'System', 'axle-page-template-usage' ); ?></option>
					<?php foreach ( $users as $user_id ) : ?>
						<option value="<?php echo esc_attr( (string) $user_id ); ?>" <?php selected( (string) $args['user_id'], (string) $user_id ); ?>><?php echo esc_html( $this->user_name( $user_id ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="aptu-f-template"><?php esc_html_e( 'Template contains', 'axle-page-template-usage' ); ?></label>
				<input type="search" name="template" id="aptu-f-template" value="<?php echo esc_attr( (string) $args['template'] ); ?>">
			</p>
			<p>
				<label for="aptu-f-from"><?php esc_html_e( 'From', 'axle-page-template-usage' ); ?></label>
				<input type="date" name="from" id="aptu-f-from" value="<?php echo esc_attr( (string) $args['from'] ); ?>">
			</p>
			<p>
				<label for="aptu-f-to"><?php esc_html_e( 'To', 'axle-page-template-usage' ); ?></label>
				<input type="date" name="to" id="aptu-f-to" value="<?php echo esc_attr( (string) $args['to'] ); ?>">
			</p>
			<p class="aptu-history__filter-actions">
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'axle-page-template-usage' ); ?></button>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Reset', 'axle-page-template-usage' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Prints the table of entries.
	 *
	 * @param object[] $items History rows.
	 */
	private function render_table( array $items ): void {
		$sources = self::source_labels();
		?>
		<table class="widefat striped aptu-history-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'axle-page-template-usage' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Page', 'axle-page-template-usage' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Change', 'axle-page-template-usage' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Changed by', 'axle-page-template-usage' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Source', 'axle-page-template-usage' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Undo', 'axle-page-template-usage' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $entry ) : ?>
					<tr class="aptu-history-row" data-entry="<?php echo esc_attr( (string) $entry->id ); ?>">
						<td><?php $this->print_time( (string) $entry->created ); ?></td>
						<td>
							<?php $this->print_page( $entry ); ?>
						</td>
						<td>
							<?php $this->print_template( (string) $entry->post_type, (string) $entry->old_template ); ?>
							<span aria-hidden="true">&rarr;</span><span class="screen-reader-text"><?php esc_html_e( 'changed to', 'axle-page-template-usage' ); ?></span>
							<?php $this->print_template( (string) $entry->post_type, (string) $entry->new_template ); ?>
						</td>
						<td><?php echo esc_html( $this->user_name( (int) $entry->user_id ) ); ?></td>
						<td>
							<span class="axle-badge axle-badge--<?php echo esc_attr( 'undo' === $entry->source ? 'info' : 'neutral' ); ?>"><?php echo esc_html( $sources[ $entry->source ] ?? $entry->source ); ?></span>
							<?php if ( '' !== $entry->batch ) : ?>
								<br><a class="aptu-batch-link" href="<?php echo esc_url( self::url( array( 'batch' => $entry->batch ) ) ); ?>"><?php esc_html_e( 'Whole batch', 'axle-page-template-usage' ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php $this->print_undo( $entry ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints a time as "5 minutes ago" with the exact time on hover.
	 *
	 * @param string $gmt Time in UTC, as stored.
	 */
	private function print_time( string $gmt ): void {
		$timestamp = strtotime( $gmt . ' UTC' );
		printf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', (int) $timestamp ) ),
			esc_attr( get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
			/* translators: %s: time since, such as "5 minutes". */
			esc_html( sprintf( __( '%s ago', 'axle-page-template-usage' ), human_time_diff( (int) $timestamp ) ) )
		);
	}

	/**
	 * Prints the page an entry belongs to.
	 *
	 * @param object $entry History row.
	 */
	private function print_page( object $entry ): void {
		$post_id = (int) $entry->post_id;
		$post    = get_post( $post_id );

		if ( ! $post ) {
			/* translators: %d: post ID. */
			echo esc_html( sprintf( __( 'Deleted page (ID %d)', 'axle-page-template-usage' ), $post_id ) );
			return;
		}

		if ( current_user_can( 'edit_post', $post_id ) ) {
			printf( '<a href="%s"><strong>%s</strong></a>', esc_url( (string) get_edit_post_link( $post_id ) ), esc_html( $this->post_title( $post_id ) ) );
		} else {
			printf( '<strong>%s</strong>', esc_html( $this->post_title( $post_id ) ) );
		}

		printf(
			' <a class="aptu-page-link" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
			esc_url( self::url( array( 'post' => $post_id ) ) ),
			/* translators: %s: page title. */
			esc_html( sprintf( __( 'Show only the history of "%s"', 'axle-page-template-usage' ), $this->post_title( $post_id ) ) ),
			esc_html__( 'Only this page', 'axle-page-template-usage' )
		);
	}

	/**
	 * Prints a template name, or its file when the theme no longer has it.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug      Stored template value.
	 */
	private function print_template( string $post_type, string $slug ): void {
		if ( $this->templates->is_default( $slug ) ) {
			echo '<span class="aptu-default">' . esc_html__( 'Default', 'axle-page-template-usage' ) . '</span>';
			return;
		}

		$name = $this->templates->get_name( $post_type, $slug );
		if ( null !== $name ) {
			echo '<strong>' . esc_html( $name ) . '</strong>';
			return;
		}

		printf( '<code class="aptu-slug">%s</code>', esc_html( $slug ) );
	}

	/**
	 * Prints the Undo button, or why the entry cannot be undone.
	 *
	 * @param object $entry History row.
	 */
	private function print_undo( object $entry ): void {
		$blocker = $this->history->blocker( $entry );

		if ( $blocker ) {
			$namer = function ( string $slug ) use ( $entry ): string {
				return $this->templates->is_default( $slug ) ? __( 'Default', 'axle-page-template-usage' ) : ( $this->templates->get_name( (string) $entry->post_type, $slug ) ?? $slug );
			};
			printf( '<span class="aptu-undo-reason">%s</span>', esc_html( $this->history->blocker_message( $blocker, $entry, $namer ) ) );
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION_UNDO . $entry->id ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UNDO ); ?>">
			<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $entry->id ); ?>">
			<button type="submit" class="button">
				<?php esc_html_e( 'Undo', 'axle-page-template-usage' ); ?>
				<span class="screen-reader-text">
					<?php
					/* translators: %s: page title. */
					echo esc_html( sprintf( __( 'the template change of "%s"', 'axle-page-template-usage' ), $this->post_title( (int) $entry->post_id ) ) );
					?>
				</span>
			</button>
		</form>
		<?php
	}

	/**
	 * Prints the summary of one batch and the button that undoes all of it.
	 *
	 * @param string   $batch      Batch ID.
	 * @param string[] $post_types Post types the user can edit. Only their changes count.
	 */
	private function render_batch( string $batch, array $post_types ): void {
		$summary = $this->history->get_batch( $batch, $post_types );
		$sources = self::source_labels();

		if ( ! $summary ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'That batch does not exist any more. The history keeps a limited number of changes, and the oldest go first.', 'axle-page-template-usage' ) );
			return;
		}

		$open = $summary['open'];
		?>
		<section class="axle-card aptu-batch" aria-labelledby="aptu-batch-title">
			<h2 class="axle-card__title" id="aptu-batch-title"><?php esc_html_e( 'One batch of changes', 'axle-page-template-usage' ); ?></h2>
			<dl class="axle-facts">
				<dt><?php esc_html_e( 'Source', 'axle-page-template-usage' ); ?></dt>
				<dd><?php echo esc_html( $sources[ $summary['source'] ] ?? $summary['source'] ); ?></dd>
				<dt><?php esc_html_e( 'Changed by', 'axle-page-template-usage' ); ?></dt>
				<dd><?php echo esc_html( $this->user_name( $summary['user_id'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Started', 'axle-page-template-usage' ); ?></dt>
				<dd><?php $this->print_time( $summary['created'] ); ?></dd>
				<dt><?php esc_html_e( 'Changes', 'axle-page-template-usage' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Not undone yet', 'axle-page-template-usage' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $open ) ); ?></dd>
			</dl>
			<?php if ( $open ) : ?>
				<?php self::render_undo_batch_form( $batch, $open, 'button' ); ?>
			<?php else : ?>
				<p><span class="axle-badge axle-badge--good"><?php esc_html_e( 'Undone', 'axle-page-template-usage' ); ?></span></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints the "Undo this batch" form, with its confirm step.
	 *
	 * @param string $batch Batch ID.
	 * @param int    $count Changes that would be undone, at most.
	 * @param string $button_class Button class.
	 */
	public static function render_undo_batch_form( string $batch, int $count, string $button_class = 'button' ): void {
		$message = sprintf(
			/* translators: %s: number of changes. */
			_n(
				'Undo %s change? The page goes back to the template it had before. Pages changed again since are left alone. The undo is recorded in the history, so you can undo it too.',
				'Undo up to %s changes? Each page goes back to the template it had before. Pages changed again since are left alone. The undo is recorded in the history, so you can undo it too.',
				$count,
				'axle-page-template-usage'
			),
			number_format_i18n( $count )
		);

		ReportPage::enqueue_script();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aptu-inline-form" data-aptu-confirm="<?php echo esc_attr( $message ); ?>">
			<?php wp_nonce_field( self::ACTION_UNDO_BATCH . $batch ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UNDO_BATCH ); ?>">
			<input type="hidden" name="batch" value="<?php echo esc_attr( $batch ); ?>">
			<button type="submit" class="<?php echo esc_attr( $button_class ); ?>"><?php esc_html_e( 'Undo this batch', 'axle-page-template-usage' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Prints the page links under the table.
	 *
	 * @param int                  $total    Entries matching.
	 * @param int                  $page     Current page.
	 * @param int                  $per_page Entries per page.
	 * @param array<string, mixed> $args     Filters in use.
	 */
	private function render_pagination( int $total, int $page, int $per_page, array $args ): void {
		$pages = (int) ceil( $total / $per_page );
		if ( $pages < 2 ) {
			return;
		}

		$base = self::url(
			array_filter(
				array(
					'post'     => $args['post_id'],
					'source'   => $args['source'],
					'user'     => $args['user_id'],
					'template' => $args['template'],
					'batch'    => $args['batch'],
					'from'     => $args['from'],
					'to'       => $args['to'],
				),
				static function ( $value ): bool {
					return '' !== $value && 0 !== $value;
				}
			)
		);

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', $base ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => __( 'Previous', 'axle-page-template-usage' ),
				'next_text' => __( 'Next', 'axle-page-template-usage' ),
			)
		);

		printf(
			'<div class="tablenav bottom"><div class="tablenav-pages"><span class="displaying-num">%1$s</span> %2$s</div></div>',
			/* translators: %s: number of entries. */
			esc_html( sprintf( _n( '%s entry', '%s entries', $total, 'axle-page-template-usage' ), number_format_i18n( $total ) ) ),
			wp_kses_post( (string) $links )
		);
	}

	/**
	 * Title of a post, or a stand-in when it has none or the user may not see it.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function post_title( int $post_id ): string {
		// Drafts and private pages of other people keep their titles to those allowed to read them. So does an
		// item that is now of a post type this user cannot edit (its post type was changed after the entry).
		$post = get_post( $post_id );
		if ( $post && ( ! ReportPage::can_access( $post->post_type ) || ! current_user_can( 'read_post', $post_id ) ) ) {
			/* translators: %d: post ID. */
			return sprintf( __( 'A page you cannot view (ID %d)', 'axle-page-template-usage' ), $post_id );
		}

		$title = get_the_title( $post_id );

		/* translators: %d: post ID. */
		return '' !== $title ? $title : sprintf( __( '(no title, ID %d)', 'axle-page-template-usage' ), $post_id );
	}

	/**
	 * Name of a user for the table. 0 means WP-CLI, cron or a person who was erased.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function user_name( int $user_id ): string {
		if ( $user_id < 1 ) {
			return __( 'System', 'axle-page-template-usage' );
		}

		$user = get_userdata( $user_id );

		return $user ? $user->display_name : __( 'Deleted user', 'axle-page-template-usage' );
	}

	/**
	 * Undoes one change.
	 */
	public function handle_undo(): void {
		$id = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
		check_admin_referer( self::ACTION_UNDO . $id );
		$this->authorize();

		// An entry of a post type this user cannot edit is treated like one that is gone.
		$entry = $this->history->get( $id );
		if ( ! $entry || ! ReportPage::can_access( (string) $entry->post_type ) ) {
			ReportPage::redirect_back( array( 'aptu_blocked' => 'gone' ), self::url() );
		}

		$result = $this->history->undo_entry( $id );
		$args   = array();

		if ( is_wp_error( $result ) ) {
			$args['aptu_blocked'] = 'gone';
		} elseif ( $result ) {
			$args['aptu_blocked'] = $result['code'];
		} else {
			$args['aptu_undone'] = 1;
		}

		ReportPage::redirect_back( $args, self::url() );
	}

	/**
	 * Undoes a whole batch, up to the limit.
	 */
	public function handle_undo_batch(): void {
		$batch = isset( $_POST['batch'] ) ? preg_replace( '/[^a-f0-9-]/', '', strtolower( sanitize_text_field( wp_unslash( $_POST['batch'] ) ) ) ) : '';
		check_admin_referer( self::ACTION_UNDO_BATCH . $batch );
		$this->authorize();

		$result = $this->history->undo_batch( (string) $batch, self::undo_limit(), true, ReportPage::accessible_types() );
		if ( is_wp_error( $result ) ) {
			ReportPage::redirect_back( array( 'aptu_blocked' => 'gone' ), self::url(), false );
		}

		ReportPage::redirect_back(
			array(
				'aptu_undone'       => $result['undone'],
				'aptu_undo_skipped' => $result['skipped'],
				'aptu_undo_left'    => $result['remaining'],
			),
			self::url( array( 'batch' => $batch ) ),
			false
		);
	}

	/**
	 * Stops users who may not use the history.
	 */
	private function authorize(): void {
		if ( ! current_user_can( ReportPage::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change templates for these items.', 'axle-page-template-usage' ), 403 );
		}
	}
}
