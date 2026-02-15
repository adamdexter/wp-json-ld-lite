<?php
/**
 * Plugin Name: WP JSON-LD Lite
 * Plugin URI:  https://github.com/adamdexter/wp-json-ld-lite
 * Description: Generates Review JSON-LD structured data from Strong Testimonials data.
 * Version:     1.0.0
 * Author:      Adam Dexter
 * Author URI:  https://www.thestartupfoundercoach.com/
 * License:     GPL-2.0-or-later
 * Text Domain: wp-json-ld-lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPJSONLD_VERSION', '1.0.0' );
define( 'WPJSONLD_OPTION_KEY', 'wpjsonld_settings' );

/* ==========================================================================
   A2. DEPENDENCY NOTICE
   ========================================================================== */

add_action( 'admin_notices', 'wpjsonld_dependency_notice' );

function wpjsonld_dependency_notice() {
	if ( ! post_type_exists( 'wpm-testimonial' ) ) {
		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>WP JSON-LD Lite:</strong> Strong Testimonials is not active. The plugin will still output Organization, Person, and Service structured data, but Review data requires <a href="%s">Strong Testimonials</a>.</p></div>',
			esc_url( admin_url( 'plugin-install.php?s=strong+testimonials&tab=search&type=term' ) )
		);
	}
}

/* ==========================================================================
   B. HELPER FUNCTIONS
   ========================================================================== */

/**
 * Parse client_name into name, title, and embedded URL.
 * "Brianna Rader, Founder & CEO" → ['name' => 'Brianna Rader', 'title' => 'Founder & CEO', 'url' => '']
 * '<a href="https://linkedin.com/in/x/">Name</a>, Title' → extracts URL and strips HTML.
 */
function wpjsonld_parse_client_name( $raw ) {
	$result = array( 'name' => '', 'title' => '', 'url' => '' );
	if ( ! $raw ) {
		return $result;
	}

	// Extract URL from anchor tag if present.
	if ( preg_match( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $raw, $matches ) ) {
		$result['url'] = $matches[1];
	}

	// Strip HTML tags and decode entities.
	$raw          = wp_strip_all_tags( $raw );
	$raw          = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
	$parts        = explode( ',', $raw, 2 );
	$result['name'] = trim( $parts[0] );
	if ( isset( $parts[1] ) ) {
		$result['title'] = trim( $parts[1] );
	}
	return $result;
}

/**
 * Strip trailing parenthetical from company name.
 * "Juicebox (acquired in 2024)" → "Juicebox"
 */
function wpjsonld_parse_company_name( $raw ) {
	if ( ! $raw ) {
		return '';
	}
	$raw = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '/\s*\(.*?\)\s*$/', '', $raw ) );
}

/**
 * Parse newline-separated URL list (for settings textareas).
 */
function wpjsonld_parse_url_list( $text ) {
	if ( ! $text ) {
		return array();
	}
	$lines = array_map( 'trim', explode( "\n", $text ) );
	return array_values( array_filter( $lines, function ( $line ) {
		return $line !== '' && filter_var( $line, FILTER_VALIDATE_URL );
	} ) );
}

/**
 * Parse newline-separated plain text items (for knowsAbout).
 */
function wpjsonld_parse_line_list( $text ) {
	if ( ! $text ) {
		return array();
	}
	$lines = array_map( 'trim', explode( "\n", $text ) );
	return array_values( array_filter( $lines, function ( $line ) {
		return $line !== '';
	} ) );
}

/**
 * Parse newline-separated URLs for per-testimonial meta fields.
 */
function wpjsonld_parse_meta_url_list( $text ) {
	if ( ! $text ) {
		return array();
	}
	$items = array_map( 'trim', preg_split( '/[\n,]+/', $text ) );
	return array_values( array_filter( $items, function ( $item ) {
		return $item !== '' && filter_var( $item, FILTER_VALIDATE_URL );
	} ) );
}

/* ==========================================================================
   C. SETTINGS PAGE
   ========================================================================== */

add_action( 'admin_menu', 'wpjsonld_add_settings_page' );
add_action( 'admin_init', 'wpjsonld_register_settings' );

function wpjsonld_add_settings_page() {
	add_options_page(
		'WP JSON-LD Lite Settings',
		'JSON-LD Lite',
		'manage_options',
		'wpjsonld-settings',
		'wpjsonld_render_settings_page'
	);
}

