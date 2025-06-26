<?php

namespace Apiato\Repository\Criteria;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Apiato\Repository\Contracts\CriteriaInterface;
use Apiato\Repository\Contracts\RepositoryInterface;
use Apiato\Repository\Support\HashIdHelper;

/**
 * Enhanced RequestCriteria with advanced search capabilities
 * Enabled by REPOSITORY_ENHANCED_SEARCH=true
 *
 * HashId decoding is now built-in and automatic for all criteria/query string lookups.
 * See HashIdHelper for details. Controlled by config('repository.hashid_decode').
 */
class RequestCriteria implements CriteriaInterface
{
    protected Request $request;

    public function __construct(Request $request = null)
    {
        $this->request = $request ?? app('request');
    }

    /**
     * @param Model|Builder $model
     * @param RepositoryInterface $repository
     * @return Model|Builder
     */
    public function apply(Model|Builder $model, RepositoryInterface $repository): Model|Builder
    {
        $fieldsSearchable = $repository->getFieldsSearchable();
        $search = $this->request->get(config('repository.criteria.params.search', 'search'), null);
        $searchFields = $this->request->get(config('repository.criteria.params.searchFields', 'searchFields'), null);
        $filter = $this->request->get(config('repository.criteria.params.filter', 'filter'), null);
        $orderBy = $this->request->get(config('repository.criteria.params.orderBy', 'orderBy'), null);
        $sortedBy = $this->request->get(config('repository.criteria.params.sortedBy', 'sortedBy'), 'asc');
        $with = $this->request->get(config('repository.criteria.params.with', 'with'), null);

        $enhancedSearch = config('repository.apiato.features.enhanced_search', true);
        $forceEnhanced = $this->request->get('enhanced', false);

        if ($with) {
            $with = is_string($with) ? explode(',', $with) : (array)$with;
            $model = $model->with($with);
        }

        // Apply search conditions
        if ($search && is_array($fieldsSearchable) && count($fieldsSearchable)) {
            if (is_array($search)) {
                // skip array search values
            } elseif (($enhancedSearch || $forceEnhanced) && $this->shouldUseEnhancedSearch($search)) {
                $model = $model->where(function($searchQuery) use ($search, $fieldsSearchable, $searchFields, $repository) {
                    $this->applyEnhancedSearchToQuery($searchQuery, $search, $fieldsSearchable, $searchFields, $repository);
                });
            } else {
                $model = $model->where(function($searchQuery) use ($search, $fieldsSearchable, $searchFields, $repository) {
                    $this->applyBasicSearchToQuery($searchQuery, $search, $fieldsSearchable, $searchFields, $repository);
                });
            }
        }

        // Apply filter conditions (separate AND clause if search is also present)
        if ($filter && is_array($fieldsSearchable) && count($fieldsSearchable)) {
            $model = $model->where(function($filterQuery) use ($filter, $fieldsSearchable, $repository) {
                $this->applyFiltersToQuery($filterQuery, $filter, $fieldsSearchable, $repository);
            });
        }

        if ($orderBy) {
            $model = $this->applyOrdering($model, $orderBy, $sortedBy);
        }

        return $model;
    }

    /**
     * Enhanced search detection - smarter logic for when to use enhanced vs basic
     */
    protected function shouldUseEnhancedSearch(string $search): bool
    {
        // Trigger for explicit operators: +, -, ~, quotes
        if (preg_match('/["+~-]/', $search)) {
            return true;
        }

        // Trigger for email-like patterns (contains @ but not field-specific)
        if (strpos($search, '@') !== false && !preg_match('/[a-zA-Z0-9_.]+:/', $search)) {
            return true;
        }

        // Trigger for multiple words without field specifiers
        // BUT be smarter about when to use it
        if (preg_match('/\s+/', $search) && !preg_match('/[a-zA-Z0-9_.]+:/', $search)) {
            // If it looks like a proper name (Title Case), don't use enhanced search
            // e.g., "Active User", "John Smith" -> use basic search for exact matching
            if (preg_match('/^[A-Z][a-z]+(\s+[A-Z][a-z]+)+$/', $search)) {
                return false; // Use basic search for exact phrase matching
            }

            // For lowercase or mixed case multi-word searches, use enhanced search
            // e.g., "john doe", "senior developer" -> use enhanced search with OR logic
            return true;
        }

        return false;
    }

