<?php

namespace Tests\Feature\Clinic;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Models\Clinic;
use App\Models\Department;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinic_can_be_created_under_a_valid_department(): void
    {
        $department = $this->developmentDepartment('Development Department A');

        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic A',
            'description' => 'Placeholder clinic for local development. Not hospital data.',
            'status' => ClinicStatus::Active,
        ]);

        $this->assertDatabaseHas('clinics', [
            'id' => $clinic->id,
            'department_id' => $department->id,
            'name' => 'Development Clinic A',
            'description' => 'Placeholder clinic for local development. Not hospital data.',
            'status' => ClinicStatus::Active->value,
        ]);
        $this->assertNull(Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic Unnamed Description',
            'description' => null,
            'status' => ClinicStatus::Active,
        ])->description);
    }

    public function test_clinic_belongs_to_department(): void
    {
        $department = $this->developmentDepartment('Development Department A');

        $clinic = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic A',
            'status' => ClinicStatus::Active,
        ]);

        $this->assertTrue($clinic->department->is($department));
        $this->assertSame('Development Department A', $clinic->department->name);
    }

    public function test_department_has_many_clinics(): void
    {
        $department = $this->developmentDepartment('Development Department A');

        $first = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic A',
            'status' => ClinicStatus::Active,
        ]);

        $second = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic B',
            'status' => ClinicStatus::Inactive,
        ]);

        $clinics = $department->clinics()->orderBy('name')->get();

        $this->assertCount(2, $clinics);
        $this->assertTrue($clinics->contains($first));
        $this->assertTrue($clinics->contains($second));
    }

    public function test_clinic_status_supports_active_and_inactive(): void
    {
        $department = $this->developmentDepartment('Development Department A');

        $active = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic Active',
            'status' => ClinicStatus::Active,
        ]);

        $inactive = Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic Inactive',
            'status' => ClinicStatus::Inactive,
        ]);

        $this->assertSame(ClinicStatus::Active, $active->fresh()->status);
        $this->assertSame('active', $active->fresh()->status->value);
        $this->assertSame('ACTIVE', $active->fresh()->status->label());

        $this->assertSame(ClinicStatus::Inactive, $inactive->fresh()->status);
        $this->assertSame('inactive', $inactive->fresh()->status->value);
        $this->assertSame('INACTIVE', $inactive->fresh()->status->label());
    }

    public function test_clinic_name_cannot_be_duplicated_inside_the_same_department(): void
    {
        $department = $this->developmentDepartment('Development Department A');

        Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic A',
            'status' => ClinicStatus::Active,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic A',
            'status' => ClinicStatus::Inactive,
        ]);
    }

    public function test_same_clinic_name_may_exist_under_different_departments(): void
    {
        $firstDepartment = $this->developmentDepartment('Development Department A');
        $secondDepartment = $this->developmentDepartment('Development Department B');

        $first = Clinic::query()->create([
            'department_id' => $firstDepartment->id,
            'name' => 'Development Clinic A',
            'status' => ClinicStatus::Active,
        ]);

        $second = Clinic::query()->create([
            'department_id' => $secondDepartment->id,
            'name' => 'Development Clinic A',
            'status' => ClinicStatus::Active,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('Development Clinic A', $first->name);
        $this->assertSame('Development Clinic A', $second->name);
        $this->assertSame(2, Clinic::query()->where('name', 'Development Clinic A')->count());
    }

    public function test_clinic_cannot_reference_a_nonexistent_department(): void
    {
        $this->expectException(QueryException::class);

        Clinic::query()->create([
            'department_id' => 999_999,
            'name' => 'Development Clinic A',
            'status' => ClinicStatus::Active,
        ]);
    }

    public function test_department_deletion_is_restricted_when_clinics_reference_it(): void
    {
        $department = $this->developmentDepartment('Development Department A');

        Clinic::query()->create([
            'department_id' => $department->id,
            'name' => 'Development Clinic A',
            'status' => ClinicStatus::Active,
        ]);

        try {
            $department->delete();
            $this->fail('Department deletion should be restricted when clinics reference it.');
        } catch (QueryException) {
            // Expected: RESTRICT prevents deleting a department that still has clinics.
        }

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
        $this->assertDatabaseHas('clinics', [
            'department_id' => $department->id,
            'name' => 'Development Clinic A',
        ]);
    }

    public function test_development_clinic_seeder_creates_placeholder_records(): void
    {
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);

        $this->assertDatabaseHas('clinics', [
            'name' => 'Development Clinic A',
            'status' => ClinicStatus::Active->value,
        ]);
        $this->assertDatabaseHas('clinics', [
            'name' => 'Development Clinic B',
            'status' => ClinicStatus::Active->value,
        ]);
        $this->assertDatabaseHas('clinics', [
            'name' => 'Development Clinic C',
            'status' => ClinicStatus::Inactive->value,
        ]);
        $this->assertSame(3, Clinic::query()->count());

        $clinicA = Clinic::query()->where('name', 'Development Clinic A')->firstOrFail();
        $this->assertSame('Development Department A', $clinicA->department->name);

        $this->assertFalse(
            Clinic::query()->pluck('name')->contains(fn (string $name) => str_contains($name, 'Queen Mary')),
        );
        $this->assertFalse(
            Clinic::query()->pluck('name')->contains(fn (string $name) => str_contains($name, 'Pediatric')),
        );
        $this->assertFalse(
            Clinic::query()->pluck('name')->contains(fn (string $name) => str_contains($name, 'Orthopedic')),
        );
    }

    public function test_development_clinic_seeder_is_safe_to_re_run(): void
    {
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);

        $this->assertSame(3, Clinic::query()->count());
        $this->assertSame(3, Department::query()->count());
    }

    private function developmentDepartment(string $name): Department
    {
        return Department::query()->create([
            'name' => $name,
            'description' => 'Placeholder department for local development. Not hospital data.',
            'status' => DepartmentStatus::Active,
        ]);
    }
}
