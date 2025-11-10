<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use NickPotts\Slice\Schemas\Relations\RelationDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Schemas\Relations\RelationType;

/**
 * Introspects Eloquent model relations.
 *
 * Uses reflection to find relation methods and parses their source code
 * to extract related model information without needing to invoke them.
 */
class RelationIntrospector
{
    /**
     * Extract all relations from a model using reflection.
     *
     * We analyze return type hints and parse method source code to detect relations
     * without needing to instantiate or invoke the relation methods.
     */
    public function introspect(string $modelClass, \ReflectionClass $reflection): RelationGraph
    {
        $relations = [];
        $file = $reflection->getFileName();
        if (! $file) {
            return new RelationGraph($relations);
        }

        $fileContent = file_get_contents($file);
        if (! $fileContent) {
            return new RelationGraph($relations);
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->shouldSkipMethod($method)) {
                continue;
            }

            if (! $this->isRelationMethod($method)) {
                continue;
            }

            try {
                $descriptor = $this->extractRelationFromMethod(
                    $method,
                    $fileContent,
                    $reflection->getNamespaceName()
                );

                if ($descriptor) {
                    $relations[$method->getName()] = $descriptor;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return new RelationGraph($relations);
    }

    /**
     * Extract relation descriptor from method by analyzing its source code.
     *
     * This avoids needing to instantiate the model with a database connection.
     */
    private function extractRelationFromMethod(
        \ReflectionMethod $method,
        string $fileContent,
        string $modelNamespace
    ): ?RelationDescriptor {
        $returnTypeName = $method->getReturnType()?->getName();

        if (! $returnTypeName) {
            return null;
        }

        // Get method source lines
        $lines = explode("\n", $fileContent);
        $startLine = $method->getStartLine() - 1;
        $endLine = $method->getEndLine();
        $methodSource = implode("\n", array_slice($lines, $startLine, $endLine - $startLine));

        // Extract related model from the relation call
        $relatedModel = $this->extractRelatedModelFromSource($methodSource, $modelNamespace);
        if (! $relatedModel) {
            return null;
        }

        // Map return type to relation type and create descriptor
        return match ($returnTypeName) {
            BelongsTo::class => new RelationDescriptor(
                name: $method->getName(),
                type: RelationType::BelongsTo,
                targetTableIdentifier: $relatedModel,
                keys: [
                    'foreign' => $method->getName().'_id',
                    'owner' => 'id',
                ],
            ),
            HasMany::class => new RelationDescriptor(
                name: $method->getName(),
                type: RelationType::HasMany,
                targetTableIdentifier: $relatedModel,
                keys: [
                    'foreign' => str_replace('_id', '', $method->getName()).'_id',
                    'local' => 'id',
                ],
            ),
            HasOne::class => new RelationDescriptor(
                name: $method->getName(),
                type: RelationType::HasOne,
                targetTableIdentifier: $relatedModel,
                keys: [
                    'foreign' => str_replace('_id', '', $method->getName()).'_id',
                    'local' => 'id',
                ],
            ),
            BelongsToMany::class => new RelationDescriptor(
                name: $method->getName(),
                type: RelationType::BelongsToMany,
                targetTableIdentifier: $relatedModel,
                keys: [
                    'foreign' => 'id',
                    'related' => 'id',
                ],
            ),
            default => null,
        };
    }

    /**
     * Extract the related model class from method source.
     *
     * Looks for patterns like hasMany(OrderItem::class) or belongsTo('App\Models\User')
     */
    private function extractRelatedModelFromSource(string $source, string $modelNamespace): ?string
    {
        // Try to match relation method calls with class references
        // Pattern: belongsTo|hasMany|hasOne|belongsToMany\s*\(\s*([\w\\:]+)
        if (preg_match('/(?:belongsTo|hasMany|hasOne|belongsToMany)\s*\(\s*([\\w\\\\]+)/', $source, $matches)) {
            $modelName = $matches[1];

            // Remove ::class suffix if present
            $modelName = str_replace('::class', '', $modelName);

            // If it's a relative class name (no namespace separator), resolve it relative to the current namespace
            if (! str_contains($modelName, '\\')) {
                // Model class defined in the same namespace
                return $modelNamespace.'\\'.$modelName;
            }

            return $modelName;
        }

        // Try quoted string pattern
        if (preg_match('/(?:belongsTo|hasMany|hasOne|belongsToMany)\s*\(\s*[\'"]([\\w\\\\]+)[\'"]/', $source, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function shouldSkipMethod(\ReflectionMethod $method): bool
    {
        // Skip inherited methods from Model class
        if ($method->class === Model::class || $method->class === 'Illuminate\\Database\\Eloquent\\Model') {
            return true;
        }

        // Skip methods with required parameters
        return $method->getNumberOfRequiredParameters() > 0;
    }

    private function isRelationMethod(\ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();
        if ($returnType === null) {
            return false;
        }

        if (! $returnType instanceof \ReflectionNamedType) {
            return false;
        }

        $returnTypeName = $returnType->getName();

        return in_array($returnTypeName, [
            BelongsTo::class,
            HasMany::class,
            HasOne::class,
            BelongsToMany::class,
            \Illuminate\Database\Eloquent\Relations\MorphTo::class,
            \Illuminate\Database\Eloquent\Relations\MorphMany::class,
            \Illuminate\Database\Eloquent\Relations\MorphOne::class,
        ]);
    }

    /**
     * Convert an Eloquent relation instance to a RelationDescriptor.
     *
     * This is kept for backward compatibility if/when we can invoke relations.
     */
    public function convert(string $name, Relation $relation): ?RelationDescriptor
    {
        $relatedModel = get_class($relation->getRelated());

        if ($relation instanceof BelongsTo) {
            return new RelationDescriptor(
                name: $name,
                type: RelationType::BelongsTo,
                targetTableIdentifier: $relatedModel,
                keys: [
                    'foreign' => $relation->getForeignKeyName(),
                    'owner' => $relation->getOwnerKeyName(),
                ],
            );
        }

        if ($relation instanceof HasMany) {
            return new RelationDescriptor(
                name: $name,
                type: RelationType::HasMany,
                targetTableIdentifier: $relatedModel,
                keys: [
                    'foreign' => $relation->getForeignKeyName(),
                    'local' => $relation->getLocalKeyName(),
                ],
            );
        }

        if ($relation instanceof HasOne) {
            return new RelationDescriptor(
                name: $name,
                type: RelationType::HasOne,
                targetTableIdentifier: $relatedModel,
                keys: [
                    'foreign' => $relation->getForeignKeyName(),
                    'local' => $relation->getLocalKeyName(),
                ],
            );
        }

        if ($relation instanceof BelongsToMany) {
            return new RelationDescriptor(
                name: $name,
                type: RelationType::BelongsToMany,
                targetTableIdentifier: $relatedModel,
                keys: [
                    'foreign' => $relation->getForeignPivotKeyName(),
                    'related' => $relation->getRelatedPivotKeyName(),
                ],
                pivot: $relation->getTable(),
            );
        }

        return null;
    }
}
