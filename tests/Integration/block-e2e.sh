#!/usr/bin/env bash
# End-to-end checks of the odds table block against a running local WordPress.
#
#   tests/Integration/block-e2e.sh            # defaults: http://localhost:8080, container wordpress_app
#
# Runs in Demo mode: real BettingOdds.com pages captured in fixtures/ replace network access,
# so no API credits are used and prices are deterministic. Creates temporary users and posts,
# runs the real refresh, checks the frontend HTML, editor REST routes and the editor preview,
# then restores the settings, tracked pairs and cached odds. Exit status 1 on any failure.
set -uo pipefail

SITE="${WP_SITE_URL:-http://localhost:8080}"
CONTAINER="${WP_CONTAINER:-wordpress_app}"
WORK="$(mktemp -d)"
FAILED=0
SUFFIX="$RANDOM$RANDOM"
AUTHOR="oc_e2e_author_$SUFFIX"
SUB="oc_e2e_sub_$SUFFIX"
AUTHOR_PASS="$(openssl rand -hex 16)"
SUB_PASS="$(openssl rand -hex 16)"
CHELSEA='bettingodds:premier-league/4othg6zhiyox0rivijbld3swk'
OUTRIGHTS='bettingodds:premier-league/outrights'
SEVEN='["william-hill","paddy-power","betfair-sportsbook","sky-bet","betfred","unibet","betway"]'
OPTIONS=(odds_comparison_settings odds_comparison_tracked odds_comparison_events odds_comparison_status)

wp() { docker exec -i "$CONTAINER" wp --allow-root "$@" 2>/dev/null; }
# Follow redirects: with ALTERNATE_WP_CRON, WordPress redirects a visit when cron is due.
page() { curl -s -L "$SITE/?p=$1" -o "$2"; }
check() { if [[ "$2" == "0" ]]; then echo "PASS $1"; else echo "FAIL $1"; FAILED=1; fi; }
count() { grep -o "$1" "$2" | wc -l | tr -d ' '; }
rest_nonce_of() { grep -o 'createNonceMiddleware( "[a-f0-9]*" )' "$1" | grep -o '[a-f0-9]\{10\}' | head -1; }
login() {
    curl -s -c "$3" -b "$3" "$SITE/wp-login.php" -o /dev/null
    curl -s -c "$3" -b "$3" -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$2" \
        -d 'wp-submit=Log+In&testcookie=1' "$SITE/wp-login.php"
}
block() { # eventId marketKey bookmakers-json
    printf '<!-- wp:odds-comparison/odds-table {"eventId":"%s","marketKey":"%s","bookmakers":%s} /-->' "$1" "$2" "$3"
}
publish() { # title content -> post id (ids go to a file: this runs in a subshell)
    local id; id=$(wp post create --post_status=publish --post_title="$1" --post_content="$2" --porcelain)
    echo "$id" >> "$WORK/posts"; echo "$id"
}

