<?php

// Reused from a different project doenst realy make sense in this project.
// Maybe rework this if i find some time.

class db {
    
    private $database;
    private $username;
    private $password;
    private $hostname;
    private $instance = array();
    private $activeInstance;
    
    public function setDatabase($var){
        if(!empty($var)){
            $this->database = $var;
        }else{
            $this->database = 'GLPI_DATABASE';
        }
    }
    
    public function setUsername($var){
        if(!empty($var)){
            $this->username = $var;
        }else{
            $this->username = 'GLPI_USER';
        }
    }
    
    public function setPassword($var){
        if(!empty($var)){
            $this->password = $var;
        }else{
            $this->password = 'GLPI_PASSWORD';
        }
    }
    
    public function setHostname($var){
        if(!empty($var)){
            $this->hostname = $var;
        }else{
            $this->hostname = 'DB_HOSTNAME';
        }
    }
    
    public function setActiveInstance($var){
        if(array_key_exists($var, $this->instance)){
            $this->activeInstance = $this->instance[$var];
        }
    }
    
    public function __construct($db = false, $host = false, $user = false, $pass = false){
        // Load the defaults.
        (isset($db)) ? $this->setDatabase($db)      :   $this->setDatabase();
        (isset($host)) ? $this->setHostname($host)  :   $this->setHostname();
        (isset($user)) ? $this->setUsername($user)  :   $this->setUsername();
        (isset($pass)) ? $this->setPassword($pass)  :   $this->setPassword();
        
        $this->createInstance();
    }
    
    public function addInstance($db, $host, $user, $pass){
        (isset($db)) ? $this->setDatabase($db)      :   $this->setDatabase();
        (isset($host)) ? $this->setHostname($host)  :   $this->setHostname();
        (isset($user)) ? $this->setUsername($user)  :   $this->setUsername();
        (isset($pass)) ? $this->setPassword($pass)  :   $this->setPassword();
        $this->createInstance();
    }
    
    public function createInstance(){
        if(!in_array($this->database, $this->instance)){
            if($db = new mysqli($this->hostname, $this->username, $this->password, $this->database)){
                $this->instance[$this->database] = $db;
                $this->activeInstance = $this->instance[$this->database];
                $this->activeInstance->select_db($this->database);
            }else{
                if(mysqli_connect_errno()){
                        printf("Connect Failed %s\n", mysqli_connect_error());
                        exit();
                }
            }
        }
    }
    
    public function query($query){
     
        if(!$result = $this->activeInstance->query($query) ) {
            $msg = printf("Error: %s\n", $this->activeInstance->error);
            throw new Exception( $msg );
        }
        return $result;
        
    }

    public function beginTransaction(){
        return $this->activeInstance->begin_transaction();
    }

    public function commit(){
        return $this->activeInstance->commit();
    }

    public function rollback(){
        return $this->activeInstance->rollback();
    }
    
    public function insert_id(){
        if($id = $this->activeInstance->insert_id ) {
            return $id;
        }else{
            return false;
        }
    }
}
?>
