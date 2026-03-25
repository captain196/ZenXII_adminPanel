<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/userguide3/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/
$route['default_controller'] = 'admin_login';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;
// $route['sync_offline_data'] = 'SyncOfflineData/index'; // REMOVED: unauthenticated stub archived 2026-03-14
$route['accounts'] = 'Account/fetch_accounts';
$route['create_account'] = 'Account/create_account';
$route['edit_account/(:any)'] = 'Account/edit_account/$1';
$route['delete_account/(:any)'] = 'Account/delete_account/$1';
$route['calculate_balances'] = 'Account/calculate_balances';
$route['account/populateTable'] = 'account/populateTable';
$route['accounts/get'] = 'AccountController/getAccounts';
$route['fees/dashboard']                    = 'Fees/fees_dashboard';
$route['fees/get_dashboard_data']           = 'Fees/get_dashboard_data';
$route['fees/lookup_student']               = 'fees/lookup_student';
$route['fees/fetch_fee_details']            = 'fees/fetch_fee_details';
$route['fees/print_receipt/(:any)']         = 'Fees/print_receipt/$1';
$route['fees/get_receipt_no']               = 'Fees/get_receipt_no';
// Audit & Recovery
$route['fees/transaction_audit']           = 'Fees/transaction_audit';
$route['fees/search_transaction']          = 'Fees/search_transaction';
$route['fees/get_stale_transactions']      = 'Fees/get_stale_transactions';
$route['fees/resolve_stale']               = 'Fees/resolve_stale';
$route['fees/diagnose_transaction']        = 'Fees/diagnose_transaction';
$route['fees/recalculate_advance']         = 'Fees/recalculate_advance';

// Fee Demand Engine
$route['fees/generate_demands_for_student'] = 'Fees/generate_demands_for_student';
$route['fees/generate_monthly_demands']     = 'Fees/generate_monthly_demands';
$route['fees/get_student_demands']          = 'Fees/get_student_demands';
$route['fees/get_demand_status']            = 'Fees/get_demand_status';
$route['fees/recalculate_demands']          = 'Fees/recalculate_demands';
$route['fees/auto_compute_fines']           = 'Fees/auto_compute_fines';
$route['fees/student_ledger']               = 'Fees/student_ledger';
$route['fees/defaulter_report']             = 'Fees/defaulter_report';
$route['fees/get_defaulter_data']           = 'Fees/get_defaulter_data';
$route['fees/get_collection_analytics']     = 'Fees/get_collection_analytics';
$route['fees/get_student_allocations']     = 'Fees/get_student_allocations';
$route['fees/get_fee_account_map']          = 'Fees/get_fee_account_map';
$route['fees/save_fee_account_map']         = 'Fees/save_fee_account_map';
// Simulation (Admin only)
$route['fee_simulation']                   = 'Fee_simulation/index';
$route['fee_simulation/run']               = 'Fee_simulation/run';
$route['fee_simulation/run_parallel']      = 'Fee_simulation/run_parallel';
$route['fee_simulation/run_school']        = 'Fee_simulation/run_school';
$route['fee_simulation/cleanup']           = 'Fee_simulation/cleanup';
// Pages (GET)
$route['fees/fees_counter']                = 'Fees/fees_counter';
$route['fees/fees_chart']                  = 'Fees/fees_chart';
$route['fees/fees_records']                = 'Fees/fees_records';
$route['fees/fees_structure']              = 'Fees/fees_structure';
$route['fees/student_fees']                = 'Fees/student_fees';
$route['fees/class_fees']                  = 'Fees/class_fees';
// Data endpoints (POST)
$route['fees/submit_fees']                 = 'Fees/submit_fees';
$route['fees/fetch_months']                = 'Fees/fetch_months';
$route['fees/fetch_fee_receipts']          = 'Fees/fetch_fee_receipts';
$route['fees/search_student']              = 'Fees/search_student';
$route['fees/due_fees_table']              = 'Fees/due_fees_table';
$route['fees/save_updated_fees']           = 'Fees/save_updated_fees';
$route['fees/submit_discount']             = 'Fees/submit_discount';
$route['fees/delete_fees_structure/(:any)'] = 'Fees/delete_fees_structure/$1';

// ─── Super Admin SaaS Control Panel ──────────────────────────────────────────
// Auth
$route['admin_login/forgot_password']                    = 'Admin_login/forgot_password';
$route['admin_login/send_otp']                          = 'Admin_login/send_otp';
$route['admin_login/verify_otp']                        = 'Admin_login/verify_otp';
$route['admin_login/reset_password']                    = 'Admin_login/reset_password';
$route['admin_login/student_forgot_password']            = 'Admin_login/student_forgot_password';
$route['admin_login/student_send_otp']                  = 'Admin_login/student_send_otp';
$route['admin_login/student_verify_otp']                = 'Admin_login/student_verify_otp';
$route['admin_login/student_reset_password']             = 'Admin_login/student_reset_password';

$route['superadmin/login']                              = 'Superadmin_login/index';
$route['superadmin/login/authenticate']                 = 'Superadmin_login/authenticate';
$route['superadmin/login/logout']                       = 'Superadmin_login/logout';
$route['superadmin/login/forgot_password']              = 'Superadmin_login/forgot_password';
$route['superadmin/login/send_otp']                     = 'Superadmin_login/send_otp';
$route['superadmin/login/verify_otp']                   = 'Superadmin_login/verify_otp';
$route['superadmin/login/reset_password']               = 'Superadmin_login/reset_password';
$route['superadmin/logout']                             = 'Superadmin_login/logout';
$route['superadmin/csrf_token']                         = 'Superadmin_login/csrf_token';

// Dashboard
$route['superadmin/dashboard']                          = 'Superadmin/dashboard';
$route['superadmin/dashboard/refresh_stats']            = 'Superadmin/refresh_stats';
$route['superadmin/dashboard/charts']                   = 'Superadmin/dashboard_charts';
$route['superadmin/dashboard/search']                   = 'Superadmin/search';

// Schools
$route['superadmin/schools']                            = 'Superadmin_schools/index';
$route['superadmin/schools/create']                     = 'Superadmin_schools/create';
$route['superadmin/schools/onboard']                    = 'Superadmin_schools/onboard';
$route['superadmin/schools/view/(:any)']                = 'Superadmin_schools/view/$1';
$route['superadmin/schools/check_availability']         = 'Superadmin_schools/check_availability';
$route['superadmin/schools/toggle_status']              = 'Superadmin_schools/toggle_status';
$route['superadmin/schools/update_profile']             = 'Superadmin_schools/update_profile';
$route['superadmin/schools/assign_plan']                = 'Superadmin_schools/assign_plan';
$route['superadmin/schools/refresh_school_stats']       = 'Superadmin_schools/refresh_school_stats';
$route['superadmin/schools/migrate_existing']           = 'Superadmin_schools/migrate_existing_schools';
$route['superadmin/schools/migrate_academic']           = 'Superadmin_schools/migrate_academic_data';
$route['superadmin/schools/upload_logo']               = 'Superadmin_schools/upload_logo';

// Migration
$route['superadmin/migration']                          = 'Superadmin_migration/index';
$route['superadmin/migration/analyze']                  = 'Superadmin_migration/analyze';
$route['superadmin/migration/get_report']               = 'Superadmin_migration/get_report';
$route['superadmin/migration/clear_map']                = 'Superadmin_migration/clear_map';
$route['superadmin/migration/migrate_phone_index']     = 'Superadmin_migration/migrate_phone_index';

// Plans
$route['superadmin/plans']                              = 'Superadmin_plans/index';
$route['superadmin/plans/create']                       = 'Superadmin_plans/create';
$route['superadmin/plans/update']                       = 'Superadmin_plans/update';
$route['superadmin/plans/delete']                       = 'Superadmin_plans/delete_plan';
$route['superadmin/plans/fetch']                        = 'Superadmin_plans/fetch';
$route['superadmin/plans/seed_defaults']                = 'Superadmin_plans/seed_defaults';
$route['superadmin/plans/subscriptions']                = 'Superadmin_plans/subscriptions';
$route['superadmin/plans/fetch_subscriptions']          = 'Superadmin_plans/fetch_subscriptions';
$route['superadmin/plans/expire_check']                 = 'Superadmin_plans/expire_check';
$route['superadmin/plans/payments']                     = 'Superadmin_plans/payments';
$route['superadmin/plans/fetch_payments']               = 'Superadmin_plans/fetch_payments';
$route['superadmin/plans/get_school_plan']               = 'Superadmin_plans/get_school_plan';
$route['superadmin/plans/add_payment']                  = 'Superadmin_plans/add_payment';
$route['superadmin/plans/update_payment']               = 'Superadmin_plans/update_payment';
$route['superadmin/plans/delete_payment']               = 'Superadmin_plans/delete_payment';
$route['superadmin/plans/generate_invoice']             = 'Superadmin_plans/generate_invoice';
$route['superadmin/plans/collect_payment']              = 'Superadmin_plans/collect_payment';
$route['superadmin/plans/fetch_school_payments']        = 'Superadmin_plans/fetch_school_payments';

