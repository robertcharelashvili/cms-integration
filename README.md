# Rilven connection library for the clinic CMS

A one-way push of **three registers** from a clinic's CodeIgniter CMS into Rilven — patients,
service categories and services. Nothing travels the other way, and nothing in the CMS changes
behaviour: the save handlers gain one line that puts a row in a queue.

Each register's scope is deliberately narrow.

| register | what it sends | scope |
|---|---|---|
| `contractor` | patients | `sma_companies` rows matching `rilven_patient_where` |
| `category` | service categories | `sma_categories` where `product_type = 2` |
| `service` | the service catalogue | `sma_products` where `service = 1` |

`sma_companies` holds patients, insurers, suppliers and billers together and only the first are
sent; `sma_categories` is typed and only the service side of it is. The other kinds are a
different mapping onto different registers and they are not this library's business.

**The services register is the catalogue, not the work done.** A service *performed* is a row of
`sma_sale_items` with its own `service_instance_id`, date, patient and amount. This library sends
the thing `sale_items.product_code` names. The specification is explicit about the difference:
«код услуги в справочнике не является идентификатором факта её оказания».

## The routes, and who owns what

| Rilven route | what it carries |
|---|---|
| `/api/v1/contractor` | the counterparty: name, identification number, kind, sex, date of birth, and **`code`** |
| `/api/v1/contractor-branch` | the contact details: phone, e-mail, address |
| `/api/v1/category` | a service category: **`code`**, name, and the account CLASS its services post through |
| `/api/v1/asset-service` | a service: **`code`**, name, category, account class, VAT treatment |
| `/api/v1/account-plan/map/filter` | read-only, to resolve and verify that account class |

**Nothing was added to Rilven for this.** All five already existed, `?by=code` included.

Over there a posting resolves a **branch**, not a counterparty, which is why the contact
details live on the branch and not beside the name. A counterparty created through the
first route is given a default branch carrying whatever phone, e-mail and address were sent
with it — so the first push of a patient needs one route. Keeping those details up to date
afterwards needs the second, because the counterparty's own update route does not carry
them.

## The link between the two systems

**`code` is always the source row's own id**, per register:

| over there | here |
|---|---|
| `tb_contractor_data.code` | `sma_companies.id` |
| `tb_category_data.code` | `sma_categories.id` |
| `tb_asset_service_data.code` | `sma_products.id` |

That is the whole binding. There is no map table on either side and neither system stores the
other's ids to work:

```
GET /api/v1/contractor/get/{code}?by=code
    → 200 {"status":"ok","data":{"item":{"id":49,...}}}     we have this patient
    → 200 {"status":"fail","data":{"error":"[code]-not-found"}}   we do not — create them
```

`[code]-not-found` is deliberately a different sentence from "the call was wrong", so the
queue can tell "create it" from "stop".

Two things about `code` that are not negotiable:

* **It must match `^[a-z0-9]+(-[a-z0-9]+)*$`.** Rilven refuses anything else outright rather
  than tidying it, because a code it altered is one this side's next lookup would not find.
  A bare numeric id passes. `rilven_code_prefix` is available when one Rilven company takes
  patients from two source systems; it becomes part of the key, so changing it later orphans
  everything already sent.
* **It is unique per company over there.** One CMS installation writes into one Rilven
  company.

The outbox also records the id Rilven answered with (`rilven_id`, `rilven_branch_id`).
Nothing reads it. It is there because the previous version of this integration kept the
mapping only on the far side, that table was dropped, and about 69 778 patient↔counterparty
links could not be reconstructed from either database.

## The files

```
install.sql                                    the queue and the state table
application/config/rilven.php                  everything per-clinic, including the credential
application/libraries/Rilven_client.php        the connection: credentials, session, transport
application/libraries/Rilven_contractor.php    a patient, as the two routes want it
application/libraries/Rilven_category.php      a service category, as /category wants it
application/libraries/Rilven_service.php       a service, as /asset-service wants it
application/libraries/Rilven.php               the queue and the run — the only class the CMS calls
application/controllers/admin/Rilven_sync.php  cron, doctor, status page
```

