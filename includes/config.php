<?php
function app_env(string $key, string $default = ''): string
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

    $value = $localEnv[$key] ?? $default;

    return trim((string) $value, " \t\n\r\0\x0B\"'");
}

define('DB_HOST', app_env('DB_HOST', '127.0.0.1'));
define('DB_PORT', app_env('DB_PORT', '3306'));
define('DB_NAME', app_env('DB_NAME', 'sembako_vercel'));
define('DB_USER', app_env('DB_USER', 'root'));
define('DB_PASS', app_env('DB_PASS', ''));
define('DB_SSL_CA', app_env('DB_SSL_CA', ''));

const LOW_STOCK = 100;       // stok (dalam satuan_dasar, atau satuan) <= angka ini dianggap menipis

// Kategori produk (tetap 3). Urutan ini dipakai di seluruh halaman.
const KATEGORI  = ['Fresh Good', 'Dry Good', 'Lainnya'];

// Nilai bawaan saat owner membuat akun customer baru (bisa diubah per customer).
const DEFAULT_CREDIT_LIMIT = 50000000; // Rp 50 juta
const DEFAULT_TERMIN_HARI  = 30;       // 30 hari

// ============================================================================
// Konfigurasi Midtrans (Snap, tampilan pembayaran pop-up bawaan Midtrans).
// Ini yang AKTIF dipakai untuk pembayaran invoice. Kunci di bawah adalah kunci
// SANDBOX (uji coba, tanpa uang sungguhan) dari Midtrans Dashboard > Settings >
// Access Keys. Saat go-live: ganti kedua kunci dengan kunci Production dan
// ubah MIDTRANS_IS_PRODUCTION menjadi true.
//
// SERVER KEY bersifat rahasia — hanya dipakai di sisi server (PHP), jangan
// pernah dicetak ke HTML/JavaScript. CLIENT KEY memang publik (dipakai snap.js).
// ============================================================================
define(
    'MIDTRANS_SERVER_KEY',
    app_env('MIDTRANS_SERVER_KEY')
);

define(
    'MIDTRANS_CLIENT_KEY',
    app_env('MIDTRANS_CLIENT_KEY')
);

define(
    'MIDTRANS_IS_PRODUCTION',
    filter_var(
        app_env('MIDTRANS_IS_PRODUCTION', 'false'),
        FILTER_VALIDATE_BOOLEAN
    )
);

// Pilihan basis berat/volume untuk Dry Good (Fresh Good selalu 'kg', Lainnya selalu tanpa basis).
const SATUAN_DASAR_PILIHAN = ['kg' => 'Kilogram (kg)', 'liter' => 'Liter'];

// Alur status PO (Purchase Order), berurutan sesuai tahapan bisnis.
// label = teks yang tampil ke pengguna, kelas = akhiran class CSS badge (.badge.status-<kelas>).
const STATUS_PESANAN = [
    'menunggu_verifikasi' => 'Menunggu verifikasi',
    'siap_diproses'       => 'Siap diproses',
    'siap_kirim'          => 'Siap kirim',
    'dikirim'             => 'Dikirim',
    'komplain'            => 'Ada komplain',
    'diterima'            => 'Diterima',
    'selesai'             => 'Selesai',
    'dibatalkan'          => 'Dibatalkan',
];

function label_status(string $status): string
{
    return STATUS_PESANAN[$status] ?? $status;
}

// Status PEMBAYARAN dipisah dari status pesanan (kolom orders.status_pembayaran).
//   belum_ditagih       -> barang belum diterima customer, invoice belum terbit.
//   menunggu_pembayaran -> invoice terbit, ada tagihan > Rp 0 yang harus dibayar.
//   lunas               -> sudah dibayar (Midtrans, atau ditandai manual oleh staf).
//   tanpa_tagihan       -> tagihan Rp 0 (mis. disesuaikan kasir setelah komplain), tidak ada yang dibayar.
const STATUS_PEMBAYARAN = [
    'belum_ditagih'       => 'Belum ditagih',
    'menunggu_pembayaran' => 'Menunggu pembayaran',
    'lunas'               => 'Lunas',
    'tanpa_tagihan'       => 'Tidak ada tagihan',
];

