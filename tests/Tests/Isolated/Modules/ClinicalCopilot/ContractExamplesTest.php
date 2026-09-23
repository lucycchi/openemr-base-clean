<?php

/**
 * The shared contract examples, run on the PHP side.
 *
 * contracts/examples/<name>.examples.json holds documents every contract
 * must accept and documents it must reject. The sidecar's pytest runs the
 * same files against the JSON Schema and its Pydantic models; this test
 * runs them against the JSON Schema through Contracts::violations() (the
 * runtime gate SidecarClient applies) and, for the shapes PHP parses, proves
 * the typed parsers accept every accepted document. One set of examples,
 * two implementations, one contract.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;
use OpenEMR\Modules\ClinicalCopilot\Documents\Handoff;
use OpenEMR\Modules\ClinicalCopilot\Documents\IntakeExtraction;
use OpenEMR\Modules\ClinicalCopilot\Documents\LabReportExtraction;
use OpenEMR\Modules\ClinicalCopilot\Documents\RunResult;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractExamplesTest extends TestCase
{
    private const EXAMPLES_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/examples/';

    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function exampleFiles(): array
    {
        $out = [];
        foreach (glob(self::EXAMPLES_DIR . '*.examples.json') ?: [] as $path) {
            $name = substr(basename($path), 0, -strlen('.examples.json'));
            $out[$name] = [$name];
        }
        return $out;
    }

    /** @return array{accept: list<array<string, mixed>>, reject: list<array<string, mixed>>} */
    private static function examples(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(self::EXAMPLES_DIR . $name . '.examples.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['accept'] ?? null);
        self::assertIsArray($decoded['reject'] ?? null);
        self::assertNotEmpty($decoded['accept'], "$name needs at least one accepted example");
        self::assertNotEmpty($decoded['reject'], "$name needs at least one rejected example");
        /** @var array{accept: list<array<string, mixed>>, reject: list<array<string, mixed>>} $decoded */
        return $decoded;
    }

    /**
     * Pins: the JSON Schema itself gives the intended verdict on every
     * example. A failure means a contract was edited so that it now refuses
     * a document it should accept (or accepts one it should refuse), and the
     * sidecar and PHP would start disagreeing at the boundary.
     */
    #[DataProvider('exampleFiles')]
    public function testContractAcceptsAndRejectsItsExamples(string $name): void
    {
        $examples = self::examples($name);
        foreach ($examples['accept'] as $i => $doc) {
            self::assertSame([], Contracts::violations($name, $doc), "$name rejected accepted example #$i");
        }
        foreach ($examples['reject'] as $i => $doc) {
            self::assertNotSame([], Contracts::violations($name, $doc), "$name accepted rejected example #$i");
        }
    }

    /**
     * Pins: PHP's typed parsers are no stricter than the contract. A failure
     * means a parser would throw schema_mismatch on a reply the contract
     * (and the sidecar) consider valid, and a good extraction would be
     * reported to the user as a sidecar error.
     */
    #[DataProvider('exampleFiles')]
    public function testTypedParserAcceptsEveryAcceptedExample(string $name): void
    {
        $parser = match ($name) {
            'citation' => Citation::fromArray(...),
            'handoff' => Handoff::fromArray(...),
            'lab-report' => LabReportExtraction::fromArray(...),
            'intake-form' => IntakeExtraction::fromArray(...),
            'run.response' => RunResult::fromArray(...),
            default => null,
        };
        if ($parser === null) {
            // Request shapes are covered by DocumentRequestTest / ChatRequestTest;
            // response shapes PHP emits are validated in ContractsTest and by the
            // eval harness against the real controllers.
            self::markTestSkipped("$name has no PHP parser");
        }
        $accepted = self::examples($name)['accept'];
        $parsed = 0;
        foreach ($accepted as $doc) {
            $parser($doc); // throws SidecarException on anything the parser refuses
            $parsed++;
        }
        self::assertSame(count($accepted), $parsed, "$name parser accepted every accepted example");
    }

    /**
     * Pins: every examples file belongs to a real contract. A failure means
     * an examples file was renamed or a contract deleted, so the examples
     * above would be silently testing nothing.
     */
    public function testEveryExampleFileNamesAContract(): void
    {
        $names = array_keys(self::exampleFiles());
        self::assertNotEmpty($names);
        foreach ($names as $name) {
            self::assertSame('object', Contracts::schema($name)->type ?? null, "$name is a contract");
        }
    }
}
