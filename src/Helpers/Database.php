<?php
namespace Helpers;

use PDO;
use PDOException;
use Helpers\Logger;


class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        // 1. ساخت DSN
        // 2. تعریف $options
        // 3. ایجاد شیء PDO و ذخیره آن در $this->pdo با استفاده از try-catch
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset={$config['charset']}";
        $options = [
            // خطاها را به صورت استثناء (Exception) پرتاب کن
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // حالت واکشی (Fetch Mode) پیش‌فرض را روی آرایه انجمنی قرار بده
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // شبیه‌سازی Prepared Statements را خاموش کن (برای امنیت)
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        // 3. ایجاد اتصال در بلاک Try-Catch
        try {
            // شیء PDO را با DSN، نام کاربری، رمز عبور و Options ایجاد می‌کنیم
            $this->pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
        } catch (\PDOException $e) {
            // در صورت بروز خطا در اتصال، یک استثناء جدید پرتاب می‌کنیم (بهتر از die کردن است)
            throw new \PDOException("DB Connection Error: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    public function insert(string $TblName, array $data, bool $lastID = false): array
    {
        if (empty($data)) {
            return ['status' => 0, 'response' => 'No data provided for insertion.'];
        }

        $columns = array_keys($data);
        $values = array_values($data);

        $column_list = '`' . implode('`, `', $columns) . '`';
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO `$TblName` ($column_list) VALUES ($placeholders)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);

            $response = ['status' => 1, 'response' => 'Done!'];
            if ($lastID) {
                $response['last_id'] = $this->pdo->lastInsertId();
            }

        } catch (\PDOException $e) {
            error_log("DB INSERT error: " . $e->getMessage());
            $response = ['status' => 0, 'response' => 'Error inserting record: ' . $e->getMessage()];
        }

        return $response;
    }

    public function select(string $TblName, array $fields = ['*'], array $where = [], bool $fetchAll = true, string $params = ''): array
    {
        // 1. ساخت لیست فیلدها
        $fieldList = implode(', ', $fields);
        $sql = "SELECT $fieldList FROM `$TblName`";
        $values = [];

        if (!empty($where)) {
            // 2. ساخت عبارت WHERE با استفاده از '?'
            $whereClauses = [];
            // مثال: WHERE `key1` = ? AND `key2` = ?
            foreach ($where as $key => $value) {
                $whereClauses[] = "`$key` = ?";
                // 3. افزودن مقدار به آرایه $values
                $values[] = $value;
            }
            // 🔴 مشکل اینجاست - کلمه WHERE را اضافه کنید نه جایگزین کنید
            $sql .= " WHERE " . implode(" AND ", $whereClauses); // ✅ اصلاح شد
        }

        if (!empty($params)) {
            $sql .= " " . $params;
        }

        // 4. آماده‌سازی و اجرای کوئری با $values
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);

            if ($fetchAll) {
                $details = $stmt->fetchAll();
                $count = count($details);
                $result = $details
                    ? ['status' => 1, 'details' => $details, 'count' => $count]
                    : ['status' => 0, 'details' => '', 'count' => 0];
            } else {
                // واکشی تنها یک رکورد (برای fetchAll = false)
                $details = $stmt->fetch();
                $result = $details
                    ? ['status' => 1, 'details' => $details]
                    : ['status' => 0, 'details' => ''];
            }
        } catch (\PDOException $e) {
            // مدیریت خطا
            error_log("DB SELECT error: " . $e->getMessage());
            $result = ['status' => 0, 'details' => 'Error selecting records: ' . $e->getMessage()];
        }
        // 5. واکشی نتایج (fetch)
        return $result;
    }

    /**
     * به‌روزرسانی رکوردها در جدول.
     * @param string $TblName نام جدول
     * @param array $data آرایه انجمنی از ستون‌ها و مقادیر برای به‌روزرسانی
     * @param array $where آرایه انجمنی از ستون‌ها و مقادیر برای شرط WHERE
     * @return array نتیجه عملیات
     */
    public function update(string $TblName, array $data, array $where): array
    {
        if (empty($data) || empty($where)) {
            return ['status' => 0, 'response' => 'Data or WHERE clause is missing.'];
        }

        $setClauses = [];
        $whereClauses = [];
        $values = [];

        foreach ($data as $key => $value) {
            $setClauses[] = "`$key` = ?";
            $values[] = $value;
        }
        $setSql = implode(', ', $setClauses);

        foreach ($where as $key => $value) {
            $whereClauses[] = "`$key` = ?";
            $values[] = $value;
        }
        $whereSql = implode(' AND ', $whereClauses);


        $sql = "UPDATE `$TblName` SET $setSql WHERE $whereSql";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);

            $rowsAffected = $stmt->rowCount();

            return ['status' => 1, 'response' => 'Done!', 'rows_affected' => $rowsAffected];

        } catch (\PDOException $e) {
            error_log("DB UPDATE error: " . $e->getMessage());
            return ['status' => 0, 'response' => 'Error updating record: ' . $e->getMessage()];
        }
    }

    public function delete(string $TblName, array $where): array
    {
        if (empty($where)) {

            return ['status' => 0, 'response' => 'DELETE operation requires a WHERE clause for safety.'];
        }

        $sql = "DELETE FROM `$TblName`";
        $values = [];

        $whereClauses = [];
        foreach ($where as $key => $value) {
            $whereClauses[] = "`$key` = ?";
            $values[] = $value;
        }

        $sql .= " WHERE " . implode(' AND ', $whereClauses);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);

            $rowsAffected = $stmt->rowCount();

            return ['status' => 1, 'response' => 'Done!', 'rows_affected' => $rowsAffected];

        } catch (\PDOException $e) {
            error_log("DB DELETE error: " . $e->getMessage());
            return ['status' => 0, 'response' => 'Error deleting record: ' . $e->getMessage()];
        }
    }

    public function json_parse(string $json): array
    {
        return json_decode($json, true);
    }


    public function query(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $sqlUpper = strtoupper(trim($sql));

            if (strpos($sqlUpper, 'SELECT') === 0) {
                $details = $stmt->fetchAll();
                return [
                    'status' => 1,
                    'details' => $details,
                    'count' => count($details)
                ];
            } elseif (strpos($sqlUpper, 'INSERT') === 0) {
                return [
                    'status' => 1,
                    'response' => 'Inserted successfully',
                    'last_id' => $this->pdo->lastInsertId()
                ];
            } else {
                return [
                    'status' => 1,
                    'response' => 'Query executed successfully',
                    'rows_affected' => $stmt->rowCount()
                ];
            }

        } catch (\PDOException $e) {
            error_log("DB QUERY error: " . $e->getMessage());
            return [
                'status' => 0,
                'response' => 'Query error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * alias برای متد query (برای راحتی)
     */
    public function rawQuery(string $sql, array $params = []): array
    {
        return $this->query($sql, $params);
    }

    /**
     * دریافت اتصال PDO برای موارد خاص (مثل تراکنش‌های دستی)
     */
    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    /**
     * شروع تراکنش
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * commit تراکنش
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * rollback تراکنش
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

}
