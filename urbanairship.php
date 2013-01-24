<?php

// Php module for using the Urban Airship API

require_once(dirname(__FILE__).'/RESTClient.php');

define('SERVER', 'go.urbanairship.com');
define('BASE_URL', 'https://go.urbanairship.com/api');
define('DEVICE_TOKEN_URL', BASE_URL . '/device_tokens/');
define('PUSH_URL', BASE_URL . '/push/');
define('BROADCAST_URL',  BASE_URL . '/push/broadcast/');
define('FEEDBACK_URL', BASE_URL . '/device_tokens/feedback/');
define('RICH_PUSH_URL', BASE_URL . '/airmail/send/');
define('USER_URL', BASE_URL . '/user/');


// Raise when we get a 401 from the server.
class Unauthorized extends Exception {
}

// Raise when we get an error response from the server.
// args are (status code, message).
class AirshipFailure extends Exception {
}


class AirshipDeviceList implements Iterator, Countable {
    private $_airship = null;
    private $_page = null;
    private $_position = 0;

    public function __construct($airship) {
        $this->_airship = $airship;
        $this->_load_page(DEVICE_TOKEN_URL);
        $this->_position = 0;
    }

    private function _load_page($url) {
        $response = $this->_airship->_request($url, 'GET', null, null);
        $response_code = $response[0];
        if ($response_code != 200) {
            throw new AirshipFailure($response[1], $response_code);
        }
        $result = json_decode($response[1]);
        if ($this->_page == null) {
            $this->_page = $result;
        } else {
            $this->_page->device_tokens = array_merge($this->_page->device_tokens, $result->device_tokens);
            $this->_page->next_page = $result->next_page;
        }
    }

    // Countable interface
    public function count() {
        return $this->_page->device_tokens_count;
    }

    // Iterator interface
    function rewind() {
        $this->_position = 0;
    }

    function current() {
        return $this->_page->device_tokens[$this->_position];
    }

    function key() {
        return $this->_position;
    }

    function next() {
        ++$this->_position;
    }

    function valid() {
        if (!isset($this->_page->device_tokens[$this->_position])) {
            $next_page =  isset($this->_page->next_page) ? $this->_page->next_page : null;
            if ($next_page == null) {
                return false;
            } else {
                $this->_load_page($next_page);
                return $this->valid();
            }
        }
        return true;
    }
}

class Airship {
    private $key = '';
    private $secret = '';

    public function __construct($key, $secret) {
        $this->key = $key;
        $this->secret = $secret;
        return true;
    }

    public function _request($url, $method, $body, $content_type=null) {
        $rest = new RESTClient($this->key, $this->secret, $content_type);
        $rest->createRequest($url, $method, $body);
        $rest->sendRequest();
        $response = $rest->getResponse();
        if ($response[0] == 401) {
            throw new Unauthorized();
        }
        return $response;
    }

    // Register the device token with UA.
    public function register($device_token, $alias=null, $tags=null, $badge=null) {
        $url = DEVICE_TOKEN_URL . $device_token;
        $payload = array();
        if ($alias != null) {
            $payload['alias'] = $alias;
        }
        if ($tags != null) {
            $payload['tags'] = $tags;
        }
        if ($badge != null) {
            $payload['badge'] = $badge;
        }
        if (count($payload) != 0) {
            $body = json_encode($payload);
            $content_type = 'application/json';
        } else {
            $body = '';
            $content_type = null;
        }
        $response = $this->_request($url, 'PUT', $body, $content_type);
        $response_code = $response[0];
        if ($response_code != 201 && $response_code != 200) {
            throw new AirshipFailure($response[1], $response_code);
        }
        return ($response_code == 201);
    }

    // Mark the device token as inactive.
    public function deregister($device_token) {
        $url = DEVICE_TOKEN_URL . $device_token;
        $response = $this->_request($url, 'DELETE', null, null);
        $response_code = $response[0];
        if ($response_code != 204) {
            throw new AirshipFailure($response[1], $response_code);
        }
    }

    // Retrieve information about this device token.
    public function get_device_token_info($device_token) {
        $url = DEVICE_TOKEN_URL . $device_token;
        $response = $this->_request($url, 'GET', null, null);
        $response_code = $response[0];
        if ($response_code != 200) {
            throw new AirshipFailure($response[1], $response_code);
        }
        return json_decode($response[1]);
    }


    public function get_device_tokens() {
        return new AirshipDeviceList($this);
    }

