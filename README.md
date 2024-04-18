env:

- MYSQL_HOST
- MYSQL_PORT (default 3306)
- MYSQL_DB
- MYSQL_USERNAME
- MYSQL_PASSWORD
- DUMP_FILENAME

you can ovveride rules if you create DumpOverride.php

```php
<?php

class DumpOverride extends Dump
{
    protected function processRow(string $table, array $row): array
    {
        // replace password/email if table is user for example
        return $row;
    }

    protected function listAdditional(string $table): array
    {
        // list rows to insert additionally to the dump
        return [];
    }

    protected function dumpTable(string $table)
    {
        // do something different - see the parent method
        parent::dumpTable($table);
    }
}
```

# Usage with php installed locally

MYSQL_HOST=localhost MYSQL_USERNAME=dbuser MYSQL_PASSWORD=12345 MYSQL_DB=mysqldb DUMP_FILENAME=sql.sql php index.php


# Usage with docker

Note: you need to clone the repo at this point!

docker run --rm -v /mnt/sourcecode:/var/www/html -v /mnt/sourcecode/data:/data -e MYSQL_HOST=localhost -e MYSQL_USERNAME=dbuser -e MYSQL_PASSWORD=12345 -e MYSQL_DB=mysqldb -e DUMP_FILENAME=/data/sql.sql aventailltd/docker-php:8.2.16-20240311 php /var/www/html/index.php

Dump to stdout:

docker run --rm -v /mnt/sourcecode:/var/www/html -e OVERRIDE_PHP_FILENAME=DumpOverride.php -e MYSQL_HOST=localhost -e MYSQL_USERNAME=dbuser -e MYSQL_PASSWORD=12345 -e MYSQL_DB=mysqldb aventailltd/docker-php:7.4-20210531 php /var/www/html/index.php

**Note:** -v option enables debug info, but stdout and stderr split is not working ATM, so only working with file mode.

# shell
docker run --rm -v ./:/var/www/html -u 1000 -it aventailltd/docker-php:8.2.16-20240311 bash

# Install composer vendor folder

docker run --rm -v /mnt/sourcecode:/var/www/html -u 1000 aventailltd/docker-php:8.2.16-20240311 composer install
