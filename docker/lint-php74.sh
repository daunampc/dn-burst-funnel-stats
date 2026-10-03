#!/bin/sh
# Syntax-check plugin PHP files against PHP 7.4, the plugin's minimum version.
cd /app || exit 1

fail=0

for f in dn-burst-funnel-stats.php uninstall.php $(find includes -name '*.php'); do
	out=$(php -l "$f" 2>&1) || { echo "$out"; fail=1; }
done

[ "$fail" -eq 0 ] && echo "PHP 7.4 lint OK"

exit "$fail"
