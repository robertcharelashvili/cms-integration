# Upgrade: adding the service registers to a clinic already running the patient sync

Everything below is run from the operator's own machine. Nothing here needs a database change:
`entity` and the `(entity, external_id)` unique key were in `install.sql` from the start, so
`sma_rilven_outbox` already holds all three registers.

Written for LJ, where the library was installed 2026-09-17 and the application directory is
`/home/www/public/app`. Confirm that path before starting — it is the directory the front
controller's `$application_folder` names, and the forks rename it.

---

## 0. Two things that will break this if they are got wrong

**The five files go together.** The new `Rilven.php` loads `rilven_category` and `rilven_service`
in its constructor. If it lands without them, the cron fatals — and that takes the *working
patient sync* down with it. Copy all five in one command, or copy none.

**The config is APPENDED, never copied over.** The clinic's `config/rilven.php` holds the real
`rilven_secret_key`; the repository's copy does not. Overwriting it wipes the credential and the
sign-in starts answering `email-address-or-password-is-invalid`.

---

## 1. Back up what is there

```bash
STAMP=$(date +%F-%H%M%S)
ssh root@188.93.89.2 "mkdir -p /root/rilven-backup-$STAMP && \
  cp /home/www/public/app/libraries/Rilven*.php \
     /home/www/public/app/controllers/admin/Rilven_sync.php \
     /home/www/public/app/config/rilven.php \
     /root/rilven-backup-$STAMP/ && ls -la /root/rilven-backup-$STAMP/"
```

Note the printed directory name — section 6 rolls back to it.

## 2. Copy the five files

```bash
cd ~/DISK/Rilven/work/rilven-sync

scp application/libraries/Rilven.php \
    application/libraries/Rilven_contractor.php \
    application/libraries/Rilven_category.php \
    application/libraries/Rilven_service.php \
    root@188.93.89.2:/home/www/public/app/libraries/

scp application/controllers/admin/Rilven_sync.php \
    root@188.93.89.2:/home/www/public/app/controllers/admin/
```

`Rilven_contractor.php` is in the list because it gained `entity()`, `typeCode()`, `enabled()`
and `hash()` — the new `Rilven.php` calls all four on it.

## 3. Append the config

```bash
scp upgrade/config-additions.php root@188.93.89.2:/root/

ssh root@188.93.89.2 '
  if grep -q rilven_category_enabled /home/www/public/app/config/rilven.php; then
      echo "ALREADY PRESENT -- config left alone"
  else
      cat /root/config-additions.php >> /home/www/public/app/config/rilven.php && echo APPENDED
  fi
  php -l /home/www/public/app/config/rilven.php'
```

24 settings, all commented in place. The guard makes the step safe to re-run.

## 4. The doctor — writes nothing

```bash
ssh root@188.93.89.2 'cd /home/www/public && php index.php admin/rilven_sync check'
```

It probes every route with values that cannot match anything, so it creates nothing over there.

**What the output must say.** These numbers were measured against the dumps on 2026-09-19; a
different answer means something moved and is worth understanding before sending anything.

| line | expected |
|---|---|
| `== service categories ==` → `categories` | **7** |
| `== services ==` → `services` | **2 106** |
| `outside a synced category` | **5**, and since `rilven_service_require_category` is FALSE they are sent without one |
| `/category` | `ok (session and rights good…)` |
| `/asset-service` | `ok (session and rights good…)` |
| `/account-plan/map` | `ok (answered)` |
| `category account` → `-> account` | `6110 … (class 3)` |
| `service vatType` | `3` |
| `== verdict ==` | `READY` |

**If a route says `NOT GRANTED`** — the service account `lj-cms@rilven.local` has not been given
`CategoryController` / `AssetServiceController` / `AccountPlanController`. That is a grant on the
Rilven side, and until it is in place nothing about the clinic will fix it. Stop here.