// Reports
$route['superadmin/reports']                            = 'Superadmin_reports/index';
$route['superadmin/reports/students']                   = 'Superadmin_reports/students_summary';
$route['superadmin/reports/revenue']                    = 'Superadmin_reports/revenue_summary';
$route['superadmin/reports/activity']                   = 'Superadmin_reports/activity_summary';
$route['superadmin/reports/plans_distribution']         = 'Superadmin_reports/plans_distribution';

// Monitor
$route['superadmin/monitor']                            = 'Superadmin_monitor/index';
$route['superadmin/monitor/logins']                     = 'Superadmin_monitor/fetch_login_logs';       // [FIX-6] was missing
$route['superadmin/monitor/activity']                   = 'Superadmin_monitor/fetch_activity_logs';
$route['superadmin/monitor/fetch_activity_logs']        = 'Superadmin_monitor/fetch_activity_logs';
$route['superadmin/monitor/school_logins']              = 'Superadmin_monitor/fetch_school_logins';
$route['superadmin/monitor/errors']                     = 'Superadmin_monitor/fetch_error_logs';
$route['superadmin/monitor/clear_logs']                 = 'Superadmin_monitor/clear_logs';
$route['superadmin/monitor/system_health']              = 'Superadmin_monitor/system_health';
$route['superadmin/monitor/fetch_api_logs']             = 'Superadmin_monitor/fetch_api_logs';
$route['superadmin/monitor/log_api_call']               = 'Superadmin_monitor/log_api_call';
$route['superadmin/monitor/firebase_usage']             = 'Superadmin_monitor/firebase_usage';
$route['superadmin/monitor/cleanup_old_logs']           = 'Superadmin_monitor/cleanup_old_logs';

// Backups
$route['superadmin/backups']                            = 'Superadmin_backups/index';
$route['superadmin/backups/fetch_backups']              = 'Superadmin_backups/fetch_backups';
$route['superadmin/backups/create_backup']              = 'Superadmin_backups/create_backup';
$route['superadmin/backups/restore_backup']             = 'Superadmin_backups/restore_backup';
$route['superadmin/backups/upload_restore']             = 'Superadmin_backups/upload_restore';
$route['superadmin/backups/delete_backup']              = 'Superadmin_backups/delete_backup';
$route['superadmin/backups/download/(:any)/(:any)']     = 'Superadmin_backups/download/$1/$2';
$route['superadmin/backups/backup_stats']               = 'Superadmin_backups/backup_stats';
$route['superadmin/backups/get_schedule']               = 'Superadmin_backups/get_schedule';
$route['superadmin/backups/save_schedule']              = 'Superadmin_backups/save_schedule';
$route['superadmin/backups/run_scheduled_now']          = 'Superadmin_backups/run_scheduled_now';
$route['backup_cron/(:any)']                            = 'Backup_cron/run/$1';

// ─── Debug Panel
$route['superadmin/debug']                              = 'Superadmin_debug/index';
$route['superadmin/debug/get_logs']                     = 'Superadmin_debug/get_logs';
$route['superadmin/debug/get_stats']                    = 'Superadmin_debug/get_stats';
$route['superadmin/debug/toggle_debug']                 = 'Superadmin_debug/toggle_debug';
$route['superadmin/debug/clear_debug_logs']             = 'Superadmin_debug/clear_debug_logs';
$route['superadmin/debug/schema_check']                 = 'Superadmin_debug/schema_check';
$route['superadmin/debug/log_ajax_error']               = 'Superadmin_debug/log_ajax_error';

// ─── Super Admin Management (developer only)
$route['superadmin/admins']                             = 'Superadmin_admins/index';
$route['superadmin/admins/fetch']                       = 'Superadmin_admins/fetch';
$route['superadmin/admins/create']                      = 'Superadmin_admins/create';
$route['superadmin/admins/toggle_status']               = 'Superadmin_admins/toggle_status';
$route['superadmin/admins/reset_password']              = 'Superadmin_admins/reset_password';
$route['superadmin/admins/update_profile']              = 'Superadmin_admins/update_profile';
$route['superadmin/admins/delete']                      = 'Superadmin_admins/delete';

// ─── School Configuration
$route['school_config']                                 = 'School_config/index';
$route['school_config/get_config']                      = 'School_config/get_config';
$route['school_config/save_profile']                    = 'School_config/save_profile';
$route['school_config/upload_logo']                     = 'School_config/upload_logo';
$route['school_config/save_board']                      = 'School_config/save_board';
$route['school_config/save_classes']                    = 'School_config/save_classes';
$route['school_config/get_sections']                    = 'School_config/get_sections';
$route['school_config/save_section']                    = 'School_config/save_section';
$route['school_config/delete_section']                  = 'School_config/delete_section';
$route['school_config/get_all_sections']                = 'School_config/get_all_sections';
$route['school_config/bulk_save_sections']              = 'School_config/bulk_save_sections';
$route['school_config/get_subjects']                    = 'School_config/get_subjects';
$route['school_config/get_suggested_subjects']          = 'School_config/get_suggested_subjects';
$route['school_config/save_subject']                    = 'School_config/save_subject';
$route['school_config/delete_subject']                  = 'School_config/delete_subject';
$route['school_config/get_default_subjects']            = 'School_config/get_default_subjects';
$route['school_config/save_bulk_subjects']              = 'School_config/save_bulk_subjects';
$route['school_config/save_stream']                     = 'School_config/save_stream';
$route['school_config/delete_stream']                   = 'School_config/delete_stream';
$route['school_config/add_session']                     = 'School_config/add_session';
$route['school_config/set_active_session']              = 'School_config/set_active_session';
$route['school_config/test_sessions']                   = 'School_config/test_sessions';
$route['school_config/sync_sessions']                   = 'School_config/sync_sessions';
$route['school_config/csrf_token']                      = 'School_config/csrf_token';
$route['school_config/test_profile']                    = 'School_config/test_profile';
$route['school_config/test_classes']                    = 'School_config/test_classes';
$route['school_config/test_sections']                   = 'School_config/test_sections';
$route['school_config/test_subjects']                   = 'School_config/test_subjects';
$route['school_config/activate_classes']                = 'School_config/activate_classes';
$route['school_config/soft_delete_class']               = 'School_config/soft_delete_class';
$route['school_config/restore_class']                   = 'School_config/restore_class';
$route['school_config/seed_streams']                    = 'School_config/seed_streams';
$route['school_config/upload_document']                 = 'School_config/upload_document';
$route['school_config/save_report_card_template']       = 'School_config/save_report_card_template';
$route['school_config/admission_payment']               = 'School_config/admission_payment_config';
$route['school_config/save_admission_payment_config']   = 'School_config/save_admission_payment_config';

// ─── Academic Management
$route['academic']                              = 'Academic/index';
$route['academic/get_classes_subjects']          = 'Academic/get_classes_subjects';
$route['academic/get_all_teachers']              = 'Academic/get_all_teachers';
$route['academic/get_curriculum']                = 'Academic/get_curriculum';
$route['academic/save_curriculum']               = 'Academic/save_curriculum';
$route['academic/update_topic_status']           = 'Academic/update_topic_status';
$route['academic/delete_topic']                  = 'Academic/delete_topic';
$route['academic/get_calendar_events']           = 'Academic/get_calendar_events';
$route['academic/save_event']                    = 'Academic/save_event';
$route['academic/delete_event']                  = 'Academic/delete_event';
$route['academic/get_master_timetable']          = 'Academic/get_master_timetable';
$route['academic/save_period']                   = 'Academic/save_period';
$route['academic/detect_conflicts']              = 'Academic/detect_conflicts';
$route['academic/get_substitutes']               = 'Academic/get_substitutes';
$route['academic/save_substitute']               = 'Academic/save_substitute';
$route['academic/update_substitute']             = 'Academic/update_substitute';
$route['academic/delete_substitute']             = 'Academic/delete_substitute';
$route['academic/get_teacher_schedule']          = 'Academic/get_teacher_schedule';
$route['academic/get_subject_assignments']       = 'Academic/get_subject_assignments';
$route['academic/save_subject_assignments']      = 'Academic/save_subject_assignments';
$route['academic/copy_subject_assignments']      = 'Academic/copy_subject_assignments';
$route['academic/get_timetable_settings']        = 'Academic/get_timetable_settings';
$route['academic/save_timetable_settings']       = 'Academic/save_timetable_settings';
$route['academic/get_section_timetable']         = 'Academic/get_section_timetable';
$route['academic/save_section_timetable']        = 'Academic/save_section_timetable';
$route['academic/get_class_subjects']            = 'Academic/get_class_subjects';

