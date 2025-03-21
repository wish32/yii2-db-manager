<?php

namespace bs\dbManager;

use creocoder\flysystem\LocalFilesystem as CreocoderLocalFileSystem;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Module as BaseModule;
use yii\base\NotSupportedException;
use yii\base\UserException;
use yii\db\Connection;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\StringHelper;
use bs\dbManager\contracts\IDumpManager;
use bs\dbManager\models\MysqlDumpManager;
use bs\dbManager\models\PostgresDumpManager;

/**
 * Class Module.
 *
 * @package bs\dbManager
 */
class Module extends BaseModule
{
    /**
     * Path for backup directory.
     *
     * @var string $path
     */
    public $path;

    /**
     * Wish: 用於多租戶系統，備份數據庫的文件名稱
     */
    public $multiTenancyDir = null;

    /**
     * Array of available db-components for dump.
     *
     * @var array $dbList
     */
    public $dbList = ['db'];

    /**
     * Process Timeout.
     *
     * @var string $timeout
     */
    public $timeout = 3600;

    /**
     * You can setup favorite dump options presets foreach db.
     *
     * @example
     *    'customDumpOptions' => [
     *        'preset1' => '--triggers --single-transaction',
     *        'preset2' => '--replace --lock-all-tables',
     *    ],
     * @var array $customDumpOptions
     */
    public $customDumpOptions = [];

    /**
     * @see $customDumpOptions
     * @var array $customRestoreOptions
     */
    public $customRestoreOptions = [];

    /**
     * @var string
     */
    public $mysqlManagerClass = MysqlDumpManager::class;

    /**
     * @var string
     */
    public $postgresManagerClass = PostgresDumpManager::class;

    /**
     * @var callable|Closure $createManagerCallback
     * argument - dbInfo; expected reply - instance of bs\dbManager\contracts\IDumpManager or false, for default
     * @example
     * 'createManagerCallback' => function($dbInfo) {
     *     if($dbInfo['dbName'] == 'exclusive') {
     *         return new MyExclusiveManager;
     *     } else {
     *         return false;
     *     }
     * }
     */
    public $createManagerCallback;

    /**
     * @var array
     */
    protected $dbInfo = [];

    /**
     * @var array
     */
    protected $fileList = [];


    public $lastFiles = 3;

    /**
     * @var string the flysystem driver to be used.
     */
    public $flySystemDriver = CreocoderLocalFileSystem::class;

    /**
     * @throws InvalidConfigException
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        if (!empty($this->dbList)) {
            if (!ArrayHelper::isIndexed($this->dbList)) {
                throw  new InvalidConfigException('Property dbList must be as indexed array');
            }
            foreach ($this->dbList as $dbAlias) {
                /**
                 * @var Connection $db
                 */
                $db = Instance::ensure($dbAlias, Connection::class);
                $this->dbInfo[$dbAlias]['driverName'] = $db->driverName;
                $this->dbInfo[$dbAlias]['dsn'] = $db->dsn;
                $this->dbInfo[$dbAlias]['host'] = $this->getDsnAttribute('host', $db->dsn);
                $this->dbInfo[$dbAlias]['port'] = $this->getDsnAttribute('port', $db->dsn);
                $this->dbInfo[$dbAlias]['dbName'] = $this->getDsnAttribute('dbname', $db->dsn);
                $this->dbInfo[$dbAlias]['username'] = $db->username;
                $this->dbInfo[$dbAlias]['password'] = $db->password;
                $this->dbInfo[$dbAlias]['prefix'] = $db->tablePrefix;
                $this->dbInfo[$dbAlias]['attributes'] = $db->attributes;
            }
        }
        $this->path = Yii::getAlias($this->path);
        if (!StringHelper::endsWith($this->path, '/', false)) {
            $this->path .= '/';
        }
        if (!is_dir($this->path)) {
            throw new InvalidConfigException('Path is not directory');
        }
        if (!is_writable($this->path)) {
            throw new InvalidConfigException('Path is not writable! Check chmod!');
        }


        // Wish: Get multi-tenancy identifier
        $this->addMultiTenancyIdToPath();

        $this->fileList = FileHelper::findFiles($this->path, ['only' => ['*.sql', '*.gz']]);

        parent::init();
    }

    /**
     * Wish: 如果是多租戶系統，需要在配置文件中配置 multiTenancyDir 參數
     * 例如：
     * 'multiTenancyDir' => function() { 
     *      return 'app'.\frontend\models\Applications::currentApplicationId(); 
     * }
     * 
     * Get the multi-tenancy identifier
     * @return mixed
     * @throws \Exception
     */
    public function addMultiTenancyIdToPath()
    {
        if( !$this->multiTenancyDir ){
            return;
        }
        $tenantId = $this->getMultiTenancyId();
        
        // Append tenant ID to path if exists
        if ($tenantId !== null) {
            $this->path = $this->path. strtolower($tenantId);
        }
        
        // Create directory if not exists
        if (!file_exists($this->path)) {
            FileHelper::createDirectory($this->path);
        }
    }

    /**
     * 獲取多租戶ID
     */
    public function getMultiTenancyId()
    {
        if( !$this->multiTenancyDir ){
            return;
        }
        // Get multi-tenancy ID
        $tenantId = null;
        if (is_callable($this->multiTenancyDir)) {
            $tenantId = call_user_func($this->multiTenancyDir);
        }
        return $tenantId;
    }

    /**
     * Get info for selected database.
     *
     * @param $db
     * @return array
     * @throws UserException
     */
    public function getDbInfo($db)
    {
        $info = ArrayHelper::getValue($this->dbInfo, $db, null);
        if (!$info) {
            throw new UserException('Database with name ' . $db . ' not configured for dump.');
        }

        return $info;
    }

    /**
     * @return array
     */
    public function getFileList()
    {
        return $this->fileList;
    }

    /**
     * @param array $dbInfo
     * @return IDumpManager
     * @throws NotSupportedException
     */
    public function createManager($dbInfo)
    {
        if (is_callable($this->createManagerCallback)) {
            $result = call_user_func($this->createManagerCallback, $dbInfo);
            if ($result !== false) {
                return $result;
            }
        }
        if ($dbInfo['driverName'] === 'mysql') {
            return new $this->mysqlManagerClass;
        } elseif ($dbInfo['driverName'] === 'pgsql') {
            return new $this->postgresManagerClass;
        } else {
            throw new NotSupportedException($dbInfo['driverName'] . ' driver unsupported!');
        }
    }

    /**
     * @param $name
     * @param $dsn
     * @return null
     */
    protected function getDsnAttribute($name, $dsn)
    {
        if (preg_match('/' . $name . '=([^;]*)/', $dsn, $match)) {
            return $match[1];
        } else {
            return null;
        }
    }
}