function wpjsonld_get_defaults() {
	return array(
		'target_mode'       => 'homepage',
		'target_page_ids'   => '',
		'org_name'          => '',
		'org_url'           => '',
		'org_description'   => '',
		'org_sameas'        => '',
		'org_founding_date' => '',
		'org_contact_type'  => '',
		'org_contact_email' => '',
		'person_name'       => '',
		'person_description' => '',
		'person_job_title'  => '',
		'person_image'      => '',
		'person_url'        => '',
		'person_sameas'     => '',
		'person_alumni_name' => '',
		'person_alumni_url' => '',
		'person_knows_about' => '',
		'services_json'     => '[]',
	);
}

function wpjsonld_register_settings() {
	register_setting( 'wpjsonld_settings_group', WPJSONLD_OPTION_KEY, array(
		'type'              => 'array',
		'sanitize_callback' => 'wpjsonld_sanitize_settings',
		'default'           => wpjsonld_get_defaults(),
	) );

	// --- Page Targeting ---
	add_settings_section( 'wpjsonld_targeting', 'Page Targeting', '__return_false', 'wpjsonld-settings' );
	add_settings_field( 'target_mode', 'Output JSON-LD on', 'wpjsonld_field_target_mode', 'wpjsonld-settings', 'wpjsonld_targeting' );
	add_settings_field( 'target_page_ids', 'Specific Page IDs', 'wpjsonld_field_target_page_ids', 'wpjsonld-settings', 'wpjsonld_targeting' );

	// --- Organization ---
	add_settings_section( 'wpjsonld_org', 'Organization (itemReviewed)', '__return_false', 'wpjsonld-settings' );
	$org_fields = array(
		'org_name'          => 'Name',
		'org_url'           => 'URL',
		'org_description'   => 'Description',
		'org_sameas'        => 'sameAs URLs (one per line)',
		'org_founding_date' => 'Founding Date (year)',
		'org_contact_type'  => 'Contact Type',
		'org_contact_email' => 'Contact Email',
	);
	foreach ( $org_fields as $key => $label ) {
		add_settings_field( $key, $label, 'wpjsonld_field_callback', 'wpjsonld-settings', 'wpjsonld_org', array( 'key' => $key, 'label' => $label ) );
	}

	// --- Person ---
	add_settings_section( 'wpjsonld_person', 'Person (Site Owner)', '__return_false', 'wpjsonld-settings' );
	$person_fields = array(
		'person_name'        => 'Name',
		'person_description' => 'Description',
		'person_job_title'   => 'Job Title',
		'person_image'       => 'Image URL',
		'person_url'         => 'URL',
		'person_sameas'      => 'sameAs URLs (one per line)',
		'person_alumni_name' => 'Alumni Of (school name)',
		'person_alumni_url'  => 'Alumni Of (school URL)',
		'person_knows_about' => 'Knows About (one per line)',
	);
	foreach ( $person_fields as $key => $label ) {
		add_settings_field( $key, $label, 'wpjsonld_field_callback', 'wpjsonld-settings', 'wpjsonld_person', array( 'key' => $key, 'label' => $label ) );
	}

	// --- Services ---
	add_settings_section( 'wpjsonld_services', 'Services', '__return_false', 'wpjsonld-settings' );
	add_settings_field( 'services_json', 'Services JSON', 'wpjsonld_field_services_json', 'wpjsonld-settings', 'wpjsonld_services' );
}

