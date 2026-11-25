<?php
/** Fungsi bantu untuk alur PO: ambil data pesanan, dan transisi antar status. */

function order_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    $o = $stmt->fetch();
    return $o ?: null;
}

function order_items_get(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

/** Pesanan boleh dilihat oleh pemiliknya sendiri, atau oleh admin/kasir. */
function order_boleh_dilihat(array $order): bool
{
    return is_admin() || is_kasir() || ((int) $order['user_id'] === (int) ($_SESSION['user_id'] ?? 0));
}

/**
 * Kasir menyetujui PO: stok dikurangi di titik ini (bukan saat checkout), lalu
 * status berubah jadi 'siap_diproses'. Mengembalikan [berhasil, pesan].
 */
function po_approve(int $orderId, int $kasirId): array
{
    $order = order_get($orderId);
    if (!$order) {
        return [false, 'Pesanan tidak ditemukan.'];
    }
    if ($order['status'] !== 'menunggu_verifikasi') {
        return [false, 'Pesanan ini sudah diproses sebelumnya.'];
    }

    $items = order_items_get($orderId);
    if (!$items) {
        return [false, 'Pesanan ini tidak punya rincian barang, tidak bisa diverifikasi.'];
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        foreach ($items as $it) {
            if ($it['product_id'] === null) {
                throw new RuntimeException('Produk "' . $it['nama_produk'] . '" sudah dihapus dari katalog, PO tidak bisa diproses.');
            }
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$it['product_id']]);
            $p = $stmt->fetch();
            if (!$p) {
                throw new RuntimeException('Produk "' . $it['nama_produk'] . '" sudah dihapus dari katalog, PO tidak bisa diproses.');
            }

            $butuh = kebutuhan_stok($p, $it['mode'], (int) $it['qty']);
            $q = $pdo->prepare('UPDATE products SET stok = stok - ? WHERE id = ? AND stok >= ?');
            $q->execute([$butuh, $p['id'], $butuh]);
            if ($q->rowCount() !== 1) {
                throw new RuntimeException('Stok "' . $it['nama_produk'] . '" tidak lagi mencukupi (sisa ' . stok_teks($p) . '). PO tidak bisa disetujui.');
            }
        }

        $q = $pdo->prepare("UPDATE orders SET status = 'siap_diproses', diverifikasi_oleh = ?, verifikasi_at = NOW() WHERE id = ?");
        $q->execute([$kasirId, $orderId]);

        $pdo->commit();
        return [true, 'PO ' . no_pesanan($orderId) . ' disetujui. Stok sudah dikurangi dan pesanan siap diproses admin.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, $e instanceof RuntimeException ? $e->getMessage() : 'PO gagal disetujui. Coba lagi.'];
    }
}

/** Kasir menolak PO (mis. dokumen/keuangan tidak valid). Stok tidak tersentuh. */
function po_reject(int $orderId, int $kasirId, string $alasan): array
{
    $order = order_get($orderId);
    if (!$order) {
        return [false, 'Pesanan tidak ditemukan.'];
    }
    if ($order['status'] !== 'menunggu_verifikasi') {
        return [false, 'Pesanan ini sudah diproses sebelumnya.'];
    }

    $q = db()->prepare("UPDATE orders SET status = 'dibatalkan', diverifikasi_oleh = ?, verifikasi_at = NOW(), catatan_kasir = ? WHERE id = ?");
    $q->execute([$kasirId, $alasan !== '' ? $alasan : null, $orderId]);
    return [true, 'PO ' . no_pesanan($orderId) . ' ditolak.'];
}

/** Admin menandai barang sudah disiapkan & siap dikirim (boleh sertakan no. resi). */
function order_tandai_siap_kirim(int $orderId, ?string $noResi): array
{
    $order = order_get($orderId);
    if (!$order || $order['status'] !== 'siap_diproses') {
        return [false, 'Pesanan tidak dalam status yang bisa ditandai siap kirim.'];
    }
    $q = db()->prepare('UPDATE orders SET status = ?, no_resi = ? WHERE id = ?');
    $q->execute(['siap_kirim', $noResi !== '' ? $noResi : null, $orderId]);
    return [true, 'Pesanan ' . no_pesanan($orderId) . ' ditandai siap kirim.'];
}

/** Admin menandai barang sudah berangkat/dalam perjalanan. */
function order_tandai_dikirim(int $orderId): array
{
    $order = order_get($orderId);
    if (!$order || $order['status'] !== 'siap_kirim') {
        return [false, 'Pesanan tidak dalam status yang bisa ditandai dikirim.'];
    }
    $q = db()->prepare("UPDATE orders SET status = 'dikirim', dikirim_at = NOW() WHERE id = ?");
    $q->execute([$orderId]);
    return [true, 'Pesanan ' . no_pesanan($orderId) . ' ditandai dikirim.'];
}

/**
 * Customer mengonfirmasi barang sudah diterima & sesuai. Ini menerbitkan tagihan:
 * status pesanan jadi "diterima" dan status pembayaran "menunggu pembayaran" — sesi pembayaran Midtrans (Snap token) baru dibuat saat
 * customer klik "Bayar sekarang" di invoice.php (lihat includes/midtrans.php::
 * midtrans_buat_token), bukan di sini, supaya konfirmasi terima barang tidak
 * gagal hanya karena Midtrans sedang bermasalah.
 */
