<?php

namespace App\Models\Concerns;

use App\Support\ObjectId;

/**
 * Gives a model a MongoDB-style ObjectId primary key (CHAR(24)).
 */
trait HasObjectId
{
    public static function bootHasObjectId()
    {
        static::creating(function ($model) {
            if (empty($model->getKey())) {
                $model->setAttribute($model->getKeyName(), ObjectId::generate());
            }
        });
    }

    public function getIncrementing()
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }
}
