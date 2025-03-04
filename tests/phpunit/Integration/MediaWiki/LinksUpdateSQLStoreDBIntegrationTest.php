<?php

namespace SMW\Tests\Integration\MediaWiki;

use LinksUpdate;
use ParserOutput;
use Revision;
use SMW\Services\ServicesFactory;
use SMW\DIWikiPage;
use SMW\ParserData;
use SMW\Tests\MwDBaseUnitTestCase;
use SMW\Tests\Utils\PageCreator;
use Title;
use UnexpectedValueException;
use User;
use WikiPage;

/**
 * @group semantic-mediawiki
 * @group medium
 *
 * @license GNU GPL v2+
 * @since 1.9.1
 *
 * @author mwjames
 */
class LinksUpdateSQLStoreDBIntegrationTest extends MwDBaseUnitTestCase {

	protected $destroyDatabaseTablesBeforeRun = true;

	private $title = null;
	private $mwHooksHandler;
	private $semanticDataValidator;
	private $pageDeleter;

	protected function setUp() : void {
		parent::setUp();

		$this->testEnvironment->addConfiguration(
			'smwgPageSpecialProperties',
			 [ '_MDAT' ]
		);

		$this->mwHooksHandler = $this->testEnvironment->getUtilityFactory()->newMwHooksHandler();

		$this->mwHooksHandler->deregisterListedHooks();
		$this->mwHooksHandler->invokeHooksFromRegistry();

		$this->semanticDataValidator = $this->testEnvironment->getUtilityFactory()->newValidatorFactory()->newSemanticDataValidator();
		$this->pageDeleter = $this->testEnvironment->getUtilityFactory()->newPageDeleter();
	}

	public function tearDown() : void {

		$this->mwHooksHandler->restoreListedHooks();

		if ( $this->title !== null ) {
			$this->pageDeleter->deletePage( $this->title );
		}

		parent::tearDown();
	}

	public function testPageCreationAndRevisionHandlingBeforeLinksUpdate() {

		$this->title = Title::newFromText( __METHOD__ );

		$beforeAlterationRevId = $this->createSinglePageWithAnnotations();
		$this->assertSemanticDataBeforeContentAlteration();

		$afterAlterationRevId = $this->alterPageContentToCreateNewRevisionWithoutAnnotations();
		$this->assertSemanticDataAfterContentAlteration();

		$this->assertNotSame(
			$beforeAlterationRevId,
			$afterAlterationRevId
		);
	}

	/**
	 * @depends testPageCreationAndRevisionHandlingBeforeLinksUpdate
	 * @dataProvider propertyCountProvider
	 */
	public function testLinksUpdateAndVerifyStoreUpdate( $expected ) {

		$this->title = Title::newFromText( __METHOD__ );

		$beforeAlterationRevId = $this->createSinglePageWithAnnotations();
		$afterAlterationRevId  = $this->alterPageContentToCreateNewRevisionWithoutAnnotations();

		$this->testEnvironment->executePendingDeferredUpdates();

		$this->fetchRevisionAndRunLinksUpdater(
			$expected['beforeAlterationRevId'],
			$beforeAlterationRevId
		);

		$this->fetchRevisionAndRunLinksUpdater(
			$expected['afterAlterationRevId'],
			$afterAlterationRevId
		);
	}

	protected function createSinglePageWithAnnotations() {
		$pageCreator = new PageCreator();
		$pageCreator->createPage( $this->title )->doEdit( 'Add user property Aa and Fuyu {{#set:|Aa=Bb|Fuyu=Natsu}}' );

		$this->testEnvironment->executePendingDeferredUpdates();

		return $pageCreator->getPage()->getRevision()->getId();
	}

	protected function alterPageContentToCreateNewRevisionWithoutAnnotations() {
		$pageCreator = new PageCreator();
		$pageCreator->createPage( $this->title )->doEdit( 'No annotations' );

		$this->testEnvironment->executePendingDeferredUpdates();

		return $pageCreator->getPage()->getRevision()->getId();
	}