function wpjsonld_sanitize_settings( $input ) {
	$clean = wpjsonld_get_defaults();

	$clean['target_mode'] = in_array( $input['target_mode'] ?? '', array( 'homepage', 'all', 'specific' ), true )
		? $input['target_mode']
		: 'homepage';
	$clean['target_page_ids'] = sanitize_text_field( $input['target_page_ids'] ?? '' );

	$url_keys = array( 'org_url', 'person_image', 'person_url', 'person_alumni_url' );
	foreach ( $url_keys as $key ) {
		$clean[ $key ] = esc_url_raw( $input[ $key ] ?? '' );
	}

	$text_keys = array( 'org_name', 'org_founding_date', 'org_contact_type', 'org_contact_email', 'person_name', 'person_job_title', 'person_alumni_name' );
	foreach ( $text_keys as $key ) {
		$clean[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
	}

	$textarea_keys = array( 'org_description', 'org_sameas', 'person_description', 'person_sameas', 'person_knows_about' );
	foreach ( $textarea_keys as $key ) {
		$clean[ $key ] = sanitize_textarea_field( $input[ $key ] ?? '' );
	}

	$services_raw = $input['services_json'] ?? '[]';
	$decoded      = json_decode( $services_raw, true );
	$clean['services_json'] = ( $decoded !== null && is_array( $decoded ) )
		? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		: '[]';

	return $clean;
}

function wpjsonld_field_target_mode() {
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	$mode = $opts['target_mode'] ?? 'homepage';
	$options = array(
		'homepage' => 'Homepage only',
		'all'      => 'All pages',
		'specific' => 'Specific page IDs',
	);
	foreach ( $options as $value => $label ) {
		printf(
			'<label><input type="radio" name="%s[target_mode]" value="%s" %s /> %s</label><br>',
			esc_attr( WPJSONLD_OPTION_KEY ),
			esc_attr( $value ),
			checked( $mode, $value, false ),
			esc_html( $label )
		);
	}
}

function wpjsonld_field_target_page_ids() {
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	printf(
		'<input type="text" name="%s[target_page_ids]" value="%s" class="regular-text" /><p class="description">Comma-separated page IDs. Only used when "Specific page IDs" is selected.</p>',
		esc_attr( WPJSONLD_OPTION_KEY ),
		esc_attr( $opts['target_page_ids'] ?? '' )
	);
}

function wpjsonld_field_callback( $args ) {
	$key  = $args['key'];
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	$val  = $opts[ $key ] ?? '';

	$textarea_keys = array( 'org_description', 'org_sameas', 'person_description', 'person_sameas', 'person_knows_about' );

	if ( in_array( $key, $textarea_keys, true ) ) {
		printf(
			'<textarea name="%s[%s]" rows="5" class="large-text">%s</textarea>',
			esc_attr( WPJSONLD_OPTION_KEY ),
			esc_attr( $key ),
			esc_textarea( $val )
		);
	} else {
		printf(
			'<input type="text" name="%s[%s]" value="%s" class="regular-text" />',
			esc_attr( WPJSONLD_OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( $val )
		);
	}
}

function wpjsonld_field_services_json() {
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	$val  = $opts['services_json'] ?? '[]';
	printf(
		'<textarea name="%s[services_json]" rows="20" class="large-text code">%s</textarea>',
		esc_attr( WPJSONLD_OPTION_KEY ),
		esc_textarea( $val )
	);
	echo '<p class="description">Enter a JSON array of Service objects. Invalid JSON will be replaced with <code>[]</code> on save.</p>';
}

function wpjsonld_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p class="description">Version <?php echo esc_html( WPJSONLD_VERSION ); ?></p>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'wpjsonld_settings_group' );
			do_settings_sections( 'wpjsonld-settings' );
			submit_button( 'Save Settings' );
			?>
		</form>
	</div>
	<?php
}

/* ==========================================================================
   D. TESTIMONIAL META FIELDS
   ========================================================================== */

add_action( 'wpmtst_after_client_fields', 'wpjsonld_render_meta_fields_st' );
add_action( 'add_meta_boxes_wpm-testimonial', 'wpjsonld_add_meta_box' );
add_action( 'save_post_wpm-testimonial', 'wpjsonld_save_testimonial_meta', 20, 2 );

/**
 * Render fields inside the Strong Testimonials "Client Details" meta box.
 */
/**
 * Output CSS for meta field UI (tooltip and placeholder styling). Called once.
 */
function wpjsonld_render_meta_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style>
	input[name="_jsonld_author_description"]::placeholder { color: #D0D0D0; }
	.wpjsonld-tip { position:relative; cursor:help; text-decoration:underline dotted; margin-left:2px; }
	.wpjsonld-tip .wpjsonld-tip-text {
		visibility:hidden; opacity:0;
		position:absolute; bottom:125%; left:50%; transform:translateX(-50%);
		width:340px; padding:10px 12px;
		background:#333; color:#fff; font-size:12px; line-height:1.5;
		border-radius:4px; z-index:9999;
		transition:opacity 0.15s;
	}
	.wpjsonld-tip:hover .wpjsonld-tip-text { visibility:visible; opacity:1; }
	</style>
	<?php
}

