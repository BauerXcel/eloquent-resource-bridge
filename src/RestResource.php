<?php

namespace PaulKnebel\EloquentResourceBridge;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Cache\Repository as Cache;

abstract class RestResource
{
    /** @var Cache  Optional cache object */
    private $cache = null;

    /** @var string Primary Key of entity */
    protected $primaryKey;

    /** @var string Where the resources can be located */
    protected $endpointUrl;

    /** @var int How long applicable results can be cached (Default) */
    protected $rememberFor = 0;

    /** @var array Defined filterable fields */
    protected $filterable = [];

    /** @var array|null  List of supported methods. Null will assume all callable methods are supported. Post filter applied otherwise */
    protected $supportedMethods = null;

    /** @var array Defined includable entities */
    protected $includable = [];

    /** @var array List of filters to attempt after resource has been retrieved (if API does not support them) */
    protected $postFilters = [
        'where' => [],
        'whereIn' => [],
        'sortBy' => [],
    ];

    /** @var array Literal query parameters that will be passed to resource */
    protected $query = [];

    /** @var array Additional resources to be included with primary resource */
    protected $included = [];

    /** @var string|null  The unique cache key. Generally automated to prevent conflict */
    protected $cacheKey = null;

    /**
     * Communicate with REST Resource
     * @todo This class is fit-for-purpose but the elements need to be split out, ideas...
     *  - RestConnection - Would describe the endpoint server data shape
     *  - Caching Layer
     *  - Parsing Layer
     * RestResource constructor.
     * @param Client|null $client
     * @param Cache|null $cache
     */
    public function __construct(Client $client = null, Cache $cache = null)
    {
        $client = $client === null ? new Client() : $client;
        $this->setHttpClient($client);

        if($cache !== null) {
            $this->setCache($cache);
        }
    }

    /**
     * Basic filter
     * @param $field
     * @param $operator
     * @param $value
     * @return $this
     * @Resource
     */
    public function where($field, $operator, $value)
    {
        if ($this->isSupportedByResource('where') && $this->isFilterable($field)) {
            $this->query['_filter'][$field][$this->getOperator($operator)] = $value;
        } else {
            $this->postFilters['where'][] = func_get_args();
        }

        return $this;
    }

    /**
     * @param $field
     * @param $values
     * @param bool $isStrict
     * @todo WhereIn currently not supported by LAPI :S
     * @return RestResource
     * @Resource
     */
    public function whereIn($field, $values, $isStrict = false)
    {
        if ($this->isSupportedByResource('whereIn') && $this->isFilterable($field)) {
            $this->query['_filter'][$field]['IN'] = (array) $values;
        } else {
            $this->postFilters['whereIn'][] = func_get_args();
        }

        return $this;
    }

    /**
     * @param $column
     * @param $direction
     * @return RestResource
     * @Resource
     */
    public function orderBy($column, $direction = 'asc')
    {
        if ($this->isSupportedByResource('orderBy')) {
            $this->query['_order'] = ($direction !== 'asc' ? '-' : '') . $column;
        } else {
            // @note orderBy --> sortBy
            $this->postFilters['sortBy'][] = [$column, $direction === 'asc' ? SORT_ASC : SORT_DESC];
        }

        return $this;
    }

    /**
     * Apply filters that are not supported by the API, itself
     * @todo To be continued (other methods)
     * @param Collection $collection
     * @return Collection
     * @Resource
     */
    protected function applyPostFilters(Collection $collection)
    {
        foreach ($this->postFilters as $methodName => $calls) {
            foreach ($calls as $field => $arguments) {
                if (!$collection->has($field)) {
                    continue;
                }
                $collection = call_user_func_array([$collection, $methodName], $arguments);
            }
        }

        return $collection;
    }

    /**
     * Request additional sub-entities
     * @param $with
     * @return RestResource
     */
    public function with($with)
    {
        if ($this->isIncludable($with)) {
            $this->included[] = $with;
        }
        return $this;
    }

    /**
     * Run query and get single entity
     * @param $entityId
     * @return Collection
     */
    public function find($entityId)
    {
        $cacheKey = $this->getCacheKey('find:'.$entityId);
        if((bool) $this->getCache() && !!($results = $this->getCache()->get($cacheKey, null))) {
            return $results;
        }

        $result = json_decode($this->sendGET($this->getViewEndpointUrl($entityId), $this->query)->getBody(), true);
        $data = $this->parseItem($result);
        $collection = Collection::make($data);
        $results = $this->applyPostFilters($collection);

        if((bool) $this->getCache()) {
            $this->getCache()->put($cacheKey, $this->getRememberFor(), $results);
        }

        return $results;
    }


