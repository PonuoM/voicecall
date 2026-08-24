<?php

require_once __DIR__ . '/../core/bootstrap.php';

class ErpWebhookService
{
    /**
     * Sends the AI summary of a conversation to the ERP webhook, if configured.
     *
     * @param PDO $pdo The voicecall_ai database connection
     * @param int $conversationId
     */
    public static function sendSummary(PDO $pdo, int $conversationId): void
    {
        $webhookUrl = getenv('ERP_WEBHOOK_URL');
        // If not configured, silently skip
        if (empty($webhookUrl)) {
            return;
        }

        try {
            require_once __DIR__ . '/ConversationDataService.php';
            $fullDetail = ConversationDataService::getFullDetail($pdo, $conversationId);
            if (!$fullDetail) return;

            $conv = $fullDetail['conversation'];
            $summary = $fullDetail['summary'] ?? [];
            $entities = $fullDetail['entities'] ?? [];

            $payload = [
                'conversation_id' => $conversationId,
                'audio_ref' => $conv['audio_ref'] ?? null,
                'call_date' => $conv['call_date'] ?? null,
                'call_time' => $conv['call_time'] ?? null,
                'caller_phone' => $conv['caller_phone'] ?? null,
                'receiver_phone' => $conv['receiver_phone'] ?? null,
                'erp_customer_id' => $conv['erp_customer_id'] ?? null,
                'summary' => [
                    'executive_summary' => $summary['executive_summary'] ?? null,
                    'customer_sentiment' => $summary['customer_sentiment'] ?? null,
                ],
                'sales_and_issues' => [
                    'sale_outcome' => $entities['sale_outcome'] ?? null,
                    'issue_category' => $entities['issue_category'] ?? null,
                    'complaint_or_reason' => $entities['complaint'] ?? null,
                    'customer_request' => $entities['request'] ?? null
                ]
            ];

            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode < 200 || $httpCode >= 300) {
                file_put_contents(
                    LOG_DIR . '/erp_webhook.log', 
                    date('Y-m-d H:i:s') . " ERROR sending summary for conversation {$conversationId} to {$webhookUrl}: HTTP {$httpCode} - {$response} (cURL error: {$curlError})\n", 
                    FILE_APPEND
                );
            } else {
                file_put_contents(
                    LOG_DIR . '/erp_webhook.log', 
                    date('Y-m-d H:i:s') . " SUCCESS sending summary for conversation {$conversationId} to {$webhookUrl}\n", 
                    FILE_APPEND
                );
            }
        } catch (Throwable $e) {
            file_put_contents(
                LOG_DIR . '/erp_webhook.log', 
                date('Y-m-d H:i:s') . " EXCEPTION sending summary for conversation {$conversationId}: " . $e->getMessage() . "\n", 
                FILE_APPEND
            );
        }
    }

    /**
     * Sends an error notification to the ERP webhook, if configured.
     *
     * @param PDO $pdo The voicecall_ai database connection
     * @param int $conversationId
     * @param string $errorCode
     * @param string $errorMessage
     */
    public static function sendError(PDO $pdo, int $conversationId, string $errorCode, string $errorMessage): void
    {
        $webhookUrl = getenv('ERP_WEBHOOK_URL');
        if (empty($webhookUrl)) {
            return;
        }

        try {
            $conv = fetch_one($pdo, 'SELECT * FROM conversations WHERE id = ?', [$conversationId]);
            $payload = [
                'conversation_id' => $conversationId,
                'caller_phone' => $conv['caller_phone'] ?? null,
                'receiver_phone' => $conv['receiver_phone'] ?? null,
                'status' => 'failed',
                'error_code' => $errorCode,
                'message' => $errorMessage
            ];

            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode < 200 || $httpCode >= 300) {
                file_put_contents(
                    LOG_DIR . '/erp_webhook.log', 
                    date('Y-m-d H:i:s') . " ERROR sending error status for conversation {$conversationId} to {$webhookUrl}: HTTP {$httpCode} - {$response} (cURL error: {$curlError})\n", 
                    FILE_APPEND
                );
            }
        } catch (Throwable $e) {
            file_put_contents(
                LOG_DIR . '/erp_webhook.log', 
                date('Y-m-d H:i:s') . " EXCEPTION sending error status for conversation {$conversationId}: " . $e->getMessage() . "\n", 
                FILE_APPEND
            );
        }
    }
}
