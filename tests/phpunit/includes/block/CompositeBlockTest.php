<?php

use MediaWiki\Block\BlockRestrictionStore;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\Block\Restriction\NamespaceRestriction;
use MediaWiki\Block\SystemBlock;
use MediaWiki\MediaWikiServices;

/**
 * @group Database
 * @group Blocking
 * @coversDefaultClass \MediaWiki\Block\CompositeBlock
 */
class CompositeBlockTest extends MediaWikiLangTestCase {
	private function getPartialBlocks() {
		$sysopId = $this->getTestSysop()->getUser()->getId();

		$userBlock = new Block( [
			'address' => $this->getTestUser()->getUser(),
			'by' => $sysopId,
			'sitewide' => false,
		] );
		$ipBlock = new Block( [
			'address' => '127.0.0.1',
			'by' => $sysopId,
			'sitewide' => false,
		] );

		$userBlock->insert();
		$ipBlock->insert();

		return [
			'user' => $userBlock,
			'ip' => $ipBlock,
		];
	}

	private function deleteBlocks( $blocks ) {
		foreach ( $blocks as $block ) {
			$block->delete();
		}
	}

	/**
	 * @covers ::__construct
	 * @dataProvider provideTestStrictestParametersApplied
	 */
	public function testStrictestParametersApplied( $blocks, $expected ) {
		$this->setMwGlobals( [
			'wgBlockDisablesLogin' => false,
			'wgBlockAllowsUTEdit' => true,
		] );

		$block = new CompositeBlock( [
			'originalBlocks' => $blocks,
		] );

		$this->assertSame( $expected[ 'hideName' ], $block->getHideName() );
		$this->assertSame( $expected[ 'sitewide' ], $block->isSitewide() );
		$this->assertSame( $expected[ 'blockEmail' ], $block->isEmailBlocked() );
		$this->assertSame( $expected[ 'allowUsertalk' ], $block->isUsertalkEditAllowed() );
	}

	public static function provideTestStrictestParametersApplied() {
		return [
			'Sitewide block and partial block' => [
				[
					new Block( [
						'sitewide' => false,
						'blockEmail' => true,
						'allowUsertalk' => true,
					] ),
					new Block( [
						'sitewide' => true,
						'blockEmail' => false,
						'allowUsertalk' => false,
					] ),
				],
				[
					'hideName' => false,
					'sitewide' => true,
					'blockEmail' => true,
					'allowUsertalk' => false,
				],
			],
			'Partial block and system block' => [
				[
					new Block( [
						'sitewide' => false,
						'blockEmail' => true,
						'allowUsertalk' => false,
					] ),
					new SystemBlock( [
						'systemBlock' => 'proxy',
					] ),
				],
				[
					'hideName' => false,
					'sitewide' => true,
					'blockEmail' => true,
					'allowUsertalk' => false,
				],
			],
			'System block and user name hiding block' => [
				[
					new Block( [
						'hideName' => true,
						'sitewide' => true,
						'blockEmail' => true,
						'allowUsertalk' => false,
					] ),
					new SystemBlock( [
						'systemBlock' => 'proxy',
					] ),
				],
				[
					'hideName' => true,
					'sitewide' => true,
					'blockEmail' => true,
					'allowUsertalk' => false,
				],
			],
			'Two lenient partial blocks' => [
				[
					new Block( [
						'sitewide' => false,
						'blockEmail' => false,
						'allowUsertalk' => true,
					] ),
					new Block( [
						'sitewide' => false,
						'blockEmail' => false,
						'allowUsertalk' => true,
					] ),
				],
				[
					'hideName' => false,
					'sitewide' => false,
					'blockEmail' => false,
					'allowUsertalk' => true,
				],
			],
		];
	}

	/**
	 * @covers ::appliesToTitle
	 */
	public function testBlockAppliesToTitle() {
		$this->setMwGlobals( [
			'wgBlockDisablesLogin' => false,
		] );

		$blocks = $this->getPartialBlocks();

		$block = new CompositeBlock( [
			'originalBlocks' => $blocks,
		] );

		$pageFoo = $this->getExistingTestPage( 'Foo' );
		$pageBar = $this->getExistingTestPage( 'User:Bar' );

		$this->getBlockRestrictionStore()->insert( [
			new PageRestriction( $blocks[ 'user' ]->getId(), $pageFoo->getId() ),
			new NamespaceRestriction( $blocks[ 'ip' ]->getId(), NS_USER ),
		] );

		$this->assertTrue( $block->appliesToTitle( $pageFoo->getTitle() ) );
		$this->assertTrue( $block->appliesToTitle( $pageBar->getTitle() ) );

		$this->deleteBlocks( $blocks );
	}

