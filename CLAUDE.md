# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

**Slice** is a Laravel package for building type-safe, Filament-inspired analytics queries. It uses a table-centric
architecture where metric enums reference tables, tables define dimensions and relations, and the query engine
automatically resolves joins and builds queries using Laravel's query builder.

## Core Architecture

currently being built. see /planning

## Testing Strategy

Tests live in `tests/` using Pest:

- Unit tests for engine components (DimensionResolver, JoinResolver, etc.)
- Feature tests for complete query flows
- Use workbench models and database for integration tests
