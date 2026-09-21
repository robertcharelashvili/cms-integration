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