**If `-> account` prints something other than 6110, or `!! account class`** — the pinned
`rilven_category_account_plan_map_id = 38` is not what it was assumed to be in this company's
chart. Do not send: a category bound to the wrong class decides how every document built on it
posts. Fix the id first.

## 5. The first real run

Only after the verdict reads READY.

```bash
# fill the queue, send nothing -- shows how much there is
ssh root@188.93.89.2 'cd /home/www/public && php index.php admin/rilven_sync backfill'

# send. Categories go first; the services follow in the same run.
ssh root@188.93.89.2 'cd /home/www/public && php index.php admin/rilven_sync cron'
```

Expect, on the first `cron`: `category created=7`, then `service created=2101` plus 5 carrying
`sent-without-category`. The patients are in the same run and should read mostly `unchanged`.

Check what landed:

```bash
ssh root@188.93.89.2 'cd /home/www/public && php index.php admin/rilven_sync index' | python3 -m json.tool
```

The number that matters is the **age of the oldest pending row**, not the counts: a count alone
looks healthy right up until it stops moving.

A second `cron` immediately after should report everything as `unchanged` and send nothing. If it
re-sends, the payload hash is not settling and that is a bug worth reporting.

## 6. Rolling back

```bash
ssh root@188.93.89.2 'cp /root/rilven-backup-<STAMP>/Rilven*.php /home/www/public/app/libraries/ && \
  cp /root/rilven-backup-<STAMP>/Rilven_sync.php /home/www/public/app/controllers/admin/ && \
  cp /root/rilven-backup-<STAMP>/rilven.php /home/www/public/app/config/'
```

This restores the patient sync exactly as it was. It does **not** remove anything already created
in Rilven — categories and services that landed stay there, and the outbox rows for them stay too
(harmless: the old code only ever looks at `entity = 'contractor'`).

To stop only the new registers without rolling back, set both switches to FALSE in
`config/rilven.php`:

```php
$config['rilven_category_enabled'] = FALSE;
$config['rilven_service_enabled']  = FALSE;
```

## 7. Hooking the save handlers — afterwards, and optional

Until this is done the two new registers only catch up through `backfill`, which the cron runs
every tick anyway; a category edited in the CMS reaches Rilven within a minute either way. The
hooks make it immediate and cost one line each.

```php
$this->load->library('rilven');

// controllers/admin/Categories.php, after a successful add and after a successful edit
$this->rilven->queueCategory($id);    // $id = sma_categories.id

// controllers/admin/Products.php, likewise
$this->rilven->queueService($id);     // $id = sma_products.id
```

Neither throws, neither blocks, and each ignores a row outside its own scope — a goods category, a
product that is not a service — so there is no need to work out first whether this one counts.

---

## What was measured, and what was not

Measured against dumps of `sma_categories` and `sma_products`, 2026-09-19:

* 20 categories, **flat** (`parent_id` is 0/NULL everywhere); 7 have `product_type = 2`
  — ids 1, 115, 119, 121, 1658, 1680, 1684. `accounting_methods` is 1 on all seven.
* 12 968 products: 9 990 goods, **2 106 services** (`service = 1`), 872 deleted (`service = 99`).
  `service` agrees exactly with the `type` column and no row is both `service = 1` and
  `type = 'delete'`.
* `hide = 1` on 417 of the services. They are sent, marked inactive.
* Longest service name 255 characters — exactly Rilven's limit, so nothing truncates.
* 5 services sit in goods categories (3 in სხვა 1696, 1 in საკანცელარიო 1695, 1 in უსასყიდლო 1691).

**Not verified:** anything on the Rilven side. The routes, the grants for the service account, and
that map id 38 really is 6110 in company 1022's chart are all what section 4 exists to find out.
The mapping and push logic are covered by a 46-case stub harness, which proves the logic and not
the far side.

---

# Phase 2: service sales — the accrual

The register that carries money. Everything above it exists so this one has something to point at.

```
Событие             Дебет            Кредит    Условие
Начисление услуги   1410 Пациент     6110      Полная стоимость услуги
```

