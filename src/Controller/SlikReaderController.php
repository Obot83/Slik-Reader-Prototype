<?php

namespace App\Controller;

use App\Service\SlikReaderService;
use App\Service\SlikReportService;
use App\Repository\LeadRepository;
use App\Repository\SlikReaderResultRepository;
use App\Repository\SlikFileRepository;

class SlikReaderController {
    private SlikReaderService $slikService;
    private SlikReportService $reportService;
    private LeadRepository $leadRepo;

    public function __construct() {
        $this->slikService = new SlikReaderService();
        $this->reportService = new SlikReportService();
        $this->leadRepo = new LeadRepository();
    }

    private function response(int $httpCode, int $error, int $errorCode, string $message, $data = null): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => $error,
            'error_code' => $errorCode,
            'message' => $message,
            'response_time' => date('Y-m-d H:i:s'),
            'data' => $data ?? (object)[]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function handleUpload(): void {
        $file = $_FILES['file'] ?? null;
        $leadId = trim($_POST['lead_id'] ?? '');
        $identityNumber = trim($_POST['identity_number'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $userId = $_SERVER['HTTP_X_USER_ID'] ?? 'USER_OPERATOR';

        if (empty($leadId) || empty($name)) {
            $this->response(400, 1, 4000, 'Parameter lead_id dan name wajib diisi');
        }

        if (!is_array($file)) {
            $this->response(400, 1, 4001, 'File tidak valid atau tidak diunggah');
        }

        $res = $this->slikService->processUpload($file, $leadId, $identityNumber, $name, $userId);
        if (!$res['success']) {
            $this->response($res['http'], 1, $res['code'], $res['message'], $res['data']);
        }

        $this->response($res['http'], 0, 0, $res['message'], $res['data']);
    }

    public function handleGetSummary(string $leadId): void {
        $res = $this->slikService->getSummaryByLead($leadId);
        if (!$res['success']) {
            $this->response($res['http'], 1, $res['code'], $res['message'], $res['data']);
        }
        $this->response(200, 0, 0, 'OK', $res['data']);
    }

    public function handleGetResult(string $resultId): void {
        $res = $this->slikService->getResultDetail($resultId);
        if (!$res['success']) {
            $this->response($res['http'], 1, $res['code'], $res['message'], $res['data']);
        }
        $this->response(200, 0, 0, 'OK', $res['data']);
    }

    public function handleApprove(string $resultId): void {
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?? [];
        $note = $body['note'] ?? null;
        $userId = $_SERVER['HTTP_X_USER_ID'] ?? 'USER_REVIEWER';

        $res = $this->slikService->approveResult($resultId, $note, $userId);
        if (!$res['success']) {
            $this->response($res['http'], 1, $res['code'], $res['message'], $res['data']);
        }
        $this->response(200, 0, 0, $res['message'], $res['data']);
    }

    public function handleReject(string $resultId): void {
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?? [];
        $reasonCode = $body['reason_code'] ?? 'GENERAL_REJECT';
        $note = $body['note'] ?? '';
        $userId = $_SERVER['HTTP_X_USER_ID'] ?? 'USER_REVIEWER';

        $res = $this->slikService->rejectResult($resultId, $reasonCode, $note, $userId);
        if (!$res['success']) {
            $this->response($res['http'], 1, $res['code'], $res['message'], $res['data']);
        }
        $this->response(200, 0, 0, $res['message'], $res['data']);
    }

    public function handleDownloadFile(string $fileId): void {
        $fileInfo = $this->slikService->getDownloadFileInfo($fileId);
        if (!$fileInfo || !file_exists($fileInfo['path'])) {
            // Generate on the fly if dummy / sample
            header('Content-Type: text/plain');
            http_response_code(404);
            echo "File not found or unauthorized";
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $fileInfo['name'] . '.pdf"');
        header('Content-Length: ' . filesize($fileInfo['path']));
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($fileInfo['path']);
        exit;
    }

    public function handleReport(string $resultId): void {
        $format = strtolower($_GET['format'] ?? 'pdf');
        $res = $this->slikService->getResultDetail($resultId);
        if (!$res['success']) {
            $this->response($res['http'], 1, $res['code'], $res['message'], $res['data']);
        }

        $resultData = $res['data'];

        if ($format === 'xlsx' || $format === 'csv') {
            $csv = $this->reportService->generateReportCsv($resultData);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="SLIK_Report_' . $resultId . '.csv"');
            echo $csv;
            exit;
        }

        // PDF / HTML Printable view
        $html = $this->reportService->generateReportHtml($resultData);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    public function handleGetPengajuanSlik(): void {
        $leads = $this->leadRepo->getAll();
        $resultRepo = new SlikReaderResultRepository();
        $fileRepo = new SlikFileRepository();

        $rows = [];
        foreach ($leads as $l) {
            $leadId = $l['_id'] ?? '';
            $pemohonName = $l['pemohon']['name'] ?? 'PEMOHON';
            $pemohonNik = $l['pemohon']['ktp']['identity_number'] ?? '-';
            $phone = $l['pemohon']['phone_number'] ?? '-';
            $loanNominal = $l['pengajuan']['submission']['loan']['$numberDecimal'] ?? 80000000;
            $actionLead = $l['status']['lead'] ?? 'RESPONSE';

            // Check results or files for this lead
            $files = $fileRepo->getFilesByLead($leadId);
            $hasPemohonFile = false;
            $hasPasanganFile = false;
            $hasPenjaminFile = false;
            $pemohonFileId = null;
            $pasanganFileId = null;
            $penjaminFileId = null;

            foreach ($files as $f) {
                if ($f['name'] === 'SLIK_PEMOHON') {
                    $hasPemohonFile = true;
                    $pemohonFileId = $f['_id'];
                }
                if ($f['name'] === 'SLIK_PASANGAN') {
                    $hasPasanganFile = true;
                    $pasanganFileId = $f['_id'];
                }
                if ($f['name'] === 'SLIK_PENJAMIN') {
                    $hasPenjaminFile = true;
                    $penjaminFileId = $f['_id'];
                }
            }

            // Results per subject
            $resPemohon = $resultRepo->findLatestByLeadAndSubject($leadId, 'PEMOHON');
            $resPasangan = $resultRepo->findLatestByLeadAndSubject($leadId, 'PASANGAN');
            $resPenjamin = $resultRepo->findLatestByLeadAndSubject($leadId, 'PENJAMIN');

            $rows[] = [
                'lead_id' => $leadId,
                'name' => $pemohonName,
                'identity_number' => $pemohonNik,
                'phone_number' => $phone,
                'nominal_pengajuan' => (float)$loanNominal,
                'action_lead' => $actionLead,
                'status_credit_checking' => $l['status']['credit_checking'] ?? 'ON PROGRESS',
                'created_dtm' => $l['create_dtm']['$date'] ?? '2026-08-07',
                'no_slik_pemohon' => (bool)($l['no_slik_pemohon'] ?? false),
                'no_slik_pasangan' => (bool)($l['no_slik_pasangan'] ?? false),
                'no_slik_penjamin' => (bool)($l['no_slik_penjamin'] ?? false),
                'slik_pemohon' => [
                    'has_file' => $hasPemohonFile || !empty($resPemohon),
                    'file_id' => $pemohonFileId ?? ($resPemohon['source_file']['file_id'] ?? null),
                    'result_id' => $resPemohon['_id'] ?? null,
                    'status' => $resPemohon['status'] ?? ($hasPemohonFile ? 'UPLOADED' : 'NOT_UPLOADED'),
                    'name' => $pemohonName,
                    'identity_number' => $pemohonNik
                ],
                'slik_pasangan' => [
                    'has_file' => $hasPasanganFile || !empty($resPasangan),
                    'file_id' => $pasanganFileId ?? ($resPasangan['source_file']['file_id'] ?? null),
                    'result_id' => $resPasangan['_id'] ?? null,
                    'status' => $resPasangan['status'] ?? ($hasPasanganFile ? 'UPLOADED' : 'NOT_UPLOADED'),
                    'name' => $l['spouse']['name'] ?? 'PASANGAN',
                    'identity_number' => $l['spouse']['identity_number'] ?? '3276020801000002'
                ],
                'slik_penjamin' => [
                    'has_file' => $hasPenjaminFile || !empty($resPenjamin),
                    'file_id' => $penjaminFileId ?? ($resPenjamin['source_file']['file_id'] ?? null),
                    'result_id' => $resPenjamin['_id'] ?? null,
                    'status' => $resPenjamin['status'] ?? ($hasPenjaminFile ? 'UPLOADED' : 'NOT_UPLOADED'),
                    'name' => $l['lec'][0]['name'] ?? 'PENJAMIN',
                    'identity_number' => $l['lec'][0]['identity_number'] ?? '3276020801000003'
                ]
            ];
        }

        $this->response(200, 0, 0, 'OK', $rows);
    }

    public function handleGetLeadDetail(string $leadId): void {
        $lead = $this->leadRepo->findById($leadId);
        if (!$lead) {
            $this->response(404, 1, 4008, 'Data Lead tidak ditemukan');
        }

        // Include latest SLIK results
        $resultRepo = new SlikReaderResultRepository();
        $allResults = $resultRepo->findByLeadId($leadId);
        $lead['slik_results'] = $allResults;

        $this->response(200, 0, 0, 'OK', $lead);
    }

    public function handleSetDone(string $leadId): void {
        $this->leadRepo->updateCreditCheckingStatus($leadId, 'PASSED');
        $this->response(200, 0, 0, 'Credit Checking berhasil diselesaikan (PASSED)');
    }

    public function handleSetNoSlik(string $leadId): void {
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?? [];

        $subjectType = strtoupper((string)($body['subject_type'] ?? ''));
        $noSlik = (bool)($body['no_slik'] ?? false);

        if ($subjectType === '') {
            $this->response(400, 1, 4001, 'subject_type wajib diisi');
        }

        $success = $this->leadRepo->setNoSlikFlag($leadId, $subjectType, $noSlik);
        if (!$success) {
            $this->response(400, 1, 4002, 'subject_type tidak valid atau lead tidak ditemukan');
        }

        $this->response(200, 0, 0, 'Status no_slik berhasil diperbarui', [
            'lead_id' => $leadId,
            'subject_type' => $subjectType,
            'no_slik' => $noSlik
        ]);
    }

    public function handleRejectLead(string $leadId): void {
        $this->leadRepo->updateCreditCheckingStatus($leadId, 'REJECTED');
        $this->response(200, 0, 0, 'Pengajuan berhasil ditolak');
    }

    public function handleGetCreditChecking(string $leadId): void {
        $lead = $this->leadRepo->findById($leadId);
        if (!$lead) {
            $this->response(404, 1, 4008, 'Data Lead tidak ditemukan');
        }
        $this->response(200, 0, 0, 'OK', $lead['credit_checking'] ?? []);
    }

    public function handleUpdateSpouseInfo(string $leadId): void {
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?? [];

        $name = trim($body['name'] ?? '');
        $identityNumber = trim($body['identity_number'] ?? '');

        if (empty($name) || empty($identityNumber)) {
            $this->response(400, 1, 4000, 'Parameter name dan identity_number wajib diisi');
        }

        $lead = $this->leadRepo->findById($leadId);
        if (!$lead) {
            $this->response(404, 1, 4008, 'Data Lead tidak ditemukan');
        }

        // Ensure spouse object exists
        if (!isset($lead['spouse'])) {
            $lead['spouse'] = [];
        }

        $updateData = [
            'spouse.name' => $name,
            'spouse.identity_number' => $identityNumber,
            'spouse.status.credit_checking' => 'ON PROGRESS',
            'update_dtm' => date('c')
        ];

        $success = $this->leadRepo->updateLead($leadId, $updateData);
        if (!$success) {
            $this->response(500, 1, 5001, 'Gagal menyimpan data pasangan');
        }

        $this->response(200, 0, 0, 'Data pasangan berhasil diperbarui', [
            'lead_id' => $leadId,
            'name' => $name,
            'identity_number' => $identityNumber
        ]);
    }

    public function handleUpdatePenjaminInfo(string $leadId): void {
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?? [];

        $name = trim($body['name'] ?? '');
        $identityNumber = trim($body['identity_number'] ?? '');

        if (empty($name) || empty($identityNumber)) {
            $this->response(400, 1, 4000, 'Parameter name dan identity_number wajib diisi');
        }

        $lead = $this->leadRepo->findById($leadId);
        if (!$lead) {
            $this->response(404, 1, 4008, 'Data Lead tidak ditemukan');
        }

        // Ensure lec array exists and has at least one element
        if (!isset($lead['lec']) || !is_array($lead['lec']) || empty($lead['lec'])) {
            $lead['lec'] = [[]];
        }

        $updateData = [
            'lec.0.name' => $name,
            'lec.0.identity_number' => $identityNumber,
            'update_dtm' => date('c')
        ];

        $success = $this->leadRepo->updateLead($leadId, $updateData);
        if (!$success) {
            $this->response(500, 1, 5001, 'Gagal menyimpan data penjamin');
        }

        $this->response(200, 0, 0, 'Data penjamin berhasil diperbarui', [
            'lead_id' => $leadId,
            'name' => $name,
            'identity_number' => $identityNumber
        ]);
    }

    public function handleResetSlikData(string $leadId): void {
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?? [];

        $subjectType = strtoupper(trim($body['subject_type'] ?? ''));
        $resetAll = (bool)($body['reset_all'] ?? false);

        $lead = $this->leadRepo->findById($leadId);
        if (!$lead) {
            $this->response(404, 1, 4008, 'Data Lead tidak ditemukan');
        }

        $resultRepo = new SlikReaderResultRepository();
        $fileRepo = new SlikFileRepository();
        $deletedCount = 0;

        if ($resetAll) {
            // Delete all SLIK data for this lead
            $deletedCount = $resultRepo->deleteByLeadId($leadId);

            // Also delete associated files
            $files = $fileRepo->getFilesByLead($leadId);
            foreach ($files as $f) {
                $fileRepo->deleteFileRecord($f['_id']);
            }
        } else {
            // Delete only specific subject type
            if (empty($subjectType) || !in_array($subjectType, ['PEMOHON', 'PASANGAN', 'PENJAMIN'])) {
                $this->response(400, 1, 4001, 'subject_type harus salah satu: PEMOHON, PASANGAN, PENJAMIN');
            }

            $deletedCount = $resultRepo->deleteByLeadIdAndSubject($leadId, $subjectType);

            // Also delete associated files for this subject
            $files = $fileRepo->getFilesByLead($leadId);
            $subjectFileMap = [
                'PEMOHON' => 'SLIK_PEMOHON',
                'PASANGAN' => 'SLIK_PASANGAN',
                'PENJAMIN' => 'SLIK_PENJAMIN'
            ];
            $targetFileName = $subjectFileMap[$subjectType];
            foreach ($files as $f) {
                if ($f['name'] === $targetFileName) {
                    $fileRepo->deleteFileRecord($f['_id']);
                }
            }
        }

        $message = $resetAll
            ? "Semua data SLIK untuk lead $leadId berhasil direset ($deletedCount records dihapus)"
            : "Data SLIK $subjectType untuk lead $leadId berhasil direset ($deletedCount records dihapus)";

        $this->response(200, 0, 0, $message, [
            'lead_id' => $leadId,
            'subject_type' => $subjectType ?: 'ALL',
            'deleted_count' => $deletedCount
        ]);
    }
}
