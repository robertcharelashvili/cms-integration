<?php
/*
 * ---------------------------------------------------------------------------
 * THIS IS A TEMPLATE. DO NOT COPY IT OVER A CONFIGURED INSTALLATION.
 *
 * `rilven_secret_key` and `rilven_api_key` are EMPTY here and hold real values
 * only on the machine that runs the sync. Copying this file onto a server
 * replaces its credentials with blanks, and the next tick stops with
 * "not configured: url, company id or credentials are missing" -- which names
 * three things and so reads like a broken installation rather than one
 * overwritten field. It happened on 2026-09-20: the file was deployed for a
 * corrected comment, and the clinic's sync was down from 20:05 until the
 * password was typed back in by hand.
 *
 * To carry a change from here to a server, edit the server's copy, or diff the
 * two and apply only the lines that are not credentials.
 * ---------------------------------------------------------------------------
 */
 defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Rilven connection -- everything that differs between one clinic and the next.
 *
 * The libraries are copied unchanged from installation to installation; this file is
 * the only one that is edited. Keep it out of any public web root, as CodeIgniter's
 * config directory already is: it holds the credential.
 *
 * After editing, run the doctor -- it says which of these values the server and the
 * clinic's own database actually agree with:
 *
 *     php index.php admin/rilven_sync check
 */

// ---------------------------------------------------------------------------
// 1. Where Rilven is
// ---------------------------------------------------------------------------

// No trailing slash, and NOT the address people type into a browser.
//
// console.rilven.com is the ERP's own front end: it answers every GET with the
// single-page application, including GETs under /api/v1, and refuses POST with an
// nginx 405. A library pointed at it reads HTML where it expects an answer and every
// lookup silently reports "not found". app.rilven.com is the API. Verified against
// both, 2026-09-17.
$config['rilven_url'] = 'https://app.rilven.com';

// The version prefix every business route sits under. Split from the URL so a
// clinic pointed at a staging box does not have to re-type the version too.
$config['rilven_api_base'] = '/api/v1';

// Verify the server's certificate. Turning this off makes the credential below
// interceptable by anything on the path; only ever do it against a test box with a
// self-signed certificate, and say so in a comment when you do.
$config['rilven_verify_ssl'] = TRUE;

// ---------------------------------------------------------------------------
// 2. Who this installation is -- the credentials
// ---------------------------------------------------------------------------

// Which company's books this clinic writes into. Travels as the `Company-Id`
// header on every single request; a call without it is refused before it reaches a
// handler. One installation writes into exactly one company.
$config['rilven_company_id'] = 1022;

// The zone the CMS's own dates are written in. Asia/Tbilisi for a clinic in Georgia.
//
// Rilven's backend is UTC throughout and its console converts on the way out using the company's
// legal offset, so an instant must leave here as UTC. Sent as a bare local string it was read as
// UTC and shown four hours late: measured 2026-09-21, CMS 12:55 -> Rilven 16:55.
//
// Named here rather than taken from PHP on purpose. This process does not agree with itself about
// the time -- the cron's PHP runs in the clinic's zone, an interactive one in UTC -- and a
// conversion that leaned on the ambient setting would send a different instant depending on who
// started the script.
//
// Empty sends the CMS's wall clock unconverted, which is what this did before the key existed.
$config['rilven_source_timezone'] = 'Asia/Tbilisi';

// How this installation proves who it is.
//
//   'user' -- sign in through /id/sign-in with `rilven_client_id` as the e-mail and
//             `rilven_secret_key` as the password. The answer is a pair of cookies,
//             kept in sma_rilven_state and reused for a year: the CMS signs in ONCE,
//             not on every cron tick. THIS IS THE ONLY MODE RILVEN ACCEPTS TODAY on
//             the contractor routes.
//
//             The account MUST carry tb_user.is_service = true. Every sign-in opens a
//             new device, born unverified, and an unverified device is refused every
//             endpoint but five -- so without the flag the login succeeds and nothing
//             else does. The flag is set by hand in the database, never through the
//             API: see db/service_account.sql in com.rilven.api.
//
//   'key'  -- present a standing credential in headers and never sign in. The
//             transport is written and tested, but no route under /api/v1 accepts a
//             key at the time of writing (only the MCP surface at /mcp/* does), so a
//             run in this mode answers `[permission]-denied` or a dead session on
//             every call. Leave it on 'user' until Rilven says otherwise.
$config['rilven_auth_mode'] = 'user';

// The client identity. In 'user' mode this is the service account's e-mail address;
// in 'key' mode it is the client id issued with the key.
$config['rilven_client_id'] = 'lj-cms@rilven.local';

// The secret that goes with it: in 'user' mode the account's password, in 'key' mode
// the client secret. Never logged, never echoed by the status page.
$config['rilven_secret_key'] = '';

// A standing API key, sent as a header whenever it is filled in -- in either mode,
// because a deployment that gates the whole API behind a gateway key still needs the
// login to carry it. Leave empty when there is none.
$config['rilven_api_key'] = '';

// The header names the two travel in. Configurable because a gateway in front of
// Rilven may want its own spelling; the defaults are what Rilven itself reads.
$config['rilven_api_key_header']   = 'X-Rilven-Api-Key';
$config['rilven_client_id_header'] = 'X-Rilven-Client-Id';

// ---------------------------------------------------------------------------
// 3. Switches, batching, timeouts
// ---------------------------------------------------------------------------

// Master switch. OFF still queues -- so nothing is lost while somebody is still
// filling this file in -- and sends nothing.
$config['rilven_enabled'] = TRUE;

// How many queued patients one chunk claims. Each one is its own small request, so
// this is not a payload size; it is how often the run stops to look at the clock.
$config['rilven_batch'] = 200;

// The ceiling for one cron run, so a first catch-up of seventy thousand patients
// cannot run into the working day. 0 means no limit.
$config['rilven_max_rows'] = 2000;

