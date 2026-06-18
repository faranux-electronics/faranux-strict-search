=== Faranux Strict Search ===
Contributors: faranux
Tags: search, woocommerce, strict search, inverted index, electronics
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 1.1.0
License: GPLv2 or later

== Description ==
High-performance AND search engine for WooCommerce, built for hardware and
electronics e-commerce. Uses a custom inverted index for instant lookups and
indexes WooCommerce SKUs and product attributes alongside post content.

== Features ==
* Strict AND logic: every word in the query must appear in the product.
* Custom inverted-index table for O(index) lookups — no full table scans.
* WooCommerce-aware: indexes SKUs, product attribute values, and product titles.
* Live AJAX search dropdown with debounce and nonce security.
* Works with WooCommerce layered navigation and shop-page product queries.
* Lightweight: no external dependencies, no dashboard bloat.
* Full uninstall cleanup via uninstall.php.

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/faranux-strict-search/.
2. Activate the plugin via the Plugins screen.
   On activation the plugin will automatically index all existing products.
3. Add the widget via Appearance → Widgets, or use the shortcode
   [faranux_strict_search] on any page or widget area.

== Shortcode ==
[faranux_strict_search]          — renders the search form
[faranux_strict_search query="arduino nano"]  — pre-fills the query

== Filters ==
faranux_strict_search_supported_post_types
    Filter the post types that are indexed and searched.
    Default: array( 'post', 'product' )

== Changelog ==
= 1.1.0 =
* Inverted index is now actually used for all search lookups (was previously
  building the index but falling back to LIKE scans).
* Added WooCommerce SKU and product attribute indexing.
* Registered missing AJAX handler with nonce verification.
* Fixed CSS and JS assets never being enqueued.
* Added bulk reindex on activation so existing products are searchable immediately.
* Switched posts_search hook to posts_where to avoid overriding WooCommerce's
  own search clauses.
* Added woocommerce_product_query hook for shop-page filtered searches.
* Parameterised all SQL via $wpdb->prepare() — eliminates SQL injection risk.
* Added uninstall.php for full cleanup on plugin deletion.
* Added DB version option for future schema migration support.
* Minimum indexed token length reduced from 3 to 2 chars (covers "5V", "I2C").
* Added debounce, abort-on-new-request, and Escape key support to live-search JS.

= 1.0.0 =
* Initial release.
