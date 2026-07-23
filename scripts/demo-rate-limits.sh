#!/usr/bin/env bash
# Demo script for the SaaS rate limiter.
# Usage: ./scripts/demo-rate-limits.sh [base_url]
# Requires: curl, jq (optional, for pretty output)

set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8000}"
ORG_NOISY="org_globex"
ORG_OTHER="org_acme"

echo "=== SaaS Rate Limiter Demo ==="
echo "Base URL: $BASE_URL"
echo ""

echo "1. Health check (exempt — no X-Org-Id required)"
curl -s "$BASE_URL/api/health" | (command -v jq >/dev/null && jq . || cat)
echo ""

echo "2. Missing org header → 401"
curl -s -w "\nHTTP %{http_code}\n" "$BASE_URL/api/items"
echo ""

echo "3. Valid read request → 200"
curl -s -w "\nHTTP %{http_code}\n" \
  -H "X-Org-Id: $ORG_OTHER" \
  "$BASE_URL/api/items"
echo ""

echo "4. Noisy tenant: hammer POST /api/items until 429 (endpoint limit: 20/min)"
echo "   Org: $ORG_NOISY"
for i in $(seq 1 22); do
  STATUS=$(curl -s -o /tmp/rate-limiter-demo-response.json -w "%{http_code}" \
    -X POST \
    -H "X-Org-Id: $ORG_NOISY" \
    -H "Content-Type: application/json" \
    "$BASE_URL/api/items")

  if [ "$STATUS" = "429" ]; then
    echo "   Request $i → HTTP $STATUS (rate limited)"
    (command -v jq >/dev/null && jq . /tmp/rate-limiter-demo-response.json || cat /tmp/rate-limiter-demo-response.json)
    break
  fi

  echo "   Request $i → HTTP $STATUS"
done
echo ""

echo "5. Other tenant: GET /api/items still succeeds while noisy tenant is limited"
curl -s -w "\nHTTP %{http_code}\n" \
  -H "X-Org-Id: $ORG_OTHER" \
  "$BASE_URL/api/items"
echo ""

echo "=== Demo complete ==="