    /**
     * Apply enhanced search to a query object (for combining with filters)
     */
    protected function applyEnhancedSearchToQuery($query, string $search, array $fieldsSearchable, array $searchFields = null, RepositoryInterface $repository): void
    {
        $fields = $this->getValidSearchFields($fieldsSearchable, $searchFields);

        // If no valid fields, return empty results
        if (empty($fields)) {
            $query->where('1', '=', '0'); // Force empty result
            return;
        }

        // Parse field-specific terms (e.g., email:foo)
        $fieldSpecificTerms = [];
        $generalSearch = $search;

        // Extract field-specific patterns
        $fieldTermPattern = '/([a-zA-Z0-9_.]+):([^;\s]+)/';
        if (preg_match_all($fieldTermPattern, $search, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $field = $match[1];
                $value = $match[2];
                // STRICT: Only allow fields that exist in the validated fields array
                if (array_key_exists($field, $fields)) {
                    $fieldSpecificTerms[$field][] = $value;
                    // Remove field-specific terms from general search
                    $generalSearch = str_replace($match[0], '', $generalSearch);
                }
                // If field is not allowed, remove it from search but don't add to fieldSpecificTerms
                else {
                    $generalSearch = str_replace($match[0], '', $generalSearch);
                }
            }
            $generalSearch = trim($generalSearch);
        }

        // If no valid field-specific terms and no general search, return empty results
        if (empty($fieldSpecificTerms) && empty($generalSearch)) {
            $query->where('1', '=', '0'); // Force empty result
            return;
        }

        $hasConditions = false;

        // Apply field-specific terms
        foreach ($fieldSpecificTerms as $field => $values) {
            foreach ($values as $value) {
                if ($hasConditions) {
                    $query->where(function($subQuery) use ($field, $value, $fields, $repository) {
                        $this->applyFieldSearch($subQuery, $field, $value, $fields[$field], $repository);
                    });
                } else {
                    $this->applyFieldSearch($query, $field, $value, $fields[$field], $repository);
                    $hasConditions = true;
                }
            }
        }

        // Apply general enhanced search if there are remaining terms
        if (!empty($generalSearch)) {
            $searchTerms = $this->parseEnhancedSearch($generalSearch);
            $availableFields = array_keys($fields);

            // Remove fields already used in field-specific search
            $generalFields = array_diff($availableFields, array_keys($fieldSpecificTerms));

            if (!empty($generalFields)) {
                if ($hasConditions) {
                    $query->where(function($subQuery) use ($searchTerms, $generalFields, $fields, $repository) {
                        $this->buildEnhancedQuery($subQuery, $searchTerms, $generalFields, $fields, $repository);
                    });
                } else {
                    $this->buildEnhancedQuery($query, $searchTerms, $generalFields, $fields, $repository);
                }
            }
        }
    }

