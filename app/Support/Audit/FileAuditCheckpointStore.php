<?php

namespace App\Support\Audit;

use RuntimeException;

/**
 * Append-only checkpoint file. Protection depends on the file living outside
 * the web root and on copies being kept off the database host.
 */
class FileAuditCheckpointStore implements AuditCheckpointStore
{
    public function __construct(private readonly string $path) {}

    public function latest(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false || $lines === []) {
            return null;
        }

        $decoded = json_decode((string) end($lines), true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['sequence', 'tag', 'key_version', 'occurred_at', 'checkpoint_tag'] as $key) {
            if (! array_key_exists($key, $decoded)) {
                return null;
            }
        }

        return [
            'sequence' => (int) $decoded['sequence'],
            'tag' => (string) $decoded['tag'],
            'key_version' => (int) $decoded['key_version'],
            'occurred_at' => (string) $decoded['occurred_at'],
            'checkpoint_tag' => (string) $decoded['checkpoint_tag'],
        ];
    }

    public function put(array $checkpoint): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The audit checkpoint directory could not be created.');
        }

        $line = json_encode($checkpoint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $written = file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);

        if ($written === false) {
            throw new RuntimeException('The audit checkpoint could not be written.');
        }
    }

    public static function pathIsOutsideWebRoot(string $path): bool
    {
        $full = str_replace('/', '\\', $path);
        $project = str_replace('/', '\\', base_path());
        $webParent = str_replace('/', '\\', dirname(base_path()));
        $absolute = preg_match('/^[A-Za-z]:\\\\/', $full) === 1 || str_starts_with($full, '\\\\');

        if (! $absolute || str_contains($full, '..')) {
            return false;
        }

        return ! str_starts_with(strtolower($full), strtolower(rtrim($project, '\\').'\\'))
            && ! str_starts_with(strtolower($full), strtolower(rtrim($webParent, '\\').'\\'));
    }
}
