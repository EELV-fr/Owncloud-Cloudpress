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

class OC_wordpress {
  var $params;

  function OC_wordpress() {
  	$this->db_conn = '';
	$this->params = array(
	'wordpress_db_host',
	'wordpress_db_user',
	'wordpress_db_password',
	'wordpress_db_name',
	'wordpress_db_prefix',
	'wordpress_url',
	'wordpress_hash_salt',
	'wordpress_have_to_be_logged',
	'wordpress_global_group',
	'wordpress_restrict_group',
	'wordpress_add_button'
	);
	$this->params = $this->getParams();	
	
	if(OC_Appconfig::getValue('user_wordpress', 'clean_groups',0)==0 && isset($this->db)){
		$res = $this->db->query('SELECT `blog_id`,`domain` FROM '. $this->wordpress_db_prefix .'blogs WHERE `deleted`=0 AND `spam`=0 ');
	    if ($res->num_rows) {
	       while($blog = mysqli_fetch_assoc($res)){
	        OC_Group::deleteGroup($blog['domain']);
		   }
		}
		OC_Appconfig::setValue('user_wordpress', 'clean_groups','1');
	}
    $this->connectdb();
	
  }
  public function getParams(){
  	$array=array();
  	foreach($this->params as $key=>$param){
    	$array[$param] = OC_Appconfig::getValue('user_wordpress', $param,'');
	}
	if(empty($array['wordpress_db_host'])) $array['wordpress_db_host']=OC_Config::getValue( "dbhost", "" );
    if(empty($array['wordpress_db_name'])) $array['wordpress_db_name']=OC_Config::getValue( "dbname", "owncloud" );
    if(empty($array['wordpress_db_user'])) $array['wordpress_db_user']=OC_Config::getValue( "dbuser", "" );
    if(empty($array['wordpress_db_password'])) $array['wordpress_db_password']=OC_Config::getValue( "dbpassword", "" );
    if(empty($array['wordpress_have_to_be_logged'])){
		 $array['wordpress_have_to_be_logged']='0';
		 OC_Appconfig::setValue('user_wordpress', 'wordpress_have_to_be_logged', '0');
	}
	return $array;
  }
  
  public function connectdb() {
  	if($this->db_conn !='') return $this->db_conn;
    $this->db_conn = false;        
    
    $errorlevel = error_reporting();
    error_reporting($errorlevel & ~E_WARNING);

    $this->db = new mysqli($this->params['wordpress_db_host'], $this->params['wordpress_db_user'], $this->params['wordpress_db_password'], $this->params['wordpress_db_name']);

    if(!$this->db){
      OC_Log::write('OC_user_wordpress',
          'OC_user_wordpress, Failed to connect to wordpress host database: ' . mysqli_error($this->db),
          OC_Log::ERROR);
      return false;
    }
    
    $this->db_conn = true;
    return true;
  }
  
   
  
  /* retreives wordpress user ID from login name */
  public function getUserId($uid) {
    if (!$this->db_conn) {
      $this->connectdb();
    }
    if (!$this->db_conn) {
      return false;
    }
    $q = 'SELECT ID FROM '. $this->params['wordpress_db_prefix'].'users WHERE user_status = 0 AND user_login=\''.$uid.'\'';
    $result = $this->db->query($q);
    if ($result->num_rows) {
      $row = mysqli_fetch_array($result);
      return $row[0];     
    }
    return false;
  }
  
  
  
