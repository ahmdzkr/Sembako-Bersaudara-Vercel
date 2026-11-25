<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/komplain.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $_SESSION['flash'] = ['error', 'Sesi formulir sudah berakhir. Coba lagi.'];
    } else {
        $pid  = (int) ($_POST['id'] ?? 0);
        $aksi = $_POST['aksi'] ?? '';
        if ($aksi === 'tolak') {
            [$ok, $pesan] = komplain_tolak($pid, (int) $_SESSION['user_id'], trim($_POST['catatan'] ?? ''));
        } elseif ($aksi === 'setujui') {
            [$ok, $pesan] = komplain_setujui($pid, (int) $_SESSION['user_id'], (string) ($_POST['jenis'] ?? ''), trim($_POST['catatan'] ?? ''));
        } elseif ($aksi === 'tandai_terkirim') {
            [$ok, $pesan] = komplain_tandai_terkirim($pid);
        } else {
            $ok = false; $pesan = 'Aksi tidak dikenal.';
        }
        $_SESSION['flash'] = [$ok ? 'success' : 'error', $pesan];
    }
    header('Location: komplain.php' . ($pid ? '?id=' . $pid : ''));
    exit;
}

require __DIR__ . '/../includes/admin_layout.php';

// ---- Detail satu komplain ----
if ($id > 0) {
    $k = komplain_get($id);
    if (!$k) {
        $_SESSION['flash'] = ['error', 'Komplain tidak ditemukan.'];
        header('Location: komplain.php');
        exit;
    }
    $order = order_get((int) $k['order_id']);
    $items = komplain_items_get($id);
    $nilaiDisengketakan = komplain_nilai_disengketakan($id);

    admin_start('Komplain ' . no_pesanan((int) $k['order_id']), 'komplain');
    ?>
    <p class="breadcrumb"><a href="komplain.php">Komplain</a> &rsaquo; <?= e(no_pesanan((int) $k['order_id'])) ?></p>
    <h1 class="page-title">Komplain <?= e(no_pesanan((int) $k['order_id'])) ?></h1>
    <p class="muted">Diajukan <?= date('d M Y, H:i', strtotime($k['created_at'])) ?> oleh <?= e($order['nama_penerima']) ?></p>

    <span class="badge status-komplain-<?= e($k['status']) ?>"><?= e(label_status_komplain($k['status'])) ?></span>

    <div class="panel" style="margin-top:1.2rem">
      <h2>Keterangan customer</h2>
      <p><?= nl2br(e($k['deskripsi'])) ?></p>
    </div>

    <div class="table-wrap" style="margin-top:1.2rem">
      <table class="table">
        <thead><tr><th>Produk</th><th class="num">Dipesan</th><th class="num">Bermasalah</th><th>Keterangan</th><th class="num">Nilai disengketakan</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['nama_produk']) ?></td>
            <td class="num"><?= angka((int) $it['qty_dipesan']) ?> <?= e($it['satuan']) ?></td>
            <td class="num"><?= angka((int) $it['qty_bermasalah']) ?> <?= e($it['satuan']) ?></td>
            <td><?= $it['keterangan'] ? e($it['keterangan']) : '<span class="muted">&mdash;</span>' ?></td>
            <td class="num"><?= rupiah((int) $it['harga'] * (int) $it['qty_bermasalah']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4" class="num"><strong>Total nilai disengketakan</strong></td><td class="num"><strong><?= rupiah($nilaiDisengketakan) ?></strong></td></tr></tfoot>
      </table>
    </div>

    <?php if ($k['status'] === 'menunggu_verifikasi'): ?>
      <div class="po-actions">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="setujui">
          <input type="hidden" name="id" value="<?= $id ?>">
          <label for="jenis">Jenis penyelesaian</label>
          <select id="jenis" name="jenis" required>
            <option value="">Pilih jenis penyelesaian</option>
            <?php foreach (JENIS_PENYELESAIAN as $kode => $label): ?>
              <option value="<?= e($kode) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="catatan" placeholder="Catatan (opsional)" class="mini-input" style="width:16rem">
          <button class="btn" type="submit">Setujui komplain</button>
        </form>
        <form method="post" class="po-reject" onsubmit="return confirm('Tolak komplain ini? Pesanan akan lanjut dianggap sesuai.');">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="tolak">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="text" name="catatan" placeholder="Alasan penolakan" class="mini-input">
          <button class="btn btn-danger" type="submit">Tolak komplain</button>
        </form>
      </div>
    <?php elseif ($k['status'] === 'disetujui'): ?>
      <p class="muted" style="margin-top:1rem">Disetujui dengan penyelesaian <strong><?= e(label_jenis_penyelesaian($k['jenis_penyelesaian'])) ?></strong>. Menunggu kasir menyesuaikan invoice.</p>
    <?php elseif ($k['status'] === 'selesai'): ?>
      <p class="muted" style="margin-top:1rem">Diselesaikan dengan penyelesaian <strong><?= e(label_jenis_penyelesaian($k['jenis_penyelesaian'])) ?></strong>, potongan tagihan <?= rupiah((int) $k['potongan_rp']) ?>.</p>
      <?php if (in_array($k['jenis_penyelesaian'], JENIS_PERLU_KIRIM_LAGI, true)): ?>
        <?php if ($k['tindak_lanjut_terkirim_at']): ?>
          <p class="muted">Barang pengganti/susulan sudah ditandai terkirim pada <?= date('d M Y, H:i', strtotime($k['tindak_lanjut_terkirim_at'])) ?>.</p>
        <?php else: ?>
          <form method="post" style="margin-top:.6rem">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="tandai_terkirim">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-small" type="submit">Tandai barang pengganti/susulan sudah dikirim</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($k['catatan_admin']): ?><p class="muted"><strong>Catatan:</strong> <?= e($k['catatan_admin']) ?></p><?php endif; ?>
    <?php
    admin_end();
    exit;
}

