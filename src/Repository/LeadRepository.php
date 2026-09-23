<?php

namespace App\Repository;

use App\Storage\Database;

class LeadRepository {
    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function findById(string $leadId): ?array {
        return $this->db->findOne('leads', ['_id' => $leadId]);
    }

    public function getAll(array $filter = []): array {
        return $this->db->find('leads', $filter);
    }

    public function updateCreditCheckingStatus(string $leadId, string $status, ?array $slikReaderRef = null): bool {
        $update = [
            '$set' => [
                'status.credit_checking' => $status,
                'update_dtm' => date('c')
            ]
        ];
        if ($slikReaderRef !== null) {
            foreach ($slikReaderRef as $k => $v) {
                $update['$set']['credit_checking.slik_reader.' . $k] = $v;
            }
        }
        return $this->db->updateOne('leads', ['_id' => $leadId], $update);
    }

    public function updateLead(string $leadId, array $setData): bool {
        return $this->db->updateOne('leads', ['_id' => $leadId], ['$set' => $setData]);
    }

    public function setNoSlikFlag(string $leadId, string $subjectType, bool $value): bool {
        $subjectKeyMap = [
            'PEMOHON' => 'no_slik_pemohon',
            'PASANGAN' => 'no_slik_pasangan',
            'PENJAMIN' => 'no_slik_penjamin',
            'SLIK_PEMOHON' => 'no_slik_pemohon',
            'SLIK_PASANGAN' => 'no_slik_pasangan',
            'SLIK_PENJAMIN' => 'no_slik_penjamin'
        ];

        $key = $subjectKeyMap[$subjectType] ?? null;
        if ($key === null) {
            return false;
        }

        return $this->db->updateOne('leads', ['_id' => $leadId], ['$set' => [$key => $value, 'update_dtm' => date('c')]]);
    }
}
