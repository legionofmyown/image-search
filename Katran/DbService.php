<?php
namespace Katran;

class DbService
{
    private $_db;
    private $_cacheDir;

    private $_stmtCacheSelect;
    private $_stmtCacheInsert;
    private $_stmtCacheUpdate;

    public function __construct()
    {
        $this->_cacheDir = dirname(__FILE__) . '/../cache';

        //create & set right to cache and tmp directories if required
        if (!file_exists($this->_cacheDir)) {
            mkdir($this->_cacheDir, 0666);
        }

        $rights = substr(sprintf('%o', fileperms($this->_cacheDir)), -4);
        if ($rights !== '0666') {
            chmod($this->_cacheDir, 0666);
        }

        //open or create db
        $this->_db = new \SQLite3($this->_cacheDir . '/cache.db');
        if (!$this->_db->exec('CREATE TABLE IF NOT EXISTS cache (keyname VARCHAR(255) PRIMARY KEY, val TEXT)')) {
            throw new DbException('Unable to access cache table');
        }

        //prepare statements
        $this->_stmtCacheUpdate = $this->_db->prepare("UPDATE cache SET val=:val WHERE keyname=:key;");
        $this->_stmtCacheInsert = $this->_db->prepare("INSERT OR IGNORE INTO cache (keyname, val) VALUES (:key, :val);");
        $this->_stmtCacheSelect = $this->_db->prepare("SELECT val FROM cache WHERE keyname=:key;");
    }

    public function set($key, $value)
    {
        $this->_stmtCacheUpdate->bindValue(':key', $key, SQLITE3_TEXT);
        $this->_stmtCacheUpdate->bindValue(':val', $value, SQLITE3_TEXT);
        $this->_stmtCacheInsert->bindValue(':key', $key, SQLITE3_TEXT);
        $this->_stmtCacheInsert->bindValue(':val', $value, SQLITE3_TEXT);

        if ($this->_stmtCacheUpdate->execute() === false || $this->_db->lastErrorCode() !== 0) {
            throw new DbException('Error while updating cache');
        }

        if ($this->_stmtCacheInsert->execute() === false || $this->_db->lastErrorCode() !== 0) {
            throw new DbException('Error while inserting in cache');
        }

        $this->_stmtCacheUpdate->reset();
        $this->_stmtCacheInsert->reset();
    }

    public function get($key)
    {
        $this->_stmtCacheSelect->bindValue(':key', $key, SQLITE3_TEXT);

        $res = $this->_stmtCacheSelect->execute();
        if ($res === false || $this->_db->lastErrorCode() !== 0) {
            throw new DbException('Error while selecting from cache');
        }
        $return = null;
        $arr = $res->fetchArray(SQLITE3_ASSOC);
        if ($arr !== false) {
            $return = $arr['val'];
        }

        $this->_stmtCacheSelect->reset();

        return $return;
    }
}