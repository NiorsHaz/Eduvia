<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\FeeCategory;
use App\Models\GradingScale;
use App\Models\GradingScaleBand;
use App\Models\NumberSeries;
use App\Models\PaymentMethod;
use App\Models\RuleMetric;
use App\Models\SettingDefinition;
use App\Models\StudentCategory;
use Illuminate\Database\Seeder;

class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->settings();
        $this->gradingScale();
        $this->metrics();
        $this->paymentMethods();
        $this->financeReference();
    }

    private function settings(): void
    {
        $definitions = [
            // key, module, label, type, allowed, default, scopes
            ['students.number_format', 'students', "Format du numéro d'élève", 'string', null, '{YEAR}-{SEQ:5}', ['school', 'campus']],
            ['attendance.mode', 'attendance', "Mode de présence", 'enum', ['daily', 'per_lesson'], 'daily', ['school', 'campus', 'level']],
            ['attendance.late_minutes_as_absent', 'attendance', 'Minutes de retard comptées comme absence', 'integer', null, 0, ['school', 'level']],
            ['attendance.lates_equal_one_absence', 'attendance', 'Retards équivalents à une absence', 'integer', null, 0, ['school', 'level']],
            ['attendance.notify_parent_after', 'attendance', 'Notifier les parents après', 'enum', ['first_absence', 'two_consecutive', 'never'], 'first_absence', ['school', 'level']],
            ['attendance.justification_deadline_days', 'attendance', 'Délai de justification (jours)', 'integer', null, 7, ['school']],
            ['grading.edit_window_days_after_term', 'grading', 'Fenêtre de modification après le trimestre (jours)', 'integer', null, 14, ['school', 'academic_year']],
            ['grading.parent_visibility', 'grading', 'Visibilité des notes aux parents', 'enum', ['on_entry', 'after_validation', 'on_report_card_only'], 'after_validation', ['school', 'level']],
            ['reportcard.show_rank', 'grading', 'Afficher le rang sur le bulletin', 'boolean', null, false, ['school', 'level']],
            ['reportcard.show_class_stats', 'grading', 'Afficher les statistiques de classe', 'boolean', null, true, ['school', 'level']],
            ['finance.currency_code', 'finance', 'Devise', 'string', null, 'MGA', ['school']],
            ['finance.allow_partial_payments', 'finance', 'Paiements partiels autorisés', 'boolean', null, true, ['school', 'level']],
            ['finance.allocation_strategy', 'finance', "Stratégie d'affectation des paiements", 'enum', ['oldest_due_first', 'by_category_priority', 'manual'], 'oldest_due_first', ['school']],
            ['finance.penalties_auto_apply', 'finance', 'Pénalités automatiques', 'boolean', null, false, ['school', 'level']],
            ['finance.overdue_grace_days', 'finance', 'Jours de grâce après échéance', 'integer', null, 0, ['school', 'level']],
            ['finance.discount_stacking', 'finance', 'Cumul des réductions', 'enum', ['stack', 'best_only'], 'stack', ['school']],
            ['finance.reversal_requires_approval', 'finance', 'Annulation soumise à approbation', 'boolean', null, true, ['school']],
            ['finance.refund_director_threshold', 'finance', 'Seuil de remboursement (directeur)', 'integer', null, 0, ['school']],
            ['finance.parent_can_download_receipts', 'finance', 'Les parents peuvent télécharger les reçus', 'boolean', null, true, ['school']],
            ['finance.block_report_card_if_overdue', 'finance', 'Bloquer le bulletin en cas d’impayé', 'boolean', null, false, ['school', 'level']],
        ];

        foreach ($definitions as [$key, $module, $label, $type, $allowed, $default, $scopes]) {
            SettingDefinition::updateOrCreate(['key' => $key], [
                'module' => $module,
                'label_key' => $label,
                'description_key' => null,
                'value_type' => $type,
                'allowed_values' => $allowed,
                'default_value' => $default,
                'allowed_scopes' => $scopes,
                'requires_permission' => 'settings.manage',
            ]);
        }
    }

    private function gradingScale(): void
    {
        $scale = GradingScale::firstOrCreate(['name' => 'Sur 20'], ['max_score' => 20]);

        $bands = [
            [0, 9.99, 'Insuffisant', false],
            [10, 13.99, 'Passable', true],
            [14, 15.99, 'Bien', true],
            [16, 20, 'Très bien', true],
        ];

        foreach ($bands as [$min, $max, $label, $passing]) {
            GradingScaleBand::updateOrCreate(
                ['scale_id' => $scale->id, 'label' => $label],
                ['min_score' => $min, 'max_score' => $max, 'is_passing' => $passing]
            );
        }
    }

    private function metrics(): void
    {
        $metrics = [
            ['annual_average', 'Moyenne annuelle', 'number'],
            ['term_average', 'Moyenne du trimestre', 'number'],
            ['subjects_below_pass_mark', 'Matières sous la moyenne', 'number'],
            ['subjects_below_eliminatory', 'Matières sous la note éliminatoire', 'number'],
            ['core_subjects_average', 'Moyenne des matières principales', 'number'],
            ['unexcused_absence_days', 'Jours d’absence non justifiés', 'number'],
            ['previous_year_decision', 'Décision de l’année précédente', 'string'],
            ['is_final_level', 'Niveau terminal', 'boolean'],
        ];

        foreach ($metrics as [$code, $label, $type]) {
            RuleMetric::updateOrCreate(['code' => $code], [
                'label_key' => $label,
                'value_type' => $type,
                'params_schema' => [],
            ]);
        }
    }

    private function paymentMethods(): void
    {
        $methods = [
            ['cash', 'Espèces', false, false, false, 10],
            ['bank_transfer', 'Virement bancaire', true, true, true, 20],
            ['cheque', 'Chèque', true, false, true, 30],
            ['mvola', 'MVola', true, false, true, 40],
            ['orange_money', 'Orange Money', true, false, true, 50],
            ['airtel_money', 'Airtel Money', true, false, true, 60],
        ];

        foreach ($methods as [$code, $name, $ref, $proof, $verify, $order]) {
            PaymentMethod::updateOrCreate(['code' => $code], [
                'name' => $name,
                'requires_reference' => $ref,
                'requires_proof' => $proof,
                'requires_verification' => $verify,
                'is_active' => true,
                'sort_order' => $order,
            ]);
        }

        NumberSeries::updateOrCreate(['code' => 'receipt'], ['pattern' => 'REC-{YEAR}-{SEQ:6}', 'is_active' => true]);
        NumberSeries::updateOrCreate(['code' => 'invoice'], ['pattern' => 'INV-{YEAR}-{SEQ:6}', 'is_active' => true]);
    }

    private function financeReference(): void
    {
        foreach ([
            ['standard', 'Standard'],
            ['staff_child', 'Enfant du personnel'],
            ['scholarship', 'Boursier'],
        ] as [$code, $name]) {
            StudentCategory::updateOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        foreach ([
            ['registration', 'Frais d’inscription', 'registration', 10],
            ['tuition', 'Écolage', 'tuition', 20],
            ['exam', 'Frais d’examen', 'exam', 30],
            ['transport', 'Transport', 'transport', 40],
            ['activity', 'Activités', 'activity', 50],
        ] as [$code, $name, $kind, $priority]) {
            FeeCategory::updateOrCreate(['code' => $code], [
                'name' => $name,
                'kind' => $kind,
                'allocation_priority' => $priority,
                'is_active' => true,
            ]);
        }

        foreach ([
            ['salaries', 'Salaires'],
            ['supplies', 'Fournitures'],
            ['maintenance', 'Entretien'],
            ['utilities', 'Eau et électricité'],
            ['other', 'Divers'],
        ] as [$code, $name]) {
            ExpenseCategory::updateOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }
    }
}
