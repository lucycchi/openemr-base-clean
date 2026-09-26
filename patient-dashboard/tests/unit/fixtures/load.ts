import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Reads a recorded FHIR fixture from tests/unit/fixtures. Resolved from the project root because
 * import.meta.url is not a file URL under the jsdom test environment.
 */
export function loadFixture<T>(name: string): T {
    return JSON.parse(readFileSync(join(process.cwd(), 'tests', 'unit', 'fixtures', name), 'utf8')) as T;
}
