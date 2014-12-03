<?php

/********************************************************************************
 * Stor is a key/value store. Stor can store to file or to database. Stor allows
 *  you to set expiration for your stored items. Stor also allows you to easily
 *  cache and serve cached pages
 *
 * Basic usage:
 *
 * $stor = new Stor();
 * $stor->set('foo', array('baz', 'bat', 'bar'));
 * print_r($cache->get('foo')); // ~> array([0] => 'baz'...
 * $stor->delete('foo');
 * print_r($stor->get('foo')); // ~> NULL
 *
 * @author Dan Breczinski
 * @version 0.1.0
 * last updated: 11/14/2014
 */

class Stor
{
    protected $array_decode_json = TRUE;
    protected $cache_dir;
    protected $cache_method;
    protected $db;
    protected $encoding_type;
    protected $expiry = NULL;
    protected $has_constructor_error = FALSE;
    protected $memcache = NULL;
    protected $memcache_compression = TRUE;
    protected $permissions;
    protected $table_name;

    const ERROR_FILE_OPEN = 'Unable to open cache file. Permissions may need to be set.';
    const STOR_PREFIX = '_stor_';

/*******************************************************************************/
    /**
     * Class constructor
     *
     * @param $options {array} - optional parameters
     * @param $options['cache_dir'] {string} - directory to store cache. If none 
     *    given, data will be stored in the cache folder in the class directory.
     * @param $options['permsission'] {hex} - permission to give cache files and
     *    directory. If none give 0764 will be used.
     * @param $options['db'] {array} - if the cache is stored in a database, an
     *    array of database params should be passed this inclue: hostname,
     *    username, password, dbname, table_name - see dev/dump.sql for the
     *    statement to make the stor table
     * @param $options['memcache']{array} - set if using memcache. The key of 
     *    each array should be the memcache host and the value its port
     * @param $options['memcache_compression']{boolean} - default is `TRUE`
     *    if `FALSE` compression will not be used
     * @param $options['storage_type'] {string} - if set to 'db' cache will use
     *    DB otherwise, it will use the file system
     * @param $options['expiry'] {int} - this is used for page cacheing only. If
     *    set, cache will get refreshed after "expiry" number of seconds has
     *    passed.
     * @param $options['array_decode_json'] {boolean} - default: `TRUE` - when
     *      decode occurs from JSON, if value of `array_decode` is `TRUE`,
     *      the JSON will be decoded as an array, if `FALSE` the JSON will
     *      be decode as an ojbect
     *    
     * @return `Stor` object
     */
    public function __construct($options = NULL)
    {

        if( isset($options['storage_type']) && $options['storage_type'] === 'db' )
        {
            $this->cache_method = 'db';
            $this->db = new PDO(
                'mysql:host='. $options['db']['hostname'].
                ';dbname='. $options['db']['dbname'],
                $options['db']['username'],
                $options['db']['password']);
            $this->table_name = $options['db']['table_name'];

        } elseif( isset($options['memcache']) ) {
            $this->cache_method = 'memcache';
              if( !class_exists('Memcache') ){
                $this->has_constructor_error = TRUE;
                trigger_error('Unable to find Memcache.');
                return;
            }
            $this->memcache = new Memcache();
            foreach( $options['memcache'] as $host => $port)
            {
                if( !($this->memcache->addServer($host, $port)) )
                {
                    $this->has_constructor_error = TRUE;
                    trigger_error('Unable to connect to Memcache.');
                    return;
                }
            }
        }else{
            $this->cache_method = 'file';
            $this->cache_dir = isset($options['cache_dir']) ? $options['cache_dir'] : __DIR__ . '/cache';
            $this->permissions = isset($options['permissions']) ? $options['permissions'] : 0764;
        }

        if( isset($options['expiry']) )
        {
            $this->expiry = $options['expiry'];
        }

        if( isset($options['array_decode_json']) )
        {
            $this->array_decode_json = $options['array_decode_json'];
        }

        $this->encoding_type = 'json';
    }

    /**
     * @param string $key, the key associated with the cached item
     * @return returns the item associated with the key or `NULL` if key doesn't exist.
     * @note - this function does not check expiry (use `has_not_expired()` to test expiry)
     */
    public function get($key, $ignore_expiry = FALSE)
    {
        if( $this->has_constructor_error ){ return NULL; }

        // if using file or DB, check if cache has expired
        if( $this->expiry !== NULL &&
            !($ignore_expiry) &&
            ($this->cache_method === 'file' || $this->cache_method === 'db') )
        {
            if( $this->has_expired($key) ){ return NULL; }
        }

        if( $this->cache_method === 'file' )
        {
            $file_name = $this->cache_dir. '/'. $key;
            if( !file_exists($file_name) ){ return NULL; }
            return $this->decode_data(file_get_contents($file_name));

        } elseif( $this->cache_method === 'db' ){

            $sql = "SELECT * FROM `$this->table_name` where `key`=:key";
            $statement = $this->db->prepare($sql);
            $statement->execute(array('key' => $key));
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            return $this->decode_data($result['value']);

        } elseif( $this->cache_method === 'memcache' ){
            if( $this->memcache === NULL ){ return NULL; }
            $result = $this->memcache->get($key);
            if( $result === FALSE ){ return NULL; }
            return $result;
        }
    }

    /**
     * @param string $key, the key associated with the cached item
     * @param function $callback, the function to call if cache has expired. 
     *      This function should return an encodable value or `NULL`.
     *      If an encodable value is returned, the cache will get refreshed with it.
     *      If `NULL`, a user error will get generated and the expired cache will get returned.
     * @return the cache associated with the `$key`
     * @note - currently untested / in development
     */
    public function get_cache_or_refresh($key, $callback)
    {
        if( $this->has_constructor_error ){ return NULL; }

        // check if cache has expired
        if($this->has_expired($key)){

            $value = callback();

            if( $value !== NULL ){ $this->set($key, $value); }

            else
            {
                // update cache time and trigger user error
                $this->set($key, $this->get($key));
                trigger_error('Unable to refresh Cache.');
            }
        }
        return $stor->get($key);
    }

