# Introspector Refactoring: Cast-to-Dimension Mapper Architecture

**Commit:** `ecc53cb`
**Branch:** `dev`
**Status:** ✅ Complete - 83 tests passing (193 assertions)

## Overview

Refactored the introspection system from temporal-only (datetime columns) to a pluggable, extensible cast-to-dimension mapper architecture. This enables analytics on any Eloquent cast type: enums (order status, product category), booleans, strings, and more.

## Key Changes

### 1. New Dimension Classes (3)

**`EnumDimension`** (`src/Schemas/Dimensions/EnumDimension.php`)
- For PHP enum casts (BackedEnum)
- Stores enum class reference and extracted cases
- Enables slicing by order status, product category, etc.

**`BooleanDimension`** (`src/Schemas/Dimensions/BooleanDimension.php`)
- For boolean columns (is_active, is_premium)
- Simple dimension for true/false grouping

**`StringDimension`** (`src/Schemas/Dimensions/StringDimension.php`)
- For generic string/categorical columns (country, region)
- Optionally tracks known values

### 2. Pluggable Mapper Architecture

**`DimensionMapper` interface** (`Introspectors/Dimensions/DimensionMapper.php`)
```php
interface DimensionMapper {
    public function handles(): array;  // Cast types this mapper handles
    public function map(string $column, string $castType): ?Dimension;
}
```

**`DimensionMapperRegistry`** (`Introspectors/Dimensions/DimensionMapperRegistry.php`)
- Manages all mappers
- **No priorities** - enforces strict, non-overlapping handlers
- **Throws on conflicts** - each cast type handled by exactly one mapper
- O(1) lookup via direct array + pattern matching for custom formats

### 3. Four Mapper Implementations

| Mapper | Handles | Returns |
|--------|---------|---------|
| `TimeDimensionMapper` | datetime, date, timestamp (+ immutable variants) | `TimeDimension` |
| `EnumDimensionMapper` | Any PHP enum class (dynamic detection) | `EnumDimension` |
| `BooleanDimensionMapper` | bool, boolean | `BooleanDimension` |
| `StringDimensionMapper` | string | `StringDimension` |

### 4. Expanded CastIntrospector

**Old:** Only discovered temporal columns
**New:** Discovers ALL casts with rich metadata

```php
public function discoverCasts(Model $model): array<string, CastInfo>
```

Returns `CastInfo` objects with:
- `column`: string
- `castType`: string
- `isEnum`: bool
- `isCustom`: bool (implements CastsAttributes)

Backward compatible: `discoverTemporalColumns()` still works.

### 5. Refactored DimensionIntrospector

**Old:**
```php
foreach ($columns as $column => $precision) {
    $dimensions[] = TimeDimension::make($column)->precision($precision);
}
```

**New:**
```php
foreach ($casts as $castInfo) {
    $mapper = $registry->getMapper($castInfo->castType);
    $dimension = $mapper->map($castInfo->column, $castInfo->castType);
}
```

- Uses mapper registry for flexible dimension creation
- Supports all dimension types automatically
- Easy to add new mappers without modifying core logic

### 6. Reorganized Directory Structure

**Before:**
```
Introspectors/
├── CastIntrospector.php
├── CastRegistry.php
├── CastTypes/
├── DimensionIntrospector.php
├── PrimaryKeyIntrospector.php
└── RelationIntrospector.php
```

**After:**
```
Introspectors/
├── Casts/
│   ├── CastIntrospector.php
│   └── CastInfo.php
├── Dimensions/
│   ├── DimensionIntrospector.php
│   ├── DimensionMapper.php (interface)
│   ├── DimensionMapperRegistry.php
│   ├── TimeDimensionMapper.php
│   ├── EnumDimensionMapper.php
│   ├── BooleanDimensionMapper.php
│   └── StringDimensionMapper.php
├── Keys/
│   └── PrimaryKeyIntrospector.php
└── Relations/
    └── RelationIntrospector.php
```

### 7. Mirrored Test Structure

Test directory now mirrors src organization:
```
tests/Unit/Providers/Eloquent/Introspectors/
├── Casts/
│   └── CastIntrospectorTest.php
├── Dimensions/
│   ├── DimensionIntrospectorTest.php
│   ├── DimensionMapperRegistryTest.php
│   ├── TimeDimensionMapperTest.php
│   ├── EnumDimensionMapperTest.php
│   ├── BooleanDimensionMapperTest.php
│   └── StringDimensionMapperTest.php
├── Keys/
│   └── PrimaryKeyIntrospectorTest.php
└── Relations/
    └── RelationIntrospectorTest.php
```

