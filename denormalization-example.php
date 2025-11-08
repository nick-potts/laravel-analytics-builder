<?php

/**
 * CURRENT SCHEMA - What your existing query would look like in Slice
 */

use NickPotts\Slice\Metrics\Count;
use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Slice;

// Define the tables
class IssuesTable extends \NickPotts\Slice\Tables\Table
{
    protected string $table = 'issues';

    public function dimensions(): array
    {
        return [
            ConversionStatusDimension::class => ConversionStatusDimension::make('conversion_status'),
            FileTypeDimension::class => FileTypeDimension::make('file_type'),
        ];
    }

    public function relations(): array
    {
        return [
            // Polymorphic many-to-many through taxables
            'terms' => $this->belongsToMany(
                TermsTable::class,
                'taxables',
                'taxable_id',
                'term_id'
            )->where('taxable_type', 'Issue'),
        ];
    }
}

class TenantIssueTable extends \NickPotts\Slice\Tables\Table
{
    protected string $table = 'tenant_issue';

    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('ti_published_at')->asTimestamp(),
            TenantDimension::class => TenantDimension::make('ti_tenant_id'),
        ];
    }

    public function relations(): array
    {
        return [
            'issue' => $this->belongsTo(IssuesTable::class, 'ti_issue_id'),
        ];
    }
}

class TermsTable extends \NickPotts\Slice\Tables\Table
{
    protected string $table = 'terms';

    public function dimensions(): array
    {
        return [
            TenantDimension::class => TenantDimension::make('tenant_id'),
            TermDimension::class => TermDimension::make('id'),
        ];
    }
}

// Current schema query - This would generate JOINS but still need EXISTS logic
$currentQuery = Slice::query()
    ->metrics([
        Count::make('tenant_issue.ti_issue_id')->label('Issue Count'),
    ])
    ->dimensions([
        TimeDimension::make('ti_published_at')->daily(),
        TenantDimension::make('ti_tenant_id')->only([1]), // Your @ parameter
    ])
    ->where('ti_published_at', '<=', now())
    ->where('ti_withdrawn', false)
    ->where('conversion_status', 'in', ['completed', 'pending']) // Your ^ parameters
    ->where('file_type', '!=', 'pdf')
    // Problem: How to filter by terms efficiently?
    // This would require joining through issues -> taxables -> terms
    // and Slice would generate similar expensive EXISTS or JOINs
    ->get();

/**
 * DENORMALIZED APPROACH 1: Flatten terms into taxables
 */
class TaxablesTable extends \NickPotts\Slice\Tables\Table
{
    protected string $table = 'taxables';

    public function dimensions(): array
    {
        return [
            TenantDimension::class => TenantDimension::make('term_tenant_id'), // Denormalized!
            TermDimension::class => TermDimension::make('term_id'),
            TermNameDimension::class => TermNameDimension::make('term_name'), // Denormalized!
        ];
    }

    public function relations(): array
    {
        return [
            'taxable' => $this->morphTo('taxable_type', 'taxable_id'),
        ];
    }
}

// Denormalized query 1 - Join to taxables directly
$denormQuery1 = Slice::query()
    ->metrics([
        Count::make('tenant_issue.ti_issue_id')->label('Issue Count'),
    ])
    ->dimensions([
        TimeDimension::make('ti_published_at')->daily(),
        TenantDimension::make('ti_tenant_id')->only([1]),
        TermDimension::make('term_id')->only([5, 10, 15]), // Filter by terms
    ])
    ->where('ti_published_at', '<=', now())
    ->where('ti_withdrawn', false)
    ->where('conversion_status', 'in', ['completed', 'pending'])
    ->where('file_type', '!=', 'pdf')
    ->get();

// This would generate:
// SELECT COUNT(tenant_issue.ti_issue_id), DATE(ti_published_at)
// FROM tenant_issue
// INNER JOIN issues ON tenant_issue.ti_issue_id = issues.id
// INNER JOIN taxables ON taxables.taxable_id = issues.id AND taxables.taxable_type = 'Issue'
// WHERE taxables.term_tenant_id = 1
//   AND taxables.term_id IN (5, 10, 15)
//   AND ti_published_at <= NOW()
//   AND ti_withdrawn = false
//   ...
// GROUP BY DATE(ti_published_at)

