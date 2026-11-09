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
	 * Sets up the abilities.
	 *
	 * @param Templates $templates Template lookups and moves.
	 */
	public function __construct( Templates $templates ) {
		$this->templates = $templates;
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
				'description'         => __( 'Every template the theme offers for a post type with how many items use it, plus templates items still point at that the theme no longer has (status missing).', 'axle-page-template-usage' ),
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
				'permission_callback' => static function (): bool {
					return current_user_can( ReportPage::capability() );
				},
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
					),
				),
				'execute_callback'    => array( $this, 'reassign' ),
				'permission_callback' => array( $this, 'can_reassign' ),
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

		return $this->templates->reassign( $post_type, $from, (string) ( $input['to'] ?? '' ), ReportPage::move_limit( $post_type ), true );
	}

	/**
	 * Permission: the report capability, and editing items of the post type.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_reassign( $input = null ): bool {
		$object = get_post_type_object( (string) ( ( (array) $input )['post_type'] ?? 'page' ) );

		return current_user_can( ReportPage::capability() ) && ( ! $object || current_user_can( $object->cap->edit_posts ) );
	}

	/**
	 * The post type from the input, if it uses templates.
	 *
	 * @param array|null $input Ability input.
	 * @return string|\WP_Error
	 */
	private function post_type( $input ) {
		$post_type = sanitize_key( (string) ( ( (array) $input )['post_type'] ?? 'page' ) );

		if ( ! $this->templates->uses_templates( $post_type ) ) {
			/* translators: %s: post type name. */
			return new \WP_Error( 'axle_page_template_usage_no_templates', sprintf( __( 'The post type "%s" does not exist or has no templates.', 'axle-page-template-usage' ), $post_type ) );
		}

		return $post_type;
	}
}