function wpjsonld_render_meta_fields_st() {
	global $wpjsonld_fields_rendered;
	if ( $wpjsonld_fields_rendered ) {
		return;
	}
	$wpjsonld_fields_rendered = true;

	global $post;
	if ( ! $post ) {
		return;
	}

	echo '<tr style="display:none"><td colspan="2">';
	wpjsonld_render_meta_styles();
	wp_nonce_field( 'wpjsonld_save_meta', 'wpjsonld_meta_nonce' );
	echo '</td></tr>';

	$fields = wpjsonld_get_meta_field_definitions( $post->ID );
	echo '<tr><td colspan="2"><hr><strong>JSON-LD Enrichment</strong></td></tr>';
	foreach ( $fields as $key => $field ) {
		$value = get_post_meta( $post->ID, $key, true );
		$placeholder = ! empty( $field['placeholder'] ) ? sprintf( ' placeholder="%s"', esc_attr( $field['placeholder'] ) ) : '';
		echo '<tr>';
		printf( '<th><label for="%s">%s</label></th>', esc_attr( $key ), esc_html( $field['label'] ) );
		echo '<td>';
		if ( $field['type'] === 'textarea' ) {
			printf(
				'<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>',
				esc_attr( $key ),
				esc_attr( $key ),
				esc_textarea( $value )
			);
		} else {
			printf(
				'<input type="%s" id="%s" name="%s" value="%s" class="regular-text"%s />',
				esc_attr( $field['type'] ),
				esc_attr( $key ),
				esc_attr( $key ),
				esc_attr( $value ),
				$placeholder
			);
		}
		if ( ! empty( $field['description'] ) ) {
			if ( ! empty( $field['description_html'] ) ) {
				printf( '<p class="description">%s</p>', wp_kses( $field['description'], array( 'span' => array( 'class' => true, 'style' => true ), 'br' => array() ) ) );
			} else {
				printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
			}
		}
		echo '</td></tr>';
	}
}

/**
 * Fallback meta box in case the Strong Testimonials hook doesn't fire.
 */
function wpjsonld_add_meta_box() {
	add_meta_box(
		'wpjsonld-meta',
		'JSON-LD Enrichment',
		'wpjsonld_render_meta_box',
		'wpm-testimonial',
		'normal',
		'default'
	);
}

function wpjsonld_render_meta_box( $post ) {
	global $wpjsonld_fields_rendered;
	if ( $wpjsonld_fields_rendered ) {
		echo '<p><em>Fields are displayed in the Client Details section above.</em></p>';
		return;
	}
	$wpjsonld_fields_rendered = true;

	wpjsonld_render_meta_styles();
	wp_nonce_field( 'wpjsonld_save_meta', 'wpjsonld_meta_nonce' );

	$fields = wpjsonld_get_meta_field_definitions( $post->ID );
	echo '<table class="form-table">';
	foreach ( $fields as $key => $field ) {
		$value = get_post_meta( $post->ID, $key, true );
		$placeholder = ! empty( $field['placeholder'] ) ? sprintf( ' placeholder="%s"', esc_attr( $field['placeholder'] ) ) : '';
		echo '<tr>';
		printf( '<th><label for="%s">%s</label></th>', esc_attr( $key ), esc_html( $field['label'] ) );
		echo '<td>';
		if ( $field['type'] === 'textarea' ) {
			printf(
				'<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>',
				esc_attr( $key ),
				esc_attr( $key ),
				esc_textarea( $value )
			);
		} else {
			printf(
				'<input type="%s" id="%s" name="%s" value="%s" class="regular-text"%s />',
				esc_attr( $field['type'] ),
				esc_attr( $key ),
				esc_attr( $key ),
				esc_attr( $value ),
				$placeholder
			);
		}
		if ( ! empty( $field['description'] ) ) {
			if ( ! empty( $field['description_html'] ) ) {
				printf( '<p class="description">%s</p>', wp_kses( $field['description'], array( 'span' => array( 'class' => true, 'style' => true ), 'br' => array() ) ) );
			} else {
				printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
			}
		}
		echo '</td></tr>';
	}
	echo '</table>';
}

