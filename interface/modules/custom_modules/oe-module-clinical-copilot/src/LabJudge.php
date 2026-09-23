<?php

/**
 * Judges one lab result against three signals, any of which can call it
 * abnormal: the laboratory's own flag (H, L, vhigh, ...), the range the
 * laboratory printed on the report, and the standard reference table for
 * the analyte and the patient's sex. The clause it returns says which range
 * was used and where it came from, and states a disagreement between the
 * printed and the standard range instead of letting one silently win.
 *
 * Precedence: a panic flag or a value outside the standard panic bounds is
 * Critical. Otherwise any abnormal signal is Abnormal. Otherwise, if any
 * range exists, Normal. A numeric result with no flag, no usable printed
 * range and no standard range is Unranged (no fact). A qualitative result
 * (no number) is Abnormal when flagged or when its wording is a positive
 * finding, Normal otherwise; it never needs a range.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final class LabJudge
{
    private const ABNORMAL_FLAGS = ['high', 'low', 'yes', 'h', 'l', 'a', 'abnormal', 'hh', 'll'];
    private const CRITICAL_FLAGS = ['vhigh', 'vlow'];
    /** Whole words that report a positive finding; matched only when no negation is present. */
    private const POSITIVE_PATTERN = '/\b(positive|reactive|detected|abnormal|present)\b/iu';
    /** "Nonreactive", "Non-reactive", "not detected", "no growth", "negative", "absent", "none": the finding is negative. */
    private const NEGATION_PATTERN = '/\b(non-?\s?\w+|not\b|no\b|negative\b|absent\b|none\b|without\b)/iu';

    public function __construct(private readonly ReferenceRanges $ranges = new ReferenceRanges())
    {
    }

    public function judge(LabRecord $lab, ?string $sex): LabJudgement
    {
        $flag = strtolower(trim($lab->labFlag));
        $flagCritical = in_array($flag, self::CRITICAL_FLAGS, true);
        $flagAbnormal = in_array($flag, self::ABNORMAL_FLAGS, true);

        if ($lab->value === null) {
            return $this->judgeQualitative($lab, $flag, $flagCritical || $flagAbnormal);
        }
        $value = $lab->value;

        // The standard table is skipped when the stored unit is not the analyte's
        // canonical unit: the numbers would be compared on the wrong scale.
        $standard = ($lab->unitMismatch || $lab->loinc === '') ? null : $this->ranges->range($lab->loinc, $sex);
        $printed = self::parsePrinted($lab->printedRange);
        $printedText = $printed === null ? '' : trim((string) $lab->printedRange);

        $standardOut = $standard !== null && !$standard->contains($value);
        $printedOut = $printed !== null && ($value < $printed[0] || $value > $printed[1]);
        $standardClause = $standard === null ? '' : sprintf('%s the standard range %s %s', $standardOut ? self::direction($value, $standard->low, $standard->high) : 'within', $standard->describe(), $standard->unit);
        $standardClause = trim($standardClause);

        if ($flagCritical || ($standard !== null && $standard->isCritical($value))) {
            $parts = [];
            if ($standard !== null && $standard->isCritical($value)) {
                $limit = $standard->panicLow !== null && $value < $standard->panicLow ? ['below', $standard->panicLow] : ['above', $standard->panicHigh ?? 0.0];
                $parts[] = sprintf('%s the panic limit %s %s', $limit[0], self::num($limit[1]), $standard->unit);
            } else {
                $parts[] = "flagged $flag by the lab";
            }
            $parts[] = $printed !== null ? "lab's range $printedText" : $standardClause;
            $direction = $standard !== null && $standard->isCritical($value) ? ($standard->panicLow !== null && $value < $standard->panicLow ? 'below' : 'above') : self::flagDirection($flag);
            return new LabJudgement(LabVerdict::Critical, implode('; ', array_filter($parts)), $this->rangeClause($printed, $printedText, $standard), $direction);
        }

        if ($flagAbnormal || $printedOut || $standardOut) {
            $parts = [];
            if ($printed !== null) {
                if ($printedOut) {
                    $parts[] = sprintf("%s the lab's range %s", self::direction($value, $printed[0], $printed[1]), $printedText);
                } elseif ($flagAbnormal) {
                    $parts[] = "flagged $flag by the lab";
                    $parts[] = "lab's range $printedText";
                    if ($standardOut) {
                        $parts[] = $standardClause;
                    }
                } else {
                    $parts[] = "within the lab's range $printedText";
                    $parts[] = $standardClause;
                }
            } else {
                if ($standardOut) {
                    $parts[] = $standardClause;
                } else {
                    $parts[] = "flagged $flag by the lab";
                    if ($standardClause !== '') {
                        $parts[] = $standardClause;
                    }
                }
                if ($lab->citation !== null && trim((string) $lab->printedRange) === '') {
                    $parts[] = 'no range printed on the report';
                }
            }
            $direction = $printedOut ? self::direction($value, $printed[0], $printed[1]) : ($standardOut ? self::direction($value, $standard->low, $standard->high) : self::flagDirection($flag));
            return new LabJudgement(LabVerdict::Abnormal, implode('; ', array_filter($parts)), $this->rangeClause($printed, $printedText, $standard), $direction);
        }

        if ($printed === null && $standard === null) {
            return new LabJudgement(LabVerdict::Unranged, '', '');
        }
        $clause = $this->rangeClause($printed, $printedText, $standard);
        return new LabJudgement(LabVerdict::Normal, $clause, $clause);
    }

    private function judgeQualitative(LabRecord $lab, string $flag, bool $flagged): LabJudgement
    {
        $text = strtolower(trim((string) $lab->text));
        if ($flagged) {
            $verdict = in_array($flag, self::CRITICAL_FLAGS, true) ? LabVerdict::Critical : LabVerdict::Abnormal;
            return new LabJudgement($verdict, $verdict === LabVerdict::Critical ? "flagged $flag by the lab" : 'flagged abnormal by the lab', '');
        }
        // A negated finding ("Nonreactive", "Not detected", "No growth") is a normal
        // result even though it contains a positive word; only an unnegated whole
        // word is a positive finding.
        if (preg_match(self::NEGATION_PATTERN, $text) === 1) {
            return new LabJudgement(LabVerdict::Normal, '', '');
        }
        if (preg_match(self::POSITIVE_PATTERN, $text, $m) === 1) {
            return new LabJudgement(LabVerdict::Abnormal, 'reported as ' . strtolower($m[1]), '');
        }
        return new LabJudgement(LabVerdict::Normal, '', '');
    }

    /**
     * "lab's range 3.5-5.1" or "reference range 135-145 mmol/L", or '' when no range exists.
     *
     * @param array{float, float}|null $printed
     */
    private function rangeClause(?array $printed, string $printedText, ?Range $standard): string
    {
        if ($printed !== null) {
            return "lab's range $printedText";
        }
        if ($standard !== null) {
            return trim(sprintf('reference range %s %s', $standard->describe(), $standard->unit));
        }
        return '';
    }

    /**
     * "3.5-5.1", "70 - 99", "3.5–5.1 mmol/L" -> [3.5, 5.1]; anything else
     * (a word, a one-sided "<130", a reversed pair) is null and the printed
     * text is then not used as a range.
     *
     * @return array{float, float}|null
     */
    public static function parsePrinted(?string $printed): ?array
    {
        if ($printed === null || preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[-\x{2013}]\s*(-?\d+(?:\.\d+)?)/u', $printed, $m) !== 1) {
            return null;
        }
        $low = (float) $m[1];
        $high = (float) $m[2];
        return $low < $high ? [$low, $high] : null;
    }

    private static function flagDirection(string $flag): ?string
    {
        return match ($flag) {
            'high', 'hh', 'h', 'vhigh' => 'above',
            'low', 'll', 'l', 'vlow' => 'below',
            default => null,
        };
    }

    private static function direction(float $value, ?float $low, ?float $high): string
    {
        return $low !== null && $value < $low ? 'below' : 'above';
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
