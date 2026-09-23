<?php

/**
 * One set of vital signs as the chart holds it (form_vitals). OpenEMR stores
 * weight in pounds and temperature in Fahrenheit; blood pressure is two
 * strings, parsed to integers here or null when unparsable. Any field can
 * be null: a reading records only what was measured.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class VitalRecord
{
    public function __construct(
        public int $id,
        public int $encounterId,
        public \DateTimeImmutable $date,
        public ?int $systolic = null,
        public ?int $diastolic = null,
        public ?float $pulse = null,
        public ?float $spo2 = null,
        public ?float $temperatureF = null,
        public ?float $respiration = null,
        public ?float $weightLb = null,
        public ?float $bmi = null,
    ) {
    }
}