    /**
     * Apply basic search to a query object - FIXED for multi-word phrases
     */
    protected function applyBasicSearchToQuery($query, string $search, array $fieldsSearchable, array $searchFields = null, RepositoryInterface $repository): void
    {
        $searchFields = is_array($searchFields) || is_null($searchFields) ? $searchFields : explode(';', $searchFields);
        $fields = $this->getValidSearchFields($fieldsSearchable, $searchFields);

        // If no valid fields, return empty results
        if (empty($fields)) {
            $query->where('1', '=', '0'); // Force empty result
            return;
        }

        // Parse all search data first
        $allSearchData = $this->parserSearchData($search, []);
        $searchValue = $this->parserSearchValue($search);

        // Now filter search data to only include valid fields
        $searchData = $allSearchData->only(array_keys($fields));

        // Check if we have field-specific search terms that were rejected
        $hasFieldSpecificTerms = stripos($search, ':') !== false;
        if ($hasFieldSpecificTerms && $searchData->isEmpty() && is_null($searchValue)) {
            // All field-specific terms were rejected and no general search term
            $query->where('1', '=', '0'); // Force empty result
            return;
        }

        // FIXED: Ensure we have something to search for
        if ($searchData->isEmpty() && (is_null($searchValue) || empty($searchValue))) {
            // No search criteria provided
            return;
        }

        $modelForceAndWhere = strtolower($searchData->get('isForceAndWhere', 'or'));

        $isFirstField = true;
        $conditionsApplied = false;

        foreach ($fields as $field => $condition) {
            $value = null;
            $condition = trim(strtolower($condition));

            if (isset($searchData[$field])) {
                $searchTerm = $searchData[$field];
                if ($condition == "like" || $condition == "ilike") {
                    $searchTerm = $this->escapeLike($searchTerm);
                    $value = "%{$searchTerm}%";
                } else {
                    $value = $searchTerm;
                }
            } else {
                // FIXED: Apply general search value to all searchable fields
                if (!is_null($searchValue) && !empty($searchValue)) {
                    if ($condition == "like" || $condition == "ilike") {
                        $searchValueEscaped = $this->escapeLike($searchValue);
                        $value = "%{$searchValueEscaped}%";
                    } else {
                        $value = $searchValue;
                    }
                }
            }

            if ($value !== null && $value !== '') {
                if ($this->isIdField($field)) {
                    $value = HashIdHelper::decodeIfNeeded($field, $value);
                    $condition = ($condition == 'like' || $condition == 'ilike') ? '=' : $condition;
                }

                $relation = null;
                $fieldName = $field;
                if (stripos($field, '.')) {
                    $explodeField = explode('.', $field);
                    $fieldName = array_pop($explodeField);
                    $relation = implode('.', $explodeField);
                }

                if ($isFirstField || $modelForceAndWhere == 'and') {
                    if (!is_null($relation)) {
                        $query->whereHas($relation, function ($relationQuery) use ($fieldName, $condition, $value) {
                            if ($condition === 'like' || $condition === 'ilike') {
                                $relationQuery->whereRaw("$fieldName LIKE ? ESCAPE '\\'", [$value]);
                            } else {
                                $relationQuery->where($fieldName, $condition, $value);
                            }
                        });
                    } else {
                        if ($condition === 'like' || $condition === 'ilike') {
                            $query->whereRaw("$fieldName LIKE ? ESCAPE '\\'", [$value]);
                        } else {
                            $query->where($fieldName, $condition, $value);
                        }
                    }
                    $isFirstField = false;
                } else {
                    if (!is_null($relation)) {
                        $query->orWhereHas($relation, function ($relationQuery) use ($fieldName, $condition, $value) {
                            if ($condition === 'like' || $condition === 'ilike') {
                                $relationQuery->whereRaw("$fieldName LIKE ? ESCAPE '\\'", [$value]);
                            } else {
                                $relationQuery->where($fieldName, $condition, $value);
                            }
                        });
                    } else {
                        if ($condition === 'like' || $condition === 'ilike') {
                            $query->orWhereRaw("$fieldName LIKE ? ESCAPE '\\'", [$value]);
                        } else {
                            $query->orWhere($fieldName, $condition, $value);
                        }
                    }
                }
            }
        }
    }

    /**
     * Parse enhanced search string into terms - simplified and more reliable
     */
    protected function parseEnhancedSearch(string $search): array
    {
        $terms = [
            'required' => [],
            'excluded' => [],
            'optional' => [],
            'phrases' => []
        ];

        // Extract quoted phrases first
        if (preg_match_all('/"([^"]+)"/', $search, $phrases)) {
            foreach ($phrases[1] as $phrase) {
                $terms['phrases'][] = trim($phrase);
                $search = str_replace('"' . $phrase . '"', '', $search);
            }
        }

        // Handle email-like patterns by splitting on @ and .
        if (strpos($search, '@') !== false && !preg_match('/[+\-]/', $search)) {
            // Split email into meaningful parts
            $emailParts = preg_split('/[@.]/', $search);
            foreach ($emailParts as $part) {
                $part = trim($part);
                if (!empty($part)) {
                    $terms['optional'][] = $part;
                }
            }
            return $terms;
        }

        // Extract terms with operators
        if (preg_match_all('/([+\-]?)(\S+)/', $search, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $operator = $match[1];
                $term = $match[2];

                if (empty($term)) continue;

                // Handle fuzzy search (term~distance)
                if (preg_match('/(\w+)~(\d+)/', $term, $fuzzyMatch)) {
                    // For now, treat fuzzy as optional (SQLite doesn't support SOUNDEX well)
                    $terms['optional'][] = $fuzzyMatch[1];
                } elseif ($operator === '+') {
                    $terms['required'][] = $term;
                } elseif ($operator === '-') {
                    $terms['excluded'][] = $term;
                } else {
                    $terms['optional'][] = $term;
                }
            }
        }

        // If no explicit operators, split by spaces and treat as optional terms
        if (empty($terms['required']) && empty($terms['excluded']) && empty($terms['optional']) && empty($terms['phrases'])) {
            $words = preg_split('/\s+/', $search);
            foreach ($words as $word) {
                $word = trim($word);
                if (!empty($word)) {
                    $terms['optional'][] = $word;
                }
            }
        }

        return $terms;
    }