	/**
	 * @covers ::appliesToUsertalk
	 * @covers ::appliesToPage
	 * @covers ::appliesToNamespace
	 */
	public function testBlockAppliesToUsertalk() {
		$this->setMwGlobals( [
			'wgBlockAllowsUTEdit' => true,
			'wgBlockDisablesLogin' => false,
		] );

		$blocks = $this->getPartialBlocks();

		$block = new CompositeBlock( [
			'originalBlocks' => $blocks,
		] );

		$title = $blocks[ 'user' ]->getTarget()->getTalkPage();
		$page = $this->getExistingTestPage( 'User talk:' . $title->getText() );

		$this->getBlockRestrictionStore()->insert( [
			new PageRestriction( $blocks[ 'user' ]->getId(), $page->getId() ),
			new NamespaceRestriction( $blocks[ 'ip' ]->getId(), NS_USER ),
		] );

		$this->assertTrue( $block->appliesToUsertalk( $blocks[ 'user' ]->getTarget()->getTalkPage() ) );

		$this->deleteBlocks( $blocks );
	}

	/**
	 * @covers ::appliesToRight
	 * @dataProvider provideTestBlockAppliesToRight
	 */
	public function testBlockAppliesToRight( $blocks, $right, $expected ) {
		$this->setMwGlobals( [
			'wgBlockDisablesLogin' => false,
		] );

		$block = new CompositeBlock( [
			'originalBlocks' => $blocks,
		] );

		$this->assertSame( $block->appliesToRight( $right ), $expected );
	}

	public static function provideTestBlockAppliesToRight() {
		return [
			'Read is not blocked' => [
				[
					new Block(),
					new Block(),
				],
				'read',
				false,
			],
			'Email is blocked if blocked by any blocks' => [
				[
					new Block( [
						'blockEmail' => true,
					] ),
					new Block( [
						'blockEmail' => false,
					] ),
				],
				'sendemail',
				true,
			],
		];
	}

	/**
	 * @covers ::getPermissionsError
	 * @dataProvider provideGetPermissionsError
	 */
	public function testGetPermissionsError( $ids, $expectedIdsMsg ) {
		// Some block options
		$timestamp = time();
		$target = '1.2.3.4';
		$byText = 'MediaWiki default';
		$formattedByText = "\u{202A}{$byText}\u{202C}";
		$reason = '';
		$expiry = 'infinite';

		$block = $this->getMockBuilder( CompositeBlock::class )
			->setMethods( [ 'getIds', 'getBlockErrorParams' ] )
			->getMock();
		$block->method( 'getIds' )
			->willReturn( $ids );
		$block->method( 'getBlockErrorParams' )
			->willReturn( [
				$formattedByText,
				$reason,
				$target,
				$formattedByText,
				null,
				$timestamp,
				$target,
				$expiry,
			] );

		$this->assertSame( [
			'blockedtext-composite',
			$formattedByText,
			$reason,
			$target,
			$formattedByText,
			$expectedIdsMsg,
			$timestamp,
			$target,
			$expiry,
		], $block->getPermissionsError( RequestContext::getMain() ) );
	}

	public static function provideGetPermissionsError() {
		return [
			'All original blocks are system blocks' => [
				[],
				'Your IP address appears in multiple blacklists',
			],
			'One original block is a database block' => [
				[ 100 ],
				'Relevant block IDs: #100 (your IP address may also be blacklisted)',
			],
			'Several original blocks are database blocks' => [
				[ 100, 101, 102 ],
				'Relevant block IDs: #100, #101, #102 (your IP address may also be blacklisted)',
			],
		];
	}

	/**
	 * Get an instance of BlockRestrictionStore
	 *
	 * @return BlockRestrictionStore
	 */
	protected function getBlockRestrictionStore() : BlockRestrictionStore {
		return MediaWikiServices::getInstance()->getBlockRestrictionStore();
	}
}