function label_status_pembayaran(string $status): string
{
    return STATUS_PEMBAYARAN[$status] ?? $status;
}

// Status komplain & jenis penyelesaian yang dipilih admin saat menyetujui komplain.
const STATUS_KOMPLAIN = [
    'menunggu_verifikasi' => 'Menunggu verifikasi admin',
    'ditolak'             => 'Ditolak',
    'disetujui'           => 'Disetujui, menunggu penyesuaian invoice',
    'selesai'             => 'Selesai',
];

const JENIS_PENYELESAIAN = [
    'retur'            => 'Retur barang',
    'penggantian'      => 'Penggantian barang',
    'potongan_tagihan' => 'Potongan tagihan',
    'kirim_susulan'    => 'Pengiriman susulan',
];

// Jenis penyelesaian yang mengirim barang lagi dari stok (penggantian/susulan perlu
// barang baru diambilkan; retur/potongan tidak perlu kirim apa pun lagi).
const JENIS_PERLU_KIRIM_LAGI = ['penggantian', 'kirim_susulan'];

function label_status_komplain(string $status): string
{
    return STATUS_KOMPLAIN[$status] ?? $status;
}

function label_jenis_penyelesaian(?string $jenis): string
{
    return $jenis !== null ? (JENIS_PENYELESAIAN[$jenis] ?? $jenis) : '-';
}

/** Tanggal jatuh tempo invoice = tanggal barang diterima + masa jatuh tempo customer (hari). */
function jatuh_tempo_invoice(string $diterimaAt, ?int $terminHari): DateTimeImmutable
{
    $hari = $terminHari ?? DEFAULT_TERMIN_HARI;
    return (new DateTimeImmutable($diterimaAt))->modify('+' . $hari . ' days');
}

function invoice_lewat_tempo(string $diterimaAt, ?int $terminHari): bool
{
    return jatuh_tempo_invoice($diterimaAt, $terminHari) < new DateTimeImmutable('now');
}


if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $redisHost = trim((string) (getenv('REDIS_HOST') ?: ''));
    $redisPassword = (string) (getenv('REDIS_PASSWORD') ?: '');

    // Gunakan Redis jika konfigurasi tersedia.
    // XAMPP tetap menggunakan session file bawaan PHP.
    if ($redisHost !== '' || $redisPassword !== '') {
        $redisPort = (int) (getenv('REDIS_PORT') ?: 6379);
        $redisPrefix = (string) (getenv('REDIS_PREFIX') ?: 'sembako:session:');

        if (!extension_loaded('redis')) {
            throw new RuntimeException('Ekstensi PHP Redis belum aktif.');
        }

        if (
            $redisHost === '' ||
            $redisPassword === '' ||
            $redisPort < 1 ||
            $redisPort > 65535
        ) {
            throw new RuntimeException('Konfigurasi Redis tidak lengkap.');
        }

        $sessionSavePath = sprintf(
            'tls://%s:%d?timeout=5&read_timeout=2.5&auth=%s&prefix=%s&stream[verify_peer]=1&stream[verify_peer_name]=1',
            $redisHost,
            $redisPort,
            rawurlencode($redisPassword),
            rawurlencode($redisPrefix)
        );

        $sessionSettings = [
            'session.save_handler' => 'redis',
            'session.save_path' => $sessionSavePath,
            'session.gc_maxlifetime' => '1440',
            'redis.session.locking_enabled' => '1',
            'redis.session.lock_expire' => '60',
            'redis.session.lock_wait_time' => '20000',
            'redis.session.lock_retries' => '100',
        ];

        foreach ($sessionSettings as $setting => $value) {
            if (ini_set($setting, $value) === false) {
                throw new RuntimeException(
                    'Gagal mengatur konfigurasi session: ' . $setting
                );
            }
        }
    }

    session_start();
}


