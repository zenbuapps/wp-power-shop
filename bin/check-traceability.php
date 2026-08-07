<?php
/**
 * Feature Rule ↔ 測試方法 追溯把關
 *
 * ── 這支腳本存在的理由 ──────────────────────────────────────
 *
 * `specs/features/**` 的 Rule 是行為契約，但契約與守著它的測試之間原本**零連結**
 * （`grep -rl "tests/" specs/features/profit-shop/*.feature` → 0）。
 * 沒有連結就無法回答「這條 Rule 有沒有人守」，也無法在刪測試時發現契約失去保護。
 *
 * 🔴 **驗證「指到的檔案與方法真的存在」才是這支腳本的核心價值。**
 * 只檢查「有沒有寫註記」是沒有意義的——註記會隨著測試改名而無聲漂移，
 * 而漂移後的註記比沒有註記更糟：它讓人以為有測試在守著。
 * 本專案已為「文件宣稱與實際不符」吃虧四次（TD-20 / TD-54 / TD-67 / settings wp_slash）。
 *
 * ── 註記格式 ────────────────────────────────────────────────
 *
 * 寫在 `Rule:` 那一行的**下方**（縮排不拘），一條 Rule 可有多筆：
 *
 *   Rule: 後置（可用）- 沒有任何衝突時回報可用
 *     # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_available_slug
 *     # @covered-by: MANUAL 需真實站台驗證（WooCommerce Subscriptions 是付費外掛，wp-env 裝不了）
 *     # @covered-by: NOT-COVERED TD-99 前端無測試框架
 *
 * 三種值：
 *   `<path>::<method>`  自動化測試（**檔案與方法都會被驗證存在**）
 *   `MANUAL <理由>`      結構性無法自動化，必須附理由
 *   `NOT-COVERED <理由>` 已知缺口，必須附理由（建議帶 TD 編號）
 *
 * ── 用法 ────────────────────────────────────────────────────
 *   php bin/check-traceability.php                      # 檢查 specs/features/ 全部
 *   php bin/check-traceability.php specs/features/profit-shop/login-partner.feature
 *   php bin/check-traceability.php --summary            # 只印統計
 *   php bin/check-traceability.php --generate           # 產生 specs/traceability.md
 *   php bin/check-traceability.php --require-full       # 缺註記即 exit 1（B7 完工後才開）
 *
 * exit code：0 = 通過；1 = 有**無效**註記（指到不存在的檔案／方法）或 --require-full 下有缺口
 *
 * @package J7\PowerShop
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions

const REPO_ROOT       = __DIR__ . '/..';
const DEFAULT_FEATURE = 'specs/features';
const MARKER          = '@covered-by:';

/**
 * 解析單一 .feature 檔，回傳每條 Rule 與其註記
 *
 * @param string $path feature 檔絕對路徑
 *
 * @return array<int, array{line:int, title:string, notes:string[]}>
 */
function parse_feature( string $path ): array {
	$lines = file( $path, FILE_IGNORE_NEW_LINES );
	if ( false === $lines ) {
		return [];
	}

	$rules   = [];
	$current = null;

	foreach ( $lines as $i => $line ) {
		$trimmed = trim( $line );

		if ( 1 === preg_match( '/^Rule:\s*(.+)$/u', $trimmed, $m ) ) {
			if ( null !== $current ) {
				$rules[] = $current;
			}
			$current = [
				'line'  => $i + 1,
				'title' => trim( $m[1] ),
				'notes' => [],
			];
			continue;
		}

		// 註記只在某條 Rule 之下才有意義
		if ( null !== $current && false !== strpos( $trimmed, MARKER ) ) {
			$value = trim( substr( $trimmed, strpos( $trimmed, MARKER ) + strlen( MARKER ) ) );
			if ( '' !== $value ) {
				$current['notes'][] = $value;
			}
		}
	}

	if ( null !== $current ) {
		$rules[] = $current;
	}

	return $rules;
}

/**
 * 驗證單筆註記
 *
 * @param string $note 註記內容
 *
 * @return array{kind:string, ok:bool, reason:string}
 */
