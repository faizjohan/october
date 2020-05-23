<?php namespace System\Classes;

use ApplicationException;
use Config;
use System\Classes\FileManifest;

/**
 * Reads and stores the October CMS source manifest information.
 *
 * The source manifest is a meta JSON file, stored on GitHub, that contains the hashsums of all module files across all
 * buils of October CMS. This allows us to compare the October CMS installation against the expected file checksums and
 * determine the installed build and whether it has been modified.
 *
 * @package october\system
 * @author Ben Thomson
 */
class SourceManifest
{
    /**
     * @var string The URL to the source manifest
     */
    protected $source;

    /**
     * @var array Array of builds, keyed by build number, with files for keys and hashes for values.
     */
    protected $builds = [];

    /**
     * Constructor
     *
     * @param string $manifest Manifest file to load
     * @param bool $autoload Loads the manifest on construct
     */
    public function __construct($source = null, $autoload = true)
    {
        if (isset($source)) {
            $this->setSource($source);
        } else {
            $this->setSource(
                Config::get(
                    'cms.sourceManifestUrl',
                    'https://raw.githubusercontent.com/octoberrain/meta/master/manifest/builds.json'
                )
            );
        }

        if ($autoload) {
            $this->load();
        }
    }

    /**
     * Sets the source manifest URL.
     *
     * @param string $source
     * @return void
     */
    public function setSource($source)
    {
        if (is_string($source)) {
            $this->source = $source;
        }
    }

    /**
     * Loads the manifest file.
     *
     * @throws ApplicationException If the manifest is invalid, or cannot be parsed.
     */
    public function load()
    {
        $source = file_get_contents($this->source);
        if (empty($source)) {
            throw new ApplicationException(
                'Source manifest not found'
            );
        }

        $data = json_decode($source, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ApplicationException(
                'Unable to decode source manifest JSON data. JSON Error: ' . json_last_error_msg()
            );
        }
        if (!isset($data['manifest']) || !is_array($data['manifest'])) {
            throw new ApplicationException(
                'The source manifest at "' . $this->source . '" does not appear to be a valid source manifest file.'
            );
        }

        foreach ($data['manifest'] as $build) {
            $this->builds[$build['build']] = [
                'modules' => $build['modules'],
                'files' => $build['files'],
            ];
        }

        return $this;
    }

    /**
     * Adds a FileManifest instance as a build to this source manifest.
     *
     * Changes between builds are calculated and stored with the build. Builds are stored numerically, in ascending
     * order.
     *
     * @param integer $build Build number.
     * @param FileManifest $manifest The file manifest to add as a build.
     * @param integer $previous The previous build number, used to determine changes with this build.
     * @return void
     */
    public function addBuild($build, FileManifest $manifest, $previous = null)
    {
        $this->builds[(int) $build] = [
            'modules' => $manifest->getModuleChecksums(),
            'files' => $this->processChanges($manifest, $previous),
        ];

        // Sort builds numerically in ascending order.
        ksort($this->builds[$build], SORT_NUMERIC);
    }

    /**
     * Gets all builds.
     *
     * @return array
     */
    public function getBuilds()
    {
        return $this->builds;
    }

    /**
     * Generates the JSON data to be stored with the source manifest.
     *
     * @throws ApplicationException If no builds have been added to this source manifest.
     * @return string
     */
    public function generate()
    {
        if (!count($this->builds)) {
            throw new ApplicationException(
                'No builds have been added to the manifest.'
            );
        }

        $json = [
            'manifest' => [],
        ];

        foreach ($this->builds as $build => $details) {
            $json['manifest'][] = [
                'build' => $build,
                'modules' => $details['modules'],
                'files' => $details['files'],
            ];
        }

        return json_encode($json, JSON_PRETTY_PRINT);
    }

