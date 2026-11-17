<?php

namespace Tey\Docket\Relations;

use Illuminate\Database\Eloquent\Model;

/** The parent key as the string the foreign key column stores, for single and eager constraints alike. */
trait StringKeys
{
    public function getParentKey(): mixed
    {
        return self::asString(parent::getParentKey());
    }

    /** @param  array<int, Model>  $models */
    public function addEagerConstraints(array $models): void
    {
        // A plain whereIn of strings: the integer path (whereIntegerInRaw) writes the keys into the SQL as integer literals.
        $keys = [];

        foreach ($models as $model) {
            $key = self::asString($model->getAttribute($this->localKey));

            if ($key !== null) {
                $keys[$key] = $key;
            }
        }

        $this->getRelationQuery()->whereIn($this->foreignKey, array_values($keys));
        $this->getRelationQuery()->where($this->morphType, $this->morphClass);
    }

    private static function asString(mixed $key): ?string
    {
        return is_int($key) || is_string($key) ? (string) $key : null;
    }
}
