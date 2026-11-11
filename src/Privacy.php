<?php
/**
 * Privacy policy text, personal data export and erasure for the template history.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

defined( 'ABSPATH' ) || exit;

/**
 * The history stores which user changed a template. This covers that user ID.
 */
final class Privacy implements Service {

	private const PER_PAGE = 100;

	/**
	 * Template history.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Sets up the privacy tools.
	 *
	 * @param History $history Template history.
	 */
	public function __construct( History $history ) {
		$this->history = $history;
	}

	/**
	 * Registers the exporter, the eraser and the policy text.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array<string, mixed> $exporters Registered exporters.
	 * @return array<string, mixed>
	 */
	public function add_exporter( $exporters ) {
		$exporters['axle-page-template-usage'] = array(
			'exporter_friendly_name' => __( 'Axle Page Template Usage history', 'axle-page-template-usage' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array<string, mixed> $erasers Registered erasers.
	 * @return array<string, mixed>
	 */
	public function add_eraser( $erasers ) {
		$erasers['axle-page-template-usage'] = array(
			'eraser_friendly_name' => __( 'Axle Page Template Usage history', 'axle-page-template-usage' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the template changes a person made.
	 *
	 * @param string $email Email address of the person.
	 * @param int    $page  Page of results, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();

		if ( $user ) {
			$entries = $this->history->for_user( (int) $user->ID, (int) $page, self::PER_PAGE );
			foreach ( $entries as $entry ) {
				$data[] = array(
					'group_id'          => 'axle-page-template-usage',
					'group_label'       => __( 'Page template changes', 'axle-page-template-usage' ),
					'group_description' => __( 'Template changes this person made to pages and posts.', 'axle-page-template-usage' ),
					'item_id'           => 'axle-page-template-usage-' . $entry->id,
					'data'              => array(
						array(
							'name'  => __( 'Post ID', 'axle-page-template-usage' ),
							'value' => (string) $entry->post_id,
						),
						array(
							'name'  => __( 'Template before', 'axle-page-template-usage' ),
							'value' => '' === $entry->old_template ? __( 'Default', 'axle-page-template-usage' ) : $entry->old_template,
						),
						array(
							'name'  => __( 'Template after', 'axle-page-template-usage' ),
							'value' => '' === $entry->new_template ? __( 'Default', 'axle-page-template-usage' ) : $entry->new_template,
						),
						array(
							'name'  => __( 'Source', 'axle-page-template-usage' ),
							'value' => (string) $entry->source,
						),
						array(
							'name'  => __( 'Date (UTC)', 'axle-page-template-usage' ),
							'value' => (string) $entry->created,
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => count( $data ) < self::PER_PAGE,
		);
	}

	/**
	 * Erases a person from the history. The changes stay, without the user.
	 *
	 * @param string $email Email address of the person.
	 * @param int    $page  Page of results, from 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = $user ? $this->history->erase_user( (int) $user->ID ) : 0;

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $removed
				? array( __( 'The template history keeps each change, without the name of the person who made it.', 'axle-page-template-usage' ) )
				: array(),
			'done'           => true,
		);
	}

	/**
	 * Suggests text for the site's privacy policy.
	 */
	public function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$days = History::retention_days();

		wp_add_privacy_policy_content(
			__( 'Axle Page Template Usage', 'axle-page-template-usage' ),
			wp_kses_post(
				'<h2>' . __( 'Page template history', 'axle-page-template-usage' ) . '</h2>' .
				'<p class="privacy-policy-tutorial">' . __( 'Suggested text. Check it against how your site is set up.', 'axle-page-template-usage' ) . '</p>' .
				'<p>' . sprintf(
					/* translators: 1: number of changes kept, 2: sentence about how long entries are kept. */
					__( 'When a user changes the template of a page or post, this site records which page, the old and new template, the ID of the user who made the change, and the time. It keeps the latest %1$s changes. %2$s The record is only visible to logged-in users who can edit pages.', 'axle-page-template-usage' ),
					number_format_i18n( History::cap() ),
					$days
						/* translators: %s: number of days. */
						? sprintf( _n( 'Entries older than %s day are deleted.', 'Entries older than %s days are deleted.', $days, 'axle-page-template-usage' ), number_format_i18n( $days ) )
						: __( 'Older entries are deleted when the limit is reached.', 'axle-page-template-usage' )
				) . '</p>' .
				'<p>' . __( 'Exporting a person\'s data includes their template changes. Erasing their data keeps the changes but removes the link to the person.', 'axle-page-template-usage' ) . '</p>'
			)
		);
	}
}
