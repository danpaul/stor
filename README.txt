<?php

/**
*   @about: Stor is a key/value store. Stor can store to file, database or Memcached.
*       Stor also allows you to easily cache and serve entire pages.
*       See class constructor for various options and documentation.
*/

/***************************************************************************************
EXAMPLES
***************************************************************************************/

require_once(dirname(__FILE__). '/stor.php');

/***************************************************************************************
CREATE
***************************************************************************************/

$stor = new Stor();

$stor->set('foo', array('baz', 'bat', 'bar'));


/***************************************************************************************
READ
***************************************************************************************/

var_dump($stor->get('foo')); // ~> array([0] => 'baz'...


/***************************************************************************************
CLEAR
***************************************************************************************/

$stor->clear_cache();
var_dump($stor->get('foo')); // ~> NULL


/***************************************************************************************
DELETE
***************************************************************************************/

$stor->set('foo', 'bar');
$stor->delete('foo');
$stor->get('foo'); // ~> NULL

/***************************************************************************************
PAGE CACHE
***************************************************************************************/

// cache page for 10 seconds
$stor = new Stor(array('expiry' => 10));
$stor->cache_page();

echo 'kee-chew';

/***************************************************************************************
CACHE value with expiry
***************************************************************************************/

$stor = new Stor(array('expiry' => 10));

if($stor->has_expired('foo')){
    $stor->set('foo', 'bat');
}

var_dump($stor->get('foo')); // ~> 'bat'

/***************************************************************************************
init using Memcache and a 5 second expiry
***************************************************************************************/

$stor = new Stor(array(
    'memcache' => array( 'localhost' => 11211 ),
    'expiry'   => 5
));