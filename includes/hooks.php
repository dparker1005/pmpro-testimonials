<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Used for the featured image block.
function pmpro_testimonial_featured_image_fallback( $html, $post_id, $post_thumbnail_id, $size, $attr ) {

	// Only modify for 'pmpro_testimonial' post type.
	if ( empty( $post_thumbnail_id ) && get_post_type( $post_id ) === 'pmpro_testimonial' ) {
		$current_testimonial = new PMPro_Testimonial( $post_id );
		$html = $current_testimonial->get_image( $size, $attr );
	}

	return $html;
}
add_filter( 'post_thumbnail_html', 'pmpro_testimonial_featured_image_fallback', 10, 5 );

// Adds the necessary Testimonial schema to the li within the query loop block.
function pmpro_testimonials_custom_query_loop_schema_attrs( $block_content, $block ) {
	// Check if this is the Query Loop block and if it has your specific variation
	if ( $block['blockName'] === 'core/query' && isset( $block['attrs']['query']['postType'] ) && $block['attrs']['query']['postType'] === 'pmpro_testimonial' ) {
		// Modify the output to add attributes to <li> elements
		$block_content = preg_replace_callback(
			'/<li(.*?)>/',
			function ( $matches ) {
				// Add custom attributes here
				return '<li' . $matches[1] . ' itemscope itemtype="https://schema.org/Review">';
			},
			$block_content
		);
	}
	return $block_content;
}
add_filter( 'render_block', 'pmpro_testimonials_custom_query_loop_schema_attrs', 10, 2 );

// Process the form submission.
function pmpro_testimonials_process_form_submission() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; the nonce is verified in PMPro_Testimonial_Form::process().
	if ( ! empty( $_POST['pmpro_testimonials_nonce'] ) ) {
		// Get the transient for the fields and pass it through.
		$atts = get_transient( 'pmpro_testimonials_form_required_fields_' . get_the_ID() );
		$form = new PMPro_Testimonial_Form( $atts );
		$form->process();
		
		// Form has been processed, let's clear the transient.
		delete_transient( 'pmpro_testimonials_form_required_fields_' . get_the_ID() );
	}
}
add_action( 'wp', 'pmpro_testimonials_process_form_submission' );

/**
 * Don't count testimonials when deciding which users the REST API users list shows publicly.
 *
 * WordPress lists users with published posts in any REST-enabled post type at /wp/v2/users.
 * Testimonials submitted by logged-in members are authored by them, so an approved testimonial
 * would otherwise list the member there with their display name and slug.
 *
 * @since TBD
 *
 * @param array           $prepared_args Arguments for WP_User_Query.
 * @param WP_REST_Request $request       The REST API request.
 * @return array
 */
function pmpro_testimonials_rest_user_query( $prepared_args, $request ) {
	// WordPress only limits the list to users with published posts for visitors who can't list users.
	if ( empty( $prepared_args['has_published_posts'] ) || current_user_can( 'list_users' ) ) {
		return $prepared_args;
	}

	$post_types = true === $prepared_args['has_published_posts'] ? get_post_types( array( 'show_in_rest' => true ), 'names' ) : (array) $prepared_args['has_published_posts'];
	$post_types = array_values( array_diff( $post_types, array( 'pmpro_testimonial' ) ) );

	// An empty array would turn off the published posts check and list every user, so return no users instead.
	if ( empty( $post_types ) ) {
		$prepared_args['include'] = array( 0 );
		return $prepared_args;
	}

	$prepared_args['has_published_posts'] = $post_types;
	return $prepared_args;
}
add_filter( 'rest_user_query', 'pmpro_testimonials_rest_user_query', 10, 2 );

/**
 * Don't count testimonials when deciding whether the REST API shows a single user publicly.
 *
 * WordPress lets anyone view a user through /wp/v2/users/<id> once they have a published post in
 * any REST-enabled post type, and has no filter for that check. This runs on rest_pre_dispatch and
 * returns the same error WordPress returns for users without published posts.
 *
 * @since TBD
 *
 * @param mixed           $result  Response to replace the requested version with.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request used to generate the response.
 * @return mixed
 */
function pmpro_testimonials_rest_pre_dispatch_user( $result, $server, $request ) {
	if ( ! empty( $result ) || ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
		return $result;
	}

	// REST API routes match case-insensitively, so this check does too.
	if ( ! preg_match( '#^/wp/v2/users/(\d+)$#i', $request->get_route(), $matches ) ) {
		return $result;
	}

	// Match the WordPress check: users can always view themselves, and users who can edit or list users can view anyone.
	$user_id = (int) $matches[1];
	if ( get_current_user_id() === $user_id || current_user_can( 'edit_user', $user_id ) || current_user_can( 'list_users' ) ) {
		return $result;
	}

	// Leave the request to WordPress unless testimonials are the user's only published posts.
	$post_types = array_values( array_diff( get_post_types( array( 'show_in_rest' => true ), 'names' ), array( 'pmpro_testimonial' ) ) );
	if ( ! count_user_posts( $user_id, 'pmpro_testimonial' ) || ( ! empty( $post_types ) && count_user_posts( $user_id, $post_types ) ) ) {
		return $result;
	}

	return new WP_Error( 'rest_user_cannot_view', __( 'Sorry, you are not allowed to list users.', 'pmpro-testimonials' ), array( 'status' => rest_authorization_required_code() ) );
}
add_filter( 'rest_pre_dispatch', 'pmpro_testimonials_rest_pre_dispatch_user', 10, 3 );
