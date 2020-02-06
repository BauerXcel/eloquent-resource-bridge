This is a package written for a Laravel 5.1. 

The goal is to treat RESTful resources (of any shape) in a similar way to Eloquent models.

**Disclaimer: This package, in it's current state, is not really intended for consumption by others, but feel free to improve it.**

## Features
 - Configurable caching
 - Filter data even if the resource server does not support it
 - Ability to bind with Eloquent
 - There are some assumptions but they can be overridden

## Roadmap
 - Define "drivers", each of which integrate with specific resource server patterns
 - Better documentation

## Usage
```php
namespace App\Resources;

use BauerXcel\EloquentResourceBridge\RestResource;
use Illuminate\Support\Collection;

class MyResource extends RestResource
{
    // Define index endpoint
    protected $endpointUrl = 'https://example.com/api/v1/resources';

    // Define primary key (Or, unique key)
    protected $primaryKey = 'stationId';

    // How long data should be cached (minutes)
    protected $rememberFor = 5;

    // Define filters supported by server. If not here, we will attempt to filter them by the data itself
    protected $filterable = [
        'type',
    ];

    // Define which subsets of data can be included with the root resource
    protected $includable = [
        'images'
    ];

    // Drivers should define this in a re-usable way, but also it should be extendable within each resource-type itself
    public function find($entityId)
    {
        return \Cache::remember($this->getCacheKey($entityId), $this->getRememberFor(), function () use ($entityId) {
            $response = json_decode($this->sendGET($this->getIndexEndpointUrl(), $this->query)->getBody(), true);

            $collection = Collection::make($this->parseCollection($response))
                ->where($this->getPrimaryKey(), '=', $entityId);

            return $this->applyPostFilters($collection);
        });
    }
}
```
