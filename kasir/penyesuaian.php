<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/komplain.php';
require_kasir();

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $_SESSION['flash'] = ['error', 'Sesi formulir sudah berakhir. Coba lagi.'];
    } else {
        $pid        = (int) ($_POST['id'] ?? 0);
        $potonganRp = filter_var($_POST['potongan_rp'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($potonganRp === false) {
            $_SESSION['flash'] = ['error', 'Potongan tagihan harus angka bulat, 0 atau lebih.'];
        } else {
            [$ok, $pesan] = komplain_sesuaikan_invoice($pid, (int) $_SESSION['user_id'], $potonganRp);
            $_SESSION['flash'] = [$ok ? 'success' : 'error', $pesan];
        }
    }
    header('Location: penyesuaian.php');
    exit;
}

require __DIR__ . '/../includes/admin_layout.php';

if ($id > 0) {
    $k = komplain_get($id);
    if (!$k || $k['status'] !== 'disetujui') {
        $_SESSION['flash'] = ['error', 'Komplain ini tidak sedang menunggu penyesuaian invoice.'];
        header('Location: penyesuaian.php');
        exit;
    }
    $order = order_get((int) $k['order_id']);
    $items = komplain_items_get($id);
    $nilaiDisengketakan = komplain_nilai_disengketakan($id);
    $perluKirimLagi = in_array($k['jenis_penyelesaian'], JENIS_PERLU_KIRIM_LAGI, true);

    admin_start('Penyesuaian ' . no_pesanan((int) $k['order_id']), 'penyesuaian', 'kasir');
    ?>
    <p class="breadcrumb"><a href="penyesuaian.php">Penyesuaian invoice</a> &rsaquo; <?= e(no_pesanan((int) $k['order_id'])) ?></p>
    <h1 class="page-title">Sesuaikan invoice <?= e(no_pesanan((int) $k['order_id'])) ?></h1>
    <p class="muted">Disetujui admin dengan penyelesaian <strong><?= e(label_jenis_penyelesaian($k['jenis_penyelesaian'])) ?></strong><?php if ($k['catatan_admin']): ?> &middot; <?= e($k['catatan_admin']) ?><?php endif; ?></p>

    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Produk</th><th class="num">Bermasalah</th><th>Keterangan</th><th class="num">Nilai</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['nama_produk']) ?></td>
            <td class="num"><?= angka((int) $it['qty_bermasalah']) ?> <?= e($it['satuan']) ?></td>
            <td><?= $it['keterangan'] ? e($it['keterangan']) : '<span class="muted">&mdash;</span>' ?></td>
            <td class="num"><?= rupiah((int) $it['harga'] * (int) $it['qty_bermasalah']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3" class="num"><strong>Total nilai disengketakan</strong></td><td class="num"><strong><?= rupiah($nilaiDisengketakan) ?></strong></td></tr></tfoot>
      </table>
    </div>

    <div class="panel" style="margin-top:1.2rem">
      <h2>Efek ke stok saat disimpan</h2>
      <?php if ($k['jenis_penyelesaian'] === 'retur'): ?>
        <p>Barang dianggap kembali ke gudang — stok produk di atas akan <strong>bertambah</strong> sebesar jumlah bermasalah.</p>
      <?php elseif ($perluKirimLagi): ?>
        <p>Barang pengganti/susulan akan diambil dari stok — stok produk di atas akan <strong>berkurang lagi</strong> sebesar jumlah bermasalah. Pastikan stoknya cukup.</p>
      <?php else: ?>
        <p>Barang rusak tetap di tangan customer, tidak dikirim ulang — stok <strong>tidak berubah</strong>.</p>
      <?php endif; ?>
    </div>

    <form method="post" class="pform" style="margin-top:1.2rem;max-width:480px">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="pform-main">
        <label for="potongan_rp">Potongan tagihan (Rp)</label>
        <input id="potongan_rp" name="potongan_rp" type="number" min="0" max="<?= $nilaiDisengketakan ?>" step="1" value="<?= $k['jenis_penyelesaian'] === 'kirim_susulan' || $k['jenis_penyelesaian'] === 'penggantian' ? 0 : $nilaiDisengketakan ?>" required>
        <p class="hint">Maksimal <?= rupiah($nilaiDisengketakan) ?> (nilai barang yang dikomplain). Isi 0 kalau tidak ada pengurangan tagihan (mis. barang diganti/dikirim ulang).</p>
      </div>
      <div class="pform-actions">
        <button class="btn" type="submit" onclick="return confirm('Simpan penyesuaian ini? Stok akan ikut disesuaikan dan tidak bisa dibatalkan.');">Simpan penyesuaian</button>
        <a class="btn btn-small" href="penyesuaian.php">Batal</a>
      </div>
    </form>
    <?php
    admin_end();
    exit;
}

$menunggu = db()->query(
    "SELECT k.*, o.nama_penerima FROM komplain k JOIN orders o ON o.id = k.order_id
     WHERE k.status = 'disetujui' ORDER BY k.diverifikasi_at ASC"
)->fetchAll();
$selesai = db()->query(
    "SELECT k.*, o.nama_penerima FROM komplain k JOIN orders o ON o.id = k.order_id
     WHERE k.status = 'selesai' ORDER BY k.disesuaikan_at DESC LIMIT 50"
)->fetchAll();

admin_start('Penyesuaian invoice', 'penyesuaian', 'kasir');
?>
<h1 class="page-title">Penyesuaian invoice</h1>
<p class="muted">Komplain yang sudah disetujui admin, menunggu invoice disesuaikan.</p>

<p class="count"><?= count($menunggu) ?> menunggu penyesuaian</p>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>No. PO</th><th>Customer</th><th>Jenis penyelesaian</th><th>Disetujui</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($menunggu as $k): ?>
      <tr>
        <td><strong><?= e(no_pesanan((int) $k['order_id'])) ?></strong></td>
        <td><?= e($k['nama_penerima']) ?></td>
        <td><?= e(label_jenis_penyelesaian($k['jenis_penyelesaian'])) ?></td>
        <td><?= date('d M Y, H:i', strtotime($k['diverifikasi_at'])) ?></td>
        <td><a href="penyesuaian.php?id=<?= (int) $k['id'] ?>">Sesuaikan &rarr;</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$menunggu): ?><tr><td colspan="5" class="muted center">Tidak ada yang menunggu penyesuaian.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<h2 style="margin-top:1.6rem">Riwayat penyesuaian</h2>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>No. PO</th><th>Customer</th><th>Jenis penyelesaian</th><th class="num">Potongan</th><th>Disesuaikan</th></tr></thead>
    <tbody>
    <?php foreach ($selesai as $k): ?>
      <tr>
        <td><?= e(no_pesanan((int) $k['order_id'])) ?></td>
        <td><?= e($k['nama_penerima']) ?></td>
        <td><?= e(label_jenis_penyelesaian($k['jenis_penyelesaian'])) ?></td>
        <td class="num"><?= rupiah((int) $k['potongan_rp']) ?></td>
        <td><?= date('d M Y, H:i', strtotime($k['disesuaikan_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$selesai): ?><tr><td colspan="5" class="muted center">Belum ada riwayat.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php admin_end(); ?>
