<?php
declare(strict_types=1);

/**
 * Generates an Ed25519 key pair for signing plugin updates (docs/update-signing.md).
 *
 * stdout: the PRIVATE key (base64) — meant to be piped straight into `gh secret set`
 *         or into a file kept offline, never shown on screen;
 * stderr: the PUBLIC key (base64) — goes into UpdateSignature::PUBLIC_KEYS.
 *
 * Refuses to run when stdout is a terminal, so the private key cannot land in the
 * scrollback by accident.
 */

if (stream_isatty(STDOUT)) {
    fwrite(STDERR, "Przekieruj stdout (np. | gh secret set ...) — klucz prywatny nie trafia na ekran.\n");
    exit(2);
}

$pair = sodium_crypto_sign_keypair();

fwrite(STDERR, 'PUBLIC KEY: ' . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n");
fwrite(STDOUT, base64_encode(sodium_crypto_sign_secretkey($pair)));

sodium_memzero($pair);
