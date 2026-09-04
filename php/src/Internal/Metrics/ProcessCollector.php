<?php

/*
Copyright 2024 Blnk Finance Authors.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

declare(strict_types=1);

namespace Blnk\Internal\Metrics;

/**
 * ProcessCollector is the PHP counterpart of client_golang's
 * `prometheus.NewProcessCollector`, which the default registry served by
 * `promhttp.Handler()` always includes: the `process_*` metrics of the
 * serving process, read from procfs on Linux (silently absent elsewhere).
 *
 * The Go runtime collector (`go_*` metrics) has no PHP counterpart and is
 * not emulated.
 */
final class ProcessCollector
{
    /** Kernel clock ticks per second (sysconf(_SC_CLK_TCK); 100 on Linux). */
    private const ClockTicks = 100;

    /** Page size in bytes (sysconf(_SC_PAGESIZE); 4096 on x86/arm64 Linux). */
    private const PageSize = 4096;

    private string $procRoot;

    public function __construct(string $procRoot = '/proc')
    {
        $this->procRoot = rtrim($procRoot, '/');
    }

    /**
     * collect returns the metric families of the current process, in the
     * {@see PrometheusExporter} family shape. Each metric is included only
     * when its source could be read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(): array
    {
        $families = [];
        $stat = $this->readStat();

        if ($stat !== null) {
            $families[] = self::family('process_cpu_seconds_total', 'Total user and system CPU time spent in seconds.', 'counter', ($stat['utime'] + $stat['stime']) / self::ClockTicks);

            $btime = $this->bootTime();
            if ($btime !== null) {
                $families[] = self::family('process_start_time_seconds', 'Start time of the process since unix epoch in seconds.', 'gauge', $btime + $stat['starttime'] / self::ClockTicks);
            }
            $families[] = self::family('process_virtual_memory_bytes', 'Virtual memory size in bytes.', 'gauge', (float) $stat['vsize']);
            $families[] = self::family('process_resident_memory_bytes', 'Resident memory size in bytes.', 'gauge', (float) ($stat['rss'] * self::PageSize));
        }

        $openFds = $this->openFds();
        if ($openFds !== null) {
            $families[] = self::family('process_open_fds', 'Number of open file descriptors.', 'gauge', (float) $openFds);
        }

        $limits = $this->limits();
        if ($limits !== null) {
            if (isset($limits['Max open files'])) {
                $families[] = self::family('process_max_fds', 'Maximum number of open file descriptors.', 'gauge', $limits['Max open files']);
            }
            if (isset($limits['Max address space'])) {
                $families[] = self::family('process_virtual_memory_max_bytes', 'Maximum amount of virtual memory available in bytes.', 'gauge', $limits['Max address space']);
            }
        }

        $net = $this->netstat();
        if ($net !== null) {
            $families[] = self::family('process_network_receive_bytes_total', 'Number of bytes received by the process over the network.', 'counter', $net['in']);
            $families[] = self::family('process_network_transmit_bytes_total', 'Number of bytes sent by the process over the network.', 'counter', $net['out']);
        }

        return $families;
    }

    /**
     * @return array<string, mixed>
     */
    private static function family(string $name, string $help, string $type, float $value): array
    {
        return [
            'name' => $name,
            'help' => $help,
            'type' => $type,
            'metrics' => [['labels' => [], 'value' => $value]],
        ];
    }

    /**
     * readStat parses /proc/self/stat (utime, stime, starttime, vsize, rss).
     *
     * @return array{utime: int, stime: int, starttime: int, vsize: int, rss: int}|null
     */
    private function readStat(): ?array
    {
        $raw = @file_get_contents($this->procRoot . '/self/stat');
        if ($raw === false) {
            return null;
        }
        // The command name is parenthesized and may contain spaces.
        $close = strrpos($raw, ')');
        if ($close === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($raw, $close + 1)));
        if ($fields === false || count($fields) < 22) {
            return null;
        }
        // fields[0] is the state (field 3 of the stat line).
        return [
            'utime' => (int) $fields[11],
            'stime' => (int) $fields[12],
            'starttime' => (int) $fields[19],
            'vsize' => (int) $fields[20],
            'rss' => (int) $fields[21],
        ];
    }

    /** bootTime reads `btime` from /proc/stat. */
    private function bootTime(): ?float
    {
        $raw = @file_get_contents($this->procRoot . '/stat');
        if ($raw === false || preg_match('/^btime\s+(\d+)/m', $raw, $m) !== 1) {
            return null;
        }
        return (float) $m[1];
    }

    private function openFds(): ?int
    {
        $entries = @scandir($this->procRoot . '/self/fd');
        if ($entries === false) {
            return null;
        }
        return max(0, count($entries) - 2);
    }

    /**
     * limits parses the soft limits of /proc/self/limits ("unlimited" →
     * the maximum uint64 value, like procfs).
     *
     * @return array<string, float>|null
     */
    private function limits(): ?array
    {
        $raw = @file_get_contents($this->procRoot . '/self/limits');
        if ($raw === false) {
            return null;
        }
        $limits = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^(Max [a-z ]+?)\s{2,}(\S+)\s+(\S+)/', $line, $m) !== 1) {
                continue;
            }
            $soft = $m[2];
            $limits[$m[1]] = $soft === 'unlimited' ? 18446744073709551615.0 : (float) $soft;
        }
        return $limits;
    }

    /**
     * netstat reads IpExt InOctets/OutOctets from /proc/self/net/netstat.
     *
     * @return array{in: float, out: float}|null
     */
    private function netstat(): ?array
    {
        $raw = @file_get_contents($this->procRoot . '/self/net/netstat');
        if ($raw === false) {
            return null;
        }
        $lines = explode("\n", $raw);
        $count = count($lines);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            if (!str_starts_with($lines[$i], 'IpExt:')) {
                continue;
            }
            $headers = preg_split('/\s+/', trim(substr($lines[$i], 6))) ?: [];
            $values = preg_split('/\s+/', trim(substr($lines[$i + 1], 6))) ?: [];
            $map = [];
            foreach ($headers as $idx => $header) {
                $map[$header] = (float) ($values[$idx] ?? 0);
            }
            return ['in' => $map['InOctets'] ?? 0.0, 'out' => $map['OutOctets'] ?? 0.0];
        }
        return null;
    }
}
