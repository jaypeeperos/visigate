=== Visigate – Conditional Content & Visibility Rules ===
Contributors: jaypeeperos
Tags: conditional content, visibility, elementor, block editor, personalization
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show or hide any block or Elementor element by user, role, login status, device, page type, or time. Visual rule builder. No front-end JavaScript.

== Description ==

Visigate lets you decide who sees which content on your WordPress site, and when. Build a rule once, then apply it to any block, any Elementor element, or any content wrapped in a shortcode.

Rules run on your server before the page is sent. Hidden blocks are never rendered, so their content isn't sitting in the page source, and Visigate loads no CSS or JavaScript on the front end.

https://www.youtube.com/watch?v=SIE5wlU_Eg0

= What you can do with it =

* Show an offer only to logged-in customers on mobile
* Run a promotion on weekends or between two dates
* Display a phone number or chat prompt during business hours
* Welcome members whose accounts are less than 7 days old
* Hide a sign-up prompt from people who are already logged in
* Show a private note or download link to selected user accounts
* Display a promotion only on a chosen post type, or only on archive pages

= Key features =

* **Visual rule builder:** combine conditions without code. Conditions in a group must all match (AND), and any matching group is enough (OR).
* **Plain-English summaries:** every rule set describes itself, so you can check its logic at a glance.
* **Reusable rule sets:** build a rule once and use it across your site. Edit it in one place and every use updates.
* **Duplicate rule sets:** use Duplicate in the Rule Sets list to copy the visibility rules into a new draft that you can edit independently.
* **Block editor support:** add a Visibility Rules panel to every block, using a saved rule set or custom rules for that block.
* **Elementor support:** apply a saved rule set to widgets, sections, columns and containers from the Advanced tab.
* **Shortcode and PHP helper:** use rules with other page builders, in classic content, or in theme templates.
* **Admin preview:** reveal hidden content on the live page, faded and outlined in red, to check your rules.
* **Cache safety:** pages using specific user, device, or time rules can be flagged automatically so page cache plugins skip them.

= Included conditions =

* **User:** login status, user role, specific users, user capability, account age
* **Visitor:** device type (desktop, tablet, mobile)
* **Page location:** specific pages or posts, post type, and page view (singular or archive)
* **Time:** day of week, time of day, date range

= Documentation =

