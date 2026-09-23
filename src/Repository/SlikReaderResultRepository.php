<?php

namespace App\Repository;

use App\Storage\Database;

class SlikReaderResultRepository {
    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function generateResultId(): string {
        return 'SRI' . date('Ymd') . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    public function findById(string $resultId): ?array {
        return $this->db->findOne('slik_reader_results', ['_id' => $resultId]);
    }

    public function findByLeadId(string $leadId): array {
        return $this->db->find('slik_reader_results', ['lead_id' => $leadId]);
    }

    public function findLatestByLeadAndSubject(string $leadId, string $subjectType): ?array {
        $results = $this->db->find('slik_reader_results', [
            'lead_id' => $leadId,
            'subject.type' => $subjectType
        ]);
        if (empty($results)) {
            return null;
        }
        // Return latest created
        usort($results, function($a, $b) {
            return strcmp($b['created_dtm'] ?? '', $a['created_dtm'] ?? '');
        });
        return $results[0];
    }

    public function save(array $data): string {
        if (!isset($data['_id']) || empty($data['_id'])) {
            $data['_id'] = $this->generateResultId();
        }
        $this->db->insertOne('slik_reader_results', $data);
        return $data['_id'];
    }

    public function updateStatus(string $resultId, string $status, array $approvalData = []): bool {
        $update = [
            'status' => $status,
            'updated_dtm' => date('Y-m-d H:i:s')
        ];
        if (!empty($approvalData)) {
            $update['approval'] = $approvalData;
        }
        return $this->db->updateOne('slik_reader_results', ['_id' => $resultId], ['$set' => $update]);
    }

    public function deleteByLeadIdAndSubject(string $leadId, string $subjectType): int {
        $items = $this->db->getCollection('slik_reader_results');
        $original_count = count($items);

        $filtered = array_filter($items, function($item) use ($leadId, $subjectType) {
            return !($item['lead_id'] === $leadId && $item['subject']['type'] === $subjectType);
        });

        $this->db->saveCollection('slik_reader_results', array_values($filtered));
        return $original_count - count($filtered);
    }

    public function deleteByLeadId(string $leadId): int {
        $items = $this->db->getCollection('slik_reader_results');
        $original_count = count($items);

        $filtered = array_filter($items, function($item) use ($leadId) {
            return $item['lead_id'] !== $leadId;
        });

        $this->db->saveCollection('slik_reader_results', array_values($filtered));
        return $original_count - count($filtered);
    }
}
