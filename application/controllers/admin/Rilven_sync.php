<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The cron entry point for the Rilven sync, a status page, and a doctor.
 *
 * Run from cron as CLI rather than over HTTP:
 *
 *   * * * * *  cd /home/www/public && php index.php admin/rilven_sync cron >> /var/log/rilven-sync.log 2>&1
 *
 * CLI because an HTTP cron needs a URL that works without a session, and a URL that works
 * without a session is a URL anybody can call. Over the browser the same controller
 * requires an owner, exactly like every other admin screen.
 *
 * Commands:
 *   cron      backfill, then send. What the crontab runs.
 *   check     the doctor: config, columns, credentials, reference ids, both routes.
 *   backfill  top the queue up from the register and send nothing.
 *   retry     re-open rows that were given up on, after fixing what refused them.
 *             Takes an optional register: retry service
 *   resend    re-queue everything, delivered rows included. Takes "yes" and an optional register.
 *   close     post what a period still holds as a draft, in the order the legs depend on.
 *             Dry unless told otherwise: close 2026-09-01 2026-09-30 yes
 *   index     JSON: what is waiting, what failed and why. Also the browser page.
 */
class Rilven_sync extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();

        if (!is_cli()) {
            if (!$this->loggedIn) {
                $this->session->set_userdata('requested_page', $this->uri->uri_string());
                $this->sma->md('login');
            }
            if (!$this->Owner) {
                $this->session->set_flashdata('warning', lang('access_denied'));
                admin_redirect('welcome');
            }
        }

        $this->load->library('rilven');
    }

    /**
     * One run: top the queue up from the register, then send what is in it.
     *
     * Backfill first so a brand-new installation does something useful on its very first
     * tick instead of waiting for somebody to edit a patient. Once the catch-up is done it
     * finds nothing and costs one indexed query.
     */
    public function cron()
    {
        $started = microtime(TRUE);

        $queued  = $this->rilven->backfill();
        $summary = $this->rilven->push();

        $line = sprintf(
            '[%s] rilven: queued=%d claimed=%d created=%d updated=%d unchanged=%d rejected=%d retrying=%d%s in %.1fs',
            date('Y-m-d H:i:s'), $queued, $summary['claimed'], $summary['created'],
            $summary['updated'], $summary['unchanged'], $summary['rejected'], $summary['retrying'],
            $summary['stopped'] !== '' ? ' STOPPED: ' . $summary['stopped'] : '',
            microtime(TRUE) - $started
        );

        $this->say($line);

        // Per register, because they fail for different reasons and an aggregate hides it: the
        // categories being stuck is what makes every service answer "category-not-synced-yet",
        // and in one total those two look like one problem with the services.
        if (isset($summary['entities'])) {
            foreach ($summary['entities'] as $entity => $one) {
                if ($one['claimed'] === 0 && $one['stopped'] === '') {
                    continue;
                }
                $this->say(sprintf(
                    '    %-10s claimed=%d created=%d updated=%d unchanged=%d rejected=%d retrying=%d%s',
                    $entity, $one['claimed'], $one['created'], $one['updated'],
                    $one['unchanged'], $one['rejected'], $one['retrying'],
                    $one['stopped'] !== '' ? ' STOPPED: ' . $one['stopped'] : ''
                ));
            }
        }

        // The refusals, grouped. CI logging is switched off on these installations, so
        // this is the log that exists -- and a count alone looks like a quiet day right up
        // until every row is being refused for one fixable reason.
        foreach ($this->rilven->refusals as $reason => $seen) {
            $this->say(sprintf('    %5d x %s (first: %s)', $seen['count'], $reason, $seen['first']));
        }

        if (!is_cli()) {
            $this->output->set_content_type('application/json')->set_output(json_encode(array(
                'status' => 'ok', 'queued' => $queued, 'summary' => $summary,
                'refusals' => $this->rilven->refusals,
            ), JSON_UNESCAPED_UNICODE));
        }
    }

    /** Top the queue up and send nothing, for a first look at how much there is. */
    public function backfill()
    {
        $limit  = (int) $this->input->get('limit');
        $queued = $this->rilven->backfill($limit > 0 ? $limit : 2000);
        $this->say(sprintf('[%s] rilven: queued %d', date('Y-m-d H:i:s'), $queued));
    }

    /**
     * Re-open what was given up on, once the reason it was refused has been fixed.
     *
     * With a register named -- category, service or contractor -- only that one, which is what
     * is wanted after fixing something that refused exactly one of them:
     *
     *     php index.php admin/rilven_sync retry service
     */
    public function retry($entity = '')
    {
        $entity = $this->readEntity($entity);
        if ($entity === FALSE) {
            return;
        }
        $reopened = $this->rilven->retry(TRUE, $entity);
        $this->say(sprintf('[%s] rilven: re-opened %d row(s)%s', date('Y-m-d H:i:s'),
            $reopened, $entity === NULL ? '' : ' in ' . $entity));
    }

    /**
     * Send every patient again, including the ones the outbox calls delivered.
     *
     * Wanted when Rilven no longer holds what this queue says it does. It costs one request
     * per patient and this register holds seventy thousand of them, so it asks to be meant:
     *
     *     php index.php admin/rilven_sync resend yes
     */
    public function resend($confirm = '', $entity = '')
    {
        $entity = $this->readEntity($entity);
        if ($entity === FALSE) {
            return;
        }

        if ($confirm !== 'yes') {
            $this->say('This re-queues EVERY row, including the ones already sent.');
            $this->say('It is for one case: Rilven no longer has what this outbox says it has.');
            $this->say('If that is the case, say so: php index.php admin/rilven_sync resend yes');
            $this->say('One register only:      php index.php admin/rilven_sync resend yes service');
            return;
        }
        $this->say(sprintf('[%s] rilven: re-opened %d row(s)%s for sending',
            date('Y-m-d H:i:s'), $this->rilven->resend($entity),
            $entity === NULL ? '' : ' in ' . $entity));
    }

    /**
     * Close a period: post every document in it that Rilven still holds as a draft.
     *
     * From the terminal:
     *
     *     php index.php admin/rilven_sync close 2026-09-01 2026-09-30        counts, calls nothing
     *     php index.php admin/rilven_sync close 2026-09-01 2026-09-30 yes    posts
     *
     * From the browser, signed in as an owner like every other admin screen:
     *
     *     /admin/rilven_sync/close                    the form
     *     /admin/rilven_sync/close/2026-09-01/2026-09-30   the counts
     *
     * **A GET never posts, whatever the URL says.** The browser route confirms only on POST, and
     * only with the one-shot token the dry run put in the session. CSRF protection is off across
     * this installation -- `csrf_protection = false` in config.php -- so a link alone is not
     * enough of a guard: an <img> in an e-mail would be a request this controller honours, and
     * the request in question writes entries into a closed month. The token also makes the
     * workflow the right one round: the numbers have to be looked at before they can be posted.
     *
     * Dry by default, and the dry run is not a formality -- it is the point of the command. It
     * shows, leg by leg, what would be posted and which legs are switched off, and a period
     * closed with a leg switched off is a half-closed period that looks finished.
     *
     * It can run while the cron does -- it only confirms, and never inserts -- with ONE thing
     * worth knowing. An edit arriving for a case just confirmed makes the sale register unpost
     * it, write the change, and then ask `shouldPost()` whether to confirm it again. While
     * `rilven_sale_post` is FALSE the answer is no, so that case quietly goes back to being a
     * draft in a period already called closed. Until that switch is on, re-run the dry close
     * after the month's last edits and look at the count.
     *
     * Deliberately NOT part of `cron`. The cron keeps Rilven's documents matching the CMS; what
     * a closed period is, is somebody deciding the month is done. That is a decision and not a
     * schedule, and it is nearly impossible to take back: unconfirming removes entries, and a
     * period Rilven itself has closed refuses even that.
     */
    public function close($from = '', $to = '', $confirm = '')
    {
        if (is_cli()) {
            $this->closeCli($from, $to, $confirm);
            return;
        }

        $posting = ($this->input->method(TRUE) === 'POST');
        if ($posting) {
            // Only from the form: the segments are ignored on a POST so a crafted URL cannot
            // widen the period the token was issued for.
            $from = (string) $this->input->post('from');
            $to   = (string) $this->input->post('to');
        } else {
            $from = $from !== '' ? $from : (string) $this->input->get('from');
            $to   = $to   !== '' ? $to   : (string) $this->input->get('to');
        }

        if ($from === '' || $to === '') {
            $this->closePage(NULL, $from, $to, '');
            return;
        }

        $warning = '';
        if ($posting) {
            $given  = (string) $this->input->post('token');
            $issued = (string) $this->session->userdata('rilven_close_token');
            // One shot. Burned whether it matched or not, so a guessed token cannot be tried
            // twice and a page left open cannot be submitted a second time by accident.
            $this->session->unset_userdata('rilven_close_token');

            if ($given === '' || $issued === '' || !hash_equals($issued, $given)) {
                $posting = FALSE;
                $warning = 'Ничего не проведено: подтверждение устарело.'
                         . ' Посмотрите цифры заново и нажмите ещё раз.';
            }
        }

        $result = $this->rilven->closePeriod($from, $to, $posting);
        $this->closePage($result, $from, $to, $warning);
    }

    /** The terminal form of {@see close}, where "yes" is the whole confirmation. */
    private function closeCli($from, $to, $confirm)
    {
        if ($from === '' || $to === '') {
            $this->say('A period is two dates:');
            $this->say('  php index.php admin/rilven_sync close 2026-09-01 2026-09-30');
            $this->say('That counts what would be posted and calls nothing. To post, add yes:');
            $this->say('  php index.php admin/rilven_sync close 2026-09-01 2026-09-30 yes');
            return;
        }

        $posting = ($confirm === 'yes');
        $result  = $this->rilven->closePeriod($from, $to, $posting);

        if ($result['error'] !== '') {
            $this->say('rilven close: ' . $result['error']);
            return;
        }

        $this->say(sprintf('[%s] rilven close %s .. %s -- %s', date('Y-m-d H:i:s'),
            $result['from'], $result['to'],
            $result['blocked'] !== '' ? 'REFUSED' : ($posting ? 'POSTING' : 'dry run, nothing was called')));

        $this->sayReconciliation($result['reconcile'], $result['blocked']);

        $off = array();
        foreach ($result['legs'] as $entity => $leg) {
            if (!$leg['enabled']) {
                $off[] = $entity;
                $this->say(sprintf('    %-10s %-12s %-16s DISABLED',
                    $entity, $leg['pair'], $leg['what']));
                continue;
            }
            $this->say(sprintf('    %-10s %-12s %-16s drafts=%d posted=%d failed=%d%s',
                $entity, $leg['pair'], $leg['what'],
                $leg['drafts'], $leg['posted'], $leg['failed'],
                $leg['held'] ? '  HELD: the period does not reconcile' : ''));

            foreach ($leg['errors'] as $reason => $seen) {
                $this->say(sprintf('        %5d x %s (first: %s)',
                    $seen['count'], $reason, $seen['first']));
            }
        }

        $this->say(sprintf('    TOTAL      drafts=%d posted=%d failed=%d',
            $result['drafts'], $result['posted'], $result['failed']));

        // Said last, where it is read, and said whether this was a dry run or not: a leg that is
        // switched off contributes nothing and reports nothing, so the only sign of it is this.
        if ($off) {
            $this->say('    NOTE: ' . implode(', ', $off) . ' did not run. The period is not'
                     . ' fully closed until every leg is switched on.');
        }

        if ($result['stopped'] !== '') {
            $this->say('    STOPPED: ' . $result['stopped']);
            $this->say('    The rest of the period was not touched. Safe to run again once the'
                     . ' reason is gone -- what is posted is not selected a second time.');
        }

        if ($result['blocked'] !== '') {
            $this->say('    The legs that read the case were HELD. The receipt was not: the money'
                     . ' arrived whatever the case says. Fix the cases above and run this again.');
        }

        if (!$posting && $result['drafts'] > 0) {
            $this->say('    To post these: php index.php admin/rilven_sync close '
                     . $result['from'] . ' ' . $result['to'] . ' yes');
        }
    }

    /** The three-way check, in the terminal. */
    private function sayReconciliation($check, $blocked)
    {
        if ($check === NULL) {
            return;
        }

        $this->say(sprintf('    reconciliation: %d case(s)  services=%.2f header=%.2f accrual=%.2f',
            $check['cases'], $check['items'], $check['header'], $check['accrued']));

        if ($blocked === '') {
            $this->say('    the three agree.');
            return;
        }

        $this->say('    DOES NOT RECONCILE: ' . $blocked);

        foreach ($check['rows'] as $row) {
            $this->say(sprintf('        case %-8s %s  services=%.2f header=%.2f accrual=%.2f',
                $row->id, substr((string) $row->d, 0, 10),
                $row->items, $row->header, $row->accrued));
        }
        if ($check['orphans'] > 0) {
            $this->say('        lines outside the item scope, in case(s): '
                     . implode(', ', $check['orphanCases']));
        }
        $this->say('    A case is fixed IN THE CASE, not with an UPDATE.');
    }

    /**
     * The browser page: a period, what it holds, and one button.
     *
     * Written inline rather than as a theme view. This controller ships as a drop-in library
     * with no views of its own, and the two installations it runs on are forks of the same
     * product that have drifted for years -- a view file would have to be placed by hand in
     * whichever theme each one happens to be using, and would be the thing that breaks when a
     * theme is changed. A page with no dependencies cannot.
     */
    private function closePage($result, $from, $to, $warning)
    {
        $token = '';
        $free = 0;
        if ($result !== NULL && $result['error'] === '') {
            foreach ($result['legs'] as $leg) {
                if (!$leg['held']) { $free += $leg['drafts']; }
            }
        }
        if ($result !== NULL && $result['error'] === '' && !$result['confirmed'] && $free > 0) {
            // Issued only when there is something to post, so the button and the token appear
            // and disappear together.
            $token = bin2hex(random_bytes(16));
            $this->session->set_userdata('rilven_close_token', $token);
        }

        $h = function ($value) {
            return html_escape((string) $value);
        };

        $action = site_url('admin/rilven_sync/close');
        $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
              . '<meta name="viewport" content="width=device-width, initial-scale=1">'
              . '<title>Закрытие периода — Rilven</title><style>'
              . 'body{font:14px/1.5 system-ui,sans-serif;margin:0;padding:24px;background:#f6f7f9;color:#1f2328}'
              . '.card{max-width:820px;margin:0 auto 16px;background:#fff;border:1px solid #d8dbdf;'
              . 'border-radius:8px;padding:20px}'
              . 'h1{font-size:19px;margin:0 0 4px}.sub{color:#5a6169;margin:0 0 18px}'
              . 'label{display:inline-block;margin-right:14px}'
              . 'input[type=date]{font:inherit;padding:6px 8px;border:1px solid #c3c8ce;border-radius:5px}'
              . 'button{font:inherit;padding:7px 15px;border-radius:5px;border:1px solid #c3c8ce;'
              . 'background:#fff;cursor:pointer}'
              . 'button.go{background:#b42318;border-color:#b42318;color:#fff}'
              . 'table{border-collapse:collapse;width:100%;margin-top:6px}'
              . 'th,td{text-align:left;padding:7px 10px;border-bottom:1px solid #e6e8ea}'
              . 'th{font-weight:600;color:#5a6169;font-size:12px;text-transform:uppercase}'
              . 'td.n{text-align:right;font-variant-numeric:tabular-nums}'
              . 'tr.total td{font-weight:600;border-top:2px solid #d8dbdf;border-bottom:none}'
              . '.off{color:#9aa0a6}.bad{color:#b42318}.msg{padding:10px 14px;border-radius:6px;'
              . 'margin-bottom:16px}.msg.warn{background:#fff4e5;border:1px solid #f0c07a}'
              . '.msg.done{background:#e7f6ec;border:1px solid #8fce9f}'
              . '.reasons{margin:4px 0 0 0;padding-left:18px;color:#b42318;font-size:13px}'
              . '</style></head><body><div class="card">'
              . '<h1>Закрытие периода</h1>'
              . '<p class="sub">Проводит в Rilven всё, что за период осталось черновиком.'
              . ' Сначала показывает, что будет проведено, и ничего не вызывает.</p>';

        if ($warning !== '') {
            $html .= '<div class="msg warn">' . $h($warning) . '</div>';
        }

        $html .= '<form method="get" action="' . $h($action) . '">'
               . '<label>с <input type="date" name="from" value="' . $h($from) . '" required></label>'
               . '<label>по <input type="date" name="to" value="' . $h($to) . '" required></label>'
               . '<button type="submit">Посмотреть</button></form>';

        if ($result !== NULL && $result['error'] !== '') {
            $html .= '<p class="bad">' . $h($result['error']) . '</p>';
        }

        if ($result !== NULL && $result['error'] === '') {
            if ($result['confirmed']) {
                $html .= '<div class="msg done">Проведено: ' . (int) $result['posted']
                       . '. Не удалось: ' . (int) $result['failed'] . '.</div>';
            }

            $check = $result['reconcile'];
            if ($check !== NULL) {
                $html .= '<h2 style="font-size:15px;margin:22px 0 2px">Сверка периода</h2>'
                       . '<p class="sub" style="margin:0 0 6px">Услуги случая, его шапка и'
                       . ' начисление должны говорить одно и то же число.</p>'
                       . '<table><tr><th>Случаев</th><th class="n">Услуги</th>'
                       . '<th class="n">Шапка</th><th class="n">Начислено</th></tr>'
                       . '<tr><td>' . (int) $check['cases'] . '</td>'
                       . '<td class="n">' . $h(number_format($check['items'], 2, '.', ' ')) . '</td>'
                       . '<td class="n">' . $h(number_format($check['header'], 2, '.', ' ')) . '</td>'
                       . '<td class="n">' . $h(number_format($check['accrued'], 2, '.', ' ')) . '</td>'
                       . '</tr></table>';
            }

            if ($result['blocked'] !== '') {
                $html .= '<div class="msg warn" style="margin-top:12px"><b>Период не сходится.</b>'
                       . '<br>' . $h($result['blocked'])
                       . '<br>Удержаны проводки, которые читают случай — начисление, доля'
                       . ' финансиста, зачёт. Приход денег не удержан: деньги пришли независимо'
                       . ' от того, что говорит случай.'
                       . '<br>Случай правится в самом случае, а не запросом.</div>';

                if ($check !== NULL && $check['rows']) {
                    $html .= '<table><tr><th>Случай</th><th>Дата</th><th class="n">Услуги</th>'
                           . '<th class="n">Шапка</th><th class="n">Начислено</th></tr>';
                    foreach ($check['rows'] as $row) {
                        $html .= '<tr><td><a href="'
                               . $h(site_url('admin/sales/view/' . (int) $row->id)) . '">'
                               . (int) $row->id . '</a></td>'
                               . '<td>' . $h(substr((string) $row->d, 0, 10)) . '</td>'
                               . '<td class="n">' . $h(number_format((float) $row->items, 2, '.', ' ')) . '</td>'
                               . '<td class="n">' . $h(number_format((float) $row->header, 2, '.', ' ')) . '</td>'
                               . '<td class="n">' . $h(number_format((float) $row->accrued, 2, '.', ' ')) . '</td>'
                               . '</tr>';
                    }
                    $html .= '</table>';
                }

                if ($check !== NULL && $check['orphans'] > 0) {
                    $html .= '<p class="bad">Строки вне области услуг, с деньгами — случаи: '
                           . $h(implode(', ', $check['orphanCases'])) . '</p>';
                }
            }

            $html .= '<h2 style="font-size:15px;margin:22px 0 2px">Проводки</h2>'
                   . '<table><tr><th>Регистр</th><th>Проводка</th><th class="n">Черновиков</th>'
                   . '<th class="n">Проведено</th><th class="n">Ошибок</th></tr>';

            $off = array();
            foreach ($result['legs'] as $entity => $leg) {
                if (!$leg['enabled']) {
                    $off[] = $entity;
                    $html .= '<tr class="off"><td>' . $h($entity) . '</td><td>' . $h($leg['pair'])
                           . '</td><td colspan="3">выключен</td></tr>';
                    continue;
                }
                $html .= '<tr' . ($leg['held'] ? ' class="off"' : '') . '><td>' . $h($entity)
                       . '</td><td>' . $h($leg['pair']) . '</td>'
                       . '<td class="n">' . (int) $leg['drafts'] . '</td>'
                       . '<td class="n">' . ($leg['held'] ? 'удержано' : (int) $leg['posted']) . '</td>'
                       . '<td class="n">' . (int) $leg['failed'] . '</td></tr>';

                if ($leg['errors']) {
                    $html .= '<tr><td colspan="5"><ul class="reasons">';
                    foreach ($leg['errors'] as $reason => $seen) {
                        $html .= '<li>' . (int) $seen['count'] . ' x ' . $h($reason)
                               . ' (первый: ' . $h($seen['first']) . ')</li>';
                    }
                    $html .= '</ul></td></tr>';
                }
            }

            $html .= '<tr class="total"><td colspan="2">Итого</td>'
                   . '<td class="n">' . (int) $result['drafts'] . '</td>'
                   . '<td class="n">' . (int) $result['posted'] . '</td>'
                   . '<td class="n">' . (int) $result['failed'] . '</td></tr></table>';

            if ($result['stopped'] !== '') {
                $html .= '<div class="msg warn"><b>Остановлено:</b> ' . $h($result['stopped'])
                       . '<br>Остаток периода не тронут. Когда причина уйдёт, запустите снова —'
                       . ' проведённое второй раз не выбирается.</div>';
            }

            if ($off) {
                $html .= '<div class="msg warn">Не участвовали: ' . $h(implode(', ', $off))
                       . '. Пока эти регистры выключены, период закрыт не полностью.</div>';
            }

            if ($token !== '') {
                $html .= '<form method="post" action="' . $h($action) . '" style="margin-top:18px">'
                       . '<input type="hidden" name="from" value="' . $h($result['from']) . '">'
                       . '<input type="hidden" name="to" value="' . $h($result['to']) . '">'
                       . '<input type="hidden" name="token" value="' . $h($token) . '">'
                       . '<button type="submit" class="go">Провести ' . (int) $free
                       . ' — отменить будет почти нечем</button></form>';
            } elseif (!$result['confirmed']) {
                $html .= '<p class="sub" style="margin:18px 0 0">За этот период проводить нечего.</p>';
            }
        }

        $html .= '</div></body></html>';

        $this->output->set_content_type('text/html', 'utf-8')->set_output($html);
    }

    /**
     * A register named on the command line, or NULL for all of them.
     *
     * Returns FALSE and says so for a name that is not a register: a typo must not silently
     * mean "every one of them" on a command that re-queues seventy thousand rows.
     */
    private function readEntity($entity)
    {
        $entity = trim((string) $entity);
        if ($entity === '') {
            return NULL;
        }
        // Asked of the library, never written out here -- see Rilven::knownEntities().
        $known = $this->rilven->knownEntities();
        if (!in_array($entity, $known, TRUE)) {
            $this->say('Unknown register "' . $entity . '". One of: ' . implode(', ', $known)
                . ' -- or leave it out for all of them.');
            return FALSE;
        }
        return $entity;
    }

    /**
     * Everything that has to be true before one patient can travel, tested in order.
     *
     * Written because every failure this integration has had was a configuration fact
     * nobody could see: a column called something else in this fork, a right that was
     * never granted, an id that means a different thing in this company. Each line says
     * what it looked at, so a wrong answer names its own cause.
     */
    public function check()
    {
        $client     = $this->rilven->client();
        $contractor = $this->rilven->contractor();

        $this->say('== configuration ==');
        $this->say('  url              : ' . $client->url(''));
        $this->say('  company id       : ' . $client->companyId());
        $this->say('  auth mode        : ' . $client->mode());
        $this->say('  client id        : ' . $client->cfg('rilven_client_id', ''));
        $this->say('  secret           : ' . ($client->cfg('rilven_secret_key', '') !== '' ? 'set' : 'MISSING'));
        $this->say('  api key          : ' . ($client->cfg('rilven_api_key', '') !== '' ? 'set' : 'not set'));
        $this->say('  enabled          : ' . ($client->cfg('rilven_enabled', FALSE) ? 'yes' : 'NO -- nothing will be sent'));
        $this->say('  branch sync      : ' . ($client->cfg('rilven_branch_enabled', FALSE) ? 'on' : 'off'));

        // ---- the clinic's own database -------------------------------------------------
        $this->say('== this database ==');
        $table = (string) $client->cfg('rilven_source_table', 'companies');
        $columns = array();
        try {
            $columns = $this->db->list_fields($table);
        } catch (Exception $e) {
            $this->say('  !! cannot read ' . $this->db->dbprefix($table) . ': ' . $e->getMessage());
        }

        if (!empty($columns)) {
            $this->say('  table            : ' . $this->db->dbprefix($table) . ' (' . count($columns) . ' columns)');
            $fields = $client->cfg('rilven_fields', array());
            foreach ($fields as $meaning => $column) {
                $found = in_array($column, $columns, TRUE);
                $this->say(sprintf('  %-16s : %-22s %s', $meaning, $column,
                    $found ? 'ok' : 'NOT A COLUMN HERE -- this value is never sent'));
            }
            foreach ($client->cfg('rilven_patient_where', array()) as $column => $value) {
                $this->say(sprintf('  filter           : %s = %s %s', $column, var_export($value, TRUE),
                    in_array($column, $columns, TRUE) ? '' : ' NOT A COLUMN HERE'));
            }
            $this->say('  all columns      : ' . implode(', ', $columns));

            $this->say('  patients         : ' . $this->rilven->sourceCount(FALSE));
            $this->say('  with movement    : ' . $this->rilven->sourceCount(TRUE) . '  (what the catch-up queues)');
        }

        // ---- money destinations ---------------------------------------------------------
        //
        // The breakdown by kind is the line to read, and the UNKNOWN line is the one that costs
        // money if ignored: every payment through a destination this library cannot classify is
        // refused, and a clinic adding a new kind of till has no way to know that except here.
        // Printed whenever the section EXISTS, enabled or not -- unlike the registers above.
        //
        // This block is the pre-flight: it is where an unknown `paid_by` shows up, and an unknown
        // kind stops every payment through that destination. Hiding it until the register is
        // switched on would mean the only way to see the problem is to switch on and find out,
        // which is the wrong order for something that touches 1110 and 1210.
        if ($client->cfg('rilven_cash_source_table', NULL) !== NULL) {
            $this->say('== money destinations ==');
            $this->say('  enabled          : ' . ($client->cfg('rilven_cash_enabled', FALSE) ? 'yes' : 'NO -- nothing is sent yet'));
            $this->describeSource(
                (string) $client->cfg('rilven_cash_source_table', 'cash'),
                $client->cfg('rilven_cash_fields', array()),
                $client->cfg('rilven_cash_where', array())
            );
            $this->say('  code             : id');
            try {
                $counts = $this->rilven->cashCount();
                $this->say('  in scope         : ' . $counts['total']);
                $this->say('    tills (1110)   : ' . $counts['cash-machine']);
                $this->say('    accounts (1210): ' . $counts['bank-account'] . '  (bank rows and card terminals)');
                if (!empty($counts['unknown'])) {
                    foreach ($counts['unknown'] as $seen => $n) {
                        $this->say('  !! UNKNOWN kind  : ' . $n . ' x "' . $seen
                            . '" -- add it to rilven_cash_kinds or their payments will not post');
                    }
                } else {
                    $this->say('  unknown kinds    : none');
                }
            } catch (Exception $e) {
                $this->say('  !! cannot count  : ' . $e->getMessage());
            }
        }

        // ---- money received -------------------------------------------------------------
        //
        // Two numbers matter here and they are easy to confuse. "other leg" is the half of the
        // table that is NOT sent -- expected, and counting it apart is the only way to see that
        // the split is working. "UNKNOWN" is a kind nobody has decided about, and its payments
        // are not posted at all.
        if ($client->cfg('rilven_deposit_source_table', NULL) !== NULL) {
            $this->say('== money received ==');
            $this->say('  enabled          : ' . ($client->cfg('rilven_deposit_enabled', FALSE) ? 'yes' : 'NO -- nothing is sent yet'));
            $this->describeSource(
                (string) $client->cfg('rilven_deposit_source_table', 'deposits'),
                $client->cfg('rilven_deposit_fields', array()),
                $client->cfg('rilven_deposit_where', array())
            );
            $this->say('  from             : ' . $client->cfg('rilven_deposit_from', '(no floor -- every payment ever)'));
            $this->say('  owed back to     : ' . $client->cfg('rilven_deposit_payer_column', 'company_id')
                . '  (the PAYER, which is not always the patient)');
            try {
                $counts = $this->rilven->depositCount();
                $this->say('  rows in window   : ' . $counts['total']);
                $this->say('    money in       : ' . $counts['in']);
                $this->say('    money out      : ' . $counts['out']);
                $this->say('    other leg      : ' . $counts['other-leg'] . '  (not sent -- Rilven writes it)');
                if (!empty($counts['unknown'])) {
                    foreach ($counts['unknown'] as $seen => $n) {
                        $this->say('  !! UNKNOWN kind  : ' . $n . ' x "' . $seen
                            . '" -- add it to rilven_deposit_kinds or these payments never post');
                    }
                } else {
                    $this->say('  unknown kinds    : none');
                }
            } catch (Exception $e) {
                $this->say('  !! cannot count  : ' . $e->getMessage());
            }
            $helpers = $client->cfg('rilven_deposit_helpers', array());
            foreach (array('cash-machine/in' => '1110 / 3120', 'cash-machine/out' => '3120 / 1110',
                           'bank-account/in' => '1210 / 3120', 'bank-account/out' => '3120 / 1210') as $k => $pair) {
                $this->say('  ' . str_pad($k, 17) . ': '
                    . (isset($helpers[$k]) && (int) $helpers[$k] > 0
                        ? 'helper ' . $helpers[$k] . '  (' . $pair . ')'
                        : '!! NOT SET -- these payments will be refused'));
            }
        }

        // ---- the service reference -----------------------------------------------------
        //
        // Same treatment as the patients, and for the same reason: every failure this
        // integration has had was a column called something else in this fork. The counts at the
        // end are the line to read -- "0 service categories" means the scope is wrong, not that
        // the clinic sells nothing.
        if ($client->cfg('rilven_cash_source_table', NULL) !== NULL) {
            // Probed with a code nothing can answer to, and with the SAME query the register
            // sends. "[code]-not-found" means the route, the rights and `?by=code` are all
            // working; anything else is the reason the register cannot run, said in its own
            // words instead of being guessed at from a refusal count.
            $probe = $client->get('/company-cash-machine/get/rilven-check-no-such-code', array('by' => 'code'));
            $this->say('  /company-cash-machine : ' . $this->readProbe($probe, '[code]-not-found'));

            $probe = $client->get('/company-bank-account/get/rilven-check-no-such-code', array('by' => 'code'));
            $this->say('  /company-bank-account : ' . $this->readProbe($probe, '[code]-not-found'));

            $probe = $client->get('/company-bank-account/list', array('key' => 'rilven-check-no-such-account'));
            $this->say('  /company-bank-account/list: ' . ($probe['ok']
                ? 'ok (answered)' : $this->readProbe($probe, '__never__')));
        }

        if ($client->cfg('rilven_category_enabled', FALSE)) {
            $this->say('== service categories ==');
            $this->describeSource(
                (string) $client->cfg('rilven_category_source_table', 'categories'),
                $client->cfg('rilven_category_fields', array()),
                $client->cfg('rilven_category_where', array())
            );
            $this->say('  code             : id  (NOT the source\'s own `code` column -- that one is editable)');
            try {
                $this->say('  categories       : ' . $this->rilven->categoryCount() . '  (what the catch-up queues)');
            } catch (Exception $e) {
                $this->say('  !! cannot count  : ' . $e->getMessage());
            }
        }

        if ($client->cfg('rilven_service_enabled', FALSE)) {
            $this->say('== services ==');
            $this->describeSource(
                (string) $client->cfg('rilven_service_source_table', 'products'),
                $client->cfg('rilven_service_fields', array()),
                $client->cfg('rilven_service_where', array())
            );
            $this->say('  category column  : ' . $client->cfg('rilven_service_category_column', 'category_id'));
            $this->say('  hidden column    : ' . ($client->cfg('rilven_service_hidden_column', '') === ''
                ? '-- none; every service is sent as active'
                : $client->cfg('rilven_service_hidden_column', '') . '  (truthy = INACTIVE, still sent)'));
            $this->say('  scoped by category: ' . ($client->cfg('rilven_service_via_category', TRUE)
                ? 'yes -- a product whose category is a service category'
                : 'no -- rilven_service_where alone decides'));
            $this->say('  code             : id  (NOT the source\'s own `code` column)');
            try {
                $this->say('  services         : ' . $this->rilven->serviceCount() . '  (what the catch-up queues)');
            } catch (Exception $e) {
                $this->say('  !! cannot count  : ' . $e->getMessage());
            }

            // THE line to read before this register is switched on. These services carry a
            // category that is not a service category, so they wait for one that is never coming
            // and spend ten attempts each discovering it.
            try {
                $orphans = $this->rilven->servicesOutsideSyncedCategories();
                if ($orphans === 0) {
                    $this->say('  outside a synced category: 0 -- every service\'s category is one this library syncs');
                } elseif ($client->cfg('rilven_service_require_category', TRUE)) {
                    $this->say('  !! outside a synced category: ' . $orphans
                        . ' service(s) point at a category that is NOT a service category.');
                    $this->say('     Each will wait for a category that never arrives'
                        . ' ("category-not-synced-yet") and be given up on after'
                        . ' ' . (int) $client->cfg('rilven_max_attempts', 10) . ' attempts.');
                    $this->say('     Either widen rilven_category_where to cover them, or set'
                        . ' rilven_service_require_category = FALSE to send them uncategorised.');
                } else {
                    $this->say('  outside a synced category: ' . $orphans
                        . ' -- these will be sent WITHOUT a category (rilven_service_require_category is FALSE)');
                }
            } catch (Exception $e) {
                $this->say('  !! cannot count  : ' . $e->getMessage());
            }
        }

        if ($client->cfg('rilven_sale_enabled', FALSE)) {
            $this->say('== service sales ==');
            $this->say('  tables           : ' . $this->db->dbprefix($client->cfg('rilven_sale_source_table', 'sales'))
                . ' + ' . $this->db->dbprefix($client->cfg('rilven_sale_item_table', 'sale_items')));
            $this->say('  from             : ' . $client->cfg('rilven_sale_from', '(no window -- the whole history)'));
            $this->say('  document type    : ' . $client->cfg('rilven_sale_document_type', 13) . ' (SALE_SERVICE)');
            $this->say('  confirm (post)   : ' . ($client->cfg('rilven_sale_post', FALSE)
                ? 'YES -- documents are CONFIRMED and the accrual is written'
                : 'no -- drafts only, nothing reaches the ledger'));
            try {
                $this->say('  cases in scope   : ' . $this->rilven->saleCount() . '  (what the catch-up queues)');
            } catch (Exception $e) {
                $this->say('  !! cannot count  : ' . $e->getMessage());
            }

            // THE line for this register. The accrual's debit leg is keyed on the patient's
            // branch, so a case whose patient has none cannot be sent at all.
            try {
                $orphans = $this->rilven->salesWithoutPatientBranch();
                if ($orphans === 0) {
                    $this->say('  without a patient branch: 0 -- every case\'s patient is known over there');
                } else {
                    // NOT a failure count. The outbox only ever captured these ids when it CREATED
                    // a patient, so most are simply unknown to it -- and the library now asks
                    // Rilven for them on demand and writes the answer back. What this number
                    // measures is the FIRST-RUN COST: up to two extra requests per distinct
                    // patient, once each, and never again.
                    $this->say('  without a patient branch (in this outbox): ' . $orphans . ' case(s).');
                    $this->say('     These are resolved on demand -- /contractor/get then');
                    $this->say('     /contractor-branch/list -- and the ids are written back, so each');
                    $this->say('     PATIENT costs the lookup once, not each case and not every run.');
                    $this->say('     Distinct patients, not cases, is what the first run actually pays.');
                }
            } catch (Exception $e) {
                $this->say('  !! cannot count  : ' . $e->getMessage());
            }
        }

        // ---- the credential ------------------------------------------------------------
        $this->say('== signing in ==');
        if (!$client->credentialsPresent()) {
            $this->say('  !! url, company id or credentials are missing -- stopping here');
            return;
        }
        if (!$client->login(TRUE)) {
            $this->say('  !! refused: ' . $client->lastError());
            return;
        }
        $this->say('  ok               : session established' . ($client->mode() === 'user' ? ' (cookies kept for later runs)' : ''));

        // ---- the two routes ------------------------------------------------------------
        //
        // Probed with values that cannot match anything, so the check writes nothing. What
        // is being read is WHICH refusal comes back: the route's own complaint means the
        // session and the grant are both good, `[permission]-denied` means the account has
        // not been given this endpoint, and those two look identical from a distance.
        $this->say('== routes ==');

        $probe = $client->get('/contractor/get/rilven-check-no-such-code', array('by' => 'code'));
        $this->say('  /contractor      : ' . $this->readProbe($probe, '[code]-not-found'));

        $branch = $client->get('/contractor-branch/list', array('contractor-id' => 0));
        $this->say('  /contractor-branch: ' . $this->readProbe($branch, '[contractorId]-is-invalid'));

        if ($client->cfg('rilven_deposit_source_table', NULL) !== NULL) {
            $probe = $client->get('/cash-flow/get/rilven-check-no-such-code', array('by' => 'code'));
            $this->say('  /cash-flow       : ' . $this->readProbe($probe, '[code]-not-found'));
        }

        if ($client->cfg('rilven_category_enabled', FALSE)) {
            $probe = $client->get('/category/get/rilven-check-no-such-code', array('by' => 'code'));
            $this->say('  /category        : ' . $this->readProbe($probe, '[code]-not-found'));
        }
        if ($client->cfg('rilven_service_enabled', FALSE)) {
            $probe = $client->get('/asset-service/get/rilven-check-no-such-code', array('by' => 'code'));
            $this->say('  /asset-service   : ' . $this->readProbe($probe, '[code]-not-found'));
        }
        if ($client->cfg('rilven_sale_enabled', FALSE)) {
            // Probed with a code nothing can answer to. It WRITES NOTHING -- code/check only asks
            // whether a code is free -- and a refusal here means the document register cannot run.
            $probe = $client->post('/waybill/code/check', array(
                'code'         => 'rilven-check-no-such-code',
                'documentType' => (int) $client->cfg('rilven_sale_document_type', 13),
            ));
            $this->say('  /waybill         : ' . ($probe['ok']
                ? 'ok (answered)'
                : $this->readProbe($probe, '__never__')));
        }
        if ($client->cfg('rilven_category_enabled', FALSE) || $client->cfg('rilven_service_enabled', FALSE)) {
            // Not probed with a value that cannot match: this one has to ANSWER, because it is
            // what an unpinned account class is resolved through.
            $map = $client->post('/account-plan/map/filter', array('type' => array(
                (int) $client->cfg('rilven_category_account_type', 3))));
            $this->say('  /account-plan/map: ' . ($map['ok']
                ? 'ok (answered)'
                : $this->readProbe($map, '__never__')));
        }

        // ---- the ids that differ per installation --------------------------------------
        $this->say('== reference ids ==');
        $refs = $contractor->references(TRUE);
        foreach (array('countryId', 'legalFormId', 'contragentTypeId', 'stateId', 'cityId') as $key) {
            $this->say(sprintf('  %-16s : %s', $key, $refs[$key] === NULL ? '-- unresolved' : $refs[$key]));
        }
        foreach ($refs['notes'] as $note) {
            $this->say('  note             : ' . $note);
        }

        $missing = $contractor->missingReferences($refs);

        // ---- the service reference's own ids -------------------------------------------
        if ($client->cfg('rilven_category_enabled', FALSE) || $client->cfg('rilven_service_enabled', FALSE)) {
            $this->say('== service reference ids ==');

            $categoryRefs = $this->rilven->category()->references(TRUE);
            $this->say(sprintf('  %-16s : %s', 'category account',
                $categoryRefs['accountPlanMapId'] === NULL ? '-- unresolved' : $categoryRefs['accountPlanMapId']));
            $this->sayAccountMap($client, $categoryRefs['accountPlanMapId']);

            $serviceRefs = $this->rilven->service()->references(TRUE);
            $this->say(sprintf('  %-16s : %s', 'service account',
                $serviceRefs['accountPlanMapId'] === NULL ? '-- unresolved' : $serviceRefs['accountPlanMapId']));
            $this->say(sprintf('  %-16s : %s', 'service vatType',
                $serviceRefs['vatType'] === NULL ? '-- NOT SET, nothing can be sent'
                    : $serviceRefs['vatType'] . ' (1 standard, 2 tax-free, 3 no VAT)'));

            foreach (array_merge($categoryRefs['notes'], $serviceRefs['notes']) as $note) {
                $this->say('  note             : ' . $note);
            }

            if ($client->cfg('rilven_category_enabled', FALSE)) {
                $missing = array_merge($missing, $this->rilven->category()->missingReferences($categoryRefs));
            }
            if ($client->cfg('rilven_service_enabled', FALSE)) {
                $missing = array_merge($missing, $this->rilven->service()->missingReferences($serviceRefs));
            }
        }

        if ($client->cfg('rilven_sale_enabled', FALSE)) {
            $saleRefs = $this->rilven->sale()->references(TRUE);
            $this->say('== sale document ids ==');
            foreach (array('companyBranchId', 'currencyId', 'vatType') as $key) {
                $this->say(sprintf('  %-16s : %s', $key,
                    $saleRefs[$key] === NULL ? '-- NOT SET, nothing can be sent' : $saleRefs[$key]));
            }
            // Not one of the required three: the warehouse comes from the case itself, resolved
            // by code. This is only what to do for a case whose warehouse was never synced.
            $this->say(sprintf('  %-16s : %s', 'warehouse',
                'per case, from ' . $client->cfg('rilven_sale_warehouse_column', 'warehouse_id')
                . ' by ?by=code'));
            $this->say(sprintf('  %-16s : %s', '  fallback',
                $saleRefs['warehouseId'] === NULL
                    ? 'none -- a case whose warehouse is not synced is refused rather than misfiled'
                    : $saleRefs['warehouseId'] . ' (every use is reported as warehouse-not-synced-fell-back)'));
            $missing = array_merge($missing, $this->rilven->sale()->missingReferences($saleRefs));
        }

        $this->say('== verdict ==');
        if (!empty($missing)) {
            $this->say('  NOT READY -- cannot resolve ' . implode(', ', $missing)
                . '. Pin them in config/rilven.php or fix what the notes say.');
            return;
        }
        if ($client->cfg('rilven_branch_enabled', FALSE) && ($refs['stateId'] === NULL || $refs['cityId'] === NULL)) {
            $this->say('  PARTLY READY -- counterparties will sync; branches will not, for want of'
                . ' a state and a city. Pin rilven_branch_state_id / rilven_branch_city_id.');
            return;
        }
        $this->say('  READY' . ($client->cfg('rilven_enabled', FALSE) ? '' : ' -- but rilven_enabled is FALSE, so nothing is sent'));
    }

    /**
     * What is waiting, what failed and why.
     *
     * The first question anybody asks about a queue is "is it stuck?", and the honest
     * answer is the oldest pending row's age -- a count alone looks healthy right up until
     * it stops moving.
     */
    public function index()
    {
        $this->output->set_content_type('application/json')->set_output(json_encode(array(
            'enabled'   => $this->rilven->enabled(),
            'signed_in' => $this->rilven->client()->signedInAt(),
            'counts'    => $this->rilven->counts(),
            'documents' => $this->rilven->documentStatusCounts(),
            'failures'  => $this->rilven->failures(50),
        ), JSON_UNESCAPED_UNICODE));
    }

    // -----------------------------------------------------------------------

    /**
     * A source table as this fork actually has it: the mapped columns, the scope, the real list.
     *
     * The same treatment the patients' table gets, and written once because the reason is the
     * same for all three -- these installations are forks that have drifted for years, and a
     * column that is not there is the single most common cause of an empty register.
     */
    private function describeSource($table, $fields, $where)
    {
        $columns = array();
        try {
            $columns = $this->db->list_fields($table);
        } catch (Exception $e) {
            $this->say('  !! cannot read ' . $this->db->dbprefix($table) . ': ' . $e->getMessage());
            return;
        }

        $this->say('  table            : ' . $this->db->dbprefix($table) . ' (' . count($columns) . ' columns)');

        foreach ((array) $fields as $meaning => $column) {
            if ($column === NULL || $column === '') {
                $this->say(sprintf('  %-16s : %-22s not mapped on this installation', $meaning, '--'));
                continue;
            }
            $this->say(sprintf('  %-16s : %-22s %s', $meaning, $column,
                in_array($column, $columns, TRUE) ? 'ok' : 'NOT A COLUMN HERE -- this value is never sent'));
        }

        foreach ((array) $where as $column => $value) {
            $this->say(sprintf('  filter           : %s = %s %s', $column, var_export($value, TRUE),
                in_array($column, $columns, TRUE) ? '' : ' NOT A COLUMN HERE'));
        }

        $this->say('  all columns      : ' . implode(', ', $columns));
    }

    /**
     * Which account a resolved map id actually names, in this company's own chart.
     *
     * The id alone says nothing a person can check. Printing "38 -> 6110 Revenue from sales,
     * class 3" is what turns a pinned number copied from somebody's browser into a fact -- and
     * an id that belongs to another company's chart, or to a class a category may not be bound
     * to, shows up here as an id that answers nothing.
     */
    private function sayAccountMap($client, $mapId)
    {
        if ($mapId === NULL) {
            return;
        }
        $type = (int) $client->cfg('rilven_category_account_type', 3);
        $answer = $client->post('/account-plan/map/filter', array('type' => array($type)));
        if (!$answer['ok']) {
            $this->say('  note             : cannot verify the account class: ' . $answer['error']);
            return;
        }
        $items = isset($answer['data']['items']) && is_array($answer['data']['items'])
            ? $answer['data']['items'] : array();
        foreach ($items as $item) {
            if (isset($item['id']) && (int) $item['id'] === (int) $mapId) {
                $this->say(sprintf('  %-16s : %s %s (class %s)', '  -> account',
                    isset($item['code']) ? $item['code'] : '?',
                    isset($item['name']) ? $item['name'] : '',
                    isset($item['type']) ? $item['type'] : '?'));
                return;
            }
        }
        $this->say('  !! account class : map id ' . $mapId . ' is not in class ' . $type
            . ' of THIS company\'s chart. A category bound to it posts somewhere nobody chose.');
    }

    /** The refusal a probe came back with, said in a way that names its own cause. */
    private function readProbe($answer, $expected)
    {
        if ($answer['ok']) {
            return 'ok (answered)';
        }
        if (strpos($answer['error'], $expected) === 0) {
            return 'ok (session and rights good; it answered "' . $expected . '" as it should)';
        }
        if (strpos($answer['error'], '[permission]-denied') === 0) {
            return 'NOT GRANTED -- this account may not call this route. Ask Rilven to grant it.';
        }
        return 'unexpected: ' . $answer['error'];
    }

    /** One line, to the console and to the library's own log file if one is configured. */
    private function say($line)
    {
        if (is_cli()) {
            echo $line . PHP_EOL;
        }
        $file = (string) $this->rilven->client()->cfg('rilven_log_file', '');
        if ($file !== '') {
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
        }
    }
}
