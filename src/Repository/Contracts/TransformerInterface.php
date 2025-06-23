<?php

namespace Apiato\Repository\Contracts;

/**
 * Transformer Interface
 * Defines the contract for data transformation
 */
interface TransformerInterface
{
    /**
     * Transform the given data
     *
     * @param mixed $model
     * @return array<string, mixed>
     */
    public function transform(mixed $model): array;
}
