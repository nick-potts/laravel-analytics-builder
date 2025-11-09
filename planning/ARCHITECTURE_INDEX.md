# Slice Package - Architecture Documentation Index

## Complete Analysis Generated

This documentation set provides a comprehensive analysis of the Slice Laravel Analytics package architecture for the upcoming Eloquent-First Architecture refactor.

### Files Included

| File | Size | Purpose | Audience |
|------|------|---------|----------|
| **ARCHITECTURE_SUMMARY.md** | 11 KB | High-level overview, findings, and next steps | Everyone (START HERE) |
| **ARCHITECTURE_ANALYSIS.md** | 30 KB | Deep dive into every class and method | Architects & Lead Developers |
| **ARCHITECTURE_DIAGRAMS.md** | 24 KB | Visual ASCII diagrams and algorithm walkthroughs | Visual learners |
| **QUICK_REFERENCE.md** | 14 KB | Practical refactoring guide with checklists | Developers implementing changes |
| **ARCHITECTURE_INDEX.md** | This file | Navigation guide | Everyone |

**Total:** 79 KB of detailed architectural documentation

---

## Quick Navigation

### I want to understand the overall architecture
→ Start with **ARCHITECTURE_SUMMARY.md**
   - 5-minute overview
   - Key findings
   - Architecture pattern explanation
   - Strengths and weaknesses

### I want to know how each class works
→ Read **ARCHITECTURE_ANALYSIS.md**
   - Section 2: Class-by-class breakdown (Slice, QueryBuilder, QueryExecutor, etc.)
   - Section 4: Complete data flow examples
   - Section 5: Metric types and processing
   - Section 6: Design patterns

### I want visual diagrams of the system
→ Check **ARCHITECTURE_DIAGRAMS.md**
   - Class relationship diagram
   - Data transformation pipeline
   - Decision trees
   - Algorithm walkthroughs (BFS, DFS, joins)
   - By-database implementation differences

### I'm planning a refactor and need practical guidance
→ Use **QUICK_REFERENCE.md**
   - Performance bottlenecks
   - Critical decision points
   - Recommended refactor phases
   - File organization for changes
   - Complexity estimates

---

## Key Concepts in 30 Seconds

### The Pipeline
```
Input → Normalize Metrics → Select Plan → Execute → Post-Process → Output
```

### Three Input Types (One Output Format)
- `Metric` instances: `Sum::make('orders.total')`
- `MetricEnum` cases: `OrdersMetric::Revenue`
- String lookups: `'orders.revenue'` (via Registry)
All normalize to: `{enum, table, metric, key}`

### Two Query Execution Strategies
- **Database Plan**: Single SQL query execution
- **Software Join Plan**: Multiple queries joined in PHP

### Two Metric Types
- **Aggregation**: Sum, Count, Avg, Min, Max (database computed)
- **Computed**: Depends on other metrics (post-execution computed)

### Five Core Classes
1. `Slice` - Entry point
2. `QueryBuilder` - Plan generation
3. `QueryExecutor` - Query execution
4. `PostProcessor` - Computed metric evaluation
5. `Registry` - Metric/table/dimension storage

---

## Key Findings Summary

### Strengths
1. Clean separation of concerns (pipeline stages)
2. Polymorphic input handling
3. Plan-based execution (defer strategy selection)
4. Adapter pattern for database abstraction
5. Support for 7 databases out of the box