`Rilven_client` knows nothing about any register; a register knows nothing about the queue.
Adding one is a file beside the others carrying ten methods — `entity`, `typeCode`, `enabled`,
`code`, `lookup`, `map`, `hash`, `push`, `references`, `missingReferences` — plus its scope in
`Rilven.php` and its switch in the config. It is not a branch inside an existing one.

## Install

1. **Apply the schema** — `install.sql`. Change the `sma_` prefix if this installation uses
   another (`config/database.php`, `dbprefix`). Everything else goes through CodeIgniter's
   query builder, which applies the prefix itself. Safe to re-run, and safe to run over the
   first version of these tables.

2. **Copy the seven PHP files** into the application directory, keeping the paths.

   The repository lays them out under `application/` because that is CodeIgniter's own
   name for that directory. **These forks rename it.** On LJ the application directory is
   `/home/www/public/app`, with `config/`, `libraries/` and `controllers/` directly inside
   it and the framework at `/home/www/public/system` — so the files land in
   `app/config/rilven.php`, `app/libraries/Rilven*.php`,
   `app/controllers/admin/Rilven_sync.php`. Find it before copying: it is the directory
   the front controller's `$application_folder` names.

3. **Fill in `config/rilven.php`.** Every value is commented there. The four that are
   always wrong on a fresh install:

   * `rilven_url` — `https://app.rilven.com`. **Not** `console.rilven.com`: that host is the
     ERP's own front end, it answers every GET with the single-page application (including
     under `/api/v1`) and refuses POST with an nginx 405. A library pointed at it reads HTML
     where it expects an answer and reports every patient as "not found".
   * `rilven_client_id` / `rilven_secret_key` — the service account and its password.
   * `rilven_company_id` — which company's books this clinic writes into.
   * `rilven_fields` — **the name columns above all.** The stock product keeps a given name
     in `name`; the LJ clinic keeps the SURNAME there and the GIVEN name in `company`. Get
     it the wrong way round and the whole register fills with half-names.

4. **Run the doctor** and fix what it says:

   ```
   php index.php admin/rilven_sync check
   ```

   It tests, in order: the configuration; that every mapped column exists in this fork, for
   each of the three source tables (and prints the real column list beside it); how many
   patients, categories and services are in scope; the sign-in; **every route, probed with
   values that cannot match anything, so it writes nothing**; and the reference ids, including
   which account `accountPlanMapId` actually names in this company's chart. It ends with READY,
   PARTLY READY or NOT READY and the reason.

   The counts at the end of each register are the lines to read. `services: 0` means the scope
   is wrong, not that the clinic sells nothing.

5. **Hook the save handlers.** After a successful add and after a successful edit — where
   the code already has the id and is about to redirect:

   ```php
   $this->load->library('rilven');

   // controllers/admin/Customers.php
   $this->rilven->queue($id);            // $id = sma_companies.id just saved

   // controllers/admin/Categories.php
   $this->rilven->queueCategory($id);    // $id = sma_categories.id just saved

   // controllers/admin/Products.php
   $this->rilven->queueService($id);     // $id = sma_products.id just saved
   ```

   And on the sale side, where the guard goes **before** the write rather than after it:

   ```php
   // controllers/admin/Sales.php -- at the top of the EDIT handler
   if ($this->rilven->isPostedInRilven($id)) {     // $id = sma_sales.id
       // the accrual is in the books; the case must not change underneath it
       redirect($_SERVER['HTTP_REFERER']);
   }

   // ...and after a successful save, add or edit
   $this->rilven->queueSale($id);
   ```

   `isPostedInRilven()` reads the queue row and sends no request, so it cannot hang and cannot
   fail the save; it answers FALSE for anything it has not positively seen confirmed. See
   `upgrade/README.md` for how it sits beside `rilven_sale_unpost`.

   None of the three throws and none of them blocks: a patient must be saveable while Rilven
   is down. **Each ignores a row outside its own scope** — a goods category, a product that is
   not a service, a counterparty that is not a patient — so the same line is safe wherever
   those records are saved, and there is no need to work out first whether this one counts.

