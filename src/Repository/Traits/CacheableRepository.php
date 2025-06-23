<?php

namespace Apiato\Repository\Traits;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Enhanced caching trait for Apiato v.13
 * Improved performance and intelligent cache invalidation
 */
trait CacheableRepository
{
    protected ?CacheRepository $cacheRepository = null;
    protected ?int $cacheMinutes = null;
    protected bool $skipCache = false;

    /**
     * Set Cache Repository
     */
    public function setCacheRepository(object $repository): static
    {
        $this->cacheRepository = $repository;
        return $this;
    }

    /**
     * Get Cache Repository
     */
    public function getCacheRepository(): ?object
    {
        return $this->cacheRepository ?? Cache::store();
    }

    /**
     * Get Cache Minutes
     */
    public function getCacheMinutes(): int
    {
        return $this->cacheMinutes ?? config('repository.cache.minutes', 30);
    }

    /**
     * Skip Cache
     */
    public function skipCache($status = true)
    {
        $this->skipCache = $status;
        return $this;
    }

    /**
     * Check if method is allowed to be cached
     */
    public function allowedCache($method)
    {
        $cacheEnabled = config('repository.cache.enabled', false);

        if (!$cacheEnabled) {
            return false;
        }

        $cacheOnly = config('repository.cache.allowed.only');
        $cacheExcept = config('repository.cache.allowed.except');

        if (is_array($cacheOnly)) {
            return in_array($method, $cacheOnly);
        }

        if (is_array($cacheExcept)) {
            return !in_array($method, $cacheExcept);
        }

        if (is_null($cacheOnly) && is_null($cacheExcept)) {
            return true;
        }

        return false;
    }

    /**
     * Check if cache is skipped
     */
    public function isSkippedCache()
    {
        $skipped = request()->get(config('repository.cache.params.skipCache', 'skipCache'), false);
        if (is_string($skipped)) {
            $skipped = strtolower($skipped) === 'true';
        }

        return $this->skipCache || $skipped;
    }

    /**
     * Serialize criteria for cache key
     */
    protected function serializeCriteria()
    {
        try {
            return serialize($this->getCriteria());
        } catch (\Exception $e) {
            return serialize([]);
        }
    }

    /**
     * Enhanced cache key generation
     */
    public function getCacheKey(string $method, ?array $args = null): string
    {
        if (is_null($args)) {
            $args = [];
        }

        $key = sprintf('%s@%s-%s-%s',
            get_called_class(),
            $method,
            serialize($args),
            $this->serializeCriteria()
        );

        return hash('sha256', $key);
    }

    /**
     * Get cached result or execute callback
     */
    protected function getCachedResult($method, $args, $callback)
    {
        if (!$this->allowedCache($method) || $this->isSkippedCache()) {
            return $callback();
        }

        $key = $this->getCacheKey($method, $args);
        $minutes = $this->getCacheMinutes();

        return $this->getCacheRepository()->remember($key, $minutes, $callback);
    }

    /**
     * Clear cache for this repository
     */
    public function clearCache()
    {
        if (config('repository.cache.enabled', false)) {
            $pattern = get_called_class() . '@*';
            
            // For Redis cache
            if (method_exists($this->getCacheRepository(), 'getRedis')) {
                $redis = $this->getCacheRepository()->getRedis();
                $keys = $redis->keys($pattern);
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            } else {
                // For file/array cache, we'll use tags if available
                try {
                    $this->getCacheRepository()->tags([get_called_class()])->flush();
                } catch (\Exception $e) {
                    // Cache driver doesn't support tags
                }
            }
        }

        return $this;
    }

    /**
     * Forget specific cache key
     */
    public function forgetCache($method, $args = null)
    {
        if (config('repository.cache.enabled', false)) {
            $key = $this->getCacheKey($method, $args);
            $this->getCacheRepository()->forget($key);
        }

        return $this;
    }

    /**
     * Remember cache with tags (if supported)
     */
    protected function rememberWithTags($key, $minutes, $callback, $tags = [])
    {
        $cacheRepository = $this->getCacheRepository();
        
        if (empty($tags)) {
            $tags = [get_called_class()];
        }

        try {
            return $cacheRepository->tags($tags)->remember($key, $minutes, $callback);
        } catch (\Exception $e) {
            // Cache driver doesn't support tags, use regular remember
            return $cacheRepository->remember($key, $minutes, $callback);
        }
    }
}
