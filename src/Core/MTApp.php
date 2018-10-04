<?php

/**
 * MultiTenant Plugin
 * Copyright (c) PRONIQUE Software (http://pronique.com)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) PRONIQUE Software (http://pronique.com)
 * @link          http://github.com/pronique/multitenant MultiTenant Plugin Project
 * @since         0.5.1
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace MultiTenant\Core;

use Cake\Core\StaticConfigTrait;
use Cake\Core\Exception\Exception;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Network\Session;

//TODO Implement Singleton/Caching to eliminate sql query on every call
class MTApp {
  
  use StaticConfigTrait {
    config as public _config;
  }

  protected static $_cachedAccounts = [];
  protected static $_configConsumed = false;
  protected static $_forceGlobalContext = false;    // true will make MTApp behave in global context, even from tenant context
  protected static $_forceTenantId = false;         // set to id of tenant to force during saving
  protected static $_isSuperTenant = false;           // when in the super user context... need to track this so we always return super tenant (because we are setting _forceTenantId on first run; subsequent runs need to know to ignore) 
  protected static $_doFullLookup = false;          // true when we are doing a full lookup
  protected static $_cachedFullAccounts = [];       // stores the full version of the account
  
  public static function config($key, $config = null) {
      if(!static::$_configConsumed) {
          static::setConfig(\Cake\Core\Configure::read('MultiTenant'));
          static::$_configConsumed = true;
      }
      
      if ($config !== null || is_array($key)) {
          static::setConfig($key, $config);
          
          return null;
      }
      
      return static::getConfig($key);
  }
  
  /**
   * Reads existing configuration.
   *
   * @param string $key The name of the configuration.
   * @return array|null Array of configuration data.
   */
  public static function getConfig($key)
  {
      return isset(static::$_config[$key]) ? static::$_config[$key] : null;
  }
  
  /**
   * Force global context
   */
  public static function forceGlobal()
  {
      self::$_forceGlobalContext = true;
  }
  
  /**
   * Resume normal context
   */
  public static function resumeNormal()
  {
      self::$_forceGlobalContext = false;
  }
  
  /**
   * Force tenant id - used when not in tenant context, but want to force account_id = tenant->id
   */
  public static function forceTenantId($id)
  {
      self::$_forceTenantId = $id;
  }
  
  /**
   * Resume normal context
   */
  public static function stopForceTenantId()
  {
      self::$_forceTenantId = false;
  }


 /**
  * find the current context based on domain/subdomain
  * 
  * @return String 'global', 'tenant', 'custom'
  *
  */
  public static function getContext() {
    // check for forced tenant id
    if(self::$_forceTenantId !== false) {
        return 'tenant';
    }
      
    //get tenant qualifier
    $qualifier = self::_getTenantQualifier();
    
    if ( $qualifier == '' ) {
      return 'global';
    }

    return 'tenant';
  }

 /**
  *
  *
  */
  public static function isPrimary() {
    // check for forced tenant id
    if(self::$_forceTenantId !== false) {
        return false;
    }
      
    //get tenant qualifier
    $qualifier = self::_getTenantQualifier();
    
    if ( $qualifier == '' ) {
      return true;
    }

    return false;
  }
  /**
  * 
  * Can be used throughout Application to resolve current tenant
  * Returns tenant entity
  * 
  * @returns Cake\ORM\Entity
  */
  public static function tenant( ) {
    
    //if tentant/_findTenant is called at the primary domain the plugin is being used wrong;
    if ( self::isPrimary() ) {
      throw new Exception('MTApp::tenant() cannot be called from primaryDomain context');
    }
    
    // check for forced tenant id
    if(self::$_forceTenantId !== false and !self::$_isSuperTenant) {
        $tenant = new \stdClass();  // mock object
        $tenant->id = self::$_forceTenantId;
    } else {
        $tenant =  static::_findTenant();
    }

    //Check for inactive/nonexistant domain
    if ( !$tenant ) {
      self::redirectInactive();
    }
    
    return $tenant;

  }
  
  /**
   *
   * Can be used throughout Application to resolve current tenant, with full item record details
   * Returns tenant entity
   *
   * @returns Cake\ORM\Entity
   */
  public static function tenantFull( ) {
      self::$_doFullLookup = true;
      $tenant = self::tenant();
      self::$_doFullLookup = false;
      
      return $tenant;
  }

  
  protected static function _findTenant() {
    
    //if tentant/_findTenant is called at the primary domain the plugin is being used wrong;
    if ( self::isPrimary() ) {
      throw new Exception('MTApp::tenant() cannot be called from primaryDomain context');
    }
    
    //get tenant qualifier
    $qualifier = self::_getTenantQualifier();
   
    
    // see if we are dealing with a super user account
    if($qualifier == 'super') {        
        $tenant = new \stdClass();  // mock object
        $tenant->id = self::$_forceTenantId;
        $tenant->username = 'super';
        self::$_cachedAccounts[$qualifier] = $tenant;
    }
    
    
    //Read entity from cache if it exists
    if ( array_key_exists($qualifier, self::$_cachedAccounts) and !self::$_doFullLookup) {
        return self::$_cachedAccounts[$qualifier];
    }
    
    //load model
    $modelConf= self::config('model');
    $tbl = TableRegistry::get( $modelConf['className'] );
    $conditions = array_merge([$modelConf['field']=>$qualifier], $modelConf['conditions']);
    
    //Query model and store in cache
    self::$_cachedAccounts[$qualifier] = $tbl->find('all', ['skipTenantCheck' => true])->where($conditions)->first();
    
    
    
    
    
    if(self::$_doFullLookup) {
        
        
        
        //Read entity from cache if it exists
        if ( array_key_exists($qualifier, self::$_cachedFullAccounts)) {
            return self::$_cachedFullAccounts[$qualifier];
        }
        
        self::$_forceGlobalContext = true;
        //load model
        $modelConf= self::config('model');
        $tbl = TableRegistry::get( $modelConf['className'] );
        
        //Query model and store in cache
        self::$_cachedFullAccounts[$qualifier] = $tbl->getAccount($qualifier);
        
        self::$_forceGlobalContext = false;
        
        return self::$_cachedFullAccounts[$qualifier];
        
    }
    
    
    return self::$_cachedAccounts[$qualifier];

    
  
  } 

  public static function redirectInactive($status = 302) {
      
      if(Configure::read('Avenger.request')) {
           //return;
      }
    $uri = self::config('redirectInactive');

    if(strpos($uri, 'http') !== false) {
      $full_uri = $uri;
    } else {
      $full_uri = env('REQUEST_SCHEME') .'://' . self::config('primaryDomain') . $uri;
    }
    
    header( 'Location: ' . $full_uri, true, $status);
    exit;
  
  }
  
  protected static function _getTenantQualifier() {
    if(self::$_forceGlobalContext) {
        return '';  // forcing global context
    }
    
    $isAvengerRequest = false;
    $uri = rtrim(parse_url(env('REQUEST_URI'), PHP_URL_PATH), '/');
    if(stripos($uri, '/'.AVENGER_PREFIX) === 0) {
        $isAvengerRequest = true;   // avenger side
    }
    
    if ( self::config('multisite') ) {
        // see if we are on the primarySite, if so, we are global
        if(env('HTTP_HOST', false) == self::config('primarySite')) {
            return 'primary';  // global context on primary site (this is where you control other sites)
        } else if (env('HTTP_HOST', false) == self::config('adminSite')) {
            return '';  // global context on primary site (this is where you control other sites)
        } else {
            // all others will be handled like subdomain
            if (substr_count(env('SERVER_NAME'), self::config('primaryDomain')) > 0 && substr_count(env('SERVER_NAME'), '.') > 1) {
                return str_replace('.' . self::config('primaryDomain'), '', env('SERVER_NAME'));
            } else {
                // no subdomain isn't allowed
                self::redirectInactive(301);
            }
        }
    }    

    //for domain this is the SERVER_NAME from $_SERVER
    if ( self::config('strategy') == 'domain' ) {
      // check if tenant is available and server name valid
      if (substr_count(env('SERVER_NAME'), self::config('primaryDomain')) > 0 && substr_count(env('SERVER_NAME'), '.') > 1) {
        return str_replace('.' . self::config('primaryDomain'), '', env('SERVER_NAME'));
      } else {
        return '';
      }
    }
    
    if( self::config('strategy') == 'accountUsername' and \Cake\Routing\Router::getRequest() ) {
        
        $account = \Cake\Routing\Router::getRequest()->getParam('account');
        if($account) {
            // see if we are dealing with a super user account
            if($account == 'super') {
                // @todo check permissions for this level of access
                self::$_forceTenantId = 0;  // forcing this to use the global tenant id
                self::$_isSuperTenant = true;   // so we know that we are in super context
            }
        } else {            
            $account = '';
        }
        
        return $account;
    }
  }
}
