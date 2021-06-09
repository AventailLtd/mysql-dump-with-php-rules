<?php

namespace DBLaci\Framework;

use Pb\PDO\Database;

class SQLUtils
{
    /**
     * @var Database
     */
    public static $db;

    /**
     * altalanos mysql insert
     *
     * @param string $table
     * @param array $data
     * @param bool $replace
     * @return string
     */
    public static function buildInsertSQL(string $table, array $data, bool $replace = false)
    {
        if ($replace) {
            $sql = 'REPLACE';
        } else {
            $sql = 'INSERT';
        }
        $sql .= ' INTO `' . $table . '`';
        $sqlset = '';
        $first = true;
        foreach ($data as $k => $m) {
            if (!$first) {
                $sqlset .= ', ';
            } else {
                $first = false;
            }
            $mit = '`' . $k . '`';
            if (is_array($m)) {
                $mire = $m[0];
            } elseif ($m === null) {
                $mire = 'NULL';
            } else {
                $mire = static::$db->quote($m);
            }

            $sqlset .= $mit . " = " . $mire;
        }

        if ($sqlset === '') {
            // lehet, hogy teljesen üres sor (ez elvileg nem hiba)
            $sql .= '() VALUES ()';
        } else {
            $sql .= ' SET ' . $sqlset;
        }
        return $sql;
    }
}
