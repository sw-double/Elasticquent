<?php namespace Elasticquent;

use \Elasticquent\ElasticquentCollection as ElasticquentCollection;
use \Elasticquent\ElasticquentResultCollection as ResultCollection;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Illuminate\Database\Eloquent\Model;

/**
 * Elasticquent Trait
 *
 * Functionality extensions for Elequent that
 * makes working with Elasticsearch easier.
 */
trait ElasticquentTrait
{
    /**
     * Uses Timestamps In Index
     *
     * @var bool
     */
    protected $usesTimestampsInIndex = true;

    /**
     * Is ES Document
     *
     * Set to true when our model is
     * populated by a
     *
     * @var bool
     */
    protected $isDocument = false;

    /**
     * Document Score
     *
     * Hit score when using data
     * from Elasticsearch results.
     *
     * @var null|int
     */
    protected $documentScore = null;

    /**
     * Document Version
     *
     * Elasticsearch document version.
     *
     * @var null|int
     */
    protected $documentVersion = null;

    /**
     * Keep your Elasticsearch documents
     * in sync with your Eloquent database rows
     *
     * It listens to 'created', 'updated', and 'deleted'
     * model events to keep documents up to date
     *
     * @var bool
     */
    protected static $syncToElasticsearch = true;

    /**
     * Bind event listeners for syncToElasticsearch
     */
    public static function bootElasticquentTrait()
    {
        static::saved(function(Model $model)
        {
            if ($model::$syncToElasticsearch)
            {
                $model->updateIndex();
            }
        }, -1);

        static::deleted(function(Model $model)
        {
            if ($model::$syncToElasticsearch)
            {
                $model->removeFromIndex();
            }
        }, -1);
    }

    /**
     * Get ElasticSearch Client
     *
     * @return \Elasticsearch\Client
     */
    public function getElasticSearchClient()
    {
        $config = [];

        if (\Config::has('elasticquent.config')) {
            $config = \Config::get('elasticquent.config');
        }

        return new \Elasticsearch\Client($config);
    }

    /**
     * New Collection
     *
     * @param array $models
     * @return Collection
     */
    public function newCollection(array $models = [])
    {
        return new ElasticquentCollection($models);
    }

    /**
     * Get Index Name
     *
     * @return string
     */
    public function getIndexName()
    {
        // The first thing we check is if there
        // is an elasticquery config file and if there is a
        // default index.
        if (\Config::has('elasticquent.default_index')) {
            return \Config::get('elasticquent.default_index');
        }

        // Otherwise we will just go with 'default'
        return 'default';
    }

    /**
     * Get Type Name
     *
     * @return string
     */
    public function getTypeName()
    {
        return $this->getTable();
    }

    /**
     * Uses Timestamps In Index
     *
     * @return void
     */
    public function usesTimestampsInIndex()
    {
        return $this->usesTimestampsInIndex;
    }

    /**
     * Use Timestamps In Index
     *
     * @return void
     */
    public function useTimestampsInIndex()
    {
        $this->usesTimestampsInIndex = true;
    }

    /**
     * Don't Use Timestamps In Index
     *
     * @return void
     */
    public function dontUseTimestampsInIndex()
    {
        $this->usesTimestampsInIndex = false;
    }

    /**
     * Get Mapping Properties
     *
     * @return array
     */
    public function getMappingProperties()
    {
        return $this->mappingProperties;
    }

    /**
     * Set Mapping Properties
     *
     * @param $mapping
     * @internal param array $mappingProperties
     */
    public function setMappingProperties($mapping)
    {
        $this->mappingProperties = $mapping;
    }

    /**
     * Is Elasticsearch Document
     *
     * Is the data in this module sourced
     * from an Elasticsearch document source?
     *
     * @return bool
     */
    public function isDocument()
    {
        return $this->isDocument;
    }

    /**
     * Get Document Score
     *
     * @return null|float
     */
    public function documentScore()
    {
        return $this->documentScore;
    }

    /**
     * Document Version
     *
     * @return null|int
     */
    public function documentVersion()
    {
        return $this->documentVersion;
    }

    /**
     * Get Index Document Data
     *
     * Get the data that Elasticsearch will
     * index for this particular document.
     *
     * @return  array
     */
    public function getIndexDocumentData()
    {
        return $this->toArray();
    }