// Awalan URL relatif: halaman di folder admin/ atau kasir/ perlu naik satu tingkat.
define('ROOT', in_array(basename(dirname($_SERVER['SCRIPT_FILENAME'])), ['admin', 'kasir', 'owner'], true) ? '../' : '');

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Konfigurasi TLS untuk TiDB Cloud.
        if (DB_SSL_CA !== '') {
            if (!is_file(DB_SSL_CA)) {
                throw new RuntimeException(
                    'CA Certificate tidak ditemukan: ' . DB_SSL_CA
                );
            }

            $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } elseif (DB_HOST !== '127.0.0.1' && DB_HOST !== 'localhost') {
            throw new RuntimeException(
                'CA Certificate wajib digunakan untuk database online.'
            );
        }

        $dsn = 'mysql:host=' . DB_HOST
            . ';port=' . DB_PORT
            . ';dbname=' . DB_NAME
            . ';charset=utf8mb4';

        $pdo = new PDO(
            $dsn,
            DB_USER,
            DB_PASS,
            $options
        );
    }

    return $pdo;
}

/** Escape teks sebelum ditampilkan di HTML. */
function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function rupiah(int $angka): string
{
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

function angka(int $n): string
{
    return number_format($n, 0, ',', '.');
}

function emoji_kategori(string $kategori): string
{
    return ['Fresh Good' => '🥬', 'Dry Good' => '🌾', 'Lainnya' => '📦'][$kategori] ?? '🛒';
}

/** 'habis' | 'menipis' | 'aman' */
function status_stok(int $stok): string
{
    if ($stok <= 0) {
        return 'habis';
    }
    return $stok <= LOW_STOCK ? 'menipis' : 'aman';
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

function is_kasir(): bool
{
    return ($_SESSION['role'] ?? '') === 'kasir';
}

function is_owner(): bool
{
    return ($_SESSION['role'] ?? '') === 'owner';
}

/** Halaman utama sesuai peran. */
function home_url(): string
{
    if (is_admin()) {
        return ROOT . 'admin/index.php';
    }
    if (is_kasir()) {
        return ROOT . 'kasir/index.php';
    }
    if (is_owner()) {
        return ROOT . 'owner/index.php';
    }
    return ROOT . 'beranda.php';
}

function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['flash'] = ['info', 'Silakan masuk terlebih dahulu.'];
        header('Location: ' . ROOT . 'login.php');
        exit;
    }
}

/** Halaman khusus customer; peran staf diarahkan ke halaman kerja masing-masing. */
function require_customer(): void
{
    require_login();
    if (is_admin() || is_kasir() || is_owner()) {
        header('Location: ' . home_url());
        exit;
    }
}

/** Halaman khusus admin; peran lain diarahkan ke halaman masing-masing. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        header('Location: ' . home_url());
        exit;
    }
}

/** Halaman khusus kasir; peran lain diarahkan ke halaman masing-masing. */
function require_kasir(): void
{
    require_login();
    if (!is_kasir()) {
        header('Location: ' . home_url());
        exit;
    }
}

/** Halaman khusus owner; peran lain diarahkan ke halaman masing-masing. */
function require_owner(): void
{
    require_login();
    if (!is_owner()) {
        header('Location: ' . home_url());
        exit;
    }
}

function flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_valid(): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}

// ---- Foto produk ----
define('UPLOAD_DIR', dirname(__DIR__) . '/assets/uploads/');

/** Hapus file foto produk (hanya nama file hasil unggahan aplikasi ini). */
function hapus_file_foto(?string $nama): void
{
    if ($nama && preg_match('/^[a-f0-9]{16}\.(jpg|png|webp)$/', $nama) && is_file(UPLOAD_DIR . $nama)) {
        @unlink(UPLOAD_DIR . $nama);
    }
}


/**
 * Menghasilkan URL gambar produk.
 *
 * Mendukung dua format:
 * 1. Gambar lama: nama file di assets/uploads/
 * 2. Gambar baru: URL Cloudinary.
 */
