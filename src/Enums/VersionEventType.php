<?php

namespace AvocetShores\LaravelRewind\Enums;

enum VersionEventType: string
{
    case Created = 'created';

    case Updated = 'updated';

    case Deleted = 'deleted';

    case Restored = 'restored';
}
