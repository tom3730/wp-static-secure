<?php

declare(strict_types=1);

$tag = getenv('WPS_RELEASE_TAG') ?: '';
$assetName = getenv('WPS_RELEASE_ASSET') ?: '';
$expectedCommit = strtolower(getenv('WPS_RELEASE_COMMIT') ?: '');
$overrideDigest = strtolower(getenv('WPS_RELEASE_SHA256') ?: '');
if (!preg_match('/^v[0-9A-Za-z.-]+$/', $tag) || $assetName !== 'wp-static-secure.zip' || !preg_match('/^[a-f0-9]{40}$/', $expectedCommit)) {
    fwrite(STDERR, "Invalid release pin.\n"); exit(2);
}
$ctx = stream_context_create(['http' => ['header' => "User-Agent: wp-static-secure-acceptance\r\nAccept: application/vnd.github+json\r\n", 'timeout' => 30]]);
$getJson = static function (string $url) use ($ctx): array {
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) { throw new RuntimeException("Unable to load {$url}"); }
    $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) { throw new RuntimeException("Unexpected JSON from {$url}"); }
    return $decoded;
};
try {
    $release = $getJson("https://api.github.com/repos/tom3730/wp-static-secure/releases/tags/" . rawurlencode($tag));
    $ref = $getJson("https://api.github.com/repos/tom3730/wp-static-secure/git/ref/tags/" . rawurlencode($tag));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); exit(3);
}
if (($release['draft'] ?? true) || !($release['prerelease'] ?? false) || ($release['tag_name'] ?? '') !== $tag) {
    fwrite(STDERR, "Release metadata does not match the pinned published pre-release.\n"); exit(4);
}
$tagObject = $ref['object'] ?? null;
if (!is_array($tagObject) || ($tagObject['type'] ?? '') !== 'tag' || !isset($tagObject['url'])) {
    fwrite(STDERR, "Release tag is not annotated.\n"); exit(5);
}
try { $annotated = $getJson((string) $tagObject['url']); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(6); }
if (($annotated['object']['type'] ?? '') !== 'commit' || strtolower((string)($annotated['object']['sha'] ?? '')) !== $expectedCommit) {
    fwrite(STDERR, "Annotated release tag does not point to the pinned commit.\n"); exit(7);
}
$body = (string)($release['body'] ?? '');
if (!preg_match('/^Source commit: ([a-f0-9]{40})$/mi', $body, $commitMatch) || strtolower($commitMatch[1]) !== $expectedCommit) {
    fwrite(STDERR, "Release notes source commit does not match the pin.\n"); exit(8);
}
if (!preg_match('/^Artifact SHA-256: ([a-f0-9]{64})$/mi', $body, $digestMatch)) {
    fwrite(STDERR, "Release notes do not contain a valid artifact SHA-256.\n"); exit(9);
}
$notesDigest = strtolower($digestMatch[1]);
$asset = null;
foreach (($release['assets'] ?? []) as $candidate) {
    if (($candidate['name'] ?? '') === $assetName) {
        if ($asset !== null) { fwrite(STDERR, "Release contains duplicate pinned assets.\n"); exit(10); }
        $asset = $candidate;
    }
}
if (!is_array($asset)) { fwrite(STDERR, "Pinned release asset not found.\n"); exit(11); }
$metadataDigest = strtolower((string)($asset['digest'] ?? ''));
if (str_starts_with($metadataDigest, 'sha256:')) { $metadataDigest = substr($metadataDigest, 7); }
if ($metadataDigest !== '' && (!preg_match('/^[a-f0-9]{64}$/', $metadataDigest) || !hash_equals($notesDigest, $metadataDigest))) {
    fwrite(STDERR, "Release asset metadata digest disagrees with release notes.\n"); exit(12);
}
$expected = $overrideDigest !== '' ? $overrideDigest : $notesDigest;
if (!preg_match('/^[a-f0-9]{64}$/', $expected) || ($overrideDigest !== '' && !hash_equals($notesDigest, $overrideDigest))) {
    fwrite(STDERR, "Configured SHA-256 disagrees with the published release notes.\n"); exit(13);
}
$url = (string)($asset['browser_download_url'] ?? '');
if ($url !== "https://github.com/tom3730/wp-static-secure/releases/download/{$tag}/{$assetName}") {
    fwrite(STDERR, "Unexpected release asset URL.\n"); exit(14);
}
$bytes = @file_get_contents($url, false, $ctx);
if ($bytes === false) { fwrite(STDERR, "Unable to download release asset.\n"); exit(15); }
$actual = hash('sha256', $bytes);
if (!hash_equals($expected, $actual)) {
    fwrite(STDERR, "Release SHA-256 mismatch. expected={$expected} actual={$actual}\n"); exit(16);
}
file_put_contents('/tmp/wp-static-secure.zip', $bytes, LOCK_EX);
echo "Verified {$tag} {$expectedCommit} {$assetName} sha256={$actual}\n";
