<?php
/**
 * Plugin-specific test stubs. The shared template never overwrites this file.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

if ( ! class_exists( 'WP_Post_Type' ) ) {
	/**
	 * Enough of WP_Post_Type for code that reads its name, labels and capabilities.
	 */
	#[\AllowDynamicProperties]
	class WP_Post_Type {
		/** @var string */
		public $name = '';
		/** @var object */
		public $cap;
		/** @var object */
		public $labels;

		public function __construct( $post_type, $args = array() ) {
			$this->name   = $post_type;
			$this->cap    = (object) ( $args['cap'] ?? array(
					'edit_posts'         => 'edit_' . $post_type . 's',
					'edit_others_posts'  => 'edit_others_' . $post_type . 's',
					'read_private_posts' => 'read_private_' . $post_type . 's',
				) );
			$this->labels = (object) ( $args['labels'] ?? array( 'name' => $post_type ) );
		}
	}
}
