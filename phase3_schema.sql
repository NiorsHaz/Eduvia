-- =====================================================================
-- High School Management System: Phase 3 schema (PostgreSQL 15+)
-- Scope: fee configuration, billing, discounts and scholarships,
--        penalties, payments, receipts, refunds, reminders, expenses,
--        cash closing, finance reporting views.
-- Depends on: phase1_schema.sql, phase2_schema.sql,
--             phase2b_configurable_rules.sql (setting_definitions).
--
-- Principles
--   * Money is bigint in the smallest currency unit (Ariary: whole units).
--     Never float or numeric for amounts. Percentages are numeric(5,2).
--   * The finance ledger is APPEND-ONLY. Payments are never deleted or
--     rewritten; corrections are new rows (negative allocations,
--     reversals, adjustments) so history is always reconstructable.
--   * Every school rule (fee amounts, installments, discounts,
--     penalties, numbering, reminders, allocation order) is data or a
--     setting, not code.
--   * Balances are DERIVED by views from charges, approved adjustments
--     and allocations. No stored balance can drift out of sync.
--   * Payments are safe to retry: client_uuid and unique references
--     prevent duplicates from flaky mobile/mobile-money connections.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. FINANCE SETTINGS (uses the registry from phase 2b)
-- ---------------------------------------------------------------------
INSERT INTO setting_definitions (key, module, label_key, value_type, allowed_values, default_value, allowed_scopes) VALUES
 ('finance.currency_code',               'finance', 'settings.finance.currency',           'string',  NULL,
    '"MGA"'::jsonb, ARRAY['school']),
 ('finance.allow_partial_payments',      'finance', 'settings.finance.partial_payments',   'boolean', NULL,
    'true'::jsonb, ARRAY['school','level']),
 ('finance.allocation_strategy',         'finance', 'settings.finance.allocation',         'enum',
    '["oldest_due_first","by_category_priority","manual"]', '"oldest_due_first"'::jsonb, ARRAY['school']),
 ('finance.penalties_auto_apply',        'finance', 'settings.finance.penalties_auto',     'boolean', NULL,
    'false'::jsonb, ARRAY['school','level']),
 ('finance.overdue_grace_days',          'finance', 'settings.finance.grace_days',         'integer', NULL,
    '0'::jsonb, ARRAY['school','level']),
 ('finance.discount_stacking',           'finance', 'settings.finance.discount_stacking',  'enum',
    '["stack","best_only"]', '"stack"'::jsonb, ARRAY['school']),
 ('finance.reversal_requires_approval',  'finance', 'settings.finance.reversal_approval',  'boolean', NULL,
    'true'::jsonb, ARRAY['school']),
 ('finance.refund_director_threshold',   'finance', 'settings.finance.refund_threshold',   'integer', NULL,
    '0'::jsonb, ARRAY['school']),               -- refunds above this need the director; 0 = always
 ('finance.parent_can_download_receipts','finance', 'settings.finance.parent_receipts',    'boolean', NULL,
    'true'::jsonb, ARRAY['school']),
 ('finance.block_report_card_if_overdue','finance', 'settings.finance.block_report_card',  'boolean', NULL,
    'false'::jsonb, ARRAY['school','level']);   -- off by default: a school policy decision

