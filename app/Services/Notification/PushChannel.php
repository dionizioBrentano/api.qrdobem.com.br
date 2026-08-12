<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * PushChannel — driver de Web Push do NotificationChannel.
 */
class PushChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'push';
    }

    public function isAvailable(): bool
    {
        return !empty(env('VAPID_PUBLIC_KEY')) && !empty(env('VAPID_PRIVATE_KEY'));
    }

    public function send(string $to, NotificationMessage $message): NotificationResult
    {
        if (!$this->isAvailable()) {
            return NotificationResult::failure($this->name(), $to, 'VAPID keys not configured.');
        }

        try {
            $subscriptionData = json_decode($to, true);
            
            if (!$subscriptionData || !isset($subscriptionData['endpoint'])) {
                return NotificationResult::failure($this->name(), $to, 'Invalid push subscription data.');
            }

            $subscription = Subscription::create($subscriptionData);

            $auth = [
                'VAPID' => [
                    'subject' => env('VAPID_SUBJECT', 'mailto:contato@qrdobem.com.br'),
                    'publicKey' => env('VAPID_PUBLIC_KEY'),
                    'privateKey' => env('VAPID_PRIVATE_KEY'),
                ],
            ];

            $webPush = new WebPush($auth);
            
            // Build the payload that sw.js expects
            $payload = json_encode([
                'title' => $message->subject,
                'body'  => $message->body,
                'url'   => $message->url,
            ]);

            $report = $webPush->sendOneNotification($subscription, $payload);

            if ($report->isSuccess()) {
                return NotificationResult::success($this->name(), $to);
            } else {
                return NotificationResult::failure($this->name(), $to, 'Push failed: ' . $report->getReason());
            }

        } catch (\Throwable $e) {
            Log::error('PushChannel: falha no envio', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return NotificationResult::failure($this->name(), $to, $e->getMessage());
        }
    }
}