    /**
     * Simplified enhanced query builder with proper logic
     */
    protected function buildEnhancedQuery($query, array $searchTerms, array $searchableFields, array $fieldConditions, RepositoryInterface $repository): void
    {
        $hasConditions = false;

        // Required terms (ALL must match) - use AND logic
        foreach ($searchTerms['required'] as $term) {
            $query->where(function($subQuery) use ($term, $searchableFields, $fieldConditions, $repository) {
                $this->applyTermToFields($subQuery, $term, $searchableFields, 'or', $repository, $fieldConditions);
            });
            $hasConditions = true;
        }

        // Excluded terms (must NOT match any)
        foreach ($searchTerms['excluded'] as $term) {
            $query->whereNot(function($subQuery) use ($term, $searchableFields, $fieldConditions, $repository) {
                $this->applyTermToFields($subQuery, $term, $searchableFields, 'or', $repository, $fieldConditions);
            });
        }

        // Phrase searches (exact matches)
        foreach ($searchTerms['phrases'] as $phrase) {
            $query->where(function($subQuery) use ($phrase, $searchableFields, $fieldConditions, $repository) {
                $this->applyTermToFields($subQuery, $phrase, $searchableFields, 'or', $repository, $fieldConditions, true);
            });
            $hasConditions = true;
        }

        // Optional terms - for space-separated words, use OR logic (ANY can match)
        if (!empty($searchTerms['optional'])) {
            $query->where(function($subQuery) use ($searchTerms, $searchableFields, $fieldConditions, $repository) {
                foreach ($searchTerms['optional'] as $term) {
                    $subQuery->orWhere(function($termQuery) use ($term, $searchableFields, $fieldConditions, $repository) {
                        $this->applyTermToFields($termQuery, $term, $searchableFields, 'or', $repository, $fieldConditions);
                    });
                }
            });
            $hasConditions = true;
        }

        // If no conditions were added, ensure we have at least one condition to avoid empty where clause
        if (!$hasConditions) {
            $query->where('1', '=', '1'); // Always true condition
        }
    }

    /**
     * Apply a single field search with proper condition handling
     */
    protected function applyFieldSearch($query, string $field, string $value, string $condition, RepositoryInterface $repository): void
    {
        $value = $this->decodeCriteriaField($field, $value);

        if ($condition === 'like') {
            $escapedValue = $this->escapeLike($value);
            $searchValue = "%{$escapedValue}%";

            if (strpos($field, '.') !== false) {
                $this->applyRelationshipSearch($query, $field, $searchValue, 'like', 'and');
            } else {
                $query->whereRaw("$field LIKE ? ESCAPE '\\'", [$searchValue]);
            }
        } else {
            if (strpos($field, '.') !== false) {
                $this->applyRelationshipSearch($query, $field, $value, $condition, 'and');
            } else {
                $query->where($field, $condition, $value);
            }
        }
    }

    /**
     * Apply term to multiple fields with proper escaping
     */
    protected function applyTermToFields($query, string $term, array $fields, string $operator = 'or', ?RepositoryInterface $repository = null, array $fieldConditions = [], bool $isPhrase = false): void
    {
        $isFirst = true;

        foreach ($fields as $field) {
            $condition = $fieldConditions[$field] ?? 'like';
            $searchValue = $term;

            // Apply HashId decoding for ID fields
            $searchValue = $this->decodeCriteriaField($field, $searchValue);

            if ($condition === 'like') {
                $escapedValue = $this->escapeLike($searchValue);
                $value = "%{$escapedValue}%";
            } else {
                $value = $searchValue;
            }

            if (strpos($field, '.') !== false) {
                if ($isFirst && $operator === 'and') {
                    $this->applyRelationshipSearch($query, $field, $value, $condition, 'and');
                } else {
                    $this->applyRelationshipSearch($query, $field, $value, $condition, 'or');
                }
            } else {
                if ($isFirst && $operator === 'and') {
                    if ($condition === 'like') {
                        $query->whereRaw("$field LIKE ? ESCAPE '\\'", [$value]);
                    } else {
                        $query->where($field, $condition, $value);
                    }
                } else {
                    if ($condition === 'like') {
                        $query->orWhereRaw("$field LIKE ? ESCAPE '\\'", [$value]);
                    } else {
                        $query->orWhere($field, $condition, $value);
                    }
                }
            }
            $isFirst = false;
        }
    }

