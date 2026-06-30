<?php

/**
 * Server-side mirror of index.html's gdriveListAllRecursive() + parseWavFilename() — walks the
 * same Drive folder tree and parses the same two filename formats, but runs from a cron instead
 * of blocking a browser tab. See migrations/004_gdrive_file_index.sql for why this exists: ~96%
 * of files live in one flat folder requiring 100+ sequential paginated Drive API calls, which is
 * unavoidable wherever it runs — doing it server-side on a schedule means users never wait for it.
 */
class GdriveIndexer
{
    /**
     * @return array<array{id:string,name:string}> "Company = N" folders directly under the root.
     */
    public static function discoverCompanyFolders(): array
    {
        return self::listFolder(GDRIVE_ROOT_FOLDER_ID, "mimeType='application/vnd.google-apps.folder'");
    }

    /**
     * Core sync logic shared by api/cron/sync_gdrive_index.php (CLI/cron) and the dashboard's
     * "sync now" button (Controllers/DriveIndexController.php) - one place for the walk-and-upsert
     * loop so both call paths stay in lockstep.
     * @param int|null $onlyCompanyId If set, skip every "Company = N" folder except this one -
     *   the dashboard always operates on one company at a time, so a manual sync only needs to
     *   touch that company's folder instead of every company's (much faster to wait on).
     * @param bool $forceFull Skip the modifiedTime filter below and walk every file regardless of
     *   when it was last seen - a full re-scan is much slower (measured: 115 sequential paginated
     *   calls just for company 1's ~108K-file flat folder, ~2s each, ~4 minutes total - Drive's
     *   nextPageToken pagination can't be parallelized) but is the trustworthy fallback if
     *   incremental sync is ever suspected of drifting (e.g. a file's metadata changed in a way
     *   that doesn't bump modifiedTime, or the index needs to be rebuilt from scratch).
     * @return array{files_found:int,files_upserted:int,run_id:int,companies:array<int,int>}
     */
    public static function runFullSync(PDO $pdo, ?int $onlyCompanyId = null, bool $forceFull = false): array
    {
        $runStmt = $pdo->prepare('INSERT INTO gdrive_sync_runs (status) VALUES (?)');
        $runStmt->execute(['running']);
        $runId = (int) $pdo->lastInsertId();

        $upsert = $pdo->prepare('
            INSERT INTO gdrive_file_index
                (company_folder_id, company_id, gdrive_file_id, filename, call_code, call_date, call_time,
                 caller_phone, receiver_phone, direction, size_bytes, last_seen_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                filename = VALUES(filename), call_code = VALUES(call_code), call_date = VALUES(call_date),
                call_time = VALUES(call_time), caller_phone = VALUES(caller_phone),
                receiver_phone = VALUES(receiver_phone), direction = VALUES(direction),
                size_bytes = VALUES(size_bytes), last_seen_at = NOW()
        ');

        $totalFound = 0;
        $totalUpserted = 0;
        $perCompany = [];

        try {
            $companyFolders = self::discoverCompanyFolders();

            foreach ($companyFolders as $folder) {
                if (!preg_match('/Company\s*=\s*(\d+)/i', $folder['name'], $m)) {
                    continue;
                }
                $companyId = (int) $m[1];
                if ($onlyCompanyId !== null && $companyId !== $onlyCompanyId) {
                    continue;
                }

                // Incremental by default: only ask Drive for files added/changed since this
                // *company's own* index was last refreshed (per-company, not a shared/global
                // timestamp - syncing company A must not affect what "since when" means for
                // company B, which may not have been touched in the same run at all). Anchored on
                // gdrive_file_index.last_seen_at, which only this company's own upserts ever bump.
                // Falls back to a full scan when this company has never been indexed before.
                $modifiedAfter = null;
                if (!$forceFull) {
                    $lastSeen = fetch_one($pdo, 'SELECT MAX(last_seen_at) as ts FROM gdrive_file_index WHERE company_id = ?', [$companyId]);
                    if ($lastSeen && $lastSeen['ts']) {
                        // Small safety buffer for clock skew / Drive's own indexing lag, so a file
                        // that landed right at the edge of the last sync window isn't missed.
                        $modifiedAfter = gmdate('Y-m-d\TH:i:s\Z', strtotime($lastSeen['ts']) - 600);
                    }
                }

                $files = self::scanFolderRecursive($folder['id'], $modifiedAfter);
                $totalFound += count($files);

                // One transaction per company - committing every row individually is what made
                // 100k+ upserts crawl; batching the commit is minutes vs seconds.
                $pdo->beginTransaction();
                foreach ($files as $file) {
                    $parsed = self::parseFilename($file['name']);
                    $upsert->execute([
                        $folder['id'],
                        $companyId,
                        $file['id'],
                        $file['name'],
                        $parsed['call_code'] ?? null,
                        $parsed['call_date'] ?? null,
                        $parsed['call_time'] ?? null,
                        $parsed['caller_phone'] ?? null,
                        $parsed['receiver_phone'] ?? null,
                        $parsed['direction'] ?? null,
                        isset($file['size']) ? (int) $file['size'] : null,
                    ]);
                    $totalUpserted++;
                }
                $pdo->commit();
                $perCompany[$companyId] = count($files);
            }

            $pdo->prepare('UPDATE gdrive_sync_runs SET finished_at = NOW(), files_found = ?, files_upserted = ?, status = ? WHERE id = ?')
                ->execute([$totalFound, $totalUpserted, 'completed', $runId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare('UPDATE gdrive_sync_runs SET finished_at = NOW(), files_found = ?, files_upserted = ?, status = ?, error_message = ? WHERE id = ?')
                ->execute([$totalFound, $totalUpserted, 'failed', $e->getMessage(), $runId]);
            throw $e;
        }

        return ['files_found' => $totalFound, 'files_upserted' => $totalUpserted, 'run_id' => $runId, 'companies' => $perCompany];
    }

    /**
     * @param string|null $modifiedAfter RFC3339 timestamp - if set, only wav files modified after
     *   this are returned. Subfolders are always traversed regardless (a folder's own modifiedTime
     *   doesn't reliably bump when a file is added inside it, so filtering folder traversal itself
     *   would risk missing new files in older subfolders - only the file results are filtered).
     * @return array<array{id:string,name:string,size:string}> every audio/wav file under $folderId, any depth.
     */
    public static function scanFolderRecursive(string $folderId, ?string $modifiedAfter = null): array
    {
        $items = self::listFolder($folderId, '', $modifiedAfter);
        $wavFiles = [];
        $subFolders = [];
        foreach ($items as $item) {
            if (in_array($item['mimeType'] ?? '', ['audio/wav', 'audio/x-wav'], true)) {
                $wavFiles[] = $item;
            } elseif (($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                $subFolders[] = $item;
            }
        }
        foreach ($subFolders as $sub) {
            $wavFiles = array_merge($wavFiles, self::scanFolderRecursive($sub['id'], $modifiedAfter));
        }
        return $wavFiles;
    }

    /**
     * Same two filename formats as index.html's parseWavFilename(), kept in lockstep with it.
     * @return array{call_code:?string,call_date:?string,call_time:?string,caller_phone:?string,receiver_phone:?string,direction:?string}|null
     */
    public static function parseFilename(string $filename): ?array
    {
        $name = preg_replace('/\.wav$/i', '', $filename);
        $parts = explode('_', $name);

        // Format 1: YYYYMMDD_HHMMSS_calldata-IN/OUT.wav (original PBX format)
        if (count($parts) >= 3 && preg_match('/^\d{8}$/', $parts[0]) && preg_match('/^\d{6}$/', $parts[1])) {
            $dateStr = $parts[0];
            $timeStr = $parts[1];
            $rest = rawurldecode(implode('_', array_slice($parts, 2)));
            $direction = (substr($rest, -4) === '-OUT') ? 'OUT' : 'IN';
            $noDir = ($direction === 'OUT') ? substr($rest, 0, -4) : substr($rest, 0, -3);
            preg_match_all('/\+\d+/', $noDir, $phoneMatches);
            $phones = $phoneMatches[0];
            $callParts = explode('-', $noDir);
            return [
                'call_code' => $callParts[0],
                'call_date' => substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2),
                'call_time' => substr($timeStr, 0, 2) . ':' . substr($timeStr, 2, 2) . ':' . substr($timeStr, 4, 2),
                'caller_phone' => $phones[0] ?? null,
                'receiver_phone' => $phones[1] ?? null,
                'direction' => $direction,
            ];
        }

        // Format 2: myrecordings_YYYYMMDD_HHMMSS_dir_phone_phone.wav
        $decoded = rawurldecode($name);
        if (preg_match('/(\d{8})/', $decoded, $dateMatch)) {
            $d = $dateMatch[1];
            $time = '00:00:00';
            if (preg_match('/\d{8}_?(\d{6})/', $decoded, $timeMatch)) {
                $t = $timeMatch[1];
                $time = substr($t, 0, 2) . ':' . substr($t, 2, 2) . ':' . substr($t, 4, 2);
            }
            $direction = null;
            if (preg_match('/_(out|in)_/i', $decoded, $dirMatch)) {
                $direction = strtoupper($dirMatch[1]);
            }
            preg_match_all('/\+\d{8,}/', $decoded, $phoneMatches);
            $phones = $phoneMatches[0];
            return [
                'call_code' => isset($timeMatch[1]) ? ($d . $timeMatch[1]) : $d,
                'call_date' => substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2),
                'call_time' => $time,
                'caller_phone' => isset($phones[0]) ? ('+' . str_replace('+', '', $phones[0])) : null,
                'receiver_phone' => isset($phones[1]) ? ('+' . str_replace('+', '', $phones[1])) : null,
                'direction' => $direction,
            ];
        }

        return null;
    }

    /**
     * @param string|null $modifiedAfter RFC3339 timestamp. When set, folders are always returned
     *   (so the caller can keep traversing) but non-folder items are restricted to ones modified
     *   after this time - see scanFolderRecursive()'s docblock for why folders aren't filtered too.
     * @return array<array{id:string,name:string,mimeType:string,size:?string}>
     */
    private static function listFolder(string $folderId, string $extraQuery = '', ?string $modifiedAfter = null): array
    {
        $q = "'{$folderId}' in parents and trashed=false";
        if ($extraQuery) {
            $q .= " and {$extraQuery}";
        }
        if ($modifiedAfter) {
            $q .= " and (mimeType='application/vnd.google-apps.folder' or modifiedTime > '{$modifiedAfter}')";
        }

        $all = [];
        $pageToken = '';
        do {
            $url = 'https://www.googleapis.com/drive/v3/files?q=' . urlencode($q)
                . '&key=' . GDRIVE_API_KEY
                . '&fields=' . urlencode('files(id,name,size,mimeType),nextPageToken')
                . '&pageSize=1000&orderBy=name';
            if ($pageToken) {
                $url .= '&pageToken=' . urlencode($pageToken);
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CAINFO => __DIR__ . '/../certs/cacert.pem',
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $httpCode !== 200) {
                throw new RuntimeException("Drive list failed for folder {$folderId} (HTTP {$httpCode})");
            }
            $decoded = json_decode($body, true);
            $all = array_merge($all, $decoded['files'] ?? []);
            $pageToken = $decoded['nextPageToken'] ?? '';
        } while ($pageToken);

        return $all;
    }
}