// How many times a row that failed for a RETRYABLE reason is tried again before it
// is given up on. A row refused for its own content (a missing field, a duplicate)
// is given up on at once, whatever this says -- retrying it would only repeat the
// same sentence.
$config['rilven_max_attempts'] = 10;

// Seconds. Generous on connect, patient on read.
$config['rilven_connect_timeout'] = 10;
$config['rilven_timeout']         = 60;

// Milliseconds to wait between requests. 0 for a link that can take it; raise it if
// the clinic's outbound bandwidth is shared with something that matters more.
$config['rilven_throttle_ms'] = 0;

// Where the cron writes its own log. CI's log_message() is switched off on these
// installations, so this is the log that exists. Empty means stdout only, which is
// what the crontab line redirects.
$config['rilven_log_file'] = '';

// ---------------------------------------------------------------------------
// 4. Which rows of this database are patients
// ---------------------------------------------------------------------------

// The counterparty table, WITHOUT the prefix -- CodeIgniter's query builder adds
// `sma_` (or whatever this installation uses) by itself.
$config['rilven_source_table'] = 'companies';

// What makes a row a patient here. Every pair is ANDed. This is the whole scope of
// the integration: suppliers, insurers and billers are not sent by this library.
$config['rilven_patient_where'] = array(
    'group_id'   => 3,
    'group_name' => 'customer',
);

// The catch-up only queues patients with financial movement: the register holds
// everybody who ever walked in, and the books only want the ones there are documents
// for. Somebody with no movement arrives on their first invoice, because saving that
// invoice queues them. Set to NULL to send every patient regardless.
$config['rilven_movement'] = array('table' => 'sales', 'column' => 'customer_id');

// ---------------------------------------------------------------------------
// 5. Which column holds what
// ---------------------------------------------------------------------------

// Every value the library reads from sma_companies, named by what it MEANS rather
// than by what the column is called -- forks have drifted and the names differ.
// A column that does not exist on this installation is simply not sent; nothing
// fatals. `check` prints the real column list beside this map.
//
// THE NAME COLUMNS ARE THE ONE TO GET RIGHT. The stock product keeps a given name in
// `name`; the LJ clinic keeps the SURNAME there and the GIVEN name in `company`.
// Get it the wrong way round and the whole register fills with half-names.
// Look before setting it:  SELECT name, company, middlename FROM sma_companies LIMIT 5
$config['rilven_fields'] = array(
    'family_name' => 'name',
    'given_name'  => 'company',
    'middle_name' => 'middlename',
    'tax_code'    => 'vat_no',
    'phone'       => 'phone',
    'phone_alt'   => 'mobphone',
    'email'       => 'email',
    'address'     => 'address',
    'address2'    => 'address2',
    'gender'      => 'sex',
    // 'dob' on a stock install; this register calls it date_of_birth. `check` prints the
    // real column list of this installation beside this map, which is how to settle it.
    'birthday'    => 'date_of_birth',
);

// The order the parts are joined in to make the counterparty's legal name, and its
// brand name (what the pickers over there show). Keys of `rilven_fields`.
$config['rilven_name_order']  = array('given_name', 'family_name');
$config['rilven_brand_order'] = array('given_name', 'family_name');

// Send sex and date of birth. They are real columns on the counterparty over there,
// and for a clinic they are worth having -- but only for counterparties who are
// PEOPLE, which is exactly what this library sends and nothing else.
$config['rilven_person_fields'] = TRUE;

// ---------------------------------------------------------------------------
// 6. What a patient becomes over there
// ---------------------------------------------------------------------------

// The country the counterparty is registered in. Leave the id NULL to have it
// resolved once by ISO code and remembered -- which is what makes this file copyable
// to the next clinic, since the id differs between installations and the ISO code
// does not.
$config['rilven_country_iso'] = 'GE';
$config['rilven_country_id']  = 81;

// The legal form, required for a resident counterparty. For a private person in
// Georgia that is ფპ (ფიზიკური პირი). Resolved from the short code when the id is
// left NULL.
$config['rilven_legal_form_code'] = 'ფპ';
$config['rilven_legal_form_id']   = 1;

// The kind of counterparty, which is what decides the settlement account a patient's
// invoices land on over there. Resolved from the code when the id is left NULL; if
// no kind with this code exists in that company, one has to be created there first.
$config['rilven_contragent_type_code'] = 'patient';
$config['rilven_contragent_type_id']   = 1;

// Optional: the price group every patient is put in. NULL sends none.
$config['rilven_price_group_id'] = NULL;

// Stored as given -- it does not decide anything by itself, and a patient of a
// clinic is a resident until somebody says otherwise.
$config['rilven_is_resident'] = TRUE;

// A patient is not VAT-registered. This is the value sent for every one of them.
$config['rilven_vat'] = FALSE;

// ---------------------------------------------------------------------------
// 7. The tax code
// ---------------------------------------------------------------------------

// What a real identification number looks like HERE. A Georgian personal number is
// nine or eleven digits; this register also holds '-', '123' and four-digit internal
// numbers in the same column, and sending those as real codes makes every one of
// them a duplicate of the next. A value that fails this test is NOT sent as a code.
// Set to NULL to send whatever the column holds.
$config['rilven_taxcode_pattern'] = '/^[0-9]{9}$|^[0-9]{11}$/';

// The counterparty route requires a tax code and will not take a blank one, so a
// patient whose number is missing or junk needs something to stand in its place.
// `{id}` is sma_companies.id, which is unique by construction and recognisable at a
// glance as a placeholder rather than a real number.
//
// Set to NULL or '' to skip such a patient instead. That is the stricter choice and
// it means the patient never reaches the books at all -- including their invoices.
$config['rilven_taxcode_fallback'] = 'cms-{id}';

// ---------------------------------------------------------------------------
// 8. The link between the two systems
// ---------------------------------------------------------------------------

