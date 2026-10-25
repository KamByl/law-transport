<?php

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$routes = new RouteCollection();

$routes->add('smsapi_delivery_report', new Route('/smsapi/delivery-report', [
    '_controller' => 'App\\Controller\\SmsDeliveryReportController::receive',
], [], [], '', [], ['GET', 'POST']));

// Frontend LAW Transport (dyspozytorzy, kierowcy, podgląd)
$routes->add('home', new Route('/', [
    '_controller' => 'App\\Controller\\App\\DashboardController::index',
], [], [], '', [], []));

$routes->add('app_login', new Route('/logowanie', [
    '_controller' => 'App\\Controller\\App\\LoginController::login',
], [], [], '', [], ['GET', 'POST']));

$routes->add('app_logout', new Route('/wyloguj', [
    '_controller' => 'App\\Controller\\App\\LoginController::logout',
], [], [], '', [], ['GET']));

$routes->add('app_planner', new Route('/planer', [
    '_controller' => 'App\\Controller\\App\\PlannerController::index',
], [], [], '', [], ['GET']));

$routes->add('app_planner_data', new Route('/planer/dane', [
    '_controller' => 'App\\Controller\\App\\PlannerController::data',
], [], [], '', [], ['GET']));

$routes->add('app_planner_save', new Route('/planer/wyjazd/{id}', [
    '_controller' => 'App\\Controller\\App\\PlannerController::save',
], ['id' => '\d+'], [], '', [], ['POST']));

$routes->add('app_trips', new Route('/wyjazdy', [
    '_controller' => 'App\\Controller\\App\\TripController::index',
], [], [], '', [], ['GET']));

$routes->add('app_trip_create', new Route('/wyjazdy/nowy', [
    '_controller' => 'App\\Controller\\App\\TripController::create',
], [], [], '', [], ['GET', 'POST']));

$routes->add('app_trip_show', new Route('/wyjazdy/{id}', [
    '_controller' => 'App\\Controller\\App\\TripController::show',
], ['id' => '\d+'], [], '', [], ['GET']));

$routes->add('app_trip_edit', new Route('/wyjazdy/{id}/edycja', [
    '_controller' => 'App\\Controller\\App\\TripController::edit',
], ['id' => '\d+'], [], '', [], ['GET', 'POST']));

$routes->add('app_trip_status', new Route('/wyjazdy/{id}/status', [
    '_controller' => 'App\\Controller\\App\\TripController::changeStatus',
], ['id' => '\d+'], [], '', [], ['POST']));

$routes->add('app_trip_start', new Route('/wyjazdy/{id}/rozpocznij', [
    '_controller' => 'App\\Controller\\App\\TripController::start',
], ['id' => '\d+'], [], '', [], ['POST']));

$routes->add('app_trip_finish', new Route('/wyjazdy/{id}/zakoncz', [
    '_controller' => 'App\\Controller\\App\\TripController::finish',
], ['id' => '\d+'], [], '', [], ['POST']));

$routes->add('app_trip_delete', new Route('/wyjazdy/{id}/usun', [
    '_controller' => 'App\\Controller\\App\\TripController::delete',
], ['id' => '\d+'], [], '', [], ['POST']));

$routes->add('app_trip_document', new Route('/wyjazdy/{id}/{type}', [
    '_controller' => 'App\\Controller\\App\\TripController::document',
], ['id' => '\d+', 'type' => 'rozkaz-wyjazdu|karta-drogowa'], [], '', [], ['GET']));

$routes->add('app_my_trips', new Route('/moje-wyjazdy', [
    '_controller' => 'App\\Controller\\App\\DriverController::myTrips',
], [], [], '', [], ['GET']));

$routes->add('app_vehicles', new Route('/pojazdy', [
    '_controller' => 'App\\Controller\\App\\FleetController::vehicles',
], [], [], '', [], ['GET']));

$routes->add('app_vehicle_create', new Route('/pojazdy/nowy', [
    '_controller' => 'App\\Controller\\App\\FleetController::vehicleForm',
], [], [], '', [], ['GET', 'POST']));

$routes->add('app_vehicle_show', new Route('/pojazdy/{id}', [
    '_controller' => 'App\\Controller\\App\\FleetController::vehicle',
], ['id' => '\d+'], [], '', [], ['GET']));

$routes->add('app_vehicle_edit', new Route('/pojazdy/{id}/edycja', [
    '_controller' => 'App\\Controller\\App\\FleetController::vehicleForm',
], ['id' => '\d+'], [], '', [], ['GET', 'POST']));

$routes->add('app_drivers', new Route('/kierowcy', [
    '_controller' => 'App\\Controller\\App\\FleetController::drivers',
], [], [], '', [], ['GET']));

$routes->add('app_driver_create', new Route('/kierowcy/nowy', [
    '_controller' => 'App\\Controller\\App\\FleetController::driverForm',
], [], [], '', [], ['GET', 'POST']));

$routes->add('app_driver_show', new Route('/kierowcy/{id}', [
    '_controller' => 'App\\Controller\\App\\FleetController::driver',
], ['id' => '\d+'], [], '', [], ['GET']));

$routes->add('app_driver_edit', new Route('/kierowcy/{id}/edycja', [
    '_controller' => 'App\\Controller\\App\\FleetController::driverForm',
], ['id' => '\d+'], [], '', [], ['GET', 'POST']));

$routes->add('app_deadlines', new Route('/terminy', [
    '_controller' => 'App\\Controller\\App\\FleetController::deadlines',
], [], [], '', [], ['GET']));

$routes->add('app_fuel', new Route('/paliwo', [
    '_controller' => 'App\\Controller\\App\\FuelController::index',
], [], [], '', [], ['GET', 'POST']));

$routes->add('app_fuel_settlement', new Route('/paliwo/rozliczenie/{id}', [
    '_controller' => 'App\\Controller\\App\\FuelController::settlement',
], ['id' => '\d+'], [], '', [], ['GET']));

$routes->add('meta_json', new Route('/meta.json', [
    '_controller' => 'App\\Controller\\FrontendController::metaJson',
], [], [], '', [], ['GET']));

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

$routes->add('cms_modules_import_csv', new Route('/cms/modules/{moduleKey}/import-csv', [
    '_controller' => 'App\\Controller\\ModuleController::importCsv',
], [], [], '', [], ['POST']));

$routes->add('cms_modules_edit', new Route('/cms/modules/{moduleKey}/{id}/edit', [
    '_controller' => 'App\\Controller\\ModuleController::edit',
]));

$routes->add('cms_modules_view', new Route('/cms/modules/{moduleKey}/{id}/view', [
    '_controller' => 'App\\Controller\\ModuleController::view',
]));


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