// ─── Result Management
$route['result']                                          = 'result/index';
$route['result/template_designer']                        = 'result/template_designer';
$route['result/template_designer/(:any)']                 = 'result/template_designer/$1';
$route['result/marks_entry']                              = 'result/marks_entry';
$route['result/marks_entry/(:any)']                       = 'result/marks_entry/$1';
$route['result/marks_sheet/(:any)/(:any)/(:any)/(:any)']  = 'result/marks_sheet/$1/$2/$3/$4';
$route['result/class_result']                             = 'result/class_result';
$route['result/class_result/(:any)']                      = 'result/class_result/$1';
$route['result/student_result/(:any)']                    = 'result/student_result/$1';
$route['result/report_card/(:any)/(:any)']                = 'result/report_card/$1/$2';
$route['result/batch_report_cards/(:any)/(:any)/(:any)']  = 'result/batch_report_cards/$1/$2/$3';
$route['result/cumulative']                               = 'result/cumulative';
$route['result/save_template']                            = 'result/save_template';
$route['result/get_template']                             = 'result/get_template';
$route['result/save_marks']                               = 'result/save_marks';
$route['result/get_marks']                                = 'result/get_marks';
$route['result/compute_results']                          = 'result/compute_results';
$route['result/get_class_result_data']                    = 'result/get_class_result_data';
$route['result/get_cumulative_data']                      = 'result/get_cumulative_data';
$route['result/save_cumulative_config']                   = 'result/save_cumulative_config';
$route['result/compute_cumulative']                       = 'result/compute_cumulative';
$route['result/get_exam_status']                          = 'result/get_exam_status';
$route['result/download_pdf/(:any)/(:any)']               = 'result/download_pdf/$1/$2';
$route['result/download_batch_pdf/(:any)/(:any)/(:any)']  = 'result/download_batch_pdf/$1/$2/$3';
$route['result/batch_pdf_count']                          = 'result/batch_pdf_count';

// ─── Examination Management ──────────────────────────────────────────────────
$route['examination']                            = 'Examination/index';
$route['examination/merit_list']                 = 'Examination/merit_list';
$route['examination/analytics']                  = 'Examination/analytics';
$route['examination/tabulation']                 = 'Examination/tabulation';
$route['examination/get_merit_data']             = 'Examination/get_merit_data';
$route['examination/get_analytics_data']         = 'Examination/get_analytics_data';
$route['examination/get_tabulation_data']        = 'Examination/get_tabulation_data';
$route['examination/bulk_compute']               = 'Examination/bulk_compute';
$route['examination/export_merit_list']          = 'Examination/export_merit_list';
$route['examination/get_exam_comparison']        = 'Examination/get_exam_comparison';

// ─── Student Information System (SIS) ────────────────────────────────────────
$route['sis']                                   = 'Sis/index';
$route['sis/students']                          = 'Sis/students';
$route['sis/admission']                         = 'Sis/admission';
$route['sis/save_admission']                    = 'Sis/save_admission';
$route['sis/profile/(:any)']                    = 'Sis/profile/$1';
$route['sis/update_profile']                    = 'Sis/update_profile';
$route['sis/promote']                           = 'Sis/promote';
$route['sis/promote_preview']                   = 'Sis/promote_preview';
$route['sis/execute_promotion']                 = 'Sis/execute_promotion';
$route['sis/tc']                                = 'Sis/tc_list';
$route['sis/issue_tc']                          = 'Sis/issue_tc';
$route['sis/print_tc/(:any)/(:any)']            = 'Sis/print_tc/$1/$2';
$route['sis/print_tc/(:any)']                   = 'Sis/print_tc/$1';
$route['sis/cancel_tc']                         = 'Sis/cancel_tc';
$route['sis/withdraw']                          = 'Sis/withdraw_student';
$route['sis/change_status']                     = 'Sis/change_status';
$route['sis/documents/(:any)']                  = 'Sis/documents/$1';
$route['sis/upload_document']                   = 'Sis/upload_document';
$route['sis/delete_document']                   = 'Sis/delete_document';
$route['sis/history/(:any)']                    = 'Sis/history/$1';
$route['sis/id_card']                           = 'Sis/id_card';
$route['sis/search_student']                    = 'Sis/search_student';
$route['sis/get_student']                       = 'Sis/get_student';
$route['sis/get_classes']                       = 'Sis/get_classes';
$route['sis/get_sections']                      = 'Sis/get_sections';
$route['sis/rebuild_index']                     = 'Sis/rebuild_index';

// ─── SIS: Student methods (merged from Student.php) ─────────────────────────
$route['sis/all_student']                       = 'Sis/students';
$route['sis/master_student']                    = 'Sis/master_student'; // FIXED: was pointing to Sis/students instead of import page
$route['sis/import_students']                   = 'Sis/import_students';
$route['sis/studentAdmission']                  = 'Sis/admission';
$route['sis/get_sections_by_class']             = 'Sis/get_sections_by_class';
$route['sis/fetch_subjects']                    = 'Sis/fetch_subjects';
$route['sis/edit_student/(:any)']               = 'Sis/edit_student/$1';
$route['sis/delete_student/(:any)']             = 'Sis/delete_student/$1';
$route['sis/student_profile/(:any)']            = 'Sis/student_profile/$1';
$route['sis/download_document']                 = 'Sis/download_document';
$route['sis/attendance']                        = 'Sis/attendance';
$route['sis/fetchAttendance']                   = 'Sis/fetchAttendance';

// ─── SIS: Admission CRM (merged from Admission_crm.php) ────────────────────
$route['sis/crm']                               = 'Sis/crm_dashboard';
$route['sis/inquiries']                         = 'Sis/inquiries';
$route['sis/fetch_inquiries']                   = 'Sis/fetch_inquiries';
$route['sis/save_inquiry']                      = 'Sis/save_inquiry';
$route['sis/delete_inquiry']                    = 'Sis/delete_inquiry';
$route['sis/convert_to_application']            = 'Sis/convert_to_application';
$route['sis/applications']                      = 'Sis/applications';
$route['sis/fetch_applications']                = 'Sis/fetch_applications';
$route['sis/save_application']                  = 'Sis/save_application';
$route['sis/get_application']                   = 'Sis/get_application';
$route['sis/delete_application']                = 'Sis/delete_application';
$route['sis/pipeline']                          = 'Sis/pipeline';
$route['sis/fetch_pipeline']                    = 'Sis/fetch_pipeline';
$route['sis/update_stage']                      = 'Sis/update_stage';
$route['sis/approve_application']               = 'Sis/approve_application';
$route['sis/reject_application']                = 'Sis/reject_application';
$route['sis/enroll_student']                    = 'Sis/enroll_student';
$route['sis/waitlist']                          = 'Sis/waitlist';
$route['sis/fetch_waitlist']                    = 'Sis/fetch_waitlist';
$route['sis/add_to_waitlist']                   = 'Sis/add_to_waitlist';
$route['sis/remove_from_waitlist']              = 'Sis/remove_from_waitlist';
$route['sis/promote_from_waitlist']             = 'Sis/promote_from_waitlist';
$route['sis/crm_settings']                      = 'Sis/crm_settings';
$route['sis/save_crm_settings']                 = 'Sis/save_crm_settings';
$route['sis/get_crm_settings']                  = 'Sis/get_crm_settings';
$route['sis/online_form']                       = 'Sis/online_form';
$route['sis/submit_online_form']                = 'Sis/submit_online_form';
$route['sis/fetch_analytics']                   = 'Sis/fetch_analytics';
// ─── Lead System ────
$route['sis/admission_leads']                   = 'Sis/admission_leads';
$route['sis/fetch_leads']                       = 'Sis/fetch_leads';
$route['sis/admission_lead']                    = 'Sis/admission_lead';
$route['sis/update_lead_status']                = 'Sis/update_lead_status';
$route['sis/get_lead_data']                     = 'Sis/get_lead_data';
$route['sis/admission_analytics']               = 'Sis/admission_analytics';

