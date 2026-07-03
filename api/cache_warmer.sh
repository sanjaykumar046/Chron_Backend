#!/bin/bash
API_URL="http://localhost/api/ai_insights.php"

# Get last working day (skip weekends)
get_last_workday() {
    local d=$(date -d "yesterday" +%Y-%m-%d)
    local dow=$(date -d "$d" +%u)  # 1=Mon ... 7=Sun
    if [ "$dow" -eq 7 ]; then d=$(date -d "2 days ago" +%Y-%m-%d); fi  # Sun → Fri
    if [ "$dow" -eq 6 ]; then d=$(date -d "2 days ago" +%Y-%m-%d); fi  # Sat → Fri
    echo "$d"
}

TODAY=$(date +%Y-%m-%d)
YESTERDAY=$(get_last_workday)

for DATE in "$YESTERDAY" "$TODAY"; do
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] Warming cache for $DATE ..."
  HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 300 \
    "${API_URL}?startDate=${DATE}&endDate=${DATE}&reportType=MONTHLY_EXPORT&bust_cache=1&userid=ALL")
  if [ "$HTTP_CODE" = "200" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✓ $DATE warmed (HTTP $HTTP_CODE)"
  else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✗ $DATE FAILED (HTTP $HTTP_CODE)"
  fi
done
