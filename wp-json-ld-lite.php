<?php
/**
 * Plugin Name: WP JSON-LD Lite
 * Plugin URI:  https://github.com/adamdexter/wp-json-ld-lite
 * Description: Generates Review JSON-LD structured data from Strong Testimonials data.
 * Version:     1.5.0
 * Author:      Adam Dexter
 * Author URI:  https://thestartupfoundercoach.com/
 * License:     GPL-2.0-or-later
 * Text Domain: wp-json-ld-lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPJSONLD_VERSION', '1.5.0' );
define( 'WPJSONLD_OPTION_KEY', 'wpjsonld_settings' );

/**
 * Absolute @id for the Person entity.
 *
 * Deliberately matches Rank Math's knowledge-graph node id
 * ({home_url}/#person) so search engines and LLMs merge this plugin's
 * Person (sameAs, knowsAbout, jobTitle, image) with Rank Math's into a
 * single entity instead of seeing two different Adam Dexters.
 */
function wpjsonld_person_id() {
	$opts = get_option( WPJSONLD_OPTION_KEY, array() );
	if ( ! empty( $opts['person_id'] ) ) {
		return $opts['person_id']; // Canonical entity home, e.g. https://adamdexter.net/#person
	}
	return trailingslashit( home_url() ) . '#person';
}

/**
 * Absolute @id for the Organization entity.
 *
 * The business ("The Startup Founder Coach") is kept distinct from the
 * Person; an absolute id (vs the old relative "#wpjsonld-org") keeps the
 * reference stable when schema consumers resolve nodes across pages.
 */