// ─── Backward-compatible routes (old student/* and admission_crm/* URLs) ────
// These map legacy URLs to consolidated SIS methods so existing views/links work
$route['student/all_student']                   = 'Sis/students';
$route['student/id_card']                       = 'Sis/id_card';
$route['student/master_student']                = 'Sis/students';
$route['student/import_students']               = 'Sis/import_students';
$route['student/studentAdmission']              = 'Sis/admission';
$route['student/get_classes']                   = 'Sis/get_classes';
$route['student/get_sections_by_class']         = 'Sis/get_sections_by_class';
$route['student/fetch_subjects']                = 'Sis/fetch_subjects';
$route['student/edit_student/(:any)']           = 'Sis/edit_student/$1';
$route['student/delete_student/(:any)']         = 'Sis/delete_student/$1';
$route['student/student_profile/(:any)']        = 'Sis/student_profile/$1';
$route['student/download_document']             = 'Sis/download_document';
$route['student/attendance']                    = 'Sis/attendance';
$route['student/fetchAttendance']               = 'Sis/fetchAttendance';
$route['admission_crm']                         = 'Sis/crm_dashboard';
$route['admission_crm/inquiries']               = 'Sis/inquiries';
$route['admission_crm/fetch_inquiries']         = 'Sis/fetch_inquiries';
$route['admission_crm/save_inquiry']            = 'Sis/save_inquiry';
$route['admission_crm/delete_inquiry']          = 'Sis/delete_inquiry';
$route['admission_crm/convert_to_application']  = 'Sis/convert_to_application';
$route['admission_crm/applications']            = 'Sis/applications';
$route['admission_crm/fetch_applications']      = 'Sis/fetch_applications';
$route['admission_crm/save_application']        = 'Sis/save_application';
$route['admission_crm/get_application']         = 'Sis/get_application';
$route['admission_crm/delete_application']      = 'Sis/delete_application';
$route['admission_crm/pipeline']                = 'Sis/pipeline';
$route['admission_crm/fetch_pipeline']          = 'Sis/fetch_pipeline';
$route['admission_crm/update_stage']            = 'Sis/update_stage';
$route['admission_crm/approve_application']     = 'Sis/approve_application';
$route['admission_crm/reject_application']      = 'Sis/reject_application';
$route['admission_crm/enroll_student']          = 'Sis/enroll_student';
$route['admission_crm/waitlist']                = 'Sis/waitlist';
$route['admission_crm/fetch_waitlist']          = 'Sis/fetch_waitlist';
$route['admission_crm/add_to_waitlist']         = 'Sis/add_to_waitlist';
$route['admission_crm/remove_from_waitlist']    = 'Sis/remove_from_waitlist';
$route['admission_crm/promote_from_waitlist']   = 'Sis/promote_from_waitlist';
$route['admission_crm/settings']                = 'Sis/crm_settings';
$route['admission_crm/save_settings']           = 'Sis/save_crm_settings';
$route['admission_crm/get_settings']            = 'Sis/get_crm_settings';
$route['admission_crm/online_form']             = 'Sis/online_form';
$route['admission_crm/submit_online_form']      = 'Sis/submit_online_form';
$route['admission_crm/fetch_analytics']         = 'Sis/fetch_analytics';

// ─── Public Admission (no login required) ────────────────────────────────────
$route['admission/form/(:any)']                 = 'Admission_public/form/$1';
$route['admission/submit/(:any)']               = 'Admission_public/submit/$1';
$route['admission/pay/(:any)']                  = 'Admission_public/initiate_payment/$1';
$route['admission/payment_callback/(:any)']     = 'Admission_public/payment_callback/$1';
$route['admission/payment_status/(:any)']       = 'Admission_public/payment_status/$1';

// ─── Attendance Management ───
$route['attendance']                         = 'Attendance/index';
$route['attendance/dashboard_stats']         = 'Attendance/dashboard_stats';
$route['attendance/student']                 = 'Attendance/student_attendance';
$route['attendance/staff']                   = 'Attendance/staff_attendance';
$route['attendance/settings']                = 'Attendance/settings';
$route['attendance/analytics']               = 'Attendance/analytics';
$route['attendance/punch_log']               = 'Attendance/punch_log';
$route['attendance/fetch_student']           = 'Attendance/fetch_student_attendance';
$route['attendance/save_student']            = 'Attendance/save_student_attendance';
$route['attendance/mark_student_day']        = 'Attendance/mark_student_day';
$route['attendance/bulk_mark_student']       = 'Attendance/bulk_mark_student';
$route['attendance/student_summary']         = 'Attendance/get_student_summary';
$route['attendance/fetch_staff']             = 'Attendance/fetch_staff_attendance';
$route['attendance/save_staff']              = 'Attendance/save_staff_attendance';
$route['attendance/mark_staff_day']          = 'Attendance/mark_staff_day';
$route['attendance/bulk_mark_staff']         = 'Attendance/bulk_mark_staff';
$route['attendance/get_settings']            = 'Attendance/get_settings';
$route['attendance/save_settings']           = 'Attendance/save_settings';
$route['attendance/save_holidays']           = 'Attendance/save_holidays';
$route['attendance/get_holidays']            = 'Attendance/get_holidays';
$route['attendance/register_device']         = 'Attendance/register_device';
$route['attendance/update_device']           = 'Attendance/update_device';
$route['attendance/delete_device']           = 'Attendance/delete_device';
$route['attendance/fetch_devices']           = 'Attendance/fetch_devices';
$route['attendance/regenerate_key']          = 'Attendance/regenerate_key';
$route['attendance/api_punch']               = 'Attendance/api_punch';
$route['attendance/fetch_analytics']         = 'Attendance/fetch_analytics';
$route['attendance/fetch_monthly_trend']     = 'Attendance/fetch_monthly_trend';
$route['attendance/fetch_individual_report'] = 'Attendance/fetch_individual_report';
$route['attendance/compute_summary']         = 'Attendance/compute_summary';
$route['attendance/fetch_punch_log']         = 'Attendance/fetch_punch_log';
$route['attendance/api_get_classes']         = 'Attendance/api_get_classes';
$route['attendance/api_get_students']        = 'Attendance/api_get_students';
$route['attendance/api_get_attendance']      = 'Attendance/api_get_attendance';
$route['attendance/api_mark_attendance']     = 'Attendance/api_mark_attendance';
$route['attendance/health_check']            = 'Attendance/health_check';
$route['attendance/fetch_audit_logs']        = 'Attendance/fetch_audit_logs';
$route['attendance/cleanup']                 = 'Attendance/cleanup';
$route['attendance/fix_attendance_keys']     = 'Attendance/fix_attendance_keys';
$route['attendance/autofill_staff_today']   = 'Attendance/autofill_staff_today';
$route['attendance/lock_staff_attendance']       = 'Attendance/lock_staff_attendance';
$route['attendance/unlock_staff_attendance']     = 'Attendance/unlock_staff_attendance';
$route['attendance/approve_attendance_request']  = 'Attendance/approve_attendance_request';
$route['attendance/reject_attendance_request']   = 'Attendance/reject_attendance_request';
$route['attendance/list_pending_attendance']     = 'Attendance/list_pending_attendance';

// ─── Health Checker
$route['health_check']                                   = 'Health_check/index';
$route['health_check/run']                               = 'Health_check/run';
$route['health_check/run_all']                           = 'Health_check/run_all';

