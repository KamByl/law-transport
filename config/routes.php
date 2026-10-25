<?php

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$routes = new RouteCollection();

$routes->add('smsapi_delivery_report', new Route('/smsapi/delivery-report', [
    '_controller' => 'App\\Controller\\SmsDeliveryReportController::receive',
], [], [], '', [], ['GET', 'POST']));

$routes->add('home', new Route('/', [
    '_controller' => 'App\\Controller\\FrontendController::home',
]));

$routes->add('meta_json', new Route('/meta.json', [
    '_controller' => 'App\\Controller\\FrontendController::metaJson',
], [], [], '', [], ['GET']));

$routes->add('age_gate_confirm', new Route('/age-gate/confirm', [
    '_controller' => 'App\\Controller\\FrontendController::confirmAge',
], [], [], '', [], ['POST']));

$routes->add('ean_code_jpg', new Route('/kod-ean/{code}.jpg', [
    '_controller' => 'App\\Controller\\EanCodeController::jpg',
], [
    'code' => '[0-9][0-9\\- ]{10,30}[0-9]',
], [], '', [], ['GET']));

$routes->add('code_128_code_jpg', new Route('/kod-code-128/{code}.jpg', [
    '_controller' => 'App\\Controller\\Code128CodeController::jpg',
], [
    'code' => '[^/]+',
], [], '', [], ['GET']));


$routes->add('loteria_m1', new Route('/loteria', [
    '_controller' => 'App\\Controller\\FrontendController::loteriaM1',
]));

$routes->add('download_pretty_file', new Route('/pliki/{slug}', [
    '_controller' => 'App\\Controller\\FrontendController::downloadPrettyFile',
], [
    'slug' => '.+',
]));

$routes->add('media_module_upload', new Route('/media/modules/{moduleKey}/{filename}', [
    '_controller' => 'App\\Controller\\ModuleController::serveUpload',
], [
    'moduleKey' => '[A-Za-z0-9_-]+',
    'filename' => '.+',
], [], '', [], ['GET']));

$routes->add('media_winner_receipt', new Route('/media/winner-receipts/{filename}', [
    '_controller' => 'App\\Controller\\FrontendController::serveWinnerReceipt',
], [
    'filename' => '.+',
], [], '', [], ['GET']));




$routes->add('lottery_losowane_zdrapki_submit', new Route('/loteria/losowane-zdrapki', [
    '_controller' => 'App\\Controller\\FrontendController::submitLosowaneZdrapki',
], [], [], '', [], ['POST']));


$routes->add('lottery_losowane_zdrapki_check', new Route('/loteria/losowane-zdrapki/sprawdz-wynik', [
    '_controller' => 'App\\Controller\\FrontendController::checkLosowaneZdrapkiTicket',
], [], [], '', [], ['POST']));

$routes->add('lottery_winners_list', new Route('/loteria/wyniki', [
    '_controller' => 'App\\Controller\\FrontendController::winnersList',
], [], [], '', [], ['GET']));

$routes->add('graphic_code_image', new Route('/kod-graficzny/{token}', [
    '_controller' => 'App\\Controller\\GraphicCodeController::image',
], [
    'token' => '[a-fA-F0-9]{64}',
], [], '', [], ['GET']));

$routes->add('lottery_losowane_zdrapki_reveal_link', new Route('/loteria/zdrapki/{token}', [
    '_controller' => 'App\\Controller\\FrontendController::revealRemainingScratchcards',
], [], [], '', [], ['GET']));

$routes->add('lottery_winner_form', new Route('/loteria/zwyciezca/{token}', [
    '_controller' => 'App\\Controller\\FrontendController::winnerForm',
], [], [], '', [], ['GET', 'POST']));

$routes->add('cron_run_configured', new Route('/cron/run', [
    '_controller' => 'App\\Controller\\FrontendController::runConfiguredCronTasks',
], [], [], '', [], ['GET']));

$routes->add('cms_login', new Route('/cms/login', [
    '_controller' => 'App\\Controller\\AuthController::login',
]));

$routes->add('cms_logout', new Route('/cms/logout', [
    '_controller' => 'App\\Controller\\AuthController::logout',
]));

$routes->add('cms_dashboard', new Route('/cms', [
    '_controller' => 'App\\Controller\\BackendController::dashboard',
]));

$routes->add('cms_dashboard_preferences', new Route('/cms/dashboard/preferences', [
    '_controller' => 'App\\Controller\\BackendController::dashboardPreferences',
]));

$routes->add('cms_dashboard_preferences_update', new Route('/cms/dashboard/preferences/update', [
    '_controller' => 'App\\Controller\\BackendController::updateDashboardPreferences',
], [], [], '', [], ['POST']));


$routes->add('cms_menu_preferences', new Route('/cms/menu/preferences', [
    '_controller' => 'App\Controller\BackendController::menuPreferences',
]));

$routes->add('cms_menu_preferences_update', new Route('/cms/menu/preferences/update', [
    '_controller' => 'App\Controller\BackendController::updateMenuPreferences',
], [], [], '', [], ['POST']));

