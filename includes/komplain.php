<?php
/**
 * Sistem komplain: customer bilang barang tidak sesuai saat sampai, admin
 * memverifikasi (setuju/tolak + jenis penyelesaian), lalu kasir menyesuaikan
 * invoice setelah admin menyetujui.
 *
 * Jenis penyelesaian & efeknya ke stok (dieksekusi kasir saat menyesuaikan):
 *   retur            -> barang balik ke gudang: stok produk BERTAMBAH.
 *   penggantian      -> barang baru dikirim sebagai ganti: stok produk BERKURANG lagi.
 *   kirim_susulan    -> barang yang belum terkirim dikirim menyusul: stok BERKURANG lagi.
 *   potongan_tagihan -> barang rusak tetap di customer, tidak dikirim ulang: stok TIDAK berubah.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/orders.php';

function komplain_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM komplain WHERE id = ?');
    $stmt->execute([$id]);
    $k = $stmt->fetch();
    return $k ?: null;
}

/** Komplain (kalau ada) untuk satu pesanan. Satu pesanan hanya boleh punya satu komplain. */
function komplain_get_by_order(int $orderId): ?array
{
    $stmt = db()->prepare('SELECT * FROM komplain WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $k = $stmt->fetch();
    return $k ?: null;
}

/** Rincian barang yang dikomplain, dilengkapi data order_item aslinya (nama, qty dipesan, harga, mode, satuan). */
function komplain_items_get(int $komplainId): array
{
    $stmt = db()->prepare(
        'SELECT ki.*, oi.order_id, oi.product_id, oi.nama_produk, oi.mode, oi.satuan, oi.harga,
                oi.qty AS qty_dipesan
         FROM komplain_items ki
         JOIN order_items oi ON oi.id = ki.order_item_id
         WHERE ki.komplain_id = ?
         ORDER BY ki.id'
    );
    $stmt->execute([$komplainId]);
    return $stmt->fetchAll();
}

/** Total nilai barang yang dikomplain (harga x qty_bermasalah per baris). */
function komplain_nilai_disengketakan(int $komplainId): int
{
    $nilai = 0;
    foreach (komplain_items_get($komplainId) as $it) {
        $nilai += (int) $it['harga'] * (int) $it['qty_bermasalah'];
    }
    return $nilai;
}

/**
 * Customer mengajukan komplain untuk pesanan yang berstatus 'dikirim'.
 * $items: array of ['order_item_id' => int, 'qty' => int, 'keterangan' => string]
 * Mengembalikan [berhasil, pesan_atau_id_komplain].
 */
function komplain_ajukan(int $orderId, int $userId, string $deskripsi, array $items): array
{
    $order = order_get($orderId);
    if (!$order || (int) $order['user_id'] !== $userId) {
        return [false, 'Pesanan tidak ditemukan.'];
    }
    if ($order['status'] !== 'dikirim') {
        return [false, 'Pesanan ini belum berstatus dikirim.'];
    }
    if (trim($deskripsi) === '') {
        return [false, 'Jelaskan apa yang tidak sesuai dengan pesanan ini.'];
    }

    $itemsOrder = order_items_get($orderId);
    $byId = [];
    foreach ($itemsOrder as $it) {
        $byId[(int) $it['id']] = $it;
    }

    $dipilih = [];
    foreach ($items as $row) {
        $oiId = (int) ($row['order_item_id'] ?? 0);
        $qty  = (int) ($row['qty'] ?? 0);
        if ($qty < 1) {
            continue; // baris yang tidak dicentang/diisi 0, lewati
        }
        if (!isset($byId[$oiId])) {
            return [false, 'Ada barang yang dipilih tidak termasuk pesanan ini.'];
        }
        if ($qty > (int) $byId[$oiId]['qty']) {
            return [false, 'Jumlah bermasalah untuk "' . $byId[$oiId]['nama_produk'] . '" tidak boleh melebihi jumlah yang dipesan.'];
        }
        $dipilih[] = ['order_item_id' => $oiId, 'qty' => $qty, 'keterangan' => trim((string) ($row['keterangan'] ?? ''))];
    }
    if (!$dipilih) {
        return [false, 'Pilih minimal satu barang yang bermasalah beserta jumlahnya.'];
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $q = $pdo->prepare('INSERT INTO komplain (order_id, dibuat_oleh, deskripsi) VALUES (?,?,?)');
        $q->execute([$orderId, $userId, trim($deskripsi)]);
        $komplainId = (int) $pdo->lastInsertId();

        $qi = $pdo->prepare('INSERT INTO komplain_items (komplain_id, order_item_id, qty_bermasalah, keterangan) VALUES (?,?,?,?)');
        foreach ($dipilih as $d) {
            $qi->execute([$komplainId, $d['order_item_id'], $d['qty'], $d['keterangan'] ?: null]);
        }

        $pdo->prepare("UPDATE orders SET status = 'komplain', diterima_at = NOW() WHERE id = ?")->execute([$orderId]);

        $pdo->commit();
        return [true, $komplainId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Komplain gagal disimpan. Coba lagi.'];
    }
}

/** Admin menolak komplain: pesanan lanjut normal, dianggap barang sesuai (total_tagihan = total). */
function komplain_tolak(int $komplainId, int $adminId, string $catatan): array
{
    $k = komplain_get($komplainId);
    if (!$k || $k['status'] !== 'menunggu_verifikasi') {
        return [false, 'Komplain ini sudah diproses sebelumnya.'];
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE komplain SET status = 'ditolak', catatan_admin = ?, diverifikasi_oleh = ?, diverifikasi_at = NOW() WHERE id = ?")
            ->execute([$catatan !== '' ? $catatan : null, $adminId, $komplainId]);
        $order = order_get((int) $k['order_id']);
        order_terbitkan_tagihan($pdo, (int) $order['id'], (int) $order['total']);
        $pdo->commit();
        return [true, 'Komplain ditolak. Pesanan ' . no_pesanan((int) $k['order_id']) . ' lanjut ke tahap pembayaran.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Gagal menolak komplain. Coba lagi.'];
    }
}

/**
 * Admin menyetujui komplain & menentukan jenis penyelesaian. Invoice BELUM disesuaikan
 * di sini — pesanan tetap berstatus 'komplain' sampai kasir memprosesnya.
 */
function komplain_setujui(int $komplainId, int $adminId, string $jenis, string $catatan): array
{
    $k = komplain_get($komplainId);
    if (!$k || $k['status'] !== 'menunggu_verifikasi') {
        return [false, 'Komplain ini sudah diproses sebelumnya.'];
    }
    if (!isset(JENIS_PENYELESAIAN[$jenis])) {
        return [false, 'Pilih jenis penyelesaian yang valid.'];
    }

    $q = db()->prepare("UPDATE komplain SET status = 'disetujui', jenis_penyelesaian = ?, catatan_admin = ?, diverifikasi_oleh = ?, diverifikasi_at = NOW() WHERE id = ?");
    $q->execute([$jenis, $catatan !== '' ? $catatan : null, $adminId, $komplainId]);
    return [true, 'Komplain disetujui dengan penyelesaian "' . label_jenis_penyelesaian($jenis) . '". Menunggu kasir menyesuaikan invoice.'];
}

/**
 * Kasir menyesuaikan invoice setelah admin menyetujui komplain. $potonganRp adalah
 * nilai pengurangan tagihan (0 kalau jenis penyelesaiannya tidak memotong harga).
 * Stok produk ikut disesuaikan sesuai jenis penyelesaian (lihat komentar di atas file ini).
 */
function komplain_sesuaikan_invoice(int $komplainId, int $kasirId, int $potonganRp): array
{
    $k = komplain_get($komplainId);
    if (!$k || $k['status'] !== 'disetujui') {
        return [false, 'Komplain ini belum disetujui admin, atau sudah selesai disesuaikan.'];
    }
    $order = order_get((int) $k['order_id']);
    if (!$order) {
        return [false, 'Pesanan tidak ditemukan.'];
    }

    $nilaiDisengketakan = komplain_nilai_disengketakan($komplainId);
    if ($potonganRp < 0 || $potonganRp > $nilaiDisengketakan) {
        return [false, 'Potongan tagihan harus antara Rp 0 dan ' . rupiah($nilaiDisengketakan) . ' (nilai barang yang dikomplain).'];
    }

    $items = komplain_items_get($komplainId);
    $pdo = db();
    try {
        $pdo->beginTransaction();

        foreach ($items as $it) {
            $stmtP = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmtP->execute([$it['product_id']]);
            $p = $stmtP->fetch();
            if (!$p) {
                continue; // produk sudah dihapus dari katalog; lewati penyesuaian stok untuk baris ini
            }
            $jumlahStok = kebutuhan_stok($p, $it['mode'], (int) $it['qty_bermasalah']);

            if ($k['jenis_penyelesaian'] === 'retur') {
                $pdo->prepare('UPDATE products SET stok = stok + ? WHERE id = ?')->execute([$jumlahStok, $p['id']]);
            } elseif (in_array($k['jenis_penyelesaian'], JENIS_PERLU_KIRIM_LAGI, true)) {
                $q = $pdo->prepare('UPDATE products SET stok = stok - ? WHERE id = ? AND stok >= ?');
                $q->execute([$jumlahStok, $p['id'], $jumlahStok]);
                if ($q->rowCount() !== 1) {
                    throw new RuntimeException('Stok "' . $it['nama_produk'] . '" tidak cukup untuk dikirim ulang (butuh ' . angka($jumlahStok) . ' ' . unit_stok($p) . ', sisa ' . stok_teks($p) . ').');
                }
            }
            // 'potongan_tagihan': stok tidak disentuh sama sekali.
        }

        $pdo->prepare('UPDATE komplain SET status = ?, potongan_rp = ?, disesuaikan_oleh = ?, disesuaikan_at = NOW() WHERE id = ?')
            ->execute(['selesai', $potonganRp, $kasirId, $komplainId]);

        $totalTagihan = (int) $order['total'] - $potonganRp;
        // Rp 0 = tidak ada kewajiban bayar: pesanan langsung 'selesai', pembayaran 'tanpa_tagihan',
        // customer tidak diarahkan ke Midtrans. Selain itu pesanan 'diterima' + pembayaran 'menunggu_pembayaran'.
        order_terbitkan_tagihan($pdo, (int) $order['id'], $totalTagihan);

        $pdo->commit();
        return [true, 'Invoice pesanan ' . no_pesanan((int) $order['id']) . ' disesuaikan. ' . ($totalTagihan > 0
            ? 'Total tagihan sekarang ' . rupiah($totalTagihan) . '.'
            : 'Total tagihan Rp 0: tidak ada yang perlu dibayar customer, pesanan langsung selesai.')];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, $e instanceof RuntimeException ? $e->getMessage() : 'Gagal menyesuaikan invoice. Coba lagi.'];
    }
}

/** Tandai barang pengganti/susulan sudah dikirim (untuk jenis 'penggantian'/'kirim_susulan'). */
function komplain_tandai_terkirim(int $komplainId): array
{
    $k = komplain_get($komplainId);
    if (!$k || $k['status'] !== 'selesai' || !in_array($k['jenis_penyelesaian'], JENIS_PERLU_KIRIM_LAGI, true)) {
        return [false, 'Komplain ini tidak memerlukan pengiriman lagi.'];
    }
    db()->prepare('UPDATE komplain SET tindak_lanjut_terkirim_at = NOW() WHERE id = ?')->execute([$komplainId]);
    return [true, 'Ditandai sudah dikirim.'];
}
