<?php
/**
 * Storage medium specific cache for storing items.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Cache
 */

use Wikimedia\WaitConditionLoop;

/**
 * Storage medium specific cache for storing items (e.g. redis, memcached, ...)
 *
 * This should not be used for proxy classes that simply wrap other cache instances
 *
 * @ingroup Cache
 * @since 1.34
 */
abstract class MediumSpecificBagOStuff extends BagOStuff {
	/** @var array[] Lock tracking */
	protected $locks = [];
	/** @var int ERR_* class constant */
	protected $lastError = self::ERR_NONE;
	/** @var string */
	protected $keyspace = 'local';
	/** @var int Seconds */
	protected $syncTimeout;
	/** @var int Bytes; chunk size of segmented cache values */
	protected $segmentationSize;
	/** @var int Bytes; maximum total size of a segmented cache value */
	protected $segmentedValueMaxSize;

	/** @var array */
	private $duplicateKeyLookups = [];
	/** @var bool */
	private $reportDupes = false;
	/** @var bool */
	private $dupeTrackScheduled = false;

	/** @var callable[] */
	protected $busyCallbacks = [];

	/** @var string Component to use for key construction of blob segment keys */
	const SEGMENT_COMPONENT = 'segment';

	/**
	 * @see BagOStuff::__construct()
	 * Additional $params options include:
	 *   - logger: Psr\Log\LoggerInterface instance
	 *   - keyspace: Default keyspace for $this->makeKey()
	 *   - reportDupes: Whether to emit warning log messages for all keys that were
	 *      requested more than once (requires an asyncHandler).
	 *   - syncTimeout: How long to wait with WRITE_SYNC in seconds.
	 *   - segmentationSize: The chunk size, in bytes, of segmented values. The value should
	 *      not exceed the maximum size of values in the storage backend, as configured by
	 *      the site administrator.
	 *   - segmentedValueMaxSize: The maximum total size, in bytes, of segmented values.
	 *      This should be configured to a reasonable size give the site traffic and the
	 *      amount of I/O between application and cache servers that the network can handle.
	 * @param array $params
	 */
	public function __construct( array $params = [] ) {
		parent::__construct( $params );

		if ( isset( $params['keyspace'] ) ) {
			$this->keyspace = $params['keyspace'];
		}

		if ( !empty( $params['reportDupes'] ) && is_callable( $this->asyncHandler ) ) {
			$this->reportDupes = true;
		}

		$this->syncTimeout = $params['syncTimeout'] ?? 3;
		$this->segmentationSize = $params['segmentationSize'] ?? 8388608; // 8MiB
		$this->segmentedValueMaxSize = $params['segmentedValueMaxSize'] ?? 67108864; // 64MiB
	}

	/**
	 * Get an item with the given key
	 *
	 * If the key includes a deterministic input hash (e.g. the key can only have
	 * the correct value) or complete staleness checks are handled by the caller
	 * (e.g. nothing relies on the TTL), then the READ_VERIFIED flag should be set.
	 * This lets tiered backends know they can safely upgrade a cached value to
	 * higher tiers using standard TTLs.
	 *
	 * @param string $key
	 * @param int $flags Bitfield of BagOStuff::READ_* constants [optional]
	 * @return mixed Returns false on failure or if the item does not exist
	 */
	public function get( $key, $flags = 0 ) {
		$this->trackDuplicateKeys( $key );

		return $this->resolveSegments( $key, $this->doGet( $key, $flags ) );
	}

	/**
	 * Track the number of times that a given key has been used.
	 * @param string $key
	 */
	private function trackDuplicateKeys( $key ) {
		if ( !$this->reportDupes ) {
			return;
		}

		if ( !isset( $this->duplicateKeyLookups[$key] ) ) {
			// Track that we have seen this key. This N-1 counting style allows
			// easy filtering with array_filter() later.
			$this->duplicateKeyLookups[$key] = 0;
		} else {
			$this->duplicateKeyLookups[$key] += 1;

			if ( $this->dupeTrackScheduled === false ) {
				$this->dupeTrackScheduled = true;
				// Schedule a callback that logs keys processed more than once by get().
				call_user_func( $this->asyncHandler, function () {
					$dups = array_filter( $this->duplicateKeyLookups );
					foreach ( $dups as $key => $count ) {
						$this->logger->warning(
							'Duplicate get(): "{key}" fetched {count} times',
							// Count is N-1 of the actual lookup count
							[ 'key' => $key, 'count' => $count + 1, ]
						);
					}
				} );
			}
		}
	}

