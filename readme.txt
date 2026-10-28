=== Visigate – Conditional Content & Visibility Rules ===
Contributors: Jaypee Peros
Tags: conditional content, visibility, woocommerce, gutenberg, elementor, personalization
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Show or hide any block or Elementor element by role, login status, device, time, country, URL, cookie or WooCommerce purchase history. Visual rule builder. No front-end JavaScript.

== Description ==

* Visual builder: conditions are AND'd inside a group, groups are OR'd. Every rule set has a plain-English summary.
* Works on every Gutenberg block (sidebar panel), in Elementor (Advanced tab), and anywhere via [visigate] or visigate_visible().
* Reusable Rule Sets: build once, apply to hundreds of blocks.
* Performance: server-side only, hidden blocks are never rendered, WooCommerce purchase checks use a cached per-user index (no per-block order queries), zero front-end CSS/JS.
* Cache-aware: pages using visitor-specific rules are automatically flagged DONOTCACHEPAGE.

= Conditions =
Free: login status, role, capability, account age, device, day of week, time of day, date range.
Visigate Pro (separate add-on): country, URL parameter, cookie, referrer, user meta, WooCommerce (purchased product/category within N days, lifetime orders/spend, billing country, cart contents/subtotal, active subscription).

== Shortcode ==
[visigate set="12"]…[/visigate]
[visigate logged_in="yes" roles="customer" device="mobile"]…[/visigate]
[visigate bought="45,46" bought_days="30"]…[/visigate]  (the bought attribute needs Visigate Pro)

== Developer hooks ==
visigate_visible( $set_id_or_rules ), filters: visigate_conditions, visigate_check_condition, visigate_condition_options, visigate_rest_search, visigate_device, visigate_missing_set_visible, visigate_is_pro, visigate_upgrade_url.

== Changelog ==
= 1.1.0 = Pro conditions moved to the separate Visigate Pro add-on; unknown conditions now fail closed and are preserved on save.
= 1.0.0 = Initial release.
