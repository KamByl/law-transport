<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class WinnerFormReminderService
{
    public function __construct(private readonly Mailer $mailer)
    {
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<int, array<string, mixed>>
     */
    public function findEligibleWinners(
        Connection $connection,
        array $settings,
        ?\DateTimeImmutable $now = null
    ): array {
        $now ??= new \DateTimeImmutable();
        $rows = $connection->createQueryBuilder()
            ->select(
                'winner.*',
                'prize.name AS reminder_prize_name',
                'prize.nazwa_nagrody_do_wyswietlenia AS reminder_prize_display_name',
                'prize.wartosc_nagrody AS reminder_prize_value',
                'prize.form_type AS reminder_form_type',
                'drawing.drawing_date AS reminder_drawing_date'
            )
            ->from('loteria_zwyciezcy_entries', 'winner')
            ->innerJoin('winner', 'rodzaje_nagrod_entries', 'prize', 'prize.id = winner.rodzaj_wygranej')
            ->leftJoin('winner', 'losowania_entries', 'drawing', 'drawing.id = winner.losowanie')
            ->where('prize.form_type IS NOT NULL')
            ->andWhere('prize.form_type <> :empty')
            ->andWhere('prize.form_type <> :noForm')
            ->andWhere('winner.email IS NOT NULL')
            ->andWhere('winner.email <> :empty')
            ->andWhere('winner.token_formularza IS NOT NULL')
            ->andWhere('winner.token_formularza <> :empty')
            ->setParameter('empty', '')
            ->setParameter('noForm', 'no_form')
            ->orderBy('winner.id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter(
            $rows,
            fn(array $winner): bool => $this->hasRequiredReminderData($winner)
                && !$this->isFormSubmitted($winner)
                && !$this->isFormExpired($winner, $settings, $now)
        ));
    }

    /**
     * @return array<string, string>
     */
    public function fetchTemplateOptions(Connection $connection): array
    {
        $rows = $connection->createQueryBuilder()
            ->select('id', 'name')
            ->from('email_templates')
            ->orderBy('name', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $options = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $options[$id] = $name !== '' ? $name : '#' . $id;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetchTemplate(Connection $connection, string $templateId): array|false
    {
        if ($templateId === '') {
            return false;
        }

        return $connection->createQueryBuilder()
            ->select('*')
            ->from('email_templates')
            ->where('id = :id')
            ->setParameter('id', $templateId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * @param array<string, mixed> $winner
     * @param array<string, mixed> $template
     * @return array{success: bool, error: string|null, queued?: bool}
     */
    public function sendReminder(
        array $winner,
        array $template,
        ?\DateTimeImmutable $sentAt = null,
        array $settings = []
    ): array {
        $sentAt ??= new \DateTimeImmutable();
        $recipient = trim((string) ($winner['email'] ?? ''));
        $token = trim((string) ($winner['token_formularza'] ?? ''));

        if ($recipient === '') {
            return ['success' => false, 'error' => 'Brak adresu e-mail.'];
        }
        if ($token === '') {
            return ['success' => false, 'error' => 'Brak tokenu formularza zwycięzcy.'];
        }

        $prizeName = trim((string) ($winner['reminder_prize_display_name'] ?? ''));
        if ($prizeName === '') {
            $prizeName = trim((string) ($winner['reminder_prize_name'] ?? ''));
        }

        $values = [
            'data' => $sentAt->format('d-m-Y'),
            'data_czas' => $sentAt->format('Y-m-d H:i:s'),
            'email' => $recipient,
            'telefon' => trim((string) ($winner['telefon'] ?? '')),
            'los' => trim((string) ($winner['numer_dowodu_zakupu'] ?? '')),
            'dowod_zakupu' => trim((string) ($winner['numer_dowodu_zakupu'] ?? '')),
            'nagroda' => $prizeName,
            'wartosc-nagrody' => trim((string) ($winner['reminder_prize_value'] ?? '')),
            'kod_vouchera' => '',
            'data_losowania' => (new WinnerDrawingDateResolver())->format($winner['reminder_drawing_date'] ?? null),
            'termin_formularza' => $this->formatFormExpiry($winner, $settings),
            'link_zwyciezca' => $this->mailer->absoluteUrl(
                '/loteria/zwyciezca/' . rawurlencode($token) . '#zglos'
            ),
        ];

        $subject = $this->replacePlaceholders((string) ($template['subject'] ?? ''), $values);
        $body = $this->replacePlaceholders((string) ($template['body'] ?? ''), $values);
        $bcc = trim((string) ($template['bcc'] ?? ''));
        $priority = filter_var($template['priority_email'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $this->mailer->sendWithError(
            $recipient,
            $subject,
            $body,
            true,
            $bcc !== '' ? $bcc : null,
            false,
            $priority
        );
    }

    /**
     * @param array<string, mixed> $winner
     * @param array<string, mixed> $settings
     */
    public function isFormExpired(
        array $winner,
        array $settings,
        ?\DateTimeImmutable $now = null
    ): bool {
        $expiry = $this->resolveFormExpiry($winner, $settings);

        return $expiry !== null && $expiry < ($now ?? new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $winner
     * @param array<string, mixed> $settings
     */
    public function resolveFormExpiry(array $winner, array $settings): ?\DateTimeImmutable
    {
        $deadlines = [];
        $globalDeadline = $this->parseDateTime($settings['winner_form_active_until'] ?? null);
        if ($globalDeadline !== null) {
            $deadlines[] = $globalDeadline;
        }

        $validityDays = (int) ($settings['winner_form_validity_days'] ?? 0);
        $sentAt = $this->parseDateTime($winner['email_o_wygranej_wyslany_at'] ?? null);
        if ($validityDays > 0 && $sentAt !== null) {
            try {
                $deadlines[] = $sentAt->add(new \DateInterval(sprintf('P%dD', $validityDays)));
            } catch (\Throwable $exception) {
                // Ignore an invalid legacy duration and keep any global deadline.
            }
        }

        if ($deadlines === []) {
            return null;
        }

        usort(
            $deadlines,
            static fn(\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right
        );

        return $deadlines[0]->setTime(23, 59, 59, 999999);
    }

    /**
     * @param array<string, mixed> $winner
     * @param array<string, mixed> $settings
     */
    public function formatFormExpiry(
        array $winner,
        array $settings,
        ?\DateTimeImmutable $fallbackSentAt = null
    ): string {
        if ($fallbackSentAt !== null && $this->parseDateTime($winner['email_o_wygranej_wyslany_at'] ?? null) === null) {
            $winner['email_o_wygranej_wyslany_at'] = $fallbackSentAt->format('Y-m-d H:i:s');
        }

        $expiry = $this->resolveFormExpiry($winner, $settings);

        return $expiry?->format('d.m.Y') ?? '';
    }

    /**
     * @param array<string, mixed> $winner
     */
    private function isFormSubmitted(array $winner): bool
    {
        $value = trim((string) ($winner['zgloszenie_wypelnione_at'] ?? ''));

        return $value !== ''
            && preg_match('/^0{4}-0{2}-0{2}(?:\s+0{2}:0{2}:0{2})?$/', $value) !== 1;
    }

    /**
     * @param array<string, mixed> $winner
     */
    private function hasRequiredReminderData(array $winner): bool
    {
        $formType = strtolower(trim((string) ($winner['reminder_form_type'] ?? '')));

        return $formType !== ''
            && $formType !== 'no_form'
            && trim((string) ($winner['email'] ?? '')) !== ''
            && trim((string) ($winner['token_formularza'] ?? '')) !== '';
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim((string) $value));
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function replacePlaceholders(string $template, array $values): string
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[strtolower(trim((string) $key))] = (string) ($value ?? '');
        }

        return (string) preg_replace_callback(
            '/\{\s*([a-zA-Z0-9_-]+)\s*\}/',
            static function (array $matches) use ($normalized): string {
                $key = strtolower((string) ($matches[1] ?? ''));

                return array_key_exists($key, $normalized)
                    ? $normalized[$key]
                    : (string) ($matches[0] ?? '');
            },
            $template
        );
    }
}