	/**
	 * @param string $key
	 * @param int $flags Bitfield of BagOStuff::READ_* constants [optional]
	 * @param mixed|null &$casToken Token to use for check-and-set comparisons
	 * @return mixed Returns false on failure or if the item does not exist
	 */
	abstract protected function doGet( $key, $flags = 0, &$casToken = null );

	/**
	 * Set an item
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 */
	public function set( $key, $value, $exptime = 0, $flags = 0 ) {
		if (
			is_int( $value ) || // avoid breaking incr()/decr()
			( $flags & self::WRITE_ALLOW_SEGMENTS ) != self::WRITE_ALLOW_SEGMENTS ||
			is_infinite( $this->segmentationSize )
		) {
			return $this->doSet( $key, $value, $exptime, $flags );
		}

		$serialized = $this->serialize( $value );
		$segmentSize = $this->getSegmentationSize();
		$maxTotalSize = $this->getSegmentedValueMaxSize();

		$size = strlen( $serialized );
		if ( $size <= $segmentSize ) {
			// Since the work of serializing it was already done, just use it inline
			return $this->doSet(
				$key,
				SerializedValueContainer::newUnified( $serialized ),
				$exptime,
				$flags
			);
		} elseif ( $size > $maxTotalSize ) {
			$this->setLastError( "Key $key exceeded $maxTotalSize bytes." );

			return false;
		}

		$chunksByKey = [];
		$segmentHashes = [];
		$count = intdiv( $size, $segmentSize ) + ( ( $size % $segmentSize ) ? 1 : 0 );
		for ( $i = 0; $i < $count; ++$i ) {
			$segment = substr( $serialized, $i * $segmentSize, $segmentSize );
			$hash = sha1( $segment );
			$chunkKey = $this->makeGlobalKey( self::SEGMENT_COMPONENT, $key, $hash );
			$chunksByKey[$chunkKey] = $segment;
			$segmentHashes[] = $hash;
		}

		$flags &= ~self::WRITE_ALLOW_SEGMENTS; // sanity
		$ok = $this->setMulti( $chunksByKey, $exptime, $flags );
		if ( $ok ) {
			// Only when all segments are stored should the main key be changed
			$ok = $this->doSet(
				$key,
				SerializedValueContainer::newSegmented( $segmentHashes ),
				$exptime,
				$flags
			);
		}

		return $ok;
	}

	/**
	 * Set an item
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 */
	abstract protected function doSet( $key, $value, $exptime = 0, $flags = 0 );

	/**
	 * Delete an item
	 *
	 * For large values written using WRITE_ALLOW_SEGMENTS, this only deletes the main
	 * segment list key unless WRITE_PRUNE_SEGMENTS is in the flags. While deleting the segment
	 * list key has the effect of functionally deleting the key, it leaves unused blobs in cache.
	 *
	 * @param string $key
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool True if the item was deleted or not found, false on failure
	 */
	public function delete( $key, $flags = 0 ) {
		if ( ( $flags & self::WRITE_PRUNE_SEGMENTS ) != self::WRITE_PRUNE_SEGMENTS ) {
			return $this->doDelete( $key, $flags );
		}

		$mainValue = $this->doGet( $key, self::READ_LATEST );
		if ( !$this->doDelete( $key, $flags ) ) {
			return false;
		}

		if ( !SerializedValueContainer::isSegmented( $mainValue ) ) {
			return true; // no segments to delete
		}

		$orderedKeys = array_map(
			function ( $segmentHash ) use ( $key ) {
				return $this->makeGlobalKey( self::SEGMENT_COMPONENT, $key, $segmentHash );
			},
			$mainValue->{SerializedValueContainer::SEGMENTED_HASHES}
		);

		return $this->deleteMulti( $orderedKeys, $flags );
	}