// The identity of a patient over there is `code` on the counterparty record
// (tb_contractor_data.code), and its value is sma_companies.id. That is the whole
// binding: no map table on either side, and a lookup by our own id at any time
// through GET /contractor/get/{code}?by=code.
//
// A prefix is available for an installation that shares one Rilven company with
// another source system and needs the two id spaces kept apart -- 'lj-' gives
// 'lj-71090'. It becomes part of the key, so changing it later orphans every record
// already sent. The whole value must match ^[a-z0-9]+(-[a-z0-9]+)*$; Rilven refuses
// anything else rather than reshaping it, because a code it altered is one the next
// lookup from here would not find.
$config['rilven_code_prefix'] = '';

// ---------------------------------------------------------------------------
// 9. The branch
// ---------------------------------------------------------------------------

// A counterparty's contact details live on its BRANCH over there, not on the
// counterparty, and a posting resolves a branch. Every counterparty gets a default
// branch at creation carrying whatever phone, e-mail and address were sent with it;
// this switch is about keeping that branch up to date afterwards, through the
// second route.
//
// The branch route demands a state and a city, which the clinic's register does not
// hold. Fill the two ids in -- `check` resolves them from the names below and prints
// them -- or leave this FALSE and let the details ride along with the counterparty
// at creation only.
$config['rilven_branch_enabled'] = FALSE;

$config['rilven_branch_state_id'] = NULL;
$config['rilven_branch_city_id']  = NULL;

// Used by `check` to resolve the two ids above. Names, as that country's list
// spells them.
$config['rilven_branch_state_name'] = 'Tbilisi';
$config['rilven_branch_city_name']  = 'Tbilisi';

// The branch route also demands an address, and a good share of patients have none.
// This is what is sent for them; it may not be blank while the branch sync is on.
$config['rilven_branch_address_fallback'] = '-';

// Give the branch the same code as the counterparty. It is what makes "which of
// these branches is the one this CMS owns" answerable when somebody over there has
// added a second one by hand. Branch codes are unique per company, and one patient
// has one branch, so they do not collide.
$config['rilven_branch_code'] = TRUE;

// ---------------------------------------------------------------------------
// 10. The service reference: categories
// ---------------------------------------------------------------------------
//
// sma_categories holds the categories of everything the clinic sells and is TYPED:
// product_type = 2 is the service side of it, which is the whole scope here. Goods
// categories belong to a different account class and are not sent.
//
//     SELECT id, code, name FROM sma_categories WHERE product_type = 2;
//
// is what this register reads -- `id` and `name`. NOT `code`: see rilven_category_fields.

// Master switch for this register. Off queues nothing and resolves nothing.
$config['rilven_category_enabled'] = TRUE;

// The category table, WITHOUT the prefix -- the query builder adds `sma_` itself.
$config['rilven_category_source_table'] = 'categories';

// What makes a row a SERVICE category here. Every pair is ANDed, and this is also what the
// services' own scope is built from, so the two cannot drift apart.
$config['rilven_category_where'] = array(
    'product_type' => 2,
);

// Which column holds what. Only the name is read.
//
// THE `code` COLUMN OF sma_categories IS DELIBERATELY NOT HERE. Over there `code` is the
// outside system's own IDENTIFIER for the record and it is what this library looks the
// category up by; sma_categories.code is a label a person may edit at any time. An earlier
// version of this integration sent the source's own `code` for services and 2103 services out
// of 2105 stopped matching. The id is the identity -- see rilven_category_code_prefix. The
// specification says the same: "редактируемое пользователем поле code не использовать как
// единственный ключ синхронизации".
$config['rilven_category_fields'] = array(
    'name' => 'name',
);

// The identity of a category over there is `code` = sma_categories.id.
//
// Category codes are unique per company among CATEGORIES only, so a category and a patient may
// both be "114" without colliding -- each register has its own namespace and its own prefix.
// The prefix becomes part of the key, so changing it later orphans everything already sent.
$config['rilven_category_code_prefix'] = '';

// The account CLASS every service category is bound to -- a row of tb_account_plan_map, NOT an
// account. The class decides how the documents built on the category post; for a clinic's
// services that is the income class, which is what the accrual credits (Дт 1410 / Кт 6110).
//
// Pin the id when somebody has looked it up on the category screen, which is the normal case:
//
//     https://console.rilven.com/reference/category/details/114  ->  accountPlanMapId 38, type 3
//
// Leave it NULL to have it resolved from the account NUMBER and the class below, which is what
// makes this file copyable to the next clinic: the map id differs between installations and
// "6110, in the income class" does not. `check` prints what it resolved and verifies a pinned
// id against the company's own chart.
$config['rilven_category_account_plan_map_id'] = 38;

// Used to resolve and to VERIFY the id above. The account number as that company's chart
// spells it, and the class type: 3 is income. (1 expense, 2 goods, 3 income, 5 benefit
// expense, 7 fixed asset -- those are the five a category may be bound to.)
$config['rilven_category_account_code'] = '6110';
$config['rilven_category_account_type'] = 3;

// ---------------------------------------------------------------------------
// 11. The service reference: the services themselves
// ---------------------------------------------------------------------------
//
// The CATALOGUE entry, not the fact that a service was performed. A service performed is a row
// of sma_sale_items with its own service_instance_id, date, patient and amount; this register
// is about the thing `sale_items.product_code` names.

// Master switch for this register.
$config['rilven_service_enabled'] = TRUE;

// The product table, WITHOUT the prefix.
$config['rilven_service_source_table'] = 'products';

// What makes a product a SERVICE here.
//
// `service = 1` is this register's own word for it. Measured against the live dump 2026-09-19:
// service=0 -> 9 990 goods, service=1 -> 2 106 services, service=99 -> 872 deleted. It agrees
// exactly with the `type` column (2 106 rows carry type = 'service'), and NO row is both
// service = 1 and type = 'delete' -- so this one test is enough and the two cannot disagree.
//
// **`service = 99` is that column's word for DELETED.** Testing for = 1 rather than for "not 0"
// is what keeps those 872 out: they must not be sent, and not as inactive either.
$config['rilven_service_where'] = array(
    'service' => 1,
);