6. **Add the cron**, once a minute, and **under a lock**:

   ```
   * * * * *  /usr/bin/flock -n /var/lock/rilven-sync.lock -c 'cd /home/www/public && php index.php admin/rilven_sync cron' >> /var/log/rilven-sync.log 2>&1
   ```

   The lock is not a precaution, it is a repair. Without it this line ran every minute
   while a run took two and a half, so two processes held the same queue rows at once.
   `requireFree` is a read-then-write with no unique index behind it, and both were told
   the code was free: 44 counterparties were created twice, in the same millisecond, with
   consecutive ids. `-n` means a tick that finds the lock held gives up rather than
   queueing behind it — the next minute will do the work, and a backlog of waiting PHP
   processes is how the first failure got as far as it did.

7. **Set `rilven_enabled = TRUE`.** Until then the hooks still queue — so nothing is lost
   while the rest is being set up — and the cron sends nothing.

## The service reference

Two registers, and the order between them is not negotiable: **a service carries its
category's Rilven id**, and this side learns that id only by asking `/category/get/{code}?by=code`.
So the run does the categories first, and a service whose category has not arrived yet is
refused with `category-not-synced-yet` and **left in the queue** rather than sent
uncategorised — a service that landed without a category is one nothing would ever come back
to fix.

**The two scopes are set independently and nothing makes them agree.** Services are
`sma_products WHERE service = 1`; service categories are `sma_categories WHERE product_type = 2`.
A service pointing at a category that is not a service category waits for one that never arrives,
and spends its ten attempts finding that out.

Measured on LJ, 2026-09-19: **2 101 of 2 106 services sit in one of the seven service categories,
and 5 do not** — three in სხვა, one in საკანცელარიო, one in უსასყიდლო, all goods categories. So
`rilven_service_require_category` ships as **FALSE**: those five go without a category and say so,
rather than being given up on and missing from the reference entirely. `check` re-counts it
(`outside a synced category`) — if it ever becomes a large number the answer is not the flag, it
is that the two scopes have drifted and somebody has to look.

Two more things that column knows and a reader might not:

* **`service = 99` means DELETED** (those rows also carry `type = 'delete'`). Testing for `= 1`
  rather than for "not 0" is what keeps them out — they must not be sent, and not as inactive
  either.
* **`hide` is the inactive flag, and it reads the opposite way from a status column.** It is set
  on 416 of the 2 103 services. `rilven_service_hidden_column` is deliberately separate from
  `rilven_service_fields['status']` rather than a flag inverting it: read one as the other and
  every withdrawn service comes back as active, which a receptionist can still pick. An inactive
  service is **sent, never omitted** — last year's documents still point at it.

What decides where they post is `accountPlanMapId`: not an account, but a row of
`tb_account_plan_map` — an account IN A CLASS — because the class decides how the documents
built on it post. For a clinic's services that is the income class (type 3), which is what the
accrual credits:

```
Начисление услуги    Дт 1410 пациент    Кт 6110    полная стоимость услуги
```

For this clinic that is **map id 38 = account 6110 Revenue from sales, class 3**. Note class 3
(`INCOME`) and **not 13** (`OPERATING_INCOME`, a financial-statement class): they read alike, and
binding a service to the second posts it somewhere nobody chose.

Pin it as `rilven_category_account_plan_map_id` when somebody has read it off the category
screen (`/reference/category/details/114` → `accountPlanMapId: 38`, `type: 3`), or leave it NULL
and let it be resolved from `rilven_category_account_code` + `rilven_category_account_type` —
which is what makes the config file copyable to the next clinic, since the map id differs
between installations and "6110, in the income class" does not. Either way `check` prints which
account the id actually names in **this company's** chart, and says so when it names none.

Two things this register has to be careful about, both of them the far side's shape and neither
of them fixable from here:

