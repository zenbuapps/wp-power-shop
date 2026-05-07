<?php
/**
 * 固定時鐘（測試替身）
 *
 * 用於驗證 token TTL、brute-force 視窗等時間相關邏輯。
 * 非真 PSR-20 ClockInterface（避免測試依賴 PSR 套件），但介面相容易遷移。
 */

declare(strict_types=1);

namespace Tests\Support;

/**
 * 可手動推進的測試時鐘
 *
 * 用法：
 *   $clock = new FixedClock( 1_000_000_000 );
 *   $clock->advance( 3600 );           // 推進一小時
 *   $clock->set_to( 1_000_900_000 );   // 跳到指定時間
 *   $clock->now();                      // 取得當前 timestamp
 */
final class FixedClock {

	/**
	 * 目前時間（unix timestamp）
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * 建構子
	 *
	 * @param int $initial_timestamp 起始 timestamp（預設 2001-09-09，方便計算）
	 */
	public function __construct( int $initial_timestamp = 1_000_000_000 ) {
		$this->now = $initial_timestamp;
	}

	/**
	 * 取得目前 timestamp
	 */
	public function now(): int {
		return $this->now;
	}

	/**
	 * 推進指定秒數
	 *
	 * @param int $seconds 正整數秒數（傳入負值 = 倒退）
	 */
	public function advance( int $seconds ): void {
		$this->now += $seconds;
	}

	/**
	 * 跳到指定時間
	 *
	 * @param int $timestamp 目標 timestamp
	 */
	public function set_to( int $timestamp ): void {
		$this->now = $timestamp;
	}
}
