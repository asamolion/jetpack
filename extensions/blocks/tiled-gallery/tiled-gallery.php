<?php
/**
 * Tiled Gallery block. Depends on the Photon module.
 *
 * @since 6.9.0
 *
 * @package Jetpack
 */

if (
	( defined( 'IS_WPCOM' ) && IS_WPCOM ) ||
	class_exists( 'Jetpack_Photon' ) && Jetpack::is_module_active( 'photon' )
) {
	jetpack_register_block(
		'jetpack/tiled-gallery',
		array(
			'render_callback' => 'jetpack_tiled_gallery_load_block_assets',
		)
	);

	/**
	 * Tiled gallery block registration/dependency declaration.
	 *
	 * @param array  $attr    Array containing the block attributes.
	 * @param string $content String containing the block content.
	 *
	 * @return string
	 */
	function jetpack_tiled_gallery_load_block_assets( $attr, $content ) {
		$dependencies = array(
			'lodash',
			'wp-i18n',
			'wp-token-list',
		);
		Jetpack_Gutenberg::load_assets_as_required( 'tiled-gallery', $dependencies );

		if ( ! preg_match_all( '/<img [^>]+>/', $content, $images ) ) {
			return $content;
		}

		$find    = array();
		$replace = array();
		foreach ( images[0] as $image_html ) {
			if ( preg_match( '/src="[^"]+"/', $image_html, $img_src ) ) {
				// @TODO make the photon sources
				$srcset = esc_attr( implode( ',', array() ) );

				$find[]    = $image_html;
				$replace[] = str_replace( '<img ', "<img $srcset", $image_html );
			}
		}

		/**
		 * Filter the output of the Tiled Galleries content.
		 *
		 * @module tiled-gallery
		 *
		 * @since 6.9.0
		 *
		 * @param string $content Tiled Gallery block content.
		 */
		return apply_filters( 'jetpack_tiled_galleries_block_content', $content );
	}
}
