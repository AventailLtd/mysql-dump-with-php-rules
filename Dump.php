<?php

use DBLaci\Framework\SQLUtils;
use Ifsnop\Mysqldump\Mysqldump;

class Dump
{
    public array $tables = [];
    private string $sqlFileName;
    private PDO $pdo;

    public function __construct(PDO $pdo, string $sqlFilename)
    {
        $this->pdo = $pdo;
        if (!empty($sqlFilename)) {
            $this->sqlFileName = $sqlFilename;
        }
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
        fwrite(STDERR, $msg);
    }

    /**
     * mivel a táblák sorrendje nem mindegy, és nem akarjuk kétszer ugyanazt, ezért ez a fv kitörli a tables tömbből a táblát.
     *
     * @param string $table
     */
    protected function dumpTable(string $table)
    {
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
    private function dumpTableStructure(string $table)
    {
        $this->dumpTableWithFilter($table, ' AND "0" = "1"'); // TODO: nem túl elegáns
    }

    /**
     * egy select exportálása sql-be
     *
     * @param string $table
     * @param string $filter
     * @throws EtalonInstantiationException
     */
    private function dumpTableWithFilter(string $table, string $filter)
    {
        $res = $this->pdo->query("SELECT * FROM " . $table . " WHERE 1 = 1" . $filter);
        $sqldump = '-- insert ' . $table . "(filter: \"" . $filter . "\")\n";
        $sqldump .= "/*!40101 SET NAMES utf8mb4 */;\n";
        $cnt = 0;

        // quote hoz kell neki.
        SQLUtils::$db = $this->pdo;

        while ($row = $res->fetch()) {
            $row = $this->processRow($table, $row);
            if ($row === null) {
                continue;
            }

            $sqldump .= SQLUtils::buildInsertSQL($table, $row) . ";\n";
            $cnt++;
        }

        // néhány további sor, szintetikus adatok
        foreach ($this->listAdditionalRows($table) as $rowAdditional) {
            $sqldump .= SQLUtils::buildInsertSQL($table, $rowAdditional) . ";\n";
            $cnt++;
        }
        $this->addToDump($sqldump);
        $this->debug($cnt . " rows\n");
    }

    private function addToDump(string $dumpString)
    {
        if (isset($this->sqlFileName)) {
            file_put_contents($this->sqlFileName, $dumpString, FILE_APPEND);
        } else {
            echo $dumpString;
        }
    }

    /**
     * @throws ArchiveCorruptedException
     * @throws ArchiveIOException
     * @throws ArchiveIllegalCompressionException
     * @throws FileInfoException
     */
    public function run()
    {
        // table structure without data
        $dump = new Mysqldump('mysql:host=' . getenv('MYSQL_HOST') . ';port=' . (getenv('MYSQL_PORT') ?: '3306') . ';dbname=' . getenv('MYSQL_DB'), getenv('MYSQL_USERNAME'), getenv('MYSQL_PASSWORD'), ['no-data' => true]);
        $dump->start($sqlfilename);

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
