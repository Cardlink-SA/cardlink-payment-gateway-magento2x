<?php

namespace Cardlink\Checkout\Cron;

use Cardlink\Checkout\Helper\Version;
use Magento\Framework\Notification\NotifierInterface as NotifierPool;

/**
 * Cronjob used to poll the module's code repository for available new versions in order to notify the administrators.
 * 
 * @author Cardlink S.A.
 */
class CheckUpdate
{
    /**
     * Notifier Pool
     *
     * @var NotifierPool
     */
    protected $notifierPool;

    /**
     * @var Version
     */
    private $versionHelper;

    /**
     * Constructor.
     * 
     * @param NotifierPool $notifierPool
     * @param Version $versionHelper
     */
    public function __construct(
        NotifierPool $notifierPool,
        Version $versionHelper
    ) {
        $this->notifierPool = $notifierPool;
        $this->versionHelper = $versionHelper;
    }

    /**
     * Action execution method.
     */
    public function execute()
    {
        try {
            // Retrieve the previously seen version of the module stored in the database.
            $previouslyFoundVersion = $this->versionHelper->getLastSeenVersion();
            // Retrieve the currently published version from the code repository.
            $publishedVersionData = $this->versionHelper->getLatestPublishedVersion();
            // Compare versions and identify whether a newer version exists.
            $hasNewVersion = version_compare($publishedVersionData['version'], $previouslyFoundVersion, '>');

            // If a new version has been published.
            if ($hasNewVersion) {

                // Add an admin notification for the new version.
                $this->notifierPool->addMajor(
                    "Cardlink Payment Gateway - New version {$publishedVersionData['version']} now available for download!",
                    'Read the details on how to install the new version.',
                    Version::VERSION_DOWNLOAD_URL
                );

                // Store the currently published version back to the database to prevent multiple notification messages from getting posted.
                $this->versionHelper->setLastSeenVersion($publishedVersionData['version']);
            }
        } catch (\Exception $ex) {
        }
        return $this;
    }
}
