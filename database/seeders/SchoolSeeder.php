<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Building;
use App\Models\Campus;
use App\Models\Classroom;
use App\Models\ClassSubjectTeacher;
use App\Models\Discount;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\GradingPolicy;
use App\Models\GradingScale;
use App\Models\Guardian;
use App\Models\InstallmentPlan;
use App\Models\InstallmentPlanItem;
use App\Models\Level;
use App\Models\LevelSubject;
use App\Models\PromotionRule;
use App\Models\PromotionRuleSet;
use App\Models\PromotionRuleSetLevel;
use App\Models\Room;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableEntry;
use App\Models\Track;
use App\Models\User;
use App\Services\FeeService;
use App\Services\GradeCalculator;
use App\Services\PaymentService;
use App\Services\ReportCardService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class SchoolSeeder extends Seeder
{
    private Campus $campus;

    private AcademicYear $year;

    /** @var array<int, Term> */
    private array $terms = [];

    /** @var array<int, Classroom> */
    private array $classes = [];

    public function run(): void
    {
        mt_srand(20261118);

        $this->campus = Campus::create([
            'name' => config('school.name'),
            'code' => 'MAIN',
            'address' => config('school.address'),
            'phone' => config('school.phone'),
        ]);

        $this->seedYear();
        $levels = $this->seedAcademicStructure();
        $subjects = $this->seedSubjects();
        $this->seedRooms();
        $this->seedStaff();
        $this->seedUsers();
        $this->seedClasses($levels);
        $this->seedSubjectAssignments($subjects);
        $this->seedTimetable();
        $this->seedStudents();
        $this->linkGuardianUser();
        $this->seedPolicy();
        $this->seedAssessments($subjects);
        $this->seedAttendance();
        $this->seedFinance();
        $this->seedReportCards();
    }

    private function seedYear(): void
    {
        $this->year = AcademicYear::create([
            'campus_id' => $this->campus->id,
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'status' => 'active',
            'is_current' => true,
        ]);

        $terms = [
            ['Trimestre 1', 1, '2026-09-01', '2026-12-05'],
            ['Trimestre 2', 2, '2026-12-15', '2027-03-20'],
            ['Trimestre 3', 3, '2027-04-01', '2027-06-25'],
        ];

        foreach ($terms as [$name, $sequence, $start, $end]) {
            $this->terms[] = Term::create([
                'academic_year_id' => $this->year->id,
                'name' => $name,
                'sequence' => $sequence,
                'start_date' => $start,
                'end_date' => $end,
                'data_entry_open' => true,
                'weight' => 1,
            ]);
        }
    }

    /** @return array<string, Level> */
    private function seedAcademicStructure(): array
    {
        $levels = [];
        foreach ([
            ['6e', 'Sixième', 'collège', 1],
            ['5e', 'Cinquième', 'collège', 2],
            ['4e', 'Quatrième', 'collège', 3],
            ['3e', 'Troisième', 'collège', 4],
            ['2nde', 'Seconde', 'lycée', 5],
            ['1ere', 'Première', 'lycée', 6],
            ['Tle', 'Terminale', 'lycée', 7],
        ] as [$code, $name, $cycle, $order]) {
            $levels[$code] = Level::create(['code' => $code, 'name' => $name, 'cycle' => $cycle, 'sort_order' => $order]);
        }

        foreach ([['A', 'Série A'], ['C', 'Série C'], ['D', 'Série D']] as [$code, $name]) {
            Track::create(['code' => $code, 'name' => $name]);
        }

        return $levels;
    }

    /** @return array<string, Subject> */
    private function seedSubjects(): array
    {
        $subjects = [];
        foreach ([
            ['MALG', 'Malagasy'], ['FRA', 'Français'], ['ANG', 'Anglais'],
            ['MATH', 'Mathématiques'], ['PC', 'Physique-Chimie'], ['SVT', 'Sciences de la vie'],
            ['HIST', 'Histoire'], ['GEO', 'Géographie'], ['EPS', 'Éducation physique'], ['PHILO', 'Philosophie'],
        ] as [$code, $name]) {
            $subjects[$code] = Subject::create(['code' => $code, 'name' => $name]);
        }

        $coefficients = ['MALG' => 2, 'FRA' => 3, 'ANG' => 2, 'MATH' => 4, 'PC' => 3, 'SVT' => 2, 'HIST' => 1, 'GEO' => 1, 'EPS' => 1, 'PHILO' => 2];

        foreach (['3e', 'Tle'] as $levelCode) {
            $level = Level::where('code', $levelCode)->first();
            foreach ($coefficients as $code => $coefficient) {
                if ($levelCode === '3e' && $code === 'PHILO') {
                    continue;
                }
                LevelSubject::create([
                    'academic_year_id' => $this->year->id,
                    'level_id' => $level->id,
                    'track_id' => null,
                    'subject_id' => $subjects[$code]->id,
                    'coefficient' => $coefficient,
                    'weekly_hours' => 3,
                ]);
            }
        }

        AssessmentType::create(['code' => 'devoir', 'name' => 'Devoir', 'default_weight' => 1]);
        AssessmentType::create(['code' => 'composition', 'name' => 'Composition', 'default_weight' => 2]);
        AssessmentType::create(['code' => 'interrogation', 'name' => 'Interrogation', 'default_weight' => 0.5]);

        return $subjects;
    }

    private function seedRooms(): void
    {
        $building = Building::create(['campus_id' => $this->campus->id, 'name' => 'Bâtiment principal']);

        foreach (['Salle 101', 'Salle 102', 'Salle 103', 'Salle 201', 'Laboratoire'] as $index => $name) {
            Room::create([
                'building_id' => $building->id,
                'name' => $name,
                'room_type' => $name === 'Laboratoire' ? 'laboratory' : 'classroom',
                'capacity' => 40,
                'is_active' => true,
            ]);
        }
    }

    private function seedStaff(): void
    {
        $department = \App\Models\Department::create(['campus_id' => $this->campus->id, 'name' => 'Sciences']);

        $rows = [
            ['Rakoto', 'Jean', 'Directeur', 'administrative', null],
            ['Rasoanaivo', 'Marie', 'Enseignante de mathématiques', 'teacher', $department->id],
            ['Randriamalala', 'Paul', 'Enseignant de français', 'teacher', null],
            ['Andriamihaja', 'Claudine', 'Enseignante de sciences', 'teacher', $department->id],
            ['Ravelo', 'Hery', 'Enseignant d’histoire-géographie', 'teacher', null],
            ['Andria', 'Nirina', 'Comptable', 'finance', null],
            ['Rakotondrabe', 'Soa', 'Enseignante de malagasy', 'teacher', null],
            ['Razafimahatratra', 'Lova', 'Enseignant d’anglais', 'teacher', null],
            ['Ravelonarivo', 'Mamy', 'Enseignant d’éducation physique', 'teacher', null],
            ['Rakotomalala', 'Onja', 'Enseignante de philosophie', 'teacher', null],
        ];

        foreach ($rows as $index => [$last, $first, $position, $type, $departmentId]) {
            Staff::create([
                'campus_id' => $this->campus->id,
                'department_id' => $departmentId,
                'employee_number' => 'EMP-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'first_name' => $first,
                'last_name' => $last,
                'gender' => in_array($last, ['Rasoanaivo', 'Andriamihaja', 'Rakotondrabe', 'Rakotomalala'], true) ? 'female' : 'male',
                'date_of_birth' => '1985-05-12',
                'phone' => '032'.str_pad((string) (1000000 + $index), 7, '0'),
                'email' => Str::slug($first.'.'.$last).'@school.mg',
                'position' => $position,
                'staff_type' => $type,
                'employment_status' => 'active',
                'hire_date' => '2020-09-01',
            ]);
        }
    }

    /** @param array<string, Level> $levels */
    private function seedClasses(array $levels): void
    {
        $definitions = [
            ['3e', 'A', null, 1],
            ['3e', 'B', null, 2],
            ['Tle', 'C', 'C', 3],
        ];

        foreach ($definitions as [$levelCode, $name, $trackCode, $room]) {
            $this->classes[] = Classroom::create([
                'academic_year_id' => $this->year->id,
                'level_id' => $levels[$levelCode]->id,
                'track_id' => $trackCode ? Track::where('code', $trackCode)->value('id') : null,
                'name' => $name,
                'capacity' => 40,
                'supervisor_staff_id' => Staff::where('staff_type', 'teacher')->value('id'),
                'home_room_id' => Room::where('name', 'Salle 10'.$room)->value('id'),
            ]);
        }
    }

    /** @param array<string, Subject> $subjects */
    private function seedSubjectAssignments(array $subjects): void
    {
        // Each subject has its own teacher, matching the speciality in the staff position.
        $specialities = [
            'MALG' => 'Rakotondrabe',
            'FRA' => 'Randriamalala',
            'ANG' => 'Razafimahatratra',
            'MATH' => 'Rasoanaivo',
            'PC' => 'Andriamihaja',
            'SVT' => 'Andriamihaja',
            'HIST' => 'Ravelo',
            'GEO' => 'Ravelo',
            'EPS' => 'Ravelonarivo',
            'PHILO' => 'Rakotomalala',
        ];

        $teacherIds = Staff::where('staff_type', 'teacher')->pluck('id', 'last_name');

        foreach ($this->classes as $class) {
            foreach ($subjects as $code => $subject) {
                if ($class->level->code === '3e' && $code === 'PHILO') {
                    continue;
                }
                ClassSubjectTeacher::create([
                    'class_id' => $class->id,
                    'subject_id' => $subject->id,
                    'staff_id' => $teacherIds[$specialities[$code]],
                    'starts_on' => '2026-09-01',
                ]);
            }
        }
    }

    private function seedTimetable(): void
    {
        $bands = [['07:30', '09:30'], ['09:45', '11:45'], ['13:30', '15:30']];
        $days = [1, 2, 3, 4, 5];

        foreach ($this->classes as $classIndex => $class) {
            $assignments = ClassSubjectTeacher::with('subject')->where('class_id', $class->id)->get()->values();

            $slot = 0;
            foreach ($days as $dayOffset => $day) {
                $day = $days[($dayOffset + $classIndex) % count($days)];

                foreach ([0, 1] as $bandOffset) {
                    $assignment = $assignments[$slot] ?? null;
                    if (! $assignment) {
                        break 2;
                    }

                    [$start, $end] = $bands[($classIndex + $bandOffset) % count($bands)];
                    TimetableEntry::create([
                        'academic_year_id' => $this->year->id,
                        'class_id' => $class->id,
                        'subject_id' => $assignment->subject_id,
                        'staff_id' => $assignment->staff_id,
                        'room_id' => $class->home_room_id,
                        'day_of_week' => $day,
                        'start_time' => $start,
                        'end_time' => $end,
                        'valid_from' => '2026-09-01',
                    ]);
                    $slot++;
                }
            }
        }
    }

    private function seedStudents(): void
    {
        $firstNames = ['Naina', 'Fara', 'Tiana', 'Miora', 'Andry', 'Fanja', 'Hery', 'Lova', 'Sariaka', 'Toky', 'Vola', 'Manda', 'Rado', 'Onja', 'Fitia', 'Tahina'];
        $lastNames = ['Rakotomalala', 'Rasolofonirina', 'Andrianarivo', 'Razafindrakoto', 'Ranaivo', 'Ramaroson', 'Andriantsitohaina', 'Rabe', 'Rajaona', 'Randrianasolo'];
        $sequence = 1;
        $phone = 3000000;

        foreach ($this->classes as $class) {
            for ($i = 0; $i < 12; $i++) {
                $guardian = Guardian::create([
                    'first_name' => $firstNames[($sequence + 4) % count($firstNames)],
                    'last_name' => $lastNames[$sequence % count($lastNames)],
                    'phone' => '034'.str_pad((string) ($phone++), 7, '0'),
                    'email' => 'parent'.$sequence.'@example.mg',
                    'address' => 'Antananarivo',
                    'occupation' => 'Parent',
                ]);

                $student = Student::create([
                    'campus_id' => $this->campus->id,
                    'student_number' => '2026-2027-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
                    'first_name' => $firstNames[($sequence - 1) % count($firstNames)],
                    'last_name' => $lastNames[($sequence - 1) % count($lastNames)],
                    'gender' => $i % 2 === 0 ? 'female' : 'male',
                    'date_of_birth' => '2011-0'.(($i % 9) + 1).'-1'.($i % 9),
                    'place_of_birth' => 'Antananarivo',
                    'nationality' => 'Malgache',
                    'address' => 'Antananarivo',
                    'status' => 'enrolled',
                    'admission_date' => '2026-09-01',
                    'student_category_id' => \App\Models\StudentCategory::where('code', 'standard')->value('id'),
                ]);

                StudentGuardian::create([
                    'student_id' => $student->id,
                    'guardian_id' => $guardian->id,
                    'relationship' => $i % 2 === 0 ? 'mother' : 'father',
                    'is_primary_contact' => true,
                    'is_emergency_contact' => true,
                    'can_view_academic' => true,
                    'can_view_finance' => true,
                    'verified_at' => now(),
                    'verified_by' => 1,
                ]);

                Enrollment::create([
                    'student_id' => $student->id,
                    'class_id' => $class->id,
                    'academic_year_id' => $this->year->id,
                    'start_date' => '2026-09-01',
                    'status' => 'active',
                    'created_at' => now(),
                ]);

                $sequence++;
            }
        }
    }

    private function seedPolicy(): void
    {
        $policy = GradingPolicy::create([
            'academic_year_id' => $this->year->id,
            'level_id' => null,
            'scale_id' => GradingScale::where('name', 'Sur 20')->value('id'),
            'name' => 'Politique standard /20',
            'pass_mark' => 10,
            'rounding_decimals' => 2,
            'rounding_mode' => 'half_up',
            'ranking_enabled' => true,
            'term_weights' => collect($this->terms)->mapWithKeys(fn ($t) => [(string) $t->id => 1])->all(),
            'absence_handling' => 'ignore',
            'missing_grade_handling' => 'ignore',
            'averaging_method' => 'weighted',
            'subject_aggregation' => 'coefficient_weighted',
            'term_aggregation' => 'weighted_terms',
            'eliminatory_mark' => 5,
            'min_graded_assessments' => 1,
            'ranking_scope' => 'class',
            'show_rank_on_report_card' => true,
            'support_threshold' => 10,
            'version' => 1,
            'is_active' => true,
        ]);

        $set = PromotionRuleSet::create([
            'policy_id' => $policy->id,
            'name' => 'Règles standard',
            'description' => 'Moyenne ≥ 10 sans note éliminatoire : promu. Entre 9 et 10 : conseil. Sinon : redoublement.',
        ]);
        PromotionRuleSetLevel::create(['rule_set_id' => $set->id, 'level_id' => null]);

        foreach ([
            [10, 'Moyenne ≥ 10 et aucune note éliminatoire', ['all' => [
                ['metric' => 'annual_average', 'op' => '>=', 'value' => 10],
                ['metric' => 'subjects_below_eliminatory', 'op' => '=', 'value' => 0],
            ]], 'promoted'],
            [20, 'Moyenne entre 9 et 10', ['all' => [
                ['metric' => 'annual_average', 'op' => '>=', 'value' => 9],
                ['metric' => 'annual_average', 'op' => '<', 'value' => 10],
            ]], 'council_review'],
            [90, 'Autres cas', ['all' => []], 'repeat'],
        ] as [$priority, $label, $condition, $outcome]) {
            PromotionRule::create([
                'rule_set_id' => $set->id,
                'priority' => $priority,
                'label' => $label,
                'condition' => $condition,
                'outcome' => $outcome,
                'requires_human_confirmation' => true,
                'is_active' => true,
            ]);
        }
    }

    /** @param array<string, Subject> $subjects */
    private function seedAssessments(array $subjects): void
    {
        $types = AssessmentType::all()->keyBy('code');
        $calculator = app(GradeCalculator::class);
        $term = $this->terms[0];

        foreach ($this->classes as $class) {
            $students = $class->students()->get();
            $assignments = ClassSubjectTeacher::where('class_id', $class->id)->get();

            foreach ($assignments as $assignment) {
                foreach ([['devoir', 1, 'Devoir 1'], ['composition', 2, 'Composition']] as [$type, $weight, $title]) {
                    $assessment = Assessment::create([
                        'class_subject_teacher_id' => $assignment->id,
                        'term_id' => $term->id,
                        'assessment_type_id' => $types[$type]->id,
                        'title' => $title.' - '.(Subject::find($assignment->subject_id)?->name ?? ''),
                        'assessment_date' => '2026-10-'.str_pad((string) mt_rand(5, 28), 2, '0', STR_PAD_LEFT),
                        'max_score' => 20,
                        'weight' => $weight,
                        'status' => 'open',
                        'created_by' => 1,
                    ]);

                    foreach ($students as $student) {
                        $roll = mt_rand(1, 100);
                        $status = $roll <= 4 ? 'absent' : ($roll <= 6 ? 'excused' : ($roll <= 8 ? 'not_graded' : 'graded'));

                        \App\Models\Grade::create([
                            'assessment_id' => $assessment->id,
                            'student_id' => $student->id,
                            'score' => $status === 'graded' ? mt_rand(60, 190) / 10 : null,
                            'status' => $status,
                            'entered_by' => 1,
                        ]);
                    }
                }
            }

            foreach ($assignments->pluck('subject_id')->unique() as $subjectId) {
                $calculator->computeSubjectTerm($class->id, (int) $subjectId, $term->id);
            }
            $calculator->computeTerm($class->id, $term->id);
        }
    }

    private function seedAttendance(): void
    {
        $dates = ['2026-11-09', '2026-11-10', '2026-11-12', '2026-11-13', '2026-11-16'];

        foreach ($this->classes as $class) {
            $students = $class->students()->get();

            foreach ($dates as $date) {
                $session = AttendanceSession::create([
                    'class_id' => $class->id,
                    'term_id' => $this->terms[0]->id,
                    'session_date' => $date,
                    'taken_by' => 1,
                    'submitted_at' => now(),
                ]);

                foreach ($students as $student) {
                    $roll = mt_rand(1, 100);
                    $status = $roll <= 88 ? 'present' : ($roll <= 94 ? 'absent' : ($roll <= 98 ? 'late' : 'excused'));

                    AttendanceRecord::create([
                        'session_id' => $session->id,
                        'student_id' => $student->id,
                        'status' => $status,
                        'minutes_late' => $status === 'late' ? mt_rand(5, 25) : null,
                        'marked_by' => 1,
                    ]);
                }
            }
        }
    }

    private function seedFinance(): void
    {
        $plan = InstallmentPlan::create(['academic_year_id' => $this->year->id, 'name' => '3 versements']);

        foreach ([
            [1, '1er versement', 40, '2026-09-15'],
            [2, '2e versement', 30, '2027-01-15'],
            [3, '3e versement', 30, '2027-04-15'],
        ] as [$sequence, $label, $percentage, $due]) {
            InstallmentPlanItem::create([
                'plan_id' => $plan->id,
                'sequence' => $sequence,
                'label' => $label,
                'percentage' => $percentage,
                'due_date' => $due,
            ]);
        }

        $tuition = FeeCategory::where('code', 'tuition')->first();
        $registration = FeeCategory::where('code', 'registration')->first();

        $amounts = ['3e' => 450000, 'Tle' => 600000];

        foreach ($this->classes as $class) {
            FeeStructure::create([
                'academic_year_id' => $this->year->id,
                'fee_category_id' => $tuition->id,
                'level_id' => $class->level_id,
                'amount' => $amounts[$class->level->code] ?? 450000,
                'installment_plan_id' => $plan->id,
                'is_mandatory' => true,
                'is_active' => true,
            ]);
        }

        FeeStructure::create([
            'academic_year_id' => $this->year->id,
            'fee_category_id' => $registration->id,
            'amount' => 50000,
            'due_date' => '2026-09-10',
            'is_mandatory' => true,
            'is_active' => true,
        ]);

        app(FeeService::class)->generate($this->year->id, [], 1, true);

        // Scholarship for two students, approved and applied.
        $scholarship = Discount::create([
            'code' => 'BOURSE-50',
            'name' => 'Bourse 50 %',
            'kind' => 'scholarship',
            'value_percent' => 50,
            'fee_category_id' => $tuition->id,
            'requires_approval' => true,
            'is_active' => true,
        ]);

        $students = Student::limit(2)->get();
        foreach ($students as $student) {
            $grant = StudentDiscount::create([
                'student_id' => $student->id,
                'discount_id' => $scholarship->id,
                'academic_year_id' => $this->year->id,
                'status' => 'approved',
                'approved_by' => 1,
                'approved_at' => now(),
                'note' => 'Dossier de bourse validé',
            ]);
            app(FeeService::class)->applyApprovedDiscounts($student->id, $this->year->id);
        }

        // Verified cash payments with automatic allocation and receipts.
        $payments = app(PaymentService::class);
        $cash = \App\Models\PaymentMethod::where('code', 'cash')->first();
        $mobile = \App\Models\PaymentMethod::where('code', 'mvola')->first();
        $admin = User::find(1);

        foreach (Student::with('currentEnrollment')->limit(20)->get() as $index => $student) {
            $charges = \App\Models\Charge::where('student_id', $student->id)
                ->where('academic_year_id', $this->year->id)
                ->where('status', 'open')->orderBy('due_date')->get();

            if ($charges->isEmpty()) {
                continue;
            }

            $method = $index % 5 === 4 ? $mobile : $cash;
            $amount = $index % 3 === 0
                ? (int) $charges->first()->amount
                : (int) round($charges->first()->amount * 0.6);

            $payment = $payments->record([
                'academic_year_id' => $this->year->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'paid_at' => '2026-11-'.str_pad((string) mt_rand(2, 17), 2, '0', STR_PAD_LEFT).' 10:00:00',
                'reference' => $method->requires_reference ? 'MM'.mt_rand(100000, 999999) : null,
                'payer_name' => 'Parent de '.$student->first_name,
                'charge_ids' => $charges->pluck('id')->all(),
            ], $admin);

            if ($payment->status === 'pending') {
                $payments->verify($payment, $admin, null, $charges->pluck('id')->all());
            }
        }
    }

    private function seedUsers(): void
    {
        $admin = User::create([
            'campus_id' => $this->campus->id,
            'name' => 'Administrateur Système',
            'email' => 'admin@school.mg',
            'phone' => '0340000001',
            'password' => 'Password123!',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $admin->assignRole('super_admin');

        $director = User::create([
            'campus_id' => $this->campus->id,
            'name' => 'Jean Rakoto',
            'email' => 'director@school.mg',
            'phone' => '0340000002',
            'password' => 'Password123!',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $director->assignRole('director');
        Staff::where('employee_number', 'EMP-001')->update(['user_id' => $director->id]);

        $teacher = User::create([
            'campus_id' => $this->campus->id,
            'name' => 'Marie Rasoanaivo',
            'email' => 'teacher@school.mg',
            'phone' => '0340000003',
            'password' => 'Password123!',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $teacher->assignRole('teacher');
        Staff::where('employee_number', 'EMP-002')->update(['user_id' => $teacher->id]);

        $finance = User::create([
            'campus_id' => $this->campus->id,
            'name' => 'Nirina Andria',
            'email' => 'finance@school.mg',
            'phone' => '0340000004',
            'password' => 'Password123!',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $finance->assignRole('finance');
        Staff::where('employee_number', 'EMP-006')->update(['user_id' => $finance->id]);

        $guardian = User::create([
            'campus_id' => $this->campus->id,
            'name' => 'Paul Rakoto',
            'email' => 'parent@school.mg',
            'phone' => '0340000005',
            'password' => 'Password123!',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $guardian->assignRole('guardian');

        // Every staff member gets a login account, using the staff record's own
        // contact details and a role matching their type.
        $roleForType = [
            'teacher' => 'teacher',
            'finance' => 'finance',
            'administrative' => 'admin_staff',
            'library' => 'admin_staff',
            'support' => 'admin_staff',
            'other' => 'admin_staff',
        ];

        foreach (Staff::whereNull('user_id')->get() as $member) {
            $account = User::create([
                'campus_id' => $this->campus->id,
                'name' => $member->full_name,
                'email' => $member->email,
                'phone' => $member->phone,
                'password' => 'Password123!',
                'locale' => 'fr',
                'status' => 'active',
            ]);
            $account->assignRole($roleForType[$member->staff_type] ?? 'teacher');
            $member->update(['user_id' => $account->id]);
        }
    }

    private function linkGuardianUser(): void
    {
        $guardianUser = User::where('email', 'parent@school.mg')->first();
        $firstGuardian = Guardian::first();
        $firstGuardian?->update(['user_id' => $guardianUser?->id]);
    }

    private function seedReportCards(): void
    {
        $service = app(ReportCardService::class);
        $term = $this->terms[0];

        foreach ($this->classes[0]->students()->limit(6)->get() as $student) {
            $service->build($student, $term, 1);
        }
    }
}