-- ---------------------------------------------------------------------
-- 1. CONFIGURABLE NUMBER SERIES (receipts, invoices)
-- Pattern tokens: {YEAR} = period key (academic year name), {SEQ:n} = zero padded counter
-- ---------------------------------------------------------------------
CREATE TABLE number_series (
    code        text PRIMARY KEY,                       -- 'receipt', 'invoice'
    pattern     text NOT NULL,                          -- 'REC-{YEAR}-{SEQ:6}'
    is_active   boolean NOT NULL DEFAULT true,
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE number_series_counters (
    series_code text NOT NULL REFERENCES number_series(code),
    period_key  text NOT NULL,
    last_value  bigint NOT NULL DEFAULT 0,
    PRIMARY KEY (series_code, period_key)
);

INSERT INTO number_series (code, pattern) VALUES
    ('receipt', 'REC-{YEAR}-{SEQ:6}'),
    ('invoice', 'INV-{YEAR}-{SEQ:6}');

-- Call inside the same transaction that inserts the document, so a
-- rollback also rolls back the counter (no gaps in receipt numbers).
CREATE OR REPLACE FUNCTION fn_next_number(p_series text, p_period text) RETURNS text AS $$
DECLARE
    pat    text;
    n      bigint;
    result text;
    m      text[];
BEGIN
    SELECT pattern INTO pat FROM number_series WHERE code = p_series AND is_active;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Number series % not found or inactive', p_series;
    END IF;

    INSERT INTO number_series_counters (series_code, period_key, last_value)
    VALUES (p_series, p_period, 1)
    ON CONFLICT (series_code, period_key)
    DO UPDATE SET last_value = number_series_counters.last_value + 1
    RETURNING last_value INTO n;

    result := replace(pat, '{YEAR}', p_period);
    m := regexp_match(result, '\{SEQ:(\d+)\}');
    IF m IS NOT NULL THEN
        result := regexp_replace(result, '\{SEQ:\d+\}', lpad(n::text, m[1]::int, '0'));
    ELSE
        result := result || n::text;
    END IF;
    RETURN result;
END;
$$ LANGUAGE plpgsql;

-- ---------------------------------------------------------------------
-- 2. FEE CONFIGURATION
-- ---------------------------------------------------------------------
-- Student categories drive different fee schedules (configurable by school)
CREATE TABLE student_categories (
    id          bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code        text NOT NULL UNIQUE,                 -- standard, staff_child, boarder, ...
    name        text NOT NULL,
    is_active   boolean NOT NULL DEFAULT true
);

ALTER TABLE students ADD COLUMN student_category_id bigint REFERENCES student_categories(id);

CREATE TABLE fee_categories (
    id                  bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code                text NOT NULL UNIQUE,
    name                text NOT NULL,
    kind                text NOT NULL DEFAULT 'custom'
                        CHECK (kind IN ('registration','tuition','exam','transport','supplies','activity','custom')),
    allocation_priority smallint NOT NULL DEFAULT 100,  -- used by 'by_category_priority'
    is_refundable       boolean NOT NULL DEFAULT false,
    is_active           boolean NOT NULL DEFAULT true
);

-- Installment plans: a split of one fee into dated parts
CREATE TABLE installment_plans (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    academic_year_id  bigint NOT NULL REFERENCES academic_years(id),
    name              text NOT NULL,                  -- '3 trimestres', 'Mensuel'
    is_active         boolean NOT NULL DEFAULT true,
    UNIQUE (academic_year_id, name)
);

CREATE TABLE installment_plan_items (
    id          bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    plan_id     bigint NOT NULL REFERENCES installment_plans(id) ON DELETE CASCADE,
    sequence    smallint NOT NULL,
    label       text,
    percentage  numeric(5,2) NOT NULL CHECK (percentage > 0 AND percentage <= 100),
    due_date    date NOT NULL,
    UNIQUE (plan_id, sequence)
);

-- A plan's percentages must total exactly 100 (checked at commit time).
-- The app gives the rounding remainder to the last installment.
CREATE OR REPLACE FUNCTION fn_plan_total_check() RETURNS trigger AS $$
DECLARE
    pid   bigint;
    total numeric;
    cnt   integer;
BEGIN
    pid := COALESCE(NEW.plan_id, OLD.plan_id);
    SELECT COALESCE(sum(percentage),0), count(*) INTO total, cnt
    FROM installment_plan_items WHERE plan_id = pid;
    IF cnt > 0 AND total <> 100 THEN
        RAISE EXCEPTION 'Installment plan % percentages total %, expected 100', pid, total
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER trg_plan_total_check
    AFTER INSERT OR UPDATE OR DELETE ON installment_plan_items
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION fn_plan_total_check();

-- The price list: amount per year, optionally per level / track / student category.
-- NULL in level/track/category means "applies to all".
CREATE TABLE fee_structures (
    id                   bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    academic_year_id     bigint NOT NULL REFERENCES academic_years(id),
    fee_category_id      bigint NOT NULL REFERENCES fee_categories(id),
    level_id             bigint REFERENCES levels(id),
    track_id             bigint REFERENCES tracks(id),
    student_category_id  bigint REFERENCES student_categories(id),
    name                 text,
    amount               bigint NOT NULL CHECK (amount >= 0),
    installment_plan_id  bigint REFERENCES installment_plans(id),   -- NULL = single due date
    due_date             date,                                       -- used when no plan
    is_mandatory         boolean NOT NULL DEFAULT true,              -- optional fees (transport, activities)
    is_active            boolean NOT NULL DEFAULT true,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (installment_plan_id IS NOT NULL OR due_date IS NOT NULL),
    UNIQUE NULLS NOT DISTINCT (academic_year_id, fee_category_id, level_id, track_id, student_category_id)
);

-- Discounts and scholarships (definition)
CREATE TABLE discounts (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code              text NOT NULL UNIQUE,
    name              text NOT NULL,
    kind              text NOT NULL DEFAULT 'discount' CHECK (kind IN ('discount','scholarship')),
    value_percent     numeric(5,2) CHECK (value_percent > 0 AND value_percent <= 100),
    value_fixed       bigint CHECK (value_fixed > 0),
    fee_category_id   bigint REFERENCES fee_categories(id),          -- NULL = all categories
    eligibility       jsonb NOT NULL DEFAULT '{}'::jsonb,            -- documented criteria, e.g. {"sibling_order":">=2"}
    requires_approval boolean NOT NULL DEFAULT true,
    is_active         boolean NOT NULL DEFAULT true,
    created_at        timestamptz NOT NULL DEFAULT now(),
    CHECK (num_nonnulls(value_percent, value_fixed) = 1)             -- exactly one of the two
);

-- A discount granted to a student for a year (workflow: pending -> approved)
CREATE TABLE student_discounts (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    student_id        bigint NOT NULL REFERENCES students(id),
    discount_id       bigint NOT NULL REFERENCES discounts(id),
    academic_year_id  bigint NOT NULL REFERENCES academic_years(id),
    status            text NOT NULL DEFAULT 'pending'
                      CHECK (status IN ('pending','approved','rejected','expired','revoked')),
    supporting_document_id bigint REFERENCES documents(id),
    note              text,
    requested_by      bigint REFERENCES users(id),
    approved_by       bigint REFERENCES users(id),
    approved_at       timestamptz,
    created_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (student_id, discount_id, academic_year_id)
);

-- Penalty (late fee) definitions: school chooses whether they exist and how they work
CREATE TABLE penalty_rules (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code              text NOT NULL UNIQUE,
    name              text NOT NULL,
    calc_type         text NOT NULL
                      CHECK (calc_type IN ('fixed','fixed_per_period','percent_of_outstanding','percent_per_period')),
    value_fixed       bigint CHECK (value_fixed > 0),
    value_percent     numeric(5,2) CHECK (value_percent > 0),
    period_days       smallint CHECK (period_days > 0),              -- for *_per_period types
    grace_days        smallint NOT NULL DEFAULT 0,
    max_total         bigint CHECK (max_total > 0),                  -- cap per charge
    fee_category_id   bigint REFERENCES fee_categories(id),          -- NULL = all
    application       text NOT NULL DEFAULT 'manual' CHECK (application IN ('automatic','manual')),
    is_active         boolean NOT NULL DEFAULT false,                -- off until the school enables it
    CHECK (
        (calc_type IN ('fixed','fixed_per_period') AND value_fixed IS NOT NULL) OR
        (calc_type IN ('percent_of_outstanding','percent_per_period') AND value_percent IS NOT NULL)
    ),
    CHECK (calc_type NOT IN ('fixed_per_period','percent_per_period') OR period_days IS NOT NULL)
);

-- ---------------------------------------------------------------------
-- 3. PAYMENT METHODS (configurable, including mobile money providers)
-- ---------------------------------------------------------------------
CREATE TABLE payment_methods (
    id                     bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code                   text NOT NULL UNIQUE,              -- cash, bank_transfer, cheque, mvola, orange_money, airtel_money
    name                   text NOT NULL,
    requires_reference     boolean NOT NULL DEFAULT false,
    requires_proof         boolean NOT NULL DEFAULT false,
    requires_verification  boolean NOT NULL DEFAULT false,    -- false: counter cash is verified on entry
    provider               text,                              -- gateway name if integrated later
    is_active              boolean NOT NULL DEFAULT true,
    sort_order             smallint NOT NULL DEFAULT 0
);

INSERT INTO payment_methods (code, name, requires_reference, requires_proof, requires_verification, sort_order) VALUES
    ('cash',          'Especes',        false, false, false, 10),
    ('bank_transfer', 'Virement',       true,  true,  true,  20),
    ('cheque',        'Cheque',         true,  false, true,  30),
    ('mvola',         'MVola',          true,  false, true,  40),
    ('orange_money',  'Orange Money',   true,  false, true,  50),
    ('airtel_money',  'Airtel Money',   true,  false, true,  60);

-- ---------------------------------------------------------------------
-- 4. BILLING: invoices and charges
-- ---------------------------------------------------------------------
-- Repeatable, auditable generation of charges from fee_structures
CREATE TABLE fee_generation_runs (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    academic_year_id  bigint NOT NULL REFERENCES academic_years(id),
    scope             jsonb NOT NULL DEFAULT '{}'::jsonb,        -- {"level_ids":[...],"class_ids":[...]}
    status            text NOT NULL DEFAULT 'preview'
                      CHECK (status IN ('preview','committed','rolled_back')),
    charges_created   integer NOT NULL DEFAULT 0,
    run_by            bigint NOT NULL REFERENCES users(id),
    created_at        timestamptz NOT NULL DEFAULT now(),
    committed_at      timestamptz
);

CREATE TABLE invoices (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    invoice_number    text NOT NULL UNIQUE,                      -- from fn_next_number('invoice', ...)
    student_id        bigint NOT NULL REFERENCES students(id),
    academic_year_id  bigint NOT NULL REFERENCES academic_years(id),
    issue_date        date NOT NULL DEFAULT current_date,
    due_date          date,
    status            text NOT NULL DEFAULT 'issued' CHECK (status IN ('draft','issued','cancelled')),
    document_id       bigint REFERENCES documents(id),           -- generated PDF
    notes             text,
    issued_by         bigint REFERENCES users(id),
    cancelled_by      bigint REFERENCES users(id),
    cancelled_reason  text,
    created_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_invoices_student ON invoices (student_id, academic_year_id);

-- A charge = one amount a student owes, with one due date
CREATE TABLE charges (
    id                 bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    student_id         bigint NOT NULL REFERENCES students(id),
    academic_year_id   bigint NOT NULL REFERENCES academic_years(id),
    fee_category_id    bigint NOT NULL REFERENCES fee_categories(id),
    fee_structure_id   bigint REFERENCES fee_structures(id),
    invoice_id         bigint REFERENCES invoices(id),
    generation_run_id  bigint REFERENCES fee_generation_runs(id),
    description        text NOT NULL,
    amount             bigint NOT NULL CHECK (amount >= 0),      -- gross, before adjustments
    due_date           date NOT NULL,
    installment_sequence smallint,
    source             text NOT NULL DEFAULT 'fee_structure'
                       CHECK (source IN ('fee_structure','manual','transport','opening_balance','import')),
    status             text NOT NULL DEFAULT 'open' CHECK (status IN ('open','cancelled')),
    cancelled_by       bigint REFERENCES users(id),
    cancelled_reason   text,
    created_by         bigint REFERENCES users(id),
    created_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'cancelled' OR cancelled_reason IS NOT NULL)
);
CREATE INDEX idx_charges_student ON charges (student_id, academic_year_id);
CREATE INDEX idx_charges_due     ON charges (due_date) WHERE status = 'open';
-- Re-running generation can never duplicate a student's fee installment
CREATE UNIQUE INDEX uq_charge_generation
    ON charges (student_id, academic_year_id, fee_structure_id, installment_sequence)
    NULLS NOT DISTINCT
    WHERE fee_structure_id IS NOT NULL AND status = 'open';

-- Discounts, scholarships, waivers, penalties, corrections.
-- Signed amount: negative reduces what is owed, positive increases it.
CREATE TABLE charge_adjustments (
    id                   bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    charge_id            bigint NOT NULL REFERENCES charges(id),
    kind                 text NOT NULL CHECK (kind IN ('discount','scholarship','waiver','penalty','correction')),
    amount               bigint NOT NULL CHECK (amount <> 0),
    student_discount_id  bigint REFERENCES student_discounts(id),
    penalty_rule_id      bigint REFERENCES penalty_rules(id),
    period_key           text,                                   -- e.g. '2026-11' so a penalty is not applied twice
    reason               text,
    status               text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    requested_by         bigint REFERENCES users(id),            -- NULL when applied by the system
    approved_by          bigint REFERENCES users(id),
    approved_at          timestamptz,
    created_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (kind NOT IN ('discount','scholarship','waiver') OR amount < 0),
    CHECK (kind <> 'penalty' OR amount > 0),
    CHECK (kind NOT IN ('waiver','correction') OR reason IS NOT NULL)
);
CREATE INDEX idx_adj_charge ON charge_adjustments (charge_id) WHERE status = 'approved';
CREATE UNIQUE INDEX uq_penalty_once ON charge_adjustments (charge_id, penalty_rule_id, period_key)
    WHERE kind = 'penalty' AND status <> 'rejected';
CREATE UNIQUE INDEX uq_discount_once ON charge_adjustments (charge_id, student_discount_id)
    WHERE student_discount_id IS NOT NULL AND status <> 'rejected';

-- ---------------------------------------------------------------------
-- 5. CASH CLOSING (daily reconciliation of counter cash)
-- ---------------------------------------------------------------------
CREATE TABLE cash_closings (
    id               bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    closing_date     date NOT NULL,
    cashier_user_id  bigint NOT NULL REFERENCES users(id),
    expected_amount  bigint NOT NULL DEFAULT 0,                 -- sum of verified cash payments
    counted_amount   bigint,
    difference       bigint GENERATED ALWAYS AS (counted_amount - expected_amount) STORED,
    status           text NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed','approved')),
    note             text,
    approved_by      bigint REFERENCES users(id),
    closed_at        timestamptz,
    UNIQUE (closing_date, cashier_user_id)
);

-- ---------------------------------------------------------------------
-- 6. PAYMENTS (append-only ledger)
-- ---------------------------------------------------------------------
CREATE TABLE payments (
    id                  bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    academic_year_id    bigint NOT NULL REFERENCES academic_years(id),
    payer_guardian_id   bigint REFERENCES guardians(id),
    payer_name          text,                                   -- when payer is not a registered guardian
    payment_method_id   bigint NOT NULL REFERENCES payment_methods(id),
    amount              bigint NOT NULL CHECK (amount > 0),
    paid_at             timestamptz NOT NULL,
    reference           text,                                   -- bank ref, cheque no, mobile money tx id
    proof_document_id   bigint REFERENCES documents(id),
    status              text NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','verified','rejected','reversed')),
    received_by         bigint NOT NULL REFERENCES users(id),
    verified_by         bigint REFERENCES users(id),
    verified_at         timestamptz,
    rejected_reason     text,
    cash_closing_id     bigint REFERENCES cash_closings(id),
    notes               text,
    client_uuid         uuid UNIQUE,                            -- idempotent retry
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'rejected' OR rejected_reason IS NOT NULL),
    CHECK (payer_guardian_id IS NOT NULL OR payer_name IS NOT NULL)
);
CREATE INDEX idx_payments_status  ON payments (status, paid_at DESC);
CREATE INDEX idx_payments_payer   ON payments (payer_guardian_id);
-- The same bank/mobile-money reference cannot be recorded twice per method
CREATE UNIQUE INDEX uq_payment_reference ON payments (payment_method_id, reference)
    WHERE reference IS NOT NULL AND status <> 'rejected';

