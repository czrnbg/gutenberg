<?php
/**
 * Layout block support flag.
 *
 * @package gutenberg
 */

/**
 * Registers the layout block attribute for block types that support it.
 *
 * @param WP_Block_Type $block_type Block Type.
 */
function gutenberg_register_layout_support( $block_type ) {
	$support_layout = gutenberg_block_has_support( $block_type, array( '__experimentalLayout' ), false );
	if ( $support_layout ) {
		if ( ! $block_type->attributes ) {
			$block_type->attributes = array();
		}

		if ( ! array_key_exists( 'layout', $block_type->attributes ) ) {
			$block_type->attributes['layout'] = array(
				'type' => 'object',
			);
		}
	}
}

/**
 * Generates the CSS corresponding to the provided layout.
 *
 * @param string $selector CSS selector.
 * @param array  $layout   Layout object.
 *
 * @return string CSS style.
 */
function gutenberg_get_layout_style( $selector, $layout ) {
	$layout_type = isset( $layout['type'] ) ? $layout['type'] : 'default';

	$style = '';
	if ( 'default' === $layout_type ) {
		$content_size = isset( $layout['contentSize'] ) ? $layout['contentSize'] : null;
		$wide_size    = isset( $layout['wideSize'] ) ? $layout['wideSize'] : null;

		$all_max_width_value  = $content_size ? $content_size : $wide_size;
		$wide_max_width_value = $wide_size ? $wide_size : $content_size;

		// Make sure there is a single CSS rule, and all tags are stripped for security.
		// TODO: Use `safecss_filter_attr` instead - once https://core.trac.wordpress.org/ticket/46197 is patched.
		$all_max_width_value  = wp_strip_all_tags( explode( ';', $all_max_width_value )[0] );
		$wide_max_width_value = wp_strip_all_tags( explode( ';', $wide_max_width_value )[0] );

		$style = '';
		if ( $content_size || $wide_size ) {
			$style  = "$selector > * {";
			$style .= 'max-width: ' . esc_html( $all_max_width_value ) . ';';
			$style .= 'margin-left: auto !important;';
			$style .= 'margin-right: auto !important;';
			$style .= '}';

			$style .= "$selector > .alignwide { max-width: " . esc_html( $wide_max_width_value ) . ';}';

			$style .= "$selector .alignfull { max-width: none; }";
		}

		$style .= "$selector .alignleft { float: left; margin-right: 2em; }";
		$style .= "$selector .alignright { float: right; margin-left: 2em; }";
	} elseif ( 'flex' === $layout_type ) {
		$style  = "$selector {";
		$style .= 'display: flex;';
		$style .= 'column-gap: 0.5em;';
		$style .= 'align-items: center;';
		$style .= '}';
	}

	return $style;
}

/**
 * Renders the layout config to the block wrapper.
 *
 * @param  string $block_content Rendered block content.
 * @param  array  $block         Block object.
 * @return string                Filtered block content.
 */
function gutenberg_render_layout_support_flag( $block_content, $block ) {
	$block_type     = WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] );
	$support_layout = gutenberg_block_has_support( $block_type, array( '__experimentalLayout' ), false );
	if ( ! $support_layout || ! isset( $block['attrs']['layout'] ) ) {
		return $block_content;
	}

	$used_layout = $block['attrs']['layout'];
	if ( isset( $used_layout['inherit'] ) && $used_layout['inherit'] ) {
		$tree           = WP_Theme_JSON_Resolver_Gutenberg::get_merged_data( array(), 'theme' );
		$default_layout = _wp_array_get( $tree->get_settings(), array( 'layout' ) );
		if ( ! $default_layout ) {
			return $block_content;
		}
		$used_layout = $default_layout;
	}

	$id    = uniqid();
	$style = gutenberg_get_layout_style( ".wp-container-$id", $used_layout );
	// This assumes the hook only applies to blocks with a single wrapper.
	// I think this is a reasonable limitation for that particular hook.
	$content = preg_replace(
		'/' . preg_quote( 'class="', '/' ) . '/',
		'class="wp-container-' . $id . ' ',
		$block_content,
		1
	);

	// Ideally styles should be loaded in the head, but blocks may be parsed
	// after that, so loading in the footer for now.
	// See https://core.trac.wordpress.org/ticket/53494.
	add_action(
		'wp_footer',
		function () use ( $style ) {
			echo '<style>' . $style . '</style>';
		}
	);

	return $content;
}

// Register the block support. (overrides core one).
WP_Block_Supports::get_instance()->register(
	'layout',
	array(
		'register_attribute' => 'gutenberg_register_layout_support',
	)
);
if ( function_exists( 'wp_render_layout_support_flag' ) ) {
	remove_filter( 'render_block', 'wp_render_layout_support_flag' );
}
add_filter( 'render_block', 'gutenberg_render_layout_support_flag', 10, 2 );

/**
 * For themes without theme.json file, make sure
 * to restore the inner div for the group block
 * to avoid breaking styles relying on that div.
 *
 * @param  string $block_content Rendered block content.
 * @param  array  $block         Block object.
 * @return string                Filtered block content.
 */
function gutenberg_restore_group_inner_container( $block_content, $block ) {
	$group_with_inner_container_regex = '/(^\s*<div\b[^>]*wp-block-group(\s|")[^>]*>)(\s*<div\b[^>]*wp-block-group__inner-container(\s|")[^>]*>)((.|\S|\s)*)/';

	if (
		'core/group' !== $block['blockName'] ||
		WP_Theme_JSON_Resolver_Gutenberg::theme_has_support() ||
		1 === preg_match( $group_with_inner_container_regex, $block_content )
	) {
		return $block_content;
	}

	$replace_regex   = '/(^\s*<div\b[^>]*wp-block-group[^>]*>)(.*)(<\/div>\s*$)/ms';
	$updated_content = preg_replace_callback(
		$replace_regex,
		function( $matches ) {
			return $matches[1] . '<div class="wp-block-group__inner-container">' . $matches[2] . '</div>' . $matches[3];
		},
		$block_content
	);
	return $updated_content;
}

// This can be removed when plugin support requires WordPress 5.8.0+.
if ( ! function_exists( 'wp_restore_group_inner_container' ) ) {
	add_filter( 'render_block', 'gutenberg_restore_group_inner_container', 10, 2 );
}
