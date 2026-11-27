<?php
declare(strict_types=1);

/**
 * Signs a release: Ed25519 over UpdateSignature::message(slug, version, sha256).
 * Runs ONLY in release.yml, in the job bound to the owner-approved environment
 * `plugin-signing` — the one place the private key exists (docs/update-signing.md).
 *
 * Usage: UPDATE_SIGNING_KEY=<base64 secret key> php bin/sign-update.php <version> <sha256>
 * Prints the base64 signature on stdout. Never prints the key.
 *
 * Refuses when the key's public half is not in UpdateSignature::PUBLIC_KEYS: a signature
 * no installed plugin trusts would only surface at customers, as failed updates.
 */

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/src/WordPress/UpdateSignature.php';

use TribuneDesk\WordPress\UpdateSignature;

$version = $argv[1] ?? '';
$sha256  = $argv[2] ?? '';

if ($version === '' || preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
    fwrite(STDERR, "Uzycie: UPDATE_SIGNING_KEY=... php bin/sign-update.php <wersja> <sha256>\n");
    exit(2);
}

$secret = base64_decode(trim((string) getenv('UPDATE_SIGNING_KEY')), true);

if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "UPDATE_SIGNING_KEY pusty albo nie jest kluczem Ed25519 (base64, 64 bajty).\n");
    exit(1);
}

$public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));

if (!in_array($public, UpdateSignature::PUBLIC_KEYS, true)) {
    fwrite(STDERR, "Klucz publiczny tego klucza ({$public}) nie jest w UpdateSignature::PUBLIC_KEYS.\n");
    exit(1);
}

$slug      = 'headlinesforge-receiver';
$signature = base64_encode(sodium_crypto_sign_detached(UpdateSignature::message($slug, $version, $sha256), $secret));
sodium_memzero($secret);

echo $signature . "\n";
