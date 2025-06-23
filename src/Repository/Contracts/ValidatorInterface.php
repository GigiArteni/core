<?php

namespace Apiato\Repository\Contracts;

/**
 * Validator Interface
 * Defines the contract for data validation
 */
interface ValidatorInterface
{
    public const RULE_CREATE = 'create';
    public const RULE_UPDATE = 'update';

    /**
     * Set data to validate
     *
     * @param array<string, mixed> $input
     * @return $this
     */
    public function with(array $input): static;

    /**
     * Validate data for create action
     *
     * @return bool
     */
    public function passesCreate(): bool;

    /**
     * Validate data for update action
     *
     * @return bool
     */
    public function passesUpdate(): bool;

    /**
     * Validate data for given action
     *
     * @param string|null $action
     * @return bool
     */
    public function passes(?string $action = null): bool;

    /**
     * Get validation errors
     *
     * @return array<string, array<int, string>>
     */
    public function errors(): array;
}