* **`/category/update` replaces the specifications wholesale.** It deletes every binding of the
  category *before* it looks at what was sent, so an update without them erases what somebody
  bound over there. `Rilven_category` reads them back out of the same `/get` it used to find the
  category and hands them straight back. The contractor's branch has the same shape and is
  handled the same way.
* **`/asset-service/update` replaces the whole service.** A measure or a settlement type an
  accountant chose over there is handed back for the same reason, whenever this clinic's config
  does not manage that field itself.

`vatType` is **required** — 1 standard, 2 tax-free (exempt turnover, still declared), 3 no VAT
at all. **3** is what the earlier version of this integration sent for all 2 103 services, as the
value standing for "Georgian medical services, exempt", and it is kept rather than re-derived.

It is an accounting decision and not a technical one, and 2 vs 3 is a real difference: exempt
turnover is declared and turnover outside VAT is not. The accrual carries no VAT leg either way,
so nothing in this sync will reveal a wrong choice — the VAT declaration will. Worth putting to
the clinic's accountant once. Until `rilven_service_vat_type` is set, `check` says NOT READY and
nothing is sent.

## The first run

`backfill()` tops up **every** enabled register. For the two service registers that is simply
everything in scope the outbox has never heard of — a reference is a few dozen categories and a
few thousand services, and "has anybody used it" is not worth asking.

For patients it queues the ones that have **financial movement** — a sale — and that the
outbox has never heard of. A clinic's register holds everybody who ever walked in; the
accounting only wants the ones there are documents for. Somebody with no movement arrives on
their first invoice, because saving that invoice queues them. Set `rilven_movement` to NULL
to send everybody.

It is bounded and resumable, and **it keeps no cursor**. The outbox itself is the record of
what is known, asked with a `NOT EXISTS` on the very pair its unique key enforces. A
high-water mark was tried and was silently wrong: it is shared between kinds, and one sweep
pushed it past the top of one sequence and put every row below it permanently out of reach.

## Watching it

`/admin/rilven_sync` answers JSON: whether the integration is on, when it last signed in,
how many rows sit in each state with the age of the oldest, and the last 50 failures with
the reason Rilven gave. **The oldest pending row's age is the number that matters** — a
count alone looks healthy right up until it stops moving.

The cron prints its refusals grouped by reason, with a count and one example. CI's own
logging is switched off on these installations, so that output *is* the log; before it
existed, fifty-five rows refused for one fixable reason looked exactly like a quiet day.

## What a failure means

| | |
|---|---|
| `email-address-or-password-is-invalid` | the credential. A retry will never fix it, and the run stops rather than repeating it |
| `account-is-temporarily-locked…` | more than 30 failed sign-ins. Wait, then fix the password — do not loop |
| `[permission]-denied` | the account has not been granted that route. See the note below |
| `system-error[0002.0001]` / `[0001.0000]` | no session. The library signs in again by itself, once, and repeats the call |
| `system-error[0002.0002]` | the device is not confirmed — the account is not marked as a service account over there |
| `[code]-format-is-invalid` | the id would not make a legal code. Almost always `rilven_code_prefix` |
| `duplicate-code` | another counterparty over there already answers to this patient's id |
| `duplicate-tax-code` | two patients here share an identification number; a person has to decide which is which |
| `[legalFormId]-is-required` | the legal form could not be resolved — pin `rilven_legal_form_id` |
| `source-row-has-no-name` | the patient has no name in any of the mapped columns |
| `no-usable-tax-code` | no real identification number and `rilven_taxcode_fallback` is empty |
| `branch-skipped: …` | the counterparty went through; only its contact details did not |
| `category-not-synced-yet: N` | the service's category has not reached Rilven. Retryable, and it clears itself once the category register catches up — if it does not, the categories are stuck and that is the thing to look at |
| `source-row-has-no-name` | the category or service has no name in the mapped column |
| `[vatType]-is-required` | `rilven_service_vat_type` is not set |
| `[accountPlanMapId]-is-invalid` | the pinned map id is not in this company's chart, or not in a class a category may be bound to. `check` prints which account it names |

