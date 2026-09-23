<?php

namespace App\Repository;

use App\Storage\Database;

class SlikFileRepository {
    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function findById(string $fileId): ?array {
        return $this->db->findOne('v_backoffice_files_slik', ['_id' => $fileId]);
    }

    public function findByLeadAndName(string $leadId, string $name): ?array {
        return $this->db->findOne('v_backoffice_files_slik', [
            'ref_id' => $leadId,
            'name' => $name
        ]);
    }

    public function getFilesByLead(string $leadId): array {
        return $this->db->find('v_backoffice_files_slik', ['ref_id' => $leadId]);
    }

    public function saveFileRecord(string $leadId, string $name, string $path, ?string $fileId = null): string {
        if (!$fileId) {
            $fileId = 'SR' . date('Ymd') . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        }
        $record = [
            '_id' => $fileId,
            'name' => $name,
            'ref_id' => $leadId,
            'path' => $path,
            'created_dtm' => date('Y-m-d H:i:s')
        ];
        $this->db->insertOne('v_backoffice_files_slik', $record);
        return $fileId;
    }

    public function deleteFileRecord(string $fileId): bool {
        return $this->db->deleteOne('v_backoffice_files_slik', ['_id' => $fileId]);
    }
}
