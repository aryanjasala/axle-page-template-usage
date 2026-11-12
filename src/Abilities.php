<?php
/**
 * Abilities: template audits for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage;

use AryanX\Axle\PageTemplateUsage\Admin\ReportPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Axle Page Template Usage's abilities on WordPress 6.9 and later.
 *
 * Useful after a theme switch: "which pages are on a template the theme no
 * longer has? Move them to the default."
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-page-template-usage';

	/**
	 * Template lookups and moves.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * Theme check.
	 *
	 * @var ThemeCheck
	 */
	private ThemeCheck $theme_check;

	/**
	 * Template history.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Finder of missing templates.
	 *
	 * @var Missing
	 */
	private Missing $missing;

	/**
	 * Front end preview.
	 *
	 * @var Preview
	 */
	private Preview $preview;

	/**
	 * Sets up the abilities.
	 *
	 * @param Templates    $templates   Template lookups and moves.
	 * @param ThemeCheck   $theme_check Theme check.
	 * @param History      $history     Template history.
	 * @param Missing|null $missing     Finder of missing templates.
	 * @param Preview|null $preview     Front end preview.
	 */
	public function __construct( Templates $templates, ThemeCheck $theme_check, History $history, ?Missing $missing = null, ?Preview $preview = null ) {
		$this->templates   = $templates;
		$this->theme_check = $theme_check;
		$this->history     = $history;
		$this->missing     = $missing ?? new Missing( $templates );
		$this->preview     = $preview ?? new Preview( $templates );
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the Axle Page Template Usage category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Page Template Usage', 'axle-page-template-usage' ),
				'description' => __( 'Which page templates are in use, and which items point at templates the theme no longer has.', 'axle-page-template-usage' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$post_type = array(
			'type'        => 'string',
			'default'     => 'page',
			'description' => __( 'Post type, such as page. Only types with templates.', 'axle-page-template-usage' ),
		);

		wp_register_ability(
			self::CATEGORY . '/get-report',
			array(
				'label'               => __( 'Get template usage', 'axle-page-template-usage' ),
				'description'         => __( 'Every template the theme offers for a post type with how many items use it, plus templates items still point at that the theme no longer has (status missing). Only for post types the user can edit.', 'axle-page-template-usage' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'post_type' => $post_type ),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'missing'   => array(
							'type'        => 'integer',
							'description' => 'Items on templates the theme no longer has.',
						),
						'templates' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'   => array( 'type' => 'string' ),
									'name'   => array( 'type' => 'string' ),
									'count'  => array( 'type' => 'integer' ),
									'status' => array(
										'type' => 'string',
										'enum' => array( 'default', 'used', 'unused', 'missing' ),
									),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_report' ),
				'permission_callback' => array( $this, 'can_use_post_type' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		wp_register_ability(
			self::CATEGORY . '/get-missing',
			array(
				'label'               => __( 'Get missing templates', 'axle-page-template-usage' ),
				'description'         => __( 'Templates that items still point at but the active theme no longer has, with how many items, since when, and the closest template the theme still has. Only covers post types the user can edit. Changes nothing: use reassign to move the items.', 'axle-page-template-usage' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Only this post type. Default: every post type the user can edit.', 'axle-page-template-usage' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total'     => array( 'type' => 'integer' ),
						'templates' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'post_type'  => array( 'type' => 'string' ),
									'slug'       => array( 'type' => 'string' ),
									'count'      => array( 'type' => 'integer' ),
									'since'      => array(
										'type'        => 'integer',
										'description' => 'Unix time the plugin first saw it missing, 0 when not known.',
									),
									'suggestion' => array(
										'type'       => 'object',
										'properties' => array(
											'template' => array( 'type' => 'string' ),
											'reason'   => array(
												'type' => 'string',
												'enum' => array( 'same_file', 'same_name', 'default' ),
											),
										),
									),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_missing' ),
				'permission_callback' => array( $this, 'can_read_missing' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		wp_register_ability(
			self::CATEGORY . '/get-preview-url',
			array(
				'label'               => __( 'Get a preview link', 'axle-page-template-usage' ),
				'description'         => __( 'A front end link that shows one page with another template, for the current user only. Nothing is saved. The user must be able to edit the page.', 'axle-page-template-usage' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => __( 'The page to preview.', 'axle-page-template-usage' ),
						),
						'template' => array(
							'type'        => 'string',
							'description' => __( 'Template the theme offers, or default.', 'axle-page-template-usage' ),
						),
					),
					'required'             => array( 'post_id', 'template' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'url' => array( 'type' => 'string' ) ),
				),
				'execute_callback'    => array( $this, 'get_preview_url' ),
				'permission_callback' => array( $this, 'can_preview' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		wp_register_ability(
			self::CATEGORY . '/reassign',
			array(
				'label'               => __( 'Move items to another template', 'axle-page-template-usage' ),
				'description'         => __( 'Moves items from one template to another, at most 500 per run, skipping items the user may not edit. Run it again while remaining is above 0.', 'axle-page-template-usage' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => $post_type,
						'from'      => array(
							'type'        => 'string',
							'description' => __( 'Template file or slug to move away from, default, or missing for every template the theme no longer has.', 'axle-page-template-usage' ),
						),
						'to'        => array(
							'type'        => 'string',
							'description' => __( 'Template the theme offers, or default.', 'axle-page-template-usage' ),
						),
					),
					'required'             => array( 'from', 'to' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'moved'     => array( 'type' => 'integer' ),
						'skipped'   => array( 'type' => 'integer' ),
						'remaining' => array( 'type' => 'integer' ),
						'batch'     => array(
							'type'        => 'string',
							'description' => 'Batch ID of this move in the template history, empty when nothing moved.',
						),
					),
				),
				'execute_callback'    => array( $this, 'reassign' ),
				'permission_callback' => array( $this, 'can_use_post_type' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		wp_register_ability(
			self::CATEGORY . '/check-theme',
			array(
				'label'               => __( 'Check a theme before switching', 'axle-page-template-usage' ),
				'description'         => __( 'For an installed theme that is not active: which items use a template the theme does not have, grouped by template with counts, for the post types the user can edit. Read-only. Use it before switching themes.', 'axle-page-template-usage' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'theme'     => array(
							'type'        => 'string',
							'description' => __( 'Folder name of an installed theme that is not active, such as twentytwentyfour.', 'axle-page-template-usage' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Only this post type. Default: every post type with templates.', 'axle-page-template-usage' ),
						),
					),
					'required'             => array( 'theme' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'theme'     => array( 'type' => 'string' ),
						'name'      => array( 'type' => 'string' ),
						'total'     => array(
							'type'        => 'integer',
							'description' => 'Items that would lose their template.',
						),
						'templates' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'post_type'       => array( 'type' => 'string' ),
									'slug'            => array( 'type' => 'string' ),
									'name'            => array( 'type' => 'string' ),
									'count'           => array( 'type' => 'integer' ),
									'already_missing' => array( 'type' => 'boolean' ),
									'planned'         => array(
										'type'        => 'string',
										'description' => 'Template saved to use instead on activation, or empty.',
									),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'check_theme' ),
				'permission_callback' => array( $this, 'can_check_theme' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => true,
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		wp_register_ability(
			self::CATEGORY . '/get-history',
			array(
				'label'               => __( 'Get template history', 'axle-page-template-usage' ),
				'description'         => __( 'Recorded template changes of the post types the user can edit, newest first: post, old and new template, user, source, time and batch, and whether each can still be undone. Read-only.', 'axle-page-template-usage' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array( 'type' => 'integer' ),
						'source'  => array(
							'type' => 'string',
							'enum' => History::SOURCES,
						),
						'batch'   => array( 'type' => 'string' ),
						'limit'   => array(
							'type'    => 'integer',
							'default' => 50,
							'maximum' => 200,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total'   => array( 'type' => 'integer' ),
						'entries' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'       => array( 'type' => 'integer' ),
									'post_id'  => array( 'type' => 'integer' ),
									'from'     => array( 'type' => 'string' ),
									'to'       => array( 'type' => 'string' ),
									'user_id'  => array( 'type' => 'integer' ),
									'source'   => array( 'type' => 'string' ),
									'batch'    => array( 'type' => 'string' ),
									'when'     => array( 'type' => 'string' ),
									'undoable' => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_history' ),
				'permission_callback' => array( $this, 'can_read_history' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => true,
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Ability: what a theme switch would do to templates.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function check_theme( $input = null ) {
		$input     = (array) $input;
		$theme     = (string) ( $input['theme'] ?? '' );
		$post_type = self::theme_post_type( $input );
		$result    = $this->theme_check->check( $theme, '' !== $post_type ? $post_type : null, ReportPage::accessible_types() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$mapping = $this->theme_check->get_mapping( $theme );
		$rows    = array();
		foreach ( $result['groups'] as $type => $group ) {
			foreach ( $group as $row ) {
				$rows[] = array(
					'post_type'       => (string) $type,
					'slug'            => $row['slug'],
					'name'            => $row['name'],
					'count'           => $row['count'],
					'already_missing' => $row['already_missing'],
					'planned'         => (string) ( $mapping[ $type ][ $row['slug'] ] ?? '' ),
				);
			}
		}

		return array(
			'theme'     => $result['theme']['stylesheet'],
			'name'      => $result['theme']['name'],
			'total'     => $result['total'],
			'templates' => $rows,
		);
	}

	/**
	 * Ability: recorded template changes.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function get_history( $input = null ): array {
		$input  = (array) $input;
		$result = $this->history->query(
			array(
				'post_id'    => absint( $input['post_id'] ?? 0 ),
				'source'     => sanitize_key( (string) ( $input['source'] ?? '' ) ),
				'batch'      => preg_replace( '/[^a-f0-9-]/', '', strtolower( (string) ( $input['batch'] ?? '' ) ) ),
				'per_page'   => min( 200, max( 1, absint( $input['limit'] ?? 50 ) ) ),
				'post_types' => ReportPage::accessible_types(),
			)
		);

		$entries = array();
		foreach ( $result['items'] as $entry ) {
			$entries[] = array(
				'id'       => (int) $entry->id,
				'post_id'  => (int) $entry->post_id,
				'from'     => '' === $entry->old_template ? Templates::DEFAULT_TEMPLATE : (string) $entry->old_template,
				'to'       => '' === $entry->new_template ? Templates::DEFAULT_TEMPLATE : (string) $entry->new_template,
				'user_id'  => (int) $entry->user_id,
				'source'   => (string) $entry->source,
				'batch'    => (string) $entry->batch,
				'when'     => (string) $entry->created,
				'undoable' => null === $this->history->blocker( $entry ),
			);
		}

		return array(
			'total'   => $result['total'],
			'entries' => $entries,
		);
	}

	/**
	 * Ability: the usage report for a post type.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function get_report( $input = null ) {
		$post_type = $this->post_type( $input );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$rows = array_map(
			static function ( array $row ): array {
				return array(
					'slug'   => (string) $row['slug'],
					'name'   => (string) $row['name'],
					'count'  => (int) $row['count'],
					'status' => (string) $row['status'],
				);
			},
			$this->templates->get_report( $post_type )
		);

		return array(
			'post_type' => $post_type,
			'missing'   => (int) array_sum( $this->templates->get_missing_counts( $post_type ) ),
			'templates' => $rows,
		);
	}

	/**
	 * Ability: the templates items point at that the theme lost.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function get_missing( $input = null ): array {
		$post_type = self::theme_post_type( $input );
		$allowed   = '' !== $post_type ? array( $post_type ) : ReportPage::accessible_types();
		$rows      = array();

		foreach ( $this->missing->get_missing( array_values( array_intersect( $allowed, ReportPage::accessible_types() ) ) ) as $row ) {
			$rows[] = array(
				'post_type'  => $row['post_type'],
				'slug'       => $row['slug'],
				'count'      => $row['count'],
				'since'      => $row['since'],
				'suggestion' => array(
					'template' => $row['suggestion']['template'],
					'reason'   => $row['suggestion']['reason'],
				),
			);
		}

		return array(
			'total'     => (int) array_sum( wp_list_pluck( $rows, 'count' ) ),
			'templates' => $rows,
		);
	}

	/**
	 * Ability: a link that previews a page with a template.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function get_preview_url( $input = null ) {
		$input = (array) $input;
		$url   = $this->preview->url( absint( $input['post_id'] ?? 0 ), (string) ( $input['template'] ?? '' ) );

		if ( '' === $url ) {
			return new \WP_Error( 'axle_page_template_usage_no_preview', __( 'That page cannot be previewed with that template: it does not exist, you cannot edit it, or the theme does not have the template.', 'axle-page-template-usage' ), array( 'status' => 400 ) );
		}

		return array( 'url' => $url );
	}

	/**
	 * Permission for get-missing: the report capability, plus edit_posts on the post type when one is given.
	 *
	 * Without a post type, the answer only covers the post types the user can edit.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_read_missing( $input = null ): bool {
		$post_type = self::theme_post_type( $input );

		return current_user_can( ReportPage::capability() ) && ( '' === $post_type || ReportPage::can_access( $post_type ) );
	}

	/**
	 * Permission for get-preview-url: access to the page's post type, and the right to edit that page.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_preview( $input = null ): bool {
		return Preview::can_preview( absint( ( (array) $input )['post_id'] ?? 0 ) );
	}

	/**
	 * Ability: moves items to another template.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function reassign( $input = null ) {
		$post_type = $this->post_type( $input );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$input = (array) $input;
		$from  = (string) ( $input['from'] ?? '' );
		$from  = 'missing' === $from ? Templates::MISSING : $from;

		return $this->templates->reassign( $post_type, $from, (string) ( $input['to'] ?? '' ), ReportPage::move_limit( $post_type ), true, 'ability' );
	}

	/**
	 * Permission for get-report and reassign: the report capability, and edit_posts on the post type.
	 *
	 * The post type is read exactly as the ability reads it, so a different
	 * spelling of a post type cannot get past this check.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_use_post_type( $input = null ): bool {
		return ReportPage::can_access( self::post_type_name( $input ) );
	}

	/**
	 * Permission for check-theme: the capabilities to check a theme, and edit_posts on the post type when one is given.
	 *
	 * Without a post type, the answer only covers the post types the user can edit.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_check_theme( $input = null ): bool {
		$post_type = self::theme_post_type( $input );

		return ReportPage::can_check_themes() && ( '' === $post_type || ReportPage::can_access( $post_type ) );
	}

	/**
	 * Permission for get-history: the report capability. The answer only covers the post types the user can edit.
	 *
	 * @return bool
	 */
	public function can_read_history(): bool {
		return current_user_can( ReportPage::capability() );
	}

	/**
	 * The post type from the input, if it uses templates.
	 *
	 * @param array|null $input Ability input.
	 * @return string|\WP_Error
	 */
	private function post_type( $input ) {
		$post_type = self::post_type_name( $input );

		if ( ! $this->templates->uses_templates( $post_type ) ) {
			/* translators: %s: post type name. */
			return new \WP_Error( 'axle_page_template_usage_no_templates', sprintf( __( 'The post type "%s" does not exist or has no templates.', 'axle-page-template-usage' ), $post_type ) );
		}

		return $post_type;
	}

	/**
	 * The post type named in the input of get-report and reassign. Default page.
	 *
	 * @param array|null $input Ability input.
	 * @return string
	 */
	private static function post_type_name( $input ): string {
		return sanitize_key( (string) ( ( (array) $input )['post_type'] ?? 'page' ) );
	}

	/**
	 * The post type named in the input of check-theme, or an empty string for every one.
	 *
	 * @param array|null $input Ability input.
	 * @return string
	 */
	private static function theme_post_type( $input ): string {
		return sanitize_key( (string) ( ( (array) $input )['post_type'] ?? '' ) );
	}
}
