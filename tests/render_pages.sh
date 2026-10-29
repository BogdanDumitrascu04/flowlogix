#!/usr/bin/env bash
# Opens every page, period and export as each role through the PHP command line
# and fails if PHP prints an error. Used by GitHub Actions (.github/workflows/ci-cd.yml).
#
# Run from the project folder, with config.php pointing to a database loaded
# from database/flowlogix.sql:
#   bash tests/render_pages.sh
set -u
cd "$(dirname "$0")/.." || exit 1

SESSION_DIR=$(mktemp -d)
trap 'rm -rf "$SESSION_DIR"' EXIT

# Renders one page as a logged-in user: the session is filled in first,
# then the page is included exactly as Apache would run it.
render() { # role page query-string
    php -d display_errors=1 -d session.save_path="$SESSION_DIR" -r "
        parse_str('$3', \$_GET);
        session_id('test$1');
        session_start();
        \$_SESSION['user'] = '$1';
        \$_SESSION['role'] = '$1';
        session_write_close();
        include '$2';
    " 2>&1
}

total=0
failed=0
check() { # role page query-string
    total=$((total + 1))
    local output
    output=$(render "$1" "$2" "$3")
    if echo "$output" | grep -q -i -E "fatal error|warning:|notice:|deprecated:|uncaught"; then
        failed=$((failed + 1))
        echo "FAIL  $1  $2?$3"
        echo "$output" | grep -i -m1 -E "fatal error|warning:|notice:|deprecated:|uncaught" | cut -c1-300
    fi
}

period_query() { [ "$1" = "none" ] && echo "" || echo "period=$1"; }

for period in none today 7d 30d month bogus; do
    q=$(period_query "$period")
    for page in dashboard-tura receptii-active picking-packing-tura expedieri-urgente raport-tura alerte-blocaje exceptii-active control-operational; do
        check tura "$page.php" "$q"
    done
    for page in dashboard-depozit rapoarte raport-depozit inbound pickpack outbound; do
        check depozit "$page.php" "$q"
    done
    for page in dashboard-manager raport-manager; do
        check manager "$page.php" "$q"
    done
    for page in receptii-active picking-packing-tura expedieri-urgente inbound pickpack outbound; do
        check tura "$page.php" "export=csv&$q"
    done
done

for period in all today 7d 30d month; do
    for area in all inbound pickpack outbound; do
        for detail in executive full; do
            check manager export_report.php "period=$period&area=$area&detail=$detail"
        done
    done
done

check guest login.php ""
check admin admin.php ""
check admin add_today_demo.php ""
check manager setari.php ""

echo "Pages checked: $total, failed: $failed"
[ "$failed" -eq 0 ]
