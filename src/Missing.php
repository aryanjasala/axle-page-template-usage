<?php
/**
 * Finds pages whose template went missing, and tells people.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a small record of which templates the theme offers and which are
 * missing, so a theme update or a deploy that drops a template is noticed.
 *
 * Nothing here changes a page. WordPress keeps the stale template in post meta
 * and shows the default layout; this class reports it and suggests a
 * replacement, and the Missing tab moves the pages through the history.
 */
final class Missing implements Service {

	public const OPTION_STATE = 'axle_page_template_usage_missing_state';
	public const OPTION_ALERT = 'axle_page_template_usage_missing_alert';
	public const META_DISMISS = 'axle_page_template_usage_missing_dismissed';
	public const CRON         = 'axle_page_template_usage_scan';
	public const CRON_SOON    = 'axle_page_template_usage_scan_soon';

	/**
	 * Template data.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Sets up the finder.
	 *
	 * @param Templates $templates Template data.
	 */
	public function __construct( Templates $templates ) {
		$this->templates = $templates;
	}

	/**
	 * Hooks in the scans: after a theme update, after a switch, and once a day.
	 */
	public function register(): void {
		add_action( self::CRON, array( $this, 'scan_cron' ) );
		add_action( self::CRON_SOON, array( $this, 'scan_cron' ) );
		add_action( 'after_switch_theme', array( $this, 'on_switch' ), 30 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Schedules the daily scan, and takes the first look at a site that has none yet.
	 */
	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			/**
			 * Filters how often the template list is compared with the last scan.
			 *
			 * The compare is one hash of the theme's template names, so a day is cheap.
			 * Return a registered cron schedule name, such as hourly or twicedaily.
			 *
			 * @param string $schedule Default daily.
			 */
			$schedule = (string) apply_filters( 'axle_page_template_usage_scan_schedule', 'daily' );
			$known    = wp_get_schedules();
			wp_schedule_event( time() + HOUR_IN_SECONDS, isset( $known[ $schedule ] ) ? $schedule : 'daily', self::CRON );
		}

		if ( ! $this->get_state() ) {
			$this->scan( true, 'first' );
		}
	}

	/**
	 * Cron callback.
	 */
	public function scan_cron(): void {
		$this->scan( false, 'cron' );
	}

	/**
	 * A theme was switched: look at the new theme once the saved plan has run.
	 */
	public function on_switch(): void {
		$this->scan( true, 'switch' );
	}

	/**
	 * Something was updated. A theme update replaces files this request still has loaded,
	 * so the scan runs a little later, in its own request.
	 *
	 * @param mixed $upgrader Upgrader.
	 * @param mixed $extra    What was upgraded: type, action, themes.
	 */
	public function on_upgrade( $upgrader, $extra = array() ): void {
		unset( $upgrader );

		if ( is_array( $extra ) && isset( $extra['type'] ) && 'theme' === $extra['type'] && ! wp_next_scheduled( self::CRON_SOON ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_SOON );
		}
	}

	/**
	 * Capability needed to change the email alert settings.
	 *
	 * @return string
	 */
	public static function alert_capability(): string {
		/**
		 * Filters the capability needed to turn the missing template email on or off, and to choose who gets it.
		 *
		 * @param string $capability Default manage_options.
		 */
		return (string) apply_filters( 'axle_page_template_usage_alert_capability', 'manage_options' );
	}

	/**
	 * The record of the last scan.
	 *
	 * @return array<string, mixed> Empty before the first scan. Keys: fingerprint, checked, templates, missing.
	 */
	public function get_state(): array {
		$state = get_option( self::OPTION_STATE );

		return is_array( $state ) && isset( $state['fingerprint'] ) ? $state : array();
	}

	/**
	 * Post types the scan looks at.
	 *
	 * @return string[]
	 */
	private function post_types(): array {
		return array_values( array_map( 'strval', wp_list_pluck( $this->templates->get_post_types(), 'name' ) ) );
	}

