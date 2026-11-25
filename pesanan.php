<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/orders.php';
require __DIR__ . '/includes/komplain.php';
require_customer();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $_SESSION['flash'] = ['error', 'Sesi formulir sudah berakhir. Coba lagi.'];
    } else {
        $aksi = $_POST['aksi'] ?? '';
        $id   = (int) ($_POST['id'] ?? 0);
        if ($aksi === 'terima') {
            [$ok, $pesan] = order_konfirmasi_diterima($id, (int) $_SESSION['user_id']);
            $_SESSION['flash'] = [$ok ? 'success' : 'error', $pesan];
        }
    }
    header('Location: pesanan.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC');
$stmt->execute([$_SESSION['user_id']]);
$pesanan = $stmt->fetchAll();

$itemsByOrder   = [];
$komplainByOrder = [];
if ($pesanan) {
    $ids = array_column($pesanan, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $qi  = db()->prepare("SELECT * FROM order_items WHERE order_id IN ($in) ORDER BY id");
    $qi->execute($ids);
    foreach ($qi->fetchAll() as $row) {
        $itemsByOrder[$row['order_id']][] = $row;
    }

    $qk = db()->prepare("SELECT * FROM komplain WHERE order_id IN ($in)");
    $qk->execute($ids);
    foreach ($qk->fetchAll() as $row) {
        $komplainByOrder[$row['order_id']] = $row;
    }
}

$halamanAktif = 'pesanan';
$judul        = 'Pesanan saya · Sembako Bersaudara';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/customer_topbar.php';
?>
<main class="wrap">
  <h1 class="page-title">Pesanan saya</h1>
  <?php if ($f = flash()): ?><div class="alert alert-<?= e($f[0]) ?>" role="status"><?= e($f[1]) ?></div><?php endif; ?>

  <?php if (!$pesanan): ?>
    <section class="empty">
      <h2>Belum ada pesanan</h2>
      <p class="muted">Pesanan yang Anda buat akan muncul di sini. <a href="beranda.php">Mulai belanja</a>.</p>
    </section>
  <?php else: ?>
    <div class="orders">
      <?php foreach ($pesanan as $o): ?>
        <?php $k = $komplainByOrder[$o['id']] ?? null; ?>
        <details class="order-card" <?= in_array($o['status'], ['menunggu_verifikasi', 'siap_diproses', 'siap_kirim', 'dikirim', 'komplain', 'diterima'], true) ? 'open' : '' ?>>
          <summary>
            <span class="order-no"><?= e(no_pesanan((int) $o['id'])) ?></span>
            <span class="order-date muted"><?= date('d M Y, H:i', strtotime($o['created_at'])) ?></span>
            <span class="badge status-<?= e($o['status']) ?>"><?= e(label_status($o['status'])) ?></span>
            <?php if ($o['status_pembayaran'] !== 'belum_ditagih'): ?>
              <span class="badge bayar-<?= e($o['status_pembayaran']) ?>">Pembayaran: <?= e(label_status_pembayaran($o['status_pembayaran'])) ?></span>
            <?php endif; ?>
            <span class="order-total"><?= rupiah((int) ($o['total_tagihan'] ?? $o['total'])) ?></span>
          </summary>

          <ol class="tracker">
            <?php
            // 'komplain' hanya ditampilkan di tracker untuk pesanan yang memang pernah dikomplain,
            // supaya pesanan normal tidak terlihat seperti ikut melalui tahap itu.
            $urutan = ['menunggu_verifikasi', 'siap_diproses', 'siap_kirim', 'dikirim'];
            if ($k) { $urutan[] = 'komplain'; }
            $urutan = array_merge($urutan, ['diterima', 'selesai']);
            $posisi = array_search($o['status'], $urutan, true);
            foreach ($urutan as $i => $u):
                $done = $o['status'] === 'dibatalkan' ? false : $i <= $posisi;
            ?>
              <li class="<?= $done ? 'done' : '' ?>"><?= e(label_status($u)) ?></li>
            <?php endforeach; ?>
          </ol>
          <?php if ($o['status'] === 'dibatalkan'): ?>
            <p class="order-alamat"><strong>PO dibatalkan.</strong><?php if ($o['catatan_kasir']): ?> Alasan: <?= e($o['catatan_kasir']) ?><?php endif; ?></p>
          <?php endif; ?>

          <?php if ($k): ?>
            <div class="komplain-box">
              <p><strong>Komplain:</strong> <span class="badge status-komplain-<?= e($k['status']) ?>"><?= e(label_status_komplain($k['status'])) ?></span></p>
              <p class="muted"><?= e($k['deskripsi']) ?></p>
              <?php if ($k['status'] === 'ditolak'): ?>
                <p class="muted">Komplain ditolak.<?php if ($k['catatan_admin']): ?> Alasan: <?= e($k['catatan_admin']) ?><?php endif; ?></p>
              <?php elseif ($k['status'] === 'disetujui'): ?>
                <p class="muted">Disetujui dengan penyelesaian <strong><?= e(label_jenis_penyelesaian($k['jenis_penyelesaian'])) ?></strong>. Menunggu kasir menyesuaikan invoice.</p>
              <?php elseif ($k['status'] === 'selesai'): ?>
                <p class="muted">Penyelesaian: <strong><?= e(label_jenis_penyelesaian($k['jenis_penyelesaian'])) ?></strong>
                  <?php if ((int) $k['potongan_rp'] > 0): ?> &middot; Potongan tagihan <?= rupiah((int) $k['potongan_rp']) ?><?php endif; ?></p>
                <?php if (in_array($k['jenis_penyelesaian'], JENIS_PERLU_KIRIM_LAGI, true)): ?>
                  <p class="muted">Status pengiriman ulang: <strong><?= $k['tindak_lanjut_terkirim_at'] ? 'Sudah dikirim (' . date('d M Y', strtotime($k['tindak_lanjut_terkirim_at'])) . ')' : 'Belum dikirim, akan segera diproses.' ?></strong></p>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <ul class="rows">
            <?php foreach ($itemsByOrder[$o['id']] ?? [] as $it): ?>
              <li><span><?= e($it['nama_produk']) ?> &times; <?= angka((int) $it['qty']) ?> <?= e($it['satuan']) ?></span><span><?= rupiah((int) $it['subtotal']) ?></span></li>
            <?php endforeach; ?>
            <?php if (empty($itemsByOrder[$o['id']])): ?><li><span class="muted">Tidak ada rincian barang.</span></li><?php endif; ?>
          </ul>
          <p class="order-alamat"><strong>Dikirim ke:</strong> <?= e($o['nama_penerima']) ?>, <?= e($o['telepon']) ?><br><?= nl2br(e($o['alamat'])) ?></p>
          <?php if ($o['catatan']): ?><p class="order-alamat"><strong>Catatan:</strong> <?= e($o['catatan']) ?></p><?php endif; ?>
          <?php if ($o['no_resi']): ?><p class="order-alamat"><strong>No. resi:</strong> <?= e($o['no_resi']) ?></p><?php endif; ?>
          <?php if ($o['status_pembayaran'] !== 'belum_ditagih' && $o['total_tagihan'] !== null && (int) $o['total_tagihan'] !== (int) $o['total']): ?>
            <p class="order-alamat"><strong>Nilai pesanan:</strong> <?= rupiah((int) $o['total']) ?> &middot;
               <strong>Setelah penyesuaian komplain:</strong> <?= rupiah((int) $o['total_tagihan']) ?></p>
          <?php endif; ?>

          <div class="order-actions">
            <?php if ($o['status'] === 'dikirim'): ?>
              <form method="post" onsubmit="return confirm('Konfirmasi barang sudah diterima dan sesuai?');">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="terima">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button class="btn" type="submit">Konfirmasi barang diterima</button>
              </form>
              <a class="btn-link" href="komplain_ajukan.php?order_id=<?= (int) $o['id'] ?>">Barang tidak sesuai? Ajukan komplain</a>
            <?php elseif ($o['status_pembayaran'] === 'menunggu_pembayaran'): ?>
              <a class="btn" href="invoice.php?id=<?= (int) $o['id'] ?>" target="_blank" rel="noopener">Lihat &amp; bayar invoice</a>
            <?php elseif (in_array($o['status_pembayaran'], ['lunas', 'tanpa_tagihan'], true)): ?>
              <a class="btn btn-small" href="invoice.php?id=<?= (int) $o['id'] ?>" target="_blank" rel="noopener">Lihat invoice</a>
            <?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
