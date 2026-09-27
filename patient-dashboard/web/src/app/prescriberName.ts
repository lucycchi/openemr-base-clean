/**
 * The name the prescription form's Prescriber box starts with (ARC-06). In: the answer from the BFF's
 * names lookup (/api/display-names, {"names": {"Practitioner/<id>": "Lee, Donna"}, "failed": [...]}) and
 * the signed-in user's id. Out: their name, or '' when it was not found, the lookup failed, or the answer
 * is not in the expected shape; the user can always type a name.
 */
export function prescriberNameFrom(body: unknown, userId: string): string {
    if (typeof body !== 'object' || body === null || !('names' in body)) {
        return '';
    }
    const names = body.names;
    if (typeof names !== 'object' || names === null) {
        return '';
    }
    const name = (names as Record<string, unknown>)[`Practitioner/${userId}`];
    return typeof name === 'string' ? name : '';
}
