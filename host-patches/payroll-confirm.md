# Payroll run confirmation for Rilven (salary register)

A payroll run (`sma_daricxva`) is recalculated and edited for days before it is right. Rilven takes
a month only from a run that the owner or the accounting department has **confirmed**, and only as
it stood when confirmed.

## Install, in this order

1. `upgrade/payroll-confirm.sql` on the CMS database. It adds `rilven_status`, `rilven_status_at`,
   `rilven_status_user` and `rilven_fingerprint`, and confirms the latest run of every existing
   month (those are correct).
2. `application/libraries/Rilven_payroll.php`: it reads only confirmed runs.
3. `billers-payroll-confirm.diff` applied to `app/controllers/admin/Billers.php`. Back the file up
   first; the diff was taken against md5 `0ce3a148217f5236b2c29619e4eefe2d`.
4. `$config['rilven_payroll_confirm_groups']` added to the server's `config/rilven.php`, in place.
   Never copy the repository's config file over the server's copy.

Steps 2 and 3 fail at runtime without step 1: they select columns that step 1 creates.

## What the patch does, in `admin/billers/daricxvebi`

- Each row carries a badge:
  - `Rilven ✓`: confirmed, and sent;
  - `Rilven: შეცვლილია`: edited after it was confirmed, so it is not sent;
  - `დასადასტურებელი`: not confirmed.
- The actions menu gains `დადასტურება Rilven-ისთვის` and `გახსნა (Rilven)`. Only the owner and the
  groups in `rilven_payroll_confirm_groups` see them, and the user also needs the `salary` right.
- Confirming stores the run's fingerprint (`did:lines:total`) and un-confirms any other run of the
  same month. Only one run per month is ever confirmed.
- Opening a run again leaves the month with no confirmed run. Nothing more is sent, and what Rilven
  already has stays as it is.
- A confirmed run cannot be deleted with `ყველა უწყისის წაშლა`; it has to be opened first.

Once Rilven's salary accrual has taken the month, Rilven refuses any replacement
(`salary-variable-is-in-payroll`). Delete that accrual in Rilven first.
