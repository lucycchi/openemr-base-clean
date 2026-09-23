<?php

/**
 * Reads sidecar/corpus/manifest.json so the panel can show a guideline's title and URL next to a cited chunk; the sidecar sends source ids only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

/**
 * The list of guidelines in the corpus, keyed by source id, read from the
 * manifest file the sidecar ships with. PHP needs it only to put a human
 * title and a link next to a cited chunk; the retrieval itself happens in
 * the sidecar.
 */
final class GuidelineManifest
{
    /** @var array<string, array{title: string, publisher: string, year: int, url: string}> */
    private array $docs = [];

    /**
     * Reads the manifest once. A missing or malformed file yields an empty
     * manifest rather than an error: the panel then shows the source id in
     * place of a title, and answers still work. Each entry's fields are
     * narrowed individually, with a harmless default for anything absent.
     */
    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__) . '/sidecar/corpus/manifest.json';
        $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $documents = is_array($raw) && is_array($raw['documents'] ?? null) ? $raw['documents'] : [];
        foreach ($documents as $d) {
            if (is_array($d) && is_string($d['source_id'] ?? null)) {
                $this->docs[$d['source_id']] = [
                    'title' => is_string($d['title'] ?? null) ? $d['title'] : $d['source_id'],
                    'publisher' => is_string($d['publisher'] ?? null) ? $d['publisher'] : '',
                    'year' => is_int($d['year'] ?? null) ? $d['year'] : 0,
                    'url' => is_string($d['url'] ?? null) ? $d['url'] : '',
                ];
            }
        }
    }

    /**
     * The manifest entry for a source id, or null when the corpus does not
     * list it.
     *
     * @return array{title: string, publisher: string, year: int, url: string}|null
     */
    public function document(string $sourceId): ?array
    {
        return $this->docs[$sourceId] ?? null;
    }
}
