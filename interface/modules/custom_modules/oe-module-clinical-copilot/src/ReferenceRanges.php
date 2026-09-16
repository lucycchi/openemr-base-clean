<?php

/**
 * Curated adult reference ranges by LOINC code for common primary-care tests.
 *
 * Source: general adult reference intervals as published by the NIH MedlinePlus
 * lab test pages and ADA (A1c). Versioned; any change must bump VERSION so
 * cached narrations are invalidated. Local lab ranges vary; this table is a
 * deliberately small, documented clinical rule, not a universal truth.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final class ReferenceRanges
{
    public const VERSION = '2026-09-15.1';

    /** @var array<string, array{float, float, string}> loinc => [low, high, units] */
    private const RANGES = [
        '4548-4' => [4.0, 5.6, '%'],          // Hemoglobin A1c
        '2345-7' => [70.0, 99.0, 'mg/dL'],    // Glucose, serum, fasting
        '718-7' => [12.0, 17.5, 'g/dL'],      // Hemoglobin
        '4544-3' => [36.0, 50.0, '%'],        // Hematocrit
        '6690-2' => [4.5, 11.0, '10*3/uL'],   // Leukocytes
        '789-8' => [4.2, 5.9, '10*6/uL'],     // Erythrocytes
        '777-3' => [150.0, 400.0, '10*3/uL'], // Platelets
        '2160-0' => [0.6, 1.3, 'mg/dL'],      // Creatinine
        '3094-0' => [7.0, 20.0, 'mg/dL'],     // Urea nitrogen
        '2951-2' => [135.0, 145.0, 'mmol/L'], // Sodium
        '2823-3' => [3.5, 5.1, 'mmol/L'],     // Potassium
        '2093-3' => [0.0, 199.0, 'mg/dL'],    // Cholesterol, total
        '2085-9' => [40.0, 200.0, 'mg/dL'],   // HDL cholesterol
        '2089-1' => [0.0, 129.0, 'mg/dL'],    // LDL cholesterol
        '2571-8' => [0.0, 149.0, 'mg/dL'],    // Triglycerides
        '3016-3' => [0.4, 4.0, 'm[IU]/L'],    // TSH
    ];

    /** @return array{float, float, string}|null */
    public function for(string $loinc): ?array
    {
        return self::RANGES[$loinc] ?? null;
    }
}
