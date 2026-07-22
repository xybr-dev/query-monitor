<?php

declare(strict_types=1);

namespace Xybr\QueryMonitor;

use Exception;

class SlowQueryDetectedException extends Exception
{
    public function __construct(
        public readonly string $sql,
        public readonly float $duration,
        public readonly string $connection,
        public readonly int $occurrences = 1,
    ) {
        $message = sprintf(
            'Slow query detected (%dms, %s): %s',
            $duration,
            $connection,
            $sql,
        );

        if ($occurrences > 1) {
            $message = sprintf(
                'Slow query detected (%dms × %d, %s): %s',
                $duration,
                $occurrences,
                $connection,
                $sql,
            );
        }

        parent::__construct($message);
    }
}
