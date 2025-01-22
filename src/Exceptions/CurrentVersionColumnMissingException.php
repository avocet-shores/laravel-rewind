<?php

namespace AvocetShores\LaravelRewind\Exceptions;

class CurrentVersionColumnMissingException extends LaravelRewindException
{
    public function __construct($model)
    {
        parent::__construct(sprintf("%s's table (%s) does not have a current_version column.
        You can use the artisan command 'php artisan rewind:add-version' to create a new migration that will add the column.", get_class($model), $model->getTable()));
    }
}
