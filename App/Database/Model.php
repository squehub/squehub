<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Casts\AttributeCaster;
use App\Database\Exception\QueryException;
use App\Database\Exception\ScopeException;
use App\Database\Relations\BelongsTo;
use App\Database\Relations\BelongsToMany;
use App\Database\Relations\HasMany;
use App\Database\Relations\HasManyThrough;
use App\Database\Relations\HasOne;
use App\Database\Relations\HasOneThrough;
use App\Database\Relations\MorphMany;
use App\Database\Relations\MorphOne;
use App\Database\Relations\MorphTo;
use App\Database\Relations\Relation;
use App\Database\Relations\RelationException;
use App\Database\Relations\RelationLoader;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * An individual v2 record with a snapshot of its last successful local write.
 *
 * A save inside a caller-owned transaction synchronizes this local snapshot,
 * but a later outer rollback cannot restore this object automatically.
 * App\Core\Model remains the separate v1 static array API.
 * Subclasses must remain constructible without required arguments: queries,
 * hydration, and relationship metadata create blank instances.
 *
 * @phpstan-consistent-constructor
 */
abstract class Model implements JsonSerializable
{
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected ?string $connection = null;
    /** @var list<string> */
    protected array $fillable = [];
    /** @var list<string> */
    protected array $guarded = ['*'];
    /** @var list<string> Additional attributes to omit from arrays and JSON. */
    protected array $hidden = [];
    /** @var array<string, string> Explicit conversions; undeclared attributes keep driver values. */
    protected array $casts = [];
    protected bool $timestamps = false;
    protected ?string $createdAtColumn = 'created_at';
    protected ?string $updatedAtColumn = 'updated_at';
    protected bool $softDeletes = false;
    protected string $deletedAtColumn = 'deleted_at';

    /** @return array<string, mixed> Declarations are validated by namedScope(). */
    protected static function scopes(): array
    {
        return [];
    }

    /** @return array<string, callable> Explicit read transforms, keyed by attribute name. */
    protected static function accessors(): array
    {
        return [];
    }

    /** @return array<string, callable> Explicit assignment transforms, keyed by attribute name. */
    protected static function mutators(): array
    {
        return [];
    }