    // Push this payload to the specified device tokens and tags.
    public function push($payload, $device_tokens=null, $aliases=null, $tags=null) {
        if ($device_tokens != null) {
            $payload['device_tokens'] = $device_tokens;
        }
        if ($aliases != null) {
            $payload['aliases'] = $aliases;
        }
        if ($tags != null) {
            $payload['tags'] = $tags;
        }
        $body = json_encode($payload);
        $response = $this->_request(PUSH_URL, 'POST', $body, 'application/json');
        $response_code = $response[0];
        if ($response_code != 200) {
            throw new AirshipFailure($response[1], $response_code);
        }
    }

    /**
     * Send rich push notification.
     * All fields except message are optional, but at least one of tags, users 
     * or aliases must be specified.
     * The response to a successful API call has an HTTP 200 status code.
     * 
     * @author Alberto Miranda <alberto@glyder.co>
     * @param string $message
     * @param array $payload
     * @param array $aliases
     * @param string $title
     * @param array $tags
     * @param array $users
     * @param string $contentType Default: "text/html"
     * @param array $extra
     * @throws AirshipFailure
     */
    public function richPush($message, $payload = null,  $aliases = null, $title = null, $tags = null, $users = null, $contentType = "text/html", $extra = null) {
        $request = array(
            'push' => $payload,
            'tags' => $tags,
            'users' => $users,
            'aliases' => $aliases,
            'title' => $title,
            'message' => $message,
            'content-type' => $contentType,
            'extra' => $extra
        );
        
        //remove empty request items and convert it to json
        $filteredRequest = array_filter($request);
        $jsonFilteredRequest = json_encode($filteredRequest);
        
        //send request to Urban Airship and get response
        $response = $this->_request(RICH_PUSH_URL, 'POST', $jsonFilteredRequest, 'application/json');
        $response_code = $response[0];
        if ($response_code != 200) {
            throw new AirshipFailure($response[1], $response_code);
        }
    }

    // Broadcast this payload to all users.
    public function broadcast($payload, $exclude_tokens=null) {
        if ($exclude_tokens != null) {
            $payload['exclude_tokens'] = $exclude_tokens;
        }
        $body = json_encode($payload);
        $response = $this->_request(BROADCAST_URL, 'POST', $body, 'application/json');
        $response_code = $response[0];
        if ($response_code != 200) {
            throw new AirshipFailure($response[1], $response_code);
        }
    }

    /*
     Return device tokens marked as inactive since this timestamp
     Return a list of (device token, timestamp, alias) functions.
     */
    public function feedback($since) {
        $url = FEEDBACK_URL . '?' . 'since=' . rawurlencode($since->format('c'));
        $response = $this->_request($url, 'GET', null, null);
        $response_code = $response[0];
        if ($response_code != 200) {
            throw new AirshipFailure($response[1], $response_code);
        }
        $results = json_decode($response[1]);
        foreach ($results as $item) {
            $item->marked_inactive_on = new DateTime($item->marked_inactive_on,
                                                       new DateTimeZone('UTC'));
        }
        return $results;
    }

    /**
     * Creates rich push user with passed params.
     * If a tag is included that does not already exist for your account, it 
     * will be created. 
     * The application’s device token must be included to use Apple’s Push 
     * Notification Service to alert the user that a new message has arrived.
     * 
     * @author Alberto Miranda <alberto@glyder.co>
     * @param array $deviceTokens
     * @param string $alias
     * @param array $tags
     * @param string $udid A unique identifier for the User, can be your internal user guid or user id
     * @return array Rich Push User data, contains username and password
     * @throws AirshipFailure
     */
    public function createRichPushUser($deviceTokens, $alias = null, $tags = null, $udid = null) {
        $request = array(
            'device_tokens' => $deviceTokens,
            'alias' => $alias,
            'tags' => $tags,
            'udid' => $udid
        );
        
        //remove empty request items and convert it to json
        $filteredRequest = array_filter($request);
        $jsonFilteredRequest = json_encode($filteredRequest);
        
        //send request to Urban Airship and get response
        $response = $this->_request(USER_URL, 'POST', $jsonFilteredRequest, 'application/json');
        $response_code = $response[0];
        if ($response_code != 201) {
            throw new AirshipFailure($response[1], $response_code);
        }

        $user = json_decode($response[1]);
        return $user;
    }
    
    /**
     * Delete passed Rich Push user.
     * 
     * @author Alberto Miranda <alberto@glyder.co>
     * @param string $username
     * @return boolean
     * @throws AirshipFailure
     */
    public function deleteRichPushUser($username) {
        //send request to Urban Airship and get response
        $response = $this->_request(USER_URL . $username, 'DELETE', null);
        $response_code = $response[0];
        if ($response_code != 301) {
            throw new AirshipFailure($response[1], $response_code);
        }
    }
}