// Scope services by their category's product_type INSTEAD of by the test above.
//
// FALSE here because `service = 1` is the real discriminator on this fork. Set it to TRUE on a
// fork that has no such column, where sitting in a service category is the only thing that makes
// a product a service -- and then `rilven_service_where` may be left empty.
//
// It is only the SCOPE this decides. Whichever way it is set, a service still carries its
// category and still waits for it: see rilven_service_require_category.
$config['rilven_service_via_category'] = FALSE;

// What to do with a service whose category has not reached Rilven.
//
// TRUE leaves it in the queue -- `category-not-synced-yet` -- rather than creating it
// uncategorised. FALSE sends it anyway, with a note.
//
// **FALSE here because it was measured.** Of LJ's 2 106 services, 2 101 sit in one of the seven
// service categories and 5 do not: three in სხვა (1696), one in საკანცელარიო (1695) and one in
// უსასყიდლო (1691), all three of which are GOODS categories (product_type = 1) and so are not
// this library's to send. With TRUE those five wait for a category that is never coming, spend
// ten attempts each and are given up on -- five services simply missing from the reference, and
// a document cannot be built on a service that is not there. Uncategorised is the lesser loss,
// and the note says which they were.
//
// Re-measure before assuming this still holds: `check` prints "outside a synced category". If it
// ever becomes a large number, the answer is not this flag -- it is that the two scopes have
// drifted apart and somebody has to look.
$config['rilven_service_require_category'] = FALSE;

// Which column of the product points at its category.
$config['rilven_service_category_column'] = 'category_id';

// Which column holds what. `status` is a column where a truthy value means ACTIVE; leave it NULL
// on a fork that has none, in which case every service is sent as active -- see
// rilven_service_status.
//
// As with the categories, the product's own `code` column is NOT the key. The id is. (It is also
// unusable as one here: nine services keep a DESCRIPTION in that column, up to 142 characters
// against a limit of 128, and two differ only by leading spaces.)
$config['rilven_service_fields'] = array(
    'name'   => 'name',
    'status' => NULL,
);

// A column that means the opposite: truthy = HIDDEN = inactive. This register spells it `hide`
// -- tinyint(1) NOT NULL DEFAULT 0 -- and it is set on 417 of the 2 106 services.
//
// Separate from `status` above rather than a flag inverting it, because the two read opposite
// ways and one of them silently inverting the other is precisely the bug this avoids: an
// inactive service sent as active is one a receptionist can still pick. An inactive service is
// still SENT -- never omitted -- because last year's documents still point at it.
//
// Set to NULL on a fork with no such column. When both are set, this one decides.
$config['rilven_service_hidden_column'] = 'hide';

// The identity of a service over there is `code` = sma_products.id. Its own namespace, like the
// categories'.
$config['rilven_service_code_prefix'] = '';

// The account CLASS a service posts through. NULL means "the same one the categories use",
// which is the normal case: both the category and the service on it credit income.
//
// For this clinic that resolves to 38 = account 6110 Revenue from sales, in the INCOME class
// (type 3). Note type 3 and NOT 13: 13 is "operating income", a financial-statement class, and
// binding a service to that one posts it somewhere nobody chose. The earlier version of this
// integration got this exact pair right and it is worth not re-deriving.
$config['rilven_service_account_plan_map_id'] = NULL;

// VAT. REQUIRED -- the route will not take a service without one, so nothing is sent until this
// is set, and `check` says so rather than refusing two thousand rows one at a time.
//
//   1  standard    -- VAT is charged at the standard rate
//   2  tax-free    -- exempt turnover: no VAT is charged, but the turnover IS declared
//   3  no VAT      -- outside VAT altogether
//
// 3 is what the earlier version of this integration sent for all 2 103 services, as the value
// standing for "Georgian medical services, exempt". It is kept so that this register does not
// quietly re-state the clinic's VAT position while its purpose is to sync a catalogue.
//
// IT IS AN ACCOUNTING DECISION AND NOT A TECHNICAL ONE, and 2 vs 3 is a real difference: exempt
// turnover is declared and turnover outside VAT is not. The accrual carries no VAT leg either
// way, so nothing here will reveal a wrong choice -- the VAT declaration will. Worth putting to
// the clinic's accountant once rather than inheriting.
$config['rilven_service_vat_type'] = 3;

// Optional. 1 prepayment, 2 payment after delivery, 3 partial payment. NULL sends none, and on
// an update keeps whatever was chosen over there.
$config['rilven_service_settlement_type'] = NULL;

// Optional: the unit every service is measured in. NULL sends none, and on an update keeps
// whatever was chosen over there.
$config['rilven_service_measure_id'] = NULL;

// Whether the price is typed on the DOCUMENT rather than taken from a per-counterparty price
// list. TRUE, and it is not a preference -- it is what makes a service sale possible at all.
//
// WaybillController.insertServiceItems counts the lines whose service has variable_price = FALSE
// and refuses the whole document unless each of them names a contractorBranchServiceId:
//
//     if (contractorServiceCount > 0 && contractorServiceIds.isEmpty())
//         throw new AppException("[contractorBranchServiceId]-is-invalid");
//
// A contractorBranchService is a price AGREED WITH ONE COUNTERPARTY. This clinic's prices come
// from the CMS, per case, and its counterparties are seventy thousand patients -- so FALSE would
// mean one agreed price per patient per service, tens of thousands of records standing for
// nothing. TRUE says the truth instead: the price is on the line.
//
// Shipped FALSE at first and every case answered [contractorBranchServiceId]-is-invalid.
// Changing it means re-sending the services: `resend yes service`.
$config['rilven_service_variable_price'] = TRUE;

// What a service's status is when no column carries one.
$config['rilven_service_status'] = TRUE;

// ---------------------------------------------------------------------------
// 12. Patients
// ---------------------------------------------------------------------------

// Master switch for the patient register, which is everything sections 4 to 9 describe.
// Defaults to TRUE: an installation whose config predates the service registers goes on doing
// exactly what it did before this switch existed.
$config['rilven_contractor_enabled'] = TRUE;


