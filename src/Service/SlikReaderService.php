<?php

namespace App\Service;

use App\Repository\LeadRepository;
use App\Repository\SlikFileRepository;
use App\Repository\SlikReaderResultRepository;
use App\Validator\SlikReaderValidator;

class SlikReaderService {
    private LeadRepository $leadRepo;
    private SlikFileRepository $fileRepo;
    private SlikReaderResultRepository $resultRepo;
    private SlikParserService $parserService;

    public function __construct() {
        $this->leadRepo = new LeadRepository();
        $this->fileRepo = new SlikFileRepository();
        $this->resultRepo = new SlikReaderResultRepository();
        $this->parserService = new SlikParserService();
    }

    public function processUpload(array $fileInfo, string $leadId, string $identityNumber, string $name, string $userId): array {
        // 1. Validate file
        $fileError = SlikReaderValidator::validateUploadedFile($fileInfo);
        if ($fileError) {
            return [
                'success' => false,
                'http' => $fileError['http'],
                'code' => $fileError['code'],
                'message' => $fileError['message'],
                'data' => null
            ];
        }

        // 2. Validate Subject Type
        $subjectType = SlikReaderValidator::validateSubject($name);
        if (!$subjectType) {
            return [
                'success' => false,
                'http' => 400,
                'code' => SlikReaderValidator::ERR_INVALID_SUBJECT,
                'message' => 'Tipe subject tidak valid (harus SLIK_PEMOHON, SLIK_PASANGAN, atau SLIK_PENJAMIN)',
                'data' => null
            ];
        }

        // 3. Validate Lead
        $lead = $this->leadRepo->findById($leadId);
        if (!$lead) {
            return [
                'success' => false,
                'http' => 404,
                'code' => SlikReaderValidator::ERR_SLIK_NOT_FOUND,
                'message' => 'Data Lead dengan ID ' . $leadId . ' tidak ditemukan',
                'data' => null
            ];
        }

        // Determine expected name & NIK from lead
        $expectedName = 'DEBITUR';
        if ($subjectType === 'PEMOHON') {
            $expectedName = $lead['pemohon']['name'] ?? 'PEMOHON';
            if (empty($identityNumber)) {
                $identityNumber = $lead['pemohon']['ktp']['identity_number'] ?? '';
            }
        } elseif ($subjectType === 'PASANGAN') {
            $expectedName = $lead['spouse']['name'] ?? 'PASANGAN';
        } elseif ($subjectType === 'PENJAMIN') {
            $expectedName = $lead['lec'][0]['name'] ?? 'PENJAMIN';
        }

        // 4. Store PDF file safely
        $fileExt = pathinfo($fileInfo['name'], PATHINFO_EXTENSION);
        $storedFileName = uniqid('slik_') . '.' . $fileExt;
        $relativePath = 'private/' . $leadId . '/' . $storedFileName;
        $fileId = 'SR' . date('Ymd') . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

        $db = \App\Storage\Database::getInstance();

        if ($db->isUsingPostgres()) {
            // Production (e.g. Vercel): serverless filesystem is read-only,
            // so the uploaded file's bytes are persisted directly in Postgres.
            // The parser still reads from PHP's own upload tmp file, which is
            // a real, readable file for the duration of this single request.
            $binaryContent = file_get_contents($fileInfo['tmp_name']);
            if ($binaryContent === false) {
                return [
                    'success' => false,
                    'http' => 500,
                    'code' => 5000,
                    'message' => 'Gagal membaca file upload',
                    'data' => null
                ];
            }
            $db->saveFile($fileId, $binaryContent, 'application/pdf');
            $targetFilePath = $fileInfo['tmp_name'];
        } else {
            $uploadDir = __DIR__ . '/../../storage/uploads/private/' . $leadId;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $targetFilePath = $uploadDir . '/' . $storedFileName;

            if (!move_uploaded_file($fileInfo['tmp_name'], $targetFilePath)) {
                // Fallback for direct CLI / testing file copy
                if (!copy($fileInfo['tmp_name'], $targetFilePath)) {
                    return [
                        'success' => false,
                        'http' => 500,
                        'code' => 5000,
                        'message' => 'Gagal menyimpan file upload',
                        'data' => null
                    ];
                }
            }
        }

        // 5. Register in v_backoffice_files_slik
        $this->fileRepo->saveFileRecord($leadId, 'SLIK_' . $subjectType, $relativePath, $fileId);

        // 6. Parse PDF
        try {
            $parseResult = $this->parserService->parsePdf($targetFilePath, $identityNumber);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'http' => 422,
                'code' => SlikReaderValidator::ERR_SLIK_PARSE_FAILED,
                'message' => 'Gagal mem-parsing dokumen SLIK: ' . $e->getMessage(),
                'data' => null
            ];
        }