  /* retrieves user sites list */
  public function getUserblogsIds($uid=NULL) {
    if (!$this->db_conn) {
      $this->connectdb();
    }
	
    if($uid==NULL) $uid=OC_User::getUser();
    //if(isset($this->current_user_blogs_ids)) return $this->current_user_blogs_ids;
    $blogs = array();
    if (!$this->db_conn) {
      return $blogs;
    }
   if(false !== $user_ID = $this->getUserId($uid)){

         
//find the blog/site id from Wordpress wp_blogs table and make sure that the user has the capacity on that site too.
$q = 'SELECT blog_id, path FROM wp_blogs';
$result = $this->db->query($q);
$ref_site_id = '';
if ($result->num_rows) {
  while ($row = mysqli_fetch_assoc($result)){
      
      $oc_settings_host = $this->params['wordpress_url'] . '/';
 
      
       if(!empty($row['path']) && $this->endsWith( $oc_settings_host, $row['path']  )) {
           $ref_site_id = $row['blog_id'];
           break;
       }
  }
} 
         
// limit the found users to those with the capability of the group name defined in cloud press settings
         
if ($this->params['wordpress_global_group']) {
    // take the role name that the current user requires from the oc settings
    $key_role = $this->params['wordpress_global_group'];
} else {
    // otherise find all sites that the current users has access with any role
    $key_role = '';
}

if ( $ref_site_id != '' ) {
    // Multi-site WP site install.
    if ( $ref_site_id === '1' ) {
        $search_for_meta_key = $this->params['wordpress_db_prefix'] . 'capabilities';
    } else {
        $search_for_meta_key = $this->params['wordpress_db_prefix'] . $ref_site_id . '_capabilities';
    }
    

    $q = 'SELECT meta_key FROM '. $this->params['wordpress_db_prefix'] .'usermeta WHERE user_id = \''.$user_ID.'\' AND `meta_key`LIKE\''. $search_for_meta_key . '\' AND (`meta_value`LIKE\'%' . $key_role . '%\' )'; 
} else {
    // assumed to be a single WP site install.
    $q = 'SELECT meta_key FROM '. $this->params['wordpress_db_prefix'] .'usermeta WHERE user_id = \''.$user_ID.'\' AND `meta_key`LIKE\'%capabilities\' AND (`meta_value`LIKE\'%' . $key_role . '%\' )'; 
}

	$result = $this->db->query($q);
     if ($result->num_rows) {
       while ($row = mysqli_fetch_assoc($result)){
            if(!empty($row['meta_key'])) {
                $blog_id = str_replace(array($this->params['wordpress_db_prefix'],'capabilities','_'),'',$row['meta_key']);
                    if($blog_id==''){
                        $blog_id='1';
                    }		   
                $blogs[] = $blog_id;
            }
       }   
    }
    }

    return $blogs;
  }
  public function getUserblogs($uid,$onlyname=false) {	
    if (!$this->db_conn) {
      $this->connectdb();
    }
	
    $blogs = array();
	
	$blogids=$this->getUserblogsIds($uid);
	$cp_groups=new OC_group_wordpress();
    
	foreach($blogids as $blog_id){
     if(is_numeric($blog_id)){
           $res = $this->db->query('SELECT * FROM '. $this->params['wordpress_db_prefix'].'blogs WHERE blog_id = \''.$blog_id.'\' AND `deleted`=0 AND `spam`=0');
           if ($res && $res->num_rows) {
             $blog = mysqli_fetch_assoc($res);
			  if($onlyname){
			  	$blogs[] = $blog['domain'];
			  }
			  else{
			  	$blogs[] = $blog;
			  }
             
           }
      }
    }
	$this->current_user_blogs=$blogs;
     
    return $blogs;
  }
  
  
  public function getAllblogs($search = '', $limit = -1, $offset = 0) {
  		
    if (!$this->db_conn) {
      $this->connectdb();
    }
    $blogs = array();
	$current_user_blog_ids=array();
	if($search!='' && $this->params['wordpress_restrict_group']==1){
       	$current_user_blog_ids = $this->getUserblogsIds();
    }
	$query=($search!='')?' `domain`LIKE\'%'.str_replace("'","''",$search).'%\' AND':'';
	$plage=($limit>0)? 'LIMIT '.$offset.','.$limit :'';
	$res = $this->db->query('SELECT `blog_id`,`domain` FROM '. $this->params['wordpress_db_prefix'] .'blogs WHERE '.$query.' `deleted`=0 AND `spam`=0 ORDER BY `domain`'.$plage);
	if ($res && $res->num_rows) {
       while($blog = mysqli_fetch_assoc($res)){
       	if($search=='' || $this->params['wordpress_restrict_group']!=1 || in_array($blog['blog_id'],$current_user_blog_ids)){
       		$blogs[]=$blog['domain'];
       	}         
      }
    }
    return $blogs;
  }
  
  

    
    function endsWith($haystack, $needle) {
        // search forward starting from end minus needle length characters
        return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== FALSE);
    }
}
