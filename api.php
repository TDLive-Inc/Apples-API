#!/usr/bin/php
<?php

/* I can't take credit for Kelpie, written by dhotson.
   It's only SLIGHTLY modified to fit into one file.

   See http://getkelpie.com OR http://github.com/dhotson.*/

/**
 * A simple single-threaded tcp server for running Kelpie apps
 */
class Kelpie_Server
{
	private $_host;
	private $_port;

	public function __construct($host = '0.0.0.0', $port = 8000)
	{
		echo "...done!\n";
		$this->_host = $host;
		$this->_port = $port;
	}

	/**
	 * Start serving requests to the application
	 * @param Kelpie_Application
	 */
	public function start($app)
	{
		if (!($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)))
		{
			throw new Kelpie_Server_Exception(socket_strerror(socket_last_error()));
		}

		if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1))
		{
			throw new Kelpie_Server_Exception(socket_strerror(socket_last_error()));
		}

		if (!socket_bind($socket, $this->_host, $this->_port))
		{
			throw new Kelpie_Server_Exception(socket_strerror(socket_last_error()));
		}

		if (!socket_listen($socket))
		{
			throw new Kelpie_Server_Exception(socket_strerror(socket_last_error()));
		}

		while ($client = socket_accept($socket))
		{
			$data = '';
			$nparsed = 0;

			$h = new HttpParser();

			while ($d = socket_read($client, 1024 * 1024 * 1024))
			{
				$data .= $d;
				$nparsed = $h->execute($data, $nparsed);

				if ($h->isFinished())
				{
					break;
				}

				if ($h->hasError())
				{
					socket_close($client);
					continue 2; // Skip to accept next connection..
				}
			}

			$env = $h->getEnvironment();

			global $argv;
			$env['SERVER_NAME'] = $this->_host;
			$env['SERVER_PORT'] = $this->_port;
			$env['SCRIPT_FILENAME'] = $argv[0];
			socket_getpeername($client, $address);
			$env['REMOTE_ADDR'] = $address;

			list($status, $headers, $body) = $app->call($env);

			$response = new Kelpie_Server_Response();
			$response->setStatus($status);
			$response->setHeaders($headers);
			$response->setBody($body);

			foreach ($response as $chunk)
			{
				$len = strlen($chunk);
				$offset = 0;
				while ($offset < $len)
				{
					if (false === ($n = @socket_write($client, substr($chunk, $offset), $len-$offset)))
						break 2;

					$offset += $n;
				}
			}

			socket_close($client);
		}
	}
}

class Kelpie_Server_Exception extends Exception
{
}

/**
 * @author dennis@99designs.com
 */
class Kelpie_Server_Headers implements ArrayAccess
{
	private static $HEADER_FORMAT = "%s: %s\r\n";
	private static $ALLOWED_DUPLICATES = array('Set-Cookie','Set-Cookie2','Warning','WWW-Authenticate');

	public function __construct()
	{
		$this->_sent = array();
		$this->_out = array();
	}

	public function offsetExists($key)
	{
		return isset($this->_sent[$key]);
	}

	public function offsetGet($key)
	{
		return $this->_sent[$key];
	}

	public function offsetSet($key, $value)
	{
		if (!isset($this->_sent[$key]) || in_array($key, self::$ALLOWED_DUPLICATES))
		{
			$this->_sent[$key] = true;

			if (!isset($value))
				return;
			elseif ($value instanceof DateTime)
				$value = $value->format(DateTime::RFC1123);
			else
				$value = "$value";

			$this->_out []= sprintf(self::$HEADER_FORMAT, $key, $value);
		}
		return $this->_sent[$key] = $value;
	}

	public function offsetUnset($key)
	{
		unset($this->_sent[$key]);
	}

	public function __toString()
	{
		return implode($this->_out);
	}
}

