<?php

declare(strict_types=1);

$tag = getenv('WPS_RELEASE_TAG') ?: '';
$assetName = getenv('WPS_RELEASE_ASSET') ?: '';
$overrideDigest = strtolower(getenv('WPS_RELEASE_SHA256') ?: '');
if (!preg_match('/^v[0-9A-Za-z.-]+$/', $tag) || $assetName !== 'wp-static-secure.zip') {
    fwrite(STDERR, "Invalid release pin.\n"); exit(2);
}
$ctx = stream_context_create(['http' => ['header' => "User-Agent: wp-static-secure-acceptance\r\nAccept: application/vnd.github+json\r\n", 'timeout' => 30]]);
$api = "https://api.github.com/repos/tom3730/wp-static-secure/releases/tags/" . rawurlencode($tag);
$json = @file_get_contents($api, false, $ctx);
if ($json === false) { fwrite(STDERR, "Unable to load GitHub release metadata.\n"); exit(3); }
$release = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
if (($release['draft'] ?? true) || !($release['prerelease'] ?? false) || ($release['tag_name'] ?? '') !== $tag) {
    fwrite(STDERR, "Release metadata does not match the pinned published pre-release.\n"); exit(4);
}
$asset = null;
foreach (($release['assets'] ?? []) as $candidate) {
    if (($candidate['name'] ?? '') === $assetName) { $asset = $candidate; break; }
}
if (!is_array($asset)) { fwrite(STDERR, "Pinned release asset not found.\n"); exit(5); }
$metadataDigest = strtolower((string)($asset['digest'] ?? ''));
if (str_starts_with($metadataDigest, 'sha256:')) { $metadataDigest = substr($metadataDigest, 7); }
$expected = $overrideDigest !== '' ? $overrideDigest : $metadataDigest;
if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
    fwrite(STDERR, "No valid expected SHA-256 is available. Set WPS_RELEASE_SHA256 if GitHub metadata has no digest.\n"); exit(6);
}
$url = (string)($asset['browser_download_url'] ?? '');
if (!str_starts_with($url, "https://github.com/tom3730/wp-static-secure/releases/download/{$tag}/")) {
    fwrite(STDERR, "Unexpected release asset URL.\n"); exit(7);
}
$bytes = @file_get_contents($url, false, $ctx);
if ($bytes === false) { fwrite(STDERR, "Unable to download release asset.\n"); exit(8); }
$actual = hash('sha256', $bytes);
if (!hash_equals($expected, $actual)) {
    fwrite(STDERR, "Release SHA-256 mismatch. expected={$expected} actual={$actual}\n"); exit(9);
}
file_put_contents('/tmp/wp-static-secure.zip', $bytes, LOCK_EX);
echo "Verified {$tag} {$assetName} sha256={$actual}\n";