### When Rilven no longer has what the outbox says it has

`php index.php admin/rilven_sync resend yes` re-opens **every** row of every register,
delivered ones included, and clears the payload hashes. Naming a register — `resend yes service`
— limits it to that one, which is almost always what is wanted: the patients are seventy
thousand requests and the services are a few thousand. It exists for one situation: the counterparties
were removed over there while this queue went on reporting them as sent — which happened on
2026-09-16 and left the sync inert and looking healthy. `retry` does not cover it, because
those rows never failed. It queues the whole register, so it asks for the word `yes`.

`retry` takes a register the same way: `php index.php admin/rilven_sync retry service`.

A refusal that names a field or a duplicate is **given up on at once** rather than retried:
it will say the same thing in ten minutes, and nine more identical attempts only bury it.
Retries are for the ones where the answer could change — the network, a restart, a dead
session. `php index.php admin/rilven_sync retry` re-opens everything that was given up on,
once the cause has been fixed.

## What has to be true on the Rilven side

This library cannot arrange any of these; they are the other system's to grant.

1. **The service account and its password.** `rilven_client_id` signs in like a person does
   and is then subject to the same checks. It must be marked as a service account over
   there, or its login device is born unverified and every business call answers
   `system-error[0002.0002]`.
2. **The routes have to be granted to that account.** At the time of writing the grant
   covers `ContractorController` but **not `ContractorBranchController`** — so the second
   route answers `[permission]-denied` and only the contact details that ride along with a
   new counterparty get through. The doctor's `== routes ==` section says which of them is
   which, and that is exactly the failure it was written to make visible.

   The service registers need three more: `CategoryController`, `AssetServiceController` and
   read access to `AccountPlanController` (`/account-plan/map/filter`). **Whether they are
   granted is not known at the time of writing** — the doctor is what answers it, and it
   answers without writing anything.
3. **A counterparty kind with code `patient`** must exist in that company, or
   `rilven_contragent_type_id` cannot be resolved and patients land on the default
   settlement account.
4. **The reference routes** (`/country/filter`, `/legal-form/filter`,
   `/contragent-type/list-all`, `/state/filter`, `/city/filter`) are only needed to resolve
   ids automatically. If they are not granted, pin the ids in the config instead — the
   doctor prints which ones it could not resolve, and everything else keeps working.

## Requirements

PHP 5.6+, CodeIgniter 3, cURL. Written without short closures, null coalescing or type
declarations on purpose — these installations are forks that have drifted for years and some
of the hosts will not be upgraded for this. Every source column is read through a map and a
tolerant accessor, so a fork without `mobphone`, `middlename` or a date of birth still syncs
rather than fatals.

## This repository, and the one file that must never be copied out of it

The library is four thousand lines of PHP that get copied into somebody else's CodeIgniter
application. It had no version control until 2026-09-21, and that day a deploy command run on the
wrong machine emptied two files — `scp file host:/that/same/dir/` run ON that host copies the file
onto itself, truncating it, and answers `100%`. There were no backups on either side. One file was
rewritten from scratch; the other was recovered only because an old copy happened to survive at
`/root/rilven-sync/` on the clinic's server.

Hence this repository. It exists so that a lost file is an inconvenience rather than an
archaeology problem.

**`application/config/rilven.php` here is a TEMPLATE.** Its `rilven_secret_key` is empty and its
flags are whatever suited the last person to edit the template — not what the clinic is running.
Copying it over a live installation replaces the password with a blank and the settings with
defaults; that happened on 2026-09-20 and stopped the sync for half an hour. Carry a change to a
server by editing the server's copy, or by diffing the two and applying only the lines that are
not credentials.

### Deploying

Every path absolute, and the machine named. `scp` runs on the DEV machine; anything touching
`/home/www/public` runs on the server. Take the md5 of both ends before and after —
`d41d8cd98f00b204e9800998ecf8427e` is the md5 of an empty file, and seeing it means a transfer
destroyed something.
