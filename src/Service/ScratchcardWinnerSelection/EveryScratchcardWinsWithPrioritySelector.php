<?php

namespace App\Service\ScratchcardWinnerSelection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

class EveryScratchcardWinsWithPrioritySelector
{
    /**
     * @param array<string, mixed> $ticket
     * @return array{is_winner: bool, schedule: array<string, mixed>|null, schedule_win_at: \DateTimeImmutable|null, diff_microseconds: int|null, assigned_schedule_id: int|null, clicked_at: \DateTimeImmutable}
     */
    public function selectAndGrant(
        Connection $connection,
        string $scheduleTable,
        array $ticket,
        bool $isMySqlPlatform
    ): array {
        $clickedAt = new \DateTimeImmutable();
        $bestSchedule = null;
        $bestScheduleWinAt = null;
        $bestDiffMicros = null;

        if ($isMySqlPlatform) {
            $scheduleSql = sprintf(
                "SELECT s.*, TIMESTAMPDIFF(MICROSECOND, s.win_at, NOW(6)) AS diff_microseconds
                FROM %s s
                WHERE s.win_at <= NOW(6) AND (s.granted IS NULL OR s.granted = 0)
                ORDER BY CASE WHEN s.priority_prize IS NULL THEN 0 WHEN s.priority_prize = 1 THEN 1 ELSE 0 END DESC, s.win_at ASC, s.id ASC
                LIMIT 1
                FOR UPDATE",
                $scheduleTable
            );

            $bestSchedule = $connection->executeQuery($scheduleSql)->fetchAssociative() ?: null;
            if ($bestSchedule !== null) {
                $bestScheduleWinAt = $this->parseDateTime($bestSchedule['win_at'] ?? null);
                $bestDiffMicros = (int) ($bestSchedule['diff_microseconds'] ?? 0);
            }
        } else {
            $scheduledRows = $connection->createQueryBuilder()
                ->select('*')
                ->from($scheduleTable)
                ->orderBy('win_at', 'ASC')
                ->addOrderBy('id', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            $clickedAt = new \DateTimeImmutable();
            foreach ($scheduledRows as $row) {
                $winAt = $this->parseDateTime($row['win_at'] ?? null);
                if ($winAt === null || $winAt > $clickedAt || $this->isTruthy($row['granted'] ?? null)) {
                    continue;
                }
                $rowDiffMicros = $this->elapsedMicroseconds($clickedAt, $winAt);
                if ($bestSchedule === null || $this->isPriorityPrize($row) > $this->isPriorityPrize($bestSchedule)) {
                    $bestDiffMicros = $rowDiffMicros;
                    $bestSchedule = $row;
                    $bestScheduleWinAt = $winAt;
                }
            }
        }

        $isWinner = false;
        $assignedScheduleId = null;
        if ($bestSchedule !== null) {
            $assignedScheduleId = (int) ($bestSchedule['id'] ?? 0);
            if ($assignedScheduleId > 0) {
                $isWinner = $this->grantSchedule(
                    $connection,
                    $scheduleTable,
                    $ticket,
                    $assignedScheduleId,
                    $clickedAt,
                    $isMySqlPlatform
                ) === 1;
            }
        }

        return [
            'is_winner' => $isWinner,
            'schedule' => $bestSchedule,
            'schedule_win_at' => $bestScheduleWinAt,
            'diff_microseconds' => $bestDiffMicros,
            'assigned_schedule_id' => $assignedScheduleId,
            'clicked_at' => $clickedAt,
        ];
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function grantSchedule(
        Connection $connection,
        string $scheduleTable,
        array $ticket,
        int $assignedScheduleId,
        \DateTimeImmutable $clickedAt,
        bool $isMySqlPlatform
    ): int {
        $grantedToTicketEntryId = (int) ($ticket['id'] ?? 0);
        $grantedToSubmissionId = (int) ($ticket['zgloszenie'] ?? 0);

        if ($isMySqlPlatform) {
            $scheduleUpdateSql = sprintf(
                'UPDATE %s SET granted = 1, granted_to_ticket_id = :ticketEntryId, granted_to_submission_id = :submissionId, granted_at = NOW(6)
                WHERE id = :id AND (granted IS NULL OR granted = 0)',
                $scheduleTable
            );
            $parameters = [
                'ticketEntryId' => $grantedToTicketEntryId > 0 ? $grantedToTicketEntryId : null,
                'submissionId' => $grantedToSubmissionId > 0 ? $grantedToSubmissionId : null,
                'id' => $assignedScheduleId,
            ];
        } else {
            $scheduleUpdateSql = sprintf(
                'UPDATE %s SET granted = 1, granted_to_ticket_id = :ticketEntryId, granted_to_submission_id = :submissionId, granted_at = :grantedAt
                WHERE id = :id AND (granted = 0 OR granted IS NULL)',
                $scheduleTable
            );
            $parameters = [
                'ticketEntryId' => $grantedToTicketEntryId > 0 ? $grantedToTicketEntryId : null,
                'submissionId' => $grantedToSubmissionId > 0 ? $grantedToSubmissionId : null,
                'grantedAt' => $clickedAt->format('Y-m-d H:i:s'),
                'id' => $assignedScheduleId,
            ];
        }

        $types = [
            'ticketEntryId' => $grantedToTicketEntryId > 0 ? ParameterType::INTEGER : ParameterType::NULL,
            'submissionId' => $grantedToSubmissionId > 0 ? ParameterType::INTEGER : ParameterType::NULL,
            'id' => ParameterType::INTEGER,
        ];
        if (!$isMySqlPlatform) {
            $types['grantedAt'] = ParameterType::STRING;
        }

        return $connection->executeStatement($scheduleUpdateSql, $parameters, $types);
    }

    private function isPriorityPrize(array $row): int
    {
        return $this->isTruthy($row['priority_prize'] ?? null) ? 1 : 0;
    }

    private function isTruthy(mixed $raw): bool
    {
        return in_array(mb_strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on', 'tak'], true);
    }

    private function parseDateTime(mixed $raw): ?\DateTimeImmutable
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function elapsedMicroseconds(\DateTimeImmutable $later, \DateTimeImmutable $earlier): int
    {
        $laterMicros = ((int) $later->format('U') * 1000000) + (int) $later->format('u');
        $earlierMicros = ((int) $earlier->format('U') * 1000000) + (int) $earlier->format('u');

        return max(0, $laterMicros - $earlierMicros);
    }
}