    /**
     *  Run query and return multiple entities
     * @todo I'd like an item to be returned rather than array, because it is equivelant to collection
     * @return Collection
     */
    public function get()
    {
        $cacheKey = $this->getCacheKey('get');
        if((bool) $this->getCache() && !!($response = $this->getCache()->get($cacheKey, null))) {
            return $this->applyPostFilters($response);
        }

        // Ensure query order is normalised, which will help all layers of caching identify similarities
        ksort($this->query, SORT_STRING);

        $response = json_decode($this->sendGET($this->getIndexEndpointUrl(), $this->query)->getBody(), true);
        $collection = collect($this->parseCollection($response));

        if((bool) $this->getCache()) {
            $this->getCache()->put($cacheKey, $collection, $this->getRememberFor());
        }

        return $this->applyPostFilters($collection);
    }

    /**
     * Trigger caching for the specified amount of time
     * @param $minutes
     * @param null $cacheKey
     * @return $this
     */
    public function remember($minutes, $cacheKey = null)
    {
        list($this->rememberFor, $this->cacheKey) = [$minutes, $cacheKey];
        return $this;
    }

    /**
     * @param $endpointUrl
     * @param array $query
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function sendGET($endpointUrl, array $query = [])
    {
        if (count($this->included)) {
            $query['include'] = implode(',', (array) $this->included);
        }

        if ($query !== []) {
            $endpointUrl .= (strpos($endpointUrl, '?') === false ? '?' : '&') . http_build_query($query);
        }

        return $this->getHttpClient()->request('GET', $endpointUrl);
    }

    /**
     * @param $endpointUrl
     * @param array $query
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function sendPOST($endpointUrl, array $query)
    {
        $this->rememberFor = null; // POST should not be cached
        return $this->getHttpClient()->request('POST', [
            RequestOptions::FORM_PARAMS => $query,
        ]);
    }

    /**
     * Get index endpoint of entities
     * @return string
     */
    public function getIndexEndpointUrl()
    {
        return $this->endpointUrl;
    }

    /**
     * Get URL of view
     * @param $entityId
     * @return string
     */
    private function getViewEndpointUrl($entityId)
    {
        return $this->getIndexEndpointUrl() . '/' . $entityId;
    }

    /**
     * Normalise operators
     * @param $operator
     * @return string
     */
    protected function getOperator($operator)
    {
        switch ($operator) {
            case '>':
                $operator = 'GT';
                break;
            case '>=':
                $operator = 'GTE';
                break;
            case '<':
                $operator = 'LT';
                break;
            case '<=':
                $operator = 'LTE';
                break;
            case '=':
                $operator = 'EQ';
                break;
        }
        return $operator;
    }

    /**
     * Determine the unique hash of the query
     * @todo Recursively sort arrays by keys to prevent different keys when they are built differently
     * @param string $additional Parameter provided on-the-fly to add uniquity to cache key
     * @return string Unique hash
     */
    protected function getCacheKey($additional = '')
    {
        // Namespace the hash so we can flush it if needed
        $prefix = 'resource:' . get_class($this) . ($additional ? ':' . $additional : '');

        if ($this->cacheKey !== null) {
            return $prefix . $this->cacheKey;
        }

        // @note placing "post_filters" here will mean the request itself isn't cache
        // ... which is more important to cache than the post-processing
        $cacheKey = $prefix . ':'. md5(json_encode([
                'included' => (array) $this->included,
                'query' => (array) $this->query,
            ]));

        return $cacheKey;
    }


    /**
     * Determine whether the entity provided can be includable
     * @param $entity
     * @return bool
     */
    private function isIncludable($entity)
    {
        return in_array($entity, $this->includable, true);
    }

    /**
     * Determine whether the field provided can be filtered via the request (if not, we will run it in post-filter)
     * @todo Needs to consider sub-data filtering via. relationships
     * @param $field
     * @return bool
     */
    protected function isFilterable($field)
    {
        return in_array($field, $this->filterable, true);
    }

    /**
     * Determine whether this resource supports the callable method
     * @param $method
     * @return bool
     */
    protected function isSupportedByResource($method)
    {
        // Assume it is
        if (!isset($this->supportedMethods) || is_null($this->supportedMethods)) {
            return true;
        }
        return in_array($method, $this->supportedMethods, true) && is_callable([$this, $method]);

    }

    /**
     * Parse resultset of multiple entities
     * @param array $data
     * @return mixed
     * @Parser
     */
    public function parseCollection($data)
    {
        if (!is_array($data)) {
            $data = (array) $data;
        }
        return (array) $data['body'];
    }

    /*
     * Parse resultset of single entity
     * @Parser
     */
    public function parseItem($data)
    {
        return $data;
    }

    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    public function setHttpClient($client)
    {
        $this->httpClient = $client;
    }

    public function getHttpClient()
    {
        return $this->httpClient;
    }

    public function setCache(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @return Cache
     */
    public function getCache()
    {
        return $this->cache;
    }

    public function getRememberFor()
    {
        return $this->rememberFor;
    }
}