// ---------------------------------------------------------------------------
// FINANCIERS -- group 5, the people who pay instead of the patient
// ---------------------------------------------------------------------------
//
// The same two routes and the same link as a patient (`code` = sma_companies.id), which is why
// Rilven_insurer is Rilven_contractor with these keys redirected rather than a copy of it. Only
// what DIFFERS is written here; everything left out falls through to the patient's setting.
//
// OFF by default. An installation upgraded to this version must not start writing a second kind
// of counterparty into Rilven because it was upgraded.
$config['rilven_insurer_enabled'] = FALSE;

// Measured on the live dump 2026-09-21: 222 rows, and the group is wider than its name. Beside
// the insurers it holds the state health programme, city halls, and placeholders -- 'shida',
// 'ფიზიკური პირი'. All of them are financiers in the sense that matters: somebody other than the
// patient settles. Narrow this if the clinic wants only the ones that carry money.
$config['rilven_insurer_where'] = array(
    'group_id'   => 5,
    'group_name' => 'insurance',
);

// NOT the patient map, and the difference is not cosmetic. For a patient `name` is the surname
// and `company` the given name; for these rows `company` holds a NUMBER -- 50, 51, 52 -- and
// only `name` is the organisation. Inheriting the patient map would have called them "50 სს
// იმედი L". 214 of 222 differ this way; two agree by accident.
$config['rilven_insurer_fields'] = array(
    'family_name' => 'name',
    'tax_code'    => 'vat_no',
    'phone'       => 'phone',
    'phone_alt'   => 'mobphone',
    'email'       => 'email',
    'address'     => 'address',
    'address2'    => 'address2',
);
$config['rilven_insurer_name_order']  = array('family_name');
$config['rilven_insurer_brand_order'] = array('family_name');

// An organisation, not a person. Sex and date of birth are not sent -- doing it once already
// stamped a sex onto two hundred insurance companies, and an update cannot unsay a value.
$config['rilven_insurer_person_fields'] = FALSE;

// The type seeded by db/contragent_type_seed.sql: receivable 1410, is_person false. The same
// account as a patient's, which is what the accrual model needs -- the financier's share moves
// from the patient to the financier WITHIN 1410.
$config['rilven_insurer_contragent_type_code'] = 'insurance';
// Given OUTRIGHT, like the patient's, so the register never asks. Resolving it would need
// /contragent-type/list-all and /legal-form/filter granted to the integration, and a right
// nothing else uses only widens what a stolen credential can do. Measured on the clinic
// 2026-09-21: the insurance type is 2, and so is შპს.
$config['rilven_insurer_contragent_type_id']   = 2;

// EMPTY ON PURPOSE, and it must stay empty unless somebody knows better. The group holds joint
// stock insurers, a state programme and municipalities; they have no single legal form, and an
// empty code sends none at all rather than the wrong one. Left to fall through it would inherit
// the patient's 'ფპ' and file two hundred organisations as natural persons.
// შპს -- the clinic's answer, 2026-09-21. It was empty before, on the grounds that the group
// mixes joint-stock insurers, a state programme and municipalities and no single form fits; the
// clinic would rather they were all the ordinary company form than have none.
$config['rilven_insurer_legal_form_code'] = 'შპს';
$config['rilven_insurer_legal_form_id']   = 2;

// 217 of 222 have no usable tax code -- the column holds '-', '1', '123', '1122', and only FIVE
// are a real nine-digit number. So identity is `code` and the rest get the shared cms-{id}
// fallback, which is unique because sma_companies.id is.

// ---------------------------------------------------------------------------
// 13. Service sales -- the accrual
// ---------------------------------------------------------------------------
//
// The register that carries money. One Rilven document per treatment case; its lines are the
// services performed within that case.
//
// THE POSTING IS NOT BUILT HERE. Rilven's own engine writes it when the document is confirmed:
//
//     Дт 1410 (пациент)  /  Кт 6110      полная стоимость услуги
//
// the debit against the patient's BRANCH, the credit taken from the account carried by the
// SERVICE itself -- which is why section 11's accountPlanMapId is load-bearing.

// Master switch for this register.
$config['rilven_sale_enabled'] = FALSE;

// Whether to CONFIRM the document after creating it, which is what writes the accrual.
//
// FALSE creates drafts and posts nothing. Leave it FALSE for the first runs: a draft can be read,
// counted against the CMS and deleted with no trace in the books, and an accrual into a period
// that is later closed cannot be undone from here. Turn it on once a sample has been checked.
$config['rilven_sale_post'] = FALSE;


// How many days a case must stand before its accrual is posted. THE DAILY CLOSE.
//
// A posted document is frozen: the guard on the CMS side refuses an edit once Rilven reports a
// status above draft. Post on the day of the visit and the doctor cannot finish writing the case
// -- and the clinic's own practice is that a case is not finished on the day it opens. So the
// document goes over as a DRAFT first, both sides may still change it, and the accrual is written
// only once the case has stopped moving.
//
// Counted from the case DATE, not from its last edit. From the last edit a case that is touched
// every day would never ripen at all; from the date, "everything older than two days is closed"
// is a rule the clinic can hold in its head, which is what a daily close has to be.
//
// The cutoff is read from MySQL (CURDATE()), not computed here, because this process does not
// agree with itself about the time: the cron's PHP runs in the clinic's zone and an interactive
// one in UTC, eight hours apart. Data and cutoff now come off one clock.
//
// 0 posts immediately -- what this library did before the key existed. 2 is the clinic's rule.
$config['rilven_sale_post_after_days'] = 2;

// The document type. 13 is SALE_SERVICE -- what /operation/sale-service/insert sends.
$config['rilven_sale_document_type'] = 13;

// Where this register starts. Everything before it belongs to periods somebody has closed.
$config['rilven_sale_from'] = '2025-01-01';

// The tables, WITHOUT the prefix.
$config['rilven_sale_source_table'] = 'sales';
$config['rilven_sale_item_table']   = 'sale_items';