	/**
	 * Delete an item
	 *
	 * @param string $key
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool True if the item was deleted or not found, false on failure
	 */
	abstract protected function doDelete( $key, $flags = 0 );

	/**
	 * Merge changes into the existing cache value (possibly creating a new one)
	 *
	 * The callback function returns the new value given the current value
	 * (which will be false if not present), and takes the arguments:
	 * (this BagOStuff, cache key, current value, TTL).
	 * The TTL parameter is reference set to $exptime. It can be overriden in the callback.
	 * Nothing is stored nor deleted if the callback returns false.
	 *
	 * @param string $key
	 * @param callable $callback Callback method to be executed
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $attempts The amount of times to attempt a merge in case of failure
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 * @throws InvalidArgumentException
	 */
	public function merge( $key, callable $callback, $exptime = 0, $attempts = 10, $flags = 0 ) {
		return $this->mergeViaCas( $key, $callback, $exptime, $attempts, $flags );
	}

	/**
	 * @param string $key
	 * @param callable $callback Callback method to be executed
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $attempts The amount of times to attempt a merge in case of failure
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 * @see BagOStuff::merge()
	 *
	 */
	final protected function mergeViaCas( $key, callable $callback, $exptime, $attempts, $flags ) {
		do {
			$casToken = null; // passed by reference
			// Get the old value and CAS token from cache
			$this->clearLastError();
			$currentValue = $this->resolveSegments(
				$key,
				$this->doGet( $key, self::READ_LATEST, $casToken )
			);
			if ( $this->getLastError() ) {
				$this->logger->warning(
					__METHOD__ . ' failed due to I/O error on get() for {key}.',
					[ 'key' => $key ]
				);

				return false; // don't spam retries (retry only on races)
			}

			// Derive the new value from the old value
			$value = call_user_func( $callback, $this, $key, $currentValue, $exptime );
			$hadNoCurrentValue = ( $currentValue === false );
			unset( $currentValue ); // free RAM in case the value is large

			$this->clearLastError();
			if ( $value === false ) {
				$success = true; // do nothing
			} elseif ( $hadNoCurrentValue ) {
				// Try to create the key, failing if it gets created in the meantime
				$success = $this->add( $key, $value, $exptime, $flags );
			} else {
				// Try to update the key, failing if it gets changed in the meantime
				$success = $this->cas( $casToken, $key, $value, $exptime, $flags );
			}
			if ( $this->getLastError() ) {
				$this->logger->warning(
					__METHOD__ . ' failed due to I/O error for {key}.',
					[ 'key' => $key ]
				);

				return false; // IO error; don't spam retries
			}

		} while ( !$success && --$attempts );

		return $success;
	}

	/**
	 * Check and set an item
	 *
	 * @param mixed $casToken
	 * @param string $key
	 * @param mixed $value
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 */
	protected function cas( $casToken, $key, $value, $exptime = 0, $flags = 0 ) {
		if ( !$this->lock( $key, 0 ) ) {
			return false; // non-blocking
		}

		$curCasToken = null; // passed by reference
		$this->doGet( $key, self::READ_LATEST, $curCasToken );
		if ( $casToken === $curCasToken ) {
			$success = $this->set( $key, $value, $exptime, $flags );
		} else {
			$this->logger->info(
				__METHOD__ . ' failed due to race condition for {key}.',
				[ 'key' => $key ]
			);

			$success = false; // mismatched or failed
		}

		$this->unlock( $key );

		return $success;
	}

	/**
	 * Change the expiration on a key if it exists
	 *
	 * If an expiry in the past is given then the key will immediately be expired
	 *
	 * For large values written using WRITE_ALLOW_SEGMENTS, this only changes the TTL of the
	 * main segment list key. While lowering the TTL of the segment list key has the effect of
	 * functionally lowering the TTL of the key, it might leave unused blobs in cache for longer.
	 * Raising the TTL of such keys is not effective, since the expiration of a single segment
	 * key effectively expires the entire value.
	 *
	 * @param string $key
	 * @param int $exptime TTL or UNIX timestamp
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants (since 1.33)
	 * @return bool Success Returns false on failure or if the item does not exist
	 * @since 1.28
	 */
	public function changeTTL( $key, $exptime = 0, $flags = 0 ) {
		return $this->doChangeTTL( $key, $exptime, $flags );
	}

