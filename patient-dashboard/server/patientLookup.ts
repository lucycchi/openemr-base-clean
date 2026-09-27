/**
 * Turns a patient's uuid (the id FHIR and the page use) into OpenEMR's numeric patient number (pid),
 * which the medication-list and prescription routes need (runs on the server). In: the Standard API
 * address, the user's token, the uuid. Out: the pid. Throws PatientLookupError if OpenEMR refuses,
 * answers for another patient, or sends no pid.
 */
export class PatientLookupError extends Error {}

/** Give up on OpenEMR after 20 seconds. */
const TIMEOUT_MS = 20_000;

export async function lookupPid(
    apiBase: string,
    accessToken: string,
    patientUuid: string,
    fetchImpl: typeof fetch,
): Promise<number> {
    const res = await fetchImpl(`${apiBase}/patient/${patientUuid}`, {
        method: 'GET',
        headers: { Authorization: `Bearer ${accessToken}`, Accept: 'application/json' },
        signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (!res.ok) {
        throw new PatientLookupError(`patient lookup returned HTTP ${res.status}`);
    }
    // OpenEMR wraps the record in `data`, and may send the pid as text ("36"), so it is converted here.
    const body = (await res.json()) as { data?: { uuid?: unknown; pid?: unknown } };
    const pid = Number(body.data?.pid);
    if (body.data?.uuid !== patientUuid || !Number.isInteger(pid) || pid <= 0) {
        throw new PatientLookupError('patient lookup did not match');
    }
    return pid;
}
