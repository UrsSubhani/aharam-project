<?php
/**
 * BaseModel.php — Abstract base for all models
 *
 * Provides common CRUD operations backed by PDO.
 * All models extend this class and define:
 *   protected string $table    — DB table name
 *   protected array  $fillable — Fields allowed for mass assignment
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

abstract class BaseModel
{
    protected string $table    = '';
    protected array  $fillable = [];

    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Core CRUD ─────────────────────────────────────────────────────────────

    /**
     * Find record by primary key.
     */
    public function find(int $id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$this->table}` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Find record by a single column.
     */
    public function findBy(string $column, mixed $value): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$this->table}` WHERE `$column` = ? LIMIT 1");
        $stmt->execute([$value]);
        return $stmt->fetch();
    }

    /**
     * Find all records matching given conditions.
     *
     * @param array  $conditions  ['column' => value, ...]
     * @param string $orderBy
     * @param int    $limit
     * @param int    $offset
     */
    public function findAll(
        array  $conditions = [],
        string $orderBy    = 'id DESC',
        int    $limit      = 100,
        int    $offset     = 0
    ): array {
        $where  = '';
        $params = [];

        if ($conditions) {
            $clauses = [];
            foreach ($conditions as $col => $val) {
                $clauses[] = "`$col` = ?";
                $params[]  = $val;
            }
            $where = 'WHERE ' . implode(' AND ', $clauses);
        }

        $sql  = "SELECT * FROM `{$this->table}` $where ORDER BY $orderBy LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Insert a new record. Returns the new row's ID.
     * Only fillable fields are inserted.
     */
    public function create(array $data): int
    {
        $data = $this->filterFillable($data);
        if (empty($data)) {
            throw new \InvalidArgumentException('No fillable data provided for insert.');
        }

        $columns = implode(', ', array_map(fn($c) => "`$c`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}` ($columns) VALUES ($placeholders)"
        );
        $stmt->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update record by ID. Returns number of affected rows.
     */
    public function update(int $id, array $data): int
    {
        $data = $this->filterFillable($data);
        if (empty($data)) {
            return 0;
        }

        $setClause = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        $params    = array_values($data);
        $params[]  = $id;

        $stmt = $this->db->prepare(
            "UPDATE `{$this->table}` SET $setClause WHERE id = ?"
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Delete record by ID.
     */
    public function delete(int $id): int
    {
        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount();
    }

    /**
     * Count records matching conditions.
     */
    public function count(array $conditions = []): int
    {
        $where  = '';
        $params = [];

        if ($conditions) {
            $clauses = [];
            foreach ($conditions as $col => $val) {
                $clauses[] = "`$col` = ?";
                $params[]  = $val;
            }
            $where = 'WHERE ' . implode(' AND ', $clauses);
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->table}` $where");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Check if a record exists.
     */
    public function exists(array $conditions): bool
    {
        return $this->count($conditions) > 0;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Run raw prepared SQL and return all rows.
     */
    protected function raw(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Run raw prepared SQL and return first row.
     */
    protected function rawOne(string $sql, array $params = []): array|false
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Execute a raw statement (INSERT/UPDATE/DELETE) and return row count.
     */
    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Filter data to only include fillable fields.
     */
    private function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data; // No restriction
        }
        return array_intersect_key($data, array_flip($this->fillable));
    }
}
