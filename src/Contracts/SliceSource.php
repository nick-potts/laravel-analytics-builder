<?php

namespace NickPotts\Slice\Contracts;

use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

/**
 * Contract for a Slice data source.
 *
 * SliceSources describe how to talk to the underlying storage (provider +
 * connection) and expose the metadata needed to build Slice manifests.
 */
interface SliceSource
{
    /**
     * Globally unique identifier for this slice source (e.g. 'eloquent:orders').
     */
    public function identifier(): string;

    /**
     * Logical identifier for the source (e.g. 'orders').
     */
    public function name(): string;

    /**
     * Provider responsible for handling the source (e.g. 'eloquent', 'manual').
     */
    public function provider(): string;

    /**
     * Concrete connection/adapter identifier (e.g. 'eloquent:mysql').
     */
    public function connection(): string;

    /**
     * Physical table/view backing the source, if any.
     */
    public function sqlTable(): ?string;

    /**
     * Raw SQL definition when the source is expressed as a query instead of a table.
     */
    public function sql(): ?string;

    /**
     * Relation metadata (used for join pre-baking).
     */
    public function relations(): RelationGraph;

    /**
     * Dimension catalog exposed by this source.
     */
    public function dimensions(): DimensionCatalog;

    /**
     * Arbitrary metadata (title, description, flags, etc).
     *
     * @return array<mixed>
     */
    public function meta(): array;
}
