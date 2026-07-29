#!/usr/bin/env bash
# Demo script for the SaaS rate limiter.
# Usage: ./scripts/demo-rate-limits.sh [base_url]
# Requires: curl, jq (optional, for pretty output)

set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8000}"

# Unique org ids per run. Counters now genuinely persist across requests (that is the whole
# point), so reusing fixed ids meant a second run within the same 60s window started with the
# previous run's counts — which can turn "still limited from last time" into a false PASS.
# Unknown org ids fall back to default_tier and get their own counter key, so this exercises
# exactly the same code paths with a guaranteed-clean slate.
RUN_ID="$(date +%s)_$$"
ORG_NOISY="org_demo_noisy_${RUN_ID}"
ORG_OTHER="org_demo_other_${RUN_ID}"

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
    LIMITED_AT=$i
    break
  fi

  echo "   Request $i → HTTP $STATUS"
done
echo ""

echo "5. Other tenant: GET /api/items still succeeds while noisy tenant is limited"
OTHER_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "X-Org-Id: $ORG_OTHER" \
  "$BASE_URL/api/items")
echo "   HTTP $OTHER_STATUS"
echo ""

# --- Assertions -------------------------------------------------------------
#
# This block is the point of the script, not decoration. An earlier version of this
# limiter kept counters in a PHP array on a container-bound singleton, which is discarded
# after every request — so it enforced nothing over HTTP while all 36 unit and feature
# tests stayed green (PHPUnit runs a whole test in one process, so the array survived
# there). This script printed 22 x HTTP 201 and exited 0, reporting success.
#
# In-process tests structurally cannot catch a state-lifetime bug. Something has to make
# real HTTP requests and fail loudly. That is this block.

FAILED=0

if [ -z "${LIMITED_AT:-}" ]; then
  echo "FAIL: sent $((i)) POSTs with a 20/min limit and never received a 429."
  echo "      The limiter is not enforcing anything. Check rate_limits.store.driver —"
  echo "      the 'array' driver cannot persist counters across HTTP requests."
  FAILED=1
else
  echo "PASS: noisy tenant limited at request $LIMITED_AT (endpoint limit: 20/min)."
fi

if [ "$OTHER_STATUS" != "200" ]; then
  echo "FAIL: a second tenant got HTTP $OTHER_STATUS while the noisy tenant was limited."
  echo "      Tenants are sharing a counter — the noisy-neighbour bug this exists to prevent."
  FAILED=1
else
  echo "PASS: second tenant unaffected by the noisy tenant's limit."
fi

echo ""
if [ "$FAILED" -ne 0 ]; then
  echo "=== Demo FAILED ==="
  exit 1
fi

echo "=== Demo complete ==="
