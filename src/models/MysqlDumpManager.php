<?php

namespace bs\dbManager\models;

use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\StringHelper;
use bs\dbManager\Module;

/**
 * Class MysqlDumpManager.
 */
class MysqlDumpManager extends BaseDumpManager
{
    /**
     * @param $path
     * @param array $dbInfo
     * @param array $dumpOptions
     * @return mixed
     */
    public function makeDumpCommand($path, array $dbInfo, array $dumpOptions)
    {
        // default port
        if (empty($dbInfo['port'])) {
            $dbInfo['port'] = '3306';
        }

        $optionFile = $this->createOptionFile($dbInfo);

        $arguments = [
            'mysqldump',
            '--defaults-extra-file=' . escapeshellarg($optionFile),
        ];

        if (isset($dbInfo['attributes']) && isset($dbInfo['attributes'][\PDO::MYSQL_ATTR_SSL_CA])) {
            $arguments[] = '--ssl-ca=' . $dbInfo['attributes'][\PDO::MYSQL_ATTR_SSL_CA];
        }
        if ($dumpOptions['schemaOnly']) {
            $arguments[] = '--no-data';
        }
        if ($dumpOptions['preset']) {
            $arguments[] = trim($dumpOptions['presetData']);
        }
        $arguments[] = $dbInfo['dbName'];
        if ($dumpOptions['isArchive']) {
            $arguments[] = '|';
            $arguments[] = 'gzip';
        }
        $arguments[] = '>';
        $arguments[] = $path;

        $this->registerCleanupHandler($optionFile);

        return implode(' ', $arguments);
    }


    /**
     * @param $path
     * @param array $dbInfo
     * @param array $restoreOptions
     * @return mixed
     */
    public function makeRestoreCommand($path, array $dbInfo, array $restoreOptions)
    {
        $arguments = [];
        if (StringHelper::endsWith($path, '.gz', false)) {
            $arguments[] = 'gunzip -c';
            $arguments[] = $path;
            $arguments[] = '|';
        }
        // default port
        if (empty($dbInfo['port'])) {
            $dbInfo['port'] = '3306';
        }

        $optionFile = $this->createOptionFile($dbInfo);

        $arguments = ArrayHelper::merge($arguments, [
            'mysql',
            '--defaults-extra-file=' . escapeshellarg($optionFile),
        ]);


        if ($restoreOptions['preset']) {
            $arguments[] = trim($restoreOptions['presetData']);
        }
        $arguments[] = $dbInfo['dbName'];
        if (!StringHelper::endsWith($path, '.gz', false)) {
            $arguments[] = '<';
            $arguments[] = $path;
        }

        $this->registerCleanupHandler($optionFile);

        return implode(' ', $arguments);
    }


    /**
     * 創建臨時選項文件 - 增強版，避免密碼泄露
     * @param array $dbInfo 數據庫連接信息
     * @return string 臨時文件路徑
     * @throws Exception 如果無法創建臨時文件
     */
    private function createOptionFile(array $dbInfo)
    {
        try {
            // 生成唯一的臨時文件名前綴，確保多租戶環境下不衝突
            $prefix = 'mysql_' . date('YmdHis') . '_' . mt_rand(10000, 99999) . '_';

            // 創建臨時文件
            $tempDir = Yii::getAlias('@runtime') . DIRECTORY_SEPARATOR . 'dbmanager';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            $optionFile = $tempDir . DIRECTORY_SEPARATOR . $prefix . '.cnf';

            // 寫入連接配置
            file_put_contents(
                $optionFile,
                "[client]
host={$dbInfo['host']}
port={$dbInfo['port']}
user={$dbInfo['username']}
password={$dbInfo['password']}
"
            );
            // 設置安全權限 - 只允許當前用戶讀寫
            chmod($optionFile, 0600);

            return $optionFile;
        } catch (\Exception $e) {
            throw $e;
        }
    }


    /**
     * 註冊清理臨時文件的處理器
     * @param string $filePath 文件路徑
     */
    private function registerCleanupHandler($filePath)
    {
        // 僅註冊腳本結束時的清理函數
        register_shutdown_function(function () use ($filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        });
    }
}
