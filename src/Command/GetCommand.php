<?php

namespace Meanbee\Magedbm2\Command;

use Meanbee\Magedbm2\Application\ConfigInterface;
use Meanbee\Magedbm2\Application\Config\Option;
use Meanbee\Magedbm2\Service\DatabaseFactory;
use Meanbee\Magedbm2\Service\DatabaseInterface;
use Meanbee\Magedbm2\Service\FilesystemFactory;
use Meanbee\Magedbm2\Service\FilesystemInterface;
use Meanbee\Magedbm2\Exception\ServiceException;
use Meanbee\Magedbm2\Service\StorageFactory;
use Meanbee\Magedbm2\Service\StorageInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class GetCommand extends BaseCommand
{
    const RETURN_CODE_DOWNLOAD_ERROR   = 1;
    const RETURN_CODE_FILESYSTEM_ERROR = 2;
    const RETURN_CODE_DATABASE_ERROR   = 3;
    const NAME                         = "get";

    /** @var DatabaseInterface */
    protected $database;

    /** @var StorageInterface */
    protected $storage;

    /** @var FilesystemInterface */
    protected $filesystem;

    /**
     * @param ConfigInterface $config
     * @param DatabaseFactory $databaseFactory
     * @param StorageFactory $storageFactory
     * @param FilesystemFactory $filesystemFactory
     */
    public function __construct(
        ConfigInterface $config,
        DatabaseFactory $databaseFactory,
        StorageFactory $storageFactory,
        FilesystemFactory $filesystemFactory
    ) {
        parent::__construct($config, self::NAME);

        $this->database = $databaseFactory->create();
        $this->storage = $storageFactory->create();
        $this->filesystem = $filesystemFactory->create();

        $this->storage->setPurpose(StorageInterface::PURPOSE_STRIPPED_DATABASE);

        $this->ensureServiceConfigurationValidated('storage', $this->storage);
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName(self::NAME)
            ->setDescription("Download and import a database backup.")
            ->addArgument(
                "project",
                InputArgument::REQUIRED,
                "Project identifier."
            )
            ->addArgument(
                "file",
                InputArgument::OPTIONAL,
                "Backup file to import. If not specified, imports the latest available file."
            )
            ->addOption(
                Option::DOWNLOAD_ONLY,
                "o",
                InputOption::VALUE_NONE,
                "Output the back up file into the current directory, instead of importing it."
            )
            ->addOption(
                Option::FORCE,
                "f",
                InputOption::VALUE_NONE,
                "Skip database import confirmation."
            );
    }

    /**
     * @inheritdoc
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$input->getOption('download-only')) {
            $this->ensureServiceConfigurationValidated('database', $this->database);
        }

        if (($parentExitCode = parent::execute($input, $output)) !== self::RETURN_CODE_SUCCESS) {
            return $parentExitCode;
        }

        $this->database->setLogger($this->getLogger());

        $project = $input->getArgument("project");
        $file = $input->getArgument("file");

        if ($this->needsUserConfirmation() && !$this->confirmUserIsOkToProceed()) {
            return static::RETURN_CODE_SUCCESS;
        }

        try {
            if ($this->storage->checkIfProjectExists($project) === false) {
                $output->writeln(sprintf("<error>Requested project does not exist: %s </error>", $project));
                return static::RETURN_CODE_DOWNLOAD_ERROR;
            }

            if (!$file) {
                $file = $this->storage->getLatestFile($project);
                if (!$file) {
                    $output->writeln(sprintf("<error>Requested file does not exist: %s </error>", $file));
                    return static::RETURN_CODE_DOWNLOAD_ERROR;
                }
            }

            $output->writeln(
                sprintf("<info>Downloading backup file %s...</info>", $file),
                OutputInterface::VERBOSITY_VERBOSE
            );

            $local_file = $this->storage->download($project, $file);
        } catch (ServiceException $e) {
            $output->writeln(sprintf("<error>Failed to download the backup file: %s</error>", $e->getMessage()));

            return static::RETURN_CODE_DOWNLOAD_ERROR;
        }

        if ($input->getOption(Option::DOWNLOAD_ONLY)) {
            $output_file = getcwd() . DIRECTORY_SEPARATOR . $file;

            if ($this->filesystem->move($local_file, $output_file)) {
                $output->writeln(sprintf("<info>Backup file downloaded to %s</info>", $output_file));
            } else {
                $output->writeln(sprintf(
                    "<error>Failed to move the downloaded backup file. Backup saved in %s</error>",
                    $local_file
                ));

                return static::RETURN_CODE_FILESYSTEM_ERROR;
            }
        } else {
            $output->writeln(
                "<info>Importing downloaded backup file into the database...</info>",
                OutputInterface::VERBOSITY_VERBOSE
            );

            try {
                $this->database->import($local_file);
            } catch (ServiceException $e) {
                $output->writeln(sprintf(
                    "<error>Failed to import backup file '%s': %s</error>",
                    $file,
                    $e->getMessage()
                ));
                $this->filesystem->delete($local_file);

                return static::RETURN_CODE_DATABASE_ERROR;
            }
            $output->writeln(sprintf("<info>Backup %s downloaded and imported into the database.</info>", $file));
        }

        $this->filesystem->delete($local_file);

        return static::RETURN_CODE_SUCCESS;
    }

    /**
     * Establish whether or not we should be asking the user to confirm this action before proceeding.
     */
    private function needsUserConfirmation(): bool
    {
        $isForced = $this->input->getOption(Option::FORCE);
        $isDownloadOnly = $this->input->getOption(Option::DOWNLOAD_ONLY);

        // Require confirm if we're not forcing and if we're importing a database (not just downloading)
        return !$isForced && !$isDownloadOnly;
    }

    /**
     * Prompt the user for confirmation that it's ok to proceed.
     */
    private function confirmUserIsOkToProceed(): bool
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper("question");
        $question = new ConfirmationQuestion(
            "Are you sure you with to overwrite the local database? [y/N] ",
            false
        );

        return (bool) $helper->ask($this->input, $this->output, $question);
    }
}
