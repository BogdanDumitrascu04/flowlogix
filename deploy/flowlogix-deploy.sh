#!/usr/bin/env bash
# Updates the live app to the latest commit on main.
# Installed on the server as /usr/local/bin/flowlogix-deploy. The GitHub Actions
# deploy key can run only this script: its line in ~/.ssh/authorized_keys starts with
#   command="/usr/local/bin/flowlogix-deploy",no-port-forwarding,no-agent-forwarding,no-X11-forwarding,no-pty
# config.php is ignored by git, so the server's database settings are never overwritten.
set -euo pipefail

APP_DIR=/var/www/flowlogix
git_www() {
    sudo -u www-data git -C "$APP_DIR" -c safe.directory="$APP_DIR" "$@"
}

git_www fetch --quiet origin main
git_www reset --quiet --hard origin/main
echo "Deployed: $(git_www log --oneline -1)"
