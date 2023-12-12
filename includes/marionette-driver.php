<?php

/**
 * Marionette driver
 *
 * This class is used to communicate with a local browser using
 * the marionette protocol.
 *
 * @author AnAx
 * @copyright GPL-2.0 license
 * @version 0.0.1
 */
class MarionetteDriver {

  private const DEFAULT_HOST = 'localhost';
  private const DEFAULT_PORT = 2828;

  private const WEBDRIVER_EXECUTE_SCRIPT = 'WebDriver:ExecuteScript';
  private const WEBDRIVER_NAVIGATE = 'WebDriver:Navigate';
  private const WEBDRIVER_NEW_SESSION = 'WebDriver:NewSession';
  private const WEBDRIVER_FIND_ELEMENT = 'WebDriver:FindElement';

  private ?string $host = null;
  private ?int $port = null;
  private $socket = null;
  private $msgId = 1;

  //socket timeout in seconds.
  //this timout is important since it takes time to load some urls fully
  private int $socketTimeout = 30;

  private string $readErrorStr = '';
  private bool $isConnected = false;

  /**
   * Constructor
   *
   * @param string $host The host to connect to. Default to 'localhost'
   * @param int    $port The port to connect to. Default to 2828
   *
   * @return void
   */
  public function __construct(string $host = self::DEFAULT_HOST, int $port = self::DEFAULT_PORT) {
    $this->host = $host;
    $this->port = $port;

    $this->init();
  }

  public function __destruct() {
    $this->disconnect();
  }

  /**
   * Set error message
   *
   * @param string $errStr The error message
   *
   * @return void
   */
  protected function setError(string $errStr) {
    echo('MarionetteDriver error: ' . $errStr . "\n");
  }

  /**
   * Init function that will try and connect to the browser
   * 
   * @return bool True on success or false on fail
   */
  protected function init() : bool {
    $rtn = false;
    //connect
    if($this->connect()) {
      //get default message
      $msg = $this->readMessage(true);

      if(empty($msg)) {
        $this->setError('Failed to read startup message');
      }else{
        $rtn = true;
      }
    }
    return $rtn;
  }


  /**
   * Read marionette message from browser
   *
   * @param bool $isInitMsg If the expected message is the init message (the first message),
   *                        since this message is special.
   *
   * @return array|null Array on success or null on fail
   */
  protected function readMessage(bool $isInitMsg=false) :?array {
    $rtn = null;

    $msgSize=0;
    $msgRemainingSize=0;
    $msgJson='';

    //read data until semicolon is found
    $char='';
    $sizeStr='';
    do{
      $sizeStr .= $char;
      $char = fread($this->socket,1);
    }while($char != ':' && $char !== false);

    if($char != ':') {
      $this->setError('Failed to find message response size: ' . $sizeStr);
    }else{
      if(!preg_match('/^[0-9]+$/', $sizeStr)) {
        $this->setError('Failed response size is not numeric: ' . $sizeStr);
      }else {
        $msgSize = intval($sizeStr);
        //read rest of message
        $msgJson = fread($this->socket,$msgSize);

        $msgArr = json_decode($msgJson,true);

        if($msgArr === null) {
          $this->setError('Failed to decode message json string: ' . $msgJson);
        }else{

          if($isInitMsg === true) {
            //init message (first message recived) has different format
            $rtn=$msgArr;
          }else{

            //check if any error is set in the message

            if(isset($msgArr[2]) && ($msgArr[2] !== null)) {

              $errArr = [];
      
              if(!empty($msgArr[2]['error'])) {
                $errArr[] = $msgArr[2]['error'] . '.';
              }
              if(!empty($msgArr[2]['message'])) {
                $errArr[] = $msgArr[2]['message'] . '.';
              }
      
              $errStr = 'Unknown message error';
              if(!empty($errArr)) {
                $errStr = implode(' -> ', $errArr);
              }
      
              $this->readErrorStr = $errorStr;
              $this->setError('Read message contained an error: ' . $errorStr);
            }else{
              //return value part of the message
              $rtn = $msgArr[3];
            }

          }

        }


        
      }
    }

    //check for timeout while reading data
    //note: unclear if we still got the data or not since this will happen if $msgSize is calculated wrong
    //todo: look into this
    $info = stream_get_meta_data($this->socket);
    if($info['timed_out'] === true) {
      $this->setError('Timeout occured while reading message');
    }

    return $rtn;
  }

