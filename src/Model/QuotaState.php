<?php

namespace App\Model;

enum QuotaState: string
{
    /** Within the monthly package. */
    case Ok = 'ok';

    /** Over the package but within the tolerance: only the admin is told. */
    case Tolerated = 'tolerated';

    /** Beyond the tolerance: the client is told too. */
    case Exceeded = 'exceeded';
}