    /**
     * Gets the filelist state at a selected build.
     *
     * This method will list all expected files and hashsums at the specified build number.
     *
     * @param integer $build Build number to get the filelist state for.
     * @throws ApplicationException If the specified build has not been added to the source manifest.
     * @return array
     */
    public function getState($build)
    {
        if (!isset($this->builds[$build])) {
            throw new \Exception('The specified build has not been added.');
        }

        $state = [];

        foreach ($this->builds as $number => $details) {
            if (isset($details['files']['added'])) {
                foreach ($details['files']['added'] as $filename => $sum) {
                    $state[$filename] = $sum;
                }
            }
            if (isset($details['files']['modified'])) {
                foreach ($details['files']['modified'] as $filename => $sum) {
                    $state[$filename] = $sum;
                }
            }
            if (isset($details['files']['removed'])) {
                foreach ($details['files']['removed'] as $filename) {
                    unset($state[$filename]);
                }
            }

            if ($number === $build) {
                break;
            }
        }

        return $state;
    }

    /**
     * Compares a file manifest with the source manifest.
     *
     * This will determine the build of the October CMS installation.
     *
     * @param FileManifest $manifest The file manifest to compare against the source.
     * @return array|null Will return an array with the build, modified state and the probability that it is the
     *  version specified. If the detected version does not look like a likely candidate, this will return null.
     */
    public function compare(FileManifest $manifest)
    {
        $modules = $manifest->getModuleChecksums();

        // Look for an unmodified version
        foreach ($this->getBuilds() as $build => $details) {
            $matched = array_intersect_assoc($details['modules'], $modules);

            if (count($matched) === count($modules)) {
                return [
                    'build' => $build,
                    'modified' => false,
                ];
            }
        }

        // If we could not find an unmodified version, try to find the closest version and assume this is a modified
        // install.
        $buildMatch = [];

        foreach ($this->getBuilds() as $build => $details) {
            $state = $this->getState($build);

            // Include only the files that match the modules being loaded in this file manifest
            $availableModules = array_keys($modules);

            foreach ($state as $file => $sum) {
                // Determine module
                $module = explode(DIRECTORY_SEPARATOR, $file)[2];

                if (!in_array($module, $availableModules)) {
                    unset($state[$file]);
                }
            }

            $filesExpected = count($state);
            $filesFound = [];
            $filesChanged = [];

            foreach ($manifest->getFiles() as $file => $sum) {
                // Unknown new file
                if (!isset($state[$file])) {
                    $filesChanged[] = $file;
                    continue;
                }

                // Modified file
                if ($state[$file] !== $sum) {
                    $filesFound[] = $file;
                    $filesChanged[] = $file;
                    continue;
                }

                // Pristine file
                $filesFound[] = $file;
            }

            $foundPercent = count($filesFound) / $filesExpected;
            $changedPercent = count($filesChanged) / $filesExpected;

            $score = ((1 * $foundPercent) - $changedPercent);
            $buildMatch[$build] = round($score * 100, 2);
        }


        // Find likely version (we have to be at least 60% sure)
        $likelyBuild = array_search(max($buildMatch), $buildMatch);
        if ($buildMatch[$likelyBuild] < 60) {
            return null;
        }

        return [
            'build' => $likelyBuild,
            'modified' => true,
        ];
    }

    /**
     * Determines file changes between the specified build and the previous build.
     *
     * Will return an array of added, modified and removed files.
     *
     * @param FileManifest $manifest The current build's file manifest.
     * @param integer $previous The previous build number, used to determine changes with this build.
     * @return array
     */
    protected function processChanges(FileManifest $manifest, $previous = null)
    {
        // If no previous build has been provided, all files are added
        if (is_null($previous)) {
            return [
                'added' => $manifest->getFiles(),
            ];
        }

        // Only save files if they are changing the "state" of the manifest (ie. the file is modified, added or removed)
        $state = $this->getState($previous);
        $added = [];
        $modified = [];

        foreach ($manifest->getFiles() as $file => $sum) {
            if (!isset($state[$file])) {
                $added[$file] = $sum;
                continue;
            } else {
                if ($state[$file] !== $sum) {
                    $modified[$file] = $sum;
                }
                unset($state[$file]);
            }
        }

        // Any files still left in state have been removed
        $removed = array_keys($state);

        $changes = [];
        if (count($added)) {
            $changes['added'] = $added;
        }
        if (count($modified)) {
            $changes['modified'] = $modified;
        }
        if (count($removed)) {
            $changes['removed'] = $removed;
        }

        return $changes;
    }
}