    /**
     * Index Documents
     *
     * Index all documents in an Eloquent model.
     *
     * @return  array
     */
    public static function addAllToIndex()
    {
        $instance = new static;

        $all = $instance->newQuery()->get(['*']);

        return $all->addToIndex();
    }

    /**
     * Re-Index All Content
     *
     * @return array
     */
    public static function reindex()
    {
        $instance = new static;

        $all = $instance->newQuery()->get(['*']);

        return $all->reindex();
    }

    /**
     * Search By Query
     *
     * Search with a query array
     *
     * @param   array $query
     * @param   int $limit
     * @param   int $offset
     * @return  ResultCollection
     */
    public static function searchByQuery($query = [], $limit = null, $offset = null)
    {
        $instance = new static;

        $params = $instance->getBasicEsParams(true, true, true, $limit, $offset);

		$params['body'] = $query;

        $result = $instance->getElasticSearchClient()->search($params);

        return new ResultCollection($result, $instance = new static);
    }

    /**
     * Search
     *
     * Simple search using a match _all query
     *
     * @param   string $term
     * @return  ResultCollection
     */
    public static function search($term = null)
    {
        $instance = new static;

        $params = $instance->getBasicEsParams();

        $params['body']['query']['match']['_all'] = $term;

        $result = $instance->getElasticSearchClient()->search($params);

        return new ResultCollection($result, $instance = new static);
    }

    /**
     * Add to Search Index
     *
     * @throws Exception
     * @return array
     */
    public function addToIndex()
    {
        if ( ! $this->exists) {
            throw new Exception('Document does not exist.');
        }

        $params = $this->getBasicEsParams();

        // Get our document body data.
        $params['body'] = $this->getIndexDocumentData();

        // The id for the document must always mirror the
        // key for this model, even if it is set to something
        // other than an auto-incrementing value. That way we
        // can do things like remove the document from
        // the index, or get the document from the index.
        $params['id'] = $this->getKey();

        return $this->getElasticSearchClient()->index($params);
    }

    /**
     * Update Record in Index
     *
     * @param bool $upsert
     *
     * @return array
     */
    public function updateIndex($upsert = true)
    {
        $params = $this->getBasicEsParams();

        array_set($params, 'body.doc', $this->getIndexDocumentData());

        if ($upsert)
        {
            array_set($params, 'body.doc_as_upsert', true);
        }

        return $this->getElasticSearchClient()->update($params);
    }

    /**
     * Remove From Search Index
     *
     * @return array
     */
    public function removeFromIndex()
    {
        return $this->getElasticSearchClient()->delete($this->getBasicEsParams());
    }

    /**
     * Get Search Document
     *
     * Retrieve an ElasticSearch document
     * for this enty.
     *
     * @return array
     */
    public function getIndexedDocument()
    {
        return $this->getElasticSearchClient()->get($this->getBasicEsParams());
    }

    /**
     * Get Basic Elasticsearch Params
     *
     * Most Elasticsearch API calls need the index and
     * type passed in a parameter array.
     *
     * @param     bool $getIdIfPossible
     * @param     bool $getSourceIfPossible
     * @param     bool $getTimestampIfPossible
     * @param     int $limit
     * @param     int $offset
     *
     * @return    array
     */
    public function getBasicEsParams($getIdIfPossible = true, $getSourceIfPossible = false, $getTimestampIfPossible = false, $limit = null, $offset = null)
    {
        $params = [
            'index'     => $this->getIndexName(),
            'type'      => $this->getTypeName()
        ];

        if ($getIdIfPossible and $this->getKey()) {
            $params['id'] = $this->getKey();
        }

        $fieldsParam = [];

        if ($getSourceIfPossible) {
            array_push($fieldsParam, '_source');
        }

        if ($getTimestampIfPossible) {
            array_push($fieldsParam, '_timestamp');
        }

        if ($fieldsParam) {
            $params['fields'] = implode(",", $fieldsParam);
        }

        if (is_numeric($limit)) {
            $params['size'] = $limit;
        }

        if (is_numeric($offset)) {
            $params['from'] = $offset;
        }

        return $params;
    }

    /**
     * Mapping Exists
     *
     * @return bool
     */
    public static function mappingExists()
    {
        $instance = new static;

        $mapping = $instance->getMapping();

        return (empty($mapping)) ? false : true;
    }

