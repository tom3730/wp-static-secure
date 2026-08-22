# Releasing

The intended first pre-alpha is `0.1.0-alpha.1`. Pre-alpha releases are for evaluation and compatibility feedback; they are not production-readiness claims.

## Checklist

1. Start from a clean checkout of the exact commit to release.
2. Confirm the version in the plugin header and `WPStaticSecure\Plugin::VERSION` is the intended SemVer pre-release version.
3. Run `composer validate --no-check-publish`, `composer install`, `composer test`, and the PHP syntax check documented in `DEVELOPMENT.md`.
4. Run `php scripts/package-plugin.php` twice and require the two `build/wp-static-secure.zip` files to be byte-for-byte identical.
5. Run `php scripts/verify-package.php build/wp-static-secure.zip` and inspect the archive for the plugin bootstrap, runtime code, production dependencies, and `LICENSE` only within the documented allowlist.
6. Require the GitHub Actions unit matrix and packaged WordPress activation smoke job to pass for the release commit. Download the `wp-static-secure-plugin` workflow artifact rather than rebuilding from an unverified checkout.
7. Confirm the canonical [private vulnerability reporting form](https://github.com/tom3730/wp-static-secure/security/advisories/new) accepts a private report. If it does not, enable **Settings → Security → Code security and analysis → Private vulnerability reporting** before tagging.
8. Prepare release notes that include all of the following:
   - evaluation-only pre-alpha status, with no production-readiness or SLA claim;
   - compatibility limited to the tested opt-in generic form and conservative Contact Form 7 subset;
   - no remote deployment executor is included;
   - operators must restrict WordPress from anonymous public access and rate-limit the public form endpoint;
   - the exact source commit and the artifact's SHA-256 digest.
9. Create an annotated tag matching the version (`v0.1.0-alpha.1`) on the verified commit, then create the GitHub pre-release and attach the verified workflow artifact.
10. Install the attached ZIP in a disposable WordPress environment and confirm activation before announcing the pre-release.

Do not tag or publish from a release-preparation PR. Tagging and GitHub Release creation happen only after that PR is reviewed and merged and the exact release commit has passed CI.

## Automated pre-release workflow

After the release workflow has been merged to `main`, run **Actions → Release → Run workflow** manually. Enter the exact annotated tag and the full commit SHA to release. The workflow must be run from `main`; it verifies that the commit is an ancestor of `main`, locates a successful `Tests` push run for that exact commit, and reuses its `wp-static-secure-plugin` artifact without rebuilding it.

For the first pre-alpha, use:

```text
release_tag:    v0.1.0-alpha.1
release_commit: 2e5501aafb1fadc54e6a59930bcf6b1934259a4d
```

The workflow verifies the unit matrix, packaged WordPress activation smoke job, package allowlist, package version, artifact provenance, and SHA-256 digest. It then creates or verifies an annotated tag, publishes a GitHub pre-release with the verified ZIP, and downloads the published asset again to verify its digest. A release tag that points to another commit or is not annotated fails closed. A failed run may be retried only when the existing tag is annotated and points to the same verified commit; an existing GitHub Release always stops the workflow.