class Kelpie_Server_Response
	implements IteratorAggregate
{
	const CONNECTION = 'Connection';
	const CLOSE = 'close';
	const KEEP_ALIVE = 'keep-alive';
	const SERVER = 'Server';
	const CONTENT_LENGTH = 'Content-Length';

	private $_status;
	private $_body;
	private $_headers;
	private $_persistent;

	public function __construct()
	{
		$this->_headers = new Kelpie_Server_Headers();
		$this->_status = 200;
		$this->_persistent = false;
	}

	/**
	 * String representation of the headers
	 * to be sent in the response.
	 */
	public function headersOutput()
	{
		// Set default headers
		$this->_headers[self::CONNECTION] = $this->isPersistent()
			? self::KEEP_ALIVE
			: self::CLOSE
			;

		return "$this->_headers";
	}

	/**
	 * Top header of the response,
	 * containing the status code and response headers.
	 */
	public function head()
	{
		return sprintf(
			"HTTP/1.1 %s %s\r\n%s\r\n",
			$this->_status,
			self::$HTTP_STATUS_CODES[$this->_status],
			$this->headersOutput()
		);
	}

	public function setStatus($status)
	{
		$this->_status = $status;
		return $this;
	}

	public function setBody($body)
	{
		$this->_body = $body;
		return $this;
	}

	/**
	 * array($key => $value)
	 * $key must be a string
	 * $value can be either an array, or a string separated by newlines
	 */
	public function setHeaders($headers)
	{
		foreach($headers as $key => $value)
		{
			if (is_string($value))
				$value = explode("\n", $value);

			foreach ($value as $v)
			{
				$this->_headers[$key] = $v;
			}
		}
	}

	public function getIterator()
	{
		$it = new AppendIterator();

		$it->append(new ArrayIterator(array($this->head())));

		if (is_string($this->_body))
			$it->append(new ArrayIterator(array($this->_body)));
		elseif (is_array($this->_body))
			$it->append(new ArrayIterator($this->_body));
		elseif ($this->_body instanceof Iterator)
			$it->append($this->_body);

		return $it;
	}

	public function persistent()
	{
		$this->_persistent = true;
	}

	public function isPersistent()
	{
		return $this->_persistent && isset($this->_headers[self::CONTENT_LENGTH]);
	}

	private static $HTTP_STATUS_CODES = array(
		100  => 'Continue',
		101  => 'Switching Protocols',
		200  => 'OK',
		201  => 'Created',
		202  => 'Accepted',
		203  => 'Non-Authoritative Information',
		204  => 'No Content',
		205  => 'Reset Content',
		206  => 'Partial Content',
		300  => 'Multiple Choices',
		301  => 'Moved Permanently',
		302  => 'Moved Temporarily',
		303  => 'See Other',
		304  => 'Not Modified',
		305  => 'Use Proxy',
		400  => 'Bad Request',
		401  => 'Unauthorized',
		402  => 'Payment Required',
		403  => 'Forbidden',
		404  => 'Not Found',
		405  => 'Method Not Allowed',
		406  => 'Not Acceptable',
		407  => 'Proxy Authentication Required',
		408  => 'Request Time-out',
		409  => 'Conflict',
		410  => 'Gone',
		411  => 'Length Required',
		412  => 'Precondition Failed',
		413  => 'Request Entity Too Large',
		414  => 'Request-URI Too Large',
		415  => 'Unsupported Media Type',
		500  => 'Internal Server Error',
		501  => 'Not Implemented',
		502  => 'Bad Gateway',
		503  => 'Service Unavailable',
		504  => 'Gateway Time-out',
		505  => 'HTTP Version not supported'
	);
}

/* End Kelpie */

class TDLive_General_Utils {