function foto_produk_src(?string $gambar, string $legacyPrefix = 'assets/uploads/'): ?string
{
    if ($gambar === null || trim($gambar) === '') {
        return null;
    }

    $gambar = trim($gambar);

    // Gambar yang sudah menggunakan Cloudinary.
    if (preg_match('#^https://res\.cloudinary\.com/#i', $gambar)) {
        return $gambar;
    }

    // Gambar lama yang masih tersimpan secara lokal.
    return rtrim($legacyPrefix, '/') . '/' . rawurlencode(basename($gambar));
}



// ---- Aturan penjualan produk ----
// Ditentukan dari data produk, bukan cuma nama kategori, supaya Dry Good bisa
// beragam: basis kg (beras), basis liter (minyak), atau tanpa basis sama
// sekali dan hanya dijual per kemasan (kopi sachet, susu kaleng).

/** Produk ini bisa dibeli berdasarkan satuan dasarnya (kg atau liter)? */
function jual_dasar(array $p): bool
{
    return ($p['satuan_dasar'] ?? '') !== '' && $p['satuan_dasar'] !== null;
}

/** Produk ini bisa dibeli per kemasan (karung, jerigen, sachet, kaleng, ...)? */
function jual_satuan(array $p): bool
{
    return ($p['satuan'] ?? '') !== '' && $p['satuan'] !== null && $p['harga_satuan'] !== null;
}

function mode_valid(array $p, string $mode): bool
{
    return ($mode === 'dasar' && jual_dasar($p)) || ($mode === 'satuan' && jual_satuan($p));
}

/** Nama satuan stok: satuan_dasar (kg/liter) jika ada, atau nama kemasan (mis. Lainnya, atau Dry Good tanpa basis). */
function unit_stok(array $p): string
{
    return jual_dasar($p) ? (string) $p['satuan_dasar'] : (string) $p['satuan'];
}

function stok_teks(array $p): string
{
    return angka((int) $p['stok']) . ' ' . unit_stok($p);
}

/** Harga utama yang tampil di kartu/detail: per satuan dasar jika ada, kalau tidak per kemasan. */
function harga_utama(array $p): string
{
    return jual_dasar($p)
        ? rupiah((int) $p['harga']) . ' / ' . $p['satuan_dasar']
        : rupiah((int) $p['harga_satuan']) . ' / ' . $p['satuan'];
}

/** Harga alternatif per kemasan, mis. "Rp 365.000 / karung (isi 25 kg)". Hanya ada jika produk punya KEDUANYA. */
function harga_alt(array $p): ?string
{
    if (!jual_dasar($p) || !jual_satuan($p)) {
        return null;
    }
    return rupiah((int) $p['harga_satuan']) . ' / ' . $p['satuan'] . ' (isi ' . angka((int) $p['isi_dasar']) . ' ' . $p['satuan_dasar'] . ')';
}

function harga_mode(array $p, string $mode): int
{
    return $mode === 'dasar' ? (int) $p['harga'] : (int) $p['harga_satuan'];
}

function label_mode(array $p, string $mode): string
{
    return $mode === 'dasar' ? (string) $p['satuan_dasar'] : (string) $p['satuan'];
}

/** Stok (dalam satuan_dasar, atau jumlah kemasan bila tanpa basis) yang terpakai untuk membeli $qty dengan $mode. */
function kebutuhan_stok(array $p, string $mode, int $qty): int
{
    if (!jual_dasar($p)) {
        return $qty; // stok memang disimpan dalam jumlah kemasan
    }
    return $mode === 'satuan' ? $qty * (int) $p['isi_dasar'] : $qty;
}

/** Jumlah maksimal yang bisa dibeli dengan $mode dari stok saat ini. */
function maks_beli(array $p, string $mode): int
{
    $stok = (int) $p['stok'];
    if (!jual_dasar($p)) {
        return $mode === 'satuan' ? $stok : 0;
    }
    if ($mode === 'dasar') {
        return $stok;
    }
    return (int) $p['isi_dasar'] > 0 ? intdiv($stok, (int) $p['isi_dasar']) : 0;
}

function no_pesanan(int $id): string
{
    return '#' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}
