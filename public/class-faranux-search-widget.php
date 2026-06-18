<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Faranux_Search_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'faranux_search_widget',
			__( 'Faranux Strict Search', 'faranux-strict-search' ),
			array(
				'description' => __( 'Search form that enforces strict AND logic.', 'faranux-strict-search' ),
			)
		);
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget'];

		$title = ! empty( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';
		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
		}

		echo self::render_form();
		echo $args['after_widget'];
	}

	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => sanitize_text_field( $new_instance['title'] ),
		);
	}

	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? esc_attr( $instance['title'] ) : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'faranux-strict-search' ); ?>
			</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<?php
	}

	public static function render_form( $query = '' ) {
		ob_start();
		?>
		<form class="faranux-strict-search-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="faranux-strict-search-input">
				<?php esc_html_e( 'Search', 'faranux-strict-search' ); ?>
			</label>
			<input
				type="search"
				id="faranux-strict-search-input"
				class="faranux-strict-search-input"
				name="s"
				value="<?php echo esc_attr( $query ); ?>"
				placeholder="<?php esc_attr_e( 'Search for exact phrases...', 'faranux-strict-search' ); ?>"
			>
			<button type="submit">
				<?php esc_html_e( 'Search', 'faranux-strict-search' ); ?>
			</button>
			<div class="faranux-strict-search-results"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function shortcode_output( $atts ) {
		$atts = shortcode_atts(
			array(
				'query' => '',
			),
			$atts,
			'faranux_strict_search'
		);

		return self::render_form( $atts['query'] );
	}
}

add_shortcode( 'faranux_strict_search', array( 'Faranux_Search_Widget', 'shortcode_output' ) );