    /** @internal Resolve an explicitly declared scope without method-name magic. */
    public static function namedScope(string $name): callable
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1) {
            throw new ScopeException("Scope name for " . static::class . " is invalid.");
        }
        $scopes = static::scopes();
        if (!array_key_exists($name, $scopes)) {
            throw new ScopeException("Scope " . static::class . "::{$name} is not defined.");
        }
        if (!is_callable($scopes[$name])) {
            throw new ScopeException("Scope " . static::class . "::{$name} is not callable.");
        }
        return $scopes[$name];
    }

    /** @var array<string, mixed> Canonical current values, including only loaded or assigned fields. */
    private array $attributes = [];
    /** @var array<string, mixed> Canonical values after hydration or the last successful SQL statement. */
    private array $original = [];
    /** @var array<string, int> Correlated query counts are readable but never part of persistence state. */
    private array $queryProjections = [];
    /** @var array<string, mixed> Loaded values never participate in persistence. */
    private array $relations = [];
    /** @var array<string, mixed>|null Metadata for one many-to-many edge, never persisted with this model. */
    private ?array $pivotData = null;
    /** @var array<string, true> Caller assignments since the last successful write or refresh. */
    private array $assigned = [];
    /** @var array<string, mixed> Values written by the most recent confirmed persistence operation. */
    private array $savedChanges = [];
    /** @var array<string, true> Prevent a transformer from reading or assigning itself recursively. */
    private array $activeAccessors = [];
    /** @var array<string, true> */
    private array $activeMutators = [];
    private bool $exists = false;
    private bool $deleted = false;
    private bool $insertOutcomeUncertain = false;
    private ?Connection $persistedConnection = null;
    /** Keep the originating Application manager alive while this Model is alive. */
    private ?DatabaseManager $persistedManager = null;
    private ?ModelClock $persistedClock = null;
    private ?string $persistedTable = null;
    private ?string $persistedKeyName = null;
    /** Prevent an observer from recursively writing the same instance. */
    private bool $lifecycleOperationActive = false;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->assertMetadata();
        $this->fill($attributes);
    }

    public static function query(): ModelQuery
    {
        return static::queryOn(Database::manager());
    }

    /** @internal Build a related query against the originating Application. */
    public static function queryOn(DatabaseManager $manager): ModelQuery
    {
        $model = new static();
        $connection = $manager->connection($model->connectionName());
        $table = $model->tableName();

        return new ModelQuery(
            static::class,
            $connection->table($table),
            $model->primaryKeyName(),
            $connection,
            $manager->clock(),
            $table,
            $model->softDeletes ? $model->deletedAtColumn : null,
            $manager
        );
    }

    public static function find(int|string $id): ?static
    {
        /** @var static|null $model */
        $model = static::query()->filter((new static())->primaryKeyName(), $id)->first();

        return $model;
    }

    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Build a clean record from trusted database data without mass assignment.
     * The supplied connection and table retain the query's identity without opening PDO.
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(
        array $row,
        ?Connection $connection = null,
        ?ModelClock $clock = null,
        ?string $table = null,
        ?DatabaseManager $manager = null
    ): static {
        $model = new static();
        $values = [];
        foreach ($row as $name => $value) {
            $model->assertAttributeName($name);
            $values[$name] = $model->fromStorage($name, $value);
        }

        $table ??= $model->tableName();
        Identifier::table($table);
        $model->attributes = $values;
        $model->original = $values;
        $model->exists = true;
        $model->persistedConnection = $connection;
        $model->persistedManager = $manager ?? $connection?->owner();
        $model->persistedClock = $clock;
        $model->persistedTable = $table;
        $model->persistedKeyName = $model->primaryKeyName();

        return $model;
    }

    /** @param array<string, mixed> $attributes */
    public function fill(array $attributes): static
    {
        // Validate and normalize the complete call before publishing any assignment.
        $staged = [];
        foreach ($attributes as $name => $value) {
            $this->assertAttributeName($name);
            $this->assertNoProjectionWrite($name);
            $this->assertWritableAttribute($name);
            if (!$this->isFillable($name)) {
                throw new InvalidArgumentException("Model attribute '{$name}' is not fillable.");
            }
            $staged[$name] = $this->fromInput($name, $this->mutateAttribute($name, $value));
        }

        foreach ($staged as $name => $value) {
            $this->attributes[$name] = $value;
            $this->assigned[$name] = true;
        }

        return $this;
    }

    public function getAttribute(string $name): mixed
    {
        if (array_key_exists($name, $this->queryProjections)) {
            return $this->queryProjections[$name];
        }
        return $this->presentAttribute($name, $this->attributes[$name] ?? null);
    }

    /** @internal Count projections are intentionally absent from original, dirty and save state. */
    public function setQueryProjection(string $name, int $value): static
    {
        $this->assertAttributeName($name);
        if (array_key_exists($name, $this->attributes)
            || array_key_exists($name, $this->queryProjections)
            || array_key_exists($name, $this->casts)
            || in_array($name, $this->fillable, true)
            || $this->accessorFor($name) !== null
            || array_key_exists($name, static::mutators())
            || $this->hasRelationMethod($name)) {
            throw new LogicException('A relation count alias conflicts with a Model attribute or relation.');
        }
        $this->queryProjections[$name] = $value;
        return $this;
    }

    private function assertNoProjectionWrite(string $name): void
    {
        if (array_key_exists($name, $this->queryProjections)) {
            throw new LogicException('A query-derived relation count cannot be assigned or saved.');
        }
    }

    /** Read canonical casted state without an accessor or an implicit database query. */
    public function rawAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function setAttribute(string $name, mixed $value): static
    {
        $this->assertAttributeName($name);
        $this->assertNoProjectionWrite($name);
        $this->assertWritableAttribute($name);
        $this->attributes[$name] = $this->fromInput($name, $this->mutateAttribute($name, $value));
        $this->assigned[$name] = true;

        return $this;
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->queryProjections)) {
            return $this->queryProjections[$name];
        }
        if (array_key_exists($name, $this->attributes)) {
            return $this->getAttribute($name);
        }
        if ($this->relationLoaded($name)) {
            return $this->relations[$name];
        }
        if ($this->hasRelationMethod($name)) {
            $value = $this->relation($name)->get();
            $this->setRelation($name, $value);
            return $value;
        }
        return $this->getAttribute($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        if (array_key_exists($name, $this->queryProjections)) {
            return true;
        }
        if (array_key_exists($name, $this->attributes)) {
            return $this->getAttribute($name) !== null;
        }
        if ($this->relationLoaded($name)) {
            return isset($this->relations[$name]);
        }
        return !$this->hasRelationMethod($name)
            && $this->accessorFor($name) !== null
            && $this->getAttribute($name) !== null;
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    public function setRelation(string $name, mixed $value): static
    {
        RelationLoader::names($name);
        $this->relations[$name] = $value;
        return $this;
    }

    public function unsetRelation(string $name): static
    {
        unset($this->relations[$name]);
        return $this;
    }

    /** Discard cached relation values after a pivot write; definitions remain callable. */
    public function clearRelations(): static
    {
        $this->relations = [];
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function pivot(): ?array
    {
        return $this->pivotData;
    }

    /** @internal Pivot metadata belongs to a hydrated relationship edge, not model attributes. */
    public function setPivot(array $data): static
    {
        $this->pivotData = $data;
        return $this;
    }

    /** @internal Retain the connection pinned to a hydrated or saved model. */
    public function relationConnection(): Connection
    {
        return $this->selectedConnection();
    }

    /** @internal Resolve relations through this Model's originating Application. */
    public function relationManager(): DatabaseManager
    {
        return $this->persistedManager ?? $this->persistedConnection?->owner() ?? Database::manager();
    }

    /** @param string|list<string> $relations */
    public function load(string|array $relations): static
    {
        RelationLoader::load([$this], RelationLoader::names($relations));
        return $this;
    }

    /** Resolve a declared public relationship method. */
    public function relation(string $name): Relation
    {
        if (!$this->hasRelationMethod($name)) {
            throw new RelationException('The model relationship method does not exist.');
        }
        $relation = $this->{$name}();
        if (!$relation instanceof Relation) {
            throw new RelationException('A model relationship method must return a Relation.');
        }
        return $relation;
    }

    /** @param class-string<Model> $related */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /** @param class-string<Model> $related */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Read a single final Model through an intermediate Model. Keys name the
     * intermediate's parent FK, final Model's intermediate FK, then the parent
     * and intermediate lookup columns, in that order.
     *
     * @param class-string<Model> $related
     * @param class-string<Model> $through
     */
    protected function hasOneThrough(
        string $related,
        string $through,
        ?string $firstForeignKey = null,
        ?string $secondForeignKey = null,
        ?string $localKey = null,
        ?string $throughKey = null
    ): HasOneThrough {
        return new HasOneThrough($this, $related, $through, $firstForeignKey,
            $secondForeignKey, $localKey, $throughKey);
    }

    /**
     * Read final Models through intermediate rows without providing a write
     * path across the intermediate table.
     *
     * @param class-string<Model> $related
     * @param class-string<Model> $through
     */
    protected function hasManyThrough(
        string $related,
        string $through,
        ?string $firstForeignKey = null,
        ?string $secondForeignKey = null,
        ?string $localKey = null,
        ?string $throughKey = null
    ): HasManyThrough {
        return new HasManyThrough($this, $related, $through, $firstForeignKey,
            $secondForeignKey, $localKey, $throughKey);
    }

    /** @param class-string<Model> $related */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /** @param class-string<Model> $related */
    protected function belongsToMany(
        string $related,
        string $pivotTable,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): BelongsToMany {
        return new BelongsToMany($this, $related, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey);
    }

    /** Resolve the stored alias through the Application's explicit morph map. */
    protected function morphTo(string $name, ?string $typeColumn = null, ?string $idColumn = null, ?string $ownerKey = null): MorphTo
    {
        return new MorphTo($this, $name, $typeColumn, $idColumn, $ownerKey);
    }

    /** @param class-string<Model> $related */
    protected function morphOne(string $related, string $name, ?string $typeColumn = null, ?string $idColumn = null, ?string $localKey = null): MorphOne
    {
        return new MorphOne($this, $related, $name, $typeColumn, $idColumn, $localKey);
    }

    /** @param class-string<Model> $related */
    protected function morphMany(string $related, string $name, ?string $typeColumn = null, ?string $idColumn = null, ?string $localKey = null): MorphMany
    {
        return new MorphMany($this, $related, $name, $typeColumn, $idColumn, $localKey);
    }

    /** @return array<string, mixed> Current canonical values, including hidden attributes. */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * With no argument, keep the established full-snapshot array contract.
     * A keyed read uses the same casted representation as a current attribute.
     *
     * @return array<string, mixed>|mixed
     */
    public function original(?string $name = null): mixed
    {
        return $name === null ? $this->original : ($this->original[$name] ?? null);
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function isDeleted(): bool
    {
        if (!$this->softDeletes || $this->deleted) {
            return false;
        }
        if ($this->exists && !array_key_exists($this->deletedAtColumn, $this->original)) {
            throw new LogicException('Deletion state is unknown for a partially selected model.');
        }
        return ($this->original[$this->deletedAtColumn] ?? null) !== null;
    }

    public function changed(?string $name = null): bool
    {
        if ($name !== null) {
            return array_key_exists($name, $this->attributes)
                && (!array_key_exists($name, $this->original)
                    || !$this->same($name, $this->attributes[$name], $this->original[$name]));
        }

        foreach (array_keys($this->attributes) as $attribute) {
            if ($this->changed($attribute)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> Pending new values, not a history of the previous save. */
    public function changes(): array
    {
        $pending = [];
        foreach ($this->attributes as $name => $value) {
            if ($this->changed($name)) {
                $pending[$name] = $value;
            }
        }
        return $pending;
    }

    /** @return array<string, mixed> Canonical values from the most recent confirmed write. */
    public function savedChanges(): array
    {
        return $this->savedChanges;
    }

    /** A failed write does not replace the last confirmed write history. */
    public function wasChanged(?string $name = null): bool
    {
        return $name === null ? $this->savedChanges !== []
            : array_key_exists($name, $this->savedChanges);
    }

    /** Preserve the foundation API for existing v2 models. */
    public function isDirty(?string $name = null): bool
    {
        return $this->changed($name);
    }

    /** Insert a new model or update changed fields; a deleted instance cannot resurrect. */
    public function save(): bool
    {
        return $this->guardLifecycleWrite(fn (): bool => $this->performSave());
    }

    private function performSave(): bool
    {
        if ($this->deleted) {
            throw new LogicException('A deleted model cannot be saved again; create a new instance.');
        }
        if ($this->insertOutcomeUncertain) {
            throw new LogicException('A previous insert may have succeeded; discard this model before retrying.');
        }
        if (!$this->exists) {
            return $this->insertNew();
        }

        $keyName = $this->persistedKeyName ?? $this->primaryKeyName();
        $key = $this->existingKey();
        $this->assertUnchangedKey($keyName, $key);
        $pending = $this->changes();
        unset($pending[$keyName]);
        if ($pending === []) {
            return true;
        }

        // Before observers may adjust ordinary writable fields. Recompute the
        // pending set after they run, while the primary-key invariant still holds.
        $this->fireLifecycle('saving', 'update');
        $this->fireLifecycle('updating', 'update');
        $this->assertUnchangedKey($keyName, $key);
        $pending = $this->changes();
        unset($pending[$keyName]);
        if ($pending === []) {
            return true;
        }

        $candidate = $this->attributes;
        $connection = $this->selectedConnection();
        $table = $this->persistedTable ?? $this->tableName();
        if ($this->timestamps && $this->updatedAtColumn !== null
            && !array_key_exists($this->updatedAtColumn, $this->assigned)) {
            $stamp = $this->automaticTimestamp($this->updatedAtColumn);
            $candidate[$this->updatedAtColumn] = $stamp;
            $pending[$this->updatedAtColumn] = $stamp;
        }
        $write = $this->storageValues($pending);
        $confirmedChanges = $this->changedFromOriginal($pending);

        $count = $connection->table($table)->filter($keyName, $key)->update($write);
        if ($count === 0) {
            try {
                $found = $connection->table($table)->filter($keyName, $key)->exists();
            } catch (Throwable $exception) {
                throw new QueryException('Model update completed, but row confirmation failed; persistence state is uncertain.', 0, $exception);
            }
            if (!$found) {
                return false;
            }
        }

        $this->attributes = $candidate;
        $this->original = $candidate;
        $this->assigned = [];
        $this->savedChanges = $confirmedChanges;
        $this->persistedConnection ??= $connection;
        $this->persistedManager ??= $connection->owner();
        $this->persistedTable ??= $table;
        $this->persistedKeyName ??= $keyName;

        $this->fireLifecycle('updated', 'update');
        $this->fireLifecycle('saved', 'update');

        return true;
    }

    /**
     * @internal Persist one trusted attribute without saving other pending edits
     * or advancing timestamps. Authentication uses this for password rehashes.
     * A failed write leaves both current and original model state untouched.
     */
    public function persistAttributeOnly(string $name, mixed $value): bool
    {
        if (!$this->exists || $this->deleted || $this->insertOutcomeUncertain || $this->isDeleted()) {
            throw new LogicException('Only an active persisted model can update one attribute.');
        }
        $this->assertAttributeName($name);
        $this->assertWritableAttribute($name);
        $keyName = $this->persistedKeyName ?? $this->primaryKeyName();
        if ($name === $keyName) throw new LogicException('A persisted model primary key cannot be changed.');
        $key = $this->existingKey();
        $this->assertUnchangedKey($keyName, $key);
        $candidate = $this->fromInput($name, $value);
        $confirmedChanges = $this->changedFromOriginal([$name => $candidate]);
        $connection = $this->selectedConnection();
        $table = $this->persistedTable ?? $this->tableName();
        $count = $connection->table($table)->filter($keyName, $key)
            ->update($this->storageValues([$name => $candidate]));
        if ($count === 0) {
            try {
                $found = $connection->table($table)->filter($keyName, $key)->exists();
            } catch (Throwable $exception) {
                throw new QueryException('Model attribute update completed, but row confirmation failed; persistence state is uncertain.', 0, $exception);
            }
            if (!$found) return false;
        }
        $this->attributes[$name] = $candidate;
        $this->original[$name] = $candidate;
        unset($this->assigned[$name]);
        $this->savedChanges = $confirmedChanges;
        return true;
    }

    /** Use an opt-in soft transition, otherwise retain physical delete behavior. */
    public function delete(): bool
    {
        return $this->guardLifecycleWrite(fn (): bool => $this->softDeletes
            ? $this->transitionDeletion(false) : $this->physicalDelete(false));
    }

    /** Clear only framework-managed deletion state on a soft-deleted row. */
    public function restore(): bool
    {
        $this->requireSoftDeletes();
        return $this->guardLifecycleWrite(fn (): bool => $this->transitionDeletion(true));
    }

    /** Physically remove an opt-in soft-deletable row using its original key. */
    public function forceDelete(): bool
    {
        $this->requireSoftDeletes();
        return $this->guardLifecycleWrite(fn (): bool => $this->physicalDelete(true));
    }

    private function requireSoftDeletes(): void
    {
        if (!$this->softDeletes) {
            throw new LogicException('Model ' . static::class . ' does not use soft deletes.');
        }
    }

    /** Write only lifecycle columns, preserving unrelated unsaved attributes. */
    private function transitionDeletion(bool $restore): bool
    {
        if (!$this->exists || $this->deleted) {
            return false;
        }
        $keyName = $this->persistedKeyName ?? $this->primaryKeyName();
        $key = $this->existingKey();
        $this->assertUnchangedKey($keyName, $key);
        $column = $this->deletedAtColumn;
        if (array_key_exists($column, $this->original)) {
            $currentlyDeleted = $this->original[$column] !== null;
            if ($currentlyDeleted === !$restore) {
                return false;
            }
        }

        $this->fireLifecycle($restore ? 'restoring' : 'deleting', $restore ? 'restore' : 'delete');
        $this->assertUnchangedKey($keyName, $key);

        $connection = $this->selectedConnection();
        $table = $this->persistedTable ?? $this->tableName();
        $query = $connection->table($table)->filter($keyName, $key);
        $restore ? $query->filterNotNull($column) : $query->filterNull($column);
        $now = $this->selectedClock()->now();
        $managed = [$column => $restore ? null : $this->automaticTimestamp($column, $now)];
        if ($this->timestamps && $this->updatedAtColumn !== null) {
            $managed[$this->updatedAtColumn] = $this->automaticTimestamp($this->updatedAtColumn, $now);
        }
        $confirmedChanges = $this->changedFromOriginal($managed);
        $count = $query->update($this->storageValues($managed));
        if ($count === 0) {
            return false;
        }
        foreach ($managed as $name => $value) {
            $this->original[$name] = $value;
            if (!array_key_exists($name, $this->assigned)) {
                $this->attributes[$name] = $value;
            }
        }
        $this->savedChanges = $confirmedChanges;
        $this->clearRelations();
        $this->fireLifecycle($restore ? 'restored' : 'deleted', $restore ? 'restore' : 'delete');
        return true;
    }

    private function physicalDelete(bool $force): bool
    {
        if (!$this->exists || $this->deleted) {
            return false;
        }

        $keyName = $this->persistedKeyName ?? $this->primaryKeyName();
        $key = $this->existingKey();
        $this->assertUnchangedKey($keyName, $key);
        $this->fireLifecycle('deleting', $force ? 'forceDelete' : 'delete');
        $this->assertUnchangedKey($keyName, $key);
        $connection = $this->selectedConnection();
        $deleted = $connection->table($this->persistedTable ?? $this->tableName())
            ->filter($keyName, $key)->delete() > 0;
        if ($deleted) {
            $this->exists = false;
            $this->deleted = true;
            $this->savedChanges = [];
            $this->clearRelations();
            $this->fireLifecycle('deleted', $force ? 'forceDelete' : 'delete');
        }

        return $deleted;
    }

    /** Reload the same persisted row; successful refresh discards pending edits. */
    public function refresh(): static
    {
        if (!$this->exists || $this->deleted || $this->insertOutcomeUncertain) {
            throw new LogicException('Only a persisted model with a known identity can be refreshed.');
        }

        $keyName = $this->persistedKeyName ?? $this->primaryKeyName();
        $key = $this->existingKey();
        $connection = $this->selectedConnection();
        $table = $this->persistedTable ?? $this->tableName();
        $row = $connection->table($table)->filter($keyName, $key)->first();
        if ($row === null) {
            throw new LogicException('The persisted model row is missing.');
        }

        $values = [];
        foreach ($row as $name => $value) {
            $this->assertAttributeName($name);
            $values[$name] = $this->fromStorage($name, $value);
        }

        $this->attributes = $values;
        $this->original = $values;
        $this->queryProjections = [];
        $this->assigned = [];
        $this->savedChanges = [];
        $this->relations = [];
        $this->pivotData = null;
        $this->persistedConnection ??= $connection;
        $this->persistedManager ??= $connection->owner();
        $this->persistedTable ??= $table;
        $this->persistedKeyName ??= $keyName;

        return $this;
    }

    public function tableName(): string
    {
        if ($this->table !== '') {
            return $this->table;
        }

        $shortName = (new ReflectionClass($this))->getShortName();
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));

        if (preg_match('/(?:ss|us|x|z|ch|sh)$/', $snake) === 1) {
            return $snake . 'es';
        }
        if (preg_match('/[^aeiou]y$/', $snake) === 1) {
            return substr($snake, 0, -1) . 'ies';
        }
        return str_ends_with($snake, 's') ? $snake : $snake . 's';
    }

    public function primaryKeyName(): string
    {
        return $this->primaryKey;
    }

    public function connectionName(): ?string
    {
        return $this->connection;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $visible = [];
        foreach ($this->attributes as $name => $value) {
            if (!$this->isHiddenAttribute($name)) {
                if ($this->accessorFor($name) !== null) {
                    // A declared accessor owns its presentation format; the
                    // stored value and its cast remain untouched.
                    $visible[$name] = $this->presentAttribute($name, $value);
                } else {
                    $type = $this->castType($name);
                    $visible[$name] = $type === null ? $value
                        : AttributeCaster::serialize(static::class, $name, $type, $value);
                }
            }
        }
        foreach ($this->queryProjections as $name => $value) {
            if (!$this->isHiddenAttribute($name)) {
                $visible[$name] = $value;
            }
        }
        return $visible;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function insertNew(): bool
    {
        if ($this->attributes === []) {
            throw new LogicException('Cannot save a model without attributes.');
        }

        $manager = Database::manager();
        $connection = $manager->connection($this->connectionName());
        $clock = $manager->clock();
        $table = $this->tableName();
        $keyName = $this->primaryKeyName();
        $this->fireLifecycle('saving', 'create', $connection);
        $this->fireLifecycle('creating', 'create', $connection);
        if ($this->attributes === []) {
            throw new LogicException('Cannot save a model without attributes.');
        }
        $candidate = $this->attributes;
        if ($this->softDeletes) {
            $candidate[$this->deletedAtColumn] = null;
        }
        if ($this->timestamps) {
            $now = $clock->now()->setTimezone(new DateTimeZone('UTC'));
            foreach ([$this->createdAtColumn, $this->updatedAtColumn] as $column) {
                if ($column !== null && !array_key_exists($column, $candidate)) {
                    $candidate[$column] = $this->automaticTimestamp($column, $now);
                }
            }
        }

        $write = $this->storageValues($candidate);
        $hasKey = array_key_exists($keyName, $candidate) && $candidate[$keyName] !== null;
        $count = $connection->table($table)->insert($write);
        if ($count === 0) {
            $this->markInsertUncertain($connection, $clock, $table, $keyName);
            throw new QueryException('Model insert completed without a confirmed row; persistence state is uncertain.');
        }
        if (!$hasKey) {
            try {
                $id = $connection->pdo()->lastInsertId();
                if (!is_string($id) || $id === '' || $id === '0') {
                    throw new QueryException('The database did not return an inserted ID.');
                }
                $candidate[$keyName] = $this->fromInput($keyName, $id);
            } catch (Throwable $exception) {
                $this->markInsertUncertain($connection, $clock, $table, $keyName);
                throw new QueryException('Model insert completed, but its generated ID is unavailable; persistence state is uncertain.', 0, $exception);
            }
        }

        $this->attributes = $candidate;
        $this->original = $candidate;
        $this->assigned = [];
        $this->savedChanges = $candidate;
        $this->exists = true;
        $this->persistedConnection = $connection;
        $this->persistedManager = $manager;
        $this->persistedClock = $clock;
        $this->persistedTable = $table;
        $this->persistedKeyName = $keyName;

        $this->fireLifecycle('created', 'create', $connection);
        $this->fireLifecycle('saved', 'create', $connection);

        return true;
    }

    /**
     * Observer failures propagate. A before failure prevents SQL; an after
     * failure occurs after this Model has synchronized its successful write.
     */
    private function fireLifecycle(string $phase, string $operation, ?Connection $connection = null): void
    {
        ($connection ?? $this->selectedConnection())->modelObservers()->emit($this, $phase, $operation);
    }

    /** @param callable():bool $write */
    private function guardLifecycleWrite(callable $write): bool
    {
        if ($this->lifecycleOperationActive) {
            throw new LogicException('A Model lifecycle observer cannot recursively write the same instance.');
        }
        $this->lifecycleOperationActive = true;
        try {
            return $write();
        } finally {
            $this->lifecycleOperationActive = false;
        }
    }

    private function markInsertUncertain(Connection $connection, ModelClock $clock, string $table, string $keyName): void
    {
        $this->insertOutcomeUncertain = true;
        $this->persistedConnection = $connection;
        $this->persistedManager = $connection->owner();
        $this->persistedClock = $clock;
        $this->persistedTable = $table;
        $this->persistedKeyName = $keyName;
    }

    private function selectedConnection(): Connection
    {
        return $this->persistedConnection ?? Database::manager()->connection($this->connectionName());
    }

    private function selectedClock(): ModelClock
    {
        return $this->persistedClock ?? Database::manager()->clock();
    }

    private function automaticTimestamp(string $column, ?DateTimeImmutable $now = null): mixed
    {
        $now ??= $this->selectedClock()->now();
        $storage = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return $this->fromInput($column, $storage);
    }

    /** @param array<string, mixed> $values @return array<string, int|float|string|bool|null> */
    private function storageValues(array $values): array
    {
        $write = [];
        foreach ($values as $name => $value) {
            $type = $this->castType($name);
            $stored = $type === null ? $value
                : AttributeCaster::toStorage(static::class, $name, $type, $value);
            if ($stored !== null && !is_scalar($stored)) {
                throw new InvalidArgumentException('Model ' . static::class . " attribute {$name} cannot be stored as a database value.");
            }
            $write[$name] = $stored;
        }
        return $write;
    }

    private function fromInput(string $name, mixed $value): mixed
    {
        $type = $this->castType($name);
        if ($type === null) {
            return $value;
        }
        // Round-trip the storage form to detach nested arrays and match DB precision.
        $canonical = AttributeCaster::fromInput(static::class, $name, $type, $value);
        return AttributeCaster::fromStorage(
            static::class,
            $name,
            $type,
            AttributeCaster::toStorage(static::class, $name, $type, $canonical)
        );
    }

    /** Only explicit caller assignment invokes a mutator; hydration and SQL writes use canonical values. */
    private function mutateAttribute(string $name, mixed $value): mixed
    {
        $mutator = static::mutators()[$name] ?? null;
        if ($mutator === null) {
            return $value;
        }
        if (isset($this->activeMutators[$name])) {
            throw new LogicException('Model ' . static::class . " attribute {$name} mutator is recursive.");
        }
        $this->activeMutators[$name] = true;
        try {
            return $mutator($value, $this);
        } finally {
            unset($this->activeMutators[$name]);
        }
    }

    /** Read transforms never replace canonical current or original state. */
    private function presentAttribute(string $name, mixed $value): mixed
    {
        $accessor = $this->accessorFor($name);
        if ($accessor === null) {
            return $value;
        }
        if (isset($this->activeAccessors[$name])) {
            throw new LogicException('Model ' . static::class . " attribute {$name} accessor is recursive.");
        }
        $this->activeAccessors[$name] = true;
        try {
            return $accessor($value, $this);
        } finally {
            unset($this->activeAccessors[$name]);
        }
    }

    private function accessorFor(string $name): ?callable
    {
        return static::accessors()[$name] ?? null;
    }

    private function fromStorage(string $name, mixed $value): mixed
    {
        $type = $this->castType($name);
        return $type === null ? $value
            : AttributeCaster::fromStorage(static::class, $name, $type, $value);
    }

    private function same(string $name, mixed $current, mixed $old): bool
    {
        $type = $this->castType($name);
        return $type === null ? $current === $old
            : AttributeCaster::same(static::class, $name, $type, $current, $old);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function changedFromOriginal(array $values): array
    {
        $changed = [];
        foreach ($values as $name => $value) {
            if (!array_key_exists($name, $this->original)
                || !$this->same($name, $value, $this->original[$name])) {
                $changed[$name] = $value;
            }
        }
        return $changed;
    }

    private function castType(string $name): ?string
    {
        if ($this->softDeletes && $name === $this->deletedAtColumn) {
            return 'datetime';
        }
        if (!array_key_exists($name, $this->casts)) {
            return null;
        }
        $type = $this->casts[$name];
        if (!is_string($type)) {
            throw new InvalidArgumentException('Model ' . static::class . " attribute {$name} has an invalid cast declaration.");
        }
        AttributeCaster::assertSupported(static::class, $name, $type);
        return $type;
    }

    private function assertMetadata(): void
    {
        Identifier::simple($this->primaryKeyName());
        Identifier::table($this->tableName());
        if ($this->softDeletes) {
            Identifier::simple($this->deletedAtColumn);
            if ($this->deletedAtColumn === $this->primaryKeyName()
                || ($this->timestamps && in_array($this->deletedAtColumn, [$this->createdAtColumn, $this->updatedAtColumn], true))) {
                throw new InvalidArgumentException('A model deletion column must be distinct from its key and timestamps.');
            }
            if (array_key_exists($this->deletedAtColumn, $this->casts)
                && $this->casts[$this->deletedAtColumn] !== 'datetime') {
                throw new InvalidArgumentException('A model deletion column requires a datetime cast.');
            }
        }
        foreach ($this->casts as $name => $type) {
            $this->assertAttributeName($name);
            if (!is_string($type)) {
                throw new InvalidArgumentException('Model ' . static::class . " attribute {$name} has an invalid cast declaration.");
            }
            AttributeCaster::assertSupported(static::class, $name, $type);
        }
        foreach (['accessor' => static::accessors(), 'mutator' => static::mutators()] as $kind => $transforms) {
            foreach ($transforms as $name => $transform) {
                $this->assertAttributeName($name);
                if (!is_callable($transform)) {
                    throw new InvalidArgumentException('Model ' . static::class . " attribute {$name} {$kind} is not callable.");
                }
                if ($kind === 'mutator' && $this->softDeletes && $name === $this->deletedAtColumn) {
                    throw new InvalidArgumentException('A model deletion column cannot have an assignment mutator.');
                }
            }
        }
        if ($this->timestamps) {
            foreach ([$this->createdAtColumn, $this->updatedAtColumn] as $column) {
                if ($column !== null) {
                    Identifier::simple($column);
                    if ($column === $this->primaryKeyName()) {
                        throw new InvalidArgumentException('A model timestamp column cannot be the primary key.');
                    }
                }
            }
            if ($this->createdAtColumn !== null && $this->createdAtColumn === $this->updatedAtColumn) {
                throw new InvalidArgumentException('A model cannot use the same column for both managed timestamps.');
            }
        }
    }

    private function assertAttributeName(mixed $name): void
    {
        if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1) {
            throw new InvalidArgumentException('Model attribute name is invalid.');
        }
    }

    private function assertUnchangedKey(string $keyName, mixed $originalKey): void
    {
        if (array_key_exists($keyName, $this->attributes)
            && !$this->same($keyName, $this->attributes[$keyName], $originalKey)) {
            throw new LogicException('A saved model primary key cannot be changed.');
        }
    }

    private function isFillable(string $name): bool
    {
        if (in_array($name, $this->guarded, true)) {
            return false;
        }
        if ($this->fillable !== []) {
            return in_array($name, $this->fillable, true);
        }

        return !in_array('*', $this->guarded, true);
    }

    private function assertWritableAttribute(string $name): void
    {
        if ($this->softDeletes && $name === $this->deletedAtColumn) {
            throw new InvalidArgumentException('Use delete() or restore() to change the model deletion state.');
        }
    }

    protected function isHiddenAttribute(string $name): bool
    {
        if (in_array(strtolower($name), array_map('strtolower', $this->hidden), true)) {
            return true;
        }
        $name = strtolower($name);
        foreach (['password', 'passwd', 'secret', 'token', 'api_key', 'private_key', 'credential'] as $sensitive) {
            if (str_contains($name, $sensitive)) {
                return true;
            }
        }
        return false;
    }

    private function existingKey(): int|string
    {
        $keyName = $this->persistedKeyName ?? $this->primaryKeyName();
        $value = $this->original[$keyName] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new LogicException('Cannot modify an existing model without its primary key.');
        }
        return $value;
    }

    private function hasRelationMethod(string $name): bool
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1 || !method_exists($this, $name)) {
            return false;
        }
        $method = new ReflectionMethod($this, $name);
        return $method->isPublic() && !$method->isStatic()
            && $method->getNumberOfRequiredParameters() === 0
            && $method->getDeclaringClass()->getName() !== self::class;
    }
}
