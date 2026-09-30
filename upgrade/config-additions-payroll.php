<?php
// Paste into application/config/rilven.php ON THE SERVER (never copy the repository's file over
// it: the server's copy holds the live credentials). See the PAYROLL block in the repository copy.
$config['rilven_payroll_enabled'] = FALSE;
$config['rilven_payroll_months_back'] = 3;
$config['rilven_payroll_since'] = '2025-01-01';
$config['rilven_payroll_months_per_tick'] = 2;
$config['rilven_payroll_code_prefix'] = 'cms-';
$config['rilven_payroll_company_branch_id'] = 30;
$config['rilven_payroll_admin_departments'] = array('ადმინისტრაცია');
$config['rilven_payroll_admin_account'] = '7410';
// who may confirm a payroll run for Rilven in the salary register, besides the owner (sma_groups.name)
$config['rilven_payroll_confirm_groups'] = array('accounting', 'chief_accountant');
