<?php
/*
 * This source file is proprietary of Beech Applications bv.
 * Created by: Ruud Silvrants
 * Date: 01/04/2020
 * All code (c) Beech Applications bv. all rights reserverd
 */

namespace BeechIt\BackupRestore\Service;


use BeechIt\BackupRestore\Repository\StorageRepository;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorageInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class StorageService
{
    /**
     * @return array
     */
    public function getOnlineLocalStorages()
    {
        $storageInfo = [];
        $storageInfo[] = $this->getDefaultLegacyStorage();
        $storages = $this->getStorageRepository()->getStorages();
        /** @var ResourceFactory $resourceFactory */
        foreach ((array)$storages as $storageRecord) {
            if ($storageRecord['driver'] !== 'Local' || empty($storageRecord['is_online'])) {
                continue;
            }
            $basePath = $this->getFullBasePathFromConfiguration($storageRecord['configuration']);
            if ($basePath) {
                $storageInfo[] = [
                    'id' => $storageRecord['uid'],
                    'name' => $storageRecord['name'],
                    'folder' => $basePath,
                    'exclude' => [$storageRecord['processingfolder'] ?: ResourceStorageInterface::DEFAULT_ProcessingFolder],
                    'backupFile' => 'storage-' . $storageRecord['uid'] . '.tgz',
                ];
            }
        }

        return $storageInfo;
    }
    /**
     * @return array
     */
    public function getDefaultLegacyStorage(): array
    {
        return [
            'id' => 'uploads',
            'name' => 'uploads',
            'folder' => realpath(Environment::getPublicPath() . '/uploads'),
            'exclude' => [],
            'backupFile' => 'uploads.tgz',
        ];
    }


    /**
     * @param $configurationArray
     * @return string
     */
    protected function getFullBasePathFromConfiguration($configurationArray): string
    {
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $configuration = $resourceFactory->convertFlexFormDataToConfigurationArray($configurationArray);
        $basePath = '';
        if (!empty($configuration['basePath'])) {
            $basePath = rtrim($configuration['basePath'], '/');
        }
        if (strpos($basePath, '/') !== 0) {
            $basePath = Environment::getPublicPath() . '/' . $basePath;
        }
        return $basePath;
    }

    /**
     * @return StorageRepository
     */
    protected function getStorageRepository(): StorageRepository
    {
        return GeneralUtility::makeInstance(StorageRepository::class);
    }


}