// ─── Accounting System ─────────────────────────────────────────────────────────
$route['accounting']                                    = 'Accounting/index';
$route['accounting/chart']                              = 'Accounting/index';
$route['accounting/ledger']                             = 'Accounting/index';
$route['accounting/income-expense']                     = 'Accounting/index';
$route['accounting/cash-book']                          = 'Accounting/index';
$route['accounting/bank-recon']                         = 'Accounting/index';
$route['accounting/reports']                            = 'Accounting/index';
$route['accounting/settings']                           = 'Accounting/index';
// Chart of Accounts
$route['accounting/get_chart']                          = 'Accounting/get_chart';
$route['accounting/save_account']                       = 'Accounting/save_account';
$route['accounting/delete_account']                     = 'Accounting/delete_account';
$route['accounting/seed_default_chart']                 = 'Accounting/seed_default_chart';
$route['accounting/migrate_existing_accounts']          = 'Accounting/migrate_existing_accounts';
// Ledger / Journal
$route['accounting/get_ledger_entries']                 = 'Accounting/get_ledger_entries';
$route['accounting/get_next_voucher_no']                = 'Accounting/get_next_voucher_no';
$route['accounting/save_journal_entry']                 = 'Accounting/save_journal_entry';
$route['accounting/delete_journal_entry']               = 'Accounting/delete_journal_entry';
$route['accounting/finalize_entry']                     = 'Accounting/finalize_entry';
// Income & Expense
$route['accounting/get_income_expenses']                = 'Accounting/get_income_expenses';
$route['accounting/save_income_expense']                = 'Accounting/save_income_expense';
$route['accounting/delete_income_expense']              = 'Accounting/delete_income_expense';
$route['accounting/get_income_expense_summary']         = 'Accounting/get_income_expense_summary';
// Cash Book
$route['accounting/get_cash_book']                      = 'Accounting/get_cash_book';
// Bank Reconciliation
$route['accounting/get_bank_accounts']                  = 'Accounting/get_bank_accounts';
$route['accounting/get_bank_statement']                 = 'Accounting/get_bank_statement';
$route['accounting/import_bank_statement']              = 'Accounting/import_bank_statement';
$route['accounting/match_transaction']                  = 'Accounting/match_transaction';
$route['accounting/get_recon_summary']                  = 'Accounting/get_recon_summary';
// Reports
$route['accounting/trial_balance']                      = 'Accounting/trial_balance';
$route['accounting/profit_loss']                        = 'Accounting/profit_loss';
$route['accounting/balance_sheet']                      = 'Accounting/balance_sheet';
$route['accounting/cash_flow']                          = 'Accounting/cash_flow';
$route['accounting/day_book']                           = 'Accounting/day_book';
$route['accounting/export_excel']                       = 'Accounting/export_excel';
$route['accounting/export_pdf']                         = 'Accounting/export_pdf';
$route['accounting/ledger_report']                      = 'Accounting/ledger_report';
$route['accounting/recompute_balances']                 = 'Accounting/recompute_balances';
// Settings
$route['accounting/get_settings']                       = 'Accounting/get_settings';
$route['accounting/lock_period']                        = 'Accounting/lock_period';
$route['accounting/get_migration_status']               = 'Accounting/get_migration_status';
$route['accounting/unmatch_transaction']                = 'Accounting/unmatch_transaction';
$route['accounting/suggest_matches']                    = 'Accounting/suggest_matches';
$route['accounting/carry_forward_balances']             = 'Accounting/carry_forward_balances';
$route['accounting/get_audit_log']                      = 'Accounting/get_audit_log';

// ─── Staff Import ───────────────────────────────────────────────────────────────
$route['staff/master_staff']                              = 'Staff/master_staff';
$route['staff/import_staff']                              = 'Staff/import_staff';
$route['staff/download_staff_template']                   = 'Staff/download_staff_template';
$route['staff/fix_staff_count']                           = 'Staff/fix_staff_count';

// ─── Staff Role Management ──────────────────────────────────────────────────────
$route['staff/get_staff_roles']                            = 'Staff/get_staff_roles';
$route['staff/save_staff_role']                            = 'Staff/save_staff_role';
$route['staff/delete_staff_role']                          = 'Staff/delete_staff_role';
$route['staff/get_staff_by_role']                          = 'Staff/get_staff_by_role';
$route['staff/migrate_staff_roles']                        = 'Staff/migrate_staff_roles';

// ─── HR & Staff Management ───────────────────────────────────────────────────────
// Page routes (all map to Hr::index — tab determined by URI segment 2)
$route['hr']                                               = 'Hr/index';
$route['hr/dashboard']                                     = 'Hr/index';
$route['hr/departments']                                   = 'Hr/index';
$route['hr/recruitment']                                   = 'Hr/index';
$route['hr/leaves']                                        = 'Hr/index';
$route['hr/payroll']                                       = 'Hr/index';
$route['hr/appraisals']                                    = 'Hr/index';
// Dashboard
$route['hr/get_dashboard']                                 = 'Hr/get_dashboard';
// Departments
$route['hr/get_departments']                               = 'Hr/get_departments';
$route['hr/save_department']                               = 'Hr/save_department';
$route['hr/delete_department']                             = 'Hr/delete_department';
// Recruitment — Jobs
$route['hr/get_jobs']                                      = 'Hr/get_jobs';
$route['hr/save_job']                                      = 'Hr/save_job';
$route['hr/delete_job']                                    = 'Hr/delete_job';
$route['hr/view_circular']                                 = 'Hr/view_circular';
$route['hr/regenerate_circular']                           = 'Hr/regenerate_circular';
// Recruitment — Applicants
$route['hr/get_applicants']                                = 'Hr/get_applicants';
$route['hr/save_applicant']                                = 'Hr/save_applicant';
$route['hr/update_applicant_status']                       = 'Hr/update_applicant_status';
$route['hr/delete_applicant']                              = 'Hr/delete_applicant';

// ─── Applicant Tracking System (ATS) ────────────────────────────────────────
$route['ats']                                              = 'Ats/index';
$route['ats/get_pipeline']                                 = 'Ats/get_pipeline';
$route['ats/get_applicant']                                = 'Ats/get_applicant';
$route['ats/save_applicant']                               = 'Ats/save_applicant';
$route['ats/delete_applicant']                             = 'Ats/delete_applicant';
$route['ats/move_stage']                                   = 'Ats/move_stage';
$route['ats/reject_applicant']                             = 'Ats/reject_applicant';
$route['ats/add_review']                                   = 'Ats/add_review';
$route['ats/get_reviews']                                  = 'Ats/get_reviews';
$route['ats/get_convert_data']                             = 'Ats/get_convert_data';
$route['ats/finalize_hire']                                = 'Ats/finalize_hire';
$route['ats/get_jobs']                                     = 'Ats/get_jobs';

// Leave Management
$route['hr/get_leave_types']                               = 'Hr/get_leave_types';
$route['hr/save_leave_type']                               = 'Hr/save_leave_type';
$route['hr/delete_leave_type']                             = 'Hr/delete_leave_type';
$route['hr/seed_leave_types']                              = 'Hr/seed_leave_types';
$route['hr/get_leave_audit_log']                           = 'Hr/get_leave_audit_log';
$route['hr/get_leave_balances']                            = 'Hr/get_leave_balances';
$route['hr/allocate_leave_balances']                       = 'Hr/allocate_leave_balances';
$route['hr/get_leave_requests']                            = 'Hr/get_leave_requests';
$route['hr/apply_leave']                                   = 'Hr/apply_leave';
$route['hr/decide_leave']                                  = 'Hr/decide_leave';
$route['hr/cancel_leave']                                  = 'Hr/cancel_leave';
// Salary & Payroll
$route['hr/get_salary_structures']                         = 'Hr/get_salary_structures';
$route['hr/save_salary_structure']                         = 'Hr/save_salary_structure';
$route['hr/delete_salary_structure']                        = 'Hr/delete_salary_structure';
$route['hr/get_payroll_runs']                              = 'Hr/get_payroll_runs';
$route['hr/preflight_payroll']                             = 'Hr/preflight_payroll';
$route['hr/generate_payroll']                              = 'Hr/generate_payroll';
$route['hr/auto_create_payroll_accounts']                  = 'Hr/auto_create_payroll_accounts';
$route['hr/get_payroll_slips']                             = 'Hr/get_payroll_slips';
$route['hr/finalize_payroll']                              = 'Hr/finalize_payroll';
$route['hr/mark_payroll_paid']                             = 'Hr/mark_payroll_paid';
$route['hr/get_payslip']                                   = 'Hr/get_payslip';
$route['hr/my_payslips']                                   = 'Hr/my_payslips';
$route['hr/backfill_salary_structures']                    = 'Hr/backfill_salary_structures';
$route['hr/lock_payroll_month']                            = 'Hr/lock_payroll_month';
$route['hr/approve_payroll']                               = 'Hr/approve_payroll';
$route['hr/download_payslip']                              = 'Hr/download_payslip';
$route['hr/export_payroll_report']                         = 'Hr/export_payroll_report';
$route['hr/delete_payroll_run']                            = 'Hr/delete_payroll_run';
// Appraisals
$route['hr/get_appraisals']                                = 'Hr/get_appraisals';
$route['hr/save_appraisal']                                = 'Hr/save_appraisal';
$route['hr/submit_appraisal']                              = 'Hr/submit_appraisal';
$route['hr/review_appraisal']                              = 'Hr/review_appraisal';
$route['hr/delete_appraisal']                              = 'Hr/delete_appraisal';
// Utility
$route['hr/get_staff_list']                                = 'Hr/get_staff_list';
$route['hr/get_report']                                    = 'Hr/get_report';

