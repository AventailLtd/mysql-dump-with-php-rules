<?php

namespace AventailLtd\Dump;

use DBLaci\Framework\SQLUtils;
use Faker\Factory;
use Faker\Generator;
use Ifsnop\Mysqldump\Mysqldump;
use PDO;

class Dump
{
    public array $tables = [];
    private string $sqlFileName;
    private PDO $pdo;
    private Generator $faker;

    public function __construct(PDO $pdo, string $sqlFilename)
    {
        $this->pdo = $pdo;
        if (!empty($sqlFilename)) {
            $this->sqlFileName = $sqlFilename;
        }
    }

    /**
     * @param string $lang 'hu_HU'
     * @param int|null $seed set / init seed
     * @param bool $forceReseed reinitialize seed on already existing faker instance - use this when you explicitly need a seed number
     * @return Generator
     */
    public function getFaker(string $lang, ?int $seed, bool $forceReseed = false): Generator
    {
        if (isset($this->faker)) {
            if (!$forceReseed) {
                return $this->faker;
            }
        } else {
            $this->faker = Factory::create($lang);
        }
        if (isset($seed)) {
            $this->faker->seed($seed);
        }
        return $this->faker;
    }

    /**
     * override if you want rules. return null for ignore this row
     */
    protected function processRow(string $table, array $row): ?array
    {
        return $row;
    }

    /**
     * override if you want rules.
     */
    protected function listAdditionalRows(string $table): array
    {
        return [];
    }

    protected function debug(string $msg)
    {
        if (!isset($this->sqlFileName) && !in_array('-v', $_SERVER['argv'])) {
            return;
        }
        fwrite(STDERR, $msg);
    }

    /**
     * mivel a táblák sorrendje nem mindegy, és nem akarjuk kétszer ugyanazt, ezért ez a fv kitörli a tables tömbből a táblát.
     *
     * @param string $table
     */
    protected function dumpTable(string $table)
    {
        if (in_array($table, $this->tables)) {
            $this->tables = array_diff($this->tables, [$table]);
        }
        if (in_array($table, [
            'skip_this_table',
        ])) {
            // ha egy tábla tartalma kihagyható, akkor átugorjuk
            $this->debug('DUMP: ' . $table . ' ... SKIPPED' . PHP_EOL);
            return;
        }

        if (in_array('dependency1', $this->tables, true) && in_array($table, [
                'dependency1',
            ])) {
            $this->dumpTable('dependency1'); // kvázi függőség
        }

        switch ($table) {
            case 'withfilter_table':
                $this->dumpTableWithFilter($table, '`name` = "alma"');
                break;
            case 'structure_only_table':
                $this->dumpTableStructure($table);
                break;
            default:
                $this->dumpTableWithFilter($table, '');
                break;
        }
    }

    /**
     * csak a struktúra dumpolása
     *
     * @param string $table
     */
    protected function dumpTableStructure(string $table)
    {
        $this->dumpTableWithFilter($table, ' AND "0" = "1"'); // TODO: nem túl elegáns
    }

    /**
     * egy select exportálása sql-be
     *
     * @param string $table
     * @param string $filter
     */
    protected function dumpTableWithFilter(string $table, string $filter)
    {
        $res = $this->pdo->query("SELECT * FROM `" . $table . "` WHERE 1 = 1" . $filter);
        $sqldump = '-- insert ' . $table . "(filter: \"" . $filter . "\")\n";
        $sqldump .= "/*!40101 SET NAMES utf8mb4 */;\n";
        $sqldump .= "/*!40000 ALTER TABLE `" . $table . "` DISABLE KEYS */;\n";
        $cnt = 0;
        $this->addToDump($sqldump);

        // quote hoz kell neki.
        SQLUtils::$db = $this->pdo;

        while ($row = $res->fetch()) {
            $row = $this->processRow($table, $row);
            if ($row === null) {
                continue;
            }

            $this->addToDump(SQLUtils::buildInsertSQL($table, $row) . ";\n");
            $cnt++;
        }

        // néhány további sor, szintetikus adatok
        foreach ($this->listAdditionalRows($table) as $rowAdditional) {
            $this->addToDump(SQLUtils::buildInsertSQL($table, $rowAdditional) . ";\n");
            $cnt++;
        }
        $this->addToDump("/*!40000 ALTER TABLE `" . $table . "` ENABLE KEYS */;\n");
        $this->debug($cnt . " rows\n");
    }

    /**
     * add string content (typically sql) to dump
     *
     * @param string $dumpString
     */
    private function addToDump(string $dumpString)
    {
        if (isset($this->sqlFileName)) {
            file_put_contents($this->sqlFileName, $dumpString, FILE_APPEND);
        } else {
            echo $dumpString;
        }
    }

    public function run()
    {
        // table structure without data
        $dump = new Mysqldump('mysql:host=' . getenv('MYSQL_HOST') . ';port=' . (getenv('MYSQL_PORT') ?: '3306') . ';dbname=' . getenv('MYSQL_DB'), getenv('MYSQL_USERNAME'), getenv('MYSQL_PASSWORD'), ['no-data' => true, 'disable-keys' => true]);
        //$dump->start($this->sqlFileName);
        $dump->start();

        // összes tábla dump, de némelyiknél okosság kell.
        $res = $this->pdo->query('SHOW TABLES');

        while ($row = $res->fetch()) {
            $this->tables[] = reset($row); // első eleme a tömbnek, mert nem számozott: ['Tables_in_agrodev' => 'acquisition_item',]
        }

        $this->addToDump("START TRANSACTION;\nSET autocommit=0;\nSET unique_checks=0;\nSET foreign_key_checks=0;\n");
        while (count($this->tables)) {
            $this->dumpTable(array_pop($this->tables));
        }
        $this->addToDump("COMMIT;\n");
        if (isset($this->sqlFileName)) {
            $cmd = 'gzip -f ' . $this->sqlFileName;
            ob_start();
            $ret = 0;
            passthru($cmd, $ret);
            $output = ob_get_clean();
            if ($ret !== 0) {
                throw new \Exception('Nem sikerült betömöríteni az sql dumpot: error: ' . $ret . ' command: ' . $cmd . "\n" . 'output: ' . $output);
            }
        }
    }
}
