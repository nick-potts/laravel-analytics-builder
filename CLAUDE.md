# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

**Slice** is a Laravel package for building type-safe, Filament-inspired analytics queries. It uses a table-centric
architecture where metric enums reference tables, tables define dimensions and relations, and the query engine
automatically resolves joins and builds queries using Laravel's query builder.

## Core Architecture

**Slice** is a cube.js-inspired semantic analytics layer for Laravel. Unlike cube.js (JavaScript-based), Slice is Laravel-native with type-safe PHP 8.1 enums and Eloquent integration.

### Three Core Auto-Features

1. **Auto-Discovery** - `EloquentSchemaProvider` introspects Eloquent models without manual Table classes
2. **Auto-Joins** - `JoinResolver` automatically finds relationship paths via graph traversal (BFS)
3. **Auto-Aggregations** - `QueryBuilder` intelligently generates GROUP BY clauses and joins

### Key Components

- **SchemaProvider** - Pluggable providers for any data source (Eloquent, ClickHouse, APIs, etc.)
- **QueryBuilder** - Builds optimized queries from normalized metrics
- **JoinResolver** - Transitive relationship walking using provider metadata
- **Aggregations** - Sum, Count, Avg with driver-specific SQL compilation

**See:** `/planning/` for detailed documentation

## Testing Strategy

Tests live in `tests/` using Pest:

- Unit tests for engine components (DimensionResolver, JoinResolver, etc.)
- Feature tests for complete query flows
- Use workbench models and database for integration tests
