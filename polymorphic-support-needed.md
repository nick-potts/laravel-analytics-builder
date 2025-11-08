# What Slice Needs to Support Polymorphic Relations

## Missing Features

To handle your monster query, Slice needs these additions:

### 1. Polymorphic Relation Types

**Current:** Only supports `belongsTo()`, `hasMany()`, `belongsToMany()`

**Needed:** Add `morphTo()`, `morphMany()`, `morphToMany()`

```php
// src/Tables/Relations/MorphToMany.php
class MorphToMany extends Relation
{
    public function __construct(
        protected string $related,
        protected string $pivotTable,
        protected string $foreignPivotKey,
        protected string $relatedPivotKey,
        protected string $morphType,
        protected string $morphClass,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'morphToMany',
            'table' => $this->related,
            'pivot_table' => $this->pivotTable,
            'foreign_key' => $this->foreignPivotKey,
            'related_key' => $this->relatedPivotKey,
            'morph_type' => $this->morphType,
            'morph_class' => $this->morphClass,
        ];
    }
}
```

**Usage in Table:**
```php
class IssuesTable extends Table
{
    public function relations(): array
    {
        return [
            'terms' => $this->morphToMany(
                TermsTable::class,
                'taxable',           // morph name
                'taxables',          // pivot table
                'taxable_id',        // foreign key
                'term_id'            // related key
            )->morphClass('Issue'), // sets taxable_type = 'Issue'
        ];
    }
}
```

### 2. whereHas() / whereExists() Query Methods

**Current:** Only `where()`, `orderBy()`, `limit()`, `offset()`

**Needed:** Add relation constraint methods

```php
// src/Slice.php (or QueryBuilder)
public function whereHas(string $relation, ?Closure $callback = null): static
{
    // Store relation constraint for later processing
    $this->relationConstraints[] = [
        'type' => 'has',
        'relation' => $relation,
        'callback' => $callback,
    ];

    return $this;
}

public function whereDoesntHave(string $relation, ?Closure $callback = null): static
{
    $this->relationConstraints[] = [
        'type' => 'doesntHave',
        'relation' => $relation,
        'callback' => $callback,
    ];

    return $this;
}
```

### 3. JoinResolver Enhancement for Polymorphic Joins

**Current:** Uses BFS to find join paths for standard relations

**Needed:** Handle polymorphic relations with type constraints

```php
// src/Engine/JoinResolver.php
protected function applyPolymorphicJoin(
    QueryAdapter $query,
    string $sourceTable,
    MorphToMany $relation
): void
{
    $pivotAlias = $relation->pivotTable;

    $query->join($pivotAlias, function($join) use ($relation, $sourceTable) {
        $join->on("{$pivotAlias}.{$relation->foreignPivotKey}", '=', "{$sourceTable}.id")
             ->where("{$pivotAlias}.{$relation->morphType}", '=', $relation->morphClass);
    });

    $query->join($relation->related, "{$relation->related}.id", '=',
                 "{$pivotAlias}.{$relation->relatedPivotKey}");
}
```

### 4. EXISTS Subquery Builder

**Current:** Direct joins only

**Needed:** Correlated subquery support

```php
// src/Engine/SubqueryBuilder.php (NEW FILE)
class SubqueryBuilder
{
    public function buildExistsSubquery(
        string $relation,
        array $relationDefinition,
        ?Closure $callback
    ): string
    {
        $subquery = DB::table($relationDefinition['pivot_table']);

        // Apply polymorphic constraints
        if ($relationDefinition['type'] === 'morphToMany') {
            $subquery->where(
                "{$relationDefinition['pivot_table']}.{$relationDefinition['morph_type']}",
                '=',
                $relationDefinition['morph_class']
            );
        }

        // Apply join to related table
        $subquery->join(
            $relationDefinition['table'],
            "{$relationDefinition['table']}.id",
            '=',
            "{$relationDefinition['pivot_table']}.{$relationDefinition['related_key']}"
        );

        // Add column correlation
        $subquery->whereColumn(
            "{$relationDefinition['pivot_table']}.{$relationDefinition['foreign_key']}",
            '=',
            "issues.id" // parent table
        );

        // Apply user callback filters
        if ($callback) {
            $callback($subquery);
        }

        return $subquery;
    }
}
```

### 5. QueryBuilder Integration

**Current:** Builds query from metrics + dimensions

**Needed:** Apply relation constraints as EXISTS