  /**
   * Start a marionette connection
   *
   * @return bool True on success or false on fail
   */
  protected function connect() : bool {
    $rtn = false;

    $fp = fsockopen ($this->host, $this->port, $errno, $errstr);

    if(!$fp) { 
      $this->setError('Could not open socket connection: ' . $errno . " -> " . $errstr);
    }else{

      //set socket timeout
      stream_set_timeout($fp, $this->socketTimeout);

      $this->socket = $fp;
      $this->isConnected = true;

      $rtn = true;
    }

    return $rtn;
  }

  /**
   * Close a marionette connection
   *
   * @return bool True on success or false on fail
   */
  protected function disconnect() : bool {
    $rtn = false;
    
    if($this->socket !== null) {
      if(!fclose($this->socket)) {
        $this->setError('Failed to close socket');
      }else{
        $this->socket = null;
        $rtn = true;
      }
    }

    $this->isConnected = false;
    
    return $rtn;
  }


  /**
   * Start a new marionette session
   *
   * @return string|null Session id string on success or null on fail
   */
  public function startNewSession() : ?string {
    $rtn = null;

    $msgArr = [];
    $msgArr['capabilities'] = ['pageLoadStrategy' => 'normal'];

    //send request
    if( $this->sendMessage(self::WEBDRIVER_NEW_SESSION,$msgArr) !== null) {
      //read request answer
      $msg = $this->readMessage();

      if(empty($msg['sessionId'])) {
        $this->setError('Failed to get sessionId out of message: ' . print_r($msg,true));
      }else{
        $rtn = $msg['sessionId'];
      }

    }

    return $rtn;
  }

  /**
   * Navigate to url in browser
   *
   * @param string $url The url to navigate to.
   *
   * @return bool True on success or false on fail
   */
  public function navigateToUrl(string $url) : bool {
    $rtn = false;

    $msgArr=[];
    $msgArr['url'] = $url;
    
    if( $this->sendMessage(self::WEBDRIVER_NAVIGATE,$msgArr) === null) {
      $this->setError('Failed to navigate to url: ' . $url);
    }else{

      //read response message that will arrive when url has been loaded
      $msg = $this->readMessage();
      if(empty($msg)) {
        $this->setError('Failed to read post navigateToUrl message');
      }else{
        $rtn = true;
      }
    }

    return $rtn;
  }

  /**
   * Find an element (UNFINISHED)
   *
   * @return void
   */
  protected function findElement() {
    $rtn = null;

    // [1,42,null,{"value":{"element-6066-11e4-a52e-4f735466cecf":"0acd2abe-75c4-446a-a868-c1a870e5c28b"}}]
    
    //$msg = '[0, 42, "WebDriver:FindElement", {"using": "link text", "value": "text to find"}]';
    //$msg = '[0, 42, "WebDriver:FindElement", {"using": "xpath", "value": "/html/body/div[1]"}]';
    //$msg = '[0, 42, "WebDriver:FindElement", {"using": "class name", "value": "action"}]';

    //$msg = '[0, 42, "WebDriver:FindElement", {"using": "id", "value": "particles"}]';
    //$msg = '[0, 42, "WebDriver:FindElement", {"using": "css selector", "value": "img"}]';
    //$msg = '[0, 42, "WebDriver:FindElement", {"using": "xpath", "value": "/html/body/div[1]"}]';
    
    //$msg = '[0, 42, "WebDriver:FindElement", {"using": "xpath", "value": "//button[contains(text(),\"button text to find\")]"}]';

    
    //"id"
    //"name"
    //"class name"
    //"tag name"
    //"css selector"
    //"link text"
    //"partial link text"
    //"xpath"
    //"anon"
    //"anon attribute"

    $msgArr=[];
    $msgArr['using'] = 'id';
    $msgArr['value'] = 'particles';

    if( $this->sendMessage(self::WEBDRIVER_FIND_ELEMENT,$msgArr) === null) {
      $this->setError('Failed to find element: ' . $msg);
    }else{

      $msg = $this->readMessage();
      if(empty($msg)) {
        $this->setError('Failed to read post findElement message');
      }else{
        print_r($msg);
        $rtn = true;
      }

    }

    return $rtn;
  }

