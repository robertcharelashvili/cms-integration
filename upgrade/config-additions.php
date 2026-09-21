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
$config['rilven_sale_where'] = array();

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