/**
 * DENORMALIZED APPROACH 2: Flatten terms into tenant_issue (BEST for SingleStore!)
 */
class TenantIssueDenormalizedTable extends \NickPotts\Slice\Tables\Table
{
    protected string $table = 'tenant_issue';

    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('ti_published_at')->asTimestamp(),
            TenantDimension::class => TenantDimension::make('ti_tenant_id'),
            TermIdsDimension::class => TermIdsDimension::make('term_ids'), // JSON array!
        ];
    }

    public function relations(): array
    {
        return [
            'issue' => $this->belongsTo(IssuesTable::class, 'ti_issue_id'),
        ];
    }
}

// JSON dimension for term filtering
class TermIdsDimension extends Dimension
{
    public function containsAny(array $termIds): static
    {
        // This would need custom grammar support for JSON_CONTAINS or JSON_OVERLAPS
        $this->addFilter('contains_any', $termIds);

        return $this;
    }
}

// Denormalized query 2 - Single table, no joins!
$denormQuery2 = Slice::query()
    ->metrics([
        Count::make('tenant_issue.ti_issue_id')->label('Issue Count'),
    ])
    ->dimensions([
        TimeDimension::make('ti_published_at')->daily(),
        TenantDimension::make('ti_tenant_id')->only([1]),
        TermIdsDimension::make('term_ids')->containsAny([5, 10, 15]),
    ])
    ->where('ti_published_at', '<=', now())
    ->where('ti_withdrawn', false)
    ->where('conversion_status', 'in', ['completed', 'pending'])
    ->where('file_type', '!=', 'pdf')
    ->get();

// This would generate (with custom grammar for SingleStore):
// SELECT COUNT(ti_issue_id), DATE(ti_published_at)
// FROM tenant_issue
// INNER JOIN issues ON tenant_issue.ti_issue_id = issues.id
// WHERE ti_tenant_id = 1
//   AND JSON_OVERLAPS(term_ids, '[5,10,15]') -- SingleStore magic!
//   AND ti_published_at <= NOW()
//   AND ti_withdrawn = false
//   AND conversion_status IN ('completed', 'pending')
//   AND issues.file_type != 'pdf'
// GROUP BY DATE(ti_published_at)
// ORDER BY ti_published_at DESC
// LIMIT 50 OFFSET 0

/**
 * DENORMALIZED APPROACH 3: Dedicated join table (middle ground)
 */
class TenantIssueTermsTable extends \NickPotts\Slice\Tables\Table
{
    protected string $table = 'tenant_issue_terms';

    public function dimensions(): array
    {
        return [
            TenantDimension::class => TenantDimension::make('tenant_id'),
            TermDimension::class => TermDimension::make('term_id'),
        ];
    }

    public function relations(): array
    {
        return [
            'tenant_issue' => $this->belongsTo(TenantIssueTable::class, ['tenant_id', 'issue_id']),
        ];
    }
}

// Denormalized query 3 - Clean join table
$denormQuery3 = Slice::query()
    ->metrics([
        Count::make('tenant_issue.ti_issue_id')->label('Issue Count'),
    ])
    ->dimensions([
        TimeDimension::make('ti_published_at')->daily(),
        TenantDimension::make('tenant_id')->only([1]),
        TermDimension::make('term_id')->only([5, 10, 15]),
    ])
    ->where('ti_published_at', '<=', now())
    ->where('ti_withdrawn', false)
    ->where('conversion_status', 'in', ['completed', 'pending'])
    ->where('file_type', '!=', 'pdf')
    ->get();

// This would generate:
// SELECT COUNT(DISTINCT tenant_issue.ti_issue_id), DATE(ti_published_at)
// FROM tenant_issue
// INNER JOIN issues ON tenant_issue.ti_issue_id = issues.id
// INNER JOIN tenant_issue_terms ON tenant_issue_terms.tenant_id = tenant_issue.ti_tenant_id
//                              AND tenant_issue_terms.issue_id = tenant_issue.ti_issue_id
// WHERE tenant_issue_terms.tenant_id = 1
//   AND tenant_issue_terms.term_id IN (5, 10, 15)
//   AND ti_published_at <= NOW()
//   ...
// GROUP BY DATE(ti_published_at)
