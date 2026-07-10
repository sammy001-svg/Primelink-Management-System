<?php
/**
 * Kenya Payroll Calculation Engine — Primelink Management System
 * Tax rates: KRA 2024 | NSSF Act 2013 (Tier I+II) | SHIF 2.75% | AHLF 1.5%
 */

// ── Monthly PAYE bands (KRA 2024) ──────────────────────────────────────────
const PAYE_BANDS = [
    ['limit' =>   24000, 'rate' => 0.10],
    ['limit' =>    8333, 'rate' => 0.25],   // 24,001 – 32,333
    ['limit' =>  467667, 'rate' => 0.30],   // 32,334 – 500,000
    ['limit' =>  300000, 'rate' => 0.325],  // 500,001 – 800,000
    ['limit' => PHP_INT_MAX, 'rate' => 0.35],
];

const PERSONAL_RELIEF      = 2400.00;  // Monthly
const MAX_INSURANCE_RELIEF = 5000.00;  // 15% of premiums, capped
const MAX_MORTGAGE_RELIEF  = 25000.00; // Monthly mortgage interest

const NSSF_RATE        = 0.06;
const NSSF_TIER1_CAP   = 6000.0;   // Lower earnings limit
const NSSF_TIER2_CAP   = 18000.0;  // Upper earnings limit
const NSSF_OLD_FLAT    = 200.00;
const SHIF_RATE        = 0.0275;   // 2.75% of gross
const HOUSING_LEVY_RATE= 0.015;    // 1.5% of gross

// ── Old NHIF tiered table ──────────────────────────────────────────────────
function oldNhifRate(float $gross): float {
    return match(true) {
        $gross < 6000   => 150,
        $gross < 8000   => 300,
        $gross < 12000  => 400,
        $gross < 15000  => 500,
        $gross < 20000  => 600,
        $gross < 25000  => 750,
        $gross < 30000  => 850,
        $gross < 35000  => 900,
        $gross < 40000  => 950,
        $gross < 45000  => 1000,
        $gross < 50000  => 1100,
        $gross < 60000  => 1200,
        $gross < 70000  => 1300,
        $gross < 80000  => 1400,
        $gross < 90000  => 1500,
        $gross < 100000 => 1600,
        default         => 1700,
    };
}

// ── NSSF ───────────────────────────────────────────────────────────────────
function calcNSSF(float $gross, string $nssfType = 'new'): array {
    if ($nssfType === 'old') {
        return ['employee' => NSSF_OLD_FLAT, 'employer' => NSSF_OLD_FLAT];
    }
    $tier1    = min($gross, NSSF_TIER1_CAP) * NSSF_RATE;
    $tier2    = min(max(0.0, $gross - NSSF_TIER1_CAP), NSSF_TIER2_CAP - NSSF_TIER1_CAP) * NSSF_RATE;
    $employee = round($tier1 + $tier2, 2);
    return ['employee' => $employee, 'employer' => $employee];
}

// ── SHIF / old NHIF ────────────────────────────────────────────────────────
function calcSHIF(float $gross, bool $useShif = true): float {
    return $useShif ? round($gross * SHIF_RATE, 2) : oldNhifRate($gross);
}

// ── Housing / Affordable Housing Levy ────────────────────────────────────
function calcHousingLevy(float $gross): array {
    $amount = round($gross * HOUSING_LEVY_RATE, 2);
    return ['employee' => $amount, 'employer' => $amount];
}