	function translateuri($string){
		$requri=explode("/", $string);
		return $requri;
	}
}
class HelloWorldApp
{
	private function cardsdb(){
		return array("cards" => array("Cheese", "Raffles"), "descs" => array("Spray-can deliciousness.", '"Place a ticket here to win a fabulous prize!" Yeah, right.'));
	}
	private function checkToken($ip, $user, $token){
		if(! $tokencontents=file_get_contents($ip . ".token")){
			return false;
		}
		explode($tokencontents, "/");
		if(! $tokencontents[0] == $token){
			return false;
		}
		elseif(! $tokencontents[1] == $user){
			return false;
		}
		else{
			return true;
		}
	}
	public function put($code, $json){
		return array(
			$code,
			array("Content-Type" => "text/json"),
			array(json_encode($json))
		);
	}
	public function call($env)
	{
		/*$genutils=new TDLive_General_Utils;
		$varget=$genutils->determinateGETvars($env['QUERY_STRING']); */
		echo "Accepted connection from " . $env["REMOTE_ADDR"] . ". ";
		$me=new TDLive_General_Utils;
		$path=$me->translateuri($env["REQUEST_URI"]);
		if($path[1] == "call"){
			if($path[2] == "alive"){
				echo "Requested alive state. Returned a 200 OK.\n";
				$return=$this->put(200, array("alive" => "true"));
			}
			if($path[2] == "token"){
				echo "Wants token. ";
				$ip=$env['REMOTE_ADDR'];
				if(! @isset($path[3])){
					echo "No username! Returned a 400 Bad Request.\n";
					return $this->put(400, array("error" => "user"));
				}
				if(file_exists($ip . ".token")) {
					echo "Token was issued. Returned 400 Bad Request.\n";
					return $this->put(400, array("error" => "alreadyissued"));
				}
				$letters=array("a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v", "w", "x", "y", "z", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9");
				$token="";
				$n=0;
				while($n < 30){
					$letteradd=$letters[rand(0, 35)];
					$token=$token . $letteradd;
					$n++;
				}
				file_put_contents($ip . ".token", $token . "/" . $path[3]);
				echo "Returned token " . $token . "\n";
				$return=$this->put(200, array("token" => $token));
			}
			elseif($path[2] == "card"){
				echo "Requested a new card. ";
				$cards=$this->cardsdb();
				$card=rand(0, count($cards['cards'])-1);
				$json=array("card" => $cards['cards'][$card], "desc" => $cards['descs'][$card], "id" => $card);
				echo $json["card"] . " issued.";
				$return=$this->put(200, $json);
			}
			elseif($path[2] == "playcard"){
				echo "Trying to play a card.";
				if(! @isset($path[3])){
					echo "Request denied. No token specified.\n";
					return $this->put(400, array("error" => "notoken"));
				}
				elseif(! @isset($path[5])){
					echo "Request denied. No card specified.\n";
					return $this->put(400, array("error" => "nocard"));
				}
				elseif(! @isset($path[4])){
					echo "Request denied. No user name specified.\n";
					return $this->put(400, array("error" => "nouser"));
				}
				if(! $this->checkToken($env["REMOTE_ADDR"], $path[3], $path[4])){
					echo "Request denied. Invalid combo.\n";
					return $this->put(400, array("error" => "invalidtoken"));
				}
				$cards=$this->cardsdb();
			}	
			else{
			echo "Invalid call. Returned a 400 Bad Request.\n";
			$return=$this->put(400, array("error" => "invalid_call"));
			}
		}
		else{
			echo "Home page call. Returning a 200 OK.\n";
			return array(
				200,
				array("Content-Type" => "text/html"),
				array("<html>\n<head>\n<title>TDLive Apples API</title>\n</head><body><h1>Welcome to the Apples API!</h1><br><b>If you are a server administrator, your Apples API installation is running correctly. Good job!<br><br>If you ae not an admin, and would like to learn more about the Apples API, read on.</b><br><br>The Apples API is a web service for a game called Apples written by <a href='http://tdlive.org/'>TDLive.org Inc</a>. It uses RESTful API calls to interact with other players.<br><br>You can learn about the Apples API on <a href='http://apples.tdlive.org/'>apples.tdlive.org</a></body></html>")
			);
			
		}
		return $return;
	}
}

echo "

TDLive.org's
 _____                   _                     _____   _____   _
|  _  |                 | |                   |  _  | |  _  | | |
| | | |                 | |                   | | | | | | | | | |
| | | |                 | |                   | | | | | | | | | |
| |_| |                 | |                   | |_| | | |_| | | |
|  _  |  _____   _____  | |  ____   ____      |  _  | |  ___| | |
| | | | |  _  | |  _  | | | |  _ | | ___|     | | | | | |     | |
| | | | | |_| | | |_| | | | | ___| |___ |     | | | | | |     | |
|_| |_| |  ___| |  ___| |_| |____| |____|     |_| |_| |_|     |_|
        | |     | |                                        alpha
        | |     | |
        |_|     |_|

This application uses various open source materials:
Kelpie: http://getkelpie.com
PHP: http://php.net/

(c)2012 TDLive.org Inc. Some rights reserved;
Dual licensed under the Creative Commons BY-NC-SA license and
the GNU General Public License, v2 or later, available at:
http://creativecommons.org/licenses/by-nc-sa/3.0 and
http://gnu.org/copyleft/gpl, respectively. \n";
echo "Initializing server...\n";
$server = @new Kelpie_Server('0.0.0.0', 8000);
$server->start(new HelloWorldApp());
