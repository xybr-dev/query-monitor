<?php

declare(strict_types=1);

namespace Xybr\QueryMonitor;

final class SlowQueryFingerprint
{
    public readonly string $normalized;

    public readonly int $hash;

    public readonly ?string $table;

    public function __construct(string $rawSql)
    {
        $normalized = $this->normalize($rawSql);
        $this->normalized = $normalized;
        $this->hash = crc32($normalized);
        $this->table = $this->extractTable($normalized);
    }

    private function normalize(string $sql): string
    {
        $sql = strtolower($sql);

        $sql = preg_replace("/'[^']*'/", '?', $sql) ?? $sql;

        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;

        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        return $sql;
    }

    private function extractTable(string $normalized): ?string
    {
        if (preg_match('/\b(?:from|into|update|join)\s+[`"[]?(\w+)[`"\]]?/', $normalized, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