    /**
     * @param string $key, the key to associate with the cache
     * @param mixed $data, any valid PHP item which can be JSON encoded
     */
    public function set($key, $data, $try_to_set_expiry = TRUE)
    {
        if( $this->has_constructor_error ){ return NULL; }

        $encoded_data = $this->encode_data($data);

        if( $this->cache_method === 'file' )
        {
            $file = $this->cache_dir. '/'. $key;
            if( !is_dir($this->cache_dir) )
            {
                mkdir($this->cache_dir, $this->permissions, TRUE);
            }
            $f = fopen($file, 'w');
            if( !$f )
            { 
                throw new Exception(self::ERROR_FILE_OPEN, 1);
            }
            flock($f, LOCK_EX);
            if( !fwrite($f, $encoded_data) )
            {
                throw new Exception('Error writing to cache.', 1);
            };
            flock($f, LOCK_UN);
            fclose($f);
            chmod($file, $this->permissions);

        } elseif($this->cache_method === 'db'){

            $sql =  "INSERT INTO `$this->table_name`(`key`, `value`) VALUES (:key, :value)".
                    "ON DUPLICATE KEY UPDATE value = :value";
            $statement = $this->db->prepare($sql);
            // return $statement->execute(array('key' => $key, 'value' => $encoded_data));
            $statement->execute(array('key' => $key, 'value' => $encoded_data));
        } elseif($this->cache_method === 'memcache'){
            $this->memcache->set(
                $key,
                $data,
                $this->memcache_compression,
                $this->expiry);
        }

        if( $this->expiry !== NULL &&
            $try_to_set_expiry &&
            $this->cache_method !== 'memcache' ){

            $this->set(
                $this->get_time_key($key),
                time(),
                FALSE
            );

        }
    }


    public function decode_data($data)
    {
        if( $this->encoding_type === 'json' )
        {
            if( $this->array_decode_json )
            {
                return json_decode($data, TRUE);
            } else {
                return json_decode($data);
            }
        }
        throw new Exception("Invalid or no encoding.", 1);      
    }

    public function encode_data($data)
    {
        if( $this->encoding_type === 'json' )
        {
            return json_encode($data);
        }
        throw new Exception("Invalid or no encoding.", 1);
    }

    /**
     * @param string $key, the key to associate with the cache
     * Deletes key, throws an error if unable to delete
     */
    public function delete($key)
    {
        if( $this->has_constructor_error ){ return NULL; }

    //check for expiry

        if( $this->cache_method === 'file' )
        {
            $file = $this->cache_dir. '/'. $key;

            if( !file_exists($file) ){ return TRUE; }
            $f = fopen($file, 'w');
            if( !$f )
            { 
                throw new Exception(self::ERROR_FILE_OPEN, 1);
            }
            flock($f, LOCK_EX);
            if( !unlink($file) )
            {
                throw new Exception('Unable to delete cache file.', 1);

            }
            flock($f, LOCK_UN);
            fclose($f);
            return TRUE;

        } elseif( $this->cache_method === 'db' ){

            $sql = "DELETE FROM `$this->table_name` WHERE `key`=:key";
            $statement = $this->db->prepare($sql);
            return($statement->execute(array('key' => $key)) );
        }
    }

    /**
     * Clears the cache.
     */
    public function clear_cache()
    {
        if( $this->has_constructor_error ){ return NULL; }

        if( $this->cache_method === 'file' )
        {
            $dir = new DirectoryIterator($this->cache_dir);
            foreach ($dir as $fileinfo)
            {
                if( !$fileinfo->isDot() )
                {
                    if( !unlink($fileinfo->getPathname()) )
                    {
                        throw new Exception('Error clearing cache.', 1);                    
                    }
                }
            }
            return TRUE;

        } elseif( $this->cache_method === 'db' ){

            $sql = "DELETE FROM `$this->table_name`";
            $statement = $this->db->prepare($sql);
            return($statement->execute());
        } elseif( $this->cache_method === 'memcache' ){
            $this->memcache->flush();
        }
    }


    public function cache_page()
    {
        $key = $this->get_page_key();

        $page = $this->get($key);

        if( $page === NULL )
        {
            ob_flush();
            ob_start();
            register_shutdown_function('Stor::shutdown', $this);
        } else {
            echo $page;
        }
    }

    public static function shutdown($stor_object)
    {   
        $page_key = $stor_object->get_page_key();
        $page_time_key = $stor_object->get_time_key($page_key);

        $stor_object->set($page_key, ob_get_contents());
        $stor_object->set($page_time_key, time());

        ob_flush();
    }

    /**
    *   @param - $key - string
    *   @return - returns `TRUE` if expiry is being used and item's expiry
    *       has not been reached 
    */
    private function has_not_expired($key)
    {
        if( $this->expiry === NULL ){ return TRUE; }
        $time_key = $this->get_time_key($key);
        // if( $time_key === NULL ){ return FALSE; }
        $last_updated = $this->get($time_key, TRUE);
        if( $last_updated === NULL ){ return FALSE; }
        return( time() < $last_updated + $this->expiry );
    }

    private function has_expired($key){
        return !($this->has_not_expired($key));
    }

    protected function get_time_key($key)
    {
        return self::STOR_PREFIX. '_expiry_'. $key;
    }

    protected function get_page_key()
    {
        return self::STOR_PREFIX. '_page_'. str_replace('/', '-', $_SERVER['REQUEST_URI']);
    }
}