	/**
	 * @param string $key
	 * @param int $exptime
	 * @param int $flags
	 * @return bool
	 */
	protected function doChangeTTL( $key, $exptime, $flags ) {
		$expiry = $this->convertToExpiry( $exptime );
		$delete = ( $expiry != 0 && $expiry < $this->getCurrentTime() );

		if ( !$this->lock( $key, 0 ) ) {
			return false;
		}
		// Use doGet() to avoid having to trigger resolveSegments()
		$blob = $this->doGet( $key, self::READ_LATEST );
		if ( $blob ) {
			if ( $delete ) {
				$ok = $this->doDelete( $key, $flags );
			} else {
				$ok = $this->doSet( $key, $blob, $exptime, $flags );
			}
		} else {
			$ok = false;
		}

		$this->unlock( $key );

		return $ok;
	}

	/**
	 * Acquire an advisory lock on a key string
	 *
	 * Note that if reentry is enabled, duplicate calls ignore $expiry
	 *
	 * @param string $key
	 * @param int $timeout Lock wait timeout; 0 for non-blocking [optional]
	 * @param int $expiry Lock expiry [optional]; 1 day maximum
	 * @param string $rclass Allow reentry if set and the current lock used this value
	 * @return bool Success
	 */
	public function lock( $key, $timeout = 6, $expiry = 6, $rclass = '' ) {
		// Avoid deadlocks and allow lock reentry if specified
		if ( isset( $this->locks[$key] ) ) {
			if ( $rclass != '' && $this->locks[$key]['class'] === $rclass ) {
				++$this->locks[$key]['depth'];
				return true;
			} else {
				return false;
			}
		}

		$fname = __METHOD__;
		$expiry = min( $expiry ?: INF, self::TTL_DAY );
		$loop = new WaitConditionLoop(
			function () use ( $key, $expiry, $fname ) {
				$this->clearLastError();
				if ( $this->add( "{$key}:lock", 1, $expiry ) ) {
					return WaitConditionLoop::CONDITION_REACHED; // locked!
				} elseif ( $this->getLastError() ) {
					$this->logger->warning(
						$fname . ' failed due to I/O error for {key}.',
						[ 'key' => $key ]
					);

					return WaitConditionLoop::CONDITION_ABORTED; // network partition?
				}

				return WaitConditionLoop::CONDITION_CONTINUE;
			},
			$timeout
		);

		$code = $loop->invoke();
		$locked = ( $code === $loop::CONDITION_REACHED );
		if ( $locked ) {
			$this->locks[$key] = [ 'class' => $rclass, 'depth' => 1 ];
		} elseif ( $code === $loop::CONDITION_TIMED_OUT ) {
			$this->logger->warning(
				"$fname failed due to timeout for {key}.",
				[ 'key' => $key, 'timeout' => $timeout ]
			);
		}

		return $locked;
	}

	/**
	 * Release an advisory lock on a key string
	 *
	 * @param string $key
	 * @return bool Success
	 */
	public function unlock( $key ) {
		if ( !isset( $this->locks[$key] ) ) {
			return false;
		}

		if ( --$this->locks[$key]['depth'] <= 0 ) {
			unset( $this->locks[$key] );

			$ok = $this->doDelete( "{$key}:lock" );
			if ( !$ok ) {
				$this->logger->warning(
					__METHOD__ . ' failed to release lock for {key}.',
					[ 'key' => $key ]
				);
			}

			return $ok;
		}

		return true;
	}

	/**
	 * Delete all objects expiring before a certain date.
	 * @param string|int $timestamp The reference date in MW or TS_UNIX format
	 * @param callable|null $progress Optional, a function which will be called
	 *     regularly during long-running operations with the percentage progress
	 *     as the first parameter. [optional]
	 * @param int $limit Maximum number of keys to delete [default: INF]
	 *
	 * @return bool Success; false if unimplemented
	 */
	public function deleteObjectsExpiringBefore(
		$timestamp,
		callable $progress = null,
		$limit = INF
	) {
		return false;
	}