```php
// src/Engine/QueryBuilder.php
protected function applyRelationConstraints(QueryAdapter $query): void
{
    foreach ($this->relationConstraints as $constraint) {
        $relation = $this->resolveRelation($constraint['relation']);

        if ($constraint['type'] === 'has') {
            $subquery = $this->subqueryBuilder->buildExistsSubquery(
                $constraint['relation'],
                $relation,
                $constraint['callback']
            );

            $query->whereExists($subquery);
        }

        if ($constraint['type'] === 'doesntHave') {
            $subquery = $this->subqueryBuilder->buildExistsSubquery(
                $constraint['relation'],
                $relation,
                $constraint['callback']
            );

            $query->whereNotExists($subquery);
        }
    }
}
```

## Complete Example After Implementation

```php
// Your monster query would become:
$result = Slice::query()
    ->metrics([
        Count::make('issues.id')->label('Total Issues'),
    ])
    ->dimensions([
        TimeDimension::make('ti_published_at')->daily(),
    ])
    ->where('ti_published_at', '<=', now())
    ->where('ti_withdrawn', '=', false)
    ->where('ti_tenant_id', '=', 1)
    ->where('conversion_status', 'in', ['completed', 'pending'])
    ->where('file_type', '!=', 'pdf')
    // NEW: whereHas() support!
    ->whereHas('terms', function($query) {
        $query->where('tenant_id', 1)
              ->whereIn('id', [5, 10, 15]);
    })
    ->orderBy('ti_published_at', 'desc')
    ->orderBy('ti_issue_id', 'desc')
    ->limit(50)
    ->offset(0)
    ->get();
```

This would generate:

```php
DB::table('issues')
    ->join('tenant_issue', function($join) {
        $join->on('ti_issue_id', '=', 'issues.id')
             ->where('ti_tenant_id', '=', 1)
             ->whereNull('ti_deleted_at');
    })
    ->select([
        DB::raw('COUNT(issues.id) as total_issues'),
        DB::raw('DATE(tenant_issue.ti_published_at) as date'),
    ])
    ->where('tenant_issue.ti_published_at', '<=', now())
    ->where('tenant_issue.ti_withdrawn', '=', false)
    ->whereIn('issues.conversion_status', ['completed', 'pending'])
    ->where('issues.file_type', '!=', 'pdf')
    ->whereNull('issues.deleted_at')
    ->whereExists(function($query) {
        $query->select(DB::raw(1))
              ->from('terms')
              ->join('taxables', 'terms.id', '=', 'taxables.term_id')
              ->whereColumn('issues.id', 'taxables.taxable_id')
              ->where('taxables.taxable_type', 'Issue')
              ->where('terms.tenant_id', 1)
              ->whereIn('terms.id', [5, 10, 15]);
    })
    ->groupBy(DB::raw('DATE(tenant_issue.ti_published_at)'))
    ->orderBy('ti_published_at', 'desc')
    ->orderBy('ti_issue_id', 'desc')
    ->limit(50)
    ->offset(0)
    ->get();
```

## Files to Create/Modify

1. **NEW:** `src/Tables/Relations/MorphToMany.php` - Polymorphic relation class
2. **NEW:** `src/Tables/Relations/MorphTo.php` - Inverse polymorphic relation
3. **NEW:** `src/Tables/Relations/MorphMany.php` - One-to-many polymorphic
4. **NEW:** `src/Engine/SubqueryBuilder.php` - EXISTS subquery generation
5. **MODIFY:** `src/Slice.php` - Add `whereHas()`, `whereDoesntHave()` methods
6. **MODIFY:** `src/Engine/QueryBuilder.php` - Apply relation constraints
7. **MODIFY:** `src/Engine/JoinResolver.php` - Handle polymorphic join types
8. **MODIFY:** `src/Tables/Table.php` - Add `morphToMany()`, `morphTo()`, `morphMany()` helpers

## Estimated Complexity

- **Low:** Polymorphic relation types (just data structures)
- **Medium:** whereHas() API and relation constraint storage
- **High:** Subquery builder with proper column correlation
- **High:** Integration with existing JoinResolver to avoid duplicate joins

## Alternative: Dimension-based Filtering

Instead of `whereHas()`, you could model polymorphic relations as dimensions:

```php
class IssuesTable extends Table
{
    public function dimensions(): array
    {
        return [
            // Treat polymorphic relation as a filterable dimension
            TermDimension::class => TermDimension::make('terms.id')
                ->throughPolymorphic('taxables', 'taxable', 'Issue'),
        ];
    }
}

// Query becomes:
Slice::query()
    ->metrics([Count::make('issues.id')])
    ->dimensions([
        TimeDimension::make('ti_published_at')->daily(),
        TermDimension::make()->only([5, 10, 15]), // Filters via EXISTS
    ])
    ->get();
```

This keeps the dimension-centric philosophy but requires custom `throughPolymorphic()` support.
