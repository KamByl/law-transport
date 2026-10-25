<?php

namespace App\Controller;

use App\Service\WinnerDrawingAvailabilityService;
use App\Service\WinnerPrizeCodeAssignmentService;
use Doctrine\DBAL\Connection;

final class ZwyciezcyModuleCreateActionController extends ModuleCreateActionController
{
    /** @var array<string, bool> */
    private array $codeAssignmentStatusCache = [];
    private bool $defaultStatusLoaded = false;
    private string $defaultStatusId = '';
    /** @var array<int, string>|null */
    private ?array $winnerColumns = null;

    /**
     * @param array<string, mixed> $values
     * @return array{values: array<string, mixed>, errors: array<int, string>}
     */
    protected function prepareWinnerCreateValues(Connection $connection, array $values, string $moduleKey): array
    {
        $errors = [];

        $preloadedTicket = is_array($values['_winner_import_ticket'] ?? null)
            ? $values['_winner_import_ticket']
            : null;
        $preloadedSubmission = is_array($values['_winner_import_submission'] ?? null)
            ? $values['_winner_import_submission']
            : null;
        $preloadedPrizeType = is_array($values['_winner_import_prize_type'] ?? null)
            ? $values['_winner_import_prize_type']
            : null;
        $skipDrawingLookup = filter_var($values['_winner_import_skip_drawing_lookup'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $skipExistingWinnerLookup = filter_var($values['_winner_import_skip_existing_winner_lookup'] ?? false, FILTER_VALIDATE_BOOLEAN);
        unset(
            $values['_winner_import_ticket'],
            $values['_winner_import_submission'],
            $values['_winner_import_prize_type'],
            $values['_winner_import_skip_drawing_lookup'],
            $values['_winner_import_skip_existing_winner_lookup']
        );

        $prizeTypeId = trim((string) ($values['rodzaj_wygranej'] ?? ''));
        $winnerKind = trim((string) ($values['rodzaj'] ?? ''));
        $isScratchWinner = $winnerKind === 'zdrapka';
        $ticketId = trim((string) ($values[$isScratchWinner ? 'zdrapka_w_loterii' : 'kupon_w_loterii'] ?? ''));
        $drawingId = trim((string) ($values['losowanie'] ?? ''));

        if ($prizeTypeId === '') {
            $errors[] = 'Wybierz rodzaj nagrody.';
        }
        if (!in_array($winnerKind, ['los', 'zdrapka'], true)) {
            $errors[] = 'Wybierz rodzaj wygranej.';
        }
        if ($ticketId === '') {
            $errors[] = $isScratchWinner ? 'Wybierz zdrapkę z loterii.' : 'Wybierz los w loterii.';
        }
        if ($winnerKind === 'los' && $drawingId === '') {
            $errors[] = 'Wybierz losowanie, w którym wylosowano nagrodę.';
        }
        if ($errors !== []) {
            return ['values' => $values, 'errors' => $errors];
        }

        if ($isScratchWinner) {
            $values['losowanie'] = null;
        } elseif (!$skipDrawingLookup) {
            $drawing = $connection->createQueryBuilder()
                ->select('id', 'drawing_date')
                ->from('losowania_entries')
                ->where('id = :drawing_id')
                ->setParameter('drawing_id', $drawingId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            if (!is_array($drawing)) {
                return ['values' => $values, 'errors' => ['Nie znaleziono wybranego losowania.']];
            }
            if (!(new WinnerDrawingAvailabilityService())->isDrawingDateSelectable($drawing['drawing_date'] ?? null)) {
                return ['values' => $values, 'errors' => ['Wybrane losowanie jest poza dozwolonym terminem.']];
            }
            $values['losowanie'] = $drawingId;
        } else {
            $values['losowanie'] = $drawingId;
        }

        $ticketTable = $isScratchWinner ? 'loteria_zdrapki_entries' : 'loteria_losy_entries';

        $ticket = $preloadedTicket;
        if (!is_array($ticket) || (string) ($ticket['id'] ?? '') !== $ticketId) {
            $ticket = $connection->createQueryBuilder()
                ->select('*')
                ->from($ticketTable)
                ->where('id = :id')
                ->setParameter('id', $ticketId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        }

        if (!is_array($ticket)) {
            return ['values' => $values, 'errors' => [$isScratchWinner ? 'Nie znaleziono wskazanej zdrapki.' : 'Nie znaleziono wskazanego losu.']];
        }

        $lotteryId = trim((string) ($ticket['loteria'] ?? ''));
        if ($lotteryId !== '') {
            $values['loteria'] = $lotteryId;
        }

        $prizeType = $preloadedPrizeType;
        if (!is_array($prizeType) || (string) ($prizeType['id'] ?? '') !== $prizeTypeId) {
            $prizeTypeColumns = array_keys($connection->createSchemaManager()->listTableColumns('rodzaje_nagrod_entries'));
            $prizeTypeSelectColumns = ['id', 'name'];
            foreach (['loteria', 'status_po_wygraniu_nagrody'] as $optionalColumn) {
                if (in_array($optionalColumn, $prizeTypeColumns, true)) {
                    $prizeTypeSelectColumns[] = $optionalColumn;
                }
            }

            $prizeType = $connection->createQueryBuilder()
                ->select(...$prizeTypeSelectColumns)
                ->from('rodzaje_nagrod_entries')
                ->where('id = :id')
                ->setParameter('id', $prizeTypeId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        }

        if (!is_array($prizeType)) {
            return ['values' => $values, 'errors' => ['Nie znaleziono wskazanego rodzaju nagrody.']];
        }

        $prizeLotteryId = trim((string) ($prizeType['loteria'] ?? ''));
        if ($lotteryId !== '' && $prizeLotteryId !== '' && $prizeLotteryId !== $lotteryId) {
            return ['values' => $values, 'errors' => [$isScratchWinner
                ? 'Wybrany rodzaj nagrody nie jest przypisany do loterii wybranej zdrapki.'
                : 'Wybrany rodzaj nagrody nie jest przypisany do loterii wybranego losu.',
            ]];
        }

        if (!$skipExistingWinnerLookup) {
            $winnerTicketColumn = $isScratchWinner ? 'zdrapka_w_loterii' : 'kupon_w_loterii';
            $existingWinnerId = $connection->createQueryBuilder()
                ->select('id')
                ->from('loteria_zwyciezcy_entries')
                ->where($winnerTicketColumn . ' = :ticket_id')
                ->andWhere('rodzaj_wygranej = :prize_type_id')
                ->setParameter('ticket_id', $ticketId)
                ->setParameter('prize_type_id', $prizeTypeId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($existingWinnerId !== false) {
                return ['values' => $values, 'errors' => [$isScratchWinner
                    ? 'Ta zdrapka ma już przypisany wybrany rodzaj nagrody.'
                    : 'Ten los ma już przypisany wybrany rodzaj nagrody.',
                ]];
            }
        }

        $normalizePrizeName = static function (string $value): string {
            $normalized = trim(mb_strtolower($value));
            if ($normalized === '') {
                return '';
            }

            return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        };

        $expectedPrizeName = $normalizePrizeName((string) ($prizeType['name'] ?? ''));
        $ticketPrizeName = $normalizePrizeName((string) ($ticket['prize_name'] ?? ''));
        if ($expectedPrizeName === '') {
            return ['values' => $values, 'errors' => [$isScratchWinner
                ? 'Wybrana zdrapka nie pasuje do wskazanego rodzaju nagrody.'
                : 'Wybrany los nie pasuje do wskazanego rodzaju nagrody.',
            ]];
        }

        if ($isScratchWinner && $ticketPrizeName !== '' && $expectedPrizeName !== $ticketPrizeName) {
            return ['values' => $values, 'errors' => ['Wybrana zdrapka nie pasuje do wskazanego rodzaju nagrody.']];
        }

        $submissionId = (int) ($ticket['zgloszenie'] ?? 0);
        if ($submissionId <= 0) {
            return ['values' => $values, 'errors' => [$isScratchWinner
                ? 'Wybrana zdrapka nie ma powiązanego zgłoszenia.'
                : 'Wybrany los nie ma powiązanego zgłoszenia.',
            ]];
        }

        $submission = $preloadedSubmission;
        if (!is_array($submission) || (int) ($submission['id'] ?? 0) !== $submissionId) {
            $submission = $connection->createQueryBuilder()
                ->select('*')
                ->from('loteria_zgloszenia_entries')
                ->where('id = :id')
                ->setParameter('id', $submissionId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        }

        if (!is_array($submission)) {
            return ['values' => $values, 'errors' => [$isScratchWinner
                ? 'Nie znaleziono powiązanego zgłoszenia dla wybranej zdrapki.'
                : 'Nie znaleziono powiązanego zgłoszenia dla wybranego losu.',
            ]];
        }

        $values['zgloszenie'] = $submissionId;
        $values['imie'] = trim((string) ($submission['imie'] ?? ''));
        $values['nazwisko'] = trim((string) ($submission['nazwisko'] ?? ''));
        $values['email'] = trim((string) (($submission['email'] ?? '') !== '' ? $submission['email'] : ($ticket['email'] ?? '')));
        $values['telefon'] = trim((string) (($submission['phone'] ?? '') !== '' ? $submission['phone'] : ($ticket['phone'] ?? '')));
        $values['centrum_handlowe'] = ($submission['centrum_handlowe'] ?? null) ?: ($ticket['centrum_handlowe'] ?? null);
        $values['data_zgloszenia'] = ($submission['submitted_at'] ?? null) ?: ($ticket['submitted_at'] ?? null);
        $values['numer_dowodu_zakupu'] = trim((string) ($submission['purchase_document_number'] ?? ''));
        $purchaseAmount = $submission['purchase_amount'] ?? null;
        $values['kwota_z_rachunku'] = is_string($purchaseAmount) ? trim($purchaseAmount) : $purchaseAmount;
        $values['token_formularza'] = bin2hex(random_bytes(32));
        $initialStatus = trim((string) ($prizeType['status_po_wygraniu_nagrody'] ?? ''));
        if ($initialStatus === '') {
            if (!$this->defaultStatusLoaded) {
                $defaultStatus = $connection->createQueryBuilder()
                    ->select('id')
                    ->from('ustawienia_statusow')
                    ->orderBy('position', 'ASC')
                    ->addOrderBy('id', 'ASC')
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchOne();
                $this->defaultStatusId = is_scalar($defaultStatus) ? trim((string) $defaultStatus) : '';
                $this->defaultStatusLoaded = true;
            }
            $initialStatus = $this->defaultStatusId;
        }
        if ($initialStatus !== '') {
            $values['stan'] = $initialStatus;
        }

        $values['email_o_wygranej_wyslany_at'] = null;
        if ($initialStatus !== '') {
            if (!array_key_exists($initialStatus, $this->codeAssignmentStatusCache)) {
                $this->codeAssignmentStatusCache[$initialStatus] = (new WinnerPrizeCodeAssignmentService())
                    ->shouldAssignCodesForStatus($connection, $initialStatus);
            }
            if ($this->codeAssignmentStatusCache[$initialStatus]) {
                $values['email_o_wygranej_wyslany_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            }
        }
        $values['data_ponownego_wyslania_formularza'] = null;
        $values['email_o_poprawnej_weryfikacji_wyslany_at'] = null;
        $values['czas_przyznanej_reklamacji'] = null;
        $values['zgloszenie_wypelnione_at'] = null;
        $values['zgloszenie_ip'] = null;
        $values['zgloszenie_user_agent'] = null;
        $values['rachunek'] = null;
        $values['kupon_w_loterii'] = $isScratchWinner ? null : $ticketId;
        $values['zdrapka_w_loterii'] = $isScratchWinner ? $ticketId : null;

        if ($this->winnerColumns === null) {
            $this->winnerColumns = array_keys($connection->createSchemaManager()->listTableColumns('loteria_zwyciezcy_entries'));
        }
        if (in_array('kwota_odczytana', $this->winnerColumns, true)) {
            $values['kwota_odczytana'] = null;
        }

        return ['values' => $values, 'errors' => []];
    }
}
