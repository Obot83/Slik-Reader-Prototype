<?php

namespace App\Validator;

class SlikReaderValidator {
    public const ERR_INVALID_REQUEST = 4000;
    public const ERR_INVALID_FILE = 4001;
    public const ERR_FILE_TOO_LARGE = 4002;
    public const ERR_PDF_UNREADABLE = 4003;
    public const ERR_SLIK_IDENTITY_MISMATCH = 4004;
    public const ERR_SLIK_PARSE_FAILED = 4005;
    public const ERR_SLIK_METADATA_INCOMPLETE = 4006;
    public const ERR_INVALID_SUBJECT = 4007;
    public const ERR_SLIK_NOT_FOUND = 4008;
    public const ERR_SLIK_ALREADY_APPROVED = 4009;
    public const ERR_SLIK_ALREADY_REJECTED = 4010;
    public const ERR_UNAUTHORIZED_BRANCH = 4011;
    public const ERR_INVALID_STATUS_TRANSITION = 4012;

    public static function validateUploadedFile(?array $fileInfo, int $maxBytes = 20971520): ?array {
        if (!$fileInfo || !isset($fileInfo['tmp_name']) || empty($fileInfo['tmp_name']) || $fileInfo['error'] !== UPLOAD_ERR_OK) {
            return [
                'http' => 400,
                'code' => self::ERR_INVALID_FILE,
                'message' => 'File tidak valid atau tidak diunggah'
            ];
        }

        if ($fileInfo['size'] > $maxBytes) {
            return [
                'http' => 400,
                'code' => self::ERR_FILE_TOO_LARGE,
                'message' => 'Ukuran file melebihi batas maksimum (20MB)'
            ];
        }

        // Validate PDF extension & MIME header
        $origName = $fileInfo['name'] ?? '';
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            return [
                'http' => 400,
                'code' => self::ERR_INVALID_FILE,
                'message' => 'File harus berformat PDF'
            ];
        }

        // Check magic bytes %PDF
        $handle = fopen($fileInfo['tmp_name'], 'rb');
        $header = fread($handle, 5);
        fclose($handle);
        if (strpos($header, '%PDF') !== 0) {
            return [
                'http' => 400,
                'code' => self::ERR_INVALID_FILE,
                'message' => 'Format file bukan dokumen PDF yang valid'
            ];
        }

        return null;
    }

    public static function validateSubject(string $subjectName): ?string {
        $allowed = [
            'SLIK_PEMOHON' => 'PEMOHON',
            'SLIK_PASANGAN' => 'PASANGAN',
            'SLIK_PENJAMIN' => 'PENJAMIN',
            'PEMOHON' => 'PEMOHON',
            'PASANGAN' => 'PASANGAN',
            'PENJAMIN' => 'PENJAMIN'
        ];
        return $allowed[strtoupper($subjectName)] ?? null;
    }

    public static function validateIdentity(string $expectedNik, string $parsedNik): bool {
        // Strip spaces, dashes, dots
        $cleanExpected = preg_replace('/[^0-9]/', '', $expectedNik);
        $cleanParsed = preg_replace('/[^0-9]/', '', $parsedNik);
        return !empty($cleanExpected) && !empty($cleanParsed) && ($cleanExpected === $cleanParsed);
    }
}