**This library does not build that posting and must not.** Rilven's engine writes it when the
document is confirmed — `debit = SLOT_AR_TRADE (1410)`, `credit = serviceItem.account_plan_id`,
which is the account carried by the SERVICE. That is why phase 1's `accountPlanMapId` is
load-bearing: get it wrong and every accrual credits the wrong account.

## What it sends

One `/waybill/insert` with `documentType = 13` (SALE_SERVICE) per **treatment case**
(`sma_sales`), its `serviceItems` the case's lines (`sma_sale_items`, `subservice = 0`).

* `code` = `sma_sales.id`
* money ×10000 — `decimal(25,4)` multiplies exactly, nothing is rounded into existence
* **quantity is a plain count, NOT scaled.** A fractional quantity is refused rather than rounded
* the accrual date, from the clinic's own rule:
  * inpatient (`service > 2`) accrues on **discharge** — `outdate`, else `indate`, else the last
    line's `post_date`, else `sales.date`
  * outpatient accrues on the **last `post_date`** of its lines, else `sales.date`

## Two calls, and why the second is off by default

`/waybill/insert` creates a **draft** and posts nothing. The accrual appears only when the status
goes 1 → 2 through `/waybill/update-status`. `rilven_sale_post` ships **FALSE**: a first run fills
the register with drafts that can be read, counted against the CMS and deleted with no trace in
the books. An accrual into a period that is later closed cannot be undone from here.

## The blocker to check FIRST

The 1410 leg is keyed on the patient's **branch**, and the engine reads it unboxed — a missing one
is a failed posting, not a blank field. `/contractor-branch/list` is **not granted** to the service
account, so the branch can only come from this library's own outbox, which recorded it when each
patient was created.

```sql
SELECT COUNT(*) AS total, COUNT(rilven_id) AS with_id, COUNT(rilven_branch_id) AS with_branch
FROM sma_rilven_outbox WHERE entity = 'contractor';
```

**Measured 2026-09-19: 70 045 rows, 379 with an id, 379 with a branch.** Both columns are written
only from the answer to a CREATE, so they hold only the patients this library itself inserted; the
other 69 666 were sent earlier or read as `unchanged`, which writes NULL.

So the library no longer depends on them. When a case finds no branch in the outbox it **asks**
— `/contractor/get/{id}?by=code` for the counterparty, `/contractor-branch/list` for its branch —
caches the answer for the run and **writes it back into the outbox row**, so each patient costs one
lookup once rather than one per run. Nothing needs re-sending.

**But `/contractor-branch/list` is NOT GRANTED**, so today that second call answers
`[permission]-denied` and every case is refused with `branch-lookup: [permission]-denied`. It is
not retryable — a grant is a decision, not a delay — so the rows are given up on and wait for
`retry sale`.

**This register cannot run until `ContractorBranchController` is granted to
`lj-cms@rilven.local`.** That is one grant on the Rilven side, and it is the last thing standing
between here and the accrual. It is also the same grant the patients' contact details have been
waiting on since the beginning.

`check` counts the cases affected, as `without a patient branch`.

## Filling in the config

Four ids the clinic's register cannot supply, all required:

```php
$config['rilven_sale_company_branch_id'] = NULL;   // company 1022 has one branch, id 30
$config['rilven_sale_warehouse_id']      = NULL;   // required even for a document moving no goods
$config['rilven_sale_currency_id']       = NULL;   // GEL
$config['rilven_sale_vat_type']          = NULL;   // NULL takes the services' own setting
```

Then `$config['rilven_sale_enabled'] = TRUE;` and run `check`. It prints the cases in scope, the
window, whether confirming is on, and the branch count above. It writes nothing.

## The staged test

```bash
# 1. a handful of drafts, nothing confirmed
ssh root@188.93.89.2 'cd /home/www/public && php index.php admin/rilven_sync backfill'
ssh root@188.93.89.2 'cd /home/www/public && php index.php admin/rilven_sync cron'
```