    /**
     * Get Mapping
     *
     * @return void
     */
    public static function getMapping()
    {
        $instance = new static;

        $params = $instance->getBasicEsParams();

        return $instance->getElasticSearchClient()->indices()->getMapping($params);
    }

    /**
     * Put Mapping
     *
     * @param    bool $ignoreConflicts
     * @return   array
     */
    public static function putMapping($ignoreConflicts = false)
    {
        $instance = new static;

        $mapping = $instance->getBasicEsParams();

        $params = [
            '_source'       => ['enabled' => true],
            'properties'    => $instance->getMappingProperties()
        ];

        $mapping['body'][$instance->getTypeName()] = $params;

        return $instance->getElasticSearchClient()->indices()->putMapping($mapping);
    }

    /**
     * Delete Mapping
     *
     * @return array
     */
    public static function deleteMapping()
    {
        $instance = new static;

        $params = $instance->getBasicEsParams();

        return $instance->getElasticSearchClient()->indices()->deleteMapping($params);
    }

    /**
     * Rebuild Mapping
     *
     * This will delete and then re-add
     * the mapping for this model.
     * This well also create index
     * if it does not exist already
     *
     * @param null $shards
     * @param null $replicas
     *
     * @return array
     */
    public static function rebuildMapping($shards = null, $replicas = null)
    {
        $instance = new static;

        try
        {
            // If the mapping exists, let's delete it.
            if ($instance->mappingExists()) {
                $instance->deleteMapping();
            }
        }
        catch (Missing404Exception $e)
        {
            //
        }

        static::createIndexIfNotExists($shards, $replicas);

        // Don't need ignore conflicts because if we
        // just removed the mapping there shouldn't
        // be any conflicts.
        return $instance->putMapping();
    }

    /**
     * Create Index
     *
     * @param int $shards
     * @param int $replicas
     * @return array
     */
    public static function createIndex($shards = null, $replicas = null)
    {
        $instance = new static;

        $client = $instance->getElasticSearchClient();

        $index = [
            'index'     => $instance->getIndexName()
        ];

        if ($shards) {
            $index['body']['settings']['number_of_shards'] = $shards;
        }

        if ($replicas) {
            $index['body']['settings']['number_of_replicas'] = $replicas;
        }

        return $client->indices()->create($index);
    }

    /**
     * Create Index if It Does Not Exist
     *
     * @param null $shards
     * @param null $replicas
     *
     * @return array|null
     */
    public static function createIndexIfNotExists($shards = null, $replicas = null)
    {
        if ( ! static::indexExists()) {
            return static::createIndex($shards, $replicas);
        }
    }

    /**
     * Index Exists
     *
     * @return bool
     */
    public static function indexExists()
    {
        $instance = new static;

        return $instance->getElasticSearchClient()->indices()->exists(['index' => $instance->getIndexName()]);
    }

    /**
     * Type Exists
     *
     * Does this type exist?
     *
     * @return bool
     */
    public static function typeExists()
    {
        $instance = new static;

        $params = $instance->getBasicEsParams();

        return $instance->getElasticSearchClient()->indices()->existsType($params);
    }

    /**
     * New From Hit Builder
     *
     * Variation on newFromBuilder. Instead, takes
     *
     * @param  array  $hit
     * @return static
     */
    public function newFromHitBuilder($hit = [])
    {
        $instance = $this->newInstance([], true);

        $attributes = $hit['_source'];

        // Add fields to attributes
        if (isset($hit['fields'])) {
            foreach ($hit['fields'] as $key => $value) {
                $attributes[$key] = $value;
            }
        }

        $instance->setRawAttributes((array) $attributes, true);

        // In addition to setting the attributes
        // from the index, we will set the score as well.
        $instance->documentScore = $hit['_score'];

        // This is now a model created
        // from an Elasticsearch document.
        $instance->isDocument = true;

        // Set our document version if it's
        if (isset($hit['_version'])) {
            $instance->documentVersion = $hit['_version'];
        }

        return $instance;
    }

	/**
     * Delete all documents of model's type using DeleteByQuery API
     */
    public static function deleteAllFromIndex()
    {
        $model = new static();

        $params = $model->getBasicEsParams() + [ 'body' => [ 'query' => [ 'match_all' => [ ] ] ] ];

        try {
            $model->getElasticSearchClient()->deleteByQuery($params);
        }
        catch (Missing404Exception $e)
        {
            //
        }
    }
}