	/**
	 * Get an associative array containing the item for each of the keys that have items.
	 * @param string[] $keys List of keys; can be a map of (unused => key) for convenience
	 * @param int $flags Bitfield; supports READ_LATEST [optional]
	 * @return mixed[] Map of (key => value) for existing keys; preserves the order of $keys
	 */
	public function getMulti( array $keys, $flags = 0 ) {
		$foundByKey = $this->doGetMulti( $keys, $flags );

		$res = [];
		foreach ( $keys as $key ) {
			// Resolve one blob at a time (avoids too much I/O at once)
			if ( array_key_exists( $key, $foundByKey ) ) {
				// A value should not appear in the key if a segment is missing
				$value = $this->resolveSegments( $key, $foundByKey[$key] );
				if ( $value !== false ) {
					$res[$key] = $value;
				}
			}
		}

		return $res;
	}

	/**
	 * Get an associative array containing the item for each of the keys that have items.
	 * @param string[] $keys List of keys
	 * @param int $flags Bitfield; supports READ_LATEST [optional]
	 * @return array Map of (key => value) for existing keys
	 */
	protected function doGetMulti( array $keys, $flags = 0 ) {
		$res = [];
		foreach ( $keys as $key ) {
			$val = $this->doGet( $key, $flags );
			if ( $val !== false ) {
				$res[$key] = $val;
			}
		}

		return $res;
	}

	/**
	 * Batch insertion/replace
	 *
	 * This does not support WRITE_ALLOW_SEGMENTS to avoid excessive read I/O
	 *
	 * @param mixed[] $data Map of (key => value)
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants (since 1.33)
	 * @return bool Success
	 * @since 1.24
	 */
	public function setMulti( array $data, $exptime = 0, $flags = 0 ) {
		if ( ( $flags & self::WRITE_ALLOW_SEGMENTS ) === self::WRITE_ALLOW_SEGMENTS ) {
			throw new InvalidArgumentException( __METHOD__ . ' got WRITE_ALLOW_SEGMENTS' );
		}
		return $this->doSetMulti( $data, $exptime, $flags );
	}

	/**
	 * @param mixed[] $data Map of (key => value)
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 */
	protected function doSetMulti( array $data, $exptime = 0, $flags = 0 ) {
		$res = true;
		foreach ( $data as $key => $value ) {
			$res = $this->doSet( $key, $value, $exptime, $flags ) && $res;
		}
		return $res;
	}

	/**
	 * Batch deletion
	 *
	 * This does not support WRITE_ALLOW_SEGMENTS to avoid excessive read I/O
	 *
	 * @param string[] $keys List of keys
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 * @since 1.33
	 */
	public function deleteMulti( array $keys, $flags = 0 ) {
		if ( ( $flags & self::WRITE_ALLOW_SEGMENTS ) === self::WRITE_ALLOW_SEGMENTS ) {
			throw new InvalidArgumentException( __METHOD__ . ' got WRITE_ALLOW_SEGMENTS' );
		}
		return $this->doDeleteMulti( $keys, $flags );
	}

	/**
	 * @param string[] $keys List of keys
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 */
	protected function doDeleteMulti( array $keys, $flags = 0 ) {
		$res = true;
		foreach ( $keys as $key ) {
			$res = $this->doDelete( $key, $flags ) && $res;
		}
		return $res;
	}

	/**
	 * Change the expiration of multiple keys that exist
	 *
	 * @param string[] $keys List of keys
	 * @param int $exptime TTL or UNIX timestamp
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants (since 1.33)
	 * @return bool Success
	 * @see BagOStuff::changeTTL()
	 *
	 * @since 1.34
	 */
	public function changeTTLMulti( array $keys, $exptime, $flags = 0 ) {
		$res = true;
		foreach ( $keys as $key ) {
			$res = $this->doChangeTTL( $key, $exptime, $flags ) && $res;
		}

		return $res;
	}

	/**
	 * Decrease stored value of $key by $value while preserving its TTL
	 * @param string $key
	 * @param int $value Value to subtract from $key (default: 1) [optional]
	 * @return int|bool New value or false on failure
	 */
	public function decr( $key, $value = 1 ) {
		return $this->incr( $key, -$value );
	}

