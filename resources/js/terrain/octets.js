/** Utilitaires d'octets partagés par la tablette (empreinte de pièces, identifiants). */

/** SHA-256 hexadécimal minuscule d'octets (ArrayBuffer, vue typée ou Blob). */
export async function sha256Hex(donnees) {
    const tampon = typeof donnees?.arrayBuffer === 'function' ? await donnees.arrayBuffer() : donnees;
    const condense = new Uint8Array(await globalThis.crypto.subtle.digest('SHA-256', tampon));

    return Array.from(condense, (o) => o.toString(16).padStart(2, '0')).join('');
}

/** Identifiant aléatoire de 36 caractères [0-9a-f-], utilisable comme clé d'idempotence (16 à 64 [A-Za-z0-9-]). */
export function identifiantAleatoire() {
    return globalThis.crypto.randomUUID();
}
