<?php

namespace Cardlink\Checkout\Helper;

use Cardlink\Checkout\Logger\Logger;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\ResourceInterface;

/**
 * Helper class containing methods to handle version checking functionalities.
 * 
 * @author Cardlink S.A.
 */
class Version extends AbstractHelper
{
    const XML_PATH_LAST_SEEN_VERSION = 'payment/cardlink_checkout/last_seen_version';
    const VERSION_DOWNLOAD_URL = 'https://github.com/Cardlink-SA/magento2-cardlink-payment-gateway/';
    const PUBLISHED_MODULE_CONFIGURATION_XML_URL = '';

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var WriterInterface
     */
    protected $configWriter;

    /**
     * @var Data
     */
    protected $dataHelper;

    /**
     * Constructor.
     * 
     * @param Logger $logger
     * @param ResourceConnection $resource
     * @param ResourceInterface $moduleResource
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param Data $dataHelper
     */
    public function __construct(
        Logger $logger,
        ResourceConnection $resource,
        ResourceInterface $moduleResource,
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        Data $dataHelper
    ) {
        $this->logger = $logger;
        $this->resource = $resource;
        $this->moduleResource = $moduleResource;
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->dataHelper = $dataHelper;
    }

    /**
     * Returns the last seen version of the module stored in the database or the currently installed module version.
     * 
     * @return string
     */
    public function getLastSeenVersion()
    {
        // Retrieve last seen module version from the config data store.
        $version = $this->scopeConfig->getValue(
            self::XML_PATH_LAST_SEEN_VERSION,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            0
        );

        // If no version already stored, return current module version.
        if (!isset($version) || $version == '') {
            $version = self::getCurrentlyInstalledVersion();
        }

        return $version;
    }

    /**
     * Stores a new version string to the database.
     * 
     * @param string The version string to store.
     */
    public function setLastSeenVersion($version)
    {
        $this->configWriter->save(self::XML_PATH_LAST_SEEN_VERSION, $version, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    /**
     * Retrieves the currently installed version of the module.
     * 
     * @param string
     */
    public function getCurrentlyInstalledVersion()
    {
        return $this->moduleResource->getDbVersion('Cardlink_Checkout');
    }

    /**
     * Contact the module's code repository and retrieve the currently available version.
     */
    public function getLatestPublishedVersion()
    {
        try {

            /*  if (self::PUBLISHED_MODULE_CONFIGURATION_XML_URL == '') {
                return array(
                    'version' => self::getCurrentlyInstalledVersion(),
                    'comment' => ''
                );
            } */

            /* $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::PUBLISHED_MODULE_CONFIGURATION_XML_URL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 1);

            $contentConfigXmlFile = curl_exec($ch); */

            $contentConfigXmlFile = '<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Cardlink_Checkout" setup_version="0.1.1" schema_version="0.1.0">
        <sequence>
            <module name="Magento_Sales"/>
            <module name="Magento_Payment"/>
            <module name="Magento_Checkout"/>
            <module name="Magento_Directory" />
            <module name="Magento_Config" />
        </sequence>
    </module>
</config>';

            $xml = simplexml_load_string($contentConfigXmlFile);
            $modulesConfig = json_decode(json_encode($xml), TRUE);

            return array(
                'version' => $modulesConfig['module']['@attributes']['setup_version'],
            );
        } catch (\Exception $exception) {
            $this->logger->error('Failed to fetch version update information.');
            return null;
        }
    }
}
