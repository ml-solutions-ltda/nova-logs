<?php

namespace MlSolutions\NovaLogViewer\Support;

use Carbon\CarbonImmutable;

class LaravelHeader
{
    public function header(string $text): ?array
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if (preg_match('/^\[([^\]\r\n]+)\]\s+([^\s:]+)\.(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):\s?(.*)/s', $text, $m)) {
            [$date, $channel, $level, $message] = [$m[1], $m[2], $m[3], $m[4]];
        } else {
            $data = json_decode($text, true);
            if (! is_array($data) || ! isset($data['datetime'], $data['message']) || ! is_string($data['datetime']) || ! is_string($data['message'])) {
                return null;
            }
            [$date, $channel, $level, $message] = [$data['datetime'], $data['channel'] ?? '', $data['level_name'] ?? 'UNKNOWN', $data['message']];
        }
        try {
            $date = CarbonImmutable::parse($date, config('app.timezone'))->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $date = null;
        }

        return ['occurred_at' => $date, 'level' => is_string($level) ? strtoupper($level) : 'UNKNOWN', 'channel' => is_string($channel) ? substr($channel, 0, 120) : null, 'message' => mb_strcut($message, 0, 1500, 'UTF-8'), 'category' => 'application'];
    }
}
