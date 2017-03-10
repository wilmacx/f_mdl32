<?php

/**
 * Extended copy of native mysqli class (representing moodle database interface).
 * This class provide simple read/write splitting by substitute mysqli object.
 *
 * It contains parts from yii2\db\Connection.
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 *
 *
 * @package    core_dml
 * @copyright  2016 Dmitriy Kuzevanov
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/mysqli_native_moodle_database.php');

class mysqli_ms_native_moodle_database extends mysqli_native_moodle_database
{
    /**
     * @var mysqli the currently active master connection
     */
    private $_master;
    /**
     * @var mysqli the currently active slave connection
     */
    private $_slave;
    /**
     * @var array list of slave connection configurations. Each configuration is used to create a slave DB connection.
     * When [[enableSlaves]] is true, one of these configurations will be chosen and used to create a DB connection
     * for performing read queries only.
     */
    private $_slavesConfigs = array();
    /**
     * @var cache the cache object or the ID of the cache application component that is used to store
     * the health status of the DB servers specified in [[masters]] and [[slaves]].
     */
    private $server_status_cache;
    /**
     * @var bool whether is there currently active master connection
     */
    private $active = false;
    /**
     * @var bool whether is there currently active transaction
     */
    private $transaction = false;
    /**
     * @var boolean whether to disable read/write splitting by using [[slaves]] to read data.
     * Note that if [[slaves]] is empty, read/write splitting will NOT be enabled no matter what value this property takes.
     */
    protected $disable_slaves = false;
    /**
     * @var bool whether is master disabled.
     */
    protected $disable_master = false;
    /**
     * @var int The database reads on slave (performance counter).
     */
    protected $reads_on_slave = 0;
    /**
     * @var integer the retry interval in seconds for dead servers listed in [[masters]] and [[slaves]].
     * This is used together with [[serverStatusCache]].
     */
    public $server_retry_interval = 600;
    /**
     * @var integer Last query type.
     */
    public $query_type;
    /**
     * @var array
     */
    protected $locked_on_master_classes = array('backup', 'db_record_lock_factory');

    /**
     * {@inheritdoc}
     */
    public function __construct($external)
    {
        global $CFG;

        if (isset($CFG->dbtimeout)) {
            $this->server_retry_interval = (int)$CFG->dbtimeout;
        }

        $this->_slavesConfigs = isset($CFG->dbslaves) && is_array($CFG->dbslaves) ? $CFG->dbslaves : array();

        parent::__construct($external);
    }

    /**
     * {@inheritdoc}
     */
    public function connect($dbhost, $dbuser, $dbpass, $dbname, $prefix, array $dboptions = null)
    {
        try {
            parent::connect($dbhost, $dbuser, $dbpass, $dbname, $prefix, $dboptions);
            $this->_master = $this->mysqli;
            unset($this->mysqli);
            $this->active = true;
        } catch (moodle_exception $e) {
            self::process_moodle_exception($e);
        }

        return true;
    }

    /**
     * @return cache|cache_application|cache_session|cache_store
     */
    protected function get_cache()
    {
        if (!($this->server_status_cache instanceof cache)) {
            $this->server_status_cache = self::make_cache();
        }

        return $this->server_status_cache;
    }

    /**
     * @return cache_application|cache_session|cache_store
     */
    protected static function make_cache()
    {
        return cache::make_from_params(cache_store::MODE_APPLICATION, __CLASS__, 'general');
    }

    /**
     * Returns database server info array.
     * @param mysqli mysqli object
     * @return array Array containing 'description' and 'version' info
     */
    public static function get_server_info_static(mysqli $mysqli)
    {
        return array('description' => $mysqli->server_info, 'version' => $mysqli->server_info);
    }

    /**
     * Connects to the database and return mysqli object.
     * @param string $dbhost The database host.
     * @param string $dbuser The database user to connect as.
     * @param string $dbpass The password to use when connecting to the database.
     * @param string $dbname The name of the database being connected to.
     * @param array $dboptions driver specific options
     * @return mysqli
     * @throws dml_connection_exception if error
     */
    private static function make_mysqli($dbhost, $dbuser, $dbpass, $dbname, array $dboptions = null)
    {
        // dbsocket is used ONLY if host is NULL or 'localhost',
        // you can not disable it because it is always tried if dbhost is 'localhost'
        if (!empty($dboptions['dbsocket'])
            and (strpos($dboptions['dbsocket'], '/') !== false or strpos($dboptions['dbsocket'], '\\') !== false)
        ) {
            $dbsocket = $dboptions['dbsocket'];
        } else {
            $dbsocket = ini_get('mysqli.default_socket');
        }

        if (empty($dboptions['dbport'])) {
            $dbport = (int)ini_get('mysqli.default_port');
        } else {
            $dbport = (int)$dboptions['dbport'];
        }

        // verify ini.get does not return nonsense
        if (empty($dbport)) {
            $dbport = 3306;
        }

        if ($dbhost and !empty($dboptions['dbpersist'])) {
            $dbhost = "p:$dbhost";
        }

        $mysqli = @new mysqli($dbhost, $dbuser, $dbpass, $dbname, $dbport, $dbsocket);

        if ($mysqli->connect_errno !== 0) {
            $dberr = $mysqli->connect_error;
            throw new dml_connection_exception($dberr);
        }

        $mysqli->set_charset('utf8');

        // If available, enforce strict mode for the session. That guaranties
        // standard behaviour under some situations, avoiding some MySQL nasty
        // habits like truncating data or performing some transparent cast losses.
        // With strict mode enforced, Moodle DB layer will be consistently throwing
        // the corresponding exceptions as expected.
        $si = self::get_server_info_static($mysqli);
        if (version_compare($si['version'], '5.0.2', '>=')) {
            $sql = "SET SESSION sql_mode = 'STRICT_ALL_TABLES'";
            $mysqli->query($sql);
        }

        return $mysqli;
    }

    /**
     * Detect backup (or restore) process.
     * @return bool
     */
    public function lock_on_master()
    {
        if (!$this->disable_slaves) {
            $backtrace = debug_backtrace();
            $backtrace = array_reverse($backtrace);
            $unique_class_names = array();

            foreach ($backtrace as $entry) {
                if (!empty($entry['class'])) {
                    $unique_class_names[$entry['class']] = true;
                }
            }

            foreach ($unique_class_names as $class_name => $flag) {
                foreach ($this->locked_on_master_classes as $locked_on_master_class) {
                    if (is_subclass_of($class_name, $locked_on_master_class)) {
                        $this->disable_slaves();
                        break 2;
                    }
                }
            }
        }
    }

    /**
     * Magic method to substitute [[mysqli]] (master/slave) based on [[queryType]]
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        if ($this->active && 'mysqli' === $name) {
            $query_type = $this->query_type;
            $this->query_type = null;

            $this->lock_on_master();

            if (($this->transaction || $this->disable_slaves) && !$this->disable_master) {
                return $this->get_master();
            } elseif ($this->disable_master && !$this->disable_slaves) {
                return $this->get_slave(false);
            }

            switch ($query_type) {
                case SQL_QUERY_SELECT:
                case SQL_QUERY_AUX:
                    return $this->get_slave();
                    break;
                case SQL_QUERY_INSERT:
                case SQL_QUERY_UPDATE:
                case SQL_QUERY_STRUCTURE:
                    return $this->get_master();
                    break;
                default:
                    return $this->get_master();
            }
        }

        trigger_error('Undefined property: ' . __CLASS__ . '::' . $name, E_USER_NOTICE);
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function query_start($sql, array $params = null, $type, $extrainfo = null)
    {
        parent::query_start($sql, $params, $this->query_type = $type, $extrainfo);
    }

    /**
     * {@inheritdoc}
     */
    public function query_end($result)
    {
        parent::query_end($result);
    }

    /**
     * Method to process raised moodle_exception while connecting to MySQL server.
     * @param moodle_exception $exception
     */
    private static function process_moodle_exception(moodle_exception $exception)
    {
        global $CFG;

        if (empty($CFG->noemailever) and !empty($CFG->emailconnectionerrorsto)) {
            $body = "Connection error: " . $CFG->wwwroot .
                "\n\nInfo:" .
                "\n\tError code: " . $exception->errorcode .
                "\n\tDebug info: " . $exception->debuginfo .
                "\n\tServer: " . $_SERVER['SERVER_NAME'] . " (" . $_SERVER['SERVER_ADDR'] . ")";
            if (file_exists($CFG->dataroot . '/emailcount')) {
                $fp = @fopen($CFG->dataroot . '/emailcount', 'r');
                $content = @fread($fp, 24);
                @fclose($fp);
                if ((time() - (int)$content) > 600) {
                    //email directly rather than using messaging
                    @mail($CFG->emailconnectionerrorsto,
                        'WARNING: Database connection error: ' . $CFG->wwwroot,
                        $body);
                    $fp = @fopen($CFG->dataroot . '/emailcount', 'w');
                    @fwrite($fp, time());
                }
            } else {
                //email directly rather than using messaging
                @mail($CFG->emailconnectionerrorsto,
                    'WARNING: Database connection error: ' . $CFG->wwwroot,
                    $body);
                $fp = @fopen($CFG->dataroot . '/emailcount', 'w');
                @fwrite($fp, time());
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function begin_transaction()
    {
        if ($this->transactions_supported()) {
            $this->transaction = true;
        }

        parent::begin_transaction();
    }

    /**
     * {@inheritdoc}
     */
    public function commit_transaction()
    {
        parent::commit_transaction();

        if ($this->transactions_supported()) {
            $this->transaction = false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rollback_transaction()
    {
        parent::rollback_transaction();

        if ($this->transactions_supported()) {
            $this->transaction = false;
        }
    }

    /**
     * Disable/enable read/write splitting by using slaves to read data.
     * This function will enable master (if it was disabled).
     *
     * @param bool $disable
     */
    public function disable_slaves($disable = true)
    {
        if ($disable) {
            $this->disable_master(false);
        }
        $this->disable_slaves = (bool)$disable;
    }

    /**
     * Disable/enable master (disable only if slaves are not disabled).
     *
     * @param bool $disable
     */
    public function disable_master($disable = true)
    {
        if (!$disable || !$this->disable_slaves) {
            $this->disable_master = (bool)$disable;
        }
    }

    /**
     * Returns the mysqli instance for the currently active master connection.
     * @return mysqli the mysqli instance for the currently active master connection.
     */
    public function get_master()
    {
        return $this->_master;
    }

    /**
     * Returns the mysqli instance for the currently active slave connection.
     * When [[disable_slaves]] is false, one of the slaves will be used for read queries, and its mysqli instance
     * will be returned by this method.
     * @param boolean $fallbackToMaster whether to return a master mysqli in case none of the slave connections is available.
     * @return mysqli the mysqli instance for the currently active slave connection. Null is returned if no slave connection
     * is available and `$fallbackToMaster` is false.
     */
    public function get_slave($fallbackToMaster = true)
    {
        if ($this->disable_slaves || empty($this->_slavesConfigs)) {
            return $fallbackToMaster ? $this->get_master() : null;
        }

        if (!$this->_slave || !$this->_slave->ping()) {
            $this->_slave = $this->open_from_pool($this->_slavesConfigs);
        }

        if ($this->_slave) {
            $this->reads_on_slave++;
            if ($this->reads > 0) {
                $this->reads--;
            }

            return $this->_slave;
        } else {
            return $fallbackToMaster ? $this->get_master() : null;
        }
    }

    /**
     * Opens the connection to a server in the pool.
     * This method implements the load balancing among the given list of the servers.
     * @param array $pool the list of connection configurations in the server pool
     * @return mysqli|null
     * @throws moodle_exception
     */
    protected function open_from_pool(array $pool)
    {
        shuffle($pool);
        $cache = $this->get_cache();

        foreach ($pool as $config) {
            $config = (object)$config;
            $key = crc32(serialize($config));

            if ($timestamp = $cache->get($key)) {
                if ($timestamp > time()) {
                    // should not try this dead server now
                    continue;
                } else {
                    $cache->delete($key);
                }
            }

            try {
                return self::make_mysqli($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, $config->dboptions);
            } catch (moodle_exception $e) {
                self::process_moodle_exception($e);
                // mark this server as dead and only retry it after the specified interval
                $cache->set($key, time() + $this->server_retry_interval);
            }
        }

        return null;
    }

    /**
     * Executes the provided callback by using the master connection.
     *
     * This method is provided so that you can temporarily force using the master connection to perform
     * DB operations even if they are read queries. For example,
     *
     * ```php
     * $result = $DB->use_master(function ($db) {
     *     return $db->get_records_sql('SELECT * FROM user LIMIT 1');
     * });
     * ```
     *
     * @param callable $callback
     * @return mixed
     */
    public function use_master(callable $callback)
    {
        $disableSlaves = $this->disable_slaves;
        $this->disable_slaves();
        $result = call_user_func($callback, $this);
        $this->disable_slaves($disableSlaves);

        return $result;
    }

    /**
     * Executes the provided callback by using the slave connection.
     *
     * This method is provided so that you can temporarily force using the slave connection to perform
     * DB operations even if they are write queries. For example,
     *
     * ```php
     * $result = $DB->use_slave(function ($db) {
     *     return $db->get_records_sql('SELECT * FROM user LIMIT 1');
     * });
     * ```
     *
     * @param callable $callback
     * @return mixed
     */
    public function use_slave(callable $callback)
    {
        $disableMaster = $this->disable_master;
        $this->disable_master();
        $result = call_user_func($callback, $this);
        $this->disable_master($disableMaster);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function dispose()
    {
        moodle_database::dispose();
        if ($this->_master) {
            $this->_master->close();
            $this->_master = null;
        }

        if ($this->_slave) {
            $this->_slave->close();
            $this->_slave = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function perf_get_reads($slaveOnly = false)
    {
        return $slaveOnly ? $this->reads_on_slave : $this->reads_on_slave + $this->reads;
    }

    /**
     * {@inheritdoc}
     */
    public function perf_get_queries()
    {
        return $this->writes + $this->perf_get_reads();
    }
}
