<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A minimal MaxMind DB writer — just enough of the format (IPv4 tree, 32-bit records, maps,
 * strings, unsigned ints, doubles, booleans, arrays) to build a fixture the real
 * `maxmind-db/reader` opens, so the MaxMind adapter is tested against the library it uses in
 * production rather than a stub of it. MaxMind's own test databases are not vendored.
 *
 * Networks must not overlap. https://maxmind.github.io/MaxMind-DB/
 */
final class MmdbWriter
{
    /**
     * @param  array<string, array<string, mixed>>  $networks  IPv4 CIDR => record
     */
    public static function write(string $path, array $networks, string $type = 'Cbox-Test'): void
    {
        /** @var list<array{0: array{0: string, 1: int}|null, 1: array{0: string, 1: int}|null}> $nodes */
        $nodes = [[null, null]];
        $blobs = [];

        foreach ($networks as $cidr => $record) {
            [$ip, $length] = explode('/', $cidr);
            $length = (int) $length;
            $bytes = array_values((array) unpack('C*', (string) inet_pton($ip)));
            $blobs[] = self::encode($record);
            $dataIndex = count($blobs) - 1;
            $node = 0;

            for ($i = 0; $i < $length; $i++) {
                $bit = ($bytes[$i >> 3] >> (7 - $i % 8)) & 1;

                if ($i === $length - 1) {
                    $nodes[$node][$bit] = ['data', $dataIndex];

                    break;
                }

                $child = $nodes[$node][$bit];

                if ($child === null || $child[0] !== 'node') {
                    $nodes[] = [null, null];
                    $next = count($nodes) - 1;
                    $nodes[$node][$bit] = ['node', $next];
                    $node = $next;
                } else {
                    $node = $child[1];
                }
            }
        }

        $offsets = [];
        $data = '';

        foreach ($blobs as $index => $blob) {
            $offsets[$index] = strlen($data);
            $data .= $blob;
        }

        $count = count($nodes);
        $record = static fn (?array $child): int => match (true) {
            $child === null => $count,
            $child[0] === 'node' => $child[1],
            default => $count + 16 + $offsets[$child[1]],
        };

        $tree = '';

        foreach ($nodes as [$left, $right]) {
            $tree .= pack('N', $record($left)).pack('N', $record($right));
        }

        $metadata = self::encode([
            'binary_format_major_version' => 2,
            'binary_format_minor_version' => 0,
            'build_epoch' => 1_700_000_000,
            'database_type' => $type,
            'description' => ['en' => 'Cbox ID test fixture'],
            'ip_version' => 4,
            'languages' => ['en'],
            'node_count' => $count,
            'record_size' => 32,
        ]);

        file_put_contents($path, $tree.str_repeat("\0", 16).$data."\xAB\xCD\xEFMaxMind.com".$metadata);
    }

    private static function encode(mixed $value): string
    {
        return match (true) {
            is_string($value) => self::control(2, strlen($value)).$value,
            is_bool($value) => chr($value ? 1 : 0).chr(14 - 7),
            is_int($value) => self::unsigned($value),
            is_float($value) => self::control(3, 8).pack('E', $value),
            is_array($value) && array_is_list($value) => self::extended(11, count($value)).implode('', array_map(self::encode(...), $value)),
            is_array($value) => self::control(7, count($value)).implode('', array_map(
                static fn (string|int $key, mixed $item): string => self::encode((string) $key).self::encode($item),
                array_keys($value),
                $value,
            )),
            default => throw new \InvalidArgumentException('Unsupported value'),
        };
    }

    private static function unsigned(int $value): string
    {
        $bytes = ltrim(pack('N', $value), "\0");

        return self::control(6, strlen($bytes)).$bytes;
    }

    private static function control(int $type, int $size): string
    {
        if ($size < 29) {
            return chr(($type << 5) | $size);
        }

        return chr(($type << 5) | 29).chr($size - 29);
    }

    private static function extended(int $type, int $size): string
    {
        return chr($size).chr($type - 7);
    }
}
