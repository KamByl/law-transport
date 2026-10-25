<?php

namespace App\Controller;

use App\Entity\SmsLog;
use App\Service\SystemSettingsProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SmsDeliveryReportController extends BaseController
{
    public function receive(Request $request): Response
    {
        $settingsProvider = new SystemSettingsProvider($this->entityManager);
        $config = $settingsProvider->getSmsApiConfig();
        $expectedToken = trim((string) ($config['delivery_token'] ?? ''));
        if ($expectedToken === '') {
            return new Response('Delivery reports are disabled', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isTokenValid($request, $expectedToken)) {
            return new Response('Invalid token', Response::HTTP_FORBIDDEN);
        }

        $payload = $this->extractPayload($request);
        if ($payload === []) {
            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        }

        $reports = $this->normalizeReports($payload);
        if ($reports === []) {
            return new Response('Missing report data', Response::HTTP_BAD_REQUEST);
        }

        $updated = 0;
        foreach ($reports as $report) {
            if ($this->applyReport($report)) {
                $updated++;
            }
        }

        return new Response('OK', Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPayload(Request $request): array
    {
        $payload = $request->request->all();
        if ($payload !== []) {
            return $payload;
        }

        $payload = $request->query->all();
        if ($payload !== []) {
            return $payload;
        }

        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReports(array $payload): array
    {
        if (isset($payload['reports']) && is_array($payload['reports'])) {
            $reports = [];
            foreach ($payload['reports'] as $report) {
                if (is_array($report)) {
                    $reports[] = $report;
                }
            }

            return $reports;
        }

        $reports = $this->expandDelimitedPayload($payload);
        if ($reports !== []) {
            return $reports;
        }

        return [$payload];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function expandDelimitedPayload(array $payload): array
    {
        $values = [];
        $maxCount = 1;

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $values[$key] = array_values($value);
                $maxCount = max($maxCount, count($values[$key]));
                continue;
            }

            if (is_scalar($value)) {
                $stringValue = (string) $value;
                $parts = explode(',', $stringValue);
                $values[$key] = $parts;
                $maxCount = max($maxCount, count($parts));
            }
        }

        if ($maxCount <= 1) {
            return [];
        }

        $reports = [];
        for ($index = 0; $index < $maxCount; $index++) {
            $report = [];
            foreach ($values as $key => $list) {
                $report[$key] = $list[$index] ?? null;
            }
            $reports[] = $report;
        }

        return $reports;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function applyReport(array $report): bool
    {
        $messageId = $this->resolveMessageId($report);
        if ($messageId === null) {
            return false;
        }

        $repository = $this->entityManager->getRepository(SmsLog::class);
        $log = $repository->findOneBy(['smsapiId' => $messageId]);
        if (!$log) {
            return false;
        }

        $statusCode = $report['status'] ?? null;
        $statusName = $report['status_name'] ?? null;
        $statusCodeValue = is_scalar($statusCode) ? (string) $statusCode : null;
        $statusNameValue = is_scalar($statusName) ? (string) $statusName : null;
        $statusValue = null;
        if ($statusCodeValue !== '' && $statusNameValue !== '') {
            $statusValue = sprintf('%s (%s)', $statusCodeValue, $statusNameValue);
        } elseif ($statusCodeValue !== '') {
            $statusValue = $statusCodeValue;
        } elseif ($statusNameValue !== '') {
            $statusValue = $statusNameValue;
        }
        $error = $report['error'] ?? $report['error_message'] ?? null;
        $errorValue = is_scalar($error) ? (string) $error : null;
        $deliveredAt = $this->parseReportDate($report['donedate'] ?? $report['date'] ?? $report['delivered_at'] ?? null);

        $log->setDeliveryStatus($statusValue !== '' ? $statusValue : null);
        $log->setDeliveryError($errorValue !== '' ? $errorValue : null);
        if ($deliveredAt) {
            $log->setDeliveredAt($deliveredAt);
        }
        $log->setDeliveryPayload(json_encode($report, JSON_UNESCAPED_UNICODE));

        $this->entityManager->flush();

        return true;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function resolveMessageId(array $report): ?string
    {
        $candidates = [
            $report['MsgId'] ?? null,
            $report['msgid'] ?? null,
            $report['msgId'] ?? null,
            $report['id'] ?? null,
            $report['sms_id'] ?? null,
            $report['message_id'] ?? null,
            $report['messageId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function parseReportDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 0) {
                return (new \DateTimeImmutable())->setTimestamp($timestamp);
            }
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function isTokenValid(Request $request, string $expectedToken): bool
    {
        $provided = $request->headers->get('X-SMSAPI-Token')
            ?? $request->headers->get('X-Smsapi-Token')
            ?? $request->query->get('token')
            ?? $request->request->get('token');

        return is_string($provided) && hash_equals($expectedToken, $provided);
    }
}
