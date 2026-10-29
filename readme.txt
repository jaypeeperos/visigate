=== Visigate – Conditional Content & Visibility Rules ===
Contributors: jaypeeperos
Tags: conditional content, visibility, elementor, block editor, personalization
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show or hide any block or Elementor element by role, login status, device, or time. Visual rule builder. No front-end JavaScript.

== Description ==

Visigate shows or hides content with reusable visibility rules. Conditions are evaluated on the server, so hidden blocks are never rendered and no front-end CSS or JavaScript is loaded.

* Visual builder: conditions in a group are AND’d; groups are OR’d. Every rule set has a plain-English summary.
* Works on every block in the block editor (sidebar panel), in Elementor (Advanced tab), and anywhere via `[visigate]` or `visigate_visible()`.
* Reusable Rule Sets: build once, apply to hundreds of blocks.
* Cache-aware: pages using visitor-specific rules (device or time) can be flagged `DONOTCACHEPAGE`.

= Included conditions =

Login status, user role, capability, account age, device type, day of week, time of day, and date range.

A separate add-on, Visigate Pro, can add extra conditions (country, URL, cookie, WooCommerce, and more). This plugin is fully usable without it.

== Installation ==

1. Upload the `visigate` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate Visigate through the Plugins screen.
3. Go to Visigate → Add Rule Set and create a rule.
4. Apply that rule set from a block’s Visibility Rules panel, from Elementor → Advanced → Visigate, or with the shortcode below.

== Shortcode ==

```
[visigate set="12"]…[/visigate]
[visigate logged_in="yes" roles="customer" device="mobile"]…[/visigate]
```

== Developer hooks ==

`visigate_visible( $set_id_or_rules )`

Filters: `visigate_conditions`, `visigate_check_condition`, `visigate_condition_options`, `visigate_rest_search`, `visigate_device`, `visigate_missing_set_visible`, `visigate_is_pro`, `visigate_upgrade_url`, `visigate_hidden_block_output`, `visigate_hidden_shortcode_output`, `visigate_brand_name`, `visigate_base_capability`, `visigate_user_can_manage`, `visigate_js_data`.

JavaScript filters: `visigate.builder.extras`, `visigate.panel.extras`.

== Frequently Asked Questions ==

= Does this plugin require Elementor or WooCommerce? =

No. Elementor support is included when Elementor is active. WooCommerce conditions are not part of this plugin.

= Is there a premium version? =

Yes. Visigate Pro is a separate plugin sold outside WordPress.org. The copy hosted here does not require it.

== Changelog ==

= 1.3.0 =
Fix: elements with a rule set are no longer stored in Elementor's element cache, so every visitor gets the right version. Add-ons can now lock individual conditions to a plan.

= 1.2.0 =
New `visigate_manage` capability (granted to administrators) and developer hooks for replacement content, renaming and builder extensions.

= 1.1.1 =
Directory-ready readme and plugin headers (free features only). Upgrade URL is empty until a real pricing page exists.

= 1.1.0 =
Pro conditions moved to the separate Visigate Pro add-on; unknown conditions now fail closed and are preserved on save.

= 1.0.0 =
Initial release.