  /**
   * Send a marionette message to the browser
   *
   * @param string $url The url to navigate to.
   *
   * @return int|null Message id on success or null on fail
   */
  protected function sendMessage(string $cmd, array $msgArr) : ?int {
    $rtn = null;
    $msgId = 0;

    $msgJson = $this->createMessage($cmd,$msgArr,$msgId);

    if($msgJson !== null) {

      $msgStr = strlen($msgJson) . ':' . $msgJson;
      if(fputs($this->socket,$msgStr) === false) {
        $this->setError('Failed to send message: ' . $msgStr);
      }else{
        $rtn = $msgId;
      }

    }

    return $rtn;
  }

  /**
   * Create a marionette message json string
   *
   * @param string $cmd The command to send, ex: WebDriver:Navigate
   * @param array  $data The message data to send with the command
   * @param int    &$msgIdParam If set then will be set to the message id
   *               that was used to send the message.
   *
   * @return string|null Json message string on success or null on fail
   */
  protected function createMessage(string $cmd, array $data, int &$msgIdParam = null) : ?string {
    $rtn = null;

    // id to use for the message
    $msgId = $this->msgId++;

    $msgArr = [];
    $msgArr[0] = 0;
    $msgArr[1] = $msgId;
    $msgArr[2] = $cmd;
    $msgArr[3] = $data;

    $msgJson = json_encode($msgArr);

    if($msgJson === false) {
      $this->setError('Failed to create command for ' . $cmd);
    }else{
      $rtn = $msgJson;

      if($msgIdParam !== null) {
        $msgIdParam = $msgId;
      }
    }

    return $rtn;
  }

  /**
   * Excute a script in the browser and return any data
   *
   * @param string $script Script string to be sent
   * @param array  $args Arguments to be passed along
   *
   * @return array|null Any data that was sent back, or an empty array, on success or null on fail
   */
  public function executeScript(string $script, array $args = []) : ?array {
    $rtn = null;
    
    $msgArr = [];
    $msgArr['script'] = $script;
    $msgArr['args'] = $args;
    $msgArr['scriptTimeout'] = 10;

    if( $this->sendMessage(self::WEBDRIVER_EXECUTE_SCRIPT,$msgArr) === null) {
      $this->setError('Failed to execute script: ' . print_r($msgArr, true));
    }else{

      $msg = $this->readMessage();
      if(empty($msg)) {
        $this->setError('Failed to read post execute script message');
      }else{
        $rtn = $msg;
      }

    }

    return $rtn;
  }

  /**
   * Set data in browser local storage
   *
   * @param array  $localStorageArr Keys will be used as local storage index and the value set as value in the local storage
   *
   * @return bool True on success or false on fail
   */
  public function setLocalStorage(array $localStorageArr) :bool {
    $rtn = false;
    $script = '';
    $args = [];
    $i = 0;

    foreach($localStorageArr as $key => $val) {

      //convert true/false/null to strings
      $valStr = $val;
      switch(gettype($val)) {
        case 'boolean':
          $valStr = ($val) ? 'true' : 'false';
          break;
        case 'NULL':
          $valStr = 'null';
          break;
      }

      $args[$i] = $valStr;
      $script .= "localStorage.setItem('" . $key . "',arguments[" . $i . "]);\n";
      $i++;
    }

    $rtn = ($this->executeScript($script,$args) === null) ? false : true;

    return $rtn;
  }

  /**
   * Get data in browser local storage
   *
   * @return array|null Array on success or null on fail
   */
  public function getLocalStorage() : ?array {

    $script = "var rtnArr={};" . 
              "Object.keys(localStorage).map(function(key) {" . 
              "  rtnArr[key] = localStorage.getItem(key);" . 
              "});" . 
              "return JSON.stringify(rtnArr);";

    $rtnValues = $this->executeScript($script);
    if($rtnValues === NULL || empty($rtnValues['value']) ) {
      $this->setError('Failed to get local storage parameters');
    }else{

      $jsonArr = json_decode($rtnValues['value'],true);

      if($jsonArr === null) {
        $this->setError('Failed to decode local storage json string: ' . $jsonStr);
      }else{
        $rtn = $jsonArr;
      }

    }

    return $rtn;
  }

  /**
   * Clear/delete all data in browser local storage (for current site)
   *
   * @return bool True on success or false on fail
   */
  public function clearLocalStorage() :bool {
    $rtn = false;

    $script = "localStorage.clear();";

    $rtn = ($this->executeScript($script) === null) ? false : true;

    return $rtn;
  }

}
?>