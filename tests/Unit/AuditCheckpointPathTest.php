<?php

namespace Tests\Unit;

use App\Support\Audit\FileAuditCheckpointStore;
use Tests\TestCase;

class AuditCheckpointPathTest extends TestCase
{
    public function test_relative_parent_and_web_root_paths_are_rejected(): void
    {
        $project = str_replace('/', '\\', base_path());
        $parent = str_replace('/', '\\', dirname(base_path()));

        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot('checkpoints\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot('checkpoints/audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot('..\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot($project.'\\..\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot($project.'\\storage\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot(strtoupper($project).'\\storage\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot($parent.'\\audit.jsonl'));
        $this->assertFalse(FileAuditCheckpointStore::pathIsOutsideWebRoot(substr($project, 0, 2).'audit.jsonl'));
    }

    public function test_an_absolute_path_outside_the_web_root_is_accepted(): void
    {
        $project = str_replace('/', '\\', base_path());
        $outside = str_starts_with(strtolower($project), 'c:')
            ? 'D:\\MediGuideAuditOutside\\audit.jsonl'
            : 'C:\\MediGuideAuditOutside\\audit.jsonl';

        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot($outside));
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot(str_replace('\\', '/', $outside)));
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot('\\\\checkpoint-host\\audit\\checkpoint.jsonl'));
    }
}