function wpjsonld_get_meta_field_definitions( $post_id = 0 ) {
	$auto_desc = '';
	if ( $post_id ) {
		$parsed = wpjsonld_parse_client_name( get_post_meta( $post_id, 'client_name', true ) );
		$company = wpjsonld_parse_company_name( get_post_meta( $post_id, 'company_name', true ) );
		if ( $parsed['title'] && $company ) {
			$auto_desc = $parsed['title'] . ' of ' . $company;
		} elseif ( $parsed['title'] ) {
			$auto_desc = $parsed['title'];
		}
	}

	return array(
		'_jsonld_author_url' => array(
			'label'       => 'Author LinkedIn URL',
			'type'        => 'url',
			'description' => 'LinkedIn profile URL for the review author.',
		),
		'_jsonld_author_description' => array(
			'label'       => 'Author Description Override',
			'type'        => 'text',
			'placeholder' => $auto_desc,
			'description' => 'Overrides auto-derived "Title of Company". Recommendation: Leave blank to auto-generate<span class="wpjsonld-tip">*<span class="wpjsonld-tip-text">Auto-generates as: {Title} of {Company}<br><br>Title = text after first comma in Client Name field<br>Company = Company Name field (parentheticals stripped)<br><br>Example: "Founder &amp; CEO of Juicebox"</span></span>',
			'description_html' => true,
		),
		'_jsonld_author_sameas' => array(
			'label'       => 'Author sameAs URLs',
			'type'        => 'textarea',
			'description' => 'One URL per line (Crunchbase, press articles, etc.).',
		),
		'_jsonld_org_sameas' => array(
			'label'       => 'Company sameAs URLs',
			'type'        => 'textarea',
			'description' => 'One URL per line (LinkedIn company page, Crunchbase, etc.).',
		),
		'_jsonld_reviewed_description' => array(
			'label'       => 'Review Context Description',
			'type'        => 'textarea',
			'description' => 'Describes the coaching context for this review (e.g., "venture-backed female founder coaching").',
		),
	);
}

