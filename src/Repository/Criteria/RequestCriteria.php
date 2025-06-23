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

        if ($search && is_array($fieldsSearchable) && count($fieldsSearchable)) {
            if (is_array($search)) {
                // skip array search values
            } elseif (($enhancedSearch || $forceEnhanced) && $this->shouldUseEnhancedSearch($search)) {
                $model = $this->applyEnhancedSearch($model, $search, $fieldsSearchable, $searchFields, $repository);
            } else {
                $model = $this->applyBasicSearch($model, $search, $fieldsSearchable, $searchFields, $repository);
            }
        }

        if ($filter && is_array($fieldsSearchable) && count($fieldsSearchable)) {
            $model = $this->applyFilters($model, $filter, $fieldsSearchable, $repository);
        }

        if ($orderBy) {
            $model = $this->applyOrdering($model, $orderBy, $sortedBy);
        }

        return $model;
    }

    protected function shouldUseEnhancedSearch(string $search): bool
    {
        return (bool)preg_match('/["+~-]|^[^:]*\s+[^:]*$/', $search);
    }

    /**
     * @param Model|Builder $model
     * @param string $search
     * @param array<string, string> $fieldsSearchable
     * @param array<int, string>|null $searchFields
     * @param RepositoryInterface $repository
     * @return Model|Builder
     */
    protected function applyEnhancedSearch(Model|Builder $model, string $search, array $fieldsSearchable, array $searchFields = null, RepositoryInterface $repository): Model|Builder
    {
        $searchTerms = $this->parseEnhancedSearch($search);
        return $model->where(function($query) use ($searchTerms, $fieldsSearchable, $repository) {
            $this->buildEnhancedQuery($query, $searchTerms, $fieldsSearchable, $repository);
        });
    }

    /**
     * @param string $search
     * @return array<string, array<int, mixed>>
     */
    protected function parseEnhancedSearch(string $search): array
    {
        $terms = [
            'required' => [],
            'excluded' => [],
            'optional' => [],
            'fuzzy' => [],
            'phrases' => []
        ];
        preg_match_all('/"([^"]+)"/', $search, $phrases);
        foreach ($phrases[1] as $phrase) {
            $terms['phrases'][] = trim($phrase);
            $search = str_replace('"' . $phrase . '"', '', $search);
        }
        preg_match_all('/([+\-]?)(\w+(?:~\d+)?)/', $search, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $operator = $match[1];
            $term = $match[2];
            if (empty($term)) continue;
            if (preg_match('/(\w+)~(\d+)/', $term, $fuzzyMatch)) {
                $terms['fuzzy'][] = [
                    'term' => $fuzzyMatch[1],
                    'distance' => (int)$fuzzyMatch[2]
                ];
            } elseif ($operator === '+') {
                $terms['required'][] = $term;
            } elseif ($operator === '-') {
                $terms['excluded'][] = $term;
            } else {
                $terms['optional'][] = $term;
            }
        }
        return $terms;
    }

    /**
     * @param mixed $query
     * @param array<string, array<int, mixed>> $searchTerms
     * @param array<string, string> $fieldsSearchable
     * @param RepositoryInterface $repository
     * @return void
     */
    protected function buildEnhancedQuery($query, array $searchTerms, array $fieldsSearchable, RepositoryInterface $repository): void
    {
        $relevanceSelects = [];
        $relevanceScore = 0;
        $searchableFields = $this->getSearchableFieldNames($fieldsSearchable);
        foreach ($searchTerms['required'] as $term) {
            $query->where(function($subQuery) use ($term, $searchableFields, $repository) {
                $this->applyTermToFields($subQuery, $term, $searchableFields, 'or', $repository);
            });
        }
        foreach ($searchTerms['excluded'] as $term) {
            $query->whereNot(function($subQuery) use ($term, $searchableFields, $repository) {
                $this->applyTermToFields($subQuery, $term, $searchableFields, 'or', $repository);
            });
        }
        foreach ($searchTerms['phrases'] as $phrase) {
            $query->where(function($subQuery) use ($phrase, $searchableFields, $repository) {
                $this->applyTermToFields($subQuery, $phrase, $searchableFields, 'or', $repository, '=');
            });
            foreach ($searchableFields as $field) {
                $relevanceSelects[] = "CASE WHEN {$field} LIKE '%{$phrase}%' THEN 10 ELSE 0 END";
            }
        }
        if (!empty($searchTerms['optional'])) {
            $query->where(function($subQuery) use ($searchTerms, $searchableFields, $repository) {
                foreach ($searchTerms['optional'] as $term) {
                    $subQuery->orWhere(function($termQuery) use ($term, $searchableFields, $repository) {
                        $this->applyTermToFields($termQuery, $term, $searchableFields, 'or', $repository);
                    });
                }
            });
            foreach ($searchTerms['optional'] as $term) {
                foreach ($searchableFields as $field) {
                    $relevanceSelects[] = "CASE WHEN {$field} LIKE '%{$term}%' THEN 5 ELSE 0 END";
                }
            }
        }
        foreach ($searchTerms['fuzzy'] as $fuzzyTerm) {
            $term = $fuzzyTerm['term'];
            $distance = $fuzzyTerm['distance'];
            $query->orWhere(function($subQuery) use ($term, $searchableFields, $repository) {
                if (function_exists('soundex')) {
                    foreach ($searchableFields as $field) {
                        $subQuery->orWhereRaw("SOUNDEX({$field}) = SOUNDEX(?)", [$term]);
                    }
                } else {
                    $this->applyTermToFields($subQuery, $term, $searchableFields, 'or', $repository);
                }
            });
        }
        if (!empty($relevanceSelects)) {
            $relevanceFormula = '(' . implode(' + ', $relevanceSelects) . ') as relevance_score';
            $query->selectRaw('*, ' . $relevanceFormula);
            $query->orderByDesc('relevance_score');
        }
    }

    /**
     * @param mixed $query
     * @param string $term
     * @param array<int, string> $fields
     * @param string $operator
     * @param RepositoryInterface|null $repository
     * @param string $condition
     * @return void
     */
    protected function applyTermToFields($query, string $term, array $fields, string $operator = 'or', ?RepositoryInterface $repository = null, string $condition = 'like'): void
    {
        foreach ($fields as $field) {
            $value = $condition === 'like' ? "%{$term}%" : $term;
            $value = $this->decodeCriteriaField($field, $value);
            if (strpos($field, '.') !== false) {
                $this->applyRelationshipSearch($query, $field, $value, $condition, $operator);
            } else {
                if ($operator === 'or') {
                    $query->orWhere($field, $condition === 'like' ? 'LIKE' : '=', $value);
                } else {
                    $query->where($field, $condition === 'like' ? 'LIKE' : '=', $value);
                }
            }
        }
    }

    /**
     * Decodes HashId for ID fields in advanced/enhanced search and filters.
     */
    protected function decodeCriteriaField(string $field, mixed $value): mixed
    {
        return $this->isIdField($field) ? HashIdHelper::decodeIfNeeded($field, $value) : $value;
    }

    /**
     * @param mixed $query
     * @param string $field
     * @param mixed $value
     * @param string $condition
     * @param string $operator
     * @return void
     */
    protected function applyRelationshipSearch($query, string $field, mixed $value, string $condition, string $operator): void
    {
        $parts = explode('.', $field);
        $relation = array_shift($parts);
        $relationField = implode('.', $parts);
        $method = $operator === 'or' ? 'orWhereHas' : 'whereHas';
        $query->$method($relation, function($relationQuery) use ($relationField, $value, $condition) {
            $relationQuery->where($relationField, $condition === 'like' ? 'LIKE' : '=', $value);
        });
    }

    /**
     * @param array<string, string> $fieldsSearchable
     * @return array<int, string>
     */
    protected function getSearchableFieldNames(array $fieldsSearchable): array
    {
        $fields = [];
        foreach ($fieldsSearchable as $key => $value) {
            if (is_numeric($key)) {
                $fields[] = $value;
            } else {
                $fields[] = $key;
            }
        }
        return $fields;
    }

    /**
     * @param Model|Builder $model
     * @param string $search
     * @param array<string, string> $fieldsSearchable
     * @param array<int, string>|null $searchFields
     * @param RepositoryInterface $repository
     * @return Model|Builder
     */
    protected function applyBasicSearch(Model|Builder $model, string $search, array $fieldsSearchable, array $searchFields = null, RepositoryInterface $repository): Model|Builder
    {
        $searchFields = is_array($searchFields) || is_null($searchFields) ? $searchFields : explode(';', $searchFields);
        $fields = $this->parserFieldsSearch($fieldsSearchable, $searchFields);
        $isFirstField = true;
        $searchData = $this->parserSearchData($search);
        $searchValue = $this->parserSearchValue($search);
        $modelForceAndWhere = strtolower($searchData->get('isForceAndWhere', 'or'));
        $fields = array_filter($fields, 'is_string', ARRAY_FILTER_USE_KEY);
        foreach ($fields as $field => $condition) {
            if (!is_string($field)) continue;
            $value = null;
            $condition = trim(strtolower($condition));
            if (isset($searchData[$field])) {
                $value = ($condition == "like" || $condition == "ilike") ? "%{$searchData[$field]}%" : $searchData[$field];
            } else {
                if (!is_null($searchValue) && !empty($searchValue)) {
                    $value = ($condition == "like" || $condition == "ilike") ? "%{$searchValue}%" : $searchValue;
                }
            }
            if ($value) {
                if ($this->isIdField($field)) {
                    $value = HashIdHelper::decodeIfNeeded($field, $value);
                    $condition = ($condition == 'like' || $condition == 'ilike') ? '=' : $condition;
                } else {
                    if ($condition == "like" || $condition == "ilike") {
                        $value = "%{$value}%";
                    }
                }
                $relation = null;
                if (stripos($field, '.')) {
                    $explodeField = explode('.', $field);
                    $field = array_pop($explodeField);
                    $relation = implode('.', $explodeField);
                }
                $modelTableName = $model->getModel()->getTable();
                if ($isFirstField || $modelForceAndWhere == 'and') {
                    if (!is_null($relation)) {
                        $model->whereHas($relation, function ($query) use ($field, $condition, $value) {
                            $query->where($field, $condition, $value);
                        });
                    } else {
                        $model->where($modelTableName.'.'.$field, $condition, $value);
                    }
                    $isFirstField = false;
                } else {
                    if (!is_null($relation)) {
                        $model->orWhereHas($relation, function ($query) use ($field, $condition, $value) {
                            $query->where($field, $condition, $value);
                        });
                    } else {
                        $model->orWhere($modelTableName.'.'.$field, $condition, $value);
                    }
                }
            }
        }
        return $model;
    }

    protected function isIdField(string $field): bool
    {
        return $field === 'id' || str_ends_with($field, '_id');
    }

    /**
     * @param array<string, string> $fields
     * @param array<int, string>|null $searchFields
     * @return array<string, string>
     */
    protected function parserFieldsSearch(array $fields = [], ?array $searchFields = null): array
    {
        if (!is_null($searchFields) && count($searchFields)) {
            $acceptedConditions = config('repository.criteria.acceptedConditions', [
                '=', 'like'
            ]);
            $originalFields = $fields;
            $fields = [];
            foreach ($searchFields as $index => $field) {
                if (is_array($field)) continue;
                $field_parts = explode(':', $field);
                $temporaryIndex = array_search($field_parts[0], $originalFields);
                if (count($field_parts) == 2) {
                    if (in_array($field_parts[1], $acceptedConditions)) {
                        unset($originalFields[$temporaryIndex]);
                        $fields[$field_parts[0]] = $field_parts[1];
                    }
                }
            }
            if (count($fields) == 0) {
                throw new \Exception('None of the search fields were accepted. Accepted conditions: ' . implode(',', $acceptedConditions));
            }
        }
        return $fields;
    }

    /**
     * @param string $search
     * @return \Illuminate\Support\Collection<string, mixed>
     */
    protected function parserSearchData(string $search): \Illuminate\Support\Collection
    {
        $searchData = [];
        if (stripos($search, ':')) {
            $fields = explode(';', $search);
            foreach ($fields as $row) {
                try {
                    [$field, $value] = explode(':', $row);
                    $searchData[trim($field)] = trim($value);
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
        return stripos($search, ';') || stripos($search, ':') ? null : $search;
    }

    /**
     * @param Model|Builder $model
     * @param string|array $filter
     * @param array<string, string> $fieldsSearchable
     * @param RepositoryInterface $repository
     * @return Model|Builder
     */
    protected function applyFilters(Model|Builder $model, string|array $filter, array $fieldsSearchable, RepositoryInterface $repository): Model|Builder
    {
        $fields = $this->parserFieldsSearch($fieldsSearchable, null);
        // Support both classic (string) and array-style (array) filter syntaxes
        if (is_string($filter)) {
            // Classic AND/OR encapsulation: email:alice@example.com;status:active|name:gigi
            $orGroups = explode('|', $filter);
            $isFirstGroup = true;
            foreach ($orGroups as $group) {
                $andParts = explode(';', $group);
                foreach ($andParts as $part) {
                    if (strpos($part, ':') === false) continue;
                    [$field, $value] = explode(':', $part, 2);
                    $field = trim($field);
                    $value = trim($value);
                    if (!array_key_exists($field, $fields)) continue;
                    $condition = $fields[$field] ?? '=';
                    if (is_numeric($condition)) {
                        $condition = '=';
                    }
                    if ($isFirstGroup) {
                        $model = $model->where($field, $condition, $value);
                    } else {
                        $model = $model->orWhere($field, $condition, $value);
                    }
                }
                $isFirstGroup = false;
            }
            return $model;
        } elseif (is_array($filter)) {
            $filterData = collect($filter);
        } else {
            $filterData = collect();
        }
        foreach ($filterData as $field => $value) {
            // Support relationship fields (e.g., roles.name)
            $isRelationship = strpos($field, '.') !== false;
            $condition = $fields[$field] ?? '=';
            if (is_numeric($condition)) {
                $condition = '=';
            }
            if (is_array($value)) {
                if ($isRelationship) {
                    $parts = explode('.', $field);
                    $relation = array_shift($parts);
                    $relationField = implode('.', $parts);
                    $model = $model->whereHas($relation, function ($query) use ($relationField, $value) {
                        $query->whereIn($relationField, $value);
                    });
                } else {
                    $model = $model->whereIn($field, $value);
                }
                continue;
            }
            if ($isRelationship) {
                $parts = explode('.', $field);
                $relation = array_shift($parts);
                $relationField = implode('.', $parts);
                $model = $model->whereHas($relation, function ($query) use ($relationField, $condition, $value) {
                    $query->where($relationField, $condition, $value);
                });
            } else {
                $model = $model->where($field, $condition, $value);
            }
        }
        return $model;
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
