#!/usr/bin/env bash
set -euo pipefail
secret="$1"
for file in /workspaces/dev/api/.env /workspaces/dev/ws/.env; do
  tmp="${file}.tmp"
  awk -v s="$secret" 'BEGIN{done=0} {if($0 ~ /^JWT_SECRET=/){print "JWT_SECRET=" s; done=1} else {print}} END{if(!done){print "JWT_SECRET=" s}}' "$file" > "$tmp"
  mv "$tmp" "$file"
done