        $parsedNik = $parseResult->metadata->no_identitas ?? '';
        $identityMatch = SlikReaderValidator::validateIdentity($identityNumber, $parsedNik);
        $parseResult->validation['identity_match'] = $identityMatch;

        // If parser could not read Debitur name, fallback to expected name
        if (empty($parseResult->metadata->nama_debitur)) {
            $parseResult->metadata->nama_debitur = $expectedName;
        }

        // 7. Check identity match constraint
        $resultId = $this->resultRepo->generateResultId();

        if (!$identityMatch) {
            // Mask identity numbers for security as required by spec Section 19/22
            $maskedExpected = '****' . substr($identityNumber, -4);
            $maskedParsed = '****' . substr($parsedNik, -4);

            // Roll back the uploaded file so the subject stays clear (no REJECTED result persisted)
            $this->fileRepo->deleteFileRecord($fileId);
            if ($db->isUsingPostgres()) {
                $db->deleteFile($fileId);
            } elseif (is_file($targetFilePath)) {
                unlink($targetFilePath);
            }

            return [
                'success' => false,
                'http' => 422,
                'code' => SlikReaderValidator::ERR_SLIK_IDENTITY_MISMATCH,
                'message' => 'NIK pada dokumen SLIK tidak sesuai dengan data subject',
                'data' => [
                    'expected_identity_number' => $maskedExpected,
                    'parsed_identity_number' => $maskedParsed
                ]
            ];
        }

        // 8. Identity Match Success -> create PENDING result
        $record = [
            '_id' => $resultId,
            'lead_id' => $leadId,
            'subject' => [
                'type' => $subjectType,
                'identity_number' => $identityNumber,
                'name' => $parseResult->metadata->nama_debitur ?? $expectedName
            ],
            'source_file' => [
                'file_id' => $fileId,
                'name' => 'SLIK_' . $subjectType,
                'path' => $relativePath
            ],
            'status' => 'PENDING',
            'parser' => [
                'status' => 'PARSED',
                'version' => '1.0'
            ],
            'metadata' => $parseResult->metadata->jsonSerialize(),
            'ringkasan' => $parseResult->ringkasan->jsonSerialize(),
            'fasilitas' => $parseResult->fasilitas,
            'data_pokok' => $parseResult->data_pokok,
            'validation' => [
                'identity_match' => true
            ],
            'approval' => [
                'status' => 'PENDING',
                'approved_by' => null,
                'approved_dtm' => null,
                'rejected_by' => null,
                'rejected_dtm' => null,
                'reason_code' => null,
                'note' => null
            ],
            'created_by' => $userId,
            'created_dtm' => date('Y-m-d H:i:s'),
            'updated_dtm' => date('Y-m-d H:i:s')
        ];

        $this->resultRepo->save($record);

        // Update reference on lead
        $leadRef = [
            strtolower($subjectType) . '_result_id' => $resultId,
            'status' => 'ON PROCESS'
        ];
        $this->leadRepo->updateCreditCheckingStatus($leadId, 'ON PROGRESS', $leadRef);

