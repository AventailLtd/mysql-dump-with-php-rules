<?php

declare(strict_types = 1);

namespace AventailLtd\Dump;

use DBLaci\Framework\SQLUtils;
use Faker\Factory;
use Faker\Generator;
use Ifsnop\Mysqldump\Mysqldump;
use LogicException;
use PDO;

class Dump
{
    /**
     * @var string[]
     */
    public array $tables = [];
    /**
     * if not isset, output is written to stdout
     */
    protected string $sqlFileName;
    private PDO $pdo;
    private Generator $faker;

    /**
     * Fix tables order for dump. (The order of the tables missing from this array is random)
     * Prefer use depends() instead
     *
     * @var string[]
     */
    protected array $customTableOrder = [];

    /**
     * This variable will guarantee that we only add additional rows to the dump once.
     *
     * @var string[]
     */
    protected array $completedAdditionalRowsTables = [];

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
     * remove processed table from the queue
     */
    protected function removeTableFromQueue(string $table)
    {
        if (in_array($table, $this->tables)) {
            $this->tables = array_diff($this->tables, [$table]);
        }
    }

    protected function depends(string $table): void
    {
        if (!in_array($table, $this->tables)) {
            return;
        }
        $this->removeTableFromQueue($table);
        $this->dumpTable($table);
    }

    /**
     * Dump table, override for specify filters and custom rules.
     *
     * @param string $table
     */
    protected function dumpTable(string $table)
    {
        // Example for skipping table.
        //if (in_array($table, [
        //    'skip_this_table',
        //])) {
        //    $this->debug('DUMP: ' . $table . ' ... SKIPPED' . PHP_EOL);
        //    return;
        //}

        // Example for filtered and structure only tables.
        //switch ($table) {
        //    case 'withfilter_table':
        //        $this->dumpTableWithFilter($table, '`name` = "alma"');
        //        break;
        //    case 'structure_only_table':
        //        $this->dumpTableStructure($table);
        //        break;
        //    default:
        //        $this->dumpTableWithFilter($table, '');
        //        break;
        //}

        // Default filter table without any filter.
        $this->dumpTableWithFilter($table, '');
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
        $sqldump = '-- insert ' . $table . "(filter: \"" . $filter . "\")\n";
        $sqldump .= "/*!40101 SET NAMES utf8mb4 */;\n";
        $sqldump .= "/*!40000 ALTER TABLE `" . $table . "` DISABLE KEYS */;\n";
        $cnt = 0;
        $this->addToDump($sqldump);

        // quote hoz kell neki.
        SQLUtils::$db = $this->pdo;

        $res = $this->pdo->query("SELECT * FROM `" . $table . "` WHERE 1 = 1" . $filter);
        while ($row = $res->fetch()) {
            $row = $this->processRow($table, $row);
            if ($row === null) {
                continue;
            }

            $this->addToDump(SQLUtils::buildInsertSQL($table, $row) . ";\n");
            $cnt++;
        }

        if (!in_array($table, $this->completedAdditionalRowsTables, true)) {
            // néhány további sor, szintetikus adatok
            foreach ($this->listAdditionalRows($table) as $rowAdditional) {
                $this->addToDump(SQLUtils::buildInsertSQL($table, $rowAdditional) . ";\n");
                $cnt++;
            }
            $this->completedAdditionalRowsTables[] = $table;
        }

        $this->addToDump("/*!40000 ALTER TABLE `" . $table . "` ENABLE KEYS */;\n");
        $this->debug($table . ': ' . $cnt . " rows\n");
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
        // table structure without data (including view structures)
        $dump = new Mysqldump('mysql:host=' . getenv('MYSQL_HOST') . ';port=' . (getenv('MYSQL_PORT') ?: '3306') . ';dbname=' . getenv('MYSQL_DB') . ';charset=utf8mb4', getenv('MYSQL_USERNAME'), getenv('MYSQL_PASSWORD'), ['no-data' => true, 'disable-keys' => true]);
        $dump->start($this->sqlFileName ?? '');

        // összes tábla dump, de némelyiknél okosság kell.
        $res = $this->pdo->query('SHOW TABLES');

        while ($row = $res->fetch()) {
            $this->tables[] = reset($row); // első eleme a tömbnek, mert nem számozott: ['Tables_in_agrodev' => 'acquisition_item',]
        }

        $this->addToDump("START TRANSACTION;\nSET autocommit=0;\nSET unique_checks=0;\nSET foreign_key_checks=0;\n");
        while (count($this->tables)) {
            $this->dumpTable($this->getNextTable());
        }
        $this->addToDump("COMMIT;\n");

        if (isset($this->sqlFileName)) {
            $this->postProcessDump($this->sqlFileName);
        }

        if (isset($this->sqlFileName)) {
            $cmd = 'gzip -f -c ' . $this->sqlFileName . ' > ' . $this->getCompressedFileName();
            ob_start();
            $ret = 0;
            passthru($cmd, $ret);
            $output = ob_get_clean();
            if ($ret !== 0) {
                throw new \RuntimeException('Nem sikerült betömöríteni az sql dumpot: error: ' . $ret . ' command: ' . $cmd . "\n" . 'output: ' . $output);
            }
        }
    }

    /**
     * @return string
     */
    protected function getCompressedFileName(): string
    {
        return $this->sqlFileName . '.gz';
    }

    /**
     * replace content or do anything with the already written dump sql
     *
     * @string string $sqlFilename
     * @return void
     */
    public function postProcessDump(string $sqlFilename): void
    {
    }

    /**
     * Return next table for dump and remove it from queue.
     *
     * @return string
     */
    public function getNextTable(): string
    {
        if (count($this->customTableOrder) > 0) {
            $tableInOrder = array_shift($this->customTableOrder);

            if (!in_array($tableInOrder, $this->tables, true)) {
                throw new LogicException('Ordered table not found: ' . $tableInOrder);
            }
            $this->removeTableFromQueue($tableInOrder);
            return $tableInOrder;
        }

        // no custom order, fallback to fifo
        return array_shift($this->tables);
    }
}