// ── PAYE ───────────────────────────────────────────────────────────────────
function calcPAYE(float $gross, float $nssf, float $housingLevy,
                  float $insurancePremiums = 0.0, float $mortgageInterest = 0.0): array
{
    // Taxable pay: gross minus pre-tax statutory deductions
    $taxable  = max(0.0, $gross - $nssf - $housingLevy);

    // Progressive tax
    $grossTax = 0.0;
    $rem      = $taxable;
    foreach (PAYE_BANDS as $b) {
        if ($rem <= 0) break;
        $inBand    = min($rem, (float)$b['limit']);
        $grossTax += $inBand * $b['rate'];
        $rem      -= $inBand;
    }

    // Reliefs
    $personalRelief  = PERSONAL_RELIEF;
    $insuranceRelief = min(round($insurancePremiums * 0.15, 2), MAX_INSURANCE_RELIEF);
    $mortgageRelief  = min($mortgageInterest, MAX_MORTGAGE_RELIEF);
    $totalRelief     = $personalRelief + $insuranceRelief + $mortgageRelief;

    $paye = max(0.0, round($grossTax - $totalRelief, 2));

    return [
        'taxable'            => round($taxable,    2),
        'paye_before_relief' => round($grossTax,   2),
        'personal_relief'    => $personalRelief,
        'insurance_relief'   => round($insuranceRelief, 2),
        'mortgage_relief'    => round($mortgageRelief,  2),
        'total_relief'       => round($totalRelief, 2),
        'paye'               => $paye,
    ];
}

// ── Full employee payroll calculation ─────────────────────────────────────
function calculateFullPayroll(float $basicSalary, array $tp): array {
    // Earnings
    $house     = (float)($tp['house_allowance']     ?? 0);
    $transport = (float)($tp['transport_allowance'] ?? 0);
    $medical   = (float)($tp['medical_allowance']   ?? 0);
    $other     = (float)($tp['other_allowances']    ?? 0);
    $gross     = $basicSalary + $house + $transport + $medical + $other;

    // Statutory employee deductions
    $nssf      = calcNSSF($gross, $tp['nssf_type']  ?? 'new');
    $shif      = calcSHIF($gross, (bool)($tp['use_shif'] ?? true));
    $housing   = calcHousingLevy($gross);
    $payeInfo  = calcPAYE($gross, $nssf['employee'], $housing['employee'],
                          (float)($tp['insurance_premiums'] ?? 0),
                          (float)($tp['mortgage_interest']  ?? 0));

    // Other recurring deductions
    $helb     = (float)($tp['helb_amount']     ?? 0);
    $loan     = (float)($tp['loan_amount']     ?? 0);
    $advance  = (float)($tp['advance_deduction'] ?? 0);
    $otherDed = (float)($tp['other_deduction']  ?? 0);

    $totalDed = $nssf['employee'] + $shif + $housing['employee'] + $payeInfo['paye']
              + $helb + $loan + $advance + $otherDed;
    $net      = max(0.0, $gross - $totalDed);

    return [
        'basic_salary'          => round($basicSalary, 2),
        'house_allowance'       => round($house,     2),
        'transport_allowance'   => round($transport, 2),
        'medical_allowance'     => round($medical,   2),
        'other_allowances'      => round($other,     2),
        'gross_pay'             => round($gross,     2),
        'nssf_employee'         => round($nssf['employee'], 2),
        'nssf_employer'         => round($nssf['employer'], 2),
        'shif'                  => round($shif,      2),
        'housing_levy'          => round($housing['employee'], 2),
        'housing_levy_employer' => round($housing['employer'], 2),
        'taxable_pay'           => $payeInfo['taxable'],
        'paye_before_relief'    => $payeInfo['paye_before_relief'],
        'personal_relief'       => $payeInfo['personal_relief'],
        'insurance_relief'      => $payeInfo['insurance_relief'],
        'paye'                  => $payeInfo['paye'],
        'helb'                  => round($helb,     2),
        'loan_deduction'        => round($loan,     2),
        'advance_deduction'     => round($advance,  2),
        'other_deductions'      => round($otherDed, 2),
        'total_deductions'      => round($totalDed, 2),
        'net_pay'               => round($net,      2),
    ];
}