function order_konfirmasi_diterima(int $orderId, int $userId): array
{
    $order = order_get($orderId);
    if (!$order || (int) $order['user_id'] !== $userId) {
        return [false, 'Pesanan tidak ditemukan.'];
    }
    if ($order['status'] !== 'dikirim') {
        return [false, 'Pesanan ini belum berstatus dikirim.'];
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare("UPDATE orders SET diterima_at = NOW() WHERE id = ? AND status = 'dikirim'");
        $q->execute([$orderId]);
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('Pesanan ini sudah dikonfirmasi sebelumnya.');
        }
        $tagihan = $order['total_tagihan'] !== null ? (int) $order['total_tagihan'] : (int) $order['total'];
        order_terbitkan_tagihan($pdo, $orderId, $tagihan);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, $e instanceof RuntimeException ? $e->getMessage() : 'Konfirmasi gagal disimpan. Coba lagi.'];
    }
    return [true, 'Barang pesanan ' . no_pesanan($orderId) . ' dikonfirmasi diterima. ' . ($tagihan > 0
        ? 'Invoice sudah terbit dan menunggu pembayaran.'
        : 'Tidak ada tagihan, pesanan langsung selesai.')];
}

/** Staf menandai lunas secara manual (mis. customer membayar di luar gateway pembayaran). */
function order_tandai_lunas_staf(int $orderId): array
{
    $q = db()->prepare("UPDATE orders SET status = 'selesai', status_pembayaran = 'lunas', dibayar_at = NOW(), metode_bayar = 'Manual oleh staf' WHERE id = ? AND status_pembayaran = 'menunggu_pembayaran'");
    $q->execute([$orderId]);
    if ($q->rowCount() !== 1) {
        return [false, 'Pesanan ini tidak sedang menunggu pembayaran.'];
    }
    return [true, 'Pesanan ' . no_pesanan($orderId) . ' ditandai lunas secara manual.'];
}


/** Tanggal pendek berbahasa Indonesia, mis. "11 Nov 2026" (bulan: Jan Feb Mar Apr Mei Jun Jul Agu Sep Okt Nov Des). */
function tanggal_pendek(string $waktu): string
{
    static $bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($waktu);
    return date('d', $t) . ' ' . $bulan[(int) date('n', $t)] . ' ' . date('Y', $t);
}

/**
 * Nomor & tanggal surat jalan sebuah pesanan. Nomor dibuat SEKALI saat surat jalan pertama kali
 * dibuka (format SJ/<tahun>/<urut 4 digit>, urutan mulai dari 0001 tiap tahun) lalu tetap sama
 * pada pembukaan berikutnya. Mengembalikan ['no_surat_jalan' => ..., 'surat_jalan_at' => ...]
 * atau null kalau pesanan tidak ada / nomor gagal dibuat.
 */
function surat_jalan_siapkan(int $orderId): ?array
{
    $pdo = db();
    $baca = $pdo->prepare('SELECT no_surat_jalan, surat_jalan_at FROM orders WHERE id = ?');
    $baca->execute([$orderId]);
    $row = $baca->fetch();
    if (!$row) {
        return null;
    }
    if ($row['no_surat_jalan'] !== null) {
        return $row;
    }

    // Dua admin yang membuat surat jalan bersamaan bisa menghitung nomor yang sama; kunci UNIQUE
    // di kolom no_surat_jalan menolak yang kedua, lalu kita hitung ulang (maksimal 10 kali).
    for ($coba = 0; $coba < 10; $coba++) {
        $tahun = (int) $pdo->query('SELECT YEAR(NOW())')->fetchColumn();
        $q = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(no_surat_jalan, '/', -1) AS UNSIGNED)), 0) FROM orders WHERE no_surat_jalan LIKE ?");
        $q->execute(['SJ/' . $tahun . '/%']);
        $nomor = sprintf('SJ/%d/%04d', $tahun, (int) $q->fetchColumn() + 1);
        try {
            $u = $pdo->prepare('UPDATE orders SET no_surat_jalan = ?, surat_jalan_at = NOW() WHERE id = ? AND no_surat_jalan IS NULL');
            $u->execute([$nomor, $orderId]);
            break;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e; // bukan bentrok nomor, lempar lagi
            }
        }
    }

    $baca->execute([$orderId]);
    $row = $baca->fetch();
    return ($row && $row['no_surat_jalan'] !== null) ? $row : null;
}


/**
 * Terbitkan tagihan setelah barang diterima (sesuai), komplain ditolak, atau invoice disesuaikan kasir.
 * Status PESANAN dan status PEMBAYARAN dipisah:
 *   tagihan > 0 : pesanan 'diterima'  + pembayaran 'menunggu_pembayaran' (customer membayar lewat Midtrans).
 *   tagihan = 0 : pesanan 'selesai'   + pembayaran 'tanpa_tagihan'       (tidak ada yang dibayar, Midtrans tidak dipakai).
 * Dipanggil di dalam transaksi milik pemanggil ($pdo yang sama).
 */
function order_terbitkan_tagihan(PDO $pdo, int $orderId, int $totalTagihan): void
{
    if ($totalTagihan > 0) {
        $pdo->prepare("UPDATE orders SET status = 'diterima', status_pembayaran = 'menunggu_pembayaran', total_tagihan = ? WHERE id = ?")
            ->execute([$totalTagihan, $orderId]);
    } else {
        $pdo->prepare("UPDATE orders SET status = 'selesai', status_pembayaran = 'tanpa_tagihan', total_tagihan = 0 WHERE id = ?")
            ->execute([$orderId]);
    }
}