cleanup() {
    # `< /dev/null`: docker exec -i would otherwise swallow the rest of the id list.
    [[ -f "$WORK/posts" ]] && while read -r id; do wp post delete "$id" --force >/dev/null < /dev/null; done < "$WORK/posts"
    wp user delete "$AUTHOR" --yes >/dev/null
    wp user delete "$SUB" --yes >/dev/null
    for option in "${OPTIONS[@]}"; do
        autoload=no; [[ "$option" == odds_comparison_settings ]] && autoload=yes
        if [[ -s "$WORK/$option.json" ]]; then
            wp option update "$option" --format=json --autoload="$autoload" < "$WORK/$option.json" >/dev/null
        else
            wp option delete "$option" >/dev/null
        fi
    done
    wp eval 'OddsComparison\Plugin::instance()->repository()->flush();' >/dev/null
    [[ $FAILED -ne 0 && -f "$WORK/full.html" ]] && cp "$WORK/full.html" "${TMPDIR:-/tmp}/oc-block-e2e-full.html"
    rm -f "$WORK"/*; rmdir "$WORK"
}
trap cleanup EXIT

# Backup (empty file = option absent) and switch to Demo mode (real captured pages, no network).
for option in "${OPTIONS[@]}"; do wp option get "$option" --format=json > "$WORK/$option.json" || : > "$WORK/$option.json"; done
[[ -s "$WORK/odds_comparison_settings.json" ]]; check "settings backed up" $?
wp eval '$s = (array) get_option("odds_comparison_settings", []);
    update_option("odds_comparison_settings", array_replace($s, ["data_mode" => "demo", "active_source" => "bettingodds",
        "enabled_competitions" => ["premier-league"], "enabled_markets" => ["match-result", "outright-winner"], "bookmakers" => []]));
    delete_option("odds_comparison_tracked"); delete_transient("odds_comparison_cb_" . md5("www.bettingodds.com"));' >/dev/null

# --- registration ---------------------------------------------------------------------------
wp eval '$t = WP_Block_Type_Registry::get_instance()->get_registered("odds-comparison/odds-table");
    echo $t && $t->editor_script_handles && $t->view_script_handles && $t->style_handles && $t->render_callback ? "ok" : "missing";' | grep -q ok
check "block registered with editor/view scripts, style and server render" $?

# --- Posts, tracking and the real refresh ----------------------------------------------------------
FULL=$(publish "OC e2e full" "$(block "$CHELSEA" match-result '["william-hill","paddy-power","betfair-sportsbook","sky-bet","betfred","unibet","betway","boylesports","matchbook","betano"]')")
SEVEN_ID=$(publish "OC e2e seven" "$(block "$CHELSEA" match-result "$SEVEN")")
OUT=$(publish "OC e2e outright" "$(block "$OUTRIGHTS" outright-winner '["william-hill","paddy-power","sky-bet","betfred","boylesports"]')")
PLAIN=$(publish "OC e2e plain" "<!-- wp:paragraph --><p>No odds here.</p><!-- /wp:paragraph -->")
wp eval 'echo wp_json_encode(OddsComparison\Plugin::instance()->scheduler()->run("manual"));' > "$WORK/run.json"
grep -q '"failed":0' "$WORK/run.json" && grep -q '"fetched":2' "$WORK/run.json"; ok=$?
check "refresh fetched both tracked pairs: $(cut -c1-140 "$WORK/run.json")" $ok

# --- frontend -----------------------------------------------------------------
page "$FULL" "$WORK/full.html"
grep -q '<caption id="oc-odds-[a-f0-9]*-caption">Chelsea vs Manchester United — Match Result</caption>' "$WORK/full.html"; ok=$?
[[ $ok -ne 0 ]] && grep -oE 'oc-odds[-_a-z]*|<title>[^<]*' "$WORK/full.html" | sort | uniq -c | head -8
check "frontend table with caption (post $FULL)" $ok
[[ "$(count '<th scope="row">' "$WORK/full.html")" == "10" ]]; check "10 bookmaker rows" $?
links=$(count 'rel="nofollow sponsored noopener" target="_blank"' "$WORK/full.html"); names=$(count 'class="oc-odds__bookmaker"' "$WORK/full.html")
[[ "$names" -ge 10 && "$links" == "$names" && "$(count '<a class="oc-odds__price' "$WORK/full.html")" == "0" ]]; check "bookmaker names link with rel nofollow sponsored noopener, prices do not ($links links)" $?
grep -q 'data-dec="1.83" data-frac="5/6" data-us="-120"' "$WORK/full.html"; check "prices carry all three formats" $?
grep -q 'class="oc-odds__cell is-best"' "$WORK/full.html" && grep -q 'best odds</span>' "$WORK/full.html"; check "best price marked with text, not colour only" $?
grep -Eq '<time datetime="[0-9T:+-]+">Updated [0-9]{1,2}:[0-9]{2}</time>' "$WORK/full.html"; check "freshness footer with <time>" $?
grep -q 'Demo data' "$WORK/full.html" && grep -q '18+ | Odds subject to change' "$WORK/full.html"; check "demo badge and disclaimer" $?
grep -q 'role="region" aria-labelledby=' "$WORK/full.html" && grep -q 'tabindex="0"' "$WORK/full.html"; check "focusable labelled scroll region" $?
grep -q 'odds-table/view.js' "$WORK/full.html" && grep -q 'odds-table/style-index.css' "$WORK/full.html"; check "view script and style loaded with the block" $?
grep -q 'class="oc-odds__switcher" hidden' "$WORK/full.html"; check "format switcher hidden until JS enables it" $?
page "$PLAIN" "$WORK/plain.html"
! grep -q 'odds-table/view.js' "$WORK/plain.html" && ! grep -q 'odds-table/style-index.css' "$WORK/plain.html"; check "no block assets on a page without the block" $?

page "$SEVEN_ID" "$WORK/seven.html"
[[ "$(count '<th scope="row">' "$WORK/seven.html")" == "7" ]] && ! grep -q '>BoyleSports' "$WORK/seven.html"; check "removing 3 bookmakers hides them on the frontend" $?

page "$OUT" "$WORK/out.html"
grep -q 'oc-odds--selection-rows' "$WORK/out.html" && [[ "$(count '<th scope="row">' "$WORK/out.html")" == "10" ]]; check "outright table transposed, 10 favourites" $?

wp eval '$s = (array) get_option("odds_comparison_settings"); $s["bookmakers"]["paddy-power"] = ["enabled" => false];
    update_option("odds_comparison_settings", $s);' >/dev/null
page "$FULL" "$WORK/disabled.html"
[[ "$(count '<th scope="row">' "$WORK/disabled.html")" == "9" ]] && ! grep -q '>Paddy Power' "$WORK/disabled.html"; check "bookmaker disabled in settings disappears from the block" $?

# --- editor REST routes ------------------------------------------------------------------------
wp user create "$AUTHOR" "$AUTHOR@example.test" --role=author --user_pass="$AUTHOR_PASS" >/dev/null
wp user create "$SUB" "$SUB@example.test" --role=subscriber --user_pass="$SUB_PASS" >/dev/null
A="$WORK/author.jar"; S="$WORK/sub.jar"
login "$AUTHOR" "$AUTHOR_PASS" "$A"; login "$SUB" "$SUB_PASS" "$S"
curl -s -b "$A" "$SITE/wp-admin/profile.php" -o "$WORK/author.html"; AN=$(rest_nonce_of "$WORK/author.html")
curl -s -b "$S" "$SITE/wp-admin/profile.php" -o "$WORK/sub.html"; SN=$(rest_nonce_of "$WORK/sub.html")
api() { curl -s -o "$WORK/api.json" -w '%{http_code}' -b "$1" -H "X-WP-Nonce: $2" "${@:4}" "$SITE/?rest_route=/odds-comparison/v1$3"; }

[[ "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/?rest_route=/odds-comparison/v1/events")" == "401" ]]; check "events: anonymous 401" $?
[[ "$(api "$S" "$SN" /events)" == "403" ]]; check "events: subscriber 403" $?
[[ "$(api "$A" "$AN" /events)" == "200" ]] && grep -q 'Chelsea vs Manchester United' "$WORK/api.json"; check "events: author 200 with upcoming events" $?
[[ "$(api "$A" "$AN" "/events&search=chel")" == "200" ]] && grep -q 'Chelsea' "$WORK/api.json" && ! grep -q 'Arsenal vs' "$WORK/api.json"; check "events search filters" $?
api "$A" "$AN" "/markets&eventId=$CHELSEA" >/dev/null; grep -q '"key":"match-result"' "$WORK/api.json" && ! grep -q 'outright-winner' "$WORK/api.json"; check "markets for a match: match markets only" $?
api "$A" "$AN" "/markets&eventId=$OUTRIGHTS" >/dev/null; grep -q '"key":"outright-winner"' "$WORK/api.json" && ! grep -q 'match-result' "$WORK/api.json"; check "markets for outrights: outright markets only" $?
api "$A" "$AN" /bookmakers >/dev/null; [[ "$(count '"slug"' "$WORK/api.json")" == "9" ]]; check "bookmakers: enabled ones only (9)" $?
api "$A" "$AN" "/selections&eventId=$CHELSEA&marketKey=match-result" >/dev/null; grep -q '"key":"home"' "$WORK/api.json" && grep -q '"key":"away"' "$WORK/api.json"; check "selections of stored odds" $?
[[ "$(api "$A" "$AN" "/selections&eventId=bad%20id&marketKey=match-result")" == "400" ]]; check "invalid eventId rejected by schema (400)" $?
[[ "$(api "$A" "$AN" /track -X POST -H 'Content-Type: application/json' -d "{\"eventId\":\"$CHELSEA\",\"marketKey\":\"match-result\"}")" == "200" ]] && grep -q '"cached":true' "$WORK/api.json"; check "track: author 200 (already cached)" $?
[[ "$(api "$S" "$SN" /track -X POST -H 'Content-Type: application/json' -d "{\"eventId\":\"$CHELSEA\",\"marketKey\":\"match-result\"}")" == "403" ]]; check "track: subscriber 403" $?
[[ "$(api "$A" "$AN" /track -X POST -H 'Content-Type: application/json' -d '{"eventId":"bettingodds:premier-league/nope","marketKey":"match-result"}')" == "404" ]]; check "track: unknown event 404" $?

# --- editor preview (ServerSideRender = /wp/v2/block-renderer) ---------------------------------
preview() { # attributes-json
    curl -s -o "$WORK/preview.json" -w '%{http_code}' -b "$A" -H "X-WP-Nonce: $AN" -H 'Content-Type: application/json' \
        -X POST -d "{\"context\":\"edit\",\"attributes\":$1}" \
        "$SITE/?rest_route=/wp/v2/block-renderer/odds-comparison/odds-table"
}
[[ "$(preview "{\"eventId\":\"$CHELSEA\",\"marketKey\":\"match-result\",\"bookmakers\":$SEVEN}")" == "200" ]] \
    && [[ "$(count 'scope=\\"row\\"' "$WORK/preview.json")" == "6" ]]; ok=$?
check "preview equals frontend: 7 chosen minus disabled Paddy Power = 6 rows" $ok
other=$(wp eval "foreach (OddsComparison\\Plugin::instance()->events()->search('bettingodds') as \$e) { if (!\$e->isOutright() && \$e->id !== '$CHELSEA') { echo \$e->id; break; } }")
preview "{\"eventId\":\"$other\",\"marketKey\":\"match-result\",\"bookmakers\":[\"betfred\"]}" >/dev/null
grep -q 'oc-odds-fetching' "$WORK/preview.json"; ok=$?
[[ $ok -ne 0 ]] && echo "  other=$other preview=$(cut -c1-300 "$WORK/preview.json")"
check "preview of an unfetched event says it is being fetched" $ok
preview '{"eventId":"","bookmakers":["betfred"]}' >/dev/null
grep -q 'Choose an event' "$WORK/preview.json"; check "preview without event explains what to do" $?

[[ $FAILED -eq 0 ]] && echo "All block e2e checks passed." || echo "Some block e2e checks FAILED."
exit $FAILED
