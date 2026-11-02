#!/usr/bin/env bash
# End-to-end checks of the admin flows against a running local WordPress.
#
#   tests/Integration/admin-e2e.sh            # defaults: http://localhost:8080, container wordpress_app
#
# Creates a temporary administrator and subscriber (random passwords, deleted at the end), logs in
# with curl and drives the real forms (nonces from the pages), admin-post actions and REST routes.
# The settings option is backed up and restored. Uses ~2 The Odds API credits (source-switch probe
# and one refresh). Exit status 1 on any failure.
set -uo pipefail
# Note: GET requests follow redirects because ALTERNATE_WP_CRON redirects any GET while cron is due.

SITE="${WP_SITE_URL:-http://localhost:8080}"
CONTAINER="${WP_CONTAINER:-wordpress_app}"
WORK="$(mktemp -d)"
FAILED=0
SUFFIX="$RANDOM$RANDOM"
ADMIN="oc_e2e_admin_$SUFFIX"
SUB="oc_e2e_sub_$SUFFIX"
ADMIN_PASS="$(openssl rand -hex 16)"
SUB_PASS="$(openssl rand -hex 16)"

wp() { docker exec -i "$CONTAINER" wp --allow-root "$@" 2>/dev/null; }
check() { if [[ "$2" == "0" ]]; then echo "PASS $1"; else echo "FAIL $1"; FAILED=1; fi; }
# Effective setting (defaults merged), as every plugin class reads it.
setting() { wp eval "echo wp_json_encode((new OddsComparison\\Settings\\Settings())->all()['$1'] ?? null);"; }
nonce_of() { grep -o "name=\"_wpnonce\" value=\"[a-f0-9]*\"" "$1" | head -1 | sed 's/.*value="\([a-f0-9]*\)"/\1/'; }
# Nonce of the admin-post form whose hidden action is $2 (forms are printed on one line).
action_nonce() { python3 -I -c 'import re,sys; m=re.search(r"value=\""+re.escape(sys.argv[2])+r"\"\s*/>\s*<input[^>]*name=\"_wpnonce\" value=\"([a-f0-9]+)\"", open(sys.argv[1]).read()); print(m.group(1) if m else "")' "$1" "$2"; }
rest_nonce_of() { grep -o 'createNonceMiddleware( "[a-f0-9]*" )' "$1" | grep -o '[a-f0-9]\{10\}' | head -1; }
logged_in() { [[ "$(curl -s -L -o /dev/null -w '%{http_code}' -b "$1" "$SITE/wp-admin/profile.php")" == "200" ]]; }

login() { # user pass jar (administrators may be sent to the admin-email confirmation; still logged in)
    curl -s -c "$3" -b "$3" "$SITE/wp-login.php" -o /dev/null
    curl -s -c "$3" -b "$3" -o /dev/null \
        --data-urlencode "log=$1" --data-urlencode "pwd=$2" -d 'wp-submit=Log+In&testcookie=1' \
        "$SITE/wp-login.php"
}

save_tab() { # jar tab extra-curl-data...; posts the tab form with its nonce, prints final page
    local jar=$1 tab=$2; shift 2
    curl -s -L -b "$jar" "$SITE/wp-admin/admin.php?page=odds-comparison&tab=$tab" -o "$WORK/form.html"
    local nonce; nonce=$(nonce_of "$WORK/form.html")
    curl -s -L -b "$jar" -c "$jar" -e "$SITE/wp-admin/admin.php?page=odds-comparison&tab=$tab" \
        -d "option_page=odds_comparison&action=update&_wpnonce=$nonce" \
        --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=odds-comparison&tab=$tab" \
        --data-urlencode "odds_comparison_settings[_tab]=$tab" "$@" \
        "$SITE/wp-admin/options.php" -o "$WORK/saved.html"
}

