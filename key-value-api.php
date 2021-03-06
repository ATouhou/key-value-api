<?php
/**
 * Key-value cache class, non-permanent. Works with APC for now, optionally should be extended to memcache.
 * Can be accessed as static class methods or instantiated to an object and accessed as an array.
 * Static access gives more possibilities, of course.
 *
 * @package key-value-api
 * @uses pecl/memcached
 * @uses pecl/apc
 * @version 0.3.1
 * @author Martins Pilsetnieks
 */
	class kv implements ArrayAccess
	{
		const DEFAULT_MEMCACHE_PORT = 11211;

		private static $DefaultAPCOptions = array(
			'Enabled' => false
		);
		private static $DefaultMemcacheOptions = array(
			'Enabled' => true,
			'Servers' => array(
				array('localhost', 11211)
			),
			'Consistent' => true
		);

		private static $APCOptions = array();
		private static $MemcacheOptions = array();

		private static $APCOn = false;
		private static $MemcacheOn = false;

		private static $Memcache = null;

		/**
		 * Does nothing for now (with APC), maybe will do something with memcache
		 */
		public function __construct(array $APCOptions = null, array $MemcacheOptions = null)
		{
			if ($APCOptions && !empty($APCOptions['Enabled']) && (!ini_get('apc.enabled') || !function_exists('apc_store')))
			{
				throw new Exception('APC not available');
			}
			elseif ($APCOptions)
			{
				self::$APCOptions = array_merge(self::$DefaultAPCOptions, $APCOptions);
				self::$APCOn = !empty(self::$APCOptions['Enabled']);
			}

			if ($MemcacheOptions && !empty($MemcacheOptions['Enabled']) && !class_exists('Memcached'))
			{
				throw new Exception('Memcached not available (Memcached extension, not Memcache)');
			}
			elseif ($MemcacheOptions)
			{
				self::$MemcacheOptions = array_merge(self::$DefaultMemcacheOptions, $MemcacheOptions);
				self::$MemcacheOn = !empty(self::$MemcacheOptions['Enabled']);

				self::$Memcache = new Memcached;

				if (!empty(self::$MemcacheOptions['Consistent']))
				{
					self::$Memcache -> setOptions(array(
    						Memcached::OPT_CONNECT_TIMEOUT => 20,
						Memcached::OPT_DISTRIBUTION => Memcached::DISTRIBUTION_CONSISTENT,
						Memcached::OPT_SERVER_FAILURE_LIMIT => 5,
						Memcached::OPT_REMOVE_FAILED_SERVERS => true,
						Memcached::OPT_RETRY_TIMEOUT => 1,
						Memcached::OPT_LIBKETAMA_COMPATIBLE => true
					));
				}

				$MemcacheServers = self::ParseMemcacheServers(self::$MemcacheOptions['Servers']);
				self::$Memcache -> addServers($MemcacheServers);
			}
		}

		private static function ParseMemcacheServers($Servers)
		{
			if (is_string($Servers))
			{
				$Servers = explode(';', $Servers);
				$Servers = array_map('trim', $Servers);
			}

			if (is_array($Servers))
			{
				foreach ($Servers as $K => $Server)
				{
					if (is_string($Server))
					{
						$Servers[$K] = $Server = explode(':', $Server);
					}

					if (is_array($Servers[$K]) && empty($Server[1]))
					{
						$Servers[$K][1] = self::DEFAULT_MEMCACHE_PORT;
					}
				}

				return $Servers;
			}

			throw new Exception('Memcached server configuration could not be read');
		}

		/**
		 * Retrieves a value from the cache
		 *
		 * @param string Key
		 *
		 * @return mixed Value or boolean false, if unsuccessful
		 */
		public static function get($Key)
		{
			if (self::$APCOn)
			{
				return apc_fetch($Key);
			}
			elseif (self::$MemcacheOn)
			{
				return self::$Memcache -> get($Key);
			}
		}

		/**
		 * Stores a value in the cache
		 *
		 * @param string Key
		 * @param mixed Value
		 * @param int Time to live (seconds). 0 = unlimited
		 *
		 * @return boolean Operation status
		 */
		public static function set($Key, $Value, $TTL = 0)
		{
			$Status = true;
			if (self::$APCOn)
			{
				$Status = $Status && apc_store($Key, $Value, $TTL);
			}
			elseif (self::$MemcacheOn)
			{
				// If the TTL is longer than 30 days, memcache considers it to be a Unix timestamp instead of seconds to live
				if ($TTL > 2592000)
				{
					$TTL = time() + $TTL;
				}

				$Status = $Status && self::$Memcache -> set($Key, $Value, $TTL);
			}
			return $Status;
		}

		/**
		 * Retrieves a value if it exists and calls and gets a new one if it doesn't
		 *
		 * @param string Key
		 * @param callback Function or method to call for value. If you need to add any parameters to the function/method call,
		 *	just wrap it in an anonymous function
		 *
		 * @return mixed Value
		 */
		public static function wrap($Key, $Callback = null)
		{
			$Value = false;

			if (self::$APCOn)
			{
				$Value = self::get($Key);
				if ($Value === false && is_callable($Callback))
				{
					$Value = call_user_func($Callback);
					self::set($Key, $Value);
				}
			}
			elseif (self::$MemcacheOn)
			{
				$Value = self::$Memcache -> get($Key, $Callback);
			}

			return $Value;
		}

		/**
		 * Increments a numeric value
		 *
		 * @param string Key
		 * @param int Amount to increment by, defaults to 1
		 *
		 * @return boolean Operation status
		 */
		public static function inc($Key, $Value = 1)
		{
			$Value = (int)$Value;
			if ($Value <= 0)
			{
				return false;
			}

			if (self::$APCOn)
			{
				$Result = apc_inc($Key, (int)$Value);
			}
			elseif (self::$MemcacheOn)
			{
				$Result = self::$Memcache -> increment($Key, $Value);
			}

			return $Result;
		}

		/**
		 * Decrements a numeric value
		 *
		 * @param string Key
		 * @param int Amount to decrement by, defaults to 1
		 *
		 * @return boolean Operation status
		 */
		public static function dec($Key, $Value = 1)
		{
			$Value = (int)$Value;
			if ($Value <= 0)
			{
				return false;
			}

			if (self::$APCOn)
			{
				$Result = apc_dec($Key, (int)$Value);
			}
			elseif (self::$MemcacheOn)
			{
				$Result = self::$Memcache -> decrement($Key, $Value);
			}

			return $Result;
		}

		/**
		 * Clears a specific key
		 *
		 * @param string Key
		 *
		 * @return boolean Operation status
		 */
		public static function clear($Key)
		{
			if (self::$APCOn)
			{
				$Status = apc_delete($Key);
			}
			elseif (self::$MemcacheOn)
			{
				$Status = self::$Memcache -> delete($Key);
			}

			return $Status;
		}

		/**
		 * Clears everything
		 *
		 * @return boolean Operation status
		 */
		public static function clear_all()
		{
			$Status = false;

			if (self::$APCOn)
			{
				$Status = apc_clear_cache('user');
			}
			elseif (self::$MemcacheOn)
			{
				$Status = self::$Memcache -> flush();
			}

			return $Status;
		}

		// !ArrayAccess methods
		/**
		 * Checks if a value with the given key exists
		 *
		 * @param string Key
		 *
		 * @return boolean Exists or not
		 */
		public function offsetExists($Offset)
		{
			if (self::$APCOn)
			{
				return apc_exists($Offset);
			}
			elseif (self::$MemcacheOn)
			{
				$Val = self::$Memcache -> get($Offset);
				return !(self::$Memcache -> getResultCode() == Memcached::RES_NOTFOUND);
			}

			return false;
		}

		/**
		 * Retrieves a value, see kv::get
		 *
		 * @param string Key
		 *
		 * @param mixed Value
		 */
		public function offsetGet($Offset)
		{
			return self::get($Offset);
		}

		/**
		 * Sets a value, see kv::set
		 *
		 * @param string Key
		 * @param mixed Value
		 *
		 * @param boolean Operation status
		 */
		public function offsetSet($Offset, $Value)
		{
			return self::set($Offset, $Value);
		}

		/**
		 * Clears a value, see kv::clear
		 *
		 * @param string Key
		 *
		 * @return boolean Operation status
		 */
		public function offsetUnset($Offset)
		{
			return self::clear($Offset);
		}
	}
?>
