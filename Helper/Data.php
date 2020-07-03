<?php
/*
 * @category    Sezzle
 * @package     Sezzle_Sezzlepay
 * @copyright   Copyright (c) Sezzle (https://www.sezzle.com/)
 */

namespace Sezzle\Sezzlepay\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem\Driver\File;
use Sezzle\Sezzlepay\Model\System\Config\Container\SezzleConfigInterface;
use Zend\Log\Logger;
use Zend\Log\Writer\Stream;

/**
 * Sezzle Helper
 */
class Data extends AbstractHelper
{
    const PRECISION = 2;
    const SEZZLE_LOG_FILE_PATH = '/var/log/sezzlepay.log';
    const SEZZLE_COMPOSER_FILE_PATH = '/app/code/Sezzle/Sezzlepay/composer.json';

    /**
     * @var SezzleConfigInterface
     */
    private $sezzleConfig;
    /**
     * @var File
     */
    private $file;
    /**
     * @var \Magento\Framework\Json\Helper\Data
     */
    private $jsonHelper;

    /**
     * Initialize dependencies.
     *
     * @param Context $context
     * @param File $file
     * @param \Magento\Framework\Json\Helper\Data $jsonHelper
     * @param SezzleConfigInterface $sezzleConfig
     */
    public function __construct(
        Context $context,
        File $file,
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        SezzleConfigInterface $sezzleConfig
    ) {
        $this->file = $file;
        $this->jsonHelper = $jsonHelper;
        $this->sezzleConfig = $sezzleConfig;
        parent::__construct($context);
    }

    /**
     * Dump Sezzle log actions
     *
     * @param string|null $msg
     * @return void
     * @throws NoSuchEntityException
     */
    public function logSezzleActions($data = null)
    {
        if ($this->sezzleConfig->isLogTrackerEnabled()) {
            $writer = new Stream(BP . self::SEZZLE_LOG_FILE_PATH);
            $logger = new Logger();
            $logger->addWriter($writer);
            $logger->info($data);
        }
    }

    /**
     * Export CSV string to array
     *
     * @param string $content
     * @param mixed $entityId
     * @return array
     */
    public function csvToArray($content)
    {
        $data = ['header' => [], 'data' => []];

        $lines = str_getcsv($content, "\n");
        foreach ($lines as $index => $line) {
            if ($index == 0) {
                $data['header'] = str_getcsv($line);
            } else {
                $row = array_combine($data['header'], str_getcsv($line));
                $data['data'][] = $row;
            }
        }

        return $data;
    }

    /**
     * Get Sezzle Module Version
     *
     * @throws FileSystemException
     */
    public function getVersion()
    {
        $file = $this->file->fileGetContents(BP . self::SEZZLE_COMPOSER_FILE_PATH);
        if ($file) {
            $contents = $this->jsonHelper->jsonDecode($file);
            if (is_array($contents) && isset($contents['version'])) {
                return $contents['version'];
            }
        }
        return '--';
    }

    /**
     * Get amount in cents
     *
     * @param float $amount
     * @return int
     */
    public function getAmountInCents($amount)
    {
        return (int)(round(
            $amount * 100,
            self::PRECISION
        ));
    }
}