    /**
     * Escapes special characters for SQL LIKE queries.
     */
    protected function escapeLike(string $value, string $escapeChar = '\\'): string
    {
        return str_replace([
            $escapeChar, '%', '_'
        ], [
            $escapeChar . $escapeChar,
            $escapeChar . '%',
            $escapeChar . '_'
        ], $value);
    }

    /**
     * Decodes HashId for ID fields in advanced/enhanced search and filters.
     */
    protected function decodeCriteriaField(string $field, mixed $value): mixed
    {
        return $this->isIdField($field) ? HashIdHelper::decodeIfNeeded($field, $value) : $value;
    }

    /**
     * Fixed: Apply relationship search with proper query structure
     */
    protected function applyRelationshipSearch($query, string $field, mixed $value, string $condition, string $operator): void
    {
        $parts = explode('.', $field);
        $relation = array_shift($parts);
        $relationField = implode('.', $parts);
        $method = $operator === 'or' ? 'orWhereHas' : 'whereHas';

        $query->$method($relation, function($relationQuery) use ($relationField, $value, $condition) {
            if ($condition === 'like') {
                $relationQuery->whereRaw("$relationField LIKE ? ESCAPE '\\'", [$value]);
            } else {
                $relationQuery->where($relationField, $condition, $value);
            }
        });
    }

    /**
     * Fixed: Get valid search fields and properly enforce restrictions
     */
    protected function getValidSearchFields(array $fieldsSearchable, ?array $searchFields = null): array
    {
        // If no searchFields parameter provided, return all fieldsSearchable
        if (is_null($searchFields) || empty($searchFields)) {
            return $fieldsSearchable;
        }

        // If searchFields parameter is provided, restrict to those fields only
        $acceptedConditions = config('repository.criteria.acceptedConditions', ['=', 'like']);
        $restrictedFields = [];

        foreach ($searchFields as $fieldSpec) {
            if (is_string($fieldSpec)) {
                $parts = explode(':', $fieldSpec);
                $fieldName = $parts[0];

                // Only allow fields that exist in fieldsSearchable
                if (array_key_exists($fieldName, $fieldsSearchable)) {
                    if (count($parts) === 2 && in_array($parts[1], $acceptedConditions)) {
                        $restrictedFields[$fieldName] = $parts[1];
                    } else {
                        $restrictedFields[$fieldName] = $fieldsSearchable[$fieldName];
                    }
                }
            }
        }

        // If no valid restricted fields found, return empty array (this will cause empty results)
        // Don't throw exception, just return empty to indicate no valid fields
        if (empty($restrictedFields)) {
            return [];
        }

        return $restrictedFields;
    }

    protected function isIdField(string $field): bool
    {
        return $field === 'id' || str_ends_with($field, '_id');
    }

    /**
     * @param string $search
     * @return \Illuminate\Support\Collection<string, mixed>
     */
    protected function parserSearchData(string $search, array $fieldsSearchable = []): \Illuminate\Support\Collection
    {
        $searchData = [];
        if (stripos($search, ':')) {
            $fields = explode(';', $search);
            foreach ($fields as $row) {
                try {
                    [$field, $value] = explode(':', $row, 2);
                    $field = trim($field);
                    // Don't filter here - let getValidSearchFields handle field validation
                    // This allows us to parse all field:value pairs and then filter later
                    $searchData[$field] = trim($value);
                } catch (\Exception $e) {
                    // Skip invalid search format
                }
            }
        }
        return collect($searchData);
    }

    /**
     * @param string $search
     * @return string|null
     */
    protected function parserSearchValue(string $search): ?string
    {
        // FIXED: Only return null if search contains field-specific syntax
        // Multi-word searches like "Active User" should return the full string
        return (stripos($search, ';') !== false || stripos($search, ':') !== false) ? null : $search;
    }

    /**
     * Apply filters to a query object (for combining with search)
     */
    protected function applyFiltersToQuery($query, string|array $filter, array $fieldsSearchable, RepositoryInterface $repository): void
    {
        $fields = $this->getValidSearchFields($fieldsSearchable, null);

        if (is_string($filter)) {
            $this->applyStringFiltersToQuery($query, $filter, $fields);
        } elseif (is_array($filter)) {
            $this->applyArrayFiltersToQuery($query, $filter, $fields);
        }
    }

