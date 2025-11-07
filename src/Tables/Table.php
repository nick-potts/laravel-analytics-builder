<?php

namespace NickPotts\Slice\Tables;

use NickPotts\Slice\Schemas\Dimension;

abstract class Table
{
    /**
     * The database table name.
     */
    protected string $table;

    /**
     * Get the table name.
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * Define which dimensions this table supports.
     *
     * @return array<class-string<Dimension>, Dimension>
     */
    public function dimensions(): array
    {
        return [];
    }

    /**
     * Define relationships to other tables.
     *
     * @return array<string, Relation>
     */
    public function relations(): array
    {
        return [];
    }

    /**
     * Define explicit cross-domain joins for tables without FK relationships.
     *
     * @return array<string, CrossJoin>
     */
    public function crossJoins(): array
    {
        return [];
    }

    /**
     * Define a belongs-to relationship.
     */
    protected function belongsTo(string $table, string $foreignKey, ?string $ownerKey = null): BelongsTo
    {
        return new BelongsTo($table, $foreignKey, $ownerKey ?? 'id');
    }

    /**
     * Define a has-many relationship.
     */
    protected function hasMany(string $table, string $foreignKey, ?string $localKey = null): HasMany
    {
        return new HasMany($table, $foreignKey, $localKey ?? 'id');
    }

    /**
     * Define a belongs-to-many relationship.
     */
    protected function belongsToMany(string $table, string $pivotTable, string $foreignKey, string $relatedKey): BelongsToMany
    {
        return new BelongsToMany($table, $pivotTable, $foreignKey, $relatedKey);
    }
}
