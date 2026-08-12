<?php
/**
 * PartnerRepositoryInterface delete() 契約測試（Phase 3-A 缺口 2）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4 / §6
 *
 * 要求：
 *   - PartnerRepositoryInterface 必須有 delete(int $term_id): void 方法
 *   - 簽名：唯一參數型別必須為 int
 *   - 簽名：回傳型別必須為 void
 *
 * 預期紅燈：Method delete does not exist on PartnerRepositoryInterface
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Repository;

use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * PartnerRepositoryInterface::delete() 契約測試
 */
final class PartnerRepositoryDeleteContractTest extends TestCase {

	/**
	 * Interface 必須宣告 delete 方法
	 *
	 * @group happy
	 */
	public function test_partner_repository_interface_has_delete_method(): void {
		$reflection = new \ReflectionClass( PartnerRepositoryInterface::class );

		$this->assertTrue(
			$reflection->hasMethod( 'delete' ),
			'PartnerRepositoryInterface 必須宣告 delete 方法以支援 Partner 刪除流程'
		);
	}

	/**
	 * delete 方法簽名必須為 delete(int $term_id): void
	 *
	 * @group happy
	 */
	public function test_delete_method_signature(): void {
		$reflection = new \ReflectionClass( PartnerRepositoryInterface::class );
		$method     = $reflection->getMethod( 'delete' );

		// 唯一一個參數
		$params = $method->getParameters();
		$this->assertCount( 1, $params, 'delete() 應只接受一個參數（term_id）' );

		// 參數型別為 int
		$param_type = $params[0]->getType();
		$this->assertNotNull( $param_type, 'delete() 第一個參數必須宣告型別' );
		$this->assertInstanceOf( \ReflectionNamedType::class, $param_type );
		$this->assertSame( 'int', $param_type->getName(), 'delete() 第一個參數型別必須為 int' );

		// 回傳型別為 void
		$return_type = $method->getReturnType();
		$this->assertNotNull( $return_type, 'delete() 必須宣告回傳型別' );
		$this->assertInstanceOf( \ReflectionNamedType::class, $return_type );
		$this->assertSame( 'void', $return_type->getName(), 'delete() 回傳型別必須為 void' );
	}
}