### Weaknesses
1. No caching of resolver results
2. Software join limitations (INNER only, memory heavy)
3. Cross-table computed metrics evaluated incorrectly
4. Registry coupling (can't use without it)
5. Expression evaluation uses eval() fallback

### Most Complex Classes
1. **QueryBuilder** (700 lines) - Plan selection and query construction
2. **QueryExecutor** (300 lines) - Polymorphic execution and software joins
3. **PostProcessor** (270 lines) - Computed metric evaluation
4. **DependencyResolver** (240 lines) - Topological sorting

---

## For Different Roles

### Product Manager
- Read: ARCHITECTURE_SUMMARY.md (Key Findings section)
- Learn: What Slice does and why the architecture matters
- Understand: Performance implications and extension points

### Architect
- Read: ARCHITECTURE_ANALYSIS.md (Complete class breakdown)
- Read: ARCHITECTURE_DIAGRAMS.md (Visual relationships)
- Understand: Design patterns and trade-offs
- Plan: Refactoring strategy from QUICK_REFERENCE.md

### Implementation Developer
- Read: QUICK_REFERENCE.md (Refactoring guide)
- Reference: ARCHITECTURE_ANALYSIS.md (Specific class details)
- Check: ARCHITECTURE_DIAGRAMS.md (Visual walkthroughs)
- Plan: Which files to modify from "File Organization" section

### Code Reviewer
- Reference: ARCHITECTURE_ANALYSIS.md (Method signatures)
- Check: Design patterns match existing approach
- Verify: Changes don't violate separation of concerns
- Test: Using guidelines from Testing Strategy section

### New Team Member
- Start: ARCHITECTURE_SUMMARY.md (Foundations)
- Deep dive: ARCHITECTURE_ANALYSIS.md (Class details)
- Practice: ARCHITECTURE_DIAGRAMS.md (Algorithm walkthroughs)
- Reference: QUICK_REFERENCE.md (Common patterns)

---

## Critical Insights

### The Query Flow
1. User calls `Slice::query()->metrics([...])->dimensions([...])->get()`
2. Metrics are normalized to unified format (handles 3 input types)
3. QueryBuilder analyzes metrics and selects best execution plan
4. Plan can be: DatabasePlan (direct SQL) or SoftwareJoinPlan (PHP joins)
5. Executor runs the plan and returns rows
6. PostProcessor evaluates any computed metrics
7. Results wrapped in ResultCollection

### Why This Matters for Refactoring
- Each stage is independent → can refactor separately
- Plans encapsulate all metadata → enables optimization
- Registry enables string-based queries → must understand coupling
- Software joins have limitations → must plan enhancements carefully

### Performance Bottlenecks
- DependencyResolver runs multiple times → add caching
- JoinResolver BFS runs per table → cache results
- Software joins require full row materialization → optimize algorithm
- Expression evaluation is row-by-row → consider materialization

### Testing Coverage Needed
- Unit tests for each resolver (already exists?)
- Integration tests for pipeline stages
- Feature tests for complete queries
- Database-specific tests for each driver

---

## Recommended Reading Order

### If you have 30 minutes
1. ARCHITECTURE_SUMMARY.md - Full read
2. ARCHITECTURE_DIAGRAMS.md - Sections 1-2 (class diagram, pipeline)

### If you have 2 hours
1. ARCHITECTURE_SUMMARY.md - Full read
2. ARCHITECTURE_ANALYSIS.md - Sections 1-2 (overview, classes)
3. ARCHITECTURE_DIAGRAMS.md - Sections 3-5 (decision tree, joins, dimensions)

### If you have a day
1. All files, cover to cover
2. ARCHITECTURE_ANALYSIS.md - All sections, especially section 4 (data flow examples)
3. ARCHITECTURE_DIAGRAMS.md - All sections, trace through examples

---

## Questions This Documentation Answers

**Architecture Questions**
- What is the overall architecture pattern?
- How does the pipeline work?
- What are the key classes and their responsibilities?
- How do metrics flow through the system?

**Design Questions**
- Why use enums for metrics?
- Why have software joins?
- Why post-process computed metrics?
- How does the registry work?

**Implementation Questions**
- How does query building work?
- How are dimensions resolved?
- How are joins found between tables?
- How are dependencies tracked?

**Refactoring Questions**
- What are the bottlenecks?
- Which files are most complex?
- What's the safest way to refactor?
- How to add new features?

**Testing Questions**
- What should be unit tested?
- What are the integration points?
- Which edge cases matter?
- How to test each database?

---

## Related Documents

- **CLAUDE.md** - Project guidelines and design philosophy
- **README.md** - User-facing package documentation
- **Workbench** - Working examples in `workbench/app/Analytics/`

---

## Last Updated

Generated: 2025-11-08
Branch: claude/eloquent-first-architecture-refactor-011CUubNrahxzLDSqJ5RUFLM

---

## How to Use These Documents

1. **For Understanding**: Pick the right document for your level (see "For Different Roles")
2. **For Reference**: Use QUICK_REFERENCE.md and ARCHITECTURE_ANALYSIS.md as lookups
3. **For Planning**: Use QUICK_REFERENCE.md's refactoring roadmap
4. **For Teaching**: Share ARCHITECTURE_SUMMARY.md with team, drill down as questions arise
5. **For Implementation**: Follow QUICK_REFERENCE.md's file organization recommendations

---

Start with **ARCHITECTURE_SUMMARY.md** →
Then choose your path based on your role →
Reference specific details in other documents as needed →
Use **QUICK_REFERENCE.md** when implementing changes

