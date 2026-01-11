<?php

namespace Sumee;

class Snowflake
{
    private int $epochMs;
    private int $workerId;
    private int $processId;
    private int $sequence = 0;
    private int $lastMs = 0;

    public function __construct(int $epochMs, int $workerId, int $processId)
    {
        $this->epochMs = $epochMs;
        $this->workerId = $workerId & 0x1F;
        $this->processId = $processId & 0x1F;
    }

    public function nextId(): string
    {
        $now = (int) floor(microtime(true) * 1000);
        if ($now === $this->lastMs) {
            $this->sequence = ($this->sequence + 1) & 0xFFF;
            if ($this->sequence === 0) {
                while ($now <= $this->lastMs) {
                    $now = (int) floor(microtime(true) * 1000);
                }
            }
        } else {
            $this->sequence = 0;
        }
        $this->lastMs = $now;

        $ts = $now - $this->epochMs;
        $id = (($ts << 22) | ($this->workerId << 17) | ($this->processId << 12) | $this->sequence);
        return sprintf('%u', $id);
    }
}