// Which column dates a case, for the window above.
$config['rilven_sale_date_column'] = 'date';

// Which column of the case names the patient.
$config['rilven_sale_patient_column'] = 'customer_id';

// What else makes a case sendable. Every pair is ANDed.
//
// EMPTY, and that is the measured answer rather than a default nobody looked at.
//
// This register has no deletion at all. `deleted_at` does not exist -- the earlier integration
// model queried it and would have failed outright -- and `sale_delete_status`, which looked like
// the marker, is NULL on every row in the database and is not used. A case is killed instead by
// REPLACING its lines with zero-valued ones, so it keeps its id, its kind and its place here, and
// what reaches Rilven is an update to zero. Nothing needs filtering out.
//
// Which means the withdrawal path (unconfirm + delete the document) fires only for a case that
// leaves scope some other way -- in practice only by moving the date window.
// Which cases are clinical. `service` 0 is not one of them -- those rows are not treatment and
// have no accrual to make, and one of them (198839) carries no usable date at all, which is what
// brought this to light.
//
// A LIST here, not a single value: applyWhere turns an array into IN. Narrowing a document
// register's scope is the one change that can DELETE what it already sent -- a row that stops
// matching reads as deleted and Rilven_sale has a remove() -- so this was checked first. All 310
// documents sent so far are ambulatory, day or inpatient; not one is a service 0.
$config['rilven_sale_where'] = array(
    'service' => array(1, 2, 3, 4),
);

// Which lines of a case are real services.
//
// A subservice is a component of the line above it and is NOT accrued separately: counting both
// would recognise the same revenue twice.
$config['rilven_sale_item_where'] = array(
    'subservice' => 0,
);

// The identity of a case over there is `code` = sma_sales.id.
//
// Unlike the reference registers, /waybill/get has NO ?by=code -- there is no way to ask Rilven
// which document belongs to a case. The mapping lives in this outbox's rilven_id, and
// /waybill/code/check is the guard that keeps a lost mapping from becoming a second accrual.
$config['rilven_sale_code_prefix'] = '';

// The ids a document needs and the clinic's register cannot supply.
//
// Company 1022: branch 30 (ცენტრალური ფილიალი), currency 44 (GEL). Both verified 2026-09-19.
$config['rilven_sale_company_branch_id'] = 30;
$config['rilven_sale_currency_id']       = 44;

// Which column of the case names the warehouse it was treated in.
//
// The warehouse is NOT pinned: all 55 are already synced and carry the CMS id as their `code`,
// so each document names the place the work actually happened -- resolved through
// /warehouse/get/{id}?by=code. One stand-in warehouse for two thousand cases would be a
// register nobody can read.
$config['rilven_sale_warehouse_column'] = 'warehouse_id';

// Prefix, if the warehouses were sent with one. Theirs were not.
$config['rilven_warehouse_code_prefix'] = '';

// The FALLBACK warehouse, for a case whose own warehouse was never synced.
//
// NULL refuses such a case instead, which is the stricter choice and the right default: a
// document silently filed under the wrong warehouse looks exactly like a correct one. Fill it in
// only if `check` shows cases that would otherwise be stuck, and note that every use of it is
// reported as `warehouse-not-synced-fell-back`.
$config['rilven_sale_warehouse_id'] = NULL;

// VAT on the document and on its lines. NULL takes the services' own setting (section 11), which
// is what it should be: a service sold cannot be taxed differently from the service itself.
$config['rilven_sale_vat']      = FALSE;
$config['rilven_sale_vat_type'] = NULL;

// Column names, where this fork spells them differently from the defaults. The keys are what the
// library MEANS; anything not listed is read by its own name.
$config['rilven_sale_fields'] = array(
    'date'         => 'date',
    'indate'       => 'indate',
    'outdate'      => 'outdate',
    'reference_no' => 'reference_no',
);

// ---------------------------------------------------------------------------
// 14. Service sales: when a case is finished, and what kind it is
// ---------------------------------------------------------------------------
//
// The rule differs by kind, and it is the clinic's, not this library's:
//
//   OUTPATIENT -- finished the moment it is recorded. The visit is over, so the document is
//   created AND confirmed in one go: the accrual is written immediately.
//
//   INPATIENT -- finished only when a person marks the case `completed`. Until then the episode
//   is still running and services are still being added to it; an accrual posted mid-stay would
//   have to be corrected by every line that came after. The document is created as a DRAFT and
//   confirmed on a later run, once the status changes.
//
// rilven_sale_post sits above all of this. FALSE confirms nothing whatever these say.

// Which value of `service` means an outpatient case.
$config['rilven_sale_outpatient_service'] = 1;

// Confirm an outpatient case as soon as it is created.
$config['rilven_sale_post_outpatient'] = TRUE;

// What tells us an inpatient case is finished. Set by a user in the CMS.
$config['rilven_sale_status_column']    = 'sale_status';
$config['rilven_sale_completed_status'] = 'completed';

// How a changed case is noticed after it has already been sent.
//
// Without this an inpatient case marked `completed` days later would sit at "sent" for ever and
// its accrual would never be written -- the catch-up only queues what it has never seen. The
// case's own updated_at against the outbox row's catches that, and any other correction besides.
// Re-opening costs nothing when nothing material changed: the payload hash is kept, so the run
// finds it identical and closes the row again without a request.
$config['rilven_sale_updated_column'] = 'updated_at';

// What kind of case this is, written into the document's comment -- the one place an accountant
// reading a service sale can see it.
//
//   1 outpatient, 3 DRG inpatient, 4 inpatient
$config['rilven_sale_case_types'] = array(
    1 => 'ამბულატორია',
    3 => 'დღის სტაციონარი',
    4 => 'სტაციონარი',
);