$routes->add('cms_menu_preferences_reset', new Route('/cms/menu/preferences/reset', [
    '_controller' => 'App\Controller\BackendController::resetMenuPreferences',
], [], [], '', [], ['POST']));

$routes->add('cms_install', new Route('/cms/install', [
    '_controller' => 'App\\Controller\\BackendController::install',
]));

$routes->add('cms_users', new Route('/cms/users', [
    '_controller' => 'App\\Controller\\UserController::index',
]));

$routes->add('cms_users_create', new Route('/cms/users/create', [
    '_controller' => 'App\\Controller\\UserController::create',
]));

$routes->add('cms_users_edit', new Route('/cms/users/{id}/edit', [
    '_controller' => 'App\\Controller\\UserController::edit',
]));

$routes->add('cms_users_impersonate', new Route('/cms/users/{id}/impersonate', [
    '_controller' => 'App\\Controller\\UserController::impersonate',
], [], [], '', [], ['POST']));

$routes->add('cms_users_impersonate_stop', new Route('/cms/users/impersonate/stop', [
    '_controller' => 'App\\Controller\\UserController::stopImpersonation',
], [], [], '', [], ['POST']));

$routes->add('cms_users_delete', new Route('/cms/users/{id}/delete', [
    '_controller' => 'App\\Controller\\UserController::delete',
], [], [], '', [], ['POST']));

$routes->add('cms_groups', new Route('/cms/groups', [
    '_controller' => 'App\\Controller\\GroupController::index',
]));

$routes->add('cms_groups_create', new Route('/cms/groups/create', [
    '_controller' => 'App\\Controller\\GroupController::create',
]));

$routes->add('cms_groups_edit', new Route('/cms/groups/{id}/edit', [
    '_controller' => 'App\\Controller\\GroupController::edit',
]));

$routes->add('cms_groups_delete', new Route('/cms/groups/{id}/delete', [
    '_controller' => 'App\\Controller\\GroupController::delete',
], [], [], '', [], ['POST']));

$routes->add('cms_permissions', new Route('/cms/permissions', [
    '_controller' => 'App\\Controller\\PermissionController::index',
]));

$routes->add('cms_permissions_create', new Route('/cms/permissions/create', [
    '_controller' => 'App\\Controller\\PermissionController::create',
]));

$routes->add('cms_permissions_edit', new Route('/cms/permissions/{id}/edit', [
    '_controller' => 'App\\Controller\\PermissionController::edit',
]));

$routes->add('cms_permissions_delete', new Route('/cms/permissions/{id}/delete', [
    '_controller' => 'App\\Controller\\PermissionController::delete',
], [], [], '', [], ['POST']));

$routes->add('cms_login_logs', new Route('/cms/login-logs', [
    '_controller' => 'App\\Controller\\LoginLogController::index',
]));

$routes->add('cms_module_change_logs', new Route('/cms/module-change-logs', [
    '_controller' => 'App\\Controller\\ModuleChangeLogController::index',
]));

$routes->add('cms_sms_logs', new Route('/cms/sms-logs', [
    '_controller' => 'App\\Controller\\SmsLogController::index',
]));

$routes->add('cms_email_logs', new Route('/cms/email-logs', [
    '_controller' => 'App\\Controller\\EmailLogController::index',
]));

$routes->add('cms_error_logs', new Route('/cms/error-logs', [
    '_controller' => 'App\\Controller\\ErrorLogController::index',
]));


$routes->add('cms_email_queue', new Route('/cms/email-queue', [
    '_controller' => 'App\Controller\EmailQueueController::index',
]));

$routes->add('cms_email_queue_test_send', new Route('/cms/email-queue/test-send', [
    '_controller' => 'App\Controller\EmailQueueController::sendTestEmail',
], [], [], '', [], ['POST']));

$routes->add('cms_email_queue_retry_all_failed', new Route('/cms/email-queue/retry-failed', [
    '_controller' => 'App\Controller\EmailQueueController::retryAllFailed',
], [], [], '', [], ['POST']));

$routes->add('cms_email_queue_retry_failed', new Route('/cms/email-queue/{id}/retry', [
    '_controller' => 'App\Controller\EmailQueueController::retryFailed',
], ['id' => '\\d+'], [], '', [], ['POST']));

$routes->add('cms_profile', new Route('/cms/profile', [
    '_controller' => 'App\\Controller\\UserController::profile',
]));


$routes->add('cms_receipt_verification', new Route('/cms/sprawdz-paragony', [
    '_controller' => 'App\\Controller\\ReceiptVerificationController::index',
]));

$routes->add('cms_receipt_verification_update', new Route('/cms/sprawdz-paragony/zapisz', [
    '_controller' => 'App\\Controller\\ReceiptVerificationController::update',
], [], [], '', [], ['POST']));

$routes->add('cms_receipts_in_verification', new Route('/cms/w-weryfikacji', [
    '_controller' => 'App\\Controller\\ReceiptVerificationController::inVerification',
]));

