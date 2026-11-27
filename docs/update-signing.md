# Update signing (Ed25519)

Decision: owner, 2026-11-27 — headlinesforge `docs/specs/2026-11-03-otwarte-api-cms-design.md`, D10
("podpis auto-aktualizacji").

## What it protects against

Until 2.7.x the plugin checked a downloaded update only against the sha256 in `manifest.json`,
and both live in the same R2 bucket. Whoever could write to that bucket (or held the R2 keys
from the repo's Actions secrets) could ship code to every site with auto-updates on.

From 2.8.0 the plugin also requires an Ed25519 signature in the manifest (`signature`), made
over `UpdateSignature::message()`:

```
tribunedesk-wp-plugin-update:v1
headlinesforge-receiver
<version>
<sha256 of the ZIP, lowercase>
```

The plugin hashes the downloaded ZIP, compares it with `sha256`, then checks the signature with
the public keys built into `src/WordPress/UpdateSignature.php` (`PUBLIC_KEYS`, room for two).
No valid signature → the update is refused (`headlinesforge_signature_invalid`), the installed
version stays. There is no warning-only mode.

The private key exists in one place: the secret `UPDATE_SIGNING_KEY` of the GitHub environment
`plugin-signing`, which requires the owner's approval. The R2 keys stay repo secrets used by the
`upload` job; the `sign` job touches no R2. Taking over R2 alone no longer reaches customers.

WordPress core's own signature check (`wp_trusted_keys` / `wp_signature_hosts`) is not used: it
covers only wordpress.org hosts, a key added there would be trusted for wordpress.org packages
too, and a failed check only warns by default. Our check runs in `upgrader_pre_download`, before
unpacking, next to the existing sha256 check.

## Transition (no site is cut off)

1. 2.7.x sites update to 2.8.0 the old way (sha256 only) — they do not know about signatures
   yet; the `signature` field in the manifest is ignored by them. This cannot be done otherwise.
2. From 2.8.0 on, every update must be signed. The release pipeline signs every version from
   2.8.0, so there is no window where a site on 2.8.0 sees an unsigned manifest.
3. A site that skipped 2.8.0 (e.g. 2.7.1 → 2.9.0) still updates the old way, and from then on
   checks signatures. No intermediate "warn only" version is needed.
4. Rollback dam (`publish-manifest` with an older version number): still works — sites already
   on the newer version ignore a lower version anyway, signed or not.

The one hard requirement: **the public key must be in `PUBLIC_KEYS` before 2.8.0 is tagged.** A
release with an empty list could never accept another update. The pipeline guards this:
`bin/sign-update.php` refuses a key that is not in `PUBLIC_KEYS`, and `bin/build-manifest.php`
refuses a signature no listed key verifies, so such a release never reaches R2.

## One-time setup (owner)

Run in PowerShell, from the repo checkout, logged in with `gh`. Nothing below prints a private
key; `bin/generate-update-key.php` refuses to run when its output is a terminal.

1. Create the environment with you as required reviewer, limited to `v*` tags:

   ```powershell
   gh api -X PUT repos/Tribunedesk/tribunedesk-wordpress/environments/plugin-signing -F "reviewers[][type]=User" -F "reviewers[][id]=$(gh api user --jq .id)" -F "deployment_branch_policy[protected_branches]=false" -F "deployment_branch_policy[custom_branch_policies]=true"
   ```

   ```powershell
   gh api -X POST repos/Tribunedesk/tribunedesk-wordpress/environments/plugin-signing/deployment-branch-policies -f name="v*" -f type=tag
   ```

   (The same in the web UI: Settings → Environments → New environment `plugin-signing` →
   Required reviewers: you; Deployment branches and tags: Selected → tag `v*`.)

2. Release key — generated straight into the environment secret:

   ```powershell
   php bin/generate-update-key.php | gh secret set UPDATE_SIGNING_KEY --env plugin-signing --repo Tribunedesk/tribunedesk-wordpress
   ```

   The line `PUBLIC KEY: …` on screen is the public half — public, safe to paste anywhere.

3. Backup key — kept offline (password manager), used only if the release key is lost or leaks:

   ```powershell
   php bin/generate-update-key.php > tribunedesk-update-backup.key
   ```

   Move the file into the password manager, then delete it from disk. Note its `PUBLIC KEY`.

4. Hand both public keys to the session that prepares 2.8.0; they go into `PUBLIC_KEYS`
   (release key first, backup second).

## Rotation

Installed sites trust only the keys built into the version they run, so **the release that
changes `PUBLIC_KEYS` must be signed with a key the installed sites already trust.**

- **Planned rotation / suspected leak of the release key:** put the backup key into
  `UPDATE_SIGNING_KEY` (`Get-Content backup.key | gh secret set …`), release a version whose
  `PUBLIC_KEYS` = [backup, newly generated key], signed with the backup. Then the new key becomes
  the release key and the backup role moves to it. If the old key leaked, do this at once: until
  sites take the new version, the leaked key plus write access to R2 is enough to ship code.
- **Release key lost (not leaked):** same as above, signed with the backup key.
- **Both keys lost:** sites on ≥ 2.8.0 can no longer be updated automatically; each one needs a
  manual ZIP upload (README → "Emergency manual install", which this check does not block).

Revisit (spec D10): the signing key leaks, or the plugin moves to wordpress.org.
