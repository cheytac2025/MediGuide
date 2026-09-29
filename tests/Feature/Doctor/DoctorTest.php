<?php

namespace Tests\Feature\Doctor;

use App\Enums\ClinicStatus;
use App\Enums\DepartmentStatus;
use App\Enums\DoctorStatus;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\User;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_be_created_under_a_valid_clinic(): void
    {
        $clinic = $this->developmentClinic('Development Clinic A');

        $doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor 1',
            'specialization' => 'Development Specialization A',
            'status' => DoctorStatus::Active,
        ]);

        $this->assertDatabaseHas('doctors', [
            'id' => $doctor->id,
            'clinic_id' => $clinic->id,
            'user_id' => null,
            'display_name' => 'Test Doctor 1',
            'specialization' => 'Development Specialization A',
            'status' => DoctorStatus::Active->value,
        ]);
        $this->assertNull(Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor Unnamed Specialization',
            'specialization' => null,
            'status' => DoctorStatus::Active,
        ])->specialization);
    }

    public function test_doctor_belongs_to_clinic(): void
    {
        $clinic = $this->developmentClinic('Development Clinic A');

        $doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor 1',
            'status' => DoctorStatus::Active,
        ]);

        $this->assertTrue($doctor->clinic->is($clinic));
        $this->assertSame('Development Clinic A', $doctor->clinic->name);
    }

    public function test_clinic_has_many_doctors(): void
    {
        $clinic = $this->developmentClinic('Development Clinic A');

        $first = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor 1',
            'status' => DoctorStatus::Active,
        ]);

        $second = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor 2',
            'status' => DoctorStatus::Inactive,
        ]);

        $doctors = $clinic->doctors()->orderBy('display_name')->get();

        $this->assertCount(2, $doctors);
        $this->assertTrue($doctors->contains($first));
        $this->assertTrue($doctors->contains($second));
    }

    public function test_doctor_status_supports_active_and_inactive(): void
    {
        $clinic = $this->developmentClinic('Development Clinic A');

        $active = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor Active',
            'status' => DoctorStatus::Active,
        ]);

        $inactive = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor Inactive',
            'status' => DoctorStatus::Inactive,
        ]);

        $this->assertSame(DoctorStatus::Active, $active->fresh()->status);
        $this->assertSame('active', $active->fresh()->status->value);
        $this->assertSame('ACTIVE', $active->fresh()->status->label());

        $this->assertSame(DoctorStatus::Inactive, $inactive->fresh()->status);
        $this->assertSame('inactive', $inactive->fresh()->status->value);
        $this->assertSame('INACTIVE', $inactive->fresh()->status->label());
    }

    public function test_doctor_may_exist_with_null_user_id(): void
    {
        $clinic = $this->developmentClinic('Development Clinic A');

        $doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => null,
            'display_name' => 'Test Doctor 1',
            'status' => DoctorStatus::Active,
        ]);

        $this->assertNull($doctor->user_id);
        $this->assertNull($doctor->user);
        $this->assertDatabaseHas('doctors', [
            'id' => $doctor->id,
            'user_id' => null,
        ]);
    }

    public function test_doctor_may_optionally_reference_an_existing_user(): void
    {
        $clinic = $this->developmentClinic('Development Clinic A');
        $user = User::factory()->create();

        $doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'display_name' => 'Test Doctor 1',
            'status' => DoctorStatus::Active,
        ]);

        $this->assertTrue($doctor->user?->is($user));
        $this->assertTrue($user->fresh()?->doctor?->is($doctor));
        $this->assertDatabaseHas('doctors', [
            'id' => $doctor->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_deleting_linked_user_nulls_user_id_and_preserves_doctor(): void
    {
        $clinic = $this->developmentClinic('Development Clinic A');
        $user = User::factory()->create();

        $doctor = Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'display_name' => 'Test Doctor 1',
            'status' => DoctorStatus::Active,
        ]);

        $user->delete();

        $doctor->refresh();

        $this->assertNull($doctor->user_id);
        $this->assertDatabaseHas('doctors', [
            'id' => $doctor->id,
            'display_name' => 'Test Doctor 1',
            'user_id' => null,
        ]);
    }

    public function test_doctor_cannot_reference_a_nonexistent_clinic(): void
    {
        $this->expectException(QueryException::class);

        Doctor::query()->create([
            'clinic_id' => 999_999,
            'display_name' => 'Test Doctor 1',
            'status' => DoctorStatus::Active,
        ]);
    }

    public function test_clinic_deletion_is_restricted_when_doctors_reference_it(): void
    {
        $clinic = $this->developmentClinic('Development Clinic A');

        Doctor::query()->create([
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor 1',
            'status' => DoctorStatus::Active,
        ]);

        try {
            $clinic->delete();
            $this->fail('Clinic deletion should be restricted when doctors reference it.');
        } catch (QueryException) {
            // Expected: RESTRICT prevents deleting a clinic that still has doctors.
        }

        $this->assertDatabaseHas('clinics', ['id' => $clinic->id]);
        $this->assertDatabaseHas('doctors', [
            'clinic_id' => $clinic->id,
            'display_name' => 'Test Doctor 1',
        ]);
    }

    public function test_development_doctor_seeder_creates_placeholder_records(): void
    {
        $this->seedDevelopmentDoctors();

        $this->assertDatabaseHas('doctors', [
            'display_name' => 'Test Doctor 1',
            'specialization' => 'Development Specialization A',
            'status' => DoctorStatus::Active->value,
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('doctors', [
            'display_name' => 'Test Doctor 2',
            'specialization' => 'Development Specialization B',
            'status' => DoctorStatus::Active->value,
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('doctors', [
            'display_name' => 'Test Doctor 3',
            'specialization' => 'Development Specialization A',
            'status' => DoctorStatus::Inactive->value,
            'user_id' => null,
        ]);
        $this->assertSame(3, Doctor::query()->count());

        $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
        $this->assertSame('Development Clinic A', $doctor->clinic->name);

        $this->assertFalse(
            Doctor::query()->pluck('display_name')->contains(fn (string $name) => str_contains($name, 'Queen Mary')),
        );
    }

    public function test_development_doctor_seeder_is_safe_to_re_run(): void
    {
        $this->seedDevelopmentDoctors();
        $this->seed(DevelopmentDoctorSeeder::class);

        $this->assertSame(3, Doctor::query()->count());
        $this->assertSame(3, Clinic::query()->count());
        $this->assertTrue(Doctor::query()->whereNull('user_id')->count() === 3);
    }

    private function seedDevelopmentDoctors(): void
    {
        $this->seed(DevelopmentDepartmentSeeder::class);
        $this->seed(DevelopmentClinicSeeder::class);
        $this->seed(DevelopmentDoctorSeeder::class);
    }

    private function developmentClinic(string $name): Clinic
    {
        $department = Department::query()->create([
            'name' => 'Development Department '.$name,
            'description' => 'Placeholder department for local development. Not hospital data.',
            'status' => DepartmentStatus::Active,
        ]);

        return Clinic::query()->create([
            'department_id' => $department->id,
            'name' => $name,
            'description' => 'Placeholder clinic for local development. Not hospital data.',
            'status' => ClinicStatus::Active,
        ]);
    }
}