    /**
     * Handle string-based filters on a query object
     */
    protected function applyStringFiltersToQuery($query, string $filter, array $fields): void
    {
        // Split by | for OR groups
        $orGroups = explode('|', $filter);

        $isFirstGroup = true;

        foreach ($orGroups as $group) {
            $group = trim($group);
            if (empty($group)) continue;

            if ($isFirstGroup) {
                $query->where(function($andQuery) use ($group, $fields) {
                    $this->applyAndFilters($andQuery, $group, $fields);
                });
                $isFirstGroup = false;
            } else {
                $query->orWhere(function($andQuery) use ($group, $fields) {
                    $this->applyAndFilters($andQuery, $group, $fields);
                });
            }
        }
    }

    /**
     * Apply AND filters within a group
     */
    protected function applyAndFilters($query, string $group, array $fields): void
    {
        $andParts = explode(';', $group);

        foreach ($andParts as $part) {
            $part = trim($part);
            if (strpos($part, ':') === false) continue;

            [$field, $value] = explode(':', $part, 2);
            $field = trim($field);
            $value = trim($value);

            if (!array_key_exists($field, $fields)) continue;

            $condition = $fields[$field] ?? '=';
            if (is_numeric($condition)) {
                $condition = '=';
            }

            // Decode HashId for ID fields
            if ($this->isIdField($field)) {
                $value = HashIdHelper::decodeIfNeeded($field, $value);
            }

            // Apply LIKE condition
            if ($condition === 'like') {
                $escapedValue = $this->escapeLike($value);
                $value = "%{$escapedValue}%";
                $query->whereRaw("$field LIKE ? ESCAPE '\\'", [$value]);
            } else {
                $query->where($field, $condition, $value);
            }
        }
    }

    /**
     * Handle array-based filters on a query object
     */
    protected function applyArrayFiltersToQuery($query, array $filter, array $fields): void
    {
        foreach ($filter as $field => $value) {
            if (!array_key_exists($field, $fields)) continue;

            $isRelationship = strpos($field, '.') !== false;
            $condition = $fields[$field] ?? '=';
            if (is_numeric($condition)) {
                $condition = '=';
            }

            if (is_array($value)) {
                // Decode HashIds for ID fields in array values
                if ($this->isIdField($field)) {
                    $value = array_map(function($v) use ($field) {
                        return HashIdHelper::decodeIfNeeded($field, $v);
                    }, $value);
                }

                if ($isRelationship) {
                    $parts = explode('.', $field);
                    $relation = array_shift($parts);
                    $relationField = implode('.', $parts);
                    $query->whereHas($relation, function ($relationQuery) use ($relationField, $value) {
                        $relationQuery->whereIn($relationField, $value);
                    });
                } else {
                    $query->whereIn($field, $value);
                }
                continue;
            }

            // Decode HashId for ID fields
            if ($this->isIdField($field)) {
                $value = HashIdHelper::decodeIfNeeded($field, $value);
            }

            // Use LIKE for fields with 'like' condition
            if ($condition === 'like') {
                $escapedValue = $this->escapeLike($value);
                $value = "%{$escapedValue}%";
            }

            if ($isRelationship) {
                $parts = explode('.', $field);
                $relation = array_shift($parts);
                $relationField = implode('.', $parts);
                $query->whereHas($relation, function ($relationQuery) use ($relationField, $condition, $value) {
                    if ($condition === 'like') {
                        $relationQuery->whereRaw("$relationField LIKE ? ESCAPE '\\'", [$value]);
                    } else {
                        $relationQuery->where($relationField, $condition, $value);
                    }
                });
            } else {
                if ($condition === 'like') {
                    $query->whereRaw("$field LIKE ? ESCAPE '\\'", [$value]);
                } else {
                    $query->where($field, $condition, $value);
                }
            }
        }
    }

    /**
     * @param Model|Builder $model
     * @param string $orderBy
     * @param string $sortedBy
     * @return Model|Builder
     */
    protected function applyOrdering(Model|Builder $model, string $orderBy, string $sortedBy): Model|Builder
    {
        $orderBySplit = explode(',', $orderBy);
        if (count($orderBySplit) > 1) {
            $sortedBySplit = explode(',', $sortedBy);
            foreach ($orderBySplit as $orderBySplitItemKey => $orderBySplitItem) {
                $sortedByValue = isset($sortedBySplit[$orderBySplitItemKey]) ? $sortedBySplit[$orderBySplitItemKey] : $sortedBySplit[0];
                $model = $model->orderBy(trim($orderBySplitItem), trim($sortedByValue));
            }
        } else {
            $model = $model->orderBy($orderBy, $sortedBy);
        }
        return $model;
    }
}
