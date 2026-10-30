#!/usr/bin/env bash
# Checks the protections that only show over real HTTP requests: CSRF tokens on every
# POST form, the limit on failed logins and the formula guard in CSV exports.
# Used by GitHub Actions (.github/workflows/ci-cd.yml) against `php -S`.
#
# Run from the project folder, with config.php pointing to a database loaded
# from database/flowlogix.sql and the app served at BASE_URL:
#   BASE_URL=http://localhost/flowlogix bash tests/security_checks.sh
set -u
cd "$(dirname "$0")/.." || exit 1

BASE=${BASE_URL:-http://127.0.0.1:8000}
JAR=$(mktemp)

# Runs one SQL statement on the database from config.php
sql() {
    php -r "require 'config.php'; \$conn->query(\"$1\");"
}

# Failed logins from this machine would block the next test run for 15 minutes
cleanup() {
    sql "DELETE FROM login_attempts WHERE ip IN ('127.0.0.1', '::1')"
    sql "DELETE FROM inbound_orders WHERE reception_number = 'TEST-CSV-FORMULA'"
    rm -f "$JAR"
}
trap cleanup EXIT
cleanup

failed=0
expect() { # description expected actual
    if [ "$2" = "$3" ]; then
        echo "ok    $1"
    else
        echo "FAIL  $1: expected '$2', got '$3'"
        failed=$((failed + 1))
    fi
}
redirect() { curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" -c "$JAR" "$@" | sed "s#^$BASE/##"; }
status()   { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$@"; }
token()    { curl -s -b "$JAR" -c "$JAR" "$BASE/$1" | grep -o 'name="csrf_token" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login()    { # user: logs in through the form, with the token from login.php
    rm -f "$JAR"
    local t
    t=$(token login.php)
    redirect -d "csrf_token=$t&name=$1&password=demo123" "$BASE/authenticate.php"
}

# CSRF: a form posted without the token from the page is refused
rm -f "$JAR"
expect "login without a CSRF token is refused" "login.php?error=expired" \
    "$(redirect -d 'name=manager&password=demo123' "$BASE/authenticate.php")"
expect "login with the CSRF token works" "dashboard-manager.php?user=manager&role=manager" "$(login manager)"

# Session fixation: the session ID used before the login is not the one used after it
rm -f "$JAR"
t=$(token login.php)
before=$(grep PHPSESSID "$JAR" | awk '{print $7}')
redirect -d "csrf_token=$t&name=manager&password=demo123" "$BASE/authenticate.php" > /dev/null
after=$(grep PHPSESSID "$JAR" | awk '{print $7}')
expect "login gives a new session ID" "yes" "$([ -n "$before" ] && [ "$before" != "$after" ] && echo yes || echo no)"

login admin > /dev/null
expect "admin change without a CSRF token is refused" "403" \
    "$(status -d 'action=edit&id=0&name=x' "$BASE/admin.php")"
t=$(token admin.php)
expect "admin change with the CSRF token is accepted" "200" \
    "$(status -d "csrf_token=$t&action=edit&id=0&name=x" "$BASE/admin.php")"
expect "demo data insert without a CSRF token is refused" "403" \
    "$(status -X POST "$BASE/add_today_demo.php")"

# CSV: a value that Excel would run as a formula is exported as text
sql "INSERT INTO inbound_orders (supplier_name, reception_number, status, priority, created_at) VALUES ('=HYPERLINK(1)', 'TEST-CSV-FORMULA', 'pending', 'low', NOW())"
login depozit > /dev/null
csv=$(curl -s -b "$JAR" "$BASE/inbound.php?export=csv&period=7d")
expect "CSV export prefixes formulas with an apostrophe" "yes" \
    "$(echo "$csv" | grep -q ";'=HYPERLINK(1);" && echo yes || echo no)"

# Login limit: after 5 wrong passwords, even the right one is refused for 15 minutes
rm -f "$JAR"
t=$(token login.php)
for i in 1 2 3 4 5; do
    redirect -d "csrf_token=$t&name=manager&password=wrong$i" "$BASE/authenticate.php" > /dev/null
done
expect "6th login after 5 failures is refused" "login.php?error=locked" \
    "$(redirect -d "csrf_token=$t&name=manager&password=demo123" "$BASE/authenticate.php")"

echo "Security checks failed: $failed"
[ "$failed" -eq 0 ]