        return [
            'success' => true,
            'http' => 201,
            'code' => 0,
            'message' => 'File SLIK berhasil diunggah dan diproses',
            'data' => $record
        ];
    }

    public function getSummaryByLead(string $leadId): array {
        $lead = $this->leadRepo->findById($leadId);
        if (!$lead) {
            return [
                'success' => false,
                'http' => 404,
                'code' => SlikReaderValidator::ERR_SLIK_NOT_FOUND,
                'message' => 'Lead tidak ditemukan',
                'data' => null
            ];
        }

        $allResults = $this->resultRepo->findByLeadId($leadId);
        
        // Group by subject.type and get the latest
        $latestPerSubject = [];
        foreach ($allResults as $res) {
            $sType = $res['subject']['type'] ?? 'PEMOHON';
            if (!isset($latestPerSubject[$sType]) || strcmp($res['created_dtm'] ?? '', $latestPerSubject[$sType]['created_dtm'] ?? '') > 0) {
                $latestPerSubject[$sType] = $res;
            }
        }

        $formattedResults = [];
        $overallStatus = 'COMPLETED';
        if (empty($latestPerSubject)) {
            $overallStatus = 'PENDING';
        }

        foreach ($latestPerSubject as $res) {
            $formattedResults[] = [
                'result_id' => $res['_id'],
                'subject_type' => $res['subject']['type'] ?? 'PEMOHON',
                'name' => $res['subject']['name'] ?? '',
                'identity_number' => $res['subject']['identity_number'] ?? '',
                'parser_status' => $res['parser']['status'] ?? 'PARSED',
                'approval_status' => $res['approval']['status'] ?? $res['status'] ?? 'PENDING',
                'summary' => [
                    'plafon_efektif_total' => $res['ringkasan']['plafon_efektif_total'] ?? 0,
                    'baki_debet_total' => $res['ringkasan']['baki_debet_total'] ?? 0,
                    'kualitas_terburuk' => $res['ringkasan']['kualitas_terburuk'] ?? '1 - Lancar'
                ]
            ];
            if (($res['approval']['status'] ?? '') === 'PENDING') {
                $overallStatus = 'ON PROCESS';
            }
        }

        return [
            'success' => true,
            'http' => 200,
            'code' => 0,
            'message' => 'OK',
            'data' => [
                'lead_id' => $leadId,
                'status' => $overallStatus,
                'results' => $formattedResults
            ]
        ];
    }

    public function getResultDetail(string $resultId): array {
        $result = $this->resultRepo->findById($resultId);
        if (!$result) {
            return [
                'success' => false,
                'http' => 404,
                'code' => SlikReaderValidator::ERR_SLIK_NOT_FOUND,
                'message' => 'Result SLIK dengan ID ' . $resultId . ' tidak ditemukan',
                'data' => null
            ];
        }

        // Format to match API Specification Section 10
        $data = [
            'result_id' => $result['_id'],
            'lead_id' => $result['lead_id'],
            'subject' => $result['subject'] ?? [],
            'source_file' => $result['source_file'] ?? [],
            'status' => $result['status'] ?? 'PENDING',
            'parser' => $result['parser'] ?? [],
            'metadata' => $result['metadata'] ?? [],
            'ringkasan' => $result['ringkasan'] ?? [],
            'fasilitas' => $result['fasilitas'] ?? [],
            'data_pokok' => $result['data_pokok'] ?? [],
            'validation' => $result['validation'] ?? ['identity_match' => true],
            'approval' => $result['approval'] ?? []
        ];

        return [
            'success' => true,
            'http' => 200,
            'code' => 0,
            'message' => 'OK',
            'data' => $data
        ];
    }

    public function approveResult(string $resultId, ?string $note, string $userId): array {
        $result = $this->resultRepo->findById($resultId);
        if (!$result) {
            return [
                'success' => false,
                'http' => 404,
                'code' => SlikReaderValidator::ERR_SLIK_NOT_FOUND,
                'message' => 'Result SLIK tidak ditemukan',
                'data' => null
            ];
        }

        $currentStatus = $result['status'] ?? 'PENDING';
        if ($currentStatus === 'APPROVED') {
            return [
                'success' => false,
                'http' => 409,
                'code' => SlikReaderValidator::ERR_SLIK_ALREADY_APPROVED,
                'message' => 'Result SLIK ini sudah berstatus APPROVED',
                'data' => null
            ];
        }

        if ($currentStatus === 'REJECTED') {
            return [
                'success' => false,
                'http' => 409,
                'code' => SlikReaderValidator::ERR_SLIK_ALREADY_REJECTED,
                'message' => 'Result SLIK ini sudah di-REJECT sebelumnya',
                'data' => null
            ];
        }

        // Identity check must be true
        if (empty($result['validation']['identity_match'])) {
            return [
                'success' => false,
                'http' => 422,
                'code' => SlikReaderValidator::ERR_SLIK_IDENTITY_MISMATCH,
                'message' => 'Tidak dapat menyetujui dokumen yang identitasnya tidak cocok',
                'data' => null
            ];
        }

        $approvalDtm = date('Y-m-d H:i:s');
        $approvalData = [
            'status' => 'APPROVED',
            'approved_by' => $userId,
            'approved_dtm' => $approvalDtm,
            'rejected_by' => null,
            'rejected_dtm' => null,
            'reason_code' => null,
            'note' => $note
        ];

        $this->resultRepo->updateStatus($resultId, 'APPROVED', $approvalData);

        return [
            'success' => true,
            'http' => 200,
            'code' => 0,
            'message' => 'Result SLIK berhasil disetujui',
            'data' => [
                'result_id' => $resultId,
                'status' => 'APPROVED',
                'approved_by' => $userId,
                'approved_dtm' => $approvalDtm
            ]
        ];
    }

    public function rejectResult(string $resultId, string $reasonCode, ?string $note, string $userId): array {
        $result = $this->resultRepo->findById($resultId);
        if (!$result) {
            return [
                'success' => false,
                'http' => 404,
                'code' => SlikReaderValidator::ERR_SLIK_NOT_FOUND,
                'message' => 'Result SLIK tidak ditemukan',
                'data' => null
            ];
        }

        $currentStatus = $result['status'] ?? 'PENDING';
        if ($currentStatus === 'APPROVED') {
            return [
                'success' => false,
                'http' => 409,
                'code' => SlikReaderValidator::ERR_SLIK_ALREADY_APPROVED,
                'message' => 'Tidak dapat me-reject dokumen yang sudah berstatus APPROVED',
                'data' => null
            ];
        }

        if ($currentStatus === 'REJECTED') {
            return [
                'success' => false,
                'http' => 409,
                'code' => SlikReaderValidator::ERR_SLIK_ALREADY_REJECTED,
                'message' => 'Result SLIK ini sudah di-REJECT sebelumnya',
                'data' => null
            ];
        }

        $rejectDtm = date('Y-m-d H:i:s');
        $approvalData = [
            'status' => 'REJECTED',
            'approved_by' => null,
            'approved_dtm' => null,
            'rejected_by' => $userId,
            'rejected_dtm' => $rejectDtm,
            'reason_code' => $reasonCode ?: 'GENERAL_REJECT',
            'note' => $note
        ];

        $this->resultRepo->updateStatus($resultId, 'REJECTED', $approvalData);

        return [
            'success' => true,
            'http' => 200,
            'code' => 0,
            'message' => 'Result SLIK berhasil ditolak',
            'data' => [
                'result_id' => $resultId,
                'status' => 'REJECTED',
                'rejected_by' => $userId,
                'rejected_dtm' => $rejectDtm,
                'reason_code' => $reasonCode,
                'note' => $note
            ]
        ];
    }

    public function getDownloadFileInfo(string $fileId): ?array {
        $fileRecord = $this->fileRepo->findById($fileId);
        if (!$fileRecord) {
            return null;
        }

        $db = \App\Storage\Database::getInstance();

        if ($db->isUsingPostgres()) {
            $file = $db->getFile($fileId);
            if (!$file) {
                return null;
            }
            return [
                'mode' => 'content',
                'content' => $file['content'],
                'content_type' => $file['content_type'] ?: 'application/pdf',
                'name' => $fileRecord['name'] ?? 'dokumen_slik.pdf'
            ];
        }

        $relativePath = $fileRecord['path'] ?? '';
        $absolutePath = realpath(__DIR__ . '/../../storage/uploads/' . $relativePath);

        // Security check: prevent path traversal
        $baseDir = realpath(__DIR__ . '/../../storage/uploads');
        if (!$absolutePath || strpos($absolutePath, $baseDir) !== 0 || !file_exists($absolutePath)) {
            // Return null or check if sample file exists
            return null;
        }

        return [
            'mode' => 'path',
            'path' => $absolutePath,
            'filename' => basename($relativePath),
            'name' => $fileRecord['name'] ?? 'dokumen_slik.pdf'
        ];
    }
}
