<?php

/**
 * An intake form as extracted by the sidecar, flattened to cited items; demographics are compared, never stored (contracts/intake-form.schema.json).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

final readonly class IntakeExtraction
{
    /**
     * @param list<IntakeItem> $items
     * @param array<string, string> $demographics name/dob/sex/phone as printed, for comparison with the chart only
     */
    public function __construct(public array $items, public array $demographics)
    {
    }

    /** @param array<mixed> $a  decoded JSON; every value is narrowed here */
    public static function fromArray(array $a): self
    {
        if (($a['doc_type'] ?? null) !== 'intake_form' || !is_array($a['demographics'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        $items = [];
        $cited = static function (mixed $node, string $kind, string $path, ?string $detail = null) use (&$items): void {
            if (!is_array($node) || !is_string($node['value'] ?? null) || !is_array($node['citation'] ?? null)) {
                throw new SidecarException('schema_mismatch');
            }
            $items[] = new IntakeItem($kind, $path, $node['value'], $detail, Citation::fromArray($node['citation']));
        };
        if (is_string($a['form_date'] ?? null) && is_array($a['form_date_citation'] ?? null)) {
            $items[] = new IntakeItem('form_date', '/form_date', $a['form_date'], null, Citation::fromArray($a['form_date_citation']));
        }
        if (($a['chief_concern'] ?? null) !== null) {
            $cited($a['chief_concern'], 'chief_concern', '/chief_concern');
        }
        foreach (['medications' => 'medication', 'allergies' => 'allergy', 'family_history' => 'family_history'] as $key => $kind) {
            $list = $a[$key] ?? null;
            if (!is_array($list)) {
                throw new SidecarException('schema_mismatch');
            }
            foreach (array_values($list) as $i => $entry) {
                if (!is_array($entry) || !is_array($entry['citation'] ?? null)) {
                    throw new SidecarException('schema_mismatch');
                }
                [$value, $detail, $field] = match ($kind) {
                    'medication' => [$entry['name'] ?? null, trim(sprintf('%s %s', is_string($entry['dose'] ?? null) ? $entry['dose'] : '', is_string($entry['frequency'] ?? null) ? $entry['frequency'] : '')) ?: null, 'name'],
                    'allergy' => [$entry['substance'] ?? null, is_string($entry['reaction'] ?? null) ? $entry['reaction'] : null, 'substance'],
                    default => [$entry['condition'] ?? null, is_string($entry['relative'] ?? null) ? $entry['relative'] : null, 'condition'],
                };
                if (!is_string($value)) {
                    throw new SidecarException('schema_mismatch');
                }
                $items[] = new IntakeItem($kind, "/$key/$i/$field", $value, $detail, Citation::fromArray($entry['citation']));
            }
        }
        $demo = [];
        foreach (['name', 'dob', 'sex', 'phone'] as $k) {
            $node = $a['demographics'][$k] ?? null;
            if (is_array($node) && is_string($node['value'] ?? null)) {
                $demo[$k] = $node['value'];
            }
        }
        return new self($items, $demo);
    }

    /** @return list<Citation> */
    public function citations(): array
    {
        return array_map(static fn(IntakeItem $i): Citation => $i->citation, $this->items);
    }
}