$routes->add('cms_receipts_in_verification_update', new Route('/cms/w-weryfikacji/zapisz', [
    '_controller' => 'App\\Controller\\ReceiptVerificationController::updateInVerification',
], [], [], '', [], ['POST']));

$routes->add('cms_participant_info', new Route('/cms/informacja-o-uczestniku', [
    '_controller' => 'App\\Controller\\ParticipantInfoController::index',
]));

$routes->add('cms_modules_file_browser', new Route('/cms/modules/file-browser', [
    '_controller' => 'App\\Controller\\ModuleController::fileBrowser',
], [], [], '', [], ['GET']));

$routes->add('cms_modules_system_settings_test_ftp', new Route('/cms/modules/system_settings/test-ftp', [
    '_controller' => 'App\\Controller\\ModuleController::testFtpSettings',
], [], [], '', [], ['POST']));

$routes->add('cms_modules', new Route('/cms/modules/{moduleKey}', [
    '_controller' => 'App\\Controller\\ModuleController::index',
]));

$routes->add('cms_modules_create', new Route('/cms/modules/{moduleKey}/create', [
    '_controller' => 'App\\Controller\\ModuleController::create',
]));

$routes->add('cms_modules_import_winning_codes', new Route('/cms/modules/{moduleKey}/import-winning-codes', [
    '_controller' => 'App\\Controller\\ModuleController::importWinningCodes',
], [], [], '', [], ['GET', 'POST']));

$routes->add('cms_modules_import_winners_csv', new Route('/cms/modules/{moduleKey}/import-winners-csv', [
    '_controller' => 'App\\Controller\\ModuleController::importWinnersCsv',
], [], [], '', [], ['GET', 'POST']));

$routes->add('cms_modules_import_winners_csv_log', new Route('/cms/modules/{moduleKey}/import-winners-csv/log/{logId}', [
    '_controller' => 'App\\Controller\\ModuleController::winnerImportLog',
], [
    'logId' => '[A-Za-z0-9-]+',
], [], '', [], ['GET']));

$routes->add('cms_modules_import_csv', new Route('/cms/modules/{moduleKey}/import-csv', [
    '_controller' => 'App\\Controller\\ModuleController::importCsv',
], [], [], '', [], ['POST']));

$routes->add('cms_modules_edit', new Route('/cms/modules/{moduleKey}/{id}/edit', [
    '_controller' => 'App\\Controller\\ModuleController::edit',
]));

$routes->add('cms_modules_view', new Route('/cms/modules/{moduleKey}/{id}/view', [
    '_controller' => 'App\\Controller\\ModuleController::view',
]));


$routes->add('cms_modules_winners_pdf', new Route('/cms/modules/{moduleKey}/{id}/winners-pdf', [
    '_controller' => 'App\Controller\ModuleController::winnersPdf',
], [], [], '', [], ['GET']));

$routes->add('cms_modules_chunk_upload', new Route('/cms/modules/{moduleKey}/chunk-upload/{fieldName}', [
    '_controller' => 'App\\Controller\\ModuleController::chunkUpload',
], [], [], '', [], ['POST']));

$routes->add('cms_modules_relation_search', new Route('/cms/modules/{moduleKey}/relation-search/{fieldName}', [
    '_controller' => 'App\\Controller\\ModuleController::relationSearch',
], [], [], '', [], ['GET']));


$routes->add('cms_modules_delete', new Route('/cms/modules/{moduleKey}/{id}/delete', [
    '_controller' => 'App\\Controller\\ModuleDeleteController::delete',
], [], [], '', [], ['POST']));

$routes->add('cms_modules_bulk_delete', new Route('/cms/modules/{moduleKey}/bulk-delete', [
    '_controller' => 'App\\Controller\\ModuleController::bulkDelete',
], [], [], '', [], ['POST']));

$routes->add('cms_modules_extra_action', new Route('/cms/modules/{moduleKey}/{id}/extra-action', [
    '_controller' => 'App\\Controller\\ModuleController::extraAction',
], [], [], '', [], ['POST']));

$routes->add('cms_modules_extra_module_action', new Route('/cms/modules/{moduleKey}/extra-action/{actionKey}', [
    '_controller' => 'App\\Controller\\ModuleController::moduleExtraAction',
], [], [], '', [], ['POST']));

$routes->add('cms_modules_move', new Route('/cms/modules/{moduleKey}/{id}/move/{direction}', [
    '_controller' => 'App\\Controller\\ModuleController::move',
], [], [], '', [], ['POST']));

$routes->add('cms_modules_reorder', new Route('/cms/modules/{moduleKey}/reorder', [
    '_controller' => 'App\\Controller\\ModuleController::reorder',
], [], [], '', [], ['POST']));

$routes->add('cms_modules_export', new Route('/cms/modules/{moduleKey}/export', [
    '_controller' => 'App\\Controller\\ModuleController::export',
]));

return $routes;