-- Protect the ledger: no deletes, no rewriting money fields after entry,
-- and only legal status transitions.
CREATE OR REPLACE FUNCTION fn_payments_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Payments cannot be deleted. Use reversal/refund workflow.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF OLD.status <> 'pending' AND (
           NEW.amount            IS DISTINCT FROM OLD.amount
        OR NEW.paid_at           IS DISTINCT FROM OLD.paid_at
        OR NEW.payment_method_id IS DISTINCT FROM OLD.payment_method_id
        OR NEW.academic_year_id  IS DISTINCT FROM OLD.academic_year_id
        OR NEW.payer_guardian_id IS DISTINCT FROM OLD.payer_guardian_id
    ) THEN
        RAISE EXCEPTION 'Payment % is no longer pending: amount, date, method and payer are immutable.', OLD.id
            USING ERRCODE = 'check_violation';
    END IF;

    IF (OLD.status = 'rejected' AND NEW.status <> 'rejected')
    OR (OLD.status = 'reversed' AND NEW.status <> 'reversed')
    OR (OLD.status = 'verified' AND NEW.status NOT IN ('verified','reversed')) THEN
        RAISE EXCEPTION 'Illegal payment status change % -> %', OLD.status, NEW.status
            USING ERRCODE = 'check_violation';
    END IF;

    NEW.updated_at := now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_payments_guard
    BEFORE UPDATE OR DELETE ON payments
    FOR EACH ROW EXECUTE FUNCTION fn_payments_guard();

