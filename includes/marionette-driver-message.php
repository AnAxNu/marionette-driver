<?php

/**
 * Marionette driver message errror
 *
 * This class used to hold any merionette driver errors.
 *
 * @author AnAx
 * @copyright GPL-2.0 license
 * @version 0.0.1
 */
class MarionetteDriverMessageError {
  protected ?string $error=null;
  protected ?string $message=null;
  protected ?string $stacktrace=null;

  /**
   * Set error message (short info)
   *
   * @param string $messageStr The error message
   *
   * @return void
   */
  public function setError(string $errorStr) : void {
    $this->error = $errorStr;
  }

  /**
   * Get error message (short info)
   *
   * @return string The error message
   */
  public function getError() : ?string {
    return $this->error;
  }

  /**
   * Set error message (more info)
   *
   * @param string $messageStr The error message
   *
   * @return void
   */
  public function setMessage(string $messageStr) : void {
    $this->message = $messageStr;
  }

  /**
   * Get error message (more info)
   *
   * @return string The error message
   */
  public function getMessage() : ?string {
    return $this->message;
  }

  /**
   * Set error stacktrace
   *
   * @param string $messageStr The error message
   *
   * @return void
   */
  public function setStacktrace(string $stacktraceStr) : void {
    $this->stacktrace = $stacktraceStr;
  }

  /**
   * Get error stacktrace
   *
   * @return string The error stacktrace
   */
  public function getStacktrace() : ?string {
    return $this->stacktrace;
  }

  /**
   * Return error all error info as string
   *
   * @return string The error info
   */
  public function __toString() : string {
    $rtn = '';

    $rtnArr = [];
    if(!empty($this->error)) {
      $rtnArr[] = 'Error: ' . $this->error;
    }
    if(!empty($this->message)) {
      $rtnArr[] = 'Message: ' . $this->message;
    }
    if(!empty($this->stacktrace)) {
      $rtnArr[] = 'Stacktrace: ' . $this->stacktrace;
    }

    $rtn = get_class($this) . ' ' . implode(',',$rtnArr);

    return $rtn;
  }  

}

/**
 * Marionette driver message base class
 *
 * @author AnAx
 * @copyright GPL-2.0 license
 * @version 0.0.1
 */
class MarionetteDriverMessage {
  protected int $replyToId=0;
  protected int $id=0;
  protected ?array $data=null;
}

/**
 * Marionette driver message request class
 *
 * @author AnAx
 * @copyright GPL-2.0 license
 * @version 0.0.1
 */
class MarionetteDriverMessageRequest extends MarionetteDriverMessage {

  //command that can be used in encode()
  public const WEBDRIVER_EXECUTE_SCRIPT = 'WebDriver:ExecuteScript';
  public const WEBDRIVER_NAVIGATE = 'WebDriver:Navigate';
  public const WEBDRIVER_NEW_SESSION = 'WebDriver:NewSession';
  public const WEBDRIVER_FIND_ELEMENT = 'WebDriver:FindElement';

  protected $usedId = 0;
  protected ?string $encodedStr=null;

  public function __construct() {
  }

  public function __destruct() {
  }

  /**
   * Get the message id used in the sent message
   *
   * @return int The used message id
   */
  public function getUsedId() : int {
    return $this->usedId;
  }

  /**
   * Encode a message to JSON string
   *
   * @param string $cmd  The command to send
   * @param array  $data The data to send
   * @param int    $replyToId The reply-to id to be set in the message
   * @param int    $id The id to be set in the message
   * @return string A JSON string on success or null on fail
   */
  public function encode(string $cmd, array $data=[], ?int $replyToId=null, ?int $id=null) : ?string {
    static $idCounter = 1; 
    $rtn = null;

    //use idCounter as message id if no message id is set
    $this->usedId = ($id !== null) ? $id : $idCounter++;

    //use zero as reply-to id if none is set
    $this->replyToId = ($replyToId !== null) ? $replyToId : 0;

    $msgArr = [];
    $msgArr[0] = $this->replyToId;
    $msgArr[1] = $this->usedId;
    $msgArr[2] = $cmd;
    $msgArr[3] = $data;

    $this->encodedStr = json_encode($msgArr);

    if($this->encodedStr === false) {
      $this->setError('Failed to create command for ' . $cmd);
    }else{
      $rtn = $this->encodedStr;
    }

    return $rtn;
  }

}


/**
 * Marionette driver message response class
 *
 * @author AnAx
 * @copyright GPL-2.0 license
 * @version 0.0.1
 */
class MarionetteDriverMessageResponse extends MarionetteDriverMessage {
  protected bool $isInit=false;
  protected int $protocolVersion=0;

  protected ?MarionetteDriverMessageError $error=null;

  public function __construct(string $messageString) {
    $this->decode($messageString);
  }

  public function __destruct() {
  }

  public function data() : array {
    return (is_array($this->data)) ? $this->data : [];
  }

  public function getError() : string {
    return $this->error; //using MarionetteDriverMessageError __toString()
  }

  /**
   * Set error message
   *
   * @param string $errStr The error message
   *
   * @return void
   */
  protected function setError(string $errStr) {
    echo(get_class($this) . ' error: ' . $errStr . "\n");
  }

  /**
   * Decode a message from returned string to object
   *
   * @param string $messageString The JSON message string
   *
   * @return bool True on success or false on fail
   */
  protected function decode(string $messageString) : bool {
    $rtn = false;

    $msgArr = json_decode($messageString,true);

    if($msgArr === null) {
      $this->setError('Failed to decode message json string: ' . $msgJson);
    }else{

      //check if this is an init message (first message recived) since it has different format
      //For Firefox: {"applicationType":"gecko","marionetteProtocol":3}
      if(!empty($msgArr['marionetteProtocol'])) {
        $this->protocolVersion = intval($msgArr['marionetteProtocol']);
        $this->isInit = true;
      }

      if($this->isInit === true) {
        //init message (first message recived) has different format
        $this->data = $msgArr;
      }else{

        //check if any error is set in the message
        //Example: [1,1,{"error":"invalid session id","message":"WebDriver session does not exist, or is not active","stacktrace":"RemoteError@chrome://remote/content/shared/RemoteError.sys.mjs:8:8\nWebDriverError@chrome://remote/content/shared/webdriver/Errors.sys.mjs:189:5\n"},null]
        if(!empty($msgArr[2]['error'])) {

          $this->error = new MarionetteDriverMessageError();
  
          if(!empty($msgArr[2]['error'])) {
            $this->error->setError($msgArr[2]['error']);
          }
          if(!empty($msgArr[2]['message'])) {
            $this->error->setMessage($msgArr[2]['message']);
          }
          if(!empty($msgArr[2]['stacktrace'])) {
            $this->error->setStacktrace($msgArr[2]['stacktrace']);
          }
  
          $this->setError('Read message contain error(s)');
        }else{

          if(!empty($msgArr[0])) {
            $this->replyToId = intval($msgArr[0]);
          }

          if(!empty($msgArr[1])) {
            $this->id = intval($msgArr[1]);
          }

          if(!empty($msgArr[3])) {
            $this->data = $msgArr[3];
          }

          $rtn = true;
        }

      }

    }

    return $rtn;
  }

}


?>