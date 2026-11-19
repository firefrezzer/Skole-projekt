<?php
declare(strict_types=1);

function isValidProfileDisplayName(string $displayName): bool
{
    $length = preg_match_all('/./us', $displayName);
    return $displayName !== ''
        && $length !== false
        && $length <= 80
        && preg_match('/[\x00-\x1F\x7F]/', $displayName) === 0;
}

function readProfileGifUpload(string $fieldName): ?string
{
    $upload = $_FILES[$fieldName] ?? null;
    if (!is_array($upload)) {
        return null;
    }

    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('GIF-filen kunne ikke uploades. Prøv en mindre fil.');
    }

    $temporaryPath = $upload['tmp_name'] ?? null;
    $size = $upload['size'] ?? null;
    if (!is_string($temporaryPath) || !is_int($size) || $size < 1 || $size > 512 * 1024 ||
        !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Vælg en GIF på højst 512 KB.');
    }

    $fileHandle = fopen($temporaryPath, 'rb');
    if ($fileHandle === false) {
        throw new RuntimeException('GIF-filen kunne ikke læses.');
    }
    $signature = fread($fileHandle, 6);
    fclose($fileHandle);
    if (!in_array($signature, ['GIF87a', 'GIF89a'], true)) {
        throw new RuntimeException('Profilbilledet skal være en GIF-fil.');
    }

    $imageInfo = @getimagesize($temporaryPath);
    if (!is_array($imageInfo) || ($imageInfo[2] ?? null) !== IMAGETYPE_GIF ||
        ($imageInfo['mime'] ?? null) !== 'image/gif' ||
        ($imageInfo[0] ?? 0) > 512 || ($imageInfo[1] ?? 0) > 512) {
        throw new RuntimeException('GIF-billedet må højst være 512 × 512 pixels.');
    }

    $gifData = file_get_contents($temporaryPath);
    if ($gifData === false) {
        throw new RuntimeException('GIF-filen kunne ikke læses.');
    }
    return $gifData;
}

function profileAnimationOptions(): array
{
    return [
        'none' => 'Ingen',
        'float' => 'Svæv',
        'pulse' => 'Puls',
        'glow' => 'Glød',
    ];
}

function profileAnimationClasses(): array
{
    return [
        'none' => '',
        'float' => 'profile-float',
        'pulse' => 'profile-pulse',
        'glow' => 'profile-glow',
    ];
}

function profileBackgroundOptions(): array
{
    return [
        'blue' => 'Blå / indigo',
        'violet' => 'Violet / pink',
        'emerald' => 'Grøn / turkis',
        'sunset' => 'Solnedgang',
    ];
}

function profileBackgroundClasses(): array
{
    return [
        'blue' => 'from-blue-500 via-indigo-500 to-violet-600',
        'violet' => 'from-violet-500 via-fuchsia-500 to-pink-600',
        'emerald' => 'from-emerald-500 via-teal-500 to-cyan-600',
        'sunset' => 'from-orange-500 via-rose-500 to-fuchsia-600',
    ];
}
