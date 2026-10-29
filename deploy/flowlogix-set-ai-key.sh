#!/usr/bin/env bash
# Saves the Anthropic API key into the server's config.php without showing it on screen.
# Installed on the server as /usr/local/bin/flowlogix-set-ai-key and run by the owner over SSH:
#   ssh -t azureuser@<server> sudo /usr/local/bin/flowlogix-set-ai-key
# The key never goes into git, the terminal history or the logs.
set -euo pipefail

CONFIG=/var/www/flowlogix/config.php

read -r -s -p "Lipește cheia Anthropic API și apasă Enter: " KEY
echo

if [[ ! "$KEY" =~ ^sk-ant-[A-Za-z0-9_-]+$ ]]; then
    echo "Cheia trebuie să înceapă cu sk-ant- și să conțină doar litere, cifre, - și _." >&2
    exit 1
fi

# Replaces an older key, if there is one, and adds the new one before the database connection
sed -i '/^\$anthropicApiKey = /d' "$CONFIG"
sed -i "s|^\(\$conn = new mysqli\)|\$anthropicApiKey = '$KEY';\n\n\1|" "$CONFIG"
php -l "$CONFIG" > /dev/null

echo "Cheia a fost salvată. Test: sudo -u www-data php /var/www/flowlogix/jobs/daily_summary.php"
