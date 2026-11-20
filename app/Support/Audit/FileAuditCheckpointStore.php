<?php

namespace App\Support\Audit;

use RuntimeException;

/**
 * Append-only checkpoint file. Reading never creates or rewrites it.
 * Only the latest record is accepted. An older valid line is not used when
 * the latest record cannot be read. Protection still depends on the file
 * living outside the web root and on copies kept off the database host.
 */
class FileAuditCheckpointStore implements AuditCheckpointStore
{
    public const MESSAGES = [
        'missing' => 'Checkpoint protection is configured, but the checkpoint file is missing.',
        'unreadable' => 'Checkpoint protection is configured, but the checkpoint file cannot be read.',
        'empty' => 'Checkpoint protection is configured, but the checkpoint file is empty.',
    ];

    public function __construct(private readonly string $path) {}

    public function status(): AuditCheckpointStatus
    {
        if (! file_exists($this->path)) {
            return AuditCheckpointStatus::problem('missing', self::MESSAGES['missing']);
        }

        if (! is_file($this->path) || ! is_readable($this->path)) {
            return AuditCheckpointStatus::problem('unreadable', self::MESSAGES['unreadable']);
        }

        $raw = file_get_contents($this->path);

        if ($raw === false) {
            return AuditCheckpointStatus::problem('unreadable', self::MESSAGES['unreadable']);
        }

        $body = rtrim($raw, "\r\n");

        if ($body === '') {
            return AuditCheckpointStatus::problem('empty', self::MESSAGES['empty']);
        }

        $lines = preg_split("/\r\n|\n|\r/", $body);

        if ($lines === false || $lines === []) {
            return AuditCheckpointStatus::problem('malformed', AuditCheckpointRecord::MESSAGES['malformed']);
        }

        $decoded = json_decode((string) $lines[array_key_last($lines)], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return AuditCheckpointStatus::problem('malformed', AuditCheckpointRecord::MESSAGES['malformed']);
        }

        $inspected = AuditCheckpointRecord::inspect($decoded);

        if ($inspected['checkpoint'] === null) {
            return AuditCheckpointStatus::problem($inspected['state'], $inspected['message']);
        }

        return AuditCheckpointStatus::ready($inspected['checkpoint']);
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
        if (str_contains($path, '..')) {
            return false;
        }

        // Normalize separators so Linux (/var/...) and Windows (C:\... or C:/...)
        // absolute paths are both recognized. The previous backslash-only absolute
        // check rejected valid Linux production paths such as /var/lib/mediguide/...
        $normalized = str_replace('\\', '/', $path);
        $project = rtrim(str_replace('\\', '/', base_path()), '/');
        $webParent = rtrim(str_replace('\\', '/', dirname(base_path())), '/');

        $absolute = str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || str_starts_with($normalized, '//');

        if (! $absolute) {
            return false;
        }

        $full = strtolower($normalized);
        $projectPrefix = strtolower($project).'/';
        $webParentPrefix = strtolower($webParent).'/';

        return ! str_starts_with($full, $projectPrefix)
            && ! str_starts_with($full, $webParentPrefix)
            && $full !== strtolower($project)
            && $full !== strtolower($webParent);
    }
}