Open a few in Rilven, tie each back to its `sma_sales` row — the comment carries
`<reference_no> / CMS case <id>` — and check the amounts and the date. Delete the drafts if
anything is wrong; nothing has reached the ledger.

Only then set `rilven_sale_post = TRUE` and re-run. Confirmed documents are read-only, so from
that point an edited case answers `case-changed-but-document-is-already-posted` and a person has
to decide whether the difference is a correction or a new fact.

## What has no answer yet

* **`/waybill` has no `?by=code`.** Contractors, categories and services can all be found by the
  CMS's own id; a document cannot. The mapping lives only in `sma_rilven_outbox.rilven_id`, and
  `/waybill/code/check` is the guard that keeps a lost mapping from becoming a second accrual —
  it refuses rather than duplicating, and says so. If that table is ever lost the link cannot be
  rebuilt from either side, which is what happened to 69 778 patients in September. Worth adding
  `?by=code` to `/waybill/get` on the Rilven side.
* **There is no deletion, and that is settled.** `sma_sales` has no `deleted_at` — the earlier
  integration model queried one and would have failed outright — and `sale_delete_status`, the
  other candidate, is NULL on every row and unused. A case is killed by **replacing its lines with
  zero-valued ones**: it keeps its id and its kind, stays in scope, and what travels is an update
  to zero. `rilven_sale_where` is therefore empty by measurement, not by omission.

  That update has to get past a confirmed document, which is read-only. `rilven_sale_unpost`
  (default TRUE) takes it back to a draft first — 2 → 1 is an allowed transition and unconfirming
  **deletes the ledger entries**, which is the product's own reversal path. The accrual ends up
  removed and a zero one in its place. A CLOSED period refuses the whole thing, and that
  protection is Rilven's, not this library's. Set it FALSE and such a case is refused with
  `case-changed-but-document-is-already-posted` and waits for a person.

## Refusing the edit rather than chasing it

Unposting and rewriting is the sync catching up with an edit that already happened. The guard
below is the other half: stopping the edit in the first place, once the accrual is in the books.

```php
// controllers/admin/Sales.php, at the TOP of the edit handler -- before any write
$this->load->library('rilven');
if ($this->rilven->isPostedInRilven($id)) {
    $this->session->set_flashdata('error', lang('case_is_posted_in_accounting'));
    redirect($_SERVER['HTTP_REFERER']);
}
```

`isPostedInRilven()` reads `sma_rilven_outbox.rilven_status`, which this library writes on every
push. **It asks Rilven nothing.** A save in the CMS that waited on this server would stop the
registration desk on a day Rilven is down, and that is the whole reason the integration is a queue.

The cost is that the answer is as fresh as the last cron tick, so the guard is a policy and not a
lock. It refuses only what it has positively seen confirmed, and a document confirmed by hand in
Rilven since the last run is caught one run later — by the very refusal that records the status.
The books are never at risk in the gap: an edit that slips through is still an edit the sync has to
carry over, and `rilven_sale_unpost` decides what happens to it.

**The two settings are a pair, and they answer the same question in opposite directions.**

| | `rilven_sale_unpost = TRUE` | `rilven_sale_unpost = FALSE` |
|---|---|---|
| **guard off** | an edit silently reverses and re-posts the accrual | an edit sticks in the queue as `case-changed-but-document-is-already-posted` |
| **guard on** | the edit is refused in the CMS; the few that slip through still carry over | the edit is refused in the CMS, and anything that slips through waits for a person |

Running the guard **with `rilven_sale_unpost = TRUE`** is the recommendation. The guard is what
stops the routine case, and unposting stays as the way the rare one — a correction made in the gap,
a case reopened deliberately — still reaches the books instead of sitting in a queue nobody reads.
Turning both on hardest (guard on, unpost FALSE) is defensible in a clinic with somebody who
watches the refusals; with nobody watching it is how an accrual quietly stops matching the case.