cleanup() {
    wp user delete "$ADMIN" --yes >/dev/null
    wp user delete "$SUB" --yes >/dev/null
    [[ -f "$WORK/backup.json" ]] && wp eval "update_option('odds_comparison_settings', json_decode('$(cat "$WORK/backup.json")', true));" >/dev/null
    rm -f "$WORK"/*; rmdir "$WORK"
}
trap cleanup EXIT

wp eval "echo wp_json_encode(get_option('odds_comparison_settings'));" > "$WORK/backup.json"
wp user create "$ADMIN" "$ADMIN@example.test" --role=administrator --user_pass="$ADMIN_PASS" >/dev/null
wp user create "$SUB" "$SUB@example.test" --role=subscriber --user_pass="$SUB_PASS" >/dev/null
A="$WORK/admin.jar"; S="$WORK/sub.jar"
login "$ADMIN" "$ADMIN_PASS" "$A"; logged_in "$A"; check "administrator can log in" $?
login "$SUB" "$SUB_PASS" "$S"; logged_in "$S"; check "subscriber can log in" $?

# --- screen, tabs, assets scoped ------------------------------------------------------------
curl -s -L -b "$A" "$SITE/wp-admin/admin.php?page=odds-comparison" -o "$WORK/general.html"
grep -q 'nav-tab nav-tab-active" aria-current="page">General' "$WORK/general.html"; check "settings screen renders with tabs" $?
grep -q 'assets/admin/admin.js' "$WORK/general.html"; check "admin.js loads on the plugin screen" $?
curl -s -L -b "$A" "$SITE/wp-admin/index.php" | grep -q 'assets/admin/admin.js'; [[ $? -ne 0 ]]; check "admin.js not loaded on the dashboard" $?
code=$(curl -s -L -o /dev/null -w '%{http_code}' -b "$S" "$SITE/wp-admin/admin.php?page=odds-comparison")
[[ "$code" == "403" ]]; check "subscriber cannot open the screen (HTTP $code)" $?

# --- Fresh install: stored settings equal the defaults exactly ------------------------------------
wp eval 'delete_option("odds_comparison_settings"); (new OddsComparison\Settings\Settings())->installDefaults();' >/dev/null
save_tab "$A" markets --data-urlencode "odds_comparison_settings[enabled_markets][]=outright-winner"
[[ "$(setting enabled_markets)" == '["outright-winner"]' ]] && ! grep -q 'settings form was incomplete' "$WORK/saved.html"
check "first save on a fresh install works (stored value equals defaults)" $?

# --- merge on save ---------------------------------------------------------------------------
before_cache=$(setting refresh_interval); before_source=$(setting active_source)
save_tab "$A" markets --data-urlencode "odds_comparison_settings[enabled_markets][]=match-result" \
    --data-urlencode "odds_comparison_settings[enabled_markets][]=outright-winner"
[[ "$(setting enabled_markets)" == '["match-result","outright-winner"]' ]]; check "markets tab saved" $?
after_cache=$(setting refresh_interval); after_source=$(setting active_source)
[[ "$after_cache" == "$before_cache" && "$after_source" == "$before_source" ]]; check "saving markets kept other tabs ($before_cache/$before_source -> $after_cache/$after_source)" $?

save_tab "$A" markets
grep -q 'Enable at least one market' "$WORK/saved.html"; check "empty market list rejected with notice" $?
[[ "$(setting enabled_markets)" == '["match-result","outright-winner"]' ]]; check "previous markets kept" $?

# --- bookmakers ----------------------------------------------------------------------
save_tab "$A" bookmakers \
    --data-urlencode "odds_comparison_settings[bookmakers][william-hill][enabled]=1" \
    --data-urlencode "odds_comparison_settings[bookmakers][william-hill][link]=https://aff.example/wh?ref=e2e" \
    --data-urlencode "odds_comparison_settings[bookmakers][william-hill][label]=Hill" \
    --data-urlencode "odds_comparison_settings[bookmaker_order][]=paddy-power" \
    --data-urlencode "odds_comparison_settings[bookmaker_order][]=william-hill"
[[ "$(setting bookmakers | grep -o '"william-hill":{[^}]*}')" == '"william-hill":{"enabled":true,"label":"Hill","link":"https:\/\/aff.example\/wh?ref=e2e"}' ]]; check "bookmaker link/label saved" $?
wp eval '$s=OddsComparison\Plugin::instance(); echo json_encode(array_slice($s->settings ?? [], 0, 0));' >/dev/null
[[ "$(setting bookmaker_order | cut -c1-30)" == '["paddy-power","william-hill",' ]]; check "bookmaker order saved" $?
[[ "$(setting bookmakers | grep -o '"betfred":{"enabled":false')" != "" ]]; check "unticked bookmaker disabled" $?

save_tab "$A" bookmakers \
    --data-urlencode "odds_comparison_settings[bookmakers][william-hill][enabled]=1" \
    --data-urlencode "odds_comparison_settings[bookmakers][william-hill][link]=javascript:alert(1)"
grep -q 'The link for william-hill must be a full http' "$WORK/saved.html"; ok=$?
[[ $ok -ne 0 ]] && grep -o '<div[^>]*settings-error[^>]*>.\{0,300\}' "$WORK/saved.html" | head -3
check "javascript: link rejected with notice" $ok
setting bookmakers | grep -q 'aff.example\\/wh?ref=e2e'; check "previous link kept" $?

# The "Bookmakers & markets" form posts links, show boxes and markets, but no labels or order.
curl -s -L -b "$A" "$SITE/wp-admin/admin.php?page=odds-comparison&tab=markets" -o "$WORK/combined.html"
grep -q 'aria-current="page">Bookmakers &amp; markets' "$WORK/combined.html" && grep -q 'data-oc-market' "$WORK/combined.html" \
    && grep -q 'id="oc-bm-william-hill-link"' "$WORK/combined.html" && ! grep -q 'name="odds_comparison_settings\[bookmaker_order\]' "$WORK/combined.html"
check "old Markets tab URL opens the combined Bookmakers & markets tab" $?
save_tab "$A" bookmakers -d 'odds_comparison_settings[_markets]=1' \
    --data-urlencode "odds_comparison_settings[bookmakers][william-hill][enabled]=1" \
    --data-urlencode "odds_comparison_settings[bookmakers][william-hill][link]=https://aff.example/wh?ref=e2e2" \
    --data-urlencode "odds_comparison_settings[enabled_markets][]=outright-winner"
[[ "$(setting bookmakers | grep -o '"william-hill":{[^}]*}')" == '"william-hill":{"enabled":true,"label":"Hill","link":"https:\/\/aff.example\/wh?ref=e2e2"}' ]]; check "combined form keeps the stored label" $?
[[ "$(setting bookmaker_order | cut -c1-30)" == '["paddy-power","william-hill",' ]]; check "combined form keeps the stored order" $?
[[ "$(setting enabled_markets)" == '["outright-winner"]' ]]; check "combined form saves markets" $?
save_tab "$A" bookmakers -d 'odds_comparison_settings[_markets]=1' \
    --data-urlencode "odds_comparison_settings[bookmakers][william-hill][enabled]=1"
grep -q 'Enable at least one market' "$WORK/saved.html" && [[ "$(setting enabled_markets)" == '["outright-winner"]' ]]; check "combined form with no market ticked keeps markets" $?

# --- cache validation and reschedule ---------------------------------------------------
save_tab "$A" cache -d 'odds_comparison_settings[refresh_interval]=30&odds_comparison_settings[stale_after]=5&odds_comparison_settings[requests_per_run]=20&odds_comparison_settings[min_request_interval]=3&odds_comparison_settings[api_refresh_interval]=180'
grep -q 'Stale after' "$WORK/saved.html"; check "invalid stale_after rejected with notice" $?
save_tab "$A" cache -d 'odds_comparison_settings[refresh_interval]=30&odds_comparison_settings[stale_after]=60&odds_comparison_settings[requests_per_run]=20&odds_comparison_settings[min_request_interval]=3&odds_comparison_settings[api_refresh_interval]=180'
[[ "$(setting refresh_interval)" == "30" ]]; check "refresh interval saved" $?
wp cron event list --hook=odds_comparison_refresh --fields=recurrence --format=csv | grep -q '30 minutes'; check "settings_saved rescheduled cron to 30 minutes" $?
save_tab "$A" cache -d 'odds_comparison_settings[refresh_interval]=15&odds_comparison_settings[stale_after]=60&odds_comparison_settings[requests_per_run]=20&odds_comparison_settings[min_request_interval]=3&odds_comparison_settings[api_refresh_interval]=180'

# --- active_source probe ---------------------------------------------------------------------------
general=(-d 'odds_comparison_settings[data_mode]=live&odds_comparison_settings[default_odds_format]=fractional&odds_comparison_settings[enabled_competitions][]=premier-league')
save_tab "$A" general "${general[@]}" -d 'odds_comparison_settings[active_source]=theoddsapi'
[[ "$(setting active_source)" == '"theoddsapi"' ]]; check "switch to The Odds API passes the probe" $?
save_tab "$A" general "${general[@]}" -d 'odds_comparison_settings[active_source]=bettingodds'
grep -q 'Could not switch to BettingOdds.com' "$WORK/saved.html"; check "switch to blocked scraper rejected with reason" $?
[[ "$(setting active_source)" == '"theoddsapi"' ]]; check "current source kept after failed probe" $?

# --- status tab, REST and actions -------------------------------------------------------------
curl -s -L -b "$A" "$SITE/wp-admin/admin.php?page=odds-comparison&tab=status" -o "$WORK/status.html"
grep -q '>Sources</h2>' "$WORK/status.html" && grep -q 'credits left\|API key configured' "$WORK/status.html" \
    && grep -q 'id="oc-refresh-form"' "$WORK/status.html" && grep -q '>Recent refreshes</h2>' "$WORK/status.html"; check "status tab shows live strip, source health and refreshes" $?
rest_nonce=$(rest_nonce_of "$WORK/status.html")
code=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/?rest_route=/odds-comparison/v1/status"); [[ "$code" == "401" ]]; check "REST status: anonymous 401 ($code)" $?
curl -s -L -b "$S" "$SITE/wp-admin/profile.php" -o "$WORK/sub.html"
sub_nonce=$(rest_nonce_of "$WORK/sub.html")
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$S" -H "X-WP-Nonce: $sub_nonce" "$SITE/?rest_route=/odds-comparison/v1/status"); [[ "$code" == "403" ]]; check "REST status: subscriber 403 ($code)" $?
code=$(curl -s -o "$WORK/rest.json" -w '%{http_code}' -b "$A" -H "X-WP-Nonce: $rest_nonce" "$SITE/?rest_route=/odds-comparison/v1/status"); [[ "$code" == "200" ]]; check "REST status: administrator 200" $?
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$A" "$SITE/?rest_route=/odds-comparison/v1/refresh" -X POST); [[ "$code" == "401" || "$code" == "403" ]]; check "REST refresh without nonce refused ($code)" $?
code=$(curl -s -o "$WORK/refresh.json" -w '%{http_code}' -b "$A" -H "X-WP-Nonce: $rest_nonce" -X POST "$SITE/?rest_route=/odds-comparison/v1/refresh"); [[ "$code" == "202" || "$code" == "200" ]]; check "REST refresh queued ($code)" $?
wp cron event run --due-now >/dev/null
wp eval 'echo wp_json_encode(get_option("odds_comparison_status")["lastRun"] ?? null);' > "$WORK/run.json"
grep -q '"trigger":"manual"' "$WORK/run.json" && grep -q '"source":"theoddsapi"' "$WORK/run.json"; ok=$?
check "queued manual run executed: $(cut -c1-160 "$WORK/run.json")" $ok

clear_nonce=$(action_nonce "$WORK/status.html" odds_comparison_clear_cache)
[[ -n "$clear_nonce" ]]; check "clear-cache form carries its own nonce" $?
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$S" -d "action=odds_comparison_clear_cache&_wpnonce=$clear_nonce" "$SITE/wp-admin/admin-post.php"); [[ "$code" == "403" ]]; check "admin-post: subscriber with admin nonce refused ($code)" $?
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$A" -d "action=odds_comparison_clear_cache&_wpnonce=bad" "$SITE/wp-admin/admin-post.php"); [[ "$code" == "403" ]]; check "admin-post: bad nonce refused ($code)" $?
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$A" -e "$SITE/wp-admin/admin.php?page=odds-comparison&tab=status" -d "action=odds_comparison_clear_cache&_wpnonce=$clear_nonce" "$SITE/wp-admin/admin-post.php")
[[ "$loc" == *"oc_notice=cache_cleared"* ]]; check "admin-post: clear cache redirects with notice" $?

[[ $FAILED -eq 0 ]] && echo "All admin e2e checks passed." || echo "Some admin e2e checks FAILED."
exit $FAILED
