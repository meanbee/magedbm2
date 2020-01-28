<?php

namespace Meanbee\Magedbm2\Service\Storage;

use Aws\S3\S3Client;
use Meanbee\Magedbm2\Application;
use Meanbee\Magedbm2\Application\Config\Option;
use Meanbee\Magedbm2\Application\ConfigInterface;
use Meanbee\Magedbm2\Exception\ConfigurationException;
use Meanbee\Magedbm2\Exception\ServiceException;
use Meanbee\Magedbm2\Service\Storage\Data\File;
use Meanbee\Magedbm2\Service\StorageInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputOption;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class S3 implements StorageInterface, LoggerAwareInterface
{
    /** @var ConfigInterface */
    protected $config;

    /** @var S3Client */
    protected $client;

    /**
     * Default S3 client parameters.
     *
     * @var array
     */
    protected $default_params = [
        "version" => "latest",
    ];

    /**
     * @var string
     */
    private $purpose;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ConfigInterface $config, S3Client $client = null)
    {
        $this->config = $config;
        $this->logger = new NullLogger();

        if ($client) {
            $this->client = $client;
        }
    }

    /**
     * @inheritdoc
     */
    public function listProjects()
    {
        $bucket = $this->getBucket();

        try {
            $this->logger->debug(sprintf("Calling ListObjects on %s", $bucket));

            $objects = $this->getClient()->getIterator("ListObjects", [
                "Bucket" => $bucket,
            ]);

            $projects = [];
            foreach ($objects as $object) {
                if ($project = strstr($object["Key"], "/", true)) {
                    $projects[$project] = 1;
                }
            }
        } catch (\Exception $e) {
            throw new ServiceException($e->getMessage());
        }

        return array_keys($projects);
    }

    /**
     * @inheritdoc
     */
    public function listFiles($project)
    {
        $bucket = $this->getBucket();
        $prefix = $this->getFileKey($project, "");

        try {
            $this->logger->debug(sprintf("Calling ListObjects on %s with prefix %s", $bucket, $prefix));

            $objects = $this->getClient()->getIterator("ListObjects", [
                "Bucket" => $bucket,
                "Prefix" => $prefix,
            ]);

            $files = [];
            foreach ($objects as $object) {
                $file = new File();

                $file->name = substr(strrchr($object["Key"], "/"), 1);
                $file->project = $project;
                $file->size = $object["Size"];
                $file->last_modified = $object["LastModified"];

                $files[] = $file;
            }
        } catch (\Exception $e) {
            throw new ServiceException($e->getMessage());
        }

        return $files;
    }

    /**
     * @inheritdoc
     *
     * @return string|null
     */
    public function getLatestFile($project)
    {
        /** @var File $latest */
        $latest = null;

        foreach ($this->listFiles($project) as $file) {
            if (!$latest || $file->last_modified > $latest->last_modified) {
                $latest = $file;
            }
        }

        return ($latest) ? $latest->name : null;
    }

    /**
     * @inheritdoc
     */
    public function upload($project, $file)
    {
        $key = $this->getFileKey($project, basename($file));
        $bucket = $this->getBucket();

        try {
            $result = $this->getClient()->putObject([
                "Bucket"     => $bucket,
                "Key"        => $key,
                "SourceFile" => $file,
            ]);
        } catch (\Exception $e) {
            throw new ServiceException($e->getMessage());
        }

        return $result["ObjectURL"];
    }

    /**
     * @inheritdoc
     */
    public function download($project, $file)
    {
        $key = $this->getFileKey($project, $file);
        $bucket = $this->getBucket();
        $local_file = implode(DIRECTORY_SEPARATOR, [
            $this->getConfig()->get(Option::TEMPORARY_DIR),
            $file
        ]);

        try {
            $this->getClient()->getObject([
                "Bucket" => $bucket,
                "Key"    => $key,
                "SaveAs" => $local_file,
            ]);
        } catch (\Exception $e) {
            throw new ServiceException($e->getMessage());
        }

        return $local_file;
    }

    /**
     * @inheritdoc
     */
    public function delete($project, $file)
    {
        $key = $this->getFileKey($project, $file);
        $bucket = $this->getBucket();

        try {
            $this->getClient()->deleteObject([
                "Bucket" => $bucket,
                "Key"    => $key,
            ]);
        } catch (\Exception $e) {
            throw new ServiceException($e->getMessage());
        }
    }

    /**
     * @inheritdoc
     */
    public function clean($project, $keep = 5)
    {
        $bucket = $this->getBucket();
        $files = $this->listFiles($project);

        // Sort files by last_modified ascending
        usort($files, function (File $a, File $b) {
            if ($a->last_modified == $b->last_modified) {
                return 0;
            }

            return ($a->last_modified < $b->last_modified) ? -1 : 1;
        });

        // Slice the latest 5 files off and convert to array of keys
        $objects = array_map(function (File $file) {
            return [
                "Key" => ($file->name) ? $this->getFileKey($file->project, $file->name) : null,
            ];
        }, array_slice($files, 0, -1 * $keep));

        // Filter out empty keys
        $objects = array_filter($objects, function ($object) {
            return $object["Key"] != null;
        });

        // Delete remaining objects
        if (!empty($objects)) {
            try {
                $this->getClient()->deleteObjects([
                    "Bucket" => $bucket,
                    "Delete" => [
                        "Objects" => $objects,
                    ],
                ]);
            } catch (\Exception $e) {
                throw new ServiceException($e->getMessage());
            }
        }
    }

    /**
     * Get the configuration model.
     *
     * @return ConfigInterface
     */
    protected function getConfig()
    {
        return $this->config;
    }

    /**
     * Get the S3 API client.
     *
     * @return S3Client
     */
    protected function getClient()
    {
        if (!$this->client instanceof S3Client) {
            $params = $this->default_params;

            if ($region = $this->getConfig()->get(Option::STORAGE_REGION)) {
                $params['region'] = $region;
            }

            if (($access_key = $this->getConfig()->get(Option::STORAGE_ACCESS_KEY))
                && ($secret_key = $this->getConfig()->get(Option::STORAGE_SECRET_KEY))
            ) {
                $params["credentials"] = [
                    "key"    => $access_key,
                    "secret" => $secret_key,
                ];
            }

            $this->client = new S3Client($params);
        }

        return $this->client;
    }

    /**
     * Get the S3 key for the given file in the given project.
     *
     * @param string $project
     * @param string $file
     *
     * @return string
     */
    protected function getFileKey($project, $file)
    {
        return sprintf("%s/%s", $project, $file);
    }

    /**
     * @inheritdoc
     */
    public function validateConfiguration(): bool
    {
        if ($this->purpose === StorageInterface::PURPOSE_STRIPPED_DATABASE && !$this->getConfig()->get(Option::STORAGE_BUCKET, true)) {
            throw new ConfigurationException('A bucket needs to be defined');
        }

        if ($this->purpose === StorageInterface::PURPOSE_ANONYMISED_DATA && !$this->getConfig()->get(Option::STORAGE_DATA_BUCKET, true)) {
            throw new ConfigurationException('A data bucket needs to be defined');
        }

        try {
            // If we call this with invalid configuration then the client-side validation will mean that an exception
            // will be thrown.
            $this->getClient();
        } catch (\InvalidArgumentException $e) {
            throw new ConfigurationException($e->getMessage());
        }

        return true;
    }

    /**
     * @return mixed
     */
    private function getBucket()
    {
        if ($this->purpose === StorageInterface::PURPOSE_ANONYMISED_DATA) {
            return $this->getConfig()->get(Option::STORAGE_DATA_BUCKET);
        }

        return $this->getConfig()->get(Option::STORAGE_BUCKET);
    }

    /**
     * Define the purpose of this instantiation.
     *
     * @param $purpose
     * @return mixed
     */
    public function setPurpose($purpose)
    {
        $this->purpose = $purpose;
    }

    /**
     * Sets a logger instance on the object.
     *
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function checkIfFileExists($file, $project)
    {
        $bucket = $this->getBucket();
        $key = $this->getFileKey($project, $file);

        try {
            return $this->getClient()->doesObjectExist($bucket, $key);
        } catch (\Exception $e) {
            throw new ServiceException($e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function checkIfProjectExists($project)
    {
        $bucket = $this->getBucket();
        try {
            return $this->getClient()->doesObjectExist($bucket, $project);
        } catch (\Exception $e) {
            throw new ServiceException($e->getMessage());
        }
    }
}
