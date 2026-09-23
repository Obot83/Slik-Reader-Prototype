<?php

namespace App\Storage;

use PDO;

class Database {
    private static ?Database $instance = null;
    private string $storageDir;
    private bool $usePostgres = false;
    private ?PDO $pdo = null;

    private function __construct() {
        // Check every source Vercel (or any host) might inject the connection
        // string through — different SAPIs/runtimes populate these differently.
        $databaseUrl = getenv('DATABASE_URL')
            ?: ($_ENV['DATABASE_URL'] ?? '')
            ?: ($_SERVER['DATABASE_URL'] ?? '')
            ?: (getenv('POSTGRES_URL') ?: '')
            ?: ($_ENV['POSTGRES_URL'] ?? '');

        if (!empty($databaseUrl)) {
            // Production mode (e.g. Vercel): real Postgres (Neon) over PDO.
            // Serverless functions have a read-only filesystem, so flat-file
            // JSON storage cannot be used here.
            $this->usePostgres = true;
            $this->connectPostgres($databaseUrl);
            $this->initializePostgresSchema();
        } else {
            // Local/dev mode: flat-file JSON, unchanged from the original prototype.
            $this->storageDir = __DIR__ . '/../../storage/data';
            if (!is_dir($this->storageDir)) {
                mkdir($this->storageDir, 0777, true);
            }
            $this->initializeCollections();
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function isUsingPostgres(): bool {
        return $this->usePostgres;
    }

    // ============================================================
    // Postgres mode (production / Vercel)
    // ============================================================

    private function connectPostgres(string $databaseUrl): void {
        // Accepts standard connection URL: postgres://user:pass@host:port/dbname?sslmode=require
        $parts = parse_url($databaseUrl);
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 5432;
        $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
        // parse_url() does NOT url-decode user/pass — decode explicitly so
        // special characters (%40 => @, etc.) in generated DB passwords work.
        $user = isset($parts['user']) ? urldecode($parts['user']) : '';
        $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';

        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);
        }
        $sslmode = $queryParams['sslmode'] ?? 'require';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function initializePostgresSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS collections (
                name TEXT PRIMARY KEY,
                data JSONB NOT NULL DEFAULT '[]'::jsonb
            )
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS blob_files (
                file_id TEXT PRIMARY KEY,
                content_type TEXT NOT NULL DEFAULT 'application/pdf',
                content BYTEA NOT NULL,
                created_dtm TIMESTAMP DEFAULT now()
            )
        ");

        // Seed each collection once from the bundled storage/data/*.json seed
        // files (read-only access to the deployed bundle is fine on Vercel).
        $collections = ['leads', 'v_backoffice_files_slik', 'slik_reader_results', 'users_client'];
        foreach ($collections as $col) {
            $check = $this->pdo->prepare('SELECT 1 FROM collections WHERE name = :name');
            $check->execute(['name' => $col]);
            if ($check->fetch()) {
                continue; // already seeded
            }

            $seedFile = __DIR__ . '/../../storage/data/' . $col . '.json';
            $seedJson = '[]';
            if (file_exists($seedFile)) {
                $raw = file_get_contents($seedFile);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $seedJson = json_encode(array_values($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            $ins = $this->pdo->prepare('
                INSERT INTO collections (name, data) VALUES (:name, CAST(:data AS JSONB))
                ON CONFLICT (name) DO NOTHING
            ');
            $ins->execute(['name' => $col, 'data' => $seedJson]);

            // v_backoffice_files_slik also needs its referenced PDF bytes
            // seeded into blob_files, otherwise download would 404 even
            // though the file metadata says the record exists.
            if ($col === 'v_backoffice_files_slik' && is_array($decoded ?? null)) {
                foreach ($decoded as $fileRecord) {
                    $fileId = $fileRecord['_id'] ?? null;
                    $relativePath = $fileRecord['path'] ?? null;
                    if (!$fileId || !$relativePath) {
                        continue;
                    }
                    $seedPdfPath = __DIR__ . '/../../storage/uploads/' . $relativePath;
                    if (file_exists($seedPdfPath)) {
                        $this->saveFile($fileId, file_get_contents($seedPdfPath), 'application/pdf');
                    }
                }
            }
        }
    }

    /** Store a binary file (e.g. uploaded PDF) — Postgres mode only. */
    public function saveFile(string $fileId, string $binaryContent, string $contentType = 'application/pdf'): void {
        if (!$this->usePostgres) {
            return;
        }
        $stmt = $this->pdo->prepare('
            INSERT INTO blob_files (file_id, content_type, content)
            VALUES (:id, :ct, :content)
            ON CONFLICT (file_id) DO UPDATE SET content_type = :ct2, content = :content2
        ');
        $stmt->bindValue(':id', $fileId);
        $stmt->bindValue(':ct', $contentType);
        $stmt->bindValue(':ct2', $contentType);
        $stmt->bindValue(':content', $binaryContent, PDO::PARAM_LOB);
        $stmt->bindValue(':content2', $binaryContent, PDO::PARAM_LOB);
        $stmt->execute();
    }

    /** Retrieve a binary file — Postgres mode only. Returns ['content' => string, 'content_type' => string] or null. */
    public function getFile(string $fileId): ?array {
        if (!$this->usePostgres) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT content, content_type FROM blob_files WHERE file_id = :id');
        $stmt->execute(['id' => $fileId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $content = $row['content'];
        // PDO pgsql may return LOB content as a stream resource depending on driver config.
        if (is_resource($content)) {
            $content = stream_get_contents($content);
        }
        return ['content' => $content, 'content_type' => $row['content_type']];
    }

    /** Delete a binary file — Postgres mode only (no-op locally, local files are unlinked directly by the caller). */
    public function deleteFile(string $fileId): void {
        if (!$this->usePostgres) {
            return;
        }
        $stmt = $this->pdo->prepare('DELETE FROM blob_files WHERE file_id = :id');
        $stmt->execute(['id' => $fileId]);
    }

    // ============================================================
    // Local flat-file mode (development)
    // ============================================================

    private function initializeCollections(): void {
        $collections = ['leads', 'v_backoffice_files_slik', 'slik_reader_results', 'users_client'];
        foreach ($collections as $col) {
            $file = $this->storageDir . '/' . $col . '.json';
            if (!file_exists($file)) {
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

    // ============================================================
    // Shared query API — used by all Repositories, works transparently
    // in both Postgres and local flat-file mode via getCollection()/saveCollection().
    // ============================================================

    public function getCollection(string $name): array {
        if ($this->usePostgres) {
            $stmt = $this->pdo->prepare('SELECT data FROM collections WHERE name = :name');
            $stmt->execute(['name' => $name]);
            $row = $stmt->fetch();
            if (!$row) {
                return [];
            }
            $decoded = json_decode($row['data'], true);
            return is_array($decoded) ? $decoded : [];
        }

        $file = $this->storageDir . '/' . $name . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $content = file_get_contents($file);
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveCollection(string $name, array $items): void {
        if ($this->usePostgres) {
            $json = json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmt = $this->pdo->prepare('
                INSERT INTO collections (name, data) VALUES (:name, CAST(:data AS JSONB))
                ON CONFLICT (name) DO UPDATE SET data = CAST(:data2 AS JSONB)
            ');
            $stmt->execute(['name' => $name, 'data' => $json, 'data2' => $json]);
            return;
        }

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
