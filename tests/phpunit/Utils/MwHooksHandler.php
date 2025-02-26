<?php

namespace SMW\Tests\Utils;

use RuntimeException;
use SMW\MediaWiki\Hooks;

/**
 * @license GNU GPL v2+
 * @since   1.9
 *
 * @author mwjames
 */
class MwHooksHandler {

	/**
	 * @var HookRegistry
	 */
	private $hookRegistry = null;

	private $wgHooks = [];
	private $inTestRegisteredHooks = [];

	private $listOfSmwHooks = [
		'SMWStore::updateDataBefore',
		'SMW::Store::BeforeDataUpdateComplete',
		'SMWStore::updateDataAfter',
		'SMW::Store::AfterDataUpdateComplete',

		// Those shoudl not be disabled so that extension used
		// by a test will run the registration in case an instance
		// is cleared
		//	'smwInitDatatypes',
		//	'SMW::DataType::initTypes',

		'SMW::Settings::BeforeInitializationComplete',
		'SMW::Setup::AfterInitializationComplete',
		'SMW::GroupPermissions::BeforeInitializationComplete',

		'smwInitProperties',
		'SMW::Property::initProperties',
		'SMW::Constraint::initConstraints',
		'SMW::Factbox::BeforeContentGeneration',
		'SMW::SQLStore::updatePropertyTableDefinitions',
		'SMW::Store::BeforeQueryResultLookupComplete',
		'SMW::Store::AfterQueryResultLookupComplete',
		'SMW::SQLStore::BeforeChangeTitleComplete',
		'SMW::SQLStore::BeforeDeleteSubjectComplete',
		'SMW::SQLStore::AfterDeleteSubjectComplete',
		'SMW::Parser::BeforeMagicWordsFinder',
		'SMW::SQLStore::BeforeDataRebuildJobInsert',
		'SMW::SQLStore::AddCustomFixedPropertyTables',
		'SMW::SQLStore::AfterDataUpdateComplete',
		'SMW::Browse::AfterIncomingPropertiesLookupComplete',
		'SMW::Browse::BeforeIncomingPropertyValuesFurtherLinkCreate',

		'SMW::Listener::ChangeListener::RegisterPropertyChangeListeners',
		'SMW::Admin::RegisterTaskHandlers',
		'SMW::Schema::RegisterSchemaTypes',

		'SMW::GetPreferences',
		'SMW::Parser::AfterLinksProcessingComplete',
		'SMW::Parser::ParserAfterTidyPropertyAnnotationComplete',

		'SMW::Maintenance::AfterUpdateEntityCollationComplete',

		'SMW::Indicator::EntityExaminer::RegisterIndicatorProviders',
		'SMW::Indicator::EntityExaminer::RegisterDeferrableIndicatorProviders',

		'SMW::RevisionGuard::IsApprovedRevision',
		'SMW::RevisionGuard::ChangeRevisionID',
		'SMW::RevisionGuard::ChangeFile',
		'SMW::RevisionGuard::ChangeRevision',

		'SMWSQLStore3::updateDataBefore',
		'SMW::SQLStore::BeforeDataUpdateComplete',

		'SMW::SQLStore::Installer::BeforeCreateTablesComplete',
		'SMW::SQLStore::Installer::AfterCreateTablesComplete',
		'SMW::SQLStore::Installer::AfterDropTablesComplete'
	];

	/**
	 * @since  2.0
	 *
	 * @return MwHooksHandler
	 */
	public function deregisterListedHooks() {

		$listOfHooks = array_merge(
			$this->listOfSmwHooks,
			$this->getHookRegistry()->getHandlerList()
		);

		foreach ( $listOfHooks as $hook ) {

			// MW 1.19
			if ( method_exists( 'Hooks', 'clear' ) ) {
				\Hooks::clear( $hook );
			}

			if ( !isset( $GLOBALS['wgHooks'][$hook] ) ) {
				continue;
			}

			$this->wgHooks[$hook] = $GLOBALS['wgHooks'][$hook];
			$GLOBALS['wgHooks'][$hook] = [];
		}

		return $this;
	}

	/**
	 * @since  2.0
	 *
	 * @return MwHooksHandler
	 */
	public function restoreListedHooks() {

		foreach ( $this->inTestRegisteredHooks as $hook ) {
			unset( $GLOBALS['wgHooks'][$hook] );
		}

		foreach ( $this->wgHooks as $hook => $definition ) {
			$GLOBALS['wgHooks'][$hook] = $definition;
			unset( $this->wgHooks[$hook] );
		}

		return $this;
	}

	/**
	 * @since  2.1
	 *
	 * @return MwHooksHandler
	 */
	public function register( $name, callable $callback ) {

		$listOfHooks = array_merge(
			$this->listOfSmwHooks,
			$this->getHookRegistry()->getHandlerList()
		);

		if ( !in_array( $name, $listOfHooks ) ) {
			throw new RuntimeException( "$name is not listed as registrable hook" );
		}

		$this->inTestRegisteredHooks[] = $name;
		$GLOBALS['wgHooks'][$name][] = $callback;

		return $this;
	}

	/**
	 * @since  2.1
	 *
	 * @return MwHooksHandler
	 */
	public function invokeHooksFromRegistry() {
		$this->getHookRegistry()->register( $GLOBALS );
		return $this;
	}

	/**
	 * @since  2.1
	 *
	 * @return HookRegistry
	 */
	public function getHookRegistry() {

		if ( $this->hookRegistry === null ) {
			 $this->hookRegistry = new Hooks( '' );
		}

		return $this->hookRegistry;
	}

}