// ─── Notifications & Workflow ──────────────────────────────────────────────────
$route['notifications/get_tasks']                          = 'Notifications/get_tasks';
$route['notifications/dismiss_alert']                      = 'Notifications/dismiss_alert';

// ─── Fee & Finance Management ──────────────────────────────────────────────────
// Pages
$route['fee_management/categories']                      = 'Fee_management/categories';
$route['fee_management/discounts']                       = 'Fee_management/discounts';
$route['fee_management/scholarships']                    = 'Fee_management/scholarships';
$route['fee_management/refunds']                         = 'Fee_management/refunds';
$route['fee_management/reminders']                       = 'Fee_management/reminders';
$route['fee_management/gateway']                         = 'Fee_management/gateway';
$route['fee_management/online_payments']                 = 'Fee_management/online_payments';
// Fee Titles AJAX
$route['fee_management/fetch_fee_titles']                = 'Fee_management/fetch_fee_titles';
$route['fee_management/save_fee_title']                  = 'Fee_management/save_fee_title';
$route['fee_management/delete_fee_title']                = 'Fee_management/delete_fee_title';
// Categories AJAX
$route['fee_management/fetch_categories']                = 'Fee_management/fetch_categories';
$route['fee_management/save_category']                   = 'Fee_management/save_category';
$route['fee_management/delete_category']                 = 'Fee_management/delete_category';
// Discounts AJAX
$route['fee_management/fetch_discounts']                 = 'Fee_management/fetch_discounts';
$route['fee_management/save_discount']                   = 'Fee_management/save_discount';
$route['fee_management/delete_discount']                 = 'Fee_management/delete_discount';
$route['fee_management/fetch_eligible_students']         = 'Fee_management/fetch_eligible_students';
$route['fee_management/apply_discount']                  = 'Fee_management/apply_discount';
// Scholarships AJAX
$route['fee_management/fetch_scholarships']              = 'Fee_management/fetch_scholarships';
$route['fee_management/save_scholarship']                = 'Fee_management/save_scholarship';
$route['fee_management/delete_scholarship']              = 'Fee_management/delete_scholarship';
$route['fee_management/fetch_awards']                    = 'Fee_management/fetch_awards';
$route['fee_management/award_scholarship']               = 'Fee_management/award_scholarship';
$route['fee_management/revoke_scholarship']              = 'Fee_management/revoke_scholarship';
// Refunds AJAX
$route['fee_management/fetch_refunds']                   = 'Fee_management/fetch_refunds';
$route['fee_management/create_refund']                   = 'Fee_management/create_refund';
$route['fee_management/update_refund_status']            = 'Fee_management/update_refund_status';
$route['fee_management/process_refund']                  = 'Fee_management/process_refund';
$route['fee_management/approve_refund']                  = 'Fee_management/approve_refund';
$route['fee_management/reject_refund']                   = 'Fee_management/reject_refund';
// Reminders AJAX
$route['fee_management/get_reminder_settings']           = 'Fee_management/get_reminder_settings';
$route['fee_management/save_reminder_settings']          = 'Fee_management/save_reminder_settings';
$route['fee_management/fetch_due_students']              = 'Fee_management/fetch_due_students';
$route['fee_management/send_reminder']                   = 'Fee_management/send_reminder';
$route['fee_management/fetch_reminder_log']              = 'Fee_management/fetch_reminder_log';
// Gateway AJAX
$route['fee_management/get_gateway_config']              = 'Fee_management/get_gateway_config';
$route['fee_management/save_gateway_config']             = 'Fee_management/save_gateway_config';
$route['fee_management/fetch_online_payments']           = 'Fee_management/fetch_online_payments';
$route['fee_management/create_payment_order']            = 'Fee_management/create_payment_order';
$route['fee_management/simulate_payment']                = 'Fee_management/simulate_payment';
$route['fee_management/verify_payment']                  = 'Fee_management/verify_payment';
$route['fee_management/payment_webhook']                 = 'Fee_management/payment_webhook';
$route['fee_management/retry_payment_processing']        = 'Fee_management/retry_payment_processing';
$route['fee_management/payment_reconciliation']          = 'Fee_management/payment_reconciliation';
$route['fee_management/get_reconciliation_data']         = 'Fee_management/get_reconciliation_data';
// Summary
$route['fee_management/get_fee_summary']                 = 'Fee_management/get_fee_summary';
// Carry-forward (F-15)
$route['fee_management/carry_forward_fees']              = 'Fee_management/carry_forward_fees';
$route['fee_management/migrate_to_demands']             = 'Fee_management/migrate_to_demands';

// ============================================================================
//  OPERATIONS MANAGEMENT
// ============================================================================

// Dashboard
$route['operations']                        = 'Operations/index';
$route['operations/get_summary']            = 'Operations/get_summary';

// Library
$route['library']                           = 'Library/index';
$route['library/catalog']                   = 'Library/index';
$route['library/categories']                = 'Library/index';
$route['library/issues']                    = 'Library/index';
$route['library/fines']                     = 'Library/index';
$route['library/reports']                   = 'Library/index';
$route['library/get_categories']            = 'Library/get_categories';
$route['library/save_category']             = 'Library/save_category';
$route['library/delete_category']           = 'Library/delete_category';
$route['library/get_books']                 = 'Library/get_books';
$route['library/save_book']                 = 'Library/save_book';
$route['library/delete_book']              = 'Library/delete_book';
$route['library/issue_book']                = 'Library/issue_book';
$route['library/return_book']               = 'Library/return_book';
$route['library/get_issues']                = 'Library/get_issues';
$route['library/get_fines']                 = 'Library/get_fines';
$route['library/pay_fine']                  = 'Library/pay_fine';
$route['library/get_reports']               = 'Library/get_reports';
$route['library/search_students']           = 'Library/search_students';

// Transport
$route['transport']                         = 'Transport/index';
$route['transport/vehicles']                = 'Transport/index';
$route['transport/routes']                  = 'Transport/index';
$route['transport/assignments']             = 'Transport/index';
$route['transport/get_vehicles']            = 'Transport/get_vehicles';
$route['transport/save_vehicle']            = 'Transport/save_vehicle';
$route['transport/delete_vehicle']          = 'Transport/delete_vehicle';
$route['transport/get_routes']              = 'Transport/get_routes';
$route['transport/save_route']              = 'Transport/save_route';
$route['transport/delete_route']            = 'Transport/delete_route';
$route['transport/get_stops']               = 'Transport/get_stops';
$route['transport/save_stop']               = 'Transport/save_stop';
$route['transport/delete_stop']             = 'Transport/delete_stop';
$route['transport/get_assignments']         = 'Transport/get_assignments';
$route['transport/save_assignment']         = 'Transport/save_assignment';
$route['transport/delete_assignment']       = 'Transport/delete_assignment';
$route['transport/search_students']         = 'Transport/search_students';

// Hostel
$route['hostel']                            = 'Hostel/index';
$route['hostel/buildings']                  = 'Hostel/index';
$route['hostel/rooms']                      = 'Hostel/index';
$route['hostel/allocations']                = 'Hostel/index';
$route['hostel/attendance']                 = 'Hostel/index';
$route['hostel/get_buildings']              = 'Hostel/get_buildings';
$route['hostel/save_building']              = 'Hostel/save_building';
$route['hostel/delete_building']            = 'Hostel/delete_building';
$route['hostel/get_rooms']                  = 'Hostel/get_rooms';
$route['hostel/save_room']                  = 'Hostel/save_room';
$route['hostel/delete_room']                = 'Hostel/delete_room';
$route['hostel/get_allocations']            = 'Hostel/get_allocations';
$route['hostel/save_allocation']            = 'Hostel/save_allocation';
$route['hostel/delete_allocation']          = 'Hostel/delete_allocation';
$route['hostel/get_attendance']             = 'Hostel/get_attendance';
$route['hostel/save_attendance']            = 'Hostel/save_attendance';
$route['hostel/get_stats']                  = 'Hostel/get_stats';
$route['hostel/search_students']            = 'Hostel/search_students';

