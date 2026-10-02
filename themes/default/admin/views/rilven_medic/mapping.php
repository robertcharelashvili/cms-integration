<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
// Which Rilven products each medicine used here is written off as (sma_rilven_product_link): one
// medicine bought from several suppliers is several products there, each with its own factor.
// Rilven keeps no map of this catalogue; this screen is the only place it is kept.
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$link = function ($changes) use ($show, $days, $q, $page) {
    $p = array_merge(array('show' => $show, 'days' => $days, 'q' => $q, 'page' => $page), $changes);
    return admin_url('rilven_medic/mapping') . '?' . http_build_query($p);
};
?>
<style>
    .rvm-table td { vertical-align: middle !important; }
    .rvm-mapped { color: #2e7d32; }
    .rvm-editor { background: #f7f9fc; }
    .rvm-results { max-height: 320px; overflow-y: auto; margin-top: 8px; }
    .rvm-results tr { cursor: pointer; }
    .rvm-results tr.rvm-picked td { background: #dff0d8; }
    .rvm-warn { color: #c62828; }
    .rvm-muted { color: #888; font-size: 12px; }
</style>

<div class="box">
    <div class="box-header">
        <h2 class="blue"><i class="fa-fw fa fa-link"></i> Rilven: მედიკამენტების დაკავშირება</h2>
    </div>
    <div class="box-content">
        <p class="introtext">
            მედიკამენტის ჩამოწერისას Rilven-ს ეგზავნება აქ მითითებული Rilven-ის პროდუქტი და გამოყენებული რაოდენობა ცალობით
            (ამპულა, ტაბლეტი, მლ). შეფუთვას Rilven თავად ხსნის: N10 შეფუთვიდან ერთი ამპულა ჩამოიწერება როგორც 1 ცალი.
            კოეფიციენტი საჭიროა მხოლოდ მაშინ, როცა ერთეული განსხვავდება (მაგ. CMS-ში ლიტრი, Rilven-ში მლ → 1000).
            დაუკავშირებელი მედიკამენტით შენახვა შეჩერდება.
            სულ დაკავშირებულია: <b><?= (int) $mapped_total ?></b>.
        </p>

        <form method="get" action="<?= admin_url('rilven_medic/mapping') ?>" class="form-inline" style="margin-bottom:12px">
            <select name="show" class="form-control">
                <option value="unmapped" <?= $show === 'unmapped' ? 'selected' : '' ?>>დაუკავშირებელი</option>
                <option value="mapped" <?= $show === 'mapped' ? 'selected' : '' ?>>დაკავშირებული</option>
                <option value="all" <?= $show === 'all' ? 'selected' : '' ?>>ყველა</option>
            </select>
            <label style="margin-left:10px">ბოლო</label>
            <input type="number" name="days" min="1" max="3650" value="<?= (int) $days ?>" class="form-control" style="width:90px">
            <label>დღეში გამოყენებული</label>
            <input type="text" name="q" value="<?= $e($q) ?>" placeholder="დასახელება, კოდი ან ID" class="form-control" style="width:260px;margin-left:10px">
            <button type="submit" class="btn btn-primary">ძებნა</button>
        </form>

        <p class="rvm-muted">ნაპოვნია <?= (int) $total ?> მედიკამენტი, გვერდი <?= (int) $page ?> / <?= (int) $pages ?>. დალაგებულია გამოყენების სიხშირით.</p>

        <div class="table-responsive">
            <table class="table table-bordered table-condensed table-hover rvm-table">
                <thead>
                <tr>
                    <th style="width:70px">ID</th>
                    <th>მედიკამენტი (CMS)</th>
                    <th style="width:80px">ერთეული</th>
                    <th style="width:80px">გამოყ.</th>
                    <th colspan="2">Rilven-ის პროდუქტები (× კოეფიციენტი)</th>
                    <th style="width:170px"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr id="rvm-row-<?= (int) $r->product_id ?>" data-id="<?= (int) $r->product_id ?>"
                        data-name="<?= $e($r->name) ?>" data-unit="<?= $e($r->unit) ?>">
                        <td><?= (int) $r->product_id ?></td>
                        <td><?= $e($r->name) ?><br><span class="rvm-muted"><?= $e($r->code) ?></span>
                        </td>
                        <td><?= $e($r->unit) ?></td>
                        <td><?= (int) $r->used ?><br><span class="rvm-muted"><?= $e(substr((string) $r->last_used, 0, 10)) ?></span></td>
                        <td class="rvm-target" colspan="2">
                            <?php foreach (isset($links[(int) $r->product_id]) ? $links[(int) $r->product_id] : array() as $l):
                                $k = isset($skus[(int) $l->rilven_sku_id]) ? $skus[(int) $l->rilven_sku_id] : array(); ?>
                                <div class="rvm-link" data-sku="<?= (int) $l->rilven_sku_id ?>">
                                    <span class="rvm-mapped"><i class="fa fa-check"></i>
                                        <?= $e(isset($k['name']) ? $k['name'] : ('#' . $l->rilven_sku_id)) ?></span>
                                    <span class="rvm-muted">#<?= (int) $l->rilven_sku_id ?> · <?= $e(isset($k['measure']) ? $k['measure'] : '') ?>
                                        · ×<?= $e(rtrim(rtrim((string) $l->factor, '0'), '.')) ?>
                                        <?php if (!empty($k['packSize']) && (int) $k['packSize'] > 1): ?> · შეფ. <?= (int) $k['packSize'] ?><?php endif; ?>
                                        · <?= $e($l->source) ?></span>
                                    <a href="#" class="rvm-delete" title="წაშლა"><i class="fa fa-times"></i></a>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($links[(int) $r->product_id])): ?><span class="rvm-muted rvm-none">—</span><?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-xs btn-primary rvm-edit">დამატება</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center rvm-muted">ვერაფერი მოიძებნა</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <ul class="pagination">
                <?php if ($page > 1): ?><li><a href="<?= $e($link(array('page' => $page - 1))) ?>">&laquo;</a></li><?php endif; ?>
                <?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): ?>
                    <li class="<?= $i === $page ? 'active' : '' ?>"><a href="<?= $e($link(array('page' => $i))) ?>"><?= $i ?></a></li>
                <?php endfor; ?>
                <?php if ($page < $pages): ?><li><a href="<?= $e($link(array('page' => $page + 1))) ?>">&raquo;</a></li><?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<script>
$(function () {
    var searchUrl = '<?= admin_url('rilven_medic/search') ?>';
    var saveUrl = '<?= admin_url('rilven_medic/save_map') ?>';
    var deleteUrl = '<?= admin_url('rilven_medic/delete_map') ?>';
    var csrf = {name: '<?= $this->security->get_csrf_token_name() ?>', value: '<?= $this->security->get_csrf_hash() ?>'};

    function esc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
    // the name as the CMS writes it ends in its unit twice ("... ამპულა ამპულა") and uses underscores
    function searchKey(name) {
        var s = String(name || '').replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
        var words = s.split(' ');
        while (words.length > 2 && words[words.length - 1] === words[words.length - 2]) { words.pop(); words.pop(); }
        return words.slice(0, 3).join(' ');
    }
    function post(url, data) {
        data[csrf.name] = csrf.value;
        return $.ajax({url: url, type: 'POST', dataType: 'json', data: data});
    }

    $(document).on('click', '.rvm-edit', function () {
        var row = $(this).closest('tr');
        var id = row.data('id');
        $('.rvm-editor').remove();
        var editor = $('<tr class="rvm-editor"><td colspan="7">' +
            '<div class="form-inline">' +
            '<input type="text" class="form-control rvm-key" style="width:340px" placeholder="Rilven-ის პროდუქტის დასახელება ან კოდი"> ' +
            '<button type="button" class="btn btn-default rvm-find">ძებნა</button> ' +
            '<label style="margin-left:14px">კოეფიციენტი</label> ' +
            '<input type="text" class="form-control rvm-f" style="width:90px" value="1"> ' +
            '<button type="button" class="btn btn-success rvm-save" disabled>შენახვა</button> ' +
            '<button type="button" class="btn btn-link rvm-cancel">გაუქმება</button>' +
            '<span class="rvm-msg" style="margin-left:10px"></span></div>' +
            '<div class="rvm-muted" style="margin-top:6px">კოეფიციენტი ჩვეულებრივ 1-ია: შეფუთვას Rilven თავად ხსნის. ' +
            'შეცვალეთ მხოლოდ განსხვავებული ერთეულისთვის (ჩვენი ერთეული: ' + esc(row.data('unit')) + ').</div>' +
            '<div class="rvm-results"></div></td></tr>');
        editor.data('for', id);
        row.after(editor);
        editor.find('.rvm-key').val(searchKey(row.data('name'))).focus();
        find(editor);
    });

    function find(editor) {
        var key = editor.find('.rvm-key').val().trim();
        var box = editor.find('.rvm-results');
        editor.find('.rvm-save').prop('disabled', true).removeData('sku');
        if (key.length < 2) { box.html('<span class="rvm-muted">მინიმუმ 2 სიმბოლო</span>'); return; }
        box.html('<span class="rvm-muted">იძებნება...</span>');
        $.getJSON(searchUrl, {key: key}).done(function (r) {
            if (!r.ok) { box.html('<span class="rvm-warn">' + esc(r.error) + '</span>'); return; }
            if (!r.items.length) { box.html('<span class="rvm-muted">Rilven-ში ვერ მოიძებნა — სცადეთ სხვა სიტყვა</span>'); return; }
            var unit = String($('#rvm-row-' + editor.data('for')).data('unit') || '');
            var html = '<table class="table table-condensed table-bordered"><thead><tr><th>#</th><th>დასახელება</th><th>კოდი</th><th>ერთეული</th><th>შეფუთვაში</th><th>ნაშთი (ცალი)</th></tr></thead><tbody>';
            r.items.forEach(function (s) {
                var differs = s.measure && unit && s.measure !== unit;
                html += '<tr data-sku="' + esc(s.assetSkuId) + '"><td>' + esc(s.assetSkuId) + '</td><td>' + esc(s.name) + '</td><td>' + esc(s.code) +
                    '</td><td' + (differs ? ' class="rvm-warn" title="ერთეული განსხვავდება — შეამოწმეთ კოეფიციენტი"' : '') + '>' + esc(s.measure) +
                    '</td><td>' + esc(s.packSize) + '</td><td>' + esc(s.unitsOnHand) + '</td></tr>';
            });
            box.html(html + '</tbody></table>');
        }).fail(function () { box.html('<span class="rvm-warn">მოთხოვნა ვერ შესრულდა</span>'); });
    }

    $(document).on('click', '.rvm-find', function () { find($(this).closest('.rvm-editor')); });
    $(document).on('keydown', '.rvm-key', function (ev) { if (ev.which === 13) { ev.preventDefault(); find($(this).closest('.rvm-editor')); } });
    $(document).on('click', '.rvm-cancel', function () { $(this).closest('.rvm-editor').remove(); });
    $(document).on('click', '.rvm-results tbody tr', function () {
        var editor = $(this).closest('.rvm-editor');
        editor.find('.rvm-results tr').removeClass('rvm-picked');
        $(this).addClass('rvm-picked');
        editor.find('.rvm-save').prop('disabled', false).data('sku', $(this).data('sku'));
    });

    $(document).on('click', '.rvm-save', function () {
        var btn = $(this), editor = btn.closest('.rvm-editor'), id = editor.data('for');
        btn.prop('disabled', true);
        post(saveUrl, {product_id: id, rilven_sku_id: btn.data('sku'), factor: editor.find('.rvm-f').val()}).done(function (r) {
            if (!r.ok) { editor.find('.rvm-msg').html('<span class="rvm-warn">' + esc(r.error) + '</span>'); btn.prop('disabled', false); return; }
            var row = $('#rvm-row-' + id);
            row.find('.rvm-none').remove();
            row.find('.rvm-link[data-sku="' + r.row.rilven_sku_id + '"]').remove();
            row.find('.rvm-target').append('<div class="rvm-link" data-sku="' + esc(r.row.rilven_sku_id) + '"><span class="rvm-mapped"><i class="fa fa-check"></i> '
                + esc(r.row.rilven_name) + '</span> <span class="rvm-muted">#' + esc(r.row.rilven_sku_id) + ' · ' + esc(r.row.rilven_measure)
                + ' · ×' + esc(r.row.factor) + ' · screen</span> <a href="#" class="rvm-delete" title="წაშლა"><i class="fa fa-times"></i></a></div>');
            editor.remove();
        }).fail(function () { editor.find('.rvm-msg').html('<span class="rvm-warn">შენახვა ვერ მოხერხდა</span>'); btn.prop('disabled', false); });
    });

    $(document).on('click', '.rvm-delete', function (ev) {
        ev.preventDefault();
        var link = $(this).closest('.rvm-link'), row = $(this).closest('tr'), id = row.data('id');
        post(deleteUrl, {product_id: id, rilven_sku_id: link.data('sku')}).done(function (r) {
            if (!r.ok) { return; }
            link.remove();
            if (!row.find('.rvm-link').length) { row.find('.rvm-target').append('<span class="rvm-muted rvm-none">—</span>'); }
        });
    });
});
</script>
