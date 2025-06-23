<?php

namespace Apiato\Repository\Validators;

use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Apiato\Repository\Contracts\ValidatorInterface;

/**
 * Laravel Validator for Apiato v.13
 * Provides validation functionality using Laravel's validator
 */
class LaravelValidator implements ValidatorInterface
{
    protected array $rules = [];
    protected array $data = [];
    protected $validator;
    protected array $errors = [];
    protected array $customMessages = [];
    protected array $customAttributes = [];

    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    /**
     * Set data to validate
     */
    public function with(array $input): static
    {
        $this->data = $input;
        return $this;
    }

    /**
     * Validate data for create action
     */
    public function passesCreate(): bool
    {
        return $this->passes(self::RULE_CREATE);
    }

    /**
     * Validate data for update action
     */
    public function passesUpdate(): bool
    {
        return $this->passes(self::RULE_UPDATE);
    }

    /**
     * Validate data for given action
     */
    public function passes(?string $action = null): bool
    {
        $rules = $this->getRules($action);
        
        if (empty($rules)) {
            return true;
        }

        $this->validator = ValidatorFacade::make(
            $this->data, 
            $rules, 
            $this->customMessages, 
            $this->customAttributes
        );
        
        if ($this->validator->fails()) {
            $this->errors = $this->validator->errors()->toArray();
            return false;
        }

        return true;
    }

    /**
     * Get validation errors
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Set validation rules
     */
    public function setRules(array $rules)
    {
        $this->rules = $rules;
        return $this;
    }

    /**
     * Set custom error messages
     */
    public function setCustomMessages(array $messages)
    {
        $this->customMessages = $messages;
        return $this;
    }

    /**
     * Set custom attribute names
     */
    public function setCustomAttributes(array $attributes)
    {
        $this->customAttributes = $attributes;
        return $this;
    }

    /**
     * Get rules for specific action
     */
    protected function getRules($action)
    {
        if (is_null($action)) {
            return $this->rules;
        }

        return $this->rules[$action] ?? $this->rules;
    }

    /**
     * Add rule for specific action
     */
    public function addRule($action, $field, $rule)
    {
        if (!isset($this->rules[$action])) {
            $this->rules[$action] = [];
        }

        $this->rules[$action][$field] = $rule;
        return $this;
    }

    /**
     * Remove rule for specific action
     */
    public function removeRule($action, $field)
    {
        if (isset($this->rules[$action][$field])) {
            unset($this->rules[$action][$field]);
        }

        return $this;
    }
}
