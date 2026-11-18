<?php

/**
 * Reference data resources. `fields` is the writable whitelist, `rules` the
 * validation contract, `filters` the query-string filters the UI may use.
 */
return [
    'levels' => [
        'table' => 'levels', 'fields' => ['code', 'name', 'cycle', 'sort_order'],
        'search' => ['code', 'name'], 'sort' => 'sort_order',
        'rules' => ['code' => 'required|string|max:20', 'name' => 'required|string|max:120', 'cycle' => 'nullable|string|max:30', 'sort_order' => 'integer'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'tracks' => [
        'table' => 'tracks', 'fields' => ['code', 'name'],
        'search' => ['code', 'name'],
        'rules' => ['code' => 'required|string|max:20', 'name' => 'required|string|max:120'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'buildings' => [
        'table' => 'buildings', 'fields' => ['campus_id', 'name'],
        'search' => ['name'],
        'rules' => ['campus_id' => 'nullable|integer|exists:campuses,id', 'name' => 'required|string|max:120'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'rooms' => [
        'table' => 'rooms', 'fields' => ['building_id', 'name', 'room_type', 'capacity', 'is_active'],
        'search' => ['name'], 'filters' => ['building_id'],
        'rules' => ['building_id' => 'nullable|integer|exists:buildings,id', 'name' => 'required|string|max:120', 'room_type' => 'in:classroom,laboratory,library,hall,sports,office,other', 'capacity' => 'nullable|integer|min:1', 'is_active' => 'boolean'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'departments' => [
        'table' => 'departments', 'fields' => ['campus_id', 'name', 'head_staff_id'],
        'search' => ['name'],
        'rules' => ['campus_id' => 'nullable|integer|exists:campuses,id', 'name' => 'required|string|max:120', 'head_staff_id' => 'nullable|integer|exists:staff,id'],
        'read' => 'staff.view', 'write' => 'staff.manage',
    ],
    'subjects' => [
        'table' => 'subjects', 'fields' => ['code', 'name', 'is_optional', 'is_active'],
        'search' => ['code', 'name'],
        'rules' => ['code' => 'required|string|max:20', 'name' => 'required|string|max:120', 'is_optional' => 'boolean', 'is_active' => 'boolean'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'academic-years' => [
        'table' => 'academic_years', 'fields' => ['campus_id', 'name', 'start_date', 'end_date', 'status', 'is_current'],
        'search' => ['name'], 'sort' => 'start_date', 'dir' => 'desc',
        'rules' => ['campus_id' => 'nullable|integer|exists:campuses,id', 'name' => 'required|string|max:40', 'start_date' => 'required|date', 'end_date' => 'required|date|after:start_date', 'status' => 'in:planned,active,closed,archived', 'is_current' => 'boolean'],
        'read' => 'academics.view', 'write' => 'settings.manage',
    ],
    'terms' => [
        'table' => 'terms', 'fields' => ['academic_year_id', 'name', 'sequence', 'start_date', 'end_date', 'data_entry_open', 'weight'],
        'search' => ['name'], 'filters' => ['academic_year_id'], 'sort' => 'sequence',
        'rules' => ['academic_year_id' => 'required|integer|exists:academic_years,id', 'name' => 'required|string|max:60', 'sequence' => 'required|integer|min:1', 'start_date' => 'required|date', 'end_date' => 'required|date|after:start_date', 'data_entry_open' => 'boolean', 'weight' => 'numeric|min:0'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'calendar-periods' => [
        'table' => 'calendar_periods', 'fields' => ['academic_year_id', 'type', 'title', 'start_date', 'end_date'],
        'search' => ['title'], 'filters' => ['academic_year_id'],
        'rules' => ['academic_year_id' => 'required|integer|exists:academic_years,id', 'type' => 'in:holiday,closure,exam_window,deadline,other', 'title' => 'required|string|max:120', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'classes' => [
        'table' => 'classes', 'fields' => ['academic_year_id', 'level_id', 'track_id', 'name', 'capacity', 'supervisor_staff_id', 'home_room_id'],
        'search' => ['name'], 'filters' => ['academic_year_id', 'level_id'],
        'rules' => ['academic_year_id' => 'required|integer|exists:academic_years,id', 'level_id' => 'required|integer|exists:levels,id', 'track_id' => 'nullable|integer|exists:tracks,id', 'name' => 'required|string|max:40', 'capacity' => 'integer|min:1', 'supervisor_staff_id' => 'nullable|integer|exists:staff,id', 'home_room_id' => 'nullable|integer|exists:rooms,id'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'level-subjects' => [
        'table' => 'level_subjects', 'fields' => ['academic_year_id', 'level_id', 'track_id', 'subject_id', 'coefficient', 'weekly_hours'],
        'filters' => ['academic_year_id', 'level_id', 'subject_id'],
        'rules' => ['academic_year_id' => 'required|integer|exists:academic_years,id', 'level_id' => 'required|integer|exists:levels,id', 'track_id' => 'nullable|integer|exists:tracks,id', 'subject_id' => 'required|integer|exists:subjects,id', 'coefficient' => 'numeric|min:0', 'weekly_hours' => 'nullable|numeric|min:0'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'class-subject-teachers' => [
        'table' => 'class_subject_teachers', 'fields' => ['class_id', 'subject_id', 'staff_id', 'starts_on', 'ends_on'],
        'filters' => ['class_id', 'subject_id', 'staff_id'],
        'rules' => ['class_id' => 'required|integer|exists:classes,id', 'subject_id' => 'required|integer|exists:subjects,id', 'staff_id' => 'required|integer|exists:staff,id', 'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on'],
        'read' => 'academics.view', 'write' => 'academics.manage',
    ],
    'staff' => [
        'table' => 'staff', 'fields' => ['user_id', 'campus_id', 'department_id', 'employee_number', 'first_name', 'last_name', 'gender', 'date_of_birth', 'phone', 'email', 'address', 'position', 'staff_type', 'qualifications', 'employment_status', 'hire_date', 'contract_end_date'],
        'search' => ['employee_number', 'first_name', 'last_name'], 'filters' => ['department_id', 'staff_type', 'employment_status'],
        'rules' => ['user_id' => 'nullable|integer|exists:users,id', 'campus_id' => 'nullable|integer|exists:campuses,id', 'department_id' => 'nullable|integer|exists:departments,id', 'employee_number' => 'required|string|max:50', 'first_name' => 'required|string|max:120', 'last_name' => 'required|string|max:120', 'gender' => 'nullable|in:female,male,other', 'date_of_birth' => 'nullable|date', 'phone' => 'nullable|string|max:40', 'email' => 'nullable|email', 'address' => 'nullable|string', 'position' => 'required|string|max:120', 'staff_type' => 'in:teacher,administrative,finance,library,support,other', 'qualifications' => 'nullable|string', 'employment_status' => 'in:active,on_leave,suspended,resigned,terminated,retired', 'hire_date' => 'nullable|date', 'contract_end_date' => 'nullable|date'],
        'read' => 'staff.view', 'write' => 'staff.manage',
    ],
    'guardians' => [
        'table' => 'guardians', 'fields' => ['first_name', 'last_name', 'phone', 'phone_alt', 'email', 'address', 'occupation', 'national_id'],
        'search' => ['first_name', 'last_name', 'phone', 'email'],
        'rules' => ['first_name' => 'required|string|max:120', 'last_name' => 'required|string|max:120', 'phone' => 'nullable|string|max:40', 'phone_alt' => 'nullable|string|max:40', 'email' => 'nullable|email', 'address' => 'nullable|string', 'occupation' => 'nullable|string|max:120', 'national_id' => 'nullable|string|max:60'],
        'read' => 'guardians.view', 'write' => 'guardians.manage',
    ],
    'assessment-types' => [
        'table' => 'assessment_types', 'fields' => ['code', 'name', 'default_weight'],
        'search' => ['code', 'name'],
        'rules' => ['code' => 'required|string|max:40', 'name' => 'required|string|max:120', 'default_weight' => 'numeric|min:0'],
        'read' => 'grading.view', 'write' => 'grading.manage',
    ],
    'grading-scales' => [
        'table' => 'grading_scales', 'fields' => ['name', 'max_score'],
        'search' => ['name'],
        'rules' => ['name' => 'required|string|max:60', 'max_score' => 'required|numeric|min:0.01'],
        'read' => 'grading.view', 'write' => 'settings.manage',
    ],
    'grading-scale-bands' => [
        'table' => 'grading_scale_bands', 'fields' => ['scale_id', 'min_score', 'max_score', 'label', 'is_passing'],
        'filters' => ['scale_id'],
        'rules' => ['scale_id' => 'required|integer|exists:grading_scales,id', 'min_score' => 'required|numeric', 'max_score' => 'required|numeric|gte:min_score', 'label' => 'required|string|max:60', 'is_passing' => 'boolean'],
        'read' => 'grading.view', 'write' => 'settings.manage',
    ],
    'student-categories' => [
        'table' => 'student_categories', 'fields' => ['code', 'name', 'is_active'],
        'search' => ['code', 'name'],
        'rules' => ['code' => 'required|string|max:40', 'name' => 'required|string|max:120', 'is_active' => 'boolean'],
        'read' => 'students.view', 'write' => 'settings.manage',
    ],
    'fee-categories' => [
        'table' => 'fee_categories', 'fields' => ['code', 'name', 'kind', 'allocation_priority', 'is_refundable', 'is_active'],
        'search' => ['code', 'name'],
        'rules' => ['code' => 'required|string|max:40', 'name' => 'required|string|max:120', 'kind' => 'in:registration,tuition,exam,transport,supplies,activity,custom', 'allocation_priority' => 'integer', 'is_refundable' => 'boolean', 'is_active' => 'boolean'],
        'read' => 'finance.view', 'write' => 'finance.manage',
    ],
    'installment-plans' => [
        'table' => 'installment_plans', 'fields' => ['academic_year_id', 'name', 'is_active'],
        'search' => ['name'], 'filters' => ['academic_year_id'],
        'rules' => ['academic_year_id' => 'required|integer|exists:academic_years,id', 'name' => 'required|string|max:120', 'is_active' => 'boolean'],
        'read' => 'finance.view', 'write' => 'finance.manage',
    ],
    'installment-plan-items' => [
        'table' => 'installment_plan_items', 'fields' => ['plan_id', 'sequence', 'label', 'percentage', 'due_date'],
        'filters' => ['plan_id'], 'sort' => 'sequence',
        'rules' => ['plan_id' => 'required|integer|exists:installment_plans,id', 'sequence' => 'required|integer|min:1', 'label' => 'nullable|string|max:120', 'percentage' => 'required|numeric|min:0.01|max:100', 'due_date' => 'required|date'],
        'read' => 'finance.view', 'write' => 'finance.manage',
    ],
    'fee-structures' => [
        'table' => 'fee_structures', 'fields' => ['academic_year_id', 'fee_category_id', 'level_id', 'track_id', 'student_category_id', 'name', 'amount', 'installment_plan_id', 'due_date', 'is_mandatory', 'is_active'],
        'filters' => ['academic_year_id', 'level_id', 'fee_category_id'],
        'rules' => ['academic_year_id' => 'required|integer|exists:academic_years,id', 'fee_category_id' => 'required|integer|exists:fee_categories,id', 'level_id' => 'nullable|integer|exists:levels,id', 'track_id' => 'nullable|integer|exists:tracks,id', 'student_category_id' => 'nullable|integer|exists:student_categories,id', 'name' => 'nullable|string|max:120', 'amount' => 'required|integer|min:0', 'installment_plan_id' => 'nullable|integer|exists:installment_plans,id', 'due_date' => 'required_without:installment_plan_id|nullable|date', 'is_mandatory' => 'boolean', 'is_active' => 'boolean'],
        'read' => 'finance.view', 'write' => 'finance.manage',
    ],
    'discounts' => [
        'table' => 'discounts', 'fields' => ['code', 'name', 'kind', 'value_percent', 'value_fixed', 'fee_category_id', 'eligibility', 'requires_approval', 'is_active'],
        'search' => ['code', 'name'],
        'rules' => ['code' => 'required|string|max:40', 'name' => 'required|string|max:120', 'kind' => 'in:discount,scholarship', 'value_percent' => 'nullable|numeric|min:0.01|max:100', 'value_fixed' => 'nullable|integer|min:1', 'fee_category_id' => 'nullable|integer|exists:fee_categories,id', 'eligibility' => 'nullable|array', 'requires_approval' => 'boolean', 'is_active' => 'boolean'],
        'read' => 'finance.view', 'write' => 'finance.manage',
    ],
    'penalty-rules' => [
        'table' => 'penalty_rules', 'fields' => ['code', 'name', 'calc_type', 'value_fixed', 'value_percent', 'period_days', 'grace_days', 'max_total', 'fee_category_id', 'application', 'is_active'],
        'search' => ['code', 'name'],
        'rules' => ['code' => 'required|string|max:40', 'name' => 'required|string|max:120', 'calc_type' => 'in:fixed,fixed_per_period,percent_of_outstanding,percent_per_period', 'value_fixed' => 'nullable|integer|min:1', 'value_percent' => 'nullable|numeric|min:0.01', 'period_days' => 'nullable|integer|min:1', 'grace_days' => 'integer|min:0', 'max_total' => 'nullable|integer|min:1', 'fee_category_id' => 'nullable|integer|exists:fee_categories,id', 'application' => 'in:automatic,manual', 'is_active' => 'boolean'],
        'read' => 'finance.view', 'write' => 'finance.manage',
    ],
    'payment-methods' => [
        'table' => 'payment_methods', 'fields' => ['code', 'name', 'requires_reference', 'requires_proof', 'requires_verification', 'provider', 'is_active', 'sort_order'],
        'search' => ['code', 'name'],
        'rules' => ['code' => 'required|string|max:40', 'name' => 'required|string|max:120', 'requires_reference' => 'boolean', 'requires_proof' => 'boolean', 'requires_verification' => 'boolean', 'provider' => 'nullable|string|max:60', 'is_active' => 'boolean', 'sort_order' => 'integer'],
        'read' => 'finance.view', 'write' => 'settings.manage',
    ],
    'reminder-schedules' => [
        'table' => 'reminder_schedules', 'fields' => ['name', 'trigger_basis', 'offset_days', 'repeat_every_days', 'max_repeats', 'channels', 'template_key', 'min_outstanding', 'level_id', 'is_active'],
        'search' => ['name'],
        'rules' => ['name' => 'required|string|max:120', 'trigger_basis' => 'in:before_due,after_due', 'offset_days' => 'required|integer|min:0', 'repeat_every_days' => 'nullable|integer|min:1', 'max_repeats' => 'integer|min:1', 'channels' => 'array', 'template_key' => 'required|string|max:120', 'min_outstanding' => 'integer|min:0', 'level_id' => 'nullable|integer|exists:levels,id', 'is_active' => 'boolean'],
        'read' => 'finance.view', 'write' => 'finance.manage',
    ],
    'expense-categories' => [
        'table' => 'expense_categories', 'fields' => ['code', 'name', 'is_active'],
        'search' => ['code', 'name'],
        'rules' => ['code' => 'required|string|max:40', 'name' => 'required|string|max:120', 'is_active' => 'boolean'],
        'read' => 'finance.view', 'write' => 'finance.manage',
    ],
    'number-series' => [
        'table' => 'number_series', 'fields' => ['code', 'pattern', 'is_active'],
        'search' => ['code', 'pattern'], 'id' => 'code',
        'rules' => ['code' => 'required|string|max:40', 'pattern' => 'required|string|max:80', 'is_active' => 'boolean'],
        'read' => 'settings.view', 'write' => 'settings.manage',
    ],
];
