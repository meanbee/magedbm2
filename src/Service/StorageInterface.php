<?php

namespace Meanbee\Magedbm2\Service;

use Meanbee\Magedbm2\Service\Storage\Data\File;

interface StorageInterface extends ConfigurableServiceInterface
{
    const PURPOSE_STRIPPED_DATABASE = 'stripped-database';
    const PURPOSE_ANONYMISED_DATA   = 'anon-data';

    /**
     * Define the purpose of this instantiation.
     *
     * @param $purpose
     * @return mixed
     */
    public function setPurpose($purpose);

    /**
     * List available projects.
     *
     * @return string[]
     */
    public function listProjects();

    /**
     * List backup files available in the given project.
     *
     * @param string $project
     *
     * @return File[]
     */
    public function listFiles($project);

    /**
     * Get the name of the latest backup file in the given project.
     *
     * @param string $project
     *
     * @return string Backup file name.
     */
    public function getLatestFile($project);

    /**
     * Upload the given backup file to the given project.
     *
     * @param string $project
     * @param string $file
     *
     * @return string Uploaded file name.
     */
    public function upload($project, $file);

    /**
     * Download the given backup file from the given project.
     *
     * @param string $project
     * @param string $file
     *
     * @return string Path to the downloaded file
     */
    public function download($project, $file);

    /**
     * Delete the given backup file from the given project.
     *
     * @param string $project
     * @param string $file
     *
     * @return void
     */
    public function delete($project, $file);

    /**
     * Delete old backup files for the given project, keeping only the latest few.
     *
     * @param string $project
     * @param int    $keep The number of latest backup files to retain.
     *
     * @return void
     */
    public function clean($project, $keep = 5);

    /**
     * Check if the given file exists for the project to allow us to gracefully handle files that don't
     *
     * @param string $file The file to check the project for
     * @param string $project The project to check
     *
     * @return bool
     */
    public function checkIfFileExists($file, $project);

    /**
     * Check if the given project exists to allow us to gracefully handle projects that don't
     *
     * @param $project
     * @return bool
     */
    public function checkIfProjectExists($project);
}