function wpjsonld_org_id() {
	return trailingslashit( home_url() ) . '#organization';
}

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
		'sitewide_identity' => '',
		'archive_page_id'   => '',
		'faq_page_id'       => '',
		'about_page_id'     => '',
		'org_name'          => '',
		'org_alternate_name' => '',
		'org_logo'          => '',
		'org_area_served'   => '',
		'org_url'           => '',
		'org_description'   => '',
		'org_sameas'        => '',
		'org_founding_date' => '',
		'org_contact_type'  => '',
		'org_contact_email' => '',
		'person_id'         => '',
		'person_name'       => '',
		'person_given_name' => '',
		'person_family_name' => '',
		'person_description' => '',
		'person_disambiguating_description' => '',
		'person_occupations' => '',
		'person_locality'   => '',
		'person_region'     => '',
		'person_country'    => '',
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

	// --- Output Options ---
	add_settings_section( 'wpjsonld_output', 'Output Options', 'wpjsonld_section_output_description', 'wpjsonld-settings' );
	add_settings_field( 'sitewide_identity', 'Site-wide Identity Data', 'wpjsonld_field_sitewide_identity', 'wpjsonld-settings', 'wpjsonld_output' );
	add_settings_field( 'archive_page_id', 'Testimonials Archive Page', 'wpjsonld_field_archive_page_id', 'wpjsonld-settings', 'wpjsonld_output' );
	add_settings_field( 'faq_page_id', 'FAQ Page (FAQPage schema)', 'wpjsonld_field_faq_page_id', 'wpjsonld-settings', 'wpjsonld_output' );
	add_settings_field( 'about_page_id', 'About Page (ProfilePage schema)', 'wpjsonld_field_about_page_id', 'wpjsonld-settings', 'wpjsonld_output' );

	// --- Organization ---
	add_settings_section( 'wpjsonld_org', 'Organization (itemReviewed)', '__return_false', 'wpjsonld-settings' );
	$org_fields = array(
		'org_name'          => 'Name',
		'org_alternate_name' => 'Alternate Name',
		'org_url'           => 'URL',
		'org_logo'          => 'Logo URL',
		'org_area_served'   => 'Area Served',
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
		'person_id'          => '@id (canonical entity URL, e.g. https://example.com/#person; blank = this site)',
		'person_name'        => 'Name',
		'person_given_name'  => 'Given Name',
		'person_family_name' => 'Family Name',
		'person_description' => 'Description',
		'person_disambiguating_description' => 'Disambiguating Description',
		'person_occupations' => 'Occupations (one per line)',
		'person_locality'    => 'Home Location: City',
		'person_region'      => 'Home Location: Region',
		'person_country'     => 'Home Location: Country (ISO code)',
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

	$clean['sitewide_identity'] = ! empty( $input['sitewide_identity'] ) ? '1' : '';

	$archive_page_id          = absint( $input['archive_page_id'] ?? 0 );
	$clean['archive_page_id'] = $archive_page_id ? (string) $archive_page_id : '';

	$faq_page_id          = absint( $input['faq_page_id'] ?? 0 );
	$clean['faq_page_id'] = $faq_page_id ? (string) $faq_page_id : '';

	$about_page_id          = absint( $input['about_page_id'] ?? 0 );
	$clean['about_page_id'] = $about_page_id ? (string) $about_page_id : '';

	$url_keys = array( 'org_url', 'org_logo', 'person_id', 'person_image', 'person_url', 'person_alumni_url' );
	foreach ( $url_keys as $key ) {
		$clean[ $key ] = esc_url_raw( $input[ $key ] ?? '' );
	}

	$text_keys = array( 'org_name', 'org_alternate_name', 'org_area_served', 'org_founding_date', 'org_contact_type', 'org_contact_email', 'person_name', 'person_given_name', 'person_family_name', 'person_job_title', 'person_alumni_name', 'person_locality', 'person_region', 'person_country' );
	foreach ( $text_keys as $key ) {
		$clean[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
	}

	$textarea_keys = array( 'org_description', 'org_sameas', 'person_description', 'person_disambiguating_description', 'person_occupations', 'person_sameas', 'person_knows_about' );
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

function wpjsonld_section_output_description() {
	echo '<p>JSON-LD is output automatically based on page context:</p>';
	echo '<ul style="list-style:disc;margin-left:20px;">';
	echo '<li><strong>Homepage</strong> — Full graph: Organization, Person, all Reviews, Services, AggregateRating</li>';
	echo '<li><strong>Testimonials archive</strong> — Organization, Person, all Reviews, AggregateRating (the CPT archive, or the page selected below)</li>';
	echo '<li><strong>Single testimonial</strong> — Organization, that single Review</li>';
	echo '<li><strong>About page</strong> — Organization, ProfilePage, Person, Services (if enabled below)</li>';
	echo '<li><strong>Other pages/posts</strong> — Organization (founder → Person @id), Services (if enabled below)</li>';
	echo '</ul>';
}

function wpjsonld_field_sitewide_identity() {
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	printf(
		'<label><input type="checkbox" name="%s[sitewide_identity]" value="1" %s /> Output Organization, Person, and Services on all pages and posts</label>',
		esc_attr( WPJSONLD_OPTION_KEY ),
		checked( $opts['sitewide_identity'] ?? '', '1', false )
	);
	echo '<p class="description">When unchecked, identity data only appears on the homepage. Review data always follows page context regardless of this setting.</p>';
}

function wpjsonld_field_archive_page_id() {
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	wp_dropdown_pages( array(
		'name'              => WPJSONLD_OPTION_KEY . '[archive_page_id]',
		'selected'          => (int) ( $opts['archive_page_id'] ?? 0 ),
		'show_option_none'  => '— None —',
		'option_none_value' => '0',
	) );
	echo '<p class="description">The testimonial post type has no built-in archive; if your testimonials listing is a regular page (e.g. /testimonials/), select it here so it gets the full Reviews + AggregateRating markup.</p>';
}

function wpjsonld_field_faq_page_id() {
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	wp_dropdown_pages( array(
		'name'              => WPJSONLD_OPTION_KEY . '[faq_page_id]',
		'selected'          => (int) ( $opts['faq_page_id'] ?? 0 ),
		'show_option_none'  => '— None —',
		'option_none_value' => '0',
	) );
	echo '<p class="description">Select your FAQ page to emit FAQPage schema. Questions and answers are read live from the page\'s Elementor accordion/toggle widgets, so the markup always matches the visible content (a Google requirement) with no duplication to maintain.</p>';
}

function wpjsonld_field_about_page_id() {
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	wp_dropdown_pages( array(
		'name'              => WPJSONLD_OPTION_KEY . '[about_page_id]',
		'selected'          => (int) ( $opts['about_page_id'] ?? 0 ),
		'show_option_none'  => '— None —',
		'option_none_value' => '0',
	) );
	echo '<p class="description">Select the About page. It gets a ProfilePage node whose mainEntity is the Person, plus the full Person node. Other pages only reference the Person by @id (as Organization.founder).</p>';
}

function wpjsonld_field_callback( $args ) {
	$key  = $args['key'];
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	$val  = $opts[ $key ] ?? '';

	$textarea_keys = array( 'org_description', 'org_sameas', 'person_description', 'person_disambiguating_description', 'person_occupations', 'person_sameas', 'person_knows_about' );

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

/**
 * Compute aggregate rating from all published testimonials.
 * Returns array with 'total', 'count', and 'rating' keys (or null values if none).
 */
function wpjsonld_compute_aggregate_rating() {
	$testimonials = get_posts( array(
		'post_type'      => 'wpm-testimonial',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	$total = 0;
	$count = 0;
	foreach ( $testimonials as $id ) {
		$star = get_post_meta( $id, 'star_rating', true );
		if ( $star ) {
			$total += (int) $star;
			$count++;
		}
	}

	if ( $count === 0 ) {
		return null;
	}

	return array(
		'@type'       => 'AggregateRating',
		'ratingValue' => round( $total / $count, 1 ),
		'reviewCount' => $count,
		'bestRating'  => 5,
		'worstRating' => 1,
	);
}

/**
 * Build all Review entities from published testimonials.
 */
function wpjsonld_build_all_reviews() {
	$testimonials = get_posts( array(
		'post_type'      => 'wpm-testimonial',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'ASC',
	) );

	$reviews = array();
	foreach ( $testimonials as $t ) {
		$review = wpjsonld_build_review( $t );
		if ( $review ) {
			$reviews[] = $review;
		}
	}
	return $reviews;
}

/**
 * Context-aware JSON-LD output.
 *
 * Homepage:              Org (+ aggregateRating), Person, all Reviews, Services
 * Testimonial archive:   Org (+ aggregateRating), Person, all Reviews
 * Single testimonial:    Org, that single Review
 * Other pages/posts:     Org, Person, Services (only if sitewide_identity enabled)
 * 404/search/etc:        No output
 */
/**
 * Is the current page the designated testimonials archive page?
 */
function wpjsonld_is_archive_page( $opts ) {
	$page_id = (int) ( $opts['archive_page_id'] ?? 0 );

	return $page_id && is_page( $page_id );
}

function wpjsonld_is_faq_page( $opts ) {
	$page_id = (int) ( $opts['faq_page_id'] ?? 0 );

	return $page_id && is_page( $page_id );
}

/**
 * Build a FAQPage node from the FAQ page's Elementor accordion/toggle widgets.
 *
 * Reads _elementor_data live at render time rather than duplicating the Q&A
 * in settings: Google requires FAQPage markup to match visible page content,
 * and this guarantees it survives content edits with zero maintenance.
 */
function wpjsonld_build_faqpage( $page_id ) {
	$raw = get_post_meta( $page_id, '_elementor_data', true );
	if ( ! $raw ) {
		return null;
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return null;
	}

	$questions = array();
	$walk      = function ( $els ) use ( &$walk, &$questions ) {
		foreach ( $els as $el ) {
			$widget = $el['widgetType'] ?? '';
			if ( in_array( $widget, array( 'accordion', 'toggle', 'nested-accordion' ), true ) ) {
				$items = $el['settings']['tabs'] ?? $el['settings']['items'] ?? array();
				foreach ( $items as $item ) {
					$q = trim( wp_strip_all_tags( $item['tab_title'] ?? $item['item_title'] ?? '' ) );
					$a = trim( wp_strip_all_tags( $item['tab_content'] ?? $item['item_content'] ?? '' ) );
					if ( $q && $a ) {
						$questions[] = array(
							'@type'          => 'Question',
							'name'           => $q,
							'acceptedAnswer' => array(
								'@type' => 'Answer',
								'text'  => $a,
							),
						);
					}
				}
			}
			if ( ! empty( $el['elements'] ) ) {
				$walk( $el['elements'] );
			}
		}
	};
	$walk( $data );

	if ( ! $questions ) {
		return null;
	}

	return array(
		'@type'      => 'FAQPage',
		'@id'        => get_permalink( $page_id ) . '#faqpage',
		'mainEntity' => $questions,
	);
}

function wpjsonld_output_jsonld() {
	if ( is_admin() || is_404() || is_search() ) {
		return;
	}

	$opts  = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	$graph = array();

	$include_reviews   = false;
	$include_person    = false;
	$include_services  = false;
	$include_aggregate = false;
	$include_faq       = false;
	$include_profile   = false;
	$single_review     = false;
	$context_label     = '';
	$about_page_id     = (int) ( $opts['about_page_id'] ?? 0 );

	if ( is_front_page() ) {
		// Homepage: full graph.
		$include_reviews   = true;
		$include_person    = true;
		$include_services  = true;
		$include_aggregate = true;
		$context_label     = 'homepage';
	} elseif ( is_post_type_archive( 'wpm-testimonial' ) || wpjsonld_is_archive_page( $opts ) ) {
		// Testimonials archive (CPT archive, or the designated page — the CPT
		// registers with has_archive=false, so /testimonials/ is a regular
		// page): all reviews + identity. Keep Services when sitewide identity
		// is on, matching what the page would get from the sitewide branch.
		$include_reviews   = true;
		$include_aggregate = true;
		$include_services  = ! empty( $opts['sitewide_identity'] );
		$context_label     = 'testimonial-archive';
	} elseif ( is_singular( 'wpm-testimonial' ) ) {
		// Single testimonial: just that review.
		$single_review = true;
		$context_label = 'single-testimonial';
	} elseif ( is_singular() || is_page() ) {
		// Other pages/posts: identity only if sitewide enabled. The FAQ page
		// additionally gets a FAQPage node, and outputs even with sitewide off.
		$include_faq     = wpjsonld_is_faq_page( $opts );
		$include_profile = $about_page_id && is_page( $about_page_id );
		if ( $include_profile ) {
			// About page: ProfilePage + the full Person node (the only page besides the homepage that carries it).
			$include_person   = true;
			$include_services = ! empty( $opts['sitewide_identity'] );
			$context_label    = 'about-page';
		} elseif ( ! empty( $opts['sitewide_identity'] ) ) {
			$include_services = true;
			$context_label    = $include_faq ? 'faq-page' : 'page-sitewide';
		} elseif ( ! $include_faq ) {
			return; // No output on non-homepage pages unless sitewide is on.
		} else {
			$context_label = 'faq-page';
		}
	} else {
		// Archives, categories, tags, etc.
		if ( ! empty( $opts['sitewide_identity'] ) ) {
			$include_services = true;
			$context_label    = 'archive-sitewide';
		} else {
			return;
		}
	}

	// Organization is always included when we're outputting anything.
	$org = wpjsonld_build_organization( $opts );

	// Attach aggregateRating if needed.
	if ( $include_aggregate ) {
		$agg = wpjsonld_compute_aggregate_rating();
		if ( $agg ) {
			$org['aggregateRating'] = $agg;
		}
	}

	// Build reviews.
	$reviews = array();
	if ( $include_reviews ) {
		$reviews = wpjsonld_build_all_reviews();
	} elseif ( $single_review ) {
		global $post;
		if ( $post ) {
			$review = wpjsonld_build_review( $post );
			if ( $review ) {
				$reviews[] = $review;
			}
		}
	}

	// Assemble graph.
	foreach ( $reviews as $r ) {
		$graph[] = $r;
	}
	$graph[] = $org;

	if ( $include_services ) {
		$services = wpjsonld_build_services( $opts );
		foreach ( $services as $s ) {
			$graph[] = $s;
		}
	}

	if ( $include_profile ) {
		$graph[] = wpjsonld_build_profilepage( $about_page_id );
	}

	if ( $include_person ) {
		$graph[] = wpjsonld_build_person( $opts );
	}

	if ( ! empty( $include_faq ) ) {
		$faq = wpjsonld_build_faqpage( (int) ( $opts['faq_page_id'] ?? 0 ) );
		if ( $faq ) {
			$graph[] = $faq;
		}
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		printf(
			"<!-- WP JSON-LD Lite v%s: context=%s, reviews=%d -->\n",
			esc_html( WPJSONLD_VERSION ),
			esc_html( $context_label ),
			count( $reviews )
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
		'@id'   => wpjsonld_org_id(),
	);

	if ( ! empty( $opts['org_name'] ) ) {
		$org['name'] = $opts['org_name'];
	}
	if ( ! empty( $opts['org_alternate_name'] ) ) {
		$org['alternateName'] = $opts['org_alternate_name'];
	}
	if ( ! empty( $opts['org_url'] ) ) {
		$org['url'] = $opts['org_url'];
	}
	if ( ! empty( $opts['org_logo'] ) ) {
		$org['logo'] = array(
			'@type'      => 'ImageObject',
			'@id'        => trailingslashit( home_url() ) . '#logo',
			'url'        => $opts['org_logo'],
			'contentUrl' => $opts['org_logo'],
			'caption'    => $opts['org_name'] ?? '',
		);
		$org['image'] = array( '@id' => trailingslashit( home_url() ) . '#logo' );
	}

	$sameas = wpjsonld_parse_url_list( $opts['org_sameas'] ?? '' );
	if ( $sameas ) {
		$org['sameAs'] = $sameas;
	}

	$org['founder']  = array( '@id' => wpjsonld_person_id() );
	$org['employee'] = array( '@id' => wpjsonld_person_id() );

	if ( ! empty( $opts['org_area_served'] ) ) {
		$org['areaServed'] = $opts['org_area_served'];
	}

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
		'@id'   => wpjsonld_person_id(),
	);

	if ( ! empty( $opts['person_name'] ) ) {
		$person['name'] = $opts['person_name'];
	}
	if ( ! empty( $opts['person_given_name'] ) ) {
		$person['givenName'] = $opts['person_given_name'];
	}
	if ( ! empty( $opts['person_family_name'] ) ) {
		$person['familyName'] = $opts['person_family_name'];
	}
	if ( ! empty( $opts['person_description'] ) ) {
		$person['description'] = $opts['person_description'];
	}
	if ( ! empty( $opts['person_disambiguating_description'] ) ) {
		$person['disambiguatingDescription'] = $opts['person_disambiguating_description'];
	}
	if ( ! empty( $opts['person_job_title'] ) ) {
		$person['jobTitle'] = $opts['person_job_title'];
	}

	$occupations = wpjsonld_parse_line_list( $opts['person_occupations'] ?? '' );
	if ( $occupations ) {
		$person['hasOccupation'] = array_map( function ( $name ) {
			return array( '@type' => 'Occupation', 'name' => $name );
		}, $occupations );
	}

	if ( ! empty( $opts['person_locality'] ) ) {
		$address = array( '@type' => 'PostalAddress', 'addressLocality' => $opts['person_locality'] );
		if ( ! empty( $opts['person_region'] ) ) {
			$address['addressRegion'] = $opts['person_region'];
		}
		if ( ! empty( $opts['person_country'] ) ) {
			$address['addressCountry'] = $opts['person_country'];
		}
		$person['homeLocation'] = array( '@type' => 'Place', 'address' => $address );
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

	$person['worksFor'] = array( '@id' => wpjsonld_org_id() );

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

	// itemReviewed — use inline Organization (no @id) when there's a per-review
	// description to avoid @id collision merging all descriptions onto #org.
	if ( $reviewed_desc ) {
		$item_reviewed = array(
			'@type'       => 'Organization',
			'description' => $reviewed_desc,
		);
		$org_opts = get_option( WPJSONLD_OPTION_KEY, array() );
		if ( ! empty( $org_opts['org_name'] ) ) {
			$item_reviewed['name'] = $org_opts['org_name'];
		}
		if ( ! empty( $org_opts['org_url'] ) ) {
			$item_reviewed['url'] = $org_opts['org_url'];
		}
		$review['itemReviewed'] = $item_reviewed;
	} else {
		$review['itemReviewed'] = array( '@id' => wpjsonld_org_id() );
	}

	// publisher.
	$review['publisher'] = array( '@id' => wpjsonld_org_id() );

	return $review;
}

/**
 * ProfilePage node for the About page: the page is *about* the Person whose
 * canonical @id lives at the entity home (see wpjsonld_person_id()).
 */
function wpjsonld_build_profilepage( $page_id ) {
	$url = get_permalink( $page_id );
	$node = array(
		'@type'      => 'ProfilePage',
		'@id'        => $url . '#profilepage',
		'url'        => $url,
		'name'       => wp_strip_all_tags( get_the_title( $page_id ) ),
		'isPartOf'   => array( '@id' => trailingslashit( home_url() ) . '#website' ),
		'about'      => array( '@id' => wpjsonld_person_id() ),
		'mainEntity' => array( '@id' => wpjsonld_person_id() ),
	);
	$modified = get_post_modified_time( 'c', true, $page_id );
	if ( $modified ) {
		$node['dateModified'] = $modified;
	}
	return $node;
}

/**
 * Single-source the Organization: drop Rank Math's knowledge-graph node
 * ('publisher') so only this plugin emits Organization/Person, and name the
 * WebSite after the Organization. Rank Math keeps WebSite/WebPage/Breadcrumb;
 * its WebSite.publisher / WebPage.publisher references resolve to this
 * plugin's #organization because both use {home_url}/#organization.
 */
add_filter( 'rank_math/json_ld', 'wpjsonld_filter_rank_math', 99, 2 );

function wpjsonld_filter_rank_math( $data, $jsonld = null ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	$opts = get_option( WPJSONLD_OPTION_KEY, wpjsonld_get_defaults() );
	if ( empty( $opts['org_name'] ) ) {
		return $data; // Plugin not configured: leave Rank Math alone.
	}

	$org_id = wpjsonld_org_id();
	$old_id = isset( $data['publisher']['@id'] ) ? $data['publisher']['@id'] : '';
	unset( $data['publisher'] );

	// Re-point any reference to the removed node at our Organization.
	if ( $old_id && $old_id !== $org_id ) {
		$walk = function ( &$node ) use ( &$walk, $old_id, $org_id ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['@id'] ) && $node['@id'] === $old_id && 1 === count( $node ) ) {
				$node['@id'] = $org_id;
				return;
			}
			foreach ( $node as &$child ) {
				$walk( $child );
			}
		};
		$walk( $data );
	}

	// Drop ImageObject nodes that only existed to illustrate the removed knowledge-graph node.
	$blob = wp_json_encode( $data );
	foreach ( $data as $key => $node ) {
		if ( is_array( $node ) && isset( $node['@type'], $node['@id'] ) && 'ImageObject' === $node['@type'] ) {
			$refs = substr_count( $blob, wp_json_encode( $node['@id'] ) );
			if ( $refs <= 1 ) { // Only its own declaration; nothing references it.
				unset( $data[ $key ] );
			}
		}
	}

	foreach ( $data as $key => &$node ) {
		if ( is_array( $node ) && isset( $node['@type'] ) && 'WebSite' === $node['@type'] ) {
			$alternates = array();
			if ( ! empty( $node['alternateName'] ) ) {
				$alternates = (array) $node['alternateName'];
			}
			if ( ! empty( $node['name'] ) && $node['name'] !== $opts['org_name'] ) {
				array_unshift( $alternates, $node['name'] );
			}
			$node['name']      = $opts['org_name'];
			$node['publisher'] = array( '@id' => $org_id );
			if ( $alternates ) {
				$node['alternateName'] = array_values( array_unique( $alternates ) );
			}
		}
	}
	unset( $node );

	return $data;
}

function wpjsonld_build_services( $opts ) {
	$json     = $opts['services_json'] ?? '[]';
	$services = json_decode( $json, true );
	if ( ! is_array( $services ) ) {
		return array();
	}
	foreach ( $services as &$svc ) {
		unset( $svc['offers'] ); // No pricing is published on the site (decision 2026-08-28).
		$svc['provider'] = array( '@id' => wpjsonld_org_id() ); // Reference the single Organization node, never an inline copy.
		if ( ! isset( $svc['@type'] ) ) {
			$svc['@type'] = 'Service';
		}
	}
	return $services;
}
