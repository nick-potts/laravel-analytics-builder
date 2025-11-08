<?php

/**
 * YOUR MONSTER QUERY - Can current Slice handle it? Let's see...
 */

use NickPotts\Slice\Metrics\Count;
use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Slice;

/**
 * ANSWER: NO - Current Slice cannot replicate this query exactly because:
 *
 * 1. No support for whereExists() with subqueries
 * 2. No support for polymorphic relation filtering (taxable_type)
 * 3. No way to express "join to tenant_issue WHERE ti_tenant_id = X AND ti_deleted_at IS NULL"
 *    (those are join conditions, not WHERE conditions)
 *
 * But here's the CLOSEST you could get with current Slice API:
 */

// Step 1: Define your tables (this is what you'd need to set up)
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
            'tenant_issue' => $this->hasMany(TenantIssueTable::class, 'ti_issue_id'),
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
            WithdrawnDimension::class => WithdrawnDimension::make('ti_withdrawn'),
        ];
    }

    public function relations(): array
    {
        return [
            'issue' => $this->belongsTo(IssuesTable::class, 'ti_issue_id'),
        ];
    }
}

// Step 2: Your Slice query (as close as possible without modifications)
$result = Slice::query()
    ->metrics([
        Count::make('issues.id')->label('Total Issues'),
    ])
    ->dimensions([
        TimeDimension::make('ti_published_at')->daily(),
    ])
    // These work fine in current Slice
    ->where('ti_published_at', '<=', now())
    ->where('ti_withdrawn', '=', false)
    ->where('ti_tenant_id', '=', 1)
    ->where('conversion_status', 'in', ['completed', 'pending'])
    ->where('file_type', '!=', 'pdf')
    ->where('file_type', '!=', 'another_bad_type')
    // PROBLEM: No way to add the EXISTS subquery for terms filtering!
    // Current Slice doesn't have ->whereExists() or ->whereHas()
    ->orderBy('ti_published_at', 'desc')
    ->orderBy('ti_issue_id', 'desc')
    ->limit(50)
    ->offset(0)
    ->get();

/**
 * What Laravel PHP would this generate? Something like:
 */

use Illuminate\Support\Facades\DB;

$laravelQuery = DB::table('issues')
    ->join('tenant_issue', function($join) {
        $join->on('ti_issue_id', '=', 'issues.id')
             ->where('ti_tenant_id', '=', 1) // Slice puts filters in join conditions when needed
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
    // MISSING: The EXISTS clause - Slice can't add this!
    // ->whereExists(function($query) {
    //     $query->select(DB::raw(1))
    //           ->from('terms')
    //           ->join('taxables', 'terms.id', '=', 'taxables.term_id')
    //           ->whereColumn('issues.id', 'taxables.taxable_id')
    //           ->where('taxables.taxable_type', 'Issue')
    //           ->where('terms.tenant_id', 1)
    //           ->whereIn('terms.id', [5, 10, 15]);
    // })
    ->groupBy(DB::raw('DATE(tenant_issue.ti_published_at)'))
    ->orderBy('ti_published_at', 'desc')
    ->orderBy('ti_issue_id', 'desc')
    ->limit(50)
    ->offset(0)
    ->get();

/**
 * WORKAROUND: You'd need to manually add the EXISTS after Slice builds the query
 */

// Get the Slice query builder (this doesn't exist in current API!)
// $query = Slice::query()
//     ->metrics([Count::make('issues.id')])
//     ->dimensions([TimeDimension::make('ti_published_at')->daily()])
//     ->where('ti_tenant_id', '=', 1)
//     ->toQuery(); // ← This method doesn't exist yet!
//
// // Then add your custom WHERE EXISTS
// $query->whereExists(function($q) {
//     $q->select(DB::raw(1))
//       ->from('terms')
//       ->join('taxables', 'terms.id', '=', 'taxables.term_id')
//       ->whereColumn('issues.id', 'taxables.taxable_id')
//       ->where('taxables.taxable_type', 'Issue')
//       ->whereIn('terms.id', [5, 10, 15]);
// });
//
// $result = $query->get();

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
