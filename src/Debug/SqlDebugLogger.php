<?php

namespace App\Debug;

use Doctrine\DBAL\Logging\SQLLogger;

class SqlDebugLogger implements SQLLogger
{
    private ?float $startedAt = null;
    private string $sql = '';

    /**
     * @var array<int|string, mixed>|null
     */
    private ?array $params = null;

    /**
     * @param array<int|string, mixed>|null $params
     * @param array<int|string, mixed>|null $types
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null): void
    {
        if (!DebugCollector::isEnabled()) {
            return;
        }

        $this->startedAt = microtime(true);
        $this->sql = (string) $sql;
        $this->params = $params;
    }

    public function stopQuery(): void
    {
        if (!DebugCollector::isEnabled() || $this->startedAt === null) {
            return;
        }

        $durationMs = round((microtime(true) - $this->startedAt) * 1000, 2);
        DebugCollector::addQuery($this->sql, $this->params, $durationMs);

        $this->startedAt = null;
        $this->sql = '';
        $this->params = null;
    }
}