	/**
	 * A hash of the active theme and the template names it offers per post type.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		$parts = array( get_stylesheet(), get_template() );
		foreach ( $this->post_types() as $type ) {
			$keys = array_map( 'strval', array_keys( $this->templates->get_templates( $type ) ) );
			sort( $keys );
			$parts[ $type ] = $keys;
		}

		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Compares the theme's templates with the last scan, and reports what is new.
	 *
	 * Without $force, the work stops after one hash compare when the theme's
	 * template list has not changed since the last scan.
	 *
	 * @param bool   $force  Look at the pages even when the template list is the same.
	 * @param string $source What asked: cron, switch, manual, first or cli.
	 * @return array<string, mixed> The record after the scan.
	 */
	public function scan( bool $force = false, string $source = 'manual' ): array {
		$previous    = $this->get_state();
		$fingerprint = $this->fingerprint();

		if ( ! $force && $previous && ( $previous['fingerprint'] ?? '' ) === $fingerprint ) {
			$previous['checked'] = time();
			update_option( self::OPTION_STATE, $previous, false );

			return $previous;
		}

		$offered = array();
		$missing = array();
		$new     = array();
		$old     = isset( $previous['missing'] ) && is_array( $previous['missing'] ) ? $previous['missing'] : array();
		$names   = isset( $previous['templates'] ) && is_array( $previous['templates'] ) ? $previous['templates'] : array();

		foreach ( $this->post_types() as $type ) {
			$offered[ $type ] = array_map( 'strval', $this->templates->get_templates( $type ) );

			foreach ( $this->templates->get_missing_counts( $type ) as $slug => $count ) {
				$slug  = (string) $slug;
				$entry = isset( $old[ $type ][ $slug ] ) ? $old[ $type ][ $slug ] : null;

				if ( ! $entry ) {
					$entry                 = array(
						'count' => (int) $count,
						'since' => time(),
						// What the template was called while the theme still had it.
						'name'  => isset( $names[ $type ][ $slug ] ) ? (string) $names[ $type ][ $slug ] : '',
					);
					$new[ $type ][ $slug ] = $entry;
				}

				$entry['count']            = (int) $count;
				$missing[ $type ][ $slug ] = $entry;
			}
		}

		$state = array(
			'fingerprint' => $fingerprint,
			'checked'     => time(),
			'templates'   => $offered,
			'missing'     => $missing,
		);
		update_option( self::OPTION_STATE, $state, false );

		/**
		 * Fires after the theme's templates were compared with the pages.
		 *
		 * @param array<string, mixed> $state  The record: fingerprint, checked, templates, missing.
		 * @param array<string, mixed> $new    Templates that went missing since the last scan: post type => template => count, since, name.
		 * @param string               $source Where the scan came from: cron, switch, manual, first or cli.
		 */
		do_action( 'axle_page_template_usage_scanned', $state, $new, $source );

		// The first scan sets the baseline. Templates that were already gone are not news.
		if ( $new && $previous ) {
			/**
			 * Fires when pages point at a template the theme lost since the last scan.
			 *
			 * @param array<string, array<string, array{count: int, since: int, name: string}>> $new   Post type => template => details.
			 * @param array<string, mixed>                                                      $state The record after the scan.
			 */
			do_action( 'axle_page_template_usage_missing_detected', $new, $state );

			$this->send_alert( $new );
		}

		return $state;
	}

	/**
	 * The email alert settings.
	 *
	 * @return array{enabled: bool, to: string}
	 */
	public function get_alert(): array {
		$alert = get_option( self::OPTION_ALERT );
		$alert = is_array( $alert ) ? $alert : array();

		return array(
			'enabled' => ! empty( $alert['enabled'] ),
			'to'      => isset( $alert['to'] ) ? (string) $alert['to'] : '',
		);
	}

	/**
	 * Saves the email alert settings.
	 *
	 * @param bool   $enabled Send the email.
	 * @param string $to      Address, or an empty string for the site admin.
	 * @return true|\WP_Error
	 */
	public function save_alert( bool $enabled, string $to ) {
		$to = trim( $to );
		if ( '' !== $to && ! is_email( $to ) ) {
			return new \WP_Error( 'axle_page_template_usage_bad_email', __( 'That email address is not valid.', 'axle-page-template-usage' ) );
		}

		update_option(
			self::OPTION_ALERT,
			array(
				'enabled' => $enabled,
				'to'      => sanitize_email( $to ),
			),
			false
		);

		return true;
	}