	/**
	 * Increase stored value of $key by $value while preserving its TTL
	 *
	 * This will create the key with value $init and TTL $ttl instead if not present
	 *
	 * @param string $key
	 * @param int $ttl
	 * @param int $value
	 * @param int $init
	 * @return int|bool New value or false on failure
	 * @since 1.24
	 */
	public function incrWithInit( $key, $ttl, $value = 1, $init = 1 ) {
		$this->clearLastError();
		$newValue = $this->incr( $key, $value );
		if ( $newValue === false && !$this->getLastError() ) {
			// No key set; initialize
			$newValue = $this->add( $key, (int)$init, $ttl ) ? $init : false;
			if ( $newValue === false && !$this->getLastError() ) {
				// Raced out initializing; increment
				$newValue = $this->incr( $key, $value );
			}
		}

		return $newValue;
	}

	/**
	 * Get and reassemble the chunks of blob at the given key
	 *
	 * @param string $key
	 * @param mixed $mainValue
	 * @return string|null|bool The combined string, false if missing, null on error
	 */
	final protected function resolveSegments( $key, $mainValue ) {
		if ( SerializedValueContainer::isUnified( $mainValue ) ) {
			return $this->unserialize( $mainValue->{SerializedValueContainer::UNIFIED_DATA} );
		}

		if ( SerializedValueContainer::isSegmented( $mainValue ) ) {
			$orderedKeys = array_map(
				function ( $segmentHash ) use ( $key ) {
					return $this->makeGlobalKey( self::SEGMENT_COMPONENT, $key, $segmentHash );
				},
				$mainValue->{SerializedValueContainer::SEGMENTED_HASHES}
			);

			$segmentsByKey = $this->doGetMulti( $orderedKeys );

			$parts = [];
			foreach ( $orderedKeys as $segmentKey ) {
				if ( isset( $segmentsByKey[$segmentKey] ) ) {
					$parts[] = $segmentsByKey[$segmentKey];
				} else {
					return false; // missing segment
				}
			}

			return $this->unserialize( implode( '', $parts ) );
		}

		return $mainValue;
	}

	/**
	 * Get the "last error" registered; clearLastError() should be called manually
	 * @return int ERR_* constant for the "last error" registry
	 * @since 1.23
	 */
	public function getLastError() {
		return $this->lastError;
	}

	/**
	 * Clear the "last error" registry
	 * @since 1.23
	 */
	public function clearLastError() {
		$this->lastError = self::ERR_NONE;
	}

	/**
	 * Set the "last error" registry
	 * @param int $err ERR_* constant
	 * @since 1.23
	 */
	protected function setLastError( $err ) {
		$this->lastError = $err;
	}

	/**
	 * Let a callback be run to avoid wasting time on special blocking calls
	 *
	 * The callbacks may or may not be called ever, in any particular order.
	 * They are likely to be invoked when something WRITE_SYNC is used used.
	 * They should follow a caching pattern as shown below, so that any code
	 * using the work will get it's result no matter what happens.
	 * @code
	 *     $result = null;
	 *     $workCallback = function () use ( &$result ) {
	 *         if ( !$result ) {
	 *             $result = ....
	 *         }
	 *         return $result;
	 *     }
	 * @endcode
	 *
	 * @param callable $workCallback
	 * @since 1.28
	 */
	final public function addBusyCallback( callable $workCallback ) {
		$this->busyCallbacks[] = $workCallback;
	}

	/**
	 * @param int $exptime
	 * @return bool
	 */
	final protected function expiryIsRelative( $exptime ) {
		return ( $exptime != 0 && $exptime < ( 10 * self::TTL_YEAR ) );
	}

	/**
	 * Convert an optionally relative timestamp to an absolute time
	 *
	 * The input value will be cast to an integer and interpreted as follows:
	 *   - zero: no expiry; return zero (e.g. TTL_INDEFINITE)
	 *   - negative: relative TTL; return UNIX timestamp offset by this value
	 *   - positive (< 10 years): relative TTL; return UNIX timestamp offset by this value
	 *   - positive (>= 10 years): absolute UNIX timestamp; return this value
	 *
	 * @param int $exptime
	 * @return int Absolute TTL or 0 for indefinite
	 */
	final protected function convertToExpiry( $exptime ) {
		return $this->expiryIsRelative( $exptime )
			? (int)$this->getCurrentTime() + $exptime
			: $exptime;
	}

