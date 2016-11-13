<?php
/* ===========================================================================
 * Copyright 2013-2016 The Opis Project
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */

namespace Opis\Database\ORM;

class EntityMapper
{
    /** @var string  */
    protected $entityClass;

    /** @var string */
    protected $className;

    /** @var string|null */
    protected $table;

    /** @var string  */
    protected $primaryKey = 'id';

    /** @var  callable|null */
    protected $primaryKeyGenerator;

    /** @var callable[] */
    protected $getters = [];

    /** @var callable[] */
    protected $setters = [];

    /** @var array  */
    protected $casts = [];

    /** @var Relation[] */
    protected $relations = [];

    /** @var  string */
    protected $sequence;

    /**
     * EntityMapper constructor.
     * @param string $entityClass
     */
    public function __construct(string $entityClass)
    {
        $this->entityClass = $entityClass;
    }

    /**
     * @param string $table
     * @return EntityMapper
     */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * @param string $primaryKey
     * @return EntityMapper
     */
    public function primaryKey(string $primaryKey): self
    {
        $this->primaryKey = $primaryKey;
        return $this;
    }

    /**
     * @param callable $callback
     * @return EntityMapper
     */
    public function primaryKeyGenerator(callable $callback): self
    {
        $this->primaryKeyGenerator = $callback;
        return $this;
    }

    /**
     * @param string $sequence
     * @return EntityMapper
     */
    public function sequence(string $sequence): self
    {
        $this->sequence = $sequence;
        return $this;
    }

    /**
     * @param string $column
     * @param callable $callback
     * @return EntityMapper
     */
    public function getter(string $column, callable $callback): self
    {
        $this->getters[$column] = $callback;
        return $this;
    }

    /**
     * @param string $column
     * @param callable $callback
     * @return EntityMapper
     */
    public function setter(string $column, callable $callback): self
    {
        $this->setters[$column] = $callback;
        return $this;
    }

    /**
     * @param string $name
     * @return RelationFactory
     */
    public function relation(string $name): RelationFactory
    {
        return new RelationFactory($name, function ($name, Relation $relation){
           return $this->relations[$name] = $relation;
        });
    }

    /**
     * @param array $casts
     * @return EntityMapper
     */
    public function cast(array $casts): self
    {
        $this->casts = $casts;
        return $this;
    }

    /**
     * @return string
     */
    public function getClass(): string
    {
        return $this->entityClass;
    }

    /**
     * Get the entity's table
     *
     * @return  string
     */
    public function getTable(): string
    {
        if ($this->table === null) {
            $this->table = strtolower(preg_replace('/([^A-Z])([A-Z])/', "$1_$2", $this->getClassShortName())) . 's';
        }

        return $this->table;
    }

    /**
     * @return string
     */
    public function getPrimaryKey(): string 
    {
        return $this->primaryKey;
    }

    /**
     * @return callable|null
     */
    public function getPrimaryKeyGenerator()
    {
        return $this->primaryKeyGenerator;
    }

    /**
     * Get the name of the foreign key of the entity's table
     *
     * @return  string
     */
    public function getForeignKey(): string
    {
        return str_replace('-', '_', strtolower(preg_replace('/([^A-Z])([A-Z])/', "$1_$2", $this->getClassShortName()))) . '_id';
    }

    /**
     * @return string[]
     */
    public function getTypeCasts(): array
    {
        return $this->casts;
    }

    /**
     * @return callable[]
     */
    public function getGetters(): array
    {
        return $this->getters;
    }

    /**
     * @return callable[]
     */
    public function getSetters(): array
    {
        return $this->setters;
    }

    /**
     * @return Relation[]
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * @return string
     */
    public function getSequence(): string
    {
        if($this->sequence === null){
            $this->sequence = $this->getTable() . '_' . $this->getPrimaryKey() . '_seq';
        }
        return $this->sequence;
    }

    /**
     * @return bool
     */
    public function supportsSoftDelete(): bool
    {
        return isset($this->casts['deleted_at']) && $this->casts['deleted_at'] === '?date';
    }

    /**
     * Returns the short class name of the entity
     *
     * @return  string
     */
    protected function getClassShortName()
    {
        if ($this->className === null) {
            $name = $this->entityClass;

            if (false !== $pos = strrpos($name, '\\')) {
                $name = substr($name, $pos + 1);
            }

            $this->className = $name;
        }

        return $this->className;
    }
}