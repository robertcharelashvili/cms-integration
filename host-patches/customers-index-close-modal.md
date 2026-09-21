# The period close, reached from the patient list

`/admin/rilven_sync/close` is a page of its own and nothing linked to it, so the only way in was
to type the URL. This is the hook that puts it in the CMS where the work happens.

**It is not part of the library.** Everything under `application/` drops into a CMS untouched;
this edits a file the CMS owns — `themes/default/admin/views/customers/index.php` — so it lives
here, apart, and is applied by hand per installation. Recorded because a change made to
production and written down nowhere is a change the next person discovers by accident.

## Why an iframe and not a remote modal

This theme loads modal bodies over AJAX (`data-toggle="modal" data-target="#myModal"`), and the
response has to be a fragment wearing the theme's own markup. The close deliberately is not:
the library carries no views, and the two installations it runs on are forks of one product on
different themes. A self-contained page cannot be broken by a theme change; a borrowed fragment
can. The iframe costs one element and owes the theme nothing.

The frame is filled when the modal OPENS, not with the page. The close reconciles the whole
period before it will show anything, and nobody opening the patient list should pay for that.
It is emptied again on close, so re-opening always re-reads rather than showing stale counts.

Guarded by `$Owner`, matching the controller, so nobody is offered a link that would bounce them.

Verified on this host: no `X-Frame-Options` is sent, so the frame renders; and an expired session
redirects with `window.top.location.href`, which takes the whole page to the login rather than
painting a login form inside the modal.

## The menu item

Added after `reports/user_settings_log_report` in the actions dropdown:

```php
<?php if ($Owner) { ?>
<li>
    <a href="#" id="rilvenClose">
        <i class="fa fa-lock"></i>
        <span>პერიოდის დახურვა (Rilven)</span>
    </a>
</li>
<?php } ?>
```

## The modal

Appended at the end of the same file:

```php
<?php if ($Owner) { ?>
<div class="modal fade" id="rilvenCloseModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" style="width:92%;max-width:960px">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                <h4 class="modal-title">პერიოდის დახურვა</h4>
            </div>
            <div class="modal-body" style="padding:0">
                <iframe id="rilvenCloseFrame" src="about:blank"
                        style="width:100%;height:72vh;border:0;display:block"></iframe>
            </div>
        </div>
    </div>
</div>
<script>
    $(document).on('click', '#rilvenClose', function (e) {
        e.preventDefault();
        $('#rilvenCloseFrame').attr('src', '<?= admin_url('rilven_sync/close'); ?>');
        $('#rilvenCloseModal').modal('show');
    });
    $('#rilvenCloseModal').on('hidden.bs.modal', function () {
        $('#rilvenCloseFrame').attr('src', 'about:blank');
    });
</script>
<?php } ?>
```

Applied to LJ 2026-09-21. The file it replaced is kept on the host as
`/root/customers-index-<timestamp>.php`.
