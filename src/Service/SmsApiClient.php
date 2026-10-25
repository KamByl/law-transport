<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SmsApiClient
{
    private SystemSettingsProvider $settingsProvider;
    private HttpClientInterface $httpClient;

    public function __construct(SystemSettingsProvider $settingsProvider, ?HttpClientInterface $httpClient = null)
    {
        $this->settingsProvider = $settingsProvider;
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    public function send(string $to, string $message): array
    {
        $config = $this->settingsProvider->getSmsApiConfig();
        $token = $config['token'];

        if ($token === '') {
            return [
                'success' => false,
                'error' => 'Missing SMSAPI token.',
                'response' => null,
            ];
        }

        $payload = [
            'to' => $to,
            'message' => $message,
            'format' => 'json',
        ];

        if ($config['from'] !== '') {
            $payload['from'] = $config['from'];
        }

        if ($config['test']) {
            $payload['test'] = 1;
        }

        $endpoint = $config['endpoint'] !== '' ? $config['endpoint'] : 'https://api.smsapi.pl/sms.do';
        $timeout = $config['timeout'] ?? 10;
        $deliveryUrl = $config['delivery_url'] ?? '';
        $deliveryToken = $config['delivery_token'] ?? '';

        if ($deliveryUrl !== '') {
            if ($deliveryToken !== '') {
                $separator = str_contains($deliveryUrl, '?') ? '&' : '?';
                $deliveryUrl .= $separator . 'token=' . urlencode($deliveryToken);
            }

            $payload['notify_url'] = $deliveryUrl;
        }

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'body' => $payload,
                'timeout' => $timeout,
            ]);

            $data = $response->toArray(false);

            $messageId = null;
            if (isset($data['list']) && is_array($data['list']) && $data['list'] !== []) {
                $first = $data['list'][0];
                if (is_array($first)) {
                    $messageId = $first['id'] ?? null;
                }
            }

            return [
                'success' => ($data['count'] ?? 0) > 0,
                'error' => $data['error'] ?? null,
                'response' => $data,
                'message_id' => is_string($messageId) ? $messageId : null,
            ];
        } catch (TransportExceptionInterface $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
                'response' => null,
            ];
        }
    }
}
