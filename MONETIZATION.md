# Visigate – launch & monetization notes

## 1. Licensing (do this first)
Use **Freemius** (or Paddle/LemonSqueezy + your own updater). Freemius gives you checkout, license keys,
auto-updates, trials, affiliates and EU VAT handling. Hook it in `visigate_is_pro()` in `visigate.php`:

    function visigate_is_pro() { return visigate_fs()->can_use_premium_code(); }

**Important – WordPress.org rules:** the .org-hosted free plugin may not contain "locked" paid code.
Use Freemius's build system (`@fs_premium_only` annotations on pro-only files/branches) to generate two zips:
a clean free build for wordpress.org and a premium build for paying customers. In this starter, all conditions
run through the same engine and Pro is gated in the builder UI only (`pro => true` in class-conditions.php),
so before submitting to .org, move the Pro conditions' *evaluation code* (country, url_param, cookie,
referrer, user_meta, woo_*) into a premium-only file.

## 2. Suggested tiers (annual, auto-renew)
| Plan | Price | Sites | Notes |
|---|---|---|---|
| Free | $0 | – | Role, login, device, time/date rules, Rule Sets, Elementor + Gutenberg |
| Pro | $59 | 1 | + Country, URL/cookie/referrer, user meta, WooCommerce conditions |
| Business | $119 | 5 | same + priority support |
| Agency | $249 | Unlimited | same + white-label option |
Offer a 14-day no-card trial and a lifetime deal at launch (AppSumo/LTD communities) for early cash + reviews.

## 3. Positioning (your unique angle)
- "Sell more with WooCommerce-aware personalization": show upsells to people who bought X in the last 30 days,
  hide promos from existing customers, VIP blocks for lifetime spend ≥ $500, win-back blocks.
- "Zero bloat": no front-end JS/CSS, hidden blocks never rendered. Publish benchmark numbers vs. competitors.
- Membership/paywall-lite use-cases (role + purchase based) for sites that don't want a full membership plugin.

## 4. Roadmap that justifies renewals
1. Beaver Builder, Bricks, Divi and Oxygen native panels (today they use the shortcode/PHP helper).
2. Client-side "cache-safe" mode for device/country (edge/ESI-friendly) with a tiny opt-in JS.
3. Per-rule analytics (impressions of show/hide) and A/B variants.
4. Fallback content ("else" block) and scheduling presets.
5. Geolocation add-on with bundled MaxMind DB updates.
6. More Woo conditions: coupon used, shipping country, review left, subscription product/status, memberships.
7. LearnDash / LifterLMS / MemberPress / Easy Digital Downloads condition packs (sell as add-ons).

## 5. Go-to-market
- Publish the free plugin on wordpress.org (SEO: "conditional content", "show hide blocks", "woocommerce conditional").
- Comparison landing pages: "Visigate vs <competitors>", plus 5–10 recipe posts (e.g. "Show a thank-you banner to
  customers who bought in the last 7 days").
- Short video demos of the builder; Woo/Elementor Facebook groups, r/Wordpress, WP agencies' Slack groups.
- In-plugin upgrade nudges only where a Pro condition is selected (already implemented as the "(Pro)" labels).

## 6. QA before shipping
- Test on WP 6.2→latest, PHP 7.4→8.3, Woo with HPOS on/off, Elementor free/Pro, popular cache plugins.
- Run `phpcs --standard=WordPress` and Plugin Check; add a `languages/` folder + .pot file.
- Add unit tests for Visigate_Engine::visible() (OR-of-AND logic, time wraparound, pro/unknown conditions).