// ── Self-heal payroll tables ───────────────────────────────────────────────
function ensurePayrollTables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $sqls = [
        "CREATE TABLE IF NOT EXISTS employee_tax_profile (
            employee_id         VARCHAR(36) NOT NULL PRIMARY KEY,
            kra_pin             VARCHAR(20)  NULL,
            nssf_number         VARCHAR(30)  NULL,
            shif_number         VARCHAR(30)  NULL,
            house_allowance     DECIMAL(15,2) NOT NULL DEFAULT 0,
            transport_allowance DECIMAL(15,2) NOT NULL DEFAULT 0,
            medical_allowance   DECIMAL(15,2) NOT NULL DEFAULT 0,
            other_allowances    DECIMAL(15,2) NOT NULL DEFAULT 0,
            helb_amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
            loan_amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
            insurance_premiums  DECIMAL(15,2) NOT NULL DEFAULT 0,
            mortgage_interest   DECIMAL(15,2) NOT NULL DEFAULT 0,
            nssf_type           VARCHAR(10)  NOT NULL DEFAULT 'new',
            use_shif            TINYINT(1)   NOT NULL DEFAULT 1,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS payroll_periods (
            id           VARCHAR(36)  NOT NULL PRIMARY KEY,
            period_year  SMALLINT     NOT NULL,
            period_month TINYINT      NOT NULL,
            status       VARCHAR(20)  NOT NULL DEFAULT 'Draft',
            notes        TEXT         NULL,
            created_by   VARCHAR(36)  NULL,
            processed_at TIMESTAMP    NULL,
            created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_period (period_year, period_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS payroll_entries (
            id                   VARCHAR(36)   NOT NULL PRIMARY KEY,
            period_id            VARCHAR(36)   NOT NULL,
            employee_id          VARCHAR(36)   NOT NULL,
            basic_salary         DECIMAL(15,2) NOT NULL DEFAULT 0,
            house_allowance      DECIMAL(15,2) NOT NULL DEFAULT 0,
            transport_allowance  DECIMAL(15,2) NOT NULL DEFAULT 0,
            medical_allowance    DECIMAL(15,2) NOT NULL DEFAULT 0,
            other_allowances     DECIMAL(15,2) NOT NULL DEFAULT 0,
            gross_pay            DECIMAL(15,2) NOT NULL DEFAULT 0,
            nssf_employee        DECIMAL(15,2) NOT NULL DEFAULT 0,
            nssf_employer        DECIMAL(15,2) NOT NULL DEFAULT 0,
            shif                 DECIMAL(15,2) NOT NULL DEFAULT 0,
            housing_levy         DECIMAL(15,2) NOT NULL DEFAULT 0,
            housing_levy_employer DECIMAL(15,2) NOT NULL DEFAULT 0,
            paye                 DECIMAL(15,2) NOT NULL DEFAULT 0,
            taxable_pay          DECIMAL(15,2) NOT NULL DEFAULT 0,
            paye_before_relief   DECIMAL(15,2) NOT NULL DEFAULT 0,
            personal_relief      DECIMAL(15,2) NOT NULL DEFAULT 0,
            insurance_relief     DECIMAL(15,2) NOT NULL DEFAULT 0,
            helb                 DECIMAL(15,2) NOT NULL DEFAULT 0,
            loan_deduction       DECIMAL(15,2) NOT NULL DEFAULT 0,
            advance_deduction    DECIMAL(15,2) NOT NULL DEFAULT 0,
            other_deductions     DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_deductions     DECIMAL(15,2) NOT NULL DEFAULT 0,
            net_pay              DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes                TEXT          NULL,
            created_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_entry (period_id, employee_id),
            FOREIGN KEY (period_id)   REFERENCES payroll_periods(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES employees(id)        ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($sqls as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) {} }
    $done = true;
}

// ── Month name helper ──────────────────────────────────────────────────────
function monthName(int $m): string {
    return date('F', mktime(0, 0, 0, $m, 1));
}