-- Allocation = which part of a verified payment settles which charge.
-- Created when a payment is verified (automatically per the configured
-- allocation_strategy, or manually). One payment may settle several
-- charges, including charges of several siblings.
-- Reversals insert NEGATIVE rows pointing to the original (never edit).
CREATE TABLE payment_allocations (
    id               bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    payment_id       bigint NOT NULL REFERENCES payments(id),
    charge_id        bigint NOT NULL REFERENCES charges(id),
    amount           bigint NOT NULL CHECK (amount <> 0),
    reversal_of_id   bigint REFERENCES payment_allocations(id),
    created_by       bigint REFERENCES users(id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    CHECK ((amount < 0) = (reversal_of_id IS NOT NULL))
);
CREATE INDEX idx_alloc_payment ON payment_allocations (payment_id);
CREATE INDEX idx_alloc_charge  ON payment_allocations (charge_id);
-- an allocation can be reversed only once
CREATE UNIQUE INDEX uq_alloc_reversed_once ON payment_allocations (reversal_of_id) WHERE reversal_of_id IS NOT NULL;

CREATE OR REPLACE FUNCTION fn_allocation_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'payment_allocations is append-only. Insert a reversing row instead.'
        USING ERRCODE = 'check_violation';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_alloc_append_only
    BEFORE UPDATE OR DELETE ON payment_allocations
    FOR EACH ROW EXECUTE FUNCTION fn_allocation_append_only();

-- Over-allocation guards (row locks serialize concurrent cashiers)
CREATE OR REPLACE FUNCTION fn_allocation_guard() RETURNS trigger AS $$
DECLARE
    p            payments%ROWTYPE;
    c            charges%ROWTYPE;
    pay_alloc    bigint;
    net_due      bigint;
    charge_paid  bigint;
BEGIN
    SELECT * INTO p FROM payments WHERE id = NEW.payment_id FOR UPDATE;
    IF NEW.amount > 0 AND p.status <> 'verified' THEN
        RAISE EXCEPTION 'Payment % must be verified before allocation (status: %)', p.id, p.status
            USING ERRCODE = 'check_violation';
    END IF;

    SELECT COALESCE(sum(amount),0) INTO pay_alloc FROM payment_allocations WHERE payment_id = NEW.payment_id;
    IF pay_alloc > p.amount OR pay_alloc < 0 THEN
        RAISE EXCEPTION 'Allocations (%) exceed payment amount (%) for payment %', pay_alloc, p.amount, p.id
            USING ERRCODE = 'check_violation';
    END IF;

    SELECT * INTO c FROM charges WHERE id = NEW.charge_id FOR UPDATE;
    IF NEW.amount > 0 AND c.status <> 'open' THEN
        RAISE EXCEPTION 'Charge % is %, cannot receive payments', c.id, c.status
            USING ERRCODE = 'check_violation';
    END IF;

    SELECT c.amount + COALESCE(sum(a.amount),0) INTO net_due
    FROM charge_adjustments a WHERE a.charge_id = c.id AND a.status = 'approved';
    net_due := COALESCE(net_due, c.amount);
    SELECT COALESCE(sum(amount),0) INTO charge_paid FROM payment_allocations WHERE charge_id = c.id;
    IF charge_paid > net_due OR charge_paid < 0 THEN
        RAISE EXCEPTION 'Allocation would pay % on charge % whose net due is %', charge_paid, c.id, net_due
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_allocation_guard
    AFTER INSERT ON payment_allocations
    FOR EACH ROW EXECUTE FUNCTION fn_allocation_guard();

-- Reversal / refund workflow (approval-controlled, never silent)
CREATE TABLE payment_reversals (
    id                 bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    payment_id         bigint NOT NULL REFERENCES payments(id),
    kind               text NOT NULL CHECK (kind IN ('reversal','refund')),
    amount             bigint NOT NULL CHECK (amount > 0),
    reason             text NOT NULL,
    status             text NOT NULL DEFAULT 'requested'
                       CHECK (status IN ('requested','approved','rejected','completed')),
    requested_by       bigint NOT NULL REFERENCES users(id),
    approved_by        bigint REFERENCES users(id),
    approved_at        timestamptz,
    refund_method_id   bigint REFERENCES payment_methods(id),
    refund_reference   text,
    completed_at       timestamptz,
    created_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (approved_by IS NULL OR approved_by <> requested_by)   -- four-eyes principle
);
CREATE INDEX idx_reversals_payment ON payment_reversals (payment_id);

-- ---------------------------------------------------------------------
-- 7. RECEIPTS
-- ---------------------------------------------------------------------
CREATE TABLE receipts (
    id              bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    payment_id      bigint NOT NULL UNIQUE REFERENCES payments(id),
    receipt_number  text NOT NULL UNIQUE,
    issued_at       timestamptz NOT NULL DEFAULT now(),
    issued_by       bigint REFERENCES users(id),
    document_id     bigint REFERENCES documents(id),            -- generated PDF
    status          text NOT NULL DEFAULT 'issued' CHECK (status IN ('issued','voided')),
    voided_by       bigint REFERENCES users(id),
    voided_reason   text,
    voided_at       timestamptz,
    CHECK (status <> 'voided' OR voided_reason IS NOT NULL)
);

-- Issues the next sequential receipt for a verified payment.
-- Numbering resets per academic year because the period key is its name.
CREATE OR REPLACE FUNCTION fn_issue_receipt(p_payment_id bigint, p_user_id bigint) RETURNS bigint AS $$
DECLARE
    p        payments%ROWTYPE;
    ay_name  text;
    new_id   bigint;
BEGIN
    SELECT * INTO p FROM payments WHERE id = p_payment_id FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Payment % not found', p_payment_id;
    END IF;
    IF p.status <> 'verified' THEN
        RAISE EXCEPTION 'Receipts are issued only for verified payments (payment % is %)', p.id, p.status;
    END IF;
    IF EXISTS (SELECT 1 FROM receipts WHERE payment_id = p.id) THEN
        RAISE EXCEPTION 'Payment % already has a receipt', p.id;
    END IF;

    SELECT name INTO ay_name FROM academic_years WHERE id = p.academic_year_id;
    INSERT INTO receipts (payment_id, receipt_number, issued_by)
    VALUES (p.id, fn_next_number('receipt', ay_name), p_user_id)
    RETURNING id INTO new_id;
    RETURN new_id;
END;
$$ LANGUAGE plpgsql;

-- ---------------------------------------------------------------------
-- 8. REMINDERS (configurable schedules)
-- ---------------------------------------------------------------------
CREATE TABLE reminder_schedules (
    id                   bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    name                 text NOT NULL,
    trigger_basis        text NOT NULL CHECK (trigger_basis IN ('before_due','after_due')),
    offset_days          smallint NOT NULL CHECK (offset_days >= 0),
    repeat_every_days    smallint CHECK (repeat_every_days > 0),
    max_repeats          smallint NOT NULL DEFAULT 1 CHECK (max_repeats >= 1),
    channels             text[] NOT NULL DEFAULT ARRAY['in_app','push']
                         CHECK (channels <@ ARRAY['in_app','push','email','sms']),
    template_key         text NOT NULL,
    min_outstanding      bigint NOT NULL DEFAULT 1,
    level_id             bigint REFERENCES levels(id),             -- NULL = all levels
    is_active            boolean NOT NULL DEFAULT true
);

-- Prevents duplicate sends if the scheduler runs twice
CREATE TABLE reminder_log (
    id            bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    schedule_id   bigint NOT NULL REFERENCES reminder_schedules(id),
    charge_id     bigint NOT NULL REFERENCES charges(id),
    guardian_id   bigint NOT NULL REFERENCES guardians(id),
    channel       text NOT NULL,
    occurrence    smallint NOT NULL DEFAULT 1,
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','sent','failed','skipped')),
    error         text,
    sent_at       timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (schedule_id, charge_id, guardian_id, channel, occurrence)
);

-- ---------------------------------------------------------------------
-- 9. EXPENSES (optional, only if the school enables the scope)
-- ---------------------------------------------------------------------
CREATE TABLE expense_categories (
    id          bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code        text NOT NULL UNIQUE,
    name        text NOT NULL,
    is_active   boolean NOT NULL DEFAULT true
);

CREATE TABLE expenses (
    id                 bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    academic_year_id   bigint REFERENCES academic_years(id),
    category_id        bigint NOT NULL REFERENCES expense_categories(id),
    supplier           text,
    description        text NOT NULL,
    amount             bigint NOT NULL CHECK (amount > 0),
    expense_date       date NOT NULL,
    payment_method_id  bigint REFERENCES payment_methods(id),
    reference          text,
    document_id        bigint REFERENCES documents(id),
    status             text NOT NULL DEFAULT 'requested'
                       CHECK (status IN ('requested','approved','paid','rejected')),
    requested_by       bigint NOT NULL REFERENCES users(id),
    approved_by        bigint REFERENCES users(id),
    paid_at            timestamptz,
    created_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (approved_by IS NULL OR approved_by <> requested_by)
);
CREATE INDEX idx_expenses_date ON expenses (expense_date, status);

-- ---------------------------------------------------------------------
-- 10. REPORTING VIEWS (balances are always derived, never stored)
-- ---------------------------------------------------------------------
-- Per charge: gross, approved adjustments, net due, paid, outstanding, overdue
-- (finance.overdue_grace_days is applied by the app on top of is_overdue)
CREATE OR REPLACE VIEW v_charge_balances AS
SELECT x.*,
       (x.outstanding > 0 AND x.due_date < current_date) AS is_overdue
FROM (
    SELECT c.id                 AS charge_id,
           c.student_id,
           c.academic_year_id,
           c.fee_category_id,
           c.invoice_id,
           c.due_date,
           c.amount             AS gross_amount,
           COALESCE(adj.total,0)::bigint                          AS adjustments_total,
           (c.amount + COALESCE(adj.total,0))::bigint             AS net_due,
           COALESCE(al.total,0)::bigint                           AS paid,
           (c.amount + COALESCE(adj.total,0) - COALESCE(al.total,0))::bigint AS outstanding
    FROM charges c
    LEFT JOIN LATERAL (
        SELECT sum(a.amount) AS total
        FROM charge_adjustments a
        WHERE a.charge_id = c.id AND a.status = 'approved'
    ) adj ON true
    LEFT JOIN LATERAL (
        SELECT sum(pa.amount) AS total
        FROM payment_allocations pa
        WHERE pa.charge_id = c.id
    ) al ON true
    WHERE c.status = 'open'
) x;

-- Per student and year: what the parent and the accountant see
CREATE OR REPLACE VIEW v_student_balances AS
SELECT student_id,
       academic_year_id,
       sum(net_due)::bigint     AS total_due,
       sum(paid)::bigint        AS total_paid,
       sum(outstanding)::bigint AS total_outstanding,
       COALESCE(sum(outstanding) FILTER (WHERE is_overdue),0)::bigint AS overdue_amount,
       min(due_date) FILTER (WHERE outstanding > 0)                   AS next_due_date
FROM v_charge_balances
GROUP BY student_id, academic_year_id;

-- Verified money not yet applied to any charge (credit on account)
CREATE OR REPLACE VIEW v_payment_unallocated AS
SELECT p.id AS payment_id,
       p.payer_guardian_id,
       p.amount,
       COALESCE(sum(pa.amount),0)::bigint            AS allocated,
       (p.amount - COALESCE(sum(pa.amount),0))::bigint AS unallocated
FROM payments p
LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
WHERE p.status = 'verified'
GROUP BY p.id
HAVING p.amount - COALESCE(sum(pa.amount),0) > 0;

-- Daily collections by method (verified only). Adjust the time zone if needed.
CREATE OR REPLACE VIEW v_collection_daily AS
SELECT (p.paid_at AT TIME ZONE 'Indian/Antananarivo')::date AS paid_date,
       pm.code AS payment_method,
       count(*)           AS payments_count,
       sum(p.amount)::bigint AS total_collected
FROM payments p
JOIN payment_methods pm ON pm.id = p.payment_method_id
WHERE p.status = 'verified'
GROUP BY 1, 2;

-- Expected vs collected by level and category (finance dashboard)
CREATE OR REPLACE VIEW v_revenue_by_level AS
SELECT cb.academic_year_id,
       cl.level_id,
       cb.fee_category_id,
       sum(cb.net_due)::bigint     AS expected,
       sum(cb.paid)::bigint        AS collected,
       sum(cb.outstanding)::bigint AS outstanding
FROM v_charge_balances cb
JOIN enrollments e ON e.student_id = cb.student_id
                  AND e.academic_year_id = cb.academic_year_id
                  AND e.end_date IS NULL
JOIN classes cl ON cl.id = e.class_id
GROUP BY cb.academic_year_id, cl.level_id, cb.fee_category_id;

-- ---------------------------------------------------------------------
-- NOTES FOR THE LARAVEL LAYER
--  * Wrap "verify payment + allocate + issue receipt" in ONE transaction
--    so receipt numbers stay gapless and nothing is half-applied.
--  * Parent portal: join student_guardians where verified_at IS NOT NULL,
--    revoked_at IS NULL and can_view_finance = true.
--  * Penalty application, discount stacking and allocation order read
--    penalty_rules / settings; no school-specific logic in code.
--  * Report card blocking for unpaid fees is a setting, default off.
-- ---------------------------------------------------------------------
