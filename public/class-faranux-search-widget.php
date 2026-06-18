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

	// -------------------------------------------------------------------------
	// WP_Widget interface
	// -------------------------------------------------------------------------

	public function widget( $args, $instance ) {
		echo $args['before_widget'];

		$title = ! empty( $instance['title'] )
			? apply_filters( 'widget_title', $instance['title'] )
			: '';

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
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>"
			>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Shared rendering
	// -------------------------------------------------------------------------

	/**
	 * Renders the search form HTML.
	 * The nonce is output as a data-attribute on the form so the JS can pick
	 * it up without relying on a hidden input surviving template stripping.
	 *
	 * @param  string $query  Optional pre-filled query value.
	 * @return string
	 */
	public static function render_form( $query = '' ) {
		ob_start();
		?>
		<form
			class="faranux-strict-search-form"
			role="search"
			method="get"
			action="<?php echo esc_url( home_url( '/' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'faranux_search_nonce' ) ); ?>"
		>
			<label class="screen-reader-text" for="faranux-strict-search-input">
				<?php esc_html_e( 'Search', 'faranux-strict-search' ); ?>
			</label>
			<div class="faranux-strict-search-inner">
				<input
					type="search"
					id="faranux-strict-search-input"
					class="faranux-strict-search-input"
					name="s"
					value="<?php echo esc_attr( $query ); ?>"
					placeholder="<?php esc_attr_e( 'Search for parts, SKUs…', 'faranux-strict-search' ); ?>"
					autocomplete="off"
				>
				<button type="submit" class="faranux-strict-search-btn" aria-label="<?php esc_attr_e( 'Search', 'faranux-strict-search' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
						aria-hidden="true">
						<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
					</svg>
					<span class="screen-reader-text"><?php esc_html_e( 'Search', 'faranux-strict-search' ); ?></span>
				</button>
			</div>
			<div class="faranux-strict-search-results" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	/**
	 * Shortcode handler: [faranux_strict_search query="optional"]
	 *
	 * @param  array $atts
	 * @return string
	 */
	public static function shortcode_output( $atts ) {
		$atts = shortcode_atts(
			array( 'query' => '' ),
			$atts,
			'faranux_strict_search'
		);

		return self::render_form( sanitize_text_field( $atts['query'] ) );
	}
}

add_shortcode( 'faranux_strict_search', array( 'Faranux_Search_Widget', 'shortcode_output' ) );