	protected function assertSemanticDataBeforeContentAlteration() {

		$wikiPage = WikiPage::factory( $this->title );
		$revision = $wikiPage->getRevision();

		$parserData = $this->retrieveAndLoadData();
		$this->assertCount( 3, $parserData->getSemanticData()->getProperties() );

		$this->assertEquals(
			$parserData->getSemanticData()->getHash(),
			$this->retrieveAndLoadData( $revision->getId() )->getSemanticData()->getHash(),
			'Asserts that data are equals with or without a revision'
		);

		$this->semanticDataValidator->assertThatSemanticDataHasPropertyCountOf(
			4,
			$this->getStore()->getSemanticData( DIWikiPage::newFromTitle( $this->title ) ),
			'Asserts property Aa, Fuyu, _SKEY, and _MDAT exists'
		);
	}

	protected function assertSemanticDataAfterContentAlteration() {
		$this->semanticDataValidator->assertThatSemanticDataHasPropertyCountOf(
			2,
			$this->getStore()->getSemanticData( DIWikiPage::newFromTitle( $this->title ) ),
			'Asserts property _SKEY and _MDAT exists'
		);
	}

	protected function fetchRevisionAndRunLinksUpdater( array $expected, $revId ) {

		$parserData = $this->retrieveAndLoadData( $revId );

		// Status before the update
		$this->assertPropertyCount( $expected['poBefore'], $expected['storeBefore'], $parserData );

		$this->runLinksUpdater( $this->title, $parserData->getOutput() );

		// Status after the update
		$this->assertPropertyCount( $expected['poAfter'], $expected['storeAfter'], $parserData );
	}

	protected function assertPropertyCount( $poExpected, $storeExpected, $parserData ) {
		$this->semanticDataValidator->assertThatSemanticDataHasPropertyCountOf(
			$poExpected['count'],
			$parserData->getSemanticData(),
			$poExpected['msg']
		);

		$this->semanticDataValidator->assertThatSemanticDataHasPropertyCountOf(
			$storeExpected['count'],
			$this->getStore()->getSemanticData( DIWikiPage::newFromTitle( $this->title ) ),
			$storeExpected['msg']
		);
	}

	protected function runLinksUpdater( Title $title, $parserOutput ) {
		$linksUpdate = new LinksUpdate( $title, $parserOutput );
		$linksUpdate->doUpdate();

		$this->testEnvironment->executePendingDeferredUpdates();
	}

	protected function retrieveAndLoadData( $revId = null ) {

		$revision = $revId ? Revision::newFromId( $revId ) : null;

		$contentParser = ServicesFactory::getInstance()->newContentParser(
			$this->title
		);

		$contentParser->setRevision( $revision );

		$parserOutput =	$contentParser->parse()->getOutput();
		$parserOutput->setExtensionData( ParserData::OPT_FORCED_UPDATE, true );

		if ( $parserOutput instanceof ParserOutput ) {
			return new ParserData( $this->title, $parserOutput );
		}

		throw new UnexpectedValueException( 'ParserOutput is missing' );
	}

	public function propertyCountProvider() {

		// Property _SKEY is always present even within an empty container
		// po = ParserOutput, before means prior LinksUpdate

		$provider = [];

		$provider[] = [ [
			'beforeAlterationRevId' => [
				'poBefore'  => [
					'count' => 3,
					'msg'   => 'Asserts property Aa, Fuyu, and _SKEY exists before the update'
				],
				'storeBefore' => [
					'count'   => 2,
					'msg'     => 'Asserts property _SKEY and _MDAT exists in Store before the update'
				],
				'poAfter'    => [
					'count'  => 4,
					'msg'    => 'Asserts property Aa, Fuyu, _SKEY, and _MDAT exists after the update'
				],
				'storeAfter' => [
					'count'  => 4,
					'msg'    => 'Asserts property Aa, Fuyu, _SKEY, and _MDAT exists after the update'
				]
			],
			'afterAlterationRevId' => [
				'poBefore'  => [
					'count' => 0,
					'msg'   => 'Asserts no property exists before the update'
				],
				'storeBefore' => [
					'count'   => 4,
					'msg'     => 'Asserts property Aa, Fuyu, _SKEY, and _MDAT from the previous state as no update has been made yet'
				],
				'poAfter'    => [
					'count'  => 0,
					'msg'    => 'Asserts property _MDAT exists after the update'
				],
				'storeAfter' => [
					'count'  => 2,
					'msg'    => 'Asserts property _SKEY, _MDAT exists after the update'
				]
			]
		] ];

		return $provider;
	}

}