// ---- Daftar komplain ----
$status = $_GET['status'] ?? '';
$sql = 'SELECT k.*, o.nama_penerima FROM komplain k JOIN orders o ON o.id = k.order_id WHERE 1=1';
$par = [];
if (isset(STATUS_KOMPLAIN[$status])) {
    $sql .= ' AND k.status = ?';
    $par[] = $status;
} else {
    $status = '';
}
$sql .= ' ORDER BY k.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($par);
$daftar = $stmt->fetchAll();

$tab = ['' => 'Semua'] + STATUS_KOMPLAIN;

admin_start('Komplain', 'komplain');
?>
<h1 class="page-title">Komplain</h1>
<p class="muted">Periksa komplain barang tidak sesuai dari customer.</p>

<nav class="chips" aria-label="Filter status komplain">
  <?php foreach ($tab as $kode => $label): ?>
    <a class="chip<?= $status === $kode ? ' on' : '' ?>" href="komplain.php<?= $kode !== '' ? '?status=' . urlencode($kode) : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<p class="count"><?= count($daftar) ?> komplain</p>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>No. PO</th><th>Customer</th><th>Tanggal</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($daftar as $k): ?>
      <tr>
        <td><strong><?= e(no_pesanan((int) $k['order_id'])) ?></strong></td>
        <td><?= e($k['nama_penerima']) ?></td>
        <td><?= date('d M Y, H:i', strtotime($k['created_at'])) ?></td>
        <td><span class="badge status-komplain-<?= e($k['status']) ?>"><?= e(label_status_komplain($k['status'])) ?></span></td>
        <td><a href="komplain.php?id=<?= (int) $k['id'] ?>">Tinjau &rarr;</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$daftar): ?><tr><td colspan="5" class="muted center">Tidak ada komplain pada status ini.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php admin_end(); ?>