// Inventory
$route['inventory']                         = 'Inventory/index';
$route['inventory/items']                   = 'Inventory/index';
$route['inventory/categories']              = 'Inventory/index';
$route['inventory/vendors']                 = 'Inventory/index';
$route['inventory/purchases']               = 'Inventory/index';
$route['inventory/stock']                   = 'Inventory/index';
$route['inventory/get_categories']          = 'Inventory/get_categories';
$route['inventory/save_category']           = 'Inventory/save_category';
$route['inventory/delete_category']         = 'Inventory/delete_category';
$route['inventory/get_items']               = 'Inventory/get_items';
$route['inventory/save_item']               = 'Inventory/save_item';
$route['inventory/delete_item']             = 'Inventory/delete_item';
$route['inventory/get_vendors']             = 'Inventory/get_vendors';
$route['inventory/save_vendor']             = 'Inventory/save_vendor';
$route['inventory/delete_vendor']           = 'Inventory/delete_vendor';
$route['inventory/get_purchases']           = 'Inventory/get_purchases';
$route['inventory/save_purchase']           = 'Inventory/save_purchase';
$route['inventory/get_issues']              = 'Inventory/get_issues';
$route['inventory/save_issue']              = 'Inventory/save_issue';
$route['inventory/return_issue']            = 'Inventory/return_issue';
$route['inventory/get_stock_report']        = 'Inventory/get_stock_report';

// Assets
$route['assets']                            = 'Assets/index';
$route['assets/registry']                   = 'Assets/index';
$route['assets/categories']                 = 'Assets/index';
$route['assets/assignments']                = 'Assets/index';
$route['assets/maintenance']                = 'Assets/index';
$route['assets/depreciation']               = 'Assets/index';
$route['assets/get_categories']             = 'Assets/get_categories';
$route['assets/save_category']              = 'Assets/save_category';
$route['assets/delete_category']            = 'Assets/delete_category';
$route['assets/get_assets']                 = 'Assets/get_assets';
$route['assets/save_asset']                 = 'Assets/save_asset';
$route['assets/delete_asset']               = 'Assets/delete_asset';
$route['assets/get_assignments']            = 'Assets/get_assignments';
$route['assets/save_assignment']            = 'Assets/save_assignment';
$route['assets/return_assignment']          = 'Assets/return_assignment';
$route['assets/get_maintenance']            = 'Assets/get_maintenance';
$route['assets/save_maintenance']           = 'Assets/save_maintenance';
$route['assets/compute_depreciation']       = 'Assets/compute_depreciation';
$route['assets/get_depreciation_report']    = 'Assets/get_depreciation_report';

// ============================================================================
//  COMMUNICATION MODULE
// ============================================================================

// Pages
$route['communication']                              = 'Communication/index';
$route['communication/messages']                     = 'Communication/messages';
$route['communication/notices']                      = 'Communication/notices';
$route['communication/circulars']                    = 'Communication/circulars';
$route['communication/templates']                    = 'Communication/templates';
$route['communication/triggers']                     = 'Communication/triggers';
$route['communication/queue']                        = 'Communication/queue';
$route['communication/logs']                         = 'Communication/logs';

// Dashboard
$route['communication/get_dashboard']                = 'Communication/get_dashboard';

// Messaging
$route['communication/get_conversations']            = 'Communication/get_conversations';
$route['communication/get_messages']                 = 'Communication/get_messages';
$route['communication/create_conversation']          = 'Communication/create_conversation';
$route['communication/send_message']                 = 'Communication/send_message';
$route['communication/mark_read']                    = 'Communication/mark_read';
$route['communication/get_unread_count']             = 'Communication/get_unread_count';
$route['communication/search_recipients']            = 'Communication/search_recipients';

// Notices
$route['communication/get_notices']                  = 'Communication/get_notices';
$route['communication/save_notice']                  = 'Communication/save_notice';
$route['communication/delete_notice']                = 'Communication/delete_notice';

// Circulars
$route['communication/get_circulars']                = 'Communication/get_circulars';
$route['communication/save_circular']                = 'Communication/save_circular';
$route['communication/delete_circular']              = 'Communication/delete_circular';
$route['communication/acknowledge_circular']         = 'Communication/acknowledge_circular';

// Templates
$route['communication/get_templates']                = 'Communication/get_templates';
$route['communication/save_template']                = 'Communication/save_template';
$route['communication/delete_template']              = 'Communication/delete_template';
$route['communication/preview_template']             = 'Communication/preview_template';

// Triggers
$route['communication/get_triggers']                 = 'Communication/get_triggers';
$route['communication/save_trigger']                 = 'Communication/save_trigger';
$route['communication/delete_trigger']               = 'Communication/delete_trigger';
$route['communication/toggle_trigger']               = 'Communication/toggle_trigger';

// Queue
$route['communication/get_queue']                    = 'Communication/get_queue';
$route['communication/process_queue']                = 'Communication/process_queue';
$route['communication/cancel_queued']                = 'Communication/cancel_queued';
$route['communication/retry_failed']                 = 'Communication/retry_failed';

// Logs
$route['communication/get_logs']                     = 'Communication/get_logs';
$route['communication/get_log_stats']                = 'Communication/get_log_stats';

// Bulk Send & Helpers
$route['communication/send_bulk']                    = 'Communication/send_bulk';
$route['communication/get_target_groups']            = 'Communication/get_target_groups';

// ─── Events & Activities ───────────────────────────────────────────────────────
// Pages
$route['events']                                     = 'Events/index';
$route['events/list']                                = 'Events/list';
$route['events/calendar']                            = 'Events/calendar';
$route['events/participation']                       = 'Events/participation';
$route['events/circular/(:any)']                     = 'Events/circular/$1';

// Dashboard
$route['events/get_dashboard']                       = 'Events/get_dashboard';

// Event CRUD
$route['events/get_events']                          = 'Events/get_events';
$route['events/get_event']                           = 'Events/get_event';
$route['events/save_event']                          = 'Events/save_event';
$route['events/delete_event']                        = 'Events/delete_event';
$route['events/update_status']                       = 'Events/update_status';

// Calendar
$route['events/get_calendar']                        = 'Events/get_calendar';

// Participation
$route['events/get_participants']                    = 'Events/get_participants';
$route['events/save_participant']                    = 'Events/save_participant';
$route['events/remove_participant']                  = 'Events/remove_participant';
$route['events/mark_attendance']                     = 'Events/mark_attendance';
$route['events/search_people']                       = 'Events/search_people';

// ─── Learning Management System (LMS) ──────────────────────────────────────────
// Page — clean URLs for each tab
$route['lms']                                        = 'Lms/index';
$route['lms/classes']                                = 'Lms/index/classes';
$route['lms/materials']                              = 'Lms/index/materials';
$route['lms/assignments']                            = 'Lms/index/assignments';
$route['lms/quizzes']                                = 'Lms/index/quizzes';

// Shared data
$route['lms/get_classes_subjects']                   = 'Lms/get_classes_subjects';
$route['lms/get_dashboard']                          = 'Lms/get_dashboard';

// Online Classes
$route['lms/get_classes']                            = 'Lms/get_classes';
$route['lms/save_class']                             = 'Lms/save_class';
$route['lms/delete_class']                           = 'Lms/delete_class';

// Study Materials
$route['lms/get_materials']                          = 'Lms/get_materials';
$route['lms/save_material']                          = 'Lms/save_material';
$route['lms/delete_material']                        = 'Lms/delete_material';

// Assignments
$route['lms/get_assignments']                        = 'Lms/get_assignments';
$route['lms/save_assignment']                        = 'Lms/save_assignment';
$route['lms/delete_assignment']                      = 'Lms/delete_assignment';
$route['lms/get_submissions']                        = 'Lms/get_submissions';
$route['lms/grade_submission']                       = 'Lms/grade_submission';

// Quizzes
$route['lms/get_quizzes']                            = 'Lms/get_quizzes';
$route['lms/get_quiz']                               = 'Lms/get_quiz';
$route['lms/save_quiz']                              = 'Lms/save_quiz';
$route['lms/delete_quiz']                            = 'Lms/delete_quiz';
$route['lms/get_quiz_attempts']                      = 'Lms/get_quiz_attempts';