// A case of THIS kind carrying THIS flag takes its own label instead, tested before the plain
// one. Note the kind is 4 and not 1: the flag hangs off the inpatient value even though what it
// names is an outpatient one. That is what the clinic's register says, so it is configured here
// rather than derived from rilven_sale_outpatient_service. Empty or NULL disables it.
//
// NOTE that this changes only the LABEL. Such a case still has service = 4, so the posting rule
// treats it as inpatient and waits for `completed`. If an emergency outpatient case is really
// finished the moment it is recorded, this is the line to revisit.
$config['rilven_sale_emergency_service'] = 4;
$config['rilven_sale_emergency_column']  = 'gad_amb';
$config['rilven_sale_emergency_label']   = 'გადაუდებელი ამბულატორია';

// Whether this library may take a document back to a draft, which DELETES ITS POSTING.
//
// Needed for two ordinary things, both of which happen after a document has been confirmed:
//
//   * A case is KILLED. That register has no delete -- a case is voided by replacing its lines
//     with zero-valued ones. The case stays, the amounts go to zero, and the document has to
//     follow. It cannot, while it is confirmed: a posted document is read-only.
//   * A case is CORRECTED -- a service added to a stay, an amount fixed. Same problem.
//
// Rilven supports it: 2 → 1 is an allowed transition and unconfirming removes the ledger entries.
// It is the product's own reversal path, the one the status button uses.
//
// It is still the most consequential thing here. Revenue that was recognised stops being
// recognised, and anybody who reported on the period in between will not match the ledger
// afterwards. A CLOSED period refuses the change outright, and that protection is Rilven's.
//
// FALSE never unconfirms. A changed or killed case whose document is posted is then refused with
// `case-changed-but-document-is-already-posted` and waits for a person -- safer, and it means
// somebody has to watch the refusals.
$config['rilven_sale_unpost'] = TRUE;

// The company bank account the document settles against. REQUIRED, and only for type 13.
//
// validateWaybill reads it UNBOXED -- `long x = dto.getCompanyBankAccountId()` -- so leaving it
// out is not a polite refusal but a NullPointerException, answered as system-error[0001.9999]
// with a stack trace and nothing naming the field. Cost one debugging round to find.
//
// It must belong to rilven_sale_company_branch_id (30), or the route refuses it by name.
//
// Company 1022 has exactly one: id 31, GE38CR0050009565113602 at ქართუ, on branch 30 and in
// currency 44 -- which agrees with rilven_sale_currency_id above. Verified 2026-09-19.
$config['rilven_sale_company_bank_account_id'] = 31;

// Which column of a LINE names the room the service was performed in.
//
// A case collects an X-ray from radiology, a consultation from a doctor's room and a test from
// the laboratory, and this register says so per line -- sma_sale_items.warehouse_id, beside the
// sma_sales.warehouse_id that names the case. Sending only the case's throws that away, and the
// per-department reporting is exactly what it is for.
//
// A line whose warehouse equals the case's, or whose warehouse is not synced, is sent without
// one -- which Rilven reads as "wherever the document was". Empty disables per-line warehouses.
$config['rilven_sale_item_warehouse_column'] = 'warehouse_id';

// How far back the content sweep looks, in days. 0 disables the window and sweeps the whole
// scope, which is correct but grows without limit -- see Rilven::saleFingerprints(). A change to
// a case older than this is still caught whenever the clinic's own `updated_at` moves.
$config['rilven_sale_fingerprint_days'] = 90;

// ---------------------------------------------------------------------------
// 15. Money destinations -- sma_cash
// ---------------------------------------------------------------------------
//
// One table, two records over there. `paid_by` decides which, and an unrecognised value is
// REFUSED rather than defaulted -- see Rilven_cash for why that is the whole safety of this
// register. Measured on the LJ fork: 10 rows, 4 cash, 2 bank, 4 CC.

$config['rilven_cash_enabled'] = FALSE;

// Without the prefix; CodeIgniter adds it.
$config['rilven_cash_source_table'] = 'cash';

// Narrow the scope if a fork keeps retired destinations in the same table. Empty means all.
$config['rilven_cash_where'] = array();

// What each value MEANS, named by meaning rather than by column, because forks rename columns.
//   name       what a person calls it
//   kind       the value `rilven_cash_kinds` is read against
//   account    the account NUMBER, for a bank row or a card terminal. This is how a destination
//              finds the Rilven account it settles into without anybody binding it by hand: an
//              IBAN is the same string in both systems or it is not the same account.
//   device     the fiscal device serial, for a till. On this fork the clinic keeps it in the
//              same `account_number` column the bank rows use for their IBAN -- one column,
//              two meanings, decided by `paid_by`. A row that leaves it empty sends its own id,
//              which is unique and traceable back.
$config['rilven_cash_fields'] = array(
    'name'    => 'name',
    'kind'    => 'paid_by',
    'account' => 'account_number',
    'device'  => 'account_number',
);

// Which Rilven record each `paid_by` becomes. A value that is not here stops its destination and
// every payment through it, by design: a till holds money on 1110 and a bank account on 1210, and
// guessing between them misstates the balance sheet in a way nobody notices for months.
$config['rilven_cash_kinds'] = array(
    'cash' => 'cash-machine',   // 1110
    'bank' => 'bank-account',   // 1210
    'CC'   => 'bank-account',   // a card terminal pays into a bank account, never into a till
);

// Where the tills belong. Same branch as the sales register.
$config['rilven_cash_company_branch_id'] = 30;

// There is NO bank or currency key here on purpose: this register never creates a bank account.
// A bank row or a card terminal is BOUND to an account a person opened in Rilven, by putting the
// sma_cash id in that account's `code`. Until that is done the destination refuses by name, which
// is what an accountant needs to see -- see Rilven_cash::push().

// A prefix for a Rilven company taking destinations from two source systems. Normally empty.
$config['rilven_cash_code_prefix'] = '';

// ---------------------------------------------------------------------------
// 16. Money received -- sma_deposits
// ---------------------------------------------------------------------------
//
// TWO ROWS PER PAYMENT. The CMS writes the money once as `cash`/`CC`/`bank` and again as
// `payment_link` -- the two legs of one entry in one table. Only the first is sent; Rilven
// writes the other leg itself from the posting rule. Sending both would double every payment.
// Measured on this fork: 343,180 rows, 172,844 real movements.