For step-by-step guides with screenshots, see the [Visigate documentation](https://persjay.com/docs/visigate/). It covers rule sets, every condition, the block editor, Elementor, shortcodes, caching and troubleshooting.

= Shortcode =

Use a saved rule set. Its ID appears in the Shortcode column under Visigate → Rule Sets:

    [visigate set="12"]Content goes here[/visigate]

Or set the conditions directly. All of them must match:

    [visigate logged_in="yes" roles="customer" device="mobile"]Content goes here[/visigate]

Attributes:

* `set`: the ID of a saved rule set
* `logged_in`: `yes` or `no`
* `roles`: one or more role slugs, separated by commas
* `device`: `desktop`, `tablet` or `mobile`, separated by commas
* `hide`: set to `yes` to hide the content when the direct conditions match

= For developers =

Check a rule set in a theme template:

    if ( visigate_visible( 12 ) ) {
        // Show your content.
    }

PHP filters: `visigate_conditions`, `visigate_check_condition`, `visigate_condition_options`, `visigate_rest_search`, `visigate_device`, `visigate_missing_set_visible`, `visigate_is_pro`, `visigate_upgrade_url`, `visigate_hidden_block_output`, `visigate_hidden_shortcode_output`, `visigate_brand_name`, `visigate_base_capability`, `visigate_user_can_manage`, `visigate_js_data`.

JavaScript filters: `visigate.builder.extras`, `visigate.panel.extras`.

= Visigate Pro =

Visigate is fully usable on its own. [Visigate Pro](https://persjay.com/plugins/visigate/) is an optional paid add-on that adds more conditions, including country, URL parameter, cookie, referrer, user meta and WooCommerce rules.

== Installation ==

1. In your WordPress admin, go to Plugins → Add New and search for "Visigate".
2. Click Install Now, then Activate.
3. Go to Visigate → Add Rule Set and create your first rule.
4. Apply it from a block's Visibility Rules panel, from Elementor → Advanced → Visigate, or with the `[visigate]` shortcode.

== Frequently Asked Questions ==

= Does this plugin require Elementor or WooCommerce? =

No. Visigate works with the block editor on its own, and its Elementor support turns on when Elementor is active. WooCommerce conditions are part of Visigate Pro.

= Does it work with other page builders? =

Yes, through the `[visigate]` shortcode or the `visigate_visible()` PHP helper. Any builder or theme that can output a shortcode or run PHP can use them.

= Where are the Elementor visibility controls? =

Select a widget, section, column or container, then open Advanced → Visigate: Visibility and choose a saved rule set. Flexbox and Grid containers are supported. A rule on a container controls the whole container and its contents. Elements remain visible while editing in Elementor.

= Will it slow down my site? =

Visigate loads no CSS or JavaScript on the front end. Rules are checked on the server while the page is being built.

= Does it work with caching? =

For specific user, device, and time rules, Visigate can flag the page with `DONOTCACHEPAGE`, which is on by default and can be changed under Visigate settings. Most page cache plugins respect this flag, but some server or CDN caches may not, so you may need to exclude those pages there. Login and role rules usually need no exclusion, because most page caches are bypassed for logged-in users.

= Which time zone do time rules use? =

Day, time and date rules use the time zone set under Settings → General.

= How is the device detected? =

Visigate uses the browser's user agent on the server, so it detects the device type rather than the screen width. Developers can adjust the result with the `visigate_device` filter.

= How do I target individual users? =

Add **Specific users** and search by display name or username. **Is any of** matches the selected accounts. **Is none of** also matches logged-out visitors. Add a **Logged in** condition in the same group to limit it to signed-in accounts.

= What do the page location rules match? =

**Specific Page or Post** searches published content by title, including public-facing custom post types. Use **is any of** to match selected entries or **is none of** to match other singular entries. Both operators check the page being visited. Archive pages and the blog posts index do not match this condition.

**Post type** checks the current page's single item or dedicated post type archive. It supports public-facing custom post types. **Page view** matches either a single item or a WordPress archive, including category, tag, author, and date archives. Search results and the blog posts index are separate views. These rules check the page being visited, regardless of which item a Query Loop block is displaying.

= How can I test a rule? =

While logged in as an administrator, use the Visigate toggle in the admin bar on any front-end page. Hidden content appears faded and outlined in red. Only you see this preview.

= What happens when I delete the plugin? =

Visigate removes its settings but keeps your rule sets, so reinstalling won't break your pages. Blocks and shortcodes stop being filtered while it's inactive, so check any sensitive content before you deactivate it.

= Is there a premium version? =

Yes. Visigate Pro is a separate plugin sold outside WordPress.org. This plugin doesn't require it.

== Screenshots ==

1. The visual rule builder, with AND/OR groups and a plain-English summary.
2. A close-up of a rule's condition groups and summary.
3. The Visibility Rules panel in the block editor sidebar.
4. Applying a saved rule set from Elementor's Advanced tab.
5. The Rule Sets list, showing each rule's summary and shortcode.
6. Cache safety, admin preview and developer reference on the settings screen.

== Changelog ==

= 1.3.4 =
* Added Specific Page or Post rules for targeting individual published pages, posts, and public-facing custom post entries.
* Added a Duplicate action that copies a rule set's visibility rules into a new draft.

= 1.3.3 =
* Added Specific users rules for including or excluding selected WordPress accounts.
* Added Post type and Page view rules for targeting singular pages and archives.

= 1.3.2 =
* Fix: the Visigate visibility panel now appears on Elementor Flexbox and Grid containers.
* Improve: register visibility controls by the Advanced tab instead of specific section names, supporting widgets that use different Advanced section IDs.

= 1.3.1 =
Fix: admin preview now also reveals content hidden by the [visigate] shortcode (faded and outlined in red), not only blocks. Plugin and author links now point to persjay.com, and Pro conditions in the builder link to the Visigate Pro plans.

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

== Upgrade Notice ==

= 1.3.4 =
Adds individual page and post targeting and lets you duplicate saved rule sets as drafts.

= 1.3.3 =
Adds server-side visibility rules for specific users, post types, and singular or archive views.

= 1.3.2 =
Fixes missing Elementor visibility controls on containers and widgets with different Advanced section names. Existing rule selections are preserved.
