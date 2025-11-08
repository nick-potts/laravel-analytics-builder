# Slice Architecture Diagrams

This document provides comprehensive architectural diagrams showing how the Slice analytics query builder works.

## Table of Contents
- [High-Level System Overview](#high-level-system-overview)
- [Query Execution Flow](#query-execution-flow)
- [Component Interaction Diagram](#component-interaction-diagram)
- [Data Flow Diagram](#data-flow-diagram)
- [Plugin System Architecture](#plugin-system-architecture)
- [Database Driver Architecture](#database-driver-architecture)
- [Metric Type Hierarchy](#metric-type-hierarchy)
- [Query Plan Strategies](#query-plan-strategies)

---

## High-Level System Overview

```mermaid
graph TB
    subgraph "User Interface Layer"
        API["Slice::query()<br/>Facade"]
    end

    subgraph "Core Engine"
        QB[QueryBuilder<br/>Orchestrator]
        QE[QueryExecutor<br/>Execution]
        PP[PostProcessor<br/>Computed Metrics]
    end

    subgraph "Resolution Layer"
        DR[DimensionResolver<br/>Maps dimensions to tables]
        JR[JoinResolver<br/>BFS path finding]
        DepR[DependencyResolver<br/>DFS topological sort]
    end

    subgraph "Data Layer"
        Tables[Tables<br/>Schema definitions]
        Metrics[Metrics<br/>Aggregations]
        Dims[Dimensions<br/>Grouping & filters]
    end

    subgraph "Driver Layer"
        QD[QueryDriver<br/>Interface]
        QA[QueryAdapter<br/>Query builder wrapper]
        QG[QueryGrammar<br/>DB-specific SQL]
    end

    subgraph "Registry & Discovery"
        Reg[Registry<br/>Metric/Table lookup]
        SP[ServiceProvider<br/>Auto-discovery]
    end

    API --> QB
    QB --> DR
    QB --> JR
    QB --> DepR
    QB --> QD
    QB --> QE
    QE --> PP

    QB -.Uses.-> Tables
    QB -.Uses.-> Metrics
    QB -.Uses.-> Dims

    QD --> QA
    QD --> QG

    SP --> Reg
    API --> Reg

    style API fill:#e1f5ff
    style QB fill:#fff4e1
    style QE fill:#fff4e1
    style PP fill:#fff4e1
    style QD fill:#e8f5e9
```

---

## Query Execution Flow

```mermaid
sequenceDiagram
    autonumber
    participant User
    participant Slice
    participant QB as QueryBuilder
    participant DR as DimensionResolver
    participant JR as JoinResolver
    participant DepR as DependencyResolver
    participant QD as QueryDriver
    participant QE as QueryExecutor
    participant PP as PostProcessor
    participant DB as Database

    User->>Slice: query()->metrics([...])->dimensions([...])->get()

    Slice->>Slice: normalizeMetrics()
    Note over Slice: Converts enums/strings to<br/>unified format

    Slice->>QB: build(metrics, dimensions)

    QB->>QB: extractTablesFromMetrics()

    QB->>DepR: splitByComputationStrategy()
    DepR-->>QB: {database: [...], software: [...]}

    alt Multiple drivers or software metrics
        QB->>QB: buildSoftwareJoinPlan()
    else Single driver with CTEs
        QB->>QB: buildWithCTEs()
    else Standard database query
        QB->>QB: buildDatabasePlan()
    end

    QB->>JR: buildJoinGraph(tables)
    JR->>JR: BFS pathfinding
    JR-->>QB: joinPath

    QB->>DR: resolveDimensionForTables()
    DR-->>QB: dimension mappings

    QB->>QD: createQuery(tableName)
    QD-->>QB: QueryAdapter

    QB->>QB: addMetricSelects()
    QB->>QB: addDimensionSelects()
    QB->>QB: addGroupBy()
    QB->>QB: addDimensionFilters()

    QB-->>Slice: QueryPlan

    Slice->>QE: run(plan)

    alt DatabasePlan
        QE->>QD: adapter.execute()
        QD->>DB: SQL Query
        DB-->>QD: Result Set
        QD-->>QE: rows
    else SoftwareJoinPlan
        loop For each table
            QE->>QD: adapter.execute()
            QD->>DB: SQL Query
            DB-->>QD: Result Set
        end
        QE->>QE: performSoftwareJoins()
        QE->>QE: groupSoftwareResults()
        QE-->>QE: merged rows
    end

    QE-->>Slice: rows

    Slice->>PP: process(rows, metrics)
    PP->>PP: normalizeMetricValues()

    alt Has computed metrics
        PP->>PP: groupByLevel()
        loop For each dependency level
            PP->>PP: evaluateExpression()
        end
    end

    PP-->>Slice: ResultCollection
    Slice-->>User: ResultCollection
```

---

## Component Interaction Diagram

```mermaid
graph LR
    subgraph "Metric Definition"
        ME[MetricEnum::Revenue]
        T[OrdersTable]
        M[Sum::make]

        ME -->|table| T
        ME -->|get| M
    end

    subgraph "Table Definition"
        T2[OrdersTable]
        TDims[dimensions]
        TRels[relations]

        T2 --> TDims
        T2 --> TRels

        TDims -->|TimeDimension| TD[created_at]
        TDims -->|CountryDimension| CD[country]

        TRels -->|BelongsTo| CT[CustomersTable]
    end

    subgraph "Query Building"
        QB2[QueryBuilder]
        DR2[DimensionResolver]
        JR2[JoinResolver]

        QB2 --> DR2
        QB2 --> JR2

        DR2 -->|Maps| TDims
        JR2 -->|Traverses| TRels
    end

    subgraph "SQL Generation"
        QA2[QueryAdapter]
        QG2[QueryGrammar]

        QA2 --> QG2

        QG2 -->|MySQL| MG[DATE_FORMAT]
        QG2 -->|PostgreSQL| PG[date_trunc]
        QG2 -->|SQLite| SG[strftime]
    end

    QB2 --> QA2

    style ME fill:#ffe0b2
    style T fill:#c8e6c9
    style M fill:#b3e5fc
    style QB2 fill:#fff9c4
    style QA2 fill:#f8bbd0
```

---

## Data Flow Diagram

```mermaid
flowchart TD
    Start([User Query])

    Start --> N1{Normalize<br/>Metrics}

    N1 -->|Enum| E1[Resolve enum.table<br/>& enum.get]
    N1 -->|Direct Metric| E2[Extract metric.table]
    N1 -->|String| E3[Registry lookup]

    E1 --> Unified
    E2 --> Unified
    E3 --> Unified

    Unified[Unified Format:<br/>enum, table, metric, key]

    Unified --> Extract[Extract Tables]
    Extract --> Strategy{Choose<br/>Strategy}

    Strategy -->|Multiple drivers| SW[Software Join Plan]
    Strategy -->|Single driver + CTEs| CTE[CTE Plan]
    Strategy -->|Standard| DB[Database Plan]

    DB --> BuildJoins[JoinResolver:<br/>BFS pathfinding]
    SW --> BuildJoins
    CTE --> BuildJoins

    BuildJoins --> BuildSQL[Build SQL Query]

    BuildSQL --> MetricSel[Add Metric SELECTs<br/>SUM/COUNT/AVG]
    MetricSel --> DimSel[Add Dimension SELECTs<br/>Time bucketing]
    DimSel --> Group[Add GROUP BY]
    Group --> Filter[Add WHERE filters]
    Filter --> Order[Add ORDER BY]

    Order --> Execute{Execute<br/>Strategy}

    Execute -->|Database| ExecDB[Single SQL query]
    Execute -->|Software| ExecSW[N queries + joins in PHP]
    Execute -->|CTE| ExecCTE[Layered WITH clauses]

    ExecDB --> Rows[Raw Result Rows]
    ExecSW --> Rows
    ExecCTE --> Rows

    Rows --> Post[PostProcessor]

    Post --> Norm[Normalize Values]
    Norm --> Compute{Has Computed<br/>Metrics?}

    Compute -->|Yes| Deps[Group by dependency level]
    Compute -->|No| Results

    Deps --> Eval[Evaluate expressions<br/>per row per level]
    Eval --> Results

    Results[ResultCollection]
    Results --> End([Return to User])

    style Start fill:#e1f5ff
    style Strategy fill:#fff4e1
    style Execute fill:#fff4e1
    style Compute fill:#e8f5e9
    style End fill:#e1f5ff
```

---

## Plugin System Architecture

### Extension Points Overview

```mermaid
graph TB
    subgraph "Core System"
        Core[Slice Core]
    end

    subgraph "Plugin Extension Points"
        P1[Custom Drivers<br/>QueryDriver interface]
        P2[Custom Aggregations<br/>DatabaseMetric interface]
        P3[Custom Dimensions<br/>Dimension base class]
        P4[Custom Query Plans<br/>QueryPlan interface]
        P5[Custom Grammars<br/>QueryGrammar class]
        P6[Macro Extensions<br/>Macroable trait]
        P7[Registry Extensions<br/>Manual registration]
    end

    subgraph "Example Plugins"
        E1[ClickHouse Driver]
        E2[Median Aggregation]
        E3[UserAge Dimension]
        E4[Graph Query Plan]
        E5[TimescaleDB Grammar]
        E6[Custom Formatters]
    end

    Core --> P1
    Core --> P2
    Core --> P3
    Core --> P4
    Core --> P5
    Core --> P6
    Core --> P7

    P1 -.Implements.-> E1
    P2 -.Implements.-> E2
    P3 -.Extends.-> E3
    P4 -.Implements.-> E4
    P5 -.Extends.-> E5
    P6 -.Uses.-> E6

    style Core fill:#e1f5ff
    style P1 fill:#fff4e1
    style P2 fill:#fff4e1
    style P3 fill:#fff4e1
    style P4 fill:#fff4e1
    style P5 fill:#fff4e1
    style P6 fill:#fff4e1
    style P7 fill:#fff4e1
    style E1 fill:#c8e6c9
    style E2 fill:#c8e6c9
    style E3 fill:#c8e6c9
    style E4 fill:#c8e6c9
    style E5 fill:#c8e6c9
    style E6 fill:#c8e6c9
```

### Plugin Lifecycle

```mermaid
sequenceDiagram
    autonumber
    participant Dev as Plugin Developer
    participant SP as ServiceProvider
    participant Reg as Registry
    participant Core as Slice Core
    participant Runtime as Runtime

    Dev->>SP: Create Plugin Package
    Note over Dev,SP: composer require vendor/slice-clickhouse

    SP->>SP: boot()

    alt Custom Driver
        SP->>Core: LaravelQueryDriver::extend('clickhouse', Grammar)
    else Custom Metric
        SP->>Reg: registerMetricEnum(CustomMetric::class)
    else Custom Dimension
        SP->>Reg: registerTable(CustomTable with custom dims)
    else Macro Extension
        SP->>Core: Aggregation::macro('asKilobytes', fn...)
    end

    Runtime->>Core: Slice::query()->metrics([...])->get()
    Core->>Reg: Lookup registered components
    Reg-->>Core: Plugin components
    Core->>Runtime: Execute with plugin extensions
```

---

## Database Driver Architecture

```mermaid
classDiagram
    class QueryDriver {
        <<interface>>
        +name() string
        +createQuery(?table) QueryAdapter
        +grammar() QueryGrammar
        +supportsDatabaseJoins() bool
        +supportsCTEs() bool
    }

    class QueryAdapter {
        <<interface>>
        +selectRaw(expr)
        +join(table, first, op, second, type)
        +groupBy(column)
        +groupByRaw(expr)
        +where(column, op, value)
        +execute() array
        +getNative() mixed
    }

    class QueryGrammar {
        <<abstract>>
        +formatTimeBucket(table, column, granularity) string
        +compileSelect(query) string
        +compileJoin(join) string
    }

    class LaravelQueryDriver {
        -string connection
        -array grammars
        +extend(driver, grammar)$
        +resolveGrammar() QueryGrammar
    }

    class LaravelQueryAdapter {
        -Builder native
        +selectRaw(expr)
        +join(...)
        +execute() array
    }

    class MySqlGrammar {
        +formatTimeBucket() string
    }

    class PostgresGrammar {
        +formatTimeBucket() string
    }

    class SqliteGrammar {
        +formatTimeBucket() string
    }

    class SqlServerGrammar {
        +formatTimeBucket() string
    }

    class ClickHouseGrammar {
        +formatTimeBucket() string
    }

    QueryDriver <|.. LaravelQueryDriver
    QueryAdapter <|.. LaravelQueryAdapter
    QueryGrammar <|-- MySqlGrammar
    QueryGrammar <|-- PostgresGrammar
    QueryGrammar <|-- SqliteGrammar
    QueryGrammar <|-- SqlServerGrammar
    QueryGrammar <|-- ClickHouseGrammar

    LaravelQueryDriver --> QueryGrammar
    LaravelQueryDriver --> LaravelQueryAdapter

    note for ClickHouseGrammar "Plugin Example:<br/>Third-party driver"
```

---

## Metric Type Hierarchy

```mermaid
classDiagram
    class MetricContract {
        <<interface>>
        +get() Metric
    }

    class Metric {
        <<interface>>
        +key() string
        +toArray() array
    }

    class DatabaseMetric {
        <<interface>>
        +applyToQuery(QueryAdapter, QueryDriver, table, alias)
    }

    class Aggregation {
        <<abstract>>
        #string column
        #string table
        +make(column)$ static
        +currency(code) this
        +decimals(n) this
        +label(label) this
        +aggregationType() string
    }

    class Sum {
        +aggregationType() "sum"
        +applyToQuery()
    }

    class Count {
        +aggregationType() "count"
        +applyToQuery()
    }

    class Avg {
        +aggregationType() "avg"
        +applyToQuery()
    }

    class Min {
        +aggregationType() "min"
        +applyToQuery()
    }

    class Max {
        +aggregationType() "max"
        +applyToQuery()
    }

    class Percentile {
        -float percentile
        +compiler(driver, fn)$
        +applyToQuery()
    }

    class Computed {
        -string expression
        -array dependencies
        +dependsOn(...keys) this
        +forTable(table) this
    }

    class OrdersMetric {
        <<enumeration>>
        Revenue
        ItemCost
        Profit
        +table() Table
        +get() Metric
    }

    MetricContract <|.. Metric
    Metric <|.. DatabaseMetric
    Metric <|.. Computed
    DatabaseMetric <|.. Aggregation
    Aggregation <|-- Sum
    Aggregation <|-- Count
    Aggregation <|-- Avg
    Aggregation <|-- Min
    Aggregation <|-- Max
    Aggregation <|-- Percentile

    MetricContract <|.. OrdersMetric
    OrdersMetric ..> Aggregation : uses
    OrdersMetric ..> Computed : uses

    note for Percentile "Plugin Extension:<br/>Custom compiler registry"
    note for Computed "Post-processing metric:<br/>Calculated in PHP or CTE"
```

---

## Query Plan Strategies

```mermaid
stateDiagram-v2
    [*] --> AnalyzeQuery

    AnalyzeQuery --> CountTables: Extract tables from metrics

    CountTables --> CheckDriver: Determine driver capabilities

    state CheckDriver {
        [*] --> MultipleDrivers
        MultipleDrivers --> UseSoftwareJoin: Different drivers
        MultipleDrivers --> SingleDriver: Same driver

        SingleDriver --> CheckJoinSupport
        CheckJoinSupport --> SupportsJoins: supportsDatabaseJoins() = true
        CheckJoinSupport --> NoJoinSupport: supportsDatabaseJoins() = false

        SupportsJoins --> CheckComputed
        NoJoinSupport --> UseSoftwareJoin

        CheckComputed --> HasComputed: Has computed metrics
        CheckComputed --> NoComputed: Only direct aggregations

        HasComputed --> CheckCTE
        NoComputed --> UseDatabasePlan

        CheckCTE --> SupportsCTE: supportsCTEs() = true
        CheckCTE --> UseDatabasePlan: supportsCTEs() = false

        SupportsCTE --> UseCTEPlan
    }

    UseDatabasePlan --> BuildDatabasePlan
    UseCTEPlan --> BuildCTEPlan
    UseSoftwareJoin --> BuildSoftwareJoinPlan

    state BuildDatabasePlan {
        [*] --> BFS_Joins
        BFS_Joins --> AddMetrics
        AddMetrics --> AddDimensions
        AddDimensions --> AddGroupBy
        AddGroupBy --> AddFilters
        AddFilters --> AddOrderBy
        AddOrderBy --> [*]
    }

    state BuildCTEPlan {
        [*] --> TopologicalSort
        TopologicalSort --> GroupByLevel
        GroupByLevel --> BuildBaseCTE
        BuildBaseCTE --> BuildComputedCTEs
        BuildComputedCTEs --> BuildFinalSelect
        BuildFinalSelect --> [*]
    }

    state BuildSoftwareJoinPlan {
        [*] --> AnalyzeRelations
        AnalyzeRelations --> CreateTablePlans
        CreateTablePlans --> StoreJoinMetadata
        StoreJoinMetadata --> [*]
    }

    BuildDatabasePlan --> Execute
    BuildCTEPlan --> Execute
    BuildSoftwareJoinPlan --> Execute

    state Execute {
        [*] --> DatabaseExec: DatabasePlan
        [*] --> SoftwareExec: SoftwareJoinPlan

        DatabaseExec --> SingleQuery
        SingleQuery --> DatabaseRows

        SoftwareExec --> MultipleQueries
        MultipleQueries --> HashJoin
        HashJoin --> GroupInPHP
        GroupInPHP --> SoftwareRows

        DatabaseRows --> [*]
        SoftwareRows --> [*]
    }

    Execute --> PostProcess

    state PostProcess {
        [*] --> NormalizeValues
        NormalizeValues --> CheckComputed2
        CheckComputed2 --> EvaluateExpressions: Has computed
        CheckComputed2 --> ReturnResults: No computed
        EvaluateExpressions --> ReturnResults
        ReturnResults --> [*]
    }

    PostProcess --> [*]
```

---

## Join Resolution (BFS Algorithm)

```mermaid
graph TD
    Start([JoinResolver::buildJoinGraph])

    Start --> Init[Initialize:<br/>queue = primaryTable<br/>visited = set<br/>joinPath = array]

    Init --> Queue{Queue<br/>empty?}

    Queue -->|Yes| Complete[Return joinPath]
    Queue -->|No| Dequeue[current = queue.shift]

    Dequeue --> GetRels[Get current.relations]

    GetRels --> ForEach{For each<br/>relation}

    ForEach --> CheckVisited{Target table<br/>visited?}

    CheckVisited -->|Yes| ForEach
    CheckVisited -->|No| AddPath[Add to joinPath:<br/>from, to, relation]

    AddPath --> MarkVisited[Mark target as visited]
    MarkVisited --> Enqueue[Add target to queue]
    Enqueue --> ForEach

    ForEach -->|Done| Queue

    Complete --> Apply[applyJoins to QueryAdapter]

    Apply --> Loop{For each<br/>join in path}

    Loop --> Type{Relation<br/>type}

    Type -->|BelongsTo| BT[query.join:<br/>from.foreignKey = to.ownerKey]
    Type -->|HasMany| HM[query.join:<br/>from.localKey = to.foreignKey]
    Type -->|BelongsToMany| BTM[query.join:<br/>through pivot table]

    BT --> Loop
    HM --> Loop
    BTM --> Loop

    Loop -->|Done| End([Return query])

    style Start fill:#e1f5ff
    style Complete fill:#c8e6c9
    style End fill:#e1f5ff
```

---

## Dimension Resolution

```mermaid
flowchart TD
    Start([resolveDimensionForTables])

    Start --> Input[Input:<br/>tables, dimension]

    Input --> Loop{For each<br/>table}

    Loop --> Check{Table has<br/>dimension class?}

    Check -->|No| Loop
    Check -->|Yes| GetInstance[Get dimension instance<br/>from table.dimensions]

    GetInstance --> ValidateType{Dimension<br/>type?}

    ValidateType -->|TimeDimension| ValidateGranularity
    ValidateType -->|Other| StoreMapping

    ValidateGranularity --> CheckMin{Input granularity >= min?}
    CheckMin -->|No| Error[Throw:<br/>Granularity too fine]
    CheckMin -->|Yes| StoreMapping

    StoreMapping[Store mapping:<br/>tableName => column]

    StoreMapping --> Loop

    Loop -->|Done| Return[Return mappings]

    Return --> Usage[Usage in QueryBuilder]

    Usage --> Select[SELECT:<br/>formatTimeBucket or raw column]
    Usage --> GroupBy[GROUP BY:<br/>formatTimeBucket or raw column]
    Usage --> Where[WHERE:<br/>Apply filters]

    style Start fill:#e1f5ff
    style Error fill:#ffcdd2
    style Return fill:#c8e6c9
```

---

## Computed Metrics Dependency Resolution

```mermaid
graph TB
    Start([DependencyResolver::groupByLevel])

    Start --> Build[Build dependency graph]

    Build --> Graph{For each<br/>metric}

    Graph -->|Computed| Extract[Extract dependencies from expression]
    Graph -->|Direct| Level0[Assign to level 0]

    Extract --> AddEdge[Add edges:<br/>metric -> dependencies]
    AddEdge --> Graph

    Graph -->|Done| DFS[DFS Topological Sort]

    DFS --> Visit{For each<br/>unvisited node}

    Visit --> Visiting[Mark as visiting]
    Visiting --> VisitDeps{For each<br/>dependency}

    VisitDeps --> CheckState{Dep state?}

    CheckState -->|Visiting| Cycle[Throw:<br/>Circular dependency]
    CheckState -->|Unvisited| Recurse[Recursively visit]
    CheckState -->|Visited| VisitDeps

    Recurse --> VisitDeps

    VisitDeps -->|Done| Visited[Mark as visited]
    Visited --> CalcLevel[Calculate level:<br/>max(dep levels) + 1]
    CalcLevel --> Visit

    Visit -->|Done| GroupLevels[Group metrics by level]

    GroupLevels --> Output[Output:<br/>level 0: [revenue, cost]<br/>level 1: [profit]]

    Output --> Usage{Usage}

    Usage -->|Database CTEs| CTE[WITH level_0 AS ...<br/>WITH level_1 AS ...]
    Usage -->|PostProcessor| PHP[For each level:<br/>evaluate expressions]

    style Start fill:#e1f5ff
    style Cycle fill:#ffcdd2
    style Output fill:#c8e6c9
```

---

## Registry & Auto-Discovery

```mermaid
sequenceDiagram
    autonumber
    participant Laravel
    participant SP as SliceServiceProvider
    participant Reg as Registry
    participant FS as Filesystem
    participant Enum as MetricEnum

    Laravel->>SP: boot()

    SP->>SP: registerMetricEnums()

    alt Config exists
        SP->>SP: config('slice.metric_enums')
    else Auto-discover
        SP->>SP: discoverMetricEnums()
        SP->>FS: RecursiveDirectoryIterator(app/Analytics)

        loop For each *Metric.php file
            FS-->>SP: file path
            SP->>SP: Extract namespace & class
            SP->>SP: Check enum_exists()
        end

        FS-->>SP: enum classes
    end

    SP->>Reg: registerMetricEnum(EnumClass)

    Reg->>Enum: EnumClass::cases()
    Enum-->>Reg: [Revenue, Cost, ...]

    loop For each case
        Reg->>Enum: case.table()
        Enum-->>Reg: OrdersTable

        Reg->>Enum: case.get()
        Enum-->>Reg: Sum::make(...)

        Reg->>Reg: Store:<br/>key => enum<br/>table => Table<br/>metric => Metric
    end

    Note over Reg: Registry now contains:<br/>orders.revenue => OrdersMetric::Revenue<br/>orders.cost => OrdersMetric::Cost

    Reg-->>SP: Registered
    SP-->>Laravel: Boot complete
```

---

## Software Join Execution

```mermaid
flowchart TD
    Start([SoftwareJoinQueryPlan.execute])

    Start --> Init[Initialize:<br/>tableResults = map<br/>joinedData = array]

    Init --> ExecuteQueries{For each<br/>table plan}

    ExecuteQueries --> RunQuery[Execute query for table]
    RunQuery --> StoreResults[Store results in tableResults]
    StoreResults --> ExecuteQueries

    ExecuteQueries -->|Done| GetPrimary[Get primary table results]

    GetPrimary --> LoopPrimary{For each<br/>primary row}

    LoopPrimary --> LoopRelations{For each<br/>relation}

    LoopRelations --> GetType{Relation<br/>type}

    GetType -->|BelongsTo| HashLookup1[Hash lookup:<br/>foreignKey -> ownerKey]
    GetType -->|HasMany| HashLookup2[Hash lookup:<br/>localKey -> foreignKey<br/>aggregate results]

    HashLookup1 --> MergeRow[Merge joined row data]
    HashLookup2 --> MergeRow

    MergeRow --> LoopRelations

    LoopRelations -->|Done| AddJoined[Add to joinedData]
    AddJoined --> LoopPrimary

    LoopPrimary -->|Done| ApplyFilters[Apply dimension filters<br/>in PHP]

    ApplyFilters --> GroupResults[Group by dimensions<br/>in PHP]

    GroupResults --> AggregateMetrics[Aggregate metrics<br/>SUM/COUNT/AVG in PHP]

    AggregateMetrics --> Return[Return rows]

    style Start fill:#e1f5ff
    style Return fill:#c8e6c9
```

---

## Time Dimension Bucketing

```mermaid
graph TB
    subgraph "User Query"
        UQ[TimeDimension::make<br/>granularity: 'day']
    end

    subgraph "QueryBuilder"
        QB[buildTimeDimensionSelect]
        QB --> Driver[driver.grammar]
    end

    subgraph "Grammar Selection"
        Driver --> Detect{Detect<br/>database}

        Detect -->|mysql| MySQL
        Detect -->|pgsql| Postgres
        Detect -->|sqlite| SQLite
        Detect -->|sqlsrv| SQLServer
        Detect -->|clickhouse| ClickHouse
    end

    subgraph "SQL Generation"
        MySQL[MySqlGrammar]
        Postgres[PostgresGrammar]
        SQLite[SqliteGrammar]
        SQLServer[SqlServerGrammar]
        ClickHouse[ClickHouseGrammar]

        MySQL -->|daily| M1["DATE(orders.created_at)"]
        MySQL -->|hourly| M2["DATE_FORMAT(..., '%Y-%m-%d %H:00:00')"]
        MySQL -->|monthly| M3["DATE_FORMAT(..., '%Y-%m')"]

        Postgres -->|daily| P1["date_trunc('day', orders.created_at)"]
        Postgres -->|hourly| P2["date_trunc('hour', orders.created_at)"]
        Postgres -->|monthly| P3["date_trunc('month', orders.created_at)"]

        SQLite -->|daily| S1["date(orders.created_at)"]
        SQLite -->|hourly| S2["strftime('%Y-%m-%d %H:00:00', ...)"]
        SQLite -->|monthly| S3["strftime('%Y-%m-01', ...)"]

        SQLServer -->|daily| SS1["CAST(orders.created_at AS DATE)"]
        SQLServer -->|hourly| SS2["DATEADD(hour, DATEDIFF(hour, 0, ...), 0)"]
        SQLServer -->|monthly| SS3["DATEADD(month, DATEDIFF(month, 0, ...), 0)"]

        ClickHouse -->|daily| C1["toDate(orders.created_at)"]
        ClickHouse -->|hourly| C2["toStartOfHour(orders.created_at)"]
        ClickHouse -->|monthly| C3["toStartOfMonth(orders.created_at)"]
    end

    UQ --> QB

    style UQ fill:#e1f5ff
    style ClickHouse fill:#c8e6c9

    note1[Plugin Example]
    ClickHouse -.-> note1
```

---

## Complete Example Query Flow

```mermaid
graph TD
    Start([User writes query])

    Start --> Code["Slice::query()<br/>.metrics([<br/>  OrdersMetric::Revenue,<br/>  Sum::make('orders.shipping')<br/>])<br/>.dimensions([<br/>  TimeDimension::make('created_at')->daily(),<br/>  CountryDimension::make()->only(['US', 'AU'])<br/>])<br/>.get()"]

    Code --> Normalize[Normalize Metrics:<br/>Revenue -> Sum::make('orders.total')->currency('USD')<br/>Shipping -> Sum::make('orders.shipping')]

    Normalize --> Extract[Extract Tables:<br/>OrdersTable]

    Extract --> Strategy{Choose Strategy:<br/>1 table, no computed metrics}

    Strategy --> BuildDB[DatabaseQueryPlan]

    BuildDB --> BuildQuery[Build SQL Query]

    BuildQuery --> Select["SELECT<br/>  DATE(orders.created_at) as orders_created_at_day,<br/>  orders.country as orders_country,<br/>  SUM(orders.total) as orders_total,<br/>  SUM(orders.shipping) as orders_shipping"]

    Select --> From["FROM orders"]

    From --> Where["WHERE<br/>  orders.country IN ('US', 'AU')"]

    Where --> GroupBy["GROUP BY<br/>  DATE(orders.created_at),<br/>  orders.country"]

    GroupBy --> OrderBy["ORDER BY<br/>  DATE(orders.created_at),<br/>  orders.country"]

    OrderBy --> Execute[Execute Query]

    Execute --> Results["Results:<br/>[<br/>  {<br/>    orders_created_at_day: '2024-01-01',<br/>    orders_country: 'AU',<br/>    orders_total: 15000.00,<br/>    orders_shipping: 450.00<br/>  },<br/>  ...<br/>]"]

    Results --> PostProcess[PostProcessor:<br/>Normalize values]

    PostProcess --> Collection[ResultCollection]

    Collection --> End([Return to user])

    style Start fill:#e1f5ff
    style Code fill:#fff3e0
    style Collection fill:#c8e6c9
    style End fill:#e1f5ff
```

---

