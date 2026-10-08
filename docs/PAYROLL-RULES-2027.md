# Payroll rules — South Africa, 2027 tax year (1 March 2026 – 28 February 2027)

*Checked 2 October 2026. These figures are the SEED values for the payroll settings. They are
stored as settings (one row per tax year), never hard-coded in calculations, and a new tax year
is added each March after the Budget. Payroll must refuse to run for a pay date that has no
tax-year row.*

## Income tax brackets (annual taxable income) — source: SARS, verified on sars.gov.za
| From (R) | To (R) | Tax |
|---|---|---|
| 1 | 245 100 | 18% of taxable income |
| 245 101 | 383 100 | 44 118 + 26% above 245 100 |
| 383 101 | 530 200 | 79 998 + 31% above 383 100 |
| 530 201 | 695 800 | 125 599 + 36% above 530 200 |
| 695 801 | 887 000 | 185 215 + 39% above 695 800 |
| 887 001 | 1 878 600 | 259 783 + 41% above 887 000 |
| 1 878 601 | — | 666 339 + 45% above 1 878 600 |

The base amounts were re-added by hand and match the rates exactly.

## Rebates (annual) — SARS
| Rebate | Amount (R) | Applies |
|---|---|---|
| Primary | 17 820 | everyone |
| Secondary | 9 765 | age 65 and older |
| Tertiary | 3 249 | age 75 and older |

Thresholds: under 65 R99 000 · 65+ R153 250 · 75+ R171 300.

## Medical scheme fees tax credit (monthly) — SARS, labels read verbatim
| Covered | Monthly credit (R) |
|---|---|
| Taxpayer alone (or one dependant where the taxpayer is not a member) | 376 |
| Taxpayer and one dependant (or two dependants where the taxpayer is not a member) | 752 |
| Each additional dependant | 254 |

So: member alone 376 · member + 1 = 752 · member + 3 = 752 + 2 × 254 = 1 260.

## Retirement fund contributions (section 11F) — secondary sources, confirm on SARS
Deduction limited to 27.5% of the greater of remuneration or taxable income, capped at
**R430 000 a year from the 2027 tax year** (was R350 000). Through payroll, the employee's
pension/provident/RA contribution reduces remuneration for PAYE within that limit.

## UIF — Unemployment Insurance Fund
Employee 1% + employer 1% of remuneration, on earnings up to **R17 712 a month** (unchanged
since 1 June 2021). Maximum R177,12 each, R354,24 total a month. Not deducted for people
working fewer than 24 hours a month.

## SDL — Skills Development Levy
Employer only, 1% of leviable remuneration. Exempt when the employer's total annual payroll is
R500 000 or less (setting `sdl_registered` yes/no).

## Monthly PAYE method used (the standard tax-table method)
1. Monthly taxable remuneration = gross + taxable allowances/fringe benefits − allowed
   retirement contributions.
2. Annualise: × 12 (irregular amounts such as bonuses are taxed on the annual-equivalent
   difference method: tax on (annual + bonus) − tax on annual).
3. Annual tax from the brackets − rebates by age at the end of the tax year (28 Feb).
4. ÷ 12, minus the monthly medical credit. Never below zero.

## What a payslip must show (Basic Conditions of Employment Act, section 33)
Employer name and address · employee name and occupation · period · remuneration in money ·
any deductions with their purpose · actual amount paid · and where relevant: ordinary hours,
overtime hours, and the rate for each.

## What the system produces each month
- Payslip per employee, stored privately as a self-contained HTML page (it can be printed from the browser).
  Each employee sees only their own payslips on the Payroll screen. PDF payslips come later.
- Payroll summary: gross, PAYE, UIF (employee + employer), SDL, net pay, other deductions.
- **EMP201 figures** (PAYE + UIF + SDL totals) for the owner to capture on SARS eFiling.
  The system does NOT submit to SARS.
- A bank payment file (CSV) of net pay per employee for the owner to upload to the bank.
- Not built yet: IRP5 certificates and the EMP501 reconciliation figures (twice a year), and
  the certificate files for e@syFile. Until then the owner prepares these from the finalised
  payslips. They are planned for a later version.

## Rules
- Salary, bank details and tax numbers are encrypted at rest.
- A pay run is drafted, checked by a second person, then finalised. Finalised payslips are
  immutable; a correction is a new adjustment line in the next run.
- Hours from approved timesheets feed hourly-paid staff and overtime; unpaid leave from
  approved leave reduces pay.
- Nothing is paid by the system. It calculates, records and produces files.

Sources:
- SARS individual rates: https://www.sars.gov.za/tax-rates/income-tax/rates-of-tax-for-individuals/
- SARS medical credits: https://www.sars.gov.za/tax-rates/medical-tax-credit-rates/
- UIF and SDL: https://payloop.co.za/tax-tables and https://www.taxtim.com/za/calculators/uif (secondary)
- Retirement cap: https://www.accountingweekly.com/tax/retirement-fund-tax-in-south-africa-2026/27-rules-explained (secondary)
