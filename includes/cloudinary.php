
<?php

/**
 * Cloudinary helper untuk Sembako Bersaudara.
 * Menggunakan REST API dan PHP cURL tanpa Composer.
 */

/**
 * Mengambil environment variable.
 * Saat lokal, nilai dapat dibaca dari file .env di root project.
 * Di Vercel, gunakan Environment Variables dari dashboard.
 */
function cloudinary_env(string $key): string
{
    $value = getenv($key);

    if ($value !== false && $value !== '') {
        return trim($value);
    }

    static $localEnv = null;

    if ($localEnv === null) {
        $envFile = dirname(__DIR__) . '/.env';

        $localEnv = is_file($envFile)
            ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [])
            : [];
    }

    $value = $localEnv[$key] ?? '';

    return trim((string) $value, " \t\n\r\0\x0B\"'");
}

/**
 * Memastikan konfigurasi Cloudinary tersedia.
 */
function cloudinary_config(): array
{
    $config = [
        'cloud_name' => cloudinary_env('CLOUDINARY_CLOUD_NAME'),
        'api_key'    => cloudinary_env('CLOUDINARY_API_KEY'),
        'api_secret' => cloudinary_env('CLOUDINARY_API_SECRET'),
    ];

    foreach ($config as $key => $value) {
        if ($value === '') {
            throw new RuntimeException(
                'Konfigurasi Cloudinary belum lengkap: ' . $key
            );
        }
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('Ekstensi PHP cURL belum aktif.');
    }

    return $config;
}

/**
 * Membuat signature sesuai aturan Cloudinary.
 */
function cloudinary_signature(array $params, string $secret): string
{
    ksort($params, SORT_STRING);

    $parts = [];

    foreach ($params as $key => $value) {
        $parts[] = $key . '=' . $value;
    }

    return sha1(implode('&', $parts) . $secret);
}

/**
 * Mengirim POST request ke Cloudinary.
 */
function cloudinary_post(string $endpoint, array $fields): array
{
    $ch = curl_init($endpoint);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException(
            'Koneksi ke Cloudinary gagal: ' . $curlError
        );
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException('Respons Cloudinary tidak valid.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $data['error']['message'] ?? 'Terjadi kesalahan Cloudinary.';

        throw new RuntimeException($message);
    }

    return $data;
}

/**
 * Upload satu gambar produk.
 *
 * Mengembalikan:
 * - url       : secure URL gambar
 * - public_id : identitas gambar Cloudinary
 */
function cloudinary_upload_image(
    string $tmpPath,
    string $originalName,
    string $mimeType
): array {
    $isUploadBrowser = is_uploaded_file($tmpPath);
    $isFileCLI = PHP_SAPI === 'cli' && is_file($tmpPath);

    if (!$isUploadBrowser && !$isFileCLI) {
        throw new RuntimeException('File upload tidak valid.');
    }

    $config = cloudinary_config();

    $timestamp = time();
    $publicId = bin2hex(random_bytes(16));

    $params = [
        'folder'    => 'sembako/products',
        'public_id' => $publicId,
        'timestamp' => $timestamp,
    ];

    $signature = cloudinary_signature(
        $params,
        $config['api_secret']
    );

    $file = new CURLFile(
        $tmpPath,
        $mimeType,
        basename($originalName)
    );

    $fields = [
        'file'      => $file,
        'api_key'   => $config['api_key'],
        'timestamp' => $timestamp,
        'folder'    => $params['folder'],
        'public_id' => $publicId,
        'signature' => $signature,
    ];

    $endpoint = 'https://api.cloudinary.com/v1_1/'
        . rawurlencode($config['cloud_name'])
        . '/image/upload';

    $result = cloudinary_post($endpoint, $fields);

    if (empty($result['secure_url']) || empty($result['public_id'])) {
        throw new RuntimeException(
            'Cloudinary tidak mengembalikan URL atau public ID gambar.'
        );
    }

    return [
        'url'       => $result['secure_url'],
        'public_id' => $result['public_id'],
    ];
}

/**
 * Menghapus gambar dari Cloudinary berdasarkan public_id.
 */
function cloudinary_delete_image(?string $publicId): bool
{
    if ($publicId === null || trim($publicId) === '') {
        return true;
    }

    $config = cloudinary_config();
    $timestamp = time();

    $params = [
        'invalidate' => 'true',
        'public_id'  => $publicId,
        'timestamp'  => $timestamp,
    ];

    $signature = cloudinary_signature(
        $params,
        $config['api_secret']
    );

    $fields = [
        'api_key'    => $config['api_key'],
        'timestamp'  => $timestamp,
        'public_id'  => $publicId,
        'invalidate' => 'true',
        'signature'  => $signature,
    ];

    $endpoint = 'https://api.cloudinary.com/v1_1/'
        . rawurlencode($config['cloud_name'])
        . '/image/destroy';

    $result = cloudinary_post($endpoint, $fields);

    return in_array(
        $result['result'] ?? '',
        ['ok', 'not found'],
        true
    );
}
