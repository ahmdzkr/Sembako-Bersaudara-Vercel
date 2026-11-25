<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/komplain.php';
require_customer();

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$order   = order_get($orderId);
if (!$order || (int) $order['user_id'] !== (int) $_SESSION['user_id']) {
    $_SESSION['flash'] = ['error', 'Pesanan tidak ditemukan.'];
    header('Location: pesanan.php');
    exit;
}
if ($order['status'] !== 'dikirim') {
    $_SESSION['flash'] = ['info', 'Komplain hanya bisa diajukan saat pesanan berstatus dikirim.'];
    header('Location: pesanan.php');
    exit;
}

$items = order_items_get($orderId);
$err   = [];
$v     = ['deskripsi' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $err['umum'] = 'Sesi formulir sudah berakhir. Muat ulang halaman lalu coba lagi.';
    } else {
        $v['deskripsi'] = trim($_POST['deskripsi'] ?? '');
        $qtyPost = (array) ($_POST['qty'] ?? []);
        $ketPost = (array) ($_POST['keterangan'] ?? []);
        $dipilih = [];
        foreach ((array) ($_POST['pilih'] ?? []) as $oiId) {
            $dipilih[] = [
                'order_item_id' => (int) $oiId,
                'qty'           => (int) ($qtyPost[$oiId] ?? 0),
                'keterangan'    => (string) ($ketPost[$oiId] ?? ''),
            ];
        }

        [$ok, $hasil] = komplain_ajukan($orderId, (int) $_SESSION['user_id'], $v['deskripsi'], $dipilih);
        if ($ok) {
            $_SESSION['flash'] = ['success', 'Komplain berhasil diajukan. Tim kami akan memeriksanya.'];
            header('Location: pesanan.php');
            exit;
        }
        $err['umum'] = $hasil;
    }
}

function field_err(array $err, string $k): string
{
    return isset($err[$k]) ? '<p class="field-err">' . e($err[$k]) . '</p>' : '';
}

$halamanAktif = 'pesanan';
$judul        = 'Ajukan Komplain · Sembako Bersaudara';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/customer_topbar.php';
?>
<main class="wrap">
  <p class="breadcrumb"><a href="pesanan.php">Pesanan saya</a> &rsaquo; Ajukan komplain <?= e(no_pesanan($orderId)) ?></p>
  <h1 class="page-title">Barang tidak sesuai?</h1>
  <p class="muted">Pilih barang yang bermasalah, isi jumlahnya, dan jelaskan apa yang tidak sesuai. Tim kami akan memeriksa komplain ini sebelum invoice diterbitkan.</p>

  <?php if (isset($err['umum'])): ?><div class="alert alert-error" role="alert"><?= e($err['umum']) ?></div><?php endif; ?>

  <div class="profile-section komplain-form">
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="order_id" value="<?= $orderId ?>">

      <span class="lbl">Barang yang bermasalah</span>
      <div class="komplain-items">
        <?php foreach ($items as $it): ?>
          <div class="komplain-item-row">
            <label class="ki-check-label">
              <input type="checkbox" class="ki-check" data-target="row-<?= (int) $it['id'] ?>" name="pilih[]" value="<?= (int) $it['id'] ?>">
              <span class="ki-nama"><?= e($it['nama_produk']) ?></span>
              <span class="muted">(dipesan <?= angka((int) $it['qty']) ?> <?= e($it['satuan']) ?>)</span>
            </label>
            <div class="ki-detail" id="row-<?= (int) $it['id'] ?>">
              <label class="sr" for="qty-<?= (int) $it['id'] ?>">Jumlah bermasalah</label>
              <input id="qty-<?= (int) $it['id'] ?>" type="number" name="qty[<?= (int) $it['id'] ?>]" min="1" max="<?= (int) $it['qty'] ?>" placeholder="Jumlah bermasalah" disabled>
              <label class="sr" for="ket-<?= (int) $it['id'] ?>">Keterangan</label>
              <input id="ket-<?= (int) $it['id'] ?>" type="text" name="keterangan[<?= (int) $it['id'] ?>]" placeholder="Keterangan (opsional), mis. rusak / tidak lengkap" maxlength="255" disabled>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <label for="deskripsi">Jelaskan apa yang tidak sesuai</label>
      <textarea id="deskripsi" name="deskripsi" rows="4" required><?= e($v['deskripsi']) ?></textarea>
      <?= field_err($err, 'deskripsi') ?>

      <button class="btn" type="submit">Kirim komplain</button>
      <a class="btn-link" href="pesanan.php">Batal, barang sebenarnya sesuai</a>
    </form>
  </div>
</main>
<script>
document.querySelectorAll('.ki-check').forEach(function (cb) {
  cb.addEventListener('change', function () {
    var row = document.getElementById(this.dataset.target);
    row.querySelectorAll('input').forEach(function (inp) { inp.disabled = !cb.checked; });
    if (!cb.checked) { row.querySelectorAll('input').forEach(function (inp) { inp.value = ''; }); }
  });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
