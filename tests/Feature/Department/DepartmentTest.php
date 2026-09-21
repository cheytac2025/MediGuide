<?php

namespace Tests\Feature\Department;

use App\Enums\DepartmentStatus;
use App\Models\Department;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_can_be_created(): void
    {
        $department = Department::query()->create([
            'name' => 'Development Department A',
            'description' => 'Placeholder department for local development. Not hospital data.',
            'status' => DepartmentStatus::Active,
        ]);

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'name' => 'Development Department A',
            'description' => 'Placeholder department for local development. Not hospital data.',
            'status' => DepartmentStatus::Active->value,
        ]);
        $this->assertSame(DepartmentStatus::Active, $department->status);
        $this->assertNull(Department::query()->create([
            'name' => 'Development Department Unnamed Description',
            'description' => null,
            'status' => DepartmentStatus::Active,
        ])->description);
    }

    public function test_department_name_must_be_unique(): void
    {
        Department::query()->create([
            'name' => 'Development Department A',
            'status' => DepartmentStatus::Active,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Department::query()->create([
            'name' => 'Development Department A',
            'status' => DepartmentStatus::Inactive,
        ]);
    }

    public function test_department_status_supports_active_and_inactive(): void
    {
        $active = Department::query()->create([
            'name' => 'Development Department Active',
            'status' => DepartmentStatus::Active,
        ]);

        $inactive = Department::query()->create([
            'name' => 'Development Department Inactive',
            'status' => DepartmentStatus::Inactive,
        ]);

        $this->assertSame(DepartmentStatus::Active, $active->fresh()->status);
        $this->assertSame('active', $active->fresh()->status->value);
        $this->assertSame('ACTIVE', $active->fresh()->status->label());

        $this->assertSame(DepartmentStatus::Inactive, $inactive->fresh()->status);
        $this->assertSame('inactive', $inactive->fresh()->status->value);
        $this->assertSame('INACTIVE', $inactive->fresh()->status->label());
    }

    public function test_development_department_seeder_creates_placeholder_records(): void
    {
        $this->seed(DevelopmentDepartmentSeeder::class);

        $this->assertDatabaseHas('departments', [
            'name' => 'Development Department A',
            'status' => DepartmentStatus::Active->value,
        ]);
        $this->assertDatabaseHas('departments', [
            'name' => 'Development Department B',
            'status' => DepartmentStatus::Active->value,
        ]);
        $this->assertDatabaseHas('departments', [
            'name' => 'Development Department C',
            'status' => DepartmentStatus::Inactive->value,
        ]);
        $this->assertSame(3, Department::query()->count());

        $this->seed(DevelopmentDepartmentSeeder::class);

        $this->assertSame(3, Department::query()->count());
        $this->assertFalse(
            Department::query()->pluck('name')->contains(fn (string $name) => str_contains($name, 'Queen Mary')),
        );
    }
}
