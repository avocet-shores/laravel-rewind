<?php

namespace AvocetShores\LaravelRewind\Tests\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\SoftDeletes;

    class Template extends Model {
        use SoftDeletes;
    }
