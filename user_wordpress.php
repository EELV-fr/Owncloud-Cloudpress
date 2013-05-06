<?php
/**
* ownCloud - Cloudpress
*
* @author Bastien Ho (EELV - Urbancube)
* @copyleft 2012 bastienho@urbancube.fr
* @projeturl http://ecolosites.eelv.fr
*
* Free Software under creative commons licence
* http://creativecommons.org/licenses/by-nc/3.0/
* Attribution-NonCommercial 3.0 Unported (CC BY-NC 3.0)
* 
* You are free:
* to Share — to copy, distribute and transmit the work
* to Remix — to adapt the work
*
* Under the following conditions:
* Attribution — You must attribute the work in the manner specified by the author or licensor (but not in any way that
* suggests  that they endorse you or your use of the work).
* Noncommercial — You may not use this work for commercial purposes.
*
*/

class OC_user_wordpress extends OC_User_Backend {
  protected $wordpress_db_host;
  protected $wordpress_db_name;
  protected $wordpress_db_user;
  protected $wordpress_db_password;
  protected $wordpress_db_prefix;
  protected $wordpress_hash_salt;
  protected $db;
  protected $db_conn;
  protected $wp_all_users;
  static $params;

  function __construct() {
	$this->wp_instance = new OC_wordpress();
    $this->wp_instance->connectdb();
	$uid=OC_User::getUser();
	if($uid){		
		$this->current_user_blogs = $this->wp_instance->getUserblogsIds($uid);
	}
	self::$params =$this->wp_instance->params;
  }
  /**
   * @brief Set email address
   * @param $uid The username
   */
  public function connectdb() {
  	$this->db_conn = $this->wp_instance->connectdb();
  }
  
  private function setUserInfos($uid) {
    $this->connectdb();
    if (!$this->db_conn)
      return false;

    $q = 'SELECT `user_email` FROM '. self::$params['wordpress_db_prefix'] .'users WHERE user_login = "'. str_replace('"','""',$uid) .'" AND user_status = 0';
    $result = mysql_query($q);
    if ($result && mysql_num_rows($result)>0) {
      $user_infos = mysql_fetch_assoc($result);
      OC_Preferences::setValue($uid, 'settings', 'email', $user_infos['user_email']);
    }
  }
  
  
  /* Check if the password is correct */
  public function checkPassword($uid, $password){
    if (!$this->db_conn) {
      $this->connectdb();
    }
    if (!$this->db_conn) {
      return false;
    }
    $query = 'SELECT user_login,user_pass FROM '. self::$params['wordpress_db_prefix'] .'users WHERE user_login = "' . str_replace('"','""',$uid) . '"';
    $query .= ' AND user_status = 0';
    $result = mysql_query($query);
    if ($result && mysql_num_rows($result)>0) {
      $row = mysql_fetch_assoc($result);
      $hash = $row['user_pass'];

    require_once('apps/user_wordpress/class-phpass.php');
    $wp_hasher = new PasswordHash(8, TRUE);
    $check = $wp_hasher->CheckPassword($password, $hash);
    
      if ($check==true) {
		  if(self::$params['wordpress_global_group']!=''){
			 if(!OC_Group::groupExists(self::$params['wordpress_global_group'])){
				  OC_Group::createGroup(self::$params['wordpress_global_group']);
			  }					
			  
			  if( OC_Group::inGroup( $uid, self::$params['wordpress_global_group'] )){
				  // Do nothing					
			  }
			  else{
				  OC_Group::addToGroup( $uid, self::$params['wordpress_global_group'] );
			  }
		 }
        $this->setUserInfos($uid);
        return $row['user_login'];
      }
    }
    return false;
  }
  
  public function log_with_hash($uid,$hash){
    if (!$this->db_conn) {
      $this->connectdb();
    }
    if (!$this->db_conn) {
      return false;
    }
    $query = 'SELECT user_pass FROM '. self::$params['wordpress_db_prefix'] .'users WHERE user_login = "' . str_replace('"','""',$uid) . '"AND user_status = 0';
    $result = mysql_query($query);
    if ($result && mysql_num_rows($result)>0) {
		$ro=mysql_fetch_array($result);
		if($hash==sha1($ro[0])){
		  //sSome line from lib/user.php
		  $enabled = OC_User::isEnabled($uid);
		  if($uid && $enabled) {
			  //session_regenerate_id(true);
			  OC_User::setUserId($uid);
			  //OC_Hook::emit( "OC_User", "post_login", array( "uid" => $uid, 'password'=>$password ));
			  return true;
		  }
	  }
    }
    return false;
  }
  