// Student View & Submissions
$route['lms/get_student_classes']                    = 'Lms/get_student_classes';
$route['lms/get_student_materials']                  = 'Lms/get_student_materials';
$route['lms/get_student_assignments']                = 'Lms/get_student_assignments';
$route['lms/get_student_quizzes']                    = 'Lms/get_student_quizzes';
$route['lms/get_student_quiz']                       = 'Lms/get_student_quiz';
$route['lms/submit_assignment']                      = 'Lms/submit_assignment';
$route['lms/delete_submission']                      = 'Lms/delete_submission';
$route['lms/submit_quiz_attempt']                    = 'Lms/submit_quiz_attempt';
$route['lms/rebuild_submission_count']               = 'Lms/rebuild_submission_count';
$route['lms/rebuild_attempt_count']                  = 'Lms/rebuild_attempt_count';

// ── Certificate Management ─────────────────────────────────────────────
// Pages — clean URLs for each tab
$route['certificates']                               = 'Certificates/index';
$route['certificates/templates']                     = 'Certificates/index/templates';
$route['certificates/generate']                      = 'Certificates/index/generate';
$route['certificates/issued']                        = 'Certificates/index/issued';

// AJAX endpoints
$route['certificates/get_dashboard']                 = 'Certificates/get_dashboard';
$route['certificates/get_classes']                   = 'Certificates/get_classes';
$route['certificates/get_templates']                 = 'Certificates/get_templates';
$route['certificates/save_template']                 = 'Certificates/save_template';
$route['certificates/delete_template']               = 'Certificates/delete_template';
$route['certificates/get_students']                  = 'Certificates/get_students';
$route['certificates/get_student_details']           = 'Certificates/get_student_details';
$route['certificates/generate_certificate']          = 'Certificates/generate_certificate';
$route['certificates/get_issued']                    = 'Certificates/get_issued';
$route['certificates/get_certificate']               = 'Certificates/get_certificate';
$route['certificates/revoke_certificate']            = 'Certificates/revoke_certificate';
$route['certificates/get_school_profile']            = 'Certificates/get_school_profile';

/* ── School Backup ─────────────────────────────────────────────────── */
$route['school_backup']                              = 'School_backup/index';
$route['school_backup/get_backups']                  = 'School_backup/get_backups';
$route['school_backup/get_schedule']                 = 'School_backup/get_schedule';
$route['school_backup/save_schedule']                = 'School_backup/save_schedule';
$route['school_backup/create_backup']                = 'School_backup/create_backup';
$route['school_backup/download/(:any)']              = 'School_backup/download/$1';

/* ── Admin Users (IAM) ─────────────────────────────────────────────── */
$route['admin_users']                                = 'AdminUsers/index';
$route['admin_users/get_dashboard']                  = 'AdminUsers/get_dashboard';
$route['admin_users/get_admins']                     = 'AdminUsers/get_admins';
$route['admin_users/create_admin']                   = 'AdminUsers/create_admin';
$route['admin_users/update_admin']                   = 'AdminUsers/update_admin';
$route['admin_users/disable_admin']                  = 'AdminUsers/disable_admin';
$route['admin_users/delete_admin']                   = 'AdminUsers/delete_admin';
$route['admin_users/reset_password']                 = 'AdminUsers/reset_password';
$route['admin_users/get_roles']                      = 'AdminUsers/get_roles';
$route['admin_users/save_role']                      = 'AdminUsers/save_role';
$route['admin_users/delete_role']                    = 'AdminUsers/delete_role';
$route['admin_users/get_login_logs']                 = 'AdminUsers/get_login_logs';


// ─── Stories Management ──────────────────────────────────────────────────────
$route['stories']                                    = 'Stories/index';
$route['stories/get_stories']                        = 'Stories/get_stories';
$route['stories/get_story_detail']                   = 'Stories/get_story_detail';
$route['stories/get_analytics']                      = 'Stories/get_analytics';
$route['stories/get_teachers']                       = 'Stories/get_teachers';
$route['stories/moderate_story']                     = 'Stories/moderate_story';
$route['stories/delete_story']                       = 'Stories/delete_story';
$route['stories/bulk_moderate']                      = 'Stories/bulk_moderate';

// ─── Red Flags Dashboard ─────────────────────────────────────────────────────
$route['red_flags']                                  = 'Red_flags/index';
$route['red_flags/get_overview']                     = 'Red_flags/get_overview';
$route['red_flags/get_flags']                        = 'Red_flags/get_flags';
$route['red_flags/get_flag_detail']                  = 'Red_flags/get_flag_detail';
$route['red_flags/resolve_flag']                     = 'Red_flags/resolve_flag';
$route['red_flags/delete_flag']                      = 'Red_flags/delete_flag';
$route['red_flags/get_student_flags']                = 'Red_flags/get_student_flags';
$route['red_flags/get_class_summary']                = 'Red_flags/get_class_summary';
$route['red_flags/get_teacher_activity']             = 'Red_flags/get_teacher_activity';
$route['red_flags/get_trend_data']                   = 'Red_flags/get_trend_data';
$route['red_flags/bulk_resolve']                     = 'Red_flags/bulk_resolve';
$route['red_flags/add_flag']                         = 'Red_flags/add_flag';
$route['red_flags/get_students_for_class']           = 'Red_flags/get_students_for_class';

// ─── Device Management ───────────────────────────────────────────────────────
$route['device_management']                          = 'Device_management/index';
$route['device_management/search_user']              = 'Device_management/search_user';
$route['device_management/get_devices/(:any)']       = 'Device_management/get_devices/$1';
$route['device_management/list_devices']             = 'Device_management/list_devices';
$route['device_management/remove_device']            = 'Device_management/remove_device';
$route['device_management/block_device']             = 'Device_management/block_device';
$route['device_management/unblock_device']           = 'Device_management/unblock_device';
$route['device_management/get_overview']             = 'Device_management/get_overview';
$route['device_management/get_all_users_devices']    = 'Device_management/get_all_users_devices';
$route['device_management/bulk_remove']              = 'Device_management/bulk_remove';

// ─── Message Monitor ─────────────────────────────────────────────────────────
$route['message_monitor']                            = 'Message_monitor/index';
$route['message_monitor/get_conversations']          = 'Message_monitor/get_conversations';
$route['message_monitor/get_conversation_detail']    = 'Message_monitor/get_conversation_detail';
$route['message_monitor/get_analytics']              = 'Message_monitor/get_analytics';
$route['message_monitor/search_messages']            = 'Message_monitor/search_messages';
$route['message_monitor/get_teacher_stats']          = 'Message_monitor/get_teacher_stats';
$route['message_monitor/delete_message']             = 'Message_monitor/delete_message';
$route['message_monitor/get_activity_timeline']      = 'Message_monitor/get_activity_timeline';
$route['message_monitor/get_flagged_content']        = 'Message_monitor/get_flagged_content';
$route['message_monitor/save_flagged_keywords']      = 'Message_monitor/save_flagged_keywords';
$route['message_monitor/export_conversation']        = 'Message_monitor/export_conversation';
$route['message_monitor/moderate_message']           = 'Message_monitor/moderate_message';

// ─── Homework Tracking ──────────────────────────────────────────────────────
$route['homework']                                   = 'Homework/index';
$route['homework/get_overview']                      = 'Homework/get_overview';
$route['homework/get_homework_list']                 = 'Homework/get_homework_list';
$route['homework/get_homework_detail']               = 'Homework/get_homework_detail';
$route['homework/get_submissions']                   = 'Homework/get_submissions';
$route['homework/get_class_summary']                 = 'Homework/get_class_summary';
$route['homework/get_teacher_activity']              = 'Homework/get_teacher_activity';
$route['homework/get_subject_breakdown']             = 'Homework/get_subject_breakdown';
$route['homework/get_overdue_report']                = 'Homework/get_overdue_report';
$route['homework/get_trend_data']                    = 'Homework/get_trend_data';
$route['homework/get_students_for_class']            = 'Homework/get_students_for_class';
$route['homework/create_homework']                   = 'Homework/create_homework';
$route['homework/update_homework']                   = 'Homework/update_homework';
$route['homework/delete_homework']                   = 'Homework/delete_homework';
$route['homework/close_homework']                    = 'Homework/close_homework';

/* ── Audit Logs ───────────────────────────────────────────────────── */
$route['audit_logs']                                 = 'AuditLogs/index';
$route['audit_logs/get_logs']                        = 'AuditLogs/get_logs';
$route['audit_logs/filter_logs']                     = 'AuditLogs/filter_logs';
$route['audit_logs/get_user_activity']               = 'AuditLogs/get_user_activity';
$route['audit_logs/get_stats']                       = 'AuditLogs/get_stats';
$route['audit_logs/archive_old']                     = 'AuditLogs/archive_old';

