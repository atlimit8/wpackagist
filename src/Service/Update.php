<?php

namespace Outlandish\Wpackagist\Service;

use Composer\Package\Version\VersionParser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Statement;
use GuzzleHttp\Exception\GuzzleException;
use Outlandish\Wpackagist\Package\AbstractPackage;
use Outlandish\Wpackagist\Package\Plugin;
use Outlandish\Wpackagist\Package\Theme;
use Rarst\Guzzle\WporgClient;
use Symfony\Component\Console\Output\OutputInterface;

class Update
{
    /** @var Connection */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function update(OutputInterface $output, ?string $name = null)
    {
        $updateStmt = $this->connection->prepare(
            'UPDATE packages SET
            last_fetched = datetime("now"), versions = :json, is_active = 1, display_name = :display_name
            WHERE class_name = :class_name AND name = :name'
        );
        $deactivateStmt = $this->connection->prepare('UPDATE packages SET last_fetched = datetime("now"), is_active = 0 WHERE class_name = :class_name AND name = :name');

        if ($name) {
            $query = $this->connection->prepare('
                SELECT * FROM packages
                WHERE name = :name
            ');
            $query->bindValue("name", $name);
        } else {
            $query = $this->connection->prepare('
                SELECT * FROM packages
                WHERE last_fetched IS NULL
                OR last_fetched < datetime(last_committed, "+2 hours")
                OR (is_active = 0 AND last_committed > date("now", "-90 days") AND last_fetched < datetime("now", "-7 days"))
            ');
        }
        // get packages that have never been fetched or have been updated since last being fetched
        // or that are inactive but have been updated in the past 90 days and haven't been fetched in the past 7 days
        $query->execute();
        $packages = $query->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE);

        $count = count($packages);
        $versionParser = new VersionParser();


        $wporgClient = WporgClient::getClient();

        $output->writeln("Updating {$count} packages");

        foreach ($packages as $index => $package) {

            $percent = $index / $count * 100;

            $info = null;
            $fields = ['versions'];
            $type = $package instanceof Plugin ? 'plugin' : 'theme';
            try {
                if ($type === 'plugin') {
                    $info = $wporgClient->getPlugin($package->getName(), $fields);
                } else {
                    $info = $wporgClient->getTheme($package->getName(), $fields);
                }

                $output->writeln(sprintf("<info>%04.1f%%</info> Fetched %s %s", $percent, $type, $package->getName()));
            } catch (GuzzleException $exception) {
                $output->writeln("Skipped {$type} '{$package->getName()}' due to error: '{$exception->getMessage()}'");
            }


            if (empty($info)) {
                // Plugin is not active
                $this->deactivate($deactivateStmt, $package, 'not active', $output);

                continue;
            }

            //get versions as [version => url]
            $versions = $info['versions'] ?: [];

            //current version of plugin not present in tags so add it
            if (empty($versions[$info['version']])) {
                //add to front of array
                $versions = array_reverse($versions, true);
                $versions[$info['version']] = 'trunk';
                $versions = array_reverse($versions, true);
            }

            //all plugins have a dev-trunk version
            if ($package instanceof Plugin) {
                unset($versions['trunk']);
                $versions['dev-trunk'] = 'trunk';
            }

            foreach ($versions as $version => $url) {
                try {
                    //make sure versions are parseable by Composer
                    $versionParser->normalize($version);
                    if ($package instanceof Theme) {
                        //themes have different SVN folder structure
                        $versions[$version] = $version;
                    } elseif ($url !== 'trunk') {
                        //add ref to SVN tag
                        $versions[$version] = 'tags/' . $version;
                    } // else do nothing, for 'trunk'.
                } catch (\UnexpectedValueException $e) {
                    //version is invalid
                    unset($versions[$version]);
                }
            }

            if ($versions) {
                $updateStmt->execute([
                    ':display_name' => $info['name'],
                    ':class_name' => get_class($package),
                    ':name' => $package->getName(),
                    ':json' => json_encode($versions)
                ]);
            } else {
                // Plugin is not active
                $this->deactivate($deactivateStmt, $package, 'no versions found', $output);
            }
        }

        $stateUpdate = $this->connection->prepare('
            UPDATE state
            SET value = "yes" WHERE key="build_required"
        ');
        $stateUpdate->execute();
    }

    private function deactivate(Statement $statement, AbstractPackage $package, string $reason, OutputInterface $output)
    {
        $statement->execute([':class_name' => get_class($package), ':name' => $package->getName()]);
        $output->writeln(sprintf("<error>Deactivated package %s because %s</error>", $package->getName(), $reason));
    }
}
