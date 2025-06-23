<?php

namespace Apiato\Repository\Contracts;

/**
 * Cacheable Interface
 * Defines the contract for caching functionality
 */
interface CacheableInterface
{
    /**
     * Set Cache Repository
     *
     * @param object $repository
     * @return $this
     */
    public function setCacheRepository(object $repository): static;

    /**
     * Get Cache Repository
     *
     * @return object|null
     */
    public function getCacheRepository(): object|null;

    /**
     * Get Cache Key
     *
     * @param string $method
     * @param array<int|string, mixed>|null $args
     * @return string
     */
    public function getCacheKey(string $method, array $args = null): string;

    /**
     * Get Cache Minutes
     *
     * @return int
     */
    public function getCacheMinutes(): int;

    /**
     * Skip Cache
     *
     * @param bool $status
     * @return $this
     */
    public function skipCache($status = true);
}
