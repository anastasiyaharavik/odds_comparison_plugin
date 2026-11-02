=== Odds Comparison ===
Tags: odds, betting, sports, comparison, block
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compare betting odds from UK bookmakers in a cached Gutenberg block, with affiliate links and a visitor odds-format switcher.

== Description ==

Odds Comparison shows betting odds from about 10 UK bookmakers in posts and pages with a block.

* **Admins** choose the bookmakers, their affiliate links, the competitions and the markets.
* **Editors** pick an event, a market and the bookmakers (and their order) for each table.
* **Visitors** see the best price for each outcome and switch between fractional, decimal and American odds.

A new install starts with **demo data**, so the block works straight away: real odds recorded from the
supported sources, with kick-off dates moved to the coming days. Tables are marked "Demo data". When you are
ready, switch to live odds from The Odds API (a licensed feed with a free plan) or the BettingOdds.com scraper.

Odds are fetched in the background (WP-Cron) and stored. Pages only read stored odds, so a block adds no
outside requests to a page view. When a source fails, the last good odds stay and are marked "may be out of
date" once they are old; odds are never invented.

The full guide (configuration, going live, the Status tab, themes and hooks) is in `README.md` and
`TECHNICAL_DOCUMENTATION.md`, included with the plugin.

== External services ==

The plugin can fetch odds from two outside services. **In demo mode (the default) it contacts neither.** In live
mode it contacts only the data source chosen on the General tab, from the server, on a schedule (WP-Cron), never
from visitors' browsers. No visitor or user data is sent; requests contain only the competition or event being
updated.

**The Odds API** (https://the-odds-api.com) provides licensed odds data. When it is the data source, the plugin
requests the odds of each enabled competition, sending your API key (defined in `wp-config.php`) with each
request. Terms: https://the-odds-api.com/terms-and-conditions.html. Privacy policy:
https://the-odds-api.com/privacy-policy.html.

**BettingOdds.com** (https://www.bettingodds.com) is an odds comparison site. When it is the data source, the
plugin requests its public odds pages and its robots.txt, only pages that robots.txt allows, at a low rate and
with an honest User-Agent. Using scraped odds on a production site needs the site owner's permission or a
licence. Terms: https://www.bettingodds.com/terms-and-conditions. Privacy policy:
https://www.bettingodds.com/privacy-policy.

== Installation ==

1. In WordPress go to **Plugins → Add New Plugin → Upload Plugin**, choose `odds-comparison.zip` and activate
   **Odds Comparison**.
2. Add the **Odds Comparison** block to a post (search "odds"), pick an event and a market, and publish.
3. Optional, for live odds: add `define( 'ODDS_COMPARISON_THEODDSAPI_KEY', 'your-key-here' );` to
   `wp-config.php`, then on **Odds Comparison → General** choose The Odds API and set Data mode to
   **Live odds from the data source**.

For exact refresh timing, disable WP-Cron's page-load trigger and run `wp cron event run --due-now` from the
server's cron every 5 minutes.

== Frequently Asked Questions ==

= Does it work without an API key? =

Yes. Demo mode needs no key and makes no outside requests. Live odds from any server need a free The Odds API
key; BettingOdds.com blocks requests from most web servers.

= Can my theme change the table? =

Copy `templates/odds-table.php` to `your-theme/odds-comparison/odds-table.php`, or restyle it with CSS custom
properties such as `--oc-accent`, `--oc-card-bg` and `--oc-border`.

= Where do I see whether odds are updating? =

On **Odds Comparison → Status**: the source in use, the last update, the next refresh, recent refreshes and
errors, and a **Refresh now** button.

== Changelog ==

= 0.1.0 =
* First release: odds block, demo mode, The Odds API and BettingOdds.com sources, Status tab.