	/**
	 * Emails the template names and page counts, when the alert is on.
	 *
	 * @param array<string, array<string, array{count: int, since: int, name: string}>> $detected Post type => template => details.
	 * @return bool Whether an email was sent.
	 */
	private function send_alert( array $detected ): bool {
		$alert = $this->get_alert();
		if ( ! $alert['enabled'] ) {
			return false;
		}

		$to = '' !== $alert['to'] ? $alert['to'] : (string) get_option( 'admin_email' );

		/**
		 * Filters who gets the missing template email.
		 *
		 * @param string[]                                                                   $recipients Email addresses.
		 * @param array<string, array<string, array{count: int, since: int, name: string}>> $detected        Post type => template => details.
		 */
		$recipients = array_values( array_filter( array_map( 'sanitize_email', (array) apply_filters( 'axle_page_template_usage_alert_recipients', array( $to ), $detected ) ), 'is_email' ) );
		if ( ! $recipients ) {
			return false;
		}

		$lines = array();
		$total = 0;
		foreach ( $detected as $type => $templates ) {
			$object = get_post_type_object( (string) $type );
			$label  = $object ? $object->labels->name : (string) $type;
			foreach ( $templates as $slug => $details ) {
				$total  += (int) $details['count'];
				$lines[] = sprintf(
					/* translators: 1: template file or slug, 2: post type label, 3: number of items. */
					__( '%1$s (%2$s: %3$s)', 'axle-page-template-usage' ),
					$slug,
					$label,
					number_format_i18n( (int) $details['count'] )
				);
			}
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: number of templates. */
			_n( '%1$s: %2$s page template went missing', '%1$s: %2$s page templates went missing', count( $lines ), 'axle-page-template-usage' ),
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
			number_format_i18n( count( $lines ) )
		);
		$message = sprintf(
			/* translators: 1: number of items, 2: list of templates, 3: URL of the Missing tab. */
			__( "%1\$s items use a template the active theme no longer has, so they show the default layout. Nothing was changed.\n\n%2\$s\n\nReview them and move them to another template:\n%3\$s\n", 'axle-page-template-usage' ),
			number_format_i18n( $total ),
			implode( "\n", $lines ),
			admin_url( 'themes.php?page=axle-page-template-usage&tab=missing' )
		);

		/**
		 * Filters the subject and text of the missing template email.
		 *
		 * @param array<string, string>                                                                  $mail Subject and plain text body: subject, message.
		 * @param array<string, array<string, array{count: int, since: int, name: string}>> $detected  Post type => template => details.
		 */
		$mail = (array) apply_filters(
			'axle_page_template_usage_alert_email',
			array(
				'subject' => $subject,
				'message' => $message,
			),
			$detected
		);

		// One email per detected change, to people the admin chose.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$sent = wp_mail( $recipients, (string) ( $mail['subject'] ?? $subject ), (string) ( $mail['message'] ?? $message ) );

		if ( $sent ) {
			/**
			 * Fires after the missing template email was sent.
			 *
			 * @param string[]             $recipients Who got it.
			 * @param array<string, mixed> $detected        What it was about.
			 */
			do_action( 'axle_page_template_usage_alert_sent', $recipients, $detected );
		}

		return (bool) $sent;
	}

	/**
	 * Every template pages still point at but the theme lacks, with a suggested replacement.
	 *
	 * @param string[]|null $allowed Post types the answer may cover, such as the ones the current user can edit. Null for every one.
	 * @return array<int, array{post_type: string, slug: string, name: string, count: int, since: int, suggestion: array{template: string, name: string, reason: string, reason_label: string}}>
	 */
	public function get_missing( ?array $allowed = null ): array {
		$state = $this->get_state();
		$rows  = array();
		$types = $this->post_types();
		if ( null !== $allowed ) {
			$types = array_values( array_intersect( $types, $allowed ) );
		}

		foreach ( $types as $type ) {
			foreach ( $this->templates->get_missing_counts( $type ) as $slug => $count ) {
				$slug   = (string) $slug;
				$entry  = isset( $state['missing'][ $type ][ $slug ] ) ? $state['missing'][ $type ][ $slug ] : array();
				$name   = isset( $entry['name'] ) ? (string) $entry['name'] : '';
				$rows[] = array(
					'post_type'  => $type,
					'slug'       => $slug,
					'name'       => '' !== $name ? $name : $slug,
					'count'      => (int) $count,
					'since'      => isset( $entry['since'] ) ? (int) $entry['since'] : 0,
					'suggestion' => $this->suggest( $type, $slug, $name ),
				);
			}
		}

		return $rows;
	}

