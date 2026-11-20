<?php

namespace Tests\Unit;

use App\Support\Audit\FileAuditCheckpointStore;
use Tests\TestCase;

class AuditCheckpointPathTest extends TestCase
{
    public function test_relative_and_traversal_paths_are_rejected(): void
    {
        $project = str_replace('\\', '/', base_path());
        $parent = str_replace('\\', '/', dirname(base_path()));

        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot('checkpoints/audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot('checkpoints\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot('../audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot('..\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot($project.'/../audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot($project.'\\..\\audit.jsonl'));
    }

    public function test_project_and_web_parent_paths_are_rejected(): void
    {
        $project = str_replace('\\', '/', base_path());
        $parent = str_replace('\\', '/', dirname(base_path()));

        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot($project.'/storage/audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot(str_replace('/', '\\', $project).'\\storage\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot(strtoupper(str_replace('/', '\\', $project)).'\\storage\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot($parent.'/audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot(str_replace('/', '\\', $parent).'\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot($project));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot($parent));
    }

    public function test_linux_absolute_paths_outside_the_web_root_are_accepted(): void
    {
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot('/var/lib/mediguide/audit/checkpoint.jsonl'));
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot('/opt/mediguide-secrets/audit/checkpoint.jsonl'));
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot('/home/mediguide/secure-audit/checkpoint.jsonl'));
    }

    public function test_windows_absolute_and_unc_paths_outside_the_web_root_are_accepted(): void
    {
        $project = str_replace('\\', '/', base_path());
        $outside = str_starts_with(strtolower($project), 'c:')
            ? 'D:\\MediGuideAuditOutside\\audit.jsonl'
            : 'C:\\MediGuideAuditOutside\\audit.jsonl';

        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot($outside));
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot(str_replace('\\', '/', $outside)));
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot('\\\\checkpoint-host\\audit\\checkpoint.jsonl'));
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot('//checkpoint-host/audit/checkpoint.jsonl'));
    }

    public function test_drive_relative_windows_paths_are_rejected(): void
    {
        // "C:audit.jsonl" is drive-relative, not absolute.
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot('C:audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot('D:checkpoints\\audit.jsonl'));
    }
}
