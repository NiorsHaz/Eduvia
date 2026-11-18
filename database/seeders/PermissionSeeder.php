<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',
            'students.view', 'students.manage', 'students.medical',
            'guardians.view', 'guardians.manage',
            'staff.view', 'staff.manage',
            'academics.view', 'academics.manage',
            'timetable.view', 'timetable.manage',
            'attendance.view', 'attendance.manage',
            'grading.view', 'grading.entry', 'grading.manage', 'grading.validate',
            'results.view', 'results.manage',
            'finance.view', 'finance.manage', 'finance.approve',
            'settings.view', 'settings.manage',
            'users.manage', 'audit.view',
            'portal.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $matrix = [
            'super_admin' => $permissions,
            // The director runs the school, including staff login accounts.
            'director' => $permissions,
            'academic_director' => [
                'dashboard.view', 'students.view', 'students.manage', 'guardians.view', 'staff.view',
                'academics.view', 'academics.manage', 'timetable.view', 'timetable.manage',
                'attendance.view', 'attendance.manage', 'grading.view', 'grading.manage', 'grading.validate',
                'results.view', 'results.manage', 'finance.view', 'settings.view', 'audit.view',
            ],
            'admin_staff' => [
                'dashboard.view', 'students.view', 'students.manage', 'students.medical',
                'guardians.view', 'guardians.manage', 'staff.view', 'academics.view', 'academics.manage',
                'timetable.view', 'attendance.view', 'attendance.manage', 'grading.view', 'results.view',
                'finance.view', 'settings.view',
            ],
            'finance' => [
                'dashboard.view', 'students.view', 'guardians.view', 'academics.view',
                'finance.view', 'finance.manage', 'finance.approve', 'audit.view',
            ],
            'teacher' => [
                'dashboard.view', 'students.view', 'academics.view', 'timetable.view',
                'attendance.view', 'attendance.manage', 'grading.view', 'grading.entry', 'results.view',
            ],
            'class_supervisor' => [
                'dashboard.view', 'students.view', 'academics.view', 'timetable.view',
                'attendance.view', 'attendance.manage', 'grading.view', 'results.view',
            ],
            'guardian' => ['portal.view'],
            'student' => ['portal.view'],
        ];

        foreach ($matrix as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
