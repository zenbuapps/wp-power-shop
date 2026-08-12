#!/usr/bin/env bash
#
# 對 legacy posts 與 HPOS 兩種訂單儲存模式各跑一輪 integration testsuite
#
# ── 為什麼需要這支腳本 ──────────────────────────────────────
#
# `WpSettlementSummaryProviderRealAggregationTest` 有兩條**互斥**的測試：
#   test_summary_works_under_hpos_enabled       ← HPOS 開才跑，關則 skip
#   test_summary_works_under_legacy_post_orders ← HPOS 關才跑，開則 skip
#
# 任何一次執行都只有一條會跑 ⇒ **跑一輪永遠只覆蓋一半**。
#
# 🔴 那條 skip 訊息原本寫著「CI matrix with-wc-hpos 才會跑此 case」，
# 但 `grep -rn "hpos" .github/` 零命中——**那個 CI matrix 從來不存在**。
# 於是 HPOS 分支（`OrderItemSettlementRepository` / `WpSettlementSummaryProvider` /
# `WpAdminSettlementQuery` 三處雙路徑 SQL）長期零驗證，卻沒有人察覺（TD-88）。
#
# 2026-08-07 實測：測試 DB 內 wp_wc_orders / wp_wc_orders_meta 兩張表本來就存在，
# 翻一個 option 即可切換；HPOS 輪與 legacy 輪的結果**逐字相同**
# （403 tests / 1964 assertions / 0 failure / 4 skipped）⇒ HPOS 分支是正確的。
#
# ── 用法 ────────────────────────────────────────────────────
#   bash bin/test-both-order-storage.sh                    # 兩輪 integration
#   bash bin/test-both-order-storage.sh --filter FooTest   # 額外參數原樣傳給 phpunit
#
# ⚠️ 不論成功失敗，離開前一律把 option 還原成 `no`（trap EXIT），
#    否則後續所有測試會在非預期模式下跑，結果不可比。

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

WPE="./tests/e2e/node_modules/.bin/wp-env"
PHPUNIT_ARGS=("-c" "phpunit.xml.dist" "--testsuite" "integration" "--colors=never" "$@")

if [ ! -x "$WPE" ]; then
	echo "找不到 wp-env：$WPE" >&2
	echo "（請用 ./tests/e2e/node_modules/.bin/wp-env，不要用 npx wp-env——後者會抓到已棄用的 shim）" >&2
	exit 1
fi

set_hpos() {
	"$WPE" run tests-cli -- wp option update woocommerce_custom_orders_table_enabled "$1" >/dev/null 2>&1
}

restore() {
	echo ""
	echo "── 還原 woocommerce_custom_orders_table_enabled = no ──"
	set_hpos no
}
trap restore EXIT

run_round() {
	local label="$1"
	local mode="$2"
	echo ""
	echo "════════════════════════════════════════════════════════"
	echo "  輪次：${label}（woocommerce_custom_orders_table_enabled = ${mode}）"
	echo "════════════════════════════════════════════════════════"
	set_hpos "$mode"
	"$WPE" run tests-cli --env-cwd=wp-content/plugins/power-shop \
		-- vendor/bin/phpunit "${PHPUNIT_ARGS[@]}"
	return $?
}

FAILED=0

run_round "legacy posts" "no" || FAILED=1
run_round "HPOS" "yes" || FAILED=1

echo ""
if [ "$FAILED" -eq 0 ]; then
	echo "✅ 兩輪皆通過（legacy posts + HPOS）"
else
	echo "❌ 至少一輪未通過——請看上方各輪輸出" >&2
fi

exit "$FAILED"
