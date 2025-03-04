<?php

namespace SMW\Query;

use SMW\DIWikiPage;
use SMW\Query\Excerpts;
use SMW\Query\PrintRequest;
use SMW\Query\QueryLinker;
use SMW\Query\Result\ItemJournal;
use SMW\Query\Result\FieldItemFinder;
use SMW\Query\Result\ItemFetcher;
use SMW\Query\Result\ResultArray;
use SMW\Query\Result\FilterMap;
use SMW\Query\ScoreSet;
use SMW\SerializerFactory;
use SMW\Store;
use SMWInfolink;
use SMWQuery as Query;

/**
 * Objects of this class encapsulate the result of a query in SMW. They
 * provide access to the query result and printed data, and to some
 * relevant query parameters that were used.
 *
 * Standard access is provided through the iterator function getNext(),
 * which returns an array ("table row") of ResultArray objects ("table cells").
 * It is also possible to access the set of result pages directly using
 * getResults(). This is useful for printers that disregard printouts and
 * only are interested in the actual list of pages.
 *
 * @license GNU GPL v2+
 * @since 2.5
 *
 * @author Markus Krötzsch
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class QueryResult {

	/**
	 * When generating a hash just iterates over available subjects, not
	 * the entire object structure.
	 */
	const QUICK_HASH = 'quick';

	/**
	 * Array of DIWikiPage objects that are the basis for this result
	 * @var DIWikiPage[]
	 */
	protected $mResults;

	/**
	 * Array of SMWPrintRequest objects, indexed by their natural hash keys
	 *
	 * @var PrintRequest[]
	 */
	protected $mPrintRequests;

	/**
	 * Are there more results than the ones given?
	 * @var boolean
	 */
	protected $mFurtherResults;

	/**
	 * The query object for which this is a result, must be set on create and is the source of
	 * data needed to create further result links.
	 * @var Query
	 */
	protected $mQuery;

	/**
	 * The Store object used to retrieve further data on demand.
	 * @var Store
	 */
	protected $mStore;

	/**
	 * Holds a value that belongs to a count query result
	 * @var integer|null
	 */
	private $countValue;

	/**
	 * Indicates whether results have been retrieved from cache or not
	 *
	 * @var boolean
	 */
	private $isFromCache = false;

	/**
	 * @var ItemJournal
	 */
	private $itemJournal;

	/**
	 * @var FieldItemFinder
	 */
	private $fieldItemFinder;

	/**
	 * @var integer
	 */
	private $serializer_version = 2;

	/**
	 * @var ScoreSet
	 */
	private $scoreSet;

	/**
	 * @var Excerpts
	 */
	private $excerpts;

	/**
	 * @var FilterMap
	 */
	private $filterMap;

	/**
	 * @param PrintRequest[] $printRequests
	 * @param Query $query
	 * @param DIWikiPage[] $results
	 * @param Store $store
	 * @param boolean $furtherRes
	 */
	public function __construct( array $printRequests, Query $query, array $results, Store $store, $furtherRes = false ) {
		$this->mResults = $results;
		reset( $this->mResults );
		$this->mPrintRequests = $printRequests;
		$this->mFurtherResults = $furtherRes;
		$this->mQuery = $query;
		$this->mStore = $store;
		$this->itemJournal = new ItemJournal();

		$itemFetcher = new ItemFetcher( $store, $this->mResults );

		// Used temporarily to allow switching back while testing
		$itemFetcher->setPrefetchFlag( $GLOBALS['smwgExperimentalFeatures'] );

		// Init the instance here so the value cache is shared and hereby avoids
		// a static declaration
		$this->fieldItemFinder = new FieldItemFinder(
			$store,
			$itemFetcher
		);

		$this->filterMap = new FilterMap( $this->mStore, $this->mResults );
	}

	/**
	 * @since 3.2
	 *
	 * @return FilterMap
	 */
	public function getFilterMap() {
		return $this->filterMap;
	}

	/**
	 * @since 3.1
	 *
	 * @return FieldItemFinder
	 */
	public function getFieldItemFinder() {
		return $this->fieldItemFinder;
	}

	/**
	 * @since 3.0
	 *
	 * @param ItemJournal $itemJournal
	 */
	public function setItemJournal( ItemJournal $itemJournal ) {
		$this->itemJournal = $itemJournal;
	}

	/**
	 * @since  2.4
	 *
	 * @return ItemJournal
	 */
	public function getItemJournal() {
		return $this->itemJournal;
	}

	/**
	 * @since  2.4
	 *
	 * @param boolean $isFromCache
	 */
	public function setFromCache( $isFromCache ) {
		$this->isFromCache = (bool)$isFromCache;
	}

	/**
	 * Only available by some stores that support the computation of scores.
	 *
	 * @since 3.0
	 *
	 * @param ScoreSet $scoreSet
	 */
	public function setScoreSet( ScoreSet $scoreSet ) {
		$this->scoreSet = $scoreSet;
	}

	/**
	 * @since 3.0
	 *
	 * @return ScoreSet|null
	 */
	public function getScoreSet() {
		return $this->scoreSet;
	}

	/**
	 * Only available by some stores that support the retrieval of excerpts.
	 *
	 * @since 3.0
	 *
	 * @param Excerpts $excerpts
	 */
	public function setExcerpts( Excerpts $excerpts ) {
		$this->excerpts = $excerpts;
	}

	/**
	 * @since 3.0
	 *
	 * @return Excerpts|null
	 */
	public function getExcerpts() {
		return $this->excerpts;
	}

	/**
	 * @since  2.4
	 *
	 * @return boolean
	 */
	public function isFromCache() {
		return $this->isFromCache;
	}

	/**
	 * Get the Store object that this result is based on.
	 *
	 * @return Store
	 */
	public function getStore() {
		return $this->mStore;
	}

	/**
	 * Return the next result row as an array of ResultArray objects, and
	 * advance the internal pointer.
	 *
	 * @return ResultArray[]|false
	 */
	public function getNext() {
		$page = current( $this->mResults );
		next( $this->mResults );

		if ( $page === false ) {
			return false;
		}

		$row = [];

		foreach ( $this->mPrintRequests as $pr ) {
			$row[] = $this->newResultArray( $page, $pr );
		}

		return $row;
	}

	private function newResultArray( DIWikiPage $page, PrintRequest $pr ) {
		$resultArray = ResultArray::factory( $page, $pr, $this );
		$resultArray->setItemJournal( $this->itemJournal );
		return $resultArray;
	}

	/**
	 * Return number of available results.
	 *
	 * @return integer
	 */
	public function getCount() {
		return count( $this->mResults );
	}

	/**
	 * Return an array of SMWDIWikiPage objects that make up the
	 * results stored in this object.
	 *
	 * @return DIWikiPage[]
	 */
	public function getResults() {
		return $this->mResults;
	}

	/**
	 * @since 2.3
	 */
	public function reset() {
		return reset( $this->mResults );
	}

	/**
	 * Returns the query object of the current result set
	 *
	 * @since 1.8
	 *
	 * @return Query
	 */
	public function getQuery() {
		return $this->mQuery;
	}

	/**
	 * Return the number of columns of result values that each row
	 * in this result set contains.
	 *
	 * @return integer
	 */
	public function getColumnCount() {
		return count( $this->mPrintRequests );
	}

	/**
	 * Return array of print requests (needed for printout since they contain
	 * property labels).
	 *
	 * @return PrintRequest[]
	 */
	public function getPrintRequests() {
		return $this->mPrintRequests;
	}

	/**
	 * Returns the query string defining the conditions for the entities to be
	 * returned.
	 *
	 * @return string
	 */
	public function getQueryString() {
		return $this->mQuery->getQueryString();
	}

	/**
	 * Would there be more query results that were not shown due to a limit?
	 *
	 * @return boolean
	 */
	public function hasFurtherResults() {
		return $this->mFurtherResults;
	}

	/**
	 * @since  2.0
	 *
	 * @param integer $countValue
	 */
	public function setCountValue( $countValue ) {
		$this->countValue = (int)$countValue;
	}

	/**
	 * @since  2.0
	 *
	 * @return integer|null
	 */
	public function getCountValue() {
		return $this->countValue;
	}

	/**
	 * Return error array, possibly empty.
	 *
	 * @return array
	 */
	public function getErrors() {
		// Just use query errors, as no errors generated in this class at the moment.
		return $this->mQuery->getErrors();
	}

	/**
	 * Adds an array of erros.
	 *
	 * @param array $errors
	 */
	public function addErrors( array $errors ) {
		$this->mQuery->addErrors( $errors );
	}

	/**
	 * Create an SMWInfolink object representing a link to further query results.
	 * This link can then be serialised or extended by further params first.
	 * The optional $caption can be used to set the caption of the link (though this
	 * can also be changed afterwards with SMWInfolink::setCaption()). If empty, the
	 * message 'smw_iq_moreresults' is used as a caption.
	 *
	 * @param string|false $caption
	 *
	 * @return SMWInfolink
	 */
	public function getQueryLink( $caption = false ) {

		$link = QueryLinker::get( $this->mQuery );

		$link->setCaption( $caption );
		$link->setParameter( $this->mQuery->getOffset() + count( $this->mResults ), 'offset' );

		return $link;
	}

	/**
	 * @deprecated since 2.5, use QueryResult::getQueryLink
	 *
	 * Returns an SMWInfolink object with the QueryResults print requests as parameters.
	 *
	 * @since 1.8
	 *
	 * @return SMWInfolink
	 */
	public function getLink() {
		return $this->getQueryLink();
	}

	/**
	 * @private
	 *
	 * @since 3.0
	 */
	public function setSerializerVersion( $version ) {
		$this->serializer_version = $version;
	}

	/**
	 * @see DISerializer::getSerializedQueryResult
	 * @since 1.7
	 * @return array
	 */
	public function serializeToArray() {

		$serializerFactory = new SerializerFactory();
		$serializer = $serializerFactory->newQueryResultSerializer();
		$serializer->version( $this->serializer_version );

		$serialized = $serializer->serialize( $this );
		reset( $this->mResults );

		return $serialized;
	}

	/**
	 * Returns a serialized QueryResult object with additional meta data
	 *
	 * This methods extends the serializeToArray() for additional meta
	 * that are useful when handling data via the api
	 *
	 * @note should be used instead of QueryResult::serializeToArray()
	 * as this method contains additional informaion
	 *
	 * @since 1.9
	 *
	 * @return array
	 */
	public function toArray() {

		$time = microtime( true );

		// @note micro optimization: We call getSerializedQueryResult()
		// only once and create the hash here instead of calling getHash()
		// to avoid getSerializedQueryResult() being called again
		// @note count + offset equals total therefore we deploy both values
		$serializeArray = $this->serializeToArray();

		return array_merge( $serializeArray, [
			'meta'=> [
				'hash'   => md5( json_encode( $serializeArray ) ),
				'count'  => $this->getCount(),
				'offset' => $this->mQuery->getOffset(),
				'source' => $this->mQuery->getQuerySource(),
				'time'   => number_format( ( microtime( true ) - $time ), 6, '.', '' )
				]
			]
		);
	}

	/**
	 * Returns result hash value
	 *
	 * @since 1.9
	 *
	 * @return string
	 */
	public function getHash( $type = null ) {

		// Just iterate over available subjects to create a "quick" hash given
		// that resolving the entire object tree is costly due to recursive
		// processing of all data items including its printouts
		if ( $type === self::QUICK_HASH ) {
			$hash = [];

			foreach ( $this->mResults as $dataItem ) {
				$hash[] = $dataItem->getHash();
			}

			reset( $this->mResults );
			return 'q:' . md5( json_encode( $hash ) );
		}

		return md5( json_encode( $this->serializeToArray() ) );
	}

}