	/**
	 * Labels for why a template is suggested.
	 *
	 * @return array<string, string> Reason => label.
	 */
	public static function reason_labels(): array {
		/**
		 * Filters the labels that say why a replacement template is suggested.
		 *
		 * @param array<string, string> $labels Reason => label. Reasons: same_file, same_name, default.
		 */
		return (array) apply_filters(
			'axle_page_template_usage_suggestion_reasons',
			array(
				'same_file' => __( 'Same file name in another folder', 'axle-page-template-usage' ),
				'same_name' => __( 'Same template name', 'axle-page-template-usage' ),
				'default'   => __( 'No close match, so the default template', 'axle-page-template-usage' ),
			)
		);
	}

	/**
	 * The closest template the theme still has: the same file name in another folder,
	 * a file with the same Template Name, or the default template.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug      Missing template value.
	 * @param string $old_name  What the template was called, when known.
	 * @return array{template: string, name: string, reason: string, reason_label: string}
	 */
	public function suggest( string $post_type, string $slug, string $old_name = '' ): array {
		$available = $this->templates->get_templates( $post_type );
		$template  = Templates::DEFAULT_TEMPLATE;
		$reason    = 'default';
		$base      = strtolower( basename( $slug ) );

		foreach ( $available as $key => $name ) {
			if ( strtolower( basename( (string) $key ) ) === $base ) {
				$template = (string) $key;
				$reason   = 'same_file';
				break;
			}
		}

		if ( 'default' === $reason ) {
			$wanted = array_filter( array( $this->normalize_name( $old_name ), $this->normalize_name( (string) preg_replace( '/\.php$/i', '', $base ) ) ) );
			foreach ( $available as $key => $name ) {
				if ( in_array( $this->normalize_name( (string) $name ), $wanted, true ) || in_array( $this->normalize_name( (string) preg_replace( '/\.php$/i', '', strtolower( basename( (string) $key ) ) ) ), $wanted, true ) ) {
					$template = (string) $key;
					$reason   = 'same_name';
					break;
				}
			}
		}

		$suggestion = array(
			'template' => $template,
			'reason'   => $reason,
		);

		/**
		 * Filters the template suggested for pages on a template the theme lost.
		 *
		 * @param array<string, string>                $suggestion Template to move to (a template the theme offers, or default) and why.
		 * @param string                                  $post_type  Post type.
		 * @param string                                  $slug       The missing template.
		 * @param array<string, string>                   $available  Templates the theme offers.
		 */
		$suggestion = (array) apply_filters( 'axle_page_template_usage_missing_suggestion', $suggestion, $post_type, $slug, $available );
		$template   = isset( $suggestion['template'] ) ? (string) $suggestion['template'] : Templates::DEFAULT_TEMPLATE;
		if ( ! $this->templates->is_default( $template ) && ! isset( $available[ $template ] ) ) {
			// Fail safe: a suggestion the theme does not offer cannot be applied.
			$template = Templates::DEFAULT_TEMPLATE;
			$reason   = 'default';
		} else {
			$reason = isset( $suggestion['reason'] ) ? (string) $suggestion['reason'] : $reason;
		}

		$labels = self::reason_labels();

		return array(
			'template'     => $this->templates->is_default( $template ) ? Templates::DEFAULT_TEMPLATE : $template,
			'name'         => $this->templates->is_default( $template ) ? __( 'Default', 'axle-page-template-usage' ) : (string) $available[ $template ],
			'reason'       => $reason,
			'reason_label' => $labels[ $reason ] ?? $reason,
		);
	}

	/**
	 * A template name with case, spaces and dashes taken out, to compare two names.
	 *
	 * @param string $name Name or file name without the extension.
	 * @return string
	 */
	private function normalize_name( string $name ): string {
		$name = strtolower( $name );
		$name = (string) preg_replace( '/^(page|template|tpl)[-_ ]+/', '', $name );

		return (string) preg_replace( '/[^a-z0-9]+/', '', $name );
	}

	/**
	 * Total number of items on missing templates, for the post types given.
	 *
	 * @param string[]|null $allowed Post types to count, null for every one.
	 * @return int
	 */
	public function count_missing( ?array $allowed = null ): int {
		return (int) array_sum( wp_list_pluck( $this->get_missing( $allowed ), 'count' ) );
	}
}
