<?php

namespace App\Storage;

class Database {
    private static ?Database $instance = null;
    private string $storageDir;
    private array $data = [];

    private function __construct() {
        $this->storageDir = __DIR__ . '/../../storage/data';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }
        $this->initializeCollections();
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initializeCollections(): void {
        $collections = ['leads', 'v_backoffice_files_slik', 'slik_reader_results', 'users_client'];
        foreach ($collections as $col) {
            $file = $this->storageDir . '/' . $col . '.json';
            if (!file_exists($file)) {
                // Check if sample exists in Dokumen API
                $sampleFile = __DIR__ . '/../../../Dokumen API Dan MongoDB TOP Operator/topop_sukma.' . $col . '.json';
                if (!file_exists($sampleFile)) {
                    $sampleFile = __DIR__ . '/../../../Dokumen API Dan MongoDB TOP Operator/topop_sukma_stag.' . $col . '.json';
                }
                
                if (file_exists($sampleFile)) {
                    copy($sampleFile, $file);
                } else {
                    file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));
                }
            }
        }
    }

    public function getCollection(string $name): array {
        $file = $this->storageDir . '/' . $name . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $content = file_get_contents($file);
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveCollection(string $name, array $items): void {
        $file = $this->storageDir . '/' . $name . '.json';
        file_put_contents($file, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public function findOne(string $collection, array $filter): ?array {
        $items = $this->getCollection($collection);
        foreach ($items as $item) {
            $match = true;
            foreach ($filter as $key => $val) {
                if ($this->getNestedValue($item, $key) !== $val) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $item;
            }
        }
        return null;
    }

    public function find(string $collection, array $filter = []): array {
        $items = $this->getCollection($collection);
        if (empty($filter)) {
            return $items;
        }
        $results = [];
        foreach ($items as $item) {
            $match = true;
            foreach ($filter as $key => $val) {
                if ($this->getNestedValue($item, $key) !== $val) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $results[] = $item;
            }
        }
        return $results;
    }

    public function insertOne(string $collection, array $document): string {
        $items = $this->getCollection($collection);
        if (!isset($document['_id'])) {
            $document['_id'] = uniqid();
        }
        // Check if already exists, replace or append
        $found = false;
        foreach ($items as $idx => $item) {
            if (isset($item['_id']) && $item['_id'] === $document['_id']) {
                $items[$idx] = $document;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $items[] = $document;
        }
        $this->saveCollection($collection, $items);
        return $document['_id'];
    }

    public function updateOne(string $collection, array $filter, array $update): bool {
        $items = $this->getCollection($collection);
        $updated = false;
        foreach ($items as $idx => $item) {
            $match = true;
            foreach ($filter as $key => $val) {
                if ($this->getNestedValue($item, $key) !== $val) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                if (isset($update['$set'])) {
                    foreach ($update['$set'] as $k => $v) {
                        $this->setNestedValue($items[$idx], $k, $v);
                    }
                } else {
                    $items[$idx] = array_merge($items[$idx], $update);
                }
                $updated = true;
                break;
            }
        }
        if ($updated) {
            $this->saveCollection($collection, $items);
        }
        return $updated;
    }

    public function deleteOne(string $collection, array $filter): bool {
        $items = $this->getCollection($collection);
        $deleted = false;
        foreach ($items as $idx => $item) {
            $match = true;
            foreach ($filter as $key => $val) {
                if ($this->getNestedValue($item, $key) !== $val) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                unset($items[$idx]);
                $deleted = true;
                break;
            }
        }
        if ($deleted) {
            $this->saveCollection($collection, array_values($items));
        }
        return $deleted;
    }

    private function getNestedValue(array $data, string $key) {
        $parts = explode('.', $key);
        $current = $data;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }

    private function setNestedValue(array &$data, string $key, $value): void {
        $parts = explode('.', $key);
        $current = &$data;
        foreach ($parts as $part) {
            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
        $current = $value;
    }
}