function validate_note( string $note ): array {
	if ( 0 === strpos( $note, 'MANUAL' ) ) {
		$reason = trim( substr( $note, strlen( 'MANUAL' ) ) );
		return [
			'kind'   => 'MANUAL',
			'ok'     => '' !== $reason,
			'reason' => '' !== $reason ? $reason : 'MANUAL 必須附理由',
		];
	}

	if ( 0 === strpos( $note, 'NOT-COVERED' ) ) {
		$reason = trim( substr( $note, strlen( 'NOT-COVERED' ) ) );
		return [
			'kind'   => 'NOT-COVERED',
			'ok'     => '' !== $reason,
			'reason' => '' !== $reason ? $reason : 'NOT-COVERED 必須附理由',
		];
	}

	// PHP：方法名受識別字規則約束，可用 `function name(` 精準比對。
	// TS/JS（vitest / Playwright）：指標是 `it()` / `test()` 的**標題字串**，
	// 可以含中文、空白、標點，因此不能套識別字正則——改以「標題是否出現在檔內」驗證。
	if ( 1 !== preg_match( '/^([^\s:]+\.(php|ts|tsx|js))::(.+)$/u', $note, $m ) ) {
		return [
			'kind'   => 'INVALID',
			'ok'     => false,
			'reason' => '格式不合：應為 <path>::<method 或測試標題> 或 MANUAL/NOT-COVERED + 理由',
		];
	}

	[ , $rel, $ext, $target ] = $m;
	$abs                      = REPO_ROOT . '/' . $rel;

	if ( ! is_file( $abs ) ) {
		return [
			'kind'   => 'AUTO',
			'ok'     => false,
			'reason' => "檔案不存在：{$rel}",
		];
	}

	$src = (string) file_get_contents( $abs );

	if ( 'php' === $ext ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $target ) ) {
			return [
				'kind'   => 'AUTO',
				'ok'     => false,
				'reason' => "PHP 指標必須是合法方法名：{$target}",
			];
		}
		$found = 1 === preg_match( '/function\s+' . preg_quote( $target, '/' ) . '\s*\(/', $src );
		return [
			'kind'   => 'AUTO',
			'ok'     => $found,
			'reason' => $found ? '' : "方法不存在：{$rel}::{$target}",
		];
	}

	// TS/JS：標題必須逐字出現在檔內（改了測試標題就會紅，正是我們要的成本）
	$found = false !== strpos( $src, $target );

	return [
		'kind'   => 'AUTO',
		'ok'     => $found,
		'reason' => $found ? '' : "測試標題不存在：{$rel}::{$target}",
	];
}

/**
 * 蒐集要檢查的 feature 檔
 *
 * @param string[] $args CLI 參數（已過濾旗標）
 *
 * @return string[]
 */
function collect_features( array $args ): array {
	$targets = [] !== $args ? $args : [ DEFAULT_FEATURE ];
	$files   = [];

	foreach ( $targets as $t ) {
		$abs = ( 0 === strpos( $t, '/' ) || 1 === preg_match( '/^[A-Za-z]:/', $t ) )
		? $t
		: REPO_ROOT . '/' . $t;

		if ( is_file( $abs ) ) {
			$files[] = $abs;
			continue;
		}
		if ( is_dir( $abs ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $abs ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() && 'feature' === $f->getExtension() ) {
					$files[] = $f->getPathname();
				}
			}
		}
	}

	sort( $files );
	return $files;
}

// ────────────────────────────── main ──────────────────────────────

$argv_in      = array_slice( $argv, 1 );
$summary_only = in_array( '--summary', $argv_in, true );
$generate     = in_array( '--generate', $argv_in, true );
$require_full = in_array( '--require-full', $argv_in, true );
$paths        = array_values(
	array_filter( $argv_in, static fn( string $a ): bool => 0 !== strpos( $a, '--' ) )
);

$features = collect_features( $paths );
if ( [] === $features ) {
	fwrite( STDERR, "找不到任何 .feature 檔\n" );
	exit( 1 );
}

$total_rules   = 0;
$total_noted   = 0;
$invalid       = [];
$uncovered     = [];
$manual        = [];
$not_covered   = [];
$per_feature   = [];

$parser_broken = [];

foreach ( $features as $feature_path ) {
	$rel   = str_replace( '\\', '/', substr( $feature_path, strlen( realpath( REPO_ROOT ) ) + 1 ) );
	$rules = parse_feature( $feature_path );

	// 🔴 解析出 0 條 Rule 是 **parser 壞掉的指紋**，不是「這個檔案很乾淨」。
	// 少了這道守門，`Rule:` 的比對樣式一旦被改壞（或 Gherkin 排版變動），
	// 全部檔案都會回報「0 條 Rule、0 條未標註」→ 統計顯示 100% 通過、exit 0，
	// 而實際上**一條都沒檢查**。這正是本專案已吃虧四次的那種失效模式：
	// 閘門看起來是綠的，只是它根本沒在看。
	if ( [] === $rules ) {
		$parser_broken[] = $rel;
	}

	$noted = 0;
	foreach ( $rules as $rule ) {
		++$total_rules;

		if ( [] === $rule['notes'] ) {
			$uncovered[] = [ $rel, $rule['line'], $rule['title'] ];
			continue;
		}

		++$noted;
		++$total_noted;

		foreach ( $rule['notes'] as $note ) {
			$v = validate_note( $note );
			if ( ! $v['ok'] ) {
				$invalid[] = [ $rel, $rule['line'], $rule['title'], $note, $v['reason'] ];
				continue;
			}
			if ( 'MANUAL' === $v['kind'] ) {
				$manual[] = [ $rel, $rule['title'], $v['reason'] ];
			} elseif ( 'NOT-COVERED' === $v['kind'] ) {
				$not_covered[] = [ $rel, $rule['title'], $v['reason'] ];
			}
		}
	}

	$per_feature[ $rel ] = [
		'rules' => count( $rules ),
		'noted' => $noted,
	];
}

