# WP JSON-LD Lite

A WordPress plugin that generates Review, Organization, Person, and Service JSON-LD structured data. Designed for thestartupfoundercoach.com.

## Installation

1. Copy the `wp-json-ld-lite` folder (containing `wp-json-ld-lite.php`) to `wp-content/plugins/`
2. Activate the plugin in WordPress admin under Plugins
3. Configure settings under Settings > JSON-LD Lite

## Strong Testimonials (Recommended)

This plugin is designed to work with [Strong Testimonials](https://wordpress.org/plugins/strong-testimonials/) for generating Review structured data from `wpm-testimonial` posts.

**Without Strong Testimonials**, the plugin still works but with reduced functionality:
- Organization, Person, and Service structured data are output normally
- Review data and aggregateRating are omitted (no testimonial posts to query)
- Per-testimonial meta fields (Author LinkedIn URL, sameAs, etc.) have no effect
- No PHP errors or breakage — the plugin degrades gracefully

## Settings (Settings > JSON-LD Lite)

All settings are stored as a single option (`wpjsonld_settings`). Four sections:

- **Page Targeting** — Choose where JSON-LD is output: homepage only (default), all pages, or specific page IDs
- **Organization** — Name, URL, description, sameAs URLs, founding date, contact info
- **Person** — Name, description, job title, image, sameAs URLs, alumniOf, knowsAbout
- **Services** — JSON textarea for an array of Service objects (invalid JSON falls back to `[]` on save)

## Per-Testimonial Fields

When editing a testimonial, 5 additional fields appear under "JSON-LD Enrichment" in the Client Details section:

| Field | Purpose |
|-------|---------|
| Author LinkedIn URL | Review author's LinkedIn profile |
| Author Description Override | Overrides auto-derived "Title of Company" (leave blank to auto-generate) |
| Author sameAs URLs | One URL per line (Crunchbase, press, etc.) |
| Company sameAs URLs | One URL per line for the author's company |
| Review Context Description | Describes the coaching context for `itemReviewed.description` |

## JSON-LD Output

Outputs a single `<script type="application/ld+json">` block in `wp_head` containing a `@graph` with:

- **Organization** (`@id: #org`) with aggregateRating computed from all testimonials
- **Person** (`@id: #person`) linked to the Organization via `worksFor`
- **Review[]** with author details, reviewRating, datePublished, inLanguage, publisher
- **Service[]** from the settings JSON textarea

## Version

Current version: 1.0.0