function wpjsonld_save_testimonial_meta( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['wpjsonld_meta_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['wpjsonld_meta_nonce'], 'wpjsonld_save_meta' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fields = wpjsonld_get_meta_field_definitions();
	foreach ( $fields as $key => $field ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$value = $_POST[ $key ];
		if ( $field['type'] === 'url' ) {
			$value = esc_url_raw( $value );
		} elseif ( $field['type'] === 'textarea' ) {
			$value = sanitize_textarea_field( $value );
		} else {
			$value = sanitize_text_field( $value );
		}

		if ( $value !== '' ) {
			update_post_meta( $post_id, $key, $value );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}
}

/* ==========================================================================
   E. JSON-LD GENERATOR
   ========================================================================== */

add_action( 'wp_head', 'wpjsonld_output_jsonld', 99 );

function wpjsonld_should_output() {
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	$mode = $opts['target_mode'] ?? 'homepage';

	switch ( $mode ) {
		case 'homepage':
			return is_front_page();
		case 'all':
			return true;
		case 'specific':
			$ids = array_map( 'intval', array_filter( explode( ',', $opts['target_page_ids'] ?? '' ) ) );
			return is_page( $ids );
		default:
			return false;
	}
}

function wpjsonld_output_jsonld() {
	if ( ! wpjsonld_should_output() ) {
		return;
	}

	$opts  = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	$graph = array();

	// Build Organization.
	$org = wpjsonld_build_organization( $opts );

	// Build Person.
	$person = wpjsonld_build_person( $opts );

	// Query all published testimonials.
	$testimonials = get_posts( array(
		'post_type'      => 'wpm-testimonial',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'ASC',
	) );

	// Build Review entities and compute aggregate.
	$reviews      = array();
	$total_rating = 0;
	$rating_count = 0;

	foreach ( $testimonials as $t ) {
		$review = wpjsonld_build_review( $t );
		if ( $review ) {
			$reviews[] = $review;
			$star = get_post_meta( $t->ID, 'star_rating', true );
			if ( $star ) {
				$total_rating += (int) $star;
				$rating_count++;
			}
		}
	}

	// Attach aggregateRating to Organization.
	if ( $rating_count > 0 ) {
		$org['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => round( $total_rating / $rating_count, 1 ),
			'reviewCount' => $rating_count,
			'bestRating'  => 5,
			'worstRating' => 1,
		);
	}

	// Build Services.
	$services = wpjsonld_build_services( $opts );

	// Assemble graph: Reviews first, then Organization, Person, Services.
	foreach ( $reviews as $r ) {
		$graph[] = $r;
	}
	$graph[] = $org;
	foreach ( $services as $s ) {
		$graph[] = $s;
	}
	$graph[] = $person;

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		printf(
			"<!-- WP JSON-LD Lite: %d reviews, avg rating: %s -->\n",
			count( $reviews ),
			$rating_count > 0 ? round( $total_rating / $rating_count, 1 ) : 'n/a'
		);
	}

	echo '<script type="application/ld+json">' . "\n";
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD in application/ld+json script tag
	echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	echo "\n</script>\n";
}

function wpjsonld_build_organization( $opts ) {
	$org = array(
		'@type' => 'Organization',
		'@id'   => '#org',
	);

	if ( ! empty( $opts['org_name'] ) ) {
		$org['name'] = $opts['org_name'];
	}
	if ( ! empty( $opts['org_url'] ) ) {
		$org['url'] = $opts['org_url'];
	}

	$sameas = wpjsonld_parse_url_list( $opts['org_sameas'] ?? '' );
	if ( $sameas ) {
		$org['sameAs'] = $sameas;
	}

	$org['founder'] = array( '@id' => '#person' );

	if ( ! empty( $opts['org_founding_date'] ) ) {
		$org['foundingDate'] = $opts['org_founding_date'];
	}

	if ( ! empty( $opts['org_contact_email'] ) || ! empty( $opts['org_contact_type'] ) ) {
		$contact = array( '@type' => 'ContactPoint' );
		if ( ! empty( $opts['org_contact_email'] ) ) {
			$contact['email'] = $opts['org_contact_email'];
		}
		if ( ! empty( $opts['org_contact_type'] ) ) {
			$contact['contactType'] = $opts['org_contact_type'];
		}
		$org['contactPoint'] = $contact;
	}

	if ( ! empty( $opts['org_description'] ) ) {
		$org['description'] = $opts['org_description'];
	}

	return $org;
}

function wpjsonld_build_person( $opts ) {
	$person = array(
		'@type' => 'Person',
		'@id'   => '#person',
	);

	if ( ! empty( $opts['person_name'] ) ) {
		$person['name'] = $opts['person_name'];
	}
	if ( ! empty( $opts['person_description'] ) ) {
		$person['description'] = $opts['person_description'];
	}
	if ( ! empty( $opts['person_job_title'] ) ) {
		$person['jobTitle'] = $opts['person_job_title'];
	}

	$sameas = wpjsonld_parse_url_list( $opts['person_sameas'] ?? '' );
	if ( $sameas ) {
		$person['sameAs'] = $sameas;
	}

	if ( ! empty( $opts['person_image'] ) ) {
		$person['image'] = $opts['person_image'];
	}
	if ( ! empty( $opts['person_url'] ) ) {
		$person['url'] = $opts['person_url'];
	}

	if ( ! empty( $opts['person_alumni_name'] ) ) {
		$alumni = array(
			'@type' => 'EducationalOrganization',
			'name'  => $opts['person_alumni_name'],
		);
		if ( ! empty( $opts['person_alumni_url'] ) ) {
			$alumni['url'] = $opts['person_alumni_url'];
		}
		$person['alumniOf'] = $alumni;
	}

	$person['worksFor'] = array( '@id' => '#org' );

	$knows = wpjsonld_parse_line_list( $opts['person_knows_about'] ?? '' );
	if ( $knows ) {
		$person['knowsAbout'] = $knows;
	}

	return $person;
}

function wpjsonld_build_review( $post ) {
	$id = $post->ID;

	// Strong Testimonials meta.
	$client_name  = get_post_meta( $id, 'client_name', true );
	$company_name = get_post_meta( $id, 'company_name', true );
	$company_url  = get_post_meta( $id, 'company_website', true );
	$star_rating  = get_post_meta( $id, 'star_rating', true );
	$thumbnail_id = get_post_meta( $id, '_thumbnail_id', true );

	// JSON-LD enrichment meta.
	$author_url          = get_post_meta( $id, '_jsonld_author_url', true );
	$author_desc_override = get_post_meta( $id, '_jsonld_author_description', true );
	$author_sameas_raw   = get_post_meta( $id, '_jsonld_author_sameas', true );
	$org_sameas_raw      = get_post_meta( $id, '_jsonld_org_sameas', true );
	$reviewed_desc       = get_post_meta( $id, '_jsonld_reviewed_description', true );

	// Parse client name.
	$parsed = wpjsonld_parse_client_name( $client_name );
	if ( ! $parsed['name'] ) {
		return null;
	}

	// Parse company name.
	$clean_company = wpjsonld_parse_company_name( $company_name );

	// Derive author description.
	$author_description = '';
	if ( $author_desc_override ) {
		$author_description = $author_desc_override;
	} elseif ( $parsed['title'] && $clean_company ) {
		$author_description = $parsed['title'] . ' of ' . $clean_company;
	} elseif ( $parsed['title'] ) {
		$author_description = $parsed['title'];
	}

	// Resolve thumbnail to URL.
	$author_image = '';
	if ( $thumbnail_id ) {
		$img_data = wp_get_attachment_image_src( (int) $thumbnail_id, 'full' );
		if ( $img_data ) {
			$author_image = $img_data[0];
		}
	}

	// Build author.
	$author = array(
		'@type'          => 'Person',
		'name'           => $parsed['name'],
		'additionalType' => 'https://schema.org/Entrepreneur',
	);

	// Use explicit meta, fall back to URL extracted from client_name anchor tag.
	$effective_author_url = $author_url ? $author_url : $parsed['url'];
	if ( $effective_author_url ) {
		$author['url'] = $effective_author_url;
	}
	if ( $author_description ) {
		$author['description'] = $author_description;
	}
	if ( $author_image ) {
		$author['image'] = $author_image;
	}

	// Build worksFor.
	if ( $clean_company ) {
		$works_for = array(
			'@type' => 'Organization',
			'name'  => $clean_company,
		);
		if ( $company_url ) {
			$works_for['url'] = $company_url;
		}
		$org_sameas_arr = wpjsonld_parse_meta_url_list( $org_sameas_raw );
		if ( $org_sameas_arr ) {
			$works_for['sameAs'] = $org_sameas_arr;
		}
		$author['worksFor'] = $works_for;
	}

	// Author sameAs.
	$author_sameas_arr = wpjsonld_parse_meta_url_list( $author_sameas_raw );
	if ( $author_sameas_arr ) {
		$author['sameAs'] = $author_sameas_arr;
	}

	// Build reviewBody — preserve paragraph breaks as newlines.
	$content = $post->post_content;
	$content = str_replace( array( "\r\n", "\r" ), "\n", $content );
	$content = wp_strip_all_tags( $content );
	$content = trim( $content );

	// Build Review.
	$review = array(
		'@type'         => 'Review',
		'author'        => $author,
		'reviewBody'    => $content,
		'reviewRating'  => null,
		'itemReviewed'  => null,
	);

	// Name from post title.
	if ( $post->post_title ) {
		$review['name'] = $post->post_title;
	}

	// datePublished.
	$review['datePublished'] = get_the_date( 'Y-m-d', $post );

	// inLanguage.
	$review['inLanguage'] = 'en';

	// reviewRating.
	if ( $star_rating ) {
		$review['reviewRating'] = array(
			'@type'       => 'Rating',
			'ratingValue' => (int) $star_rating,
			'bestRating'  => 5,
			'worstRating' => 1,
		);
	} else {
		unset( $review['reviewRating'] );
	}

	// itemReviewed.
	if ( $reviewed_desc ) {
		$review['itemReviewed'] = array(
			'@type'       => 'Organization',
			'@id'         => '#org',
			'description' => $reviewed_desc,
		);
	} else {
		$review['itemReviewed'] = array( '@id' => '#org' );
	}

	// publisher.
	$review['publisher'] = array( '@id' => '#org' );

	return $review;
}

function wpjsonld_build_services( $opts ) {
	$json     = $opts['services_json'] ?? '[]';
	$services = json_decode( $json, true );
	if ( ! is_array( $services ) ) {
		return array();
	}
	foreach ( $services as &$svc ) {
		if ( ! isset( $svc['@type'] ) ) {
			$svc['@type'] = 'Service';
		}
	}
	return $services;
}