if ( ! $summary_only ) {
	if ( [] !== $invalid ) {
		echo "\n🔴 無效註記（指到不存在的檔案／方法，或格式不合）——**這是必須修的**\n";
		echo str_repeat( '─', 78 ) . "\n";
		foreach ( $invalid as [ $f, $line, $rule_title, $note, $reason ] ) {
			printf( "  %s:%d\n    Rule: %s\n    註記: %s\n    問題: %s\n\n", $f, $line, $rule_title, $note, $reason );
		}
	}

	if ( [] !== $uncovered ) {
		echo "\n⚠️  尚未標註的 Rule（" . count( $uncovered ) . " 條）\n";
		echo str_repeat( '─', 78 ) . "\n";
		$by_file = [];
		foreach ( $uncovered as [ $f, $line, $rule_title ] ) {
			$by_file[ $f ][] = sprintf( '    L%-5d %s', $line, $rule_title );
		}
		foreach ( $by_file as $f => $items ) {
			printf( "  %s（%d 條）\n%s\n\n", $f, count( $items ), implode( "\n", $items ) );
		}
	}
}

if ( [] !== $parser_broken ) {
	fwrite( STDERR, "\n🔴 下列 feature 檔解析出 0 條 Rule——這是 **parser 壞掉的指紋**，不是檔案很乾淨：\n" );
	foreach ( $parser_broken as $rel ) {
		fwrite( STDERR, "  {$rel}\n" );
	}
	fwrite( STDERR, "  （若該檔真的沒有 Rule，請先確認 Gherkin 排版；本檢查刻意不允許靜默通過）\n" );
}

printf( "\n%s\n", str_repeat( '═', 78 ) );
printf( "  Feature 檔：%d ｜ Rule 總數：%d ｜ 已標註：%d ｜ 未標註：%d\n", count( $features ), $total_rules, $total_noted, count( $uncovered ) );
printf( "  無效註記：%d ｜ MANUAL：%d ｜ NOT-COVERED：%d\n", count( $invalid ), count( $manual ), count( $not_covered ) );
if ( [] !== $parser_broken ) {
	printf( "  🔴 解析出 0 條 Rule 的檔案：%d\n", count( $parser_broken ) );
}
printf( "%s\n", str_repeat( '═', 78 ) );

if ( $generate ) {
	$md   = [];
	$md[] = '<!-- GENERATED by bin/check-traceability.php — 請勿手動編輯 -->';
	$md[] = '<!-- 唯一真相是 .feature 檔內的 `# @covered-by:` 註記；本檔只是給人看的總覽 -->';
	$md[] = '';
	$md[] = '# Feature Rule 追溯矩陣';
	$md[] = '';
	$md[] = sprintf(
		'Rule 總數 **%d** ｜ 已標註 **%d** ｜ 未標註 **%d** ｜ MANUAL **%d** ｜ NOT-COVERED **%d**',
		$total_rules,
		$total_noted,
		count( $uncovered ),
		count( $manual ),
		count( $not_covered )
	);
	$md[] = '';
	$md[] = '## 逐檔覆蓋率';
	$md[] = '';
	$md[] = '| Feature | Rule | 已標註 | 覆蓋率 |';
	$md[] = '|---|---:|---:|---:|';
	foreach ( $per_feature as $f => $stat ) {
		$pct  = $stat['rules'] > 0 ? round( $stat['noted'] / $stat['rules'] * 100 ) : 100;
		$md[] = sprintf( '| `%s` | %d | %d | %d%% |', basename( $f, '.feature' ), $stat['rules'], $stat['noted'], $pct );
	}

	if ( [] !== $manual ) {
		$md[] = '';
		$md[] = '## 標為 MANUAL 的 Rule（結構性無法自動化）';
		$md[] = '';
		foreach ( $manual as [ $f, $rule_title, $reason ] ) {
			$md[] = sprintf( '- `%s` — %s' . "\n" . '  - 理由：%s', basename( $f, '.feature' ), $rule_title, $reason );
		}
	}

	if ( [] !== $not_covered ) {
		$md[] = '';
		$md[] = '## 標為 NOT-COVERED 的 Rule（已知缺口）';
		$md[] = '';
		foreach ( $not_covered as [ $f, $rule_title, $reason ] ) {
			$md[] = sprintf( '- `%s` — %s' . "\n" . '  - 理由：%s', basename( $f, '.feature' ), $rule_title, $reason );
		}
	}

	$md[] = '';
	file_put_contents( REPO_ROOT . '/specs/traceability.md', implode( "\n", $md ) . "\n" );
	echo "\n已產生 specs/traceability.md\n";
}

// parser 壞掉時一律失敗——這比「有無效註記」更嚴重，因為它代表整份檢查都沒在看
if ( [] !== $parser_broken ) {
	exit( 1 );
}
if ( [] !== $invalid ) {
	exit( 1 );
}
if ( $require_full && [] !== $uncovered ) {
	exit( 1 );
}
exit( 0 );
