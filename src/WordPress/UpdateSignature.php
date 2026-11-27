<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ed25519 signature of a plugin update (decision 2026-11-27, headlinesforge spec
 * docs/specs/2026-11-03-otwarte-api-cms-design.md, D10).
 *
 * Why: the sha256 in the manifest lives on the same R2 bucket as the ZIP, so anyone who can
 * write to R2 could ship code to every site with auto-updates on. The signature is made by a
 * release step that runs in a GitHub environment the owner approves, with a key kept apart
 * from the R2 keys — R2 alone is no longer enough.
 *
 * What is signed: message() — slug, version and sha256 of the ZIP, on separate lines behind
 * a fixed prefix. The sha256 ties the signature to the exact bytes of the package (the
 * updater still hashes the downloaded file and compares), the version stops a signed old
 * package from being relabelled as a newer one, the prefix stops a signature made for
 * anything else from passing here. The rest of the manifest (changelog, "Requires") is
 * display text only and stays unsigned.
 *
 * Why not WordPress core's own check (wp_trusted_keys / wp_signature_hosts in download_url):
 * it covers only the hosts in wp_signature_hosts (wordpress.org), a key added to
 * wp_trusted_keys would be trusted for wordpress.org packages as well, and a failed check
 * only warns by default (wp_signature_softfail). Besides, Updater::verify_package() hands
 * core an already downloaded file, so core's download_url() never runs for our package.
 */
final class UpdateSignature
{
    /**
     * Trusted public keys, base64 of the raw 32-byte Ed25519 key. Room for two: the release
     * key and the backup / next key. ROTATION: the release that changes this list must
     * itself be signed with a key that the INSTALLED versions already trust — otherwise no
     * site takes it. Procedure: docs/update-signing.md.
     */
    public const PUBLIC_KEYS = [
    ];

    private const PREFIX = 'tribunedesk-wp-plugin-update:v1';

    /** @var list<string>|null Keys swapped in by tests; null = PUBLIC_KEYS. */
    private static ?array $keys_override = null;

    public static function message(string $slug, string $version, string $sha256): string
    {
        return self::PREFIX . "\n" . $slug . "\n" . $version . "\n" . strtolower($sha256) . "\n";
    }

    /**
     * True only when the manifest carries a signature that one of the trusted keys made over
     * its slug, version and sha256. No keys, no signature, a malformed one -> false.
     *
     * @param array<string, mixed> $manifest
     */
    public static function is_valid(array $manifest, string $slug): bool
    {
        $version   = $manifest['version'] ?? null;
        $sha256    = $manifest['sha256'] ?? null;
        $signature = $manifest['signature'] ?? null;

        if (!is_string($version) || !is_string($sha256) || !is_string($signature)) {
            return false;
        }

        $raw = base64_decode($signature, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        $message = self::message($slug, $version, $sha256);

        foreach (self::keys() as $key) {
            $public = base64_decode($key, true);
            if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }
            try {
                if (sodium_crypto_sign_verify_detached($raw, $message, $public)) {
                    return true;
                }
            } catch (\SodiumException $e) {
                continue;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return self::$keys_override ?? array_values(self::PUBLIC_KEYS);
    }

    /**
     * Test seam only: lets the test suite sign with a throwaway key pair. Code that can call
     * this already runs inside WordPress and could replace the plugin outright, so it opens
     * nothing an attacker on the update path (R2, the network) can reach.
     *
     * @param list<string>|null $keys
     */
    public static function use_keys_for_tests(?array $keys): void
    {
        self::$keys_override = $keys;
    }
}
