<?php

declare(strict_types=1);

namespace Xybr\QueryMonitor;

use Exception;

class SlowQueryDetectedException extends Exception
{
    public readonly string $fingerprint;

    public readonly ?string $table;

    public function __construct(
        public readonly string $sql,
        public readonly float $duration,
        public readonly string $connection,
        ?string $fingerprint = null,
        ?string $table = null,
        public readonly int $occurrences = 1,
    ) {
        if ($fingerprint === null) {
            $fp = new SlowQueryFingerprint($sql);
            $fingerprint = $fp->normalized;
            $table ??= $fp->table;
        }

        $this->fingerprint = $fingerprint;
        $this->table = $table;

        $message = sprintf(
            'Slow query detected%s (%dms%s, %s): %s',
            $this->table !== null ? " on \"{$this->table}\"" : '',
            $duration,
            $occurrences > 1 ? " × {$occurrences}" : '',
            $connection,
            $sql,
        );

        parent::__construct($message, crc32($this->fingerprint));
    }
}