$config['rilven_deposit_enabled'] = FALSE;

// Whether a payment is CONFIRMED as well as recorded.
//
// TRUE, and that is not the same decision as rilven_sale_post. An accrual states what the clinic
// intends to charge and can reasonably wait for somebody to look at it. A receipt cannot: the
// patient handed the money over, the till holds it, and Дт 1110|1210 / Кт 3120 describes a fact
// that is already true. A draft here would mean the books disagree with the cash drawer for as
// long as nobody pressed the button.
//
// /cash-flow/insert forces status 1 whatever it is sent, so confirming is a second call --
// PUT /cash-flow/update-status -- which needs its own grant: db/cms_deposit_post_grant.sql.
// FALSE leaves every payment a draft, which is the way to stop posting without stopping the sync.
$config['rilven_deposit_post'] = TRUE;

$config['rilven_deposit_source_table'] = 'deposits';

// The window. Payments must not run ahead of the accruals they belong to, so this is normally
// the same date as rilven_sale_from -- load a year of payments against two days of revenue and
// 3120 swells by everything the clinic has ever taken.
$config['rilven_deposit_from']        = '2026-09-18';
$config['rilven_deposit_date_column'] = 'date';
$config['rilven_deposit_where']       = array();

// Who the advance is owed back to. NOT the patient: an insurer settling for a patient puts its
// own id here and the patient's in customer_id, and the money is owed to whoever handed it over.
$config['rilven_deposit_payer_column'] = 'company_id';

//   kind       the value `rilven_deposit_kinds` is read against
//   amount_in  money received; amount_out money returned -- the CMS keeps them in two columns
//   destination  sma_cash.id, which the cash register has already resolved
//   payer / patient / case / reference / note  for the comment a person reads
$config['rilven_deposit_fields'] = array(
    'kind'        => 'paid_by',
    'amount_in'   => 'amount',
    'amount_out'  => 'amount_credit',
    'destination' => 'cash_type',
    'date'        => 'date',
    'payer'       => 'company_id',
    'patient'     => 'customer_id',
    'case'        => 'sale_id',
    'reference'   => 'reference_no',
    'note'        => 'note',
);

// Which way the money went. A value that is not here and not in `rilven_deposit_other_leg` is
// reported by `check` as unknown -- its payments are not sent at all, which is the safe answer.
$config['rilven_deposit_kinds'] = array(
    'cash'                => 'in',
    'CC'                  => 'in',
    'bank'                => 'in',
    'refund'              => 'out',
    'payment_link_refund' => 'out',
);

// The other leg, written by the CMS and by Rilven both. Listed so that `check` can count it
// apart from the kinds nobody has decided about -- it is expected, not a problem.
$config['rilven_deposit_other_leg'] = array('payment_link');

// The posting rule per destination and direction. These are tb_transaction_helper ids:
//   2852  Дт 1110 / Кт 3120   cash receipt in till -- from advances received
//   2853  Дт 3120 / Кт 1110   advance returned from the till
//    240  Дт 1210 / Кт 3120   cash receipt in bank -- from advances received
//    161  Дт 3120 / Кт 1210   advance returned from the bank
// The till pair did not exist in the product and was added by db/cash_advance_till_helper.sql:
// Rilven could take an advance into a bank account but not into a till, and a till is where
// this clinic takes most of its money.
$config['rilven_deposit_helpers'] = array(
    'cash-machine/in'  => 2852,
    'cash-machine/out' => 2853,
    'bank-account/in'  => 240,
    'bank-account/out' => 161,
);

$config['rilven_deposit_company_branch_id'] = 30;
$config['rilven_deposit_currency_id']       = 44;
$config['rilven_deposit_code_prefix']       = '';


// ---------------------------------------------------------------------------
// FINANCIER SHARES -- the settlement that moves a case's receivable
// ---------------------------------------------------------------------------
//
// The case's own document accrues the WHOLE of it against the patient. Most of the money is owed
// by somebody else, and this register moves it: one settlement per case (Rilven document_type
// 81), one line per share, Дт1410 financier / Кт1410 patient. Measured September 2026 -- patients
// 291 995, financiers 350 809. Without it fifty-five per cent of the turnover sits on people who
// do not owe it.
//
// OFF until the settlements have been watched for a while. Deliberately not tied to
// rilven_sale_enabled: the cases can run for weeks before anybody is ready for this.
$config['rilven_financing_enabled'] = FALSE;

// Where the share lives, and it is not where the column names suggest. `insurance_group_id` is
// NULL in every row of this database; the financier is `company_id` pointing into the financier
// group, on an `accruing` row. The case-level row with no sale_item_id is the PATIENT's share and
// is not sent -- the case document already accrued it.
$config['rilven_financing_source_table'] = 'payments';
$config['rilven_financing_payment_type'] = 'accruing';

// Who counts as a financier: the same scope as the insurer register, so the two can never
// disagree about it. Written once, read twice.
//   -- see rilven_insurer_where above

// The two rules, from db/settlement.sql. The clinic's chart: 2854 is Дт1410/Кт1410 and 2855 is
// Дт8290/Кт1410.
$config['rilven_financing_helper_share']      = 2854;
$config['rilven_financing_helper_concession'] = 2855;

// The payers that are NOT debtors. `კლინიკის შეღავათი (ლჯ-ის დაფინანსება)` is the clinic paying
// for itself: nobody owes it, the service was given away, so it is a non-operating expense and
// taxed as one -- 8290/1410 rather than 1410/1410, and the debit side is a company branch
// instead of a counterparty.
//
// EMPTY until the accountant names them. An id in the wrong list here writes a real receivable
// against a company that owes nothing, or hides one that does.
$config['rilven_financing_concession_ids'] = array();

// Same branch and currency as the case documents, so a settlement and the case it settles never
// land in different books.
$config['rilven_financing_company_branch_id'] = 30;
$config['rilven_financing_currency_id']       = 44;

$config['rilven_financing_code_prefix'] = '';

