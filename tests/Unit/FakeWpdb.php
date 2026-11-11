<?php
/**
 * A tiny stand-in for $wpdb that records the queries it gets.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

namespace AryanX\Axle\PageTemplateUsage\Tests\Unit;

/**
 * Answers prepare() like the real one for %s and %d, and returns what a test queues.
 */
final class FakeWpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var int */
	public $insert_id = 0;

	/** @var string[] */
	public $queries = array();

	/** @var array<int, mixed> Values get_var() returns, in order. */
	public $vars = array();

	/** @var array<int, array> Rows get_results() returns, in order. */
	public $results = array();

	/** @var object|null Row get_row() returns. */
	public $row = null;

	/** @var array[] Calls to update(). */
	public $updates = array();

	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		$i    = 0;

		return preg_replace_callback(
			'/%[sdi]/',
			static function ( $match ) use ( $args, &$i ) {
				$value = $args[ $i++ ];
				if ( '%i' === $match[0] ) {
					return (string) $value;
				}
				return '%d' === $match[0] ? (string) (int) $value : "'" . $value . "'";
			},
			$query
		);
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return 1;
	}

	public function get_var( $query = null ) {
		$this->queries[] = (string) $query;
		return array_shift( $this->vars );
	}

	public function get_results( $query = null ) {
		$this->queries[] = (string) $query;
		return (array) array_shift( $this->results );
	}

	public function get_row( $query = null ) {
		$this->queries[] = (string) $query;
		return $this->row;
	}

	public function get_col( $query = null ) {
		$this->queries[] = (string) $query;
		return array();
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->updates[] = array( $table, $data, $where );
		return 3;
	}
}