### 8. Real Enum Testing

Created `tests/Support/TestEnum.php` with real enum for testing:
```php
enum TestEnum: string {
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}
```

EnumDimensionMapperTest now validates:
- Enum detection
- Enum case extraction
- EnumDimension creation with actual enum instances

## Architecture Benefits

### ✅ Pluggable
- Add new mappers without modifying existing code
- Easy to support custom cast types

### ✅ Strict
- No ambiguity - each cast type handled by exactly one mapper
- Throws on conflicts instead of silently choosing

### ✅ Extensible
- DimensionMapper interface is simple and clear
- Supports both fixed handlers and dynamic detection (enums)

### ✅ Organized
- Logical directory structure by concern
- Test files mirror source locations
- Clear separation of responsibilities

### ✅ Testable
- Each mapper independently testable
- DimensionMapperRegistry tested for conflict detection
- CastIntrospector tested for both old and new APIs
- Real enum for integration testing

## Test Coverage

### Files Created/Modified

**Source Files:** 11
- 1 Interface (DimensionMapper)
- 1 Registry (DimensionMapperRegistry)
- 4 Mappers (Time, Enum, Boolean, String)
- 3 Dimensions (Enum, Boolean, String)
- 1 Introspector (expanded CastIntrospector)
- 1 DTO (CastInfo)

**Test Files:** 9
- 1 CastIntrospectorTest (3 assertions)
- 1 DimensionIntrospectorTest (8 assertions)
- 1 DimensionMapperRegistryTest (7 assertions)
- 4 Mapper tests (6+3+4+3 assertions)
- 1 TestEnum (real enum)

### Test Quality

**Total:** 83 tests passing (193 assertions)

**Assertion Types:**
- Exact counts: `toHaveCount(2)`, `toBe(0)`
- Instance checks: `toBeInstanceOf(EnumDimension::class)`
- Property validation: `->column()`, `->enumClass()`
- Exception testing: `toThrow(RuntimeException::class)`
- Real data testing: Uses workbench models (Order, OrderItem, Product)

**Strong Assertions:**
- No vague `toBeGreaterThanOrEqual(0)` checks
- No loose `not->toBeEmpty()` assertions
- All tests have specific, concrete expectations

## Backward Compatibility

✅ `CastIntrospector::discoverTemporalColumns()` still works
✅ All existing tests pass
✅ No breaking changes to public APIs

## Migration Path

If adding support for other cast types in the future:

1. Create new Dimension class (if needed)
2. Create new DimensionMapper implementation
3. Register in `DimensionMapperRegistry::__construct()`
4. Add tests

Example:
```php
class NumericDimension implements Dimension { ... }

class NumericDimensionMapper implements DimensionMapper {
    public function handles(): array {
        return ['int', 'integer', 'float', 'decimal'];
    }

    public function map(string $column, string $castType): ?Dimension {
        return NumericDimension::make($column);
    }
}

// Register in DimensionMapperRegistry::__construct()
$this->register(new NumericDimensionMapper());
```

## Files Deleted

- `src/Providers/Eloquent/Introspectors/CastRegistry.php` (replaced by mappers)
- `src/Providers/Eloquent/Introspectors/CastTypes/` directory (replaced by mapper interface)
- Old test files for CastRegistry and CastTypes

## Commits

**Main Refactoring:** `ecc53cb`
- 30 files changed
- 1,079 insertions
- 555 deletions

## Next Steps (Optional)

### Future Enhancements

1. **NumericDimension** - For bucketing numeric ranges
2. **DateDimension** - Separate from TimeDimension for date-only columns
3. **JSONDimension** - For JSON/JSONB columns
4. **Custom Cast Support** - Map CastsAttributes implementations to dimensions
5. **Dimension Filters** - Built-in filtering for enum/boolean/string dimensions

### Testing

- Add tests for edge cases (nullable dimensions, etc.)
- Add performance benchmarks for mapper lookup
- Add integration tests with actual Eloquent models

## Summary

This refactoring transforms the introspection system from a temporal-only implementation to a flexible, extensible cast-to-dimension mapper architecture. It enables type-safe analytics on any Eloquent cast type while maintaining clean, well-organized, and thoroughly tested code.

The strict, non-conflicting handler approach ensures clarity and prevents hard-to-debug issues that could arise from overlapping cast type handling.
