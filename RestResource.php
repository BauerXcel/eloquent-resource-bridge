<?php

namespace App\Resources;

use Illuminate\Support\Collection;
use PaulKnebel\EloquentResourceBridge\RestResource;

class App extends RestResource
{
    protected $primaryKey = 'AppId';
    protected $endpointUrl = 'https://listenapi.bauerradio.com/api9/applist';
    protected $filterable = [

    ];

    protected $includable = [];
    protected $supportedMethods = [
        'where',
//        'whereIn',
//        'orderBy',
    ];

    /**
     * There isn't a get endpoint with consistent shape data so we'll just hitch a ride on applist
     * @return Collection
     */
    public function find($entityId)
    {
        return \Cache::remember($this->getCacheKey($entityId), $this->getRememberFor(), function () use ($entityId) {
            $response = json_decode($this->sendGET($this->getIndexEndpointUrl(), $this->query)->getBody(), true);

            $collection = Collection::make($this->parseCollection($response))
                ->where($this->getPrimaryKey(), '=', $entityId);

            return $this->applyPostFilters($collection);
        });
    }

    /**
     * Response is flat
     * @param array $data
     * @return array|mixed
     */
    public function parseCollection($data)
    {
        return $data;
    }

    public function getIndexEndpointUrl()
    {
        return env('LISTEN_ENDPOINT', 'https://listenapi.planetradio.co.uk/api9/') . 'applist';
    }

}