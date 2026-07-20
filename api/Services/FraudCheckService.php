<?php

/**
 * Deterministic fraud cross-check for payment channels mentioned in a call.
 *
 * Division of labor: UnifiedPipelineAgent (LLM) only EXTRACTS payment-channel mentions from the
 * transcript (fraud_signals.payment_channels) — it never decides whether one is fraudulent.
 * This service computes the verdict by comparing the detected number against the company's
 * official accounts in primacom_mini_erp.bank_account, entirely in PHP/SQL, so a model
 * hallucination can't accuse anyone. A regex pass over the raw transcript backstops numbers the
 * LLM missed.
 *
 * Every row written to fraud_checks is a suspicious item for HUMAN review (review_status
 * pending/confirmed/dismissed), not an automatic accusation — phone/ERP matching in this system
 * is best-effort by design.
 */
class FraudCheckService
{
    /**
     * @param array<array<string,mixed>> $paymentChannels from UnifiedPipelineAgent fraud_signals
     * @return array{inserted:int,flagged:int,critical:int}
     */
    public static function run(PDO $pdo, PDO $erp, int $conversationId, int $companyId, array $paymentChannels, string $transcriptText): array
    {
        // Idempotent: reprocessing a conversation replaces its previous findings.
        $pdo->prepare('DELETE FROM fraud_checks WHERE conversation_id = ?')->execute([$conversationId]);

        $conv = self::loadConversation($pdo, $conversationId);
        $companyAccounts = self::loadCompanyAccounts($erp, $companyId);
        $callPhoneDigits = self::callPhoneDigitForms($conv);

        $rows = [];
        $seenDigits = [];

        foreach ($paymentChannels as $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $row = self::evaluateChannel($ch, $companyAccounts, $callPhoneDigits);
            if ($row === null) {
                continue;
            }
            $row['source'] = 'llm';
            if ($row['detected_value'] !== null && ctype_digit($row['detected_value'])) {
                $seenDigits[$row['detected_value']] = true;
            }
            $rows[] = $row;
        }

        // Safety net: digit runs in the transcript the LLM did not report. Deliberately low risk —
        // most are alternate contact numbers — but they surface in the review queue instead of
        // vanishing.
        foreach (self::scanDigitRuns($transcriptText) as $digits) {
            if (isset($seenDigits[$digits]) || in_array($digits, $callPhoneDigits, true)) {
                continue;
            }
            $seenDigits[$digits] = true;
            $match = self::matchCompanyAccount($digits, $companyAccounts);
            if ($match) {
                $rows[] = [
                    'status' => 'clear',
                    'risk_level' => 'low',
                    'channel_type' => 'number_sequence',
                    'detected_value' => $digits,
                    'bank_name' => $match['bank'],
                    'spoken_by' => 'unknown',
                    'purpose' => null,
                    'evidence' => null,
                    'explanation' => "ชุดตัวเลขใน transcript ตรงกับบัญชีบริษัท: {$match['bank']} ({$match['bank_number']})" . ($match['is_active'] ? '' : ' (บัญชีปิดใช้งานแล้ว)'),
                    'matched_bank_account_id' => $match['id'],
                    'source' => 'regex',
                ];
            } else {
                $rows[] = [
                    'status' => 'flagged',
                    'risk_level' => 'low',
                    'channel_type' => 'number_sequence',
                    'detected_value' => $digits,
                    'bank_name' => null,
                    'spoken_by' => 'unknown',
                    'purpose' => null,
                    'evidence' => self::excerptAround($transcriptText, $digits),
                    'explanation' => 'พบชุดตัวเลข 10-13 หลักใน transcript ที่ AI ไม่ได้รายงานเป็นช่องทางรับเงิน และไม่ตรงกับบัญชีบริษัทหรือเบอร์คู่สายนี้ — อาจเป็นเบอร์ติดต่อสำรองหรือเลขบัญชี ควรตรวจสอบบริบท',
                    'matched_bank_account_id' => null,
                    'source' => 'regex',
                ];
            }
        }

        $insert = $pdo->prepare('
            INSERT INTO fraud_checks
              (conversation_id, company_id, check_type, source, status, risk_level, channel_type,
               detected_value, bank_name, spoken_by, purpose, evidence, explanation, matched_bank_account_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $flagged = 0;
        $critical = 0;
        foreach ($rows as $r) {
            $insert->execute([
                $conversationId,
                $companyId,
                'payment_channel',
                $r['source'],
                $r['status'],
                $r['risk_level'],
                $r['channel_type'],
                $r['detected_value'] !== null ? mb_substr($r['detected_value'], 0, 255) : null,
                $r['bank_name'] !== null ? mb_substr($r['bank_name'], 0, 100) : null,
                $r['spoken_by'],
                $r['purpose'],
                $r['evidence'],
                $r['explanation'],
                $r['matched_bank_account_id'],
            ]);
            if ($r['status'] === 'flagged') {
                $flagged++;
                if ($r['risk_level'] === 'critical') {
                    $critical++;
                }
            }
        }

        return ['inserted' => count($rows), 'flagged' => $flagged, 'critical' => $critical];
    }

    /** @return array<string,mixed>|null null = mention too weak to record (e.g. no usable value) */
    private static function evaluateChannel(array $ch, array $companyAccounts, array $callPhoneDigits): ?array
    {
        $type = strtolower(trim((string) ($ch['channel_type'] ?? '')));
        if (!in_array($type, ['bank_account', 'promptpay', 'line_id', 'wallet', 'other'], true)) {
            $type = 'other';
        }
        $spokenBy = strtolower(trim((string) ($ch['spoken_by'] ?? '')));
        if (!in_array($spokenBy, ['employee', 'customer'], true)) {
            $spokenBy = 'unknown';
        }

        $rawValue = trim((string) ($ch['normalized_value'] ?? $ch['raw_mention'] ?? ''));
        $isNumeric = in_array($type, ['bank_account', 'promptpay'], true);
        $value = $isNumeric ? preg_replace('/\D+/', '', $rawValue) : $rawValue;

        if ($value === '' || ($isNumeric && strlen($value) < 6)) {
            return null; // too short/garbled to compare or review meaningfully
        }

        $base = [
            'channel_type' => $type,
            'detected_value' => $value,
            'bank_name' => isset($ch['bank_name']) && is_string($ch['bank_name']) && trim($ch['bank_name']) !== '' ? trim($ch['bank_name']) : null,
            'spoken_by' => $spokenBy,
            'purpose' => isset($ch['purpose']) && is_string($ch['purpose']) ? $ch['purpose'] : null,
            'evidence' => isset($ch['evidence']) && is_string($ch['evidence']) ? $ch['evidence'] : null,
            'matched_bank_account_id' => null,
        ];

        if ($isNumeric) {
            $match = self::matchCompanyAccount($value, $companyAccounts);
            if ($match) {
                return array_merge($base, [
                    'status' => 'clear',
                    'risk_level' => 'low',
                    'matched_bank_account_id' => $match['id'],
                    'explanation' => "ตรงกับบัญชีบริษัท: {$match['bank']} ({$match['bank_number']})" . ($match['is_active'] ? '' : ' (บัญชีปิดใช้งานแล้ว — ควรสอบถามว่าทำไมยังแจ้งบัญชีนี้)'),
                ]);
            }

            if ($spokenBy === 'employee') {
                $note = in_array($value, $callPhoneDigits, true)
                    ? ' เลขนี้ตรงกับเบอร์โทรของคู่สายนี้เอง (พร้อมเพย์เข้าเบอร์ส่วนตัว?)'
                    : '';
                return array_merge($base, [
                    'status' => 'flagged',
                    'risk_level' => 'critical',
                    'explanation' => 'พนักงานแจ้งเลขบัญชี/พร้อมเพย์ที่ไม่ตรงกับบัญชีบริษัทในระบบ ERP' . $note . ' — ต้องตรวจสอบโดยด่วน',
                ]);
            }

            return array_merge($base, [
                'status' => 'flagged',
                'risk_level' => $spokenBy === 'customer' ? 'low' : 'high',
                'explanation' => $spokenBy === 'customer'
                    ? 'ลูกค้าเป็นผู้แจ้งเลขบัญชี (เช่น กรณีขอรับเงินคืน) — ไม่ตรงกับบัญชีบริษัท ควรดูบริบทว่าเหมาะสมหรือไม่'
                    : 'พบเลขบัญชี/พร้อมเพย์ที่ไม่ตรงกับบัญชีบริษัท แต่ระบุผู้พูดไม่ได้ — ควรฟังไฟล์เสียงยืนยัน',
            ]);
        }

        // Non-numeric channels (LINE ID, wallet, other) can't be verified against ERP — risk is
        // driven by who offered them. Official company LINE OA mentions will show up here too;
        // reviewers dismiss those once and can see the pattern.
        if ($spokenBy === 'employee') {
            return array_merge($base, [
                'status' => 'flagged',
                'risk_level' => 'medium',
                'explanation' => 'พนักงานให้ช่องทางติดต่อ/รับเงินที่ตรวจสอบกับระบบไม่ได้ (เช่น LINE ส่วนตัว, wallet) — ตรวจสอบว่าเป็นช่องทางทางการของบริษัทหรือไม่',
            ]);
        }
        return array_merge($base, [
            'status' => 'flagged',
            'risk_level' => 'low',
            'explanation' => 'มีการพูดถึงช่องทางโอนเงิน/ติดต่อนอกระบบ โดยลูกค้าหรือระบุผู้พูดไม่ได้ — บันทึกไว้เพื่อตรวจสอบบริบท',
        ]);
    }

    /**
     * @return array<array{id:int,bank:string,bank_number:string,digits:string,is_active:bool}>
     */
    private static function loadCompanyAccounts(PDO $erp, int $companyId): array
    {
        // Inactive accounts stay in the match list: money sent to a company account that was
        // merely deactivated is not personal-account fraud (the verdict text notes it instead).
        $stmt = $erp->prepare('SELECT id, bank, bank_number, is_active FROM bank_account WHERE company_id = ? AND deleted_at IS NULL');
        $stmt->execute([$companyId]);
        $accounts = [];
        foreach ($stmt->fetchAll() as $row) {
            $digits = preg_replace('/\D+/', '', (string) $row['bank_number']);
            if (strlen($digits) < 6) {
                continue; // rows like "QR Code" / "-" carry no comparable number
            }
            $accounts[] = [
                'id' => (int) $row['id'],
                'bank' => (string) $row['bank'],
                'bank_number' => (string) $row['bank_number'],
                'digits' => $digits,
                'is_active' => (bool) $row['is_active'],
            ];
        }
        return $accounts;
    }

    /**
     * Exact match, or suffix containment (≥8 shared digits) — spoken account numbers often drop
     * leading digits or the STT drops a leading zero.
     * @return array{id:int,bank:string,bank_number:string,digits:string,is_active:bool}|null
     */
    private static function matchCompanyAccount(string $digits, array $companyAccounts): ?array
    {
        foreach ($companyAccounts as $acc) {
            if ($digits === $acc['digits']) {
                return $acc;
            }
            if (strlen($digits) >= 8 && strlen($acc['digits']) >= 8) {
                if (substr($digits, -8) === substr($acc['digits'], -8)
                    && (strpos($acc['digits'], $digits) !== false || strpos($digits, $acc['digits']) !== false)) {
                    return $acc;
                }
            }
        }
        return null;
    }

    private static function loadConversation(PDO $pdo, int $conversationId): array
    {
        $stmt = $pdo->prepare('SELECT caller_phone, receiver_phone FROM conversations WHERE id = ?');
        $stmt->execute([$conversationId]);
        return $stmt->fetch() ?: ['caller_phone' => null, 'receiver_phone' => null];
    }

    /**
     * Digit-only forms of both call parties' numbers, in local and E.164-stripped variants,
     * so a "payment number" that is just someone repeating a call-party phone isn't flagged.
     * @return string[]
     */
    private static function callPhoneDigitForms(array $conv): array
    {
        $forms = [];
        foreach ([$conv['caller_phone'] ?? null, $conv['receiver_phone'] ?? null] as $phone) {
            $digits = preg_replace('/\D+/', '', (string) $phone);
            if ($digits === '') {
                continue;
            }
            $forms[] = $digits;
            if (strpos($digits, '66') === 0 && strlen($digits) >= 10) {
                $forms[] = '0' . substr($digits, 2); // +668x... -> 08x...
            } elseif (strpos($digits, '0') === 0 && strlen($digits) >= 9) {
                $forms[] = '66' . substr($digits, 1);
            }
        }
        return array_values(array_unique($forms));
    }

    /**
     * Contiguous digit runs of 10-13 digits (allowing space/dash separators) — the length range
     * of Thai phone/PromptPay (10), bank accounts (10-12), and citizen-ID PromptPay (13).
     * @return string[]
     */
    private static function scanDigitRuns(string $text): array
    {
        if (!preg_match_all('/(?<![\d])\d(?:[ \-]?\d){8,14}(?![\d])/u', $text, $m)) {
            return [];
        }
        $runs = [];
        foreach ($m[0] as $raw) {
            $digits = preg_replace('/\D+/', '', $raw);
            $len = strlen($digits);
            if ($len >= 10 && $len <= 13) {
                $runs[$digits] = true;
            }
        }
        return array_keys($runs);
    }

    /** Short transcript excerpt around the first appearance of a digit run (for review context). */
    private static function excerptAround(string $text, string $digits): ?string
    {
        // The run may appear with separators; search for its first 6 digits allowing separators.
        $needle = implode('[ \-]?', str_split(substr($digits, 0, 6)));
        if (!preg_match('/' . $needle . '/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $byteOffset = $m[0][1];
        $prefix = substr($text, 0, $byteOffset);
        $start = mb_strlen($prefix);
        $from = max(0, $start - 60);
        return ($from > 0 ? '…' : '') . mb_substr($text, $from, 160) . '…';
    }
}
