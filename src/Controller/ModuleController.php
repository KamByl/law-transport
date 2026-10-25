<?php

namespace App\Controller;

use App\Debug\DebugCollector;
use App\Entity\ModuleListPreference;
use App\Entity\ModuleChangeLog;
use App\Service\FtpMediaStorage;
use App\Service\ModuleDefinitionLoader;
use App\Service\LotteryDailyEntryLimitService;
use App\Service\LotteryDailyLimitException;
use App\Service\LotteryRegistrationValidator;
use App\Service\LotteryReceiptValidator;
use App\Service\LotteryProductAllocation;
use App\Service\SystemSettingsProvider;
use App\Service\PermissionService;
use App\Service\WinnerDrawingAvailabilityService;
use App\Service\WinnerFormReminderService;
use App\Service\WinnerPrizeCodeAssignmentService;
use App\Service\WinnerStatusEmailService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ModuleController extends BaseController
{
    private const LIST_STATE_SESSION_PREFIX = 'cms.module_list_state.';
    private const TRANSIENT_LIST_QUERY_KEYS = ['eligible_tickets_min', 'exclude_invalid_submissions'];
    /** @var array<string, ModuleOnChangeController> */
    private array $onChangeControllers = [];
    /** @var array<string, ModuleListActionController> */
    private array $listActionControllers = [];
    /** @var array<string, ModuleCreateActionController|null> */
    private array $createActionControllers = [];

    public function index(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_VIEW)) {
            return $response;
        }

        $module = $definition['module'];
        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';
        $manualSortColumn = $this->getManualSortColumn($module);
        $manualSortEnabled = $manualSortColumn !== null;

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        $moduleType = strtolower((string) ($module['type'] ?? 'list'));
        if ($moduleType === 'single') {
            $connection = $this->entityManager->getConnection();
            $existingId = $connection->createQueryBuilder()
                ->select($primaryKey)
                ->from($table)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if (!$existingId) {
                $existingId = $this->createEmptySingleModuleRecord($connection, $table, $primaryKey);
                $this->recordModuleChange($request, $moduleKey, 'create', is_numeric($existingId) ? (int) $existingId : null);
            }

            return new RedirectResponse('/cms/modules/' . $moduleKey . '/' . $existingId . '/edit');
        }

        $fields = $this->normalizeFields($definition);
        $fields = $this->hydrateRelationFields($fields);
        $fieldColumns = array_map(
            static fn(array $field): string => (string) ($field['name'] ?? ''),
            $fields
        );
        $fieldColumns = array_values(array_unique(array_filter($fieldColumns, static fn(string $column): bool => $column !== '')));
        $allAvailableColumns = array_values(array_unique(array_merge($fieldColumns, [$primaryKey])));
        $availableColumnsConfig = $module['list']['available_columns'] ?? null;
        $availableColumns = $allAvailableColumns;
        if (is_array($availableColumnsConfig)) {
            $configuredAvailableColumns = array_map(
                static fn(mixed $column): string => is_array($column) ? (string) ($column['name'] ?? '') : (string) $column,
                $availableColumnsConfig
            );
            $configuredAvailableColumns = array_values(array_unique(array_filter(
                array_map('trim', $configuredAvailableColumns),
                static fn(string $column): bool => $column !== ''
            )));
            $configuredAvailableColumns = array_values(array_intersect($configuredAvailableColumns, $allAvailableColumns));
            if ($configuredAvailableColumns !== []) {
                $availableColumns = $configuredAvailableColumns;
            }
        }

        $defaultColumns = $module['list']['columns'] ?? $fieldColumns;
        $defaultColumns = array_values(array_unique(array_intersect($defaultColumns, $availableColumns)));
        if ($defaultColumns === []) {
            $defaultColumns = $fieldColumns !== [] ? $fieldColumns : [$primaryKey];
        }

        $settingsColumns = array_values(array_merge($defaultColumns, array_values(array_diff($availableColumns, $defaultColumns))));

        $this->restoreModuleListState($request, $moduleKey);

        $query = trim((string) $request->query->get('q', ''));
        $listColumnsInput = $request->query->all()['list_columns'] ?? null;
        $selectedColumns = [];
        if (is_array($listColumnsInput)) {
            foreach ($listColumnsInput as $selectedColumn) {
                if (!is_scalar($selectedColumn)) {
                    continue;
                }
                $selectedColumns[] = (string) $selectedColumn;
            }
        } else {
            $rawListColumns = trim((string) $request->query->get('list_columns', ''));
            if ($rawListColumns !== '') {
                $selectedColumns = array_map('trim', explode(',', $rawListColumns));
            }
        }
        $selectedColumns = array_values(array_unique(array_intersect($selectedColumns, $availableColumns)));
        if ($selectedColumns === []) {
            $selectedColumns = $defaultColumns;
        }

        $columnsOrder = trim((string) $request->query->get('list_order', ''));
        if ($columnsOrder !== '') {
            $orderedColumns = array_map('trim', explode(',', $columnsOrder));
            $orderedColumns = array_values(array_unique(array_intersect($orderedColumns, $selectedColumns)));
            $missingColumns = array_values(array_diff($selectedColumns, $orderedColumns));
            $selectedColumns = array_merge($orderedColumns, $missingColumns);
        }
        $columns = $selectedColumns;
        $defaultSort = (string) ($module['list']['default_sort'] ?? $primaryKey);
        $defaultDir = strtolower((string) ($module['list']['default_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort = (string) $request->query->get('sort', $defaultSort);
        $dir = strtolower((string) $request->query->get('dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) $request->query->get('page', 1));
        $perPageOptions = [10, 20, 50, 100];
        $perPage = (int) $request->query->get('perPage', 20);
        if (!in_array($perPage, $perPageOptions, true)) {
            $perPage = 20;
        }

        if ($manualSortEnabled) {
            $sort = $manualSortColumn;
            $dir = 'asc';
        }

        $sortableColumns = $this->filterStoredFieldColumns($fields, $columns);
        $relatedSortExpressions = $this->buildRelatedFieldSortExpressions($fields, $columns);
        $allowedSorts = array_unique(array_merge([$primaryKey], $sortableColumns, array_keys($relatedSortExpressions), $manualSortColumn ? [$manualSortColumn] : []));
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = $primaryKey;
        }
        $sortExpression = $relatedSortExpressions[$sort] ?? $sort;

        $columnLabels = [];
        $columnTypes = [];
        $selectFieldOptions = [];
        $relationFieldMap = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name !== '' && isset($field['label'])) {
                $columnLabels[$name] = (string) $field['label'];
            }
            if ($name !== '' && isset($field['type'])) {
                $fieldType = strtolower((string) $field['type']);
                $columnTypes[$name] = in_array($fieldType, ['related_field', 'relation_field'], true)
                    ? (string) ($field['display_type'] ?? 'text')
                    : (string) $field['type'];
            }
            $fieldType = strtolower((string) ($field['type'] ?? ''));
            $fieldDisplayType = strtolower((string) ($field['display_type'] ?? ''));
            if (
                $name !== ''
                && ($fieldType === 'select' || (in_array($fieldType, ['related_field', 'relation_field'], true) && $fieldDisplayType === 'select'))
                && isset($field['options'])
                && is_array($field['options'])
            ) {
                $selectFieldOptions[$name] = $field['options'];
            }
            if (
                $name !== ''
                && strtolower((string) ($field['type'] ?? '')) === 'relation'
                && isset($field['options'])
                && is_array($field['options'])
            ) {
                $relationFieldMap[$name] = [
                    'options' => $field['options'],
                    'multiple' => (bool) ($field['multiple'] ?? false),
                ];
            }
        }

        if (!isset($columnLabels[$primaryKey])) {
            $columnLabels[$primaryKey] = 'ID';
        }

        $defaultColumnLabels = $columnLabels;

        $rawColumnLabels = (string) $request->query->get('list_labels', '');
        if ($rawColumnLabels !== '') {
            $decodedColumnLabels = json_decode($rawColumnLabels, true);
            if (is_array($decodedColumnLabels)) {
                foreach ($decodedColumnLabels as $columnName => $columnLabel) {
                    if (!is_string($columnName) || !in_array($columnName, $availableColumns, true)) {
                        continue;
                    }

                    if (!is_scalar($columnLabel)) {
                        continue;
                    }

                    $label = trim((string) $columnLabel);
                    if ($label === '') {
                        continue;
                    }

                    $columnLabels[$columnName] = $label;
                }
            }
        }

        $lotteryTypeOptions = [];
        $lotteryOptions = [];
        $storeActiveOptions = [];
        $grantedOptions = [];
        $winnerStatusOptions = [];
        $winnerPrizeTypeOptions = [];
        $winnerStoreOptions = [];
        $scratchWinOptions = [];
        $scratchStatusOptions = [];
        $ticketStatusOptions = [];
        $faqCategoryOptions = [];
        $defaultFilters = $this->resolveModuleFilters($module, $fields);
        $availableFilters = $this->resolveModuleFilters($module, $fields, [], true);
        $defaultFilterNames = array_keys($defaultFilters);
        $selectedFilterNames = [];
        $queryParameters = $request->query->all();
        $hasListFiltersInput = array_key_exists('list_filters', $queryParameters);
        $listFiltersInput = $queryParameters['list_filters'] ?? null;
        if (is_array($listFiltersInput)) {
            foreach ($listFiltersInput as $selectedFilter) {
                if (!is_scalar($selectedFilter)) {
                    continue;
                }
                $selectedFilterNames[] = (string) $selectedFilter;
            }
        } else {
            $rawListFilters = trim((string) $request->query->get('list_filters', ''));
            if ($rawListFilters !== '') {
                $selectedFilterNames = array_map('trim', explode(',', $rawListFilters));
            }
        }
        $selectedFilterNames = array_values(array_unique(array_filter(array_map(
            static fn(mixed $name): string => trim((string) $name),
            $selectedFilterNames
        ), static fn(string $name): bool => $name !== '')));
        if (!$hasListFiltersInput && $selectedFilterNames === []) {
            $selectedFilterNames = $defaultFilterNames;
        }
        $configuredFilters = $hasListFiltersInput && $selectedFilterNames === []
            ? []
            : $this->resolveModuleFilters($module, $fields, $selectedFilterNames);
        if (!$hasListFiltersInput && $configuredFilters === []) {
            $configuredFilters = $defaultFilters;
        }
        $availableFilterNames = array_values(array_unique(array_merge(
            array_keys($availableFilters),
            $defaultFilterNames,
            array_keys($configuredFilters)
        )));
        $defaultFilterLabels = [];
        foreach ($availableFilters as $filterName => $filterConfig) {
            $defaultFilterLabels[$filterName] = trim((string) ($filterConfig['label'] ?? $filterName));
        }
        foreach ($defaultFilters as $filterName => $filterConfig) {
            $defaultFilterLabels[$filterName] = trim((string) ($filterConfig['label'] ?? $filterName));
        }
        foreach ($configuredFilters as $filterName => $filterConfig) {
            $defaultFilterLabels[$filterName] = trim((string) ($filterConfig['label'] ?? $filterName));
        }

        $filterLabels = $defaultFilterLabels;
        $rawFilterLabels = (string) $request->query->get('list_filter_labels', '');
        if ($rawFilterLabels !== '') {
            $decodedFilterLabels = json_decode($rawFilterLabels, true);
            if (is_array($decodedFilterLabels)) {
                foreach ($decodedFilterLabels as $filterName => $filterLabel) {
                    if (!is_string($filterName) || !in_array($filterName, $availableFilterNames, true)) {
                        continue;
                    }

                    if (!is_scalar($filterLabel)) {
                        continue;
                    }

                    $label = trim((string) $filterLabel);
                    if ($label === '') {
                        continue;
                    }

                    $filterLabels[$filterName] = $label;
                }
            }
        }

        foreach ($configuredFilters as $filterName => &$filterConfig) {
            if (isset($filterLabels[$filterName])) {
                $filterConfig['label'] = $filterLabels[$filterName];
            }
        }
        unset($filterConfig);

        $selectedFilters = [];
        foreach ($configuredFilters as $filterName => $filterConfig) {
            $isMultiple = (bool) ($filterConfig['multiple'] ?? false);
            if ($isMultiple) {
                $rawValues = $request->query->all()[$filterName] ?? null;
                if (is_string($rawValues)) {
                    $rawValues = [$rawValues];
                } elseif (!is_array($rawValues)) {
                    $rawValues = [];
                }

                $values = array_values(array_unique(array_filter(array_map(
                    static fn(mixed $value): string => trim((string) $value),
                    $rawValues
                ), static fn(string $value): bool => $value !== '')));

                if ($values === []) {
                    continue;
                }

                $selectedFilters[$filterName] = $values;
                continue;
            }

            $filterType = strtolower((string) ($filterConfig['type'] ?? ''));
            if (in_array($filterType, ['date', 'datetime'], true)) {
                $fromValue = $this->normalizeRangeFilterValue((string) $request->query->get($filterName . '_from', ''), $filterType);
                $toValue = $this->normalizeRangeFilterValue((string) $request->query->get($filterName . '_to', ''), $filterType);
                if ($fromValue === '' && $toValue === '') {
                    continue;
                }

                $selectedFilters[$filterName] = [
                    'from' => $fromValue,
                    'to' => $toValue,
                    'range' => true,
                ];
                continue;
            }

            $filterValue = trim((string) $request->query->get($filterName, ''));
            if ($filterValue === '') {
                continue;
            }

            $selectedFilters[$filterName] = $filterValue;
        }

        $stateToStore = [
            'q' => $query,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
            'list_columns' => implode(',', $columns),
            'list_order' => implode(',', $columns),
            'list_labels' => json_encode($columnLabels, JSON_UNESCAPED_UNICODE) ?: '',
            'list_filters' => implode(',', array_keys($configuredFilters)),
            'list_filter_labels' => json_encode($filterLabels, JSON_UNESCAPED_UNICODE) ?: '',
        ];

        foreach ($request->query->all() as $key => $value) {
            if (!is_string($key) || isset($stateToStore[$key])) {
                continue;
            }

            if (in_array($key, array_merge(['reset', 'status', 'message'], self::TRANSIENT_LIST_QUERY_KEYS), true)) {
                continue;
            }

            if (is_array($value)) {
                $normalizedValues = array_values(array_unique(array_filter(array_map(
                    static fn(mixed $item): string => trim((string) $item),
                    $value
                ), static fn(string $item): bool => $item !== '')));
                if ($normalizedValues === []) {
                    continue;
                }

                $stateToStore[$key] = implode(',', $normalizedValues);
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $normalizedValue = trim((string) $value);
            if ($normalizedValue === '') {
                continue;
            }

            $stateToStore[$key] = $normalizedValue;
        }

        $this->storeModuleListState($request, $moduleKey, $stateToStore);

        $connection = $this->entityManager->getConnection();
        $queryBuilder = $connection->createQueryBuilder()
            ->select('*')
            ->from($table);
        $this->applyFixedListFilters($queryBuilder, $module);
        $winningCodesUsageSummary = [];

        $searchColumns = $this->resolveSearchColumns($fields, $columns, $primaryKey);
        $this->applyModuleListSearch($queryBuilder, $fields, $searchColumns, $table, $query);

        foreach ($selectedFilters as $filterName => $filterValue) {
            $filterConfig = $configuredFilters[$filterName] ?? [];
            $filterField = is_array($filterConfig) ? ($filterConfig['field'] ?? null) : null;
            if (is_array($filterField) && $this->isRelatedField($filterField)) {
                $this->applyRelatedFieldFilter($queryBuilder, $fields, $filterField, $filterValue, $filterName);
                continue;
            }

            $this->applyDirectModuleFilter($queryBuilder, $filterName, $filterValue, $filterConfig);
        }

        $this->applyLotteryTicketsVerifiedSubmissionFilter($request, $moduleKey, $queryBuilder);
        $this->applyEligibleTicketsMinimumFilter($request, $moduleKey, $table, $queryBuilder);

        if (in_array($moduleKey, ['lista_wygranych_kodow', 'kody_graficzne'], true)) {
            $summaryQueryBuilder = clone $queryBuilder;
            $usageSummaryRows = $summaryQueryBuilder
                ->select(
                    'wartosc_kodu',
                    'SUM(CASE WHEN kod_wykorzystany = 1 THEN 1 ELSE 0 END) AS wykorzystane',
                    'SUM(CASE WHEN kod_wykorzystany = 0 OR kod_wykorzystany IS NULL THEN 1 ELSE 0 END) AS niewykorzystane',
                    'COUNT(*) AS wszystkie'
                )
                ->groupBy('wartosc_kodu')
                ->orderBy('CAST(wartosc_kodu AS DECIMAL(10,2))', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($usageSummaryRows as $usageSummaryRow) {
                $winningCodesUsageSummary[] = [
                    'wartosc_kodu' => (string) ($usageSummaryRow['wartosc_kodu'] ?? ''),
                    'wykorzystane' => (int) ($usageSummaryRow['wykorzystane'] ?? 0),
                    'niewykorzystane' => (int) ($usageSummaryRow['niewykorzystane'] ?? 0),
                    'wszystkie' => (int) ($usageSummaryRow['wszystkie'] ?? 0),
                ];
            }
        }


        $countQueryBuilder = clone $queryBuilder;
        $totalRows = (int) $countQueryBuilder
            ->select('COUNT(*)')
            ->resetQueryPart('orderBy')
            ->executeQuery()
            ->fetchOne();

        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $queryBuilder
            ->orderBy($sortExpression, $dir)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->executeQuery()
            ->fetchAllAssociative();
        $rows = $this->hydrateRelatedFieldRows($fields, $rows);
        $rows = $this->hydrateRelationRows($rows, $relationFieldMap);
        $rows = $this->hydrateRemoteRelationRows($rows, $fields);
        $rows = $this->formatStructuredListRows($rows, $fields);

        $availableActions = $module['list']['actions'] ?? [];
        $permissionActions = $module['list']['permissions'] ?? ($module['permissions'] ?? []);
        $extraAction = $this->normalizeExtraActionConfig($module['list']['extra_action'] ?? null);
        $extraActions = $this->normalizeExtraActionsConfig($module['list']['extra_actions'] ?? null);
        $extraRowActions = $this->normalizeExtraActionsConfig($module['list']['extra_row_actions'] ?? null);

        $user = $this->authService->user();

        $canViewEntry = in_array('view', $availableActions, true)
            && in_array('view', $permissionActions, true)
            && $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_VIEW);

        $alert = null;
        $alertStatus = strtolower((string) $request->query->get('status', ''));
        $alertMessage = trim((string) $request->query->get('message', ''));
        if ($alertMessage !== '' && in_array($alertStatus, ['success', 'error'], true)) {
            $alert = [
                'type' => $alertStatus === 'success' ? 'success' : 'danger',
                'message' => $alertMessage,
            ];
        } else {
            $session = $request->getSession();
            if ($session !== null) {
                $sessionAlert = $session->get('module_alert_' . $moduleKey);
                if (is_array($sessionAlert)) {
                    $sessionType = (string) ($sessionAlert['type'] ?? '');
                    $sessionMessage = trim((string) ($sessionAlert['message'] ?? ''));
                    if ($sessionMessage !== '' && in_array($sessionType, ['success', 'error', 'danger'], true)) {
                        $alert = [
                            'type' => $sessionType === 'success' ? 'success' : 'danger',
                            'message' => $sessionMessage,
                        ];
                    }
                }

                $session->remove('module_alert_' . $moduleKey);
            }
        }

        foreach ($configuredFilters as $filterName => &$filterConfig) {
            $filterField = is_array($filterConfig) ? ($filterConfig['field'] ?? null) : null;
            $filterConfig['options'] = is_array($filterField) && $this->isRelatedField($filterField)
                ? $this->fetchDistinctRelatedFieldFilterOptions($connection, $fields, $filterField, $filterConfig)
                : $this->fetchDistinctFilterOptions($connection, $table, $filterName, $filterConfig);
        }
        unset($filterConfig);

        $pendingWinnerPrizesCount = null;
        $winnerFormReminder = null;
        $winnerPostCreateQueueSummary = null;
        if ($moduleKey === 'zwyciezcy') {
            $winnerTableColumns = array_keys($connection->createSchemaManager()->listTableColumns($table));
            if (in_array('post_create_processing_status', $winnerTableColumns, true)) {
                $queueCounts = $connection->createQueryBuilder()
                    ->select("SUM(CASE WHEN post_create_processing_status = 'pending' THEN 1 ELSE 0 END) AS pending_count")
                    ->addSelect("SUM(CASE WHEN post_create_processing_status = 'processing' THEN 1 ELSE 0 END) AS processing_count")
                    ->addSelect("SUM(CASE WHEN post_create_processing_status = 'failed' THEN 1 ELSE 0 END) AS failed_count")
                    ->from($table)
                    ->executeQuery()
                    ->fetchAssociative();

                $pendingQueueCount = (int) ($queueCounts['pending_count'] ?? 0);
                $processingQueueCount = (int) ($queueCounts['processing_count'] ?? 0);
                $winnerPostCreateQueueSummary = [
                    'remaining' => $pendingQueueCount + $processingQueueCount,
                    'pending' => $pendingQueueCount,
                    'processing' => $processingQueueCount,
                    'failed' => (int) ($queueCounts['failed_count'] ?? 0),
                ];
            }

            $pendingWinnerQueryBuilder = $this->entityManager->getConnection()->createQueryBuilder()
                ->from($table)
                ->where('stan IN (:statuses)')
                ->andWhere('(kod_z_listy_wygranych IS NULL OR kod_z_listy_wygranych = 0 OR kod_z_listy_wygranych = :empty)')
                ->setParameter('statuses', ['sprawdzone', 'ai_sprawdzone'], ArrayParameterType::STRING)
                ->setParameter('empty', '');

            $pendingWinnerPrizesCount = (int) (clone $pendingWinnerQueryBuilder)
                ->select('COUNT(*)')
                ->executeQuery()
                ->fetchOne();

            $winnerFormReminderService = new WinnerFormReminderService($this->mailer);
            $winnerFormSettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
            $eligibleWinnerForms = $winnerFormReminderService->findEligibleWinners(
                $connection,
                $winnerFormSettings
            );
            $winnerFormReminderRecipients = array_values(array_unique(array_filter(array_map(
                static fn(array $winner): string => strtolower(trim((string) ($winner['email'] ?? ''))),
                $eligibleWinnerForms
            ), static fn(string $email): bool => $email !== '')));
            $winnerFormReminder = [
                'count' => count($eligibleWinnerForms),
                'recipient_count' => count($winnerFormReminderRecipients),
                'templates' => $winnerFormReminderService->fetchTemplateOptions($connection),
                'active_until' => trim((string) ($winnerFormSettings['winner_form_active_until'] ?? '')),
                'validity_days' => max(0, (int) ($winnerFormSettings['winner_form_validity_days'] ?? 0)),
            ];
        }

        return $this->render('backend/module_list.html.twig', [
            'user' => $user,
            'moduleKey' => $moduleKey,
            'module' => $module,
            'primaryKey' => $primaryKey,
            'columns' => $columns,
            'columnLabels' => $columnLabels,
            'defaultColumnLabels' => $defaultColumnLabels,
            'columnTypes' => $columnTypes,
            'defaultColumns' => $defaultColumns,
            'settingsColumns' => $settingsColumns,
            'selectFieldOptions' => $selectFieldOptions,
            'availableFilterNames' => $availableFilterNames,
            'activeFilterNames' => array_keys($configuredFilters),
            'defaultActiveFilterNames' => array_keys($defaultFilters),
            'filterLabels' => $filterLabels,
            'defaultFilterLabels' => $defaultFilterLabels,
            'rows' => $rows,
            'query' => $query,
            'selectedCenter' => '',
            'lotteryTypeOptions' => $lotteryTypeOptions,
            'selectedLotteryType' => '',
            'lotteryOptions' => $lotteryOptions,
            'selectedLottery' => '',
            'storeActiveOptions' => $storeActiveOptions,
            'selectedStoreActive' => '',
            'grantedOptions' => $grantedOptions,
            'selectedGranted' => '',
            'winnerStatusOptions' => $winnerStatusOptions,
            'winnerPrizeTypeOptions' => $winnerPrizeTypeOptions,
            'selectedWinnerStatus' => '',
            'selectedWinnerPrizeType' => '',
            'winnerStoreOptions' => $winnerStoreOptions,
            'selectedWinnerStore' => '',
            'selectedWinnerDateFrom' => '',
            'selectedWinnerDateTo' => '',
            'scratchWinOptions' => $scratchWinOptions,
            'selectedScratchWin' => '',
            'scratchStatusOptions' => $scratchStatusOptions,
            'selectedScratchStatus' => '',
            'ticketStatusOptions' => $ticketStatusOptions,
            'selectedTicketStatus' => '',
            'faqCategoryOptions' => $faqCategoryOptions,
            'selectedFaqCategory' => '',
            'configuredFilters' => $configuredFilters,
            'selectedFilters' => $selectedFilters,
            'sort' => $sort,
            'dir' => $dir,
            'availableActions' => $availableActions,
            'manualSort' => $manualSortEnabled,
            'page' => $page,
            'perPage' => $perPage,
            'perPageOptions' => $perPageOptions,
            'totalPages' => $totalPages,
            'totalRows' => $totalRows,
            'totalAllRows' => $totalRows,
            'canView' => $canViewEntry,
            'canCreate' => in_array('create', $permissionActions, true)
                && $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_CREATE),
            'importableFields' => $this->buildImportableFields($fields),
            'canEdit' => in_array('edit', $availableActions, true)
                && in_array('edit', $permissionActions, true)
                && $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_EDIT),
            'canDelete' => in_array('delete', $availableActions, true)
                && in_array('delete', $permissionActions, true)
                && $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_DELETE),
            'extraAction' => $extraAction,
            'extraActions' => $extraActions,
            'extraRowActions' => $extraRowActions,
            'alert' => $alert,
            'winningCodesUsageSummary' => $winningCodesUsageSummary,
            'pendingWinnerPrizesCount' => $pendingWinnerPrizesCount,
            'winnerFormReminder' => $winnerFormReminder,
            'winnerPostCreateQueueSummary' => $winnerPostCreateQueueSummary,
        ]);
    }

    public function importCsv(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);
        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }
        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_CREATE)) {
            return $response;
        }

        $database = $definition['database'] ?? [];
        $table = $database['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        $csvFile = $request->files->get('csv_file');
        if (!$csvFile instanceof UploadedFile || !$csvFile->isValid()) {
            return $this->redirectWithModuleAlert($request, $moduleKey, 'danger', 'Nie udało się przesłać pliku CSV.');
        }

        $mappingJson = (string) $request->request->get('mapping', '{}');
        $defaultsJson = (string) $request->request->get('defaults', '{}');
        $mapping = json_decode($mappingJson, true);
        $defaults = json_decode($defaultsJson, true);
        if (!is_array($mapping) || !is_array($defaults)) {
            return $this->redirectWithModuleAlert($request, $moduleKey, 'danger', 'Nieprawidłowa konfiguracja mapowania importu.');
        }

        $rows = $this->parseCsvFile($csvFile);
        if ($rows === []) {
            return $this->redirectWithModuleAlert($request, $moduleKey, 'warning', 'Plik CSV nie zawiera danych do importu.');
        }

        $fields = $this->buildImportableFields($this->normalizeFields($definition));
        $fieldMap = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name !== '') {
                $fieldMap[$name] = $field;
            }
        }

        $connection = $this->entityManager->getConnection();
        $allowedColumns = array_keys($connection->createSchemaManager()->listTableColumns($table));
        $allowedMap = array_fill_keys($allowedColumns, true);
        $manualSortColumn = $this->getManualSortColumn($definition['module'] ?? []);
        $shouldAutoAssignManualSort = false;
        $nextManualSortValue = 1;
        if ($manualSortColumn !== null && isset($allowedMap[$manualSortColumn])) {
            $sortMapping = trim((string) ($mapping[$manualSortColumn] ?? ''));
            $sortDefault = isset($defaults[$manualSortColumn]) ? trim((string) $defaults[$manualSortColumn]) : '';
            $shouldAutoAssignManualSort = $sortMapping === '' && $sortDefault === '';
            if ($shouldAutoAssignManualSort) {
                $currentMaxSort = $connection->createQueryBuilder()
                    ->select(sprintf('MAX(%s)', $manualSortColumn))
                    ->from($table)
                    ->executeQuery()
                    ->fetchOne();

                if (is_numeric($currentMaxSort)) {
                    $nextManualSortValue = ((int) $currentMaxSort) + 1;
                }
            }
        }
        $inserted = 0;
        $limitRejectedCount = 0;
        $limitRejectionMessage = '';

        foreach ($rows as $row) {
            $values = [];
            foreach ($fieldMap as $fieldName => $fieldConfig) {
                $columnName = $this->normalizeCsvColumnName((string) ($mapping[$fieldName] ?? ''));
                $defaultValue = isset($defaults[$fieldName]) ? trim((string) $defaults[$fieldName]) : '';
                $rawValue = $columnName !== '' ? $this->getCsvRowValue($row, $columnName) : '';
                if ($rawValue === '' && $defaultValue !== '') {
                    $rawValue = $defaultValue;
                }
                $values[$fieldName] = $this->normalizeImportValue($fieldConfig, $rawValue);
            }

            $values = array_filter(
                $values,
                static fn($value): bool => $value !== null && $value !== ''
            );
            $values = array_intersect_key($values, $allowedMap);
            if ($shouldAutoAssignManualSort && !isset($values[$manualSortColumn])) {
                $values[$manualSortColumn] = $nextManualSortValue;
                $nextManualSortValue++;
            }
            if ($values === []) {
                continue;
            }

            try {
                $this->insertModuleEntry($connection, $moduleKey, $table, $values);
            } catch (LotteryDailyLimitException $exception) {
                $limitRejectedCount++;
                $limitRejectionMessage = $exception->getMessage();
                continue;
            }
            $inserted++;
        }

        $this->recordModuleChange($request, $moduleKey, 'import', null, ['imported_rows' => $inserted]);

        $message = sprintf('Zaimportowano %d wierszy z CSV.', $inserted);
        if ($limitRejectedCount > 0) {
            $message .= sprintf(' Nie przyznano %d wpisów: %s', $limitRejectedCount, $limitRejectionMessage);
        }
        return $this->redirectWithModuleAlert($request, $moduleKey, $limitRejectedCount > 0 ? 'warning' : 'success', $message);
    }

    public function export(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_VIEW)) {
            return $response;
        }

        $module = $definition['module'];
        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';
        $manualSortColumn = $this->getManualSortColumn($module);
        $manualSortEnabled = $manualSortColumn !== null;

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        $fields = $this->normalizeFields($definition);
        $fields = $this->hydrateRelationFields($fields);
        $listColumns = $module['list']['columns'] ?? array_map(
            static fn(array $field): string => (string) ($field['name'] ?? ''),
            $fields
        );
        $listColumns = array_values(array_filter($listColumns, static fn(string $column): bool => $column !== ''));
        $selectFieldOptions = [];
        $relationFieldMap = [];
        $exportColumns = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $includeInForm = $field['form'] ?? true;
            $type = strtolower((string) ($field['type'] ?? 'text'));
            $displayType = strtolower((string) ($field['display_type'] ?? ''));
            if (
                $name !== ''
                && ($type === 'select' || (in_array($type, ['related_field', 'relation_field'], true) && $displayType === 'select'))
                && isset($field['options'])
                && is_array($field['options'])
            ) {
                $selectFieldOptions[$name] = $field['options'];
            }
            if ($name !== '' && $type === 'relation' && isset($field['options']) && is_array($field['options'])) {
                $relationFieldMap[$name] = [
                    'options' => $field['options'],
                    'multiple' => (bool) ($field['multiple'] ?? false),
                ];
            }
            if ($name === '' || $includeInForm === false || $type === 'info') {
                continue;
            }
            $exportColumns[] = $name;
        }
        $exportColumns = array_values(array_unique($exportColumns));
        if ($exportColumns === []) {
            $exportColumns = $listColumns;
        }

        if ($exportColumns === []) {
            return new Response('Brak kolumn do eksportu.', Response::HTTP_BAD_REQUEST);
        }

        $query = trim((string) $request->query->get('q', ''));
        $selectedIdsRaw = trim((string) $request->query->get('ids', ''));
        $selectedIds = array_values(array_unique(array_filter(array_map(
            static fn(string $value): string => trim($value),
            explode(',', $selectedIdsRaw)
        ), static fn(string $value): bool => $value !== '')));
        $selectedCenter = '';
        $selectedLotteryType = '';
        $selectedLottery = '';
        $selectedStoreActive = '';
        $selectedGranted = '';
        $selectedWinnerStatus = '';
        $selectedWinnerPrizeType = '';
        $selectedWinnerStore = '';
        $selectedWinnerDateFrom = '';
        $selectedWinnerDateTo = '';
        $selectedScratchWin = '';
        $selectedScratchStatus = '';
        $selectedTicketStatus = '';
        $defaultSort = (string) ($module['list']['default_sort'] ?? $primaryKey);
        $defaultDir = strtolower((string) ($module['list']['default_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort = (string) $request->query->get('sort', $defaultSort);
        $dir = strtolower((string) $request->query->get('dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        if ($manualSortEnabled) {
            $sort = $manualSortColumn;
            $dir = 'asc';
        }

        $sortableColumns = $this->filterStoredFieldColumns($fields, array_merge($listColumns, $exportColumns));
        $allowedSorts = array_unique(array_merge(
            [$primaryKey],
            $sortableColumns,
            $manualSortColumn ? [$manualSortColumn] : []
        ));
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = $primaryKey;
        }

        $columnLabels = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name !== '' && isset($field['label'])) {
                $columnLabels[$name] = (string) $field['label'];
            }
        }

        $centerOptions = [];
        $selectedWinnerDateFrom = '';
        $selectedWinnerDateTo = '';
        $configuredFilters = $this->resolveModuleFilters($module, $fields, [], true);
        $selectedFilters = [];
        foreach ($configuredFilters as $filterName => $filterConfig) {
            $isMultiple = (bool) ($filterConfig['multiple'] ?? false);
            if ($isMultiple) {
                $rawValues = $request->query->all($filterName);
                if (!is_array($rawValues)) {
                    $rawValues = [];
                }

                $values = array_values(array_unique(array_filter(array_map(
                    static fn(mixed $value): string => trim((string) $value),
                    $rawValues
                ), static fn(string $value): bool => $value !== '')));

                if ($values === []) {
                    continue;
                }

                $selectedFilters[$filterName] = $values;
                continue;
            }

            $filterType = strtolower((string) ($filterConfig['type'] ?? ''));
            if (in_array($filterType, ['date', 'datetime'], true)) {
                $fromValue = $this->normalizeRangeFilterValue((string) $request->query->get($filterName . '_from', ''), $filterType);
                $toValue = $this->normalizeRangeFilterValue((string) $request->query->get($filterName . '_to', ''), $filterType);
                if ($fromValue === '' && $toValue === '') {
                    continue;
                }

                $selectedFilters[$filterName] = [
                    'from' => $fromValue,
                    'to' => $toValue,
                    'range' => true,
                ];
                continue;
            }

            $filterValue = trim((string) $request->query->get($filterName, ''));
            if ($filterValue === '') {
                continue;
            }

            $selectedFilters[$filterName] = $filterValue;
        }

        $connection = $this->entityManager->getConnection();
        $queryBuilder = $connection->createQueryBuilder()
            ->select('*')
            ->from($table);
        $this->applyFixedListFilters($queryBuilder, $module);

        $defaultSearchColumns = $listColumns !== [] ? $listColumns : $exportColumns;
        $searchColumns = $this->resolveSearchColumns($fields, $defaultSearchColumns, $primaryKey);
        $this->applyModuleListSearch($queryBuilder, $fields, $searchColumns, $table, $query);














        foreach ($selectedFilters as $filterName => $filterValue) {
            $filterConfig = $configuredFilters[$filterName] ?? [];
            $filterField = is_array($filterConfig) ? ($filterConfig['field'] ?? null) : null;
            if (is_array($filterField) && $this->isRelatedField($filterField)) {
                $this->applyRelatedFieldFilter($queryBuilder, $fields, $filterField, $filterValue, $filterName);
                continue;
            }

            $this->applyDirectModuleFilter($queryBuilder, $filterName, $filterValue, $filterConfig);
        }

        $this->applyLotteryTicketsVerifiedSubmissionFilter($request, $moduleKey, $queryBuilder);
        $this->applyEligibleTicketsMinimumFilter($request, $moduleKey, $table, $queryBuilder);

        if ($selectedIds !== []) {
            $queryBuilder->andWhere($queryBuilder->expr()->in($primaryKey, ':selected_export_ids'));
            $queryBuilder->setParameter('selected_export_ids', $selectedIds, ArrayParameterType::STRING);
        }

        $format = strtolower((string) $request->query->get('format', 'csv'));
        $supportedFormats = ['csv', 'xlsx', 'pdf'];
        if (!in_array($format, $supportedFormats, true)) {
            return new Response('Nieobsługiwany format eksportu.', Response::HTTP_BAD_REQUEST);
        }

        $filenameBase = sprintf('%s-%s', $moduleKey, (new \DateTimeImmutable())->format('Y-m-d'));

        if ($format === 'csv') {
            $response = new StreamedResponse(function () use (
                $queryBuilder,
                $sort,
                $dir,
                $relationFieldMap,
                $fields,
                $selectFieldOptions,
                $exportColumns,
                $columnLabels
            ): void {
                $output = fopen('php://output', 'wb');
                if ($output === false) {
                    throw new \RuntimeException('Nie udało się otworzyć strumienia wyjściowego.');
                }

                fwrite($output, "\xEF\xBB\xBF");

                $headerLabels = array_map(
                    static fn(string $column) => $columnLabels[$column] ?? ucwords(str_replace('_', ' ', $column)),
                    $exportColumns
                );
                fputcsv($output, $headerLabels, ';', '"', '');

                $offset = 0;
                $batchSize = 5000;
                while (true) {
                    $batchQueryBuilder = clone $queryBuilder;
                    $batchRows = $batchQueryBuilder
                        ->orderBy($sort, $dir)
                        ->setFirstResult($offset)
                        ->setMaxResults($batchSize)
                        ->executeQuery()
                        ->fetchAllAssociative();

                    if ($batchRows === []) {
                        break;
                    }

                    $batchRows = $this->hydrateRelatedFieldRows($fields, $batchRows);
                    $batchRows = $this->hydrateRelationRows($batchRows, $relationFieldMap);
                    $batchRows = $this->hydrateRemoteRelationRows($batchRows, $fields);
                    $batchRows = $this->hydrateSelectRows($batchRows, $selectFieldOptions);

                    foreach ($batchRows as $row) {
                        $line = [];
                        foreach ($exportColumns as $column) {
                            $line[] = (string) ($row[$column] ?? '');
                        }
                        fputcsv($output, $line, ';', '"', '');
                    }

                    $offset += $batchSize;
                    if (count($batchRows) < $batchSize) {
                        break;
                    }
                }

                fclose($output);
            });
            $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s.csv"', $filenameBase));

            return $response;
        }

        if ($format === 'pdf') {
            $rows = $queryBuilder
                ->orderBy($sort, $dir)
                ->executeQuery()
                ->fetchAllAssociative();
            $rows = $this->hydrateRelatedFieldRows($fields, $rows);
            $rows = $this->hydrateRelationRows($rows, $relationFieldMap);
            $rows = $this->hydrateRemoteRelationRows($rows, $fields);
            $rows = $this->hydrateSelectRows($rows, $selectFieldOptions);

            $title = $module['label'] ?? $moduleKey;
            $headerCells = array_map(
                static fn(string $column) => htmlspecialchars($columnLabels[$column] ?? ucwords(str_replace('_', ' ', $column)), ENT_QUOTES, 'UTF-8'),
                $exportColumns
            );

            $tableRows = '';
            foreach ($rows as $row) {
                $tableRows .= '<tr>';
                foreach ($exportColumns as $column) {
                    $value = $row[$column] ?? '';
                    $tableRows .= '<td>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                $tableRows .= '</tr>';
            }

            $html = sprintf(
                '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans, sans-serif;font-size:12px;}table{width:100%%;border-collapse:collapse;}th,td{border:1px solid #ddd;padding:6px;text-align:left;}th{background:#f5f5f5;}</style></head><body><h1>%s</h1><table><thead><tr><th>%s</th></tr></thead><tbody>%s</tbody></table></body></html>',
                htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8'),
                implode('</th><th>', $headerCells),
                $tableRows
            );

            $options = new Options();
            $options->setDefaultFont('DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            return new Response(
                $dompdf->output(),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => sprintf('attachment; filename="%s.pdf"', $filenameBase),
                ]
            );
        }

        $xlsxMaxRows = 50000;
        $countSql = sprintf('SELECT COUNT(*) FROM (%s) export_rows', $queryBuilder->getSQL());
        $totalRows = (int) $connection
            ->executeQuery(
                $countSql,
                $queryBuilder->getParameters(),
                $queryBuilder->getParameterTypes()
            )
            ->fetchOne();

        if ($totalRows > $xlsxMaxRows) {
            return new Response(
                sprintf(
                    'Eksport Excel jest dostępny maksymalnie dla %d rekordów. Wybrano %d rekordów — użyj eksportu CSV.',
                    $xlsxMaxRows,
                    $totalRows
                ),
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE
            );
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $headerLabels = array_map(
            static fn(string $column) => $columnLabels[$column] ?? ucwords(str_replace('_', ' ', $column)),
            $exportColumns
        );

        foreach ($headerLabels as $index => $label) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . '1', $label);
        }

        $rowIndex = 2;
        $offset = 0;
        $batchSize = 5000;
        while (true) {
            $batchQueryBuilder = clone $queryBuilder;
            $batchRows = $batchQueryBuilder
                ->orderBy($sort, $dir)
                ->setFirstResult($offset)
                ->setMaxResults($batchSize)
                ->executeQuery()
                ->fetchAllAssociative();

            if ($batchRows === []) {
                break;
            }

            $batchRows = $this->hydrateRelatedFieldRows($fields, $batchRows);
            $batchRows = $this->hydrateRelationRows($batchRows, $relationFieldMap);
            $batchRows = $this->hydrateRemoteRelationRows($batchRows, $fields);
            $batchRows = $this->hydrateSelectRows($batchRows, $selectFieldOptions);

            foreach ($batchRows as $row) {
                foreach ($exportColumns as $index => $column) {
                    $value = $row[$column] ?? '';
                    $cell = Coordinate::stringFromColumnIndex($index + 1) . $rowIndex;
                    $sheet->setCellValue($cell, (string) $value);
                }
                $rowIndex++;
            }

            $offset += $batchSize;
            if (count($batchRows) < $batchSize) {
                break;
            }
        }

        if ($format === 'xlsx') {
            $writer = new Xlsx($spreadsheet);

            $response = new StreamedResponse(static function () use ($writer, $spreadsheet): void {
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            });
            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s.xlsx"', $filenameBase));

            return $response;
        }

        return new Response('Nieobsługiwany format eksportu.', Response::HTTP_BAD_REQUEST);
    }

    public function importWinningCodes(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        if (!in_array($moduleKey, ['lista_wygranych_kodow'], true)) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        $definition = $this->loadModuleDefinition($moduleKey);
        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_CREATE)) {
            return $response;
        }

        $fields = $this->hydrateRelationFields($this->normalizeFields($definition));
        $lotteryOptions = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $options = $field['options'] ?? [];
            if ($name !== 'loteria' || !is_array($options)) {
                continue;
            }

            $lotteryOptions = $options;
        }

        $errors = [];
        $values = [
            'loteria' => trim((string) $request->request->get('loteria', '')),
        ];

        if ($request->isMethod('POST')) {
            if ($values['loteria'] === '' || !array_key_exists($values['loteria'], $lotteryOptions)) {
                $errors[] = 'Wybierz poprawną loterię.';
            }

            $uploadedFile = $request->files->get('codes_file');
            if (!$uploadedFile instanceof UploadedFile) {
                $errors[] = 'Dodaj plik XLS lub XLSX z kodami.';
            } elseif (!$uploadedFile->isValid()) {
                $errors[] = 'Nie udało się przesłać pliku.';
            } else {
                $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
                if (!in_array($extension, ['xls', 'xlsx'], true)) {
                    $errors[] = 'Dozwolone są wyłącznie pliki XLS i XLSX.';
                }
            }

            if ($errors === []) {
                try {
                    $importRows = $this->extractWinningCodesFromSpreadsheet($uploadedFile);
                } catch (\Throwable $exception) {
                    $importRows = [];
                    $errors[] = 'Nie udało się odczytać pliku. Sprawdź, czy zawiera kolumnę PickupCode.';
                }
            }

            if ($errors === []) {
                if ($importRows === []) {
                    $errors[] = 'Plik nie zawiera żadnych poprawnych wierszy do importu.';
                }

                $duplicateRowsInFile = $this->findDuplicateWinningCodeRows($importRows);
                if ($duplicateRowsInFile !== []) {
                    $errors[] = 'Plik zawiera zduplikowane kody PickupCode: ' . implode(', ', array_slice($duplicateRowsInFile, 0, 10)) . (count($duplicateRowsInFile) > 10 ? '…' : '');
                }
            }

            if ($errors === []) {
                $connection = $this->entityManager->getConnection();
                $table = (string) ($definition['database']['table'] ?? '');
                if ($table === '') {
                    $errors[] = 'Brak konfiguracji tabeli modułu.';
                } else {
                    $existingRows = $connection->createQueryBuilder()
                        ->select('pickup_code')
                        ->from($table)
                        ->executeQuery()
                        ->fetchAllAssociative();

                    $existingPairs = [];
                    foreach ($existingRows as $existingRow) {
                        $existingKey = $this->buildWinningCodeKey((string) ($existingRow['pickup_code'] ?? ''));
                        if ($existingKey !== '') {
                            $existingPairs[$existingKey] = true;
                        }
                    }

                    $conflictingCodes = [];
                    foreach ($importRows as $importRow) {
                        $codeKey = $this->buildWinningCodeKey($importRow['pickup_code']);
                        if ($codeKey !== '' && isset($existingPairs[$codeKey])) {
                            $conflictingCodes[] = $codeKey;
                        }
                    }
                    $conflictingCodes = array_values(array_unique($conflictingCodes));

                    if ($conflictingCodes !== []) {
                        $errors[] = 'Takie kody już istnieją. Konfliktowe kody: ' . implode(', ', array_slice($conflictingCodes, 0, 10)) . (count($conflictingCodes) > 10 ? '…' : '');
                    } else {
                        $connection->beginTransaction();
                        try {
                            foreach ($importRows as $importRow) {
                                $connection->insert($table, [
                                    'pickup_code' => $importRow['pickup_code'],
                                    'loteria' => $values['loteria'],
                                    'kod_wykorzystany' => 0,
                                    'data_i_godzina_przyznania' => null,
                                ]);
                            }
                            $connection->commit();
                        } catch (\Throwable $exception) {
                            $connection->rollBack();
                            throw $exception;
                        }

                        $this->recordModuleChange($request, $moduleKey, 'import', null, [
                            'imported_rows' => count($importRows),
                            'loteria' => $values['loteria'],
                            'source_file' => $uploadedFile->getClientOriginalName(),
                        ]);
                        $query = http_build_query([
                            'status' => 'success',
                            'message' => sprintf('Zaimportowano %d kodów z pliku %s.', count($importRows), $uploadedFile->getClientOriginalName()),
                        ]);

                        return new RedirectResponse('/cms/modules/' . $moduleKey . '?' . $query);
                    }
                }
            }
        }

        return $this->render('backend/module_import_winning_codes.html.twig', [
            'user' => $this->authService->user(),
            'moduleKey' => $moduleKey,
            'module' => $definition['module'],
            'values' => $values,
            'errors' => $errors,
            'lotteryOptions' => $lotteryOptions,
            'backUrl' => $this->getModuleListUrl($request, $moduleKey),
        ]);
    }

    public function importWinnersCsv(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        if ($moduleKey !== 'zwyciezcy') {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        $definition = $this->loadModuleDefinition($moduleKey);
        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }
        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_CREATE)) {
            return $response;
        }

        $module = $definition['module'];
        if (!$this->moduleAllowsConfiguredAction($module, 'create')) {
            return new Response('Brak uprawnień.', Response::HTTP_FORBIDDEN);
        }

        if ($request->isMethod('GET') && $request->query->getBoolean('sample')) {
            $response = new StreamedResponse(static function (): void {
                $output = fopen('php://output', 'wb');
                if ($output === false) {
                    throw new \RuntimeException('Nie udało się utworzyć przykładowego pliku CSV.');
                }

                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, ['ID'], ';', '"', '');
                fputcsv($output, ['12345'], ';', '"', '');
                fputcsv($output, ['12346'], ';', '"', '');
                fclose($output);
            });
            $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="przyklad-importu-zwyciezcow.csv"');

            return $response;
        }

        $connection = $this->entityManager->getConnection();
        $prizeTypeColumns = array_keys($connection->createSchemaManager()->listTableColumns('rodzaje_nagrod_entries'));
        $prizeTypeSelect = ['id', 'name'];
        if (in_array('rodzaj_nagrody', $prizeTypeColumns, true)) {
            $prizeTypeSelect[] = 'rodzaj_nagrody';
        }
        if (in_array('jedna_nagroda_na_uzytkownika_w_losowaniu', $prizeTypeColumns, true)) {
            $prizeTypeSelect[] = 'jedna_nagroda_na_uzytkownika_w_losowaniu';
        }
        if (in_array('jedna_nagroda_na_uzytkownika_w_loterii', $prizeTypeColumns, true)) {
            $prizeTypeSelect[] = 'jedna_nagroda_na_uzytkownika_w_loterii';
        }
        foreach (['loteria', 'status_po_wygraniu_nagrody'] as $optionalColumn) {
            if (in_array($optionalColumn, $prizeTypeColumns, true)) {
                $prizeTypeSelect[] = $optionalColumn;
            }
        }
        $prizeTypeRows = $connection->createQueryBuilder()
            ->select(...$prizeTypeSelect)
            ->from('rodzaje_nagrod_entries')
            ->orderBy('name', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
        $prizeTypeOptions = [];
        $prizeTypesById = [];
        foreach ($prizeTypeRows as $prizeTypeRow) {
            $id = trim((string) ($prizeTypeRow['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $option = [
                'id' => $id,
                'name' => trim((string) ($prizeTypeRow['name'] ?? '')),
                'kind' => trim((string) ($prizeTypeRow['rodzaj_nagrody'] ?? '')),
                'lottery_id' => trim((string) ($prizeTypeRow['loteria'] ?? '')),
                'initial_status' => trim((string) ($prizeTypeRow['status_po_wygraniu_nagrody'] ?? '')),
                'one_per_user_per_drawing' => filter_var(
                    $prizeTypeRow['jedna_nagroda_na_uzytkownika_w_losowaniu'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                ),
                'one_per_user_per_lottery' => filter_var(
                    $prizeTypeRow['jedna_nagroda_na_uzytkownika_w_loterii'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                ),
            ];
            $prizeTypeOptions[] = $option;
            $prizeTypesById[$id] = $option;
        }

        $drawingRows = (new WinnerDrawingAvailabilityService())->fetchDrawingOptions($connection);
        $drawingOptions = [];
        $disabledDrawingOptions = [];
        foreach ($drawingRows as $drawingRow) {
            $drawingId = trim((string) ($drawingRow['id'] ?? ''));
            if ($drawingId === '') {
                continue;
            }

            $drawingOptions[$drawingId] = (string) ($drawingRow['label'] ?? $drawingId);
            if (!($drawingRow['selectable'] ?? false)) {
                $disabledDrawingOptions[$drawingId] = true;
            }
        }

        $values = [
            'rodzaj' => trim((string) $request->request->get('rodzaj', 'los')),
            'rodzaj_wygranej' => trim((string) $request->request->get('rodzaj_wygranej', '')),
            'losowanie' => trim((string) $request->request->get('losowanie', '')),
            'winner_ids' => trim((string) $request->request->get('winner_ids', '')),
        ];
        $errors = [];
        $importLogDetails = [];
        $technicalErrors = [];
        $sourceFileName = '';
        $createdRecords = [];
        $skippedWinners = [];
        $currentImportLog = null;

        if ($request->isMethod('POST')) {
            $winnerKind = $values['rodzaj'];
            if ($request->request->getBoolean('send_winner_emails')
                && !$request->request->getBoolean('confirm_winner_emails')) {
                $errors[] = 'Potwierdź wysyłkę maili do zwycięzców przed dodaniem.';
            }
            $winnerPostCreateColumns = [
                'post_create_processing_status',
                'post_create_send_email',
                'post_create_attempts',
                'post_create_last_error',
                'post_create_locked_at',
                'post_create_next_attempt_at',
                'post_create_processed_at',
            ];
            $winnerTableColumns = array_keys($connection->createSchemaManager()->listTableColumns(
                (string) ($definition['database']['table'] ?? 'loteria_zwyciezcy_entries')
            ));
            if (array_diff($winnerPostCreateColumns, $winnerTableColumns) !== []) {
                $errors[] = 'Brak pól kolejki przetwarzania zwycięzców. Uruchom instalację/aktualizację bazy danych w CMS.';
            }
            $isScratchWinner = $winnerKind === 'zdrapka';
            if (!in_array($winnerKind, ['los', 'zdrapka'], true)) {
                $errors[] = 'Wybierz poprawny rodzaj wygranej.';
            }
            if (!$isScratchWinner && (
                !array_key_exists($values['losowanie'], $drawingOptions)
                || isset($disabledDrawingOptions[$values['losowanie']])
            )) {
                $errors[] = 'Wybierz losowanie, w którym wylosowano nagrodę.';
            }
            if ($isScratchWinner) {
                $values['losowanie'] = '';
            }

            $selectedPrizeType = $prizeTypesById[$values['rodzaj_wygranej']] ?? null;
            if (!is_array($selectedPrizeType)) {
                $errors[] = 'Wybierz poprawny rodzaj nagrody.';
            } else {
                $requiredPrizeKind = $isScratchWinner ? 'zdrapki' : 'losy';
                $selectedPrizeKind = trim((string) ($selectedPrizeType['kind'] ?? ''));
                if ($selectedPrizeKind !== '' && $selectedPrizeKind !== $requiredPrizeKind) {
                    $errors[] = $isScratchWinner
                        ? 'Wybrany rodzaj nagrody nie jest przeznaczony dla zdrapek.'
                        : 'Wybrany rodzaj nagrody nie jest przeznaczony dla losów.';
                }
            }

            $uploadedFile = $request->files->get('winners_csv_file');
            $hasManualIds = $values['winner_ids'] !== '';
            $sourceFileName = $uploadedFile instanceof UploadedFile
                ? $uploadedFile->getClientOriginalName()
                : ($hasManualIds ? 'ID wpisane ręcznie' : '');
            if ($hasManualIds && $uploadedFile instanceof UploadedFile) {
                $errors[] = 'Wpisz identyfikatory ręcznie albo dodaj plik CSV, nie obie opcje naraz.';
            } elseif (!$hasManualIds && !($uploadedFile instanceof UploadedFile)) {
                $errors[] = 'Wpisz identyfikatory lub dodaj plik CSV.';
            } elseif ($uploadedFile instanceof UploadedFile && !$uploadedFile->isValid()) {
                $errors[] = 'Nie udało się przesłać pliku CSV.';
            } elseif ($uploadedFile instanceof UploadedFile && strtolower((string) $uploadedFile->getClientOriginalExtension()) !== 'csv') {
                $errors[] = 'Dozwolone są wyłącznie pliki CSV.';
            }

            $csvRows = [];
            $ticketIds = [];
            if ($errors === [] && !$hasManualIds) {
                $csvRows = $this->parseCsvFile($uploadedFile);
                if ($csvRows === []) {
                    $errors[] = 'Plik CSV nie zawiera danych do importu.';
                }
            }

            if ($errors === []) {
                if ($hasManualIds) {
                    $ticketIds = preg_split('/[\s,;]+/u', $values['winner_ids'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
                } else {
                    $acceptedIdHeaders = [
                        'id',
                        'los - id',
                        'zdrapka - id',
                        'ticket_id',
                        'los - ticket_id',
                        'zdrapka - ticket_id',
                    ];
                    $idColumn = null;
                    foreach (array_keys($csvRows[0]) as $columnName) {
                        if (in_array(mb_strtolower(trim((string) $columnName)), $acceptedIdHeaders, true)) {
                            $idColumn = (string) $columnName;
                            break;
                        }
                    }
                    if ($idColumn === null) {
                        $errors[] = 'Plik CSV musi zawierać kolumnę ID lub Los - ID.';
                    } else {
                        foreach ($csvRows as $csvRow) {
                            $ticketId = trim((string) ($csvRow[$idColumn] ?? ''));
                            if ($ticketId !== '') {
                                $ticketIds[] = $ticketId;
                            }
                        }
                    }
                }

                if ($ticketIds === [] && ($hasManualIds || $idColumn !== null)) {
                    $errors[] = $hasManualIds
                        ? 'Nie znaleziono identyfikatorów w polu tekstowym.'
                        : 'Kolumna ID nie zawiera żadnych identyfikatorów.';
                }
                if (count($ticketIds) > 5000) {
                    $errors[] = 'Jednorazowo można zaimportować maksymalnie 5000 zwycięzców.';
                }

                $idCounts = array_count_values($ticketIds);
                $duplicateIds = array_keys(array_filter($idCounts, static fn(int $count): bool => $count > 1));
                if ($duplicateIds !== []) {
                    $errors[] = 'Podane dane zawierają powtórzone ID: '
                        . implode(', ', array_slice($duplicateIds, 0, 10))
                        . (count($duplicateIds) > 10 ? '…' : '') . '.';
                    foreach ($duplicateIds as $duplicateId) {
                        $importLogDetails[] = [
                            'level' => 'error',
                            'ticket_id' => (string) $duplicateId,
                            'message' => sprintf('ID występuje w podanych danych %d razy.', $idCounts[$duplicateId]),
                        ];
                    }
                }
            }

            $preparedWinners = [];
            if ($errors === []) {
                $ticketTable = $isScratchWinner ? 'loteria_zdrapki_entries' : 'loteria_losy_entries';
                $ticketRows = $connection->createQueryBuilder()
                    ->select('*')
                    ->from($ticketTable)
                    ->where('ticket_id IN (:ticket_ids)')
                    ->setParameter('ticket_ids', $ticketIds, ArrayParameterType::STRING)
                    ->executeQuery()
                    ->fetchAllAssociative();

                $ticketsByPublicId = [];
                foreach ($ticketRows as $ticketRow) {
                    $publicId = trim((string) ($ticketRow['ticket_id'] ?? ''));
                    if ($publicId !== '') {
                        $ticketsByPublicId[$publicId] = $ticketRow;
                    }
                }

                $submissionIds = array_values(array_unique(array_filter(array_map(
                    static fn(array $ticket): int => (int) ($ticket['zgloszenie'] ?? 0),
                    $ticketRows
                ), static fn(int $id): bool => $id > 0)));
                $submissionRows = $submissionIds === [] ? [] : $connection->createQueryBuilder()
                    ->select('*')
                    ->from('loteria_zgloszenia_entries')
                    ->where('id IN (:submission_ids)')
                    ->setParameter('submission_ids', $submissionIds, ArrayParameterType::INTEGER)
                    ->executeQuery()
                    ->fetchAllAssociative();
                $submissionsById = [];
                foreach ($submissionRows as $submissionRow) {
                    $submissionId = (int) ($submissionRow['id'] ?? 0);
                    if ($submissionId > 0) {
                        $submissionsById[$submissionId] = $submissionRow;
                    }
                }

                $missingIds = array_values(array_filter(
                    $ticketIds,
                    static fn(string $ticketId): bool => !isset($ticketsByPublicId[$ticketId])
                ));
                if ($missingIds !== []) {
                    $errors[] = ($isScratchWinner ? 'Nie znaleziono zdrapek o ID: ' : 'Nie znaleziono losów o ID: ')
                        . implode(', ', array_slice($missingIds, 0, 10))
                        . (count($missingIds) > 10 ? '…' : '') . '.';
                    foreach ($missingIds as $missingId) {
                        $importLogDetails[] = [
                            'level' => 'error',
                            'ticket_id' => $missingId,
                            'message' => $isScratchWinner
                                ? 'Nie znaleziono zdrapki o tym ID.'
                                : 'Nie znaleziono losu o tym ID.',
                        ];
                    }
                }

                if ($errors === []) {
                    $internalTicketIds = array_values(array_filter(array_map(
                        static fn(array $ticket): int => (int) ($ticket['id'] ?? 0),
                        $ticketsByPublicId
                    ), static fn(int $id): bool => $id > 0));
                    $winnerTicketColumn = $isScratchWinner ? 'zdrapka_w_loterii' : 'kupon_w_loterii';
                    $existingTicketIds = $internalTicketIds === [] ? [] : $connection->createQueryBuilder()
                        ->select($winnerTicketColumn)
                        ->from((string) $definition['database']['table'])
                        ->where($winnerTicketColumn . ' IN (:ticket_ids)')
                        ->andWhere('rodzaj_wygranej = :prize_type_id')
                        ->setParameter('ticket_ids', $internalTicketIds, ArrayParameterType::INTEGER)
                        ->setParameter('prize_type_id', $values['rodzaj_wygranej'])
                        ->executeQuery()
                        ->fetchFirstColumn();
                    $existingTicketMap = array_fill_keys(array_map('intval', $existingTicketIds), true);
                    $conflictingIds = [];
                    foreach ($ticketIds as $ticketId) {
                        $internalId = (int) ($ticketsByPublicId[$ticketId]['id'] ?? 0);
                        if (isset($existingTicketMap[$internalId])) {
                            $conflictingIds[] = $ticketId;
                        }
                    }
                    if ($conflictingIds !== []) {
                        $errors[] = 'Dla tych ID istnieją już zwycięzcy z wybranym rodzajem nagrody: '
                            . implode(', ', array_slice($conflictingIds, 0, 10))
                            . (count($conflictingIds) > 10 ? '…' : '') . '.';
                        foreach ($conflictingIds as $conflictingId) {
                            $importLogDetails[] = [
                                'level' => 'error',
                                'ticket_id' => $conflictingId,
                                'message' => 'Dla tego ID istnieje już zwycięzca z wybranym rodzajem nagrody.',
                            ];
                        }
                    }
                }

                if ($errors === []) {
                    $enforceOnePrizePerUserInDrawing = !$isScratchWinner
                        && ($selectedPrizeType['one_per_user_per_drawing'] ?? false) === true;
                    $enforceOnePrizePerUserInLottery = !$isScratchWinner
                        && ($selectedPrizeType['one_per_user_per_lottery'] ?? false) === true;
                    $enforceOnePrizePerUser = $enforceOnePrizePerUserInDrawing
                        || $enforceOnePrizePerUserInLottery;
                    $knownWinnerEmails = [];
                    $knownWinnerPhones = [];

                    if ($enforceOnePrizePerUser) {
                        $existingWinnersQuery = $connection->createQueryBuilder()
                            ->select(
                                'w.email AS winner_email',
                                'w.telefon AS winner_phone',
                                's.email AS submission_email',
                                's.phone AS submission_phone'
                            )
                            ->from((string) $definition['database']['table'], 'w')
                            ->leftJoin('w', 'loteria_zgloszenia_entries', 's', 's.id = w.zgloszenie')
                            ->where('w.rodzaj_wygranej = :prize_type_id')
                            ->setParameter('prize_type_id', $values['rodzaj_wygranej']);
                        if (!$enforceOnePrizePerUserInLottery) {
                            $existingWinnersQuery
                                ->andWhere('w.losowanie = :drawing_id')
                                ->setParameter('drawing_id', $values['losowanie']);
                        }
                        $existingWinners = $existingWinnersQuery->executeQuery()->fetchAllAssociative();

                        foreach ($existingWinners as $existingWinner) {
                            $winnerEmail = trim((string) ($existingWinner['winner_email'] ?? ''));
                            $winnerPhone = trim((string) ($existingWinner['winner_phone'] ?? ''));
                            $normalizedEmail = mb_strtolower($winnerEmail !== ''
                                ? $winnerEmail
                                : trim((string) ($existingWinner['submission_email'] ?? '')));
                            $normalizedPhone = preg_replace('/\D+/', '', $winnerPhone !== ''
                                ? $winnerPhone
                                : (string) ($existingWinner['submission_phone'] ?? '')) ?? '';
                            if ($normalizedEmail !== '') {
                                $knownWinnerEmails[$normalizedEmail] = true;
                            }
                            if ($normalizedPhone !== '') {
                                $knownWinnerPhones[$normalizedPhone] = true;
                            }
                        }
                    }

                    $validationErrorCount = 0;
                    foreach ($ticketIds as $ticketId) {
                        $internalId = (int) ($ticketsByPublicId[$ticketId]['id'] ?? 0);
                        $createValues = [
                            'rodzaj' => $winnerKind,
                            'rodzaj_wygranej' => $values['rodzaj_wygranej'],
                            'losowanie' => $values['losowanie'],
                            $isScratchWinner ? 'zdrapka_w_loterii' : 'kupon_w_loterii' => $internalId,
                            '_winner_import_ticket' => $ticketsByPublicId[$ticketId],
                            '_winner_import_submission' => $submissionsById[(int) ($ticketsByPublicId[$ticketId]['zgloszenie'] ?? 0)] ?? null,
                            '_winner_import_prize_type' => [
                                'id' => $values['rodzaj_wygranej'],
                                'name' => $selectedPrizeType['name'] ?? '',
                                'loteria' => $selectedPrizeType['lottery_id'] ?? '',
                                'status_po_wygraniu_nagrody' => $selectedPrizeType['initial_status'] ?? '',
                            ],
                            '_winner_import_skip_drawing_lookup' => true,
                            '_winner_import_skip_existing_winner_lookup' => true,
                        ];
                        $createResult = $this->runCreateActionHandler($connection, $module, $moduleKey, $createValues);
                        if ($createResult['errors'] !== []) {
                            foreach ($createResult['errors'] as $createError) {
                                ++$validationErrorCount;
                                $importLogDetails[] = [
                                    'level' => 'error',
                                    'ticket_id' => $ticketId,
                                    'message' => (string) $createError,
                                ];
                                if ($validationErrorCount <= 20) {
                                    $errors[] = sprintf('ID %s: %s', $ticketId, $createError);
                                }
                            }
                            continue;
                        }

                        if ($enforceOnePrizePerUser) {
                            $normalizedEmail = mb_strtolower(trim((string) ($createResult['values']['email'] ?? '')));
                            $normalizedPhone = preg_replace('/\D+/', '', (string) ($createResult['values']['telefon'] ?? '')) ?? '';
                            $matchedIdentityFields = [];
                            if ($normalizedEmail !== '' && isset($knownWinnerEmails[$normalizedEmail])) {
                                $matchedIdentityFields[] = 'adres e-mail';
                            }
                            if ($normalizedPhone !== '' && isset($knownWinnerPhones[$normalizedPhone])) {
                                $matchedIdentityFields[] = 'numer telefonu';
                            }

                            if ($matchedIdentityFields !== []) {
                                $skippedWinners[$ticketId] = sprintf(
                                    'uczestnik rozpoznany przez %s wygrał już tę nagrodę %s',
                                    implode(' i ', $matchedIdentityFields),
                                    $enforceOnePrizePerUserInLottery
                                        ? 'w tej loterii'
                                        : 'w wybranym losowaniu'
                                );
                                continue;
                            }

                            if ($normalizedEmail !== '') {
                                $knownWinnerEmails[$normalizedEmail] = true;
                            }
                            if ($normalizedPhone !== '') {
                                $knownWinnerPhones[$normalizedPhone] = true;
                            }
                        }

                        $preparedWinners[] = $createResult['values'];
                    }
                    if ($validationErrorCount > 20) {
                        $errors[] = sprintf('Pozostałe błędy walidacji: %d.', $validationErrorCount - 20);
                    }
                    if ($preparedWinners === [] && $skippedWinners !== []) {
                        $errors[] = 'Nie dodano żadnego zwycięzcy. Pominięte pozycje:';
                        foreach ($skippedWinners as $skippedId => $skipReason) {
                            $errors[] = sprintf('ID %s: %s.', $skippedId, $skipReason);
                        }
                    }
                }
            }

            if ($errors === [] && $preparedWinners !== []) {
                $initialStatus = trim((string) ($preparedWinners[0]['stan'] ?? ''));
                $codeAssignmentService = new WinnerPrizeCodeAssignmentService();
                if ($codeAssignmentService->shouldAssignCodesForStatus($connection, $initialStatus)) {
                    $capacity = $codeAssignmentService->validateAvailableCodesForWinners(
                        $connection,
                        (int) $values['rodzaj_wygranej'],
                        count($preparedWinners)
                    );
                    if (!$capacity['success']) {
                        $errors[] = $capacity['error'];
                    }
                }
            }

            if ($errors === [] && $preparedWinners !== []) {
                $table = (string) ($definition['database']['table'] ?? '');
                $allowedColumns = array_keys($connection->createSchemaManager()->listTableColumns($table));
                $allowedMap = array_fill_keys($allowedColumns, true);
                $importTicketIds = [];

                $connection->beginTransaction();
                try {
                    foreach ($preparedWinners as $preparedWinner) {
                        $preparedWinner['post_create_processing_status'] = 'pending';
                        $preparedWinner['post_create_send_email'] = $request->request->getBoolean('send_winner_emails') ? 1 : 0;
                        $preparedWinner['post_create_attempts'] = 0;
                        $preparedWinner['post_create_last_error'] = null;
                        $preparedWinner['post_create_locked_at'] = null;
                        $preparedWinner['post_create_next_attempt_at'] = null;
                        $preparedWinner['post_create_processed_at'] = null;
                        $insertValues = array_intersect_key($preparedWinner, $allowedMap);
                        $connection->insert($table, $insertValues);
                        $recordId = (int) $connection->lastInsertId();
                        if ($recordId <= 0) {
                            throw new \RuntimeException('Nie udało się ustalić ID dodanego zwycięzcy.');
                        }
                        $linkedTicketId = (int) ($insertValues[$isScratchWinner ? 'zdrapka_w_loterii' : 'kupon_w_loterii'] ?? 0);
                        if ($linkedTicketId > 0) {
                            $importTicketIds[] = $linkedTicketId;
                        }
                        $createdRecords[] = $recordId;
                    }
                    if ($importTicketIds !== []) {
                        $ticketColumns = array_keys($connection->createSchemaManager()->listTableColumns($ticketTable));
                        if (in_array('wygrany', $ticketColumns, true)) {
                            $connection->createQueryBuilder()
                                ->update($ticketTable)
                                ->set('wygrany', '1')
                                ->where('id IN (:ticket_ids)')
                                ->setParameter('ticket_ids', array_values(array_unique($importTicketIds)), ArrayParameterType::INTEGER)
                                ->executeStatement();
                        }
                    }
                    $connection->commit();
                } catch (\Throwable $exception) {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                    $errors[] = 'Nie udało się dodać zwycięzców. Żaden rekord nie został zapisany.';
                    $technicalErrors[] = sprintf('%s: %s', $exception::class, $exception->getMessage());
                }

                if ($errors === []) {
                    foreach ($skippedWinners as $skippedId => $skipReason) {
                        $importLogDetails[] = [
                            'level' => 'warning',
                            'ticket_id' => (string) $skippedId,
                            'message' => $skipReason,
                        ];
                    }
                    $currentImportLog = $this->storeWinnerImportLog($request, [
                        'status' => $skippedWinners !== [] ? 'warning' : 'success',
                        'source_file' => $sourceFileName,
                        'winner_kind' => $winnerKind,
                        'winner_kind_label' => $isScratchWinner ? 'Zdrapka' : 'Los',
                        'prize_type' => $values['rodzaj_wygranej'],
                        'prize_name' => (string) ($selectedPrizeType['name'] ?? ''),
                        'drawing_id' => $values['losowanie'],
                        'drawing_label' => (string) ($drawingOptions[$values['losowanie']] ?? ''),
                        'imported_count' => count($createdRecords),
                        'skipped_count' => count($skippedWinners),
                        'errors' => [],
                        'details' => $importLogDetails,
                        'technical_errors' => [],
                    ]);
                    $this->recordModuleChange($request, $moduleKey, 'import_winners_csv', null, [
                        'imported_rows' => count($createdRecords),
                        'winner_kind' => $winnerKind,
                        'prize_type' => $values['rodzaj_wygranej'],
                        'drawing_id' => $values['losowanie'],
                        'source_file' => $sourceFileName,
                        'skipped_rows' => count($skippedWinners),
                        'skipped_ids' => array_keys($skippedWinners),
                        'send_winner_emails' => $request->request->getBoolean('send_winner_emails'),
                    ]);

                    $message = sprintf(
                        'Dodano %d zwycięzców (%s).',
                        count($createdRecords),
                        $sourceFileName
                    );
                    $message .= $request->request->getBoolean('send_winner_emails')
                        ? ' Przydzielanie kodów i przygotowanie maili zostało przekazane do kolejki CRON.'
                        : ' Przydzielanie kodów zostało przekazane do kolejki CRON.';
                    if ($skippedWinners !== []) {
                        $message .= sprintf(' Pominięto %d pozycji: ', count($skippedWinners));
                        $skippedMessages = [];
                        foreach ($skippedWinners as $skippedId => $skipReason) {
                            $skippedMessages[] = sprintf('ID %s: %s.', $skippedId, $skipReason);
                        }
                        $message .= implode(' | ', $skippedMessages);
                    }
                    if ($currentImportLog !== null) {
                        $message .= ' Pełny raport jest dostępny w „Logach importu”.';
                    }
                    return $skippedWinners !== []
                        ? $this->redirectWithModuleAlert($request, $moduleKey, 'success', $message)
                        : $this->buildModuleAlertRedirect($request, $moduleKey, 'success', $message);
                }
            }

            if ($errors !== []) {
                foreach ($skippedWinners as $skippedId => $skipReason) {
                    $importLogDetails[] = [
                        'level' => 'warning',
                        'ticket_id' => (string) $skippedId,
                        'message' => $skipReason,
                    ];
                }
                $currentImportLog = $this->storeWinnerImportLog($request, [
                    'status' => 'error',
                    'source_file' => $sourceFileName,
                    'winner_kind' => $values['rodzaj'],
                    'winner_kind_label' => $values['rodzaj'] === 'zdrapka' ? 'Zdrapka' : 'Los',
                    'prize_type' => $values['rodzaj_wygranej'],
                    'prize_name' => (string) (($prizeTypesById[$values['rodzaj_wygranej']]['name'] ?? '')),
                    'drawing_id' => $values['losowanie'],
                    'drawing_label' => (string) ($drawingOptions[$values['losowanie']] ?? ''),
                    'imported_count' => 0,
                    'skipped_count' => count($skippedWinners),
                    'errors' => array_values(array_unique($errors)),
                    'details' => $importLogDetails,
                    'technical_errors' => $technicalErrors,
                ]);
            }
        }

        return $this->render('backend/module_import_winners_csv.html.twig', [
            'sendWinnerEmails' => $request->request->getBoolean('send_winner_emails'),
            'user' => $this->authService->user(),
            'moduleKey' => $moduleKey,
            'module' => $module,
            'values' => $values,
            'errors' => $errors,
            'prizeTypeOptions' => $prizeTypeOptions,
            'drawingOptions' => $drawingOptions,
            'disabledDrawingOptions' => $disabledDrawingOptions,
            'backUrl' => $this->getModuleListUrl($request, $moduleKey),
            'currentImportLog' => $currentImportLog,
            'importLogs' => $this->listWinnerImportLogs(),
        ]);
    }

    public function winnerImportLog(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        if ($moduleKey !== 'zwyciezcy') {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }
        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_CREATE)) {
            return $response;
        }

        $logId = trim((string) ($parameters['logId'] ?? ''));
        $log = $this->loadWinnerImportLog($logId);
        if ($log === null) {
            return new Response('Nie znaleziono logu importu.', Response::HTTP_NOT_FOUND);
        }

        if ($request->query->getBoolean('download')) {
            $response = new Response("\xEF\xBB\xBF" . $this->formatWinnerImportLog($log));
            $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set(
                'Content-Disposition',
                sprintf('attachment; filename="import-zwyciezcow-%s.txt"', $logId)
            );

            return $response;
        }

        $response = $this->render('backend/winner_import_log.html.twig', [
            'user' => $this->authService->user(),
            'moduleKey' => $moduleKey,
            'log' => $log,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @return array<int, array{pickup_code: string}>
     */
    private function extractWinningCodesFromSpreadsheet(UploadedFile $uploadedFile): array
    {
        $spreadsheet = IOFactory::load($uploadedFile->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        if ($rows === []) {
            return [];
        }

        $headerRow = array_shift($rows);
        if (!is_array($headerRow)) {
            return [];
        }

        $pickupColumn = null;
        foreach ($headerRow as $column => $label) {
            $normalizedLabel = mb_strtolower(trim((string) $label));
            if ($normalizedLabel === 'pickupcode') {
                $pickupColumn = (string) $column;
            }
        }

        if ($pickupColumn === null) {
            throw new \RuntimeException('Missing required columns.');
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $pickupCode = trim((string) ($row[$pickupColumn] ?? ''));
            if ($pickupCode === '') {
                continue;
            }

            $result[] = [
                'pickup_code' => $pickupCode,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{pickup_code: string}> $rows
     * @return array<int, string>
     */
    private function findDuplicateWinningCodeRows(array $rows): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($rows as $row) {
            $key = $this->buildWinningCodeKey($row['pickup_code']);
            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $duplicates[$key] = $key;
                continue;
            }

            $seen[$key] = true;
        }

        return array_values($duplicates);
    }

    private function buildWinningCodeKey(string $pickupCode): string
    {
        $pickupCode = trim($pickupCode);

        if ($pickupCode === '') {
            return '';
        }

        return $pickupCode;
    }

    private function createEmptySingleModuleRecord(Connection $connection, string $table, string $primaryKey): string|int
    {
        $schemaManager = $connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns($table);
        $values = [];

        foreach ($columns as $column) {
            $columnName = $column->getName();
            if ($columnName === $primaryKey && $column->getAutoincrement()) {
                continue;
            }

            if (!$column->getNotnull() || $column->getDefault() !== null || $column->getAutoincrement()) {
                continue;
            }

            $typeName = strtolower($column->getType()->getName());
            $values[$columnName] = match ($typeName) {
                'integer', 'bigint', 'smallint' => 0,
                'float', 'decimal' => '0',
                'boolean' => false,
                'date', 'datetime', 'datetimetz', 'time' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                default => '',
            };
        }

        if ($values !== []) {
            $connection->insert($table, $values);
        } else {
            $platformName = strtolower($connection->getDatabasePlatform()->getName());
            $quotedTable = $connection->quoteIdentifier($table);
            if (str_contains($platformName, 'mysql')) {
                $connection->executeStatement(sprintf('INSERT INTO %s () VALUES ()', $quotedTable));
            } else {
                $connection->executeStatement(sprintf('INSERT INTO %s DEFAULT VALUES', $quotedTable));
            }
        }

        $lastInsertId = $connection->lastInsertId();
        if (is_numeric($lastInsertId) && (int) $lastInsertId > 0) {
            return (int) $lastInsertId;
        }

        $insertedId = $connection->createQueryBuilder()
            ->select('MAX(' . $primaryKey . ')')
            ->from($table)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($insertedId) ? (int) $insertedId : (string) $insertedId;
    }


    public function testFtpSettings(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Sesja wygasła. Zaloguj się ponownie.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->permissionService->can($this->authService->user(), 'system_settings', PermissionService::ACTION_EDIT)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak uprawnień do testowania ustawień FTP.',
            ], Response::HTTP_FORBIDDEN);
        }

        $portRaw = $request->request->get('ftp_port');
        $timeoutRaw = $request->request->get('ftp_timeout');
        $config = [
            'enabled' => true,
            'media_offload_enabled' => true,
            'host' => trim((string) $request->request->get('ftp_host', '')),
            'port' => is_numeric($portRaw) ? (int) $portRaw : null,
            'username' => trim((string) $request->request->get('ftp_username', '')),
            'password' => (string) $request->request->get('ftp_password', ''),
            'root_path' => trim((string) $request->request->get('ftp_root_path', '')),
            'passive_mode' => filter_var($request->request->get('ftp_passive_mode', false), FILTER_VALIDATE_BOOLEAN),
            'ssl_enabled' => filter_var($request->request->get('ftp_ssl_enabled', false), FILTER_VALIDATE_BOOLEAN),
            'timeout' => is_numeric($timeoutRaw) ? (int) $timeoutRaw : null,
        ];

        $result = (new FtpMediaStorage($config))->testConnection();

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    public function create(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_CREATE)) {
            return $response;
        }

        $module = $definition['module'];
        if (!$this->moduleAllowsConfiguredAction($module, 'create')) {
            return new Response('Brak uprawnień.', Response::HTTP_FORBIDDEN);
        }

        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';
        $manualSortColumn = $this->getManualSortColumn($module);

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        $fields = $this->normalizeFields($definition);
        $fields = $this->hydrateRelationFields($fields);
        if ($moduleKey === 'zwyciezcy') {
            $fields = $this->applyWinnerDrawingAvailability($fields, $this->entityManager->getConnection());
        }
        $fields = $this->filterFieldsForCreate($fields, $module);
        $errors = [];
        $values = [];
        $formValues = [];
        $backUrl = $this->getModuleListUrl($request, $moduleKey);

        $moduleType = strtolower((string) ($module['type'] ?? 'list'));

        if ($request->isMethod('GET') && $moduleType === 'list') {
            $duplicateId = (int) $request->query->get('duplicateId', 0);
            if ($duplicateId > 0) {
                $connection = $this->entityManager->getConnection();
                $row = $connection->createQueryBuilder()
                    ->select('*')
                    ->from($table)
                    ->where($primaryKey . ' = :id')
                    ->setParameter('id', $duplicateId)
                    ->executeQuery()
                    ->fetchAssociative();

                if (is_array($row)) {
                    foreach ($fields as $field) {
                        if (!is_array($field)) {
                            continue;
                        }

                        $fieldName = trim((string) ($field['name'] ?? ''));
                        if ($fieldName === '' || !array_key_exists($fieldName, $row)) {
                            continue;
                        }

                        $values[$fieldName] = $row[$fieldName];
                    }

                    unset($values[$primaryKey]);
                    if ($manualSortColumn) {
                        unset($values[$manualSortColumn]);
                    }
                }
            }
        }

        if ($request->isMethod('POST')) {
            $result = $this->extractFieldValues($fields, $request, $moduleKey);
            $values = $result['values'];
            $errors = array_merge($errors, $result['errors']);
            $connection = $this->entityManager->getConnection();

            if ($moduleKey === 'zwyciezcy' && $request->request->getBoolean('send_winner_emails')
                && !$request->request->getBoolean('confirm_winner_emails')) {
                $errors[] = 'Potwierdź wysyłkę maili do zwycięzców przed dodaniem.';
            }

            if ($errors === []) {
                $createActionResult = $this->runCreateActionHandler($connection, $module, $moduleKey, $values);
                $values = $createActionResult['values'];
                $errors = array_merge($errors, $createActionResult['errors']);
            }

            $formValues = $this->normalizeRelationFormValues($fields, $values);

            foreach ($fields as $field) {
                if (!is_array($field) || !($field['auto_current_datetime_on_create'] ?? false)) {
                    continue;
                }

                $fieldName = isset($field['name']) ? (string) $field['name'] : '';
                if ($fieldName === '' || array_key_exists($fieldName, $values)) {
                    continue;
                }

                $values[$fieldName] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            }

            if ($manualSortColumn && (!array_key_exists($manualSortColumn, $values) || $values[$manualSortColumn] === '')) {
                $maxPosition = (int) $connection->createQueryBuilder()
                    ->select('MAX(' . $manualSortColumn . ')')
                    ->from($table)
                    ->executeQuery()
                    ->fetchOne();
                $values[$manualSortColumn] = $maxPosition + 1;
            }

            if ($errors === []) {
                $allowedColumns = array_keys($connection->createSchemaManager()->listTableColumns($table));
                $values = array_intersect_key($values, array_flip($allowedColumns));
                try {
                    $lastCreatedId = $this->insertModuleEntry($connection, $moduleKey, $table, $values);
                } catch (LotteryDailyLimitException $exception) {
                    return $this->render('backend/module_form.html.twig', [
                        'user' => $this->authService->user(),
                        'moduleKey' => $moduleKey,
                        'module' => $module,
                        'fields' => $this->hydrateRemoteRelationFields($fields, $this->normalizeRelationFormValues($fields, $values)),
                        'values' => $this->normalizeRelationFormValues($fields, $values),
                        'errors' => [$exception->getMessage()],
                        'alert' => null,
                        'title' => 'Dodaj wpis',
                        'submitLabel' => 'Zapisz',
                        'backUrl' => $backUrl,
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $recordId = null;
                if (array_key_exists($primaryKey, $values) && $values[$primaryKey] !== '' && $values[$primaryKey] !== null) {
                    $recordId = (int) $values[$primaryKey];
                } else {
                    $lastInsertId = $lastCreatedId;
                    if (is_numeric($lastInsertId)) {
                        $recordId = (int) $lastInsertId;
                    }
                }
                $postCreateNotices = ['success' => [], 'error' => []];
                if ($moduleKey === 'zwyciezcy') {
                    $this->markWinnerLinkedTicketAsWon($connection, $values);
                    if ($recordId !== null) {
                        $postCreateFields = $this->hydrateRelationFields($this->normalizeFields($definition));
                        $postCreateNotices = $this->runWinnerInitialStatusHandlers(
                            $request,
                            $moduleKey,
                            $postCreateFields,
                            $table,
                            $primaryKey,
                            $recordId
                        );
                    }
                }
                $this->recordModuleChange($request, $moduleKey, 'create', $recordId);
                if ($postCreateNotices['error'] !== []) {
                    return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', implode(' ', $postCreateNotices['error']));
                }
                if ($postCreateNotices['success'] !== []) {
                    return $this->buildModuleAlertRedirect($request, $moduleKey, 'success', implode(' ', $postCreateNotices['success']));
                }
                $moduleType = strtolower((string) ($module['type'] ?? 'list'));
                if ($moduleType === 'single' && $recordId !== null) {
                    $query = http_build_query([
                        'status' => 'success',
                        'message' => 'Dane zostały zmienione.',
                    ]);

                    return new RedirectResponse('/cms/modules/' . $moduleKey . '/' . $recordId . '/edit?' . $query);
                }

                return new RedirectResponse($backUrl);
            }
        }
        if ($formValues === []) {
            $formValues = $this->normalizeRelationFormValues($fields, $values);
        }

        $fields = $this->hydrateRemoteRelationFields($fields, $formValues);

        $alert = $this->buildAlertFromRequest($request);

        return $this->render('backend/module_form.html.twig', [
            'user' => $this->authService->user(),
            'moduleKey' => $moduleKey,
            'module' => $module,
            'fields' => $fields,
            'values' => $formValues,
            'errors' => $errors,
            'alert' => $alert,
            'title' => 'Dodaj wpis',
            'showWinnerEmailOption' => $moduleKey === 'zwyciezcy',
            'sendWinnerEmails' => $request->request->getBoolean('send_winner_emails'),
            'submitLabel' => 'Zapisz',
            'backUrl' => $backUrl,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $module
     * @return array<int, array<string, mixed>>
     */
    private function filterFieldsForCreate(array $fields, array $module): array
    {
        $allowedFieldNames = $module['create_fields'] ?? null;
        if (!is_array($allowedFieldNames) || $allowedFieldNames === []) {
            return $fields;
        }

        $allowedMap = [];
        foreach ($allowedFieldNames as $allowedFieldName) {
            $allowedFieldName = trim((string) $allowedFieldName);
            if ($allowedFieldName === '') {
                continue;
            }
            $allowedMap[$allowedFieldName] = true;
        }

        if ($allowedMap === []) {
            return $fields;
        }

        $filteredFields = [];
        foreach ($fields as $field) {
            $fieldType = strtolower((string) ($field['type'] ?? 'text'));
            if ($fieldType === 'section') {
                continue;
            }

            $fieldName = trim((string) ($field['name'] ?? ''));
            if ($fieldName === '' || !isset($allowedMap[$fieldName])) {
                continue;
            }

            $filteredFields[] = $field;
        }

        return $filteredFields;
    }

    public function move(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if (!$request->isMethod('POST')) {
            return new Response('Niepoprawna metoda.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        $editPermissionError = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_EDIT);
        if ($editPermissionError !== null) {
            $createPermissionError = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_CREATE);
            if ($createPermissionError !== null) {
                return $editPermissionError;
            }
        }

        $module = $definition['module'];
        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';
        $manualSortColumn = $this->getManualSortColumn($module);
        $direction = (string) ($parameters['direction'] ?? '');
        $id = (int) ($parameters['id'] ?? 0);

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        if (!$manualSortColumn) {
            return new Response('Ręczne sortowanie jest wyłączone.', Response::HTTP_BAD_REQUEST);
        }

        if (!in_array($direction, ['up', 'down'], true)) {
            return new Response('Niepoprawny kierunek.', Response::HTTP_BAD_REQUEST);
        }

        $connection = $this->entityManager->getConnection();
        $current = $connection->createQueryBuilder()
            ->select($primaryKey, $manualSortColumn)
            ->from($table)
            ->where($primaryKey . ' = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        if (!$current) {
            return new Response('Nie znaleziono wpisu.', Response::HTTP_NOT_FOUND);
        }

        $currentPosition = (int) $current[$manualSortColumn];
        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'desc' : 'asc';

        $neighbor = $connection->createQueryBuilder()
            ->select($primaryKey, $manualSortColumn)
            ->from($table)
            ->where($manualSortColumn . ' ' . $operator . ' :position')
            ->setParameter('position', $currentPosition)
            ->orderBy($manualSortColumn, $order)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$neighbor) {
            return new RedirectResponse($this->getModuleListUrl($request, $moduleKey));
        }

        $neighborPosition = (int) $neighbor[$manualSortColumn];

        $connection->beginTransaction();
        try {
            $connection->update($table, [$manualSortColumn => $neighborPosition], [$primaryKey => $id]);
            $connection->update($table, [$manualSortColumn => $currentPosition], [$primaryKey => $neighbor[$primaryKey]]);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
        $this->recordModuleChange($request, $moduleKey, 'move', $id);

        return new RedirectResponse($this->getModuleListUrl($request, $moduleKey));
    }

    public function edit(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        DebugCollector::addDump('edytuje');
        
        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        $user = $this->authService->user();
        $canEdit = $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_EDIT);
        $canView = $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_VIEW);

        if (!$canView) {
            return new Response('Brak uprawnień.', Response::HTTP_FORBIDDEN);
        }

        $module = $definition['module'];
        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';
        $id = (int) ($parameters['id'] ?? 0);

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        $fields = $this->normalizeFields($definition);
        $fields = $this->hydrateRelationFields($fields);
        $connection = $this->entityManager->getConnection();
        $existing = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where($primaryKey . ' = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        if (!$existing) {
            return new Response('Nie znaleziono wpisu.', Response::HTTP_NOT_FOUND);
        }

        $errors = [];
        $values = $existing;
        $formValues = $this->normalizeRelationFormValues($fields, $values);
        $formValues = $this->hydrateRelatedFieldValues($fields, $formValues);
        $readOnly = !$canEdit;
        $backUrl = $this->getModuleListUrl($request, $moduleKey);

        if ($readOnly && $request->isMethod('POST')) {
            return new Response('Brak uprawnień.', Response::HTTP_FORBIDDEN);
        }

        if ($request->isMethod('POST')) {
            
            $result = $this->extractFieldValues($fields, $request, $moduleKey);
            $values = array_merge($existing, $result['values']);
            if ($moduleKey === 'loteria_zgloszenia') {
                $values = array_replace($values, LotteryReceiptValidator::normalize($values));
            }
            $errors = array_merge($errors, $result['errors']);
            $formValues = $this->normalizeRelationFormValues($fields, $values);

            DebugCollector::addDump('wysłane');

            if ($errors === []) {
                $relatedFieldUpdateErrors = $this->updateEditableRelatedFieldValues($fields, $request, $values);
                $errors = array_merge($errors, $relatedFieldUpdateErrors);
            }

            if ($errors === []) {
                $recalculateLotteryTickets = $moduleKey === 'loteria_zgloszenia'
                    && $this->hasLotteryAllocationInputChanged($existing, $values);
                $updateValues = $values;
                if ($moduleKey === 'loteria_ustawienia') {
                    // The sequence is advanced by frontend submissions. Do not overwrite
                    // it with the stale value loaded when the settings form was opened.
                    unset($updateValues['ticket_id_sequence_value']);
                }
                if ($moduleKey === 'loteria_zgloszenia') {
                    try {
                        $updateSubmission = function () use ($connection, $table, $updateValues, $primaryKey, $id): void {
                            $connection->update($table, $updateValues, [$primaryKey => $id]);
                        };
                        if ($recalculateLotteryTickets) {
                            $this->rebuildLotteryTickets($connection, $values, $id, $updateSubmission);
                        } else {
                            $this->updateLotterySubmission($connection, $table, $values, $id, $updateSubmission);
                        }
                    } catch (LotteryDailyLimitException $exception) {
                        return $this->render('backend/module_form.html.twig', [
                            'user' => $user,
                            'moduleKey' => $moduleKey,
                            'module' => $module,
                            'fields' => $this->hydrateRemoteRelationFields($fields, $formValues),
                            'values' => $formValues,
                            'errors' => [$exception->getMessage()],
                            'alert' => null,
                            'readOnly' => $readOnly,
                            'title' => 'Edytuj wpis',
                            'submitLabel' => 'Zapisz zmiany',
                            'backUrl' => $backUrl,
                        ], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }
                } else {
                    $connection->update($table, $updateValues, [$primaryKey => $id]);
                }
                $onChangeNotices = $this->runOnChangeHandlers($request, $moduleKey, $fields, $existing, $values, $id);
                $changeSet = $this->buildChangeSet($fields, $existing, $values);
                $this->recordModuleChange($request, $moduleKey, 'edit', $id, $changeSet);

                $moduleType = strtolower((string) ($module['type'] ?? 'list'));
                if ($moduleType === 'single') {
                    $hasOnChangeErrors = $onChangeNotices['error'] !== [];
                    $baseMessage = $hasOnChangeErrors ? 'Dane zostały zmienione, ale część akcji nie została wykonana.' : 'Dane zostały zmienione.';
                    $query = http_build_query([
                        'status' => $hasOnChangeErrors ? 'error' : 'success',
                        'message' => trim(implode(' ', array_filter([
                            $baseMessage,
                            implode(' ', $onChangeNotices['success']),
                            implode(' ', $onChangeNotices['error']),
                        ]))),
                    ]);

                    return new RedirectResponse('/cms/modules/' . $moduleKey . '/' . $id . '/edit?' . $query);
                }

                $hasOnChangeErrors = $onChangeNotices['error'] !== [];
                $baseMessage = $hasOnChangeErrors ? 'Dane zostały zmienione, ale część akcji nie została wykonana.' : 'Dane zostały zmienione.';
                $message = trim(implode(' ', array_filter([
                    $baseMessage,
                    implode(' ', $onChangeNotices['success']),
                    implode(' ', $onChangeNotices['error']),
                ])));

                return $this->buildModuleAlertRedirect(
                    $request,
                    $moduleKey,
                    $hasOnChangeErrors ? 'error' : 'success',
                    $message
                );
            }
        }

        $fields = $this->hydrateRemoteRelationFields($fields, $formValues);
        $alert = $this->buildAlertFromRequest($request);

        return $this->render('backend/module_form.html.twig', [
            'user' => $user,
            'moduleKey' => $moduleKey,
            'module' => $module,
            'fields' => $fields,
            'values' => $formValues,
            'errors' => $errors,
            'alert' => $alert,
            'readOnly' => $readOnly,
            'title' => $readOnly ? 'Podgląd wpisu' : 'Edytuj wpis',
            'submitLabel' => 'Zapisz zmiany',
            'backUrl' => $backUrl,
        ]);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $values
     */
    private function hasLotteryAllocationInputChanged(array $existing, array $values): bool
    {
        $fields = ['promoted_products_count', 'other_products_count'];
        if (!LotteryProductAllocation::isProductBased($this->fetchModuleEntry('loteria_ustawienia') ?? [])) {
            $fields[] = 'purchase_amount';
        }
        foreach ($fields as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $old = $field === 'purchase_amount' ? $this->normalizeLotteryAmount($existing[$field] ?? null) : (int) ($existing[$field] ?? 0);
            $new = $field === 'purchase_amount' ? $this->normalizeLotteryAmount($values[$field]) : (int) ($values[$field] ?? 0);
            if ($old !== $new) {
                return true;
            }
        }
        return false;
    }

    /** Inserts individual CMS/CSV entries through the same limit as frontend grants. */
    private function insertModuleEntry(Connection $connection, string $moduleKey, string $table, array &$values): string
    {
        $lastCreatedId = '';
        $insertEntry = function () use ($connection, $table, &$values, &$lastCreatedId): void {
            $connection->insert($table, $values);
            $lastCreatedId = (string) $connection->lastInsertId();
        };
        if (!in_array($moduleKey, ['loteria_losy', 'loteria_zdrapki', 'loteria_zgloszenia'], true)) {
            $insertEntry();
            return $lastCreatedId;
        }
        $timezone = $this->config['app']['timezone'] ?? 'Europe/Warsaw';
        $grantedAt = new \DateTimeImmutable(($values['submitted_at'] ?? '') ?: 'now', new \DateTimeZone($timezone));
        $values['submitted_at'] = $grantedAt->format('Y-m-d H:i:s');
        $values['email'] = mb_strtolower(trim((string) ($values['email'] ?? '')));
        $values['phone'] = LotteryRegistrationValidator::normalizePhone((string) ($values['phone'] ?? ''));
        $type = $moduleKey === 'loteria_losy' ? 'tickets' : 'scratchcards';
        $isSubmission = $moduleKey === 'loteria_zgloszenia';
        if ($isSubmission) {
            $values = array_replace($values, LotteryReceiptValidator::normalize($values));
        }
        $allocationSettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $purchase = $isSubmission ? array_merge(['table' => $table], LotteryProductAllocation::quantities($values)) : null;
        (new LotteryDailyEntryLimitService($connection, $timezone))->allocate(
            $allocationSettings,
            [$type => $table],
            $isSubmission ? [] : [$type => 1],
            $grantedAt,
            ['email' => $values['email'], 'phone' => $values['phone']],
            static function (array $counts) use ($insertEntry, $isSubmission, &$values): void {
                if ($isSubmission) {
                    $values['allocated_promoted_products_count'] = $counts['allocated_promoted_products_count'];
                    $values['allocated_other_products_count'] = $counts['allocated_other_products_count'];
                }
                $insertEntry();
            },
            null,
            $purchase,
            $isSubmission ? array_merge(['table' => $table], LotteryReceiptValidator::normalize($values)) : null
        );
        return $lastCreatedId;
    }

    /** Updates receipt/status fields without regenerating existing entries. */
    private function updateLotterySubmission(Connection $connection, string $table, array $submission, int $submissionId, callable $updateSubmission): void
    {
        (new LotteryDailyEntryLimitService($connection))->allocate(
            [], [], [], new \DateTimeImmutable(), [],
            static function (array $_counts) use ($updateSubmission): void { $updateSubmission(); },
            $submissionId, null,
            array_merge(['table' => $table], LotteryReceiptValidator::normalize($submission))
        );
    }

    /**
     * @param array<string, mixed> $submission
     */
    private function rebuildLotteryTickets(Connection $connection, array $submission, int $submissionId, callable $updateSubmission): void
    {
        $ticketDefinition = $this->resolveModuleDefinition('loteria_losy');
        $ticketTable = $ticketDefinition ? ($ticketDefinition['database']['table'] ?? null) : null;
        $scratchDefinition = $this->resolveModuleDefinition('loteria_zdrapki');
        $scratchTable = $scratchDefinition ? ($scratchDefinition['database']['table'] ?? null) : null;
        if (!$ticketTable && !$scratchTable) {
            $this->updateLotterySubmission($connection, $this->resolveModuleDefinition('loteria_zgloszenia')['database']['table'], $submission, $submissionId, $updateSubmission);
            return;
        }

        $settingsEntry = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $amountPerTicket = 50.0;
        if (isset($settingsEntry['amount_per_ticket']) && is_numeric($settingsEntry['amount_per_ticket'])) {
            $amountPerTicket = (float) $settingsEntry['amount_per_ticket'];
        }
        if ($amountPerTicket <= 0) {
            $amountPerTicket = 50.0;
        }
        $entryGenerationMode = $this->resolveLotteryEntryGenerationMode($settingsEntry);
        $shouldGenerateTickets = $this->shouldGenerateLotteryTickets($entryGenerationMode);
        $shouldGenerateScratchcards = $this->shouldGenerateLotteryScratchcards($entryGenerationMode);

        $purchaseAmount = $this->normalizeLotteryAmount($submission['purchase_amount'] ?? null) ?? 0.0;
        $ticketCount = (int) floor($purchaseAmount / $amountPerTicket);
        if (isset($settingsEntry['max_tickets_per_receipt']) && is_numeric($settingsEntry['max_tickets_per_receipt'])) {
            $maxTicketsPerReceipt = (int) $settingsEntry['max_tickets_per_receipt'];
            if ($maxTicketsPerReceipt > 0) {
                $ticketCount = min($ticketCount, $maxTicketsPerReceipt);
            }
        }
        if (LotteryProductAllocation::isProductBased($settingsEntry)) {
            $ticketCount = LotteryProductAllocation::requestedEntryCount($submission, $settingsEntry);
        }

        $submittedAt = $submission['submitted_at'] ?? null;
        if ($submittedAt instanceof \DateTimeInterface) {
            $submittedAt = $submittedAt->format('Y-m-d H:i:s');
        }
        if (!is_string($submittedAt) || $submittedAt === '') {
            $submittedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        $email = $submission['email'] ?? null;
        $phone = $submission['phone'] ?? null;
        (new LotteryDailyEntryLimitService($connection, $this->config['app']['timezone'] ?? 'Europe/Warsaw'))->allocate(
            $settingsEntry,
            ['tickets' => $ticketTable, 'scratchcards' => $scratchTable],
            [
                'tickets' => $ticketTable && $shouldGenerateTickets ? max(0, $ticketCount) : 0,
                'scratchcards' => $scratchTable && $shouldGenerateScratchcards ? max(0, $ticketCount) : 0,
            ],
            new \DateTimeImmutable($submittedAt, new \DateTimeZone($this->config['app']['timezone'] ?? 'Europe/Warsaw')),
            ['email' => $email, 'phone' => $phone],
            function (array $counts) use ($connection, $ticketTable, $scratchTable, $submissionId, $settingsEntry, $email, $phone, $submittedAt, $updateSubmission): void {
                $updateSubmission();
                $connection->update($this->resolveModuleDefinition('loteria_zgloszenia')['database']['table'], [
                    'allocated_promoted_products_count' => $counts['allocated_promoted_products_count'],
                    'allocated_other_products_count' => $counts['allocated_other_products_count'],
                ], ['id' => $submissionId]);
                if ($ticketTable) {
                    $connection->delete($ticketTable, ['zgloszenie' => $submissionId]);
                }
                if ($scratchTable) {
                    $connection->delete($scratchTable, ['zgloszenie' => $submissionId]);
                }
                $this->createLotteryEntriesWithIdentifiers(
                    $connection,
                    array_values(array_filter([$ticketTable, $scratchTable], static fn(mixed $table): bool => is_string($table) && $table !== '')),
                    $submissionId,
                    max($counts['tickets'], $counts['scratchcards']),
                    $settingsEntry,
                    function (string $ticketId, int $index) use (
                        $connection,
                        $ticketTable,
                        $scratchTable,
                        $counts,
                        $submissionId,
                        $email,
                        $phone,
                        $submittedAt
                    ): void {
                        $payload = [
                            'ticket_id' => $ticketId,
                            'zgloszenie' => $submissionId,
                            'email' => $email !== '' ? $email : null,
                            'phone' => $phone !== '' ? $phone : null,
                            'submitted_at' => $submittedAt,
                            'wygrany' => 0,
                        ];
                        if ($ticketTable && $index <= $counts['tickets']) {
                            $ticketPayload = $payload;
                            $ticketPayload['status'] = 'nie_sprawdzany';
                            $connection->insert($ticketTable, $ticketPayload);
                        }
                        if ($scratchTable && $index <= $counts['scratchcards']) {
                            $scratchPayload = $payload;
                            $scratchPayload['status'] = 'Nie zdrapana';
                            $connection->insert($scratchTable, $scratchPayload);
                        }
                    }
                );
            },
            $submissionId,
            array_merge(
                ['table' => $this->resolveModuleDefinition('loteria_zgloszenia')['database']['table']],
                LotteryProductAllocation::quantities($submission)
            ),
            array_merge(
                ['table' => $this->resolveModuleDefinition('loteria_zgloszenia')['database']['table']],
                LotteryReceiptValidator::normalize($submission)
            )
        );
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function buildAlertFromRequest(Request $request): ?array
    {
        $alertStatus = strtolower((string) $request->query->get('status', ''));
        $alertMessage = trim((string) $request->query->get('message', ''));

        if ($alertMessage === '' || !in_array($alertStatus, ['success', 'error'], true)) {
            return null;
        }

        return [
            'type' => $alertStatus === 'success' ? 'success' : 'danger',
            'message' => $alertMessage,
        ];
    }

    private function normalizeFieldValueForComparison(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return trim((string) json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $values
     */
    private function runOnChangeHandlers(
        Request $request,
        string $moduleKey,
        array $fields,
        array $existing,
        array $values,
        int $recordId
    ): array {
        $notices = [
            'success' => [],
            'error' => [],
        ];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldName = trim((string) ($field['name'] ?? ''));
            if ($fieldName === '' || !array_key_exists($fieldName, $values) || !array_key_exists($fieldName, $existing)) {
                continue;
            }

            $oldValue = $this->normalizeFieldValueForComparison($existing[$fieldName]);
            $newValue = $this->normalizeFieldValueForComparison($values[$fieldName]);
            if ($oldValue === $newValue) {
                continue;
            }

            $handlerName = is_array($field['on_change'] ?? null)
                ? trim((string) (($field['on_change']['handler'] ?? '')))
                : trim((string) ($field['on_change'] ?? ''));

            if ($handlerName === '') {
                continue;
            }

            $onChangeController = $this->resolveOnChangeController($moduleKey);
            if ($onChangeController === null) {
                continue;
            }

            $handlerNotices = $onChangeController->handle(
                $handlerName,
                $moduleKey,
                $fieldName,
                $existing[$fieldName],
                $values[$fieldName],
                $recordId,
                $values,
                $existing
            );

            foreach (['success', 'error'] as $type) {
                foreach (($handlerNotices[$type] ?? []) as $notice) {
                    if (!is_string($notice)) {
                        continue;
                    }

                    $notice = trim($notice);
                    if ($notice === '') {
                        continue;
                    }

                    $notices[$type][] = $notice;
                }
            }
        }

        return [
            'success' => array_values(array_unique($notices['success'])),
            'error' => array_values(array_unique($notices['error'])),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array{success: array<int, string>, error: array<int, string>}
     */
    private function runWinnerInitialStatusHandlers(
        Request $request,
        string $moduleKey,
        array $fields,
        string $table,
        string $primaryKey,
        int $recordId
    ): array {
        $winner = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where($primaryKey . ' = :id')
            ->setParameter('id', $recordId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($winner) || $winner === []) {
            return ['success' => [], 'error' => []];
        }

        $initialStatus = trim((string) ($winner['stan'] ?? ''));
        if ($initialStatus === '') {
            return ['success' => [], 'error' => []];
        }

        $previousWinner = $winner;
        $previousWinner['stan'] = '';
        $winner['_send_status_email_after_creation'] = $request->request->getBoolean('send_winner_emails')
            && $request->request->getBoolean('confirm_winner_emails');

        return $this->runOnChangeHandlers($request, $moduleKey, $fields, $previousWinner, $winner, $recordId);
    }

    private function resolveOnChangeController(string $moduleKey): ?ModuleOnChangeController
    {
        if (array_key_exists($moduleKey, $this->onChangeControllers)) {
            return $this->onChangeControllers[$moduleKey];
        }

        $controllerClass = $this->resolveOnChangeControllerClass($moduleKey);
        $controller = null;
        if ($controllerClass !== null) {
            $controllerInstance = new $controllerClass($this->entityManager, $this->mailer);
            $controller = $controllerInstance instanceof ModuleOnChangeController ? $controllerInstance : null;
        }

        $this->onChangeControllers[$moduleKey] = $controller;

        return $controller;
    }

    private function resolveOnChangeControllerClass(string $moduleKey): ?string
    {
        $normalizedModuleKey = trim($moduleKey);
        if ($normalizedModuleKey === '') {
            return null;
        }

        $normalizedModuleKey = $this->resolveModuleControllerKey($normalizedModuleKey);

        $segments = array_values(array_filter(array_map(
            static fn(string $segment): string => trim($segment),
            explode('_', $normalizedModuleKey)
        ), static fn(string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return null;
        }

        $classBaseName = implode('', array_map(static fn(string $segment): string => ucfirst($segment), $segments));
        $controllerClass = __NAMESPACE__ . '\\' . $classBaseName . 'ModuleOnChangeController';

        if (!class_exists($controllerClass) || !is_subclass_of($controllerClass, ModuleOnChangeController::class)) {
            return null;
        }

        return $controllerClass;
    }

    private function resolveModuleControllerKey(string $moduleKey): string
    {
        $definition = $this->loadModuleDefinition($moduleKey);
        $module = is_array($definition) ? ($definition['module'] ?? []) : [];
        $controllerModule = is_array($module) ? trim((string) ($module['controller_module'] ?? '')) : '';

        return $controllerModule !== '' ? $controllerModule : $moduleKey;
    }

    private function resolveCreateActionController(string $moduleKey): ?ModuleCreateActionController
    {
        if (array_key_exists($moduleKey, $this->createActionControllers)) {
            return $this->createActionControllers[$moduleKey];
        }

        $controllerClass = $this->resolveModuleScopedControllerClass($moduleKey, 'ModuleCreateActionController', ModuleCreateActionController::class);
        $controller = null;
        if ($controllerClass !== null) {
            $instance = new $controllerClass($this->entityManager);
            $controller = $instance instanceof ModuleCreateActionController ? $instance : null;
        }

        $this->createActionControllers[$moduleKey] = $controller;

        return $controller;
    }

    protected function resolveModuleScopedControllerClass(string $moduleKey, string $suffix, string $baseClass): ?string
    {
        $normalizedModuleKey = trim($moduleKey);
        if ($normalizedModuleKey === '') {
            return null;
        }

        $segments = array_values(array_filter(array_map(
            static fn(string $segment): string => trim($segment),
            explode('_', $normalizedModuleKey)
        ), static fn(string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return null;
        }

        $classBaseName = implode('', array_map(static fn(string $segment): string => ucfirst($segment), $segments));
        $controllerClass = __NAMESPACE__ . '\\' . $classBaseName . $suffix;

        if (!class_exists($controllerClass) || !is_subclass_of($controllerClass, $baseClass)) {
            return null;
        }

        return $controllerClass;
    }

    private function normalizeLotteryAmount(mixed $amount): ?float
    {
        if ($amount === null) {
            return null;
        }

        if (is_string($amount)) {
            $amount = trim($amount);
        }

        if ($amount === '') {
            return null;
        }

        if (!is_numeric($amount)) {
            return null;
        }

        return (float) $amount;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $values
     */
    private function shouldTriggerWinnerVerificationActions(string $moduleKey, array $existing, array $values): bool
    {
        DebugCollector::addDump($moduleKey, 'winner_verification.module_key');

        if (!in_array($moduleKey, ['zwyciezcy'], true)) {
            DebugCollector::addDump(false, 'winner_verification.result');
            return false;
        }

        $previousStatus = trim((string) ($existing['stan'] ?? ''));
        $newStatus = trim((string) ($values['stan'] ?? ''));
        DebugCollector::addDump($previousStatus, 'winner_verification.previous_status');
        DebugCollector::addDump($newStatus, 'winner_verification.new_status');

        if ($newStatus !== 'sprawdzone' || $previousStatus === 'sprawdzone') {
            DebugCollector::addDump(false, 'winner_verification.result');
            return false;
        }

        $emailSentAt = trim((string) ($values['email_o_poprawnej_weryfikacji_wyslany_at'] ?? ''));
        DebugCollector::addDump($emailSentAt, 'winner_verification.email_sent_at');

        if ($emailSentAt === '') {
            DebugCollector::addDump(true, 'winner_verification.result');
            return true;
        }

        DebugCollector::addDump($emailSentAt, 'data');
        $hasZeroDate = preg_match('/^0{4}-0{2}-0{2}(?:\s+0{2}:0{2}:0{2})?$/', $emailSentAt) === 1;
        DebugCollector::addDump($hasZeroDate, 'winner_verification.has_zero_date');

        if (!$hasZeroDate) {
            DebugCollector::addDump(false, 'winner_verification.result');
            return false;
        }

        $result = true;
        if ($moduleKey === 'zwyciezcy') {
            $hasAssignedWinningCodes = $this->hasAssignedWinningCodes($values['kod_z_listy_wygranych'] ?? null);
            DebugCollector::addDump($hasAssignedWinningCodes, 'winner_verification.has_assigned_winning_codes');
            $result = !$hasAssignedWinningCodes;
        } else {
            $result = true;
        }
        DebugCollector::addDump($result, 'winner_verification.result');

        return $result;
    }

    public function view(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        $user = $this->authService->user();
        $canView = $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_VIEW);

        if (!$canView) {
            return new Response('Brak uprawnień.', Response::HTTP_FORBIDDEN);
        }

        $module = $definition['module'];
        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';
        $id = (int) ($parameters['id'] ?? 0);

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        $allFields = $this->normalizeFields($definition);
        $fields = $this->buildConfiguredViewFields($allFields, $module['view']['fields'] ?? null);
        $editableViewFields = $this->buildConfiguredViewFields($allFields, $module['view']['editable_fields'] ?? null, false);
        $fields = $this->hydrateRelationFields($fields);
        $editableViewFields = $this->hydrateRelationFields($editableViewFields);
        $connection = $this->entityManager->getConnection();
        $existing = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where($primaryKey . ' = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        if (!$existing) {
            return new Response('Nie znaleziono wpisu.', Response::HTTP_NOT_FOUND);
        }

        $formValues = $this->normalizeRelationFormValues($fields, $existing);
        $formValues = $this->hydrateRelatedFieldValues($fields, $formValues);
        $fields = $this->hydrateRemoteRelationFields($fields, $formValues);
        $editableViewFields = $this->hydrateRemoteRelationFields($editableViewFields, $formValues);

        if (strtolower((string) ($module['view']['display_mode'] ?? '')) === 'text') {
            $formValues = $this->hydratePlainViewRelationValues($formValues, $fields);
        }

        return $this->render('backend/module_form.html.twig', [
            'user' => $user,
            'moduleKey' => $moduleKey,
            'module' => $module,
            'fields' => $fields,
            'viewEditableFields' => $editableViewFields,
            'values' => $formValues,
            'errors' => [],
            'alert' => $this->buildAlertFromRequest($request),
            'readOnly' => true,
            'title' => 'Podgląd wpisu',
            'submitLabel' => 'Zapisz zmiany',
            'backUrl' => $this->getModuleListUrl($request, $moduleKey),
            'viewActions' => $this->normalizeExtraActionsConfig($module['view']['actions'] ?? null),
            'recordId' => $id,
        ]);
    }

    public function relationSearch(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $fieldName = (string) ($parameters['fieldName'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_VIEW)) {
            return $response;
        }

        $fields = $this->normalizeFields($definition);
        $fieldConfig = null;
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = strtolower((string) ($field['type'] ?? 'text'));
            if ($name === $fieldName && $type === 'relation') {
                $fieldConfig = $field;
                break;
            }
        }

        if (!$fieldConfig || !($fieldConfig['remote'] ?? false)) {
            return new Response('Nie znaleziono pola.', Response::HTTP_NOT_FOUND);
        }

        $relationDefinition = $this->resolveRelationDefinition($fieldConfig);
        if (!$relationDefinition) {
            return new Response('Brak konfiguracji relacji.', Response::HTTP_BAD_REQUEST);
        }

        $term = trim((string) $request->query->get('q', ''));
        $idsParam = trim((string) $request->query->get('ids', ''));
        $ids = [];
        if ($idsParam !== '') {
            $ids = array_values(array_filter(array_map(
                static fn(string $value): string => trim($value),
                explode(',', $idsParam)
            ), static fn(string $value): bool => $value !== ''));
        }

        if ($term === '' && $ids === []) {
            return new Response(json_encode(['items' => []], JSON_UNESCAPED_UNICODE), Response::HTTP_OK, [
                'Content-Type' => 'application/json',
            ]);
        }

        $limit = (int) $request->query->get('limit', 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        $limit = min($limit, 50);

        $connection = $this->entityManager->getConnection();
        $queryBuilder = $connection->createQueryBuilder()
            ->select(...$this->getRelationSelectFields($fieldConfig, $relationDefinition))
            ->from($relationDefinition['table'])
            ->setMaxResults($limit);

        if (is_string($relationDefinition['order_field']) && $relationDefinition['order_field'] !== '') {
            $queryBuilder->addOrderBy($relationDefinition['order_field'], $relationDefinition['order_direction']);
        }

        $queryBuilder->addOrderBy($relationDefinition['label_field'], 'asc');

        $searchFilters = $fieldConfig['search_filters'] ?? [];
        if (is_array($searchFilters)) {
            $filterIndex = 0;
            foreach ($searchFilters as $queryParam => $columnName) {
                $queryParam = trim((string) $queryParam);
                $columnName = trim((string) $columnName);
                if ($queryParam === '' || $columnName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
                    continue;
                }

                $value = trim((string) $request->query->get($queryParam, ''));
                if ($value === '') {
                    continue;
                }

                $parameterName = 'filter_' . $filterIndex;
                ++$filterIndex;
                $queryBuilder
                    ->andWhere($columnName . ' = :' . $parameterName)
                    ->setParameter($parameterName, $value);
            }
        }

        if ($ids !== []) {
            $queryBuilder
                ->andWhere($relationDefinition['value_field'] . ' IN (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::STRING);
        } else {
            $expression = $queryBuilder->expr()->orX();
            foreach ($this->getRelationSearchFields($fieldConfig, $relationDefinition) as $searchField) {
                $expression->add($queryBuilder->expr()->like($searchField, ':term'));
            }
            $queryBuilder
                ->andWhere($expression)
                ->setParameter('term', '%' . $term . '%');
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        $items = [];
        foreach ($rows as $row) {
            if (!array_key_exists($relationDefinition['value_field'], $row)) {
                continue;
            }
            $value = (string) $row[$relationDefinition['value_field']];
            if ($value === '') {
                continue;
            }
            $items[] = [
                'value' => $value,
                'label' => $this->buildRelationLabel($fieldConfig, $row, $relationDefinition),
            ];
        }

        return new Response(json_encode(['items' => $items], JSON_UNESCAPED_UNICODE), Response::HTTP_OK, [
            'Content-Type' => 'application/json',
        ]);
    }

    /** @param array<string, mixed> $values */
    private function markWinnerLinkedTicketAsWon(Connection $connection, array $values): void
    {
        $winnerKind = trim((string) ($values['rodzaj'] ?? ''));
        $ticketColumn = $winnerKind === 'zdrapka' ? 'zdrapka_w_loterii' : 'kupon_w_loterii';
        $ticketId = (int) ($values[$ticketColumn] ?? 0);
        if ($ticketId <= 0 || !in_array($winnerKind, ['los', 'zdrapka'], true)) {
            return;
        }

        $ticketTable = $winnerKind === 'zdrapka' ? 'loteria_zdrapki_entries' : 'loteria_losy_entries';
        $ticketColumns = array_keys($connection->createSchemaManager()->listTableColumns($ticketTable));
        if (!in_array('wygrany', $ticketColumns, true)) {
            return;
        }

        $connection->update($ticketTable, ['wygrany' => 1], ['id' => $ticketId]);
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, mixed> $values
     * @return array{values: array<string, mixed>, errors: array<int, string>}
     */
    private function runCreateActionHandler(Connection $connection, array $module, string $moduleKey, array $values): array
    {
        $createActionConfig = $module['create_action'] ?? null;
        $handlerName = is_array($createActionConfig)
            ? trim((string) ($createActionConfig['handler'] ?? ''))
            : trim((string) $createActionConfig);
        if ($handlerName === '') {
            return ['values' => $values, 'errors' => []];
        }

        $controller = $this->resolveCreateActionController($moduleKey);
        if ($controller === null) {
            return ['values' => $values, 'errors' => []];
        }

        return $controller->handle($handlerName, $connection, $values, $moduleKey);
    }

    public function fileBrowser(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('pliki', PermissionService::ACTION_VIEW)) {
            return $response;
        }

        $term = mb_strtolower(trim((string) $request->query->get('q', '')));
        $limit = (int) $request->query->get('limit', 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        $limit = min($limit, 500);

        $items = [];
        $seenSlugs = [];
        foreach ($this->fetchModuleEntries('pliki', [], ['orderBy' => 'nazwa', 'direction' => 'asc']) as $entry) {
            $id = trim((string) ($entry['id'] ?? ''));
            $label = trim((string) ($entry['nazwa'] ?? ''));
            $originalName = trim((string) ($entry['oryginalna_nazwa_pliku'] ?? ''));
            $storedUrl = trim((string) ($entry['plik'] ?? ''));

            if ($id === '' || $storedUrl === '') {
                continue;
            }

            if ($label === '') {
                $label = $originalName !== '' ? $originalName : $id;
            }

            $prettyUrl = '/pliki/' . $this->buildPrettyFileSlug($entry, $seenSlugs);

            if ($term !== '') {
                $haystack = mb_strtolower($label . ' ' . $originalName . ' ' . $prettyUrl . ' ' . $storedUrl);
                if (!str_contains($haystack, $term)) {
                    continue;
                }
            }

            $items[] = [
                'value' => $id,
                'label' => $label,
                'url' => $prettyUrl,
                'originalName' => $originalName,
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return new Response(json_encode(['items' => $items], JSON_UNESCAPED_UNICODE), Response::HTTP_OK, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function winnersPdf(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        return new Response('Moduł loterii został usunięty.', Response::HTTP_GONE);
    }

    private function maskEmailForWinnersPdf(string $email): string
    {
        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            return '';
        }

        [$localPart, $domainPart] = explode('@', $email, 2);
        if ($localPart === '' || $domainPart === '') {
            return '';
        }

        $maskSegment = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }

            $length = mb_strlen($value);
            if ($length <= 1) {
                return mb_substr($value, 0, 1) . '***';
            }

            return mb_substr($value, 0, 1) . '***' . mb_substr($value, -1, 1);
        };

        $maskedLocal = $maskSegment($localPart);
        $domainLabels = explode('.', $domainPart);
        $domainName = (string) array_shift($domainLabels);
        $maskedDomainName = $maskSegment($domainName);

        $domainSuffix = implode('.', array_filter($domainLabels, static fn ($part): bool => trim($part) !== ''));
        if ($maskedLocal === '' || $maskedDomainName === '') {
            return '';
        }

        if ($domainSuffix === '') {
            return $maskedLocal . '@' . $maskedDomainName;
        }

        return $maskedLocal . '@' . $maskedDomainName . '.' . $domainSuffix;
    }

    public function delete(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if (!$request->isMethod('POST')) {
            return new Response('Niepoprawna metoda.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_DELETE)) {
            return $response;
        }

        if (!$this->moduleAllowsConfiguredAction($definition['module'], 'delete')) {
            return new Response('Brak uprawnień.', Response::HTTP_FORBIDDEN);
        }

        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';
        $id = (int) ($parameters['id'] ?? 0);

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->getConnection()->delete($table, [$primaryKey => $id]);
        $this->recordModuleChange($request, $moduleKey, 'delete', $id);

        return new RedirectResponse($this->getModuleListUrl($request, $moduleKey));
    }

    public function bulkDelete(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if (!$request->isMethod('POST')) {
            return new Response('Niepoprawna metoda.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_DELETE)) {
            return $response;
        }

        if (!$this->moduleAllowsConfiguredAction($definition['module'], 'delete')) {
            return new Response('Brak uprawnień.', Response::HTTP_FORBIDDEN);
        }

        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        $rawIds = $request->request->all('ids');
        if (!is_array($rawIds)) {
            $rawIds = [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => (int) $value,
            $rawIds
        ), static fn(int $value): bool => $value > 0)));

        if ($ids === []) {
            return new RedirectResponse($this->getModuleListUrl($request, $moduleKey));
        }

        $connection = $this->entityManager->getConnection();
        $deletedCount = $connection->createQueryBuilder()
            ->delete($table)
            ->where($connection->quoteIdentifier($primaryKey) . ' IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();

        foreach ($ids as $id) {
            $this->recordModuleChange($request, $moduleKey, 'delete', $id);
        }

        $message = sprintf('Usunięto %d zaznaczonych elementów.', $deletedCount);
        return $this->buildModuleAlertRedirect($request, $moduleKey, 'success', $message);
    }

    public function extraAction(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if (!$request->isMethod('POST')) {
            return new Response('Niepoprawna metoda.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_EDIT)) {
            return $response;
        }

        $module = $definition['module'];
        $handler = null;

        $actionKey = trim((string) $request->request->get('action_key', ''));
        if ($actionKey !== '') {
            $configuredRecordActions = array_merge(
                $this->normalizeExtraActionsConfig($module['list']['extra_row_actions'] ?? null),
                $this->normalizeExtraActionsConfig($module['view']['actions'] ?? null)
            );
            foreach ($configuredRecordActions as $action) {
                if ($action['key'] !== $actionKey) {
                    continue;
                }

                $handler = $this->resolveExtraActionHandler($moduleKey, $action['handler']);
                break;
            }
        }

        if ($handler === null) {
            $extraAction = $this->normalizeExtraActionConfig($module['list']['extra_action'] ?? null);
            if (!$extraAction || !array_key_exists('handler', $extraAction)) {
                return new Response('Brak konfiguracji akcji.', Response::HTTP_NOT_FOUND);
            }

            $handler = $this->resolveExtraActionHandler($moduleKey, $extraAction['handler']);
            if ($handler === null) {
                return new Response('Nieprawidłowa konfiguracja akcji.', Response::HTTP_BAD_REQUEST);
            }
        }

        $recordId = (string) ($parameters['id'] ?? '');
        if ($recordId === '') {
            return new Response('Brak identyfikatora wpisu.', Response::HTTP_BAD_REQUEST);
        }

        $response = $handler($request, $moduleKey, $recordId, $definition);
        if ($response instanceof Response) {
            return $response;
        }

        return new RedirectResponse($this->getModuleListUrl($request, $moduleKey));
    }

    public function moduleExtraAction(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if (!$request->isMethod('POST')) {
            return new Response('Niepoprawna metoda.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_EDIT)) {
            return $response;
        }

        $module = $definition['module'];
        $actionKey = (string) ($parameters['actionKey'] ?? '');
        if ($actionKey === '') {
            return new Response('Brak identyfikatora akcji.', Response::HTTP_BAD_REQUEST);
        }

        $extraActions = $this->normalizeExtraActionsConfig($module['list']['extra_actions'] ?? null);
        $extraAction = null;
        foreach ($extraActions as $action) {
            if ($action['key'] === $actionKey) {
                $extraAction = $action;
                break;
            }
        }

        if (!$extraAction || !array_key_exists('handler', $extraAction)) {
            return new Response('Brak konfiguracji akcji.', Response::HTTP_NOT_FOUND);
        }

        $handler = $this->resolveExtraActionHandler($moduleKey, $extraAction['handler']);
        if ($handler === null) {
            return new Response('Nieprawidłowa konfiguracja akcji.', Response::HTTP_BAD_REQUEST);
        }

        $response = $handler($request, $moduleKey, '0', $definition);
        if ($response instanceof Response) {
            return $response;
        }

        return new RedirectResponse($this->getModuleListUrl($request, $moduleKey));
    }

    public function reorder(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if (!$request->isMethod('POST')) {
            return new Response('Niepoprawna metoda.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_EDIT)) {
            return $response;
        }

        $module = $definition['module'];
        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';
        $manualSortColumn = $this->getManualSortColumn($module);

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        if (!$manualSortColumn) {
            return new Response('Ręczne sortowanie jest wyłączone.', Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload) || !isset($payload['ids']) || !is_array($payload['ids'])) {
            return new Response('Niepoprawne dane.', Response::HTTP_BAD_REQUEST);
        }

        $ids = array_values(array_unique(array_map('intval', $payload['ids'])));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

        if ($ids === []) {
            return new Response('Niepoprawne dane.', Response::HTTP_BAD_REQUEST);
        }

        $connection = $this->entityManager->getConnection();
        $existingIds = $connection->createQueryBuilder()
            ->select($primaryKey)
            ->from($table)
            ->where($primaryKey . ' IN (:ids)')
            ->setParameter('ids', $ids, Connection::PARAM_INT_ARRAY)
            ->executeQuery()
            ->fetchFirstColumn();

        if (count($existingIds) !== count($ids)) {
            return new Response('Niepoprawne dane.', Response::HTTP_BAD_REQUEST);
        }

        $connection->beginTransaction();
        try {
            foreach ($ids as $index => $id) {
                $connection->update($table, [$manualSortColumn => $index + 1], [$primaryKey => $id]);
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
        $this->recordModuleChange($request, $moduleKey, 'reorder', null);

        return new Response('OK');
    }

    /**
     * @return array{module: array<string, mixed>, database: array<string, mixed>}
     */
    protected function loadModuleDefinition(string $moduleKey): ?array
    {
        if ($moduleKey === '') {
            return null;
        }

        $definitionLoader = new ModuleDefinitionLoader();
        foreach ($definitionLoader->getInstallDefinitions() as $definition) {
            if (($definition['key'] ?? null) !== $moduleKey) {
                continue;
            }

            $module = $definition['module'] ?? null;
            $database = $definition['database'] ?? null;

            if (!is_array($module) || !is_array($database)) {
                return null;
            }

            return [
                'module' => $module,
                'database' => $database,
                'fields' => $definition['fields'] ?? ($module['fields'] ?? []),
            ];
        }

        return null;
    }

    /**
     * @param array<string, scalar|null> $state
     */
    private function storeModuleListState(Request $request, string $moduleKey, array $state): void
    {
        if ($moduleKey === '') {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $sessionKey = self::LIST_STATE_SESSION_PREFIX . $moduleKey;

        $normalizedState = [];
        foreach ($state as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (in_array((string) $key, self::TRANSIENT_LIST_QUERY_KEYS, true)) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '' && $key !== 'list_filters') {
                    continue;
                }
            }

            $normalizedState[$key] = $value;
        }

        if ($normalizedState === []) {
            $session->remove($sessionKey);
            $this->storeModuleListStateInDatabase($moduleKey, []);

            return;
        }

        $session->set($sessionKey, $normalizedState);
        $this->storeModuleListStateInDatabase($moduleKey, $normalizedState);
    }

    private function restoreModuleListState(Request $request, string $moduleKey): void
    {
        if ($moduleKey === '' || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $sessionKey = self::LIST_STATE_SESSION_PREFIX . $moduleKey;

        if ($request->query->getBoolean('reset', false)) {
            $session->remove($sessionKey);
            $this->storeModuleListStateInDatabase($moduleKey, []);
            $request->query->remove('reset');
            $request->query->remove('status');
            $request->query->remove('message');

            return;
        }

        if ($request->query->getBoolean('reset_filters', false)) {
            $state = $this->getModuleListState($request, $moduleKey);
            $listFilters = trim((string) ($state['list_filters'] ?? ''));
            $filterKeys = $listFilters === ''
                ? []
                : array_values(array_unique(array_filter(array_map('trim', explode(',', $listFilters)), static fn(string $key): bool => $key !== '')));

            foreach ($filterKeys as $filterKey) {
                unset($state[$filterKey], $state[$filterKey . '_from'], $state[$filterKey . '_to']);
            }

            foreach (self::TRANSIENT_LIST_QUERY_KEYS as $transientKey) {
                unset($state[$transientKey]);
            }

            unset($state['q'], $state['page']);
            $this->storeModuleListState($request, $moduleKey, $state);

            $request->query->replace($state);

            return;
        }

        $query = $request->query->all();
        $alertQuery = [];
        foreach (['status', 'message'] as $key) {
            if (!array_key_exists($key, $query)) {
                continue;
            }

            $value = $query[$key];
            if (!is_scalar($value)) {
                continue;
            }

            $alertQuery[$key] = (string) $value;
            unset($query[$key]);
        }

        if ($query !== []) {
            return;
        }

        $state = $this->getModuleListState($request, $moduleKey);
        if ($state === []) {
            return;
        }

        foreach (array_merge($state, $alertQuery) as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $request->query->set($key, (string) $value);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function getModuleListState(Request $request, string $moduleKey): array
    {
        if ($moduleKey === '' || !$request->hasSession()) {
            return [];
        }

        $sessionKey = self::LIST_STATE_SESSION_PREFIX . $moduleKey;
        $state = $request->getSession()->get($sessionKey, []);
        if (!is_array($state) || $state === []) {
            $state = $this->fetchModuleListStateFromDatabase($moduleKey);
        }

        if (!is_array($state) || $state === []) {
            return [];
        }

        $sanitizedState = $this->sanitizeModuleListState($state);
        if ($sanitizedState !== $state) {
            $this->storeModuleListState($request, $moduleKey, $sanitizedState);

            return $sanitizedState;
        }

        $request->getSession()->set($sessionKey, $sanitizedState);

        return $sanitizedState;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, scalar|null>
     */
    private function sanitizeModuleListState(array $state): array
    {
        $sanitizedState = [];
        foreach ($state as $key => $value) {
            if (!is_string($key) || in_array($key, self::TRANSIENT_LIST_QUERY_KEYS, true)) {
                continue;
            }

            if ($value !== null && !is_scalar($value)) {
                continue;
            }

            $sanitizedState[$key] = $value;
        }

        return $sanitizedState;
    }

    /**
     * @param array<string, scalar|null> $state
     */
    private function storeModuleListStateInDatabase(string $moduleKey, array $state): void
    {
        if ($moduleKey === '') {
            return;
        }

        $user = $this->authService->user();
        if (!$user) {
            return;
        }

        $repository = $this->entityManager->getRepository(ModuleListPreference::class);
        $preference = $repository->findOneBy(['user' => $user, 'moduleKey' => $moduleKey]);

        if ($state === []) {
            if ($preference) {
                $this->entityManager->remove($preference);
                $this->entityManager->flush();
            }

            return;
        }

        if (!$preference) {
            $preference = new ModuleListPreference();
            $preference->setUser($user);
            $preference->setModuleKey($moduleKey);
            $this->entityManager->persist($preference);
        }

        $preference->setState($state);
        $this->entityManager->flush();
    }

    /**
     * @return array<string, scalar|null>
     */
    private function fetchModuleListStateFromDatabase(string $moduleKey): array
    {
        if ($moduleKey === '') {
            return [];
        }

        $user = $this->authService->user();
        if (!$user) {
            return [];
        }

        $preference = $this->entityManager->getRepository(ModuleListPreference::class)
            ->findOneBy(['user' => $user, 'moduleKey' => $moduleKey]);

        if (!$preference) {
            return [];
        }

        $state = $preference->getState();

        return is_array($state) ? $state : [];
    }

    protected function getModuleListUrl(Request $request, string $moduleKey): string
    {
        if ($moduleKey === '') {
            return '/cms/modules';
        }

        $basePath = '/cms/modules/' . $moduleKey;
        if (!$request->hasSession()) {
            return $basePath;
        }

        $state = $this->getModuleListState($request, $moduleKey);
        if ($state === []) {
            return $basePath;
        }

        $query = http_build_query($state);

        return $query !== '' ? $basePath . '?' . $query : $basePath;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFields(array $definition): array
    {
        $fields = $definition['fields'] ?? [];
        if (!is_array($fields)) {
            return [];
        }

        return array_values(array_filter(
            $fields,
            static fn($field): bool => is_array($field) && !self::isFieldHidden($field)
        ));
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function isFieldHidden(array $field): bool
    {
        return ($field['hidden'] ?? false) === true;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param mixed $configuredFields
     * @return array<int, array<string, mixed>>
     */
    private function buildConfiguredViewFields(array $fields, mixed $configuredFields, bool $fallbackToAllFields = true): array
    {
        if (!is_array($configuredFields) || $configuredFields === []) {
            return $fallbackToAllFields ? $fields : [];
        }

        $fieldsByName = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $fieldsByName[$name] = $field;
            }
        }

        $viewFields = [];
        foreach ($configuredFields as $configuredField) {
            $fieldName = null;
            $overrides = [];
            if (is_string($configuredField)) {
                $fieldName = $configuredField;
            } elseif (is_array($configuredField)) {
                $fieldName = isset($configuredField['name']) ? trim((string) $configuredField['name']) : '';
                $overrides = $configuredField;
            }

            if (!is_string($fieldName) || $fieldName === '' || !isset($fieldsByName[$fieldName])) {
                continue;
            }

            $viewFields[] = array_merge($fieldsByName[$fieldName], $overrides);
        }

        return $viewFields !== [] ? $viewFields : ($fallbackToAllFields ? $fields : []);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array{values: array<string, mixed>, errors: array<int, string>}
     */
    private function extractFieldValues(array $fields, Request $request, string $moduleKey): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            $includeInForm = $field['form'] ?? true;
            if (!$name || $includeInForm === false) {
                continue;
            }

            $type = strtolower((string) ($field['type'] ?? 'text'));
            if (in_array($type, ['info', 'related_field', 'relation_field'], true) || (bool) ($field['readonly'] ?? false)) {
                continue;
            }

            if (in_array($type, ['image', 'file', 'video'], true)) {
                $removeRequested = $request->request->getBoolean($name . '_remove');
                if ($removeRequested) {
                    $values[$name] = null;
                    continue;
                }

                if ($type === 'video') {
                    $existingPath = trim((string) $request->request->get($name, ''));
                    if ($existingPath !== '') {
                        $values[$name] = $existingPath;
                        continue;
                    }
                }

                $uploadedFile = $request->files->get($name);
                if (!$uploadedFile instanceof UploadedFile) {
                    continue;
                }

                if (!$uploadedFile->isValid()) {
                    $errors[] = sprintf('Nie udało się przesłać pliku dla pola "%s".', $field['label'] ?? $name);
                    continue;
                }

                if (!$this->isUploadAllowed($uploadedFile, $field)) {
                    $errors[] = sprintf('Nieprawidłowy typ pliku dla pola "%s".', $field['label'] ?? $name);
                    continue;
                }

                $storedPath = $this->storeUploadedFile($uploadedFile, $moduleKey);
                if ($storedPath === null) {
                    $errors[] = sprintf('Nie udało się zapisać pliku dla pola "%s".', $field['label'] ?? $name);
                    continue;
                }

                $values[$name] = $storedPath;
                $originalNameField = trim((string) ($field['original_name_field'] ?? ''));
                if ($originalNameField !== '') {
                    $values[$originalNameField] = $uploadedFile->getClientOriginalName();
                }
                continue;
            }

            if ($type === 'relation') {
                $isMultiple = (bool) ($field['multiple'] ?? false);
                if ($isMultiple) {
                    $rawValues = $request->request->all($name);
                    if (!is_array($rawValues)) {
                        $rawValues = $request->request->get($name, []);
                        if (!is_array($rawValues)) {
                            $rawValues = [$rawValues];
                        }
                    }
                    $normalized = array_values(array_filter(array_map(
                        static fn($value): string => trim((string) $value),
                        $rawValues
                    ), static fn(string $value): bool => $value !== ''));
                    $values[$name] = $normalized === [] ? null : json_encode($normalized);
                } else {
                    $value = trim((string) $request->request->get($name, ''));
                    $values[$name] = $value === '' ? null : $value;
                }
                continue;
            }

            if ($type === 'link_list') {
                $rawValue = $request->request->get($name, '');
                if (!is_string($rawValue)) {
                    $values[$name] = null;
                    continue;
                }

                $decoded = json_decode($rawValue, true);
                if (!is_array($decoded)) {
                    $values[$name] = null;
                    continue;
                }

                $normalizedLinks = [];
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $linkName = trim((string) ($item['nazwa'] ?? ''));
                    $fileId = trim((string) ($item['file_id'] ?? ''));
                    if ($linkName === '' || $fileId === '') {
                        continue;
                    }

                    $normalizedLinks[] = [
                        'nazwa' => $linkName,
                        'file_id' => $fileId,
                    ];
                }

                $values[$name] = $normalizedLinks === [] ? null : json_encode($normalizedLinks);
                continue;
            }

            if ($type === 'structured_list') {
                $rawValue = $request->request->get($name, '');
                if (!is_string($rawValue)) {
                    $values[$name] = null;
                    continue;
                }

                $decoded = json_decode($rawValue, true);
                if (!is_array($decoded)) {
                    $values[$name] = null;
                    continue;
                }

                $nestedFields = is_array($field['fields'] ?? null) ? $field['fields'] : [];
                $normalizedItems = [];
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $normalizedItem = [];
                    $hasValue = false;

                    foreach ($nestedFields as $nestedField) {
                        if (!is_array($nestedField)) {
                            continue;
                        }

                        $nestedName = trim((string) ($nestedField['name'] ?? ''));
                        if ($nestedName === '') {
                            continue;
                        }

                        $nestedValue = trim((string) ($item[$nestedName] ?? ''));
                        if ($nestedValue !== '') {
                            $hasValue = true;
                        }

                        $normalizedItem[$nestedName] = $nestedValue;
                    }

                    if ($hasValue && $normalizedItem !== []) {
                        $normalizedItems[] = $normalizedItem;
                    }
                }

                $values[$name] = $normalizedItems === [] ? null : json_encode($normalizedItems);
                continue;
            }

            if ($type === 'select' && ($field['multiple'] ?? false)) {
                $rawValues = $request->request->all($name);
                if (!is_array($rawValues)) {
                    $rawValues = $request->request->get($name, []);
                    if (!is_array($rawValues)) {
                        $rawValues = [$rawValues];
                    }
                }
                $normalized = array_values(array_filter(array_map(
                    static fn($value): string => trim((string) $value),
                    $rawValues
                ), static fn(string $value): bool => $value !== ''));
                $values[$name] = $normalized === [] ? null : json_encode($normalized);
                continue;
            }

            if (in_array($type, ['date', 'datetime'], true)) {
                $value = trim((string) $request->request->get($name, ''));
                $values[$name] = $value === '' ? null : $value;
                continue;
            }

            if (in_array($type, ['number', 'integer', 'float', 'decimal'], true)) {
                if ($moduleKey === 'loteria_ustawienia'
                    && in_array($name, ['promoted_product_entry_count', 'other_product_entry_count', 'amount_per_ticket', 'max_tickets_per_receipt'], true)
                    && !$request->request->has($name)
                ) {
                    continue;
                }
                $value = str_replace(',', '.', trim((string) $request->request->get($name, '')));
                if ($value === '') {
                    $values[$name] = null;
                    continue;
                }

                $isIntegerField = in_array($type, ['number', 'integer'], true);
                $isValidNumber = $isIntegerField
                    ? preg_match('/^-?\d+$/', $value) === 1
                    : is_numeric($value);

                if (!$isValidNumber) {
                    $errors[] = sprintf('Pole "%s" musi zawierać prawidłową liczbę.', $field['label'] ?? $name);
                    continue;
                }

                if ($moduleKey === 'loteria_ustawienia'
                    && in_array($name, ['daily_ticket_limit', 'daily_scratchcard_limit', 'daily_product_limit', 'promoted_product_entry_count', 'other_product_entry_count'], true)
                    && ((float) $value < 0 || (float) $value > 2147483647)
                ) {
                    $errors[] = sprintf('Pole "%s" musi zawierać liczbę całkowitą od 0 do 2147483647.', $field['label'] ?? $name);
                    continue;
                }
                if ($moduleKey === 'loteria_zgloszenia'
                    && in_array($name, ['promoted_products_count', 'other_products_count'], true)
                    && ((float) $value < 0 || (float) $value > 2147483647)
                ) {
                    $errors[] = sprintf('Pole "%s" musi zawierać nieujemną liczbę całkowitą.', $field['label'] ?? $name);
                    continue;
                }

                $values[$name] = $value;
                continue;
            }

            if (($field['numeric_only'] ?? false) === true) {
                $value = trim((string) $request->request->get($name, ''));
                if ($value !== '' && !preg_match('/^\d+$/', $value)) {
                    $errors[] = sprintf('Pole "%s" może zawierać wyłącznie cyfry.', $field['label'] ?? $name);
                    continue;
                }
                $values[$name] = $value;
                continue;
            }

            $values[$name] = match ($type) {
                'checkbox', 'boolean', 'boolean_select' => $request->request->getBoolean($name),
                default => trim((string) $request->request->get($name, '')),
            };
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function hydrateRelationFields(array $fields): array
    {
        $relationModules = [];

        foreach ($fields as $field) {
            $type = strtolower((string) ($field['type'] ?? 'text'));
            $moduleKey = $field['module'] ?? null;
            $isRemote = (bool) ($field['remote'] ?? false);
            if (in_array($type, ['relation', 'link_list'], true) && is_string($moduleKey) && $moduleKey !== '' && !$isRemote) {
                $relationModules[] = $moduleKey;
            }
        }

        if ($relationModules === []) {
            return $fields;
        }

        $relationModules = array_values(array_unique($relationModules));
        $definitions = [];
        $definitionLoader = new ModuleDefinitionLoader();
        foreach ($definitionLoader->getInstallDefinitions() as $definition) {
            $key = $definition['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $definitions[$key] = $definition;
            }
        }

        $connection = $this->entityManager->getConnection();

        foreach ($fields as $index => $field) {
            $type = strtolower((string) ($field['type'] ?? 'text'));
            $moduleKey = $field['module'] ?? null;
            if (!in_array($type, ['relation', 'link_list'], true) || !is_string($moduleKey) || $moduleKey === '' || ($field['remote'] ?? false)) {
                continue;
            }

            $definition = $definitions[$moduleKey] ?? null;
            if (!is_array($definition)) {
                continue;
            }

            $database = $definition['database'] ?? [];
            if (!is_array($database)) {
                continue;
            }

            $table = $database['table'] ?? null;
            if (!is_string($table) || $table === '') {
                continue;
            }

            $valueField = $field['value_field'] ?? ($database['primary_key'] ?? 'id');
            $labelField = $field['label_field'] ?? 'name';
            if (!is_string($valueField) || $valueField === '' || !is_string($labelField) || $labelField === '') {
                continue;
            }

            $dependsOnField = $field['depends_field'] ?? null;
            if (!is_string($dependsOnField) || $dependsOnField === '') {
                $dependsOnField = null;
            }

            $selectFields = $this->getRelationSelectFields($field, [
                'value_field' => $valueField,
                'label_field' => $labelField,
            ]);
            if ($dependsOnField !== null && !in_array($dependsOnField, $selectFields, true)) {
                $selectFields[] = $dependsOnField;
            }

            $relationOrderField = $this->resolveRelationOrderField($field, $definition);
            $relationOrderDirection = $this->resolveRelationOrderDirection($field);

            $queryBuilder = $connection->createQueryBuilder()
                ->select(...$selectFields)
                ->from($table);

            if (is_string($relationOrderField) && $relationOrderField !== '') {
                $queryBuilder->addOrderBy($relationOrderField, $relationOrderDirection);
            }

            $rows = $queryBuilder
                ->addOrderBy($labelField, 'asc')
                ->executeQuery()
                ->fetchAllAssociative();

            $options = [];
            $optionMeta = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !array_key_exists($valueField, $row)) {
                    continue;
                }
                $optionValue = (string) $row[$valueField];
                if ($optionValue === '') {
                    continue;
                }
                $options[$optionValue] = $this->buildRelationLabel($field, $row, [
                    'value_field' => $valueField,
                    'label_field' => $labelField,
                ]);
                if ($dependsOnField !== null) {
                    $optionMeta[$optionValue] = [
                        'depends_value' => isset($row[$dependsOnField]) ? (string) $row[$dependsOnField] : '',
                    ];
                }
            }

            $field['options'] = $options;
            $extraOptions = $this->normalizeConfiguredOptions($field['extra_options'] ?? []);
            if ($extraOptions !== []) {
                $primaryGroupLabel = trim((string) ($field['options_group_label'] ?? 'Opcje'));
                if ($primaryGroupLabel === '') {
                    $primaryGroupLabel = 'Opcje';
                }
                $extraGroupLabel = trim((string) ($field['extra_options_group_label'] ?? 'Pozostałe'));
                if ($extraGroupLabel === '') {
                    $extraGroupLabel = 'Pozostałe';
                }
                $field['option_groups'] = [
                    $primaryGroupLabel => $options,
                    $extraGroupLabel => $extraOptions,
                ];
                $field['options'] = $options + $extraOptions;
            }
            if ($optionMeta !== []) {
                $field['option_meta'] = $optionMeta;
            }
            $fields[$index] = $field;
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function applyWinnerDrawingAvailability(array $fields, Connection $connection): array
    {
        $drawings = (new WinnerDrawingAvailabilityService())->fetchDrawingOptions($connection);

        foreach ($fields as $index => $field) {
            if (
                trim((string) ($field['name'] ?? '')) !== 'losowanie'
                || trim((string) ($field['module'] ?? '')) !== 'losowania'
            ) {
                continue;
            }

            $options = [];
            $optionMeta = is_array($field['option_meta'] ?? null) ? $field['option_meta'] : [];
            foreach ($drawings as $drawing) {
                $drawingId = (string) $drawing['id'];
                $drawingLabel = trim((string) ($drawing['name'] ?? ''));
                if ($drawingLabel === '') {
                    $drawingLabel = $drawingId;
                }
                $drawingDate = trim((string) ($drawing['drawing_date'] ?? ''));
                if ($drawingDate !== '') {
                    $drawingLabel .= ' — ' . $drawingDate;
                }

                $options[$drawingId] = $this->formatRelationLabel($field, $drawingLabel);
                $optionMeta[$drawingId] = array_merge(
                    is_array($optionMeta[$drawingId] ?? null) ? $optionMeta[$drawingId] : [],
                    ['disabled' => !($drawing['selectable'] ?? false)]
                );
            }

            $field['options'] = $options;
            $field['option_meta'] = $optionMeta;
            $fields[$index] = $field;
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<int, array<string, mixed>>
     */
    private function hydrateRemoteRelationFields(array $fields, array $values): array
    {
        foreach ($fields as $index => $field) {
            $type = strtolower((string) ($field['type'] ?? 'text'));
            $isRemote = (bool) ($field['remote'] ?? false);
            $name = (string) ($field['name'] ?? '');

            if ($type !== 'relation' || !$isRemote || $name === '') {
                continue;
            }

            $selected = $values[$name] ?? null;
            if ($selected === null || $selected === '') {
                continue;
            }

            $ids = [];
            if ($field['multiple'] ?? false) {
                if (is_array($selected)) {
                    $ids = array_values(array_filter(array_map('strval', $selected), static fn(string $value): bool => $value !== ''));
                } else {
                    $decoded = json_decode((string) $selected, true);
                    if (is_array($decoded)) {
                        $ids = array_values(array_filter(array_map('strval', $decoded), static fn(string $value): bool => $value !== ''));
                    } else {
                        $ids = [(string) $selected];
                    }
                }
            } else {
                $ids = [(string) $selected];
            }

            $ids = array_values(array_unique(array_filter($ids, static fn(string $value): bool => $value !== '')));
            if ($ids === []) {
                continue;
            }

            $labels = $this->fetchRelationLabels($field, $ids);
            if ($labels !== []) {
                $field['selected_options'] = $labels;
                $fields[$index] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeRelationFormValues(array $fields, array $values): array
    {
        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $fieldType = strtolower((string) ($field['type'] ?? ''));
            if ($fieldType === 'relation' && ($field['multiple'] ?? false)) {
                $rawValue = $values[$name] ?? null;
                if (is_string($rawValue) && $rawValue !== '') {
                    $decoded = json_decode($rawValue, true);
                    if (is_array($decoded)) {
                        $values[$name] = array_values(array_filter(array_map(
                            static fn($value): string => trim((string) $value),
                            $decoded
                        ), static fn(string $value): bool => $value !== ''));
                    } else {
                        $values[$name] = [trim($rawValue)];
                    }
                }
                continue;
            }

            if ($fieldType === 'select' && ($field['multiple'] ?? false)) {
                $rawValue = $values[$name] ?? null;
                if (is_string($rawValue) && $rawValue !== '') {
                    $decoded = json_decode($rawValue, true);
                    if (is_array($decoded)) {
                        $values[$name] = array_values(array_filter(array_map(
                            static fn($value): string => trim((string) $value),
                            $decoded
                        ), static fn(string $value): bool => $value !== ''));
                    }
                }
                continue;
            }

            if ($fieldType === 'link_list') {
                $rawValue = $values[$name] ?? null;
                if (is_string($rawValue) && $rawValue !== '') {
                    $decoded = json_decode($rawValue, true);
                    if (is_array($decoded)) {
                        $normalizedLinks = [];
                        foreach ($decoded as $item) {
                            if (!is_array($item)) {
                                continue;
                            }

                            $linkName = trim((string) ($item['nazwa'] ?? ''));
                            $fileId = trim((string) ($item['file_id'] ?? ''));
                            if ($linkName === '' || $fileId === '') {
                                continue;
                            }

                            $normalizedLinks[] = [
                                'nazwa' => $linkName,
                                'file_id' => $fileId,
                            ];
                        }

                        $values[$name] = $normalizedLinks;
                    }
                }
            }

            if ($fieldType === 'structured_list') {
                $rawValue = $values[$name] ?? null;
                if (is_string($rawValue) && $rawValue !== '') {
                    $decoded = json_decode($rawValue, true);
                    if (is_array($decoded)) {
                        $nestedFields = is_array($field['fields'] ?? null) ? $field['fields'] : [];
                        $normalizedItems = [];
                        foreach ($decoded as $item) {
                            if (!is_array($item)) {
                                continue;
                            }

                            $normalizedItem = [];
                            $hasValue = false;
                            foreach ($nestedFields as $nestedField) {
                                if (!is_array($nestedField)) {
                                    continue;
                                }

                                $nestedName = trim((string) ($nestedField['name'] ?? ''));
                                if ($nestedName === '') {
                                    continue;
                                }

                                $nestedValue = trim((string) ($item[$nestedName] ?? ''));
                                if ($nestedValue !== '') {
                                    $hasValue = true;
                                }
                                $normalizedItem[$nestedName] = $nestedValue;
                            }

                            if ($hasValue && $normalizedItem !== []) {
                                $normalizedItems[] = $normalizedItem;
                            }
                        }

                        $values[$name] = $normalizedItems;
                    }
                }
            }
        }

        return $values;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function hydrateRelatedFieldValues(array $fields, array $values): array
    {
        $rows = $this->hydrateRelatedFieldRows($fields, [$values]);

        return $rows[0] ?? $values;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<int, string>
     */
    private function updateEditableRelatedFieldValues(array $fields, Request $request, array $values): array
    {
        $errors = [];
        $connection = $this->entityManager->getConnection();

        foreach ($fields as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            $type = strtolower((string) ($field['type'] ?? 'text'));

            if ($name === ''
                || !in_array($type, ['related_field', 'relation_field'], true)
                || !($field['editable'] ?? false)
                || (bool) ($field['readonly'] ?? false)
                || !$request->request->has($name)
            ) {
                continue;
            }

            $config = $this->resolveRelatedFieldConfig($field, $fields);
            if ($config === null || !$this->isSafeRelatedFieldConfig($config)) {
                $errors[] = sprintf('Nie można zapisać pola powiązanego "%s".', $field['label'] ?? $name);
                continue;
            }

            $relationValue = $values[$config['relation_name']] ?? null;
            if ($relationValue === null || $relationValue === '') {
                $errors[] = sprintf('Nie można zapisać pola "%s" bez wybranego powiązanego wpisu.', $field['label'] ?? $name);
                continue;
            }

            $newValue = $request->request->get($name);
            if (is_array($newValue)) {
                $newValue = reset($newValue);
            }
            $newValue = trim((string) $newValue);

            $displayType = strtolower((string) ($field['display_type'] ?? 'text'));
            if ($displayType === 'select') {
                $options = $field['options'] ?? [];
                if ($newValue !== '' && is_array($options) && !array_key_exists($newValue, $options)) {
                    $errors[] = sprintf('Wybrano nieprawidłową wartość dla pola "%s".', $field['label'] ?? $name);
                    continue;
                }
            }

            $connection->update(
                $config['table'],
                [$config['target_field'] => $newValue],
                [$config['value_field'] => (string) $relationValue]
            );
        }

        return $errors;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateRelatedFieldRows(array $fields, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $relatedFields = [];
        foreach ($fields as $field) {
            if (!in_array(strtolower((string) ($field['type'] ?? 'text')), ['related_field', 'relation_field'], true)) {
                continue;
            }

            $config = $this->resolveRelatedFieldConfig($field, $fields);
            if ($config === null) {
                continue;
            }

            $relatedFields[] = [$field, $config];
        }

        if ($relatedFields === []) {
            return $rows;
        }

        $connection = $this->entityManager->getConnection();
        foreach ($relatedFields as [$field, $config]) {
            $fieldName = (string) $field['name'];
            $relationName = $config['relation_name'];
            $ids = [];

            foreach ($rows as $row) {
                $relationValue = $row[$relationName] ?? null;
                if ($relationValue === null || $relationValue === '') {
                    continue;
                }

                $ids[] = (string) $relationValue;
            }

            $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
            if ($ids === []) {
                continue;
            }

            $relatedRows = $connection->createQueryBuilder()
                ->select($config['value_field'], $config['target_field'])
                ->from($config['table'])
                ->where($config['value_field'] . ' IN (:related_field_ids)')
                ->setParameter('related_field_ids', $ids, ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchAllAssociative();

            $valuesById = [];
            foreach ($relatedRows as $relatedRow) {
                if (!is_array($relatedRow) || !array_key_exists($config['value_field'], $relatedRow)) {
                    continue;
                }

                $valuesById[(string) $relatedRow[$config['value_field']]] = $relatedRow[$config['target_field']] ?? null;
            }

            foreach ($rows as $rowIndex => $row) {
                $relationValue = $row[$relationName] ?? null;
                if ($relationValue === null || $relationValue === '') {
                    $rows[$rowIndex][$fieldName] = null;
                    continue;
                }

                $rows[$rowIndex][$fieldName] = $valuesById[(string) $relationValue] ?? null;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<int, array<string, mixed>> $fields
     * @return array{relation_name: string, table: string, value_field: string, target_field: string}|null
     */
    private function resolveRelatedFieldConfig(array $field, array $fields): ?array
    {
        $fieldName = trim((string) ($field['name'] ?? ''));
        $relationName = trim((string) ($field['relation'] ?? $field['source_relation'] ?? ''));
        $targetField = trim((string) ($field['field'] ?? $field['source_field'] ?? ''));

        if ($fieldName === '' || $relationName === '' || $targetField === '') {
            return null;
        }

        $relationField = null;
        foreach ($fields as $candidate) {
            if (trim((string) ($candidate['name'] ?? '')) === $relationName) {
                $relationField = $candidate;
                break;
            }
        }

        $moduleKey = trim((string) ($field['module'] ?? ($relationField['module'] ?? '')));
        if ($moduleKey === '') {
            return null;
        }

        $definition = $this->resolveModuleDefinition($moduleKey);
        if (!is_array($definition)) {
            return null;
        }

        $database = $definition['database'] ?? null;
        if (!is_array($database)) {
            return null;
        }

        $table = trim((string) ($database['table'] ?? ''));
        $valueField = trim((string) ($field['value_field'] ?? ($relationField['value_field'] ?? ($database['primary_key'] ?? 'id'))));

        if ($table === '' || $valueField === '') {
            return null;
        }

        return [
            'relation_name' => $relationName,
            'table' => $table,
            'value_field' => $valueField,
            'target_field' => $targetField,
        ];
    }


    /** @param array<string, mixed> $field */
    private function isRelatedField(array $field): bool
    {
        return in_array(strtolower((string) ($field['type'] ?? 'text')), ['related_field', 'relation_field'], true);
    }

    /** @param array<string, mixed> $field */
    private function resolveFilterType(array $field): string
    {
        if (!$this->isRelatedField($field)) {
            return strtolower((string) ($field['type'] ?? 'text'));
        }

        return strtolower((string) ($field['display_type'] ?? 'text'));
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, string> $columns
     * @return array<string, string>
     */
    private function buildRelatedFieldSortExpressions(array $fields, array $columns): array
    {
        $expressions = [];
        $columnMap = array_fill_keys($columns, true);

        foreach ($fields as $field) {
            $fieldName = trim((string) ($field['name'] ?? ''));
            if ($fieldName === '' || !isset($columnMap[$fieldName]) || !$this->isRelatedField($field)) {
                continue;
            }

            $config = $this->resolveRelatedFieldConfig($field, $fields);
            if ($config === null || !$this->isSafeRelatedFieldConfig($config)) {
                continue;
            }

            $expressions[$fieldName] = sprintf(
                '(SELECT %s FROM %s WHERE %s = %s LIMIT 1)',
                $config['target_field'],
                $config['table'],
                $config['value_field'],
                $config['relation_name']
            );
        }

        return $expressions;
    }

    /** @param array<string, mixed> $filterConfig */
    private function applyDirectModuleFilter(
        \Doctrine\DBAL\Query\QueryBuilder $queryBuilder,
        string $filterName,
        mixed $filterValue,
        array $filterConfig
    ): void
    {
        if (is_array($filterValue) && ($filterValue['range'] ?? false) === true) {
            $fromValue = trim((string) ($filterValue['from'] ?? ''));
            $toValue = trim((string) ($filterValue['to'] ?? ''));
            if ($fromValue !== '') {
                $queryBuilder->andWhere($queryBuilder->expr()->gte($filterName, ':' . $filterName . '_from_filter'));
                $queryBuilder->setParameter($filterName . '_from_filter', $fromValue);
            }
            if ($toValue !== '') {
                $queryBuilder->andWhere($queryBuilder->expr()->lte($filterName, ':' . $filterName . '_to_filter'));
                $queryBuilder->setParameter($filterName . '_to_filter', $toValue);
            }

            return;
        }

        $filterType = strtolower((string) ($filterConfig['type'] ?? ''));
        if (is_array($filterValue)) {
            $condition = $queryBuilder->expr()->in($filterName, ':' . $filterName . '_filter');
            if ($this->isBooleanFilterType($filterType) && $this->booleanFilterIncludesFalse($filterValue)) {
                $condition = sprintf('(%s OR %s IS NULL)', $condition, $filterName);
            }
            $queryBuilder->andWhere($condition);
            $queryBuilder->setParameter($filterName . '_filter', $filterValue, ArrayParameterType::STRING);

            return;
        }

        $condition = $queryBuilder->expr()->eq($filterName, ':' . $filterName . '_filter');
        if ($this->isBooleanFilterType($filterType) && $this->isFalseBooleanFilterValue($filterValue)) {
            $condition = sprintf('(%s OR %s IS NULL)', $condition, $filterName);
        }
        $queryBuilder->andWhere($condition);
        $queryBuilder->setParameter($filterName . '_filter', $filterValue);
    }

    private function isBooleanFilterType(string $filterType): bool
    {
        return in_array(strtolower($filterType), ['boolean', 'boolean_select'], true);
    }

    private function isFalseBooleanFilterValue(mixed $value): bool
    {
        if ($value === false || $value === 0) {
            return true;
        }

        if (!is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'nie', 'off'], true);
    }

    /** @param array<int|string, mixed> $values */
    private function booleanFilterIncludesFalse(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->isFalseBooleanFilterValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $field
     */
    private function applyRelatedFieldFilter(\Doctrine\DBAL\Query\QueryBuilder $queryBuilder, array $fields, array $field, mixed $filterValue, string $parameterPrefix): void
    {
        $config = $this->resolveRelatedFieldConfig($field, $fields);
        if ($config === null || !$this->isSafeRelatedFieldConfig($config)) {
            return;
        }

        $subquery = $this->entityManager->getConnection()->createQueryBuilder()
            ->select($config['value_field'])
            ->from($config['table']);

        if (is_array($filterValue) && ($filterValue['range'] ?? false) === true) {
            $fromValue = trim((string) ($filterValue['from'] ?? ''));
            $toValue = trim((string) ($filterValue['to'] ?? ''));
            if ($fromValue !== '') {
                $subquery->andWhere($subquery->expr()->gte($config['target_field'], ':' . $parameterPrefix . '_from_filter'));
                $queryBuilder->setParameter($parameterPrefix . '_from_filter', $fromValue);
            }
            if ($toValue !== '') {
                $subquery->andWhere($subquery->expr()->lte($config['target_field'], ':' . $parameterPrefix . '_to_filter'));
                $queryBuilder->setParameter($parameterPrefix . '_to_filter', $toValue);
            }

            if ($fromValue === '' && $toValue === '') {
                return;
            }
        } elseif (is_array($filterValue)) {
            $condition = $subquery->expr()->in($config['target_field'], ':' . $parameterPrefix . '_filter');
            if ($this->isBooleanFilterType($this->resolveFilterType($field)) && $this->booleanFilterIncludesFalse($filterValue)) {
                $condition = sprintf('(%s OR %s IS NULL)', $condition, $config['target_field']);
            }
            $subquery->andWhere($condition);
            $queryBuilder->setParameter($parameterPrefix . '_filter', $filterValue, ArrayParameterType::STRING);
        } else {
            $condition = $subquery->expr()->eq($config['target_field'], ':' . $parameterPrefix . '_filter');
            if ($this->isBooleanFilterType($this->resolveFilterType($field)) && $this->isFalseBooleanFilterValue($filterValue)) {
                $condition = sprintf('(%s OR %s IS NULL)', $condition, $config['target_field']);
            }
            $subquery->andWhere($condition);
            $queryBuilder->setParameter($parameterPrefix . '_filter', $filterValue);
        }

        $queryBuilder->andWhere(sprintf('%s IN (%s)', $config['relation_name'], $subquery->getSQL()));
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $field
     * @param array<string, mixed> $filterConfig
     * @return array<string, string>
     */
    private function fetchDistinctRelatedFieldFilterOptions(Connection $connection, array $fields, array $field, array $filterConfig): array
    {
        $filterType = (string) ($filterConfig['type'] ?? '');
        if (in_array($filterType, ['date', 'datetime'], true)) {
            return [];
        }
        if ($this->isBooleanFilterType($filterType)) {
            return ['0' => 'Nie', '1' => 'Tak'];
        }

        $config = $this->resolveRelatedFieldConfig($field, $fields);
        if ($config === null || !$this->isSafeRelatedFieldConfig($config)) {
            return [];
        }

        $rows = $connection->createQueryBuilder()
            ->select('DISTINCT ' . $config['target_field'] . ' AS value')
            ->from($config['table'])
            ->where($config['target_field'] . ' IS NOT NULL')
            ->andWhere($config['target_field'] . " <> ''")
            ->orderBy($config['target_field'], 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $options = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value !== '') {
                $options[$value] = $value;
            }
        }

        return $options;
    }

    /** @param array{relation_name: string, table: string, value_field: string, target_field: string} $config */
    private function isSafeRelatedFieldConfig(array $config): bool
    {
        return $this->isSafeSqlIdentifier($config['relation_name'])
            && $this->isSafeSqlIdentifier($config['table'])
            && $this->isSafeSqlIdentifier($config['value_field'])
            && $this->isSafeSqlIdentifier($config['target_field']);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function filterStoredFieldColumns(array $fields, array $columns): array
    {
        $virtualColumns = [];
        foreach ($fields as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (in_array(strtolower((string) ($field['type'] ?? 'text')), ['related_field', 'relation_field'], true)) {
                $virtualColumns[$name] = true;
            }
        }

        return array_values(array_filter(array_unique($columns), static fn(string $column): bool => !isset($virtualColumns[$column])));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function hydratePlainViewRelationValues(array $values, array $fields): array
    {
        $relationFieldMap = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if (
                $name !== ''
                && strtolower((string) ($field['type'] ?? '')) === 'relation'
                && isset($field['options'])
                && is_array($field['options'])
            ) {
                $relationFieldMap[$name] = [
                    'options' => $field['options'],
                    'multiple' => (bool) ($field['multiple'] ?? false),
                ];
            }
        }

        $rows = [$values];
        $rows = $this->hydrateRelationRows($rows, $relationFieldMap);
        $rows = $this->hydrateRemoteRelationRows($rows, $fields);

        return is_array($rows[0] ?? null) ? $rows[0] : $values;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array{options: array<string, string>, multiple: bool}> $relationFieldMap
     * @return array<int, array<string, mixed>>
     */
    private function hydrateRelationRows(array $rows, array $relationFieldMap): array
    {
        if ($relationFieldMap === []) {
            return $rows;
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($relationFieldMap as $fieldName => $relationConfig) {
                if (!array_key_exists($fieldName, $row)) {
                    continue;
                }

                $value = $row[$fieldName];
                if ($value === null || $value === '') {
                    continue;
                }

                $options = $relationConfig['options'];
                if ($relationConfig['multiple']) {
                    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $labels = [];
                    foreach ($decoded as $item) {
                        $key = (string) $item;
                        if ($key === '') {
                            continue;
                        }
                        $labels[] = $options[$key] ?? $key;
                    }
                    $rows[$rowIndex][$fieldName] = $labels !== [] ? implode(', ', $labels) : null;
                } else {
                    $key = (string) $value;
                    if ($key !== '') {
                        $rows[$rowIndex][$fieldName] = $options[$key] ?? $key;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<string, string>> $selectFieldOptions
     * @return array<int, array<string, mixed>>
     */
    private function hydrateSelectRows(array $rows, array $selectFieldOptions): array
    {
        if ($selectFieldOptions === []) {
            return $rows;
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($selectFieldOptions as $fieldName => $options) {
                if (!array_key_exists($fieldName, $row)) {
                    continue;
                }

                $value = $row[$fieldName];
                if ($value === null || $value === '') {
                    continue;
                }

                if (str_starts_with(ltrim((string) $value), '[')) {
                    $decoded = json_decode((string) $value, true);
                    if (is_array($decoded)) {
                        $labels = [];
                        foreach ($decoded as $item) {
                            $key = (string) $item;
                            if ($key === '') {
                                continue;
                            }
                            $labels[] = $options[$key] ?? $key;
                        }
                        $rows[$rowIndex][$fieldName] = $labels !== [] ? implode(', ', $labels) : null;
                        continue;
                    }
                }

                $key = (string) $value;
                $rows[$rowIndex][$fieldName] = $options[$key] ?? $key;
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function hydrateRemoteRelationRows(array $rows, array $fields): array
    {
        if ($rows === []) {
            return $rows;
        }

        foreach ($fields as $field) {
            $type = strtolower((string) ($field['type'] ?? 'text'));
            $isRemote = (bool) ($field['remote'] ?? false);
            $name = (string) ($field['name'] ?? '');

            if ($type !== 'relation' || !$isRemote || $name === '') {
                continue;
            }

            $ids = [];
            $isMultiple = (bool) ($field['multiple'] ?? false);
            foreach ($rows as $row) {
                if (!array_key_exists($name, $row)) {
                    continue;
                }
                $value = $row[$name];
                if ($value === null || $value === '') {
                    continue;
                }
                if ($isMultiple) {
                    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    foreach ($decoded as $item) {
                        $itemValue = trim((string) $item);
                        if ($itemValue !== '') {
                            $ids[] = $itemValue;
                        }
                    }
                } else {
                    $ids[] = (string) $value;
                }
            }

            $ids = array_values(array_unique(array_filter($ids, static fn(string $value): bool => $value !== '')));
            if ($ids === []) {
                continue;
            }

            $labels = $this->fetchRelationLabels($field, $ids);
            if ($labels === []) {
                continue;
            }

            foreach ($rows as $rowIndex => $row) {
                if (!array_key_exists($name, $row)) {
                    continue;
                }
                $value = $row[$name];
                if ($value === null || $value === '') {
                    continue;
                }
                if ($isMultiple) {
                    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $resolved = [];
                    foreach ($decoded as $item) {
                        $key = (string) $item;
                        if ($key === '') {
                            continue;
                        }
                        $resolved[] = $labels[$key] ?? $key;
                    }
                    $rows[$rowIndex][$name] = $resolved !== [] ? implode(', ', $resolved) : null;
                } else {
                    $key = (string) $value;
                    if ($key !== '') {
                        $rows[$rowIndex][$name] = $labels[$key] ?? $key;
                    }
                }
            }
        }

        return $rows;
    }


    private function resolveRelationOrderDirection(array $field): string
    {
        $direction = strtolower(trim((string) ($field['relation_sort_direction'] ?? 'asc')));

        return in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';
    }

    private function resolveRelationOrderField(array $field, array $definition): ?string
    {
        $fieldOrderColumn = trim((string) ($field['relation_sort_column'] ?? ''));
        if ($fieldOrderColumn !== '') {
            return $fieldOrderColumn;
        }

        $listConfig = $definition['module']['list'] ?? [];
        $manualSort = is_array($listConfig) ? (bool) ($listConfig['manual_sort'] ?? false) : false;
        $sortColumn = is_array($listConfig) ? trim((string) ($listConfig['sort_column'] ?? 'position')) : 'position';

        return $manualSort && $sortColumn !== '' ? $sortColumn : null;
    }

    /**
     * @param array<string, mixed> $field
     * @return array{table: string, value_field: string, label_field: string, order_field: string|null, order_direction: string}|null
     */
    private function resolveRelationDefinition(array $field): ?array
    {
        $moduleKey = $field['module'] ?? null;
        if (!is_string($moduleKey) || $moduleKey === '') {
            return null;
        }

        $definitionLoader = new ModuleDefinitionLoader();
        foreach ($definitionLoader->getInstallDefinitions() as $definition) {
            if (($definition['key'] ?? null) !== $moduleKey) {
                continue;
            }
            $database = $definition['database'] ?? [];
            if (!is_array($database)) {
                return null;
            }
            $table = $database['table'] ?? null;
            if (!is_string($table) || $table === '') {
                return null;
            }
            $valueField = $field['value_field'] ?? ($database['primary_key'] ?? 'id');
            $labelField = $field['label_field'] ?? 'name';
            if (!is_string($valueField) || $valueField === '' || !is_string($labelField) || $labelField === '') {
                return null;
            }

            $orderField = $this->resolveRelationOrderField($field, $definition);

            return [
                'table' => $table,
                'value_field' => $valueField,
                'label_field' => $labelField,
                'order_field' => $orderField,
                'order_direction' => $this->resolveRelationOrderDirection($field),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<int, string> $ids
     * @return array<string, string>
     */
    private function fetchRelationLabels(array $field, array $ids): array
    {
        $relationDefinition = $this->resolveRelationDefinition($field);
        if (!$relationDefinition || $ids === []) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        $rows = $connection->createQueryBuilder()
            ->select(...$this->getRelationSelectFields($field, $relationDefinition))
            ->from($relationDefinition['table'])
            ->where($relationDefinition['value_field'] . ' IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $labels = [];
        foreach ($rows as $row) {
            if (!array_key_exists($relationDefinition['value_field'], $row)) {
                continue;
            }
            $value = (string) $row[$relationDefinition['value_field']];
            if ($value === '') {
                continue;
            }
            $labels[$value] = $this->buildRelationLabel($field, $row, $relationDefinition);
        }

        foreach ($this->normalizeConfiguredOptions($field['extra_options'] ?? []) as $value => $label) {
            if (in_array($value, $ids, true) && !array_key_exists($value, $labels)) {
                $labels[$value] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $relationDefinition
     * @return array<int, string>
     */
    private function getRelationSelectFields(array $field, array $relationDefinition): array
    {
        $selectFields = [
            (string) ($relationDefinition['value_field'] ?? 'id'),
            (string) ($relationDefinition['label_field'] ?? 'name'),
        ];

        foreach ($this->getRelationLabelTemplateFields($field) as $templateField) {
            if (!in_array($templateField, $selectFields, true)) {
                $selectFields[] = $templateField;
            }
        }

        return array_values(array_filter(array_unique($selectFields), static fn(string $fieldName): bool => $fieldName !== ''));
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $relationDefinition
     * @return array<int, string>
     */
    private function getRelationSearchFields(array $field, array $relationDefinition): array
    {
        return $this->getRelationSelectFields($field, $relationDefinition);
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, string>
     */
    private function getRelationLabelTemplateFields(array $field): array
    {
        $template = trim((string) ($field['label_template'] ?? ''));
        if ($template === '') {
            return [];
        }

        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $template, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $row
     * @param array<string, mixed> $relationDefinition
     */
    private function buildRelationLabel(array $field, array $row, array $relationDefinition): string
    {
        $template = trim((string) ($field['label_template'] ?? ''));
        if ($template !== '') {
            foreach ($this->getRelationLabelTemplateFields($field) as $templateField) {
                $template = str_replace('{' . $templateField . '}', trim((string) ($row[$templateField] ?? '')), $template);
            }

            return $this->formatRelationLabel($field, $template);
        }

        $valueField = (string) ($relationDefinition['value_field'] ?? 'id');
        $labelField = (string) ($relationDefinition['label_field'] ?? 'name');
        $labelValue = $row[$labelField] ?? ($row[$valueField] ?? '');

        return $this->formatRelationLabel($field, (string) $labelValue);
    }

    private function hasAssignedWinningCodes(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (trim((string) $item) !== '') {
                    return true;
                }
            }

            return false;
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '0') {
            return false;
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $this->hasAssignedWinningCodes($decoded);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function formatRelationLabel(array $field, string $label): string
    {
        $label = trim($label);
        $datetimeFormat = trim((string) ($field['label_datetime_format'] ?? ''));

        if ($label !== '' && $datetimeFormat !== '') {
            try {
                $label = (new \DateTimeImmutable($label))->format($datetimeFormat);
            } catch (\Throwable) {
                // Keep original label when value is not a valid datetime string.
            }
        }

        $prefix = trim((string) ($field['label_prefix'] ?? ''));
        if ($prefix === '' || $label === '') {
            return $label;
        }

        return $prefix . ' ' . $label;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isUploadAllowed(UploadedFile $uploadedFile, array $field): bool
    {
        $type = strtolower((string) ($field['type'] ?? ''));

        if ($type === 'image') {
            $mimeType = $uploadedFile->getMimeType();

            return is_string($mimeType) && str_starts_with($mimeType, 'image/');
        }

        if ($type === 'video') {
            $maxSizeMb = max(1, (int) ($field['max_size_mb'] ?? 300));
            if ($uploadedFile->getSize() > $maxSizeMb * 1024 * 1024) {
                return false;
            }
            $mimeType = strtolower((string) $uploadedFile->getMimeType());
            return str_starts_with($mimeType, 'video/');
        }

        if ($type !== 'file') {
            return true;
        }

        $extensions = $this->normalizeFileTypes($field['file_types'] ?? null);
        if ($extensions === []) {
            return true;
        }

        $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());

        return $extension !== '' && in_array($extension, $extensions, true);
    }

    /**
     * @param array<int, string>|string|null $fileTypes
     * @return array<int, string>
     */
    private function normalizeFileTypes(array|string|null $fileTypes): array
    {
        if (is_string($fileTypes)) {
            $fileTypes = preg_split('/[,\\s]+/', $fileTypes) ?: [];
        }

        if (!is_array($fileTypes)) {
            return [];
        }

        $normalized = [];
        $lookup = [
            'pdf' => ['pdf'],
            'excel' => ['xls', 'xlsx', 'csv'],
            'word' => ['doc', 'docx'],
        ];

        foreach ($fileTypes as $type) {
            if (!is_string($type)) {
                continue;
            }

            $key = strtolower(trim($type));
            if ($key === '') {
                continue;
            }

            $key = ltrim($key, '.');
            if (isset($lookup[$key])) {
                $normalized = array_merge($normalized, $lookup[$key]);
                continue;
            }

            $normalized[] = $key;
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized;
    }


    /**
     * @param array<string, mixed> $fileEntry
     * @param array<string, int> $seenSlugs
     */
    private function buildPrettyFileSlug(array $fileEntry, array &$seenSlugs): string
    {
        $relativePath = trim((string) ($fileEntry['plik'] ?? ''));
        $path = (string) parse_url($relativePath, PHP_URL_PATH);
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $nameFromCms = trim((string) ($fileEntry['nazwa'] ?? ''));
        $fallbackName = pathinfo($path, PATHINFO_FILENAME);
        $baseName = $this->slugify($nameFromCms !== '' ? $nameFromCms : (string) $fallbackName);
        if ($baseName === '') {
            $baseName = 'plik';
        }

        $candidate = $baseName;
        if ($extension !== '') {
            $candidate .= '.' . $extension;
        }

        $occurrences = ($seenSlugs[$candidate] ?? 0) + 1;
        $seenSlugs[$candidate] = $occurrences;
        if ($occurrences <= 1) {
            return $candidate;
        }

        if ($extension === '') {
            return sprintf('%s-%d', $baseName, $occurrences);
        }

        return sprintf('%s-%d.%s', $baseName, $occurrences, $extension);
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';

        return trim($value, '-');
    }

    private function storeUploadedFile(UploadedFile $uploadedFile, string $moduleKey): ?string
    {
        $rootDir = dirname(__DIR__, 2);
        $uploadDir = $rootDir . '/var/uploads/modules/' . $moduleKey;

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return null;
        }

        $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension ?? '');
        if (!is_string($extension) || $extension === '') {
            $extension = 'bin';
        }

        try {
            $filename = sprintf('%s-%s.%s', $moduleKey, bin2hex(random_bytes(8)), $extension);
            $uploadedFile->move($uploadDir, $filename);
        } catch (\Throwable $exception) {
            return null;
        }

        return '/media/modules/' . rawurlencode($moduleKey) . '/' . rawurlencode($filename);
    }

    public function chunkUpload(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new JsonResponse(['error' => 'Wymagane logowanie.'], Response::HTTP_UNAUTHORIZED);
        }
        $moduleKey = trim((string) ($parameters['moduleKey'] ?? ''));
        $fieldName = trim((string) ($parameters['fieldName'] ?? ''));
        if ($moduleKey === '' || $fieldName === '') {
            return new JsonResponse(['error' => 'Nieprawidłowe parametry.'], Response::HTTP_BAD_REQUEST);
        }
        $editPermissionError = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_EDIT);
        if ($editPermissionError !== null) {
            $createPermissionError = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_CREATE);
            if ($createPermissionError !== null) {
                return $editPermissionError;
            }
        }

        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->request->get('uploadId', ''));
        $chunkIndex = (int) $request->request->get('chunkIndex', -1);
        $totalChunks = (int) $request->request->get('totalChunks', 0);
        $originalName = trim((string) $request->request->get('originalName', ''));
        $chunk = $request->files->get('chunk');
        if ($uploadId === '' || $chunkIndex < 0 || $totalChunks < 1 || !$chunk instanceof UploadedFile || !$chunk->isValid()) {
            return new JsonResponse(['error' => 'Nieprawidłowe dane uploadu.'], Response::HTTP_BAD_REQUEST);
        }

        $rootDir = dirname(__DIR__, 2);
        $tmpDir = $rootDir . '/var/uploads/chunks/' . $moduleKey . '/' . $fieldName . '/' . $uploadId;
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            return new JsonResponse(['error' => 'Nie udało się utworzyć katalogu tymczasowego.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $chunk->move($tmpDir, sprintf('%06d.part', $chunkIndex));
        if ($chunkIndex < $totalChunks - 1) {
            return new JsonResponse(['done' => false, 'receivedChunk' => $chunkIndex]);
        }

        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]+/', '', $ext ?? '');
        if (!is_string($ext) || $ext === '') {
            $ext = 'mp4';
        }
        $finalDir = $rootDir . '/var/uploads/modules/' . $moduleKey;
        if (!is_dir($finalDir) && !mkdir($finalDir, 0755, true) && !is_dir($finalDir)) {
            return new JsonResponse(['error' => 'Nie udało się utworzyć katalogu docelowego.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $filename = sprintf('%s-%s.%s', $moduleKey, bin2hex(random_bytes(8)), $ext);
        $finalPath = $finalDir . '/' . $filename;
        $out = fopen($finalPath, 'wb');
        if ($out === false) {
            return new JsonResponse(['error' => 'Nie udało się zapisać pliku.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        for ($i = 0; $i < $totalChunks; $i++) {
            $partPath = $tmpDir . '/' . sprintf('%06d.part', $i);
            if (!is_file($partPath)) {
                fclose($out);
                @unlink($finalPath);
                return new JsonResponse(['error' => 'Brak części pliku.'], Response::HTTP_BAD_REQUEST);
            }
            $in = fopen($partPath, 'rb');
            if ($in === false) {
                fclose($out);
                @unlink($finalPath);
                return new JsonResponse(['error' => 'Błąd odczytu części pliku.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        array_map('unlink', glob($tmpDir . '/*.part') ?: []);
        @rmdir($tmpDir);

        return new JsonResponse(['done' => true, 'path' => '/media/modules/' . rawurlencode($moduleKey) . '/' . rawurlencode($filename)]);
    }

    public function serveUpload(Request $request, array $parameters): Response
    {
        $moduleKey = trim((string) ($parameters['moduleKey'] ?? ''));
        $filename = trim((string) ($parameters['filename'] ?? ''));
        $absolutePath = $this->resolveModuleUploadAbsolutePath($moduleKey, $filename);
        if ($absolutePath === null && $this->moduleUsesExternalFtp($moduleKey)) {
            $absolutePath = $this->restoreModuleUploadFromExternalFtp($moduleKey, $filename);
        }
        if ($absolutePath === null) {
            throw $this->createNotFoundException('Nie znaleziono pliku.');
        }

        if (!$this->isPublicFileSource($moduleKey)) {
            if (!$this->authService->requireAuth()) {
                return new Response('Wymagane logowanie.', Response::HTTP_UNAUTHORIZED);
            }

            if (!$this->canViewModuleUpload($moduleKey)) {
                return new Response('Brak uprawnień.', Response::HTTP_FORBIDDEN);
            }
        }

        return $this->createInlineFileResponse($absolutePath);
    }

    private function canViewModuleUpload(string $sourceModuleKey): bool
    {
        $user = $this->authService->user();
        if ($this->permissionService->can($user, $sourceModuleKey, PermissionService::ACTION_VIEW)) {
            return true;
        }

        foreach ((new ModuleDefinitionLoader())->getInstallDefinitions() as $definition) {
            $moduleKey = trim((string) ($definition['key'] ?? ''));
            $module = $definition['module'] ?? null;
            if ($moduleKey === '' || !is_array($module)) {
                continue;
            }

            $mediaSources = $module['media_sources'] ?? [];
            if (!is_array($mediaSources)) {
                continue;
            }

            $mediaSources = array_values(array_filter(array_map(
                static fn(mixed $source): string => trim((string) $source),
                $mediaSources
            ), static fn(string $source): bool => $source !== ''));

            if (
                in_array($sourceModuleKey, $mediaSources, true)
                && $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_VIEW)
            ) {
                return true;
            }
        }

        return false;
    }

    private function restoreModuleUploadFromExternalFtp(string $moduleKey, string $filename): ?string
    {
        $decodedFilename = rawurldecode($filename);
        if ($decodedFilename === '' || str_contains($decodedFilename, '..') || str_contains($decodedFilename, '/')
            || str_contains($decodedFilename, '\\')) {
            return null;
        }

        $rootDir = dirname(__DIR__, 2);
        $localPath = $rootDir . '/var/uploads/modules/' . $moduleKey . '/' . $decodedFilename;
        $storage = new FtpMediaStorage((new SystemSettingsProvider($this->entityManager))->getFtpConfig());

        return $storage->download($this->buildModuleUploadRemotePath($moduleKey, $decodedFilename), $localPath) ? $localPath : null;
    }

    private function moduleUsesExternalFtp(string $moduleKey): bool
    {
        $definition = $this->loadModuleDefinition($moduleKey);
        $fields = is_array($definition) ? ($definition['fields'] ?? []) : [];
        if (!is_array($fields)) {
            return false;
        }

        foreach ($fields as $field) {
            if (is_array($field) && ($field['external_ftp'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function fieldUsesExternalFtp(string $moduleKey, string $fieldName): bool
    {
        $definition = $this->loadModuleDefinition($moduleKey);
        $fields = is_array($definition) ? ($definition['fields'] ?? []) : [];
        if (!is_array($fields)) {
            return false;
        }

        foreach ($fields as $field) {
            if (!is_array($field) || ($field['name'] ?? null) !== $fieldName) {
                continue;
            }

            return (bool) ($field['external_ftp'] ?? false);
        }

        return false;
    }

    private function buildModuleUploadRemotePath(string $moduleKey, string $filename): string
    {
        return 'modules/' . $moduleKey . '/' . rawurldecode($filename);
    }

    private function resolveModuleUploadAbsolutePath(string $moduleKey, string $filename): ?string
    {
        if ($moduleKey === '' || $filename === '' || preg_match('/^[A-Za-z0-9_-]+$/', $moduleKey) !== 1) {
            return null;
        }

        $decodedFilename = rawurldecode($filename);
        if ($decodedFilename === '' || str_contains($decodedFilename, '..') || str_contains($decodedFilename, '/')
            || str_contains($decodedFilename, '\\')) {
            return null;
        }

        $rootDir = dirname(__DIR__, 2);
        $storagePath = $rootDir . '/var/uploads/modules/' . $moduleKey . '/' . $decodedFilename;
        if (is_file($storagePath)) {
            return $storagePath;
        }

        $legacyPath = $rootDir . '/public_html/uploads/modules/' . $moduleKey . '/' . $decodedFilename;
        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        return null;
    }

    private function getManualSortColumn(array $module): ?string
    {
        $listConfig = $module['list'] ?? [];
        $manualSort = $listConfig['manual_sort'] ?? false;

        if (!$manualSort) {
            return null;
        }

        $column = $listConfig['sort_column'] ?? 'position';
        if (!is_string($column) || $column === '') {
            return null;
        }

        return $column;
    }

    /**
     * @param array<string, mixed>|null $extraAction
     * @return array{label: string, handler: mixed, confirm?: string}|null
     */
    private function normalizeExtraActionConfig(?array $extraAction): ?array
    {
        if (!is_array($extraAction)) {
            return null;
        }

        $label = $extraAction['label'] ?? null;
        if (!is_string($label) || $label === '') {
            return null;
        }

        $normalized = [
            'label' => $label,
            'handler' => $extraAction['handler'] ?? null,
        ];

        if (isset($extraAction['confirm']) && is_string($extraAction['confirm'])) {
            $normalized['confirm'] = $extraAction['confirm'];
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>>|null $extraActions
     * @return array<int, array{key: string, label: string, handler: mixed, confirm?: string}>
     */
    private function normalizeExtraActionsConfig(?array $extraActions): array
    {
        if (!is_array($extraActions)) {
            return [];
        }

        $normalized = [];
        foreach ($extraActions as $extraAction) {
            if (!is_array($extraAction)) {
                continue;
            }

            $key = $extraAction['key'] ?? null;
            $label = $extraAction['label'] ?? null;

            if (!is_string($key) || $key === '' || !is_string($label) || $label === '') {
                continue;
            }

            $action = [
                'key' => $key,
                'label' => $label,
                'handler' => $extraAction['handler'] ?? null,
            ];

            if (isset($extraAction['confirm']) && is_string($extraAction['confirm'])) {
                $action['confirm'] = $extraAction['confirm'];
            }

            if (isset($extraAction['class']) && is_string($extraAction['class'])) {
                $action['class'] = $extraAction['class'];
            }

            $normalized[] = $action;
        }

        return $normalized;
    }

    /**
     * @param mixed $handler
     * @return callable(Request, string, string, array{module: array<string, mixed>, database: array<string, mixed>}): ?Response|null
     */
    private function resolveExtraActionHandler(string $moduleKey, mixed $handler): ?callable
    {
        if (is_string($handler) && $handler !== '') {
            $listActionController = $this->resolveListActionController($moduleKey);
            if ($listActionController !== null) {
                return function (Request $request, string $calledModuleKey, string $recordId, array $definition) use ($listActionController, $handler): ?Response {
                    return $listActionController->handle($handler, $request, $calledModuleKey, $recordId, $definition);
                };
            }
        }

        if (is_string($handler) && method_exists($this, $handler)) {
            return function (Request $request, string $moduleKey, string $recordId, array $definition) use ($handler): ?Response {
                $result = $this->{$handler}($request, $moduleKey, $recordId, $definition);
                return $result instanceof Response ? $result : null;
            };
        }

        if (is_callable($handler)) {
            return function (Request $request, string $moduleKey, string $recordId, array $definition) use ($handler): ?Response {
                $result = $handler($request, $moduleKey, $recordId, $definition);
                return $result instanceof Response ? $result : null;
            };
        }

        return null;
    }

    private function resolveListActionController(string $moduleKey): ?ModuleListActionController
    {
        if (array_key_exists($moduleKey, $this->listActionControllers)) {
            return $this->listActionControllers[$moduleKey];
        }

        $controllerKey = $this->resolveModuleControllerKey($moduleKey);
        $controller = match ($controllerKey) {
            'email_templates' => new EmailTemplatesModuleListActionController(
                $this->entityManager,
                $this->mailer,
                fn() => $this->authService->user(),
                fn(Request $calledRequest, string $calledModuleKey, string $status, string $message): RedirectResponse => $this->buildModuleAlertRedirect($calledRequest, $calledModuleKey, $status, $message),
                function (Request $calledRequest, string $calledModuleKey, string $action, ?int $calledRecordId): void {
                    $this->recordModuleChange($calledRequest, $calledModuleKey, $action, $calledRecordId);
                }
            ),
            'zwyciezcy' => new WinnersModuleListActionController(
                $this->entityManager,
                $this->mailer,
                fn(Request $calledRequest, string $calledModuleKey, string $status, string $message): RedirectResponse => $this->buildModuleAlertRedirect($calledRequest, $calledModuleKey, $status, $message),
                function (Request $calledRequest, string $calledModuleKey, string $action, ?int $calledRecordId): void {
                    $this->recordModuleChange($calledRequest, $calledModuleKey, $action, $calledRecordId);
                }
            ),
            'losowania' => new DrawingsModuleListActionController(function (string $recordId, array $definition): array {
                $table = $definition['database']['table'] ?? null;
                if (!is_string($table) || $table === '') {
                    return [];
                }

                $record = $this->entityManager->getConnection()->createQueryBuilder()
                    ->select('*')
                    ->from($table)
                    ->where('id = :id')
                    ->setParameter('id', $recordId)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();

                return is_array($record) ? $record : [];
            }, $this->entityManager->getConnection()),
            default => null,
        };

        $this->listActionControllers[$moduleKey] = $controller;

        return $controller;
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    private function sendTestSmsTemplate(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        $user = $this->authService->user();
        $phone = $user?->getPhone();
        if (!$user || $phone === null || trim($phone) === '') {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Brak numeru telefonu zalogowanego użytkownika.');
        }

        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';

        if (!$table) {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Brak konfiguracji bazy danych.');
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where($primaryKey . ' = :id')
            ->setParameter('id', $recordId)
            ->executeQuery()
            ->fetchAssociative();

        if (!$template) {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Nie znaleziono szablonu.');
        }

        $firstName = $user->getFirstName();
        $lastName = $user->getLastName();
        $placeholders = ['{imie}', '{nazwisko}'];
        $replacement = [$firstName, $lastName];

        $message = str_replace($placeholders, $replacement, (string) ($template['body'] ?? ''));

        $sendResult = $this->sendSms($phone, $message, $request, 'test_sms');
        if (!$sendResult['success']) {
            $message = 'Nie udało się wysłać testowego SMS-a.';
            if (is_string($sendResult['error']) && $sendResult['error'] !== '') {
                $message .= ' Powód: ' . $sendResult['error'];
            }
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', $message);
        }

        $this->recordModuleChange($request, $moduleKey, 'test_sms', (int) $recordId);

        return $this->buildModuleAlertRedirect($request, $moduleKey, 'success', 'Wiadomość testowa została wysłana.');
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    private function resendScratchcardsLinkEmail(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';

        if (!is_string($table) || $table === '') {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Brak konfiguracji bazy danych.');
        }

        $connection = $this->entityManager->getConnection();
        $submission = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where($primaryKey . ' = :id')
            ->setParameter('id', $recordId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$submission) {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Nie znaleziono zgłoszenia.');
        }

        $recipient = trim((string) ($submission['email'] ?? ''));
        if ($recipient === '') {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Brak adresu e-mail dla tego zgłoszenia.');
        }

        $token = trim((string) ($submission['scratch_link_token'] ?? ''));
        if ($token === '') {
            $token = $this->resolveScratchcardsTokenForEmail($connection, $table, $recipient);
            $connection->update($table, [
                'scratch_link_token' => $token,
            ], [
                $primaryKey => (int) $submission[$primaryKey],
            ]);
        }

        $sentAt = new \DateTimeImmutable();
        $scratchLink = $this->buildScratchcardsLink($request, $token);
        $sendResult = $this->sendScratchcardsReminderEmail($submission, $scratchLink, $sentAt);

        if (!$sendResult['success']) {
            $message = 'Nie udało się ponownie wysłać e-maila z linkiem do zdrapek.';
            $error = $sendResult['error'] ?? null;
            if (is_string($error) && $error !== '') {
                $message .= ' Powód: ' . $error;
            }
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', $message);
        }

        $connection->update($table, [
            'reminder_sent_at' => $sentAt->format('Y-m-d H:i:s'),
        ], [
            $primaryKey => (int) $submission[$primaryKey],
        ]);

        $this->recordModuleChange($request, $moduleKey, 'resend_scratchcards_link', (int) $submission[$primaryKey]);

        return $this->buildModuleAlertRedirect($request, $moduleKey, 'success', 'Wiadomość z linkiem do zdrapek została wysłana ponownie.');
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    private function sendPendingWinnerPrizes(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        if ($moduleKey !== 'zwyciezcy') {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Ta akcja jest dostępna tylko dla modułu Zwycięzcy.');
        }

        $database = $definition['database'];
        $table = $database['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Brak konfiguracji bazy danych.');
        }

        $connection = $this->entityManager->getConnection();
        $winners = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('stan IN (:statuses)')
            ->andWhere('(kod_z_listy_wygranych IS NULL OR kod_z_listy_wygranych = 0 OR kod_z_listy_wygranych = :empty)')
            ->setParameter('statuses', ['sprawdzone', 'ai_sprawdzone'], ArrayParameterType::STRING)
            ->setParameter('empty', '')
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if (!is_array($winners) || $winners === []) {
            return $this->buildModuleAlertRedirect(
                $request,
                $moduleKey,
                'success',
                'Nie znaleziono zgłoszeń ze statusem „Sprawdzone” lub „AI Sprawdzone - poprawnie” bez przypisanego kodu.'
            );
        }

        $sentCount = 0;
        $errors = [];

        foreach ($winners as $winner) {
            $winnerId = (int) ($winner['id'] ?? 0);
            if ($winnerId <= 0) {
                continue;
            }

            $emailResult = $this->sendWinnerCodeEmailWithError($winner, $request);
            if ($emailResult['success']) {
                $sentCount++;
                continue;
            }

            $errorReason = trim((string) ($emailResult['error'] ?? 'Nieznany błąd.'));
            $errors[] = '#' . $winnerId . ': ' . $errorReason;
        }

        if ($sentCount > 0) {
            $this->recordModuleChange($request, $moduleKey, 'send_pending_winner_prizes', null);
        }

        if ($sentCount === 0) {
            $message = 'Nie udało się wysłać żadnej zaległej wygranej.';
            if ($errors !== []) {
                $message .= ' ' . implode(' | ', array_slice($errors, 0, 3));
            }

            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', $message);
        }

        $message = 'Wysłano zaległe wygrane: ' . $sentCount . '.';
        if ($errors !== []) {
            $message .= ' Błędy: ' . count($errors) . '.';
            $message .= ' ' . implode(' | ', array_slice($errors, 0, 3));
        }

        return $this->buildModuleAlertRedirect($request, $moduleKey, 'success', $message);
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    private function rejectTicketsForRejectedScratchcards(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        if ($moduleKey !== 'loteria_losy') {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Ta akcja jest dostępna tylko dla modułu Loteria losy.');
        }

        $ticketTable = $definition['database']['table'] ?? null;
        if (!is_string($ticketTable) || $ticketTable === '') {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Brak konfiguracji bazy danych dla modułu Loteria losy.');
        }

        $winnersDefinition = $this->loadModuleDefinition('zwyciezcy');
        if ($winnersDefinition === null) {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Nie znaleziono definicji modułu Zwycięzcy.');
        }

        $winnersTable = $winnersDefinition['database']['table'] ?? null;
        if (!is_string($winnersTable) || $winnersTable === '') {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Brak konfiguracji bazy danych dla modułu Zwycięzcy.');
        }

        $connection = $this->entityManager->getConnection();
        $submissionIds = $connection->createQueryBuilder()
            ->select('DISTINCT zgloszenie')
            ->from($winnersTable)
            ->where('stan = :status')
            ->andWhere('zgloszenie IS NOT NULL')
            ->andWhere('zgloszenie <> 0')
            ->andWhere("COALESCE(rodzaj, '') IN (:kinds)")
            ->setParameter('status', 'odrzucone')
            ->setParameter('kinds', ['zdrapka', ''], ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchFirstColumn();

        $submissionIds = array_values(array_unique(array_filter(array_map('intval', $submissionIds), static fn(int $id): bool => $id > 0)));
        if ($submissionIds === []) {
            return $this->buildModuleAlertRedirect(
                $request,
                $moduleKey,
                'success',
                'Nie znaleziono odrzuconych zdrapek z przypisanym zgłoszeniem.'
            );
        }

        $updatedCount = $connection->createQueryBuilder()
            ->update($ticketTable)
            ->set('status', ':status')
            ->where('zgloszenie IN (:submissionIds)')
            ->setParameter('status', 'odrzucony')
            ->setParameter('submissionIds', $submissionIds, ArrayParameterType::INTEGER)
            ->executeStatement();

        if ($updatedCount > 0) {
            $this->recordModuleChange($request, $moduleKey, 'reject_tickets_for_rejected_scratchcards', null);
        }

        return $this->buildModuleAlertRedirect(
            $request,
            $moduleKey,
            'success',
            sprintf(
                'Zaktualizowano losy dla %d zgłoszeń. Ustawiono status „Odrzucony” dla %d losów.',
                count($submissionIds),
                $updatedCount
            )
        );
    }

    private function buildScratchcardsLink(Request $request, string $token): string
    {
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');

        return $baseUrl . '/loteria/zdrapki/' . urlencode($token);
    }

    private function resolveScratchcardsTokenForEmail(Connection $connection, string $submissionTable, string $email): string
    {
        $normalizedEmail = trim($email);
        if ($normalizedEmail !== '') {
            $existingToken = $connection->createQueryBuilder()
                ->select('scratch_link_token')
                ->from($submissionTable)
                ->where('email = :email')
                ->andWhere('scratch_link_token IS NOT NULL')
                ->andWhere('scratch_link_token <> :emptyToken')
                ->setParameter('email', $normalizedEmail)
                ->setParameter('emptyToken', '')
                ->orderBy('id', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            $existingToken = trim((string) $existingToken);
            if ($existingToken !== '') {
                return $existingToken;
            }
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            return sha1(uniqid((string) mt_rand(), true));
        }
    }

    /**
     * @param array<string, mixed> $submission
     * @return array{success: bool, error?: string}
     */
    private function sendScratchcardsReminderEmail(array $submission, string $scratchLink, \DateTimeImmutable $sentAt): array
    {
        $recipient = trim((string) ($submission['email'] ?? ''));
        if ($recipient === '') {
            return ['success' => false, 'error' => 'Brak adresu e-mail.'];
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $templateId = $settings['reminder_email_template'] ?? null;
        if (!is_string($templateId) && !is_int($templateId)) {
            return ['success' => false, 'error' => 'Brak skonfigurowanego szablonu przypomnienia.'];
        }

        $definition = $this->resolveModuleDefinition('email_templates');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return ['success' => false, 'error' => 'Brak konfiguracji modułu szablonów e-mail.'];
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', (string) $templateId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$template) {
            return ['success' => false, 'error' => 'Nie znaleziono szablonu przypomnienia.'];
        }

        $placeholders = ['{data}', '{data_czas}', '{email}', '{telefon}', '{link_zdrapki}'];
        $replacement = [
            $sentAt->format('d-m-Y'),
            $sentAt->format('Y-m-d H:i:s'),
            $recipient,
            trim((string) ($submission['phone'] ?? '')),
            $scratchLink,
        ];

        $subject = str_replace($placeholders, $replacement, (string) ($template['subject'] ?? ''));
        $body = str_replace($placeholders, $replacement, (string) ($template['body'] ?? ''));

        return $this->mailer->sendWithError($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), false, $this->isPriorityEmailTemplate($template));
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    private function generateLotteryTickets(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        $database = $definition['database'];
        $table = $database['table'] ?? null;

        if (!$table) {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Brak konfiguracji bazy danych.');
        }

        $connection = $this->entityManager->getConnection();
        $rows = $connection->createQueryBuilder()
            ->select('id', 'submission_code')
            ->from($table)
            ->where('suspicious = 0 OR suspicious IS NULL')
            ->orderBy('id', 'asc')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return $this->buildModuleAlertRedirect($request, $moduleKey, 'error', 'Brak zgłoszeń do wygenerowania losów.');
        }

        $ticketRows = '';
        $chunks = array_chunk($rows, 2);
        foreach ($chunks as $chunk) {
            $ticketRows .= '<tr>';
            for ($i = 0; $i < 2; $i++) {
                if (isset($chunk[$i])) {
                    $id = htmlspecialchars((string) ($chunk[$i]['id'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $code = htmlspecialchars((string) ($chunk[$i]['submission_code'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $ticketRows .= '<td><div class="ticket-id">ID: ' . $id . '</div><div class="ticket-code">Kod: ' . $code . '</div></td>';
                } else {
                    $ticketRows .= '<td></td>';
                }
            }
            $ticketRows .= '</tr>';
        }

        $html = sprintf(
            '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans, sans-serif;font-size:14px;}table{width:100%%;border-collapse:collapse;table-layout:fixed;}td{border:1px dashed #000;padding:14px;height:80px;vertical-align:middle;} .ticket-id{font-weight:bold;margin-bottom:6px;} .ticket-code{font-size:16px;}</style></head><body><h1>Losy do loterii</h1><table>%s</table></body></html>',
            $ticketRows
        );

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('losy-%s-%s.pdf', $moduleKey, (new \DateTimeImmutable())->format('Y-m-d'));

        $this->recordModuleChange($request, $moduleKey, 'lottery_pdf', null);

        return new Response(
            $dompdf->output(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ]
        );
    }


    /**
     * @return array<int, string>
     */

    /**
     * @param array<int, array<string, mixed>> $fields
     *
     * @return array<string, string>
     */
    private function extractSelectFieldOptions(array $fields, string $fieldName): array
    {
        if ($fieldName === '') {
            return [];
        }

        foreach ($fields as $field) {
            if ((string) ($field['name'] ?? '') !== $fieldName) {
                continue;
            }

            $options = $field['options'] ?? [];
            if (!is_array($options)) {
                return [];
            }

            $normalizedOptions = [];
            foreach ($options as $key => $label) {
                if (!is_scalar($key) || !is_scalar($label)) {
                    continue;
                }

                $normalizedOptions[(string) $key] = (string) $label;
            }

            return $normalizedOptions;
        }

        return [];
    }

    /**
     * Applies the common list search to stored columns and to labels of relation fields.
     *
     * Relation values are stored in the module table as IDs, so searching the stored
     * column alone cannot find the text displayed to the user. The additional
     * subqueries translate a label match back to the related record IDs.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, string> $searchColumns
     */
    private function applyModuleListSearch(
        \Doctrine\DBAL\Query\QueryBuilder $queryBuilder,
        array $fields,
        array $searchColumns,
        string $table,
        string $query
    ): void {
        if ($query === '' || $searchColumns === [] || !$this->isSafeSqlIdentifier($table)) {
            return;
        }

        $orConditions = $queryBuilder->expr()->orX();
        $hasConditions = false;
        $searchColumnMap = array_fill_keys($searchColumns, true);

        foreach ($searchColumns as $index => $column) {
            if (!$this->isSafeSqlIdentifier($column)) {
                continue;
            }

            $parameter = 'query' . $index;
            $orConditions->add($queryBuilder->expr()->like($column, ':' . $parameter));
            $queryBuilder->setParameter($parameter, '%' . $query . '%');
            $hasConditions = true;
        }

        $relationIndex = 0;
        foreach ($fields as $field) {
            $fieldName = trim((string) ($field['name'] ?? ''));
            $fieldType = strtolower(trim((string) ($field['type'] ?? '')));
            if (
                $fieldName === ''
                || $fieldType !== 'relation'
                || !isset($searchColumnMap[$fieldName])
                || !$this->isSafeSqlIdentifier($fieldName)
            ) {
                continue;
            }

            $relationDefinition = $this->resolveRelationDefinition($field);
            if ($relationDefinition === null) {
                continue;
            }

            $relationTable = (string) ($relationDefinition['table'] ?? '');
            $valueField = (string) ($relationDefinition['value_field'] ?? '');
            if (!$this->isSafeSqlIdentifier($relationTable) || !$this->isSafeSqlIdentifier($valueField)) {
                continue;
            }

            $searchFields = array_values(array_filter(
                $this->getRelationSearchFields($field, $relationDefinition),
                fn(string $searchField): bool => $searchField !== $valueField
                    && $this->isSafeSqlIdentifier($searchField)
            ));
            if ($searchFields === []) {
                continue;
            }

            $alias = 'relation_search_' . $relationIndex;
            $parameter = 'relation_query_' . $relationIndex;
            ++$relationIndex;

            $relationConditions = $queryBuilder->expr()->orX();
            foreach ($searchFields as $searchField) {
                $relationConditions->add(
                    $queryBuilder->expr()->like($alias . '.' . $searchField, ':' . $parameter)
                );
            }

            $relationQueryBuilder = $this->entityManager->getConnection()->createQueryBuilder()
                ->from($relationTable, $alias)
                ->where($relationConditions);

            if ((bool) ($field['multiple'] ?? false)) {
                $relationQueryBuilder
                    ->select('1')
                    ->andWhere(sprintf(
                        "%s.%s LIKE CONCAT('%%\"', CAST(%s.%s AS CHAR), '\"%%')",
                        $table,
                        $fieldName,
                        $alias,
                        $valueField
                    ));
                $orConditions->add('EXISTS (' . $relationQueryBuilder->getSQL() . ')');
            } else {
                $relationQueryBuilder->select($alias . '.' . $valueField);
                $orConditions->add(
                    $queryBuilder->expr()->in($fieldName, $relationQueryBuilder->getSQL())
                );
            }

            $queryBuilder->setParameter($parameter, '%' . $query . '%');
            $hasConditions = true;
        }

        if ($hasConditions) {
            $queryBuilder->andWhere($orConditions);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, string> $defaultColumns
     * @return array<int, string>
     */
    private function resolveSearchColumns(array $fields, array $defaultColumns, string $primaryKey): array
    {
        $virtualColumns = [];
        foreach ($fields as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (in_array(strtolower((string) ($field['type'] ?? 'text')), ['related_field', 'relation_field'], true)) {
                $virtualColumns[$name] = true;
            }
        }

        if ($virtualColumns === []) {
            return $defaultColumns;
        }

        return array_values(array_filter(
            array_unique(array_merge([$primaryKey], $defaultColumns)),
            static fn(string $column): bool => $column === $primaryKey || !isset($virtualColumns[$column])
        ));
    }

    /**
     * @param array<string, mixed> $module
     */
    private function moduleAllowsConfiguredAction(array $module, string $action): bool
    {
        $list = $module['list'] ?? [];
        $configuredActions = is_array($list) ? ($list['permissions'] ?? null) : null;
        if ($configuredActions === null) {
            $configuredActions = $module['permissions'] ?? [];
        }

        if (!is_array($configuredActions) || $configuredActions === []) {
            return true;
        }

        return in_array($action, $configuredActions, true);
    }

    private function applyLotteryTicketsVerifiedSubmissionFilter(
        Request $request,
        string $moduleKey,
        \Doctrine\DBAL\Query\QueryBuilder $queryBuilder
    ): void {
        if ($moduleKey !== 'loteria_losy' || trim((string) $request->query->get('exclude_invalid_submissions', '')) !== '1') {
            return;
        }

        $validSubmissionsQueryBuilder = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('id')
            ->from('loteria_zgloszenia_entries')
            ->where('(zgloszenie_sprawdzone IS NULL OR zgloszenie_sprawdzone <> :excluded_submission_verification_status)');

        $queryBuilder->andWhere(sprintf('zgloszenie IN (%s)', $validSubmissionsQueryBuilder->getSQL()));
        $queryBuilder->setParameter('excluded_submission_verification_status', 'niepoprawne');
    }

    private function applyEligibleTicketsMinimumFilter(
        Request $request,
        string $moduleKey,
        string $table,
        \Doctrine\DBAL\Query\QueryBuilder $queryBuilder
    ): void {
        if ($moduleKey !== 'loteria_losy' || !$this->isSafeSqlIdentifier($table)) {
            return;
        }

        $minimum = (int) trim((string) $request->query->get('eligible_tickets_min', ''));
        if ($minimum <= 1) {
            return;
        }

        $eligibleTicketsQueryBuilder = clone $queryBuilder;
        $eligibleTicketsQueryBuilder
            ->select('email')
            ->resetQueryPart('orderBy')
            ->andWhere('email IS NOT NULL')
            ->andWhere("email <> ''")
            ->groupBy('email')
            ->having('COUNT(*) >= :eligible_tickets_min_filter');

        $queryBuilder->andWhere(sprintf('email IN (%s)', $eligibleTicketsQueryBuilder->getSQL()));
        $queryBuilder->setParameter('eligible_tickets_min_filter', $minimum);
    }

    /**
     * Applies immutable module-level list constraints before user search and filters.
     *
     * Supported fixed filter examples:
     * - ['field' => 'status', 'operator' => 'eq', 'value' => 'active']
     * - ['field' => 'status', 'operator' => 'in', 'values' => ['active', 'pending']]
     * - ['field' => 'purchase_date', 'operator' => 'at_least_days_ago', 'days' => 14]
     * - [
     *     'field' => 'stan',
     *     'operator' => 'in_relation_flag',
     *     'module' => 'ustawienia_statusow',
     *     'flag' => 'do_weryfikacji_przez_administratora',
     *   ]
     *
     * @param \Doctrine\DBAL\Query\QueryBuilder $queryBuilder
     * @param array<string, mixed> $module
     */
    private function applyFixedListFilters(\Doctrine\DBAL\Query\QueryBuilder $queryBuilder, array $module): void
    {
        $list = $module['list'] ?? null;
        $fixedFilters = is_array($list) ? ($list['fixed_filters'] ?? []) : [];
        if (!is_array($fixedFilters) || $fixedFilters === []) {
            return;
        }

        foreach (array_values($fixedFilters) as $index => $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $field = trim((string) ($filter['field'] ?? ''));
            if (!$this->isSafeSqlIdentifier($field)) {
                continue;
            }

            $operator = strtolower(trim((string) ($filter['operator'] ?? 'eq')));
            $parameter = 'fixed_filter_' . $index;

            if ($operator === 'eq') {
                $queryBuilder->andWhere($queryBuilder->expr()->eq($field, ':' . $parameter));
                $queryBuilder->setParameter($parameter, $filter['value'] ?? null);
                continue;
            }

            if ($operator === 'in') {
                $values = $filter['values'] ?? [];
                if (!is_array($values) || $values === []) {
                    continue;
                }

                $queryBuilder->andWhere($queryBuilder->expr()->in($field, ':' . $parameter));
                $queryBuilder->setParameter($parameter, $values, ArrayParameterType::STRING);
                continue;
            }

            if ($operator === 'at_least_days_ago') {
                $days = (int) ($filter['days'] ?? 0);
                if ($days < 0) {
                    continue;
                }

                $thresholdDate = (new \DateTimeImmutable('today'))
                    ->modify(sprintf('-%d days', $days))
                    ->format('Y-m-d');

                $queryBuilder->andWhere($queryBuilder->expr()->lte($field, ':' . $parameter));
                $queryBuilder->setParameter($parameter, $thresholdDate);
                continue;
            }

            if ($operator === 'in_relation_flag') {
                $this->applyRelationFlagFixedFilter($queryBuilder, $filter, $field, $parameter);
            }
        }
    }

    /**
     * @param \Doctrine\DBAL\Query\QueryBuilder $queryBuilder
     * @param array<string, mixed> $filter
     */
    private function applyRelationFlagFixedFilter(
        \Doctrine\DBAL\Query\QueryBuilder $queryBuilder,
        array $filter,
        string $field,
        string $parameter
    ): void {
        $moduleKey = trim((string) ($filter['module'] ?? ''));
        $flagField = trim((string) ($filter['flag'] ?? ''));
        if ($moduleKey === '' || !$this->isSafeSqlIdentifier($flagField)) {
            return;
        }

        $definition = null;
        $definitionLoader = new ModuleDefinitionLoader();
        foreach ($definitionLoader->getInstallDefinitions() as $installDefinition) {
            if (($installDefinition['key'] ?? null) === $moduleKey) {
                $definition = $installDefinition;
                break;
            }
        }

        $database = is_array($definition) ? ($definition['database'] ?? []) : [];
        if (!is_array($database)) {
            return;
        }

        $table = trim((string) ($database['table'] ?? ''));
        $valueField = trim((string) ($filter['value_field'] ?? ($database['primary_key'] ?? 'id')));
        if (!$this->isSafeSqlIdentifier($table) || !$this->isSafeSqlIdentifier($valueField)) {
            return;
        }

        $subquery = sprintf(
            'SELECT %s FROM %s WHERE %s = :%s',
            $valueField,
            $table,
            $flagField,
            $parameter
        );

        $queryBuilder->andWhere(sprintf('%s IN (%s)', $field, $subquery));
        $queryBuilder->setParameter($parameter, 1);
    }

    private function isSafeSqlIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }

    /**
     * @param array<string, mixed> $module
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, array{label: string, type: string, options: array<string, string>}>
     */
    private function resolveModuleFilters(array $module, array $fields, array $selectedFilterNames = [], bool $includeAllAvailable = false): array
    {
        $allowedFilterTypes = ['select', 'boolean', 'boolean_select', 'relation', 'datetime', 'date', 'related_field', 'relation_field'];
        $list = $module['list'] ?? null;
        $configuredFilters = is_array($list) ? ($list['filters'] ?? []) : [];

        $fieldMap = [];
        foreach ($fields as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $fieldMap[$name] = $field;
        }

        $allowedFilterNames = null;
        if (is_array($list)) {
            $availableFilterConfig = $list['available_filters'] ?? null;
            $availableColumnConfig = $list['available_columns'] ?? null;
            $accessLimitedFilterConfig = is_array($availableFilterConfig)
                ? $availableFilterConfig
                : (is_array($availableColumnConfig) ? $availableColumnConfig : null);

            if (is_array($accessLimitedFilterConfig)) {
                $normalizedAllowedFilterNames = array_values(array_unique(array_filter(array_map(
                    static fn(mixed $field): string => trim(is_array($field) ? (string) ($field['name'] ?? '') : (string) $field),
                    $accessLimitedFilterConfig
                ), static fn(string $field): bool => $field !== '' && isset($fieldMap[$field]))));

                if ($normalizedAllowedFilterNames !== []) {
                    $allowedFilterNames = array_fill_keys($normalizedAllowedFilterNames, true);
                }
            }
        }

        $filters = [];
        $filterDefinitions = [];
        if (is_array($configuredFilters) && $configuredFilters !== []) {
            $filterDefinitions = $configuredFilters;
        } else {
            $filterDefinitions = array_keys($fieldMap);
        }

        if ($includeAllAvailable) {
            $configuredFilterNames = [];
            foreach ($filterDefinitions as $filterConfig) {
                $configuredFilterNames[] = is_array($filterConfig)
                    ? trim((string) ($filterConfig['name'] ?? ''))
                    : trim((string) $filterConfig);
            }

            foreach (array_keys($fieldMap) as $fieldName) {
                if (in_array($fieldName, $configuredFilterNames, true)) {
                    continue;
                }

                if ($allowedFilterNames !== null && !isset($allowedFilterNames[$fieldName])) {
                    continue;
                }

                $filterDefinitions[] = $fieldName;
            }
        }

        foreach ($filterDefinitions as $filterConfig) {
            $name = '';
            $customLabel = '';
            if (is_array($filterConfig)) {
                $name = trim((string) ($filterConfig['name'] ?? ''));
                $customLabel = trim((string) ($filterConfig['label'] ?? ''));
            } else {
                $name = trim((string) $filterConfig);
            }

            if ($name === '' || !isset($fieldMap[$name])) {
                continue;
            }

            if ($allowedFilterNames !== null && !isset($allowedFilterNames[$name])) {
                continue;
            }

            $field = $fieldMap[$name];
            $fieldType = strtolower((string) ($field['type'] ?? 'text'));
            if (!in_array($fieldType, $allowedFilterTypes, true)) {
                continue;
            }
            $filterType = $this->resolveFilterType($field);
            if (!in_array($filterType, ['select', 'boolean', 'boolean_select', 'relation', 'datetime', 'date'], true)) {
                continue;
            }
            $filters[$name] = [
                'label' => $customLabel !== '' ? $customLabel : trim((string) ($field['label'] ?? $name)),
                'type' => $filterType,
                'options' => [],
                'field' => $field,
                'option_label_template' => is_array($filterConfig) ? trim((string) ($filterConfig['option_label_template'] ?? '')) : '',
                'multiple' => is_array($filterConfig) && !empty($filterConfig['multiple']),
            ];
        }

        if ($selectedFilterNames !== []) {
            $selectedFilterNames = array_values(array_unique(array_filter(array_map(
                static fn(mixed $name): string => trim((string) $name),
                $selectedFilterNames
            ), static fn(string $name): bool => $name !== '')));
            $selectedFilters = [];
            foreach ($selectedFilterNames as $selectedFilterName) {
                if (isset($filters[$selectedFilterName])) {
                    $selectedFilters[$selectedFilterName] = $filters[$selectedFilterName];
                    continue;
                }

                if (!isset($fieldMap[$selectedFilterName])) {
                    continue;
                }

                if ($allowedFilterNames !== null && !isset($allowedFilterNames[$selectedFilterName])) {
                    continue;
                }

                $field = $fieldMap[$selectedFilterName];
                $fieldType = strtolower((string) ($field['type'] ?? 'text'));
                if (!in_array($fieldType, $allowedFilterTypes, true)) {
                    continue;
                }
                $filterType = $this->resolveFilterType($field);
                if (!in_array($filterType, ['select', 'boolean', 'boolean_select', 'relation', 'datetime', 'date'], true)) {
                    continue;
                }
                $selectedFilters[$selectedFilterName] = [
                    'label' => trim((string) ($field['label'] ?? $selectedFilterName)),
                    'type' => $filterType,
                    'options' => [],
                    'field' => $field,
                    'option_label_template' => '',
                    'multiple' => false,
                ];
            }

            return $selectedFilters;
        }

        return $filters;
    }

    /**
     * @return array<string, string>
     */
    private function fetchDistinctFilterOptions(Connection $connection, string $table, string $filterName, array $filterConfig): array
    {
        if ($filterName === '') {
            return [];
        }

        $filterType = strtolower((string) ($filterConfig['type'] ?? ''));
        if ($this->isBooleanFilterType($filterType)) {
            return ['0' => 'Nie', '1' => 'Tak'];
        }

        $queryBuilder = $connection->createQueryBuilder()
            ->select('DISTINCT ' . $filterName . ' AS value')
            ->from($table)
            ->where($filterName . ' IS NOT NULL');

        $queryBuilder->andWhere($filterName . " <> ''");

        $rows = $queryBuilder
            ->orderBy($filterName, 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $options = [];
        $field = $filterConfig['field'] ?? [];
        $optionTemplate = trim((string) ($filterConfig['option_label_template'] ?? ''));
        $relationOptions = [];
        $selectFieldOptions = [];
        $isRelationFilter = is_array($field) && strtolower((string) ($field['type'] ?? '')) === 'relation';
        if ($isRelationFilter && $optionTemplate !== '') {
            $relationOptions = $this->buildRelationFilterOptionsWithTemplate($field, $optionTemplate);
        } elseif ($isRelationFilter) {
            $relationIds = [];
            foreach ($rows as $row) {
                $rawValue = $row['value'] ?? null;
                if ($rawValue === null) {
                    continue;
                }

                $value = is_bool($rawValue)
                    ? ($rawValue ? '1' : '0')
                    : trim((string) $rawValue);
                if ($value !== '') {
                    $relationIds[] = $value;
                }
            }

            $relationIds = array_values(array_unique($relationIds));
            $relationOptions = $this->fetchRelationLabels($field, $relationIds);
        }
        if (is_array($field) && strtolower((string) ($field['type'] ?? '')) === 'select') {
            $configuredOptions = $field['options'] ?? [];
            if (is_array($configuredOptions)) {
                foreach ($configuredOptions as $optionValue => $optionLabel) {
                    $normalizedOptionValue = trim((string) $optionValue);
                    if ($normalizedOptionValue === '') {
                        continue;
                    }

                    $selectFieldOptions[$normalizedOptionValue] = trim((string) $optionLabel);
                }
            }
        }

        foreach ($rows as $row) {
            $rawValue = $row['value'] ?? null;
            if ($rawValue === null) {
                continue;
            }

            $value = is_bool($rawValue)
                ? ($rawValue ? '1' : '0')
                : trim((string) $rawValue);
            if ($value === '') {
                continue;
            }

            $label = $relationOptions[$value] ?? $value;
            if (isset($selectFieldOptions[$value]) && $selectFieldOptions[$value] !== '') {
                $label = $selectFieldOptions[$value];
            }
            if ($filterType === 'boolean' || $filterType === 'boolean_select') {
                $label = in_array($value, ['1', 'true', 'yes'], true) ? 'Tak' : 'Nie';
            }

            $options[$value] = $label;
        }

        if (is_array($field) && strtolower((string) ($field['type'] ?? '')) === 'relation') {
            $options = $this->sortRelationFilterOptionsByModuleOrder($options, $field);
        }

        return $options;
    }

    /**
     * @param mixed $options
     * @return array<string, string>
     */
    private function normalizeConfiguredOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $value => $label) {
            $optionValue = trim((string) $value);
            if ($optionValue === '') {
                continue;
            }

            $normalized[$optionValue] = (string) $label;
        }

        return $normalized;
    }

    /**
     * @param array<string, string> $options
     * @param array<string, mixed>  $field
     *
     * @return array<string, string>
     */
    private function sortRelationFilterOptionsByModuleOrder(array $options, array $field): array
    {
        if ($options === []) {
            return [];
        }

        $moduleKey = trim((string) ($field['module'] ?? ''));
        if ($moduleKey === '') {
            return $options;
        }

        $relationDefinition = $this->resolveModuleDefinition($moduleKey);
        if (!is_array($relationDefinition)) {
            return $options;
        }

        $database = $relationDefinition['database'] ?? null;
        $table = is_array($database) ? trim((string) ($database['table'] ?? '')) : '';
        if ($table === '') {
            return $options;
        }

        $primaryKey = trim((string) ($database['primary_key'] ?? 'id'));
        if ($primaryKey === '') {
            return $options;
        }

        $moduleConfig = $relationDefinition['module'] ?? null;
        $listConfig = is_array($moduleConfig) ? ($moduleConfig['list'] ?? null) : null;
        $sortColumn = is_array($listConfig) ? trim((string) ($listConfig['sort_column'] ?? '')) : '';
        if ($sortColumn === '') {
            return $options;
        }

        $ids = array_values(array_filter(array_map('trim', array_keys($options)), static fn(string $id): bool => $id !== ''));
        if ($ids === []) {
            return $options;
        }

        $sortDirection = $this->resolveRelationOrderDirection($field);
        $configuredSortColumn = trim((string) ($field['relation_sort_column'] ?? ''));
        if ($configuredSortColumn !== '') {
            $sortColumn = $configuredSortColumn;
        }

        $rows = $this->entityManager->getConnection()->createQueryBuilder()
            ->select($primaryKey . ' AS id')
            ->from($table)
            ->where($primaryKey . ' IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->orderBy($sortColumn, $sortDirection)
            ->addOrderBy($primaryKey, 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $sorted = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || !array_key_exists($id, $options)) {
                continue;
            }

            $sorted[$id] = $options[$id];
        }

        foreach ($options as $id => $label) {
            if (array_key_exists($id, $sorted)) {
                continue;
            }

            $sorted[$id] = $label;
        }

        return $sorted;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, string>
     */
    private function buildRelationFilterOptionsWithTemplate(array $field, string $template): array
    {
        $moduleKey = trim((string) ($field['module'] ?? ''));
        if ($moduleKey === '') {
            return [];
        }

        $relationDefinition = $this->resolveModuleDefinition($moduleKey);
        if (!is_array($relationDefinition)) {
            return [];
        }

        $database = $relationDefinition['database'] ?? null;
        $table = is_array($database) ? (string) ($database['table'] ?? '') : '';
        if ($table === '') {
            return [];
        }

        $valueField = trim((string) ($field['value_field'] ?? ($database['primary_key'] ?? 'id')));
        if ($valueField === '') {
            return [];
        }

        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $template, $matches);
        $templateFields = array_values(array_unique($matches[1] ?? []));
        if ($templateFields === []) {
            return [];
        }

        $selectFields = array_values(array_unique(array_merge([$valueField], $templateFields)));
        $rows = $this->entityManager->getConnection()->createQueryBuilder()
            ->select(...$selectFields)
            ->from($table)
            ->executeQuery()
            ->fetchAllAssociative();

        $options = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$valueField] ?? ''));
            if ($value === '') {
                continue;
            }

            $label = $template;
            foreach ($templateFields as $templateField) {
                $label = str_replace('{' . $templateField . '}', (string) ($row[$templateField] ?? ''), $label);
            }

            $options[$value] = trim($label);
        }

        return $options;
    }

    /**
     * @param array<string, string> $winnerStoreOptions
     * @param array<string, string> $centerOptions
     *
     * @return array<string, string>
     */
    private function appendCenterLabelsToWinnerStores(array $winnerStoreOptions, array $centerOptions): array
    {
        if ($winnerStoreOptions === []) {
            return [];
        }

        $storeCenterMap = $this->fetchStoreCenterMap();
        if ($storeCenterMap === []) {
            return $winnerStoreOptions;
        }

        $optionsWithCenter = [];
        foreach ($winnerStoreOptions as $storeId => $storeName) {
            $normalizedStoreId = (string) $storeId;
            $normalizedStoreName = trim((string) $storeName);
            $centerId = $storeCenterMap[$normalizedStoreId] ?? '';
            $centerLabel = $centerOptions[$centerId] ?? '';
            if ($centerLabel === '') {
                $optionsWithCenter[$normalizedStoreId] = $normalizedStoreName;
                continue;
            }

            $optionsWithCenter[$normalizedStoreId] = sprintf('%s (%s)', $normalizedStoreName, $centerLabel);
        }

        return $optionsWithCenter;
    }

    /**
     * @param array<string, string> $options
     * @return array<int, string>
     */
    private function findMatchingOptionIds(array $options, string $query): array
    {
        $normalizedQuery = trim($query);
        if ($normalizedQuery === '' || $options === []) {
            return [];
        }

        $matchingIds = [];
        foreach ($options as $optionId => $optionLabel) {
            if (stripos((string) $optionLabel, $normalizedQuery) === false) {
                continue;
            }

            $matchingIds[] = (string) $optionId;
        }

        return $matchingIds;
    }

    /**
     * @return array<string, string>
     */
    private function fetchStoreCenterMap(): array
    {
        $definition = $this->resolveModuleDefinition('lista_sklepow');
        $database = is_array($definition) ? ($definition['database'] ?? null) : null;
        $table = is_array($database) ? ($database['table'] ?? null) : null;
        if (!is_string($table) || $table === '') {
            return [];
        }

        $rows = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('id', 'centrum_handlowe')
            ->from($table)
            ->executeQuery()
            ->fetchAllAssociative();

        $storeCenterMap = [];
        foreach ($rows as $row) {
            $storeId = trim((string) ($row['id'] ?? ''));
            if ($storeId === '') {
                continue;
            }

            $storeCenterMap[$storeId] = trim((string) ($row['centrum_handlowe'] ?? ''));
        }

        return $storeCenterMap;
    }

    private function isValidDateFilterValue(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date) {
            return false;
        }

        return $date->format('Y-m-d') === $value;
    }

    private function normalizeRangeFilterValue(string $value, string $filterType): string
    {
        $normalizedValue = trim($value);
        if ($normalizedValue === '') {
            return '';
        }

        if ($filterType !== 'datetime') {
            return $normalizedValue;
        }

        $normalizedValue = str_replace('T', ' ', $normalizedValue);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalizedValue) === 1) {
            return $normalizedValue . ':00';
        }

        return $normalizedValue;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function buildImportableFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            $type = strtolower((string) ($field['type'] ?? 'text'));
            if ($name === '' || ($field['form'] ?? true) === false) {
                continue;
            }
            if (in_array($type, ['section', 'info', 'related_field', 'relation_field', 'image', 'file', 'video'], true)) {
                continue;
            }
            $result[] = $field;
        }

        return $result;
    }

    private function parseCsvFile(UploadedFile $csvFile): array
    {
        $handle = fopen($csvFile->getPathname(), 'rb');
        if ($handle === false) {
            return [];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }

        $firstLine = rtrim($firstLine, "
");
        $semicolonHeader = str_getcsv($firstLine, ';', '"', '');
        $commaHeader = str_getcsv($firstLine, ',', '"', '');
        $delimiter = count($semicolonHeader) >= count($commaHeader) ? ';' : ',';
        $header = $delimiter === ';' ? $semicolonHeader : $commaHeader;

        if (!is_array($header) || $header === []) {
            fclose($handle);
            return [];
        }

        $header = array_map(fn($column): string => $this->normalizeCsvColumnName((string) $column), $header);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $assoc = [];
            foreach ($header as $index => $columnName) {
                $columnName = trim((string) $columnName);
                if ($columnName === '') {
                    continue;
                }
                $columnName = (string) preg_replace('/^\x{FEFF}/u', '', $columnName);
                $assoc[$columnName] = isset($row[$index]) ? trim((string) $row[$index]) : '';
            }
            if ($assoc !== []) {
                $rows[] = $assoc;
            }
        }
        fclose($handle);

        return $rows;
    }


    private function normalizeCsvColumnName(string $columnName): string
    {
        $normalized = trim($columnName);
        $normalized = (string) preg_replace('/^\x{FEFF}/u', '', $normalized);
        $normalized = (string) preg_replace('/^ï»¿/u', '', $normalized);
        $normalized = (string) preg_replace('/^\xEF\xBB\xBF/', '', $normalized);

        if (strlen($normalized) >= 2 && $normalized[0] === '"' && substr($normalized, -1) === '"') {
            $normalized = substr($normalized, 1, -1);
        }

        return trim($normalized);
    }

    private function getCsvRowValue(array $row, string $columnName): string
    {
        if (array_key_exists($columnName, $row)) {
            return trim((string) $row[$columnName]);
        }

        foreach ($row as $rowColumn => $value) {
            if ($this->normalizeCsvColumnName((string) $rowColumn) !== $columnName) {
                continue;
            }

            return trim((string) $value);
        }

        return '';
    }

    private function normalizeImportValue(array $field, string $rawValue): mixed
    {
        $type = strtolower((string) ($field['type'] ?? 'text'));
        if ($rawValue === '') {
            return in_array($type, ['checkbox', 'boolean', 'boolean_select'], true) ? false : null;
        }

        return match ($type) {
            'checkbox', 'boolean', 'boolean_select' => in_array(mb_strtolower($rawValue), ['1', 'true', 'tak', 'yes'], true),
            'relation', 'date', 'datetime', 'select', 'text', 'textarea' => $rawValue,
            default => $rawValue,
        };
    }

    private function redirectWithModuleAlert(Request $request, string $moduleKey, string $type, string $message): RedirectResponse
    {
        $session = $request->getSession();
        if ($session !== null) {
            $session->set('module_alert_' . $moduleKey, ['type' => $type, 'message' => $message]);
        }

        return new RedirectResponse('/cms/modules/' . $moduleKey);
    }

    /**
     * @return array<int, string>
     */
    private function fetchLotteryIdsByType(string $lotteryType): array
    {
        return [];
    }


    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function formatStructuredListRows(array $rows, array $fields): array
    {
        $structuredListFields = [];
        foreach ($fields as $field) {
            $fieldName = trim((string) ($field['name'] ?? ''));
            $fieldType = strtolower((string) ($field['type'] ?? 'text'));
            if ($fieldName === '' || $fieldType !== 'structured_list') {
                continue;
            }

            $structuredListFields[$fieldName] = is_array($field['fields'] ?? null) ? $field['fields'] : [];
        }

        if ($structuredListFields === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($structuredListFields as $fieldName => $nestedFields) {
                $rawValue = $row[$fieldName] ?? null;
                if (!is_string($rawValue) || trim($rawValue) === '') {
                    continue;
                }

                $decoded = json_decode($rawValue, true);
                if (!is_array($decoded) || $decoded === []) {
                    continue;
                }

                $formattedItems = [];
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $parts = [];
                    foreach ($nestedFields as $nestedField) {
                        $nestedName = trim((string) ($nestedField['name'] ?? ''));
                        if ($nestedName === '' || !array_key_exists($nestedName, $item)) {
                            continue;
                        }

                        $nestedValue = trim((string) $item[$nestedName]);
                        if ($nestedValue === '') {
                            continue;
                        }

                        $nestedLabel = trim((string) ($nestedField['label'] ?? $nestedName));
                        $parts[] = sprintf('%s: %s', $nestedLabel, $nestedValue);
                    }

                    if ($parts !== []) {
                        $formattedItems[] = implode(', ', $parts);
                    }
                }

                if ($formattedItems !== []) {
                    $row[$fieldName] = implode(' | ', $formattedItems);
                }
            }
        }
        unset($row);

        return $rows;
    }

    protected function buildModuleAlertRedirect(Request $request, string $moduleKey, string $status, string $message): RedirectResponse
    {
        $session = $request->getSession();
        if ($session !== null) {
            $session->set('module_alert_' . $moduleKey, ['type' => $status, 'message' => $message]);
        }

        $query = $this->getModuleListState($request, $moduleKey);
        if (!is_array($query)) {
            $query = [];
        }

        $query['status'] = $status;
        $query['message'] = $message;

        $queryString = http_build_query($query);

        return new RedirectResponse('/cms/modules/' . $moduleKey . ($queryString !== '' ? '?' . $queryString : ''));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function storeWinnerImportLog(Request $request, array $data): ?array
    {
        $directory = $this->getWinnerImportLogDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        try {
            $logId = (new \DateTimeImmutable())->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        } catch (\Throwable) {
            $logId = (new \DateTimeImmutable())->format('Ymd-His') . '-' . substr(sha1(uniqid('', true)), 0, 8);
        }

        $details = is_array($data['details'] ?? null) ? array_values($data['details']) : [];
        $errors = is_array($data['errors'] ?? null) ? array_values($data['errors']) : [];
        $detailErrorCount = count(array_filter(
            $details,
            static fn(mixed $entry): bool => is_array($entry) && ($entry['level'] ?? 'error') === 'error'
        ));
        $user = $this->authService->user();
        $log = array_merge($data, [
            'id' => $logId,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'user_email' => $user?->getEmail() ?? '',
            'ip_address' => $request->getClientIp() ?? '',
            'error_count' => max(count($errors), $detailErrorCount),
            'details' => $details,
            'errors' => $errors,
        ]);

        $encoded = json_encode(
            $log,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($encoded)) {
            return null;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $logId . '.json';
        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            return null;
        }

        return $this->hydrateWinnerImportLog($log);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listWinnerImportLogs(int $limit = 30): array
    {
        $paths = glob($this->getWinnerImportLogDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [];
        usort($paths, static function (string $left, string $right): int {
            return (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0);
        });

        $logs = [];
        foreach (array_slice($paths, 0, $limit) as $path) {
            $logId = pathinfo($path, PATHINFO_FILENAME);
            $log = $this->loadWinnerImportLog($logId);
            if ($log !== null) {
                $logs[] = $log;
            }
        }

        return $logs;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadWinnerImportLog(string $logId): ?array
    {
        if (preg_match('/^[A-Za-z0-9-]+$/D', $logId) !== 1) {
            return null;
        }

        $path = $this->getWinnerImportLogDirectory() . DIRECTORY_SEPARATOR . $logId . '.json';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return null;
        }
        $log = json_decode($contents, true);
        if (!is_array($log) || ($log['id'] ?? null) !== $logId) {
            return null;
        }

        return $this->hydrateWinnerImportLog($log);
    }

    /**
     * @param array<string, mixed> $log
     * @return array<string, mixed>
     */
    private function hydrateWinnerImportLog(array $log): array
    {
        $createdAt = trim((string) ($log['created_at'] ?? ''));
        try {
            $log['display_created_at'] = $createdAt !== ''
                ? (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s')
                : '—';
        } catch (\Throwable) {
            $log['display_created_at'] = $createdAt !== '' ? $createdAt : '—';
        }

        foreach (['errors', 'details', 'technical_errors'] as $listKey) {
            if (!is_array($log[$listKey] ?? null)) {
                $log[$listKey] = [];
            }
        }

        return $log;
    }

    /**
     * @param array<string, mixed> $log
     */
    private function formatWinnerImportLog(array $log): string
    {
        $statusLabels = [
            'success' => 'SUKCES',
            'warning' => 'CZĘŚCIOWO',
            'error' => 'BŁĄD',
        ];
        $lines = [
            'LOG IMPORTU ZWYCIĘZCÓW',
            str_repeat('=', 80),
            'Identyfikator raportu: ' . (string) ($log['id'] ?? ''),
            'Data: ' . (string) ($log['display_created_at'] ?? $log['created_at'] ?? ''),
            'Status: ' . ($statusLabels[(string) ($log['status'] ?? '')] ?? (string) ($log['status'] ?? '')),
            'Źródło: ' . ((string) ($log['source_file'] ?? '') ?: '—'),
            'Użytkownik: ' . ((string) ($log['user_email'] ?? '') ?: '—'),
            'Rodzaj wygranej: ' . ((string) ($log['winner_kind_label'] ?? '') ?: '—'),
            'Nagroda: ' . ((string) ($log['prize_name'] ?? '') ?: (string) ($log['prize_type'] ?? '—')),
            'Losowanie: ' . ((string) ($log['drawing_label'] ?? '') ?: ((string) ($log['drawing_id'] ?? '') ?: '—')),
            sprintf(
                'Podsumowanie: dodano %d, pominięto %d, błędy %d',
                (int) ($log['imported_count'] ?? 0),
                (int) ($log['skipped_count'] ?? 0),
                (int) ($log['error_count'] ?? 0)
            ),
        ];

        $errors = is_array($log['errors'] ?? null) ? $log['errors'] : [];
        if ($errors !== []) {
            $lines[] = '';
            $lines[] = 'KOMUNIKATY BŁĘDÓW';
            $lines[] = str_repeat('-', 80);
            foreach ($errors as $error) {
                $lines[] = '- ' . trim((string) $error);
            }
        }

        $details = is_array($log['details'] ?? null) ? $log['details'] : [];
        if ($details !== []) {
            $lines[] = '';
            $lines[] = 'SZCZEGÓŁY POZYCJI';
            $lines[] = str_repeat('-', 80);
            foreach ($details as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $level = ($entry['level'] ?? 'error') === 'warning' ? 'POMINIĘTO' : 'BŁĄD';
                $ticketId = trim((string) ($entry['ticket_id'] ?? ''));
                $message = trim((string) ($entry['message'] ?? ''));
                $lines[] = sprintf('[%s]%s %s', $level, $ticketId !== '' ? ' ID ' . $ticketId . ':' : '', $message);
            }
        }

        $technicalErrors = is_array($log['technical_errors'] ?? null) ? $log['technical_errors'] : [];
        if ($technicalErrors !== []) {
            $lines[] = '';
            $lines[] = 'SZCZEGÓŁY TECHNICZNE';
            $lines[] = str_repeat('-', 80);
            foreach ($technicalErrors as $technicalError) {
                $lines[] = trim((string) $technicalError);
            }
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function getWinnerImportLogDirectory(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'winner-import-logs';
    }

    protected function recordModuleChange(Request $request, string $moduleKey, string $action, ?int $recordId, ?array $changeSet = null): void
    {
        $user = $this->authService->user();
        $log = new ModuleChangeLog();
        $log->setModuleKey($moduleKey);
        $log->setAction($action);
        $log->setRecordId($recordId);
        $log->setUser($user);
        $log->setEmail($user?->getEmail() ?? '');
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setChangedAt(new \DateTimeImmutable());
        if ($changeSet !== null && $changeSet !== []) {
            $log->setChangeSet(json_encode($changeSet, JSON_UNESCAPED_UNICODE));
        }

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $values
     * @return array<int, array<string, mixed>>
     */
    private function buildChangeSet(array $fields, array $existing, array $values): array
    {
        $changes = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            $includeInForm = $field['form'] ?? true;
            if (!$name || $includeInForm === false) {
                continue;
            }

            $type = strtolower((string) ($field['type'] ?? 'text'));
            $label = $field['label'] ?? $name;
            $oldValue = $existing[$name] ?? null;
            $newValue = $values[$name] ?? null;
            $normalizedOld = $this->normalizeLogValue($oldValue, $type);
            $normalizedNew = $this->normalizeLogValue($newValue, $type);

            if ($normalizedOld === $normalizedNew) {
                continue;
            }

            $changes[] = [
                'field' => $name,
                'label' => is_string($label) ? $label : $name,
                'from' => $normalizedOld,
                'to' => $normalizedNew,
            ];
        }

        return $changes;
    }

    private function normalizeLogValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        if (in_array($type, ['checkbox', 'boolean', 'boolean_select'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Tak' : 'Nie';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
    }
    /**
     * @param array<string, mixed> $template
     */
    protected function isPriorityEmailTemplate(array $template): bool
    {
        return filter_var($template['priority_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }


}