	/**
	 * Convert an optionally absolute expiry time to a relative time. If an
	 * absolute time is specified which is in the past, use a short expiry time.
	 *
	 * The input value will be cast to an integer and interpreted as follows:
	 *   - zero: no expiry; return zero (e.g. TTL_INDEFINITE)
	 *   - negative: relative TTL; return a short expiry time (1 second)
	 *   - positive (< 10 years): relative TTL; return this value
	 *   - positive (>= 10 years): absolute UNIX timestamp; return offset to current time
	 *
	 * @param int $exptime
	 * @return int Relative TTL or 0 for indefinite
	 */
	final protected function convertToRelative( $exptime ) {
		return $this->expiryIsRelative( $exptime ) || !$exptime
			? (int)$exptime
			: max( $exptime - (int)$this->getCurrentTime(), 1 );
	}

	/**
	 * Check if a value is an integer
	 *
	 * @param mixed $value
	 * @return bool
	 */
	final protected function isInteger( $value ) {
		if ( is_int( $value ) ) {
			return true;
		} elseif ( !is_string( $value ) ) {
			return false;
		}

		$integer = (int)$value;

		return ( $value === (string)$integer );
	}

	/**
	 * Construct a cache key.
	 *
	 * @param string $keyspace
	 * @param array $args
	 * @return string Colon-delimited list of $keyspace followed by escaped components of $args
	 * @since 1.27
	 */
	public function makeKeyInternal( $keyspace, $args ) {
		$key = $keyspace;
		foreach ( $args as $arg ) {
			$key .= ':' . str_replace( ':', '%3A', $arg );
		}
		return strtr( $key, ' ', '_' );
	}

	/**
	 * Make a global cache key.
	 *
	 * @param string $class Key class
	 * @param string ...$components Key components (starting with a key collection name)
	 * @return string Colon-delimited list of $keyspace followed by escaped components
	 * @since 1.27
	 */
	public function makeGlobalKey( $class, ...$components ) {
		return $this->makeKeyInternal( 'global', func_get_args() );
	}

	/**
	 * Make a cache key, scoped to this instance's keyspace.
	 *
	 * @param string $class Key class
	 * @param string ...$components Key components (starting with a key collection name)
	 * @return string Colon-delimited list of $keyspace followed by escaped components
	 * @since 1.27
	 */
	public function makeKey( $class, ...$components ) {
		return $this->makeKeyInternal( $this->keyspace, func_get_args() );
	}

	/**
	 * @param int $flag ATTR_* class constant
	 * @return int QOS_* class constant
	 * @since 1.28
	 */
	public function getQoS( $flag ) {
		return $this->attrMap[$flag] ?? self::QOS_UNKNOWN;
	}

	/**
	 * @return int|float The chunk size, in bytes, of segmented objects (INF for no limit)
	 * @since 1.34
	 */
	public function getSegmentationSize() {
		return $this->segmentationSize;
	}

	/**
	 * @return int|float Maximum total segmented object size in bytes (INF for no limit)
	 * @since 1.34
	 */
	public function getSegmentedValueMaxSize() {
		return $this->segmentedValueMaxSize;
	}

	/**
	 * @param mixed $value
	 * @return string|int String/integer representation
	 * @note Special handling is usually needed for integers so incr()/decr() work
	 */
	protected function serialize( $value ) {
		return is_int( $value ) ? $value : serialize( $value );
	}

	/**
	 * @param string|int $value
	 * @return mixed Original value or false on error
	 * @note Special handling is usually needed for integers so incr()/decr() work
	 */
	protected function unserialize( $value ) {
		return $this->isInteger( $value ) ? (int)$value : unserialize( $value );
	}

	/**
	 * @param string $text
	 */
	protected function debug( $text ) {
		if ( $this->debugMode ) {
			$this->logger->debug( "{class} debug: $text", [ 'class' => static::class ] );
		}
	}
}