  public function getAllWpUsers(){
	if (!$this->db_conn) {
      $this->connectdb();
    }
    $users = array();
    if (!$this->db_conn) {
      return;
    }
	$CONFIG_DATADIRECTORY = OC_Config::getValue( "datadirectory", OC::$SERVERROOT."/data" );
	$q = 'SELECT `user_login`,`display_name` FROM '. self::$params['wordpress_db_prefix'] .'users WHERE user_status = 0 ORDER BY `user_login` '; 
    $result = mysql_query($q);
    if ($result && mysql_num_rows($result)>0) {
		$i=0;
      while ($row = mysql_fetch_assoc($result)){
        if(!empty($row['user_login']) ) {
			if(self::$params['wordpress_have_to_be_logged']=='0' || is_dir($CONFIG_DATADIRECTORY.'/'.$row['user_login'])){ 
				$this->wp_all_users[] = array(
					'login'=>$row['user_login'],
					'display_name'=>$row['display_name']
				);
			}
			else{
				// who goe's to hunt loose his place	
			}
		}
	  }
	}
  }
  public function getPartWpUsers($search = '', $limit = NULL, $offset = NULL){
  	$start=0;
	$fin=sizeof($this->wp_all_users);
		
	if($fin==0){
		$this->getAllWpUsers();
		$fin=sizeof($this->wp_all_users);
	}
	
	$nb_users=$fin;
	if($search==''){
		if($offset!=NULL) $start=$offset;
		if($limit!=NULL) $fin=$start+$limit; 
	}	
	if($fin>$nb_users) $fin=$nb_users;
	return array('start'=>$start,'fin'=>$fin);
  }
  public function getSearchWpUser($user,$search){
  	if($search=='') return true;
  	if(strpos(strtolower($user['login']),strtolower($search))>-1 || strpos(strtolower($user['display_name']),strtolower($search))>-1){
  		if(self::$params['wordpress_restrict_group']==1){
  			$thisuserblogs = $this->wp_instance->getUserblogsIds($user['login']);
			$inter = array_intersect($thisuserblogs,$this->current_user_blogs);
			if($inter==false || sizeof($inter)==0){
				return false;
			}
  		}
		return true;
	}
	return false;
  }

  /*  a list of all users from wordpress DB */
  public function getUsers($search = '', $limit = NULL, $offset = NULL) {
	$users=array();
	$plage = $this->getPartWpUsers($search, $limit, $offset);		  
	if($plage['fin']==0){
		return $users;
	}
	for($i=$plage['start'] ; $i<$plage['fin'] ; $i++){
		if($this->getSearchWpUser($this->wp_all_users[$i],$search)){
			$users[] = $this->wp_all_users[$i]['user_login'];
		}
    }
    return $users;
  }
  /* Assoc display names from WP database */
  public function getDisplayNames($search = '', $limit = NULL, $offset = NULL) {	  
	$users=array();
	$plage = $this->getPartWpUsers($search, $limit, $offset);		  
	if($plage['fin']==0){
		return $users;
	}
	for($i=$plage['start'] ; $i<$plage['fin'] ; $i++){
		if($this->getSearchWpUser($this->wp_all_users[$i],$search)){
			$users[$this->wp_all_users[$i]['login']] = (!empty($this->wp_all_users[$i]['display_name']))?$this->wp_all_users[$i]['display_name']:$this->wp_all_users[$i]['login'];
		}
    }
    return $users;
  }
  
  
  
  /* check if a user exists */
  public function userExists($uid) {
    if (!$this->db_conn) {
      $this->connectdb();
    }
    if (!$this->db_conn) {
      return false;
    }
    $q = 'SELECT user_login FROM '. self::$params['wordpress_db_prefix'] .'users WHERE user_login = "'. str_replace('"','""',$uid) .'"  AND user_status = 0';
    $result = mysql_query($q);
    if ($result && mysql_num_rows($result)>0) {
      return true;
    }
    return false;
  }
}
