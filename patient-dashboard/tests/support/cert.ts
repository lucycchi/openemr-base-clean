import { X509Certificate, createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';

/**
 * Chromium flag that trusts exactly the OpenEMR dev certificate (by its public-key hash)
 * instead of turning certificate checks off. The dev cert has no subjectAltName, so it
 * cannot be trusted by importing it. Returns no flag when the cert file is absent.
 */
export function devCertTrustArgs(certPath = 'certs/dev-cert.pem'): string[] {
    if (!existsSync(certPath)) {
        return [];
    }
    const cert = new X509Certificate(readFileSync(certPath));
    const spki = cert.publicKey.export({ type: 'spki', format: 'der' });
    const hash = createHash('sha256').update(spki).digest('base64');
    return [`--ignore-certificate-errors-spki-list=${hash}`];
}
